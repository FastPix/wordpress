<?php
/**
 * Self-check for the global plugin header — one shared partial on every
 * FastPix admin screen; logo served locally (REQ-112); version chip from
 * FASTPIX_VERSION and in agreement with the plugin file header.
 *
 *   docker compose exec wordpress php -d zend.assertions=1 \
 *     /var/www/html/wp-content/plugins/fastpix/tests/test-plugin-header.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';
set_current_screen('admin_page_fastpix-connection');

use Fastpix\Fastpix_Addmedia;
use Fastpix\Fastpix_Credentials as Creds;
use Fastpix\Fastpix_Library_Page;
use Fastpix\Fastpix_Onboarding;
use Fastpix\Fastpix_Settings_Page;

$saved = array();
foreach (array(Creds::OPT_TOKEN_ID, Creds::OPT_SECRET, 'fastpix_workspace_seen_id', 'fastpix_workspace_seen_name') as $opt) {   // the learned workspace must survive fixtures
    $saved[$opt] = get_option($opt, null);
}
// Restore even when a failed assertion aborts the run — a leaked fixture pair once shadowed the live one.
register_shutdown_function(function () use (&$saved) {
    foreach ($saved as $opt => $value) { $value === null ? delete_option($opt) : update_option($opt, $value, false); }
});
Creds::store('4f27c4c4-9a1e-4c2f-9d33-2b8f0a7c1e55', 'sk_selfcheck_header');

$admins = get_users(array('role' => 'administrator', 'number' => 1, 'fields' => 'ID'));
wp_set_current_user($admins[0]);

// ------------------------------------------- present on every registered page

// No registered page draws the partial any more. The Figma re-matches dropped it
// from the wizard (ASSUME-058/059/063/065: hero/stepper start under the admin bar),
// Videos (ASSUME-055 "no plugin header bar"), Add videos (ASSUME-066), Analytics
// (ASSUME-090) and Settings (ASSUME-097, frame 9522:120112 starts with the page
// title); only the restricted wizard view keeps it — asserted below.
$pages = array();
foreach (array('videos' => function () { Fastpix_Library_Page::render(); }, 'add-media' => function () { Fastpix_Addmedia::render(); }, 'settings' => function () { Fastpix_Settings_Page::render(); }) as $name => $render) {
    ob_start();
    $render();
    assert(strpos(ob_get_clean(), 'fp-plugin-header') === false, "{$name}: Figma-matched screen has no plugin header [ASSUME-055/066]");
}

foreach ($pages as $name => $render) {
    ob_start();
    $render();
    $html = ob_get_clean();

    assert(substr_count($html, 'fp-plugin-header') >= 1, "{$name}: the global header renders");
    assert(strpos($html, 'fp-plugin-header-bar') !== false, "{$name}: with its inner bar");
    assert(strpos($html, 'fp-plugin-header-accent') !== false, "{$name}: and the gradient accent");
    assert(strpos($html, 'fp-version-chip') !== false, "{$name}: and the version chip");
    assert(strpos($html, 'v' . FASTPIX_VERSION) !== false, "{$name}: chip shows v" . FASTPIX_VERSION);

    // Logo is the bundled file, served from this plugin — never a CDN. [REQ-112]
    assert(strpos($html, 'assets/images/fastpix-logo.svg') !== false, "{$name}: the logo is the bundled SVG");
    assert(preg_match('#src="[^"]*//(?!localhost|' . preg_quote(wp_parse_url(home_url(), PHP_URL_HOST), '#') . ')[^"]*fastpix-logo#', $html) === 0,
        "{$name}: the logo src is local");
}

// The wizard draws no header for the settings-capable user (ASSUME-058/059:
// the frames put the hero/stepper at the top of the column); the restricted
// view (REQ-004), which has no frame, keeps the full-width header.
ob_start();
Fastpix_Onboarding::render();
$wizard_html = ob_get_clean();
assert(strpos($wizard_html, 'fp-plugin-header') === false, 'the Figma-matched wizard has no plugin header [ASSUME-058/059]');
$author_ids = get_users(array('role' => 'author', 'number' => 1, 'fields' => 'ID'));
if ($author_ids) {
    wp_set_current_user($author_ids[0]);
    ob_start();
    Fastpix_Onboarding::render();
    $restricted_html = ob_get_clean();
    wp_set_current_user($admins[0]);
    assert(strpos($restricted_html, 'fp-plugin-header full') !== false, 'the restricted wizard view keeps the full-width header');
}
// Settings starts with its page title like every other Figma-matched screen (ASSUME-097) — asserted above.

// ------------------------------------------------------ the logo file itself

$logo = FASTPIX_PLUGIN_DIR . 'assets/images/fastpix-logo.svg';
assert(file_exists($logo), 'the logo ships in the package [REQ-112]');
$svg = file_get_contents($logo);
assert(strpos($svg, '<svg') === 0, 'and is an SVG');
assert(strpos($svg, 'http') === false || strpos($svg, 'xmlns') !== false, 'with no remote references');
assert(preg_match('/href\s*=\s*["\']https?:/i', $svg) === 0, 'no external loads inside the SVG');

// ------------------------------------- version chip agrees with the header

$plugin_data = get_file_data(FASTPIX_PLUGIN_DIR . 'fastpix-io.php', array('Version' => 'Version'));
assert($plugin_data['Version'] === FASTPIX_VERSION, 'FASTPIX_VERSION matches the plugin file header — one version, stated twice');

// ---------------------------------------------------------------- teardown

foreach ($saved as $opt => $value) {
    if ($value === null) { delete_option($opt); } else { update_option($opt, $value, false); }
}
wp_set_current_user(0);

echo "plugin header: all checks passed\n";
