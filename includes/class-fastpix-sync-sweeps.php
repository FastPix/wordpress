<?php
/**
 * Sweeps, poll fallback, audit and usage reconciliation — the job half of
 * Fastpix_Sync (WF-009, FR-101, DATA-008/010).
 *
 * Split out of class-fastpix-sync.php for size only; budgets, resume
 * semantics and hook names are unchanged. Entry points are reached through
 * the Fastpix_Sync delegates and the hooks Fastpix_Sync::boot() registers.
 */

namespace Fastpix;

if (!defined('WPINC')) {
    die;
}

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery
// All SQL here runs against the plugin's own tables: identifiers come from the
// Fastpix_Schema::table() registry (a fixed whitelist, never request input) and
// every value travels through $wpdb->prepare(). Core APIs cannot query these tables.

class Fastpix_Sync_Sweeps {

    private const WHERE_MEDIA = ' WHERE media_id = %s';
    const DEEP_HANDOFF = 'fastpix_deep_sweep_handoff';   // set while a hand-off chain is walking (M19)

    /* ---------------------------------------------------------------------
     * Sweeps — FR-101
     * ------------------------------------------------------------------ */

    public static function on_connected() {
        self::seed_sync_state('new_media');
        self::seed_sync_state('deep_sweep');
        Fastpix_Jobs::enqueue('fastpix_new_media_sweep', array(), Fastpix_Jobs::GROUP_SYNC);   // immediate first sweep on connect
    }

    /**
     * Creation-ordered walk that stops at the first fully known page — it
     * catches media created outside WordPress and cannot catch edits (the deep
     * sweep's job). [WF-009]
     */
    public static function new_media_sweep() {
        if (!Fastpix_Credentials::has_pair()) {
            return;
        }

        $client  = new Fastpix_Api_Client();
        $created = 0;
        $started = microtime(true);
        $token   = Fastpix_Credentials::token_id();

        $client->paginate(Fastpix_Sync::EP_ON_DEMAND, array('context' => 'background', 'limit' => Fastpix_Api_Client::PAGE_MAX), function ($page) use (&$created, $started, $token) {
            $known_whole_page = true;
            if (self::pair_changed($token)) {
                return false;   // this page was read with a pair that is no longer the site's [ASSUME-092]
            }

            foreach ($page as $media) {
                $verdict = Fastpix_Sync::apply_media($media);
                if ($verdict === 'created') {
                    $created++;
                    $known_whole_page = false;
                } elseif ($verdict === 'updated') {
                    $known_whole_page = false;
                }
            }

            if ($known_whole_page && count($page) > 0) {
                return false;   // first fully known page — stop [WF-009]
            }

            return (microtime(true) - $started) < Fastpix_Sync::ACTION_BUDGET;   // [ARCH-07]
        });

        self::record_sweep('new_media', $created);
        // Migration pushes are linked by video.upload.media_created; without webhooks this sweep is what finds
        // their media, so link them here too, or the batch never verifies and cleanup is never offered. [QA F5]
        Fastpix_Uploads_Ingest::bind_unbound(0);
    }

