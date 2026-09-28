<?php
/**
 * Upload engine (server half) + URL ingestion — ARCH-04, WF-002, WF-003,
 * FR-010…FR-014, API-P07, API-P01 (POST /videos for URL ingest).
 *
 * The browser sends bytes straight to the signed URL — no plugin endpoint ever
 * receives file content (REQ-011). This class owns sessions (fastpix_uploads),
 * the per-URL SSRF gate, the upload webhook binding, and the 7-day orphan
 * sweep.
 *
 * Split across two classes to stay within the 20-method budget:
 * Fastpix_Uploads (routes + session lifecycle + settings) and
 * Fastpix_Uploads_Ingest (URL ingest + webhook binding + domain lock),
 * Fastpix_Url_Guard (SSRF gate) and Fastpix_Uploads_Settings (settings + watermark gate).
 */

namespace Fastpix;

if (!defined('WPINC')) {
    die;
}

require_once __DIR__ . '/class-fastpix-uploads-ingest.php';

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery
// All SQL here runs against the plugin's own tables: identifiers come from the
// Fastpix_Schema::table() registry (a fixed whitelist, never request input) and
// every value travels through $wpdb->prepare(). Core APIs cannot query these tables.

class Fastpix_Uploads {

    /** [REQ-012] */
    const MAX_FILE_BYTES     = 21474836480;   // 20 GB
    const MAX_FILES_PER_SUBMISSION = 50;
    const MAX_SESSIONS_PER_SITE    = 20;
    const INTERRUPT_SECONDS        = 180;   // an 'uploading' row silent this long was cut off (closed tab, crash); well past a hidden tab's ~1/min timers (QA U20)
    /**
     * A finished transfer whose media is still unknown here is "Processing" for this long only. FastPix
     * turns a file into a media in minutes; a row that never found its video (bound under another id by an
     * older build, or its video row removed) used to sit on Add media as "Processing" for a whole day,
     * next to a video that was Ready in the library. (QA 2026-09-21)
     */
    const UNBOUND_GRACE_SECONDS = HOUR_IN_SECONDS;
    /** While the queue is watching a media that has not settled, at most one platform read per media this often. */
    const WATCH_REFRESH_SECONDS = 10;
    const CHUNK_BYTES        = 16777216;      // 16 MB (browser drops to 8 then 5 after failures)

    /** [WF-003, SEC-010] */
    const MAX_REDIRECTS = 3;

    private const MYSQL_DATETIME = 'Y-m-d H:i:s';

    /** "Play only on this site" (AMBIG-009): the platform only takes domain
     *  restrictions as a PATCH on a playback id whose status is 'available',
     *  so the lock is applied on media-ready and retried through the
     *  'preparing' window. */
    const HOOK_DOMAIN_LOCK         = 'fastpix_domain_lock';
    const MAX_DOMAIN_LOCK_ATTEMPTS = 5;
    const DOMAIN_LOCK_SPACING      = 60;

    public static function boot() {
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
        add_action('fastpix_upload_event', array(Fastpix_Uploads_Ingest::class, 'on_upload_event'), 10, 2);
        add_action('fastpix_upload_orphans', array(__CLASS__, 'orphan_sweep'));
        add_action('fastpix_media_ready', array(__CLASS__, 'queue_domain_lock'));
        add_action(self::HOOK_DOMAIN_LOCK, array(Fastpix_Uploads_Ingest::class, 'domain_lock_job'));
    }

    /* ------------------------------------------------------------- routes */

