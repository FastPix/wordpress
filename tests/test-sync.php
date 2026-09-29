<?php
/**
 * Self-check for Fastpix_Sync — ARCH-06, WF-009, FR-101, RULE-021/022/023.
 *
 *   docker compose exec wordpress php -d zend.assertions=1 \
 *     /var/www/html/wp-content/plugins/fastpix/tests/test-sync.php
 *
 * All fixture rows use media ids prefixed sync-check- and are removed at the end.
 */

require_once __DIR__ . '/bootstrap.php';

use Fastpix\Fastpix_Credentials as Creds;
use Fastpix\Fastpix_Schema as Schema;
use Fastpix\Fastpix_Sync as Sync;

const SQL_DELETE_FROM = 'DELETE FROM ';
const SQL_SELECT_ALL_FROM = 'SELECT * FROM ';
const SQL_WHERE_MEDIA_SYNC = " WHERE media_id LIKE 'sync-check-%'";
const TITLE_LOCAL_EDIT = 'Local edit';
const PLATFORM_CLOCK = '2026-08-14 03:00:00';
const TS_UPDATED_02 = '2026-08-14T02:00:00Z';
const TS_UPDATED_04 = '2026-08-14T04:00:00Z';
const TITLE_DASHBOARD_RENAMED = 'Dashboard renamed it';
const EP_ON_DEMAND_M8 = '/on-demand/m8';

global $wpdb;

$saved = array();
foreach (array(Creds::OPT_TOKEN_ID, Creds::OPT_SECRET, 'fastpix_workspace_seen_id', 'fastpix_workspace_seen_name') as $opt) {   // the learned workspace must survive fixtures
    $saved[$opt] = get_option($opt, null);
}
// Restore even when a failed assertion aborts the run — a leaked fixture pair once shadowed the live one.
register_shutdown_function(function () use (&$saved) {
    foreach ($saved as $opt => $value) { $value === null ? delete_option($opt) : update_option($opt, $value, false); }
});
// The sweeps below rewrite the live sync_state rows (cursors, last_success_at) — put them back as they were.
$sync_rows = $wpdb->get_results(SQL_SELECT_ALL_FROM . Schema::table('sync_state'), ARRAY_A);
register_shutdown_function(function () use ($sync_rows) {
    global $wpdb;
    $wpdb->query(SQL_DELETE_FROM . Schema::table('sync_state'));
    foreach ($sync_rows as $row) { $wpdb->insert(Schema::table('sync_state'), $row); }
});
Creds::store('4f27c4c4-9a1e-4c2f-9d33-2b8f0a7c1e55', 'sk_selfcheck_sync');

$mock = array('pages' => array(), 'default_body' => array('data' => array()), 'requests' => array());
add_filter('pre_http_request', function ($_pre, $_args, $url) use (&$mock) {
    $mock['requests'][] = $url;
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    // FastPix pages: offset = 1-based page number, limit ≤ 50 (verified 2026-08-19); 0 is a 422.
    $offset = isset($query['offset']) ? (int) $query['offset'] : 1;
    assert($offset >= 1 && (!isset($query['limit']) || (int) $query['limit'] <= 50), 'the client pages the way FastPix does: 1-based page number, limit ≤ 50');
    $body   = isset($mock['pages'][$offset]) ? $mock['pages'][$offset] : $mock['default_body'];

    return array('headers' => array(), 'body' => wp_json_encode($body), 'response' => array('code' => 200, 'message' => ''));
}, 10, 3);
add_filter('fastpix_api_backoff_seconds', '__return_zero');

// The fixtures' workspace is the connected one for the length of the run (the snapshot above restores the real value).
update_option(\Fastpix\Fastpix_Connection::OPT_WORKSPACE_SEEN_ID, 'ws-sync', false);
// An aborted earlier run may have left fixtures behind — start clean.
$wpdb->query(SQL_DELETE_FROM . Schema::table('playback_ids') . " WHERE playback_id LIKE 'pb-sync-check-%'");
$wpdb->query(SQL_DELETE_FROM . Schema::table('videos') . SQL_WHERE_MEDIA_SYNC);

