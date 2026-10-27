<?php
/**
 * Self-check for lesson completion + opt-in per-learner progress — ASSUME-046,
 * RULE-031/032 (reused guard set), SEC-016.
 *
 *   docker compose exec wordpress php -d zend.assertions=1 \
 *     /var/www/html/wp-content/plugins/fastpix/tests/test-lms-progress.php
 */

require_once __DIR__ . '/bootstrap.php';

use Fastpix\Fastpix_Cache as Cache;
use Fastpix\Fastpix_Lms;
use Fastpix\Fastpix_Render;
use Fastpix\Fastpix_Schema as Schema;

const SQL_DELETE_FROM = 'DELETE FROM ';
const SQL_WHERE_MEDIA_LMSX = " WHERE media_id LIKE 'lmsx-%'";
const SQL_SELECT_ALL_FROM = 'SELECT * FROM ';
const SQL_WHERE_VIEWER_POST = ' WHERE viewer_key = %s AND post_id = %d';
const SQL_WHERE_VIEWER = ' WHERE viewer_key = %s';
const SQL_SELECT_COUNT_FROM = 'SELECT COUNT(*) FROM ';
const REST_ANALYTICS_COURSES = '/fastpix/v1/analytics/courses/';

global $wpdb;

$saved = array(
    Fastpix_Lms::OPT_RETENTION => get_option(Fastpix_Lms::OPT_RETENTION, null),
    Fastpix_Lms::OPT_ENABLED   => get_option(Fastpix_Lms::OPT_ENABLED, null),
);
// Course features are off by default (owner ruling) — the suite turns them on.
assert(Fastpix_Lms::is_lesson('sfwd-lessons') === false || (bool) get_option(Fastpix_Lms::OPT_ENABLED, false),
    'with the switch off, no post type counts as a lesson [ASSUME-046]');
update_option(Fastpix_Lms::OPT_ENABLED, true, false);
// boot() already ran (and returned early) while the switch was off, so the
// routes it would have registered are wired up here for this run.
add_action('rest_api_init', array('Fastpix\Fastpix_Lms', 'register_routes'));
register_shutdown_function(function () use (&$saved) {
    foreach ($saved as $opt => $value) { $value === null ? delete_option($opt) : update_option($opt, $value, false); }
});

/* ---------------------------------------------------------------- fixtures */

