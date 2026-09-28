<?php
/**
 * UI-002 — Videos. The prototype's `videos` screen, one to one: page head with
 * two actions, tabs, .tablenav (search · Table/Grid · ⚙ View), the five-column
 * .tbl, footer count + pager, sticky .bulkbar; empty / filtered-zero / loading
 * states. Rows arrive from GET /videos client-side (keyset paging).
 * Variables: $own_only (bool), $search_on (bool), $paused_uploads (int), $offline (bool).
 */

if (!defined('WPINC')) {
    die;
}
$fastpix_add = admin_url('admin.php?page=fastpix-add-media');
?>
<div class="wrap fastpix-wrap fp-videos">
    <div class="pagehead">
        <div><h1 class="pt"><?php esc_html_e('Videos', 'fastpix'); ?></h1></div>
        <div class="acts">
            <a class="btn sec" href="<?php echo esc_url($fastpix_add); ?>"><?php esc_html_e('Move existing video', 'fastpix'); ?></a>
            <a class="btn" href="<?php echo esc_url($fastpix_add); ?>"><?php esc_html_e('Add video', 'fastpix'); ?></a>
        </div>
    </div>

    <div class="tabs" role="tablist">
        <button type="button" class="on" role="tab" aria-selected="true"><?php esc_html_e('All videos', 'fastpix'); ?><span class="pill" id="fp-lib-total-pill" hidden>…</span></button>
        <?php if (\Fastpix\Fastpix_Live::enabled()) : /* Live streams [WF-008, UI-004L] */ ?>
        <button type="button" role="tab" aria-selected="false" id="fp-tab-live"><?php esc_html_e('Live streams', 'fastpix'); ?> <span class="pill live" id="fp-live-pill" hidden></span></button>
        <?php endif; ?>
    </div>