    public static function register_routes() {
        // API-P07 — sessions.
        Fastpix_Rest::register('/uploads', array(
            'methods'    => 'POST',
            'callback'   => array(__CLASS__, 'create_session'),
            'capability' => Fastpix_Capabilities::UPLOAD_VIDEO,
            'args'       => array(
                'filename' => Fastpix_Rest::arg('string', array('required' => true)),
                'filesize' => Fastpix_Rest::arg('integer', array('required' => true, 'minimum' => 1)),
                'filetype' => Fastpix_Rest::arg('string'),
                'settings' => array('type' => 'object', 'required' => false),
            ),
        ));

        // The sessions this user still owns on the server: paused / interrupted
        // (Resume asks for the same file) and transferred-but-processing. The
        // page rebuilds its queue from this on every load. [FR-010 reopen]
        Fastpix_Rest::register('/uploads', array(
            'methods'    => 'GET',
            'callback'   => array(__CLASS__, 'list_sessions'),
            'capability' => Fastpix_Capabilities::UPLOAD_VIDEO,
        ));

        Fastpix_Rest::register('/uploads/(?P<id>\d+)', array(
            'methods'    => 'PATCH',
            'callback'   => array(__CLASS__, 'update_session'),
            'capability' => Fastpix_Capabilities::UPLOAD_VIDEO,
            'args'       => array(
                'bytes_sent' => Fastpix_Rest::arg('integer', array('minimum' => 0)),
                'state'      => Fastpix_Rest::arg('string', array('enum' => array('uploading', 'paused', 'completed'))),
                'resume'     => Fastpix_Rest::arg('boolean'),
                'filename'   => Fastpix_Rest::arg('string'),
                'filesize'   => Fastpix_Rest::arg('integer'),
                // The bucket's resumable session address the SDK opened from the signed URL —
                // https, carrying upload_id=. Kept so a later resume reopens the SAME session.
                'session_uri' => Fastpix_Rest::arg('string', array(
                    'validate_callback' => function ($value) { return (bool) preg_match('#^https://[^\s]+[?&]upload_id=[^\s&]+#', (string) $value); },
                )),
            ),
        ));

        // The Add media queue reads LOCAL state (a DB read — never a platform
        // call) until every row is terminal; readiness itself arrives by webhook,
        // or by the poll fallback when webhooks are not configured.
        Fastpix_Rest::register('/uploads/status', array(
            'methods'    => 'GET',
            'callback'   => array(__CLASS__, 'batch_status'),
            'capability' => Fastpix_Capabilities::VIEW_VIDEOS,
            'args'       => array(
                'ids'       => Fastpix_Rest::arg('string'),   // comma-separated upload row ids
                'media_ids' => Fastpix_Rest::arg('string'),   // comma-separated media ids (URL ingests)
            ),
        ));

        Fastpix_Rest::register('/uploads/(?P<id>\d+)/cancel', array(
            'methods'    => 'POST',
            'callback'   => array(__CLASS__, 'cancel_session'),
            'capability' => Fastpix_Capabilities::UPLOAD_VIDEO,
        ));

        // The modal checks the watermark URL before anything is staged: FastPix fetches it
        // server-side, so a private or unreachable image would otherwise fail every create.
        Fastpix_Rest::register('/uploads/watermark-check', array(
            'methods'    => 'POST',
            'callback'   => array(Fastpix_Uploads_Settings::class, 'watermark_check'),
            'capability' => Fastpix_Capabilities::UPLOAD_VIDEO,
            'args'       => array('url' => Fastpix_Rest::arg('string', array('required' => true))),
        ));

        // API-P01 — POST /videos: URL ingestion, one verdict per URL.
        Fastpix_Rest::register('/videos', array(
            'methods'    => 'POST',
            'callback'   => array(Fastpix_Uploads_Ingest::class, 'ingest_urls'),
            'capability' => Fastpix_Capabilities::UPLOAD_VIDEO,
            'args'       => array(
                'urls'     => array('type' => 'array', 'required' => true, 'items' => array('type' => 'string')),
                'settings' => array('type' => 'object', 'required' => false),
            ),
        ));
    }

    /* ------------------------------------------------- session lifecycle */

    /**
     * [WF-002 step 2] Validate, refuse-don't-promise, then create the platform
     * session and the local row. The settings snapshot rides along (FR-013;
     * per-batch, not persisted).
     */
    public static function create_session($request) {
        global $wpdb;

        $filename = sanitize_file_name((string) $request->get_param('filename'));
        $filesize = (int) $request->get_param('filesize');
        $filetype = (string) $request->get_param('filetype');

        $refusal = self::create_refusal($filesize, $filetype);
        if ($refusal) {
            return $refusal;
        }

        $table    = Fastpix_Schema::table('uploads');
        $now      = current_time('mysql', true);
        $settings = Fastpix_Uploads_Settings::settings_snapshot($request->get_param('settings'));

        // upload_id is UNIQUE: a shared '' placeholder made the second of two parallel
        // creates fail its INSERT (insert_id 0) while its platform session was still made —
        // an untitled orphan on FastPix and a PATCH to /uploads/0. Unique placeholder. [QA F2]
        $inserted = $wpdb->insert($table, array(
            'upload_id'    => 'pending:' . wp_generate_uuid4(),   // platform id arrives with the session
            'filename'     => $filename,
            'filesize'     => $filesize,
            'chunk_size'   => self::CHUNK_BYTES,
            'state'        => 'created',
            'settings_json'=> wp_json_encode($settings),
            'user_id'      => get_current_user_id(),
            'created_at'   => $now,
            'updated_at'   => $now,
        ));
        $row_id  = (int) $wpdb->insert_id;
        $session = (!$inserted || $row_id === 0)
            ? new \WP_Error('fastpix_upload_row', __('The upload could not be recorded on this site — try again.', 'fastpix'), array('status' => 500))
            : self::platform_session($row_id, $settings);
        if (is_wp_error($session)) {
            $wpdb->delete($table, array('id' => $row_id));   // nothing queued on refusal [RULE-005]; a no-op when the INSERT itself failed (id 0)

            return $session;
        }

        $wpdb->update($table, array(
            'upload_id'      => $session['upload_id'],
            'signed_url'     => $session['url'],
            'url_expires_at' => $session['expires_at'],
            'state'          => 'uploading',
            'updated_at'     => $now,
        ), array('id' => $row_id));

        return rest_ensure_response(array(
            'id'         => $row_id,
            'upload_id'  => $session['upload_id'],
            'signed_url' => $session['url'],
            'chunk_size' => self::CHUNK_BYTES,
        ));
    }

