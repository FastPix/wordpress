<?php
/**
 * Video routes — API-P01 (list/read/update/delete), API-P02 (bulk),
 * API-P03 (AI re-run), API-P06 (embed/usage). FR-030…FR-034, WF-011.
 *
 * Own-video limits are applied as a QUERY CONDITION, never a response filter
 * (REQ-091): a caller holding only the _own capabilities gets SQL scoped to
 * their author_id before a row is ever read.
 */

namespace Fastpix;

if (!defined('WPINC')) {
    die;
}

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery
// All SQL here runs against the plugin's own tables: identifiers come from the
// Fastpix_Schema::table() registry (a fixed whitelist, never request input) and
// every value travels through $wpdb->prepare(). Core APIs cannot query these tables.

class Fastpix_Videos_Rest {

    const PER_PAGE = 25;   // admin lists server-paged at 25 rows [REQ-039]

    /** The platform's VOD path prefix (media id appended, rawurlencoded). */
    const ONDEMAND = '/on-demand/';
    const SELECT_ALL = 'SELECT * FROM ';
    const TRACK_STUCK_AFTER = 1800;   // seconds a track may sit in "generating" before it reads as failed (QA B7)

    public static function boot() {
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
        add_action('fastpix_bulk_item', array(__CLASS__, 'bulk_item_job'));
    }

    public static function register_routes() {
        Fastpix_Rest::register('/videos', array(
            'methods'    => 'GET',
            'callback'   => array(__CLASS__, 'list_videos'),
            'capability' => Fastpix_Capabilities::VIEW_VIDEOS,
            'args'       => array_merge(Fastpix_Rest::pagination_args(), array(
                'search'  => Fastpix_Rest::arg('string'),
                'status'  => Fastpix_Rest::arg('string'),
                'access'  => Fastpix_Rest::arg('string', array('enum' => array('public', 'private', 'drm'))),
                'source'  => Fastpix_Rest::arg('string'),
                'orderby' => Fastpix_Rest::arg('string', array('enum' => array('id', 'title'), 'default' => 'id')),
                'order'   => Fastpix_Rest::arg('string', array('enum' => array('asc', 'desc'), 'default' => 'desc')),
            )),
        ));

        Fastpix_Rest::register('/videos/(?P<id>\d+)', array(
            array(
                'methods'    => 'GET',
                'callback'   => array(__CLASS__, 'get_video'),
                'capability' => Fastpix_Capabilities::VIEW_VIDEOS,
            ),
            array(
                'methods'    => 'PATCH',
                'callback'   => array(__CLASS__, 'update_video'),
                'capability' => Fastpix_Capabilities::EDIT_VIDEO_OWN,   // scoped further in own_scope()
                'args'       => array(
                    'title'        => Fastpix_Rest::arg('string'),
                    'description'  => Fastpix_Rest::arg('string', array('sanitize_callback' => 'sanitize_textarea_field')),
                    'downloadable' => Fastpix_Rest::arg('string', array('enum' => array('off', 'video', 'audio', 'both'))),
                    'tracks'       => array('type' => 'array', 'required' => false),
                    // accept copies the suggestion into the local field; dismiss
                    // discards it. [RULE-022]
                    'suggestion'   => Fastpix_Rest::arg('string', array('enum' => array('accept_title', 'accept_description', 'dismiss'))),
                ),
            ),
            array(
                'methods'    => 'DELETE',
                'callback'   => array(__CLASS__, 'delete_video'),
                'capability' => Fastpix_Capabilities::DELETE_VIDEO_OWN,
                'args'       => array(
                    // MISS-005: the parameter shape is unspecified; a boolean is
                    // the smallest honest contract.
                    'delete_on_platform' => Fastpix_Rest::arg('boolean', array('default' => false)),
                    // Remove an Unavailable record for good (the library's "Remove from library"). [ASSUME-102]
                    'purge'              => Fastpix_Rest::arg('boolean', array('default' => false)),
                ),
            ),
        ));

        Fastpix_Rest::register('/videos/bulk', array(
            'methods'    => 'POST',
            'callback'   => array(__CLASS__, 'bulk'),
            'capability' => Fastpix_Capabilities::EDIT_VIDEO_OWN,
            'args'       => array(
                // Not `required`: WordPress checks required args BEFORE the permission callback, which
                // told anonymous callers the parameter names; the callback refuses them itself. [QA B6]
                'action' => Fastpix_Rest::arg('string'),
                'ids'    => array('type' => 'array', 'items' => array('type' => 'integer')),
                'delete_on_platform' => Fastpix_Rest::arg('boolean', array('default' => false)),
            ),
        ));

        Fastpix_Rest::register('/videos/(?P<id>\d+)/ai', array(
            'methods'    => 'POST',
            'callback'   => array(__CLASS__, 'rerun_ai'),
            'capability' => Fastpix_Capabilities::EDIT_VIDEO_OWN,   // own_row() scopes edit-own users to their rows [QA L15]
            'args'       => array('kind' => Fastpix_Rest::arg('string')),
        ));

        // The block editor resolves its saved identifier (a media id) here — the list's
        // search reads the FULLTEXT index, which never matches an id. [QA L9]
        Fastpix_Rest::register('/videos/by-media/(?P<media_id>[A-Za-z0-9_-]+)', array(
            'methods'    => 'GET',
            'callback'   => array(__CLASS__, 'get_by_media'),
            'capability' => Fastpix_Capabilities::VIEW_VIDEOS,
        ));

        // Subtitle file upload [FR-031 "Upload a .vtt or .srt"]: the platform
        // fetches tracks by URL, so the file lands in the Media Library first
        // and its URL feeds POST /tracks.
        Fastpix_Rest::register('/videos/(?P<id>\d+)/track-file', array(
            'methods'    => 'POST',
            'callback'   => array(__CLASS__, 'track_file'),
            'capability' => Fastpix_Capabilities::EDIT_VIDEO_OWN,
        ));

        Fastpix_Rest::register('/videos/(?P<id>\d+)/embed', array(
            'methods'    => 'GET',
            'callback'   => array(__CLASS__, 'embed'),
            'capability' => Fastpix_Capabilities::VIEW_VIDEOS,
        ));

        Fastpix_Rest::register('/videos/(?P<id>\d+)/usage', array(
            'methods'    => 'GET',
            'callback'   => array(__CLASS__, 'usage'),
            'capability' => Fastpix_Capabilities::VIEW_VIDEOS,
        ));
    }

    /* ------------------------------------------------------------- listing */