function media($id, $status, $extra = array()) {
    return array_merge(array(
        'id' => $id, 'status' => $status, 'workspaceId' => 'ws-sync', 'title' => 'T ' . $id,
        'duration' => 12.5, 'aspectRatio' => '16:9', 'updatedAt' => '2026-08-14T01:00:00Z',
        'playbackIds' => array(array('id' => 'pb-' . $id, 'accessPolicy' => 'public')),
    ), $extra);
}

function video_row($media_id) {
    global $wpdb;

    return $wpdb->get_row($wpdb->prepare(
        SQL_SELECT_ALL_FROM . Schema::table('videos') . ' WHERE media_id = %s', $media_id
    ), ARRAY_A);
}

// ---------------------------------------------------- state machine [RULE-023]

assert(Sync::rank('Created') === 0 && Sync::rank('Processing') === 1 && Sync::rank('Ready') === 2 && Sync::rank('Failed') === 3, 'ranks follow created→processing→ready→terminal');
assert(Sync::rank('In Queue') === 1 && Sync::rank('Validating') === 1, 'every intermediate platform status ranks as processing');
assert(Sync::rank('BrandNewStatus') === 1, 'an unknown status degrades to processing, not a crash [WF-009]');

assert(Sync::can_advance('Created', 'Processing') && Sync::can_advance('Processing', 'Ready'), 'forward transitions pass');
assert(Sync::can_advance('Ready', 'Failed') && Sync::can_advance('Ready', 'Deleted'), 'after ready, only failed or deleted');
assert(!Sync::can_advance('Ready', 'Processing'), 'ready cannot regress [RULE-023]');
assert(!Sync::can_advance('Failed', 'Created'), 'created-after-failed is ignored [FR-101]');
assert(!Sync::can_advance('Deleted', 'Ready'), 'nothing advances out of deleted — the tombstone wins');
assert(Sync::can_advance('', 'Ready'), 'ready-before-created lands on ready, not an error');

// ---------------------------------------------- apply_media ownership [RULE-022]

$verdict = Sync::apply_media(media('sync-check-a', 'Processing'));
assert($verdict === 'created', 'an unknown record creates a row');
$row = video_row('sync-check-a');
assert($row['status'] === 'Processing', 'platform status stored verbatim [ARCH-03]');
assert($row['title'] === 'T sync-check-a', 'platform title seeds a new row [ASSUME-023]');
assert($row['access_policy'] === 'public', 'policy read from the playback id');
assert((float) $row['duration_seconds'] === 12.5, 'technical fields are platform-owned');

$pb = $wpdb->get_row($wpdb->prepare(SQL_SELECT_ALL_FROM . Schema::table('playback_ids') . ' WHERE playback_id = %s', 'pb-sync-check-a'), ARRAY_A);
assert($pb !== null && (int) $pb['video_id'] === (int) $row['id'], 'playback ids land in their table');

// WordPress owns the title thereafter.
// A title edit made in WordPress: the PATCH route also stamps the platform clock, so an older record still in flight cannot undo it. (QA #13)
$wpdb->update(Schema::table('videos'), array('title' => TITLE_LOCAL_EDIT, 'platform_updated_at' => PLATFORM_CLOCK), array('id' => $row['id']));
$verdict = Sync::apply_media(media('sync-check-a', 'Ready', array('updatedAt' => TS_UPDATED_02)));
assert($verdict === 'updated', 'a changed record updates');
$row = video_row('sync-check-a');
assert($row['title'] === TITLE_LOCAL_EDIT, 'the platform cannot overwrite a WordPress-owned title [RULE-022]');
assert($row['status'] === 'Ready', 'while platform-owned status advanced');

// Out-of-order webhook cannot regress the row.
Sync::apply_media(media('sync-check-a', 'Created', array('updatedAt' => TS_UPDATED_02)));
assert(video_row('sync-check-a')['status'] === 'Ready', 'a late created event cannot regress ready [RULE-023]');

assert(Sync::apply_media(media('sync-check-a', 'Ready', array('updatedAt' => TS_UPDATED_02))) === 'unchanged', 'an identical record is a no-op');

// -------------------------------------- dashboard edits become suggestions

