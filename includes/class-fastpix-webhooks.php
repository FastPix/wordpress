<?php
/**
 * Webhook receiver and handler table — ARCH-05, WF-009, FR-100, API-P14.
 *
 * Receiver flow: raw body read → signature verified (base64 HMAC-SHA256 of the
 * raw body, keyed with the webhook signing secret, compared with hash_equals)
 * → workspace check → stored in fastpix_webhook_events (event_id unique = the
 * replay defence; no timestamp is signed) → 200 fast → processing enqueued.
 * Nothing runs inline. Invalid → 401 + source logged; ten failures from one
 * address in a minute → fifteen-minute block [SEC-013].
 *
 * The handler keys on media id + payload status, not the event name alone, so
 * a renamed or added event degrades to a log line instead of a stalled video.
 */

namespace Fastpix;

if (!defined('WPINC')) {
    die;
}

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery
// All SQL here runs against the plugin's own tables: identifiers come from the
// Fastpix_Schema::table() registry (a fixed whitelist, never request input) and
// every value travels through $wpdb->prepare(). Core APIs cannot query these tables.

class Fastpix_Webhooks {

    /** DATA-016: stored non-autoloaded, encrypted like the Secret Key. */
    const OPT_SECRET = Fastpix_Health::OPT_WEBHOOK_SECRET;

    /** The outgoing secret, kept briefly after a rotation so in-flight deliveries
     *  signed with it are still accepted (SEC-003 / WF-014 dual-accept window). */
    const OPT_SECRET_PREV  = 'fastpix_webhook_secret_prev';
    const OPT_SECRET_AT    = 'fastpix_webhook_secret_at';       // when the current secret was saved
    const OPT_LAST_REJECT  = 'fastpix_webhook_last_rejected';   // last signed delivery that failed the check
    const ROTATION_WINDOW  = DAY_IN_SECONDS;   // accept the old secret for 24 h
    /** The last real FastPix delivery (raw body + signature), so a newly saved secret can be checked at once. */
    const OPT_SAMPLE       = 'fastpix_webhook_sample';
    const OPT_PROVEN_AT    = 'fastpix_webhook_secret_proven_at';   // when the saved secret matched that delivery

    const ROUTE            = '/webhook';
    const SIGNATURE_HEADER = 'fastpix-signature';
    const MAX_ATTEMPTS     = 3;
    /** How many one-minute-stepped holds a queued event gets while the connected workspace is still unnamed. */
    const MAX_HOLDS = 15;    // job attempts, then failed and left to reconciliation [WF-009]
    const FAILURES_PER_MIN = 10;   // then a 15-minute block [SEC-013]

    public static function boot() {
        add_action('rest_api_init', array(__CLASS__, 'register_route'));
        add_action('fastpix_process_webhook', array(__CLASS__, 'process'));
    }

    /* ------------------------------------------------------------- secret */

    public static function set_secret($secret) {
        $secret = trim((string) $secret);
        if ($secret === '') {
            delete_option(self::OPT_SECRET);
            delete_option(self::OPT_SECRET_PREV);
            delete_option(self::OPT_SECRET_AT);
            delete_option(self::OPT_LAST_REJECT);
            delete_option(self::OPT_PROVEN_AT);

            return true;
        }
        // A fresh save starts a fresh verdict: only deliveries from now on count.
        update_option(self::OPT_SECRET_AT, time(), false);
        delete_option(self::OPT_LAST_REJECT);

        // Rotation: hold the outgoing secret for a short window so a delivery
        // signed just before the change is still verified. [SEC-003, WF-014]
        $current = self::secret();
        if ($current !== '' && !hash_equals($current, $secret)) {
            update_option(self::OPT_SECRET_PREV, array(
                'blob' => (string) get_option(self::OPT_SECRET, ''),   // already sealed
                'at'   => time(),
            ), false);
        }

        $stored = update_option(self::OPT_SECRET, Fastpix_Credentials::seal($secret), false);

        // Save & verify: check the new secret against FastPix's last real delivery. A match is
        // verified now; no match, or no delivery yet, stays "not verified" until FastPix's next event.
        $sample = get_option(self::OPT_SAMPLE, array());
        if (!self::mistyped($secret) && is_array($sample) && !empty($sample['raw']) && self::signed_by((string) $sample['raw'], (string) $sample['sig'], $secret)) {
            update_option(self::OPT_PROVEN_AT, time(), false);
        } else {
            delete_option(self::OPT_PROVEN_AT);
        }

        return $stored;
    }

