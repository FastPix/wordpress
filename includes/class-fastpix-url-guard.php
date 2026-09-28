<?php
/**
 * The per-URL SSRF gate [SEC-010, RULE-006] — split from Fastpix_Uploads_Ingest
 * to keep each class within the 20-method budget. Same behaviour; callers go
 * through Fastpix_Uploads::validate_public_video_url() or this class directly.
 */

namespace Fastpix;

if (!defined('WPINC')) {
    die;
}

class Fastpix_Url_Guard {   // NOSONAR php:S101 — WordPress class naming

    /** How many of a host's validated addresses the probe will try before calling it unreachable. */
    const MAX_PIN_ADDRESSES = 3;

    /**
     * The SSRF gate [SEC-010, RULE-006]: http/https only; resolve and refuse
     * loopback, link-local, private and reserved ranges; follow at most three
     * redirects, re-checking at every hop; must answer and look like video.
     *
     * @return true|\WP_Error Error message is the per-URL refusal reason.
     */
    public static function validate_public_video_url($url, $hops = 0) {   // NOSONAR php:S100 — WordPress snake_case naming
        return self::validate_public_url($url, $hops, 'video');
    }

    /**
     * The same gate for a watermark image: FastPix fetches it server-side, so a localhost, private
     * or login-protected URL is a silently watermark-less video unless it is refused here.
     *
     * @return true|\WP_Error
     */
    public static function validate_public_image_url($url, $hops = 0) { // NOSONAR php:S100 — WordPress snake_case naming
        return self::validate_public_url($url, $hops, 'image');
    }

    /** @param string $kind video|image — all that differs is the content type demanded at the end. */
    private static function validate_public_url($url, $hops, $kind) { // NOSONAR php:S100 — WordPress snake_case naming
        if ($hops > Fastpix_Uploads::MAX_REDIRECTS) {
            return new \WP_Error('fastpix_url_redirects', __('Too many redirects (more than 3).', 'fastpix'));
        }

        $response = self::probe_url($url);
        if (is_wp_error($response)) {
            return $response;
        }

        return self::head_verdict($response, $hops, $url, $kind);
    }

    /**
     * Shape checks, address resolution and the pinned HEAD probe for one URL.
     *
     * @return array|\WP_Error the HTTP response, or the per-URL refusal.
     */
    private static function probe_url($url) {   // NOSONAR php:S100 — WordPress snake_case naming
        $parts  = wp_parse_url($url);
        $result = null;
        if (!$parts || empty($parts['host'])) {
            $result = new \WP_Error('fastpix_url_invalid', __('Not a valid URL.', 'fastpix'));
        } elseif (!isset($parts['scheme']) || !in_array(strtolower($parts['scheme']), array('http', 'https'), true)) {
            $result = new \WP_Error('fastpix_url_scheme', __('Only http and https URLs are accepted.', 'fastpix'));
        } else {
            // Resolve and check the ADDRESS, not the name — every A and AAAA record,
            // so a dual-stack host cannot hide a loopback behind a clean IPv4. [SEC-010]
            $ips    = self::resolve_public_ips($parts['host']);
            $result = is_wp_error($ips) ? $ips : self::pinned_head($url, $parts, $ips);
        }

        return $result;
    }

    /**
     * Pin the HEAD to one validated address. Without this, curl/streams would
     * resolve the name a SECOND time and a rebinding DNS answer could point
     * the request at an internal host between the check above and the
     * connection (TOCTOU). CURLOPT_RESOLVE keeps the original hostname for SNI
     * and certificate validation while forcing the socket to the vetted IP.
     * [SEC-010]
     *
     * Every validated address is tried, not just the first: a multi-homed host (any Cloudflare
     * site has two) can have one address time out while the others answer, and curl's own failover
     * is exactly what pinning disables. Giving up on the first one reported "did not respond" for a
     * host that was reachable all along. (owner 2026-09-23)
     *
     * @param array $ips validated public addresses, tried in order.
     * @return array|\WP_Error the HTTP response, or the unreachable refusal.
     */
    private static function pinned_head($url, $parts, $ips) { // NOSONAR php:S100 — WordPress snake_case naming
        $response = null;
        foreach (array_slice((array) $ips, 0, self::MAX_PIN_ADDRESSES) as $ip) {
            $response = self::pinned_head_once($url, $parts, $ip);
            if (!is_wp_error($response)) {
                return $response;   // an HTTP answer, whatever its status: the verdict is the caller's
            }
        }

        return new \WP_Error('fastpix_url_unreachable', __('The URL did not respond.', 'fastpix'));
    }

