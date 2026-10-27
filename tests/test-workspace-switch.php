<?php
/**
 * Self-check for switching workspaces — ASSUME-092.
 *
 *   docker compose exec wordpress php -d zend.assertions=1 \
 *     /var/www/html/wp-content/plugins/fastpix/tests/test-workspace-switch.php
 *
 * A pair change leaves the previous workspace behind: the list, the Live tab,
 * analytics, the media modal and protected renders all scope to the connected
 * workspace, the platform-derived rollup follows on a key change, and the deep
 * sweep re-stamps a reconnected workspace's rows. Every read goes through the
 * real REST routes as an administrator with the transport mocked.
 *
 * Round 3 (sections i-ix): live rows set aside and restored, paused uploads held
 * then cancelled or kept, the export refusal, player-config 404, a queued event of
 * a workspace since left, fetch_and_apply never orphaning another workspace's row,
 * the left-unknown flag, a disconnect keeping the key, and a page fetched under
 * the old pair being dropped.
 *
 * All fixture rows carry a `wsw-` media/stream/event id prefix and are removed
 * both at the end and from a shutdown function.
 */

require_once __DIR__ . '/bootstrap.php';

use Fastpix\Fastpix_Analytics as Analytics;
use Fastpix\Fastpix_Api_Client as Client;
use Fastpix\Fastpix_Attachments as Attachments;
use Fastpix\Fastpix_Cache as Cache;
use Fastpix\Fastpix_Connection as Connection;
use Fastpix\Fastpix_Credentials as Creds;
use Fastpix\Fastpix_Health as Health;
use Fastpix\Fastpix_Jobs as Jobs;
use Fastpix\Fastpix_Render as Render;
use Fastpix\Fastpix_Schema as Schema;
use Fastpix\Fastpix_Sync as Sync;
use Fastpix\Fastpix_Sync_Sweeps as Sweeps;
use Fastpix\Fastpix_Webhooks as Webhooks;

const KEY_A = '111111111111';
const KEY_B = '222222222222';
const WS_A  = 'wsw-A';
const WS_B  = 'wsw-B';
const SQL_DELETE_FROM = 'DELETE FROM ';
const REST_CONNECTION_WORKSPACE = '/connection/workspace';
const NOT_AVAILABLE = 'not available right now';
const SQL_WHERE_MEDIA_WSW = " WHERE media_id LIKE 'wsw-%'";
const BACKFILL_MARK = '2026-02-02';

global $wpdb;

/* ------------------------------------------------------------- snapshots */

$saved = array();
foreach (array(Creds::OPT_TOKEN_ID, Creds::OPT_SECRET, Connection::OPT_WORKSPACE_ID, Connection::OPT_WORKSPACE_SEEN_ID,
             Connection::OPT_WORKSPACE_SEEN_NAME, Connection::OPT_PENDING_LEAVE, Connection::OPT_LAST_TOKEN, Connection::OPT_LAST_KEY, Connection::OPT_LEFT_UNKNOWN, Connection::OPT_CONNECTED_AT,
             Client::OPT_HEALTH, Health::OPT_SIGNING_KEY, Webhooks::OPT_SECRET, Webhooks::OPT_SECRET_PREV,
             Analytics::OPT_BACKFILL, Analytics::OPT_LAST_SUCCESS, Analytics::OPT_HOURLY, Analytics::OPT_PULL_CURSOR, Analytics::OPT_EXPORTS) as $opt) {
    $saved[$opt] = get_option($opt, null);
}
$kick = get_transient('fastpix_analytics_kick');
// leave_pair() stamps unstamped rows, sets EVERY live row aside ('prev:' prefix), cancels
// open uploads and resets sweep cursors; wipe_rollup() truncates the rollup. Snapshot all
// five so the live site is untouched.
$rollup_rows  = $wpdb->get_results('SELECT * FROM ' . Schema::table('analytics_daily'), ARRAY_A);
$unstamped    = $wpdb->get_col('SELECT media_id FROM ' . Schema::table('videos') . " WHERE workspace_id = ''");
$open_uploads = $wpdb->get_results('SELECT id, state FROM ' . Schema::table('uploads') . " WHERE state IN ('created', 'uploading', 'paused')", ARRAY_A);
$sync_rows    = $wpdb->get_results('SELECT * FROM ' . Schema::table('sync_state') . " WHERE scope IN ('deep_sweep', 'new_media', 'audit')", ARRAY_A);
$live_stamps  = $wpdb->get_results('SELECT stream_id, workspace_id FROM ' . Schema::table('live_streams') . " WHERE stream_id NOT LIKE 'wsw-%'", ARRAY_A);
$export_ids   = array();   // export jobs this run queues, unscheduled at teardown

