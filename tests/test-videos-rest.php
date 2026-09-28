<?php
/**
 * Self-check for the video routes — API-P01/P02/P03/P06, FR-030/033/034,
 * REQ-036/037/039/091, WF-011, TEST-006 (route half).
 *
 *   docker compose exec wordpress php -d zend.assertions=1 \
 *     /var/www/html/wp-content/plugins/fastpix/tests/test-videos-rest.php
 */

require_once __DIR__ . '/bootstrap.php';

use Fastpix\Fastpix_Credentials as Creds;
use Fastpix\Fastpix_Schema as Schema;
use Fastpix\Fastpix_Videos_Rest;

const REST_VIDEOS_SLASH = '/videos/';
const SQL_WHERE_ID = ' WHERE id = %d';
const SQL_DELETE_FROM = 'DELETE FROM ';
const SUGGESTED_TITLE = 'From the dashboard';
const REST_TRACKS = '/tracks';
const REST_TRACK_FILE = '/track-file';
const REST_NS_VIDEOS = '/fastpix/v1/videos/';
const MIME_VTT = 'text/vtt';
const REST_BY_MEDIA_VR1 = '/videos/by-media/vr-media-1';

global $wpdb;

$saved = array();
foreach (array(Creds::OPT_TOKEN_ID, Creds::OPT_SECRET, \Fastpix\Fastpix_Connection::OPT_WORKSPACE_SEEN_ID) as $opt) {
    $saved[$opt] = get_option($opt, null);
}
// Restore even when a failed assertion aborts the run — a leaked fixture pair once shadowed the live one.
register_shutdown_function(function () use (&$saved) {
    foreach ($saved as $opt => $value) { $value === null ? delete_option($opt) : update_option($opt, $value, false); }
});
Creds::store('4f27c4c4-9a1e-4c2f-9d33-2b8f0a7c1e55', 'sk_selfcheck_videos');
update_option(\Fastpix\Fastpix_Connection::OPT_WORKSPACE_SEEN_ID, 'ws-vr', false);   // the fixtures' workspace is the connected one

$platform = array('requests' => array());
add_filter('pre_http_request', function ($pre, $args, $url) use (&$platform) {
    $platform['requests'][] = array('url' => $url, 'method' => $args['method']);

    if ($pre !== false) {
        return $pre;   // an earlier, more specific mock already answered
    }
    if ($args['method'] === 'POST' && substr($url, -7) === REST_TRACKS) {   // the platform answers a new track with its id
        $GLOBALS['fp_track_body'] = json_decode((string) $args['body'], true);
        $refused = !empty($GLOBALS['fp_track_refuse']);   // QA F12: the platform refuses the new file
        $body = $refused
            ? array('success' => false, 'error' => array('code' => 422, 'message' => 'payload validation failed'))
            : array('success' => true, 'data' => array('id' => 'trk-up-1'));
        return array('headers' => array(), 'body' => wp_json_encode($body), 'response' => array('code' => $refused ? 422 : 201, 'message' => ''));
    }

    return array('headers' => array(), 'body' => wp_json_encode(array('success' => true)), 'response' => array('code' => 200, 'message' => ''));
}, 10, 3);
add_filter('fastpix_api_backoff_seconds', '__return_zero');

$admins = get_users(array('role' => 'administrator', 'number' => 1, 'fields' => 'ID'));
$author_ids = get_users(array('role' => 'author', 'number' => 1, 'fields' => 'ID'));
wp_set_current_user($admins[0]);

