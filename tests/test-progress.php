<?php
/**
 * Self-check for watch progress — WF-013, FR-061, REQ-064/065, RULE-032,
 * SEC-014/016, INT-008, TEST-009 (progress half).
 *
 *   docker compose exec wordpress php -d zend.assertions=1 \
 *     /var/www/html/wp-content/plugins/fastpix/tests/test-progress.php
 */

require_once __DIR__ . '/bootstrap.php';

use Fastpix\Fastpix_Cache as Cache;
use Fastpix\Fastpix_Progress;
use Fastpix\Fastpix_Schema as Schema;

const SQL_DELETE_FROM = 'DELETE FROM ';
const REST_PROGRESS = '/progress';
const SQL_WHERE_VIDEO_VIEWER = ' WHERE video_id = %d AND viewer_key = %s';
const SQL_SELECT_ALL_FROM = 'SELECT * FROM ';

global $wpdb;

$saved = array(Fastpix_Progress::OPT_THRESHOLD => get_option(Fastpix_Progress::OPT_THRESHOLD, null));
register_shutdown_function(function () use (&$saved) {
    foreach ($saved as $opt => $value) { $value === null ? delete_option($opt) : update_option($opt, $value, false); }
});

/* ---------------------------------------------------------------- fixtures */

$now = current_time('mysql', true);
$wpdb->query(SQL_DELETE_FROM . Schema::table('videos') . " WHERE media_id LIKE 'prg-%'");
$wpdb->query(SQL_DELETE_FROM . Schema::table('watch_progress') . " WHERE viewer_key LIKE 'chk%' OR viewer_key LIKE 'user:%'");

$wpdb->insert(Schema::table('videos'), array(
    'media_id' => 'prg-1', 'workspace_id' => 'ws-prg', 'title' => 'Progress check', 'status' => 'Ready',
    'access_policy' => 'public', 'duration_seconds' => 100, 'author_id' => 1,
    'created_at' => $now, 'updated_at' => $now,
));
$video_id = (int) $wpdb->insert_id;

$post_id = wp_insert_post(array('post_title' => 'Progress lesson', 'post_status' => 'publish', 'post_type' => 'post', 'post_content' => 'x'));
$wpdb->query($wpdb->prepare(SQL_DELETE_FROM . Schema::table('usage') . ' WHERE video_id = %d', $video_id));
$wpdb->insert(Schema::table('usage'), array('video_id' => $video_id, 'post_id' => $post_id, 'context' => 'render', 'occurrences' => 1, 'last_seen_at' => $now, 'created_at' => $now, 'updated_at' => $now));

Cache::flush_group('ratelimit');
wp_set_current_user(0);

function preq($method, $path, $body = null, $query = array()) {
    $request = new WP_REST_Request($method, '/fastpix/v1' . $path);
    if ($query) { $request->set_query_params($query); }
    if ($body !== null) {
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(wp_json_encode($body));
    }

    return rest_get_server()->dispatch($request);
}

// Between legitimate beats the 15 s window has "passed" — relax the cadence
// bucket (only) so the test does not have to wait; the cadence assertion
// switches it back on.
$GLOBALS['fp_relax_cadence'] = true;
add_filter('fastpix_rate_limit', function ($resolved, $bucket) {
    return ($bucket === 'progress_viewer' && !empty($GLOBALS['fp_relax_cadence'])) ? array(1000, 15) : $resolved;
}, 5, 2);

function beat($video, $viewer, $position, $covered) {
    return preq('POST', REST_PROGRESS, array('video' => $video, 'viewer_key' => $viewer, 'position' => $position, 'covered' => $covered));
}

/* ------------------------------------------------------------- guard set */

$r = preq('POST', REST_PROGRESS, array('video' => $video_id, 'viewer_key' => 'chk-viewer-0001', 'position' => 10, 'covered' => str_repeat('9', 1200)));
assert($r->get_status() === 413 || $r->get_status() === 400, 'an oversized body is refused [RULE-032]');

$r = preq('POST', REST_PROGRESS, array('video' => $video_id, 'position' => 10, 'covered' => 5));
assert($r->get_status() === 400, 'no viewer reference, no write');

$r = beat(999999999, 'chk-viewer-0001', 10, 5);
assert($r->get_status() === 404, 'an unknown video is a 404');

$r = beat($video_id, 'chk-viewer-0001', 500, 5);
assert($r->get_status() === 400, 'a position outside the duration is refused [RULE-032]');