// A record with NO updatedAt cannot be ordered against the local edit: the row keeps
// "Local edit", the platform value is STORED as a suggestion. [RULE-022]
$undated = media('sync-check-a', 'Ready', array('title' => TITLE_DASHBOARD_RENAMED)); unset($undated['updatedAt']);
Sync::apply_media($undated);
$row = video_row('sync-check-a');
assert($row['title'] === TITLE_LOCAL_EDIT, 'the local title survives an undated record [RULE-022]');
assert($row['suggested_title'] === TITLE_DASHBOARD_RENAMED, 'the platform edit is stored as a suggestion [ASSUME-032]');
assert($row['suggested_at'] !== null, 'stamped when it arrived');

// The same suggestion arriving again is a no-op; a platform title matching the
// local one suggests nothing.
Sync::apply_media($undated);
assert(video_row('sync-check-a')['suggested_title'] === TITLE_DASHBOARD_RENAMED, 'a repeated suggestion does not churn');
$matching = media('sync-check-a', 'Ready', array('title' => TITLE_LOCAL_EDIT)); unset($matching['updatedAt']);
Sync::apply_media($matching);
assert(video_row('sync-check-a')['suggested_title'] === TITLE_DASHBOARD_RENAMED, 'a matching title does not clear a pending suggestion — that is the owner\'s call');

// QA report #13 (owner ruling 2026-09-21): a title edited on the FastPix dashboard IS applied here —
// newer than what the row reflects, and no local title edit waiting in the outbox.
$stale = Sync::apply_media(media('sync-check-a', 'Ready', array('title' => 'Older record', 'updatedAt' => TS_UPDATED_02)));
assert(video_row('sync-check-a')['title'] === TITLE_LOCAL_EDIT, 'a record older than the local edit cannot put an old title back');
assert(video_row('sync-check-a')['platform_updated_at'] === PLATFORM_CLOCK, 'and cannot wind the row\'s platform clock back');
\Fastpix\Fastpix_Outbox::queue('PATCH', '/on-demand/sync-check-a', array('title' => TITLE_LOCAL_EDIT), 'title of sync-check-a');
Sync::apply_media(media('sync-check-a', 'Ready', array('title' => TITLE_DASHBOARD_RENAMED, 'updatedAt' => TS_UPDATED_04)));
assert(video_row('sync-check-a')['title'] === TITLE_LOCAL_EDIT, 'a local title edit still waiting in the outbox wins');
\Fastpix\Fastpix_Outbox::supersede('PATCH', '/on-demand/sync-check-a', array('title' => TITLE_LOCAL_EDIT));
Sync::apply_media(media('sync-check-a', 'Ready', array('title' => TITLE_DASHBOARD_RENAMED, 'updatedAt' => '2026-08-14T05:00:00Z')));
$row = video_row('sync-check-a');
assert($row['title'] === TITLE_DASHBOARD_RENAMED, 'a dashboard rename is reflected in WordPress');
assert($row['suggested_title'] === null, 'and the pending suggestion is cleared — it has been applied');
$wpdb->update(Schema::table('videos'), array('title' => TITLE_LOCAL_EDIT), array('id' => $row['id']));   // the sections below expect the local title

// Playback ids: an ABSENT key (list-endpoint shape) tombstones nothing; an explicitly
// EMPTY list means the only id was deleted — tombstone it and bump the row. [L10]
$pb_live = function () use ($wpdb) { return $wpdb->get_var("SELECT deleted_at IS NULL FROM " . Schema::table('playback_ids') . " WHERE playback_id = 'pb-sync-check-a'") === '1'; };
$no_key = media('sync-check-a', 'Ready', array('title' => TITLE_LOCAL_EDIT, 'updatedAt' => TS_UPDATED_04));
unset($no_key['playbackIds']);
Sync::apply_media($no_key);
assert($pb_live(), 'an absent playbackIds key tombstones nothing [L10]');
assert(Sync::apply_media(array('playbackIds' => array()) + $no_key) === 'updated' && !$pb_live(), 'an empty playbackIds list tombstones the last id and counts as a change [L10]');
Sync::apply_media(media('sync-check-a', 'Ready', array('title' => TITLE_LOCAL_EDIT, 'updatedAt' => TS_UPDATED_04)));
assert($pb_live(), 'and the id revives when the platform lists it again');