    /**
     * [RULE-005] Refuse before creating anything. Validation names the limit
     * that was hit and costs nothing. [REQ-016]
     *
     * @return \WP_Error|null
     */
    private static function create_refusal($filesize, $filetype) {
        global $wpdb;

        $refusal = self::refuse_if_unavailable();   // RULE-005
        if (!$refusal && $filesize > self::MAX_FILE_BYTES) {
            $refusal = new \WP_Error('fastpix_too_large', __('This file is over the 20 GB per-file limit.', 'fastpix'), array('status' => 400, 'limit' => '20 GB'));
        }
        if (!$refusal && $filetype !== '' && !Fastpix_Uploads_Settings::accepted_type($filetype)) {
            /* translators: %s: file extension or MIME type */
            $refusal = new \WP_Error('fastpix_bad_format', sprintf(__('%s is not an accepted video format.', 'fastpix'), $filetype), array('status' => 400, 'limit' => 'format'));
        }
        if (!$refusal) {
            $table = Fastpix_Schema::table('uploads');
            $open  = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE state IN ('created', 'uploading', 'paused')");
            if ($open >= self::MAX_SESSIONS_PER_SITE) {
                // The cap is per site [REQ-012] but a user only sees their own sessions: say whose they are and how the rest clear. (QA U10)
                $mine = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE state IN ('created', 'uploading', 'paused') AND user_id = %d", get_current_user_id()));
                /* translators: 1: number of open sessions site-wide, 2: how many of them belong to this user */
                $refusal = new \WP_Error('fastpix_session_cap', sprintf(__('This site already has %1$d open upload sessions across all users (%2$d of them yours). Finish or discard yours from Add media; other users\' sessions clear when they finish, or after 7 days untouched.', 'fastpix'), $open, $mine), array('status' => 429, 'limit' => '20 sessions'));
            }
        }

        return $refusal;
    }

    /** GET /uploads/status — {uploads:{id:{state, media_id, video_id, status}}, media:{media_id:{video_id, status, ai_state}}} */
    /**
     * 'uploading' rows with no progress report for INTERRUPT_SECONDS were cut
     * off (the tab closed or crashed before its pagehide beacon landed): they
     * become paused, bytes held. One rule, used by the listing and the library
     * banner; the pagehide PATCH is the fast path.
     */
    public static function mark_interrupted() {
        global $wpdb;

        $wpdb->query($wpdb->prepare(
            'UPDATE ' . Fastpix_Schema::table('uploads') . " SET state = 'paused' WHERE state = 'uploading' AND updated_at < %s",
            gmdate(self::MYSQL_DATETIME, time() - self::INTERRUPT_SECONDS)
        ));
    }

