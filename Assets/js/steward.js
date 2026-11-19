/*
 * Steward — the header dropdown's Switch back is a POST form. Core's Dropdown.js shows the menu as a clone and
 * removes it on any click that reaches document, which happens before the button's default action runs, and a
 * form no longer in the page never submits. Clicks on Steward's dropdown buttons stop at window (capture phase):
 * the default action (the submit) still runs, core's closer never sees the click, and the page navigates away.
 */
(function () {
    'use strict';

    window.addEventListener('click', function (event) {
        if (event.target instanceof Element && event.target.closest('.steward-link-button')) {
            event.stopPropagation();
        }
    }, true);
})();
