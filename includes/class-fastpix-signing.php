<?php
/**
 * Playback signing — SEC-005, REQ-052, ARCH-09 (signed-URL rules).
 *
 * One signing key per site, created on the platform (POST /iam/signing-keys)
 * the first time a protected video renders and stored sealed, non-autoloaded
 * (DATA-016) under Fastpix_Health::OPT_SIGNING_KEY as {kid, private_key}.
 *
 * Tokens are minted at render, never from a cached page: RS256 JWTs with
 * kid / aud / iss / sub / iat / exp. ONE token with `aud: "media:{playbackId}"`
 * authorises the stream (.m3u8), the thumbnail (images …/thumbnail.png) and the
 * spritesheet (images …/spritesheet.jpg) — exactly REQ-052's one token. DRM adds
 * a second token, `aud: "drm:{playbackId}"`, for the licence exchange. Signed
 * URLs live only in the object cache, ≥2-minute floor, never in transients,
 * post content, logs or reports.
 */

namespace Fastpix;

if (!defined('WPINC')) {
    die;
}

class Fastpix_Signing {

    const OPT_TOKEN_TTL  = 'fastpix_playback_token_ttl';   // seconds; short default
    const DEFAULT_TTL    = 900;
    const MIN_TTL        = 120;                             // the ≥2-minute floor
    const MAX_TTL        = 86400;


    /* ------------------------------------------------------------ settings */

    public static function ttl() {
        $ttl = (int) get_option(self::OPT_TOKEN_TTL, self::DEFAULT_TTL);

        return max(self::MIN_TTL, min(self::MAX_TTL, $ttl ?: self::DEFAULT_TTL));
    }

    /* ---------------------------------------------------------------- key */

    /** @return array|null {kid, private_key} or null when none is stored/readable */
    public static function key() {
        $blob = (string) get_option(Fastpix_Health::OPT_SIGNING_KEY, '');
        if ($blob === '') {
            return null;
        }
        $json = Fastpix_Credentials::unseal($blob);
        $key  = $json !== '' ? json_decode($json, true) : null;

        return is_array($key) && !empty($key['kid']) && !empty($key['private_key']) ? $key : null;
    }

    public static function has_key() {
        return self::key() !== null;
    }

    /**
     * Create the site's key on the platform and store it. Idempotent: an
     * existing readable key is kept. The response carries {id, privateKey
     * (base64 PEM)} once — it is never retrievable again.
     *
     * @return array|\WP_Error the key
     */
    public static function ensure_key() {
        $existing = self::key();
        if ($existing && self::key_still_exists($existing['kid'])) {
            return $existing;
        }

        $client = new Fastpix_Api_Client();
        // Verified live 2026-09-18: a constant idempotency key makes the platform REPLAY
        // the first-ever creation response — a key that may since have been deleted or
        // belong to a workspace this site left — so every later token fails the licence
        // check with 401. A key is created only when none is stored, so each creation
        // gets its own key; the client still reuses it across its own retries.
        $result = $client->request('POST', '/iam/signing-keys', array(
            'idempotency_row_id' => 'signing-key:' . get_current_blog_id() . ':' . wp_generate_uuid4(),
            'body'               => (object) array(),
        ));
        if (is_wp_error($result)) {
            return $result;
        }

        $parsed = self::key_from_response($result);

        return is_wp_error($parsed) ? $parsed : self::store_key($parsed['kid'], $parsed['pem']);
    }

    /**
     * Once a day, ask the platform whether the stored key still exists there
     * (deleted in the dashboard, or a stale replay — see ensure_key). A key the
     * workspace no longer knows is forgotten so the next call creates a real
     * one; otherwise every token signs fine locally and fails every licence
     * check with 401. Unreachable platform = keep the key (no false forgets).
     */
    public const CHECK_TRANSIENT = 'fastpix_signing_key_checked';
    const HOOK_CHECK = 'fastpix_signing_key_check';

    public static function boot() {
        add_action(self::HOOK_CHECK, array(__CLASS__, 'check_key_job'));
    }

