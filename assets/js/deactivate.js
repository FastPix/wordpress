/**
 * Deactivation confirm — intercepts the Deactivate link for THIS plugin only and asks once.
 *
 * Bound on the document rather than on the link: core prints footer scripts BEFORE
 * admin_footer-plugins.php (wp-admin/admin-footer.php), so this file runs before the dialog
 * markup exists. Everything is therefore looked up at click time, not at load time.
 *
 * Cancel, Escape, the backdrop and the × close it; Deactivate follows the link. If this file does
 * not load, or the dialog is missing, the link behaves normally.
 */
(function () {
    'use strict';

    var cfg = window.fastpixDeactivate || {};
    function el(id) { return document.getElementById(id); }

    if (!cfg.slug) { return; }

    var target = '';      // where the Deactivate link was going
    var opener = null;    // focus returns here if they cancel

    function modal() { return el('fp-de'); }

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
        var f = m.querySelectorAll('button');
        var first = f[0], last = f[f.length - 1];
        if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    }

    function open(link) {
        var m = modal();
        if (!m) { window.location.href = link.href; return; }   // no dialog: never stand in the way
        target = link.href;
        opener = link;
        m.hidden = false;
        document.addEventListener('keydown', onKey, true);
        el('fp-de-cancel').focus();   // the safe choice has focus
    }

    document.addEventListener('click', function (e) {
        var m = modal();

        if (e.target.id === 'fp-de-go') { window.location.href = target; return; }
        if (e.target.id === 'fp-de-x' || e.target.id === 'fp-de-cancel') { close(); return; }
        if (m && !m.hidden && e.target === m) { close(); return; }   // backdrop

        var link = e.target.closest && e.target.closest('a[href*="action=deactivate"]');
        if (!link || e.metaKey || e.ctrlKey || e.shiftKey) { return; }   // a new tab is not a deactivation
        var row = link.closest('tr[data-plugin]');
        if (!row || row.getAttribute('data-plugin') !== cfg.slug) { return; }   // only this plugin's link
        e.preventDefault();
        open(link);
    });
}());