function vreq($method, $path, $body = null) {
    // WP_REST_Request does not parse ?query strings out of the route.
    $query = array();
    if (strpos($path, '?') !== false) {
        list($path, $qs) = explode('?', $path, 2);
        parse_str($qs, $query);
    }

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

// Fixtures: 30 admin-owned + 2 author-owned videos. A run that died half-way leaves its rows behind, and a
// duplicate media_id would make every insert below fail silently — start from a clean slate.
$wpdb->query("DELETE FROM " . Schema::table('videos') . " WHERE workspace_id = 'ws-vr' AND media_id LIKE 'vr-%'");
$now = current_time('mysql', true);
$fixture_ids = array();
for ($i = 1; $i <= 30; $i++) {
    $wpdb->insert(Schema::table('videos'), array(
        'media_id' => 'vr-media-' . $i, 'workspace_id' => 'ws-vr', 'status' => $i % 5 === 0 ? 'Processing' : 'Ready',
        'source' => $i % 4 === 0 ? 'URL' : 'Upload', 'access_policy' => $i % 3 === 0 ? 'private' : 'public',
        'title' => 'Fixture video ' . $i, 'author_id' => $admins[0],
        'duration_seconds' => 60 + $i, 'created_at' => $now, 'updated_at' => $now,
    ));
    $fixture_ids[$i] = (int) $wpdb->insert_id;
}
foreach (array('own-a' => 'Author video A', 'own-b' => 'Author video B') as $slug => $title) {
    $wpdb->insert(Schema::table('videos'), array(
        'media_id' => 'vr-' . $slug, 'workspace_id' => 'ws-vr', 'status' => 'Ready', 'source' => 'Upload',
        'access_policy' => 'public', 'title' => $title, 'author_id' => $author_ids ? $author_ids[0] : 999,
        'created_at' => $now, 'updated_at' => $now,
    ));
    $fixture_ids[$slug] = (int) $wpdb->insert_id;
}
$wpdb->insert(Schema::table('playback_ids'), array(
    'video_id' => $fixture_ids[1], 'playback_id' => 'pb-vr-1', 'access_policy' => 'public',
    'created_at' => $now, 'updated_at' => $now,
));

// --------------------------------------------------- keyset pagination [REQ-039]

// A video of a previously connected workspace: kept in the table, never listed, read-only by id. [ASSUME-040]
$wpdb->insert(Schema::table('videos'), array('media_id' => 'vr-other-ws', 'workspace_id' => 'ws-old', 'status' => 'Ready', 'source' => 'Upload',
    'access_policy' => 'public', 'title' => 'Old workspace video', 'author_id' => $admins[0], 'created_at' => $now, 'updated_at' => $now));
$other_id = (int) $wpdb->insert_id;
$page = vreq('GET', '/videos?per_page=100')->get_data();
assert(!in_array($other_id, array_column($page['videos'], 'id'), true), 'only the connected workspace\'s videos are listed');
assert(vreq('GET', REST_VIDEOS_SLASH . $other_id)->get_data()['can_edit'] === false, 'a row of another workspace is read-only — the current credentials cannot manage it');
$wpdb->query($wpdb->prepare(SQL_DELETE_FROM . Schema::table('videos') . SQL_WHERE_ID, $other_id));

$response = vreq('GET', '/videos');
assert(!$response->is_error(), 'the list loads');
$page = $response->get_data();
assert(count($page['videos']) === 25, '25 rows per page, server-paged [REQ-039]');
assert($page['next'] !== null, 'a keyset cursor is returned');
$first_page_last = end($page['videos'])['id'];
assert($page['next'] === $first_page_last, 'the cursor is the last id — keyset, not offset');

$response = vreq('GET', '/videos?after=' . $page['next']);
$page2 = $response->get_data();
assert(count($page2['videos']) >= 1, 'the cursor fetches the next page');
assert($page2['videos'][0]['id'] < $first_page_last, 'strictly past the cursor');

// ------------------------------------------------------------------- filters

$response = vreq('GET', '/videos?status=Processing');
foreach ($response->get_data()['videos'] as $video) {
    assert($video['status'] === 'Processing', 'status filter filters');
}
$response = vreq('GET', '/videos?access=private&source=Upload');
foreach ($response->get_data()['videos'] as $video) {
    assert($video['access_policy'] === 'private' && $video['source'] === 'Upload', 'filters combine');
}

// ---------------------------------------------------- own-scope [REQ-091]

if ($author_ids) {
    wp_set_current_user($author_ids[0]);
    $response = vreq('GET', '/videos');
    $rows = $response->get_data()['videos'];
    assert(count($rows) === 2, 'an author sees own videos only');
    foreach ($rows as $video) {
        assert($video['author_id'] === (int) $author_ids[0], 'the limit is a query condition, not a filter [REQ-091]');
    }

    $response = vreq('GET', REST_VIDEOS_SLASH . $fixture_ids[1]);
    assert($response->is_error() && $response->get_status() === 404, 'another user\'s video is a 404, not a 403 leak');

    $response = vreq('PATCH', REST_VIDEOS_SLASH . $fixture_ids['own-a'], array('title' => 'Renamed by its author'));
    assert(!$response->is_error(), 'an author edits their own video');
    wp_set_current_user($admins[0]);
}

// ----------------------------------------------------------- PATCH ownership

$response = vreq('PATCH', REST_VIDEOS_SLASH . $fixture_ids[1], array('title' => 'Locally owned title'));
assert(!$response->is_error(), 'title edits save');
assert($response->get_data()['title'] === 'Locally owned title', 'and come back');

// Downloadable toggles call the platform (API-F06) before mirroring.
$platform['requests'] = array();
$response = vreq('PATCH', REST_VIDEOS_SLASH . $fixture_ids[1], array('downloadable' => 'video'));
assert(!$response->is_error(), 'the downloadable toggle saves');
$mp4_call = array_filter($platform['requests'], function ($r) { return strpos($r['url'], 'update-mp4Support') !== false; });
assert(count($mp4_call) === 1, 'mp4 support goes through the platform [API-F06]');

// Track operations ride on PATCH (ASSUME-029) and map to the tracks API.
$platform['requests'] = array();
$response = vreq('PATCH', REST_VIDEOS_SLASH . $fixture_ids[1], array('tracks' => array(
    array('action' => 'add', 'language_code' => 'de', 'language_name' => 'German', 'url' => 'https://203.0.113.9/subs.vtt'),
)));
assert(!$response->is_error(), 'a track add succeeds');
$track_call = array_filter($platform['requests'], function ($r) { return strpos($r['url'], REST_TRACKS) !== false && $r['method'] === 'POST'; });
assert(count($track_call) === 1, 'the open language list maps to POST /tracks [REQ-044, FR-041]');

// ------------------------------------------------- title sort [FR-030]

$response = vreq('GET', '/videos?orderby=title&order=asc&per_page=10');
$titles = array_map(function ($t) { return mb_strtolower((string) $t); }, array_column($response->get_data()['videos'], 'title'));
$sorted = $titles;
sort($sorted, SORT_STRING);   // the DB collation is case-insensitive; compare like for like
assert($titles === $sorted, 'title asc sorts');
$cursor = $response->get_data()['next'];
assert(is_string($cursor) && json_decode(base64_decode($cursor), true) !== null, 'the title cursor is composite, keyset not offset [REQ-039]');

$response2 = vreq('GET', '/videos?orderby=title&order=asc&per_page=10&after=' . urlencode($cursor));
$titles2 = array_map(function ($t) { return mb_strtolower((string) $t); }, array_column($response2->get_data()['videos'], 'title'));
assert(!empty($titles2) && strcmp($titles2[0], end($titles)) >= 0, 'page two continues past the cursor');

// ------------------------------------------- suggestions accept + dismiss

$wpdb->update(Schema::table('videos'), array('suggested_title' => SUGGESTED_TITLE, 'suggested_at' => $now), array('id' => $fixture_ids[8]));
$response = vreq('GET', REST_VIDEOS_SLASH . $fixture_ids[8]);
assert($response->get_data()['suggestions']['title'] === SUGGESTED_TITLE, 'a pending suggestion rides on the video [RULE-022]');

$response = vreq('PATCH', REST_VIDEOS_SLASH . $fixture_ids[8], array('suggestion' => 'accept_title'));
assert($response->get_data()['title'] === SUGGESTED_TITLE, 'accept copies the suggestion into the local title');
assert(empty($response->get_data()['suggestions']), 'and clears it');

$wpdb->update(Schema::table('videos'), array('suggested_title' => 'Another suggestion', 'suggested_at' => $now), array('id' => $fixture_ids[8]));
$response = vreq('PATCH', REST_VIDEOS_SLASH . $fixture_ids[8], array('suggestion' => 'dismiss'));
assert($response->get_data()['title'] === SUGGESTED_TITLE, 'dismiss keeps the local title');
assert(empty($response->get_data()['suggestions']), 'and drops the suggestion');

// -------------------------------------- generate-from-audio [FR-041]
// Real contract (docs, checked 2026-08-18): read the media, take its AUDIO track,
// POST /tracks/{audioTrackId}/generate-subtitles {languageName, languageCode (BCP 47)}
// → the new subtitle track's id.

$platform['requests'] = array();
$GLOBALS['fp_gen_mock'] = true;
add_filter('pre_http_request', function ($pre, $args, $url) {
    if ($GLOBALS['fp_gen_mock'] && $args['method'] === 'GET' && preg_match('#/on-demand/vr-media-1$#', $url)) {
        return array('headers' => array(), 'body' => wp_json_encode(array('success' => true, 'data' => array('id' => 'vr-media-1', 'tracks' => array(
            array('id' => 'vtrk', 'type' => 'video'), array('id' => 'atrk-1', 'type' => 'audio', 'languageCode' => 'und'),
        )))), 'response' => array('code' => 200, 'message' => ''));
    }
    if ($GLOBALS['fp_gen_mock'] && $args['method'] === 'POST' && strpos($url, '/tracks/atrk-1/generate-subtitles') !== false) {
        $GLOBALS['fp_gen_body'] = json_decode($args['body'], true);
        $GLOBALS['fp_gen_calls'] = ($GLOBALS['fp_gen_calls'] ?? 0) + 1;
        $dup = !empty($GLOBALS['fp_gen_dup_once']) && $GLOBALS['fp_gen_calls'] === 1;   // the platform still holds the language (QA F4)
        $body = $dup
            ? array('success' => false, 'error' => array('code' => 400, 'message' => 'Duplicate language languageName exists for the given media.'))
            : array('success' => true, 'data' => array('id' => 'trk-new-1', 'type' => 'subtitle', 'languageCode' => 'fr-FR'));
        return array('headers' => array(), 'body' => wp_json_encode($body), 'response' => array('code' => $dup ? 400 : 200, 'message' => ''));
    }

    return $pre;
}, 5, 3);

$response = vreq('PATCH', REST_VIDEOS_SLASH . $fixture_ids[1], array('tracks' => array(
    array('action' => 'generate', 'language_code' => 'fr', 'language_name' => 'French'),
)));
assert(!$response->is_error(), 'generate for a new language succeeds');
$calls = array_column($platform['requests'], 'url');
assert(count(array_filter($calls, function ($u) { return strpos($u, '/tracks/atrk-1/generate-subtitles') !== false; })) === 1, 'subtitles are generated FROM the audio track');
assert($GLOBALS['fp_gen_body']['languageCode'] === 'fr-FR' && $GLOBALS['fp_gen_body']['languageName'] === 'French', 'the body carries the BCP 47 code and the name');
assert($wpdb->get_var("SELECT state FROM " . Schema::table('tracks') . " WHERE track_id = 'trk-new-1'") === 'generating', 'the new track shows Generating until its webhook');
// A track still "generating" past the threshold (lost webhook) reads as failed so Retry appears; the row is untouched. [B7]
$wpdb->update(Schema::table('tracks'), array('updated_at' => gmdate('Y-m-d H:i:s', time() - Fastpix_Videos_Rest::TRACK_STUCK_AFTER - 60)), array('track_id' => 'trk-new-1'));
$stuck = array_column(vreq('GET', REST_VIDEOS_SLASH . $fixture_ids[1])->get_data()['tracks'], 'state', 'track_id');
assert($stuck['trk-new-1'] === 'failed' && $wpdb->get_var("SELECT state FROM " . Schema::table('tracks') . " WHERE track_id = 'trk-new-1'") === 'generating', 'a stuck generating track is reported failed [B7]');
// Regenerate for a language the platform still holds: generate first, delete the old track only on
// "Duplicate language", then retry — never delete up front (that lost the track for good). [QA F4]
$wpdb->insert(Schema::table('tracks'), array('video_id' => $fixture_ids[1], 'track_id' => 'trk-old-fr', 'type' => 'subtitle', 'language_code' => 'fr', 'source' => 'generated', 'state' => 'ready', 'created_at' => current_time('mysql', true), 'updated_at' => current_time('mysql', true)));
$GLOBALS['fp_gen_calls'] = 0; $GLOBALS['fp_gen_dup_once'] = true; $platform['requests'] = array();
$response = vreq('PATCH', REST_VIDEOS_SLASH . $fixture_ids[1], array('tracks' => array(array('action' => 'generate', 'language_code' => 'fr', 'language_name' => 'French', 'track_id' => 'trk-old-fr'))));
assert(!$response->is_error(), 'a regenerate refused as duplicate is retried after removing the old track');
$seq = array_map(function ($r) { return $r['method'] . ' ' . preg_replace('#^.*/on-demand/[^/]+#', '', $r['url']); }, array_filter($platform['requests'], function ($r) { return strpos($r['url'], REST_TRACKS) !== false; }));
$seq = array_values($seq);
assert(count($seq) === 3 && strpos($seq[0], 'POST') === 0 && $seq[1] === 'DELETE /tracks/trk-old-fr' && strpos($seq[2], 'POST') === 0, 'order is generate → (duplicate) → delete old → generate: ' . implode(' | ', $seq));
assert($wpdb->get_var("SELECT deleted_at IS NOT NULL FROM " . Schema::table('tracks') . " WHERE track_id = 'trk-old-fr'") === '1', 'the old row is retired only once the platform refused the duplicate');
$GLOBALS['fp_gen_dup_once'] = false;
// No old track id and the platform refuses: a clear 409, nothing deleted.
$GLOBALS['fp_gen_calls'] = 0; $GLOBALS['fp_gen_dup_once'] = true;
$response = vreq('PATCH', REST_VIDEOS_SLASH . $fixture_ids[1], array('tracks' => array(array('action' => 'generate', 'language_code' => 'fr', 'language_name' => 'French'))));
assert($response->is_error() && $response->as_error()->get_error_code() === 'fastpix_track_exists', 'a duplicate without a known old track is a clear refusal');
$GLOBALS['fp_gen_dup_once'] = false;
$GLOBALS['fp_gen_mock'] = false;
$wpdb->query(SQL_DELETE_FROM . Schema::table('tracks') . " WHERE track_id IN ('trk-new-1', 'trk-old-fr')");

// ------------------------------------------------ track-file upload route

$vtt = wp_upload_bits('upcheck-subs.vtt', null, "WEBVTT\n\n00:00.000 --> 00:02.000\nHello");
assert(empty($vtt['error']), 'a fixture .vtt can be written');
// FastPix fetches the file from this site: a host the internet cannot reach is refused up front (QA F12).
$wpdb->query(SQL_DELETE_FROM . Schema::table('tracks') . " WHERE source = 'uploaded' AND video_id = " . (int) $fixture_ids[1]);
$request = new WP_REST_Request('POST', REST_NS_VIDEOS . $fixture_ids[1] . REST_TRACK_FILE);
$request->set_param('language_code', 'de');
$request->set_file_params(array('file' => array('name' => 'upcheck-subs.vtt', 'type' => MIME_VTT, 'tmp_name' => $vtt['file'], 'error' => 0, 'size' => filesize($vtt['file']))));
add_filter('fastpix_migration_reachable', '__return_false');
$response = rest_get_server()->dispatch($request);
remove_filter('fastpix_migration_reachable', '__return_false');
assert($response->is_error() && $response->as_error()->get_error_code() === 'fastpix_subtitle_unreachable', 'an unreachable site is told so instead of a silent platform failure');
add_filter('fastpix_migration_reachable', '__return_true');

$request = new WP_REST_Request('POST', REST_NS_VIDEOS . $fixture_ids[1] . REST_TRACK_FILE);
$request->set_param('language_code', 'de');
$request->set_param('language_name', 'German');
$request->set_file_params(array('file' => array(
    'name' => 'upcheck-subs.vtt', 'type' => MIME_VTT, 'tmp_name' => $vtt['file'],
    'error' => 0, 'size' => filesize($vtt['file']),
)));
$platform['requests'] = array();
$response = rest_get_server()->dispatch($request);
assert(!$response->is_error(), 'a .vtt uploads');
assert(strpos($response->get_data()['file_url'], '.vtt') !== false, 'and lands at a public URL for the platform to fetch [ASSUME-032]');
$track_posts = array_filter($platform['requests'], function ($r) { return strpos($r['url'], REST_TRACKS) !== false && $r['method'] === 'POST'; });
assert(count($track_posts) === 1, 'its URL feeds POST /tracks');
assert(isset($GLOBALS['fp_track_body']['tracks']) && $GLOBALS['fp_track_body']['tracks']['languageCode'] === 'de-DE' && $GLOBALS['fp_track_body']['tracks']['type'] === 'subtitle', 'the body rides under `tracks` with a BCP 47 code (verified live 2026-09-20)');
assert((int) $wpdb->get_var("SELECT COUNT(*) FROM " . Schema::table('tracks') . " WHERE source = 'uploaded' AND state = 'processing' AND video_id = " . (int) $fixture_ids[1]) === 1, 'the uploaded track is visible at once, as Processing, until its webhook');

// QA F12: a replace is remove + add (the platform allows nothing else). When the add is refused the
// answer says the old track is gone, and no stray .vtt stays in uploads.
$GLOBALS['fp_track_refuse'] = true;
$before = glob(wp_upload_dir()['path'] . '/upcheck-subs*.vtt');
$request = new WP_REST_Request('POST', REST_NS_VIDEOS . $fixture_ids[1] . REST_TRACK_FILE);
$request->set_param('language_code', 'de'); $request->set_param('language_name', 'German'); $request->set_param('track_id', 'trk-up-1');
$request->set_file_params(array('file' => array('name' => 'upcheck-subs.vtt', 'type' => MIME_VTT, 'tmp_name' => $vtt['file'], 'error' => 0, 'size' => filesize($vtt['file']))));
$response = rest_get_server()->dispatch($request);
$GLOBALS['fp_track_refuse'] = false;
assert($response->is_error() && $response->as_error()->get_error_code() === 'fastpix_track_replace_half', 'a refused replace says the old track is gone');
assert(glob(wp_upload_dir()['path'] . '/upcheck-subs*.vtt') == $before, 'and leaves no stray .vtt behind');
$wpdb->query('UPDATE ' . Schema::table('tracks') . " SET deleted_at = NULL WHERE track_id = 'trk-up-1'");

// An .srt is converted to WebVTT here — WordPress's own mime sniff would reject it (QA F12).
$srt = wp_upload_bits('upcheck-subs.srt', null, "1\r\n00:00:01,000 --> 00:00:02,500\r\nHallo\r\n");
$request = new WP_REST_Request('POST', REST_NS_VIDEOS . $fixture_ids[1] . REST_TRACK_FILE);
$request->set_param('language_code', 'de');
$request->set_param('language_name', 'German');
$request->set_file_params(array('file' => array('name' => 'upcheck-subs.srt', 'type' => 'text/plain', 'tmp_name' => $srt['file'], 'error' => 0, 'size' => filesize($srt['file']))));
$response = rest_get_server()->dispatch($request);
assert(!$response->is_error(), 'an .srt uploads');
$stored = str_replace(wp_upload_dir()['baseurl'], wp_upload_dir()['basedir'], $response->get_data()['file_url']);
assert(substr($stored, -4) === '.vtt' && strpos(file_get_contents($stored), "WEBVTT\n\n") === 0 && strpos(file_get_contents($stored), '00:00:01.000 --> 00:00:02.500') !== false, 'stored as .vtt with a WEBVTT header and dot timestamps');
wp_delete_file($stored); wp_delete_file($srt['file']);

// Upload has its own language, any BCP 47 tag: the code passes as-is (never re-labelled en-US) and the
// name is letters only — the platform 422s "Azerbaijani (Cyrillic)" (verified live 2026-09-25).
$request = new WP_REST_Request('POST', REST_NS_VIDEOS . $fixture_ids[1] . REST_TRACK_FILE);
$request->set_param('language_code', 'az-Cyrl'); $request->set_param('language_name', 'Azerbaijani (Cyrillic)');
$request->set_file_params(array('file' => array('name' => 'upcheck-subs.vtt', 'type' => MIME_VTT, 'tmp_name' => $vtt['file'], 'error' => 0, 'size' => filesize($vtt['file']))));
$response = rest_get_server()->dispatch($request);
assert(!$response->is_error() && $GLOBALS['fp_track_body']['tracks']['languageCode'] === 'az-Cyrl' && $GLOBALS['fp_track_body']['tracks']['languageName'] === 'AzerbaijaniCyrillic', 'a script-tagged upload keeps its code, name made letters-only');
wp_delete_file(str_replace(wp_upload_dir()['baseurl'], wp_upload_dir()['basedir'], $response->get_data()['file_url']));
$request->set_param('language_code', 'af'); $request->set_param('language_name', 'Afrikaans');
$response = rest_get_server()->dispatch($request);
assert(!$response->is_error() && $GLOBALS['fp_track_body']['tracks']['languageCode'] === 'af', 'a bare code outside the generate list is not turned into en-US');
wp_delete_file(str_replace(wp_upload_dir()['baseurl'], wp_upload_dir()['basedir'], $response->get_data()['file_url']));
$wpdb->query(SQL_DELETE_FROM . Schema::table('tracks') . " WHERE source = 'uploaded' AND video_id = " . (int) $fixture_ids[1]);
remove_filter('fastpix_migration_reachable', '__return_true');

$request = new WP_REST_Request('POST', REST_NS_VIDEOS . $fixture_ids[1] . REST_TRACK_FILE);
$request->set_file_params(array('file' => array(
    'name' => 'nope.exe', 'type' => 'application/octet-stream', 'tmp_name' => $vtt['file'],
    'error' => 0, 'size' => 10,
)));
$response = rest_get_server()->dispatch($request);
assert($response->is_error() && $response->as_error()->get_error_code() === 'fastpix_bad_subtitle', 'only .vtt/.srt are accepted');

// -------------------------------------------------------- deletion [WF-011]

// Local-only delete: tombstone, platform untouched.
$platform['requests'] = array();
$response = vreq('DELETE', REST_VIDEOS_SLASH . $fixture_ids[2] . '?delete_on_platform=false');
assert(!$response->is_error() && $response->get_data()['tombstoned'] === true, 'local delete tombstones');
$platform_deletes = array_filter($platform['requests'], function ($r) { return $r['method'] === 'DELETE'; });
assert(count($platform_deletes) === 0, 'the platform copy is untouched unless confirmed [REQ-036]');
$row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Schema::table('videos') . SQL_WHERE_ID, $fixture_ids[2]), ARRAY_A);
assert($row['deleted_at'] !== null, 'tombstoned, never dropped [FR-034]');

