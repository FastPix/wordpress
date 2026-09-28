<?php
/**
 * UI-001 — Connection (setup wizard). All states rendered; JS shows one.
 * Markup mirrors references/FastPixWordPressPrototype (2).html (scrOnboard/cwPage).
 * Variables from Fastpix_Onboarding::render(): $state (array), $restricted (bool).
 */

if (!defined('WPINC')) {
    die;
}

$fastpix_webhooks_configured = (bool) get_option(Fastpix\Fastpix_Health::OPT_WEBHOOK_SECRET);
$fastpix_link_arrow = FASTPIX_PLUGIN_URL . 'assets/images/onboarding-link-arrow.svg';
$fastpix_copy_icon  = FASTPIX_PLUGIN_URL . 'assets/images/onboarding-copy-icon.svg';
// The step the page opens on (onboarding.js applies the same rule): rendered
// un-hidden so the page has content before — or without — the script.
$fastpix_initial = 'landing';
if ($state['connected']) {
    $fastpix_initial = $state['workspace_saved'] ? 'connect' : 'wsweb';
}
$fastpix_hidden = ' hidden';
?>
<div class="wrap fastpix-wrap">
<?php if ($restricted) : ?>
    <?php fastpix_template('partials/plugin-header.php', array('fastpix_header_full' => true)); ?>
    <?php /* Restricted: no fields rendered at all. [UI-001 restricted, REQ-004] */ ?>
    <div class="cwiz" data-fp-view="restricted">
        <div class="cwcard">
            <div class="cwhead"><span class="cwlogo" aria-hidden="true">F</span></div>
            <div class="cwbody">
                <div class="fpnotice info">
                    <span class="ni" aria-hidden="true">ⓘ</span>
                    <div class="nb">
                        <p class="nt"><?php esc_html_e('Credentials are limited to one capability', 'fastpix'); ?></p>
                        <p><?php esc_html_e('Your role can upload and manage video, but not reach the workspace credentials. Only a role with the settings capability can see or change either field.', 'fastpix'); ?></p>
                    </div>
                </div>
                <div class="kv"><span><?php esc_html_e('Connection', 'fastpix'); ?></span><span><b><?php echo esc_html(get_bloginfo('name')); ?></b></span></div>
                <div class="kv"><span><?php esc_html_e('State', 'fastpix'); ?></span>
                    <span class="b <?php echo $state['connected'] ? 'ok' : 'neutral'; ?>"><span class="dot" aria-hidden="true"></span><?php echo $state['connected'] ? esc_html__('Connected', 'fastpix') : esc_html__('Not connected', 'fastpix'); ?></span>
                </div>
            </div>
            <div class="cwfoot"><a class="btn" href="<?php echo esc_url(admin_url('admin.php?page=fastpix-video-library')); ?>"><?php esc_html_e('Go to Videos', 'fastpix'); ?></a></div>
        </div>
    </div>
