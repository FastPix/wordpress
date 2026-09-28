<?php
/**
 * Analytics rollup, screens' data source, export — WF-010, FR-060,
 * REQ-060…063, RULE-029, API-P04/P05, API-F08.
 *
 * Jobs pull the FastPix data API into fastpix_analytics_daily; the screens
 * read ONLY that table and always state the stored age. Days inside the 7-day
 * correction window are rewritten on every pull; older days are immutable —
 * a change to one is logged, never applied silently (RULE-029).
 *
 * Data-API shapes: metric ids views, unique_viewers, playing_time,
 * video_startup_time, buffer_count, buffer_fill, playback_failure_percentage;
 * `timespan[]` twice with epoch seconds bounds a day; `filterby[]=video_id:{mediaId}`
 * scopes to one video (the player's metadata-video-id is the media id);
 * `groupBy=device_type|video_id|error_code` on /breakdown; `measurement=median|95th`
 * on /overall. Watch time arrives in milliseconds.
 *
 * Three dimensions are stored per video per day — 'all' (one row, value ''),
 * 'device', 'error_code' — and a site-wide rollup lives under video_id = 0.
 * One dimension per row is why the screens filter by a single dimension.
 *
 * The pull jobs live in Fastpix_Analytics_Pull and the CSV export in
 * Fastpix_Analytics_Export (class-size split); options, hooks, and the
 * public entry points stay here.
 */

namespace Fastpix;

if (!defined('WPINC')) {
    die;
}

require_once __DIR__ . '/class-fastpix-analytics-pull.php';
require_once __DIR__ . '/class-fastpix-analytics-export.php';

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery
// All SQL here runs against the plugin's own tables: identifiers come from the
// Fastpix_Schema::table() registry (a fixed whitelist, never request input) and
// every value travels through $wpdb->prepare(). Core APIs cannot query these tables.

class Fastpix_Analytics {

    const CORRECTION_DAYS  = 7;      // RULE-029
    const RETENTION_MONTHS = 25;     // DATA-011

    /** Nightly backfill walks this many days further into the past per run. */
    const BACKFILL_CHUNK_DAYS = 30;

    const OPT_BACKFILL     = 'fastpix_analytics_backfill';       // oldest day pulled (Y-m-d), or 'done'
    const OPT_LAST_SUCCESS = 'fastpix_analytics_last_success';   // epoch of the last completed pull

    const OPT_EXPORTS = 'fastpix_analytics_exports';   // last few export jobs, newest first
    const EXPORTS_KEPT = 5;

    const OPT_HOURLY = 'fastpix_analytics_hourly';     // site-wide last-48 h hourly cache
    const DEVICES    = array('Mobile', 'Desktop', 'Tablet');   // the device filter's values (platform device_type)
    const OPT_PULL_CURSOR = 'fastpix_analytics_pull_cursor';   // [day => offset] resume point for the per-video walk

    const SQL_COUNT = 'SELECT COUNT(*) FROM ';

    public static function boot() {
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
        add_action('fastpix_analytics_video', array(__CLASS__, 'video_job'));
        add_action('fastpix_analytics_sweep', array(Fastpix_Analytics_Pull::class, 'sweep_job'));
        add_action('fastpix_analytics_export', array(__CLASS__, 'export_job'), 10, 1);
    }

    /* ======================================================== entry points */
    /* Delegating stubs: the hooks and tests reach the jobs here, unchanged.  */

    /** Hourly [WF-010]: videos viewed in the last 48 hours, plus the site rows. */
    public static function video_job() {
        Fastpix_Analytics_Pull::video_job();
    }

    /** The export job (WF-010) — full precision, day by day. */
    public static function export_job($args = array()) {
        Fastpix_Analytics_Export::export_job($args);
    }

    /** Upsert one rollup row under the RULE-029 correction window. */
    public static function store($video_id, $day, $dimension, $value, $fields) {
        Fastpix_Analytics_Pull::store($video_id, $day, $dimension, $value, $fields);
    }