    /**
     * The nightly deep sweep: pages EVERY media including terminal rows,
     * compares platform updatedAt with the stored platform_updated_at, and
     * re-applies any difference. Budget 2,000 calls / 90 minutes; the offset
     * lives in fastpix_sync_state so a huge library finishes over several
     * nights; one action per page-batch. [WF-009]
     */
    public static function deep_sweep($args = array()) {
        if (!Fastpix_Credentials::has_pair()) {
            return;
        }

        // M19: the nightly trigger (no args) yields to a hand-off chain still walking.
        $args = is_array($args) ? array_diff_key($args, array('_fastpix_correlation_id' => 1)) : array();
        if (!$args && get_transient(self::DEEP_HANDOFF)) {
            return;
        }

        $state = self::sync_state('deep_sweep');
        // `offset` is FastPix's 1-based page number; the stored watermark is the page to resume from.
        $walk = wp_parse_args((array) $args, array(
            'offset' => (int) $state['watermark_id'], 'calls' => 0, 'begun' => time(), 'seen' => 0, 'changed' => 0,
        ));
        $walk = array_map('intval', array_intersect_key($walk, array_flip(array('offset', 'calls', 'begun', 'seen', 'changed'))));
        $walk['offset'] = max(1, $walk['offset']);
        $token   = Fastpix_Credentials::token_id();
        $outcome = self::deep_walk($walk);

        if (self::pair_changed($token)) {
            return;   // the walk belonged to the previous pair: neither its cursor, its continuation nor its completion counts [ASSUME-092]
        }
        if ($outcome === 'handoff') {
            // Action budget spent: hand the remainder to the next action.
            Fastpix_Jobs::enqueue('fastpix_deep_sweep', $walk, Fastpix_Jobs::GROUP_SYNC);
            self::store_sweep_offset('deep_sweep', $walk['offset']);
            set_transient(self::DEEP_HANDOFF, time(), Fastpix_Sync::SWEEP_MAX_SECONDS);   // ponytail: expires with the nightly budget if the chain dies
        } elseif ($outcome === 'done') {
            delete_transient(self::DEEP_HANDOFF);
            self::record_sweep('deep_sweep', $walk['changed'], $walk['seen']);
        } else {
            delete_transient(self::DEEP_HANDOFF);
            self::store_sweep_offset('deep_sweep', $walk['offset']);   // nightly budget spent or the client refused; tomorrow resumes from the offset [WF-009]
        }
    }

    /**
     * Page through the library until a budget runs out; $walk is advanced in place.
     *
     * @return string 'done' (last page seen) | 'handoff' (action budget spent) | 'stop' (nightly budget spent, or a request error)
     */
    private static function deep_walk(&$walk) {
        $client  = new Fastpix_Api_Client();
        $started = microtime(true);
        $outcome = 'stop';

        while ($walk['calls'] < Fastpix_Sync::SWEEP_MAX_CALLS && (time() - $walk['begun']) < Fastpix_Sync::SWEEP_MAX_SECONDS) {
            if ((microtime(true) - $started) >= Fastpix_Sync::ACTION_BUDGET) {
                $outcome = 'handoff';
                break;
            }

            $page = self::list_page($client, $walk['offset']);
            $walk['calls']++;

            if ($page === null) {
                break;   // breaker/429 handling lives in the client; resume next run
            }

            $walk['seen']    += count($page);
            $walk['changed'] += self::deep_page($page);

            if (count($page) < Fastpix_Api_Client::PAGE_MAX) {
                $outcome = 'done';
                break;
            }

            $walk['offset']++;   // next page
        }

        return $outcome;
    }

    /** One deep-sweep page: re-apply every record whose updatedAt differs. */
    private static function deep_page($page) {
        global $wpdb;

        $changed = 0;
        foreach ($page as $media) {
            $media_id = Fastpix_Sync::field($media, array('id', 'mediaId', 'media_id'));
            $remote   = Fastpix_Sync_Apply::datetime(Fastpix_Sync::field($media, array('updatedAt', 'updated_at')));
            $row      = $wpdb->get_row($wpdb->prepare(
                'SELECT platform_updated_at, workspace_id FROM ' . Fastpix_Schema::table('videos') . self::WHERE_MEDIA,
                (string) $media_id
            ), ARRAY_A);
            $local    = $row ? $row['platform_updated_at'] : null;
            // A row stamped for another pair (or the sentinel) is re-applied even when
            // unchanged, so the connected workspace's stamp lands on it. [ASSUME-092]
            $restamp  = $row && $row['workspace_id'] !== (string) get_option(Fastpix_Connection::OPT_WORKSPACE_SEEN_ID, '');

            // Any difference pulls the full record and re-applies it. [WF-009]
            if (($local === null || $remote === null || $remote !== $local || $restamp)
                && Fastpix_Sync::apply_media($media) !== 'unchanged') {
                $changed++;
            }
        }

        return $changed;
    }

    /** True once the stored pair differs from the one a walk started with — its pages must not be applied. */
    private static function pair_changed($token) {
        return Fastpix_Credentials::token_id_fresh() !== $token;
    }

