<?php
/**
 * Media Library migration — WF-004, FR-020…024, REQ-020…029, REQ-104,
 * REQ-122, RULE-007/018/019, ERR-050…055, API-P08.
 *
 * Copy, never move: the platform fetches each attachment's public URL
 * (POST /on-demand, one idempotency key per batch+attachment) — bytes do not
 * pass through WordPress a second time. Attachments the outside world cannot
 * reach are pushed from here through the upload path instead. No post is
 * edited: playback is substituted at render (RULE-018) and the local file
 * stays until an explicit, typed cleanup of VERIFIED items only (RULE-019).
 *
 * Jobs (group migration, self-cap of four in flight):
 *   fastpix_migration_scan      walk video attachments → items with skip reasons
 *   fastpix_migration_item      submit one attachment
 *   fastpix_migration_finalise  cleanup — delete verified items' local files
 *
 * Batch states: scanning → scanned → running ⇄ paused → done → cleaned;
 * cancelled. Item states: pending · skipped · excluded · submitting ·
 * submitted · failed · cancelled · cleaned (+ reverted_at). Ready/failed
 * are read from the linked video row, so the platform's word is the truth.
 *
 * The background workers live in Fastpix_Migration_Scan and the REST
 * handlers in Fastpix_Migration_Rest; hooks and tests keep addressing this
 * class, which delegates.
 */

namespace Fastpix;

if (!defined('WPINC')) {
    die;
}

require_once __DIR__ . '/class-fastpix-migration-scan.php';
require_once __DIR__ . '/class-fastpix-migration-rest.php';

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery
// All SQL here runs against the plugin's own tables: identifiers come from the
// Fastpix_Schema::table() registry (a fixed whitelist, never request input) and
// every value travels through $wpdb->prepare(). Core APIs cannot query these tables.

class Fastpix_Migration {

    const HOOK_SCAN     = 'fastpix_migration_scan';
    const HOOK_ITEM     = 'fastpix_migration_item';
    const HOOK_FINALISE = 'fastpix_migration_finalise';

    const CONCURRENCY   = 4;              // REQ-029
    const BUDGET        = 20;             // seconds per job walk (RULE-042)
    const MAX_BYTES     = 21474836480;    // 20 GB ceiling — same as uploads
    const PUSH_MAX      = 2147483648;     // ponytail: server-side push (unreachable files) up to 2 GB; larger ⇒ stated, item failed
    const OPT_LEGACY    = 'fastpix_legacy_imported';

    // SQL fragments shared with the migration companion classes (identifiers
    // always come from the Fastpix_Schema::table() registry).
    const SQL_COUNT_FROM  = 'SELECT COUNT(*) FROM ';
    const SQL_SELECT_FROM = 'SELECT * FROM ';
    const SQL_UPDATE      = 'UPDATE ';
    const SQL_WHERE_ID    = ' WHERE id = %d';
    /**
     * M4: one definition of "a failed item" for the verification counters, the failed filter and Retry (items as i, videos as vv).
     * (QA M4) A linked video row that is gone — purged 30 days after its tombstone — is failed too, not "processing" forever.
     */
    const SQL_ITEM_FAILED = "(i.state = 'failed' OR (i.state = 'submitted' AND i.reverted_at IS NULL AND (vv.status = 'Failed' OR vv.deleted_at IS NOT NULL OR vv.error_code = 'orphaned' OR (i.video_id IS NOT NULL AND vv.id IS NULL))))";

    public static function boot() {
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
        add_action(self::HOOK_SCAN, array(__CLASS__, 'scan_job'));
        add_action(self::HOOK_ITEM, array(__CLASS__, 'item_job'));
        add_action(self::HOOK_FINALISE, array(__CLASS__, 'finalise_job'));

        // Swap at render — the same pass WordPress expands shortcodes/blocks. [REQ-025]
        add_filter('wp_video_shortcode_override', array(__CLASS__, 'swap_video_shortcode'), 10, 2);
        add_filter('render_block_core/video', array(__CLASS__, 'swap_video_block'), 10, 2);

        add_action('admin_init', array(__CLASS__, 'import_legacy'));   // REQ-104, once
    }

