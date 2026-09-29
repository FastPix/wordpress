<?php
/**
 * Self-check for Fastpix_Rate_Limiter and the REST scaffolding — SEC-011, SEC-012.
 *
 *   docker compose exec wordpress php -d zend.assertions=1 \
 *     /var/www/html/wp-content/plugins/fastpix/tests/test-rate-limiter.php
 */

define('FASTPIX_TRUSTED_PROXIES', '192.0.2.1, 192.0.2.128/25, *');   // S3 fixture: one exact address + one IPv4 range + a '*' that must be ignored
require_once __DIR__ . '/bootstrap.php';

foreach (array('cache', 'schema', 'log', 'rate-limiter', 'rest') as $class) {
    require_once __DIR__ . '/../includes/class-fastpix-' . $class . '.php';
}

use Fastpix\Fastpix_Rate_Limiter as Limiter;
use Fastpix\Fastpix_Rest as Rest;

const PROXY_ADDRESS = '198.51.100.9';
const PEER_ADDRESS = '203.0.113.7';
const CF_ADDRESS = '203.0.113.51';
const DOMINANT_ADDRESS = '198.51.100.3';

$id = 'selfcheck-' . wp_generate_uuid4();

// ------------------------------------------------------- counting and refusal

for ($i = 0; $i < 5; $i++) {
    assert(Limiter::check('test_bucket', $id, 5, 60) === true, "hit {$i} is allowed under the limit");
}
assert(Limiter::count('test_bucket', $id, 60) === 5, 'hits are counted');

$over = Limiter::check('test_bucket', $id, 5, 60);
assert(is_wp_error($over), 'the sixth hit is refused');
assert($over->get_error_code() === 'fastpix_too_many_requests', 'refusal is a rate-limit error');
assert($over->get_error_data()['status'] === 429, 'excess is dropped with 429, never queued [RULE-032]');
assert($over->get_error_data()['retry_after'] > 0, 'the caller is told when to come back');

// A different identifier has its own budget — one noisy viewer cannot lock out
// the rest of a site.
assert(Limiter::check('test_bucket', $id . '-other', 5, 60) === true, 'limits are per identifier [SEC-012]');

// ----------------------------------------------------------- spec'd figures

assert(Limiter::LIMITS['progress_address'] === array(60, 60), '60/min per address on /progress [RULE-032]');
assert(Limiter::LIMITS['progress_viewer'] === array(1, 15), 'one write per viewer per video per 15 s [RULE-032]');
assert(Limiter::LIMITS['progress_viewer_keys'][1] === DAY_IN_SECONDS, 'viewer keys are capped per address per day [RULE-032]');
assert(isset(Limiter::LIMITS['player_config'], Limiter::LIMITS['webhook'], Limiter::LIMITS['admin']),
    'all three public routes plus admin have limits [SEC-012]');
assert(Limiter::LIMITS['webhook'][0] >= 500, 'the webhook limit clears the 500/minute burst gate [REQ-123]');

// A site can retune without editing the plugin.
add_filter('fastpix_rate_limit', function ($limits, $bucket) {
    return $bucket === 'filtered_bucket' ? array(1, 60) : $limits;
}, 10, 2);
assert(Limiter::check('filtered_bucket', $id) === true, 'the first filtered hit passes');
assert(is_wp_error(Limiter::check('filtered_bucket', $id)), 'the filter lowered the limit');
remove_all_filters('fastpix_rate_limit');

// ------------------------------------------------------------------- blocking

assert(Limiter::is_blocked('webhook', $id) === false, 'nothing is blocked to start with');
Limiter::block('webhook', $id, 900);
assert(Limiter::is_blocked('webhook', $id) === true, 'an address can be blocked outright [SEC-013]');
assert(Limiter::block_remaining('webhook', $id) > 800, 'the block lasts 15 minutes [SEC-013]');
$blocked = Limiter::check('webhook', $id);
assert(is_wp_error($blocked) && $blocked->get_error_data()['status'] === 429, 'a blocked caller is refused before counting');
Limiter::release('webhook', $id);
assert(Limiter::is_blocked('webhook', $id) === false, 'a block can be released');

// --------------------------------------------------- address is not spoofable

$_SERVER['REMOTE_ADDR'] = PEER_ADDRESS;
$_SERVER['HTTP_X_FORWARDED_FOR'] = PROXY_ADDRESS;
assert(Limiter::address() === PEER_ADDRESS, 'X-Forwarded-For is not trusted by default [SEC-012]');

add_filter('fastpix_client_address', function () { return PROXY_ADDRESS; });
assert(Limiter::address() === PROXY_ADDRESS, 'a site behind a proxy can opt in');
remove_all_filters('fastpix_client_address');

