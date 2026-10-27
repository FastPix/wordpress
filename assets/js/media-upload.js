/**
 * Media Library upload surface — REQ-010's third surface.
 *
 * A thin queue over the same session flow as Add media: POST /uploads for the
 * signed URL, the vendored FastPix SDK for the transfer, PATCH for progress.
 * The finished video is bound by the upload webhook and appears in the Media
 * Library as its proxy attachment (REQ-035) — refresh the modal to see it.
 * Batch settings are the REQ-017 defaults; the Add media screen is the surface
 * for choosing them.
 *
 * The panel is injected by post-plupload-upload-ui, which fires in the media
 * modal template too — a delegated click handler covers both without caring
 * when the markup appears. The queue itself lives here, not in the DOM core
 * re-renders: three transfers at once [REQ-012], the rest wait. (QA U12)
 */
(function () {
    'use strict';

    if (typeof fastpixMediaUpload === 'undefined') {
        return;
    }

    var cfg = fastpixMediaUpload;
    var i18n = cfg.i18n || {};
    function t(key, a) { var s = i18n[key] || key; return a === undefined ? s : s.replace('%s', a); }

    var waiting = [], running = [], active = 0;

    function api(method, path, body, retried) {
        return fetch(cfg.restUrl + path, {
            method: method,
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
            credentials: 'same-origin',
            body: body ? JSON.stringify(body) : undefined
        }).then(function (r) {
            return r.json().catch(function () { return {}; }).then(function (j) {
                var res = { ok: r.ok, status: r.status, json: j || {} };
                // Heartbeat suspends after ~10 min without input: fetch a fresh nonce and retry ONCE. (QA U18)
                if (r.status !== 403 || res.json.code !== 'rest_cookie_invalid_nonce' || retried || !cfg.ajaxUrl) { return res; }
                return fetch(cfg.ajaxUrl + '?action=rest-nonce', { credentials: 'same-origin' })
                    .then(function (n) { return n.ok ? n.text() : ''; })
                    .then(function (nonce) {
                        if (!/^\w{6,}$/.test(nonce)) { return res; }   // logged out: admin-ajax answers "0"
                        cfg.nonce = nonce;
                        return api(method, path, body, true);
                    }, function () { return res; });
            });
        }, function () { return { ok: false, status: 0, json: {} }; });
    }
    // Core's heartbeat hands out a fresh REST nonce in the second half of its 24 h life; the jQuery
    // event is 'heartbeat-tick' ('heartbeat.tick' is only the wp.hooks action). (QA U18)
    if (window.jQuery) {
        window.jQuery(document).on('heartbeat-tick', function (e, data) { if (data && data.rest_nonce) { cfg.nonce = data.rest_nonce; } });
    }

    // Core can render the uploader template twice (the inline uploader on upload.php AND the media
    // modal): same ids, so take the panel that is actually on screen, never just the first. (QA U2)
    var used = null;   // the panel the user last clicked in — behind an open modal the inline one is "visible" too
    function panel() {
        if (used && document.contains(used) && used.offsetParent !== null) { return used; }
        var all = document.querySelectorAll('.fastpix-media-panel'), i;
        for (i = 0; i < all.length; i++) { if (all[i].offsetParent !== null) { return all[i]; } }
        return all[0] || null;
    }

    document.addEventListener('click', function (event) {
        if (event.target && event.target.id === 'fastpix-media-pick') {
            used = event.target.closest('.fastpix-media-panel') || used;
            var input = panel().querySelector('#fastpix-media-file');
            input.onchange = function () {
                Array.prototype.slice.call(input.files).forEach(enqueue);
                input.value = '';
                pump();
            };
            input.click();
        }
    });

    // Core's Backbone uploader view re-creates the panel on every re-render, leaving these rows
    // detached: they are kept here and put back into whichever queue node is in the document. (QA U12)
    var rows = [];
    function paint() {
        var q = panel() && panel().querySelector('#fastpix-media-queue');
        if (!q) { return; }
        rows.forEach(function (li) { if (li.parentNode !== q) { q.appendChild(li); } });
    }
    function line(text) {
        var li = document.createElement('li');
        li.textContent = text;
        rows.push(li);
        paint();
        return li;
    }
    function say(job, text) { job.li.textContent = job.file.name + ' — ' + text; paint(); }

    function enqueue(file) {
        if (file.size > cfg.maxFileBytes) {
            line(file.name + ' — ' + t('tooBig'));
            return;
        }
        if (file.type && cfg.acceptedTypes.indexOf(file.type.toLowerCase()) === -1) {
            line(file.name + ' — ' + t('badType', file.type));
            return;
        }
        waiting.push({ file: file, li: line(file.name + ' — ' + t('queued')), id: 0, bytes: 0, reportLast: 0, uploader: null });
    }

    function pump() {
        while (active < cfg.concurrency && waiting.length) {
            var job = waiting.shift();
            active++;
            running.push(job);
            upload(job);
        }
    }
    function finish(job) {
        if (job.uploader) { try { job.uploader.destroy(); } catch (e) { /* already gone */ } job.uploader = null; }
        running.splice(running.indexOf(job), 1);
        active--;
        pump();
    }

    function upload(job) {
        var file = job.file;
        say(job, t('creatingSession'));

        api('POST', '/uploads', {
            filename: file.name,
            filesize: file.size,
            filetype: file.type,
            settings: {}                    // REQ-017 defaults
        }).then(function (res) {
            if (!res.ok) {
                say(job, res.json.message || t('refused'));
                finish(job);
                return;
            }
            job.id = res.json.id;

            try {
                job.uploader = window.Uploader.init({
                    endpoint: res.json.signed_url,
                    file: file,
                    chunkSize: cfg.chunkKb,
                    retryChunkAttempt: 5,
                    connectionRefreshInterval: 600,   // no 45 s recycle: it aborts the chunk in flight and the bar jumps back
                    stallTimeout: 60
                });
            } catch (e) {
                say(job, e && e.message ? e.message : t('uploaderRefused'));
                finish(job);
                return;
            }

            var sessionSent = '';
            job.uploader.on('chunkAttempt', function () {   // keep the bucket session address so Add media can resume it after a page change
                var uri = job.uploader && job.uploader.sessionUri;
                if (uri && uri !== sessionSent && /[?&]upload_id=/.test(uri)) { sessionSent = uri; api('PATCH', '/uploads/' + job.id, { session_uri: uri }); }
            });
            job.uploader.on('progress', function (event) {
                job.bytes = Math.round((event.detail.progress / 100) * file.size);
                say(job, Math.round(event.detail.progress) + '%');
                report(job);
            });
            job.uploader.on('success', function () {
                api('PATCH', '/uploads/' + job.id, { bytes_sent: file.size, state: 'completed' });
                say(job, t('transferred'));
                finish(job);
            });
            job.uploader.on('error', function (event) {
                if (!job.uploader) { return; }   // pagehide dropped it already
                api('PATCH', '/uploads/' + job.id, { bytes_sent: job.bytes, state: 'paused' });
                if (job.bytes >= file.size) {
                    // Every byte went out; only the bucket's answer was lost. The server asks FastPix. [QA F1]
                    say(job, t('confirming'));
                    var tries = 0, check = function () {
                        api('GET', '/uploads/status?ids=' + job.id).then(function (res) {
                            var st = res.ok && res.json.uploads && res.json.uploads[String(job.id)];
                            if (st && st.state === 'completed') { say(job, t('transferred')); return; }
                            if (++tries < 4) { setTimeout(check, tries * 6000); } else { say(job, t('pausedMsg', event.detail.message || t('transferFailed'))); }
                        });
                    };
                    setTimeout(check, 1500);
                } else {
                    say(job, t('pausedMsg', event.detail.message || t('transferFailed')));
                }
                finish(job);
            });
        });
    }

    // Progress → server, throttled; without it the server calls the row interrupted after 3 min silent.
    function report(job) {
        var now = Date.now();
        if (now - job.reportLast > 3000) {
            job.reportLast = now;
            api('PATCH', '/uploads/' + job.id, { bytes_sent: job.bytes, state: 'uploading' });
        }
    }

    // A transfer lives in this page: Save/Update/close would kill it silently — ask first
    // (the browser shows its own wording), and tell the server it paused if they leave anyway. (QA U12)
    window.addEventListener('beforeunload', function (e) {
        if (active || waiting.length) { e.preventDefault(); e.returnValue = ''; }
    });
    window.addEventListener('pagehide', function () {
        running.forEach(function (job) {
            if (!job.uploader || !job.id) { return; }
            var up = job.uploader;
            job.uploader = null;   // its `error` listener must not answer the abort below
            try { up.abort(); up.destroy(); } catch (err) { /* already gone */ }
            fetch(cfg.restUrl + '/uploads/' + job.id, {
                method: 'PATCH', keepalive: true, credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
                body: JSON.stringify({ bytes_sent: job.bytes, state: 'paused' })
            });
        });
    });
})();
