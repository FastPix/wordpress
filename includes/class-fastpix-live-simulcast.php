<?php
/**
 * Simulcast targets of a live stream — the /streams/{id}/simulcast routes,
 * split out of Fastpix_Live for size only. Fastpix_Live::register_routes()
 * wires them; the contract (POST {url, streamKey}, PUT isEnabled only) is
 * unchanged. [WF-008]
 */

namespace Fastpix;

if (!defined('WPINC')) {
    die;
}

class Fastpix_Live_Simulcast {

    private const PATH = '/live/streams/';

    public static function shape($t) {
        $t   = (array) $t;
        $key = (string) ($t['streamKey'] ?? '');
        // A third party's stream key never leaves the server in clear —
        // the UI only ever shows "key •••••" (edits are delete + re-add).
        $masked = '';
        if ($key !== '') {
            $masked = '••••' . (strlen($key) > 8 ? substr($key, -4) : '');
        }
        return array(
            'id'         => (string) ($t['simulcastId'] ?? ''),
            'url'        => (string) ($t['url'] ?? ''),
            'stream_key' => $masked,
            'enabled'    => !empty($t['isEnabled']),
        );
    }

    /** POST /streams/{id}/simulcast — add a target. RTMP(S) URLs only. */
    public static function add($request) {
        $url = trim((string) $request->get_param('url'));
        if (!preg_match('#^rtmps?://.#i', $url)) {
            return new \WP_Error('fastpix_simulcast_url', __('The target URL must start with rtmp:// or rtmps://.', 'fastpix-io'), array('status' => 400));
        }

        $stream_id = (string) $request->get_param('id');
        $client    = new Fastpix_Api_Client();
        $result    = $client->request('POST', self::PATH . rawurlencode($stream_id) . '/simulcast', array(
            'context' => 'interactive',
            'body'    => array('url' => $url, 'streamKey' => (string) $request->get_param('stream_key')),
        ));
        if (is_wp_error($result)) {
            return $result;
        }

        $target = self::shape((array) ($result['body']['data'] ?? array()));
        do_action('fastpix_audit_event', 'live_simulcast_added', array('stream_id' => $stream_id, 'simulcast_id' => $target['id']));

        return rest_ensure_response($target);
    }

    /** PATCH /streams/{id}/simulcast/{tid} — enable/pause. The platform's PUT takes only isEnabled. */
    public static function update($request) {
        $client = new Fastpix_Api_Client();
        $result = $client->request('PUT', self::PATH . rawurlencode((string) $request->get_param('id')) . '/simulcast/' . rawurlencode((string) $request->get_param('tid')), array(
            'context' => 'interactive',
            'body'    => array('isEnabled' => (bool) $request->get_param('enabled')),
        ));
        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response(self::shape((array) ($result['body']['data'] ?? array())));
    }

    /** DELETE /streams/{id}/simulcast/{tid} — remove a target. */
    public static function remove($request) {
        $stream_id = (string) $request->get_param('id');
        $tid       = (string) $request->get_param('tid');
        $client    = new Fastpix_Api_Client();
        $result    = $client->request('DELETE', self::PATH . rawurlencode($stream_id) . '/simulcast/' . rawurlencode($tid), array('context' => 'interactive'));
        if (is_wp_error($result)) {
            return $result;
        }
        do_action('fastpix_audit_event', 'live_simulcast_removed', array('stream_id' => $stream_id, 'simulcast_id' => $tid));

        return rest_ensure_response(array('deleted' => true));
    }
}
