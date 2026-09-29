<?php
/**
 * Self-check for the Settings screen + minimal /settings route — UI-006,
 * FR-080, ASSUME-030, REQ-102, ASSUME-107 (no secret in the page, B3).
 *
 *   docker compose exec wordpress php -d zend.assertions=1 \
 *     /var/www/html/wp-content/plugins/fastpix/tests/test-settings-page.php
 */

require_once __DIR__ . '/bootstrap.php';

use Fastpix\Fastpix_Credentials as Creds;
use Fastpix\Fastpix_Settings_Page as Page;
use Fastpix\Fastpix_Webhooks as Webhooks;

const REST_SETTINGS = '/settings';
const SELFCHECK_TOKEN_ID = '4f27c4c4-9a1e-4c2f-9d33-2b8f0a7c1e55';

$saved = array();
foreach (array(Creds::OPT_TOKEN_ID, Creds::OPT_SECRET, Webhooks::OPT_SECRET, Webhooks::OPT_SECRET_PREV, Webhooks::OPT_SECRET_AT, Webhooks::OPT_LAST_REJECT, Fastpix\Fastpix_Connection::OPT_CONNECTED_AT, Fastpix\Fastpix_Connection::OPT_LAST_TOKEN, Fastpix\Fastpix_Api_Client::OPT_HEALTH, Page::OPT_DELETE_ON_UNINSTALL, Fastpix\Fastpix_Lms::OPT_ENABLED, Fastpix\Fastpix_Render::OPT_SEO, 'fastpix_workspace_seen_id', 'fastpix_workspace_seen_name') as $opt) {   // the learned workspace must survive fixtures
    $saved[$opt] = get_option($opt, null);
}
// Restore even when a failed assertion aborts the run — a leaked fixture pair once shadowed the live one.
register_shutdown_function(function () use (&$saved) {
    foreach ($saved as $opt => $value) { $value === null ? delete_option($opt) : update_option($opt, $value, false); }
});
Creds::store(SELFCHECK_TOKEN_ID, 'sk_selfcheck_settings');
Webhooks::set_secret('');
delete_option(Page::OPT_DELETE_ON_UNINSTALL);

$admins = get_users(array('role' => 'administrator', 'number' => 1, 'fields' => 'ID'));
wp_set_current_user($admins[0]);

function sreq($method, $path, $body = null) {
    $request = new WP_REST_Request($method, '/fastpix/v1' . $path);
    if ($body !== null) {
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(wp_json_encode($body));
    }

    return rest_get_server()->dispatch($request);
}

// ------------------------------------------------------- the minimal route

$response = sreq('GET', REST_SETTINGS);
assert(!$response->is_error(), '/settings is registered [ASSUME-030]');
$settings = $response->get_data();
assert($settings['webhook_configured'] === false, 'webhook state reads');
assert(strpos($settings['webhook_url'], 'fastpix/v1/webhook') !== false, 'the receiver address is given for the dashboard');
assert($settings['delete_on_uninstall'] === false, 'delete-on-uninstall defaults OFF [REQ-102]');
assert(!array_key_exists('webhook_secret', $settings), 'the secret is write-only — never read back');

// Save the secret through the route: "Instant updates".
$whsec = 'whsec_settings_check_9RtP2xW7';
$response = sreq('PATCH', REST_SETTINGS, array('webhook_secret' => $whsec));
assert($response->get_data()['webhook_configured'] === true, 'saving the secret turns instant updates on');
assert(Webhooks::secret() === $whsec, 'stored where the receiver reads it');
assert(strpos((string) get_option(Webhooks::OPT_SECRET), $whsec) === false, 'and encrypted at rest [DATA-016]');
assert(strpos(wp_json_encode($response->get_data()), $whsec) === false, 'the response never echoes it [REQ-003 discipline]');

$response = sreq('PATCH', REST_SETTINGS, array('delete_on_uninstall' => true));
assert($response->get_data()['delete_on_uninstall'] === true, 'the uninstall opt-in saves');

