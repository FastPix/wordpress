<?php
/**
 * FastPix credential store.
 *
 * One credential pair binds the site to exactly one workspace (REQ-001).
 * The Access Token ID is stored as a non-autoloaded option; the Secret Key is
 * stored non-autoloaded and encrypted AES-256-GCM under a key derived from the
 * site authentication salts. The secret is masked after saving and is never
 * returned to the browser, never logged, never in the system report.
 * [REQ-003, REQ-004, FR-005, SEC-002, DATA-016]
 *
 * Honest limit, as the design states it: this hardens a database dump only.
 * An attacker with filesystem access has wp-config.php, and therefore the salts.
 */

namespace Fastpix;

if (!defined('WPINC')) {
    die;
}

class Fastpix_Credentials {

    const OPT_TOKEN_ID = 'fastpix_access_token_id';
    const OPT_SECRET   = 'fastpix_secret_key';

    const CIPHER = 'aes-256-gcm';
    const PREFIX = 'g1:';   // blob format marker, so a future format can be told apart
    const IV_LEN = 12;
    const TAG_LEN = 16;

    /** What every surface shows instead of the secret (REQ-003). */
    const SECRET_MASK = '••••••••••••••••';

    /**
     * Store a pair. Callers MUST have validated it against FastPix first —
     * a pair that does not work is never stored (REQ-002, FR-001).
     *
     * Rotation is the same call: the new pair replaces the old only once it has
     * been validated, and a failed write leaves the previous pair in place
     * (FR-005).
     *
     * @return bool True when both values are stored.
     */
    public static function store($token_id, $secret) {
        $token_id = sanitize_text_field((string) $token_id);
        $secret   = trim((string) $secret);

        $blob = ($token_id === '' || $secret === '') ? null : self::encrypt($secret);
        if ($blob === null) {
            return false;
        }

        $previous_token = get_option(self::OPT_TOKEN_ID, '');
        $previous_blob  = get_option(self::OPT_SECRET, '');

        update_option(self::OPT_TOKEN_ID, $token_id, false);

        if (!update_option(self::OPT_SECRET, $blob, false) && get_option(self::OPT_SECRET, '') !== $blob) {
            // Roll back so the pair never ends up half-swapped.
            if ($previous_token === '') {
                delete_option(self::OPT_TOKEN_ID);
            } else {
                update_option(self::OPT_TOKEN_ID, $previous_token, false);
            }
            if ($previous_blob !== '') {
                update_option(self::OPT_SECRET, $previous_blob, false);
            }
            return false;
        }

        return true;
    }

    /** @return string Empty string when no pair is stored. */
    public static function token_id() {
        return (string) get_option(self::OPT_TOKEN_ID, '');
    }

    /**
     * The token id as stored NOW, bypassing this process's options cache — a
     * long-running job must notice a connect made by another PHP process.
     */
    public static function token_id_fresh() {
        global $wpdb;

        // One direct read, no cache side effects: correct with and without a persistent object cache.
        return (string) $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", self::OPT_TOKEN_ID));
    }

    /**
     * The plaintext secret. Server-side callers only — the API client and
     * nothing else. Never hand the return value to a REST response, a template,
     * a log record or the system report.
     *
     * @return string Empty string when absent or undecryptable.
     */
    public static function secret() {
        $blob = (string) get_option(self::OPT_SECRET, '');
        if ($blob === '') {
            return '';
        }
        $plain = self::decrypt($blob);

        return $plain === null ? '' : $plain;
    }

    public static function has_pair() {
        return self::token_id() !== '' && (string) get_option(self::OPT_SECRET, '') !== '';
    }

    /**
     * True when a stored secret cannot be decrypted — the salts changed, or the
     * row was tampered with. The connection is unusable and the owner has to
     * re-enter the pair; GCM tells us this instead of returning garbage.
     */
    public static function is_unreadable() {
        $blob = (string) get_option(self::OPT_SECRET, '');

        return $blob !== '' && self::decrypt($blob) === null;
    }