    /* =============================================================== reads */

    public static function register_routes() {
        $range_args = array(
            'from'   => Fastpix_Rest::arg('string', array('pattern' => '^\d{4}-\d{2}-\d{2}$')),
            'to'     => Fastpix_Rest::arg('string', array('pattern' => '^\d{4}-\d{2}-\d{2}$')),
            'device' => Fastpix_Rest::arg('string'),
            'hours'  => Fastpix_Rest::arg('integer', array('enum' => array(1, 6, 24))),   // sub-day ranges, from the hourly cache
        );

        Fastpix_Rest::register('/analytics/site', array(
            'methods'    => 'GET',
            'capability' => Fastpix_Capabilities::VIEW_ANALYTICS,
            'callback'   => array(__CLASS__, 'site'),
            'args'       => $range_args,
        ));

        Fastpix_Rest::register('/videos/(?P<id>\d+)/analytics', array(
            'methods'    => 'GET',
            'capability' => Fastpix_Capabilities::VIEW_ANALYTICS,
            'callback'   => array(__CLASS__, 'video'),
            'args'       => $range_args,
        ));

        Fastpix_Rest::register('/analytics/export', array(
            array(
                'methods'    => 'POST',
                'capability' => Fastpix_Capabilities::VIEW_ANALYTICS,
                'callback'   => array(Fastpix_Analytics_Export::class, 'start_export'),
                'args'       => array(
                    'range'       => Fastpix_Rest::arg('string', array('enum' => array('30', '90', 'all'), 'default' => '30')),
                    'every_video' => Fastpix_Rest::arg('boolean', array('default' => true)),
                    'per_day'     => Fastpix_Rest::arg('boolean', array('default' => true)),
                    'device'      => Fastpix_Rest::arg('boolean', array('default' => true)),
                    'video'       => Fastpix_Rest::arg('integer'),
                ),
            ),
            array(
                'methods'    => 'GET',
                'capability' => Fastpix_Capabilities::VIEW_ANALYTICS,
                'callback'   => array(Fastpix_Analytics_Export::class, 'list_exports'),
            ),
        ));

        Fastpix_Rest::register('/analytics/export/(?P<id>[a-f0-9]{16})/download', array(
            'methods'    => 'GET',
            'capability' => Fastpix_Capabilities::VIEW_ANALYTICS,
            'callback'   => array(Fastpix_Analytics_Export::class, 'download_export'),
        ));
    }

    /** Authors without the any-video capability see only their own videos (UI-005 restricted). */
    public static function scope_video_ids() {
        if (current_user_can(Fastpix_Capabilities::EDIT_VIDEO)) {
            return null;
        }

        global $wpdb;

        return array_map('intval', $wpdb->get_col($wpdb->prepare(
            'SELECT id FROM ' . Fastpix_Schema::table('videos') . " WHERE author_id = %d AND (workspace_id = %s OR workspace_id = '')",
            get_current_user_id(), Fastpix_Videos_Rest::connected_workspace()
        )));
    }

    private static function range($request) {
        $to   = (string) $request->get_param('to');
        $from = (string) $request->get_param('from');
        $to   = $to !== '' ? $to : gmdate('Y-m-d');
        $from = $from !== '' ? $from : gmdate('Y-m-d', time() - 29 * DAY_IN_SECONDS);

        return array($from, $to);
    }

    /** @param array|null $video_ids null = the site rows (video_id 0); a list = those videos' rows. */
    private static function scope_sql($video_ids) {
        if ($video_ids === null) {
            return ' AND video_id = 0';
        }
        if (empty($video_ids)) {
            return ' AND 1=0';
        }

        return ' AND video_id IN (' . implode(',', array_map('intval', $video_ids)) . ')';
    }