Sync::tombstone_media('sync-check-a');
$row = video_row('sync-check-a');
assert($row['deleted_at'] !== null, 'deletion tombstones, never drops [spec 07]');
assert(Sync::apply_media(media('sync-check-a', 'Ready')) === 'blocked', 'a late webhook cannot recreate a removed video [RULE-022]');
assert(video_row('sync-check-a')['deleted_at'] !== null, 'the tombstone survives');

// ---------------------------------------------------- orphans surfaced [RULE-021]

Sync::apply_media(media('sync-check-orphan', 'Ready'));
Sync::mark_orphaned('sync-check-orphan');
$row = video_row('sync-check-orphan');
assert($row['error_code'] === 'orphaned', 'an orphan is marked');
assert($row['deleted_at'] === null, 'and NOT removed — that is the owner\'s decision [RULE-021]');

// ------------------------------------- new-media sweep stops at known page

// A FULL first page (50 items) so the walk continues to page two; a short
// page means the list ended and would stop any paginator.
$first_page = array(media('sync-check-n1', 'Ready'), media('sync-check-n2', 'Ready'));
for ($i = count($first_page); $i < 50; $i++) {
    $first_page[] = media('sync-check-fill-' . $i, 'Ready');
}
// Responses carry the binding pagination block; paginate() relies on it (offsetCount = page count).
$mock['pages'] = array(
    1 => array('data' => $first_page, 'pagination' => array('totalRecords' => 51, 'currentOffset' => 1, 'offsetCount' => 2)),
    2 => array('data' => array(media('sync-check-n3', 'Ready')), 'pagination' => array('totalRecords' => 51, 'currentOffset' => 2, 'offsetCount' => 2)),
);
$mock['requests'] = array();
Sync::new_media_sweep();
assert(video_row('sync-check-n1') !== null && video_row('sync-check-n3') !== null, 'the sweep files unknown media [WF-009]');
assert(count($mock['requests']) === 2, 'an unknown page keeps the walk going');

// Second run: page 0 is now fully known → stop, page 100 never requested.
$mock['requests'] = array();
Sync::new_media_sweep();
assert(count($mock['requests']) === 1, 'the sweep stops at the first fully known page [WF-009]');

$state = Sync::sync_state('new_media');
assert(!empty($state['last_success_at']), 'the sweep records its run [DATA-010]');

// ------------------------------------------------- deep sweep diff + resume

// Local row is stale (platform_updated_at behind) → deep sweep re-applies.
$wpdb->update(Schema::table('videos'), array('platform_updated_at' => '2026-08-13 00:00:00', 'access_policy' => 'private'), array('media_id' => 'sync-check-n1'));
$mock['pages'] = array(1 => array('data' => array(
    media('sync-check-n1', 'Ready', array('updatedAt' => '2026-08-14T03:00:00Z')),
    media('sync-check-n2', 'Ready'),
    media('sync-check-n3', 'Ready'),
)));
Sync::deep_sweep();
$row = video_row('sync-check-n1');
assert($row['platform_updated_at'] === PLATFORM_CLOCK, 'a drifted row is re-applied from the platform [WF-009]');
assert($row['access_policy'] === 'public', 'the deep sweep is the universal repair');

$state = Sync::sync_state('deep_sweep');
assert(!empty($state['last_success_at']), 'a completed sweep records success');
assert($state['watermark_id'] === '0', 'and resets its offset for the next night');

// Budget: a spent action budget stores the offset and hands off instead of running on.
$big = array();
for ($i = 0; $i < 50; $i++) {
    $big[] = media('sync-check-bulk-' . $i, 'Ready');
}
$mock['pages'] = array(1 => array('data' => $big), 2 => array('data' => array()));
Sync::deep_sweep(array('calls' => Fastpix\Fastpix_Sync::SWEEP_MAX_CALLS, 'begun' => time()));
$state = Sync::sync_state('deep_sweep');
assert($state !== null, 'sync state row exists');
// With the call budget already spent, the sweep must not have requested anything.
$before = count($mock['requests']);
Sync::deep_sweep(array('calls' => Fastpix\Fastpix_Sync::SWEEP_MAX_CALLS, 'begun' => time()));
assert(count($mock['requests']) === $before, 'a spent nightly budget spends no further calls [WF-009]');
// M19: while a hand-off chain is still walking, the nightly trigger (no args) yields instead of walking beside it.
set_transient(Fastpix\Fastpix_Sync_Sweeps::DEEP_HANDOFF, time(), 60);
$before = count($mock['requests']);
Sync::deep_sweep();
assert(count($mock['requests']) === $before, 'M19: the nightly trigger yields to a running hand-off chain');
delete_transient(Fastpix\Fastpix_Sync_Sweeps::DEEP_HANDOFF);