    /**
     * Keyset pagination on id DESC: `after` is the last id of the previous
     * page; every query is LIMITed. [REQ-039, ARCH-08]
     */
    public static function list_videos($request) {
        global $wpdb;

        $table = Fastpix_Schema::table('videos');
        list($where, $params) = self::list_filters($request);
        list($cursor_where, $cursor_params, $orderby, $order) = self::list_cursor($request);

        $search = trim((string) $request->get_param('search'));
        $found  = $search === '' ? null : self::list_search($search, $where, $params);
        if ($found !== null && $found['empty']) {
            return rest_ensure_response(array('videos' => array(), 'next' => null, 'total' => 0, 'search_note' => null));
        }
        $search_note = $found !== null ? $found['note'] : null;
        $timestamps  = $found !== null ? $found['timestamps'] : array();

        $per_page = min(100, max(1, (int) $request->get_param('per_page') ?: self::PER_PAGE));
        $order_sql = $orderby === 'title' ? "v.title {$order}, v.id {$order}" : "v.id {$order}";
        $count_sql = "SELECT COUNT(*) FROM {$table} v WHERE (" . implode(') AND (', $where) . ')';
        $total     = (int) ($params ? $wpdb->get_var($wpdb->prepare($count_sql, $params)) : $wpdb->get_var($count_sql));
        $all_where = array_merge($where, $cursor_where);
        $all_params = array_merge($params, $cursor_params);
        $sql      = "SELECT v.* FROM {$table} v WHERE (" . implode(') AND (', $all_where) . ") ORDER BY {$order_sql} LIMIT " . ((int) $per_page + 1);
        $rows     = $all_params ? $wpdb->get_results($wpdb->prepare($sql, $all_params), ARRAY_A) : $wpdb->get_results($sql, ARRAY_A);

        $next = null;
        if (count($rows) > $per_page) {
            array_pop($rows);
            $last = end($rows);
            $next = $orderby === 'title'
                ? base64_encode(wp_json_encode(array('t' => $last['title'], 'i' => (int) $last['id'])))
                : (int) $last['id'];
        }

        return rest_ensure_response(array(
            'videos'      => array_map(function ($row) use ($timestamps) {
                $shaped = self::shape($row);
                if (isset($timestamps[$row['id']])) {
                    $shaped['match'] = $timestamps[$row['id']];
                }

                return $shaped;
            }, $rows),
            'next'        => $next,
            'total'       => $total,
            'search_note' => $search_note,
        ));
    }

    /** The list's base conditions (never the cursor), as (where, params). */
    private static function list_filters($request) {
        $where  = array('1=1');   // tombstoned rows stay listed, shown as Unavailable [FR-034]
        $params = array();

        $scope = self::own_scope(Fastpix_Capabilities::EDIT_VIDEO);
        if ($scope !== null) {
            $where[]  = 'v.author_id = %d';   // own-limit as a query condition [REQ-091]
            $params[] = $scope;
        }

        // Only the connected workspace's videos are listed. Rows from a previously
        // connected workspace stay in the table (posts keep their embeds; switching
        // back lists them again) but never show here. The clause always applies:
        // until the new workspace's UUID is learned (an empty workspace teaches
        // nothing) only rows with no workspace recorded — this connection's own
        // uploads — are listed, never the previous workspace's library.
        $where[]  = "(v.workspace_id = %s OR v.workspace_id = '')";
        $params[] = self::connected_workspace();

        foreach (array('status' => 'v.status', 'access' => 'v.access_policy', 'source' => 'v.source') as $param => $column) {
            $value = $request->get_param($param);
            if ($value === null || $value === '') {
                continue;
            }
            if ($param === 'status' && $value === 'Unavailable') {   // tombstoned or orphaned — the listed "Unavailable" [ASSUME-102]
                $where[] = "(v.deleted_at IS NOT NULL OR v.error_code = 'orphaned')";
                continue;
            }
            $where[]  = "{$column} = %s";
            $params[] = $value;
            if ($param === 'status') {
                $where[] = "v.deleted_at IS NULL AND v.error_code <> 'orphaned'";   // an orphan keeps its old status; it is not "Ready"
            }
        }

        return array($where, $params);
    }

    /**
     * Ordering: id (default) or title, both keyset-paged [FR-030, REQ-039].
     * The title cursor is composite (title, id) so duplicate titles page
     * stably; it travels base64-encoded in the same `after` param.
     *
     * The cursor is kept apart so the total (footer count) is the whole result
     * set. Returns (cursor_where, cursor_params, orderby, order).
     */
    private static function list_cursor($request) {
        $orderby = $request->get_param('orderby') === 'title' ? 'title' : 'id';
        $order   = strtolower((string) $request->get_param('order')) === 'asc' ? 'ASC' : 'DESC';
        $cmp     = $order === 'ASC' ? '>' : '<';
        $after   = (string) $request->get_param('after');

        $cursor_where = array();
        $cursor_params = array();
        if ($after !== '') {
            if ($orderby === 'title') {
                $cursor = json_decode(base64_decode($after), true);
                if (is_array($cursor) && isset($cursor['t'], $cursor['i'])) {
                    $cursor_where[]  = "(v.title {$cmp} %s OR (v.title = %s AND v.id {$cmp} %d))";
                    $cursor_params[] = (string) $cursor['t'];
                    $cursor_params[] = (string) $cursor['t'];
                    $cursor_params[] = (int) $cursor['i'];
                }
            } elseif ((int) $after > 0) {
                $cursor_where[]  = "v.id {$cmp} %d";
                $cursor_params[] = (int) $after;
            }
        }

        return array($cursor_where, $cursor_params, $orderby, $order);
    }

    /**
     * Apply the search to the base conditions. 'empty' = the index matched
     * nothing (the list short-circuits); 'timestamps' carries the timestamped
     * matches that ride along per video [REQ-032].
     */
    private static function list_search($search, &$where, &$params) {
        global $wpdb;

        $out     = array('note' => null, 'empty' => false, 'timestamps' => array());
        $matches = Fastpix_Search::query($search, 200);

        if (is_wp_error($matches)) {
            $out['note'] = $matches->get_error_message();   // RULE-044: disabled and said so
            $where[]     = 'v.title LIKE %s';
            $params[]    = '%' . $wpdb->esc_like($search) . '%';

            return $out;
        }

        $ids = array_map('intval', array_unique(array_column($matches, 'video_id')));
        // Title matches join the index matches rather than replacing them.
        $title_ids = $wpdb->get_col($wpdb->prepare(
            'SELECT id FROM ' . Fastpix_Schema::table('videos') . " WHERE title LIKE %s AND (workspace_id = %s OR workspace_id = '') LIMIT 200",
            '%' . $wpdb->esc_like($search) . '%', self::connected_workspace()
        ));
        $ids = array_unique(array_merge($ids, array_map('intval', $title_ids)));
        if (!$ids) {
            $out['empty'] = true;

            return $out;
        }
        $where[] = 'v.id IN (' . implode(',', $ids) . ')';

        foreach ($matches as $match) {
            if ($match['start_seconds'] !== null && !isset($out['timestamps'][$match['video_id']])) {
                $out['timestamps'][$match['video_id']] = array(
                    'field'   => $match['field'],
                    'seconds' => (float) $match['start_seconds'],
                    'snippet' => $match['snippet'],
                );
            }
        }

        return $out;
    }