    /**
     * The common read: KPI totals, per-day series, error table — from the local
     * rollup only, with its stored age (REQ-063).
     *
     * @param array|null $video_ids null = the site rows (video_id 0);
     *                              a list = sum over those videos' rows.
     */
    private static function read_rollup($from, $to, $device, $video_ids) {
        global $wpdb;

        $table = Fastpix_Schema::table('analytics_daily');

        if ($device !== '') {
            $where  = "dimension = 'device' AND dimension_value = %s";
            $params = array($device, $from, $to);
        } else {
            $where  = "dimension = 'all'";
            $params = array($from, $to);
        }
        $where .= ' AND day BETWEEN %s AND %s' . self::scope_sql($video_ids);

        $days = $wpdb->get_results($wpdb->prepare(
            "SELECT day, SUM(views) views, SUM(unique_viewers) people, SUM(watch_seconds) watch_seconds,
                    MAX(startup_ms_p50) p50, MAX(startup_ms_p95) p95, SUM(error_count) errors, MAX(fetched_at) fetched_at
             FROM {$table} WHERE {$where} GROUP BY day ORDER BY day",
            $params
        ), ARRAY_A);

        // Error table rows come from the error_code dimension regardless of the
        // device filter — one dimension per row, the device split of an error is
        // a query this table cannot answer.
        $err_where  = "dimension = 'error_code' AND day BETWEEN %s AND %s" . self::scope_sql($video_ids);
        $err_params = array($from, $to);
        $errors = $wpdb->get_results($wpdb->prepare(
            "SELECT dimension_value code, SUM(views) views, MAX(day) last_seen
             FROM {$table} WHERE {$err_where} GROUP BY dimension_value ORDER BY views DESC LIMIT 20",
            $err_params
        ), ARRAY_A);

        // QoE lives on the 'all' rows only (one dimension per row). Under a
        // device filter it is unknowable and omitted.
        $qoe = $device === '' ? self::rollup_qoe($table, $where, $params) : null;

        $totals = array('views' => 0, 'people' => 0, 'watch_seconds' => 0, 'errors' => 0);
        $age    = null;
        foreach ($days as $d) {
            $totals['views']         += (int) $d['views'];
            $totals['people']        += (int) $d['people'];
            $totals['watch_seconds'] += (int) $d['watch_seconds'];
            $totals['errors']        += (int) $d['errors'];
            $age = max($age, strtotime($d['fetched_at'] . ' UTC'));
        }

        return array(
            'totals'           => $totals,
            'days'             => $days,
            'errors'           => $errors,
            'qoe'              => $qoe,
            'fetched_at'       => $age,
            'provisional_from' => gmdate('Y-m-d', time() - (self::CORRECTION_DAYS - 1) * DAY_IN_SECONDS),
            'stale'            => !Fastpix_Api_Client::is_healthy(),
            'last_contact'     => (int) get_option(self::OPT_LAST_SUCCESS, 0),
            // People cannot be split by device — uniqueness is only known per
            // video per day (one dimension per row).
            'people_known'     => $device === '',
        );
    }

    /** A views-weighted average of the QoE columns over the range, NULL days excluded. */
    private static function rollup_qoe($table, $where, $params) {
        global $wpdb;

        $exprs = array();
        foreach (array('qoe_score', 'score_playback', 'score_startup', 'score_stability', 'score_render',
                       'playback_failure_pct', 'startup_failure_pct', 'buffer_ratio', 'avg_bitrate', 'startup_ms_p50') as $col) {
            $exprs[] = "SUM(IF({$col} IS NULL, 0, views * {$col})) / NULLIF(SUM(IF({$col} IS NULL, 0, views)), 0) AS {$col}";
        }
        $qoe_row = $wpdb->get_row($wpdb->prepare(
            'SELECT ' . implode(', ', $exprs) . " FROM {$table} WHERE {$where}",
            $params
        ), ARRAY_A);
        if ($qoe_row && $qoe_row['qoe_score'] !== null) {
            return array_map(function ($v) { return $v === null ? null : (float) $v; }, $qoe_row);
        }

        return null;
    }

