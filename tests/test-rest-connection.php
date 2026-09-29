<?php
/**
 * Self-check for the connection routes — API-P09/P10, TEST-001 route half.
 *
 *   docker compose exec wordpress php -d zend.assertions=1 \
 *     /var/www/html/wp-content/plugins/fastpix/tests/test-rest-connection.php
 *
 * Dispatches through the real REST server with the transport mocked at
 * pre_http_request. The full plugin is loaded by WordPress itself, so the
 * routes under test are the ones production registers.
 */

require_once __DIR__ . '/bootstrap.php';

use Fastpix\Fastpix_Api_Client as Client;
use Fastpix\Fastpix_Connection as Connection;
use Fastpix\Fastpix_Credentials as Creds;
use Fastpix\Fastpix_Schema as Schema;

const REST_CONNECTION = '/connection';
const REST_CONNECTION_TEST = '/connection/test';
const REST_CONNECTION_WORKSPACE = '/connection/workspace';
const SQL_DELETE_FROM = 'DELETE FROM ';

$saved = array();
foreach (array(Creds::OPT_TOKEN_ID, Creds::OPT_SECRET, Connection::OPT_WORKSPACE_ID, Client::OPT_HEALTH, 'fastpix_workspace_seen_id', 'fastpix_workspace_seen_name', Connection::OPT_PENDING_LEAVE, Connection::OPT_LAST_TOKEN, Connection::OPT_LAST_KEY, Connection::OPT_LEFT_UNKNOWN, 'fastpix_analytics_backfill',   // the learned workspace must survive fixtures
             Connection::OPT_CONNECTED_AT, \Fastpix\Fastpix_Health::OPT_SIGNING_KEY,   // stamped/rotated by connect and disconnect
             \Fastpix\Fastpix_Analytics::OPT_LAST_SUCCESS, \Fastpix\Fastpix_Analytics::OPT_HOURLY, \Fastpix\Fastpix_Analytics::OPT_PULL_CURSOR) as $opt) {   // wipe_rollup() clears these with the backfill mark
    $saved[$opt] = get_option($opt, null);
}
// Restore even when a failed assertion aborts the run — a leaked fixture pair once shadowed the live one.
register_shutdown_function(function () use (&$saved) {
    foreach ($saved as $opt => $value) { $value === null ? delete_option($opt) : update_option($opt, $value, false); }
});

delete_option(Connection::OPT_WORKSPACE_ID);   // start from no workspace; teardown restores the snapshot
delete_option(Client::OPT_HEALTH);   // and from a healthy client: a real 401 on this site (any page that calls FastPix) would otherwise fail the S1 assert below

// A pair change makes connect() leave the previous workspace: it stamps unstamped
// video rows and drops the platform-derived rollup. Snapshot both tables so the
// fixture pairs below never touch the live site's data.
global $wpdb;
$rollup_copy = Schema::table('analytics_daily') . '_selfcheck';   // a table copy, not PHP memory: the rollup can be large
$wpdb->query("DROP TABLE IF EXISTS {$rollup_copy}");
$wpdb->query("CREATE TABLE {$rollup_copy} LIKE " . Schema::table('analytics_daily'));
$wpdb->query("INSERT INTO {$rollup_copy} SELECT * FROM " . Schema::table('analytics_daily'));
$unstamped   = $wpdb->get_col('SELECT media_id FROM ' . Schema::table('videos') . " WHERE workspace_id = ''");
$open_uploads = $wpdb->get_results('SELECT id, state FROM ' . Schema::table('uploads') . " WHERE state IN ('created', 'uploading', 'paused')", ARRAY_A);
// leave_pair() also sets EVERY live row aside ('prev:' prefix) and resets the sweep cursors.
$live_stamps = $wpdb->get_results('SELECT stream_id, workspace_id FROM ' . Schema::table('live_streams'), ARRAY_A);
$sync_rows   = $wpdb->get_results('SELECT * FROM ' . Schema::table('sync_state'), ARRAY_A);
register_shutdown_function(function () use ($rollup_copy, $unstamped, $open_uploads, $live_stamps, $sync_rows) {
    global $wpdb;
    $wpdb->query(SQL_DELETE_FROM . Schema::table('analytics_daily'));
    $wpdb->query('INSERT INTO ' . Schema::table('analytics_daily') . " SELECT * FROM {$rollup_copy}");
    $wpdb->query("DROP TABLE IF EXISTS {$rollup_copy}");
    foreach ($unstamped as $media_id) { $wpdb->update(Schema::table('videos'), array('workspace_id' => ''), array('media_id' => $media_id)); }
    foreach ($open_uploads as $u) { $wpdb->update(Schema::table('uploads'), array('state' => $u['state']), array('id' => (int) $u['id'])); }
    foreach ($live_stamps as $s) { $wpdb->update(Schema::table('live_streams'), array('workspace_id' => $s['workspace_id']), array('stream_id' => $s['stream_id'])); }
    $wpdb->query(SQL_DELETE_FROM . Schema::table('sync_state'));
    foreach ($sync_rows as $row) { $wpdb->insert(Schema::table('sync_state'), $row); }
    $wpdb->query(SQL_DELETE_FROM . Schema::table('videos') . " WHERE media_id LIKE 'conn-t%'");
});
// No workspace is known at the start, so the fixture connects below leave nothing behind.
delete_option('fastpix_workspace_seen_id');
delete_option(Connection::OPT_PENDING_LEAVE);
delete_option(Connection::OPT_LAST_TOKEN);
delete_option(Connection::OPT_LAST_KEY);
delete_option(Connection::OPT_LEFT_UNKNOWN);

