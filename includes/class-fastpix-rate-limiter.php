<?php
/**
 * Rate limiter — SEC-012.
 *
 * Per address AND per identifier on the three public routes (player-config,
 * progress, webhook); per user on admin routes. One primitive, used by every
 * route, so a new route cannot quietly ship without a limit.
 *
 * ponytail: fixed-window counters. The known ceiling is the window edge — a
 * caller can spend two windows' worth across a boundary. That is acceptable for
 * abuse control at these limits; swap in a sliding window if a real attack
 * makes the edge matter.
 */

namespace Fastpix;

if (!defined('WPINC')) {
    die;
}

class Fastpix_Rate_Limiter {

    /**
     * Defaults, all filterable. Only the /progress figures are stated by spec
     * (RULE-032, API-P13); the player-config, webhook and admin numbers are
     * NOT SPECIFIED anywhere in the specs and are chosen here.
     */
    const LIMITS = array(
        // route bucket        => array(limit, window seconds)
        'progress_address'     => array(60, 60),        // RULE-032: 60/min per address
        'progress_viewer'      => array(1, 15),         // RULE-032: 1 write/viewer/video/15 s
        'progress_final'       => array(1, 3),          // the session's last beat (QA F7): still bounded, never dropped behind a regular beat
        'progress_viewer_keys' => array(50, 86400),     // RULE-032: viewer keys capped per address per day
        'player_config'        => array(120, 60),       // NOT SPECIFIED — chosen
        'webhook'              => array(600, 60),       // NOT SPECIFIED — chosen; 500/min burst must pass (REQ-123)
        'admin'                => array(120, 60),       // NOT SPECIFIED — chosen, per user
    );

    /** SEC-013: 10 rejected webhooks per address per minute ⇒ 15-minute block. */
    const BLOCK_SECONDS = 900;

    const GROUP = 'ratelimit';

    /**
     * Count one hit and decide.
     *
     * @param string $bucket     One of LIMITS, or any name when limit/window are given.
     * @param string $identifier Address, user id, viewer key — whatever is being limited.
     * @return true|\WP_Error 429 with retry_after when over the limit.
     */
    public static function check($bucket, $identifier, $limit = null, $window = null) {
        list($limit, $window) = self::resolve($bucket, $limit, $window);

        if (self::is_blocked($bucket, $identifier)) {
            return self::too_many($bucket, self::block_remaining($bucket, $identifier));
        }

        $window_start = (int) floor(time() / $window) * $window;
        $key          = $bucket . ':' . hash('sha256', (string) $identifier) . ':' . $window_start;
        $count        = (int) Fastpix_Cache::get(self::GROUP, $key);

        if ($count >= $limit) {
            return self::too_many($bucket, ($window_start + $window) - time());
        }

        // Store for the remainder of the window plus a second, so the key expires
        // on its own rather than being cleaned up.
        Fastpix_Cache::set(self::GROUP, $key, $count + 1, ($window_start + $window) - time() + 1);

        return true;
    }

    /** Read the current count without spending one. */
    public static function count($bucket, $identifier, $window = null) {
        list(, $window) = self::resolve($bucket, null, $window);
        $window_start   = (int) floor(time() / $window) * $window;

        return (int) Fastpix_Cache::get(self::GROUP, $bucket . ':' . hash('sha256', (string) $identifier) . ':' . $window_start);
    }

    /**
     * Block an identifier outright for a period — the webhook receiver's
     * 10-failures-per-minute rule lands here. [SEC-013]
     */
    public static function block($bucket, $identifier, $seconds = self::BLOCK_SECONDS) {
        Fastpix_Cache::set(self::GROUP, self::block_key($bucket, $identifier), time() + $seconds, $seconds);
        do_action('fastpix_log', 'rate_limit_block', array(
            'scope'   => 'security',
            'message' => sprintf('%s blocked for %d seconds', $bucket, $seconds),
        ));
    }

    public static function is_blocked($bucket, $identifier) {
        return self::block_remaining($bucket, $identifier) > 0;
    }

    public static function block_remaining($bucket, $identifier) {
        $until = (int) Fastpix_Cache::get(self::GROUP, self::block_key($bucket, $identifier));

        return max(0, $until - time());
    }

    public static function release($bucket, $identifier) {
        Fastpix_Cache::delete(self::GROUP, self::block_key($bucket, $identifier));
    }

