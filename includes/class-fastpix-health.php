<?php
/**
 * Site Health checks and the one-click system report — REQ-083, FR-081, INT-010.
 *
 * Eight registered checks: connection, scheduler, webhook delivery, signing,
 * schema version, object cache, detected page cache, environment. The system
 * report layers the same checks plus the last fifty error records — and carries
 * no secrets BY CONSTRUCTION: everything it contains is either a version
 * number, a check verdict, or a log row whose context was redacted at write
 * time (SEC-019). No code path reads a credential into the report.
 */

namespace Fastpix;

if (!defined('WPINC')) {
    die;
}

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery
// All SQL here runs against the plugin's own tables: identifiers come from the
// Fastpix_Schema::table() registry (a fixed whitelist, never request input) and
// every value travels through $wpdb->prepare(). Core APIs cannot query these tables.

class Fastpix_Health {

    /**
     * Option names for the webhook signing secret and playback signing key
     * (DATA-016: both non-autoloaded + encrypted).
     */
    const OPT_WEBHOOK_SECRET = 'fastpix_webhook_secret';
    const OPT_SIGNING_KEY    = 'fastpix_signing_key';

    public static function boot() {
        add_filter('site_status_tests', array(__CLASS__, 'register_tests'));
    }

    /** [INT-010] All eight, as direct (synchronous) Site Health tests. */
    public static function register_tests($tests) {
        foreach (self::checks() as $id => $check) {
            $tests['direct']['fastpix_' . $id] = array(
                'label' => $check['label'],
                'test'  => function () use ($id) {
                    return self::result($id, self::checks()[$id]);
                },
            );
        }

        return $tests;
    }

