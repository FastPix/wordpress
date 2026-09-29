<?php
/**
 * Self-check for playback & protection — WF-006/007, FR-050/051/052, FR-090,
 * REQ-050…056, REQ-075, REQ-101, RULE-010/013…016/034/045/046, SEC-005/006/007,
 * TEST-008.
 *
 *   docker compose exec wordpress php -d zend.assertions=1 \
 *     /var/www/html/wp-content/plugins/fastpix/tests/test-render.php
 */

require_once __DIR__ . '/bootstrap.php';

use Fastpix\Fastpix_Cache as Cache;
use Fastpix\Fastpix_Credentials as Creds;
use Fastpix\Fastpix_Health as Health;
use Fastpix\Fastpix_Render as Render;
use Fastpix\Fastpix_Schema as Schema;
use Fastpix\Fastpix_Signing as Signing;

const SQL_DELETE_FROM = 'DELETE FROM ';
const ATTR_PLAYBACK_ID_PUB = 'playback-id="pb-rd-pub"';
const QS_TOKEN = 'token=';
const ATTR_TOKEN = 'token="';
const REST_PLAYER_CONFIG_PRIV = '/fastpix/v1/player-config/pb-rd-priv';
const SHORTCODE_LIVE = '[fastpix streamid="live-check-1"]';
const EP_SIGNING_KEYS = '/iam/signing-keys';
const ATTR_AUTOPLAY = ' auto-play';

global $wpdb;

$saved = array();
foreach (array(Creds::OPT_TOKEN_ID, Creds::OPT_SECRET, Health::OPT_SIGNING_KEY, Render::OPT_SEO, Render::OPT_DRM_BAD, Signing::OPT_TOKEN_TTL,
               \Fastpix\Fastpix_Settings_Page::OPT_DRM_CONFIG_ID, \Fastpix\Fastpix_Api_Client::OPT_HEALTH, 'fastpix_workspace_seen_id', 'fastpix_workspace_seen_name') as $opt) {   // the learned workspace must survive fixtures
    $saved[$opt] = get_option($opt, null);
}
// Restore even when a failed assertion aborts the run — a leaked fixture pair once shadowed the live one.
register_shutdown_function(function () use (&$saved) {
    foreach ($saved as $opt => $value) { $value === null ? delete_option($opt) : update_option($opt, $value, false); }
});
// The fixture rows belong to workspace 'ws-rd'; protected renders refuse other-workspace media (ASSUME-092).
update_option('fastpix_workspace_seen_id', 'ws-rd', false);

Creds::store('4f27c4c4-9a1e-4c2f-9d33-2b8f0a7c1e55', 'sk_selfcheck_render');
delete_option(\Fastpix\Fastpix_Api_Client::OPT_HEALTH);
add_filter('fastpix_api_backoff_seconds', '__return_zero');