// S14: the LMS args are declared, so the string "false" is false and the retention range is enforced.
update_option(Fastpix\Fastpix_Lms::OPT_ENABLED, true, false);
$response = sreq('PATCH', REST_SETTINGS, array('lms_enabled' => 'false'));
assert(!$response->is_error() && $response->get_data()['lms_enabled'] === false, 'lms_enabled "false" turns course features OFF (S14)');
assert(sreq('PATCH', REST_SETTINGS, array('lesson_retention_days' => 0))->get_status() === 400, 'an out-of-range retention is refused by the schema (S14)');

// Structured data defaults ON, so OFF must stick on a never-saved option: update_option(false) is a
// silent no-op there and the reload read the default again. (QA: structured data save)
delete_option(Fastpix\Fastpix_Render::OPT_SEO);
sreq('PATCH', REST_SETTINGS, array('structured_data' => false));
assert(sreq('GET', REST_SETTINGS)->get_data()['structured_data'] === false, 'structured data OFF survives a reload on a fresh install');
sreq('PATCH', REST_SETTINGS, array('structured_data' => true));
assert(sreq('GET', REST_SETTINGS)->get_data()['structured_data'] === true, 'and ON saves back');

// Anonymous and non-settings roles are refused.
wp_set_current_user(0);
assert(sreq('GET', REST_SETTINGS)->is_error(), 'anonymous cannot read settings [SEC-011]');
$author_ids = get_users(array('role' => 'author', 'number' => 1, 'fields' => 'ID'));
if ($author_ids) {
    wp_set_current_user($author_ids[0]);
    assert(sreq('PATCH', REST_SETTINGS, array('webhook_secret' => 'nope'))->is_error(), 'authors cannot write the webhook secret [REQ-004]');
    assert(Webhooks::secret() === $whsec, 'and the stored secret is untouched');
}
wp_set_current_user($admins[0]);

// ------------------------------------------------------------- the screen

ob_start();
Page::render();
$html = ob_get_clean();

// The redesign (2026-08-18): Video setup + Account, footer links + support report.
foreach (array('FastPix settings', 'Video setup', 'Account', 'Workspace key', 'DRM configuration ID', 'Webhook URL', 'Signing secret',
               'Private link lifetime', 'Emit structured data for public videos', 'Send test event', 'Verify',
               'Delete local data on uninstall', 'Disconnecting stops playback', 'Copy support report', 'Plugin version') as $marker) {
    assert(strpos($html, $marker) !== false, "the screen renders: {$marker} [UI-006]");
}
assert(strpos($html, 'Save &amp; verify') !== false, 'the signing secret has its Save & verify action');
assert(strpos($html, 'fp-webhook-skip') === false, 'Skip is the wizard\'s button — not on Settings [ASSUME-033]');
assert(strpos($html, 'https://fastpix.com/contact-us') !== false && strpos($html, 'https://fastpix.com/docs') !== false && strpos($html, 'https://status.fastpix.io') !== false, 'footer links resolve to live pages (checked 2026-08-18)');

// No mismatch warning anywhere (owner ruling 2026-08-19); the Account card names the workspace only once a delivery has.
assert(strpos($html, 'name a different workspace') === false, 'no mismatch warning renders');
// Owner ruling 2026-09-22 (ASSUME-113 reverses ASSUME-107, back to ASSUME-037): a fastpix_manage_settings user
// reads AND edits the stored credentials here — a value they cannot see is one they cannot check. The two
// secrets sit behind Show/Hide, so they are password fields carrying their real value, not a mask.
assert(strpos($html, 'sk_selfcheck_settings') !== false, 'the stored secret key is shown to the owner');
assert(strpos($html, $whsec) !== false, 'and so is the webhook signing secret');
assert(strpos($html, SELFCHECK_TOKEN_ID) !== false, 'the token id is shown in full — it is an identifier, not a secret');
assert(substr_count($html, 'type="password"') === 2, 'both secrets start dotted: Show/Hide reveals them on request');
assert(strpos($html, 'class="mono fp-secret"') !== false, 'and they carry the class the Show/Hide button attaches to');
Page::assets('fastpix_page_' . Page::SLUG);
$localized = (string) wp_scripts()->get_data('fastpix-settings-page', 'data');
assert(strpos($localized, 'sk_selfcheck_settings') === false && strpos($localized, $whsec) === false, 'the localized script data still carries no secret — the fields are the only place they appear');

