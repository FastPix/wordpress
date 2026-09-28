<?php
/**
 * Self-check for Fastpix_Credentials — run it with plain PHP, no WordPress:
 *
 *   docker compose exec wordpress php /var/www/html/wp-content/plugins/fastpix/tests/test-credentials.php
 *
 * Covers the parts that fail silently and dangerously if they break: the
 * round-trip, non-autoloaded storage, tamper/salt-change detection, and the
 * secret never being echoed (REQ-003, SEC-002).
 */

define('WPINC', 'wp-includes');

$GLOBALS['options'] = array();
$GLOBALS['autoload'] = array();
$GLOBALS['salt'] = 'the-original-site-salt';

function get_option($name, $default = false) {
    return array_key_exists($name, $GLOBALS['options']) ? $GLOBALS['options'][$name] : $default;
}

function update_option($name, $value, $autoload = null) {
    if (array_key_exists($name, $GLOBALS['options']) && $GLOBALS['options'][$name] === $value) {
        return false;   // WordPress returns false for a no-op write
    }
    $GLOBALS['options'][$name] = $value;
    $GLOBALS['autoload'][$name] = $autoload;
    return true;
}

function delete_option($name) {
    unset($GLOBALS['options'][$name], $GLOBALS['autoload'][$name]);
    return true;
}

function sanitize_text_field($value) {
    return trim(strip_tags($value));
}

function wp_salt() {
    return $GLOBALS['salt'];
}

require_once __DIR__ . '/../includes/class-fastpix-credentials.php';

use Fastpix\Fastpix_Credentials as Creds;

$token_id = '4f27c4c4-9a1e-4c2f-9d33-2b8f0a7c1e55';
$secret   = 'test_secret_9RtP2xW7qL4mZ0vC8bN6yH3jK1sD5gF';

// Round-trip.
assert(Creds::store($token_id, $secret) === true, 'a valid pair stores');
assert(Creds::token_id() === $token_id, 'token id round-trips');
assert(Creds::secret() === $secret, 'secret round-trips');
assert(Creds::has_pair() === true, 'pair reported present');
assert(Creds::is_unreadable() === false, 'a freshly stored secret is readable');

// The stored secret is ciphertext, and neither option autoloads (SEC-002, DATA-016).
$stored = $GLOBALS['options'][Creds::OPT_SECRET];
assert(strpos($stored, $secret) === false, 'the secret is not stored in the clear');
assert($GLOBALS['autoload'][Creds::OPT_SECRET] === false, 'secret option is non-autoloaded');
assert($GLOBALS['autoload'][Creds::OPT_TOKEN_ID] === false, 'token id option is non-autoloaded');

// Two encryptions of the same value differ — the IV is not reused.
Creds::store($token_id, $secret);
assert($GLOBALS['options'][Creds::OPT_SECRET] !== $stored, 'each encryption uses a fresh IV');

// Nothing echoes the secret (REQ-003).
assert(Creds::masked_secret() === Creds::SECRET_MASK, 'secret is masked, never returned');
assert(strpos(Creds::masked_secret(), $secret) === false, 'mask leaks nothing');
assert(Creds::masked_token_id() === '4f27c4c4…1e55', 'token id shows truncated');

// An empty half is refused rather than half-stored.
$before = $GLOBALS['options'];
assert(Creds::store($token_id, '') === false, 'a missing secret is refused');
assert(Creds::store('', $secret) === false, 'a missing token id is refused');
assert($GLOBALS['options'] === $before, 'a refused pair changes nothing');

// Tampering is detected by the GCM tag rather than returning garbage.
$GLOBALS['options'][Creds::OPT_SECRET] = Creds::PREFIX . base64_encode('not a real blob at all, padded out');
assert(Creds::secret() === '', 'a tampered blob yields no secret');
assert(Creds::is_unreadable() === true, 'a tampered blob is reported unreadable');

// Rotated site salts make the stored secret unreadable — not silently wrong.
Creds::store($token_id, $secret);
$GLOBALS['salt'] = 'the-salts-were-rotated';
assert(Creds::secret() === '', 'a salt change yields no secret');
assert(Creds::is_unreadable() === true, 'a salt change is reported unreadable');
$GLOBALS['salt'] = 'the-original-site-salt';
assert(Creds::secret() === $secret, 'restoring the salt restores the secret');

// Disconnect drops the pair; nothing else is touched.
Creds::forget();
assert(Creds::has_pair() === false, 'forget clears the pair');
assert(Creds::secret() === '', 'no secret after forget');

// purge_legacy removes ONLY v1 credential leftovers and must never touch the v2
// pair. OPT_SECRET is the option 'fastpix_secret_key' — a v1 option name — so a
// naive purge list that includes it would delete the live v2 secret. Guard it.
Creds::store($token_id, $secret);
$GLOBALS['options']['fastpix_api_key']              = 'v1-plain-key';
$GLOBALS['options']['fastpix_api_key_encrypted']    = 'v1-enc-key';
$GLOBALS['options']['fastpix_api_secret']           = 'v1-plain-secret';
$GLOBALS['options']['fastpix_api_secret_encrypted'] = 'v1-enc-secret';
Creds::purge_legacy();
assert(Creds::has_pair() === true, 'purge_legacy keeps the v2 pair intact');
assert(Creds::secret() === $secret, 'purge_legacy never deletes the v2 sealed secret (fastpix_secret_key)');
assert(!isset($GLOBALS['options']['fastpix_api_key']), 'purge_legacy removes the v1 plaintext key');
assert(!isset($GLOBALS['options']['fastpix_api_secret']), 'purge_legacy removes the v1 plaintext secret');
assert(!isset($GLOBALS['options']['fastpix_api_key_encrypted'])
    && !isset($GLOBALS['options']['fastpix_api_secret_encrypted']), 'purge_legacy removes the v1 encrypted copies');

echo "credentials: all checks passed\n";