$GLOBALS['fp_relax_cadence'] = false;
$r = beat($video_id, 'chk-viewer-cad01', 10, 8);
assert($r->get_status() === 200, 'a valid beat lands');

$r = preq('POST', REST_PROGRESS, array('video' => $video_id, 'viewer_key' => 'chk-viewer-cad01', 'position' => 12, 'covered' => 9));
assert($r->get_status() === 429, 'a second write inside 15 s is dropped with 429, not queued [RULE-032]');
$r = preq('POST', REST_PROGRESS, array('video' => $video_id, 'viewer_key' => 'chk-viewer-cad01', 'position' => 14, 'covered' => 9, 'final' => true));
assert($r->get_status() === 200, 'the final beat of a session (tab hidden / page left) is exempt from the cadence — it is the resume point [QA F7]');
$GLOBALS['fp_relax_cadence'] = true;

// A video on no published post refuses anonymous progress.
$wpdb->insert(Schema::table('videos'), array(
    'media_id' => 'prg-2', 'workspace_id' => 'ws-prg', 'title' => 'Unpublished', 'status' => 'Ready',
    'access_policy' => 'public', 'duration_seconds' => 50, 'author_id' => 1,
    'created_at' => $now, 'updated_at' => $now,
));
$unpublished = (int) $wpdb->insert_id;
$r = beat($unpublished, 'chk-viewer-0001', 5, 2);
assert($r->get_status() === 403, 'a video published nowhere on this site records nothing');

// New viewer keys are capped per address per day.
$cap_filter = function ($resolved, $bucket) {
    return $bucket === 'progress_viewer_keys' ? array(2, 86400) : $resolved;
};
add_filter('fastpix_rate_limit', $cap_filter, 10, 2);
Cache::flush_group('ratelimit');
assert(beat($video_id, 'chk-cap-000000a', 1, 1)->get_status() === 200, 'first new key of the day');
assert(beat($video_id, 'chk-cap-000000b', 1, 1)->get_status() === 200, 'second new key of the day');
assert(beat($video_id, 'chk-cap-000000c', 1, 1)->get_status() === 429, 'the third new key is over the per-address cap [RULE-032]');
assert(beat($video_id, 'chk-cap-000000a', 2, 2)->get_status() === 200, 'a known key spends nothing from the cap');
remove_filter('fastpix_rate_limit', $cap_filter, 10);

/* ------------------------------------------------- coverage semantics */

Cache::flush_group('ratelimit');
beat($video_id, 'chk-viewer-cover', 60, 50);
$row = $wpdb->get_row($wpdb->prepare(SQL_SELECT_ALL_FROM . Schema::table('watch_progress') . SQL_WHERE_VIDEO_VIEWER, $video_id, 'chk-viewer-cover'), ARRAY_A);
assert((int) $row['furthest_seconds'] === 60 && (int) $row['covered_seconds'] === 50, 'a beat lands as furthest + covered');
assert(abs((float) $row['coverage_ratio'] - 0.5) < 0.001, 'coverage_ratio = covered / duration [REQ-064]');

beat($video_id, 'chk-viewer-cover', 20, 30);
$row = $wpdb->get_row($wpdb->prepare(SQL_SELECT_ALL_FROM . Schema::table('watch_progress') . SQL_WHERE_VIDEO_VIEWER, $video_id, 'chk-viewer-cover'), ARRAY_A);
assert((int) $row['furthest_seconds'] === 60, 'a backward seek cannot reduce the furthest point [REQ-064]');
assert((int) $row['covered_seconds'] === 50, 'a rewatch cannot reduce covered seconds');

beat($video_id, 'chk-viewer-cover', 99, 5000);
$row = $wpdb->get_row($wpdb->prepare(SQL_SELECT_ALL_FROM . Schema::table('watch_progress') . SQL_WHERE_VIDEO_VIEWER, $video_id, 'chk-viewer-cover'), ARRAY_A);
assert((int) $row['covered_seconds'] === 100, 'covered seconds are capped at the duration — rewatch cannot inflate [REQ-064]');

/* ------------------------------------------------------- resume read */

$r = preq('GET', '/progress/' . $video_id, null, array('viewer_key' => 'chk-viewer-cover'));
assert((int) $r->get_data()['furthest_seconds'] === 60 || (int) $r->get_data()['furthest_seconds'] === 99, 'the viewer resumes where they stopped [REQ-064]');
$r = preq('GET', '/progress/' . $video_id, null, array('viewer_key' => 'chk-viewer-none1'));
assert((int) $r->get_data()['furthest_seconds'] === 0, 'an unknown viewer starts at the beginning');

