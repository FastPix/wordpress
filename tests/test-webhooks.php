<?php
/**
 * Self-check for the webhook receiver + handler table — ARCH-05, FR-100,
 * SEC-013, TEST-021.
 *
 *   docker compose exec wordpress php -d zend.assertions=1 \
 *     /var/www/html/wp-content/plugins/fastpix/tests/test-webhooks.php
 */

require_once __DIR__ . '/bootstrap.php';

use Fastpix\Fastpix_Cache as Cache;
use Fastpix\Fastpix_Connection as Connection;
use Fastpix\Fastpix_Credentials as Creds;
use Fastpix\Fastpix_Rate_Limiter as Limiter;
use Fastpix\Fastpix_Schema as Schema;
use Fastpix\Fastpix_Webhooks as Webhooks;

const SQL_DELETE_FROM = 'DELETE FROM ';
const SQL_WHERE_EVENT_ID = ' WHERE event_id = %s';
const SQL_SELECT_ALL_FROM = 'SELECT * FROM ';
const SQL_SELECT_COUNT_FROM = 'SELECT COUNT(*) FROM ';
const SQL_WHERE_MEDIA_ID = ' WHERE media_id = %s';
const FIXTURE_IP = '198.51.100.77';
const SQL_WHERE_MEDIA_WH = " WHERE media_id LIKE 'wh-media-%'";

global $wpdb;

$saved = array();
foreach (array(Creds::OPT_TOKEN_ID, Creds::OPT_SECRET, Webhooks::OPT_SECRET, Webhooks::OPT_SECRET_PREV, Webhooks::OPT_SECRET_AT, Webhooks::OPT_LAST_REJECT, Webhooks::OPT_SAMPLE, Webhooks::OPT_PROVEN_AT, Connection::OPT_WORKSPACE_ID, Connection::OPT_WORKSPACE_SEEN_ID, Connection::OPT_WORKSPACE_SEEN_NAME,
             Connection::OPT_PENDING_LEAVE, Connection::OPT_LEFT_UNKNOWN, Connection::OPT_LAST_TOKEN, Connection::OPT_LAST_KEY) as $opt) {   // the leave machinery must never fire on real data
    $saved[$opt] = get_option($opt, null);
}
// Restore even when a failed assertion aborts the run — a leaked fixture pair once shadowed the live one.
register_shutdown_function(function () use (&$saved) {
    foreach ($saved as $opt => $value) { $value === null ? delete_option($opt) : update_option($opt, $value, false); }
});
foreach (array(Connection::OPT_PENDING_LEAVE, Connection::OPT_LEFT_UNKNOWN, Connection::OPT_LAST_TOKEN, Connection::OPT_LAST_KEY) as $opt) { delete_option($opt); }   // learn_workspace can never wipe

Creds::store('4f27c4c4-9a1e-4c2f-9d33-2b8f0a7c1e55', 'sk_selfcheck_webhooks');
update_option(Connection::OPT_WORKSPACE_ID, 'ws-hook', false);
delete_option(Connection::OPT_WORKSPACE_SEEN_ID); delete_option(Connection::OPT_WORKSPACE_SEEN_NAME);   // learned from the first delivery below
$_SERVER['REMOTE_ADDR'] = FIXTURE_IP;
Limiter::release('webhook_failures', FIXTURE_IP);   // an aborted earlier run may have left the fixture address blocked
$wpdb->query(SQL_DELETE_FROM . Schema::table('webhook_events') . " WHERE event_id LIKE 'evt-check%'");   // …or its fixture events behind
$wpdb->query(SQL_DELETE_FROM . Schema::table('videos') . SQL_WHERE_MEDIA_WH);   // …and its video rows (the status machine is monotonic — a leftover 'Ready' row would refuse 'Created')

// The platform is mocked: processing fetches records during dispatch.
add_filter('pre_http_request', function () {
    return array('headers' => array(), 'body' => wp_json_encode(array('data' => array(
        'id' => 'wh-media-1', 'status' => 'Ready', 'workspaceId' => 'ws-hook',
        'updatedAt' => '2026-08-14T05:00:00Z', 'duration' => 30,
        'playbackIds' => array(array('id' => 'pb-wh-1', 'accessPolicy' => 'public')),
    ))), 'response' => array('code' => 200, 'message' => ''));
}, 10, 0);
add_filter('fastpix_api_backoff_seconds', '__return_zero');

