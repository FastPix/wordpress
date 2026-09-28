<?php
/**
 * Self-check for the upload engine + URL ingestion — WF-002/003, FR-010…014,
 * RULE-005/008/009, SEC-010, TEST-002/003.
 *
 *   docker compose exec wordpress php -d zend.assertions=1 \
 *     /var/www/html/wp-content/plugins/fastpix/tests/test-uploads.php
 */

require_once __DIR__ . '/bootstrap.php';

use Fastpix\Fastpix_Api_Client as Client;
use Fastpix\Fastpix_Connection as Connection;
use Fastpix\Fastpix_Credentials as Creds;
use Fastpix\Fastpix_Schema as Schema;
use Fastpix\Fastpix_Settings_Page as Settings;
use Fastpix\Fastpix_Uploads as Uploads;
use Fastpix\Fastpix_Webhooks as Webhooks;

const SQL_DELETE_FROM = 'DELETE FROM ';
const CONTENT_TYPE_MP4 = 'video/mp4';
const REST_UPLOADS = '/uploads';
const REST_UPLOADS_SLASH = '/uploads/';
const REASON_PRIVATE_OR_RESERVED = 'local or private';
const REST_VIDEOS = '/videos';
const REST_CANCEL = '/cancel';
const RES_1080P = '1080p';
const SESSION_URI = 'https://storage.example.com/bucket/o?upload_id=sess-abc';
const TITLE_JUNE_WEBINARS = 'June webinars';
const MYSQL_DATETIME = 'Y-m-d H:i:s';
const CONTENT_TYPE_PNG = 'image/png';
const WATERMARK_URL = 'https://4.4.4.4/logo.png';   // NOSONAR php:S1313 — SSRF-guard fixture address
const REST_WATERMARK_CHECK = '/uploads/watermark-check';
const MULTI_ADDRESS_URL = 'https://multi.example.com/logo.png';
const PUBLIC_IP = '93.184.216.34';   // NOSONAR php:S1313 — SSRF-guard fixture address
const REST_UPLOADS_STATUS = '/fastpix/v1/uploads/status';

global $wpdb;

$saved = array();
foreach (array(Creds::OPT_TOKEN_ID, Creds::OPT_SECRET, Client::OPT_HEALTH, 'fastpix_workspace_seen_id', 'fastpix_workspace_seen_name',   // the learned workspace must survive fixtures
             Connection::OPT_PENDING_LEAVE, Connection::OPT_LEFT_UNKNOWN, Connection::OPT_LAST_TOKEN, Connection::OPT_LAST_KEY,        // the leave machinery must never fire on real data
             Settings::OPT_DRM_CONFIG_ID, Webhooks::OPT_SECRET) as $opt) {                                                              // the DRM id and webhook secret are toggled below
    $saved[$opt] = get_option($opt, null);
}
// Restore even when a failed assertion aborts the run — a leaked fixture pair once shadowed the live one.
register_shutdown_function(function () use (&$saved) {
    foreach ($saved as $opt => $value) { $value === null ? delete_option($opt) : update_option($opt, $value, false); }
});
foreach (array(Connection::OPT_PENDING_LEAVE, Connection::OPT_LEFT_UNKNOWN, Connection::OPT_LAST_TOKEN, Connection::OPT_LAST_KEY) as $opt) { delete_option($opt); }   // learn_workspace can never wipe
Creds::store('4f27c4c4-9a1e-4c2f-9d33-2b8f0a7c1e55', 'sk_selfcheck_uploads');
delete_option(Client::OPT_HEALTH);
delete_transient(Client::TRANSIENT_BREAKER);

// Transport mock: platform session/ingest calls + the SSRF HEAD probes.
function up_mock_head_response($mock, $url) {
    foreach ($mock['head'] as $needle => $resp) {
        if (strpos($url, $needle) !== false) {
            return $resp;
        }
    }

    return array('headers' => array('content-type' => CONTENT_TYPE_MP4), 'body' => '', 'response' => array('code' => 200, 'message' => ''));
}

/** The SSRF gate's HEAD, or its GET fallback (QA U13): answers like HEAD unless told otherwise. */
function up_mock_probe($mock, $method, $url) {
    if ($method === 'GET') {
        foreach ($mock['get'] as $needle => $resp) {
            if (strpos($url, $needle) !== false) {
                return $resp;
            }
        }
    }

    return up_mock_head_response($mock, $url);
}

$mock = array('head' => array(), 'get' => array(), 'requests' => array());
add_filter('pre_http_request', function ($_pre, $args, $url) use (&$mock) {
    $mock['requests'][] = array('url' => $url, 'method' => $args['method'], 'body' => isset($args['body']) ? $args['body'] : null);

    $is_probe = $args['method'] === 'HEAD' || ($args['method'] === 'GET' && strpos($url, 'api.fastpix.com') === false);
    if ($is_probe) {
        return up_mock_probe($mock, $args['method'], $url);
    }

    // GET /on-demand/{id} for a media the test is watching — the status the platform reports right now.
    if (!empty($mock['media_status']) && preg_match('~/on-demand/([^/?]+)$~', $url, $m) && $args['method'] === 'GET') {
        $mock['media_reads'][] = $m[1];
        return array('headers' => array(), 'body' => wp_json_encode(array('data' => array(
            'id' => $m[1], 'status' => $mock['media_status'], 'workspaceId' => 'ws-up', 'updatedAt' => gmdate('c'),
            'playbackIds' => array(array('id' => 'pb-' . $m[1], 'accessPolicy' => 'public')),
        ))), 'response' => array('code' => 200, 'message' => ''));
    }

    $upload_id = isset($mock['upload_id']) ? $mock['upload_id'] : 'up-plat-1';
    $data = strpos($url, '/on-demand/upload') !== false
        ? array('uploadId' => $upload_id, 'url' => 'https://storage.example.com/signed-abc', 'timeout' => 14400)
        : array('id' => 'ing-media-1', 'status' => 'Created', 'workspaceId' => 'ws-up', 'updatedAt' => '2026-08-14T08:00:00Z');
    return array('headers' => array(), 'body' => wp_json_encode(array('data' => $data)), 'response' => array('code' => 200, 'message' => ''));
}, 10, 3);
add_filter('fastpix_api_backoff_seconds', '__return_zero');

$admins = get_users(array('role' => 'administrator', 'number' => 1, 'fields' => 'ID'));
wp_set_current_user($admins[0]);

function upreq($method, $path, $body = null) {
    $request = new WP_REST_Request($method, '/fastpix/v1' . $path);
    if ($body !== null) {
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(wp_json_encode($body));
    }

    return rest_get_server()->dispatch($request);
}

$uploads_table = Schema::table('uploads');
$wpdb->query("DELETE FROM {$uploads_table} WHERE filename LIKE 'upcheck-%'");

// ------------------------------------------------------------ session create

$response = upreq('POST', REST_UPLOADS, array(
    'filename' => 'upcheck-talk.mp4', 'filesize' => 1073741824, 'filetype' => CONTENT_TYPE_MP4,
    'settings' => array('access_policy' => 'private', 'quality_tier' => 'pro'),
));
assert(!$response->is_error(), 'a valid file gets a session [WF-002]');
$session = $response->get_data();
assert($session['signed_url'] === 'https://storage.example.com/signed-abc', 'the signed URL is returned');
assert($session['chunk_size'] === 16777216, '16 MB chunks [REQ-012]');

