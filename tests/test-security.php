<?php
/**
 * Security release suite — SEC-021 (functional-tests §Security), Phase 12.
 *
 *   docker compose exec wordpress php -d zend.assertions=1 \
 *     /var/www/html/wp-content/plugins/fastpix/tests/test-security.php
 *
 * The cross-cutting checks. Webhook signature/replay/blocking live in
 * test-webhooks; per-bucket rate limits in test-rate-limiter; SEC-005/006/007
 * render behaviour in test-render — this suite covers SSRF, SQLi, XSS,
 * privilege escalation, route authorisation, offline outbox, token leakage.
 */

require_once __DIR__ . '/bootstrap.php';

use Fastpix\Fastpix_Api_Client as Api;
use Fastpix\Fastpix_Cache as Cache;
use Fastpix\Fastpix_Credentials as Creds;
use Fastpix\Fastpix_Health as Health;
use Fastpix\Fastpix_Outbox as Outbox;
use Fastpix\Fastpix_Render as Render;
use Fastpix\Fastpix_Schema as Schema;
use Fastpix\Fastpix_Uploads as Uploads;
use Fastpix\Fastpix_Videos_Rest as Videos;

const REST_VIDEOS = '/fastpix/v1/videos/';
const SCHEME_HTTPS = 'https://';

global $wpdb;

$saved = array();
foreach (array(Creds::OPT_TOKEN_ID, Creds::OPT_SECRET, Api::OPT_HEALTH, Health::OPT_SIGNING_KEY, Outbox::OPTION, 'fastpix_workspace_seen_id') as $opt) {
    $saved[$opt] = get_option($opt, null);
}
register_shutdown_function(function () use (&$saved) {
    global $wpdb;
    foreach ($saved as $opt => $value) { $value === null ? delete_option($opt) : update_option($opt, $value, false); }
    delete_transient(Api::TRANSIENT_BREAKER);
    $wpdb->query('DELETE FROM ' . Schema::table('playback_ids') . ' WHERE video_id IN (SELECT id FROM ' . Schema::table('videos') . " WHERE media_id LIKE 'sec-%')");
    $wpdb->query('DELETE FROM ' . Schema::table('videos') . " WHERE media_id LIKE 'sec-%'");
    wp_set_current_user(0);
});
Creds::store('4f27c4c4-9a1e-4c2f-9d33-2b8f0a7c1e55', 'sk_selfcheck_security');
delete_option(Api::OPT_HEALTH);
delete_transient(Api::TRANSIENT_BREAKER);
delete_option(Outbox::OPTION);
add_filter('fastpix_api_backoff_seconds', '__return_zero');

/* ------------------------------------------------- SSRF gate [SEC-010] */

// Private, loopback, link-local and reserved addresses refuse by ADDRESS —
// including the bypass classes: CGNAT, IPv6 loopback/link-local/ULA, and a
// v4-mapped IPv6 that would otherwise smuggle a loopback past an IPv4-only check.
// https:// throughout: these fixtures are exercising address-class blocking
// (loopback/private/link-local/CGNAT/ULA/v4-mapped/NAT64), never protocol
// behaviour, so the scheme itself carries no test intent.
foreach (array(
    'https://127.0.0.1/video.mp4', 'https://10.1.2.3/a.mp4', 'https://192.168.1.10/a.mp4',
    'https://172.16.0.9/a.mp4', 'https://169.254.169.254/latest/meta-data', 'https://0.0.0.0/a.mp4',
    'https://100.64.0.1/a.mp4',                       // CGNAT
    'https://[::1]/a.mp4', 'https://[fe80::1]/a.mp4', 'https://[fc00::1]/a.mp4',
    'https://[::ffff:127.0.0.1]/a.mp4',               // v4-mapped loopback
    'https://[64:ff9b::7f00:1]/a.mp4',                // NAT64-embedded loopback
) as $url) {
    $verdict = Uploads::validate_public_video_url($url);
    assert(is_wp_error($verdict), 'refuses ' . $url);
    assert(in_array($verdict->get_error_code(), array('fastpix_url_private', 'fastpix_url_unresolvable', 'fastpix_url_unreachable'), true), 'address-refused ' . $url);
}
// Non-http schemes refuse outright.
foreach (array('file:///etc/passwd', 'ftp://example.com/a.mp4', 'gopher://example.com/') as $url) {
    $verdict = Uploads::validate_public_video_url($url);
    // file:// has no host, so it refuses as invalid before the scheme check — refused either way.
    assert(is_wp_error($verdict) && in_array($verdict->get_error_code(), array('fastpix_url_scheme', 'fastpix_url_invalid'), true));
}

