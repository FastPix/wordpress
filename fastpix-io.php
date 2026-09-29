<?php
/*
Plugin Name: FastPix Video
Description: Upload, manage, embed and measure video with the FastPix platform — library, protected playback, analytics, migration and live streaming, from your WordPress dashboard.
Version: 2.0.0
Requires at least: 6.8
Requires PHP: 8.3
Author: FastPix
Author URI: https://fastpix.com
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: fastpix
*/

/*
FastPix — video for WordPress.
Copyright (C) 2026 FastPix, Inc.

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License, version 2, as
published by the Free Software Foundation.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program (see the LICENSE file); if not, see
https://www.gnu.org/licenses/gpl-2.0.html.
*/

// Prevent direct access
if (!defined('WPINC')) {
    die;
}

// Check if Composer autoloader exists
$fastpix_composer_autoload = __DIR__ . '/vendor/autoload.php';
if (!file_exists($fastpix_composer_autoload)) {
    add_action('admin_notices', function() {
        echo wp_kses_post('<div class="error"><p>Fastpix plugin error: Composer dependencies are not installed. Please run <code>composer install</code> in the plugin directory.</p></div>');
    });
    return;
}

require_once $fastpix_composer_autoload;

define('FASTPIX_VERSION', '2.0.0');

/**
 * Cache-busting asset version: the file's mtime, so every edit reaches the
 * browser without bumping FASTPIX_VERSION by hand.
 */
function fastpix_asset_ver($relative) {
    $mtime = @filemtime(plugin_dir_path(__FILE__) . $relative);

    return $mtime ? FASTPIX_VERSION . '.' . $mtime : FASTPIX_VERSION;
}

/**
 * Render a template with an explicit variable set, the same shape WP core's
 * load_template() gives query vars. Deliberately `include`, not include_once:
 * a template must re-execute on every render (screens render twice in the
 * self-checks, and partials repeat across screens).
 */