    public static function list_sessions() {
        global $wpdb;

        self::mark_interrupted();
        Fastpix_Uploads_Ingest::bind_unbound(get_current_user_id());   // a Ready media drops its row below
        // A finished row with no video found yet is "Processing" only within UNBOUND_GRACE_SECONDS; a bound one until its video settles.
        // While a pair change is unresolved nothing resumes: the session may belong to the workspace just left. [ASSUME-092]
        $rows = (string) get_option(Fastpix_Connection::OPT_PENDING_LEAVE, '') !== '' ? array() : $wpdb->get_results($wpdb->prepare(
            'SELECT u.id, u.upload_id, u.filename, u.filesize, u.bytes_sent, u.state, u.signed_url, u.session_uri, u.settings_json, u.url_expires_at
             FROM ' . Fastpix_Schema::table('uploads') . ' u LEFT JOIN ' . Fastpix_Schema::table('videos') . " v ON v.id = u.video_id
             WHERE u.user_id = %d AND (
                 u.state = 'paused'
                 OR (u.state = 'completed' AND u.updated_at > %s AND (
                        (v.id IS NULL AND u.updated_at > %s)
                        OR (v.id IS NOT NULL AND v.deleted_at IS NULL AND LOWER(v.status) NOT IN ('ready', 'failed', 'errored', 'deleted'))
                    ))
             )
             ORDER BY u.created_at ASC LIMIT 50",
            get_current_user_id(), gmdate(self::MYSQL_DATETIME, time() - DAY_IN_SECONDS), gmdate(self::MYSQL_DATETIME, time() - self::UNBOUND_GRACE_SECONDS)
        ), ARRAY_A);

        $sessions = array();
        foreach ((array) $rows as $r) {
            $sessions[] = array(
                'id'         => (int) $r['id'],
                'upload_id'  => (string) $r['upload_id'],
                'filename'   => (string) $r['filename'],
                'filesize'   => (int) $r['filesize'],
                'bytes_sent' => (int) $r['bytes_sent'],
                'state'      => (string) $r['state'],
                'signed_url' => (string) $r['signed_url'],
                'session_uri' => (string) $r['session_uri'],
                'settings'   => json_decode((string) $r['settings_json'], true) ?: array(),
                'expired'    => self::url_expired($r),   // Resume will re-create the session: the row must not promise held bytes (QA U6)
            );
        }

        return rest_ensure_response(array('sessions' => $sessions));
    }

    public static function batch_status($request) {
        global $wpdb;

        Fastpix_Uploads_Ingest::bind_unbound(get_current_user_id());   // a row the browser gave up on may be a media already (QA F1/F3)

        // The Add media queue polls this route while a file is processing. Its answer is a LOCAL read, and the
        // local row only reaches Ready when the media.ready webhook lands or the 15-minute sweep runs — with
        // webhooks configured the poll chain is deliberately skipped (schedule_poll_if_pending), so a delayed or
        // undelivered webhook left the row saying "Processing" for many minutes while FastPix already had it
        // Ready. While someone is watching, ask the platform about those media directly. (owner 2026-09-22)
        self::refresh_watched($request);

        $ids       = array_filter(array_map('intval', explode(',', (string) $request->get_param('ids'))));
        $media_ids = array_filter(array_map('trim', explode(',', (string) $request->get_param('media_ids'))));
        $out       = array('uploads' => array(), 'media' => array());
        $videos    = Fastpix_Schema::table('videos');

        if ($ids) {
            $rows = $wpdb->get_results(
                'SELECT u.id, u.state, u.video_id, u.updated_at, v.id AS vid, v.media_id, v.status, v.ai_state, v.error_code
                 FROM ' . Fastpix_Schema::table('uploads') . ' u LEFT JOIN ' . $videos . ' v ON v.id = u.video_id
                 WHERE u.id IN (' . implode(',', array_map('intval', $ids)) . ') AND u.user_id = ' . (int) get_current_user_id(),
                ARRAY_A
            );
            foreach ((array) $rows as $r) {
                $out['uploads'][(string) $r['id']] = array(
                    'state' => $r['state'], 'video_id' => (int) $r['video_id'], 'media_id' => (string) $r['media_id'],
                    'status' => (string) $r['status'], 'ai_state' => (string) $r['ai_state'], 'error_code' => (string) $r['error_code'],
                    // Finished, but no video was ever found for it and the grace is over: an open tab stops
                    // saying "Processing" (the list above drops the row on the next load). (QA 2026-09-21)
                    'stale' => $r['state'] === 'completed' && $r['vid'] === null && strtotime($r['updated_at'] . ' UTC') < time() - self::UNBOUND_GRACE_SECONDS,
                );
            }
        }
        if ($media_ids) {
            $place = implode(',', array_fill(0, count($media_ids), '%s'));
            $rows  = $wpdb->get_results($wpdb->prepare(
                // A deleted video must still ANSWER: filtered out, the link row never settled and polled every 4 s
                // for the life of the page. The queue removes a row it is told is deleted. (QA 2026-09-22)
                'SELECT id, media_id, status, ai_state, error_code, deleted_at FROM ' . $videos . " WHERE media_id IN ({$place})",
                $media_ids
            ), ARRAY_A);
            foreach ((array) $rows as $r) {
                $out['media'][$r['media_id']] = array('video_id' => (int) $r['id'], 'status' => $r['deleted_at'] ? 'deleted' : (string) $r['status'], 'ai_state' => (string) $r['ai_state'], 'error_code' => (string) $r['error_code']);
            }
        }

        return rest_ensure_response($out);
    }

