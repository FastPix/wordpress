<?php
/**
 * Search index — DATA-013, REQ-032, RULE-044, ARCH-08.
 *
 * One row per indexed field (title, description, chapter, summary, entity) and
 * one per thirty-second transcript segment, so matches return timestamps.
 * FULLTEXT drives the search; where FULLTEXT is genuinely unavailable the
 * transcript search is disabled and the interface says so — never a LIKE scan.
 */

namespace Fastpix;

if (!defined('WPINC')) {
    die;
}

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery
// All SQL here runs against the plugin's own tables: identifiers come from the
// Fastpix_Schema::table() registry (a fixed whitelist, never request input) and
// every value travels through $wpdb->prepare(). Core APIs cannot query these tables.

class Fastpix_Search {

    const SEGMENT_SECONDS = 30;

    public static function boot() {
        add_action('fastpix_search_reindex', array(__CLASS__, 'reindex_job'));
    }

    public static function available() {
        return !get_option('fastpix_fulltext_unavailable');
    }

    /**
     * Job handler: reindex one video (args carry video_id) or walk everything.
     * Reindexed on change, never on read. [DATA-013]
     */
    public static function reindex_job($args = array()) {
        $video_id = isset($args['video_id']) ? (int) $args['video_id'] : 0;

        if ($video_id) {
            self::reindex_video($video_id);

            return;
        }

        // Full walk, inside the action budget; long libraries continue next run
        // FROM WHERE THIS RUN STOPPED (`after` = the last id reindexed). [QA X1]
        global $wpdb;
        $started = microtime(true);
        $budget  = (float) apply_filters('fastpix_search_reindex_budget', 15);
        $after   = isset($args['after']) ? (int) $args['after'] : 0;
        $ids     = $wpdb->get_col($wpdb->prepare('SELECT id FROM ' . Fastpix_Schema::table('videos') . ' WHERE deleted_at IS NULL AND id > %d ORDER BY id', $after));

        foreach ($ids as $id) {
            self::reindex_video((int) $id);
            if ((microtime(true) - $started) > $budget) {
                Fastpix_Jobs::enqueue('fastpix_search_reindex', array('after' => (int) $id), Fastpix_Jobs::GROUP_MAINTENANCE);

                return;
            }
        }
    }

