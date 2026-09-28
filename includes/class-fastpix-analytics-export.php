<?php
/**
 * Analytics CSV export — API-P05, WF-010, split out of Fastpix_Analytics
 * (class-size rule). The export option shape (Fastpix_Analytics::OPT_EXPORTS),
 * routes, and CSV columns are unchanged.
 */

namespace Fastpix;

if (!defined('WPINC')) {
    die;
}

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery
// All SQL here runs against the plugin's own tables: identifiers come from the
// Fastpix_Schema::table() registry (a fixed whitelist, never request input) and
// every value travels through $wpdb->prepare(). Core APIs cannot query these tables.

class Fastpix_Analytics_Export {

    /** POST /analytics/export [API-P05] — a background job; never inline. */
    public static function start_export($request) {
        if ((string) get_option(Fastpix_Connection::OPT_PENDING_LEAVE, '') !== '') {   // the stored rollup may be the previous workspace's [ASSUME-092]
            return new \WP_Error('fastpix_workspace_pending', __('Analytics for the newly connected workspace are not available until its first video syncs.', 'fastpix'), array('status' => 409));
        }
        if (!Fastpix_Jobs::available()) {
            return new \WP_Error('fastpix_jobs_unavailable', __('Background jobs are not running.', 'fastpix'), array('status' => 503));
        }

        $id  = substr(bin2hex(random_bytes(16)), 0, 16);
        $job = array(
            'id'          => $id,
            'state'       => 'running',
            'range'       => (string) $request->get_param('range'),
            'every_video' => (bool) $request->get_param('every_video'),
            'per_day'     => (bool) $request->get_param('per_day'),
            'device'      => (bool) $request->get_param('device'),
            'video'       => (int) $request->get_param('video'),
            'requested_at' => time(),
            'file'        => '',
            'rows'        => 0,
            // Capture the per-author scope NOW, while the user is known — the async
            // export_job has no current user, so it can't re-derive it. null = the
            // caller may read every video (EDIT_VIDEO); an array = only these ids.
            'scope'       => Fastpix_Analytics::scope_video_ids(),
        );

        $exports = (array) get_option(Fastpix_Analytics::OPT_EXPORTS, array());
        array_unshift($exports, $job);
        update_option(Fastpix_Analytics::OPT_EXPORTS, array_slice($exports, 0, Fastpix_Analytics::EXPORTS_KEPT), false);

        as_enqueue_async_action('fastpix_analytics_export', array(array('id' => $id)), Fastpix_Jobs::GROUP_ANALYTICS);

        return rest_ensure_response(array('id' => $id, 'state' => 'running'));
    }

    /** GET /analytics/export — recent exports, the screen polls this. */
    public static function list_exports() {
        $exports = (array) get_option(Fastpix_Analytics::OPT_EXPORTS, array());

        return rest_ensure_response(array('exports' => array_values(array_map(function ($e) {
            unset($e['file']);   // the path stays server-side; downloads go through the route
            return $e;
        }, $exports))));
    }

    /** The export job: full precision, day by day, every video in range (WF-010). */
    public static function export_job($args = array()) {
        $id      = isset($args['id']) ? (string) $args['id'] : '';
        $exports = (array) get_option(Fastpix_Analytics::OPT_EXPORTS, array());
        $index   = null;
        foreach ($exports as $i => $e) {
            if ($e['id'] === $id) {
                $index = $i;
                break;
            }
        }
        if ($index === null) {
            return;
        }
        $job  = $exports[$index];
        $rows = self::export_rows($job);

        $dir = wp_upload_dir();
        $path = trailingslashit($dir['basedir']) . 'fastpix-exports';
        wp_mkdir_p($path);
        if (!file_exists($path . '/index.html')) {
            file_put_contents($path . '/index.html', '');   // no listing
        }
        if (!file_exists($path . '/.htaccess')) {
            file_put_contents($path . '/.htaccess', "Deny from all\n");   // downloads go through the capability-checked route
        }

        $file = $path . '/analytics-' . $id . '.csv';
        // phpcs:ignore WordPress.WP.AlternativeFunctions -- streaming CSV writer; WP_Filesystem has no row-by-row append.
        $fh = fopen($file, 'w');
        fputcsv($fh, array('video', 'media_id', 'day', 'dimension', 'dimension_value', 'views', 'unique_viewers', 'watch_seconds', 'startup_ms_p50', 'startup_ms_p95', 'rebuffer_count', 'rebuffer_seconds', 'error_count'));
        foreach ($rows as $r) {
            fputcsv($fh, array(
                self::csv_text($r['title']), self::csv_text($r['media_id']), $job['per_day'] ? $r['day'] : '',
                self::csv_text($r['dimension']), self::csv_text($r['dimension_value']),
                (int) $r['views'], (int) $r['unique_viewers'], (int) $r['watch_seconds'],
                $r['startup_ms_p50'], $r['startup_ms_p95'],
                (int) $r['rebuffer_count'], (int) $r['rebuffer_seconds'], (int) $r['error_count'],
            ));
        }
        fclose($fh);   // phpcs:ignore WordPress.WP.AlternativeFunctions

        $job['state'] = 'ready';
        $job['file']  = $file;
        $job['rows']  = count($rows);
        $job['finished_at'] = time();

        $exports[$index] = $job;
        update_option(Fastpix_Analytics::OPT_EXPORTS, $exports, false);

        do_action('fastpix_log', 'analytics_export_ready', array(
            'scope' => 'analytics', 'severity' => 'info',
            'message' => sprintf('Export %s ready: %d rows', $id, count($rows)),
        ));
    }

