<?php
/**
 * Course-wise analytics + course structure lookups for Fastpix_Lms.
 *
 * Split out of class-fastpix-lms.php for size only; the aggregate-only /
 * hashed-identity rules (ASSUME-046) and route registrations are unchanged.
 */

namespace Fastpix;

if (!defined('WPINC')) {
    die;
}

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery
// All SQL here runs against the plugin's own tables: identifiers come from the
// Fastpix_Schema::table() registry (a fixed whitelist, never request input) and
// every value travels through $wpdb->prepare(). Core APIs cannot query these tables.

class Fastpix_Lms_Courses {

    /* ------------------------------------------------------- admin surface */

    public static function register_routes() {
        // Course-wise only: a list of courses to choose from, then the students
        // enrolled in THAT course. There is no all-courses roll-up and no
        // all-students table.
        Fastpix_Rest::register('/analytics/courses', array(
            'methods'    => 'GET',
            'capability' => Fastpix_Capabilities::VIEW_ANALYTICS,
            'callback'   => array(__CLASS__, 'course_list'),
        ));

        Fastpix_Rest::register('/analytics/courses/(?P<id>\d+)', array(
            'methods'    => 'GET',
            'capability' => Fastpix_Capabilities::VIEW_ANALYTICS,
            'callback'   => array(__CLASS__, 'course_detail'),
        ));
    }

    /** GET /analytics/courses — just the pickable courses, no figures. */
    public static function course_list() {
        global $wpdb;

        $seen = array();
        foreach ($wpdb->get_col('SELECT DISTINCT post_id FROM ' . Fastpix_Schema::table('usage') . self::usage_scope_sql()) as $post_id) {
            $post_id = (int) $post_id;
            if (!Fastpix_Lms::is_lesson(get_post_type($post_id)) || get_post_status($post_id) !== 'publish') {
                continue;
            }
            $course_id = Fastpix_Lms::course_of($post_id);
            if ($course_id && !isset($seen[$course_id])) {
                $seen[$course_id] = array(
                    'id'    => $course_id,
                    'title' => get_the_title($course_id),
                );
            }
        }

        return rest_ensure_response(array('courses' => array_values($seen)));
    }

    /**
     * GET /analytics/courses/{id} — ONE course: its video lessons and the
     * students enrolled in it, with each student's progress. Course-wise only —
     * there is no site-wide roll-up and no all-students table.
     */
    public static function course_detail($request) {
        $course_id = (int) $request->get_param('id');
        if (!$course_id || !get_post($course_id)) {
            return new \WP_Error('fastpix_course_missing', __('No such course.', 'fastpix-io'), array('status' => 404));
        }

        $lessons  = self::course_lessons($course_id);
        if (!$lessons && self::usage_scope_sql() !== '') {
            // Author-scoped and none of this course's lessons are theirs: no roster, no enrolled count. (QA X20)
            return new \WP_Error('fastpix_course_missing', __('No such course.', 'fastpix-io'), array('status' => 404));
        }
        $enrolled = self::enrolled_map($course_id);

        // Batch every learner's progress in TWO queries instead of a get_row per
        // student×lesson×media (O(students×lessons×media) — thousands of queries
        // on a large course). The per-student loops are pure in-memory lookups.
        list($prog, $last_by_hash) = self::load_progress($enrolled, $lessons);

        $students  = self::student_rows($enrolled, $lessons, $prog, $last_by_hash);
        $breakdown = self::lesson_breakdown($lessons, $students);

        $totals = array(
            'lessons'    => count(array_filter($lessons, function ($l) { return empty($l['other_workspace']); })),   // lessons the connected workspace can reach
            'enrolled'   => count($students),
            'complete'   => count(array_filter($students, function ($s) { return $s['state'] === 'complete'; })),
            'notstarted' => count(array_filter($students, function ($s) { return $s['state'] === 'notstarted'; })),
            'avg'        => $students ? (int) round(array_sum(array_column($students, 'progress')) / count($students)) : 0,
        );

        return rest_ensure_response(array(
            'course'    => array('id' => $course_id, 'title' => get_the_title($course_id)),
            'lessons'   => $lessons,
            'breakdown' => $breakdown,
            'students'  => $students,
            'totals'    => $totals,
            'tracking'  => self::course_tracks($lessons),
        ));
    }

