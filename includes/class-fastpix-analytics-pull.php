<?php
/**
 * Analytics rollup pull jobs — the FastPix data-API side of WF-010, split out
 * of Fastpix_Analytics (class-size rule). Options, constants, and the rollup
 * table shape stay on Fastpix_Analytics; the hooks and the RULE-029 behaviour
 * are unchanged.
 */

namespace Fastpix;

if (!defined('WPINC')) {
    die;
}

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery
// All SQL here runs against the plugin's own tables: identifiers come from the
// Fastpix_Schema::table() registry (a fixed whitelist, never request input) and
// every value travels through $wpdb->prepare(). Core APIs cannot query these tables.

class Fastpix_Analytics_Pull {

    /* ================================================================ jobs */

    /** Hourly [WF-010]: videos viewed in the last 48 hours, plus the site rows. */
    public static function video_job() {
        if (!self::ready()) {
            return;
        }

        $started = time();
        foreach (array(gmdate('Y-m-d'), gmdate('Y-m-d', time() - DAY_IN_SECONDS)) as $day) {
            self::pull_day($day, $started);
        }
        self::pull_hourly();
        update_option(Fastpix_Analytics::OPT_LAST_SUCCESS, time(), false);
    }

    /**
     * Site-wide hourly cache behind the dashboard-matching sub-day ranges
     * (Last 60 mins / 6 hours / 24 hours). Three
     * timeseries calls, stored locally, so the screens still never call
     * FastPix at render (REQ-063). Site scope only — per-video hourly would
     * multiply the pull per video for a range the row can answer at day grain.
     */
    private static function pull_hourly() {
        $client  = new Fastpix_Api_Client();
        $span    = array(time() - 2 * DAY_IN_SECONDS, time());
        $all     = self::hourly_series($client, $span, array());
        $devices = array();
        // Per device too, so a device filter keeps the sub-day ranges (QA: time filter disabled per device).
        // Values are the device_type strings the platform reports — the analytics screen's device options.
        foreach (Fastpix_Analytics::DEVICES as $device) {
            $devices[$device] = self::hourly_series($client, $span, array('filterby[]' => 'device_type:' . $device));
        }
        if ($all === null || in_array(null, $devices, true)) {
            return;   // partial caches mislead — keep the previous one
        }

        update_option(Fastpix_Analytics::OPT_HOURLY, array('fetched_at' => time(), 'hours' => $all, 'devices' => $devices), false);
    }

    /** One scope's hourly views / people / watch time, keyed by interval; null on any API failure. */
    private static function hourly_series($client, $span, $filter) { // NOSONAR php:S100 — WordPress snake_case naming
        $hours = array();
        foreach (array('views' => 'views', 'unique_viewers' => 'people', 'playing_time' => 'watch_ms') as $metric => $key) {
            $r = $client->request('GET', self::data_path("/data/metrics/{$metric}/timeseries", $span, array('granularity' => 'hour') + $filter), array('context' => 'background'));
            if (is_wp_error($r) || !isset($r['body']['data']) || !is_array($r['body']['data'])) {
                return null;
            }
            foreach ($r['body']['data'] as $point) {
                if (!isset($point['intervalTime'])) {
                    continue;
                }
                $at = (string) $point['intervalTime'];
                if (!isset($hours[$at])) {
                    $hours[$at] = array('views' => 0, 'people' => 0, 'watch_ms' => 0);
                }
                $hours[$at][$key] = (float) self::pick($point, 'metricValue');
            }
        }

        return $hours;
    }

    /**
     * Nightly [WF-010]: rewrite the correction window, then walk the backfill
     * watermark further into the past (retention window) until the platform
     * has nothing older or 25 months are covered.
     */
    public static function sweep_job() {
        if (!self::ready()) {
            return;
        }

        $started = time();

        for ($i = 0; $i < Fastpix_Analytics::CORRECTION_DAYS; $i++) {
            if (!self::pull_day(gmdate('Y-m-d', time() - $i * DAY_IN_SECONDS), $started)) {
                return;   // budget spent; the next night continues
            }
        }
        update_option(Fastpix_Analytics::OPT_LAST_SUCCESS, time(), false);

        // Backfill, one chunk per night. Older days are insert-only (RULE-029).
        $mark = (string) get_option(Fastpix_Analytics::OPT_BACKFILL, '');
        if ($mark !== 'done') {
            self::backfill_chunk($mark, $started);
        }
    }

