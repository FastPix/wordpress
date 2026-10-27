<?php
/**
 * Renderer — player element + degraded states, split from Fastpix_Render to
 * keep each class within the 20-method budget. Same behaviour, same markup;
 * the spec IDs stay with the code they govern (WF-007, RULE-010/015/016/045/
 * 046, SEC-005/007, REQ-053, ERR-041/061/062).
 */

namespace Fastpix;

if (!defined('WPINC')) {
    die;
}

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery
// All SQL here runs against the plugin's own tables: identifiers come from the
// Fastpix_Schema::table() registry (a fixed whitelist, never request input) and
// every value travels through $wpdb->prepare(). Core APIs cannot query these tables.

class Fastpix_Render_Player {

    /** Thumbnail frame path fragment for poster URLs. */
    const THUMB_TIME = '/thumbnail.png?time=';

    /** The player element. $tokens null ⇒ public. */
    public static function player($video, $playback, $settings, $policy, $tokens) {
        $attrs = array(
            'playback-id'          => $playback,
            'accent-color'         => $settings['accentColour'],
            'metadata-video-id'    => $video['media_id'],                        // RULE-046 (workspace key
            'metadata-video-title' => (string) $video['title'],                  //   attached at render, never stored)
            'preload'              => $settings['lazyLoad'] ? 'metadata' : 'auto',
        );
        $progress        = self::player_progress_attrs($video, $settings);
        $attrs          += $progress['attrs'];
        $is_lesson_embed = $progress['is_lesson'];
        $ws = (string) get_option(Fastpix_Connection::OPT_WORKSPACE_ID, '');
        if ($ws !== '') {
            $attrs['metadata-workspace-key'] = $ws;
        }
        if ((float) $settings['startTime'] > 0) {
            $attrs['start-time'] = (string) (float) $settings['startTime'];
        }
        if ($settings['aspectRatio'] !== '') {
            $attrs['aspect-ratio'] = $settings['aspectRatio'];
        } elseif (!empty($video['aspect_ratio'])) {
            $attrs['aspect-ratio'] = $video['aspect_ratio'];
        }
        // showChapters: the player draws its own chapter marks from the platform;
        // the toggle governs the chapter Clips in structured data and the
        // transcript block below (no player attribute switches marks off).
        // Verified in the vendored player 1.0.21 (QA F9, 2026-09-20): it shows the first
        // subtitle track by itself unless `disable-hidden-captions` is present — that is
        // the only working switch (`default-subtitle-track` wants a track LABEL, never "auto").
        $attrs += self::player_url_attrs($playback, $settings, $policy, $tokens);

        $flags = '';
        foreach (array('autoplay' => 'auto-play', 'muted' => 'muted', 'loop' => 'loop') as $flag => $attr) {   // the player reads `auto-play`
            if ($settings[$flag]) {
                $flags .= ' ' . $attr;
            }
        }
        $cf         = self::control_flags($settings);
        $flags     .= $cf['flags'];
        $style_attr = $cf['style'] !== '' ? ' style="' . esc_attr($cf['style']) . '"' : '';

        // Skip-proof lessons: hide the LMS's own complete button until watched.
        // Printed inline (before the LMS prints its button) so there is no flash.
        $html = $is_lesson_embed ? Fastpix_Lms::antiskip_style_tag() : '';
        $html .= '<figure class="wp-block-fastpix-video fastpix-embed" data-fastpix-policy="' . esc_attr($policy) . '">';
        $html .= '<fastpix-player' . self::attr_string($attrs) . $flags . $style_attr . '></fastpix-player>';
        $html .= self::player_children($video, $settings, $policy);
        $html .= '</figure>';

        return $html;
    }