$_SERVER['REMOTE_ADDR'] = 'not-an-address';
assert(Limiter::address() === '', 'a malformed address yields nothing rather than a bogus bucket');

// S3: FASTPIX_TRUSTED_PROXIES (defined at the top of this file) names the proxies whose forwarding headers count.
$_SERVER['REMOTE_ADDR'] = '192.0.2.200';                    // inside the trusted 192.0.2.128/25
$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.50, ' . PROXY_ADDRESS;
assert(Limiter::address() === PROXY_ADDRESS, 'behind a trusted proxy the LAST X-Forwarded-For hop is the client (S3)');
$_SERVER['HTTP_CF_CONNECTING_IP'] = CF_ADDRESS;
assert(Limiter::address() === CF_ADDRESS, 'CF-Connecting-IP wins when present (S3)');
$_SERVER['REMOTE_ADDR'] = '192.0.2.1';                      // the exact trusted address
assert(Limiter::address() === CF_ADDRESS, 'an exact trusted address is honoured too');
$_SERVER['REMOTE_ADDR'] = PEER_ADDRESS;                    // NOT trusted: headers ignored
assert(Limiter::address() === PEER_ADDRESS, 'an untrusted REMOTE_ADDR keeps its own address whatever the headers say');
assert(Limiter::trusted_proxy(PEER_ADDRESS) === false, "'*' in the constant trusts nobody (QA S3)");
unset($_SERVER['HTTP_CF_CONNECTING_IP']);
$_SERVER['REMOTE_ADDR'] = '172.18.0.1';                     // a private peer (docker gateway / local nginx), not in the constant
assert(Limiter::address() === PROXY_ADDRESS, 'a private-range peer is a local proxy: its forwarded client counts without the constant (QA S3)');
$_SERVER['REMOTE_ADDR'] = PEER_ADDRESS;
assert(Limiter::address() === PEER_ADDRESS, 'a public peer with the same header still keeps its own address (QA S3)');
unset($_SERVER['HTTP_X_FORWARDED_FOR']);
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

// S3: the proxy heuristic — nearly every public hit from one address is reported.
Fastpix\Fastpix_Cache::delete(Limiter::GROUP, 'recent_addresses');
assert(Limiter::dominant_address() === null, 'no verdict on an empty sample');
for ($i = 0; $i < Limiter::OBSERVE_SAMPLE; $i++) { Limiter::observe($i % 20 === 0 ? '198.51.100.2' : PROXY_ADDRESS); }
$dominant = Limiter::dominant_address();
assert($dominant['address'] === PROXY_ADDRESS && $dominant['share'] >= 0.9, 'a 95% single-address sample names the proxy (S3)');
assert(isset(Fastpix\Fastpix_Health::checks()['proxy']), 'and Site Health warns about it (S3)');
Fastpix\Fastpix_Cache::delete(Limiter::GROUP, 'recent_addresses');
for ($i = 0; $i < Limiter::OBSERVE_MIN; $i++) { Limiter::observe(DOMINANT_ADDRESS, true); }
assert(Limiter::dominant_address() === null, 'an address seen only on webhook deliveries is FastPix, not a proxy (QA S3)');
Limiter::observe(DOMINANT_ADDRESS);
assert(Limiter::dominant_address()['address'] === DOMINANT_ADDRESS, 'deliveries AND a viewer from one address → a proxy, at the low-traffic floor (QA S3)');
Fastpix\Fastpix_Cache::delete(Limiter::GROUP, 'recent_addresses');

// ------------------------------------------------- REST authorisation model

assert(Rest::NS === 'fastpix/v1', 'the namespace is the binding one [06 §B]');

// A route with no capability and no public bucket is refused, not left open.
$unguarded = Rest::prepare(array('methods' => 'GET', 'callback' => '__return_true'));
assert($unguarded['permission_callback'] === '__return_false', 'a route without an authorisation decision is closed [SEC-011]');

// Admin route: the specific capability, and a refusal is audited.
$audited = array();
add_action('fastpix_audit_event', function ($event) use (&$audited) { $audited[] = $event; });

$admin = Rest::prepare(array('methods' => 'GET', 'callback' => '__return_true', 'capability' => 'fastpix_manage_settings'));
$request = new WP_REST_Request('GET', '/fastpix/v1/settings');

wp_set_current_user(0);
$denied = call_user_func($admin['permission_callback'], $request);
assert(is_wp_error($denied), 'a user without the capability is refused [SEC-011]');
assert($denied->get_error_data()['status'] === rest_authorization_required_code(), 'refusal uses the REST authorisation status');
assert(in_array('permission_refused', $audited, true), 'every refused check is audited [SEC-011, REQ-092]');

