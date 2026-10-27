<?php
/**
 * Self-check for the analytics rollup, reads and export — WF-010, FR-060,
 * REQ-060…063, RULE-029/030, API-P04/P05, TEST-009 (rollup half).
 *
 *   docker compose exec wordpress php -d zend.assertions=1 \
 *     /var/www/html/wp-content/plugins/fastpix/tests/test-analytics.php
 */

require_once __DIR__ . '/bootstrap.php';

use Fastpix\Fastpix_Analytics;
use Fastpix\Fastpix_Credentials as Creds;
use Fastpix\Fastpix_Schema as Schema;

const SQL_DELETE_FROM = 'DELETE FROM ';
const SQL_SELECT_ALL_FROM = 'SELECT * FROM ';
const SQL_WHERE_VIDEO_DAY_ALL = " WHERE video_id = %d AND day = %s AND dimension = 'all'";
const SQL_WHERE_VIDEO_IN_SITE = ' WHERE video_id IN (0, %d)';
const EP_ANALYTICS_SITE = '/analytics/site';

global $wpdb;

$saved = array();
foreach (array(Creds::OPT_TOKEN_ID, Creds::OPT_SECRET, \Fastpix\Fastpix_Api_Client::OPT_HEALTH,
               Fastpix_Analytics::OPT_EXPORTS, Fastpix_Analytics::OPT_BACKFILL, Fastpix_Analytics::OPT_LAST_SUCCESS,
               Fastpix_Analytics::OPT_HOURLY, Fastpix_Analytics::OPT_PULL_CURSOR) as $opt) {
    $saved[$opt] = get_option($opt, null);
}
register_shutdown_function(function () use (&$saved) {
    foreach ($saved as $opt => $value) { $value === null ? delete_option($opt) : update_option($opt, $value, false); }
});
Creds::store('4f27c4c4-9a1e-4c2f-9d33-2b8f0a7c1e55', 'sk_selfcheck_analytics');
delete_option(\Fastpix\Fastpix_Api_Client::OPT_HEALTH);
add_filter('fastpix_api_backoff_seconds', '__return_zero');

/* ---------------------------------------------------------------- fixtures */

$now = current_time('mysql', true);
$wpdb->query(SQL_DELETE_FROM . Schema::table('videos') . " WHERE media_id LIKE 'anl-%'");
// The fixture rows belong to workspace 'ws-anl'; the per-video read is scoped to the connected one (ASSUME-092).
$saved_seen = get_option('fastpix_workspace_seen_id', null);
update_option('fastpix_workspace_seen_id', 'ws-anl', false);
register_shutdown_function(function () use ($saved_seen) { $saved_seen === null ? delete_option('fastpix_workspace_seen_id') : update_option('fastpix_workspace_seen_id', $saved_seen, false); });
// A pending pair change holds the pull job (ASSUME-092); this check runs with none pending.
$saved_pending = get_option(\Fastpix\Fastpix_Connection::OPT_PENDING_LEAVE, null);
delete_option(\Fastpix\Fastpix_Connection::OPT_PENDING_LEAVE);
register_shutdown_function(function () use ($saved_pending) { if ($saved_pending !== null) { update_option(\Fastpix\Fastpix_Connection::OPT_PENDING_LEAVE, $saved_pending, false); } });
$wpdb->insert(Schema::table('videos'), array(
    'media_id' => 'anl-media-1', 'workspace_id' => 'ws-anl', 'title' => 'Analytics check', 'status' => 'Ready',
    'access_policy' => 'public', 'duration_seconds' => 100, 'author_id' => 1,
    'created_at' => $now, 'updated_at' => $now,
));
$video_id = (int) $wpdb->insert_id;
// video_id-0 rows are the REAL site rollup — snapshot them, restored from a shutdown function
// registered BEFORE the delete so a failed assertion cannot lose them.
$site_snapshot = $wpdb->get_results(SQL_SELECT_ALL_FROM . Schema::table('analytics_daily') . ' WHERE video_id = 0', ARRAY_A);
register_shutdown_function(function () use ($site_snapshot, $video_id) {
    global $wpdb;
    $wpdb->query($wpdb->prepare(SQL_DELETE_FROM . Schema::table('analytics_daily') . SQL_WHERE_VIDEO_IN_SITE, $video_id));
    foreach ($site_snapshot as $snap_row) { $wpdb->insert(Schema::table('analytics_daily'), $snap_row); }
});
$wpdb->query($wpdb->prepare(SQL_DELETE_FROM . Schema::table('analytics_daily') . SQL_WHERE_VIDEO_IN_SITE, $video_id));
// A run aborted by a failed assertion leaks its rows (the video row is gone,
// its analytics rows are not) — sweep those orphans so they never pollute
// real figures.
$wpdb->query('DELETE a FROM ' . Schema::table('analytics_daily') . ' a LEFT JOIN ' . Schema::table('videos') . ' v ON v.id = a.video_id WHERE a.video_id > 0 AND v.id IS NULL');
$wpdb->query($wpdb->prepare(SQL_DELETE_FROM . Schema::table('watch_progress') . ' WHERE video_id = %d', $video_id));

