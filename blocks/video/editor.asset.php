<?php
if (!defined('ABSPATH')) {
    exit;
}
// Version tracks editor.js's mtime so editor changes cache-bust in the browser
// (register_block_type reads this file for the editorScript's deps + version).
return array(
    'dependencies' => array('wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-server-side-render', 'wp-data', 'wp-api-fetch', 'wp-i18n'),
    'version'      => (string) (@filemtime(__DIR__ . '/editor.js') ?: '2.0.0'),
);