$cleanup = function () use (&$saved, $kick, $rollup_rows, $unstamped, $open_uploads, $sync_rows, $live_stamps, &$export_ids) {
    global $wpdb;
    foreach ($export_ids as $id) {
        as_unschedule_all_actions('fastpix_analytics_export', array(array('id' => $id)), Jobs::GROUP_ANALYTICS);
    }
    $export_ids = array();
    $wpdb->query(SQL_DELETE_FROM . Schema::table('uploads') . " WHERE upload_id LIKE 'wsw-%'");
    foreach ($live_stamps as $s) { $wpdb->update(Schema::table('live_streams'), array('workspace_id' => $s['workspace_id']), array('stream_id' => $s['stream_id'])); }
    foreach ((array) $wpdb->get_col("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_fastpix_media_id' AND meta_value LIKE 'wsw-%'") as $post_id) {
        wp_delete_post((int) $post_id, true);
    }
    $ids = $wpdb->get_col('SELECT id FROM ' . Schema::table('videos') . SQL_WHERE_MEDIA_WSW);
    if ($ids) {
        $in = implode(',', array_map('intval', $ids));
        foreach (array('playback_ids', 'usage') as $table) {
            $wpdb->query(SQL_DELETE_FROM . Schema::table($table) . " WHERE video_id IN ({$in})");
        }
    }
    $wpdb->query(SQL_DELETE_FROM . Schema::table('videos') . SQL_WHERE_MEDIA_WSW);
    $wpdb->query(SQL_DELETE_FROM . Schema::table('live_streams') . " WHERE stream_id LIKE 'wsw-%'");
    $wpdb->query(SQL_DELETE_FROM . Schema::table('webhook_events') . " WHERE event_id LIKE 'wsw-%'");
    $wpdb->query(SQL_DELETE_FROM . Schema::table('logs') . " WHERE error_code = 'deep_sweep_complete'");
    $wpdb->query(SQL_DELETE_FROM . Schema::table('analytics_daily'));
    foreach ($rollup_rows as $row) { $wpdb->insert(Schema::table('analytics_daily'), $row); }
    foreach ($unstamped as $media_id) { $wpdb->update(Schema::table('videos'), array('workspace_id' => ''), array('media_id' => $media_id)); }
    foreach ($open_uploads as $u) { $wpdb->update(Schema::table('uploads'), array('state' => $u['state']), array('id' => (int) $u['id'])); }
    $wpdb->query(SQL_DELETE_FROM . Schema::table('sync_state') . " WHERE scope IN ('deep_sweep', 'new_media', 'audit')");
    foreach ($sync_rows as $row) { $wpdb->insert(Schema::table('sync_state'), $row); }
    foreach ($saved as $opt => $value) { $value === null ? delete_option($opt) : update_option($opt, $value, false); }
    $kick === false ? delete_transient('fastpix_analytics_kick') : set_transient('fastpix_analytics_kick', $kick, 10 * MINUTE_IN_SECONDS);
    delete_transient(Client::TRANSIENT_BREAKER);
    delete_transient(Client::TRANSIENT_PAUSE);
    foreach (array('videos', 'embed', 'analytics', 'tokens', 'player_config') as $group) { Cache::flush_group($group); }
};
register_shutdown_function($cleanup);   // restore even when a failed assertion aborts the run

/* ------------------------------------------------------------------ mock */

function jresp($code, $body) {
    return array('headers' => array(), 'body' => wp_json_encode($body), 'response' => array('code' => $code, 'message' => ''));
}

$mock = array('streams' => array(), 'page' => array(), 'handler' => null);
add_filter('pre_http_request', function ($_pre, $_args, $url) use (&$mock) {
    if ($mock['handler'] && ($hit = call_user_func($mock['handler'], $url)) !== null) {
        return $hit;   // a section's own per-URL answer (404s, a paged listing)
    }
    $data = array();   // verify-basic and anything else
    if (strpos($url, '/live/streams') !== false) {
        $data = $mock['streams'];   // the Live tab's refresh
    } elseif (strpos($url, '/on-demand') !== false) {
        $data = $mock['page'];      // the deep sweep's list page
    }

    return jresp(200, array('success' => true, 'data' => $data));
}, 10, 3);
add_filter('fastpix_api_backoff_seconds', '__return_zero');

function stream_a() {
    return array('streamId' => 'wsw-s1', 'status' => 'idle', 'enableRecording' => true, 'metadata' => array('name' => 'Town hall A'));
}