    /** One probe, pinned to one already-validated address. */
    private static function pinned_head_once($url, $parts, $pin_ip) { // NOSONAR php:S100 — WordPress snake_case naming
        $host = $parts['host'];
        if (isset($parts['port'])) {
            $port = (int) $parts['port'];
        } else {
            $port = strtolower($parts['scheme']) === 'https' ? 443 : 80;
        }
        $pin = static function ($handle) use ($host, $port, $pin_ip) {
            if (function_exists('curl_setopt')) {
                // ponytail: pinning needs the curl transport (this plugin already
                // relies on it for connect timeouts). On the rare streams-only
                // host the name is re-resolved and pinning is a no-op — acceptable
                // ceiling; revisit if a streams-transport deployment appears.
                // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- SSRF guard: pins the already-validated IP on WP's own curl transport (http_api_curl); the WP HTTP API has no equivalent, and without it a rebinding DNS answer could re-point the probe at a private address.
                curl_setopt($handle, CURLOPT_RESOLVE, array($host . ':' . $port . ':' . $pin_ip));
            }
        };
        add_action('http_api_curl', $pin);
        $response = wp_remote_head($url, array('timeout' => 10, 'redirection' => 0));
        // HEAD refused (an S3 presigned URL is signed for GET only → 403; some CDNs answer 405/501):
        // FastPix fetches with GET, so ask that way — one byte, pinned the same. (QA U13)
        if (!is_wp_error($response) && in_array((int) wp_remote_retrieve_response_code($response), array(403, 405, 501), true)) {
            $response = wp_remote_get($url, array('timeout' => 10, 'redirection' => 0, 'headers' => array('Range' => 'bytes=0-0'), 'limit_response_size' => 1024));
        }
        remove_action('http_api_curl', $pin);

        return $response;
    }

    /** The verdict from a successful HEAD: follow redirects (re-checked), demand success + the right type. */
    private static function head_verdict($response, $hops, $url, $kind = 'video') { // NOSONAR php:S100 — WordPress snake_case naming
        $status = (int) wp_remote_retrieve_response_code($response);

        if (in_array($status, array(301, 302, 303, 307, 308), true)) {
            $location = wp_remote_retrieve_header($response, 'location');
            if (!$location) {
                $verdict = new \WP_Error('fastpix_url_redirect_blind', __('The URL redirects without a destination.', 'fastpix'));
            } else {
                // A relative Location is legal (RFC 7231) and resolved against the URL that sent it. (QA U16)
                $verdict = self::validate_public_url(\WP_Http::make_absolute_url((string) $location, $url), $hops + 1, $kind);
            }
        } elseif ($status === 401 || $status === 403) {
            // 403 is usually hotlink or bot protection rather than a login, and both refuse FastPix
            // the same way they refused this probe. Naming only the login sent people hunting for a
            // password that does not exist. (owner 2026-09-23)
            $verdict = new \WP_Error('fastpix_url_login', __('The URL is behind a login, or the host blocks other servers from fetching it — FastPix cannot fetch it either.', 'fastpix'));   // REQ-015
        } elseif ($status < 200 || $status >= 400) {
            /* translators: %d: HTTP status code */
            $verdict = new \WP_Error('fastpix_url_status', sprintf(__('The URL answered HTTP %d.', 'fastpix'), $status));
        } else {
            $verdict = self::type_verdict(strtolower((string) wp_remote_retrieve_header($response, 'content-type')), $kind);
        }

        return $verdict;
    }

    /** A 2xx answer: it must look like the kind asked for (an absent type is given the benefit of the doubt). */
    private static function type_verdict($type, $kind) { // NOSONAR php:S100 — WordPress snake_case naming
        $verdict = true;
        if ($kind === 'image') {
            if ($type !== '' && strpos($type, 'image/') !== 0 && strpos($type, 'application/octet-stream') !== 0) {
                /* translators: %s: content type served by the URL */
                $verdict = new \WP_Error('fastpix_url_not_image', sprintf(__('the URL serves %s, not an image.', 'fastpix'), explode(';', $type)[0]));
            }
        } elseif ($type !== '' && strpos($type, 'video/') !== 0
            && strpos($type, 'application/octet-stream') !== 0
            && strpos($type, 'application/x-mpegurl') !== 0) {
            /* translators: %s: content type served by the URL */
            $verdict = new \WP_Error('fastpix_url_not_video', sprintf(__('The URL serves %s, not video.', 'fastpix'), explode(';', $type)[0]));
        }

        return $verdict;
    }

