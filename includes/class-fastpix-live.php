<?php
/**
 * Live streams — WF-008, UI-004L, FR-070, REQ-070…072, RULE-033/034/040,
 * API-F07/F10, TEST-010. On by default; the `fastpix_feature_live` filter can turn it off.
 *
 * A control surface, not a broadcast tool: streams are created and controlled
 * here, state is driven by webhooks (class-fastpix-webhooks-apply.php stream),
 * playback is the render engine's three-state live embed (RULE-034), and the
 * recording lands in the library as an ordinary video (REQ-072).
 *
 * Platform contract: the create/list/detail responses carry streamId,
 * streamKey, srtSecret, status, enableRecording, playbackIds and
 * srtPlaybackResponse — but NO ingest addresses, so the documented endpoints
 * (API-F10: rtmps://live.fastpix.com:443/live, srt://live.fastpix.com:778) are
 * constants here, filterable. `PUT /live/streams/{id}/finish` refuses while
 * idle ("stream cannot be completed") — the End button only shows while live.
 * Viewer count answers `{views: n}`.
 */

namespace Fastpix;

if (!defined('WPINC')) {
    die;
}

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery
// All SQL here runs against the plugin's own tables: identifiers come from the
// Fastpix_Schema::table() registry (a fixed whitelist, never request input) and
// every value travels through $wpdb->prepare(). Core APIs cannot query these tables.

class Fastpix_Live {

    private const PATH       = '/live/streams/';
    private const SQL_SELECT = 'SELECT * FROM ';

    /** Live ships on in 2.0.0; a site can still switch it off with the filter. */
    public static function enabled() {
        return (bool) apply_filters('fastpix_feature_live', true);
    }

    /** API-F10 — documented ingest endpoints; the platform never returns them. */
    public static function ingest() {
        return apply_filters('fastpix_live_ingest', array(
            'rtmps' => 'rtmps://live.fastpix.com:443/live',
            'srt'   => 'srt://live.fastpix.com:778',
        ));
    }

    public static function boot() {
        if (!self::enabled()) {
            return;
        }

        add_action('rest_api_init', array(__CLASS__, 'register_routes'));

        // Sweep repair (WF-009): a missed webhook cannot strand a published
        // embed — the 15-minute sweep re-reads stream state from the platform.
        add_action('fastpix_new_media_sweep', array(__CLASS__, 'refresh_from_platform'));
    }

