/**
 * UI-002/UI-003 — Videos table, opened row, bulk bar.
 * Rows from GET /videos (keyset pagination); opened row from GET /videos/{id};
 * edits via PATCH; deletion via DELETE with the REQ-036 platform question;
 * bulk via POST /videos/bulk (queued, never inline).
 */
(function () {
    'use strict';

    if (typeof fastpixLibrary === 'undefined') {
        return;
    }

    var cfg = fastpixLibrary;
    var __ = wp.i18n.__, _n = wp.i18n._n, sprintf = wp.i18n.sprintf;   // [QA L25]
    var rowsEl = document.getElementById('fp-lib-rows');
    if (!rowsEl) { return; }

    // The frame's "S / arrow" glyph (assets/images/add-media-chevron.svg), inlined so it can rotate and recolour.
    var ARROW = '<svg viewBox="0 0 16 16" width="16" height="16" fill="none" aria-hidden="true"><path d="M4 6.5L8 10L12 6.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    var el = {
        search: document.getElementById('fp-lib-search'),
        status: document.getElementById('fp-lib-status'),
        access: document.getElementById('fp-lib-access'),
        source: document.getElementById('fp-lib-source'),
        perPage: document.getElementById('fp-lib-perpage'),
        dense: document.getElementById('fp-lib-dense'),
        viewBtn: document.getElementById('fp-view-btn'),
        viewPop: document.getElementById('fp-view-pop'),
        viewTable: document.getElementById('fp-view-table'),
        viewGrid: document.getElementById('fp-view-grid'),
        chips: document.getElementById('fp-lib-chips'),
        chipList: document.getElementById('fp-lib-chiplist'),
        clearAll: document.getElementById('fp-lib-clearall'),
        totalPill: document.getElementById('fp-lib-total-pill'),
        bulkbar: document.getElementById('fp-bulkbar'),
        bulkCount: document.getElementById('fp-bulk-count'),
        bulkAction: document.getElementById('fp-bulk-action'),
        bulkApply: document.getElementById('fp-bulk-apply'),
        bulkClear: document.getElementById('fp-bulk-clear'),
        checkAll: document.getElementById('fp-check-all'),
        empty: document.getElementById('fp-lib-empty'),
        zero: document.getElementById('fp-lib-zero'),
        zeroFilters: document.getElementById('fp-lib-zero-filters'),
        zeroHint: document.getElementById('fp-lib-zero-hint'),
        zeroClear: document.getElementById('fp-lib-zero-clear'),
        zeroClearFilters: document.getElementById('fp-lib-zero-clear-filters'),
        sortTitle: document.getElementById('fp-sort-title'),
        error: document.getElementById('fp-lib-error'),
        errorBody: document.getElementById('fp-lib-error-body'),
        count: document.getElementById('fp-lib-count'),
        pager: document.getElementById('fp-lib-pager'),
        foot: document.getElementById('fp-lib-foot'),
        table: document.getElementById('fp-libtable')
    };

    // Keyset paging: `cursors[n]` is the `after` value that opens page n (page 1 = null).
    var state = { next: null, selected: {}, open: null, orderby: 'id', order: 'desc', page: 1, cursors: [null], total: 0, view: 'table' };

    // Open language list — a list, not a fixed pair; taken codes are removed
    // per video when the row opens. [REQ-044]
    var LANGUAGES = [['en', 'English'], ['es', 'Spanish'], ['it', 'Italian'], ['pt', 'Portuguese'], ['de', 'German'], ['fr', 'French'], ['pl', 'Polish'], ['ru', 'Russian'], ['nl', 'Dutch'], ['ca', 'Catalan'], ['tr', 'Turkish'], ['sv', 'Swedish'], ['uk', 'Ukrainian'], ['no', 'Norwegian'], ['fi', 'Finnish'], ['sk', 'Slovak'], ['el', 'Greek'], ['cs', 'Czech'], ['hr', 'Croatian'], ['da', 'Danish'], ['ro', 'Romanian'], ['bg', 'Bulgarian']];
    // Uploaded .vtt/.srt files take any BCP 47 tag (docs "Language code support"; verified live 2026-09-25) —
    // the dashboard's CLDR list plus the ISO 639-1 common subtags. English names built in: browsers name only
    // some of these (Chrome shows "dsb" bare); the page-language pass below localizes the rest. [QA subtitle upload language]
    var UPLOAD_LANGUAGES = (
        'ab:Abkhazian|aa:Afar|af:Afrikaans|agq:Aghem|ak:Akan|sq:Albanian|am:Amharic|ar:Arabic|hy:Armenian|as:Assamese|ast:Asturian|asa:Asu|av:Avaric|ae:Avestan|' +
        'ay:Aymara|az:Azerbaijani|az-Cyrl:Azerbaijani (Cyrillic)|az-Latn:Azerbaijani (Latin)|ksf:Bafia|bm:Bambara|bn:Bangla|bas:Basaa|ba:Bashkir|eu:Basque|' +
        'be:Belarusian|bem:Bemba|bez:Bena|bho:Bhojpuri|bi:Bislama|brx:Bodo|bs:Bosnian|bs-Cyrl:Bosnian (Cyrillic)|bs-Latn:Bosnian (Latin)|br:Breton|bg:Bulgarian|' +
        'my:Burmese|yue:Cantonese|yue-Hans:Cantonese (Simplified)|yue-Hant:Cantonese (Traditional)|ca:Catalan|ceb:Cebuano|tzm:Central Atlas Tamazight|' +
        'ckb:Central Kurdish|ccp:Chakma|ch:Chamorro|ce:Chechen|chr:Cherokee|cgg:Chiga|zh:Chinese|zh-Hans:Simplified Chinese|zh-Hant:Traditional Chinese|' +
        'cu:Church Slavic|cv:Chuvash|ksh:Colognian|kw:Cornish|co:Corsican|cr:Cree|hr:Croatian|cs:Czech|da:Danish|dv:Divehi|doi:Dogri|dua:Duala|nl:Dutch|' +
        'dz:Dzongkha|ebu:Embu|en:English|eo:Esperanto|et:Estonian|ee:Ewe|ewo:Ewondo|fo:Faroese|fj:Fijian|fil:Filipino|fi:Finnish|fr:French|fur:Friulian|ff:Fula|' +
        'gl:Galician|lg:Ganda|ka:Georgian|de:German|el:Greek|gn:Guarani|gu:Gujarati|guz:Gusii|ht:Haitian Creole|ha:Hausa|haw:Hawaiian|he:Hebrew|hz:Herero|' +
        'hi:Hindi|ho:Hiri Motu|hu:Hungarian|is:Icelandic|io:Ido|ig:Igbo|smn:Inari Sami|id:Indonesian|ia:Interlingua|ie:Interlingue|iu:Inuktitut|ik:Inupiaq|' +
        'ga:Irish|it:Italian|ja:Japanese|jv:Javanese|dyo:Jola-Fonyi|kea:Kabuverdianu|kab:Kabyle|kl:Kalaallisut|kln:Kalenjin|kam:Kamba|kn:Kannada|kr:Kanuri|' +
        'ks:Kashmiri|kk:Kazakh|km:Khmer|ki:Kikuyu|rw:Kinyarwanda|kv:Komi|kg:Kongo|kok:Konkani|ko:Korean|khq:Koyra Chiini|ses:Koyraboro Senni|kj:Kuanyama|' +
        'ku:Kurdish|ky:Kyrgyz|lkt:Lakota|lag:Langi|lo:Lao|la:Latin|lv:Latvian|li:Limburgish|ln:Lingala|lt:Lithuanian|dsb:Lower Sorbian|lu:Luba-Katanga|luo:Luo|' +
        'lb:Luxembourgish|luy:Luyia|mk:Macedonian|jmc:Machame|mai:Maithili|mgh:Makhuwa-Meetto|kde:Makonde|mg:Malagasy|ms:Malay|ml:Malayalam|mt:Maltese|' +
        'mni:Manipuri|gv:Manx|mi:Māori|mr:Marathi|mh:Marshallese|mas:Masai|mzn:Mazanderani|mer:Meru|mgo:Metaʼ|mn:Mongolian|mfe:Morisyen|mua:Mundang|naq:Nama|' +
        'na:Nauru|nv:Navajo|ng:Ndonga|ne:Nepali|nnh:Ngiemboon|jgo:Ngomba|nd:North Ndebele|se:Northern Sami|no:Norwegian|nb:Norwegian Bokmål|' +
        'nn:Norwegian Nynorsk|nus:Nuer|ny:Nyanja|oc:Occitan|or:Odia|oj:Ojibwa|om:Oromo|os:Ossetic|pi:Pali|ps:Pashto|fa:Persian|pl:Polish|pt:Portuguese|' +
        'pa:Punjabi|pa-Arab:Punjabi (Arabic)|pa-Guru:Punjabi (Gurmukhi)|qu:Quechua|ro:Romanian|rm:Romansh|rof:Rombo|ru:Russian|rwk:Rwa|saq:Samburu|sm:Samoan|' +
        'sg:Sango|sbp:Sangu|sa:Sanskrit|sat:Santali|sc:Sardinian|gd:Scottish Gaelic|seh:Sena|sr:Serbian|sr-Cyrl:Serbian (Cyrillic)|sr-Latn:Serbian (Latin)|' +
        'ksb:Shambala|sn:Shona|ii:Sichuan Yi|sd:Sindhi|si:Sinhala|sk:Slovak|sl:Slovenian|xog:Soga|so:Somali|nr:South Ndebele|st:Southern Sotho|es:Spanish|' +
        'zgh:Standard Moroccan Tamazight|su:Sundanese|sw:Swahili|ss:Swati|sv:Swedish|gsw:Swiss German|shi:Tachelhit|shi-Latn:Tachelhit (Latin)|' +
        'shi-Tfng:Tachelhit (Tifinagh)|ty:Tahitian|tg:Tajik|ta:Tamil|twq:Tasawaq|tt:Tatar|te:Telugu|teo:Teso|th:Thai|bo:Tibetan|ti:Tigrinya|to:Tongan|ts:Tsonga|' +
        'tn:Tswana|tr:Turkish|tk:Turkmen|uk:Ukrainian|hsb:Upper Sorbian|ur:Urdu|ug:Uyghur|uz:Uzbek|uz-Arab:Uzbek (Arabic)|uz-Cyrl:Uzbek (Cyrillic)|' +
        'uz-Latn:Uzbek (Latin)|vai:Vai|vai-Latn:Vai (Latin)|vai-Vaii:Vai (Vai)|ve:Venda|vi:Vietnamese|vo:Volapük|vun:Vunjo|wa:Walloon|wae:Walser|cy:Welsh|' +
        'fy:Western Frisian|wo:Wolof|xh:Xhosa|sah:Yakut|yav:Yangben|yi:Yiddish|yo:Yoruba|dje:Zarma|za:Zhuang|zu:Zulu').split('|').map(function (p) { var i = p.indexOf(':'); return [p.slice(0, i), p.slice(i + 1)]; });
    // Names in the page's language where the browser can supply them; the English names stay the fallback. (QA L25)
    try {
        var langNames = new Intl.DisplayNames([document.documentElement.lang || 'en'], { type: 'language' });
        LANGUAGES.concat(UPLOAD_LANGUAGES).forEach(function (l) { var n = langNames.of(l[0]); if (n && n !== l[0]) { l[1] = n.charAt(0).toLocaleUpperCase() + n.slice(1); } });
        UPLOAD_LANGUAGES.sort(function (a, b) { return a[1].localeCompare(b[1]); });
    } catch (e) {}

    function api(method, path, body) {
        // Always resolves to {ok,status,json}. Reading the body with .text() +
        // a tolerant parse (never .json(), which REJECTS on an empty/204/non-JSON
        // body) means a caller's .then always runs — so a control like the Save
        // button can never get stuck "Saving…" after a request the server actually
        // completed. A network failure resolves to {ok:false,status:0,json:null}.
        return fetch(cfg.restUrl + path, {
            method: method,
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
            credentials: 'same-origin',
            body: body ? JSON.stringify(body) : undefined
        }).then(function (r) {
            return r.text().then(function (t) {
                var j = null;
                try { j = t ? JSON.parse(t) : null; } catch (e) { j = null; }
                return { ok: r.ok, status: r.status, json: j };
            });
        }, function () {
            return { ok: false, status: 0, json: null };
        });
    }

    /* --------------------------------------------------------------- list */

    function hasFilters() {
        return !!(el.search.value.trim() || el.status.value || el.access.value || el.source.value);
    }

    function skeleton() {
        var rows = '';
        for (var i = 0; i < 6; i++) {
            rows += '<tr class="fp-skrow"><td class="cbcell"><div class="sk line" style="width:16px"></div></td><td class="thcell"><div class="sk t"></div></td>' +
                '<td class="titlecell"><div class="sk line" style="width:62%"></div><div class="sk line" style="width:34%"></div></td>' +
                '<td class="idcell"><div class="sk line" style="width:60px"></div></td><td class="idcell"><div class="sk line" style="width:60px"></div></td>' +
                '<td class="statuscell"><div class="sk line" style="width:56px"></div></td><td class="acccell"><div class="sk line" style="width:70px"></div></td><td class="toolcell"></td></tr>';
        }
        rowsEl.innerHTML = rows;
    }

    /** Load page `state.page` (cursor from state.cursors). */
    var querySeq = 0;
    function query(reset) {
        if (reset) { state.page = 1; state.cursors = [null]; }
        // A selection is the rows on screen: a new page or filter starts empty, so the
        // bulk bar can never act on rows the user no longer sees. [QA L2]
        state.selected = {}; el.checkAll.checked = false; syncBulk();
        openSeq++;   // an opened row that is still loading belongs to the old list [QA L19]
        var params = new URLSearchParams();
        if (el.search.value.trim()) { params.set('search', el.search.value.trim()); }
        if (el.status.value) { params.set('status', el.status.value); }
        if (el.access.value) { params.set('access', el.access.value); }
        if (el.source.value) { params.set('source', el.source.value); }
        params.set('per_page', el.perPage.value || '25');
        if (state.orderby !== 'id') { params.set('orderby', state.orderby); params.set('order', state.order); }
        var after = state.cursors[state.page - 1];
        if (after) { params.set('after', after); }

        skeleton();
        state.open = null;
        var seq = ++querySeq;   // only the newest request may paint — a slow earlier one is dropped [QA L3]
        api('GET', '/videos?' + params.toString()).then(function (res) {
            if (seq !== querySeq) { return; }
            rowsEl.innerHTML = '';
            if (!res.ok) {
                el.error.hidden = false;
                el.errorBody.textContent = (res.json && res.json.message) || __('The list could not be loaded.', 'fastpix-io');
                return;
            }
            el.error.hidden = true;

            // Page ≥2 emptied (its last row was deleted): step back a page rather than
            // showing the "upload your first video" card over a library of 25. [QA L1]
            if (!res.json.videos.length && state.page > 1) { state.page--; query(false); return; }

            res.json.videos.forEach(addRow);
            state.next = res.json.next;
            state.total = res.json.total || 0;
            if (state.next) { state.cursors[state.page] = state.next; }

            var any = res.json.videos.length > 0;
            el.table.style.display = any ? '' : 'none';
            el.foot.hidden = !any;
            // "N of M" only once the unfiltered M is known; a filtered first load shows N alone, never "N of N". [QA L4/L6]
            /* translators: 1: videos matching the filters, 2: videos in the whole library (QA L25) */
            el.totalPill.textContent = hasFilters() && state.libraryTotal != null ? sprintf(__('%1$s of %2$s', 'fastpix-io'), state.total, state.libraryTotal) : String(state.total);
            el.totalPill.hidden = false;
            if (!hasFilters()) { state.libraryTotal = state.total; }

            renderChips();
            renderPager();

            // Empty vs filtered-zero: an empty LIBRARY invites an upload; zero
            // MATCHES echoes the filters and hints at the closest match. [UI-002]
            el.empty.hidden = any || hasFilters();
            el.zero.hidden = any || !hasFilters();
            if (!any && hasFilters()) {
                var parts = [];
                if (el.search.value.trim()) { parts.push('“' + el.search.value.trim() + '”'); }
                if (el.status.value) { parts.push(sprintf(__('Status: %s', 'fastpix-io'), el.status.value)); }
                if (el.access.value) { parts.push(sprintf(__('Access: %s', 'fastpix-io'), el.access.value.charAt(0).toUpperCase() + el.access.value.slice(1))); }
                if (el.source.value) { parts.push(sprintf(__('Source: %s', 'fastpix-io'), el.source.value)); }
                el.zeroFilters.textContent = parts.join(' · ');
                el.zeroClearFilters.hidden = !(el.status.value || el.access.value || el.source.value);
                closestMatchHint(seq);
            }

            if (res.json.search_note) {
                el.error.hidden = false;
                el.errorBody.textContent = res.json.search_note;   // RULE-044: said plainly
            }
            syncBulk();
        });
    }

    function renderChips() {
        var chips = [];
        if (el.search.value.trim()) { chips.push({ label: '“' + el.search.value.trim() + '”', clear: function () { el.search.value = ''; } }); }
        if (el.status.value) { chips.push({ label: sprintf(__('Status: %s', 'fastpix-io'), el.status.value), clear: function () { el.status.value = ''; } }); }
        if (el.access.value) { chips.push({ label: sprintf(__('Access: %s', 'fastpix-io'), el.access.value), clear: function () { el.access.value = ''; } }); }
        if (el.source.value) { chips.push({ label: sprintf(__('Source: %s', 'fastpix-io'), el.source.value), clear: function () { el.source.value = ''; } }); }
        el.chipList.innerHTML = '';
        chips.forEach(function (c) {
            var b = document.createElement('button'); b.type = 'button'; b.className = 'b neutral'; b.textContent = c.label + ' ✕'; b.title = __('Remove this filter', 'fastpix-io');
            b.addEventListener('click', function () { c.clear(); query(true); });
            el.chipList.appendChild(b);
        });
        el.chips.hidden = chips.length === 0;
    }

    /** ‹ 1 2 3 › — numbered for the pages we know (visited + the next), honest for keyset paging. */
    function renderPager() {
        var per = parseInt(el.perPage.value || '25', 10);
        var pages = Math.max(1, Math.ceil(state.total / per));
        /* translators: %s: the count, in bold */
        el.count.innerHTML = sprintf(_n('<b>%s</b> video', '<b>%s</b> videos', state.total, 'fastpix-io'), state.total) + (cfg.ownOnly ? ' ' + __('you uploaded', 'fastpix-io') : '');   // server int only
        el.pager.innerHTML = '';
        if (pages <= 1) { return; }
        function btn(label, page, on, disabled) {
            var b = document.createElement('button'); b.type = 'button';
            if (label === '‹' || label === '›') { b.className = label === '‹' ? 'prev' : 'next'; b.innerHTML = ARROW; b.setAttribute('aria-label', label === '‹' ? __('Previous page', 'fastpix-io') : __('Next page', 'fastpix-io')); } else { b.textContent = label; }
            if (on) { b.className = 'on'; }
            b.disabled = !!disabled;
            if (!disabled && !on) { b.addEventListener('click', function () { state.page = page; query(false); window.scrollTo({ top: 0, behavior: 'smooth' }); }); }
            return b;
        }
        el.pager.appendChild(btn('‹', state.page - 1, false, state.page <= 1));
        var known = state.cursors.length;   // pages we can jump to directly
        var from = Math.max(1, state.page - 2), to = Math.min(pages, Math.max(state.page + 2, 3));
        for (var p = from; p <= to; p++) {
            el.pager.appendChild(btn(String(p), p, p === state.page, p > known));
        }
        if (to < pages) { var dots = document.createElement('span'); dots.className = 'muted small'; dots.textContent = '…'; el.pager.appendChild(dots); }
        el.pager.appendChild(btn('›', state.page + 1, false, !state.next));
    }

    /** "Your search matches N videos without the filters." */
    function closestMatchHint(seq) {
        el.zeroHint.hidden = true;
        var term = el.search.value.trim();
        if (!term || !(el.status.value || el.access.value || el.source.value)) { return; }

        api('GET', '/videos?' + new URLSearchParams({ search: term }).toString()).then(function (res) {
            if (seq !== querySeq) { return; }   // the list moved on [QA L3]
            if (res.ok && res.json.videos.length) {
                el.zeroHint.textContent = sprintf(_n('Your search matches %s video without the filters.', 'Your search matches %s videos without the filters.', res.json.videos.length, 'fastpix-io'), res.json.videos.length + (res.json.next ? '+' : ''));
                el.zeroHint.hidden = false;
            }
        });
    }

    function shortId(v) { return v && v.length > 17 ? v.slice(0, 8) + '…' + v.slice(-3) : (v || ''); }

    /** ID cell: mono truncated, full value in the tooltip, copy on hover; em-dash when absent. */
    function idCell(td, value) {
        if (!value) { td.innerHTML = '<span class="faint">—</span>'; return; }
        var wrap = document.createElement('span'); wrap.className = 'idv';
        var v = document.createElement('span'); v.className = 'mono'; v.title = value; v.textContent = shortId(value);
        var cp = document.createElement('button'); cp.type = 'button'; cp.className = 'cp'; cp.title = __('Copy', 'fastpix-io'); cp.textContent = '⧉';
        cp.addEventListener('click', function (e) { e.stopPropagation(); copyText(value, cp); });
        wrap.appendChild(v); wrap.appendChild(cp); td.appendChild(wrap);
    }

    function copyText(text, button) {
        var done = function () { if (button) { var was = button.innerHTML; button.textContent = '✓'; setTimeout(function () { button.innerHTML = was; }, 1200); } };   // innerHTML: buttons carry an icon
        if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(text).then(done, done); } else { done(); }
    }

    function stBadge(status) {
        var s = (status || '').toLowerCase();
        if (s === 'ready') { return '<span class="b ok"><span class="dot"></span>' + esc(__('Ready', 'fastpix-io')) + '</span>'; }
        if (s === 'processing' || s === 'preparing' || s === 'created') { return '<span class="b proc">' + esc(__('Preparing…', 'fastpix-io')) + '</span>'; }
        if (s === 'failed') { return '<span class="b err">' + esc(__('Failed', 'fastpix-io')) + '</span>'; }
        if (s === 'queued') { return '<span class="b neutral">◷ ' + esc(__('Queued', 'fastpix-io')) + '</span>'; }
        if (s === 'unavailable') { return '<span class="b err">' + esc(__('Unavailable', 'fastpix-io')) + '</span>'; }
        return '<span class="b neutral"></span>';
    }
    function polBadge(p) {
        if (p === 'private') { return '<span class="b info">🔒 ' + esc(__('Private', 'fastpix-io')) + '</span>'; }
        if (p === 'drm') { return '<span class="b info">🛡 ' + esc(__('DRM', 'fastpix-io')) + '</span>'; }
        return '<span class="b neutral"><span class="dot"></span>' + esc(__('Public', 'fastpix-io')) + '</span>';
    }

    function addRow(video) {
        var tr = document.createElement('tr');
        tr.className = 'fp-vrow';
        tr.setAttribute('data-fp-id', video.id);
        var unavailable = video.status === 'Unavailable';
        var failed = video.status === 'Failed';

        tr.innerHTML =
            '<td class="cbcell"><input type="checkbox" class="fp-check" aria-label="' + esc(__('Select', 'fastpix-io')) + '"></td>' +
            '<td class="thcell"><div class="thumb' + (failed ? ' ph' : '') + '">' +
                (video.poster && !failed ? '<img alt="" loading="lazy" src="' + esc(video.poster) + '">' : '') +
                (video.access_policy && video.access_policy !== 'public' ? '<span class="lock">🔒</span>' : '') +
                (video.duration ? '<span class="dur">' + fmtTime(video.duration) + '</span>' : '') + '</div></td>' +
            '<td class="titlecell"><a class="vtitle" href="#"></a><div class="rowacts">' +
                '<a href="#" class="act-edit">' + esc(__('Edit', 'fastpix-io')) + '</a><a href="#" class="act-embed">' + esc(__('Copy embed', 'fastpix-io')) + '</a>' +
                (cfg.canDelete ? '<a href="#" class="act-del del">' + esc(__('Delete', 'fastpix-io')) + '</a>' : '') + '</div></td>' +
            '<td class="idcell media"></td><td class="idcell playback"></td>' +
            '<td class="statuscell">' + stBadge(video.status) + '</td>' +
            '<td class="acccell">' + polBadge(video.access_policy) + '</td>' +
            '<td class="toolcell"><div class="rowtools">' +
                '<button type="button" class="btn sm ghost shortcode-btn" title="' + esc(__('Copy the shortcode for this video', 'fastpix-io')) + '"><svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V6a2 2 0 0 1 2-2h9"/></svg>' + esc(__('Shortcode', 'fastpix-io')) + '</button>' +
                '<button type="button" class="kebab" title="' + esc(__('More', 'fastpix-io')) + '" aria-haspopup="menu" aria-expanded="false">⋮</button>' +
                '<button type="button" class="chev" aria-expanded="false" title="' + esc(__('Expand', 'fastpix-io')) + '">' + ARROW + '</button>' +
            '</div></td>';

        var title = tr.querySelector('.vtitle');
        title.textContent = video.title || __('(untitled)', 'fastpix-io');
        tr.querySelector('.fp-check').setAttribute('aria-label', sprintf(__('Select %s', 'fastpix-io'), video.title || __('this video', 'fastpix-io')));   // a per-row accessible name
        if (unavailable) { var sb = tr.querySelector('.statuscell .b'); if (sb) { sb.title = __('This video no longer exists on FastPix. Posts using it are unchanged.', 'fastpix-io'); } }
        var titleCell = tr.querySelector('.titlecell');
        if (video.match && typeof video.match.seconds === 'number') {
            var m = document.createElement('div');
            m.className = 'micro muted';
            // Whole sentences per field; the server only reports timed hits in these two. (QA L26)
            /* translators: %s: a timestamp like 1:23 */
            var matchText = video.match.field === 'chapter' ? __('match at %s in its chapters', 'fastpix-io')
                : video.match.field === 'transcript' ? __('match at %s in its transcript', 'fastpix-io') : __('match at %s', 'fastpix-io');
            m.textContent = sprintf(matchText, fmtTime(video.match.seconds));
            titleCell.insertBefore(m, titleCell.querySelector('.rowacts'));   // transcript matches return timestamps [REQ-032]
        }
        idCell(tr.querySelector('.idcell.media'), video.media_id);
        idCell(tr.querySelector('.idcell.playback'), video.playback_id);
        if (!video.playback_id) { tr.querySelector('.idcell.playback').title = __('A playback id exists once processing has produced something to play', 'fastpix-io'); }

        var open = function (e) { if (e) { e.preventDefault(); } toggleOpen(tr, video.id); };
        title.addEventListener('click', open);
        tr.querySelector('.act-edit').addEventListener('click', open);
        tr.querySelector('.chev').addEventListener('click', open);
        tr.querySelector('.act-embed').addEventListener('click', function (e) { e.preventDefault(); copyEmbed(video.id, e.target); });
        tr.querySelector('.shortcode-btn').addEventListener('click', function (e) {
            // The open panel's configured shortcode wins over the plain one.
            if (state.open && state.open.id === video.id && state.open.shortcode) { copyText(state.open.shortcode, e.currentTarget); return; }
            copyEmbed(video.id, e.currentTarget);
        });
        var del = tr.querySelector('.act-del');
        if (del) {
            if (video.status === 'Unavailable') { del.textContent = __('Remove', 'fastpix-io'); del.title = __('Remove this record from the library', 'fastpix-io'); }
            del.addEventListener('click', function (e) { e.preventDefault(); video.status === 'Unavailable' ? removeVideo(video.id, video.title) : deleteVideo(video.id, video.title); });
        }
        var kebab = tr.querySelector('.kebab');
        kebab.addEventListener('click', function (e) { e.stopPropagation(); rowMenu(kebab, tr, video); });

        tr.querySelector('.fp-check').addEventListener('change', function (e) {
            if (e.target.checked) { state.selected[video.id] = true; tr.classList.add('sel'); } else { delete state.selected[video.id]; tr.classList.remove('sel'); }
            syncBulk();
        });

        rowsEl.appendChild(tr);
    }

    /** ⋮ menu: the row actions for keyboard/touch users (hover reveals them on the title). */
    function rowMenu(button, tr, video) {
        closeMenus();
        var menu = document.createElement('div'); menu.className = 'fp-menu'; menu.setAttribute('role', 'menu');
        // Focus goes back to the ⋮ BEFORE the item is removed, so a confirm dialog opened by the action can return focus there. [QA L21]
        function item(label, cls, fn) { var b = document.createElement('button'); b.type = 'button'; b.textContent = label; if (cls) { b.className = cls; } b.setAttribute('role', 'menuitem'); b.addEventListener('click', function () { button.focus(); closeMenus(); fn(); }); menu.appendChild(b); }
        menuKeys(menu);
        item(tr.classList.contains('isopen') ? __('Close', 'fastpix-io') : __('Edit', 'fastpix-io'), '', function () { toggleOpen(tr, video.id); });
        item(__('Copy shortcode', 'fastpix-io'), '', function () { copyEmbed(video.id, button); });   // owner 2026-09-09: "Copy embed" was the same call twice
        if (cfg.analyticsUrl) { item(__('View analytics', 'fastpix-io'), '', function () { location.href = cfg.analyticsUrl + '&video=' + video.id; }); }
        if (cfg.canDelete) {
            video.status === 'Unavailable'
                ? item(__('Remove from library…', 'fastpix-io'), 'del', function () { removeVideo(video.id, video.title); })
                : item(__('Delete…', 'fastpix-io'), 'del', function () { deleteVideo(video.id, video.title); });
        }
        button.parentNode.appendChild(menu);
        placeMenu(menu, button);
        button.setAttribute('aria-expanded', 'true');
        menu.querySelector('button').focus({ preventScroll: true });   // a scroll would close the fixed menu
    }
    // The table clips overflow (rounded corners), so the menu is position:fixed against the ⋮ itself — a
    // 1-row table can no longer clip it. It opens upward when there is no room below in the viewport. [QA U1]
    function placeMenu(menu, button) {
        var a = button.getBoundingClientRect(), h = menu.offsetHeight;
        var up = a.bottom + 4 + h > window.innerHeight && a.top - 4 - h > 0;
        menu.style.position = 'fixed';
        menu.style.right = (document.documentElement.clientWidth - a.right) + 'px';
        menu.style.top = (up ? a.top - 4 - h : a.bottom + 4) + 'px';
        menu.style.bottom = 'auto';
    }
    // A fixed menu does not follow its ⋮ when something scrolls or the window resizes: close it.
    window.addEventListener('scroll', function () { if (document.querySelector('.fp-videos .fp-menu')) { closeMenus(); } }, true);
    window.addEventListener('resize', function () { closeMenus(); });
    function closeMenus() {
        document.querySelectorAll('.fp-videos .fp-menu').forEach(function (m) { m.remove(); });
        document.querySelectorAll('.fp-videos .kebab[aria-expanded="true"]').forEach(function (k) { k.setAttribute('aria-expanded', 'false'); });
    }
    /* ⋮ menus: ArrowUp/Down (and Home/End) move between items; Escape (document handler) closes and returns focus to the ⋮. [QA L21] */
    function menuKeys(menu) {
        menu.addEventListener('keydown', function (e) {
            var items = Array.prototype.slice.call(menu.querySelectorAll('[role="menuitem"]')), i = items.indexOf(document.activeElement), n = items.length;
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); items[i < 0 ? (e.key === 'ArrowDown' ? 0 : n - 1) : (i + (e.key === 'ArrowDown' ? 1 : n - 1)) % n].focus(); }
            else if (e.key === 'Home' || e.key === 'End') { e.preventDefault(); items[e.key === 'Home' ? 0 : n - 1].focus(); }
        });
    }
    document.addEventListener('click', function (e) {
        if (!e.target.closest('.fp-menu') && !e.target.closest('.kebab')) { closeMenus(); }
        if (!e.target.closest('.fp-viewwrap')) { el.viewPop.hidden = true; el.viewBtn.setAttribute('aria-expanded', 'false'); }
        // One listener closes every open info bubble and language list — never one per card build. [QA L20]
        document.querySelectorAll('.fp-videos .fp-o-tip:not([hidden]), .fp-videos .fp-o-menu:not([hidden])').forEach(function (pop) {
            if (pop.parentNode.contains(e.target)) { return; }
            pop.hidden = true;
            var opener = pop.previousElementSibling; if (opener) { opener.setAttribute('aria-expanded', 'false'); }
        });
    });
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') { return; }
        var opened = document.querySelector('.fp-videos .kebab[aria-expanded="true"]');
        closeMenus(); el.viewPop.hidden = true;
        if (opened) { opened.focus(); }   // never drop focus to <body> [QA L21]
    });

    function copyEmbed(id, button) {
        api('GET', '/videos/' + id + '/embed').then(function (res) {
            if (res.ok) { copyText(res.json.shortcode, button && button.classList.contains('shortcode-btn') ? button : null); }
        });
    }

    /** DELETE with the REQ-036 platform question; the row goes when the server confirms. */
    function deleteVideo(id, title) {
        // REQ-037: name the posts that use this video BEFORE the confirm, so the
        // decision is informed. The count comes from the local usage table
        // (/usage); if that read fails we fall back to the plain warning.
        api('GET', '/videos/' + id + '/usage').then(function (u) {
            var posts = (u.ok && u.json && u.json.posts) ? u.json.posts : [];
            var usageNote = '';
            if (posts.length) {
                var names = posts.slice(0, 5).map(function (p) { return '• ' + (p.title || sprintf(__('Post #%d', 'fastpix-io'), p.post_id)); }).join('\n');
                var more = posts.length > 5 ? '\n' + sprintf(__('…and %d more', 'fastpix-io'), posts.length - 5) : '';
                usageNote = '\n\n' + sprintf(_n('Used in %d post:', 'Used in %d posts:', posts.length, 'fastpix-io'), posts.length) + '\n' + names + more +
                    '\n' + __('They keep working from the saved poster and a link.', 'fastpix-io');
            }
            fpDialog.confirm({ title: sprintf(__('Delete "%s" from this site?', 'fastpix-io'), title || __('this video', 'fastpix-io')), message: usageNote + '\n\n' + __('Nothing is deleted on FastPix unless you say so next.', 'fastpix-io'), ok: __('Delete', 'fastpix-io'), danger: true }).then(function (ok) {
                if (!ok) { return; }
                return fpDialog.confirm({ title: __('Also delete it on FastPix?', 'fastpix-io'), message: __('“Delete on FastPix too” removes it from your FastPix account as well — anything else that plays it stops working, and it can’t be undone. “Only this site” removes it from this library and leaves it on FastPix.', 'fastpix-io'), ok: __('Delete on FastPix too', 'fastpix-io'), cancel: __('Only this site', 'fastpix-io'), danger: true }).then(function (alsoPlatform) {
                if (alsoPlatform === null) { return; }   // Escape / backdrop = dismissed, not "only this site"
                    api('DELETE', '/videos/' + id + '?delete_on_platform=' + (alsoPlatform ? 'true' : 'false')).then(function (res) {
                        if (res.ok) { query(false); } else { fpDialog.alert((res.json && res.json.message) || __('Could not delete.', 'fastpix-io')); }
                    });
                });
            });
        });
    }

    /* An Unavailable record (deleted here or on FastPix, or no longer known to it) can go
       for good. Posts still embedding it lose the saved poster fallback — the confirm names
       them. Unused ones also leave on their own after 30 days (nightly). [ASSUME-102] */
    function removeVideo(id, title) {
        api('GET', '/videos/' + id + '/usage').then(function (u) {
            var posts = (u.ok && u.json && u.json.posts) ? u.json.posts : [];
            var usageNote = posts.length
                ? '\n\n' + sprintf(_n('Still embedded in %d post — that embed will show "video not found" instead of the saved poster.', 'Still embedded in %d posts — those embeds will show "video not found" instead of the saved poster.', posts.length, 'fastpix-io'), posts.length)
                : '';
            fpDialog.confirm({ title: sprintf(__('Remove "%s" from the library?', 'fastpix-io'), title || __('this video', 'fastpix-io')), message: usageNote + '\n\n' + __('The record and its local data go for good. Nothing changes on FastPix.', 'fastpix-io'), ok: __('Remove', 'fastpix-io'), danger: true }).then(function (ok) {
                if (!ok) { return; }
                api('DELETE', '/videos/' + id + '?purge=true').then(function (res) {
                    if (res.ok) { query(false); } else { fpDialog.alert((res.json && res.json.message) || __('Could not remove.', 'fastpix-io')); }
                });
            });
        });
    }

    /* --------------------------------------------------------- opened row */
    /* Figma FastPix-V3, frame 9376:105215. LEFT — Basics · Subtitles + Extras;
       RIGHT — Poster image · Player options · Cancel/Save · a collapsed "More
       options" disclosure for everything the frame does not draw (downloadable
       file, keyboard shortcuts, start-at, accent, course lesson, block insert,
       ids/details, revert-to-local, analytics). Wired to the real routes. */

    function markOpen(tr, on) {
        tr.classList.toggle('isopen', on);
        var chev = tr.querySelector('.chev'); if (chev) { chev.classList.toggle('open', on); chev.setAttribute('aria-expanded', String(on)); chev.title = on ? __('Collapse', 'fastpix-io') : __('Expand', 'fastpix-io'); }
        var edit = tr.querySelector('.act-edit'); if (edit) { edit.textContent = on ? __('Close', 'fastpix-io') : __('Edit', 'fastpix-io'); }
    }
    function h(tag, cls, text) { var e = document.createElement(tag); if (cls) { e.className = cls; } if (text !== undefined && text !== null) { e.textContent = text; } return e; }
    // Escapes &<> via textContent AND quotes, since esc() output also lands in
    // attribute context (e.g. src="'+esc(...)+'") where a " would break out.
    function esc(t) { var d = document.createElement('div'); d.textContent = t == null ? '' : String(t); return d.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;'); }
    // The frame's "InfoIcons16pt/Info" (assets/images/library-info-icon.svg), inlined so it recolours; the title is the help text.
    var INFO_PATH = 'M10 2.5C5.84375 2.5 2.5 5.84375 2.5 10C2.5 14.1562 5.84375 17.5 10 17.5C14.1562 17.5 17.5 14.1562 17.5 10C17.5 5.84375 14.1562 2.5 10 2.5ZM10.0002 6.28135C10.5315 6.28135 10.9377 6.6876 10.9377 7.21885C10.9377 7.7501 10.5315 8.15635 10.0002 8.15635C9.46899 8.15635 9.06274 7.7501 9.06274 7.21885C9.06274 6.6876 9.46899 6.28135 10.0002 6.28135V6.28135ZM11.5631 12.8439C11.5631 13.0001 11.4381 13.1251 11.2506 13.1251H8.75057C8.59432 13.1251 8.43807 13.0314 8.43807 12.8439V12.2189C8.43807 12.0626 8.56307 11.8751 8.75057 11.8751C8.90682 11.8751 9.06307 11.7814 9.06307 11.5939V10.3439C9.06307 10.1876 8.93807 10.0001 8.75057 10.0001C8.59432 10.0001 8.43807 9.90635 8.43807 9.71885V9.09385C8.43807 8.9376 8.56307 8.7501 8.75057 8.7501H10.6256C10.7818 8.7501 10.9381 8.90635 10.9381 9.09385V11.5939C10.9381 11.7501 11.0631 11.8751 11.2506 11.8751C11.4068 11.8751 11.5631 12.0314 11.5631 12.2189V12.8439Z';
    /** The info icon: hover shows the text as a tooltip, click (and touch) opens it as a bubble. Mount inside a position:relative row. */
    function infoIcon(help, host) {
        var i = h('button', 'fp-o-info'); i.type = 'button'; i.title = help; i.setAttribute('aria-label', help); i.setAttribute('aria-expanded', 'false');
        i.innerHTML = '<svg viewBox="0 0 20 20" width="20" height="20" aria-hidden="true"><path fill="currentColor" fill-rule="evenodd" clip-rule="evenodd" d="' + INFO_PATH + '"/></svg>';
        var tip = h('div', 'fp-o-tip', help); tip.hidden = true; tip.setAttribute('role', 'note');
        i.addEventListener('click', function (e) { e.stopPropagation(); tip.hidden = !tip.hidden; i.setAttribute('aria-expanded', String(!tip.hidden)); });
        host.appendChild(i); host.appendChild(tip);   // closed by the one document click listener (tip.previousElementSibling = i) [QA L20]
    }
    function titleRow(text, help) {
        var r = h('div', 'fp-o-hrow'); r.appendChild(h('h3', 'fp-o-h', text));
        if (help) { infoIcon(help, r); }
        return r;
    }
    // A language list opens on the side with more room (the fixed admin bar is not room), and
    // shrinks to fit when even that side is short.
    function flipMenu(menu) {
        menu.classList.remove('up'); menu.style.maxHeight = '';
        var a = menu.parentNode.getBoundingClientRect(), bar = document.getElementById('wpadminbar');
        var below = window.innerHeight - a.bottom - 12, above = a.top - Math.max(0, bar ? bar.getBoundingClientRect().bottom : 0) - 12, h = menu.offsetHeight;
        if (h > below && above > below) { menu.classList.add('up'); }
        var room = menu.classList.contains('up') ? above : below;
        if (h > room) { menu.style.maxHeight = Math.max(room, 96) + 'px'; }
    }
    function bt(label, cls, fn) { var b = h('button', cls || 'btn', label); b.type = 'button'; if (fn) { b.addEventListener('click', fn); } return b; }
    function pill(text, kind) { return h('span', 'fp-o-pill' + (kind ? ' ' + kind : ''), text); }
    function hhmmss(sec) { sec = Math.floor(sec || 0); var hh = Math.floor(sec / 3600), mm = Math.floor(sec % 3600 / 60), ss = sec % 60; return String(hh).padStart(2, '0') + ':' + String(mm).padStart(2, '0') + ':' + String(ss).padStart(2, '0'); }
    var LANG_NAME = {}; UPLOAD_LANGUAGES.concat(LANGUAGES).forEach(function (l) { LANG_NAME[l[0]] = l[1]; });
    function langName(code) { code = String(code || ''); return LANG_NAME[code] || LANG_NAME[code.split('-')[0]] || code; }
    // Languages the dashboard marks Beta (docs, 2026-09-20) — label only, never sent to the platform. [QA U5]
    var BETA = { pl: 1, ru: 1, nl: 1, ca: 1, tr: 1, sv: 1, uk: 1, no: 1, fi: 1, sk: 1, el: 1, cs: 1, hr: 1, da: 1, ro: 1, bg: 1 };
    function langLabel(code) { return langName(code) + (BETA[String(code || '').split('-')[0]] ? ' · ' + __('Beta', 'fastpix-io') : ''); }
    var AI_LABELS = { chapters: __('Chapters', 'fastpix-io'), summary: __('Summary', 'fastpix-io'), entities: __('People & places mentioned', 'fastpix-io'), moderation: __('Moderation', 'fastpix-io'), transcript: __('Transcript', 'fastpix-io'), subtitles: __('Subtitles', 'fastpix-io') };
    function aiLabel(kind) { return AI_LABELS[kind] || kind; }
    // The same three words the ACCESS column, its filter and every policy dropdown use. (owner 2026-09-22)
    var ACCESS_LABEL = { public: __('Public', 'fastpix-io'), private: __('Private', 'fastpix-io'), drm: __('DRM', 'fastpix-io') };
    // phrase = true: a whole translatable sentence ("5 minutes ago") for track rows. Without it the
    // live list gets its short units and the 'now' / 'yesterday' keys agoPhrase() translates. (QA L25)
    function timeAgo(iso, phrase) {
        if (!iso) { return ''; }
        var t = Date.parse(iso.replace(' ', 'T') + (iso.indexOf('Z') === -1 && iso.indexOf('+') === -1 ? 'Z' : ''));
        if (isNaN(t)) { return ''; }
        var s = Math.max(0, Math.round((Date.now() - t) / 1000));
        if (phrase) {
            if (s < 60) { return __('just now', 'fastpix-io'); }
            var n = s < 3600 ? Math.round(s / 60) : Math.round(s / 3600);
            /* translators: %s: a number of minutes */
            if (s < 3600) { return sprintf(_n('%s minute ago', '%s minutes ago', n, 'fastpix-io'), n); }
            /* translators: %s: a number of hours */
            if (s < 86400) { return sprintf(_n('%s hour ago', '%s hours ago', n, 'fastpix-io'), n); }
            n = Math.round(s / 86400);
            /* translators: %s: a number of days */
            return n === 1 ? __('yesterday', 'fastpix-io') : sprintf(_n('%s day ago', '%s days ago', n, 'fastpix-io'), n);
        }
        if (s < 60) { return 'now'; } if (s < 3600) { return sprintf(__('%d min', 'fastpix-io'), Math.round(s / 60)); } if (s < 86400) { return sprintf(__('%d h', 'fastpix-io'), Math.round(s / 3600)); }
        var d = Math.round(s / 86400); return d === 1 ? 'yesterday' : sprintf(__('%d d', 'fastpix-io'), d);   // 'now' / 'yesterday' stay keys — agoPhrase() translates them
    }

    var openSeq = 0;   // only the latest open may mount its panel — quick clicks leave no orphans [QA L19]
    function toggleOpen(tr, id) {
        openSeq++;
        if (state.open && state.open.id === id) { state.open.panel.remove(); markOpen(state.open.tr, false); state.open = null; return; }
        if (state.open) { state.open.panel.remove(); markOpen(state.open.tr, false); state.open = null; }
        var seq = openSeq;
        api('GET', '/videos/' + id).then(function (res) {
            if (!res.ok || seq !== openSeq) { return; }
            var panel = document.createElement('tr'); panel.className = 'fp-openrow exprow';
            var td = document.createElement('td'); td.colSpan = (el.table.tHead && el.table.tHead.rows[0]) ? el.table.tHead.rows[0].cells.length : 8; panel.appendChild(td);
            markOpen(tr, true);
            // Set before the build so build() can publish the shortcode for the row's Shortcode button.
            state.open = { id: id, panel: panel, tr: tr, shortcode: '' };
            td.appendChild(buildPanel(res.json, tr, id));
            placePanel();
        });
    }

    /* In the grid the panel spans every column, so it must follow the LAST card of the
       opened card's visual row — dropped straight after the card it would push the rest
       of that row under the panel and leave the row half empty. Re-placed on resize
       because the row composition changes with the column count. */
    function placePanel() {
        if (!state.open) { return; }
        var tr = state.open.tr, host = tr;
        state.open.panel.remove();   // measure the row without the panel in it (it changes what "same row" means after a resize)
        if (el.table.classList.contains('cards')) {
            var top = tr.offsetTop, n = tr.nextElementSibling;
            while (n && n.classList.contains('fp-vrow') && n.offsetTop === top) { host = n; n = n.nextElementSibling; }
        }
        host.after(state.open.panel);
    }
    var placeTimer = null;
    window.addEventListener('resize', function () { clearTimeout(placeTimer); placeTimer = setTimeout(placePanel, 120); });

    function buildPanel(v, tr, id) {
        var ro = !v.can_edit;
        var st = (v.status || '').toLowerCase();
        var held = st !== 'ready';
        var wrap = h('div', 'fp-open fp-o'), left = h('div', 'fp-o-left'), right = h('div', 'fp-o-right');
        wrap.appendChild(left); wrap.appendChild(right);
        var dirty = {};
        function msg(text, kind) { return h('p', 'fp-o-msg' + (kind ? ' ' + kind : ''), text); }

        /* ================= LEFT: Basics ================= */
        // Title only (frame 9376:105215): "Who can watch" is fixed at creation and lives under More options.
        var basics = h('div', 'fp-o-card fp-o-basics');
        var tf = h('label', 'fp-o-field'); tf.appendChild(h('span', 'fp-o-lab', __('Title', 'fastpix-io')));
        var titleIn = h('input', 'fp-o-in'); titleIn.type = 'text'; titleIn.placeholder = __('Title', 'fastpix-io'); titleIn.value = v.title || ''; titleIn.disabled = ro; tf.appendChild(titleIn); basics.appendChild(tf);
        basics.appendChild(h('p', 'fp-o-hint', __('Edits here are sent to FastPix, and a title changed on the FastPix dashboard shows up here.', 'fastpix-io')));
        if (v.other_workspace) {   // kept for its embeds; nothing here can reach FastPix with the current credentials
            basics.appendChild(msg(__('This video belongs to a previously connected FastPix workspace. Posts that embed it are unchanged; reconnect that workspace to manage it.', 'fastpix-io'), 'warn'));
        }
        if (v.suggestions && v.suggestions.title && !ro) {
            var sug = msg(__('The FastPix dashboard has a different title: ', 'fastpix-io')); sug.appendChild(h('b', null, v.suggestions.title)); sug.appendChild(document.createTextNode(' '));
            sug.appendChild(bt(__('Use it', 'fastpix-io'), 'fp-o-link', function () { api('PATCH', '/videos/' + id, { suggestion: 'accept_title' }).then(function () { refreshOpen(tr, id); query(false); }); }));
            sug.appendChild(bt(__('Keep mine', 'fastpix-io'), 'fp-o-link', function () { api('PATCH', '/videos/' + id, { suggestion: 'dismiss' }).then(function () { refreshOpen(tr, id); }); }));
            basics.appendChild(sug);
        }
        if (st === 'failed' && !ro) {
            // No platform call re-processes a failed media; the honest way back is a fresh upload. [QA L16]
            var f = msg(__('This video could not be processed', 'fastpix-io') + (v.error_code ? ' — ' + v.error_code : '') + '. ' + __('Nothing was published and nothing was charged. Upload it again from', 'fastpix-io') + ' ', 'err');
            var again = h('a', 'fp-o-link', __('Add media', 'fastpix-io')); again.href = cfg.addMediaUrl; f.appendChild(again); f.appendChild(document.createTextNode('.')); basics.appendChild(f);
        }
        if (st === 'unavailable') {
            var g = msg(__('This video no longer exists on FastPix. Nothing here has been deleted and no post has been edited; posts using it show the saved poster and a link. ', 'fastpix-io'), 'err');
            if (cfg.canDelete && !ro) { g.appendChild(bt(__('Remove from library', 'fastpix-io'), 'fp-o-link del', function () { removeVideo(id, v.title); })); }
            basics.appendChild(g);
        }
        left.appendChild(basics);

        /* ================= LEFT: Subtitles + Extras ================= */
        var two = h('div', 'fp-o-two'); two.appendChild(subsSec(v, tr, id, held, ro)); two.appendChild(aiSec(v, tr, id, ro)); left.appendChild(two);

        /* ================= RIGHT: Poster image ================= */
        var on = { controls: true, click: true, keys: true };   // the rest of the chips start off
        var lesson = { track: false, at: 90 };
        var mode = 'frame', frameT = 1, posterUrl = '';
        var isPublic = !!(v.playback_id && v.access_policy === 'public');
        function thumbUrl(t) { return cfg.imageBase + '/' + encodeURIComponent(v.playback_id) + '/thumbnail.png?time=' + t + '&width=184'; }
        function fmt(s) { s = Number(s) || 0; return Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0'); }

        var pc = h('div', 'fp-o-card fp-o-poster'); pc.appendChild(titleRow(__('Poster image', 'fastpix-io'), __('The still shown before playback. Pick a frame from the video or use your own image URL.', 'fastpix-io')));
        var prow = h('div', 'fp-o-prow'), thumb = h('span', 'fp-o-thumb'), tt = h('span', 'fp-o-tbadge', '0:01'); thumb.appendChild(tt); prow.appendChild(thumb);
        var pinfo = h('div', 'fp-o-pinfo'), pline = h('div', 'fp-o-pline'), plab = h('span', null, __('Using the frame at ', 'fastpix-io')), ptime = h('span', 'fp-o-mono', '0:01');
        pline.appendChild(plab); pline.appendChild(ptime); pinfo.appendChild(pline);
        var pbtns = h('div', 'fp-o-pbtns');
        var pickBtn = bt(__('Pick a frame', 'fastpix-io'), 'fp-o-btn sm accent'), urlBtn = bt(__('Use image URL', 'fastpix-io'), 'fp-o-btn sm');
        pickBtn.setAttribute('aria-expanded', 'false'); urlBtn.setAttribute('aria-expanded', 'false');
        pbtns.appendChild(pickBtn); pbtns.appendChild(urlBtn); pinfo.appendChild(pbtns); prow.appendChild(pinfo); pc.appendChild(prow);

        var fm = h('div', 'fp-o-reveal'); fm.hidden = true;
        // ponytail: no mini player any more to read the real length from — an unsynced duration falls back to a 60 s slider; the number box stays uncapped.
        var max = Math.floor(v.duration || 0) || 60;
        var range = h('input'); range.type = 'range'; range.min = 0; range.max = max; range.value = 1; range.setAttribute('aria-label', __('Poster frame', 'fastpix-io')); range.disabled = held; fm.appendChild(range);
        var fr = h('div', 'fp-o-row'), num = h('input', 'fp-o-in num'); num.type = 'number'; num.min = 0; if (v.duration) { num.max = max; } num.value = 1; num.disabled = held; num.setAttribute('aria-label', __('Poster frame (seconds)', 'fastpix-io'));
        fr.appendChild(num); fr.appendChild(h('span', 'fp-o-hint', __('seconds in', 'fastpix-io'))); fm.appendChild(fr);
        var um = h('div', 'fp-o-reveal'); um.hidden = true;
        var urlIn = h('input', 'fp-o-in'); urlIn.type = 'text'; urlIn.placeholder = 'https://example.com/poster.jpg'; urlIn.setAttribute('aria-label', __('Poster image URL', 'fastpix-io')); um.appendChild(urlIn);
        pc.appendChild(fm); pc.appendChild(um); right.appendChild(pc);

        function paintPoster() {
            var useUrl = mode === 'url' && posterUrl;
            plab.textContent = useUrl ? __('Using your image', 'fastpix-io') : __('Using the frame at ', 'fastpix-io');
            ptime.hidden = !!useUrl; ptime.textContent = tt.textContent = fmt(frameT);
            thumb.style.backgroundImage = useUrl ? 'url(' + JSON.stringify(posterUrl) + ')' : (isPublic ? 'url(' + thumbUrl(frameT) + ')' : '');
            tt.hidden = !!useUrl;
            pickBtn.setAttribute('aria-expanded', String(!fm.hidden)); urlBtn.setAttribute('aria-expanded', String(!um.hidden));
            build();
        }
        function sync(val) { frameT = Math.max(0, parseInt(val, 10) || 0); range.value = frameT; num.value = frameT; paintPoster(); }
        range.addEventListener('input', function () { sync(range.value); }); num.addEventListener('input', function () { sync(num.value); });
        urlIn.addEventListener('input', function () { posterUrl = urlIn.value.trim(); paintPoster(); });
        pickBtn.addEventListener('click', function () { mode = 'frame'; um.hidden = true; fm.hidden = !fm.hidden; paintPoster(); });
        urlBtn.addEventListener('click', function () { mode = 'url'; fm.hidden = true; um.hidden = !um.hidden; paintPoster(); if (!um.hidden) { urlIn.focus(); } });

        /* ================= RIGHT: Player options ================= */
        var po = h('div', 'fp-o-card fp-o-player'); po.appendChild(titleRow(__('Player options', 'fastpix-io'), __('How this video plays where you embed it. The shortcode updates as you toggle.\n\nPlayer controls: the play button, progress bar, volume and fullscreen. Off gives a bare video.\nChapters: chapter markers on the progress bar so viewers can jump to a section. Needs the Chapters extra.\nTranscript: a written, timestamped text block under the video (not the captions — the player shows those on its own from the subtitle track). It appears only once a transcript has been captured for this video.\nClick to play: shows the poster and waits for a click instead of loading the video at once.\n\nAutoplay, Muted and Loop do what they say. Autoplay only works while muted.', 'fastpix-io')));
        var chips = h('div', 'fp-o-chips');
        [['controls', __('Player controls', 'fastpix-io')], ['autoplay', __('Autoplay', 'fastpix-io')], ['muted', __('Muted', 'fastpix-io')], ['loop', __('Loop', 'fastpix-io')], ['chapters', __('Chapters', 'fastpix-io')], ['transcript', __('Transcript', 'fastpix-io')], ['click', __('Click to play', 'fastpix-io')]].forEach(function (k) {
            var c = bt('', 'fp-o-chip');
            if (k[0] === 'transcript' && !(v.ai || []).some(function (r) { return r.kind === 'transcript' && r.state === 'ready'; })) {
                c.disabled = true; c.title = __('No transcript for this video yet — it is captured from the subtitle track once subtitles exist.', 'fastpix-io');
            }
            function paint() { c.textContent = k[1] + (on[k[0]] ? ' ✓' : ''); c.setAttribute('aria-pressed', String(!!on[k[0]])); }
            c.addEventListener('click', function () { on[k[0]] = !on[k[0]]; paint(); build(); });
            paint(); chips.appendChild(c);
        });
        po.appendChild(chips);
        // Course lesson (LMS features on): track_viewer + complete_at ride the
        // shortcode/block and only take effect on lesson post types.
        var atIn = h('input', 'fp-o-in num'); atIn.type = 'number'; atIn.min = 10; atIn.max = 100; atIn.value = 90; atIn.setAttribute('aria-label', __('Complete at (percent watched)', 'fastpix-io'));
        atIn.addEventListener('input', function () { var n = parseInt(atIn.value, 10); lesson.at = (n >= 10 && n <= 100) ? n : 90; build(); });
        if (cfg.lms) {
            var ls = h('div', 'fp-o-lesson'); ls.appendChild(h('h3', 'fp-o-h', __('Course lessons', 'fastpix-io')));
            var tv = bt(__('Track viewer', 'fastpix-io'), 'fp-o-chip mini');
            // Owner 2026-09-09: Complete at belongs to Track viewer — it lights up with it and rides the shortcode/block whenever tracking is on (90 included).
            function paintTv() { tv.textContent = __('Track viewer', 'fastpix-io') + (lesson.track ? ' ✓' : ''); tv.setAttribute('aria-pressed', String(lesson.track)); atIn.disabled = !lesson.track; atIn.classList.toggle('on', lesson.track); }
            tv.addEventListener('click', function () { lesson.track = !lesson.track; paintTv(); build(); }); paintTv();
            atIn.className = 'fp-o-in mini';
            var lr = h('div', 'fp-o-row'); lr.appendChild(tv); lr.appendChild(h('span', 'fp-o-hint', __('Complete at', 'fastpix-io'))); lr.appendChild(atIn);
            ls.appendChild(lr); ls.appendChild(h('p', 'fp-o-hint fp-o-lhint', __('% watched — on lesson pages', 'fastpix-io'))); po.appendChild(ls);
        }
        right.appendChild(po);

        /* ================= RIGHT: Shortcode ================= */
        var code = h('code', 'fp-o-mono');
        var scc = h('div', 'fp-o-card fp-o-shortcode'); scc.appendChild(titleRow(__('Shortcode', 'fastpix-io'), __('Paste this into any post or page. It carries the poster and player options chosen here.', 'fastpix-io')));
        var scrow = h('div', 'fp-o-scrow'), codeBox = h('div', 'fp-o-code'); codeBox.appendChild(code); scrow.appendChild(codeBox); scc.appendChild(scrow);
        var scr = h('div', 'fp-o-scbtns');
        var copyBtn = bt(__('Copy shortcode', 'fastpix-io'), 'fp-o-btn sm accent', function () { copyText(code.textContent, null); var w = copyBtn.textContent; copyBtn.textContent = __('Copied', 'fastpix-io'); setTimeout(function () { copyBtn.textContent = w; }, 1100); });
        var blockBtn = bt(__('Insert block', 'fastpix-io'), 'fp-o-btn sm', function () {
            // The block form of the same embed, ready to paste into the editor (Ctrl/Cmd+V on a new line).
            var attrs = { videoId: v.media_id }; ['autoplay', 'muted', 'loop'].forEach(function (k) { if (on[k]) { attrs[k] = true; } }); if (on.chapters) { attrs.showChapters = true; } if (on.transcript) { attrs.showTranscript = true; }
            if (!on.controls) { attrs.controls = false; }
            if (!on.click) { attrs.clickToPlay = false; } if (!on.keys) { attrs.keyboard = false; }
            var st2 = parseInt(startIn.value, 10) || 0; if (st2 > 0) { attrs.startTime = st2; }
            if (lesson.track) { attrs.trackViewer = true; }
            if (lesson.track) { attrs.completeAt = lesson.at; }
            if (colourIn.value && colourIn.value.toLowerCase() !== '#6d22cd') { attrs.accentColour = colourIn.value; }
            if (mode === 'frame' && frameT !== 1) { attrs.thumbnailTime = frameT; } if (mode === 'url' && posterUrl) { attrs.poster = posterUrl; }
            copyText('<!-- wp:fastpix/video ' + JSON.stringify(attrs) + ' /-->', null); var w = blockBtn.textContent; blockBtn.textContent = __('Block copied — paste in the editor', 'fastpix-io'); setTimeout(function () { blockBtn.textContent = w; }, 1800);
        });
        scr.appendChild(copyBtn); scr.appendChild(blockBtn); scrow.appendChild(scr); left.appendChild(scc);

        /* ================= RIGHT: Cancel / Save ================= */
        var acts = h('div', 'fp-o-acts'), stateEl = h('span', 'fp-o-state', '');
        var cancelBtn = bt(__('Cancel', 'fastpix-io'), 'fp-o-btn', function () { toggleOpen(tr, id); });
        var saveBtn = bt(__('Save changes', 'fastpix-io'), 'fp-o-btn primary', null); saveBtn.disabled = true;
        acts.appendChild(stateEl); acts.appendChild(cancelBtn); acts.appendChild(saveBtn); right.appendChild(acts);

        /* ================= RIGHT: More options ================= */
        var more = h('details', 'fp-o-more'), sum = h('summary', null, __('More options', 'fastpix-io')); more.appendChild(sum);
        var mb = h('div', 'fp-o-morebody'); more.appendChild(mb);
        function mrow(label, fill) { var r = h('div', 'fp-o-mrow'); r.appendChild(h('span', 'fp-o-mlab', label)); var c = h('div', 'fp-o-mval'); fill(c); r.appendChild(c); mb.appendChild(r); return r; }
        function check(label, checked, fn) { var l = h('label', 'fp-o-check'), cb = h('input'); cb.type = 'checkbox'; cb.checked = checked; cb.addEventListener('change', function () { fn(cb.checked); }); l.appendChild(cb); l.appendChild(h('span', null, label)); return l; }

        // Downloadable file — FastPix mp4Support: none | capped_4k | audioOnly | audioOnly,capped_4k (docs).
        var drm = v.access_policy === 'drm';
        var dlSel = h('select', 'fp-o-in'); dlSel.setAttribute('aria-label', __('Downloadable file', 'fastpix-io'));
        [['off', __('Off', 'fastpix-io')], ['video', __('Video (MP4)', 'fastpix-io')], ['audio', __('Audio only (M4A)', 'fastpix-io')], ['both', __('Video + audio', 'fastpix-io')]].forEach(function (o) { var op = document.createElement('option'); op.value = o[0]; op.textContent = o[1]; dlSel.appendChild(op); });
        var dlSaved = v.mp4_support || 'off'; dlSel.value = dlSaved; dlSel.disabled = drm || held || ro;
        mrow(__('Downloadable file', 'fastpix-io'), function (c) { c.appendChild(dlSel); c.appendChild(h('span', 'fp-o-hint', drm ? __('Unavailable with DRM — protection applies to streaming only.', 'fastpix-io') : __('Let visitors save a copy. Separate from who can watch it.', 'fastpix-io'))); });

        mrow(__('Who can watch', 'fastpix-io'), function (c) { c.textContent = ACCESS_LABEL[v.access_policy] || ACCESS_LABEL.public; c.title = __('Set when the video was created — it cannot change here.', 'fastpix-io'); });
        mrow(__('Keyboard shortcuts', 'fastpix-io'), function (c) { c.appendChild(check(__('Let viewers use the keyboard', 'fastpix-io'), true, function (val) { on.keys = val; build(); })); });

        var startIn = h('input', 'fp-o-in num'); startIn.type = 'number'; startIn.min = 0; startIn.value = 0; startIn.setAttribute('aria-label', __('Start at (seconds)', 'fastpix-io')); startIn.addEventListener('input', build);
        mrow(__('Start at', 'fastpix-io'), function (c) { c.appendChild(startIn); c.appendChild(h('span', 'fp-o-hint', __('seconds', 'fastpix-io'))); });

        var colourIn = h('input', 'fp-o-colour'); colourIn.type = 'color'; colourIn.value = '#6d22cd'; colourIn.setAttribute('aria-label', __('Accent colour', 'fastpix-io')); colourIn.addEventListener('input', build);
        mrow(__('Accent colour', 'fastpix-io'), function (c) { c.appendChild(colourIn); });

        function copyIcon(text, label) { var b = bt('⧉', 'fp-o-cp', function () { copyText(text, b); }); b.setAttribute('aria-label', label); return b; }
        function idRow(label, value) { mrow(label, function (c) { if (value) { c.appendChild(h('code', 'fp-o-mono', value)); c.appendChild(copyIcon(value, /* translators: %s: what is copied, e.g. Media ID (QA L25) */ sprintf(__('Copy %s', 'fastpix-io'), label))); } else { c.textContent = '—'; } }); }
        idRow(__('Media ID', 'fastpix-io'), v.media_id);
        idRow(__('Playback ID', 'fastpix-io'), v.playback_id);
        mrow(__('Duration', 'fastpix-io'), function (c) { c.appendChild(h('span', 'fp-o-mono', v.duration ? hhmmss(v.duration) : '—')); });
        mrow(__('Max resolution', 'fastpix-io'), function (c) { c.textContent = v.max_resolution || '—'; });
        mrow(__('Aspect ratio', 'fastpix-io'), function (c) { c.textContent = v.aspect_ratio || '—'; });
        mrow(__('Source', 'fastpix-io'), function (c) { c.textContent = v.source || '—'; });
        mrow(__('Quality tier', 'fastpix-io'), function (c) { c.textContent = v.quality_tier || '—'; });
        if (v.migration && v.migration.path) {
            mrow(__('Came from', 'fastpix-io'), function (c) { c.appendChild(h('code', 'fp-o-mono', v.migration.path)); });
            if (!v.migration.reverted_at && !v.migration.cleaned && cfg.canManage) {
                mrow(__('Local file', 'fastpix-io'), function (c) { c.appendChild(document.createTextNode(__('Still on disk ', 'fastpix-io'))); c.appendChild(bt(__('Revert to local', 'fastpix-io'), 'fp-o-link', function () {
                    var warn = v.access_policy !== 'public' ? '\n\n' + sprintf(__('This video is %s on FastPix. Reverting returns it to an unprotected file on this server.', 'fastpix-io'), v.access_policy) : '';
                    fpDialog.confirm({ title: sprintf(__('Restore local playback for "%s"?', 'fastpix-io'), v.title || __('this video', 'fastpix-io')), message: __('Posts go back to playing the local file. The FastPix copy stays.', 'fastpix-io') + warn, ok: __('Revert', 'fastpix-io') }).then(function (ok) {
                        if (!ok) { return; }
                        api('POST', '/migration/items/' + v.migration.item_id + '/revert').then(function () { refreshOpen(tr, id); });
                    });
                })); });
            } else if (v.migration.reverted_at) { mrow(__('Local file', 'fastpix-io'), function (c) { c.textContent = __('Playing locally since ', 'fastpix-io') + v.migration.reverted_at.slice(0, 10); }); }
        }
        if (cfg.analyticsUrl) {
            // A single video's numbers only mean anything beside the rest of the library — they live on Analytics, one click away.
            var anl = h('a', 'fp-o-link fp-o-anl', __('View analytics →', 'fastpix-io')); anl.href = cfg.analyticsUrl + '&video=' + id; mb.appendChild(anl);
        }
        // Owner 2026-09-09: the "More options" disclosure is not shown. Its controls still exist
        // (unmounted) because the shortcode/block builder and Save read their defaults.
        // ponytail: strip the builder's dependence on them if this stays gone.

        /* ================= save + shortcode ================= */
        function renderDirty() { var n = Object.keys(dirty).length; saveBtn.disabled = !n || ro; stateEl.textContent = n ? sprintf(_n('%d unsaved change', '%d unsaved changes', n, 'fastpix-io'), n) : ''; }
        titleIn.addEventListener('input', function () { if (titleIn.value !== (v.title || '')) { dirty.title = true; } else { delete dirty.title; } renderDirty(); });
        dlSel.addEventListener('change', function () { if (dlSel.value !== dlSaved) { dirty.download = true; } else { delete dirty.download; } renderDirty(); });
        saveBtn.addEventListener('click', function () {
            saveBtn.disabled = true; stateEl.textContent = __('Saving…', 'fastpix-io');
            var body = {}; if (dirty.title) { body.title = titleIn.value; } if (dirty.download) { body.downloadable = dlSel.value; }
            api('PATCH', '/videos/' + id, body).then(function (r) {
                if (!r.ok) { stateEl.textContent = (r.json && r.json.message) || __('Not saved.', 'fastpix-io'); saveBtn.disabled = false; return; }
                v.title = titleIn.value; v.mp4_support = dlSel.value; dlSaved = dlSel.value; dirty = {}; renderDirty(); stateEl.textContent = __('Saved', 'fastpix-io');
                var t = tr.querySelector('.vtitle'); if (t) { t.textContent = v.title || __('(untitled)', 'fastpix-io'); }
            });
        });
        function build() {
            var out = '[fastpix id="' + v.media_id + '"';
            ['autoplay', 'muted', 'loop', 'chapters', 'transcript'].forEach(function (k) { if (on[k]) { out += ' ' + k; } });
            if (!on.controls) { out += ' nocontrols'; }
            if (!on.click) { out += ' noclick'; } if (!on.keys) { out += ' nokeys'; }
            var s = parseInt(startIn.value, 10) || 0; if (s > 0) { out += ' starttime="' + s + '"'; }
            if (lesson.track) { out += ' track_viewer'; }
            if (lesson.track) { out += ' complete_at="' + lesson.at + '"'; }
            if (colourIn.value && colourIn.value.toLowerCase() !== '#6d22cd') { out += ' accentcolor="' + colourIn.value + '"'; }
            if (mode === 'frame') { if (frameT !== 1) { out += ' poster="' + frameT + 's"'; } } else if (posterUrl) { out += ' poster="' + posterUrl.replace(/"/g, '') + '"'; }
            code.textContent = out + ']';
            if (state.open && state.open.id === id) { state.open.shortcode = code.textContent; }   // the row's Shortcode button copies this
        }
        paintPoster();
        return wrap;
    }

    /* Subtitles card: "Generate — <Language> ▾" (Regenerate when it exists) or "Upload file" (.vtt/.srt, sent at once), then the tracks. */
    function subsSec(v, tr, id, held, ro) {
        var card = h('div', 'fp-o-card fp-o-subs'); card.dataset.part = 'subs';
        var tracks = (v.tracks || []).filter(function (t) { return !t.type || t.type === 'subtitle'; });
        var head = h('div', 'fp-o-head'); head.appendChild(h('h3', 'fp-o-h', __('Subtitles', 'fastpix-io'))); head.appendChild(h('span', 'fp-o-count', sprintf(_n('%d language', '%d languages', tracks.length, 'fastpix-io'), tracks.length))); card.appendChild(head);
        card.appendChild(h('p', 'fp-o-body', __('Written from the audio for you, or upload your own .vtt / .srt file.', 'fastpix-io')));
        var byCode = {}; tracks.forEach(function (t) { byCode[String(t.language_code || '').split('-')[0]] = t; });
        var ferr = h('p', 'fp-o-msg err'); ferr.hidden = true;
        function showErr(m) { ferr.textContent = m; ferr.hidden = false; }
        // The card is only rebuilt on success — a rebuild would wipe the error it just showed. [QA L14]
        function patchTracks(ops, then) { return api('PATCH', '/videos/' + id, { tracks: ops }).then(function (r) { if (then) { then(r); } if (r.ok) { refreshPart(tr, id, 'subs'); } else { showErr((r.json && r.json.message) || __('Could not update the subtitle track.', 'fastpix-io')); } }); }

        if (!ro) {
            var row = h('div', 'fp-o-btns'), code = LANGUAGES[0][0];
            // Split button: the label generates, the caret opens the language list.
            var split = h('div', 'fp-o-split'), gen = bt('', 'fp-o-btn primary'), caret = bt('▾', 'fp-o-btn primary caret');
            caret.setAttribute('aria-haspopup', 'listbox'); caret.setAttribute('aria-expanded', 'false'); caret.setAttribute('aria-label', __('Choose a language', 'fastpix-io'));
            var menu = h('ul', 'fp-o-menu'); menu.setAttribute('role', 'listbox'); menu.hidden = true;
            menu.id = 'fp-o-langs-' + id; caret.setAttribute('aria-controls', menu.id);   // [QA L24]
            var items = LANGUAGES.map(function (lg) {
                var li = h('li'), b = bt(langLabel(lg[0]), 'fp-o-mi', function () { code = lg[0]; label(); openMenu(false); gen.focus(); });
                b.setAttribute('role', 'option'); li.appendChild(b); menu.appendChild(li); return b;
            });
            function label() {
                var ex = byCode[code];
                gen.textContent = sprintf(ex ? __('Regenerate — %s', 'fastpix-io') : __('Generate — %s', 'fastpix-io'), langLabel(code)); gen.title = ex ? __('Already added — this replaces it', 'fastpix-io') : __('From the audio track', 'fastpix-io');
                items.forEach(function (b, i) { b.setAttribute('aria-selected', String(LANGUAGES[i][0] === code)); });
            }
            function openMenu(open) { menu.hidden = !open; caret.setAttribute('aria-expanded', String(open)); if (open) { flipMenu(menu); var cur = menu.querySelector('[aria-selected="true"]'); if (cur) { cur.focus(); } } }
            caret.addEventListener('click', function (e) { e.stopPropagation(); openMenu(menu.hidden); });
            // Arrow keys open the list and walk it; Escape closes it. (Outside clicks close it via the one document listener.) [QA L24]
            split.addEventListener('keydown', function (e) {
                var i = items.indexOf(document.activeElement), n = items.length, down = e.key === 'ArrowDown';
                if (e.key === 'Escape' && !menu.hidden) { openMenu(false); caret.focus(); }
                else if (down || e.key === 'ArrowUp') { e.preventDefault(); if (menu.hidden) { openMenu(true); return; } items[i < 0 ? (down ? 0 : n - 1) : (i + (down ? 1 : n - 1)) % n].focus(); }
            });
            gen.disabled = caret.disabled = held;
            gen.addEventListener('click', function () {
                var ex = byCode[code], name = langName(code);
                gen.disabled = true; var was = gen.textContent; gen.textContent = __('Queued…', 'fastpix-io');
                var op = ex ? { action: 'generate', track_id: ex.track_id, language_code: code, language_name: name } : { action: 'generate', language_code: code, language_name: name };
                patchTracks([op], function (r) { if (!r.ok) { gen.disabled = false; gen.textContent = was; } });
            });
            split.appendChild(gen); split.appendChild(caret); split.appendChild(menu); row.appendChild(split);

            var fin = h('input'); fin.type = 'file'; fin.accept = '.vtt,.srt'; fin.hidden = true; fin.tabIndex = -1; fin.setAttribute('aria-hidden', 'true');
            // Upload has its own language — any BCP 47 tag, not just the generate list. Same popup as the
            // generate menu (a native select opens ~250 rows full-height), with a search like the dashboard's.
            var upCode = 'en', upWrap = h('div', 'fp-o-split');
            var upLang = bt('', 'fp-o-btn outline fp-o-uplang', function (e) { e.stopPropagation(); upOpen(upMenu.hidden); });
            upLang.setAttribute('aria-haspopup', 'listbox'); upLang.setAttribute('aria-expanded', 'false'); upLang.disabled = held;
            var upMenu = h('div', 'fp-o-menu fp-o-upmenu'); upMenu.hidden = true;
            var upFind = h('input', 'fp-o-in fp-o-upfind'); upFind.type = 'search'; upFind.placeholder = __('Search…', 'fastpix-io'); upFind.setAttribute('aria-label', __('Search languages', 'fastpix-io'));
            var upList = h('ul'); upList.setAttribute('role', 'listbox'); upList.setAttribute('aria-label', __('Language of the subtitle file', 'fastpix-io'));
            var upItems = UPLOAD_LANGUAGES.map(function (lg) {
                var li = h('li'), b = bt(lg[1] + ' (' + lg[0] + ')', 'fp-o-mi', function () { upPick(lg[0]); upOpen(false); upLang.focus(); });
                b.setAttribute('role', 'option'); li.appendChild(b); upList.appendChild(li); return { li: li, b: b, code: lg[0], text: (lg[1] + ' ' + lg[0]).toLowerCase() };
            });
            function upPick(c) { upCode = c; upLang.textContent = langName(c) + ' (' + c + ') ▾'; upItems.forEach(function (it) { it.b.setAttribute('aria-selected', String(it.code === c)); }); }
            function upOpen(open) { upMenu.hidden = !open; upLang.setAttribute('aria-expanded', String(open)); if (open) { upFind.value = ''; upFilter(); flipMenu(upMenu); upFind.focus(); } }
            function upFilter() { var q = upFind.value.trim().toLowerCase(); upItems.forEach(function (it) { it.li.hidden = q !== '' && it.text.indexOf(q) === -1; }); }
            upFind.addEventListener('input', upFilter);
            upFind.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') { e.preventDefault(); var first = upItems.filter(function (it) { return !it.li.hidden; })[0]; if (first) { first.b.click(); } }
                else if (e.key === 'Escape') { e.stopPropagation(); upOpen(false); upLang.focus(); }
            });
            upMenu.appendChild(upFind); upMenu.appendChild(upList); upWrap.appendChild(upLang); upWrap.appendChild(upMenu);
            upPick('en');
            var upBtn = bt(__('Upload file', 'fastpix-io'), 'fp-o-btn outline', function () { fin.click(); }); upBtn.disabled = held;
            upBtn.title = __('Upload a .vtt / .srt file in the chosen language (up to 2 MB)', 'fastpix-io');
            var prog = h('span', 'fp-o-prog'); prog.hidden = true; var barI = h('i'); prog.appendChild(barI);
            fin.addEventListener('change', function () {
                var f = fin.files[0]; if (!f) { return; }
                ferr.hidden = true;
                if (!/\.(vtt|srt)$/i.test(f.name)) { fin.value = ''; return showErr(__('That is not a .vtt or .srt file.', 'fastpix-io')); }
                if (f.size > 2 * 1024 * 1024) { fin.value = ''; return showErr(__('That file is over 2 MB.', 'fastpix-io')); }
                // Same language replaces its track: exact tag first; a bare tag also matches its regional form (en ↔ en-US).
                var up = upCode, ex = tracks.filter(function (t) { return t.language_code === up; })[0] || (up.indexOf('-') === -1 ? byCode[up] : null);
                upBtn.disabled = true; prog.hidden = false; barI.style.width = '0';
                var form = new FormData(); form.append('file', f); form.append('language_code', up); form.append('language_name', langName(up)); if (ex) { form.append('track_id', ex.track_id); }
                var xhr = new XMLHttpRequest(); xhr.open('POST', cfg.restUrl + '/videos/' + id + '/track-file'); xhr.setRequestHeader('X-WP-Nonce', cfg.nonce); xhr.withCredentials = true;
                xhr.upload.onprogress = function (e) { if (e.lengthComputable) { barI.style.width = Math.round(e.loaded / e.total * 100) + '%'; } };
                var fail = function (m) { upBtn.disabled = false; prog.hidden = true; fin.value = ''; showErr(m); };
                xhr.onload = function () { barI.style.width = '100%'; if (xhr.status >= 200 && xhr.status < 300) { setTimeout(function () { refreshPart(tr, id, 'subs'); }, 300); } else { var j = {}; try { j = JSON.parse(xhr.responseText); } catch (e2) {} fail(j.message || __('The upload did not complete.', 'fastpix-io')); } };
                xhr.onerror = function () { fail(__('The upload did not complete.', 'fastpix-io')); };
                xhr.send(form);
            });
            var upGrp = h('div', 'fp-o-upgrp'); upGrp.appendChild(upWrap); upGrp.appendChild(upBtn); row.appendChild(upGrp); row.appendChild(fin); row.appendChild(prog);
            label();
            card.appendChild(row);
            if (held) { card.appendChild(h('p', 'fp-o-hint', __('Subtitle tracks can be added once processing finishes.', 'fastpix-io'))); }
        }
        card.appendChild(ferr);

        if (tracks.length) {
            var ul = h('ul', 'fp-o-tracks');
            tracks.forEach(function (t) {
                var s = (t.state || '').toLowerCase(), li = h('li');
                var n = h('span', 'fp-o-tname', (langName(t.language_code) || '?') + ' '); n.appendChild(h('span', 'fp-o-mono faint', t.language_code || '')); li.appendChild(n);
                li.appendChild(s === 'failed' ? pill(__('Failed', 'fastpix-io'), 'err') : (s === 'ready' || s === 'available' ? pill(__('Ready', 'fastpix-io'), 'ok') : pill(__('Generating', 'fastpix-io'), 'busy')));
                li.appendChild(h('span', 'fp-o-tsrc', (t.source === 'generated' || s === 'generating' ? __('auto', 'fastpix-io') : (t.source === 'uploaded' || !t.source ? __('uploaded', 'fastpix-io') : t.source)) + (t.updated_at ? ' · ' + timeAgo(t.updated_at, true) : '')));
                var acts = h('span', 'fp-o-tacts');
                if (!ro) {
                    if (s === 'failed') { acts.appendChild(bt(__('Retry', 'fastpix-io'), 'fp-o-link', function () { patchTracks([{ action: 'generate', track_id: t.track_id, language_code: t.language_code, language_name: langName(t.language_code) }]); })); }
                    acts.appendChild(bt(__('Remove', 'fastpix-io'), 'fp-o-link del', function () {   // deletes on FastPix too — ask first [QA L17]
                        fpDialog.confirm({ title: sprintf(__('Remove the %s subtitles?', 'fastpix-io'), langName(t.language_code)), message: __('The track is deleted on FastPix as well. Generate or upload it again to bring it back.', 'fastpix-io'), ok: __('Remove', 'fastpix-io'), danger: true }).then(function (ok) {
                            if (ok) { patchTracks([{ action: 'remove', track_id: t.track_id }]); }
                        });
                    }));
                }
                li.appendChild(acts); ul.appendChild(li);
            });
            card.appendChild(ul);
        }
        return card;
    }

    /* Extras card: one row per AI output — the name and either "Completed" (toggles the output) or a Generate button. */
    var AI_OFFER = ['chapters', 'summary', 'entities'];
    function aiOutputText(kind, o) {
        if (o == null || o === '') { return __('(empty)', 'fastpix-io'); }
        if (kind === 'chapters' && o.chapters) { return o.chapters.map(function (c) { return (c.startTime || '') + '–' + (c.endTime || '') + '  ' + (c.title || '') + (c.summary ? ' — ' + c.summary : ''); }).join('\n'); }
        if (kind === 'entities' && o.namedEntities) { return o.namedEntities.map(function (e) { return e.entity + (e.category ? ' (' + e.category + ')' : ''); }).join('\n'); }
        return typeof o === 'string' ? o : JSON.stringify(o, null, 2);
    }
    function aiSec(v, tr, id, ro) {
        var card = h('div', 'fp-o-card fp-o-extras'); card.dataset.part = 'ai';
        card.appendChild(titleRow(__('Extras, written by AI', 'fastpix-io'), __('FastPix writes these from what is said in the video, only when you press Generate.\n\nChapters: splits the video into timed sections viewers can jump to.\nSummary: a short paragraph describing what the video is about.\nPeople & places mentioned: the names, brands and locations that come up.\n\nThe results are saved here and used for search.', 'fastpix-io')));
        var byKind = {}; (v.ai || []).forEach(function (r) { byKind[r.kind] = r; });
        var ul = h('ul', 'fp-o-ai');
        var aerr = h('p', 'fp-o-msg err'); aerr.hidden = true;   // a refused request is said, not swallowed [QA L15]
        AI_OFFER.forEach(function (kind) {
            var r = byKind[kind], li = h('li'); li.appendChild(h('span', 'fp-o-ailab', aiLabel(kind)));
            if (r && r.state === 'ready') {
                // Completed is verifiable: the pill toggles the output FastPix returned.
                var done = bt(__('Completed', 'fastpix-io'), 'fp-o-pill ok toggle', null); done.setAttribute('aria-expanded', 'false');
                var out = h('pre', 'fp-o-out'); out.hidden = true; out.textContent = aiOutputText(kind, r.output);
                done.addEventListener('click', function () { out.hidden = !out.hidden; done.setAttribute('aria-expanded', out.hidden ? 'false' : 'true'); });
                li.appendChild(done); li.appendChild(out);
            } else {
                var g = bt(r && r.state === 'failed' ? __('Retry', 'fastpix-io') : __('Generate', 'fastpix-io'), 'fp-o-btn sm accent', function () {
                    g.disabled = true; aerr.hidden = true;
                    api('POST', '/videos/' + id + '/ai', { kind: kind }).then(function (res) {
                        if (res.ok) { refreshPart(tr, id, 'ai'); return; }
                        g.disabled = false; aerr.textContent = (res.json && res.json.message) || __('Could not start.', 'fastpix-io'); aerr.hidden = false;
                    });
                });
                if (ro || v.status !== 'Ready' || (r && r.state !== 'failed')) { g.disabled = true; }   // running, or nothing to run on yet
                if (r && r.state !== 'failed') { g.textContent = __('Working…', 'fastpix-io'); }
                li.appendChild(g);
            }
            ul.appendChild(li);
        });
        card.appendChild(ul); card.appendChild(aerr);
        // Outputs land in the background (webhook or the minute-apart read-back): while any is
        // still "Working…", re-read this card every 15 s so Completed appears on its own.
        if (AI_OFFER.some(function (k) { return byKind[k] && byKind[k].state !== 'ready' && byKind[k].state !== 'failed'; })) {
            setTimeout(function () { if (card.isConnected) { refreshPart(tr, id, 'ai'); } }, 15000);
        }
        return card;
    }

    /* Re-render one card of the opened panel in place (no rebuild, no scroll jump). */
    function refreshPart(tr, id, part) {
        var panel = state.open && state.open.id === id ? state.open.panel : null;
        var cur = panel && panel.querySelector('[data-part="' + part + '"]');
        if (!cur) { return; }
        api('GET', '/videos/' + id).then(function (res) {
            if (!res.ok || !cur.isConnected) { return; }
            var v = res.json, held = (v.status || '').toLowerCase() !== 'ready', ro = !v.can_edit;
            cur.replaceWith(part === 'ai' ? aiSec(v, tr, id, ro) : subsSec(v, tr, id, held, ro));
        });
    }

    function refreshOpen(tr, id) {
        if (state.open && state.open.id === id) {
            state.open.panel.remove();
            state.open = null;
            toggleOpen(tr, id);
        }
    }

    /* --------------------------------------------------------------- bulk */

    function syncBulk() {
        var count = Object.keys(state.selected).length;
        el.bulkbar.hidden = count === 0;
        el.bulkCount.textContent = sprintf(_n('%d selected', '%d selected', count, 'fastpix-io'), count);
        // The header box follows the rows: ticked = all, dash = some, clear = none. (QA L2)
        var rows = rowsEl.querySelectorAll('.fp-check').length;
        el.checkAll.checked = count > 0 && count >= rows;
        el.checkAll.indeterminate = count > 0 && count < rows;
    }

    el.checkAll.addEventListener('change', function (e) {
        rowsEl.querySelectorAll('.fp-check').forEach(function (cb) {
            cb.checked = e.target.checked;
            cb.closest('.fp-vrow').classList.toggle('sel', e.target.checked);
            var id = cb.closest('.fp-vrow').getAttribute('data-fp-id');
            if (e.target.checked) { state.selected[id] = true; } else { delete state.selected[id]; }
        });
        syncBulk();
    });

    el.bulkApply.addEventListener('click', function () {
        var action = el.bulkAction.value;
        if (!action) { el.bulkAction.focus(); return; }
        var body = { action: action, ids: Object.keys(state.selected).map(Number) };

        function send() {
            api('POST', '/videos/bulk', body).then(function (res) {
                if (res.ok) {
                    el.bulkbar.hidden = true;
                    state.selected = {};
                    query(true);   // queued in the background [FR-033]
                }
            });
        }
        if (action !== 'delete') { send(); return; }
        fpDialog.confirm({ title: sprintf(_n('Delete %d video from this site?', 'Delete %d videos from this site?', body.ids.length, 'fastpix-io'), body.ids.length), message: __('Nothing is deleted on FastPix unless you say so next.', 'fastpix-io'), ok: __('Delete', 'fastpix-io'), danger: true }).then(function (ok) {
            if (!ok) { return; }
            return fpDialog.confirm({ title: __('Also delete these videos on FastPix?', 'fastpix-io'), message: __('“Delete on FastPix too” removes them from your FastPix account as well — anything else that plays them stops working, and it can’t be undone. “Only this site” removes them from this library and leaves them on FastPix.', 'fastpix-io'), ok: __('Delete on FastPix too', 'fastpix-io'), cancel: __('Only this site', 'fastpix-io'), danger: true }).then(function (alsoPlatform) {
                if (alsoPlatform === null) { return; }   // Escape / backdrop = dismissed, not "only this site"
                body.delete_on_platform = alsoPlatform;
                send();
            });
        });
    });

    el.bulkClear.addEventListener('click', function () {
        state.selected = {};
        rowsEl.querySelectorAll('.fp-check').forEach(function (cb) { cb.checked = false; cb.closest('tr').classList.remove('sel'); });
        el.checkAll.checked = false;
        syncBulk();
    });

    /* ------------------------------------------------------------ filters */

    var debounce = null;
    el.search.addEventListener('input', function () {
        clearTimeout(debounce);
        debounce = setTimeout(function () { query(true); }, 350);
    });
    [el.status, el.access, el.source, el.perPage].forEach(function (select) {
        select.addEventListener('change', function () { query(true); });
    });
    el.dense.addEventListener('change', function () { el.table.classList.toggle('dense', el.dense.checked); });

    // ⚙ View popover — filters, rows per page, density.
    el.viewBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        var open = el.viewPop.hidden;
        el.viewPop.hidden = !open;
        el.viewBtn.setAttribute('aria-expanded', String(open));
    });

    // Table / Grid — the same rows, laid out as cards.
    function setView(view) {
        state.view = view;
        el.table.classList.toggle('cards', view === 'grid');
        placePanel();   // an open panel follows its row in either view
        el.table.classList.toggle('tbl-auto', view === 'table');
        el.viewTable.classList.toggle('on', view === 'table'); el.viewTable.setAttribute('aria-pressed', String(view === 'table'));
        el.viewGrid.classList.toggle('on', view === 'grid'); el.viewGrid.setAttribute('aria-pressed', String(view === 'grid'));
        try { window.localStorage.setItem('fastpix-lib-view', view); } catch (err) {}
    }
    el.viewTable.addEventListener('click', function () { setView('table'); });
    el.viewGrid.addEventListener('click', function () { setView('grid'); });
    var savedView = 'table';
    try { savedView = window.localStorage.getItem('fastpix-lib-view') || 'table'; } catch (err) {}
    setView(savedView === 'grid' ? 'grid' : 'table');

    // Sortable title column [FR-030]: click cycles newest → A–Z → Z–A.
    function cycleSort() {
        if (state.orderby !== 'title') { state.orderby = 'title'; state.order = 'asc'; }
        else if (state.order === 'asc') { state.order = 'desc'; }
        else { state.orderby = 'id'; state.order = 'desc'; }
        el.sortTitle.classList.toggle('sorted', state.orderby === 'title');
        el.sortTitle.classList.toggle('asc', state.orderby === 'title' && state.order === 'asc');
        el.sortTitle.setAttribute('aria-sort', state.orderby === 'title' ? (state.order === 'asc' ? 'ascending' : 'descending') : 'none');
        query(true);
    }
    el.sortTitle.addEventListener('click', cycleSort);
    el.sortTitle.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); cycleSort(); } });

    function clearFilters(includeSearch) {
        if (includeSearch) { el.search.value = ''; }
        el.status.value = el.access.value = el.source.value = '';
        query(true);
    }
    el.zeroClear.addEventListener('click', function () { clearFilters(true); });
    el.zeroClearFilters.addEventListener('click', function () { clearFilters(false); });
    el.clearAll.addEventListener('click', function () { clearFilters(true); });

    function fmtTime(seconds) {
        seconds = Math.floor(seconds);
        var h = Math.floor(seconds / 3600), m = Math.floor((seconds % 3600) / 60), s = seconds % 60;
        return (h ? h + ':' + String(m).padStart(2, '0') : m) + ':' + String(s).padStart(2, '0');
    }

    /* ------------------------------------------------- Live streams tab */
    /* UI-004L / WF-008, behind fastpix_feature_live (Figma FastPix-V3 frames
       9393:108540 / 108969 / 109263 / 109581). Rows from GET /streams; the
       opened stream fetches GET /streams/{id} for the encoder credentials
       (never stored client-side beyond the open panel). State is webhook-fed —
       the list just re-reads. Active/preparing streams are lifted into a
       .fp-lv-card above the table; idle/ended ones stay table rows. */
    (function live() {
        var tab = document.getElementById('fp-tab-live');
        if (!tab) { return; }

        var wrap = document.querySelector('.fp-videos');
        var allTab = document.querySelector('.fp-videos .tabs button');
        var lv = {
            pane: document.getElementById('fp-live-pane'),
            pill: document.getElementById('fp-live-pill'),
            table: document.getElementById('fp-livetable'),
            rows: document.getElementById('fp-live-rows'),
            empty: document.getElementById('fp-live-empty'),
            error: document.getElementById('fp-live-error'),
            errorBody: document.getElementById('fp-live-error-body'),
            form: document.getElementById('fp-live-form'),
            name: document.getElementById('fp-live-name'),
            access: document.getElementById('fp-live-access'),
            mediaAccess: document.getElementById('fp-live-media-access'),
            rec: document.getElementById('fp-live-rec'),
            search: document.getElementById('fp-live-search')
        };
        var lstate = { open: null, openAfterLoad: null };
        var DOTS = '•••••••••••••••••••••';

        function switchTab(toLive) {
            wrap.classList.toggle('live-on', toLive);
            lv.pane.hidden = !toLive;
            tab.classList.toggle('on', toLive); tab.setAttribute('aria-selected', String(toLive));
            allTab.classList.toggle('on', !toLive); allTab.setAttribute('aria-selected', String(!toLive));
            if (toLive) { loadStreams(); }
        }
        tab.addEventListener('click', function () { switchTab(true); });
        allTab.addEventListener('click', function () { switchTab(false); });

        function fail(res) {
            lv.error.hidden = false;
            lv.errorBody.textContent = (res && res.json && res.json.message) || __('FastPix could not be reached. The list shows the last known state.', 'fastpix-io');
        }

        function loadStreams() {
            lv.error.hidden = true;
            api('GET', '/streams').then(function (res) {
                if (!res.ok) { fail(res); return; }
                var streams = res.json.streams || [];
                closePanel();
                lv.rows.innerHTML = '';
                Array.prototype.forEach.call(lv.pane.querySelectorAll('.fp-lv-card, #fp-live-foot'), function (n) { n.remove(); });
                var rows = 0, liveCount = 0;
                streams.forEach(function (s) {
                    if (s.status === 'active' || s.status === 'preparing') {
                        lv.table.before(liveCard(s));
                        if (s.status === 'active') { liveCount++; }
                    } else {
                        lv.rows.appendChild(streamRow(s)); rows++;
                    }
                });
                lv.table.hidden = rows === 0;
                lv.empty.hidden = streams.length > 0 || !lv.form.hidden;
                if (streams.length) {
                    var foot = h('div'); foot.id = 'fp-live-foot';
                    var count = h('span', 'lv-count'); count.appendChild(h('b', 'mono', String(streams.length)));
                    count.appendChild(document.createTextNode(' ' + _n('stream', 'streams', streams.length, 'fastpix-io') + ' · ' + sprintf(__('%d live', 'fastpix-io'), liveCount)));
                    foot.appendChild(count);
                    foot.appendChild(h('span', 'lv-note', __('Idle streams don’t cost anything — they wait for your broadcast software.', 'fastpix-io')));
                    lv.table.after(foot);
                }
                // Always show a count (like "All videos 248"); go red "N live" while any stream is on air.
                lv.pill.hidden = streams.length === 0;
                lv.pill.className = 'pill' + (liveCount ? ' live' : '');
                lv.pill.textContent = liveCount ? sprintf(__('%d live', 'fastpix-io'), liveCount) : String(streams.length);
                if (lstate.openAfterLoad) {
                    var id = lstate.openAfterLoad; lstate.openAfterLoad = null;
                    var host = lv.pane.querySelector('[data-stream="' + id + '"]');
                    if (host) { toggleStream(host, id); }
                }
            });
        }
        // Prototype toolbar has no Refresh — the search filters the loaded list (cards and rows).
        lv.search.addEventListener('input', function () {
            var q = this.value.trim().toLowerCase();
            closePanel();
            Array.prototype.forEach.call(lv.pane.querySelectorAll('[data-stream]'), function (n) {
                n.hidden = q !== '' && n.textContent.toLowerCase().indexOf(q) === -1;
            });
        });

        /* "Ended 2 h ago" · "Ended yesterday" · "Live for 2 h" */
        function agoPhrase(verb, iso) {
            var a = timeAgo(iso);
            if (!a) { return verb; }
            /* translators: 1: a verb like Ended, 2: a relative time like 2 h */
            if (a === 'now') { return sprintf(__('%s just now', 'fastpix-io'), verb); }
            if (a === 'yesterday') { return sprintf(__('%s yesterday', 'fastpix-io'), verb); }
            return sprintf(__('%1$s %2$s ago', 'fastpix-io'), verb, a);
        }
        function liveFor(s) {
            var a = timeAgo(s.last_active_at || s.created_at);
            if (!a || a === 'now') { return __('Live for under a minute', 'fastpix-io'); }
            return sprintf(__('Live for %s', 'fastpix-io'), a === 'yesterday' ? sprintf(__('%d d', 'fastpix-io'), 1) : a);
        }
        function parseIso(iso) {
            if (!iso) { return NaN; }
            return Date.parse(iso.replace(' ', 'T') + (iso.indexOf('Z') === -1 && iso.indexOf('+') === -1 ? 'Z' : ''));
        }
        function fmtDate(iso) {
            var t = parseIso(iso);
            return isNaN(t) ? '' : new Date(t).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
        }
        // Whether this stream records, not where a recording is: once one has landed in the library it reads
        // "Recording on" again. "on the way" is only the gap between the broadcast ending and the recording
        // arriving; the opened row links to it. (owner 2026-09-22)
        function recText(s) {
            if (!s.recording) { return __('Recording off', 'fastpix-io'); }
            if (s.status === 'ended' && !s.recorded_video) { return __('Recording on the way', 'fastpix-io'); }
            return __('Recording on', 'fastpix-io');
        }
        function copyStreamEmbed(s, btn) { copyText('[fastpix streamid="' + s.stream_id + '"]', btn); }
        // Owner 2026-09-09: the live buttons read "Shortcode" like the Videos row (same copy glyph), not "⧉ Embed".
        var COPY_ICON = '<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V6a2 2 0 0 1 2-2h9"/></svg>';
        function shortcodeBtn(s, cls) { var b = bt('', cls, function () { copyStreamEmbed(s, this); }); b.innerHTML = COPY_ICON + __('Shortcode', 'fastpix-io'); b.title = __('Copy the shortcode for this stream', 'fastpix-io'); return b; }
        function openRecording(s) {
            switchTab(false);
            el.search.value = (s.recorded_video && (s.recorded_video.title || s.recorded_video.media_id)) || '';
            query(true);
        }
        function chevron(host, s, extra) {
            var c = bt('', 'fp-lv-chev' + (extra || ''), function () { toggleStream(host, s.stream_id); }); c.innerHTML = ARROW;   // the frame's S / arrow (9509:119933…)
            c.title = __('Expand', 'fastpix-io'); c.setAttribute('aria-expanded', 'false'); return c;
        }
        function markStream(host, on) {
            host.classList.toggle(host.tagName === 'TR' ? 'isopen' : 'is-open', on);
            var c = host.querySelector('.fp-lv-chev');
            if (c) { c.classList.toggle('open', on); c.setAttribute('aria-expanded', String(on)); c.title = on ? __('Collapse', 'fastpix-io') : __('Expand', 'fastpix-io'); }
        }

        /* Lifted card for a live/preparing stream (sits above the table). */
        /* ⋮ menu — every action the stream offers, for rows and the live card alike (the Videos row has the same button). */
        function streamMenu(button, host, s) {
            closeMenus();
            var menu = document.createElement('div'); menu.className = 'fp-menu'; menu.setAttribute('role', 'menu');
            function item(label, cls, fn) { var b = document.createElement('button'); b.type = 'button'; b.textContent = label; if (cls) { b.className = cls; } b.setAttribute('role', 'menuitem'); b.addEventListener('click', function () { button.focus(); closeMenus(); fn.call(button); }); menu.appendChild(b); }
            menuKeys(menu);   // [QA L21]
            // Owner 2026-09-09: Copy shortcode · Enable/Disable · Complete (live only) · Delete — nothing else.
            var live = s.status === 'active' || s.status === 'preparing';
            item(__('Copy shortcode', 'fastpix-io'), '', function () { copyStreamEmbed(s, button); });
            if (s.status === 'disabled') { item(__('Enable', 'fastpix-io'), '', function () { setEnabled(s, true); }); }
            else { item(__('Disable', 'fastpix-io'), '', function () { setEnabled(s, false); }); }
            item(__('Complete…', 'fastpix-io'), 'del', function () { endStream(s); });   // always listed (owner 2026-09-09); endStream explains when there is nothing to complete
            item(__('Delete…', 'fastpix-io'), 'del', function () { deleteStream(s); });   // same gate as the Details pane's Delete (server-side capability)
            button.parentNode.appendChild(menu); placeMenu(menu, button);
            button.setAttribute('aria-expanded', 'true');
            menu.querySelector('button').focus({ preventScroll: true });   // a scroll would close the fixed menu
        }
        function kebab(host, s) {
            var k = bt('⋮', 'kebab', function (e) { e.stopPropagation(); streamMenu(k, host, s); });
            k.title = __('More', 'fastpix-io'); k.setAttribute('aria-haspopup', 'menu'); k.setAttribute('aria-expanded', 'false');
            return k;
        }

        function liveCard(s) {
            var card = h('div', 'fp-lv-card'); card.dataset.stream = s.stream_id;
            if (!document.getElementById('fp-live-card')) { card.id = 'fp-live-card'; }
            var head = h('div', 'fp-lv-head');
            var thumb = h('div', 'fp-lv-thumb');
            var preparing = s.status === 'preparing';
            thumb.appendChild(h('span', 'fp-lv-badge' + (preparing ? ' prep' : ''), preparing ? __('PREPARING', 'fastpix-io') : __('LIVE', 'fastpix-io')));
            head.appendChild(thumb);

            var info = h('div', 'fp-lv-info');
            var title = h('a', 'fp-lv-title', s.name || __('Untitled stream', 'fastpix-io')); title.href = '#'; info.appendChild(title);
            var sub = h('div', 'fp-lv-sub');
            // No concurrent-viewer figure: real-time analytics is out of scope by owner ruling. (QA X26)
            sub.appendChild(document.createTextNode(preparing ? __('Preparing · your encoder is connected', 'fastpix-io') : liveFor(s)));
            if (s.recording) { sub.appendChild(document.createTextNode(__(' · recording', 'fastpix-io'))); }
            info.appendChild(sub); head.appendChild(info);

            var tools = h('div', 'fp-lv-tools');
            tools.appendChild(shortcodeBtn(s, 'fp-lv-btn'));
            tools.appendChild(bt(__('End stream', 'fastpix-io'), 'fp-lv-btn end', function () { endStream(s); }));
            tools.appendChild(kebab(card, s));
            tools.appendChild(chevron(card, s, ''));
            head.appendChild(tools);
            card.appendChild(head);

            title.addEventListener('click', function (e) { e.preventDefault(); toggleStream(card, s.stream_id); });
            return card;
        }

        /* Table row for an idle/ended stream. */
        function streamRow(s) {
            var tr = h('tr'); tr.dataset.stream = s.stream_id;
            var ended = s.status === 'ended';

            var name = h('td', 'lv-name');
            var title = h('a', 'vtitle', s.name || __('Untitled stream', 'fastpix-io')); title.href = '#'; name.appendChild(title);
            var sub = h('div', 'lv-sub', (ended ? agoPhrase(__('Ended', 'fastpix-io'), s.last_active_at || s.created_at) : __('Never streamed', 'fastpix-io')) + ' ');
            // frame: stream ids shorten to 8…6 (video ids use 8…3)
            var idEl = h('span', 'mono', s.stream_id.length > 17 ? s.stream_id.slice(0, 8) + '…' + s.stream_id.slice(-6) : s.stream_id); idEl.title = s.stream_id; sub.appendChild(idEl);
            var cp = bt('⧉', 'lv-copy', function () { copyText(s.stream_id, this); }); cp.title = __('Copy stream ID', 'fastpix-io'); cp.setAttribute('aria-label', __('Copy stream ID', 'fastpix-io'));
            sub.appendChild(cp); name.appendChild(sub); tr.appendChild(name);

            var st = h('td', 'lv-state');
            var disabled = s.status === 'disabled';
            // A finished broadcast leaves the stream ready for the next one, so the state is Idle — the line under
            // the name carries when it last ended. Only a stream that refuses a broadcast reads Disabled. The stored
            // status stays 'ended' (the embed and the state poll depend on it); this is the wording. (owner 2026-09-22)
            var pillEl = h('span', 'lv-pill ' + (disabled ? 'disabled' : 'idle')); pillEl.appendChild(h('span', 'dot'));
            pillEl.appendChild(document.createTextNode(disabled ? __('Disabled', 'fastpix-io') : __('Idle', 'fastpix-io'))); st.appendChild(pillEl); tr.appendChild(st);

            var recWord = recText(s), onOff = /off/i.test(recWord) ? 'off' : 'on';
            var rec = h('td', 'lv-rec ' + onOff); rec.appendChild(h('span', 'lv-recdot ' + onOff)); rec.appendChild(document.createTextNode(recWord)); tr.appendChild(rec);

            var tools = h('td', 'lv-tools'), toolrow = h('span', 'lv-toolrow');   // relative wrapper: the ⋮ menu hangs off it
            toolrow.appendChild(shortcodeBtn(s, 'fp-lv-btn sm'));
            toolrow.appendChild(kebab(tr, s));
            toolrow.appendChild(chevron(tr, s, ' sm'));
            tools.appendChild(toolrow); tr.appendChild(tools);

            title.addEventListener('click', function (e) { e.preventDefault(); toggleStream(tr, s.stream_id); });
            return tr;
        }


        function closePanel() {
            if (!lstate.open) { return; }
            lstate.open.nodes.forEach(function (n) { n.remove(); });
            markStream(lstate.open.host, false);
            lstate.open = null;
        }

        /* host = the .fp-lv-card (panel appended inside it) or the <tr> (panel in a following .fp-openrow). */
        function toggleStream(host, id) {
            if (lstate.open && lstate.open.id === id) { closePanel(); return; }
            closePanel();
            api('GET', '/streams/' + id).then(function (res) {
                if (!res.ok) { fail(res); return; }
                var parts = buildStreamPanel(res.json), nodes = parts, mount = host;
                if (host.tagName === 'TR') {
                    var panel = h('tr', 'fp-openrow exprow'); var td = h('td'); td.colSpan = 4; panel.appendChild(td);
                    host.after(panel); mount = td; nodes = [panel];
                }
                parts.forEach(function (n) { mount.appendChild(n); });
                markStream(host, true);
                lstate.open = { id: id, host: host, nodes: nodes };
            });
        }

        /* Boxed value with Copy (and Show/Hide for secrets). */
        function keyBox(value, secret) {
            var box = h('div', 'fp-lv-box');
            var code = h('code', 'mono' + (secret ? ' dots' : ''), secret ? DOTS : value); box.appendChild(code);
            if (secret) {
                var shown = false;
                box.appendChild(bt(__('Show', 'fastpix-io'), 'fp-lv-link', function () {
                    shown = !shown; code.textContent = shown ? value : DOTS; code.classList.toggle('dots', !shown); this.textContent = shown ? __('Hide', 'fastpix-io') : __('Show', 'fastpix-io');
                }));
            }
            box.appendChild(bt(__('Copy', 'fastpix-io'), 'fp-lv-link', function () { copyText(value, this); }));
            return box;
        }
        function kv(label, value) {
            var row = h('div'); row.appendChild(h('dt', null, label));
            var dd = h('dd'); if (typeof value === 'string') { dd.textContent = value; } else { dd.appendChild(value); }
            row.appendChild(dd); return row;
        }
        function strip(kind, title, body, action) {
            var n = h('div', 'fp-lv-strip ' + kind);
            n.appendChild(h('b', null, title)); n.appendChild(document.createTextNode(' ' + body));
            if (action) { n.appendChild(action); }
            return n;
        }

        function endStream(s) {
            if (s.status !== 'active' && s.status !== 'preparing') { fail({ json: { message: __('Nothing to complete — this stream is not broadcasting. Complete ends a live broadcast; the platform refuses it while idle.', 'fastpix-io') } }); return; }
            fpDialog.confirm({ message: __('End this broadcast for everyone watching?', 'fastpix-io'), ok: __('End broadcast', 'fastpix-io'), danger: true }).then(function (ok) {
                if (!ok) { return; }
                api('POST', '/streams/' + s.stream_id + '/finish').then(function (res) {
                    if (res.ok) { lstate.openAfterLoad = s.stream_id; loadStreams(); } else { fail(res); }
                });
            });
        }
        /* Enable / disable: a disabled stream refuses every encoder until it is enabled again. */
        function setEnabled(s, on) {
            var live = s.status === 'active' || s.status === 'preparing';
            var ask = (!on && live) ? fpDialog.confirm({ message: __('Disable this stream? The broadcast stops and viewers see the waiting message.', 'fastpix-io'), ok: __('Disable', 'fastpix-io'), danger: true }) : Promise.resolve(true);
            return ask.then(function (ok) {
                if (!ok) { return false; }
                api('POST', '/streams/' + s.stream_id + '/' + (on ? 'enable' : 'disable')).then(function (res) {
                    if (res.ok) { loadStreams(); } else { fail(res); loadStreams(); }
                });
                return true;
            });
        }
        /* An on/off switch — the Settings screen's toggle, so enable/disable reads the same everywhere (QA #10). */
        function lvSwitch(on, text, onChange) {
            var lab = h('label', 'fp-lv-sw'), box = h('input', 'fp-lv-toggle');
            box.type = 'checkbox'; box.setAttribute('role', 'switch'); box.checked = !!on;
            box.addEventListener('change', function () { onChange(box.checked, box); });
            lab.appendChild(box); lab.appendChild(h('span', null, text));
            return lab;
        }
        function renameStream(s) {
            fpDialog.prompt({ title: __('Stream name', 'fastpix-io'), value: s.name || '', ok: __('Rename', 'fastpix-io') }).then(function (name) {
                if (name === null) { return; }
                api('PATCH', '/streams/' + s.stream_id, { name: name }).then(function (res) {
                    if (res.ok) { lstate.openAfterLoad = s.stream_id; loadStreams(); } else { fail(res); }
                });
            });
        }
        function deleteStream(s) {
            fpDialog.confirm({ title: __('Delete this stream and its key?', 'fastpix-io'), message: __('Pages using the shortcode show the recording if one exists, or nothing.', 'fastpix-io'), ok: __('Delete', 'fastpix-io'), danger: true }).then(function (ok) {
                if (!ok) { return; }
                api('DELETE', '/streams/' + s.stream_id).then(function (res) {
                    if (res.ok) { loadStreams(); } else { fail(res); }
                });
            });
        }

        function targetName(url) {
            var host = (String(url).match(/^[a-z]+:\/\/([^/:]+)/i) || [])[1] || url;
            if (/youtube/i.test(host)) { return 'YouTube'; }
            if (/linkedin/i.test(host)) { return 'LinkedIn'; }
            if (/twitch/i.test(host)) { return 'Twitch'; }
            if (/facebook/i.test(host)) { return 'Facebook'; }
            return host;
        }


        /* Returns [strip?, .fp-lv-tabs, .fp-lv-body]; the caller mounts them.
           Tabs remember nothing — default is Connect encoder, Details for ended. */
        function buildStreamPanel(s) {
            var live = s.status === 'active', preparing = s.status === 'preparing', ended = s.status === 'ended', idle = s.status === 'idle';
            var ingest = s.ingest || {};
            s.simulcast = s.simulcast || [];
            var out = [];

            // ---- banner strip (preparing / ended; the live frames draw none — the LIVE badge is the context)
            if (preparing) {
                out.push(strip('fp', __('Preparing your stream.', 'fastpix-io'), __('Your encoder is connected and we are receiving video. Viewers will be able to watch in a few seconds — this pause is normal.', 'fastpix-io')));
            } else if (ended) {
                var body = s.recorded_video
                    ? __('The recording is in your library as an ordinary video. Nothing needs re-embedding — the embed on your page switched by itself.', 'fastpix-io')
                    : (s.recording
                        ? __('The recording is being finalised and will appear in your library within about a minute. The embed on your page switches to it by itself.', 'fastpix-io')
                        : __('Recording was off for this stream, so there is nothing to replay.', 'fastpix-io'));
                out.push(strip('success', __('This stream has ended.', 'fastpix-io'), body, s.recorded_video ? bt(__('Open the recording →', 'fastpix-io'), 'fp-lv-link', function () { openRecording(s); }) : null));
            }

            // ---- Tab 1: Connect encoder
            function encoderPane() {
                var p = h('div', 'fp-lv-pane enc');
                var hr = h('div', 'fp-lv-hintrow');
                hr.appendChild(h('p', 'fp-lv-hint', __('Paste these two into your streaming app — in OBS that’s Settings → Stream.', 'fastpix-io')));
                infoIcon(__('Two ways to send your video. Pick one.\n\nRTMPS (left): works with OBS and most apps. Paste the Server and the Stream key.\n\nSRT (right): for apps that support SRT. Steadier on weak internet. Paste the address and the secret.\n\nThe key and the secret are passwords. Keep them private.', 'fastpix-io'), hr);
                p.appendChild(hr);
                // Owner 2026-09-09: RTMPS and SRT side by side — no disclosure, no "Rotate key" (FastPix cannot reset a key).
                var cols = h('div', 'fp-lv-enc2'), rtmp = h('div', 'fp-lv-sec fp-lv-enccol'), srt = h('div', 'fp-lv-sec fp-lv-enccol');   // two cards, like the Details tab's
                rtmp.appendChild(h('label', 'fp-lv-lab', __('Server', 'fastpix-io'))); rtmp.appendChild(keyBox(ingest.rtmps || '', false));
                rtmp.appendChild(h('label', 'fp-lv-lab', __('Stream key', 'fastpix-io'))); rtmp.appendChild(keyBox(s.stream_key || '', true));
                rtmp.appendChild(h('p', 'fp-lv-fine', __('Anyone holding this can broadcast as you — it stays hidden so it survives a screen share.', 'fastpix-io')));
                srt.appendChild(h('label', 'fp-lv-lab', __('SRT address', 'fastpix-io'))); srt.appendChild(keyBox(ingest.srt || '', false));
                if (s.srt_secret) { srt.appendChild(h('label', 'fp-lv-lab', __('SRT secret', 'fastpix-io'))); srt.appendChild(keyBox(s.srt_secret, true)); }
                srt.appendChild(h('p', 'fp-lv-fine', __('Use these instead if your encoder speaks SRT — same stream, same key rules.', 'fastpix-io')));
                cols.appendChild(rtmp); cols.appendChild(srt); p.appendChild(cols);
                return p;
            }

            // ---- Tab 2: Put it in a page
            function pagePane() {
                var p = h('div', 'fp-lv-pane page'), two = h('div', 'fp-lv-two'), left = h('div', 'fp-lv-l'), right = h('div', 'fp-lv-r');
                left.appendChild(h('p', 'fp-lv-intro', __('One embed covers all three states — a waiting message before you start, the stream while you’re live, then the recording. You never edit the post.', 'fastpix-io')));

                // Embed options — the same controls + accent the video embed offers; the
                // live player and the recording both honour them. (Autoplay/muted are
                // forced for a live start, so they aren't offered.)
                var ctlOn = { controls: true, click: true, keys: true };
                var accent = '#6d22cd';
                left.appendChild(h('label', 'fp-lv-lab', __('Player options', 'fastpix-io')));
                var chips = h('div', 'fp-lv-chips');
                [['controls', __('Player controls', 'fastpix-io')], ['click', __('Click to play', 'fastpix-io')], ['keys', __('Keyboard shortcuts', 'fastpix-io')]].forEach(function (k) {
                    var c = bt(k[1], 'fp-lv-chip', function () { ctlOn[k[0]] = !ctlOn[k[0]]; c.setAttribute('aria-pressed', String(ctlOn[k[0]])); buildCode(); });
                    c.setAttribute('aria-pressed', 'true'); chips.appendChild(c);
                });
                left.appendChild(chips);

                var acc = h('div', 'fp-lv-accent'); acc.appendChild(h('label', 'fp-lv-lab', __('Accent color', 'fastpix-io')));
                var swatch = h('span', 'fp-lv-swatch'); swatch.style.background = accent;
                var accIn = h('input'); accIn.type = 'color'; accIn.value = accent; accIn.setAttribute('aria-label', __('Accent colour', 'fastpix-io')); swatch.appendChild(accIn);
                var hex = h('input', 'fp-lv-hex'); hex.type = 'text'; hex.value = accent; hex.maxLength = 7; hex.spellcheck = false; hex.setAttribute('aria-label', __('Accent colour hex', 'fastpix-io'));
                function setAccent(v) { accent = v.toLowerCase(); swatch.style.background = accent; accIn.value = accent; hex.value = accent; buildCode(); }
                accIn.addEventListener('input', function () { setAccent(accIn.value); });
                hex.addEventListener('change', function () { var v = hex.value.trim(); if (/^#[0-9a-f]{6}$/i.test(v)) { setAccent(v); } else { hex.value = accent; } });
                acc.appendChild(swatch); acc.appendChild(hex); acc.appendChild(h('span', 'fp-lv-fine', __('used for the progress bar & buttons', 'fastpix-io')));
                left.appendChild(acc);

                // The shortcode (posts, widgets, most page builders) or a PHP call for
                // theme templates. The block only takes a videoId, so it isn't offered here.
                var forms = { shortcode: '', php: '' }, fmt = 'shortcode';
                var seg = h('div', 'fp-lv-seg');
                var segSc = bt(__('Shortcode', 'fastpix-io'), 'fp-lv-segbtn', function () { pickFmt('shortcode'); }), segPhp = bt(__('PHP', 'fastpix-io'), 'fp-lv-segbtn', function () { pickFmt('php'); });
                seg.appendChild(segSc); seg.appendChild(segPhp); left.appendChild(seg);
                var box = h('div', 'fp-lv-code'), codeEl = h('code', 'mono'); box.appendChild(codeEl);
                box.appendChild(bt(__('Copy', 'fastpix-io'), 'fp-lv-copy', function () { copyText(forms[fmt], this); }));
                left.appendChild(box);
                var foot = h('p', 'fp-lv-fine', __('Paste it into any post or page — or ', 'fastpix-io'));
                var ed = document.createElement('a'); ed.className = 'fp-lv-link'; ed.href = 'post-new.php'; ed.textContent = __('open the editor', 'fastpix-io'); foot.appendChild(ed);
                foot.appendChild(document.createTextNode(__(' and add the FastPix block instead.', 'fastpix-io'))); left.appendChild(foot);

                function buildCode() {
                    var parts = ['fastpix streamid="' + s.stream_id + '"'];
                    if (!ctlOn.controls) { parts.push('nocontrols'); }
                    if (!ctlOn.click) { parts.push('noclick'); }
                    if (!ctlOn.keys) { parts.push('nokeys'); }
                    if (accent && accent !== '#6d22cd') { parts.push('accentcolour="' + accent + '"'); }
                    forms.shortcode = '[' + parts.join(' ') + ']';
                    forms.php = "<?php echo do_shortcode('" + forms.shortcode.replace(/'/g, "\\'") + "'); ?>";
                    codeEl.textContent = forms[fmt];
                }
                function pickFmt(which) {
                    fmt = which; codeEl.textContent = forms[which];
                    segSc.setAttribute('aria-pressed', String(which === 'shortcode')); segPhp.setAttribute('aria-pressed', String(which === 'php'));
                }
                buildCode(); pickFmt('shortcode');

                right.appendChild(h('div', 'fp-lv-eyebrow', __('What visitors see right now', 'fastpix-io')));
                var pv = h('div', 'fp-lv-preview ' + (live ? 'live' : ended ? 'ended' : 'waiting')), top = h('div', 'fp-lv-pvtop');
                var l1, l2, showing;
                if (live) { l1 = __('You’re live', 'fastpix-io'); l2 = __('Visitors are watching the stream', 'fastpix-io'); showing = __('live stream', 'fastpix-io'); }
                else if (ended && s.recorded_video) { l1 = __('Showing the recording', 'fastpix-io'); l2 = __('It plays in place of the stream', 'fastpix-io'); showing = __('the recording', 'fastpix-io'); }
                else if (ended) { l1 = __('The stream has ended', 'fastpix-io'); l2 = s.recording ? __('The recording is on its way', 'fastpix-io') : __('Recording was off — nothing to replay', 'fastpix-io'); showing = s.recording ? __('the recording, shortly', 'fastpix-io') : __('nothing', 'fastpix-io'); }
                else { l1 = __('The stream hasn’t started yet', 'fastpix-io'); l2 = __('This page will update on its own', 'fastpix-io'); showing = __('waiting message', 'fastpix-io'); }
                if (live && s.playback_id) {
                    // Owner 2026-09-09: while live the rail plays the stream itself — the same
                    // <fastpix-player> the shortcode renders (muted autoplay, RULE-034), not a mock.
                    top.classList.add('player');
                    var pl = document.createElement('fastpix-player');
                    pl.setAttribute('playback-id', s.playback_id); pl.setAttribute('stream-type', 'live-stream');
                    pl.setAttribute('auto-play', ''); pl.setAttribute('muted', ''); pl.setAttribute('accent-color', accent);
                    if (s.playback_token) { pl.setAttribute('token', s.playback_token); }
                    top.appendChild(pl);
                } else {
                    top.appendChild(h('span', 'fp-lv-ring'));
                    top.appendChild(h('p', 'fp-lv-pv1', l1)); top.appendChild(h('p', 'fp-lv-pv2', l2));
                }
                pv.appendChild(top);
                var bar = h('div', 'fp-lv-pvbar'); bar.appendChild(h('span', null, sprintf(__('Showing: %s', 'fastpix-io'), showing))); bar.appendChild(h('span', 'faint', __('→ live → recording', 'fastpix-io'))); pv.appendChild(bar);
                right.appendChild(pv);

                two.appendChild(left); two.appendChild(right); p.appendChild(two);
                return p;
            }

            // ---- Tab 3: Details & simulcast
            function detailsPane() {
                var p = h('div', 'fp-lv-pane det'), two = h('div', 'fp-lv-two det'), left = h('div', 'fp-lv-col'), right = h('div', 'fp-lv-col');

                // IDs (Rename… lives here so it stays reachable while live, when the Delete card is hidden)
                var ids = h('div', 'fp-lv-sec'), ih = h('div', 'fp-lv-head2'); ih.appendChild(h('h3', 'fp-lv-h', __('IDs', 'fastpix-io')));
                ih.appendChild(bt(__('Rename…', 'fastpix-io'), 'fp-lv-link', function () { renameStream(s); })); ids.appendChild(ih);
                function idRow(label, value) {
                    var r = h('div', 'fp-lv-idrow'); r.appendChild(h('span', 'fp-lv-idlab', label));
                    var c = h('code', 'mono', value); c.title = value; r.appendChild(c);
                    r.appendChild(bt(__('Copy', 'fastpix-io'), 'fp-lv-link', function () { copyText(value, this); })); return r;
                }
                ids.appendChild(idRow(__('Stream ID', 'fastpix-io'), s.stream_id));
                if (s.playback_id) { ids.appendChild(idRow(__('Playback ID', 'fastpix-io'), s.playback_id)); }
                ids.appendChild(h('p', 'fp-lv-fine', __('Only needed for support tickets or custom code — the shortcode already carries them.', 'fastpix-io')));
                left.appendChild(ids);

                // Recording (read-only, set at creation)
                var rc = h('div', 'fp-lv-sec'); rc.appendChild(h('h3', 'fp-lv-h', __('Recording', 'fastpix-io')));
                var dl = h('dl', 'fp-lv-kv');
                var rv = h('span'); rv.appendChild(h('b', null, s.recording ? __('On', 'fastpix-io') : __('Off', 'fastpix-io'))); rv.appendChild(document.createTextNode(__(' · chosen at creation, can’t change now', 'fastpix-io')));
                dl.appendChild(kv(__('Recording', 'fastpix-io'), rv));
                var where;
                if (s.recorded_video) { where = bt(__('In your video library — open it', 'fastpix-io'), 'fp-lv-link', function () { openRecording(s); }); }
                else { where = s.recording ? __('Your video library, after the stream ends', 'fastpix-io') : __('Nowhere — recording is off', 'fastpix-io'); }
                dl.appendChild(kv(__('Where it lands', 'fastpix-io'), where));
                // The policy is the stream's playback id's, as FastPix reports it (`playback_policy`). This read
                // `s.access`, which the server never sends, so every stream — private ones too — said "Anyone
                // (public)". Unknown is shown as unknown, never as public. (QA report #11)
                // Once the recording exists it carries its own policy (they are chosen separately at create);
                // before that, the broadcast's is the only thing known here. (owner 2026-09-22)
                var recPolicy = s.recorded_video && s.recorded_video.access_policy;
                dl.appendChild(kv(__('Who can watch it', 'fastpix-io'), recPolicy
                    ? (ACCESS_LABEL[recPolicy] || recPolicy)
                    : (s.playback_policy ? __('Same as the stream — ', 'fastpix-io') + (ACCESS_LABEL[s.playback_policy] || s.playback_policy) : '—')));
                rc.appendChild(dl); right.appendChild(rc);

                // Enable / disable, visible here instead of only in the ⋮ menu (QA #10).
                var en = h('div', 'fp-lv-sec fp-lv-del'), et = h('div'), onNow = s.status !== 'disabled';
                et.appendChild(h('b', null, __('Accept broadcasts', 'fastpix-io')));
                et.appendChild(h('p', 'fp-lv-fine', onNow
                    ? __('On — the encoder can go live with this stream key.', 'fastpix-io')
                    : __('Off — every encoder is refused until you turn this back on.', 'fastpix-io')));
                en.appendChild(et);
                en.appendChild(lvSwitch(onNow, onNow ? __('On', 'fastpix-io') : __('Off', 'fastpix-io'), function (on, box) {
                    box.disabled = true;
                    setEnabled(s, on).then(function (went) { if (!went) { box.checked = !on; box.disabled = false; } });
                }));
                right.appendChild(en);

                // Delete — the frame draws it on a live stream too; the confirm is the guard.
                var del = h('div', 'fp-lv-sec fp-lv-del'), dt = h('div');
                dt.appendChild(h('b', null, __('Delete this stream', 'fastpix-io')));
                dt.appendChild(h('p', 'fp-lv-fine', __('Removes the stream key and embed. Recordings already in your library stay.', 'fastpix-io')));
                del.appendChild(dt); del.appendChild(bt(__('Delete', 'fastpix-io'), 'fp-lv-btn danger', function () { deleteStream(s); }));
                right.appendChild(del);

                // Simulcast — targets change only while idle; the platform PUT takes only
                // isEnabled, so an edit is delete + re-add. Rebuilt whole on every change.
                // The frame draws the add controls on a live stream as well, so they always
                // render; outside idle a click explains the lock instead of calling the API.
                var sim = h('div', 'fp-lv-sec sim');
                function locked() {
                    fpDialog.alert(live || preparing ? __('Destinations lock while you’re live — add them before you start.', 'fastpix-io') : __('This stream has ended — its destinations can’t change any more.', 'fastpix-io'));
                }
                function render() {
                    while (sim.firstChild) { sim.removeChild(sim.firstChild); }
                    var sh = h('div', 'fp-lv-head2'), st = h('div', 'fp-lv-hrow'); st.appendChild(h('h3', 'fp-lv-h', __('Simulcast', 'fastpix-io'))); infoIcon(__('Simulcast sends this broadcast to other platforms at the same time — YouTube, LinkedIn, X…\n\nPaste each platform’s RTMP address and stream key. Destinations lock while you’re live, so add them before you start.', 'fastpix-io'), st); sh.appendChild(st);
                    var form = null;
                    if (!idle) {
                        sh.appendChild(bt(__('＋ Add destination', 'fastpix-io'), 'fp-lv-btn accent sm', locked));
                    } else {
                        form = h('div', 'fp-lv-simform'); form.hidden = true;
                        var url = h('input'); url.type = 'text'; url.placeholder = 'rtmps://host/app'; url.setAttribute('aria-label', __('Destination RTMP URL', 'fastpix-io')); url.autocomplete = 'off'; url.spellcheck = false;
                        var key = h('input'); key.type = 'password'; key.placeholder = __('The platform’s stream key', 'fastpix-io'); key.setAttribute('aria-label', __('Destination stream key', 'fastpix-io')); key.autocomplete = 'new-password'; key.spellcheck = false;
                        form.appendChild(url); form.appendChild(key);
                        var acts = h('div', 'fp-lv-acts');
                        acts.appendChild(bt(__('Cancel', 'fastpix-io'), 'fp-lv-btn sm', function () { form.hidden = true; }));
                        var addBtn = bt(__('Add destination', 'fastpix-io'), 'fp-lv-btn sm primary', function () {
                            var u = url.value.trim();
                            if (!/^rtmps?:\/\/.+/i.test(u)) { url.focus(); return; }
                            if (!key.value) { key.focus(); return; }
                            addBtn.disabled = true;
                            api('POST', '/streams/' + s.stream_id + '/simulcast', { url: u, stream_key: key.value }).then(function (res) {
                                if (res.ok) { s.simulcast.push(res.json); } else { fail(res); }
                                render();
                            });
                        });
                        acts.appendChild(addBtn); form.appendChild(acts);
                        sh.appendChild(bt(__('＋ Add destination', 'fastpix-io'), 'fp-lv-btn accent sm', function () { form.hidden = false; url.focus(); }));
                    }
                    sim.appendChild(sh);
                    sim.appendChild(h('p', 'fp-lv-hint', __('Mirror this broadcast anywhere that takes an RTMP address — YouTube, LinkedIn, X…', 'fastpix-io')));
                    if (form) { sim.appendChild(form); }

                    s.simulcast.forEach(function (t, i) {
                        var row = h('div', 'fp-lv-dest'), nm = targetName(t.url), yt = /youtube/i.test(t.url);
                        row.appendChild(h('span', 'fp-lv-tile' + (yt ? ' yt' : ''), yt ? '' : nm.charAt(0).toUpperCase()));
                        var info = h('div', 'fp-lv-dinfo'); info.appendChild(h('b', null, nm));
                        var subT = h('span', null, sprintf(/* translators: %s: an RTMP address; the key stays masked */ __('%s · key •••••', 'fastpix-io'), t.url)); subT.title = t.url; info.appendChild(subT); row.appendChild(info);
                        var tg = lvSwitch(t.enabled, t.enabled ? __('Mirror', 'fastpix-io') : __('Paused', 'fastpix-io'), function (on, box) {
                            if (!idle) { box.checked = !on; locked(); return; }
                            box.disabled = true;
                            api('PATCH', '/streams/' + s.stream_id + '/simulcast/' + t.id, { enabled: on }).then(function (res) {
                                if (res.ok) { t.enabled = res.json.enabled; } else { fail(res); } render();
                            });
                        });
                        tg.title = idle ? __('On: this broadcast is sent here too. Off: this destination is skipped.', 'fastpix-io') : __('Locked while the stream is not idle', 'fastpix-io');
                        row.appendChild(tg);
                        var x = bt('×', 'fp-lv-x', function () {
                            if (!idle) { locked(); return; }
                            api('DELETE', '/streams/' + s.stream_id + '/simulcast/' + t.id).then(function (res) {
                                if (res.ok) { s.simulcast.splice(i, 1); } else { fail(res); } render();
                            });
                        });
                        x.title = __('Remove', 'fastpix-io'); x.setAttribute('aria-label', __('Remove destination', 'fastpix-io')); row.appendChild(x);
                        sim.appendChild(row);
                    });

                    var add = bt('', 'fp-lv-dest add', function () { if (!idle) { locked(); return; } form.hidden = false; form.querySelector('input').focus(); });
                    add.appendChild(h('span', 'fp-lv-tile', '+')); add.appendChild(h('span', null, __('Paste an RTMP address and key to add another', 'fastpix-io')));
                    sim.appendChild(add);
                    sim.appendChild(h('p', 'fp-lv-fine', ended ? __('Shown as it was when the stream ran.', 'fastpix-io') : __('Destinations lock while you’re live — add them before you start.', 'fastpix-io')));
                }
                render();
                left.appendChild(sim);

                // History
                var hist = h('div', 'fp-lv-sec hist'); hist.appendChild(h('div', 'fp-lv-eyebrow', __('History', 'fastpix-io')));
                var hl = h('dl', 'fp-lv-kv');
                hl.appendChild(kv(__('Created', 'fastpix-io'), fmtDate(s.created_at) || '—'));
                hl.appendChild(kv(__('Last broadcast', 'fastpix-io'), (idle ? '' : fmtDate(s.last_active_at)) || __('Never streamed', 'fastpix-io')));
                hl.appendChild(kv(__('Recordings', 'fastpix-io'), sprintf(__('%d in library', 'fastpix-io'), s.recorded_video ? 1 : 0)));
                hist.appendChild(hl); left.appendChild(hist);

                two.appendChild(left); two.appendChild(right); p.appendChild(two);
                return p;
            }

            var panes = [encoderPane(), pagePane(), detailsPane()];
            var tabs = h('div', 'fp-lv-tabs'); tabs.setAttribute('role', 'tablist');
            var bodyEl = h('div', 'fp-lv-body');
            [__('Connect encoder', 'fastpix-io'), __('Put it in a page', 'fastpix-io'), __('Details & simulcast', 'fastpix-io')].forEach(function (label, i) {
                var t = bt(label, 'fp-lv-tab', function () { pick(i); }); t.setAttribute('role', 'tab');
                tabs.appendChild(t); bodyEl.appendChild(panes[i]);
            });
            function pick(i) {
                Array.prototype.forEach.call(tabs.children, function (t, j) { t.classList.toggle('on', j === i); t.setAttribute('aria-selected', String(j === i)); });
                panes.forEach(function (pane, j) { pane.hidden = j !== i; });
            }
            pick(ended ? 2 : 0);
            out.push(tabs); out.push(bodyEl);
            return out;
        }

        // ---- create
        // "Who can watch" opens the library's own in-page menu (.fp-menu), not the browser's native list
        // (QA #9). The <select> stays as the value holder and the no-JS fallback; an option the template
        // disabled (DRM with no DRM configuration ID) is shown greyed out and cannot be picked.
        function ddFor(sel, labelText, onPick) {
            var wrap = h('span', 'fp-lv-dd'), btn = h('button', 'fp-lv-ddbtn');
            btn.type = 'button'; btn.setAttribute('aria-haspopup', 'listbox'); btn.setAttribute('aria-expanded', 'false');
            var label = h('span', 'fp-lv-ddval'); btn.appendChild(label);
            function paint() { label.textContent = sel.options[sel.selectedIndex].textContent; }
            function close(focus) { closeMenus(); btn.setAttribute('aria-expanded', 'false'); if (focus) { btn.focus(); } }
            btn.addEventListener('click', function (e) {
                e.stopPropagation();   // the document handler would close the menu this click opens
                if (btn.getAttribute('aria-expanded') === 'true') { close(false); return; }
                closeMenus();
                var menu = h('div', 'fp-menu fp-lv-ddmenu'); menu.setAttribute('role', 'listbox');
                Array.prototype.forEach.call(sel.options, function (o) {
                    var it = h('button', o.value === sel.value ? 'cur' : '', o.textContent); it.type = 'button';
                    it.setAttribute('role', 'menuitem'); it.setAttribute('aria-selected', String(o.value === sel.value));
                    if (o.disabled) { it.setAttribute('aria-disabled', 'true'); it.title = o.title || ''; }
                    it.addEventListener('click', function () { if (o.disabled) { return; } sel.value = o.value; paint(); close(true); if (onPick) { onPick(o.value); } });
                    menu.appendChild(it);
                });
                menuKeys(menu);
                menu.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') { ev.stopPropagation(); close(true); } else if (ev.key === 'Tab') { close(false); } });
                wrap.appendChild(menu); placeMenu(menu, btn);
                menu.style.minWidth = btn.offsetWidth + 'px';
                btn.setAttribute('aria-expanded', 'true');
                (menu.querySelector('.cur') || menu.querySelector('button')).focus({ preventScroll: true });
            });
            btn.addEventListener('keydown', function (e) { if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); btn.click(); } });
            document.addEventListener('click', function () { btn.setAttribute('aria-expanded', 'false'); });
            sel.parentNode.insertBefore(wrap, sel); wrap.appendChild(btn); sel.hidden = true;
            btn.setAttribute('aria-label', labelText);
            paint();

            return { btn: btn, wrap: wrap, paint: paint,
                // "Record it" off: nothing to gate, so the recording's policy is greyed out and unclickable.
                enable: function (on) { btn.disabled = !on; wrap.classList.toggle('is-off', !on); btn.setAttribute('aria-disabled', String(!on)); } };
        }
        (function () {
            var liveDd = ddFor(lv.access, __('Who can watch live', 'fastpix-io'), function (v) {   // live is public | private; the recording adds DRM
                // The recording follows the broadcast until someone chooses otherwise: a private stream whose
                // recording is public would give away what the stream was protecting.
                if (lv.mediaAccess.dataset.touched) { return; }
                var opt = lv.mediaAccess.querySelector('option[value="' + v + '"]');
                if (opt && !opt.disabled) { lv.mediaAccess.value = v; recDd.paint(); }
            });
            var recDd = ddFor(lv.mediaAccess, __('Who can watch the recording', 'fastpix-io'), function () { lv.mediaAccess.dataset.touched = '1'; });
            lv.liveDd = liveDd; lv.recDd = recDd;
            function syncRec() { recDd.enable(lv.rec.checked); }
            lv.rec.addEventListener('change', syncRec); syncRec();
            lv.resetAccess = function () { lv.access.value = 'public'; lv.mediaAccess.value = 'public'; delete lv.mediaAccess.dataset.touched; liveDd.paint(); recDd.paint(); lv.rec.checked = true; syncRec(); };
        })();
        function showForm(on) {
            lv.form.hidden = !on;
            if (on) { lv.empty.hidden = true; lv.name.focus(); }
            else { lv.empty.hidden = lv.pane.querySelector('[data-stream]') !== null; }
        }
        document.getElementById('fp-live-create').addEventListener('click', function () { showForm(true); });
        document.getElementById('fp-live-empty-create').addEventListener('click', function () { showForm(true); });
        document.getElementById('fp-live-cancel').addEventListener('click', function () { showForm(false); });
        document.getElementById('fp-live-submit').addEventListener('click', function () {
            var b = this; b.disabled = true;
            api('POST', '/streams', { name: lv.name.value.trim(), access: lv.access.value, media_access: lv.mediaAccess.value, recording: lv.rec.checked }).then(function (res) {
                b.disabled = false;
                if (!res.ok) { fail(res); return; }
                lv.name.value = ''; lv.resetAccess(); showForm(false);
                lstate.openAfterLoad = res.json.stream_id;   // open straight onto the credentials
                loadStreams();
            });
        });
    })();

    query(true);
})();