$mock = array('default' => null, 'requests' => 0);
add_filter('pre_http_request', function () use (&$mock) {
    $mock['requests']++;

    return $mock['default'];
}, 10, 0);
add_filter('fastpix_api_backoff_seconds', '__return_zero');

function jresp($code, $body) {
    return array('headers' => array(), 'body' => wp_json_encode($body), 'response' => array('code' => $code, 'message' => ''));
}

$server = rest_get_server();
$pair   = array('4f27c4c4-9a1e-4c2f-9d33-2b8f0a7c1e55', 'test-secret-not-a-real-key');
$admins = get_users(array('role' => 'administrator', 'number' => 1, 'fields' => 'ID'));
assert(!empty($admins), 'the test site has an administrator');

function req($method, $path, $body = null) {
    $request = new WP_REST_Request($method, '/fastpix/v1' . $path);
    if ($body !== null) {
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(wp_json_encode($body));
    }

    return rest_get_server()->dispatch($request);
}

// ------------------------------------------------- routes exist, and are guarded

$routes = $server->get_routes();
foreach (array('/fastpix/v1/connection', '/fastpix/v1/connection/test', '/fastpix/v1/logs', '/fastpix/v1/system-report') as $route) {
    assert(isset($routes[$route]), "{$route} is registered [API-P09/P10]");
}

Creds::forget();
wp_set_current_user(0);
foreach (array(array('POST', REST_CONNECTION, array('token_id' => 'x', 'secret' => 'y')),
             array('DELETE', REST_CONNECTION, null),
             array('POST', REST_CONNECTION_TEST, null),
             array('GET', '/logs', null),
             array('GET', '/system-report', null)) as $case) {
    $response = req($case[0], $case[1], $case[2]);
    assert($response->is_error(), "{$case[0]} {$case[1]} refuses an anonymous caller [SEC-011]");
}
assert(Creds::has_pair() === false, 'the refused connect stored nothing');

// ------------------------------------------------- connect: failure through the route

wp_set_current_user($admins[0]);

$mock['default'] = jresp(401, array('success' => false, 'error' => array('code' => 401, 'message' => 'Unauthorized', 'description' => 'bad pair')));
$response = req('POST', REST_CONNECTION, array('token_id' => $pair[0], 'secret' => $pair[1]));

assert($response->is_error(), 'a rejected pair errors through the route');
$data = $response->as_error()->get_error_data();
assert($response->as_error()->get_error_code() === 'fastpix_bad_credentials', 'ERR-001 code surfaces');
assert(!isset($data['field']), 'no field is singled out — the platform returns the same 401 for either half [ASSUME-071]');
assert($data['status'] === 400, 'a connect failure is a 400, not a 500');
assert(Creds::has_pair() === false, 'nothing stored on failure [REQ-002]');
assert(Client::is_healthy(), 'a rejected CANDIDATE pair never marks the connection unhealthy (bug S1)');

