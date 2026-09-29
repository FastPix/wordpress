<?php
/**
 * Renderer — live-stream embeds and the player-config route, split from
 * Fastpix_Render to keep each class within the 20-method budget. Same
 * behaviour; the spec IDs stay with the code they govern (RULE-014/033/034/
 * 040, REQ-071, SEC-012/014, API-P12).
 */

namespace Fastpix;

if (!defined('WPINC')) {
    die;
}

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery
// All SQL here runs against the plugin's own tables: identifiers come from the
// Fastpix_Schema::table() registry (a fixed whitelist, never request input) and
// every value travels through $wpdb->prepare(). Core APIs cannot query these tables.

class Fastpix_Render_Live {

    /** Post meta: a stream id this post was seen rendering as a PRIVATE live embed (SEC-014 gate for live player-config). */
    const META_LIVE = '_fastpix_live_stream';

    /* ---------------------------------------------------------------- live */

    /**
     * One embed, three states driven by the stored stream state (RULE-034):
     * waiting before start; the live player while active; the recording after.
     * The live playback id is read from the platform and cached briefly.
     */
    public static function render_live($stream_id, $over = array()) {
        global $wpdb;

        $settings = Fastpix_Render::settings($over);

        // Live is behind fastpix_feature_live. With it off, Fastpix_Live never
        // boots, so the /stream-state polling route is unregistered and a live
        // embed would render but never self-switch. Degrade to the neutral
        // fallback instead — same message a missing stream gives, so nothing
        // about the flag state leaks to a visitor. [RULE-040]
        $stream = null;
        if (Fastpix_Live::enabled()) {
            $stream = $wpdb->get_row($wpdb->prepare(
                Fastpix_Render::SQL_SELECT_ALL . Fastpix_Schema::table('live_streams') . ' WHERE stream_id = %s AND workspace_id NOT LIKE %s',   // a left workspace\'s stream reads "not available" [ASSUME-092]
                $stream_id, $wpdb->esc_like('prev:') . '%'
            ), ARRAY_A);
        }
        if (!$stream) {
            return Fastpix_Render_Player::message(__('This live stream is not available.', 'fastpix-io'), '');
        }
        if (!empty($stream['deleted_at'])) {
            // A deleted stream keeps its recording renderable — the delete dialog
            // promises "pages show the recording if one exists". No state poll:
            // the /stream-state route tombstones it, and nothing can change.
            $recording = self::live_recording($stream, 'ended', $over);

            return $recording !== null ? $recording : Fastpix_Render_Player::message(__('This live stream is not available.', 'fastpix-io'), '');
        }

        // Every state carries the poll attributes so player.js can reload the
        // page when the stored state moves on — the embed switches by itself
        // (waiting → live → recording) without anyone editing the post. [RULE-034]
        $status = strtolower((string) $stream['status']);
        $live_attrs = Fastpix_Render_Player::attr_string(array(
            'data-fp-stream'     => $stream_id,
            'data-fp-live-state' => $status,
            'data-fp-state-url'  => rest_url(Fastpix_Rest::NS . '/stream-state/' . rawurlencode($stream_id)),
        ));

        if ($status === 'active') {
            $result = self::live_active($stream_id, $stream, $settings, $live_attrs);
        } else {
            $result = self::live_recording($stream, $status, $over);
            // Ended, recorded, and still no recording linked: ask the platform for it (throttled) instead of
            // promising "will appear here" forever on a site whose recording webhook never arrived. (QA report #12)
            if ($result === null && $status === 'ended' && !empty($stream['recording_enabled']) && empty($stream['recorded_video_id'])
                && Fastpix_Live::find_recording($stream_id)) {
                $stream = $wpdb->get_row($wpdb->prepare(Fastpix_Render::SQL_SELECT_ALL . Fastpix_Schema::table('live_streams') . ' WHERE stream_id = %s', $stream_id), ARRAY_A);
                $result = self::live_recording($stream, $status, $over);
            }
            if ($result === null) {
                Fastpix_Render_Player::enqueue();   // the waiting card needs player.js for the state poll

                $result = '<figure class="wp-block-fastpix-video fastpix-embed fastpix-embed--message fastpix-embed--waiting"' . $live_attrs . '><figcaption class="fastpix-embed__note">'
                    . esc_html(self::waiting_note($status, $stream)) . '</figcaption></figure>';
            } else {
                // The recording embed polls too, so a RE-USED stream switches back
                // to live. Stamped as data-fp-recording-of (not data-fp-stream):
                // player.js must reload this card only for active/preparing, never
                // for the ended+recording answer it already shows.
                // First <figure>, unanchored: an anti-skip embed leads with its <style>. (QA X5)
                $result = preg_replace('/<figure\b/', '<figure' . Fastpix_Render_Player::attr_string(array(
                    'data-fp-recording-of' => $stream_id,
                    'data-fp-live-state'   => 'recording',
                    'data-fp-state-url'    => rest_url(Fastpix_Rest::NS . '/stream-state/' . rawurlencode($stream_id)),
                )), $result, 1);
            }
        }

        return $result;
    }

