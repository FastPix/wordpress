<?php
/**
 * The media, track and live-stream webhook handlers of the WF-009 handler
 * table, plus the receiver's helpers (rejection, workspace check, redaction,
 * hold-or-skip), split out of Fastpix_Webhooks for size only.
 * Fastpix_Webhooks::dispatch() routes here; AI and playback-id events stay
 * with the dispatcher.
 */

namespace Fastpix;

if (!defined('WPINC')) {
    die;
}

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery
// All SQL here runs against the plugin's own tables: identifiers come from the
// Fastpix_Schema::table() registry (a fixed whitelist, never request input) and
// every value travels through $wpdb->prepare(). Core APIs cannot query these tables.

class Fastpix_Webhooks_Apply {

    /* ----------------------------------------------------------- receiver */

    /** The stored copy of a delivery: the decoded payload with secret-looking keys redacted, else the raw text. */
    public static function redacted_payload($raw, $payload) {
        if (!is_array($payload)) {
            return $raw;
        }
        $clean = Fastpix_Log::redact_keys($payload);   // keys only: a signed generatedSubtitles[].url must survive for sync_subtitles

        return $clean === $payload ? $raw : wp_json_encode($clean);
    }

    /**
     * An unverifiable delivery: source logged, counted, blocked after ten in a minute. [SEC-013]
     * The block lives on the failures bucket, which only this path reads: behind a proxy the blocked
     * address is FastPix's too, so a validly signed delivery never reaches here and still gets in, while
     * bad ones keep their 429 for the full 15 minutes — nothing a valid delivery does releases it. (QA S3)
     */
    public static function reject_invalid($raw, $signature, $address) {
        if (Fastpix_Rate_Limiter::is_blocked('webhook_failures', $address)) {
            return Fastpix_Rate_Limiter::check('webhook_failures', $address);   // the 429, before any logging
        }
        // Remembered for the settings verdict: a signed delivery that fails the
        // check is the only proof that the saved secret is not the dashboard's.
        if ($signature !== '') {
            update_option(Fastpix_Webhooks::OPT_LAST_REJECT, array('at' => time()), false);   // M27: the address lives in the (pruned) log only, not in an option without retention
        }
        do_action('fastpix_log', 'webhook_signature_invalid', array(
            'scope'   => 'webhooks',
            'message' => sprintf(
                'Webhook rejected from %s: %s (body %d bytes)',
                $address,
                $signature === '' ? 'FastPix-Signature header absent' : 'signature mismatch — check the signing secret matches the FastPix dashboard endpoint',
                strlen($raw)
            ),
        ));

        // Ten rejected deliveries from one address in a minute → 15-minute block.
        $failures = Fastpix_Rate_Limiter::count('webhook_failures', $address, 60);
        Fastpix_Rate_Limiter::check('webhook_failures', $address, Fastpix_Webhooks::FAILURES_PER_MIN + 1, 60);
        if ($failures + 1 >= Fastpix_Webhooks::FAILURES_PER_MIN) {
            Fastpix_Rate_Limiter::block('webhook_failures', $address);
        }

        return new \WP_Error('fastpix_webhook_invalid', __('Invalid signature.', 'fastpix-io'), array('status' => 401));
    }

