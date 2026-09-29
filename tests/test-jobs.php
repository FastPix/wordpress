<?php
/**
 * Self-check for Fastpix_Jobs — ARCH-07.
 *
 *   docker compose exec wordpress php -d zend.assertions=1 \
 *     /var/www/html/wp-content/plugins/fastpix/tests/test-jobs.php
 *
 * The watchdog's decision is checked directly rather than through Action
 * Scheduler: the interesting part is which cause it names, not how it counted.
 * Scheduling is checked against the real library, and every action this file
 * schedules is unscheduled again.
 */

define('WP_USE_THEMES', false);
define('FASTPIX_PLUGIN_DIR', '/var/www/html/wp-content/plugins/fastpix/');
require_once __DIR__ . '/bootstrap.php';

foreach (array('schema', 'log', 'jobs') as $class) {
    require_once __DIR__ . '/../includes/class-fastpix-' . $class . '.php';
}

use Fastpix\Fastpix_Jobs as Jobs;
use Fastpix\Fastpix_Log as Log;

$saved_stalled = get_option(Jobs::OPT_STALLED, null);

// ------------------------------------------------- groups and hooks (binding)

$groups = array(Jobs::GROUP_WEBHOOKS, Jobs::GROUP_SYNC, Jobs::GROUP_MIGRATION,
                Jobs::GROUP_AI, Jobs::GROUP_ANALYTICS, Jobs::GROUP_MAINTENANCE);
assert(count(array_unique($groups)) === 6, 'six groups, all distinct [ARCH-07]');

// The hook names ARCH-07 states are the hook names used.
foreach (array('fastpix_new_media_sweep', 'fastpix_deep_sweep', 'fastpix_analytics_video',
             'fastpix_analytics_sweep', 'fastpix_search_reindex', 'fastpix_usage_sweep',
             'fastpix_upload_orphans', 'fastpix_prune', 'fastpix_health_check') as $hook) {
    assert(isset(Jobs::RECURRING[$hook]), "{$hook} is a recurring job [ARCH-07]");
}

assert(Jobs::RECURRING['fastpix_analytics_video']['interval'] === HOUR_IN_SECONDS, 'analytics per video is hourly [ARCH-07]');
assert(Jobs::RECURRING['fastpix_analytics_sweep']['interval'] === DAY_IN_SECONDS, 'the analytics sweep is nightly');
assert(Jobs::RECURRING['fastpix_prune']['interval'] === DAY_IN_SECONDS, 'pruning is nightly');
assert(Jobs::RECURRING['fastpix_health_check']['interval'] === 6 * HOUR_IN_SECONDS, 'health checks are 6-hourly');
assert(Jobs::RECURRING['fastpix_new_media_sweep']['interval'] === 15 * MINUTE_IN_SECONDS, 'the new-media sweep runs every 15 minutes [ARCH-06]');
assert(Jobs::RECURRING['fastpix_audit']['interval'] === WEEK_IN_SECONDS, 'the consistency audit is weekly [WF-008]');
assert(Jobs::RECURRING['fastpix_audit']['group'] === Jobs::GROUP_SYNC, 'and belongs to the sync group [ARCH-07]');
assert(Jobs::RECURRING['fastpix_prune']['group'] === Jobs::GROUP_MAINTENANCE, 'pruning belongs to maintenance');
assert(Jobs::RECURRING['fastpix_analytics_video']['group'] === Jobs::GROUP_ANALYTICS, 'analytics jobs belong to analytics');

assert(Jobs::WALK_BUDGET_SECONDS === 20, 'list-walking jobs stop at 20 s [ARCH-07]');

// `fastpix_audit` is a scheduled sync job in ARCH-07, so it must NOT be the
// audit-writer's action hook — Action Scheduler fires a job by that name.
assert(has_action('fastpix_audit', array('Fastpix\Fastpix_Log', 'audit')) === false, 'the audit writer does not squat on the fastpix_audit job hook');

// ------------------------------------------------------- watchdog decisions

assert(Jobs::diagnose(0, false, false, true) === null, 'an empty queue is healthy [ARCH-07]');
assert(Jobs::diagnose(5, true, false, true) === null, 'work completing recently is healthy');

$cron_off = Jobs::diagnose(5, false, true, true);
assert($cron_off['cause'] === 'wp_cron_disabled', 'a disabled WP-Cron is named as the cause [ARCH-07]');

$loopback = Jobs::diagnose(5, false, false, false);
assert($loopback['cause'] === 'loopback_blocked', 'a blocked loopback is named as the cause [ARCH-07]');

$stalled = Jobs::diagnose(5, false, false, true);
assert($stalled['cause'] === 'queue_stalled', 'an otherwise stalled queue is reported plainly');
foreach (array($cron_off, $loopback, $stalled) as $case) {
    assert(strlen($case['message']) > 30, 'every cause carries a message that says what to do [REQ-084]');
}

// ---------------------------------------------------- Action Scheduler is bundled

Jobs::boot();
assert(Jobs::available() === true, 'Action Scheduler 4.x is bundled and loaded [ARCH-07, REQ-112]');
assert(file_exists(FASTPIX_PLUGIN_DIR . 'vendor/woocommerce/action-scheduler/action-scheduler.php'), 'it ships in the package — no remote code [SEC-020]');

// ------------------------------------------------------ correlation travels

Log::set_correlation_id($correlation = wp_generate_uuid4());
$action_id = Jobs::schedule_at(time() + 3600, 'fastpix_selfcheck_job', array('video_id' => 7), Jobs::GROUP_SYNC);

if ($action_id) {
    $args = ActionScheduler::store()->fetch_action($action_id)->get_args();
    assert($args[0]['video_id'] === 7, 'the caller arguments are carried');
    assert($args[0]['_fastpix_correlation_id'] === $correlation, 'a job inherits the correlation id of the action that scheduled it [ARCH-13]');

    // The consuming half: the runner adopts it before the handler runs, so
    // records written by the job join the ones written by the request.
    assert(has_action('action_scheduler_before_execute', array('Fastpix\Fastpix_Jobs', 'adopt_correlation_id')) !== false,
        'the runner hooks job entry [ARCH-13]');

    Log::set_correlation_id(wp_generate_uuid4());
    assert(Log::correlation_id() !== $correlation, 'the id differs before the job starts');

    do_action('action_scheduler_before_execute', $action_id, 'selfcheck');
    assert(Log::correlation_id() === $correlation, 'entering the job restores the scheduling action\'s correlation id [ARCH-13]');

    // A job scheduled without one leaves the current id alone rather than blanking it.
    $plain = as_schedule_single_action(time() + 3600, 'fastpix_selfcheck_plain', array(array('video_id' => 8)), Jobs::GROUP_SYNC);
    do_action('action_scheduler_before_execute', $plain, 'selfcheck');
    assert(Log::correlation_id() === $correlation, 'a job without an id does not clear the current one');
    as_unschedule_all_actions('fastpix_selfcheck_plain');

    as_unschedule_all_actions('fastpix_selfcheck_job');
    assert(as_has_scheduled_action('fastpix_selfcheck_job') === false, 'the self-check leaves no scheduled work behind');
} else {
    echo "note: Action Scheduler declined to schedule (its tables may not be installed yet)\n";
}

// ---------------------------------------------------------------- teardown

if ($saved_stalled === null) {
    delete_option(Jobs::OPT_STALLED);
} else {
    update_option(Jobs::OPT_STALLED, $saved_stalled, false);
}

echo "jobs: all checks passed\n";