// A throwaway RSA key stands in for the platform's — the platform is mocked below.
$rsa = openssl_pkey_new(array('private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA));
openssl_pkey_export($rsa, $pem);
$pub = openssl_pkey_get_details($rsa)['key'];

$requests = array();
add_filter('pre_http_request', function ($_pre, $args, $url) use (&$requests, $pem) {
    $requests[] = array('method' => $args['method'], 'url' => $url);
    if (strpos($url, '/iam/signing-keys/') !== false && !empty($GLOBALS['rd_key_gone'])) {   // the daily check finds the key gone
        return array('headers' => array(), 'body' => wp_json_encode(array('success' => false, 'error' => array('code' => 404, 'message' => 'signing key not found'))), 'response' => array('code' => 404, 'message' => ''));
    }
    $data = array();
    if (strpos($url, EP_SIGNING_KEYS) !== false) {
        $data = array('id' => 'kid-check-1', 'privateKey' => base64_encode($pem));
    } elseif (strpos($url, '/live/streams/') !== false) {
        $data = array('id' => 'live-check-1', 'playbackIds' => array(array('id' => 'pb-live-1')));
    }
    return array('headers' => array(), 'body' => wp_json_encode(array('success' => true, 'data' => $data)), 'response' => array('code' => 200, 'message' => ''));
}, 10, 3);

// ------------------------------------------------------------------ fixtures

$now = current_time('mysql', true);
$wpdb->query(SQL_DELETE_FROM . Schema::table('videos') . " WHERE media_id LIKE 'rd-%'");
$wpdb->query(SQL_DELETE_FROM . Schema::table('playback_ids') . " WHERE playback_id LIKE 'pb-rd-%'");
$wpdb->query(SQL_DELETE_FROM . Schema::table('live_streams') . " WHERE stream_id = 'live-check-1'");
function rd_video($media, $policy, $status = 'Ready', $extra = array()) {
    global $wpdb, $now;
    $wpdb->insert(Schema::table('videos'), array_merge(array(
        'media_id' => $media, 'workspace_id' => 'ws-rd', 'title' => 'Render ' . $media, 'description' => 'desc', 'status' => $status,
        'source' => 'Upload', 'access_policy' => $policy, 'author_id' => 1, 'aspect_ratio' => '16:9', 'duration_seconds' => 61.5,
        'created_at' => $now, 'updated_at' => $now,
    ), $extra));
    $id = (int) $wpdb->insert_id;
    $wpdb->insert(Schema::table('playback_ids'), array('video_id' => $id, 'playback_id' => 'pb-' . $media, 'access_policy' => $policy, 'created_at' => $now, 'updated_at' => $now));
    return $id;
}
$pub_id  = rd_video('rd-pub', 'public');
$priv_id = rd_video('rd-priv', 'private');
$drm_id  = rd_video('rd-drm', 'drm', 'Ready', array('drm_configuration_id' => ''));
$proc_id = rd_video('rd-proc', 'public', 'Processing');
$wpdb->insert(Schema::table('ai'), array('video_id' => $pub_id, 'kind' => 'chapters', 'state' => 'ready',
    'generated_json' => wp_json_encode(array('chapters' => array(array('title' => 'Intro', 'startTime' => '00:00:00', 'endTime' => '00:00:30')))), 'created_at' => $now, 'updated_at' => $now));
Cache::flush_group('embed'); Cache::flush_group('player_config'); Cache::flush_group('signed');
Signing::forget_key();
delete_option(Render::OPT_DRM_BAD);
update_option(Render::OPT_SEO, true, false);

// ------------------------------------------------- 1. settings precedence

$s = Render::settings(array('autoplay' => 'true', 'controls' => 'false', 'accentColour' => 'nope', 'aspectRatio' => '4:3', 'startTime' => '12'));
assert($s['autoplay'] === true && $s['controls'] === false && $s['muted'] === false, 'per-embed overrides beat site defaults; untouched keys keep the default [RULE-013]');
assert($s['accentColour'] === '#6D22CD' && $s['aspectRatio'] === '4/3' && $s['startTime'] === 12.0, 'values are sanitised once, at the merge');

// -------------------------------------------- 2. public: unsigned, cached, SEO

$html = Render::render('rd-pub', array('muted' => true), array('context' => 'shortcode'));
assert(strpos($html, '<fastpix-player') !== false && strpos($html, ATTR_PLAYBACK_ID_PUB) !== false, 'a public video renders the platform player [REQ-050]');
assert(strpos($html, QS_TOKEN) === false && strpos($html, ' muted') !== false && strpos($html, 'metadata-video-id="rd-pub"') !== false, 'no token, the override applied, analytics metadata attached [RULE-046]');
assert(strpos($html, 'thumbnail.png?time=1') !== false, 'poster defaults to the 1 s frame — never posterless [RULE-045]');
$again = Render::render('rd-pub', array('muted' => true), array('context' => 'shortcode'));
assert($again === $html, 'public markup is served from the 1 h cache [ARCH-09]');
assert(wp_script_is('fastpix-player', 'enqueued'), 'the vendored player is enqueued (never a CDN) [REQ-112]');
ob_start(); Fastpix\Fastpix_Render_Player::print_structured_data(); $ld = ob_get_clean();
assert(strpos($ld, '"@type":"VideoObject"') !== false && strpos($ld, '"@type":"Clip"') !== false && strpos($ld, '"name":"Intro"') !== false, 'structured data with chapter clips for a public video [REQ-075]');
assert(strpos($ld, '"contentUrl":"') !== false && strpos($ld, 'embedUrl') === false && strpos($ld, '1970-') === false, 'the manifest is contentUrl, and uploadDate is never 1970 [QA L13]');

// Shortcode aliases: id (canonical), videoid (UI-007), playback_id (v1).
foreach (array('[fastpix id="rd-pub"]', '[fastpix videoid="rd-pub" autoplay="true"]', '[fastpix playback_id="pb-rd-pub"]') as $sc) {
    assert(strpos(do_shortcode($sc), ATTR_PLAYBACK_ID_PUB) !== false, 'shortcode form resolves: ' . $sc);
}
assert(strpos(do_shortcode('[fastpix videoid="rd-pub" autoplay="true"]'), ATTR_AUTOPLAY) !== false, 'shortcode booleans as "true"; the player reads `auto-play` [UI-007]');
foreach (array('autoplay', 'autoplay="1"', 'autoplay="yes"', 'auto-play', 'auto-play="true"', 'auto_play="true"') as $spelling) {   // QA: autoplay shortcode
    assert(preg_match('/<fastpix-player[^>]*\sauto-play[\s>]/', do_shortcode('[fastpix id="rd-pub" ' . $spelling . ']')) === 1, 'autoplay as ' . $spelling);
}
assert(strpos(do_shortcode('[fastpix id="rd-pub" autoplay="false"]'), ATTR_AUTOPLAY) === false, 'autoplay="false" stays off');
// QA #16: while FastPix is deactivated a must-use fallback swallows [fastpix …] instead of printing it.
\Fastpix\Fastpix_Render::install_shortcode_fallback();
$fallback = (string) file_get_contents(WPMU_PLUGIN_DIR . '/' . \Fastpix\Fastpix_Render::FALLBACK_FILE);
assert(strpos($fallback, "!shortcode_exists('fastpix')") !== false && strpos($fallback, 'This video is not available right now.') !== false, 'the fallback only registers [fastpix] when nothing else has, and says the video is unavailable');
assert(strpos(do_shortcode('[fastpix id="rd-pub"]'), '<fastpix-player') !== false, 'with FastPix active the real shortcode still renders');
// Per-embed player controls (owner rulings 2026-08-19/20): one switch for the
// whole bar plus the two interaction flags; the per-part hide= list is retired.
$ctl = do_shortcode('[fastpix videoid="rd-pub" noclick nokeys starttime="30" accentcolor="#112233"]');
assert(strpos($ctl, ' disable-video-click') !== false && strpos($ctl, ' disable-keyboard-controls') !== false, 'noclick / nokeys');
assert(strpos($ctl, 'start-time="30"') !== false && strpos($ctl, 'accent-color="#112233"') !== false, 'start time and accent colour ride along');
// Captions: the player shows the first track by itself; the only switch it honours is disable-hidden-captions (QA F9).
assert(strpos(do_shortcode('[fastpix videoid="rd-pub"]'), ' disable-hidden-captions') !== false, 'captions off by default ⇒ disable-hidden-captions');
$cap = do_shortcode('[fastpix videoid="rd-pub" captionsdefault="true"]');
assert(strpos($cap, 'disable-hidden-captions') === false && strpos($cap, 'default-subtitle-track') === false, 'captions on ⇒ no switch at all (the player defaults to showing track 0)');
assert(strpos(do_shortcode('[fastpix videoid="rd-pub" nocontrols]'), ' hide-controls') !== false, 'nocontrols hides the whole bar');
assert(strpos(do_shortcode('[fastpix videoid="rd-pub" hide="seekbar"]'), 'style=') === false, 'the retired hide= list is ignored');

// ------------------------------------------ 3. private: signed at render

ob_start(); Fastpix\Fastpix_Render_Player::print_structured_data(); ob_end_clean();   // drop what the public shortcodes above collected
$requests = array();
$html = Render::render('rd-priv', array(), array('context' => 'block'));
assert(strpos($html, ATTR_TOKEN) !== false && strpos($html, 'thumbnail.png?time=1&amp;token=') !== false,
    'ONE token authorises playback and the thumbnail [REQ-052]');
assert(strpos($html, 'spritesheet-src') === false,
    'no spritesheet-src: the player treats it as a base URL and builds {base}/{pb}/spritesheet.json?token= itself (2026-08-20)');
assert(strpos($html, 'data-fp-config=') !== false && strpos($html, 'data-fp-exp=') !== false, 'the element carries the player-config URL + expiry for the edge-cache fallback [SEC-005]');
assert(defined('DONOTCACHEPAGE') && DONOTCACHEPAGE, 'the page is excluded from page caches [SEC-005]');
assert(count(array_filter($requests, function ($r) { return strpos($r['url'], EP_SIGNING_KEYS) !== false; })) === 1, 'the signing key was created on the platform, once');
assert(Signing::has_key(), 'and stored');

// A key the workspace no longer knows (deleted, or a stale idempotency replay — ASSUME-103) is
// forgotten on the daily check and re-created, instead of signing tokens every licence call rejects.
delete_transient(Signing::CHECK_TRANSIENT);
$GLOBALS['rd_key_gone'] = true;
$before = count($requests);
Signing::ensure_key();                                   // arms the day transient and schedules the check (no HTTP on the render path)
assert(count($requests) === $before, 'the render path never waits on the platform for the check');
Signing::check_key_job(array('kid' => 'kid-check-1'));   // the job finds the key gone and forgets it
Signing::ensure_key();                                   // the next protected render creates a real one
$GLOBALS['rd_key_gone'] = false;
$after = array_slice($requests, $before);
assert(count(array_filter($after, function ($r) { return $r['method'] === 'GET' && strpos($r['url'], '/iam/signing-keys/kid-check-1') !== false; })) === 1, 'the stored key is checked on the platform once a day');
assert(count(array_filter($after, function ($r) { return $r['method'] === 'POST' && substr($r['url'], -strlen(EP_SIGNING_KEYS)) === EP_SIGNING_KEYS; })) === 1, 'a key the platform no longer knows is re-created');
assert(get_transient(Signing::CHECK_TRANSIENT) === 'kid-check-1', 'a fresh key is not re-checked today');
preg_match('/ token="([^"]+)"/', $html, $m);
list($h, $p, $sig) = explode('.', $m[1]);
$claims = json_decode(base64_decode(strtr($p, '-_', '+/')), true);
assert($claims['aud'] === 'media:pb-rd-priv' && $claims['kid'] === 'kid-check-1' && $claims['exp'] > time() && $claims['exp'] <= time() + Signing::ttl(), 'RS256 JWT: kid, aud media:{playbackId}, short exp');
assert(openssl_verify($h . '.' . $p, base64_decode(strtr($sig, '-_', '+/')), $pub, OPENSSL_ALGO_SHA256) === 1, 'signed with the site key');
$second = Render::render('rd-priv', array(), array('context' => 'block'));
assert(strpos($second, ATTR_TOKEN) !== false, 'a private render is never served from the markup cache');
ob_start(); Fastpix\Fastpix_Render_Player::print_structured_data(); $ld = ob_get_clean();
assert($ld === '', 'structured data suppressed for private video [RULE-015]');
assert(strpos(Render::render('rd-priv', array('showTranscript' => true), array()), 'fastpix-transcript') === false, 'no transcript block for protected video [RULE-015]');

// SEC-006: a crafted attribute cannot promote a private video.
$html = Render::render('rd-priv', array('access_policy' => 'public', 'accessPolicy' => 'public'), array());
assert(strpos($html, ATTR_TOKEN) !== false, 'the policy is re-checked server-side; attributes cannot make it public [SEC-006]');

// ------------------------------------------------------- 4. DRM reasons

$_SERVER['HTTPS'] = 'on';
delete_option(\Fastpix\Fastpix_Settings_Page::OPT_DRM_CONFIG_ID);
$html = Render::render('rd-drm', array(), array());
assert(strpos($html, 'no longer resolves') !== false && strpos($html, '<fastpix-player') === false, 'an unresolvable DRM configuration renders poster + message, never a broken stream [ERR-061, RULE-016]');
assert(is_array(get_option(Render::OPT_DRM_BAD)), 'and raises the health failure');
assert(isset(Health::checks()['drm']) && Health::checks()['drm']['status'] === 'critical', 'Site Health shows it as critical');
update_option(\Fastpix\Fastpix_Settings_Page::OPT_DRM_CONFIG_ID, '3fa85f64-5717-4562-b3fc-2c963f66afa6', false);
$html = Render::render('rd-drm', array(), array());
assert(strpos($html, 'drm-token="') !== false && strpos($html, ' token="') !== false, 'with a configuration the DRM player carries both tokens [REQ-053]');
assert(get_option(Render::OPT_DRM_BAD) === false, 'and the health failure clears');
unset($_SERVER['HTTPS']);
// This dev site is http://localhost — browsers treat that as a secure context, so DRM renders; a real http host is refused with the reason.
$html = Render::render('rd-drm', array(), array());
assert(strpos($html, 'drm-token="') !== false, 'plain http on localhost is a secure context — the DRM player renders');
$fake_host = function () { return 'http://example.test'; };
add_filter('home_url', $fake_host);
$html = Render::render('rd-drm', array(), array());
remove_filter('home_url', $fake_host);
assert(strpos($html, 'secure (HTTPS)') !== false, 'an insecure page states the reason [ERR-060, SEC-007]');
$_SERVER['HTTPS'] = 'on';

// ------------------------------------ 5. processing / unavailable / fallback

$html = Render::render('rd-proc', array(), array());
assert(strpos($html, 'still being processed') !== false && strpos($html, 'thumbnail.png') !== false, 'a processing public video shows its poster and says so — publish now [RULE-010]');
$fallback = '<figure class="fastpix-embed--fallback"><a href="/p"><img src="https://images.fastpix.com/pb-rd-proc/thumbnail.png"></a></figure>';
assert(Render::render('rd-proc', array(), array('fallback' => $fallback)) === $fallback, 'the block\'s saved poster + link is what visitors see until ready');
assert(strpos(Render::render('rd-nope', array(), array()), 'not available') !== false, 'an unknown identifier renders a message, never a blank frame [RULE-016]');
assert(Render::render('rd-nope', array(), array('fallback' => $fallback)) === $fallback, 'a removed video renders the saved fallback [ERR-041, REQ-101]');
assert(strpos(Render::render_block(array('videoId' => ''), ''), 'No video selected') !== false, 'an empty block says so');
// Failed / orphaned / playback id gone: a stated message, never a live player or "still processing". [QA L10/L11/L12]
rd_video('rd-fail', 'public', 'Failed');
$orph_id = rd_video('rd-orph', 'public', 'Ready', array('error_code' => 'orphaned'));
$gone_id = rd_video('rd-gone', 'public', 'Ready');
$wpdb->update(Schema::table('playback_ids'), array('deleted_at' => $now), array('video_id' => $gone_id));
assert(strpos(Render::render('rd-fail', array(), array()), 'could not be processed') !== false, 'a Failed video says so, not "still being processed" [QA L11]');
$orph = Render::render('rd-orph', array(), array());
assert(strpos($orph, '<fastpix-player') === false && strpos($orph, 'no longer exists') !== false, 'an orphaned (404 on FastPix) video renders no player [QA L12]');
assert(Render::render('rd-orph', array(), array('fallback' => $fallback)) === $fallback, 'or the saved fallback');
$gone = Render::render('rd-gone', array(), array());
assert(strpos($gone, '<fastpix-player') === false && strpos($gone, 'not available') !== false, 'Ready with its playback id removed is "not available", not processing forever [QA L10]');
// The sync bumps updated_at when a playback id is tombstoned, so the 1 h public embed cache moves on. [QA L10]
$cached = Render::render('rd-pub', array(), array());
assert(strpos($cached, ATTR_PLAYBACK_ID_PUB) !== false, 'the public embed is cached with its playback id');
$before_upd = $wpdb->get_var($wpdb->prepare('SELECT updated_at FROM ' . Schema::table('videos') . ' WHERE id = %d', $pub_id));
sleep(1);
\Fastpix\Fastpix_Sync::apply_media(array('id' => 'rd-pub', 'status' => 'Ready', 'playbackIds' => array(array('id' => 'pb-rd-pub-2', 'accessPolicy' => 'public'))));
assert($wpdb->get_var($wpdb->prepare('SELECT updated_at FROM ' . Schema::table('videos') . ' WHERE id = %d', $pub_id)) !== $before_upd, 'a playback id change bumps updated_at');
assert(strpos(Render::render('rd-pub', array(), array()), 'playback-id="pb-rd-pub-2"') !== false, 'and the embed re-renders with the new playback id');
\Fastpix\Fastpix_Sync::apply_media(array('id' => 'rd-pub', 'status' => 'Ready', 'playbackIds' => array(array('id' => 'pb-rd-pub', 'accessPolicy' => 'public'))));   // restore for the assertions below
Cache::flush_group('embed');   // two playback changes inside one second share an updated_at (cache key) — test artefact

// QA F7: a PUBLIC embed rendered in the loop files its usage row at once — progress beats are
// validated against that table, so waiting for the nightly sweep meant no resume on a new page.
$f7_post = wp_insert_post(array('post_title' => 'rd f7 check', 'post_status' => 'publish', 'post_content' => '[fastpix id="rd-pub" loop="1"]'));
$GLOBALS['wp_query'] = new WP_Query(array('p' => $f7_post));
while (have_posts()) { the_post(); Render::render('rd-pub', array('loop' => true), array('context' => 'shortcode')); }
wp_reset_postdata();
assert((int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Schema::table('usage') . ' WHERE video_id = %d AND post_id = %d', $pub_id, $f7_post)) === 1, 'a public in-loop render files usage immediately');
$wpdb->delete(Schema::table('usage'), array('post_id' => $f7_post)); wp_delete_post($f7_post, true);

// -------------------------------------------- 6. player-config (API-P12)

// SEC-014: possession of a playback id mints nothing. Tokens only for a viewer
// who could read a page embedding the video (or a library viewer).
wp_set_current_user(0);
$res = rest_get_server()->dispatch(new WP_REST_Request('GET', REST_PLAYER_CONFIG_PRIV));
assert($res->is_error() && $res->get_status() === 403, 'anonymous with no readable embedding page is refused [SEC-014]');
$rd_post = wp_insert_post(array('post_title' => 'rd embed check', 'post_status' => 'publish', 'post_content' => '[fastpix id="rd-priv"]'));
$wpdb->insert(Schema::table('usage'), array('video_id' => $priv_id, 'post_id' => $rd_post, 'context' => 'shortcode', 'occurrences' => 1, 'created_at' => current_time('mysql', true), 'updated_at' => current_time('mysql', true)));
$res = rest_get_server()->dispatch(new WP_REST_Request('GET', REST_PLAYER_CONFIG_PRIV));
assert(!$res->is_error(), 'embedded on a public page ⇒ anonymous gets a token (the page is the gate)');
$gate = function () { return false; };
add_filter('fastpix_player_config_access', $gate);
assert(rest_get_server()->dispatch(new WP_REST_Request('GET', REST_PLAYER_CONFIG_PRIV))->get_status() === 403, 'a membership wall hooked on fastpix_player_config_access wins');
remove_filter('fastpix_player_config_access', $gate);
wp_set_current_user((int) get_users(array('role' => 'administrator', 'number' => 1, 'fields' => 'ID'))[0]);   // a library viewer may always fetch (previews)
$d = $res->get_data();
assert(!empty($d['token']) && $d['access_policy'] === 'private' && strpos($d['stream'], QS_TOKEN) !== false && !empty($d['exp']), 'a fresh token per call for private video');
$hdrs = $res->get_headers();
assert(isset($hdrs['Cache-Control']) && strpos($hdrs['Cache-Control'], 'no-store') !== false, 'the response is no-store [SEC-005]');
$res = rest_get_server()->dispatch(new WP_REST_Request('GET', '/fastpix/v1/player-config/pb-rd-pub'));
$d = $res->get_data();
assert(empty($d['token']) && strpos($d['stream'], '.m3u8') !== false && count($d['chapters']) === 1, 'public config is unsigned and carries chapters');
assert(rest_get_server()->dispatch(new WP_REST_Request('GET', '/fastpix/v1/player-config/pb-none'))->is_error(), 'unknown playback id ⇒ 404');
$registered = rest_get_server()->get_routes();
$route = $registered['/fastpix/v1/player-config/(?P<id>[A-Za-z0-9_-]+)'][0];
assert(is_callable($route['permission_callback']), 'the route is public via the rate-limited scaffolding, not unguarded [SEC-012]');

// ------------------------------------------------ 7. live: three states

$wpdb->insert(Schema::table('live_streams'), array('stream_id' => 'live-check-1', 'workspace_id' => 'ws-rd', 'name' => 'Town hall', 'status' => 'idle', 'created_at' => $now, 'updated_at' => $now));
assert(strpos(do_shortcode(SHORTCODE_LIVE), 'has not started yet') !== false, 'waiting before start [RULE-034]');
$wpdb->update(Schema::table('live_streams'), array('status' => 'active'), array('stream_id' => 'live-check-1'));
Cache::flush_group('live');
assert(strpos(do_shortcode(SHORTCODE_LIVE), 'stream-type="live-stream"') !== false, 'the live player while active');
$wpdb->update(Schema::table('live_streams'), array('status' => 'ended', 'recorded_video_id' => $pub_id), array('stream_id' => 'live-check-1'));
assert(strpos(do_shortcode(SHORTCODE_LIVE), ATTR_PLAYBACK_ID_PUB) !== false, 'the recording afterwards — no post edited');

// ---------------------------------------------------- 8. block registered

$block = WP_Block_Type_Registry::get_instance()->get_registered('fastpix/video');
assert($block && $block->api_version === 3 && is_callable($block->render_callback), 'fastpix/video is a dynamic apiVersion 3 block [FR-090]');
foreach (array('videoId', 'autoplay', 'muted', 'loop', 'controls', 'poster', 'startTime', 'aspectRatio', 'accentColour', 'showChapters', 'showTranscript', 'captionsDefault', 'lazyLoad', 'clickToPlay', 'keyboard') as $attr) {
    assert(isset($block->attributes[$attr]), 'block attribute ' . $attr . ' [SDD §20]');
}
$rendered = do_blocks('<!-- wp:fastpix/video {"videoId":"rd-pub","autoplay":true} --><figure class="fastpix-embed--fallback"><a href="/p"><img src="x"></a></figure><!-- /wp:fastpix/video -->');
assert(strpos($rendered, ATTR_PLAYBACK_ID_PUB) !== false && strpos($rendered, ATTR_AUTOPLAY) !== false, 'the saved block renders through the same renderer');
assert(strpos($rendered, QS_TOKEN) === false, 'saved markup never contains a playback URL or token [SEC-006]');

// ---------------------------------------------------------------- teardown

$wpdb->query(SQL_DELETE_FROM . Schema::table('ai') . " WHERE video_id IN ({$pub_id},{$priv_id},{$drm_id},{$proc_id})");
$wpdb->query(SQL_DELETE_FROM . Schema::table('playback_ids') . " WHERE playback_id LIKE 'pb-rd-%'");
$wpdb->query(SQL_DELETE_FROM . Schema::table('videos') . " WHERE media_id LIKE 'rd-%'");
$wpdb->query(SQL_DELETE_FROM . Schema::table('live_streams') . " WHERE stream_id = 'live-check-1'");
$wpdb->query(SQL_DELETE_FROM . Schema::table('logs') . " WHERE error_code = 'render_unsigned'");
if (!empty($rd_post)) { wp_delete_post($rd_post, true); $wpdb->delete(Schema::table('usage'), array('post_id' => $rd_post)); }
Cache::flush_group('embed'); Cache::flush_group('player_config'); Cache::flush_group('signed'); Cache::flush_group('live'); Cache::flush_group('videos');
delete_option(Render::OPT_DRM_BAD);
unset($_SERVER['HTTPS']);

echo "render + protection: all checks passed\n";