    /**
     * Workspace check: an event for another workspace is acknowledged (it is
     * authentic) but stored as skipped, never processed. [ARCH-05]
     *
     * @return array ($workspace, $foreign)
     */
    public static function event_workspace($payload, $data) {
        $ours      = (string) get_option(Fastpix_Connection::OPT_WORKSPACE_ID, '');
        $workspace = (string) Fastpix_Sync::field($payload, array('workspaceId', 'workspace_id'));
        $ws_name   = '';
        $flat      = ($workspace !== '');   // a flat workspaceId is the same kind of id the owner saved — comparable directly
        if ($workspace === '' && isset($payload['workspace']) && is_array($payload['workspace'])) {   // live shape: workspace {id, name}
            $workspace = (string) Fastpix_Sync::field($payload['workspace'], array('id', 'workspaceId'));
            $ws_name   = (string) Fastpix_Sync::field($payload['workspace'], array('name'));
        }
        if ($workspace === '') {
            $workspace = (string) Fastpix_Sync::field($data, array('workspaceId', 'workspace_id'));
        }
        // The saved value is the numeric workspace *key*; deliveries name the
        // workspace by UUID. The first authentic delivery teaches us that UUID
        // (+ name); later ones are foreign when they name a different one.
        $known = (string) get_option(Fastpix_Connection::OPT_WORKSPACE_SEEN_ID, '');
        // Only FastPix's own deliveries teach us the workspace — never the plugin's self-test (it names our saved key).
        $ours_is_uuid  = (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $ours);
        $from_platform = (!$flat && $workspace !== '' && $workspace !== $ours);
        if ($from_platform && $known === '' && (!$ours_is_uuid || $workspace === $ours)
            && Fastpix_Connection::learn_workspace($workspace, false)) {   // refused learns are queued and held/skipped by process()
            $known = $workspace;
        }
        if ($ws_name !== '' && $workspace === $known && $ws_name !== (string) get_option(Fastpix_Connection::OPT_WORKSPACE_SEEN_NAME, '')) {
            update_option(Fastpix_Connection::OPT_WORKSPACE_SEEN_NAME, $ws_name, false);
        }
        // Foreign = names neither the saved key nor the UUID learned for it. Foreign
        // deliveries are acknowledged and stored as skipped — never processed, and
        // no warning is raised.
        // A refused learn is not foreign at receipt: the event is queued and process()
        // holds it until the API names the connected workspace (a rotation or a
        // reconnect keeps its deliveries), or skips it once that workspace is known.
        $foreign = ($workspace !== '' && $workspace !== $ours && ($flat ? $ours !== '' : ($known !== '' && $workspace !== $known)));

        return array($workspace, $foreign);
    }

    /**
     * A queued event whose workspace is stale: while the connected workspace is
     * not named yet (a rotation or a reconnect is settling) hold it for the API
     * sweep to decide, up to MAX_HOLDS; otherwise skip it — never apply. [ASSUME-092]
     */
    public static function hold_or_skip($event, $event_id) {
        global $wpdb;

        $table   = Fastpix_Schema::table('webhook_events');
        $unnamed = (string) get_option(Fastpix_Connection::OPT_WORKSPACE_SEEN_ID, '') === ''
            && (string) get_option(Fastpix_Connection::OPT_PENDING_LEAVE, '') !== Fastpix_Connection::WS_UNKNOWN
            && (int) get_option(Fastpix_Connection::OPT_LEFT_UNKNOWN, 0) !== 1;
        $held = preg_match('/^held:(\d+)/', (string) $event['process_error'], $hm) ? (int) $hm[1] : 0;
        if ($unnamed && $held < Fastpix_Webhooks::MAX_HOLDS) {
            $held++;
            $wpdb->update($table, array('process_error' => 'held:' . $held, 'updated_at' => current_time('mysql', true)), array('id' => $event['id']));
            Fastpix_Jobs::schedule_at(time() + 60 * $held, 'fastpix_process_webhook', array('event_id' => $event_id), Fastpix_Jobs::GROUP_WEBHOOKS);

            return;
        }
        $wpdb->update($table, array(
            'process_state' => 'skipped',
            'process_error' => 'workspace left',
            'updated_at'    => current_time('mysql', true),
        ), array('id' => $event['id']));
    }

    /* ----------------------------------------------------------- handlers */