    /**
     * The requesting address. Forwarding headers are NOT trusted by default —
     * an attacker forges their own. A site behind a proxy/CDN opts in with
     * define('FASTPIX_TRUSTED_PROXIES', '203.0.113.9, 198.51.100.0/24'): when
     * REMOTE_ADDR is a trusted proxy, CF-Connecting-IP wins, else the LAST
     * X-Forwarded-For entry (the one the trusted proxy appended). A loopback or
     * private-range REMOTE_ADDR is trusted without the constant (QA S3).
     * `fastpix_client_address` still filters the result. [SEC-012]
     */
    public static function address() {
        $address = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';

        if ($address !== '' && self::trusted_proxy($address)) {
            $forwarded = '';
            if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
                $forwarded = sanitize_text_field(wp_unslash($_SERVER['HTTP_CF_CONNECTING_IP']));
            } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                $hops      = array_map('trim', explode(',', sanitize_text_field(wp_unslash($_SERVER['HTTP_X_FORWARDED_FOR']))));
                $forwarded = (string) end($hops);
            }
            if (rest_is_ip_address($forwarded)) {
                $address = $forwarded;
            }
        }
        $address = apply_filters('fastpix_client_address', $address);

        return (string) rest_is_ip_address($address);
    }

    /**
     * Is this REMOTE_ADDR a loopback/private peer, or one of FASTPIX_TRUSTED_PROXIES (exact IPs, IPv4 CIDRs)?
     * A private direct peer can only be a local reverse proxy / container gateway / load balancer, so it is
     * trusted by default; public-address proxies (Cloudflare…) still need the constant. '*' is NOT accepted:
     * it would trust every direct client's forged header — ignored like any malformed entry. (QA S3)
     */
    public static function trusted_proxy($address) {
        $list = '127.0.0.0/8, 10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16, ::1, ' . (defined('FASTPIX_TRUSTED_PROXIES') ? (string) FASTPIX_TRUSTED_PROXIES : '');
        foreach (array_filter(array_map('trim', explode(',', $list))) as $entry) {
            if ($entry === $address) {
                return true;
            }
            // ponytail: IPv4 CIDR only; IPv6 ranges (incl. private fc00::/7) are listed as exact addresses.
            if (strpos($entry, '/') !== false && filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && self::in_cidr($address, $entry)) {
                return true;
            }
        }

        return false;
    }

    /** Is the IPv4 $address inside the IPv4 CIDR $entry? A malformed entry never matches (nor throws). */
    private static function in_cidr($address, $entry) {
        list($net, $bits) = explode('/', $entry, 2);
        if (!ctype_digit($bits) || (int) $bits > 32) {
            return false;   // a typo in the constant must not throw on every public route (review 2026-09-20)
        }
        $mask = (int) $bits === 0 ? 0 : (-1 << (32 - (int) $bits)) & 0xFFFFFFFF;

        return filter_var($net, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && ((ip2long($address) & $mask) === (ip2long($net) & $mask));
    }

    /** How many of the last public hits came from one address — the sample observe() keeps. */
    const OBSERVE_SAMPLE = 100;

    /** Hits needed before dominant_address() gives a verdict — low enough for a low-traffic site (QA S3). */
    const OBSERVE_MIN = 20;

    /**
     * Remember the address of a public hit (last 100). Site Health reads the
     * sample: when nearly every hit shares one address the site is behind a
     * proxy it has not told the plugin about, and every visitor shares a bucket.
     * ponytail: read-modify-write on one cache row, unlocked — a lost sample is
     * harmless for a heuristic.
     */
    public static function observe($address, $webhook = false) {
        $recent   = Fastpix_Cache::get(self::GROUP, 'recent_addresses');
        $recent   = is_array($recent) ? $recent : array();
        $recent[] = ($webhook ? 'w:' : '') . $address;   // verified deliveries count too, tagged (QA S3)
        Fastpix_Cache::set(self::GROUP, 'recent_addresses', array_slice($recent, -self::OBSERVE_SAMPLE), DAY_IN_SECONDS);
    }

    /** ['address' => string, 'share' => float 0..1, 'hits' => int] over the sample, or null while it is too small. */
    public static function dominant_address() {
        $recent = Fastpix_Cache::get(self::GROUP, 'recent_addresses');
        if (!is_array($recent) || count($recent) < self::OBSERVE_MIN) {
            return null;
        }
        $counts = array_count_values(array_filter(preg_replace('/^w:/', '', $recent), 'strlen'));
        if (!$counts) {
            return null;
        }
        arsort($counts);
        $top = (string) key($counts);

        // An address seen ONLY on deliveries is FastPix itself, not a proxy: a viewer must share it. (QA S3)
        return in_array($top, $recent, true)
            ? array('address' => $top, 'share' => reset($counts) / count($recent), 'hits' => count($recent))
            : null;
    }

    /** The effective [limit, window] of a bucket after the site's filter. */
    public static function limits($bucket) {
        return self::resolve($bucket, null, null);
    }

    private static function resolve($bucket, $limit, $window) {
        $defaults = isset(self::LIMITS[$bucket]) ? self::LIMITS[$bucket] : array(60, 60);
        $limit    = $limit === null ? $defaults[0] : $limit;
        $window   = $window === null ? $defaults[1] : $window;

        /** Site owners behind a CDN or a busy LMS may need different figures. */
        $resolved = apply_filters('fastpix_rate_limit', array((int) $limit, (int) $window), $bucket);

        return array(max(1, (int) $resolved[0]), max(1, (int) $resolved[1]));
    }

    private static function block_key($bucket, $identifier) {
        return 'block:' . $bucket . ':' . hash('sha256', (string) $identifier);
    }

    /** Excess is dropped with 429, never queued. [RULE-032] */
    private static function too_many($bucket, $retry_after) {
        $retry_after = max(1, (int) $retry_after);

        return new \WP_Error(
            'fastpix_too_many_requests',
            __('Too many requests. Try again shortly.', 'fastpix'),
            array('status' => 429, 'retry_after' => $retry_after, 'bucket' => $bucket)
        );
    }
}
