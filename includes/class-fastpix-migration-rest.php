<?php
/**
 * Media Library migration — the REST surface (start/run/pause/cancel/retry/
 * cleanup/revert plus the reads). Split from Fastpix_Migration, which still
 * owns the routes and delegates its callbacks here. WF-004, FR-021,
 * REQ-024/027/028/029, RULE-007/019, ERR-054, API-P08.
 */

namespace Fastpix;

if (!defined('WPINC')) {
    die;
}

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery
// All SQL here runs against the plugin's own tables: identifiers come from the
// Fastpix_Schema::table() registry (a fixed whitelist, never request input) and
// every value travels through $wpdb->prepare(). Core APIs cannot query these tables.

class Fastpix_Migration_Rest {

    /* ---------------------------------------------------------------- scan */

    /** M7: every route that queues background work says so when the queue is not there, instead of reporting success. */
    private static function jobs_unavailable() {
        return Fastpix_Jobs::available() ? null
            : new \WP_Error('fastpix_jobs_unavailable', __('Background jobs are unavailable on this site (Action Scheduler is not loaded), so the migration cannot run.', 'fastpix-io'), array('status' => 503));
    }

    /** (QA M7) Cancelling queued jobs is a no-op — never a fatal — where Action Scheduler is not loaded. */
    private static function unschedule($hook) {
        if (Fastpix_Jobs::available()) {
            as_unschedule_all_actions($hook, null, Fastpix_Jobs::GROUP_MIGRATION);
        }
    }

    /** M20: an un-run scan leaves nothing behind — its job, its items, its left-out counter. */
    private static function discard_scan($batch_id) {
        global $wpdb;

        self::unschedule(Fastpix_Migration::HOOK_SCAN);
        $wpdb->delete(Fastpix_Schema::table('migrations'), array('batch_id' => $batch_id));   // (QA M20) batch first: a scan step still probing sees it gone and removes its own late row
        $wpdb->delete(Fastpix_Schema::table('migration_items'), array('batch_id' => $batch_id));
        delete_option('fastpix_migration_left_out_' . $batch_id);
    }

    /** POST /migration/scan — a new batch in `scanning`; the walk runs in the background. */
    public static function start_scan() {
        global $wpdb;

        $err  = Fastpix_Credentials::has_pair() ? self::jobs_unavailable() : new \WP_Error('fastpix_not_connected', __('Connect this site to FastPix first.', 'fastpix-io'), array('status' => 409));
        $open = Fastpix_Migration::latest_batch(array('scanning', 'scanned', 'running', 'paused'));
        if (!$err && $open && in_array($open['state'], array('running', 'paused'), true)) {
            $err = new \WP_Error('fastpix_migration_busy', __('A migration is already running. Finish or cancel it before scanning again.', 'fastpix-io'), array('status' => 409));
        }
        if ($err) {
            return $err;
        }
        if ($open) {
            self::discard_scan($open['batch_id']);   // a stale un-run scan is replaced — nothing moved, nothing to keep
        }

        $now      = current_time('mysql', true);
        $batch_id = 'mig_' . gmdate('Ymd_His') . '_' . wp_generate_password(6, false, false);
        $wpdb->insert(Fastpix_Schema::table('migrations'), array(
            'batch_id' => $batch_id, 'state' => 'scanning', 'started_by' => get_current_user_id(),
            'created_at' => $now, 'updated_at' => $now,
        ));
        Fastpix_Jobs::enqueue(Fastpix_Migration::HOOK_SCAN, array('batch_id' => $batch_id, 'offset' => 0), Fastpix_Jobs::GROUP_MIGRATION);

        return rest_ensure_response(array('batch_id' => $batch_id, 'state' => 'scanning'));
    }

    /* ---------------------------------------------------------------- run */

    /** The three ways a run request is refused up front, or null to proceed. */
    private static function run_refusal($batch, $policy) {
        $err = null;
        if (!$batch) {
            $err = new \WP_Error('fastpix_batch_missing', __('Unknown batch.', 'fastpix-io'), array('status' => 404));
        } elseif ($batch['state'] !== 'scanned') {
            $err = new \WP_Error('fastpix_batch_state', __('This batch is not ready to run — scan first, or it already ran.', 'fastpix-io'), array('status' => 409));
        } elseif ($policy === 'drm' && Fastpix_Settings_Page::drm_configuration_id() === '') {
            $err = new \WP_Error('fastpix_drm_unconfigured', __('DRM needs a DRM configuration ID — add it under FastPix → Settings first.', 'fastpix-io'), array('status' => 409));
        }

        return $err;
    }

