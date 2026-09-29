<?php
/**
 * Retention + privacy tooling for the per-learner lesson-progress rows.
 *
 * Split out of class-fastpix-lms.php for size only. These always run —
 * rows may exist from a period when the course features were on.
 */

namespace Fastpix;

if (!defined('WPINC')) {
    die;
}

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery
// All SQL here runs against the plugin's own tables: identifiers come from the
// Fastpix_Schema::table() registry (a fixed whitelist, never request input) and
// every value travels through $wpdb->prepare(). Core APIs cannot query these tables.

class Fastpix_Lms_Privacy {

    /** Drop a deleted user's lesson-progress rows (keyed by their hashed id). */
    public static function purge_deleted_user($user_id) {
        global $wpdb;
        $wpdb->delete(Fastpix_Schema::table('lesson_progress'), array('viewer_key' => Fastpix_Lms::viewer_hash($user_id)));
    }

    /** Nightly, from the fastpix_prune job. */
    public static function prune() {
        global $wpdb;

        if (!Fastpix_Schema::table_exists('lesson_progress')) {
            return;
        }
        $wpdb->query($wpdb->prepare(
            'DELETE FROM ' . Fastpix_Schema::table('lesson_progress') . ' WHERE updated_at < %s',
            gmdate('Y-m-d H:i:s', time() - Fastpix_Lms::retention_days() * DAY_IN_SECONDS)
        ));
    }

    public static function register_exporter($exporters) {
        $exporters['fastpix-lesson-progress'] = array(
            'exporter_friendly_name' => __('FastPix lesson progress', 'fastpix-io'),
            'callback'               => array(__CLASS__, 'export_personal_data'),
        );

        return $exporters;
    }

    public static function register_eraser($erasers) {
        $erasers['fastpix-lesson-progress'] = array(
            'eraser_friendly_name' => __('FastPix lesson progress', 'fastpix-io'),
            'callback'             => array(__CLASS__, 'erase_personal_data'),
        );

        return $erasers;
    }

    private static function user_rows($email) {
        global $wpdb;

        $user = get_user_by('email', $email);
        if (!$user || !Fastpix_Schema::table_exists('lesson_progress')) {
            return array();
        }

        return $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . Fastpix_Schema::table('lesson_progress') . ' WHERE viewer_key = %s',
            Fastpix_Lms::viewer_hash((int) $user->ID)
        ), ARRAY_A);
    }

    public static function export_personal_data($email) {
        $items = array();
        foreach (self::user_rows($email) as $row) {
            $items[] = array(
                'group_id'    => 'fastpix-lesson-progress',
                'group_label' => __('FastPix lesson progress', 'fastpix-io'),
                'item_id'     => 'fastpix-lesson-progress-' . $row['post_id'] . '-' . $row['media_id'],
                'data'        => array(
                    array('name' => __('Lesson', 'fastpix-io'), 'value' => get_the_title((int) $row['post_id'])),
                    array('name' => __('Parts watched', 'fastpix-io'), 'value' => self::popcount((string) $row['slots']) . '%'),
                    array('name' => __('Completed', 'fastpix-io'), 'value' => $row['completed_at'] ? $row['completed_at'] : __('No', 'fastpix-io')),
                    array('name' => __('Last activity', 'fastpix-io'), 'value' => (string) $row['updated_at']),
                ),
            );
        }

        return array('data' => $items, 'done' => true);
    }

    public static function erase_personal_data($email) {
        global $wpdb;

        $removed = 0;
        foreach (self::user_rows($email) as $row) {
            $removed += (int) $wpdb->delete(Fastpix_Schema::table('lesson_progress'), array(
                'viewer_key' => $row['viewer_key'], 'post_id' => (int) $row['post_id'], 'media_id' => $row['media_id'],
            ));
        }

        return array('items_removed' => $removed > 0, 'items_retained' => false, 'messages' => array(), 'done' => true);
    }

    private static function popcount($binary) {
        $count = 0;
        foreach (str_split($binary) as $byte) {
            $count += substr_count(decbin(ord($byte)), '1');
        }

        return $count;
    }
}
