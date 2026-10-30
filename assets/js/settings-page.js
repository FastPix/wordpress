/**
 * UI-006 behaviour (redesign): edits collect until the page's one "Save changes"; the
 * credential pair and the signing secret must pass Verify before Save takes them. They are write-only —
 * the page carries masks, never the values (ASSUME-107), and the server reads a
 * mask coming back as "the stored value" — and verified before they replace anything; Send test event proves the receiver
 * end to end; Disconnect asks first.
 */
(function () {
    'use strict';

    if (typeof fastpixSettings === 'undefined') { return; }
    var cfg = fastpixSettings;
    var __ = wp.i18n.__, _n = wp.i18n._n, sprintf = wp.i18n.sprintf;   // [QA L25]
    var $ = function (id) { return document.getElementById(id); };

    function api(method, path, body) {
        return fetch(cfg.restUrl + path, {
            method: method, headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce }, credentials: 'same-origin',
            body: body ? JSON.stringify(body) : undefined
        }).then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { return { ok: r.ok, status: r.status, json: j || {} }; }); },
                function () { return { ok: false, status: 0, json: { message: '' } }; });   // transport failure never leaves a button stuck (review 2026-09-20)
    }
    function note(el, text, err) {
        if (!el) { return; }
        el.textContent = text; el.classList.toggle('err', !!err);
        clearTimeout(el._t); if (!err) { el._t = setTimeout(function () { el.textContent = ''; }, 2500); }
    }
    function showErr(el, text) { if (el) { el.textContent = text || ''; el.hidden = !text; } }
    function copy(text, button) {
        var done = function () { if (button) { var was = button.textContent; button.textContent = '✓'; setTimeout(function () { button.textContent = was; }, 1500); } };
        if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(text).then(done, done); } else { done(); }
    }

    // The stored secret is the owner's to read and edit (ASSUME-113). It stays dotted until asked for, the
    // same Show/Hide the stream key uses — a screen share should not catch it by accident.
    document.querySelectorAll('.fp-secret').forEach(function (f) {
        var btn = document.createElement('button');
        btn.type = 'button'; btn.className = 'fp-st__show'; btn.textContent = __('Show', 'fastpix-io');
        btn.setAttribute('aria-controls', f.id); btn.setAttribute('aria-pressed', 'false');
        btn.addEventListener('click', function () {
            var shown = f.type === 'text';
            f.type = shown ? 'password' : 'text';
            btn.textContent = shown ? __('Show', 'fastpix-io') : __('Hide', 'fastpix-io');
            btn.setAttribute('aria-pressed', String(!shown));
        });
        f.parentNode.insertBefore(btn, f.nextSibling);
    });

    /* ------------------------------------------------- one Save for the page */

    var fields = {
        workspace: $('fp-workspace-input'), drm: $('fp-drm-config'), ttl: $('fp-token-ttl'), retention: $('fp-lesson-retention'),
        lms: $('fp-lms-enabled'), seo: $('fp-structured-data'), uninstall: $('fp-delete-on-uninstall'),
        whSecret: $('fp-webhook-secret'), tokenId: $('fp-token-id'), secret: $('fp-secret')
    };
    Object.keys(fields).forEach(function (k) { if (!fields[k]) { delete fields[k]; } });
    function val(k) { var el = fields[k]; if (!el) { return ''; } return el.type === 'checkbox' ? el.checked : el.value.trim(); }
    var saved = {};
    Object.keys(fields).forEach(function (k) { saved[k] = val(k); });
    function changed() { return Object.keys(fields).filter(function (k) { return val(k) !== saved[k]; }); }
    function has(list, k) { return list.indexOf(k) !== -1; }

    // What Verify last approved — Save takes the pair / secret only while the fields still hold exactly that.
    var credsOk = '', credsBusy = false, wh = { value: null, result: '' };
    function pairNow() { return val('tokenId') + '\n' + val('secret'); }

    var saveAll = $('fp-save-all'), cancelAll = $('fp-cancel-all'), dirtyNote = $('fp-dirty-note'), busy = false;
    function refresh() {
        if (!saveAll) { return; }
        var c = changed(), block = '';
        if ((has(c, 'tokenId') || has(c, 'secret')) && credsOk !== pairNow()) {
            block = __('Verify the access token and secret key before saving.', 'fastpix-io');
        } else if (has(c, 'whSecret') && (wh.value !== val('whSecret') || wh.result === 'mismatch')) {
            block = __('Verify the signing secret before saving.', 'fastpix-io');
        }
        saveAll.disabled = busy || !c.length || !!block;
        cancelAll.disabled = busy || !c.length;
        if (!busy) {
            dirtyNote.textContent = block || (c.length ? sprintf(_n('%d unsaved change', '%d unsaved changes', c.length, 'fastpix-io'), c.length) : '');
            dirtyNote.classList.toggle('warn', !!block);
        }
        if ($('fp-creds-save') && !credsBusy) {
            var credsDone = credsOk === pairNow();
            $('fp-creds-save').disabled = busy || !val('tokenId') || !val('secret') || credsDone;
            $('fp-creds-save').textContent = credsDone ? __('Verified', 'fastpix-io') : __('Verify', 'fastpix-io');
        }
        if ($('fp-webhook-save')) {
            var whDone = wh.value === val('whSecret');
            $('fp-webhook-save').disabled = busy || !val('whSecret') || whDone;
            $('fp-webhook-save').textContent = whDone && wh.result === 'verified' ? __('Verified', 'fastpix-io') : __('Verify', 'fastpix-io');
        }
    }
    Object.keys(fields).forEach(function (k) {
        fields[k].addEventListener('input', refresh); fields[k].addEventListener('change', refresh);
        if (fields[k].tagName === 'INPUT' && fields[k].type !== 'checkbox') {
            fields[k].addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); } });   // Enter no longer saves a single field
        }
    });
    if (fields.lms) { fields.lms.addEventListener('change', function () { var f = $('fp-lesson-retention-field'); if (f) { f.hidden = !fields.lms.checked; } }); }
    window.addEventListener('beforeunload', function (e) { if (!busy && changed().length) { e.preventDefault(); e.returnValue = ''; } });

    if (cancelAll) {
        cancelAll.addEventListener('click', function () {
            Object.keys(fields).forEach(function (k) { if (fields[k].type === 'checkbox') { fields[k].checked = saved[k]; } else { fields[k].value = saved[k]; } });
            if (fields.lms && $('fp-lesson-retention-field')) { $('fp-lesson-retention-field').hidden = !saved.lms; }
            credsOk = ''; wh = { value: null, result: '' };
            ['fp-workspace-err', 'fp-drm-err', 'fp-creds-err', 'fp-webhook-note'].forEach(function (id) { showErr($(id), ''); });
            refresh();
        });
    }

    if (saveAll) {
        saveAll.addEventListener('click', function () {
            var c = changed(), creds = has(c, 'tokenId') || has(c, 'secret');
            busy = true; refresh(); dirtyNote.classList.remove('warn'); dirtyNote.textContent = __('Saving…', 'fastpix-io');
            ['fp-workspace-err', 'fp-drm-err', 'fp-creds-err'].forEach(function (id) { showErr($(id), ''); });
            var step = Promise.resolve(true);
            function then(fn) { step = step.then(function (ok) { return ok ? fn() : false; }); }
            function failed(res, errId, fallback) { showErr($(errId), (res.json && res.json.message) || fallback); return false; }

            if (has(c, 'workspace')) {
                then(function () {
                    return api('POST', '/connection/workspace', { workspace_id: val('workspace') }).then(function (res) {
                        if (!res.ok) { return failed(res, 'fp-workspace-err', __('That workspace key was not accepted.', 'fastpix-io')); }
                        saved.workspace = val('workspace'); var acc = $('fp-account-workspace'); if (acc) { acc.textContent = saved.workspace || '—'; }
                        return true;
                    });
                });
            }
            if (creds) {   // verified a moment ago; connect checks it once more and stores it
                then(function () {
                    return api('POST', '/connection', { token_id: val('tokenId'), secret: val('secret') }).then(function (res) {
                        if (!res.ok) { credsOk = ''; return failed(res, 'fp-creds-err', __('The pair was not accepted. Nothing was saved.', 'fastpix-io')); }
                        saved.tokenId = val('tokenId'); saved.secret = val('secret');
                        return true;
                    });
                });
            }
            var body = {};
            if (has(c, 'drm')) { body.drm_configuration_id = val('drm'); }
            if (has(c, 'ttl')) { body.playback_token_ttl = Math.max(2, Math.min(1440, parseInt(val('ttl'), 10) || 15)) * 60; }
            if (has(c, 'retention')) { body.lesson_retention_days = Math.max(1, Math.min(3650, parseInt(val('retention'), 10) || 90)); }
            if (has(c, 'lms')) { body.lms_enabled = val('lms'); }
            if (has(c, 'seo')) { body.structured_data = val('seo'); }
            if (has(c, 'uninstall')) { body.delete_on_uninstall = val('uninstall'); }
            if (has(c, 'whSecret')) { body.webhook_secret = val('whSecret'); }
            if (Object.keys(body).length) {
                then(function () {
                    return api('PATCH', '/settings', body).then(function (res) {
                        if (!res.ok) { return failed(res, has(c, 'drm') ? 'fp-drm-err' : 'fp-webhook-note', __('Not saved.', 'fastpix-io')); }
                        var j = res.json;
                        if (fields.drm) { fields.drm.value = j.drm_configuration_id || ''; }
                        if (fields.ttl && j.playback_token_ttl) { fields.ttl.value = Math.round(j.playback_token_ttl / 60); }
                        if (fields.retention && j.lesson_retention_days) { fields.retention.value = j.lesson_retention_days; }
                        Object.keys(fields).forEach(function (k) { if (k !== 'workspace' && k !== 'tokenId' && k !== 'secret') { saved[k] = val(k); } });
                        return true;
                    });
                });
            }
            step.then(function (ok) {
                busy = false; refresh();
                if (!ok) { dirtyNote.classList.add('warn'); dirtyNote.textContent = __('Some changes were not saved — see the message above.', 'fastpix-io'); return; }
                dirtyNote.textContent = __('Saved', 'fastpix-io');
                if (creds) { setTimeout(function () { window.location.reload(); }, 900); return; }   // the Account rows and the header pill come from the server
                if (has(c, 'whSecret')) { testBtn.disabled = false; testBtn.removeAttribute('title'); sendTest(true); }   // ask FastPix for a delivery that confirms it
            });
        });
    }

    /* ---------------------------------------- Webhook URL + signing secret */

    var whCopy = $('fp-webhook-copy');
    if (whCopy) { whCopy.addEventListener('click', function () { copy($('fp-webhook-url').value, whCopy); }); }

    // Verify checks the secret against FastPix's last delivery without saving it. No delivery yet
    // ('unknown') cannot be checked here — Save is allowed and FastPix's next event confirms it.
    var whSave = $('fp-webhook-save'), whNote = $('fp-webhook-note');
    if (whSave) {
        whSave.addEventListener('click', function () {
            var v = val('whSecret');
            whSave.disabled = true; showErr(whNote, '');
            api('POST', '/settings/webhook-verify', { secret: v }).then(function (res) {
                if (!res.ok) { wh = { value: null, result: '' }; showErr(whNote, (res.json && res.json.message) || __('Could not verify.', 'fastpix-io')); refresh(); return; }
                wh = { value: v, result: res.json.result };
                if (wh.result === 'mismatch') {
                    showErr(whNote, __('Not verified — this secret does not match the one on the endpoint in the FastPix dashboard.', 'fastpix-io'));
                } else if (wh.result === 'unknown') {
                    showErr(whNote, __('Cannot check yet — FastPix has not sent an event to this site. You can save it; FastPix\u2019s next event confirms it.', 'fastpix-io'));
                } else {
                    showErr(whNote, '');
                }
                refresh();
            });
        });
    }

    var testBtn = $('fp-webhook-test');
    /* The self-test signs with the stored secret and checks it with the same secret, so
       it can only prove the receiver URL. Whether the SECRET is right is settled by
       FastPix's own deliveries: the server keeps a verdict (pending / verified /
       rejected) judged from the moment the secret was saved. Render that, never
       "verified" on the strength of the self-test. */
    function verdictLine(st, prefix) {
        var le = $('fp-last-event'); le.innerHTML = '';
        var v = st.verdict, text, pill, cls;
        if (v === 'verified' && st.last_delivery) {
            /* translators: 1: webhook event type, 2: time, HH:MM */
            text = st.last_delivery.at ? sprintf(__('Secret verified — %1$s from FastPix, %2$s UTC', 'fastpix-io'), st.last_delivery.type, st.last_delivery.at.slice(11, 16)) : sprintf(__('Secret verified — %s from FastPix', 'fastpix-io'), st.last_delivery.type);
            pill = __('verified', 'fastpix-io'); cls = 'ok';
        } else if (v === 'rejected') {
            text = __('Not verified — this secret does not match the one on the endpoint in the FastPix dashboard.', 'fastpix-io');
            pill = __('not verified', 'fastpix-io'); cls = 'warn';
        } else {
            text = (prefix || '') + __('Not verified — waiting for FastPix\u2019s next event to confirm this secret.', 'fastpix-io');
            pill = __('not verified', 'fastpix-io'); cls = 'warn';
        }
        le.dataset.verdict = v || '';
        le.appendChild(document.createTextNode(text + ' '));
        var p = document.createElement('span'); p.className = 'fp-st__pill sm ' + cls; p.textContent = pill; le.appendChild(p);
        return v;
    }
    // After the self-test FastPix is nudged for a real delivery; poll until it settles the verdict.
    function watchStatus(why) {
        var tries = 0, tick = function () {
            api('GET', '/settings/webhook-status').then(function (res) {
                if (!res.ok) { return; }
                var st = res.json, nameEl = $('fp-account-workspace-name');
                if (st.workspace_name && nameEl) { nameEl.textContent = st.workspace_name; }
                var v = verdictLine(st, __('Receiver reachable.', 'fastpix-io') + ' ' + (why || ''));
                if (v === 'verified' || v === 'rejected') { return; }
                if (++tries < 40) { watchTimer = setTimeout(tick, 3000); }   // two minutes, then the next reload picks it up
            });
        };
        clearTimeout(watchTimer);
        watchTimer = setTimeout(tick, 1500);
    }
    var watchTimer = null;
    // A reload that lands on a still-waiting secret keeps watching for FastPix's delivery.
    if ($('fp-last-event') && $('fp-last-event').dataset.verdict === 'pending') { watchStatus(''); }
    function sendTest(afterSave) {
        testBtn.disabled = true; var was = testBtn.textContent; testBtn.textContent = __('Sending…', 'fastpix-io');
        api('POST', '/settings/webhook-test').then(function (res) {
            testBtn.disabled = false; testBtn.textContent = was;
            var le = $('fp-last-event');
            if (res.ok && res.json.delivered) {
                var why = (res.json.platform_nudged ? __('FastPix was asked for a delivery.', 'fastpix-io') : (res.json.nudge_reason ? /* translators: %s: the reason */ sprintf(__('FastPix could not be asked for a delivery (%s).', 'fastpix-io'), res.json.nudge_reason) : __('FastPix could not be asked for a delivery.', 'fastpix-io'))) + ' ';
                verdictLine(res.json, (afterSave ? __('Saved.', 'fastpix-io') + ' ' : '') + __('Receiver reachable.', 'fastpix-io') + ' ' + why);
                watchStatus(why);   // the platform's own delivery proves or refutes the secret
            } else {
                le.innerHTML = '';
                le.appendChild(document.createTextNode((res.json && res.json.message) || /* translators: %s: HTTP status code */ sprintf(__('The receiver answered HTTP %s — the URL is not reachable from this server.', 'fastpix-io'), res.json && res.json.status ? res.json.status : res.status)));
                var bad = document.createElement('span'); bad.className = 'fp-st__pill sm warn'; bad.textContent = __('not reachable', 'fastpix-io'); le.appendChild(bad);
            }
        });
    }
    if (testBtn) { testBtn.addEventListener('click', function () { sendTest(false); }); }

    /* ---------------------------------------------------- Account: copy, creds */

    document.querySelectorAll('.fp-copy').forEach(function (b) { b.addEventListener('click', function () { copy(b.getAttribute('data-copy'), b); }); });

    // The pair edits in place; Verify checks it against FastPix and stores nothing — Save stores it.
    var credsBtn = $('fp-creds-save');
    if (credsBtn) {
        credsBtn.addEventListener('click', function () {
            var pair = pairNow();
            credsBusy = true; credsBtn.disabled = true; credsBtn.textContent = __('Verifying…', 'fastpix-io'); showErr($('fp-creds-err'), '');
            api('POST', '/connection/verify', { token_id: val('tokenId'), secret: val('secret') }).then(function (res) {
                credsBusy = false;
                if (!res.ok) { credsOk = ''; showErr($('fp-creds-err'), res.json.message || __('The pair was not accepted.', 'fastpix-io')); refresh(); return; }
                credsOk = pair;   // the button reads "Verified" until either field changes
                refresh();
            });
        });
    }

    refresh();

    var disconnect = $('fp-disconnect');
    if (disconnect) {
        disconnect.addEventListener('click', function () {
            fpDialog.confirm({ title: __('Disconnect this site from FastPix?', 'fastpix-io'), message: __('Nothing is deleted. Videos on this site stop playing until an account is connected again.', 'fastpix-io'), ok: __('Disconnect', 'fastpix-io'), danger: true }).then(function (ok) {
                if (!ok) { return; }
                api('DELETE', '/connection').then(function () { window.location.reload(); });
            });
        });
    }

    var report = $('fp-copy-report');
    if (report) {
        report.addEventListener('click', function () {
            api('GET', '/system-report').then(function (res) {
                if (!res.ok) { note($('fp-report-note'), __('Could not build the report', 'fastpix-io'), true); return; }
                copy(JSON.stringify(res.json, null, 2), null); note($('fp-report-note'), __('Copied', 'fastpix-io'));
            });
        });
    }
})();
