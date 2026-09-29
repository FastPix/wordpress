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
            __('Analytics', 'fastpix-io'),
            __('Analytics', 'fastpix-io'),
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
                'oneVideo'       => __('One video', 'fastpix-io'),
                'acrossSite'     => __('Across every video on this site', 'fastpix-io'),
                'courseWatching' => __('Course watching, one course at a time', 'fastpix-io'),
                'copied'         => __('Copied', 'fastpix-io'),
                'public'         => __('Public', 'fastpix-io'),
                'private'        => __('Private', 'fastpix-io'),
                'untitled'       => __('Untitled', 'fastpix-io'),
                /* translators: %s: upload date */
                'uploaded'       => __('Uploaded %s', 'fastpix-io'),
                'views'          => __('Views', 'fastpix-io'),
                'people'         => __('People', 'fastpix-io'),
                'dailyViewersSummed'  => __('Viewers (daily, summed)', 'fastpix-io'),
                'hourlyViewersSummed' => __('Viewers (hourly, summed)', 'fastpix-io'),
                'watchTime'      => __('Watch time', 'fastpix-io'),
                'avgWatch'       => __('Average watch', 'fastpix-io'),
                'finished'       => __('Finished', 'fastpix-io'),
                'playbackSuccess'=> __('Playback Success', 'fastpix-io'),
                'startupTime'    => __('Startup Time', 'fastpix-io'),
                'stability'      => __('Stability', 'fastpix-io'),
                'renderQuality'  => __('Render Quality', 'fastpix-io'),
                'qoeEmpty'       => __('No measured views in this range yet — figures appear about an hour after the first play.', 'fastpix-io'),
                'qoeGood'        => __('Good. Nothing here needs your attention.', 'fastpix-io'),
                'qoeWarn'        => __('Watchable, but one component is dragging the score down.', 'fastpix-io'),
                /* translators: 1: weakest component name, 2: its score */
                'qoeWeakest'     => __(' Weakest component is <b>%1$s</b> at %2$s.', 'fastpix-io'),
                'qoeBad'         => __('Poor. Most viewers are having a bad time.', 'fastpix-io'),
                'playbackFailurePct'     => __('Playback Failure Percentage', 'fastpix-io'),
                'videoStartupFailurePct' => __('Video Startup Failure Percentage', 'fastpix-io'),
                'bufferRatio'    => __('Buffer Ratio', 'fastpix-io'),
                'videoStartupTime' => __('Video Startup Time', 'fastpix-io'),
                'avgBitrate'     => __('Average Bitrate', 'fastpix-io'),
                'trendOverTime'  => __('Trend over time', 'fastpix-io'),
                'pinnedRelease'  => __('Pinned — click again to release', 'fastpix-io'),
                'preparing'      => __('Preparing…', 'fastpix-io'),
                /* translators: %s: lesson number */
                'lessonN'        => __('Lesson %s', 'fastpix-io'),
                'enrolled'       => __('Enrolled', 'fastpix-io'),
                'completed'      => __('Completed', 'fastpix-io'),
                'avgProgress'    => __('Average progress', 'fastpix-io'),
                'notStarted'     => __('Not started', 'fastpix-io'),
                'openCoverage'   => __('Open this video’s coverage chart', 'fastpix-io'),
                'students'       => __('Students', 'fastpix-io'),
                'notRecorded'    => __('Per-student progress is not being recorded for this course.', 'fastpix-io'),
                'addTrackViewer' => __('Add <b>track_viewer</b> to its lesson videos to see who has watched what.', 'fastpix-io'),
                'all'            => __('All', 'fastpix-io'),
                'inProgress'     => __('In progress', 'fastpix-io'),
                'searchStudents' => __('Search students', 'fastpix-io'),
                'hashedLearner'  => __('hashed learner', 'fastpix-io'),
                'unknownError'   => __('Unknown error', 'fastpix-io'),
                'today'          => __('today', 'fastpix-io'),
                'yesterday'      => __('yesterday', 'fastpix-io'),
                'momentsAgo'     => __('moments ago', 'fastpix-io'),
                /* translators: %d: number of minutes */
                'minutesAgo'     => __('%d minutes ago', 'fastpix-io'),
                /* translators: %d: number of hours */
                'hoursAgo'       => __('%d hours ago', 'fastpix-io'),
                /* translators: %d: number of days */
                'daysAgo'        => __('%d days ago', 'fastpix-io'),
                /* translators: %d: number of posts */
                'onPost'         => __('On %d post', 'fastpix-io'),
                /* translators: %d: number of posts */
                'onPosts'        => __('On %d posts', 'fastpix-io'),
            ),
        ));
    }

    public static function render() {
        fastpix_template('analytics-page.php');
    }
}