    /** Access Token ID may be echoed truncated; the Secret Key never is (FR-005). */
    public static function masked_token_id() {
        $id = self::token_id();
        if ($id === '') {
            return '';
        }
        if (strlen($id) <= 12) {
            return $id;
        }

        return substr($id, 0, 8) . '…' . substr($id, -4);
    }

    public static function masked_secret() {
        return self::has_pair() ? self::SECRET_MASK : '';
    }

    /**
     * Drop the pair. Disconnect only (DELETE /connection) — deactivation
     * removes nothing (REQ-101), and a 401/403 on any call must NOT clear the
     * stored credential (FR-005).
     *
     * ponytail: WF-001 says disconnect "deletes nothing", which reads as local
     * video data, not the pair itself — the pair IS the connection. Confirm
     * before this reaches a release.
     */
    public static function forget() {
        delete_option(self::OPT_TOKEN_ID);
        delete_option(self::OPT_SECRET);
    }

    /**
     * Remove the v1 credential options — the plaintext access pair and v1's own
     * "encrypted" copies. The v2 pair is sealed AES-256-GCM (SEC-002), so once
     * it exists these are stale credential material readable by any plugin, any
     * admin, and every DB backup. Idempotent; safe to call whenever the sealed
     * pair is present. Callers MUST gate this on has_pair() so a site whose only
     * credentials are still the v1 options (import_legacy has not run yet) keeps
     * them for adoption.
     *
     * IMPORTANT: OPT_SECRET is the option 'fastpix_secret_key' and OPT_TOKEN_ID
     * is 'fastpix_access_token_id' — the v2 pair itself. They are NOT v1 leftovers
     * and must never appear below; the guard skips them defensively in case the
     * constants ever change to collide with a name in the list.
     */
    public static function purge_legacy() {
        $keep = array(self::OPT_TOKEN_ID, self::OPT_SECRET);
        foreach (array(
            'fastpix_api_key',
            'fastpix_api_key_encrypted',
            'fastpix_api_secret',
            'fastpix_api_secret_encrypted',
        ) as $legacy_option) {
            if (!in_array($legacy_option, $keep, true)) {
                delete_option($legacy_option);
            }
        }
    }

    /**
     * Seal/unseal an arbitrary secret with the same AES-256-GCM + salts-derived
     * key as the credential pair — DATA-016 requires the webhook signing secret
     * and playback signing key stored the same way. Callers own the option row;
     * this owns only the cryptography.
     */
    public static function seal($plain) {
        return self::encrypt((string) $plain);
    }

    public static function unseal($blob) {
        $plain = self::decrypt((string) $blob);

        return $plain === null ? '' : $plain;
    }

    /**
     * Encryption key, derived from the site authentication salts (SEC-002).
     * Never stored — rederived per call, so there is no key row to steal
     * alongside the ciphertext.
     */
    private static function key() {
        return hash_hkdf('sha256', wp_salt('auth'), 32, 'fastpix-credentials');
    }

    /** @return string|null Null when the value cannot be encrypted. */
    private static function encrypt($plain) {
        $iv  = random_bytes(self::IV_LEN);
        $tag = '';

        $cipher = openssl_encrypt($plain, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG_LEN);
        if ($cipher === false) {
            return null;
        }

        return self::PREFIX . base64_encode($iv . $tag . $cipher);
    }

    /** @return string|null Null when the blob is malformed, tampered with, or keyed differently. */
    private static function decrypt($blob) {
        if (strpos($blob, self::PREFIX) !== 0) {
            return null;
        }

        $raw = base64_decode(substr($blob, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) <= self::IV_LEN + self::TAG_LEN) {
            return null;
        }

        $iv     = substr($raw, 0, self::IV_LEN);
        $tag    = substr($raw, self::IV_LEN, self::TAG_LEN);
        $cipher = substr($raw, self::IV_LEN + self::TAG_LEN);

        $plain = openssl_decrypt($cipher, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag);

        return $plain === false ? null : $plain;
    }
}
