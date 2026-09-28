/**
 * UI-004M — the migration card's five shapes (screen-006), over the
 * /migration/* routes (API-P08). Everything is built with textContent —
 * titles and paths are the site's own data, kept as text.
 */
(function () {
    'use strict';

    if (typeof fastpixAddMedia === 'undefined' || !fastpixAddMedia.canMigrate) { return; }
    var cfg = fastpixAddMedia;
    var __ = wp.i18n.__, _n = wp.i18n._n, sprintf = wp.i18n.sprintf;   // [QA L25]
    var card = document.getElementById('fp-mig'), body = document.getElementById('fp-mig-body'), hint = document.getElementById('fp-mig-hint');
    if (!card) { return; }

    function api(method, path, data) {
        return fetch(cfg.restUrl + path, {
            method: method, headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce }, credentials: 'same-origin',
            body: data ? JSON.stringify(data) : undefined
        }).then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { return { ok: r.ok, status: r.status, json: j || {} }; }); });
    }
    function el(tag, cls, text) { var e = document.createElement(tag); if (cls) { e.className = cls; } if (text !== undefined && text !== null) { e.textContent = text; } return e; }
    function btn(label, cls, fn) { var b = el('button', 'btn ' + (cls || ''), label); b.type = 'button'; b.addEventListener('click', fn); return b; }
    function size(b) { var u = ['B', 'KB', 'MB', 'GB', 'TB'], i = 0; b = Number(b) || 0; while (b >= 1024 && i < 4) { b /= 1024; i++; } return b.toFixed(b < 10 && i > 0 ? 1 : 0) + ' ' + u[i]; }
    function n(x) { return Number(x || 0).toLocaleString(); }
    // QA F6: history reads like a record, not a dump — dates as dates, settings as words.
    // Same three words as the library's ACCESS column and every policy dropdown. (owner 2026-09-22)
    var ACCESS = { public: __('Public', 'fastpix'), private: __('Private', 'fastpix'), drm: __('DRM', 'fastpix') }, TIER = { standard: __('Standard', 'fastpix'), pro: __('Pro', 'fastpix'), premium: __('Premium', 'fastpix') };
    function when(iso) { if (!iso) { return '—'; } var d = new Date(iso.replace(' ', 'T') + (/Z|[+-]\d\d:?\d\d$/.test(iso) ? '' : 'Z')); return isNaN(d) ? iso.slice(0, 16) : d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' }) + ' ' + d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' }); }
    function batchSettings() {
        // The one up-front decision comes from the batch-settings card above (RULE-007).
        var access = document.getElementById('fp-set-access'), tier = document.getElementById('fp-set-tier');
        return { access_policy: access ? access.getAttribute('data-value') : 'public', quality_tier: tier ? tier.getAttribute('data-value') : 'standard' };
    }
    function setState(s) { card.setAttribute('data-state', s); }
    var title = card.querySelector('.fp-mig__h');
    function clear() { body.innerHTML = ''; if (title) { body.appendChild(title); } body.appendChild(hint); }   // the template's title + hint survive every render

    var state = { batch: null, selected: {}, page: 1, timer: null, filter: 'all' };

    /* -------------------------------------------------------------- boot */

    function boot() {
        api('GET', '/migration/history').then(function (res) {
            // M23: a failed first read still leaves a working card — idle, with the reason.
            if (!res.ok) { showIdle(); flash(res.json.message || __('Could not load the migration status — try again.', 'fastpix')); return; }
            var active = res.json.active;
            if (!active) { showIdle(); return; }
            if (active.state === 'scanning' || active.state === 'scanned') { loadScan(); }
            else { loadBatch(active.batch_id); }
        }, function () { showIdle(); flash(__('Could not reach the site — try again.', 'fastpix')); });
    }
    function resetSelection() { state.selected = {}; state.page = 1; state.filter = 'all'; }   // M22: a scan's selection never leaks into the next
    function showIdle() {
        setState('idle'); clear(); resetSelection();
        hint.textContent = __('Moving one copies it and leaves the original where it is.', 'fastpix');
        body.appendChild(el('p', 'fp-mig__intro', __('Scan the Media Library first — it reports what can move, what will be skipped and why. Nothing moves until you decide.', 'fastpix')));
        var acts = el('div', 'fp-mig__acts');
        acts.appendChild(btn(__('Scan the Media Library', 'fastpix'), '', startScan));
        var hist = el('button', 'linkbtn', __('Past migrations', 'fastpix')); hist.type = 'button'; hist.addEventListener('click', showHistory); acts.appendChild(hist);
        body.appendChild(acts);
    }
    function startScan() {
        api('POST', '/migration/scan').then(function (res) {
            if (!res.ok) { flash(res.json.message || __('Could not start the scan.', 'fastpix')); return; }
            resetSelection(); loadScan();
        });
    }
    // One page strip for every list here: a 7-wide window around the current page, an ellipsis marks the gaps.
    function pager(pages, current, goTo) {
        var strip = el('span', 'fp-mig__pager');
        function to(pg) { return function () { goTo(pg); }; }
        var prev = btn('‹', 'sm ghost', to(current - 1)); prev.disabled = current <= 1; strip.appendChild(prev);
        var lo = Math.max(1, Math.min(current - 3, pages - 6)), hi = Math.min(pages, lo + 6);
        if (lo > 1) { strip.appendChild(btn('1', 'sm ghost', to(1))); if (lo > 2) { strip.appendChild(el('span', 'small muted', '…')); } }
        for (var pg = lo; pg <= hi; pg++) { var pb = btn(String(pg), 'sm ' + (pg === current ? '' : 'ghost'), to(pg)); pb.disabled = pg === current; strip.appendChild(pb); }
        if (hi < pages) { if (hi < pages - 1) { strip.appendChild(el('span', 'small muted', '…')); } strip.appendChild(btn(String(pages), 'sm ghost', to(pages))); }
        var next = btn('›', 'sm ghost', to(current + 1)); next.disabled = current >= pages; strip.appendChild(next);
        return strip;
    }
    function listFoot(items, page, goTo) {
        var foot = el('div', 'fp-mig__listfoot'), per = items.per_page || 10, pages = Math.max(1, Math.ceil(items.total / per)), first = (page - 1) * per;
        foot.appendChild(el('span', 'small muted', items.total ? /* translators: 1: first row shown, 2: last row shown, 3: total rows */ sprintf(__('Showing %1$s–%2$s of %3$s', 'fastpix'), n(first + 1), n(first + items.rows.length), n(items.total)) : __('Nothing to show', 'fastpix')));
        if (pages > 1) { foot.appendChild(pager(pages, page, goTo)); }
        return foot;
    }
    function flash(text, ok) {
        var f = el('p', 'fp-mig__flash' + (ok ? ' ok' : ' err'), text);
        body.insertBefore(f, hint.nextSibling);   // under the title + hint, above the content
        setTimeout(function () { f.remove(); }, 6000);
    }

    /* ------------------------------------------------------- shape 1: choose */

    function loadScan() {
        clearTimeout(state.timer);
        api('GET', '/migration/scan?page=' + state.page + '&per_page=10&filter=' + (state.filter === 'skipped' ? 'skipped' : 'all')).then(function (res) {
            if (!res.ok || !res.json.batch_id) { showIdle(); return; }
            state.batch = res.json;
            if (res.json.state === 'scanning') { renderScanning(res.json); state.timer = setTimeout(loadScan, 3000); return; }
            if (!res.json.totals.movable) {
                // Nothing to move: drop the empty scan so it never blocks the next one or lands in history.
                api('DELETE', '/migration/' + res.json.batch_id).then(function () { showIdle(); flash(res.json.totals.skipped ? /* translators: %s: number of skipped videos */ sprintf(__('Scan finished — no local videos can move (%s skipped).', 'fastpix'), n(res.json.totals.skipped)) : __('Scan finished — no local videos can move.', 'fastpix'), true); });
                return;
            }
            renderChoose(res.json);
        });
    }
    function renderScanning(b) {
        setState('scanning'); clear();
        hint.textContent = /* translators: %s: number of attachments scanned */ sprintf(__('%s scanned so far', 'fastpix'), n(b.totals.scanned));
        body.appendChild(el('p', 'fp-mig__intro', __('Scanning the Media Library — every video attachment is checked for format, size and whether FastPix can reach it from outside. Nothing moves.', 'fastpix')));
        var bar = el('div', 'fp-mig__bar indeterminate'); bar.appendChild(el('i')); body.appendChild(bar);
        var acts = el('div', 'fp-mig__acts'); acts.appendChild(btn(__('Cancel scan', 'fastpix'), 'sm ghost', function () { api('DELETE', '/migration/' + b.batch_id).then(showIdle); })); body.appendChild(acts);
    }
    function renderChoose(b) {
        setState('choose'); clear();
        var t = b.totals;
        hint.innerHTML = '';
        hint.appendChild(document.createTextNode(/* translators: %s: number of videos */ sprintf(__('%s can move', 'fastpix'), n(t.movable)) + ' · '));
        var cant = el('button', 'linkbtn', /* translators: %s: number of videos that cannot move */ sprintf(__("%s can't", 'fastpix'), n(t.skipped))); cant.type = 'button';
        cant.addEventListener('click', function () { state.filter = state.filter === 'skipped' ? 'all' : 'skipped'; state.page = 1; loadScan(); });
        hint.appendChild(cant);
        if (t.left_out) { hint.appendChild(document.createTextNode(' · ' + /* translators: %s: number of attachments */ sprintf(__('%s without a file', 'fastpix'), n(t.left_out)))); }   // M20: attachment rows whose file is gone — reported, not listed

        var s = batchSettings();
        if (s.access_policy !== 'public' && t.audience_change_posts) {
            body.appendChild(notice('warn', /* translators: 1: access policy (private or drm), 2: number of posts */ sprintf(_n('Gating these as %1$s changes who can watch on %2$s published post.', 'Gating these as %1$s changes who can watch on %2$s published posts.', t.audience_change_posts, 'fastpix'), s.access_policy, n(t.audience_change_posts))));
        }

        // The scan list IS the report. Checking everything = migrate all; anything less = a chosen subset.
        var count = Object.keys(state.selected).length, allOn = t.movable > 0 && count === t.movable;
        var list = el('div', 'fp-mig__list');
        var head = el('div', 'fp-mig__listhead');
        var selAll = el('label'); var sa = el('input'); sa.type = 'checkbox'; sa.checked = allOn; sa.disabled = t.movable === 0;
        sa.addEventListener('change', function () {
            if (!sa.checked) { state.selected = {}; renderChoose(b); return; }
            sa.disabled = true;   // while every page is fetched
            var before = JSON.parse(JSON.stringify(state.selected));   // a failed fetch must not leave half the pages selected (QA U6)
            api('GET', '/migration/scan?filter=movable&per_page=200&page=1').then(function (r1) {
                if (!r1.ok) { throw new Error('scan'); }
                (r1.json.items.rows || []).forEach(function (it) { if (it.state === 'pending') { state.selected[it.attachment_id] = true; } });
                var pages = Math.ceil(r1.json.items.total / 200), pending = [];
                for (var p = 2; p <= pages; p++) { pending.push(api('GET', '/migration/scan?filter=movable&per_page=200&page=' + p).then(function (rr) { if (!rr.ok) { throw new Error('scan'); } (rr.json.items.rows || []).forEach(function (it) { if (it.state === 'pending') { state.selected[it.attachment_id] = true; } }); })); }
                return Promise.all(pending);
            }).then(function () { renderChoose(b); }, function () { state.selected = before; renderChoose(b); flash(__('Could not load every video — try Select all again.', 'fastpix')); });
        });
        selAll.appendChild(sa); selAll.appendChild(document.createTextNode(' ' + /* translators: %s: number of videos */ sprintf(__('Select all %s', 'fastpix'), n(t.movable)))); head.appendChild(selAll);
        head.appendChild(el('span', 'right small muted', /* translators: 1: selected count, 2: movable count, 3: total size, e.g. 1.2 GB */ sprintf(__('%1$s of %2$s selected · %3$s total', 'fastpix'), count, n(t.movable), size(t.bytes))));
        list.appendChild(head);

        var table = el('table', 'fp-mig__tbl'); var tb = el('tbody');
        b.items.rows.forEach(function (it) {
            var tr = el('tr', it.state === 'skipped' ? 'skipped' : '');
            var c1 = el('td'); var cb = el('input'); cb.type = 'checkbox'; cb.disabled = it.state !== 'pending'; cb.checked = !!state.selected[it.attachment_id];
            cb.addEventListener('change', function () { if (cb.checked) { state.selected[it.attachment_id] = true; } else { delete state.selected[it.attachment_id]; } renderChoose(b); });
            c1.appendChild(cb); tr.appendChild(c1);
            var c2 = el('td'); c2.appendChild(el('div', 'fp-mig__title', it.title || it.path));
            var sub = el('div', 'micro ' + (it.state === 'skipped' ? 'skip' : (it.error_code === 'unreachable' ? 'skip' : 'muted')));
            sub.textContent = it.state === 'skipped' ? it.skip_reason : (it.error_code === 'unreachable' ? it.skip_reason : (it.used_on_posts ? /* translators: %s: number of posts */ sprintf(_n('Used on %s post', 'Used on %s posts', it.used_on_posts, 'fastpix'), it.used_on_posts) : __('Not used in any post', 'fastpix')));
            c2.appendChild(sub); tr.appendChild(c2);
            tr.appendChild(el('td', 'num mono', size(it.size_bytes)));
            tb.appendChild(tr);
        });
        table.appendChild(tb); list.appendChild(table);
        list.appendChild(listFoot(b.items, state.page, function (pg) { state.page = pg; loadScan(); })); body.appendChild(list);

        var acts = el('div', 'fp-mig__acts');
        var hist = el('button', 'linkbtn', __('Past migrations', 'fastpix')); hist.type = 'button'; hist.addEventListener('click', showHistory); acts.appendChild(hist);
        var rescan = el('button', 'linkbtn', __('Scan again', 'fastpix')); rescan.type = 'button'; rescan.addEventListener('click', function () { api('DELETE', '/migration/' + b.batch_id).then(startScan); }); acts.appendChild(rescan);
        var later = el('button', 'linkbtn', __('Not now', 'fastpix')); later.type = 'button'; later.addEventListener('click', function () { api('DELETE', '/migration/' + b.batch_id).then(showIdle); }); acts.appendChild(later);
        var go = btn(/* translators: %s: number of videos */ allOn ? sprintf(__('Migrate all %s', 'fastpix'), n(t.movable)) : sprintf(__('Migrate %s', 'fastpix'), n(count)), '', function () {
            var s2 = batchSettings();
            go.disabled = true;
            api('POST', '/migration/run', { batch_id: b.batch_id, scope: allOn ? 'all' : 'selection', ids: Object.keys(state.selected).map(Number), quality_tier: s2.quality_tier, access_policy: s2.access_policy }).then(function (res) {
                if (!res.ok) { go.disabled = false; flash(res.json.message || __('Could not start.', 'fastpix')); return; }
                loadBatch(b.batch_id);
            });
        });
        go.disabled = count === 0;
        acts.appendChild(go); body.appendChild(acts);
    }

    /* ---------------------------------------------- shapes 2–4: batch views */

    function loadBatch(id, view) {
        clearTimeout(state.timer);
        api('GET', '/migration/' + id + '?page=' + state.page + '&per_page=25&filter=' + (view === 'failed' ? 'failed' : 'all')).then(function (res) {
            if (!res.ok) { showIdle(); return; }
            var b = res.json; state.batch = b;
            var v = b.verification;
            if (view === 'verify') { renderVerify(b); }
            else if (view === 'cleanup') { renderCleanup(b); }
            else if (b.state === 'running' || b.state === 'paused' || (b.state === 'done' && v.pending > 0)) { renderTransfer(b); state.timer = setTimeout(function () { loadBatch(id, view); }, 4000); }
            else if (b.state === 'done') { showIdle(); flash(/* translators: 1: videos copied, 2: videos in the batch */ sprintf(__('Transfer finished — %1$s of %2$s copied. Verify and clean up from Past migrations.', 'fastpix'), n(v.ready), n(b.item_count)), true); }
            else if (b.state === 'cleaned' || b.state === 'cancelled') { showIdle(); flash(b.state === 'cleaned' ? __('Local files removed.', 'fastpix') : __('Migration cancelled.', 'fastpix'), true); }
            else { renderTransfer(b); }
        });
    }
    function stat(label, value) { var d = el('div', 'fp-mig__stat'); d.appendChild(el('b', null, n(value))); d.appendChild(el('span', 'micro muted', label)); return d; }

    function renderTransfer(b) {
        setState('migrating'); clear();
        var v = b.verification, total = b.item_count || 0, done = (b.submitted_count || 0) + (b.failed_count || 0);
        hint.textContent = b.state === 'paused' ? __('Paused at the item boundary', 'fastpix') : __('Moving your video', 'fastpix');
        body.appendChild(el('p', 'fp-mig__intro', __('Moving your video — leave this page whenever you like. Each video is one background job; if this tab closes or the connection drops, the batch carries on and resumes at the first unsubmitted item.', 'fastpix')));
        var stats = el('div', 'fp-mig__stats');
        stats.appendChild(stat(__('Submitted', 'fastpix'), b.submitted_count)); stats.appendChild(stat(__('Processing', 'fastpix'), v.processing)); stats.appendChild(stat(__('Ready', 'fastpix'), v.ready)); stats.appendChild(stat(__('Failed', 'fastpix'), v.failed));   // M28: processing = on FastPix, not queued here
        body.appendChild(stats);
        var pct = total ? Math.round(done / total * 100) : 0;
        var bar = el('div', 'fp-mig__bar'); var fill = el('i'); fill.style.width = pct + '%'; bar.appendChild(fill); body.appendChild(bar);
        body.appendChild(el('p', 'small muted', /* translators: 1: videos selected, 2: videos sent, 3: percent done */ sprintf(__('%1$s selected · %2$s sent · four at a time · %3$s%%', 'fastpix'), n(total), n(done), pct)));
        var acts = el('div', 'fp-mig__acts');
        if (b.state === 'running') { acts.appendChild(btn(__('Pause', 'fastpix'), 'sm sec', function () { api('POST', '/migration/' + b.batch_id + '/pause').then(function () { loadBatch(b.batch_id); }); })); }
        if (b.state === 'paused') { acts.appendChild(btn(__('Resume', 'fastpix'), 'sm', function () { api('POST', '/migration/' + b.batch_id + '/resume').then(function () { loadBatch(b.batch_id); }); })); }
        if (b.state === 'running' || b.state === 'paused') {
            acts.appendChild(btn(__('Cancel this batch', 'fastpix'), 'sm ghost', function () {
                fpDialog.confirm({ title: __('Cancel this batch?', 'fastpix'), message: __('Videos already copied stay on FastPix; the rest never move. Nothing is deleted.', 'fastpix'), ok: __('Cancel batch', 'fastpix'), cancel: __('Keep going', 'fastpix'), danger: true }).then(function (ok) {
                    if (!ok) { return; }
                    api('DELETE', '/migration/' + b.batch_id).then(showHistory);
                });
            }));
        }
        acts.appendChild(btn(__('Go to verify', 'fastpix'), 'sm ghost', function () { loadBatch(b.batch_id, 'verify'); }));
        body.appendChild(acts);
        if (v.failed) { body.appendChild(failedBlock(b)); }
    }
    function failedBlock(b) {
        var box = el('div', 'fp-mig__failed');
        box.appendChild(el('b', null, /* translators: %s: number of failed videos */ sprintf(__('The %s that failed', 'fastpix'), n(b.verification.failed))));
        box.appendChild(el('p', 'small muted', __('Every failure is per item — retrying one never resubmits the rest, and a failed item\'s local file is never touched.', 'fastpix')));
        var acts = el('div', 'fp-mig__acts');
        acts.appendChild(btn(__('Retry all failed', 'fastpix'), 'sm sec', function () { api('POST', '/migration/' + b.batch_id + '/retry').then(function () { loadBatch(b.batch_id); }); }));
        acts.appendChild(btn(__('Show failed items', 'fastpix'), 'sm ghost', function () { showItems(b, 'failed'); }));
        box.appendChild(acts);
        return box;
    }
    function renderVerify(b) {
        setState('verify'); clear();
        var v = b.verification;
        hint.textContent = __('Verify before cleanup', 'fastpix');
        var checks = el('ul', 'fp-mig__checks');
        [[v.pending === 0, /* translators: 1: ready count, 2: failed count, 3: still-processing count */ v.pending ? sprintf(__('Every item is terminal — %1$s ready, %2$s failed, %3$s still processing', 'fastpix'), n(v.ready), n(v.failed), n(v.pending)) : sprintf(__('Every item is terminal — %1$s ready, %2$s failed', 'fastpix'), n(v.ready), n(v.failed))],
         [v.ready > 0, __('Each copied video has a playback id on FastPix', 'fastpix')],
         [v.usage_swept, v.usage_swept ? __('Usage swept — the plugin knows which posts use these videos', 'fastpix') : __('The nightly usage sweep has not run yet — it records which posts use each video', 'fastpix')]].forEach(function (c) {
            var li = el('li', c[0] ? 'ok' : 'wait'); li.textContent = (c[0] ? '✓ ' : '◷ ') + c[1]; checks.appendChild(li);
        });
        body.appendChild(checks);
        if (v.verified) {
            body.appendChild(notice('ok', /* translators: 1: videos copied, 2: videos in the batch */ sprintf(__('Transfer finished — %1$s of %2$s copied and playing from FastPix. Old posts render the FastPix player; no post was edited.', 'fastpix'), n(v.ready), n(b.item_count))));
        }
        if (v.failed) {
            body.appendChild(notice('warn', /* translators: %s: number of failed videos */ sprintf(__('%s failed — their local files are the only copy and must not be deleted. Retry them, or leave them; cleanup excludes them.', 'fastpix'), n(v.failed))));
        }
        body.appendChild(notice('info', __('Open a few pages yourself first — the plugin never loads your pages. If a video plays where it always did, the swap is working.', 'fastpix')));
        var acts = el('div', 'fp-mig__acts');
        var cont = btn(__('Continue to cleanup', 'fastpix'), 'sm', function () { loadBatch(b.batch_id, 'cleanup'); }); cont.disabled = !v.verified || v.cleanable === 0; acts.appendChild(cont);
        var lib = el('a', 'btn sm ghost', __('Go to the library', 'fastpix')); lib.href = cfg.libraryUrl; acts.appendChild(lib);
        if (v.failed) { acts.appendChild(btn(__('Retry failed', 'fastpix'), 'sm ghost', function () { api('POST', '/migration/' + b.batch_id + '/retry').then(function () { loadBatch(b.batch_id); }); })); }
        acts.appendChild(btn(__('Back', 'fastpix'), 'sm ghost', function () { loadBatch(b.batch_id); }));
        body.appendChild(acts);
    }
    function notice(kind, text) { var d = el('div', 'fp-mig__notice ' + kind); d.appendChild(el('span', 'ni', kind === 'ok' ? '✓' : (kind === 'warn' ? '⚠' : 'ⓘ'))); d.appendChild(el('span', null, text)); return d; }
    function renderCleanup(b) {
        setState('cleanup'); clear();
        // The typed phrase stays English: the server compares it to its own literal "delete N files" (class-fastpix-migration-rest.php).
        var v = b.verification, expected = 'delete ' + v.cleanable + (v.cleanable === 1 ? ' file' : ' files');
        hint.textContent = __('The only step that deletes anything', 'fastpix');
        body.appendChild(el('p', 'fp-mig__intro', __('Remove the local files — the only step here that deletes anything. Until you do, the site pays for both copies. Only videos that passed verification in this batch are included; failed items are excluded because their local file is the only copy.', 'fastpix')));
        body.appendChild(notice('warn', __('This is permanent — once a local file is gone, revert stops being possible for that video.', 'fastpix')));
        var stats = el('div', 'fp-mig__stats'); stats.appendChild(stat(__('Verified, will be removed', 'fastpix'), v.cleanable)); stats.appendChild(stat(__('Failed, kept', 'fastpix'), v.failed)); stats.appendChild(stat(__('Reverted, kept', 'fastpix'), v.reverted)); body.appendChild(stats);
        /* translators: %s: the exact phrase to type (shown in bold, always English) */
        var typeIt = __('Type %s to confirm', 'fastpix').split('%s');   // one sentence, cut at the bold phrase
        var lab = el('label', 'fp-mig__confirm'); lab.appendChild(el('span', null, typeIt[0]));
        lab.appendChild(el('b', null, expected)); lab.appendChild(el('span', null, typeIt[1] || ''));
        var input = el('input'); input.type = 'text'; input.autocomplete = 'off'; input.spellcheck = false; lab.appendChild(input); body.appendChild(lab);
        var acts = el('div', 'fp-mig__acts');
        var go = btn(__('Remove local files', 'fastpix'), 'sm danger', function () {
            api('POST', '/migration/' + b.batch_id + '/cleanup', { confirm: input.value }).then(function (res) {
                if (!res.ok) { flash(res.json.message || __('Not removed.', 'fastpix')); return; }
                /* translators (all four below): %s: number of files */
                var r = res.json, msg = sprintf(_n('%s local file removed', '%s local files removed', r.removed, 'fastpix'), n(r.removed)) +
                    (r.failed ? ' · ' + sprintf(__('%s could not be removed — check file permissions (see the log)', 'fastpix'), n(r.failed)) : '') +
                    // (QA M7) no background job was queued (no Action Scheduler) — the rest needs another Clean up.
                    (r.remaining ? ' · ' + sprintf(r.background ? __('%s more being removed in the background', 'fastpix') : __('%s still on disk — press Clean up again to remove them', 'fastpix'), n(r.remaining)) : '') + '.';
                loadBatch(b.batch_id); flash(msg, !r.failed && !(r.remaining && !r.background));
            });
        });
        go.disabled = true; input.addEventListener('input', function () { go.disabled = input.value.trim().toLowerCase() !== expected; });
        acts.appendChild(go); acts.appendChild(btn(__('Not yet', 'fastpix'), 'sm ghost', function () { loadBatch(b.batch_id, 'verify'); })); body.appendChild(acts);
        body.appendChild(el('p', 'micro faint', __('There is no rush. Nothing changes for visitors either way — this panel will still be here next month.', 'fastpix')));
    }

    /* -------------------------------------------------- shape 5: history */

    function showHistory() {
        clearTimeout(state.timer);
        api('GET', '/migration/history').then(function (res) {
            if (!res.ok) { return; }
            setState('history'); clear();
            hint.textContent = __('Every batch this site has run', 'fastpix');
            body.appendChild(el('p', 'fp-mig__intro', __('Past migrations — every batch this site has run. Reverting is per video — there is no batch undo.', 'fastpix')));
            var rows = res.json.batches || [];
            if (!rows.length) { body.appendChild(el('p', 'small muted', __('No batch has run yet.', 'fastpix'))); }
            else {
                var table = el('table', 'fp-mig__tbl hist'); var thead = el('thead'); var hr = el('tr');
                [__('When', 'fastpix'), __('Videos', 'fastpix'), __('Settings', 'fastpix'), __('Result', 'fastpix'), __('Local files', 'fastpix'), ''].forEach(function (h) { hr.appendChild(el('th', null, h)); }); thead.appendChild(hr); table.appendChild(thead);
                var tb = el('tbody');
                rows.forEach(function (bt) {
                    var tr = el('tr'); var v = bt.verification;
                    tr.appendChild(el('td', 'small', when(bt.created_at)));
                    tr.appendChild(el('td', 'small', n(bt.item_count)));
                    tr.appendChild(el('td', 'small', (ACCESS[bt.access_policy] || bt.access_policy || '—') + ' · ' + (TIER[bt.quality_tier] || bt.quality_tier || '—')));
                    tr.appendChild(el('td', 'small', /* translators: counts of videos — 1: ready, 2: failed, 3: reverted */ bt.state === 'cancelled' ? sprintf(__('Cancelled — %s copied', 'fastpix'), n(bt.submitted_count)) : (v.reverted ? sprintf(__('%1$s ready · %2$s failed · %3$s reverted', 'fastpix'), n(v.ready), n(v.failed), n(v.reverted)) : sprintf(__('%1$s ready · %2$s failed', 'fastpix'), n(v.ready), n(v.failed)))));
                    tr.appendChild(el('td', 'small', bt.local_files === 'removed' ? /* translators: %s: date, YYYY-MM-DD */ sprintf(__('Removed %s', 'fastpix'), (bt.finished_at || '').slice(0, 10)) : __('Still on disk', 'fastpix')));
                    var ta = el('td', 'num');
                    if (bt.local_files !== 'removed' && v.verified && v.cleanable) { ta.appendChild(btn(__('Clean up', 'fastpix'), 'sm ghost', function () { loadBatch(bt.batch_id, 'cleanup'); })); }
                    ta.appendChild(btn(__('Open', 'fastpix'), 'sm ghost', function () { showItems(bt, 'all'); })); tr.appendChild(ta);
                    tb.appendChild(tr);
                });
                table.appendChild(tb); var scroll = el("div", "fp-mig__scroll"); scroll.appendChild(table); body.appendChild(scroll);
            }
            var acts = el('div', 'fp-mig__acts');
            var active = res.json.active;
            acts.appendChild(btn(active ? __('Back to the current batch', 'fastpix') : __('Back', 'fastpix'), 'sm ghost', function () { active ? (active.state === 'scanned' || active.state === 'scanning' ? loadScan() : loadBatch(active.batch_id)) : showIdle(); }));
            body.appendChild(acts);
        });
    }
    function showItems(bt, filter, page) {
        clearTimeout(state.timer);
        page = page || 1;   // M18: paged, so row 101+ can be reverted or retried too
        api('GET', '/migration/' + bt.batch_id + '?page=' + page + '&per_page=50&filter=' + filter).then(function (res) {
            if (!res.ok) { return; }
            var b = res.json; setState('history'); clear();
            hint.textContent = /* translators: 1: date and time, 2: number of videos */ sprintf(_n('Migrated %1$s · %2$s video', 'Migrated %1$s · %2$s videos', b.item_count, 'fastpix'), when(b.created_at), n(b.item_count));
            var filters = el('div', 'fp-mig__acts');
            [['all', __('All', 'fastpix')], ['failed', __('Failed only', 'fastpix')], ['reverted', __('Reverted', 'fastpix')]].forEach(function (f) { var x = btn(f[1], 'sm ' + (filter === f[0] ? 'sec' : 'ghost'), function () { showItems(bt, f[0]); }); filters.appendChild(x); });
            body.appendChild(filters);
            var table = el('table', 'fp-mig__tbl hist'); var thead = el('thead'); var hr = el('tr');
            [__('Video', 'fastpix'), __('Size', 'fastpix'), __('Status', 'fastpix'), ''].forEach(function (h) { hr.appendChild(el('th', null, h)); }); thead.appendChild(hr); table.appendChild(thead);
            var tb = el('tbody');
            (b.items.rows || []).forEach(function (it) {
                var tr = el('tr');
                tr.appendChild(el('td', null, it.title));
                tr.appendChild(el('td', 'num mono small', size(it.size_bytes)));
                // M4: a copy that failed or vanished on FastPix is a failure here too — same words, same Retry as a local failure.
                var lost = it.state === 'submitted' && !it.reverted_at && (it.video_gone || it.video_status === 'Failed');
                /* translators: %s: date, YYYY-MM-DD */
                var st = it.reverted_at ? sprintf(__('Reverted %s', 'fastpix'), it.reverted_at.slice(0, 10)) : (it.state === 'cleaned' ? __('Migrated ✓ · local file removed', 'fastpix') : (lost ? (it.video_gone ? __('✕ Did not finish — removed on FastPix', 'fastpix') : __('✕ Did not finish — FastPix could not process it', 'fastpix')) : (it.state === 'submitted' ? (it.video_status === 'Ready' ? __('Migrated ✓', 'fastpix') : __('Processing on FastPix', 'fastpix')) : (it.state === 'failed' ? /* translators: %s: the reason or error code */ sprintf(__('✕ Did not finish — %s', 'fastpix'), it.skip_reason || it.error_code) : (it.state === 'skipped' ? /* translators: %s: the reason */ sprintf(__('Skipped — %s', 'fastpix'), it.skip_reason || '') : __('Queued', 'fastpix'))))));
                tr.appendChild(el('td', 'small', st));
                var ta = el('td', 'num');
                if (it.state === 'submitted' && !it.reverted_at && !lost) {
                    ta.appendChild(btn(__('Revert to local', 'fastpix'), 'sm ghost', function () {
                        var warn = it.access_policy && it.access_policy !== 'public' ? '\n\n' + /* translators: %s: access policy (private or drm) */ sprintf(__('This video is %s on FastPix. Reverting returns it to an unprotected file on this server that anyone with the address can download.', 'fastpix'), it.access_policy) : '';
                        fpDialog.confirm({ title: /* translators: %s: video title */ sprintf(__('Restore local playback for "%s"?', 'fastpix'), it.title), message: __('Posts go back to playing the local file. The FastPix copy stays.', 'fastpix') + warn, ok: __('Revert', 'fastpix') }).then(function (ok) {
                            if (!ok) { return; }
                            api('POST', '/migration/items/' + it.id + '/revert').then(function (r) { if (!r.ok) { flash(r.json.message || __('Not reverted.', 'fastpix')); } showItems(bt, filter, page); });
                        });
                    }));
                }
                if (it.state === 'failed' || lost) { ta.appendChild(btn(__('Retry', 'fastpix'), 'sm ghost', function () { api('POST', '/migration/' + bt.batch_id + '/retry', { item_id: it.id }).then(function (r) { if (!r.ok) { flash(r.json.message || __('Not retried.', 'fastpix')); } showItems(bt, filter, page); }); })); }
                tr.appendChild(ta); tb.appendChild(tr);
            });
            table.appendChild(tb); var scroll = el("div", "fp-mig__scroll"); scroll.appendChild(table); body.appendChild(scroll);
            body.appendChild(listFoot(b.items, page, function (pg) { showItems(bt, filter, pg); }));
            var acts = el('div', 'fp-mig__acts'); acts.appendChild(btn(__('Back', 'fastpix'), 'sm ghost', showHistory)); body.appendChild(acts);
        });
    }

    boot();
})();
