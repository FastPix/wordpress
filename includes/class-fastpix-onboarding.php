<?php
/**
 * Connection screen / setup wizard — UI-001, REQ-007, FR-001…FR-004.
 *
 * One admin page. Roles holding fastpix_manage_settings get the wizard;
 * everyone else who can reach the page gets the restricted view — connection
 * name and state only, no fields rendered at all (not merely disabled).
 * [REQ-004, UI-001 restricted]
 *
 * Navigation (CONFLICT-001): "Connection" ships as a submenu of the FastPix
 * menu, following the prototype's five-destination IA.
 */

namespace Fastpix;

if (!defined('WPINC')) {
    die;
}

class Fastpix_Onboarding {

    const SLUG = 'fastpix-connection';

    public static function boot() {
        add_action('admin_menu', array(__CLASS__, 'add_menu'), 20);
        add_action('admin_enqueue_scripts', array(__CLASS__, 'register_fonts'), 1);   // before every screen's enqueue
        add_action('admin_enqueue_scripts', array(__CLASS__, 'assets'));

        // Admin notices (update banners etc.) shift the card top by a different
        // amount on every site — the wizard hides them so every step, on every
        // screen, starts at the same place.
        add_action('in_admin_header', function () {
            $screen = function_exists('get_current_screen') ? get_current_screen() : null;
            if ($screen && strpos((string) $screen->id, self::SLUG) !== false) {
                remove_all_actions('admin_notices');
                remove_all_actions('all_admin_notices');
            }
        }, 999);
    }

    public static function add_menu() {
        // Connected sites don't list Connection: Settings carries the account
        // card, "Run setup again" and Disconnect, so the menu entry would be a
        // second door to the same room. The page stays registered-by-URL for
        // that Settings link; disconnected sites get it as the ONLY entry via
        // the menu gate.
        if (Fastpix_Connection::workspace_ready()) {
            $hook = add_submenu_page(
                '',   // no parent: reachable at admin.php?page=fastpix-connection, listed nowhere
                __('Connection', 'fastpix-io'),
                __('Connection', 'fastpix-io'),
                Fastpix_Capabilities::VIEW_VIDEOS,
                self::SLUG,
                array(__CLASS__, 'render')
            );
            // A parentless page has no menu row for get_admin_page_title() to read,
            // so the tab title came out empty ("‹ Site"); name it before the header.
            if ($hook) {
                add_action('load-' . $hook, function () { $GLOBALS['title'] = __('Connection', 'fastpix-io'); });
            }

            return;
        }

        add_submenu_page(
            'fastpix-settings',
            __('Connection', 'fastpix-io'),
            __('Connection', 'fastpix-io'),
            // Menu visibility is never authorisation [SEC-011]: the page itself
            // branches on fastpix_manage_settings; view-capability holders reach
            // the restricted view by URL.
            Fastpix_Capabilities::VIEW_VIDEOS,
            self::SLUG,
            array(__CLASS__, 'render'),
            0
        );
    }

    /**
     * The plugin's type (IBM Plex Sans/Mono + Inter, Figma FastPix-V3) ships
     * bundled — REQ-112: no CDN, no third-party request from wp-admin. Registered
     * once under the handle every screen enqueues; a screen that still passes the
     * Google Fonts URL is ignored (a registered handle keeps its src).
     */
    public static function register_fonts() {
        wp_register_style('fastpix-onboarding-fonts', FASTPIX_PLUGIN_URL . 'assets/css/fonts.css', array(), fastpix_asset_ver('assets/css/fonts.css'));
    }

