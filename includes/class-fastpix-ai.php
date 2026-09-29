<?php
/**
 * AI enrichment — WF-005, FR-040/041, REQ-040…045, RULE-010/011/012.
 *
 * Two jobs, both in the ai group:
 *   fastpix_ai_request  — on media ready (auto) or a re-run: PATCH the four
 *                         API-F05 endpoints per the stored batch settings, and
 *                         ask for subtitles in the chosen language. One row per
 *                         (video, kind) in fastpix_ai; a failed request marks
 *                         ONLY that kind failed and retries only it (RULE-011).
 *   fastpix_ai_fetch    — on the matching video.mediaAI.<kind>.ready webhook
 *                         (the completion signal — media ready is not): read
 *                         the output back from GET /on-demand/{mediaId} into
 *                         fastpix_ai as returned (RULE-012), reindex, flush.
 *
 * The four PATCHes answer {mediaId, is<Kind>Enabled:true}; the media record
 * then carries chapters / summary / namedEntities / moderation as {status, data}.
 */

namespace Fastpix;

if (!defined('WPINC')) {
    die;
}

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery
// All SQL here runs against the plugin's own tables: identifiers come from the
// Fastpix_Schema::table() registry (a fixed whitelist, never request input) and
// every value travels through $wpdb->prepare(). Core APIs cannot query these tables.

class Fastpix_Ai {

    const HOOK_REQUEST = 'fastpix_ai_request';
    const HOOK_FETCH   = 'fastpix_ai_fetch';
    const HOOK_TRANSCRIPT = 'fastpix_transcript_fetch';   // subtitle track → transcript segments [ASSUME-086]

    private const SQL_INSERT_INTO = 'INSERT INTO ';

    const MAX_REQUEST_ATTEMPTS = 3;    // per item, spaced [ERR-021 recovery]
    const MAX_FETCH_ATTEMPTS   = 12;   // "preparing" is re-read a minute apart, then left requested
    const RETRY_SPACING        = 60;

    /** local kind => [platform PATCH path, platform read-back keys] */
    const KINDS = array(
        'chapters'   => array('chapters',       array('chapters')),
        'summary'    => array('summary',        array('summary', 'advancedSummary')),
        'entities'   => array('named-entities', array('namedEntities', 'named_entities')),
        'moderation' => array('moderation',     array('moderation')),
    );

    public static function boot() {
        add_action('fastpix_media_ready', array(__CLASS__, 'on_media_ready'));
        add_action(self::HOOK_REQUEST, array(__CLASS__, 'request_job'));
        add_action(self::HOOK_FETCH, array(__CLASS__, 'fetch_job'));
        add_action(self::HOOK_TRANSCRIPT, array(__CLASS__, 'transcript_job'));
    }

    /* ------------------------------------- subtitle tracks → transcript */

    /**
     * Called on every media apply that carries `tracks`. Mirrors the platform's
     * subtitle tracks into the local list (the panel's "N languages") and, once
     * one is available, queues a one-time read of its WebVTT so the Transcript
     * block and transcript search have text. Verified live 2026-09-09: the media
     * lists the file under generatedSubtitles[].url (stream.fastpix.com/{playbackId}/text/{trackId}.vtt).
     */
    public static function sync_subtitles($video_id, $media, $complete = true) {
        $tracks   = isset($media['tracks']) && is_array($media['tracks']) ? $media['tracks'] : array();
        $urls     = self::subtitle_urls($media);
        $playback = (string) Fastpix_Sync::field(isset($media['playbackIds'][0]) ? $media['playbackIds'][0] : array(), array('id'));
        $best_url = '';

        foreach ($tracks as $t) {
            $track_id = self::subtitle_track_id($t);
            if ($track_id === '') {
                continue;
            }
            $state = self::track_state((string) Fastpix_Sync::field($t, array('status')));
            $url   = self::track_url($urls, $track_id);
            self::upsert_track((int) $video_id, $t, $track_id, $url, $state);
            if ($state === 'ready' && $best_url === '') {
                $best_url = $url !== '' ? $url : self::stream_vtt_url($playback, $track_id);
            }
        }

        if ($complete) {
            self::tombstone_missing((int) $video_id, $tracks);
        }

        if ($best_url !== '') {
            self::queue_transcript((int) $video_id, $media, $best_url);
        }
    }

