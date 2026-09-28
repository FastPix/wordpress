/**
 * fastpix/video — UI-007, FR-090. Dynamic block: the canvas is the server
 * render (what a visitor sees), the sidebar exposes the per-embed overrides
 * ("Site default unless changed here"), and save() writes ONLY the identifier,
 * the overrides and a static fallback — never a playback URL or token
 * (SEC-006). No build step: WordPress globals only.
 */
(function (wp) {
    'use strict';

    var el = wp.element.createElement, Fragment = wp.element.Fragment, useState = wp.element.useState, useEffect = wp.element.useEffect;
    var __ = wp.i18n.__, _n = wp.i18n._n, sprintf = wp.i18n.sprintf;
    var registerBlockType = wp.blocks.registerBlockType;
    var InspectorControls = wp.blockEditor.InspectorControls, useBlockProps = wp.blockEditor.useBlockProps;
    var C = wp.components;
    var ServerSideRender = wp.serverSideRender;
    var apiFetch = wp.apiFetch;
    // Integration marker on every API call, matching the rest of the plugin.
    apiFetch.use(function (options, next) {
        options.headers = options.headers || {};
        options.headers['X-FastPix-Integration'] = 'wordpress';
        return next(options);
    });
    var select = wp.data.select;

    var LIB_URL = (window.fastpixBlock && window.fastpixBlock.libraryUrl) || '';
    var ADD_URL = (window.fastpixBlock && window.fastpixBlock.addMediaUrl) || '';

    function fmt(s) { s = Math.round(s || 0); var m = Math.floor(s / 60), r = s % 60; return (m >= 60 ? Math.floor(m / 60) + ':' + String(m % 60).padStart(2, '0') : m) + ':' + String(r).padStart(2, '0'); }

    // Where a search hit was found: one whole translatable string per field, so a raw English
    // field name never lands in a sentence or gets capitalised by hand. The search only reports
    // timed hits (chapter, transcript); anything else reads as a plain match. (QA L26)
    var MATCH = {
        title: { label: __('Title match', 'fastpix'), one: __('one in its title', 'fastpix') },
        /* translators: %s: a timestamp like 1:23 */
        chapter: { label: __('Chapter', 'fastpix'), one: __('one at %s in its chapters', 'fastpix') },
        /* translators: %s: a timestamp like 1:23 */
        transcript: { label: __('Transcript', 'fastpix'), one: __('one at %s in its transcript', 'fastpix') }
    };
    /* translators: %s: a timestamp like 1:23 */
    var MATCH_OTHER = { label: __('Match', 'fastpix'), one: __('one at %s', 'fastpix') };
    function matchText(m) { return MATCH[m.field] || MATCH_OTHER; }

    /* ---------------------------------------------------------- picker */

    function Picker(props) {
        var _q = useState(''), q = _q[0], setQ = _q[1];
        var _r = useState({ videos: [], note: '', loading: true }), r = _r[0], setR = _r[1];
        var _s = useState(null), sel = _s[0], setSel = _s[1];
        var _tab = useState('library'), tab = _tab[0], setTab = _tab[1];

        useEffect(function () {
            var alive = true;
            setR(function (o) { return { videos: o.videos, note: o.note, loading: true }; });
            apiFetch({ path: '/fastpix/v1/videos?per_page=24' + (q ? '&search=' + encodeURIComponent(q) : '') }).then(function (res) {
                if (!alive) { return; }
                setR({ videos: res.videos || [], note: res.search_note || '', loading: false });
            }).catch(function () { if (alive) { setR({ videos: [], note: __('The library could not be loaded.', 'fastpix'), loading: false }); } });
            return function () { alive = false; };
        }, [q]);

        var provenance = '';
        if (q && !r.loading) {
            var withMatch = r.videos.filter(function (v) { return v.match; });
            // Whole sentences with placeholders, never concatenated fragments. [QA L26]
            provenance = r.videos.length
                ? sprintf(
                    /* translators: 1: number of videos, 2: the search term */
                    _n('%1$d video mentions “%2$s”', '%1$d videos mention “%2$s”', r.videos.length, 'fastpix'), r.videos.length, q) +
                  (withMatch.length ? ' — ' + withMatch.slice(0, 2).map(function (v) {
                      return sprintf(matchText(v.match).one, fmt(v.match.seconds));
                  }).join(', ') : '') + '.'
                : '';
        }

        function card(v) {
            var isSel = sel && sel.id === v.id;
            var reason = v.match ? (v.match.field === 'title' ? MATCH.title.label : matchText(v.match).label + ' · ' + fmt(v.match.seconds) + (v.match.snippet ? ' “' + v.match.snippet + '”' : '')) : '';
            // Click selects; double-click inserts right away (the footer's
            // Insert button can sit below the modal's fold on small screens).
            return el('button', { key: v.id, type: 'button', className: 'fp-pick-card' + (isSel ? ' is-selected' : ''), onClick: function () { setSel(v); }, onDoubleClick: function () { props.onInsert(v); }, 'aria-pressed': isSel },
                el('span', { className: 'fp-pick-thumb' },
                    v.poster ? el('img', { src: v.poster, alt: '' }) : el('span', { className: 'fp-pick-nothumb' }),
                    v.duration ? el('span', { className: 'fp-pick-dur' }, fmt(v.duration)) : null,
                    v.access_policy !== 'public' ? el('span', { className: 'fp-pick-lock', title: __('Private', 'fastpix') }, '🔒') : null),
                el('span', { className: 'fp-pick-title' }, v.title || __('(untitled)', 'fastpix')),
                reason ? el('span', { className: 'fp-pick-reason' }, reason) : null);
        }

        return el(C.Modal, { title: __('Choose a video', 'fastpix'), onRequestClose: props.onClose, className: 'fp-picker', shouldCloseOnClickOutside: false },
            el(C.TabPanel, { tabs: [{ name: 'library', title: __('Your library', 'fastpix') }, { name: 'upload', title: __('Upload', 'fastpix') }], onSelect: setTab },
                function () { return null; }),
            tab === 'library'
                ? el('div', null,
                    el(C.SearchControl, { value: q, onChange: setQ, placeholder: __('Search titles, transcripts and chapters', 'fastpix'), label: __('Search', 'fastpix') }),
                    provenance ? el('p', { className: 'fp-pick-prov' }, provenance) : null,
                    r.note ? el('p', { className: 'fp-pick-prov' }, r.note) : null,
                    r.loading ? el(C.Spinner) : (r.videos.length
                        ? el('div', { className: 'fp-pick-grid' }, r.videos.map(card))
                        /* translators: %s: the search term */
                        : el('p', { className: 'fp-pick-empty' }, q ? sprintf(__('Nothing matches “%s” — search reads titles, transcripts and chapters.', 'fastpix'), q) : __('Your library is empty. Upload a video first.', 'fastpix'))))
                : el('div', { className: 'fp-pick-upload' },
                    el('p', null, __('Uploading here adds the video to your library and inserts it into this post when it is ready. You can keep writing — you do not have to wait.', 'fastpix')),
                    el(C.Button, { variant: 'primary', href: ADD_URL, target: '_blank', rel: 'noopener' }, __('Open Add media', 'fastpix')),
                    el('p', { className: 'fp-pick-prov' }, __('Then come back and choose it from Your library.', 'fastpix'))),
            el('div', { className: 'fp-pick-footer' },
                el(C.Button, { variant: 'primary', disabled: !sel, onClick: function () { props.onInsert(sel); } }, __('Insert video', 'fastpix')),
                el(C.Button, { variant: 'tertiary', onClick: props.onClose }, __('Cancel', 'fastpix')),
                el('span', { className: 'fp-pick-prov' }, __('Private videos are marked. Focus returns to the block when this closes.', 'fastpix'))));
    }

    /* ------------------------------------------------------------ edit */

    function Edit(props) {
        var a = props.attributes, set = props.setAttributes;
        var _p = useState(false), picking = _p[0], setPicking = _p[1];
        var _v = useState(null), video = _v[0], setVideo = _v[1];
        var blockProps = useBlockProps();
        // A block inserted into a fresh post captures the auto-draft permalink; keep the fallback
        // link current as the post gets its real slug. (QA T18)
        var permalink = wp.data.useSelect(function (s) { var e = s('core/editor'); return (e && e.getPermalink && e.getPermalink()) || ''; }, []);
        useEffect(function () {
            if (a.videoId && permalink && permalink !== a.fallbackLink) { set({ fallbackLink: permalink }); }
        }, [permalink, a.videoId]);

        // Keep the fallback (poster + link) current — it is what renders when the plugin is inactive.
        useEffect(function () {
            if (!a.videoId) { setVideo(null); return; }
            var alive = true;
            // By media id — the list's search reads the FULLTEXT index, which never matches an id. [QA L9]
            apiFetch({ path: '/fastpix/v1/videos/by-media/' + encodeURIComponent(a.videoId) }).then(function (v) {
                if (!alive) { return; }
                setVideo(v || null);
                if (v) {
                    var link = (select('core/editor') && select('core/editor').getPermalink && select('core/editor').getPermalink()) || a.fallbackLink;
                    set({ fallbackTitle: v.title || '', fallbackPoster: v.access_policy === 'public' && v.poster ? v.poster.replace(/\?.*$/, '') : '', fallbackPolicy: v.access_policy || 'public', fallbackLink: link || '' });
                }
            }).catch(function () { if (alive) { setVideo(null); } });
            return function () { alive = false; };
        }, [a.videoId]);

        function insert(v) {
            set({ videoId: v.media_id });
            setPicking(false);
        }

        if (!a.videoId) {
            return el('div', blockProps,
                el(C.Placeholder, { icon: 'video-alt3', label: __('FastPix Video', 'fastpix'), instructions: __('Pick a video from your FastPix library, or upload one now. Your site defaults are already applied — you do not have to configure anything to insert it.', 'fastpix') },
                    el(C.Button, { variant: 'primary', onClick: function () { setPicking(true); } }, __('Choose from library', 'fastpix')),
                    el(C.Button, { variant: 'secondary', href: ADD_URL, target: '_blank', rel: 'noopener' }, __('Upload', 'fastpix'))),
                picking ? el(Picker, { onClose: function () { setPicking(false); }, onInsert: insert }) : null);
        }

        var isPrivate = video && video.access_policy !== 'public';
        var processing = video && video.status !== 'Ready';
        var unavailable = video && video.status === 'Unavailable';

        var inspector = el(InspectorControls, null,
            el(C.PanelBody, { title: __('Settings', 'fastpix'), initialOpen: true },
                video ? el('div', { className: 'fp-insp-video' },
                    video.poster ? el('img', { src: video.poster, alt: '' }) : null,
                    el('div', null, el('strong', null, video.title || __('(untitled)', 'fastpix')), el('div', { className: 'fp-insp-meta' }, (video.duration ? fmt(video.duration) : '—') + ' · ' + (isPrivate ? (video.access_policy === 'drm' ? '🔒 ' + __('DRM', 'fastpix') : '🔒 ' + __('Private', 'fastpix')) : __('Public', 'fastpix'))))) : null,
                // Access is a property of the video, set at upload — read-only
                // here; change it in the video library. [ASSUME-046]
                video ? el('p', { className: 'fp-insp-help' }, __('Access was set when the video was uploaded. Change it in the video library.', 'fastpix')) : null,
                el(C.Button, { variant: 'secondary', onClick: function () { setPicking(true); } }, __('Replace video', 'fastpix'))),
            el(C.PanelBody, { title: __('Playback', 'fastpix'), initialOpen: true },
                el(C.ToggleControl, { label: __('Autoplay', 'fastpix'), checked: !!a.autoplay, onChange: function (v) { set({ autoplay: v }); } }),
                el(C.ToggleControl, { label: __('Start muted', 'fastpix'), checked: !!a.muted, onChange: function (v) { set({ muted: v }); } }),
                el(C.ToggleControl, { label: __('Loop', 'fastpix'), checked: !!a.loop, onChange: function (v) { set({ loop: v }); } }),
                el(C.ToggleControl, { label: __('Resume where they stopped', 'fastpix'), checked: a.resume !== false, onChange: function (v) { set({ resume: v }); } }),
                el('p', { className: 'fp-insp-help' }, __('Site default unless changed here.', 'fastpix'))),
            el(C.PanelBody, { title: __('Controls', 'fastpix'), initialOpen: true },
                el(C.ToggleControl, { label: __('Player controls', 'fastpix'), checked: !!a.controls, onChange: function (v) { set({ controls: v }); } }),
                el(C.ToggleControl, { label: __('Click the video to play / pause', 'fastpix'), checked: a.clickToPlay !== false, onChange: function (v) { set({ clickToPlay: v }); } }),
                el(C.ToggleControl, { label: __('Keyboard shortcuts', 'fastpix'), checked: a.keyboard !== false, onChange: function (v) { set({ keyboard: v }); } })),
            // Completion panel — registered ONLY on LMS lesson post types, not
            // merely hidden: on any other post type the panel is never created.
            // [ASSUME-046]
            (function () {
                var types = (typeof fastpixBlock !== 'undefined' && fastpixBlock.lessonTypes) || [];
                var postType = wp.data && wp.data.select('core/editor') ? wp.data.select('core/editor').getCurrentPostType() : '';
                if (types.indexOf(postType) === -1) { return null; }
                // Prototype-matched (fastpix-block-prototype.html): a number
                // field with a % unit, and a "lesson" pill on the panel title.
                return el(C.PanelBody, {
                    title: el(Fragment, null, __('Completion', 'fastpix'), ' ', el('span', { className: 'fp-lesson-pill' }, __('lesson', 'fastpix'))),
                    initialOpen: true
                },
                    el('div', { className: 'fp-complete-row' },
                        el('label', { htmlFor: 'fp-complete-at' }, __('Mark complete at', 'fastpix')),
                        el('span', { className: 'fp-complete-input' },
                            el('input', {
                                id: 'fp-complete-at', type: 'number', min: 10, max: 100, step: 5,
                                value: a.completeAt || 90,
                                onChange: function (e) {
                                    var v = parseInt(e.target.value, 10);
                                    set({ completeAt: isNaN(v) ? 90 : Math.max(10, Math.min(100, v)) });
                                }
                            }),
                            el('span', { className: 'fp-insp-help' }, '%'))),
                    el('p', { className: 'fp-insp-help' }, __('Counts parts actually played. Skipping ahead does not fill them. When the student reaches it, this lesson is marked complete in your LMS automatically.', 'fastpix')),
                    el(C.ToggleControl, {
                        label: __('Record who watched', 'fastpix'),
                        checked: !!a.trackViewer,
                        onChange: function (v) { set({ trackViewer: v }); },
                        help: a.trackViewer
                            ? __('On — each learner’s watch data is stored against their account.', 'fastpix')
                            : __('Off — only totals are stored. Turn on to see per-learner watch data in Analytics.', 'fastpix')
                    }));
            })(),
            el(C.PanelBody, { title: __('Chapters & transcript', 'fastpix'), initialOpen: true },
                el(C.ToggleControl, { label: __('Chapters in the timeline', 'fastpix'), checked: !!a.showChapters, onChange: function (v) { set({ showChapters: v }); } }),
                el(C.ToggleControl, { label: __('Transcript block below', 'fastpix'), checked: !!a.showTranscript, help: isPrivate ? __('Not shown for private or DRM video.', 'fastpix') : '', onChange: function (v) { set({ showTranscript: v }); } }),
                el(C.ToggleControl, { label: __('Captions on by default', 'fastpix'), checked: !!a.captionsDefault, onChange: function (v) { set({ captionsDefault: v }); } })),
            el(C.PanelBody, { title: __('Appearance', 'fastpix'), initialOpen: false },
                el(C.BaseControl, { label: __('Accent colour', 'fastpix') }, el(C.ColorPalette, { value: a.accentColour, colors: [{ name: 'FastPix', color: '#6D22CD' }], onChange: function (v) { set({ accentColour: v || '#6D22CD' }); } })),
                el('p', { className: 'fp-insp-help' }, __('Poster, start time and aspect ratio are under Advanced.', 'fastpix'))),
            el(C.PanelBody, { title: __('Advanced', 'fastpix'), initialOpen: false },
                el(C.TextControl, { label: __('Poster image URL', 'fastpix'), value: a.poster, help: __('An image URL wins; otherwise the frame at the time below is used.', 'fastpix'), onChange: function (v) { set({ poster: v }); } }),
                el(C.NumberControl ? C.NumberControl : C.TextControl, { label: __('Poster frame time (seconds)', 'fastpix'), value: a.thumbnailTime, min: 0, onChange: function (v) { set({ thumbnailTime: Number(v) || 0 }); } }),
                el(C.NumberControl ? C.NumberControl : C.TextControl, { label: __('Start time (seconds)', 'fastpix'), value: a.startTime, min: 0, onChange: function (v) { set({ startTime: Number(v) || 0 }); } }),
                el(C.TextControl, { label: __('Aspect ratio', 'fastpix'), value: a.aspectRatio, placeholder: '16:9', onChange: function (v) { set({ aspectRatio: v }); } }),
                el(C.ToggleControl, { label: __('Deferred loading', 'fastpix'), checked: !!a.lazyLoad, onChange: function (v) { set({ lazyLoad: v }); } })));

        var notice = null;
        if (unavailable) {
            notice = el(C.Notice, { status: 'error', isDismissible: false },
                __('This video no longer exists on FastPix. Your post has not been changed. Visitors are seeing the saved poster and a link.', 'fastpix'), ' ',
                el(C.Button, { variant: 'link', onClick: function () { setPicking(true); } }, __('Replace video', 'fastpix')));
        } else if (processing) {
            notice = el(C.Notice, { status: 'info', isDismissible: false }, __('This video is still being processed. You can publish this post now — visitors will see the saved poster until it is ready, and the player appears by itself.', 'fastpix'));
        } else if (isPrivate) {
            notice = el(C.Notice, { status: 'warning', isDismissible: false }, __('This video is private — it will play for your visitors on this page because the plugin authorises it at page load. The embed code will not work if it is copied to another site, and social previews will show the poster without a token.', 'fastpix'));
        }

        // Guard the canvas preview: if wp-server-side-render didn't load on this
        // host, ServerSideRender is undefined and rendering it would throw and take
        // the sidebar (inspector) down with it. Fall back to the poster/title so
        // the options panels always show.
        var preview = ServerSideRender
            ? el(ServerSideRender, { block: 'fastpix/video', attributes: a })
            : el('div', { className: 'fp-canvas-fallback' },
                (video && video.poster) ? el('img', { src: video.poster, alt: '', style: { maxWidth: '100%', display: 'block' } }) : null,
                el('p', null, (video && video.title) || a.videoId),
                el('p', { className: 'fp-canvas-note' }, __('Live preview is unavailable in this editor; the video plays on the published page.', 'fastpix')));

        return el(Fragment, null, inspector,
            el('div', blockProps,
                notice,
                preview,
                el('p', { className: 'fp-canvas-note' }, __('What you see here is what a visitor sees. The block renders on the server, so a private video never saves an expiring address into your post.', 'fastpix')),
                picking ? el(Picker, { onClose: function () { setPicking(false); }, onInsert: insert }) : null));
    }

    /* ------------------------------------------------------------ save */

    // Static fallback ONLY (REQ-101 / RULE-037): public → poster + link;
    // private/DRM → message + link, no image (the poster is signed too).
    function Save(props) {
        var a = props.attributes;
        var blockProps = wp.blockEditor.useBlockProps.save({ className: 'fastpix-embed fastpix-embed--fallback' });
        if (!a.videoId) { return null; }
        var link = a.fallbackLink || '#';
        if (a.fallbackPolicy === 'public' && a.fallbackPoster) {
            return el('figure', blockProps,
                el('a', { href: link }, el('img', { src: a.fallbackPoster, alt: a.fallbackTitle || '' })),
                a.fallbackTitle ? el('figcaption', null, a.fallbackTitle) : null);
        }
        return el('figure', blockProps,
            el('figcaption', null, __('This video is not available right now.', 'fastpix'), ' ', el('a', { href: link }, a.fallbackTitle || __('Open the post', 'fastpix'))));
    }

    registerBlockType('fastpix/video', {
        edit: Edit,
        save: Save,
        deprecated: []   // RULE-036: attribute changes ship with an entry here.
    });
})(window.wp);
