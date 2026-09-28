<?php
/**
 * Self-check for the UI-001 wizard page — REQ-004, REQ-007, SEC-017, FR-004.
 *
 *   docker compose exec wordpress php -d zend.assertions=1 \
 *     /var/www/html/wp-content/plugins/fastpix/tests/test-onboarding.php
 *
 * Renders the template for both roles and asserts on the markup: the wizard's
 * behaviour is JS, but what the server must and must not RENDER (disclosure
 * before credentials, no fields for restricted roles, no secret anywhere) is
 * checkable here.
 */

require_once __DIR__ . '/bootstrap.php';
// Firing admin hooks outside wp-admin: other active plugins (Cloudinary) call
// get_current_screen() etc. in their handlers, so give them a real admin context.
require_once ABSPATH . 'wp-admin/includes/admin.php';
set_current_screen('admin_page_' . Fastpix\Fastpix_Onboarding::SLUG);

use Fastpix\Fastpix_Capabilities as Caps;
use Fastpix\Fastpix_Credentials as Creds;
use Fastpix\Fastpix_Onboarding as Onboarding;

const FIXTURE_TOKEN_ID = '4f27c4c4-9a1e-4c2f-9d33-2b8f0a7c1e55';
const MSG_WHAT_FASTPIX_SENDS = 'What FastPix sends';

$saved = array();
foreach (array(Creds::OPT_TOKEN_ID, Creds::OPT_SECRET, Fastpix\Fastpix_Webhooks::OPT_SECRET, Fastpix\Fastpix_Connection::OPT_WORKSPACE_ID, 'fastpix_workspace_seen_id', 'fastpix_workspace_seen_name') as $opt) {   // the learned workspace must survive fixtures
    $saved[$opt] = get_option($opt, null);
}
// Restore even when a failed assertion aborts the run — a leaked fixture pair once shadowed the live one.
register_shutdown_function(function () use (&$saved) {
    foreach ($saved as $opt => $value) { $value === null ? delete_option($opt) : update_option($opt, $value, false); }
});

function render_page() {
    ob_start();
    Onboarding::render();

    return ob_get_clean();
}

$admins = get_users(array('role' => 'administrator', 'number' => 1, 'fields' => 'ID'));
assert(!empty($admins), 'the test site has an administrator');

// ------------------------------------------------------------- menu + assets

wp_set_current_user($admins[0]);
// The connected IA is what this asserts; disconnected menus are test-menu-gate's.
Creds::store(FIXTURE_TOKEN_ID, 'sk_selfcheck_onboarding_menu');
// workspace_ready() needs BOTH the pair and a workspace id. This block used to rely on the live
// site already having one, so it failed on a site that had never connected. (QA 2026-09-23)
update_option(Fastpix\Fastpix_Connection::OPT_WORKSPACE_ID, '1209042455454187521', false);
do_action('admin_menu');

global $submenu, $_registered_pages;
$slugs = array_column(isset($submenu['fastpix-settings']) ? $submenu['fastpix-settings'] : array(), 2);
// Connected: Connection is not a menu destination (Settings owns the account
// actions) but stays reachable by URL for "Run setup again".
assert(!in_array(Onboarding::SLUG, $slugs, true), 'connected: Connection is not listed in the menu');
assert(!empty($_registered_pages['admin_page_' . Onboarding::SLUG]), 'but the page is registered for direct access');

do_action('admin_enqueue_scripts', 'fastpix_page_' . Onboarding::SLUG);
assert(wp_style_is('fastpix-onboarding', 'enqueued'), 'the wizard styles enqueue on its page');
assert(wp_script_is('fastpix-onboarding', 'enqueued'), 'the wizard script enqueues on its page');

$localized = wp_scripts()->get_data('fastpix-onboarding', 'data');
assert(strpos($localized, 'restUrl') !== false && strpos($localized, 'fastpix/v1') !== false, 'the script knows the REST namespace');
assert(strpos($localized, '"nonce"') !== false, 'and carries the wp_rest nonce [SEC-011]');

// ------------------------------------------------- full wizard, disconnected

Creds::forget();
delete_option(Fastpix\Fastpix_Webhooks::OPT_SECRET);   // the wizard is asserted in its fresh, polling-mode state
$html = render_page();

// Disclosure comes before a credential is ever entered. Since ASSUME-058/062 it
// is the Welcome step's "What FastPix sends" box with the frame's copy. [REQ-007, SEC-017]
assert(strpos($html, MSG_WHAT_FASTPIX_SENDS) !== false, 'the disclosure block renders [ASSUME-058]');
foreach (array('Video files that you upload', 'Video metadata such as titles and descriptions', 'Playback and engagement events when analytics are enabled') as $item) {
    assert(strpos($html, $item) !== false, 'the data-sent list is enumerated [SEC-017, ASSUME-058]');
}
assert(strpos($html, 'No other content from your WordPress site is shared') !== false, 'and closed [SEC-017, ASSUME-058]');
assert(strpos($html, 'fastpix.com/privacy-policy') !== false && strpos($html, 'fastpix.com/terms-and-conditions') !== false, 'terms + privacy linked [ASSUME-062]');
assert(strpos($html, MSG_WHAT_FASTPIX_SENDS) < strpos($html, 'fp-token-id'), 'disclosure precedes the credential fields [REQ-007]');