    /** The waiting card's line: not started yet, ended with a recording to come, or just ended. */
    private static function waiting_note($status, $stream) { // NOSONAR php:S100 — WordPress snake_case naming
        if ($status !== 'ended') {
            return __('The stream has not started yet — this page will play it once it goes live.', 'fastpix-io');
        }

        return !empty($stream['recording_enabled'])
            ? __('This stream has ended. The recording will appear here when it is ready.', 'fastpix-io')
            : __('This stream has ended.', 'fastpix-io');   // recording off: promise nothing
    }

    /** The live player for an active stream — or the warming-up card while the playback id resolves. */
    private static function live_active($stream_id, $stream, $settings, $live_attrs) {
        $playback = self::live_playback($stream_id);
        $player_attrs = array();
        if ($playback['id'] !== '') {
            $player_attrs = array_filter(array(
                'playback-id'            => $playback['id'],
                'stream-type'            => 'live-stream',
                'accent-color'           => $settings['accentColour'],
                'metadata-workspace-key' => (string) get_option(Fastpix_Connection::OPT_WORKSPACE_ID, ''),
                'metadata-video-title'   => (string) $stream['name'],
            ));
            // RULE-033/REQ-071: a private stream inherits protection — the
            // same signed token as recorded video, minted at render.
            if ($playback['policy'] !== '' && $playback['policy'] !== 'public') {
                $tokens = Fastpix_Signing::tokens($playback['id']);
                if (is_wp_error($tokens)) {
                    $player_attrs = array();   // fall through to the warming-up card
                } else {
                    $player_attrs['token'] = $tokens['media'];
                    // RULE-014: the markup carries a short-lived signed token,
                    // so it must NEVER be persisted by a page or edge cache.
                    // Mark the page no-store so a fresh token is minted on every
                    // load — including the reloads the 15 s state poll triggers.
                    // A cache that ignores that, or a broadcast longer than the
                    // token TTL, is covered the same way as recorded video:
                    // player.js renews/recovers from player-config (QA L7/L8).
                    // The URL carries no nonce or signature, so it never expires;
                    // ?stream= is how the route finds a live playback id (the
                    // streams table stores none).
                    $player_attrs['data-fp-config'] = add_query_arg('stream', rawurlencode($stream_id), rest_url(Fastpix_Rest::NS . '/player-config/' . rawurlencode($playback['id'])));
                    $player_attrs['data-fp-exp']    = (string) (int) $tokens['exp'];
                    self::note_live_usage($stream_id);
                    Fastpix_Render_Player::nocache();
                }
            }
        }
        Fastpix_Render_Player::enqueue();
        if (!$player_attrs) {
            return '<figure class="wp-block-fastpix-video fastpix-embed fastpix-embed--message fastpix-embed--waiting"' . $live_attrs
                . '><figcaption class="fastpix-embed__note">' . esc_html__('The stream is live — the player is warming up.', 'fastpix-io') . '</figcaption></figure>';
        }

        // Live must start muted+autoplay (browsers block unmuted autoplay);
        // the per-embed options still govern controls/click/keyboard. The
        // player reads `auto-play` (not `autoplay`), and the control bar is the
        // `--controls` custom property, not the read-but-unused hide-controls.
        $cf         = Fastpix_Render_Player::control_flags($settings);
        $flags      = ' auto-play muted' . $cf['flags'];
        $style_attr = $cf['style'] !== '' ? ' style="' . esc_attr($cf['style']) . '"' : '';

        return '<figure class="wp-block-fastpix-video fastpix-embed fastpix-embed--live"' . $live_attrs . '><fastpix-player'
            . Fastpix_Render_Player::attr_string($player_attrs) . $flags . $style_attr . '></fastpix-player></figure>';
    }

