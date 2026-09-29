<?php
/**
 * Self-check for the search index — DATA-013, REQ-032, RULE-044.
 *
 *   docker compose exec wordpress php -d zend.assertions=1 \
 *     /var/www/html/wp-content/plugins/fastpix/tests/test-search.php
 */

require_once __DIR__ . '/bootstrap.php';

use Fastpix\Fastpix_Schema as Schema;
use Fastpix\Fastpix_Search as Search;

const SQL_WHERE_VIDEO_ID = ' WHERE video_id = %d';

global $wpdb;

$now = current_time('mysql', true);
$wpdb->insert(Schema::table('videos'), array(
    'media_id' => 'search-check-1', 'workspace_id' => 'ws-s', 'status' => 'Ready', 'source' => 'Upload',
    'title' => 'Quarterly zebra briefing', 'description' => 'The okapi roadmap, discussed at length.',
    'created_at' => $now, 'updated_at' => $now,
));
$video_id = (int) $wpdb->insert_id;

// AI outputs: chapters with timings, a summary, entities, and a timed transcript.
$ai = array(
    array('kind' => 'chapters', 'json' => wp_json_encode(array(
        array('title' => 'Introduction to zebras', 'startTime' => 0, 'endTime' => 42),
        array('title' => 'The wildebeest question', 'startTime' => 42, 'endTime' => 90),
    ))),
    array('kind' => 'summary', 'json' => wp_json_encode('A short film about zebra migration patterns.')),
    array('kind' => 'entities', 'json' => wp_json_encode(array(array('name' => 'Serengeti'), array('name' => 'Dr Hoofington')))),
    array('kind' => 'transcript', 'json' => wp_json_encode(array(
        array('startTime' => 5, 'text' => 'Welcome to the annual migration review.'),
        array('startTime' => 65, 'text' => 'The pangolin cameo begins here.'),
    ))),
);
foreach ($ai as $output) {
    $wpdb->insert(Schema::table('ai'), array(
        'video_id' => $video_id, 'kind' => $output['kind'], 'generated_json' => $output['json'],
        'state' => 'ready', 'created_at' => $now, 'updated_at' => $now,
    ));
}

// ------------------------------------------------------------------ reindex

$rows = Search::reindex_video($video_id);
assert($rows >= 6, 'title, description, chapters, summary, entities and transcript all index');

$indexed = $wpdb->get_results($wpdb->prepare(
    'SELECT field, segment_index, start_seconds, content FROM ' . Schema::table('search_index') . SQL_WHERE_VIDEO_ID,
    $video_id
), ARRAY_A);
$by_field = array();
foreach ($indexed as $row) {
    $by_field[$row['field']][] = $row;
}

assert(isset($by_field['title'][0]) && $by_field['title'][0]['content'] === 'Quarterly zebra briefing', 'the title indexes');
assert(count($by_field['chapter']) === 2, 'one row per chapter [DATA-013]');
assert((float) $by_field['chapter'][1]['start_seconds'] === 42.0, 'chapters carry their timestamps');
assert(count($by_field['transcript']) === 2, 'transcript cues land in 30-second segments [DATA-013]');
assert((float) $by_field['transcript'][1]['start_seconds'] === 60.0, 'segment two starts at its bucket boundary');

// Reindex is idempotent — rebuild, not append.
Search::reindex_video($video_id);
$count = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Schema::table('search_index') . SQL_WHERE_VIDEO_ID, $video_id));
assert($count === $rows, 'reindexing replaces rather than appends');

// The full walk resumes past the last id it reached, never from the start again. [QA X1]
add_filter('fastpix_search_reindex_budget', '__return_zero');
as_unschedule_all_actions('fastpix_search_reindex');
Search::reindex_job(array());
$resume = as_get_scheduled_actions(array('hook' => 'fastpix_search_reindex', 'status' => \ActionScheduler_Store::STATUS_PENDING, 'per_page' => 5));
$resume_args = $resume ? array_values($resume)[0]->get_args() : null;
$resume_args = ($resume_args && isset($resume_args[0]) && is_array($resume_args[0])) ? $resume_args[0] : $resume_args;
assert($resume_args && isset($resume_args['after']) && (int) $resume_args['after'] > 0, 'an exhausted budget re-queues the walk with a cursor');
$first_id = (int) $wpdb->get_var('SELECT MIN(id) FROM ' . Schema::table('videos') . ' WHERE deleted_at IS NULL');
assert((int) $resume_args['after'] === $first_id, 'the cursor is the last id reindexed');
as_unschedule_all_actions('fastpix_search_reindex');
Search::reindex_job(array('after' => PHP_INT_MAX - 1));
assert(as_has_scheduled_action('fastpix_search_reindex') === false, 'a walk that reaches the end does not re-queue');
remove_filter('fastpix_search_reindex_budget', '__return_zero');

// --------------------------------------------------------------- searching

if (Search::available()) {
    $hits = Search::query('zebra');
    assert(is_array($hits) && !empty($hits), 'FULLTEXT search finds matches');
    assert(in_array((int) $hits[0]['video_id'], array($video_id), true), 'for the right video');

    $hits = Search::query('pangolin');
    assert(count($hits) === 1 && $hits[0]['field'] === 'transcript', 'a transcript match is found');
    assert((float) $hits[0]['start_seconds'] === 60.0, 'and returns a timestamp, not just a title match [REQ-032]');

    $hits = Search::query('Hoofington');
    assert(!empty($hits) && $hits[0]['field'] === 'entity', 'named entities are searchable [REQ-032]');

    assert(Search::query('') === array(), 'an empty term searches nothing');
} else {
    echo "note: FULLTEXT unavailable here; disabled-path assertions only\n";
}

// ------------------------------------------- the RULE-044 gate, both sides

update_option('fastpix_fulltext_unavailable', 1, false);
$refused = Search::query('zebra');
assert(is_wp_error($refused), 'without FULLTEXT the search is disabled — never a LIKE scan [RULE-044]');
assert(strpos($refused->get_error_message(), 'unavailable') !== false, 'and the interface can say so');
delete_option('fastpix_fulltext_unavailable');

// ----------------------------------------------- tombstoned videos drop out

$wpdb->update(Schema::table('videos'), array('deleted_at' => $now), array('id' => $video_id));
Search::reindex_video($video_id);
$count = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Schema::table('search_index') . SQL_WHERE_VIDEO_ID, $video_id));
assert($count === 0, 'a tombstoned video leaves the index');

// ---------------------------------------------------------------- teardown

$wpdb->query($wpdb->prepare('DELETE FROM ' . Schema::table('ai') . SQL_WHERE_VIDEO_ID, $video_id));
$wpdb->query("DELETE FROM " . Schema::table('videos') . " WHERE media_id = 'search-check-1'");

echo "search index: all checks passed\n";
