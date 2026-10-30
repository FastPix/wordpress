<?php
/**
 * Deactivation confirm — the Plugins-screen "Deactivate FastPix?" dialog. It only asks; nothing is
 * stored or sent, and deactivating is never blocked when the script or markup is missing.
 */

require_once __DIR__ . '/bootstrap.php';

use Fastpix\Fastpix_Deactivate as De;

$admins = get_users(array('role' => 'administrator', 'number' => 1, 'fields' => 'ID'));
assert(!empty($admins), 'an administrator exists');
wp_set_current_user($admins[0]);

ob_start();
De::dialog();
$html = ob_get_clean();
foreach (array('Deactivate FastPix?', 'Video on this site stops playing until FastPix is active again. Nothing is deleted, here or on FastPix.',
               'id="fp-de-cancel"', '>Cancel<', 'id="fp-de-go"', '>Deactivate<', 'id="fp-de-x"') as $needle) {
    assert(strpos($html, $needle) !== false, 'the dialog carries: ' . $needle);
}
assert(strpos($html, 'fp-de-reason') === false, 'no survey any more');

$sub = get_users(array('role' => 'subscriber', 'number' => 1, 'fields' => 'ID'));
if (!empty($sub)) {
    wp_set_current_user($sub[0]);
    ob_start(); De::dialog();
    assert(ob_get_clean() === '', 'someone who cannot deactivate gets no dialog');
    wp_set_current_user($admins[0]);
}

$js = (string) file_get_contents(FASTPIX_PLUGIN_DIR . 'assets/js/deactivate.js');
assert(strpos($js, 'e.target.closest') !== false, 'the link is caught by delegation: core prints footer scripts BEFORE admin_footer-plugins.php');
assert(strpos($js, 'cfg.slug') !== false, 'only THIS plugin\'s Deactivate link is intercepted');
assert(strpos($js, 'if (!m) { window.location.href = link.href; return; }') !== false, 'no dialog markup: the link still deactivates');

echo "deactivation confirm: all checks passed\n";
