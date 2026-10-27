<?php
/**
 * LMS completion + optional per-learner lesson progress.
 *
 * Per-learner data is allowed ONLY on LMS lesson post types and ONLY when the
 * embed's trackViewer toggle is on; everywhere else the plugin stays
 * aggregate-only.
 *
 * The server is authoritative: completeAt / trackViewer are re-read from the
 * lesson's own block markup (never trusted from the client), and a beat whose
 * post/video pair has no usage row is dropped. Learners are identified by
 * hash_hmac('sha256', user_id, wp_salt()) — never a name, never reversible
 * without the site's salt. Lesson credit is automatic: on completion the plugin
 * calls the detected LMS's own completion function — LearnDash, TutorLMS,
 * LifterLMS, LearnPress — every call guarded; the `fastpix_video_completed`
 * hook fires first for custom rules, and `fastpix_lms_auto_complete` (default
 * true) turns the automatic marking off. See docs/lms-completion.md.
 *
 * The implementation is split by concern (size only, behaviour unchanged):
 * beats/completion in Fastpix_Lms_Beats, course analytics in
 * Fastpix_Lms_Courses, retention/privacy in Fastpix_Lms_Privacy.
 */

namespace Fastpix;

if (!defined('WPINC')) {
    die;
}

require_once __DIR__ . '/class-fastpix-lms-beats.php';
require_once __DIR__ . '/class-fastpix-lms-courses.php';
require_once __DIR__ . '/class-fastpix-lms-privacy.php';

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery
// All SQL here runs against the plugin's own tables: identifiers come from the
// Fastpix_Schema::table() registry (a fixed whitelist, never request input) and
// every value travels through $wpdb->prepare(). Core APIs cannot query these tables.

class Fastpix_Lms {

    /**
     * Course features are OFF until the owner turns them on in Settings.
     * Detecting an LMS is not consent to change the editor, the front end or
     * Analytics — nothing LMS-shaped appears anywhere until this is enabled.
     */
    const OPT_ENABLED = 'fastpix_lms_enabled';

    /** Per-learner rows retention, days (Settings → Video setup). */
    const OPT_RETENTION = 'fastpix_lesson_retention_days';
    const RETENTION_DEFAULT = 90;

    /** 100 coverage slots, packed into 13 bytes. */
    const SLOTS      = 100;
    const SLOT_BYTES = 13;

    /** LMS plugins keep their curriculum in their own tables — probed before use. */
    const SQL_SHOW_TABLES = 'SHOW TABLES LIKE %s';

    /** Master switch: Settings → Video setup → Course features. */
    public static function enabled() {
        return (bool) apply_filters('fastpix_lms_enabled', (bool) get_option(self::OPT_ENABLED, false));
    }

    /**
     * Course plugins present on the site — the switch is only offered then.
     * Checks the active-plugin list as well as runtime symbols: some LMSes
     * (Tutor) do not define their helpers this early in every request.
     */
    public static function detected() {
        $active = (array) get_option('active_plugins', array());
        $has    = function ($needle) use ($active) {
            foreach ($active as $plugin) {
                if (strpos($plugin, $needle) === 0) {
                    return true;
                }
            }

            return false;
        };

        $found = array();
        if (defined('LEARNDASH_VERSION') || function_exists('learndash_process_mark_complete') || $has('sfwd-lms/')) { $found[] = 'LearnDash'; }
        if (function_exists('tutor') || $has('tutor/')) { $found[] = 'TutorLMS'; }
        if (function_exists('llms_mark_complete') || defined('LLMS_VERSION') || $has('lifterlms/')) { $found[] = 'LifterLMS'; }
        if (function_exists('learn_press_get_user') || defined('LP_PLUGIN_FILE') || $has('learnpress/')) { $found[] = 'LearnPress'; }

        return $found;
    }

