<?php
/**
 * Activation checks — REQ-005, with the thresholds of REQ-112.
 *
 * On activation the plugin checks supported WordPress and PHP versions, a
 * secure connection, outbound connectivity to the platform, no conflicting
 * video plugin claiming the same content, the database baseline, and a
 * reachable REST API. Anything unmet is refused with the reason AND the value
 * found — a bare "requirements not met" is a defect (REQ-084).
 */

namespace Fastpix;

if (!defined('WPINC')) {
    die;
}

class Fastpix_Activation {

    /** [REQ-112] */
    const MIN_WP      = '6.8';
    const MIN_PHP     = '8.3';
    const MIN_MYSQL   = '8.0';
    const MIN_MARIADB = '10.11';

    /**
     * Run every check.
     *
     * @return array[] Each: id, requirement, found, ok, fatal.
     */
    public static function checks($force = false) {
        global $wp_version;

        $checks = array();

        $checks[] = self::check(
            'wordpress_version',
            /* translators: %s: minimum WordPress version */
            sprintf(__('WordPress %s or newer', 'fastpix-io'), self::MIN_WP),
            $wp_version,
            version_compare($wp_version, self::MIN_WP, '>=')
        );

        $checks[] = self::check(
            'php_version',
            /* translators: %s: minimum PHP version */
            sprintf(__('PHP %s or newer', 'fastpix-io'), self::MIN_PHP),
            PHP_VERSION,
            version_compare(PHP_VERSION, self::MIN_PHP, '>=')
        );

        // Local and development environments are exempt from HTTPS — a dev
        // site on http:// must be able to activate. Fatal everywhere else. [REQ-112]
        $environment = wp_get_environment_type();
        $exempt      = in_array($environment, array('local', 'development'), true);
        $scheme      = (string) wp_parse_url(home_url(), PHP_URL_SCHEME);

        $https = self::check(
            'https',
            __('A secure connection (HTTPS)', 'fastpix-io'),
            /* translators: 1: URL scheme found, 2: environment type */
            $exempt ? sprintf(__('%1$s (exempt: %2$s environment)', 'fastpix-io'), $scheme, $environment) : $scheme,
            $exempt || 'https' === $scheme
        );
        // Advisory, never a blocker (owner ruling 2026-09-01): playback works on
        // http://, so activation must too. Only DRM needs a secure context, and
        // the renderer surfaces that on the embed itself.
        $https['fatal'] = false;
        $checks[] = $https;

        $checks[] = self::database_check();
        $checks[] = self::outbound_check($force);
        $checks[] = self::rest_check($force);
        $checks[] = self::conflict_check();

        return $checks;
    }

    /**
     * The `fastpix_health_check` cron handler: refresh the cached network probes
     * in the background so the settings screen and Site Health always read a warm
     * cache and never block on a live round-trip.
     */
    public static function refresh_checks() {
        self::checks(true);
    }

    /** The checks that refuse activation — advisory ones do not. */
    public static function failures() {
        return array_values(array_filter(self::checks(), function ($check) {
            return !$check['ok'] && $check['fatal'];
        }));
    }

    /**
     * Activation hook. Refuses with the reason and the value found, and does
     * not leave the plugin half-activated. [REQ-005]
     */
    public static function activate($network_wide = false) {
        // Single-site only: caps/schema install per site, so network activation
        // would leave subsites without capabilities (broken cap-gated menus/REST).
        // Refuse it with guidance rather than half-installing.
        if (is_multisite() && $network_wide) {
            deactivate_plugins(plugin_basename(FASTPIX_PLUGIN_DIR . 'fastpix-io.php'), false, true);
            wp_die(
                esc_html__('FastPix does not support network activation. Please activate it on each site individually.', 'fastpix-io'),
                esc_html__('Network activation is not supported', 'fastpix-io'),
                array('back_link' => true)
            );
        }

        $failures = self::failures();

        if ($failures) {
            deactivate_plugins(plugin_basename(FASTPIX_PLUGIN_DIR . 'fastpix-io.php'));

            $lines = array_map(function ($check) {
                return sprintf(
                    /* translators: 1: requirement, 2: value found */
                    esc_html__('%1$s — found: %2$s', 'fastpix-io'),
                    $check['requirement'],
                    $check['found']
                );
            }, $failures);

            wp_die(
                '<h1>' . esc_html__('FastPix cannot be activated yet', 'fastpix-io') . '</h1><ul><li>'
                    . implode('</li><li>', array_map('esc_html', $lines)) . '</li></ul>',
                esc_html__('Plugin activation refused', 'fastpix-io'),
                array('back_link' => true)
            );
        }

        Fastpix_Capabilities::install();
        delete_option(Fastpix_Schema::OPT_FAILED);   // an explicit (re)activation is a legitimate retry of a failed migration (review 2026-09-20)
        Fastpix_Schema::update();
        Fastpix_Jobs::boot();
        Fastpix_Jobs::schedule_recurring();

        self::reactivation_sweep();

        // Advisory failures do not refuse activation, but they are not silent
        // either: logged now (a live run, which also warms the probe cache),
        // re-checked by fastpix_health_check / Site Health.
        foreach (self::checks(true) as $check) {
            if (!$check['ok'] && !$check['fatal']) {
                do_action('fastpix_log', 'activation_advisory', array(
                    'severity' => 'warning',
                    'scope'    => 'activation',
                    'message'  => sprintf('%s — found: %s', $check['requirement'], $check['found']),
                ));
            }
        }
    }

