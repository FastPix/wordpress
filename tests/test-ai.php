<?php
/**
 * Self-check for AI enrichment — WF-005, FR-040/041, REQ-040…045,
 * RULE-010/011/012, ERR-021/022, TEST-007.
 *
 *   docker compose exec wordpress php -d zend.assertions=1 \
 *     /var/www/html/wp-content/plugins/fastpix/tests/test-ai.php
 */

require_once __DIR__ . '/bootstrap.php';

use Fastpix\Fastpix_Ai as Ai;
use Fastpix\Fastpix_Api_Client as Client;
use Fastpix\Fastpix_Cache as Cache;
use Fastpix\Fastpix_Credentials as Creds;
use Fastpix\Fastpix_Schema as Schema;
use Fastpix\Fastpix_Uploads as Uploads;
use Fastpix\Fastpix_Videos_Rest as Videos;
use Fastpix\Fastpix_Webhooks as Webhooks;

const SQL_DELETE_FROM = 'DELETE FROM ';
const SQL_WHERE_VIDEO_ID = ' WHERE video_id = %d';
const EP_GENERATE_SUBTITLES = '/tracks/aud-1/generate-subtitles';
const REST_VIDEOS = '/fastpix/v1/videos/';
const SQL_WHERE_ID = ' WHERE id = %d';
const SQL_WHERE_AI_MEDIA = " WHERE media_id = 'ai-media-1'";
const SQL_WHERE_VIDEO_TRACK = ' WHERE video_id = %d AND track_id = %s';

global $wpdb;

$saved = array();
foreach (array(Creds::OPT_TOKEN_ID, Creds::OPT_SECRET, Client::OPT_HEALTH, 'fastpix_workspace_seen_id', 'fastpix_workspace_seen_name') as $opt) {   // the learned workspace must survive fixtures
    $saved[$opt] = get_option($opt, null);
}
// Restore even when a failed assertion aborts the run — a leaked fixture pair once shadowed the live one.
register_shutdown_function(function () use (&$saved) {
    foreach ($saved as $opt => $value) { $value === null ? delete_option($opt) : update_option($opt, $value, false); }
});
// The fixture rows belong to workspace 'ws-ai'; writes on another workspace's row answer 409 (ASSUME-092).
update_option('fastpix_workspace_seen_id', 'ws-ai', false);

Creds::store('4f27c4c4-9a1e-4c2f-9d33-2b8f0a7c1e55', 'sk_selfcheck_ai');
delete_option(Client::OPT_HEALTH);
add_filter('fastpix_api_backoff_seconds', '__return_zero');

// ------------------------------------------------------------- transport mock

$mock = array('requests' => array(), 'media' => array(), 'fail_paths' => array());
add_filter('pre_http_request', function ($_pre, $args, $url) use (&$mock) {
    $mock['requests'][] = array('method' => $args['method'], 'url' => $url, 'body' => isset($args['body']) ? json_decode($args['body'], true) : null);
    if (substr($url, -4) === '.vtt') {   // the subtitle file on the stream host [ASSUME-086]
        return array('headers' => array(), 'body' => "WEBVTT\nKind: captions\n\n00:00:00.000 --> 00:00:02.439\nPerfect gift.\n\n00:01:02.359 --> 00:01:04.000\nLine <i>two</i>\n", 'response' => array('code' => 200, 'message' => ''));
    }
    foreach ($mock['fail_paths'] as $needle) {
        if (strpos($url, $needle) !== false) {
            return array('headers' => array(), 'body' => wp_json_encode(array('success' => false, 'error' => array('code' => 422, 'message' => 'audio too quiet in first 30s'))),
                         'response' => array('code' => 422, 'message' => ''));
        }
    }
    $data = $args['method'] === 'GET' ? $mock['media'] : array('mediaId' => 'ai-media-1', 'ok' => true);
    return array('headers' => array(), 'body' => wp_json_encode(array('success' => true, 'data' => $data)), 'response' => array('code' => 200, 'message' => ''));
}, 10, 3);

function ai_calls(&$mock, $method, $needle) {
    return array_values(array_filter($mock['requests'], function ($r) use ($method, $needle) {
        return $r['method'] === $method && strpos($r['url'], $needle) !== false;
    }));
}

// ------------------------------------------------------------------ fixture