/* --------------------------------------------------------------- helpers */

function req($method, $path, $body = null, $query = array()) {
    $request = new WP_REST_Request($method, '/fastpix/v1' . $path);
    if ($query) {
        $request->set_query_params($query);
    }
    if ($body !== null) {
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(wp_json_encode($body));
    }

    return rest_get_server()->dispatch($request);
}

/** The fixture media ids GET /videos lists (sorted), keyed by the wsw- prefix. */
function listed() {
    $response = req('GET', '/videos', null, array('per_page' => 100));
    assert(!$response->is_error(), 'GET /videos answers');
    $ids = array();
    foreach ($response->get_data()['videos'] as $v) {
        if (strpos($v['media_id'], 'wsw-') === 0) {
            $ids[$v['media_id']] = $v['other_workspace'];
        }
    }
    ksort($ids);

    return $ids;
}

/** The fixture stream ids GET /streams lists. */
function streams() {
    $response = req('GET', '/streams');
    assert(!$response->is_error(), 'GET /streams answers');
    $ids = array();
    foreach ($response->get_data()['streams'] as $s) {
        if (strpos($s['stream_id'], 'wsw-') === 0) {
            $ids[] = $s['stream_id'];
        }
    }

    return $ids;
}

function seed_video($media_id, $ws, $status, $policy) {
    global $wpdb;
    $now = current_time('mysql', true);
    $wpdb->insert(Schema::table('videos'), array('media_id' => $media_id, 'workspace_id' => $ws, 'title' => $media_id, 'status' => $status,
        'access_policy' => $policy, 'source' => 'Dashboard', 'platform_updated_at' => '2026-08-01 00:00:00', 'created_at' => $now, 'updated_at' => $now));
    $id = (int) $wpdb->insert_id;
    $wpdb->insert(Schema::table('playback_ids'), array('video_id' => $id, 'playback_id' => 'pb-' . $media_id, 'access_policy' => $policy, 'created_at' => $now, 'updated_at' => $now));

    return $id;
}

function ws_of($media_id) {
    global $wpdb;

    return (string) $wpdb->get_var($wpdb->prepare('SELECT workspace_id FROM ' . Schema::table('videos') . ' WHERE media_id = %s', $media_id));
}

function video_col($media_id, $column) {
    global $wpdb;

    return (string) $wpdb->get_var($wpdb->prepare('SELECT ' . $column . ' FROM ' . Schema::table('videos') . ' WHERE media_id = %s', $media_id));
}

function stream_ws($stream_id) {
    global $wpdb;

    return (string) $wpdb->get_var($wpdb->prepare('SELECT workspace_id FROM ' . Schema::table('live_streams') . ' WHERE stream_id = %s', $stream_id));
}

function upload_state($upload_id) {
    global $wpdb;

    return (string) $wpdb->get_var($wpdb->prepare('SELECT state FROM ' . Schema::table('uploads') . ' WHERE upload_id = %s', $upload_id));
}

/** The upload ids GET /uploads (list_sessions) hands the Add media page. */
function sessions() {
    $response = req('GET', '/uploads');
    assert(!$response->is_error(), 'GET /uploads answers');

    return array_map(function ($s) { return $s['upload_id']; }, $response->get_data()['sessions']);
}

function seed_event($event_id, $ws, $type, $data) {
    global $wpdb;
    $now = current_time('mysql', true);
    $wpdb->insert(Schema::table('webhook_events'), array('event_id' => $event_id, 'event_type' => $type, 'object_type' => 'media', 'object_id' => $data['id'],
        'workspace_id' => $ws, 'payload' => wp_json_encode(array('id' => $event_id, 'type' => $type, 'data' => $data)), 'signature_valid' => 1,
        'received_at' => $now, 'process_state' => 'pending', 'created_at' => $now, 'updated_at' => $now));
}

function event_col($event_id, $column) {
    global $wpdb;

    return (string) $wpdb->get_var($wpdb->prepare('SELECT ' . $column . ' FROM ' . Schema::table('webhook_events') . ' WHERE event_id = %s', $event_id));
}

/* ---------------------------------------------------------- (a) seed: workspace A */

$admins = get_users(array('role' => 'administrator', 'number' => 1, 'fields' => 'ID'));
assert(!empty($admins), 'the test site has an administrator');
wp_set_current_user($admins[0]);
$_SERVER['REMOTE_ADDR'] = '198.51.100.78';

