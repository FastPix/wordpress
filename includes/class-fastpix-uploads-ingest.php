<?php
/**
 * Upload engine — URL ingestion, the upload webhook
 * binding and the domain lock, split from Fastpix_Uploads to keep each class
 * within the 20-method budget. Same behaviour; the spec IDs stay with the
 * code they govern (WF-002/003, SEC-010, RULE-006, REQ-015, AMBIG-009).
 */

namespace Fastpix;

if (!defined('WPINC')) {
    die;
}

require_once __DIR__ . '/class-fastpix-url-guard.php';

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery
// All SQL here runs against the plugin's own tables: identifiers come from the
// Fastpix_Schema::table() registry (a fixed whitelist, never request input) and
// every value travels through $wpdb->prepare(). Core APIs cannot query these tables.

class Fastpix_Uploads_Ingest {

    /** Platform media path prefix (PATCH targets). */
    const API_ON_DEMAND = '/on-demand/';

    /* ----------------------------------------------------- URL ingestion */

    /**
     * [WF-003] One verdict per URL, never one for the batch. Validation happens
     * before any transfer and costs nothing.
     */
    public static function ingest_urls($request) {
        $refusal = Fastpix_Uploads::refuse_if_unavailable();   // RULE-005
        if ($refusal) {
            return $refusal;
        }

        $urls = array_slice(array_filter(array_map('trim', (array) $request->get_param('urls'))), 0, Fastpix_Uploads::MAX_FILES_PER_SUBMISSION);
        $settings = Fastpix_Uploads_Settings::settings_snapshot($request->get_param('settings'));
        $media_settings = Fastpix_Uploads::platform_media_settings($settings);
        if (is_wp_error($media_settings)) {
            return $media_settings;   // one reason for the batch: the DRM id is a site setting, not a per-URL fact
        }
        $client   = new Fastpix_Api_Client();
        $verdicts = array();
        $many     = count($urls) > 1;

        foreach ($urls as $url) {
            $verdicts[] = self::ingest_one($client, $url, $settings, $media_settings, $many);
        }

        return rest_ensure_response(array('verdicts' => $verdicts));
    }

    /** One URL's verdict: the SSRF gate, then the create call. */
    private static function ingest_one($client, $url, $settings, $media_settings, $many) { // NOSONAR php:S100 — WordPress snake_case naming
        $check = Fastpix_Url_Guard::validate_public_video_url($url);

        if (is_wp_error($check)) {
            return array('url' => $url, 'accepted' => false, 'reason' => $check->get_error_message());   // nothing transferred, nothing charged [WF-003]
        }

        $title = self::per_url_title($settings['title'], $url, $many);

        // POST /on-demand body shape follows the public FastPix API
        // (inputs[] + accessPolicy); idempotency key from the URL hash
        // so a retried submission cannot create a second copy.
        $result = $client->request('POST', '/on-demand', array(
            // 32 hex chars keep 'ingest:…' inside the varchar(64) idempotency column
            'idempotency_row_id' => 'ingest:' . substr(hash('sha256', $url), 0, 32),
            'body'               => array_merge(
                $media_settings,
                // The video input first, then whatever the settings carry (a watermark, or
                // nothing). Merging the other way round dropped the video: both live in `inputs`.
                array('inputs' => array_merge(
                    array(array('type' => 'video', 'url' => $url)),
                    isset($media_settings['inputs']) ? $media_settings['inputs'] : array()
                )),
                // Top-level `title` is the media's dashboard name (verified live
                // 2026-08-31: metadata.* is a free-form map the dashboard ignores).
                $title !== '' ? array('title' => $title) : array()
            ),
        ));

        if (is_wp_error($result)) {
            return array('url' => $url, 'accepted' => false, 'reason' => $result->get_error_message());
        }

        $body  = is_array($result['body']) ? $result['body'] : array();
        $media = isset($body['data']) && is_array($body['data']) ? $body['data'] : $body;
        $media['_fastpix_source'] = 'URL';
        Fastpix_Sync::apply_media($media);
        Fastpix_Ai::park_settings((string) Fastpix_Sync::field($media, array('id', 'mediaId', 'media_id')), $settings);   // read back on ready [WF-005]

        return array(
            'url'      => $url,
            'accepted' => true,
            'media_id' => (string) Fastpix_Sync::field($media, array('id', 'mediaId', 'media_id')),
            'title'    => $title,   // the row is named as the media is (QA F4)
        );
    }