    /** POST /migration/run — the one up-front decision (tier + policy, RULE-007), then queue. [FR-021] */
    public static function run($request) {
        global $wpdb;

        $batch  = Fastpix_Migration::batch((string) $request->get_param('batch_id'));
        $policy = (string) ($request->get_param('access_policy') ?: 'public');
        $err    = self::run_refusal($batch, $policy) ?: self::jobs_unavailable();
        $scope  = $request->get_param('scope') === 'selection' ? 'selection' : 'all';
        $ids    = array_filter(array_map('intval', (array) $request->get_param('ids')));
        if (!$err && $scope === 'selection' && !$ids) {
            $err = new \WP_Error('fastpix_nothing_selected', __('Choose at least one video.', 'fastpix-io'), array('status' => 400));
        }
        if ($err) {
            return $err;
        }

        $t   = Fastpix_Schema::table('migration_items');
        // M9: claim the batch atomically — a second concurrent run finds it taken.
        $now = current_time('mysql', true);
        $claimed = $wpdb->update(Fastpix_Schema::table('migrations'), array(
            'state' => 'running', 'quality_tier' => (string) ($request->get_param('quality_tier') ?: 'standard'), 'access_policy' => $policy,
            'started_by' => get_current_user_id(), 'updated_at' => $now,
        ), array('batch_id' => $batch['batch_id'], 'state' => 'scanned'));
        $items = array();
        if (!$claimed) {
            $err = new \WP_Error('fastpix_batch_state', __('This batch is not ready to run — scan first, or it already ran.', 'fastpix-io'), array('status' => 409));
        } else {
            if ($scope === 'selection') {
                $wpdb->query($wpdb->prepare(
                    "UPDATE {$t} SET state = 'excluded' WHERE batch_id = %s AND state = 'pending' AND attachment_id NOT IN (" . implode(',', $ids) . ')',
                    $batch['batch_id']
                ));
            }
            $items = $wpdb->get_results($wpdb->prepare("SELECT id, attachment_id FROM {$t} WHERE batch_id = %s AND state = 'pending' ORDER BY id", $batch['batch_id']), ARRAY_A);
            if (!$items) {
                $wpdb->update(Fastpix_Schema::table('migrations'), array('state' => 'scanned', 'updated_at' => $now), array('batch_id' => $batch['batch_id'], 'state' => 'running'));
                $err = new \WP_Error('fastpix_nothing_to_move', __('Nothing in this scan can be moved.', 'fastpix-io'), array('status' => 400));
            }
        }
        if ($err) {
            return $err;
        }
        $wpdb->update(Fastpix_Schema::table('migrations'), array('item_count' => count($items)), array('batch_id' => $batch['batch_id']));

        Fastpix_Migration_Scan::pump($batch['batch_id'], 1);   // (QA M10) only the free slots are queued; each finishing item pumps the next
        do_action('fastpix_audit_event', 'migration_started', array('batch_id' => $batch['batch_id'], 'items' => count($items), 'access_policy' => $policy));

        return rest_ensure_response(array('batch_id' => $batch['batch_id'], 'state' => 'running', 'queued' => count($items)));
    }

    /* ------------------------------------------ pause / resume / cancel / retry */

