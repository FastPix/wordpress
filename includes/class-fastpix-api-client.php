<?php
/**
 * FastPix API client.
 *
 * The only component in the plugin that opens HTTP to FastPix. Everything else
 * goes through request().
 *
 * Returns an array on success (`status`, `body`, `headers`) and a WP_Error on
 * every failure, so callers use is_wp_error(). The WP_Error carries the
 * platform's `message` as its message and `status` + `description` in its data.
 */

namespace Fastpix;

if (!defined('WPINC')) {
    die;
}

class Fastpix_Api_Client {

    /**
     * Base URL: HTTPS only. Override without touching this file:
     * define('FASTPIX_API_BASE_URL', …) or the fastpix_api_base_url filter.
     */
    const BASE_URL = 'https://api.fastpix.com/v1';

    /** [06 §A] 10 s connect; 30 s read interactive; 60 s background. */
    const CONNECT_TIMEOUT     = 10;
    const TIMEOUT_INTERACTIVE = 30;
    const TIMEOUT_BACKGROUND  = 60;

    /** [ARCH-03] backoff 1,2,4,8,16,32,60 s full jitter; 2 attempts interactive, 7 background. */
    const BACKOFF               = array(1, 2, 4, 8, 16, 32, 60);
    const ATTEMPTS_INTERACTIVE  = 2;
    const ATTEMPTS_BACKGROUND   = 7;

    /** [ARCH-03, ERR-037] 5 consecutive failures in 60 s open the breaker for 5 minutes. */
    const BREAKER_THRESHOLD = 5;
    const BREAKER_WINDOW    = 60;
    const BREAKER_OPEN_FOR  = 300;

    const OPT_HEALTH        = 'fastpix_connection_health';
    const TRANSIENT_BREAKER = 'fastpix_api_breaker';
    const TRANSIENT_PAUSE   = 'fastpix_api_pause_until';

    private $token_id;
    private $secret;

    /**
     * Pass a candidate pair to test credentials that are NOT stored yet —
     * validate-before-store depends on it (FR-001). Omit both to use the
     * stored pair.
     */
    public function __construct($token_id = null, $secret = null) {
        $this->token_id = $token_id === null ? Fastpix_Credentials::token_id() : (string) $token_id;
        $this->secret   = $secret === null ? Fastpix_Credentials::secret() : (string) $secret;
        $this->stored   = ($token_id === null);
    }

    /** True when built from the stored pair — such a client stops once that pair is replaced. */
    private $stored = false;

    public static function base_url() {
        $base = defined('FASTPIX_API_BASE_URL') ? FASTPIX_API_BASE_URL : self::BASE_URL;

        return rtrim(apply_filters('fastpix_api_base_url', $base), '/');
    }

    /**
     * Perform a request, applying the ARCH-03 response policy.
     *
     * @param string $method GET|POST|PATCH|DELETE|PUT
     * @param string $path   e.g. '/on-demand'
     * @param array  $args   query, body, context ('interactive'|'background'),
     *                       idempotency_row_id, headers
     * @return array|\WP_Error ['status'=>int,'body'=>mixed,'headers'=>array]
     */
    public function request($method, $path, $args = array()) {
        $context    = isset($args['context']) ? $args['context'] : 'interactive';
        $background = ($context === 'background');

        $gate = $this->gate_error();
        if ($gate !== null) {
            return $gate;
        }

        $url        = self::base_url() . '/' . ltrim($path, '/');
        $url        = empty($args['query']) ? $url : add_query_arg($args['query'], $url);
        $attempts   = $background ? self::ATTEMPTS_BACKGROUND : self::ATTEMPTS_INTERACTIVE;
        $http_args  = $this->http_args($method, $args, $background);
        $result     = null;

        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            if ($attempt > 0) {
                $this->backoff_sleep($attempt - 1);
            }

            $result = $this->send($url, $http_args, $method, $path, $args);

            // Retryable: transport failure or 5xx. Everything else is terminal.
            if (!is_wp_error($result) || !in_array($result->get_error_code(), array('fastpix_unreachable', 'fastpix_server_error'), true)) {
                break;
            }
        }

