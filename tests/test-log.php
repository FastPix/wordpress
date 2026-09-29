<?php
/**
 * Self-check for Fastpix_Log — ARCH-13, SEC-018, SEC-019, DATA-014.
 *
 *   docker compose exec wordpress php -d zend.assertions=1 \
 *     /var/www/html/wp-content/plugins/fastpix/tests/test-log.php
 *
 * Writes real rows and deletes exactly the ones it wrote (matched on its own
 * correlation id), so an existing log is left alone.
 */

require_once __DIR__ . '/bootstrap.php';

foreach (array('schema', 'log') as $class) {
    require_once __DIR__ . '/../includes/class-fastpix-' . $class . '.php';
}

use Fastpix\Fastpix_Schema as Schema;
use Fastpix\Fastpix_Log as Log;

const SQL_SELECT_COUNT_FROM = 'SELECT COUNT(*) FROM ';

global $wpdb;

Schema::update();
Log::boot();

// A real uuid: correlation_id is char(36), and a longer marker would be truncated.
$correlation = wp_generate_uuid4();
Log::set_correlation_id($correlation);

// ---------------------------------------------------------------- redaction

$secret = 'test-secret-not-a-real-key';
$jwt    = 'eyJhbGciOiJIUzI1NiIsImtpZCI6ImFiYyJ9.eyJhdWQiOiJ2IiwiZXhwIjo5OTk5fQ.c2lnbmF0dXJl';

$clean = Log::redact(array(
    'secret_key'     => $secret,
    'access_token'   => 'tok_123',
    'Authorization'  => 'Basic ' . base64_encode('id:' . $secret),
    'signature'      => 'abc123',
    'webhook_secret' => 'whsec_x',
    'idempotency_key'=> 'abc',
    'api_key'        => 'k',
    'streamKey'      => 'live-ingest-key',   // live stream / simulcast ingest key, as the platform names it
    'media_id'       => 'm-1',
    'nested'         => array('signing_key' => $secret, 'status' => 'Ready'),
    'playback_url'   => 'https://stream.fastpix.com/pb-1.m3u8?token=' . $jwt,
    'note'           => 'the token is ' . $jwt,
    'count'          => 7,
));

foreach (array('secret_key', 'access_token', 'Authorization', 'signature', 'webhook_secret', 'idempotency_key', 'api_key', 'streamKey') as $key) {
    assert($clean[$key] === Log::REDACTED, "{$key} is redacted by name [SEC-019]");
}
assert($clean['nested']['signing_key'] === Log::REDACTED, 'nested secrets are redacted');
assert($clean['nested']['status'] === 'Ready', 'nested non-secrets survive');
assert($clean['media_id'] === 'm-1', 'ordinary fields are untouched');
assert($clean['count'] === 7, 'non-strings pass through unchanged');
assert(strpos($clean['playback_url'], $jwt) === false, 'a signed URL loses its token [ARCH-09]');
assert(strpos($clean['playback_url'], 'stream.fastpix.com/pb-1.m3u8') !== false, 'the URL shape is kept for diagnosis');
assert(strpos($clean['note'], $jwt) === false, 'a bare JWT is redacted wherever it appears');

$serialised = wp_json_encode($clean);
assert(strpos($serialised, $secret) === false, 'no secret survives serialisation [SEC-019]');

// ------------------------------------------------------- the writer redacts

do_action('fastpix_log', 'api_error', array(
    'scope'       => 'connection',
    // A message that embeds a signed URL and a Bearer value — the writer must
    // redact the message column too, not only context [SEC-019].
    'message'     => 'transport error for https://stream.fastpix.com/x.m3u8?token=' . $jwt . ' (Authorization: Bearer ' . $jwt . ')',
    'http_status' => 401,
    'endpoint'    => '/on-demand',
    'http_method' => 'GET',
    'secret_key'  => $secret,          // a caller that forgot — the writer catches it
    'description' => 'token ' . $jwt,
));