    /**
     * Watch progress (WF-013): player.js needs the local row id and the
     * duration to post beats and resume. Only with a known duration —
     * coverage is meaningless without one.
     *
     * @return array {attrs: array, is_lesson: bool}
     */
    private static function player_progress_attrs($video, $settings) {
        $attrs     = array();
        $is_lesson = false;
        if (!empty($video['id']) && !empty($video['duration_seconds'])) {
            $attrs['data-fp-video']    = (string) (int) $video['id'];
            $attrs['data-fp-duration'] = (string) (float) $video['duration_seconds'];
            if (!$settings['resume']) {
                $attrs['data-fp-resume'] = '0';
            }
            // Lesson completion: complete-at on lesson posts; viewer-key only
            // when the embed opted in AND the viewer is logged in — a hashed
            // identity, never the user id itself.
            $post_id = Fastpix_Lms::rendering_lesson_id();   // NOT get_the_ID(): LMS lesson content renders under the course post
            if ($post_id) {
                $attrs['complete-at']  = (string) (int) $settings['completeAt'];
                $attrs['data-fp-post'] = (string) $post_id;
                $is_lesson             = true;   // gate the LMS's own complete button
                if ($settings['trackViewer'] && is_user_logged_in()) {
                    $attrs['viewer-key'] = Fastpix_Lms::viewer_hash(get_current_user_id());
                }
            }
        }

        return array('attrs' => $attrs, 'is_lesson' => $is_lesson);
    }

    /** Poster + token attributes. Poster: an image URL wins; else the frame time (default 1 s). [RULE-045] */
    private static function player_url_attrs($playback, $settings, $policy, $tokens) {
        $attrs = array();
        $img   = Fastpix_Attachments::image_base() . '/' . rawurlencode($playback);
        if ($settings['poster'] !== '') {
            $attrs['poster'] = $settings['poster'];
        } elseif ($tokens) {
            $attrs['poster'] = $img . self::THUMB_TIME . (int) $settings['thumbnailTime'] . '&token=' . $tokens['media'];
        } else {
            $attrs['poster'] = $img . self::THUMB_TIME . (int) $settings['thumbnailTime'];
        }

        if ($tokens) {
            // A DRM stream is served only against the drm-audience token (media-aud
            // → 401, drm-aud → 200), so for DRM media the same token is the playback
            // `token` AND the `drm-token` (docs: "reuse the same token for both").
            // Images accept either.
            $stream_token   = ($policy === 'drm' && isset($tokens['drm'])) ? $tokens['drm'] : $tokens['media'];
            $attrs['token'] = $stream_token;
            // No spritesheet-src: the player treats it as a BASE and builds
            // {base}/{playbackId}/spritesheet.json?token={token attr} itself —
            // a full URL here produced a mangled 401 request.
            if ($policy === 'drm' && isset($tokens['drm'])) {
                $attrs['drm-token'] = $tokens['drm'];
            }
            // Edge-cache fallback: a cached copy of this HTML carries an expiring
            // token; player.js re-fetches from player-config when it is stale.
            $attrs['data-fp-config'] = rest_url(Fastpix_Rest::NS . '/player-config/' . rawurlencode($playback));
            $attrs['data-fp-exp']    = (string) (int) $tokens['exp'];
        }

        return $attrs;
    }

    /** The three control switches, shared by recorded and live embeds. */
    public static function control_flags($settings) {
        $flags = '';
        $style = '';
        if (!$settings['controls']) {
            // The vendored player reads `hide-controls` into a property it never
            // uses — the control bar is actually governed by the `--controls`
            // custom property (getComputedStyle on the host). Set it to none so
            // the bar is genuinely hidden; keep hide-controls for a future build.
            $flags .= ' hide-controls';
            $style .= '--controls:none;';
        }
        if (!$settings['clickToPlay']) {
            $flags .= ' disable-video-click';   // the player reads it but never acts on it (1.0.21) — player.js intercepts the click (QA F8)
        }
        if (!$settings['captionsDefault']) {
            $flags .= ' disable-hidden-captions';   // the one captions switch the player honours (QA F9)
        }
        if (!$settings['keyboard']) {
            $flags .= ' disable-keyboard-controls';   // this one the player honours
        }

        return array('flags' => $flags, 'style' => $style);
    }

