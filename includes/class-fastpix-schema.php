<?php
/**
 * Schema installer and migration registry — REQ-103, ARCH-08, spec 07.
 *
 * One schema version, one ordered registry. Migrations run under a lock; the
 * version advances only when all of them succeed. A failed migration leaves the
 * version unchanged and marks its table read-only with one notice, rather than
 * letting code write through a half-applied shape.
 *
 * Column *types* are NOT SPECIFIED by spec 07 ("do not invent constraints
 * beyond those listed"), so the types below are implementation choices; the
 * column *names*, keys and indexes are the binding part and match 07 exactly.
 */

namespace Fastpix;

if (!defined('WPINC')) {
    die;
}

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery
// All SQL here runs against the plugin's own tables: identifiers come from the
// Fastpix_Schema::table() registry (a fixed whitelist, never request input) and
// every value travels through $wpdb->prepare(). Core APIs cannot query these tables.

class Fastpix_Schema {

    const OPT_VERSION = 'fastpix_schema_version';
    const OPT_FAILED  = 'fastpix_schema_failed';    // tables held read-only after a failed migration
    const LOCK        = 'fastpix_schema_lock';
    const LOCK_TTL    = 300;
    const RETRY_AFTER = 3600;   // a failed migration is retried at most hourly (M12)

    private const WP_UPGRADE_PHP = 'wp-admin/includes/upgrade.php';
    private const SQL_DELETE     = 'DELETE FROM ';

    /**
     * Ordered migration registry. Key = schema version, value = method name.
     * Versions run in ascending order and never re-run. [REQ-103]
     */
    private static function registry() {
        return array(
            1 => 'migrate_001_logs_and_sync_state',
            2 => 'migrate_002_core_tables',
            3 => 'migrate_003_suggestions',
            4 => 'migrate_004_qoe_columns',
            5 => 'migrate_005_lesson_progress',
            6 => 'migrate_006_lesson_plays',
            7 => 'migrate_007_purge_legacy_credentials',
            8 => 'migrate_008_indexes',
            9 => 'migrate_009_workspace_index',
            10 => 'migrate_010_upload_session_uri',
            11 => 'migrate_011_drop_ready_count',
        );
    }

    /** Every table the plugin owns, by short name. [spec 07] */
    public static function tables() {
        return array(
            'videos', 'playback_ids', 'tracks', 'ai', 'uploads', 'migrations',
            'migration_items', 'usage', 'webhook_events', 'sync_state',
            'analytics_daily', 'watch_progress', 'search_index', 'logs',
            'live_streams', 'audit', 'lesson_progress',
        );
    }

    public static function target_version() {
        return max(array_keys(self::registry()));
    }

    public static function current_version() {
        return (int) get_option(self::OPT_VERSION, 0);
    }

    public static function needs_update() {
        return self::current_version() < self::target_version();
    }

    /**
     * Run every pending migration in order, under a lock. The version advances
     * only when all succeed; a failure stops the run, leaves the version where
     * it was, and records the affected table. [REQ-103]
     *
     * @return true|\WP_Error
     */
    public static function update() {
        if (!self::needs_update()) {
            return true;
        }
        // M12: a failed migration is retried hourly, not on every request (the ALTER
        // and the log line would otherwise repeat on each page load until fixed).
        $failed = get_option(self::OPT_FAILED);
        $error  = null;
        if (is_array($failed) && isset($failed['at']) && time() - (int) $failed['at'] < self::RETRY_AFTER) {
            $error = new \WP_Error('fastpix_schema_failed', (string) $failed['message']);
        } elseif (!self::acquire_lock()) {
            $error = new \WP_Error('fastpix_schema_locked', __('A schema update is already running.', 'fastpix'));
        }
        if ($error) {
            return $error;
        }

        try {
            return self::run_pending(self::current_version());
        } finally {
            self::release_lock();
        }
    }

    /** Every migration newer than $from, in order; stops at the first failure. */
    private static function run_pending($from) {
        foreach (self::registry() as $version => $method) {
            if ($version <= $from) {
                continue;
            }

            $result = call_user_func(array(__CLASS__, $method));

            if (is_wp_error($result)) {
                // Version unchanged; the affected tables go read-only and one
                // notice is raised. [REQ-103]
                update_option(self::OPT_FAILED, array(
                    'version' => $version,
                    'tables'  => (array) $result->get_error_data(),
                    'message' => $result->get_error_message(),
                    'at'      => time(),
                ), false);
                do_action('fastpix_log', 'schema_migration_failed', array(
                    'version'     => $version,
                    'description' => $result->get_error_message(),
                ));

                return $result;
            }

            update_option(self::OPT_VERSION, $version, false);
        }

        delete_option(self::OPT_FAILED);

        return true;
    }