    /** The recording embed once an ended stream has one; null when there is nothing to show yet. */
    private static function live_recording($stream, $status, $over) {
        global $wpdb;

        $result = null;
        if (!empty($stream['recorded_video_id']) && in_array($status, array('ended', 'idle', 'disabled'), true)) {
            $video = $wpdb->get_row($wpdb->prepare('SELECT media_id FROM ' . Fastpix_Schema::table('videos') . ' WHERE id = %d', (int) $stream['recorded_video_id']), ARRAY_A);
            if ($video) {
                // The recording inherits the same per-embed options as the live embed.
                $result = Fastpix_Render::render($video['media_id'], $over, array('context' => 'live-recording'));
            }
        }

        return $result;
    }

    /** ['id' => playback id, 'policy' => accessPolicy] from the platform, cached 5 min. */
    private static function live_playback($stream_id) {
        $hit = Fastpix_Cache::remember('live', 'pb2:' . $stream_id, 300, function () use ($stream_id) {
            $client = new Fastpix_Api_Client();
            $result = $client->request('GET', '/live/streams/' . rawurlencode($stream_id), array('context' => 'background'));
            if (is_wp_error($result)) {
                return null;   // a transient API error is not "no playback id" — never cached
            }
            $data = isset($result['body']['data']) ? $result['body']['data'] : (array) $result['body'];
            $ids  = (array) Fastpix_Sync::field($data, array('playbackIds', 'playback_ids'));

            return array(
                'id'     => isset($ids[0]['id']) ? (string) $ids[0]['id'] : '',
                'policy' => isset($ids[0]['accessPolicy']) ? strtolower((string) $ids[0]['accessPolicy']) : '',
            );
        });

        return is_array($hit) ? $hit + array('id' => '', 'policy' => '') : array('id' => '', 'policy' => '');
    }

    /* ------------------------------------------------- player-config route */

    public static function register_routes() {
        Fastpix_Rest::register('/player-config/(?P<id>[A-Za-z0-9_-]+)', array(
            'methods'       => 'GET',
            'callback'      => array(__CLASS__, 'player_config'),
            'public_bucket' => 'player_config',   // per address AND per identifier [SEC-012]
        ));
    }