    /** The chapter-marks JSON and the transcript block inside the <figure>. */
    private static function player_children($video, $settings, $policy) {
        $html = '';
        // Chapter marks: this player build does not auto-draw them from the
        // platform — they must be handed to its addChapters() API. Embed the
        // marks (start/end in seconds, value = title) for player.js to apply.
        if ($settings['showChapters']) {
            $marks = array();
            foreach (self::chapters($video['id']) as $c) {
                $marks[] = array('startTime' => $c['start'], 'endTime' => $c['end'], 'value' => $c['title']);
            }
            if ($marks) {
                $html .= '<script type="application/json" class="fastpix-chapters">' . wp_json_encode($marks) . '</script>';
            }
        }
        if ($settings['showTranscript'] && $policy === 'public') {   // transcript never for protected video [RULE-015]
            $html .= self::transcript($video);
        }

        return $html;
    }

    /* ---------------------------------------------------- degraded states */

    public static function processing($video, $policy, $settings, $fallback) {
        $playback = Fastpix_Render::playback_id($video);
        if ($fallback !== '') {
            return $fallback;   // the saved poster + link the block wrote
        }
        if ($policy === 'public' && $playback !== '') {
            return self::poster_message($video, $playback, __('This video is still being processed. It will play here as soon as it is ready.', 'fastpix-io'), $settings, true);
        }

        return self::message(__('This video is still being processed. It will play here as soon as it is ready.', 'fastpix-io'), '');
    }

    /** Poster (public only — a private poster is signed too) + a stated message. */
    public static function poster_message($video, $playback, $message, $settings, $with_poster) {
        $html = '<figure class="wp-block-fastpix-video fastpix-embed fastpix-embed--message">';
        if ($with_poster && $playback !== '') {
            $html .= '<img class="fastpix-embed__poster" src="' . esc_url(Fastpix_Attachments::image_base() . '/' . rawurlencode($playback) . self::THUMB_TIME . (int) $settings['thumbnailTime']) . '" alt="' . esc_attr((string) $video['title']) . '" loading="lazy">';
        }
        $html .= '<figcaption class="fastpix-embed__note">' . esc_html($message) . '</figcaption></figure>';

        return $html;
    }

    public static function message($message, $fallback) {
        return $fallback !== '' ? $fallback : '<figure class="wp-block-fastpix-video fastpix-embed fastpix-embed--message"><figcaption class="fastpix-embed__note">' . esc_html($message) . '</figcaption></figure>';
    }

