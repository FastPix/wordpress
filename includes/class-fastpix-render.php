<?php
/**
 * Renderer — WF-007, FR-050/051/052, REQ-050…056, REQ-075, RULE-010/013/
 * 014/015/016/034/045/046, SEC-005/006/007.
 *
 * One renderer behind the block (blocks/video), the shortcode ([fastpix …])
 * and GET /player-config/{playbackId}. It resolves the video by identifier,
 * re-checks the access policy SERVER-SIDE (a crafted attribute cannot promote
 * a private video — SEC-006), merges site defaults ← per-embed overrides,
 * and places the vendored FastPix player (assets/vendor/fastpix-player.js,
 * REQ-112 — never a CDN).
 *
 *   PUBLIC   markup cached 1 h (ARCH-09 TTL_EMBED_PUBLIC), unsigned URLs,
 *            deferred player, structured data (REQ-075) unless suppressed.
 *   PRIVATE  never cached; ONE token minted at render (Fastpix_Signing);
 *            DONOTCACHEPAGE + nocache_headers; the element also carries the
 *            player-config URL + token expiry so assets/js/player.js re-fetches
 *            a token when a cached copy of the page is served (edge cache).
 *   DRM      as private plus the drm token; HTTPS required (stated reason);
 *            unresolvable configuration ⇒ poster + message + health failure.
 *   LIVE     [fastpix streamid=…] waiting → live → recording (RULE-034).
 *
 * Every failure path resolves to poster / message / fallback — never a blank
 * frame (RULE-016).
 *
 * Split across three classes to stay within the 20-method budget:
 * Fastpix_Render (entries + resolution), Fastpix_Render_Player (player
 * element + degraded states + SEO), Fastpix_Render_Live (live + player-config).
 */

namespace Fastpix;

if (!defined('WPINC')) {
    die;
}

require_once __DIR__ . '/class-fastpix-render-player.php';
require_once __DIR__ . '/class-fastpix-render-live.php';

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery
// All SQL here runs against the plugin's own tables: identifiers come from the
// Fastpix_Schema::table() registry (a fixed whitelist, never request input) and
// every value travels through $wpdb->prepare(). Core APIs cannot query these tables.

class Fastpix_Render {

    const OPT_SEO      = 'fastpix_structured_data';     // site-wide toggle, default on
    const OPT_DRM_BAD  = 'fastpix_drm_unresolved';      // {media_id, at} — health failure (ERR-061)
    const SCRIPT       = 'fastpix-player';
    const HELPER       = 'fastpix-player-helper';
    const HLS          = 'fastpix-hls';
    const CONSENT      = 'fastpix-player-consent';

    /** Query prefix shared with the render companions (own tables only). */
    const SQL_SELECT_ALL = 'SELECT * FROM ';

    /** Site defaults — CONFLICT-003 keeps them constants (no defaults screen). Per-embed overrides win. */
    const DEFAULTS = array(
        'autoplay'        => false,
        'muted'           => false,
        'loop'            => false,
        'controls'        => true,
        'showChapters'    => true,
        'showTranscript'  => false,
        'captionsDefault' => false,
        'lazyLoad'        => true,
        'accentColour'    => '#6D22CD',
        'poster'          => '',      // image URL wins; else thumbnailTime [RULE-045]
        'thumbnailTime'   => 1,
        'startTime'       => 0,
        'aspectRatio'     => '',      // '' ⇒ the video's own
        // One on/off switch for the whole control bar plus the two interaction
        // switches; no per-part hide= list (the player did not honour the CSS
        // variables reliably).
        'clickToPlay'     => true,
        'keyboard'        => true,
        'resume'          => true,    // seek-on-load resume, every post type
        // Lesson-only — printed only on LMS lesson post types; the block's
        // Completion panel does not register elsewhere.
        'completeAt'      => 90,
        'trackViewer'     => false,
    );

    public static function boot() {
        add_action('init', array(__CLASS__, 'register'));
        add_action('rest_api_init', array(Fastpix_Render_Live::class, 'register_routes'));
        add_action('save_post', array(Fastpix_Render_Live::class, 'forget_live_usage'), 10, 2);
        add_action('wp_footer', array(Fastpix_Render_Player::class, 'print_structured_data'), 5);
        add_action('wp_enqueue_scripts', array(__CLASS__, 'register_assets'));
        add_action('enqueue_block_editor_assets', array(__CLASS__, 'editor_assets'));
        add_action('enqueue_block_assets', array(__CLASS__, 'canvas_assets'));
        add_action('admin_init', array(__CLASS__, 'install_shortcode_fallback'));
    }