    /** One list page from the platform, or null on a request error — or null when the pair changed while it was in flight. */
    private static function list_page($client, $offset) {
        $token  = Fastpix_Credentials::token_id();
        $result = $client->request('GET', Fastpix_Sync::EP_ON_DEMAND, array(
            'context' => 'background',
            'query'   => array('limit' => Fastpix_Api_Client::PAGE_MAX, 'offset' => $offset),
        ));

        if (is_wp_error($result) || self::pair_changed($token)) {
            return null;
        }

        $body = is_array($result['body']) ? $result['body'] : array();

        return isset($body['data']) && is_array($body['data']) ? $body['data'] : array();
    }

    /**
     * Poll fallback for one non-terminal video: 1→15 min backoff, stalled at
     * 24 h. Runs regardless of webhooks; it is the answer when they are not
     * configured or one goes missing. [FR-101]
     */
    public static function poll_media($args = array()) {
        $media_id = isset($args['media_id']) ? (string) $args['media_id'] : '';
        if ($media_id === '' || !Fastpix_Credentials::has_pair()) {
            return;
        }

        $attempt = isset($args['attempt']) ? (int) $args['attempt'] : 0;
        $first   = isset($args['first']) ? (int) $args['first'] : time();

        global $wpdb;
        // A previous workspace's media cannot be polled with the connected pair: stop, and never mark it stalled. [ASSUME-092]
        $status = '';
        if (!Fastpix_Sync::other_workspace_row($media_id)) {
            Fastpix_Sync::fetch_and_apply($media_id);
            $status = (string) $wpdb->get_var($wpdb->prepare(
                'SELECT status FROM ' . Fastpix_Schema::table('videos') . self::WHERE_MEDIA . ' AND deleted_at IS NULL',
                $media_id
            ));
        }

        if ($status === '' || Fastpix_Sync::rank($status) >= 2) {
            Fastpix_Cache::delete('poll', $media_id);

            return;   // out of reach, terminal or gone — polling is done
        }

        if ((time() - $first) >= Fastpix_Sync::POLL_GIVE_UP) {
            Fastpix_Cache::delete('poll', $media_id);
            // Stalled: surfaced, not abandoned silently. [FR-101]
            $wpdb->query($wpdb->prepare(
                Fastpix_Sync::SQL_UPDATE . Fastpix_Schema::table('videos') . " SET error_code = 'stalled', updated_at = %s WHERE media_id = %s",
                current_time('mysql', true), $media_id
            ));
            do_action('fastpix_log', 'media_poll_stalled', array(
                'scope' => 'sync', 'media_id' => $media_id,
                'message' => 'Still not terminal after 24 hours of polling.',
            ));

            return;
        }

        $delay = Fastpix_Sync::POLL_BACKOFFS[min($attempt, count(Fastpix_Sync::POLL_BACKOFFS) - 1)];
        Fastpix_Jobs::schedule_at(time() + $delay, 'fastpix_poll_media', array(
            'media_id' => $media_id, 'attempt' => $attempt + 1, 'first' => $first,
        ), Fastpix_Jobs::GROUP_SYNC);
    }