$server = rest_get_server();

function hook_request($raw, $signature) {
    $request = new WP_REST_Request('POST', '/fastpix/v1/webhook');
    $request->set_header('Content-Type', 'application/json');
    if ($signature !== null) {
        $request->set_header('FastPix-Signature', $signature);
    }
    $request->set_body($raw);

    return rest_get_server()->dispatch($request);
}

function sign($raw, $secret) {
    return base64_encode(hash_hmac('sha256', $raw, $secret, true));
}

$secret = 'whsec_selfcheck_9RtP2xW7qL4mZ0vC';

// -------------------------------------------------- secret storage (DATA-016)

Webhooks::set_secret($secret);
update_option(Webhooks::OPT_SECRET_AT, time() - 2, false);   // saved a moment ago, so the deliveries below count
assert(Webhooks::secret() === $secret, 'the secret round-trips');
assert(strpos((string) get_option(Webhooks::OPT_SECRET), $secret) === false, 'and is stored encrypted, not in the clear [DATA-016]');
assert(Webhooks::configured() === true, 'configured once set');

// Rotation keeps a dual-accept window: a delivery signed with the OUTGOING
// secret is still verified for a while after the change, so nothing in flight
// is dropped. [SEC-003, WF-014]
$rotated = 'whsec_selfcheck_rotated_A1b2C3';
Webhooks::set_secret($rotated);   // $secret is now the outgoing secret
$rold = wp_json_encode(array('id' => 'rot-old', 'type' => 'video.media.ready', 'data' => array('id' => 'rot')));
$rnew = wp_json_encode(array('id' => 'rot-new', 'type' => 'video.media.ready', 'data' => array('id' => 'rot')));
assert(!hook_request($rold, sign($rold, $secret))->is_error(), 'a delivery signed with the outgoing secret survives the rotation window');
assert(!hook_request($rnew, sign($rnew, $rotated))->is_error(), 'the new secret verifies');
$rbad = hook_request($rnew, sign($rnew, 'whsec_never_valid'));
assert($rbad->is_error() && $rbad->get_status() === 401, 'an unrelated secret is still refused during the window');
Webhooks::set_secret($secret);    // restore $secret as the current secret for the rest of the checks
update_option(Webhooks::OPT_SECRET_AT, time() - 2, false);   // saved a moment ago, so the deliveries below count
$wpdb->query(SQL_DELETE_FROM . Schema::table('webhook_events') . " WHERE event_id IN ('rot-old','rot-new')");

// The dashboard checks a new endpoint URL with an unsigned `{}` — 2xx, nothing stored. [ASSUME-039]
$response = hook_request('{}', '');
assert($response->get_status() === 200 && $response->get_data()['stored'] === false, 'the unsigned empty probe is acknowledged and stores nothing');
$response = hook_request(wp_json_encode(array('type' => 'video.media.ready', 'data' => array('id' => 'x'))), '');
assert($response->is_error() && $response->get_status() === 401, 'an unsigned real event is still refused');

// ------------------------------------------------------- valid delivery flow

$event = array('id' => 'evt-check-1', 'type' => 'video.media.created', 'workspaceId' => 'ws-hook',
               'data' => array('id' => 'wh-media-1', 'status' => 'Created'));
$raw   = wp_json_encode($event);

$response = hook_request($raw, sign($raw, $secret));
assert($response->get_status() === 200, 'a signed delivery is acknowledged [FR-100]');

$row = $wpdb->get_row($wpdb->prepare(SQL_SELECT_ALL_FROM . Schema::table('webhook_events') . SQL_WHERE_EVENT_ID, 'evt-check-1'), ARRAY_A);
assert($row !== null, 'the event is stored [ARCH-05]');
assert((int) $row['signature_valid'] === 1, 'marked signature-valid');
assert($row['process_state'] === 'pending', 'stored as pending — NOTHING runs inline [FR-100]');
assert($row['object_id'] === 'wh-media-1', 'the object key is extracted');
assert(as_has_scheduled_action('fastpix_process_webhook') !== false, 'processing is enqueued async [ARCH-05]');