// A public host whose redirect chain lands on a private address is refused,
// and more than three redirects is refused regardless of destination.
$public_host = '93.184.216.34';   // an IP literal: resolvable without DNS, publicly routable
$redirect_to = 'https://192.168.0.5/internal.mp4';
add_filter('pre_http_request', function ($pre, $_args, $url) use (&$redirect_to, $public_host) {
    if (strpos($url, $public_host) !== false) {
        return array('headers' => array('location' => $redirect_to), 'body' => '', 'response' => array('code' => 302, 'message' => ''));
    }
    return $pre;
}, 10, 3);

$verdict = Uploads::validate_public_video_url(SCHEME_HTTPS . $public_host . '/a.mp4');
assert(is_wp_error($verdict) && $verdict->get_error_code() === 'fastpix_url_private');   // hop re-checked

$redirect_to = SCHEME_HTTPS . $public_host . '/again.mp4';   // redirect loop → the hop cap answers
$verdict = Uploads::validate_public_video_url(SCHEME_HTTPS . $public_host . '/a.mp4');
assert(is_wp_error($verdict) && $verdict->get_error_code() === 'fastpix_url_redirects');

/* ------------------------------- route authorisation sweep [SEC-011] */

// Every fastpix/v1 route ships with a real permission decision — never a
// bare true. Fastpix_Rest::prepare() refuses unregistered authorisation with
// __return_false; this sweep proves nothing slipped around it.
do_action('rest_api_init', rest_get_server());
$routes = rest_get_server()->get_routes('fastpix/v1');
assert(count($routes) > 10);
foreach ($routes as $route => $handlers) {
    if ($route === '/fastpix/v1') { continue; }   // core's namespace discovery index, not a plugin route
    foreach ($handlers as $handler) {
        $callback = $handler['permission_callback'] ?? null;
        assert($callback !== null, 'route without permission callback: ' . $route);
        assert($callback !== '__return_true', 'open route: ' . $route);
    }
}

/* --------------------------------------- SQLi via filters and search */

$fixtures = array();
$now = current_time('mysql', true);
foreach (array(array('sec-pub', 'public'), array('sec-priv', 'private')) as $pair) {
    $wpdb->insert(Schema::table('videos'), array(
        'media_id' => $pair[0], 'workspace_id' => (string) get_option('fastpix_workspace_seen_id', ''),
        'title' => $pair[0] === 'sec-pub' ? '<script>alert(1)</script>"onmouseover="x' : 'Private sec fixture',
        'status' => 'Ready', 'source' => 'Upload', 'access_policy' => $pair[1], 'author_id' => 1,
        'created_at' => $now, 'updated_at' => $now,
    ));
    $fixtures[$pair[0]] = (int) $wpdb->insert_id;
    $wpdb->insert(Schema::table('playback_ids'), array('video_id' => $fixtures[$pair[0]], 'playback_id' => 'pb-' . $pair[0], 'access_policy' => $pair[1], 'created_at' => $now, 'updated_at' => $now));
}

$admins = get_users(array('role' => 'administrator', 'fields' => 'ID'));
wp_set_current_user((int) $admins[0]);