    /** The course's lessons that carry a FastPix video, in course order. */
    private static function course_lessons($course_id) {
        global $wpdb;

        $lessons = array();
        foreach ($wpdb->get_results('SELECT DISTINCT post_id, video_id FROM ' . Fastpix_Schema::table('usage') . self::usage_scope_sql(), ARRAY_A) as $row) {
            $lessons = self::add_lesson($lessons, $row, $course_id);
        }
        uasort($lessons, function ($a, $b) {
            return $a['order'] <=> $b['order'];
        });

        return array_values(array_map(function ($l) {
            unset($l['order']);
            $config = isset($l['media'][0]) ? Fastpix_Lms::embed_config((int) $l['id'], $l['media'][0]) : null;
            $l['complete_at'] = $config ? (int) $config['completeAt'] : 90;
            return $l;
        }, $lessons));
    }

    /**
     * The same per-author scope the site/video reads apply (UI-005 restricted):
     * an author without the any-video capability sees only lessons that embed
     * THEIR videos — the courses list, the lessons and so the students follow.
     */
    private static function usage_scope_sql() {
        $scope = Fastpix_Analytics::scope_video_ids();
        if ($scope === null) {
            return '';
        }

        return $scope ? ' WHERE video_id IN (' . implode(',', array_map('intval', $scope)) . ')' : ' WHERE 1=0';
    }

    /** Fold one usage row into the lessons map (skipping non-course rows). */
    private static function add_lesson($lessons, $row, $course_id) {
        global $wpdb;

        $post_id = (int) $row['post_id'];
        if (!Fastpix_Lms::is_lesson(get_post_type($post_id)) || get_post_status($post_id) !== 'publish') {
            return $lessons;
        }
        if (Fastpix_Lms::course_of($post_id) !== $course_id) {
            return $lessons;
        }
        if (!isset($lessons[$post_id])) {
            $post = get_post($post_id);
            $lessons[$post_id] = array(
                'id'       => $post_id,
                'title'    => $post ? $post->post_title : '',
                'order'    => $post ? array((int) $post->menu_order, strtotime($post->post_date)) : array(0, 0),
                'media'    => array(),
                'duration' => 0,
                // True until one of its videos belongs to the connected workspace: such a
                // lesson keeps its records but is labelled and left out of the totals. [ASSUME-095]
                'other_workspace' => true,
            );
        }
        $vrow = $wpdb->get_row($wpdb->prepare('SELECT media_id, duration_seconds, workspace_id FROM ' . Fastpix_Schema::table('videos') . ' WHERE id = %d', (int) $row['video_id']), ARRAY_A);
        if ($vrow) {
            $lessons[$post_id]['media'][] = (string) $vrow['media_id'];
            $lessons[$post_id]['duration'] = max((float) ($lessons[$post_id]['duration'] ?? 0), (float) $vrow['duration_seconds']);
            if (!Fastpix_Videos_Rest::is_other_workspace($vrow)) {
                $lessons[$post_id]['other_workspace'] = false;
            }
        }

        return $lessons;
    }

    /**
     * Enrolled students, per the LMS's own record, keyed by their hashed
     * identity. Their names are known because we go user id → hash, never
     * the reverse.
     */
    private static function enrolled_map($course_id) {
        $enrolled = array();   // hash => WP_User
        foreach (self::enrolled_students($course_id) as $user_id) {
            $u = get_userdata($user_id);
            if ($u) { $enrolled[Fastpix_Lms::viewer_hash($user_id)] = $u; }
        }

        return $enrolled;
    }