    /** One night's backfill chunk: walk the watermark further into the past. */
    private static function backfill_chunk($mark, $started) {
        $oldest    = $mark !== '' ? strtotime($mark . ' UTC') : time() - Fastpix_Analytics::CORRECTION_DAYS * DAY_IN_SECONDS;
        $floor     = strtotime('-' . Fastpix_Analytics::RETENTION_MONTHS . ' months');
        $any_views = false;

        for ($i = 1; $i <= Fastpix_Analytics::BACKFILL_CHUNK_DAYS; $i++) {
            $ts   = $oldest - $i * DAY_IN_SECONDS;
            $stop = null;
            if ($ts < $floor) {
                $stop = 'done';
            } elseif (time() - $started > Fastpix_Jobs::WALK_BUDGET_SECONDS) {
                $stop = gmdate('Y-m-d', $ts + DAY_IN_SECONDS);
            }
            if ($stop !== null) {
                update_option(Fastpix_Analytics::OPT_BACKFILL, $stop, false);
                return;
            }
            $pulled = self::pull_day(gmdate('Y-m-d', $ts), $started, true);
            if ($pulled === null) {
                return;   // API failure — retry from the same watermark next night
            }
            $any_views = $any_views || $pulled > 0;
            update_option(Fastpix_Analytics::OPT_BACKFILL, gmdate('Y-m-d', $ts), false);
        }

        if (!$any_views) {
            update_option(Fastpix_Analytics::OPT_BACKFILL, 'done', false);   // a whole silent chunk ends the walk
        }
    }

    public static function ready() {
        return Fastpix_Credentials::token_id() !== '' && Fastpix_Api_Client::is_healthy()
            && (string) get_option(Fastpix_Connection::OPT_PENDING_LEAVE, '') === '';   // no pulls into a rollup whose workspace is unresolved
    }

    /**
     * Pull one UTC day: the site rollup plus every video the platform saw that
     * day. Returns the number of active videos, null on API failure, false when
     * the walk budget ran out.
     */
    private static function pull_day($day, $started, $quiet = false) {
        $span   = self::day_span($day);
        $client = new Fastpix_Api_Client();

        // Which videos had views that day? One call answers it.
        $active = self::breakdown($client, $span, 'video_id');
        if ($active === null) {
            if (!$quiet) {
                do_action('fastpix_log', 'analytics_pull_failed', array(
                    'scope' => 'analytics', 'severity' => 'warning',
                    'message' => 'Could not list active videos for ' . $day,
                ));
            }
            return null;
        }

        self::pull_scope($client, $span, $day, 0, null);   // site rows

        global $wpdb;
        // Resume where the last budget-limited run stopped, so the pull ADVANCES
        // through the whole active list across cycles instead of re-pulling the
        // same first-N forever. Keyed per day (video_job walks today+yesterday
        // under one shared budget). Offset over a views-ordered list is best-effort
        // for a still-moving "today"; a full pass then clears the cursor.
        $cursors = (array) get_option(Fastpix_Analytics::OPT_PULL_CURSOR, array());
        $offset  = isset($cursors[$day]) ? (int) $cursors[$day] : 0;
        $total   = count($active);
        $count   = 0;
        for ($i = $offset; $i < $total; $i++) {
            if (time() - $started > Fastpix_Jobs::WALK_BUDGET_SECONDS) {
                self::save_cursor($cursors, $day, $i);
                return false;
            }
            $media_id = (string) $active[$i]['field'];
            $video_id = (int) $wpdb->get_var($wpdb->prepare(
                'SELECT id FROM ' . Fastpix_Schema::table('videos') . ' WHERE media_id = %s',
                $media_id
            ));
            if ($video_id === 0) {
                continue;   // a foreign workspace's video, or one this site never saw
            }
            self::pull_scope($client, $span, $day, $video_id, $media_id);
            $count++;
        }

        if (isset($cursors[$day])) {   // finished the day → next cycle refreshes from the top
            unset($cursors[$day]);
            update_option(Fastpix_Analytics::OPT_PULL_CURSOR, $cursors, false);
        }
        return $count;
    }

