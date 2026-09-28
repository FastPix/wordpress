<?php
/**
 * Watch progress, resume, coverage, completion — WF-013, FR-061,
 * REQ-064/065, RULE-031/032, SEC-014/015/016, INT-009.
 *
 * This class stays aggregate-only and speaks of viewers. Lesson-scoped,
 * opt-in per-learner handling lives in class-fastpix-lms.php and rides this
 * route's guard set via handle_beat(); `fastpix_video_completed`
 * (media_id, post_id, viewer_key|null) is its LMS-facing hook, while this
 * class's own site-threshold event is `fastpix_watch_completed`.
 *
 * The player posts to POST /progress. Consent is enforced where the consent
 * signal lives — in the browser (player.js): without analytics consent no
 * viewer reference is ever created, so nothing reaches this route (RULE-031).
 * Server-side, every write passes the RULE-032 guard set before it touches
 * fastpix_watch_progress.
 *
 * Coverage semantics (binding, DATA-012): the client counts distinct whole
 * seconds and the server is strictly monotonic — covered_seconds and
 * furthest_seconds only ever grow, capped at the duration. Rewatch cannot
 * inflate (client dedupes, server caps); backward seek cannot reduce (max).
 * ponytail: a hostile client can claim seconds it skipped — the schema stores
 * counts, not ranges (DATA-012), so the server cannot re-derive the set. The
 * cap at duration and the rate guards bound the damage.
 */

namespace Fastpix;

if (!defined('WPINC')) {
    die;
}

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery
// All SQL here runs against the plugin's own tables: identifiers come from the
// Fastpix_Schema::table() registry (a fixed whitelist, never request input) and
// every value travels through $wpdb->prepare(). Core APIs cannot query these tables.

class Fastpix_Progress {

    /** Completion threshold (REQ-065): site option, default 0.9, filterable. */
    const OPT_THRESHOLD = 'fastpix_completion_threshold';

    /** RULE-032: request bodies over 1 KB are refused. */
    const MAX_BODY_BYTES = 1024;