// Replay: same event id → acknowledged, not duplicated, not reprocessed.
$before = (int) $wpdb->get_var(SQL_SELECT_COUNT_FROM . Schema::table('webhook_events') . " WHERE event_id = 'evt-check-1'");
$response = hook_request($raw, sign($raw, $secret));
assert($response->get_status() === 200, 'a duplicate is acknowledged [replay defence]');
$after = (int) $wpdb->get_var(SQL_SELECT_COUNT_FROM . Schema::table('webhook_events') . " WHERE event_id = 'evt-check-1'");
assert($after === $before, 'and stored exactly once [DATA-009]');

// ------------------------------------------------------------ bad signatures

assert(Webhooks::verdict() === 'verified', 'a passing FastPix delivery verifies the secret [settings verdict]');
$response = hook_request($raw, 'not-a-signature');
assert($response->get_status() === 401, 'a bad signature is 401 [FR-100]');
assert(Webhooks::verdict() === 'rejected', 'a signed delivery that fails the check marks the secret rejected — the self-test alone never can');
Webhooks::set_secret($secret);
assert(Webhooks::verdict() === 'verified', 'Save & verify: the secret matches FastPix\'s last delivery, so it is verified at once');
delete_option(Webhooks::OPT_SAMPLE);
Webhooks::set_secret($secret);
assert(Webhooks::verdict() === 'pending', 'with no FastPix delivery to check against, it is not verified until one arrives');
update_option(Webhooks::OPT_SECRET_AT, time() - 2, false);   // the save happened a moment ago; the next delivery must count
$response = hook_request(wp_json_encode(array('id' => 'evt-check-2v', 'type' => 'video.media.updated', 'workspaceId' => 'ws-hook', 'data' => array('id' => 'wh-media-1'))), sign(wp_json_encode(array('id' => 'evt-check-2v', 'type' => 'video.media.updated', 'workspaceId' => 'ws-hook', 'data' => array('id' => 'wh-media-1'))), $secret));
assert($response->get_status() === 200 && Webhooks::verdict() === 'verified', 'the next passing delivery verifies it again');
// A delivery signed with the PREVIOUS secret (rotation window) is accepted but proves nothing about the new one. [S6]
$rotated = 'rotated-' . wp_generate_password(16, false, false);
Webhooks::set_secret($rotated);
update_option(Webhooks::OPT_SECRET_AT, time() - 2, false);
$raw_prev = wp_json_encode(array('id' => 'evt-check-3prev', 'type' => 'video.media.updated', 'workspaceId' => 'ws-hook', 'data' => array('id' => 'wh-media-1')));
$response = hook_request($raw_prev, sign($raw_prev, $secret));
assert($response->get_status() === 200 && Webhooks::verdict() === 'pending', 'a delivery signed with the previous secret is accepted but leaves the new secret unverified [S6]');
$raw_new = wp_json_encode(array('id' => 'evt-check-3new', 'type' => 'video.media.updated', 'workspaceId' => 'ws-hook', 'data' => array('id' => 'wh-media-1')));
$response = hook_request($raw_new, sign($raw_new, $rotated));
assert($response->get_status() === 200 && Webhooks::verdict() === 'verified', 'the first delivery signed with the NEW secret verifies it [S6]');
// A base64 secret with its last character edited decodes to the SAME key — refused, nothing stored. [QA F1]
$b64 = base64_encode(str_repeat("\x5a", 32));   // 44 chars, ends "lo=" — "lp=" decodes identically
$twin = substr($b64, 0, -2) . chr(ord($b64[42]) + 1) . '=';
assert(base64_decode($twin, true) === base64_decode($b64, true), 'fixture: the edited secret is the same key');
assert(Webhooks::set_secret($b64) === true, 'a canonical base64 secret is accepted');
Webhooks::set_secret($twin);
assert(Webhooks::secret() === $twin && Webhooks::verdict() === 'rejected', 'a mistyped copy (same key) is saved but reads not verified [QA F1]');
$raw_twin = wp_json_encode(array('id' => 'evt-check-twin', 'type' => 'video.media.updated', 'workspaceId' => 'ws-hook', 'data' => array('id' => 'wh-media-1')));
hook_request($raw_twin, sign($raw_twin, base64_decode($b64)));
assert(Webhooks::verdict() === 'rejected', 'and a FastPix delivery signed with the same key does not flip it to verified [QA F1]');
// Save & verify against FastPix's last delivery (evt-check-twin, signed with $b64's key).
Webhooks::set_secret('whsec_wrong_' . wp_generate_password(12, false, false));
assert(Webhooks::verdict() === 'pending', 'a wrong secret does not match FastPix\'s last delivery: not verified');
$raw_self = wp_json_encode(array('id' => 'test-selfcheck', 'type' => 'fastpix.plugin.test', 'workspaceId' => 'ws-hook', 'data' => array()));
hook_request($raw_self, sign($raw_self, Webhooks::secret()));
Webhooks::set_secret(Webhooks::secret());   // Save & verify again: the check runs against the stored delivery
assert(Webhooks::verdict() === 'pending', 'our own self-test (signed with the stored secret) never becomes the delivery a secret is checked against');
Webhooks::set_secret($b64);
assert(Webhooks::verdict() === 'verified', 'the right secret matches FastPix\'s last delivery: verified at once, no new event needed');