    public static function boot() {
        // Retention pruning and the privacy tools always run — rows may exist
        // from a period when the feature was on.
        add_action('fastpix_prune', array(Fastpix_Lms_Privacy::class, 'prune'));
        add_filter('wp_privacy_personal_data_exporters', array(Fastpix_Lms_Privacy::class, 'register_exporter'));
        add_filter('wp_privacy_personal_data_erasers', array(Fastpix_Lms_Privacy::class, 'register_eraser'));
        // Drop a deleted user's lesson-progress at once, rather than waiting out the
        // retention window (their hashed id is otherwise un-erasable once the account,
        // and thus the email lookup, is gone).
        add_action('deleted_user', array(Fastpix_Lms_Privacy::class, 'purge_deleted_user'));

        if (!self::enabled()) {
            return;   // nothing LMS-shaped exists until the owner opts in
        }

        add_action('rest_api_init', array(Fastpix_Lms_Courses::class, 'register_routes'));
    }

    /** Delegating stub — the self-checks register the routes through this class. */
    public static function register_routes() {
        Fastpix_Lms_Courses::register_routes();
    }

    /* --------------------------------------------- skip-proof button gating */

    /**
     * The LMS complete-button selectors hidden until the video is watched.
     * Verified for LearnPress (`.button-complete-lesson`) 2026-09-01; the other
     * three are the documented defaults and are filterable per site.
     */
    public static function hide_selectors() {
        return (array) apply_filters('fastpix_antiskip_hide_selectors', array(
            '.button-complete-lesson',                        // LearnPress
            'form[name="learn-press-form-complete-lesson"]',  // LearnPress (the whole form)
            '.learndash_mark_complete_button',                // LearnDash
            '.llms-complete-lesson-button',                   // LifterLMS
            'button[name="mark_complete"]',                   // LifterLMS
            '.tutor-course-complete-btn',                     // TutorLMS
            '.tutor-course-complete-button',                  // TutorLMS
        ));
    }

    /**
     * The <style> that hides the LMS's own complete button until player.js adds
     * `body.fastpix-antiskip-done` (on completion, or when watching can't be
     * measured). Emitted inline by the renderer next to a lesson embed — before
     * the LMS prints its button, so there is no click-to-skip flash. Returns ''
     * after the first call per request. `null` selector list disables gating.
     */
    public static function antiskip_style_tag() {
        if (self::$antiskip_emitted) {
            return '';
        }
        $css = self::antiskip_css();
        if ($css === '') {
            return '';
        }
        self::$antiskip_emitted = true;

        return '<style id="fastpix-antiskip">' . $css . '</style>';
    }
    private static $antiskip_emitted = false;

    /** The gate CSS body (no <style> wrapper, no one-shot guard) — testable. */
    public static function antiskip_css() {
        $selectors = self::hide_selectors();
        if (empty($selectors)) {
            return '';
        }
        $rules = array();
        foreach ($selectors as $sel) {
            $rules[] = 'body:not(.fastpix-antiskip-done) ' . $sel;
        }
        // Selectors are code (constants + a developer filter), not user input;
        // strip only `<` so a filter value can never close the <style> element.
        return str_replace('<', '', implode(',', $rules)) . '{display:none !important}';
    }

    /* ------------------------------------------------------ lesson detection */

    /** Known LMS lesson post types; extendable, no user-facing toggle. */
    public static function lesson_types() {
        return (array) apply_filters('fastpix_lesson_post_types', array(
            'sfwd-lessons', 'sfwd-topic',   // LearnDash
            'lesson', 'courses',            // TutorLMS
            'llms_lesson',                  // LifterLMS
            'lp_lesson',                    // LearnPress
        ));
    }

    public static function is_lesson($post_type) {
        return self::enabled() && in_array((string) $post_type, self::lesson_types(), true);
    }

    /**
     * The lesson post id currently being RENDERED. `get_the_ID()` is unreliable
     * inside an LMS lesson view — LearnPress renders the lesson's content while
     * the global post is still the COURSE, so `get_the_ID()` returns the course
     * and the embed is never recognised as a lesson (no data-fp-post → no beats
     * → no completion). Fall back to the LMS's own "current item", filterable
     * for other LMSs. Returns 0 when not rendering a lesson.
     */
    public static function rendering_lesson_id() {
        if (is_admin() || !self::enabled()) {
            return 0;
        }
        $id = (int) get_the_ID();
        if (!$id || !self::is_lesson(get_post_type($id))) {
            // Not a block / classic lesson where the loop IS the lesson.
            $id = self::learnpress_rendering_id();
        }
        if (!$id) {
            $filtered = (int) apply_filters('fastpix_rendering_lesson_id', 0);
            $id = ($filtered && self::is_lesson(get_post_type($filtered))) ? $filtered : 0;
        }

        return $id;
    }

