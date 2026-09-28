/**
 * UI-004 upload queue — ARCH-04 (browser half), REQ-010…016, RULE-005/008.
 *
 * Transfer is driven by the official FastPix web upload SDK
 * (@fastpix/resumable-uploads, vendored as window.Uploader — REQ-112) against
 * the signed URL from POST /uploads. The SDK owns chunking (16 MB default,
 * 5 MB minimum), one-chunk-in-flight, per-chunk retry with backoff (5
 * attempts), committed-offset synchronisation on resume, and offline
 * detection. This file owns what sits above it [REQ-012]: the queue (3 files
 * at once, configurable to 6; 50 per submission), client-side refusals that
 * name the limit, pause/resume/cancel UI, progress reporting to the server,
 * and the RULE-008 same-file check on resume (verified server-side).
 *
 * The chrome (custom selects, switches, drop zone, item rows) is the approved
 * mockup's script, adapted to read/write real state.
 *
 * The SDK does not expose the 16→8→5 MB chunk-size downshift after failures;
 * it retries at the configured size with exponential backoff, landing on the
 * same pause state the downshift protected. No fallback chunker is kept.
 */
(function () {
    'use strict';

    if (typeof fastpixAddMedia === 'undefined') {
        return;
    }

    var cfg = fastpixAddMedia;
    var i18n = cfg.i18n || {};
    // Translatable string with an optional %s / %d placeholder filled from `a`.
    function t(key, a) {
        var s = i18n[key] || key;
        return a === undefined ? s : s.replace('%s', a).replace('%d', a);
    }

    var queue = [];       // {file, el, row, state, uploader, bytes, base, settings}
    var links = [];       // staged links: {url, el}
    var active = 0;

    var $ = function (id) { return document.getElementById(id); };
    var el = {
        drop: $('fp-drop'), pick: $('fp-pick'), input: $('fp-file-input'),
        list: $('fp-queue'), next: $('fp-next'),
        modal: $('fp-am-modal'), modalSub: $('fp-am-modal-sub'), modalClose: $('fp-am-close'), modalCancel: $('fp-am-cancel'), upload: $('fp-upload'),
        urls: $('fp-urls'), ingest: $('fp-ingest')
    };
    if (!el.drop) { return; }

    var FILM = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M8 5v14M16 5v14M3 12h18"/></svg>';
    var LINK = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M10 13a5 5 0 0 0 7 0l3-3a5 5 0 0 0-7-7l-1 1"/><path d="M14 11a5 5 0 0 0-7 0l-3 3a5 5 0 0 0 7 7l1-1"/></svg>';
    var WARN = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M12 8v5M12 16.5v.5"/><circle cx="12" cy="12" r="9"/></svg>';
    var XSVG = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>';
    var ARROW = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h13"/><path d="m12 5 7 7-7 7"/></svg>';

    function api(method, path, body, retried) {
        return fetch(cfg.restUrl + path, {
            method: method,
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
            credentials: 'same-origin',
            body: body ? JSON.stringify(body) : undefined
        }).then(function (r) {
            return r.json().catch(function () { return { message: t('httpNoAnswer', r.status) }; })
                .then(function (j) {
                    var res = { ok: r.ok, status: r.status, json: j || {} };
                    // Heartbeat suspends after ~10 min without input, so the nonce can still go stale:
                    // fetch a fresh one and retry ONCE. (QA U18)
                    if (r.status !== 403 || res.json.code !== 'rest_cookie_invalid_nonce' || retried || !cfg.ajaxUrl) { return res; }
                    return fetch(cfg.ajaxUrl + '?action=rest-nonce', { credentials: 'same-origin' })
                        .then(function (n) { return n.ok ? n.text() : ''; })
                        .then(function (nonce) {
                            if (!/^\w{6,}$/.test(nonce)) { return res; }   // logged out: admin-ajax answers "0"
                            cfg.nonce = nonce;
                            return api(method, path, body, true);
                        }, function () { return res; });
                });
        }, function () {
            return { ok: false, status: 0, json: { message: t('sendFailed') } };
        });
    }
    // A REST nonce lives 24 h and core's heartbeat hands out a fresh one in the second half;
    // a page kept open for a day-long upload must take it, or its last PATCH is refused. The jQuery
    // event is 'heartbeat-tick' ('heartbeat.tick' is only the wp.hooks action). (QA U18)
    if (window.jQuery) {
        window.jQuery(document).on('heartbeat-tick', function (e, data) { if (data && data.rest_nonce) { cfg.nonce = data.rest_nonce; } });
    }

    /* ---------------------------------------------------- custom selects */

    document.querySelectorAll('.fp-am .select').forEach(function (sel) {
        var val = sel.querySelector('.select__val');
        var opts = Array.prototype.slice.call(sel.querySelectorAll('li'));
        opts.forEach(function (o, i) { if (!o.id) { o.id = sel.id + '-opt-' + i; } });   // aria-activedescendant needs option ids (QA U15)
        var mark = function (li) {
            opts.forEach(function (o) { o.classList.remove('cur'); });
            li.classList.add('cur');
            sel.setAttribute('aria-activedescendant', li.id);
        };
        var open = function (s) {
            document.querySelectorAll('.fp-am .select[aria-expanded="true"]').forEach(function (o) { if (o !== sel) { o.setAttribute('aria-expanded', 'false'); o.removeAttribute('aria-activedescendant'); } });
            sel.setAttribute('aria-expanded', s);
            if (s) {
                // The dialog body scrolls: a menu with no room below it opens upward instead of being clipped.
                var box = sel.closest('.fp-am-modal__body'), menu = sel.querySelector('.select__menu');
                sel.classList.toggle('up', !!box && sel.getBoundingClientRect().bottom + menu.scrollHeight + 8 > box.getBoundingClientRect().bottom);
                mark(opts.filter(function (o) { return o.getAttribute('aria-selected') === 'true'; })[0] || opts[0]);
            } else {
                sel.removeAttribute('aria-activedescendant');
            }
        };
        var off = function (li) { return li.getAttribute('aria-disabled') === 'true'; };   // e.g. DRM with no DRM configuration ID
        var pick = function (li, quiet) {
            if (off(li)) { return; }
            opts.forEach(function (o) { o.setAttribute('aria-selected', 'false'); });
            li.setAttribute('aria-selected', 'true');
            val.textContent = li.textContent.trim();
            sel.setAttribute('data-value', li.getAttribute('data-value'));
            open(false);
            if (!quiet) { sel.focus(); }
            sel.dispatchEvent(new Event('change'));
        };
        sel.pickValue = function (v) { var li = opts.filter(function (o) { return o.getAttribute('data-value') === v; })[0]; if (li) { pick(li, true); } };
        sel.addEventListener('click', function (e) {
            if (sel.getAttribute('aria-disabled') === 'true') { return; }
            var li = e.target.closest('li');
            if (li) { pick(li); return; }
            open(sel.getAttribute('aria-expanded') !== 'true');
        });
        sel.addEventListener('keydown', function (e) {
            if (sel.getAttribute('aria-disabled') === 'true') { return; }
            var isOpen = sel.getAttribute('aria-expanded') === 'true';
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                if (isOpen) { var c = sel.querySelector('li.cur'); if (c) { pick(c); } } else { open(true); }
            } else if (e.key === 'Escape') {
                if (isOpen) { e.stopPropagation(); open(false); }   // closes the menu only — the dialog's own Escape stays out of it (QA U14)
            } else if (e.key === 'Tab') { open(false); }             // focus leaves: the menu goes with it (QA U15)
            else if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                if (!isOpen) { open(true); return; }
                var cur = sel.querySelector('li.cur') || opts[0], step = e.key === 'ArrowDown' ? 1 : -1;
                var i = opts.indexOf(cur) + step;
                while (opts[i] && off(opts[i])) { i += step; }   // arrows step over an option that cannot be picked
                if (opts[i]) { mark(opts[i]); }
            }
        });
    });
    document.addEventListener('click', function (e) {
        if (!e.target.closest('.fp-am .select')) {
            document.querySelectorAll('.fp-am .select[aria-expanded="true"]').forEach(function (o) { o.setAttribute('aria-expanded', 'false'); });
        }
    });

    /* ---------------------------------------------------------- switches */

    // The language menu is disabled for real (not just dimmed) while Subtitles is off:
    // out of the tab order and ignored by its handlers, so a keyboard user cannot pick
    // a language the batch then discards. (QA U15)
    function langEnabled(on) {
        var lang = $('fp-set-lang');
        lang.setAttribute('aria-disabled', on ? 'false' : 'true');
        lang.setAttribute('tabindex', on ? '0' : '-1');
    }
    document.querySelectorAll('.fp-am .sw').forEach(function (sw) {
        sw.addEventListener('click', function () {
            var on = sw.getAttribute('aria-checked') === 'true';
            sw.setAttribute('aria-checked', String(!on));
            if (sw.id === 'fp-sw-subtitles') { $('subrow').classList.toggle('off', on); langEnabled(!on); }
            if (sw.id === 'fp-sw-wm') { $('fp-wm-card').hidden = on; $('fp-wm-err').hidden = true; if (!on) { $('fp-set-wm').focus(); } }
        });
    });
    langEnabled(swOn('fp-sw-subtitles'));

    function selVal(id) { return $(id).getAttribute('data-value'); }

    // DRM renditions are never downloadable as MP4 (platform docs): DRM forces "Allow downloads" to Off and locks it.
    function syncDrm() {
        var drm = selVal('fp-set-access') === 'drm', dl = $('fp-set-download');
        if (drm) {
            if (!dl.dataset.beforeDrm) { dl.dataset.beforeDrm = dl.getAttribute('data-value'); }   // give the choice back if they leave DRM
            dl.pickValue('off');
        } else if (dl.dataset.beforeDrm) {
            dl.pickValue(dl.dataset.beforeDrm);
            delete dl.dataset.beforeDrm;
        }
        dl.setAttribute('aria-disabled', drm ? 'true' : 'false');
        dl.setAttribute('tabindex', drm ? '-1' : '0');   // dimmed was not enough: keyboard users tabbed into a dead control
        dl.title = drm ? t('drmNoDownload') : '';
    }
    $('fp-set-access').addEventListener('change', syncDrm); syncDrm();
    function swOn(id) { return $(id).getAttribute('aria-checked') === 'true'; }

    /* ------------------------------------------- domain lock (ASSUME-073) */

    // Sites not on the list: 'deny' = whitelist (this site + allowHosts),
    // 'allow' = blacklist (denyHosts). Only the list that applies is shown.
    var lockPolicy = 'deny', allowHosts = [], denyHosts = [];
    var HOST_RE = /^(\*\.)?([a-z0-9-]+\.)+[a-z]{2,}$/;

    function cleanHost(raw) {
        return raw.trim().toLowerCase().replace(/^[a-z]+:\/\//, '').replace(/[\/:].*$/, '');
    }
    function chips(box, list, kind, input) {
        Array.prototype.slice.call(box.querySelectorAll('.chip:not(.site)')).forEach(function (c) { c.remove(); });
        list.forEach(function (h, i) {
            var c = document.createElement('span'), x = document.createElement('button');
            c.className = 'chip ' + kind; c.textContent = h;
            x.type = 'button'; x.textContent = '×'; x.setAttribute('aria-label', t('removeHost', h));
            x.addEventListener('click', function () { list.splice(i, 1); renderLock(); });
            c.appendChild(x); box.insertBefore(c, input);
        });
    }
    function renderLock() {
        var on = $('fp-set-domainlock').checked, n = 1 + allowHosts.length;
        $('fp-lock').hidden = !on;
        $('fp-pol-deny').setAttribute('aria-pressed', String(lockPolicy === 'deny'));
        $('fp-pol-allow').setAttribute('aria-pressed', String(lockPolicy === 'allow'));
        $('fp-lock-allow').hidden = lockPolicy !== 'deny';
        $('fp-lock-deny').hidden = lockPolicy !== 'allow';
        chips($('fp-allow-chips'), allowHosts, '', $('fp-allow-in'));
        chips($('fp-deny-chips'), denyHosts, 'deny', $('fp-deny-in'));
        $('fp-lock-sum').textContent = lockPolicy === 'deny' ? t('lockDenySum', n)
            : (denyHosts.length ? t('lockAllowSumN', denyHosts.length) : t('lockAllowSum'));
    }
    function hostInput(input, list, other, err) {
        function add() {
            var h = cleanHost(input.value);
            if (!h) { return; }
            if (!HOST_RE.test(h)) { err.textContent = t('badHost'); err.hidden = false; return; }
            if (h !== cfg.siteHost && list.indexOf(h) === -1) {
                var i = other.indexOf(h); if (i !== -1) { other.splice(i, 1); }
                list.push(h);
            }
            input.value = ''; err.hidden = true; renderLock();
        }
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ',' || e.key === ' ') { e.preventDefault(); add(); }
            if (e.key === 'Backspace' && !input.value && list.length) { list.pop(); renderLock(); }
        });
        input.addEventListener('blur', add);
        input.addEventListener('input', function () { err.hidden = true; });
        input.parentNode.addEventListener('click', function () { input.focus(); });
    }
    hostInput($('fp-allow-in'), allowHosts, denyHosts, $('fp-allow-err'));
    hostInput($('fp-deny-in'), denyHosts, allowHosts, $('fp-deny-err'));
    $('fp-pol-deny').addEventListener('click', function () { lockPolicy = 'deny'; renderLock(); });
    $('fp-pol-allow').addEventListener('click', function () { lockPolicy = 'allow'; renderLock(); });
    $('fp-set-domainlock').addEventListener('change', renderLock);
    renderLock();

    // The watermark is burned in at encode time: a typo cannot be corrected later, so the shape is
    // checked before anything leaves the browser. Reachability is the server's call (it probes).
    $('fp-set-wm').addEventListener('input', function () { $('fp-wm-err').hidden = true; });
    function wmError(msg) {
        var err = $('fp-wm-err');
        err.textContent = msg;
        err.hidden = false;
        $('fp-set-wm').focus();
    }
    // Resolves true when the batch may start: watermark off, or on with a URL this server — and so
    // FastPix — can fetch. Shape first (no round trip for a typo), then the same probe the create call runs.
    function watermarkOk() {
        if (!swOn('fp-sw-wm')) { return Promise.resolve(true); }
        var url = ($('fp-set-wm').value || '').trim();
        if (!/^https?:\/\/\S+$/i.test(url)) { wmError(t('wmBad')); return Promise.resolve(false); }
        return api('POST', '/uploads/watermark-check', { url: url }).then(function (res) {
            if (res.ok) { $('fp-wm-err').hidden = true; return true; }
            wmError(res.json.message || t('sendFailed'));
            return false;
        });
    }

    /** REQ-017 batch settings, read from the mockup controls. */
    function settings() {
        return {
            title:           ($('fp-set-title').value || '').trim(),
            watermark_url:   swOn('fp-sw-wm') ? ($('fp-set-wm').value || '').trim() : '',   // off → no watermark input is sent
            watermark_pos:   selVal('fp-set-wmpos'),
            watermark_margin: selVal('fp-set-wmmargin'),
            watermark_size:  selVal('fp-set-wmsize'),
            watermark_opacity: selVal('fp-set-wmopacity'),
            access_policy:   selVal('fp-set-access'),
            quality_tier:    selVal('fp-set-tier'),
            max_resolution:  selVal('fp-set-res'),
            downloadable:    selVal('fp-set-download'),
            normalize_audio: swOn('fp-sw-audio'),
            subtitles:       swOn('fp-sw-subtitles') ? selVal('fp-set-lang') : 'off',
            domain_lock:     $('fp-set-domainlock').checked,
            domain_policy:   lockPolicy,
            domain_allow:    allowHosts.slice(),
            domain_deny:     denyHosts.slice()
        };
    }

    /* -------------------------------------------------- intake + validation */

    function accept(files) {
        var list = Array.prototype.slice.call(files), added = 0;
        var room = Math.max(0, cfg.maxFiles - batchCount());   // 50 per submission [REQ-012]: what is STAGED counts, not finished, cancelled or restored rows (QA U9)

        list.forEach(function (file) {
            // Refused before anything leaves this browser, naming the limit. [REQ-016]
            if (added >= room) {
                badRow(FILM, file.name, t('tooMany', cfg.maxFiles));   // never dropped silently (QA U9)
                return;
            }
            if (file.size > cfg.maxFileBytes) {
                badRow(FILM, file.name, t('tooBig'));
                return;
            }
            if (file.type && cfg.acceptedTypes.indexOf(file.type.toLowerCase()) === -1) {
                badRow(FILM, file.name, t('badType', file.type));
                return;
            }

            queue.push(fileRow(file));   // staged — nothing leaves until Upload [ASSUME-054]
            added++;
        });
        sync();
        if (added) { openModal(); }   // a dropped/browsed file goes straight to Media settings, like a pasted URL [ASSUME-072]
    }

    // The whole zone is the button; the "Browse" link inside is just a label for it.
    el.drop.addEventListener('click', function () { el.input.click(); });
    el.pick.setAttribute('tabindex', '-1');
    el.input.addEventListener('change', function () { accept(el.input.files); el.input.value = ''; });
    var depth = 0;
    ['dragenter', 'dragover'].forEach(function (t) {
        el.drop.addEventListener(t, function (e) { e.preventDefault(); if (t === 'dragenter') { depth++; } el.drop.classList.add('hot'); });
    });
    el.drop.addEventListener('dragleave', function () { depth = Math.max(0, depth - 1); if (!depth) { el.drop.classList.remove('hot'); } });
    el.drop.addEventListener('drop', function (e) { e.preventDefault(); depth = 0; el.drop.classList.remove('hot'); accept(e.dataTransfer.files); });
    window.addEventListener('dragover', function (e) { e.preventDefault(); });
    window.addEventListener('drop', function (e) { e.preventDefault(); });

    /* --------------------------------------------------------------- rows */

    function batchCount() {
        return queue.filter(function (i) { return i.state === 'staged'; }).length + links.length;
    }
    function sync() {
        el.list.hidden = el.list.children.length === 0;
        el.next.disabled = batchCount() === 0;
        var foot = $('fp-am-foot');
        if (foot) { foot.hidden = el.list.hidden; }   // the frame draws no footer in the empty state
    }

    function row(icon, name, bad) {
        var d = document.createElement('div');
        d.className = 'item' + (bad ? ' bad' : '');
        d.innerHTML =
            '<span class="item__ico">' + icon + '</span>' +
            '<div><div class="item__name"></div><div class="item__meta"></div><div class="bar"><i></i></div></div>' +
            '<span class="item__acts"></span>';
        d.querySelector('.item__name').textContent = name;
        el.list.appendChild(d); sync();
        return d;
    }

    function xBtn(onClick) {
        var b = document.createElement('button');
        b.type = 'button'; b.className = 'x'; b.setAttribute('aria-label', t('remove')); b.innerHTML = XSVG;
        b.addEventListener('click', onClick);
        return b;
    }
    function textBtn(label, onClick) {
        var b = document.createElement('button');
        b.type = 'button'; b.className = 'open'; b.textContent = label;
        b.addEventListener('click', onClick);
        return b;
    }
    function openBtn() {
        var a = document.createElement('a');
        a.className = 'open'; a.href = cfg.libraryUrl; a.innerHTML = t('openInLibrary') + ' ' + ARROW;
        return a;
    }

    function badRow(icon, name, reason) {
        var d = row(WARN, name, true);
        d.querySelector('.item__meta').textContent = reason;
        d.querySelector('.item__acts').appendChild(xBtn(function () { d.remove(); sync(); }));
        return d;
    }

    function fileRow(file) {
        var d = row(FILM, file.name, false);
        var item = { file: file, el: d, row: null, state: 'queued', uploader: null, bytes: 0, base: size(file.size) };
        setState(item, 'staged', '');
        return item;
    }

    function chip(cls, text) { return ' <span class="chip ' + cls + '">' + text + '</span>'; }

    /** One place turns queue state into what the row shows and offers. */
    function setState(item, state, note) {
        item.state = state;
        item.el.className = 'item ' + state + (state === 'refused' ? ' bad' : '') + (state === 'done' ? ' proc' : '');
        var meta = item.el.querySelector('.item__meta');
        var acts = item.el.querySelector('.item__acts');
        acts.innerHTML = '';

        // QA F2 (2026-09-20): the bar never runs backwards. A re-render on offline/online/pause
        // used the committed byte, which sits below what the bar already showed after a
        // background tab throttled the transfer — the "random position" testers saw.
        var pct = item.file.size ? Math.round((item.bytes / item.file.size) * 100) : 0;
        if (state === 'uploading' || state === 'paused') { pct = Math.max(pct, item.shown || 0); }
        if (state === 'staged') {
            meta.textContent = item.base;
            acts.appendChild(xBtn(function () { item.el.remove(); queue.splice(queue.indexOf(item), 1); sync(); }));
        } else if (state === 'queued') {
            meta.innerHTML = item.base + chip('', t('stQueued'));
            acts.appendChild(xBtn(function () { cancel(item); }));
        } else if (state === 'uploading') {
            meta.innerHTML = item.base + chip('work', note || (t('stUploading') + ' ' + pct + '%'));
            if (item.row) { acts.appendChild(textBtn(t('pause'), function () { pause(item); })); }   // no session yet ("Creating session"): nothing to pause (QA U2)
            acts.appendChild(xBtn(function () { cancel(item); }));
        } else if (state === 'paused') {
            meta.innerHTML = item.base + chip('', t('stPaused') + ' ' + pct + '%') + (note ? ' <span>' + esc(note) + '</span>' : '');
            acts.appendChild(textBtn(t('resume'), function () { pickResume(item); }));   // nothing resumes silently [RULE-008]
            acts.appendChild(xBtn(function () { cancel(item); }));
        } else if (state === 'done') {
            // Transferred; FastPix is processing. Readiness reaches the server by
            // webhook (or its poll fallback) — the row reads that LOCAL state below.
            meta.innerHTML = item.base + chip('work', t('stProcessing'));
            acts.appendChild(openBtn());
            watch({ upload_id: item.row.id, el: item.el, base: item.base });
        } else if (state === 'refused' || state === 'cancelled') {
            item.el.querySelector('.item__ico').innerHTML = WARN;
            meta.textContent = note || (state === 'cancelled' ? t('cancelled') : t('refused'));
            acts.appendChild(xBtn(function () { item.el.remove(); sync(); }));
        }
    }

    function esc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

    function progress(item, sent) {
        item.bytes = sent;
        // The bar never runs backwards: a re-sent chunk (retry, connection refresh) reports
        // from the committed byte again, but the bytes it is redoing are not lost progress.
        var pct = item.file.size ? Math.min(100, Math.round((sent / item.file.size) * 100)) : 0;   // a 0-byte file showed NaN%
        item.shown = Math.max(item.shown || 0, pct);
        item.el.querySelector('.bar i').style.width = item.shown + '%';
        item.el.querySelector('.item__meta').innerHTML = item.base + chip('work', t('stUploading') + ' ' + item.shown + '%');
    }

    /* The server learns that a transfer finished from FastPix itself (the upload webhook
       or its poll binds the media) — sometimes before this tab does, e.g. the browser
       throttled the tab and the SDK dropped the final chunk's reply. Ask it whenever the
       tab comes back and every 15 s while anything is in flight, and settle rows it
       already calls completed. [WF-002 step 4] */
    function reconcile() {
        var live = queue.filter(function (it) { return it.row && (it.state === 'uploading' || it.state === 'paused'); });
        if (!live.length) { return; }
        api('GET', '/uploads/status?ids=' + live.map(function (it) { return it.row.id; }).join(',')).then(function (res) {
            if (!res.ok) { return; }
            live.forEach(function (it) {
                var st = res.json.uploads && res.json.uploads[String(it.row.id)];
                if (!st || it.state === 'done' || it.state === 'cancelled') { return; }
                if (st.state === 'cancelled') { gone(it); return; }   // cancelled elsewhere (another tab, the 7-day sweep) (QA U21)
                if (st.state !== 'completed') { return; }
                var slotHeld = holdsSlot(it);
                drop(it);
                if (slotHeld) { active--; }
                item_finish(it);
                pump();
            });
        });
    }
    /** Does this row hold one of the `active` slots? A row "confirming" after an SDK error is still
        'uploading' but already gave its slot back (no instance, session known); "Creating session"
        holds one with neither. Read BEFORE drop()/setState(). (QA U1) */
    function holdsSlot(item) {
        return item.state === 'uploading' && (!!item.uploader || !item.row);
    }
    function item_finish(item) {
        item.shown = 100;
        progress(item, item.file.size);
        setState(item, 'done', '');
    }
    /** The server says this session is cancelled: nothing more leaves this tab for it. (QA U21) */
    function gone(item) {
        if (item.state === 'cancelled' || item.state === 'done') { return; }
        var wasUploading = holdsSlot(item);
        drop(item);
        setState(item, 'cancelled', t('cancelledElsewhere'));
        if (wasUploading) { active--; }
        pump();
    }
    document.addEventListener('visibilitychange', function () { if (!document.hidden) { reconcile(); } });
    setInterval(function () {
        reconcile();
        // Heartbeat: the server calls an 'uploading' row silent for 3 min interrupted and offers it
        // for Resume in other tabs, but a stall or the SDK's back-off is silent longer than that
        // while the transfer is very much alive here. (QA U20)
        queue.forEach(function (it) {
            if (it.state === 'uploading' && it.uploader && it.row && Date.now() - (it.reportLast || 0) > 15000) { report(it, true); }
        });
    }, 15000);

    function pump() {
        while (active < cfg.concurrency) {   // 3 files at once, configurable to 6 [REQ-012]
            var next = null;
            for (var i = 0; i < queue.length; i++) {
                if (queue[i].state === 'queued') { next = queue[i]; break; }
            }
            if (!next) { return; }
            start(next);
        }
    }

    /* ------------------------------------------------------------ transfer */

    function start(item) {
        active++;
        setState(item, 'uploading', t('creatingSession'));

        api('POST', '/uploads', {
            filename: item.file.name,
            filesize: item.file.size,
            filetype: item.file.type,
            settings: item.settings || settings()
        }).then(function (res) {
            if (item.state === 'cancelled') {
                // × clicked while the session was being created: cancel() already freed the slot and
                // removed the row; the session it never saw must not upload into thin air. (QA U2)
                if (res.ok) { api('POST', '/uploads/' + res.json.id + '/cancel'); }
                return;
            }
            if (!res.ok) {
                active--;
                if (res.status === 503) {
                    // RULE-005: refused, nothing queued; work in flight continues.
                    setState(item, 'refused', res.json.message || t('notResponding'));   // the row carries the reason; no page-level banner (owner 2026-09-22)
                } else {
                    setState(item, 'refused', res.json.message || t('refused'));
                }
                pump();
                return;
            }

            item.row = res.json;
            attach(item);
        });
    }

    /** Hand the file to the SDK and translate its events into queue state. */
    function attach(item) {
        try {
            item.uploader = window.Uploader.init({
                endpoint: item.row.session_uri || item.row.signed_url,   // the bucket session the server holds, when one is known
                file: item.file,
                chunkSize: Math.floor(cfg.chunkBytes / 1024),   // SDK takes KB; 16384 = 16 MB [REQ-012]
                retryChunkAttempt: 5,                           // five attempts per chunk [REQ-012]
                // The SDK "recycles" the connection every 45 s by default: it aborts the chunk in
                // flight, asks the bucket for the committed byte and re-sends from there, so the
                // bar visibly jumps back (worse in a background tab, where its timers fire late).
                // A chunk is retried on failure anyway; refresh only after a long-idle 10 min.
                connectionRefreshInterval: 600,
                stallTimeout: 60
            });
        } catch (e) {
            active--;
            setState(item, 'refused', e && e.message ? e.message : t('uploaderRefused'));
            pump();
            return;
        }

        setState(item, 'uploading', '');

        // Every listener speaks for THIS instance only: cancel()/resume()/reconcile() swap or
        // drop it, and the SDK's abort() fires `error` synchronously for a chunk in flight —
        // which must not PATCH paused or free the slot a second time. (QA U1)
        var up = item.uploader;
        function live() { return item.uploader === up; }

        // The SDK opens the bucket's resumable session from the signed URL and keeps its
        // address (…?upload_id=…) to itself. Send it to the server once it exists, so a
        // resume after a page change reopens THIS session instead of starting a new one at 0.
        up.on('chunkAttempt', function () {
            var uri = live() && up.sessionUri;
            if (uri && uri !== item.row.session_uri && /[?&]upload_id=/.test(uri)) {
                item.row.session_uri = uri;
                api('PATCH', '/uploads/' + item.row.id, { session_uri: uri });
            }
        });
        up.on('progress', function (event) {
            if (!live()) { return; }
            progress(item, Math.round((event.detail.progress / 100) * item.file.size));
            report(item);
        });

        up.on('success', function () {
            if (!live() || item.state === 'done') { return; }   // reconcile() already settled it
            api('PATCH', '/uploads/' + item.row.id, { bytes_sent: item.file.size, state: 'completed' });
            item_finish(item);
            drop(item);
            active--;
            pump();
        });

        // Five failed attempts on a chunk end here: paused, bytes held. [WF-002]
        up.on('error', function (event) {
            if (!live()) { return; }
            item.uploader = null;   // the SDK destroyed itself with this event; Resume builds a fresh instance on the same session (QA U3)
            api('PATCH', '/uploads/' + item.row.id, { bytes_sent: item.bytes, state: 'paused' });
            if (item.bytes >= item.file.size) {
                // Every byte went out and only the bucket's answer was lost (it can store a PUT while
                // refusing the browser a CORS header — verified 2026-09-20): FastPix may well have the
                // file. Ask the server, which asks the platform, before calling this paused. [QA F1]
                setState(item, 'uploading', t('confirming'));
                setTimeout(reconcile, 1500); setTimeout(reconcile, 8000); setTimeout(reconcile, 20000);
                setTimeout(function () { if (item.state === 'uploading' && !item.uploader) { setState(item, 'paused', t('transferFailedMsg', event.detail.message || t('transferFailed'))); } }, 35000);
            } else {
                setState(item, 'paused', t('transferFailedMsg', event.detail.message || t('transferFailed')));
            }
            active--;
            pump();
        });

        up.on('offline', function (event) {
            if (!live()) { return; }
            setState(item, 'uploading', t('connLost'));
            if (event.detail && typeof event.detail.uploadOffset === 'number') {
                item.bytes = event.detail.uploadOffset;
            }
        });
        up.on('online', function () {
            if (live()) { setState(item, 'uploading', ''); }
        });
    }

    /** Let go of the SDK instance without hearing from it again (its listeners check identity). */
    function drop(item) {
        var up = item.uploader;
        if (!up) { return; }
        item.uploader = null;
        try { up.abort(); up.destroy(); } catch (e) { /* already gone */ }
    }

    // Progress → server, throttled per file; the state rides along so a row the server had
    // called interrupted (silent 3 min) reads uploading again the moment this tab speaks. (QA U20)
    function report(item, force) {
        var now = Date.now();
        if (force || now - (item.reportLast || 0) > 3000) {
            item.reportLast = now;
            api('PATCH', '/uploads/' + item.row.id, { bytes_sent: item.bytes, state: 'uploading' }).then(function (res) {
                if (res.status === 409 && res.json.code === 'fastpix_upload_cancelled') { gone(item); }
            });
        }
    }

    /* ------------------------------------------------------ pause + resume */

    function pause(item) {
        var slotHeld = holdsSlot(item);
        if (item.uploader) { item.uploader.pause(); }
        api('PATCH', '/uploads/' + item.row.id, { bytes_sent: item.bytes, state: 'paused' });
        setState(item, 'paused', t('platformHoldsResume'));
        if (slotHeld) { active--; }
        pump();
    }

    /** Resume needs the same file: still here in this tab, or re-picked after a reload — a browser cannot reopen one on its own. */
    function pickResume(item) {
        if (item.file instanceof File) { resume(item, item.file); return; }
        var input = document.createElement('input');
        input.type = 'file';
        input.accept = el.input.accept;
        input.addEventListener('change', function () { if (input.files[0]) { resume(item, input.files[0]); } });
        input.click();
    }

    function resume(item, file) {
        // Name and size are verified server-side; a different file is refused, never spliced. [RULE-008]
        api('PATCH', '/uploads/' + item.row.id, { resume: true, filename: file.name, filesize: file.size }).then(function (res) {
            if (res.status === 409 && res.json.code === 'fastpix_upload_cancelled') { gone(item); return; }
            if (!res.ok) {
                setState(item, 'paused', res.json.message || t(file === item.file ? 'resumeRefused' : 'differentFile'));
                return;
            }
            if (item.state !== 'paused') { return; }   // cancelled or settled while the request ran
            item.file = file;
            active++;
            setState(item, 'uploading', t('resuming'));

            if (item.uploader && res.json.signed_url === item.row.signed_url) {
                item.uploader.resume();   // same tab, same platform session: the SDK re-syncs the committed offset itself
                return;
            }

            // No live SDK instance (page reload, or a transfer error destroyed it — QA U3), or the
            // server minted a NEW platform session because the signed URL had expired (QA U4): a
            // fresh instance on the session the server holds now, never the old one.
            drop(item);
            if (res.json.signed_url && res.json.signed_url !== item.row.signed_url) { item.bytes = 0; item.shown = 0; }   // new bucket object: nothing of the old one counts — the only time the bar may start lower; otherwise it holds until the re-synced byte catches up (QA F2)
            item.row.signed_url = res.json.signed_url || item.row.signed_url;
            item.row.session_uri = res.json.session_uri || '';
            attach(item);
            // A fresh SDK instance starts at byte 0 and only learns the committed byte from the
            // bucket's reply to that first chunk — a whole chunk re-sent for nothing. pause()
            // before its first request and resume() make it ask the bucket first. Only with a known
            // bucket session: on a fresh platform session the SDK's sync would PUT at the bare signed
            // URL while it is still opening the session — there it just starts normally. (QA U4, U6)
            if (item.uploader && item.row.session_uri) {
                item.uploader.pause();   // synchronous: the SDK's first request is queued as a microtask and now finds it paused
                setTimeout(function () { if (item.uploader) { item.uploader.resume(); } }, 0);   // after that microtask: sync with the bucket, then send from the committed byte
            }
        });
    }

    function cancel(item) {
        var wasUploading = holdsSlot(item);

        item.state = 'cancelled';   // before the SDK hears of it: start()'s reply and the listeners read this (QA U1, U2)
        drop(item);
        if (item.row) { api('POST', '/uploads/' + item.row.id + '/cancel'); }
        item.el.remove(); sync();
        if (wasUploading) { active--; }
        pump();
    }


    /* ------------------------------------------------------- URL ingestion */

    el.urls.addEventListener('input', function () { el.ingest.disabled = !el.urls.value.trim(); });
    el.urls.addEventListener('keydown', function (e) { if (e.key === 'Enter' && !el.ingest.disabled) { el.ingest.click(); } });

    el.ingest.addEventListener('click', function () {
        var urls = el.urls.value.split(/\s+/).filter(Boolean);   // whitespace only: a comma can sit inside a URL (?tracks=1,2) (QA U19)
        if (!urls.length) { return; }
        el.urls.value = '';
        el.ingest.disabled = true;
        var room = Math.max(0, cfg.maxFiles - batchCount());   // 50 per submission, refused by name — the server would silently cap (QA U11)
        urls.forEach(function (u, i) {
            if (i >= room) { badRow(LINK, shortName(u), t('tooMany', cfg.maxFiles)); return; }
            var d = row(LINK, shortName(u), false);
            d.querySelector('.item__meta').textContent = t('stLink');
            var entry = { url: u, el: d };
            d.querySelector('.item__acts').appendChild(xBtn(function () { d.remove(); links.splice(links.indexOf(entry), 1); sync(); }));
            links.push(entry);
        });
        sync();
        if (batchCount()) { openModal(); }   // the frame's URL button says Upload: staging + straight to name & settings
    });

    /** Links leave the batch together: one POST, one verdict per URL. [REQ-015] */
    function ingest(entries, snap) {
        entries.forEach(function (e) {
            e.el.classList.add('proc');
            e.el.querySelector('.item__meta').innerHTML = t('stLink') + chip('work', t('stChecking'));   // the base said "Checking" too, so every row read "Checking Checking"
            e.el.querySelector('.item__acts').innerHTML = '';
        });
        var urls = entries.map(function (e) { return e.url; });

        api('POST', '/videos', { urls: urls, settings: snap }).then(function (res) {
            if (!res.ok) {
                entries.forEach(function (e) { e.el.remove(); });
                urls.forEach(function (u) { badRow(WARN, u, res.json.message || t('nothingWasQueued')); });
                return;
            }

            var refuse = function (d, reason) {
                d.className = 'item bad';
                d.querySelector('.item__ico').innerHTML = WARN;
                d.querySelector('.item__meta').textContent = reason;
                d.querySelector('.item__acts').appendChild(xBtn(function () { d.remove(); sync(); }));
            };
            res.json.verdicts.forEach(function (v, i) {
                var d = entries[i] ? entries[i].el : row(LINK, shortName(v.url), false);
                var meta = d.querySelector('.item__meta'), acts = d.querySelector('.item__acts');
                acts.innerHTML = '';
                if (v.accepted) {
                    if (v.title) { d.querySelector('.item__name').textContent = v.title; }   // the custom title names a link row too (QA F4)
                    d.className = 'item proc';
                    meta.innerHTML = t('checked') + chip('work', t('stProcessing'));
                    acts.appendChild(openBtn());
                    if (v.media_id) { watch({ media_id: v.media_id, el: d, base: t('checked') }); }
                } else {
                    refuse(d, v.reason || t('notReachable'));
                }
            });
            // The server caps a submission too: a link it never judged must not pulse "Checking" forever. (QA U11)
            entries.slice(res.json.verdicts.length).forEach(function (e) { refuse(e.el, t('tooMany', cfg.maxFiles)); });
        });
    }

    /* ---------------------------------------------- name & settings modal */

    function openModal() {
        var n = batchCount();
        el.modalSub.textContent = n === 1 ? t('appliesToOne') : t('appliesTo', n);
        el.upload.textContent = n === 1 ? t('uploadOne') : t('uploadN', n);
        el.modal.hidden = false;
        $('fp-set-title').focus();
    }
    function closeModal() {
        el.modal.hidden = true;
        (el.next.disabled ? el.drop : el.next).focus();   // never a control sync() has just disabled — focus would fall to <body> (QA U15)
    }
    el.next.addEventListener('click', openModal);
    el.modalClose.addEventListener('click', closeModal);
    el.modalCancel.addEventListener('click', closeModal);
    el.modal.addEventListener('click', function (e) { if (e.target === el.modal) { closeModal(); } });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !el.modal.hidden) { closeModal(); } });
    // Tab stays inside the dialog while it is open. (QA U15)
    el.modal.addEventListener('keydown', function (e) {
        if (e.key !== 'Tab') { return; }
        var f = Array.prototype.filter.call(el.modal.querySelectorAll('button,input,[tabindex="0"]'), function (n) { return !n.disabled && n.offsetParent !== null; });
        if (!f.length) { return; }
        if (e.shiftKey && document.activeElement === f[0]) { e.preventDefault(); f[f.length - 1].focus(); }
        else if (!e.shiftKey && document.activeElement === f[f.length - 1]) { e.preventDefault(); f[0].focus(); }
    });

    function stem(name) { return name.replace(/\.[^.]+$/, ''); }
    function withTitle(snap, title) { var s = {}; for (var k in snap) { s[k] = snap[k]; } s.title = title; return s; }

    // The only place a transfer starts. One settings snapshot for the batch [REQ-017].
    el.upload.addEventListener('click', function () {
        if (el.upload.disabled) { return; }
        el.upload.disabled = true;   // one check at a time; a double click must not start the batch twice
        watermarkOk().then(function (ok) {
            el.upload.disabled = false;
            if (ok) { startBatch(); }   // nothing is staged or sent until the watermark can be fetched
        });
    });
    function startBatch() {
        var snap = settings();
        var staged = queue.filter(function (item) { return item.state === 'staged'; });
        // One title on several items would name them all alike (which row failed? which video is
        // which?): each file gets "Title — file name"; links get the same server-side. (QA U8)
        var multi = staged.length + links.length > 1;
        staged.forEach(function (item) {
            item.settings = multi && snap.title ? withTitle(snap, snap.title + ' — ' + stem(item.file.name)) : snap;
            if (item.settings.title) { item.el.querySelector('.item__name').textContent = item.settings.title; }   // the title names the row, as it names the media
            setState(item, 'queued', '');
        });
        var entries = links.splice(0, links.length);
        if (entries.length) { ingest(entries, snap); }
        $('fp-set-title').value = '';   // the title was this batch's; the next one starts blank (QA U8)
        $('fp-set-wm').value = '';      // same for the watermark — it is per batch, not a site setting
        if (swOn('fp-sw-wm')) { $('fp-sw-wm').click(); }
        sync();
        closeModal();
        pump();
    }

    /* ------------------------------------------------- readiness (local read) */

    // Rows still processing. One GET /uploads/status for the whole batch every
    // few seconds — a DB read on this site, never a call to FastPix. Stops
    // when nothing is pending. Terminal: Ready → "Ready", Failed → the reason.
    var pending = [], watchTimer = null;
    function watch(entry) {
        pending.push(entry);
        if (!watchTimer) { watchTimer = setTimeout(tick, 4000); }
    }
    function tick() {
        watchTimer = null;
        if (!pending.length) { return; }
        var ids = pending.filter(function (p) { return p.upload_id; }).map(function (p) { return p.upload_id; });
        var mids = pending.filter(function (p) { return p.media_id; }).map(function (p) { return p.media_id; });
        api('GET', '/uploads/status?ids=' + ids.join(',') + '&media_ids=' + encodeURIComponent(mids.join(','))).then(function (res) {
            if (res.ok) {
                pending = pending.filter(function (p) {
                    var st = p.upload_id ? res.json.uploads[String(p.upload_id)] : res.json.media[p.media_id];
                    return !(st && settle(p, st));
                });
            }
            if (pending.length) { watchTimer = setTimeout(tick, res.ok ? 4000 : 10000); }
        });
    }
    /** Apply a status to a row; true when the row is terminal. */
    function settle(p, st) {
        var meta = p.el.querySelector('.item__meta');
        var status = (st.status || '').toLowerCase();
        if (status === 'ready') {
            meta.innerHTML = p.base + chip('ok', t('stReady'));
            p.el.classList.add('done');
            askReview();
            return true;
        }
        if (st.stale) {   // finished long ago and no video was ever linked to it: not "Processing" — the library is the truth (QA 2026-09-21)
            p.el.remove();
            return true;
        }
        if (status === 'deleted') {   // removed from the library meanwhile: nothing left to show here
            p.el.remove();
            return true;
        }
        if (status === 'failed' || status === 'errored') {
            p.el.className = 'item bad';
            p.el.querySelector('.item__ico').innerHTML = WARN;
            meta.textContent = t('processFailedMsg', st.error_code ? ' — ' + st.error_code : '');
            return true;
        }
        return false;   // created / preparing / processing — keep the Processing chip
    }

    /* An upload is ready: one dismissible review line under the queue, once per site — the server is
       told at once that it was shown, so it never appears again. "first" when nothing had been
       uploaded through the plugin before this page loaded. */
    function askReview() {
        if (!cfg.askReview) { return; }
        var first = cfg.askReview === 'first';
        cfg.askReview = false;
        api('POST', '/review-asked');
        var d = document.createElement('div');
        d.className = 'fp-am-review'; d.setAttribute('role', 'note');
        var stars = document.createElement('span');
        stars.className = 'fp-am-review__stars'; stars.setAttribute('aria-hidden', 'true'); stars.textContent = '★★★★★';
        var link = document.createElement('a');
        link.href = cfg.reviewUrl; link.target = '_blank'; link.rel = 'noopener'; link.textContent = t('reviewLink');
        var txt = document.createElement('span');
        txt.className = 'fp-am-review__txt';
        txt.appendChild(document.createTextNode(t(first ? 'reviewReady' : 'reviewReadyNext') + ' '));
        txt.appendChild(link);
        txt.appendChild(document.createTextNode(' ' + t('reviewTail')));
        var x = xBtn(function () { d.remove(); });
        x.setAttribute('aria-label', t('reviewDismiss'));
        d.appendChild(stars); d.appendChild(txt); d.appendChild(x);
        el.list.parentNode.insertBefore(d, $('fp-am-foot'));
    }

    function shortName(u) {
        try { var p = new URL(u); return p.hostname + p.pathname; } catch (e) { return u; }
    }

    function size(b) {
        var u = ['B', 'KB', 'MB', 'GB'], i = 0;
        while (b >= 1024 && i < 3) { b /= 1024; i++; }
        return b.toFixed(b < 10 && i > 0 ? 1 : 0) + ' ' + u[i];
    }

    /* ------------------------------------ sessions that outlive this page */

    // The server still holds this user's paused / interrupted sessions and the
    // ones transferred but still processing. Rebuild them as rows on every load,
    // so refresh, back/forward and a closed tab lose nothing. Resume asks for the
    // same file — a browser cannot reopen one on its own. [FR-010 reopen]
    function restore() {
        api('GET', '/uploads').then(function (res) {
            if (!res.ok) { return; }
            (res.json.sessions || []).forEach(function (s) {
                var d = row(FILM, (s.settings && s.settings.title) || s.filename, false);
                var item = {
                    file: { name: s.filename, size: s.filesize, type: '' }, el: d, state: s.state, uploader: null,
                    row: { id: s.id, upload_id: s.upload_id, signed_url: s.signed_url, session_uri: s.session_uri || '' },
                    bytes: s.expired ? 0 : s.bytes_sent, base: size(s.filesize), settings: s.settings   // past the platform's window the server re-creates the session: nothing sent counts (QA U6)
                };
                queue.push(item);
                d.querySelector('.bar i').style.width = (s.state === 'completed' ? 100 : Math.round((item.bytes / s.filesize) * 100)) + '%';
                setState(item, s.state === 'completed' ? 'done' : 'paused', s.state === 'completed' ? '' : t(s.expired ? 'windowClosed' : 'platformHoldsResume'));
            });
        });
    }
    restore();

    // Leaving the page (refresh, back/forward, close): the transfer dies with the
    // tab, so tell the server now — keepalive outlives the page — and show the
    // row as paused in case the browser restores this page from its cache.
    // A browser transfer lives in this page: leaving it stops the upload. Ask first
    // while anything is in flight (the browser shows its own wording).
    window.addEventListener('beforeunload', function (e) {
        if (queue.some(function (item) { return item.state === 'uploading' || item.state === 'queued'; })) { e.preventDefault(); e.returnValue = ''; }   // queued files vanish with the page too (QA U21)
    });
    // While a transfer is in flight every link on the page (admin menu, admin bar,
    // Open in library) opens in a new tab, so Videos / Live / Analytics / Settings
    // never take this page — and its uploads — down.
    document.addEventListener('click', function (e) {
        var a = e.target.closest ? e.target.closest('a[href]') : null;
        if (!a || a.target === '_blank' || /^(#|javascript:)/i.test(a.getAttribute('href'))) { return; }
        if (queue.some(function (item) { return item.state === 'uploading' || item.state === 'queued'; })) { e.preventDefault(); window.open(a.href, '_blank', 'noopener'); }
    }, true);
    window.addEventListener('pagehide', function () {
        queue.forEach(function (item) {
            if (item.state !== 'uploading' || !item.row) { return; }
            drop(item);
            fetch(cfg.restUrl + '/uploads/' + item.row.id, {
                method: 'PATCH', keepalive: true, credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
                body: JSON.stringify({ bytes_sent: item.bytes, state: 'paused' })
            });
            setState(item, 'paused', t('platformHoldsResume'));
        });
        active = 0;
    });

    sync();
})();
