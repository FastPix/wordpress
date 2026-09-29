<?php
/**
 * Self-check for live streaming — WF-008, UI-004L, FR-070, REQ-070…072,
 * RULE-033/034/040, API-F07/F10, ASSUME-047, TEST-010.
 *
 *   docker compose exec wordpress php -d zend.assertions=1 \
 *     /var/www/html/wp-content/plugins/fastpix/tests/test-live.php
 *
 * The platform is mocked; state transitions run through the real webhook
 * dispatcher; the embed is the real render engine.
 */

require_once __DIR__ . '/bootstrap.php';

use Fastpix\Fastpix_Api_Client as Api;
use Fastpix\Fastpix_Cache as Cache;
use Fastpix\Fastpix_Credentials as Creds;
use Fastpix\Fastpix_Live as Live;
use Fastpix\Fastpix_Render as Render;
use Fastpix\Fastpix_Schema as Schema;
use Fastpix\Fastpix_Webhooks as Webhooks;

const SQL_DELETE_FROM = 'DELETE FROM ';
const STREAM_NAME = 'Town hall';
const SIMULCAST_TARGET_URL = 'rtmps://a.rtmp.youtube.com/live2';
const SQL_WHERE_STREAM_T1 = " WHERE stream_id = 'lv-t1'";
const REST_STREAM_LV_T1 = '/fastpix/v1/streams/lv-t1';
const SQL_SELECT_STATUS_FROM = 'SELECT status FROM ';
const MSG_NOT_AVAILABLE = 'not available';
const REST_STREAMS = '/fastpix/v1/streams';
const REST_STREAM_STATE_LV_T1 = '/fastpix/v1/stream-state/lv-t1';
const MSG_RECORDING_WILL_APPEAR = 'recording will appear';
const SQL_SELECT_COUNT_FROM = 'SELECT COUNT(*) FROM ';

global $wpdb;

$saved = array();
foreach (array(Creds::OPT_TOKEN_ID, Creds::OPT_SECRET, Api::OPT_HEALTH, \Fastpix\Fastpix_Health::OPT_SIGNING_KEY) as $opt) {
    $saved[$opt] = get_option($opt, null);
}
register_shutdown_function(function () use (&$saved) {
    global $wpdb;
    foreach ($saved as $opt => $value) { $value === null ? delete_option($opt) : update_option($opt, $value, false); }
    foreach (get_posts(array('post_status' => 'any', 'title' => 'lv-t live page', 'fields' => 'ids')) as $id) { wp_delete_post($id, true); }   // only left behind when an assertion failed mid-way
    $wpdb->query(SQL_DELETE_FROM . Schema::table('live_streams') . " WHERE stream_id LIKE 'lv-t%'");
    $wpdb->query(SQL_DELETE_FROM . Schema::table('playback_ids') . ' WHERE video_id IN (SELECT id FROM ' . Schema::table('videos') . " WHERE media_id LIKE 'lv-rec-%')");
    $wpdb->query(SQL_DELETE_FROM . Schema::table('videos') . " WHERE media_id LIKE 'lv-rec-%'");
});
Creds::store('4f27c4c4-9a1e-4c2f-9d33-2b8f0a7c1e55', 'sk_selfcheck_live');
delete_option(Api::OPT_HEALTH);
add_filter('fastpix_api_backoff_seconds', '__return_zero');
$wpdb->query(SQL_DELETE_FROM . Schema::table('live_streams') . " WHERE stream_id LIKE 'lv-t%'");

/* ---------------------------------------------------------- platform mock */

function lv_stream_body($overrides = array()) {
    return array_merge(array(
        'streamId'        => 'lv-t1',
        'streamKey'       => 'key-secret-abc',
        'srtSecret'       => 'srt-secret-def',
        'status'          => 'idle',
        'maxResolution'   => '1080p',
        'enableRecording' => true,
        'mediaPolicy'     => 'public',
        'metadata'        => array('name' => STREAM_NAME),
        'playbackIds'     => array(array('id' => 'pb-lv-t1', 'accessPolicy' => 'public')),
    ), $overrides);
}