    public static function register_routes() {
        // Actor per WF-008 / SDD §20: fastpix_upload_video creates and
        // controls; viewing the list takes the library capability.
        Fastpix_Rest::register('/streams', array(
            array(
                'methods'    => 'GET',
                'capability' => Fastpix_Capabilities::VIEW_VIDEOS,
                'callback'   => array(__CLASS__, 'list_streams'),
            ),
            array(
                'methods'    => 'POST',
                'capability' => Fastpix_Capabilities::UPLOAD_VIDEO,
                'callback'   => array(__CLASS__, 'create_stream'),
                'args'       => array(
                    'name'      => Fastpix_Rest::arg('string'),
                    'recording' => Fastpix_Rest::arg('boolean', array('default' => true)),
                    // Two policies: the broadcast (public | private — the live API takes nothing else) and the
                    // recording it lands as, which is an ordinary media and can be DRM. media_access defaults to
                    // the broadcast's when it is not sent. (owner 2026-09-22)
                    'access'       => Fastpix_Rest::arg('string', array('enum' => array('public', 'private'), 'default' => 'public')),
                    'media_access' => Fastpix_Rest::arg('string', array('enum' => array('public', 'private', 'drm'))),
                ),
            ),
        ));

        Fastpix_Rest::register('/streams/(?P<id>[A-Za-z0-9_-]+)', array(
            array(
                'methods'    => 'GET',
                'capability' => Fastpix_Capabilities::UPLOAD_VIDEO,   // the detail carries the stream key
                'callback'   => array(__CLASS__, 'get_stream'),
            ),
            array(
                'methods'    => 'PATCH',
                'capability' => Fastpix_Capabilities::UPLOAD_VIDEO,
                'callback'   => array(__CLASS__, 'update_stream'),
                'args'       => array(
                    // Recording is create-time only — the platform ignores
                    // enableRecording on PATCH.
                    'name' => Fastpix_Rest::arg('string'),
                ),
            ),
            array(
                'methods'    => 'DELETE',
                'capability' => Fastpix_Capabilities::UPLOAD_VIDEO,
                'callback'   => array(__CLASS__, 'delete_stream'),
            ),
        ));

        // Simulcast contract: POST {url, streamKey} answers the target with
        // simulcastId; PUT accepts ONLY isEnabled (url/key edits are
        // delete + re-add); the stream detail lists simulcastResponses.
        Fastpix_Rest::register('/streams/(?P<id>[A-Za-z0-9_-]+)/simulcast', array(
            'methods'    => 'POST',
            'capability' => Fastpix_Capabilities::UPLOAD_VIDEO,
            'callback'   => array(Fastpix_Live_Simulcast::class, 'add'),
            'args'       => array(
                'url'        => Fastpix_Rest::arg('string', array('required' => true)),
                'stream_key' => Fastpix_Rest::arg('string', array('required' => true)),
            ),
        ));
        Fastpix_Rest::register('/streams/(?P<id>[A-Za-z0-9_-]+)/simulcast/(?P<tid>[A-Za-z0-9_-]+)', array(
            array(
                'methods'    => 'PATCH',
                'capability' => Fastpix_Capabilities::UPLOAD_VIDEO,
                'callback'   => array(Fastpix_Live_Simulcast::class, 'update'),
                'args'       => array(
                    'enabled' => Fastpix_Rest::arg('boolean', array('required' => true)),
                ),
            ),
            array(
                'methods'    => 'DELETE',
                'capability' => Fastpix_Capabilities::UPLOAD_VIDEO,
                'callback'   => array(Fastpix_Live_Simulcast::class, 'remove'),
            ),
        ));

        Fastpix_Rest::register('/streams/(?P<id>[A-Za-z0-9_-]+)/finish', array(
            'methods'    => 'POST',
            'capability' => Fastpix_Capabilities::UPLOAD_VIDEO,
            'callback'   => array(__CLASS__, 'finish_stream'),
        ));

        // Verified live 2026-09-09: PUT /live/streams/{id}/live-disable → status "disabled",
        // PUT …/live-enable → "idle"; both answer {success:true}. [ASSUME-080]
        Fastpix_Rest::register('/streams/(?P<id>[A-Za-z0-9_-]+)/(?P<op>enable|disable)', array(
            'methods'    => 'POST',
            'capability' => Fastpix_Capabilities::UPLOAD_VIDEO,
            'callback'   => array(__CLASS__, 'set_enabled'),
        ));

        // The published embed polls this to switch states by itself (RULE-034).
        // Local row only — no platform call, no secrets, rate-limited per
        // address AND per stream id like every public route. [SEC-012]
        Fastpix_Rest::register('/stream-state/(?P<id>[A-Za-z0-9_-]+)', array(
            'methods'       => 'GET',
            'public_bucket' => 'player_config',
            'callback'      => array(__CLASS__, 'stream_state'),
        ));
    }