$row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$uploads_table} WHERE id = %d", $session['id']), ARRAY_A);
assert($row['state'] === 'uploading', 'the row tracks the session');
assert($row['upload_id'] === 'up-plat-1', 'bound to the platform session');
// Two creates in flight at once used to share the '' placeholder on the UNIQUE upload_id: the second
// INSERT failed, its platform session was still made, and the client PATCHed /uploads/0. [QA F2]
$mock['upload_id'] = 'up-plat-1b';
$second = upreq('POST', REST_UPLOADS, array('filename' => 'upcheck-second.mp4', 'filesize' => 5000, 'filetype' => CONTENT_TYPE_MP4));
assert(!$second->is_error() && (int) $second->get_data()['id'] > (int) $session['id'], 'a second session gets its own row');
assert((int) $wpdb->get_var("SELECT COUNT(*) FROM {$uploads_table} WHERE upload_id LIKE 'pending:%'") === 0, 'no row keeps the placeholder once the platform answered');
upreq('POST', REST_UPLOADS_SLASH . $second->get_data()['id'] . REST_CANCEL);
unset($mock['upload_id']);
$settings = json_decode($row['settings_json'], true);
assert($settings['access_policy'] === 'private' && $settings['quality_tier'] === 'pro', 'the batch snapshot rides along [FR-013]');
assert($settings['chapters'] === true && $settings['summary'] === 'medium', 'REQ-017 defaults fill the gaps');
// The platform states its window (`timeout`, 4 h live) — not an assumed hour. (QA U6)
assert(abs(strtotime($row['url_expires_at'] . ' UTC') - (time() + 14400)) < 120, 'the signed URL expiry follows the platform timeout');
// Every settings knob is clamped — the routes take `settings` as a bare object. (QA U17)
$snap = \Fastpix\Fastpix_Uploads_Settings::settings_snapshot(array('quality_tier' => 'ultra', 'max_resolution' => '4k', 'subtitles' => 'xx', 'summary' => 'huge', 'moderation' => 'yes', 'chapters' => 'no'));
assert($snap['quality_tier'] === 'standard' && $snap['max_resolution'] === RES_1080P && $snap['subtitles'] === 'off' && $snap['summary'] === 'medium' && $snap['moderation'] === 'on' && $snap['chapters'] === true, 'unknown tier/resolution/language/summary fall back; flags are booleans');
assert(\Fastpix\Fastpix_Uploads_Settings::settings_snapshot(array('subtitles' => 'fr'))['subtitles'] === 'fr', 'a platform language survives');
// Accepted formats are lowercase throughout — the browsers compare file.type lowercased against the list verbatim. (QA U7)
$mimes = \Fastpix\Fastpix_Uploads_Settings::mime_config();
assert($mimes['mime_types'] === array_map('strtolower', $mimes['mime_types']) && in_array('video/mp2t', $mimes['mime_types'], true), 'mime list is lowercase (.ts / .m3u8 pass the client check)');
// UI-004 mockup controls: the extra knobs are recorded; DRM is a valid platform policy.
$snap = \Fastpix\Fastpix_Uploads_Settings::settings_snapshot(array('access_policy' => 'drm', 'domain_lock' => false, 'normalize_audio' => false, 'trim' => true));
assert($snap['access_policy'] === 'drm' && $snap['domain_lock'] === false && $snap['normalize_audio'] === false && !isset($snap['trim']), 'mockup settings ride along; trim is not a setting (AMBIG-007)');
$snap = \Fastpix\Fastpix_Uploads_Settings::settings_snapshot(array('access_policy' => 'drm', 'downloadable' => 'video'));
assert($snap['downloadable'] === 'off', 'DRM forces downloads off — DRM renditions are never static MP4s');
assert(\Fastpix\Fastpix_Uploads_Settings::settings_snapshot(array('access_policy' => 'signed'))['access_policy'] === 'public', 'unknown policy falls back to public');
// DRM policy needs the Settings-screen configuration id; without it the session is refused before any call.
delete_option(\Fastpix\Fastpix_Settings_Page::OPT_DRM_CONFIG_ID);
$m = new ReflectionMethod('Fastpix\\Fastpix_Uploads', 'platform_media_settings'); $m->setAccessible(true);
$r = $m->invoke(null, array('access_policy' => 'drm', 'max_resolution' => RES_1080P));
assert(is_wp_error($r) && $r->get_error_code() === 'fastpix_drm_unconfigured', 'DRM without a configuration id is refused with a reason');
update_option(\Fastpix\Fastpix_Settings_Page::OPT_DRM_CONFIG_ID, '3fa85f64-5717-4562-b3fc-2c963f66afa6', false);
$r = $m->invoke(null, array('access_policy' => 'drm', 'max_resolution' => RES_1080P));
assert($r['accessPolicy'] === 'drm' && $r['drmConfigurationId'] === '3fa85f64-5717-4562-b3fc-2c963f66afa6', 'DRM carries the saved configuration id');
assert(!isset($m->invoke(null, array('access_policy' => 'public'))['drmConfigurationId']), 'public carries no DRM id');
// Downloadable file rides as mp4Support at creation — real enum (docs 2026-08-19); never with DRM.
assert($m->invoke(null, array('access_policy' => 'public', 'downloadable' => 'both'))['mp4Support'] === 'audioOnly,capped_4k', 'Video + audio → audioOnly,capped_4k');
assert($m->invoke(null, array('access_policy' => 'public', 'downloadable' => 'video'))['mp4Support'] === 'capped_4k', 'Video → capped_4k');
assert(!isset($m->invoke(null, array('access_policy' => 'public', 'downloadable' => 'off'))['mp4Support']), 'Off sends nothing');
assert(!isset($m->invoke(null, array('access_policy' => 'drm', 'downloadable' => 'video'))['mp4Support']), 'DRM never asks for downloads');
// Quality tier and "Even out volume" reach the platform: mediaQuality + optimizeAudio (verified live 2026-09-20). (QA U5)
$pm = $m->invoke(null, array('access_policy' => 'public', 'quality_tier' => 'premium', 'normalize_audio' => true));
assert($pm['mediaQuality'] === 'premium' && $pm['optimizeAudio'] === true, 'tier and audio normalisation ride along');
$pm = $m->invoke(null, array('access_policy' => 'public', 'quality_tier' => 'bogus'));
assert($pm['mediaQuality'] === 'standard' && $pm['optimizeAudio'] === false, 'an unknown tier falls back to standard (the platform 422s anything else); audio off by default');
assert(\Fastpix\Fastpix_Uploads_Settings::settings_snapshot(array('downloadable' => '720p'))['downloadable'] === 'off', 'unknown download choices fall back to off');

// ------------------------------------------------ watermark (create-time input)
// Burned in during encoding, so it is settled at creation like the access policy. Direct upload
// carries the watermark ALONE — the video is the file being pushed, so there is no video input.
$mock['head']['4.4.4.4'] = array('headers' => array('content-type' => CONTENT_TYPE_PNG), 'body' => '', 'response' => array('code' => 200, 'message' => ''));
$wmp = function ($extra) use ($m) {
    $snap = \Fastpix\Fastpix_Uploads_Settings::settings_snapshot(array_merge(array('watermark_url' => WATERMARK_URL), $extra));
    return $m->invoke(null, $snap)['inputs'][0];
};
$wm = $m->invoke(null, array('access_policy' => 'public', 'watermark_url' => WATERMARK_URL));
assert(isset($wm['inputs']) && count($wm['inputs']) === 1 && $wm['inputs'][0]['type'] === 'watermark'
    && $wm['inputs'][0]['url'] === WATERMARK_URL && !isset($wm['inputs'][0]['height']), 'the watermark rides as a single input, with no video entry and no height');
$in = $wmp(array());
assert($in['placement'] === array('xAlign' => 'left', 'xMargin' => '6%', 'yAlign' => 'top', 'yMargin' => '11%')
    && $in['width'] === '10%' && $in['opacity'] === '65%', 'defaults: top left / Normal / Medium / 65% → xMargin 6%, yMargin 11% (letterbox clearance)');
