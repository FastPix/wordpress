<?php
/**
 * WP-CLI commands — ARCH-07 ("WP-CLI commands exist for large libraries").
 *
 * A library of 100,000 videos cannot be swept, reindexed or migrated through an
 * admin screen that has to answer inside a request. These commands enqueue the
 * same jobs the scheduler runs, so there is one implementation of the work and
 * the CLI is only another way to start it.
 *
 * ponytail: the commands that enqueue are complete; the ones whose handlers
 * belong to later phases say so and exit non-zero rather than pretending. They
 * become useful the moment their phase lands, with no change here.
 */

namespace Fastpix;

if (!defined('WPINC')) {
    die;
}

class Fastpix_Cli {

    public static function register() {
        if (!defined('WP_CLI') || !WP_CLI) {
            return;
        }

        \WP_CLI::add_command('fastpix status', array(__CLASS__, 'status'));
        \WP_CLI::add_command('fastpix sync', array(__CLASS__, 'sync'));
        \WP_CLI::add_command('fastpix reindex', array(__CLASS__, 'reindex'));
        \WP_CLI::add_command('fastpix prune', array(__CLASS__, 'prune'));
        \WP_CLI::add_command('fastpix doctor', array(__CLASS__, 'doctor'));
        \WP_CLI::add_command('fastpix webhook-secret', array(__CLASS__, 'webhook_secret'));
    }

    /**
     * Store the webhook signing secret from the FastPix dashboard.
     * Until both the receiver URL and this secret are configured, the plugin
     * runs in polling mode. [ERR-035]
     *
     * ## OPTIONS
     *
     * <secret>
     * : The signing secret shown when the webhook was created. Pass "" to clear.
     *
     * ## EXAMPLES
     *     wp fastpix webhook-secret whsec_xxxxx
     */
    public static function webhook_secret($args) {
        $secret = isset($args[0]) ? (string) $args[0] : '';
        Fastpix_Webhooks::set_secret($secret);

        if ($secret === '') {
            \WP_CLI::success('Webhook secret cleared; the site is in polling mode.');
        } else {
            \WP_CLI::success('Webhook secret stored (encrypted). Receiver: ' . rest_url('fastpix/v1/webhook'));
        }
    }

    /**
     * Connection, schema and queue state.
     *
     * ## EXAMPLES
     *     wp fastpix status
     */
    public static function status() {
        $state = Fastpix_Connection::state();

        \WP_CLI::log('Connection:   ' . ($state['connected'] ? 'connected' : 'not connected'));
        \WP_CLI::log('Workspace:    ' . ($state['workspace_id'] !== '' ? $state['workspace_id'] : 'unknown'));
        $health = $state['healthy'] ? 'healthy' : 'unhealthy — re-check the credential pair';
        if ($state['unreadable']) {
            $health = 'stored secret cannot be read (salts rotated?) — re-enter the pair';
        }
        \WP_CLI::log('Health:       ' . $health);
        \WP_CLI::log('Schema:       v' . Fastpix_Schema::current_version() . ' of v' . Fastpix_Schema::target_version());
        \WP_CLI::log('Job runner:   ' . (Fastpix_Jobs::available() ? 'Action Scheduler ready' : 'unavailable'));

        $stalled = get_option(Fastpix_Jobs::OPT_STALLED);
        if ($stalled) {
            \WP_CLI::warning($stalled['message']);
        }
    }

    /**
     * Queue a media sweep.
     *
     * ## OPTIONS
     *
     * [--deep]
     * : Run the resumable deep sweep instead of the incremental new-media sweep.
     *
     * ## EXAMPLES
     *     wp fastpix sync --deep
     */
    public static function sync($args, $assoc = array()) {
        self::require_connection();

        $deep = !empty($assoc['deep']);
        $hook = $deep ? 'fastpix_deep_sweep' : 'fastpix_new_media_sweep';

        if (!Fastpix_Jobs::enqueue($hook, array(), Fastpix_Jobs::GROUP_SYNC)) {
            \WP_CLI::error('Could not queue the sweep — the job runner is unavailable.');
        }

        \WP_CLI::success(($deep ? 'Deep sweep' : 'New-media sweep') . ' queued. Handler arrives with Phase 3.');
    }

    /**
     * Queue a search-index rebuild.
     *
     * ## EXAMPLES
     *     wp fastpix reindex
     */
    public static function reindex() {
        self::require_connection();

        if (get_option('fastpix_fulltext_unavailable')) {
            \WP_CLI::warning('FULLTEXT is unavailable on this database, so transcript search stays disabled. [RULE-044]');
        }

        Fastpix_Jobs::enqueue('fastpix_search_reindex', array(), Fastpix_Jobs::GROUP_MAINTENANCE);
        \WP_CLI::success('Reindex queued. Handler arrives with Phase 5.');
    }

    /**
     * Apply retention now instead of waiting for the nightly job.
     *
     * ## EXAMPLES
     *     wp fastpix prune
     */
    public static function prune() {
        $removed = Fastpix_Schema::prune();
        $lines   = array();

        foreach ($removed as $table => $count) {
            $lines[] = $table . ': ' . $count;
        }

        \WP_CLI::success('Pruned past retention — ' . implode(', ', $lines) . '.');
    }

    /**
     * Run the activation checks against the current environment.
     *
     * ## EXAMPLES
     *     wp fastpix doctor
     */
    public static function doctor() {
        $failed = 0;

        foreach (Fastpix_Activation::checks() as $check) {
            $line = sprintf('%-18s %s — found: %s', $check['id'], $check['requirement'], $check['found']);

            if ($check['ok']) {
                \WP_CLI::log('  OK    ' . $line);
                continue;
            }

            $failed++;
            if ($check['fatal']) {
                \WP_CLI::log('  FAIL  ' . $line);
            } else {
                \WP_CLI::log('  WARN  ' . $line);
            }
        }

        $queue = Fastpix_Jobs::watchdog();
        if ($queue) {
            \WP_CLI::warning($queue['message']);
        }

        if ($failed === 0) {
            \WP_CLI::success('Every requirement is met.');
        }
    }

    private static function require_connection() {
        if (!Fastpix_Credentials::has_pair()) {
            \WP_CLI::error('This site is not connected to FastPix. Run the setup wizard first.');
        }
    }
}