    /**
     * True when a table is held read-only by a failed migration. Callers that
     * write must consult this. [REQ-103]
     */
    public static function is_read_only($table) {
        $failed = get_option(self::OPT_FAILED);

        return is_array($failed) && in_array($table, (array) $failed['tables'], true);
    }

    /** Fully-qualified table name, site-prefixed. [ARCH-08] */
    public static function table($name) {
        global $wpdb;

        return $wpdb->prefix . 'fastpix_' . $name;
    }

    public static function table_exists($name) {
        global $wpdb;

        $table = self::table($name);

        return (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }

    /**
     * v1 — the two tables Phase 1 writes: the log record (DATA-014) and the
     * sync watermark seeded at connect (DATA-010), plus the audit log, which
     * SEC-018/ARCH-13 require to be separate from the error log.
     */
    private static function migrate_001_logs_and_sync_state() {
        global $wpdb;

        require_once ABSPATH . self::WP_UPGRADE_PHP;
        $charset = $wpdb->get_charset_collate();

        // DATA-014 — one column per field of the §22 log record.
        $logs = self::table('logs');
        dbDelta("CREATE TABLE {$logs} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  timestamp datetime NOT NULL,
  severity varchar(20) NOT NULL DEFAULT 'error',
  scope varchar(50) NOT NULL DEFAULT '',
  message text NULL,
  correlation_id char(36) NOT NULL DEFAULT '',
  workspace_id varchar(64) NOT NULL DEFAULT '',
  video_id bigint(20) unsigned NULL,
  media_id varchar(64) NOT NULL DEFAULT '',
  upload_id varchar(64) NOT NULL DEFAULT '',
  migration_id bigint(20) unsigned NULL,
  webhook_event_id varchar(64) NOT NULL DEFAULT '',
  action_id bigint(20) unsigned NULL,
  endpoint varchar(255) NOT NULL DEFAULT '',
  http_method varchar(10) NOT NULL DEFAULT '',
  http_status smallint(5) unsigned NULL,
  error_code varchar(64) NOT NULL DEFAULT '',
  latency_ms int(10) unsigned NULL,
  attempt smallint(5) unsigned NULL,
  max_attempts smallint(5) unsigned NULL,
  actor varchar(64) NOT NULL DEFAULT '',
  context longtext NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY timestamp (timestamp),
  KEY severity_scope (severity,scope),
  KEY correlation_id (correlation_id)
) {$charset};");

        // SEC-018 — audit log, separate from the error log: actor, time, origin address.
        // Spec 07 defines no table for it; columns follow SEC-018's field list.
        $audit = self::table('audit');
        dbDelta("CREATE TABLE {$audit} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  event varchar(64) NOT NULL,
  actor bigint(20) unsigned NOT NULL DEFAULT 0,
  origin_address varchar(100) NOT NULL DEFAULT '',
  context longtext NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY event (event),
  KEY created_at (created_at)
) {$charset};");

        // DATA-010 — deep-sweep resumable offset and health-check timestamps.
        $sync = self::table('sync_state');
        dbDelta("CREATE TABLE {$sync} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  scope varchar(50) NOT NULL,
  workspace_id varchar(64) NOT NULL DEFAULT '',
  watermark_id varchar(64) NOT NULL DEFAULT '',
  watermark_at datetime NULL,
  last_run_at datetime NULL,
  last_success_at datetime NULL,
  last_error text NULL,
  items_seen bigint(20) unsigned NOT NULL DEFAULT 0,
  items_changed bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY scope_workspace (scope,workspace_id)
) {$charset};");

        $missing = array_values(array_filter(
            array('logs', 'audit', 'sync_state'),
            function ($name) { return !self::table_exists($name); }
        ));

        if ($missing) {
            return new \WP_Error(
                'fastpix_schema_create_failed',
                /* translators: %s: comma-separated table names */
                sprintf(__('These tables could not be created: %s', 'fastpix'), implode(', ', $missing)),
                $missing
            );
        }

        return true;
    }

    /**
     * v2 — the remaining tables of spec 07. Column names, keys and indexes are
     * binding; types are chosen (07 states types are NOT SPECIFIED).
     *
     * Tombstones (deleted_at) only on tables mirroring a platform object, so a
     * late webhook cannot recreate something the owner removed; purely local
     * derivatives are pruned outright instead. [spec 07 Common columns]
     */
    /** migrate_002, first half: the media-object tables (DATA-001..007). */
    private static function migrate_002_media_tables($charset) {
        $t = function ($name) { return self::table($name); };

        // DATA-001 — local mirror + WordPress-owned fields.
        dbDelta("CREATE TABLE {$t('videos')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  media_id varchar(64) NOT NULL,
  workspace_id varchar(64) NOT NULL DEFAULT '',
  attachment_id bigint(20) unsigned NULL,
  title text NULL,
  description longtext NULL,
  status varchar(32) NOT NULL DEFAULT '',
  source varchar(20) NOT NULL DEFAULT '',
  access_policy varchar(20) NOT NULL DEFAULT 'public',
  drm_configuration_id varchar(64) NOT NULL DEFAULT '',
  quality_tier varchar(20) NOT NULL DEFAULT '',
  max_resolution varchar(20) NOT NULL DEFAULT '',
  duration_seconds decimal(12,3) NULL,
  aspect_ratio varchar(20) NOT NULL DEFAULT '',
  poster_updated_at datetime NULL,
  mp4_support varchar(20) NOT NULL DEFAULT '',
  moderation_state varchar(32) NOT NULL DEFAULT '',
  ai_state varchar(32) NOT NULL DEFAULT '',
  error_code varchar(64) NOT NULL DEFAULT '',
  author_id bigint(20) unsigned NOT NULL DEFAULT 0,
  platform_updated_at datetime NULL,
  local_updated_at datetime NULL,
  deleted_at datetime NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY media_workspace (media_id,workspace_id),
  KEY status_created (status,created_at),
  KEY source (source),
  KEY access_policy (access_policy),
  KEY author_id (author_id),
  KEY attachment_id (attachment_id),
  KEY workspace_id (workspace_id)
) {$charset};");

        // DATA-002
        dbDelta("CREATE TABLE {$t('playback_ids')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  video_id bigint(20) unsigned NOT NULL,
  playback_id varchar(64) NOT NULL,
  access_policy varchar(20) NOT NULL DEFAULT 'public',
  drm_configuration_id varchar(64) NOT NULL DEFAULT '',
  domain_restrictions text NULL,
  user_agent_restrictions text NULL,
  deleted_at datetime NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY playback_id (playback_id),
  KEY video_id (video_id)
) {$charset};");

        // DATA-003
        dbDelta("CREATE TABLE {$t('tracks')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  video_id bigint(20) unsigned NOT NULL,
  track_id varchar(64) NOT NULL,
  type varchar(32) NOT NULL DEFAULT '',
  language_code varchar(20) NOT NULL DEFAULT '',
  source varchar(32) NOT NULL DEFAULT '',
  state varchar(32) NOT NULL DEFAULT '',
  deleted_at datetime NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY track_id (track_id),
  KEY video_id (video_id)
) {$charset};");

        // DATA-004 — per-item retry hangs off state/error_code.
        dbDelta("CREATE TABLE {$t('ai')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  video_id bigint(20) unsigned NOT NULL,
  kind varchar(32) NOT NULL,
  generated_json longtext NULL,
  state varchar(32) NOT NULL DEFAULT '',
  error_code varchar(64) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY video_kind (video_id,kind)
) {$charset};");

        // DATA-005 — completed rows pruned after 7 days.
        dbDelta("CREATE TABLE {$t('uploads')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  upload_id varchar(64) NOT NULL,
  video_id bigint(20) unsigned NULL,
  signed_url text NULL,
  session_uri text NULL,
  url_expires_at datetime NULL,
  filename text NULL,
  filesize bigint(20) unsigned NULL,
  bytes_sent bigint(20) unsigned NOT NULL DEFAULT 0,
  chunk_size int(10) unsigned NULL,
  state varchar(32) NOT NULL DEFAULT '',
  attempts smallint(5) unsigned NOT NULL DEFAULT 0,
  settings_json longtext NULL,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY upload_id (upload_id),
  KEY state_updated (state,updated_at),
  KEY user_id (user_id)
) {$charset};");

        // DATA-006
        dbDelta("CREATE TABLE {$t('migrations')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  batch_id varchar(64) NOT NULL,
  quality_tier varchar(20) NOT NULL DEFAULT '',
  access_policy varchar(20) NOT NULL DEFAULT '',
  item_count int(10) unsigned NOT NULL DEFAULT 0,
  submitted_count int(10) unsigned NOT NULL DEFAULT 0,
  failed_count int(10) unsigned NOT NULL DEFAULT 0,
  skipped_count int(10) unsigned NOT NULL DEFAULT 0,
  estimated_cost decimal(12,2) NULL,
  state varchar(32) NOT NULL DEFAULT '',
  started_by bigint(20) unsigned NOT NULL DEFAULT 0,
  finished_at datetime NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY batch_id (batch_id),
  KEY state (state)
) {$charset};");

        // DATA-007
        dbDelta("CREATE TABLE {$t('migration_items')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  batch_id varchar(64) NOT NULL,
  attachment_id bigint(20) unsigned NOT NULL,
  video_id bigint(20) unsigned NULL,
  source_url text NULL,
  size_bytes bigint(20) unsigned NULL,
  state varchar(32) NOT NULL DEFAULT '',
  skip_reason varchar(191) NOT NULL DEFAULT '',
  error_code varchar(64) NOT NULL DEFAULT '',
  idempotency_key varchar(64) NOT NULL DEFAULT '',
  reverted_at datetime NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY batch_attachment (batch_id,attachment_id),
  KEY state (state),
  KEY attachment_id (attachment_id),
  KEY idempotency_key (idempotency_key)
) {$charset};");

    }

    /** migrate_002, second half: usage, events, analytics, progress, search, live (DATA-008..015). */
    private static function migrate_002_activity_tables($charset) {
        $t = function ($name) { return self::table($name); };

        // DATA-008 — post associations are the one thing not rebuildable from
        // the platform, so this table is backup-critical (SDD §19).
        dbDelta("CREATE TABLE {$t('usage')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  video_id bigint(20) unsigned NOT NULL,
  post_id bigint(20) unsigned NOT NULL,
  context varchar(32) NOT NULL DEFAULT '',
  occurrences int(10) unsigned NOT NULL DEFAULT 1,
  last_seen_at datetime NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY video_post_context (video_id,post_id,context),
  KEY post_id (post_id)
) {$charset};");

        // DATA-009 — event_id unique IS the dedup/replay defence (ARCH-05).
        dbDelta("CREATE TABLE {$t('webhook_events')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  event_id varchar(191) NOT NULL,
  event_type varchar(100) NOT NULL DEFAULT '',
  object_type varchar(32) NOT NULL DEFAULT '',
  object_id varchar(64) NOT NULL DEFAULT '',
  workspace_id varchar(64) NOT NULL DEFAULT '',
  payload longtext NULL,
  signature_valid tinyint(1) NOT NULL DEFAULT 0,
  received_at datetime NOT NULL,
  processed_at datetime NULL,
  process_state varchar(32) NOT NULL DEFAULT 'pending',
  process_error text NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY event_id (event_id),
  KEY process_state (process_state,received_at),
  KEY received_at (received_at),
  KEY object (object_type,object_id)
) {$charset};");

        // DATA-011 — natural composite key, no id. One dimension per row.
        dbDelta("CREATE TABLE {$t('analytics_daily')} (
  video_id bigint(20) unsigned NOT NULL,
  day date NOT NULL,
  dimension varchar(32) NOT NULL,
  dimension_value varchar(100) NOT NULL,
  views bigint(20) unsigned NOT NULL DEFAULT 0,
  unique_viewers bigint(20) unsigned NOT NULL DEFAULT 0,
  watch_seconds bigint(20) unsigned NOT NULL DEFAULT 0,
  startup_ms_p50 int(10) unsigned NULL,
  startup_ms_p95 int(10) unsigned NULL,
  rebuffer_count bigint(20) unsigned NOT NULL DEFAULT 0,
  rebuffer_seconds bigint(20) unsigned NOT NULL DEFAULT 0,
  error_count bigint(20) unsigned NOT NULL DEFAULT 0,
  fetched_at datetime NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (video_id,day,dimension,dimension_value),
  KEY day (day)
) {$charset};");

        // DATA-012 — coverage semantics are binding; see REQ-064.
        dbDelta("CREATE TABLE {$t('watch_progress')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  video_id bigint(20) unsigned NOT NULL,
  viewer_key varchar(64) NOT NULL,
  furthest_seconds int(10) unsigned NOT NULL DEFAULT 0,
  covered_seconds int(10) unsigned NOT NULL DEFAULT 0,
  coverage_ratio decimal(5,4) NOT NULL DEFAULT 0.0000,
  completed_at datetime NULL,
  last_seen_at datetime NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY video_viewer (video_id,viewer_key),
  KEY last_seen_at (last_seen_at)
) {$charset};");

        // DATA-013 — composite natural key, no id; FULLTEXT on content.
        dbDelta("CREATE TABLE {$t('search_index')} (
  video_id bigint(20) unsigned NOT NULL,
  field varchar(20) NOT NULL,
  segment_index int(10) unsigned NOT NULL DEFAULT 0,
  start_seconds decimal(12,3) NULL,
  end_seconds decimal(12,3) NULL,
  content longtext NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (video_id,field,segment_index),
  FULLTEXT KEY content (content)
) {$charset};");

        // DATA-015
        dbDelta("CREATE TABLE {$t('live_streams')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  stream_id varchar(64) NOT NULL,
  workspace_id varchar(64) NOT NULL DEFAULT '',
  name text NULL,
  status varchar(32) NOT NULL DEFAULT '',
  recording_enabled tinyint(1) NOT NULL DEFAULT 1,
  recorded_video_id bigint(20) unsigned NULL,
  last_active_at datetime NULL,
  deleted_at datetime NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY stream_id (stream_id),
  KEY status (status)
) {$charset};");

    }

    private static function migrate_002_core_tables() {
        global $wpdb;

        require_once ABSPATH . self::WP_UPGRADE_PHP;
        $charset = $wpdb->get_charset_collate();

        self::migrate_002_media_tables($charset);
        self::migrate_002_activity_tables($charset);

        // Verify only the tables that exist as of v2 (migrate_001 + migrate_002).
        // self::tables() is the CURRENT full set, which includes tables added by
        // LATER migrations (lesson_progress at v5). On a fresh install migrate_002
        // runs before those, so filtering self::tables() here would always report
        // lesson_progress missing, return WP_Error, and freeze the schema at v1.
        $v2_tables = array(
            'logs', 'sync_state', 'audit',                              // migrate_001
            'videos', 'playback_ids', 'tracks', 'ai', 'uploads',        // migrate_002
            'migrations', 'migration_items', 'usage', 'webhook_events',
            'analytics_daily', 'watch_progress', 'search_index', 'live_streams',
        );
        $missing = array_values(array_filter($v2_tables, function ($name) {
            return !self::table_exists($name);
        }));

        if ($missing) {
            return new \WP_Error(
                'fastpix_schema_create_failed',
                /* translators: %s: comma-separated table names */
                sprintf(__('These tables could not be created: %s', 'fastpix'), implode(', ', $missing)),
                $missing
            );
        }

        // FULLTEXT is part of the §23 database baseline, but where it genuinely
        // is not available transcript search is disabled and the interface says
        // so — never a LIKE scan. [ARCH-08, RULE-044, DATA-013]
        $has_fulltext = (bool) $wpdb->get_var(
            "SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . esc_sql(self::table('search_index')) . "'
               AND INDEX_TYPE = 'FULLTEXT'"
        );

        update_option('fastpix_fulltext_unavailable', $has_fulltext ? 0 : 1, false);

        if (!$has_fulltext) {
            do_action('fastpix_log', 'fulltext_unavailable', array(
                'scope'   => 'schema',
                'message' => 'FULLTEXT indexing is unavailable on this database; transcript search stays disabled.',
            ));
        }

        return true;
    }

    /**
     * Retention, applied nightly by the fastpix_prune job [spec 07 Common
     * columns]: analytics 25 months; watch progress 12 months since last
     * activity; completed uploads 7 days; logs and webhook payloads 30 days.
     *
     * Tables that do not exist yet, or hold nothing old enough, prune as a
     * no-op — so every retention rule is in force from day one and none has
     * to be remembered when its feature lands.
     *
     * @return array<string,int> Rows (or payloads) removed per table.
     */
    public static function prune() {
        global $wpdb;

        $removed  = array();
        $cutoff   = function ($seconds) { return gmdate('Y-m-d H:i:s', time() - $seconds); };
        $prunable = function ($table) { return self::table_exists($table) && !self::is_read_only($table); };

        // DATA-011 — analytics 25 months, keyed by day.
        if ($prunable('analytics_daily')) {
            $removed['analytics_daily'] = (int) $wpdb->query($wpdb->prepare(
                self::SQL_DELETE . self::table('analytics_daily') . ' WHERE day < %s',
                gmdate('Y-m-d', strtotime('-25 months'))
            ));
        }

        // DATA-012 — watch progress 12 months since last activity.
        if ($prunable('watch_progress')) {
            $removed['watch_progress'] = (int) $wpdb->query($wpdb->prepare(
                self::SQL_DELETE . self::table('watch_progress') . ' WHERE last_seen_at IS NOT NULL AND last_seen_at < %s',
                $cutoff(12 * MONTH_IN_SECONDS)
            ));
        }

        // DATA-005 — completed upload rows after 7 days. Abandoned sessions are
        // the orphan sweep's job (REQ-014), not retention's.
        if ($prunable('uploads')) {
            $removed['uploads'] = (int) $wpdb->query($wpdb->prepare(
                self::SQL_DELETE . self::table('uploads') . " WHERE state = 'completed' AND updated_at < %s",
                $cutoff(7 * DAY_IN_SECONDS)
            ));
        }

        // Unavailable videos (deleted here or on FastPix, or no longer known to it)
        // are removed for good after 30 days — unless a post still embeds them,
        // in which case the record stays so the saved poster keeps rendering.
        // fastpix_unavailable_retention_days: 0 keeps them forever. [ASSUME-102]
        $video_days = (int) apply_filters('fastpix_unavailable_retention_days', 30);
        if ($video_days > 0 && $prunable('videos')) {
            $ids = $wpdb->get_col($wpdb->prepare(
                'SELECT v.id FROM ' . self::table('videos') . ' v LEFT JOIN ' . self::table('usage') . " u ON u.video_id = v.id
                 WHERE (v.deleted_at IS NOT NULL OR v.error_code = 'orphaned') AND v.updated_at < %s AND u.video_id IS NULL
                 ORDER BY v.id ASC LIMIT 200",
                $cutoff($video_days * DAY_IN_SECONDS)
            ));
            $removed['videos'] = 0;
            foreach ($ids as $id) {
                $removed['videos'] += (int) Fastpix_Sync::purge_video((int) $id);
            }
        }

        // DATA-009 — webhook PAYLOADS are pruned at 30 days; the rows stay, so
        // event-id dedup (the replay defence) keeps working. [ARCH-05]
        if ($prunable('webhook_events')) {
            $removed['webhook_payloads'] = (int) $wpdb->query($wpdb->prepare(
                'UPDATE ' . self::table('webhook_events') . ' SET payload = NULL WHERE payload IS NOT NULL AND received_at < %s',
                $cutoff(30 * DAY_IN_SECONDS)
            ));
        }

        // DATA-014 — logs 30 days.
        $removed['logs'] = Fastpix_Log::prune();

        // The audit table records every permission refusal WITH the origin IP —
        // personal data that must not grow forever. 90 days by default (security
        // forensics window), filterable. [SEC-018]
        if ($prunable('audit')) {
            $audit_days = (int) apply_filters('fastpix_audit_retention_days', 90);
            $removed['audit'] = (int) $wpdb->query($wpdb->prepare(
                self::SQL_DELETE . self::table('audit') . ' WHERE created_at < %s',
                $cutoff(max(1, $audit_days) * DAY_IN_SECONDS)
            ));
        }

        return $removed;
    }

    /**
     * v3 — dashboard-edit suggestions [RULE-022]: platform edits to
     * WordPress-owned title/description are stored beside the row, never
     * applied. Add-then-populate: columns land before any code reads them.
     */
    private static function migrate_003_suggestions() {
        global $wpdb;

        $table = self::table('videos');
        foreach (array('suggested_title text NULL', 'suggested_description longtext NULL', 'suggested_at datetime NULL') as $column) {
            $name = explode(' ', $column)[0];
            if (!in_array($name, (array) $wpdb->get_col("DESC {$table}", 0), true)) {
                $wpdb->query("ALTER TABLE {$table} ADD COLUMN {$column}");
            }
        }

        $missing = array_diff(array('suggested_title', 'suggested_description', 'suggested_at'), (array) $wpdb->get_col("DESC {$table}", 0));

        return $missing
            ? new \WP_Error('fastpix_schema_create_failed', __('The suggestion columns could not be added.', 'fastpix'), array('videos'))
            : true;
    }

    /**
     * The nine QoE columns UI-005's card needs. Scores and ratios are 0..1
     * fractions from the platform; bitrate is bps. NULL means "not measured
     * that day", never zero.
     */
    private static function migrate_004_qoe_columns() {
        global $wpdb;

        $table   = self::table('analytics_daily');
        $columns = array(
            'qoe_score decimal(7,6) NULL',
            'score_playback decimal(7,6) NULL',
            'score_startup decimal(7,6) NULL',
            'score_stability decimal(7,6) NULL',
            'score_render decimal(7,6) NULL',
            'playback_failure_pct decimal(7,6) NULL',
            'startup_failure_pct decimal(7,6) NULL',
            'buffer_ratio decimal(7,6) NULL',
            'avg_bitrate bigint(20) unsigned NULL',
        );
        $names = array_map(function ($c) { return explode(' ', $c)[0]; }, $columns);

        foreach ($columns as $column) {
            $name = explode(' ', $column)[0];
            if (!in_array($name, (array) $wpdb->get_col("DESC {$table}", 0), true)) {
                $wpdb->query("ALTER TABLE {$table} ADD COLUMN {$column}");
            }
        }

        $missing = array_diff($names, (array) $wpdb->get_col("DESC {$table}", 0));

        return $missing
            ? new \WP_Error('fastpix_schema_create_failed', __('The QoE columns could not be added.', 'fastpix'), array('analytics_daily'))
            : true;
    }

    /**
     * Per-learner lesson progress — written only when an embed's
     * trackViewer toggle is on. viewer_key is hash_hmac(user_id, salt), never
     * a name; slots packs 100 coverage slots into 13 bytes. Pruned by
     * retention (default 90 days), covered by the privacy exporter/eraser.
     */
    private static function migrate_005_lesson_progress() {
        global $wpdb;

        $table   = self::table('lesson_progress');
        $charset = $wpdb->get_charset_collate();

        require_once ABSPATH . self::WP_UPGRADE_PHP;
        dbDelta("CREATE TABLE {$table} (
  viewer_key varchar(64) NOT NULL,
  post_id bigint(20) unsigned NOT NULL,
  media_id varchar(64) NOT NULL,
  slots varbinary(13) NULL,
  max_position int(10) unsigned NOT NULL DEFAULT 0,
  completed_at datetime NULL,
  updated_at datetime NOT NULL,
  UNIQUE KEY viewer_post_media (viewer_key,post_id,media_id),
  KEY updated_at (updated_at)
) {$charset};");

        return self::table_exists('lesson_progress')
            ? true
            : new \WP_Error('fastpix_schema_create_failed', __('The lesson progress table could not be created.', 'fastpix'), array('lesson_progress'));
    }

    /**
     * Per-segment play COUNTS: one byte per coverage slot (0–250 plays), so
     * rewatched stretches can be shown. The old yes/no bitmap in `slots` stays
     * derived for back-compat.
     */
    private static function migrate_006_lesson_plays() {
        global $wpdb;

        $table = self::table('lesson_progress');
        if (!in_array('plays', (array) $wpdb->get_col("DESC {$table}", 0), true)) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN plays varbinary(100) NULL AFTER slots");
        }

        return in_array('plays', (array) $wpdb->get_col("DESC {$table}", 0), true)
            ? true
            : new \WP_Error('fastpix_schema_create_failed', __('The plays column could not be added.', 'fastpix'), array('lesson_progress'));
    }

