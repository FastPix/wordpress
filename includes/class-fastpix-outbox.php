<?php
/**
 * Offline edit outbox — WF-015, TEST-025.
 *
 * When FastPix is unreachable, an author's edit is saved locally and its
 * platform call joins an ordered outbox of at most 500 entries; beyond that,
 * new edits are refused with the reason. Recovery (the breaker closing on the
 * first successful request) flushes the outbox oldest-first; the 15-minute
 * sweep is the belt-and-braces flush when no interactive request happens to
 * close the breaker.
 */

namespace Fastpix;

if (!defined('WPINC')) {
    die;
}

class Fastpix_Outbox {

    const OPTION = 'fastpix_outbox';
    const LIMIT  = 500;

    /** Availability failures queue; contract refusals (4xx) never do. */
    const QUEUEABLE = array('fastpix_unreachable', 'fastpix_server_error', 'fastpix_breaker_open', 'fastpix_rate_limited');

    public static function boot() {
        add_action('fastpix_api_recovered', array(__CLASS__, 'flush'));
        add_action('fastpix_new_media_sweep', array(__CLASS__, 'flush'));
    }

    /** Is this WP_Error the platform being unavailable (queue) or a real refusal (propagate)? */
    public static function should_queue($error) {
        return is_wp_error($error) && in_array($error->get_error_code(), self::QUEUEABLE, true);
    }

    /**
     * Append one platform call. Returns true, or WP_Error when the outbox is
     * full — the caller refuses the edit with that reason. [WF-015]
     */
    public static function queue($method, $path, $body = array(), $note = '') {
        // The whole read-append-write runs under an advisory lock: it is a single
        // wp_options row, so two edits racing during an outage would otherwise
        // read the same list and the second update_option would clobber the first
        // (a lost DELETE leaves the video on the platform forever).
        return self::with_lock(function () use ($method, $path, $body, $note) {
            $entries = self::retire(self::entries(), $method, $path, $body);   // M8: a newer edit of the same target retires the older queued one
            if (count($entries) >= self::LIMIT) {
                return new \WP_Error(
                    'fastpix_outbox_full',
                    __('FastPix is not responding and the queue of waiting changes is full (500). Try again when the connection is back.', 'fastpix'),
                    array('status' => 503)
                );
            }

            $entries[] = array('method' => $method, 'path' => $path, 'body' => $body, 'note' => $note, 'at' => time());
            update_option(self::OPTION, $entries, false);
            do_action('fastpix_log', 'outbox_queued', array(
                'scope' => 'sync', 'severity' => 'info',
                'message' => sprintf('Saved locally, platform call queued (%d waiting): %s', count($entries), $note ?: $method . ' ' . $path),
            ));

            return true;
        });
    }

    /**
     * M8: a call that REACHED the platform makes older queued calls to the same
     * target stale — a replay would put the old value back over the new one. The
     * API client calls this on every successful PATCH/DELETE, before the recovery
     * flush runs. A DELETE retires everything queued for the path; a PATCH
     * retires the fields it carried.
     */
    public static function supersede($method, $path, $body = array()) {
        if (!in_array($method, array('PATCH', 'DELETE'), true) || !self::count()) {
            return;
        }
        self::with_lock(function () use ($method, $path, $body) {
            update_option(self::OPTION, self::retire(self::entries(), $method, $path, $body), false);
        });
    }

    /** The entries a newer $method $path $body leaves standing. */
    private static function retire($entries, $method, $path, $body) {
        $out = array();
        foreach ($entries as $e) {
            if ($e['path'] === $path) {
                if ($method === 'DELETE') {
                    continue;
                }
                if ($method === 'PATCH' && $e['method'] === 'PATCH' && is_array($e['body']) && is_array($body)) {
                    $e['body'] = array_diff_key($e['body'], $body);
                    if (!$e['body']) {
                        continue;
                    }
                }
            }
            $out[] = $e;
        }

        return $out;
    }

    /**
     * Run $fn holding a DB advisory lock, so the outbox's read-modify-write of its
     * single option row is atomic across concurrent requests. A lock timeout
     * ($got = 0) still runs $fn — the race is rare and losing the lock is better
     * than blocking the edit.
     */
    private static function with_lock(callable $fn) {
        global $wpdb;
        $name = 'fastpix_outbox_' . substr(md5(DB_NAME), 0, 16);
        $got  = (int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $name, 5));
        try {
            return $fn();
        } finally {
            if ($got === 1) {
                $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
            }
        }
    }

    /** Oldest-first; the first still-failing entry stops the pass (order is the contract). */
    public static function flush() {
        static $flushing = false;   // fastpix_api_recovered fires from our own successful requests
        if ($flushing) {
            return;
        }
        $flushing = true;
        try {
            self::flush_entries();
        } finally {
            $flushing = false;
        }
    }

    private static function flush_entries() {
        $client = new Fastpix_Api_Client();
        while (true) {
            // Read the head under the lock; run the HTTP OUTSIDE it (never hold the
            // lock across a network call, or a queue() during an outage would block).
            $entry = self::with_lock(function () {
                $entries = self::entries();
                return $entries ? $entries[0] : null;
            });
            if ($entry === null) {
                return;
            }

            $result = $client->request($entry['method'], $entry['path'], array('context' => 'background', 'body' => $entry['body']));
            if (self::should_queue($result)) {
                break;   // still down — keep the rest, in order
            }
            // Success or a terminal refusal (the object changed or vanished
            // meanwhile): either way the entry is spent. The nightly deep
            // sweep reconciles whatever a refusal left behind. [WF-015]
            if (is_wp_error($result)) {
                do_action('fastpix_log', 'outbox_entry_refused', array(
                    'scope' => 'sync', 'severity' => 'warning',
                    'message' => sprintf('%s %s: %s', $entry['method'], $entry['path'], $result->get_error_message()),
                ));
            }
            // Remove exactly this head under the lock. A concurrent queue() only
            // appends to the tail, so the head is unchanged; the identity guard
            // avoids dropping the wrong entry if another flusher already shifted it.
            self::with_lock(function () use ($entry) {
                $entries = self::entries();
                if ($entries && $entries[0]['at'] === $entry['at']
                    && $entries[0]['method'] === $entry['method'] && $entries[0]['path'] === $entry['path'] && $entries[0]['body'] === $entry['body']) {
                    array_shift($entries);
                    update_option(self::OPTION, $entries, false);
                }
            });
        }
    }

    public static function entries() {
        $entries = get_option(self::OPTION, array());

        return is_array($entries) ? array_values($entries) : array();
    }

    public static function count() {
        return count(self::entries());
    }
}