// An aborted earlier run may have left fixtures behind.
$wpdb->query(SQL_DELETE_FROM . Schema::table('videos') . SQL_WHERE_MEDIA_WSW);
$wpdb->query(SQL_DELETE_FROM . Schema::table('live_streams') . " WHERE stream_id LIKE 'wsw-%'");
$wpdb->query(SQL_DELETE_FROM . Schema::table('webhook_events') . " WHERE event_id LIKE 'wsw-%'");

$a_pub  = seed_video('wsw-a-pub', WS_A, 'Ready', 'public');
$a_priv = seed_video('wsw-a-priv', WS_A, 'Ready', 'private');
$a_proc = seed_video('wsw-a-proc', WS_A, 'Processing', 'public');
seed_video('wsw-unstamped', '', 'Ready', 'public');
$now = current_time('mysql', true);
$wpdb->insert(Schema::table('live_streams'), array('stream_id' => 'wsw-s1', 'workspace_id' => KEY_A, 'name' => 'Town hall A', 'status' => 'idle', 'created_at' => $now, 'updated_at' => $now));

Creds::store('wsw-token-A', 'wsw-secret-A');
update_option(Connection::OPT_WORKSPACE_ID, KEY_A, false);
update_option(Connection::OPT_WORKSPACE_SEEN_ID, WS_A, false);
delete_option(Connection::OPT_PENDING_LEAVE);
delete_option(Connection::OPT_LAST_TOKEN);
delete_option(Client::OPT_HEALTH);
Cache::flush_group('videos');

$mock['streams'] = array(stream_a());
assert(array_keys(listed()) === array('wsw-a-priv', 'wsw-a-proc', 'wsw-a-pub', 'wsw-unstamped'), 'workspace A lists its three rows plus the unstamped one');
assert(streams() === array('wsw-s1'), 'the Live tab lists A\'s stream');

/* ------------------------------------------- (b) a new pair, then an EMPTY workspace B */

$state = Connection::connect('wsw-token-B', 'wsw-secret-B');
assert(!is_wp_error($state) && $state['connected'] === true, 'a different pair connects');
assert(ws_of('wsw-unstamped') === WS_A, 'the unstamped row is stamped with the outgoing UUID');
assert(get_option(Connection::OPT_WORKSPACE_SEEN_ID, '') === '', 'the learned UUID is forgotten');
assert(get_option(Connection::OPT_PENDING_LEAVE) === WS_A, 'the leave is pending until the new UUID is learned');

/* --------------------------------- (c) a late webhook from A arrives while pending */

assert(Connection::learn_workspace(WS_A, false) === false, 'a late delivery naming the pending UUID cannot re-teach it');
assert(get_option(Connection::OPT_WORKSPACE_SEEN_ID, '') === '', 'seen stays empty after the refused delivery');
// The same delivery through the real receiver: signed, live shape (workspace {id, name}).
Webhooks::set_secret('whsec_wsw_selfcheck');
$raw = wp_json_encode(array('id' => 'wsw-evt-1', 'type' => 'video.media.updated', 'workspace' => array('id' => WS_A, 'name' => 'Old workspace'),
    'data' => array('id' => 'wsw-a-pub', 'status' => 'Ready')));
$request  = new WP_REST_Request('POST', '/fastpix/v1/webhook');
$request->set_header('Content-Type', 'application/json');
$request->set_header('FastPix-Signature', base64_encode(hash_hmac('sha256', $raw, 'whsec_wsw_selfcheck', true)));
$request->set_body($raw);
$response = rest_get_server()->dispatch($request);
assert($response->get_status() === 200, 'the late delivery is acknowledged');
assert(get_option(Connection::OPT_WORKSPACE_SEEN_ID, '') === '', 'and teaches nothing through the receiver either');
assert(get_option(Connection::OPT_WORKSPACE_SEEN_NAME, '') === '', 'not even the name');

/* ------------------------------------------------- (b, continued) the key changes to B */

update_option(Analytics::OPT_BACKFILL, '2026-01-01', false);
$response = req('POST', REST_CONNECTION_WORKSPACE, array('workspace_id' => KEY_B));
assert(!$response->is_error() && $response->get_data()['workspace_id'] === KEY_B, 'the new key saves through the route');
assert(get_option(Connection::OPT_WORKSPACE_SEEN_ID, '') === '', 'seen is still empty — an empty workspace teaches nothing');
assert(get_option(Connection::OPT_PENDING_LEAVE, '') === '', 'a different key settles the leave outright');
assert((int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Schema::table('analytics_daily')) === 0, 'the platform-derived rollup is dropped');
assert(get_option(Analytics::OPT_BACKFILL, '') === '', 'and its backfill mark with it');

