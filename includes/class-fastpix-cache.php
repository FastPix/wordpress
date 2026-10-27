<?php
/**
 * Cache wrapper — ARCH-09.
 *
 * One wrapper, two backends: the persistent object cache where a site has one,
 * transients otherwise. Keys carry a group version, so invalidating a group is
 * a version bump rather than a scan. Every value has a regeneration path
 * (remember()), and expensive rebuilds take a short lock so a cold key does not
 * start a stampede.
 */

namespace Fastpix;

if (!defined('WPINC')) {
    die;
}

class Fastpix_Cache {

    /** TTLs, SDD §21 via ARCH-09. Seconds. */
    const TTL_VIDEO         = 3600;    // video record + AI, touched by webhook/sync
    const TTL_LIST          = 300;     // library list
    const TTL_SEARCH        = 120;     // search results
    const TTL_PLAYER_CONFIG = 43200;   // stored player config, unsigned half only
    const TTL_POSTER        = 86400;   // poster/thumbnail URLs
    const TTL_EMBED_PUBLIC  = 3600;    // rendered embed markup, public video only
    const TTL_ANALYTICS     = 3600;    // site analytics rollup
    const TTL_WORKSPACE     = 900;     // workspace state

    const LOCK_TTL = 10;

    /**
     * Never cached here, by rule rather than by TTL:
     * - the /player-config RESPONSE (no-store, excluded at every layer)
     * - private embed markup
     * - signed URLs anywhere but the object cache, never below a 2-minute expiry
     * Callers own those decisions; this note is the reminder. [ARCH-09]
     */

    public static function using_object_cache() {
        return function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache();
    }

    /**
     * @param string $group  Cache group, e.g. 'videos', 'analytics'.
     * @param string $key    Key within the group.
     * @return mixed|false   False on miss.
     */
    public static function get($group, $key) {
        $composed = self::compose($group, $key);

        if (self::using_object_cache()) {
            return wp_cache_get($composed, 'fastpix');
        }

        return get_transient($composed);
    }

    public static function set($group, $key, $value, $ttl) {
        $composed = self::compose($group, $key);

        if (self::using_object_cache()) {
            return wp_cache_set($composed, $value, 'fastpix', (int) $ttl);
        }

        return set_transient($composed, $value, (int) $ttl);
    }

    public static function delete($group, $key) {
        $composed = self::compose($group, $key);

        if (self::using_object_cache()) {
            return wp_cache_delete($composed, 'fastpix');
        }

        return delete_transient($composed);
    }

    /**
     * Read through, regenerating on miss under a short lock. A caller that
     * cannot take the lock re-reads once (the holder usually just filled it)
     * and otherwise regenerates too — a duplicated rebuild is better than a
     * blocked page. [ARCH-09 stampede control]
     */
    public static function remember($group, $key, $ttl, $callback) {
        $value = self::get($group, $key);

        if ($value !== false) {
            return $value;
        }

        $lock   = self::compose($group, $key) . ':lock';
        $locked = self::acquire_lock($lock);

        if (!$locked) {
            // Another request holds the lock. Prefer its fresh value; if the key
            // is still cold, rebuild anyway — but do NOT release a lock we never
            // took, or we would delete the holder's lock.
            $value = self::get($group, $key);
            if ($value !== false) {
                return $value;
            }
        }

        $value = call_user_func($callback);

        if ($value !== false && $value !== null) {
            self::set($group, $key, $value, $ttl);
        }

        if ($locked) {
            self::release_lock($lock);
        }

        return $value;
    }

    /**
     * Invalidate a whole group by bumping its version — every composed key
     * changes at once, and the old entries expire on their own. [ARCH-09]
     */
    public static function flush_group($group) {
        $version = self::version($group) + 1;

        if (self::using_object_cache()) {
            wp_cache_set(self::version_key($group), $version, 'fastpix', 0);
        } else {
            set_transient(self::version_key($group), $version, 0);
        }

        return $version;
    }

    public static function version($group) {
        $key = self::version_key($group);

        $version = self::using_object_cache()
            ? wp_cache_get($key, 'fastpix')
            : get_transient($key);

        return $version === false ? 1 : (int) $version;
    }

    private static function version_key($group) {
        return 'fastpix_ver_' . $group;
    }

    /**
     * Composed key: group, its version, then a hash of the caller's key. Hashing
     * keeps the result inside the 172-character option-name limit that
     * transients inherit, whatever the caller passes.
     */
    private static function compose($group, $key) {
        return 'fpx_' . $group . '_' . self::version($group) . '_' . hash('sha256', (string) $key);
    }

    private static function acquire_lock($lock) {
        if (self::using_object_cache()) {
            return (bool) wp_cache_add($lock, 1, 'fastpix', self::LOCK_TTL);
        }

        if (get_transient($lock)) {
            return false;
        }

        return (bool) set_transient($lock, 1, self::LOCK_TTL);
    }

    private static function release_lock($lock) {
        if (self::using_object_cache()) {
            wp_cache_delete($lock, 'fastpix');

            return;
        }

        delete_transient($lock);
    }
}
