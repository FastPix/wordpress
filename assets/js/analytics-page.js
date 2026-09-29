/**
 * UI-005 — Analytics screen. Figures come from GET /analytics/site and
 * GET /videos/{id}/analytics, which read the local rollup only; every render
 * states the stored age, marks the last seven days provisional, and keeps
 * working (dimmed, with a stale notice) when FastPix is unreachable (REQ-063).
 */
(function () {
    'use strict';

    if (typeof fastpixAnalytics === 'undefined') { return; }
    var cfg = fastpixAnalytics;
    var i18n = cfg.i18n || {};
    // Translatable string with an optional %s / %d placeholder filled from `a`.
    // Named tr() (not t()) — `t` is used locally for the totals object.
    function tr(key, a) {
        var s = i18n[key] || key;
        return a === undefined ? s : s.replace('%s', a).replace('%d', a);
    }

    var el = function (id) { return document.getElementById(id); };
    // Sub-day ranges (dashboard-matching) come from the site-wide hourly
    // cache (per device too) — available on the site tab only, for full-scope views.
    var HOURS = { '60m': 1, '6h': 6, '24h': 24 };
    var state = {
        // wp_localize_script hands scalars over as STRINGS: "0" is truthy, so "no video" asked for
        // /videos/0/analytics and showed "Could not load figures". (QA 2026-09-21)
        tab: parseInt(cfg.video, 10) > 0 ? 'video' : 'site',
        video: parseInt(cfg.video, 10) || 0,
        range: '24h', from: '', to: '',
        device: '',
        mode: 'views',       // over-time pill
        covMode: 'still',    // coverage pills
        pinned: -1,
        data: null
    };

    function subDayAllowed() { return state.tab === 'site' && !cfg.restricted; }
    function syncRangeAvailability() {
        var ok = subDayAllowed();
        document.querySelectorAll('#fp-an-range .fp-an-subday').forEach(function (o) { o.disabled = !ok; });
        if (!ok && HOURS[state.range]) {
            state.range = '7';
            el('fp-an-range').value = '7';
        }
        if (el('fp-an-range').fpDD) { el('fp-an-range').fpDD(); }
        if (el('fp-an-device').fpDD) { el('fp-an-device').fpDD(); }
    }

    /* A native <select> pops an OS menu that ignores the page's styling — this
       swaps in a button whose menu drops down from the control itself. The
       select stays as the source of truth; picking an item sets its value and
       fires its change event, so every existing listener keeps working. */
    function dropdown(sel) {
        var wrap = document.createElement('span');
        wrap.className = 'fp-dd';
        sel.parentNode.insertBefore(wrap, sel);
        wrap.appendChild(sel);
        sel.classList.add('fp-dd-native');
        sel.tabIndex = -1;
        sel.setAttribute('aria-hidden', 'true');

        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'fp-dd-btn';
        btn.setAttribute('aria-haspopup', 'listbox');
        btn.setAttribute('aria-expanded', 'false');
        wrap.appendChild(btn);

        var menu = null;
        function label() {
            var o = sel.options[sel.selectedIndex];
            var val = o ? o.textContent : '';
            btn.innerHTML = esc(val) + '<span class="fp-dd-caret" aria-hidden="true">▾</span>';
            // Accessible name = the field's own label plus the current value, so a
            // screen reader hears "Date range: 7 days", not just "7 days". [TEST-032]
            var name = sel.getAttribute('aria-label') || '';
            btn.setAttribute('aria-label', (name ? name + ': ' : '') + val);
        }
        function close() {
            if (menu) { menu.remove(); menu = null; btn.setAttribute('aria-expanded', 'false'); }
        }
        function open() {
            close();
            menu = document.createElement('div');
            menu.className = 'fp-dd-menu';
            menu.setAttribute('role', 'listbox');
            var items = [];
            Array.prototype.forEach.call(sel.options, function (o, i) {
                var it = document.createElement('button');
                it.type = 'button';
                it.className = 'fp-dd-item' + (i === sel.selectedIndex ? ' on' : '');
                it.setAttribute('role', 'option');
                it.setAttribute('aria-selected', i === sel.selectedIndex ? 'true' : 'false');
                it.textContent = o.textContent;
                it.disabled = o.disabled;
                it.addEventListener('click', function () {
                    sel.value = o.value;
                    label();
                    close();
                    btn.focus();
                    sel.dispatchEvent(new Event('change', { bubbles: true }));
                });
                menu.appendChild(it);
                if (!it.disabled) { items.push(it); }
            });
            wrap.appendChild(menu);
            btn.setAttribute('aria-expanded', 'true');
            requestAnimationFrame(function () { menu && menu.classList.add('open'); });

            // Keyboard: arrows move between options, Enter/Space picks (native
            // button click), Escape/Tab returns to the trigger. [TEST-032]
            var focusAt = function (idx) { if (items.length) { items[(idx + items.length) % items.length].focus(); } };
            menu.addEventListener('keydown', function (e) {
                var cur = items.indexOf(document.activeElement);
                if (e.key === 'ArrowDown') { e.preventDefault(); focusAt(cur + 1); }
                else if (e.key === 'ArrowUp') { e.preventDefault(); focusAt(cur - 1); }
                else if (e.key === 'Home') { e.preventDefault(); focusAt(0); }
                else if (e.key === 'End') { e.preventDefault(); focusAt(items.length - 1); }
                else if (e.key === 'Escape') { e.preventDefault(); close(); btn.focus(); }
                else if (e.key === 'Tab') { close(); }
            });
            // Land focus on the selected option (or the first) so the list is operable.
            var start = 0;
            for (var k = 0; k < items.length; k++) { if (items[k].classList.contains('on')) { start = k; break; } }
            requestAnimationFrame(function () { focusAt(start); });
        }
        btn.addEventListener('click', function (e) { e.stopPropagation(); menu ? close() : open(); });
        btn.addEventListener('keydown', function (e) { if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); if (!menu) { open(); } } });
        document.addEventListener('click', function (e) { if (!wrap.contains(e.target)) { close(); } });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { close(); } });

        sel.fpDD = label;   // callers refresh the button after setting the value directly
        label();
    }

    /* -------------------------------------------------------------- utils */

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function fmt(n) { return Number(n || 0).toLocaleString(); }
    function mmss(s) {
        s = Math.max(0, Math.round(s));
        var m = Math.floor(s / 60);
        return m + ':' + ('0' + (s % 60)).slice(-2);
    }
    function ago(epoch) {
        if (!epoch) { return ''; }
        var s = Math.max(0, Math.floor(Date.now() / 1000) - epoch);
        if (s < 90) { return tr('momentsAgo'); }
        if (s < 5400) { return tr('minutesAgo', Math.round(s / 60)); }
        if (s < 129600) { return tr('hoursAgo', Math.round(s / 3600)); }
        return tr('daysAgo', Math.round(s / 86400));
    }
    function dates() {
        if (state.range === 'custom' && state.from && state.to) { return { from: state.from, to: state.to }; }
        var days = HOURS[state.range] ? 2 : (parseInt(state.range, 10) || 30);   // sub-day: the errors table reads yesterday+today
        var to = new Date();
        var from = new Date(Date.now() - (days - 1) * 86400000);
        var iso = function (d) { return d.toISOString().slice(0, 10); };
        return { from: iso(from), to: iso(to) };
    }
    function api(path, opts) {
        opts = opts || {};
        opts.credentials = 'same-origin';
        opts.headers = opts.headers || {};
        opts.headers['X-WP-Nonce'] = cfg.nonce;
        return fetch(cfg.restUrl + path, opts).then(function (r) {
            return r.json().then(function (j) { if (!r.ok) { throw j; } return j; });
        });
    }

    /* --------------------------------------------------------------- load */

    function load() {
        el('fp-an-tab-video').hidden = !state.video;   // the tab exists only for a chosen video (from the library, or a lesson row here)
        // One video with none chosen: never a request (and never the site figures under this tab) —
        // the tab is reached from a video's row in the library. Range / device changes land here too.
        if (state.tab === 'video' && !state.video) {
            state.data = null;
            el('fp-an-loading').hidden = true;
            el('fp-an-content').hidden = true;
            el('fp-an-video-head').hidden = true;
            el('fp-an-notices').innerHTML = '';
            el('fp-an-empty').hidden = false;
            el('fp-an-empty').innerHTML = '<div class="fp-an-emptybox"><p>You arrive here from the library — open a video\'s row and choose <b>View analytics</b>.</p>' +
                '<p><a class="btn sec" href="' + esc(cfg.libraryUrl) + '">Open the library</a></p></div>';
            syncUrl();
            return;
        }
        el('fp-an-loading').hidden = false;
        el('fp-an-content').hidden = true;
        el('fp-an-empty').hidden = true;
        syncRangeAvailability();
        var d = dates();
        var qs = '?from=' + d.from + '&to=' + d.to + (state.device ? '&device=' + encodeURIComponent(state.device) : '');
        if (HOURS[state.range] && subDayAllowed()) { qs += '&hours=' + HOURS[state.range]; }
        var path = state.tab === 'video' && state.video
            ? '/videos/' + state.video + '/analytics' + qs
            : '/analytics/site' + qs;
        api(path).then(function (data) {
            state.data = data;
            render();
        }).catch(function () {
            state.data = null;
            el('fp-an-loading').hidden = true;
            el('fp-an-empty').hidden = false;
            el('fp-an-empty').innerHTML = '<div class="fp-an-emptybox"><p>Could not load figures. Try reloading the page.</p></div>';
        });
        syncUrl();
    }

    function syncUrl() {
        var q = '&range=' + state.range +
            (state.range === 'custom' ? '&from=' + state.from + '&to=' + state.to : '') +
            (state.device ? '&device=' + encodeURIComponent(state.device) : '') +
            (state.tab === 'video' && state.video ? '&video=' + state.video : '');
        history.replaceState(null, '', cfg.pageUrl + q);
    }

    /* ------------------------------------------------------------- render */

    function render() {
        var d = state.data;
        el('fp-an-loading').hidden = true;
        el('fp-an-subtitle').textContent = tr('acrossSite');   // frame 9393:110343 keeps the site subtitle on every tab
        document.querySelector('.fp-analytics').dataset.tab = state.tab;
        el('fp-an-age').textContent = d.fetched_at ? 'figures stored ' + ago(d.fetched_at) : '';

        notices(d);
        videoHead(d);

        var noData = !d.days.length && (!d.coverage || !d.coverage.viewers);
        if (d.pending_workspace) {   // the pair changed and its workspace is not learned yet (ASSUME-092)
            el('fp-an-content').hidden = true;
            el('fp-an-empty').hidden = false;
            el('fp-an-empty').innerHTML = '<div class="fp-an-emptybox"><div class="fp-an-emoji" aria-hidden="true">📈</div>' +
                '<p><b>Confirming the connected workspace</b> — figures return after the next sync, once the first video of the connected workspace is seen.</p></div>';
            el('fp-an-export-btn').disabled = true;   // the stored rollup may be the previous workspace's
            return;
        }
        el('fp-an-export-btn').disabled = false;
        if (noData && !state.device && state.range !== 'custom') {
            el('fp-an-content').hidden = true;
            el('fp-an-empty').hidden = false;
            el('fp-an-empty').innerHTML = '<div class="fp-an-emptybox"><div class="fp-an-emoji" aria-hidden="true">📈</div>' +
                '<p><b>Nothing has been watched yet</b> — numbers appear here within about an hour of the first play.' +
                (d.ready_videos ? ' You have ' + fmt(d.ready_videos) + ' videos ready' + (d.unused_videos ? ' and ' + fmt(d.unused_videos) + ' of them are not on a post yet — that is usually the reason.' : '.') : '') + '</p>' +
                (d.unused_videos ? '<p><a class="btn sec" href="' + esc(cfg.libraryUrl) + '">See which videos are unused</a></p>' : '') + '</div>';
            return;
        }

        el('fp-an-empty').hidden = true;
        var c = el('fp-an-content');
        c.hidden = false;

        var html = kpis(d);
        html += qoeCard(d);
        html += '<div class="fp-an-two' + (state.tab === 'video' ? ' video' : '') + '">';
        if (state.tab === 'video' && d.coverage) {
            html += coverageCard(d) + errorsCard(d);
        } else {
            html += overTimeCard(d) + errorsCard(d);
        }
        html += '</div>';
        c.innerHTML = html;
        wire(c, d);
    }

    function notices(d) {
        var out = '';
        // No "FastPix has not responded" notice: these screens always read the local roll-up, the figures are
        // real, and the footer already stamps how old they are. (owner 2026-09-22)
        // No "Filtered" banner: the filter bar already shows the scope (owner 2026-09-09, frame 9393:109921).
        if (d.restricted) {
            out += '<div class="fpnotice info"><span class="ni" aria-hidden="true">ℹ</span><div class="nb">' +
                '<p>You are seeing only your own videos — site-wide totals are available to editors and administrators. Everything below covers the ' +
                fmt(d.own_videos || 0) + ' videos you uploaded.</p></div></div>';
        }
        el('fp-an-notices').innerHTML = out;
        var retry = el('fp-an-retry');
        if (retry) { retry.addEventListener('click', load); }
    }

    function videoHead(d) {
        var head = el('fp-an-video-head');
        if (state.tab !== 'video' || !d.video) { head.hidden = true; return; }
        head.hidden = false;
        var v = d.video;
        var meta = [mmss(v.duration), v.access === 'public' ? tr('public') : tr('private'),
            (v.on_posts === 1 ? tr('onPost', 1) : tr('onPosts', fmt(v.on_posts))),
            v.created_at ? tr('uploaded', new Date(v.created_at.replace(' ', 'T') + 'Z').toLocaleDateString([], { day: 'numeric', month: 'long', year: 'numeric' })) : ''];
        head.innerHTML = '<div class="fp-an-vhead">' +
            (v.playback_id ? '<img class="fp-an-thumb" alt="" src="' + esc(cfg.imageBase + '/' + v.playback_id + '/thumbnail.png?time=1&width=152') + '">' : '') +
            '<div><p class="fp-an-vtitle">' + esc(v.title || tr('untitled')) + '</p>' +
            '<p class="fp-an-vmeta">' + esc(meta.filter(Boolean).join(' · ')) + '</p></div>' +
            '<a class="btn sec" href="' + esc(cfg.libraryUrl + '&open=' + v.id) + '">Open in library</a></div>';
    }

    /* The rollup knows uniqueness per day (or per hour) only, so over a range the
       "people" figure is a SUM of per-period uniques — the tile says so unless
       the range is a single day, where the sum is the distinct count. */
    function peopleLabel(d) {
        if (d.granularity === 'hour') { return i18n.hourlyViewersSummed || 'Viewers (hourly, summed)'; }
        var span = dates();
        return span.from === span.to ? tr('people') : (i18n.dailyViewersSummed || 'Viewers (daily, summed)');
    }

    function kpis(d) {
        var t = d.totals;
        var tiles;
        if (state.tab === 'video' && d.coverage) {
            var avg = t.views ? t.watch_seconds / t.views : 0;
            var dur = d.video ? d.video.duration : 0;
            var fin = d.coverage.viewers ? Math.round(100 * d.coverage.finished / d.coverage.viewers) : 0;
            tiles = [
                [tr('views'), fmt(t.views)],
                [peopleLabel(d), d.people_known ? fmt(t.people) : '—'],
                [tr('avgWatch'), mmss(avg) + (dur ? ' <span class="fp-an-of sm">of ' + mmss(dur) + '</span>' : '')],
                [tr('finished'), fin + ' <span class="fp-an-of">%</span>']
            ];
        } else {
            var hours = Math.round(t.watch_seconds / 3600);
            tiles = [
                [tr('views'), fmt(t.views)],
                [peopleLabel(d), d.people_known ? fmt(t.people) : '—'],
                [tr('watchTime'), fmt(hours) + ' <span class="fp-an-of">hours</span>'],
                [tr('avgWatch'), mmss(t.views ? t.watch_seconds / t.views : 0)]
            ];
        }
        return '<div class="fp-an-kpis">' + tiles.map(function (x) {
            return '<div class="fp-an-tile"><p class="fp-an-klab">' + x[0] + '</p><p class="fp-an-kval">' + x[1] + '</p></div>';
        }).join('') + '</div>';
    }

    /* Quality of Experience card (UI-005 §2) — figures scored by FastPix,
       stored in the local rollup, views-weighted over the range. */
    function qoeCard(d) {
        var q = d.qoe;
        // No measured views yet: the card still renders, with an empty graph.
        var blank = !q || q.qoe_score == null;
        if (blank) {
            // Everything reads 0 — a uniform empty graph, not dash placeholders.
            q = { score_playback: 0, score_startup: 0, score_stability: 0, score_render: 0,
                  playback_failure_pct: 0, startup_failure_pct: 0, buffer_ratio: 0, avg_bitrate: 0, startup_ms_p50: 0 };
        }
        var score = blank ? 0 : Math.round(q.qoe_score * 100);
        var toneOf = function (v) { return v >= 85 ? 'good' : (v >= 60 ? 'warn' : 'bad'); };
        var tone = blank ? '' : toneOf(score);
        var scores = [
            [tr('playbackSuccess'), q.score_playback],
            [tr('startupTime'), q.score_startup],
            [tr('stability'), q.score_stability],
            [tr('renderQuality'), q.score_render]
        ];
        var weakest = null;
        scores.forEach(function (s) {
            if (s[1] != null && (!weakest || s[1] < weakest[1])) { weakest = s; }
        });
        var verdict = blank
            ? tr('qoeEmpty')
            : (score >= 85
                ? tr('qoeGood')
                : (score >= 60
                    ? tr('qoeWarn') + (weakest ? i18n.qoeWeakest.replace('%1$s', weakest[0]).replace('%2$s', Math.round(weakest[1] * 100)) : '')
                    : tr('qoeBad')));
        var barRows = scores.map(function (s) {
            var v = s[1] == null ? null : Math.round(s[1] * 100);
            return '<div class="fp-an-qsrow">' +
                '<div class="fp-an-qshead"><span>' + s[0] + '</span><span class="fp-an-qval">' + (v == null ? '—' : v) + '</span></div>' +
                '<div class="fp-an-qbar ' + (v == null ? '' : toneOf(v)) + '"><span style="width:' + (v || 0) + '%"></span></div></div>';
        }).join('');
        var pct = function (v) { return v == null ? '—' : (v * 100).toFixed(2) + '%'; };
        var startup = q.startup_ms_p50 == null ? '—'
            : (q.startup_ms_p50 >= 1000 ? (q.startup_ms_p50 / 1000).toFixed(2) + 's' : Math.round(q.startup_ms_p50) + 'ms');
        var metrics = [
            [tr('playbackFailurePct'), pct(q.playback_failure_pct)],
            [tr('videoStartupFailurePct'), pct(q.startup_failure_pct)],
            [tr('bufferRatio'), pct(q.buffer_ratio)],
            [tr('videoStartupTime'), startup],
            [tr('avgBitrate'), q.avg_bitrate == null ? '—' : (q.avg_bitrate / 1000000).toFixed(2) + ' Mbps']
        ].map(function (m) {
            return '<div class="fp-an-qmrow"><span>' + m[0] + '</span><span class="fp-an-qval">' + m[1] + '</span></div>';
        }).join('');
        return '<div class="fp-an-card fp-an-qoe"><div class="fp-an-chd"><h2>Quality of Experience</h2>' +
            '<span class="fp-an-sub">scored by FastPix from every measured view</span></div>' +
            '<div class="fp-an-qhead">' +
            '<p class="fp-an-qscore ' + tone + '">' + score + '<span>/ 100</span></p>' +
            '<p class="fp-an-qverdict">' + verdict + '</p></div>' +
            '<div class="fp-an-qgrid">' +
            '<div><p class="fp-an-qcol">Scores</p>' + barRows + '</div>' +
            '<div class="fp-an-qsplit"><p class="fp-an-qcol">Key Metrics</p>' + metrics + '</div></div></div>';
    }

    /* Over time — area line chart of the per-day (or per-hour) series. */
    function overTimeCard(d) {
        var key = state.mode === 'people' ? 'people' : (state.mode === 'watch' ? 'watch_seconds' : 'views');
        var hourMode = d.granularity === 'hour';
        var series = [];
        if (hourMode) {
            series = d.days.map(function (r) { return { day: r.day, v: Number(r[key]) || 0 }; });
        } else {
            var span = dates();
            var byDay = {};
            d.days.forEach(function (r) { byDay[r.day] = r; });
            for (var t = new Date(span.from + 'T00:00:00Z').getTime(); t <= new Date(span.to + 'T00:00:00Z').getTime(); t += 86400000) {
                var day = new Date(t).toISOString().slice(0, 10);
                series.push({ day: day, v: byDay[day] ? Number(byDay[day][key]) || 0 : 0 });
            }
        }
        var max = Math.max(1, Math.max.apply(null, series.map(function (p) { return p.v; })));
        var W = 560, H = 188, TOP = 20, n = Math.max(1, series.length - 1);   // frame 9393:109921: gridlines every 42px, baseline 22px above the plot's end
        var y = function (v) { return (H - v / max * (H - TOP)).toFixed(1); };
        var line = series.map(function (p, i) { return (i * W / n).toFixed(1) + ',' + y(p.v); }).join(' ');
        // Five dashed gridlines at quarter steps; labels on max · half · 0 only (frame 9393:109921).
        var grid = '', ylabels = '';
        for (var g = 4; g >= 0; g--) {
            var gv = max * g / 4;
            grid += '<line x1="0" y1="' + y(gv) + '" x2="' + W + '" y2="' + y(gv) + '" class="fp-an-grid"></line>';
            if (g % 2 === 0) { ylabels += '<span>' + fmt(Math.round(gv)) + '</span>'; }
        }
        var pills = [['views', tr('views')], ['people', tr('people')], ['watch', tr('watchTime')]].map(function (p) {
            return '<button type="button" class="fp-an-pill' + (state.mode === p[0] ? ' on' : '') + '" data-mode="' + p[0] + '">' + p[1] + '</button>';
        }).join('');
        var xlab = function (p) {
            if (hourMode) { return p.day.slice(11, 16); }
            return new Date(p.day + 'T00:00:00Z').toLocaleDateString('en-GB', { day: 'numeric', month: 'short', timeZone: 'UTC' });
        };
        var labels = series.length
            ? '<span>' + xlab(series[0]) + '</span><span>' + xlab(series[series.length - 1]) + '</span>'
            : '';
        var note = hourMode
            ? ''
            : '';   // the frame carries no provisional-days note
        return '<div class="fp-an-card"><div class="fp-an-chd"><h2>Over time</h2><div class="fp-an-pills" id="fp-an-mode">' + pills + '</div></div>' +
            '<div class="fp-an-chartwrap"><div class="fp-an-yaxis">' + ylabels + '</div>' +
            '<div class="fp-an-chartcol"><svg viewBox="0 0 ' + W + ' ' + H + '" class="fp-an-chart" preserveAspectRatio="none" role="img" aria-label="' + esc(tr('trendOverTime')) + '">' +
            grid +
            '<polygon points="0,' + H + ' ' + line + ' ' + W + ',' + H + '" class="fp-an-area"></polygon>' +
            '<polyline points="' + line + '" class="fp-an-line"></polyline></svg>' +
            '<div class="fp-an-x">' + labels + '</div></div></div>' + note + '</div>';
    }

    function errorsCard(d) {
        // Sub-day ranges: the errors stay at day grain, so the % is taken
        // against THAT window's views (errors_views), not the hourly total.
        var total = Math.max(1, d.errors_views != null ? d.errors_views : d.totals.views);
        var ago2 = function (day) {
            var diff = Math.round((Date.now() - new Date(day + 'T00:00:00Z').getTime()) / 86400000);
            return diff <= 0 ? tr('today') : (diff === 1 ? tr('yesterday') : tr('daysAgo', diff));
        };
        // The rollup counts views per error; uniqueness per error is not stored,
        // so the count column is views, and says so.
        var rows = d.errors.map(function (e) {
            return '<tr><td><span class="fp-an-errchip">' + esc(e.code) + '</span></td>' +
                '<td>' + (100 * e.views / total).toFixed(2) + '%</td>' +
                '<td>' + fmt(e.views) + '</td>' +
                '<td class="fp-an-dim">' + ago2(e.last_seen) + '</td></tr>';
        }).join('');
        var body = d.errors.length
            ? '<p class="fp-an-cint">Every one of these is counted in <b>Playback Failure Percentage</b>. Ranked by how many views hit it.</p>' +
              '<table class="fp-an-errs"><thead><tr><th>Error</th><th>% of views</th><th>Views</th><th>Last seen</th></tr></thead><tbody>' + rows + '</tbody></table>'
            : '<p class="fp-an-zero">No playback errors — every view in this range reached the first frame.</p>';
        return '<div class="fp-an-card"><div class="fp-an-chd"><h2>Errors</h2><span class="fp-an-sub">' + esc(d.errors_window || 'same date range') + '</span></div><div class="fp-an-cbody">' + body + '</div></div>';
    }

    /* Watch-coverage card (unique to per-video) — computed in the plugin from
       watch_progress; FastPix never sees it (RULE-030). */
    function coverageCard(d) {
        var cov = d.coverage;
        var total = cov.viewers;
        var dur = d.video.duration;
        // With no measured viewers the graph still renders empty — the readout
        // says what is missing.
        var bars = '';
        for (var i = 0; i < 10; i++) {
            var dec = cov.deciles[i];
            var still = total ? Math.round(100 * dec.viewers / total) : 0;
            var prev = i === 0 ? total : cov.deciles[i - 1].viewers;
            var drop = total ? Math.round(100 * (prev - dec.viewers) / total) : 0;
            var val = state.covMode === 'still' ? still : drop;
            var label = state.covMode === 'still' ? still + '%' : '−' + drop + '%';
            bars += '<button type="button" class="fp-an-dec' + (still < 50 ? ' past' : '') + (state.pinned === i ? ' pinned' : '') + '" data-i="' + i + '"' +
                ' aria-label="' + mmss(dec.start) + ' to ' + mmss(dec.end) + ': ' + label + '"' +
                ' title="' + (state.pinned === i ? tr('pinnedRelease') : '') + '">' +
                (total ? '<span class="fp-an-decv">' + label + '</span>' : '') +   // no 0% clutter on an empty graph
                '<span class="fp-an-decbar" style="height:' + Math.max(2, (state.covMode === 'still' ? still : Math.min(100, drop))) + '%"></span>' +
                '<span class="fp-an-decx">' + mmss(dec.start) + '</span></button>';
        }
        var readout;
        if (!total || state.pinned < 0) {   // the frame shows the readout only for a pinned bar
            readout = '';   // nothing measured yet — the empty chart says it already
        } else {
            var i0 = state.pinned >= 0 ? state.pinned : 9;
            var dec0 = cov.deciles[i0];
            var still0 = Math.round(100 * dec0.viewers / total);
            var prev0 = i0 === 0 ? total : cov.deciles[i0 - 1].viewers;
            readout = state.covMode === 'still'
                ? '<p class="fp-an-rbig">' + still0 + '%</p><p>' + mmss(dec0.start) + ' – ' + mmss(dec0.end) + '</p><p>' + fmt(dec0.viewers) + ' of ' + fmt(total) + ' viewers are still watching at this point.</p>'
                : '<p class="fp-an-rbig">−' + Math.round(100 * (prev0 - dec0.viewers) / total) + '%</p><p>' + mmss(dec0.start) + ' – ' + mmss(dec0.end) + '</p><p>' + fmt(prev0 - dec0.viewers) + ' viewers stopped during this stretch.</p>';
        }
        var thr = Math.round((cfg.threshold || 0.9) * 100);
        return '<div class="fp-an-card"><div class="fp-an-chd"><h2>How far do people get?</h2>' +
            '<div class="fp-an-pills" id="fp-an-covmode">' +
            '<button type="button" class="fp-an-pill' + (state.covMode === 'still' ? ' on' : '') + '" data-cov="still">Still watching</button>' +
            '<button type="button" class="fp-an-pill' + (state.covMode === 'drop' ? ' on' : '') + '" data-cov="drop">Dropped off here</button></div></div>' +
            '<p class="fp-an-thrnote">' + thr + '% — a viewer counts as finished</p>' +
            '<div class="fp-an-decs">' + bars + '</div>' +
            (readout ? '<div class="fp-an-readout">' + readout + '</div>' : '') +
            '<p class="fp-an-note">Counts <b>distinct seconds watched</b> per viewer, so rewatching cannot inflate it. Calculated in the plugin — FastPix never sees it.</p></div>';
    }

    function wire(c, d) {
        var mode = el('fp-an-mode');
        if (mode) {
            mode.addEventListener('click', function (e) {
                var b = e.target.closest('[data-mode]');
                if (b) { state.mode = b.getAttribute('data-mode'); render(); }
            });
        }
        var covmode = el('fp-an-covmode');
        if (covmode) {
            covmode.addEventListener('click', function (e) {
                var b = e.target.closest('[data-cov]');
                if (b) { state.covMode = b.getAttribute('data-cov'); render(); }
            });
        }
        c.querySelectorAll('.fp-an-dec').forEach(function (b) {
            b.addEventListener('click', function () {
                var i = parseInt(b.getAttribute('data-i'), 10);
                state.pinned = state.pinned === i ? -1 : i;
                render();
            });
        });
    }

    /* ------------------------------------------------------------- export */

    var exportPoll = null;
    var exportReturnFocus = null;

    function exportFocusables() {
        return el('fp-an-export').querySelectorAll('.fp-an-dialog select, .fp-an-dialog input, .fp-an-dialog button, .fp-an-dialog a[href]');
    }
    function openExport() {
        el('fp-an-export').hidden = false;
        el('fp-an-export-status').innerHTML = '';
        refreshExports();
        // Move focus into the dialog and remember where to send it back. [TEST-032]
        exportReturnFocus = document.activeElement;
        var first = exportFocusables()[0];
        if (first) { first.focus(); }
    }
    function closeExport() {
        el('fp-an-export').hidden = true;
        clearTimeout(exportPoll);
        if (exportReturnFocus && exportReturnFocus.focus) { exportReturnFocus.focus(); }
        exportReturnFocus = null;
    }
    function refreshExports() {
        api('/analytics/export').then(function (r) {
            var running = false;
            var rows = (r.exports || []).slice(0, 3).map(function (e) {
                if (e.state === 'running') { running = true; }
                var when = new Date(e.requested_at * 1000).toLocaleString();
                return '<p class="fp-an-exp">' + (e.state === 'ready'
                    ? '<a href="' + esc(cfg.restUrl + '/analytics/export/' + e.id + '/download?_wpnonce=' + cfg.nonce) + '">Download CSV</a> <span class="fp-an-sub">' + esc(when) + ' · <span class="mono">' + fmt(e.rows) + '</span> rows' + (e.rows ? '' : ' <span class="fp-an-dim">— empty range</span>') + '</span>'
                    : tr('preparing') + ' <span class="fp-an-sub">' + esc(when) + '</span>') + '</p>';
            }).join('');
            el('fp-an-export-status').innerHTML = rows ? '<div class="fp-an-past"><p class="fp-an-qcol">Past exports</p>' + rows + '</div>' : '';
            if (running && !el('fp-an-export').hidden) {
                clearTimeout(exportPoll);
                exportPoll = setTimeout(refreshExports, 3000);
            }
        }).catch(function () {});
    }

    el('fp-an-export-btn').addEventListener('click', openExport);
    el('fp-an-export-cancel').addEventListener('click', closeExport);
    el('fp-an-export-x').addEventListener('click', closeExport);
    // Escape closes; a click on the backdrop (outside the dialog card) closes;
    // Tab cycles within the dialog so focus cannot escape behind it. [TEST-032]
    el('fp-an-export').addEventListener('mousedown', function (e) { if (e.target === el('fp-an-export')) { closeExport(); } });
    el('fp-an-export').addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { e.preventDefault(); closeExport(); return; }
        if (e.key !== 'Tab') { return; }
        var f = exportFocusables();
        if (!f.length) { return; }
        var first = f[0], last = f[f.length - 1];
        if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    });
    el('fp-an-export-start').addEventListener('click', function () {
        api('/analytics/export', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                range: el('fp-an-export-range').value,
                every_video: el('fp-an-export-every').checked,
                per_day: el('fp-an-export-days').checked,
                device: el('fp-an-export-device').checked,
                video: state.tab === 'video' ? state.video : 0
            })
        }).then(refreshExports).catch(function (err) {
            el('fp-an-export-status').innerHTML = '<p class="fp-an-exp err">' + esc((err && err.message) || 'Could not start the export.') + '</p>';
        });
    });

    /* -------------------------------------------------------------- wires */

    el('fp-an-tab-site').addEventListener('click', function () {
        state.tab = 'site';
        this.classList.add('on'); this.setAttribute('aria-selected', 'true');
        el('fp-an-tab-video').classList.remove('on'); el('fp-an-tab-video').setAttribute('aria-selected', 'false');
        load();
    });
    el('fp-an-tab-video').addEventListener('click', function () {
        state.tab = 'video';
        this.classList.add('on'); this.setAttribute('aria-selected', 'true');
        el('fp-an-tab-site').classList.remove('on'); el('fp-an-tab-site').setAttribute('aria-selected', 'false');
        load();   // with no video chosen, load() shows the "pick one in the library" prompt
    });
    /* Courses tab: course-wise ONLY — pick a course, see the students enrolled
       in it. No site-wide roll-up and no all-students table. */
    var coursesTab = el('fp-an-tab-courses');
    var courseList = [];
    var courseSel = 0;

    function agoDate(mysql) {
        if (!mysql) { return 'never'; }
        var t = new Date(mysql.replace(' ', 'T') + 'Z').getTime();
        var days = Math.floor((Date.now() - t) / 86400000);
        if (days <= 0) { return tr('today'); }
        if (days === 1) { return tr('yesterday'); }
        if (days < 30) { return tr('daysAgo', days); }
        return Math.round(days / 30) + ' months ago';
    }

    var lmsFilter = 'all';   // roster filter chip

    /* Per-lesson detail: "watched 6:24 of 10:00", the 100-segment strip
       (watched / rewatched / skipped / not watched, hover for time range), axis, and fact chips.
       Skipped = an unplayed stretch BEFORE the furthest point watched (jumped over); after it
       is only "not watched" — a student who stopped at 0:23 skipped nothing. (QA) */
    function decodePlays(hex) {
        var counts = new Array(100);
        for (var i = 0; i < 100; i++) {
            counts[i] = hex && hex.length >= (i + 1) * 2 ? (parseInt(hex.substr(i * 2, 2), 16) || 0) : 0;
        }
        return counts;
    }

    function lessonDetailBlock(ls, lesson, li) {
        var title = lesson ? lesson.title : tr('lessonN', li + 1);
        if (ls && ls.other_workspace) {   // kept records of a lesson the connected pair cannot reach
            return '<div class="fp-an-lblock"><div class="fp-an-lbhead"><b>' + (li + 1) + ' · ' + esc(title) + '</b>' +
                '<span class="fp-an-lws">video in a previous workspace</span></div></div>';
        }
        var dur = lesson && lesson.duration ? Number(lesson.duration) : 0;
        var at = lesson && lesson.complete_at ? lesson.complete_at : 90;
        var counts = decodePlays(ls.plays || '');
        var watched = 0, rewatched = 0, gaps = 0, inGap = false, rwFrom = -1, rwTo = -1, rwMax = 0, reached = -1;
        counts.forEach(function (n, i) { if (n > 0) { reached = i; } });
        var skipped = 0;
        counts.forEach(function (n, i) {
            if (n === 0 && i < reached) { skipped++; }
            if (n > 0) { watched++; inGap = false; } else { if (!inGap) { gaps++; } inGap = true; }
            if (n > 1) { rewatched++; if (rwFrom < 0) { rwFrom = i; } rwTo = i; rwMax = Math.max(rwMax, n); }
        });
        // Old rows have only a percentage, no per-slot data — show what we know.
        var hasStrip = (ls.plays || '') !== '';
        var pct = hasStrip ? watched : ls.watched_pct;
        var per = dur / 100;
        var strip = '';
        if (hasStrip) {
            strip = '<div class="fp-an-strip">' + counts.map(function (n, i) {
                var cls = n > 1 ? ' class="r"' : (n > 0 ? ' class="w"' : (i < reached ? ' class="s"' : ''));
                var label = (n > 1 ? 'rewatched ' + n + '× ' : (n > 0 ? 'watched ' : (i < reached ? 'skipped ' : 'not watched '))) +
                    (dur ? mmss(i * per) + ' – ' + mmss((i + 1) * per) : '');
                return '<i' + cls + ' data-t="' + esc(label) + '"></i>';
            }).join('') + '</div>' +
            (dur ? '<div class="fp-an-saxis"><span>0:00</span><span>' + mmss(dur / 2) + '</span><span>' + mmss(dur) + '</span></div>' : '');
        }
        var facts = '';
        if (hasStrip) {
            // Times only when they mean something: sub-second amounts on short
            // videos round to 0:00 noise, so those chips show percent alone.
            var t = function (secs) { return dur && secs >= 1 ? ' (' + mmss(secs) + ')' : ''; };
            var rwSecs = rewatched * per;
            facts = '<div class="fp-an-sfacts">' +
                '<span class="fp-an-sfact"><i class="sw w"></i><b>' + pct + '%</b>&nbsp;watched' + t(pct * per) + '</span>' +
                (skipped > 0
                    ? '<span class="fp-an-sfact"><i class="sw s"></i><b>' + skipped + '%</b>&nbsp;skipped' + t(skipped * per) + '</span>'
                    : '') +
                ((100 - pct - skipped) > 0
                    ? '<span class="fp-an-sfact"><i class="sw n"></i><b>' + (100 - pct - skipped) + '%</b>&nbsp;not watched' + t((100 - pct - skipped) * per) + '</span>'
                    : '') +
                (rewatched > 0
                    ? '<span class="fp-an-sfact"><i class="sw r"></i><b>' + (rwSecs >= 1 ? mmss(rwSecs) : rewatched + '%') + '</b>&nbsp;rewatched' +
                      (dur && ((rwTo + 1) * per - rwFrom * per) >= 2
                          ? ' — ' + mmss(rwFrom * per) + ' to ' + mmss((rwTo + 1) * per) + ', ' + rwMax + ' plays'
                          : ', ' + rwMax + ' plays') + '</span>'
                    : '') + '</div>';
        }
        var status = ls.completed
            ? '<span class="fp-an-cchip good">✓ Completed</span>'
            : (pct > 0 ? '<span class="fp-an-dim">' + pct + '% · needs ' + at + '%</span> <span class="fp-an-cchip warn">' + Math.max(0, at - pct) + '% short</span>'
                       : '<span class="fp-an-dim">not started</span>');
        return '<div class="fp-an-lblock">' +
            '<div class="fp-an-lbhead"><b>' + (li + 1) + ' · ' + esc(title) + '</b>' +
            (dur || pct ? '<span class="fp-an-watchof">watched <b>' + (dur ? mmss(pct * per) : pct + '%') + '</b>' + (dur ? ' of ' + mmss(dur) : '') + '</span>' : '') +
            '<span class="fp-an-lbstatus">' + status + '</span></div>' +
            strip + facts + '</div>';
    }

    function renderCourseDetail(d) {
        el('fp-an-loading').hidden = true;
        var c = el('fp-an-content');
        c.hidden = false;

        var lessons = d.lessons || [];
        var students = d.students || [];
        var totals = d.totals || {};
        var breakdown = d.breakdown || [];
        var active = typeof totals.lessons === 'number' ? totals.lessons : lessons.length;   // lessons the connected workspace can reach

        /* Header bar: custom dropdown course picker + meta. */
        var head = '<div class="fp-an-coursebar">' +
            '<label for="fp-an-course-pick">Course</label>' +
            '<select id="fp-an-course-pick">' + courseList.map(function (co, i) {
                return '<option value="' + i + '"' + (i === courseSel ? ' selected' : '') + '>' + esc(co.title) + '</option>';
            }).join('') + '</select>' +
            '<span class="fp-an-coursemeta">' + fmt(lessons.length) + ' video lesson' + (lessons.length === 1 ? '' : 's') +
            (lessons.length - active > 0 ? ' · ' + fmt(lessons.length - active) + ' in a previous workspace' : '') +
            (d.tracking ? '' : ' · <b style="color:#a07800">per-student tracking off</b>') + '</span></div>';

        var pct = totals.enrolled ? Math.round(100 * totals.complete / totals.enrolled) : 0;
        var kpis = '<div class="fp-an-kpis">' + [
            [tr('enrolled'), fmt(totals.enrolled)],
            [tr('completed'), fmt(totals.complete) + (totals.enrolled ? ' <span class="fp-an-of">· ' + pct + '%</span>' : '')],
            [tr('avgProgress'), (totals.avg || 0) + '<span class="fp-an-of tight">%</span>'],
            [tr('notStarted'), fmt(totals.notstarted)]
        ].map(function (x) {
            return '<div class="fp-an-tile"><p class="fp-an-klab">' + x[0] + '</p><p class="fp-an-kval">' + x[1] + '</p></div>';
        }).join('') + '</div>';

        if (!totals.enrolled) {
            c.innerHTML = head + kpis + '<div class="fp-an-emptybox"><div class="fp-an-emoji">🎓</div>' +
                '<p><b>No students are enrolled in this course yet.</b><br>Progress appears here as soon as someone enrols and starts watching.</p></div>';
            wireCoursePick();
            return;
        }

        /* Lesson breakdown — drop lesson flagged, rows click through to coverage. */
        var maxDrop = -1, dropIdx = -1;
        var prevDone = totals.enrolled;
        breakdown.forEach(function (b, i) {
            if (b.other_workspace) { return; }   // not reachable with the connected pair: never "the lesson you lose them on"
            var drop = prevDone - b.completed;
            if (drop > maxDrop) { maxDrop = drop; dropIdx = i; }
            prevDone = b.completed;
        });
        var bars = breakdown.map(function (b, i) {
            if (b.other_workspace) {   // the video belongs to a previous workspace: labelled, not counted, not clickable
                return '<div class="fp-an-lrow foreign">' +
                    '<span class="fp-an-lnum">' + (i + 1) + '</span>' +
                    '<span class="fp-an-lname">' + esc(b.title || '(untitled)') + ' <span class="fp-an-lws">video in a previous workspace</span></span>' +
                    '<span class="fp-an-lbar"></span><span class="fp-an-lval">—</span><span></span></div>';
            }
            var donePct = totals.enrolled ? Math.round(100 * b.completed / totals.enrolled) : 0;
            var startedPct = totals.enrolled ? Math.round(100 * b.started / totals.enrolled) : 0;
            return '<button type="button" class="fp-an-lrow' + (i === dropIdx && maxDrop > 0 ? ' drop' : '') + '"' +
                (b.video_id ? ' data-video="' + b.video_id + '"' : '') +
                ' title="' + esc(tr('openCoverage')) + '">' +
                '<span class="fp-an-lnum">' + (i + 1) + '</span>' +
                '<span class="fp-an-lname">' + esc(b.title || '(untitled)') + '</span>' +
                '<span class="fp-an-lbar"><i class="started" style="width:' + startedPct + '%"></i><i class="done" style="width:' + donePct + '%"></i></span>' +
                '<span class="fp-an-lval">' + donePct + '%</span>' +
                '<span class="fp-an-lgo" aria-hidden="true">→</span></button>';
        }).join('');
        var breakdownCard = '<div class="fp-an-card fp-an-lcard"><div class="fp-an-chd"><h2>Lesson progress</h2>' +
            '<span class="fp-an-legend"><i class="done"></i> completed <i class="started"></i> started</span></div>' +
            '<div class="fp-an-lessons">' + bars + '</div>' +
            (dropIdx >= 0 && maxDrop > 0
                ? '<p class="fp-an-note"><b>Lesson ' + (dropIdx + 1) + ' is where you lose the most students</b> — ' +
                  fmt(maxDrop) + ' of them stop there. Click it to see exactly where in the video.</p>'
                : '') + '</div>';

        /* Student roster — filter chips + search + expandable rows. */
        var roster;
        if (!d.tracking) {
            roster = '<div class="fp-an-card fp-an-scard"><div class="fp-an-chd"><h2>' + esc(tr('students')) + '</h2></div>' +
                '<div class="fp-an-emptybox" style="border:0;padding:26px"><p>' + esc(tr('notRecorded')) + '<br>' +
                i18n.addTrackViewer + '</p></div></div>';
        } else {
            var counts = { all: students.length, progress: 0, complete: 0, notstarted: 0 };
            students.forEach(function (st) { counts[st.state]++; });
            var chipDef = [['all', tr('all')], ['progress', tr('inProgress')], ['complete', tr('completed')], ['notstarted', tr('notStarted')]];
            var chips = '<div class="fp-an-lchips">' + chipDef.map(function (cd) {
                return '<button type="button" class="fp-an-lchip' + (lmsFilter === cd[0] ? ' on' : '') + '" data-f="' + cd[0] + '">' +
                    cd[1] + ' <span>' + counts[cd[0]] + '</span></button>';
            }).join('') + '</div>';

            var stChip = function (st) {
                if (st.state === 'complete') { return '<span class="fp-an-cchip good">Completed</span>'; }
                if (st.state === 'notstarted') { return '<span class="fp-an-cchip">Not started</span>'; }
                return '<span class="fp-an-cchip warn">In progress</span>';
            };
            roster = '<div class="fp-an-card fp-an-scard"><div class="fp-an-chd"><h2>' + esc(tr('students')) + '</h2>' +
                '<span class="search"><input type="search" id="fp-an-student-q" placeholder="' + esc(tr('searchStudents')) + '" aria-label="' + esc(tr('searchStudents')) + '"></span></div>' +
                chips +
                '<div class="fp-an-scroll"><table class="fp-an-errs fp-an-roster"><thead><tr>' +
                '<th></th><th>Student</th><th>Progress</th><th>Lessons</th><th>Status</th><th class="fp-an-r">Last active</th>' +
                '</tr></thead><tbody>' + students.map(function (st, si) {
                    var detail = st.lessons.map(function (ls, li) {
                        return lessonDetailBlock(ls, lessons[li], li);
                    }).join('');
                    // st.name is present only for administrators (server-gated).
                    // An admin sees the display name as the primary label with the
                    // hash as the sub-line; lower roles see the hash and "hashed
                    // learner" — the stored data stays hashed either way.
                    var hasName = !!st.name;
                    var primary = hasName ? esc(st.name) : esc(st.viewer);
                    var sub = hasName ? esc(st.viewer) : tr('hashedLearner');
                    return '<tr class="fp-an-srow" data-i="' + si + '" data-state="' + st.state + '" data-name="' + esc(((st.viewer || '') + ' ' + (st.name || '')).toLowerCase()) + '" tabindex="0">' +
                        '<td class="fp-an-chev">▸</td>' +
                        '<td><span class="fp-an-student"><span class="fp-an-avatar">' + esc(st.avatar) + '</span>' +
                        '<span><b>' + primary + '</b><span class="fp-an-dim">' + sub + '</span></span></span></td>' +
                        '<td><span class="fp-an-cmeter"><span class="fp-an-ctrack ' + (st.progress === 100 ? 'good' : 'warn') + '">' +
                        '<span style="width:' + st.progress + '%"></span></span>' + st.progress + '%</span></td>' +
                        '<td>' + st.completed + ' <span class="fp-an-dim">/ ' + active + '</span></td>' +
                        '<td>' + stChip(st) + '</td>' +
                        '<td class="fp-an-r fp-an-dim">' + agoDate(st.last_active) + '</td></tr>' +
                        '<tr class="fp-an-sdetail" data-for="' + si + '" hidden><td></td><td colspan="5">' + detail + '</td></tr>';
                }).join('') + '</tbody></table></div>' +
                '<p class="fp-an-rosterfoot" id="fp-an-roster-none" hidden>No students match.</p></div>';
        }

        c.innerHTML = head + kpis + breakdownCard + roster;
        wireCoursePick();

        c.querySelectorAll('.fp-an-lrow[data-video]').forEach(function (r) {
            r.addEventListener('click', function () {
                state.video = parseInt(r.getAttribute('data-video'), 10);
                el('fp-an-fbar').hidden = false;
                el('fp-an-tab-video').click();
            });
        });

        /* Row expansion: click a student → their lesson-by-lesson detail. */
        c.querySelectorAll('.fp-an-srow').forEach(function (tr) {
            var openRow = function () {
                var i = tr.getAttribute('data-i');
                var dRow = c.querySelector('.fp-an-sdetail[data-for="' + i + '"]');
                var open = dRow.hidden;
                dRow.hidden = !open;
                tr.classList.toggle('open', open);
                tr.querySelector('.fp-an-chev').textContent = open ? '▾' : '▸';
            };
            tr.addEventListener('click', openRow);
            tr.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openRow(); } });
        });

        function applyRosterFilter() {
            var needle = (el('fp-an-student-q') ? el('fp-an-student-q').value : '').trim().toLowerCase();
            var visible = 0;
            c.querySelectorAll('.fp-an-srow').forEach(function (tr) {
                var show = (lmsFilter === 'all' || tr.getAttribute('data-state') === lmsFilter)
                    && (needle === '' || tr.getAttribute('data-name').indexOf(needle) !== -1);
                tr.hidden = !show;
                if (!show) {
                    var dRow = c.querySelector('.fp-an-sdetail[data-for="' + tr.getAttribute('data-i') + '"]');
                    if (dRow) { dRow.hidden = true; tr.classList.remove('open'); }
                }
                if (show) { visible++; }
            });
            var none = el('fp-an-roster-none');
            if (none) { none.hidden = visible !== 0; }
        }
        c.querySelectorAll('.fp-an-lchip').forEach(function (ch) {
            ch.addEventListener('click', function () {
                lmsFilter = ch.getAttribute('data-f');
                c.querySelectorAll('.fp-an-lchip').forEach(function (o) { o.classList.toggle('on', o === ch); });
                applyRosterFilter();
            });
        });
        var q = el('fp-an-student-q');
        if (q) { q.addEventListener('input', applyRosterFilter); }
        applyRosterFilter();
    }

    function wireCoursePick() {
        var pick = el('fp-an-course-pick');
        if (!pick) { return; }
        pick.addEventListener('change', function () {
            courseSel = parseInt(pick.value, 10) || 0;
            loadCourse();
        });
        dropdown(pick);   // same styled dropdown as the filter bar
    }

    function courseFail(err) {
        el('fp-an-loading').hidden = true;
        el('fp-an-content').hidden = true;
        el('fp-an-empty').hidden = false;
        var why = (err && (err.message || err.code)) ? String(err.message || err.code) : tr('unknownError');
        el('fp-an-empty').innerHTML = '<div class="fp-an-emptybox"><p><b>Could not load this course.</b><br>' +
            esc(why) + '</p><p><button type="button" class="btn sec" id="fp-an-course-retry">Try again</button></p></div>';
        var retry = el('fp-an-course-retry');
        if (retry) { retry.addEventListener('click', function () { coursesTab.click(); }); }
    }

    function loadCourse() {
        el('fp-an-loading').hidden = false;
        el('fp-an-content').hidden = true;
        el('fp-an-empty').hidden = true;
        api('/analytics/courses/' + courseList[courseSel].id)
            .then(function (d) {
                try {
                    renderCourseDetail(d);
                } catch (e) {
                    courseFail(e);   // a render bug must surface, not blank the page
                }
            })
            .catch(courseFail);
    }

    if (coursesTab) {
        coursesTab.addEventListener('click', function () {
            state.tab = 'courses';
            [el('fp-an-tab-site'), el('fp-an-tab-video')].forEach(function (b) {
                if (b) { b.classList.remove('on'); b.setAttribute('aria-selected', 'false'); }
            });
            this.classList.add('on'); this.setAttribute('aria-selected', 'true');
            el('fp-an-fbar').hidden = true;
            el('fp-an-video-head').hidden = true;
            el('fp-an-notices').innerHTML = '';
            el('fp-an-loading').hidden = false;
            el('fp-an-content').hidden = true;
            el('fp-an-empty').hidden = true;
            el('fp-an-subtitle').textContent = tr('acrossSite');
            document.querySelector('.fp-analytics').dataset.tab = 'courses';

            api('/analytics/courses').then(function (d) {
                courseList = d.courses || [];
                if (!courseList.length) {
                    el('fp-an-loading').hidden = true;
                    el('fp-an-empty').hidden = false;
                    el('fp-an-empty').innerHTML = '<div class="fp-an-emptybox"><p>No course has a FastPix video on its lessons yet.</p></div>';
                    return;
                }
                if (courseSel >= courseList.length) { courseSel = 0; }
                loadCourse();
            }).catch(courseFail);
        });
    }

    // Leaving the Courses tab restores the filter bar.
    [el('fp-an-tab-site'), el('fp-an-tab-video')].forEach(function (b) {
        b.addEventListener('click', function () {
            el('fp-an-fbar').hidden = false;
            if (coursesTab) { coursesTab.classList.remove('on'); coursesTab.setAttribute('aria-selected', 'false'); }
        });
    });

    el('fp-an-range').addEventListener('change', function () {
        state.range = this.value;
        el('fp-an-custom').hidden = this.value !== 'custom';
        if (this.value !== 'custom') { load(); }
    });
    var onCustom = function () {
        state.from = el('fp-an-from').value;
        state.to = el('fp-an-to').value;
        if (state.from && state.to) { load(); }
    };
    el('fp-an-from').addEventListener('change', onCustom);
    el('fp-an-to').addEventListener('change', onCustom);
    el('fp-an-device').addEventListener('change', function () { state.device = this.value; load(); });

    // Deep link: ?video=&range=&device=&from=&to=
    var qs = new URLSearchParams(location.search);
    if (qs.get('range')) { state.range = qs.get('range'); el('fp-an-range').value = state.range === 'custom' ? 'custom' : state.range; }
    if (state.range === 'custom') {
        state.from = qs.get('from') || ''; state.to = qs.get('to') || '';
        el('fp-an-custom').hidden = false; el('fp-an-from').value = state.from; el('fp-an-to').value = state.to;
    }
    if (qs.get('device')) { state.device = qs.get('device'); el('fp-an-device').value = state.device; }
    if (state.tab === 'video') {
        el('fp-an-tab-video').classList.add('on'); el('fp-an-tab-video').setAttribute('aria-selected', 'true');
        el('fp-an-tab-site').classList.remove('on'); el('fp-an-tab-site').setAttribute('aria-selected', 'false');
    }

    dropdown(el('fp-an-range'));
    dropdown(el('fp-an-device'));

    load();
})();