<?php if (\Fastpix\Fastpix_Live::enabled()) : /* Live streams pane — rows and the opened stream are built client-side [UI-004L] */ ?>
    <div id="fp-live-pane" hidden>
        <?php /* Toolbar: search left, primary Create stream right — no Table/Grid/View here */ ?>
        <div class="tablenav">
            <div class="search">
                <input type="search" id="fp-live-search" placeholder="<?php esc_attr_e('Search streams', 'fastpix'); ?>" aria-label="<?php esc_attr_e('Search streams', 'fastpix'); ?>">
                <span class="sr"><?php esc_html_e('Search live streams', 'fastpix'); ?></span>
            </div>
            <button type="button" class="btn right" id="fp-live-create"><?php esc_html_e('Create stream', 'fastpix'); ?></button>
        </div>

        <div class="fpnotice error" id="fp-live-error" hidden><span class="ni" aria-hidden="true">⚠</span>
            <div class="nb"><p id="fp-live-error-body"></p></div>
        </div>

        <?php /* Create form card (Figma 9393:108540): name · who can watch · record it · Create / Cancel; recording is a create-time choice */ ?>
        <div class="fp-lv-form" id="fp-live-form" hidden>
            <div class="fp-lv-form-head">
                <h2><?php esc_html_e('Create a live stream', 'fastpix'); ?></h2>
                <span><?php esc_html_e('takes a few seconds', 'fastpix'); ?></span>
            </div>
            <div class="fp-lv-form-row">
                <label class="f-name"><span class="lbl"><?php esc_html_e('Name', 'fastpix'); ?></span>
                    <input type="text" id="fp-live-name" placeholder="<?php esc_attr_e('e.g. Thursday webinar', 'fastpix'); ?>"></label>
                <?php /* The BROADCAST takes public | private only — FastPix's live API documents no other value for
                   playbackSettings.accessPolicy. DRM belongs to the recording below, which is an ordinary media.
                   The JS swaps both selects for the in-page menu the rest of the library uses. (owner 2026-09-22) */ ?>
                <?php $fastpix_live_drm = \Fastpix\Fastpix_Settings_Page::drm_configuration_id() !== ''; ?>
                <label class="f-access"><span class="lbl"><?php esc_html_e('Who can watch live', 'fastpix'); ?></span>
                    <select id="fp-live-access">
                        <option value="public"><?php esc_html_e('Public', 'fastpix'); ?></option>
                        <option value="private"><?php esc_html_e('Private', 'fastpix'); ?></option>
                    </select></label>
                <?php /* The broadcast and the recording it becomes are two different things with two different
                   policies: playbackSettings.accessPolicy for the live stream, inputMediaSettings.mediaPolicy for the
                   media it lands as. Both are create-time. Greyed out while "Record it" is off — there is no
                   recording to gate. (owner 2026-09-22) */ ?>
                <label class="f-access f-access-rec"><span class="lbl"><?php esc_html_e('Who can watch the recording', 'fastpix'); ?></span>
                    <select id="fp-live-media-access">
                        <option value="public"><?php esc_html_e('Public', 'fastpix'); ?></option>
                        <option value="private"><?php esc_html_e('Private', 'fastpix'); ?></option>
                        <option value="drm"<?php if (!$fastpix_live_drm) : ?> disabled title="<?php esc_attr_e('Add a DRM configuration ID under FastPix → Settings to use DRM.', 'fastpix'); ?>"<?php endif; ?>><?php esc_html_e('DRM', 'fastpix'); ?></option>
                    </select></label>
                <label class="f-rec"><input type="checkbox" id="fp-live-rec" checked>
                    <span><?php esc_html_e('Record it', 'fastpix'); ?></span></label>
                <button type="button" class="btn" id="fp-live-submit"><?php esc_html_e('Create', 'fastpix'); ?></button>
                <button type="button" class="btn ghost" id="fp-live-cancel"><?php esc_html_e('Cancel', 'fastpix'); ?></button>
            </div>
            <p class="fp-lv-hint"><?php esc_html_e("Recording can't be turned on or off later. When the broadcast ends, the recording lands in your library as an ordinary video.", 'fastpix'); ?></p>
        </div>

        <?php /* The active (live/preparing) stream is lifted out of the table into #fp-live-card by the JS. Idle/ended rows: Name · State · Recording · tools */ ?>
        <table class="tbl" id="fp-livetable" hidden>
            <thead><tr>
                <th class="lv-name"><?php esc_html_e('Name', 'fastpix'); ?></th>
                <th class="lv-state"><?php esc_html_e('State', 'fastpix'); ?></th>
                <th class="lv-rec"><?php esc_html_e('Recording', 'fastpix'); ?></th>
                <th class="lv-tools"></th>
            </tr></thead>
            <tbody id="fp-live-rows"></tbody>
        </table>

        <div class="empty welcome" id="fp-live-empty" hidden>
            <p class="eyebrow"><?php esc_html_e('Welcome to FastPix Live!', 'fastpix'); ?></p>
            <h3><?php esc_html_e('Let\'s get started by creating your first live stream.', 'fastpix'); ?></h3>
            <button type="button" class="btn lg" id="fp-live-empty-create"><?php esc_html_e('Create stream', 'fastpix'); ?></button>
        </div>
    </div>
<?php endif; ?>

<?php /* Connection trouble is NOT announced here: this screen reads the local library, published pages are
   served by the FastPix network, and a platform hiccup is not the reader's problem. Site Health carries the
   detail for whoever needs it, and an action that cannot be completed still says so where it was taken.
   (owner 2026-09-22) */ ?>

<?php if (!empty($paused_uploads)) : ?>
    <div class="fpnotice warning"><span class="ni" aria-hidden="true">⚠</span>
        <div class="nb"><p class="nt"><?php
            /* translators: %d: number of paused uploads */
            echo esc_html(sprintf(_n('%d upload is paused', '%d uploads are paused', $paused_uploads, 'fastpix'), $paused_uploads)); ?></p>
        <p><?php esc_html_e('Nothing was lost. Resume asks for the same file again and continues from where it stopped — the browser cannot reopen a file on its own.', 'fastpix'); ?></p>
        <div class="na"><a class="btn sm" href="<?php echo esc_url($fastpix_add); ?>"><?php esc_html_e('Resume upload', 'fastpix'); ?></a></div></div>
    </div>
<?php endif; ?>

<?php if ($own_only) : ?>
    <div class="fpnotice info"><span class="ni" aria-hidden="true">ⓘ</span>
        <div class="nb"><p class="nt"><?php esc_html_e('You are seeing your own videos', 'fastpix'); ?></p>
        <p><?php esc_html_e('Your role can upload and manage videos you created. Videos uploaded by other people are not shown, and site-wide analytics are not available to you. The plugin defines its own capabilities and an administrator can remap them to roles.', 'fastpix'); ?></p></div>
    </div>