<?php else : ?>

    <div class="cwiz fit" id="fp-wizard"
         data-fp-connected="<?php echo $state['connected'] ? '1' : '0'; ?>"
         data-fp-workspace-saved="<?php echo $state['workspace_saved'] ? '1' : '0'; ?>"
         data-fp-token-masked="<?php echo esc_attr($token_full !== '' ? $token_full : $state['token_id']); ?>"
         data-fp-webhooks="<?php echo $fastpix_webhooks_configured ? '1' : '0'; ?>">

    <?php /* ------- Welcome page (Figma 9342:73581) — shown before the wizard */ ?>
    <section class="fp-landing" data-fp-view="landing"<?php echo esc_attr($fastpix_initial === 'landing' ? '' : $fastpix_hidden); ?>>
        <?php /* Hero card 1175×508: logo · headline · sub · Get started · 900×160 feature strip */ ?>
        <div class="fp-land-hero">
            <span class="fp-land-brand"><img src="<?php echo esc_url(FASTPIX_PLUGIN_URL . 'assets/images/onboarding-welcome-logo.svg'); ?>" alt="FastPix" width="111" height="25"></span>
            <div class="fp-land-head">
                <h2><?php esc_html_e('Video infrastructure for WordPress', 'fastpix'); ?></h2>
                <p class="fp-land-sub"><?php esc_html_e('Upload, stream, and manage video directly from WordPress. FastPix handles encoding, adaptive streaming, captions, thumbnails, and playback analytics automatically.', 'fastpix'); ?></p>
            </div>
            <button type="button" class="fp-land-start" id="fp-start-setup"><?php esc_html_e('Get started', 'fastpix'); ?></button>

            <div class="fp-land-strip">
                <div class="fp-land-col">
                    <div class="fp-land-colhead"><span class="fp-land-ic"><img src="<?php echo esc_url(FASTPIX_PLUGIN_URL . 'assets/images/onboarding-welcome-library.svg'); ?>" alt="" width="16" height="16"></span><b><?php esc_html_e('Video library', 'fastpix'); ?></b></div>
                    <p><?php esc_html_e('Upload, organize, and search your video library without leaving WordPress.', 'fastpix'); ?></p>
                </div>
                <span class="fp-land-div" aria-hidden="true"><span class="ring"><img src="<?php echo esc_url(FASTPIX_PLUGIN_URL . 'assets/images/onboarding-welcome-arrow.svg'); ?>" alt="" width="13" height="13"></span></span>
                <div class="fp-land-col">
                    <div class="fp-land-colhead"><span class="fp-land-ic"><img src="<?php echo esc_url(FASTPIX_PLUGIN_URL . 'assets/images/onboarding-welcome-streaming.svg'); ?>" alt="" width="16" height="16"></span><b><?php esc_html_e('Streaming ready', 'fastpix'); ?></b></div>
                    <p><?php esc_html_e('FastPix automatically processes videos for adaptive streaming, captions, thumbnails, and poster images.', 'fastpix'); ?></p>
                </div>
                <span class="fp-land-div" aria-hidden="true"><span class="ring"><img src="<?php echo esc_url(FASTPIX_PLUGIN_URL . 'assets/images/onboarding-welcome-arrow.svg'); ?>" alt="" width="13" height="13"></span></span>
                <div class="fp-land-col">
                    <div class="fp-land-colhead"><span class="fp-land-ic"><img src="<?php echo esc_url(FASTPIX_PLUGIN_URL . 'assets/images/onboarding-welcome-analytics.svg'); ?>" alt="" width="16" height="16"></span><b><?php esc_html_e('Built-in analytics', 'fastpix'); ?></b></div>
                    <p><?php esc_html_e('Track plays, engagement, watch time, and playback quality directly from WordPress.', 'fastpix'); ?></p>
                </div>
            </div>
        </div>

        <?php /* Disclosure box 1175×185: enumerated and closed. [SEC-017, REQ-007] */ ?>
        <div class="fp-land-sends">
            <h3><?php esc_html_e('What FastPix sends — FastPix only receives the information required to process and deliver video content.', 'fastpix'); ?></h3>
            <ol>
                <li><span class="n">1.</span><span><?php esc_html_e('Video files that you upload.', 'fastpix'); ?></span></li>
                <li><span class="n">2.</span><span><?php esc_html_e('Video metadata such as titles and descriptions.', 'fastpix'); ?></span></li>
                <li><span class="n">3.</span><span><?php esc_html_e('Playback and engagement events when analytics are enabled.', 'fastpix'); ?></span></li>
            </ol>
            <p class="fine"><?php esc_html_e('FastPix processes this information to deliver video playback and analytics. No other content from your WordPress site is shared.', 'fastpix'); ?>
               <a href="https://fastpix.com/terms-and-conditions" target="_blank" rel="noopener"><?php esc_html_e('FastPix terms', 'fastpix'); ?></a>
               <a href="https://fastpix.com/privacy-policy" target="_blank" rel="noopener"><?php esc_html_e('Privacy policy', 'fastpix'); ?></a></p>
        </div>
    </section>

    <?php /* Stepper above the card (Figma 9342:73208) */ ?>
    <div class="cwhead">
        <div class="cwsteps" id="fp-cwsteps">
            <div class="cwstep on" data-fp-step="welcome"><span class="c" aria-hidden="true">1</span><span class="l"><?php esc_html_e('Welcome', 'fastpix'); ?></span></div>
            <div class="cwline" data-fp-line="1"></div>
            <div class="cwstep" data-fp-step="connect"><span class="c" aria-hidden="true">2</span><span class="l"><?php esc_html_e('Connect FastPix', 'fastpix'); ?></span></div>
            <div class="cwline" data-fp-line="2"></div>
            <div class="cwstep" data-fp-step="wsweb"><span class="c" aria-hidden="true">3</span><span class="l"><?php esc_html_e('Workspace & webhooks', 'fastpix'); ?></span></div>
        </div>
    </div>

    <div class="cwcard">

        <div class="cwbody" id="fp-cwbody">


        <?php /* -------------- step 2: connect (Figma 9342:73208) */ ?>
        <section data-fp-view="connect"<?php echo esc_attr($fastpix_initial === 'connect' ? '' : $fastpix_hidden); ?>>
            <div class="fp-con2">
                <div class="fp-con2-main" id="fp-pair">
                    <p class="fp-eyebrow"><?php esc_html_e('Step 2 of 3', 'fastpix'); ?></p>
                    <h2 class="fp-con2-h"><?php esc_html_e('Connect your FastPix account', 'fastpix'); ?></h2>
                    <p class="fp-con2-lede"><?php esc_html_e('Enter your FastPix access token and secret key to connect your WordPress site.', 'fastpix'); ?></p>
                    <?php if ($state['unreadable']) : /* salts rotated: the pair is stored but useless — say why the wizard asks again */ ?>
                    <div class="fpnotice error" id="fp-unreadable">
                        <span class="ni" aria-hidden="true">✕</span>
                        <div class="nb"><p class="nt"><?php esc_html_e('The stored Secret Key can no longer be read', 'fastpix'); ?></p>
                            <p><?php esc_html_e('The site security keys may have been rotated. Enter the credential pair again to reconnect.', 'fastpix'); ?></p></div>
                    </div>
                    <?php endif; ?>

                    <?php
                    $fastpix_token_value = '';
                    if ($state['connected']) {
                        $fastpix_token_value = $token_full !== '' ? $token_full : $state['token_id'];
                    }
                    ?>
                    <div class="fld" data-fp-field="token">
                        <label for="fp-token-id"><?php esc_html_e('Access token', 'fastpix'); ?></label>
                        <input type="text" id="fp-token-id" class="mono" autocomplete="off" spellcheck="false"
                               placeholder="<?php esc_attr_e('e.g. ba7b2cdf-f2a4-4b50-8d0c-6eafbf546c6f', 'fastpix'); ?>"
                               value="<?php echo esc_attr($fastpix_token_value); ?>">
                        <p class="fp-fld-help"><?php esc_html_e('Used to authenticate requests from your WordPress site.', 'fastpix'); ?> <a class="fp-go" href="https://fastpix.com/docs/getting-started/activate-your-account#create-an-access-token" target="_blank" rel="noopener"><?php esc_html_e('Get a token in FastPix', 'fastpix'); ?><img src="<?php echo esc_url($fastpix_link_arrow); ?>" alt="" width="16" height="16"></a></p>
                        <p class="berr" id="fp-token-err" role="alert" hidden></p>
                    </div>
                    <div class="fld" data-fp-field="secret">
                        <label for="fp-secret"><?php esc_html_e('Secret key', 'fastpix'); ?></label>
                        <div class="pwwrap">
                            <?php /* A plain, readable field like Access token above it — the stored secret is the
                                   owner's to read and edit, and a row of dots showed neither its value nor its
                                   real length. Hide is still there for a screen share. (owner 2026-09-23) */ ?>
                            <input type="text" id="fp-secret" class="mono" autocomplete="off" spellcheck="false"
                                   value="<?php echo esc_attr($secret_full); ?>"
                                   placeholder="<?php esc_attr_e('e.g. 1c32f452-5fec-4cb9-b795-f7a9f09a1b45', 'fastpix'); ?>">
                            <button type="button" class="pwt" id="fp-showhide" aria-pressed="true"><?php esc_html_e('Hide', 'fastpix'); ?></button>
                        </div>
                        <p class="fp-fld-help"><?php esc_html_e('Use with your access token to authenticate API requests. Keep it secure.', 'fastpix'); ?> <a class="fp-go" href="https://fastpix.com/docs/getting-started/activate-your-account" target="_blank" rel="noopener"><?php esc_html_e('I don’t have an account yet', 'fastpix'); ?><img src="<?php echo esc_url($fastpix_link_arrow); ?>" alt="" width="16" height="16"></a></p>
                        <p class="berr" id="fp-secret-err" role="alert" hidden></p>
                    </div>

                    <div class="fpnotice error" id="fp-conn-error" hidden>
                        <span class="ni" aria-hidden="true">✕</span>
                        <div class="nb">
                            <p class="nt" id="fp-conn-error-title"></p>
                            <p id="fp-conn-error-body"></p>
                            <div class="na"><button type="button" class="btn sm ghost" id="fp-copy-report" hidden><?php esc_html_e('Copy system report', 'fastpix'); ?></button></div>
                        </div>
                    </div>

                    <div class="fp-con2-act">
                        <button type="button" class="fp-linklike" id="fp-back2">← <?php esc_html_e('Back', 'fastpix'); ?></button>
                        <span class="fp-con2-status" id="fp-pair-status" aria-live="polite"></span>
                        <span class="fp-con2-status" id="fp-savestate" aria-live="polite" hidden><span class="dot" aria-hidden="true"></span></span>
                        <button type="button" class="fp-con2-cta" id="fp-save" disabled><?php echo $state['connected'] ? esc_html__('Update', 'fastpix') : esc_html__('Connect', 'fastpix'); ?></button>
                        <span class="fp-con2-done" id="fp-pair-done" role="status" aria-label="<?php esc_attr_e('Connected', 'fastpix'); ?>" hidden><img src="<?php echo esc_url(FASTPIX_PLUGIN_URL . 'assets/images/onboarding-step-check.svg'); ?>" alt="" width="15" height="15"></span>
                        <button type="button" class="fp-con2-cta" id="fp-next2" <?php disabled(!$state['connected']); echo esc_attr($state['connected'] ? '' : $fastpix_hidden); ?>><?php esc_html_e('Next', 'fastpix'); ?></button>
                        <button type="button" class="btn ghost" id="fp-change" hidden><?php esc_html_e('Change these', 'fastpix'); ?></button>
                    </div>
                </div>

                <aside class="fp-con2-side" aria-label="<?php esc_attr_e('Get your API credentials', 'fastpix'); ?>">
                    <p class="fp-eyebrow"><?php esc_html_e('Get your API credentials', 'fastpix'); ?></p>
                    <ol class="fp-con2-steps">
                        <li><span class="n">1</span><span><?php esc_html_e('Sign in to', 'fastpix'); ?> <a class="fp-go" href="https://dashboard.fastpix.com" target="_blank" rel="noopener">dashboard.fastpix.com<img src="<?php echo esc_url($fastpix_link_arrow); ?>" alt="" width="16" height="16"></a></span></li>
                        <li><span class="n">2</span><span><?php esc_html_e('In FastPix dashboard go to', 'fastpix'); ?> <a class="fp-go" href="https://fastpix.com/docs/getting-started/activate-your-account#create-an-access-token" target="_blank" rel="noopener"><?php esc_html_e('Manage → Access Tokens', 'fastpix'); ?><img src="<?php echo esc_url($fastpix_link_arrow); ?>" alt="" width="16" height="16"></a></span></li>
                        <li><span class="n">3</span><span><?php esc_html_e('Copy access token and secret keys then paste them into the fields.', 'fastpix'); ?></span></li>
                    </ol>
                    <p class="fp-con2-cap"><?php esc_html_e("You'll see this popup — copy from it:", 'fastpix'); ?></p>
                    <?php /* Drawn popup mockup — carries no real token */ ?>
                    <div class="fp-con2-popup" aria-hidden="true">
                        <div class="ttl"><b><?php esc_html_e('New access token generated successfully', 'fastpix'); ?></b><span>×</span></div>
                        <span class="lbl"><?php esc_html_e('Access token ID', 'fastpix'); ?></span>
                        <span class="key">ba7b2cdf-f2a4-4b50-8d0c-6eafbf546c6f<img src="<?php echo esc_url($fastpix_copy_icon); ?>" alt="" width="9" height="9"></span>
                        <span class="lbl"><?php esc_html_e('Secret Key', 'fastpix'); ?></span>
                        <span class="key">1c32f452-5fec-4cb9-b795-f7a9f09a1b45<img src="<?php echo esc_url($fastpix_copy_icon); ?>" alt="" width="9" height="9"></span>
                        <span class="env"><?php esc_html_e('Download as .env file', 'fastpix'); ?></span>
                        <div class="foot"><span class="go"><?php esc_html_e('Continue', 'fastpix'); ?></span></div>
                    </div>
                    <a class="fp-con2-link" href="<?php echo esc_url('https://www.youtube.com/watch?v=' . apply_filters('fastpix_onboarding_video_id', 'ybamvBkRYaE')); ?>" target="_blank" rel="noopener"><?php esc_html_e('Watch youtube video', 'fastpix'); ?> ↗</a>
                </aside>
            </div>
        </section>

        <?php /* --------------------------- step 3: Workspace & Webhooks */ ?>
        <?php /* ---- step 3: workspace & webhooks (Figma 9342:95054) ---- */ ?>
        <section data-fp-view="wsweb"<?php echo esc_attr($fastpix_initial === 'wsweb' ? '' : $fastpix_hidden); ?>>
            <div class="fp-con2 fp-ws3">
                <div class="fp-con2-main">
                    <p class="fp-eyebrow"><?php esc_html_e('Step 3 of 3 — last one', 'fastpix'); ?></p>
                    <h2 class="fp-con2-h"><?php esc_html_e('Link your workspace', 'fastpix'); ?></h2>
                    <p class="fp-con2-lede"><?php esc_html_e('Two small jobs: tell FastPix which workspace this site reports to, and let it push updates back instantly.', 'fastpix'); ?></p>

                    <div class="fp-ws3-box">
                        <div class="fp-ws3-head">
                            <b><?php esc_html_e('1 · Workspace', 'fastpix'); ?></b>
                            <span class="fp-chip req"><?php esc_html_e('Required', 'fastpix'); ?></span>
                        </div>
                        <div class="fld">
                            <label for="fp-workspace-input"><?php esc_html_e('Workspace key', 'fastpix'); ?></label>
                            <input type="text" id="fp-workspace-input" class="mono" autocomplete="off" spellcheck="false"
                                   placeholder="980293090846277633"
                                   value="<?php echo esc_attr($state['workspace_saved'] ? $state['workspace_id'] : ''); ?>">
                            <p class="fp-fld-help"><?php esc_html_e('Makes sure views from this site show up under the right workspace.', 'fastpix'); ?></p>
                            <p class="berr" id="fp-workspace-err" hidden></p>
                        </div>
                        <button type="button" id="fp-workspace-save" hidden disabled><?php esc_html_e('Save workspace', 'fastpix'); ?></button>
                        <span class="fp-ws3-status" id="fp-workspace-state" aria-live="polite"></span>
                        <span class="fp-ws3-status" id="fp-workspace-note" aria-live="polite"></span>
                    </div>

                    <div class="fp-ws3-box">
                        <div class="fp-ws3-head">
                            <b><?php esc_html_e('2 · Instant updates', 'fastpix'); ?></b>
                            <span class="fp-chip opt"><?php esc_html_e('Optional — can skip', 'fastpix'); ?></span>
                        </div>
                        <p class="fp-ws3-why"><?php esc_html_e('Receive media status updates automatically. Without webhooks, the plugin checks for updates periodically.', 'fastpix'); ?><button type="button" class="fp-tip" aria-describedby="fp-webhook-tip"><img src="<?php echo esc_url(FASTPIX_PLUGIN_URL . 'assets/images/onboarding-info-icon.svg'); ?>" alt="<?php esc_attr_e('What is a webhook?', 'fastpix'); ?>" width="20" height="20"><span class="fp-tip-box" role="tooltip" id="fp-webhook-tip"><b><?php esc_html_e('What is a webhook?', 'fastpix'); ?></b><?php esc_html_e('A webhook is a way for FastPix to tell your site the moment something happens — for example, when a video you uploaded has finished processing and is ready to play.', 'fastpix'); ?><br><br><?php esc_html_e('Without it, the plugin asks FastPix for news every few minutes, so new videos can take a little longer to show up in your library.', 'fastpix'); ?><br><br><?php esc_html_e('Setting it up is two copy-pastes and can be done later from Settings. Nothing else on your site changes.', 'fastpix'); ?></span></button></p>
                        <div class="fld">
                            <label for="fp-webhook-url"><?php esc_html_e("Your site's webhook address — copy this URL into FastPix dashboard", 'fastpix'); ?></label>
                            <div class="fp-ws3-urlrow">
                                <input type="text" id="fp-webhook-url" class="mono" readonly value="<?php echo esc_attr(rest_url('fastpix/v1/webhook')); ?>">
                                <button type="button" class="fp-ws3-copy" id="fp-webhook-copy"><?php esc_html_e('Copy', 'fastpix'); ?></button>
                            </div>
                        </div>
                        <div class="fld">
                            <label for="fp-webhook-secret"><?php esc_html_e('Signing secret — FastPix displays it after you paste the address', 'fastpix'); ?></label>
                            <div class="pwwrap">
                                <input type="password" id="fp-webhook-secret" class="mono" autocomplete="off" spellcheck="false"
                                       placeholder="<?php echo esc_attr($fastpix_webhooks_configured ? '•••••••• (stored encrypted — paste to replace)' : __('Paste it back here', 'fastpix')); ?>">
                                <button type="button" class="pwt" id="fp-wh-showhide" aria-pressed="false"><?php esc_html_e('Show', 'fastpix'); ?></button>
                            </div>
                            <p class="fp-fld-help"><?php esc_html_e('Proves updates really come from FastPix.', 'fastpix'); ?></p>
                        </div>
                        <button type="button" id="fp-webhook-save" hidden disabled><?php esc_html_e('Save & verify', 'fastpix'); ?></button>
                        <span class="fp-ws3-status" id="fp-webhook-state" hidden></span>
                        <?php
                        // The secret's verdict comes only from FastPix's own deliveries (ASSUME-098):
                        // waiting until one arrives, then verified or not verified. onboarding.js keeps
                        // polling while it reads "waiting".
                        $fastpix_wh_verdict = \Fastpix\Fastpix_Webhooks::verdict();
                        $fastpix_wh_last    = \Fastpix\Fastpix_Webhooks::last_delivery();
                        $fastpix_wh_class   = array('verified' => 'ok', 'rejected' => 'err', 'pending' => 'wait');
                        ?>
                        <span class="fp-ws3-status <?php echo esc_attr(isset($fastpix_wh_class[$fastpix_wh_verdict]) ? $fastpix_wh_class[$fastpix_wh_verdict] : ''); ?>" id="fp-webhook-note" aria-live="polite" data-verdict="<?php echo esc_attr($fastpix_wh_verdict); ?>"><?php
                            if ($fastpix_wh_verdict === 'verified') {
                                /* translators: 1: event type, 2: time */
                                echo esc_html(sprintf(__('Verified — %1$s received %2$s', 'fastpix'), $fastpix_wh_last['type'], date_i18n('j M H:i', strtotime($fastpix_wh_last['at'] . ' UTC'))));
                            } elseif ($fastpix_wh_verdict === 'rejected') {
                                esc_html_e('Not verified — this secret does not match the one on the endpoint in the FastPix dashboard. Paste it again.', 'fastpix');
                            } elseif ($fastpix_wh_verdict === 'pending') {
                                esc_html_e('Not verified — waiting for FastPix’s next event to confirm this secret.', 'fastpix');
                            }
                        ?></span>
                    </div>

                    <div class="fp-con2-act">
                        <button type="button" class="fp-linklike" id="fp-back3">← <?php esc_html_e('Back', 'fastpix'); ?></button>
                        <span class="fp-ws3-spacer"></span>
                        <button type="button" class="fp-ws3-skip" id="fp-webhook-skip"><?php esc_html_e('Skip webhook setup', 'fastpix'); ?></button>
                        <button type="button" class="fp-con2-cta" id="fp-finish" disabled
                                title="<?php esc_attr_e('Save the workspace ID first', 'fastpix'); ?>"><?php esc_html_e('Finish setup', 'fastpix'); ?></button>
                    </div>
                </div>

                <aside class="fp-con2-side" aria-label="<?php esc_attr_e('Find the workspace key', 'fastpix'); ?>">
                    <p class="fp-eyebrow"><?php esc_html_e('Find the workspace key', 'fastpix'); ?></p>
                    <ol class="fp-con2-steps">
                        <li><span class="n">1</span><span><?php esc_html_e('In FastPix, open', 'fastpix'); ?> <a class="fp-go" href="https://dashboard.fastpix.com" target="_blank" rel="noopener"><?php esc_html_e('Workspaces', 'fastpix'); ?><img src="<?php echo esc_url($fastpix_link_arrow); ?>" alt="" width="16" height="16"></a></span></li>
                        <li><span class="n">2</span><span><?php esc_html_e('Copy the key on the workspace card', 'fastpix'); ?></span></li>
                    </ol>
                    <p class="fp-con2-cap"><?php esc_html_e("You'll see this card — copy from it:", 'fastpix'); ?></p>
                    <?php /* Workspace card mockup — drawn, no real key */ ?>
                    <div class="fp-con2-popup fp-ws3-mock" aria-hidden="true">
                        <div class="ttl"><b><?php esc_html_e('Your workspace', 'fastpix'); ?></b><span class="fp-ws3-prod"><?php esc_html_e('Production', 'fastpix'); ?></span></div>
                        <span class="lbl"><?php esc_html_e('Workspace key', 'fastpix'); ?></span>
                        <span class="key">980293090846277633<img src="<?php echo esc_url($fastpix_copy_icon); ?>" alt="" width="9" height="9"></span>
                    </div>

                    <hr class="fp-ws3-divider">

                    <p class="fp-eyebrow"><?php esc_html_e('Turn on instant updates', 'fastpix'); ?></p>
                    <ol class="fp-con2-steps">
                        <li><span class="n">1</span><span><?php esc_html_e("Copy your site's webhook address", 'fastpix'); ?></span></li>
                        <li><span class="n">2</span><span><?php esc_html_e('In FastPix, open', 'fastpix'); ?> <a class="fp-go" href="https://dashboard.fastpix.com" target="_blank" rel="noopener"><?php esc_html_e('Settings → Webhooks', 'fastpix'); ?><img src="<?php echo esc_url($fastpix_link_arrow); ?>" alt="" width="16" height="16"></a> <?php esc_html_e('and paste it', 'fastpix'); ?></span></li>
                        <li><span class="n">3</span><span><?php esc_html_e('Copy the signing secret FastPix shows, paste it back here', 'fastpix'); ?></span></li>
                    </ol>
                    <p class="fp-con2-cap"><?php esc_html_e("You'll see this popup — copy from it:", 'fastpix'); ?></p>
                    <div class="fp-con2-popup" aria-hidden="true">
                        <div class="ttl"><b><?php esc_html_e('Webhook added successfully', 'fastpix'); ?></b><span>×</span></div>
                        <span class="lbl"><?php esc_html_e('Signing secret', 'fastpix'); ?></span>
                        <span class="key">whsec_9f2c81d4a7e35b60c8f1<img src="<?php echo esc_url($fastpix_copy_icon); ?>" alt="" width="9" height="9"></span>
                        <div class="foot"><span class="go"><?php esc_html_e('Continue', 'fastpix'); ?></span></div>
                    </div>
                    <a class="fp-con2-link" href="<?php echo esc_url('https://www.youtube.com/watch?v=' . apply_filters('fastpix_onboarding_video_id', 'ybamvBkRYaE')); ?>" target="_blank" rel="noopener"><?php esc_html_e('Watch youtube video', 'fastpix'); ?> ↗</a>
                </aside>
            </div>
        </section>

        </div><?php /* /cwbody */ ?>

        <?php /* ---------------------------------------- done (outside stepper) */ ?>
        <section class="celebrate" data-fp-view="done" hidden>
            <span class="confetti" aria-hidden="true"><?php
                // Figma 9342:95375 — 15 pieces: centre x%, y% of the card, w, h, rotation, colour.
                $fastpix_c_purple = '#BE9DE9';
                $fastpix_c_green  = '#6FF3BD';
                $fastpix_c_amber  = '#FFB020';
                $fastpix_confetti = array(
                    array(6.4, 25.7, 8, 12, 24, $fastpix_c_purple),   array(13.4, 67.6, 7, 11, -18, $fastpix_c_green),
                    array(9.4, 86.6, 8, 8, 0, '#EFE7F7'),     array(20.3, 39.6, 7, 11, 40, $fastpix_c_amber),
                    array(27.4, 78.2, 8, 12, -32, '#D9C7EE'), array(32.3, 23.5, 7, 7, 0, '#30F2A2'),
                    array(41.3, 88.6, 7, 11, 14, $fastpix_c_purple),  array(55.3, 92.2, 8, 12, -24, $fastpix_c_green),
                    array(63.3, 22.2, 8, 12, 30, '#D9C7EE'),  array(71.3, 72.5, 7, 7, 0, $fastpix_c_amber),
                    array(78.2, 36.1, 7, 11, -40, $fastpix_c_green), array(86.3, 64.2, 8, 12, 20, $fastpix_c_purple),
                    array(92.2, 29.1, 7, 11, -14, '#EFE7F7'), array(95.2, 82.9, 7, 7, 0, '#30F2A2'),
                    array(48.3, 18.6, 7, 11, -8, $fastpix_c_amber),
                );
                foreach ($fastpix_confetti as $fastpix_bit) {
                    printf(
                        '<i class="%s" style="left:%s%%;top:%s%%;width:calc(%d * var(--u));height:calc(%d * var(--u));background:%s;transform:translate(-50%%,-50%%) rotate(%ddeg)"></i>',
                        $fastpix_bit[2] === $fastpix_bit[3] ? 'dot' : '',
                        (float) $fastpix_bit[0],
                        (float) $fastpix_bit[1],
                        (int) $fastpix_bit[2],
                        (int) $fastpix_bit[3],
                        esc_attr($fastpix_bit[5]),
                        (int) $fastpix_bit[4]
                    );
                }
            ?></span>
            <div class="cinner">
                <div class="fp-done-icons" aria-hidden="true">
                    <span class="fp-done-tile wp"><img src="<?php echo esc_url(FASTPIX_PLUGIN_URL . 'assets/images/onboarding-done-wordpress.png'); ?>" alt="" width="50" height="50"></span>
                    <img class="fp-done-arrow" src="<?php echo esc_url(FASTPIX_PLUGIN_URL . 'assets/images/onboarding-done-arrow.svg'); ?>" alt="" width="72" height="24">
                    <span class="fp-done-tile fp"><img src="<?php echo esc_url(FASTPIX_PLUGIN_URL . 'assets/images/onboarding-done-fastpix.svg'); ?>" alt="" width="44" height="39"></span>
                </div>
                <h3><?php esc_html_e('All set! You are connected to FastPix', 'fastpix'); ?></h3>
                <p class="fp-done-lede"><?php esc_html_e('Videos you add from here on are streamed by FastPix instead of your web host — with captions, chapters, and a summary generated for every upload.', 'fastpix'); ?></p>
                <p class="fp-done-sub"><?php esc_html_e('Once your first video is watched, the dashboard starts showing plays, watch time, and playback quality.', 'fastpix'); ?></p>
                <a class="fp-done-cta" href="<?php echo esc_url(admin_url('admin.php?page=fastpix-video-library')); ?>"><?php esc_html_e('Open Plugin dashboard', 'fastpix'); ?></a>
            </div>
        </section>
        <?php if (!$fastpix_webhooks_configured) : ?>
        <div class="cwfoot" data-fp-view="done" hidden>
            <span class="micro faint" data-fp-webhook-note><?php esc_html_e('Webhooks are not configured yet, so video state can be up to 15 minutes behind. Logs tells you how to fix it.', 'fastpix'); ?></span>
        </div>
        <?php endif; ?>

        <div class="cwfoot" id="fp-navfoot">
            <span class="right"></span>
            <button type="button" class="btn ghost" id="fp-back" disabled><?php esc_html_e('Back', 'fastpix'); ?></button>
            <button type="button" class="btn" id="fp-next"><?php esc_html_e('Next', 'fastpix'); ?></button>
        </div>
    </div><?php /* /cwcard */ ?>

    <?php /* Under-card note for the connect step (Figma 9342:94781); the REQ-110 disclosure lives on the Welcome step */ ?>
    <div class="fp-under-note" id="fp-under-connect" hidden>
        <p><?php esc_html_e('Uploading and the video library unlock after step 3.', 'fastpix'); ?></p>
    </div>
    </div>
<?php endif; ?>
</div>
