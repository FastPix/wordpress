<?php
/**
 * Self-check for the menu gate — ASSUME-028, SEC-011.
 *
 *   docker compose exec wordpress php -d zend.assertions=1 \
 *     /var/www/html/wp-content/plugins/fastpix/tests/test-menu-gate.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';
set_current_screen('dashboard');

use Fastpix\Fastpix_Addmedia;
use Fastpix\Fastpix_Credentials as Creds;
use Fastpix\Fastpix_Menu_Gate as Gate;
use Fastpix\Fastpix_Onboarding;

$saved = array();
foreach (array(Creds::OPT_TOKEN_ID, Creds::OPT_SECRET, Fastpix\Fastpix_Connection::OPT_WORKSPACE_ID, 'fastpix_workspace_seen_id', 'fastpix_workspace_seen_name',   // the learned workspace must survive fixtures
             Fastpix\Fastpix_Connection::OPT_PENDING_LEAVE, Fastpix\Fastpix_Connection::OPT_LEFT_UNKNOWN, Fastpix\Fastpix_Connection::OPT_LAST_TOKEN, Fastpix\Fastpix_Connection::OPT_LAST_KEY) as $opt) {   // the leave machinery must never fire on real data
    $saved[$opt] = get_option($opt, null);
}
// Restore even when a failed assertion aborts the run — a leaked fixture pair once shadowed the live one.
register_shutdown_function(function () use (&$saved) {
    foreach ($saved as $opt => $value) { $value === null ? delete_option($opt) : update_option($opt, $value, false); }
});
foreach (array(Fastpix\Fastpix_Connection::OPT_PENDING_LEAVE, Fastpix\Fastpix_Connection::OPT_LEFT_UNKNOWN, Fastpix\Fastpix_Connection::OPT_LAST_TOKEN, Fastpix\Fastpix_Connection::OPT_LAST_KEY) as $opt) { delete_option($opt); }   // learn_workspace can never wipe

$admins = get_users(array('role' => 'administrator', 'number' => 1, 'fields' => 'ID'));
wp_set_current_user($admins[0]);

function build_menu() {
    global $menu, $submenu, $_wp_submenu_nopriv, $_wp_menu_nopriv, $admin_page_hooks, $_registered_pages;

    $menu = $submenu = $_wp_submenu_nopriv = $_wp_menu_nopriv = $admin_page_hooks = $_registered_pages = array();
    do_action('admin_menu');

    return array(
        'top'  => array_column((array) $menu, 2),
        'subs' => isset($submenu['fastpix-settings']) ? array_column($submenu['fastpix-settings'], 2) : array(),
    );
}

// ------------------------------------------------------------- disconnected

Creds::forget();
$built = build_menu();

assert(in_array(Fastpix_Onboarding::SLUG, $built['top'], true), 'disconnected: the wizard is the one FastPix top-level entry [ASSUME-028]');
assert(!in_array('fastpix-settings', $built['top'], true), 'disconnected: the v1 top-level is gone');
foreach (Gate::GATED_PAGES as $page) {
    assert(!in_array($page, $built['subs'], true), "disconnected: {$page} is not registered");
}

// Direct URLs to gated pages redirect to the wizard.
foreach (array('fastpix-video-library', Fastpix_Addmedia::SLUG, 'fastpix-settings', 'fastpix-settings-screen') as $page) {
    $target = Gate::gated_redirect_url($page);
    assert(is_string($target) && strpos($target, 'page=' . Fastpix_Onboarding::SLUG) !== false, "disconnected: {$page} redirects to the wizard");
}
assert(Gate::gated_redirect_url(Fastpix_Onboarding::SLUG) === null, 'the wizard itself never redirects');
assert(Gate::gated_redirect_url('some-other-plugin') === null, 'other plugins\' pages are left alone');

// REST: routes stay registered and answer 409 with the reason. [ASSUME-028]
$request = new WP_REST_Request('POST', '/fastpix/v1/uploads');
$request->set_header('Content-Type', 'application/json');
$request->set_body(wp_json_encode(array('filename' => 'gate.mp4', 'filesize' => 1000, 'filetype' => 'video/mp4')));
$response = rest_get_server()->dispatch($request);
assert($response->get_status() === 409, 'disconnected /uploads answers 409, not 404 — the route exists');
assert($response->as_error()->get_error_code() === 'fastpix_not_connected', 'with the machine-readable code');
assert(strpos($response->as_error()->get_error_message(), 'Connect a workspace') !== false, 'and the human reason');

$request = new WP_REST_Request('POST', '/fastpix/v1/videos');
$request->set_header('Content-Type', 'application/json');
$request->set_body(wp_json_encode(array('urls' => array('https://203.0.113.9/a.mp4'))));
$response = rest_get_server()->dispatch($request);
assert($response->get_status() === 409, 'disconnected /videos answers 409 too');

$response = rest_get_server()->dispatch(new WP_REST_Request('POST', '/fastpix/v1/sync'));
assert($response->get_status() === 409, 'and /sync');

// SEC-011 unchanged: without the capability the answer is authorisation, not state.
wp_set_current_user(0);
$response = rest_get_server()->dispatch(new WP_REST_Request('POST', '/fastpix/v1/sync'));
assert($response->get_status() === rest_authorization_required_code(), 'capability checks still come first [SEC-011]');
wp_set_current_user($admins[0]);

// -------------------------------- pair alone does NOT unlock [ASSUME-033]

Creds::store('4f27c4c4-9a1e-4c2f-9d33-2b8f0a7c1e55', 'sk_selfcheck_gate');
delete_option(Fastpix\Fastpix_Connection::OPT_WORKSPACE_ID);
$built = build_menu();
assert(in_array(Fastpix_Onboarding::SLUG, $built['top'], true), 'pair saved but no workspace: still the wizard only');
assert(!in_array('fastpix-settings', $built['top'], true), 'the full menu needs BOTH halves [ASSUME-033]');
assert(Gate::gated_redirect_url('fastpix-video-library') !== null, 'gated pages still redirect');

// ---------------------------------------------------------------- connected

Fastpix\Fastpix_Connection::set_workspace_id('9f3c2a10-4b6d-4e2f-8a75-1c9e07d4b210');
$built = build_menu();

assert(in_array('fastpix-settings', $built['top'], true), 'connected: the full IA returns [ASSUME-019]');
assert(!in_array(Fastpix_Onboarding::SLUG, $built['top'], true), 'connected: no duplicate wizard top-level');
foreach (array('fastpix-video-library', Fastpix_Addmedia::SLUG, 'fastpix-analytics', 'fastpix-settings-screen') as $page) {
    assert(in_array($page, $built['subs'], true), "connected: {$page} is a destination");
}
assert(in_array('fastpix-analytics', Gate::GATED_PAGES, true), 'Analytics is behind the gate like every other workspace screen (S10)');

// S5: a pair whose secret no longer decrypts (rotated salts) is NOT connected — the menu collapses to the wizard.
$sealed = get_option(Creds::OPT_SECRET);
update_option(Creds::OPT_SECRET, 'g1:' . base64_encode(random_bytes(40)), false);
assert(Creds::has_pair() && Creds::is_unreadable(), 'fixture: stored but unreadable');
assert(Fastpix\Fastpix_Connection::workspace_ready() === false && Fastpix\Fastpix_Connection::state()['connected'] === false, 'an unreadable secret reads as not connected (S5)');
$built = build_menu();
assert(in_array(Fastpix_Onboarding::SLUG, $built['top'], true) && !in_array('fastpix-settings', $built['top'], true), 'and the menu is the wizard only (S5)');
update_option(Creds::OPT_SECRET, $sealed, false);
$built = build_menu();   // back to the connected IA for the assertions below

// S11: an administrator stripped of every plugin capability (clone / role plugin) is refilled on admin_init.
$admin_role = get_role('administrator');
foreach (Fastpix\Fastpix_Capabilities::all() as $cap) { $admin_role->remove_cap($cap); }
try {
    assert(!get_role('administrator')->has_cap(Fastpix\Fastpix_Capabilities::MANAGE_SETTINGS), 'fixture: caps gone');
    Fastpix\Fastpix_Capabilities::ensure();
    assert(get_role('administrator')->has_cap(Fastpix\Fastpix_Capabilities::MANAGE_SETTINGS), 'ensure() reinstalls the defaults (S11)');
} finally {
    Fastpix\Fastpix_Capabilities::install();   // never leave the docker admin without its menu
}
$editor_role = get_role('editor');
if ($editor_role) {   // a deliberate single-cap remap is respected: ensure() only acts on a role holding NOTHING
    $editor_role->remove_cap(Fastpix\Fastpix_Capabilities::DELETE_VIDEO);
    Fastpix\Fastpix_Capabilities::ensure();
    assert(!get_role('editor')->has_cap(Fastpix\Fastpix_Capabilities::DELETE_VIDEO), 'a remapped role is left alone by the self-heal (S11)');
    $editor_role->add_cap(Fastpix\Fastpix_Capabilities::DELETE_VIDEO);
}
// Connected: Connection is NOT listed (Settings owns account actions) but the
// page stays URL-reachable for "Run setup again".
assert(!in_array(Fastpix_Onboarding::SLUG, $built['subs'], true), 'connected: no Connection menu entry');
global $_registered_pages;
assert(!empty($_registered_pages['admin_page_' . Fastpix_Onboarding::SLUG]), 'connected: the wizard stays reachable by URL');
// The v1 pages are retired: no Upload Video page, no self-titled Settings entry.
assert(!in_array('fastpix-upload', $built['subs'], true), 'the v1 Upload Video page is gone');
assert(!in_array('fastpix-settings', $built['subs'], true), 'the top level is a container, not a self-listed destination');
assert(Gate::gated_redirect_url('fastpix-video-library') === null, 'connected: no redirect');

// ------------------------------------------------- disconnect collapses back

Creds::forget();
$built = build_menu();
assert(in_array(Fastpix_Onboarding::SLUG, $built['top'], true), 'after disconnect the menu collapses to the wizard [ASSUME-028]');
assert(!in_array('fastpix-settings', $built['top'], true), 'and the destinations are gone again');

// ---------------------------------------------------------------- teardown

foreach ($saved as $opt => $value) {
    if ($value === null) { delete_option($opt); } else { update_option($opt, $value, false); }
}
wp_set_current_user(0);

echo "menu gate: all checks passed\n";