// Missing params are refused by schema validation before any transport happens.
$mock['requests'] = 0;
$response = req('POST', REST_CONNECTION, array('token_id' => $pair[0]));
assert($response->is_error() && $response->get_status() === 400, 'a missing secret is refused by args validation [ARCH-02]');
assert($mock['requests'] === 0, 'and costs no platform request');

// ------------------------------------------------- connect: success through the route

$mock['default'] = jresp(200, array('success' => true, 'data' => array(array('id' => 'm-1', 'workspaceId' => 'ws-route-1'))));
$response = req('POST', REST_CONNECTION, array('token_id' => $pair[0], 'secret' => $pair[1]));

assert(!$response->is_error(), 'a valid pair connects through the route');
$body = $response->get_data();
assert($body['connected'] === true, 'the response is the connection state');
assert($body['workspace_id'] === '', 'no workspace id sniffed from the response — explicit entry only [ASSUME-033]');

// S1: a WRONG candidate typed over a working connection changes nothing about it.
$mock['default'] = jresp(401, array('success' => false, 'error' => array('code' => 401, 'message' => 'Unauthorized', 'description' => 'bad pair')));
$response = req('POST', REST_CONNECTION, array('token_id' => $pair[0], 'secret' => 'sk_wrong'));
assert($response->is_error() && Client::is_healthy() && Creds::token_id() === $pair[0], 'a refused candidate leaves the stored pair healthy and in place (S1)');
$mock['default'] = jresp(200, array('success' => true, 'data' => array()));

// S4: the wizard posts the TRUNCATED token id with a new secret — that means "my stored token".
$response = req('POST', REST_CONNECTION, array('token_id' => Creds::masked_token_id(), 'secret' => $pair[1]));
assert(!$response->is_error() && Creds::token_id() === $pair[0], 'the masked token id resolves to the stored token (S4)');

// S12: the same pair re-verified keeps its playback signing key (every forget orphaned a key on the platform).
update_option(\Fastpix\Fastpix_Health::OPT_SIGNING_KEY, 'selfcheck-key-blob', false);
$response = req('POST', REST_CONNECTION, array('token_id' => $pair[0], 'secret' => $pair[1]));
assert(!$response->is_error() && get_option(\Fastpix\Fastpix_Health::OPT_SIGNING_KEY) === 'selfcheck-key-blob', 'an unchanged pair keeps the signing key (S12)');

// S13: a successful connect clears the breaker and the 429 pause.
set_transient(Client::TRANSIENT_BREAKER, array('fails' => 5, 'first' => time(), 'open_until' => time() + 300), 300);
set_transient(Client::TRANSIENT_PAUSE, time() + 60, 60);
$response = req('POST', REST_CONNECTION, array('token_id' => $pair[0], 'secret' => $pair[1]));
assert(!$response->is_error(), 'a candidate pair is checked even while the breaker is open (S13)');
assert(get_transient(Client::TRANSIENT_BREAKER) === false && get_transient(Client::TRANSIENT_PAUSE) === false, 'and its success closes the breaker and the pause (S13)');

// The workspace step: its own route, UUID-validated. [ASSUME-033]
$response = req('POST', REST_CONNECTION_WORKSPACE, array('workspace_id' => 'nope'));
assert($response->is_error() && $response->get_status() === 400, 'a non-UUID workspace id is refused');

$response = req('POST', REST_CONNECTION_WORKSPACE, array('workspace_id' => '9f3c2a10-4b6d-4e2f-8a75-1c9e07d4b210'));
assert(!$response->is_error(), 'a UUID saves through the route');
assert($response->get_data()['workspace_saved'] === true, 'and the state reports it');
// The dashboard's Workspaces page shows a numeric "Workspace key" — that form is the normal one.
$response = req('POST', REST_CONNECTION_WORKSPACE, array('workspace_id' => '980293090846277633'));
assert(!$response->is_error() && $response->get_data()['workspace_id'] === '980293090846277633', 'the numeric workspace key from the dashboard is accepted');

wp_set_current_user(0);
$response = req('POST', REST_CONNECTION_WORKSPACE, array('workspace_id' => '9f3c2a10-4b6d-4e2f-8a75-1c9e07d4b210'));
assert($response->is_error(), 'anonymous cannot set the workspace [SEC-011]');
wp_set_current_user($admins[0]);
assert($body['secret'] === Creds::SECRET_MASK, 'the secret in the response is the mask [REQ-003]');
assert(strpos(wp_json_encode($body), $pair[1]) === false, 'the raw secret appears nowhere in the response [RULE-003]');
assert(Creds::has_pair() === true, 'the pair is stored');

