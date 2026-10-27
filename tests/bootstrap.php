<?php
/**
 * Shared self-check bootstrap. Loads WordPress with the background machinery
 * held still, so a test cannot touch the live site through a side door:
 *
 *  - no WP-Cron spawn and no Action Scheduler async runner: a test's fixture
 *    credentials/options must never be used by a real job running in a
 *    loopback request while the test holds them (that once flagged a live
 *    video orphaned);
 *  - the recurring sweeps are re-seeded on shutdown: teardowns that cancel
 *    hooks must not leave the site without its sweeps.
 *
 * Tests keep their own option snapshots/restores on top of this.
 */

if (!defined('DISABLE_WP_CRON')) {
    define('DISABLE_WP_CRON', true);
}
if (!defined('WP_USE_THEMES')) {
    define('WP_USE_THEMES', false);
}
if (!defined('FASTPIX_SELFCHECK')) {
    define('FASTPIX_SELFCHECK', true);
}

require_once '/var/www/html/wp-load.php';

add_filter('action_scheduler_allow_async_request_runner', '__return_false');
add_filter('action_scheduler_disable_wp_cron_runner', '__return_true');   // newer AS: no cron-driven queue either

register_shutdown_function(function () {
    if (class_exists('\Fastpix\Fastpix_Jobs') && \Fastpix\Fastpix_Jobs::available()) {
        \Fastpix\Fastpix_Jobs::schedule_recurring();
    }
});
