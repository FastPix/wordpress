<?php
/**
 * FastPix capabilities (CAP-01…CAP-08).
 *
 * The plugin defines its own capabilities and maps them to WordPress roles;
 * sites may remap freely — nothing is hard-coded to author/editor.
 * [REQ-090, REQ-004; specs 03 §2, §3]
 */

namespace Fastpix;

if (!defined('WPINC')) {
    die;
}

class Fastpix_Capabilities {

    const MANAGE_SETTINGS  = 'fastpix_manage_settings';   // CAP-01
    const VIEW_VIDEOS      = 'fastpix_view_videos';       // CAP-02
    const UPLOAD_VIDEO     = 'fastpix_upload_video';      // CAP-03
    const EDIT_VIDEO       = 'fastpix_edit_video';        // CAP-04
    const EDIT_VIDEO_OWN   = 'fastpix_edit_video_own';    // CAP-05
    const DELETE_VIDEO     = 'fastpix_delete_video';      // CAP-06
    const DELETE_VIDEO_OWN = 'fastpix_delete_video_own';  // CAP-07
    const VIEW_ANALYTICS   = 'fastpix_view_analytics';    // CAP-08

    // ponytail: CAP-09 "review flagged content" has no slug in the SDD (MISS-007).
    // Left out rather than invented — add it when the answer lands.

    /**
     * Default role → capability map (specs 03 §2, binding defaults).
     * Author's "own only" is enforced as a query condition, never a response
     * filter (REQ-091), so authors simply hold the _own variants.
     */
    public static function default_map() {
        $author = array(
            self::VIEW_VIDEOS,
            self::UPLOAD_VIDEO,
            self::EDIT_VIDEO_OWN,
            self::DELETE_VIDEO_OWN,
            self::VIEW_ANALYTICS,
        );

        $editor = array_merge($author, array(
            self::EDIT_VIDEO,
            self::DELETE_VIDEO,
        ));

        $administrator = array_merge($editor, array(
            self::MANAGE_SETTINGS,
        ));

        return array(
            'administrator' => $administrator,
            'editor'        => $editor,
            'author'        => $author,
        );
    }

    /**
     * Grant the defaults. Idempotent, so it is safe on every activation and
     * as the "new capability goes to roles holding the equivalent permission"
     * step of an update (REQ-100).
     */
    public static function install() {
        foreach (self::default_map() as $role_name => $caps) {
            $role = get_role($role_name);
            if (!$role) {
                continue;
            }
            foreach ($caps as $cap) {
                if (!$role->has_cap($cap)) {
                    $role->add_cap($cap);
                }
            }
        }
    }

    /**
     * Self-heal: a cloned site, a restored active_plugins row or a role plugin
     * that recreated the roles never ran the activation hook, and an
     * administrator without a single plugin capability sees no menu and 403s on
     * every route. Only a role holding NONE of them is refilled — a site that
     * deliberately remapped one capability keeps its map (REQ-090).
     */
    public static function ensure() {
        $role = get_role('administrator');
        if (!$role) {
            return;
        }
        foreach (self::all() as $cap) {
            if ($role->has_cap($cap)) {
                return;
            }
        }
        foreach (self::all() as $cap) {   // only the administrator: editors/authors keep whatever the site chose (review 2026-09-20)
            $role->add_cap($cap);
        }
    }

    /**
     * Remove every plugin capability from every role. Uninstall only —
     * deactivation removes nothing (REQ-101).
     */
    public static function remove() {
        $roles = wp_roles();
        foreach (array_keys($roles->roles) as $role_name) {
            $role = get_role($role_name);
            if (!$role) {
                continue;
            }
            foreach (self::all() as $cap) {
                $role->remove_cap($cap);
            }
        }
    }

    public static function all() {
        return array(
            self::MANAGE_SETTINGS,
            self::VIEW_VIDEOS,
            self::UPLOAD_VIDEO,
            self::EDIT_VIDEO,
            self::EDIT_VIDEO_OWN,
            self::DELETE_VIDEO,
            self::DELETE_VIDEO_OWN,
            self::VIEW_ANALYTICS,
        );
    }
}