$in = $wmp(array('watermark_pos' => 'bottom-right'));
assert($in['placement'] === array('xAlign' => 'right', 'xMargin' => '6%', 'yAlign' => 'bottom', 'yMargin' => '14%'), 'bottom right / Normal → yMargin 14% clears the control row');
$in = $wmp(array('watermark_pos' => 'middle-left', 'watermark_margin' => '3%'));
assert($in['placement'] === array('xAlign' => 'left', 'xMargin' => '3%', 'yAlign' => 'middle'), 'middle → no yMargin');
$in = $wmp(array('watermark_pos' => 'top-center', 'watermark_margin' => '10%', 'watermark_size' => '20%', 'watermark_opacity' => '100%'));
assert($in['placement'] === array('xAlign' => 'center', 'yAlign' => 'top', 'yMargin' => '15%') && $in['width'] === '20%' && $in['opacity'] === '100%', 'center → no xMargin; the owner picks size and opacity');
$bad = \Fastpix\Fastpix_Uploads_Settings::settings_snapshot(array('watermark_pos' => 'center', 'watermark_size' => '90%', 'watermark_margin' => '50%', 'watermark_opacity' => '1%'));
assert($bad['watermark_pos'] === 'top-left' && $bad['watermark_size'] === '10%' && $bad['watermark_margin'] === '6%' && $bad['watermark_opacity'] === '65%', 'unknown values fall back to the defaults (center is not offered)');
assert(!isset($m->invoke(null, array('access_policy' => 'public'))['inputs']), 'no watermark URL → no inputs key at all');
// FastPix fetches the image server-side, so it faces the same gate a video URL does. [SEC-010]
$r = $m->invoke(null, array('access_policy' => 'public', 'watermark_url' => 'https://127.0.0.1/logo.png'));
assert(is_wp_error($r) && $r->get_error_code() === 'fastpix_watermark_url'
    && stripos($r->get_error_message(), 'private') !== false, 'a watermark on a private address is refused, naming why');
// The modal asks the same gate up front, so an unfetchable image never starts a batch.
$res = upreq('POST', REST_WATERMARK_CHECK, array('url' => 'https://127.0.0.1/logo.png'));
assert($res->get_status() === 400 && stripos($res->get_data()['message'], 'FastPix cannot fetch it') !== false, 'the pre-check refuses a local host with a clear reason');
$res = upreq('POST', REST_WATERMARK_CHECK, array('url' => WATERMARK_URL));
assert($res->get_status() === 200 && $res->get_data()['ok'] === true, 'the pre-check passes a public image');
$res = upreq('POST', REST_WATERMARK_CHECK, array('url' => 'ftp://4.4.4.4/logo.png'));   // NOSONAR php:S5332 — asserts ftp:// is rejected
assert($res->get_status() === 400, 'the pre-check refuses a non-http URL');
$mock['head']['6.6.6.6'] = array('headers' => array('content-type' => 'text/html'), 'body' => '', 'response' => array('code' => 200, 'message' => ''));
$r = $m->invoke(null, array('access_policy' => 'public', 'watermark_url' => 'https://6.6.6.6/logo.png'));
assert(is_wp_error($r) && stripos($r->get_error_message(), 'not an image') !== false, 'an HTML page is not a watermark');
$mock['head']['7.7.7.7'] = array('headers' => array(), 'body' => '', 'response' => array('code' => 401, 'message' => ''));
$r = $m->invoke(null, array('access_policy' => 'public', 'watermark_url' => 'https://7.7.7.7/logo.png'));
assert(is_wp_error($r) && stripos($r->get_error_message(), 'login') !== false, 'a login-walled image is refused: FastPix could not fetch it either');
// The snapshot judges shape only — reachability is the create call's business.
assert(\Fastpix\Fastpix_Uploads_Settings::settings_snapshot(array('watermark_url' => 'javascript:alert(1)'))['watermark_url'] === '', 'a non-http watermark URL is dropped');
assert(\Fastpix\Fastpix_Uploads_Settings::settings_snapshot(array('watermark_url' => ' https://cdn.example.com/logo.png '))['watermark_url'] === 'https://cdn.example.com/logo.png', 'a good one survives, trimmed');
assert(\Fastpix\Fastpix_Uploads_Settings::settings_snapshot(array())['watermark_url'] === '', 'blank by default: no watermark, no input');
// Optional batch title (UI-004): blank by default, sanitized, capped at 255 chars.
assert(\Fastpix\Fastpix_Uploads_Settings::settings_snapshot(array())['title'] === '', 'title defaults to empty (per-file name)');
assert(\Fastpix\Fastpix_Uploads_Settings::settings_snapshot(array('title' => "  My <b>Talk</b>  "))['title'] === 'My Talk', 'title is sanitized and trimmed');
assert(mb_strlen(\Fastpix\Fastpix_Uploads_Settings::settings_snapshot(array('title' => str_repeat('x', 400)))['title']) === 255, 'title is capped at 255 chars');
delete_option(\Fastpix\Fastpix_Settings_Page::OPT_DRM_CONFIG_ID);

// The platform call carried an idempotency key derived from the row id.
$create_call = null;
foreach ($mock['requests'] as $r) {
    if (strpos($r['url'], '/on-demand/upload') !== false) { $create_call = $r; }
}
assert($create_call !== null, 'the client created the platform session');

// ----------------------------------------------------- refusals name limits

$response = upreq('POST', REST_UPLOADS, array('filename' => 'upcheck-huge.mp4', 'filesize' => 21474836481, 'filetype' => CONTENT_TYPE_MP4));
assert($response->is_error(), 'over 20 GB is refused');
assert(strpos($response->as_error()->get_error_message(), '20 GB') !== false, 'naming the limit [REQ-016]');

$response = upreq('POST', REST_UPLOADS, array('filename' => 'upcheck-doc.pdf', 'filesize' => 1000, 'filetype' => 'application/pdf'));
assert($response->is_error() && $response->as_error()->get_error_code() === 'fastpix_bad_format', 'a non-video format is refused, named');

// ------------------------------------------------------------ RULE-005 offline

set_transient(Client::TRANSIENT_BREAKER, array('fails' => 5, 'first' => time(), 'open_until' => time() + 300), 400);
$before = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$uploads_table}");
$response = upreq('POST', REST_UPLOADS, array('filename' => 'upcheck-off.mp4', 'filesize' => 1000, 'filetype' => CONTENT_TYPE_MP4));
assert($response->is_error() && $response->get_status() === 503, 'an open breaker refuses new uploads [RULE-005]');
assert((int) $wpdb->get_var("SELECT COUNT(*) FROM {$uploads_table}") === $before, 'and queues NOTHING');
$urls_resp = upreq('POST', REST_VIDEOS, array('urls' => array('https://example.com/a.mp4')));
assert($urls_resp->is_error() && $urls_resp->get_status() === 503, 'URL ingestion refuses the same way');
delete_transient(Client::TRANSIENT_BREAKER);

// ------------------------------------------------------- RULE-008 resume

$response = upreq('PATCH', REST_UPLOADS_SLASH . $session['id'], array('resume' => true, 'filename' => 'different.mp4', 'filesize' => 999));
assert($response->is_error() && $response->as_error()->get_error_code() === 'fastpix_wrong_file', 'a different file is refused, never spliced [RULE-008]');
assert(strpos($response->as_error()->get_error_message(), 'upcheck-talk.mp4') !== false, 'the refusal names the expected file');

$response = upreq('PATCH', REST_UPLOADS_SLASH . $session['id'], array('resume' => true, 'filename' => 'upcheck-talk.mp4', 'filesize' => 1073741824));
assert($response->is_error() && $response->get_status() === 409 && $response->as_error()->get_error_code() === 'fastpix_upload_live', 'a row still reporting progress is not resumable: another tab owns it (QA U20)');
upreq('PATCH', REST_UPLOADS_SLASH . $session['id'], array('state' => 'paused'));
$response = upreq('PATCH', REST_UPLOADS_SLASH . $session['id'], array('resume' => true, 'filename' => 'upcheck-talk.mp4', 'filesize' => 1073741824));
assert(!$response->is_error(), 'the same file resumes');
assert($response->get_data()['state'] === 'uploading', 'back to uploading');

