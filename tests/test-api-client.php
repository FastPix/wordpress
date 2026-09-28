<?php
/**
 * Self-check for Fastpix_Api_Client (ARCH-03) and Fastpix_Connection (WF-001).
 *
 *   docker compose exec wordpress php -d zend.assertions=1 \
 *     /var/www/html/wp-content/plugins/fastpix/tests/test-api-client.php
 *
 * Boots real WordPress and mocks the transport at pre_http_request, so filters,
 * options and transients behave exactly as they will in production. Every
 * option and transient it touches is snapshotted and restored at the end.
 */

require_once __DIR__ . '/bootstrap.php';

use Fastpix\Fastpix_Api_Client as Client;
use Fastpix\Fastpix_Connection as Connection;
use Fastpix\Fastpix_Credentials as Creds;

const EP_ON_DEMAND = '/on-demand';
const EP_VERIFY_BASIC = '/iam/auth/verify-basic';

foreach (array('credentials', 'cache', 'api-client', 'connection') as $class) {
    require_once __DIR__ . '/../includes/class-fastpix-' . $class . '.php';
}

// ---------------------------------------------------------------- harness

$saved = array();
foreach (array(Creds::OPT_TOKEN_ID, Creds::OPT_SECRET, Connection::OPT_WORKSPACE_ID, Connection::OPT_WORKSPACE_SEEN_ID, Connection::OPT_WORKSPACE_SEEN_NAME, Client::OPT_HEALTH,
             Connection::OPT_PENDING_LEAVE, Connection::OPT_LEFT_UNKNOWN, Connection::OPT_LAST_TOKEN, Connection::OPT_LAST_KEY,   // the leave machinery must never fire on real data
             \Fastpix\Fastpix_Health::OPT_SIGNING_KEY) as $opt) {                                                                   // connect/disconnect may rotate it
    $saved[$opt] = get_option($opt, null);
}
// Restore even when a failed assertion aborts the run — a leaked fixture pair once shadowed the live one.
register_shutdown_function(function () use (&$saved) {
    foreach ($saved as $opt => $value) { $value === null ? delete_option($opt) : update_option($opt, $value, false); }
});
foreach (array(Connection::OPT_PENDING_LEAVE, Connection::OPT_LEFT_UNKNOWN, Connection::OPT_LAST_TOKEN, Connection::OPT_LAST_KEY) as $opt) { delete_option($opt); }   // learn_workspace can never wipe
delete_option(Connection::OPT_WORKSPACE_SEEN_ID); delete_option(Connection::OPT_WORKSPACE_SEEN_NAME);
// Every connect() seeds sync_state rows keyed by the workspace key of the moment (empty here) — put the table back as it was.
global $wpdb;
$sync_rows = $wpdb->get_results('SELECT * FROM ' . \Fastpix\Fastpix_Schema::table('sync_state'), ARRAY_A);
register_shutdown_function(function () use ($sync_rows) {
    global $wpdb;
    $wpdb->query('DELETE FROM ' . \Fastpix\Fastpix_Schema::table('sync_state'));
    foreach ($sync_rows as $row) { $wpdb->insert(\Fastpix\Fastpix_Schema::table('sync_state'), $row); }
});

$mock = array('queue' => array(), 'default' => null, 'requests' => array());

add_filter('pre_http_request', function ($_pre, $args, $url) use (&$mock) {
    $mock['requests'][] = array('url' => $url, 'args' => $args);
    $next = array_shift($mock['queue']);

    return $next === null ? $mock['default'] : $next;
}, 10, 3);

// No real waiting: full jitter is exercised, the sleep is not.
add_filter('fastpix_api_backoff_seconds', '__return_zero');

$audit = array();
add_action('fastpix_audit_event', function ($event) use (&$audit) {
    $audit[] = $event;
}, 10, 1);

function resp($code, $body = array(), $headers = array()) {
    return array(
        'headers'  => $headers,
        'body'     => wp_json_encode($body),
        'response' => array('code' => $code, 'message' => ''),
    );
}

function fail_resp($code, $message = 'Something went wrong', $description = 'internal detail') {
    return resp($code, array('success' => false, 'error' => array(
        'code' => $code, 'message' => $message, 'description' => $description,
    )));
}

function transport_error() {
    return new WP_Error('http_request_failed', 'cURL error 28: Operation timed out');
}