    public static function pause_resume($request) {
        global $wpdb;

        $batch = Fastpix_Migration::batch((string) $request['id']);
        $op    = (string) $request['op'];
        $err   = null;
        if (!$batch) {
            $err = new \WP_Error('fastpix_batch_missing', __('Unknown batch.', 'fastpix-io'), array('status' => 404));
        } elseif ($op === 'pause' && $batch['state'] !== 'running') {
            $err = new \WP_Error('fastpix_batch_state', __('Only a running batch can be paused.', 'fastpix-io'), array('status' => 409));
        } elseif ($op === 'resume' && $batch['state'] !== 'paused') {
            $err = new \WP_Error('fastpix_batch_state', __('This batch is not paused.', 'fastpix-io'), array('status' => 409));
        }
        if ($err) {
            return $err;
        }
        if ($op === 'resume' && ($err = self::jobs_unavailable())) {
            return $err;
        }
        // M1: guarded transition — the batch must still be in the state just checked.
        $wpdb->update(Fastpix_Schema::table('migrations'), array('state' => $op === 'pause' ? 'paused' : 'running', 'updated_at' => current_time('mysql', true)), array('batch_id' => $batch['batch_id'], 'state' => $batch['state']));
        if ($op === 'resume') {
            do_action('fastpix_log', 'migration_resumed', array('scope' => 'migration', 'severity' => 'info', 'message' => 'Batch ' . $batch['batch_id'] . ' resumed at the first unsubmitted item.'));   // ERR-054
            // The queue restarts from the first still-pending item; the pump carries it through the rest. [REQ-122]
            self::unschedule(Fastpix_Migration::HOOK_ITEM);
            Fastpix_Migration_Scan::pump($batch['batch_id'], 1);   // (QA M10)
        }

        return self::get_batch($request);
    }

    /** DELETE /migration/{id} — cancel: pending items never move; what was submitted stays (it is a copy). */
    public static function cancel($request) {
        global $wpdb;

        $batch = Fastpix_Migration::batch((string) $request['id']);
        $err   = null;
        if (!$batch) {
            $err = new \WP_Error('fastpix_batch_missing', __('Unknown batch.', 'fastpix-io'), array('status' => 404));
        } elseif (!in_array($batch['state'], array('scanning', 'scanned', 'running', 'paused'), true)) {
            $err = new \WP_Error('fastpix_batch_state', __('This batch has already finished.', 'fastpix-io'), array('status' => 409));
        }
        if ($err) {
            return $err;
        }
        if (in_array($batch['state'], array('scanning', 'scanned'), true)) {
            self::discard_scan($batch['batch_id']);   // M20: nothing moved — the scan is dropped, not kept as a cancelled row
        } else {
            self::unschedule(Fastpix_Migration::HOOK_ITEM);
            $now = current_time('mysql', true);
            $wpdb->query($wpdb->prepare(Fastpix_Migration::SQL_UPDATE . Fastpix_Schema::table('migration_items') . " SET state = 'cancelled', updated_at = %s WHERE batch_id = %s AND state IN ('pending')", $now, $batch['batch_id']));
            $wpdb->update(Fastpix_Schema::table('migrations'), array('state' => 'cancelled', 'finished_at' => $now, 'updated_at' => $now), array('batch_id' => $batch['batch_id'], 'state' => $batch['state']));   // M1: guarded
            do_action('fastpix_audit_event', 'migration_cancelled', array('batch_id' => $batch['batch_id']));
        }

        return rest_ensure_response(array('batch_id' => $batch['batch_id'], 'state' => 'cancelled'));
    }

