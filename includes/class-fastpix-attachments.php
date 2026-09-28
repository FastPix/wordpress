<?php
/**
 * Media Library bridge — ARCH-11, REQ-035.
 *
 * One proxy attachment per video: post_mime_type `video/fastpix`, no file on
 * disk, meta carrying the FastPix identity. The filters below make it behave
 * in the Media Library: URLs and thumbnails resolve to delivery endpoints,
 * nothing is written to disk, and private video is excluded from contexts that
 * cannot mint a token — it resolves to nothing rather than to a URL that would
 * leak.
 */

namespace Fastpix;

if (!defined('WPINC')) {
    die;
}

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery
// All SQL here runs against the plugin's own tables: identifiers come from the
// Fastpix_Schema::table() registry (a fixed whitelist, never request input) and
// every value travels through $wpdb->prepare(). Core APIs cannot query these tables.

class Fastpix_Attachments {

    const MIME = 'video/fastpix';

    /** Delivery hosts, filterable so the .io-vs-.com answer stays one line. */
    public static function stream_base() {
        return apply_filters('fastpix_stream_base_url', 'https://stream.fastpix.com');
    }

    public static function image_base() {
        return apply_filters('fastpix_image_base_url', 'https://images.fastpix.com');
    }

    public static function boot() {
        add_filter('wp_get_attachment_url', array(__CLASS__, 'attachment_url'), 10, 2);
        add_filter('wp_get_attachment_image_src', array(__CLASS__, 'image_src'), 10, 3);
        add_filter('image_downsize', array(__CLASS__, 'downsize'), 10, 3);
        add_filter('wp_calculate_image_srcset', array(__CLASS__, 'srcset'), 10, 5);
        add_filter('wp_prepare_attachment_for_js', array(__CLASS__, 'prepare_for_js'), 10, 2);
        add_filter('wp_generate_attachment_metadata', array(__CLASS__, 'generate_metadata'), 10, 2);
        add_action('delete_attachment', array(__CLASS__, 'on_delete'), 10, 1);
        add_filter('ajax_query_attachments_args', array(__CLASS__, 'query_args'));
        add_filter('rest_attachment_query', array(__CLASS__, 'exclude_foreign'));   // block inserter / third-party pickers
    }

    /* ------------------------------------------------------------ creation */