    /* -------------------------------------------------------------- routes */

    public static function register_routes() {
        $cap  = Fastpix_Capabilities::MANAGE_SETTINGS;   // API-P08: owner only
        $rest = Fastpix_Migration_Rest::class;

        Fastpix_Rest::register('/migration/scan', array(
            array('methods' => 'POST', 'callback' => array($rest, 'start_scan'), 'capability' => $cap),
            array('methods' => 'GET',  'callback' => array($rest, 'get_scan'), 'capability' => $cap, 'args' => array(
                'page'     => Fastpix_Rest::arg('integer', array('minimum' => 1)),
                'per_page' => Fastpix_Rest::arg('integer', array('minimum' => 1, 'maximum' => 200)),
                'filter'   => Fastpix_Rest::arg('string', array('enum' => array('all', 'movable', 'skipped'))),
            )),
        ));
        Fastpix_Rest::register('/migration/run', array(
            'methods' => 'POST', 'callback' => array($rest, 'run'), 'capability' => $cap,
            'args' => array(
                'batch_id'      => Fastpix_Rest::arg('string', array('required' => true)),
                'scope'         => Fastpix_Rest::arg('string', array('enum' => array('all', 'selection'))),
                'ids'           => array('type' => 'array', 'items' => array('type' => 'integer')),
                'quality_tier'  => Fastpix_Rest::arg('string'),
                'access_policy' => Fastpix_Rest::arg('string', array('enum' => array('public', 'private', 'drm'))),
            ),
        ));
        Fastpix_Rest::register('/migration/history', array(
            'methods' => 'GET', 'callback' => array($rest, 'history'), 'capability' => $cap,
        ));
        Fastpix_Rest::register('/migration/(?P<id>[A-Za-z0-9_-]+)', array(
            array('methods' => 'GET', 'callback' => array($rest, 'get_batch'), 'capability' => $cap, 'args' => array(
                'page'     => Fastpix_Rest::arg('integer', array('minimum' => 1)),
                'per_page' => Fastpix_Rest::arg('integer', array('minimum' => 1, 'maximum' => 200)),
                'filter'   => Fastpix_Rest::arg('string', array('enum' => array('all', 'failed', 'reverted'))),
            )),
            array('methods' => 'DELETE', 'callback' => array($rest, 'cancel'), 'capability' => $cap),
        ));
        Fastpix_Rest::register('/migration/(?P<id>[A-Za-z0-9_-]+)/(?P<op>pause|resume)', array(
            'methods' => 'POST', 'callback' => array($rest, 'pause_resume'), 'capability' => $cap,
        ));
        Fastpix_Rest::register('/migration/(?P<id>[A-Za-z0-9_-]+)/cleanup', array(
            'methods' => 'POST', 'callback' => array($rest, 'cleanup'), 'capability' => $cap,
            'args' => array('confirm' => Fastpix_Rest::arg('string', array('required' => true))),
        ));
        Fastpix_Rest::register('/migration/(?P<id>[A-Za-z0-9_-]+)/retry', array(
            'methods' => 'POST', 'callback' => array($rest, 'retry_failed'), 'capability' => $cap,
            'args' => array('item_id' => Fastpix_Rest::arg('integer')),
        ));
        Fastpix_Rest::register('/migration/items/(?P<id>\d+)/revert', array(
            'methods' => 'POST', 'callback' => array($rest, 'revert'), 'capability' => $cap,
        ));
    }

    /* ------------------------------------------------------ job delegates */
    // The Action Scheduler hooks (and the self-checks) address this class;
    // the workers themselves live in Fastpix_Migration_Scan.

    /** Walk every video attachment into items with skip reasons. [REQ-021] */
    public static function scan_job($args) {
        Fastpix_Migration_Scan::scan_job($args);
    }