    /**
     * Re-read from the platform the media this screen is watching that have not settled yet, so the queue
     * does not wait on a webhook that may never arrive. Bounded: only media named in THIS request (the rows
     * the user is looking at), only while unsettled, and at most one platform GET per media per
     * WATCH_REFRESH_SECONDS however many tabs poll. (owner 2026-09-22)
     */
    private static function refresh_watched($request) { // NOSONAR php:S100 — WordPress snake_case naming
        global $wpdb;

        $ids       = array_filter(array_map('intval', explode(',', (string) $request->get_param('ids'))));
        $media_ids = array_filter(array_map('trim', explode(',', (string) $request->get_param('media_ids'))));
        $videos    = Fastpix_Schema::table('videos');

        if ($ids) {
            $own = ' AND u.user_id = ' . (int) get_current_user_id();   // never another user's rows
            $media_ids = array_merge($media_ids, (array) $wpdb->get_col(
                'SELECT v.media_id FROM ' . Fastpix_Schema::table('uploads') . ' u JOIN ' . $videos . ' v ON v.id = u.video_id
                 WHERE u.id IN (' . implode(',', array_map('intval', $ids)) . ')' . $own
            ));
        }
        $media_ids = array_slice(array_unique(array_filter($media_ids)), 0, 20);
        if (!$media_ids) {
            return;
        }

        $place = implode(',', array_fill(0, count($media_ids), '%s'));
        $rows  = $wpdb->get_results($wpdb->prepare(
            "SELECT media_id, status FROM {$videos} WHERE media_id IN ({$place}) AND deleted_at IS NULL",
            $media_ids
        ), ARRAY_A);

        foreach ((array) $rows as $row) {
            if (Fastpix_Sync::rank((string) $row['status']) >= 2) {
                continue;   // ready / failed / deleted: nothing left to learn
            }
            $key = 'fastpix_watch_' . hash('sha256', (string) $row['media_id']);
            if (get_transient($key)) {
                continue;
            }
            set_transient($key, 1, self::WATCH_REFRESH_SECONDS);
            Fastpix_Sync::fetch_and_apply((string) $row['media_id']);
        }
    }

    /**
     * Progress, pause and resume. Resume verifies name and size — a different
     * file is refused, never spliced; nothing resumes silently. [RULE-008]
     */
    public static function update_session($request) {
        global $wpdb;

        $row = self::own_row($request);
        if (is_wp_error($row)) {
            return $row;
        }

        $update  = array('updated_at' => current_time('mysql', true));
        $refusal = null;
        if ($row['state'] === 'cancelled') {   // terminal: the tab learns it and stops (QA U21)
            $refusal = new \WP_Error('fastpix_upload_cancelled', __('This upload was cancelled.', 'fastpix'), array('status' => 409));
        } elseif ($request->get_param('resume')) {
            $refusal = self::apply_resume($request, $row, $update);
        }
        $refusal = $refusal ?: self::apply_progress($request, $row, $update);
        if ($refusal) {
            return $refusal;
        }

        $wpdb->update(Fastpix_Schema::table('uploads'), $update, array('id' => $row['id']));

        if (isset($update['state']) && $update['state'] === 'completed') {
            Fastpix_Uploads_Ingest::bind_unbound((int) $row['user_id']);   // media id = upload id; webhooks may never arrive here
        }

        $session_uri = isset($row['session_uri']) ? (string) $row['session_uri'] : '';
        if (array_key_exists('session_uri', $update)) {
            $session_uri = (string) $update['session_uri'];
        }

        return rest_ensure_response(array(
            'id'         => (int) $row['id'],
            'state'      => isset($update['state']) ? $update['state'] : $row['state'],
            'bytes_sent' => isset($update['bytes_sent']) ? $update['bytes_sent'] : (int) $row['bytes_sent'],
            'signed_url' => $row['signed_url'],
            'session_uri' => $session_uri,
        ));
    }

