<?php
/**
 * Menu gate — connection state decides the IA.
 *
 * Disconnected: one top-level FastPix entry → the setup wizard (UI-001).
 * Videos, Upload/Add media, Analytics and Settings are not registered — the
 * prototype treats onboarding as the entry surface and every other screen
 * assumes a workspace. Connected: the full IA.
 *
 * Menu visibility is never authorisation (SEC-011): capability checks on every
 * route and page are untouched. Direct URL access to a gated page while
 * disconnected redirects to the wizard; REST routes stay registered and answer
 * 409 with the reason. Published pages are unaffected.
 */

namespace Fastpix;

if (!defined('WPINC')) {
    die;
}

class Fastpix_Menu_Gate {

    /** Admin pages that need a workspace. The wizard is deliberately absent. */
    const GATED_PAGES = array(
        'fastpix-settings',          // top-level container (lands on Videos)
        'fastpix-video-library',     // Videos (UI-002)
        'fastpix-add-media',         // Add media (UI-004)
        'fastpix-analytics',         // Analytics (UI-005)
        'fastpix-settings-screen',   // Settings (UI-006)
    );

    public static function boot() {
        add_action('admin_menu', array(__CLASS__, 'register'), 9);  // parent before the page classes add their submenus
        add_action('admin_menu', array(__CLASS__, 'gate'), 999);    // after every registrar
    }

    /**
     * The top-level FastPix menu — a container that lands on Videos; the
     * destinations (Videos, Add media, Settings) register themselves as submenus.
     */
    public static function register() {
        add_menu_page(
            __('FastPix', 'fastpix'),
            __('FastPix', 'fastpix'),
            Fastpix_Capabilities::VIEW_VIDEOS,
            'fastpix-settings',
            array('\Fastpix\Fastpix_Library_Page', 'render'),
            'data:image/svg+xml;base64,' . base64_encode(file_get_contents(FASTPIX_PLUGIN_DIR . 'assets/images/fastpix-mark.svg')),
            30
        );
    }

    /** Collapse the menu to the wizard while no workspace is connected. */
    public static function gate() {
        // The top level is a container, not a destination: no self-titled first
        // submenu entry (WordPress auto-inserts one when submenus register).
        remove_submenu_page('fastpix-settings', 'fastpix-settings');

        if (Fastpix_Connection::workspace_ready()) {
            return;   // full IA
        }

        // Core checks page access right after admin_menu (wp-admin/menu.php), BEFORE admin_init:
        // a redirect hooked there never ran, so Disconnect on Settings (which reloads the page)
        // died on "Sorry, you are not allowed to access this page." Redirect here, before the
        // pages are taken out of the menu. (QA 2026-09-21)
        self::redirect();

        foreach (self::GATED_PAGES as $page) {
            remove_submenu_page('fastpix-settings', $page);
        }
        remove_submenu_page('fastpix-settings', Fastpix_Onboarding::SLUG);   // re-added as the top level below
        remove_menu_page('fastpix-settings');

        add_menu_page(
            __('FastPix', 'fastpix'),
            __('FastPix', 'fastpix'),
            Fastpix_Capabilities::VIEW_VIDEOS,
            Fastpix_Onboarding::SLUG,
            array('\Fastpix\Fastpix_Onboarding', 'render'),
            'data:image/svg+xml;base64,' . base64_encode(file_get_contents(FASTPIX_PLUGIN_DIR . 'assets/images/fastpix-mark.svg')),
            30
        );
    }

    /**
     * Where a gated request should land instead, or null to leave it alone.
     * Pure decision, separated from the redirect so it can be tested.
     */
    public static function gated_redirect_url($page) {
        if (!in_array((string) $page, self::GATED_PAGES, true)) {
            return null;
        }
        if (Fastpix_Connection::workspace_ready()) {
            return null;
        }

        return admin_url('admin.php?page=' . Fastpix_Onboarding::SLUG);
    }

    /** remove_menu_page hides but never blocks — direct URLs are caught here. Called from gate(), i.e. during admin_menu. */
    public static function redirect() {
        if (wp_doing_ajax() || !isset($_GET['page'])) {   // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation: which screen/video to show; nothing is saved
            return;
        }

        $target = self::gated_redirect_url(sanitize_key(wp_unslash($_GET['page'])));   // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation: which screen/video to show; nothing is saved
        if ($target === null) {
            return;
        }

        wp_safe_redirect($target);
        exit;
    }
}
