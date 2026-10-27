<?php
/**
 * Applying one platform media record — the internals behind
 * Fastpix_Sync::apply_media() (RULE-022 ownership, RULE-023 monotonic status).
 *
 * Split out of class-fastpix-sync.php for size only; verdicts, field
 * ownership and the poll-chain rules are unchanged.
 */

namespace Fastpix;

if (!defined('WPINC')) {
    die;
}

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery
// All SQL here runs against the plugin's own tables: identifiers come from the
// Fastpix_Schema::table() registry (a fixed whitelist, never request input) and
// every value travels through $wpdb->prepare(). Core APIs cannot query these tables.

class Fastpix_Sync_Apply {

    /** Platform-owned fields only. [RULE-022] */
    public static function platform_fields($media) {
        return array_filter(array(
            'workspace_id'         => Fastpix_Sync::field($media, array('workspaceId', 'workspace_id')),
            'access_policy'        => self::policy($media),
            'duration_seconds'     => Fastpix_Sync::duration_seconds(Fastpix_Sync::field($media, array('duration'))),
            'aspect_ratio'         => Fastpix_Sync::field($media, array('aspectRatio', 'aspect_ratio')),
            'max_resolution'       => Fastpix_Sync::field($media, array('maxResolution', 'max_resolution')),
            'mp4_support'          => self::mp4_support(Fastpix_Sync::field($media, array('mp4Support', 'mp4_support'))),
            'platform_updated_at'  => self::datetime(Fastpix_Sync::field($media, array('updatedAt', 'updated_at'))),
            'platform_created_at'  => self::datetime(Fastpix_Sync::field($media, array('createdAt', 'created_at'))),   // the library's newest-first order
        ), function ($value) { return $value !== null && $value !== ''; });
    }

    /** First sight of a media id: create the local row. Returns 'created'. */
    public static function insert_media($media, $media_id, $platform, $status, $now) {
        global $wpdb;

        $wpdb->insert(Fastpix_Schema::table('videos'), array_merge($platform, array(
            'media_id'   => $media_id,
            // Title/description are WordPress-owned once a row exists; on
            // first sight the platform's values seed them. [RULE-022]
            'title'      => (string) Fastpix_Sync::field($media, array('title')),
            'description'=> (string) Fastpix_Sync::field($media, array('description')),
            'status'     => $status !== '' ? $status : 'Created',
            'source'     => isset($media['_fastpix_source']) ? $media['_fastpix_source'] : 'Dashboard',
            'created_at' => $now,
            'updated_at' => $now,
        )));

        self::apply_playback_ids($media, (int) $wpdb->insert_id);
        self::schedule_poll_if_pending($media_id, $status);
        Fastpix_Cache::flush_group('videos');

        return 'created';
    }

    /** Existing row: apply platform-owned changes. Returns 'updated'|'unchanged'. */
    public static function update_media($media, $row, $platform, $status, $now) {
        global $wpdb;

        $update = $platform;
        if ($status !== '' && $status !== $row['status'] && Fastpix_Sync::can_advance($row['status'], $status)) {
            $update['status'] = $status;   // verbatim [ARCH-03]
        }
        // The platform answered for it, so an earlier 404 is reconciled: the
        // orphan flag comes off (it made the row read Unavailable). [ERR-040]
        if ((string) $row['error_code'] === 'orphaned') {
            $update['error_code'] = '';
        }

        // A title the dashboard changed is applied (QA #13); whatever is not applied is suggested.
        $title  = self::dashboard_title($media, $row, $platform);
        $update = array_merge($update, self::suggest_edits($title ? array_diff_key($media, array('title' => 1)) : $media, $row), $title);
        // The row's platform clock only moves forward: an older record must not wind it back,
        // or the next stale record would pass for news.
        if (!empty($row['platform_updated_at']) && !empty($update['platform_updated_at'])
            && strtotime($update['platform_updated_at'] . ' UTC') < strtotime($row['platform_updated_at'] . ' UTC')) {
            unset($update['platform_updated_at']);
        }

        // Anything platform-owned actually different?
        $changed = self::changed_columns($update, $row);

        // A playback id that came or went is a change too: the public embed cache is
        // keyed on updated_at and would keep serving a dead playback id for an hour. [QA L10]
        $playback_changed = self::apply_playback_ids($media, (int) $row['id']);

        if (!$changed && !$playback_changed) {
            return 'unchanged';
        }

        $changed['local_updated_at'] = $now;
        $changed['updated_at']       = $now;
        $wpdb->update(Fastpix_Schema::table('videos'), $changed, array('id' => $row['id']));
        Fastpix_Cache::flush_group('videos');
        if (isset($changed['title'])) {   // same follow-up a title edit made in WordPress gets
            Fastpix_Cache::flush_group('embed');
            Fastpix_Jobs::enqueue('fastpix_search_reindex', array('video_id' => (int) $row['id']), Fastpix_Jobs::GROUP_MAINTENANCE);
        }

        if (isset($changed['status'])) {
            self::schedule_poll_if_pending((string) $row['media_id'], $changed['status']);
        }

        return 'updated';
    }

