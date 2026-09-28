<?php
/**
 * Self-check for Fastpix_Schema — REQ-103, spec 07.
 *
 *   docker compose exec wordpress php -d zend.assertions=1 \
 *     /var/www/html/wp-content/plugins/fastpix/tests/test-schema.php
 *
 * Runs the real installer against the real database, then restores the schema
 * version option it found. Tables are left in place — they are additive and
 * dbDelta is idempotent.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/class-fastpix-schema.php';
require_once __DIR__ . '/../includes/class-fastpix-log.php';   // Schema::prune() delegates logs to it

use Fastpix\Fastpix_Schema as Schema;

const SQL_DESC = 'DESC ';
const SQL_SHOW_INDEX_FROM = 'SHOW INDEX FROM ';
const DATETIME_FORMAT = 'Y-m-d H:i:s';
const SQL_SELECT_COUNT_FROM = 'SELECT COUNT(*) FROM ';

global $wpdb;

$saved_version = get_option(Schema::OPT_VERSION, null);
$saved_failed  = get_option(Schema::OPT_FAILED, null);

// ------------------------------------------------------------- installation

delete_option(Schema::OPT_VERSION);
delete_transient(Schema::LOCK);

assert(Schema::current_version() === 0, 'a fresh site is at version 0');
assert(Schema::needs_update() === true, 'a fresh site needs the installer');
assert(Schema::update() === true, 'the installer runs clean');
assert(Schema::current_version() === Schema::target_version(), 'the version advances only after success [REQ-103]');
assert(Schema::needs_update() === false, 'nothing pending afterwards');

assert(count(Schema::tables()) === 17, 'the 15 tables of spec 07 plus the audit log and lesson_progress [ARCH-08, SEC-018, ASSUME-046]');

foreach (Schema::tables() as $table) {
    assert(Schema::table_exists($table), "{$table} exists");
    assert(strpos(Schema::table($table), $wpdb->prefix . 'fastpix_') === 0, "{$table} is site-prefixed [ARCH-08]");
}

// DATA-001 — key columns and every index the spec names.
$video_columns = $wpdb->get_col(SQL_DESC . Schema::table('videos'), 0);
foreach (array('media_id', 'workspace_id', 'attachment_id', 'title', 'description', 'status', 'source',
             'access_policy', 'drm_configuration_id', 'quality_tier', 'max_resolution', 'duration_seconds',
             'aspect_ratio', 'poster_updated_at', 'mp4_support', 'moderation_state', 'ai_state', 'error_code',
             'author_id', 'platform_updated_at', 'local_updated_at', 'deleted_at') as $column) {
    assert(in_array($column, $video_columns, true), "fastpix_videos has {$column} [DATA-001]");
}
$video_indexes = $wpdb->get_col(SQL_SHOW_INDEX_FROM . Schema::table('videos'), 2);
foreach (array('media_workspace', 'status_created', 'source', 'access_policy', 'author_id', 'attachment_id') as $index) {
    assert(in_array($index, $video_indexes, true), "fastpix_videos is indexed on {$index} [DATA-001]");
}

// Tombstones only where a platform object is mirrored; local derivatives are pruned.
foreach (array('videos', 'playback_ids', 'tracks', 'live_streams') as $mirrored) {
    assert(in_array('deleted_at', $wpdb->get_col(SQL_DESC . Schema::table($mirrored), 0), true),
        "{$mirrored} is tombstoned [spec 07 Common columns]");
}
foreach (array('analytics_daily', 'search_index', 'logs', 'uploads') as $derivative) {
    assert(!in_array('deleted_at', $wpdb->get_col(SQL_DESC . Schema::table($derivative), 0), true),
        "{$derivative} is pruned outright, not tombstoned [spec 07 Common columns]");
}

// DATA-011 / DATA-013 — natural composite keys, no id column.
foreach (array('analytics_daily', 'search_index') as $natural) {
    assert(!in_array('id', $wpdb->get_col(SQL_DESC . Schema::table($natural), 0), true),
        "{$natural} is keyed by its natural composite [spec 07]");
}
$analytics_pk = $wpdb->get_results(SQL_SHOW_INDEX_FROM . Schema::table('analytics_daily') . " WHERE Key_name = 'PRIMARY'");
assert(count($analytics_pk) === 4, 'analytics_daily is keyed on (video_id, day, dimension, dimension_value) [DATA-011]');

// DATA-013 — FULLTEXT, or an honest flag saying transcript search is off.
$fulltext = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . esc_sql(Schema::table('search_index')) . "'
       AND INDEX_TYPE = 'FULLTEXT'"
);
assert($fulltext > 0 || get_option('fastpix_fulltext_unavailable') == 1,
    'either FULLTEXT exists or transcript search is flagged unavailable — never a LIKE scan [ARCH-08, RULE-044]');

// v3 — suggestion columns, add-then-populate [REQ-103, ASSUME-032].
$video_columns = $wpdb->get_col(SQL_DESC . Schema::table('videos'), 0);
foreach (array('suggested_title', 'suggested_description', 'suggested_at') as $column) {
    assert(in_array($column, $video_columns, true), "fastpix_videos gained {$column} [ASSUME-032]");
}

// Uniques that carry a rule of their own.
$event_unique = $wpdb->get_results(SQL_SHOW_INDEX_FROM . Schema::table('webhook_events') . " WHERE Key_name = 'event_id'");
assert(count($event_unique) === 1 && (int) $event_unique[0]->Non_unique === 0, 'event_id is unique — the replay defence [DATA-009, ARCH-05]');
$progress_unique = $wpdb->get_results(SQL_SHOW_INDEX_FROM . Schema::table('watch_progress') . " WHERE Key_name = 'video_viewer'");
assert(count($progress_unique) === 2 && (int) $progress_unique[0]->Non_unique === 0, 'watch progress is unique per (video, viewer) [DATA-012]');

// DATA-014 names the columns; types are ours, names are binding.
$columns = $wpdb->get_col(SQL_DESC . Schema::table('logs'), 0);
foreach (array('timestamp', 'severity', 'scope', 'message', 'correlation_id', 'workspace_id', 'video_id',
             'media_id', 'upload_id', 'migration_id', 'webhook_event_id', 'action_id', 'endpoint',
             'http_method', 'http_status', 'error_code', 'latency_ms', 'attempt', 'max_attempts',
             'actor', 'context', 'created_at', 'updated_at') as $column) {
    assert(in_array($column, $columns, true), "fastpix_logs has the {$column} column [DATA-014]");
}

$indexes = $wpdb->get_col(SQL_SHOW_INDEX_FROM . Schema::table('logs'), 2);
foreach (array('timestamp', 'severity_scope', 'correlation_id') as $index) {
    assert(in_array($index, $indexes, true), "fastpix_logs is indexed on {$index} [DATA-014]");
}

// DATA-010 — unique (scope, workspace_id).
$sync_indexes = $wpdb->get_results(SQL_SHOW_INDEX_FROM . Schema::table('sync_state'));
$unique = array_filter($sync_indexes, function ($i) { return $i->Key_name === 'scope_workspace' && (int) $i->Non_unique === 0; });
assert(count($unique) === 2, 'sync_state is unique on (scope, workspace_id) [DATA-010]');

// ------------------------------------------------------- rerun and ordering

$before = Schema::current_version();
assert(Schema::update() === true, 'a second run is a no-op');
assert(Schema::current_version() === $before, 'migrations never re-run [REQ-103]');

// (QA M29) An install from before v11 still carries the dead migrations.ready_count — dbDelta never drops, so v11 does.
$has_ready = function () use ($wpdb) { return in_array('ready_count', (array) $wpdb->get_col('DESC ' . Schema::table('migrations'), 0), true); };
$wpdb->query('ALTER TABLE ' . Schema::table('migrations') . ' ADD COLUMN ready_count int(10) unsigned NOT NULL DEFAULT 0');
update_option(Schema::OPT_VERSION, 10, false);
assert(Schema::update() === true && !$has_ready() && Schema::current_version() === 11, 'QA M29: v11 drops the dead column and the version advances');
assert(Schema::update() === true && !$has_ready() && Schema::current_version() === 11, 'QA M29: running it again is a no-op');

// ---------------------------------------------------------------- the lock

delete_option(Schema::OPT_VERSION);
add_option(Schema::LOCK, time(), '', 'no');   // M12: the lock is an option row (atomic INSERT), not a get/set transient
$locked = Schema::update();
assert(is_wp_error($locked) && $locked->get_error_code() === 'fastpix_schema_locked', 'a second runner is refused [REQ-103]');
assert(Schema::current_version() === 0, 'a refused run advances nothing');
update_option(Schema::LOCK, time() - Schema::LOCK_TTL - 1, false);   // a crashed runner's stale lock is reclaimed
wp_cache_delete(Schema::LOCK, 'options');
wp_cache_set('notoptions', array(Schema::LOCK => true) + (array) wp_cache_get('notoptions', 'options'), 'options');   // QA M12: a persistent object cache that says "missing"
assert(Schema::update() === true && get_option(Schema::LOCK, null) === null, 'M12: a stale lock is reclaimed and released after the run');

// M12: a recorded failure is not retried on every request — only after RETRY_AFTER.
delete_option(Schema::OPT_VERSION);
update_option(Schema::OPT_FAILED, array('version' => 1, 'tables' => array('logs'), 'message' => 'forced', 'at' => time()), false);
$gated = Schema::update();
assert(is_wp_error($gated) && $gated->get_error_code() === 'fastpix_schema_failed' && Schema::current_version() === 0, 'M12: a fresh failure short-circuits update() without running migrations');
update_option(Schema::OPT_FAILED, array('version' => 1, 'tables' => array('logs'), 'message' => 'forced', 'at' => time() - Schema::RETRY_AFTER - 1), false);
assert(Schema::update() === true && get_option(Schema::OPT_FAILED) === false, 'M12: after the retry window the migrations run and a success clears the failure');

// ------------------------------------------------- read-only after failure

update_option(Schema::OPT_FAILED, array('version' => 99, 'tables' => array('logs'), 'message' => 'forced'), false);
assert(Schema::is_read_only('logs') === true, 'a failed migration holds its table read-only [REQ-103]');
assert(Schema::is_read_only('sync_state') === false, 'other tables keep working');
delete_option(Schema::OPT_FAILED);
assert(Schema::is_read_only('logs') === false, 'read-only clears when the failure does');

// ----------------------------------------------------- retention (fastpix_prune)

$now = current_time('mysql', true);
$old_day       = gmdate('Y-m-d', strtotime('-26 months'));
$fresh_day     = gmdate('Y-m-d', strtotime('-1 month'));
$old_activity  = gmdate(DATETIME_FORMAT, strtotime('-13 months'));
$old_upload    = gmdate(DATETIME_FORMAT, strtotime('-8 days'));
$old_received  = gmdate(DATETIME_FORMAT, strtotime('-31 days'));

// One stale and one fresh row per retention rule, all tagged with video_id
// 999999901 (or the marker event id) so teardown removes exactly these.
$vid = 999999901;
$wpdb->insert(Schema::table('analytics_daily'), array('video_id' => $vid, 'day' => $old_day, 'dimension' => 'selfcheck', 'dimension_value' => 'x', 'created_at' => $now, 'updated_at' => $now));
$wpdb->insert(Schema::table('analytics_daily'), array('video_id' => $vid, 'day' => $fresh_day, 'dimension' => 'selfcheck', 'dimension_value' => 'x', 'created_at' => $now, 'updated_at' => $now));
$wpdb->insert(Schema::table('watch_progress'), array('video_id' => $vid, 'viewer_key' => 'stale-viewer', 'last_seen_at' => $old_activity, 'created_at' => $now, 'updated_at' => $now));
$wpdb->insert(Schema::table('watch_progress'), array('video_id' => $vid, 'viewer_key' => 'fresh-viewer', 'last_seen_at' => $now, 'created_at' => $now, 'updated_at' => $now));
$wpdb->insert(Schema::table('uploads'), array('upload_id' => 'selfcheck-done', 'state' => 'completed', 'user_id' => 0, 'created_at' => $old_upload, 'updated_at' => $old_upload));
$wpdb->insert(Schema::table('uploads'), array('upload_id' => 'selfcheck-paused', 'state' => 'paused', 'user_id' => 0, 'created_at' => $old_upload, 'updated_at' => $old_upload));
$wpdb->insert(Schema::table('webhook_events'), array('event_id' => 'selfcheck-old-evt', 'payload' => '{"big":"payload"}', 'received_at' => $old_received, 'created_at' => $now, 'updated_at' => $now));
$wpdb->insert(Schema::table('webhook_events'), array('event_id' => 'selfcheck-new-evt', 'payload' => '{"big":"payload"}', 'received_at' => $now, 'created_at' => $now, 'updated_at' => $now));

// Unavailable videos: an old unused tombstone goes, a fresh one and an old embedded one stay. [ASSUME-102]
$old_video = gmdate(DATETIME_FORMAT, strtotime('-40 days'));   // past the 30-day Unavailable rule
$vcols = array('workspace_id' => 'ws-prune', 'status' => 'Deleted', 'source' => 'Upload', 'access_policy' => 'public', 'author_id' => 0, 'created_at' => $old_video);
$wpdb->insert(Schema::table('videos'), $vcols + array('media_id' => 'prune-old-unused', 'title' => 'prune old unused', 'deleted_at' => $old_video, 'updated_at' => $old_video));
$wpdb->insert(Schema::table('videos'), $vcols + array('media_id' => 'prune-fresh', 'title' => 'prune fresh', 'deleted_at' => $now, 'updated_at' => $now));
$wpdb->insert(Schema::table('videos'), $vcols + array('media_id' => 'prune-old-used', 'title' => 'prune old used', 'deleted_at' => $old_video, 'updated_at' => $old_video));
$prune_used = (int) $wpdb->insert_id;
$wpdb->insert(Schema::table('usage'), array('video_id' => $prune_used, 'post_id' => 1, 'context' => 'shortcode', 'occurrences' => 1, 'created_at' => $now, 'updated_at' => $now));

$removed = Schema::prune();

assert(array_key_exists('videos', $removed), 'prune applies the Unavailable-videos rule');
assert((int) $wpdb->get_var(SQL_SELECT_COUNT_FROM . Schema::table('videos') . " WHERE media_id = 'prune-old-unused'") === 0, 'an Unavailable video unused for 30 days is removed');
assert((int) $wpdb->get_var(SQL_SELECT_COUNT_FROM . Schema::table('videos') . " WHERE media_id = 'prune-fresh'") === 1, 'a recently deleted one stays');
assert((int) $wpdb->get_var(SQL_SELECT_COUNT_FROM . Schema::table('videos') . " WHERE media_id = 'prune-old-used'") === 1, 'one a post still embeds stays, so the saved poster keeps rendering');

foreach (array('analytics_daily', 'watch_progress', 'uploads', 'webhook_payloads', 'logs') as $rule) {
    assert(array_key_exists($rule, $removed), "prune applies the {$rule} rule [spec 07 retention]");
}

assert((int) $wpdb->get_var($wpdb->prepare(SQL_SELECT_COUNT_FROM . Schema::table('analytics_daily') . ' WHERE video_id = %d AND day = %s', $vid, $old_day)) === 0, 'analytics past 25 months is pruned');
assert((int) $wpdb->get_var($wpdb->prepare(SQL_SELECT_COUNT_FROM . Schema::table('analytics_daily') . ' WHERE video_id = %d AND day = %s', $vid, $fresh_day)) === 1, 'recent analytics survives');
assert((int) $wpdb->get_var($wpdb->prepare(SQL_SELECT_COUNT_FROM . Schema::table('watch_progress') . ' WHERE video_id = %d AND viewer_key = %s', $vid, 'stale-viewer')) === 0, 'progress idle 12 months is pruned');
assert((int) $wpdb->get_var($wpdb->prepare(SQL_SELECT_COUNT_FROM . Schema::table('watch_progress') . ' WHERE video_id = %d AND viewer_key = %s', $vid, 'fresh-viewer')) === 1, 'active progress survives — 12 months since last activity, not creation');
assert((int) $wpdb->get_var(SQL_SELECT_COUNT_FROM . Schema::table('uploads') . " WHERE upload_id = 'selfcheck-done'") === 0, 'completed uploads past 7 days are pruned');
assert((int) $wpdb->get_var(SQL_SELECT_COUNT_FROM . Schema::table('uploads') . " WHERE upload_id = 'selfcheck-paused'") === 1, 'a paused upload is NOT pruned — that is the orphan sweep\'s decision [REQ-014]');

$old_evt = $wpdb->get_row("SELECT payload FROM " . Schema::table('webhook_events') . " WHERE event_id = 'selfcheck-old-evt'");
$new_evt = $wpdb->get_row("SELECT payload FROM " . Schema::table('webhook_events') . " WHERE event_id = 'selfcheck-new-evt'");
assert($old_evt !== null && $old_evt->payload === null, 'old webhook PAYLOADS are pruned, the row stays — dedup keeps working [DATA-009, ARCH-05]');
assert($new_evt->payload !== null, 'recent payloads survive');

// Pruning an empty or absent table is a quiet no-op — run twice, nothing breaks.
$second = Schema::prune();
assert(is_array($second), 'prune is idempotent');

// Teardown of retention fixtures.
$wpdb->delete(Schema::table('usage'), array('video_id' => $prune_used));
$wpdb->query("DELETE FROM " . Schema::table('videos') . " WHERE media_id LIKE 'prune-%'");
$wpdb->delete(Schema::table('analytics_daily'), array('video_id' => $vid));
$wpdb->delete(Schema::table('watch_progress'), array('video_id' => $vid));
$wpdb->delete(Schema::table('uploads'), array('upload_id' => 'selfcheck-paused'));
$wpdb->query("DELETE FROM " . Schema::table('webhook_events') . " WHERE event_id LIKE 'selfcheck-%'");

// ---------------------------------------------------------------- teardown

if ($saved_version === null) {
    update_option(Schema::OPT_VERSION, Schema::target_version(), false);
} else {
    update_option(Schema::OPT_VERSION, $saved_version, false);
}
if ($saved_failed !== null) {
    update_option(Schema::OPT_FAILED, $saved_failed, false);
}

echo "schema: all checks passed\n";