function reset_state(&$mock) {
    $mock['queue'] = array();
    $mock['requests'] = array();
    $mock['default'] = fail_resp(503, 'FastPix is not responding');
    delete_transient(Client::TRANSIENT_BREAKER);
    delete_transient(Client::TRANSIENT_PAUSE);
    delete_option(Client::OPT_HEALTH);
}

$pair = array('4f27c4c4-9a1e-4c2f-9d33-2b8f0a7c1e55', 'test_secret_9RtP2xW7qL4mZ0vC8bN6yH3jK1sD5gF');
$media_body = array('success' => true, 'data' => array(array(
    'id' => 'm-1', 'workspaceId' => 'ws-4f27c4c4', 'status' => 'Ready',
)));

// ------------------------------------------------- headers and success shape

reset_state($mock);
Creds::store($pair[0], $pair[1]);
$mock['queue'][] = resp(200, $media_body);

$client = new Client();
$ok = $client->request('GET', EP_ON_DEMAND, array('query' => array('limit' => 1)));

assert(!is_wp_error($ok), 'a 200 is not an error');
assert($ok['status'] === 200, 'status surfaces');
assert($ok['body']['data'][0]['id'] === 'm-1', 'body is decoded');

$sent = $mock['requests'][0]['args']['headers'];
assert($sent['Authorization'] === 'Basic ' . base64_encode($pair[0] . ':' . $pair[1]), 'HTTP Basic from the stored pair [06 §A]');
assert($sent['Content-Type'] === 'application/json', 'Content-Type on every request');
assert(!isset($sent['X-FastPix-Integration']), 'no integration header on ordinary calls [ASSUME-069]');
assert(strpos($mock['requests'][0]['url'], 'limit=1') !== false, 'query args are applied');
assert($mock['requests'][0]['args']['timeout'] === 30, 'interactive read timeout is 30 s');
assert($mock['requests'][0]['args']['connect_timeout'] === 10, 'connect timeout is 10 s');

// ------------------------------------- integration header only when asked for

reset_state($mock);
$mock['queue'][] = resp(200, $media_body);
$client->request('GET', EP_ON_DEMAND, array('headers' => array('X-FastPix-Integration' => 'wordpress')));
assert($mock['requests'][0]['args']['headers']['X-FastPix-Integration'] === 'wordpress', 'the connect check can add the integration header per call [ASSUME-069]');

// -------------------------------------------------------------- retry policy

reset_state($mock);
$client->request('GET', EP_ON_DEMAND);
assert(count($mock['requests']) === 2, 'interactive gets 2 attempts [ARCH-03]');

reset_state($mock);
$err = $client->request('GET', EP_ON_DEMAND, array('context' => 'background'));
assert(count($mock['requests']) === 7, 'background gets 7 attempts [ARCH-03]');
assert($err->get_error_code() === 'fastpix_server_error', '5xx surfaces after the last attempt');
assert($err->get_error_message() === 'FastPix is not responding', 'the platform message is surfaced');
assert($err->get_error_data()['description'] === 'internal detail', 'the description is carried for logging, not display');

reset_state($mock);
$mock['default'] = transport_error();
$client->request('GET', EP_ON_DEMAND);
assert(count($mock['requests']) === 2, 'transport failure retries like a 5xx');

// Terminal statuses never retry.
foreach (array(400 => 'fastpix_bad_request', 422 => 'fastpix_bad_request', 404 => 'fastpix_not_found', 409 => 'fastpix_conflict') as $status => $slug) {
    reset_state($mock);
    $mock['default'] = fail_resp($status);
    $err = $client->request('GET', EP_ON_DEMAND, array('context' => 'background'));
    assert(count($mock['requests']) === 1, "HTTP {$status} is not retried");
    assert($err->get_error_code() === $slug, "HTTP {$status} maps to {$slug}");
}

// ------------------------------------------------------- breaker open / close

reset_state($mock);
$client->request('GET', EP_ON_DEMAND, array('context' => 'background'));   // 7 consecutive 5xx
$breaker = get_transient(Client::TRANSIENT_BREAKER);
assert($breaker['fails'] >= Client::BREAKER_THRESHOLD, '5 failures in 60 s trip the breaker');
assert($breaker['open_until'] > time(), 'the breaker is open');
assert($breaker['open_until'] <= time() + Client::BREAKER_OPEN_FOR, 'open for 5 minutes, not longer');

$mock['requests'] = array();
$err = $client->request('GET', EP_ON_DEMAND);
assert($err->get_error_code() === 'fastpix_breaker_open', 'an open breaker fails immediately');
assert(count($mock['requests']) === 0, 'an open breaker spends no request');