Webhooks::set_secret($secret);
update_option(Webhooks::OPT_SECRET_AT, time() - 2, false);
$response = hook_request($raw, null);
assert($response->get_status() === 401, 'a missing signature is 401');
$logged = (int) $wpdb->get_var(SQL_SELECT_COUNT_FROM . Schema::table('logs') . " WHERE error_code = 'webhook_signature_invalid' AND message LIKE '%198.51.100.77%'");
assert($logged >= 2, 'rejections log the source address [ARCH-05]');

// Ten failures in a minute block the address for 15 minutes. [SEC-013]
for ($i = 0; $i < 10; $i++) {
    hook_request($raw, 'wrong-' . $i);
}
assert(Limiter::is_blocked('webhook_failures', FIXTURE_IP) === true, 'ten failures block the address [SEC-013]');
assert(Limiter::block_remaining('webhook_failures', FIXTURE_IP) > 850, 'for fifteen minutes');
$response = hook_request($raw, sign($raw, $secret));
assert($response->get_status() === 200, 'a VALIDLY signed delivery from a blocked address still gets in — behind a proxy that address is FastPix too (QA S3)');
$response = hook_request($raw, 'wrong-again');
assert($response->get_status() === 429 && Limiter::is_blocked('webhook_failures', FIXTURE_IP), 'while a badly signed one is refused, and the valid delivery did not lift the block (QA S3)');
Limiter::release('webhook_failures', FIXTURE_IP);

// (QA S3) The route's address bucket is spent by unverified requests only: exhausted, a signed delivery still gets in.
$_SERVER['REMOTE_ADDR'] = '198.51.100.78';   // its own address, so the spent bucket cannot reach the checks below
$spent_before = Limiter::count('webhook', 'addr:198.51.100.78');
hook_request($raw, 'wrong-bucket');
$spent = Limiter::count('webhook', 'addr:198.51.100.78') - $spent_before;
for ($i = 0; $i < Limiter::LIMITS['webhook'][0]; $i++) {
    Limiter::check('webhook', 'addr:198.51.100.78');
}
assert($spent === 1 && hook_request($raw, sign($raw, $secret))->get_status() === 200 && hook_request('{}', null)->get_status() === 429, 'QA S3: bad requests spend the address bucket; exhausted, a validly signed delivery is still accepted and an unverified one is 429');
$_SERVER['REMOTE_ADDR'] = FIXTURE_IP;

// ----------------------------------------------------- foreign workspace

$foreign = wp_json_encode(array('id' => 'evt-check-foreign', 'type' => 'video.media.created',
    'workspaceId' => 'ws-someone-else', 'data' => array('id' => 'other-media')));
