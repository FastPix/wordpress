<?php
/**
 * Media Library migration — the background workers (scan → transfer →
 * finalise). Split from Fastpix_Migration; the hooks and tests still address
 * that class, which delegates here. WF-004, REQ-020…029, REQ-122,
 * RULE-018/019/041/042, ERR-050…055.
 */

namespace Fastpix;

if (!defined('WPINC')) {
    die;
}

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery
// All SQL here runs against the plugin's own tables: identifiers come from the
// Fastpix_Schema::table() registry (a fixed whitelist, never request input) and
// every value travels through $wpdb->prepare(). Core APIs cannot query these tables.

class Fastpix_Migration_Scan {

    /* ---------------------------------------------------------------- scan */

    /**
     * Walk every video attachment (bounded, resumable by offset): size, format,
     * reachability from outside, usage — recorded per item with the skip reason
     * (ERR-050). Nothing moves. [REQ-021]
     */
    public static function scan_job($args) {
        global $wpdb;

        $args    += array('batch_id' => '', 'offset' => 0, 'left_out' => 0);
        $batch_id = (string) $args['batch_id'];
        $offset   = (int) $args['offset'];
        $batch    = Fastpix_Migration::batch($batch_id);
        if (!$batch || $batch['state'] !== 'scanning') {
            return;
        }

        $mimes = Fastpix_Uploads_Settings::mime_config();
        $accepted = array_map('strtolower', (array) $mimes['mime_types']);
        $started  = time();
        $step     = 50;
        $left_out = (int) $args['left_out'];   // rows without a file, carried across steps

        while (time() - $started < Fastpix_Migration::BUDGET) {
            $ids = self::scan_pending_ids($step, $offset);
            if (!$ids) {
                self::finish_scan($batch_id, $left_out);
                return;
            }
            foreach ($ids as $attachment_id) {
                // M2: each item may spend a 10 s reachability probe — the budget is checked per item, not per 50.
                // M20: a cancelled/replaced batch stops the walk here instead of filing rows for a batch that is gone.
                $batch = Fastpix_Migration::batch($batch_id);
                if (!$batch || $batch['state'] !== 'scanning' || time() - $started >= Fastpix_Migration::BUDGET) {
                    break 2;
                }
                $left_out += self::scan_item($batch_id, (int) $attachment_id, $accepted);
                $offset++;
            }
            $wpdb->update(Fastpix_Schema::table('migrations'), array('updated_at' => current_time('mysql', true)), array('batch_id' => $batch_id));
        }
        if (!$batch || $batch['state'] !== 'scanning') {
            return;   // cancelled or replaced mid-step — nothing to resume
        }

        // Budget spent — continue from here (REQ-122: nothing is lost across a stop).
        Fastpix_Jobs::enqueue(Fastpix_Migration::HOOK_SCAN, array('batch_id' => $batch_id, 'offset' => $offset, 'left_out' => $left_out), Fastpix_Jobs::GROUP_MIGRATION);
    }

