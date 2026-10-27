<?php
/**
 * Deactivation feedback — the Plugins-screen dialog. Local only: the answer is stored on the site
 * and rides in the support report; nothing leaves. Deactivating is never blocked.
 */

require_once __DIR__ . '/bootstrap.php';

use Fastpix\Fastpix_Deactivate as De;
use Fastpix\Fastpix_Health as Health;

global $wpdb;

$saved = get_option(De::OPT_FEEDBACK);
register_shutdown_function(function () use ($saved) {
    if ($saved === false) { delete_option(De::OPT_FEEDBACK); } else { update_option(De::OPT_FEEDBACK, $saved, false); }
});

$admins = get_users(array('role' => 'administrator', 'number' => 1, 'fields' => 'ID'));
assert(!empty($admins), 'an administrator exists to answer as');
wp_set_current_user($admins[0]);
delete_option(De::OPT_FEEDBACK);

$post = function ($body) {
    $r = new WP_REST_Request('POST', '/fastpix/v1/feedback/deactivate');
    $r->set_header('content-type', 'application/json');
    $r->set_body(wp_json_encode($body));

    return rest_get_server()->dispatch($r);
};

/* ------------------------------------------------------------ the answer is stored */

$res = $post(array('reason' => 'playback', 'detail' => 'uploads kept saying processing'));
assert(!$res->is_error() && $res->get_data() === array('stored' => true), 'an answer is accepted');
$entry = get_option(De::OPT_FEEDBACK);
assert($entry['reason'] === 'playback' && $entry['detail'] === 'uploads kept saying processing', 'reason and detail are stored');
assert(!empty($entry['label']) && $entry['version'] === FASTPIX_VERSION && (int) $entry['at'] > 0, 'with the label it was shown as, the version and the time');

// One row, overwritten — a survey must not grow without bound.
$post(array('reason' => 'temporary'));
assert(get_option(De::OPT_FEEDBACK)['reason'] === 'temporary', 'the latest answer replaces the last');
assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = %s", De::OPT_FEEDBACK)) === 1, 'one row only');

/* --------------------------------------------------- nothing is sent anywhere [privacy] */

$calls = 0;
$spy = function ($pre) use (&$calls) { $calls++; return $pre; };
add_filter('pre_http_request', $spy, 1, 1);
$post(array('reason' => 'connect', 'detail' => 'could not connect'));
remove_filter('pre_http_request', $spy, 1);
assert($calls === 0, 'storing an answer makes NO outbound request — the plugin declares one external service and this is not one');

// A site that wants it forwarded can hook it; the plugin never does.
$seen = null;
$hook = function ($entry) use (&$seen) { $seen = $entry; };
add_action('fastpix_deactivation_feedback', $hook);
$post(array('reason' => 'not_needed'));
remove_action('fastpix_deactivation_feedback', $hook);
assert(is_array($seen) && $seen['reason'] === 'not_needed', 'fastpix_deactivation_feedback carries the answer for anyone who wants to forward it');

/* ------------------------------------------------------------------- shape + authorisation */

$res = $post(array('reason' => 'not-a-listed-reason'));
assert($res->is_error() && $res->get_status() === 400, 'a reason outside the list is refused by the schema');

$res = $post(array('reason' => 'other', 'detail' => str_repeat('x', 4000)));
assert(!$res->is_error() && strlen(get_option(De::OPT_FEEDBACK)['detail']) <= 1000, 'a long detail is capped, not stored whole');

$sub = get_users(array('role' => 'subscriber', 'number' => 1, 'fields' => 'ID'));
$tmp = 0;
if (empty($sub)) {
    $tmp = wp_insert_user(array('user_login' => 'fp_deact_check', 'user_pass' => wp_generate_password(), 'role' => 'subscriber'));
    $sub = array($tmp);
}
wp_set_current_user($sub[0]);
$res = $post(array('reason' => 'playback'));
assert($res->is_error() && $res->get_status() === rest_authorization_required_code(), 'someone who cannot deactivate cannot answer either [SEC-011]');
wp_set_current_user($admins[0]);
if ($tmp) { require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user($tmp); }

/* ------------------------------------------------------- it reaches the support report only */

$post(array('reason' => 'confusing', 'detail' => 'could not find the shortcode'));
$line = De::report_line();
assert(strpos($line, 'could not find the shortcode') !== false, 'the answer reads back as one line');
$report = Health::system_report();
assert(isset($report['deactivation_feedback']) && $report['deactivation_feedback'] === $line, 'and travels only in the support report the owner sends');
delete_option(De::OPT_FEEDBACK);
assert(De::report_line() === '' && Health::system_report()['deactivation_feedback'] === '', 'no answer yet: the report says nothing');

/* ----------------------------------------------------- the dialog never blocks deactivation */

$js = (string) file_get_contents(FASTPIX_PLUGIN_DIR . 'assets/js/deactivate.js');
assert(strpos($js, "e.target.closest") !== false, 'the link is caught by delegation: core prints footer scripts BEFORE admin_footer-plugins.php, so binding at load time missed the dialog');
assert(substr_count($js, '.then(go, go)') === 1, 'a failed or refused request still deactivates');
assert(strpos($js, "cfg.slug") !== false, 'only THIS plugin\'s Deactivate link is intercepted');

echo "deactivation feedback: all checks passed\n";
