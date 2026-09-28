<?php
/**
 * UI-004M — the migration card (screen-006). A shell; assets/js/migration.js
 * draws the shape the batch is in: choose → migrating → verify → cleanup →
 * history. Owner only (fastpix_manage_settings). Copy is the prototype's.
 */

if (!defined('WPINC')) {
    die;
}
?>
<section class="card fp-mig" id="fp-mig" data-state="idle">
  <?php /* Figma 9376:104326: 760×131, 16px inset, title · copy · Scan + Past migrations. The hint is JS-driven and hidden while idle. */ ?>
  <div class="card__body fp-mig__body" id="fp-mig-body">
    <h2 class="fp-mig__h"><?php esc_html_e('Already have video on this site', 'fastpix'); ?></h2>
    <span class="hint" id="fp-mig-hint"><?php esc_html_e('Moving one copies it and leaves the original where it is.', 'fastpix'); ?></span>
    <p class="fp-mig__intro"><?php esc_html_e('Scan the media library first - it reports what can move, what will be skipped and why. Nothing moves until you decide.', 'fastpix'); ?></p>
    <div class="fp-mig__acts">
      <button type="button" class="btn" id="fp-mig-scan"><?php esc_html_e('Scan the Media Library', 'fastpix'); ?></button>
      <button type="button" class="linkbtn" id="fp-mig-history"><?php esc_html_e('Past migrations', 'fastpix'); ?></button>
    </div>
  </div>
</section>
