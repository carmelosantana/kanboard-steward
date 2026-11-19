# Mermaid under core CSP — spike result (2026-11-19)

**Result: Mermaid works under Kanboard's core CSP unchanged. No extra directive is needed.**
No `unsafe-eval`, no `worker-src`/`blob:`, no `script-src` change. Task 18 has nothing to merge
into the policy for Mermaid.

Policy tested (core `cspRules`, read back from the live `/dashboard` response on :8081):

```
Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; img-src * data:;
```

Mermaid's SVG output carries an inline `<style>` element and `style="max-width: …"` attributes;
both are covered by core's existing `style-src 'self' 'unsafe-inline'`.

## Vendored files (Task 15 vendors exactly these)

Downloaded as registry tarballs (no npm install, no scripts run); tarball `sha512` integrity
matched the registry value before extraction; only the files below were extracted.

| Package | Version | Published (npm) | Licence | Provenance | Tarball |
| --- | --- | --- | --- | --- | --- |
| `mermaid` | 11.17.2 (latest 11.x; npm `latest` is 12.3.0) | 2026-08-25T11:52:39Z | MIT | npm attestation present | https://registry.npmjs.org/mermaid/-/mermaid-11.17.2.tgz |
| `@highlightjs/cdn-assets` | 11.12.0 (latest 11.x and npm `latest`) | 2026-08-12T04:05:08Z | BSD-3-Clause | npm attestation present | https://registry.npmjs.org/@highlightjs/cdn-assets/-/cdn-assets-11.12.0.tgz |

Both are well past the 14-day age floor (86 and 99 days). Neither version declares
`preinstall`/`install`/`postinstall` scripts. Maintainers: mermaid — knsv, sidv, aloisklink,
ashishjain0512, p_brolin47; highlight.js — joshgoebel, isagalaev, marcosc, highlightjs_bot.

Tarball integrity (registry `dist.integrity`, verified locally with `openssl dgst -sha512`):

- `mermaid-11.17.2.tgz`: `sha512-V6K3C8EBdEsPFZXSKMJe6ppQOENxuHARr9GvHX4hh47lAbhMRD9qf4oEK7LoaRQxULMa80/qt5gHO73aCleBBg==`
- `cdn-assets-11.12.0.tgz`: `sha512-KvOKXODaiFmId9xaq3xc5xCL66wVLUuOngDbO9B/kewbFTqdGbn2nJxNhN3H5R1cgDTVj6R8vH0zgiNDEGjpDw==`

Extracted files (SHA-256):

| Tarball path | Size | SHA-256 |
| --- | --- | --- |
| mermaid `package/dist/mermaid.min.js` | 3,572,661 B | `581ed7d74bd9048d0e3a91363927d72ef22942d7722546b27f7cc29e35390eb8` |
| mermaid `package/LICENSE` | | `ec9fb67dcb25eccc416ed56e1aab819222c805a2a4bfe4cb19e7556bf2ffde80` |
| cdn-assets `package/highlight.min.js` | 129,254 B | `8ab71eb09c51f501e5e25157d9cff100e46cc29bcbfc744d0b746d451fca7f53` |
| cdn-assets `package/styles/github.min.css` | | `3a9a5def8b9c311e5ae43abde85c63133185eed4f0d9f67fea4b00a8308cf066` |
| cdn-assets `package/styles/github-dark.min.css` | | `9f208d022102b1d0c7aebfecd8e42ca7997d5de636649d2b31ea63093d809019` |
| cdn-assets `package/LICENSE` | | `6c081431591d9df696c82dc598fe1423765b8a299b200ed00b281afd0f64c490` |

`mermaid.min.js` is the self-contained IIFE build (sets `window.mermaid`, no lazy chunk loading).
A static scan of both minified files finds no `eval(`, `new Function`, `new Worker`,
`createObjectURL` or `importScripts`.

## Method

- Plugin mounted on the :8081 stack via `testing/docker-compose.steward-overlay.yml` (harness).
- Temporary (reverted) hooks: `template:layout:js` loaded `mermaid.min.js` and an external
  `init.js` as plugin assets; a template on `template:dashboard:show:before-filter-box` emitted
  three `<pre class="mermaid">` blocks: a flowchart (including a `click` directive), a sequence
  diagram with a note, and a gantt chart.
- `init.js`: `mermaid.initialize({ startOnLoad: false, securityLevel: 'strict' })`, then
  `mermaid.run({ querySelector: 'pre.mermaid' })`; a `securitypolicyviolation` listener
  counted any CSP violation.
- Firefox: a static page with the identical policy as a `<meta http-equiv>` CSP, same scripts
  and same three blocks (Kanboard login is not scriptable from headless `firefox`).

## Evidence

**Chromium 156 (built-in browser), logged in as admin, `/dashboard` with the server CSP header:**

```
[log] STEWARD-SPIKE: rendered 3 svg(s); CSP violations so far: 0
```

DOM check after render: three SVGs, sizes 431×174 (flowchart), 450×314 (sequence), 734×148
(gantt), node and label text present, each SVG with its inline `<style>`; `window.__cspViolations`
is `[]`. No `Refused to …` console messages.

**Google Chrome headless (`--dump-dom`, meta CSP page):**

```
INFO:CONSOLE:12] "STEWARD-SPIKE: rendered 3 svg(s); CSP violations so far: 0", source: …/.spike/init.js (12)
RESULT rendered=3 cspViolations=0 []
```

**Firefox 158.0.1 headless (meta CSP page, `devtools.console.stdout.content`):**

```
console.log: "STEWARD-SPIKE: rendered 3 svg(s); CSP violations so far: 0"
```

A headless screenshot showed all three diagrams drawn correctly.

## Notes for later tasks

- In the first two Chromium loads, Mermaid logged `Error: <rect> attribute width: A negative value
  is not valid. ("-37.5")` eight times. These are SVG layout warnings, not CSP; the first
  DOM read in that load also measured the SVGs at 0 width, so the likely cause is measuring
  while the browser pane had no layout. A later load with the pane visible logged none, and all diagrams
  still rendered. Task 17 should only run Mermaid on visible containers (for example, not inside
  a collapsed or hidden tab).
- The ~3.5 MB `mermaid.min.js` should load only on pages that contain `pre.mermaid`, not on
  every page through `template:layout:js` as the spike did.
- `securityLevel: 'strict'` disables `click` directives and HTML labels, as intended.
