/**
 * Deactivation feedback — intercepts the Deactivate link for THIS plugin only, asks once, then
 * follows the link either way.
 *
 * Bound on the document rather than on the link: core prints footer scripts BEFORE
 * admin_footer-plugins.php (wp-admin/admin-footer.php), so this file runs before the dialog
 * markup exists. Everything is therefore looked up at click time, not at load time.
 *
 * Deactivating is never blocked: Skip, Escape, the backdrop and the × all go straight through, a
 * failed request goes through anyway, and if this file does not load the link behaves normally.
 */
(function () {
    'use strict';

    var cfg = window.fastpixDeactivate || {};
    var i18n = window.wp && window.wp.i18n;
    function __(s) { return i18n ? i18n.__(s, 'fastpix') : s; }
    function el(id) { return document.getElementById(id); }

    if (!cfg.slug) { return; }

    var target = '';      // where the Deactivate link was going
    var sending = false;
    var opener = null;    // focus returns here if they close without deactivating

    function modal() { return el('fp-de'); }
    function go() { window.location.href = target; }   // the deactivation itself, untouched

    function close() {
        var m = modal();
        if (m) { m.hidden = true; }
        document.removeEventListener('keydown', onKey, true);
        if (opener && opener.focus) { opener.focus(); }
    }

    function onKey(e) {
        var m = modal();
        if (!m || m.hidden) { return; }
        if (e.key === 'Escape') { e.preventDefault(); close(); return; }
        if (e.key !== 'Tab') { return; }
        var f = m.querySelectorAll('button, input, textarea');
        if (!f.length) { return; }
        var first = f[0], last = f[f.length - 1];
        if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    }

    function open(link) {
        var m = modal();
        if (!m) { window.location.href = link.href; return; }   // no dialog: never stand in the way
        target = link.href;
        opener = link;
        sending = false;
        var send = el('fp-de-send');
        if (send) { send.textContent = __('Send & deactivate'); }
        m.hidden = false;
        document.addEventListener('keydown', onKey, true);
        var firstBox = m.querySelector('input[name="fp-de-reason"]');
        if (firstBox) { firstBox.focus(); }
    }

    function send() {
        if (sending) { return; }
        var m = modal();
        var picked = m.querySelector('input[name="fp-de-reason"]:checked');
        if (!picked) {
            var firstBox = m.querySelector('input[name="fp-de-reason"]');
            if (firstBox) { firstBox.focus(); }
            return;   // nothing chosen yet: wait rather than send an empty answer
        }
        sending = true;
        var btn = el('fp-de-send');
        if (btn) { btn.textContent = __('Sending…'); }
        var detail = el('fp-de-detail');
        // Deactivation must not hang on this: it proceeds on success, on failure and on a dead
        // connection alike.
        fetch(cfg.restUrl + '/feedback/deactivate', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
            credentials: 'same-origin',
            body: JSON.stringify({ reason: picked.value, detail: detail ? detail.value : '' })
        }).then(go, go);
    }

    document.addEventListener('click', function (e) {
        var m = modal();

        if (e.target.id === 'fp-de-skip') { go(); return; }
        if (e.target.id === 'fp-de-x') { close(); return; }
        if (e.target.id === 'fp-de-send') { send(); return; }
        if (m && !m.hidden && e.target === m) { close(); return; }   // backdrop

        var link = e.target.closest && e.target.closest('a[href*="action=deactivate"]');
        if (!link || e.metaKey || e.ctrlKey || e.shiftKey) { return; }   // a new tab is not a deactivation
        var row = link.closest('tr[data-plugin]');
        if (!row || row.getAttribute('data-plugin') !== cfg.slug) { return; }   // only this plugin's link
        e.preventDefault();
        open(link);
    });
}());