    /** POST /migration/{id}/retry — one failed item, or all failed items. Per item, never per batch. [REQ-029] */
    public static function retry_failed($request) {
        global $wpdb;

        $batch = Fastpix_Migration::batch((string) $request['id']);
        $err   = null;
        if (!$batch) {
            $err = new \WP_Error('fastpix_batch_missing', __('Unknown batch.', 'fastpix-io'), array('status' => 404));
        } elseif (!in_array($batch['state'], array('running', 'paused', 'done'), true)) {
            // M5: only a batch that ran and is still open to items can retry — never a cancelled or cleaned one.
            $err = new \WP_Error('fastpix_batch_state', __('This batch is finished — cancelled or cleaned up — and cannot retry items.', 'fastpix-io'), array('status' => 409));
        }
        $err = $err ?: self::jobs_unavailable();
        if ($err) {
            return $err;
        }
        $t    = Fastpix_Schema::table('migration_items');
        $v    = Fastpix_Schema::table('videos');
        $only = (int) $request->get_param('item_id');
        // Failed here, or accepted by the platform and then failed or deleted there (M4). A reverted
        // item is the owner's decision to stay local — never resurrected by a retry (M25).
        $sql  = "SELECT i.id, i.state, i.video_id, i.attachment_id, vv.id AS vid, vv.deleted_at, vv.error_code FROM {$t} i LEFT JOIN {$v} vv ON vv.id = i.video_id
                 WHERE i.batch_id = %s AND " . Fastpix_Migration::SQL_ITEM_FAILED . ($only ? ' AND i.id = %d' : '');
        $rows = $only ? $wpdb->get_results($wpdb->prepare($sql, $batch['batch_id'], $only), ARRAY_A) : $wpdb->get_results($wpdb->prepare($sql, $batch['batch_id']), ARRAY_A);
        if (!$rows) {
            return rest_ensure_response(array('queued' => 0));
        }
        $ids = array_map(function ($r) { return (int) $r['id']; }, $rows);
        $now = current_time('mysql', true);
        // Counters are UNSIGNED: GREATEST(0, n - 1) errors out at 0 instead of clamping — subtract LEAST(n, …).
        $counted_failed = 0;   // M17: only rows that landed in failed_count come back out of it
        foreach ($rows as $r) {
            $update = array('state' => 'pending', 'updated_at' => $now);
            if ($r['state'] === 'submitted') {
                // M24: say what happened; the submit path picks the road (a failed URL copy is pushed from here, a deleted copy is fetched again).
                // (QA M24/M4) orphaned, or purged after 30 days, is "gone" like deleted — fetched again by URL, not forced to push.
                $update['error_code']  = $r['vid'] === null || Fastpix_Sync::is_unavailable($r) ? 'platform_deleted' : 'platform_failed';
                $update['skip_reason'] = '';
                $update['video_id']    = null;
                $wpdb->update($v, array('deleted_at' => $now, 'updated_at' => $now), array('id' => (int) $r['video_id']));   // the failed copy is tombstoned locally
                delete_post_meta((int) $r['attachment_id'], '_fastpix_media_id');   // M24: the pointer to the dead copy goes
                delete_post_meta((int) $r['attachment_id'], '_fastpix_video_id');
                $wpdb->query($wpdb->prepare(Fastpix_Migration::SQL_UPDATE . Fastpix_Schema::table('migrations') . ' SET submitted_count = submitted_count - LEAST(submitted_count, 1) WHERE batch_id = %s', $batch['batch_id']));
            } else {
                $counted_failed++;
            }
            $wpdb->update($t, $update, array('id' => (int) $r['id']));
        }
        Fastpix_Migration::reset_map();
        $wpdb->query($wpdb->prepare(Fastpix_Migration::SQL_UPDATE . Fastpix_Schema::table('migrations') . ' SET failed_count = failed_count - LEAST(failed_count, %d), updated_at = %s WHERE batch_id = %s', $counted_failed, $now, $batch['batch_id']));
        $wpdb->query($wpdb->prepare(Fastpix_Migration::SQL_UPDATE . Fastpix_Schema::table('migrations') . " SET state = 'running' WHERE batch_id = %s AND state = 'done'", $batch['batch_id']));   // a paused batch stays paused
        Fastpix_Migration_Scan::pump($batch['batch_id'], 1);   // (QA M10)

        return rest_ensure_response(array('queued' => count($ids)));
    }

    /* ---------------------------------------------------- cleanup + revert */

    /** POST /migration/{id}/cleanup — typed confirmation "delete N files"; finalise job deletes verified items' files only. */
    public static function cleanup($request) {
        $batch = Fastpix_Migration::batch((string) $request['id']);
        if (!$batch) {
            return new \WP_Error('fastpix_batch_missing', __('Unknown batch.', 'fastpix-io'), array('status' => 404));
        }
        $verify   = Fastpix_Migration::verification($batch['batch_id']);
        $expected = sprintf('delete %d file%s', $verify['cleanable'], $verify['cleanable'] === 1 ? '' : 's');
        $typed    = preg_replace('/\s+/', ' ', trim(strtolower((string) $request->get_param('confirm'))));
        $err      = null;
        if (!$verify['verified'] || $verify['cleanable'] === 0) {
            $err = new \WP_Error('fastpix_not_verified', __('Cleanup is only offered for a batch whose items passed verification.', 'fastpix-io'), array('status' => 409));
        } elseif (!in_array($typed, array(sprintf('delete %d file', $verify['cleanable']), sprintf('delete %d files', $verify['cleanable'])), true)) {   // M26: "1 file" / "1 files" both pass; "filess" does not
            /* translators: %s: the exact confirmation phrase to type */
            $err = new \WP_Error('fastpix_confirm_mismatch', sprintf(__('Type "%s" to confirm.', 'fastpix-io'), $expected), array('status' => 400, 'expected' => $expected));
        }
        if ($err) {
            return $err;
        }
        do_action('fastpix_audit_event', 'migration_cleanup', array('batch_id' => $batch['batch_id'], 'files' => $verify['cleanable']));
        // QA F5 (2026-09-20): run the first 20 s of deletion right here — a site whose cron/Action
        // Scheduler never fires still gets its files removed; anything over budget goes to the job.
        $result = Fastpix_Migration_Scan::finalise_job(array('batch_id' => $batch['batch_id']));

        // (QA M7) `background` says whether the remainder really has a job — without Action Scheduler it does not.
        return rest_ensure_response(array('queued' => $verify['cleanable']) + $result);
    }