    /**
     * DRM prerequisites, stated (never a broken protected stream — SEC-007):
     * HTTPS page; a configuration that still resolves. An unresolvable one is
     * recorded for the health check (ERR-061).
     */
    public static function drm_blocker($video) {
        $reason = '';
        // REQ-053 / TEST-044: DRM ships behind a flag; off ⇒ a stated reason, never a broken stream.
        if (!apply_filters('fastpix_feature_drm', true)) {
            $reason = __('DRM playback is not enabled on this site.', 'fastpix-io');
        } else {
            // Browsers only unlock DRM (EME) in a secure context: HTTPS — or
            // localhost / 127.0.0.1, which every browser treats as secure (dev sites).
            $host  = strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));
            $local = in_array($host, array('localhost', '127.0.0.1', '::1'), true) || substr($host, -10) === '.localhost';
            if (!is_ssl() && !$local && !(defined('WP_CLI') && WP_CLI) && !(defined('REST_REQUEST') && REST_REQUEST)) {
                $reason = __('This protected video needs a secure (HTTPS) page to play.', 'fastpix-io');
            } else {
                $reason = self::drm_config_reason($video);
            }
        }

        return $reason;
    }

    /**
     * The DRM configuration id must still resolve; an empty one is recorded
     * for the health check (ERR-061), and a working one clears that record.
     */
    private static function drm_config_reason($video) {
        $config = (string) $video['drm_configuration_id'];
        if ($config === '') {
            $config = Fastpix_Settings_Page::drm_configuration_id();
        }
        if ($config === '') {
            update_option(Fastpix_Render::OPT_DRM_BAD, array('media_id' => $video['media_id'], 'at' => time()), false);

            return __('This protected video cannot play right now — its DRM configuration no longer resolves.', 'fastpix-io');
        }
        if (get_option(Fastpix_Render::OPT_DRM_BAD)) {
            delete_option(Fastpix_Render::OPT_DRM_BAD);
        }

        return '';
    }

    /* ------------------------------------------------------------ helpers */

    /**
     * A protected render inside a post proves that post embeds the video — file
     * it in the usage table right away (the sweep confirms later), so the SEC-014
     * check on player-config knows the page without waiting for the next sweep.
     */
    public static function note_usage($video) {
        if (is_admin()) {
            return;
        }
        $post_id  = (int) get_the_ID();
        $is_lesson = $post_id && Fastpix_Lms::is_lesson(get_post_type($post_id));
        // LMS templates (LearnPress, Tutor) render lesson content under the COURSE
        // post, so get_the_ID() is the course, not the lesson — resolve the real
        // lesson id, or the usage row is filed against the wrong post and every
        // lesson beat is dropped as "not embedded".
        if (!$is_lesson) {
            $lesson_id = Fastpix_Lms::rendering_lesson_id();
            if ($lesson_id) {
                $post_id   = $lesson_id;
                $is_lesson = true;
            }
        }
        if (!$post_id || (!in_the_loop() && !$is_lesson)) {
            return;
        }
        // Public videos are filed at render too: every progress beat is validated against
        // this table (Fastpix_Progress::published_video), so waiting for the nightly sweep
        // meant 403 on every beat — no resume — on a freshly published page. Public markup
        // is cached for an hour, so this is one cheap upsert per embed per hour. (QA F7)
        global $wpdb;
        $now = current_time('mysql', true);
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . Fastpix_Schema::table('usage') . ' (video_id, post_id, context, occurrences, last_seen_at, created_at, updated_at)
             VALUES (%d, %d, %s, 1, %s, %s, %s)
             ON DUPLICATE KEY UPDATE last_seen_at = VALUES(last_seen_at), updated_at = VALUES(updated_at)',
            (int) $video['id'], $post_id, 'render', $now, $now, $now
        ));
    }

    public static function chapters($video_id) {
        global $wpdb;

        $json = $wpdb->get_var($wpdb->prepare(
            'SELECT generated_json FROM ' . Fastpix_Schema::table('ai') . " WHERE video_id = %d AND kind = 'chapters' AND state = 'ready'",
            (int) $video_id
        ));
        $data = $json ? json_decode($json, true) : null;
        $list = array();
        if (is_array($data)) {
            $list = isset($data['chapters']) ? $data['chapters'] : $data;
        }
        $out  = array();
        foreach ((array) $list as $c) {
            if (!is_array($c)) {
                continue;
            }
            $out[] = array(
                'title' => (string) Fastpix_Sync::field($c, array('title', 'chapter', 'name')),
                'start' => Fastpix_Search::seconds(Fastpix_Sync::field($c, array('startTime', 'start', 'start_seconds'))),
                'end'   => Fastpix_Search::seconds(Fastpix_Sync::field($c, array('endTime', 'end', 'end_seconds'))),
            );
        }

        return $out;
    }

    private static function transcript($video) {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT start_seconds, content FROM ' . Fastpix_Schema::table('search_index') . " WHERE video_id = %d AND field = 'transcript' ORDER BY segment_index",
            (int) $video['id']
        ), ARRAY_A);
        if (!$rows) {
            return '';
        }
        $html = '<details class="fastpix-transcript"><summary>' . esc_html__('Transcript', 'fastpix-io') . '</summary>';
        foreach ($rows as $row) {
            $html .= '<p><span class="fastpix-transcript__time">' . esc_html(self::hms((float) $row['start_seconds'])) . '</span> ' . esc_html((string) $row['content']) . '</p>';
        }

        return $html . '</details>';
    }

    public static function nocache() {
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);   // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- cache-plugin convention
        }
        if (!headers_sent()) {
            nocache_headers();
        }
    }

    public static function enqueue() {
        if (!wp_script_is(Fastpix_Render::SCRIPT, 'registered')) {
            Fastpix_Render::register_assets();   // renders can happen outside wp_enqueue_scripts (REST, CLI, editor)
        }
        wp_enqueue_script(Fastpix_Render::SCRIPT);
        wp_enqueue_script(Fastpix_Render::HELPER);
        wp_enqueue_style('fastpix-embed');
    }

    public static function attr_string($attrs) {
        $out = '';
        foreach ($attrs as $k => $v) {
            if ($v === null || $v === '') {
                continue;
            }
            $out .= ' ' . $k . '="' . esc_attr($v) . '"';
        }

        return $out;
    }

    private static function hms($s) {
        $s = (int) $s;

        return $s >= 3600 ? sprintf('%d:%02d:%02d', $s / 3600, ($s / 60) % 60, $s % 60) : sprintf('%d:%02d', $s / 60, $s % 60);
    }

    /** @var array structured-data payloads collected while rendering this request */
    private static $seo = array();

    /* ---------------------------------------------------------- SEO output */

    public static function collect_seo($video, $playback) {   // NOSONAR php:S100 — WordPress snake_case naming
        if (!get_option(Fastpix_Render::OPT_SEO, true) || !apply_filters('fastpix_structured_data_enabled', true, $video)) {
            return;
        }
        $img  = Fastpix_Attachments::image_base() . '/' . rawurlencode($playback) . '/thumbnail.png';
        $data = array(
            '@context'     => 'https://schema.org',
            '@type'        => 'VideoObject',
            'name'         => (string) $video['title'],
            'description'  => (string) $video['description'],
            'thumbnailUrl' => array($img),
            // The manifest is the media itself (contentUrl), not a player page (embedUrl). [QA L13]
            'contentUrl'   => Fastpix_Attachments::stream_base() . '/' . rawurlencode($playback) . '.m3u8',
        );
        $created = strtotime((string) $video['created_at']);
        if ($created) {   // never a 1970 uploadDate [QA L13]
            $data['uploadDate'] = gmdate('c', $created);
        }
        if ($video['duration_seconds'] !== null) {
            $data['duration'] = 'PT' . (int) $video['duration_seconds'] . 'S';
        }
        $clips = array();
        foreach (self::chapters($video['id']) as $chapter) {
            $clips[] = array_filter(array(
                '@type'       => 'Clip',
                'name'        => $chapter['title'],
                'startOffset' => $chapter['start'],
                'endOffset'   => $chapter['end'],
                'url'         => add_query_arg('t', (int) $chapter['start'], get_permalink() ?: home_url('/')),
            ), function ($v) { return $v !== null && $v !== ''; });
        }
        if ($clips) {
            $data['hasPart'] = $clips;
        }
        self::$seo[$video['media_id']] = apply_filters('fastpix_structured_data', $data, $video);
    }

    /** MISS-008: no SEO-plugin hand-off is specified — JSON-LD is printed directly, filterable. */
    public static function print_structured_data() {   // NOSONAR php:S100 — WordPress snake_case naming
        foreach (self::$seo as $data) {
            echo '<script type="application/ld+json">' . wp_json_encode($data) . "</script>\n";
        }
        self::$seo = array();
    }
}
