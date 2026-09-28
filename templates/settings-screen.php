<?php
/**
 * UI-006 — Settings, matched to Figma FastPix-V3 frame 9522:120112 (ASSUME-097).
 * One tall card of rows: VIDEO SETUP (workspace, DRM, webhook URL, signing
 * secret, link lifetime, retention, course features, structured data, last
 * event) then ACCOUNT (workspace, key, credential pair, connected since,
 * version, uninstall, disconnect); a footer bar of links + the support
 * report. Behaviour in assets/js/settings-page.js (element ids unchanged).
 * Variables from Fastpix_Settings_Page::render(): $state, $checks, $problems,
 * $delete_on_uninstall, $webhook_configured, $webhook_url, $token_id_full,
 * $secret_full, $webhook_secret_full, $connected_at, $last_event. The stored
 * credentials are shown to a fastpix_manage_settings user and are editable;
 * the two secrets sit behind Show/Hide (ASSUME-113 reverses ASSUME-107).
 */

if (!defined('WPINC')) {
    die;
}
$fastpix_lifetime = (int) round(\Fastpix\Fastpix_Signing::ttl() / 60);
$fastpix_seo      = (bool) get_option(\Fastpix\Fastpix_Render::OPT_SEO, true);
$fastpix_drm      = \Fastpix\Fastpix_Settings_Page::drm_configuration_id();
$fastpix_wizard   = admin_url('admin.php?page=fastpix-connection');
$fastpix_lms_found = \Fastpix\Fastpix_Lms::detected();
$fastpix_lms_on    = \Fastpix\Fastpix_Lms::enabled();
?>
<div class="wrap fastpix-wrap fp-st">
    <div class="fp-st__head">
        <h1><?php esc_html_e('FastPix settings', 'fastpix'); ?></h1>
        <div class="fp-st__headacts">
            <?php if ($state['unreadable']) : ?>
                <span class="fp-st__pill warn" id="fp-conn-pill"><?php esc_html_e('Not connected — the stored secret can no longer be read, re-enter the pair', 'fastpix'); ?></span>
            <?php elseif ($state['connected']) : ?>
                <span class="fp-st__pill ok" id="fp-conn-pill"><?php echo $state['healthy'] ? esc_html__('Connected', 'fastpix') : esc_html__('Connected — check credentials', 'fastpix'); ?></span>
            <?php else : ?>
                <span class="fp-st__pill warn" id="fp-conn-pill"><?php esc_html_e('Not connected', 'fastpix'); ?></span>
            <?php endif; ?>
            <a class="fp-st__btn sec" href="<?php echo esc_url($fastpix_wizard); ?>"><?php esc_html_e('Run setup again', 'fastpix'); ?></a>
        </div>
    </div>

    <div class="fp-st__card">

        <?php /* ------------------------------------------------ Video setup */ ?>
        <h2 class="fp-st__section" id="fp-card-video"><?php esc_html_e('Video setup', 'fastpix'); ?></h2>

        <div class="fp-st__row">
            <div class="fp-st__lab"><label for="fp-workspace-input"><?php esc_html_e('Workspace key', 'fastpix'); ?></label>
                <p class="fp-st__err" id="fp-workspace-err" hidden></p></div>
            <div class="fp-st__ctl">
                <input type="text" id="fp-workspace-input" class="mono" autocomplete="off" spellcheck="false" placeholder="980293090846277633" value="<?php echo esc_attr($state['workspace_id']); ?>">
                <span class="fp-st__saved" id="fp-workspace-note" aria-live="polite"></span>
            </div>
        </div>

        <div class="fp-st__row">
            <div class="fp-st__lab"><label for="fp-drm-config"><?php esc_html_e('DRM configuration ID', 'fastpix'); ?> <span class="fp-st__tag"><?php esc_html_e('optional', 'fastpix'); ?></span></label>
                <p class="fp-st__err" id="fp-drm-err" hidden></p></div>
            <div class="fp-st__ctl">
                <input type="text" id="fp-drm-config" class="mono" autocomplete="off" spellcheck="false" placeholder="<?php esc_attr_e('Leave empty if unused', 'fastpix'); ?>" value="<?php echo esc_attr($fastpix_drm); ?>">
                <span class="fp-st__saved" id="fp-drm-note" aria-live="polite"></span>
            </div>
        </div>

        <div class="fp-st__row">
            <div class="fp-st__lab"><label for="fp-webhook-url"><?php esc_html_e('Webhook URL', 'fastpix'); ?></label>
                <p class="fp-st__help"><?php esc_html_e('Paste into FastPix.', 'fastpix'); ?></p></div>
            <div class="fp-st__ctl">
                <input type="text" id="fp-webhook-url" class="mono" readonly value="<?php echo esc_attr($webhook_url); ?>">
                <button type="button" class="fp-st__btn icon-copy" id="fp-webhook-copy"><?php esc_html_e('Copy', 'fastpix'); ?></button>
            </div>
        </div>

        <div class="fp-st__row">
            <div class="fp-st__lab"><label for="fp-webhook-secret"><?php esc_html_e('Signing secret', 'fastpix'); ?></label>
                <p class="fp-st__err" id="fp-webhook-note" hidden></p></div>
            <div class="fp-st__ctl" id="fp-secret-edit">
                <input type="password" id="fp-webhook-secret" class="mono fp-secret" autocomplete="new-password" spellcheck="false" value="<?php echo esc_attr($webhook_secret_full); ?>" placeholder="<?php esc_attr_e('Paste the signing secret from FastPix', 'fastpix'); ?>">
                <button type="button" class="fp-st__btn primary" id="fp-webhook-save"<?php echo $webhook_configured ? '' : ' disabled'; ?>><?php esc_html_e('Save & verify', 'fastpix'); ?></button>
            </div>
        </div>

        <div class="fp-st__row">
            <div class="fp-st__lab"><label for="fp-token-ttl"><?php esc_html_e('Private link lifetime', 'fastpix'); ?></label></div>
            <div class="fp-st__ctl inline">
                <input type="number" id="fp-token-ttl" min="2" max="1440" step="1" value="<?php echo esc_attr($fastpix_lifetime); ?>">
                <span class="fp-st__unit"><?php esc_html_e('minutes', 'fastpix'); ?></span>
                <span class="fp-st__saved" id="fp-ttl-note" aria-live="polite"></span>
            </div>
        </div>

        <?php /* Course features — always offered, off until the owner turns them on. */ ?>
        <div class="fp-st__row" id="fp-lesson-retention-field"<?php echo $fastpix_lms_on ? '' : ' hidden'; ?>>
            <div class="fp-st__lab"><label for="fp-lesson-retention"><?php esc_html_e('Per-learner retention', 'fastpix'); ?></label>
                <p class="fp-st__help"><?php esc_html_e('How long per-student lesson watch records are kept. Only recorded where a lesson video has "Record who watched" turned on.', 'fastpix'); ?></p></div>
            <div class="fp-st__ctl inline">
                <input type="number" id="fp-lesson-retention" min="1" max="3650" step="1" value="<?php echo esc_attr(\Fastpix\Fastpix_Lms::retention_days()); ?>">
                <span class="fp-st__unit"><?php esc_html_e('days', 'fastpix'); ?></span>
                <span class="fp-st__saved" id="fp-lesson-retention-note" aria-live="polite"></span>
            </div>
        </div>

        <div class="fp-st__row">
            <div class="fp-st__lab"><label for="fp-lms-enabled"><?php esc_html_e('Course features', 'fastpix'); ?></label>
                <p class="fp-st__help"><?php
                    echo esc_html($fastpix_lms_found
                        /* translators: %s: detected course plugin names. */
                        ? sprintf(__('Completion settings on lesson videos, automatic lesson credit and the Courses tab in Analytics. Detected: %s.', 'fastpix'), implode(', ', $fastpix_lms_found))
                        : __('Completion settings on lesson videos, automatic lesson credit and the Courses tab in Analytics. No course plugin detected yet — works with LearnDash, Tutor LMS, LifterLMS and LearnPress.', 'fastpix'));
                ?></p></div>
            <div class="fp-st__ctl">
                <input type="checkbox" class="fp-st__toggle" id="fp-lms-enabled" <?php checked($fastpix_lms_on); ?>>
                <span class="fp-st__saved" id="fp-lms-note" aria-live="polite"></span>
            </div>
        </div>

        <div class="fp-st__row">
            <div class="fp-st__lab"><label for="fp-structured-data"><?php esc_html_e('Emit structured data for public videos', 'fastpix'); ?></label>
                <p class="fp-st__help"><?php esc_html_e('Never for private or DRM video.', 'fastpix'); ?></p></div>
            <div class="fp-st__ctl">
                <input type="checkbox" class="fp-st__toggle" id="fp-structured-data" <?php checked($fastpix_seo); ?>>
                <span class="fp-st__saved" id="fp-seo-note" aria-live="polite"></span>
            </div>
        </div>

        <?php
        $fastpix_wh_verdict = \Fastpix\Fastpix_Webhooks::verdict();
        $fastpix_wh_last    = \Fastpix\Fastpix_Webhooks::last_delivery();
        ?>
        <div class="fp-st__row foot">
            <span class="fp-st__lastevent" id="fp-last-event" data-verdict="<?php echo esc_attr($fastpix_wh_verdict); ?>">
                <?php if ($fastpix_wh_verdict === 'verified') : ?>
                    <?php echo esc_html(sprintf(
                        /* translators: 1: webhook event type, 2: time */
                        __('Secret verified — %1$s from FastPix, %2$s', 'fastpix'),
                        $fastpix_wh_last['type'], date_i18n('j M, H:i', strtotime($fastpix_wh_last['at'] . ' UTC'))
                    )); ?>
                    <span class="fp-st__pill sm ok"><?php esc_html_e('verified', 'fastpix'); ?></span>
                <?php elseif ($fastpix_wh_verdict === 'rejected') : ?>
                    <?php esc_html_e('Not verified — this secret does not match the one on the endpoint in the FastPix dashboard.', 'fastpix'); ?>
                    <span class="fp-st__pill sm warn"><?php esc_html_e('not verified', 'fastpix'); ?></span>
                <?php elseif ($fastpix_wh_verdict === 'pending') : ?>
                    <?php echo $last_event ? esc_html__('Receiver reachable. ', 'fastpix') : ''; ?><?php esc_html_e('Not verified — waiting for FastPix’s next event to confirm this secret.', 'fastpix'); ?>
                    <span class="fp-st__pill sm warn"><?php esc_html_e('not verified', 'fastpix'); ?></span>
                <?php else : ?>
                    <?php esc_html_e('No signing secret yet.', 'fastpix'); ?>
                <?php endif; ?>
            </span>
            <button type="button" class="fp-st__btn" id="fp-webhook-test"<?php echo $webhook_configured ? '' : ' disabled title="' . esc_attr__('Save the signing secret first', 'fastpix') . '"'; ?>><?php esc_html_e('Send test event', 'fastpix'); ?></button>
        </div>

        <?php /* ---------------------------------------------------- Account */ ?>
        <h2 class="fp-st__section" id="fp-card-account"><?php esc_html_e('Account', 'fastpix'); ?></h2>

        <div class="fp-st__row kv">
            <div class="fp-st__lab"><?php esc_html_e('Workspace', 'fastpix'); ?></div>
            <div class="fp-st__ctl"><span id="fp-account-workspace-name"><?php echo $state['workspace_name'] ? esc_html($state['workspace_name']) : '—'; ?></span></div>
        </div>
        <div class="fp-st__row kv">
            <div class="fp-st__lab"><?php esc_html_e('Workspace key', 'fastpix'); ?></div>
            <div class="fp-st__ctl"><span class="mono" id="fp-account-workspace"><?php echo esc_html($state['workspace_id'] !== '' ? $state['workspace_id'] : '—'); ?></span>
                <?php if ($state['workspace_id'] !== '') : ?><button type="button" class="fp-st__link fp-copy" data-copy="<?php echo esc_attr($state['workspace_id']); ?>" aria-label="<?php esc_attr_e('Copy workspace key', 'fastpix'); ?>"><?php esc_html_e('Copy', 'fastpix'); ?></button><?php endif; ?></div>
        </div>
        <?php /* The pair edits in place: type over the (truncated) token id, paste a secret, Verify. Nothing is stored until the pair verifies; an untouched mask means the stored value. */ ?>
        <div class="fp-st__row">
            <div class="fp-st__lab"><label for="fp-token-id"><?php esc_html_e('Access token ID', 'fastpix'); ?></label></div>
            <div class="fp-st__ctl"><input type="text" id="fp-token-id" class="mono" autocomplete="off" spellcheck="false" value="<?php echo esc_attr($token_id_full); ?>" placeholder="<?php esc_attr_e('e.g. ba7b2cdf-f2a4-4b50-8d0c-6eafbf546c6f', 'fastpix'); ?>"></div>
        </div>
        <div class="fp-st__row">
            <div class="fp-st__lab"><label for="fp-secret"><?php esc_html_e('Secret key', 'fastpix'); ?></label>
                <p class="fp-st__err inline" id="fp-creds-err" hidden></p></div>
            <div class="fp-st__ctl"><input type="password" id="fp-secret" class="mono fp-secret" autocomplete="new-password" spellcheck="false" value="<?php echo esc_attr($secret_full); ?>" placeholder="<?php esc_attr_e('e.g. 1c32f452-5fec-4cb9-b795-f7a9f09a1b45', 'fastpix'); ?>">
                <button type="button" class="fp-st__btn primary" id="fp-creds-save"><?php esc_html_e('Verify', 'fastpix'); ?></button>
                <span class="fp-st__saved" id="fp-creds-note" aria-live="polite"></span></div>
        </div>
        <div class="fp-st__row kv">
            <div class="fp-st__lab"><?php esc_html_e('Connected since', 'fastpix'); ?></div>
            <div class="fp-st__ctl"><?php echo $connected_at ? esc_html(date_i18n('j M Y', $connected_at)) : '—'; ?></div>
        </div>
        <div class="fp-st__row kv">
            <div class="fp-st__lab"><?php esc_html_e('Plugin version', 'fastpix'); ?></div>
            <div class="fp-st__ctl"><span class="mono"><?php echo esc_html(FASTPIX_VERSION); ?></span></div>
        </div>
        <div class="fp-st__row">
            <div class="fp-st__lab"><label for="fp-delete-on-uninstall"><?php esc_html_e('Delete local data on uninstall', 'fastpix'); ?></label>
                <p class="fp-st__help"><?php esc_html_e('Tables, options and proxy attachments. Video on FastPix is never touched.', 'fastpix'); ?></p></div>
            <div class="fp-st__ctl">
                <input type="checkbox" class="fp-st__toggle" id="fp-delete-on-uninstall" <?php checked($delete_on_uninstall); ?>>
                <span class="fp-st__saved" id="fp-uninstall-note" aria-live="polite"></span>
            </div>
        </div>
        <div class="fp-st__row danger">
            <div class="fp-st__lab"><p class="fp-st__help"><?php esc_html_e('Disconnecting stops playback until an account is connected again. Nothing is deleted.', 'fastpix'); ?></p></div>
            <div class="fp-st__ctl"><button type="button" class="fp-st__btn danger" id="fp-disconnect"<?php echo $state['connected'] ? '' : ' disabled'; ?>><?php esc_html_e('Disconnect', 'fastpix'); ?></button></div>
        </div>
    </div>

    <div class="fp-st__pagefoot">
        <nav class="fp-st__links">
            <a href="https://status.fastpix.io" target="_blank" rel="noopener"><?php esc_html_e('Status', 'fastpix'); ?></a>
            <a href="https://fastpix.com/contact-us" target="_blank" rel="noopener"><?php esc_html_e('Support', 'fastpix'); ?></a>
            <a href="https://fastpix.com/docs" target="_blank" rel="noopener"><?php esc_html_e('Docs', 'fastpix'); ?></a>
        </nav>
        <span class="fp-st__reportwrap"><span class="fp-st__saved" id="fp-report-note" aria-live="polite"></span>
        <button type="button" class="fp-st__btn icon-copy" id="fp-copy-report"><?php esc_html_e('Copy support report', 'fastpix'); ?></button></span>
    </div>
</div>