// Confirmed platform delete.
$platform['requests'] = array();
vreq('DELETE', REST_VIDEOS_SLASH . $fixture_ids[3] . '?delete_on_platform=true');
$platform_deletes = array_filter($platform['requests'], function ($r) { return $r['method'] === 'DELETE'; });
assert(count($platform_deletes) === 1, 'a confirmed delete removes the platform copy too [REQ-036]');

// A tombstoned row lists as Unavailable, still present.
$response = vreq('GET', '/videos?per_page=100');
$statuses = array_column($response->get_data()['videos'], 'status', 'id');
assert(isset($statuses[$fixture_ids[2]]) && $statuses[$fixture_ids[2]] === 'Unavailable', 'a deleted video stays listed as Unavailable [FR-034]');

// "Remove from library": only an Unavailable record can be purged, and then it is gone for good. [ASSUME-102]
$response = vreq('GET', '/videos?per_page=100&status=Unavailable');
assert(in_array($fixture_ids[2], array_column($response->get_data()['videos'], 'id'), true), 'the Unavailable filter lists the tombstone');
$response = vreq('DELETE', REST_VIDEOS_SLASH . $fixture_ids[1] . '?purge=true');
assert($response->is_error() && $response->get_status() === 409, 'a live video cannot be purged — delete it first');
$response = vreq('DELETE', REST_VIDEOS_SLASH . $fixture_ids[2] . '?purge=true');
assert(!$response->is_error() && $response->get_data()['purged'] === true, 'an Unavailable record can be removed from the library');
assert($wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Schema::table('videos') . SQL_WHERE_ID, $fixture_ids[2])) === '0', 'and the row is gone');

