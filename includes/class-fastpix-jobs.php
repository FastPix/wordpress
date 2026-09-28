<?php
/**
 * Background job runner — ARCH-07.
 *
 * Action Scheduler 4.x, bundled via Composer (WP-Cron alone is insufficient,
 * SDD §19). Group and hook names below are binding: they are the names ARCH-07
 * states, not aliases.
 */

namespace Fastpix;

if (!defined('WPINC')) {
    die;
}

class Fastpix_Jobs {

    /** Groups [ARCH-07]. */
    const GROUP_WEBHOOKS    = 'fastpix-webhooks';
    const GROUP_SYNC        = 'fastpix-sync';
    const GROUP_MIGRATION   = 'fastpix-migration';
    const GROUP_AI          = 'fastpix-ai';
    const GROUP_ANALYTICS   = 'fastpix-analytics';
    const GROUP_MAINTENANCE = 'fastpix-maintenance';

    /**
     * Recurring jobs and their intervals [ARCH-07, ARCH-06]. On-request hooks
     * (migration items, AI, webhooks) are scheduled by their callers, not here.
     */
    const RECURRING = array(
        'fastpix_new_media_sweep' => array('interval' => 900,   'group' => self::GROUP_SYNC),          // every 15 min
        'fastpix_deep_sweep'      => array('interval' => 86400, 'group' => self::GROUP_SYNC),
        /**
         * Weekly consistency audit: deep sweep, field-level comparison (playback
         * ids, tracks, access policy, AI state), and a check for local rows the
         * platform no longer has. It REPORTS what it changed rather than changing
         * silently, and also runs after any restore. Orphans are surfaced, never
         * auto-removed [RULE-021]. Handler lives in the sync engine; the schedule
         * lives here so the cadence is defined in one place.
         */
        'fastpix_audit'           => array('interval' => 604800, 'group' => self::GROUP_SYNC),         // weekly
        'fastpix_analytics_video' => array('interval' => 3600,  'group' => self::GROUP_ANALYTICS),     // hourly, 48 h-active
        'fastpix_analytics_sweep' => array('interval' => 86400, 'group' => self::GROUP_ANALYTICS),     // nightly
        'fastpix_search_reindex'  => array('interval' => 86400, 'group' => self::GROUP_MAINTENANCE),
        'fastpix_usage_sweep'     => array('interval' => 86400, 'group' => self::GROUP_MAINTENANCE),
        'fastpix_upload_orphans'  => array('interval' => 86400, 'group' => self::GROUP_MAINTENANCE),
        'fastpix_prune'           => array('interval' => 86400, 'group' => self::GROUP_MAINTENANCE),
        'fastpix_health_check'    => array('interval' => 21600, 'group' => self::GROUP_MAINTENANCE),   // 6-hourly
        'fastpix_jobs_watchdog'   => array('interval' => 3600,  'group' => self::GROUP_MAINTENANCE),
    );

    /** A list-walking job stops at 20 s and reschedules from where it stopped. [ARCH-07] */
    const WALK_BUDGET_SECONDS = 20;

    /** Queue considered stalled when actions have been due this long with nothing completing. */
    const STALL_SECONDS = 3600;

    const OPT_STALLED = 'fastpix_jobs_stalled';

    /** Load the bundled library. Safe to call more than once. */
    public static function boot() {
        $bundled = FASTPIX_PLUGIN_DIR . 'vendor/woocommerce/action-scheduler/action-scheduler.php';

        if (!self::available() && file_exists($bundled)) {
            require_once $bundled;
        }

        // A job adopts the correlation id of the action that scheduled it, so
        // one user action reads as one story across every record it produces,
        // including the jobs it spawned. [ARCH-13]
        add_action('action_scheduler_before_execute', array(__CLASS__, 'adopt_correlation_id'), 10, 1);
    }

    /**
     * Entry point for every scheduled job: pick the correlation id out of the
     * job arguments before the handler runs. [ARCH-13]
     */
    public static function adopt_correlation_id($action_id) {
        if (!class_exists('\ActionScheduler')) {
            return;
        }

        $action = \ActionScheduler::store()->fetch_action($action_id);
        $args   = $action ? $action->get_args() : array();
        $first  = isset($args[0]) && is_array($args[0]) ? $args[0] : array();

        if (!empty($first['_fastpix_correlation_id'])) {
            Fastpix_Log::set_correlation_id($first['_fastpix_correlation_id']);
        }
    }

    public static function available() {
        return function_exists('as_schedule_single_action');
    }