    /** One title on several links would name every media alike: "Title — file" per link, as Add media does for files. (QA U8) */
    private static function per_url_title($title, $url, $many) {
        if ($title !== '' && $many) {
            $stem  = pathinfo((string) wp_parse_url($url, PHP_URL_PATH), PATHINFO_FILENAME);
            $title = mb_substr($title . ' — ' . ($stem !== '' ? $stem : (string) wp_parse_url($url, PHP_URL_HOST)), 0, 255);
        }

        return $title;
    }

    /* ------------------------------------------------- webhook binding */

    /**
     * [WF-002 step 4] video.upload.media_created binds upload → media, applies
     * the stored settings, creates the proxy attachment.
     */
    public static function on_upload_event($type, $data) {
        global $wpdb;

        $upload_id = (string) Fastpix_Sync::field($data, array('uploadId', 'upload_id', 'id'));
        $media_id  = (string) Fastpix_Sync::field($data, array('mediaId', 'media_id'));
        if ($upload_id === '') {
            return;
        }

        $table = Fastpix_Schema::table('uploads');
        $row   = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE upload_id = %s", $upload_id), ARRAY_A);
        if (!$row) {
            return;
        }

        $now = current_time('mysql', true);

        if (stripos($type, 'cancelled') !== false) {
            $wpdb->update($table, array('state' => 'cancelled', 'updated_at' => $now), array('id' => $row['id']));

            return;
        }

        if ($media_id !== '' && stripos($type, 'media_created') !== false) {
            self::bind_media($row, $media_id, $now);
        }

        if (stripos($type, 'ready') !== false) {
            $wpdb->update($table, array('state' => 'completed', 'bytes_sent' => (int) $row['filesize'], 'updated_at' => $now), array('id' => $row['id']));
        }
    }

