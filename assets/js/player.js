/**
 * Frontend helper for embeds.
 *
 * 1. Token refresh (SEC-005 edge-cache fallback): a private/DRM
 *    <fastpix-player> is rendered with a token minted at page render. If this
 *    HTML was served from a cache that ignored DONOTCACHEPAGE, the token may be
 *    stale — so before the player boots we compare the expiry the server
 *    stamped (data-fp-exp) with now and, when it is within 60 s of lapsing (or
 *    the element has no token at all), fetch GET /player-config/{id} (no-store)
 *    and set fresh token/poster attributes.
 *
 * 2. Watch progress (WF-013, RULE-031/032): with analytics consent, a
 *    pseudonymous reference in first-party local storage identifies the viewer
 *    (logged-in viewers are keyed by their account server-side — their
 *    requests carry the REST nonce). Distinct whole seconds watched are
 *    tracked as merged ranges, persisted locally so a rewatch in a later
 *    session cannot inflate the count, and posted to /progress at most once
 *    every 15 s. Resume (REQ-064, MISS-019 → seek-on-load): the stored
 *    furthest point is applied before playback starts. Without consent none of
 *    this runs and playback is unaffected.
 */
(function () {
    'use strict';

    var cfg = window.fastpixPlayerCfg || {};

    /* ------------------------------------------------------ token refresh */

    function nowSec() { return Math.floor(Date.now() / 1000); }

    function fetchConfig(el) {
        var opts = { credentials: 'same-origin', cache: 'no-store', headers: {} };
        if (cfg.nonce) { opts.headers['X-WP-Nonce'] = cfg.nonce; }
        return fetch(el.getAttribute('data-fp-config'), opts)
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (c) { return c && c.token ? c : null; })
            .catch(function () { return null; });
    }

    /* The vendored player (1.0.21) reads token/drm-token ONCE at boot — its
       observedAttributes is ["theme"] — so a token set on the attribute after the
       element upgraded changes nothing. Once upgraded it is handed the token through
       the player's own properties — which it reads only in loadByPlaybackId() and the
       spritesheet fetch; it has NO error-recovery reload of its own (see recover()
       below) — and, when the token it booted with was already stale, its
       loadByPlaybackId() API reloads the source with the fresh one. [QA L7, L8] */
    function applyConfig(el, c, reload) {
        el.setAttribute('token', c.token);
        if (c.drm_token) { el.setAttribute('drm-token', c.drm_token); }
        if (c.poster) { el.setAttribute('poster', c.poster); }
        el.setAttribute('data-fp-exp', String(c.exp || 0));
        if (typeof el.loadByPlaybackId !== 'function') { return; }   // not upgraded yet: the attributes are read at boot
        el.token = c.token;
        if (c.drm_token) { el.drmToken = c.drm_token; }
        if (reload) {
            try { el.loadByPlaybackId(el.getAttribute('playback-id'), { token: c.token, drmToken: c.drm_token || undefined }); } catch (e) {}
        }
    }

    /* In-session renewal [QA L8]: a fresh token is fetched a minute before `exp`, for
       as long as the page stays open. Verified live 2026-09-20: the platform checks
       the JWT on the master manifest only (level playlists and segments carry their
       own CDN signatures), so a renewal never has to interrupt playback — it only
       has to be in the player's hands before its next manifest load. */
    function scheduleRenewal(el) {
        var exp = parseInt(el.getAttribute('data-fp-exp') || '0', 10);
        if (exp <= nowSec()) { return; }
        clearTimeout(el.fpRenew);
        el.fpRenew = setTimeout(function () {
            fetchConfig(el).then(function (c) {
                if (!c) { return; }
                applyConfig(el, c, false);
                if (parseInt(c.exp || '0', 10) === exp) { el.fpRenew = setTimeout(function () { scheduleRenewal(el); }, 30000); return; }   // same set back (clock skew): ask again in 30 s, not 5
                scheduleRenewal(el);
            });
        }, Math.max(5, exp - nowSec() - 60) * 1000);
    }

    /* Recovery (QA L8): the vendored player never reloads by itself — on a fatal hls.js
       error it shows a message and usually destroys its hls instance, so a token the CDN
       refuses mid-play ends playback for good. When a tokened embed dies, fetch a fresh
       set, reload through the player's own destroy() (a clean hls instance) +
       loadByPlaybackId(), and put the viewer back where they were. At most one attempt
       per 30 s and 3 per page view, so a video that is really broken is left alone.
       Runs on error only — healthy playback is never interrupted. */
    var RECOVER_GAP = 30, RECOVER_MAX = 3;

    function recover(el) {
        var v = innerVideo(el);
        if (!v || (el.fpRecoveries || 0) >= RECOVER_MAX || nowSec() - (el.fpRecoverAt || 0) < RECOVER_GAP) { return; }
        el.fpRecoverAt = nowSec();
        var at = v.currentTime, playing = !v.paused && !v.ended;   // read now: destroy() pauses
        fetchConfig(el).then(function (c) {
            if (!c || typeof el.loadByPlaybackId !== 'function') { return; }
            el.fpRecoveries = (el.fpRecoveries || 0) + 1;
            v.addEventListener('loadedmetadata', function () {
                // The player clears its error card only on hls RECOVERED, which a reload never fires.
                try { var card = el.shadowRoot.querySelector('.errorContainer'); if (card) { card.remove(); el.isError = false; } } catch (e) {}
                // A live stream has no place to go back to — and under hls.js its duration IS finite, so ask the element.
                if (at > 0 && isFinite(v.duration) && el.getAttribute('stream-type') !== 'live-stream') { try { v.currentTime = Math.min(at, v.duration); } catch (e) {} }
                if (playing) { var p = v.play(); if (p && p.catch) { p.catch(function () {}); } }
            }, { once: true });
            try { if (typeof el.destroy === 'function') { el.destroy(); } } catch (e) {}
            applyConfig(el, c, true);
            scheduleRenewal(el);
            watchErrors(el);   // destroy() made a new hls instance
        });
    }

    // Two places a dead source surfaces: the inner <video>'s `error`, which the player
    // re-dispatches on the host (native HLS — Safari), and a FATAL hls.js error, which it
    // does not surface at all — that one is read from the player's own hls instance. (QA L8)
    function watchErrors(el) {
        if (!el.fpErrHost) { el.fpErrHost = true; el.addEventListener('error', function () { recover(el); }); }
        var tries = 0;
        var hook = function () {
            var hls = el.hls;
            if (!hls || typeof hls.on !== 'function') { if (tries++ < 100) { setTimeout(hook, 200); } return; }   // boots after hls.js arrives from its CDN
            if (hls.fpHooked) { return; }
            hls.fpHooked = true;
            hls.on((window.Hls && window.Hls.Events && window.Hls.Events.ERROR) || 'hlsError', function (evt, data) { if (data && data.fatal) { recover(el); } });
        };
        hook();
    }

    function refresh(el) {
        if (!el.getAttribute('data-fp-config')) { return; }
        watchErrors(el);
        var exp = parseInt(el.getAttribute('data-fp-exp') || '0', 10);
        if (el.getAttribute('token') && exp - nowSec() > 60) { scheduleRenewal(el); return; }
        // Stale (a cached copy of the page): the player boots with a token the platform
        // refuses, so the fresh one must also RELOAD the source.
        fetchConfig(el).then(function (c) { if (c) { applyConfig(el, c, true); scheduleRenewal(el); } });
    }

    /* ------------------------------------------------------------ consent */

    // RULE-031/INT-009: one consent decision for the whole player — assets/js/player-consent.js
    // (loaded first) also switches the player's own analytics off when consent is refused.
    function hasConsent() {
        return typeof window.fastpixHasConsent === 'function' ? window.fastpixHasConsent() : true;
    }

    /* ----------------------------------------------------- watch progress */

    var BEAT_SECONDS = 16;   // RULE-032: one write per viewer per video per 15 s — a 15 s cadence lands exactly on the bucket boundary and every other beat was 429 (QA F5)

    function store(key, value) { try { localStorage.setItem(key, value); } catch (e) {} }
    function load(key) { try { return localStorage.getItem(key); } catch (e) { return null; } }

    // The pseudonymous viewer reference (SEC-014) — created only with consent,
    // only for visitors (logged-in viewers are identified by their nonce).
    function viewerKey() {
        if (cfg.nonce) { return ''; }
        var key = load('fastpix_viewer');
        if (!key) {
            var bytes = new Uint8Array(16);
            (window.crypto || window.msCrypto).getRandomValues(bytes);
            key = Array.prototype.map.call(bytes, function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
            store('fastpix_viewer', key);
        }
        return key;
    }

    // Distinct whole seconds as merged, sorted [start, end) ranges — rewatch
    // cannot inflate, backward seek cannot reduce (REQ-064).
    function addSecond(ranges, s) {
        for (var i = 0; i < ranges.length; i++) {
            var r = ranges[i];
            if (s >= r[0] && s < r[1]) { return false; }
            if (s === r[1]) {
                r[1]++;
                if (ranges[i + 1] && ranges[i + 1][0] === r[1]) { r[1] = ranges[i + 1][1]; ranges.splice(i + 1, 1); }
                return true;
            }
            if (s === r[0] - 1) { r[0]--; return true; }
            if (s < r[0]) { ranges.splice(i, 0, [s, s + 1]); return true; }
        }
        ranges.push([s, s + 1]);
        return true;
    }

    function covered(ranges) {
        var n = 0;
        for (var i = 0; i < ranges.length; i++) { n += ranges[i][1] - ranges[i][0]; }
        return n;
    }

    function progressUrl(video) { return cfg.progress + '/' + video; }

    // Lesson completion: 100 coverage slots. Each slot stores a
    // PLAY COUNT (a pass through the slot), so rewatched stretches are known.
    // A slot is credited only while genuinely playing — seeking marks nothing.
    function playsHex(counts) {
        var hex = '';
        for (var i = 0; i < counts.length; i++) { hex += ('0' + counts[i].toString(16)).slice(-2); }
        return hex;
    }

    function send(state, useBeacon) {
        if (!state.dirty) { return; }
        state.dirty = false;
        var body = {
            video: state.video,
            position: Math.round(state.position * 10) / 10,
            covered: covered(state.ranges),
            viewer_key: state.viewer || undefined,
            final: useBeacon || undefined   // exempt from the 15 s per-viewer cadence — it is the resume point
        };
        if (state.lesson) {
            // Cumulative play counts — one beat carries every slot's count so
            // far (batched, never one request per slot).
            body.post = state.lesson.post;
            body.plays = playsHex(state.lesson.counts);
            if (state.lesson.learner) { body.learner = state.lesson.learner; }
            store('fastpix_plays_' + state.video + '_' + state.lesson.post, body.plays);
        }
        body = JSON.stringify(body);
        store('fastpix_cov_' + state.video, JSON.stringify(state.ranges));
        if (useBeacon && !cfg.nonce && navigator.sendBeacon) {
            // sendBeacon cannot carry the nonce header — visitors only.
            navigator.sendBeacon(cfg.progress, new Blob([body], { type: 'application/json' }));
            return;
        }
        var headers = { 'Content-Type': 'application/json' };
        if (cfg.nonce) { headers['X-WP-Nonce'] = cfg.nonce; }
        fetch(cfg.progress, { method: 'POST', credentials: 'same-origin', headers: headers, body: body, keepalive: !!useBeacon })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (res) {
                // The server confirms the lesson is complete and names what
                // comes next — that is when the learner may move on.
                if (res && res.lesson && res.lesson.completed) {
                    releaseGate();   // watched enough — reveal the LMS's own button
                    if (state.lesson && state.lesson.ui.unlock) {
                        state.lesson.ui.unlock(res.lesson.next_url || '');
                    }
                    // The LMS renders its "completed" banner server-side, so it
                    // can only appear on a page load — refresh once, when the
                    // video finishes (or loops back), never mid-watch.
                    if (state.lesson && state.el) { refreshWhenDone(state.el, state.lesson); }
                }
            })
            .catch(function () {});
    }

    // The vendored player is a web component with an open shadow root; the
    // real <video> appears once it boots.
    function innerVideo(el) {
        try { return el.shadowRoot && el.shadowRoot.querySelector('video'); } catch (e) { return null; }
    }

    // Skip-proof lessons: the renderer hid the LMS's own complete button until
    // `body.fastpix-antiskip-done` is set. Reveal it once watching is done — or
    // when we cannot measure watching — so a learner is never locked out.
    function releaseGate() {
        if (document.body) { document.body.classList.add('fastpix-antiskip-done'); }
    }

    // After the LMS ticked the lesson, reload once so its server-rendered
    // "completed" banner appears — but only when the video ends or loops back,
    // never in the middle of watching. sessionStorage stops reload loops.
    function refreshWhenDone(el, lesson) {
        if (lesson.refreshArmed) { return; }
        lesson.refreshArmed = true;
        var key = 'fastpix_lsn_reload_' + lesson.post;
        try { if (sessionStorage.getItem(key)) { return; } } catch (e) { return; }
        var fire = function () {
            try { sessionStorage.setItem(key, '1'); } catch (e) {}
            location.reload();
        };
        var v = innerVideo(el);
        if (!v) { return; }   // no inner video — the next natural page load shows it
        // Only when the video actually finishes: a pause to take notes or a seek back to
        // rewatch must never reload the page mid-watch. [QA X12]
        if (v.ended) { fire(); return; }
        v.addEventListener('ended', fire, { once: true });
    }

    /* FastPix hosts the video, measures the watching and reports completion —
       course flow (locking, unlocking, navigation) belongs to the LMS. So the
       lesson page shows nothing from us: the video plays, the threshold is
       detected server-side, the LMS is told, and the LMS decides what opens
       next. `fastpix:lessoncomplete` is there for themes that want more. */
    function lessonUi(el, lesson) {
        return {
            update: function () {},
            unlock: function (url) {
                if (lesson.unlocked) { return; }
                lesson.unlocked = true;
                document.dispatchEvent(new CustomEvent('fastpix:lessoncomplete', {
                    detail: { post: lesson.post, next: url || '' }
                }));
            }
        };
    }

    function track(el) {
        var video = parseInt(el.getAttribute('data-fp-video') || '0', 10);
        var duration = parseFloat(el.getAttribute('data-fp-duration') || '0');
        var lessonEmbed = !!(el.getAttribute('data-fp-post') && el.hasAttribute('complete-at'));
        if (el.hasAttribute('data-fp-tracked')) { return; }
        // Can't measure this embed (misconfigured, or no consent) → never hold the
        // LMS button hostage, or the learner could not finish the lesson at all.
        if (!video || !duration) { if (lessonEmbed) { releaseGate(); } return; }
        if (!hasConsent()) { if (lessonEmbed) { releaseGate(); } return; }   // nothing recorded, playback unaffected
        el.setAttribute('data-fp-tracked', '1');

        var ranges = [];
        try { ranges = JSON.parse(load('fastpix_cov_' + video)) || []; } catch (e) { ranges = []; }
        var state = { el: el, video: video, viewer: viewerKey(), ranges: ranges, position: 0, dirty: false };

        // Lesson embed: complete-at + post printed by the server on lesson post
        // types only; viewer-key only when the embed opted in.
        var lessonPost = parseInt(el.getAttribute('data-fp-post') || '0', 10);
        if (lessonPost && el.hasAttribute('complete-at')) {
            var counts = new Uint8Array(100);
            var savedHex = load('fastpix_plays_' + video + '_' + lessonPost) || '';
            for (var ci = 0; ci + 1 < savedHex.length && ci / 2 < 100; ci += 2) {
                counts[ci / 2] = parseInt(savedHex.substr(ci, 2), 16) || 0;
            }
            state.lesson = { post: lessonPost, counts: counts, lastSlot: -1,
                             learner: el.getAttribute('viewer-key') || '',
                             at: Math.max(10, Math.min(100, parseInt(el.getAttribute('complete-at'), 10) || 90)) };
            state.lesson.ui = lessonUi(el, state.lesson);
            state.lesson.ui.update();
        }

        // Resume where they stopped (REQ-064): the server's furthest point,
        // applied before playback starts — skip when nearly finished, or when
        // the embed turned resume off.
        var q = state.viewer ? '?viewer_key=' + state.viewer : '';
        var headers = cfg.nonce ? { 'X-WP-Nonce': cfg.nonce } : {};
        var wantResume = el.getAttribute('data-fp-resume') !== '0';
        if (wantResume || lessonEmbed) {   // lesson embeds still read state to release the gate
            fetch(progressUrl(video) + q, { credentials: 'same-origin', headers: headers })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (p) {
                    if (!p) { return; }
                    if (lessonEmbed && p.completed) { releaseGate(); }   // already finished on a prior visit
                    if (!wantResume || !p.furthest_seconds) { return; }
                    var at = p.furthest_seconds;
                    if (at > 10 && at < duration - 5) {
                        // QA F7 (2026-09-20): a seek before the source is attached is thrown away
                        // when the player loads its manifest — that was the "intermittent" resume.
                        // Seek once metadata is there; before boot, start-time does the same job.
                        var seekIn = function (v) {
                            var seek = function () { if (!state.resumed && v.currentTime < at) { state.resumed = true; try { v.currentTime = at; } catch (e) {} } };
                            if (v.readyState >= 1) { seek(); } else { v.addEventListener('loadedmetadata', seek, { once: true }); }
                        };
                        var v = innerVideo(el);
                        if (v) { seekIn(v); return; }
                        // Not booted yet: start-time covers a boot that reads attributes; an element
                        // that already upgraded ignores it, so keep looking for the inner video. [QA L7]
                        el.setAttribute('start-time', String(at));
                        var tries = 0;
                        var find = function () { var v2 = innerVideo(el); if (v2) { seekIn(v2); } else if (tries++ < 50) { setTimeout(find, 100); } };
                        setTimeout(find, 100);
                    }
                }).catch(function () {});
        }

        // Poll once a second: the inner video's clock is the truth. Genuine
        // playback advances by ≈ playbackRate × 1 s per tick — anything far
        // beyond that is a seek and credits nothing. Within a genuine tick,
        // EVERY second and EVERY slot the playhead crossed is credited, so 2×
        // watching and short videos (slots under 1 s wide) count correctly.
        var last = null;
        var sinceBeat = 0;
        setInterval(function () {
            var v = innerVideo(el);
            if (!v) { return; }
            // Ended: report now, not at the next 16 s beat or tab close — a short
            // lesson video otherwise stops short of complete-at until the page is left.
            // Sent as the final beat so the 15 s cadence cannot 429 it.
            if (v.ended) {
                if (!state.endSent) { state.endSent = true; send(state, true); }
                return;
            }
            state.endSent = false;
            if (v.paused || v.seeking) { return; }
            var now = v.currentTime;
            var rate = Math.max(0.25, Math.min(16, v.playbackRate || 1));
            if (last !== null && now > last && now - last < rate + 1.0) {
                for (var sec = Math.floor(last); sec < now && sec < duration; sec++) {
                    if (addSecond(state.ranges, sec)) { state.dirty = true; }
                }
                if (state.lesson) {
                    // Credit every slot crossed this tick — a seek never reaches
                    // this branch, so anti-skip holds. A slot's count rises once
                    // per PASS (entering it), so replays register as rewatching
                    // without a long slot inflating its own count.
                    var from = Math.min(99, Math.floor(last / duration * 100));
                    var to   = Math.min(99, Math.floor(now / duration * 100));
                    for (var slot = from; slot <= to; slot++) {
                        if (slot !== state.lesson.lastSlot && state.lesson.counts[slot] < 250) {
                            state.lesson.counts[slot]++;
                            state.dirty = true;
                        }
                    }
                    state.lesson.lastSlot = to;
                    state.lesson.ui.update();
                }
            }
            if (now > state.position) { state.position = Math.min(now, duration); state.dirty = true; }
            last = now;
            sinceBeat++;
            // First beat after ~3 s so short videos register quickly; then the
            // 15 s cadence (still at most one write per 15 s window — RULE-032).
            if (sinceBeat >= BEAT_SECONDS || (!state.sentOnce && state.dirty && sinceBeat >= 3)) {
                sinceBeat = 0;
                state.sentOnce = true;
                send(state, false);
            }
        }, 1000);

        // The last beat must not be lost when the tab closes.
        window.addEventListener('pagehide', function () { send(state, true); });
        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'hidden') { send(state, true); }
        });
    }

    /* -------------------------------------------------- live state switch */
    /* RULE-034: one embed, three states. The server renders whichever state
       the stream is in; this poll reloads the page once when the stored state
       moves on (waiting → live → recording), so nobody edits the post. A
       sessionStorage key per (stream, state) stops reload loops when a cached
       page or a missing recording row re-renders the same card. */

    function liveWatch(fig) {
        var url = fig.getAttribute('data-fp-state-url');
        var rendered = fig.getAttribute('data-fp-live-state') || '';
        var streamId = fig.getAttribute('data-fp-stream') || fig.getAttribute('data-fp-recording-of');   // a recording embed watches its stream too [QA X5]
        if (!url) { return; }
        var timer = window.setInterval(function () {
            fetch(url, { cache: 'no-store', credentials: 'same-origin' })
                .then(function (r) { if (r.status === 404) { window.clearInterval(timer); return null; } return r.ok ? r.json() : null; })   // the stream is gone: stop asking
                .then(function (j) {
                    if (!j || !j.status) { return; }
                    // Reload when the status moved, or when a recording landed
                    // while a waiting/ended card is showing. A recording that
                    // predates a re-used stream never interrupts a broadcast.
                    var recDue = j.recording && (rendered === 'ended' || rendered === 'idle');
                    if (rendered === 'recording' && j.status !== 'active' && j.status !== 'preparing') { return; }   // the ended+recording answer IS what is showing [QA X5]
                    if (j.status === rendered && !recDue) { return; }
                    // Keyed on the TRANSITION (rendered → new), so a stream that goes live again
                    // in the same tab (idle → active a second time) reloads again. [QA X4]
                    // One key per stream holding the LAST transition: the same transition twice in a row
                    // (a cached page re-rendering the old state) is a loop and is not reloaded; every
                    // new transition — including a second broadcast, and its end — is. [QA X4, review]
                    var guard = 'fp-live-' + streamId, val = rendered + '>' + j.status + (recDue ? '+rec' : '');
                    try {
                        if (window.sessionStorage.getItem(guard) === val) { return; }
                        window.sessionStorage.setItem(guard, val);
                    } catch (err) {}
                    window.location.reload();
                })
                .catch(function () {});
        }, 15000);
    }

    /* ---------------------------------------------------------------- run */

    /* Chapter marks: the server embeds them as a JSON script beside the player
       (this player build won't auto-draw them). Hand them to addChapters() once
       the custom element is upgraded. */
    function chapters(script) {
        var fig = script.closest('.fastpix-embed');
        var player = fig && fig.querySelector('fastpix-player');
        if (!player) { return; }
        var marks;
        try { marks = JSON.parse(script.textContent); } catch (e) { return; }
        if (!marks || !marks.length) { return; }
        var apply = function () {
            if (typeof player.addChapters === 'function') {
                try { player.addChapters(marks); } catch (e) {}
            }
        };
        if (window.customElements && customElements.whenDefined) {
            customElements.whenDefined('fastpix-player').then(apply);
        } else {
            apply();
        }
    }

    /* The vendored player 1.0.21 ignores two of its own attributes (QA F8/F10, 2026-09-20):
       `disable-video-click` is stored and never read, and `auto-play` un-mutes the video
       before calling play(), which every browser then refuses. Both are fixed on the inner
       <video> once the element has booted. */
    function fixups(el) {
        var noClick = el.hasAttribute('disable-video-click'), auto = el.hasAttribute('auto-play');
        if (!noClick && !auto) { return; }
        var tries = 0;
        var arm = function () {
            var v = innerVideo(el);
            if (!v) { if (tries++ < 50) { setTimeout(arm, 100); } return; }
            if (noClick) {
                // Capture on the target runs before the player's own bubble listener.
                v.addEventListener('click', function (e) { e.stopImmediatePropagation(); e.preventDefault(); }, true);
            }
            if (auto) {
                var nudge = function () {
                    setTimeout(function () {
                        if (!v.paused) { return; }
                        v.muted = true;   // the only autoplay browsers allow without a gesture
                        var p = v.play(); if (p && p.catch) { p.catch(function () {}); }
                    }, 300);
                };
                if (v.readyState >= 1) { nudge(); } else { v.addEventListener('loadedmetadata', nudge, { once: true }); }   // once: a viewer's own pause is never undone
            }
        };
        if (window.customElements && customElements.whenDefined) { customElements.whenDefined('fastpix-player').then(arm); } else { arm(); }
    }

    function run() {
        document.querySelectorAll('fastpix-player').forEach(fixups);
        document.querySelectorAll('fastpix-player[data-fp-config]').forEach(refresh);
        if (cfg.progress) {
            document.querySelectorAll('fastpix-player[data-fp-video]').forEach(track);
        }
        document.querySelectorAll('.fastpix-embed[data-fp-stream], .fastpix-embed[data-fp-recording-of]').forEach(liveWatch);
        document.querySelectorAll('script.fastpix-chapters').forEach(chapters);
    }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', run); } else { run(); }
})();