// The wizard structure per UI-001 as re-matched to Figma: Welcome (ASSUME-058)
// → Connect FastPix (ASSUME-059..061) → Workspace & webhooks (ASSUME-063/064)
// → Done (ASSUME-065).
foreach (array('Welcome', 'Connect FastPix', 'Workspace &amp; webhooks',
             'Video infrastructure for WordPress', 'Get started',
             'Connect your FastPix account', 'Access token', 'Secret key', 'Get a token in FastPix',
             'Manage → Access Tokens', 'Watch youtube video',
             'Link your workspace', 'Workspace key', 'Save workspace',
             'Receive media status updates automatically', 'What is a webhook?',
             'Signing secret', 'Save &amp; verify', 'fastpix/v1/webhook', 'Skip webhook setup',
             'Finish setup', 'Open Plugin dashboard') as $marker) {
    assert(strpos($html, $marker) !== false, "the wizard renders: {$marker} [UI-001]");
}
assert(strpos($html, 'type="password"') !== false, 'the secret field is a password input');
assert(strpos($html, 'fp-webhook-skip') !== false, 'the wizard webhooks card offers Skip [ASSUME-033]');
assert(strpos($html, 'Or upload your first video') === false, 'the Done step has one action only [ASSUME-065]');
assert(strpos($html, 'data-fp-webhook-note') !== false, 'the polling footnote renders [ERR-035, ASSUME-065]');
assert(strpos($html, 'migration') === false, 'the done screen promises no auto-migration [ASSUME-018, REQ-022]');

// ------------------------------------- connected: the owner reads and edits what they entered

// Owner ruling 2026-09-23 (as on the Settings screen): a fastpix_manage_settings user sees the
// stored pair and can edit it — a value you cannot see is one you cannot check. The secret sits
// behind the field's own Show/Hide. Connection::state() stays masked: it is a REST shape too.
Creds::store(FIXTURE_TOKEN_ID, 'sk_live_onboarding_check');
$html = render_page();

assert(strpos($html, 'sk_live_onboarding_check') !== false, 'the stored secret is shown to the owner');
assert(strpos($html, 'id="fp-secret"') !== false && strpos($html, 'type="password"') !== false, 'dotted until Show is pressed');
assert(strpos($html, FIXTURE_TOKEN_ID) !== false, 'the access token id is shown in full — an identifier, not a secret');
$state = Fastpix\Fastpix_Connection::state();
assert($state['secret'] === Creds::masked_secret() && $state['token_id'] === Creds::masked_token_id(), 'but the REST state shape carries masks only');
// B4: a connected visit ("Run setup again") has content before the script runs — the opening step is not hidden.
delete_option(Fastpix\Fastpix_Connection::OPT_WORKSPACE_ID);   // snapshot-restored on shutdown
$html = render_page();
assert(preg_match('/<section data-fp-view="wsweb">/', $html) === 1, 'connected without a workspace opens on step 3, un-hidden (B4)');
assert(preg_match('/<section data-fp-view="connect" hidden>/', $html) === 1, 'the other steps stay hidden for the script to switch');
update_option(Fastpix\Fastpix_Connection::OPT_WORKSPACE_ID, '980293090846277633', false);
assert(preg_match('/<section data-fp-view="connect">/', render_page()) === 1, 'connected with a workspace opens on the Connect step for a revisit (B4)');
delete_option(Fastpix\Fastpix_Connection::OPT_WORKSPACE_ID);

// S5: an unreadable secret (rotated salts) renders the wizard as NOT connected and says why.
$sealed = get_option(Creds::OPT_SECRET);
update_option(Creds::OPT_SECRET, 'g1:' . base64_encode(random_bytes(40)), false);
$html = render_page();
assert(strpos($html, 'data-fp-connected="0"') !== false, 'an unreadable pair is not "connected" to the wizard (S5)');
assert(strpos($html, 'can no longer be read') !== false, 'and the wizard names the cause (S5)');
update_option(Creds::OPT_SECRET, $sealed, false);

// S19: the wizard's type is bundled — no fonts.googleapis.com request from wp-admin [REQ-112].
$fonts_src = wp_styles()->registered['fastpix-onboarding-fonts']->src;
assert(strpos($fonts_src, 'fonts.googleapis.com') === false && strpos($fonts_src, 'assets/css/fonts.css') !== false, 'the font handle points at the bundled stylesheet (S19)');
assert(file_exists(FASTPIX_PLUGIN_DIR . 'assets/fonts/inter-latin.woff2'), 'and the font files ship with the plugin');

// -------------------------------------------------------------- restricted

$author_ids = get_users(array('role' => 'author', 'number' => 1, 'fields' => 'ID'));
if (!$author_ids) {
    $author_ids = array(wp_insert_user(array(
        'user_login' => 'fastpix_selfcheck_author',
        'user_pass'  => wp_generate_password(24),
        'role'       => 'author',
    )));
}
wp_set_current_user($author_ids[0]);
assert(current_user_can(Caps::VIEW_VIDEOS), 'authors hold fastpix_view_videos [03 §2]');
assert(!current_user_can(Caps::MANAGE_SETTINGS), 'authors do not hold CAP-01');

$html = render_page();

assert(strpos($html, 'Credentials are limited to one capability') !== false, 'the restricted notice renders [UI-001 restricted]');
assert(strpos($html, '<input') === false, 'no fields rendered at all — not merely disabled [REQ-004]');
assert(strpos($html, 'sk_live_onboarding_check') === false, 'no secret for restricted roles either');
assert(strpos($html, Creds::SECRET_MASK) === false, 'not even the mask');
assert(strpos($html, 'Connected') !== false, 'connection state is visible');
assert(strpos($html, 'Go to Videos') !== false, 'with the CTA [UI-001 restricted]');
assert(strpos($html, MSG_WHAT_FASTPIX_SENDS) === false, 'the wizard body is absent, not hidden');

// ---------------------------------------------------------------- teardown

wp_set_current_user(0);
foreach ($saved as $opt => $value) {
    if ($value === null) {
        delete_option($opt);
    } else {
        update_option($opt, $value, false);
    }
}

echo "onboarding wizard: all checks passed\n";