// ------------------------------------------------------------- audit orphans

Sync::apply_media(media('sync-check-gone', 'Ready'));
$mock['pages'] = array(1 => array('data' => array(
    media('sync-check-n1', 'Ready', array('updatedAt' => '2026-08-14T03:00:00Z')),
)));
// Platform list no longer contains n2/n3/gone/orphan → audit surfaces them.
Sync::audit();
foreach (array('sync-check-gone', 'sync-check-n2', 'sync-check-n3') as $media_id) {
    $row = video_row($media_id);
    assert($row['error_code'] === 'orphaned', "{$media_id} surfaced as an orphan [WF-009]");
    assert($row['deleted_at'] === null, 'and not deleted [RULE-021]');
}
$reported = $wpdb->get_var('SELECT COUNT(*) FROM ' . Schema::table('logs') . " WHERE error_code = 'consistency_audit_complete'");
assert((int) $reported >= 1, 'the audit REPORTS what it changed [WF-009]');

// ------------------------------------------------ outbox: a newer edit retires the older (M8)

$outbox_saved = get_option(Fastpix\Fastpix_Outbox::OPTION, null);
delete_option(Fastpix\Fastpix_Outbox::OPTION);
Fastpix\Fastpix_Outbox::queue('PATCH', EP_ON_DEMAND_M8, array('title' => 'A'), 'title');
Fastpix\Fastpix_Outbox::supersede('PATCH', EP_ON_DEMAND_M8, array('title' => 'B'));   // what the API client calls once "B" reached the platform
assert(Fastpix\Fastpix_Outbox::count() === 0, 'M8: a queued title is retired by a later successful edit of the same field — "A" never replays over "B"');
Fastpix\Fastpix_Outbox::queue('PATCH', EP_ON_DEMAND_M8, array('title' => 'A'), 'title');
Fastpix\Fastpix_Outbox::queue('PATCH', EP_ON_DEMAND_M8, array('description' => 'D'), 'description');
assert(Fastpix\Fastpix_Outbox::count() === 2, 'different fields of one target both stay queued');
Fastpix\Fastpix_Outbox::queue('DELETE', EP_ON_DEMAND_M8, array(), 'delete');
$left = Fastpix\Fastpix_Outbox::entries();
assert(count($left) === 1 && $left[0]['method'] === 'DELETE', 'a DELETE retires every queued edit of the path');
$outbox_saved === null ? delete_option(Fastpix\Fastpix_Outbox::OPTION) : update_option(Fastpix\Fastpix_Outbox::OPTION, $outbox_saved, false);

// ---------------------------------------------------------------- teardown

$ids = $wpdb->get_col("SELECT id FROM " . Schema::table('videos') . SQL_WHERE_MEDIA_SYNC);
if ($ids) {
    $in = implode(',', array_map('intval', $ids));
    $wpdb->query(SQL_DELETE_FROM . Schema::table('playback_ids') . " WHERE video_id IN ({$in})");
}
$wpdb->query(SQL_DELETE_FROM . Schema::table('videos') . SQL_WHERE_MEDIA_SYNC);
$wpdb->query(SQL_DELETE_FROM . Schema::table('logs') . " WHERE error_code IN ('consistency_audit_complete', 'deep_sweep_complete')");
// sync_state is restored from the snapshot by the shutdown function above.
foreach (array('fastpix_new_media_sweep', 'fastpix_deep_sweep', 'fastpix_poll_media') as $hook) {
    as_unschedule_all_actions($hook);
}
foreach ($saved as $opt => $value) {
    if ($value === null) { delete_option($opt); } else { update_option($opt, $value, false); }
}

echo "sync engine: all checks passed\n";
