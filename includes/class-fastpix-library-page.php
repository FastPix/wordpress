<?php
/**
 * Videos screen — UI-002/UI-003, FR-030…FR-032.
 *
 * Uses the same slug as the previous library page, so the wizard's "Go to
 * your video library" CTA and the menu gate keep working. Columns (CONFLICT-005):
 * thumbnail, Title, Media ID, Playback ID, Status, Access. The page renders the
 * shell; the table, opened row and bulk bar are driven by /videos routes
 * client-side.
 */

namespace Fastpix;

if (!defined('WPINC')) {
    die;
}

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery
// All SQL here runs against the plugin's own tables: identifiers come from the
// Fastpix_Schema::table() registry (a fixed whitelist, never request input) and
// every value travels through $wpdb->prepare(). Core APIs cannot query these tables.

class Fastpix_Library_Page {

    const SLUG = 'fastpix-video-library';   // deliberately reused slug

    public static function boot() {
        add_action('admin_menu', array(__CLASS__, 'replace_menu'), 30);
        add_action('admin_enqueue_scripts', array(__CLASS__, 'assets'));
    }

    /** Replace the existing submenu entry: same slug, new renderer. */
    public static function replace_menu() {
        remove_submenu_page('fastpix-settings', self::SLUG);

        add_submenu_page(
            'fastpix-settings',
            __('Videos', 'fastpix'),
            __('Videos', 'fastpix'),
            Fastpix_Capabilities::VIEW_VIDEOS,
            self::SLUG,
            array(__CLASS__, 'render'),
            2
        );
    }

    public static function assets($hook) {
        if (strpos((string) $hook, self::SLUG) === false) {
            return;
        }

        wp_enqueue_style('fastpix-onboarding-fonts');   // the bundled fonts.css registered by Fastpix_Onboarding::register_fonts — no external host (QA S19)
        wp_enqueue_style('fastpix-onboarding', FASTPIX_PLUGIN_URL . 'assets/css/onboarding.css', array(), fastpix_asset_ver('assets/css/onboarding.css'));
        wp_enqueue_style('fastpix-library-page', FASTPIX_PLUGIN_URL . 'assets/css/library-page.css', array('fastpix-onboarding'), fastpix_asset_ver('assets/css/library-page.css'));
        wp_enqueue_style('fastpix-library-open', FASTPIX_PLUGIN_URL . 'assets/css/library-open.css', array('fastpix-library-page'), fastpix_asset_ver('assets/css/library-open.css'));
        // The opened row's mini player is the real player (REQ-112 vendored) — what a visitor sees.
        wp_register_script('fastpix-hls', FASTPIX_PLUGIN_URL . 'assets/vendor/hls.min.js', array(), '1.7.3', true);   // never the player's CDN fallback (guideline 8)
        wp_enqueue_script('fastpix-player', FASTPIX_PLUGIN_URL . 'assets/vendor/fastpix-player.js', array('fastpix-hls'),
            trim((string) @file_get_contents(FASTPIX_PLUGIN_DIR . 'assets/vendor/fastpix-player.VERSION.txt')) ?: '1', true);
        wp_enqueue_style('fastpix-dialog');
        wp_enqueue_script('fastpix-library-page', FASTPIX_PLUGIN_URL . 'assets/js/library-page.js', array('fastpix-player', 'fastpix-dialog', 'wp-i18n'), fastpix_asset_ver('assets/js/library-page.js'), true);
        wp_set_script_translations('fastpix-library-page', 'fastpix');   // the screen's strings go through wp.i18n [QA L25]

        wp_localize_script('fastpix-library-page', 'fastpixLibrary', array(
            'restUrl'  => esc_url_raw(rest_url(Fastpix_Rest::NS)),
            'nonce'    => wp_create_nonce('wp_rest'),
            'ownOnly'  => !current_user_can(Fastpix_Capabilities::EDIT_VIDEO),
            'canEdit'  => current_user_can(Fastpix_Capabilities::EDIT_VIDEO_OWN),
            'canDelete'=> current_user_can(Fastpix_Capabilities::DELETE_VIDEO_OWN),
            'searchOn' => Fastpix_Search::available(),
            'canManage'=> current_user_can(Fastpix_Capabilities::MANAGE_SETTINGS),
            'addMediaUrl' => admin_url('admin.php?page=fastpix-add-media'),
            'analyticsUrl' => current_user_can(Fastpix_Capabilities::VIEW_ANALYTICS) ? admin_url('admin.php?page=' . Fastpix_Analytics_Page::SLUG) : '',
            'imageBase'   => Fastpix_Attachments::image_base(),
            'lms'         => Fastpix_Lms::enabled(),   // shows the Course-lesson embed options
        ));
    }

    public static function render() {
        global $wpdb;


        // Paused-upload banner [UI-002 paused-upload]: this user's
        // interrupted sessions, resumable from Add media.
        $paused = 0;
        if (Fastpix_Schema::table_exists('uploads')) {
            Fastpix_Uploads::mark_interrupted();
            $paused = (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM ' . Fastpix_Schema::table('uploads') . " WHERE state = 'paused' AND user_id = %d",
                get_current_user_id()
            ));
        }

        fastpix_template('library-page.php', array(
            'own_only'  => !current_user_can(Fastpix_Capabilities::EDIT_VIDEO),
            'search_on' => Fastpix_Search::available(),
            'paused_uploads' => $paused,
        ));
    }
}