    /**
     * The check itself runs in a background job, never inside a page render —
     * a slow or down platform must not hold a visitor's page (review 2026-09-20).
     * The day transient is armed first, so one render schedules one check.
     */
    private static function key_still_exists($kid) {
        if (get_transient(self::CHECK_TRANSIENT) === $kid) {
            return true;
        }
        set_transient(self::CHECK_TRANSIENT, $kid, DAY_IN_SECONDS);
        if (Fastpix_Jobs::available()) {
            Fastpix_Jobs::enqueue(self::HOOK_CHECK, array('kid' => $kid), Fastpix_Jobs::GROUP_MAINTENANCE);
        } else {
            // No Action Scheduler: still once a day, after the page has gone out — never inside the render. (QA F11)
            add_action('shutdown', static function () use ($kid) {
                if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); }
                self::check_key_job(array('kid' => $kid));
            });
        }

        return true;
    }

    /** Job: a key the workspace no longer knows is forgotten; the next protected render creates a real one. */
    public static function check_key_job($args) {
        $kid = isset($args['kid']) ? (string) $args['kid'] : '';
        $key = self::key();
        if ($kid === '' || !$key || $key['kid'] !== $kid) {
            return;   // already replaced
        }
        $client = new Fastpix_Api_Client();
        $result = $client->request('GET', '/iam/signing-keys/' . rawurlencode($kid), array('context' => 'background'));
        if (is_wp_error($result) && $result->get_error_code() === 'fastpix_not_found') {
            self::forget_key();
            Fastpix_Cache::flush_group('signed');
            do_action('fastpix_log', 'signing_key_stale', array('scope' => 'signing', 'severity' => 'warning', 'message' => 'Stored playback signing key ' . $kid . ' no longer exists on FastPix — a new one will be created.'));
        }
    }

    /** {kid, pem} out of the create response, the PEM un-base64ed when needed. */
    private static function key_from_response($result) {
        $data = isset($result['body']['data']) ? $result['body']['data'] : (array) $result['body'];
        $kid  = (string) Fastpix_Sync::field($data, array('id', 'signingKeyId', 'keyId'));
        $pem  = (string) Fastpix_Sync::field($data, array('privateKey', 'private_key'));
        if ($kid === '' || $pem === '') {
            return new \WP_Error('fastpix_signing_key_shape', __('FastPix did not return a signing key.', 'fastpix-io'), array('status' => 502));
        }
        if (strpos($pem, '-----BEGIN') === false) {
            $decoded = base64_decode($pem, true);
            if ($decoded !== false && strpos($decoded, '-----BEGIN') !== false) {
                $pem = $decoded;
            }
        }

        return array('kid' => $kid, 'pem' => $pem);
    }

    /** Store a key pair (also used by tests and by a future rotate). */
    public static function store_key($kid, $pem) {
        $key = array('kid' => (string) $kid, 'private_key' => (string) $pem, 'created_at' => time());
        update_option(Fastpix_Health::OPT_SIGNING_KEY, Fastpix_Credentials::seal(wp_json_encode($key)), false);
        set_transient(self::CHECK_TRANSIENT, (string) $kid, DAY_IN_SECONDS);   // just created: no need to ask the platform again today
        do_action('fastpix_audit_event', 'signing_key_created', array('kid' => $kid));

        return $key;
    }

    public static function forget_key() {
        delete_option(Fastpix_Health::OPT_SIGNING_KEY);
        delete_transient(self::CHECK_TRANSIENT);
    }

    /* -------------------------------------------------------------- tokens */

    /**
     * Mint the token(s) for one playback id: {media, drm?, exp}. Cached in the
     * OBJECT cache only, keyed per playback id, for the token lifetime minus a
     * margin (floor 2 min) — never in transients (SEC-005).
     *
     * @return array|\WP_Error
     */
    public static function tokens($playback_id, $with_drm = false) {
        $playback_id = (string) $playback_id;
        $cache_key   = 'tok:' . $playback_id . ($with_drm ? ':drm' : '');

        if (Fastpix_Cache::using_object_cache()) {
            $hit = Fastpix_Cache::get('signed', $cache_key);
            if (is_array($hit) && isset($hit['exp']) && $hit['exp'] - time() > self::MIN_TTL / 2) {
                return $hit;
            }
        }

        $key = self::ensure_key();
        if (is_wp_error($key)) {
            return $key;
        }

        $set = self::mint_set($key, $playback_id, $with_drm);
        if (!is_wp_error($set) && Fastpix_Cache::using_object_cache()) {
            Fastpix_Cache::set('signed', $cache_key, $set, max(self::MIN_TTL, self::ttl() - 60));
        }

        return $set;
    }

    /** The {media, drm?, exp} token set, or the first signing failure. */
    private static function mint_set($key, $playback_id, $with_drm) {
        $now = time();
        $exp = $now + self::ttl();
        $set = array('exp' => $exp);
        foreach ($with_drm ? array('media', 'drm') : array('media') as $aud) {
            $jwt = self::jwt($key, array('kid' => $key['kid'], 'aud' => $aud . ':' . $playback_id, 'iss' => 'fastpix.io', 'sub' => '', 'iat' => $now, 'exp' => $exp));
            if (is_wp_error($jwt)) {
                return $jwt;
            }
            $set[$aud] = $jwt;
        }

        return $set;
    }

    /** RS256 JWT — the platform verifies with the public half it keeps. */
    public static function jwt($key, $claims) {
        $header  = self::b64(wp_json_encode(array('alg' => 'RS256', 'typ' => 'JWT', 'kid' => $key['kid'])));
        $payload = self::b64(wp_json_encode($claims));
        $sig     = '';
        $pkey    = openssl_pkey_get_private($key['private_key']);
        if (!$pkey || !openssl_sign($header . '.' . $payload, $sig, $pkey, OPENSSL_ALGO_SHA256)) {
            return new \WP_Error('fastpix_signing_failed', __('The playback signing key could not sign.', 'fastpix-io'), array('status' => 500));
        }

        return $header . '.' . $payload . '.' . self::b64($sig);
    }

    private static function b64($raw) {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /** Signed asset URLs for one playback id (used by the renderer and player-config). */
    public static function urls($playback_id, $tokens) {
        $pb = rawurlencode($playback_id);

        return array(
            'stream'      => Fastpix_Attachments::stream_base() . '/' . $pb . '.m3u8?token=' . $tokens['media'],
            'thumbnail'   => Fastpix_Attachments::image_base() . '/' . $pb . '/thumbnail.png?token=' . $tokens['media'],
            'spritesheet' => Fastpix_Attachments::image_base() . '/' . $pb . '/spritesheet.jpg?token=' . $tokens['media']   // direct-download form; the player builds its own .json URL,
        );
    }
}