    /** The platform-owned columns whose value really differs from the row ("12.500" equals 12.5). */
    private static function changed_columns($update, $row) { // NOSONAR php:S100 — WordPress snake_case naming
        $changed = array();
        foreach ($update as $column => $value) {
            $same = is_numeric($value) && is_numeric($row[$column])
                ? (float) $row[$column] === (float) $value
                : (string) $row[$column] === (string) $value;
            if (!$same) {
                $changed[$column] = $value;
            }
        }

        return $changed;
    }

    /**
     * Owner ruling 2026-09-21 (QA report #13), narrowing RULE-022 for the TITLE: a title
     * edited on the FastPix dashboard is applied here. Every title edit made in WordPress
     * is pushed to FastPix (or waits in the outbox), so a platform title that differs from
     * the local one IS a dashboard edit — unless
     *   - a local title edit for this media is still waiting in the outbox (local wins), or
     *   - the record is older than what this row already reflects (`updatedAt` behind
     *     `platform_updated_at`): a delayed webhook must not undo a newer local edit, or
     *   - the record carries no `updatedAt` at all (nothing to order it by).
     * In those cases the title stays a suggestion, as before. Descriptions are unchanged.
     */
    private static function dashboard_title($media, $row, $platform) { // NOSONAR php:S100 — WordPress snake_case naming
        $remote = (string) Fastpix_Sync::field($media, array('title'));
        $skip   = $remote === '' || $remote === (string) $row['title'] || empty($platform['platform_updated_at'])
            || (!empty($row['platform_updated_at']) && strtotime($platform['platform_updated_at'] . ' UTC') < strtotime($row['platform_updated_at'] . ' UTC'));
        $path = '/on-demand/' . rawurlencode((string) $row['media_id']);
        foreach ($skip ? array() : Fastpix_Outbox::entries() as $entry) {
            if ($entry['method'] === 'PATCH' && $entry['path'] === $path && isset($entry['body']['title'])) {
                $skip = true;   // a local title edit is still waiting: local wins
                break;
            }
        }
        if ($skip) {
            return array();
        }
        $update = array('title' => $remote);
        if (array_key_exists('suggested_title', $row)) {
            $update['suggested_title'] = null;   // applied: nothing left to suggest (suggest_edits() may have just filed it)
        }

        return $update;
    }

    /**
     * Dashboard edits the row does not take by itself arrive as SUGGESTIONS, never a
     * silent overwrite [RULE-022]: a platform title/description that differs from the
     * local one is stored beside the row for the owner to accept.
     */
    private static function suggest_edits($media, $row) {
        $update = array();
        if (!array_key_exists('suggested_title', $row)) {
            return $update;
        }
        foreach (array('title' => 'suggested_title', 'description' => 'suggested_description') as $field => $column) {
            $remote_value = (string) Fastpix_Sync::field($media, array($field));
            if ($remote_value !== '' && $remote_value !== (string) $row[$field] && $remote_value !== (string) $row[$column]) {
                $update[$column]        = $remote_value;
                $update['suggested_at'] = current_time('mysql', true);
            }
        }

        return $update;
    }

    /**
     * Playback ids inside a media record: upsert present, tombstone absent. [RULE-022]
     * @return bool true when an id was added, revived or tombstoned.
     */
    private static function apply_playback_ids($media, $video_row_id) {
        global $wpdb;

        $ids = Fastpix_Sync::field($media, array('playbackIds', 'playback_ids'));
        if (!is_array($ids) || !$video_row_id) {
            return false;
        }

        $table   = Fastpix_Schema::table('playback_ids');
        $now     = current_time('mysql', true);
        $seen    = array();
        $changed = false;
        $live    = $wpdb->get_col($wpdb->prepare("SELECT playback_id FROM {$table} WHERE video_id = %d AND deleted_at IS NULL", $video_row_id));

        foreach ($ids as $entry) {
            $pid = is_array($entry) ? Fastpix_Sync::field($entry, array('id', 'playbackId', 'playback_id')) : $entry;
            if (!$pid) {
                continue;
            }
            $seen[] = (string) $pid;
            if (!in_array((string) $pid, $live, true)) {
                $changed = true;   // new, or back from a tombstone
            }

            $wpdb->query($wpdb->prepare(
                "INSERT INTO {$table} (video_id, playback_id, access_policy, created_at, updated_at)
                 VALUES (%d, %s, %s, %s, %s)
                 ON DUPLICATE KEY UPDATE access_policy = VALUES(access_policy), deleted_at = NULL, updated_at = VALUES(updated_at)",
                $video_row_id, (string) $pid,
                is_array($entry) ? (string) Fastpix_Sync::field($entry, array('accessPolicy', 'access_policy', 'policy')) : '',
                $now, $now
            ));
        }

        return self::tombstone_absent($table, $video_row_id, $seen, (bool) $ids, $now) || $changed;
    }