    /**
     * A full media record lists every track it has: a subtitle track that is here but no longer
     * there was deleted on the FastPix dashboard — it goes here too (QA report #13: media details
     * stay in sync both ways). A track added in WordPress moments ago may not be in a record that
     * was already in flight, so the last two minutes are left alone; a record that lists it again
     * later revives it (upsert_track clears deleted_at).
     */
    private static function tombstone_missing($video_id, $tracks) { // NOSONAR php:S100 — WordPress snake_case naming
        global $wpdb;

        $present = array();
        foreach ($tracks as $t) {
            $id = self::subtitle_track_id($t);
            if ($id !== '') { $present[] = $id; }
        }
        $table = Fastpix_Schema::table('tracks');
        $not   = $present ? ' AND track_id NOT IN (' . implode(',', array_fill(0, count($present), '%s')) . ')' : '';
        $now   = current_time('mysql', true);
        $gone  = $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET deleted_at = %s, updated_at = %s
              WHERE video_id = %d AND type = 'subtitle' AND deleted_at IS NULL AND created_at < %s" . $not,
            array_merge(array($now, $now, $video_id, gmdate('Y-m-d H:i:s', time() - 2 * MINUTE_IN_SECONDS)), $present)
        ));
        if ($gone) {
            Fastpix_Cache::flush_group('videos');
            Fastpix_Cache::flush_group('embed');   // the player's captions list rides in the cached markup
        }
    }

    /** The track's id when it is a subtitle track, else ''. */
    private static function subtitle_track_id($t) {
        if (!is_array($t) || (string) Fastpix_Sync::field($t, array('type')) !== 'subtitle') {
            return '';
        }

        return (string) Fastpix_Sync::field($t, array('id', 'trackId'));
    }

    /** generatedSubtitles[].url — the platform's WebVTT files, one per generated track. */
    private static function subtitle_urls($media) {
        $urls = array();
        foreach ((array) Fastpix_Sync::field($media, array('generatedSubtitles', 'generated_subtitles')) as $g) {
            if (is_array($g) && !empty($g['url'])) {
                $urls[] = (string) $g['url'];
            }
        }

        return $urls;
    }

    private static function track_state($status) {
        $status = strtolower($status);
        // Verified live 2026-09-20: the detail record says "available", the LIST record
        // (the new-media sweep) says "Ready" for the same track — both are done. [QA B1]
        if ($status === 'available' || $status === 'ready') {
            return 'ready';
        }

        return in_array($status, array('failed', 'errored'), true) ? 'failed' : 'generating';
    }

    /** The generated file whose URL carries this track id, or '' for an uploaded track. */
    private static function track_url($urls, $track_id) {
        $url = '';
        foreach ($urls as $u) {
            if (strpos($u, $track_id) !== false) {
                $url = $u;
            }
        }

        return $url;
    }

    private static function stream_vtt_url($playback, $track_id) {
        return $playback !== '' ? 'https://stream.fastpix.com/' . rawurlencode($playback) . '/text/' . rawurlencode($track_id) . '.vtt' : '';
    }

    private static function upsert_track($video_id, $t, $track_id, $url, $state) {
        global $wpdb;

        $now = current_time('mysql', true);
        $wpdb->query($wpdb->prepare(
            self::SQL_INSERT_INTO . Fastpix_Schema::table('tracks') . '
                 (video_id, track_id, type, language_code, source, state, created_at, updated_at)
             VALUES (%d, %s, %s, %s, %s, %s, %s, %s)
             ON DUPLICATE KEY UPDATE state = VALUES(state), language_code = IF(VALUES(language_code) <> \'\' AND SUBSTRING_INDEX(LOWER(VALUES(language_code)), \'-\', 1) <> SUBSTRING_INDEX(LOWER(language_code), \'-\', 1), VALUES(language_code), language_code),
                                     source = IF(source = \'\', VALUES(source), source), deleted_at = NULL, updated_at = VALUES(updated_at)',
            $video_id, $track_id, 'subtitle', (string) Fastpix_Sync::field($t, array('languageCode', 'language_code')),
            $url !== '' ? 'generated' : 'uploaded', $state, $now, $now
        ));
    }

    /** One-time transcript read of the first ready track; the ai row is the guard against duplicate jobs. */
    private static function queue_transcript($video_id, $media, $url) {
        global $wpdb;

        $have = $wpdb->get_var($wpdb->prepare(
            'SELECT state FROM ' . Fastpix_Schema::table('ai') . " WHERE video_id = %d AND kind = 'transcript'", $video_id
        ));
        if ($have === 'ready' || $have === 'requested') {
            return;   // captured, or on its way
        }
        self::upsert($video_id, 'transcript', 'requested', '');
        Fastpix_Jobs::enqueue(self::HOOK_TRANSCRIPT, array('media_id' => (string) Fastpix_Sync::field($media, array('id', 'mediaId', 'media_id')), 'url' => $url, 'attempt' => 1), Fastpix_Jobs::GROUP_AI);
    }

    /** Fetch the WebVTT, keep the cues, store them as the 'transcript' output (search buckets them into 30 s segments). */
    public static function transcript_job($args) {
        $media_id = isset($args['media_id']) ? (string) $args['media_id'] : '';
        $url      = isset($args['url']) ? (string) $args['url'] : '';
        $attempt  = isset($args['attempt']) ? max(1, (int) $args['attempt']) : 1;
        $video    = self::video_by_media($media_id);
        if (!$video || $url === '' || strpos($url, 'https://stream.fastpix.') !== 0) {
            return;
        }

        $res  = wp_remote_get($url, array('timeout' => 20));
        $code = is_wp_error($res) ? 0 : (int) wp_remote_retrieve_response_code($res);
        $cues = $code === 200 ? self::parse_vtt((string) wp_remote_retrieve_body($res)) : array();

        if (!$cues) {
            if ($attempt < self::MAX_REQUEST_ATTEMPTS) {
                Fastpix_Jobs::schedule_at(time() + self::RETRY_SPACING * $attempt, self::HOOK_TRANSCRIPT,
                    array('media_id' => $media_id, 'url' => $url, 'attempt' => $attempt + 1), Fastpix_Jobs::GROUP_AI);
                return;
            }
            self::upsert($video['id'], 'transcript', 'failed', $code ? 'http_' . $code : 'unreachable');
            return;
        }

        self::upsert($video['id'], 'transcript', 'ready', '', $cues);
        Fastpix_Jobs::enqueue('fastpix_search_reindex', array('video_id' => (int) $video['id']), Fastpix_Jobs::GROUP_MAINTENANCE);
        Fastpix_Cache::flush_group('embed');   // the Transcript block is cached inside the public markup [QA X18]
        Fastpix_Cache::flush_group('videos');
    }

    /** WebVTT → [{start, end, text}], tags stripped, empty cues dropped. */
    public static function parse_vtt($text) {
        $cues = array();
        $cur  = null;
        foreach (preg_split('/\r?\n/', (string) $text) as $line) {
            if (preg_match('/^(\d{1,2}:)?(\d{2}):(\d{2})[.,](\d{3})\s+-->\s+(\d{1,2}:)?(\d{2}):(\d{2})[.,](\d{3})/', $line, $m)) {
                self::flush_cue($cues, $cur);
                $cur = array('start' => self::vtt_seconds($m, 1), 'end' => self::vtt_seconds($m, 5), 'text' => '');
            } elseif ($cur !== null && trim($line) === '') {
                self::flush_cue($cues, $cur);
                $cur = null;
            } elseif ($cur !== null) {
                // Sound cues ([music], [silence], [applause]…) are captions, not transcript.
                $clean = trim(preg_replace('/\[[^\]]*\]/', '', wp_strip_all_tags($line)));
                $cur['text'] = trim($cur['text'] . ' ' . $clean);
            }
        }
        self::flush_cue($cues, $cur);

        return $cues;
    }

    /** Four timestamp captures starting at $i (hours, minutes, seconds, millis) → seconds. */
    private static function vtt_seconds($m, $i) {
        return (float) ((int) $m[$i] * 3600 + (int) $m[$i + 1] * 60 + (int) $m[$i + 2] + (int) $m[$i + 3] / 1000);
    }

    private static function flush_cue(&$cues, $cur) {
        if ($cur && $cur['text'] !== '') {
            $cues[] = $cur;
        }
    }

    /* ------------------------------------------------------------ triggers */

    /** On ready only the batch's subtitle language is requested. Chapters, summary
     *  and named entities are never generated on their own — the owner clicks
     *  Generate on the video row. */
    public static function on_media_ready($media_id) {
        self::request((string) $media_id, array('subtitles'));
    }

    /**
     * Queue a request for all kinds (null) or the named ones (per-item re-run).
     * @return int action id (0 when jobs are unavailable)
     */
    public static function request($media_id, $kinds = null, $attempt = 1) {
        $args = array('media_id' => (string) $media_id, 'attempt' => (int) $attempt);
        if (is_array($kinds) && $kinds) {
            $args['kinds'] = array_values($kinds);
        }

        return Fastpix_Jobs::enqueue(self::HOOK_REQUEST, $args, Fastpix_Jobs::GROUP_AI);
    }

    /* --------------------------------------------------------- request job */

    public static function request_job($args) {
        global $wpdb;

        $media_id = isset($args['media_id']) ? (string) $args['media_id'] : '';
        $attempt  = isset($args['attempt']) ? max(1, (int) $args['attempt']) : 1;
        $video    = self::video_by_media($media_id);
        if (!$video) {
            return;
        }

        $settings = self::settings_for($video);
        $wanted   = isset($args['kinds']) && is_array($args['kinds']) ? $args['kinds'] : self::kinds_from_settings($settings);
        $client   = new Fastpix_Api_Client();

        foreach ($wanted as $kind) {
            if ($kind === 'subtitles') {
                self::request_subtitles($client, $video, $settings);
            } elseif (isset(self::KINDS[$kind])) {
                self::request_one($client, $video, $kind, $settings, $attempt);
            }
        }

        self::rollup($video['id']);
    }

    /** One PATCH for one output kind, with the already-exists and retry branches. */
    private static function request_one($client, $video, $kind, $settings, $attempt) {
        $media_id = $video['media_id'];
        $result   = $client->request('PATCH', '/on-demand/' . rawurlencode($media_id) . '/' . self::KINDS[$kind][0], array(
            'context'            => 'background',
            'idempotency_row_id' => 'ai:' . $video['id'] . ':' . $kind . ':' . $attempt,
            'body'               => self::request_body($kind, $settings),
        ));

        if (is_wp_error($result) && preg_match('/already exist/i', $result->get_error_message())) {
            // The platform already has this output (made earlier, or from the
            // dashboard) — nothing to request; read it back so it shows Completed.
            self::upsert($video['id'], $kind, 'requested', '');
            Fastpix_Jobs::enqueue(self::HOOK_FETCH, array('media_id' => $media_id, 'kind' => $kind, 'attempt' => 1), Fastpix_Jobs::GROUP_AI);
            return;
        }

        if (is_wp_error($result)) {
            // Only this item fails; only this item is retried, spaced. [RULE-011, ERR-021]
            self::upsert($video['id'], $kind, 'failed', $result->get_error_code());
            do_action('fastpix_log', 'ai_' . $kind . '_failed', array(
                'severity' => 'warning', 'scope' => 'ai',
                'message'  => sprintf('%s request failed for %s: %s', $kind, $media_id, $result->get_error_message()),
            ));
            if ($attempt < self::MAX_REQUEST_ATTEMPTS) {
                Fastpix_Jobs::schedule_at(time() + self::RETRY_SPACING * $attempt, self::HOOK_REQUEST,
                    array('media_id' => $media_id, 'kinds' => array($kind), 'attempt' => $attempt + 1), Fastpix_Jobs::GROUP_AI);
            }
            return;
        }

        self::upsert($video['id'], $kind, 'requested', '');
        // Read the output back a minute later (then up to 12× a minute apart while
        // "preparing"). Webhooks bring it sooner when configured; without them
        // (this site) nothing else would — the row sat at "requested" for good. [ASSUME-082]
        Fastpix_Jobs::schedule_at(time() + self::RETRY_SPACING, self::HOOK_FETCH,
            array('media_id' => $media_id, 'kind' => $kind, 'attempt' => 1), Fastpix_Jobs::GROUP_AI);
    }

    /** The four outputs, per the batch's stored settings [REQ-040]. */
    public static function kinds_from_settings($settings) {
        $kinds = array('entities');   // no switch for these in the batch settings
        if (!empty($settings['chapters'])) {
            $kinds[] = 'chapters';
        }
        if (isset($settings['summary']) && $settings['summary'] !== 'off' && $settings['summary'] !== false) {
            $kinds[] = 'summary';
        }
        if (isset($settings['moderation']) && $settings['moderation'] !== 'off' && $settings['moderation'] !== false) {
            $kinds[] = 'moderation';
        }
        if (isset($settings['subtitles']) && $settings['subtitles'] !== 'off' && $settings['subtitles'] !== '') {
            $kinds[] = 'subtitles';
        }

        return $kinds;
    }

    private static function request_body($kind, $settings) {
        switch ($kind) {
            case 'chapters':
                $body = array('chapters' => true);
                break;
            case 'summary':
                $lengths = array('short' => 50, 'medium' => 100, 'long' => 200);
                $len     = isset($settings['summary']) && isset($lengths[$settings['summary']]) ? $lengths[$settings['summary']] : 100;
                $body    = array('generate' => true, 'summaryLength' => $len);
                break;
            case 'entities':
                $body = array('namedEntities' => true);
                break;
            case 'moderation':
                $body = array('moderation' => array('type' => 'video'));
                break;
            default:
                $body = array();
        }

        return $body;
    }

    /**
     * Subtitles for the chosen language: generate into the media's audio track
     * (API-F05 generate-subtitles). Skipped when a live subtitle track for that
     * language already exists locally — a re-run must not duplicate a track.
     */
    private static function request_subtitles($client, $video, $settings) {
        global $wpdb;

        $code = isset($settings['subtitles']) ? (string) $settings['subtitles'] : '';
        if ($code === '' || $code === 'off') {
            return;   // subtitles switched off for this batch
        }
        $have = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . Fastpix_Schema::table('tracks') . "
             WHERE video_id = %d AND type = 'subtitle' AND language_code = %s AND deleted_at IS NULL AND state <> 'failed'",
            (int) $video['id'], $code
        ));

        $media = rawurlencode($video['media_id']);
        $audio = $have ? '' : self::audio_track_id($client, $media);
        if ($audio === '') {
            // Track already live, the record was unreachable (the media
            // poll/sweep will bring it), or no audio track (yet).
            return;
        }

        $names  = Fastpix_Utils::get_language_map();
        $result = $client->request('POST', "/on-demand/{$media}/tracks/" . rawurlencode($audio) . '/generate-subtitles', array(
            'context'            => 'background',
            'idempotency_row_id' => 'ai:' . $video['id'] . ':subtitles:' . $code,
            'body'               => array(
                'languageCode' => $code,
                'languageName' => isset($names[$code]) ? $names[$code] : strtoupper($code),
            ),
        ));
        if (is_wp_error($result)) {
            do_action('fastpix_log', 'ai_subtitles_failed', array(
                'severity' => 'warning', 'scope' => 'ai',
                'message'  => sprintf('subtitle generation (%s) failed for %s: %s', $code, $video['media_id'], $result->get_error_message()),
            ));
            return;
        }

        // The platform may hand back the new track id; show it as generating now
        // rather than only when the track.created webhook lands.
        $body     = isset($result['body']['data']) ? $result['body']['data'] : (array) $result['body'];
        $track_id = (string) Fastpix_Sync::field((array) $body, array('id', 'trackId', 'track_id'));
        if ($track_id !== '') {
            $now = current_time('mysql', true);
            $wpdb->query($wpdb->prepare(
                self::SQL_INSERT_INTO . Fastpix_Schema::table('tracks') . '
                     (video_id, track_id, type, language_code, source, state, created_at, updated_at)
                 VALUES (%d, %s, %s, %s, %s, %s, %s, %s)
                 ON DUPLICATE KEY UPDATE state = VALUES(state), source = VALUES(source), deleted_at = NULL, updated_at = VALUES(updated_at)',
                (int) $video['id'], $track_id, 'subtitle', $code, 'generated', 'generating', $now, $now
            ));
        }
    }

    /** The media's audio track id, or '' when the record or track is unavailable. */
    private static function audio_track_id($client, $media) {
        $record = $client->request('GET', "/on-demand/{$media}", array('context' => 'background'));
        if (is_wp_error($record)) {
            return '';
        }
        $data = isset($record['body']['data']) ? $record['body']['data'] : (array) $record['body'];
        foreach ((array) Fastpix_Sync::field($data, array('tracks')) as $track) {
            if (is_array($track) && (string) Fastpix_Sync::field($track, array('type')) === 'audio') {
                return (string) Fastpix_Sync::field($track, array('id', 'trackId'));
            }
        }

        return '';   // no audio track (yet) — nothing to transcribe
    }

    /* ----------------------------------------------- completion + fetch job */

    /**
     * Called by the webhook handler table for video.mediaAI.* / video.media.ai.*
     * events. Marks the kind and queues the read-back; never fetches inline
     * (the receiver's 200-under-200 ms budget, ARCH-05).
     */
    public static function on_completion_event($type, $data) {
        $media_id = (string) Fastpix_Sync::field($data, array('mediaId', 'media_id', 'id'));
        $video    = self::video_by_media($media_id);
        $kind     = self::kind_from_event($type);
        if (!$video || $kind === '') {
            return;
        }

        if (stripos($type, '.failed') !== false || stripos($type, '.errored') !== false) {
            $reason = (string) Fastpix_Sync::field($data, array('failedReason', 'reason', 'error', 'message'));
            self::upsert($video['id'], $kind, 'failed', $reason !== '' ? substr($reason, 0, 64) : 'platform_failed');
            do_action('fastpix_log', 'ai_' . $kind . '_failed', array('severity' => 'warning', 'scope' => 'ai',
                'message' => sprintf('%s failed on the platform for %s: %s', $kind, $media_id, $reason)));
            self::rollup($video['id']);
            return;
        }

        Fastpix_Jobs::enqueue(self::HOOK_FETCH, array('media_id' => $media_id, 'kind' => $kind, 'attempt' => 1), Fastpix_Jobs::GROUP_AI);
    }

    /** Event type → local kind ('' when it is not one of ours). */
    public static function kind_from_event($type) {
        $type = strtolower((string) $type);
        foreach (array('chapters' => 'chapters', 'namedentities' => 'entities', 'named_entities' => 'entities',
                       'advanced_summary' => 'summary', 'summary' => 'summary', 'moderation' => 'moderation',
                       'attributed_transcript' => 'transcript', 'transcript' => 'transcript') as $needle => $kind) {
            if (strpos($type, $needle) !== false) {
                return $kind;
            }
        }

        return '';
    }

    public static function fetch_job($args) {
        $media_id = isset($args['media_id']) ? (string) $args['media_id'] : '';
        $kind     = isset($args['kind']) ? (string) $args['kind'] : '';
        $attempt  = isset($args['attempt']) ? max(1, (int) $args['attempt']) : 1;
        $video    = self::video_by_media($media_id);
        if (!$video || $kind === '') {
            return;
        }

        $client = new Fastpix_Api_Client();
        $result = $client->request('GET', '/on-demand/' . rawurlencode($media_id), array('context' => 'background'));
        if (is_wp_error($result)) {
            self::retry_fetch($media_id, $kind, $attempt);
            return;
        }

        $data = isset($result['body']['data']) ? $result['body']['data'] : (array) $result['body'];
        $keys = isset(self::KINDS[$kind]) ? self::KINDS[$kind][1] : array('attributedTranscript', 'attributed_transcript', 'transcript');
        $out  = Fastpix_Sync::field((array) $data, $keys);

        if (!self::store_fetched($video, $kind, $media_id, $out)) {
            self::retry_fetch($media_id, $kind, $attempt);   // preparing / not there yet
            return;
        }

        self::rollup($video['id']);
    }

    /** The output's status; shapes without one are treated as available. */
    private static function fetch_status($out) {
        if (is_array($out) && array_key_exists('status', $out)) {
            return strtolower((string) $out['status']);
        }

        return ($out === null) ? 'missing' : 'available';
    }

    /**
     * Store a fetched output row. Live shape: {status: available|preparing|
     * failed, data: …}. Older/other shapes may hand the output straight —
     * anything without a status is treated as available. Returns false while
     * the platform is still preparing (the caller schedules the retry).
     */
    private static function store_fetched($video, $kind, $media_id, $out) {
        $status = self::fetch_status($out);

        if ($status === 'available' || $status === 'ready' || $status === 'completed') {
            $payload = is_array($out) && array_key_exists('data', $out) ? $out['data'] : $out;
            self::upsert($video['id'], $kind, 'ready', '', $payload);
            Fastpix_Jobs::enqueue('fastpix_search_reindex', array('video_id' => (int) $video['id']), Fastpix_Jobs::GROUP_MAINTENANCE);

            return true;
        }

        if ($status === 'failed' || $status === 'errored' || $status === 'error') {
            $reason = is_array($out) ? (string) Fastpix_Sync::field($out, array('failedReason', 'reason', 'error', 'message')) : '';
            self::upsert($video['id'], $kind, 'failed', $reason !== '' ? substr($reason, 0, 64) : 'platform_failed');
            do_action('fastpix_log', 'ai_' . $kind . '_failed', array('severity' => 'warning', 'scope' => 'ai',
                'message' => sprintf('%s did not finish for %s: %s', $kind, $media_id, $reason)));

            return true;
        }

        return false;
    }

    private static function retry_fetch($media_id, $kind, $attempt) {
        if ($attempt >= self::MAX_FETCH_ATTEMPTS) {
            // Twelve reads a minute apart and still not there: say so, instead of "Generating"
            // forever — the per-item Generate re-requests it. [QA X17]
            $video = self::video_by_media($media_id);
            if ($video) {
                self::upsert((int) $video['id'], $kind, 'failed', 'timed_out');
                self::rollup((int) $video['id']);   // videos.ai_state must leave "generating" too (QA X17)
            }
            return;
        }
        Fastpix_Jobs::schedule_at(time() + self::RETRY_SPACING, self::HOOK_FETCH,
            array('media_id' => $media_id, 'kind' => $kind, 'attempt' => $attempt + 1), Fastpix_Jobs::GROUP_AI);
    }

    /* ------------------------------------------------------------ storage */

    /** One row per (video, kind); the platform's output stored as returned [RULE-012]. */
    public static function upsert($video_id, $kind, $state, $error_code = '', $payload = null) {
        global $wpdb;

        $now = current_time('mysql', true);
        if ($payload === null) {
            $wpdb->query($wpdb->prepare(
                self::SQL_INSERT_INTO . Fastpix_Schema::table('ai') . ' (video_id, kind, state, error_code, created_at, updated_at)
                 VALUES (%d, %s, %s, %s, %s, %s)
                 ON DUPLICATE KEY UPDATE state = VALUES(state), error_code = VALUES(error_code), updated_at = VALUES(updated_at)',
                (int) $video_id, $kind, $state, (string) $error_code, $now, $now
            ));
        } else {
            $wpdb->query($wpdb->prepare(
                self::SQL_INSERT_INTO . Fastpix_Schema::table('ai') . ' (video_id, kind, generated_json, state, error_code, created_at, updated_at)
                 VALUES (%d, %s, %s, %s, %s, %s, %s)
                 ON DUPLICATE KEY UPDATE generated_json = VALUES(generated_json), state = VALUES(state),
                                         error_code = VALUES(error_code), updated_at = VALUES(updated_at)',
                (int) $video_id, $kind, wp_json_encode($payload), $state, (string) $error_code, $now, $now
            ));
        }
    }

    /**
     * videos.ai_state from the per-kind rows: '' none · generating · partial
     * (something failed, the rest fine — the video is fully usable, RULE-010) ·
     * ready. Flushes the videos cache so rows and badges pick it up.
     */
    public static function rollup($video_id) {
        global $wpdb;

        $rows  = self::rows($video_id);
        $state = '';
        if ($rows) {
            $states = array_column($rows, 'state');
            if (in_array('failed', $states, true)) {
                $state = 'partial';
            } elseif (count(array_unique($states)) === 1 && $states[0] === 'ready') {
                $state = 'ready';
            } else {
                $state = 'generating';
            }
        }

        $wpdb->update(Fastpix_Schema::table('videos'), array('ai_state' => $state, 'updated_at' => current_time('mysql', true)), array('id' => (int) $video_id));
        Fastpix_Cache::flush_group('videos');

        return $state;
    }

    /** Per-kind rows with the output decoded — what the row and the renderer read. */
    public static function rows($video_id, $with_output = false) {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT kind, state, error_code' . ($with_output ? ', generated_json' : '') . ', updated_at
             FROM ' . Fastpix_Schema::table('ai') . ' WHERE video_id = %d ORDER BY kind',
            (int) $video_id
        ), ARRAY_A);

        if ($with_output) {
            foreach ($rows as &$row) {
                $row['output'] = $row['generated_json'] !== null ? json_decode($row['generated_json'], true) : null;
                unset($row['generated_json']);
            }
        }

        return $rows ? $rows : array();
    }

    /** "✦ 3 of 4" / "Generating" — counts for the badge [WF-005 step 4].
     *  Only the offered outputs (self::KINDS) count; an attributed-transcript
     *  row is stored but is not a badge output, so it must not inflate "of N". */
    public static function badge($video_id) {
        $rows  = array_filter(self::rows($video_id), function ($r) { return isset(self::KINDS[$r['kind']]); });
        $ready = count(array_filter($rows, function ($r) { return $r['state'] === 'ready'; }));

        return array('ready' => $ready, 'total' => count($rows));
    }

    /* ------------------------------------------------------------ settings */

    /**
     * The batch settings the video was added with: the upload session's
     * snapshot for uploads; the ingest snapshot parked for URL videos; REQ-017
     * defaults for anything else (dashboard, migrated).
     */
    public static function settings_for($video) {
        return Fastpix_Uploads_Settings::settings_snapshot(self::batch_settings_raw($video));
    }

    /** The stored batch snapshot (upload row or parked ingest/migration), or
     *  null when this media was never added through a batch — dashboard and
     *  sweep-discovered videos have none, and callers that must not invent
     *  defaults (domain lock) check for exactly that. */
    public static function batch_settings_raw($video) {
        global $wpdb;

        $json = $wpdb->get_var($wpdb->prepare(
            'SELECT settings_json FROM ' . Fastpix_Schema::table('uploads') . ' WHERE video_id = %d ORDER BY id DESC LIMIT 1',
            (int) $video['id']
        ));
        $raw = $json ? json_decode($json, true) : null;
        if (!is_array($raw)) {
            $raw = get_transient('fastpix_ai_settings_' . md5((string) $video['media_id']));   // parked by URL ingest
        }

        return is_array($raw) ? $raw : null;
    }

    /** URL ingest has no upload row: park the batch snapshot until the media is ready. */
    public static function park_settings($media_id, $settings) {
        // ponytail: a 7-day transient keyed on media id — a column on videos if this ever needs to outlive that.
        set_transient('fastpix_ai_settings_' . md5((string) $media_id), $settings, 7 * DAY_IN_SECONDS);
    }

    private static function video_by_media($media_id) {
        global $wpdb;

        if ($media_id === '') {
            return null;
        }
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . Fastpix_Schema::table('videos') . ' WHERE media_id = %s AND deleted_at IS NULL',
            $media_id
        ), ARRAY_A);

        return $row ? $row : null;
    }
}