    /**
     * All lesson-progress rows for these learners in two queries:
     * hash => post_id => media_id => row, plus each learner's MAX(updated_at)
     * across ALL their rows (any course), as before.
     */
    private static function load_progress($enrolled, $lessons) {
        global $wpdb;

        $table      = Fastpix_Schema::table('lesson_progress');
        $lesson_ids = array_map(function ($l) { return (int) $l['id']; }, $lessons);
        $prog         = array();
        $last_by_hash = array();
        if ($enrolled && $lesson_ids) {
            $hash_in = "'" . implode("','", array_map('esc_sql', array_keys($enrolled))) . "'";
            $post_in = implode(',', array_map('intval', $lesson_ids));
            foreach ($wpdb->get_results(
                "SELECT viewer_key, post_id, media_id, slots, plays, completed_at
                 FROM {$table} WHERE viewer_key IN ({$hash_in}) AND post_id IN ({$post_in})",
                ARRAY_A
            ) as $r) {
                $prog[$r['viewer_key']][(int) $r['post_id']][(string) $r['media_id']] = $r;
            }
            foreach ($wpdb->get_results(
                "SELECT viewer_key, MAX(updated_at) last_at FROM {$table} WHERE viewer_key IN ({$hash_in}) GROUP BY viewer_key",
                ARRAY_A
            ) as $r) {
                $last_by_hash[$r['viewer_key']] = $r['last_at'];
            }
        }

        return array($prog, $last_by_hash);
    }

    private static function student_rows($enrolled, $lessons, $prog, $last_by_hash) {
        // Stored + displayed data stays hashed. Full administrators
        // (manage_options) MAY resolve the hash
        // to the WordPress display name for a hover tooltip only — never lower
        // analytics roles (editor/author), and never an email. A filter can turn
        // even the admin reveal off for stricter sites.
        $reveal = (bool) apply_filters('fastpix_reveal_learner_names', current_user_can('manage_options'));

        $students = array();
        foreach ($enrolled as $hash => $user) {
            $students[] = self::student_row($hash, $user, $lessons, $prog, $last_by_hash, $reveal);
        }

        usort($students, function ($a, $b) {
            return $a['progress'] <=> $b['progress'];   // least progress first — who needs help
        });

        return $students;
    }

    private static function student_row($hash, $user, $lessons, $prog, $last_by_hash, $reveal) {
        $done = 0;
        $active = 0;   // lessons whose video the connected workspace can reach — the only ones that count
        $started = 0;
        $watched = array();
        foreach ($lessons as $lesson) {
            $w = self::lesson_watch($lesson, $hash, $prog);
            $w['other_workspace'] = !empty($lesson['other_workspace']);
            if (!$w['other_workspace']) {
                $active++;
                $done    += (int) (bool) $w['completed'];
                $started += (int) ($w['watched_pct'] > 0);
            }
            $watched[] = $w;
        }

        $last  = isset($last_by_hash[$hash]) ? $last_by_hash[$hash] : '';
        $state = 'notstarted';
        if ($done && $done === $active) {
            $state = 'complete';
        } elseif ($started) {
            $state = 'progress';
        }

        return array(
            // The row is keyed by the hashed identity; `name` is added only
            // for a full administrator (see $reveal above) and only ever the
            // display name — never an email. Absent for everyone else.
            'viewer'      => substr($hash, 0, 12),
            'avatar'      => strtoupper(substr($hash, 0, 2)),
            'name'        => $reveal ? $user->display_name : null,
            'lessons'     => $watched,
            'completed'   => $done,
            'started'     => $started,
            'progress'    => $active ? (int) round(100 * $done / $active) : 0,
            'last_active' => $last ? (string) $last : '',
            'state'       => $state,
        );
    }

