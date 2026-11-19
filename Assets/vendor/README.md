# Vendored assets

Third-party files shipped unmodified. Each was taken from its npm registry tarball (no
`npm install`, no scripts run) after the tarball's `sha512` integrity matched the registry value.
Vetting record: `docs/mermaid-csp-spike.md`. `Test/VendorTest.php` checks every file below against
its SHA-256 and fails if a `.js`/`.css` file here is not listed.

| File | Name | Version | Source | Tarball path | SHA-256 | Licence |
| --- | --- | --- | --- | --- | --- | --- |
| `highlight.min.js` | @highlightjs/cdn-assets | 11.12.0 | https://registry.npmjs.org/@highlightjs/cdn-assets/-/cdn-assets-11.12.0.tgz | `package/highlight.min.js` | `8ab71eb09c51f501e5e25157d9cff100e46cc29bcbfc744d0b746d451fca7f53` | BSD-3-Clause (`LICENSE-highlightjs`) |
| `highlight-github.css` | @highlightjs/cdn-assets | 11.12.0 | https://registry.npmjs.org/@highlightjs/cdn-assets/-/cdn-assets-11.12.0.tgz | `package/styles/github.min.css` | `3a9a5def8b9c311e5ae43abde85c63133185eed4f0d9f67fea4b00a8308cf066` | BSD-3-Clause (`LICENSE-highlightjs`) |
| `mermaid.min.js` | mermaid | 11.17.2 | https://registry.npmjs.org/mermaid/-/mermaid-11.17.2.tgz | `package/dist/mermaid.min.js` | `581ed7d74bd9048d0e3a91363927d72ef22942d7722546b27f7cc29e35390eb8` | MIT (`LICENSE-mermaid`) |

## Tarball integrity

- `cdn-assets-11.12.0.tgz`: `sha512-KvOKXODaiFmId9xaq3xc5xCL66wVLUuOngDbO9B/kewbFTqdGbn2nJxNhN3H5R1cgDTVj6R8vH0zgiNDEGjpDw==`
- `mermaid-11.17.2.tgz`: `sha512-V6K3C8EBdEsPFZXSKMJe6ppQOENxuHARr9GvHX4hh47lAbhMRD9qf4oEK7LoaRQxULMa80/qt5gHO73aCleBBg==`

## Licences

Upstream licence texts ship beside the files, copied from each tarball's `package/LICENSE`:

| File | SHA-256 |
| --- | --- |
| `LICENSE-highlightjs` | `6c081431591d9df696c82dc598fe1423765b8a299b200ed00b281afd0f64c490` |
| `LICENSE-mermaid` | `ec9fb67dcb25eccc416ed56e1aab819222c805a2a4bfe4cb19e7556bf2ffde80` |

## Updating

Fetch the new tarball with `curl` into an empty directory, check its `sha512` against the
registry's `dist.integrity`, extract only the paths above, then update this table. The test
fails until the table and the files agree.
