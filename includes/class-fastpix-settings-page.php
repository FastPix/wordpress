<?php
/**
 * Settings screen — UI-006, FR-080 — and the minimal /settings route:
 * the webhook signing secret (write-only — "Instant updates") and the
 * delete-on-uninstall flag (REQ-102, default off).
 */

namespace Fastpix;

if (!defined('WPINC')) {
    die;
}

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery
// All SQL here runs against the plugin's own tables: identifiers come from the
// Fastpix_Schema::table() registry (a fixed whitelist, never request input) and
// every value travels through $wpdb->prepare(). Core APIs cannot query these tables.

class Fastpix_Settings_Page {

    const SLUG = 'fastpix-settings-screen';

    const OPT_DELETE_ON_UNINSTALL = 'fastpix_delete_on_uninstall';
    const OPT_DRM_CONFIG_ID       = 'fastpix_drm_configuration_id';   // from the FastPix dashboard (Settings → DRM)

    /** UUID or '' — the id used whenever a batch chooses the DRM access policy. */
    public static function drm_configuration_id() {
        return (string) get_option(self::OPT_DRM_CONFIG_ID, '');
    }

    public static function boot() {
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
        add_action('admin_menu', array(__CLASS__, 'add_menu'), 31);
        add_action('admin_enqueue_scripts', array(__CLASS__, 'assets'));
    }

    /* -------------------------------------------------------------- route */