// ------------------------------------------------------------ usage [REQ-037]

$usage_post = wp_insert_post(array('post_title' => 'vr usage check', 'post_status' => 'publish', 'post_author' => 1));   // its own post: a site may have deleted post 1
$wpdb->insert(Schema::table('usage'), array(
    'video_id' => $fixture_ids[1], 'post_id' => $usage_post, 'context' => 'block', 'occurrences' => 2,
    'created_at' => $now, 'updated_at' => $now,
));
$response = vreq('GET', REST_VIDEOS_SLASH . $fixture_ids[1] . '/usage');
assert(!$response->is_error() && count($response->get_data()['posts']) === 1, 'usage lists the posts using a video [REQ-037]');
$wpdb->delete(Schema::table('usage'), array('post_id' => $usage_post));
wp_delete_post($usage_post, true);

// ------------------------------------------------------------- embed chip

$response = vreq('GET', REST_VIDEOS_SLASH . $fixture_ids[1] . '/embed');
$embed = $response->get_data();
assert(strpos($embed['shortcode'], 'vr-media-1') !== false, 'the shortcode carries the media id [OQ-011 assumption]');
assert(strpos($embed['constraints'], 'Public') === 0, 'embedding constraints are stated where the choice is made [REQ-054]');

// ---------------------------------------------------------------- bulk queue