// Closes on the first success once the window lapses.
set_transient(Client::TRANSIENT_BREAKER, array('fails' => 5, 'first' => time() - 120, 'open_until' => time() - 1), 300);
$mock['queue'][] = resp(200, $media_body);
assert(!is_wp_error($client->request('GET', EP_ON_DEMAND)), 'a lapsed breaker lets the request through');
assert(get_transient(Client::TRANSIENT_BREAKER) === false, 'the breaker closes on first success [ERR-037]');

// S8: the half-open probe that FAILS after the window lapsed is failure one of a new streak, not the sixth.
set_transient(Client::TRANSIENT_BREAKER, array('fails' => 5, 'first' => time() - 400, 'open_until' => time() - 1), 300);
$mock['queue'] = array(transport_error(), transport_error());   // both interactive attempts
assert(is_wp_error($client->request('GET', EP_ON_DEMAND)), 'the probe fails');
$breaker = get_transient(Client::TRANSIENT_BREAKER);
assert($breaker['fails'] === 2 && (int) $breaker['open_until'] === 0, 'a lapsed window starts a fresh streak (S8)');
delete_transient(Client::TRANSIENT_BREAKER);

// S13: a CANDIDATE pair is not gated by the breaker — one deliberate Connect click goes out.
set_transient(Client::TRANSIENT_BREAKER, array('fails' => 5, 'first' => time(), 'open_until' => time() + 300), 300);
$mock['queue'] = array(resp(200, array('success' => true)));
assert(!is_wp_error((new Client('cand-token', 'cand-secret'))->request('POST', EP_VERIFY_BASIC)), 'a candidate pair passes an open breaker (S13)');
assert(get_transient(Client::TRANSIENT_BREAKER) === false, 'and its success closes it');
$mock['queue'] = array();

// ------------------------------------------------------------------ 429 pause

reset_state($mock);
$mock['queue'][] = resp(429, array('success' => false, 'error' => array('code' => 429, 'message' => 'Too many requests')), array('retry-after' => '30'));
$err = $client->request('GET', EP_ON_DEMAND, array('context' => 'background'));

assert($err->get_error_code() === 'fastpix_rate_limited', '429 maps to rate limited');
assert($err->get_error_data()['retry_after'] === 30, 'Retry-After is honoured [ERR-038]');
assert(count($mock['requests']) === 1, '429 is not retried inline');

$mock['requests'] = array();
$err = $client->request('GET', EP_ON_DEMAND);
assert($err->get_error_code() === 'fastpix_rate_limited', 'the pause holds subsequent requests');
assert(count($mock['requests']) === 0, 'nothing goes out while paused');
delete_transient(Client::TRANSIENT_PAUSE);

// ------------------------------------------------------- idempotency replay

reset_state($mock);
$mock['queue'][] = fail_resp(503);
$mock['queue'][] = resp(201, array('success' => true));
$client->request('POST', EP_ON_DEMAND, array(
    'context'            => 'background',
    'body'               => array('inputs' => array()),
    'idempotency_row_id' => 'videos:123',
));

$keys = array_column(array_column($mock['requests'], 'args'), 'headers');
assert(count($mock['requests']) === 2, 'the create was retried once');
assert($keys[0]['Idempotency-Key'] === $keys[1]['Idempotency-Key'], 'a retried create replays the same idempotency key [ARCH-03]');

$first_key = $keys[0]['Idempotency-Key'];
reset_state($mock);
$mock['queue'][] = resp(201);
$client->request('POST', EP_ON_DEMAND, array('idempotency_row_id' => 'videos:123'));
assert($mock['requests'][0]['args']['headers']['Idempotency-Key'] === $first_key, 'the key is derived from the row id, so it is stable');

reset_state($mock);
$mock['queue'][] = resp(201);
$client->request('POST', EP_ON_DEMAND, array('idempotency_row_id' => 'videos:124'));
assert($mock['requests'][0]['args']['headers']['Idempotency-Key'] !== $first_key, 'a different row gets a different key');

reset_state($mock);
$mock['queue'][] = resp(200, $media_body);
$client->request('GET', EP_ON_DEMAND);
assert(!isset($mock['requests'][0]['args']['headers']['Idempotency-Key']), 'reads carry no idempotency key');

// ------------------------------------- 401: unhealthy, no retry, pair kept

reset_state($mock);
$mock['default'] = fail_resp(401, 'Unauthorized');
$err = $client->request('GET', EP_ON_DEMAND, array('context' => 'background'));