    public static function register_routes() {
        Fastpix_Rest::register('/settings/webhook-test', array(
            'methods' => 'POST', 'callback' => array(__CLASS__, 'webhook_test'), 'capability' => Fastpix_Capabilities::MANAGE_SETTINGS,
        ));
        Fastpix_Rest::register('/settings/webhook-status', array(
            'methods' => 'GET', 'callback' => array(__CLASS__, 'webhook_status'), 'capability' => Fastpix_Capabilities::MANAGE_SETTINGS,
        ));
        Fastpix_Rest::register('/settings', array(
            array(
                'methods'    => 'GET',
                'callback'   => array(__CLASS__, 'get_settings'),
                'capability' => Fastpix_Capabilities::MANAGE_SETTINGS,
            ),
            array(
                'methods'    => 'PATCH',
                'callback'   => array(__CLASS__, 'patch_settings'),
                'capability' => Fastpix_Capabilities::MANAGE_SETTINGS,
                'args'       => array(
                    'webhook_secret' => Fastpix_Rest::arg('string', array(
                        'sanitize_callback' => function ($value) { return trim((string) $value); },
                    )),
                    'delete_on_uninstall' => Fastpix_Rest::arg('boolean'),
                    'playback_token_ttl' => Fastpix_Rest::arg('integer', array('minimum' => Fastpix_Signing::MIN_TTL, 'maximum' => Fastpix_Signing::MAX_TTL)),
                    'structured_data'    => Fastpix_Rest::arg('boolean'),
                    'lms_enabled'          => Fastpix_Rest::arg('boolean'),   // declared, so "false" is false [ARCH-02]
                    'lesson_retention_days' => Fastpix_Rest::arg('integer', array('minimum' => 1, 'maximum' => 3650)),
                    'drm_configuration_id' => Fastpix_Rest::arg('string', array(
                        'sanitize_callback'  => function ($value) { return strtolower(trim((string) $value)); },
                        'validate_callback'  => function ($value) {
                            return $value === '' || (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', trim((string) $value));
                        },
                    )),
                ),
            ),
        ));
    }

    public static function webhook_test() {
        $result = Fastpix_Webhooks_Apply::send_test_event();
        if (is_wp_error($result)) {
            return $result;
        }
        // The self-test proves the receiver + secret; a real FastPix delivery
        // (nudged here) proves the platform end and names the workspace.
        $nudge = $result['delivered'] ? Fastpix_Webhooks_Apply::nudge_platform_result() : array('sent' => false, 'reason' => '');
        $result['platform_nudged'] = $nudge['sent'];
        $result['nudge_reason']    = $nudge['reason'];   // why FastPix could not be asked for a delivery (invalid pair, no media, API down)

        return rest_ensure_response($result);
    }

    public static function webhook_status() {
        return rest_ensure_response(Fastpix_Webhooks::status());
    }

    public static function get_settings() {
        return rest_ensure_response(array(
            // Write-only: configured yes/no, never the value. [REQ-003 discipline]
            'webhook_configured'  => Fastpix_Webhooks::configured(),
            'webhook_url'         => rest_url(Fastpix_Rest::NS . '/webhook'),
            'delete_on_uninstall' => (bool) get_option(self::OPT_DELETE_ON_UNINSTALL, false),
            'drm_configuration_id' => self::drm_configuration_id(),
            'playback_token_ttl'   => Fastpix_Signing::ttl(),
            'structured_data'      => (bool) get_option(Fastpix_Render::OPT_SEO, true),
            'lms_enabled'           => Fastpix_Lms::enabled(),
            'lesson_retention_days' => Fastpix_Lms::retention_days(),
            'signing_key'          => Fastpix_Signing::has_key(),   // configured yes/no, never the key
        ));
    }

    public static function patch_settings($request) {
        // The screen renders the mask, never the secret (ASSUME-107): the mask coming
        // back means "the secret I already have" — nothing is rewritten, the verdict stands.
        $webhook_secret = $request->get_param('webhook_secret');
        if ($webhook_secret !== null && !($webhook_secret === Fastpix_Credentials::SECRET_MASK && Fastpix_Webhooks::configured())) {
            Fastpix_Webhooks::set_secret((string) $request->get_param('webhook_secret'));
            do_action('fastpix_audit_event', 'webhook_secret_' . (Fastpix_Webhooks::configured() ? 'set' : 'cleared'), array());
        }

        if ($request->get_param('delete_on_uninstall') !== null) {
            update_option(self::OPT_DELETE_ON_UNINSTALL, (bool) $request->get_param('delete_on_uninstall'), false);
        }

        if ($request->get_param('playback_token_ttl') !== null) {
            update_option(Fastpix_Signing::OPT_TOKEN_TTL, (int) $request->get_param('playback_token_ttl'), false);
        }
        if ($request->get_param('lms_enabled') !== null) {   // course features are opt-in
            update_option(Fastpix_Lms::OPT_ENABLED, (bool) $request->get_param('lms_enabled'), false);
        }
        if ($request->get_param('lesson_retention_days') !== null) {
            update_option(Fastpix_Lms::OPT_RETENTION, max(1, min(3650, (int) $request->get_param('lesson_retention_days'))), false);
        }
        if ($request->get_param('structured_data') !== null) {
            // '1'/'0', not a bool: on a never-saved option update_option() treats `false` as "unchanged"
            // (it matches WP's own missing-option default) and skips the write, so Off reverted to the
            // default On after reload. '0' is still falsy for every reader. (QA: structured data save)
            update_option(Fastpix_Render::OPT_SEO, $request->get_param('structured_data') ? '1' : '0', false);
        }

        if ($request->get_param('drm_configuration_id') !== null) {
            $id = (string) $request->get_param('drm_configuration_id');
            $id === '' ? delete_option(self::OPT_DRM_CONFIG_ID) : update_option(self::OPT_DRM_CONFIG_ID, $id, false);
        }

        return self::get_settings();
    }

    /* --------------------------------------------------------------- page */

    public static function add_menu() {
        add_submenu_page(
            'fastpix-settings',
            __('Settings', 'fastpix'),
            __('Settings', 'fastpix'),
            Fastpix_Capabilities::MANAGE_SETTINGS,
            self::SLUG,
            array(__CLASS__, 'render'),
            9
        );
    }

    public static function assets($hook) {
        if (strpos((string) $hook, self::SLUG) === false) {
            return;
        }

        wp_enqueue_style('fastpix-onboarding-fonts');   // bundled (REQ-112), registered by Fastpix_Onboarding::register_fonts
        wp_enqueue_style('fastpix-onboarding', FASTPIX_PLUGIN_URL . 'assets/css/onboarding.css', array(), fastpix_asset_ver('assets/css/onboarding.css'));
        wp_enqueue_style('fastpix-settings-page', FASTPIX_PLUGIN_URL . 'assets/css/settings-page.css', array('fastpix-onboarding'), fastpix_asset_ver('assets/css/settings-page.css'));
        wp_enqueue_style('fastpix-dialog');
        wp_enqueue_script('fastpix-settings-page', FASTPIX_PLUGIN_URL . 'assets/js/settings-page.js', array('fastpix-dialog', 'wp-i18n'), fastpix_asset_ver('assets/js/settings-page.js'), true);
        wp_set_script_translations('fastpix-settings-page', 'fastpix');   // QA L25

        wp_localize_script('fastpix-settings-page', 'fastpixSettings', array(
            'restUrl' => esc_url_raw(rest_url(Fastpix_Rest::NS)),
            'nonce'   => wp_create_nonce('wp_rest'),
        ));
    }

    public static function render() {
        if (!current_user_can(Fastpix_Capabilities::MANAGE_SETTINGS)) {
            wp_die(esc_html__('Settings are limited to the settings capability.', 'fastpix'));   // REQ-004
        }

        global $wpdb;

        $state    = Fastpix_Connection::state();
        $checks   = self::reader_checks();
        $problems = Fastpix_Schema::table_exists('logs') ? $wpdb->get_results($wpdb->prepare(
            'SELECT timestamp, severity, scope, message, error_code FROM ' . Fastpix_Schema::table('logs') . "
             WHERE severity IN ('error', 'critical', 'warning') AND timestamp > %s
             ORDER BY id DESC LIMIT 20",
            gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS)
        ), ARRAY_A) : array();
        $delete_on_uninstall = (bool) get_option(self::OPT_DELETE_ON_UNINSTALL, false);
        $webhook_configured  = Fastpix_Webhooks::configured();
        $webhook_url         = rest_url(Fastpix_Rest::NS . '/webhook');
        // Owner ruling 2026-09-22 (ASSUME-113, reverses ASSUME-107 back to ASSUME-037): a user who holds
        // fastpix_manage_settings may READ and EDIT the stored credentials on this screen — they are the
        // person who entered them, and a value they cannot see is one they cannot check. Show/Hide keeps
        // them off the screen by default so a screen share or a shoulder does not catch them; the routes
        // still accept a mask coming back as "keep what is stored" for any client that sends one.
        $token_id_full       = Fastpix_Credentials::token_id();
        $secret_full         = Fastpix_Credentials::secret();
        $webhook_secret_full = Fastpix_Webhooks::secret();
        $connected_at        = (int) get_option(Fastpix_Connection::OPT_CONNECTED_AT, 0);
        if (!$connected_at && $state['connected'] && Fastpix_Schema::table_exists('audit')) {
            $connected_at = (int) strtotime((string) $wpdb->get_var("SELECT created_at FROM " . Fastpix_Schema::table('audit') . " WHERE event = 'connect' ORDER BY id DESC LIMIT 1"));
        }
        $last_event = Fastpix_Webhooks::last_event();

        fastpix_template('settings-screen.php', compact(
            'state', 'checks', 'problems', 'delete_on_uninstall',
            'webhook_configured', 'webhook_url', 'token_id_full', 'secret_full',
            'webhook_secret_full', 'connected_at', 'last_event'
        ));
    }

