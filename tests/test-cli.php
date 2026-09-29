<?php
/**
 * Self-check for Fastpix_Cli — ARCH-07 ("WP-CLI commands exist for large libraries").
 *
 *   docker compose exec wordpress php -d zend.assertions=1 \
 *     /var/www/html/wp-content/plugins/fastpix/tests/test-cli.php
 *
 * WP-CLI is not installed in this container, so the runner is stubbed: the point
 * is that the commands register and behave, not that WP-CLI itself works.
 */

define('WP_USE_THEMES', false);
define('FASTPIX_PLUGIN_DIR', '/var/www/html/wp-content/plugins/fastpix/');
require_once __DIR__ . '/bootstrap.php';

class WP_CLI_Halt extends Exception {}

class WP_CLI {
    public static $commands = array();
    public static $log = array();
    public static $warnings = array();
    public static $success = array();

    public static function add_command($name, $callable) { self::$commands[$name] = $callable; }
    public static function log($message) { self::$log[] = $message; }
    public static function warning($message) { self::$warnings[] = $message; }
    public static function success($message) { self::$success[] = $message; }
    public static function error($message) { throw new WP_CLI_Halt($message); }

    public static function reset() {
        self::$log = self::$warnings = self::$success = array();
    }
}

// Action Scheduler registers its own commands when WP_CLI is defined, and they
// extend this base class.
class WP_CLI_Command {}

define('WP_CLI', true);

foreach (array('credentials', 'cache', 'api-client', 'connection', 'capabilities', 'schema', 'log', 'jobs', 'activation', 'cli') as $class) {
    require_once __DIR__ . '/../includes/class-fastpix-' . $class . '.php';
}

use Fastpix\Fastpix_Cli as Cli;
use Fastpix\Fastpix_Credentials as Creds;
use Fastpix\Fastpix_Schema as Schema;

$saved = array();
foreach (array(Creds::OPT_TOKEN_ID, Creds::OPT_SECRET, 'fastpix_workspace_seen_id', 'fastpix_workspace_seen_name') as $opt) {   // the learned workspace must survive fixtures
    $saved[$opt] = get_option($opt, null);
}
// Restore even when a failed assertion aborts the run — a leaked fixture pair once shadowed the live one.
register_shutdown_function(function () use (&$saved) {
    foreach ($saved as $opt => $value) { $value === null ? delete_option($opt) : update_option($opt, $value, false); }
});

Schema::update();
Fastpix\Fastpix_Jobs::boot();

// --------------------------------------------------------------- registration

Cli::register();

foreach (array('fastpix status', 'fastpix sync', 'fastpix reindex', 'fastpix prune', 'fastpix doctor') as $command) {
    assert(isset(WP_CLI::$commands[$command]), "{$command} is registered [ARCH-07]");
    assert(is_callable(WP_CLI::$commands[$command]), "{$command} is callable");
}

// ------------------------------------------------------------------- status

Creds::forget();
WP_CLI::reset();
Cli::status();

$out = implode("\n", WP_CLI::$log);
assert(strpos($out, 'not connected') !== false, 'status reports a missing connection');
assert(strpos($out, 'Schema:       v' . Schema::current_version()) !== false, 'status reports the schema version [REQ-103]');
assert(strpos($out, 'Action Scheduler ready') !== false, 'status reports the job runner [ARCH-07]');

// -------------------------------------- commands that need a connection say so

WP_CLI::reset();
$halted = false;
try {
    Cli::sync(array(), array());
} catch (WP_CLI_Halt $e) {
    $halted = true;
    assert(strpos($e->getMessage(), 'not connected') !== false, 'sync names the reason it cannot run [REQ-084]');
}
assert($halted, 'a disconnected site cannot sweep');

// ---------------------------------------------------- connected: work queues

Creds::store('4f27c4c4-9a1e-4c2f-9d33-2b8f0a7c1e55', 'test-secret-not-a-real-key');
WP_CLI::reset();

Cli::sync(array(), array());
assert(as_has_scheduled_action('fastpix_new_media_sweep') !== false, 'sync queues the new-media sweep [ARCH-07]');
assert(count(WP_CLI::$success) === 1, 'and reports success');

WP_CLI::reset();
Cli::sync(array(), array('deep' => true));
assert(as_has_scheduled_action('fastpix_deep_sweep') !== false, '--deep queues the deep sweep [ARCH-06]');

WP_CLI::reset();
Cli::reindex();
assert(as_has_scheduled_action('fastpix_search_reindex') !== false, 'reindex queues the rebuild [DATA-013]');
if (get_option('fastpix_fulltext_unavailable')) {
    assert(count(WP_CLI::$warnings) === 1, 'reindex says so when FULLTEXT is unavailable [RULE-044]');
}

WP_CLI::reset();
Cli::prune();
assert(count(WP_CLI::$success) === 1, 'prune reports what it removed [spec 07 retention]');
foreach (array('analytics_daily', 'watch_progress', 'uploads', 'webhook_payloads', 'logs') as $pruned) {
    assert(strpos(WP_CLI::$success[0], $pruned . ':') !== false, "prune covers {$pruned} [spec 07 retention]");
}

// ------------------------------------------------------------------- doctor

WP_CLI::reset();
Cli::doctor();

$doctor = implode("\n", WP_CLI::$log);
assert(count(WP_CLI::$log) >= 7, 'doctor reports every REQ-005 check');
assert(strpos($doctor, 'found:') !== false, 'each line carries the value found [REQ-005]');
assert(preg_match('/^\s+(OK|FAIL|WARN)\s/m', $doctor) === 1, 'each line carries a verdict');
assert(strpos($doctor, 'plugin_conflict') !== false, 'including the advisory conflict check [AMBIG-001]');

// ---------------------------------------------------------------- teardown

foreach (array('fastpix_new_media_sweep', 'fastpix_deep_sweep', 'fastpix_search_reindex') as $hook) {
    as_unschedule_all_actions($hook);
}
foreach ($saved as $opt => $value) {
    if ($value === null) {
        delete_option($opt);
    } else {
        update_option($opt, $value, false);
    }
}

echo "cli: all checks passed\n";