    /**
     * Weekly consistency audit: the deep sweep's diff plus the orphan check —
     * local rows whose media the platform no longer has. REPORTS what changed
     * rather than changing silently. [WF-009, RULE-021]
     *
     * Walks the remote library one page at a time, holding only the id STRINGS
     * (not the media objects) so memory stays flat on a large workspace, and
     * stops at the same per-action budget every other list-walking job honours.
     * ponytail: no cross-action resume here — the audit is a weekly safety net
     * on top of the nightly resumable deep sweep. A library too large to walk
     * in one action still gets each page it reaches applied; only the orphan
     * pass, which needs the COMPLETE set, is skipped that cycle (and said so).
     */
    public static function audit() {
        if (!Fastpix_Credentials::has_pair()) {
            return;
        }

        $client     = new Fastpix_Api_Client();
        $started    = microtime(true);
        $offset     = 1;
        $calls      = 0;
        $seen       = 0;
        $changed    = 0;
        $remote_ids = array();
        $completed  = false;

        while (true) {
            if ($calls >= Fastpix_Sync::SWEEP_MAX_CALLS || (microtime(true) - $started) >= Fastpix_Sync::ACTION_BUDGET) {
                break;   // budget spent; the walk is incomplete this cycle
            }

            $page = self::list_page($client, $offset);
            $calls++;

            if ($page === null) {
                break;   // breaker/429 handled in the client; the report notes the incomplete walk
            }

            $seen    += count($page);
            $changed += self::audit_page($page, $remote_ids);

            if (count($page) < Fastpix_Api_Client::PAGE_MAX) {
                $completed = true;
                break;
            }
            $offset++;
        }

        $orphaned = $completed ? self::audit_orphans($remote_ids) : 0;

        // The audit's defining behaviour: it reports. [WF-009]
        do_action('fastpix_log', 'consistency_audit_complete', array(
            'severity' => 'info', 'scope' => 'sync',
            'message'  => $completed
                ? sprintf('Audit: %d records compared, %d re-applied, %d orphans surfaced.', $seen, $changed, $orphaned)
                : sprintf('Audit: walked %d records (%d re-applied) before the %ds budget; orphan surfacing skipped this cycle.', $seen, $changed, (int) Fastpix_Sync::ACTION_BUDGET),
        ));
        self::record_sweep('audit', $changed);
    }

    /** One audit page: collect remote ids, re-apply what differs. */
    private static function audit_page($page, &$remote_ids) {
        $changed = 0;
        foreach ($page as $media) {
            $remote_ids[(string) Fastpix_Sync::field($media, array('id', 'mediaId', 'media_id'))] = true;
            if (!in_array(Fastpix_Sync::apply_media($media), array('unchanged', 'blocked', 'skipped'), true)) {
                $changed++;
            }
        }

        return $changed;
    }

    /**
     * Orphan surfacing needs the COMPLETE remote set — a local row is an orphan
     * only if NO page contained it — so it runs only after a full walk, never on
     * a truncated one where it would mislabel live videos. Only rows of the
     * CONNECTED workspace can be judged against this list; a previously connected
     * workspace's rows are elsewhere, not orphans.
     */
    private static function audit_orphans($remote_ids) {
        global $wpdb;

        $orphaned = 0;
        // Always scoped: with the UUID still unlearned only unstamped rows are judged,
        // never a previous workspace's library. [ASSUME-092]
        $ws_uuid  = (string) get_option(Fastpix_Connection::OPT_WORKSPACE_SEEN_ID, '');
        $local    = $wpdb->get_col($wpdb->prepare('SELECT media_id FROM ' . Fastpix_Schema::table('videos') . " WHERE deleted_at IS NULL AND (workspace_id = %s OR workspace_id = '')", $ws_uuid));
        foreach ($local as $media_id) {
            if (!isset($remote_ids[$media_id])) {
                Fastpix_Sync::mark_orphaned($media_id);
                $orphaned++;
            }
        }

        return $orphaned;
    }