    /** Store the resume point, pruning cursors for days no cycle walks any more. */
    private static function save_cursor($cursors, $day, $i) {
        $cursors[$day] = $i;
        $keep = array(gmdate('Y-m-d'), gmdate('Y-m-d', time() - DAY_IN_SECONDS));
        foreach (array_keys($cursors) as $k) {
            if (!in_array($k, $keep, true)) {
                unset($cursors[$k]);
            }
        }
        update_option(Fastpix_Analytics::OPT_PULL_CURSOR, $cursors, false);
    }

    /** The seven calls for one scope (site when $media_id is null) on one day. */
    private static function pull_scope($client, $span, $day, $video_id, $media_id) {
        $filter = $media_id === null ? array() : array('filterby[]' => 'video_id:' . $media_id);

        $views = self::overall($client, $span, 'views', $filter);
        if ($views === null) {
            return;
        }

        $p50    = self::overall($client, $span, 'video_startup_time', $filter + array('measurement' => 'median'));
        $p95    = self::overall($client, $span, 'video_startup_time', $filter + array('measurement' => '95th'));
        $bcount = self::overall($client, $span, 'buffer_count', $filter);
        $bfill  = self::overall($client, $span, 'buffer_fill', $filter);
        $errors = self::breakdown($client, $span, 'error_code', $filter);
        $devices = self::breakdown($client, $span, 'device_type', $filter);

        $qoe = self::pull_qoe($client, $span, $filter);

        $error_count = 0;
        foreach ((array) $errors as $row) {
            if ((string) $row['field'] !== 'null' && (string) $row['field'] !== '') {
                $error_count += (int) $row['views'];
            }
        }

        self::store($video_id, $day, 'all', '', array(
            'views'            => (int) self::pick($views, 'totalViews'),
            'unique_viewers'   => (int) self::pick($views, 'uniqueViews'),
            'watch_seconds'    => (int) round(self::pick($views, 'totalWatchTime') / 1000),
            'startup_ms_p50'   => $p50 === null ? null : (int) self::pick($p50, 'value'),
            'startup_ms_p95'   => $p95 === null ? null : (int) self::pick($p95, 'value'),
            'rebuffer_count'   => $bcount === null ? 0 : (int) self::pick($bcount, 'value'),
            'rebuffer_seconds' => $bfill === null ? 0 : (int) round(self::pick($bfill, 'value') / 1000),
            'error_count'      => $error_count,
        ) + $qoe);

        self::store_breakdowns($video_id, $day, $devices, $errors);
    }

    /**
     * The nine QoE figures. Scores and ratios are 0..1 fractions; NULL = unmeasured.
     * ponytail: nine more calls per scope-day — fold into a comparison
     * endpoint if the pull budget ever pinches.
     */
    private static function pull_qoe($client, $span, $filter) {
        $qoe = array();
        foreach (array(
            'qoe_score'            => 'quality_of_experience_score',
            'score_playback'       => 'playback_score',
            'score_startup'        => 'startup_score',
            'score_stability'      => 'stability_score',
            'score_render'         => 'render_quality_score',
            'playback_failure_pct' => 'playback_failure_percentage',
            'startup_failure_pct'  => 'video_startup_failure_percentage',
            'buffer_ratio'         => 'buffer_ratio',
            'avg_bitrate'          => 'average_bitrate',
        ) as $column => $metric) {
            $answer = self::overall($client, $span, $metric, $filter);
            if ($answer === null || !isset($answer['value']) || !is_numeric($answer['value'])) {
                $qoe[$column] = null;
            } elseif ($column === 'avg_bitrate') {
                $qoe[$column] = (int) round($answer['value']);
            } else {
                $qoe[$column] = round((float) $answer['value'], 6);
            }
        }

        return $qoe;
    }

    /** The per-device and per-error-code rows of one scope-day. */
    private static function store_breakdowns($video_id, $day, $devices, $errors) {
        foreach ((array) $devices as $row) {
            if ((string) $row['field'] === '') {
                continue;
            }
            self::store($video_id, $day, 'device', (string) $row['field'], array(
                'views'         => (int) $row['views'],
                'watch_seconds' => (int) round((float) $row['totalWatchTime'] / 1000),
            ));
        }

        foreach ((array) $errors as $row) {
            if ((string) $row['field'] === 'null' || (string) $row['field'] === '') {
                continue;
            }
            self::store($video_id, $day, 'error_code', (string) $row['field'], array(
                'views' => (int) $row['views'],
            ));
        }
    }