    /**
     * The eight checks. Each: label, status (good|recommended|critical),
     * description. [REQ-083]
     */
    public static function checks() {
        $checks = array();

        // 1. Connection.
        $checks['connection'] = self::connection_check();

        // 2. Scheduler.
        $checks['scheduler'] = self::scheduler_check();

        // 3. Webhook delivery. Unconfigured is polling mode, stated plainly. [ERR-035]
        if (!get_option(self::OPT_WEBHOOK_SECRET)) {
            $checks['webhook_delivery'] = self::c(__('FastPix webhooks', 'fastpix-io'), 'recommended',
                __('Webhooks are not configured yet, so video state can be up to 15 minutes behind. Configure the receiver URL and signing secret in the FastPix dashboard.', 'fastpix-io'));
        } else {
            $checks['webhook_delivery'] = self::c(__('FastPix webhooks', 'fastpix-io'), 'good',
                __('The webhook receiver is configured.', 'fastpix-io'));
        }

        // 4. Signing (needed the moment a video is private).
        if (!get_option(self::OPT_SIGNING_KEY)) {
            $checks['signing'] = self::c(__('FastPix playback signing', 'fastpix-io'), 'recommended',
                __('No playback signing key is configured. Public video plays; private video will need one.', 'fastpix-io'));
        } else {
            $checks['signing'] = self::c(__('FastPix playback signing', 'fastpix-io'), 'good',
                __('A playback signing key is configured.', 'fastpix-io'));
        }

        // 4b. DRM configuration — ERR-061: a render found it no longer resolves.
        $drm_bad = get_option(Fastpix_Render::OPT_DRM_BAD);
        if (is_array($drm_bad)) {
            $checks['drm'] = self::c(__('FastPix DRM configuration', 'fastpix-io'), 'critical',
                /* translators: %s: media id */
                sprintf(__('A DRM video (%s) could not play: its DRM configuration no longer resolves. Set the DRM configuration ID under FastPix → Settings, or change the video\'s policy.', 'fastpix-io'), (string) $drm_bad['media_id']));
        }

        // 5. Schema version.
        if (Fastpix_Schema::needs_update()) {
            $checks['schema_version'] = self::c(__('FastPix database schema', 'fastpix-io'), 'critical',
                /* translators: 1: current schema version, 2: expected schema version */
                sprintf(__('The schema is at v%1$d but v%2$d is expected. A migration has not run or has failed.', 'fastpix-io'),
                    Fastpix_Schema::current_version(), Fastpix_Schema::target_version()));
        } elseif (get_option(Fastpix_Schema::OPT_FAILED)) {
            $failed = get_option(Fastpix_Schema::OPT_FAILED);
            $checks['schema_version'] = self::c(__('FastPix database schema', 'fastpix-io'), 'critical',
                /* translators: %s: comma-separated table names */
                sprintf(__('A schema migration failed; these tables are read-only: %s', 'fastpix-io'), implode(', ', (array) $failed['tables'])));
        } else {
            $checks['schema_version'] = self::c(__('FastPix database schema', 'fastpix-io'), 'good',
                /* translators: %d: schema version */
                sprintf(__('Schema v%d, current.', 'fastpix-io'), Fastpix_Schema::current_version()));
        }

        // 6. Object cache.
        $checks['object_cache'] = Fastpix_Cache::using_object_cache()
            ? self::c(__('FastPix object cache', 'fastpix-io'), 'good', __('A persistent object cache is in use.', 'fastpix-io'))
            : self::c(__('FastPix object cache', 'fastpix-io'), 'recommended',
                __('No persistent object cache; the plugin falls back to transients. Fine for small sites, slower for large libraries.', 'fastpix-io'));

        // 7. Detected page cache — signed output must never be cached. [ARCH-09]
        $page_cache = self::detected_page_cache();
        $checks['page_cache'] = $page_cache
            ? self::c(__('FastPix page cache compatibility', 'fastpix-io'), 'recommended',
                /* translators: %s: page cache plugin name */
                sprintf(__('A page cache is detected (%s). Pages with private video must be excluded from it; the plugin sends DONOTCACHEPAGE, but full-page caches configured at the edge cannot see that header.', 'fastpix-io'), $page_cache))
            : self::c(__('FastPix page cache compatibility', 'fastpix-io'), 'good', __('No page cache detected.', 'fastpix-io'));

        // 7b. Proxy/CDN in front of the site that the rate limiter does not know
        // about: every viewer then shares one address bucket. Only while detected.
        $dominant = Fastpix_Rate_Limiter::dominant_address();
        if ($dominant && $dominant['share'] > 0.9) {
            $checks['proxy'] = self::c(__('FastPix rate limiting behind a proxy', 'fastpix-io'), 'recommended',
                sprintf(
                    /* translators: 1: percentage, 2: IP address */
                    __('%1$d%% of recent player and webhook requests arrived from one address (%2$s), which looks like a reverse proxy or CDN. Add define(\'FASTPIX_TRUSTED_PROXIES\', \'%2$s\') to wp-config.php so rate limits apply per visitor instead of to everyone at once.', 'fastpix-io'),
                    (int) round($dominant['share'] * 100), $dominant['address']
                ));
        }

        // 7c. Players are watched here but FastPix reports no views (QA B2). Only while comparable.
        $analytics = self::analytics_check();
        if ($analytics) {
            $checks['analytics_collection'] = $analytics;
        }

        // 8. Environment.
        $unmet = array_filter(Fastpix_Activation::checks(), function ($check) { return !$check['ok']; });
        $checks['environment'] = $unmet
            ? self::c(__('FastPix environment', 'fastpix-io'), 'recommended',
                implode(' · ', array_map(function ($check) {
                    return sprintf('%s — found: %s', $check['requirement'], $check['found']);
                }, $unmet)))
            : self::c(__('FastPix environment', 'fastpix-io'), 'good', __('Every environment requirement is met.', 'fastpix-io'));

        return $checks;
    }

