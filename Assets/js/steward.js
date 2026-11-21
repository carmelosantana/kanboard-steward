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

    /*
     * Sign-in link page: a button with data-steward-copy="<input id>" copies that field. No inline script (CSP).
     * The Clipboard API needs a secure context; elsewhere the selected field is copied with execCommand.
     */
    document.addEventListener('click', function (event) {
        var button = event.target instanceof Element ? event.target.closest('[data-steward-copy]') : null;
        var field = button ? document.getElementById(button.getAttribute('data-steward-copy')) : null;
        if (!field) {
            return;
        }
        event.preventDefault();
        field.focus();
        field.select();
        var done = function () {
            var copied = button.getAttribute('data-steward-copied');
            if (copied) {
                button.textContent = copied;
            }
        };
        var fallback = function () {
            try {
                if (document.execCommand('copy')) {
                    done();
                }
            } catch (e) {
                // Left selected: the user can copy it by hand.
            }
        };
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(field.value).then(done, fallback);
        } else {
            fallback();
        }
    });
})();
