<?php
/**
 * UI-005 — Analytics. The shell; every figure arrives client-side from the
 * local rollup routes and carries its stored age (REQ-063). States (loading,
 * no-data, stale, filtered, restricted, export) are driven by analytics-page.js.
 */

if (!defined('WPINC')) {
    die;
}
?>
<div class="wrap fastpix-wrap fp-videos fp-analytics">
    <div class="pagehead">
        <div>
            <h1 class="pt"><?php esc_html_e('Analytics', 'fastpix-io'); ?></h1>
            <p class="ps" id="fp-an-subtitle"><?php esc_html_e('Across every video on this site', 'fastpix-io'); ?></p>
        </div>
        <div class="acts">
            <button type="button" class="btn sec" id="fp-an-export-btn"><?php esc_html_e('Export CSV', 'fastpix-io'); ?></button>
        </div>
    </div>

    <div class="tabs" role="tablist">
        <button type="button" class="on" role="tab" aria-selected="true" id="fp-an-tab-site"><?php esc_html_e('Across the site', 'fastpix-io'); ?></button>
        <?php /* Shown by the JS only once a video is chosen — you arrive from a video's row in the library (owner 2026-09-21). */ ?>
        <button type="button" role="tab" aria-selected="false" id="fp-an-tab-video" hidden><?php esc_html_e('One video', 'fastpix-io'); ?></button>
        <?php if (\Fastpix\Fastpix_Lms::enabled()) : /* only when course features are switched on */ ?>
        <button type="button" role="tab" aria-selected="false" id="fp-an-tab-courses"><?php esc_html_e('LMS', 'fastpix-io'); ?></button>
        <?php endif; ?>
    </div>

    <div class="fbar" id="fp-an-fbar">
        <span class="fbar-lab"><?php esc_html_e('Showing', 'fastpix-io'); ?></span>
        <select id="fp-an-range" aria-label="<?php esc_attr_e('Date range', 'fastpix-io'); ?>">
            <option value="60m" class="fp-an-subday"><?php esc_html_e('Last 60 mins', 'fastpix-io'); ?></option>
            <option value="6h" class="fp-an-subday"><?php esc_html_e('Last 6 hours', 'fastpix-io'); ?></option>
            <option value="24h" class="fp-an-subday" selected><?php esc_html_e('Last 24 hours', 'fastpix-io'); ?></option>
            <option value="3"><?php esc_html_e('Last 3 days', 'fastpix-io'); ?></option>
            <option value="7"><?php esc_html_e('Last 7 days', 'fastpix-io'); ?></option>
            <option value="30"><?php esc_html_e('Last 30 days', 'fastpix-io'); ?></option>
            <option value="custom"><?php esc_html_e('Custom…', 'fastpix-io'); ?></option>
        </select>
        <span id="fp-an-custom" hidden>
            <input type="date" id="fp-an-from" aria-label="<?php esc_attr_e('From', 'fastpix-io'); ?>">
            <span>–</span>
            <input type="date" id="fp-an-to" aria-label="<?php esc_attr_e('To', 'fastpix-io'); ?>">
        </span>
        <select id="fp-an-device" aria-label="<?php esc_attr_e('Device', 'fastpix-io'); ?>">
            <option value=""><?php esc_html_e('All devices', 'fastpix-io'); ?></option>
            <option value="Mobile"><?php esc_html_e('Mobile', 'fastpix-io'); ?></option>
            <option value="Desktop"><?php esc_html_e('Desktop', 'fastpix-io'); ?></option>
            <option value="Tablet"><?php esc_html_e('Tablet', 'fastpix-io'); ?></option>
        </select>
        <span class="fbar-age" id="fp-an-age"></span>
    </div>

    <div id="fp-an-notices"></div>

    <div id="fp-an-video-head" hidden></div>

    <div id="fp-an-body" aria-live="polite">
        <div class="fp-an-skel" id="fp-an-loading">
            <div class="fp-an-kpis"><div class="fp-an-tile sk"></div><div class="fp-an-tile sk"></div><div class="fp-an-tile sk"></div><div class="fp-an-tile sk"></div></div>
            <div class="fp-an-two"><div class="fp-an-card sk" style="height:280px"></div><div class="fp-an-card sk" style="height:280px"></div></div>
        </div>
        <div id="fp-an-content" hidden></div>
        <div id="fp-an-empty" hidden></div>
    </div>

    <div class="fp-an-dialog-back" id="fp-an-export" hidden>
        <div class="fp-an-dialog" role="dialog" aria-modal="true" aria-labelledby="fp-an-export-title">
            <h2 id="fp-an-export-title"><?php esc_html_e('Export CSV', 'fastpix-io'); ?></h2>
            <button type="button" class="fp-an-dialog-x" id="fp-an-export-x" aria-label="<?php esc_attr_e('Close', 'fastpix-io'); ?>">×</button>
            <label class="fp-an-drow"><span><?php esc_html_e('Date range', 'fastpix-io'); ?></span>
                <select id="fp-an-export-range">
                    <option value="30"><?php esc_html_e('Last 30 days', 'fastpix-io'); ?></option>
                    <option value="90"><?php esc_html_e('Last 90 days', 'fastpix-io'); ?></option>
                    <option value="all"><?php esc_html_e('All available', 'fastpix-io'); ?></option>
                </select>
            </label>
            <p class="fp-an-inc-title"><?php esc_html_e('Include', 'fastpix-io'); ?></p>
            <label class="fp-an-inc"><input type="checkbox" id="fp-an-export-every" checked> <?php esc_html_e('Every video, not just the one on screen', 'fastpix-io'); ?></label>
            <label class="fp-an-inc"><input type="checkbox" id="fp-an-export-days" checked> <?php esc_html_e('The per-day rows behind each figure', 'fastpix-io'); ?></label>
            <label class="fp-an-inc"><input type="checkbox" id="fp-an-export-device"> <?php esc_html_e('Device breakdown', 'fastpix-io'); ?></label>
            <div class="fpnotice info"><span class="ni" aria-hidden="true">ℹ</span><div class="nb">
                <p><?php esc_html_e('This file contains more than the screen does — the screen shows totals. The export carries every video in the range at full precision, day by day.', 'fastpix-io'); ?></p>
            </div></div>
            <p class="fp-an-help"><?php esc_html_e('Export runs as a background job — the file appears here when it is ready. You can leave the page.', 'fastpix-io'); ?></p>
            <div id="fp-an-export-status"></div>
            <div class="fp-an-dialog-acts">
                <button type="button" class="btn sec" id="fp-an-export-cancel"><?php esc_html_e('Cancel', 'fastpix-io'); ?></button>
                <button type="button" class="btn" id="fp-an-export-start"><?php esc_html_e('Start export', 'fastpix-io'); ?></button>
            </div>
        </div>
    </div>
</div>