$now = current_time('mysql', true);
// A run that died half-way leaves the old row's tracks/AI behind, and track ids are unique — clear them with it.
foreach ($wpdb->get_col("SELECT id FROM " . Schema::table('videos') . SQL_WHERE_AI_MEDIA) as $old_id) {
    foreach (array('ai', 'tracks', 'search_index') as $child) { $wpdb->query($wpdb->prepare(SQL_DELETE_FROM . Schema::table($child) . SQL_WHERE_VIDEO_ID, (int) $old_id)); }
}
$wpdb->query(SQL_DELETE_FROM . Schema::table('videos') . SQL_WHERE_AI_MEDIA);
$wpdb->insert(Schema::table('videos'), array(
    'media_id' => 'ai-media-1', 'workspace_id' => 'ws-ai', 'title' => 'AI check', 'status' => 'Ready', 'source' => 'Upload',
    'access_policy' => 'public', 'author_id' => 1, 'created_at' => $now, 'updated_at' => $now,
));
$video_id = (int) $wpdb->insert_id;
$wpdb->query($wpdb->prepare(SQL_DELETE_FROM . Schema::table('ai') . SQL_WHERE_VIDEO_ID, $video_id));
$wpdb->query($wpdb->prepare(SQL_DELETE_FROM . Schema::table('tracks') . SQL_WHERE_VIDEO_ID, $video_id));
$wpdb->query(SQL_DELETE_FROM . Schema::table('uploads') . " WHERE filename = 'ai-check.mp4'");
// The batch snapshot the upload was created with [REQ-017]: chapters on, summary medium, moderation off, subtitles en.
$wpdb->insert(Schema::table('uploads'), array(
    'upload_id' => 'up-ai-1', 'filename' => 'ai-check.mp4', 'filesize' => 100, 'chunk_size' => 1, 'state' => 'completed',
    'settings_json' => wp_json_encode(\Fastpix\Fastpix_Uploads_Settings::settings_snapshot(array('chapters' => true, 'summary' => 'medium', 'moderation' => 'off', 'subtitles' => 'en'))),
    'video_id' => $video_id, 'user_id' => 1, 'created_at' => $now, 'updated_at' => $now,
));
$mock['media'] = array('id' => 'ai-media-1', 'status' => 'Ready',
    'tracks' => array(array('id' => 'aud-1', 'type' => 'audio', 'status' => 'available', 'languageCode' => 'und')));

// -------------------------------------------- 1. auto-request on media ready

as_unschedule_all_actions(Ai::HOOK_REQUEST);
do_action('fastpix_media_ready', 'ai-media-1');
assert(as_has_scheduled_action(Ai::HOOK_REQUEST) !== false, 'media ready queues the request job — nobody had to ask [REQ-040]');
as_unschedule_all_actions(Ai::HOOK_REQUEST);

$mock['requests'] = array();
Ai::request_job(array('media_id' => 'ai-media-1', 'attempt' => 1));

assert(count(ai_calls($mock, 'PATCH', '/on-demand/ai-media-1/chapters')) === 1, 'chapters requested [API-F05]');
assert(ai_calls($mock, 'PATCH', '/on-demand/ai-media-1/chapters')[0]['body'] === array('chapters' => true), 'chapters body {chapters:true}');
$sum = ai_calls($mock, 'PATCH', '/on-demand/ai-media-1/summary');
assert(count($sum) === 1 && $sum[0]['body']['generate'] === true && $sum[0]['body']['summaryLength'] === 100, 'summary requested with generate + summaryLength');
assert(count(ai_calls($mock, 'PATCH', '/on-demand/ai-media-1/named-entities')) === 1, 'named entities requested');
assert(count(ai_calls($mock, 'PATCH', '/on-demand/ai-media-1/moderation')) === 0, 'moderation off in the batch ⇒ not requested (per stored settings)');
$gen = ai_calls($mock, 'POST', EP_GENERATE_SUBTITLES);
assert(count($gen) === 1 && $gen[0]['body']['languageCode'] === 'en', 'subtitles generated into the audio track for the chosen language');