    /**
     * The progress half of update_session: bytes sent, the bucket session address
     * and the state change. $update is amended in place.
     *
     * @return \WP_Error|null
     */
    private static function apply_progress($request, $row, &$update) {
        if ($request->get_param('bytes_sent') !== null) {
            $update['bytes_sent'] = min((int) $request->get_param('bytes_sent'), (int) $row['filesize']);
        }
        if ($request->get_param('session_uri') !== null && !array_key_exists('session_uri', $update)) {   // a resume that re-created the session sets it to null on purpose
            $uri = (string) $request->get_param('session_uri');
            // Verified live 2026-09-20: the bucket session lives on the signed URL's host (storage.googleapis.com); any other origin is not ours to resume against. (QA U17)
            if (strtolower((string) wp_parse_url($uri, PHP_URL_HOST)) !== strtolower((string) wp_parse_url((string) $row['signed_url'], PHP_URL_HOST))) {
                return new \WP_Error('fastpix_session_uri_host', __('The session address must be on the same host as the signed upload URL.', 'fastpix'), array('status' => 400));
            }
            $update['session_uri'] = $uri;
        }
        // completed is terminal too: a progress report that lands late cannot reopen it.
        if ($request->get_param('state') !== null && !$request->get_param('resume') && $row['state'] !== 'completed') {
            $update['state'] = (string) $request->get_param('state');
            if ($update['state'] === 'paused') {
                $update['attempts'] = (int) $row['attempts'] + 1;
            }
        }

        return null;
    }

    /**
     * The resume half of update_session: verify it is the SAME file, refresh an
     * expired signed URL under the same idempotency key, mark uploading. $row and
     * $update are amended in place. [RULE-008]
     *
     * @return \WP_Error|null
     */
    private static function apply_resume($request, &$row, &$update) {
        $name = sanitize_file_name((string) $request->get_param('filename'));
        $size = (int) $request->get_param('filesize');

        $session = null;
        if ((string) get_option(Fastpix_Connection::OPT_PENDING_LEAVE, '') !== '') {   // the signed URL may belong to the workspace just left [ASSUME-092]
            $session = new \WP_Error('fastpix_workspace_pending', __('Uploads resume once the newly connected workspace is confirmed.', 'fastpix'), array('status' => 409));
        } elseif ($name !== $row['filename'] || $size !== (int) $row['filesize']) {
            $session = new \WP_Error(
                'fastpix_wrong_file',
                /* translators: 1: file name, 2: file size */
                sprintf(__('That is a different file. Resume needs %1$s (%2$s) — the upload continues from where it stopped only with the same file.', 'fastpix'),
                    $row['filename'], size_format((int) $row['filesize'])),
                array('status' => 409)
            );
        } elseif ($row['state'] === 'uploading' && strtotime($row['updated_at'] . ' UTC') > time() - self::INTERRUPT_SECONDS) {
            // Still reporting progress: another tab owns this transfer; two senders on one session corrupt it. (QA U20)
            $session = new \WP_Error('fastpix_upload_live', __('This upload is still running in another tab or window.', 'fastpix'), array('status' => 409));
        } elseif (self::url_expired($row)) {
            // Signed URL past its window: re-create the platform session under
            // the SAME idempotency key so no second object appears.
            $session = self::platform_session((int) $row['id'], json_decode((string) $row['settings_json'], true));
            if (!is_wp_error($session)) {
                $update['signed_url']     = $session['url'];
                $update['url_expires_at'] = $session['expires_at'];
                $update['session_uri']    = null;   // a new platform session is a new bucket object: the old resumable session, and its bytes, are gone
                $update['bytes_sent']     = 0;
                $row['signed_url']        = $session['url'];
                // Verified live 2026-09-18: the platform ignores the idempotency key here and
                // hands back a NEW upload id. The media will carry that id, so the row (and
                // the placeholder video row keyed by the old id) must follow it, or the
                // media_created webhook finds no upload to bind and the row stays "Processing".
                if ($session['upload_id'] !== '' && $session['upload_id'] !== $row['upload_id']) {
                    Fastpix_Uploads_Ingest::rekey_upload($row['upload_id'], $session['upload_id']);
                    $update['upload_id'] = $session['upload_id'];
                    $row['upload_id']    = $session['upload_id'];
                }
            }
        }
        if (!is_wp_error($session)) {
            $update['state'] = 'uploading';
            $session = null;
        }

        return $session;
    }

    /** [WF-002] Cancel → platform cancel → row cancelled. Bytes already sent are discarded by the platform. */
    public static function cancel_session($request) {
        global $wpdb;

        $row = self::own_row($request);
        if (is_wp_error($row)) {
            return $row;
        }

        if ($row['upload_id'] !== '' && strpos($row['upload_id'], 'pending:') !== 0) {
            $client = new Fastpix_Api_Client();
            $client->request('PUT', '/on-demand/upload/' . rawurlencode($row['upload_id']) . '/cancel', array('context' => 'background'));
        }

        $wpdb->update(Fastpix_Schema::table('uploads'), array(
            'state'      => 'cancelled',
            'updated_at' => current_time('mysql', true),
        ), array('id' => $row['id']));

        return rest_ensure_response(array('id' => (int) $row['id'], 'state' => 'cancelled'));
    }