$admins = get_users(array('role' => 'administrator', 'number' => 1, 'fields' => 'ID'));
if ($admins) {
    wp_set_current_user($admins[0]);
    if (current_user_can('fastpix_manage_settings')) {
        assert(call_user_func($admin['permission_callback'], $request) === true, 'the capability holder is allowed through');
    }
    wp_set_current_user(0);
}

// Public route: limited per address AND per identifier.
$public = Rest::prepare(array('methods' => 'GET', 'callback' => '__return_true', 'public_bucket' => 'player_config'));
$player_request = new WP_REST_Request('GET', '/fastpix/v1/player-config/pb-1');
$player_request->set_param('id', 'pb-' . $id);

assert(call_user_func($public['permission_callback'], $player_request) === true, 'a public request within limits passes');
assert(Limiter::count('player_config', 'addr:' . Limiter::address()) >= 1, 'the address bucket was spent [SEC-012]');
assert(Limiter::count('player_config:id', 'id:pb-' . $id) >= 1, 'the identifier bucket was spent too [SEC-012]');
// S2: the identifier half must not throttle a popular resource — it is ID_MULTIPLIER × the address limit.
$popular = Rest::prepare(array('methods' => 'GET', 'callback' => '__return_true', 'public_bucket' => 'player_config'));
$viewers = Limiter::LIMITS['player_config'][0] + 5;   // more distinct addresses than one address may spend
for ($i = 0; $i < $viewers; $i++) {
    $_SERVER['REMOTE_ADDR'] = long2ip(ip2long('198.18.0.0') + $i);   // a distinct viewer each time
    assert(call_user_func($popular['permission_callback'], $player_request) === true, "viewer {$i} of one popular id is admitted (S2)");
}
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
assert(Rest::ID_MULTIPLIER >= 10, 'the identifier half is an order of magnitude above the address half');

assert(!isset($public['capability'], $public['public_bucket']), 'the helper keys do not leak into the route args');

// Args declare validate and sanitize callbacks.
$arg = Rest::arg('string');
assert($arg['validate_callback'] === 'rest_validate_request_arg', 'args are schema-validated [ARCH-02]');
assert($arg['sanitize_callback'] === 'sanitize_text_field', 'and sanitised');
assert(Rest::pagination_args()['per_page']['maximum'] === 100, 'every list query is bounded [REQ-039]');

// ------------------------------- the Phase 1 guarantee, through a real dispatch

// A route registered through the scaffolding without an authorisation decision
// must refuse EVERY caller — even an administrator — via the actual REST
// server, not just in the prepared args. [SEC-011]
add_action('rest_api_init', function () {
    Rest::register('/selfcheck-unguarded', array(
        'methods'  => 'GET',
        'callback' => '__return_true',
        // no capability, no public_bucket — the mistake the scaffolding exists to catch
    ));
    Rest::register('/selfcheck-guarded', array(
        'methods'    => 'GET',
        'callback'   => function () { return rest_ensure_response(array('ok' => true)); },
        'capability' => 'fastpix_manage_settings',
    ));
});

$server = rest_get_server();   // instantiates and fires rest_api_init

$routes = $server->get_routes();
assert(isset($routes['/fastpix/v1/selfcheck-unguarded']), 'the unguarded route registered under the namespace [06 §B]');

$admins = get_users(array('role' => 'administrator', 'number' => 1, 'fields' => 'ID'));
wp_set_current_user($admins ? $admins[0] : 0);

$response = $server->dispatch(new WP_REST_Request('GET', '/fastpix/v1/selfcheck-unguarded'));
assert($response->is_error(), 'an unguarded route is refused by default [SEC-011]');
assert($response->get_status() === rest_authorization_required_code(), 'refused with the authorisation status, even for an administrator');

// The guarded twin works for the capability holder and refuses without it —
// the refusal above is the scaffolding's doing, not a broken dispatcher.
if ($admins && current_user_can('fastpix_manage_settings')) {
    $ok = $server->dispatch(new WP_REST_Request('GET', '/fastpix/v1/selfcheck-guarded'));
    assert(!$ok->is_error(), 'the guarded twin passes for the capability holder');
}
wp_set_current_user(0);
$denied = $server->dispatch(new WP_REST_Request('GET', '/fastpix/v1/selfcheck-guarded'));
assert($denied->is_error(), 'the guarded twin refuses without the capability [SEC-011]');

// A request to a route that was never registered 404s (WordPress's own
// behaviour, asserted so a route cannot exist without passing through here).
$missing = $server->dispatch(new WP_REST_Request('GET', '/fastpix/v1/never-registered'));
assert($missing->get_status() === 404, 'an unregistered route does not exist');

// ---------------------------------------------------------------- teardown

Fastpix\Fastpix_Cache::flush_group(Limiter::GROUP);

echo "rate limiter + REST scaffolding: all checks passed\n";