$rows = array_column(Ai::rows($video_id), 'state', 'kind');
assert($rows === array('chapters' => 'requested', 'entities' => 'requested', 'summary' => 'requested'), 'one row per (video, kind), all requested');
$v = $wpdb->get_row($wpdb->prepare('SELECT ai_state, status FROM ' . Schema::table('videos') . SQL_WHERE_ID, $video_id), ARRAY_A);
assert($v['ai_state'] === 'generating' && $v['status'] === 'Ready', 'the video is Ready and usable while AI generates [RULE-010]');

// ------------------------------- 2. completion webhook ⇒ fetch job, not inline

assert(as_has_scheduled_action(Ai::HOOK_FETCH) !== false, 'an accepted request also queues its own read-back — webhooks are optional [ASSUME-082]');
as_unschedule_all_actions(Ai::HOOK_FETCH);
$before = count($mock['requests']);
Webhooks::dispatch('video.mediaAI.chapters.ready', array('mediaId' => 'ai-media-1'));
assert(count($mock['requests']) === $before, 'the completion event does not fetch inline [ARCH-05]');
assert(as_has_scheduled_action(Ai::HOOK_FETCH) !== false, 'it queues the fetch job [WF-005]');
as_unschedule_all_actions(Ai::HOOK_FETCH);

// -------------------------------------------- 3. fetch: ready / preparing / failed

$mock['media']['chapters']      = array('status' => 'available', 'data' => array('chapters' => array(array('chapter' => '1', 'startTime' => '00:00:00', 'endTime' => '00:00:30', 'title' => 'Intro'))));
$mock['media']['namedEntities'] = array('status' => 'preparing');
$mock['media']['summary']       = array('status' => 'failed', 'failedReason' => 'audio too quiet in first 30s');

Ai::fetch_job(array('media_id' => 'ai-media-1', 'kind' => 'chapters', 'attempt' => 1));
$ch = array_values(array_filter(Ai::rows($video_id, true), function ($r) { return $r['kind'] === 'chapters'; }))[0];
assert($ch['state'] === 'ready' && $ch['output']['chapters'][0]['title'] === 'Intro', 'the output is stored as returned [RULE-012]');
assert(as_has_scheduled_action('fastpix_search_reindex') !== false, 'and the search index is rebuilt [DATA-013]');

as_unschedule_all_actions(Ai::HOOK_FETCH);
Ai::fetch_job(array('media_id' => 'ai-media-1', 'kind' => 'entities', 'attempt' => 1));
assert(array_column(Ai::rows($video_id), 'state', 'kind')['entities'] === 'requested', 'preparing leaves the item requested…');
assert(as_has_scheduled_action(Ai::HOOK_FETCH) !== false, '…and re-reads later');
as_unschedule_all_actions(Ai::HOOK_FETCH);
// The last attempt times the item out AND rolls the video up — ai_state must not sit at "generating". [X17]
Ai::fetch_job(array('media_id' => 'ai-media-1', 'kind' => 'entities', 'attempt' => Ai::MAX_FETCH_ATTEMPTS));
assert(array_column(Ai::rows($video_id), 'state', 'kind')['entities'] === 'failed'
    && $wpdb->get_var($wpdb->prepare('SELECT ai_state FROM ' . Schema::table('videos') . SQL_WHERE_ID, $video_id)) === 'partial', 'a timed-out fetch rolls up [X17]');
Ai::upsert($video_id, 'entities', 'requested');   // back to the state the next section expects

Ai::fetch_job(array('media_id' => 'ai-media-1', 'kind' => 'summary', 'attempt' => 1));
$states = array_column(Ai::rows($video_id), 'state', 'kind');
assert($states['summary'] === 'failed' && $states['chapters'] === 'ready' && $states['entities'] === 'requested', 'only the summary is failed — itemised, per output [REQ-042]');
$ai_state = $wpdb->get_var($wpdb->prepare('SELECT ai_state FROM ' . Schema::table('videos') . SQL_WHERE_ID, $video_id));
assert($ai_state === 'partial', 'the video rolls up to partial, never blocked');
$badge = Ai::badge($video_id);
assert($badge['ready'] === 1 && $badge['total'] === 3, 'badge counts feed "✦ 1 of 3" [WF-005]');
$logged = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Schema::table('logs') . " WHERE error_code = 'ai_summary_failed'");
assert($logged >= 1, 'logged as ai_summary_failed [ERR-021]');

// ---------------------------------- 4. per-item retry: only the failed item