<?php endif; ?>

    <?php /* Toolbar — search · Table/Grid · ⚙ View (filters + rows per page live in its popover) */ ?>
    <div class="tablenav" id="fp-lib-toolbar">
        <div class="search">
            <input type="search" id="fp-lib-search" placeholder="<?php echo esc_attr($search_on ? __('Search titles, transcripts and chapters', 'fastpix') : __('Search titles (transcript search unavailable on this database)', 'fastpix')); ?>" aria-label="<?php esc_attr_e('Search videos', 'fastpix'); ?>">
            <span class="sr"><?php esc_html_e('Search videos', 'fastpix'); ?></span>
        </div>
        <div class="right row">
            <div class="seg" role="group" aria-label="<?php esc_attr_e('Layout', 'fastpix'); ?>">
                <button type="button" class="on" id="fp-view-table" aria-pressed="true"><?php esc_html_e('Table', 'fastpix'); ?></button>
                <button type="button" id="fp-view-grid" aria-pressed="false"><?php esc_html_e('Grid', 'fastpix'); ?></button>
            </div>
            <div class="fp-viewwrap">
                <button type="button" class="btn ghost sm" id="fp-view-btn" aria-expanded="false" aria-controls="fp-view-pop" title="<?php esc_attr_e('Columns, density, rows per page', 'fastpix'); ?>"><svg viewBox="0 0 24 24" width="15" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="3.2"/><path d="M12 2.5v2.8M12 18.7v2.8M2.5 12h2.8M18.7 12h2.8M5.3 5.3l2 2M16.7 16.7l2 2M5.3 18.7l2-2M16.7 7.3l2-2"/></svg><?php esc_html_e('View', 'fastpix'); ?></button>
                <div class="fp-viewpop" id="fp-view-pop" hidden>
                    <label><span><?php esc_html_e('Status', 'fastpix'); ?></span>
                        <select id="fp-lib-status">
                            <option value=""><?php esc_html_e('Any', 'fastpix'); ?></option>
                            <option value="Ready"><?php esc_html_e('Ready', 'fastpix'); ?></option>
                            <option value="Processing"><?php esc_html_e('Processing', 'fastpix'); ?></option>
                            <option value="Failed"><?php esc_html_e('Failed', 'fastpix'); ?></option>
                            <option value="Unavailable"><?php esc_html_e('Unavailable', 'fastpix'); ?></option>
                        </select></label>
                    <label><span><?php esc_html_e('Access', 'fastpix'); ?></span>
                        <select id="fp-lib-access">
                            <option value=""><?php esc_html_e('Any', 'fastpix'); ?></option>
                            <option value="public"><?php esc_html_e('Public', 'fastpix'); ?></option>
                            <option value="private"><?php esc_html_e('Private', 'fastpix'); ?></option>
                            <option value="drm"><?php esc_html_e('DRM', 'fastpix'); ?></option>
                        </select></label>
                    <label><span><?php esc_html_e('Source', 'fastpix'); ?></span>
                        <select id="fp-lib-source">
                            <option value=""><?php esc_html_e('Any', 'fastpix'); ?></option>
                            <option value="Upload"><?php esc_html_e('Upload', 'fastpix'); ?></option>
                            <option value="URL"><?php esc_html_e('URL', 'fastpix'); ?></option>
                            <option value="Migrated"><?php esc_html_e('Migrated', 'fastpix'); ?></option>
                            <option value="Dashboard"><?php esc_html_e('Dashboard', 'fastpix'); ?></option>
                            <?php if (\Fastpix\Fastpix_Live::enabled()) : ?>
                            <option value="Live"><?php esc_html_e('Live', 'fastpix'); ?></option>
                            <?php endif; ?>
                        </select></label>
                    <label><span><?php esc_html_e('Rows per page', 'fastpix'); ?></span>
                        <select id="fp-lib-perpage">
                            <option value="25">25</option><option value="50">50</option><option value="100">100</option>
                        </select></label>
                    <label class="fp-viewpop__dense"><input type="checkbox" id="fp-lib-dense"> <span><?php esc_html_e('Compact rows', 'fastpix'); ?></span></label>
                </div>
            </div>
        </div>
        <div class="fp-chips" id="fp-lib-chips" hidden>
            <span class="micro faint"><?php esc_html_e('Filtered:', 'fastpix'); ?></span>
            <span id="fp-lib-chiplist"></span>
            <button type="button" class="lnk micro" id="fp-lib-clearall"><?php esc_html_e('Clear all', 'fastpix'); ?></button>
        </div>
    </div>

    <div class="fpnotice error" id="fp-lib-error" hidden><span class="ni" aria-hidden="true">⚠</span>
        <div class="nb"><p id="fp-lib-error-body"></p></div>
    </div>

    <table class="tbl" id="fp-libtable">
        <thead>
            <tr>
                <th class="cbcol"><input type="checkbox" id="fp-check-all" aria-label="<?php esc_attr_e('Select all', 'fastpix'); ?>"></th>
                <th class="thcol"><?php esc_html_e('Video', 'fastpix'); ?></th>
                <th class="sortable" id="fp-sort-title" tabindex="0" aria-sort="none" title="<?php esc_attr_e('Sort by title', 'fastpix'); ?>"><?php esc_html_e('Title', 'fastpix'); ?></th>
                <th class="idcol"><?php esc_html_e('Media ID', 'fastpix'); ?></th>
                <th class="idcol"><?php esc_html_e('Playback ID', 'fastpix'); ?></th>
                <th><?php esc_html_e('Status', 'fastpix'); ?></th>
                <th class="acccol"><?php esc_html_e('Access', 'fastpix'); ?></th>
                <th class="toolcol"></th>
            </tr>
        </thead>
        <tbody id="fp-lib-rows"></tbody>
    </table>

    <?php /* filtered-zero: white panel; echoes every active filter + closest match [UI-002] */ ?>
    <div class="empty white" id="fp-lib-zero" hidden>
        <div class="em">⌕</div>
        <h3><?php esc_html_e('Nothing matches those filters', 'fastpix'); ?></h3>
        <p id="fp-lib-zero-filters"></p>
        <p class="small" id="fp-lib-zero-hint" hidden></p>
        <button type="button" class="btn sec" id="fp-lib-zero-clear-filters"><?php esc_html_e('Clear filters', 'fastpix'); ?></button>
        <button type="button" class="btn ghost" id="fp-lib-zero-clear"><?php esc_html_e('Clear all filters', 'fastpix'); ?></button>
    </div>

    <?php /* empty library: the welcome card (owner 2026-09-10) — one way in, the Add media page [REQ-034] */ ?>
    <div class="empty welcome" id="fp-lib-empty" hidden>
        <p class="eyebrow"><?php esc_html_e('Welcome to FastPix!', 'fastpix'); ?></p>
        <h3><?php esc_html_e('Let\'s get started by uploading your first video.', 'fastpix'); ?></h3>
        <a class="btn lg" href="<?php echo esc_url($fastpix_add); ?>"><?php esc_html_e('Upload media', 'fastpix'); ?></a>
    </div>

    <div class="tablenav" id="fp-lib-foot">
        <span class="count" id="fp-lib-count"></span>
        <div class="pager" id="fp-lib-pager"></div>
    </div>

    <?php /* Bulk bar [FR-033]: sticky, dark; queued in the background, never inline. */ ?>
    <div class="bulkbar" id="fp-bulkbar" hidden>
        <b id="fp-bulk-count">0 selected</b>
        <select class="inline-in" id="fp-bulk-action" style="max-width:200px" aria-label="<?php esc_attr_e('Bulk action', 'fastpix'); ?>">
            <option value=""><?php esc_html_e('Bulk actions', 'fastpix'); ?></option>
            <option value="rerun_ai"><?php esc_html_e('Re-run AI', 'fastpix'); ?></option>
            <option value="regenerate_posters"><?php esc_html_e('Regenerate posters', 'fastpix'); ?></option>
            <option value="delete"><?php esc_html_e('Delete…', 'fastpix'); ?></option>
        </select>
        <button type="button" class="btn sm sec" id="fp-bulk-apply"><?php esc_html_e('Apply', 'fastpix'); ?></button>
        <span class="right small bulknote"><?php esc_html_e('Bulk actions run in the background — you can leave this page.', 'fastpix'); ?></span>
        <button type="button" class="btn sm ghost" id="fp-bulk-clear"><?php esc_html_e('Clear', 'fastpix'); ?></button>
    </div>
</div>