as_unschedule_all_actions('fastpix_bulk_item');
$response = vreq('POST', '/videos/bulk', array('action' => 'rerun_ai', 'ids' => array($fixture_ids[4], $fixture_ids[5])));
assert(!$response->is_error(), 'bulk accepts');
assert($response->get_data()['queued'] === 2 && $response->get_data()['background'] === true, 'queued in the background, returns immediately [FR-033]');
assert(as_has_scheduled_action('fastpix_bulk_item') !== false, 'the job group exists');

// The job itself respects scope and acts: a re-run requests the FULL set (not only subtitles), and only on Ready rows. [QA L5/X14]
$signals = array();
add_action('fastpix_media_ready', function ($media_id) use (&$signals) { $signals[] = $media_id; });
as_unschedule_all_actions(\Fastpix\Fastpix_Ai::HOOK_REQUEST);
Fastpix_Videos_Rest::bulk_item_job(array('video_id' => $fixture_ids[4], 'bulk_action' => 'rerun_ai', 'author_scope' => null));
$ai_jobs = as_get_scheduled_actions(array('hook' => \Fastpix\Fastpix_Ai::HOOK_REQUEST, 'status' => \ActionScheduler_Store::STATUS_PENDING, 'per_page' => 5));
$ai_args = $ai_jobs ? array_values($ai_jobs)[0]->get_args() : null;
$ai_args = ($ai_args && isset($ai_args[0]) && is_array($ai_args[0])) ? $ai_args[0] : $ai_args;   // Fastpix_Jobs wraps args in one positional array
assert($signals === array() && $ai_args && $ai_args['media_id'] === 'vr-media-4' && !isset($ai_args['kinds']), 'a bulk re-run queues the full AI set directly — not the subtitles-only media-ready signal [QA L5]');
as_unschedule_all_actions(\Fastpix\Fastpix_Ai::HOOK_REQUEST);
Fastpix_Videos_Rest::bulk_item_job(array('video_id' => $fixture_ids[5], 'bulk_action' => 'rerun_ai', 'author_scope' => null));   // fixture 5 is Processing
assert(as_has_scheduled_action(\Fastpix\Fastpix_Ai::HOOK_REQUEST) === false, 'nothing is requested for a video that is not Ready yet');