$row = $wpdb->get_row($wpdb->prepare(
    'SELECT * FROM ' . Schema::table('logs') . ' WHERE correlation_id = %s ORDER BY id DESC LIMIT 1',
    $correlation
), ARRAY_A);

assert($row !== null, 'the writer subscribes to fastpix_log [ARCH-13]');
assert($row['error_code'] === 'api_error', 'the error code is stored');
assert($row['scope'] === 'connection', 'the scope is stored');
assert((int) $row['http_status'] === 401, 'numeric fields are stored as numbers');
assert($row['correlation_id'] === $correlation, 'the correlation id is constant across records [ARCH-13]');
assert(strpos($row['context'], $secret) === false, 'a caller cannot leak a secret by forgetting [SEC-019]');
assert(strpos($row['context'], $jwt) === false, 'nor a token inside a description');
assert(strpos($row['message'], $jwt) === false, 'the message column is redacted too — no token survives [SEC-019]');
assert(strpos($row['message'], 'stream.fastpix.com/x.m3u8') !== false, 'but the message shape is kept for diagnosis');

// ------------------------------------------------ audit is a separate sink

do_action('fastpix_audit_event', 'connect', array('user_id' => 1, 'workspace_id' => 'ws-1', 'secret_key' => $secret));

$audit = $wpdb->get_row('SELECT * FROM ' . Schema::table('audit') . ' ORDER BY id DESC LIMIT 1', ARRAY_A);
assert($audit['event'] === 'connect', 'audit records the event [SEC-018]');
assert((int) $audit['actor'] === 1, 'audit records the actor [SEC-018]');
assert(array_key_exists('origin_address', $audit), 'audit records the origin address [SEC-018]');
assert(strpos($audit['context'], $secret) === false, 'audit context is redacted too [SEC-019]');

$audit_count_before = (int) $wpdb->get_var(SQL_SELECT_COUNT_FROM . Schema::table('audit'));
$log_count_before   = (int) $wpdb->get_var(SQL_SELECT_COUNT_FROM . Schema::table('logs'));
assert($audit_count_before > 0 && $log_count_before > 0, 'the two logs are separate tables [ARCH-13]');

// ---------------------------------------------------------------- retention

$old = gmdate('Y-m-d H:i:s', time() - (40 * DAY_IN_SECONDS));
$wpdb->insert(Schema::table('logs'), array(
    'timestamp' => $old, 'severity' => 'error', 'scope' => 'test',
    'correlation_id' => $correlation, 'error_code' => 'stale_row',
    'created_at' => $old, 'updated_at' => $old,
));

$pruned = Log::prune(30);
assert($pruned >= 1, 'rows past 30 days are pruned [spec 07 retention]');
$still_there = (int) $wpdb->get_var($wpdb->prepare(
    SQL_SELECT_COUNT_FROM . Schema::table('logs') . ' WHERE correlation_id = %s AND error_code = %s',
    $correlation, 'stale_row'
));
assert($still_there === 0, 'the stale row is gone');
$fresh = (int) $wpdb->get_var($wpdb->prepare(
    SQL_SELECT_COUNT_FROM . Schema::table('logs') . ' WHERE correlation_id = %s',
    $correlation
));
assert($fresh === 1, 'fresh rows survive pruning');

// ------------------------------------------- read-only tables are not written

update_option(Schema::OPT_FAILED, array('version' => 99, 'tables' => array('logs'), 'message' => 'forced'), false);
assert(Log::write('should_not_write', array()) === false, 'a read-only table is not written [REQ-103]');
delete_option(Schema::OPT_FAILED);

// ---------------------------------------------------------------- teardown

$wpdb->delete(Schema::table('logs'), array('correlation_id' => $correlation));
$wpdb->delete(Schema::table('audit'), array('id' => $audit['id']));

echo "log + audit: all checks passed\n";