    /**
     * [API-P12] Unsigned half cached (12 h); a fresh token per call; the
     * RESPONSE is no-store at every layer.
     */
    public static function player_config($request) {
        global $wpdb;

        $playback = (string) $request['id'];
        $video_id = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT video_id FROM ' . Fastpix_Schema::table('playback_ids') . ' WHERE playback_id = %s AND deleted_at IS NULL',
            $playback
        ));
        $video  = $video_id ? $wpdb->get_row($wpdb->prepare(Fastpix_Render::SQL_SELECT_ALL . Fastpix_Schema::table('videos') . ' WHERE id = %d AND deleted_at IS NULL', $video_id), ARRAY_A) : null;
        $policy = $video ? Fastpix_Render::policy($video) : '';

        if (!$video && is_string($request['stream'])) {   // a live embed's config URL names its stream
            return self::no_store(self::live_config($playback, (string) $request['stream']));
        }
        $refusal = self::config_refusal($video, $playback, $policy);
        if (!$refusal && Fastpix_Videos_Rest::is_other_workspace($video)) {   // the connected key cannot sign it [ASSUME-092]
            $refusal = new \WP_Error('fastpix_video_missing', __('No such video.', 'fastpix-io'), array('status' => 404));
        }
        if ($refusal) {
            return $refusal;
        }
        $config = Fastpix_Cache::remember('player_config', 'pc:' . $video['id'] . ':' . hash('sha256', $video['updated_at']), Fastpix_Cache::TTL_PLAYER_CONFIG, function () use ($video, $playback, $policy) {
            return array(
                'playback_id'   => $playback,
                'access_policy' => $policy,
                'title'         => (string) $video['title'],
                'aspect_ratio'  => (string) $video['aspect_ratio'],
                'duration'      => $video['duration_seconds'] !== null ? (float) $video['duration_seconds'] : null,
                'chapters'      => Fastpix_Render_Player::chapters($video['id']),
                'poster'        => $policy === 'public' ? Fastpix_Attachments::image_base() . '/' . rawurlencode($playback) . '/thumbnail.png' : null,
                'stream'        => $policy === 'public' ? Fastpix_Attachments::stream_base() . '/' . rawurlencode($playback) . '.m3u8' : null,
            );
        });

        if ($policy !== 'public') {
            $config = self::config_tokens($config, $playback, $policy);
        }
        return self::no_store($config);
    }

    /** The RESPONSE is no-store at every layer; an error passes through. */
    private static function no_store($config) {
        if (is_wp_error($config)) {
            return $config;
        }
        $response = rest_ensure_response($config);
        $response->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $response->header('Pragma', 'no-cache');
        $response->header('Expires', '0');

        return $response;
    }

    /**
     * The token set for a PRIVATE stream that is on air — what a live embed's
     * data-fp-config asks for. Everything else is the same 404 an unknown id
     * gets: flag off, unknown/deleted/left-workspace stream, not active, a
     * playback id that is not this stream's, a public stream (needs no token).
     * Same SEC-014 gate as recorded video. Carries tokens only — never keys.
     */
    private static function live_config($playback, $stream_id) {
        global $wpdb;

        $stream = Fastpix_Live::enabled() ? $wpdb->get_row($wpdb->prepare(
            Fastpix_Render::SQL_SELECT_ALL . Fastpix_Schema::table('live_streams') . ' WHERE stream_id = %s AND deleted_at IS NULL AND workspace_id NOT LIKE %s',
            $stream_id, $wpdb->esc_like('prev:') . '%'
        ), ARRAY_A) : null;
        // The local row is checked BEFORE the platform is asked, so a made-up id costs no API call.
        $live = ($stream && strtolower((string) $stream['status']) === 'active') ? self::live_playback($stream_id) : null;
        if (!$live || $live['id'] === '' || !hash_equals($live['id'], $playback) || in_array($live['policy'], array('', 'public'), true)) {
            return new \WP_Error('fastpix_video_missing', __('Unknown video.', 'fastpix-io'), array('status' => 404));
        }

        $post_ids = get_posts(array(
            'post_type' => 'any', 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => 50, 'no_found_rows' => true,
            'meta_key' => self::META_LIVE, 'meta_value' => $stream_id,   // phpcs:ignore WordPress.DB.SlowDBQuery -- indexed meta_key, rate-limited route
        ));
        if (!self::posts_allow($post_ids, $stream)) {
            do_action('fastpix_audit_event', 'player_config_refused', array('playback_id' => $playback));

            return new \WP_Error('fastpix_not_allowed', __('This video is protected. Open the page it is embedded on.', 'fastpix-io'), array('status' => 403));
        }
        $tokens = Fastpix_Signing::tokens($playback);   // the same media-audience token live_active() mints

        return is_wp_error($tokens) ? $tokens : array(
            'playback_id'   => $playback,
            'access_policy' => $live['policy'],
            'token'         => $tokens['media'],
            'drm_token'     => null,
            'exp'           => $tokens['exp'],
        );
    }

    /**
     * A private live render inside a post proves that post embeds the stream —
     * the live twin of Fastpix_Render_Player::note_usage() (the usage table is
     * keyed by video id, a stream has none, so this is post meta).
     * ponytail: no LMS lesson-id resolution and no sweep — add if live lessons appear.
     */
    private static function note_live_usage($stream_id) {
        $post_id = (int) get_the_ID();
        if (is_admin() || !$post_id || !in_the_loop()) {
            return;
        }
        if (!in_array($stream_id, get_post_meta($post_id, self::META_LIVE), true)) {
            add_post_meta($post_id, self::META_LIVE, $stream_id);
        }
    }

    /** save_post: a post whose content no longer names the stream stops vouching for it (the next render re-files a builder embed). */
    public static function forget_live_usage($post_id, $post) {
        foreach (get_post_meta($post_id, self::META_LIVE) as $stream_id) {
            if (strpos((string) $post->post_content, (string) $stream_id) === false) {
                delete_post_meta($post_id, self::META_LIVE, $stream_id);
            }
        }
    }

    /** The 404 / 403 gate for player-config; null when the request may proceed. */
    private static function config_refusal($video, $playback, $policy) {
        $refusal = null;
        if (!$video) {
            $refusal = new \WP_Error('fastpix_video_missing', __('Unknown video.', 'fastpix-io'), array('status' => 404));
        } elseif ($policy !== 'public' && !self::viewer_may_stream($video)) {
            // SEC-014: this route must not out-mint the page's own gate. The
            // playback id is visible in page source, so possession of it proves
            // nothing — tokens only for viewers who could open an embedding page.
            do_action('fastpix_audit_event', 'player_config_refused', array('playback_id' => $playback));

            $refusal = new \WP_Error('fastpix_not_allowed', __('This video is protected. Open the page it is embedded on.', 'fastpix-io'), array('status' => 403));
        }

        return $refusal;
    }

    /** The signed half of the payload — a fresh token per call. */
    private static function config_tokens($config, $playback, $policy) {
        $tokens = Fastpix_Signing::tokens($playback, $policy === 'drm');
        if (is_wp_error($tokens)) {
            return $tokens;
        }
        $urls = Fastpix_Signing::urls($playback, $tokens);
        $config['token']     = ($policy === 'drm' && isset($tokens['drm'])) ? $tokens['drm'] : $tokens['media'];   // DRM streams want the drm-aud token
        $config['drm_token'] = isset($tokens['drm']) ? $tokens['drm'] : null;
        $config['exp']       = $tokens['exp'];
        $config['poster']    = $urls['thumbnail'];
        $config['stream']    = $urls['stream'];

        return $config;
    }

    /**
     * May the CURRENT requester stream this protected video? True when some
     * post embedding it (usage table, kept by the usage sweep) is readable to
     * them: WP visibility + password, plus the big membership/LMS gates when
     * those plugins are active. `fastpix_player_config_access` has the last
     * word, so any custom wall can hook in. Editors/library viewers always may
     * (previews). [SEC-014]
     */
    private static function viewer_may_stream($video) {
        if (current_user_can(Fastpix_Capabilities::VIEW_VIDEOS)) {
            return true;
        }

        global $wpdb;

        return self::posts_allow(array_map('intval', $wpdb->get_col($wpdb->prepare(
            'SELECT DISTINCT post_id FROM ' . Fastpix_Schema::table('usage') . ' WHERE video_id = %d',
            (int) $video['id']
        ))), $video);
    }

    /** The gate itself, shared by video and live: $subject is the videos row, or the live_streams row (it has `stream_id`). */
    private static function posts_allow($post_ids, $subject) {
        if (current_user_can(Fastpix_Capabilities::VIEW_VIDEOS)) {
            return true;
        }

        $allowed = false;
        foreach ($post_ids as $post_id) {
            if (self::post_readable($post_id)) {
                $allowed = true;
                break;
            }
        }

        return (bool) apply_filters('fastpix_player_config_access', $allowed, $subject, $post_ids);
    }

    /** One embedding post, checked the way its own wall would check it. */
    private static function post_readable($post_id) {
        $post = get_post($post_id);
        if (!$post || post_password_required($post)) {
            return false;
        }
        if (!is_post_publicly_viewable($post) && !current_user_can('read_post', $post_id)) {
            return false;
        }

        return self::membership_allows($post, $post_id);
    }

    /** The common membership/LMS walls, asked directly when present (all return their own booleans). */
    private static function membership_allows($post, $post_id) {
        $user_id = get_current_user_id();
        $allowed = true;
        if (function_exists('pmpro_has_membership_access') && !pmpro_has_membership_access($post_id, $user_id ?: null)) {
            $allowed = false;   // Paid Memberships Pro
        } elseif (function_exists('rcp_user_can_access') && !rcp_user_can_access($user_id, $post_id)) {
            $allowed = false;   // Restrict Content Pro
        } elseif (class_exists('\MeprRule') && \MeprRule::is_locked($post)) {
            $allowed = false;   // MemberPress
        } elseif (function_exists('sfwd_lms_has_access') && !sfwd_lms_has_access($post_id, $user_id)) {
            $allowed = false;   // LearnDash
        } elseif (function_exists('llms_page_restricted')) {
            $r = llms_page_restricted($post_id, $user_id);
            if (is_array($r) && !empty($r['is_restricted'])) {
                $allowed = false;   // LifterLMS
            }
        }

        return $allowed;
    }
}