function fastpix_template($template, array $vars = array()) {
    extract($vars, EXTR_SKIP);
    include FASTPIX_PLUGIN_DIR . 'templates/' . $template;
}
define('FASTPIX_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('FASTPIX_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once FASTPIX_PLUGIN_DIR . 'includes/class-fastpix-capabilities.php';
require_once FASTPIX_PLUGIN_DIR . 'includes/class-fastpix-credentials.php';
require_once FASTPIX_PLUGIN_DIR . 'includes/class-fastpix-api-client.php';
require_once FASTPIX_PLUGIN_DIR . 'includes/class-fastpix-connection.php';
require_once FASTPIX_PLUGIN_DIR . 'includes/class-fastpix-schema.php';
require_once FASTPIX_PLUGIN_DIR . 'includes/class-fastpix-log.php';
require_once FASTPIX_PLUGIN_DIR . 'includes/class-fastpix-cache.php';
require_once FASTPIX_PLUGIN_DIR . 'includes/class-fastpix-jobs.php';
require_once FASTPIX_PLUGIN_DIR . 'includes/class-fastpix-rate-limiter.php';
require_once FASTPIX_PLUGIN_DIR . 'includes/class-fastpix-rest.php';
require_once FASTPIX_PLUGIN_DIR . 'includes/class-fastpix-cli.php';
require_once FASTPIX_PLUGIN_DIR . 'includes/class-fastpix-health.php';
require_once FASTPIX_PLUGIN_DIR . 'includes/class-fastpix-rest-connection.php';
require_once FASTPIX_PLUGIN_DIR . 'includes/class-fastpix-onboarding.php';
require_once FASTPIX_PLUGIN_DIR . 'includes/class-fastpix-sync.php';
require_once FASTPIX_PLUGIN_DIR . 'includes/class-fastpix-outbox.php';
require_once FASTPIX_PLUGIN_DIR . 'includes/class-fastpix-webhooks.php';
require_once FASTPIX_PLUGIN_DIR . 'includes/class-fastpix-webhooks-apply.php';
require_once FASTPIX_PLUGIN_DIR . 'includes/class-fastpix-uploads.php';
require_once FASTPIX_PLUGIN_DIR . 'includes/class-fastpix-uploads-settings.php';
require_once FASTPIX_PLUGIN_DIR . 'includes/class-fastpix-attachments.php';
require_once FASTPIX_PLUGIN_DIR . 'includes/class-fastpix-addmedia.php';
require_once FASTPIX_PLUGIN_DIR . 'includes/class-fastpix-menu-gate.php';
require_once FASTPIX_PLUGIN_DIR . 'includes/class-fastpix-search.php';
require_once FASTPIX_PLUGIN_DIR . 'includes/class-fastpix-ai.php';
require_once FASTPIX_PLUGIN_DIR . 'includes/class-fastpix-signing.php';
require_once FASTPIX_PLUGIN_DIR . 'includes/class-fastpix-render.php';
require_once FASTPIX_PLUGIN_DIR . 'includes/class-fastpix-migration.php';
require_once FASTPIX_PLUGIN_DIR . 'includes/class-fastpix-videos-rest.php';
require_once FASTPIX_PLUGIN_DIR . 'includes/class-fastpix-library-page.php';
require_once FASTPIX_PLUGIN_DIR . 'includes/class-fastpix-progress.php';
require_once FASTPIX_PLUGIN_DIR . 'includes/class-fastpix-lms.php';
require_once FASTPIX_PLUGIN_DIR . 'includes/class-fastpix-live.php';
require_once FASTPIX_PLUGIN_DIR . 'includes/class-fastpix-live-simulcast.php';
require_once FASTPIX_PLUGIN_DIR . 'includes/class-fastpix-analytics.php';
require_once FASTPIX_PLUGIN_DIR . 'includes/class-fastpix-analytics-page.php';
require_once FASTPIX_PLUGIN_DIR . 'includes/class-fastpix-settings-page.php';
require_once FASTPIX_PLUGIN_DIR . 'includes/class-fastpix-deactivate.php';
require_once FASTPIX_PLUGIN_DIR . 'includes/class-fastpix-activation.php';
// Fastpix_Utils is the only v1 class kept: v2 reads its language map, and the
// migration reads its getters for a legacy-credential import.
require_once FASTPIX_PLUGIN_DIR . 'includes/class-fastpix-utils.php';

/**
 * Deactivation deletes nothing and edits no post (REQ-101). There is therefore
 * no deactivation hook: the previous one wiped every fastpix_* option, which
 * destroyed the connection and every embed on reactivation.
 *
 * Removal of local data belongs to uninstall, and only when the owner has
 * ticked the delete-on-uninstall setting, off by default (REQ-102). Video on
 * the platform is never touched by any path.
 */
register_activation_hook(__FILE__, array(\Fastpix\Fastpix_Activation::class, 'activate'));
// Deactivation deletes nothing and edits no post (REQ-101): recurring jobs are
// unscheduled so nothing fires while inactive; activation re-schedules them.
register_deactivation_hook(__FILE__, array(\Fastpix\Fastpix_Jobs::class, 'unschedule_all'));

// Log and audit writers subscribe before anything can fire an event [ARCH-13];
// the job runner loads its bundled library and keeps its schema current.
\Fastpix\Fastpix_Log::boot();
\Fastpix\Fastpix_Jobs::boot();
\Fastpix\Fastpix_Signing::boot();          // daily signing-key check job (ASSUME-103)

add_action('plugins_loaded', function () {
    if (\Fastpix\Fastpix_Schema::needs_update()) {
        \Fastpix\Fastpix_Schema::update();
    }
});
// Capabilities self-heal like the schema: a clone/restore that skipped activation still gets its admin menu.
// On init, not admin_init: admin_menu and core's page-access check run before admin_init, and REST never reaches it. (QA S11)
add_action('init', array(\Fastpix\Fastpix_Capabilities::class, 'ensure'));

// Connecting is what makes recurring work meaningful [WF-001 background step].
add_action('fastpix_connected', array(\Fastpix\Fastpix_Jobs::class, 'schedule_recurring'));
add_action('fastpix_prune', array(\Fastpix\Fastpix_Schema::class, 'prune'));   // every spec'd retention, logs included
\Fastpix\Fastpix_Cli::register();
\Fastpix\Fastpix_Rest_Connection::boot();   // /connection, /connection/test, /logs, /system-report [API-P09/P10]
\Fastpix\Fastpix_Health::boot();            // Site Health checks [REQ-083, INT-010]
\Fastpix\Fastpix_Onboarding::boot();        // UI-001 wizard [REQ-007]
\Fastpix\Fastpix_Sync::boot();              // sweeps, poll fallback, audit [ARCH-06]
\Fastpix\Fastpix_Webhooks::boot();          // receiver + handler table [ARCH-05]
\Fastpix\Fastpix_Uploads::boot();           // sessions, URL ingest, orphan sweep [ARCH-04]
\Fastpix\Fastpix_Attachments::boot();       // Media Library proxies [ARCH-11]
\Fastpix\Fastpix_Addmedia::boot();          // UI-004 Add media screen
\Fastpix\Fastpix_Menu_Gate::boot();         // disconnected ⇒ wizard-only menu
\Fastpix\Fastpix_Search::boot();            // index + reindex jobs [DATA-013]
\Fastpix\Fastpix_Ai::boot();                // request on ready, fetch on completion [WF-005]
\Fastpix\Fastpix_Render::boot();            // block + shortcode + player-config + SEO [WF-007]
\Fastpix\Fastpix_Migration::boot();         // scan → transfer → verify → cleanup; swap at render [WF-004]
\Fastpix\Fastpix_Videos_Rest::boot();       // /videos routes + bulk jobs [API-P01/P02/P03/P06]
\Fastpix\Fastpix_Library_Page::boot();      // UI-002/003 Videos screen
\Fastpix\Fastpix_Progress::boot();          // watch progress + privacy [WF-013]
\Fastpix\Fastpix_Lms::boot();               // lesson completion + opt-in per-learner rows
\Fastpix\Fastpix_Live::boot();              // live streams, behind fastpix_feature_live [WF-008, RULE-040]
\Fastpix\Fastpix_Outbox::boot();            // offline edit queue, flushed on recovery [WF-015]
\Fastpix\Fastpix_Analytics::boot();         // rollup jobs + reads + export [WF-010]
\Fastpix\Fastpix_Analytics_Page::boot();    // UI-005 Analytics screen
\Fastpix\Fastpix_Settings_Page::boot();     // UI-006 Settings + minimal /settings
\Fastpix\Fastpix_Deactivate::boot();        // "why are you turning this off?" on the Plugins screen — local only
add_action('fastpix_jobs_watchdog', array(\Fastpix\Fastpix_Jobs::class, 'watchdog'));
// The 6-hourly health probe: refresh the cached env checks in the background so
// the settings screen / Site Health never block on a live round-trip.
add_action('fastpix_health_check', array(\Fastpix\Fastpix_Activation::class, 'refresh_checks'));
