<?php
/**
 * The per-upload settings snapshot (REQ-017) and the accepted-format
 * config, split out of Fastpix_Uploads for size only. Sessions, URL ingestion
 * and the migration scan all read the same snapshot.
 */

namespace Fastpix;

if (!defined('WPINC')) {
    die;
}

class Fastpix_Uploads_Settings {

    /** The rendition cap unless the batch says otherwise. */
    const DEFAULT_RESOLUTION = '1080p';

    /**
     * Watermark position → FastPix placement alignment (x, y). Center and bottom center are left
     * out on purpose: they sit under the play button and the seekbar.
     */
    const WATERMARK_PLACEMENT = array(
        'top-left'     => array('left', 'top'),
        'top-center'   => array('center', 'top'),
        'top-right'    => array('right', 'top'),
        'middle-left'  => array('left', 'middle'),
        'middle-right' => array('right', 'middle'),
        'bottom-left'  => array('left', 'bottom'),
        'bottom-right' => array('right', 'bottom'),
    );

    /** Extra vertical clearance on top of the chosen margin: a letterbox bar (top), plus the player's control row (bottom). */
    const WATERMARK_Y_EXTRA = array('top' => 5, 'bottom' => 8);

    /** The picker's labels, in WATERMARK_PLACEMENT order. */
    public static function watermark_positions() { // NOSONAR php:S100 — WordPress snake_case naming
        return array(
            'top-left'     => __('Top left', 'fastpix-io'),
            'top-center'   => __('Top center', 'fastpix-io'),
            'top-right'    => __('Top right', 'fastpix-io'),
            'middle-left'  => __('Middle left', 'fastpix-io'),
            'middle-right' => __('Middle right', 'fastpix-io'),
            'bottom-left'  => __('Bottom left', 'fastpix-io'),
            'bottom-right' => __('Bottom right', 'fastpix-io'),
        );
    }

    /** Gap from the video's edge, as a share of its size. */
    public static function watermark_margins() { // NOSONAR php:S100 — WordPress snake_case naming
        return array('3%' => __('Tight · 3%', 'fastpix-io'), '6%' => __('Normal · 6%', 'fastpix-io'), '10%' => __('Wide · 10%', 'fastpix-io'));
    }

    /** Watermark width as a share of the video's width; the height follows the image's own shape. */
    public static function watermark_sizes() { // NOSONAR php:S100 — WordPress snake_case naming
        return array(
            '6%'  => __('Small · 6%', 'fastpix-io'),
            '10%' => __('Medium · 10%', 'fastpix-io'),
            '15%' => __('Large · 15%', 'fastpix-io'),
            '20%' => __('Extra large · 20%', 'fastpix-io'),
        );
    }

    public static function watermark_opacities() { // NOSONAR php:S100 — WordPress snake_case naming
        return array('40%' => '40%', '55%' => '55%', '65%' => '65%', '80%' => '80%', '100%' => '100%');
    }

    /**
     * The `inputs` entry for the create call, or null for no watermark. Always carries `width`
     * (without it the mark renders at the image's native pixel size) and never `height`.
     */
    public static function watermark_input($settings) { // NOSONAR php:S100 — WordPress snake_case naming
        if (empty($settings['watermark_url'])) {
            return null;
        }
        list($x, $y) = self::WATERMARK_PLACEMENT[$settings['watermark_pos'] ?? 'top-left'] ?? self::WATERMARK_PLACEMENT['top-left'];
        $margin    = (int) ($settings['watermark_margin'] ?? 6);
        $placement = array('xAlign' => $x);
        if ($x !== 'center') {
            $placement['xMargin'] = $margin . '%';
        }
        $placement['yAlign'] = $y;
        if ($y !== 'middle') {
            $placement['yMargin'] = ($margin + self::WATERMARK_Y_EXTRA[$y]) . '%';
        }

        return array(
            'type'      => 'watermark',
            'url'       => $settings['watermark_url'],
            'placement' => $placement,
            'width'     => $settings['watermark_size'] ?? '10%',
            'opacity'   => $settings['watermark_opacity'] ?? '65%',
        );
    }