$before = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Schema::table('videos'));
foreach (array(
    array('search', "'; DROP TABLE {$wpdb->prefix}fastpix_videos; --"),
    array('search', '" OR 1=1 --'),
    array('status', "Ready' OR '1'='1"),
    array('access', "public' UNION SELECT 1 --"),
    array('source', 'Upload"; DELETE FROM x; --'),
    array('after', 'AAAA////'),
    array('orderby', 'title; DROP TABLE users'),
) as $probe) {
    $request = new WP_REST_Request('GET', '/fastpix/v1/videos');
    $request->set_param($probe[0], $probe[1]);
    Videos::list_videos($request);   // must not throw or corrupt — asserted below
}
assert((int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Schema::table('videos')) === $before);   // nothing dropped
assert($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->prefix . 'fastpix_videos')) !== null);

/* ------------------------------------------ XSS: hostile stored title */

Cache::flush_group('embed');
$html = Render::render('sec-pub');
assert(strpos($html, '<script>alert(1)</script>') === false);          // stored XSS escaped
assert(strpos($html, 'onmouseover="x') === false);
// Hostile shortcode attribute never lands raw in markup.
$html = Render::shortcode(array('id' => 'sec-pub', 'accentcolor' => '"><script>alert(2)</script>'));
assert(strpos($html, '<script>alert(2)') === false);

/* ------------------- privilege escalation: role checks + SEC-006 */

// A logged-out caller cannot edit or delete through the REST permission layer.
wp_set_current_user(0);
foreach (array(
    array('PATCH', REST_VIDEOS . $fixtures['sec-pub']),
    array('DELETE', REST_VIDEOS . $fixtures['sec-pub']),
    array('POST', '/fastpix/v1/streams'),
) as $probe) {
    $request  = new WP_REST_Request($probe[0], $probe[1]);
    $response = rest_get_server()->dispatch($request);
    assert(in_array($response->get_status(), array(401, 403), true), $probe[1] . ' answered ' . $response->get_status());
}

// SEC-011: the bulk route is gated EDIT_VIDEO_OWN, but action=delete must still
// demand a DELETE capability — checked at request time, since the background job
// runs with no current user. A user who can edit but not delete their own videos
// must not delete through bulk.
$capns   = '\\Fastpix\\Fastpix_Capabilities';
$del_uid = wp_insert_user(array('user_login' => 'fp_sec_bulkdel', 'user_pass' => wp_generate_password(), 'role' => 'author'));
if (!is_wp_error($del_uid)) {
    $du = new WP_User($del_uid);
    $du->add_cap(constant($capns . '::DELETE_VIDEO_OWN'), false);   // explicit deny overrides the role grant
    $du->add_cap(constant($capns . '::DELETE_VIDEO'), false);
    wp_set_current_user(0);
    wp_set_current_user($del_uid);
    assert(current_user_can(constant($capns . '::EDIT_VIDEO_OWN')), 'the probe user can still edit its own videos');
    assert(!current_user_can(constant($capns . '::DELETE_VIDEO_OWN')), 'but cannot delete');

    $bulk = new WP_REST_Request('POST', '/fastpix/v1/videos/bulk');
    $bulk->set_param('action', 'delete');
    $bulk->set_param('ids', array($fixtures['sec-pub']));
    $verdict = Videos::bulk($bulk);
    assert(is_wp_error($verdict) && (int) ($verdict->get_error_data()['status'] ?? 0) === 403, 'edit-own cannot bulk-delete without a delete capability [SEC-011]');

    // A non-delete bulk action stays allowed for this user.
    $bulk2 = new WP_REST_Request('POST', '/fastpix/v1/videos/bulk');
    $bulk2->set_param('action', 'rerun_ai');
    $bulk2->set_param('ids', array($fixtures['sec-pub']));
    assert(!is_wp_error(Videos::bulk($bulk2)), 'a non-delete bulk action is unaffected by the delete gate');

    wp_set_current_user(0);
    require_once ABSPATH . 'wp-admin/includes/user.php';
    wp_delete_user($del_uid);
}
wp_set_current_user((int) $admins[0]);