    /** Schedule the recurring set. Idempotent — Action Scheduler dedupes on hook + args. */
    public static function schedule_recurring() {
        if (!self::available()) {
            return false;
        }

        foreach (self::RECURRING as $hook => $job) {
            if (!as_has_scheduled_action($hook, array(), $job['group'])) {
                as_schedule_recurring_action(time() + $job['interval'], $job['interval'], $hook, array(), $job['group']);
            }
        }

        return true;
    }

    /** Remove every scheduled job. Deactivation hook — activation re-schedules; data is untouched (REQ-101). */
    public static function unschedule_all() {
        if (!self::available()) {
            return false;
        }

        foreach (array_keys(self::RECURRING) as $hook) {
            as_unschedule_all_actions($hook);
        }

        return true;
    }

    /**
     * Enqueue work. The correlation id of the action that scheduled the job
     * travels with it, so its records join up with the ones already written.
     * [ARCH-13]
     */
    public static function enqueue($hook, $args = array(), $group = self::GROUP_SYNC) {
        if (!self::available()) {
            return 0;
        }

        $args['_fastpix_correlation_id'] = Fastpix_Log::correlation_id();

        return (int) as_enqueue_async_action($hook, array($args), $group);
    }

    public static function schedule_at($timestamp, $hook, $args = array(), $group = self::GROUP_SYNC) {
        if (!self::available()) {
            return 0;
        }

        $args['_fastpix_correlation_id'] = Fastpix_Log::correlation_id();

        return (int) as_schedule_single_action((int) $timestamp, $hook, array($args), $group);
    }

    /**
     * Queue watchdog: actions due for an hour with none completing means the
     * queue is not running. Name the cause rather than the symptom. [ARCH-07]
     */
    public static function watchdog() {
        if (!self::available()) {
            return self::record_stall(self::diagnose(1, false, self::wp_cron_disabled(), self::loopback_ok()));
        }

        $due = as_get_scheduled_actions(array(
            'status'         => \ActionScheduler_Store::STATUS_PENDING,
            'date'           => gmdate('Y-m-d H:i:s', time() - self::STALL_SECONDS),
            'date_compare'   => '<=',
            'group'          => '',
            'per_page'       => 1,
            'partial_args_matching' => 'off',
        ), 'ids');

        $completed = as_get_scheduled_actions(array(
            'status'       => \ActionScheduler_Store::STATUS_COMPLETE,
            'modified'     => gmdate('Y-m-d H:i:s', time() - self::STALL_SECONDS),
            'modified_compare' => '>=',
            'per_page'     => 1,
        ), 'ids');

        return self::record_stall(self::diagnose(count($due), (bool) count($completed), self::wp_cron_disabled(), self::loopback_ok()));
    }

    /**
     * Pure decision: given the queue's state, what does the owner need to be
     * told? Null means healthy. Kept separate from the Action Scheduler reads
     * so it can be checked directly. [ARCH-07]
     */
    public static function diagnose($due_count, $completed_recently, $wp_cron_disabled, $loopback_ok) {
        if ($due_count < 1 || $completed_recently) {
            return null;
        }

        if ($wp_cron_disabled) {
            $stall = array(
                'cause'   => 'wp_cron_disabled',
                'message' => __('Background work is not running: WP-Cron is disabled on this site and no system cron is calling wp-cron.php.', 'fastpix'),
            );
        } elseif (!$loopback_ok) {
            $stall = array(
                'cause'   => 'loopback_blocked',
                'message' => __('Background work is not running: this site cannot make a request to itself, so the queue is never started.', 'fastpix'),
            );
        } else {
            $stall = array(
                'cause'   => 'queue_stalled',
                'message' => __('Background work has been waiting for over an hour with nothing completing.', 'fastpix'),
            );
        }

        return $stall;
    }

    private static function record_stall($stall) {
        if ($stall === null) {
            delete_option(self::OPT_STALLED);

            return null;
        }

        update_option(self::OPT_STALLED, $stall + array('at' => time()), false);
        do_action('fastpix_log', 'jobs_stalled', array('scope' => 'maintenance', 'message' => $stall['message']));

        return $stall;
    }

    private static function wp_cron_disabled() {
        return defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
    }

    /** Site Health's own loopback test, reused rather than reimplemented. */
    private static function loopback_ok() {
        $response = wp_remote_post(site_url('wp-cron.php'), array(
            'timeout'   => 10,
            'blocking'  => true,
            'sslverify' => apply_filters('https_local_ssl_verify', false),   // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core filter
            'body'      => array('doing_wp_cron' => sprintf('%.22F', microtime(true))),
        ));

        return !is_wp_error($response) && (int) wp_remote_retrieve_response_code($response) < 500;
    }
}
