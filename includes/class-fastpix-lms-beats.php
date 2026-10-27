<?php
/**
 * Lesson beats + completion — the write half of Fastpix_Lms.
 *
 * Split out of class-fastpix-lms.php for size only; the consent model,
 * guard order and hook names are unchanged (RULE-031/032, ASSUME-046).
 * Entry point handle_beat() is reached through Fastpix_Lms::handle_beat().
 */

namespace Fastpix;

if (!defined('WPINC')) {
    die;
}

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery
// All SQL here runs against the plugin's own tables: identifiers come from the
// Fastpix_Schema::table() registry (a fixed whitelist, never request input) and
// every value travels through $wpdb->prepare(). Core APIs cannot query these tables.

class Fastpix_Lms_Beats {

    /* -------------------------------------------------- embed configuration */

    /**
     * The lesson's own block markup is the authority on completeAt /
     * trackViewer — the client is never trusted. Cached against the post's
     * modified time. Null = this post has no fastpix/video block for that media.
     */
    public static function embed_config($post_id, $media_id) {
        $post = get_post($post_id);
        if (!$post || $post->post_status !== 'publish' || !Fastpix_Lms::is_lesson($post->post_type)) {
            return null;
        }

        $key    = 'cfg:' . $post_id . ':' . hash('sha256', $media_id . '|' . $post->post_modified_gmt);
        $cached = Fastpix_Cache::get('lms', $key);
        if (is_array($cached)) {
            return isset($cached['none']) ? null : $cached;
        }

        $config = self::find_block(parse_blocks($post->post_content), (string) $media_id);
        if ($config === null) {
            // Classic-editor lessons (e.g. TutorLMS's builder never loads
            // Gutenberg) carry the options as shortcode attributes instead —
            // still read from the lesson's own content, never from the client.
            $config = self::find_shortcode($post->post_content, (string) $media_id);
        }
        Fastpix_Cache::set('lms', $key, $config === null ? array('none' => 1) : $config, HOUR_IN_SECONDS);

        return $config;
    }

    private static function find_shortcode($content, $media_id) {
        if (!has_shortcode($content, 'fastpix')) {
            return null;
        }
        preg_match_all('/' . get_shortcode_regex(array('fastpix')) . '/', $content, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $atts = shortcode_parse_atts($match[3]);
            $atts = is_array($atts) ? array_change_key_case($atts, CASE_LOWER) : array();
            if (self::shortcode_media_id($atts) !== $media_id) {
                continue;
            }
            $at = isset($atts['complete_at']) ? (int) $atts['complete_at'] : 90;

            return array(
                'completeAt'  => ($at >= 10 && $at <= 100) ? $at : 90,
                'trackViewer' => self::shortcode_track($atts),
            );
        }

        return null;
    }

    private static function shortcode_media_id($atts) {
        foreach (array('id', 'videoid', 'media_id', 'mediaid') as $k) {
            if (!empty($atts[$k])) {
                return (string) $atts[$k];
            }
        }

        return '';
    }

    private static function shortcode_track($atts) {
        return in_array('track_viewer', $atts, true) || in_array('trackviewer', $atts, true)   // bare flag
            || in_array(strtolower((string) ($atts['track_viewer'] ?? $atts['trackviewer'] ?? '')), array('1', 'true', 'yes', 'on'), true);
    }

