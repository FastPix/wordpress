<?php
/**
 * Sync engine — ARCH-06, WF-009, FR-101.
 *
 * Four mechanisms over one monotonic state machine, because the list endpoint
 * has no updated-since filter: webhook push (class-fastpix-webhooks), the
 * 15-minute new-media sweep, a per-video poll fallback, and the nightly deep
 * sweep as the universal repair. The weekly audit adds a field-level diff and
 * the orphan check.
 *
 * Ownership on conflict [RULE-022]: WordPress owns title/description, post
 * associations, author, local tags. The platform owns access-policy results,
 * every technical field, and AI output. Deletion: either side wins; tombstones
 * stop resurrection. Sync never deletes local work — orphans are surfaced,
 * removal is the owner's decision [RULE-021].
 *
 * The implementation is split by concern (size only, behaviour unchanged):
 * record application in Fastpix_Sync_Apply, jobs/sweeps/audit/usage in
 * Fastpix_Sync_Sweeps.
 */

namespace Fastpix;

if (!defined('WPINC')) {
    die;
}

require_once __DIR__ . '/class-fastpix-sync-apply.php';
require_once __DIR__ . '/class-fastpix-sync-sweeps.php';

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery
// All SQL here runs against the plugin's own tables: identifiers come from the
// Fastpix_Schema::table() registry (a fixed whitelist, never request input) and
// every value travels through $wpdb->prepare(). Core APIs cannot query these tables.

class Fastpix_Sync {

    /** Deep-sweep budget: 2,000 calls or 90 minutes, whichever first. [WF-009] */
    const SWEEP_MAX_CALLS   = 2000;
    const SWEEP_MAX_SECONDS = 5400;

    /** Poll fallback: 1 → 15 min backoff, stalled at 24 h. [FR-101] */
    const POLL_BACKOFFS  = array(60, 120, 300, 600, 900);
    const POLL_GIVE_UP   = 86400;

    /** One job action walks pages only this long, then reschedules. [ARCH-07] */
    const ACTION_BUDGET = 15;

    /** The platform's media collection endpoint. */
    const EP_ON_DEMAND = '/on-demand';

    /** Shared SQL verb — the plugin's own tables only. */
    const SQL_UPDATE = 'UPDATE ';

    public static function boot() {
        add_action('fastpix_new_media_sweep', array(Fastpix_Sync_Sweeps::class, 'new_media_sweep'));
        add_action('fastpix_deep_sweep', array(Fastpix_Sync_Sweeps::class, 'deep_sweep'));
        add_action('fastpix_poll_media', array(Fastpix_Sync_Sweeps::class, 'poll_media'));
        add_action('fastpix_audit', array(Fastpix_Sync_Sweeps::class, 'audit'));
        add_action('fastpix_usage_sweep', array(Fastpix_Sync_Sweeps::class, 'usage_sweep'));

        // WF-001 background step: connecting seeds sync state and runs the
        // immediate first sweep.
        add_action('fastpix_connected', array(Fastpix_Sync_Sweeps::class, 'on_connected'));
    }

    /* ---------------------------------------------------------------------
     * State machine — RULE-023, FR-101
     * ------------------------------------------------------------------ */

    /**
     * Platform statuses are stored verbatim; the guard works on their rank:
     * created(0) → processing(1) → ready(2) → failed/deleted(3, terminal-ish).
     * A status the plugin has never seen ranks as processing, so a renamed
     * platform status degrades to a log line, not a stalled video. [WF-009]
     */
    public static function rank($status) {
        $map = array(
            'created'  => 0,
            'downloading' => 1, 'downloaded' => 1, 'validating' => 1,
            'in queue' => 1, 'processing' => 1,
            'ready'    => 2,
            'failed'   => 3, 'deleted' => 3,
        );
        $key = strtolower(trim((string) $status));

        return isset($map[$key]) ? $map[$key] : 1;
    }

    /**
     * Out-of-order deliveries cannot regress state: ready-before-created stays
     * ready; created-after-failed is ignored. From ready, only failed or
     * deleted. From deleted, nothing — the tombstone wins. [RULE-023]
     */
    public static function can_advance($from, $to) {
        if (strtolower((string) $from) === 'deleted') {
            return false;
        }
        if ($from === '' || $from === null) {
            return true;
        }

        return self::rank($to) >= self::rank($from);
    }

