<?php
/**
 * Global plugin header — one partial, included by every FastPix admin screen.
 * White card, local logo (REQ-112: bundled, no CDN), version chip from
 * FASTPIX_VERSION, 3px gradient accent along the bottom.
 *
 * Set $fastpix_header_full = true before including on full-width screens (the
 * wizard); default caps at the 1160px page container.
 */

if (!defined('WPINC')) {
    die;
}

$fastpix_header_full = !empty($fastpix_header_full);
?>
<div class="fp-plugin-header<?php echo $fastpix_header_full ? ' full' : ''; ?>">
    <div class="fp-plugin-header-bar">
        <img class="fp-plugin-logo"
             src="<?php echo esc_url(FASTPIX_PLUGIN_URL . 'assets/images/fastpix-logo.svg'); ?>"
             alt="FastPix" height="24">
        <span class="fp-version-chip">v<?php echo esc_html(FASTPIX_VERSION); ?></span>
    </div>
    <div class="fp-plugin-header-accent" aria-hidden="true"></div>
</div>
