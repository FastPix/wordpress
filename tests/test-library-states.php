<?php
/**
 * Self-check for the UI-002 server-rendered states — paused-upload banner,
 * offline dim, filtered-zero scaffolding, sortable title, media surface.
 *
 *   docker compose exec wordpress php -d zend.assertions=1 \
 *     /var/www/html/wp-content/plugins/fastpix/tests/test-library-states.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';
set_current_screen('admin_page_fastpix-video-library');

use Fastpix\Fastpix_Api_Client as Client;
use Fastpix\Fastpix_Credentials as Creds;
use Fastpix\Fastpix_Library_Page as Page;
use Fastpix\Fastpix_Schema as Schema;

const MSG_UPLOAD_PAUSED = 'upload is paused';

global $wpdb;

$saved = array();
foreach (array(Creds::OPT_TOKEN_ID, Creds::OPT_SECRET, 'fastpix_workspace_seen_id', 'fastpix_workspace_seen_name') as $opt) {   // the learned workspace must survive fixtures
    $saved[$opt] = get_option($opt, null);
}
// Restore even when a failed assertion aborts the run — a leaked fixture pair once shadowed the live one.
register_shutdown_function(function () use (&$saved) {
    foreach ($saved as $opt => $value) { $value === null ? delete_option($opt) : update_option($opt, $value, false); }
});
Creds::store('4f27c4c4-9a1e-4c2f-9d33-2b8f0a7c1e55', 'sk_selfcheck_states');

$admins = get_users(array('role' => 'administrator', 'number' => 1, 'fields' => 'ID'));
wp_set_current_user($admins[0]);

function render_library() {
    ob_start();
    Page::render();

    return ob_get_clean();
}

// ------------------------------------------------------------ plain render

delete_transient(Client::TRANSIENT_BREAKER);
delete_option(Client::OPT_HEALTH);
$html = render_library();
assert(strpos($html, 'fp-offline-dim') === false, 'healthy: nothing dimmed');
assert(strpos($html, 'uploads are paused') === false && strpos($html, MSG_UPLOAD_PAUSED) === false, 'no paused banner without paused uploads');
assert(strpos($html, 'fp-sort-title') !== false, 'the title column is sortable [FR-030]');
assert(strpos($html, 'fp-lib-zero') !== false, 'the filtered-zero state is scaffolded [UI-002]');
assert(strpos($html, 'Clear filters') !== false, 'with its clear action');

// ------------------------------------------------------- paused banner

$now = current_time('mysql', true);
$wpdb->query("DELETE FROM " . Schema::table('uploads') . " WHERE upload_id = 'state-paused-1'");   // a run that aborted before teardown must not poison this one
$wpdb->insert(Schema::table('uploads'), array(
    'upload_id' => 'state-paused-1', 'filename' => 'statecheck.mp4', 'filesize' => 100,
    'state' => 'paused', 'user_id' => $admins[0], 'created_at' => $now, 'updated_at' => $now,
));
$html = render_library();
assert(strpos($html, MSG_UPLOAD_PAUSED) !== false, 'a paused session raises the banner [UI-002 paused-upload]');
assert(strpos($html, 'Resume asks for the same file again') !== false, 'stating the RULE-008 guarantee (prototype copy)');
assert(strpos($html, 'fastpix-add-media') !== false, 'and pointing at Add media to resume');

// Another user's paused upload is not this user's banner.
$wpdb->update(Schema::table('uploads'), array('user_id' => 999999), array('upload_id' => 'state-paused-1'));
$html = render_library();
assert(strpos($html, MSG_UPLOAD_PAUSED) === false, 'the banner is per-user');

// ------------------------------------------------------------ offline dim

// Owner 2026-09-22: a platform outage is NOT announced on this screen and never dims the list — the library
// is a local read, published pages are served by the FastPix network, and Site Health carries the detail.
set_transient(Client::TRANSIENT_BREAKER, array('fails' => 5, 'first' => time(), 'open_until' => time() + 300), 400);
update_option(Client::OPT_HEALTH, array('state' => 'unhealthy', 'status' => 401, 'at' => time()), false);
$html = render_library();
assert(strpos($html, 'fp-offline-dim') === false, 'an open breaker never dims the list');
assert(strpos($html, 'not responding') === false && strpos($html, 'still playing normally') === false, 'and nothing announces it');
assert(strpos($html, 'id="fp-libtable"') !== false, 'the library renders as usual');
delete_transient(Client::TRANSIENT_BREAKER);
delete_option(Client::OPT_HEALTH);

// --------------------------------------------- media surface panel exists

// Core fires this hook from wp_print_media_templates(), which it also hooks to wp_footer and the Customizer,
// while the panel's JS loads on four admin screens only — so the panel is gated on those screens. (QA 2026-09-22)
$fp_panel = function () { ob_start(); do_action('post-plupload-upload-ui'); return ob_get_clean(); };
assert(strpos($fp_panel(), 'fastpix-media-panel') === false, 'no panel on a screen that never loads its JS (the Videos page here)');

set_current_screen('upload');
$panel = $fp_panel();
assert(strpos($panel, 'fastpix-media-panel') !== false, 'the Media Library upload surface renders [REQ-010]');
assert(strpos($panel, 'Upload video to FastPix') !== false, 'with its action');
set_current_screen('admin_page_fastpix-video-library');   // back to the screen the rest of this file renders

// Without a connection the panel stays out of the way.
Creds::forget();
ob_start();
do_action('post-plupload-upload-ui');
$panel = ob_get_clean();
assert(strpos($panel, 'fastpix-media-panel') === false, 'disconnected: no panel in the media modal');

// ---------------------------------------------------------------- teardown

$wpdb->query("DELETE FROM " . Schema::table('uploads') . " WHERE upload_id = 'state-paused-1'");
foreach ($saved as $opt => $value) {
    if ($value === null) { delete_option($opt); } else { update_option($opt, $value, false); }
}
delete_transient(Client::TRANSIENT_BREAKER);   // set open above; never leave it open on the live site
delete_transient(Client::TRANSIENT_PAUSE);
wp_set_current_user(0);

echo "library states: all checks passed\n";
