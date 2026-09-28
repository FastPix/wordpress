<?php
/**
 * Connection routes — API-P09 (/connection, /connection/test) and
 * API-P10 (/logs, /system-report). All under fastpix_manage_settings.
 */

namespace Fastpix;

if (!defined('WPINC')) {
    die;
}

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery
// All SQL here runs against the plugin's own tables: identifiers come from the
// Fastpix_Schema::table() registry (a fixed whitelist, never request input) and
// every value travels through $wpdb->prepare(). Core APIs cannot query these tables.

class Fastpix_Rest_Connection {

    public static function boot() {
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
    }

    public static function register_routes() {
        // POST /connection — validate-before-store; DELETE — disconnect. [WF-001]
        Fastpix_Rest::register('/connection', array(
            array(
                'methods'    => 'POST',
                'callback'   => array(__CLASS__, 'connect'),
                'capability' => Fastpix_Capabilities::MANAGE_SETTINGS,
                'args'       => array(
                    'token_id' => Fastpix_Rest::arg('string', array('required' => true)),
                    // The secret is write-only input; sanitize_text_field would
                    // mangle legitimate key material, so it is only trimmed.
                    'secret'   => Fastpix_Rest::arg('string', array(
                        'required'          => true,
                        'sanitize_callback' => function ($value) { return trim((string) $value); },
                    )),
                ),
            ),
            array(
                'methods'    => 'DELETE',
                'callback'   => array(__CLASS__, 'disconnect'),
                'capability' => Fastpix_Capabilities::MANAGE_SETTINGS,
            ),
        ));

        // POST /connection/workspace — the workspace id is explicit user entry,
        // UUID-validated, its own card/action.
        Fastpix_Rest::register('/connection/workspace', array(
            'methods'    => 'POST',
            'callback'   => function ($request) {
                $result = Fastpix_Connection::set_workspace_id((string) $request->get_param('workspace_id'));

                return is_wp_error($result) ? $result : rest_ensure_response($result);
            },
            'capability' => Fastpix_Capabilities::MANAGE_SETTINGS,
            'args'       => array(
                'workspace_id' => Fastpix_Rest::arg('string', array('required' => true)),
            ),
        ));

        // POST /connection/test — re-runs the validation read. [WF-001]
        Fastpix_Rest::register('/connection/test', array(
            'methods'    => 'POST',
            'callback'   => array(__CLASS__, 'test'),
            'capability' => Fastpix_Capabilities::MANAGE_SETTINGS,
        ));

        // GET /logs — recent records for the diagnostics surface. [API-P10]
        Fastpix_Rest::register('/logs', array(
            'methods'    => 'GET',
            'callback'   => array(__CLASS__, 'logs'),
            'capability' => Fastpix_Capabilities::MANAGE_SETTINGS,
            'args'       => Fastpix_Rest::pagination_args(),
        ));

        // POST /sync — on-demand sync trigger. [API-P09, WF-009 "15 min + on demand"]
        Fastpix_Rest::register('/sync', array(
            'methods'    => 'POST',
            'callback'   => function () {
                if (!Fastpix_Credentials::has_pair()) {
                    return new \WP_Error('fastpix_not_connected', __('This site is not connected to FastPix. Connect a workspace from the FastPix menu first.', 'fastpix'), array('status' => 409));
                }
                Fastpix_Jobs::enqueue('fastpix_new_media_sweep', array(), Fastpix_Jobs::GROUP_SYNC);

                return rest_ensure_response(array('queued' => true));
            },
            'capability' => Fastpix_Capabilities::MANAGE_SETTINGS,
        ));

        // GET /system-report — the one-click report. [API-P10, FR-081]
        Fastpix_Rest::register('/system-report', array(
            'methods'    => 'GET',
            'callback'   => array(__CLASS__, 'system_report'),
            'capability' => Fastpix_Capabilities::MANAGE_SETTINGS,
        ));
    }

    /**
     * The pair is validated with one read before anything is stored; a failure
     * returns the named error and the caller keeps the typed entry (it was
     * never persisted anywhere to begin with). [FR-001, FR-002]
     */
    public static function connect($request) {
        $token_id = (string) $request->get_param('token_id');
        // The wizard pre-fills the TRUNCATED id (FR-005) and lets the owner paste a
        // new secret under it: the masked form means "the token I already have".
        if (Fastpix_Credentials::has_pair() && $token_id === Fastpix_Credentials::masked_token_id()) {
            $token_id = Fastpix_Credentials::token_id();
        }
        // Settings renders the secret as the mask (ASSUME-107); the mask coming back
        // means "the secret I already have", so an untouched field re-verifies it.
        $secret = (string) $request->get_param('secret');
        if (Fastpix_Credentials::has_pair() && $secret === Fastpix_Credentials::SECRET_MASK) {
            $secret = Fastpix_Credentials::secret();
        }
        $result = Fastpix_Connection::connect($token_id, $secret);

        if (is_wp_error($result)) {
            $data = (array) $result->get_error_data();

            return new \WP_Error($result->get_error_code(), $result->get_error_message(), array_merge(
                $data,
                array('status' => isset($data['status']) && $data['status'] >= 400 ? $data['status'] : 400)
            ));
        }

        return rest_ensure_response($result);   // Fastpix_Connection::state() — secret already masked [REQ-003]
    }

    public static function disconnect() {
        return rest_ensure_response(Fastpix_Connection::disconnect());
    }

    public static function test() {
        $result = Fastpix_Connection::test();

        if (is_wp_error($result)) {
            $data = (array) $result->get_error_data();

            return new \WP_Error($result->get_error_code(), $result->get_error_message(), array_merge(
                $data,
                array('status' => isset($data['status']) && $data['status'] >= 400 ? $data['status'] : 400)
            ));
        }

        return rest_ensure_response($result);
    }

    /** Last N log records, newest first. Context is redacted at write time. [SEC-019] */
    public static function logs($request) {
        global $wpdb;

        if (!Fastpix_Schema::table_exists('logs')) {
            return rest_ensure_response(array('records' => array()));
        }

        $per_page = min(100, max(1, (int) $request->get_param('per_page') ?: 25));

        $records = $wpdb->get_results($wpdb->prepare(
            'SELECT timestamp, severity, scope, message, correlation_id, error_code, endpoint,
                    http_method, http_status, latency_ms, attempt, max_attempts, context
             FROM ' . Fastpix_Schema::table('logs') . '
             ORDER BY id DESC LIMIT %d',
            $per_page
        ), ARRAY_A);

        return rest_ensure_response(array('records' => $records));
    }

    public static function system_report() {
        return rest_ensure_response(Fastpix_Health::system_report());
    }
}