    private static function find_block($blocks, $media_id) {
        foreach ((array) $blocks as $block) {
            if (($block['blockName'] ?? '') === 'fastpix/video'
                && (string) ($block['attrs']['videoId'] ?? '') === $media_id) {
                $at = (int) ($block['attrs']['completeAt'] ?? 90);

                return array(
                    'completeAt'  => ($at >= 10 && $at <= 100) ? $at : 90,
                    'trackViewer' => !empty($block['attrs']['trackViewer']),
                );
            }
            if (!empty($block['innerBlocks'])) {
                $found = self::find_block($block['innerBlocks'], $media_id);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    /* ------------------------------------------------------------ the beat */

    /**
     * Called by Fastpix_Progress::write() AFTER the RULE-032 guard set has
     * passed and the aggregate row is upserted — lesson handling rides the
     * same route and the same rate-limit buckets.
     *
     * @param array  $video    videos row (id, media_id, duration_seconds, …)
     * @param string $viewer   the progress viewer key ('user:N' or anon)
     * @param float  $position current position, already duration-validated
     */
    public static function handle_beat($video, $viewer, $position, $request) {
        $post_id = (int) $request->get_param('post');
        $counts  = self::parse_plays((string) $request->get_param('plays'), (string) $request->get_param('slots'));
        $config  = self::beat_config($video, $post_id, $counts);

        $learner = (string) $request->get_param('learner');
        $user_id = (strpos($viewer, 'user:') === 0) ? (int) substr($viewer, 5) : 0;

        // Per-learner storage: only when the embed opted in AND the beat comes
        // from a logged-in user whose hash matches — trackViewer off means no
        // row is ever written, server-enforced. A supplied hash that does NOT
        // match the authenticated user is a forgery: the whole beat is dropped,
        // it does not fall through to the anonymous path.
        $genuine = $learner !== ''
            && strpos($viewer, 'user:') === 0
            && hash_equals(Fastpix_Lms::viewer_hash((int) substr($viewer, 5)), $learner);
        if ($config === null || ($learner !== '' && !$genuine)) {
            return null;
        }

        if ($config['trackViewer'] && $genuine) {
            return self::tracked_beat($video, $post_id, $learner, $counts, $position, $config, $viewer);
        }

        return self::untracked_beat($video, $viewer, $post_id, $counts, $config, $user_id);
    }

    /** The beat's server-side authority, or null when any guard fails. */
    private static function beat_config($video, $post_id, $counts) {
        global $wpdb;

        if ($post_id <= 0 || $counts === null) {
            return null;
        }

        // Guard: the post must actually embed this video (usage row — filed at
        // render), and its block must exist; the block's attrs are authoritative.
        $embedded = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . Fastpix_Schema::table('usage') . ' WHERE video_id = %d AND post_id = %d',
            (int) $video['id'], $post_id
        ));
        if ($embedded === 0) {
            return null;
        }

        return self::embed_config($post_id, (string) $video['media_id']);
    }

    /** trackViewer on + genuine learner: the per-learner row is written. */
    private static function tracked_beat($video, $post_id, $learner, $counts, $position, $config, $viewer) {
        global $wpdb;

        $user_id = (int) substr($viewer, 5);   // a genuine learner is always 'user:N' (the caller checked)
        $now   = current_time('mysql', true);
        $table = Fastpix_Schema::table('lesson_progress');
        $row   = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE viewer_key = %s AND post_id = %d AND media_id = %s",
            $learner, $post_id, $video['media_id']
        ), ARRAY_A);

        // Counts only ever accumulate — per-slot element-wise MAX against
        // the stored counts, so a stale or replayed beat cannot lower
        // anything (the server half of seek-marks-nothing).
        $stored = $row && !empty($row['plays']) ? (string) $row['plays'] : str_repeat("\0", 100);
        $merged = '';
        for ($i = 0; $i < 100; $i++) {
            $merged .= chr(max(ord($stored[$i] ?? "\0"), ord($counts[$i])));
        }
        $filled = Fastpix_Lms::count_played($merged);
        $done   = $filled >= $config['completeAt'];

        $values = array(
            'plays'        => $merged,
            'slots'        => self::plays_to_bitmap($merged),   // derived, for anything still reading the bitmap
            'max_position' => max((int) floor($position), $row ? (int) $row['max_position'] : 0),
            'updated_at'   => $now,
        );
        $fires = $done && (!$row || $row['completed_at'] === null);
        if ($fires) {
            $values['completed_at'] = $now;   // once, immutable
        }