    /**
     * The one-click system report: environment, the eight checks, the last
     * fifty error records. No secrets by construction. [FR-081, REQ-083]
     */
    public static function system_report() {
        global $wpdb, $wp_version;

        $errors = array();
        if (Fastpix_Schema::table_exists('logs')) {
            $errors = $wpdb->get_results(
                'SELECT timestamp, severity, scope, message, correlation_id, error_code, endpoint,
                        http_method, http_status, attempt, max_attempts, context
                 FROM ' . Fastpix_Schema::table('logs') . "
                 WHERE severity IN ('error', 'critical')
                 ORDER BY id DESC LIMIT 50",
                ARRAY_A
            );
        }

        return array(
            'generated_at' => gmdate('c'),
            'environment'  => array(
                'wordpress'        => $wp_version,
                'php'              => PHP_VERSION,
                'database'         => (string) $wpdb->get_var('SELECT VERSION()'),
                'plugin'           => FASTPIX_VERSION,
                'schema'           => Fastpix_Schema::current_version(),
                'environment_type' => wp_get_environment_type(),
                'multisite'        => is_multisite(),
                'object_cache'     => Fastpix_Cache::using_object_cache(),
                'page_cache'       => self::detected_page_cache(),
                'action_scheduler' => Fastpix_Jobs::available(),
                'locale'           => get_locale(),
            ),
            'connection' => array(
                // Identity only — the masked token id is the ONLY credential-adjacent
                // field, and it is already truncated. The secret has no path here.
                'connected'    => Fastpix_Connection::pair_usable(),   // an unreadable secret is not connected (QA S5)
                'healthy'      => Fastpix_Api_Client::is_healthy(),
                'workspace_id' => (string) get_option(Fastpix_Connection::OPT_WORKSPACE_ID, ''),
                'token_id'     => Fastpix_Credentials::masked_token_id(),
            ),
            'checks'      => self::checks(),
            'deactivation_feedback' => Fastpix_Deactivate::report_line(),   // the last "why did you turn it off?" answer, if any
            'last_errors' => $errors,
        );
    }

    /** [FR-101] True when connected but no deep sweep has completed in 7 days. */
    private static function deep_sweep_overdue() {
        if (!Fastpix_Credentials::has_pair() || !Fastpix_Schema::table_exists('sync_state')) {
            return false;
        }

        $state = Fastpix_Sync::sync_state('deep_sweep');
        if (empty($state['last_success_at'])) {
            // Never completed: overdue only once the connection is a week old.
            return isset($state['created_at']) && strtotime($state['created_at']) < time() - (7 * DAY_IN_SECONDS);
        }

        return strtotime($state['last_success_at']) < time() - (7 * DAY_IN_SECONDS);
    }

    /** Best-effort page cache detection. A name when found, empty string otherwise. */
    public static function detected_page_cache() {
        if (defined('WP_CACHE') && WP_CACHE && file_exists(WP_CONTENT_DIR . '/advanced-cache.php')) {
            return 'advanced-cache.php';
        }

        $known = array(
            'wp-super-cache/wp-cache.php'       => 'WP Super Cache',
            'w3-total-cache/w3-total-cache.php' => 'W3 Total Cache',
            'litespeed-cache/litespeed-cache.php' => 'LiteSpeed Cache',
            'wp-rocket/wp-rocket.php'           => 'WP Rocket',
            'wp-fastest-cache/wpFastestCache.php' => 'WP Fastest Cache',
        );

        foreach ($known as $file => $name) {
            if (in_array($file, (array) get_option('active_plugins', array()), true)) {
                return $name;
            }
        }

        return '';
    }

    /** Check 1 — the credential pair and whether the platform accepts it. */
    private static function connection_check() {
        if (!Fastpix_Credentials::has_pair()) {
            return self::c(__('FastPix connection', 'fastpix-io'), 'recommended',
                __('This site is not connected to FastPix. Run the setup wizard from the Connection screen.', 'fastpix-io'));
        }
        if (Fastpix_Credentials::is_unreadable()) {
            return self::c(__('FastPix connection', 'fastpix-io'), 'critical',
                __('The stored Secret Key can no longer be read — the site security keys may have been rotated. Re-enter the credential pair.', 'fastpix-io'));
        }

        return Fastpix_Api_Client::is_healthy()
            ? self::c(__('FastPix connection', 'fastpix-io'), 'good', __('Connected and healthy.', 'fastpix-io'))
            : self::c(__('FastPix connection', 'fastpix-io'), 'critical',
                __('FastPix rejected the stored credentials. Playback of existing pages continues; new work is paused until the pair is re-entered or rotated.', 'fastpix-io'));
    }