// Progress + pause.
upreq('PATCH', REST_UPLOADS_SLASH . $session['id'], array('bytes_sent' => 500000000, 'state' => 'paused'));
$row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$uploads_table} WHERE id = %d", $session['id']), ARRAY_A);
assert((int) $row['bytes_sent'] === 500000000 && $row['state'] === 'paused', 'progress and pause are recorded');

// The bucket's resumable session address is kept, so a resume after a page change reopens it. [ASSUME-101]
$response = upreq('PATCH', REST_UPLOADS_SLASH . $session['id'], array('session_uri' => SESSION_URI));
assert(!$response->is_error() && $response->get_data()['session_uri'] === SESSION_URI, 'the session address round-trips');
$response = upreq('PATCH', REST_UPLOADS_SLASH . $session['id'], array('session_uri' => 'https://storage.example.com/bucket/o'));
assert($response->is_error() && $response->get_status() === 400, 'an address without upload_id is refused');
$response = upreq('PATCH', REST_UPLOADS_SLASH . $session['id'], array('session_uri' => 'https://evil.example.net/o?upload_id=x'));
assert($response->is_error() && $response->as_error()->get_error_code() === 'fastpix_session_uri_host', 'a session address on another host than the signed URL is refused (QA U17)');
$response = upreq('PATCH', REST_UPLOADS_SLASH . $session['id'], array('resume' => true, 'filename' => 'upcheck-talk.mp4', 'filesize' => 1073741824));
assert($response->get_data()['session_uri'] === SESSION_URI, 'resume hands the same session back');
upreq('PATCH', REST_UPLOADS_SLASH . $session['id'], array('state' => 'paused'));

// An expired signed URL means a NEW platform session — verified live 2026-09-18 it carries a NEW upload id.
// The row and the placeholder video row keyed by the old id must follow it, or the media never binds.
$wpdb->query(SQL_DELETE_FROM . Schema::table('videos') . " WHERE media_id IN ('up-plat-1','up-plat-2')");
$wpdb->insert(Schema::table('videos'), array('media_id' => 'up-plat-1', 'workspace_id' => 'ws-up', 'title' => '', 'status' => 'waiting', 'source' => 'Upload', 'created_at' => current_time('mysql', true), 'updated_at' => current_time('mysql', true)));
$wpdb->update($uploads_table, array('url_expires_at' => '2000-01-01 00:00:00'), array('id' => $session['id']));
$mock['upload_id'] = 'up-plat-2';
$response = upreq('PATCH', REST_UPLOADS_SLASH . $session['id'], array('resume' => true, 'filename' => 'upcheck-talk.mp4', 'filesize' => 1073741824));
assert(!$response->is_error(), 'resume after expiry re-creates the session');
$row = $wpdb->get_row($wpdb->prepare("SELECT upload_id FROM {$uploads_table} WHERE id = %d", $session['id']), ARRAY_A);
assert($row['upload_id'] === 'up-plat-2', 'the row follows the new upload id');
assert((string) $wpdb->get_var("SELECT media_id FROM " . Schema::table('videos') . " WHERE media_id = 'up-plat-2'") === 'up-plat-2', 'the placeholder video row is re-keyed to the new id');
$wpdb->query(SQL_DELETE_FROM . Schema::table('videos') . " WHERE media_id IN ('up-plat-1','up-plat-2')");
$wpdb->update($uploads_table, array('upload_id' => 'up-plat-1', 'url_expires_at' => null), array('id' => $session['id']));
unset($mock['upload_id']);
upreq('PATCH', REST_UPLOADS_SLASH . $session['id'], array('state' => 'paused'));

// ------------------------------------------------------ own-limit [REQ-091]

$author_ids = get_users(array('role' => 'author', 'number' => 1, 'fields' => 'ID'));
if ($author_ids) {
    wp_set_current_user($author_ids[0]);
    $response = upreq('PATCH', REST_UPLOADS_SLASH . $session['id'], array('bytes_sent' => 1));
    assert($response->is_error() && $response->get_status() === 404, 'an author cannot touch another user\'s session — a query condition, not a filter [REQ-091]');
    wp_set_current_user($admins[0]);
}
// Nor can an administrator: a session is one browser's transfer, whatever the caller's video capability. (QA U17)
$now = current_time('mysql', true);
$wpdb->insert($uploads_table, array('upload_id' => 'up-foreign-1', 'filename' => 'upcheck-foreign.mp4', 'filesize' => 10, 'state' => 'paused', 'settings_json' => '{}', 'user_id' => $admins[0] + 100000, 'created_at' => $now, 'updated_at' => $now));
$foreign = (int) $wpdb->insert_id;
assert(upreq('PATCH', REST_UPLOADS_SLASH . $foreign, array('bytes_sent' => 1))->get_status() === 404 && upreq('POST', REST_UPLOADS_SLASH . $foreign . REST_CANCEL)->get_status() === 404, 'an admin cannot pause/resume/cancel another user\'s session');
// A row the server had called interrupted reads uploading again the moment its tab reports. (QA U20)
$wpdb->update($uploads_table, array('state' => 'paused'), array('id' => $session['id']));
upreq('PATCH', REST_UPLOADS_SLASH . $session['id'], array('bytes_sent' => 600000000, 'state' => 'uploading'));
assert($wpdb->get_var($wpdb->prepare("SELECT state FROM {$uploads_table} WHERE id = %d", $session['id'])) === 'uploading', 'a progress report carries the live state back');
// The site-wide cap names whose sessions they are — a user only ever sees their own. (QA U10)
for ($i = 0; $i < Uploads::MAX_SESSIONS_PER_SITE; $i++) {
    $wpdb->insert($uploads_table, array('upload_id' => 'up-cap-' . $i, 'filename' => 'upcheck-cap.mp4', 'filesize' => 10, 'state' => 'paused', 'settings_json' => '{}', 'user_id' => 0, 'created_at' => $now, 'updated_at' => $now));
}
$response = upreq('POST', REST_UPLOADS, array('filename' => 'upcheck-capped.mp4', 'filesize' => 1000, 'filetype' => CONTENT_TYPE_MP4));
assert($response->is_error() && $response->get_status() === 429 && preg_match('/\d+ of them yours/', $response->as_error()->get_error_message()), 'the cap refusal says how many open sessions are the caller\'s own');
$wpdb->query("DELETE FROM {$uploads_table} WHERE filename = 'upcheck-cap.mp4'");

// ------------------------------------------------------------------- cancel

$response = upreq('POST', REST_UPLOADS_SLASH . $session['id'] . REST_CANCEL);
assert(!$response->is_error() && $response->get_data()['state'] === 'cancelled', 'cancel cancels');
$cancel_call = null;
foreach ($mock['requests'] as $r) {
    if (strpos($r['url'], '/upload/up-plat-1/cancel') !== false && $r['method'] === 'PUT') { $cancel_call = $r; }
}
assert($cancel_call !== null, 'the platform session is cancelled too [WF-002]');
// A cancelled session is terminal: the tab that still reports on it learns so and stops. (QA U21)
$response = upreq('PATCH', REST_UPLOADS_SLASH . $session['id'], array('bytes_sent' => 7, 'state' => 'uploading'));
assert($response->is_error() && $response->get_status() === 409 && $response->as_error()->get_error_code() === 'fastpix_upload_cancelled', 'a PATCH on a cancelled session answers 409 fastpix_upload_cancelled');
assert($wpdb->get_var($wpdb->prepare("SELECT state FROM {$uploads_table} WHERE id = %d", $session['id'])) === 'cancelled', 'and the row stays cancelled');

// ------------------------------------------------------------- SSRF [SEC-010]