    /**
     * Create (or find) the proxy for a video row. No file, no disk write —
     * the attachment is identity, not storage. [ARCH-11]
     *
     * @return int Attachment id, 0 on failure.
     */
    public static function create_proxy($video_row_id) {
        global $wpdb;

        $video = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . Fastpix_Schema::table('videos') . ' WHERE id = %d', (int) $video_row_id
        ), ARRAY_A);
        if (!$video) {
            return 0;
        }

        if (!empty($video['attachment_id']) && get_post((int) $video['attachment_id'])) {
            return (int) $video['attachment_id'];
        }

        $playback = self::playback_id((int) $video['id']);

        $attachment_id = wp_insert_post(array(
            'post_type'      => 'attachment',
            'post_title'     => $video['title'] !== '' ? $video['title'] : $video['media_id'],
            'post_status'    => 'inherit',
            'post_mime_type' => self::MIME,
            'post_author'    => (int) $video['author_id'],
        ));

        if (!$attachment_id || is_wp_error($attachment_id)) {
            $attachment_id = 0;
        }

        if ($attachment_id) {
            update_post_meta($attachment_id, '_fastpix_video_id', (int) $video['id']);
            update_post_meta($attachment_id, '_fastpix_media_id', $video['media_id']);
            update_post_meta($attachment_id, '_fastpix_playback_id', $playback);
            update_post_meta($attachment_id, '_fastpix_duration', (float) $video['duration_seconds']);
            update_post_meta($attachment_id, '_fastpix_access_policy', $video['access_policy']);

            $wpdb->update(Fastpix_Schema::table('videos'), array(
                'attachment_id' => (int) $attachment_id,
                'updated_at'    => current_time('mysql', true),
            ), array('id' => (int) $video['id']));
        }

        return (int) $attachment_id;
    }

    public static function is_proxy($attachment_id) {
        return get_post_mime_type($attachment_id) === self::MIME;
    }

    /* ------------------------------------------------------------- filters */

    /** URL of the attachment = the delivery manifest. Private ⇒ nothing leakable. */
    public static function attachment_url($url, $attachment_id) {
        if (!self::is_proxy($attachment_id)) {
            return $url;
        }

        if (!self::is_public($attachment_id)) {
            return '';   // no token-minting here; signed at render
        }

        $playback = (string) get_post_meta($attachment_id, '_fastpix_playback_id', true);

        return $playback === '' ? '' : self::stream_base() . '/' . rawurlencode($playback) . '.m3u8';
    }

    public static function image_src($image, $attachment_id, $size) {
        if (!self::is_proxy($attachment_id)) {
            return $image;
        }

        $thumb = self::thumbnail_url($attachment_id, $size);

        return $thumb === '' ? $image : array($thumb, self::size_px($size)[0], self::size_px($size)[1], false);
    }

    public static function downsize($out, $attachment_id, $size) {
        if (!self::is_proxy($attachment_id)) {
            return $out;
        }

        $thumb = self::thumbnail_url($attachment_id, $size);
        if ($thumb === '') {
            return $out;
        }
        $px = self::size_px($size);

        return array($thumb, $px[0], $px[1], false);
    }

    /** No srcset for delivery-endpoint thumbnails — one URL serves every size. */
    public static function srcset($sources, $size_array, $image_src, $image_meta, $attachment_id) {
        return self::is_proxy($attachment_id) ? array() : $sources;
    }

    /** What the media modal sees: duration, thumbnail, and the FastPix identity. */
    public static function prepare_for_js($response, $attachment) {
        if (!self::is_proxy($attachment->ID)) {
            return $response;
        }

        $response['fastpix'] = array(
            'media_id'      => (string) get_post_meta($attachment->ID, '_fastpix_media_id', true),
            'playback_id'   => (string) get_post_meta($attachment->ID, '_fastpix_playback_id', true),
            'access_policy' => (string) get_post_meta($attachment->ID, '_fastpix_access_policy', true),
        );
        $response['fileLength'] = (string) get_post_meta($attachment->ID, '_fastpix_duration', true);

        $thumb = self::thumbnail_url($attachment->ID, 'medium');
        if ($thumb !== '') {
            $response['image'] = array('src' => $thumb, 'width' => 300, 'height' => 169);
            $response['thumb'] = array('src' => self::thumbnail_url($attachment->ID, 'thumbnail'), 'width' => 150, 'height' => 84);
            $response['icon']  = $thumb;
        }

        return $response;
    }

    /** Nothing to generate: there is no file. Stops WP probing the missing path. */
    public static function generate_metadata($metadata, $attachment_id) {
        return self::is_proxy($attachment_id) ? array() : $metadata;
    }

    /**
     * Deleting the proxy clears the video row's pointer. Whether the platform
     * copy goes too is the REQ-036 dialog; nothing here touches the platform
     * (deactivation/deletion never destroys remote video).
     */
    public static function on_delete($attachment_id) {
        global $wpdb;

        if (!self::is_proxy($attachment_id)) {
            return;
        }

        $wpdb->update(Fastpix_Schema::table('videos'), array(
            'attachment_id' => null,
            'updated_at'    => current_time('mysql', true),
        ), array('attachment_id' => (int) $attachment_id));
    }

    /** Proxies stay visible in the media modal alongside ordinary attachments. */
    public static function query_args($args) {
        // Only intervene when a mime filter would EXCLUDE video/fastpix while
        // asking for video — authors filtering "video" expect FastPix video too.
        if (isset($args['post_mime_type']) && $args['post_mime_type'] === 'video') {
            $args['post_mime_type'] = array('video', self::MIME);
        }

        return self::exclude_foreign($args);
    }

    /**
     * Proxies of a previously connected workspace stay out of every attachment
     * listing (media modal AJAX and the REST media query): the current credentials
     * cannot sign or manage them. Skipped for queries that cannot return a proxy
     * (a mime filter without video); the id list is cached in the videos group,
     * which every stamp/learn/apply flushes. [ASSUME-092]
     */
    public static function exclude_foreign($args) {
        $mime = $args['post_mime_type'] ?? '';
        $mimes = array_filter((array) $mime, 'strlen');
        if ($mimes && !array_filter($mimes, function ($m) { return strpos((string) $m, 'video') === 0 || $m === self::MIME; })) {
            return $args;
        }
        $foreign = Fastpix_Cache::remember('videos', 'foreign_attachments:' . Fastpix_Videos_Rest::connected_workspace(), HOUR_IN_SECONDS, function () {
            global $wpdb;
            return array_map('intval', $wpdb->get_col($wpdb->prepare(
                'SELECT attachment_id FROM ' . Fastpix_Schema::table('videos') . " WHERE attachment_id IS NOT NULL AND workspace_id <> '' AND workspace_id <> %s",
                Fastpix_Videos_Rest::connected_workspace()
            )));
        });
        if ($foreign) {
            $args['post__not_in'] = array_values(array_unique(array_merge((array) ($args['post__not_in'] ?? array()), $foreign)));
        }

        return $args;
    }

    /* ------------------------------------------------------------ helpers */

    private static function is_public($attachment_id) {
        $policy = (string) get_post_meta($attachment_id, '_fastpix_access_policy', true);

        return $policy === '' || $policy === 'public';
    }

    private static function thumbnail_url($attachment_id, $size) {
        if (!self::is_public($attachment_id)) {
            return '';   // a private poster is signed too [REQ-101]
        }

        $playback = (string) get_post_meta($attachment_id, '_fastpix_playback_id', true);
        if ($playback === '') {
            return '';
        }
        $px = self::size_px($size);

        return self::image_base() . '/' . rawurlencode($playback) . '/thumbnail.png?width=' . $px[0];
    }

    private static function size_px($size) {
        $map = array('thumbnail' => array(150, 84), 'medium' => array(300, 169), 'large' => array(1024, 576), 'full' => array(1280, 720));
        if (is_array($size)) {
            return array((int) $size[0], (int) (isset($size[1]) ? $size[1] : $size[0] * 9 / 16));
        }

        return isset($map[$size]) ? $map[$size] : $map['medium'];
    }

    public static function playback_id($video_row_id) {
        global $wpdb;

        return (string) $wpdb->get_var($wpdb->prepare(
            'SELECT playback_id FROM ' . Fastpix_Schema::table('playback_ids') . '
             WHERE video_id = %d AND deleted_at IS NULL ORDER BY id ASC LIMIT 1',
            (int) $video_row_id
        ));
    }
}