    /**
     * Base64 not in canonical form is a mistyped copy: its spare trailing bits are ignored when decoded, so
     * "…KU=" and "…KV=" are the SAME key and FastPix's deliveries still pass. It saves, but it is never the
     * secret the dashboard shows, so it reads "not verified" until the right one is saved. [QA F1]
     */
    private static function mistyped($secret) {
        $decoded = base64_decode($secret, true);

        return $decoded !== false && $decoded !== '' && base64_encode($decoded) !== $secret;
    }

    /** The outgoing secret while its rotation window is still open, else ''. */
    private static function previous_secret() {
        $prev = get_option(self::OPT_SECRET_PREV, '');
        if (!is_array($prev) || empty($prev['blob']) || empty($prev['at'])) {
            return '';
        }
        if (time() - (int) $prev['at'] > self::ROTATION_WINDOW) {
            delete_option(self::OPT_SECRET_PREV);   // window closed — forget it
            return '';
        }

        return (string) Fastpix_Credentials::unseal((string) $prev['blob']);
    }

    public static function secret() {
        $blob = (string) get_option(self::OPT_SECRET, '');

        return $blob === '' ? '' : (string) Fastpix_Credentials::unseal($blob);
    }

    public static function configured() {
        return self::secret() !== '';
    }

    /**
     * FastPix-Signature = Base64(HMAC-SHA256(raw body, key)). The dashboard hands
     * out the key Base64-encoded and its docs do not say whether to decode it
     * first, so both readings are accepted. Never accepts an empty signature.
     */
    public static function signature_valid($raw, $signature) {
        $signature = trim((string) $signature);
        if ($signature === '') {
            return false;
        }
        // The current secret, plus the outgoing one during a rotation overlap
        // window, so deliveries signed just before a rotation are not dropped.
        // Each is tried raw and base64-decoded, mac in base64 and hex — every
        // comparison constant-time. [SEC-003, WF-014]
        // Returns which secret matched ('current' | 'previous') — truthy, so boolean callers keep working;
        // only 'current' may count toward the Settings verdict. [QA S6]
        foreach (array('current' => self::secret(), 'previous' => self::previous_secret()) as $which => $secret) {
            if (self::signed_by($raw, $signature, $secret)) {
                return $which;
            }
        }

        return false;
    }

    /** Is $signature this body's HMAC under $secret (raw or base64-decoded key, mac in base64 or hex)? */
    private static function signed_by($raw, $signature, $secret) { // NOSONAR php:S100 — WordPress snake_case naming
        $signature = trim((string) $signature);
        if ($secret === '' || $signature === '') {
            return false;
        }
        $keys = array($secret);
        $decoded = base64_decode($secret, true);
        if ($decoded !== false && $decoded !== '') {
            $keys[] = $decoded;
        }
        foreach ($keys as $key) {
            $mac = hash_hmac('sha256', $raw, $key, true);
            if (hash_equals(base64_encode($mac), $signature) || hash_equals(bin2hex($mac), strtolower($signature))) {
                return true;
            }
        }

        return false;
    }

    /* ----------------------------------------------------------- receiver */

    public static function register_route() {
        Fastpix_Rest::register(self::ROUTE, array(
            'methods'       => 'POST',
            'callback'      => array(__CLASS__, 'receive'),
            'public_bucket' => 'webhook',   // per-address limit, spent in receive() by unverified requests only [SEC-012, QA S3]
        ));
    }