    /**
     * Only video WordPress actually hosts: a real file on disk and not already
     * a FastPix video of the CONNECTED workspace (proxy mime, or FastPix meta
     * whose media lives in this workspace). A copy made under a previously
     * connected workspace does not count — it is offered again.
     */
    private static function scan_pending_ids($step, $offset) {
        global $wpdb;

        $ws_uuid  = Fastpix_Videos_Rest::connected_workspace();
        $videos_t = Fastpix_Schema::table('videos');
        // Always scoped (ASSUME-092): a copy in a previous workspace is offered again.
        $done_sql = "SELECT 1 FROM {$wpdb->postmeta} x JOIN {$videos_t} v ON v.media_id = x.meta_value
               WHERE x.post_id = p.ID AND x.meta_key = '_fastpix_media_id' AND x.meta_value <> ''
                 AND v.deleted_at IS NULL AND (v.workspace_id = %s OR v.workspace_id = '')";
        $sql = "SELECT p.ID FROM {$wpdb->posts} p
             WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE 'video/%%' AND p.post_mime_type <> %s
               AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} f WHERE f.post_id = p.ID AND f.meta_key = '_wp_attached_file' AND f.meta_value <> '')
               AND NOT EXISTS ({$done_sql})
             ORDER BY p.ID ASC LIMIT %d OFFSET %d";

        return $wpdb->get_col($wpdb->prepare($sql, Fastpix_Attachments::MIME, $ws_uuid, $step, $offset));
    }

    /** Files the item row; returns 1 when the attachment has no local file (left out, not listed), else 0. */
    private static function scan_item($batch_id, $attachment_id, $accepted) {
        global $wpdb;

        $path  = get_attached_file($attachment_id);
        $url   = wp_get_attachment_url($attachment_id);
        $mime  = strtolower((string) get_post_mime_type($attachment_id));
        $size  = $path && file_exists($path) ? (int) filesize($path) : 0;
        $state = 'pending';
        $skip  = '';

        // Already migrated (and not reverted) INTO THE CONNECTED WORKSPACE — nothing
        // to move. A copy made under a previously connected workspace is out of
        // reach for these credentials, so it is offered again.
        $ws_uuid = Fastpix_Videos_Rest::connected_workspace();
        $already = (int) $wpdb->get_var($wpdb->prepare(
            Fastpix_Migration::SQL_COUNT_FROM . Fastpix_Schema::table('migration_items') . ' m
               JOIN ' . Fastpix_Schema::table('videos') . " v ON v.id = m.video_id AND v.deleted_at IS NULL
             WHERE m.attachment_id = %d AND m.state IN ('submitted','cleaned') AND m.reverted_at IS NULL AND m.batch_id <> %s
               AND (v.workspace_id = %s OR v.workspace_id = '')",
            $attachment_id, $batch_id, $ws_uuid
        ));

        if (!$path || !file_exists($path)) {
            return 1;   // no local file ⇒ nothing WordPress hosts here (ERR-052 is stated per item at submit time, not listed)
        }
        if ($already) {
            $state = 'skipped'; $skip = __('Already on FastPix', 'fastpix');
        } elseif (!in_array($mime, $accepted, true)) {
            $state = 'skipped'; $skip = __('Format not accepted', 'fastpix');                        // ERR-050
        } elseif ($size > Fastpix_Migration::MAX_BYTES) {
            $state = 'skipped'; $skip = __('Over the 20 GB ceiling', 'fastpix');                     // ERR-050
        } elseif (!self::looks_like_video($path)) {
            $state = 'skipped'; $skip = __('The extension says video; the contents do not.', 'fastpix');   // ERR-053
        }

        $reachable = null;   // unknown until we check; only worth checking for movable items
        if ($state === 'pending') {
            $reachable = self::reachable_from_outside($url);
            if (!$reachable) {
                $skip = __('Not reachable from outside the site — will be uploaded from here instead.', 'fastpix');   // ERR-050 reroute (REQ-023)
            }
        }

        $now = current_time('mysql', true);
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . Fastpix_Schema::table('migration_items') . '
                 (batch_id, attachment_id, source_url, size_bytes, state, skip_reason, error_code, created_at, updated_at)
             VALUES (%s, %d, %s, %d, %s, %s, %s, %s, %s)
             ON DUPLICATE KEY UPDATE source_url = VALUES(source_url), size_bytes = VALUES(size_bytes), state = VALUES(state),
                                     skip_reason = VALUES(skip_reason), error_code = VALUES(error_code), updated_at = VALUES(updated_at)',
            $batch_id, $attachment_id, (string) $url, $size, $state, $skip, $reachable === false ? 'unreachable' : '', $now, $now
        ));
        // (QA M20) a cancel that landed during the probe above: the batch is gone, so this row goes too.
        // discard_scan() deletes the batch row first, so one of the two deletes always sees the row.
        if (!Fastpix_Migration::batch($batch_id)) {
            $wpdb->delete(Fastpix_Schema::table('migration_items'), array('batch_id' => $batch_id));
        }

        return 0;
    }

    private static function finish_scan($batch_id, $left_out = 0) {
        global $wpdb;

        $t     = Fastpix_Schema::table('migration_items');
        $count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t} WHERE batch_id = %s", $batch_id));
        $skip  = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t} WHERE batch_id = %s AND state = 'skipped'", $batch_id));
        $hit = $wpdb->update(Fastpix_Schema::table('migrations'), array(
            'state' => 'scanned', 'item_count' => $count, 'skipped_count' => $skip,
            'estimated_cost' => null,   // no pricing endpoint exists; the dashboard states cost
            'updated_at' => current_time('mysql', true),
        ), array('batch_id' => $batch_id, 'state' => 'scanning'));   // M1: a cancel that landed mid-step is never undone
        if ($hit && $left_out) {   // (QA M20) no counter left behind for a batch that was cancelled meanwhile
            update_option('fastpix_migration_left_out_' . $batch_id, (int) $left_out, false);   // rows without a file — reported, not listed
        }
    }

    /** ERR-053: a cheap sniff — the first bytes must look like a container we know. */
    public static function looks_like_video($path) {
        $fh = @fopen($path, 'rb');   // phpcs:ignore WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors -- 16-byte container sniff, unreadable file = not video
        if (!$fh) {
            return false;
        }
        $head = (string) fread($fh, 16);   // phpcs:ignore WordPress.WP.AlternativeFunctions
        fclose($fh);                       // phpcs:ignore WordPress.WP.AlternativeFunctions
        if (strlen($head) < 8) {
            return false;
        }
        $sigs = array("\x1A\x45\xDF\xA3", 'RIFF', 'FLV', "\x00\x00\x01\xBA", "\x00\x00\x01\xB3", "\x30\x26\xB2\x75", 'OggS', 'ID3', "\xFF\xFB", "\xFF\xF1", "\xFF\xF9", 'fLaC', "\x47");
        $ok = substr($head, 4, 4) === 'ftyp';   // MP4 / MOV / 3GP / M4V
        if (!$ok) {
            foreach ($sigs as $sig) {
                if (strpos($head, $sig) === 0) {
                    $ok = true;
                    break;
                }
            }
        }

        return $ok ? true : (bool) apply_filters('fastpix_migration_looks_like_video', false, $path, $head);
    }

    /** REQ-023: can the platform fetch this URL? A HEAD from this server is the cheapest honest proxy (filterable for tests). */
    public static function reachable_from_outside($url) {
        $pre = apply_filters('fastpix_migration_reachable', null, $url);
        if ($pre !== null) {
            return (bool) $pre;
        }
        $ok = $url && preg_match('#^https?://#i', $url);
        if ($ok) {
            $host = wp_parse_url($url, PHP_URL_HOST);
            $ok   = $host && !in_array(strtolower($host), array('localhost', '127.0.0.1', '::1'), true) && substr($host, -6) !== '.local';
        }

        return (bool) $ok && !is_wp_error(Fastpix_Uploads::validate_public_video_url($url));
    }

    /* ---------------------------------------------------------------- items */

    /**
     * One attachment. Four in flight at once; a paused/cancelled batch parks the
     * item; a failure is per item with its reason (ERR-051…053).
     */
    public static function item_job($args) {
        global $wpdb;

        $batch_id = isset($args['batch_id']) ? (string) $args['batch_id'] : '';
        $item_id  = isset($args['item_id']) ? (int) $args['item_id'] : 0;
        $t        = Fastpix_Schema::table('migration_items');
        $batch    = Fastpix_Migration::batch($batch_id);
        $item     = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE id = %d AND batch_id = %s", $item_id, $batch_id), ARRAY_A);
        if (!self::item_gate($batch, $item)) {
            return;
        }
        self::reap($batch_id);
        // Never more than four submissions in flight for this site.
        $inflight = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t} WHERE batch_id = %s AND state = 'submitting'", $batch_id));
        if ($inflight >= Fastpix_Migration::CONCURRENCY) {
            // M10: a finishing item pumps the next pending one (below); this long
            // reschedule is only the safety net that keeps the reaper running.
            Fastpix_Jobs::schedule_at(time() + 300, Fastpix_Migration::HOOK_ITEM, $args, Fastpix_Jobs::GROUP_MIGRATION);
            return;
        }

        // M9: the claim is atomic — a second job for the same item (a resume, the
        // pump and the storm overlapping) finds it taken and drops out.
        $now = current_time('mysql', true);
        if (!$wpdb->query($wpdb->prepare("UPDATE {$t} SET state = 'submitting', updated_at = %s WHERE id = %d AND state = 'pending'", $now, $item_id))) {
            return;
        }

        // A catchable Throwable (fopen→false into CURLOPT_INFILE, OOM) must land the
        // row in 'failed', not leave it stuck in 'submitting'.
        try {
            $result = self::submit($batch, $item);
        } catch (\Throwable $e) {
            $result = new \WP_Error('migration_item_exception', substr($e->getMessage(), 0, 191));
        }
        // M6: the completion write only lands on a row still 'submitting' — a row the
        // reaper already failed keeps its counters (no double count, no drift).
        $now = current_time('mysql', true);
        if (is_wp_error($result)) {
            $hit = $wpdb->query($wpdb->prepare("UPDATE {$t} SET state = 'failed', error_code = %s, skip_reason = %s, updated_at = %s WHERE id = %d AND state = 'submitting'",
                substr($result->get_error_code(), 0, 64), substr($result->get_error_message(), 0, 191), $now, $item_id));
            if ($hit) {
                $wpdb->query($wpdb->prepare(Fastpix_Migration::SQL_UPDATE . Fastpix_Schema::table('migrations') . ' SET failed_count = failed_count + 1, updated_at = %s WHERE batch_id = %s', $now, $batch_id));
            }
            do_action('fastpix_log', 'migration_item_failed', array('scope' => 'migration', 'severity' => 'warning', 'message' => 'Attachment ' . $item['attachment_id'] . ': ' . $result->get_error_message()));
        } else {
            $vid = (int) $result;   // 0 = pushed, linked by the upload webhook later
            $hit = $wpdb->query($wpdb->prepare("UPDATE {$t} SET state = 'submitted', video_id = NULLIF(%d, 0), error_code = '', skip_reason = '', updated_at = %s WHERE id = %d AND state = 'submitting'",
                $vid, $now, $item_id));
            Fastpix_Migration::reset_map();
            if ($hit) {
                $wpdb->query($wpdb->prepare(Fastpix_Migration::SQL_UPDATE . Fastpix_Schema::table('migrations') . ' SET submitted_count = submitted_count + 1, updated_at = %s WHERE batch_id = %s', $now, $batch_id));
            }
        }
        self::maybe_finish($batch_id);
        self::pump($batch_id);
    }

    /**
     * (QA reaper) Also called from the batch status read the UI polls: a batch whose
     * last items all wedge has no item_job left to reap them. Returns how many were failed.
     */
    public static function reap($batch_id) {
        global $wpdb;

        // Reclaim rows wedged in 'submitting': a worker killed mid-submit (OOM,
        // process kill during a multi-GB PUT) never reaches the failed/submitted
        // update, so the row pins the concurrency gate and maybe_finish never
        // fires — the batch then reschedules every 10s forever. 30 min is well
        // past any real submission (a killed worker's cURL PUT died incomplete, so
        // no platform copy exists; RULE-041's idempotency key covers a re-run).
        $t      = Fastpix_Schema::table('migration_items');
        $reaped = (int) $wpdb->query($wpdb->prepare(
            "UPDATE {$t} SET state = 'failed', error_code = 'timed_out', skip_reason = 'Submission did not complete (worker interrupted)', updated_at = %s
             WHERE batch_id = %s AND state = 'submitting' AND updated_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 MINUTE)",
            current_time('mysql', true), $batch_id));
        if ($reaped > 0) {
            $wpdb->query($wpdb->prepare(Fastpix_Migration::SQL_UPDATE . Fastpix_Schema::table('migrations') . ' SET failed_count = failed_count + %d, updated_at = %s WHERE batch_id = %s', $reaped, current_time('mysql', true), $batch_id));
            self::maybe_finish($batch_id);
        }

        return $reaped;
    }

    /**
     * M10: fill the free slots from the pending queue — no per-item polling every 10 s.
     * (QA M10) Run, resume and retry start the queue through here too ($min = 1), so an item
     * never holds a +300 s action AND a pumped one. With every slot wedged, that one item
     * parks at the gate and keeps the reaper running. Returns how many were queued.
     */
    public static function pump($batch_id, $min = 0) {
        global $wpdb;

        $t    = Fastpix_Schema::table('migration_items');
        $free = max((int) $min, Fastpix_Migration::CONCURRENCY - (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t} WHERE batch_id = %s AND state = 'submitting'", $batch_id)));
        if ($free <= 0) {
            return 0;
        }
        // ponytail: two items finishing together may both queue the same next item; the atomic claim makes the duplicate a no-op.
        $ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$t} WHERE batch_id = %s AND state = 'pending' ORDER BY id LIMIT %d", $batch_id, $free));
        foreach ($ids as $id) {
            Fastpix_Jobs::enqueue(Fastpix_Migration::HOOK_ITEM, array('batch_id' => $batch_id, 'item_id' => (int) $id), Fastpix_Jobs::GROUP_MIGRATION);
        }

        return count($ids);
    }

    /**
     * false drops the job: a missing/handled row, a paused batch, a cancelled batch.
     * (QA M10) A paused item is not re-checked every 60 s — resume re-queues the pending ones (Fastpix_Migration_Rest::pause_resume()).
     */
    private static function item_gate($batch, $item) {
        if (!$batch || !$item || $item['state'] !== 'pending') {
            return false;
        }

        return $batch['state'] === 'running';   // paused — waits for resume; cancelled — leave it pending → marked cancelled by cancel()
    }

    /** Hand the platform the URL (or push the file); returns the local video row id. */
    private static function submit($batch, $item) {
        $client        = new Fastpix_Api_Client();
        $settings      = Fastpix_Uploads_Settings::settings_snapshot(array('access_policy' => $batch['access_policy'], 'quality_tier' => $batch['quality_tier']));
        $media_body    = array('accessPolicy' => $batch['access_policy'] ?: 'public');
        if ($media_body['accessPolicy'] === 'drm') {
            $media_body['drmConfigurationId'] = Fastpix_Settings_Page::drm_configuration_id();
        }
        $key = 'migration:' . $batch['batch_id'] . ':' . (int) $item['attachment_id'];   // RULE-041 — a retried submission never makes a second copy
        if (in_array($item['error_code'], array('source_unfetchable', 'platform_failed', 'platform_deleted'), true)) {
            $key .= ':' . $item['error_code'];   // the first attempt consumed the plain key and its media failed/vanished on the platform; this is a distinct create (M24)
        }

        // M3: a reverted-then-rescanned attachment whose copy is still live on the
        // platform is re-linked, not copied a second time.
        $existing = self::live_copy_of((int) $item['attachment_id']);
        if ($existing) {
            update_post_meta((int) $item['attachment_id'], '_fastpix_video_id', (int) $existing['id']);
            update_post_meta((int) $item['attachment_id'], '_fastpix_media_id', $existing['media_id']);

            return (int) $existing['id'];
        }

        $media = self::create_media($client, $item, $media_body, $key);
        if (is_wp_error($media)) {
            return $media;
        }

        return self::link_media_row($batch, $item, $media, $settings);
    }

    /**
     * Re-check at submit time (a marker can be overwritten by an earlier failure);
     * a URL the outside world cannot reach — localhost, private nets, login-walled —
     * is pushed from this server instead. [REQ-023]
     */
    private static function create_media($client, $item, $media_body, $key) {
        $attachment_id = (int) $item['attachment_id'];
        // platform_failed: the URL copy never became playable — the retry takes the other road (push).
        $unreachable   = in_array($item['error_code'], array('unreachable', 'source_unfetchable', 'platform_failed'), true)
            || !self::reachable_from_outside((string) $item['source_url']);

        return $unreachable
            ? self::push_file($client, $attachment_id, $media_body, $key)   // REQ-023 reroute
            : self::fetch_by_url($client, $item, $media_body, $key, $attachment_id);
    }

    /** Ask the platform to fetch the URL; a refusal reroutes through the push path. */
    private static function fetch_by_url($client, $item, $media_body, $key, $attachment_id) {
        $result = $client->request('POST', '/on-demand', array(
            'idempotency_row_id' => $key,
            'context'            => 'background',
            'body'               => array_merge(array('inputs' => array(array('type' => 'video', 'url' => (string) $item['source_url']))), $media_body),
        ));
        if (is_wp_error($result)) {
            // ERR-051: the platform could not fetch it (host refused) — re-queue through the upload path.
            $data = $result->get_error_data();
            $code = is_array($data) && isset($data['status']) ? (int) $data['status'] : 0;

            return in_array($code, array(400, 403, 404, 422), true) ? self::push_file($client, $attachment_id, $media_body, $key) : $result;
        }
        $body = is_array($result['body']) ? $result['body'] : array();

        return isset($body['data']) && is_array($body['data']) ? $body['data'] : $body;
    }

    /** A Ready, non-deleted migrated copy of this attachment in the connected workspace (a reverted item's copy), or null. */
    private static function live_copy_of($attachment_id) {
        global $wpdb;

        // ponytail: the copy keeps the policy/tier it was made with; the new batch's choice is not re-applied to it.
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT id, media_id FROM ' . Fastpix_Schema::table('videos') . " WHERE attachment_id = %d AND source = 'Migrated' AND status = 'Ready'
               AND deleted_at IS NULL AND error_code <> 'orphaned' AND (workspace_id = %s OR workspace_id = '') ORDER BY id DESC LIMIT 1",
            $attachment_id, Fastpix_Videos_Rest::connected_workspace()
        ), ARRAY_A);

        return $row ? $row : null;
    }

    /** The platform answered: apply the media and stamp the origin pointer [REQ-024]; returns the local video row id. */
    private static function link_media_row($batch, $item, $media, $settings) {
        global $wpdb;

        $attachment_id = (int) $item['attachment_id'];
        $media_id      = (string) Fastpix_Sync::field($media, array('id', 'mediaId', 'media_id'));
        if ($media_id === '' && !empty($media['_upload_id'])) {
            // Pushed: the platform names the media through the upload webhook; remember the upload so the item can be linked then.
            $wpdb->update(Fastpix_Schema::table('migration_items'), array('idempotency_key' => 'upload:' . $media['_upload_id']), array('id' => (int) $item['id']));
            return 0;   // submitted, video pending
        }
        if ($media_id === '') {
            return new \WP_Error('fastpix_no_media', __('FastPix did not return a media id.', 'fastpix'));
        }
        $media['_fastpix_source'] = 'Migrated';
        Fastpix_Sync::apply_media($media);
        Fastpix_Ai::park_settings($media_id, $settings);   // WF-005 runs per migrated video on ready

        $video_id = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . Fastpix_Schema::table('videos') . ' WHERE media_id = %s', $media_id));
        if ($video_id) {
            // The origin pointer [REQ-024]: the ORIGINAL attachment, not a proxy (there is a file already).
            $wpdb->update(Fastpix_Schema::table('videos'), array(
                'attachment_id' => $attachment_id, 'source' => 'Migrated', 'title' => get_the_title($attachment_id) ?: null,
                'author_id' => (int) get_post_field('post_author', $attachment_id), 'quality_tier' => (string) $batch['quality_tier'],
                'updated_at' => current_time('mysql', true),
            ), array('id' => $video_id));
            update_post_meta($attachment_id, '_fastpix_video_id', $video_id);
            update_post_meta($attachment_id, '_fastpix_media_id', $media_id);
            Fastpix_Uploads_Ingest::push_title($media_id, array('title' => get_the_title($attachment_id)));   // the dashboard shows the same name (QA F7)
        }

        return $video_id ?: new \WP_Error('fastpix_no_row', __('The video row could not be created.', 'fastpix'));
    }

    /* ---------------------------------------------------------------- push */

    /**
     * Upload path from the server: create a session, stream the file with a
     * single PUT (curl, no memory copy). Bounded to PUSH_MAX; beyond it the
     * item states why. Returns the media record from the upload session.
     */
    private static function push_file($client, $attachment_id, $media_body, $key) {
        $path = get_attached_file($attachment_id);
        $err  = self::push_guard($path);
        if ($err) {
            return $err;
        }
        $data = self::push_stream($client, $attachment_id, $path, $media_body, $key);
        if (is_wp_error($data)) {
            return $data;
        }

        return self::finish_push($client, $attachment_id, $path, $media_body, $key, $data);
    }

    /** WP_Error when the file cannot be pushed from this server, null when it can. */
    private static function push_guard($path) {
        $err = null;
        if (!$path || !file_exists($path)) {
            $err = new \WP_Error('fastpix_file_missing', __('The attachment row exists but the file does not.', 'fastpix'));
        } elseif ((int) filesize($path) > Fastpix_Migration::PUSH_MAX) {
            $err = new \WP_Error('fastpix_push_too_large', __('Not reachable from outside the site and too large to upload from here (over 2 GB). Make the uploads folder publicly reachable and retry.', 'fastpix'));
        } elseif (!function_exists('curl_init')) {
            $err = new \WP_Error('fastpix_no_curl', __('This server cannot stream the file (cURL missing).', 'fastpix'));
        }

        return $err;
    }

    /** Create the upload session and stream the file to it; returns the session data array, or WP_Error. */
    private static function push_stream($client, $attachment_id, $path, $media_body, $key) {
        $session = $client->request('POST', '/on-demand/upload', array(
            'idempotency_row_id' => $key . ':push', 'context' => 'background',
            'body' => array('corsOrigin' => home_url(), 'pushMediaSettings' => $media_body),
        ));
        if (is_wp_error($session)) {
            return $session;
        }
        $data = isset($session['body']['data']) ? $session['body']['data'] : (array) $session['body'];
        $url  = (string) Fastpix_Sync::field($data, array('url', 'uploadUrl', 'signedUrl'));
        if ($url === '') {
            return new \WP_Error('fastpix_no_signed_url', __('FastPix did not return an upload URL.', 'fastpix'));
        }
        $err = self::stream_put($path, $url, $attachment_id);

        return $err ? $err : $data;
    }

    /** The streaming PUT itself; WP_Error on failure, null when the platform took the bytes. */
    private static function stream_put($path, $url, $attachment_id) {
        $size = (int) filesize($path);
        // phpcs:disable WordPress.WP.AlternativeFunctions -- streaming PUT of up to 2 GB; wp_remote_* buffers the whole body in memory.
        $fh = fopen($path, 'rb');
        $ch = curl_init($url);
        // M6: a multi-GB PUT outlives the 30-minute reaper — heartbeat the row's
        // updated_at while bytes move, and abort a transfer stalled for 10 min so
        // a dead connection fails here long before the reaper could touch it.
        global $wpdb;
        $beat = time();
        curl_setopt_array($ch, array(
            CURLOPT_PUT => true, CURLOPT_INFILE => $fh, CURLOPT_INFILESIZE => $size,
            CURLOPT_HTTPHEADER => array('Content-Type: ' . (get_post_mime_type($attachment_id) ?: 'application/octet-stream')),
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 0, CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_LOW_SPEED_LIMIT => 1, CURLOPT_LOW_SPEED_TIME => 600,
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => function () use (&$beat, $attachment_id, $wpdb) {
                if (time() - $beat >= 60) {
                    $beat = time();
                    $wpdb->query($wpdb->prepare('UPDATE ' . Fastpix_Schema::table('migration_items') . " SET updated_at = %s WHERE attachment_id = %d AND state = 'submitting'", current_time('mysql', true), $attachment_id));
                }

                return 0;
            },
        ));
        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err    = curl_error($ch);
        curl_close($ch);
        fclose($fh);
        // phpcs:enable WordPress.WP.AlternativeFunctions
        if ($status < 200 || $status >= 300) {
            /* translators: %s: error message or HTTP status */
            return new \WP_Error('fastpix_push_failed', sprintf(__('The upload from this server did not complete (%s).', 'fastpix'), $err ?: 'HTTP ' . $status));
        }

        return null;
    }

    /**
     * The upload session becomes a media on the platform's side; the plugin
     * learns which one from the video.upload.media_created webhook (or the
     * poll fallback), through the same uploads row the browser path uses.
     * The item is 'submitted' now and linked to its video when that lands.
     */
    private static function finish_push($client, $attachment_id, $path, $media_body, $key, $data) {
        $upload_id = (string) Fastpix_Sync::field($data, array('uploadId', 'id', 'upload_id'));
        $media_id  = (string) Fastpix_Sync::field($data, array('mediaId', 'media_id', 'assetId'));
        if ($media_id === '' && $upload_id !== '') {
            // The media created from a direct upload carries the upload id as its
            // media id — a GET on it confirms; the webhook remains the fallback.
            $probe = $client->request('GET', '/on-demand/' . rawurlencode($upload_id), array('context' => 'background'));
            if (!is_wp_error($probe)) {
                $media_id = $upload_id;
            }
        }
        global $wpdb;
        $size = (int) filesize($path);
        $url  = (string) Fastpix_Sync::field($data, array('url', 'uploadUrl', 'signedUrl'));
        $now  = current_time('mysql', true);
        $wpdb->insert(Fastpix_Schema::table('uploads'), array(
            'upload_id' => $upload_id, 'filename' => basename($path), 'filesize' => $size, 'chunk_size' => $size, 'state' => 'completed',
            'bytes_sent' => $size, 'signed_url' => $url, 'user_id' => get_current_user_id(),
            'settings_json' => wp_json_encode(array_merge($media_body, array('access_policy' => $media_body['accessPolicy'], '_migration_attachment' => (int) $attachment_id, '_migration_key' => $key))),
            'created_at' => $now, 'updated_at' => $now,
        ));

        return array('id' => $media_id, 'status' => 'Preparing', '_upload_id' => $upload_id);
    }

    /** The upload webhook named the media: link the item that pushed this upload. */
    public static function bind_pushed_upload($upload_id, $video_id) {
        global $wpdb;

        $t   = Fastpix_Schema::table('migration_items');
        $row = $wpdb->get_row($wpdb->prepare("SELECT id, batch_id, attachment_id FROM {$t} WHERE idempotency_key = %s AND state = 'submitted' AND video_id IS NULL", 'upload:' . $upload_id), ARRAY_A);
        if (!$row) {
            return;
        }
        $wpdb->update($t, array('video_id' => (int) $video_id, 'updated_at' => current_time('mysql', true)), array('id' => (int) $row['id']));
        $batch = Fastpix_Migration::batch($row['batch_id']);
        $wpdb->update(Fastpix_Schema::table('videos'), array('source' => 'Migrated', 'attachment_id' => (int) $row['attachment_id'],
            'title' => get_the_title((int) $row['attachment_id']) ?: null, 'quality_tier' => $batch ? (string) $batch['quality_tier'] : '', 'updated_at' => current_time('mysql', true)), array('id' => (int) $video_id));
        update_post_meta((int) $row['attachment_id'], '_fastpix_video_id', (int) $video_id);
        Fastpix_Uploads_Ingest::push_title($upload_id, array('title' => get_the_title((int) $row['attachment_id'])));   // media id = upload id (QA F7)
        Fastpix_Migration::reset_map();
    }

    /** Batch bookkeeping: `done` once nothing is pending/submitting. */
    private static function maybe_finish($batch_id) {
        global $wpdb;

        $left = (int) $wpdb->get_var($wpdb->prepare(
            Fastpix_Migration::SQL_COUNT_FROM . Fastpix_Schema::table('migration_items') . " WHERE batch_id = %s AND state IN ('pending','submitting')", $batch_id
        ));
        if ($left === 0) {
            $wpdb->query($wpdb->prepare(Fastpix_Migration::SQL_UPDATE . Fastpix_Schema::table('migrations') . " SET state = 'done', updated_at = %s WHERE batch_id = %s AND state = 'running'", current_time('mysql', true), $batch_id));
        }
    }

    /* -------------------------------------------------------------- finalise */

    /**
     * Deletes local files of VERIFIED items in this batch only (ERR-055: "The
     * plugin will not delete a file it never successfully copied."). The
     * attachment stays; playback comes from FastPix. Permanent — revert stops
     * being possible for these (RULE-019).
     */
    public static function finalise_job($args) {
        global $wpdb;

        $batch_id = isset($args['batch_id']) ? (string) $args['batch_id'] : '';
        $t = Fastpix_Schema::table('migration_items');
        $v = Fastpix_Schema::table('videos');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT i.id, i.attachment_id FROM {$t} i JOIN {$v} vv ON vv.id = i.video_id
             WHERE i.batch_id = %s AND i.state = 'submitted' AND i.reverted_at IS NULL AND vv.status = 'Ready' AND vv.deleted_at IS NULL
               AND vv.error_code <> 'orphaned'   /* an orphan keeps Ready + its playback ids, but the local file is the only copy (QA F5) */
               AND EXISTS (SELECT 1 FROM " . Fastpix_Schema::table('playback_ids') . " p WHERE p.video_id = vv.id AND p.deleted_at IS NULL)",
            $batch_id
        ), ARRAY_A);
        $started = time();
        $out = array('removed' => 0, 'failed' => 0, 'remaining' => 0);
        foreach ($rows as $r) {
            if (time() - $started > Fastpix_Migration::BUDGET) {
                $out['remaining']++;
                continue;   // counted, then handed to the background job below
            }
            // QA F5 (2026-09-20): count what is actually gone from disk. A file that survives
            // wp_delete_file (permissions, an offload plugin's filter) keeps its item un-cleaned
            // and the batch open, instead of "N files removed" over files still there.
            if (self::delete_item_files((int) $r['attachment_id'])) {
                $wpdb->update($t, array('state' => 'cleaned', 'updated_at' => current_time('mysql', true)), array('id' => (int) $r['id']));
                $out['removed']++;
            } else {
                $out['failed']++;
                do_action('fastpix_log', 'migration_cleanup_failed', array('scope' => 'migration', 'severity' => 'error', 'message' => sprintf('Batch %s: the local file of attachment %d could not be removed — check file permissions or an offloading plugin.', $batch_id, (int) $r['attachment_id'])));
            }
        }
        if ($out['remaining'] > 0) {
            // (QA M7) 0 = no Action Scheduler: the screen asks for another Clean up instead of promising a background job.
            $out['background'] = Fastpix_Jobs::enqueue(Fastpix_Migration::HOOK_FINALISE, array('batch_id' => $batch_id), Fastpix_Jobs::GROUP_MIGRATION) > 0;
        } elseif ($out['failed'] === 0) {
            $wpdb->update(Fastpix_Schema::table('migrations'), array('state' => 'cleaned', 'finished_at' => current_time('mysql', true), 'updated_at' => current_time('mysql', true)), array('batch_id' => $batch_id));
        }
        do_action('fastpix_log', 'migration_cleaned', array('scope' => 'migration', 'severity' => $out['failed'] ? 'warning' : 'info', 'message' => sprintf('Batch %s: %d local files removed, %d could not be removed, %d left for the background job.', $batch_id, $out['removed'], $out['failed'], $out['remaining'])));

        return $out;
    }

    /** The original file, plus any generated stills next to it. True when nothing of it is left on disk. */
    private static function delete_item_files($attachment_id) {
        $path = get_attached_file($attachment_id);
        if (!$path) {
            return true;   // nothing recorded on disk — nothing to remove
        }
        if (file_exists($path)) {
            wp_delete_file($path);
            $meta  = (array) wp_get_attachment_metadata($attachment_id);
            $files = array_column(isset($meta['sizes']) ? (array) $meta['sizes'] : array(), 'file');   // any generated stills next to it
            if (!empty($meta['original_image'])) {   // the -original companion of a -scaled file
                $files[] = $meta['original_image'];
            }
            foreach (array_filter($files) as $file) {
                wp_delete_file(dirname($path) . '/' . $file);
            }
        }
        clearstatcache(true, $path);

        return !file_exists($path);
    }
}