$mock['streams'] = array();
assert(listed() === array(), 'workspace B lists nothing — not A\'s rows, not the row A left unstamped');
assert(streams() === array(), 'the Live tab lists nothing for B');

set_transient('fastpix_analytics_kick', 1, 10 * MINUTE_IN_SECONDS);   // an empty rollup would otherwise queue a pull job
$response = req('GET', '/analytics/site');
assert(!$response->is_error() && $response->get_data()['ready_videos'] === 0, 'the analytics no-data helper counts none of A\'s ready videos');

$response = req('PATCH', '/videos/' . $a_pub, array('title' => 'renamed from B'));
assert($response->is_error() && $response->as_error()->get_error_code() === 'fastpix_other_workspace' && $response->get_status() === 409, 'a write on A\'s row is refused with 409 fastpix_other_workspace');
assert($wpdb->get_var($wpdb->prepare('SELECT title FROM ' . Schema::table('videos') . ' WHERE id = %d', $a_pub)) === 'wsw-a-pub', 'and nothing changed');

$response = req('GET', '/videos/' . $a_pub . '/analytics');
assert($response->is_error() && $response->get_status() === 404, 'per-video analytics of A\'s row is a 404');

$pub_proxy  = Attachments::create_proxy($a_pub);
$priv_proxy = Attachments::create_proxy($a_priv);
assert($pub_proxy > 0 && $priv_proxy > 0, 'proxies exist for A\'s rows');
$args = Attachments::query_args(array());
assert(in_array($pub_proxy, $args['post__not_in'], true) && in_array($priv_proxy, $args['post__not_in'], true), 'the media modal excludes A\'s proxies');

$html = Render::render('wsw-a-priv', array(), array('context' => 'shortcode'));
assert(strpos($html, NOT_AVAILABLE) !== false, 'A\'s private row renders the poster message');
assert(strpos($html, '<fastpix-player') === false && strpos($html, ' token=') === false, 'no player, no token minted with the wrong pair');
$html = Render::render('wsw-a-pub', array(), array('context' => 'shortcode'));
assert(strpos($html, '<fastpix-player') !== false && strpos($html, 'playback-id="pb-wsw-a-pub"') !== false, 'A\'s public row still plays — posts keep their embeds');

/* ------------------------------------------------- (d) B\'s first media record */

$verdict = Sync::apply_media(array('id' => 'wsw-b1', 'workspaceId' => WS_B, 'status' => 'Ready', 'title' => 'First in B',
    'updatedAt' => '2026-08-02T00:00:00Z', 'playbackIds' => array(array('id' => 'pb-wsw-b1', 'accessPolicy' => 'public'))));
assert($verdict === 'created', 'the first record read with the new pair is created');
assert(get_option(Connection::OPT_WORKSPACE_SEEN_ID) === WS_B, 'and teaches B\'s UUID');
assert(listed() === array('wsw-b1' => false), 'the B row is listed as this workspace\'s own');

/* -------------------------------------------------- (e) rotation: new token, same key */

update_option(Analytics::OPT_BACKFILL, BACKFILL_MARK, false);
$state = Connection::connect('wsw-token-B2', 'wsw-secret-B2');
assert(!is_wp_error($state), 'the rotated token connects');
assert(get_option(Connection::OPT_WORKSPACE_SEEN_ID, '') === '' && get_option(Connection::OPT_PENDING_LEAVE) === WS_B, 'seen forgotten, B pending');
assert(get_option(Analytics::OPT_BACKFILL) === BACKFILL_MARK, 'the rollup survives a pair change alone');
assert(listed() === array(), 'until the UUID is learned again, nothing stamped is listed');
assert(Connection::learn_workspace(WS_B, true) === true, 'the same UUID settles the leave');
assert(get_option(Connection::OPT_PENDING_LEAVE, '') === '' && get_option(Analytics::OPT_BACKFILL) === BACKFILL_MARK, 'without a wipe');
assert(listed() === array('wsw-b1' => false), 'the B row is listed again');

/* -------------------------------------------------------- (f) switch back to A */