    /**
     * Upsert one rollup row. Days inside the correction window are rewritten;
     * an older day is written only if absent — an attempted change to an
     * existing one is logged, never applied (RULE-029).
     */
    public static function store($video_id, $day, $dimension, $value, $fields) {
        global $wpdb;

        $now      = current_time('mysql', true);
        $table    = Fastpix_Schema::table('analytics_daily');
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE video_id = %d AND day = %s AND dimension = %s AND dimension_value = %s",
            $video_id, $day, $dimension, $value
        ), ARRAY_A);

        $immutable = strtotime($day . ' UTC') < time() - Fastpix_Analytics::CORRECTION_DAYS * DAY_IN_SECONDS;

        if ($existing && $immutable) {
            foreach ($fields as $k => $v) {
                if ($v !== null && (string) $existing[$k] !== (string) $v) {
                    do_action('fastpix_log', 'analytics_immutable_change', array(
                        'scope' => 'analytics', 'severity' => 'warning', 'video_id' => $video_id,
                        'message' => sprintf('Corrected data arrived for %s outside the correction window (%s %s: %s → %s) — not applied', $day, $dimension, $k, $existing[$k], $v),
                    ));
                    break;
                }
            }
            return;
        }

        $fields += array('fetched_at' => $now, 'updated_at' => $now);
        if ($existing) {
            $wpdb->update($table, $fields, array(
                'video_id' => $video_id, 'day' => $day, 'dimension' => $dimension, 'dimension_value' => $value,
            ));
        } else {
            $wpdb->insert($table, $fields + array(
                'video_id' => $video_id, 'day' => $day, 'dimension' => $dimension,
                'dimension_value' => $value, 'created_at' => $now,
            ));
        }
    }

    /* ----------------------------------------------------- data API access */

    private static function day_span($day) {
        $start = strtotime($day . ' 00:00:00 UTC');

        return array($start, $start + DAY_IN_SECONDS);
    }

    /**
     * The data API's repeated `timespan[]` keys must reach the wire verbatim —
     * add_query_arg would rewrite them as timespan[0]/timespan[1], which the
     * platform refuses ("Invalid timespan value") — so the query string is
     * built by hand and the client gets no 'query' to merge.
     */
    private static function data_path($path, $span, $extra) {
        $qs = "timespan[]={$span[0]}&timespan[]={$span[1]}";
        foreach ($extra as $key => $value) {
            $qs .= '&' . $key . '=' . rawurlencode((string) $value);
        }

        return $path . '?' . $qs;
    }

    /** GET /data/metrics/{id}/overall — the whole body's data object, or null. */
    private static function overall($client, $span, $metric, $extra = array()) {
        $r = $client->request('GET', self::data_path("/data/metrics/{$metric}/overall", $span, $extra), array('context' => 'background'));
        if (is_wp_error($r) || empty($r['body']['data']) || !is_array($r['body']['data'])) {
            return is_wp_error($r) ? null : array();
        }

        return $r['body']['data'];
    }

    /**
     * GET /data/metrics/views/breakdown — rows of {field, views, totalWatchTime},
     * or null on failure. The platform caps limit at 50 (100 is refused with
     * "payload validation failed"), so busy days page by offset.
     */
    private static function breakdown($client, $span, $group_by, $extra = array()) {
        $rows = array();

        for ($offset = 1; $offset <= 10; $offset++) {
            $r = $client->request('GET', self::data_path('/data/metrics/views/breakdown', $span, $extra + array('groupBy' => $group_by, 'limit' => 50, 'offset' => $offset)), array('context' => 'background'));
            if (is_wp_error($r)) {
                return null;
            }
            $page = isset($r['body']['data']) && is_array($r['body']['data']) ? $r['body']['data'] : array();
            $rows = array_merge($rows, $page);

            $pages = isset($r['body']['pagination']['offsetCount']) ? (int) $r['body']['pagination']['offsetCount'] : 1;
            if ($offset >= $pages || count($page) === 0) {
                break;
            }
        }

        return array_map(function ($row) {
            return array(
                'field'          => isset($row['field']) ? (string) $row['field'] : '',
                'views'          => isset($row['views']) ? (int) $row['views'] : (int) self::pick($row, 'value'),
                'totalWatchTime' => (float) self::pick($row, 'totalWatchTime'),
            );
        }, $rows);
    }

    private static function pick($arr, $key) {
        return isset($arr[$key]) && is_numeric($arr[$key]) ? (float) $arr[$key] : 0;
    }
}