    /** One learner's best coverage across a lesson's media. */
    private static function lesson_watch($lesson, $hash, $prog) {
        $best = 0;
        $best_plays = '';
        $complete = false;
        foreach ($lesson['media'] as $media_id) {
            $row = isset($prog[$hash][(int) $lesson['id']][$media_id]) ? $prog[$hash][(int) $lesson['id']][$media_id] : null;
            if (!$row) {
                continue;
            }
            $plays = self::row_plays($row);
            $pct = $plays !== null ? Fastpix_Lms::count_played($plays) : 0;
            if ($pct >= $best) {
                $best = $pct;
                $best_plays = $plays !== null ? bin2hex($plays) : '';
            }
            $complete = $complete || $row['completed_at'] !== null;
        }

        return array('lesson' => (int) $lesson['id'], 'watched_pct' => $best, 'completed' => $complete, 'plays' => $best_plays);
    }

    /**
     * Prefer play counts; a legacy row's yes/no bitmap is upgraded to counts
     * of 1 so the strip still renders (watched/skipped, no rewatch shading).
     */
    private static function row_plays($row) {
        $plays = !empty($row['plays']) ? (string) $row['plays'] : null;
        if ($plays === null && !empty($row['slots'])) {
            // Legacy 13-byte bitmap → 100 one-byte counts of 1.
            $bits  = (string) $row['slots'];
            $plays = '';
            for ($slot = 0; $slot < 100; $slot++) {
                $plays .= (ord($bits[$slot >> 3] ?? "\0") & (1 << ($slot % 8))) ? "\1" : "\0";
            }
        }

        return $plays;
    }

