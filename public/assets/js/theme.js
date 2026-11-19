/* Colour mode, applied before the page is drawn (loaded in <head>, so there is no flash of the wrong theme).
   The server writes the user's choice in data-theme-pref; for "auto" the device's preference decides,
   and the page follows it if it changes while open. A separate file because the CSP forbids inline scripts. */
(function () {
    'use strict';
    var root = document.documentElement;
    if (root.getAttribute('data-theme-pref') !== 'auto' || !window.matchMedia) {
        return;
    }
    var query = window.matchMedia('(prefers-color-scheme: dark)');
    var apply = function () {
        root.setAttribute('data-bs-theme', query.matches ? 'dark' : 'light');
    };
    apply();
    if (query.addEventListener) {
        query.addEventListener('change', apply);
    }
})();