    /**
     * Resolve a host to every A and AAAA address and refuse the lot if any one
     * of them is private or reserved. Returning all addresses (not just IPv4)
     * closes the dual-stack bypass; refusing on ANY bad address means a rebinding
     * name that mixes public and internal answers is rejected outright. [SEC-010]
     *
     * @return array|\WP_Error validated public IPs, or a refusal.
     */
    private static function resolve_public_ips($host) {   // NOSONAR php:S100 — WordPress snake_case naming
        $host = trim($host, '[]');   // an IPv6 literal arrives bracketed from the URL
        $ips  = filter_var($host, FILTER_VALIDATE_IP) ? array($host) : self::host_ips($host);

        $ips = array_values(array_unique(array_filter($ips)));
        if (!$ips) {
            return new \WP_Error('fastpix_url_unresolvable', __('The host could not be resolved.', 'fastpix'));
        }
        foreach ($ips as $ip) {
            if (!self::ip_is_public($ip)) {
                return new \WP_Error('fastpix_url_private', __('The URL points at a local or private address — FastPix cannot fetch it.', 'fastpix'));
            }
        }

        return $ips;
    }

    /** Every A and AAAA address for a hostname. */
    private static function host_ips($host) {   // NOSONAR php:S100 — WordPress snake_case naming
        $ips = array();
        $v4  = gethostbynamel($host);
        if (is_array($v4)) {
            $ips = array_merge($ips, $v4);
        }
        $aaaa = @dns_get_record($host, DNS_AAAA);
        if (is_array($aaaa)) {
            foreach ($aaaa as $rec) {
                if (!empty($rec['ipv6'])) {
                    $ips[] = $rec['ipv6'];
                }
            }
        }

        return $ips;
    }

    /**
     * True only for a genuinely public unicast address. Beyond PHP's private and
     * reserved flags this normalises a v4-mapped IPv6 to its embedded IPv4 (so
     * ::ffff:127.0.0.1 is seen as loopback) and refuses the ranges those flags
     * miss: CGNAT and multicast on v4, and loopback / link-local / ULA / NAT64 on
     * v6. [SEC-010]
     */
    private static function ip_is_public($ip) {   // NOSONAR php:S100 — WordPress snake_case naming
        // A v4-mapped IPv6 (::ffff:a.b.c.d) connects to the embedded IPv4; judge
        // it by that address, not the v6 wrapper.
        if (stripos($ip, '::ffff:') === 0) {
            $tail = substr($ip, 7);
            if (filter_var($tail, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $ip = $tail;
            }
        }

        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            $public = false;
        } elseif (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $public = self::ipv4_is_public($ip);
        } else {
            $public = self::ipv6_is_public($ip);
        }

        return $public;
    }

    /** The IPv4 ranges PHP's filter flags miss. [SEC-010] */
    private static function ipv4_is_public($ip) {   // NOSONAR php:S100 — WordPress snake_case naming
        $long = sprintf('%u', ip2long($ip));   // unsigned, 32-/64-bit safe
        $in   = static function ($cidr, $bits) use ($long) {
            $mask = 0xFFFFFFFF << (32 - $bits) & 0xFFFFFFFF;
            return ((int) $long & $mask) === (sprintf('%u', ip2long($cidr)) & $mask);
        };
        if ($in('100.64.0.0', 10)) { return false; }   // CGNAT   // NOSONAR php:S1313 — reserved range in the SSRF guard, not a destination
        if ($in('224.0.0.0', 3))   { return false; }   // multicast + reserved (224/4 + 240/4)   // NOSONAR php:S1313 — reserved range in the SSRF guard, not a destination
        return true;
    }

    /** IPv6: cover what the filter flags don't reliably reject across versions. [SEC-010] */
    private static function ipv6_is_public($ip) {   // NOSONAR php:S100 — WordPress snake_case naming
        $packed = @inet_pton($ip);
        $public = true;
        if ($packed === false || $ip === '::1' || $ip === '::') {
            $public = false;
        } else {
            $b0 = ord($packed[0]);
            if (($b0 & 0xFE) === 0xFC                                              // fc00::/7 ULA
                || ($b0 === 0xFE && (ord($packed[1]) & 0xC0) === 0x80)             // fe80::/10 link-local
                || strncmp($packed, inet_pton('64:ff9b::'), 12) === 0) {           // 64:ff9b::/96 NAT64   // NOSONAR php:S1313 — reserved range in the SSRF guard, not a destination
                $public = false;
            }
        }

        return $public;
    }
}