    /** The SSRF gate lives in Fastpix_Url_Guard; kept here as the public API (migration + tests). [SEC-010, RULE-006] */
    public static function validate_public_video_url($url, $hops = 0) {
        return Fastpix_Url_Guard::validate_public_video_url($url, $hops);
    }

    /* ---------------------------------------------------- orphan sweep */

    /**
     * [RULE-009] Sessions untouched for seven days are cancelled by the nightly
     * sweep, and the owner is told how many.
     */
    public static function orphan_sweep() {
        global $wpdb;

        $table  = Fastpix_Schema::table('uploads');
        $cutoff = gmdate(self::MYSQL_DATETIME, time() - (7 * DAY_IN_SECONDS));
        $rows   = $wpdb->get_results($wpdb->prepare(
            "SELECT id, upload_id FROM {$table}
             WHERE state IN ('created', 'uploading', 'paused') AND updated_at < %s",
            $cutoff
        ), ARRAY_A);

        if (!$rows) {
            return 0;
        }

        $client = new Fastpix_Api_Client();
        foreach ($rows as $row) {
            if ($row['upload_id'] !== '' && strpos($row['upload_id'], 'pending:') !== 0) {
                $client->request('PUT', '/on-demand/upload/' . rawurlencode($row['upload_id']) . '/cancel', array('context' => 'background'));
            }
            $wpdb->update($table, array('state' => 'cancelled', 'updated_at' => current_time('mysql', true)), array('id' => $row['id']));
        }

        $count = count($rows);
        update_option('fastpix_orphan_notice', array('count' => $count, 'at' => time()), false);
        do_action('fastpix_log', 'upload_orphans_cancelled', array(
            'severity' => 'info', 'scope' => 'uploads',
            'message'  => sprintf('%d upload sessions untouched for 7 days were cancelled.', $count),
        ));

        return $count;
    }

    /* ------------------------------------------------------------ helpers */

    /** [RULE-005] Refuse, don't promise: no signed URL can be issued ⇒ refuse with reason + retry. */
    public static function refuse_if_unavailable() {
        $refusal = null;
        if (!Fastpix_Credentials::has_pair()) {
            // 409: the route exists, the site's state conflicts with it.
            $refusal = new \WP_Error('fastpix_not_connected', __('This site is not connected to FastPix. Connect a workspace from the FastPix menu first.', 'fastpix'), array('status' => 409));
        } elseif (!Fastpix_Api_Client::is_healthy()) {
            $refusal = new \WP_Error('fastpix_unhealthy', __('FastPix rejected the stored credentials, so new uploads are paused. Re-check the connection.', 'fastpix'), array('status' => 503));
        } else {
            $breaker = get_transient(Fastpix_Api_Client::TRANSIENT_BREAKER);
            if (is_array($breaker) && isset($breaker['open_until']) && $breaker['open_until'] > time()) {
                $refusal = new \WP_Error('fastpix_offline', __('FastPix is not responding right now, so nothing was queued. Try again shortly.', 'fastpix'), array('status' => 503, 'retry' => true));
            }
        }

        return $refusal;
    }

    /** Past the platform's upload window (its `timeout`, 4 h — verified live 2026-09-20), the session is re-created on resume. */
    private static function url_expired($row) {
        return $row['url_expires_at'] !== null && strtotime($row['url_expires_at'] . ' UTC') < time();
    }