// ------------------------------------------------------------- test + disconnect

$response = req('POST', REST_CONNECTION_TEST, null);
assert(!$response->is_error(), 'the test route re-validates the stored pair');
assert($response->get_data()['connected'] === true, 'and reports state');

update_option('fastpix_canary_p2', 'untouched', false);
$response = req('DELETE', REST_CONNECTION, null);
assert(!$response->is_error(), 'disconnect succeeds');
assert($response->get_data()['connected'] === false, 'and reports disconnected');
assert(Creds::has_pair() === false, 'the pair is gone');
assert(get_option('fastpix_canary_p2') === 'untouched', 'disconnect deletes nothing else [WF-001]');
delete_option('fastpix_canary_p2');

$response = req('POST', REST_CONNECTION_TEST, null);
assert($response->is_error(), 'testing a disconnected site errors plainly');

// ------------------------------------------------------------------- logs route

Schema::update();
do_action('fastpix_log', 'selfcheck_route_log', array('scope' => 'test', 'message' => 'route check'));
$response = req('GET', '/logs', null);
assert(!$response->is_error(), 'the logs route reads');
$records = $response->get_data()['records'];
assert(is_array($records) && !empty($records), 'records come back');
assert(!array_key_exists('actor_secret', (array) $records[0]) && !array_key_exists('context_raw', (array) $records[0]), 'only the declared columns are exposed');

// ------------------------------------------------------- system report route

Creds::store($pair[0], $pair[1]);
$response = req('GET', '/system-report', null);
assert(!$response->is_error(), 'the system report route reads');
$report = $response->get_data();
$json   = wp_json_encode($report);

assert(strpos($json, $pair[1]) === false, 'the report contains no secret — by construction [FR-081, SEC-019]');
assert(isset($report['environment']['wordpress'], $report['environment']['php'], $report['environment']['plugin']), 'environment block present');
assert(count($report['checks']) === 9, 'all nine checks in the report (REQ-083 + analytics collection)');
assert(count($report['last_errors']) <= 50, 'at most the last fifty error records [FR-081]');
assert($report['connection']['token_id'] === Creds::masked_token_id(), 'the only credential-adjacent field is the truncated token id');

// ------------------------------------------------- pair change: the previous workspace is left (ASSUME-092)