    /** POST /migration/items/{id}/revert — "restore local playback". No batch undo. [REQ-024/027] */
    public static function revert($request) {
        global $wpdb;

        $t    = Fastpix_Schema::table('migration_items');
        $item = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE id = %d", (int) $request['id']), ARRAY_A);
        $err  = null;
        if (!$item) {
            $err = new \WP_Error('fastpix_item_missing', __('Unknown item.', 'fastpix-io'), array('status' => 404));
        } elseif ($item['state'] === 'cleaned') {
            $err = new \WP_Error('fastpix_revert_impossible', __('The local file was removed at cleanup — there is nothing to revert to.', 'fastpix-io'), array('status' => 409));
        } elseif ($item['state'] !== 'submitted') {
            $err = new \WP_Error('fastpix_revert_state', __('Only a migrated video can be reverted.', 'fastpix-io'), array('status' => 409));
        }
        if ($err) {
            return $err;
        }
        if ($item['reverted_at'] !== null) {
            return rest_ensure_response(array('reverted' => true, 'already' => true));
        }
        $wpdb->update($t, array('reverted_at' => current_time('mysql', true), 'updated_at' => current_time('mysql', true)), array('id' => (int) $item['id']));
        // M3: the attachment is a local video again — the next scan offers it (and re-links the live copy instead of copying twice).
        delete_post_meta((int) $item['attachment_id'], '_fastpix_media_id');
        delete_post_meta((int) $item['attachment_id'], '_fastpix_video_id');
        Fastpix_Migration::reset_map();
        Fastpix_Cache::flush_group('embed');
        do_action('fastpix_audit_event', 'migration_reverted', array('item_id' => (int) $item['id'], 'attachment_id' => (int) $item['attachment_id']));

        return rest_ensure_response(array('reverted' => true));
    }

    /* ---------------------------------------------------------------- reads */

    public static function get_scan($request) {
        $batch = Fastpix_Migration::latest_batch(array('scanning', 'scanned'));
        if (!$batch) {
            return rest_ensure_response(array('batch' => null));
        }

        return rest_ensure_response(self::batch_payload($batch, $request, 'scan'));
    }

    public static function get_batch($request) {
        $batch = Fastpix_Migration::batch((string) $request['id']);
        if (!$batch) {
            return new \WP_Error('fastpix_batch_missing', __('Unknown batch.', 'fastpix-io'), array('status' => 404));
        }
        // (QA reaper) The UI polls this while a batch is open — the one path sure to run when the last items wedge in 'submitting'.
        // (QA M10 stall) Nothing parks as a keep-alive any more: when every worker died at once (restart, queue cleared) no item action is
        // pending or in progress, so this read refills the free slots. A healthy batch always has one, and nothing is queued for it.
        if ($batch['state'] === 'running' && (Fastpix_Migration_Scan::reap($batch['batch_id'])
            || (Fastpix_Jobs::available() && !as_has_scheduled_action(Fastpix_Migration::HOOK_ITEM, null, Fastpix_Jobs::GROUP_MIGRATION)))) {
            Fastpix_Migration_Scan::pump($batch['batch_id']);
            $batch = Fastpix_Migration::batch($batch['batch_id']);
        }

        Fastpix_Uploads_Ingest::bind_unbound(0);   // pushed items whose media_created webhook never arrived (QA F5)

        return rest_ensure_response(self::batch_payload($batch, $request, 'batch'));
    }