    public static function settings_snapshot($raw) {
        $raw = is_array($raw) ? $raw : array();

        $defaults = array(
            'title'          => '',            // optional batch title; blank → per-file name
            'watermark_url'  => '',            // optional image burned in at encode time; blank → no watermark input at all
            'watermark_pos'  => 'top-left',    // corner/edge the watermark sits in; see WATERMARK_PLACEMENT
            'watermark_size' => '10%',         // width as a share of the video's; see watermark_sizes()
            'watermark_margin'  => '6%',       // gap from the edge; see watermark_margins()
            'watermark_opacity' => '65%',
            'access_policy'  => 'public',      // Public/Private, default Public
            'quality_tier'   => 'standard',    // locked after upload (REQ-018)
            'max_resolution' => self::DEFAULT_RESOLUTION,
            'subtitles'      => 'off',         // owner 2026-09-18: off by default; the pop-up offers the site language
            'chapters'       => true,
            'summary'        => 'medium',
            'moderation'     => 'off',
            'downloadable'   => 'off',
            'normalize_audio'=> false,        // owner 2026-09-18: off by default
            'domain_lock'    => true,    // "Lock to this site"
            'domain_policy'  => 'deny',  // what sites NOT on the list get: deny (whitelist) | allow (blacklist) [ASSUME-073]
            'domain_allow'   => array(), // extra hosts allowed besides this site (deny policy)
            'domain_deny'    => array(), // hosts blocked (allow policy)
        );

        $clean = self::scalars_only(array_intersect_key(array_merge($defaults, $raw), $defaults), $defaults);
        $clean['access_policy'] = in_array($clean['access_policy'], array('public', 'private', 'drm'), true) ? $clean['access_policy'] : 'public';
        $clean['downloadable']  = isset(Fastpix_Videos_Rest::MP4_SUPPORT[$clean['downloadable']]) ? $clean['downloadable'] : 'off';
        if ($clean['access_policy'] === 'drm') {
            $clean['downloadable'] = 'off';   // DRM renditions are never downloadable as MP4 (platform docs) — mirrors the library rule
        }
        // Every knob clamped to what the platform/AI paths accept — the routes take `settings` as a bare object (QA U17).
        $clean['quality_tier']   = in_array($clean['quality_tier'], array('standard', 'pro', 'premium'), true) ? $clean['quality_tier'] : 'standard';   // mediaQuality enum, verified live 2026-09-20
        $clean['max_resolution'] = in_array($clean['max_resolution'], array('720p', self::DEFAULT_RESOLUTION, '1440p', '2160p'), true) ? $clean['max_resolution'] : self::DEFAULT_RESOLUTION;
        $clean['subtitles']      = isset(Fastpix_Utils::get_language_map()[$clean['subtitles']]) ? $clean['subtitles'] : 'off';
        $clean['summary']        = in_array($clean['summary'], array('off', 'short', 'medium', 'long'), true) ? $clean['summary'] : 'medium';
        $clean['moderation']     = ($clean['moderation'] === 'off' || $clean['moderation'] === false) ? 'off' : 'on';
        $clean['chapters']       = (bool) $clean['chapters'];
        $clean['normalize_audio']= (bool) $clean['normalize_audio'];
        $clean['domain_lock']    = (bool) $clean['domain_lock'];
        $clean['title']         = mb_substr(sanitize_text_field((string) $clean['title']), 0, 255);
        // Shape only here; whether FastPix can actually FETCH it is decided once per request, at the
        // create call (Fastpix_Uploads::platform_media_settings), where a refusal can name the reason.
        $clean['watermark_url'] = self::clean_watermark_url($clean['watermark_url']);
        $clean['watermark_pos'] = isset(self::WATERMARK_PLACEMENT[$clean['watermark_pos']]) ? $clean['watermark_pos'] : 'top-left';
        $clean['watermark_size']    = isset(self::watermark_sizes()[$clean['watermark_size']]) ? $clean['watermark_size'] : '10%';
        $clean['watermark_margin']  = isset(self::watermark_margins()[$clean['watermark_margin']]) ? $clean['watermark_margin'] : '6%';
        $clean['watermark_opacity'] = isset(self::watermark_opacities()[$clean['watermark_opacity']]) ? $clean['watermark_opacity'] : '65%';
        $clean['domain_policy'] = $clean['domain_policy'] === 'allow' ? 'allow' : 'deny';
        $clean['domain_allow']  = self::clean_hosts($clean['domain_allow']);
        $clean['domain_deny']   = array_values(array_diff(self::clean_hosts($clean['domain_deny']), $clean['domain_allow']));

        return $clean;
    }