/* The platform, mocked with the live-verified shapes (ASSUME-044). */

function analytics_mock_ok($data) {
    return array('headers' => array(), 'body' => wp_json_encode(array('success' => true, 'data' => $data)), 'response' => array('code' => 200, 'message' => ''));
}

function analytics_mock_breakdown($url) {
    if (strpos($url, 'groupBy=video_id') !== false) {
        return array(array('field' => 'anl-media-1', 'views' => 6, 'value' => 6, 'totalWatchTime' => 90000, 'totalPlayingTime' => 88000));
    }
    if (strpos($url, 'groupBy=device_type') !== false) {
        return array(
            array('field' => 'Desktop', 'views' => 4, 'value' => 4, 'totalWatchTime' => 60000, 'totalPlayingTime' => 59000),
            array('field' => 'Mobile', 'views' => 2, 'value' => 2, 'totalWatchTime' => 30000, 'totalPlayingTime' => 29000),
        );
    }
    // error_code
    return array(
        array('field' => 'null', 'views' => 5, 'value' => 5, 'totalWatchTime' => 90000),
        array('field' => 'keySystemError', 'views' => 1, 'value' => 1, 'totalWatchTime' => 0),
    );
}

function analytics_mock_timeseries($url) {
    $mv = 2;   // views
    if (strpos($url, 'unique_viewers') !== false) { $mv = 1; }
    if (strpos($url, 'playing_time') !== false) { $mv = 60000; }

    $points = array(
        array('intervalTime' => gmdate('Y-m-d\TH:00:00\Z'), 'metricValue' => $mv, 'numberOfViews' => 2),
        array('intervalTime' => gmdate('Y-m-d\TH:00:00\Z', time() - 3 * HOUR_IN_SECONDS), 'metricValue' => $mv, 'numberOfViews' => 2),
    );

    return strpos(urldecode($url), 'device_type:Mobile') !== false ? array($points[0]) : $points;   // Mobile: its own, smaller series
}

function analytics_mock_overall($url) {
    $value = 6;
    if (strpos($url, 'video_startup_time') !== false) { $value = strpos($url, 'measurement=95th') !== false ? 900 : 350; }
    if (strpos($url, 'buffer_count') !== false) { $value = 3; }
    if (strpos($url, 'buffer_fill') !== false) { $value = 4400; }
    if (strpos($url, '_score') !== false) { $value = 0.75; }
    if (strpos($url, '_percentage') !== false || strpos($url, 'buffer_ratio') !== false) { $value = 0.25; }
    if (strpos($url, 'average_bitrate') !== false) { $value = 2000000; }

    return array(
        'value' => $value, 'totalViews' => 6, 'uniqueViews' => 4, 'totalWatchTime' => 90000, 'totalPlayTime' => 88000, 'globalValue' => $value,
    );
}

$data_calls = array();
add_filter('pre_http_request', function ($_pre, $_args, $url) use (&$data_calls) {
    if (strpos($url, '/data/') === false) {
        return analytics_mock_ok(array());
    }
    $data_calls[] = $url;

    if (strpos($url, '/breakdown') !== false) {
        $payload = analytics_mock_breakdown($url);
    } elseif (strpos($url, '/timeseries') !== false) {
        $payload = analytics_mock_timeseries($url);
    } else {   // /overall
        $payload = analytics_mock_overall($url);
    }

    return analytics_mock_ok($payload);
}, 9, 3);

/* ------------------------------------------------------------ rollup job */

Fastpix_Analytics::video_job();

$today = gmdate('Y-m-d');
$all = $wpdb->get_row($wpdb->prepare(
    SQL_SELECT_ALL_FROM . Schema::table('analytics_daily') . SQL_WHERE_VIDEO_DAY_ALL,
    $video_id, $today
), ARRAY_A);
assert($all !== null, 'the hourly job stores a per-video daily row [WF-010]');
assert((int) $all['views'] === 6 && (int) $all['unique_viewers'] === 4, 'views and unique viewers land');
assert((int) $all['watch_seconds'] === 90, 'watch time arrives in ms and is stored in seconds');
assert((int) $all['startup_ms_p50'] === 350 && (int) $all['startup_ms_p95'] === 900, 'startup p50/p95 from measurement=median|95th');
assert((int) $all['rebuffer_count'] === 3 && (int) $all['rebuffer_seconds'] === 4, 'rebuffer count + seconds');
assert((int) $all['error_count'] === 1, 'error count excludes the no-error bucket');