        // A success read with a pair that was replaced while the retries ran
        // is the previous workspace's data — never hand it back. [ASSUME-092]
        if (!is_wp_error($result) && $this->stored && $this->token_id !== Fastpix_Credentials::token_id_fresh()) {
            $result = new \WP_Error('fastpix_pair_changed', __('The connection changed while this job was running.', 'fastpix-io'));
        }

        return $result;
    }

    /** The states that refuse a request before it is sent, or null to proceed. */
    private function gate_error() {
        $error = null;
        // A job that started under the previous pair must not keep paging the old
        // workspace (and teach it as the new one) after a connect. [ASSUME-092]
        if ($this->stored && $this->token_id !== Fastpix_Credentials::token_id_fresh()) {
            $error = new \WP_Error('fastpix_pair_changed', __('The connection changed while this job was running.', 'fastpix-io'));
        } elseif ($this->token_id === '' || $this->secret === '') {
            $error = new \WP_Error(
                'fastpix_not_connected',
                __('This site is not connected to FastPix.', 'fastpix-io')
            );
        } elseif (!$this->stored) {
            // A candidate pair is one deliberate click (Connect / Verify): it goes
            // out even while the breaker or a 429 pause holds the background back,
            // and its success closes them — otherwise a reconnect after an outage
            // is refused for 5 minutes and the wizard blames the firewall.
            $error = null;
        } elseif (($open_until = $this->breaker_open_until()) > time()) {
            // [ERR-037] Breaker open: fail immediately, do not spend a request.
            $error = new \WP_Error(
                'fastpix_breaker_open',
                __('FastPix is not responding. Requests are paused briefly.', 'fastpix-io'),
                array('retry_after' => $open_until - time())
            );
        } elseif (($pause_until = (int) get_transient(self::TRANSIENT_PAUSE)) > time()) {
            // [ERR-038] A 429 pauses the queue group; nothing new goes out until it lapses.
            $error = new \WP_Error(
                'fastpix_rate_limited',
                __('FastPix is rate limiting this site. Requests resume shortly.', 'fastpix-io'),
                array('retry_after' => $pause_until - time())
            );
        }

        return $error;
    }

    /**
     * FastPix lists page by `limit` (max 50) and `offset` = 1-based PAGE NUMBER
     * (`offset=0` is a 422); the body carries pagination.totalRecords /
     * currentOffset / offsetCount (= page count).
     */
    const PAGE_MAX = 50;

    /**
     * Walk an offset/limit collection.
     *
     * @param callable|null $per_page Called with each page's items; return false to stop.
     * @return array|\WP_Error All items, or everything gathered before the callback stopped.
     */
    public function paginate($path, $args = array(), $per_page = null) {
        $limit = isset($args['limit']) ? min(self::PAGE_MAX, max(1, (int) $args['limit'])) : self::PAGE_MAX;
        $page_no = isset($args['offset']) ? max(1, (int) $args['offset']) : 1;
        $items = array();

        do {
            $result = $this->fetch_page($path, $args, $limit, $page_no);

            if (is_wp_error($result)) {
                return $items ? $items : $result;
            }

            $page  = $this->page_items($result);
            $items = array_merge($items, $page);

            if ($per_page !== null && call_user_func($per_page, $page) === false) {
                return $items;
            }

            $pages = $this->page_count($result, $page, $limit, $page_no);
            $page_no++;
        } while (count($page) === $limit && $page_no <= $pages);

        return $items;
    }

    /** One GET of one list page, the limit/offset folded into the query. */
    private function fetch_page($path, $args, $limit, $page_no) {
        $query = array_merge(isset($args['query']) ? $args['query'] : array(), array('limit' => $limit, 'offset' => $page_no));

        return $this->request('GET', $path, array_merge($args, array('query' => $query)));
    }

    /** One page's items out of a list response. */
    private function page_items($result) {
        $body = is_array($result['body']) ? $result['body'] : array();

        return isset($body['data']) && is_array($body['data']) ? $body['data'] : array();
    }

    /** Total page count from a list response, inferred when the platform omits it. */
    private function page_count($result, $page, $limit, $page_no) {
        $body       = is_array($result['body']) ? $result['body'] : array();
        $pagination = isset($body['pagination']) && is_array($body['pagination']) ? $body['pagination'] : $body;
        if (isset($pagination['offsetCount'])) {
            return (int) $pagination['offsetCount'];
        }
        if ($page && count($page) === $limit) {
            return $page_no + 1;   // a full page with no count: assume one more
        }

        return $page_no;
    }

    /**
     * Capability probe: does this endpoint exist for this workspace? A missing
     * endpoint disables ONE feature rather than erroring everywhere, so the
     * answer is cached and consulted by the feature that needs it.
     * [ARCH-03, REQ-100]
     *
     * 404 and 501 mean "not available here". 401/403/429 and transport failures
     * are not answers about capability, so they are not cached as one.
     *
     * @return bool
     */
    public function supports($path, $method = 'GET') {
        $key    = 'probe:' . strtolower($method) . ':' . $path;
        $cached = Fastpix_Cache::get('capability', $key);

        if ($cached !== false) {
            return $cached === 'yes';
        }

        $result = $this->request($method, $path, array('query' => array('limit' => 1)));

        if (!is_wp_error($result)) {
            Fastpix_Cache::set('capability', $key, 'yes', DAY_IN_SECONDS);

            return true;
        }

        $data   = (array) $result->get_error_data();
        $status = isset($data['status']) ? (int) $data['status'] : 0;

        if ($result->get_error_code() === 'fastpix_not_found' || $status === 501) {
            Fastpix_Cache::set('capability', $key, 'no', DAY_IN_SECONDS);

            do_action('fastpix_log', 'api_capability_missing', array(
                'scope'    => 'sync',
                'endpoint' => $path,
                'message'  => sprintf('%s %s is not available on this workspace; the feature that needs it stays off.', $method, $path),
            ));
        }

        // Otherwise — unreachable, rate limited, unauthorised: no verdict
        // cached, ask again later.
        return false;
    }

    /** One attempt, with the full response policy applied to its outcome. */
    private function send($url, $http_args, $method = 'GET', $path = '', $args = array()) {
        $response = wp_remote_request($url, $http_args);

        // Transport failure — DNS, TLS, timeout. Counts toward the breaker. [ERR-037]
        if (is_wp_error($response)) {
            $this->record_failure();
            do_action('fastpix_log', 'api_unreachable', array('message' => $response->get_error_message()));

            return new \WP_Error(
                'fastpix_unreachable',
                __('FastPix did not respond.', 'fastpix-io'),
                array('description' => $response->get_error_message())
            );
        }

        $status  = (int) wp_remote_retrieve_response_code($response);
        $headers = wp_remote_retrieve_headers($response);
        $headers = is_object($headers) && method_exists($headers, 'getAll') ? $headers->getAll() : (array) $headers;
        $body    = json_decode(wp_remote_retrieve_body($response), true);

        if ($status >= 200 && $status < 300) {
            if (in_array($method, array('PATCH', 'DELETE'), true)) {
                // A queued edit of the same target must not replay over this newer one. [QA M8]
                Fastpix_Outbox::supersede($method, $path, isset($args['body']) && is_array($args['body']) ? $args['body'] : array());
            }
            $this->record_success();

            return array('status' => $status, 'body' => $body, 'headers' => $headers);
        }

        return $this->error_for_status($status, $response, $body);
    }

    /**
     * The platform's error envelope out of a non-2xx body — [06 §A]
     * {success:false,error:{code,message,description}}; message is shown,
     * description is logged and never surfaced.
     */
    private function platform_error($status, $body) {
        $platform    = isset($body['error']) && is_array($body['error']) ? $body['error'] : array();
        $message     = isset($platform['message']) ? (string) $platform['message'] : '';
        $description = isset($platform['description']) ? (string) $platform['description'] : '';
        $code        = isset($platform['code']) ? $platform['code'] : null;

        if ($description !== '') {
            do_action('fastpix_log', 'api_error', array(
                'status'      => $status,
                'code'        => $code,
                'description' => $description,
            ));
        }

        return array($message, $description, $code);
    }

    /** The non-2xx half of the ARCH-03 response policy, one WP_Error per status class. */
    private function error_for_status($status, $response, $body) {
        list($message, $description, $code) = $this->platform_error($status, $body);

        $error = function ($slug, $fallback, $extra = array()) use ($status, $message, $description, $code) {
            return new \WP_Error(
                $slug,
                $message !== '' ? $message : $fallback,
                array_merge(array('status' => $status, 'description' => $description, 'platform_code' => $code), $extra)
            );
        };

        // 401/403: no retry. Connection marked unhealthy, writing pauses, one
        // notice — and the stored credential is NOT cleared. [ERR-005, FR-005]
        // A 403 whose description says the token lacks a *permission* is a valid
        // token that cannot do this one thing — not a dead connection. [ERR-005]
        if ($status === 403 && stripos((string) $description, 'permission') !== false) {
            $out = $error('fastpix_forbidden', __('The access token does not have permission for this request.', 'fastpix-io'));
        } elseif ($status === 401 || $status === 403) {
            // Only the STORED pair can be unhealthy: a rejected candidate (Verify /
            // Update with a typo) says nothing about the pair the site runs on.
            if ($this->stored) {
                $this->mark_unhealthy($status);
            }
            $out = $error('fastpix_bad_credentials', __('FastPix rejected these credentials.', 'fastpix-io'));
        } elseif ($status === 429) {
            // 429: honour Retry-After and pause; the caller does not retry inline. [ERR-038]
            $retry_after = (int) wp_remote_retrieve_header($response, 'retry-after');
            $retry_after = $retry_after > 0 ? $retry_after : 60;
            set_transient(self::TRANSIENT_PAUSE, time() + $retry_after, $retry_after);
            $out = $error('fastpix_rate_limited', __('FastPix is rate limiting this site.', 'fastpix-io'), array('retry_after' => $retry_after));
        } elseif ($status === 404) {
            // 404: the caller marks its row orphaned; sync decides, never this class. [ERR-040, RULE-021]
            $out = $error('fastpix_not_found', __('FastPix has no such object.', 'fastpix-io'));
        } elseif ($status === 409) {
            // 409: the caller refetches and re-applies under §17 ownership rules. [ERR-039]
            $out = $error('fastpix_conflict', __('This object changed on FastPix.', 'fastpix-io'));
        } elseif ($status < 500) {
            // 400/422 and any other 4xx: no retry, the request itself is wrong.
            $out = $error('fastpix_bad_request', __('FastPix refused the request.', 'fastpix-io'));
        } else {
            $this->record_failure();
            $out = $error('fastpix_server_error', __('FastPix is not responding.', 'fastpix-io'));
        }

        return $out;
    }

    private function http_args($method, $args, $background) {
        $headers = array(
            'Authorization'           => 'Basic ' . base64_encode($this->token_id . ':' . $this->secret),
            'Content-Type'            => 'application/json',
            // Owner ruling 2026-09-08 (ASSUME-069): X-FastPix-Integration is sent only by
            // the connection check (Fastpix_Connection::validate), not on every call.
        );

        // Creates carry an idempotency key derived from the local row id, so a
        // retried POST cannot create a second object. [ARCH-03]
        if (!empty($args['idempotency_row_id'])) {
            $headers['Idempotency-Key'] = wp_hash('fastpix-idempotency|' . $args['idempotency_row_id']);
        }

        if (!empty($args['headers'])) {
            $headers = array_merge($headers, $args['headers']);
        }

        $http_args = array(
            'method'  => strtoupper($method),
            'headers' => $headers,
            'timeout' => $background ? self::TIMEOUT_BACKGROUND : self::TIMEOUT_INTERACTIVE,
            // ponytail: WP's cURL transport reuses `timeout` for the connect phase
            // (class-wp-http-curl.php:133), so the separate 10 s connect timeout is
            // applied by the http_api_curl hook below. Read by that hook only.
            'connect_timeout' => self::CONNECT_TIMEOUT,
        );

        if (isset($args['body'])) {
            $http_args['body'] = is_string($args['body']) ? $args['body'] : wp_json_encode($args['body']);
        }

        return $http_args;
    }

    /** Applies the 10 s connect timeout the transport would otherwise flatten. */
    public static function apply_connect_timeout($handle, $parsed_args) {
        if (!empty($parsed_args['connect_timeout']) && function_exists('curl_setopt')) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions -- wp_remote_* exposes no connect timeout; this rides its own cURL hook.
            curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, (int) $parsed_args['connect_timeout']);
        }
    }

    /** Full jitter: sleep anywhere in [0, schedule]. [ARCH-03] */
    private function backoff_sleep($index) {
        $seconds = self::BACKOFF[min($index, count(self::BACKOFF) - 1)];
        $seconds = (float) apply_filters('fastpix_api_backoff_seconds', wp_rand(0, (int) ($seconds * 1000)) / 1000, $index);

        if ($seconds > 0) {
            usleep((int) round($seconds * 1000000));
        }
    }

    private function breaker_open_until() {
        $breaker = get_transient(self::TRANSIENT_BREAKER);

        return is_array($breaker) && isset($breaker['open_until']) ? (int) $breaker['open_until'] : 0;
    }

    /** Success closes the breaker and clears unhealthy. [ERR-037] */
    private function record_success() {
        $was_down = (bool) get_transient(self::TRANSIENT_BREAKER) || (bool) get_option(self::OPT_HEALTH);
        delete_transient(self::TRANSIENT_BREAKER);

        if (get_option(self::OPT_HEALTH)) {
            delete_option(self::OPT_HEALTH);
        }
        if ($was_down) {
            // Recovery: the first success after an outage. The outbox flushes
            // on this, then sweeps repair the rest. [WF-015, ERR-037]
            do_action('fastpix_api_recovered');
        }
    }

    private function record_failure() {
        $now     = time();
        $breaker = get_transient(self::TRANSIENT_BREAKER);

        // Count CONSECUTIVE failures (a success clears the transient in
        // record_success). A time-window reset would miss a slow/timeout outage:
        // background requests time out ~60s apart, so a fixed 60s window resets
        // the counter before it reaches the threshold. The sliding TTL below
        // expires the streak only after a long quiet gap. [ERR-037]
        // A lapsed open window starts a NEW streak: the half-open probe that
        // failed is one failure, not the sixth.
        if (!is_array($breaker) || (!empty($breaker['open_until']) && (int) $breaker['open_until'] <= $now)) {
            $breaker = array('fails' => 0, 'first' => $now, 'open_until' => 0);
        }

        $breaker['fails']++;

        if ($breaker['fails'] >= self::BREAKER_THRESHOLD) {
            $breaker['open_until'] = $now + self::BREAKER_OPEN_FOR;
            do_action('fastpix_log', 'api_breaker_open', array('until' => $breaker['open_until']));
        }

        // Refreshed on each failure: the streak survives as long as failures keep
        // coming within this span (comfortably covers a 60s timeout + backoff).
        set_transient(self::TRANSIENT_BREAKER, $breaker, self::BREAKER_THRESHOLD * self::TIMEOUT_BACKGROUND + self::BREAKER_OPEN_FOR);
    }

    /**
     * Connection unhealthy: writing queue groups pause, one notice is raised,
     * the credential stays put. [ERR-005, FR-005]
     */
    private function mark_unhealthy($status) {
        update_option(self::OPT_HEALTH, array('state' => 'unhealthy', 'status' => (int) $status, 'at' => time()), false);
        do_action('fastpix_connection_unhealthy', $status);
    }

    public static function is_healthy() {
        return !get_option(self::OPT_HEALTH);
    }
}

add_action('http_api_curl', array(__NAMESPACE__ . '\\Fastpix_Api_Client', 'apply_connect_timeout'), 10, 2);