assert($err->get_error_code() === 'fastpix_bad_credentials', '401 maps to bad credentials');
assert(count($mock['requests']) === 1, '401 is never retried [ARCH-03]');
assert(Client::is_healthy() === false, 'the connection is marked unhealthy [ERR-005]');
assert(Creds::has_pair() === true, 'the stored credential is NOT cleared [FR-005]');

// ------------------------------------------- connection: validate before store

reset_state($mock);
Creds::forget();
delete_option(Connection::OPT_WORKSPACE_ID);
$audit = array();

$mock['default'] = fail_resp(401, 'Unauthorized');
$err = Connection::connect($pair[0], $pair[1]);

assert(is_wp_error($err), 'a rejected pair does not connect');
assert($err->get_error_code() === 'fastpix_bad_credentials', 'ERR-001 is raised');
assert($err->get_error_message() === 'Invalid credentials' && empty($err->get_error_data()), 'one plain verdict, no field singled out [ASSUME-071]');
assert(Creds::has_pair() === false, 'a pair that does not work is never stored [REQ-002]');
assert($audit === array(), 'a failed connect audits nothing');

reset_state($mock);
$mock['default'] = transport_error();
$err = Connection::connect($pair[0], $pair[1]);
assert($err->get_error_code() === 'fastpix_unreachable', 'ERR-002 is raised for an unreachable platform');
assert(Creds::has_pair() === false, 'still nothing stored');

reset_state($mock);
$mock['queue'][] = resp(200);   // the verify call answers 200 with an empty body
$state = Connection::connect($pair[0], $pair[1]);

assert(!is_wp_error($state), 'a valid pair connects');
assert(count($mock['requests']) === 1, 'validation is ONE call — its only job is proving the pair works [ASSUME-033]');
assert($mock['requests'][0]['args']['method'] === 'POST' && substr($mock['requests'][0]['url'], -strlen(EP_VERIFY_BASIC)) === EP_VERIFY_BASIC, 'it is the platform\'s private Basic-auth verify call [ASSUME-070]');
assert(!isset($mock['requests'][0]['args']['body']), 'sent with no body: nothing created, nothing sniffed');
assert($mock['requests'][0]['args']['headers']['X-FastPix-Integration'] === 'wordpress', 'the integration header rides only this call [ASSUME-069]');
assert(Creds::has_pair() === true, 'the pair is stored on success');
assert($state['workspace_id'] === '', 'NO workspace id is extracted from responses — it is explicit user entry [ASSUME-033]');
assert($state['workspace_saved'] === false, 'and none is saved yet');
assert($state['workspace_name'] === null, 'no endpoint returns the workspace name — it is learned from deliveries only [CONFLICT-004]');
assert($state['permissions'] === null, 'the permission list is unknown [OQ-008]');
assert($state['secret'] === Creds::SECRET_MASK, 'the secret is masked in the state [REQ-003]');
assert(in_array('connect', $audit, true), 'connect is audited');

// --------------------------------------------- workspace id: explicit entry

$bad = Connection::set_workspace_id('not-a-uuid');
assert(is_wp_error($bad) && $bad->get_error_code() === 'fastpix_bad_workspace_id', 'a non-UUID is refused [ASSUME-033]');
assert(Connection::workspace_ready() === false, 'not ready until a workspace is saved');

$state = Connection::set_workspace_id('9F3C2A10-4B6D-4E2F-8A75-1C9E07D4B210');
assert(!is_wp_error($state), 'a UUID saves');
assert($state['workspace_id'] === '9f3c2a10-4b6d-4e2f-8a75-1c9e07d4b210', 'normalised to lowercase');
assert($state['workspace_saved'] === true, 'and reported saved');
assert(Connection::workspace_ready() === true, 'pair + workspace = ready [ASSUME-033]');
assert(in_array('workspace_set', $audit, true), 'the workspace save is audited');

// The token binds the workspace: a key saved under the same pair keeps the learned UUID + name (ASSUME-092); no mismatch warning exists (owner ruling 2026-08-19).
update_option(Connection::OPT_WORKSPACE_SEEN_ID, 'other-ws', false); update_option(Connection::OPT_WORKSPACE_SEEN_NAME, 'Other', false);
Connection::set_workspace_id('9f3c2a10-4b6d-4e2f-8a75-1c9e07d4b210');
assert(get_option(Connection::OPT_WORKSPACE_SEEN_ID, '') === 'other-ws' && Connection::state()['workspace_name'] === 'Other', 'saving a key under the same pair keeps the learned workspace [ASSUME-092]');
assert(!array_key_exists('workspace_mismatch', Connection::state()), 'no mismatch state is exposed');