$mock['requests'] = array();
Ai::request_job(array('media_id' => 'ai-media-1', 'kinds' => array('summary'), 'attempt' => 1));
$patches = array_filter($mock['requests'], function ($r) { return $r['method'] === 'PATCH'; });
assert(count($patches) === 1 && strpos(array_values($patches)[0]['url'], '/summary') !== false, 'a re-run of one kind touches only that endpoint [RULE-011]');
assert(array_column(Ai::rows($video_id), 'state', 'kind')['summary'] === 'requested', 'and only that row goes back to requested');

// A request that the platform refuses is failed and re-queued spaced, per item.
as_unschedule_all_actions(Ai::HOOK_REQUEST);
$mock['fail_paths'] = array('/summary');
Ai::request_job(array('media_id' => 'ai-media-1', 'kinds' => array('summary', 'chapters'), 'attempt' => 1));
$states = array_column(Ai::rows($video_id), 'state', 'kind');
assert($states['summary'] === 'failed' && $states['chapters'] === 'requested', 'a refused request fails only its own item');
assert(as_has_scheduled_action(Ai::HOOK_REQUEST, null, \Fastpix\Fastpix_Jobs::GROUP_AI) !== false, 'and schedules a spaced retry for that item alone [ERR-021 recovery]');
as_unschedule_all_actions(Ai::HOOK_REQUEST);
$mock['fail_paths'] = array();

// ------------------------------------------- 5. REST: /ai kind-aware, read-only

wp_set_current_user(1);
$req = new WP_REST_Request('POST', REST_VIDEOS . $video_id . '/ai');
$req->set_param('kind', 'summary');
$res = rest_get_server()->dispatch($req);
assert(!$res->is_error() && $res->get_data()['queued'] === true && $res->get_data()['kind'] === 'summary', 'POST /videos/{id}/ai re-runs one output [API-P03]');
$req = new WP_REST_Request('POST', REST_VIDEOS . $video_id . '/ai');
$req->set_param('kind', 'edit');
assert(rest_get_server()->dispatch($req)->is_error(), 'unknown kinds are refused; there is no save/revert route [RULE-012]');
as_unschedule_all_actions(Ai::HOOK_REQUEST);

$req = new WP_REST_Request('GET', REST_VIDEOS . $video_id);
$data = rest_get_server()->dispatch($req)->get_data();
assert(isset($data['ai_badge']) && $data['ai_badge']['total'] === 3, 'the row carries the badge counts');
$chapters = array_values(array_filter($data['ai'], function ($r) { return $r['kind'] === 'chapters'; }))[0];
assert($chapters['output']['chapters'][0]['title'] === 'Intro', 'and the outputs, decoded, for the opened row');

// Bulk re-run queues background jobs (already asserted end-to-end in test-videos-rest; the signal lands here).
as_unschedule_all_actions(Ai::HOOK_REQUEST);
Videos::bulk_item_job(array('video_id' => $video_id, 'bulk_action' => 'rerun_ai', 'author_scope' => null));
assert(as_has_scheduled_action(Ai::HOOK_REQUEST) !== false, 'bulk re-run queues the request job [REQ-043]');
as_unschedule_all_actions(Ai::HOOK_REQUEST);

// ------------------------------------------------ 6. tracks: failed alone

Webhooks::dispatch('video.media.track.created', array('mediaId' => 'ai-media-1', 'id' => 'trk-es', 'type' => 'subtitle', 'languageCode' => 'es', 'status' => 'preparing'));
Webhooks::dispatch('video.media.track.failed', array('mediaId' => 'ai-media-1', 'id' => 'trk-es', 'type' => 'subtitle', 'languageCode' => 'es'));
Webhooks::dispatch('video.media.subtitle.generated.ready', array('mediaId' => 'ai-media-1', 'id' => 'trk-en', 'type' => 'subtitle', 'languageCode' => 'en'));
$tracks = $wpdb->get_results($wpdb->prepare('SELECT track_id, state FROM ' . Schema::table('tracks') . ' WHERE video_id = %d ORDER BY track_id', $video_id), ARRAY_A);
$tstates = array_column($tracks, 'state', 'track_id');
assert($tstates['trk-es'] === 'failed' && $tstates['trk-en'] === 'ready', 'a track can fail on its own; the other is ready [REQ-044, ERR-022]');

