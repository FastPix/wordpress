<?php
/**
 * Connection lifecycle — WF-001 / WF-014.
 *
 * One credential pair binds the site to exactly one workspace; the pair is
 * validated with one private IAM verification call before anything is stored, and a
 * pair that does not work is never stored. [REQ-001, REQ-002, FR-001…FR-003]
 */

namespace Fastpix;

if (!defined('WPINC')) {
    die;
}

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery
// All SQL here runs against the plugin's own tables: identifiers come from the
// Fastpix_Schema::table() registry (a fixed whitelist, never request input) and
// every value travels through $wpdb->prepare(). Core APIs cannot query these tables.

class Fastpix_Connection {

    const OPT_WORKSPACE_ID = 'fastpix_workspace_id';
    /** The platform's UUID (+ display name) for the connected pair's workspace: learned from the first record read with the pair, or from a delivery of a never-synced workspace. Never overrides the saved key. */
    const OPT_WORKSPACE_SEEN_ID   = 'fastpix_workspace_seen_id';
    const OPT_WORKSPACE_SEEN_NAME = 'fastpix_workspace_seen_name';
    /** Stamped on rows left behind by a pair whose workspace UUID was never learned. */
    const WS_UNKNOWN = 'previous-unknown';
    /** The outgoing workspace UUID (or WS_UNKNOWN) while the new pair's UUID is still unlearned. */
    const OPT_PENDING_LEAVE = 'fastpix_workspace_pending_leave';
    /** The token a disconnect dropped, so the next connect can tell a new pair from the old one. */
    const OPT_LAST_TOKEN = 'fastpix_last_token_id';
    /** The workspace key a disconnect dropped, so a key saved after it can still be seen to differ. */
    const OPT_LAST_KEY = 'fastpix_last_workspace_id';
    /** Set while the workspace just left was never identified: deliveries teach nothing until the API sweep does. */
    const OPT_LEFT_UNKNOWN = 'fastpix_workspace_left_unknown';

    const OPT_CONNECTED_AT = 'fastpix_connected_at';

    /**
     * Owner ruling 2026-09-08 (ASSUME-070): credentials are verified with the
     * platform's private Basic-auth check (POST, empty body; 200 = pair valid,
     * 401 = not). Server-side only — never localized to JS, echoed in a REST
     * response, logged or written into the specs.
     */
    private const VERIFY_PATH = 'iam/auth/verify-basic';

    /**
     * Validate a candidate pair: one authenticated call to VERIFY_PATH whose
     * ONLY job is proving the pair works. No workspace id is sniffed from responses — it
     * is explicit user entry on the Connect workspace step. [FR-001]
     *
     * @return array|\WP_Error ['permissions' => null]
     */
    public static function validate($token_id, $secret) {
        $client = new Fastpix_Api_Client($token_id, $secret);

        $result = $client->request('POST', self::VERIFY_PATH, array(
            'headers' => array('X-FastPix-Integration' => 'wordpress'),   // identifies the plugin at connect time only [ASSUME-069]
        ));

        if (is_wp_error($result)) {
            return self::connect_error($result);
        }

        return array(
            // OQ-008 — no endpoint returns a pair's granted capabilities.
            'permissions' => null,
        );
    }

    /**
     * The workspace id comes from the user, never from response sniffing. Saved
     * via its own card/action, keyed on by webhooks and analytics.
     *
     * @return array|\WP_Error State on success.
     */
    public static function set_workspace_id($workspace_id) {
        $workspace_id = strtolower(trim((string) $workspace_id));

        // The dashboard's Workspaces page shows a numeric "Workspace key"
        // (e.g. 980293090846277633) — that is what analytics keys on. The
        // UUID form (as webhooks name the workspace) is accepted too.
        if (!preg_match('/^\d{6,32}$/', $workspace_id)
            && !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $workspace_id)) {
            return new \WP_Error(
                'fastpix_bad_workspace_id',
                __('That does not look like a workspace key — copy it from the Workspaces page of the FastPix dashboard (a long number).', 'fastpix'),
                array('status' => 400)
            );
        }