/* --------------------------------------------- completion fires once */

$fired = array();
add_action('fastpix_watch_completed', function ($_vid, $_uid, $viewer) use (&$fired) { $fired[] = $viewer; }, 10, 3);   // renamed — fastpix_video_completed is the lesson hook now [ASSUME-046]

beat($video_id, 'chk-viewer-done1', 95, 90);   // 0.9 of 100
$row = $wpdb->get_row($wpdb->prepare(SQL_SELECT_ALL_FROM . Schema::table('watch_progress') . SQL_WHERE_VIDEO_VIEWER, $video_id, 'chk-viewer-done1'), ARRAY_A);
assert($row['completed_at'] !== null, 'completion fires at the threshold [REQ-065]');
assert(count($fired) === 1, 'the completion action fired');

$was = $row['completed_at'];
sleep(1);
beat($video_id, 'chk-viewer-done1', 99, 100);
$row = $wpdb->get_row($wpdb->prepare(SQL_SELECT_ALL_FROM . Schema::table('watch_progress') . SQL_WHERE_VIDEO_VIEWER, $video_id, 'chk-viewer-done1'), ARRAY_A);
assert($row['completed_at'] === $was, 'completed_at is immutable — a rewatch cannot re-fire [REQ-065]');
assert(count($fired) === 1, 'completion fires once, ever');

/* ---------------------------------------------- logged-in viewers */

// (The LMS hand-off was dropped by owner ruling 2026-08-20 — completion is a
// viewer fact, exposed only through the fastpix_watch_completed action.)
$admin = get_users(array('role' => 'administrator', 'number' => 1, 'fields' => 'ID'))[0];
wp_set_current_user($admin);
Cache::flush_group('ratelimit');
$r = preq('POST', REST_PROGRESS, array('video' => $video_id, 'position' => 95, 'covered' => 95));
assert($r->get_status() === 200, 'a logged-in beat lands without a viewer key');
$row = $wpdb->get_row($wpdb->prepare(SQL_SELECT_ALL_FROM . Schema::table('watch_progress') . SQL_WHERE_VIDEO_VIEWER, $video_id, 'user:' . $admin), ARRAY_A);
assert($row && $row['completed_at'] !== null, 'logged-in viewers are keyed by user id [SEC-014]');
assert(count($fired) === 2, 'the completion action fired for the logged-in viewer too');

/* --------------------------------------------------- privacy (SEC-016) */

$email = get_userdata($admin)->user_email;
// The eraser rightly removes EVERY row for the account — snapshot the admin's
// real (non-fixture) watch rows so live data survives the check.
$real_watch = $wpdb->get_results($wpdb->prepare(
    SQL_SELECT_ALL_FROM . Schema::table('watch_progress') . ' WHERE viewer_key = %s AND video_id != %d', 'user:' . $admin, $video_id
), ARRAY_A);
$export = Fastpix_Progress::export_personal_data($email);
assert(count($export['data']) >= 1, 'user-keyed rows appear in the personal data export');

$erase = Fastpix_Progress::erase_personal_data($email);
assert($erase['items_removed'] === true, 'the eraser removes them');
$left = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Schema::table('watch_progress') . ' WHERE viewer_key = %s', 'user:' . $admin));
assert($left === 0, 'nothing keyed to the account remains');
foreach ($real_watch as $real_row) {
    unset($real_row['id']);
    $wpdb->insert(Schema::table('watch_progress'), $real_row);
}

$exporters = apply_filters('wp_privacy_personal_data_exporters', array());
assert(isset($exporters['fastpix-watch-progress']), 'the exporter is registered');
$erasers = apply_filters('wp_privacy_personal_data_erasers', array());
assert(isset($erasers['fastpix-watch-progress']), 'the eraser is registered');

/* ------------------------------------------------------------- teardown */

wp_delete_post($post_id, true);
$wpdb->query(SQL_DELETE_FROM . Schema::table('videos') . " WHERE media_id LIKE 'prg-%'");
$wpdb->query(SQL_DELETE_FROM . Schema::table('watch_progress') . " WHERE viewer_key LIKE 'chk%'");
$wpdb->query($wpdb->prepare(SQL_DELETE_FROM . Schema::table('usage') . ' WHERE video_id = %d', $video_id));
Cache::flush_group('ratelimit');

echo "test-progress: OK\n";