    /** GET /migration/history — every batch that actually moved something, newest first (empty scans and empty cancels never show). */
    public static function history() {
        global $wpdb;

        $rows = $wpdb->get_results(Fastpix_Migration::SQL_SELECT_FROM . Fastpix_Schema::table('migrations') . " WHERE state IN ('done','cleaned') OR (state = 'cancelled' AND submitted_count > 0) ORDER BY id DESC LIMIT 100", ARRAY_A);
        $out  = array();
        foreach ((array) $rows as $b) {
            $out[] = self::batch_summary($b);
        }
        // Also the running/paused one (it belongs to the current shape) and any scan awaiting a decision.
        return rest_ensure_response(array('batches' => $out, 'active' => Fastpix_Migration::latest_batch(array('scanning', 'scanned', 'running', 'paused'))));
    }

    private static function batch_summary($b) {
        global $wpdb;

        $verify = Fastpix_Migration::verification($b['batch_id']);
        $files  = (int) $wpdb->get_var($wpdb->prepare(Fastpix_Migration::SQL_COUNT_FROM . Fastpix_Schema::table('migration_items') . " WHERE batch_id = %s AND state = 'cleaned'", $b['batch_id']));

        return array(
            'batch_id' => $b['batch_id'], 'state' => $b['state'], 'quality_tier' => $b['quality_tier'], 'access_policy' => $b['access_policy'],
            'item_count' => (int) $b['item_count'], 'submitted_count' => (int) $b['submitted_count'], 'failed_count' => (int) $b['failed_count'],
            'skipped_count' => (int) $b['skipped_count'], 'estimated_cost' => $b['estimated_cost'] !== null ? (float) $b['estimated_cost'] : null,
            'started_by' => (int) $b['started_by'], 'created_at' => $b['created_at'], 'updated_at' => $b['updated_at'], 'finished_at' => $b['finished_at'],
            'verification' => $verify, 'local_files' => $b['state'] === 'cleaned' ? 'removed' : 'on_disk', 'files_removed' => $files,
        );
    }

    private static function batch_payload($batch, $request, $mode) {
        global $wpdb;

        $t        = Fastpix_Schema::table('migration_items');
        $page     = max(1, (int) $request->get_param('page'));
        $per_page = min(200, max(1, (int) $request->get_param('per_page') ?: 25));
        $filter   = (string) $request->get_param('filter');
        $where    = 'i.batch_id = %s';
        if ($filter === 'skipped') { $where .= " AND i.state = 'skipped'"; }
        elseif ($filter === 'movable') { $where .= " AND i.state <> 'skipped'"; }
        elseif ($filter === 'failed') { $where .= ' AND ' . Fastpix_Migration::SQL_ITEM_FAILED; }   // M4: the same rows verification counts as failed
        elseif ($filter === 'reverted') { $where .= ' AND i.reverted_at IS NOT NULL'; }

        $from  = "{$t} i LEFT JOIN " . Fastpix_Schema::table('videos') . ' vv ON vv.id = i.video_id';
        $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$from} WHERE {$where}", $batch['batch_id']));
        $rows  = $wpdb->get_results($wpdb->prepare("SELECT i.* FROM {$from} WHERE {$where} ORDER BY i.id LIMIT %d OFFSET %d", $batch['batch_id'], $per_page, ($page - 1) * $per_page), ARRAY_A);
        $items = array();
        foreach ((array) $rows as $r) {
            $items[] = self::item_payload($r, $mode === 'scan');
        }