    /* ---------------------------------------------------------------------
     * Applying a platform record — RULE-022 ownership
     * ------------------------------------------------------------------ */

    /**
     * Upsert one platform media record into fastpix_videos.
     *
     * @param array  $media  Platform record (lenient shape).
     * @param string $source 'api' for a record READ with the connected pair (authoritative for
     *                       the workspace), 'webhook' for a delivered payload (a late delivery
     *                       from a workspace this site left must not re-teach it).
     * @return string created|updated|unchanged|blocked|skipped
     */
    public static function apply_media($media, $source = 'api') {
        global $wpdb;

        $media_id = self::field($media, array('id', 'mediaId', 'media_id'));
        if (!$media_id) {
            return 'skipped';
        }
        // The platform names its workspace (UUID) on every record; the first one
        // after a connect teaches the plugin which workspace the credentials
        // belong to. That UUID scopes the Videos page — not the owner's key,
        // which is never derived from responses.
        $ws_uuid = (string) self::field($media, array('workspaceId', 'workspace_id'));
        Fastpix_Connection::learn_workspace($ws_uuid, $source !== 'webhook');

        $table = Fastpix_Schema::table('videos');
        $row   = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE media_id = %s", $media_id), ARRAY_A);

        // Tombstones stop late webhooks recreating removed objects. [spec 07]
        if ($row && $row['deleted_at'] !== null) {
            return 'blocked';
        }

        $status   = (string) self::field($media, array('status'));
        $now      = current_time('mysql', true);
        $platform = Fastpix_Sync_Apply::platform_fields($media);

        $result = $row
            ? Fastpix_Sync_Apply::update_media($media, $row, $platform, $status, $now)
            : Fastpix_Sync_Apply::insert_media($media, $media_id, $platform, $status, $now);

        // Full media objects carry the subtitle tracks: mirror them and capture the transcript. [ASSUME-086]
        if (!empty($media['tracks']) && is_array($media['tracks'])) {
            $video_id = $row ? (int) $row['id'] : (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE media_id = %s", $media_id));
            if ($video_id) {
                Fastpix_Ai::sync_subtitles($video_id, $media, $source !== 'webhook');   // a raw webhook payload may list tracks only in part: it adds, the full fetch that follows it removes
            }
        }

        return $result;
    }

    /** Tombstone a video: deletion wins from either side. [RULE-022] */
    public static function tombstone_media($media_id) {
        global $wpdb;

        $now = current_time('mysql', true);
        $wpdb->query($wpdb->prepare(
            self::SQL_UPDATE . Fastpix_Schema::table('videos') . "
             SET deleted_at = %s, status = 'Deleted', updated_at = %s
             WHERE media_id = %s AND deleted_at IS NULL",
            $now, $now, $media_id
        ));
        Fastpix_Cache::flush_group('videos');
    }

    /** Tombstoned (deleted here or on the platform) or orphaned (FastPix no longer knows it): what the library shows as Unavailable. */
    public static function is_unavailable($row) {
        return !empty($row['deleted_at']) || (isset($row['error_code']) && $row['error_code'] === 'orphaned');
    }

    /**
     * Remove an Unavailable video's record for good: the row and its local
     * children (playback ids, tracks, AI output, search index, usage). Analytics
     * and watch progress keep their own retention. Never called on a live row —
     * callers check is_unavailable() first. [ASSUME-102]
     */
    public static function purge_video($video_id) {
        global $wpdb;

        $video_id = (int) $video_id;
        foreach (array('playback_ids', 'tracks', 'ai', 'search_index', 'usage') as $name) {
            if (!Fastpix_Schema::table_exists($name)) {
                continue;
            }
            $table = Fastpix_Schema::table($name);
            if (in_array('video_id', (array) $wpdb->get_col("DESC {$table}", 0), true)) {
                $wpdb->delete($table, array('video_id' => $video_id));
            }
        }
        $gone = (bool) $wpdb->delete(Fastpix_Schema::table('videos'), array('id' => $video_id));
        Fastpix_Cache::flush_group('videos');

        return $gone;
    }

    /** Orphans are surfaced, never removed. [RULE-021] */
    public static function mark_orphaned($media_id) {
        global $wpdb;

        $wpdb->query($wpdb->prepare(
            self::SQL_UPDATE . Fastpix_Schema::table('videos') . "
             SET error_code = 'orphaned', updated_at = %s
             WHERE media_id = %s AND deleted_at IS NULL AND error_code <> 'orphaned'",
            current_time('mysql', true), $media_id
        ));
    }

    /** Whether the local row is stamped for a workspace other than the connected one — out of the pair's reach. [ASSUME-092] */
    public static function other_workspace_row($media_id) {
        global $wpdb;

        $stamp = (string) $wpdb->get_var($wpdb->prepare('SELECT workspace_id FROM ' . Fastpix_Schema::table('videos') . ' WHERE media_id = %s', $media_id));

        return $stamp !== '' && $stamp !== (string) get_option(Fastpix_Connection::OPT_WORKSPACE_SEEN_ID, '');
    }

    /** Fetch one record from the platform and apply it. */
    public static function fetch_and_apply($media_id) {
        $client = new Fastpix_Api_Client();
        $result = $client->request('GET', self::EP_ON_DEMAND . '/' . rawurlencode($media_id), array('context' => 'background'));

        if (is_wp_error($result)) {
            // A previous workspace's media is not found by the connected pair by
            // definition — that is not an orphan, the row is just out of reach. [ASSUME-092]
            if ($result->get_error_code() !== 'fastpix_not_found' || self::other_workspace_row($media_id)) {
                return 'error';
            }
            self::mark_orphaned($media_id);   // [ERR-040]

            return 'orphaned';
        }

        $body  = is_array($result['body']) ? $result['body'] : array();
        $media = isset($body['data']) && is_array($body['data']) ? $body['data'] : $body;

        return self::apply_media($media);
    }

    /* ---------------------------------------------------------------------
     * Lenient payload parsing
     * ------------------------------------------------------------------ */

    /** First present key wins — payload shapes are lenient by design. */
    public static function field($data, $keys) {
        foreach ((array) $keys as $key) {
            if (is_array($data) && array_key_exists($key, $data)) {
                return $data[$key];
            }
        }

        return null;
    }

    /**
     * The platform reports duration as "HH:MM:SS" — a decimal column would
     * silently coerce that string to 0, starving the watch-progress beacon and
     * coverage chart, so parse it here. Plain numbers pass through.
     */
    public static function duration_seconds($value) {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return (float) $value;
        }
        $seconds = null;
        if (preg_match('/^(\d+):([0-5]?\d):([0-5]?\d(?:\.\d+)?)$/', (string) $value, $m)) {
            $seconds = (int) $m[1] * 3600 + (int) $m[2] * 60 + (float) $m[3];
        } elseif (preg_match('/^([0-5]?\d):([0-5]?\d(?:\.\d+)?)$/', (string) $value, $m)) {
            $seconds = (int) $m[1] * 60 + (float) $m[2];
        }

        return $seconds;
    }

    /* ----------------------------------------------- delegates (stable API) */
    // Kept here so callers and tests keep one entry point; the implementations
    // live in Fastpix_Sync_Sweeps.

    /** @see Fastpix_Sync_Sweeps::new_media_sweep() */
    public static function new_media_sweep() {
        Fastpix_Sync_Sweeps::new_media_sweep();
    }

    /** @see Fastpix_Sync_Sweeps::deep_sweep() */
    public static function deep_sweep($args = array()) {
        Fastpix_Sync_Sweeps::deep_sweep($args);
    }

    /** @see Fastpix_Sync_Sweeps::audit() */
    public static function audit() {
        Fastpix_Sync_Sweeps::audit();
    }

    /** @see Fastpix_Sync_Sweeps::usage_sweep() */
    public static function usage_sweep($args = array()) {
        return Fastpix_Sync_Sweeps::usage_sweep($args);
    }

    /** @see Fastpix_Sync_Sweeps::sync_state() */
    public static function sync_state($scope) {
        return Fastpix_Sync_Sweeps::sync_state($scope);
    }
}