    /** Rebuild every index row for one video. */
    public static function reindex_video($video_id) {
        global $wpdb;

        $video = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . Fastpix_Schema::table('videos') . ' WHERE id = %d', (int) $video_id
        ), ARRAY_A);

        $table = Fastpix_Schema::table('search_index');
        $wpdb->delete($table, array('video_id' => (int) $video_id));

        if (!$video || $video['deleted_at'] !== null) {
            return 0;   // tombstoned videos leave the index
        }

        $now  = current_time('mysql', true);
        $rows = 0;
        $add  = function ($field, $segment, $content, $start = null, $end = null) use (&$rows, $wpdb, $table, $video_id, $now) {
            $content = trim(wp_strip_all_tags((string) $content));
            if ($content === '') {
                return;
            }
            $wpdb->insert($table, array(
                'video_id'      => (int) $video_id,
                'field'         => $field,
                'segment_index' => (int) $segment,
                'start_seconds' => $start,
                'end_seconds'   => $end,
                'content'       => $content,
                'created_at'    => $now,
                'updated_at'    => $now,
            ));
            $rows++;
        };

        $add('title', 0, $video['title']);
        $add('description', 0, $video['description']);
        self::index_ai($add, $video_id);

        return $rows;
    }

    /** AI outputs [REQ-032: search reads chapters; entities feed AI search]. */
    private static function index_ai($add, $video_id) {
        global $wpdb;

        $ai = $wpdb->get_results($wpdb->prepare(
            'SELECT kind, generated_json FROM ' . Fastpix_Schema::table('ai') . " WHERE video_id = %d AND state = 'ready'",
            (int) $video_id
        ), ARRAY_A);

        foreach ($ai as $output) {
            $data = json_decode((string) $output['generated_json'], true);

            if ($output['kind'] === 'chapters' && is_array($data)) {
                self::index_chapters($add, $data);
            } elseif ($output['kind'] === 'summary') {
                $add('summary', 0, is_string($data) ? $data : wp_json_encode($data));
            } elseif ($output['kind'] === 'entities' && is_array($data)) {
                self::index_entities($add, $data);
            } elseif ($output['kind'] === 'transcript') {
                self::index_transcript($add, $data);
            }
        }
    }

    /** Entity names joined into one searchable row. */
    private static function index_entities($add, $data) {
        $names = array();
        foreach ($data as $entity) {
            $names[] = is_array($entity) ? (string) Fastpix_Sync::field($entity, array('name', 'value', 'entity')) : (string) $entity;
        }
        $add('entity', 0, implode(' ', array_filter($names)));
    }

    /** One row per chapter, carrying its start/end so matches can seek. */
    private static function index_chapters($add, $data) {
        foreach (array_values($data) as $i => $chapter) {
            if (!is_array($chapter)) {
                continue;
            }
            $add('chapter', $i,
                trim(Fastpix_Sync::field($chapter, array('title', 'value', 'chapter')) . ' ' . (string) Fastpix_Sync::field($chapter, array('summary', 'description'))),
                self::seconds(Fastpix_Sync::field($chapter, array('startTime', 'start', 'start_seconds'))),
                self::seconds(Fastpix_Sync::field($chapter, array('endTime', 'end', 'end_seconds')))
            );
        }
    }

    /** One row per thirty-second segment, so matches return timestamps. [DATA-013] */
    private static function index_transcript($add, $data) {
        // Cue list (start/end/text) — segments are grouped into 30 s buckets.
        if (is_array($data) && isset($data[0]) && is_array($data[0])) {
            $buckets = array();
            foreach ($data as $cue) {
                $start = (float) self::seconds(Fastpix_Sync::field($cue, array('startTime', 'start')));
                $text  = (string) Fastpix_Sync::field($cue, array('text', 'value', 'content'));
                $bucket = (int) floor($start / self::SEGMENT_SECONDS);
                $buckets[$bucket] = (isset($buckets[$bucket]) ? $buckets[$bucket] . ' ' : '') . $text;
            }
            foreach ($buckets as $bucket => $text) {
                $add('transcript', $bucket, $text, $bucket * self::SEGMENT_SECONDS, ($bucket + 1) * self::SEGMENT_SECONDS);
            }

            return;
        }

        // A flat text transcript has no timings: one segment, timestamps unknown.
        $add('transcript', 0, is_string($data) ? $data : wp_json_encode($data));
    }

    /**
     * Search across titles, transcripts and chapters; matches carry timestamps.
     * [REQ-032]
     *
     * @return array|\WP_Error rows {video_id, field, start_seconds, snippet, score};
     *                         WP_Error fastpix_search_disabled when FULLTEXT is absent.
     */
    public static function query($term, $limit = 25) {
        global $wpdb;

        $term = trim((string) $term);
        if ($term === '') {
            return array();
        }

        if (!self::available()) {
            // Disabled and said so — never a LIKE scan. [RULE-044]
            return new \WP_Error(
                'fastpix_search_disabled',
                __('Transcript search is unavailable: this database has no FULLTEXT support. Title search still works.', 'fastpix')
            );
        }

        $table = Fastpix_Schema::table('search_index');

        return $wpdb->get_results($wpdb->prepare(
            "SELECT video_id, field, segment_index, start_seconds,
                    SUBSTRING(content, 1, 160) AS snippet,
                    MATCH(content) AGAINST (%s IN NATURAL LANGUAGE MODE) AS score
             FROM {$table}
             WHERE MATCH(content) AGAINST (%s IN NATURAL LANGUAGE MODE)
             ORDER BY score DESC
             LIMIT %d",
            $term, $term, (int) $limit
        ), ARRAY_A);
    }

    public static function seconds($value) {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return (float) $value;
        }
        // "hh:mm:ss" / "mm:ss"
        $parts = array_reverse(explode(':', (string) $value));
        $seconds = 0.0;
        foreach ($parts as $i => $part) {
            $seconds += ((float) $part) * pow(60, $i);
        }

        return $seconds;
    }
}