    /**
     * Check 7c — local data only, no probe of the collector (QA B2): watch-progress rows on the
     * already-pulled days of the last week (today excluded: views lag) against the site rollup's
     * views for those same days. Null when there is nothing reliable to compare.
     *
     * @param array|null $counts array(plays, views) — tests pass it; null reads the tables.
     */
    public static function analytics_check($counts = null) {
        global $wpdb;

        if ($counts === null) {
            if (!Fastpix_Schema::table_exists('watch_progress') || !Fastpix_Schema::table_exists('analytics_daily')
                || (int) get_option(Fastpix_Analytics::OPT_LAST_SUCCESS, 0) < time() - 2 * DAY_IN_SECONDS) {
                return null;   // no current pull → silence proves nothing
            }
            $pulled = 'FROM ' . Fastpix_Schema::table('analytics_daily') . $wpdb->prepare(
                " WHERE video_id = 0 AND dimension = 'all' AND day >= %s AND day < %s",
                gmdate('Y-m-d', time() - 7 * DAY_IN_SECONDS), gmdate('Y-m-d')
            );
            $counts = array(
                (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Fastpix_Schema::table('watch_progress') . " WHERE DATE(last_seen_at) IN (SELECT day {$pulled})"),
                (int) $wpdb->get_var("SELECT SUM(views) {$pulled}"),
            );
        }
        list($plays, $views) = $counts;

        // ponytail: a fixed floor of 5 viewer records — below it a blocked beacon or two explains the gap.
        if ($plays < 5) {
            return null;
        }

        return $views > 0
            ? self::c(__('FastPix analytics collection', 'fastpix-io'), 'good', __('Videos are being watched and FastPix is reporting views.', 'fastpix-io'))
            : self::c(__('FastPix analytics collection', 'fastpix-io'), 'recommended',
                /* translators: %d: number of watch-progress records */
                sprintf(__('Players were watched on this site in the last 7 days (%d watch-progress records), but FastPix reported no views for the same days. The player\'s analytics beacons are probably being rejected: check that the Workspace key under FastPix → Settings is the one the FastPix dashboard shows for this workspace, and that the workspace has FastPix Data enabled.', 'fastpix-io'), $plays));
    }

    /** Check 2 — the queue exists, is moving, and the deep sweep completes. */
    private static function scheduler_check() {
        $stalled = get_option(Fastpix_Jobs::OPT_STALLED);
        if (!Fastpix_Jobs::available()) {
            return self::c(__('FastPix background work', 'fastpix-io'), 'critical',
                __('Action Scheduler is not loaded, so no background work can run.', 'fastpix-io'));
        }
        if ($stalled) {
            return self::c(__('FastPix background work', 'fastpix-io'), 'critical', $stalled['message']);
        }

        // FR-101: no completed deep sweep for 7 days is a health failure.
        return self::deep_sweep_overdue()
            ? self::c(__('FastPix background work', 'fastpix-io'), 'critical',
                __('The nightly deep sweep has not completed in over 7 days, so local video records may be drifting from the platform.', 'fastpix-io'))
            : self::c(__('FastPix background work', 'fastpix-io'), 'good', __('The job queue is running.', 'fastpix-io'));
    }

    private static function c($label, $status, $description) {
        return array('label' => $label, 'status' => $status, 'description' => $description);
    }

    /** Shape one check as a WP_Site_Health test result. */
    private static function result($id, $check) {
        return array(
            'label'       => $check['label'],
            'status'      => $check['status'],
            'badge'       => array('label' => 'FastPix', 'color' => 'purple'),
            'description' => '<p>' . esc_html($check['description']) . '</p>',
            'actions'     => '',
            'test'        => 'fastpix_' . $id,
        );
    }
}