    /**
     * Free-text cells (titles are editable by any EDIT_VIDEO user, dimension
     * values come from the platform) get a leading apostrophe when they start
     * with a formula trigger, so a spreadsheet shows text, never runs it.
     */
    private static function csv_text($value) {
        $value = (string) $value;

        return ($value !== '' && strpbrk($value[0], "=+-@\t\r") !== false) ? "'" . $value : $value;
    }

    /** The rollup rows one export job may see — range, scope, dimensions, grouping. */
    private static function export_rows($job) {
        global $wpdb;

        $from = $job['range'] === 'all' ? '1970-01-01' : gmdate('Y-m-d', time() - ((int) $job['range'] - 1) * DAY_IN_SECONDS);

        $where  = "a.day >= %s AND a.video_id > 0 AND (v.workspace_id = %s OR v.workspace_id = '')";   // the connected workspace only [ASSUME-092]
        $params = array($from, Fastpix_Videos_Rest::connected_workspace());
        if (!$job['every_video'] && $job['video'] > 0) {
            $where   .= ' AND a.video_id = %d';
            $params[] = (int) $job['video'];
        }
        // Enforce the author scope captured at request time: a non-EDIT_VIDEO
        // caller only exports their own videos (broken-access-control guard).
        if (isset($job['scope']) && is_array($job['scope'])) {
            $ids = array_map('intval', $job['scope']);
            $where .= $ids ? ' AND a.video_id IN (' . implode(',', $ids) . ')' : ' AND 1 = 0';
        }
        $where .= $job['device'] ? " AND a.dimension IN ('all','device','error_code')" : " AND a.dimension = 'all'";

        $group = $job['per_day'] ? 'a.video_id, a.day, a.dimension, a.dimension_value' : 'a.video_id, a.dimension, a.dimension_value';

        return $wpdb->get_results($wpdb->prepare(
            "SELECT a.video_id, " . ($job['per_day'] ? 'a.day' : 'MIN(a.day) day') . ", a.dimension, a.dimension_value,
                    SUM(a.views) views, SUM(a.unique_viewers) unique_viewers, SUM(a.watch_seconds) watch_seconds,
                    MAX(a.startup_ms_p50) startup_ms_p50, MAX(a.startup_ms_p95) startup_ms_p95,
                    SUM(a.rebuffer_count) rebuffer_count, SUM(a.rebuffer_seconds) rebuffer_seconds, SUM(a.error_count) error_count,
                    v.title, v.media_id
             FROM " . Fastpix_Schema::table('analytics_daily') . ' a
             LEFT JOIN ' . Fastpix_Schema::table('videos') . " v ON v.id = a.video_id
             WHERE {$where} GROUP BY {$group} ORDER BY a.video_id, day",
            $params
        ), ARRAY_A);
    }

    /** GET /analytics/export/{id}/download — streams the CSV to a permitted user. */
    public static function download_export($request) {
        $id = (string) $request->get_param('id');
        foreach ((array) get_option(Fastpix_Analytics::OPT_EXPORTS, array()) as $e) {
            if ($e['id'] === $id && $e['state'] === 'ready' && $e['file'] !== '' && file_exists($e['file'])) {
                nocache_headers();
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="fastpix-analytics-' . $id . '.csv"');
                header('Content-Length: ' . filesize($e['file']));
                readfile($e['file']);   // phpcs:ignore WordPress.WP.AlternativeFunctions -- capability-checked streaming download
                exit;
            }
        }

        return new \WP_Error('fastpix_export_missing', __('No such export.', 'fastpix'), array('status' => 404));
    }
}