    /**
     * Tombstone every live id not in $seen. An explicitly present EMPTY list means every
     * id is gone (the only one was deleted). An absent key never reaches here (is_array in
     * the caller), and a list of unparseable entries tombstones nothing. (QA L10)
     * @return bool true when a row was tombstoned.
     */
    private static function tombstone_absent($table, $video_row_id, $seen, $had_ids, $now) {
        global $wpdb;

        if (!$seen && $had_ids) {
            return false;
        }
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET deleted_at = %s WHERE video_id = %d AND deleted_at IS NULL"
             . ($seen ? ' AND playback_id NOT IN (' . implode(',', array_fill(0, count($seen), '%s')) . ')' : ''),
            array_merge(array($now, $video_row_id), $seen)
        ));

        return $wpdb->rows_affected > 0;
    }

    public static function schedule_poll_if_pending($media_id, $status) {
        if (Fastpix_Sync::rank($status) >= 2 || !Fastpix_Jobs::available()) {
            return;
        }
        // Webhooks configured ⇒ the platform tells us; the poll chain is the
        // fallback for sites still in polling mode. The sweeps remain the
        // universal repair either way.
        if (Fastpix_Webhooks::configured()) {
            return;
        }

        // One poll chain per video: the marker outlives the first backoff step,
        // and poll_media() reschedules itself, so a second chain never starts
        // while one is walking.
        if (Fastpix_Cache::get('poll', $media_id) !== false) {
            return;
        }
        Fastpix_Cache::set('poll', $media_id, 1, Fastpix_Sync::POLL_GIVE_UP);

        Fastpix_Jobs::schedule_at(time() + Fastpix_Sync::POLL_BACKOFFS[0], 'fastpix_poll_media', array(
            'media_id' => $media_id, 'attempt' => 1, 'first' => time(),
        ), Fastpix_Jobs::GROUP_SYNC);
    }

    /**
     * Platform mp4Support → local off|video|audio|both. The record carries either
     * the setting string (none|capped_4k|audioOnly|audioOnly,capped_4k) or a list
     * of renditions [{type: capped_4k|audioOnly, status}].
     */
    public static function mp4_support($value) {
        if ($value === null || $value === '') {
            return null;
        }
        list($video, $audio) = is_array($value) ? self::mp4_from_renditions($value) : self::mp4_from_setting($value);

        $out = $audio ? 'audio' : 'off';
        if ($video) {
            $out = $audio ? 'both' : 'video';
        }

        return $out;
    }

    private static function mp4_from_renditions($value) {
        $video = false;
        $audio = false;
        foreach ($value as $r) {
            $t = is_array($r) ? (string) Fastpix_Sync::field($r, array('type')) : (string) $r;
            if ($t === 'capped_4k') { $video = true; }
            if ($t === 'audioOnly') { $audio = true; }
        }

        return array($video, $audio);
    }

    private static function mp4_from_setting($value) {
        $v = strtolower((string) $value);

        return array(
            strpos($v, 'capped_4k') !== false || $v === 'video' || $v === 'both',
            strpos($v, 'audioonly') !== false || $v === 'audio' || $v === 'both',
        );
    }

    public static function policy($media) {
        $ids = Fastpix_Sync::field($media, array('playbackIds', 'playback_ids'));
        if (is_array($ids) && isset($ids[0]) && is_array($ids[0])) {
            $policy = Fastpix_Sync::field($ids[0], array('accessPolicy', 'access_policy', 'policy'));
            if ($policy) {
                return strtolower((string) $policy);
            }
        }

        $direct = Fastpix_Sync::field($media, array('accessPolicy', 'access_policy'));

        return $direct ? strtolower((string) $direct) : null;
    }

    public static function datetime($value) {
        if (!$value) {
            return null;
        }
        $time = is_numeric($value) ? (int) $value : strtotime((string) $value);

        return $time ? gmdate('Y-m-d H:i:s', $time) : null;
    }
}