    /**
     * [FR-100] Store-and-acknowledge only; the 200-under-200 ms budget is why
     * nothing here fetches, parses deeply, or writes anything but one row.
     */
    public static function receive($request) {
        $address   = Fastpix_Rate_Limiter::address();
        $raw       = $request->get_body();   // raw body, read before parsing [ARCH-05]
        $signature = (string) $request->get_header(self::SIGNATURE_HEADER);
        $probe     = json_decode($raw, true);

        $early = null;
        if (!self::configured()) {
            // No secret yet, so nothing can be verified or stored — but the FastPix
            // dashboard needs a 2xx before it will create the endpoint and hand out
            // the secret. Acknowledge, store nothing; polling mode covers sync.
            $early = new \WP_REST_Response(array('stored' => false, 'reason' => 'unconfigured'), 200);
        } elseif ($signature === '' && is_array($probe) && $probe === array()) {
            // The FastPix dashboard checks a new endpoint URL with an unsigned, empty
            // `{}` POST and refuses to save it unless we answer 2xx. It carries no
            // event, so nothing is stored or processed — acknowledge and stop.
            $early = new \WP_REST_Response(array('stored' => false, 'reason' => 'probe'), 200);
        } elseif (!($which = self::signature_valid($raw, $signature))) {
            $early = Fastpix_Webhooks_Apply::reject_invalid($raw, $signature, $address);
        }
        if ($early !== null) {
            // (QA S3) The route's address bucket is spent here, by unverified requests only — a signed delivery is never refused for rate reasons.
            $limited = Fastpix_Rate_Limiter::check('webhook', 'addr:' . $address);

            return is_wp_error($limited) ? $limited : $early;
        }
        Fastpix_Rate_Limiter::observe($address, true);   // a verified delivery sharing the viewers' address is the proxy signal (QA S3)

        $payload  = json_decode($raw, true);
        $event_id = (string) Fastpix_Sync::field($payload, array('id', 'eventId', 'event_id'));
        $type     = (string) Fastpix_Sync::field($payload, array('type', 'event', 'eventType'));
        $data     = Fastpix_Sync::field($payload, array('data', 'object'));
        $data     = is_array($data) ? $data : array();

        if ($event_id === '') {
            // Signed but shapeless: store under a digest id so it is kept, not dropped.
            $event_id = 'digest:' . hash('sha256', $raw);
        }

        list($workspace, $foreign) = Fastpix_Webhooks_Apply::event_workspace($payload, $data);

        // Keep the latest FastPix delivery for set_secret() to check a new secret against. Never our own
        // self-test (signed with whatever is stored, it proves nothing), never one carrying redacted secrets.
        $stored_payload = Fastpix_Webhooks_Apply::redacted_payload($raw, $payload);
        if ($type !== 'fastpix.plugin.test' && $stored_payload === $raw) {
            update_option(self::OPT_SAMPLE, array('raw' => $raw, 'sig' => $signature), false);
        }

        global $wpdb;
        $inserted = $wpdb->query($wpdb->prepare(
            'INSERT IGNORE INTO ' . Fastpix_Schema::table('webhook_events') . '
                 (event_id, event_type, object_type, object_id, workspace_id, payload,
                  signature_valid, received_at, process_state, created_at, updated_at)
             VALUES (%s, %s, %s, %s, %s, %s, %d, %s, %s, %s, %s)',
            $event_id, $type,
            self::object_type($type),
            (string) Fastpix_Sync::field($data, array('id', 'mediaId', 'media_id', 'streamId', 'stream_id', 'uploadId', 'upload_id')),
            $workspace, $stored_payload,   // secrets (stream keys, srt secrets) never persist [QA X15, SEC-019]
            $which === 'current' ? 1 : 2,   // 2 = accepted through the rotation window: real, but not proof of the NEW secret [QA S6]
            current_time('mysql', true),
            $foreign ? 'skipped' : 'pending',
            current_time('mysql', true), current_time('mysql', true)
        ));

        // A DB error ($wpdb->query returns false) must NOT be acknowledged: 0 rows
        // means a duplicate (safe to ack), but false means the event was not stored,
        // and non-media events (track/AI/live) have no sweep to reconstruct them.
        // Return 500 so the platform redelivers rather than dropping the event.
        if ($inserted === false && $wpdb->last_error !== '') {
            return new \WP_Error('fastpix_webhook_store', __('The event could not be stored; retry.', 'fastpix-io'), array('status' => 500));
        }

        self::enqueue_processing($event_id, $inserted, $foreign);

        return rest_ensure_response(array('received' => true));
    }