// The block editor resolves its saved media id directly. [QA L9]
$response = vreq('GET', REST_BY_MEDIA_VR1);
assert(!$response->is_error() && $response->get_data()['id'] === $fixture_ids[1] && $response->get_data()['media_id'] === 'vr-media-1', 'GET /videos/by-media/{mediaId} finds the row');
assert(vreq('GET', '/videos/by-media/vr-nope')->get_status() === 404, 'an unknown media id is a 404');
// Another author's video embedded in a post: the inspector's fields only — nothing else. (QA L9)
if ($author_ids) {
    wp_set_current_user($author_ids[0]);
    $response = vreq('GET', REST_BY_MEDIA_VR1);
    $keys = array_keys($response->get_data());
    sort($keys);
    assert(!$response->is_error() && $keys === array('access_policy', 'duration', 'media_id', 'poster', 'status', 'title'), 'a non-owner gets the minimal block shape, exactly');
    assert(isset(vreq('GET', '/videos/by-media/vr-own-a')->get_data()['can_edit']), 'an owned row keeps the full shape');
    assert(vreq('GET', '/videos/by-media/vr-nope')->get_status() === 404, 'an unknown media id is still a 404 for an author');
    assert(vreq('GET', REST_VIDEOS_SLASH . $fixture_ids[1])->get_status() === 404, 'the by-id route stays own-scoped [REQ-091]');
}
$nocap = wp_insert_user(array('user_login' => 'vr-nocap-' . wp_generate_password(6, false), 'user_pass' => wp_generate_password(), 'role' => 'subscriber'));
wp_set_current_user($nocap);
$status = vreq('GET', REST_BY_MEDIA_VR1)->get_status();
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user($nocap);
wp_set_current_user($admins[0]);
assert($status === 403, 'no video capability is a 403');

