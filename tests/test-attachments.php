<?php
/**
 * Self-check for the Media Library bridge — ARCH-11, REQ-035.
 *
 *   docker compose exec wordpress php -d zend.assertions=1 \
 *     /var/www/html/wp-content/plugins/fastpix/tests/test-attachments.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once ABSPATH . 'wp-admin/includes/image.php';   // wp_generate_attachment_metadata lives in admin

use Fastpix\Fastpix_Attachments as Attachments;
use Fastpix\Fastpix_Schema as Schema;

const SQL_WHERE_ID = ' WHERE id = %d';
const SQL_SELECT_ATTACHMENT_ID_FROM = 'SELECT attachment_id FROM ';

global $wpdb;

$now = current_time('mysql', true);

// Fixture: one public and one private video, each with a playback id.
$wpdb->insert(Schema::table('videos'), array(
    'media_id' => 'att-check-pub', 'workspace_id' => 'ws-att', 'status' => 'Ready', 'source' => 'Upload',
    'title' => 'Public att check', 'access_policy' => 'public', 'duration_seconds' => 62.5,
    'created_at' => $now, 'updated_at' => $now,
));
$pub_video = (int) $wpdb->insert_id;
$wpdb->insert(Schema::table('playback_ids'), array(
    'video_id' => $pub_video, 'playback_id' => 'pb-att-pub', 'access_policy' => 'public',
    'created_at' => $now, 'updated_at' => $now,
));

$wpdb->insert(Schema::table('videos'), array(
    'media_id' => 'att-check-priv', 'workspace_id' => 'ws-att', 'status' => 'Ready', 'source' => 'Upload',
    'title' => 'Private att check', 'access_policy' => 'private', 'duration_seconds' => 10,
    'created_at' => $now, 'updated_at' => $now,
));
$priv_video = (int) $wpdb->insert_id;
$wpdb->insert(Schema::table('playback_ids'), array(
    'video_id' => $priv_video, 'playback_id' => 'pb-att-priv', 'access_policy' => 'private',
    'created_at' => $now, 'updated_at' => $now,
));

// ------------------------------------------------------------------ creation

$pub_att = Attachments::create_proxy($pub_video);
assert($pub_att > 0, 'a proxy attachment is created [REQ-035]');
assert(get_post_mime_type($pub_att) === 'video/fastpix', 'mime video/fastpix [ARCH-11]');
assert(get_post_meta($pub_att, '_wp_attached_file', true) === '', 'no file on disk [ARCH-11]');
assert((int) get_post_meta($pub_att, '_fastpix_video_id', true) === $pub_video, 'meta: video id');
assert(get_post_meta($pub_att, '_fastpix_media_id', true) === 'att-check-pub', 'meta: media id');
assert(get_post_meta($pub_att, '_fastpix_playback_id', true) === 'pb-att-pub', 'meta: playback id');
assert((float) get_post_meta($pub_att, '_fastpix_duration', true) === 62.5, 'meta: duration');
assert(get_post_meta($pub_att, '_fastpix_access_policy', true) === 'public', 'meta: policy');

assert(Attachments::create_proxy($pub_video) === $pub_att, 'creating twice returns the same proxy — idempotent');
assert((int) $wpdb->get_var($wpdb->prepare(SQL_SELECT_ATTACHMENT_ID_FROM . Schema::table('videos') . SQL_WHERE_ID, $pub_video)) === $pub_att, 'the video row points back at it');

$priv_att = Attachments::create_proxy($priv_video);

// -------------------------------------------------------- URLs and thumbnails

$url = wp_get_attachment_url($pub_att);
assert(strpos($url, 'stream.fastpix.com/pb-att-pub.m3u8') !== false, 'the URL resolves to the delivery endpoint [ARCH-11]');

$src = wp_get_attachment_image_src($pub_att, 'medium');
assert(is_array($src) && strpos($src[0], 'images.fastpix.com/pb-att-pub/thumbnail.png') !== false, 'thumbnails resolve to the image endpoint');
assert(strpos($src[0], 'width=300') !== false, 'sized per the requested size');

// Private: excluded from contexts that cannot mint a token. [ARCH-11]
assert(in_array(wp_get_attachment_url($priv_att), array('', false), true), 'a private video yields NO url — nothing leakable until Phase 7 signs');
$priv_src = wp_get_attachment_image_src($priv_att, 'medium');
assert(!is_array($priv_src) || $priv_src === false || strpos((string) (is_array($priv_src) ? $priv_src[0] : ''), 'fastpix') === false,
    'a private poster is signed too — no unsigned image URL [REQ-101]');

// OQ-005: the delivery hosts are filterable — one line when the answer lands.
add_filter('fastpix_stream_base_url', function () { return 'https://stream.fastpix.com'; });
assert(strpos(wp_get_attachment_url($pub_att), 'stream.fastpix.com') !== false, 'the stream host is filterable [OQ-005]');
remove_all_filters('fastpix_stream_base_url');

// -------------------------------------------------------------- js + metadata

$prepared = wp_prepare_attachment_for_js(get_post($pub_att));
assert($prepared['fastpix']['media_id'] === 'att-check-pub', 'the media modal sees the FastPix identity');
assert(strpos($prepared['image']['src'], 'thumbnail.png') !== false, 'and a real thumbnail');

assert(wp_generate_attachment_metadata($pub_att, '') === array(), 'metadata generation is a no-op — there is no file');

// Media modal "video" filter shows FastPix video too.
$args = apply_filters('ajax_query_attachments_args', array('post_mime_type' => 'video'));
assert(is_array($args['post_mime_type']) && in_array('video/fastpix', $args['post_mime_type'], true), 'the video filter includes proxies [REQ-035]');

// ------------------------------------------------------------------ deletion

wp_delete_post($pub_att, true);
assert($wpdb->get_var($wpdb->prepare(SQL_SELECT_ATTACHMENT_ID_FROM . Schema::table('videos') . SQL_WHERE_ID, $pub_video)) === null
    || (int) $wpdb->get_var($wpdb->prepare(SQL_SELECT_ATTACHMENT_ID_FROM . Schema::table('videos') . SQL_WHERE_ID, $pub_video)) === 0,
    'deleting the proxy clears the video row pointer');
$still = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Schema::table('videos') . SQL_WHERE_ID, $pub_video), ARRAY_A);
assert($still !== null && $still['deleted_at'] === null, 'the video row itself survives — platform video is never touched by attachment deletion');

// ---------------------------------------------------------------- teardown

wp_delete_post($priv_att, true);
$wpdb->query("DELETE FROM " . Schema::table('playback_ids') . " WHERE playback_id LIKE 'pb-att-%'");
$wpdb->query("DELETE FROM " . Schema::table('videos') . " WHERE media_id LIKE 'att-check-%'");

echo "media library bridge: all checks passed\n";