    /** The UUID FastPix uses for the workspace the current credentials belong to ('' until learned). */
    public static function connected_workspace() {
        return (string) get_option(Fastpix_Connection::OPT_WORKSPACE_SEEN_ID, '');
    }

    /** GET /videos/by-media/{mediaId}: the row for a media id (a live row wins over a tombstone), shaped like get_video. */
    public static function get_by_media($request) {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            self::SELECT_ALL . Fastpix_Schema::table('videos') . ' WHERE media_id = %s ORDER BY deleted_at IS NULL DESC, id DESC LIMIT 1',
            (string) $request['media_id']
        ), ARRAY_A);
        if (!$row) {
            return new \WP_Error('fastpix_video_missing', __('No such video.', 'fastpix'), array('status' => 404));
        }
        // Someone else's video embedded in a post the caller edits: only what the block
        // inspector reads and the front end already shows — this route only. (QA L9, ASSUME-106)
        $scope = self::own_scope(Fastpix_Capabilities::EDIT_VIDEO);
        if ($scope !== null && (int) $row['author_id'] !== $scope) {
            return rest_ensure_response(array_intersect_key(self::shape($row), array_flip(array('media_id', 'title', 'status', 'access_policy', 'duration', 'poster'))));
        }
        $request->set_param('id', (int) $row['id']);

        return self::get_video($request);
    }

    public static function get_video($request) {
        $row = self::own_row($request, Fastpix_Capabilities::EDIT_VIDEO);
        if (is_wp_error($row)) {
            return $row;
        }

        global $wpdb;
        $shaped = self::shape($row, true);
        // A track "generating" past the threshold lost its webhook: report it as
        // failed (read-only — the row is untouched) so the panel offers Retry. (QA B7)
        $shaped['tracks'] = $wpdb->get_results($wpdb->prepare(
            'SELECT track_id, type, language_code, source, IF(state = \'generating\' AND updated_at < %s, \'failed\', state) AS state, updated_at FROM ' . Fastpix_Schema::table('tracks') . '
             WHERE video_id = %d AND deleted_at IS NULL',
            gmdate('Y-m-d H:i:s', time() - self::TRACK_STUCK_AFTER), (int) $row['id']
        ), ARRAY_A);
        // Per-kind state + the output as the platform returned it (RULE-012 —
        // read-only; the client treats it as untrusted text). Badge counts ride along.
        $shaped['ai']       = Fastpix_Ai::rows((int) $row['id'], true);
        $shaped['ai_badge'] = Fastpix_Ai::badge((int) $row['id']);

        // What the opened row shows beside the editable side (UI-003).
        $author = get_userdata((int) $row['author_id']);
        $shaped['author_name'] = $author ? $author->display_name : '';
        $shaped['created_at']  = $row['created_at'];
        $shaped['embed'] = array(
            'shortcode'   => '[fastpix id="' . $row['media_id'] . '"]',
            'constraints' => self::access_constraints($row['access_policy']),
        );
        $item = Fastpix_Schema::table_exists('migration_items') ? $wpdb->get_row($wpdb->prepare(
            'SELECT id, batch_id, attachment_id, state, reverted_at FROM ' . Fastpix_Schema::table('migration_items') . " WHERE video_id = %d AND state IN ('submitted','cleaned') ORDER BY id DESC LIMIT 1",
            (int) $row['id']
        ), ARRAY_A) : null;
        if ($item) {
            $path = get_attached_file((int) $item['attachment_id']);
            $shaped['migration'] = array(
                'item_id' => (int) $item['id'], 'batch_id' => $item['batch_id'], 'attachment_id' => (int) $item['attachment_id'],
                'path' => $path ? str_replace(ABSPATH, '', $path) : '', 'reverted_at' => $item['reverted_at'],
                'file_present' => (bool) ($path && file_exists($path)), 'cleaned' => $item['state'] === 'cleaned',
            );
        }

        // Dashboard-edit suggestions [RULE-022].
        $shaped['suggestions'] = array_filter(array(
            'title'       => isset($row['suggested_title']) ? $row['suggested_title'] : null,
            'description' => isset($row['suggested_description']) ? $row['suggested_description'] : null,
        ));

        return rest_ensure_response($shaped);
    }

    /* ------------------------------------------------------------- editing */

    /** PATCH: WordPress-owned fields + per-video settings + track operations. */
    public static function update_video($request) {
        global $wpdb;

        $row = self::own_row($request, Fastpix_Capabilities::EDIT_VIDEO);
        if (is_wp_error($row)) {
            return $row;
        }

        $update = self::collect_update($request, $row);

        // The title goes to the platform LAST: a refused track/download op must not
        // leave the dashboard renamed while the local row keeps the old name. [QA L18]
        $failed = self::apply_downloadable($row, $request->get_param('downloadable'), $update);
        if (!is_wp_error($failed)) {
            foreach ((array) $request->get_param('tracks') as $op) {
                $failed = self::track_operation($row, (array) $op);
                if (is_wp_error($failed)) {
                    break;
                }
            }
        }
        if (!is_wp_error($failed)) {
            $failed = self::push_title($row, isset($update['title']) ? $update['title'] : null);
        }
        if (is_wp_error($failed)) {
            return $failed;
        }

        if ($update) {
            $update['local_updated_at'] = current_time('mysql', true);
            $update['updated_at']       = current_time('mysql', true);
            if (isset($update['title']) && $update['title'] !== (string) $row['title']) {
                // The platform now carries this title as of now: an older record still in flight
                // (a delayed webhook) must not put the previous title back. (QA #13, Fastpix_Sync_Apply::dashboard_title)
                $update['platform_updated_at'] = current_time('mysql', true);
            }
            $wpdb->update(Fastpix_Schema::table('videos'), $update, array('id' => (int) $row['id']));
            Fastpix_Cache::flush_group('videos');
            Fastpix_Jobs::enqueue('fastpix_search_reindex', array('video_id' => (int) $row['id']), Fastpix_Jobs::GROUP_MAINTENANCE);
        }

        return self::get_video($request);
    }

    /** The WordPress-owned field changes plus an acted-on suggestion. [RULE-022] */
    private static function collect_update($request, $row) {
        $update = array();
        foreach (array('title', 'description') as $field) {   // WordPress-owned [RULE-022]
            if ($request->get_param($field) !== null) {
                $update[$field] = (string) $request->get_param($field);
            }
        }

        // A suggestion is acted on once: accept copies it in, dismiss drops it,
        // both clear the stored suggestion. [RULE-022]
        $suggestion = $request->get_param('suggestion');
        if ($suggestion !== null && array_key_exists('suggested_title', $row)) {
            if ($suggestion === 'accept_title' && $row['suggested_title'] !== null) {
                $update['title'] = (string) $row['suggested_title'];
            } elseif ($suggestion === 'accept_description' && $row['suggested_description'] !== null) {
                $update['description'] = (string) $row['suggested_description'];
            }
            $update['suggested_title']       = null;
            $update['suggested_description'] = null;
            $update['suggested_at']          = null;
        }

        return $update;
    }

    /**
     * WordPress owns the title, and the platform is told so the dashboard
     * shows the same name (PATCH /on-demand/{id} {title}). Null = nothing to
     * push, or pushed/queued fine; a WP_Error is a real refusal (or a full
     * outbox) and the row keeps the old name.
     */
    private static function push_title($row, $title) {
        if ($title === null || $title === (string) $row['title'] || empty($row['media_id'])) {
            return null;
        }

        $client = new Fastpix_Api_Client();
        $pushed = $client->request('PATCH', self::ONDEMAND . rawurlencode($row['media_id']), array('body' => array('title' => $title)));
        if (Fastpix_Outbox::should_queue($pushed)) {
            // FastPix unavailable: save locally, queue the push. [WF-015]
            $pushed = Fastpix_Outbox::queue('PATCH', self::ONDEMAND . rawurlencode($row['media_id']),
                array('title' => $title), 'title of ' . $row['media_id']);
        }

        return is_wp_error($pushed) ? $pushed : null;
    }

    /** Downloadable file: platform call (API-F06), then mirror locally into $update. */
    private static function apply_downloadable($row, $downloadable, &$update) {
        if ($downloadable === null) {
            return null;
        }
        if ($row['access_policy'] === 'drm' && $downloadable !== 'off') {
            return new \WP_Error('fastpix_drm_no_download', __('Downloadable files are unavailable with DRM.', 'fastpix'), array('status' => 400));   // REQ-017
        }

        $client = new Fastpix_Api_Client();
        $result = $client->request('PATCH', self::ONDEMAND . rawurlencode($row['media_id']) . '/update-mp4Support', array(
            'body' => array('mp4Support' => self::MP4_SUPPORT[$downloadable]),
        ));
        if (Fastpix_Outbox::should_queue($result)) {
            $result = Fastpix_Outbox::queue('PATCH', self::ONDEMAND . rawurlencode($row['media_id']) . '/update-mp4Support',
                array('mp4Support' => self::MP4_SUPPORT[$downloadable]), 'downloadable file of ' . $row['media_id']);
        }
        if (!is_wp_error($result)) {
            $update['mp4_support'] = (string) $downloadable;
            $result = null;
        }

        return $result;
    }

    /** Our 2-letter subtitle codes → the BCP 47 codes the generate endpoint accepts. */
    private static function bcp47($code) {
        $map = array('en' => 'en-US', 'es' => 'es-ES', 'it' => 'it-IT', 'pt' => 'pt-PT', 'de' => 'de-DE', 'fr' => 'fr-FR', 'pl' => 'pl-PL',
            'ru' => 'ru-RU', 'nl' => 'nl-NL', 'ca' => 'ca-ES', 'tr' => 'tr-TR', 'sv' => 'sv-SE', 'uk' => 'uk-UA', 'no' => 'no-NO', 'fi' => 'fi-FI',
            'sk' => 'sk-SK', 'el' => 'el-GR', 'cs' => 'cs-CZ', 'hr' => 'hr-HR', 'da' => 'da-DK', 'ro' => 'ro-RO', 'bg' => 'bg-BG');
        $code = (string) $code;
        if (isset($map[$code])) {
            return $map[$code];
        }
        // Any other well-formed tag passes as-is ("af", "az-Cyrl" — verified live 2026-09-25); never re-label it English.
        return preg_match('/^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})*$/', $code) ? $code : 'en-US';
    }

    /**
     * FastPix validates languageName as ASCII letters only and unique per media (verified live
     * 2026-09-25: "Azerbaijani (Cyrillic)", "English UK", "Bokmål" are 422s) — "AzerbaijaniCyrillic".
     */
    private static function track_language_name($name, $code) { // NOSONAR php:S100 — WordPress snake_case naming
        $clean = preg_replace('/[^A-Za-z]/', '', remove_accents((string) $name));
        return $clean !== '' ? $clean : preg_replace('/[^A-Za-z]/', '', (string) $code);
    }

    /** [REQ-054] The embed constraint line for an access policy. */
    private static function access_constraints($policy) {
        if ($policy === 'public') {
            return __('Public — the reference is visible in page source and embeddable anywhere.', 'fastpix');
        }
        if ($policy === 'drm') {
            return __('DRM — authorised at render and expires; needs an HTTPS page and a supported browser; downloads unavailable.', 'fastpix');
        }
        return __('Private — authorised at render and expires; an embed code copied elsewhere will not work.', 'fastpix');
    }

    /** Local downloadable choice → FastPix mp4Support (docs: Update the mp4Support of a media). */
    const MP4_SUPPORT = array('off' => 'none', 'video' => 'capped_4k', 'audio' => 'audioOnly', 'both' => 'audioOnly,capped_4k');

    /** Track operations per FR-041, riding on PATCH (no dedicated /tracks route). */
    private static function track_operation($row, $op) {
        $client = new Fastpix_Api_Client();
        $media  = rawurlencode($row['media_id']);
        $action = isset($op['action']) ? (string) $op['action'] : '';

        switch ($action) {
            case 'add':        // open language list [REQ-044]
                // Verified live 2026-09-20 (QA F12): the body rides under `tracks` and the
                // code is BCP 47 — flat fields, or "de", are a 422 "payload validation failed".
                $result = $client->request('POST', "/on-demand/{$media}/tracks", array('body' => array('tracks' => array(
                    'url'          => (string) Fastpix_Sync::field($op, array('url')),
                    'type'         => 'subtitle',
                    'languageCode' => self::bcp47((string) Fastpix_Sync::field($op, array('language_code', 'languageCode'))),
                    'languageName' => self::track_language_name(Fastpix_Sync::field($op, array('language_name', 'languageName')), Fastpix_Sync::field($op, array('language_code', 'languageCode'))),
                ))));
                break;
            case 'generate':
                $result = self::generate_subtitle_track($client, $row, $media, $op);
                break;
            case 'replace':
                // Verified live 2026-09-20: PATCH /tracks/{id} changes language/title only (a url is a
                // 422), and a second track with the same languageName is refused (400 "duplicate
                // languageName"; names are validated, so the old one cannot be parked under a temporary
                // name) — a new file for an existing language can only be remove + add. [QA F12]
                if (empty($op['track_id'])) {
                    $result = new \WP_Error('fastpix_bad_track_op', __('A replace needs the track to replace.', 'fastpix'), array('status' => 400));
                    break;
                }
                $result = self::track_operation($row, array('action' => 'remove', 'track_id' => (string) $op['track_id']));
                if (!is_wp_error($result)) {
                    $add    = array_merge($op, array('action' => 'add'));
                    $result = self::track_operation($row, $add);
                    if (is_wp_error($result)) {
                        $result = self::track_operation($row, $add);   // the old track is already gone: one more try before giving up
                    }
                    if (is_wp_error($result)) {
                        // No rollback exists on the platform — say exactly where things stand instead of a bare error.
                        $result = new \WP_Error('fastpix_track_replace_half', sprintf(
                            /* translators: %s: the platform's reason */
                            __('The old subtitle track was removed, but FastPix refused the new file (%s). This language has no subtitles now — upload the file again.', 'fastpix'),
                            $result->get_error_message()
                        ), array('status' => 502));
                    }
                }
                break;
            case 'remove':
                $result = $client->request('DELETE', "/on-demand/{$media}/tracks/" . rawurlencode((string) $op['track_id']));
                if (!is_wp_error($result)) {
                    global $wpdb;
                    $wpdb->update(Fastpix_Schema::table('tracks'),
                        array('deleted_at' => current_time('mysql', true)),
                        array('track_id' => (string) $op['track_id']));
                }
                break;

            default:
                $result = new \WP_Error('fastpix_bad_track_op', __('Unknown subtitle operation.', 'fastpix'), array('status' => 400));
        }

        return $result;
    }

    /**
     * FastPix generates subtitles FROM THE AUDIO TRACK:
     * POST /tracks/{audioTrackId}/generate-subtitles {languageName, languageCode (BCP 47)}
     * → a new subtitle track.
     * Regenerate = drop the existing subtitle track for that language first.
     */
    private static function generate_subtitle_track($client, $row, $media, $op) {
        $lang_code = (string) Fastpix_Sync::field($op, array('language_code', 'languageCode'));
        $lang_name = (string) Fastpix_Sync::field($op, array('language_name', 'languageName'));
        $old_id    = (string) Fastpix_Sync::field($op, array('track_id', 'trackId'));

        $audio_id = self::audio_track_id($client, $media);
        $label    = $lang_name ?: strtoupper($lang_code);

        global $wpdb;
        // Generate FIRST. Deleting the old track up front (the earlier order) lost it for good when
        // the platform then refused the new one — "Duplicate language" while its own copy still
        // existed (QA F4). Now the old track goes only when the platform insists, and one retry follows.
        $generate = function () use ($client, $media, $audio_id, $lang_name, $lang_code) {
            return $client->request('POST', "/on-demand/{$media}/tracks/" . rawurlencode($audio_id) . '/generate-subtitles', array('body' => array(
                'languageName' => $lang_name,
                'languageCode' => self::bcp47($lang_code),
            )));
        };
        // Remove the old track on the platform and tombstone it locally; "already gone" (404) counts as removed.
        $retire = function () use ($client, $media, $old_id, $wpdb) {
            $removed = $client->request('DELETE', "/on-demand/{$media}/tracks/" . rawurlencode($old_id));
            if (!is_wp_error($removed) || (int) ((array) $removed->get_error_data() + array('status' => 0))['status'] === 404) {
                $wpdb->update(Fastpix_Schema::table('tracks'), array('deleted_at' => current_time('mysql', true)), array('track_id' => $old_id));
                return true;
            }
            return $removed;
        };
        $generated = is_wp_error($audio_id) ? $audio_id : $generate();
        if (is_wp_error($generated) && stripos($generated->get_error_message(), 'duplicate language') !== false) {
            $retired = $old_id === ''
                /* translators: %s: subtitle language name */
                ? new \WP_Error('fastpix_track_exists', sprintf(__('%s subtitles already exist on FastPix — remove that track first, then generate again.', 'fastpix'), $label), array('status' => 409))
                : $retire();
            if (is_wp_error($retired)) {
                return $retired;
            }
            $generated = $generate();
        } elseif (!is_wp_error($generated) && $old_id !== '') {
            // The platform took a second track for the language: retire the old one now that the new exists.
            $retire();
        }
        $gbody    = is_wp_error($generated) ? array() : (array) ($generated['body']['data'] ?? $generated['body']);
        $track_id = (string) Fastpix_Sync::field($gbody, array('id', 'trackId', 'track_id'));
        if ($track_id !== '') {
            // Visible as "Generating" now; the track webhooks move it to ready/failed. [ERR-022]
            $now = current_time('mysql', true);
            $wpdb->query($wpdb->prepare(
                'INSERT INTO ' . Fastpix_Schema::table('tracks') . '
                     (video_id, track_id, type, language_code, source, state, created_at, updated_at)
                 VALUES (%d, %s, %s, %s, %s, %s, %s, %s)
                 ON DUPLICATE KEY UPDATE state = VALUES(state), deleted_at = NULL, updated_at = VALUES(updated_at)',
                (int) $row['id'], $track_id, 'subtitle', $lang_code, 'generated', 'generating', $now, $now
            ));
        }

        return $generated;
    }

    /** The media's first audio track id — subtitles are generated from it. */
    private static function audio_track_id($client, $media) {
        $detail = $client->request('GET', "/on-demand/{$media}");
        if (is_wp_error($detail)) {
            return $detail;
        }

        $mdata = isset($detail['body']['data']) ? (array) $detail['body']['data'] : (array) $detail['body'];
        foreach ((array) Fastpix_Sync::field($mdata, array('tracks')) as $t) {
            if (isset($t['type']) && $t['type'] === 'audio' && !empty($t['id'])) {
                return (string) $t['id'];
            }
        }

        return new \WP_Error('fastpix_no_audio', __('This video has no audio track to generate subtitles from.', 'fastpix'), array('status' => 409));
    }

    /* ------------------------------------------------------------ deletion */

    /**
     * [WF-011, FR-034] Local tombstone always; the platform copy goes only when
     * the caller confirmed it. The usage list guards against deleting something
     * live.
     */
    public static function delete_video($request) {
        global $wpdb;

        $row = self::own_row($request, Fastpix_Capabilities::DELETE_VIDEO);
        if (is_wp_error($row)) {
            return $row;
        }

        $usage = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . Fastpix_Schema::table('usage') . ' WHERE video_id = %d',
            (int) $row['id']
        ));

        if ($request->get_param('purge')) {
            if (!Fastpix_Sync::is_unavailable($row)) {
                $verdict = new \WP_Error('fastpix_not_unavailable', __('Only an Unavailable video can be removed from the library. Delete it first.', 'fastpix'), array('status' => 409));
            } else {
                Fastpix_Sync::purge_video((int) $row['id']);
                do_action('fastpix_audit_event', 'video_purged', array('media_id' => $row['media_id'], 'usage' => $usage));

                $verdict = array('deleted' => true, 'purged' => true, 'affected_posts' => $usage);
            }
        } else {
            $result = null;
            if ($request->get_param('delete_on_platform')) {
                $client = new Fastpix_Api_Client();
                $result = $client->request('DELETE', self::ONDEMAND . rawurlencode($row['media_id']));
                if (Fastpix_Outbox::should_queue($result)) {
                    // Tombstone now, delete on the platform when it is back. [WF-015]
                    $result = Fastpix_Outbox::queue('DELETE', self::ONDEMAND . rawurlencode($row['media_id']),
                        array(), 'platform delete of ' . $row['media_id']);
                }
            }
            if (is_wp_error($result) && $result->get_error_code() !== 'fastpix_not_found') {
                $verdict = $result;   // already gone on the platform is fine
            } else {
                Fastpix_Sync::tombstone_media($row['media_id']);
                do_action('fastpix_audit_event', 'video_deleted', array(
                    'media_id' => $row['media_id'],
                    'platform' => (bool) $request->get_param('delete_on_platform'),
                    'usage'    => $usage,
                ));
                $verdict = array('deleted' => true, 'tombstoned' => true, 'affected_posts' => $usage);
            }
        }

        return rest_ensure_response($verdict);   // passes a WP_Error through untouched
    }

    /* ---------------------------------------------------------------- bulk */

    /** [FR-033] Queued, never inline; the request returns immediately. */
    public static function bulk($request) {
        $error = null;
        if ($request->get_param('action') === null || !is_array($request->get_param('ids'))) {
            $error = new \WP_Error('rest_missing_callback_param', __('Missing parameter(s): action, ids', 'fastpix'), array('status' => 400));
        } elseif (!in_array($request->get_param('action'), array('rerun_ai', 'regenerate_posters', 'delete'), true)) {
            $error = new \WP_Error('rest_invalid_param', __('Unknown bulk action.', 'fastpix'), array('status' => 400));
        }
        if ($error) {
            return $error;
        }
        $ids = array_map('intval', (array) $request->get_param('ids'));
        $action = (string) $request->get_param('action');

        // Delete needs a DELETE capability, enforced HERE at request time: the
        // background job runs with no current user, so the gate cannot live in
        // it. The bulk route's own gate is EDIT_VIDEO_OWN, so without this a user
        // with edit-own but not delete-own could delete through bulk, bypassing
        // the DELETE_VIDEO_OWN gate the single-row route enforces. [SEC-011]
        if ($action === 'delete') {
            if (!current_user_can(Fastpix_Capabilities::DELETE_VIDEO)
                && !current_user_can(Fastpix_Capabilities::DELETE_VIDEO_OWN)) {
                return new \WP_Error('fastpix_forbidden', __('Your role cannot delete videos.', 'fastpix'), array('status' => 403));
            }
            // Scope by the DELETE capability, not EDIT: a delete-own user may
            // only reach their own videos, even if they can edit every video.
            $scope = self::own_scope(Fastpix_Capabilities::DELETE_VIDEO);
        } else {
            $scope = self::own_scope(Fastpix_Capabilities::EDIT_VIDEO);
        }
        $queued = 0;

        foreach ($ids as $id) {
            Fastpix_Jobs::enqueue('fastpix_bulk_item', array(
                'video_id'           => $id,
                'bulk_action'        => $action,
                'author_scope'       => $scope,   // the job re-applies the scope [REQ-091]
                'delete_on_platform' => (bool) $request->get_param('delete_on_platform'),
            ), $action === 'rerun_ai' ? Fastpix_Jobs::GROUP_AI : Fastpix_Jobs::GROUP_SYNC);
            $queued++;
        }

        return rest_ensure_response(array('queued' => $queued, 'background' => true));
    }

    /** One item of a bulk action, in the background. Idempotent per state. */
    public static function bulk_item_job($args = array()) {
        global $wpdb;

        $video_id = isset($args['video_id']) ? (int) $args['video_id'] : 0;
        $action   = isset($args['bulk_action']) ? (string) $args['bulk_action'] : '';
        $scope    = isset($args['author_scope']) ? $args['author_scope'] : null;

        $sql = self::SELECT_ALL . Fastpix_Schema::table('videos') . ' WHERE id = %d AND deleted_at IS NULL';
        $params = array($video_id);
        if ($scope !== null) {
            $sql     .= ' AND author_id = %d';
            $params[] = (int) $scope;
        }
        $row = $wpdb->get_row($wpdb->prepare($sql, $params), ARRAY_A);
        if (!$row || self::is_other_workspace($row)) {   // never act on a previous workspace's row
            return;
        }

        switch ($action) {
            case 'delete':
                if (!empty($args['delete_on_platform'])) {
                    $client = new Fastpix_Api_Client();
                    $client->request('DELETE', self::ONDEMAND . rawurlencode($row['media_id']), array('context' => 'background'));
                }
                Fastpix_Sync::tombstone_media($row['media_id']);
                break;

            case 'rerun_ai':
                // The full set per the batch settings, directly — fastpix_media_ready only
                // re-requests subtitles (and re-queues the domain lock). Nothing to run on
                // until the media is Ready. [QA L5/X14]
                if ($row['status'] === 'Ready') {
                    Fastpix_Ai::request($row['media_id']);
                }
                break;

            case 'regenerate_posters':
                // ponytail: no poster-regeneration endpoint exists in 06 §A; the
                // honest minimum is to stamp poster_updated_at and flush, so
                // delivery URLs re-fetch. Revisit when the platform names one.
                $wpdb->update(Fastpix_Schema::table('videos'),
                    array('poster_updated_at' => current_time('mysql', true), 'updated_at' => current_time('mysql', true)),
                    array('id' => $video_id));
                Fastpix_Cache::flush_group('videos');
                break;

            default:   // an unknown bulk action is a no-op
                break;
        }
    }

    /**
     * Accept a .vtt/.srt upload, store it as an attachment, hand its URL to
     * the platform tracks API. [FR-031]
     */
    public static function track_file($request) {
        $row = self::own_row($request, Fastpix_Capabilities::EDIT_VIDEO);
        if (is_wp_error($row)) {
            return $row;
        }

        $upload = self::sideload_subtitle($request->get_file_params());
        // FastPix fetches the file from this site's address. A host the internet cannot
        // reach (localhost, a private LAN, .local) can only fail later and silently —
        // refuse now with the reason, as the migration path does. [QA F12, ASSUME-032 (c)]
        if (!is_wp_error($upload) && !self::subtitle_url_reachable($upload['url'])) {
            wp_delete_file($upload['file']);
            $upload = new \WP_Error('fastpix_subtitle_unreachable', __('FastPix downloads the subtitle file from this site, and this site is not reachable from the internet. Publish it on a public address, or use Generate instead.', 'fastpix'), array('status' => 409));
        }
        if (is_wp_error($upload)) {
            return $upload;
        }

        $track_id = (string) $request->get_param('track_id');
        $result   = self::track_operation($row, array(
            'action'        => $track_id !== '' ? 'replace' : 'add',
            'track_id'      => $track_id,
            'language_code' => (string) $request->get_param('language_code'),
            'language_name' => (string) $request->get_param('language_name'),
            'url'           => $upload['url'],
        ));
        if (!is_wp_error($result)) {
            // Record the track now, as the generate path does, so the panel shows it at once;
            // the track webhooks (or the sweep) move it to ready/failed. [QA F12]
            global $wpdb;
            $body = (array) (isset($result['body']) ? $result['body'] : array());
            $body = isset($result['body']['data']) ? (array) $result['body']['data'] : $body;
            $new_id = (string) Fastpix_Sync::field($body, array('id', 'trackId', 'track_id'));   // replace = remove + add, so the id is always the new one
            if ($new_id !== '') {
                $now = current_time('mysql', true);
                $wpdb->query($wpdb->prepare(
                    'INSERT INTO ' . Fastpix_Schema::table('tracks') . '
                         (video_id, track_id, type, language_code, source, state, created_at, updated_at)
                     VALUES (%d, %s, %s, %s, %s, %s, %s, %s)
                     ON DUPLICATE KEY UPDATE state = VALUES(state), deleted_at = NULL, updated_at = VALUES(updated_at)',
                    (int) $row['id'], $new_id, 'subtitle', (string) $request->get_param('language_code'), 'uploaded', 'processing', $now, $now
                ));
                Fastpix_Cache::flush_group('videos');
            }
            $result = rest_ensure_response(array('queued' => true, 'file_url' => $upload['url'], 'track_id' => $new_id));
        } else {
            wp_delete_file($upload['file']);   // FastPix never took it: no stray .vtt left in uploads [QA F12]
            Fastpix_Cache::flush_group('videos');   // a half-done replace tombstoned the old track
        }

        return $result;
    }

    /**
     * Same host test the migration scan uses, minus its "must be a video" content-type
     * rule — a .vtt answers text/vtt, which is exactly right here. [QA F12 review]
     */
    private static function subtitle_url_reachable($url) {
        $pre = apply_filters('fastpix_migration_reachable', null, $url);
        if ($pre !== null) {
            return (bool) $pre;
        }
        $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        if (!$host || in_array($host, array('localhost', '127.0.0.1', '::1'), true) || substr($host, -6) === '.local') {
            return false;
        }
        $verdict = Fastpix_Uploads::validate_public_video_url($url);

        return !is_wp_error($verdict) || $verdict->get_error_code() === 'fastpix_url_not_video';
    }

    /**
     * Validate and store the uploaded subtitle file as a public .vtt in the uploads dir.
     * An .srt is converted here — WordPress's own mime sniff rejects .srt outright
     * (finfo says text/plain and only text/vtt is on its forgiveness list), and
     * FastPix wants WebVTT anyway. [QA F12, verified 2026-09-20]
     */
    const SUBTITLE_MAX_BYTES = 2097152;   // matches the 2 MB the panel states

    private static function sideload_subtitle($files) {
        if (empty($files['file']['name']) || empty($files['file']['tmp_name'])) {
            return new \WP_Error('fastpix_no_file', __('No subtitle file was sent.', 'fastpix'), array('status' => 400));
        }

        $ext   = strtolower(pathinfo($files['file']['name'], PATHINFO_EXTENSION));
        $error = null;
        if (!in_array($ext, array('vtt', 'srt'), true)) {
            $error = array('fastpix_bad_subtitle', __('Only .vtt and .srt files are accepted.', 'fastpix'));
        } elseif (!empty($files['file']['size']) && (int) $files['file']['size'] > self::SUBTITLE_MAX_BYTES) {
            $error = array('fastpix_subtitle_too_large', __('That file is over 2 MB.', 'fastpix'));
        }
        $text = $error ? '' : (string) file_get_contents($files['file']['tmp_name']);
        if (substr($text, 0, 3) === "\xEF\xBB\xBF") {
            $text = substr($text, 3);   // BOM
        }
        $text = str_replace(array("\r\n", "\r"), "\n", $text);
        if (!$error && strpos($text, '-->') === false) {
            $error = array('fastpix_bad_subtitle', __('That file does not contain subtitle cues.', 'fastpix'));
        } elseif ($ext === 'srt') {
            $text = "WEBVTT\n\n" . preg_replace('/(\d{1,3}:\d{2}:\d{2}),(\d{3})/', '$1.$2', $text);
        } elseif (!$error && strpos($text, 'WEBVTT') !== 0) {
            $error = array('fastpix_bad_subtitle', __('That .vtt file does not start with WEBVTT.', 'fastpix'));
        }
        if ($error) {
            return new \WP_Error($error[0], $error[1], array('status' => 400));
        }

        $name   = sanitize_file_name(pathinfo($files['file']['name'], PATHINFO_FILENAME)) . '.vtt';
        $upload = wp_upload_bits($name, null, $text);

        return !empty($upload['error']) ? new \WP_Error('fastpix_subtitle_failed', $upload['error'], array('status' => 400)) : $upload;
    }

    /* --------------------------------------------------------- ai + embed */

    /** [API-P03] Request a re-run; the platform owns the output (REQ-045). */
    public static function rerun_ai($request) {
        $row = self::own_row($request, Fastpix_Capabilities::EDIT_VIDEO);
        if (is_wp_error($row)) {
            return $row;
        }

        // One kind → only that item is re-run (RULE-011); none → the full set per the batch settings.
        $kind = (string) $request->get_param('kind');
        if ($kind !== '' && !isset(Fastpix_Ai::KINDS[$kind]) && $kind !== 'subtitles') {
            return new \WP_Error('fastpix_bad_kind', __('Unknown AI output.', 'fastpix'), array('status' => 400));
        }
        Fastpix_Ai::request($row['media_id'], $kind !== '' ? array($kind) : null);

        return rest_ensure_response(array('queued' => true, 'kind' => $kind !== '' ? $kind : null));
    }

    /** [API-P06] Embed code + shortcode for the row's chips. */
    public static function embed($request) {
        $row = self::own_row($request, Fastpix_Capabilities::EDIT_VIDEO);
        if (is_wp_error($row)) {
            return $row;
        }

        global $wpdb;
        $playback = (string) $wpdb->get_var($wpdb->prepare(
            'SELECT playback_id FROM ' . Fastpix_Schema::table('playback_ids') . ' WHERE video_id = %d AND deleted_at IS NULL LIMIT 1',
            (int) $row['id']
        ));

        return rest_ensure_response(array(
            // OQ-011: the canonical shortcode identifier is undecided; media_id
            // is used and the renderer accepts it.
            'shortcode'   => '[fastpix id="' . esc_attr($row['media_id']) . '"]',
            'playback_id' => $playback,
            'constraints' => self::access_constraints($row['access_policy']),   // REQ-054
        ));
    }

    /** [API-P06, REQ-037] The posts using this video. */
    public static function usage($request) {
        $row = self::own_row($request, Fastpix_Capabilities::EDIT_VIDEO);
        if (is_wp_error($row)) {
            return $row;
        }

        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT post_id, context, occurrences, last_seen_at FROM ' . Fastpix_Schema::table('usage') . ' WHERE video_id = %d',
            (int) $row['id']
        ), ARRAY_A);

        foreach ($rows as $i => $usage) {
            $rows[$i]['title'] = get_the_title((int) $usage['post_id']);
            $rows[$i]['link']  = get_permalink((int) $usage['post_id']);
        }

        return rest_ensure_response(array('posts' => $rows));
    }

    /* ------------------------------------------------------------ helpers */

    /** Null = full access; an author id = scope every query to it. [REQ-091] */
    private static function own_scope($any_cap) {
        if (current_user_can($any_cap)) {
            return null;
        }

        return get_current_user_id();
    }

    private static function own_row($request, $any_cap) {
        global $wpdb;

        $id     = (int) $request->get_param('id');
        $sql    = self::SELECT_ALL . Fastpix_Schema::table('videos') . ' WHERE id = %d';
        $params = array($id);

        $scope = self::own_scope($any_cap);
        if ($scope !== null) {
            $sql     .= ' AND author_id = %d';
            $params[] = $scope;
        }

        $row = $wpdb->get_row($wpdb->prepare($sql, $params), ARRAY_A);

        if (!$row) {
            return new \WP_Error('fastpix_video_missing', __('No such video.', 'fastpix'), array('status' => 404));
        }
        // A previous workspace's row is readable (posts keep their embeds) but the
        // connected pair cannot change it on the platform — refuse every write. [ASSUME-092]
        if ($request->get_method() !== 'GET' && self::is_other_workspace($row)) {
            return new \WP_Error('fastpix_other_workspace', __('This video belongs to a previously connected workspace — the current credentials cannot change it.', 'fastpix'), array('status' => 409));
        }

        return $row;
    }

    /** True for a row stamped with a workspace other than the connected one (or the sentinel). */
    public static function is_other_workspace($row) {
        return $row['workspace_id'] !== '' && $row['workspace_id'] !== self::connected_workspace();
    }

    private static function shape($row, $full = false) {
        global $wpdb;

        $playback = (string) $wpdb->get_var($wpdb->prepare(
            'SELECT playback_id FROM ' . Fastpix_Schema::table('playback_ids') . ' WHERE video_id = %d AND deleted_at IS NULL LIMIT 1',
            (int) $row['id']
        ));

        $shaped = array(
            'id'            => (int) $row['id'],
            'media_id'      => $row['media_id'],
            'playback_id'   => $playback,
            'title'         => $row['title'],
            'status'        => ($row['deleted_at'] !== null || $row['error_code'] === 'orphaned') ? 'Unavailable' : $row['status'],   // FR-034; ERR-040: a 404'd row reads Unavailable, never "Processing" forever
            'access_policy' => $row['access_policy'],
            'source'        => $row['source'],
            'duration'      => $row['duration_seconds'] !== null ? (float) $row['duration_seconds'] : null,
            'ai_state'      => $row['ai_state'],
            'error_code'    => $row['error_code'],
            'author_id'     => (int) $row['author_id'],
            'poster'        => ($row['access_policy'] === 'public' && $playback !== '')
                ? Fastpix_Attachments::image_base() . '/' . rawurlencode($playback) . '/thumbnail.png?width=160'
                : null,   // a private poster is signed too [REQ-101]
        );
        // Belongs to a previously connected workspace: the current credentials cannot manage it.
        $shaped['other_workspace'] = self::is_other_workspace($row);

        if ($full) {
            $shaped['description']    = $row['description'];
            $shaped['aspect_ratio']   = $row['aspect_ratio'];
            $shaped['max_resolution'] = $row['max_resolution'];
            $shaped['mp4_support']    = $row['mp4_support'];
            $shaped['quality_tier']   = $row['quality_tier'];
            $shaped['deleted_at']     = $row['deleted_at'];
            $shaped['can_edit']       = !$shaped['other_workspace'] && (current_user_can(Fastpix_Capabilities::EDIT_VIDEO)
                || ((int) $row['author_id'] === get_current_user_id() && current_user_can(Fastpix_Capabilities::EDIT_VIDEO_OWN)));
        }

        return $shaped;
    }
}
