<?php
/**
 * Uninstall — REQ-102, WF-012.
 *
 * Removes local data (plugin tables, options and transients, proxy
 * attachments, scheduled actions and their logs/groups, export files, the
 * plugin capabilities) ONLY when the owner ticked the delete-on-uninstall
 * setting, which is off by default — the capabilities stay with the data so a
 * reinstall keeps the site's role map. On multisite every site is walked and
 * each site's own setting decides. Video on the FastPix platform is never
 * touched by any path — this file makes no HTTP request of any kind.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    die;
}

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery
// All SQL here runs against the plugin's own tables: identifiers come from the
// Fastpix_Schema::table() registry (a fixed whitelist, never request input) and
// every value travels through $wpdb->prepare(). Core APIs cannot query these tables.

require_once __DIR__ . '/includes/class-fastpix-schema.php';

// Guard against a SECOND include (the self-check loads this file to read the manifest).
// It must be a runtime flag: a function_exists() guard is useless here because PHP hoists the
// declarations below, so it fired on the FIRST include too — the file returned before ever
// reaching fastpix_uninstall_run(), and delete-on-uninstall silently removed nothing, ever.
// (QA 2026-09-22 — reproduced on a throwaway install, then fixed.)
if (defined('FASTPIX_UNINSTALL_LOADED')) {
    return;
}
define('FASTPIX_UNINSTALL_LOADED', true);

/**
 * Everything uninstall would remove, gathered in one place so the self-check
 * can assert coverage without destroying a live site's data.
 *
 * @return array{tables: string[], option_patterns: string[], attachment_ids: int[], dirs: string[]}
 */
function fastpix_uninstall_manifest() {
    global $wpdb;

    $upload = wp_upload_dir(null, false);

    return array(
        'tables'          => array_map(array('Fastpix\Fastpix_Schema', 'table'), Fastpix\Fastpix_Schema::tables()),
        // Both live options and the transient rows they leave behind.
        'option_patterns' => array(
            $wpdb->esc_like('fastpix_') . '%',
            $wpdb->esc_like('_transient_fastpix_') . '%',
            $wpdb->esc_like('_transient_timeout_fastpix_') . '%',
            $wpdb->esc_like('_site_transient_fastpix_') . '%',
            $wpdb->esc_like('_site_transient_timeout_fastpix_') . '%',
        ),
        'attachment_ids'  => array_map('intval', (array) $wpdb->get_col(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type = 'video/fastpix'"
        )),
        'dirs'            => array(trailingslashit($upload['basedir']) . 'fastpix-exports'),
    );
}

/** Execute the removal: every site on a network, each judged by its own setting. */
function fastpix_uninstall_run() {
    // The plugin is being deleted: its shortcode fallback goes with it, whatever the data setting (QA #16).
    if (defined('WPMU_PLUGIN_DIR') && file_exists(WPMU_PLUGIN_DIR . '/fastpix-shortcode-fallback.php')) {
        wp_delete_file(WPMU_PLUGIN_DIR . '/fastpix-shortcode-fallback.php');
    }
    if (!is_multisite()) {
        fastpix_uninstall_site();

        return;
    }
    // ponytail: every site in one pass, no batching — fine below a few thousand sites.
    foreach (get_sites(array('fields' => 'ids', 'number' => 0)) as $site_id) {
        switch_to_blog($site_id);
        fastpix_uninstall_site();
        restore_current_blog();
    }
}

/** Remove the current site's data. Split from the manifest so tests can inspect without running. */
function fastpix_uninstall_site() {
    global $wpdb;

    if (!get_option('fastpix_delete_on_uninstall', false)) {
        return;   // default: leave every row for a reinstall [REQ-102]
    }

    $manifest = fastpix_uninstall_manifest();

    // Scheduled actions first, so nothing fires against half-removed data.
    // Action Scheduler ships inside this plugin, so its functions are absent
    // here — clean its rows directly when its tables exist: the actions, their
    // log lines, and the fastpix-* groups.
    $as = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->prefix . 'actionscheduler_actions'));
    if ($as) {
        $wpdb->query("DELETE l FROM {$wpdb->prefix}actionscheduler_logs l INNER JOIN {$wpdb->prefix}actionscheduler_actions a ON a.action_id = l.action_id WHERE a.hook LIKE 'fastpix\_%'");
        $wpdb->query("DELETE FROM {$wpdb->prefix}actionscheduler_actions WHERE hook LIKE 'fastpix\_%'");
        $wpdb->query("DELETE FROM {$wpdb->prefix}actionscheduler_groups WHERE slug LIKE 'fastpix-%'");
    }
    wp_clear_scheduled_hook('fastpix_jobs_watchdog');

    foreach ($manifest['attachment_ids'] as $attachment_id) {
        wp_delete_attachment($attachment_id, true);
    }

    foreach ($manifest['tables'] as $table) {
        $wpdb->query('DROP TABLE IF EXISTS ' . $table);   // names come from the schema registry, not input
    }

    foreach ($manifest['option_patterns'] as $pattern) {
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $pattern));
    }

    fastpix_uninstall_remove_dirs($manifest['dirs']);
    fastpix_uninstall_remove_caps();

    wp_cache_flush();
}

/** Export CSVs live under uploads; remove each directory and its files. */
function fastpix_uninstall_remove_dirs($dirs) {
    foreach ($dirs as $dir) {
        if (is_dir($dir)) {
            // scandir, not glob('*'): glob skips dotfiles, so the folder's .htaccess survived and rmdir failed.
            foreach (array_diff((array) scandir($dir), array('.', '..')) as $name) {
                $file = trailingslashit($dir) . $name;
                if (is_file($file)) {
                    wp_delete_file($file);
                }
            }
            rmdir($dir);   // phpcs:ignore WordPress.WP.AlternativeFunctions -- WP_Filesystem is not initialised during uninstall
        }
    }
}

/** Roles keep working without the plugin; its capabilities go with it. */
function fastpix_uninstall_remove_caps() {
    foreach (wp_roles()->role_objects as $role) {
        foreach (array_keys($role->capabilities) as $capability) {
            if (strpos($capability, 'fastpix_') === 0) {
                $role->remove_cap($capability);
            }
        }
    }
}

// WordPress includes this file to DO the work. The self-check includes it to read the manifest and
// must never trigger a run against a live site's tables, so it declares itself first. (QA 2026-09-22)
if (!defined('FASTPIX_UNINSTALL_INSPECT')) {
    fastpix_uninstall_run();
}