    const FALLBACK_FILE = 'fastpix-shortcode-fallback.php';

    /**
     * QA #16: a deactivated plugin runs no code, so WordPress prints [fastpix …] as text. A must-use
     * file (always loaded, even with FastPix off) takes the shortcode over only when nothing registered
     * it — i.e. while FastPix is inactive — and shows the same "not available" line the block's saved
     * fallback does. Written on admin requests (activation and existing installs both get it, and an
     * older copy is replaced); removed by uninstall.php when the plugin is deleted.
     * ponytail: best effort — an unwritable mu-plugins folder just means the text shows, as before.
     */
    public static function install_shortcode_fallback() { // NOSONAR php:S100 — WordPress snake_case naming
        $path = WPMU_PLUGIN_DIR . '/' . self::FALLBACK_FILE;
        $code = "<?php\n/**\n * Plugin Name: FastPix shortcode fallback\n * Description: While the FastPix plugin is deactivated, shows \"This video is not available right now.\" in place of [fastpix] shortcodes instead of the raw shortcode text. Removed when FastPix is deleted.\n */\n"
            . "add_action('init', function () {\n    if (!shortcode_exists('fastpix')) {\n        add_shortcode('fastpix', function () {\n"
            . "            return '<figure class=\"fastpix-embed fastpix-embed--fallback\"><figcaption>' . esc_html__('This video is not available right now.', 'fastpix') . '</figcaption></figure>';\n"
            . "        });\n    }\n}, 99);\n";
        if ((file_exists($path) && (string) file_get_contents($path) === $code) || !wp_mkdir_p(WPMU_PLUGIN_DIR) || !wp_is_writable(WPMU_PLUGIN_DIR)) {
            return;
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- one small file, no credentials prompt on admin_init
        file_put_contents($path, $code);
    }

    public static function register() {
        // v1 registered [fastpix playback_id=…] as an iframe; take the name over (aliases kept).
        remove_shortcode('fastpix');
        add_shortcode('fastpix', array(__CLASS__, 'shortcode'));

        if (function_exists('register_block_type')) {
            register_block_type(FASTPIX_PLUGIN_DIR . 'blocks/video', array(
                'render_callback' => array(__CLASS__, 'render_block'),
            ));
        }
    }

    public static function register_assets() {
        // hls.js ships in the plugin: without window.Hls the player fetches it from cdn.jsdelivr.net (guideline 8).
        wp_register_script(self::HLS, FASTPIX_PLUGIN_URL . 'assets/vendor/hls.min.js', array(), '1.7.3', array('strategy' => 'defer', 'in_footer' => true));
        // Consent is settled before the player starts, so a refusal also stops its analytics (guideline 7).
        wp_register_script(self::CONSENT, FASTPIX_PLUGIN_URL . 'assets/js/player-consent.js', array(), fastpix_asset_ver('assets/js/player-consent.js'), array('strategy' => 'defer', 'in_footer' => true));
        wp_register_script(self::SCRIPT, FASTPIX_PLUGIN_URL . 'assets/vendor/fastpix-player.js', array(self::HLS, self::CONSENT),
            trim((string) @file_get_contents(FASTPIX_PLUGIN_DIR . 'assets/vendor/fastpix-player.VERSION.txt')) ?: '1', array('strategy' => 'defer', 'in_footer' => true));
        wp_register_script(self::HELPER, FASTPIX_PLUGIN_URL . 'assets/js/player.js', array(self::CONSENT), fastpix_asset_ver('assets/js/player.js'), array('strategy' => 'defer', 'in_footer' => true));
        $cfg = array('progress' => esc_url_raw(rest_url(Fastpix_Rest::NS . '/progress')));
        if (is_user_logged_in()) {
            // The token refresh and progress writes must arrive AS this user
            // (SEC-014: player-config checks who may read the embedding post;
            // logged-in viewers are keyed by user id) — cookie + REST nonce.
            $cfg['nonce'] = wp_create_nonce('wp_rest');
        }
        wp_localize_script(self::HELPER, 'fastpixPlayerCfg', $cfg);
        wp_register_style('fastpix-embed', FASTPIX_PLUGIN_URL . 'assets/css/embed.css', array(), fastpix_asset_ver('assets/css/embed.css'));
    }

    /**
     * The post editor renders the canvas in an IFRAME (WP 6.3+), and scripts
     * from enqueue_block_editor_assets never run inside it — so the
     * <fastpix-player> custom element stayed undefined there and the
     * ServerSideRender preview collapsed to nothing. enqueue_block_assets is
     * the one hook WordPress carries into the canvas iframe.
     */
    public static function canvas_assets() {
        if (!is_admin()) {
            return;   // the front end enqueues per-render via Fastpix_Render_Player::enqueue()
        }
        self::register_assets();
        wp_enqueue_script(self::SCRIPT);
        wp_enqueue_style('fastpix-embed');
    }

    public static function editor_assets() {
        // The canvas is server-rendered (ServerSideRender) — it needs the player too.
        self::register_assets();
        wp_enqueue_script(self::SCRIPT);
        wp_enqueue_style('fastpix-embed');
        wp_localize_script(generate_block_asset_handle('fastpix/video', 'editorScript'), 'fastpixBlock', array(
            'libraryUrl'  => admin_url('admin.php?page=' . Fastpix_Library_Page::SLUG),
            'addMediaUrl' => admin_url('admin.php?page=' . Fastpix_Addmedia::SLUG),
            'lessonTypes' => Fastpix_Lms::enabled() ? Fastpix_Lms::lesson_types() : array(),   // the Completion panel registers only on these, and only with Course features on [QA X13]
        ));
    }

    /* ------------------------------------------------------------- entries */

    /**
     * [fastpix id="{mediaId}" …]. Aliases: videoid (UI-007), media_id,
     * playback_id (v1). Every block attribute as a lower-case attribute;
     * booleans "true"/"false". Streams: [fastpix streamid="…"].
     */
    public static function shortcode($atts) {
        $atts = is_array($atts) ? $atts : array();
        // Bare flags — [fastpix id="…" autoplay muted] — arrive as positional values; lift them to keys.
        foreach ($atts as $k => $v) {
            if (is_int($k) && is_string($v) && preg_match('/^[a-z_-]+$/i', $v)) {
                $atts[strtolower($v)] = 'true';
                unset($atts[$k]);
            }
        }
        $atts = array_change_key_case($atts, CASE_LOWER);
        // poster="5s" is a frame time, not an image URL.
        if (isset($atts['poster']) && preg_match('/^(\d+)s$/', trim((string) $atts['poster']), $pm)) {
            $atts['thumbnailtime'] = $pm[1];
            unset($atts['poster']);
        }

        $over = self::overrides_from_atts($atts);

        // Streams carry the same per-embed options — the live player and its
        // recording both honour controls/click/keyboard/accent.
        if (!empty($atts['streamid'])) {
            return self::render_live((string) $atts['streamid'], $over);
        }

        $id = '';
        foreach (array('id', 'videoid', 'media_id', 'mediaid', 'playback_id', 'playbackid') as $k) {
            if (!empty($atts[$k])) {
                $id = (string) $atts[$k];
                break;
            }
        }

        return self::render($id, $over, array('context' => 'shortcode'));
    }

    /** Map already-normalised shortcode attributes to render() overrides. */
    private static function overrides_from_atts($atts) {
        $map = array('autoplay' => 'autoplay', 'auto-play' => 'autoplay', 'auto_play' => 'autoplay', 'muted' => 'muted',   // auto-play: the player's own attribute name, copied from FastPix docs
                     'loop' => 'loop', 'controls' => 'controls',
                     'showchapters' => 'showChapters', 'chapters' => 'showChapters', 'showtranscript' => 'showTranscript',
                     'transcript' => 'showTranscript', 'captionsdefault' => 'captionsDefault', 'lazyload' => 'lazyLoad',
                     'accentcolour' => 'accentColour', 'accentcolor' => 'accentColour', 'poster' => 'poster',
                     'thumbnailtime' => 'thumbnailTime', 'thumbnail-time' => 'thumbnailTime', 'starttime' => 'startTime',
                     'aspectratio' => 'aspectRatio', 'clicktoplay' => 'clickToPlay', 'keyboard' => 'keyboard',
                     // Classic-editor lessons (TutorLMS's builder does not load Gutenberg,
                     // so the block panel is unreachable there) carry the completion
                     // options as shortcode attributes.
                     'complete_at' => 'completeAt', 'track_viewer' => 'trackViewer', 'trackviewer' => 'trackViewer');
        $over = array();
        foreach ($map as $k => $attr) {
            if (array_key_exists($k, $atts)) {
                $over[$attr] = $atts[$k];
            }
        }
        // Readable negative flags: [fastpix id=… nocontrols noclick nokeys]
        foreach (array('nocontrols' => 'controls', 'noclick' => 'clickToPlay', 'nokeys' => 'keyboard') as $flag => $attr) {
            if (!empty($atts[$flag]) && $atts[$flag] !== 'false') {
                $over[$attr] = false;
            }
        }
        return $over;
    }

    public static function render_block($attributes, $content = '') {
        $attributes = is_array($attributes) ? $attributes : array();
        $id = isset($attributes['videoId']) ? (string) $attributes['videoId'] : '';

        return self::render($id, $attributes, array('context' => 'block', 'fallback' => $content));
    }

    /* ------------------------------------------------------------- render */

    /**
     * @param string $identifier media id (canonical) or playback id
     * @param array  $overrides  per-embed settings (block attributes / shortcode)
     * @param array  $opts       context, fallback (saved static markup)
     */
    public static function render($identifier, $overrides = array(), $opts = array()) {
        $settings = self::settings($overrides);
        $fallback = isset($opts['fallback']) ? (string) $opts['fallback'] : '';

        $video  = $identifier === '' ? null : self::resolve($identifier);
        $result = self::render_gate($identifier, $video, $fallback);
        if ($result === null) {
            $result = self::render_playable($video, $settings, $fallback);
        }

        return $result;
    }

    /** The not-renderable cases: no id, unknown, deleted — a message or the saved fallback, never a blank frame. */
    private static function render_gate($identifier, $video, $fallback) {
        $result = null;
        if ($identifier === '') {
            $result = Fastpix_Render_Player::message(__('No video selected.', 'fastpix'), $fallback);
        } elseif (!$video) {
            // ERR-041: removed / unknown — the saved poster + link, never a blank frame.
            $result = $fallback !== '' ? $fallback : Fastpix_Render_Player::message(__('This video is not available right now.', 'fastpix'), '');
        } elseif (Fastpix_Sync::is_unavailable($video)) {   // deleted, or orphaned (404 on FastPix) — the library's "Unavailable" [QA L12]
            $result = $fallback !== '' ? $fallback : Fastpix_Render_Player::message(__('This video no longer exists on FastPix.', 'fastpix'), '');
        } elseif ($video['status'] === 'Failed') {   // never "still being processed" [QA L11]
            $result = $fallback !== '' ? $fallback : Fastpix_Render_Player::message(__('This video could not be processed.', 'fastpix'), '');
        } elseif ($video['status'] === 'Ready' && self::playback_id($video) === '') {   // its playback id was removed on the platform [QA L10]
            $result = $fallback !== '' ? $fallback : Fastpix_Render_Player::message(__('This video is not available right now.', 'fastpix'), '');
        }

        return $result;
    }

    /** A resolved, undeleted video: processing card until Ready, then public or protected markup. */
    private static function render_playable($video, $settings, $fallback) {
        $policy   = self::policy($video);   // server-side re-check [SEC-006]
        $playback = self::playback_id($video);
        Fastpix_Render_Player::note_usage($video);

        if ($video['status'] !== 'Ready' || $playback === '') {
            // Publish now; visitors see the saved poster until ready. [RULE-010]
            return Fastpix_Render_Player::processing($video, $policy, $settings, $fallback);
        }
        if ($policy === 'public') {
            return self::render_public($video, $playback, $settings);
        }

        return self::render_protected($video, $playback, $settings, $policy);
    }

    private static function render_public($video, $playback, $settings) {
        // The markup is post-specific on lessons (data-fp-post), so the key
        // carries the lesson id; markup carrying a per-user viewer-key must
        // never be cached at all. Signed markup stays out of this cache group.
        // Use the resolved lesson id, NOT get_the_ID() — an LMS renders the
        // lesson under the course post, so get_the_ID() is the course here.
        $lesson_id = Fastpix_Lms::rendering_lesson_id();
        if ($lesson_id && $settings['trackViewer'] && is_user_logged_in()) {
            Fastpix_Render_Player::enqueue();
            Fastpix_Render_Player::collect_seo($video, $playback);

            return Fastpix_Render_Player::player($video, $playback, $settings, 'public', null);
        }
        $key = 'embed:' . $video['id'] . ':' . hash('sha256', wp_json_encode($settings) . '|' . $video['updated_at'] . '|' . (int) $lesson_id);
        $that = $video;
        $html = Fastpix_Cache::remember('embed', $key, Fastpix_Cache::TTL_EMBED_PUBLIC, function () use ($that, $playback, $settings) {
            return Fastpix_Render_Player::player($that, $playback, $settings, 'public', null);
        });
        Fastpix_Render_Player::enqueue();
        Fastpix_Render_Player::collect_seo($video, $playback);

        return $html;
    }

    private static function render_protected($video, $playback, $settings, $policy) {
        // Private / DRM: signed at render, never cached, page excluded from caches. [SEC-005]
        Fastpix_Render_Player::nocache();

        $reason = $policy === 'drm' ? Fastpix_Render_Player::drm_blocker($video) : '';
        // A previously connected workspace's media: the connected pair's signing key
        // cannot sign it, so say so instead of minting a token the platform rejects. [ASSUME-092]
        if ($reason === '' && Fastpix_Videos_Rest::is_other_workspace($video)) {
            $reason = __('This video is not available right now.', 'fastpix');
        }
        if ($reason !== '') {
            return Fastpix_Render_Player::poster_message($video, $playback, $reason, $settings, false);
        }

        $tokens = Fastpix_Signing::tokens($playback, $policy === 'drm');
        if (is_wp_error($tokens)) {
            // ERR-062: platform unreachable / no key — poster + message, page renders.
            do_action('fastpix_log', 'render_unsigned', array('scope' => 'render', 'severity' => 'warning',
                'message' => 'Could not sign playback for ' . $video['media_id'] . ': ' . $tokens->get_error_message()));

            return Fastpix_Render_Player::poster_message($video, $playback, __('This video is not available right now.', 'fastpix'), $settings, false);
        }

        Fastpix_Render_Player::enqueue();

        return Fastpix_Render_Player::player($video, $playback, $settings, $policy, $tokens);
    }

    /** Live embeds live in Fastpix_Render_Live; kept here as the public API (shortcode, migration, tests). */
    public static function render_live($stream_id, $over = array()) {
        return Fastpix_Render_Live::render_live($stream_id, $over);
    }

    /* ------------------------------------------------------------ helpers */

    /** Site defaults ← per-embed overrides. [RULE-013] Values sanitised here, once. */
    public static function settings($overrides) {
        $s = self::DEFAULTS;
        foreach ((array) $overrides as $k => $v) {
            if (!array_key_exists($k, $s)) {
                continue;
            }
            if (is_bool($s[$k])) {
                $s[$k] = is_bool($v) ? $v : in_array(strtolower((string) $v), array('1', 'true', 'yes', 'on'), true);
            } elseif (is_int($s[$k]) || is_float($s[$k])) {
                $s[$k] = max(0, (float) $v);
            } else {
                $s[$k] = (string) $v;
            }
        }
        $s['accentColour'] = preg_match('/^#[0-9a-f]{3,8}$/i', $s['accentColour']) ? $s['accentColour'] : self::DEFAULTS['accentColour'];
        $s['poster']       = $s['poster'] !== '' ? esc_url_raw($s['poster']) : '';
        $s['aspectRatio']  = preg_match('/^\d+(\.\d+)?[:\/]\d+(\.\d+)?$/', $s['aspectRatio']) ? str_replace(':', '/', $s['aspectRatio']) : '';

        return $s;
    }

    /** media id (canonical) or playback id → video row. */
    public static function resolve($identifier) {
        global $wpdb;

        $identifier = (string) $identifier;
        $videos     = Fastpix_Schema::table('videos');
        $row = $wpdb->get_row($wpdb->prepare(self::SQL_SELECT_ALL . $videos . ' WHERE media_id = %s ORDER BY deleted_at IS NULL DESC LIMIT 1', $identifier), ARRAY_A);
        if (!$row) {
            $row = $wpdb->get_row($wpdb->prepare(
                'SELECT v.* FROM ' . $videos . ' v JOIN ' . Fastpix_Schema::table('playback_ids') . ' p ON p.video_id = v.id WHERE p.playback_id = %s LIMIT 1',
                $identifier
            ), ARRAY_A);
        }

        return $row ? $row : null;
    }

    /** The video's own policy is authoritative; a playback row's is a tie-break only. [SEC-006] */
    public static function policy($video) {
        $p = strtolower((string) $video['access_policy']);

        return in_array($p, array('public', 'private', 'drm'), true) ? $p : 'private';   // unknown ⇒ treated as protected
    }

    public static function playback_id($video) {
        return (string) Fastpix_Attachments::playback_id((int) $video['id']);
    }
}