    /**
     * Nightly usage sweep — DATA-008, REQ-037. Scans published post content
     * for fastpix shortcodes ([fastpix id="…"]) and blocks (wp:fastpix/video
     * with a mediaId attribute) and reconciles fastpix_usage. No-op-safe: with
     * no embeds it finds nothing and clears stale rows.
     */
    public static function usage_sweep($args = array()) {
        global $wpdb;

        if (!Fastpix_Schema::table_exists('usage')) {
            return 0;
        }

        // M13: resumable — posts are walked by id in pages under the action budget;
        // a spent budget hands the cursor to the next action. Every row a walk
        // touches is stamped last_seen_at >= begun, so the prune at the end can
        // drop what no post references any more without holding the whole set.
        $args    = is_array($args) ? $args : array();
        $cursor  = isset($args['cursor']) ? (int) $args['cursor'] : 0;
        $begun   = isset($args['begun']) ? (string) $args['begun'] : current_time('mysql', true);
        $found   = isset($args['found']) ? (int) $args['found'] : 0;
        $started = time();
        $migrated = Fastpix_Migration::attachment_map();   // attachment_id => [video_id, url basename]

        while (true) {
            // Only posts that can contain an embed at all. Migrated videos are referenced by
            // their LOCAL file ([video], wp:video, the attachment URL) — scanned too (RULE-018 swap).
            $posts = $wpdb->get_results($wpdb->prepare(
                "SELECT ID, post_content FROM {$wpdb->posts}
                 WHERE ID > %d AND post_status = 'publish' AND post_type NOT IN ('attachment', 'revision')
                   AND (post_content LIKE %s OR post_content LIKE %s
                        OR post_content LIKE %s OR post_content LIKE %s OR post_content LIKE %s)
                 ORDER BY ID ASC LIMIT 200", $cursor,
                '%' . $wpdb->esc_like('[fastpix') . '%', '%' . $wpdb->esc_like('wp:fastpix/') . '%',
                '%' . $wpdb->esc_like('[video') . '%', '%' . $wpdb->esc_like('wp:video') . '%', '%' . $wpdb->esc_like('<video') . '%'
            ), ARRAY_A);
            if (!$posts) {
                break;   // walked every post
            }
            foreach ($posts as $post) {
                $cursor = (int) $post['ID'];
                $now    = current_time('mysql', true);
                foreach (self::embed_keys($post, $migrated) as $key => $occurrences) {
                    list($video_id, $post_id, $context) = explode(':', $key);
                    $wpdb->query($wpdb->prepare(
                        'INSERT INTO ' . Fastpix_Schema::table('usage') . '
                             (video_id, post_id, context, occurrences, last_seen_at, created_at, updated_at)
                         VALUES (%d, %d, %s, %d, %s, %s, %s)
                         ON DUPLICATE KEY UPDATE occurrences = VALUES(occurrences), last_seen_at = VALUES(last_seen_at), updated_at = VALUES(updated_at)',
                        (int) $video_id, (int) $post_id, $context, (int) $occurrences, $now, $now, $now
                    ));
                    $found++;
                }
            }
            if (time() - $started >= Fastpix_Jobs::WALK_BUDGET_SECONDS) {
                Fastpix_Jobs::enqueue('fastpix_usage_sweep', array('cursor' => $cursor, 'begun' => $begun, 'found' => $found), Fastpix_Jobs::GROUP_MAINTENANCE);
                self::store_sweep_offset('usage_sweep', $cursor);   // honest: last_run_at moves, last_success_at does not

                return $found;
            }
        }

        // Drop rows this walk did not see (the embed was removed).
        self::usage_prune($begun);
        self::record_sweep('usage_sweep', $found);   // the migration verify screen reads last_success_at (QA F5/F6)

        return $found;
    }

    /** One post's usage keys ("video_id:post_id:context" => occurrences). */
    private static function embed_keys($post, $migrated) {
        global $wpdb;

        $keys = array();
        foreach (self::post_embeds($post['post_content']) as $hit) {
            $video_id = (int) $wpdb->get_var($wpdb->prepare(
                'SELECT id FROM ' . Fastpix_Schema::table('videos') . self::WHERE_MEDIA, $hit[0]
            ));
            if (!$video_id) {
                continue;
            }
            $key = $video_id . ':' . $post['ID'] . ':' . $hit[1];
            $keys[$key] = (isset($keys[$key]) ? $keys[$key] : 0) + 1;
        }
        foreach (self::migrated_hits($post['post_content'], $migrated) as $video_id => $n) {
            $key = $video_id . ':' . $post['ID'] . ':migrated';
            $keys[$key] = (isset($keys[$key]) ? $keys[$key] : 0) + $n;
        }

        return $keys;
    }

    /** FastPix embeds in raw content: [media_id, context] pairs. */
    private static function post_embeds($content) {
        $found = array();

        // Shortcode: [fastpix id="<media id>" …]
        if (preg_match_all('/\[fastpix\s+[^\]]*id=["\']([^"\']+)["\']/', $content, $m)) {
            foreach ($m[1] as $media_id) {
                $found[] = array($media_id, 'shortcode');
            }
        }
        // Block: <!-- wp:fastpix/video {"mediaId":"…"} -->
        if (preg_match_all('/wp:fastpix\/\w+\s+({[^}]*})/', $content, $m)) {
            foreach ($m[1] as $json) {
                $attrs    = json_decode($json, true);
                $media_id = is_array($attrs) ? Fastpix_Sync::field($attrs, array('mediaId', 'media_id', 'id')) : null;
                if ($media_id) {
                    $found[] = array((string) $media_id, 'block');
                }
            }
        }

        return $found;
    }