    /**
     * SEC-002 one-time cleanup for sites already migrated before this shipped:
     * import_legacy() adopted the v1 pair but earlier builds never deleted the
     * v1 credential options, so they linger in wp_options as readable secret
     * material. Purge them once the sealed v2 pair exists. Gated on has_pair()
     * so a fresh v1→v2 install (schema runs before import_legacy) keeps them for
     * adoption — import_legacy purges them itself right after it seals. Deleting
     * options cannot fail, so the version always advances.
     */
    private static function migrate_007_purge_legacy_credentials() {
        if (class_exists('\Fastpix\Fastpix_Credentials') && Fastpix_Credentials::has_pair()) {
            Fastpix_Credentials::purge_legacy();
        }

        return true;
    }

    /**
     * v8 — two missing indexes on existing installs (fresh installs get them from
     * the CREATE TABLEs). `webhook_events.received_at`: the nightly payload prune
     * filters on received_at alone, which the composite (process_state,received_at)
     * can't serve → full scan. `migration_items.idempotency_key`: bind_pushed_upload
     * looks it up on every upload webhook during a migration → full scan.
     */
    private static function migrate_008_indexes() {
        global $wpdb;

        $add = array(
            array('webhook_events', 'received_at', 'received_at'),
            array('migration_items', 'idempotency_key', 'idempotency_key'),
        );
        foreach ($add as $ix) {
            list($short, $name, $columns) = $ix;
            $table = self::table($short);
            if (!self::table_exists($short)) {
                continue;
            }
            $exists = (bool) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s",
                $table, $name
            ));
            if (!$exists) {
                $wpdb->query("ALTER TABLE {$table} ADD KEY {$name} ({$columns})");
            }
        }

        return true;
    }

    /**
     * Every workspace-scoped read (Videos list, media modal, orphan audit) and the
     * connect-time stamp filter on workspace_id; the unique (media_id, workspace_id)
     * key cannot serve a leading workspace_id lookup. [ASSUME-092]
     */
    private static function migrate_009_workspace_index() {
        global $wpdb;

        if (!self::table_exists('videos')) {
            return true;
        }
        $table  = self::table('videos');
        $exists = (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s",
            $table, 'workspace_id'
        ));
        if (!$exists) {
            $wpdb->query("ALTER TABLE {$table} ADD KEY workspace_id (workspace_id)");
        }

        return true;
    }

    /**
     * uploads.session_uri — the bucket's resumable SESSION address (the one carrying
     * upload_id=), which the SDK creates from the signed URL and which only lived in
     * the running page. Stored so a resume after a page change reopens the same
     * session and continues from the committed byte instead of starting a new one
     * at 0. [ASSUME-101]
     */
    private static function migrate_010_upload_session_uri() {
        global $wpdb;

        if (!self::table_exists('uploads')) {
            return true;
        }
        $table = self::table('uploads');
        if (!in_array('session_uri', (array) $wpdb->get_col("DESC {$table}", 0), true)) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN session_uri text NULL AFTER signed_url");
        }

        return in_array('session_uri', (array) $wpdb->get_col("DESC {$table}", 0), true)
            ? true
            : new \WP_Error('fastpix_schema_create_failed', __('The session_uri column could not be added.', 'fastpix'), array('uploads'));
    }

    /**
     * migrations.ready_count was never read or written (QA M29). dbDelta never drops
     * columns, so existing installs need the explicit ALTER; guarded, so a re-run is a no-op.
     */
    private static function migrate_011_drop_ready_count() {
        global $wpdb;

        if (!self::table_exists('migrations')) {
            return true;
        }
        $table = self::table('migrations');
        if (in_array('ready_count', (array) $wpdb->get_col("DESC {$table}", 0), true)) {
            $wpdb->query("ALTER TABLE {$table} DROP COLUMN ready_count");
        }

        return in_array('ready_count', (array) $wpdb->get_col("DESC {$table}", 0), true)
            ? new \WP_Error('fastpix_schema_create_failed', __('The ready_count column could not be dropped.', 'fastpix'), array('migrations'))
            : true;
    }

    /**
     * M12: a real lock — one INSERT IGNORE on the options table's unique option_name (add_option()
     * is a read-then-write and lets two runners both win — review 2026-09-20); a stale one
     * (crashed runner) is reclaimed after LOCK_TTL with the same atomic step.
     */
    private static function acquire_lock() {
        global $wpdb;

        $insert = function () use ($wpdb) {
            $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", self::LOCK, (string) time()));
            wp_cache_delete(self::LOCK, 'options');

            return (int) $wpdb->rows_affected === 1;
        };
        if ($insert()) {
            return true;
        }
        // (QA M12) Read straight from the table: the row was INSERTed behind the options API, so a persistent object cache's 'notoptions' can still answer "missing".
        $held = (int) $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::LOCK));
        if ($held && time() - $held > self::LOCK_TTL) {
            self::release_lock();

            return $insert();
        }

        return false;
    }

    private static function release_lock() {
        global $wpdb;

        $wpdb->delete($wpdb->options, array('option_name' => self::LOCK));   // (QA M12) delete_option() trusts 'notoptions' and would skip the row
        wp_cache_delete(self::LOCK, 'options');
    }
}