// The generate op shows the track as generating right away (retry of the failed one).
$mock['requests'] = array();
$req = new WP_REST_Request('PATCH', REST_VIDEOS . $video_id);
$req->set_param('tracks', array(array('action' => 'generate', 'track_id' => 'trk-es', 'language_code' => 'es')));
$res = rest_get_server()->dispatch($req);
assert(!$res->is_error(), 'PATCH tracks generate accepted [ASSUME-029]');
// Retry = drop the failed subtitle track, then generate again FROM THE AUDIO TRACK (real contract, docs 2026-08-18).
assert(count(ai_calls($mock, 'DELETE', '/tracks/trk-es')) === 1, 'retry drops the failed track first');
assert(count(ai_calls($mock, 'POST', EP_GENERATE_SUBTITLES)) === 1, 'then generates from the audio track');
$gen = ai_calls($mock, 'POST', EP_GENERATE_SUBTITLES);
assert($gen[0]['body']['languageCode'] === 'es-ES', 'with the BCP 47 code the endpoint accepts');

// ------------------------------------ transcript from the subtitle track [ASSUME-086]

$cues = Ai::parse_vtt("WEBVTT\nKind: captions\n\n00:00:00.000 --> 00:00:02.439\nPerfect gift.\n\n00:01:02.359 --> 00:01:04.000\nLine <i>two</i>\n");
assert($cues === array(array('start' => 0.0, 'end' => 2.439, 'text' => 'Perfect gift.'), array('start' => 62.359, 'end' => 64.0, 'text' => 'Line two')), 'WebVTT cues parsed with seconds and tags stripped');
$media_obj = array('id' => 'ai-media-1', 'playbackIds' => array(array('id' => 'pb-ai')),
    'tracks' => array(array('id' => 'trk-en', 'type' => 'subtitle', 'status' => 'available', 'languageCode' => 'en', 'languageName' => 'English')),
    'generatedSubtitles' => array(array('status' => 'available', 'url' => 'https://stream.fastpix.com/pb-ai/text/trk-en.vtt')));
as_unschedule_all_actions(Ai::HOOK_TRANSCRIPT);
Ai::sync_subtitles($video_id, $media_obj);
$trk = $wpdb->get_row($wpdb->prepare('SELECT source, state FROM ' . Schema::table('tracks') . SQL_WHERE_VIDEO_TRACK, $video_id, 'trk-en'), ARRAY_A);
assert($trk && $trk['source'] === 'generated' && $trk['state'] === 'ready', 'the platform\'s subtitle track is mirrored locally');
assert(array_column(Ai::rows($video_id), 'state', 'kind')['transcript'] === 'requested' && as_has_scheduled_action(Ai::HOOK_TRANSCRIPT) !== false, 'and a one-time transcript read is queued');
Ai::transcript_job(array('media_id' => 'ai-media-1', 'url' => 'https://stream.fastpix.com/pb-ai/text/trk-en.vtt', 'attempt' => 1));
$tr = array_column(Ai::rows($video_id, true), 'output', 'kind');
assert(array_column(Ai::rows($video_id), 'state', 'kind')['transcript'] === 'ready' && count($tr['transcript']) === 2 && $tr['transcript'][1]['text'] === 'Line two', 'the cues are stored as the transcript output');
as_unschedule_all_actions(Ai::HOOK_TRANSCRIPT);
Ai::sync_subtitles($video_id, $media_obj);
assert(as_has_scheduled_action(Ai::HOOK_TRANSCRIPT) === false, 'a captured transcript is not fetched again on the next sweep');
// The LIST endpoint (new-media sweep) reports the same finished track as "Ready" (verified live 2026-09-20) — it must stay ready. [QA B1]
$media_list = $media_obj;
$media_list['tracks'][0]['status'] = 'Ready';
Ai::sync_subtitles($video_id, $media_list);
assert($wpdb->get_var($wpdb->prepare('SELECT state FROM ' . Schema::table('tracks') . SQL_WHERE_VIDEO_TRACK, $video_id, 'trk-en')) === 'ready', 'a list-endpoint "Ready" track is ready, not generating again [QA B1]');