assert(abs((float) $all['qoe_score'] - 0.75) < 0.001 && abs((float) $all['buffer_ratio'] - 0.25) < 0.001 && (int) $all['avg_bitrate'] === 2000000, 'the nine QoE columns land on the all row [MISS-010]');

$site = $wpdb->get_row($wpdb->prepare(
    SQL_SELECT_ALL_FROM . Schema::table('analytics_daily') . " WHERE video_id = 0 AND day = %s AND dimension = 'all'", $today
), ARRAY_A);
assert($site !== null && (int) $site['views'] === 6, 'a site-wide rollup lives under video_id 0');

$device = $wpdb->get_results($wpdb->prepare(
    SQL_SELECT_ALL_FROM . Schema::table('analytics_daily') . " WHERE video_id = %d AND day = %s AND dimension = 'device' ORDER BY dimension_value",
    $video_id, $today
), ARRAY_A);
assert(count($device) === 2 && $device[0]['dimension_value'] === 'Desktop', 'one dimension per row: device rows [CONFLICT-008]');

$err = $wpdb->get_row($wpdb->prepare(
    SQL_SELECT_ALL_FROM . Schema::table('analytics_daily') . " WHERE video_id = %d AND day = %s AND dimension = 'error_code' AND dimension_value = 'keySystemError'",
    $video_id, $today
), ARRAY_A);
assert($err !== null && (int) $err['views'] === 1, 'error codes are rows too');

/* -------------------------------------- correction window (RULE-029) */

$old_day = gmdate('Y-m-d', time() - 30 * DAY_IN_SECONDS);
$wpdb->insert(Schema::table('analytics_daily'), array(
    'video_id' => $video_id, 'day' => $old_day, 'dimension' => 'all', 'dimension_value' => '',
    'views' => 10, 'unique_viewers' => 5, 'watch_seconds' => 100, 'error_count' => 0,
    'fetched_at' => $now, 'created_at' => $now, 'updated_at' => $now,
));
$store = new ReflectionMethod(Fastpix_Analytics::class, 'store');
$store->setAccessible(true);
$log_before = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Schema::table('logs') . " WHERE message LIKE '%outside the correction window%'");
$store->invoke(null, $video_id, $old_day, 'all', '', array('views' => 99, 'unique_viewers' => 5, 'watch_seconds' => 100, 'error_count' => 0));
$kept = $wpdb->get_row($wpdb->prepare(
    SQL_SELECT_ALL_FROM . Schema::table('analytics_daily') . SQL_WHERE_VIDEO_DAY_ALL,
    $video_id, $old_day
), ARRAY_A);
assert((int) $kept['views'] === 10, 'a day outside the correction window is immutable [RULE-029]');
$log_after = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Schema::table('logs') . " WHERE message LIKE '%outside the correction window%'");
assert($log_after === $log_before + 1, 'the refused change is logged, never applied silently [RULE-029]');

$store->invoke(null, $video_id, gmdate('Y-m-d', time() - 40 * DAY_IN_SECONDS), 'all', '', array('views' => 7, 'unique_viewers' => 1, 'watch_seconds' => 10, 'error_count' => 0));
$filled = $wpdb->get_var($wpdb->prepare(
    'SELECT views FROM ' . Schema::table('analytics_daily') . SQL_WHERE_VIDEO_DAY_ALL,
    $video_id, gmdate('Y-m-d', time() - 40 * DAY_IN_SECONDS)
));
assert((int) $filled === 7, 'an absent old day may still be backfilled — writing is not a change');

/* ------------------------------------------------------- screen reads */

$admins = get_users(array('role' => 'administrator', 'number' => 1, 'fields' => 'ID'));
wp_set_current_user($admins[0]);

function anreq($method, $path, $body = null, $query = array()) {
    $request = new WP_REST_Request($method, '/fastpix/v1' . $path);
    if ($query) { $request->set_query_params($query); }
    if ($body !== null) {
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(wp_json_encode($body));
    }

    return rest_get_server()->dispatch($request);
}

$calls_before = count($data_calls);
$r = anreq('GET', EP_ANALYTICS_SITE);
assert(!$r->is_error(), 'the site read answers');
$d = $r->get_data();
assert($d['totals']['views'] === 12, 'site KPIs sum the video_id-0 rows (6 views on each of the two pulled days)');
assert($d['fetched_at'] > 0, 'every figure carries its stored age [REQ-063]');
assert(isset($d['provisional_from']), 'the provisional window is stated');
assert(count($data_calls) === $calls_before, 'screens read the local rollup only — never FastPix [REQ-063]');

$r = anreq('GET', EP_ANALYTICS_SITE, null, array('device' => 'Mobile'));
$d = $r->get_data();
assert($d['people_known'] === false, 'a device filter says People is unknown rather than inventing it');