    /**
     * Per-lesson aggregate: the drop-off curve is the insight a course
     * owner actually acts on — which lesson loses people.
     */
    private static function lesson_breakdown($lessons, $students) {
        global $wpdb;

        $breakdown = array();
        foreach ($lessons as $i => $lesson) {
            list($started, $completed) = self::lesson_counts($i, $students);
            // Attach the local video row id so a lesson can open its coverage chart.
            $video_id = 0;
            foreach ($lesson['media'] as $media_id) {
                $vid = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . Fastpix_Schema::table('videos') . ' WHERE media_id = %s', $media_id));
                if ($vid) { $video_id = $vid; break; }
            }
            $breakdown[] = array(
                'id'        => (int) $lesson['id'],
                'title'     => $lesson['title'],
                'video_id'  => $video_id,
                'started'   => $started,
                'completed' => $completed,
                'other_workspace' => !empty($lesson['other_workspace']),
            );
        }

        return $breakdown;
    }

    private static function lesson_counts($i, $students) {
        $completed = 0;
        $started   = 0;
        foreach ($students as $st) {
            if (!isset($st['lessons'][$i])) { continue; }
            if ($st['lessons'][$i]['completed']) { $completed++; }
            if ($st['lessons'][$i]['watched_pct'] > 0) { $started++; }
        }

        return array($started, $completed);
    }

    /** Does any lesson in this course record per-student data? */
    private static function course_tracks($lessons) {
        foreach ($lessons as $lesson) {
            foreach ($lesson['media'] as $media_id) {
                $config = Fastpix_Lms::embed_config((int) $lesson['id'], $media_id);
                if ($config && $config['trackViewer']) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Students enrolled in a course, per the active LMS's own record. */
    public static function enrolled_students($course_id) {
        global $wpdb;

        $ids = array();

        if (function_exists('learndash_get_users_for_course')) {
            $q = learndash_get_users_for_course($course_id, array(), false);
            $ids = is_a($q, 'WP_User_Query') ? $q->get_results() : (array) $q;
        } elseif (function_exists('llms_get_enrolled_students')) {
            $ids = (array) llms_get_enrolled_students($course_id, 'enrolled', 500, 0);
        } elseif (function_exists('tutor')) {
            // Tutor records an enrolment as a post whose parent is the course.
            $ids = $wpdb->get_col($wpdb->prepare(
                "SELECT post_author FROM {$wpdb->posts} WHERE post_type = 'tutor_enrolled' AND post_parent = %d AND post_status = 'completed'",
                $course_id
            ));
        }

        if (!$ids) {
            $items = $wpdb->prefix . 'learnpress_user_items';
            if ($wpdb->get_var($wpdb->prepare(Fastpix_Lms::SQL_SHOW_TABLES, $items))) {
                $ids = $wpdb->get_col($wpdb->prepare(
                    "SELECT DISTINCT user_id FROM {$items} WHERE item_id = %d AND item_type = 'lp_course'",
                    $course_id
                ));
            }
        }

        $ids = array_values(array_unique(array_map('intval', (array) $ids)));

        return (array) apply_filters('fastpix_course_students', $ids, $course_id);
    }

    /* -------------------------------------------------------- next lesson */

    /**
     * The lesson that follows this one in its course, as a URL — so a learner
     * who finishes a video moves straight on. Each LMS's own ordering is used,
     * with a post_parent/menu_order fallback; `fastpix_next_lesson_url` overrides.
     */
    public static function next_lesson_url($post_id) {
        $post_id = (int) $post_id;
        $type    = get_post_type($post_id);
        $next    = 0;

        // LearnPress: the curriculum lives in its own section tables.
        if ($type === 'lp_lesson') {
            $next = self::next_learnpress($post_id);
        }

        if (!$next && $type === 'llms_lesson' && function_exists('llms_get_post')) {
            $lesson = llms_get_post($post_id);
            if ($lesson && is_callable(array($lesson, 'get_next_lesson'))) {
                $next = (int) $lesson->get_next_lesson();
            }
        }

        if (!$next) {
            $next = self::next_learndash($post_id);
        }

        // Fallback (Tutor 3 and anything else): siblings under the same parent
        // in menu order — how those builders store lesson sequence.
        if (!$next) {
            $next = self::next_sibling($post_id, $type);
        }

        $url = $next ? (string) get_permalink($next) : '';

        return (string) apply_filters('fastpix_next_lesson_url', $url, $post_id, $next);
    }

    private static function next_learnpress($post_id) {
        global $wpdb;

        $items = $wpdb->prefix . 'learnpress_section_items';
        $secs  = $wpdb->prefix . 'learnpress_sections';
        if (!$wpdb->get_var($wpdb->prepare(Fastpix_Lms::SQL_SHOW_TABLES, $items))) {
            return 0;
        }

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT si.item_id FROM {$items} si
             JOIN {$secs} s ON s.section_id = si.section_id
             WHERE s.section_course_id = (
                SELECT s2.section_course_id FROM {$items} si2
                JOIN {$secs} s2 ON s2.section_id = si2.section_id WHERE si2.item_id = %d LIMIT 1
             )
             AND (si.section_id, si.item_order) > (
                SELECT si3.section_id, si3.item_order FROM {$items} si3 WHERE si3.item_id = %d LIMIT 1
             )
             ORDER BY si.section_id, si.item_order LIMIT 1",
            $post_id, $post_id
        ));
    }

    private static function next_learndash($post_id) {
        if (!function_exists('learndash_get_course_steps') || !function_exists('learndash_get_course_id')) {
            return 0;
        }
        $steps = (array) learndash_get_course_steps((int) learndash_get_course_id($post_id));
        $at    = array_search($post_id, array_map('intval', $steps), true);

        return ($at !== false && isset($steps[$at + 1])) ? (int) $steps[$at + 1] : 0;
    }

    private static function next_sibling($post_id, $type) {
        $parent = (int) wp_get_post_parent_id($post_id);
        if (!$parent) {
            return 0;
        }
        $siblings = get_posts(array(
            'post_type' => $type, 'post_parent' => $parent, 'post_status' => 'publish',
            'numberposts' => -1, 'orderby' => array('menu_order' => 'ASC', 'ID' => 'ASC'), 'fields' => 'ids',
        ));
        $at = array_search($post_id, array_map('intval', $siblings), true);

        return ($at !== false && isset($siblings[$at + 1])) ? (int) $siblings[$at + 1] : 0;
    }
}