    public static function assets($hook) {
        if (strpos((string) $hook, self::SLUG) === false) {
            return;
        }

        // The onboarding ground is a soft gray-violet→cream gradient (Figma 9342:94781).
        add_filter('admin_body_class', function ($classes) { return $classes . ' fastpix-onboarding-bg'; });

        wp_enqueue_style('fastpix-onboarding-fonts');
        wp_enqueue_style('fastpix-onboarding', FASTPIX_PLUGIN_URL . 'assets/css/onboarding.css', array(), fastpix_asset_ver('assets/css/onboarding.css'));
        wp_enqueue_script('fastpix-onboarding', FASTPIX_PLUGIN_URL . 'assets/js/onboarding.js', array(), fastpix_asset_ver('assets/js/onboarding.js'), true);

        wp_localize_script('fastpix-onboarding', 'fastpixOnboarding', array(
            'restUrl'    => esc_url_raw(rest_url(Fastpix_Rest::NS)),
            'nonce'      => wp_create_nonce('wp_rest'),
            'libraryUrl' => admin_url('admin.php?page=fastpix-video-library'),
            'i18n'       => array(
                'bothRequired'  => __('Both values required', 'fastpix-io'),
                'oneToGo'       => __('One value to go', 'fastpix-io'),
                'pairComplete'  => __('Pair complete', 'fastpix-io'),
                'enterSecret'   => __('Enter your secret key again to update', 'fastpix-io'),
                'checking'      => __('Checking…', 'fastpix-io'),
                'notSaved'      => __('Nothing is saved yet', 'fastpix-io'),
                'savedWorking'  => __('Saved and working', 'fastpix-io'),
                'show'          => __('Show', 'fastpix-io'),
                'hide'          => __('Hide', 'fastpix-io'),
                'copyReport'    => __('Copy system report', 'fastpix-io'),
                'reportCopied'  => __('System report copied.', 'fastpix-io'),
                'unknownError'  => __('Something went wrong. The connection was not changed.', 'fastpix-io'),
                // FR-002 unreachable state: a server-side reachability problem,
                // distinct from wrong credentials.
                'unreachableTitle' => __('FastPix could not be reached', 'fastpix-io'),
                'unreachableBody'  => __('Your keys were not checked because this server could not reach FastPix. A firewall or proxy may be blocking outbound HTTPS to api.fastpix.com — confirm outbound access and try again. If it persists, copy the system report for support.', 'fastpix-io'),
                'invalidCredentials' => __('Invalid credentials', 'fastpix-io'),
                'unreachableShort' => __('FastPix did not respond — check again in a moment', 'fastpix-io'),
                'notAccepted'      => __('Not accepted', 'fastpix-io'),
                'connected'        => __('Connected', 'fastpix-io'),
                'update'           => __('Update', 'fastpix-io'),
                'connect'          => __('Connect', 'fastpix-io'),
                'saveWsFirst'      => __('Save the workspace ID first', 'fastpix-io'),
                'wsHint'           => __('Copy the workspace key exactly from the Workspaces page of the FastPix dashboard.', 'fastpix-io'),
                'wsRejected'       => __('That workspace ID was not accepted.', 'fastpix-io'),
                'saved'            => __('Saved', 'fastpix-io'),
                'copied'           => __('Copied', 'fastpix-io'),
                'copy'             => __('Copy', 'fastpix-io'),
                'configured'       => __('Configured', 'fastpix-io'),
                'whVerified'       => __('Receiver reachable. Not verified — waiting for FastPix’s next event to confirm this secret…', 'fastpix-io'),
                /* translators: %s: why FastPix could not be asked for a delivery */
                'whNoNudge'        => __('FastPix could not be asked for a delivery (%s) — its next event will confirm it.', 'fastpix-io'),
                'whRejected'       => __('Not verified — this secret does not match the one on the endpoint in the FastPix dashboard. Paste it again.', 'fastpix-io'),
                /* translators: %d: HTTP status code */
                'whNotVerified'    => __('Not verified — the receiver answered HTTP %d', 'fastpix-io'),
                'couldNotSave'     => __('Could not save.', 'fastpix-io'),
                'whSavedVerifying' => __('Saved — verifying…', 'fastpix-io'),
                /* translators: %s: the delivered event type */
                'whVerifiedDelivered' => __('Verified — FastPix delivered %s', 'fastpix-io'),
                /* translators: %s: workspace name */
                'whForWorkspace'   => __(' for workspace “%s”', 'fastpix-io'),
                'whSkipped'        => __('Skipped — save the workspace ID to finish', 'fastpix-io'),
            ),
        ));
    }

    public static function render() {
        if (!current_user_can(Fastpix_Capabilities::VIEW_VIDEOS) && !current_user_can(Fastpix_Capabilities::MANAGE_SETTINGS)) {
            wp_die(esc_html__('Your role cannot view this screen.', 'fastpix-io'));
        }

        // Owner ruling 2026-09-23 (as on Settings): the person who entered these may read and edit
        // them — a value you cannot see is one you cannot check. They are passed to the TEMPLATE
        // only; Connection::state() stays masked because it is also a REST response shape.
        $manages = current_user_can(Fastpix_Capabilities::MANAGE_SETTINGS);
        fastpix_template('onboarding-wizard.php', array(
            'state'        => Fastpix_Connection::state(),
            'restricted'   => !$manages,
            'token_full'   => $manages ? Fastpix_Credentials::token_id() : '',
            'secret_full'  => $manages ? Fastpix_Credentials::secret() : '',
        ));
    }
}