foreach (array(
    'ftp://example.com/a.mp4'        => 'http and https',
    'https://127.0.0.1/a.mp4'        => REASON_PRIVATE_OR_RESERVED,
    'https://10.0.0.8/a.mp4'         => REASON_PRIVATE_OR_RESERVED,
    'https://192.168.1.5/a.mp4'      => REASON_PRIVATE_OR_RESERVED,
    'https://169.254.169.254/latest' => REASON_PRIVATE_OR_RESERVED,   // cloud metadata
    'https://[::1]/a.mp4'            => '',                      // refused, message varies by parser
    'not a url at all'               => '',
) as $bad => $needle) {
    $verdict = Uploads::validate_public_video_url($bad);
    assert(is_wp_error($verdict), "refused: {$bad} [SEC-010]");
    if ($needle !== '') {
        assert(stripos($verdict->get_error_message(), $needle) !== false, "the reason names it: {$bad}");
    }
}

// IP-literal public hosts so resolution cannot interfere: the HEAD is mocked,
// the address checks are real.

// Redirect to a private address is caught at the hop. [SEC-010]
$mock['head'][PUBLIC_IP] = array('headers' => array('location' => 'https://192.168.0.9/internal.mp4'), 'body' => '', 'response' => array('code' => 302, 'message' => ''));
$verdict = Uploads::validate_public_video_url('https://93.184.216.34/a.mp4');
assert(is_wp_error($verdict) && stripos($verdict->get_error_message(), 'private') !== false, 'a redirect into private space is refused at the hop');

// Login-walled: named reason. [REQ-015]
$mock['head']['8.8.8.8'] = array('headers' => array(), 'body' => '', 'response' => array('code' => 403, 'message' => ''));
$verdict = Uploads::validate_public_video_url('https://8.8.8.8/a.mp4');
assert(is_wp_error($verdict) && stripos($verdict->get_error_message(), 'login') !== false, 'a login wall is refused with that reason');

// Not a video: named. HTML page masquerading as a video URL.
$mock['head']['1.1.1.1'] = array('headers' => array('content-type' => 'text/html; charset=utf-8'), 'body' => '', 'response' => array('code' => 200, 'message' => ''));
$verdict = Uploads::validate_public_video_url('https://1.1.1.1/a.mp4');
assert(is_wp_error($verdict) && stripos($verdict->get_error_message(), 'text/html') !== false, 'a non-video answer is refused, naming what it served');

// HEAD refused but GET fine (S3 presigned URLs are signed for GET only; HEAD-405 CDNs): FastPix fetches with GET, so the probe asks that way too. (QA U13)
$mock['head']['5.5.5.5'] = array('headers' => array(), 'body' => '', 'response' => array('code' => 405, 'message' => ''));
$mock['get']['5.5.5.5']  = array('headers' => array('content-type' => CONTENT_TYPE_MP4), 'body' => 'x', 'response' => array('code' => 206, 'message' => ''));
assert(Uploads::validate_public_video_url('https://5.5.5.5/a.mp4?X-Amz-Signature=abc') === true, 'a GET-only URL is accepted through the one-byte GET fallback');
$mock['get']['5.5.5.5']  = array('headers' => array(), 'body' => '', 'response' => array('code' => 403, 'message' => ''));
$mock['head']['5.5.5.5'] = array('headers' => array(), 'body' => '', 'response' => array('code' => 403, 'message' => ''));
$verdict = Uploads::validate_public_video_url('https://5.5.5.5/b.mp4');
assert(is_wp_error($verdict) && stripos($verdict->get_error_message(), 'login') !== false, 'refused on both methods keeps the login-wall reason');

// A host with several addresses: pinning disables curl's own failover, so the probe must try the
// others itself. One address timing out used to report "did not respond" for a reachable host. (owner 2026-09-23)
$pin = new ReflectionMethod('Fastpix\Fastpix_Url_Guard', 'pinned_head'); $pin->setAccessible(true);
$attempts = 0;
$flaky = function () use (&$attempts) {   // pre_http_request args unused — the attempt count is the point
    $attempts++;

    return $attempts === 1
        ? new WP_Error('http_request_failed', 'cURL error 28: Connection timed out')
        : array('headers' => array('content-type' => CONTENT_TYPE_PNG), 'body' => '', 'response' => array('code' => 200, 'message' => ''));
};
add_filter('pre_http_request', $flaky, 20, 3);   // after the harness mock at 10: the last filter's return is the response
$parts = wp_parse_url(MULTI_ADDRESS_URL);
$answer = $pin->invoke(null, MULTI_ADDRESS_URL, $parts, array(PUBLIC_IP, '93.184.216.35'));
assert(!is_wp_error($answer) && (int) wp_remote_retrieve_response_code($answer) === 200 && $attempts === 2, 'the second address is tried when the first one fails');
$attempts = 100;   // every attempt now answers, so a single address is enough
$answer = $pin->invoke(null, MULTI_ADDRESS_URL, $parts, array(PUBLIC_IP));
assert(!is_wp_error($answer), 'one good address needs one probe');
remove_filter('pre_http_request', $flaky, 20);
$dead = function () { return new WP_Error('http_request_failed', 'cURL error 28: Connection timed out'); };
add_filter('pre_http_request', $dead, 20);
$answer = $pin->invoke(null, MULTI_ADDRESS_URL, $parts, array(PUBLIC_IP, '93.184.216.35'));
assert(is_wp_error($answer) && $answer->get_error_code() === 'fastpix_url_unreachable', 'only when every address fails is the URL unreachable');
remove_filter('pre_http_request', $dead, 20);

// A relative redirect Location is resolved against the URL that sent it. (QA U16)
$mock['head']['93.184.216.35/a.mp4'] = array('headers' => array('location' => '/moved/a.mp4'), 'body' => '', 'response' => array('code' => 302, 'message' => ''));
assert(Uploads::validate_public_video_url('https://93.184.216.35/a.mp4') === true, 'a relative Location follows to the same host');
$followed = array_filter($mock['requests'], function ($r) { return $r['url'] === 'https://93.184.216.35/moved/a.mp4' && $r['method'] === 'HEAD'; });
assert(count($followed) === 1, 'the absolute form of the relative Location was probed');

// ------------------------------------------------------ per-URL verdicts

$response = upreq('POST', REST_VIDEOS, array('urls' => array(
    'https://9.9.9.9/keep.mp4',
    'https://127.0.0.1/refuse.mp4',
)));
assert(!$response->is_error(), 'the batch call succeeds even when some URLs fail');
$verdicts = $response->get_data()['verdicts'];
assert(count($verdicts) === 2, 'one verdict per URL, never one for the batch [REQ-015]');
assert($verdicts[0]['accepted'] === true && $verdicts[0]['media_id'] === 'ing-media-1', 'the good URL queued');
assert($verdicts[1]['accepted'] === false && $verdicts[1]['reason'] !== '', 'the bad URL carries its own reason');

$video = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Schema::table('videos') . ' WHERE media_id = %s', 'ing-media-1'), ARRAY_A);
assert($video !== null && $video['source'] === 'URL', 'an ingested video is filed with source URL');

// Create-from-URL pairs the video with the watermark: merging the other way round dropped the
// video input, since both live under `inputs`.
$mock['head']['4.4.4.4'] = array('headers' => array('content-type' => CONTENT_TYPE_PNG), 'body' => '', 'response' => array('code' => 200, 'message' => ''));
$mark = count($mock['requests']);
upreq('POST', REST_VIDEOS, array('urls' => array('https://9.9.9.9/wm.mp4'), 'settings' => array('watermark_url' => WATERMARK_URL)));
$ingested = null;
foreach (array_slice($mock['requests'], $mark) as $r) {
    if ($r['method'] === 'POST' && strpos($r['url'], '/on-demand') !== false) { $ingested = json_decode((string) $r['body'], true); }
}
assert($ingested !== null && count($ingested['inputs']) === 2, 'the URL create carries both inputs');
assert($ingested['inputs'][0] === array('type' => 'video', 'url' => 'https://9.9.9.9/wm.mp4'), 'the video input comes first and survives the merge');
assert($ingested['inputs'][1]['type'] === 'watermark' && $ingested['inputs'][1]['url'] === WATERMARK_URL, 'the watermark follows it');