        $previous_key = (string) get_option(self::OPT_WORKSPACE_ID, '') ?: (string) get_option(self::OPT_LAST_KEY, '');
        update_option(self::OPT_WORKSPACE_ID, $workspace_id, false);
        delete_option(self::OPT_LAST_KEY);
        // One key being a prefix of the other is a half-typed key, not a new workspace.
        $extends = strpos($workspace_id, $previous_key) === 0 || strpos($previous_key, $workspace_id) === 0;
        if ($previous_key !== '' && $previous_key !== $workspace_id && !$extends && (string) get_option(self::OPT_PENDING_LEAVE, '') !== '') {
            // The token binds the workspace; a changed key is evidence of a NEW
            // workspace only while a pair change is still unresolved — then it
            // settles the pending leave (rows and UUID were left at connect). A key
            // typo fixed under the same pair leaves nothing.
            self::wipe_rollup();
        }
        self::audit('workspace_set', array('workspace_id' => $workspace_id));

        return self::state();
    }

    /** The full menu needs both halves: a working pair AND a workspace id. */
    public static function workspace_ready() {
        return self::pair_usable() && (string) get_option(self::OPT_WORKSPACE_ID, '') !== '';
    }

    /**
     * A stored pair whose secret still decrypts. After a salt rotation the pair
     * is present but useless (every call refuses before sending), so every
     * "connected" surface — pill, wizard, menu gate — keys on this, and the
     * wizard asks for the pair again. Site Health names the cause.
     */
    public static function pair_usable() {
        return Fastpix_Credentials::has_pair() && !Fastpix_Credentials::is_unreadable();
    }

    /**
     * Validate, then store. Rotation is the same call: the new pair replaces the
     * old only once validated, and a failure leaves the old pair in place.
     * [REQ-002, FR-001, FR-005, WF-001 steps 3-4]
     *
     * @return array|\WP_Error State on success.
     */
    public static function connect($token_id, $secret) {
        $validated = self::validate($token_id, $secret);

        if (is_wp_error($validated)) {
            return $validated;   // Nothing stored; the caller preserves the typed entry.
        }

        // A disconnect keeps the dropped token aside so this connect can still tell a new pair from the old.
        $previous_token = Fastpix_Credentials::token_id() ?: (string) get_option(self::OPT_LAST_TOKEN, '');
        $previous_ws    = (string) get_option(self::OPT_WORKSPACE_SEEN_ID, '');

        if (!Fastpix_Credentials::store($token_id, $secret)) {
            return new \WP_Error(
                'fastpix_store_failed',
                __('The connection could not be saved on this site.', 'fastpix')
            );
        }

        delete_option(Fastpix_Api_Client::OPT_HEALTH);
        delete_transient(Fastpix_Api_Client::TRANSIENT_BREAKER);   // the pair just answered: nothing is paused any more
        delete_transient(Fastpix_Api_Client::TRANSIENT_PAUSE);
        delete_option(self::OPT_LAST_TOKEN);
        update_option(self::OPT_CONNECTED_AT, time(), false);
        // The same token re-saved is the same workspace: nothing to leave. A different
        // token (or a workspace known without any token, from an older disconnect)
        // leaves the previous pair — the rollup follows once the new UUID is learned.
        $changed = $previous_token !== '' && $previous_token !== sanitize_text_field((string) $token_id);
        if ($changed || ($previous_token === '' && $previous_ws !== '')) {
            // Signing keys are per workspace: a key made under the previous pair
            // signs tokens the new workspace rejects ("Incorrect playback ID or
            // token"). Forget it; the next protected render creates one here. The
            // same pair re-verified keeps its key — every forget left an orphan
            // key on the platform.
            Fastpix_Signing::forget_key();
            Fastpix_Cache::flush_group('tokens');
            self::leave_pair($previous_ws);
        }
        self::audit('connect', array());

        // Sweeps and sync-state seeding subscribe here [WF-001 background step].
        do_action('fastpix_connected', (string) get_option(self::OPT_WORKSPACE_ID, ''));

        return self::state();
    }

    /**
     * The pair changed. Everything the old pair owned is left behind NOW — rows,
     * the learned UUID, paused uploads, sweep cursors, read caches — but the
     * platform-derived analytics rollup waits: a rotated token for the SAME
     * workspace must keep its 25 months of history, and only the next learned
     * UUID (or a changed workspace key) can tell the two apart. Local work —
     * video rows, watch progress, lesson progress — is kept, per ASSUME-040.
     */
    private static function leave_pair($previous_ws) {
        global $wpdb;

        // Every unstamped row predates this pair, so none of it belongs to the new
        // workspace. When the outgoing UUID was never learned (an empty workspace
        // teaches nothing) a sentinel keeps those rows out of every later listing.
        $wpdb->query($wpdb->prepare(
            Fastpix_Sync::SQL_UPDATE . Fastpix_Schema::table('videos') . " SET workspace_id = %s WHERE workspace_id = ''",
            $previous_ws !== '' ? $previous_ws : self::WS_UNKNOWN
        ));
        delete_option(self::OPT_WORKSPACE_SEEN_ID);     // the new pair's workspace is learned from its first record
        delete_option(self::OPT_WORKSPACE_SEEN_NAME);
        if ((string) get_option(self::OPT_PENDING_LEAVE, '') === '') {   // a second leave before the first resolved keeps the older UUID
            update_option(self::OPT_PENDING_LEAVE, $previous_ws !== '' ? $previous_ws : self::WS_UNKNOWN, false);
        }
        if ($previous_ws === '') {
            update_option(self::OPT_LEFT_UNKNOWN, 1, false);   // nothing can name the workspace just left, so no delivery may teach
        }
        // Live rows are keyed on the owner-typed workspace key, which a pair change
        // does not touch: set the old rows aside so only streams the new pair lists
        // (re-stamped on the next refresh) match the key.
        $wpdb->query(Fastpix_Sync::SQL_UPDATE . Fastpix_Schema::table('live_streams') . " SET workspace_id = CONCAT('prev:', workspace_id) WHERE workspace_id NOT LIKE 'prev:%'");
        // Sweep cursors belong to the old library: the new one is walked from its first page.
        $wpdb->query(Fastpix_Sync::SQL_UPDATE . Fastpix_Schema::table('sync_state') . " SET watermark_id = '0' WHERE scope IN ('deep_sweep', 'new_media', 'audit')");
        delete_option(Fastpix_Analytics::OPT_PULL_CURSOR);
        foreach (array('videos', 'embed') as $group) {
            Fastpix_Cache::flush_group($group);
        }
        self::audit('pair_left', array('previous_workspace' => $previous_ws));
    }

    /**
     * The workspace itself changed: drop the platform-derived rollup and let the
     * pull job refetch the new workspace's figures (FastPix keeps the history;
     * reconnecting pulls it again). TRUNCATE, not DELETE — the connect route is
     * synchronous and the rollup can hold millions of rows.
     */
    private static function wipe_rollup() {
        global $wpdb;

        if ($wpdb->query('TRUNCATE TABLE ' . Fastpix_Schema::table('analytics_daily')) === false) {
            $wpdb->query('DELETE FROM ' . Fastpix_Schema::table('analytics_daily'));   // a host without DROP privilege
        }
        foreach (array(Fastpix_Analytics::OPT_BACKFILL, Fastpix_Analytics::OPT_LAST_SUCCESS, Fastpix_Analytics::OPT_HOURLY, Fastpix_Analytics::OPT_PULL_CURSOR) as $opt) {
            delete_option($opt);
        }
        delete_transient('fastpix_analytics_kick');
        // Past exports are the previous workspace's figures: files and list go too.
        foreach ((array) get_option(Fastpix_Analytics::OPT_EXPORTS, array()) as $export) {
            if (!empty($export['file']) && is_string($export['file']) && file_exists($export['file'])) {
                wp_delete_file($export['file']);
            }
        }
        delete_option(Fastpix_Analytics::OPT_EXPORTS);
        delete_option(self::OPT_PENDING_LEAVE);
        // Now proven a different workspace: open uploads hold signed URLs of the old
        // one, so resuming them would land the file there. (A rotation keeps them.)
        $wpdb->query(Fastpix_Sync::SQL_UPDATE . Fastpix_Schema::table('uploads') . " SET state = 'cancelled', updated_at = UTC_TIMESTAMP() WHERE state IN ('created', 'uploading', 'paused')");
        Fastpix_Cache::flush_group('analytics');
        self::audit('workspace_left', array());
    }

    /**
     * The first record after a connect names the pair's workspace (UUID). Sweeps
     * and uploads learn from platform records read WITH the new pair; webhooks
     * learn too, except that a late delivery from the workspace just left must
     * not re-teach it. Learning settles the pending leave: a different UUID is a
     * different workspace (rollup dropped), the same UUID was a rotated token.
     *
     * @return bool Whether the UUID was accepted as the connected workspace.
     */
    public static function learn_workspace($uuid, $from_api) {
        $uuid = (string) $uuid;
        if ($uuid === '' || (string) get_option(self::OPT_WORKSPACE_SEEN_ID, '') !== '') {
            return false;
        }
        $pending = (string) get_option(self::OPT_PENDING_LEAVE, '');
        if (!$from_api && ($pending === self::WS_UNKNOWN || (int) get_option(self::OPT_LEFT_UNKNOWN, 0) === 1 || $uuid === $pending || self::stamped_anywhere($uuid))) {
            // A delivery can teach only a workspace this site has never synced — and
            // nothing at all while the workspace just left was never identified. The
            // API sweep teaches the rest within minutes of the connect.
            return false;
        }
        update_option(self::OPT_WORKSPACE_SEEN_ID, $uuid, false);
        delete_option(self::OPT_LEFT_UNKNOWN);   // the API named the connected workspace; deliveries may teach again after the next leave
        if ($pending !== '') {
            if ($uuid === $pending) {
                delete_option(self::OPT_PENDING_LEAVE);   // rotated token, same workspace: history stays
            } else {
                self::wipe_rollup();
            }
        }
        Fastpix_Cache::flush_group('videos');
        return true;
    }

    /**
     * A delivery or queued event naming this UUID must not be applied: it is not
     * the connected workspace (learned or still unlearned) and either a workspace
     * is already known, or the UUID is one this site has left or synced before.
     */
    public static function workspace_is_stale($uuid) {
        $uuid = (string) $uuid;
        if ($uuid === '' || $uuid === (string) get_option(self::OPT_WORKSPACE_ID, '')) {
            return false;
        }
        $seen = (string) get_option(self::OPT_WORKSPACE_SEEN_ID, '');
        if ($uuid === $seen) {
            return false;
        }
        $pending = (string) get_option(self::OPT_PENDING_LEAVE, '');
        return $seen !== '' || $pending === self::WS_UNKNOWN || $uuid === $pending || self::stamped_anywhere($uuid);
    }

    /** Whether any video row already carries this UUID — i.e. it is a workspace this site has synced before. */
    private static function stamped_anywhere($uuid) {
        global $wpdb;

        return (bool) $wpdb->get_var($wpdb->prepare(
            'SELECT 1 FROM ' . Fastpix_Schema::table('videos') . ' WHERE workspace_id = %s LIMIT 1',
            $uuid
        ));
    }

    /** POST /connection/test — re-runs the validation read with the stored pair. [WF-001] */
    public static function test() {
        if (!Fastpix_Credentials::has_pair()) {
            return new \WP_Error('fastpix_not_connected', __('This site is not connected to FastPix.', 'fastpix'));
        }

        $validated = self::validate(Fastpix_Credentials::token_id(), Fastpix_Credentials::secret());

        return is_wp_error($validated) ? $validated : self::state();
    }

    /**
     * DELETE /connection — drops the pair and the workspace binding, and
     * nothing else. No video, post, row or option belonging to the library is
     * touched; playback stops until an account is connected again. [WF-001]
     */
    public static function disconnect() {
        $workspace_id = get_option(self::OPT_WORKSPACE_ID, '');

        update_option(self::OPT_LAST_TOKEN, Fastpix_Credentials::token_id(), false);   // so the next connect can tell a new pair from this one
        update_option(self::OPT_LAST_KEY, (string) get_option(self::OPT_WORKSPACE_ID, ''), false);
        Fastpix_Credentials::forget();
        delete_option(self::OPT_WORKSPACE_ID);
        delete_option(Fastpix_Api_Client::OPT_HEALTH);
        delete_transient(Fastpix_Api_Client::TRANSIENT_BREAKER);   // the outage (if any) belonged to the pair just dropped
        delete_transient(Fastpix_Api_Client::TRANSIENT_PAUSE);

        self::audit('disconnect', array('workspace_id' => $workspace_id));

        return self::state();
    }

    /** What the connection screen renders. [REQ-006, FR-003] */
    public static function state() {
        return array(
            'connected'       => self::pair_usable(),   // an unreadable secret is NOT connected, whatever is stored
            'workspace_id'    => (string) get_option(self::OPT_WORKSPACE_ID, ''),
            'workspace_saved' => (string) get_option(self::OPT_WORKSPACE_ID, '') !== '',
            // CONFLICT-004 / OQ-006: no endpoint returns the workspace name — it
            // arrives with the first webhook. Plan and usage stay on the FastPix
            // dashboard until that question is answered.
            'workspace_name' => (string) get_option(self::OPT_WORKSPACE_SEEN_NAME, '') ?: null,   // learned from the first authentic webhook
            'permissions'    => null,   // OQ-008
            'token_id'       => Fastpix_Credentials::masked_token_id(),
            'secret'         => Fastpix_Credentials::masked_secret(),
            'healthy'        => Fastpix_Api_Client::is_healthy(),
            'unreadable'     => Fastpix_Credentials::is_unreadable(),
        );
    }

    /**
     * Map a client failure to the three named connect failures.
     * [FR-002, ERR-001/002/003]
     */
    private static function connect_error($error) {
        switch ($error->get_error_code()) {
            case 'fastpix_bad_credentials':
                // ERR-001. The verify call cannot say which half is wrong, so no
                // field is singled out (owner ruling 2026-09-08, ASSUME-071).
                return new \WP_Error(
                    'fastpix_bad_credentials',
                    __('Invalid credentials', 'fastpix')
                );

            case 'fastpix_unreachable':
            case 'fastpix_server_error':
            case 'fastpix_breaker_open':
            case 'fastpix_rate_limited':
                // ERR-002. Firewall/outbound guidance and a system report live in the UI.
                return new \WP_Error(
                    'fastpix_unreachable',
                    __('FastPix did not respond. A firewall or outbound rule may be blocking this site; your hosting provider can confirm.', 'fastpix'),
                    array('action' => 'copy_system_report')
                );

            default:
                return $error;
        }
    }

    /**
     * Audit log: connect, disconnect and secret rotation are audited events.
     * [ARCH-13, SDD §22]
     *
     * The hook is `fastpix_audit_event`, not `fastpix_audit`: ARCH-07 claims
     * `fastpix_audit` as a scheduled job hook in the sync group, and Action
     * Scheduler runs a job by firing the hook of that name. Fastpix_Log
     * subscribes to this one.
     */
    private static function audit($event, $context = array()) {
        do_action('fastpix_audit_event', $event, array_merge(array(
            'user_id' => function_exists('get_current_user_id') ? get_current_user_id() : 0,
            'at'      => time(),
        ), $context));
    }
}