    /** GET /analytics/site [API-P04]. */
    public static function site($request) {
        global $wpdb;

        // First view before the hourly job has ever run: kick one pull now
        // (async — the screen still answers from the empty rollup) so figures
        // appear in a minute, not an hour. Guarded so repeat loads don't queue
        // a pile of jobs.
        if (Fastpix_Analytics_Pull::ready() && Fastpix_Jobs::available() && !get_transient('fastpix_analytics_kick')
            && (int) $wpdb->get_var(self::SQL_COUNT . Fastpix_Schema::table('analytics_daily') . ' LIMIT 1') === 0) {
            set_transient('fastpix_analytics_kick', 1, 10 * MINUTE_IN_SECONDS);
            as_enqueue_async_action('fastpix_analytics_video', array(), Fastpix_Jobs::GROUP_ANALYTICS);
        }

        list($from, $to) = self::range($request);
        $scope = self::scope_video_ids();
        $hours = (int) $request->get_param('hours');

        $pending = (string) get_option(Fastpix_Connection::OPT_PENDING_LEAVE, '') !== '';
        if ($pending) {
            // The pair changed and its workspace is not learned yet: the stored rollup
            // may belong to the previous workspace, so show nothing from it. [ASSUME-092]
            $out = self::read_rollup($from, $to, (string) $request->get_param('device'), array(-1));
            $out['pending_workspace'] = true;
        } elseif ($hours > 0 && $scope === null) {
            $out = self::site_hourly($hours, (string) $request->get_param('device'));
        } else {
            $out = self::read_rollup($from, $to, (string) $request->get_param('device'), $scope);
        }

        $out['restricted'] = $scope !== null;
        if ($scope !== null) {
            $out['own_videos'] = count($scope);
        }

        // The no-data helper: ready videos not yet on any post (UI-005 no-data).
        $ws = Fastpix_Videos_Rest::connected_workspace();   // the connected workspace's videos only [ASSUME-092]
        $out['ready_videos'] = (int) $wpdb->get_var($wpdb->prepare(self::SQL_COUNT . Fastpix_Schema::table('videos') . " WHERE status = 'Ready' AND deleted_at IS NULL AND (workspace_id = %s OR workspace_id = '')", $ws));
        $out['unused_videos'] = (int) $wpdb->get_var($wpdb->prepare(
            self::SQL_COUNT . Fastpix_Schema::table('videos') . ' v WHERE v.status = \'Ready\' AND v.deleted_at IS NULL AND (v.workspace_id = %s OR v.workspace_id = \'\')
             AND NOT EXISTS (SELECT 1 FROM ' . Fastpix_Schema::table('usage') . ' u WHERE u.video_id = v.id)', $ws
        ));

        return rest_ensure_response($out);
    }

    /**
     * Sub-day range: KPIs and series from the hourly cache; the errors
     * table stays at day grain (its finest local grain).
     */
    private static function site_hourly($hours, $device = '') { // NOSONAR php:S100 — WordPress snake_case naming
        $out = self::read_rollup(gmdate('Y-m-d', time() - DAY_IN_SECONDS), gmdate('Y-m-d'), $device, null);
        // The errors table (and its %) stays on the yesterday+today day-grain
        // window — the hourly cache carries no error split. Hand the screen
        // that window's own view total so an error % is never computed against
        // the 1/6/24 h view count (600 % errors).
        $out['errors_views']  = (int) $out['totals']['views'];
        $out['errors_window'] = __('yesterday and today (day grain)', 'fastpix');
        $cache = (array) get_option(self::OPT_HOURLY, array());
        $since = time() - $hours * HOUR_IN_SECONDS;
        $series = array();
        $totals = array('views' => 0, 'people' => 0, 'watch_seconds' => 0, 'errors' => $out['totals']['errors']);
        // A device reads its own hourly series; a cache from before per-device pulls has none — empty until the next hourly pull.
        $hourly = $device === '' ? ($cache['hours'] ?? array()) : ($cache['devices'][$device] ?? array());
        foreach ((array) $hourly as $at => $h) {
            $ts = strtotime($at);
            if ($ts === false || $ts < $since) {
                continue;
            }
            $watch = (int) round($h['watch_ms'] / 1000);
            $series[] = array('day' => $at, 'views' => (int) $h['views'], 'people' => (int) $h['people'], 'watch_seconds' => $watch);
            $totals['views']         += (int) $h['views'];
            $totals['people']        += (int) $h['people'];
            $totals['watch_seconds'] += $watch;
        }
        usort($series, function ($a, $b) { return strcmp($a['day'], $b['day']); });
        $out['totals']      = $totals;
        $out['days']        = $series;
        $out['granularity'] = 'hour';
        $out['fetched_at']  = isset($cache['fetched_at']) ? (int) $cache['fetched_at'] : null;

        return $out;
    }

