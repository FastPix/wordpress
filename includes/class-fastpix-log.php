<?php
/**
 * Log and audit writers — ARCH-13, SEC-018, SEC-019, DATA-014.
 *
 * Two sinks, deliberately separate: the error log (fastpix_logs) and the audit
 * log (fastpix_audit — connect/disconnect, policy change, secret rotation,
 * migration start and revert, deletion, every refused permission check).
 *
 * Redaction happens HERE, in the writer, so a caller cannot leak a secret by
 * forgetting to strip it. [SEC-019]
 */

namespace Fastpix;

if (!defined('WPINC')) {
    die;
}

class Fastpix_Log {

    /** Retention: logs and webhook payloads 30 days. [spec 07 Common columns] */
    const RETENTION_DAYS = 30;

    const REDACTED = '[redacted]';

    private static $correlation_id = null;

    /**
     * Subscribe the writers. Note the audit hook is `fastpix_audit_event`, not
     * `fastpix_audit`: ARCH-07 already claims `fastpix_audit` as a scheduled job
     * hook in the sync group, and Action Scheduler runs jobs by firing the hook
     * of that name — sharing it would call this writer with job arguments.
     */
    public static function boot() {
        add_action('fastpix_log', array(__CLASS__, 'write'), 10, 2);
        add_action('fastpix_audit_event', array(__CLASS__, 'audit'), 10, 2);
    }

    /**
     * One correlation id per user action, constant across every record that
     * action produces, including jobs it spawns. [ARCH-13]
     */
    public static function correlation_id() {
        if (self::$correlation_id === null) {
            self::$correlation_id = wp_generate_uuid4();
        }

        return self::$correlation_id;
    }

    /** Jobs adopt the correlation id of the action that scheduled them. */
    public static function set_correlation_id($id) {
        self::$correlation_id = (string) $id;
    }

    /**
     * Write one error-log record. Fired as do_action('fastpix_log', $code, $context).
     * [DATA-014, §22 field list]
     */
    public static function write($error_code, $context = array()) {
        global $wpdb;

        if (!Fastpix_Schema::table_exists('logs') || Fastpix_Schema::is_read_only('logs')) {
            return false;   // Nothing to write to yet, or held read-only by REQ-103.
        }

        $context = is_array($context) ? $context : array('value' => $context);
        $now     = current_time('mysql', true);

        $row = array(
            'timestamp'        => $now,
            'severity'         => isset($context['severity']) ? (string) $context['severity'] : 'error',
            'scope'            => isset($context['scope']) ? (string) $context['scope'] : '',
            // Redacted like every context value (SEC-019): messages routinely
            // carry a transport error string or a raw response body, either of
            // which can embed a signed URL or token.
            'message'          => isset($context['message']) ? self::redact_string((string) $context['message']) : '',
            'correlation_id'   => self::correlation_id(),
            'workspace_id'     => isset($context['workspace_id']) ? (string) $context['workspace_id'] : '',
            'media_id'         => isset($context['media_id']) ? (string) $context['media_id'] : '',
            'upload_id'        => isset($context['upload_id']) ? (string) $context['upload_id'] : '',
            'webhook_event_id' => isset($context['webhook_event_id']) ? (string) $context['webhook_event_id'] : '',
            'endpoint'         => isset($context['endpoint']) ? (string) $context['endpoint'] : '',
            'http_method'      => isset($context['http_method']) ? (string) $context['http_method'] : '',
            'error_code'       => (string) $error_code,
            'actor'            => (string) get_current_user_id(),
            'context'          => wp_json_encode(self::redact($context)),
            'created_at'       => $now,
            'updated_at'       => $now,
        );

        foreach (array('video_id', 'migration_id', 'action_id', 'http_status', 'latency_ms', 'attempt', 'max_attempts') as $numeric) {
            if (isset($context[$numeric])) {
                $row[$numeric] = (int) $context[$numeric];
            }
        }

        return (bool) $wpdb->insert(Fastpix_Schema::table('logs'), $row);
    }