    /** GET /stream-state/{id} — status + whether the recording has landed. */
    public static function stream_state($request) {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT status, recorded_video_id, recording_enabled FROM ' . Fastpix_Schema::table('live_streams') . ' WHERE stream_id = %s AND deleted_at IS NULL AND workspace_id NOT LIKE %s',
            (string) $request->get_param('id'), $wpdb->esc_like('prev:') . '%'
        ), ARRAY_A);

        if (!$row) {
            return new \WP_Error('fastpix_stream_missing', __('No such stream.', 'fastpix-io'), array('status' => 404));   // [QA B5]
        }

        // A visitor waiting on the ended card polls this route: it is what makes the page switch to the
        // recording by itself (RULE-034). The local row only learns of a recording from a webhook or an admin
        // refresh, so an ended, recorded, still-unlinked stream asks the platform — find_recording() allows one
        // GET per stream every two minutes however many visitors poll; every other answer stays a local read. (QA report #12)
        $has_recording = !empty($row['recorded_video_id']);
        if (!$has_recording && strtolower((string) $row['status']) === 'ended' && !empty($row['recording_enabled'])) {
            $has_recording = self::find_recording((string) $request->get_param('id'));
        }

        $response = rest_ensure_response(array(
            'status'    => strtolower((string) $row['status']),
            'recording' => $has_recording,
        ));
        $response->header('Cache-Control', 'no-store');

        return $response;
    }

    /* --------------------------------------------------------------- reads */

    /**
     * GET /streams — the local rows, refreshed from the platform first so
     * streams created on the dashboard appear too (sweep repair, WF-009).
     */
    public static function list_streams() {
        global $wpdb;

        self::refresh_from_platform();

        // Only the connected workspace's streams (RULE-001): rows are stamped with
        // the owner's workspace key at upsert, so a new pair lists none of the old.
        $rows = $wpdb->get_results($wpdb->prepare(
            self::SQL_SELECT . Fastpix_Schema::table('live_streams') . ' WHERE deleted_at IS NULL AND workspace_id = %s ORDER BY created_at DESC LIMIT 100',
            (string) get_option(Fastpix_Connection::OPT_WORKSPACE_ID, '')
        ), ARRAY_A);

        return rest_ensure_response(array('streams' => array_map(array(__CLASS__, 'shape'), $rows)));
    }

    /** GET /streams/{id} — fresh platform detail (keys included) + local row. */
    public static function get_stream($request) {
        global $wpdb;

        $stream_id = (string) $request->get_param('id');
        $client    = new Fastpix_Api_Client();
        $result    = $client->request('GET', self::PATH . rawurlencode($stream_id), array('context' => 'interactive'));
        if (is_wp_error($result)) {
            return $result;
        }
        $data = isset($result['body']['data']) ? (array) $result['body']['data'] : array();
        self::upsert($data);

        $row = $wpdb->get_row($wpdb->prepare(
            self::SQL_SELECT . Fastpix_Schema::table('live_streams') . ' WHERE stream_id = %s',
            $stream_id
        ), ARRAY_A);

        $out = self::shape($row ?: array('stream_id' => $stream_id, 'status' => (string) ($data['status'] ?? 'idle')));
        // Credentials — read from the platform response, never composed
        // (REQ-070). The key is a secret: the UI masks it by default.
        $playback = (array) ($data['playbackIds'][0] ?? array());
        $policy   = strtolower((string) ($playback['accessPolicy'] ?? ''));
        // The library's preview rail plays the live stream in place. A private
        // stream needs the same signed token the front-end embed mints
        // (RULE-033); this route is capability-gated, so minting here is safe.
        $token = '';
        if ($out['status'] === 'active' && $policy !== '' && $policy !== 'public' && !empty($playback['id'])) {
            $signed = Fastpix_Signing::tokens((string) $playback['id']);
            $token  = is_wp_error($signed) ? '' : (string) $signed['media'];
        }
        $out += array(
            'stream_key'  => (string) ($data['streamKey'] ?? ''),
            'srt_secret'  => (string) ($data['srtSecret'] ?? ''),
            'ingest'      => self::ingest(),
            'playback_id' => (string) ($playback['id'] ?? ''),
            'playback_policy' => $policy,
            'playback_token'  => $token,
            'max_resolution' => (string) ($data['maxResolution'] ?? ''),
            'simulcast'   => array_map(array(Fastpix_Live_Simulcast::class, 'shape'), (array) ($data['simulcastResponses'] ?? array())),
        );

        return rest_ensure_response($out);
    }

    /* -------------------------------------------------------------- writes */

    /** POST /streams — create on the platform; the row appears Idle. */
    public static function create_stream($request) {
        $name      = sanitize_text_field((string) $request->get_param('name'));
        $access    = (string) $request->get_param('access');
        $recording = (bool) $request->get_param('recording');
        // TWO policies (owner 2026-09-22): the LIVE broadcast (playbackSettings.accessPolicy — public | private,
        // the live API takes nothing else) and the RECORDING it becomes (inputMediaSettings.mediaPolicy), which is
        // an ordinary media and so can also be DRM. The recording follows the broadcast when the caller sends
        // nothing, and means nothing with recording off.
        $media = (string) $request->get_param('media_access');
        $media = ($media !== '' && $recording) ? $media : $access;
        // DRM needs the DRM configuration ID from Settings — refused here, not only greyed out in the form.
        // mediaPolicy: 'drm' is NOT verified against the platform (the live guide documents public | private), so
        // the body mirrors the on-demand shape and a refusal comes back to the form verbatim.
        $drm_id = $media === 'drm' ? Fastpix_Settings_Page::drm_configuration_id() : '';
        if ($media === 'drm' && $drm_id === '') {
            return new \WP_Error('fastpix_drm_unconfigured', __('DRM needs a DRM configuration ID — add it under FastPix → Settings → DRM, then try again.', 'fastpix-io'), array('status' => 409));
        }
        $playback = array('accessPolicy' => $access);
        $client = new Fastpix_Api_Client();
        $result = $client->request('POST', '/live/streams', array(
            'context' => 'interactive',
            'body'    => array(
                'playbackSettings'   => $playback,
                // Verified live 2026-09-20: the platform defaults enableRecording
                // to TRUE when omitted and honours an explicit false — so false
                // must travel (a plain array_filter dropped it).
                'inputMediaSettings' => array_filter(array(
                    'maxResolution'   => '1080p',
                    'mediaPolicy'        => $media,
                    'drmConfigurationId' => $media === 'drm' ? $drm_id : null,
                    'enableRecording'    => $recording,
                    'metadata'        => $name !== '' ? array('name' => $name) : null,
                ), function ($v) { return $v !== null; }),
            ),
        ));
        $data = !is_wp_error($result) && isset($result['body']['data']) ? (array) $result['body']['data'] : array();
        if (!is_wp_error($result) && empty($data['streamId'])) {
            $result = new \WP_Error('fastpix_stream_create_failed', __('FastPix did not return a stream.', 'fastpix-io'), array('status' => 502));
        }
        if (is_wp_error($result)) {
            return $result;
        }
        self::upsert($data, $name);

        do_action('fastpix_audit_event', 'live_stream_created', array('stream_id' => (string) $data['streamId']));

        // Hand back everything the encoder panel needs in one round trip.
        $request2 = new \WP_REST_Request('GET', '');
        $request2->set_param('id', (string) $data['streamId']);

        return self::get_stream($request2);
    }

    /** PATCH /streams/{id} — rename only; recording cannot change after create. */
    public static function update_stream($request) {
        global $wpdb;

        $stream_id = (string) $request->get_param('id');
        $row = $wpdb->get_row($wpdb->prepare(
            self::SQL_SELECT . Fastpix_Schema::table('live_streams') . ' WHERE stream_id = %s AND deleted_at IS NULL',
            $stream_id
        ), ARRAY_A);
        if (!$row) {
            return new \WP_Error('fastpix_stream_missing', __('No such stream.', 'fastpix-io'), array('status' => 404));
        }

        $body = array();
        if ($request->get_param('name') !== null) {
            $body['metadata'] = array('name' => sanitize_text_field((string) $request->get_param('name')));
        }

        if ($body) {
            $renamed = self::rename_remote($stream_id, $body);
            if (is_wp_error($renamed)) {
                return $renamed;
            }
            $row = $wpdb->get_row($wpdb->prepare(self::SQL_SELECT . Fastpix_Schema::table('live_streams') . ' WHERE stream_id = %s', $stream_id), ARRAY_A);
        }

        return rest_ensure_response(self::shape($row));
    }

    /** PATCH the rename to the platform and fold the answer into the local row. */
    private static function rename_remote($stream_id, $body) {
        $client = new Fastpix_Api_Client();
        $result = $client->request('PATCH', self::PATH . rawurlencode($stream_id), array('context' => 'interactive', 'body' => $body));
        if (is_wp_error($result)) {
            return $result;
        }
        self::upsert(isset($result['body']['data']) ? (array) $result['body']['data'] : array(), (string) ($body['metadata']['name'] ?? ''));

        return null;
    }

    /** POST /streams/{id}/finish — the "End this stream" button (live only). */
    public static function finish_stream($request) {
        $client = new Fastpix_Api_Client();
        $result = $client->request('PUT', self::PATH . rawurlencode((string) $request->get_param('id')) . '/finish', array('context' => 'interactive'));
        if (is_wp_error($result)) {
            // The platform refuses while idle ("stream cannot be completed").
            return new \WP_Error('fastpix_stream_not_live', $result->get_error_message(), array('status' => 409));
        }

        do_action('fastpix_audit_event', 'live_stream_finished', array('stream_id' => (string) $request->get_param('id')));

        return rest_ensure_response(array('finished' => true));
    }

    /** POST /streams/{id}/enable|disable — a disabled stream refuses every encoder until enabled again. */
    public static function set_enabled($request) {
        global $wpdb;

        $stream_id = (string) $request->get_param('id');
        $enable    = $request->get_param('op') === 'enable';
        $client    = new Fastpix_Api_Client();
        $result    = $client->request('PUT', self::PATH . rawurlencode($stream_id) . ($enable ? '/live-enable' : '/live-disable'), array('context' => 'interactive'));
        if (is_wp_error($result)) {
            return new \WP_Error('fastpix_stream_toggle_failed', $result->get_error_message(), array('status' => 409));
        }

        $status = $enable ? 'idle' : 'disabled';
        $wpdb->update(Fastpix_Schema::table('live_streams'), array('status' => $status, 'updated_at' => current_time('mysql', true)), array('stream_id' => $stream_id));
        do_action('fastpix_audit_event', $enable ? 'live_stream_enabled' : 'live_stream_disabled', array('stream_id' => $stream_id));

        return rest_ensure_response(array('status' => $status));
    }

    /** DELETE /streams/{id} — platform delete + local tombstone (WF-008). */
    public static function delete_stream($request) {
        global $wpdb;

        $stream_id = (string) $request->get_param('id');
        $client    = new Fastpix_Api_Client();
        $result    = $client->request('DELETE', self::PATH . rawurlencode($stream_id), array('context' => 'interactive'));
        if (is_wp_error($result)) {
            return $result;
        }

        $wpdb->update(Fastpix_Schema::table('live_streams'), array(
            'deleted_at' => current_time('mysql', true),
            'updated_at' => current_time('mysql', true),
        ), array('stream_id' => $stream_id));

        do_action('fastpix_audit_event', 'live_stream_deleted', array('stream_id' => $stream_id));

        return rest_ensure_response(array('deleted' => true));
    }

    /* ------------------------------------------------------------ plumbing */

    /** Pull the platform's list and upsert — dashboard-created streams appear. */
    public static function refresh_from_platform() {
        $client = new Fastpix_Api_Client();
        $result = $client->request('GET', '/live/streams', array('context' => 'interactive', 'query' => array('limit' => 50)));
        if (is_wp_error($result)) {
            return;   // the local rows still render, with their stored age
        }
        foreach ((array) ($result['body']['data'] ?? array()) as $data) {
            self::upsert((array) $data);
        }
    }

    /**
     * One platform stream object → the local row (status is webhook-owned once live).
     *
     * Verified live 2026-09-20: the platform reports "idle" again once a
     * broadcast ends (and PATCH answers the full object, status included), so a
     * platform "idle" must not regress a row that has streamed — it means
     * "ended" there (WF-009 repair of a missed disconnect too), and a
     * "preparing" row keeps waiting for its webhook.
     */
    private static function upsert($data, $name = '') {
        global $wpdb;

        $stream_id = (string) ($data['streamId'] ?? '');
        if ($stream_id === '') {
            return;
        }
        $name   = $name !== '' ? $name : (string) ($data['metadata']['name'] ?? '');
        $status = strtolower((string) ($data['status'] ?? 'idle'));
        $now    = current_time('mysql', true);

        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . Fastpix_Schema::table('live_streams') . '
                 (stream_id, workspace_id, name, status, recording_enabled, created_at, updated_at)
             VALUES (%s, %s, %s, %s, %d, %s, %s)
             ON DUPLICATE KEY UPDATE
                 workspace_id = VALUES(workspace_id),
                 name = IF(VALUES(name) = \'\', name, VALUES(name)),
                 status = ' . self::status_sql() . ',
                 recording_enabled = VALUES(recording_enabled),
                 updated_at = VALUES(updated_at)',
            $stream_id, (string) get_option(Fastpix_Connection::OPT_WORKSPACE_ID, ''), $name, $status,
            !empty($data['enableRecording']) ? 1 : 0, $now, $now
        ));

        // The stream object lists the media its recordings became (`mediaIds`, docs: Get live stream
        // by ID): the newest one is this stream's recording. This is what links a recording on a site
        // no webhook reaches, and what repairs a link a webhook missed. (QA report #12)
        $media_ids = array_values(array_filter((array) ($data['mediaIds'] ?? array()), 'is_string'));
        if ($media_ids) {
            self::link_recording($stream_id, (string) end($media_ids));
        }
    }

    /**
     * File a recorded media as THIS stream's recording: the library video (source "Live") and the
     * link the ended embed plays. Idempotent; one platform GET only while the media is unknown here.
     * Shared by the recording webhook, the stream list/detail refresh and the ended embed.
     */
    public static function link_recording($stream_id, $media_id) { // NOSONAR php:S100 — WordPress snake_case naming
        global $wpdb;

        if ($stream_id === '' || $media_id === '') {
            return false;
        }
        $videos  = Fastpix_Schema::table('videos');
        $streams = Fastpix_Schema::table('live_streams');
        $linked  = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT v.media_id FROM {$streams} ls JOIN {$videos} v ON v.id = ls.recorded_video_id WHERE ls.stream_id = %s", $stream_id
        ));
        if ($linked === $media_id) {
            return true;
        }
        $video_id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$videos} WHERE media_id = %s", $media_id));
        if (!$video_id) {
            Fastpix_Sync::fetch_and_apply($media_id);
            $video_id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$videos} WHERE media_id = %s", $media_id));
        }
        if ($video_id) {   // else not reachable right now: the next refresh / poll tries again
            $now = current_time('mysql', true);
            $wpdb->query($wpdb->prepare("UPDATE {$videos} SET source = 'Live' WHERE id = %d AND source = 'Dashboard'", $video_id));
            $wpdb->query($wpdb->prepare("UPDATE {$streams} SET recorded_video_id = %d, updated_at = %s WHERE stream_id = %s", $video_id, $now, $stream_id));
            Fastpix_Cache::flush_group('live');
            Fastpix_Cache::flush_group('embed');
            Fastpix_Cache::flush_group('videos');
        }

        return (bool) $video_id;
    }

    /**
     * The ended embed's own look-up: a stream that recorded but has no recording linked yet asks the
     * platform for its `mediaIds`, at most once every two minutes per stream. True when one got linked.
     */
    public static function find_recording($stream_id) { // NOSONAR php:S100 — WordPress snake_case naming
        if (get_transient('fastpix_rec_lookup_' . md5($stream_id))) {
            return false;
        }
        set_transient('fastpix_rec_lookup_' . md5($stream_id), 1, 2 * MINUTE_IN_SECONDS);
        $client = new Fastpix_Api_Client();
        $result = $client->request('GET', self::PATH . rawurlencode($stream_id), array('context' => 'background'));
        if (is_wp_error($result)) {
            return false;
        }
        $data      = isset($result['body']['data']) ? (array) $result['body']['data'] : array();
        $media_ids = array_values(array_filter((array) ($data['mediaIds'] ?? array()), 'is_string'));

        return $media_ids ? self::link_recording($stream_id, (string) end($media_ids)) : false;
    }

    /**
     * ON DUPLICATE KEY status expression shared by the list refresh and the
     * webhook apply: an incoming "idle" on a row that has broadcast means
     * "ended". Must precede any last_active_at assignment. (QA X9)
     */
    public static function status_sql() {
        return "IF(VALUES(status) = 'idle' AND last_active_at IS NOT NULL, IF(status = 'preparing', status, 'ended'), VALUES(status))";
    }

    /** The list/detail row shape the Live tab renders. */
    public static function shape($row) {
        global $wpdb;

        $recorded = null;
        if (!empty($row['recorded_video_id'])) {
            $recorded = $wpdb->get_row($wpdb->prepare(
                'SELECT id, media_id, title, status, access_policy FROM ' . Fastpix_Schema::table('videos') . ' WHERE id = %d',
                (int) $row['recorded_video_id']
            ), ARRAY_A);
        }

        return array(
            'stream_id'      => (string) $row['stream_id'],
            'name'           => (string) ($row['name'] ?? ''),
            'status'         => strtolower((string) ($row['status'] ?? 'idle')),
            'recording'      => !empty($row['recording_enabled']),
            'last_active_at' => (string) ($row['last_active_at'] ?? ''),
            'created_at'     => (string) ($row['created_at'] ?? ''),
            'recorded_video' => $recorded ? array(
                'id'       => (int) $recorded['id'],
                'media_id' => (string) $recorded['media_id'],
                'title'    => (string) $recorded['title'],
                'status'   => (string) $recorded['status'],
                // The recording gates on its OWN policy (mediaPolicy at create) — not the broadcast's. (owner 2026-09-22)
                'access_policy' => (string) $recorded['access_policy'],
            ) : null,
        );
    }
}