    /** Own-limits as a query condition: the row is fetched WITH the user filter. [REQ-091]
     *  A session is one browser's transfer — nobody else's to pause, resume or cancel, whatever their video capability. (QA U17) */
    private static function own_row($request) {
        global $wpdb;

        $table = Fastpix_Schema::table('uploads');
        $row   = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d AND user_id = %d",
            (int) $request->get_param('id'), get_current_user_id()
        ), ARRAY_A);

        if (!$row) {
            return new \WP_Error('fastpix_upload_missing', __('No such upload session.', 'fastpix'), array('status' => 404));
        }

        return $row;
    }

    /** Create/refresh the platform session. Idempotency key from the local row id. [WF-002] */
    private static function platform_session($row_id, $settings) {
        $media = self::platform_media_settings($settings);
        if (is_wp_error($media)) {
            return $media;
        }
        $client = new Fastpix_Api_Client();
        $result = $client->request('POST', '/on-demand/upload', array(
            'idempotency_row_id' => 'uploads:' . $row_id,
            'body'               => array(
                // Media settings ride under pushMediaSettings (flat keys → 422);
                // corsOrigin so the signed URL accepts this site's browser PUTs.
                // The batch title is not set here — the media does not exist until
                // the file lands; it is stamped on the `media_created` webhook via
                // PATCH /on-demand/{id} {title} (see finalize handler).
                'corsOrigin'        => home_url(),
                'pushMediaSettings' => $media,
            ),
        ));

        if (is_wp_error($result)) {
            return $result;
        }

        $body = is_array($result['body']) ? $result['body'] : array();
        $data = isset($body['data']) && is_array($body['data']) ? $body['data'] : $body;
        $url  = (string) Fastpix_Sync::field($data, array('url', 'uploadUrl', 'signedUrl'));

        if ($url === '') {
            $session = new \WP_Error('fastpix_no_signed_url', __('FastPix did not return an upload URL.', 'fastpix'), array('status' => 502));
        } else {
            $session = array(
                'upload_id'  => (string) Fastpix_Sync::field($data, array('uploadId', 'id', 'upload_id')),
                'url'        => $url,
                // The response states the window as `timeout` seconds (14400 — verified live 2026-09-20,
                // matching X-Goog-Expires on the URL); an hour only if it ever goes missing. (QA U6)
                // ponytail: whether the bucket session outlives the platform window is unverified —
                // the session is re-created past it and the row says so.
                'expires_at' => gmdate(self::MYSQL_DATETIME, time() + ((int) Fastpix_Sync::field($data, array('timeout')) > 0 ? (int) Fastpix_Sync::field($data, array('timeout')) : HOUR_IN_SECONDS)),
            );
        }

        return $session;
    }

    /**
     * The REQ-017 per-upload settings, defaults included: a per-batch snapshot,
     * persisted on the rows it created and nowhere else.
     */
    /**
     * The media-settings fragment shared by upload sessions and URL ingestion.
     * DRM needs the configuration id saved on the Settings screen. [REQ-053]
     *
     * @return array|\WP_Error
     */
    public static function platform_media_settings($settings) {
        $tier = isset($settings['quality_tier']) ? $settings['quality_tier'] : 'standard';
        $body = array(
            'accessPolicy'  => isset($settings['access_policy']) ? $settings['access_policy'] : 'public',   // public | private | drm — the snapshot already clamps to these
            'maxResolution' => isset($settings['max_resolution']) ? $settings['max_resolution'] : '1080p',
            // Verified live 2026-09-20 on POST /on-demand/upload (pushMediaSettings) and POST /on-demand:
            // the quality tier is `mediaQuality` (standard | pro | premium, anything else → 422) and
            // "Even out volume" is `optimizeAudio` (boolean). Both echoed back on the media. (QA U5)
            'mediaQuality'  => in_array($tier, array('standard', 'pro', 'premium'), true) ? $tier : 'standard',
            'optimizeAudio' => !empty($settings['normalize_audio']),
        );

        if ($body['accessPolicy'] === 'drm') {
            $drm_id = Fastpix_Settings_Page::drm_configuration_id();
            if ($drm_id === '') {
                return new \WP_Error('fastpix_drm_unconfigured', __('DRM needs a DRM configuration ID — add it under FastPix → Settings → DRM, then try again.', 'fastpix'), array('status' => 409));
            }
            $body['drmConfigurationId'] = $drm_id;
        }

        // Downloadable file → mp4Support at creation (create-from-URL and direct
        // upload both take it, per docs). DRM never has downloads.
        if (!empty($settings['downloadable']) && $settings['downloadable'] !== 'off' && $body['accessPolicy'] !== 'drm') {
            $body['mp4Support'] = Fastpix_Videos_Rest::MP4_SUPPORT[$settings['downloadable']];
        }

        $watermark = Fastpix_Uploads_Settings::watermark_input($settings);
        if ($watermark) {
            $check = Fastpix_Uploads_Settings::watermark_reachable($watermark['url']);
            if (is_wp_error($check)) {
                return $check;
            }
            // Direct upload takes the watermark alone (the video is the file being pushed); the
            // create-from-URL path prepends its own video input to this same array.
            $body['inputs'] = array($watermark);
        }

        return $body;
    }

    /* -------------------------------------------- domain lock (AMBIG-009) */

    public static function queue_domain_lock($media_id) {
        Fastpix_Jobs::enqueue(self::HOOK_DOMAIN_LOCK, array('media_id' => (string) $media_id, 'attempt' => 1), Fastpix_Jobs::GROUP_SYNC);
    }
}