    /** A stored, non-foreign event gets its processing queued. Duplicate event id (0 rows): acknowledge, do not reprocess. [replay defence] */
    private static function enqueue_processing($event_id, $inserted, $foreign) {
        if (!$inserted || $foreign) {
            return;
        }
        if (Fastpix_Jobs::available()) {
            Fastpix_Jobs::enqueue('fastpix_process_webhook', array('event_id' => $event_id), Fastpix_Jobs::GROUP_WEBHOOKS);

            return;
        }
        // M7: no queue on this site — process after the response is sent rather than leaving the event pending forever.
        add_action('shutdown', function () use ($event_id) {
            ignore_user_abort(true);
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();   // the 2xx goes out first; processing no longer holds the delivery open (QA M7)
            }
            self::process(array('event_id' => $event_id));
        });
    }

    /* ---------------------------------------------------------- processing */

    /**
     * Async handler — the handler table of WF-009. Keys on object id + payload
     * status; the event type only picks the object family.
     */
    public static function process($args = array()) {
        global $wpdb;

        $event_id = isset($args['event_id']) ? (string) $args['event_id'] : '';
        $table    = Fastpix_Schema::table('webhook_events');
        $event    = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE event_id = %s", $event_id), ARRAY_A);

        if (!$event || $event['process_state'] === 'done' || $event['process_state'] === 'skipped') {
            return;   // gone, already handled, or foreign — idempotent by state [ARCH-07]
        }

        $attempt = preg_match('/^attempts:(\d+)/', (string) $event['process_error'], $m) ? (int) $m[1] : 0;

        // Foreignness was judged at receipt; a pair change since then can make a
        // queued event stale (its workspace was left). Skip it — never apply. [ASSUME-092]
        $event_ws = (string) $event['workspace_id'];
        if (Fastpix_Connection::workspace_is_stale($event_ws)) {
            Fastpix_Webhooks_Apply::hold_or_skip($event, $event_id);

            return;
        }

        try {
            $payload = json_decode((string) $event['payload'], true);
            if ($event_ws !== '' && is_array($payload) && isset($payload['data']) && is_array($payload['data'])
                && (string) Fastpix_Sync::field($payload['data'], array('workspaceId', 'workspace_id')) === '') {
                $payload['data']['workspaceId'] = $event_ws;   // the record inserted from this payload is stamped, never ''
            }
            self::dispatch((string) $event['event_type'], $payload);

            $wpdb->update($table, array(
                'process_state' => 'done',
                'processed_at'  => current_time('mysql', true),
                'updated_at'    => current_time('mysql', true),
            ), array('id' => $event['id']));
        } catch (\Throwable $e) {
            $attempt++;

            if ($attempt >= self::MAX_ATTEMPTS) {
                // Failed and left to reconciliation — the deep sweep repairs it. [WF-009]
                $wpdb->update($table, array(
                    'process_state' => 'failed',
                    'process_error' => 'attempts:' . $attempt . ' ' . $e->getMessage(),
                    'updated_at'    => current_time('mysql', true),
                ), array('id' => $event['id']));
                do_action('fastpix_log', 'webhook_processing_failed', array(
                    'scope' => 'webhooks', 'webhook_event_id' => $event_id,
                    'message' => $e->getMessage(), 'attempt' => $attempt, 'max_attempts' => self::MAX_ATTEMPTS,
                ));
            } else {
                $wpdb->update($table, array(
                    'process_error' => 'attempts:' . $attempt,
                    'updated_at'    => current_time('mysql', true),
                ), array('id' => $event['id']));
                Fastpix_Jobs::schedule_at(time() + 30 * $attempt, 'fastpix_process_webhook', array('event_id' => $event_id), Fastpix_Jobs::GROUP_WEBHOOKS);
            }
        }
    }

    /** The WF-009 handler table. */
    public static function dispatch($type, $payload) {
        $data = Fastpix_Sync::field($payload, array('data', 'object'));
        $data = is_array($data) ? $data : (array) $payload;
        // Live shape: the media id rides on payload.object.id for track / mediaAI
        // events (data holds only the output) — hand it down to every handler.
        if (!Fastpix_Sync::field($data, array('mediaId', 'media_id')) && isset($payload['object']['id'])
            && (!isset($payload['object']['type']) || $payload['object']['type'] === 'media')) {
            $data['mediaId'] = (string) $payload['object']['id'];
        }

        switch (self::object_type($type)) {
            case 'media':
                Fastpix_Webhooks_Apply::media($type, $data);

                break;

            case 'watermark':
                // One event per watermark, fired when it is burned in. Nothing in the payload
                // belongs on the video row — re-read the media, which now carries the result.
                $media_id = (string) Fastpix_Sync::field($data, array('mediaId', 'media_id'));
                if ($media_id !== '') {
                    Fastpix_Sync::fetch_and_apply($media_id);
                }

                break;

            case 'playback_id':
                // Fetch playback ids: the media record carries the full set.
                $media_id = (string) Fastpix_Sync::field($data, array('mediaId', 'media_id', 'id'));
                if ($media_id !== '') {
                    Fastpix_Sync::fetch_and_apply($media_id);
                    Fastpix_Uploads::queue_domain_lock($media_id);   // a later-minted id inherits the batch's lock
                }

                break;

            case 'ai':
                // mediaAI completion events: mark the kind, queue the read-back. [WF-005]
                Fastpix_Ai::on_completion_event($type, $data);

                break;

            case 'track':
                Fastpix_Webhooks_Apply::track($data, $type);

                break;

            case 'live':
                Fastpix_Webhooks_Apply::stream($type, $data);

                break;

            case 'upload':
                // Binding media to upload sessions is the upload engine's half;
                // here the media side syncs through apply_media.
                $media_id = (string) Fastpix_Sync::field($data, array('mediaId', 'media_id'));
                if ($media_id !== '') {
                    Fastpix_Sync::fetch_and_apply($media_id);
                }
                do_action('fastpix_upload_event', $type, $data);

                break;

            case 'plugin_test':   // the Settings "Send test event" ping — accepted, nothing to apply
                break;

            default:
                // Transforms, simulcast, anything unrecognised: stored and
                // logged, not discarded. [WF-009]
                do_action('fastpix_log', 'webhook_unhandled', array(
                    'severity' => 'info', 'scope' => 'webhooks',
                    'message'  => 'Stored without a handler: ' . $type,
                ));
        }
    }

    /* ------------------------------------------------------------ plumbing */

    /** The most recent accepted delivery — what the Settings footer shows. */
    public static function last_event() {
        global $wpdb;
        if (!Fastpix_Schema::table_exists('webhook_events')) {
            return null;
        }
        $row = $wpdb->get_row('SELECT event_type, received_at, signature_valid FROM ' . Fastpix_Schema::table('webhook_events') . ' ORDER BY id DESC LIMIT 1', ARRAY_A);

        return $row ? array('type' => $row['event_type'], 'at' => $row['received_at'], 'verified' => (int) $row['signature_valid'] === 1) : null;
    }

    /** The last delivery that came from FastPix itself (the plugin's self-test excluded). */
    public static function last_delivery() {
        global $wpdb;
        if (!Fastpix_Schema::table_exists('webhook_events')) {
            return null;
        }
        $row = $wpdb->get_row('SELECT event_type, received_at, signature_valid FROM ' . Fastpix_Schema::table('webhook_events') . " WHERE event_type <> 'fastpix.plugin.test' ORDER BY id DESC LIMIT 1", ARRAY_A);

        return $row ? array('type' => $row['event_type'], 'at' => $row['received_at'], 'verified' => (int) $row['signature_valid'] === 1) : null;
    }

    /**
     * Is the saved secret the one FastPix signs with? Only FastPix can say: the
     * self-test signs with the same stored secret it then checks, so it proves the
     * URL, never the secret. Verdicts, judged from the moment the secret was saved:
     *   unconfigured — no secret;
     *   rejected     — a signed delivery failed the check since the save (wrong secret);
     *   verified     — the saved secret matched FastPix's last delivery, or a delivery passed since the save;
     *   pending      — no FastPix delivery has proved it yet; shown as "not verified" until one does.
     */
    public static function verdict() {
        $since   = (int) get_option(self::OPT_SECRET_AT, 0);
        $reject  = get_option(self::OPT_LAST_REJECT, array());
        $last    = self::last_delivery();
        $verdict = 'pending';
        if (!self::configured()) {
            $verdict = 'unconfigured';
        } elseif (self::mistyped(self::secret()) || (is_array($reject) && !empty($reject['at']) && (int) $reject['at'] >= $since)) {
            $verdict = 'rejected';
        } elseif ($since > 0 && (int) get_option(self::OPT_PROVEN_AT, 0) >= $since) {   // matched FastPix's last delivery on save
            $verdict = 'verified';
        } elseif ($last && $last['verified'] && strtotime($last['at'] . ' UTC') > $since) {   // strictly after the save: a delivery from the same second belongs to the old secret
            $verdict = 'verified';
        }

        return $verdict;
    }

    /** What the settings screens poll after Save & verify: the last delivery + the workspace FastPix named. */
    public static function status() {
        return array(
            'configured'     => self::configured(),
            'verdict'        => self::verdict(),
            'last_delivery'  => self::last_delivery(),
            'last_event'     => self::last_event(),
            'workspace_name' => (string) get_option(Fastpix_Connection::OPT_WORKSPACE_SEEN_NAME, ''),
            'workspace_uuid' => (string) get_option(Fastpix_Connection::OPT_WORKSPACE_SEEN_ID, ''),
        );
    }

    private static function object_type($type) {
        $type = strtolower((string) $type);

        // First family whose needle matches wins; only the track family matches
        // anywhere in the name (the rest are prefixes), and it must be tried
        // before the generic video.media. catch-all.
        $families = array(
            'plugin_test' => array('fastpix.plugin.test'),
            'upload'      => array('video.upload.', 'video.media.upload.'),
            'playback_id' => array('video.playback_id.'),
            'ai'          => array('video.mediaai.', 'video.media.ai.'),
            'track'       => array('.track.', '.subtitle.'),
            'live'        => array('video.live_stream.', 'video.media.live_stream.', 'video.media.live_clip.'),
            // Ahead of the video.media. catch-all: `data` here is the WATERMARK object, so its `id`
            // is a watermark id and apply_media() would file the media under it.
            'watermark'   => array('video.media.watermark.'),
            'media'       => array('video.media.mp4support.', 'video.media.'),
        );
        foreach ($families as $family => $needles) {
            foreach ($needles as $needle) {
                $at = strpos($type, $needle);
                if ($family === 'track' ? $at !== false : $at === 0) {
                    return $family;
                }
            }
        }

        return 'other';
    }

    public static function type_is($type, $suffix) {
        return substr(strtolower((string) $type), -strlen('.' . $suffix)) === '.' . $suffix
            || stripos((string) $type, '.' . $suffix . '.') !== false;
    }
}