        $sums = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) AS n, SUM(state <> 'skipped') AS movable, SUM(state = 'skipped') AS skipped, SUM(CASE WHEN state <> 'skipped' THEN size_bytes ELSE 0 END) AS bytes,
                    SUM(error_code = 'unreachable' AND state <> 'skipped') AS unreachable
             FROM {$t} WHERE batch_id = %s", $batch['batch_id']
        ), ARRAY_A);

        $payload = self::batch_summary($batch);
        $payload['totals'] = array(
            'scanned' => (int) $sums['n'], 'movable' => (int) $sums['movable'], 'skipped' => (int) $sums['skipped'],
            'bytes' => (int) $sums['bytes'], 'unreachable' => (int) $sums['unreachable'],
            'left_out' => (int) get_option('fastpix_migration_left_out_' . $batch['batch_id'], 0),   // attachment rows without a file
            'audience_change_posts' => $mode === 'scan' ? self::audience_change_posts($batch['batch_id']) : null,   // REQ-028 / MISS-004
        );
        $payload['items'] = array('rows' => $items, 'total' => $total, 'page' => $page, 'per_page' => $per_page);

        return $payload;
    }

    private static function item_payload($r, $with_usage) {
        $attachment = get_post((int) $r['attachment_id']);
        $path       = get_attached_file((int) $r['attachment_id']);
        $out = array(
            'id' => (int) $r['id'], 'attachment_id' => (int) $r['attachment_id'], 'video_id' => $r['video_id'] !== null ? (int) $r['video_id'] : null,
            'title' => $attachment ? $attachment->post_title : ('#' . $r['attachment_id']),
            'path' => $path ? str_replace(ABSPATH, '', $path) : '', 'source_url' => (string) $r['source_url'],
            'size_bytes' => (int) $r['size_bytes'], 'state' => $r['state'], 'skip_reason' => (string) $r['skip_reason'], 'error_code' => (string) $r['error_code'],
            'reverted_at' => $r['reverted_at'], 'file_present' => (bool) ($path && file_exists($path)),
        );
        if ($r['video_id']) {
            global $wpdb;
            $v = $wpdb->get_row($wpdb->prepare('SELECT status, media_id, access_policy, deleted_at, error_code FROM ' . Fastpix_Schema::table('videos') . Fastpix_Migration::SQL_WHERE_ID, (int) $r['video_id']), ARRAY_A);
            $out['video_status']  = $v ? $v['status'] : null;
            $out['video_gone']    = !$v || $v['deleted_at'] !== null || $v['error_code'] === 'orphaned';   // M4: deleted on FastPix (or no longer known to it)
            $out['media_id']      = $v ? $v['media_id'] : null;
            $out['access_policy'] = $v ? $v['access_policy'] : null;
            $out['playback_id']   = $v ? Fastpix_Attachments::playback_id((int) $r['video_id']) : '';
        }
        if ($with_usage) {
            $out['used_on_posts'] = self::posts_using_attachment((int) $r['attachment_id']);
        }

        return $out;
    }

    /** "Used on N posts" — wp:video {"id":N}, [video …file], <video …file>. Bounded match, admin-only read. */
    public static function posts_using_attachment($attachment_id) {
        global $wpdb;

        list($sql, $args) = self::usage_match($attachment_id);

        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT ID) FROM {$wpdb->posts} WHERE {$sql}", $args));
    }

    /**
     * M21: the WHERE for "a published post references this attachment" — the block id
     * is matched whole and only inside a wp:video block (never 1234, never another
     * block's "id"; same rule as the usage sweep's migrated_hits()) and the file only
     * after a slash (…/movie.mp4, never my-movie.mp4).
     */
    private static function usage_match($attachment_id) {
        global $wpdb;

        $file = basename((string) get_attached_file($attachment_id));
        $like = 'post_content REGEXP %s' . ($file !== '' ? ' OR post_content LIKE %s' : '');
        $args = array('wp:video[[:space:]]+[{][^}]*"id"[[:space:]]*:[[:space:]]*' . (int) $attachment_id . '[^0-9]');   // (QA M21)
        if ($file !== '') {
            $args[] = '%/' . $wpdb->esc_like($file) . '%';
        }

        return array("post_status = 'publish' AND post_type NOT IN ('attachment','revision') AND ({$like})", $args);
    }

    /** REQ-028: how many posts change audience if these public local files become private/DRM. */
    private static function audience_change_posts($batch_id) {
        global $wpdb;

        $ids   = $wpdb->get_col($wpdb->prepare('SELECT attachment_id FROM ' . Fastpix_Schema::table('migration_items') . " WHERE batch_id = %s AND state = 'pending' LIMIT 500", $batch_id));
        $posts = array();
        foreach ($ids as $id) {
            list($sql, $args) = self::usage_match((int) $id);
            foreach ($wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE {$sql}", $args)) as $pid) { $posts[(int) $pid] = true; }
        }

        return count($posts);
    }
}
