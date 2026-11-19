/*
 * Steward — client side of the safer Markdown (spec §4): syntax highlighting with the vendored highlight.js and
 * Mermaid diagrams. Vanilla JS (no jQuery), so it also runs on public pages that load no core scripts.
 *
 * Mermaid (~3.5 MB) loads only when a page holds a <pre class="mermaid">: a <script src> to the vendored file,
 * resolved next to this script's own URL and carrying its query (core's cache-buster), with no inline config
 * (core's CSP forbids inline script). After a failed load, later blocks are left as text.
 * Content core inserts later (the comment preview tab, modals) is picked up by a MutationObserver.
 * Every block is marked before it is touched, so each runs once however often a pass repeats.
 */
(function (window, document) {
    'use strict';

    var HL_MARK = 'data-steward-hl';
    var MERMAID_MARK = 'data-steward-mermaid';
    var mermaidUrl = resolveMermaidUrl();
    var mermaidState = 'idle'; // idle → loading → ready | failed
    var pendingMermaid = [];

    function resolveMermaidUrl() {
        var script = document.currentScript;
        var src = script && script.src;
        if (!src) {
            var tag = document.querySelector('script[src*="steward-markdown.js"]');
            src = tag && (tag.src || tag.getAttribute('src'));
        }
        if (!src) {
            return null;
        }
        var self = new URL(src, document.baseURI);
        var url = new URL('../vendor/mermaid.min.js', self);
        url.search = self.search; // core's cache-buster (?<mtime>), so a new release is fetched afresh
        return url.href;
    }

    function toArray(list) {
        return Array.prototype.slice.call(list);
    }

    /** Highlights each `<pre><code class="language-…">` once; Mermaid blocks and unknown languages are left alone. */
    function highlightWithin(root) {
        var hljs = window.hljs;
        if (!hljs) {
            return 0;
        }

        var count = 0;
        toArray(root.querySelectorAll('code[class*="language-"]')).forEach(function (code) {
            var pre = code.parentNode;
            if (code.hasAttribute(HL_MARK) || code.hasAttribute('data-highlighted')) {
                return;
            }
            if (!pre || pre.tagName !== 'PRE' || pre.classList.contains('mermaid')) {
                return;
            }
            code.setAttribute(HL_MARK, '');
            var match = /(?:^|\s)language-([\w#+.-]+)/.exec(code.getAttribute('class') || '');
            if (match && hljs.getLanguage(match[1])) {
                hljs.highlightElement(code);
                count++;
            }
        });

        return count;
    }

    /** Renders each `<pre class="mermaid">` once, loading Mermaid on first need. */
    function renderMermaidWithin(root) {
        if (mermaidState === 'failed') {
            return 0;
        }
        var nodes = toArray(root.querySelectorAll('pre.mermaid')).filter(function (node) {
            return !node.hasAttribute(MERMAID_MARK) && !node.hasAttribute('data-processed');
        });
        if (nodes.length === 0) {
            return 0;
        }

        nodes.forEach(function (node) {
            node.setAttribute(MERMAID_MARK, '');
        });
        if (mermaidState === 'ready') {
            runMermaid(nodes);
        } else {
            pendingMermaid = pendingMermaid.concat(nodes);
            loadMermaid();
        }

        return nodes.length;
    }

    function loadMermaid() {
        if (mermaidState !== 'idle') {
            return;
        }
        // Another plugin (or a page) may already have loaded Mermaid: reuse it rather than load a second copy.
        // mermaidReady() initialises it with Steward's strict settings either way.
        if (window.mermaid) {
            mermaidReady();
            return;
        }
        if (!mermaidUrl) {
            mermaidState = 'failed';
            pendingMermaid = [];
            return;
        }

        mermaidState = 'loading';
        var script = document.createElement('script');
        script.src = mermaidUrl;
        script.onload = mermaidReady;
        script.onerror = function () {
            mermaidState = 'failed';
            pendingMermaid = [];
        };
        document.head.appendChild(script);
    }

    function mermaidReady() {
        if (!window.mermaid) {
            mermaidState = 'failed';
            pendingMermaid = [];
            return;
        }
        mermaidState = 'ready';
        window.mermaid.initialize({ startOnLoad: false, securityLevel: 'strict' });
        var nodes = pendingMermaid;
        pendingMermaid = [];
        runMermaid(nodes);
    }

    function runMermaid(nodes) {
        // A diagram with a syntax error rejects; Mermaid draws its own error message in place of that block.
        Promise.resolve(window.mermaid.run({ nodes: nodes })).catch(function (error) {
            window.console.warn('Steward: Mermaid could not render a diagram', error);
        });
    }

    function processWithin(root) {
        highlightWithin(root);
        renderMermaidWithin(root);
    }

    /** The `.markdown` container an added node belongs to: itself or an ancestor, else the node if it holds one. */
    function markdownRootFor(node) {
        if (!node || node.nodeType !== 1) {
            return null;
        }
        return node.closest('.markdown') || (node.querySelector('.markdown') ? node : null);
    }

    function observe() {
        if (!window.MutationObserver || !document.body) {
            return;
        }
        new window.MutationObserver(function (mutations) {
            var roots = [];
            mutations.forEach(function (mutation) {
                toArray(mutation.addedNodes).forEach(function (node) {
                    var root = markdownRootFor(node);
                    if (root && roots.indexOf(root) === -1) {
                        roots.push(root);
                    }
                });
            });
            roots.forEach(processWithin);
        }).observe(document.body, { childList: true, subtree: true });
    }

    function start() {
        processWithin(document);
        observe();
    }

    window.StewardMarkdown = { highlightWithin: highlightWithin, renderMermaidWithin: renderMermaidWithin };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})(window, document);