    /** MySQL ≥8.0 / MariaDB ≥10.11, InnoDB, FULLTEXT. [REQ-112, ARCH-08] */
    private static function database_check() {
        global $wpdb;

        $raw        = (string) $wpdb->db_version();
        $server     = (string) $wpdb->get_var('SELECT VERSION()');
        $is_mariadb = stripos($server, 'mariadb') !== false;
        $minimum    = $is_mariadb ? self::MIN_MARIADB : self::MIN_MYSQL;

        // wpdb::db_version() reports MariaDB's MySQL-compatibility version (5.5.5-…),
        // so the real number comes from the VERSION() string itself.
        $version = $raw;
        if ($is_mariadb && preg_match('/(\d+\.\d+\.\d+)-MariaDB/i', $server, $m)) {
            $version = $m[1];
        }

        $engines = $wpdb->get_col("SHOW ENGINES");
        $has_innodb = empty($engines) || in_array('InnoDB', $engines, true);

        $ok = version_compare($version, $minimum, '>=') && $has_innodb;

        return self::check(
            'database',
            sprintf(
                /* translators: 1: database engine name, 2: minimum version */
                __('%1$s %2$s or newer, with InnoDB and FULLTEXT', 'fastpix-io'),
                $is_mariadb ? 'MariaDB' : 'MySQL',
                $minimum
            ),
            $server . ($has_innodb ? '' : __(' (no InnoDB)', 'fastpix-io')),
            $ok
        );
    }

    /**
     * The two network probes below are cached: on the settings screen and Site
     * Health they must NOT block the page on a live round-trip (up to ~40s when
     * the platform/loopback is down). The `fastpix_health_check` cron refreshes
     * the cache in the background with $force=true; activation forces a live run.
     * TTL is 2× the cron interval so a running cron always refreshes before it
     * expires and a render never triggers a live probe.
     */
    const CHECK_CACHE_TTL = 43200;   // 12 h (cron runs 6-hourly)

    private static function outbound_check($force = false) {
        if (!$force) {
            $cached = get_transient('fastpix_check_outbound');
            if (is_array($cached)) { return $cached; }
        }
        $url      = Fastpix_Api_Client::base_url() . '/on-demand';
        $response = wp_remote_get($url, array(
            'timeout' => Fastpix_Api_Client::TIMEOUT_INTERACTIVE,
        ));

        // Any HTTP answer proves the route out. 401 is the expected one here:
        // the request is unauthenticated on purpose.
        $ok    = !is_wp_error($response);
        $found = $ok
            /* translators: 1: HTTP status code, 2: URL */
            ? sprintf(__('HTTP %1$d from %2$s', 'fastpix-io'), wp_remote_retrieve_response_code($response), $url)
            : $response->get_error_message();

        /* translators: %s: URL */
        $check = self::check('outbound', sprintf(__('Outbound connectivity to %s', 'fastpix-io'), $url), $found, $ok);
        set_transient('fastpix_check_outbound', $check, self::CHECK_CACHE_TTL);
        return $check;
    }

    /**
     * The plugin's own REST namespace has to be reachable. [REQ-005, REQ-112]
     *
     * Advisory, not fatal: this is a loopback request, and
     * containers, reverse proxies and split-horizon DNS all legitimately fail
     * it while the site's REST API works fine from a browser. Refusing
     * activation on it locks out working sites — including this project's own
     * docker stack. A persistent failure surfaces through the fastpix_health_check
     * job and Site Health (REQ-083) instead.
     */
    private static function rest_check($force = false) {
        if (!$force) {
            $cached = get_transient('fastpix_check_rest');
            if (is_array($cached)) { return $cached; }
        }
        $url      = rest_url();
        $response = wp_remote_get($url, array('timeout' => 10, 'sslverify' => apply_filters('https_local_ssl_verify', false)));   // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core filter
        $status   = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);
        $ok       = $status > 0 && $status < 400;

        $check = self::check(
            'rest_api',
            __('A reachable WordPress REST API', 'fastpix-io'),
            /* translators: 1: HTTP status code, 2: URL */
            is_wp_error($response) ? $response->get_error_message() : sprintf(__('HTTP %1$d from %2$s', 'fastpix-io'), $status, $url),
            $ok
        );
        $check['fatal'] = false;

        set_transient('fastpix_check_rest', $check, self::CHECK_CACHE_TTL);
        return $check;
    }

    /**
     * "No conflicting video plugin claiming the same content" — the spec names
     * the check but defines no plugin list or heuristic, and a false positive
     * would lock users out. So this reports what it finds and never refuses; it
     * becomes fatal the day someone writes the list. [REQ-005, AMBIG-001]
     */
    private static function conflict_check() {
        $known = apply_filters('fastpix_conflicting_plugins', array());
        $found = array();

        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        foreach ($known as $file) {
            if (is_plugin_active($file)) {
                $found[] = $file;
            }
        }

        $check = self::check(
            'plugin_conflict',
            __('No conflicting video plugin claiming the same content', 'fastpix-io'),
            $found ? implode(', ', $found) : __('none detected', 'fastpix-io'),
            empty($found)
        );
        $check['fatal'] = false;   // AMBIG-001 — advisory until the list exists.

        return $check;
    }

    /**
     * Reactivation on a connected site runs one deep sweep so everything that
     * changed on the platform while inactive is repaired; embeds themselves
     * restore the moment the render hooks return. [REQ-101, WF-012]
     */
    public static function reactivation_sweep() {
        if (Fastpix_Credentials::has_pair()) {
            Fastpix_Jobs::enqueue('fastpix_deep_sweep', array(), Fastpix_Jobs::GROUP_SYNC);
        }
    }

    private static function check($id, $requirement, $found, $ok, $fatal = true) {
        return array(
            'id'          => $id,
            'requirement' => $requirement,
            'found'       => (string) $found,
            'ok'          => (bool) $ok,
            'fatal'       => (bool) $fatal,
        );
    }
}