    /** LearnPress: the current course item is the lesson being viewed. */
    private static function learnpress_rendering_id() {
        if (class_exists('LP_Global') && is_callable(array('LP_Global', 'course_item'))) {
            $item = \LP_Global::course_item();
            if (is_object($item) && is_callable(array($item, 'get_id'))) {
                $lid = (int) $item->get_id();
                if ($lid && self::is_lesson(get_post_type($lid))) {
                    return $lid;
                }
            }
        }

        return 0;
    }

    /** The stable pseudonymous learner key for a logged-in user. */
    public static function viewer_hash($user_id) {
        return hash_hmac('sha256', (string) (int) $user_id, wp_salt());
    }

    public static function retention_days() {
        $days = (int) get_option(self::OPT_RETENTION, self::RETENTION_DEFAULT);

        return $days >= 1 ? $days : self::RETENTION_DEFAULT;
    }

    /** How many of the 100 slots were played at least once. */
    public static function count_played($counts) {
        $n = 0;
        foreach (str_split((string) $counts) as $byte) {
            if (ord($byte) > 0) {
                $n++;
            }
        }

        return $n;
    }

    /** The course a lesson belongs to (0 = ungrouped), per the active LMS. */
    public static function course_of($lesson_id) {
        $course = 0;
        $type   = get_post_type($lesson_id);

        if (in_array($type, array('sfwd-lessons', 'sfwd-topic'), true) && function_exists('learndash_get_course_id')) {
            $course = (int) learndash_get_course_id($lesson_id);
        } elseif (in_array($type, array('lesson', 'courses'), true) && function_exists('tutor_utils') && is_callable(array(tutor_utils(), 'get_course_id_by'))) {
            $course = (int) tutor_utils()->get_course_id_by('lesson', $lesson_id);   // Tutor's own types only — never ahead of LifterLMS/LearnPress
        } elseif ($type === 'lp_lesson' && function_exists('learn_press_get_item_course_id')) {
            $course = (int) learn_press_get_item_course_id($lesson_id, 'lp_lesson');
        } elseif ($type === 'llms_lesson' && function_exists('llms_get_post')) {
            $lesson = llms_get_post($lesson_id);
            $course = $lesson && is_callable(array($lesson, 'get')) ? (int) $lesson->get('parent_course') : 0;
        }

        // LearnPress fallback: its own section tables name the course.
        if (!$course && $type === 'lp_lesson') {
            global $wpdb;
            $items = $wpdb->prefix . 'learnpress_section_items';
            $secs  = $wpdb->prefix . 'learnpress_sections';
            if ($wpdb->get_var($wpdb->prepare(self::SQL_SHOW_TABLES, $items))) {
                $course = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT s.section_course_id FROM {$items} si JOIN {$secs} s ON s.section_id = si.section_id WHERE si.item_id = %d LIMIT 1",
                    $lesson_id
                ));
            }
        }

        return (int) apply_filters('fastpix_course_of_post', $course, $lesson_id);
    }

    /* ----------------------------------------------- delegates (stable API) */
    // Kept here so callers and tests keep one entry point; the implementations
    // live in the companion classes.

    /** @see Fastpix_Lms_Beats::embed_config() */
    public static function embed_config($post_id, $media_id) {
        return Fastpix_Lms_Beats::embed_config($post_id, $media_id);
    }

    /** @see Fastpix_Lms_Beats::handle_beat() */
    public static function handle_beat($video, $viewer, $position, $request) {
        return Fastpix_Lms_Beats::handle_beat($video, $viewer, $position, $request);
    }

    /** @see Fastpix_Lms_Privacy::prune() */
    public static function prune() {
        Fastpix_Lms_Privacy::prune();
    }

    /** @see Fastpix_Lms_Privacy::export_personal_data() */
    public static function export_personal_data($email) {
        return Fastpix_Lms_Privacy::export_personal_data($email);
    }

    /** @see Fastpix_Lms_Privacy::erase_personal_data() */
    public static function erase_personal_data($email) {
        return Fastpix_Lms_Privacy::erase_personal_data($email);
    }
}