// One batch title on several links names each media "Title — file", never all alike. (QA U8)
$mark = count($mock['requests']);
$titled = upreq('POST', REST_VIDEOS, array('urls' => array('https://9.9.9.9/keynote.mp4', 'https://9.9.9.9/panel.mp4'), 'settings' => array('title' => TITLE_JUNE_WEBINARS)));
assert($titled->get_data()['verdicts'][0]['title'] === 'June webinars — keynote', 'the verdict carries the title so the link row is named as the media is (QA F4)');
$titles = array();
foreach (array_slice($mock['requests'], $mark) as $r) {
    if ($r['method'] === 'POST' && strpos($r['url'], '/on-demand') !== false) { $b = json_decode((string) $r['body'], true); $titles[] = isset($b['title']) ? $b['title'] : ''; }
}
assert($titles === array('June webinars — keynote', 'June webinars — panel'), 'links in a titled batch get distinct titles: ' . implode(' | ', $titles));
$mark = count($mock['requests']);
upreq('POST', REST_VIDEOS, array('urls' => array('https://9.9.9.9/solo.mp4'), 'settings' => array('title' => TITLE_JUNE_WEBINARS)));
$b = json_decode((string) end($mock['requests'])['body'], true);
assert(isset($b['title']) && $b['title'] === TITLE_JUNE_WEBINARS, 'a single link keeps the title as typed');

// ------------------------------------------------- webhook binding + proxy

$now = current_time('mysql', true);
$wpdb->insert($uploads_table, array(
    'upload_id' => 'up-bind-1', 'filename' => 'upcheck-bind.mp4', 'filesize' => 5000,
    'state' => 'uploading', 'settings_json' => wp_json_encode(array('access_policy' => 'private', 'quality_tier' => 'pro')),
    'user_id' => $admins[0], 'created_at' => $now, 'updated_at' => $now,
));
$wpdb->insert(Schema::table('videos'), array(
    'media_id' => 'bind-media-1', 'workspace_id' => 'ws-up', 'status' => 'Created', 'source' => 'Dashboard',
    'title' => '', 'created_at' => $now, 'updated_at' => $now,
));

do_action('fastpix_upload_event', 'video.upload.media_created', array('uploadId' => 'up-bind-1', 'mediaId' => 'bind-media-1'));

$video = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Schema::table('videos') . ' WHERE media_id = %s', 'bind-media-1'), ARRAY_A);
assert($video['source'] === 'Upload', 'binding stamps the source [WF-002 step 4]');
assert((int) $video['author_id'] === (int) $admins[0], 'and the uploader as author');
assert($video['title'] === 'upcheck-bind', 'the filename seeds the title');
assert($video['access_policy'] === 'private', 'the stored settings snapshot is applied');
assert(!empty($video['attachment_id']), 'a proxy attachment is created [REQ-035]');
assert(get_post_mime_type((int) $video['attachment_id']) === 'video/fastpix', 'with the proxy mime [ARCH-11]');

// Without webhooks nothing binds — except that a direct upload's media has the
// SAME id as its session (verified live 2026-09-08), so a completed row is bound
// to the media of that id once it is known locally, and a Ready one leaves the
// Add media list. [ASSUME-075]
$wpdb->insert($uploads_table, array('upload_id' => 'bind-media-2', 'filename' => 'upcheck-bind2.mp4', 'filesize' => 5000, 'bytes_sent' => 5000,
    'state' => 'completed', 'settings_json' => '{}', 'user_id' => $admins[0], 'created_at' => $now, 'updated_at' => $now));
$wpdb->insert(Schema::table('videos'), array('media_id' => 'bind-media-2', 'workspace_id' => 'ws-up', 'status' => 'Ready', 'source' => 'Dashboard', 'title' => '', 'created_at' => $now, 'updated_at' => $now));
$listed2 = array_column(upreq('GET', REST_UPLOADS)->get_data()['sessions'], 'upload_id');
$bound2  = $wpdb->get_row($wpdb->prepare("SELECT u.video_id, v.source FROM {$uploads_table} u JOIN " . Schema::table('videos') . ' v ON v.id = u.video_id WHERE u.upload_id = %s', 'bind-media-2'), ARRAY_A);
assert($bound2 && $bound2['source'] === 'Upload' && !in_array('bind-media-2', $listed2, true), 'completed session bound to the media of the same id; Ready → no longer listed');

do_action('fastpix_upload_event', 'video.upload.ready', array('uploadId' => 'up-bind-1'));
$row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$uploads_table} WHERE upload_id = %s", 'up-bind-1'), ARRAY_A);
assert($row['state'] === 'completed', 'ready completes the session');

// -------------------------------------------------- orphan sweep [RULE-009]

$stale = gmdate(MYSQL_DATETIME, time() - (8 * DAY_IN_SECONDS));
$wpdb->insert($uploads_table, array(
    'upload_id' => 'up-stale-1', 'filename' => 'upcheck-stale.mp4', 'filesize' => 1,
    'state' => 'paused', 'user_id' => 0, 'created_at' => $stale, 'updated_at' => $stale,
));
$count = Uploads::orphan_sweep();
assert($count >= 1, 'the sweep cancels sessions untouched for 7 days [RULE-009]');
$row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$uploads_table} WHERE upload_id = %s", 'up-stale-1'), ARRAY_A);
assert($row['state'] === 'cancelled', 'the stale session is cancelled');
$notice = get_option('fastpix_orphan_notice');
assert(is_array($notice) && $notice['count'] >= 1, 'and the owner is told how many [RULE-009]');
$fresh = $wpdb->get_row($wpdb->prepare("SELECT state FROM {$uploads_table} WHERE upload_id = %s", 'up-plat-1'), ARRAY_A);
assert($fresh === null || $fresh['state'] === 'cancelled', 'noop: earlier session was already cancelled by its own test');

// ------------------------------------- readiness: webhooks first, poll fallback

// The Add media queue reads local state through GET /uploads/status.
// A row the browser gave up on with every byte sent, whose upload id FastPix already turned into a
// media, is completed by the status poll — the platform is the judge, not the SDK's CORS view. [QA F1/F3]
$wpdb->query(SQL_DELETE_FROM . Schema::table('videos') . " WHERE media_id = 'up-abandoned-1'");
$wpdb->insert(Schema::table('videos'), array('media_id' => 'up-abandoned-1', 'workspace_id' => 'ws-up', 'title' => '', 'status' => 'Ready', 'source' => 'Upload', 'created_at' => current_time('mysql', true), 'updated_at' => current_time('mysql', true)));
$wpdb->insert($uploads_table, array('upload_id' => 'up-abandoned-1', 'filename' => 'upcheck-abandoned.mp4', 'filesize' => 100, 'bytes_sent' => 100, 'chunk_size' => 1, 'state' => 'paused', 'settings_json' => '{}', 'user_id' => get_current_user_id(), 'created_at' => current_time('mysql', true), 'updated_at' => current_time('mysql', true)));
$abandoned_id = (int) $wpdb->insert_id;
$sreq0 = new WP_REST_Request('GET', REST_UPLOADS_STATUS); $sreq0->set_param('ids', (string) $abandoned_id);
$st0 = rest_get_server()->dispatch($sreq0)->get_data();
assert($st0['uploads'][(string) $abandoned_id]['state'] === 'completed' && $st0['uploads'][(string) $abandoned_id]['status'] === 'Ready', 'an abandoned row whose media exists is completed and bound by the status poll');
$wpdb->delete($uploads_table, array('id' => $abandoned_id));
$wpdb->query(SQL_DELETE_FROM . Schema::table('videos') . " WHERE media_id = 'up-abandoned-1'");
$wpdb->query(SQL_DELETE_FROM . Schema::table('videos') . " WHERE media_id = 'up-status-media'");
$wpdb->insert(Schema::table('videos'), array('media_id' => 'up-status-media', 'workspace_id' => 'ws', 'title' => 's', 'status' => 'Ready', 'source' => 'URL',
    'access_policy' => 'public', 'author_id' => get_current_user_id(), 'created_at' => current_time('mysql', true), 'updated_at' => current_time('mysql', true)));
