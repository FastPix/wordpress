<?php
/**
 * Self-check for Fastpix_Cache — ARCH-09.
 *
 *   docker compose exec wordpress php -d zend.assertions=1 \
 *     /var/www/html/wp-content/plugins/fastpix/tests/test-cache.php
 *
 * Uses its own cache group, so nothing the site cached is disturbed.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/class-fastpix-cache.php';

use Fastpix\Fastpix_Cache as Cache;

$group = 'selfcheck';

// ------------------------------------------------------------ get/set/delete

assert(Cache::get($group, 'absent') === false, 'a miss returns false');
assert(Cache::set($group, 'k', array('v' => 1), Cache::TTL_LIST) !== false, 'set stores');
assert(Cache::get($group, 'k') === array('v' => 1), 'a hit returns the value, structure intact');
Cache::delete($group, 'k');
assert(Cache::get($group, 'k') === false, 'delete removes the value');

// Long and awkward keys survive: the composed key is hashed, so it stays inside
// the option-name limit transients inherit.
$long = str_repeat('video-id-with-a-very-long-name/', 20);
Cache::set($group, $long, 'ok', 60);
assert(Cache::get($group, $long) === 'ok', 'a long key round-trips');
Cache::delete($group, $long);

// ------------------------------------------------------ group version bumps

Cache::set($group, 'a', 'first', 300);
Cache::set($group, 'b', 'second', 300);
$version = Cache::version($group);

$bumped = Cache::flush_group($group);
assert($bumped === $version + 1, 'flushing a group bumps its version [ARCH-09]');
assert(Cache::get($group, 'a') === false, 'every key in the group misses after a bump');
assert(Cache::get($group, 'b') === false, 'including the others');

// A different group is unaffected by the bump.
Cache::set('other', 'a', 'kept', 300);
Cache::flush_group($group);
assert(Cache::get('other', 'a') === 'kept', 'flushing one group leaves the rest alone');
Cache::delete('other', 'a');

// -------------------------------------------------------------- remember()

$calls = 0;
$build = function () use (&$calls) {
    $calls++;

    return 'built-' . $calls;
};

assert(Cache::remember($group, 'r', 60, $build) === 'built-1', 'a miss regenerates');
assert($calls === 1, 'the callback ran once');
assert(Cache::remember($group, 'r', 60, $build) === 'built-1', 'a hit is served from cache');
assert($calls === 1, 'the callback did not run again [ARCH-09 regeneration path]');

// A callback returning false is not cached — false is the miss signal, so
// storing it would make every later read regenerate anyway.
$falsey = Cache::remember($group, 'f', 60, function () { return false; });
assert($falsey === false, 'a false value is returned');
assert(Cache::get($group, 'f') === false, 'and not stored');

// The rebuild lock is released, so the next cold key is not blocked by the last.
Cache::delete($group, 'r');
assert(Cache::remember($group, 'r', 60, $build) === 'built-2', 'a released lock lets the next rebuild through');

// --------------------------------------------------------- TTLs from §21

assert(Cache::TTL_VIDEO === HOUR_IN_SECONDS, 'video record + AI cached 1 h [ARCH-09]');
assert(Cache::TTL_LIST === 5 * MINUTE_IN_SECONDS, 'library list 5 min');
assert(Cache::TTL_SEARCH === 2 * MINUTE_IN_SECONDS, 'search 2 min');
assert(Cache::TTL_PLAYER_CONFIG === 12 * HOUR_IN_SECONDS, 'stored player config 12 h');
assert(Cache::TTL_POSTER === DAY_IN_SECONDS, 'poster URLs 24 h');
assert(Cache::TTL_ANALYTICS === HOUR_IN_SECONDS, 'site analytics 1 h');
assert(Cache::TTL_WORKSPACE === 15 * MINUTE_IN_SECONDS, 'workspace state 15 min');

// ---------------------------------------------------------------- teardown

Cache::delete($group, 'r');
Cache::flush_group($group);

echo "cache: all checks passed\n";
