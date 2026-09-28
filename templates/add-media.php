<?php
/**
 * UI-004 — Add videos (Figma 9376:104326 page + 9437:116537 modal). One card:
 * drop zone, link field, the staged batch, "Next — name & settings". Settings
 * live in a modal and NOTHING transfers until "Upload N videos" (owner ruling
 * 2026-09-04, ASSUME-054). Behaviour: assets/js/add-media.js over the real
 * /uploads and /videos routes.
 * Variables: $can_upload (bool), $connected (bool), $settings (array — REQ-017 defaults).
 */

if (!defined('WPINC')) {
    die;
}

$fastpix_sel = function ($id, $label, $options, $selected, $disabled = array(), $hint = '') {   // $disabled: value => reason, shown as the option's tooltip
    ?>
    <div>
        <span class="lab" id="l-<?php echo esc_attr($id); ?>"><?php echo esc_html($label); ?><?php if ($hint !== '') : ?> <span class="lab__hint"><?php echo esc_html($hint); ?></span><?php endif; ?></span>
        <div class="select" id="fp-set-<?php echo esc_attr($id); ?>" tabindex="0" role="combobox" aria-expanded="false" aria-haspopup="listbox" aria-controls="menu-<?php echo esc_attr($id); ?>" aria-labelledby="l-<?php echo esc_attr($id); ?>" data-value="<?php echo esc_attr($selected); ?>">
            <span class="select__val"><?php echo esc_html($options[$selected]); ?></span>
            <svg class="select__chev" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M4 6.5L8 10L12 6.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
            <ul class="select__menu" role="listbox" id="menu-<?php echo esc_attr($id); ?>">
                <?php foreach ($options as $fastpix_val => $fastpix_text) : ?>
                <li role="option" data-value="<?php echo esc_attr($fastpix_val); ?>" aria-selected="<?php echo $fastpix_val === $selected ? 'true' : 'false'; ?>"<?php if (isset($disabled[$fastpix_val])) : ?> aria-disabled="true" title="<?php echo esc_attr($disabled[$fastpix_val]); ?>"<?php endif; ?>><?php echo esc_html($fastpix_text); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
    <?php
};
$fastpix_sw = function ($id, $label, $on) {
    ?><button type="button" class="sw" role="switch" id="fp-sw-<?php echo esc_attr($id); ?>" aria-checked="<?php echo $on ? 'true' : 'false'; ?>"><span><?php echo esc_html($label); ?></span><span class="track"><span class="knob"></span></span></button><?php
};
$fastpix_lang = Fastpix\Fastpix_Utils::get_language_map();   // the platform's auto-subtitle languages
$fastpix_sub  = isset($settings['subtitles']) && isset($fastpix_lang[$settings['subtitles']]) ? $settings['subtitles'] : 'en';
?>
<div class="wrap fastpix-wrap">
<div class="fp-am">
  <h1 class="fp-am-title"><?php esc_html_e('Add videos', 'fastpix'); ?></h1>
  <p class="fp-am-sub"><?php esc_html_e("Add the files first — you'll name them and choose settings next.", 'fastpix'); ?></p>

<?php if (!$can_upload) : ?>
    <?php /* UI-004 restricted: surfaces disabled, reason stated. */ ?>
    <div class="fpnotice info">
        <span class="ni" aria-hidden="true">ⓘ</span>
        <div class="nb"><p class="nt"><?php esc_html_e('Your role cannot upload video', 'fastpix'); ?></p>
        <p><?php esc_html_e('Uploading needs the upload video capability. An administrator can grant it without granting access to settings or credentials.', 'fastpix'); ?></p></div>
    </div>
<?php elseif (!$connected) : ?>
    <div class="fpnotice info">
        <span class="ni" aria-hidden="true">ⓘ</span>
        <div class="nb"><p class="nt"><?php esc_html_e('Not connected', 'fastpix'); ?></p>
        <p><?php esc_html_e('Connect this site to a FastPix workspace first.', 'fastpix'); ?></p>
        <div class="na"><a class="btn sm" href="<?php echo esc_url(admin_url('admin.php?page=fastpix-connection')); ?>"><?php esc_html_e('Open Connection', 'fastpix'); ?></a></div></div>
    </div>