    public static function boot() {
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));

        // SEC-016 — privacy disclosure, exporter, eraser.
        add_action('admin_init', array(__CLASS__, 'privacy_policy_content'));
        add_filter('wp_privacy_personal_data_exporters', array(__CLASS__, 'register_exporter'));
        add_filter('wp_privacy_personal_data_erasers', array(__CLASS__, 'register_eraser'));
        // Deleting a WP user should drop their watch-progress immediately, not wait
        // out the 12-month retention. The email-based eraser only runs on request.
        add_action('deleted_user', array(__CLASS__, 'purge_deleted_user'));
    }

    /** Drop a deleted user's watch-progress rows (keyed user:{ID}). */
    public static function purge_deleted_user($user_id) {
        global $wpdb;
        $wpdb->delete(Fastpix_Schema::table('watch_progress'), array('viewer_key' => 'user:' . (int) $user_id));
    }

    public static function threshold() {
        $stored = (float) get_option(self::OPT_THRESHOLD, 0.9);

        $t = (float) apply_filters('fastpix_completion_threshold', $stored > 0 && $stored <= 1 ? $stored : 0.9);

        return ($t > 0 && $t <= 1) ? $t : 0.9;
    }

    /* -------------------------------------------------------------- routes */

    public static function register_routes() {
        // The video param is deliberately named `video`, not `video_id`: the
        // shared public callback rate-limits per identifier, and the identifier
        // here must be the viewer key (per-viewer cadence lives in the handler)
        // — never the video, or sixty viewers a minute would exhaust a popular
        // video's bucket site-wide. [SEC-012, RULE-032]
        Fastpix_Rest::register('/progress', array(
            'methods'             => 'POST',
            'public_bucket'       => 'progress_address',
            'callback'            => array(__CLASS__, 'write'),
            'args'                => array(
                'video'      => Fastpix_Rest::arg('integer', array('required' => true, 'minimum' => 1)),
                'position'   => Fastpix_Rest::arg('number', array('required' => true, 'minimum' => 0)),
                'covered'    => Fastpix_Rest::arg('integer', array('required' => true, 'minimum' => 0)),
                'viewer_key' => Fastpix_Rest::arg('string', array('pattern' => '^[A-Za-z0-9_-]{8,48}$')),
                // Lesson beat extras: the lesson post, the 100-slot
                // coverage bitmap (hex), and the hashed learner key. Optional —
                // present only on lesson embeds; validated server-side against
                // the lesson's own block markup.
                'post'       => Fastpix_Rest::arg('integer', array('minimum' => 1)),
                'slots'      => Fastpix_Rest::arg('string', array('pattern' => '^[a-f0-9]{1,26}$')),      // legacy bitmap (cached pages)
                'plays'      => Fastpix_Rest::arg('string', array('pattern' => '^[a-f0-9]{1,200}$')),     // per-slot play counts
                'learner'    => Fastpix_Rest::arg('string', array('pattern' => '^[a-f0-9]{64}$')),
                // The last beat before the tab is hidden or the page left. It carries the
                // position resume depends on, so it is not subject to the 15 s per-viewer
                // cadence (still one per address-minute). [QA F7, 2026-09-20]
                'final'      => Fastpix_Rest::arg('boolean'),
            ),
        ));

        // Resume read (REQ-064): the viewer's own row, keyed by the reference
        // only that viewer holds (or their login). Seek-on-load — player.js
        // sets start-time from furthest_seconds.
        Fastpix_Rest::register('/progress/(?P<video>\d+)', array(
            'methods'             => 'GET',
            'public_bucket'       => 'progress_address',
            'callback'            => array(__CLASS__, 'read'),
            'args'                => array(
                'viewer_key' => Fastpix_Rest::arg('string', array('pattern' => '^[A-Za-z0-9_-]{8,48}$')),
            ),
        ));
    }

    /**
     * The viewer's key: logged-in viewers are keyed by user id (SEC-014 —
     * their request carries the REST nonce, so WordPress authenticated them);
     * visitors send the pseudonymous reference player.js keeps in first-party
     * local storage.
     */
    private static function viewer_key($request) {
        if (get_current_user_id() > 0) {
            return 'user:' . get_current_user_id();
        }

        $key = (string) $request->get_param('viewer_key');

        return $key === '' ? null : $key;
    }

    /** POST /progress — the full RULE-032 guard set, then a monotonic upsert. */
    public static function write($request) {
        global $wpdb;

        $ctx = self::write_guards($request);
        if (is_wp_error($ctx)) {
            return $ctx;
        }
        $viewer   = $ctx['viewer'];
        $video    = $ctx['video'];
        $video_id = $ctx['video_id'];
        $duration = $ctx['duration'];
        $position = $ctx['position'];

        $furthest = min((int) floor($position), (int) ceil($duration));
        $covered  = min((int) $request->get_param('covered'), (int) ceil($duration));

        $now = current_time('mysql', true);
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . Fastpix_Schema::table('watch_progress') . ' WHERE video_id = %d AND viewer_key = %s',
            $video_id, $viewer
        ), ARRAY_A);

        // Monotonic: rewatch cannot inflate past the duration; backward seek
        // cannot reduce. [REQ-064]
        $furthest = max($furthest, $row ? (int) $row['furthest_seconds'] : 0);
        $covered  = max($covered, $row ? (int) $row['covered_seconds'] : 0);
        $ratio    = round(min(1, $covered / max(1, $duration)), 4);

        $values = array(
            'furthest_seconds' => $furthest,
            'covered_seconds'  => $covered,
            'coverage_ratio'   => $ratio,
            'last_seen_at'     => $now,
            'updated_at'       => $now,
        );

        // Completion fires ONCE, at the first crossing; completed_at is
        // immutable — a later rewatch cannot un-complete. [REQ-065]
        $completed = $row && $row['completed_at'] !== null;
        $completes = !$completed && $ratio >= self::threshold();
        if ($completes) {
            $values['completed_at'] = $now;
        }

        if ($row) {
            $wpdb->update(Fastpix_Schema::table('watch_progress'), $values, array('id' => (int) $row['id']));
        } else {
            $wpdb->insert(Fastpix_Schema::table('watch_progress'), $values + array(
                'video_id'   => $video_id,
                'viewer_key' => $viewer,
                'created_at' => $now,
            ));
        }

        if ($completes) {
            self::completed($video, $viewer);
        }

        // Lesson beat: rides this route and its guard set; the LMS class
        // re-validates everything against the lesson's block markup.
        $lesson = null;
        if ((int) $request->get_param('post') > 0) {
            $lesson = Fastpix_Lms::handle_beat($video, $viewer, $position, $request);
        }

        $response = array(
            'furthest_seconds' => $furthest,
            'covered_seconds'  => $covered,
            'coverage_ratio'   => $ratio,
            'completed'        => $completed || $completes,
        );
        if ($lesson !== null) {
            $response['lesson'] = $lesson;   // {completed, next_url} — the player unlocks what follows
        }

        return rest_ensure_response($response);
    }

    /**
     * The RULE-032 guard set for a write, in order: body size, viewer
     * reference, both rate limits, published video, position sanity.
     * Returns the validated context, or the guard's WP_Error.
     */
    private static function write_guards($request) {
        // Guard: body size. Refused before anything is parsed further.
        if (strlen((string) $request->get_body()) > self::MAX_BODY_BYTES) {
            return new \WP_Error('fastpix_payload_too_large', __('Request too large.', 'fastpix'), array('status' => 413));
        }

        $viewer = self::viewer_key($request);
        if ($viewer === null) {
            return new \WP_Error('fastpix_no_viewer', __('No viewer reference.', 'fastpix'), array('status' => 400));
        }

        $video_id = (int) $request->get_param('video');
        $limited  = self::write_rate_limits($viewer, $video_id, (bool) $request->get_param('final'));

        return is_wp_error($limited) ? $limited : self::write_target($request, $viewer, $video_id);
    }

    /** The two write rate limits; null when both pass. */
    private static function write_rate_limits($viewer, $video_id, $final = false) {
        // Guard: one write per viewer per video per 15 s. Excess is dropped
        // with 429, never queued. The final beat of a session is exempt (F7).
        $limited = Fastpix_Rate_Limiter::check($final ? 'progress_final' : 'progress_viewer', $viewer . ':' . $video_id);
        if (is_wp_error($limited)) {
            return $limited;
        }

        // Guard: new viewer keys capped per address per day. A key counts once
        // — repeat writes from a known key spend nothing.
        if (strpos($viewer, 'user:') !== 0) {
            $address = Fastpix_Rate_Limiter::address();
            $seen    = 'seenkey:' . hash('sha256', $address . '|' . $viewer);
            if (!Fastpix_Cache::get('ratelimit', $seen)) {
                $limited = Fastpix_Rate_Limiter::check('progress_viewer_keys', $address);
                if (is_wp_error($limited)) {
                    return $limited;
                }
                Fastpix_Cache::set('ratelimit', $seen, 1, DAY_IN_SECONDS);
            }
        }

        return null;
    }

    /** The published-video and position guards; the validated context when both pass. */
    private static function write_target($request, $viewer, $video_id) {
        // Guard: the video is published on this site.
        $video = self::published_video($video_id);
        if (is_wp_error($video)) {
            return $video;
        }

        // Guard: position within duration (small tolerance for the last beat).
        $duration = (float) $video['duration_seconds'];
        $position = (float) $request->get_param('position');
        if ($duration <= 0 || $position > $duration + 2) {
            return new \WP_Error('fastpix_bad_position', __('Position is outside this video.', 'fastpix'), array('status' => 400));
        }

        return array(
            'viewer'   => $viewer,
            'video'    => $video,
            'video_id' => $video_id,
            'duration' => $duration,
            'position' => $position,
        );
    }

    /** GET /progress/{video} — the resume point for this viewer only. */
    public static function read($request) {
        global $wpdb;

        $viewer = self::viewer_key($request);
        if ($viewer === null) {
            return rest_ensure_response(array('furthest_seconds' => 0, 'completed' => false));
        }

        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT furthest_seconds, covered_seconds, coverage_ratio, completed_at FROM ' . Fastpix_Schema::table('watch_progress')
            . ' WHERE video_id = %d AND viewer_key = %s',
            (int) $request->get_param('video'), $viewer
        ), ARRAY_A);

        return rest_ensure_response(array(
            'furthest_seconds' => $row ? (int) $row['furthest_seconds'] : 0,
            'covered_seconds'  => $row ? (int) $row['covered_seconds'] : 0,
            'completed'        => (bool) ($row && $row['completed_at'] !== null),
        ));
    }

    /**
     * "Published on this site": the video is embedded in a published post
     * (usage rows — renders file one immediately). Library-page
     * previews come from users who may view videos; they pass too.
     */
    private static function published_video($video_id) {
        global $wpdb;

        $video = $wpdb->get_row($wpdb->prepare(
            'SELECT id, media_id, title, duration_seconds FROM ' . Fastpix_Schema::table('videos')
            . ' WHERE id = %d AND deleted_at IS NULL',
            $video_id
        ), ARRAY_A);

        if (!$video) {
            return new \WP_Error('fastpix_video_missing', __('No such video.', 'fastpix'), array('status' => 404));
        }

        if (current_user_can(Fastpix_Capabilities::VIEW_VIDEOS)) {
            return $video;
        }

        $published = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . Fastpix_Schema::table('usage') . " u
             INNER JOIN {$wpdb->posts} p ON p.ID = u.post_id AND p.post_status = 'publish'
             WHERE u.video_id = %d",
            $video_id
        ));

        return $published === 0
            ? new \WP_Error('fastpix_not_published', __('This video is not published here.', 'fastpix'), array('status' => 403))
            : $video;
    }

    /* --------------------------------------------------------- completion */

    /**
     * First site-threshold crossing (the aggregate Finished KPI). Lesson
     * completion is the LMS class's business, not this one's.
     */
    private static function completed($video, $viewer) {
        do_action('fastpix_log', 'watch_completed', array(
            'scope'    => 'progress',
            'severity' => 'info',
            'video_id' => (int) $video['id'],
            'message'  => 'Coverage threshold reached for ' . $video['media_id'],
        ));

        $user_id = (strpos($viewer, 'user:') === 0) ? (int) substr($viewer, 5) : 0;

        // Not fastpix_video_completed — that name belongs to the lesson-completion
        // hook (media_id, post_id, viewer_key|null) in class-fastpix-lms.php.
        do_action('fastpix_watch_completed', (int) $video['id'], $user_id, $viewer);
    }

    /* ------------------------------------------------------------ privacy */

    public static function privacy_policy_content() {
        if (!function_exists('wp_add_privacy_policy_content')) {
            return;
        }

        wp_add_privacy_policy_content(__('FastPix Video', 'fastpix'), wp_kses_post(wpautop(
            __('When a visitor watches a video and has given analytics consent, this site stores a watch-progress record: how far they got and which parts they watched, so playback can resume and completion can be recognised. Visitors are identified by a pseudonymous random reference kept in their own browser (not a cookie); logged-in viewers are identified by their account. Records are kept for 12 months after the last activity, then deleted. Aggregate viewing figures are provided by FastPix; the per-viewer progress records never leave this site. Watch-progress records are included in personal data exports and erased on request.', 'fastpix')
        )));
    }

    public static function register_exporter($exporters) {
        $exporters['fastpix-watch-progress'] = array(
            'exporter_friendly_name' => __('FastPix watch progress', 'fastpix'),
            'callback'               => array(__CLASS__, 'export_personal_data'),
        );

        return $exporters;
    }

    public static function register_eraser($erasers) {
        $erasers['fastpix-watch-progress'] = array(
            'eraser_friendly_name' => __('FastPix watch progress', 'fastpix'),
            'callback'             => array(__CLASS__, 'erase_personal_data'),
        );

        return $erasers;
    }

    /** Rows keyed by this account. Anonymous references cannot be tied to an email — the reference lives only in the visitor's browser. */
    private static function user_rows($email) {
        global $wpdb;

        $user = get_user_by('email', $email);
        if (!$user) {
            return array();
        }

        return $wpdb->get_results($wpdb->prepare(
            'SELECT wp.*, v.title FROM ' . Fastpix_Schema::table('watch_progress') . ' wp
             LEFT JOIN ' . Fastpix_Schema::table('videos') . ' v ON v.id = wp.video_id
             WHERE wp.viewer_key = %s',
            'user:' . $user->ID
        ), ARRAY_A);
    }

    public static function export_personal_data($email) {
        $items = array();

        foreach (self::user_rows($email) as $row) {
            $items[] = array(
                'group_id'    => 'fastpix-watch-progress',
                'group_label' => __('FastPix watch progress', 'fastpix'),
                'item_id'     => 'fastpix-watch-progress-' . $row['id'],
                'data'        => array(
                    array('name' => __('Video', 'fastpix'), 'value' => (string) $row['title']),
                    array('name' => __('Furthest point reached', 'fastpix'), 'value' => $row['furthest_seconds'] . ' s'),
                    array('name' => __('Seconds watched', 'fastpix'), 'value' => (string) $row['covered_seconds']),
                    array('name' => __('Completed', 'fastpix'), 'value' => $row['completed_at'] ? $row['completed_at'] : __('No', 'fastpix')),
                    array('name' => __('Last activity', 'fastpix'), 'value' => (string) $row['last_seen_at']),
                ),
            );
        }

        return array('data' => $items, 'done' => true);
    }

    public static function erase_personal_data($email) {
        global $wpdb;

        $rows    = self::user_rows($email);
        $removed = 0;

        foreach ($rows as $row) {
            $removed += (int) $wpdb->delete(Fastpix_Schema::table('watch_progress'), array('id' => (int) $row['id']));
        }

        return array('items_removed' => $removed > 0, 'items_retained' => false, 'messages' => array(), 'done' => true);
    }
}