wp_set_current_user($admins[0]);
$wpdb->insert(Schema::table('videos'), array('media_id' => 'conn-t-unstamped', 'workspace_id' => '', 'title' => 'left behind', 'status' => 'Ready', 'created_at' => current_time('mysql', true), 'updated_at' => current_time('mysql', true)));
$wpdb->insert(Schema::table('videos'), array('media_id' => 'conn-t-old', 'workspace_id' => 'ws-old', 'title' => 'old workspace', 'status' => 'Ready', 'created_at' => current_time('mysql', true), 'updated_at' => current_time('mysql', true)));
Creds::store('token-old', 'secret-old');
update_option('fastpix_workspace_seen_id', 'ws-old', false);
update_option('fastpix_analytics_backfill', '2026-01-01', false);
$mock['default'] = jresp(200, array('success' => true, 'data' => array()));
$response = req('POST', REST_CONNECTION, array('token_id' => $pair[0], 'secret' => $pair[1]));
assert(!$response->is_error(), 'a different pair connects');
assert($wpdb->get_var("SELECT workspace_id FROM " . Schema::table('videos') . " WHERE media_id = 'conn-t-unstamped'") === 'ws-old', 'unstamped rows are stamped with the outgoing UUID');
assert(get_option('fastpix_workspace_seen_id', '') === '', 'the learned UUID is forgotten');
assert(get_option(Connection::OPT_PENDING_LEAVE) === 'ws-old', 'the leave is pending until the new UUID is learned');
assert(get_option('fastpix_analytics_backfill') === '2026-01-01', 'the rollup is NOT dropped on the pair change alone (a rotated token keeps its history)');
assert(Connection::learn_workspace('ws-old', false) === false, 'a late webhook from the left workspace cannot re-teach it');
assert(get_option('fastpix_workspace_seen_id', '') === '', 'still unlearned after the refused delivery');
assert(Connection::learn_workspace('ws-new', true) === true, 'the first record read with the new pair teaches the workspace');
assert(get_option('fastpix_workspace_seen_id') === 'ws-new', 'learned');
assert(get_option(Connection::OPT_PENDING_LEAVE, '') === '', 'the pending leave is settled');
assert(get_option('fastpix_analytics_backfill', '') === '', 'a different UUID drops the rollup and its backfill mark');
assert((int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Schema::table('analytics_daily')) === 0, 'rollup emptied');
// Rotation: same pair re-saved leaves nothing.
$response = req('POST', REST_CONNECTION, array('token_id' => $pair[0], 'secret' => $pair[1]));
assert(!$response->is_error() && get_option('fastpix_workspace_seen_id') === 'ws-new', 'the same token re-saved keeps the learned workspace');
// Rotation to a new token of the SAME workspace: the pending leave settles without a wipe.
update_option('fastpix_analytics_backfill', '2026-02-02', false);
$response = req('POST', REST_CONNECTION, array('token_id' => 'token-rotated', 'secret' => $pair[1]));
assert(!$response->is_error() && get_option(Connection::OPT_PENDING_LEAVE) === 'ws-new', 'a new token leaves the pair pending');
assert(Connection::learn_workspace('ws-new', true) === true && get_option(Connection::OPT_PENDING_LEAVE, '') === '', 'the same UUID settles it');
assert(get_option('fastpix_analytics_backfill') === '2026-02-02', 'a rotated token keeps the analytics history');
// A changed workspace key under the SAME pair (a typo fixed) leaves nothing.
update_option(Connection::OPT_WORKSPACE_ID, '111111111111', false);
update_option('fastpix_analytics_backfill', '2026-03-03', false);
$response = req('POST', REST_CONNECTION_WORKSPACE, array('workspace_id' => '222222222222'));
assert(!$response->is_error() && get_option('fastpix_workspace_seen_id') === 'ws-new' && get_option('fastpix_analytics_backfill') === '2026-03-03', 'a key change under the same pair keeps the workspace and its history');
// A changed key while a pair change is unresolved settles it: the rollup goes.
$response = req('POST', REST_CONNECTION, array('token_id' => 'token-third', 'secret' => $pair[1]));
assert(!$response->is_error() && get_option(Connection::OPT_PENDING_LEAVE) === 'ws-new', 'pair change pending again');
// S7: a key that merely EXTENDS the saved one (the rest of a half-typed key) is not a new workspace.
update_option('fastpix_analytics_backfill', '2026-04-04', false);
$response = req('POST', REST_CONNECTION_WORKSPACE, array('workspace_id' => '22222222222200'));
assert(!$response->is_error() && get_option(Connection::OPT_PENDING_LEAVE) === 'ws-new' && get_option('fastpix_analytics_backfill') === '2026-04-04', 'a prefix-extending key while pending wipes nothing (S7)');
$response = req('POST', REST_CONNECTION_WORKSPACE, array('workspace_id' => '333333333333'));
assert(!$response->is_error() && get_option(Connection::OPT_PENDING_LEAVE, '') === '' && get_option('fastpix_analytics_backfill', '') === '', 'a different key while pending drops the rollup and settles the leave');
assert(Connection::learn_workspace('ws-old', false) === false, 'even with nothing pending, a delivery cannot teach a workspace already stamped on rows');
$wpdb->query(SQL_DELETE_FROM . Schema::table('videos') . " WHERE media_id LIKE 'conn-t%'");

// ---------------------------------------------------------------- teardown

global $wpdb;
$wpdb->query(SQL_DELETE_FROM . Schema::table('logs') . " WHERE error_code = 'selfcheck_route_log'");
foreach ($saved as $opt => $value) {
    if ($value === null) {
        delete_option($opt);
    } else {
        update_option($opt, $value, false);
    }
}
// The mocked timeouts above count toward the REAL breaker; leaving it open
// makes every genuine connect fail with "did not respond" for 5 minutes.
delete_transient(Client::TRANSIENT_BREAKER);
delete_transient(Client::TRANSIENT_PAUSE);
wp_set_current_user(0);

echo "rest connection routes: all checks passed\n";
