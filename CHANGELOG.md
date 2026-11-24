# Changelog

## 1.2.1 — 2026-11-24

### Changed

- **Sign-in links:** replaying a link that was already used, has expired or no longer fits its user is audited
  once per link. Further attempts with the same link write nothing, so a leaked link can't be used to fill the
  audit log. Schema version 4 adds a `failure_logged` column to `steward_login_link`.
- The switched-user read row for Presence names `PresenceSettingsController`, Presence's settings controller
  from 1.0.2. With an older Presence the page is blocked while switched, as for any unlisted action.

## 1.2.0 — 2026-11-24

### Changed

- **Layout wrappers stack.** Steward's layout wrapper now includes whichever layout was registered before it, instead of core's own. Another wrapper, such as Harbor's server-rendered theme, keeps working alongside Steward's branding in either load order.

## 1.1.0 — 2026-11-21

### Added

- **Sign-in links:** on a user's profile, an admin can make a one-time link that signs that user in. A link
  works once, for 15 minutes, and is shown only when it is made; making another cancels it. Opening the link
  shows a **Sign in** button, so a link preview doesn't use it up. Two-factor authentication is still asked
  for. Only active, non-admin users who are not LDAP users, not locked out and not set to **Disallow login
  form**; at most 10 links per admin per hour. Making links for a user is serialised (a READ COMMITTED
  transaction; on MySQL/MariaDB that needs `binlog_format` ROW or MIXED, not STATEMENT), so two at once still
  leave only one live link. Links are built from the Application URL, which must be set, and follow
  whichever URL rewriting is in effect (query-string links when it is off). Audited as `login_link.created`,
  `login_link.refused`, `login_link.used` and `login_link.failed`; mistyped or unknown links are not audited.
- The feature is off by default (**Settings → Steward**); `STEWARD_DISABLE_LOGIN_LINKS` in `config.php`, or
  reverse-proxy authentication, keeps it off.

## 1.0.0 — 2026-11-19

First release. Every feature has its own switch under **Settings → Steward**; on a fresh install only
Markdown is on.

### Added

- **User switching:** admins can view Kanboard as another (non-admin, active) user, with a banner and a
  **Switch back** that signs the browser out to sign in again. Account-security pages are blocked while
  switched, every core action is classified read / write / blocked, other plugins are blocked unless they
  register with `stewardSwitching->allow()`, and every write is audited. Idle timeout setting (default 60
  minutes) and a `STEWARD_DISABLE_SWITCHING` kill switch for `config.php`.
- **Audit log:** switch, login, branding, banner, test-email, settings and public-page events in the
  `steward_audit` table, a filterable admin page, retention setting (default 180 days), and
  `stewardAudit->log()` for other plugins' `plugin.<name>.<event>` events.
- **Branding:** site name, logo and favicon (PNG, JPEG, WebP or GIF, re-encoded to PNG), on every page and the
  login page, with **Import from ShadcnTheme**.
- **Markdown:** safe mode and a link/image scheme allow-list (`http`, `https`, `mailto`, relative) on top of
  core's escaping; task lists, heading anchors, syntax highlighting (highlight.js) and Mermaid diagrams.
- **Project pages:** a per-project **Home** view with a header (tagline, links) and chosen panels; an opt-in
  anonymous public page on core's public access token; listed projects at `/explore/:identifier` and a
  public directory at `/explore`.
- **Test email:** a button on Settings → Email that sends to your own address through the active transport,
  with redacted error logging.
- **Announcement banner:** a scheduled, optionally dismissible site-wide message, also on the login page and
  public pages if chosen.
- Tested on Kanboard 1.2.47 and 1.2.57.

### Notes

- Switching back means signing in again; starting a switch also signs you out of "remember me" in that
  browser.
- `#N` task references stay plain text in notification emails.
- If another plugin uses Kanboard's `text` helper before Steward loads, Steward's Markdown can't take over and
  logs an error; core's renderer stays in place (on 1.2.47 without safe mode).
- Plugins that add assets through core's layout hooks also load on Steward's public pages.
- Until ShadcnTheme 1.2.0, the login page shows ShadcnTheme's header above Steward's logo and site name.
- Turning a project's public access back on brings its Steward public page back, at a new token URL.
- Public pages show `[hidden]` in place of any project's public access token, however it is written.
- While switched, the user summary page (core's **My profile** link) is refused, as it shows the user's feed
  token.
- Known gap: there is no `steward:switch` hook event yet; other plugins can check
  `stewardSwitching->isSwitched()`.
- A switch that ends because the PHP session itself expired leaves no `switch.end` row.
- Public project pages at `/public/project/:token` are never indexed; only listed projects' `/explore` pages
  can be.