    /**
     * Completed sessions the webhook never bound (no webhooks reach this site,
     * or the event was missed). Verified live 2026-09-08: a direct upload's
     * media carries the SAME id as its upload session, so the media is fetched
     * by that id when it is not known locally yet (which also starts the poll
     * chain in polling mode) and bound exactly as video.upload.media_created
     * would. Bounded: one user's rows from the last 24 h, at most one platform
     * GET per row until its media is known. [ASSUME-075]
     * $user_id 0 = the migration's server-side pushes (a background job has no user): no screen retries
     * those, so they have no 24 h limit — a batch left alone for days still gets its items linked. [QA F5]
     */
    public static function bind_unbound($user_id) {
        global $wpdb;

        $uploads = Fastpix_Schema::table('uploads');
        $videos  = Fastpix_Schema::table('videos');
        // Also rows the browser gave up on with every byte sent: verified 2026-09-20 that the
        // bucket can store the PUT while answering without CORS headers (and refuse the
        // resumable POST outright), so the SDK reports failure for a file FastPix already has.
        // The platform is the judge: a media for that upload id means the upload completed. [QA F1/F3]
        $rows    = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$uploads} WHERE user_id = %d AND upload_id <> '' AND upload_id NOT LIKE %s AND updated_at > %s
               AND ((state = 'completed' AND video_id IS NULL) OR (state IN ('paused', 'uploading') AND bytes_sent >= filesize AND filesize > 0)) LIMIT 50",
            $user_id, $wpdb->esc_like('pending:') . '%', $user_id ? gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS) : '1970-01-01 00:00:00'
        ), ARRAY_A);

        foreach ((array) $rows as $row) {
            $known = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$videos} WHERE media_id = %s", $row['upload_id']));
            if (!$known) {
                Fastpix_Sync::fetch_and_apply($row['upload_id']);   // not there yet / unreachable → try again on the next call
                $known = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$videos} WHERE media_id = %s", $row['upload_id']));
            }
            if ($known && $row['state'] !== 'completed') {
                $wpdb->update($uploads, array('state' => 'completed', 'updated_at' => current_time('mysql', true)), array('id' => (int) $row['id']));
                $row['state'] = 'completed';
            }
            if (empty($row['video_id'])) {   // the media_created webhook may have bound it already
                self::bind_media($row, $row['upload_id'], current_time('mysql', true));   // no-op until the video row exists
            }
        }
    }

    /**
     * A re-created platform session hands back a NEW upload id (verified live 2026-09-18);
     * the placeholder video row keyed by the old id follows it — unless the new id already
     * has a row — or the media_created webhook finds no upload to bind. [WF-002]
     */
    public static function rekey_upload($old_id, $new_id) {
        global $wpdb;

        $videos = Fastpix_Schema::table('videos');
        if ($old_id !== '' && !$wpdb->get_var($wpdb->prepare("SELECT id FROM {$videos} WHERE media_id = %s", $new_id))) {
            $wpdb->update($videos, array('media_id' => $new_id), array('media_id' => $old_id));
        }
    }

    /** The media_created half: local video row updated from the batch snapshot, upload row bound, proxy created. */
    private static function bind_media($row, $media_id, $now) {
        global $wpdb;

        $video_id = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM ' . Fastpix_Schema::table('videos') . ' WHERE media_id = %s', $media_id
        ));
        if (!$video_id) {
            return;
        }

        $settings  = json_decode((string) $row['settings_json'], true);
        $migrated  = is_array($settings) && !empty($settings['_migration_attachment']);   // pushed by WF-004
        $wpdb->update(Fastpix_Schema::table('videos'), array_filter(array(
            'source'        => $migrated ? 'Migrated' : 'Upload',
            'author_id'     => (int) $row['user_id'],
            'title'         => (is_array($settings) && !empty($settings['title'])) ? $settings['title'] : pathinfo((string) $row['filename'], PATHINFO_FILENAME),
            'access_policy' => isset($settings['access_policy']) ? $settings['access_policy'] : null,
            'quality_tier'  => isset($settings['quality_tier']) ? $settings['quality_tier'] : null,
            'attachment_id' => $migrated ? (int) $settings['_migration_attachment'] : null,   // origin pointer [REQ-024]
            'updated_at'    => $now,
        )), array('id' => $video_id));

        $wpdb->update(Fastpix_Schema::table('uploads'), array('video_id' => $video_id, 'updated_at' => $now), array('id' => $row['id']));
        if ($migrated) {
            Fastpix_Migration::bind_pushed_upload((string) $row['upload_id'], $video_id);   // the item learns its video; the file already IS in the Media Library
        } else {
            Fastpix_Attachments::create_proxy($video_id);   // REQ-035
            self::push_title($media_id, $settings);
        }
        // AI-related settings (subtitles/chapters/summary/moderation)
        // from the snapshot are applied on ready by the AI path (API-F05),
        // not here.
    }

    /**
     * Stamp the batch title on the platform media so the FastPix
     * dashboard shows the same name. A direct-upload media does not
     * exist until the file lands, so the title is set now (the
     * upload session can't carry it). Field verified live 2026-08-31:
     * PATCH /on-demand/{id} {title} — top-level, not metadata.*.
     */
    public static function push_title($media_id, $settings) {
        if (is_array($settings) && !empty($settings['title'])) {
            $client = new Fastpix_Api_Client();
            $pushed = $client->request('PATCH', self::API_ON_DEMAND . rawurlencode($media_id), array('body' => array('title' => $settings['title'])));
            if (is_wp_error($pushed)) {   // unreachable → queue like the edit path [WF-015]
                Fastpix_Outbox::queue('PATCH', self::API_ON_DEMAND . rawurlencode($media_id), array('title' => $settings['title']), 'title of ' . $media_id);
            }
        }
    }

    /* -------------------------------------------- domain lock (AMBIG-009) */

    /**
     * The restriction this media's batch asked for, or null when it must not
     * be touched: no stored batch snapshot (dashboard/sweep-discovered media),
     * or the "Play only on this site" box was unticked.
     */
    public static function domain_lock_target($video) {
        $target = null;
        $raw    = Fastpix_Ai::batch_settings_raw($video);
        if (is_array($raw)) {
            $settings = Fastpix_Uploads_Settings::settings_snapshot($raw);
            if (!empty($settings['domain_lock']) && $settings['domain_policy'] === 'allow') {
                // Blacklist: everything plays except the listed hosts. Nothing listed
                // means nothing to lock, so the media is left untouched. [ASSUME-073]
                if ($settings['domain_deny']) {
                    $target = array('defaultPolicy' => 'allow', 'allow' => array(), 'deny' => $settings['domain_deny']);
                }
            } elseif (!empty($settings['domain_lock'])) {
                // Whitelist: this site plus any extra hosts from the modal. The filter is
                // the calibration knob: dev sites can test with a real domain (the
                // platform refuses localhost), production can add www/apex twins.
                $hosts = array_filter((array) apply_filters('fastpix_domain_lock_host', (string) wp_parse_url(home_url(), PHP_URL_HOST)));
                if ($hosts) {
                    $allow  = array_values(array_unique(array_merge(array_values($hosts), $settings['domain_allow'])));
                    $target = array('defaultPolicy' => 'deny', 'allow' => $allow, 'deny' => array());
                }
            }
        }

        return $target;
    }

    /** PATCH …/playback-ids/{id}/domains for every live playback id. Live-verified
     *  (ASSUME-051): the body is FLAT {defaultPolicy, allow, deny} — wrapping it
     *  in {domains:…} returns 200 but stores NOTHING; only 'available' playback
     *  ids accept it; dot-less hosts (localhost) are refused with 422. */
    public static function domain_lock_job($args) {
        global $wpdb;

        $media_id = isset($args['media_id']) ? (string) $args['media_id'] : '';
        $attempt  = isset($args['attempt']) ? max(1, (int) $args['attempt']) : 1;
        if ($media_id === '') {
            return;
        }

        $video = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . Fastpix_Schema::table('videos') . ' WHERE media_id = %s AND deleted_at IS NULL',
            $media_id
        ), ARRAY_A);
        if (!$video) {
            return;
        }

        $target = self::domain_lock_target($video);
        if ($target === null) {
            return;
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . Fastpix_Schema::table('playback_ids') . ' WHERE video_id = %d AND deleted_at IS NULL',
            (int) $video['id']
        ), ARRAY_A);

        $client = new Fastpix_Api_Client();
        $retry  = false;

        foreach ($rows as $row) {
            if (self::apply_domain_lock($client, $media_id, $row, $target)) {
                $retry = true;
            }
        }

        if ($retry && $attempt < Fastpix_Uploads::MAX_DOMAIN_LOCK_ATTEMPTS) {
            Fastpix_Jobs::schedule_at(time() + Fastpix_Uploads::DOMAIN_LOCK_SPACING * $attempt, Fastpix_Uploads::HOOK_DOMAIN_LOCK,
                array('media_id' => $media_id, 'attempt' => $attempt + 1), Fastpix_Jobs::GROUP_SYNC);
        } elseif ($retry) {
            do_action('fastpix_log', 'domain_lock_failed', array(
                'severity' => 'error', 'scope' => 'sync',
                'message'  => sprintf('Domain lock could not be applied to %s after %d attempts — the video still plays on other sites.', $media_id, $attempt),
            ));
        }
    }

    /** PATCH one playback id's domains; true when the row still needs a retry. */
    private static function apply_domain_lock($client, $media_id, $row, $target) {
        global $wpdb;

        $stored = $row['domain_restrictions'] ? json_decode($row['domain_restrictions'], true) : null;
        if ($stored == $target) {
            return false;   // media_ready re-fires on every 'updated' event; already locked is done
        }

        $result = $client->request('PATCH',
            self::API_ON_DEMAND . rawurlencode($media_id) . '/playback-ids/' . rawurlencode($row['playback_id']) . '/domains',
            array('context' => 'background', 'body' => $target)
        );

        if (is_wp_error($result)) {
            return self::domain_lock_error($media_id, $target, $result);
        }

        $wpdb->update(Fastpix_Schema::table('playback_ids'),
            array('domain_restrictions' => wp_json_encode($target), 'updated_at' => current_time('mysql', true)),
            array('id' => (int) $row['id'])
        );

        return false;
    }

    /** A failed domains PATCH: false when permanent (422, logged once a day), true when worth retrying. */
    private static function domain_lock_error($media_id, $target, $result) {
        $data = $result->get_error_data();
        if (is_array($data) && isset($data['status']) && (int) $data['status'] === 422) {
            // Permanent: the platform refuses the domain itself (a
            // dot-less host like localhost). Retrying cannot help, and
            // media_ready re-fires per webhook — log once a day, not per event.
            if (!get_transient('fastpix_domain_lock_rejected_' . hash('sha256', $media_id))) {
                set_transient('fastpix_domain_lock_rejected_' . hash('sha256', $media_id), 1, DAY_IN_SECONDS);
                do_action('fastpix_log', 'domain_lock_rejected', array(
                    'severity' => 'error', 'scope' => 'sync',
                    'message'  => sprintf('FastPix rejected the domain lock for %s (host "%s") — the video still plays on other sites. Dev hosts without a dot cannot be locked; the fastpix_domain_lock_host filter can substitute a real domain.', $media_id, implode(', ', $target['allow'])),
                ));
            }

            return false;
        }

        return true;   // usually the playback id still 'preparing' right after ready
    }
}