$state = Connection::connect('wsw-token-A', 'wsw-secret-A');
assert(!is_wp_error($state) && get_option(Connection::OPT_PENDING_LEAVE) === WS_B, 'leaving B is pending');
$response = req('POST', REST_CONNECTION_WORKSPACE, array('workspace_id' => KEY_A));
assert(!$response->is_error() && get_option(Connection::OPT_PENDING_LEAVE, '') === '', 'A\'s key settles it (B\'s rollup dropped)');
$verdict = Sync::apply_media(array('id' => 'wsw-a-pub', 'workspaceId' => WS_A, 'status' => 'Ready', 'updatedAt' => '2026-08-01T00:00:00Z'));
assert($verdict === 'unchanged', 'the A record is unchanged on the platform');
assert(ws_of('wsw-a-pub') === WS_A && get_option(Connection::OPT_WORKSPACE_SEEN_ID) === WS_A, 'but it teaches A\'s UUID again');
$listed = listed();
assert(array_keys($listed) === array('wsw-a-priv', 'wsw-a-proc', 'wsw-a-pub', 'wsw-unstamped'), 'A\'s rows are back, the private one included');
assert(!in_array(true, $listed, true), 'none flagged as another workspace\'s');
$mock['streams'] = array(stream_a());
assert(streams() === array('wsw-s1'), 'A\'s stream is back on the Live tab');
$args = Attachments::query_args(array());
assert(!in_array($pub_proxy, (array) ($args['post__not_in'] ?? array()), true), 'A\'s proxies are back in the media modal');

/* ---------------------------------------- (g) disconnect, then connect a pair C */

Connection::disconnect();
assert(Creds::has_pair() === false && get_option(Connection::OPT_LAST_TOKEN) === 'wsw-token-A', 'a disconnect keeps the dropped token aside');
$state = Connection::connect('wsw-token-C', 'wsw-secret-C');
assert(!is_wp_error($state), 'pair C connects');
assert(get_option(Connection::OPT_PENDING_LEAVE) === WS_A && get_option(Connection::OPT_WORKSPACE_SEEN_ID, '') === '', 'the leave still happens across a disconnect');
assert(listed() === array(), 'A\'s rows (and B\'s) are hidden from C');

/* ------------------------------------- (h) the deep sweep\'s re-stamp gate */

// C turns out to be A's rotated pair: the platform lists A's media, unchanged since
// the stored platform_updated_at. Without the gate nothing would be re-applied and
// the rows would stay hidden; with it, apply_media runs and A's UUID is learned.
$mock['page'] = array(array('id' => 'wsw-a-pub', 'workspaceId' => WS_A, 'status' => 'Ready', 'updatedAt' => '2026-08-01T00:00:00Z'));
Sync::deep_sweep();
assert(get_option(Connection::OPT_WORKSPACE_SEEN_ID) === WS_A, 'the unchanged record is re-applied because its stamp is not the connected UUID');
assert(get_option(Connection::OPT_PENDING_LEAVE, '') === '', 'the same UUID settles the pending leave');
$sweep = Sync::sync_state('deep_sweep');
assert((int) $sweep['items_seen'] === 1 && (int) $sweep['items_changed'] === 0, 'one record seen, none changed — the re-apply was the stamp check alone');
assert(array_keys(listed()) === array('wsw-a-priv', 'wsw-a-proc', 'wsw-a-pub', 'wsw-unstamped'), 'and A\'s rows return in one pass');

/* ------------------------------------ (i) live rows are set aside at a pair change */

// The connect of pair C set A's stream aside; C proved to be A's rotated pair, so the
// Live tab's refresh (the platform still lists the stream) restores its key.
assert(stream_ws('wsw-s1') === 'prev:' . KEY_A, 'the connect of pair C set the live row aside');
$state = Connection::set_workspace_id(KEY_A);   // (g) dropped the key with the pair; the same key under C wipes nothing
assert(!is_wp_error($state) && get_option(Connection::OPT_LAST_KEY, null) === null, 'the key is saved again and the kept one dropped');
$mock['streams'] = array(stream_a());
assert(streams() === array('wsw-s1') && stream_ws('wsw-s1') === KEY_A, 'the refresh restores the key of a stream the rotated pair still lists');

$wpdb->insert(Schema::table('uploads'), array('upload_id' => 'wsw-up-1', 'signed_url' => 'https://example.invalid/wsw', 'filename' => 'wsw.mp4', 'filesize' => 1000,
    'bytes_sent' => 500, 'state' => 'paused', 'user_id' => $admins[0], 'created_at' => $now, 'updated_at' => $now));

$state = Connection::connect('wsw-token-D', 'wsw-secret-D');
assert(!is_wp_error($state) && get_option(Connection::OPT_PENDING_LEAVE) === WS_A, 'pair D connects, leaving A pending');
assert(stream_ws('wsw-s1') === 'prev:' . KEY_A, 'every pre-existing live row is set aside under prev:<old key>');
$mock['streams'] = array();
assert(streams() === array() && stream_ws('wsw-s1') === 'prev:' . KEY_A, 'a refresh that lists nothing leaves it aside — GET /streams stays empty');
$mock['streams'] = array(stream_a());
assert(streams() === array('wsw-s1') && stream_ws('wsw-s1') === KEY_A, 'a refresh that lists it restores the key (rotation) and GET /streams lists it again');