    /** http/https only, and short enough for the column that carries the snapshot. Anything else is dropped. */
    private static function clean_watermark_url($url) { // NOSONAR php:S100 — WordPress snake_case naming
        $url = esc_url_raw(trim((string) $url), array('http', 'https'));

        return mb_strlen($url) > 1000 ? '' : $url;
    }

    /** The routes take `settings` as a bare object: an array where a scalar belongs is a 500 in isset(), so it falls back to the default (review 2026-09-20). */
    private static function scalars_only($clean, $defaults) {
        foreach ($clean as $k => $v) {
            if (!is_array($defaults[$k]) && (is_array($v) || is_object($v))) {
                $clean[$k] = $defaults[$k];
            }
        }

        return $clean;
    }

    /** Hostnames only (optionally *.wildcard), lowercased, deduped, capped at 20; scheme/path/port stripped, this site's own host dropped. */
    public static function clean_hosts($list) {
        $site = strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));
        $out  = array();
        foreach ((array) $list as $raw) {
            $h = strtolower(trim((string) $raw));
            $h = preg_replace('#^[a-z]+://#', '', $h);
            $h = preg_replace('#[/:].*$#', '', $h);
            if ($h === '' || $h === $site || !preg_match('/^(\*\.)?([a-z0-9-]+\.)+[a-z]{2,}$/', $h) || in_array($h, $out, true)) {
                continue;
            }
            $out[] = $h;
            if (count($out) === 20) {
                break;
            }
        }

        return $out;
    }

    public static function accepted_type($mime) {
        $config = self::mime_config();

        return in_array(strtolower($mime), array_map('strtolower', (array) $config['mime_types']), true);
    }

    /**
     * The mime-types config, read once per request. require_once is safe only
     * because the static cache guarantees a single evaluation — a bare second
     * require_once would return true, not the array.
     */
    public static function mime_config() {
        static $config = null;
        if (null === $config) {
            $config = require_once FASTPIX_PLUGIN_DIR . 'config/mime-types.php';
        }

        return $config;
    }

    /**
     * One probe per URL per request: a 50-file batch creates 50 sessions off one snapshot, and the
     * answer cannot change between them.
     *
     * @return true|\WP_Error
     */
    public static function watermark_reachable($url) { // NOSONAR php:S100 — WordPress snake_case naming
        static $seen = array();
        if (!isset($seen[$url])) {
            $verdict     = Fastpix_Url_Guard::validate_public_image_url($url);
            $seen[$url] = is_wp_error($verdict)
                ? new \WP_Error('fastpix_watermark_url', sprintf(
                    /* translators: %s: why the image URL was refused */
                    __('The watermark image cannot be used: %s', 'fastpix-io'),
                    $verdict->get_error_message()
                ), array('status' => 400))
                : true;
        }

        return $seen[$url];
    }

    /** POST /uploads/watermark-check — the same gate the create call applies, asked up front. */
    public static function watermark_check($request) { // NOSONAR php:S100 — WordPress snake_case naming
        $url = self::settings_snapshot(array('watermark_url' => (string) $request->get_param('url')))['watermark_url'];
        $ok  = $url === ''
            ? new \WP_Error('fastpix_watermark_url', __('Enter a full http:// or https:// image URL.', 'fastpix-io'), array('status' => 400))
            : self::watermark_reachable($url);

        return is_wp_error($ok) ? $ok : rest_ensure_response(array('ok' => true));
    }
}