    /** Migrated: wp:video {"id":N} · [video … src="…/file.mp4"] · <video src="…/file.mp4"> */
    private static function migrated_hits($content, $migrated) {
        $hits = array();
        foreach ($migrated as $attachment_id => $ref) {
            $n = 0;
            if (preg_match_all('/wp:video\s+{[^}]*"id"\s*:\s*' . (int) $attachment_id . '\b/', $content, $mm)) {
                $n += count($mm[0]);
            }
            if ($ref['basename'] !== '' && preg_match_all('/(\[video[^\]]*|<video[^>]*|<source[^>]*)\/' . preg_quote($ref['basename'], '/') . '/', $content, $mm)) {   // (QA M21) only after a slash — movie.mp4 never matches my-movie.mp4
                $n += count($mm[0]);
            }
            if ($n) {
                $hits[$ref['video_id']] = (isset($hits[$ref['video_id']]) ? $hits[$ref['video_id']] : 0) + $n;
            }
        }

        return $hits;
    }

    /**
     * Drop usage rows the walk that began at $begun did not touch (the embed was
     * removed). 'render' rows are filed by live renders the parser cannot see
     * (page builders, dynamic templates) — kept while fresh, dropped when stale.
     */
    private static function usage_prune($begun) {
        global $wpdb;

        return (int) $wpdb->query($wpdb->prepare(
            'DELETE FROM ' . Fastpix_Schema::table('usage') . " WHERE (last_seen_at IS NULL OR last_seen_at < %s) AND (context <> 'render' OR last_seen_at < %s)",
            $begun, gmdate('Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS)
        ));
    }

    /* ---------------------------------------------------------------------
     * Sync state (DATA-010)
     * ------------------------------------------------------------------ */

    public static function sync_state($scope) {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . Fastpix_Schema::table('sync_state') . ' WHERE scope = %s',
            $scope
        ), ARRAY_A);

        return $row ? $row : array('scope' => $scope, 'watermark_id' => '0', 'last_run_at' => null, 'last_success_at' => null);
    }

    private static function seed_sync_state($scope) {
        global $wpdb;

        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . Fastpix_Schema::table('sync_state') . '
                 (scope, workspace_id, watermark_id, created_at, updated_at)
             VALUES (%s, %s, %s, %s, %s)
             ON DUPLICATE KEY UPDATE updated_at = VALUES(updated_at)',
            $scope, (string) get_option(Fastpix_Connection::OPT_WORKSPACE_ID, ''), '0',
            current_time('mysql', true), current_time('mysql', true)
        ));
    }

    /** A finished run. The deep sweep also reports what it saw and rewinds its watermark: next night starts over. */
    private static function record_sweep($scope, $items_changed, $items_seen = null) {
        global $wpdb;

        self::seed_sync_state($scope);
        $fields = array(
            'last_run_at'     => current_time('mysql', true),
            'last_success_at' => current_time('mysql', true),
            'items_changed'   => (int) $items_changed,
            'updated_at'      => current_time('mysql', true),
        );
        if ($items_seen !== null) {
            $fields['items_seen']   = (int) $items_seen;
            $fields['watermark_id'] = '0';
        }
        $wpdb->update(Fastpix_Schema::table('sync_state'), $fields, array('scope' => $scope));

        if ($items_seen !== null) {
            do_action('fastpix_log', 'deep_sweep_complete', array(
                'severity' => 'info', 'scope' => 'sync',
                'message'  => sprintf('Deep sweep complete: %d records, %d re-applied.', $items_seen, $items_changed),
            ));
        }
    }

    private static function store_sweep_offset($scope, $offset) {
        global $wpdb;

        self::seed_sync_state($scope);
        $wpdb->update(Fastpix_Schema::table('sync_state'), array(
            'watermark_id' => (string) $offset,
            'last_run_at'  => current_time('mysql', true),
            'updated_at'   => current_time('mysql', true),
        ), array('scope' => $scope));
    }

}
