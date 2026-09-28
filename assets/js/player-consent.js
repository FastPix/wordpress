/**
 * Viewer consent, decided before the FastPix player starts (WordPress.org guideline 7).
 *
 * The WordPress Consent API first, then the major platforms; window.fastpixConsent
 * (boolean or function) has the last word. No consent framework present = no signal
 * to refuse. Without consent the player's playback-quality reporting to FastPix is
 * switched off (`disable-data-monitoring`) — playback itself is unaffected — and
 * player.js records no watch progress. Loads before the player script, and also
 * catches players added to the page later (the live embed swaps them in).
 */
(function () {
    'use strict';

    window.fastpixHasConsent = function () {
        if (typeof window.fastpixConsent === 'function') { return !!window.fastpixConsent(); }
        if (typeof window.fastpixConsent === 'boolean') { return window.fastpixConsent; }
        try {
            if (typeof window.wp_has_consent === 'function') { return !!window.wp_has_consent('statistics'); }
            if (window.Cookiebot && window.Cookiebot.consent) { return !!window.Cookiebot.consent.statistics; }
            if (typeof window.OnetrustActiveGroups === 'string') { return window.OnetrustActiveGroups.indexOf('C0002') !== -1; }
            if (typeof window.getCkyConsent === 'function') { return !!(window.getCkyConsent().categories || {}).analytics; }
        } catch (e) { return false; }
        return true;
    };

    if (window.fastpixHasConsent()) { return; }
    function quiet(root) {
        if (root.nodeType !== 1) { return; }
        if (root.tagName === 'FASTPIX-PLAYER') { root.setAttribute('disable-data-monitoring', ''); }
        root.querySelectorAll('fastpix-player').forEach(function (p) { p.setAttribute('disable-data-monitoring', ''); });
    }
    quiet(document.documentElement);
    if (window.MutationObserver) {
        new MutationObserver(function (list) {
            list.forEach(function (m) { m.addedNodes.forEach(quiet); });
        }).observe(document.documentElement, { childList: true, subtree: true });
    }
})();