/* ------------------------------------ (ii, iii) uploads and the export while pending */

assert(sessions() === array() && upload_state('wsw-up-1') === 'paused', 'nothing resumes while a leave is pending, but the row is kept');
$response = req('POST', '/analytics/export', array('range' => '30'));
assert($response->is_error() && $response->get_status() === 409 && $response->as_error()->get_error_code() === 'fastpix_workspace_pending', 'the export refuses 409 fastpix_workspace_pending while pending');

update_option(Analytics::OPT_BACKFILL, '2026-03-03', false);
assert(Connection::learn_workspace(WS_B, true) === true, 'the API names a DIFFERENT workspace');
assert(get_option(Connection::OPT_PENDING_LEAVE, '') === '' && get_option(Analytics::OPT_BACKFILL, '') === '', 'the leave settles with a wipe');
assert(upload_state('wsw-up-1') === 'cancelled', 'and the open upload is cancelled — its signed URL belongs to the old workspace');
$response = req('POST', '/analytics/export', array('range' => '30'));
assert($response->get_status() !== 409, 'the export is no longer refused for a pending leave');
if (!$response->is_error()) {
    $export_ids[] = $response->get_data()['id'];
}

// Rotation: the same UUID keeps the paused upload, and it resumes once settled.
$wpdb->update(Schema::table('uploads'), array('state' => 'paused'), array('upload_id' => 'wsw-up-1'));
$state = Connection::connect('wsw-token-E', 'wsw-secret-E');
assert(!is_wp_error($state) && get_option(Connection::OPT_PENDING_LEAVE) === WS_B, 'pair E connects, leaving B pending');
assert(sessions() === array() && upload_state('wsw-up-1') === 'paused', 'held, not cancelled, while pending');
assert(Connection::learn_workspace(WS_B, true) === true && upload_state('wsw-up-1') === 'paused', 'the same UUID settles the leave and the upload is untouched');
assert(sessions() === array('wsw-up-1'), 'and it resumes');

/* --------------------------------------- (iv) player-config for another workspace */

$response = req('GET', '/player-config/pb-wsw-a-priv');
assert($response->is_error() && $response->get_status() === 404 && $response->as_error()->get_error_code() === 'fastpix_video_missing', 'A\'s private row is 404 fastpix_video_missing while B is connected');

/* ---------------------------------- (v) a queued event of a workspace since left */

$mock['page'] = array();
seed_event('wsw-evt-a', WS_A, 'video.media.created', array('id' => 'wsw-a-pub', 'status' => 'Ready', 'updatedAt' => '2026-08-05T00:00:00Z'));
seed_event('wsw-evt-b', WS_B, 'video.media.created', array('id' => 'wsw-b1', 'status' => 'Ready', 'updatedAt' => '2026-08-03T00:00:00Z'));
Webhooks::process(array('event_id' => 'wsw-evt-a'));
assert(event_col('wsw-evt-a', 'process_state') === 'skipped' && event_col('wsw-evt-a', 'process_error') === 'workspace left', 'the queued A event is skipped');
assert(video_col('wsw-a-pub', 'platform_updated_at') === '2026-08-01 00:00:00' && ws_of('wsw-a-pub') === WS_A, 'and A\'s row is unchanged');
Webhooks::process(array('event_id' => 'wsw-evt-b'));
assert(event_col('wsw-evt-b', 'process_state') === 'done', 'the B event is processed normally');
assert(video_col('wsw-b1', 'platform_updated_at') === '2026-08-03 00:00:00', 'and applied to B\'s row');

/* -------------------------------------------- (vi) fetch_and_apply never orphans A */

$mock['handler'] = function ($url) {
    return strpos($url, '/on-demand/wsw-') !== false ? jresp(404, array('success' => false)) : null;
};
seed_video('wsw-unstamped-2', '', 'Ready', 'public');
assert(video_col('wsw-a-pub', 'error_code') === '', 'A\'s row starts without an error');
assert(Sync::fetch_and_apply('wsw-a-pub') === 'error' && video_col('wsw-a-pub', 'error_code') === '', 'a 404 for another workspace\'s media is an error, not an orphan');
assert(Sync::fetch_and_apply('wsw-b1') === 'orphaned' && video_col('wsw-b1', 'error_code') === 'orphaned', 'the same 404 for the connected workspace\'s media orphans it');
assert(Sync::fetch_and_apply('wsw-unstamped-2') === 'orphaned' && video_col('wsw-unstamped-2', 'error_code') === 'orphaned', 'and an unstamped media too');
$mock['handler'] = null;

