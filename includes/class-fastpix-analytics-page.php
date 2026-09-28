<?php
/**
 * Analytics screen — UI-005, REQ-060…063, FR-060.
 *
 * Two questions in the place the video is managed: was it watched, did it play
 * well. Per-video analytics live here on the "One video" tab (reached from a
 * library row), not on the opened row. The page renders the shell; figures
 * arrive client-side from /analytics/site and /videos/{id}/analytics — which
 * read the local rollup only, never FastPix.
 */

namespace Fastpix;

if (!defined('WPINC')) {
    die;
}

class Fastpix_Analytics_Page {

    const SLUG = 'fastpix-analytics';

    public static function boot() {
        add_action('admin_menu', array(__CLASS__, 'menu'), 31);
        add_action('admin_enqueue_scripts', array(__CLASS__, 'assets'));
    }

    public static function menu() {
        add_submenu_page(
            'fastpix-settings',
            __('Analytics', 'fastpix'),
            __('Analytics', 'fastpix'),
            Fastpix_Capabilities::VIEW_ANALYTICS,
            self::SLUG,
            array(__CLASS__, 'render'),
            3
        );
    }

    public static function assets($hook) {
        if (strpos((string) $hook, self::SLUG) === false) {
            return;
        }

        wp_enqueue_style('fastpix-onboarding-fonts');   // the bundled fonts.css registered by Fastpix_Onboarding::register_fonts — no external host (QA S19)
        wp_enqueue_style('fastpix-onboarding', FASTPIX_PLUGIN_URL . 'assets/css/onboarding.css', array(), fastpix_asset_ver('assets/css/onboarding.css'));
        wp_enqueue_style('fastpix-library-page', FASTPIX_PLUGIN_URL . 'assets/css/library-page.css', array('fastpix-onboarding'), fastpix_asset_ver('assets/css/library-page.css'));
        wp_enqueue_style('fastpix-analytics-page', FASTPIX_PLUGIN_URL . 'assets/css/analytics-page.css', array('fastpix-library-page'), fastpix_asset_ver('assets/css/analytics-page.css'));
        wp_enqueue_script('fastpix-analytics-page', FASTPIX_PLUGIN_URL . 'assets/js/analytics-page.js', array(), fastpix_asset_ver('assets/js/analytics-page.js'), true);

        wp_localize_script('fastpix-analytics-page', 'fastpixAnalytics', array(
            'restUrl'    => esc_url_raw(rest_url(Fastpix_Rest::NS)),
            'nonce'      => wp_create_nonce('wp_rest'),
            'video'      => isset($_GET['video']) ? absint(wp_unslash($_GET['video'])) : 0,   // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation: which screen/video to show; nothing is saved
            'libraryUrl' => admin_url('admin.php?page=' . Fastpix_Library_Page::SLUG),
            'pageUrl'    => admin_url('admin.php?page=' . self::SLUG),
            'imageBase'  => Fastpix_Attachments::image_base(),
            'restricted' => !current_user_can(Fastpix_Capabilities::EDIT_VIDEO),
            'threshold'  => Fastpix_Progress::threshold(),
            'i18n'       => array(
                'oneVideo'       => __('One video', 'fastpix'),
                'acrossSite'     => __('Across every video on this site', 'fastpix'),
                'courseWatching' => __('Course watching, one course at a time', 'fastpix'),
                'copied'         => __('Copied', 'fastpix'),
                'public'         => __('Public', 'fastpix'),
                'private'        => __('Private', 'fastpix'),
                'untitled'       => __('Untitled', 'fastpix'),
                /* translators: %s: upload date */
                'uploaded'       => __('Uploaded %s', 'fastpix'),
                'views'          => __('Views', 'fastpix'),
                'people'         => __('People', 'fastpix'),
                'dailyViewersSummed'  => __('Viewers (daily, summed)', 'fastpix'),
                'hourlyViewersSummed' => __('Viewers (hourly, summed)', 'fastpix'),
                'watchTime'      => __('Watch time', 'fastpix'),
                'avgWatch'       => __('Average watch', 'fastpix'),
                'finished'       => __('Finished', 'fastpix'),
                'playbackSuccess'=> __('Playback Success', 'fastpix'),
                'startupTime'    => __('Startup Time', 'fastpix'),
                'stability'      => __('Stability', 'fastpix'),
                'renderQuality'  => __('Render Quality', 'fastpix'),
                'qoeEmpty'       => __('No measured views in this range yet — figures appear about an hour after the first play.', 'fastpix'),
                'qoeGood'        => __('Good. Nothing here needs your attention.', 'fastpix'),
                'qoeWarn'        => __('Watchable, but one component is dragging the score down.', 'fastpix'),
                /* translators: 1: weakest component name, 2: its score */
                'qoeWeakest'     => __(' Weakest component is <b>%1$s</b> at %2$s.', 'fastpix'),
                'qoeBad'         => __('Poor. Most viewers are having a bad time.', 'fastpix'),
                'playbackFailurePct'     => __('Playback Failure Percentage', 'fastpix'),
                'videoStartupFailurePct' => __('Video Startup Failure Percentage', 'fastpix'),
                'bufferRatio'    => __('Buffer Ratio', 'fastpix'),
                'videoStartupTime' => __('Video Startup Time', 'fastpix'),
                'avgBitrate'     => __('Average Bitrate', 'fastpix'),
                'trendOverTime'  => __('Trend over time', 'fastpix'),
                'pinnedRelease'  => __('Pinned — click again to release', 'fastpix'),
                'preparing'      => __('Preparing…', 'fastpix'),
                /* translators: %s: lesson number */
                'lessonN'        => __('Lesson %s', 'fastpix'),
                'enrolled'       => __('Enrolled', 'fastpix'),
                'completed'      => __('Completed', 'fastpix'),
                'avgProgress'    => __('Average progress', 'fastpix'),
                'notStarted'     => __('Not started', 'fastpix'),
                'openCoverage'   => __('Open this video’s coverage chart', 'fastpix'),
                'students'       => __('Students', 'fastpix'),
                'notRecorded'    => __('Per-student progress is not being recorded for this course.', 'fastpix'),
                'addTrackViewer' => __('Add <b>track_viewer</b> to its lesson videos to see who has watched what.', 'fastpix'),
                'all'            => __('All', 'fastpix'),
                'inProgress'     => __('In progress', 'fastpix'),
                'searchStudents' => __('Search students', 'fastpix'),
                'hashedLearner'  => __('hashed learner', 'fastpix'),
                'unknownError'   => __('Unknown error', 'fastpix'),
                'today'          => __('today', 'fastpix'),
                'yesterday'      => __('yesterday', 'fastpix'),
                'momentsAgo'     => __('moments ago', 'fastpix'),
                /* translators: %d: number of minutes */
                'minutesAgo'     => __('%d minutes ago', 'fastpix'),
                /* translators: %d: number of hours */
                'hoursAgo'       => __('%d hours ago', 'fastpix'),
                /* translators: %d: number of days */
                'daysAgo'        => __('%d days ago', 'fastpix'),
                /* translators: %d: number of posts */
                'onPost'         => __('On %d post', 'fastpix'),
                /* translators: %d: number of posts */
                'onPosts'        => __('On %d posts', 'fastpix'),
            ),
        ));
    }

    public static function render() {
        fastpix_template('analytics-page.php');
    }
}
