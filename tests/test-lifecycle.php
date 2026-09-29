<?php
/**
 * Self-check for plugin lifecycle & compliance — WF-012, REQ-101/102/110,
 * TEST-013/024/043 (the parts checkable without deactivating the live site).
 *
 *   docker compose exec wordpress php -d zend.assertions=1 \
 *     /var/www/html/wp-content/plugins/fastpix/tests/test-lifecycle.php
 *
 * Uninstall is exercised through its manifest and its option-off no-op —
 * never by dropping this site's real tables.
 */

require_once __DIR__ . '/bootstrap.php';

use Fastpix\Fastpix_Activation as Activation;
use Fastpix\Fastpix_Credentials as Creds;
use Fastpix\Fastpix_Schema as Schema;

global $wpdb;

$saved = array();
foreach (array(Creds::OPT_TOKEN_ID, Creds::OPT_SECRET, 'fastpix_delete_on_uninstall') as $opt) {
    $saved[$opt] = get_option($opt, null);
}
$fixture_attachment = 0;
register_shutdown_function(function () use (&$saved, &$fixture_attachment) {
    foreach ($saved as $opt => $value) { $value === null ? delete_option($opt) : update_option($opt, $value, false); }
    if ($fixture_attachment) { wp_delete_attachment($fixture_attachment, true); }
});

/* ---------------------------------------------- uninstall.php [REQ-102] */

// Loading the file with the setting off must remove NOTHING — that is the
// shipped default, and this run happens against the live site's tables.
update_option('fastpix_delete_on_uninstall', false, false);
if (!defined('WP_UNINSTALL_PLUGIN')) {
    define('WP_UNINSTALL_PLUGIN', 'fastpix-io/fastpix-io.php');
}
define('FASTPIX_UNINSTALL_INSPECT', true);   // loading the file must not run it against this live site
$tables_before = count($wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($wpdb->prefix . 'fastpix_') . '%')));
assert($tables_before >= count(Schema::tables()));
require_once dirname(__DIR__) . '/uninstall.php';
// The file must actually EXECUTE to the bottom: a function_exists() guard there used to fire on the
// first include too (PHP hoists the declarations), so the runner never ran and delete-on-uninstall
// removed nothing, ever — while this assertion still passed. Prove the file ran, then prove the
// flag-off run is the no-op. (QA 2026-09-22)
assert(defined('FASTPIX_UNINSTALL_LOADED'), 'uninstall.php executed past its double-include guard');
assert(function_exists('fastpix_uninstall_run'), 'and defined its runner');
fastpix_uninstall_run();                                             // flag is off: must remove nothing
$tables_after = count($wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($wpdb->prefix . 'fastpix_') . '%')));
assert($tables_after === $tables_before);                            // option off → no-op
assert(get_option(Creds::OPT_TOKEN_ID, null) !== null || $saved[Creds::OPT_TOKEN_ID] === null);   // options untouched

// The manifest names everything REQ-102 lists: every registry table, the
// option/transient patterns, proxy attachments, the export directory.
$fixture_attachment = wp_insert_post(array(
    'post_type' => 'attachment', 'post_mime_type' => 'video/fastpix',
    'post_title' => 'lifecycle-fixture-proxy', 'post_status' => 'inherit',
));
assert($fixture_attachment > 0);
$manifest = fastpix_uninstall_manifest();
assert(count($manifest['tables']) === count(Schema::tables()));
foreach (Schema::tables() as $name) {
    assert(in_array(Schema::table($name), $manifest['tables'], true));
}
$patterns = implode(' ', $manifest['option_patterns']);
assert(strpos($patterns, 'fastpix\_%') === 0);
assert(strpos($patterns, '\_transient\_fastpix\_') !== false);
assert(strpos($patterns, '\_transient\_timeout\_fastpix\_') !== false);
assert(strpos($patterns, '\_site\_transient\_fastpix\_') !== false);
assert(in_array($fixture_attachment, $manifest['attachment_ids'], true));
assert(strpos($manifest['dirs'][0], 'fastpix-exports') !== false);