// A refused track op leaves the dashboard title untouched — the title push is last. [QA L18]
$platform['requests'] = array();
$response = vreq('PATCH', REST_VIDEOS_SLASH . $fixture_ids[1], array('title' => 'Never pushed', 'tracks' => array(array('action' => 'bogus'))));
assert($response->is_error(), 'the bad track op is refused');
assert(count(array_filter($platform['requests'], function ($r) { return $r['method'] === 'PATCH' && substr($r['url'], -strlen('/on-demand/vr-media-1')) === '/on-demand/vr-media-1'; })) === 0, 'and the title was not pushed to FastPix first');

Fastpix_Videos_Rest::bulk_item_job(array('video_id' => $fixture_ids[6], 'bulk_action' => 'delete', 'author_scope' => (int) $admins[0], 'delete_on_platform' => false));
assert($wpdb->get_var($wpdb->prepare('SELECT deleted_at FROM ' . Schema::table('videos') . SQL_WHERE_ID, $fixture_ids[6])) !== null, 'a bulk delete tombstones');

Fastpix_Videos_Rest::bulk_item_job(array('video_id' => $fixture_ids[7], 'bulk_action' => 'delete', 'author_scope' => 424242));
assert($wpdb->get_var($wpdb->prepare('SELECT deleted_at FROM ' . Schema::table('videos') . SQL_WHERE_ID, $fixture_ids[7])) === null, 'the job re-applies the author scope — no escalation through the queue [REQ-091]');

// ---------------------------------------------------------------- teardown

as_unschedule_all_actions('fastpix_bulk_item');
as_unschedule_all_actions('fastpix_search_reindex');
$in = implode(',', array_map('intval', $fixture_ids));
$wpdb->query(SQL_DELETE_FROM . Schema::table('playback_ids') . " WHERE video_id IN ({$in})");
$wpdb->query(SQL_DELETE_FROM . Schema::table('usage') . " WHERE video_id IN ({$in})");
$wpdb->query(SQL_DELETE_FROM . Schema::table('search_index') . " WHERE video_id IN ({$in})");
$wpdb->query(SQL_DELETE_FROM . Schema::table('videos') . " WHERE media_id LIKE 'vr-%'");
foreach ($saved as $opt => $value) {
    if ($value === null) { delete_option($opt); } else { update_option($opt, $value, false); }
}
wp_set_current_user(0);

echo "video routes: all checks passed\n";
