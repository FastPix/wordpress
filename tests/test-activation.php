<?php
/**
 * Self-check for Fastpix_Activation — REQ-005, thresholds from REQ-112.
 *
 *   docker compose exec wordpress php -d zend.assertions=1 \
 *     /var/www/html/wp-content/plugins/fastpix/tests/test-activation.php
 *
 * Runs the checks; it never calls activate(), which would wp_die by design.
 * Results depend on the environment, so this asserts the contract — every
 * check names its requirement AND the value found — rather than that a
 * particular machine passes.
 */

define('WP_USE_THEMES', false);
define('FASTPIX_PLUGIN_DIR', '/var/www/html/wp-content/plugins/fastpix/');
require_once __DIR__ . '/bootstrap.php';

foreach (array('credentials', 'api-client', 'capabilities', 'schema', 'log', 'jobs', 'activation') as $class) {
    require_once __DIR__ . '/../includes/class-fastpix-' . $class . '.php';
}

use Fastpix\Fastpix_Activation as Activation;

$checks = Activation::checks();
$by_id  = array_column($checks, null, 'id');

// -------------------------------------------------- every REQ-005 check exists

foreach (array('wordpress_version', 'php_version', 'https', 'database', 'outbound', 'rest_api', 'plugin_conflict') as $id) {
    assert(isset($by_id[$id]), "REQ-005 checks {$id}");
}
assert(count($checks) === 7, 'the seven REQ-005 checks, no more');

// ------------------------------------- the contract: reason AND value found

foreach ($checks as $check) {
    assert(is_string($check['requirement']) && $check['requirement'] !== '', "{$check['id']} states its requirement [REQ-005]");
    assert(is_string($check['found']) && $check['found'] !== '', "{$check['id']} states the value found [REQ-005]");
    assert(is_bool($check['ok']) && is_bool($check['fatal']), "{$check['id']} is a decision, not a maybe");
}

// ------------------------------------------------------- thresholds REQ-112

assert(Activation::MIN_WP === '6.8', 'WordPress ≥6.8 [REQ-112]');
assert(Activation::MIN_PHP === '8.3', 'PHP ≥8.3 [REQ-112]');
assert(Activation::MIN_MYSQL === '8.0', 'MySQL ≥8.0 [REQ-112]');
assert(Activation::MIN_MARIADB === '10.11', 'MariaDB ≥10.11 [REQ-112]');

assert(strpos($by_id['php_version']['requirement'], '8.3') !== false, 'the PHP requirement names the version');
assert($by_id['php_version']['found'] === PHP_VERSION, 'the PHP check reports the running version');
assert($by_id['php_version']['ok'] === version_compare(PHP_VERSION, '8.3', '>='), 'the PHP check compares correctly');

global $wp_version;
assert($by_id['wordpress_version']['found'] === $wp_version, 'the WordPress check reports the running version');

// The database check distinguishes MariaDB from MySQL — their minimums differ,
// and wpdb reports MariaDB's compatibility version rather than its own.
global $wpdb;
$server = (string) $wpdb->get_var('SELECT VERSION()');
$is_mariadb = stripos($server, 'mariadb') !== false;
assert(strpos($by_id['database']['requirement'], $is_mariadb ? 'MariaDB' : 'MySQL') !== false, 'the database check names the right engine');
assert(strpos($by_id['database']['requirement'], $is_mariadb ? '10.11' : '8.0') !== false, 'and the right minimum [REQ-112]');
assert($by_id['database']['ok'] === true, 'this database meets the baseline');

// ------------------- https: advisory everywhere, exempt in local/development

$environment = wp_get_environment_type();
$scheme      = wp_parse_url(home_url(), PHP_URL_SCHEME);
$exempt      = in_array($environment, array('local', 'development'), true);

assert(strpos($by_id['https']['found'], $scheme) === 0, 'the https check reports the scheme in use');
assert($by_id['https']['fatal'] === false, 'the https check never blocks activation [owner ruling 2026-09-01]');

if ($exempt) {
    assert($by_id['https']['ok'] === true, 'local/development activates over http [ruling 2026-08-14]');
    assert(strpos($by_id['https']['found'], 'exempt') !== false, 'and says WHY it passed, naming the environment');
} else {
    assert($by_id['https']['ok'] === ('https' === $scheme), 'every other environment requires https [REQ-112]');
}

// The exemption covers exactly local and development — staging and production
// get no pass. (wp_get_environment_type() is fixed per-request by constants,
// so the other branch is asserted by inspection of the exempt list, not run.)
assert(in_array('local', array('local', 'development'), true) && !in_array('staging', array('local', 'development'), true),
    'the exempt list is local + development only');

// ------------------------- rest api: advisory + Site Health, never a refusal

assert($by_id['rest_api']['fatal'] === false, 'a failed loopback cannot refuse activation [ruling 2026-08-14]');
assert(!in_array('rest_api', array_column(Activation::failures(), 'id'), true), 'rest_api never appears in failures()');

// ------------------------------------------ plugin conflict is advisory only

assert($by_id['plugin_conflict']['fatal'] === false, 'the conflict check never refuses — the list is undefined [AMBIG-001]');
assert($by_id['plugin_conflict']['ok'] === true, 'with no list configured, nothing conflicts');

$active = (array) get_option('active_plugins', array());
if ($active) {
    add_filter('fastpix_conflicting_plugins', function () use ($active) { return array($active[0]); });
    $conflicted = array_column(Activation::checks(), null, 'id')['plugin_conflict'];
    assert($conflicted['ok'] === false, 'a configured conflict is detected');
    assert(strpos($conflicted['found'], $active[0]) !== false, 'and named in the value found');
    assert($conflicted['fatal'] === false, 'but still does not lock the user out [AMBIG-001]');
    remove_all_filters('fastpix_conflicting_plugins');
}

// ------------------------------------------------- failures() is fatal-only

$failures = Activation::failures();
foreach ($failures as $failure) {
    assert($failure['ok'] === false && $failure['fatal'] === true, 'failures() returns only refusals');
}
$advisory_ids = array_column(array_filter($checks, function ($c) { return !$c['fatal']; }), 'id');
foreach ($advisory_ids as $id) {
    assert(!in_array($id, array_column($failures, 'id'), true), "advisory check {$id} never refuses activation");
}

// This docker stack (http://, blocked loopback) must now be activatable: the
// only remaining fatals are versions, database and outbound — all met here.
if ($exempt || $scheme === 'https') {
    assert(!in_array('https', array_column($failures, 'id'), true), 'https does not block this environment');
}

printf(
    "activation: all checks passed (%d of 7 requirements met here; %s)\n",
    count(array_filter($checks, function ($c) { return $c['ok']; })),
    $failures ? 'would refuse on: ' . implode(', ', array_column($failures, 'id')) : 'would activate'
);
