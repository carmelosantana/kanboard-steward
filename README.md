# Steward (Kanboard plugin)

Admin tools, branding, Markdown and project pages for Kanboard: user switching with an audit log, a site
logo, favicon and name, a safer and richer Markdown renderer, a project Home page, opt-in public project
pages and a public directory, a send-test-email button and a site-wide announcement banner. Each feature
has its own switch under **Settings → Steward**.

## Install

Download `Steward-1.0.0.zip` from the releases page and unzip it into `plugins/Steward/` (the zip's single top-level folder is `Steward`). Requires Kanboard `>=1.2.47` and PHP
`>=8.4` with GD. Steward adds two tables (`steward_audit`, `steward_project_page`) through core's plugin
migrations on SQLite, MySQL, Postgres and SQL Server. The SQLite schema runs in the test suite; the MySQL/MariaDB
and PostgreSQL schemas have been run against real servers; the SQL Server schema is written but has not been run.

Install manually from the release zip for now; ModMenu can install it once Steward is listed there.

Recommended: [Resend](https://github.com/carmelosantana/kanboard-resend) 1.0.0 or later, for a working
email transport behind the test-email button (any transport works).

On a fresh install only **Markdown** is on. Nothing else changes for users until an admin turns a feature
on.

## Features and settings

**Settings → Steward** (admins only) holds one section per feature:

| Feature | Default | Settings | What it does |
|---|---|---|---|
| Markdown | on | — | Safer Markdown everywhere core renders it, plus task lists, heading anchors, syntax highlighting and Mermaid diagrams. |
| User switching | off | Idle timeout, 5–480 minutes (default 60) | An admin can view Kanboard as another user. |
| Branding | off | Site name, logo, favicon (on **Steward branding**) | Replaces "Kanboard", the logo and the favicon. |
| Project pages | off | Per project, under the project's settings | A **Home** view for signed-in users. |
| Public pages | off | Per project | An anonymous page at `/public/project/:token`. |
| Explore | off | — | A public directory at `/explore` and clean URLs `/explore/:identifier` for listed projects. |
| Test email | off | — | **Send test email** on core's Settings → Email page and in Steward's settings. |
| Announcement banner | off | Message, level, schedule, where it shows (on **Steward banner**) | A site-wide banner. |
| Sign-in links | off | — | An admin can make a one-time link that signs a user in. |

The **Audit log** section sets retention, 7–3650 days (default 180). The audit log page is always
available to admins as **Settings → Steward audit log**.

Settings save accepts only Steward's known keys. Ops kill switch: `define('STEWARD_DISABLE_SWITCHING',
true);` in `config.php` turns switching off whatever the settings page says, and
`define('STEWARD_DISABLE_LOGIN_LINKS', true);` does the same for sign-in links.

### Markdown

Steward replaces core's `text` helper with one whose Markdown renderer:

- keeps core's HTML escaping and turns on safe mode;
- allows only `http`, `https`, `mailto` and relative URLs in links and images; anything else shows as plain
  text (this holds in notification emails too);
- renders `- [ ]` / `- [x]` as read-only checkboxes, gives headings `md-` prefixed ids with a hover anchor,
  keeps a heading that contains `#N`, highlights fenced code with highlight.js and draws ```` ```mermaid ````
  blocks with Mermaid (loaded only on pages that have a diagram, `securityLevel: 'strict'`).

highlight.js and Mermaid are vendored and pinned; versions, SHA-256 sums and licences are in
`Assets/vendor/README.md`. All script is external, so core's Content Security Policy is unchanged.

### User switching

On a user's profile an admin sees **Switch to this user** when the switch is allowed. A confirmation dialog
names the user; confirming signs the browser in as that user and shows a banner, "You are viewing Kanboard as
…", with **Switch back**. Details are under [Security model](#security-model).

### Branding

Site name (plain text, up to 60 characters), logo (fit within 512×128) and favicon (32×32 and 180×180
apple-touch). Uploads are PNG, JPEG, WebP or GIF up to 2 MB, at most 4096 px a side and 16 megapixels. Each
upload is decoded and re-encoded to PNG with GD, so only pixels are kept; SVG and ICO are refused. Images are
stored under `steward/branding/` in core's object storage and served with their content hash in the URL. A
missing slot falls back to core's default. When ShadcnTheme 1.1 holds a logo or favicon and Steward's slot is
empty, the page offers **Import from ShadcnTheme** (through the same pipeline; an SVG can't be imported).

Other plugins can use `$this->helper->stewardBranding->logoUrl()`, `faviconUrl(32)` (or `180`) and `siteName()`; each
returns `''` when unset or while branding is off.

### Project pages

Project managers and admins get **Project page** in the project's settings sidebar:

- **Header:** a tagline (one line, up to 160 characters) and up to five links (label up to 40 characters and
  an `http(s)` address).
- **Panels** for Home, each with a position: header, description (through the Markdown renderer), progress
  (open/closed counts and a bar per column), board snapshot, recent activity (core's stream, last 20),
  recently completed (last 14 days, up to 50) and files. Defaults: header, description, progress, recent
  activity. Home shows only what the viewer can already see.
- **Public page** (needs the Public pages feature and the project's core public access), with its own panel
  choice from header, description, progress, board snapshot, recently completed and activity — never files.
  Defaults: header, description, progress.
- **Listed in the project directory** (needs the public page, a project identifier and the Explore feature).
- **Show names in public activity** adds each actor's display name, never a username or email.

The settings are stored in Steward's own table, `steward_project_page`, and checked again whenever they are
read: an invalid value is dropped, and the public page and listing stay off while core public access or the
identifier no longer allow them.

### Test email

**Send test email** sends a short message straight through the active transport (not the queue) to the
signed-in admin's own profile address; there is no recipient field. One send per admin per 30 seconds; the
button is disabled when the profile has no email address. On success the flash says the message was handed to
the transport without error (core's SMTP and mail transports log failures rather than raise them, so check
your inbox). On failure it shows a generic message and a short reference; the detail goes to the server log
with credentials redacted (authorization headers, password/secret/token/key/user values, URL user info, SMTP
AUTH lines and the configured SMTP username and password). Each send is audited as `email.test`.

### Announcement banner

A message up to 500 characters with *emphasis*, **strong**, `code` and `http(s)` links (anything else shows
as typed), a level (info or warning), an optional start and end time, and where it shows: always on
signed-in pages, optionally on the login page and on Steward's public pages and directory (not core's public
board or task views). If dismissible, a signed-in user's dismissal lasts until the message, level or times
change. A non-dismissible banner shows to everyone, whatever they dismissed earlier. Defaults: dismissible on;
login page and public pages off.

### Sign-in links

For a user who can't sign in (a lost password, a new account), an admin can make a link that signs that user
in once. On the user's profile, **Sign-in link** creates one and shows it a single time, with a copy button
and the time it stops working. Send it to the user however you like.

- **Who can make one:** admins, after their own two-factor step, and not while switched to another user.
- **For whom:** active users who are not admins and not yourself. LDAP users are refused, as are users
  locked out after failed logins and users with **Disallow login form** set (`disable_login_form`), whom
  Kanboard wouldn't let sign in with a password either.
- **15 minutes, once.** A link works for 15 minutes and only once. Making a new link for the same user
  cancels the earlier one, even when two admins make one at the same moment. An admin can make at most 10
  links an hour. The link is a bearer credential: whoever has it can use it, and it shows up in server access
  logs and browser history, which is why it lasts 15 minutes and works once.
- **Opening the link** shows "Sign in to *site* as *username*" with a **Sign in** button; only the button
  uses the link, so a chat or mail preview that opens it doesn't spend it. Someone already signed in in that
  browser is asked to sign out first.
- **Two-factor authentication** is still asked for: the link replaces the password, not the code.
- **Application URL required.** Links are built from **Settings → Application settings → Application URL**,
  never from the request's host; with no Application URL set, no link is made. The rest of the link follows
  whichever URL rewriting is in effect: `login-link/…` with rewriting on, `?controller=…` with it off.
  That depends on the server setup: off in Kanboard's `config.default.php`, on in the official Docker image and
  with Apache's `.htaccess`.
- An unknown, used, expired or mistyped link always gets the same page: "This sign-in link is invalid or has
  expired." Only a hash of each link is stored.

The feature is off by default; turn it on under **Settings → Steward**. `STEWARD_DISABLE_LOGIN_LINKS` in
`config.php`, or reverse-proxy authentication (`REVERSE_PROXY_AUTH`), keeps it off whatever the setting says.
Making, refusing, using and failed links are audited as `login_link.created`, `login_link.refused`,
`login_link.used` and `login_link.failed`. A failed link is one that was used, expired or no longer valid for
its user; a mistyped or unknown link isn't audited, since nothing ties it to a user and anyone could send one.

## Security model

### Switching rules

- Only app admins can switch, and only to an active user who is not an app admin and not themselves. A
  switched session can't start another switch. An admin who uses two-factor authentication must have passed
  it in the current session. Every refusal shows the same message; the real reason is audited as
  `switch.refused`.
- A switch never gives the admin more than the target user has: the session holds exactly what core's own
  sign-in would give the target, with the admin's id kept only in the PHP session (no cookie, form field or
  URL carries it).
- Starting is a POST with CSRF. Steward audits `switch.start` first and refuses the switch if that write
  fails, revokes this browser's remember-me login for the admin (refusing if it can't), regenerates the
  session id and clears the old session. The target's last-login record is untouched and no remember-me
  cookie is set for the target.
- **Switch back = sign in again.** Switch back (a POST with CSRF) audits `switch.end`, ends the session and
  sends the browser to the login page; after signing in as usual (password and 2FA) the admin lands on the
  target's profile. Nothing in a switched session restores admin rights by itself.
- A switch ends and the browser is logged out when the idle timeout passes (`switch.expired`; board
  auto-refresh doesn't count as activity), when the admin loses admin rights or is disabled, when core would
  no longer accept the target's session (role or active flag changed; on Kanboard 1.2.57 also a password
  change), or when switching is turned off (`switch.revoked`). Logging out while switched
  ends everything.
- With reverse-proxy authentication (`REVERSE_PROXY_AUTH`) switching is unavailable.
- The API and JSON-RPC never open a PHP session, so switching doesn't affect them.

### What a switched session may do

Every request while switched is classified by `(controller, action)`:

| Class | Examples | While switched |
|---|---|---|
| read | dashboards, boards, task and project views, search | allowed |
| write-allowed | everyday work: board moves, tasks, subtasks, comments, task files, links, tags, project settings the user could already change | allowed, audited as `switch.action` (method, controller, action, task and project id) **before** it runs; if that row can't be written the answer is 503 and the action doesn't run |
| blocked | password, 2FA, API token, email, username, avatar, sessions, OAuth linking, the user summary and public access pages (they show the user's RSS/iCal links), user/config/plugin admin pages, Steward's own settings | 403 "not while switched", read and write |

Every core action of Kanboard 1.2.47 and 1.2.57 is in the table; anything not in it is blocked. Core mutates on GET in places, so
those actions are classified as writes whatever the method.

**Other plugins are blocked while switched, reads included,** unless they opt in. A plugin registers its
actions, keyed by its own name so it never covers another plugin's same-named controller:

```php
if (isset($this->container['stewardSwitching'])) {
    $this->container['stewardSwitching']->allow('MyPlugin', [
        'MyController::index' => 'read',
        'MyController::save'  => 'write',
    ]);
}
```

Steward ships read registrations for the plain view pages of Pulse, Presence, Bigboard, TimeReport and
Agents. A plugin that holds secrets can check `$this->container['stewardSwitching']->isSwitched()` and refuse.

### Audit log

Table `steward_audit`; the page (admins only) is newest first with filters for event, user, date range and
switch, read-only. Each switch's rows share a switch id; the page shows its first 8 characters, linked to that
switch's rows. Events: `switch.start`, `switch.end`, `switch.expired`, `switch.revoked`, `switch.refused`,
`switch.action`, `login.success`, `login.failure` (username as typed, up to 64 characters; failures capped at
10,000 rows), `login_link.created`, `login_link.refused`, `login_link.used`, `login_link.failed` (capped like
`login.failure`), `branding.changed`, `banner.changed`, `email.test`, `settings.changed` (keys, never secret
values) and `public.changed`. API logins: failures are recorded (`source=api`), successes are not. Rows older
than the retention period are pruned at most once a day.

Other plugins can write their own events:

```php
$this->container['stewardAudit']->log('plugin.myplugin.export', $actorUserId, $subjectUserId, ['rows' => 12]);
```

Events must be named `plugin.<name>.<event>`; anything else is rejected. `log()` returns `false` instead of
throwing. Listeners on the `steward:audit` hook are called after each write.

### Feature switches and access

Every Steward route and ACL rule is registered whatever the switches say; a switched-off feature's pages
answer a fixed 404 before core's ACL runs, and each action checks again. Admin pages are `APP_ADMIN`;
per-project settings are project-manager only and take `project_id` from the URL, never the form. If another
plugin replaces one of Steward's ACL rules, Steward refuses all its own actions (403) and every switch, and
logs the conflict.

### Public pages

- A public page needs both core's public access for the project and Steward's **Public page** toggle, so
  turning public board access on never publishes a page by accident.
- **What core public access already exposes:** anyone with the project's token link can see core's read-only
  board (with assignees), the RSS feed, the iCal feed and task pages (with comments and subtasks). Steward's
  pages don't add to that, and don't share the link.
- Steward's public pages show only the chosen panels; never files, comments, member lists, emails or task
  descriptions. `#N` and `@user` are plain text. Board snapshot titles are not links. Activity shows task
  created, moved, closed and reopened, without names unless **Show names** is on.
- Every project's core token is replaced with `[hidden]` wherever it appears in a Steward public page (for
  example a core board link pasted into the description, a task title or the tagline), in any case and also
  when percent-encoded, written with HTML character references or split by markup. No Steward page links to
  core's token URLs.
- `/public/project/:token` is always `noindex`. Only `/explore` and `/explore/:identifier` of listed projects
  may be indexed. Every Steward public page sends `Referrer-Policy: no-referrer`.
- Archived (inactive) projects are left out of the directory.
- Turning core public access off takes the page down; turning it on again issues a new token, and the old
  link stops working.

## Known limitations

- **Markdown fallback.** If another plugin used the `text` helper before Steward loaded, Steward can't
  replace it: core's renderer stays in place and an error is logged. On Kanboard 1.2.47 core's renderer has
  no safe mode, so that install keeps stock 1.2.47 behaviour. Kanboard 1.2.57 core turns safe mode on itself.
- **Other plugins on public pages.** Plugins that add CSS or JS through core's layout hooks also load on
  Steward's public pages. Those pages are anonymous and carry no user data.
- **Anonymous session cookie.** Anonymous visitors to Steward's public pages get core's session cookie, as on
  any core page.
- **`#N` in notification emails** stays plain text: core would link it with the owning project's public
  token, which would hand that token to every recipient.
- **Login page with ShadcnTheme 1.1.** Until ShadcnTheme 1.2.0, its login card header shows above Steward's
  logo and site name.
- **A public page returns with public access.** Turning core public access off hides the page but keeps
  Steward's toggle; turning it on again brings the page back (with a new token). The project settings page
  says so.
- **Admin password change.** Changing the admin's own password during a switch doesn't end the switch; the
  switched session only carries the target's rights, and switching back signs you out.
- **Natural session expiry** (PHP's own session lifetime, not Steward's idle timeout) leaves no `switch.end`
  row; the switch simply ends with the session.
- **Accepted risks.** A request already in flight on the admin's pre-switch session id can write that
  session back after the switch deletes it (core's DB session handler doesn't lock; core's logout has the
  same window). It is the admin's own old session, usable only by whoever already held it. With reverse-proxy
  authentication switching is off, and the next request is signed in as whoever the proxy says.

## Kanboard versions

Tested against Kanboard 1.2.47 and 1.2.57 (the full PHPUnit suite on both). Differences that show:

- **Markdown:** 1.2.57's own renderer runs in safe mode; 1.2.47's doesn't. Steward's renderer runs in safe
  mode on both, so the difference only shows in the fallback case under Known limitations.
- **Sessions:** 1.2.57 drops a session opened before the user's password changed. Steward writes the switched
  session the way core's sign-in does on each version and asks core's own session check, so on 1.2.57 a
  password change for the target user ends the switch like a role change does.
- **Project page settings:** Steward keeps them in its own table on both versions and checks them again
  whenever they are read.
- **Board moves:** 1.2.57 reads a JSON request body only when it is sent as JSON. The `switch.action` row reads
  the task id the same way core does, so it records the task core moves.
- **Reverse proxy:** 1.2.57 uses reverse-proxy authentication only with trusted proxy networks set. Steward
  turns switching off whenever `REVERSE_PROXY_AUTH` is on, on both versions.
- The switch action table covers every core controller action of both versions.

## Development

Buildless: plain PHP, CSS and vanilla JS. From the harness root:

```sh
./testing/run-plugin-tests.sh Steward                               # PHPUnit, against Kanboard 1.2.47
KB_SRC=<1.2.57 checkout> ./testing/run-plugin-tests.sh Steward      # PHPUnit, against Kanboard 1.2.57
node --test 'Steward/Test/js/*.test.mjs'     # JS
```

## License

MIT, © Carmelo Santana.