    /**
     * The six reader-named checks of UI-006 — each named for what it does for
     * the reader, saying what it MEANS rather than reporting a state.
     */
    public static function reader_checks() {
        $health = Fastpix_Health::checks();
        $sweep  = Fastpix_Schema::table_exists('sync_state') ? Fastpix_Sync::sync_state('deep_sweep') : array();
        $map    = function ($check, $name, $good_meaning) {
            return array(
                'name'    => $name,
                'ok'      => $check['status'] === 'good',
                'warn'    => $check['status'] === 'recommended',
                'meaning' => $check['status'] === 'good' ? $good_meaning : $check['description'],
            );
        };

        $sweep_meaning = !empty($sweep['last_success_at'])
            /* translators: 1: date and time, 2: records read, 3: records corrected */
            ? sprintf(__('Last completed %1$s. %2$d records read, %3$d corrected.', 'fastpix'),
                $sweep['last_success_at'], (int) $sweep['items_seen'], (int) $sweep['items_changed'])
            : __('Has not completed yet — it runs nightly.', 'fastpix');

        $checks = array(
            $map($health['connection'], __('Talking to FastPix', 'fastpix'), __('Answering normally.', 'fastpix')),
            $map($health['scheduler'], __('Background jobs', 'fastpix'), __('Running.', 'fastpix')),
            $map($health['webhook_delivery'], __('Instant updates', 'fastpix'), __('Webhook deliveries are arriving.', 'fastpix')),
            array('name' => __('Nightly double-check', 'fastpix'), 'ok' => !empty($sweep['last_success_at']), 'warn' => empty($sweep['last_success_at']), 'meaning' => $sweep_meaning),
            $map($health['signing'], __('Keys for private video', 'fastpix'), __('Valid. They renew themselves.', 'fastpix')),
            $map($health['page_cache'], __('Page caching', 'fastpix'), __('No page cache detected.', 'fastpix')),
        );
        if (isset($health['drm'])) {   // only present while a DRM configuration fails to resolve (ERR-061)
            $checks[] = $map($health['drm'], __('DRM configuration', 'fastpix'), '');
        }

        return $checks;
    }
}