/* ----------------------------------------------- (vii) the workspace left was never named */

$state = Connection::connect('wsw-token-F', 'wsw-secret-F');
assert(!is_wp_error($state) && get_option(Connection::OPT_WORKSPACE_SEEN_ID, '') === '', 'pair F connects and nothing ever names its workspace');
$state = Connection::connect('wsw-token-G', 'wsw-secret-G');
assert(!is_wp_error($state) && (int) get_option(Connection::OPT_LEFT_UNKNOWN, 0) === 1, 'leaving F flags the workspace left as unknown');
assert(get_option(Connection::OPT_PENDING_LEAVE) === WS_B, 'the older pending UUID is kept');
assert(Connection::learn_workspace('wsw-Z', false) === false && get_option(Connection::OPT_WORKSPACE_SEEN_ID, '') === '', 'no delivery may teach');
assert(Connection::learn_workspace('wsw-Z', true) === true && get_option(Connection::OPT_WORKSPACE_SEEN_ID) === 'wsw-Z', 'the API sweep may');
assert(get_option(Connection::OPT_LEFT_UNKNOWN, null) === null, 'and the flag is gone');

/* -------------------------------------------- (iv, continued) A reconnected */

$state = Connection::connect('wsw-token-A', 'wsw-secret-A');
assert(!is_wp_error($state) && Connection::learn_workspace(WS_A, true) === true, 'A is back');
$response = req('GET', '/player-config/pb-wsw-a-priv');
assert(!$response->is_error() || $response->as_error()->get_error_code() !== 'fastpix_video_missing', 'A\'s private row is no longer missing to player-config');

/* ------------------------------------------- (viii) a disconnect keeps the key */

update_option(Analytics::OPT_BACKFILL, '2026-04-04', false);
Connection::disconnect();
assert(get_option(Connection::OPT_LAST_KEY) === KEY_A && get_option(Connection::OPT_WORKSPACE_ID, '') === '', 'a disconnect keeps the dropped key aside');
$state = Connection::connect('wsw-token-H', 'wsw-secret-H');
assert(!is_wp_error($state) && get_option(Connection::OPT_PENDING_LEAVE) === WS_A && get_option(Analytics::OPT_BACKFILL) === '2026-04-04', 'pair H connects, A pending, rollup kept');
$state = Connection::set_workspace_id(KEY_B);
assert(!is_wp_error($state) && $state['workspace_id'] === KEY_B, 'a new key saves');
assert(get_option(Connection::OPT_PENDING_LEAVE, '') === '' && get_option(Analytics::OPT_BACKFILL, '') === '', 'a key differing from the kept one settles the leave with a wipe');
assert(get_option(Connection::OPT_LAST_KEY, null) === null, 'the kept key is dropped once used');

/* --------------------------------------- (ix) a page fetched under the old pair */

$hits = array();
$mock['handler'] = function ($url) use (&$hits) {
    if (strpos($url, '/on-demand') === false) {
        return null;
    }
    parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
    $hits[] = (int) $q['offset'];
    if ((int) $q['offset'] === 1) {
        Creds::store('wsw-token-X', 'wsw-secret-X');   // the pair changes while page 1 is in flight
    }
    $media = array('id' => 'wsw-p' . (int) $q['offset'], 'workspaceId' => 'wsw-X', 'status' => 'Ready', 'updatedAt' => '2026-08-06T00:00:00Z');

    return jresp(200, array('success' => true, 'data' => array($media), 'pagination' => array('offsetCount' => 2)));
};
Sweeps::new_media_sweep();
assert($hits === array(1), 'page 1 was served and page 2 never asked for');
assert((int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Schema::table('videos') . " WHERE media_id IN ('wsw-p1', 'wsw-p2')") === 0, 'neither page\'s media was filed');
assert(get_option(Connection::OPT_WORKSPACE_SEEN_ID, '') === '', 'and the old pair\'s page taught nothing');
$mock['handler'] = null;

/* ---------------------------------------------------------------- teardown */

$cleanup();
foreach (array('fastpix_new_media_sweep', 'fastpix_process_webhook') as $hook) {   // each connect queued a first sweep; the receiver queued the event
    as_unschedule_all_actions($hook);
}
wp_set_current_user(0);
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

echo "workspace switch: all checks passed\n";