    /** GET /videos/{id}/analytics [API-P04] — KPIs, coverage deciles, errors. */
    public static function video($request) {
        global $wpdb;

        $id    = (int) $request->get_param('id');
        $scope = self::scope_video_ids();
        if ($scope !== null && !in_array($id, $scope, true)) {
            return new \WP_Error('fastpix_video_missing', __('No such video.', 'fastpix'), array('status' => 404));
        }

        $video = $wpdb->get_row($wpdb->prepare(
            'SELECT id, media_id, title, duration_seconds, status, access_policy, created_at, workspace_id FROM ' . Fastpix_Schema::table('videos') . ' WHERE id = %d',
            $id
        ), ARRAY_A);
        if (!$video || Fastpix_Videos_Rest::is_other_workspace($video)) {   // a previous workspace's figures are not this workspace's [ASSUME-092]
            return new \WP_Error('fastpix_video_missing', __('No such video.', 'fastpix'), array('status' => 404));
        }

        list($from, $to) = self::range($request);
        $out = self::read_rollup($from, $to, (string) $request->get_param('device'), array($id));

        $out['video'] = array(
            'id'          => (int) $video['id'],
            'title'       => (string) $video['title'],
            'duration'    => (float) $video['duration_seconds'],
            'access'      => (string) $video['access_policy'],
            'created_at'  => (string) $video['created_at'],
            'on_posts'    => (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(DISTINCT post_id) FROM ' . Fastpix_Schema::table('usage') . ' WHERE video_id = %d', $id)),
            'playback_id' => (string) $wpdb->get_var($wpdb->prepare('SELECT playback_id FROM ' . Fastpix_Schema::table('playback_ids') . ' WHERE video_id = %d LIMIT 1', $id)),
        );

        // Coverage: computed here from watch_progress — FastPix never sees it
        // (RULE-030). Decile d holds viewers whose furthest point reached its
        // start; drop-off is the difference between neighbours.
        $duration = max(1.0, (float) $video['duration_seconds']);
        $viewers  = (int) $wpdb->get_var($wpdb->prepare(self::SQL_COUNT . Fastpix_Schema::table('watch_progress') . ' WHERE video_id = %d', $id));
        $finished = (int) $wpdb->get_var($wpdb->prepare(self::SQL_COUNT . Fastpix_Schema::table('watch_progress') . ' WHERE video_id = %d AND completed_at IS NOT NULL', $id));

        $deciles = array();
        for ($i = 0; $i < 10; $i++) {
            $start = $duration * $i / 10;
            $deciles[] = array(
                'start'   => $start,
                'end'     => $duration * ($i + 1) / 10,
                'viewers' => (int) $wpdb->get_var($wpdb->prepare(
                    self::SQL_COUNT . Fastpix_Schema::table('watch_progress') . ' WHERE video_id = %d AND furthest_seconds >= %f',
                    $id, $start
                )),
            );
        }

        $out['coverage'] = array(
            'viewers'   => $viewers,
            'finished'  => $finished,
            'deciles'   => $deciles,
            'threshold' => Fastpix_Progress::threshold(),
        );

        return rest_ensure_response($out);
    }
}