    /** Submit one attachment (four in flight, per-item failures). [REQ-029] */
    public static function item_job($args) {
        Fastpix_Migration_Scan::item_job($args);
    }

    /** Delete verified items' local files only. [RULE-019, ERR-055] */
    public static function finalise_job($args) {
        Fastpix_Migration_Scan::finalise_job($args);
    }

    /** The upload webhook named the media: link the item that pushed this upload. */
    public static function bind_pushed_upload($upload_id, $video_id) {
        Fastpix_Migration_Scan::bind_pushed_upload($upload_id, $video_id);
    }

    /* ---------------------------------------------------------- verification */

    /**
     * Machine verification [RULE-019]: every submitted item's video is Ready with
     * a playback id; failed items are excluded (their local file is the only copy).
     */
    public static function verification($batch_id) {
        global $wpdb;

        $t = Fastpix_Schema::table('migration_items');
        $v = Fastpix_Schema::table('videos');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT i.id, i.state, i.reverted_at, i.video_id, v.id AS vid, v.status, v.deleted_at, v.error_code,
                    (SELECT COUNT(*) FROM " . Fastpix_Schema::table('playback_ids') . " p WHERE p.video_id = v.id AND p.deleted_at IS NULL) AS playbacks
             FROM {$t} i LEFT JOIN {$v} v ON v.id = i.video_id WHERE i.batch_id = %s", $batch_id
        ), ARRAY_A);
        $counts = array('ready' => 0, 'failed' => 0, 'pending' => 0, 'processing' => 0, 'submitted' => 0, 'reverted' => 0, 'cleaned' => 0);
        foreach ($rows as $r) {
            foreach (self::verification_buckets($r) as $bucket) {
                $counts[$bucket]++;
            }
        }
        // M15: no placeholder, no prepare().
        $swept = (bool) $wpdb->get_var("SELECT 1 FROM " . Fastpix_Schema::table('sync_state') . " WHERE scope = 'usage_sweep' AND last_success_at IS NOT NULL LIMIT 1");

        return array_merge($counts, array(
            'usage_swept' => $swept,
            'verified' => $counts['pending'] === 0 && $counts['ready'] > 0,
            'cleanable' => max(0, $counts['ready'] - $counts['cleaned']),
        ));
    }

    /** Which verification counters one item row lands in (a cleaned item also counts as ready). */
    private static function verification_buckets($r) {
        $out = array();
        if ($r['state'] === 'cleaned') {
            $out = array('cleaned', 'ready');
        } elseif ($r['reverted_at'] !== null) {
            $out = array('reverted');
        } elseif ($r['state'] === 'failed') {
            $out = array('failed');
        } elseif (in_array($r['state'], array('pending', 'submitting'), true)) {
            $out = array('pending');
        } elseif ($r['state'] === 'submitted') {
            $out[] = 'submitted';
            if ($r['status'] === 'Ready' && (int) $r['playbacks'] > 0 && $r['deleted_at'] === null && $r['error_code'] !== 'orphaned') { $out[] = 'ready'; }
            elseif ($r['status'] === 'Failed' || $r['deleted_at'] !== null || $r['error_code'] === 'orphaned' || ($r['video_id'] !== null && $r['vid'] === null)) { $out[] = 'failed'; }   // mirrors SQL_ITEM_FAILED (M4)
            else { $out[] = 'pending'; $out[] = 'processing'; }   // M28: on the platform, not merely queued here
        }

        return $out;
    }

    /* ---------------------------------------------------- swap at render */

    /**
     * attachment_id ⇒ {video_id, basename} for every migrated, non-reverted item
     * whose video is Ready. Cached per request.
     */
    private static $map = null;

    public static function reset_map() {
        self::$map = null;
    }

    public static function attachment_map() {
        if (self::$map !== null) {
            return self::$map;
        }
        global $wpdb;
        $map = array();
        if (!Fastpix_Schema::table_exists('migration_items')) {
            return self::$map = $map;
        }
        // M11: one query — the file name rides along from postmeta instead of a get_attached_file() per row.
        // M14: an orphaned copy (FastPix no longer knows it) is not swapped in; the local file keeps playing.
        $rows = $wpdb->get_results(
            'SELECT i.attachment_id, i.video_id, f.meta_value AS file FROM ' . Fastpix_Schema::table('migration_items') . ' i
             JOIN ' . Fastpix_Schema::table('videos') . " v ON v.id = i.video_id
             LEFT JOIN {$wpdb->postmeta} f ON f.post_id = i.attachment_id AND f.meta_key = '_wp_attached_file'
             WHERE i.state IN ('submitted','cleaned') AND i.reverted_at IS NULL AND v.deleted_at IS NULL AND v.error_code <> 'orphaned'",
            ARRAY_A
        );
        foreach ((array) $rows as $r) {
            $map[(int) $r['attachment_id']] = array('video_id' => (int) $r['video_id'], 'basename' => basename((string) $r['file']));
        }

        return self::$map = $map;
    }

    /** attachment id (or a URL to its file) → the migrated video's media id, or ''. */
    public static function media_for_attachment($attachment_id, $url = '') {
        global $wpdb;

        $attachment_id = (int) $attachment_id;
        if (!$attachment_id && $url !== '') {
            $attachment_id = (int) attachment_url_to_postid(preg_replace('/\?.*$/', '', $url));
        }
        if (!$attachment_id) {
            return '';
        }
        $map = self::attachment_map();
        if (!isset($map[$attachment_id])) {
            return '';
        }
        $row = $wpdb->get_row($wpdb->prepare('SELECT media_id, status FROM ' . Fastpix_Schema::table('videos') . self::SQL_WHERE_ID, $map[$attachment_id]['video_id']), ARRAY_A);

        return $row && $row['status'] === 'Ready' ? (string) $row['media_id'] : '';   // not ready yet ⇒ the local file keeps playing
    }

    /** [video src=…] / [video mp4=…] — core shortcode; the platform player replaces the local file (RULE-018). */
    public static function swap_video_shortcode($override, $attr) {
        if ($override !== '' || !is_array($attr)) {
            return $override;
        }
        $src = '';
        foreach (array('src', 'mp4', 'm4v', 'webm', 'ogv', 'flv', 'wmv') as $k) {
            if (!empty($attr[$k])) { $src = (string) $attr[$k]; break; }
        }
        $media = self::media_for_attachment(isset($attr['id']) ? (int) $attr['id'] : 0, $src);
        if ($media === '') {
            return $override;
        }
        $over = array();
        if (!empty($attr['autoplay'])) { $over['autoplay'] = true; }
        if (!empty($attr['loop'])) { $over['loop'] = true; }
        if (!empty($attr['muted'])) { $over['muted'] = true; }
        if (!empty($attr['poster'])) { $over['poster'] = (string) $attr['poster']; }
        $html = Fastpix_Render::render($media, $over, array('context' => 'migrated'));

        return $html !== '' ? $html : $override;
    }

    /** wp:video block — attributes.id (or the <video src>) resolves the same way. */
    public static function swap_video_block($html, $block) {
        $attrs = isset($block['attrs']) && is_array($block['attrs']) ? $block['attrs'] : array();
        $src   = '';
        if (preg_match('/<video[^>]*\ssrc="([^"]+)"/', (string) $html, $m)) { $src = html_entity_decode($m[1]); }
        $media = self::media_for_attachment(isset($attrs['id']) ? (int) $attrs['id'] : 0, $src);
        if ($media === '') {
            return $html;
        }
        $over = array();
        foreach (array('autoplay', 'loop', 'muted') as $k) { if (!empty($attrs[$k])) { $over[$k] = true; } }
        if (!empty($attrs['poster'])) { $over['poster'] = (string) $attrs['poster']; }
        $out = Fastpix_Render::render($media, $over, array('context' => 'migrated'));

        return $out !== '' ? $out : $html;
    }

    /* ------------------------------------------------------- legacy import */

    /**
     * REQ-104 — the fastpix-io (v1) upgrade: adopt its credential pair when the
     * new one is empty; carry v1 registry titles onto rows the sweep created;
     * old [fastpix playback_id=…] shortcodes already render through the new
     * renderer. Runs once; edits no post.
     */
    public static function import_legacy() {
        if (get_option(self::OPT_LEGACY) || !current_user_can('manage_options')) {
            return;
        }
        $report = array(
            'credentials' => self::adopt_legacy_credentials(),
            'titles'      => self::carry_legacy_titles(),
        );

        update_option(self::OPT_LEGACY, array_merge($report, array('at' => time())), false);
        if ($report['credentials'] || $report['titles']) {
            do_action('fastpix_audit_event', 'legacy_import', $report);
        }
    }

    /** Adopt the v1 credential pair when the v2 one is empty; purge the redundant v1 options either way. */
    private static function adopt_legacy_credentials() {
        $adopted = false;
        if (!Fastpix_Credentials::has_pair()) {
            $key    = (string) get_option('fastpix_api_key', '');
            $secret = (string) get_option('fastpix_api_secret', '');
            if ($key === '' && class_exists('\Fastpix\Fastpix_Utils') && method_exists('\Fastpix\Fastpix_Utils', 'get_api_key')) {
                $key    = (string) Fastpix_Utils::get_api_key();
                $secret = (string) Fastpix_Utils::get_api_secret();
            }
            if ($key !== '' && $secret !== '') {
                Fastpix_Credentials::store($key, $secret);
                $adopted = true;
            }
        }

        // SEC-002: the v2 pair is now sealed (adopted above or already present),
        // so the v1 plaintext/legacy-encrypted credential options are redundant
        // and must not linger in wp_options. Gated on has_pair() so we never
        // strip a site whose only credentials are still the v1 options.
        if (Fastpix_Credentials::has_pair()) {
            Fastpix_Credentials::purge_legacy();
        }

        return $adopted;
    }

    /** Carry v1 registry titles onto video rows the sweep created without one; returns how many. */
    private static function carry_legacy_titles() {
        global $wpdb;

        $registry = get_option('fastpix_videos', array());
        if (!is_array($registry) || !$registry || !Fastpix_Schema::table_exists('videos')) {
            return 0;
        }

        $titles = 0;
        foreach ($registry as $entry) {
            $pb    = isset($entry['playback_id']) ? (string) $entry['playback_id'] : '';
            $title = isset($entry['title']) ? (string) $entry['title'] : '';
            if ($pb === '' || $title === '') {
                continue;
            }
            $video_id = (int) $wpdb->get_var($wpdb->prepare('SELECT video_id FROM ' . Fastpix_Schema::table('playback_ids') . ' WHERE playback_id = %s', $pb));
            if (!$video_id || (string) $wpdb->get_var($wpdb->prepare('SELECT title FROM ' . Fastpix_Schema::table('videos') . self::SQL_WHERE_ID, $video_id)) !== '') {
                continue;
            }
            $wpdb->update(Fastpix_Schema::table('videos'), array('title' => $title), array('id' => $video_id));
            $titles++;
        }

        return $titles;
    }

    /* -------------------------------------------------------------- helpers */

    public static function batch($batch_id) {
        global $wpdb;
        if ($batch_id === '') {
            return null;
        }
        $row = $wpdb->get_row($wpdb->prepare(self::SQL_SELECT_FROM . Fastpix_Schema::table('migrations') . ' WHERE batch_id = %s', $batch_id), ARRAY_A);

        return $row ? $row : null;
    }

    public static function latest_batch($states) {
        global $wpdb;
        $in  = "'" . implode("','", array_map('esc_sql', (array) $states)) . "'";
        $row = $wpdb->get_row(self::SQL_SELECT_FROM . Fastpix_Schema::table('migrations') . " WHERE state IN ({$in}) ORDER BY id DESC LIMIT 1", ARRAY_A);

        return $row ? $row : null;
    }
}