$now = current_time('mysql', true);
// Aborted runs leak fixture lessons and usage rows — sweep them on entry so
// they can never pollute the real Courses view.
foreach (get_posts(array('post_type' => array('sfwd-lessons', 'lesson'), 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids')) as $fp_pid) {
    if (in_array(get_the_title($fp_pid), array('Tracked lesson', 'Untracked lesson', 'Shortcode lesson', 'Stranger lesson', 'Crosspath lesson'), true)) {
        wp_delete_post($fp_pid, true);
    }
}
$wpdb->query('DELETE u FROM ' . Schema::table('usage') . " u LEFT JOIN {$wpdb->posts} p ON p.ID = u.post_id WHERE p.ID IS NULL");
$wpdb->query(SQL_DELETE_FROM . Schema::table('videos') . SQL_WHERE_MEDIA_LMSX);
$wpdb->query(SQL_DELETE_FROM . Schema::table('playback_ids') . " WHERE playback_id = 'pb-lmsx-1'");
if (Schema::table_exists('lesson_progress')) {
    $wpdb->query(SQL_DELETE_FROM . Schema::table('lesson_progress') . SQL_WHERE_MEDIA_LMSX);
}
assert(Schema::table_exists('lesson_progress'), 'schema v5 created fastpix_lesson_progress');

$wpdb->insert(Schema::table('videos'), array(
    'media_id' => 'lmsx-1', 'workspace_id' => 'ws-lms', 'title' => 'LMS check', 'status' => 'Ready',
    'access_policy' => 'public', 'duration_seconds' => 100, 'author_id' => 1,
    'created_at' => $now, 'updated_at' => $now,
));
$video_id = (int) $wpdb->insert_id;
$wpdb->insert(Schema::table('playback_ids'), array(
    'video_id' => $video_id, 'playback_id' => 'pb-lmsx-1', 'access_policy' => 'public',
    'created_at' => $now, 'updated_at' => $now,
));

// A lesson that opted in (trackViewer on, complete at 80%), a lesson on
// defaults (90%, no tracking), and a plain post embedding the same video.
$block = function ($attrs) { return '<!-- wp:fastpix/video ' . wp_json_encode($attrs) . ' /-->'; };
$lesson_track = wp_insert_post(array('post_title' => 'Tracked lesson', 'post_status' => 'publish', 'post_type' => 'sfwd-lessons',
    'post_content' => $block(array('videoId' => 'lmsx-1', 'completeAt' => 80, 'trackViewer' => true))));
$lesson_plain = wp_insert_post(array('post_title' => 'Untracked lesson', 'post_status' => 'publish', 'post_type' => 'sfwd-lessons',
    'post_content' => $block(array('videoId' => 'lmsx-1'))));
$normal_post  = wp_insert_post(array('post_title' => 'Just a post', 'post_status' => 'publish', 'post_type' => 'post',
    'post_content' => $block(array('videoId' => 'lmsx-1', 'completeAt' => 80, 'trackViewer' => true))));

foreach (array($lesson_track, $lesson_plain, $normal_post) as $pid) {
    $wpdb->insert(Schema::table('usage'), array('video_id' => $video_id, 'post_id' => $pid, 'context' => 'render',
        'occurrences' => 1, 'last_seen_at' => $now, 'created_at' => $now, 'updated_at' => $now));
}

Cache::flush_group('ratelimit');
Cache::flush_group('lms');
Cache::flush_group('embed');

// N filled slots as the client would send them (LSB-first per byte).
function _fp_course_of_fixture($lesson_id) { return \Fastpix\Fastpix_Lms::course_of($lesson_id); }

function lms_slots($n) {
    $bits = array_fill(0, 13, 0);
    for ($i = 0; $i < $n; $i++) { $bits[$i >> 3] |= 1 << ($i % 8); }
    return implode('', array_map(function ($b) { return sprintf('%02x', $b); }, $bits));
}

function lmsreq($body) {
    $request = new WP_REST_Request('POST', '/fastpix/v1/progress');
    $request->set_header('Content-Type', 'application/json');
    $request->set_body(wp_json_encode($body));
    return rest_get_server()->dispatch($request);
}

$GLOBALS['fp_relax'] = true;
add_filter('fastpix_rate_limit', function ($resolved, $bucket) {
    return ($bucket === 'progress_viewer' && !empty($GLOBALS['fp_relax'])) ? array(1000, 15) : $resolved;
}, 5, 2);

$fired = array();
add_action('fastpix_video_completed', function ($media, $post, $viewer_key) use (&$fired) {
    $fired[] = array($media, $post, $viewer_key);
}, 10, 3);

// Automatic LMS credit (ASSUME-046 revision): LearnDash is absent here, so a
// stub captures the call; auto-marking is toggled off around the Tutor-typed
// fixture so the REAL Tutor plugin is never handed a fake lesson.
if (!function_exists('learndash_process_mark_complete')) {
    function learndash_process_mark_complete($user_id, $post_id) { $GLOBALS['fp_ld_marked'][] = array((int) $user_id, (int) $post_id); }
}
$GLOBALS['fp_ld_marked'] = array();
$GLOBALS['fp_auto'] = true;
add_filter('fastpix_lms_auto_complete', function () { return !empty($GLOBALS['fp_auto']); });

/* -------------------------------------------- lesson detection + render */

assert(Fastpix_Lms::is_lesson('sfwd-lessons') && Fastpix_Lms::is_lesson('llms_lesson'), 'known LMS lesson types match');
assert(!Fastpix_Lms::is_lesson('post') && !Fastpix_Lms::is_lesson('page'), 'a plain post is never a lesson');

// The server prints lesson attributes only in lesson context — the PHP half
// of "the panel does not register on post type post". [ASSUME-046]
$GLOBALS['post'] = get_post($lesson_track); setup_postdata($GLOBALS['post']);
$html = Fastpix_Render::render('lmsx-1', array('completeAt' => 80, 'trackViewer' => true));
assert(strpos($html, 'complete-at="80"') !== false, 'complete-at printed on a lesson');
assert(strpos($html, 'data-fp-post="' . $lesson_track . '"') !== false, 'the lesson post rides on the element');
assert(strpos($html, 'viewer-key=') === false, 'no viewer-key for a logged-out visitor even with trackViewer on');

$GLOBALS['post'] = get_post($normal_post); setup_postdata($GLOBALS['post']);
Cache::flush_group('embed');
$html = Fastpix_Render::render('lmsx-1', array('completeAt' => 80, 'trackViewer' => true));
assert(strpos($html, 'complete-at') === false && strpos($html, 'viewer-key') === false,
    'no completion attributes on a plain post, whatever the block claims [ASSUME-046]');
wp_reset_postdata(); unset($GLOBALS['post']);

$admins = get_users(array('role' => 'administrator', 'number' => 1, 'fields' => 'ID'));
$admin  = (int) $admins[0];
$hash   = Fastpix_Lms::viewer_hash($admin);

/* ---------------------------------------- config comes from the block */

$cfg = Fastpix_Lms::embed_config($lesson_track, 'lmsx-1');
assert($cfg['completeAt'] === 80 && $cfg['trackViewer'] === true, 'the lesson block is the authority on completeAt/trackViewer');
assert(Fastpix_Lms::embed_config($lesson_track, 'other-media') === null, 'unknown media on the lesson → null');
assert(Fastpix_Lms::embed_config($normal_post, 'lmsx-1') === null, 'a non-lesson post never yields a config');

// Skip-proof button gating: the hide list covers LearnPress; the style tag hides
// those selectors until `body.fastpix-antiskip-done`, and emits only once/request.
assert(in_array('.button-complete-lesson', Fastpix_Lms::hide_selectors(), true), 'LearnPress complete button is in the hide list');
$css = Fastpix_Lms::antiskip_css();
assert(strpos($css, '.button-complete-lesson') !== false && strpos($css, 'display:none') !== false, 'gate CSS hides the LMS complete button');
assert(strpos($css, 'body:not(.fastpix-antiskip-done)') !== false, 'the gate lifts once the video is watched');
// The earlier lesson render already consumed the one-shot <style> for this request.
assert(Fastpix_Lms::antiskip_style_tag() === '', 'the gate style is emitted at most once per request');

/* ------------------------------------------------- tracked completion */

wp_set_current_user($admin);
Cache::flush_group('ratelimit');

// 70 slots < 80: stored, not complete.
$r = lmsreq(array('video' => $video_id, 'position' => 70, 'covered' => 70, 'post' => $lesson_track, 'slots' => lms_slots(70), 'learner' => $hash));
assert($r->get_status() === 200, 'a lesson beat rides the progress route');
$row = $wpdb->get_row($wpdb->prepare(SQL_SELECT_ALL_FROM . Schema::table('lesson_progress') . SQL_WHERE_VIEWER_POST, $hash, $lesson_track), ARRAY_A);
assert($row !== null && $row['completed_at'] === null, 'trackViewer on → a per-learner row, not yet complete');
assert(empty($fired), 'below the threshold nothing fires');

// A "seek" beat: a smaller bitmap must not shrink what is stored (server OR).
lmsreq(array('video' => $video_id, 'position' => 10, 'covered' => 70, 'post' => $lesson_track, 'slots' => lms_slots(5), 'learner' => $hash));
$row = $wpdb->get_row($wpdb->prepare('SELECT slots FROM ' . Schema::table('lesson_progress') . SQL_WHERE_VIEWER_POST, $hash, $lesson_track), ARRAY_A);
$popcount = 0; foreach (str_split((string) $row['slots']) as $b) { $popcount += substr_count(decbin(ord($b)), '1'); }
assert($popcount === 70, 'slots only accumulate — a seek cannot fill or clear them [ASSUME-046]');

// Crossing 80: completes once.
lmsreq(array('video' => $video_id, 'position' => 85, 'covered' => 85, 'post' => $lesson_track, 'slots' => lms_slots(85), 'learner' => $hash));
$row = $wpdb->get_row($wpdb->prepare(SQL_SELECT_ALL_FROM . Schema::table('lesson_progress') . SQL_WHERE_VIEWER_POST, $hash, $lesson_track), ARRAY_A);
assert($row['completed_at'] !== null, 'completed_at set at the crossing');
assert(count($fired) === 1 && $fired[0] === array('lmsx-1', $lesson_track, $hash), 'fastpix_video_completed fired with (media_id, post_id, viewer_key)');
assert($GLOBALS['fp_ld_marked'] === array(array($admin, $lesson_track)), 'the lesson is marked complete in the LMS automatically — no snippet needed [ASSUME-046]');
// The log line (what the Settings problems box prints) names no WP user id;
// the context carries the hashed learner key only. [X16]
$marked_log = $wpdb->get_row('SELECT message, context FROM ' . Schema::table('logs') . " WHERE error_code = 'lesson_marked_in_lms' ORDER BY id DESC LIMIT 1", ARRAY_A);
assert($marked_log !== null && strpos($marked_log['message'], 'user ') === false && strpos($marked_log['context'], substr($hash, 0, 12)) !== false,
    'completion log lines carry a hashed learner key, never a user id [X16]');

lmsreq(array('video' => $video_id, 'position' => 99, 'covered' => 99, 'post' => $lesson_track, 'slots' => lms_slots(100), 'learner' => $hash));
assert(count($fired) === 1, 'completion fires exactly once');

// A forged learner hash is ignored — no row for it.
lmsreq(array('video' => $video_id, 'position' => 90, 'covered' => 90, 'post' => $lesson_track, 'slots' => lms_slots(90), 'learner' => str_repeat('a', 64)));
assert((int) $wpdb->get_var($wpdb->prepare(SQL_SELECT_COUNT_FROM . Schema::table('lesson_progress') . SQL_WHERE_VIEWER, str_repeat('a', 64))) === 0,
    'a learner hash that does not match the logged-in user writes nothing');

/* -------------------------------------------------- untracked lesson */

$before = (int) $wpdb->get_var(SQL_SELECT_COUNT_FROM . Schema::table('lesson_progress'));
lmsreq(array('video' => $video_id, 'position' => 95, 'covered' => 95, 'post' => $lesson_plain, 'slots' => lms_slots(95), 'learner' => $hash));
assert((int) $wpdb->get_var(SQL_SELECT_COUNT_FROM . Schema::table('lesson_progress')) === $before,
    'trackViewer off → no rows written, server-enforced [ASSUME-046]');
assert(count($fired) === 2 && $fired[1] === array('lmsx-1', $lesson_plain, null), 'completion still fires, viewer_key null');
assert(count($GLOBALS['fp_ld_marked']) === 2 && $GLOBALS['fp_ld_marked'][1] === array($admin, $lesson_plain),
    'a logged-in student gets LMS credit even without per-learner tracking');
lmsreq(array('video' => $video_id, 'position' => 99, 'covered' => 99, 'post' => $lesson_plain, 'slots' => lms_slots(99), 'learner' => $hash));
assert(count($fired) === 2, 'the untracked completion is deduped too');

// A beat naming a post that does not embed the video is dropped.
$stranger = wp_insert_post(array('post_title' => 'Stranger lesson', 'post_status' => 'publish', 'post_type' => 'sfwd-lessons', 'post_content' => 'x'));
lmsreq(array('video' => $video_id, 'position' => 99, 'covered' => 99, 'post' => $stranger, 'slots' => lms_slots(99), 'learner' => $hash));
assert(count($fired) === 2, 'no usage row → the lesson beat is ignored');

/* ---------------------------------- classic-editor lesson (shortcode) */

// TutorLMS's builder never loads Gutenberg — the options travel as shortcode
// attributes and the server reads them from the lesson content. [ASSUME-046]
// Auto-marking off here: this fixture is Tutor's real post type on a site
// where Tutor is actually active.
$GLOBALS['fp_auto'] = false;
$lesson_sc = wp_insert_post(array('post_title' => 'Shortcode lesson', 'post_status' => 'publish', 'post_type' => 'lesson',
    'post_content' => '[fastpix id="lmsx-1" complete_at="80" track_viewer]'));
$wpdb->insert(Schema::table('usage'), array('video_id' => $video_id, 'post_id' => $lesson_sc, 'context' => 'render',
    'occurrences' => 1, 'last_seen_at' => $now, 'created_at' => $now, 'updated_at' => $now));

$cfg = Fastpix_Lms::embed_config($lesson_sc, 'lmsx-1');
assert($cfg !== null && $cfg['completeAt'] === 80 && $cfg['trackViewer'] === true,
    'a shortcode lesson yields the same authoritative config as a block lesson');

lmsreq(array('video' => $video_id, 'position' => 85, 'covered' => 85, 'post' => $lesson_sc, 'slots' => lms_slots(85), 'learner' => $hash));
$row = $wpdb->get_row($wpdb->prepare(SQL_SELECT_ALL_FROM . Schema::table('lesson_progress') . SQL_WHERE_VIEWER_POST, $hash, $lesson_sc), ARRAY_A);
assert($row !== null && $row['completed_at'] !== null, 'the shortcode lesson tracks and completes');
assert(count($fired) === 3 && $fired[2] === array('lmsx-1', $lesson_sc, $hash), 'and fires the hook with the learner key');

/* ------------------------------------------------ course-wise analytics */

// The list is a picker only — no figures, no site-wide roll-up. [ASSUME-046]
$cl = rest_get_server()->dispatch(new WP_REST_Request('GET', '/fastpix/v1/analytics/courses'))->get_data();
assert(isset($cl['courses']), 'the course picker answers');
foreach ($cl['courses'] as $co) {
    assert(array_keys($co) === array('id', 'title'), 'the picker carries id + title only — no aggregate figures');
}

// One course: its video lessons and the students ENROLLED in it.
$course_id = _fp_course_of_fixture($lesson_track);
if ($course_id) {
    $cdetail = rest_get_server()->dispatch(new WP_REST_Request('GET', REST_ANALYTICS_COURSES . $course_id))->get_data();
    assert(isset($cdetail['students']) && isset($cdetail['lessons']), 'the course view carries its lessons and students');
    assert(!isset($cdetail['totals']), 'no site-wide totals in a course-wise view');
    // [ASSUME-046 revision] Rows are keyed by a hashed id and NEVER carry an
    // email. A full administrator (this test's user) may resolve the hash to a
    // display name for the hover tooltip; lower analytics roles may not.
    foreach ($cdetail['students'] as $st) {
        assert(!isset($st['email']), 'roster rows never carry an email');
        assert(isset($st['viewer']) && ctype_xdigit((string) $st['viewer']), 'roster rows are keyed by a hashed viewer id');
        assert(array_key_exists('name', $st) && $st['name'] !== null && $st['name'] !== '', 'an administrator resolves the hash to a display name');
    }

    // A non-admin analytics viewer (view_analytics but not manage_options) must
    // NOT receive names — the reveal is admin-only.
    $editors = get_users(array('role' => 'editor', 'number' => 1, 'fields' => 'ID'));
    if ($editors) {
        wp_set_current_user((int) $editors[0]);
        $as_editor = rest_get_server()->dispatch(new WP_REST_Request('GET', REST_ANALYTICS_COURSES . $course_id));
        if ($as_editor->get_status() === 200) {
            foreach ((array) ($as_editor->get_data()['students'] ?? array()) as $st) {
                assert(empty($st['name']), 'a non-admin analytics viewer never gets a learner name [privacy]');
            }
        }
        wp_set_current_user($admin);   // restore admin for the rest of the test
    }
}
$missing = rest_get_server()->dispatch(new WP_REST_Request('GET', REST_ANALYTICS_COURSES . '99999999'));
assert($missing->get_status() === 404, 'an unknown course is a 404');

// Course analytics honour the per-author scope like the site/video reads:
// without the any-video capability only lessons embedding the caller's own
// (connected-workspace) videos remain. The plain post stands in as a course. [X20]
$as_course = function ($course, $lesson) use ($lesson_track, $normal_post) { return $lesson === $lesson_track ? $normal_post : $course; };
add_filter('fastpix_course_of_post', $as_course, 10, 2);
$full = rest_get_server()->dispatch(new WP_REST_Request('GET', REST_ANALYTICS_COURSES . $normal_post))->get_data();
assert(count($full['lessons']) === 1, 'unrestricted: the course lists its video lesson');
$drop_cap = function ($caps) { unset($caps[\Fastpix\Fastpix_Capabilities::EDIT_VIDEO]); return $caps; };
add_filter('user_has_cap', $drop_cap);
$scoped = rest_get_server()->dispatch(new WP_REST_Request('GET', REST_ANALYTICS_COURSES . $normal_post));
assert($scoped->get_status() === 404, 'restricted: a course with none of the caller\'s lessons is a 404 — no roster, no enrolled count [X20]');
remove_filter('user_has_cap', $drop_cap);
remove_filter('fastpix_course_of_post', $as_course, 10);

/* ------------------------------------------------- play counts (rewatch) */

// The player now reports per-slot PLAY COUNTS; the server merges by element-
// wise max and derives the legacy bitmap. [ASSUME-046, 2026-08-25]
$plays = array_fill(0, 100, 0);
for ($i = 0; $i < 50; $i++) { $plays[$i] = 1; }
for ($i = 10; $i < 20; $i++) { $plays[$i] = 3; }   // a rewatched stretch
$plays_hex = implode('', array_map(function ($n) { return sprintf('%02x', $n); }, $plays));
lmsreq(array('video' => $video_id, 'position' => 50, 'covered' => 50, 'post' => $lesson_track, 'slots' => lms_slots(1), 'plays' => $plays_hex, 'learner' => $hash));
$row = $wpdb->get_row($wpdb->prepare('SELECT plays, slots FROM ' . Schema::table('lesson_progress') . SQL_WHERE_VIEWER_POST, $hash, $lesson_track), ARRAY_A);
assert(!empty($row['plays']), 'play counts are stored');
assert(ord($row['plays'][15]) === 3, 'a rewatched slot keeps its count');
assert(ord($row['plays'][99]) >= 1, 'earlier full-watch counts survive the max-merge — a stale beat cannot lower them');
assert(Fastpix_Lms::count_played($row['plays']) === 100, 'played-slot count derives from counts');

/* ------------------------------- one completion across both beat paths */

// A tracked lesson whose first beat arrives WITHOUT the learner hash (cached
// page, tracking toggled on later) completes on the untracked path; the
// tracked beat that follows must not fire a second time. [X22]
$lesson_x = wp_insert_post(array('post_title' => 'Crosspath lesson', 'post_status' => 'publish', 'post_type' => 'sfwd-lessons',
    'post_content' => $block(array('videoId' => 'lmsx-1', 'completeAt' => 80, 'trackViewer' => true))));
$wpdb->insert(Schema::table('usage'), array('video_id' => $video_id, 'post_id' => $lesson_x, 'context' => 'render',
    'occurrences' => 1, 'last_seen_at' => $now, 'created_at' => $now, 'updated_at' => $now));
$fired_before = count($fired);
lmsreq(array('video' => $video_id, 'position' => 90, 'covered' => 90, 'post' => $lesson_x, 'slots' => lms_slots(90)));
assert(count($fired) === $fired_before + 1 && end($fired)[2] === null, 'no learner hash → the untracked path completes once');
lmsreq(array('video' => $video_id, 'position' => 95, 'covered' => 95, 'post' => $lesson_x, 'slots' => lms_slots(95), 'learner' => $hash));
$xrow = $wpdb->get_row($wpdb->prepare(SQL_SELECT_ALL_FROM . Schema::table('lesson_progress') . SQL_WHERE_VIEWER_POST, $hash, $lesson_x), ARRAY_A);
assert($xrow !== null && $xrow['completed_at'] !== null, 'the tracked row still records the completion');
assert(count($fired) === $fired_before + 1, 'but the hook does not fire twice for one learner across the two paths [X22]');

/* --------------------------------------------------------- rate limits */

$GLOBALS['fp_relax'] = false;
Cache::flush_group('ratelimit');
$codes = array();
for ($i = 0; $i < 16; $i++) {
    $codes[] = lmsreq(array('video' => $video_id, 'position' => 50, 'covered' => 50, 'post' => $lesson_track, 'slots' => lms_slots(50), 'learner' => $hash))->get_status();
}
assert($codes[0] === 200, 'the first write in the window lands');
assert($codes[15] === 429, 'the 16th write inside the 15 s window is rejected [RULE-032]');
$GLOBALS['fp_relax'] = true;

/* ------------------------------------------------------------- prune */

update_option(Fastpix_Lms::OPT_RETENTION, 90, false);
$wpdb->insert(Schema::table('lesson_progress'), array(
    'viewer_key' => str_repeat('b', 64), 'post_id' => $lesson_track, 'media_id' => 'lmsx-1',
    'slots' => str_repeat("\xff", 5), 'max_position' => 40,
    'updated_at' => gmdate('Y-m-d H:i:s', time() - 200 * DAY_IN_SECONDS),
));
Fastpix_Lms::prune();
assert((int) $wpdb->get_var($wpdb->prepare(SQL_SELECT_COUNT_FROM . Schema::table('lesson_progress') . SQL_WHERE_VIEWER, str_repeat('b', 64))) === 0,
    'rows past the retention window are pruned');
assert((int) $wpdb->get_var($wpdb->prepare(SQL_SELECT_COUNT_FROM . Schema::table('lesson_progress') . " WHERE viewer_key = %s AND media_id LIKE 'lmsx-%%'", $hash)) === 3,
    'recent rows survive the prune (block + shortcode + crosspath lessons)');

/* ------------------------------------------------------------ privacy */

$email = get_userdata($admin)->user_email;
// The eraser rightly removes EVERY row for the account — snapshot the user's
// real (non-fixture) rows so the live site's data survives this check.
$real_rows = $wpdb->get_results($wpdb->prepare(
    SQL_SELECT_ALL_FROM . Schema::table('lesson_progress') . " WHERE viewer_key = %s AND media_id NOT LIKE 'lmsx-%%'", $hash
), ARRAY_A);
$export = Fastpix_Lms::export_personal_data($email);
assert(count($export['data']) >= 1, 'the learner rows appear in the personal data export');
$erase = Fastpix_Lms::erase_personal_data($email);
assert($erase['items_removed'] === true, 'the eraser removes them');
assert((int) $wpdb->get_var($wpdb->prepare(SQL_SELECT_COUNT_FROM . Schema::table('lesson_progress') . SQL_WHERE_VIEWER, $hash)) === 0,
    'nothing keyed to the account remains');
foreach ($real_rows as $real) {
    $wpdb->insert(Schema::table('lesson_progress'), $real);
}

/* ------------------------------------------------------------ teardown */

foreach (array($lesson_track, $lesson_plain, $normal_post, $stranger, $lesson_sc, $lesson_x) as $pid) { wp_delete_post($pid, true); }
$wpdb->query($wpdb->prepare(SQL_DELETE_FROM . Schema::table('usage') . ' WHERE video_id = %d', $video_id));
$wpdb->query(SQL_DELETE_FROM . Schema::table('lesson_progress') . SQL_WHERE_MEDIA_LMSX);
$wpdb->query(SQL_DELETE_FROM . Schema::table('watch_progress') . " WHERE video_id = " . (int) $video_id);
$wpdb->query(SQL_DELETE_FROM . Schema::table('videos') . SQL_WHERE_MEDIA_LMSX);
$wpdb->query(SQL_DELETE_FROM . Schema::table('playback_ids') . " WHERE playback_id = 'pb-lmsx-1'");
Cache::flush_group('ratelimit');
Cache::flush_group('lms');
Cache::flush_group('embed');

echo "test-lms-progress: OK\n";
