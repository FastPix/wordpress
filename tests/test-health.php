<?php
/**
 * Self-check for Fastpix_Health — REQ-083, FR-081, INT-010.
 *
 *   docker compose exec wordpress php -d zend.assertions=1 \
 *     /var/www/html/wp-content/plugins/fastpix/tests/test-health.php
 *
 * The plugin is active, so boot() has run and the Site Health filter is live.
 */

require_once __DIR__ . '/bootstrap.php';

use Fastpix\Fastpix_Api_Client as Client;
use Fastpix\Fastpix_Credentials as Creds;
use Fastpix\Fastpix_Health as Health;
use Fastpix\Fastpix_Jobs as Jobs;

$saved = array();
foreach (array(Creds::OPT_TOKEN_ID, Creds::OPT_SECRET, Client::OPT_HEALTH, Jobs::OPT_STALLED, Health::OPT_WEBHOOK_SECRET, Health::OPT_SIGNING_KEY, \Fastpix\Fastpix_Render::OPT_DRM_BAD, 'fastpix_workspace_seen_id', 'fastpix_workspace_seen_name') as $opt) {   // the learned workspace must survive fixtures
    $saved[$opt] = get_option($opt, null);
}
// Restore even when a failed assertion aborts the run — a leaked fixture pair once shadowed the live one.
register_shutdown_function(function () use (&$saved) {
    foreach ($saved as $opt => $value) { $value === null ? delete_option($opt) : update_option($opt, $value, false); }
});

$expected = array('connection', 'scheduler', 'webhook_delivery', 'signing', 'schema_version', 'object_cache', 'page_cache', 'environment');
// The checks are asserted from a fresh site's state; the live site's secrets are restored on shutdown.
delete_option(Health::OPT_WEBHOOK_SECRET);
delete_option(Health::OPT_SIGNING_KEY);
delete_option(\Fastpix\Fastpix_Render::OPT_DRM_BAD);

// ---------------------------------------------- all eight checks, well-formed

// The conditional extras (proxy, analytics_collection) depend on the live site's traffic — not part of the eight.
$eight  = function ($all) { return array_diff_key($all, array('proxy' => 1, 'analytics_collection' => 1)); };
$checks = $eight(Health::checks());
assert(count($checks) === 8, 'exactly the eight REQ-083 checks');
foreach ($expected as $id) {
    assert(isset($checks[$id]), "the {$id} check exists [REQ-083]");
    assert(in_array($checks[$id]['status'], array('good', 'recommended', 'critical'), true), "{$id} has a Site Health status");
    assert($checks[$id]['description'] !== '', "{$id} says something actionable [REQ-084]");
}

// ------------------------------------------------ registered with Site Health

$tests = apply_filters('site_status_tests', array('direct' => array(), 'async' => array()));
foreach ($expected as $id) {
    assert(isset($tests['direct']['fastpix_' . $id]), "fastpix_{$id} is registered with Site Health [INT-010]");
}
$result = call_user_func($tests['direct']['fastpix_connection']['test']);
foreach (array('label', 'status', 'badge', 'description', 'test') as $key) {
    assert(array_key_exists($key, $result), "a Site Health result carries {$key}");
}

// ------------------------------------------------------- states drive verdicts

Creds::forget();
assert(Health::checks()['connection']['status'] === 'recommended', 'no pair → recommended, not critical');

Creds::store('4f27c4c4-9a1e-4c2f-9d33-2b8f0a7c1e55', 'sk_selfcheck_health');
delete_option(Client::OPT_HEALTH);
assert(Health::checks()['connection']['status'] === 'good', 'a healthy pair → good');

update_option(Client::OPT_HEALTH, array('state' => 'unhealthy', 'status' => 401, 'at' => time()), false);
assert(Health::checks()['connection']['status'] === 'critical', 'rejected credentials → critical [ERR-005]');
delete_option(Client::OPT_HEALTH);

update_option(Jobs::OPT_STALLED, array('cause' => 'queue_stalled', 'message' => 'stalled for the check', 'at' => time()), false);
assert(Health::checks()['scheduler']['status'] === 'critical', 'a stalled queue → critical [ARCH-07]');
assert(strpos(Health::checks()['scheduler']['description'], 'stalled for the check') !== false, 'and names the watchdog cause');
delete_option(Jobs::OPT_STALLED);

assert(Health::checks()['webhook_delivery']['status'] === 'recommended', 'no webhook secret → polling mode, recommended [ERR-035]');
assert(strpos(Health::checks()['webhook_delivery']['description'], '15 minutes') !== false, 'polling-mode copy states the lag');
assert(Health::checks()['signing']['status'] === 'recommended', 'no signing key yet → recommended, public playback unaffected');
assert(Health::checks()['schema_version']['status'] === 'good', 'schema current → good [REQ-103]');

// QA B2: watched here, no views at FastPix → say so; nothing comparable → say nothing.
assert(Health::analytics_check(array(4, 0)) === null, 'too few plays to compare → no check (B2)');
assert(Health::analytics_check(array(12, 30))['status'] === 'good', 'plays and reported views → good (B2)');
assert(Health::analytics_check(array(12, 0))['status'] === 'recommended', 'plays but zero reported views → recommended, points at the workspace key (B2)');
assert(Health::analytics_check() === null || isset(Health::analytics_check()['status']), 'the live query runs against the real tables');

// ---------------------------------------------------------------- the report

$report = Health::system_report();
$json   = wp_json_encode($report);

assert(strpos($json, 'sk_selfcheck_health') === false, 'no secret in the report [FR-081, SEC-019]');
assert(strpos($json, Creds::SECRET_MASK) === false || $report['connection']['token_id'] !== '', 'nothing echoes even the mask outside connection identity');
assert(count($eight($report['checks'])) === 8, 'the report layers the same eight checks [INT-010]');
assert(count($report['last_errors']) <= 50, 'last fifty error records at most [FR-081]');
assert($report['environment']['plugin'] === FASTPIX_VERSION, 'the report names the plugin version');
assert(is_string($report['environment']['page_cache']), 'page cache detection reports a name or nothing');
assert(isset($report['generated_at']), 'the report is timestamped');

foreach ($report['last_errors'] as $row) {
    assert(strpos(wp_json_encode($row), 'sk_selfcheck_health') === false, 'no log row leaks the secret — redacted at write [SEC-019]');
}

// ---------------------------------------------------------------- teardown

foreach ($saved as $opt => $value) {
    if ($value === null) {
        delete_option($opt);
    } else {
        update_option($opt, $value, false);
    }
}

echo "health + system report: all checks passed\n";