$response = hook_request($foreign, sign($foreign, $secret));
assert($response->get_status() === 200, 'an authentic foreign-workspace event is acknowledged');
$row = $wpdb->get_row($wpdb->prepare(SQL_SELECT_ALL_FROM . Schema::table('webhook_events') . SQL_WHERE_EVENT_ID, 'evt-check-foreign'), ARRAY_A);
assert($row['process_state'] === 'skipped', 'but stored as skipped, never processed [ARCH-05, ASSUME-022]');

// The saved key is NEVER overwritten from a payload, and no warning is raised (owner ruling 2026-08-19).
assert(get_option(Connection::OPT_WORKSPACE_ID) === 'ws-hook', 'the saved workspace key is untouched');
assert(get_option('fastpix_workspace_mismatch') === false, 'no mismatch option is written');

// ---------------------------------------------------------- unconfigured

Webhooks::set_secret('');
$before   = (int) $wpdb->get_var(SQL_SELECT_COUNT_FROM . Schema::table('webhook_events'));
$response = hook_request($raw, sign($raw, $secret));
assert($response->get_status() === 200 && $response->get_data()['stored'] === false, 'an unconfigured receiver acknowledges (the dashboard needs a 2xx to create the endpoint) but stores nothing');
assert((int) $wpdb->get_var(SQL_SELECT_COUNT_FROM . Schema::table('webhook_events')) === $before, 'nothing stored while unconfigured — polling mode covers sync [ERR-035]');
Webhooks::set_secret($secret);
update_option(Webhooks::OPT_SECRET_AT, time() - 2, false);   // saved a moment ago, so the deliveries below count

// ------------------------------------------------- processing: handler table

Webhooks::process(array('event_id' => 'evt-check-1'));
$row = $wpdb->get_row($wpdb->prepare(SQL_SELECT_ALL_FROM . Schema::table('webhook_events') . SQL_WHERE_EVENT_ID, 'evt-check-1'), ARRAY_A);
assert($row['process_state'] === 'done', 'processing completes the event');
assert($row['processed_at'] !== null, 'and stamps when');

$video = $wpdb->get_row($wpdb->prepare(SQL_SELECT_ALL_FROM . Schema::table('videos') . SQL_WHERE_MEDIA_ID, 'wh-media-1'), ARRAY_A);
assert($video !== null, 'the media event filed the video');
assert($video['status'] === 'Created', 'with the payload status');

// Ready event: state advances AND the full record is fetched. [WF-009]
$ready = array('id' => 'evt-check-2', 'type' => 'video.media.ready', 'workspaceId' => 'ws-hook',
               'data' => array('id' => 'wh-media-1', 'status' => 'Ready'));
$raw_ready = wp_json_encode($ready);
hook_request($raw_ready, sign($raw_ready, $secret));

$fired = array();
add_action('fastpix_media_ready', function ($media_id) use (&$fired) { $fired[] = $media_id; });
Webhooks::process(array('event_id' => 'evt-check-2'));

$video = $wpdb->get_row($wpdb->prepare(SQL_SELECT_ALL_FROM . Schema::table('videos') . SQL_WHERE_MEDIA_ID, 'wh-media-1'), ARRAY_A);
assert($video['status'] === 'Ready', 'ready advances the state');
assert((float) $video['duration_seconds'] === 30.0, 'the fetch pulled the full record [WF-009]');
assert($fired === array('wh-media-1'), 'the enrichment signal fires for Phase 6 [WF-009]');
// M16: an updated event (a title edit, the plugin's own nudge) refreshes the record but never re-fires the ready signal.
$upd = array('id' => 'evt-check-2b', 'type' => 'video.media.updated', 'workspaceId' => 'ws-hook', 'data' => array('id' => 'wh-media-1', 'status' => 'Ready'));
hook_request(wp_json_encode($upd), sign(wp_json_encode($upd), $secret));
Webhooks::process(array('event_id' => 'evt-check-2b'));
assert($fired === array('wh-media-1'), 'M16: video.media.updated does not fire fastpix_media_ready');