// Sub-day ranges (dashboard-matching) come from the hourly cache the job built.
$r = anreq('GET', EP_ANALYTICS_SITE, null, array('hours' => 24));
$d = $r->get_data();
assert($d['granularity'] === 'hour', 'sub-day ranges answer at hour grain');
assert($d['totals']['views'] === 4 && $d['totals']['people'] === 2 && $d['totals']['watch_seconds'] === 120, 'hourly KPIs sum the cached points');
assert(count($d['days']) === 2, 'the hourly series carries the cached points');
// The errors table stays at day grain (yesterday + today = 12 site views): the
// screen takes the error % against THAT window, labelled, never the hourly total.
assert($d['errors_views'] === 12 && $d['errors_window'] !== '', 'a sub-day read hands the errors table its own window and view total [X2]');
// A device filter keeps the sub-day ranges: its own hourly series, pulled per device. (QA: time filter disabled per device)
$r = anreq('GET', EP_ANALYTICS_SITE, null, array('hours' => 24, 'device' => 'Mobile'));
$d = $r->get_data();
assert($d['granularity'] === 'hour' && $d['totals']['views'] === 2 && count($d['days']) === 1, 'a device answers sub-day ranges from its own hourly series');

// Coverage deciles from watch_progress (RULE-030).
foreach (array(array('cv-a', 95, true), array('cv-b', 55, false), array('cv-c', 15, false)) as $v) {
    $wpdb->insert(Schema::table('watch_progress'), array(
        'video_id' => $video_id, 'viewer_key' => 'chk-' . $v[0], 'furthest_seconds' => $v[1],
        'covered_seconds' => $v[1], 'coverage_ratio' => $v[1] / 100,
        'completed_at' => $v[2] ? $now : null, 'last_seen_at' => $now, 'created_at' => $now, 'updated_at' => $now,
    ));
}
$r = anreq('GET', '/videos/' . $video_id . '/analytics');
$d = $r->get_data();
assert($d['video']['id'] === $video_id, 'the one-video read carries the header card facts');
assert($d['coverage']['viewers'] === 3 && $d['coverage']['finished'] === 1, 'viewer and finished counts');
assert($d['coverage']['deciles'][0]['viewers'] === 3, 'everyone reached the start');
assert($d['coverage']['deciles'][5]['viewers'] === 2, 'decile 6 keeps those whose furthest point passed it');
assert($d['coverage']['deciles'][9]['viewers'] === 1, 'only the finisher reached the last decile');

/* ------------------------------------------------------------- export */

delete_option(Fastpix_Analytics::OPT_EXPORTS);
// A title any EDIT_VIDEO user can set must not become a spreadsheet formula. [X10]
$wpdb->update(Schema::table('videos'), array('title' => '=HYPERLINK("https://evil.example")'), array('id' => $video_id));
$r = anreq('POST', '/analytics/export', array('range' => '90', 'every_video' => true, 'per_day' => true, 'device' => true));
assert(!$r->is_error(), 'the export starts');
$export_id = $r->get_data()['id'];

Fastpix_Analytics::export_job(array('id' => $export_id));
$exports = get_option(Fastpix_Analytics::OPT_EXPORTS);
assert($exports[0]['state'] === 'ready', 'the background job finishes the file');
assert(file_exists($exports[0]['file']), 'the CSV exists');
$csv = file($exports[0]['file']);
assert(strpos($csv[0], 'video,media_id,day,dimension') === 0, 'CSV header');
$body_rows = array_filter(array_slice($csv, 1), function ($line) { return strpos($line, 'anl-media-1') !== false; });
assert(count($body_rows) >= 4, 'per-day rows at full precision for every dimension [WF-010]');
$first_row = reset($body_rows);
assert(strpos($first_row, '"\'=HYPERLINK') === 0, 'a formula-shaped title is neutralised with a leading apostrophe [X10]');

$r = anreq('GET', '/analytics/export');
assert($r->get_data()['exports'][0]['state'] === 'ready', 'the screen can poll the export');
assert(!isset($r->get_data()['exports'][0]['file']), 'the server path never leaves the server');

/* ------------------------------------------------------------- teardown */

@unlink($exports[0]['file']);
delete_option(Fastpix_Analytics::OPT_EXPORTS);
$wpdb->query($wpdb->prepare(SQL_DELETE_FROM . Schema::table('analytics_daily') . SQL_WHERE_VIDEO_IN_SITE, $video_id));
foreach ($site_snapshot as $snap_row) {
    $wpdb->insert(Schema::table('analytics_daily'), $snap_row);
}
$wpdb->query($wpdb->prepare(SQL_DELETE_FROM . Schema::table('watch_progress') . ' WHERE video_id = %d', $video_id));
$wpdb->query(SQL_DELETE_FROM . Schema::table('videos') . " WHERE media_id LIKE 'anl-%'");

echo "test-analytics: OK\n";