$sreq = new WP_REST_Request('GET', REST_UPLOADS_STATUS);
$sreq->set_param('media_ids', 'up-status-media,nope');
$sres = rest_get_server()->dispatch($sreq);
assert(!$sres->is_error() && $sres->get_data()['media']['up-status-media']['status'] === 'Ready' && !isset($sres->get_data()['media']['nope']), 'batch status is a local read keyed by media id');
$wpdb->query(SQL_DELETE_FROM . Schema::table('videos') . " WHERE media_id = 'up-status-media'");

// Sessions outlive the page: GET /uploads rebuilds the queue. An 'uploading' row
// silent for three minutes is interrupted (→ paused); a live one is not listed; a
// transferred row still processing is listed as completed. [FR-010 reopen]
$now  = current_time('mysql', true);
$old  = gmdate(MYSQL_DATETIME, time() - 300);
$slow = gmdate(MYSQL_DATETIME, time() - 120);   // a hidden tab's throttled heartbeat (QA U20)
$mine = get_current_user_id();
$base = array('signed_url' => 'https://u.example/s', 'filesize' => 1000, 'chunk_size' => 1, 'settings_json' => '{"domain_lock":false}', 'user_id' => $mine, 'created_at' => $now);
$wpdb->insert(Schema::table('uploads'), $base + array('upload_id' => 'reopen-1', 'filename' => 'reopen-cut.mp4', 'state' => 'uploading', 'bytes_sent' => 250, 'updated_at' => $old));   $cut  = (int) $wpdb->insert_id;
$wpdb->insert(Schema::table('uploads'), $base + array('upload_id' => 'reopen-2', 'filename' => 'reopen-live.mp4', 'state' => 'uploading', 'bytes_sent' => 10, 'updated_at' => $now));  $live = (int) $wpdb->insert_id;
$wpdb->insert(Schema::table('uploads'), $base + array('upload_id' => 'reopen-3', 'filename' => 'reopen-proc.mp4', 'state' => 'completed', 'bytes_sent' => 1000, 'updated_at' => $now)); $proc = (int) $wpdb->insert_id;
$wpdb->insert(Schema::table('uploads'), array('user_id' => $mine + 100000) + $base + array('upload_id' => 'reopen-4', 'filename' => 'reopen-other.mp4', 'state' => 'paused', 'bytes_sent' => 5, 'updated_at' => $now)); $other = (int) $wpdb->insert_id;
$wpdb->insert(Schema::table('uploads'), $base + array('upload_id' => 'reopen-5', 'filename' => 'reopen-hidden.mp4', 'state' => 'uploading', 'bytes_sent' => 10, 'updated_at' => $slow)); $hidden = (int) $wpdb->insert_id;
// QA 2026-09-21: a finished row that never found its video is "Processing" for an hour, not a day —
// and the status poll tells an open tab so. A BOUND row still processing stays listed as before.
$ago2h = gmdate(MYSQL_DATETIME, time() - 2 * HOUR_IN_SECONDS);
$wpdb->insert(Schema::table('uploads'), $base + array('upload_id' => 'reopen-6', 'filename' => 'reopen-unbound-old.mp4', 'state' => 'completed', 'bytes_sent' => 1000, 'updated_at' => $ago2h)); $unbound_old = (int) $wpdb->insert_id;
$wpdb->insert(Schema::table('videos'), array('media_id' => 'reopen-7', 'workspace_id' => 'ws-up', 'title' => '', 'status' => 'Processing', 'source' => 'Upload', 'created_at' => $now, 'updated_at' => $now)); $slow_vid = (int) $wpdb->insert_id;
$wpdb->insert(Schema::table('uploads'), $base + array('upload_id' => 'reopen-7', 'filename' => 'reopen-bound-slow.mp4', 'state' => 'completed', 'bytes_sent' => 1000, 'video_id' => $slow_vid, 'updated_at' => $ago2h)); $bound_slow = (int) $wpdb->insert_id;
$listed = array();
foreach (upreq('GET', REST_UPLOADS)->get_data()['sessions'] as $sess) { $listed[$sess['id']] = $sess; }
assert(!isset($listed[$unbound_old]), 'a finished row with no video after the grace is no longer listed as Processing');
assert(isset($listed[$bound_slow]), 'a bound row whose video is genuinely still processing stays listed');
$sreq2 = new WP_REST_Request('GET', REST_UPLOADS_STATUS); $sreq2->set_param('ids', $unbound_old . ',' . $bound_slow . ',' . $proc);
$st2 = rest_get_server()->dispatch($sreq2)->get_data()['uploads'];
assert($st2[(string) $unbound_old]['stale'] === true && $st2[(string) $bound_slow]['stale'] === false && $st2[(string) $proc]['stale'] === false, 'only the unbound row past the grace is stale');
$wpdb->delete(Schema::table('videos'), array('id' => $slow_vid));
assert(isset($listed[$cut]) && $listed[$cut]['state'] === 'paused' && $listed[$cut]['bytes_sent'] === 250 && $listed[$cut]['settings'] === array('domain_lock' => false), 'interrupted upload comes back paused with its bytes and batch settings');
assert(!isset($listed[$live]), 'an upload still reporting progress is not listed (another tab owns it)');
assert(!isset($listed[$hidden]), 'two minutes silent is a throttled background tab, not an interruption (QA U20)');
assert(isset($listed[$proc]) && $listed[$proc]['state'] === 'completed', 'transferred-but-processing row is listed so the Processing chip survives a refresh');
assert(!isset($listed[$other]), 'only this user\'s sessions');
$wpdb->query(SQL_DELETE_FROM . Schema::table('uploads') . " WHERE filename LIKE 'reopen-%'");

// A media that is READY on FastPix but still 'Preparing' locally: with webhooks configured the poll chain is
// skipped, so nothing moved the local row and the queue said "Processing" for many minutes. The status route the
// queue polls now re-reads an unsettled media it is being asked about — throttled to one platform GET per media.
// (owner 2026-09-22)
$wpdb->query(SQL_DELETE_FROM . Schema::table('videos') . " WHERE media_id = 'up-watch-1'");
$now_w = current_time('mysql', true);
$wpdb->insert(Schema::table('videos'), array('media_id' => 'up-watch-1', 'workspace_id' => 'ws-up', 'title' => 'watched', 'status' => 'Preparing', 'source' => 'Upload', 'created_at' => $now_w, 'updated_at' => $now_w));
$watch_vid = (int) $wpdb->insert_id;
$wpdb->insert(Schema::table('uploads'), array('upload_id' => 'up-watch-1', 'filename' => 'watch.mp4', 'filesize' => 10, 'bytes_sent' => 10, 'chunk_size' => 1, 'state' => 'completed', 'video_id' => $watch_vid, 'settings_json' => '{}', 'user_id' => get_current_user_id(), 'created_at' => $now_w, 'updated_at' => $now_w));
$watch_up = (int) $wpdb->insert_id;
delete_transient('fastpix_watch_' . md5('up-watch-1'));
$mock['media_status'] = 'Ready'; $mock['media_reads'] = array();

