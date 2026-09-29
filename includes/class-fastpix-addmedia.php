<?php
/**
 * Add media screen — UI-004 (upload + URL ingestion surfaces), REQ-010/015/016.
 */

namespace Fastpix;

if (!defined('WPINC')) {
    die;
}

class Fastpix_Addmedia {

    const SLUG = 'fastpix-add-media';

    /** Set once the "Your first video is ready — Leave a review" line has been shown; it never shows again. */
    const OPT_REVIEW_ASKED = 'fastpix_review_asked';
    const REVIEW_URL       = 'https://wordpress.org/support/plugin/fastpix-io/reviews/#new-post';

    public static function boot() {
        add_action('admin_menu', array(__CLASS__, 'add_menu'), 21);
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'assets'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'register_dialog'), 5);

        // REQ-010's third surface: the Media Library. A FastPix panel inside
        // the upload UI (media modal + upload.php) drives the same session
        // flow; the video appears as a proxy attachment once bound.
        // Core fires this hook from wp_print_media_templates(), which it hooks to admin_footer, wp_footer AND
        // customize_controls_print_footer_scripts — so an unconditional panel also rendered on the front end, in the
        // Customizer and on any admin page calling wp_enqueue_media(), where media-upload.js is not loaded and the
        // button did nothing. Gate it on the same screens media_assets() serves. (QA 2026-09-22)
        add_action('post-plupload-upload-ui', array(__CLASS__, 'media_panel'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'media_assets'));
    }

    /** Shared in-page confirm/alert (QA U3). Registered once; screens list 'fastpix-dialog' as a dependency. */
    public static function register_dialog() {
        wp_register_script('fastpix-dialog', FASTPIX_PLUGIN_URL . 'assets/js/fp-dialog.js', array('wp-i18n'), fastpix_asset_ver('assets/js/fp-dialog.js'), true);
        wp_set_script_translations('fastpix-dialog', 'fastpix');   // default OK / Cancel (QA L25)
        wp_register_style('fastpix-dialog', FASTPIX_PLUGIN_URL . 'assets/css/fp-dialog.css', array(), fastpix_asset_ver('assets/css/fp-dialog.css'));
    }

    /** Renders inside the media modal's Upload files tab and on upload.php. */
    /** The four screens that load media-upload.js; anywhere else the panel would be inert markup. */
    private static function media_screen() { // NOSONAR php:S100 — WordPress snake_case naming
        if (!is_admin() || !function_exists('get_current_screen')) {
            return false;
        }
        $screen = get_current_screen();

        return $screen && in_array($screen->base, array('upload', 'media', 'post'), true);
    }

    public static function media_panel() {
        if (!self::media_screen() || !current_user_can(Fastpix_Capabilities::UPLOAD_VIDEO) || !Fastpix_Connection::pair_usable()) {   // a pair whose secret no longer decrypts is not connected (QA S5)
            return;
        }
        ?>
        <?php /* QA U2: core puts this block in an absolutely positioned .uploader-inline-content — keep it compact and on its own layer so it never paints over the attachments below. */ ?>
        <div class="fastpix-media-panel" id="fastpix-media-panel" style="position:relative; z-index:2; background:#fff; margin:12px auto 0; max-width:560px; padding:10px 14px; border:1px solid #dcdcde; border-radius:8px; text-align:left;">
            <p><strong><?php esc_html_e('Video? Send it to FastPix instead', 'fastpix'); ?></strong> —
               <?php esc_html_e('streamed from the FastPix network, captions and chapters generated, and it still shows up here in the Media Library.', 'fastpix'); ?></p>
            <p><button type="button" class="button" id="fastpix-media-pick"><?php esc_html_e('Upload video to FastPix', 'fastpix'); ?></button>
               <input type="file" id="fastpix-media-file" accept="video/*,audio/*,.mkv,.mts,.m2ts,.mxf,.rm,.wtv,.vob,.ts" multiple aria-label="<?php esc_attr_e('Video files to upload to FastPix', 'fastpix'); ?>" hidden>
               <span id="fastpix-media-status"></span></p>
            <ul id="fastpix-media-queue" aria-live="polite" style="margin:4px 0 0; font-size:12px; max-height:120px; overflow-y:auto;"></ul>
        </div>
        <?php
    }

    /** The media surfaces load their own thin queue over the same SDK. */
    public static function media_assets($hook) {
        $media_screens = in_array($hook, array('upload.php', 'media-new.php', 'post.php', 'post-new.php'), true);
        if (!$media_screens || !current_user_can(Fastpix_Capabilities::UPLOAD_VIDEO) || !Fastpix_Connection::pair_usable()) {
            return;
        }

        wp_enqueue_script('fastpix-resumable-uploads', FASTPIX_PLUGIN_URL . 'assets/vendor/fastpix-resumable-uploads.js', array(), '1.0.6', true);
        // 'heartbeat' (jQuery-driven) hands the page a fresh REST nonce in the second half of its 24 h life (QA U18).
        wp_enqueue_script('fastpix-media-upload', FASTPIX_PLUGIN_URL . 'assets/js/media-upload.js', array('fastpix-resumable-uploads', 'heartbeat'), fastpix_asset_ver('assets/js/media-upload.js'), true);

        $mimes = Fastpix_Uploads_Settings::mime_config();
        wp_localize_script('fastpix-media-upload', 'fastpixMediaUpload', array(
            'restUrl'       => esc_url_raw(rest_url(Fastpix_Rest::NS)),
            'nonce'         => wp_create_nonce('wp_rest'),
            'ajaxUrl'       => admin_url('admin-ajax.php'),   // action=rest-nonce: a fresh nonce when heartbeat was suspended (QA U18)
            'acceptedTypes' => array_values((array) $mimes['mime_types']),
            'maxFileBytes'  => Fastpix_Uploads::MAX_FILE_BYTES,
            'chunkKb'       => (int) (Fastpix_Uploads::CHUNK_BYTES / 1024),
            'concurrency'   => min(6, max(1, (int) apply_filters('fastpix_upload_concurrency', 3))),   // same queue rule as Add media [REQ-012] (QA U12)
            'i18n'          => array(
                'tooBig'          => __('Over the 20 GB per-file limit — refused before anything left this browser.', 'fastpix'),
                /* translators: %s: file MIME type */
                'badType'         => __('%s is not an accepted video or audio format — refused before anything left this browser.', 'fastpix'),
                'queued'          => __('Queued', 'fastpix'),
                'creatingSession' => __('Creating session…', 'fastpix'),
                'refused'         => __('Refused.', 'fastpix'),
                'uploaderRefused' => __('The uploader refused this file.', 'fastpix'),
                'transferred'     => __('Transferred. It appears here as a FastPix video once processing starts.', 'fastpix'),
                'transferFailed'  => __('The transfer failed', 'fastpix'),
                /* translators: %s: the transfer error message */
                'pausedMsg'       => __('Paused (%s). Resume it on the FastPix Add media screen.', 'fastpix'),
                'confirming'      => __('sent — confirming with FastPix…', 'fastpix'),
            ),
        ));
    }

    public static function add_menu() {
        add_submenu_page(
            'fastpix-settings',
            __('Add media', 'fastpix'),
            __('Add media', 'fastpix'),
            Fastpix_Capabilities::VIEW_VIDEOS,   // page branches on UPLOAD_VIDEO [SEC-011]
            self::SLUG,
            array(__CLASS__, 'render'),
            1
        );
    }

    public static function assets($hook) {
        if (strpos((string) $hook, self::SLUG) === false) {
            return;
        }

        add_filter('admin_body_class', function ($classes) { return $classes . ' fastpix-onboarding-bg'; });   // same ground + frame-pixel unit as the wizard
        wp_enqueue_style('fastpix-onboarding-fonts');   // bundled (REQ-112), registered by Fastpix_Onboarding::register_fonts (QA S19)
        wp_enqueue_style('fastpix-onboarding', FASTPIX_PLUGIN_URL . 'assets/css/onboarding.css', array(), fastpix_asset_ver('assets/css/onboarding.css'));
        wp_enqueue_style('fastpix-add-media', FASTPIX_PLUGIN_URL . 'assets/css/add-media.css', array('fastpix-onboarding'), fastpix_asset_ver('assets/css/add-media.css'));
        // The official FastPix web upload SDK, vendored — no runtime CDN loads
        // [REQ-112]. IIFE build exposing window.Uploader.
        wp_enqueue_script('fastpix-resumable-uploads', FASTPIX_PLUGIN_URL . 'assets/vendor/fastpix-resumable-uploads.js', array(), '1.0.6', true);
        // 'heartbeat' (jQuery-driven) hands the page a fresh REST nonce in the second half of its 24 h life — a day-long upload needs it (QA U18).
        wp_enqueue_script('fastpix-add-media', FASTPIX_PLUGIN_URL . 'assets/js/add-media.js', array('fastpix-resumable-uploads', 'heartbeat'), fastpix_asset_ver('assets/js/add-media.js'), true);

        $mimes = Fastpix_Uploads_Settings::mime_config();

        wp_localize_script('fastpix-add-media', 'fastpixAddMedia', array(
            'restUrl'       => esc_url_raw(rest_url(Fastpix_Rest::NS)),
            'nonce'         => wp_create_nonce('wp_rest'),
            'ajaxUrl'       => admin_url('admin-ajax.php'),   // action=rest-nonce: a fresh nonce when heartbeat was suspended (QA U18)
            'acceptedTypes' => array_values((array) $mimes['mime_types']),
            'maxFileBytes'  => Fastpix_Uploads::MAX_FILE_BYTES,
            'maxFiles'      => Fastpix_Uploads::MAX_FILES_PER_SUBMISSION,
            /** 3 files at once, configurable to 6. [REQ-012] */
            'concurrency'   => min(6, max(1, (int) apply_filters('fastpix_upload_concurrency', 3))),
            'chunkBytes'    => Fastpix_Uploads::CHUNK_BYTES,
            'libraryUrl'    => admin_url('admin.php?page=' . Fastpix_Library_Page::SLUG),
            'siteHost'      => (string) wp_parse_url(home_url(), PHP_URL_HOST),
            'canMigrate'    => current_user_can(Fastpix_Capabilities::MANAGE_SETTINGS),
            'askReview'     => self::ask_review(),
            'reviewUrl'     => self::REVIEW_URL,
            'i18n'          => array(
                'reviewReady'     => __('Your first video is ready.', 'fastpix'),
                'reviewReadyNext' => __('Your video is ready.', 'fastpix'),
                'reviewLink'      => __('Leave a review', 'fastpix'),
                'reviewTail'      => __('if this saved you time.', 'fastpix'),
                'reviewDismiss'   => __('Dismiss', 'fastpix'),
                /* translators: %d: HTTP status code */
                'httpNoAnswer'    => __('FastPix did not answer (HTTP %d).', 'fastpix'),
                'sendFailed'      => __('The request could not be sent — check your connection.', 'fastpix'),
                'tooBig'          => __('Over the 20 GB per-file limit — refused before anything left this browser.', 'fastpix'),
                /* translators: %s: file MIME type */
                'badType'         => __('%s is not an accepted video or audio format — refused before anything left this browser.', 'fastpix'),
                'stLink'          => __('Link — checked when you upload', 'fastpix'),
                /* translators: %d: files per submission */
                'tooMany'         => __('Over the %d files per submission — add it to the next batch.', 'fastpix'),
                'cancelledElsewhere' => __('This upload was cancelled elsewhere — nothing more will be sent.', 'fastpix'),
                'windowClosed'    => __('The upload window closed while this was paused — Resume sends the file again from the start.', 'fastpix'),
                /* translators: %d: number of files and links in the batch */
                'appliesTo'       => __('Applies to the %d files in this batch. Who can watch, quality, top resolution, even out volume, lock to this site and the watermark are fixed once the upload starts. The title, downloads, subtitles, chapters and summary can be changed later, per video.', 'fastpix'),
                'wmBad'           => __('Enter a full http:// or https:// image URL, or turn Watermark off.', 'fastpix'),
                'badHost'         => __('Enter a domain like videos.example.com or *.example.com.', 'fastpix'),
                /* translators: %d: number of sites */
                'lockDenySum'     => __('Plays on %d site(s) and nowhere else.', 'fastpix'),
                'lockAllowSum'    => __('Plays everywhere. Add the sites to block.', 'fastpix'),
                /* translators: %d: number of blocked sites */
                'lockAllowSumN'   => __('Plays everywhere except %d blocked site(s).', 'fastpix'),
                /* translators: %s: domain */
                'removeHost'      => __('Remove %s', 'fastpix'),
                'appliesToOne'    => __('Applies to this file. Who can watch, quality, top resolution, even out volume, lock to this site and the watermark are fixed once the upload starts. The title, downloads, subtitles, chapters and summary can be changed later.', 'fastpix'),
                /* translators: %d: number of files and links in the batch */
                'uploadN'         => __('Upload %d videos', 'fastpix'),
                'uploadOne'       => __('Upload 1 video', 'fastpix'),
                'stQueued'        => __('Queued', 'fastpix'),
                'stProcessing'    => __('Processing', 'fastpix'),
                'stPaused'        => __('Paused', 'fastpix'),
                'stUploading'     => __('Uploading', 'fastpix'),
                'stChecking'      => __('Checking', 'fastpix'),
                'pause'           => __('Pause', 'fastpix'),
                'resume'          => __('Resume', 'fastpix'),
                'remove'          => __('Remove', 'fastpix'),
                'stReady'         => __('Ready', 'fastpix'),
                'drmNoDownload'   => __('Unavailable with DRM — protection applies to streaming only.', 'fastpix'),
                'cancelled'       => __('Cancelled.', 'fastpix'),
                'refused'         => __('Refused.', 'fastpix'),
                'creatingSession' => __('Creating session', 'fastpix'),
                'notResponding'   => __('FastPix is not responding — nothing was queued.', 'fastpix'),
                'uploaderRefused' => __('The uploader refused this file.', 'fastpix'),
                'transferFailed'  => __('The transfer failed', 'fastpix'),
                /* translators: %s: the transfer error message */
                'transferFailedMsg' => __('%s. The platform holds what was sent.', 'fastpix'),
                'confirming'        => __('Sent — confirming with FastPix…', 'fastpix'),
                'connLost'        => __('Connection lost — resumes when it returns', 'fastpix'),
                'platformHoldsResume' => __('The platform holds what was sent. Resume needs this same file.', 'fastpix'),
                'resumeRefused'   => __('Resume was refused.', 'fastpix'),
                'resuming'        => __('Resuming', 'fastpix'),
                'differentFile'   => __('That is a different file — refused.', 'fastpix'),
                'nothingWasQueued'=> __('Nothing was queued.', 'fastpix'),
                'checked'         => __('Checked', 'fastpix'),
                'notReachable'    => __('Not a reachable video file — check the address and add it again', 'fastpix'),
                'openInLibrary'   => __('Open in library', 'fastpix'),
                /* translators: %s: an optional " — ERROR_CODE" suffix */
                'processFailedMsg'=> __('FastPix could not process this file%s. Nothing was published and nothing was charged.', 'fastpix'),
            ),
        ));
        if (current_user_can(Fastpix_Capabilities::MANAGE_SETTINGS)) {
            wp_enqueue_style('fastpix-dialog');
            wp_enqueue_script('fastpix-migration', FASTPIX_PLUGIN_URL . 'assets/js/migration.js', array('fastpix-add-media', 'fastpix-dialog', 'wp-i18n'), fastpix_asset_ver('assets/js/migration.js'), true);
            wp_set_script_translations('fastpix-migration', 'fastpix');   // QA L25
        }
    }

    /**
     * The review line is offered once per site: on the next upload that turns Ready here, whether or
     * not the workspace already holds videos, and never again once shown — one dismissible line on the
     * plugin's own screen, no nagging (guideline 11). Returns false (never), 'first' (nothing was
     * uploaded through the plugin before — "Your first video is ready") or 'next' ("Your video is ready").
     */
    private static function ask_review() {
        global $wpdb;

        if (get_option(self::OPT_REVIEW_ASKED) || !current_user_can(Fastpix_Capabilities::UPLOAD_VIDEO) || !Fastpix_Schema::table_exists('videos')) {
            return false;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- the plugin's own table (fixed registry name), no input
        $uploads = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Fastpix_Schema::table('videos') . " WHERE source IN ('Upload', 'URL')");

        return $uploads === 0 ? 'first' : 'next';
    }

    public static function register_routes() {
        // POST /review-asked — the line was shown; it is never offered again.
        Fastpix_Rest::register('/review-asked', array(
            'methods'    => 'POST',
            'capability' => Fastpix_Capabilities::UPLOAD_VIDEO,
            'callback'   => function () {
                update_option(self::OPT_REVIEW_ASKED, time(), false);

                return rest_ensure_response(array('asked' => true));
            },
        ));
    }

    public static function render() {
        fastpix_template('add-media.php', array(
            'can_upload'  => current_user_can(Fastpix_Capabilities::UPLOAD_VIDEO),
            'connected'   => Fastpix_Connection::pair_usable(),
            'settings'    => Fastpix_Uploads_Settings::settings_snapshot(null),
            'can_migrate' => current_user_can(Fastpix_Capabilities::MANAGE_SETTINGS),   // WF-004 actor: owner
        ));
    }
}