// SEC-006: a crafted attribute cannot promote a private video to public —
// policy is re-read server-side; the private render carries a token, never a
// bare unsigned stream URL.
wp_set_current_user((int) $admins[0]);
add_filter('pre_http_request', function ($pre, $_args, $url) {
    if (strpos($url, '/iam/signing-keys') !== false) {
        $rsa = openssl_pkey_new(array('private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA));
        openssl_pkey_export($rsa, $pem);
        return array('headers' => array(), 'body' => wp_json_encode(array('success' => true, 'data' => array('id' => 'kid-sec', 'privateKey' => base64_encode($pem)))), 'response' => array('code' => 200, 'message' => ''));
    }
    return $pre;
}, 9, 3);
$html = Render::shortcode(array('id' => 'sec-priv', 'access' => 'public', 'access_policy' => 'public'));
assert(strpos($html, 'token=') !== false || strpos($html, 'fastpix-embed--message') !== false);   // signed or refused — never open
assert(strpos($html, 'stream.fastpix.io/pb-sec-priv.m3u8"') === false || strpos($html, 'token=') !== false);

/* ------------------------- token leakage: logs + system report clean */

$report = wp_json_encode(Health::system_report());
foreach (array('sk_selfcheck_security', Creds::secret(), 'eyJhbGciOiJSUzI1NiI') as $needle) {
    if ((string) $needle === '') { continue; }
    assert(strpos($report, (string) $needle) === false, 'secret in system report');
}
$log_blob = wp_json_encode($wpdb->get_results('SELECT message FROM ' . Schema::table('logs') . ' ORDER BY id DESC LIMIT 200', ARRAY_A));
assert(strpos((string) $log_blob, 'sk_selfcheck_security') === false, 'secret in logs');

/* ------------------------------ offline outbox drills [WF-015, TEST-025] */

delete_option(Outbox::OPTION);
$down = true;
add_filter('pre_http_request', function ($pre, $_args, $url) use (&$down) {
    if ($down && strpos($url, 'api.fastpix') !== false) {
        return new WP_Error('http_request_failed', 'simulated outage');
    }
    return $pre;
}, 8, 3);

// Availability failure queues; the local save proceeds.
$request = new WP_REST_Request('PATCH', REST_VIDEOS . $fixtures['sec-pub']);
$request->set_param('id', $fixtures['sec-pub']);
$request->set_param('title', 'Renamed while offline');
$response = Videos::update_video($request);
assert(!is_wp_error($response));
assert(Outbox::count() === 1);
assert($wpdb->get_var($wpdb->prepare('SELECT title FROM ' . Schema::table('videos') . ' WHERE id = %d', $fixtures['sec-pub'])) === 'Renamed while offline');

// Order is preserved and the 500 cap refuses new edits with the reason.
$entries = get_option(Outbox::OPTION);
for ($i = Outbox::count(); $i < Outbox::LIMIT; $i++) {
    $entries[] = array('method' => 'PATCH', 'path' => '/on-demand/fill-' . $i, 'body' => array(), 'note' => 'fill', 'at' => time());
}
update_option(Outbox::OPTION, $entries, false);
$refused = Outbox::queue('PATCH', '/on-demand/one-too-many', array(), 'overflow');
assert(is_wp_error($refused) && $refused->get_error_code() === 'fastpix_outbox_full');

// Recovery: platform back → flush drains oldest-first; first entry is the rename.
update_option(Outbox::OPTION, array_slice($entries, 0, 3), false);
$down    = false;
$flushed = array();
add_filter('pre_http_request', function ($pre, $_args, $url) use (&$flushed) {
    if (strpos($url, 'api.fastpix') !== false) {
        $flushed[] = $url;
        return array('headers' => array(), 'body' => wp_json_encode(array('success' => true, 'data' => array())), 'response' => array('code' => 200, 'message' => ''));
    }
    return $pre;
}, 7, 3);
Outbox::flush();
assert(Outbox::count() === 0);
assert(count($flushed) === 3);
assert(strpos($flushed[0], 'sec-pub') !== false);                     // oldest (the rename) went first

echo "test-security: OK\n";