// QA report #13 — media details stay in sync both ways. Subtitle tracks, platform → WordPress:
$trk_state = function ($id) use ($wpdb, $video_id) { return $wpdb->get_row($wpdb->prepare('SELECT language_code, deleted_at FROM ' . Schema::table('tracks') . SQL_WHERE_VIDEO_TRACK, $video_id, $id), ARRAY_A); };
$old = gmdate('Y-m-d H:i:s', time() - 600);
$wpdb->query($wpdb->prepare('UPDATE ' . Schema::table('tracks') . ' SET created_at = %s WHERE video_id = %d', $old, $video_id));   // past the two-minute in-flight grace
$wpdb->insert(Schema::table('tracks'), array('video_id' => $video_id, 'track_id' => 'trk-dash', 'type' => 'subtitle', 'language_code' => 'es', 'source' => 'uploaded', 'state' => 'ready', 'created_at' => $old, 'updated_at' => $old));   // an established track the dashboard will delete
$wpdb->insert(Schema::table('tracks'), array('video_id' => $video_id, 'track_id' => 'trk-fresh', 'type' => 'subtitle', 'language_code' => 'fr', 'source' => 'uploaded', 'state' => 'processing', 'created_at' => current_time('mysql', true), 'updated_at' => current_time('mysql', true)));
// (a) a raw webhook payload (partial list) removes nothing
Ai::sync_subtitles($video_id, $media_obj, false);
assert($trk_state('trk-dash')['deleted_at'] === null, 'a partial webhook payload never removes a track');
// (b) a full record that no longer lists trk-es: it was deleted on the dashboard
Ai::sync_subtitles($video_id, $media_obj);
assert($trk_state('trk-dash')['deleted_at'] !== null, 'a subtitle track deleted on the FastPix dashboard is removed here too');
assert($trk_state('trk-en')['deleted_at'] === null, 'a listed track stays');
assert($trk_state('trk-fresh')['deleted_at'] === null, 'a track added here moments ago is not removed by a record already in flight');
// (c) listed again ⇒ back
$both = $media_obj; $both['tracks'][] = array('id' => 'trk-dash', 'type' => 'subtitle', 'status' => 'available', 'languageCode' => 'es', 'languageName' => 'Spanish');
Ai::sync_subtitles($video_id, $both);
assert($trk_state('trk-dash')['deleted_at'] === null, 'a track the platform lists again comes back');
// (d) language changed on the dashboard is followed; "en" vs "en-US" is the same language, not a change
$both['tracks'][1]['languageCode'] = 'pt-BR';
$both['tracks'][0]['languageCode'] = 'en-US';
Ai::sync_subtitles($video_id, $both);
assert($trk_state('trk-dash')['language_code'] === 'pt-BR', 'a track language changed on the dashboard is reflected');
assert($trk_state('trk-en')['language_code'] === 'en', 'the same language in BCP 47 form does not churn the stored code');
// (e) the track-deleted webhook removes the track instead of upserting it back as "generating"
Webhooks::dispatch('video.media.track.deleted', array('mediaId' => 'ai-media-1', 'id' => 'trk-dash', 'type' => 'subtitle'));
assert($trk_state('trk-dash')['deleted_at'] !== null, 'video.media.track.deleted removes the track here');

// ---------------------------------------------------------------- teardown

$wpdb->query($wpdb->prepare(SQL_DELETE_FROM . Schema::table('ai') . SQL_WHERE_VIDEO_ID, $video_id));
$wpdb->query($wpdb->prepare(SQL_DELETE_FROM . Schema::table('tracks') . SQL_WHERE_VIDEO_ID, $video_id));
$wpdb->query($wpdb->prepare(SQL_DELETE_FROM . Schema::table('search_index') . SQL_WHERE_VIDEO_ID, $video_id));
$wpdb->query(SQL_DELETE_FROM . Schema::table('uploads') . " WHERE filename = 'ai-check.mp4'");
$wpdb->query(SQL_DELETE_FROM . Schema::table('videos') . SQL_WHERE_AI_MEDIA);
$wpdb->query(SQL_DELETE_FROM . Schema::table('logs') . " WHERE error_code IN ('ai_summary_failed', 'ai_chapters_failed', 'track_failed')");
foreach (array(Ai::HOOK_REQUEST, Ai::HOOK_FETCH, Ai::HOOK_TRANSCRIPT, 'fastpix_search_reindex') as $hook) {
    as_unschedule_all_actions($hook);
}
Cache::flush_group('videos');
wp_set_current_user(0);

echo "ai enrichment: all checks passed\n";