    /** The media arm of the handler table: tombstone on deleted, else apply + fetch on ready/updated. [WF-009] */
    public static function media($type, $data) {
        $media_id = (string) Fastpix_Sync::field($data, array('id', 'mediaId', 'media_id'));

        if (Fastpix_Webhooks::type_is($type, 'deleted')) {
            Fastpix_Sync::tombstone_media($media_id);   // tombstone + removed-video behaviour

            return;
        }

        Fastpix_Sync::apply_media($data, 'webhook');

        // Fetch on ready & updated: the event names the moment, the
        // record is the truth. [WF-009]
        if ($media_id !== '' && (Fastpix_Webhooks::type_is($type, 'ready') || Fastpix_Webhooks::type_is($type, 'updated'))) {
            Fastpix_Sync::fetch_and_apply($media_id);
            if (Fastpix_Webhooks::type_is($type, 'ready')) {
                do_action('fastpix_media_ready', $media_id);   // AI requests subscribe here — once, on ready; never on a title edit (M16)
            }
        }
    }

    public static function track($data, $type = '') {
        global $wpdb;

        $media_id = (string) Fastpix_Sync::field($data, array('mediaId', 'media_id'));
        $track_id = (string) Fastpix_Sync::field($data, array('id', 'trackId', 'track_id'));
        $video_id = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM ' . Fastpix_Schema::table('videos') . ' WHERE media_id = %s AND deleted_at IS NULL',
            $media_id
        ));
        if (!$video_id || $track_id === '') {
            return;
        }

        // A track deleted on the FastPix dashboard goes here too — this handler used to upsert it
        // back as "generating". (QA report #13)
        if (Fastpix_Webhooks::type_is($type, 'deleted')) {
            $now = current_time('mysql', true);
            $wpdb->update(Fastpix_Schema::table('tracks'), array('deleted_at' => $now, 'updated_at' => $now), array('video_id' => $video_id, 'track_id' => $track_id));
            Fastpix_Cache::flush_group('videos');
            Fastpix_Cache::flush_group('embed');

            return;
        }

        // The event name is the truth for failure/readiness; the payload's own
        // status fills the rest (created → preparing/generating). [ERR-022]
        $state = strtolower((string) Fastpix_Sync::field($data, array('status', 'state')));   // (QA B7)
        if (Fastpix_Webhooks::type_is($type, 'failed') || Fastpix_Webhooks::type_is($type, 'errored')) {
            $state = 'failed';
        } elseif (Fastpix_Webhooks::type_is($type, 'ready') || $state === 'available') {
            $state = 'ready';
        } elseif ($state === '' || $state === 'preparing') {
            $state = 'generating';
        }
        $type_v = (string) Fastpix_Sync::field($data, array('type'));
        if ($type_v === '' && stripos($type, 'subtitle') !== false) {
            $type_v = 'subtitle';
        }

        $now = current_time('mysql', true);
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . Fastpix_Schema::table('tracks') . '
                 (video_id, track_id, type, language_code, source, state, created_at, updated_at)
             VALUES (%d, %s, %s, %s, %s, %s, %s, %s)
             ON DUPLICATE KEY UPDATE state = VALUES(state), language_code = IF(VALUES(language_code) <> \'\' AND SUBSTRING_INDEX(LOWER(VALUES(language_code)), \'-\', 1) <> SUBSTRING_INDEX(LOWER(language_code), \'-\', 1), VALUES(language_code), language_code),
                                     type = IF(VALUES(type) = \'\', type, VALUES(type)),
                                     source = IF(source = \'\', VALUES(source), source), updated_at = VALUES(updated_at)',
            $video_id, $track_id, $type_v,
            (string) Fastpix_Sync::field($data, array('languageCode', 'language_code')),
            (stripos($type, 'generated') !== false || Fastpix_Sync::field($data, array('generated')) ? 'generated' : ''),
            $state, $now, $now
        ));
        if ($state === 'failed') {
            do_action('fastpix_log', 'track_failed', array('severity' => 'warning', 'scope' => 'ai',
                'message' => sprintf('Subtitle track %s did not finish for %s', $track_id, $media_id)));
        }
        Fastpix_Jobs::enqueue('fastpix_search_reindex', array('video_id' => $video_id), Fastpix_Jobs::GROUP_MAINTENANCE);
    }

    /**
     * WF-009 live states: idle / preparing / active / ended.
     * 'disconnected' must precede 'connected' — the longer word contains it.
     */
    private static function stream_status($type) { // NOSONAR php:S100 — WordPress snake_case naming
        foreach (array('disconnected' => 'ended', 'preparing' => 'preparing', 'connected' => 'preparing', 'recording' => 'active',
                     'active' => 'active', 'idle' => 'idle', 'deleted' => 'ended') as $needle => $state) {
            if (stripos($type, $needle) !== false) {
                return $state;
            }
        }

        return 'idle';
    }

    public static function stream($type, $data) {
        global $wpdb;

        // Live is behind fastpix_feature_live. With it off, don't record live
        // stream state into the local table — the admin surfaces that would read
        // it are hidden anyway, and turning the flag on re-syncs from the platform
        // via refresh_from_platform() on the next sweep. [RULE-040]
        if (!Fastpix_Live::enabled()) {
            return;
        }

        // `streamId` first: on the recording events (video.media.live_stream.* / live_clip.*, docs:
        // Media events) `data.id` is the MEDIA id and `data.streamId` the stream — reading `id` first
        // filed the recording's media id as a stream and never linked it to the real one. (QA report #12)
        $stream_id = (string) Fastpix_Sync::field($data, array('streamId', 'stream_id', 'id'));
        if ($stream_id === '') {
            return;
        }
        if (stripos($type, 'video.media.') === 0) {
            // A media event about a stream's recording: it says nothing about the stream's own state.
            $media_id = (string) Fastpix_Sync::field($data, array('mediaId', 'media_id', 'id'));
            if ($media_id !== '' && $media_id !== $stream_id) {
                Fastpix_Sync::apply_media(array('id' => $media_id) + (array) $data, 'webhook');
                Fastpix_Sync::fetch_and_apply($media_id);
                if (stripos($type, 'live_clip') === false) {   // clips file as library videos but never replace the recording link
                    Fastpix_Live::link_recording($stream_id, $media_id);
                } else {
                    $wpdb->query($wpdb->prepare('UPDATE ' . Fastpix_Schema::table('videos') . " SET source = 'Live' WHERE media_id = %s AND source = 'Dashboard'", $media_id));
                }
            }

            return;
        }

        $status = self::stream_status($type);

        // Only a broadcast event (preparing/active/ended) stamps last_active_at —
        // created/updated/idle must not make a never-streamed row read "ended";
        // an idle after a broadcast maps like the list refresh does. (QA X9)
        $now = current_time('mysql', true);
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . Fastpix_Schema::table('live_streams') . '
                 (stream_id, workspace_id, status, last_active_at, created_at, updated_at)
             VALUES (%s, %s, %s, NULLIF(%s, \'\'), %s, %s)
             ON DUPLICATE KEY UPDATE workspace_id = IF(workspace_id LIKE \'prev:%%\', workspace_id, VALUES(workspace_id)), status = ' . Fastpix_Live::status_sql() . ', last_active_at = COALESCE(VALUES(last_active_at), last_active_at), updated_at = VALUES(updated_at)',
            $stream_id, (string) get_option(Fastpix_Connection::OPT_WORKSPACE_ID, ''), $status, $status === 'idle' ? '' : $now, $now, $now
        ));

        // Recording hand-off: the stream's media id files an ordinary library
        // video, marked source "Live" (REQ-072). Clips file likewise but never
        // replace the stream's recording link.
        $media_id = (string) Fastpix_Sync::field($data, array('mediaId', 'media_id'));
        if ($media_id !== '') {
            Fastpix_Sync::fetch_and_apply($media_id);
            $wpdb->query($wpdb->prepare(
                'UPDATE ' . Fastpix_Schema::table('videos') . " SET source = 'Live' WHERE media_id = %s AND source = 'Dashboard'",
                $media_id
            ));
            if (stripos($type, 'live_clip') === false) {
                Fastpix_Live::link_recording($stream_id, $media_id);
            }
        }
    }

    /* -------------------------------------------- settings self-test */

    /**
     * Settings "Send test event": sign a small payload with the stored secret and
     * POST it to this site's own receiver URL — proves the URL is reachable end to
     * end through the same path FastPix uses. It cannot prove the secret (see
     * verdict()); the caller nudges FastPix for a real delivery to settle that.
     */
    public static function send_test_event() {   // NOSONAR php:S100 — WordPress snake_case naming
        if (!Fastpix_Webhooks::configured()) {
            return new \WP_Error('fastpix_webhook_unconfigured', __('Save the signing secret first.', 'fastpix-io'), array('status' => 409));
        }
        $payload = wp_json_encode(array('id' => 'test-' . wp_generate_password(8, false, false), 'type' => 'fastpix.plugin.test', 'workspaceId' => (string) get_option(Fastpix_Connection::OPT_WORKSPACE_ID, ''), 'data' => array('sentAt' => gmdate('c'))));
        $key = base64_decode(Fastpix_Webhooks::secret(), true) ?: Fastpix_Webhooks::secret();
        $res = wp_remote_post(rest_url(Fastpix_Rest::NS . Fastpix_Webhooks::ROUTE), array(
            'sslverify' => apply_filters('https_local_ssl_verify', false),   // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core filter; a self-signed local cert must not fail the self-test [QA S15]
            'timeout' => 15, 'headers' => array('Content-Type' => 'application/json', 'FastPix-Signature' => base64_encode(hash_hmac('sha256', $payload, $key, true))), 'body' => $payload,
        ));
        if (is_wp_error($res)) {
            /* translators: %s: transport error message */
            return new \WP_Error('fastpix_test_unreachable', sprintf(__('This site could not reach its own webhook URL: %s', 'fastpix-io'), $res->get_error_message()), array('status' => 502));
        }
        $code = (int) wp_remote_retrieve_response_code($res);

        return array('delivered' => $code >= 200 && $code < 300, 'status' => $code, 'url' => rest_url(Fastpix_Rest::NS . Fastpix_Webhooks::ROUTE), 'last_event' => Fastpix_Webhooks::last_event(), 'last_delivery' => Fastpix_Webhooks::last_delivery(), 'verdict' => Fastpix_Webhooks::verdict());
    }

    /**
     * Ask FastPix for a real delivery: a no-op update (the title written back
     * unchanged) on one media of the workspace makes the platform emit
     * video.media.updated, which names the workspace.
     * @return bool whether a nudge was sent (false: no media yet / API down).
     */
    public static function nudge_platform() {   // NOSONAR php:S100 — WordPress snake_case naming
        return self::nudge_platform_result()['sent'];
    }

    /** nudge_platform() with the reason it could not be sent, for the screens to show. */
    public static function nudge_platform_result() {   // NOSONAR php:S100 — WordPress snake_case naming
        $client = new Fastpix_Api_Client();
        $list   = $client->request('GET', '/on-demand?limit=1');
        if (is_wp_error($list)) {
            return array('sent' => false, 'reason' => $list->get_error_message());
        }
        if (empty($list['body']['data'][0]['id'])) {
            return array('sent' => false, 'reason' => __('the workspace has no video yet', 'fastpix-io'));
        }
        $media = $list['body']['data'][0];
        $res   = $client->request('PATCH', '/on-demand/' . rawurlencode((string) $media['id']), array('body' => array('title' => (string) (isset($media['title']) ? $media['title'] : ''))));

        return is_wp_error($res) ? array('sent' => false, 'reason' => $res->get_error_message()) : array('sent' => true, 'reason' => '');
    }
}
