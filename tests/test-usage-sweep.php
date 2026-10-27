<?php
/**
 * Self-check for the usage sweep — DATA-008, REQ-037.
 *
 *   docker compose exec wordpress php -d zend.assertions=1 \
 *     /var/www/html/wp-content/plugins/fastpix/tests/test-usage-sweep.php
 */

require_once __DIR__ . '/bootstrap.php';

use Fastpix\Fastpix_Schema as Schema;
use Fastpix\Fastpix_Sync as Sync;

const SQL_WHERE_VIDEO_ID = ' WHERE video_id = %d';
const SQL_SELECT_COUNT_FROM = 'SELECT COUNT(*) FROM ';

global $wpdb;

$now = current_time('mysql', true);
// An aborted earlier run leaves its fixture behind — clear it so the insert (unique media/workspace) cannot collide.
$wpdb->query("DELETE FROM " . Schema::table('videos') . " WHERE media_id = 'usage-check-1'");
foreach ($wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_title IN ('usage-sweep fixture', 'no embeds')") as $old) { wp_delete_post($old, true); }
$wpdb->insert(Schema::table('videos'), array(
    'media_id' => 'usage-check-1', 'workspace_id' => 'ws-u', 'status' => 'Ready', 'source' => 'Upload',
    'title' => 'Usage check', 'created_at' => $now, 'updated_at' => $now,
));
$video_id = (int) $wpdb->insert_id;

// A post with a shortcode twice and a block once; one post with nothing.
$post_id = wp_insert_post(array(
    'post_title'   => 'usage-sweep fixture',
    'post_status'  => 'publish',
    'post_content' => 'Intro [fastpix id="usage-check-1"] middle [fastpix id="usage-check-1"] and '
        . '<!-- wp:fastpix/video {"mediaId":"usage-check-1"} /-->',
));
$plain_id = wp_insert_post(array('post_title' => 'no embeds', 'post_status' => 'publish', 'post_content' => 'nothing here'));

// ------------------------------------------------------------------ the sweep

$found = Sync::usage_sweep();
assert($found >= 2, 'the sweep finds embeds');

$rows = $wpdb->get_results($wpdb->prepare(
    'SELECT * FROM ' . Schema::table('usage') . ' WHERE video_id = %d ORDER BY context', $video_id
), ARRAY_A);
assert(count($rows) === 2, 'one row per (video, post, context) [DATA-008]');
$by_context = array_column($rows, null, 'context');
assert((int) $by_context['shortcode']['occurrences'] === 2, 'shortcode occurrences are counted');
assert((int) $by_context['block']['occurrences'] === 1, 'the block is found too');
assert((int) $by_context['block']['post_id'] === $post_id, 'attributed to the right post [REQ-037]');

// Idempotent: a second sweep changes nothing.
Sync::usage_sweep();
assert((int) $wpdb->get_var($wpdb->prepare(SQL_SELECT_COUNT_FROM . Schema::table('usage') . SQL_WHERE_VIDEO_ID, $video_id)) === 2, 'sweeping twice does not duplicate');
// M13: a resumed walk prunes only what the walk that BEGAN at `begun` never touched — rows stamped
// since then survive a continuation that starts past them (the cursor, not a LIMIT, bounds each action).
$stamped = $wpdb->get_var($wpdb->prepare('SELECT MIN(last_seen_at) FROM ' . Schema::table('usage') . SQL_WHERE_VIDEO_ID, $video_id));
Sync::usage_sweep(array('cursor' => $plain_id + 1000, 'begun' => $stamped));
assert((int) $wpdb->get_var($wpdb->prepare(SQL_SELECT_COUNT_FROM . Schema::table('usage') . SQL_WHERE_VIDEO_ID, $video_id)) === 2, 'M13: a continuation past the post keeps the rows its walk already stamped');
assert(!empty(Sync::sync_state('usage_sweep')['last_success_at']), 'a completed walk records success');

// Removing the embed removes the row on the next sweep.
wp_update_post(array('ID' => $post_id, 'post_content' => 'The shortcodes are gone; only <!-- wp:fastpix/video {"mediaId":"usage-check-1"} /--> stays.'));
sleep(1);   // last_seen_at is a datetime: a walk that begins in the same second as the previous stamp cannot tell the rows apart (M13 prune is "stamped before this walk began")
Sync::usage_sweep();
$rows = $wpdb->get_results($wpdb->prepare('SELECT context FROM ' . Schema::table('usage') . SQL_WHERE_VIDEO_ID, $video_id), ARRAY_A);
assert(count($rows) === 1 && $rows[0]['context'] === 'block', 'a removed embed drops its row; the surviving one stays');

// No-op-safe: no embeds anywhere → empty table, no errors.
wp_delete_post($post_id, true);
sleep(1);   // same datetime-resolution tie as above
Sync::usage_sweep();
assert((int) $wpdb->get_var($wpdb->prepare(SQL_SELECT_COUNT_FROM . Schema::table('usage') . SQL_WHERE_VIDEO_ID, $video_id)) === 0, 'no embeds ⇒ no rows — the schedule is no longer silent, just quiet');

// ---------------------------------------------------------------- teardown

wp_delete_post($plain_id, true);
$wpdb->query("DELETE FROM " . Schema::table('videos') . " WHERE media_id = 'usage-check-1'");

echo "usage sweep: all checks passed\n";