    /**
     * Write one audit record: actor, time and origin address. [SEC-018]
     * Fired as do_action('fastpix_audit_event', $event, $context).
     */
    public static function audit($event, $context = array()) {
        global $wpdb;

        if (!Fastpix_Schema::table_exists('audit') || Fastpix_Schema::is_read_only('audit')) {
            return false;
        }

        $context = is_array($context) ? $context : array('value' => $context);
        $now     = current_time('mysql', true);

        return (bool) $wpdb->insert(Fastpix_Schema::table('audit'), array(
            'event'          => (string) $event,
            'actor'          => isset($context['user_id']) ? (int) $context['user_id'] : get_current_user_id(),
            'origin_address' => self::origin_address(),
            'context'        => wp_json_encode(self::redact($context)),
            'created_at'     => $now,
            'updated_at'     => $now,
        ));
    }

    /**
     * Redaction, in the writer. Removes anything named like a secret, anything
     * shaped like one, and any signed URL — signed URLs are never written into
     * logs, the system report or any export. [SEC-019, ARCH-09]
     */
    public static function redact($value, $depth = 0) {
        if ($depth > 6) {
            return self::REDACTED;   // Runaway nesting is not worth walking.
        }

        if (is_object($value)) {
            $value = get_object_vars($value);   // public properties only, like the walk always did
        }

        if (is_array($value)) {
            $out = array();
            foreach ($value as $key => $item) {
                $out[$key] = self::is_secret_key($key) ? self::REDACTED : self::redact($item, $depth + 1);
            }

            return $out;
        }

        return is_string($value) ? self::redact_string($value) : $value;
    }

    /** Key-based redaction only: values under secret-looking keys go, signed URLs stay intact (the stored webhook copy feeds sync). */
    public static function redact_keys($value, $depth = 0) {
        if ($depth > 6 || !is_array($value)) {
            return $value;
        }
        $out = array();
        foreach ($value as $key => $item) {
            $out[$key] = self::is_secret_key($key) ? self::REDACTED : self::redact_keys($item, $depth + 1);
        }

        return $out;
    }

    private static function is_secret_key($key) {
        // stream_?key: a live stream's (or a simulcast target's) ingest key —
        // it rides on platform payloads and webhook bodies as `streamKey`.
        return is_string($key) && preg_match('/secret|token|password|passwd|authorization|auth_?key|signature|signing|credential|idempotency|jwt|nonce|api_?key|stream_?key/i', $key) === 1;
    }

    private static function redact_string($string) {
        // A signed asset URL: keep the shape, drop the token.
        if (preg_match('#^https?://#i', $string) && preg_match('/[?&](token|signature|sig|jwt)=/i', $string)) {
            return preg_replace('/([?&](?:token|signature|sig|jwt)=)[^&]*/i', '$1' . self::REDACTED, $string);
        }

        // A bare JWT, wherever it turns up.
        $string = preg_replace('/\beyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]+/', self::REDACTED, $string);

        // An Authorization header value that was passed as a plain string.
        $string = preg_replace('/\bBasic\s+[a-z0-9+\/=]{8,}/i', 'Basic ' . self::REDACTED, $string);
        $string = preg_replace('/\bBearer\s+[a-z0-9._~+\/=-]{8,}/i', 'Bearer ' . self::REDACTED, $string);

        return $string;
    }

    /** [SEC-018] Origin address of the actor, for audit records only. */
    private static function origin_address() {
        if (empty($_SERVER['REMOTE_ADDR'])) {
            return '';
        }

        return (string) rest_is_ip_address(sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])));
    }

    /**
     * Nightly pruning, called by the fastpix_prune maintenance job.
     * Logs are purely local derivatives, so they are deleted outright rather
     * than tombstoned. [spec 07 Common columns]
     *
     * @return int Rows removed.
     */
    public static function prune($days = self::RETENTION_DAYS) {
        global $wpdb;

        if (!Fastpix_Schema::table_exists('logs')) {
            return 0;
        }

        $cutoff = gmdate('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS));
        $table  = Fastpix_Schema::table('logs');

        // ponytail: one unbounded DELETE. Fine at 30-day retention on a normal
        // site; if a burst ever makes this slow, batch it with LIMIT and reschedule.
        return (int) $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE timestamp < %s", $cutoff));
    }
}
