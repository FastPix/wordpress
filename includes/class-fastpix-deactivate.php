<?php
/**
 * Deactivation feedback — the short "why are you turning this off?" dialog on the Plugins screen.
 *
 * Deliberately local-only: the answer is stored on this site and rides along in the support
 * report, and NOTHING leaves the site. The plugin declares exactly one external service in its
 * readme (FastPix itself); a survey that phoned home would be a second one, needs disclosure, and
 * WordPress.org requires it to be opt-in. A site that DOES want the answer forwarded can hook
 * `fastpix_deactivation_feedback` and send it wherever it likes.
 *
 * Deactivating is never blocked: Skip goes straight through, and so does Escape, the backdrop, or
 * JavaScript failing to load at all — the dialog only ever intercepts the click.
 */

namespace Fastpix;

if (!defined('WPINC')) {
    die;
}

class Fastpix_Deactivate { // NOSONAR php:S101 — WordPress class naming

    /** The last answer, kept for the support report. One row, overwritten each time. */
    const OPT_FEEDBACK = 'fastpix_deactivation_feedback';

    public static function boot() {
        add_action('admin_footer-plugins.php', array(__CLASS__, 'dialog'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'assets'));
        add_action('rest_api_init', array(__CLASS__, 'routes'));
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
        wp_enqueue_script('fastpix-deactivate', FASTPIX_PLUGIN_URL . 'assets/js/deactivate.js', array('wp-i18n'), fastpix_asset_ver('assets/js/deactivate.js'), true);
        wp_set_script_translations('fastpix-deactivate', 'fastpix-io');
        wp_localize_script('fastpix-deactivate', 'fastpixDeactivate', array(
            'restUrl' => rest_url(Fastpix_Rest::NS),
            'nonce'   => wp_create_nonce('wp_rest'),
            'slug'    => plugin_basename(FASTPIX_PLUGIN_DIR . 'fastpix-io.php'),
        ));
    }

    /**
     * The reasons. Written as the person would say them, most likely first, with "Other" last —
     * FastPix-specific where that tells you more than a generic answer would.
     */
    private static function reasons() {
        return array(
            'temporary'   => __('Just turning it off for a moment', 'fastpix-io'),
            'not_needed'  => __('I do not need it any more', 'fastpix-io'),
            'connect'     => __('I could not connect my FastPix workspace', 'fastpix-io'),
            'playback'    => __('Uploads or playback did not work', 'fastpix-io'),
            'confusing'   => __('I could not work out how to use it', 'fastpix-io'),
            'other_tool'  => __('I am using something else', 'fastpix-io'),
            'other'       => __('Another reason', 'fastpix-io'),
        );
    }

    public static function dialog() {
        if (!self::allowed()) {
            return;
        }
        ?>
        <div class="fp-de" id="fp-de" hidden>
            <dialog class="fp-de__box" open aria-modal="true" aria-labelledby="fp-de-title">
                <div class="fp-de__head">
                    <h2 id="fp-de-title"><?php esc_html_e('Before you go', 'fastpix-io'); ?></h2>
                    <button type="button" class="fp-de__x" id="fp-de-x" aria-label="<?php esc_attr_e('Close', 'fastpix-io'); ?>">&times;</button>
                </div>
                <div class="fp-de__body">
                    <p class="fp-de__ask"><?php esc_html_e('What made you turn FastPix off? It stays on this site — we only see it if you send us a support report.', 'fastpix-io'); ?></p>
                    <ul class="fp-de__list">
                        <?php foreach (self::reasons() as $key => $label) : ?>
                            <li>
                                <label>
                                    <input type="radio" name="fp-de-reason" value="<?php echo esc_attr($key); ?>">
                                    <span><?php echo esc_html($label); ?></span>
                                </label>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <label class="fp-de__more" for="fp-de-detail"><?php esc_html_e('Anything else worth telling us?', 'fastpix-io'); ?>
                        <textarea id="fp-de-detail" rows="3" maxlength="1000" placeholder="<?php esc_attr_e('Optional', 'fastpix-io'); ?>"></textarea>
                    </label>
                </div>
                <div class="fp-de__foot">
                    <button type="button" class="fp-de__btn primary" id="fp-de-send"><?php esc_html_e('Send &amp; deactivate', 'fastpix-io'); ?></button>
                    <button type="button" class="fp-de__btn ghost" id="fp-de-skip"><?php esc_html_e('Skip &amp; deactivate', 'fastpix-io'); ?></button>
                </div>
            </dialog>
        </div>
        <?php
    }

    public static function routes() {
        Fastpix_Rest::register('/feedback/deactivate', array(
            // SEC-011: the registrar derives the permission callback from 'capability' and refuses a
            // route that supplies neither that nor 'public_bucket' — a bare permission_callback is
            // dropped. Whoever can deactivate the plugin can answer why. (QA 2026-09-23)
            'methods'    => 'POST',
            'callback'   => array(__CLASS__, 'store'),
            'capability' => 'activate_plugins',
            'args'       => array(
                'reason' => Fastpix_Rest::arg('string', array('enum' => array_keys(self::reasons()))),
                'detail' => Fastpix_Rest::arg('string', array('sanitize_callback' => 'sanitize_textarea_field')),
            ),
        ));
    }

    /** Store the answer and hand it to anyone who wants to forward it. Never blocks deactivation. */
    public static function store($request) {
        $reasons = self::reasons();
        $reason  = (string) $request->get_param('reason');
        $entry   = array(
            'reason'  => isset($reasons[$reason]) ? $reason : 'other',
            'label'   => isset($reasons[$reason]) ? $reasons[$reason] : '',
            'detail'  => mb_substr((string) $request->get_param('detail'), 0, 1000),
            'at'      => time(),
            'version' => FASTPIX_VERSION,
        );
        update_option(self::OPT_FEEDBACK, $entry, false);

        /**
         * A site that wants this sent somewhere can do it here. Nothing in the plugin sends it.
         *
         * @param array $entry reason, label, detail, at, version.
         */
        do_action('fastpix_deactivation_feedback', $entry);

        return rest_ensure_response(array('stored' => true));
    }

    /** One line for the support report, so it travels only when the owner sends one. */
    public static function report_line() { // NOSONAR php:S100 — WordPress snake_case naming
        $entry = get_option(self::OPT_FEEDBACK);
        if (!is_array($entry) || empty($entry['reason'])) {
            return '';
        }

        return sprintf(
            '%s (%s)%s',
            isset($entry['label']) && $entry['label'] !== '' ? $entry['label'] : $entry['reason'],
            gmdate('Y-m-d', (int) $entry['at']),
            !empty($entry['detail']) ? ' — ' . $entry['detail'] : ''
        );
    }
}
