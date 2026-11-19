// Tests for Assets/js/steward-markdown.js. Zero dependencies; from the harness root: node --test 'Steward/Test/js/*.test.mjs'
// The script runs in a vm context against the small fake DOM below (enough selector support for what it queries).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const SOURCE = readFileSync(new URL('../../Assets/js/steward-markdown.js', import.meta.url), 'utf8');
const SCRIPT_URL = 'http://kb.test/kanboard/plugins/Steward/Assets/js/steward-markdown.js?1700000000';

// --- fake DOM -------------------------------------------------------------------------------------------

/** One simple selector: tag, .class, tag.class, [attr], [attr*="v"], combined. */
function parseSelector(selector) {
    const m = /^([a-z]+)?((?:\.[\w-]+)*)((?:\[[\w-]+(?:\*?="[^"]*")?\])*)$/.exec(selector.trim());
    if (!m) throw new Error('fake DOM cannot parse selector ' + selector);
    const classes = m[2] ? m[2].slice(1).split('.') : [];
    const attrs = [...m[3].matchAll(/\[([\w-]+)(?:(\*?=)"([^"]*)")?\]/g)].map(a => ({ name: a[1], op: a[2], value: a[3] }));
    return el => (!m[1] || el.tagName === m[1].toUpperCase())
        && classes.every(c => el.classList.contains(c))
        && attrs.every(a => {
            if (!el.hasAttribute(a.name)) return false;
            const v = el.getAttribute(a.name);
            return a.op === '*=' ? v.includes(a.value) : a.op === '=' ? v === a.value : true;
        });
}

class FakeElement {
    constructor(tag, attrs = {}, children = []) {
        this.nodeType = 1;
        this.tagName = tag.toUpperCase();
        this.attributes = new Map(Object.entries(attrs));
        this.children = [];
        this.parentNode = null;
        const self = this;
        this.classList = { contains: c => (self.getAttribute('class') || '').split(/\s+/).includes(c) };
        children.forEach(c => this.appendChild(c));
    }
    getAttribute(n) { return this.attributes.has(n) ? this.attributes.get(n) : null; }
    setAttribute(n, v) { this.attributes.set(n, String(v)); }
    hasAttribute(n) { return this.attributes.has(n); }
    appendChild(c) { c.parentNode = this; this.children.push(c); return c; }
    descendants() { return this.children.flatMap(c => [c, ...c.descendants()]); }
    matches(s) { return parseSelector(s)(this); }
    querySelectorAll(s) { const p = parseSelector(s); return this.descendants().filter(p); }
    querySelector(s) { return this.querySelectorAll(s)[0] || null; }
    closest(s) { for (let e = this; e && e.nodeType === 1; e = e.parentNode) if (e.matches(s)) return e; return null; }
}

const el = (tag, attrs, children) => new FakeElement(tag, attrs, children);
const codeBlock = lang => el('pre', {}, [el('code', { class: 'language-' + lang })]);
const mermaidBlock = () => el('pre', { class: 'mermaid' });

function setup({ body = [], readyState = 'complete', hljs = true, currentScript = true } = {}) {
    const head = el('head');
    const bodyEl = el('body', {}, body);
    const html = el('html', {}, [head, bodyEl]);
    const listeners = {};
    const calls = { highlight: [], initialize: [], run: [] };
    const observers = [];

    const document = {
        readyState,
        head,
        body: bodyEl,
        documentElement: html,
        baseURI: 'http://kb.test/kanboard/task/1',
        currentScript: currentScript ? { src: SCRIPT_URL } : null,
        createElement: tag => el(tag),
        addEventListener: (type, fn) => { (listeners[type] = listeners[type] || []).push(fn); },
        querySelectorAll: s => html.querySelectorAll(s),
        querySelector: s => html.querySelector(s),
    };
    const window = {
        document,
        URL,
        console: { warn() {}, error() {} },
        MutationObserver: class {
            constructor(fn) { this.fn = fn; observers.push(this); }
            observe(target, opts) { this.target = target; this.opts = opts; }
        },
    };
    if (hljs) {
        window.hljs = {
            getLanguage: lang => (['php', 'javascript'].includes(lang) ? {} : undefined),
            highlightElement: node => { calls.highlight.push(node); node.setAttribute('data-highlighted', 'yes'); },
        };
    }
    window.window = window;
    const context = vm.createContext(window);
    vm.runInContext(SOURCE, context);

    const loadMermaid = () => {
        context.mermaid = {
            initialize: cfg => calls.initialize.push({ ...cfg }), // copies: vm-realm objects fail deepStrictEqual
            run: opts => { calls.run.push(Array.from(opts.nodes)); return Promise.resolve(); },
        };
        const script = head.querySelector('script');
        script.onload();
    };

    return { window: context, document, head, body: bodyEl, calls, listeners, observers, loadMermaid, api: context.StewardMarkdown };
}

// --- highlighting ---------------------------------------------------------------------------------------

test('highlight runs once per block and marks it', () => {
    const php = codeBlock('php');
    const js = codeBlock('javascript');
    const { api, calls, body } = setup({ body: [el('div', { class: 'markdown' }, [php, js])] });

    assert.deepEqual(calls.highlight, [php.children[0], js.children[0]], 'DOMContentLoaded already passed: runs at once');
    assert.equal(php.children[0].getAttribute('data-steward-hl'), '');

    api.highlightWithin(body);
    assert.equal(calls.highlight.length, 2, 'a second pass changes nothing');
});

test('highlight skips mermaid, already highlighted, unlabelled and unknown-language blocks', () => {
    const inMermaid = el('code', { class: 'language-php' });
    const done = el('code', { class: 'language-php hljs', 'data-highlighted': 'yes' });
    const plain = el('code');
    const unknown = el('code', { class: 'language-klingon' });
    const { calls } = setup({
        body: [el('div', { class: 'markdown' }, [
            el('pre', { class: 'mermaid' }, [inMermaid]), el('pre', {}, [done]), el('pre', {}, [plain]), el('pre', {}, [unknown]),
        ])],
    });

    assert.equal(calls.highlight.length, 0);
});

test('without highlight.js nothing breaks', () => {
    const { api, body } = setup({ hljs: false, body: [codeBlock('php')] });
    assert.equal(api.highlightWithin(body), 0);
});

// --- Mermaid --------------------------------------------------------------------------------------------

test('mermaid loader is not injected without a block', () => {
    const { head, api, body } = setup({ body: [el('div', { class: 'markdown' }, [codeBlock('php')])] });

    api.renderMermaidWithin(body);
    assert.equal(head.querySelectorAll('script').length, 0);
});

test('mermaid loader is injected once for several blocks, next to this script', async () => {
    const a = mermaidBlock();
    const b = mermaidBlock();
    const { head, api, body, calls, loadMermaid } = setup({ body: [el('div', { class: 'markdown' }, [a, b])] });

    api.renderMermaidWithin(body);
    const scripts = head.querySelectorAll('script');
    assert.equal(scripts.length, 1);
    assert.equal(scripts[0].src, 'http://kb.test/kanboard/plugins/Steward/Assets/vendor/mermaid.min.js?1700000000',
        "carries this script's cache-buster");

    loadMermaid();
    assert.deepEqual(calls.initialize, [{ startOnLoad: false, securityLevel: 'strict' }]);
    assert.deepEqual(calls.run, [[a, b]]);

    const c = mermaidBlock();
    body.children[0].appendChild(c);
    api.renderMermaidWithin(body);
    assert.equal(head.querySelectorAll('script').length, 1, 'no second loader');
    assert.equal(calls.initialize.length, 1, 'initialised once');
    assert.deepEqual(calls.run, [[a, b], [c]], 'only the new block runs');
});

test('blocks found while the loader is in flight join the first run', () => {
    const a = mermaidBlock();
    const { head, api, body, calls, loadMermaid } = setup({ body: [el('div', { class: 'markdown' }, [a])] });

    const b = mermaidBlock();
    body.children[0].appendChild(b);
    api.renderMermaidWithin(body);
    assert.equal(head.querySelectorAll('script').length, 1);

    loadMermaid();
    assert.deepEqual(calls.run, [[a, b]]);
});

test('without currentScript the mermaid URL comes from this script tag', () => {
    const tag = el('script', { src: '/kanboard/plugins/Steward/Assets/js/steward-markdown.js?1' });
    const { head, api, body } = setup({ currentScript: false, body: [tag, el('div', { class: 'markdown' }, [mermaidBlock()])] });

    api.renderMermaidWithin(body);
    assert.equal(head.querySelector('script').src, 'http://kb.test/kanboard/plugins/Steward/Assets/vendor/mermaid.min.js?1');
});

test('without a query on this script the mermaid URL has none', () => {
    const tag = el('script', { src: '/kanboard/plugins/Steward/Assets/js/steward-markdown.js' });
    const { head, api, body } = setup({ currentScript: false, body: [tag, el('div', { class: 'markdown' }, [mermaidBlock()])] });

    api.renderMermaidWithin(body);
    assert.equal(head.querySelector('script').src, 'http://kb.test/kanboard/plugins/Steward/Assets/vendor/mermaid.min.js');
});

test('after a failed load new blocks are left alone', () => {
    const a = mermaidBlock();
    const { head, api, body, calls } = setup({ body: [el('div', { class: 'markdown' }, [a])] });
    assert.equal(a.getAttribute('data-steward-mermaid'), '');
    head.querySelector('script').onerror();

    const b = mermaidBlock();
    body.children[0].appendChild(b);
    assert.equal(api.renderMermaidWithin(body), 0, 'nothing queued');
    assert.equal(b.hasAttribute('data-steward-mermaid'), false);
    assert.equal(head.querySelectorAll('script').length, 1, 'no second loader');
    assert.deepEqual(calls.run, []);
});

// --- start-up and late content --------------------------------------------------------------------------

test('waits for DOMContentLoaded while the document is loading', () => {
    const php = codeBlock('php');
    const { calls, listeners } = setup({ readyState: 'loading', body: [el('div', { class: 'markdown' }, [php])] });

    assert.equal(calls.highlight.length, 0);
    listeners.DOMContentLoaded.forEach(fn => fn());
    assert.deepEqual(calls.highlight, [php.children[0]]);
});

test('content added later inside or as a .markdown container is processed', () => {
    const { observers, calls, body, head } = setup();
    assert.equal(observers.length, 1);
    assert.equal(observers[0].target, body);
    assert.deepEqual({ ...observers[0].opts }, { childList: true, subtree: true });

    // Preview tab: core sets innerHTML of an existing .markdown div; the added nodes are its children.
    const preview = el('div', { class: 'text-editor-preview-area markdown' });
    body.appendChild(preview);
    const added = codeBlock('php');
    preview.appendChild(added);
    observers[0].fn([{ addedNodes: [added] }]);
    assert.deepEqual(calls.highlight, [added.children[0]]);

    // Modal: a wrapper holding a .markdown container is added.
    const modalCode = codeBlock('javascript');
    const modal = el('div', { id: 'modal-box' }, [el('div', { class: 'markdown' }, [modalCode, mermaidBlock()])]);
    body.appendChild(modal);
    observers[0].fn([{ addedNodes: [modal] }]);
    assert.deepEqual(calls.highlight, [added.children[0], modalCode.children[0]]);
    assert.equal(head.querySelectorAll('script').length, 1, 'mermaid loads for a block in a modal');

    // Unrelated nodes and text nodes are ignored.
    const outside = codeBlock('php');
    body.appendChild(outside);
    observers[0].fn([{ addedNodes: [outside, { nodeType: 3 }] }]);
    assert.equal(calls.highlight.length, 2);
});