        if ($row) {
            $wpdb->update($table, $values, array(
                'viewer_key' => $learner, 'post_id' => $post_id, 'media_id' => $video['media_id'],
            ));
        } else {
            $wpdb->insert($table, $values + array(
                'viewer_key' => $learner, 'post_id' => $post_id, 'media_id' => $video['media_id'],
            ));
        }

        if ($fires) {
            self::completed($video['media_id'], $post_id, $learner, $user_id, $viewer);
        }

        // The player reveals "Next lesson" the moment this says completed.
        return self::beat_state($post_id, $done);
    }

    /**
     * No tracking: nothing is stored. Completion still fires with a null
     * viewer key — once per viewer best-effort (no durable identity
     * exists by design), deduped for 30 days in completed().
     */
    private static function untracked_beat($video, $viewer, $post_id, $counts, $config, $user_id) {
        $done = Fastpix_Lms::count_played($counts) >= $config['completeAt'];
        if ($done) {
            self::completed($video['media_id'], $post_id, null, $user_id, $viewer);
        }

        return self::beat_state($post_id, $done);
    }

    /** What the player needs back from a lesson beat. */
    private static function beat_state($post_id, $done) {
        return array(
            'completed' => (bool) $done,
            'next_url'  => $done ? Fastpix_Lms_Courses::next_lesson_url($post_id) : '',
        );
    }

    /**
     * Completion: the hook fires first (for custom rules and extensions), then
     * the plugin marks the lesson complete in the detected LMS itself — it has
     * to just work, no snippet required. `fastpix_lms_auto_complete` (default
     * true) is the kill-switch.
     */
    private static function completed($media_id, $post_id, $viewer_key, $user_id, $viewer) {
        // One firing per viewer per lesson video, whichever path the beat took:
        // a beat that arrives without the learner hash (cached page, tracking
        // toggled) must not complete again once the tracked row does, or vice
        // versa. The guard is keyed by the progress viewer key both paths share.
        // ponytail: 30-day transient; a tracked row completed >30 days ago is
        // still fenced by its own completed_at, the untracked path is best-effort.
        $guard = 'fastpix_lsn_done_' . hash('sha256', $viewer . '|' . $post_id . '|' . $media_id);
        if (get_transient($guard)) {
            return;
        }
        set_transient($guard, 1, 30 * DAY_IN_SECONDS);

        do_action('fastpix_video_completed', (string) $media_id, (int) $post_id, $viewer_key);
        do_action('fastpix_log', 'lesson_video_completed', array(
            'scope' => 'progress', 'severity' => 'info',
            'message' => sprintf('Video %s reached its completion threshold on lesson %d', $media_id, $post_id),
        ));

        if ($user_id > 0 && apply_filters('fastpix_lms_auto_complete', true, $post_id, $user_id)) {
            self::mark_lesson_complete((int) $post_id, (int) $user_id);
        }
    }

    /**
     * Tell the active LMS, through its OWN public function, that this student
     * finished this lesson — every call guarded, so an absent or updated LMS
     * degrades to the hook alone rather than an error.
     */
    public static function mark_lesson_complete($post_id, $user_id) {
        $type = get_post_type($post_id);

        try {
            $marked = self::dispatch_completion($type, (int) $post_id, (int) $user_id);
        } catch (\Throwable $e) {
            do_action('fastpix_log', 'lms_mark_failed', array(
                'scope' => 'progress', 'severity' => 'warning',
                'message' => sprintf('Could not mark lesson %d complete: %s', $post_id, $e->getMessage()),
                'learner' => self::learner_ref($user_id),
            ));
            return;
        }
        if (!$marked) {
            return;
        }

        do_action('fastpix_log', 'lesson_marked_in_lms', array(
            'scope' => 'progress', 'severity' => 'info',
            'message' => sprintf('Lesson %d marked complete (%s)', $post_id, $type),
            'learner' => self::learner_ref($user_id),
        ));
    }

    /**
     * Log lines never carry a WordPress user id (house rule: hashed identities,
     * and only on lesson surfaces). The message stays identity-free — it is what
     * the Settings "problems" box prints — and the context carries the same
     * hashed key prefix the course view shows.
     */
    private static function learner_ref($user_id) {
        return substr(Fastpix_Lms::viewer_hash((int) $user_id), 0, 12);
    }

    /** Route to the one LMS that claims this post type. True = log the success line. */
    private static function dispatch_completion($type, $post_id, $user_id) {
        $marked = true;
        if (in_array($type, array('sfwd-lessons', 'sfwd-topic'), true) && function_exists('learndash_process_mark_complete')) {
            learndash_process_mark_complete($user_id, $post_id);
        } elseif (function_exists('tutor_utils') && function_exists('tutor') && $type === tutor()->lesson_post_type
            && is_callable(array(tutor_utils(), 'mark_lesson_complete'))) {   // Tutor 3 proxies utils via __call — method_exists() lies
            tutor_utils()->mark_lesson_complete($post_id, $user_id);
        } elseif ($type === 'llms_lesson' && function_exists('llms_mark_complete')) {
            llms_mark_complete($user_id, $post_id, 'lesson', 'fastpix_video_completed');
        } elseif ($type === 'lp_lesson' && function_exists('learn_press_get_user')) {
            $marked = self::learnpress_complete($post_id, $user_id);
        } else {
            $marked = false;   // no LMS claims this post type — the hook already fired
        }

        return $marked;
    }

    private static function learnpress_complete($post_id, $user_id) {
        $lp_user = learn_press_get_user($user_id);
        if (!$lp_user || !is_callable(array($lp_user, 'complete_lesson'))) {
            return true;   // matches the pre-split fall-through: the success line still logs
        }

        // `learn_press_get_item_course_id()` returns null in current
        // LearnPress (verified live 2026-09-01) — passing 0 makes
        // complete_lesson throw "Invalid course" and the lesson is
        // never ticked. Resolve the course the way LearnPress's own
        // REST does (section → course join).
        $course_id = self::learnpress_course_id($post_id);
        $ok        = true;
        if ($course_id <= 0) {
            do_action('fastpix_log', 'lms_mark_failed', array(
                'scope' => 'progress', 'severity' => 'warning',
                'message' => sprintf('LearnPress: no course resolved for lesson %d — not completed', $post_id),
            ));
            $ok = false;
        } else {
            // LearnPress 4.2 creates the learner's lesson row lazily with an
            // EMPTY status; complete_lesson() rejects those as "Invalid
            // lesson". Mark it started first (verified live 2026-09-01).
            self::learnpress_ensure_started($user_id, $post_id, $course_id);
            $res = $lp_user->complete_lesson($post_id, $course_id);
            if (is_wp_error($res)) {   // e.g. not enrolled / lesson not started
                do_action('fastpix_log', 'lms_mark_failed', array(
                    'scope' => 'progress', 'severity' => 'warning',
                    'message' => sprintf('LearnPress could not complete lesson %d: %s', $post_id, $res->get_error_message()),
                    'learner' => self::learner_ref($user_id),
                ));
                $ok = false;
            }
        }

        return $ok;
    }

    /**
     * Make sure the learner's lesson user-item is 'started' so LearnPress's
     * complete_lesson() accepts it. Modern LearnPress creates the row lazily
     * with an empty status on view (and none at all for some flows) — both
     * shapes make complete_lesson() throw "Invalid lesson". Only acts for an
     * enrolled learner; an un-enrolled one is left for complete_lesson's own
     * "must enroll" refusal, which we log.
     */
    private static function learnpress_ensure_started($user_id, $lesson_id, $course_id) {
        global $wpdb;
        $t   = $wpdb->prefix . 'learnpress_user_items';
        $now = current_time('mysql', true);
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT user_item_id, status FROM {$t} WHERE user_id=%d AND item_id=%d AND item_type='lp_lesson' ORDER BY user_item_id DESC LIMIT 1",
            $user_id, $lesson_id
        ), ARRAY_A);

        if ($row && in_array($row['status'], array('started', 'completed'), true)) {
            return;   // already usable
        }
        if ($row) {
            $wpdb->update($t,
                array('status' => 'started', 'graduation' => 'in-progress', 'start_time' => $now),
                array('user_item_id' => (int) $row['user_item_id']));
            return;
        }
        $parent = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT user_item_id FROM {$t} WHERE user_id=%d AND item_id=%d AND item_type='lp_course' ORDER BY user_item_id DESC LIMIT 1",
            $user_id, $course_id
        ));
        if (!$parent) {
            return;   // not enrolled — complete_lesson() refuses with its own message
        }
        $wpdb->insert($t, array(
            'user_id' => $user_id, 'item_id' => $lesson_id, 'ref_id' => $course_id,
            'item_type' => 'lp_lesson', 'ref_type' => 'lp_course',
            'status' => 'started', 'graduation' => 'in-progress',
            'start_time' => $now, 'parent_id' => $parent,
        ));
    }

    /**
     * A LearnPress lesson's course id. `learn_press_get_item_course_id()`
     * returns null in current LearnPress, so resolve it the way LearnPress's own
     * REST controllers do — the section_items → sections join. Verified live
     * 2026-09-01.
     */
    private static function learnpress_course_id($lesson_id) {
        global $wpdb;
        $sections      = $wpdb->prefix . 'learnpress_sections';
        $section_items = $wpdb->prefix . 'learnpress_section_items';

        $course_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT s.section_course_id
               FROM {$sections} s
               INNER JOIN {$section_items} si ON si.section_id = s.section_id
              WHERE si.item_id = %d
              ORDER BY si.section_id DESC LIMIT 1",
            (int) $lesson_id
        ));

        if ($course_id <= 0 && function_exists('learn_press_get_item_course_id')) {
            $course_id = (int) learn_press_get_item_course_id($lesson_id, 'lp_lesson');   // fallback for other LP versions
        }

        return $course_id;
    }

    /* -------------------------------------------------------- slot packing */

    /**
     * Client play counts: up to 200 hex chars → 100 bytes (one count per slot).
     * A legacy 26-hex bitmap (cached pages) is upgraded to counts of 1.
     * Null when malformed.
     */
    private static function parse_plays($plays_hex, $slots_hex) {
        if ($plays_hex !== '' && strlen($plays_hex) <= 200 && ctype_xdigit($plays_hex)) {
            if (strlen($plays_hex) % 2) {
                $plays_hex = '0' . $plays_hex;
            }

            return str_pad(hex2bin($plays_hex), 100, "\0");
        }
        if ($slots_hex !== '' && strlen($slots_hex) <= Fastpix_Lms::SLOT_BYTES * 2 && ctype_xdigit($slots_hex)) {
            if (strlen($slots_hex) % 2) {
                $slots_hex = '0' . $slots_hex;
            }
            $bits   = str_pad(hex2bin($slots_hex), Fastpix_Lms::SLOT_BYTES, "\0");
            $counts = '';
            for ($slot = 0; $slot < 100; $slot++) {
                $counts .= (ord($bits[$slot >> 3]) & (1 << ($slot % 8))) ? "\1" : "\0";
            }

            return $counts;
        }

        return null;
    }

    /** Counts → the legacy 13-byte yes/no bitmap. */
    private static function plays_to_bitmap($counts) {
        $bits = array_fill(0, Fastpix_Lms::SLOT_BYTES, 0);
        for ($slot = 0; $slot < 100; $slot++) {
            if (ord($counts[$slot] ?? "\0") > 0) {
                $bits[$slot >> 3] |= 1 << ($slot % 8);
            }
        }

        return implode('', array_map('chr', $bits));
    }
}