<?php else : ?>

    <?php /* No "FastPix is not responding" banner: a file that cannot be queued says so on its own row. (owner 2026-09-22) */ ?>

<?php /* Upload card (Figma 9376:104326): 760×378 gradient, 16px inset; drop zone 728×184; URL row 643 + 73 */ ?>
  <section class="fp-am-card">
    <p class="fp-am-lead"><?php esc_html_e('Upload a file', 'fastpix'); ?></p>

    <div class="drop" id="fp-drop" role="button" tabindex="0" onkeydown="if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); this.querySelector('#fp-file-input').click(); }" aria-label="<?php esc_attr_e('Choose video or audio files', 'fastpix'); ?>">
      <img class="drop__ico" src="<?php echo esc_url(FASTPIX_PLUGIN_URL . 'assets/images/add-media-upload-cloud.svg'); ?>" alt="" width="53" height="48">
      <h3><?php esc_html_e('Drag & drop video and audio or', 'fastpix'); ?> <button type="button" class="linkbtn" id="fp-pick"><?php esc_html_e('Browse', 'fastpix'); ?></button></h3>
      <p class="dim"><?php esc_html_e('You can upload multiple files at once.', 'fastpix'); ?></p>
      <input type="file" id="fp-file-input" multiple accept="video/*,audio/*,.mkv,.mts,.m2ts,.mxf,.rm,.wtv,.vob,.ts" aria-label="<?php esc_attr_e('Video or audio files to upload', 'fastpix'); ?>" hidden>
    </div>

    <p class="fp-am-urllab"><?php esc_html_e('Or upload using video URL', 'fastpix'); ?></p>
    <div class="fp-am-links">
      <input class="urlinput" id="fp-urls" type="text" placeholder="<?php esc_attr_e('Paste your video URL here...', 'fastpix'); ?>" spellcheck="false" aria-label="<?php esc_attr_e('Video links to add', 'fastpix'); ?>">
      <button type="button" class="fp-am-addlinks" id="fp-ingest" disabled><?php esc_html_e('Upload', 'fastpix'); ?></button>
    </div>
    <a class="fp-am-go fp-am-plat" href="https://fastpix.com/docs/upload-videos/upload-videos-from-a-url" target="_blank" rel="noopener"><?php esc_html_e('Supported platforms', 'fastpix'); ?><img src="<?php echo esc_url(FASTPIX_PLUGIN_URL . 'assets/images/onboarding-link-arrow.svg'); ?>" alt="" width="16" height="16"></a>

    <?php /* The batch: staged files and links, then their upload progress. Appears only once something is staged (the frame draws the empty state). */ ?>
    <?php /* No aria-live here: it wraps every row, and progress rewrites each row on every chunk — a screen reader read "Uploading 3%… 4%…" without pause. State changes still re-render the row text. (QA 2026-09-22) */ ?>
    <div class="queue__list" id="fp-queue" hidden></div>

    <div class="fp-am-foot" id="fp-am-foot" hidden>
      <p><?php esc_html_e('Keep this tab open until uploads finish — other pages open in a new tab meanwhile. If you leave, pick the same file again to resume.', 'fastpix'); ?></p>
      <button type="button" class="fp-am-next" id="fp-next" disabled><?php esc_html_e('Next — name & settings', 'fastpix'); ?></button>
    </div>
  </section>

  <?php if (!empty($can_migrate)) : ?>
  <?php /* Migration card — below the upload card, per UI-004M / screen-006. */ ?>
  <?php fastpix_template('partials/card-migration.php'); ?>
  <?php endif; ?>

  <?php /* Media settings for this batch (Figma 9489:119243, was 9437:116537). REQ-017 batch settings. */ ?>
  <div class="fp-am-modal" id="fp-am-modal" hidden>
    <div class="fp-am-modal__box" role="dialog" aria-modal="true" aria-labelledby="fp-am-modal-title">
      <button type="button" class="fp-am-modal__x" id="fp-am-close" aria-label="<?php esc_attr_e('Close', 'fastpix'); ?>">×</button>
      <h2 id="fp-am-modal-title"><?php esc_html_e('Media settings', 'fastpix'); ?></h2>
      <p class="fp-am-modal__sub" id="fp-am-modal-sub"></p>

      <?php /* Header and footer stay put; only this part scrolls (dialog capped at 82vh). */ ?>
      <div class="fp-am-modal__body">

      <label class="fp-am-lab" for="fp-set-title"><?php esc_html_e('Title', 'fastpix'); ?> <span><?php esc_html_e("(optional — blank uses each file's name)", 'fastpix'); ?></span></label>
      <input type="text" id="fp-set-title" class="titleinput" maxlength="255" autocomplete="off" spellcheck="false" placeholder="<?php esc_attr_e('e.g. June product webinars', 'fastpix'); ?>">

      <div class="fp-am-selects">
        <?php
        // DRM needs a DRM configuration ID (Settings): without one the option is there but cannot be
        // picked, and a saved DRM default falls back to Private. The server refuses it too. (QA 2026-09-21)
        $fastpix_drm_ready = \Fastpix\Fastpix_Settings_Page::drm_configuration_id() !== '';
        $fastpix_access    = $settings['access_policy'] ?? 'public';
        if ($fastpix_access === 'drm' && !$fastpix_drm_ready) { $fastpix_access = 'private'; }
        $fastpix_sel('access', __('Who can watch', 'fastpix'), array('public' => __('Public', 'fastpix'), 'private' => __('Private', 'fastpix'), 'drm' => __('DRM', 'fastpix')), $fastpix_access,
            $fastpix_drm_ready ? array() : array('drm' => __('Add a DRM configuration ID under FastPix → Settings to use DRM.', 'fastpix')));
        $fastpix_sel('tier', __('Quality', 'fastpix'), array('standard' => __('Standard', 'fastpix'), 'pro' => __('Pro', 'fastpix'), 'premium' => __('Premium', 'fastpix')), $settings['quality_tier'] ?? 'standard');
        $fastpix_sel('res', __('Top resolution', 'fastpix'), array('720p' => '720p', '1080p' => '1080p', '1440p' => '1440p', '2160p' => '2160p'), $settings['max_resolution'] ?? '1080p');
        $fastpix_sel('download', __('Allow downloads', 'fastpix'), array('off' => __('Off', 'fastpix'), 'video' => __('Video (MP4)', 'fastpix'), 'audio' => __('Audio only (M4A)', 'fastpix'), 'both' => __('Video + audio', 'fastpix')), $settings['downloadable'] ?? 'off');
        ?>
      </div>

      <div class="fp-am-toggles">
        <?php $fastpix_sw('audio', __('Even out volume', 'fastpix'), false); ?>
        <div class="fp-am-subs off" id="subrow">
          <?php $fastpix_sw('subtitles', __('Subtitles ·', 'fastpix'), false); ?>
          <div class="select" id="fp-set-lang" tabindex="-1" role="combobox" aria-disabled="true" aria-expanded="false" aria-haspopup="listbox" aria-controls="menu-lang" aria-label="<?php esc_attr_e('Subtitle language', 'fastpix'); ?>" data-value="<?php echo esc_attr($fastpix_sub); ?>">
            <span class="select__val"><?php echo esc_html(Fastpix\Fastpix_Utils::get_language_label($fastpix_sub)); ?></span>
            <svg class="select__chev" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M4 6.5L8 10L12 6.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
            <ul class="select__menu" role="listbox" id="menu-lang">
              <?php foreach ($fastpix_lang as $fastpix_val => $fastpix_text) : ?>
              <li role="option" data-value="<?php echo esc_attr($fastpix_val); ?>" aria-selected="<?php echo $fastpix_val === $fastpix_sub ? 'true' : 'false'; ?>"><?php echo esc_html(Fastpix\Fastpix_Utils::get_language_label($fastpix_val)); ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
        </div>
        <label class="check">
          <input type="checkbox" id="fp-set-domainlock" checked aria-controls="fp-lock">
          <span class="box"></span>
          <span class="txt"><?php esc_html_e('Lock to this site', 'fastpix'); ?></span>
        </label>
        <?php $fastpix_sw('wm', __('Watermark', 'fastpix'), false); ?>
      </div>

      <?php /* Domain lock: default policy + the one list that applies to it (ASSUME-073). */ ?>
      <div class="fp-am-lock" id="fp-lock">
        <div class="fp-am-lock__head">
          <span class="lab"><?php esc_html_e('Sites not on the list', 'fastpix'); ?></span>
          <div class="fp-am-seg" role="group" aria-label="<?php esc_attr_e('Sites not on the list', 'fastpix'); ?>">
            <button type="button" id="fp-pol-deny" aria-pressed="true"><?php esc_html_e('Blocked', 'fastpix'); ?></button>
            <button type="button" id="fp-pol-allow" aria-pressed="false"><?php esc_html_e('Allowed', 'fastpix'); ?></button>
          </div>
        </div>
        <div id="fp-lock-allow">
          <label class="lab" for="fp-allow-in"><?php esc_html_e('Allow on', 'fastpix'); ?></label>
          <div class="fp-am-chips" id="fp-allow-chips">
            <span class="chip site" title="<?php esc_attr_e('This site is always allowed', 'fastpix'); ?>"><?php echo esc_html(wp_parse_url(home_url(), PHP_URL_HOST)); ?></span>
            <input type="text" id="fp-allow-in" autocomplete="off" spellcheck="false" placeholder="<?php esc_attr_e('Add a domain, press Enter', 'fastpix'); ?>">
          </div>
          <p class="fp-am-err" id="fp-allow-err" hidden></p>
          <p class="fp-am-hint"><?php esc_html_e('Your site is always here. Add www or other domains you own.', 'fastpix'); ?></p>
        </div>
        <div id="fp-lock-deny" hidden>
          <label class="lab" for="fp-deny-in"><?php esc_html_e('Block on', 'fastpix'); ?></label>
          <div class="fp-am-chips" id="fp-deny-chips">
            <input type="text" id="fp-deny-in" autocomplete="off" spellcheck="false" placeholder="<?php esc_attr_e('Add a domain, press Enter', 'fastpix'); ?>">
          </div>
          <p class="fp-am-err" id="fp-deny-err" hidden></p>
          <p class="fp-am-hint"><?php esc_html_e('Every other site can play the video.', 'fastpix'); ?></p>
        </div>
        <p class="fp-am-lock__sum" id="fp-lock-sum"></p>
      </div>

      <?php /* Watermark: burned in during encoding, so it is settled at creation like who-can-watch
             and the quality tier — never editable afterwards. Off → no watermark input is sent. */ ?>
      <div class="fp-am-lock fp-am-wmcard" id="fp-wm-card" hidden>
        <label class="lab" for="fp-set-wm"><?php esc_html_e('Image URL', 'fastpix'); ?></label>
        <input type="url" id="fp-set-wm" class="titleinput" maxlength="1000" autocomplete="off" spellcheck="false" placeholder="https://example.com/logo.png" aria-describedby="fp-wm-hint">
        <p class="fp-am-err" id="fp-wm-err" role="alert" hidden></p>
        <div class="fp-am-selects">
          <?php
          $fastpix_sel('wmpos', __('Position', 'fastpix'), \Fastpix\Fastpix_Uploads_Settings::watermark_positions(), 'top-left');
          $fastpix_sel('wmmargin', __('Margin', 'fastpix'), \Fastpix\Fastpix_Uploads_Settings::watermark_margins(), '6%', array(), __('gap from edge', 'fastpix'));
          $fastpix_sel('wmsize', __('Size', 'fastpix'), \Fastpix\Fastpix_Uploads_Settings::watermark_sizes(), '10%');
          $fastpix_sel('wmopacity', __('Opacity', 'fastpix'), \Fastpix\Fastpix_Uploads_Settings::watermark_opacities(), '65%');
          ?>
        </div>
        <p class="fp-am-hint" id="fp-wm-hint"><?php esc_html_e('Public image URL, transparent PNG works best. Burned into the video, so it cannot be changed later.', 'fastpix'); ?></p>
      </div>
      </div>

      <div class="fp-am-modal__foot">
        <span><?php esc_html_e('Nothing uploads until you press Upload.', 'fastpix'); ?></span>
        <button type="button" class="fp-am-cancel" id="fp-am-cancel"><?php esc_html_e('Cancel', 'fastpix'); ?></button>
        <button type="button" class="fp-am-upload" id="fp-upload"><?php esc_html_e('Upload', 'fastpix'); ?></button>
      </div>
    </div>
  </div>

<?php endif; ?>
</div>
</div>
