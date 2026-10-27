/**
 * UI-001 wizard — four steps: Welcome → Connect plugin → Workspace → Webhooks
 * → done.
 *
 * Connect plugin: background validation (one authenticated GET server-side);
 * the header badge shows a muted fill-state while typing and turns green only
 * once CONNECTED. Workspace: explicit UUID entry, required for Next/Finish.
 * Webhooks: copyable URL + write-only secret, Save & verify / Skip — skipped
 * keeps the polling footnote on done. No workspace id is ever read from API
 * responses.
 */
(function () {
    'use strict';

    // Escape platform-supplied strings before they land in innerHTML (workspace
    // name, event type come from the API response).
    function esc(t) { var d = document.createElement('div'); d.textContent = t == null ? '' : String(t); return d.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;'); }

    var root = document.getElementById('fp-wizard');
    if (!root || typeof fastpixOnboarding === 'undefined') {
        return;
    }

    var cfg = fastpixOnboarding;
    var i18n = cfg.i18n;
    var STEPS = ['welcome', 'connect', 'wsweb'];

    var el = {
        views: root.querySelectorAll('[data-fp-view]'),
        steps: root.querySelectorAll('.cwsteps [data-fp-step]'),
        lines: root.querySelectorAll('.cwsteps [data-fp-line]'),
        stepsWrap: document.getElementById('fp-cwsteps'),
        body: document.getElementById('fp-cwbody'),
        navfoot: document.getElementById('fp-navfoot'),
        pair: document.getElementById('fp-pair'),
        token: document.getElementById('fp-token-id'),
        secret: document.getElementById('fp-secret'),
        tokenErr: document.getElementById('fp-token-err'),
        secretErr: document.getElementById('fp-secret-err'),
        pairStatus: document.getElementById('fp-pair-status'),
        showhide: document.getElementById('fp-showhide'),
        save: document.getElementById('fp-save'),
        change: document.getElementById('fp-change'),
        savestate: document.getElementById('fp-savestate'),
        error: document.getElementById('fp-conn-error'),
        errorTitle: document.getElementById('fp-conn-error-title'),
        errorBody: document.getElementById('fp-conn-error-body'),
        copyReport: document.getElementById('fp-copy-report'),
        wsInput: document.getElementById('fp-workspace-input'),
        wsErr: document.getElementById('fp-workspace-err'),
        wsSave: document.getElementById('fp-workspace-save'),
        wsState: document.getElementById('fp-workspace-state'),
        wsNote: document.getElementById('fp-workspace-note'),
        whUrl: document.getElementById('fp-webhook-url'),
        whCopy: document.getElementById('fp-webhook-copy'),
        whSecret: document.getElementById('fp-webhook-secret'),
        whSave: document.getElementById('fp-webhook-save'),
        whState: document.getElementById('fp-webhook-state'),
        whNote: document.getElementById('fp-webhook-note'),
        whSkip: document.getElementById('fp-webhook-skip'),
        back: document.getElementById('fp-back'),
        next: document.getElementById('fp-next'),
        finish: document.getElementById('fp-finish'),
        done: document.getElementById('fp-pair-done')
    };

    var connected = root.getAttribute('data-fp-connected') === '1';
    var workspaceSaved = root.getAttribute('data-fp-workspace-saved') === '1';
    var webhooksOn = root.getAttribute('data-fp-webhooks') === '1';
    var view = 'welcome';

    function api(method, path, body) {
        return fetch(cfg.restUrl + path, {
            method: method,
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
            credentials: 'same-origin',
            body: body ? JSON.stringify(body) : undefined
        }).then(function (response) {
            return response.json().catch(function () { return {}; }).then(function (json) {
                return { ok: response.ok, json: json || {} };
            });
        }, function () {
            return { ok: false, json: { message: '' } };   // transport failure: callers see a refusal, never a hung promise (review 2026-09-20)
        });
    }

    /* ---------------------------------------------------------- navigation */

    function nextAllowed() {
        if (view === 'connect') { return connected; }
        return view === 'welcome';
    }

    function show(name) {
        view = name;
        el.views.forEach(function (section) {
            section.hidden = section.getAttribute('data-fp-view') !== name;
        });

        // Landing hides the whole wizard card + plugin header (Figma 9341:73141).
        var wrap = root.closest('.fastpix-wrap');
        if (wrap) { wrap.classList.toggle('fp-landing-active', name === 'landing'); }
        root.setAttribute('data-fp-current', name);   // per-view CSS hook (Figma v3 steps)
        var underNote = document.getElementById('fp-under-connect');
        if (underNote) { underNote.hidden = name !== 'connect'; }

        var stepIdx = STEPS.indexOf(name);
        var inWizard = stepIdx !== -1;
        el.stepsWrap.hidden = !inWizard;
        el.steps.forEach(function (step, i) {
            step.classList.toggle('on', i === stepIdx);
            step.classList.toggle('done', inWizard && i < stepIdx);
            if (i === stepIdx) { step.setAttribute('aria-current', 'step'); } else { step.removeAttribute('aria-current'); }
            step.querySelector('.c').textContent = (inWizard && i < stepIdx) ? '✓' : String(i + 1);
        });
        el.lines.forEach(function (line, i) {
            line.classList.toggle('done', inWizard && stepIdx > i);
        });

        el.body.hidden = !inWizard;
        if (!inWizard) { root.classList.remove('grown'); }
        el.navfoot.hidden = true;   // every step carries its own footer now (Figma v3)
        el.back.disabled = name === 'welcome';
        el.next.hidden = name === 'wsweb';
        el.next.disabled = !nextAllowed();

        syncFinish();

        if (name === 'done') {
            var note = root.querySelector('[data-fp-webhook-note]');
            if (note) { note.parentElement.hidden = webhooksOn; }   // polling footnote only without webhooks
            var doneFoot = root.querySelector('.cwfoot[data-fp-view="done"]');
            if (doneFoot) { doneFoot.hidden = webhooksOn; }
        }
    }

    el.next.addEventListener('click', function () {
        var idx = STEPS.indexOf(view);
        if (idx !== -1 && idx < STEPS.length - 1 && nextAllowed()) { show(STEPS[idx + 1]); }
    });
    el.back.addEventListener('click', function () {
        var idx = STEPS.indexOf(view);
        if (idx > 0) { show(STEPS[idx - 1]); }
    });
    el.finish.addEventListener('click', function () {
        if (workspaceSaved) { show('done'); }   // Finish requires the workspace
    });

    function syncFinish() {
        el.finish.disabled = !workspaceSaved;
        if (workspaceSaved) {
            el.finish.removeAttribute('title');
        } else {
            el.finish.setAttribute('title', i18n.saveWsFirst);
        }
    }

    /* ------------------------------------------- step 2: the credential pair */

    function filled(input) {
        return input.value.replace(/\s/g, '') !== '';
    }

    function syncPair() {
        var count = (filled(el.token) ? 1 : 0) + (filled(el.secret) ? 1 : 0);
        setVerdict(null);
        el.save.disabled = count !== 2;   // the click (or Enter) checks the pair — nothing runs on its own
    }

    /* Pane state class only — the row carries no fill-state chatter (frame 9342:94781):
       the disabled Connect says "not yet", one red line says "refused". */
    function setVerdict(verdict) {
        el.pair.classList.toggle('verified', verdict === 'ok');
        el.pair.classList.toggle('refused', verdict === 'err');
        el.pairStatus.textContent = '';
    }

    var lastTried = '';
    var saving = false;
    // The field carries the stored secret and edits in place (owner 2026-09-23) — no sentinel to
    // clear, and Show/Hide reveals it the same way Settings does.


    function clearFieldError(input, errEl) {
        input.closest('.fld').classList.remove('bad');
        errEl.hidden = true;
        input.removeAttribute('aria-invalid');
        input.removeAttribute('aria-describedby');
    }

    // No empty-on-blur nags (Figma v2): the helper lines under each field
    // already say what goes in; the disabled CTA carries the fill state.
    el.token.addEventListener('input', function () { clearFieldError(el.token, el.tokenErr); syncPair(); });
    el.secret.addEventListener('input', function () { clearFieldError(el.secret, el.secretErr); syncPair(); });

    [el.token, el.secret].forEach(function (input) {
        input.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' && !el.save.disabled) {
                event.preventDefault();
                save();
            }
        });
    });

    el.showhide.addEventListener('click', function () {
        var showing = el.secret.type === 'text';
        el.secret.type = showing ? 'password' : 'text';
        el.showhide.textContent = showing ? i18n.show : i18n.hide;
        el.showhide.setAttribute('aria-pressed', String(!showing));
    });

    /* The button itself reports progress: "Connect" → spinner "Checking…" → ✓ → "Update". */
    function setSaving(state) {
        saving = state;
        el.savestate.hidden = true;
        if (state) {
            el.save.disabled = true;
            el.save.innerHTML = '<span class="spin" aria-hidden="true"></span>' + i18n.checking;
        } else {
            el.save.disabled = false;
            el.save.textContent = connected ? i18n.update : i18n.connect;
        }
        el.back.disabled = state || view === 'welcome';
    }

    function statusError(code, message) {
        el.savestate.hidden = false;
        var text;
        if (code === 'fastpix_bad_credentials') {           // ERR-001
            text = i18n.invalidCredentials;
            el.error.hidden = true;
        } else if (code === 'fastpix_unreachable') {        // ERR-002
            text = i18n.unreachableShort;
            // FR-002: unreachable is a server/firewall problem, not wrong keys —
            // reveal the guidance panel and the system-report action.
            el.errorTitle.textContent = i18n.unreachableTitle;
            el.errorBody.textContent = i18n.unreachableBody;
            el.copyReport.hidden = false;
            el.copyReport.textContent = i18n.copyReport;   // reset if a prior copy renamed it
            el.error.hidden = false;
        } else {
            text = message || i18n.unknownError;
            el.error.hidden = true;
        }
        el.savestate.classList.remove('ok');
        el.savestate.classList.add('err');
        el.savestate.textContent = text;
        setVerdict('err');
    }

    /* flash = the pair was just accepted by a click: the button turns into a ✓ for a
       beat, then Update + Next take its place. On page load (already connected) no flash. */
    function showSaved(state, flash) {
        connected = true;
        el.error.hidden = true;
        el.savestate.hidden = true;
        el.savestate.classList.remove('err');
        el.save.textContent = i18n.update;
        el.save.disabled = false;                         // the template renders it disabled; connected means it stays live
        var next2 = document.getElementById('fp-next2');
        function reveal() {
            el.done.hidden = true;
            el.save.hidden = false;
            if (next2) { next2.disabled = false; next2.hidden = false; }   // the frame draws Next only once connected
        }
        if (flash) {
            el.save.hidden = true;
            el.done.hidden = false;
            setTimeout(reveal, 900);
        } else {
            reveal();
        }
        el.secret.type = 'text';                          // stays readable after a save
        el.showhide.textContent = i18n.hide;
        el.showhide.setAttribute('aria-pressed', 'true');
        el.token.value = state.token_id || el.token.value; // truncated form [FR-005]
        setVerdict('ok');
        el.next.disabled = !nextAllowed();
    }

    function save() {
        setSaving(true);
        el.error.hidden = true;

        var tried = el.token.value.trim() + ' ' + el.secret.value;
        lastTried = tried;

        api('POST', '/connection', {
            token_id: el.token.value.trim(),
            secret: el.secret.value
        }).then(function (result) {
            setSaving(false);
            if (tried !== el.token.value.trim() + ' ' + el.secret.value) { return; }   // stale
            if (result.ok) { showSaved(result.json, true); return; }
            statusError(result.json.code, result.json.message);
        }).catch(function () {
            setSaving(false);
            statusError('fastpix_unreachable', '');
        });
    }

    el.save.addEventListener('click', function () {
        if (el.secret.value.trim() === '') {   // nothing to send: ask for it rather than post an empty pair
            el.secret.focus();
            el.pairStatus.textContent = i18n.enterSecret;
            return;
        }
        save();
    });

    el.change.addEventListener('click', function () {
        el.change.hidden = true;
        el.save.hidden = false;
        el.token.value = '';
        el.secret.value = '';
        lastTried = '';
        connected = false;
        el.next.disabled = true;
        var next2Off = document.getElementById('fp-next2');
        if (next2Off) { next2Off.disabled = true; next2Off.hidden = true; }
        el.savestate.hidden = true;
        el.save.textContent = i18n.connect;
        syncPair();
        el.token.focus();
    });

    // navigator.clipboard exists only on secure origins (an http:// admin has none):
    // fall back to the selection-based copy so the button never throws silently.
    function copyText(text) {
        if (navigator.clipboard && navigator.clipboard.writeText) { return navigator.clipboard.writeText(text); }
        return new Promise(function (resolve, reject) {
            var ta = document.createElement('textarea');
            ta.value = text; ta.setAttribute('readonly', ''); ta.style.position = 'fixed'; ta.style.opacity = '0';
            document.body.appendChild(ta); ta.select();
            var ok = false;
            try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
            document.body.removeChild(ta);
            ok ? resolve() : reject(new Error('copy unavailable'));
        });
    }

    el.copyReport.addEventListener('click', function () {
        api('GET', '/system-report').then(function (result) {
            if (!result.ok) { return; }
            copyText(JSON.stringify(result.json, null, 2)).then(function () {
                el.copyReport.textContent = i18n.reportCopied;
            }, function () {});
        });
    });

    /* --------------------------------------------- step 3: the workspace id */

    var UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

    if (el.wsInput) {
        var wsSavedValue = el.wsInput.value.trim();
        el.wsInput.addEventListener('input', function () {
            el.wsErr.hidden = true;
            el.wsInput.closest('.fld').classList.remove('bad');
            el.wsSave.disabled = el.wsInput.value.trim() === '';
        });
        // No Save button in the design: the key saves when the field is LEFT (blur /
        // Enter), never on a typing pause — a half-typed key must not reach the server.
        el.wsInput.addEventListener('change', function () { el.wsSave.click(); });
        el.wsInput.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') { event.preventDefault(); el.wsSave.click(); }
        });
        el.wsSave.disabled = el.wsInput.value.trim() === '';

        el.wsSave.addEventListener('click', function () {
            var id = el.wsInput.value.trim();
            if (id === '' || id === wsSavedValue) { return; }
            if (!UUID.test(id) && !/^\d{6,32}$/.test(id)) {
                el.wsErr.textContent = i18n.wsHint;
                el.wsErr.hidden = false;
                el.wsInput.closest('.fld').classList.add('bad');
                return;
            }

            if (el.wsSave.dataset.busy) { return; }
            el.wsSave.dataset.busy = '1';
            el.wsSave.disabled = true;
            api('POST', '/connection/workspace', { workspace_id: id }).then(function (result) {
                delete el.wsSave.dataset.busy;
                el.wsSave.disabled = false;
                if (!result.ok) {
                    el.wsErr.textContent = result.json.message || i18n.wsRejected;
                    el.wsErr.hidden = false;
                    el.wsInput.closest('.fld').classList.add('bad');
                    return;
                }
                workspaceSaved = true;   // no "Saved" line under the field (owner 2026-09-07) — Finish enabling is the signal
                wsSavedValue = id;
                syncFinish();
            });
        });
    }

    /* ------------------------------------------------- step 4: the webhooks */

    if (el.whCopy) {
        el.whCopy.addEventListener('click', function () {
            copyText(el.whUrl.value).then(function () {
                el.whCopy.textContent = i18n.copied;
                setTimeout(function () { el.whCopy.textContent = i18n.copy; }, 1500);
            }, function () {});
        });
    }

    if (el.whSecret) {
        var whBusy = false;
        el.whSecret.addEventListener('input', function () {
            el.whSave.disabled = whBusy || el.whSecret.value.trim() === '';   // a keystroke must not re-arm a save in flight
        });
        // Saves when the field is LEFT (blur / Enter), never on a typing pause — same as the workspace key (QA S7).
        el.whSecret.addEventListener('change', function () { el.whSave.click(); });
        el.whSecret.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') { event.preventDefault(); el.whSave.click(); }
        });

        el.whSave.addEventListener('click', function () {
            if (whBusy || el.whSecret.value.trim() === '') { return; }   // a blur on the emptied field must not post an empty secret
            whBusy = true;
            el.whSave.disabled = true;
            api('PATCH', '/settings', { webhook_secret: el.whSecret.value.trim() }).then(function (result) {
                whBusy = false;
                if (result.ok && result.json.webhook_configured) {
                    webhooksOn = true;
                    el.whState.hidden = false;
                    el.whState.textContent = i18n.configured;
                    el.whState.classList.remove('warnb');
                    el.whState.classList.add('ok');
                    el.whNote.classList.remove('err');
                    el.whNote.classList.add('ok');
                    el.whNote.innerHTML = '<span class="dot" aria-hidden="true"></span>' + i18n.whSavedVerifying;
                    el.whSecret.value = '';
                    el.whSecret.placeholder = '•••••••• (stored encrypted — paste to replace)';
                    // Prove the secret end to end: a signed test event through the public URL.
                    api('POST', '/settings/webhook-test').then(function (t) {
                        var okv = t.ok && t.json && t.json.delivered;
                        if (okv && t.json.verdict === 'verified') { renderWebhookVerdict(t.json); return; }   // the new secret matched FastPix's last delivery
                        el.whNote.classList.toggle('ok', !!okv); el.whNote.classList.toggle('err', !okv);
                        el.whNote.innerHTML = '<span class="dot" aria-hidden="true"></span>' + (okv
                            ? i18n.whVerified + (t.json.platform_nudged ? '' : ' ' + i18n.whNoNudge.replace('%s', esc(t.json.nudge_reason || '')))
                            : ((t.json && t.json.message) || i18n.whNotVerified.replace('%d', (t.json && t.json.status) || t.status)));
                        if (okv) {   // the self-test only proves the URL; FastPix's own delivery (nudged when possible) settles the secret
                            el.whNote.classList.remove('ok'); el.whNote.classList.add('wait');
                            watchWebhookVerdict();
                        }
                    });
                } else {
                    el.whNote.classList.add('err');
                    el.whNote.innerHTML = '<span class="dot" aria-hidden="true"></span>' + ((result.json && result.json.message) || i18n.couldNotSave);
                    el.whSave.disabled = false;
                }
            });
        });
    }

    /* The secret is verified only by FastPix's own deliveries (ASSUME-098): while the
       verdict is "waiting" keep asking the server for two minutes, so the card turns
       green (verified) or red (not verified) as soon as FastPix delivers. Runs after
       Save & verify and on a reload that lands on a still-waiting secret. */
    function renderWebhookVerdict(st) {
        if (!el.whNote || !st) { return st && st.verdict; }
        el.whNote.classList.remove('ok', 'err', 'wait');
        if (st.verdict === 'verified' && st.last_delivery) {
            el.whNote.classList.add('ok');
            el.whNote.innerHTML = '<span class="dot" aria-hidden="true"></span>' + i18n.whVerifiedDelivered.replace('%s', esc(st.last_delivery.type)) + (st.workspace_name ? i18n.whForWorkspace.replace('%s', esc(st.workspace_name)) : '');
        } else if (st.verdict === 'rejected') {
            el.whNote.classList.add('err');
            el.whNote.innerHTML = '<span class="dot" aria-hidden="true"></span>' + i18n.whRejected;
            if (el.whSave) { el.whSave.disabled = false; }
        } else if (st.verdict === 'pending') {
            el.whNote.classList.add('wait');
        }
        el.whNote.dataset.verdict = st.verdict || '';
        return st.verdict;
    }
    var whWatch = null;
    function watchWebhookVerdict() {
        clearTimeout(whWatch);
        var tries = 0, tick = function () {
            api('GET', '/settings/webhook-status').then(function (r2) {
                var v = renderWebhookVerdict(r2.ok ? r2.json : null);
                if (v === 'verified' || v === 'rejected') { return; }
                if (++tries < 40) { whWatch = setTimeout(tick, 3000); }
            });
        };
        whWatch = setTimeout(tick, 1500);
    }
    if (el.whNote && el.whNote.dataset.verdict === 'pending') { watchWebhookVerdict(); }

    if (el.whSkip) {
        el.whSkip.addEventListener('click', function () {
            // Skipped webhooks keep the polling footnote on the done screen.
            if (workspaceSaved) {
                show('done');
            } else {
                el.whNote.innerHTML = '<span class="dot" aria-hidden="true"></span>' + i18n.whSkipped;
            }
        });
    }



    /* ----------------------------------------------------------- initial */

    // The landing IS the Welcome step (Figma 9342:73581): Start setup enters
    // the wizard at Connect; Back from Connect returns to the landing.
    var startBtn = document.getElementById('fp-start-setup');
    if (startBtn) { startBtn.addEventListener('click', function () { show('connect'); }); }
    var back2 = document.getElementById('fp-back2');
    if (back2) { back2.addEventListener('click', function () { show('landing'); }); }
    var next2Btn = document.getElementById('fp-next2');
    if (next2Btn) { next2Btn.addEventListener('click', function () { if (connected) { show('wsweb'); } }); }
    var back3 = document.getElementById('fp-back3');
    if (back3) { back3.addEventListener('click', function () { show('connect'); }); }
    var whShowHide = document.getElementById('fp-wh-showhide');
    if (whShowHide) {
        whShowHide.addEventListener('click', function () {
            var showing = el.whSecret.type === 'text';
            el.whSecret.type = showing ? 'password' : 'text';
            whShowHide.textContent = showing ? i18n.show : i18n.hide;
            whShowHide.setAttribute('aria-pressed', String(!showing));
        });
    }

    if (connected) {
        showSaved({
            token_id: root.getAttribute('data-fp-token-masked'),
            secret: el.secret.value || el.secret.placeholder
        });
        show(workspaceSaved ? 'connect' : 'wsweb');   // revisit vs continue setup
    } else {
        show('landing');   // the Figma landing precedes the wizard
        syncPair();
    }
})();
