/**
 * UI-006 behaviour (redesign): fields autosave on change with an inline
 * "Saved" note; the signing secret and the credential pair are write-only —
 * the page carries masks, never the values (ASSUME-107), and the server reads a
 * mask coming back as "the stored value" — and verified before they replace anything; Send test event proves the receiver
 * end to end; Disconnect asks first.
 */
(function () {
    'use strict';

    if (typeof fastpixSettings === 'undefined') { return; }
    var cfg = fastpixSettings;
    var __ = wp.i18n.__, sprintf = wp.i18n.sprintf;   // [QA L25]
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
        var done = function () { if (button) { var was = button.textContent; button.textContent = __('Copied', 'fastpix'); setTimeout(function () { button.textContent = was; }, 1500); } };
        if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(text).then(done, done); } else { done(); }
    }

    // The stored secret is the owner's to read and edit (ASSUME-113). It stays dotted until asked for, the
    // same Show/Hide the stream key uses — a screen share should not catch it by accident.
    document.querySelectorAll('.fp-secret').forEach(function (f) {
        var btn = document.createElement('button');
        btn.type = 'button'; btn.className = 'fp-st__show'; btn.textContent = __('Show', 'fastpix');
        btn.setAttribute('aria-controls', f.id); btn.setAttribute('aria-pressed', 'false');
        btn.addEventListener('click', function () {
            var shown = f.type === 'text';
            f.type = shown ? 'password' : 'text';
            btn.textContent = shown ? __('Show', 'fastpix') : __('Hide', 'fastpix');
            btn.setAttribute('aria-pressed', String(!shown));
        });
        f.parentNode.insertBefore(btn, f.nextSibling);
    });

    /* ---------------------------------------------- Video setup: autosave */

    var ws = $('fp-workspace-input');
    if (ws) {
        var wsSaved = ws.value.trim();
        function saveWorkspace() {
            var v = ws.value.trim();
            if (v === wsSaved) { return; }
            showErr($('fp-workspace-err'), '');
            api('POST', '/connection/workspace', { workspace_id: v }).then(function (res) {
                if (!res.ok) { showErr($('fp-workspace-err'), res.json.message || __('That workspace key was not accepted.', 'fastpix')); return; }
                wsSaved = v; note($('fp-workspace-note'), __('Saved', 'fastpix'));
                var acc = $('fp-account-workspace'); if (acc) { acc.textContent = v || '—'; }
            });
        }
        ws.addEventListener('change', saveWorkspace);
        ws.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); saveWorkspace(); } });
    }

    var drm = $('fp-drm-config');
    if (drm) {
        var drmSaved = drm.value.trim();
        function saveDrm() {
            var v = drm.value.trim();
            if (v === drmSaved) { return; }
            showErr($('fp-drm-err'), '');
            api('PATCH', '/settings', { drm_configuration_id: v }).then(function (res) {
                if (!res.ok) { showErr($('fp-drm-err'), res.json.message || __('That does not look like a DRM configuration ID (UUID).', 'fastpix')); return; }
                drmSaved = res.json.drm_configuration_id || ''; drm.value = drmSaved; note($('fp-drm-note'), drmSaved ? __('Saved', 'fastpix') : __('Cleared', 'fastpix'));
            });
        }
        drm.addEventListener('change', saveDrm);
        drm.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); saveDrm(); } });
    }

    var ttl = $('fp-token-ttl');
    if (ttl) {
        ttl.addEventListener('change', function () {
            var minutes = Math.max(2, Math.min(1440, parseInt(ttl.value, 10) || 15));
            api('PATCH', '/settings', { playback_token_ttl: minutes * 60 }).then(function (res) {
                if (!res.ok) { note($('fp-ttl-note'), res.json.message || __('Not saved', 'fastpix'), true); return; }
                ttl.value = Math.round(res.json.playback_token_ttl / 60); note($('fp-ttl-note'), __('Saved', 'fastpix'));
            });
        });
    }
    // Course features master switch — reveals the retention field.
    var lms = $('fp-lms-enabled');
    if (lms) {
        lms.addEventListener('change', function () {
            api('PATCH', '/settings', { lms_enabled: lms.checked }).then(function (res) {
                if (!res.ok) { return; }
                note($('fp-lms-note'), __('Saved', 'fastpix'));
                var field = $('fp-lesson-retention-field');
                if (field) { field.hidden = !lms.checked; }
            });
        });
    }

    // Per-learner retention days — same autosave pattern as the TTL.
    var retention = $('fp-lesson-retention');
    if (retention) {
        retention.addEventListener('change', function () {
            var days = Math.max(1, Math.min(3650, parseInt(retention.value, 10) || 90));
            api('PATCH', '/settings', { lesson_retention_days: days }).then(function (res) {
                if (!res.ok) { return; }
                retention.value = res.json.lesson_retention_days; note($('fp-lesson-retention-note'), __('Saved', 'fastpix'));
            });
        });
    }

    var seo = $('fp-structured-data');
    if (seo) {
        seo.addEventListener('change', function () {
            api('PATCH', '/settings', { structured_data: seo.checked }).then(function (res) { note($('fp-seo-note'), res.ok ? __('Saved', 'fastpix') : __('Not saved', 'fastpix'), !res.ok); });
        });
    }

    /* ---------------------------------------- Webhook URL + signing secret */

    var whCopy = $('fp-webhook-copy');
    if (whCopy) { whCopy.addEventListener('click', function () { copy($('fp-webhook-url').value, whCopy); }); }

    var secret = $('fp-webhook-secret'), whSave = $('fp-webhook-save'), whNote = $('fp-webhook-note');
    if (secret) {
        var whSaved = secret.value.trim(), whBusy = false;
        secret.addEventListener('input', function () { whSave.disabled = whBusy || secret.value.trim() === ''; showErr(whNote, ''); });   // a keystroke must not re-arm a save in flight
        whSave.addEventListener('click', function () {
            if (whBusy) { return; }
            whSave.disabled = true;
            if (secret.value.trim() === whSaved) { whSave.disabled = false; sendTest(true); return; }   // unchanged: just verify it again
            whBusy = true;
            api('PATCH', '/settings', { webhook_secret: secret.value.trim() }).then(function (res) {
                whBusy = false;
                whSave.disabled = false;
                if (!res.ok || !res.json.webhook_configured) { showErr(whNote, (res.json && res.json.message) || __('Could not save.', 'fastpix')); return; }
                whSaved = secret.value.trim();
                $('fp-webhook-test').disabled = false; $('fp-webhook-test').removeAttribute('title');
                showErr(whNote, '');
                sendTest(true);   // "Save & verify": verify right away
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
            text = st.last_delivery.at ? sprintf(__('Secret verified — %1$s from FastPix, %2$s UTC', 'fastpix'), st.last_delivery.type, st.last_delivery.at.slice(11, 16)) : sprintf(__('Secret verified — %s from FastPix', 'fastpix'), st.last_delivery.type);
            pill = __('verified', 'fastpix'); cls = 'ok';
        } else if (v === 'rejected') {
            text = __('Not verified — this secret does not match the one on the endpoint in the FastPix dashboard.', 'fastpix');
            pill = __('not verified', 'fastpix'); cls = 'warn';
        } else {
            text = (prefix || '') + __('Not verified — waiting for FastPix\u2019s next event to confirm this secret.', 'fastpix');
            pill = __('not verified', 'fastpix'); cls = 'warn';
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
                var v = verdictLine(st, __('Receiver reachable.', 'fastpix') + ' ' + (why || ''));
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
        testBtn.disabled = true; var was = testBtn.textContent; testBtn.textContent = __('Sending…', 'fastpix');
        api('POST', '/settings/webhook-test').then(function (res) {
            testBtn.disabled = false; testBtn.textContent = was;
            var le = $('fp-last-event');
            if (res.ok && res.json.delivered) {
                var why = (res.json.platform_nudged ? __('FastPix was asked for a delivery.', 'fastpix') : (res.json.nudge_reason ? /* translators: %s: the reason */ sprintf(__('FastPix could not be asked for a delivery (%s).', 'fastpix'), res.json.nudge_reason) : __('FastPix could not be asked for a delivery.', 'fastpix'))) + ' ';
                verdictLine(res.json, (afterSave ? __('Saved.', 'fastpix') + ' ' : '') + __('Receiver reachable.', 'fastpix') + ' ' + why);
                watchStatus(why);   // the platform's own delivery proves or refutes the secret
            } else {
                le.innerHTML = '';
                le.appendChild(document.createTextNode((res.json && res.json.message) || /* translators: %s: HTTP status code */ sprintf(__('The receiver answered HTTP %s — the URL is not reachable from this server.', 'fastpix'), res.json && res.json.status ? res.json.status : res.status)));
                var bad = document.createElement('span'); bad.className = 'fp-st__pill sm warn'; bad.textContent = __('not reachable', 'fastpix'); le.appendChild(bad);
            }
        });
    }
    if (testBtn) { testBtn.addEventListener('click', function () { sendTest(false); }); }

    /* ---------------------------------------------------- Account: copy, creds */

    document.querySelectorAll('.fp-copy').forEach(function (b) { b.addEventListener('click', function () { copy(b.getAttribute('data-copy'), b); }); });

    // The pair edits in place; Verify checks it against FastPix before anything is stored.
    if ($('fp-creds-save')) {
        var credsBtn = $('fp-creds-save');
        function credsDirty() { showErr($('fp-creds-err'), ''); }
        $('fp-token-id').addEventListener('input', credsDirty); $('fp-secret').addEventListener('input', credsDirty);
        credsBtn.addEventListener('click', function () {
            var t = $('fp-token-id').value.trim(), s = $('fp-secret').value.trim();
            if (!t || !s) { showErr($('fp-creds-err'), __('Paste both the token ID and its secret key.', 'fastpix')); return; }
            credsBtn.disabled = true; showErr($('fp-creds-err'), ''); note($('fp-creds-note'), __('Verifying…', 'fastpix'));
            api('POST', '/connection', { token_id: t, secret: s }).then(function (res) {
                credsBtn.disabled = false;
                if (!res.ok) { note($('fp-creds-note'), ''); showErr($('fp-creds-err'), res.json.message || __('The pair was not accepted. Nothing was saved.', 'fastpix')); return; }
                note($('fp-creds-note'), __('Verified and saved', 'fastpix'));
                setTimeout(function () { window.location.reload(); }, 900);   // the Account rows and the header pill come from the server
            });
        });
    }

    var uninstall = $('fp-delete-on-uninstall');
    if (uninstall) { uninstall.addEventListener('change', function () { api('PATCH', '/settings', { delete_on_uninstall: uninstall.checked }).then(function (res) { note($('fp-uninstall-note'), res.ok ? __('Saved', 'fastpix') : __('Not saved', 'fastpix'), !res.ok); }); }); }

    var disconnect = $('fp-disconnect');
    if (disconnect) {
        disconnect.addEventListener('click', function () {
            fpDialog.confirm({ title: __('Disconnect this site from FastPix?', 'fastpix'), message: __('Nothing is deleted. Videos on this site stop playing until an account is connected again.', 'fastpix'), ok: __('Disconnect', 'fastpix'), danger: true }).then(function (ok) {
                if (!ok) { return; }
                api('DELETE', '/connection').then(function () { window.location.reload(); });
            });
        });
    }

    var report = $('fp-copy-report');
    if (report) {
        report.addEventListener('click', function () {
            api('GET', '/system-report').then(function (res) {
                if (!res.ok) { note($('fp-report-note'), __('Could not build the report', 'fastpix'), true); return; }
                copy(JSON.stringify(res.json, null, 2), null); note($('fp-report-note'), __('Copied', 'fastpix'));
            });
        });
    }
})();
