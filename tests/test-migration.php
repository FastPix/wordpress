<?php
/**
 * Self-check for Media Library migration — WF-004, FR-020…024, REQ-020…029,
 * REQ-104, REQ-122, RULE-007/018/019, ERR-050…055, TEST-004.
 *
 *   docker compose exec wordpress php -d zend.assertions=1 \
 *     /var/www/html/wp-content/plugins/fastpix/tests/test-migration.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

use Fastpix\Fastpix_Api_Client as Client;
use Fastpix\Fastpix_Cache as Cache;
use Fastpix\Fastpix_Credentials as Creds;
use Fastpix\Fastpix_Migration as Mig;
use Fastpix\Fastpix_Schema as Schema;
use Fastpix\Fastpix_Sync as Sync;

const SQL_SELECT_COUNT_FROM = 'SELECT COUNT(*) FROM ';
const SQL_DELETE_FROM = 'DELETE FROM ';
const SQL_WHERE_ID = ' WHERE id = %d';
const REST_MIGRATION = '/migration/';
const REST_CLEANUP = '/cleanup';
const ATTR_PLAYBACK_ID = 'playback-id="';
const REST_MIGRATION_SCAN = '/migration/scan';
const REST_MIGRATION_RUN = '/migration/run';
const REST_RETRY = '/retry';
const SQL_WHERE_ID_IN = ' WHERE id IN (';
const SQL_SELECT_ID_FROM = 'SELECT id FROM ';
const SQL_SELECT_VIDEO_ID_FROM = 'SELECT video_id FROM ';

global $wpdb;

$saved = array();
foreach (array(Creds::OPT_TOKEN_ID, Creds::OPT_SECRET, Client::OPT_HEALTH, Mig::OPT_LEGACY, 'fastpix_api_key', 'fastpix_api_secret', 'fastpix_videos', 'fastpix_workspace_seen_id', 'fastpix_workspace_seen_name',   // the learned workspace must survive fixtures
             \Fastpix\Fastpix_Connection::OPT_PENDING_LEAVE, \Fastpix\Fastpix_Connection::OPT_LEFT_UNKNOWN, \Fastpix\Fastpix_Connection::OPT_LAST_TOKEN, \Fastpix\Fastpix_Connection::OPT_LAST_KEY) as $opt) {   // the leave machinery must never fire on real data
    $saved[$opt] = get_option($opt, null);
}
// Restore even when a failed assertion aborts the run — a leaked fixture pair once shadowed the live one.
register_shutdown_function(function () use (&$saved) {
    foreach ($saved as $opt => $value) { $value === null ? delete_option($opt) : update_option($opt, $value, false); }
});
foreach (array(\Fastpix\Fastpix_Connection::OPT_PENDING_LEAVE, \Fastpix\Fastpix_Connection::OPT_LEFT_UNKNOWN, \Fastpix\Fastpix_Connection::OPT_LAST_TOKEN, \Fastpix\Fastpix_Connection::OPT_LAST_KEY) as $opt) { delete_option($opt); }   // learn_workspace can never wipe
Creds::store('4f27c4c4-9a1e-4c2f-9d33-2b8f0a7c1e55', 'sk_selfcheck_migration');
delete_option(Client::OPT_HEALTH);
add_filter('fastpix_api_backoff_seconds', '__return_zero');
wp_set_current_user(1);

// ------------------------------------------------------------- transport mock

$requests = array();
$mock = array('fail_urls' => array());   // source URLs the platform "cannot fetch" (ERR-051)
add_filter('pre_http_request', function ($_pre, $args, $url) use (&$requests, &$mock) {
    $body = isset($args['body']) ? json_decode($args['body'], true) : null;
    $requests[] = array('method' => $args['method'], 'url' => $url, 'body' => $body);

    if (strpos($url, '/on-demand/upload') !== false) {
        return array('headers' => array(), 'body' => wp_json_encode(array('success' => true, 'data' => array('uploadId' => 'up-mig-1', 'mediaId' => 'mig-media-pushed', 'url' => 'https://storage.example.com/put'))), 'response' => array('code' => 200, 'message' => ''));
    }
    if ($args['method'] !== 'POST' || !preg_match('#/on-demand$#', $url)) {
        return array('headers' => array(), 'body' => wp_json_encode(array('success' => true, 'data' => array())), 'response' => array('code' => 200, 'message' => ''));
    }

    $src = isset($body['inputs'][0]['url']) ? $body['inputs'][0]['url'] : '';
    $ws = \Fastpix\Fastpix_Videos_Rest::connected_workspace() ?: 'ws-mig';

    return in_array($src, $mock['fail_urls'], true)
        ? array('headers' => array(), 'body' => wp_json_encode(array('success' => false, 'error' => array('code' => 403, 'message' => 'source refused'))), 'response' => array('code' => 403, 'message' => ''))
        // not a real secret — a stable, non-cryptographic fixture id derived from the source URL
        : array('headers' => array(), 'body' => wp_json_encode(array('success' => true, 'data' => array('id' => 'mig-media-' . substr(hash('sha256', $src), 0, 12), 'status' => 'Preparing', 'workspaceId' => $ws))), 'response' => array('code' => 200, 'message' => ''));
}, 10, 3);
// Reachability is decided by a filter here — the real check does an outbound HEAD.
$unreachable = array();
add_filter('fastpix_migration_reachable', function ($_pre, $url) use (&$unreachable) { return !in_array($url, $unreachable, true); }, 10, 2);

// ---------------------------------------------------------------- fixtures

$upload = wp_upload_dir();
$fixtures = array();
// Leftovers from an aborted run would skew usage counts — clear them first.
foreach ($wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_title LIKE 'mig-check-%'") as $old) { $p = get_attached_file($old); wp_delete_attachment($old, true); if ($p && file_exists($p)) { @unlink($p); } }
foreach ($wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_title = 'migration fixture post'") as $old) { wp_delete_post($old, true); }
foreach (glob(trailingslashit($upload['basedir']) . 'mig-check-*') as $f) { @unlink($f); }
function mig_file($name, $bytes) {
    global $upload;
    $path = trailingslashit($upload['basedir']) . $name;
    file_put_contents($path, $bytes);
    return $path;
}
function mig_attachment($name, $bytes, $mime = 'video/mp4') {
    global $fixtures;
    $path = mig_file($name, $bytes);
    $id = wp_insert_attachment(array('post_mime_type' => $mime, 'post_title' => preg_replace('/\.\w+$/', '', $name), 'post_status' => 'inherit'), $path);
    update_post_meta($id, '_wp_attached_file', str_replace(trailingslashit(wp_upload_dir()['basedir']), '', $path));
    $fixtures[] = $id;
    return $id;
}
$mp4 = str_repeat("\x00", 4) . 'ftypisom' . str_repeat('x', 200);   // looks like an MP4
$att_ok      = mig_attachment('mig-check-ok.mp4', $mp4);
$att_ok2     = mig_attachment('mig-check-ok2.mp4', $mp4);
$att_wmv     = mig_attachment('mig-check-old.wmv', $mp4, 'video/x-ms-wmv');   // wmv IS accepted by config; use an unaccepted mime instead
wp_update_post(array('ID' => $att_wmv, 'post_mime_type' => 'video/x-unknown'));
$att_fake    = mig_attachment('mig-check-fake.mp4', str_repeat('hello world ', 30));   // says mp4, is text (ERR-053)
$att_missing = mig_attachment('mig-check-missing.mp4', $mp4); unlink(get_attached_file($att_missing));   // row without file (ERR-052)
$att_unreach = mig_attachment('mig-check-private.mp4', $mp4);
$unreachable[] = wp_get_attachment_url($att_unreach);
$att_refused = mig_attachment('mig-check-refused.mp4', $mp4);
$mock['fail_urls'][] = wp_get_attachment_url($att_refused);

$post_id = wp_insert_post(array('post_title' => 'migration fixture post', 'post_status' => 'publish',
    'post_content' => 'Local: [video src="' . wp_get_attachment_url($att_ok) . '"] and <!-- wp:video {"id":' . $att_ok2 . '} --><figure class="wp-block-video"><video controls src="' . wp_get_attachment_url($att_ok2) . '"></video></figure><!-- /wp:video -->'));
$original_post_content = get_post($post_id)->post_content;
// M21: a decoy that must NOT count as usage of $att_ok — a block id with an extra digit and a file with a prefix.
$decoy_id = wp_insert_post(array('post_title' => 'migration fixture post', 'post_status' => 'publish',
    'post_content' => '<!-- wp:video {"id":' . $att_ok . '7} --><video src="https://x.test/my-mig-check-ok.mp4"></video><!-- /wp:video --><!-- wp:image {"id":' . $att_ok . '} --><!-- /wp:image -->'));

// Only THIS test's leftovers: batches whose items are all fixture attachments (or that have no items) — never a real batch.
$stale = $wpdb->get_col('SELECT m.batch_id FROM ' . Schema::table('migrations') . ' m WHERE NOT EXISTS (
    SELECT 1 FROM ' . Schema::table('migration_items') . " i JOIN {$wpdb->posts} p ON p.ID = i.attachment_id WHERE i.batch_id = m.batch_id AND p.post_title NOT LIKE 'mig-check-%')");
foreach ($stale as $sb) {
    $has_real = (int) $wpdb->get_var($wpdb->prepare(SQL_SELECT_COUNT_FROM . Schema::table('migration_items') . " i WHERE i.batch_id = %s AND i.attachment_id NOT IN (SELECT ID FROM {$wpdb->posts} WHERE post_title LIKE 'mig-check-%%')", $sb));
    if ($has_real === 0) {
        $wpdb->delete(Schema::table('migration_items'), array('batch_id' => $sb));
        $wpdb->delete(Schema::table('migrations'), array('batch_id' => $sb));
    }
}
$wpdb->query(SQL_DELETE_FROM . Schema::table('videos') . " WHERE media_id LIKE 'mig-media-%'");
// A real un-run scan would block this test's scan; park it aside and put it back at the end.
$parked = $wpdb->get_var("SELECT batch_id FROM " . Schema::table('migrations') . " WHERE state IN ('scanning','scanned') ORDER BY id DESC LIMIT 1");
if ($parked) { $wpdb->update(Schema::table('migrations'), array('state' => 'parked-by-test'), array('batch_id' => $parked)); }
$live_running = $wpdb->get_var("SELECT batch_id FROM " . Schema::table('migrations') . " WHERE state IN ('running','paused') LIMIT 1");
if ($live_running) { echo "a real migration is running — not testing against it\n"; exit(0); }
foreach (array(Mig::HOOK_SCAN, Mig::HOOK_ITEM, Mig::HOOK_FINALISE) as $h) { as_unschedule_all_actions($h); }

function mreq($method, $path, $params = array()) {
    $r = new WP_REST_Request($method, '/fastpix/v1' . $path);
    foreach ($params as $k => $v) { $r->set_param($k, $v); }
    return rest_get_server()->dispatch($r);
}

// --------------------------------------------------------- 1. scan (nothing moves)

$res = mreq('POST', REST_MIGRATION_SCAN);
assert(!$res->is_error(), 'scan starts');
$batch_id = $res->get_data()['batch_id'];
assert(as_has_scheduled_action(Mig::HOOK_SCAN) !== false, 'the scan is a background job [ARCH-07]');
$before = count($requests);
Mig::scan_job(array('batch_id' => $batch_id, 'offset' => 0));
assert(count($requests) === $before, 'the scan submits nothing to the platform [REQ-021]');
$scan = mreq('GET', REST_MIGRATION_SCAN, array('per_page' => 50))->get_data();
assert($scan['state'] === 'scanned', 'scan finished');
$by = array();
foreach ($scan['items']['rows'] as $it) { $by[$it['attachment_id']] = $it; }
assert($by[$att_ok]['state'] === 'pending' && $by[$att_ok]['used_on_posts'] === 1, 'a movable item with its usage ("Used on 1 post") — the decoy post with id 1234-style and my-file.mp4 does not count, nor does another block type with the same id (M21)');
assert($by[$att_wmv]['state'] === 'skipped' && $by[$att_wmv]['skip_reason'] === 'Format not accepted', 'ERR-050 format skip, reason inline');
assert($by[$att_fake]['state'] === 'skipped' && strpos($by[$att_fake]['skip_reason'], 'contents do not') !== false, 'ERR-053 contents-not-video');
assert(!isset($by[$att_missing]) && $scan['totals']['left_out'] >= 1, 'a row without a file is not a WordPress-hosted video — left out and counted, not listed');
assert($by[$att_unreach]['state'] === 'pending' && $by[$att_unreach]['error_code'] === 'unreachable' && strpos($by[$att_unreach]['skip_reason'], 'uploaded from here') !== false, 'unreachable ⇒ rerouted to the upload path, said so [REQ-023]');
assert($scan['totals']['skipped'] >= 2 && $scan['totals']['movable'] >= 4, 'totals: what can move and what cannot');
assert(is_int($scan['totals']['audience_change_posts']), 'the report counts posts whose audience would change [REQ-028]');
assert($scan['estimated_cost'] === null, 'no invented cost figure (ASSUME-036)');

// -------------------------------------------------- 2. run: decide + submit

// SELECTION scope with the fixture attachments only — this test must never touch a real attachment (a cleanup deletes files).
$run = mreq('POST', REST_MIGRATION_RUN, array('batch_id' => $batch_id, 'scope' => 'selection', 'ids' => $fixtures, 'quality_tier' => 'pro', 'access_policy' => 'public'));
assert(!$res->is_error() && $run->get_data()['state'] === 'running', 'run queues the batch');
assert(count(as_get_scheduled_actions(array('hook' => Mig::HOOK_ITEM, 'status' => 'pending', 'per_page' => 100), 'ids')) === Mig::CONCURRENCY, 'QA M10: run queues only the free slots — the pump feeds the rest [REQ-029]');
$b = Mig::batch($batch_id);
assert($b['quality_tier'] === 'pro' && $b['access_policy'] === 'public', 'tier + policy stored on the batch [REQ-022]');
assert(mreq('POST', REST_MIGRATION_RUN, array('batch_id' => $batch_id, 'scope' => 'all'))->is_error(), 'a batch runs once');

$items = $wpdb->get_results($wpdb->prepare('SELECT id, attachment_id FROM ' . Schema::table('migration_items') . " WHERE batch_id = %s AND state = 'pending' ORDER BY id", $batch_id), ARRAY_A);
$item_of = array(); foreach ($items as $it) { $item_of[(int) $it['attachment_id']] = (int) $it['id']; }

$requests = array();
Mig::item_job(array('batch_id' => $batch_id, 'item_id' => $item_of[$att_ok]));
$posts = array_values(array_filter($requests, function ($r) { return $r['method'] === 'POST' && preg_match('#/on-demand$#', $r['url']); }));
assert(count($posts) === 1 && $posts[0]['body']['inputs'][0]['url'] === wp_get_attachment_url($att_ok) && $posts[0]['body']['accessPolicy'] === 'public', 'the platform is handed the attachment URL, not the bytes [REQ-023]');
$row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Schema::table('migration_items') . SQL_WHERE_ID, $item_of[$att_ok]), ARRAY_A);
assert($row['state'] === 'submitted' && $row['video_id'], 'submitted and linked to a video row');
$video = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Schema::table('videos') . SQL_WHERE_ID, $row['video_id']), ARRAY_A);
assert($video['source'] === 'Migrated' && (int) $video['attachment_id'] === $att_ok && $video['quality_tier'] === 'pro', 'source Migrated + origin pointer to the ORIGINAL attachment [REQ-024]');
assert(file_exists(get_attached_file($att_ok)), 'the local file is untouched — copy, never move [REQ-020, RULE-018]');
assert(get_post($post_id)->post_content === $original_post_content && strpos(get_post($post_id)->post_content, 'fastpix') === false, 'no post edited');

// Idempotency: re-running the same item never creates a second copy. [RULE-041]
$requests = array();
Mig::item_job(array('batch_id' => $batch_id, 'item_id' => $item_of[$att_ok]));
assert(count($requests) === 0, 'a submitted item is not resubmitted');

// Refused by the platform (host 403) ⇒ rerouted through the upload path. [ERR-051]
$requests = array();
Mig::item_job(array('batch_id' => $batch_id, 'item_id' => $item_of[$att_refused]));
assert(count(array_filter($requests, function ($r) { return strpos($r['url'], '/on-demand/upload') !== false; })) === 1, 'ERR-051: refused fetch ⇒ upload session from here');
$row = $wpdb->get_row($wpdb->prepare('SELECT state, error_code, video_id FROM ' . Schema::table('migration_items') . SQL_WHERE_ID, $item_of[$att_refused]), ARRAY_A);
// The PUT itself goes out via cURL to storage.example.com and cannot succeed here — the item fails with a stated reason, per item.
assert(in_array($row['state'], array('failed', 'submitted'), true), 'the reroute resolves per item (' . $row['state'] . ')');

// Four in flight, never more. [ASSUME-002] — throwaway rows only; never touch scanned rows.
$fakes = array();
for ($k = 1; $k <= 4; $k++) {
    $wpdb->insert(Schema::table('migration_items'), array('batch_id' => $batch_id, 'attachment_id' => 999990 + $k, 'state' => 'submitting', 'created_at' => current_time('mysql', true), 'updated_at' => current_time('mysql', true)));
    $fakes[] = (int) $wpdb->insert_id;
}
$wpdb->insert(Schema::table('migration_items'), array('batch_id' => $batch_id, 'attachment_id' => 999999, 'state' => 'pending', 'created_at' => current_time('mysql', true), 'updated_at' => current_time('mysql', true)));
$extra = (int) $wpdb->insert_id;
as_unschedule_all_actions(Mig::HOOK_ITEM);
$requests = array();
Mig::item_job(array('batch_id' => $batch_id, 'item_id' => $extra));
assert(count($requests) === 0 && as_has_scheduled_action(Mig::HOOK_ITEM) !== false, 'the fifth waits its turn — four at a time [REQ-029]');
// M10: a finishing item pumps the next pending one into the freed slot — no 10-second polling per waiting item.
$wpdb->delete(Schema::table('migration_items'), array('id' => array_pop($fakes)));   // three in flight
as_unschedule_all_actions(Mig::HOOK_ITEM);
Mig::item_job(array('batch_id' => $batch_id, 'item_id' => $item_of[$att_unreach]));   // the push cannot reach storage.example.com here — it fails, and pumps
assert($wpdb->get_var($wpdb->prepare('SELECT state FROM ' . Schema::table('migration_items') . SQL_WHERE_ID, $item_of[$att_unreach])) === 'failed', 'the unreachable item took the push road and failed per item');
assert(count(as_get_scheduled_actions(array('hook' => Mig::HOOK_ITEM, 'status' => 'pending', 'per_page' => 10), 'ids')) === 1, 'M10: exactly one freed slot ⇒ exactly one pending item queued');
$wpdb->query(SQL_DELETE_FROM . Schema::table('migration_items') . SQL_WHERE_ID_IN . implode(',', array_merge($fakes, array($extra))) . ')');

// Pause parks items at the boundary; resume re-queues every pending item once. [REQ-029, REQ-122]
assert(!mreq('POST', REST_MIGRATION . $batch_id . '/pause')->is_error(), 'pause');
as_unschedule_all_actions(Mig::HOOK_ITEM);
$requests = array();
Mig::item_job(array('batch_id' => $batch_id, 'item_id' => $item_of[$att_ok2]));
assert(count($requests) === 0 && as_has_scheduled_action(Mig::HOOK_ITEM) === false, 'QA M10: a paused batch submits nothing and schedules nothing — resume re-queues');
$pending_before = (int) $wpdb->get_var($wpdb->prepare(SQL_SELECT_COUNT_FROM . Schema::table('migration_items') . " WHERE batch_id = %s AND state = 'pending'", $batch_id));
as_unschedule_all_actions(Mig::HOOK_ITEM);
assert(!mreq('POST', REST_MIGRATION . $batch_id . '/resume')->is_error(), 'resume');
$queued = as_get_scheduled_actions(array('hook' => Mig::HOOK_ITEM, 'status' => 'pending', 'per_page' => 100), 'ids');
assert(count($queued) === min($pending_before, Mig::CONCURRENCY) && $pending_before > 0, 'resume restarts the queue at the first unsubmitted items — nothing doubled; the pump carries the rest [REQ-122, QA M10]');
assert((int) $wpdb->get_var(SQL_SELECT_COUNT_FROM . Schema::table('logs') . " WHERE error_code = 'migration_resumed'") >= 1, 'logged migration_resumed [ERR-054]');
// (QA M10 stall) A healthy batch is left alone by the polled read; with every action gone (restart, queue cleared) the same read restarts it.
mreq('GET', REST_MIGRATION . $batch_id);
$healthy = count(as_get_scheduled_actions(array('hook' => Mig::HOOK_ITEM, 'status' => 'pending', 'per_page' => 100), 'ids'));
as_unschedule_all_actions(Mig::HOOK_ITEM);
mreq('GET', REST_MIGRATION . $batch_id);
assert($healthy === count($queued) && count(as_get_scheduled_actions(array('hook' => Mig::HOOK_ITEM, 'status' => 'pending', 'per_page' => 100), 'ids')) === count($queued), 'QA M10: a poll queues nothing on a healthy batch, and refills the free slots of a stalled one');

// Finish the rest.
foreach ($wpdb->get_col($wpdb->prepare(SQL_SELECT_ID_FROM . Schema::table('migration_items') . " WHERE batch_id = %s AND state = 'pending'", $batch_id)) as $id) {
    Mig::item_job(array('batch_id' => $batch_id, 'item_id' => (int) $id));
}
as_unschedule_all_actions(Mig::HOOK_ITEM);

// (QA reaper) The last item wedges in 'submitting' with no item_job left — the status read the UI polls reaps it and the batch finishes.
$wpdb->insert(Schema::table('migration_items'), array('batch_id' => $batch_id, 'attachment_id' => 999998, 'state' => 'submitting', 'created_at' => current_time('mysql', true), 'updated_at' => gmdate('Y-m-d H:i:s', time() - 31 * MINUTE_IN_SECONDS)));
$wedged = (int) $wpdb->insert_id;
$wpdb->update(Schema::table('migrations'), array('state' => 'running'), array('batch_id' => $batch_id));
$polled = mreq('GET', REST_MIGRATION . $batch_id)->get_data();
assert($wpdb->get_var($wpdb->prepare('SELECT state FROM ' . Schema::table('migration_items') . SQL_WHERE_ID, $wedged)) === 'failed' && $polled['state'] === 'done', 'QA reaper: a status read fails a row wedged in submitting past the cutoff and the batch finishes');
$wpdb->delete(Schema::table('migration_items'), array('id' => $wedged));
$wpdb->query($wpdb->prepare('UPDATE ' . Schema::table('migrations') . ' SET failed_count = failed_count - 1 WHERE batch_id = %s', $batch_id));

// -------------------------------------------- 3. verify gate + typed cleanup

$b = mreq('GET', REST_MIGRATION . $batch_id)->get_data();
assert($b['verification']['verified'] === false && $b['verification']['pending'] > 0, 'not verified while videos are still processing on the platform');
assert($b['verification']['processing'] === $b['verification']['pending'], 'M28: every locally-terminal pending item is "processing on FastPix", not merely queued');
assert(mreq('POST', REST_MIGRATION . $batch_id . REST_CLEANUP, array('confirm' => 'delete 99 files'))->is_error(), 'cleanup refused before verification [RULE-019]');

// The platform finishes: Ready + playback ids for the migrated videos (the failed/pushed one stays as it is).
$ready_ids = $wpdb->get_col($wpdb->prepare(SQL_SELECT_VIDEO_ID_FROM . Schema::table('migration_items') . " WHERE batch_id = %s AND state = 'submitted' AND video_id IS NOT NULL", $batch_id));
foreach ($ready_ids as $vid) {
    $wpdb->update(Schema::table('videos'), array('status' => 'Ready'), array('id' => (int) $vid));
    $wpdb->insert(Schema::table('playback_ids'), array('video_id' => (int) $vid, 'playback_id' => 'pb-mig-' . $vid, 'access_policy' => 'public', 'created_at' => current_time('mysql', true), 'updated_at' => current_time('mysql', true)));
}
Cache::flush_group('videos');
$b = mreq('GET', REST_MIGRATION . $batch_id)->get_data();
$v = $b['verification'];
assert($v['verified'] === true && $v['ready'] === count($ready_ids), 'verified: every item terminal, each ready video has a playback id');
$failed_count = $v['failed'];
assert(mreq('POST', REST_MIGRATION . $batch_id . REST_CLEANUP, array('confirm' => 'delete 1 file'))->is_error(), 'the typed confirmation must match exactly');
assert(mreq('POST', REST_MIGRATION . $batch_id . REST_CLEANUP, array('confirm' => 'delete ' . $v['cleanable'] . ' filess'))->is_error(), 'M26: "filess" is not a confirmation');

// M4/M17/M25: a copy deleted on FastPix after submission is a failure everywhere — counted, listed under the
// failed filter, retryable (without touching failed_count) — unless the owner reverted it (M25).
$wpdb->insert(Schema::table('videos'), array('media_id' => 'mig-media-gone', 'status' => 'Ready', 'source' => 'Migrated', 'deleted_at' => current_time('mysql', true), 'created_at' => current_time('mysql', true), 'updated_at' => current_time('mysql', true)));
$gone_vid = (int) $wpdb->insert_id;
$wpdb->insert(Schema::table('migration_items'), array('batch_id' => $batch_id, 'attachment_id' => 999980, 'video_id' => $gone_vid, 'state' => 'submitted', 'created_at' => current_time('mysql', true), 'updated_at' => current_time('mysql', true)));
$gone_item = (int) $wpdb->insert_id;
$wpdb->insert(Schema::table('migration_items'), array('batch_id' => $batch_id, 'attachment_id' => 999981, 'video_id' => $gone_vid, 'state' => 'submitted', 'reverted_at' => current_time('mysql', true), 'created_at' => current_time('mysql', true), 'updated_at' => current_time('mysql', true)));
$gone_reverted = (int) $wpdb->insert_id;
$b = mreq('GET', REST_MIGRATION . $batch_id, array('filter' => 'failed'))->get_data();
$listed = array_map(function ($r) { return $r['id']; }, $b['items']['rows']);
assert($b['verification']['failed'] === $failed_count + 1 && in_array($gone_item, $listed, true) && !in_array($gone_reverted, $listed, true), 'M4: the deleted copy is counted AND listed as failed; the reverted one is neither');
$fc_before = (int) Mig::batch($batch_id)['failed_count'];
as_unschedule_all_actions(Mig::HOOK_ITEM);
assert(mreq('POST', REST_MIGRATION . $batch_id . REST_RETRY, array('item_id' => $gone_reverted))->get_data()['queued'] === 0, 'M25: a reverted item is never retried');
$rt = mreq('POST', REST_MIGRATION . $batch_id . REST_RETRY, array('item_id' => $gone_item))->get_data();
assert($rt['queued'] === 1 && (int) Mig::batch($batch_id)['failed_count'] === $fc_before, 'M4/M17: Retry accepts the deleted copy, and failed_count does not drift for a row it never counted');
$gone_row = $wpdb->get_row($wpdb->prepare('SELECT state, error_code, video_id FROM ' . Schema::table('migration_items') . SQL_WHERE_ID, $gone_item), ARRAY_A);
assert($gone_row['state'] === 'pending' && $gone_row['error_code'] === 'platform_deleted' && $gone_row['video_id'] === null, 'M24: the retry names what happened and unlinks the dead copy');
// QA M4/M24: a copy whose row was purged (30 days after its tombstone), and an orphaned one, fail the same way and are re-fetched, not pushed.
$wpdb->insert(Schema::table('videos'), array('media_id' => 'mig-media-orphan', 'status' => 'Ready', 'source' => 'Migrated', 'error_code' => 'orphaned', 'created_at' => current_time('mysql', true), 'updated_at' => current_time('mysql', true)));
$orphan_vid = (int) $wpdb->insert_id;
$lost_items = array();
foreach (array(999982 => 2147483000, 999983 => $orphan_vid) as $att => $vid) {   // no video row has id 2147483000
    $wpdb->insert(Schema::table('migration_items'), array('batch_id' => $batch_id, 'attachment_id' => $att, 'video_id' => $vid, 'state' => 'submitted', 'created_at' => current_time('mysql', true), 'updated_at' => current_time('mysql', true)));
    $lost_items[] = (int) $wpdb->insert_id;
}
assert(mreq('GET', REST_MIGRATION . $batch_id)->get_data()['verification']['failed'] === $failed_count + 2, 'QA M4: a purged video row counts as failed, not as processing forever');
assert(mreq('POST', REST_MIGRATION . $batch_id . REST_RETRY, array('item_id' => $lost_items[0]))->get_data()['queued'] + mreq('POST', REST_MIGRATION . $batch_id . REST_RETRY, array('item_id' => $lost_items[1]))->get_data()['queued'] === 2, 'QA M4: Retry re-queues it');
assert($wpdb->get_col('SELECT DISTINCT error_code FROM ' . Schema::table('migration_items') . SQL_WHERE_ID_IN . implode(',', $lost_items) . ") AND state = 'pending'") === array('platform_deleted'), 'QA M24: purged and orphaned copies are labelled platform_deleted');
$wpdb->query(SQL_DELETE_FROM . Schema::table('migration_items') . SQL_WHERE_ID_IN . implode(',', $lost_items) . ')');
$wpdb->query(SQL_DELETE_FROM . Schema::table('migration_items') . SQL_WHERE_ID_IN . $gone_item . ',' . $gone_reverted . ')');
$wpdb->delete(Schema::table('videos'), array('id' => $gone_vid));
$wpdb->update(Schema::table('migrations'), array('state' => 'done'), array('batch_id' => $batch_id));
as_unschedule_all_actions(Mig::HOOK_ITEM);

// -------------------------------------------- 4. swap at render (before cleanup)

Cache::flush_group('embed');
$rendered = apply_filters('the_content', get_post($post_id)->post_content);
$pb_ok = $wpdb->get_var($wpdb->prepare('SELECT p.playback_id FROM ' . Schema::table('playback_ids') . ' p JOIN ' . Schema::table('videos') . ' v ON v.id = p.video_id WHERE v.attachment_id = %d', $att_ok));
assert(strpos($rendered, ATTR_PLAYBACK_ID . $pb_ok . '"') !== false, 'the [video] shortcode renders the FastPix player at render time [REQ-025, RULE-018]');
$pb_ok2 = $wpdb->get_var($wpdb->prepare('SELECT p.playback_id FROM ' . Schema::table('playback_ids') . ' p JOIN ' . Schema::table('videos') . ' v ON v.id = p.video_id WHERE v.attachment_id = %d', $att_ok2));
assert(strpos($rendered, ATTR_PLAYBACK_ID . $pb_ok2 . '"') !== false, 'so does the wp:video block');
assert(strpos(get_post($post_id)->post_content, 'fastpix') === false, 'the stored post is untouched');

// Usage sweep sees the local references as usage of the migrated videos.
Sync::usage_sweep();
$vid_ok = (int) $wpdb->get_var($wpdb->prepare(SQL_SELECT_ID_FROM . Schema::table('videos') . ' WHERE attachment_id = %d', $att_ok));
assert((int) $wpdb->get_var($wpdb->prepare(SQL_SELECT_COUNT_FROM . Schema::table('usage') . " WHERE video_id = %d AND post_id = %d AND context = 'migrated'", $vid_ok, $post_id)) === 1, 'usage records the migrated reference [REQ-037]');
assert((int) $wpdb->get_var($wpdb->prepare(SQL_SELECT_COUNT_FROM . Schema::table('usage') . ' WHERE video_id = %d AND post_id = %d', $vid_ok, $decoy_id)) === 0, 'QA M21: my-mig-check-ok.mp4 is not a use of mig-check-ok.mp4');

// Revert = restore local playback; the swap steps aside; the copy stays. [REQ-024/027]
$rv = mreq('POST', '/migration/items/' . $item_of[$att_ok] . '/revert');
assert(!$rv->is_error() && $rv->get_data()['reverted'] === true, 'revert per video');
Cache::flush_group('embed');
$rendered = apply_filters('the_content', get_post($post_id)->post_content);
assert(strpos($rendered, ATTR_PLAYBACK_ID . $pb_ok . '"') === false && strpos($rendered, '<video') !== false, 'after revert the local file plays again');
assert(strpos($rendered, ATTR_PLAYBACK_ID . $pb_ok2 . '"') !== false, 'the other video is unaffected — no batch undo');
// M14: a copy FastPix no longer knows (orphaned by the audit) is not swapped in — the local file keeps playing.
$wpdb->update(Schema::table('videos'), array('error_code' => 'orphaned'), array('attachment_id' => $att_ok2));
Mig::reset_map(); Cache::flush_group('embed');
assert(strpos(apply_filters('the_content', get_post($post_id)->post_content), ATTR_PLAYBACK_ID . $pb_ok2 . '"') === false, 'M14: an orphaned copy is never swapped in at render');
$wpdb->update(Schema::table('videos'), array('error_code' => ''), array('attachment_id' => $att_ok2));
Mig::reset_map(); Cache::flush_group('embed');

// -------------------------------------------- 5. cleanup job: verified only

// Cleanup runs its first budget inside the request (QA F5: no dependence on cron) and reports what
// really left the disk. A file wp_delete_file cannot remove keeps its item un-cleaned and the batch open.
// QA F5: an orphaned copy still reads Ready with playback ids — cleanup must never take its local file.
$orphan_vid = (int) $wpdb->get_var($wpdb->prepare(SQL_SELECT_VIDEO_ID_FROM . Schema::table('migration_items') . ' WHERE id = %d', $item_of[$att_ok2]));
$state_was = Mig::batch($batch_id)['state'];
$wpdb->update(Schema::table('videos'), array('error_code' => 'orphaned'), array('id' => $orphan_vid));
$o = \Fastpix\Fastpix_Migration_Scan::finalise_job(array('batch_id' => $batch_id));
assert($o['removed'] === 0 && file_exists(get_attached_file($att_ok2)), 'an orphaned copy\'s local file survives cleanup');
$wpdb->update(Schema::table('videos'), array('error_code' => ''), array('id' => $orphan_vid));
$wpdb->update(Schema::table('migrations'), array('state' => $state_was, 'finished_at' => null), array('batch_id' => $batch_id));
$v = mreq('GET', REST_MIGRATION . $batch_id)->get_data()['verification'];
$expected = 'delete ' . $v['cleanable'] . ' files';
add_filter('wp_delete_file', '__return_empty_string');
$c = mreq('POST', REST_MIGRATION . $batch_id . REST_CLEANUP, array('confirm' => $expected));
remove_filter('wp_delete_file', '__return_empty_string');
assert(!$c->is_error() && $c->get_data()['queued'] === $v['cleanable'], 'typed "' . $expected . '" ⇒ cleanup accepted [REQ-026]');
assert($c->get_data()['removed'] === 0 && $c->get_data()['failed'] === $v['cleanable'], 'a file that survives deletion is reported as failed, not removed');
assert(file_exists(get_attached_file($att_ok2)) && Mig::batch($batch_id)['state'] !== 'cleaned', 'nothing is marked cleaned while the file is still there');
$c = mreq('POST', REST_MIGRATION . $batch_id . REST_CLEANUP, array('confirm' => $expected));
assert(!$c->is_error() && $c->get_data()['removed'] === $v['cleanable'] && $c->get_data()['failed'] === 0, 'the retry removes them and says so');
assert(!file_exists(get_attached_file($att_ok2)), 'a verified item\'s local file is removed');
assert(file_exists(get_attached_file($att_ok)), 'a REVERTED item keeps its file');
if ($failed_count) {
    $failed_att = (int) $wpdb->get_var($wpdb->prepare('SELECT attachment_id FROM ' . Schema::table('migration_items') . " WHERE batch_id = %s AND state = 'failed' LIMIT 1", $batch_id));
    assert(file_exists(get_attached_file($failed_att)), 'a failed item\'s file is never deleted — it is the only copy [ERR-055]');
}
assert(Mig::batch($batch_id)['state'] === 'cleaned', 'batch cleaned');
$rv = mreq('POST', '/migration/items/' . $item_of[$att_ok2] . '/revert');
assert($rv->is_error() && $rv->as_error()->get_error_code() === 'fastpix_revert_impossible', 'revert is impossible once the file is gone [RULE-019]');
$rendered = apply_filters('the_content', get_post($post_id)->post_content);
assert(strpos($rendered, ATTR_PLAYBACK_ID . $pb_ok2 . '"') !== false, 'a cleaned item still swaps at render');
$rt = mreq('POST', REST_MIGRATION . $batch_id . REST_RETRY);
assert($rt->is_error() && $rt->as_error()->get_error_code() === 'fastpix_batch_state', 'M5: a cleaned batch cannot be resurrected by Retry');

// -------------------------------------------- 5b. M3: revert is not forever — the next scan offers the attachment and re-links the live copy
$batch2 = mreq('POST', REST_MIGRATION_SCAN)->get_data()['batch_id'];
Mig::scan_job(array('batch_id' => $batch2, 'offset' => 0));
$by2 = array();
foreach (mreq('GET', REST_MIGRATION_SCAN, array('per_page' => 50))->get_data()['items']['rows'] as $it) { $by2[$it['attachment_id']] = $it; }
assert(isset($by2[$att_ok]) && $by2[$att_ok]['state'] === 'pending', 'M3: a reverted attachment is offered again');
assert(!isset($by2[$att_ok2]), 'a still-migrated (cleaned) attachment is not');
assert(!mreq('POST', REST_MIGRATION_RUN, array('batch_id' => $batch2, 'scope' => 'selection', 'ids' => array($att_ok), 'access_policy' => 'public'))->is_error(), 'run the re-migration');
$item2 = (int) $wpdb->get_var($wpdb->prepare(SQL_SELECT_ID_FROM . Schema::table('migration_items') . ' WHERE batch_id = %s AND attachment_id = %d', $batch2, $att_ok));
$requests = array();
Mig::item_job(array('batch_id' => $batch2, 'item_id' => $item2));
$row2 = $wpdb->get_row($wpdb->prepare('SELECT state, video_id FROM ' . Schema::table('migration_items') . SQL_WHERE_ID, $item2), ARRAY_A);
assert(count($requests) === 0 && $row2['state'] === 'submitted' && (int) $row2['video_id'] === $vid_ok, 'M3: the live copy is re-linked — no second copy on the platform');
assert((int) get_post_meta($att_ok, '_fastpix_video_id', true) === $vid_ok, 'and the origin pointer is back');
as_unschedule_all_actions(Mig::HOOK_ITEM);

// M20: cancelling an un-run scan discards it entirely, and a scan job that outlives the cancel files nothing.
$batch3 = mreq('POST', REST_MIGRATION_SCAN)->get_data()['batch_id'];
assert(!mreq('DELETE', REST_MIGRATION . $batch3)->is_error(), 'cancel the scan');
Mig::scan_job(array('batch_id' => $batch3, 'offset' => 0));
assert(Mig::batch($batch3) === null && (int) $wpdb->get_var($wpdb->prepare(SQL_SELECT_COUNT_FROM . Schema::table('migration_items') . ' WHERE batch_id = %s', $batch3)) === 0, 'M1/M20: a cancelled scan leaves no batch, no items, and the late job cannot revive it');

// QA M20: a cancel landing DURING an item's reachability probe leaves no late item row and no left-out option.
$batch4 = mreq('POST', REST_MIGRATION_SCAN)->get_data()['batch_id'];
$cancel_mid_probe = function ($pre) use ($batch4) { if (Mig::batch($batch4)) { mreq('DELETE', REST_MIGRATION . $batch4); } return $pre; };
add_filter('fastpix_migration_reachable', $cancel_mid_probe, 5);
Mig::scan_job(array('batch_id' => $batch4, 'offset' => 0, 'left_out' => 1));
remove_filter('fastpix_migration_reachable', $cancel_mid_probe, 5);
assert(Mig::batch($batch4) === null && (int) $wpdb->get_var($wpdb->prepare(SQL_SELECT_COUNT_FROM . Schema::table('migration_items') . ' WHERE batch_id = %s', $batch4)) === 0 && get_option('fastpix_migration_left_out_' . $batch4, null) === null, 'QA M20: a cancel during the probe leaves nothing behind');

// History lists the batch; audit recorded start and revert. [SEC-018]
$h = mreq('GET', '/migration/history')->get_data();
assert(count(array_filter($h['batches'], function ($x) use ($batch_id) { return $x['batch_id'] === $batch_id; })) === 1, 'history lists the batch');
// An empty scan that was cancelled (nothing movable) never shows in history.
$empty_id = 'mig_test_empty';
$wpdb->insert(Schema::table('migrations'), array('batch_id' => $empty_id, 'state' => 'cancelled', 'submitted_count' => 0, 'started_by' => 1, 'created_at' => current_time('mysql', true), 'updated_at' => current_time('mysql', true)));
$h = mreq('GET', '/migration/history')->get_data();
assert(count(array_filter($h['batches'], function ($x) use ($empty_id) { return $x['batch_id'] === $empty_id; })) === 0, 'history hides an empty cancelled scan');
$wpdb->delete(Schema::table('migrations'), array('batch_id' => $empty_id));
$audited = $wpdb->get_col('SELECT event FROM ' . Schema::table('audit') . " WHERE event IN ('migration_started','migration_reverted','migration_cleanup') ORDER BY id DESC LIMIT 10");
assert(in_array('migration_started', $audited, true) && in_array('migration_reverted', $audited, true), 'audit log records start and revert');

// -------------------------------------------- 6. legacy fastpix-io import (REQ-104)

Creds::forget();
delete_option(Mig::OPT_LEGACY);
update_option('fastpix_api_key', '51479437-8b69-4cb3-8f9a-b01d0ad41e5c', false);
update_option('fastpix_api_secret', 'legacy-secret-value', false);
update_option('fastpix_videos', array(array('upload_id' => 'u1', 'playback_id' => 'pb-mig-' . $ready_ids[0], 'title' => 'Legacy title', 'status' => 'ready')), false);
$wpdb->update(Schema::table('videos'), array('title' => ''), array('id' => (int) $ready_ids[0]));
Mig::import_legacy();
assert(Creds::has_pair() && Creds::token_id() === '51479437-8b69-4cb3-8f9a-b01d0ad41e5c', 'v1 credentials adopted when the new pair is empty [REQ-104]');
assert($wpdb->get_var($wpdb->prepare('SELECT title FROM ' . Schema::table('videos') . SQL_WHERE_ID, (int) $ready_ids[0])) === 'Legacy title', 'v1 registry titles carried onto the rows');
assert(is_array(get_option(Mig::OPT_LEGACY)), 'runs once');
assert(strpos(do_shortcode('[fastpix playback_id="pb-mig-' . $ready_ids[0] . '"]'), 'playback-id="pb-mig-' . $ready_ids[0] . '"') !== false, 'old [fastpix playback_id] shortcodes render through the new renderer — no post edited');

// ---------------------------------------------------------------- teardown

wp_delete_post($post_id, true);
wp_delete_post($decoy_id, true);
foreach ($fixtures as $id) { $p = get_attached_file($id); wp_delete_attachment($id, true); if ($p && file_exists($p)) { unlink($p); } }
$wpdb->query(SQL_DELETE_FROM . Schema::table('usage') . ' WHERE post_id IN (' . (int) $post_id . ',' . (int) $decoy_id . ')');
$wpdb->query(SQL_DELETE_FROM . Schema::table('playback_ids') . " WHERE playback_id LIKE 'pb-mig-%'");
foreach (array($batch_id, $batch2) as $bid) {
    $wpdb->delete(Schema::table('migration_items'), array('batch_id' => $bid));
    $wpdb->delete(Schema::table('migrations'), array('batch_id' => $bid));
    delete_option('fastpix_migration_left_out_' . $bid);
}
if ($parked) { $wpdb->update(Schema::table('migrations'), array('state' => 'scanned'), array('batch_id' => $parked)); }
$wpdb->query(SQL_DELETE_FROM . Schema::table('videos') . " WHERE media_id LIKE 'mig-media-%'");
$wpdb->query(SQL_DELETE_FROM . Schema::table('logs') . " WHERE error_code IN ('migration_resumed','migration_item_failed','migration_cleaned')");
$wpdb->query(SQL_DELETE_FROM . Schema::table('audit') . " WHERE event LIKE 'migration_%' OR event = 'legacy_import'");
foreach (array(Mig::HOOK_SCAN, Mig::HOOK_ITEM, Mig::HOOK_FINALISE) as $h) { as_unschedule_all_actions($h); }
Cache::flush_group('embed'); Cache::flush_group('videos');
wp_set_current_user(0);

// ------------------------------------ QA F5: a pushed item is linked without webhooks
// A server-side push is made by a background job (user 0); with no webhook reaching the site the sweep
// knows the media (id = upload id) but nothing linked the item, so the batch never verified. Days old, too.
$old = gmdate('Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS);
$wpdb->insert(Schema::table('videos'), array('media_id' => 'up-stranded', 'status' => 'Ready', 'source' => 'Dashboard', 'created_at' => $old, 'updated_at' => $old));
$stranded_vid = (int) $wpdb->insert_id;
$wpdb->insert(Schema::table('uploads'), array('upload_id' => 'up-stranded', 'filename' => 'stranded.mp4', 'filesize' => 10, 'chunk_size' => 10, 'bytes_sent' => 10, 'state' => 'completed', 'user_id' => 0,
    'settings_json' => wp_json_encode(array('access_policy' => 'public', '_migration_attachment' => 999970)), 'created_at' => $old, 'updated_at' => $old));
$stranded_up = (int) $wpdb->insert_id;
$wpdb->insert(Schema::table('migration_items'), array('batch_id' => 'mig_stranded', 'attachment_id' => 999970, 'state' => 'submitted', 'idempotency_key' => 'upload:up-stranded', 'created_at' => $old, 'updated_at' => $old));
$stranded_item = (int) $wpdb->insert_id;
\Fastpix\Fastpix_Uploads_Ingest::bind_unbound(0);
assert((int) $wpdb->get_var($wpdb->prepare(SQL_SELECT_VIDEO_ID_FROM . Schema::table('migration_items') . SQL_WHERE_ID, $stranded_item)) === $stranded_vid, 'QA F5: a 3-day-old pushed item is linked to its video without any webhook');
assert($wpdb->get_var($wpdb->prepare('SELECT source FROM ' . Schema::table('videos') . SQL_WHERE_ID, $stranded_vid)) === 'Migrated', 'and the video is recorded as migrated, not a dashboard upload');
$wpdb->query($wpdb->prepare(SQL_DELETE_FROM . Schema::table('migration_items') . SQL_WHERE_ID, $stranded_item));
$wpdb->query($wpdb->prepare(SQL_DELETE_FROM . Schema::table('uploads') . SQL_WHERE_ID, $stranded_up));
$wpdb->query($wpdb->prepare(SQL_DELETE_FROM . Schema::table('videos') . SQL_WHERE_ID, $stranded_vid));
delete_post_meta(999970, '_fastpix_video_id');

echo "migration: all checks passed\n";
