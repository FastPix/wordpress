<?php
/**
 * Deactivation confirm — "Deactivate FastPix?" on the Plugins screen, so nobody turns playback
 * off by accident. Nothing is asked, stored or sent.
 *
 * Deactivating is never blocked by a failure: if the dialog markup or the script is missing, the
 * Deactivate link behaves normally — the dialog only ever intercepts the click.
 */

namespace Fastpix;

if (!defined('WPINC')) {
    die;
}

class Fastpix_Deactivate { // NOSONAR php:S101 — WordPress class naming

    public static function boot() {
        add_action('admin_footer-plugins.php', array(__CLASS__, 'dialog'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'assets'));
    }

    /** Only on the Plugins screen, and only for someone who could deactivate anyway. */
    private static function allowed($hook = '') {
        return ($hook === '' || $hook === 'plugins.php') && current_user_can('activate_plugins');
    }

    public static function assets($hook) {
        if (!self::allowed($hook)) {
            return;
        }

        wp_enqueue_style('fastpix-deactivate', FASTPIX_PLUGIN_URL . 'assets/css/deactivate.css', array(), fastpix_asset_ver('assets/css/deactivate.css'));
        wp_enqueue_script('fastpix-deactivate', FASTPIX_PLUGIN_URL . 'assets/js/deactivate.js', array(), fastpix_asset_ver('assets/js/deactivate.js'), true);
        wp_localize_script('fastpix-deactivate', 'fastpixDeactivate', array(
            'slug' => plugin_basename(FASTPIX_PLUGIN_DIR . 'fastpix-io.php'),
        ));
    }

    public static function dialog() {
        if (!self::allowed()) {
            return;
        }
        ?>
        <div class="fp-de" id="fp-de" hidden>
            <dialog class="fp-de__box" open aria-modal="true" aria-labelledby="fp-de-title" aria-describedby="fp-de-text">
                <div class="fp-de__head">
                    <h2 id="fp-de-title"><?php esc_html_e('Deactivate FastPix?', 'fastpix-io'); ?></h2>
                    <button type="button" class="fp-de__x" id="fp-de-x" aria-label="<?php esc_attr_e('Close', 'fastpix-io'); ?>">&times;</button>
                </div>
                <p class="fp-de__text" id="fp-de-text"><?php esc_html_e('Video on this site stops playing until FastPix is active again. Nothing is deleted, here or on FastPix.', 'fastpix-io'); ?></p>
                <div class="fp-de__foot">
                    <button type="button" class="fp-de__btn sec" id="fp-de-cancel"><?php esc_html_e('Cancel', 'fastpix-io'); ?></button>
                    <button type="button" class="fp-de__btn primary" id="fp-de-go"><?php esc_html_e('Deactivate', 'fastpix-io'); ?></button>
                </div>
            </dialog>
        </div>
        <?php
    }
}