// An untouched (masked) field posts the mask: the stored value stays, a typed value replaces it.
$sealed_wh = get_option(Webhooks::OPT_SECRET);
$at        = get_option(Webhooks::OPT_SECRET_AT);
$response  = sreq('PATCH', REST_SETTINGS, array('webhook_secret' => Creds::SECRET_MASK));
assert(!$response->is_error() && Webhooks::secret() === $whsec && get_option(Webhooks::OPT_SECRET) === $sealed_wh && get_option(Webhooks::OPT_SECRET_AT) === $at, 'PATCH with the mask leaves the stored signing secret (and its verdict clock) untouched');

$seen_auth = '';
$mock_http = function ($pre, $args) use (&$seen_auth) {
    $seen_auth = isset($args['headers']['Authorization']) ? $args['headers']['Authorization'] : '';

    return array('headers' => array(), 'body' => wp_json_encode(array('success' => true, 'data' => array())), 'response' => array('code' => 200, 'message' => ''));
};
add_filter('pre_http_request', $mock_http, 10, 2);
$response = sreq('POST', '/connection', array('token_id' => Creds::masked_token_id(), 'secret' => Creds::SECRET_MASK));
remove_filter('pre_http_request', $mock_http, 10);
assert(!$response->is_error(), 'Verify with both masks re-verifies the stored pair');
assert($seen_auth === 'Basic ' . base64_encode(SELFCHECK_TOKEN_ID . ':sk_selfcheck_settings'), 'FastPix was asked with the STORED pair, not the dots');
assert(Creds::secret() === 'sk_selfcheck_settings' && Creds::token_id() === SELFCHECK_TOKEN_ID, 'and the stored pair is unchanged');
assert(strpos(wp_json_encode($response->get_data()), 'sk_selfcheck_settings') === false, 'the response carries no secret');
assert(strpos($html, 'fastpix/v1/webhook') !== false, 'the receiver address is shown for the dashboard');
assert(strpos($html, 'Run setup again') !== false, 'the wizard re-entry exists [UI-001 entry]');
assert(strpos($html, 'Get new keys') === false, 'Get new keys is omitted — flow unspecified [MISS-017]');
assert(strpos($html, 'FASTPIX_TRUSTED_PROXIES') === false, 'the proxy opt-in is a wp-config detail: Site Health names it when a proxy is detected, the Settings screen does not (owner 2026-09-21)');

// S5: the pill says why when the stored secret no longer decrypts.
$sealed = get_option(Creds::OPT_SECRET);
update_option(Creds::OPT_SECRET, 'g1:' . base64_encode(random_bytes(40)), false);
ob_start(); Page::render(); $unreadable_html = ob_get_clean();
assert(strpos($unreadable_html, 'can no longer be read') !== false, 'the connection pill surfaces an unreadable secret (S5)');
update_option(Creds::OPT_SECRET, $sealed, false);

// S19: Settings enqueues the bundled font stylesheet, not fonts.googleapis.com [REQ-112].
Fastpix\Fastpix_Onboarding::register_fonts();   // priority-1 admin_enqueue_scripts hook; Settings enqueues the handle bare
Page::assets('fastpix_page_' . Page::SLUG);
assert(wp_style_is('fastpix-onboarding-fonts', 'enqueued') && strpos(wp_styles()->registered['fastpix-onboarding-fonts']->src, 'fonts.googleapis.com') === false, 'no third-party font request from Settings (S19)');

// Send test event: the receiver is pinged through its own public URL with a signed payload.
$t = sreq('POST', '/settings/webhook-test');
assert(!$t->is_error(), 'the test route answers');
assert(array_key_exists('delivered', $t->get_data()) && isset($t->get_data()['status']), 'and reports delivered + HTTP status');

// Six reader-named checks exactly.
assert(count(Page::reader_checks()) === 6, 'six reader-named checks [UI-006]');

// ---------------------------------------------------------------- teardown

foreach ($saved as $opt => $value) {
    if ($value === null) { delete_option($opt); } else { update_option($opt, $value, false); }
}
wp_set_current_user(0);

echo "settings screen: all checks passed\n";