$wreq = new WP_REST_Request('GET', REST_UPLOADS_STATUS); $wreq->set_param('ids', (string) $watch_up);
$wdata = rest_get_server()->dispatch($wreq)->get_data()['uploads'][(string) $watch_up];
assert($wdata['status'] === 'Ready', 'the queue learns the media is Ready without waiting for a webhook or the sweep');
assert(in_array('up-watch-1', $mock['media_reads'], true), 'and it asked the platform to find out');

// Throttled: the next poll a second later reads nothing from the platform.
$mock['media_reads'] = array();
rest_get_server()->dispatch($wreq);
assert($mock['media_reads'] === array(), 'a second poll within the window asks the platform nothing');

// A settled media is never re-read, however often the queue polls.
delete_transient('fastpix_watch_' . md5('up-watch-1'));
$mock['media_reads'] = array();
rest_get_server()->dispatch($wreq);
assert($mock['media_reads'] === array(), 'a media that is already Ready is not read again');

// Another user's upload id cannot make this site call the platform.
$wpdb->update(Schema::table('uploads'), array('video_id' => $watch_vid), array('id' => $watch_up));
$wpdb->update(Schema::table('videos'), array('status' => 'Preparing'), array('id' => $watch_vid));
$wpdb->update(Schema::table('uploads'), array('user_id' => get_current_user_id() + 100000), array('id' => $watch_up));
delete_transient('fastpix_watch_' . md5('up-watch-1'));
$mock['media_reads'] = array();
rest_get_server()->dispatch($wreq);
assert($mock['media_reads'] === array(), 'another user\'s row is never refreshed on your behalf');
$mock['media_status'] = null;
$wpdb->delete(Schema::table('uploads'), array('id' => $watch_up));
$wpdb->query(SQL_DELETE_FROM . Schema::table('videos') . " WHERE media_id = 'up-watch-1'");
$wpdb->query(SQL_DELETE_FROM . Schema::table('playback_ids') . ' WHERE video_id = ' . $watch_vid);

// Webhooks configured ⇒ no poll chain; polling mode ⇒ the chain starts. [user ruling 2026-08-17]
as_unschedule_all_actions('fastpix_poll_media');   // the webhook secret is in $saved: the shutdown function restores it
\Fastpix\Fastpix_Webhooks::set_secret('whsec_poll_gate_check');
\Fastpix\Fastpix_Sync::apply_media(array('id' => 'up-poll-gate', 'status' => 'Preparing', 'workspaceId' => 'ws'), 'webhook');
assert(as_has_scheduled_action('fastpix_poll_media', array(array('media_id' => 'up-poll-gate', 'attempt' => 1, 'first' => time()))) === false
    && !as_has_scheduled_action('fastpix_poll_media'), 'with webhooks configured, pending media is NOT polled');
\Fastpix\Fastpix_Webhooks::set_secret('');
\Fastpix\Fastpix_Cache::delete('poll', 'up-poll-gate-2');
\Fastpix\Fastpix_Sync::apply_media(array('id' => 'up-poll-gate-2', 'status' => 'Preparing', 'workspaceId' => 'ws'), 'webhook');
assert(as_has_scheduled_action('fastpix_poll_media') !== false, 'in polling mode the poll chain starts');
as_unschedule_all_actions('fastpix_poll_media');
$wpdb->query(SQL_DELETE_FROM . Schema::table('videos') . " WHERE media_id LIKE 'up-poll-gate%'");
\Fastpix\Fastpix_Cache::delete('poll', 'up-poll-gate'); \Fastpix\Fastpix_Cache::delete('poll', 'up-poll-gate-2');

// ------------------------------------------- domain lock decision (AMBIG-009)

// "Play only on this site" resolves to a deny-by-default restriction naming the
// site host — but ONLY for media with a stored batch snapshot that asked for it.
$lock_video = array('id' => 0, 'media_id' => 'lockcheck-1');
assert(Fastpix\Fastpix_Uploads_Ingest::domain_lock_target($lock_video) === null, 'no batch snapshot → never touched (dashboard media)');
\Fastpix\Fastpix_Ai::park_settings('lockcheck-1', array('domain_lock' => true));
$target = Fastpix\Fastpix_Uploads_Ingest::domain_lock_target($lock_video);
$host   = wp_parse_url(home_url(), PHP_URL_HOST);
assert($target === array('defaultPolicy' => 'deny', 'allow' => array($host), 'deny' => array()), 'ticked → deny-by-default, site host allowed');
\Fastpix\Fastpix_Ai::park_settings('lockcheck-1', array('domain_lock' => false));
assert(Fastpix\Fastpix_Uploads_Ingest::domain_lock_target($lock_video) === null, 'unticked → left open');

// ASSUME-073: default policy + the one list that applies to it.
assert(\Fastpix\Fastpix_Uploads_Settings::clean_hosts(array('HTTPS://Www.Example.com/path', 'www.example.com', '*.cdn.example.org', 'localhost', 'not a host', $host, 'x.io:8080')) === array('www.example.com', '*.cdn.example.org', 'x.io'), 'hosts are lowercased, stripped of scheme/path/port, validated, deduped, site host dropped');
$snap = \Fastpix\Fastpix_Uploads_Settings::settings_snapshot(array('domain_policy' => 'bogus', 'domain_allow' => array('a.example.com'), 'domain_deny' => array('a.example.com', 'b.example.com')));
assert($snap['domain_policy'] === 'deny' && $snap['domain_allow'] === array('a.example.com') && $snap['domain_deny'] === array('b.example.com'), 'unknown policy → deny; a host never sits in both lists');
\Fastpix\Fastpix_Ai::park_settings('lockcheck-1', array('domain_lock' => true, 'domain_policy' => 'deny', 'domain_allow' => array('www.example.com', $host)));
assert(Fastpix\Fastpix_Uploads_Ingest::domain_lock_target($lock_video) === array('defaultPolicy' => 'deny', 'allow' => array($host, 'www.example.com'), 'deny' => array()), 'whitelist → site host first, extras after, no duplicate');
\Fastpix\Fastpix_Ai::park_settings('lockcheck-1', array('domain_lock' => true, 'domain_policy' => 'allow', 'domain_deny' => array('thief.example.net')));
assert(Fastpix\Fastpix_Uploads_Ingest::domain_lock_target($lock_video) === array('defaultPolicy' => 'allow', 'allow' => array(), 'deny' => array('thief.example.net')), 'blacklist → allow-by-default with the deny list');
\Fastpix\Fastpix_Ai::park_settings('lockcheck-1', array('domain_lock' => true, 'domain_policy' => 'allow'));
assert(Fastpix\Fastpix_Uploads_Ingest::domain_lock_target($lock_video) === null, 'blacklist with nothing listed → nothing to lock, untouched');
delete_transient('fastpix_ai_settings_' . md5('lockcheck-1'));

// ---------------------------------------------------------------- teardown

foreach (array((int) $video['attachment_id'], (int) $wpdb->get_var("SELECT attachment_id FROM " . Schema::table('videos') . " WHERE media_id = 'bind-media-2'")) as $attachment_id) {
    if ($attachment_id) {
        wp_delete_post($attachment_id, true);
    }
}
$wpdb->query("DELETE FROM {$uploads_table} WHERE filename LIKE 'upcheck-%'");
$wpdb->query(SQL_DELETE_FROM . Schema::table('videos') . " WHERE media_id IN ('ing-media-1', 'bind-media-1', 'bind-media-2')");
$wpdb->query(SQL_DELETE_FROM . Schema::table('logs') . " WHERE error_code = 'upload_orphans_cancelled'");
delete_option('fastpix_orphan_notice');
foreach ($saved as $opt => $value) {
    if ($value === null) { delete_option($opt); } else { update_option($opt, $value, false); }
}
wp_set_current_user(0);

echo "uploads + url ingestion: all checks passed\n";