// A throwaway RSA key stands in for the platform's signing key (private-stream render).
$rsa = openssl_pkey_new(array('private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA));
openssl_pkey_export($rsa, $lv_pem);

function lv_mock_ok($data, $code = 200) {
    return array('headers' => array(), 'body' => wp_json_encode(array('success' => true, 'data' => $data)), 'response' => array('code' => $code, 'message' => ''));
}

function lv_mock_fail($message, $code) {
    return array('headers' => array(), 'body' => wp_json_encode(array('success' => false, 'error' => array('message' => $message))), 'response' => array('code' => $code, 'message' => ''));
}

// Contract probed live 2026-08-26: POST {url, streamKey} answers the target;
// PUT accepts ONLY isEnabled; DELETE removes.
function lv_mock_simulcast($method, $args) {
    $body = json_decode($args['body'], true);

    if ($method === 'POST') {
        $result = lv_mock_ok(array('simulcastId' => 'sim-t1', 'url' => (string) $body['url'], 'streamKey' => (string) $body['streamKey'], 'isEnabled' => true), 201);
    } elseif ($method === 'PUT' && array_diff(array_keys((array) $body), array('isEnabled'))) {
        $result = lv_mock_fail('payload validation failed', 400);
    } elseif ($method === 'PUT') {
        $result = lv_mock_ok(array('simulcastId' => 'sim-t1', 'url' => SIMULCAST_TARGET_URL, 'streamKey' => 'yt-key', 'isEnabled' => !empty($body['isEnabled'])));
    } else {   // DELETE
        $result = lv_mock_ok(array());
    }

    return $result;
}

function lv_mock_stream_response($method, $url, $args, $mock) {
    $body = empty($args['body']) ? null : json_decode($args['body'], true);

    if (strpos($url, '/simulcast') !== false) {
        return lv_mock_simulcast($method, $args);
    }

    if ($method === 'POST') {
        // ASSUME-047: create takes enableRecording in inputMediaSettings and
        // answers with the keys; it never returns ingest addresses.
        $name = (string) ($body['inputMediaSettings']['metadata']['name'] ?? '');
        $result = lv_mock_ok(lv_stream_body(array('metadata' => array('name' => $name), 'enableRecording' => !empty($body['inputMediaSettings']['enableRecording']))), 201);
    } elseif (strpos($url, '/live-enable') !== false || strpos($url, '/live-disable') !== false) {
        $result = lv_mock_ok(array('success' => true));   // verified live 2026-09-09
    } elseif (strpos($url, '/finish') !== false) {
        $result = $mock['finish_refuses'] ? lv_mock_fail('stream cannot be completed', 400) : lv_mock_ok(array('finished' => true));
    } elseif ($method === 'PATCH') {
        $result = lv_mock_ok(lv_stream_body(array('metadata' => array('name' => (string) ($body['metadata']['name'] ?? '')))));
    } elseif ($method === 'DELETE') {
        $result = lv_mock_ok(array());
    } else {
        $result = lv_mock_stream_get($url, $mock);
    }

    return $result;
}

/** GET: one stream's detail, or the list. */
function lv_mock_stream_get($url, $mock) {   // NOSONAR php:S100 — WordPress snake_case naming
    $media = $mock['media_ids'] ?? array();
    if (!preg_match('~/live/streams/([^/?]+)$~', $url)) {
        return lv_mock_ok(array(lv_stream_body(array('status' => $mock['status'], 'mediaIds' => $media))));   // list
    }
    if (!empty($mock['detail_fails'])) {
        return lv_mock_fail('changed upstream', 409);   // a 409 is not retried and never trips the breaker
    }

    return lv_mock_ok(lv_stream_body(array('status' => $mock['status'], 'mediaIds' => $media,
        'playbackIds' => array(array('id' => 'pb-lv-t1', 'accessPolicy' => $mock['policy'])))));
}

$calls = array();
$mock  = array('finish_refuses' => true, 'status' => 'idle', 'policy' => 'public');
add_filter('pre_http_request', function ($_pre, $args, $url) use (&$calls, &$mock, $lv_pem) {
    $method  = strtoupper($args['method']);
    $calls[] = $method . ' ' . preg_replace('~^.*(/live/|/on-demand)~', '$1', $url) . (empty($args['body']) ? '' : ' ' . $args['body']);

    if (strpos($url, '/iam/signing-keys') !== false) {
        return lv_mock_ok(array('id' => 'kid-live-1', 'privateKey' => base64_encode($lv_pem)));
    }
    if (strpos($url, '/live/streams') !== false) {
        return lv_mock_stream_response($method, $url, $args, $mock);
    }

    return lv_mock_ok(array());   // fetch_and_apply etc. — empty is a no-op for sync
}, 10, 3);

/* -------------------------------------------------------------- the flag */

// 2.0.0 ships Live on — judged with site filters (dev mu-plugins) removed; a site can still turn it off.
remove_all_filters('fastpix_feature_live');
assert(Live::enabled() === true);
add_filter('fastpix_feature_live', '__return_false');
assert(Live::enabled() === false);
remove_all_filters('fastpix_feature_live');
add_filter('fastpix_feature_live', '__return_true');

/* ---------------------------------------------------- create: credentials */

// Who can watch (owner 2026-09-22): the same three choices as a video. Private travels as private; DRM is
// refused without the DRM configuration ID from Settings and carries it when there is one.
$drm_opt = \Fastpix\Fastpix_Settings_Page::OPT_DRM_CONFIG_ID; $drm_was = get_option($drm_opt, null);
$mk = function ($access) { $r = new WP_REST_Request('POST', REST_STREAMS); $r->set_param('name', 'access check'); $r->set_param('access', $access); $r->set_param('recording', false); return $r; };
$mark = count($calls);
assert(!is_wp_error(Live::create_stream($mk('private'))));
$sent = implode("\n", array_slice($calls, $mark));
assert(strpos($sent, '"playbackSettings":{"accessPolicy":"private"}') !== false && strpos($sent, '"mediaPolicy":"private"') !== false, 'a private stream is created private, and so is its recording');
// TWO policies (owner 2026-09-22): the broadcast and the recording it becomes gate separately.
$mk2 = function ($access, $media, $rec = true) { $r = new WP_REST_Request('POST', REST_STREAMS); $r->set_param('name', 'two policies'); $r->set_param('access', $access); $r->set_param('media_access', $media); $r->set_param('recording', $rec); return $r; };
$mark = count($calls);
assert(!is_wp_error(Live::create_stream($mk2('private', 'public'))));
$sent = implode("\n", array_slice($calls, $mark));
assert(strpos($sent, '"accessPolicy":"private"') !== false && strpos($sent, '"mediaPolicy":"public"') !== false, 'a private broadcast can leave a public recording');
$mark = count($calls);
assert(!is_wp_error(Live::create_stream($mk('public'))));   // media_access absent
$sent = implode("\n", array_slice($calls, $mark));
assert(strpos($sent, '"mediaPolicy":"public"') !== false, 'with no recording policy sent, the recording follows the broadcast');
$mark = count($calls);
assert(!is_wp_error(Live::create_stream($mk2('private', 'public', false))));
$sent = implode("\n", array_slice($calls, $mark));
assert(strpos($sent, '"mediaPolicy":"private"') !== false && strpos($sent, '"enableRecording":false') !== false, 'recording off: the recording policy is ignored, not sent as something else');

// The BROADCAST takes public | private only — the route refuses drm, so no DRM live body can be built.
$drm_live = rest_get_server()->dispatch($mk('drm'));
assert($drm_live->is_error() && $drm_live->get_status() === 400, 'the live policy has no DRM: the route refuses it');
// DRM belongs to the RECORDING: refused without the configuration id, carried when there is one.
delete_option($drm_opt);
$refused2 = Live::create_stream($mk2('public', 'drm'));
assert(is_wp_error($refused2) && $refused2->get_error_code() === 'fastpix_drm_unconfigured', 'a DRM recording without the configuration id is refused');
update_option($drm_opt, 'drm-config-live-1', false);
$mark = count($calls);
assert(!is_wp_error(Live::create_stream($mk2('public', 'drm'))));
$sent = implode("\n", array_slice($calls, $mark));
assert(strpos($sent, '"accessPolicy":"public"') !== false && strpos($sent, '"mediaPolicy":"drm"') !== false, 'a public broadcast can leave a DRM recording');
if ($drm_was === null) { delete_option($drm_opt); } else { update_option($drm_opt, $drm_was, false); }

// Recording OFF must reach the platform: it defaults enableRecording to true
// when the key is omitted (verified live 2026-09-20), so false has to travel.
$request = new WP_REST_Request('POST', REST_STREAMS);
$request->set_param('name', STREAM_NAME);
$request->set_param('recording', false);
$request->set_param('access', 'public');
assert(!is_wp_error(Live::create_stream($request)), 'a recording-off stream is created');
assert(strpos(implode("\n", $calls), '"enableRecording":false') !== false, 'enableRecording:false is sent, not dropped [X8]');

$request = new WP_REST_Request('POST', REST_STREAMS);
$request->set_param('name', STREAM_NAME);
$request->set_param('recording', true);
$request->set_param('access', 'public');
$response = Live::create_stream($request);
assert(!is_wp_error($response));
$body = $response->get_data();
assert($body['stream_id'] === 'lv-t1');
assert($body['stream_key'] === 'key-secret-abc');                   // REQ-070: read from the response
assert($body['srt_secret'] === 'srt-secret-def');
assert($body['ingest']['rtmps'] === 'rtmps://live.fastpix.com:443/live');   // API-F10 constants
assert($body['ingest']['srt'] === 'srt://live.fastpix.com:778');
assert($body['status'] === 'idle');
assert($body['recording'] === true);
$create_call = implode("\n", $calls);
assert(strpos($create_call, '"enableRecording":true') !== false);   // create-time only [ASSUME-047]

$row = $wpdb->get_row('SELECT * FROM ' . Schema::table('live_streams') . SQL_WHERE_STREAM_T1, ARRAY_A);
assert($row !== null && $row['status'] === 'idle' && $row['name'] === STREAM_NAME && (int) $row['recording_enabled'] === 1);

/* ------------------------------------------------- list: the key never leaks */

$response = Live::list_streams();
$list = $response->get_data();
assert(count($list['streams']) >= 1);
$mine = null;
foreach ($list['streams'] as $s) { if ($s['stream_id'] === 'lv-t1') { $mine = $s; } }
assert($mine !== null);
assert(!array_key_exists('stream_key', $mine) && !array_key_exists('srt_secret', $mine));   // masked list [UI-004L]

/* ------------------------------------------------------------------ detail */

$request = new WP_REST_Request('GET', REST_STREAM_LV_T1);
$request->set_param('id', 'lv-t1');
$detail = Live::get_stream($request)->get_data();
assert($detail['stream_key'] === 'key-secret-abc' && $detail['playback_id'] === 'pb-lv-t1');
assert($detail['playback_policy'] === 'public' && $detail['playback_token'] === '', 'no token for a public / idle stream');

/* ------------------------------------------------------------------ rename */

$request = new WP_REST_Request('PATCH', REST_STREAM_LV_T1);
$request->set_param('id', 'lv-t1');
$request->set_param('name', 'Town hall — renamed');
$patched = Live::update_stream($request);
assert(!is_wp_error($patched) && $patched->get_data()['name'] === 'Town hall — renamed');

/* ------------------------------------------- simulcast [CONFLICT-006 → prototype] */

$request = new WP_REST_Request('POST', '/fastpix/v1/streams/lv-t1/simulcast');
$request->set_param('id', 'lv-t1');
$request->set_param('url', 'https://not-rtmp.example');   // scheme refusal
$request->set_param('stream_key', 'k');
$bad = \Fastpix\Fastpix_Live_Simulcast::add($request);
assert(is_wp_error($bad) && $bad->get_error_data()['status'] === 400);

$request->set_param('url', SIMULCAST_TARGET_URL);
$target = \Fastpix\Fastpix_Live_Simulcast::add($request)->get_data();
assert($target['id'] === 'sim-t1' && $target['enabled'] === true && $target['url'] === SIMULCAST_TARGET_URL);

$request = new WP_REST_Request('PATCH', '/fastpix/v1/streams/lv-t1/simulcast/sim-t1');
$request->set_param('id', 'lv-t1');
$request->set_param('tid', 'sim-t1');
$request->set_param('enabled', false);
$paused = \Fastpix\Fastpix_Live_Simulcast::update($request)->get_data();
assert($paused['enabled'] === false);   // PUT carries only isEnabled
assert(strpos($paused['stream_key'], 'yt-key') === false && strpos($target['stream_key'], 'k') === false, 'a third-party stream key is masked server-side, never in the JSON [X21]');

$request = new WP_REST_Request('DELETE', '/fastpix/v1/streams/lv-t1/simulcast/sim-t1');
$request->set_param('id', 'lv-t1');
$request->set_param('tid', 'sim-t1');
assert(\Fastpix\Fastpix_Live_Simulcast::remove($request)->get_data()['deleted'] === true);

/* -------------------------------------------- finish while idle refuses */

$request = new WP_REST_Request('POST', '/fastpix/v1/streams/lv-t1/finish');
$request->set_param('id', 'lv-t1');
$finish = Live::finish_stream($request);
assert(is_wp_error($finish) && $finish->get_error_data()['status'] === 409);   // ASSUME-047

/* ------------------------------------------- disable / enable (ASSUME-080) */

foreach (array(array('disable', 'disabled'), array('enable', 'idle')) as $pair) {
    $request = new WP_REST_Request('POST', '/fastpix/v1/streams/lv-t1/' . $pair[0]);
    $request->set_param('id', 'lv-t1'); $request->set_param('op', $pair[0]);
    assert(Live::set_enabled($request)->get_data()['status'] === $pair[1]);
    assert($wpdb->get_var(SQL_SELECT_STATUS_FROM . Schema::table('live_streams') . SQL_WHERE_STREAM_T1) === $pair[1], $pair[0] . ' updates the local status');
}
assert(in_array('PUT /live/streams/lv-t1/live-disable', $calls, true) && in_array('PUT /live/streams/lv-t1/live-enable', $calls, true), 'the platform toggles are PUT live-disable / live-enable');

/* X26: no concurrent-viewer route (real-time analytics is out of scope). */
assert(!isset(rest_get_server()->get_routes()['/fastpix/v1/streams/(?P<id>[A-Za-z0-9_-]+)/viewers']));

/* ---------------------- state transitions via the real webhook dispatcher */

Webhooks::dispatch('video.live_stream.connected', array('data' => array('id' => 'lv-t1')));
assert($wpdb->get_var(SQL_SELECT_STATUS_FROM . Schema::table('live_streams') . SQL_WHERE_STREAM_T1) === 'preparing');

// Preparing: the embed is the waiting card, never an error — and it carries
// the poll attributes so the page switches by itself. [WF-008, RULE-034]
$html = Render::render_live('lv-t1');
assert(strpos($html, 'fastpix-embed--waiting') !== false);
assert(strpos($html, MSG_NOT_AVAILABLE) === false);
assert(strpos($html, 'data-fp-stream="lv-t1"') !== false);
assert(strpos($html, '/stream-state/lv-t1') !== false);

// The public stream-state route: status + recording flag, nothing else.
$request = new WP_REST_Request('GET', REST_STREAM_STATE_LV_T1);
$request->set_param('id', 'lv-t1');
$state = Live::stream_state($request)->get_data();
assert($state === array('status' => 'preparing', 'recording' => false));

Webhooks::dispatch('video.live_stream.active', array('data' => array('id' => 'lv-t1')));
assert($wpdb->get_var(SQL_SELECT_STATUS_FROM . Schema::table('live_streams') . SQL_WHERE_STREAM_T1) === 'active');

// Live: the real player, live-stream type, auto-play muted, no token (public).
// The vendored player reads `auto-play` (not `autoplay`).
Cache::flush_group('live');
$html = Render::render_live('lv-t1');
assert(strpos($html, 'stream-type="live-stream"') !== false);
assert(strpos($html, 'playback-id="pb-lv-t1"') !== false);
assert(strpos($html, 'auto-play muted') !== false);
assert(strpos($html, ' token="') === false);
assert(strpos($html, 'data-fp-config') === false, 'a public stream needs no token, so it carries no config URL');

// Per-embed options reach the live player: nocontrols hides the bar, accent applies.
$opts = Render::render_live('lv-t1', array('controls' => false, 'accentColour' => '#ff0000'));
assert(strpos($opts, '--controls:none') !== false, 'nocontrols hides the live control bar');
assert(strpos($opts, 'accent-color="#ff0000"') !== false, 'accent reaches the live player');
assert(!defined('DONOTCACHEPAGE'), 'a public live render leaves page caching alone');

// A transient API error while resolving the playback id renders the warming-up
// card but is NOT cached as "no playback id" for five minutes. [X19]
Cache::flush_group('live');
$mock['detail_fails'] = true;
assert(strpos(Render::render_live('lv-t1'), 'warming up') !== false, 'an API error renders the warming-up card');
$mock['detail_fails'] = false;
assert(strpos(Render::render_live('lv-t1'), 'playback-id="pb-lv-t1"') !== false, 'the error verdict was not cached — the next render resolves the player [X19]');

// A private stream inherits protection: the live player carries a signed
// token, same contract as recorded video. [RULE-033, REQ-071]
$mock['policy'] = 'private';
Cache::flush_group('live');
$html = Render::render_live('lv-t1');
assert(strpos($html, ' token="') !== false);
// RULE-014: a token in the markup must never be page-cached — the private live
// render marks the page no-store so a fresh token is minted on every load.
assert(defined('DONOTCACHEPAGE') && DONOTCACHEPAGE, 'a private live render disables page caching for the token');
// …and the same renew/recover hook as recorded video: the player-config URL
// (naming the stream — the streams table stores no playback id) + the expiry. [QA L7/L8]
assert(preg_match('~data-fp-config="([^"]+/player-config/pb-lv-t1[^"]*stream=lv-t1)"~', $html, $m) === 1, 'a private live player carries its config URL');
assert(strpos($m[1], 'nonce') === false && strpos($html, 'data-fp-exp="') !== false, 'the config URL is not itself something that expires');
$lv_config = function ($playback, $stream = 'lv-t1') {
    $r = new WP_REST_Request('GET', '/fastpix/v1/player-config/' . $playback);
    $r->set_query_params(array('stream' => $stream));

    return rest_get_server()->dispatch($r);
};
$lv_admin = (int) current(get_users(array('role' => 'administrator', 'number' => 1, 'fields' => 'ID')));
$lv_was   = get_current_user_id();
wp_set_current_user($lv_admin);
$got = $lv_config('pb-lv-t1');
assert($got->get_status() === 200 && array_keys($got->get_data()) === array('playback_id', 'access_policy', 'token', 'drm_token', 'exp'), 'the route answers a token set — and nothing else — for the private stream');
assert(substr_count($got->get_data()['token'], '.') === 2 && $got->get_data()['exp'] > time() && strpos((string) $got->get_headers()['Cache-Control'], 'no-store') !== false);
assert($lv_config('pb-unknown')->get_status() === 404, 'an unknown playback id is 404');
assert($lv_config('pb-lv-t1', 'lv-nope')->get_status() === 404, 'an unknown stream is 404');
// SEC-014: a visitor gets tokens only through a post that renders the embed and that they can read.
wp_set_current_user(0);
assert($lv_config('pb-lv-t1')->get_status() === 403, 'no embedding post — a visitor is refused');
$lv_post = wp_insert_post(array('post_title' => 'lv-t live page', 'post_status' => 'publish', 'post_content' => '[fastpix streamid="lv-t1"]'));
$GLOBALS['wp_query'] = new WP_Query(array('p' => $lv_post));
while (have_posts()) { the_post(); Render::render_live('lv-t1'); }
wp_reset_query();
assert(get_post_meta($lv_post, \Fastpix\Fastpix_Render_Live::META_LIVE) === array('lv-t1'), 'a private live render in the loop files the post');
assert($lv_config('pb-lv-t1')->get_status() === 200, 'a visitor who can read the embedding post gets tokens');
wp_update_post(array('ID' => $lv_post, 'post_content' => 'embed removed'));
assert($lv_config('pb-lv-t1')->get_status() === 403, 'a post that dropped the embed stops vouching for it');
wp_delete_post($lv_post, true);
wp_set_current_user($lv_admin);
$mock['policy'] = 'public';
Cache::flush_group('live');
assert($lv_config('pb-lv-t1')->get_status() === 404, 'a public stream has no token set to hand out');
$mock['policy'] = 'private';
Cache::flush_group('live');
wp_set_current_user($lv_was);
// The library's preview rail plays the live stream in place: the (admin-only)
// detail carries the policy and, for a private live stream, the same token.
$mock['status'] = 'active';   // the detail fetch re-syncs from the platform, so the mock must agree
$request = new WP_REST_Request('GET', REST_STREAM_LV_T1);
$request->set_param('id', 'lv-t1');
$detail = Live::get_stream($request)->get_data();
assert($detail['playback_policy'] === 'private' && $detail['playback_token'] !== '', 'private live detail mints the preview token');
// QA report #11: the details panel must read the field this route sends. It read `s.access`, which
// nothing sends, so a private stream was labelled "Anyone (public)".
$lib_js = (string) file_get_contents(FASTPIX_PLUGIN_DIR . 'assets/js/library-page.js');
assert(strpos($lib_js, 's.playback_policy') !== false && strpos($lib_js, "s.access === 'private'") === false, 'the stream panel shows playback_policy, not a field the server never sends');
$mock['status'] = 'idle';
$mock['policy'] = 'public';
Cache::flush_group('live');

Webhooks::dispatch('video.live_stream.disconnected', array('data' => array('id' => 'lv-t1')));
assert($wpdb->get_var(SQL_SELECT_STATUS_FROM . Schema::table('live_streams') . SQL_WHERE_STREAM_T1) === 'ended');

// Ended, no recording yet: honest message, never error-shaped. [TEST-010]
$html = Render::render_live('lv-t1');
assert(strpos($html, 'stream has ended') !== false);
assert(strpos($html, MSG_NOT_AVAILABLE) === false);
assert(strpos($html, MSG_RECORDING_WILL_APPEAR) !== false, 'recording on: the ended card promises the recording');

// Recording OFF: the ended card promises nothing. [X6]
$wpdb->update(Schema::table('live_streams'), array('recording_enabled' => 0), array('stream_id' => 'lv-t1'));
$html = Render::render_live('lv-t1');
assert(strpos($html, 'stream has ended') !== false && strpos($html, MSG_RECORDING_WILL_APPEAR) === false, 'recording off: no recording is promised [X6]');
$wpdb->update(Schema::table('live_streams'), array('recording_enabled' => 1), array('stream_id' => 'lv-t1'));

// The platform reports "idle" again once a broadcast ends (verified live
// 2026-09-20): neither the list refresh nor a rename may regress the
// webhook-owned "ended" to "idle · never streamed". [X9, X7]
Live::refresh_from_platform();   // the mock list answers status idle
assert($wpdb->get_var(SQL_SELECT_STATUS_FROM . Schema::table('live_streams') . SQL_WHERE_STREAM_T1) === 'ended', 'a platform idle after a broadcast stays ended [X9]');
$request = new WP_REST_Request('PATCH', REST_STREAM_LV_T1);
$request->set_param('id', 'lv-t1');
$request->set_param('name', 'Town hall — ended');
assert(Live::update_stream($request)->get_data()['status'] === 'ended', 'a rename (PATCH answers the full object, status idle) keeps ended [X7]');
// The direct idle webhook after a broadcast maps the same way; created/updated
// on a never-streamed stream stamp no last_active_at, so it stays idle. [X9]
Webhooks::dispatch('video.live_stream.idle', array('data' => array('id' => 'lv-t1')));
assert($wpdb->get_var(SQL_SELECT_STATUS_FROM . Schema::table('live_streams') . SQL_WHERE_STREAM_T1) === 'ended', 'an idle webhook after a broadcast stays ended [X9]');
Webhooks::dispatch('video.live_stream.created', array('data' => array('id' => 'lv-t9', 'status' => 'idle')));
Webhooks::dispatch('video.live_stream.updated', array('data' => array('id' => 'lv-t9', 'status' => 'idle')));
$never = $wpdb->get_row('SELECT status, last_active_at FROM ' . Schema::table('live_streams') . " WHERE stream_id = 'lv-t9'", ARRAY_A);
assert($never['status'] === 'idle' && $never['last_active_at'] === null, 'a never-streamed stream stays idle, not ended [X9]');
$wpdb->delete(Schema::table('live_streams'), array('stream_id' => 'lv-t9'));

/* --------------------------- recording hand-off: filed, linked, embedded */

$now = current_time('mysql', true);
$wpdb->insert(Schema::table('videos'), array(
    'media_id' => 'lv-rec-1', 'workspace_id' => 'ws-lv', 'title' => 'Town hall (recording)', 'status' => 'Ready',
    'source' => 'Dashboard', 'access_policy' => 'public', 'author_id' => 1, 'created_at' => $now, 'updated_at' => $now,
));
$rec_id = (int) $wpdb->insert_id;
$wpdb->insert(Schema::table('playback_ids'), array('video_id' => $rec_id, 'playback_id' => 'pb-lv-rec-1', 'access_policy' => 'public', 'created_at' => $now, 'updated_at' => $now));

Webhooks::dispatch('video.live_stream.recording.ready', array('data' => array('id' => 'lv-t1', 'mediaId' => 'lv-rec-1')));
$row = $wpdb->get_row('SELECT * FROM ' . Schema::table('live_streams') . SQL_WHERE_STREAM_T1, ARRAY_A);
assert((int) $row['recorded_video_id'] === $rec_id);                // REQ-072: linked
assert($wpdb->get_var($wpdb->prepare('SELECT source FROM ' . Schema::table('videos') . ' WHERE id = %d', $rec_id)) === 'Live');   // WF-008: source "Live"

$shaped = Live::shape($row);
assert($shaped['recorded_video']['media_id'] === 'lv-rec-1');

// A live clip files in the library too, but never replaces the recording link.
$wpdb->insert(Schema::table('videos'), array(
    'media_id' => 'lv-rec-2', 'workspace_id' => 'ws-lv', 'title' => 'Town hall (clip)', 'status' => 'Ready',
    'source' => 'Dashboard', 'access_policy' => 'public', 'author_id' => 1, 'created_at' => $now, 'updated_at' => $now,
));
$clip_id = (int) $wpdb->insert_id;
Webhooks::dispatch('video.media.live_clip.created', array('data' => array('id' => 'lv-t1', 'mediaId' => 'lv-rec-2')));
assert((int) $wpdb->get_var("SELECT recorded_video_id FROM " . Schema::table('live_streams') . SQL_WHERE_STREAM_T1) === $rec_id);
assert($wpdb->get_var($wpdb->prepare('SELECT source FROM ' . Schema::table('videos') . ' WHERE id = %d', $clip_id)) === 'Live');

// QA report #12 — "after the stream is completed the recording is not available on the page".
// (1) The DOCUMENTED recording event (fastpix.com/docs/webhooks/media-events): video.media.live_stream.created,
//     object = the MEDIA, data.id = the media id, data.streamId = the stream. Reading `id` first filed the media
//     id as a stream and the real stream never got its recording.
$unlink = function () use ($wpdb) { $wpdb->query('UPDATE ' . Schema::table('live_streams') . " SET recorded_video_id = NULL WHERE stream_id = 'lv-t1'"); delete_transient('fastpix_rec_lookup_' . md5('lv-t1')); Cache::flush_group('live'); Cache::flush_group('embed'); };
$linked = function () use ($wpdb) { return (int) $wpdb->get_var('SELECT recorded_video_id FROM ' . Schema::table('live_streams') . SQL_WHERE_STREAM_T1); };
$unlink();
Webhooks::dispatch('video.media.live_stream.created', array(
    'type' => 'video.media.live_stream.created', 'object' => array('type' => 'media', 'id' => 'lv-rec-1'), 'status' => 'media_created',
    'data' => array('id' => 'lv-rec-1', 'streamId' => 'lv-t1', 'status' => 'Created', 'maxResolution' => '1080p',
        'playbackIds' => array(array('id' => 'pb-lv-rec-1', 'accessPolicy' => 'public'))),
));
assert($linked() === $rec_id, 'the documented recording event links the recording to ITS stream');
assert((int) $wpdb->get_var(SQL_SELECT_COUNT_FROM . Schema::table('live_streams') . " WHERE stream_id = 'lv-rec-1'") === 0, 'and the media id is never filed as a stream');
assert($wpdb->get_var(SQL_SELECT_STATUS_FROM . Schema::table('live_streams') . SQL_WHERE_STREAM_T1) !== 'idle', 'a media event does not touch the stream\'s own state');

// (2) No webhook at all: the stream object lists its recordings (`mediaIds`, docs: Get live stream by ID) —
//     the list/detail refresh links the newest one.
$unlink();
$mock['media_ids'] = array('lv-rec-1');   // NOSONAR php:S4143 — read through the by-ref pre_http_request closure
Live::refresh_from_platform();
assert($linked() === $rec_id, 'the stream refresh links the recording from mediaIds');

// (3) Nobody opens the Live tab either: the ended embed looks the recording up itself (throttled) instead
//     of promising "will appear here" forever.
$unlink();
$wpdb->update(Schema::table('live_streams'), array('status' => 'ended', 'recording_enabled' => 1), array('stream_id' => 'lv-t1'));
$html = Render::render_live('lv-t1');
assert(strpos($html, 'pb-lv-rec-1') !== false && strpos($html, MSG_RECORDING_WILL_APPEAR) === false, 'an ended embed finds and plays its recording by itself');
$unlink();
$mock['media_ids'] = array();   // NOSONAR php:S4143 — read through the by-ref pre_http_request closure
$html = Render::render_live('lv-t1');
assert(strpos($html, MSG_RECORDING_WILL_APPEAR) !== false, 'no recording on the platform yet: the honest waiting card');
$before = count($calls);
Render::render_live('lv-t1');
assert(count(array_filter(array_slice($calls, $before), function ($c) { return strpos($c, 'GET /live/streams/lv-t1') === 0; })) === 0, 'the look-up is throttled: a second render asks nothing');
// (4) A visitor already on the waiting card polls /stream-state: it finds the recording too, so the page switches by itself.
$mock['media_ids'] = array('lv-rec-1'); $unlink();   // NOSONAR php:S4143 — read through the by-ref pre_http_request closure
$sreq = new WP_REST_Request('GET', REST_STREAM_STATE_LV_T1); $sreq->set_param('id', 'lv-t1');
assert(Live::stream_state($sreq)->get_data() === array('status' => 'ended', 'recording' => true) && $linked() === $rec_id, 'the public state poll links and reports the recording');
$mock['media_ids'] = array('lv-rec-1'); $unlink(); Live::refresh_from_platform();   // leave it linked for the sections below   // NOSONAR php:S4143 — read through the by-ref pre_http_request closure
assert($linked() === $rec_id);

// Sweep repair: the 15-minute sweep re-reads stream state. [WF-009]
Live::boot();
assert((bool) has_action('fastpix_new_media_sweep', array('Fastpix\\Fastpix_Live', 'refresh_from_platform')));

// The webhook 'recording' needle maps to active — end it again, then the
// embed must switch to the recording. [WF-008 lifecycle]
Webhooks::dispatch('video.live_stream.disconnected', array('data' => array('id' => 'lv-t1')));
$html = Render::render_live('lv-t1');
assert(strpos($html, 'pb-lv-rec-1') !== false);                     // plays the recording
// The recording embed keeps polling (under its own attribute, so the current
// player.js ignores it) so a RE-USED stream can switch back to live. [X5]
assert(strpos($html, 'data-fp-recording-of="lv-t1"') !== false && strpos($html, '/stream-state/lv-t1') !== false, 'the recording embed carries the state poll [X5]');
assert(strpos($html, 'data-fp-stream="lv-t1"') === false, 'but not as data-fp-stream — that would reload once per session on today\'s player.js');

/* ------------------------------------------- flag-off gating [RULE-040] */

// With fastpix_feature_live off, Fastpix_Live never boots, so the
// /stream-state polling route is unregistered — a live embed would render but
// never self-switch. The render path must degrade to the neutral fallback and
// the webhook path must ignore stream events (a flag-flip re-syncs from the
// platform via refresh_from_platform).
remove_all_filters('fastpix_feature_live');
add_filter('fastpix_feature_live', '__return_false');

$off = Render::render_live('lv-t1');   // lv-t1 is still valid here (deleted below)
assert(strpos($off, 'data-fp-state-url') === false && strpos($off, 'stream-type="live-stream"') === false, 'no live embed rendered while the flag is off');
assert(strpos($off, MSG_NOT_AVAILABLE) !== false, 'render degrades to the neutral fallback while off [RULE-040]');

$before = (int) $wpdb->get_var(SQL_SELECT_COUNT_FROM . Schema::table('live_streams') . " WHERE stream_id = 'lv-off-1'");
Webhooks::dispatch('video.live_stream.created', array('data' => array('id' => 'lv-off-1', 'status' => 'idle')));
$after = (int) $wpdb->get_var(SQL_SELECT_COUNT_FROM . Schema::table('live_streams') . " WHERE stream_id = 'lv-off-1'");
assert($after === $before, 'a stream webhook is ignored while the flag is off [RULE-040]');

remove_all_filters('fastpix_feature_live');
add_filter('fastpix_feature_live', '__return_true');   // restore for the remaining assertions

/* ------------------------------------------------------------------ delete */

$request = new WP_REST_Request('DELETE', REST_STREAM_LV_T1);
$request->set_param('id', 'lv-t1');
assert(Live::delete_stream($request)->get_data()['deleted'] === true);
assert($wpdb->get_var('SELECT deleted_at FROM ' . Schema::table('live_streams') . SQL_WHERE_STREAM_T1) !== null);
$list = Live::list_streams()->get_data();
foreach ($list['streams'] as $s) { assert($s['stream_id'] !== 'lv-t1'); }   // tombstoned rows never listed

// The delete dialog promises "pages show the recording if one exists, or
// nothing": a deleted stream still renders its recording (no poll), and
// reads "not available" without one. [X3]
$html = Render::render_live('lv-t1');
assert(strpos($html, 'pb-lv-rec-1') !== false && strpos($html, 'data-fp-state-url') === false, 'a deleted stream keeps its recording renderable [X3]');
$wpdb->update(Schema::table('live_streams'), array('recorded_video_id' => null), array('stream_id' => 'lv-t1'));
assert(strpos(Render::render_live('lv-t1'), MSG_NOT_AVAILABLE) !== false, 'a deleted stream without a recording is not available');
$state = Live::stream_state((function () { $r = new WP_REST_Request('GET', REST_STREAM_STATE_LV_T1); $r->set_param('id', 'lv-t1'); return $r; })());
assert(is_wp_error($state) && $state->get_error_data()['status'] === 404, 'the public state route answers 404 for a deleted or unknown stream [B5]');

echo "test-live: OK\n";