// S9 / S18: the removal is per site (walked over a network) and clears Action
// Scheduler's log lines and groups with the actions — asserted on the source,
// since running it would drop this site's tables.
$uninstall_src = (string) file_get_contents(dirname(__DIR__) . '/uninstall.php');
assert(function_exists('fastpix_uninstall_site') && strpos($uninstall_src, 'switch_to_blog') !== false, 'multisite: every site is walked (S9)');
assert(strpos($uninstall_src, 'fastpix-shortcode-fallback.php') !== false, 'deleting the plugin removes its shortcode fallback (QA #16)');
assert(strpos($uninstall_src, 'actionscheduler_logs') !== false && strpos($uninstall_src, 'actionscheduler_groups') !== false, 'Action Scheduler logs and groups go with the actions (S18)');

/* ------------------------------------- deactivation & reactivation [REQ-101] */

// Deactivation registers exactly one job: unschedule everything. Nothing that
// deletes data or edits posts hooks deactivation.
$deactivate_hook = 'deactivate_' . plugin_basename(FASTPIX_PLUGIN_DIR . 'fastpix-io.php');
assert((bool) has_action($deactivate_hook, array(\Fastpix\Fastpix_Jobs::class, 'unschedule_all')));

// Reactivation sweep: nothing without credentials; one deep sweep with them.
delete_option(Creds::OPT_TOKEN_ID);
delete_option(Creds::OPT_SECRET);
as_unschedule_all_actions('fastpix_deep_sweep');
Activation::reactivation_sweep();
assert(as_next_scheduled_action('fastpix_deep_sweep') === false);

Creds::store('4f27c4c4-9a1e-4c2f-9d33-2b8f0a7c1e55', 'sk_selfcheck_lifecycle');
Activation::reactivation_sweep();
assert(as_next_scheduled_action('fastpix_deep_sweep') !== false);
as_unschedule_all_actions('fastpix_deep_sweep');   // the bootstrap re-seeds the recurring schedule on shutdown

/* --------------------------------------------------- compliance [REQ-110] */

$readme = (string) file_get_contents(dirname(__DIR__) . '/readme.txt');
assert(strpos($readme, 'Stable tag: 2.0.0') !== false);
assert(strpos($readme, 'License: GPLv2 or later') !== false);
assert(strpos($readme, '== External services ==') === false);        // section is a heading INSIDE Description
assert(strpos($readme, 'External services') !== false);              // the disclosure exists
assert(strpos($readme, 'api.fastpix.com') !== false);                // enumerated endpoints
assert(strpos($readme, 'live.fastpix.com') !== false);               // ingest host — must match the .com hosts the code serves (OQ-005)
assert(strpos($readme, 'Data sent to FastPix') !== false);           // enumerated data list
assert(strpos($readme, 'Action Scheduler') !== false);               // bundled GPL declaration
assert(strpos($readme, 'privacy-policy') !== false);
assert(stripos($readme, 'delete-on-uninstall') !== false || stripos($readme, 'Delete plugin data on uninstall') !== false);

$header = (string) file_get_contents(dirname(__DIR__) . '/fastpix-io.php');
assert(strpos($header, 'Version: 2.0.0') !== false);
assert(strpos($header, 'Requires at least: 6.8') !== false);
assert(strpos($header, 'Requires PHP: 8.3') !== false);
assert(strpos($header, 'License: GPLv2 or later') !== false);   // matches readme.txt's "GPLv2 or later"

// The wizard carries the disclosure too — since ASSUME-062 it is the Welcome
// step's "What FastPix sends" box (.fp-land-sends), not an under-card paragraph. [REQ-110]
$wizard = (string) file_get_contents(dirname(__DIR__) . '/templates/onboarding-wizard.php');
assert(strpos($wizard, 'fp-land-sends') !== false, 'the Welcome sends-box carries the REQ-110 disclosure [ASSUME-062]');
assert(strpos($wizard, 'privacy-policy') !== false);

// Headers agree with the activation thresholds — one source of truth drifting
// from the other would refuse users the readme said were supported.
assert(strpos($readme, 'Requires at least: ' . Activation::MIN_WP) !== false);
assert(strpos($readme, 'Requires PHP: ' . Activation::MIN_PHP) !== false);

echo "test-lifecycle: OK\n";