// Deleted: tombstone; a replayed created cannot resurrect. [RULE-022]
$deleted = array('id' => 'evt-check-3', 'type' => 'video.media.deleted', 'workspaceId' => 'ws-hook',
                 'data' => array('id' => 'wh-media-1'));
$raw_del = wp_json_encode($deleted);
hook_request($raw_del, sign($raw_del, $secret));
Webhooks::process(array('event_id' => 'evt-check-3'));
$video = $wpdb->get_row($wpdb->prepare(SQL_SELECT_ALL_FROM . Schema::table('videos') . SQL_WHERE_MEDIA_ID, 'wh-media-1'), ARRAY_A);
assert($video['deleted_at'] !== null, 'deleted tombstones the row');
Webhooks::process(array('event_id' => 'evt-check-1'));   // replaying the old created event
assert($wpdb->get_var($wpdb->prepare('SELECT deleted_at FROM ' . Schema::table('videos') . SQL_WHERE_MEDIA_ID, 'wh-media-1')) !== null,
    'a replayed created event cannot resurrect it [RULE-023]');

// Unrecognised event: stored and logged, not discarded. [WF-009]
$odd = array('id' => 'evt-check-4', 'type' => 'video.simulcast.target.updated', 'workspaceId' => 'ws-hook',
             'data' => array('id' => 'sim-1'));
$raw_odd = wp_json_encode($odd);
hook_request($raw_odd, sign($raw_odd, $secret));
Webhooks::process(array('event_id' => 'evt-check-4'));
$row = $wpdb->get_row($wpdb->prepare(SQL_SELECT_ALL_FROM . Schema::table('webhook_events') . SQL_WHERE_EVENT_ID, 'evt-check-4'), ARRAY_A);
assert($row['process_state'] === 'done', 'an unrecognised event completes');
$logged = (int) $wpdb->get_var(SQL_SELECT_COUNT_FROM . Schema::table('logs') . " WHERE error_code = 'webhook_unhandled'");
assert($logged >= 1, 'and is logged, not discarded [WF-009]');

// Live stream events drive the state table.
$live = array('id' => 'evt-check-5', 'type' => 'video.live_stream.active', 'workspaceId' => 'ws-hook',
              'data' => array('id' => 'stream-check-1'));
$raw_live = wp_json_encode($live);
hook_request($raw_live, sign($raw_live, $secret));
Webhooks::process(array('event_id' => 'evt-check-5'));
$stream = $wpdb->get_row($wpdb->prepare(SQL_SELECT_ALL_FROM . Schema::table('live_streams') . ' WHERE stream_id = %s', 'stream-check-1'), ARRAY_A);
assert($stream !== null && $stream['status'] === 'active', 'live events drive idle/preparing/active/ended [WF-009]');

// ---------------------------------------------------------------- teardown

$wpdb->query(SQL_DELETE_FROM . Schema::table('webhook_events') . " WHERE event_id LIKE 'evt-check-%'");
$ids = $wpdb->get_col("SELECT id FROM " . Schema::table('videos') . SQL_WHERE_MEDIA_WH);
if ($ids) {
    $in = implode(',', array_map('intval', $ids));
    $wpdb->query(SQL_DELETE_FROM . Schema::table('playback_ids') . " WHERE video_id IN ({$in})");
    $wpdb->query(SQL_DELETE_FROM . Schema::table('ai') . " WHERE video_id IN ({$in})");
}
$wpdb->query(SQL_DELETE_FROM . Schema::table('videos') . SQL_WHERE_MEDIA_WH);
$wpdb->query(SQL_DELETE_FROM . Schema::table('live_streams') . " WHERE stream_id LIKE 'stream-check-%'");
$wpdb->query(SQL_DELETE_FROM . Schema::table('logs') . " WHERE error_code IN ('webhook_signature_invalid', 'webhook_unhandled', 'rate_limit_block')");
as_unschedule_all_actions('fastpix_process_webhook');
as_unschedule_all_actions('fastpix_search_reindex');
Cache::flush_group('ratelimit');
Cache::flush_group('videos');
foreach ($saved as $opt => $value) {
    if ($value === null) { delete_option($opt); } else { update_option($opt, $value, false); }
}
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

echo "webhook receiver + handlers: all checks passed\n";