// Rotation: a bad new pair leaves the working one in place.
reset_state($mock);
$mock['default'] = fail_resp(401, 'Unauthorized');
Connection::connect('other-token', 'other-secret');
assert(Creds::token_id() === $pair[0], 'a failed rotation keeps the old pair [FR-005]');
assert(Creds::secret() === $pair[1], 'and the old secret');

// ------------------------------------------------------------- disconnect

reset_state($mock);
update_option('fastpix_canary', 'untouched', false);
$audit = array();

$state = Connection::disconnect();

assert($state['connected'] === false, 'disconnect ends the connection');
assert(Creds::has_pair() === false, 'the pair is gone');
assert(get_option(Connection::OPT_WORKSPACE_ID, '') === '', 'the workspace binding is gone');
assert(get_option('fastpix_canary') === 'untouched', 'disconnect deletes nothing else [WF-001]');
assert(in_array('disconnect', $audit, true), 'disconnect is audited');
delete_option('fastpix_canary');

// ------------------------------------------------------------- pagination

reset_state($mock);
Creds::store($pair[0], $pair[1]);
$client = new Client();

$mock['queue'][] = resp(200, array('data' => array(array('id' => 'm-1'), array('id' => 'm-2')), 'pagination' => array('totalRecords' => 3)));
$mock['queue'][] = resp(200, array('data' => array(array('id' => 'm-3')), 'pagination' => array('totalRecords' => 3)));

$all = $client->paginate(EP_ON_DEMAND, array('limit' => 2));
assert(count($all) === 3, 'paginate walks every page [06 §A]');
assert($all[2]['id'] === 'm-3', 'items arrive in order');
assert(count($mock['requests']) === 2, 'one request per page');
assert(strpos($mock['requests'][1]['url'], 'offset=2') !== false, 'offset advances by the page size');

// A page callback can stop the walk — long sweeps stop at their time budget.
reset_state($mock);
$mock['default'] = resp(200, array('data' => array(array('id' => 'x'), array('id' => 'y')), 'pagination' => array('totalRecords' => 100)));
$seen = 0;
$client->paginate(EP_ON_DEMAND, array('limit' => 2), function ($page) use (&$seen) {
    $seen += count($page);

    return $seen < 4 ? true : false;
});
assert($seen === 4, 'the walk stops when the caller says so [ARCH-07 20 s budget]');
assert(count($mock['requests']) === 2, 'and spends no further requests');

// --------------------------------------------------------- capability probe

Fastpix\Fastpix_Cache::flush_group('capability');
reset_state($mock);

$mock['default'] = fail_resp(404, 'Not found');
assert($client->supports('/live/streams') === false, 'a missing endpoint reports unsupported [ARCH-03]');
assert(count($mock['requests']) === 1, 'the probe costs one request');

$mock['requests'] = array();
assert($client->supports('/live/streams') === false, 'the verdict is remembered');
assert(count($mock['requests']) === 0, 'a cached verdict costs nothing — one feature is disabled, not every call [REQ-100]');

Fastpix\Fastpix_Cache::flush_group('capability');
reset_state($mock);
$mock['default'] = resp(200, array('data' => array()));
assert($client->supports(EP_ON_DEMAND) === true, 'a present endpoint reports supported');
$mock['requests'] = array();
assert($client->supports(EP_ON_DEMAND) === true, 'and is remembered too');
assert(count($mock['requests']) === 0, 'without re-probing');

// An outage is not a verdict about capability.
Fastpix\Fastpix_Cache::flush_group('capability');
reset_state($mock);
$mock['default'] = transport_error();
assert($client->supports(EP_ON_DEMAND) === false, 'an unreachable platform yields no support');
reset_state($mock);
$mock['default'] = resp(200, array('data' => array()));
assert($client->supports(EP_ON_DEMAND) === true, 'and is re-probed rather than cached as missing [ARCH-03]');
Fastpix\Fastpix_Cache::flush_group('capability');

// ---------------------------------------------------------------- teardown

foreach ($saved as $opt => $value) {
    if ($value === null) {
        delete_option($opt);
    } else {
        update_option($opt, $value, false);
    }
}
delete_transient(Client::TRANSIENT_BREAKER);
delete_transient(Client::TRANSIENT_PAUSE);

echo "api client + connection: all checks passed\n";
