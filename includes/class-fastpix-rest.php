<?php
/**
 * REST namespace scaffolding — ARCH-02, SEC-011, SEC-012.
 *
 * One authorisation model in one place. Route files call register() and get the
 * whole contract applied for them: a permission callback that checks the
 * SPECIFIC capability, audits every refusal, and rate-limits; schema-declared
 * args; and the WordPress nonce handling that `X-WP-Nonce` already provides for
 * cookie-authenticated calls.
 *
 * Menu visibility is never authorisation, and neither is "the route is
 * undocumented" — a route registered without a permission callback is refused
 * here rather than shipped open.
 */

namespace Fastpix;

if (!defined('WPINC')) {
    die;
}

class Fastpix_Rest {

    /** [06 §B — binding] */
    const NS = 'fastpix/v1';

    /**
     * Register a route.
     *
     * @param string $route e.g. '/connection'
     * @param array  $args  Standard register_rest_route args, plus either
     *                      'capability' (admin route) or 'public_bucket'
     *                      (one of the three public routes).
     */
    public static function register($route, $args) {
        $args = self::prepare($args);

        return register_rest_route(self::NS, $route, $args);
    }

    /**
     * Apply the model to one or more route definitions. Exposed separately so
     * it can be checked without touching the WordPress route registry.
     */
    public static function prepare($args) {
        $multiple = isset($args[0]) && is_array($args[0]);
        $entries  = $multiple ? $args : array($args);

        foreach ($entries as $i => $entry) {
            $entry['permission_callback'] = self::permission_for($entry);
            unset($entry['capability'], $entry['public_bucket']);
            $entries[$i] = $entry;
        }

        return $multiple ? $entries : $entries[0];
    }

    /** The permission callback one route entry declares. */
    private static function permission_for($entry) {
        $capability = isset($entry['capability']) ? $entry['capability'] : null;
        $bucket     = isset($entry['public_bucket']) ? $entry['public_bucket'] : null;

        if ($capability === null && $bucket === null) {
            // SEC-011: no route ships without an authorisation decision.
            return '__return_false';
        }
        if ($capability !== null) {
            return self::capability_callback($capability, isset($entry['methods']) ? $entry['methods'] : '');
        }

        return self::public_callback($bucket);
    }

    /**
     * Admin routes: the specific capability, per-user rate limit, and an audit
     * record for every refusal. [SEC-011, SEC-012, REQ-092]
     */
    public static function capability_callback($capability, $methods = '') {
        return function ($request) use ($capability, $methods) {
            if (!current_user_can($capability)) {
                do_action('fastpix_audit_event', 'permission_refused', array(
                    'capability' => $capability,
                    'route'      => $request instanceof \WP_REST_Request ? $request->get_route() : '',
                    'method'     => $request instanceof \WP_REST_Request ? $request->get_method() : $methods,
                ));

                return new \WP_Error(
                    'fastpix_forbidden',
                    __('Your role cannot do that.', 'fastpix-io'),
                    array('status' => rest_authorization_required_code())
                );
            }

            $limited = Fastpix_Rate_Limiter::check('admin', 'user:' . get_current_user_id());

            return is_wp_error($limited) ? $limited : true;
        };
    }

    /**
     * The identifier half of a public bucket is this many times the address
     * half: it exists to stop a many-address flood on ONE playback id / stream /
     * video, not to cap how many viewers a popular one may have per minute.
     * Filterable through `fastpix_rate_limit` with the bucket "<name>:id".
     */
    const ID_MULTIPLIER = 50;

    /**
     * The three public routes: limited per address AND per identifier, never by
     * one of the two alone. [SEC-012]
     *
     * @param string $bucket 'player_config' | 'progress_address' | 'webhook'
     */
    public static function public_callback($bucket) {
        return function ($request) use ($bucket) {
            if ($bucket === 'webhook') {
                // (QA S3) Behind an undeclared proxy every client shares one address, so a flood here would 429 FastPix's real
                // deliveries. The receiver spends this bucket itself, and only for requests that fail the (cheap HMAC) signature check.
                return true;
            }
            $address = Fastpix_Rate_Limiter::address();
            Fastpix_Rate_Limiter::observe($address);
            $limited = Fastpix_Rate_Limiter::check($bucket, 'addr:' . $address);

            $identifier = is_wp_error($limited) ? '' : self::identifier($request);

            if ($identifier !== '') {
                list($limit, $window) = Fastpix_Rate_Limiter::limits($bucket);
                $limited = Fastpix_Rate_Limiter::check($bucket . ':id', 'id:' . $identifier, $limit * self::ID_MULTIPLIER, $window);
            }

            return is_wp_error($limited) ? $limited : true;
        };
    }

    /**
     * The per-identifier half of SEC-012: the thing being asked about, not the
     * asker — a playback id, a video, a webhook's workspace.
     */
    private static function identifier($request) {
        if (!$request instanceof \WP_REST_Request) {
            return '';
        }

        foreach (array('id', 'playback_id', 'video_id', 'viewer_key') as $param) {
            $value = $request->get_param($param);
            if (is_scalar($value) && (string) $value !== '') {
                return (string) $value;
            }
        }

        return '';
    }

    /**
     * Standard arg definitions, so every route declares validate/sanitize
     * callbacks rather than trusting input. [ARCH-02]
     */
    public static function arg($type, $args = array()) {
        $base = array(
            'type'              => $type,
            'required'          => false,
            'validate_callback' => 'rest_validate_request_arg',
            'sanitize_callback' => $type === 'string' ? 'sanitize_text_field' : 'rest_sanitize_request_arg',
        );

        return array_merge($base, $args);
    }

    /** Keyset pagination args shared by every list route. [REQ-039, API-P01] */
    public static function pagination_args() {
        return array(
            'after'    => self::arg('string'),                                    // keyset cursor
            'per_page' => self::arg('integer', array('default' => 25, 'minimum' => 1, 'maximum' => 100)),
        );
    }
}
