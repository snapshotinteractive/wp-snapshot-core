# Changelog

Every release of Snapshot Core. The release workflow publishes the section whose heading
matches the version, so write the notes here before releasing. Versions follow semver: a
patch fixes, a minor adds without breaking, a major needs a change in the site theme (a
renamed filter, a moved file, a new required function). Sites are offered patches and
minors with **Update now**; a major is installed by hand after the theme change.

## [Unreleased]

## [2.1.0] - 2026-11-12

- Snapshot OS: new `Snapshot\Remote` class (`Remote::API` 1), a stable in-process API for
  the tethered plugin. It gives a status document with a module hash, module switches,
  redirects, the 404 monitor, IndexNow submits, diagnostics and the per entry SEO meta keys.
  Snapshot Core still makes no request to Snapshot OS. See `.claude/docs/snapshot-os.md`.
- Modules: `wp snap security enable|disable`, `wp snap seo enable|disable` and
  `Remote::setModules()` share one code path (`Snapshot\ModuleSwitch`). It changes only the
  modules it is given. A switch to the value it already has is no longer logged. The SEO CLI
  line now records `wp-cli` as the person, as the security CLI does. The severities did
  change: see the critical list and the SEO master switch below.
- Two factor: new setting **Let Snapshot OS staff sign in without a second code**
  (`twofactor_trust_remote`, off by default). With it on, `Remote::trustSignIn()` lets the
  next session of that user in this request carry the two factor tag. New event
  `twofactor.remote_trusted` (warning). `TwoFactor::enforceSession` changes: see the last
  round below.
- Status route: its builders are shared with `Remote::status()`
  (`StatusEndpoint::twoFactor()`, `scan()`, `vulnerabilities()`). The route returns the same
  payload.
- Diagnostics: each check has a stable `key`. New `Diagnostics::structured()`.
- Redirects: new `Redirects::find()`. The origin `snapshot-os` shows as Snapshot OS.
- Check: `bin/remote-check.php`, run in CI.
- Snapshot OS, review round: the status document gains `booted` per layer (and, from the
  last round, per module), `changed` per module (the last change: source,
  actor, time, on), and `details` (`csp`, `hsts`) on the security headers module. The hash
  now also covers `two_factor.active` and `two_factor.trust_remote`.
- New `Remote::postMeta()` (each SEO field with its `own`, `legacy` or `default` source,
  resolved as Snapshot Core renders it, through the new `Meta::resolvePost()`),
  `Remote::record()` (companions log `remote.*` events; new types `remote.seo_meta_updated`,
  `remote.robots_served`, `remote.llms_served`), `Remote::overrides()` and the filter
  `snap/remote/overrides`, printed as notices on the Crawlers and AI settings, Search
  appearance, Security headers settings and the entry SEO panel.
- `Remote::addRedirect()` answers `updated` and `previous`. It keeps a regex source and the
  target exactly as given, validates the target (a path, or an http(s) address without user
  info or control characters) and refuses a source over 191 characters (`source_too_long`)
  instead of cutting it. New `Redirects::existing()` and `Redirects::sourceKey()`.
- `Remote::notFound()` takes `$suggest` (default true) and looks up suggestions only when
  asked.
- `Remote::trustSignIn()` refuses while the security tables are not ready. The trust is
  cleared at `shutdown` too. A trusted session carries the new tag `snapshot_2fa_remote`, and
  such a session cannot create MCP tokens or grant OAuth consent while two factor is on.
- Turning `twofactor_trust_remote` on logs `twofactor.remote_trust_on` (critical); off logs
  `twofactor.remote_trust_off`.
- Modules: turning off notifications or the status endpoint is now critical, as is the
  master, two factor, the firewall and login. Any security module that Snapshot OS turns off
  is emailed to the alert recipients, even while notifications are off (same sender and
  throttle, new `Alerts::alert()`). From the last round, one summary email per change, not
  one per module. The last change of each module is stored in the new
  option `snapshot_module_changes` by Snapshot OS, WP-CLI and both wp-admin control panels,
  and the panels show it under each switch. `seo.module_changed` is now a registered event
  type even when the SEO feature is off.
- WP-CLI: `wp snap security|seo enable|disable` now fails on every refused change (not
  loaded, invalid value, locked, unknown), never reports success.
- New `Shield::booted()`, `Engine::booted()` and `EventLog::last()`.
- Snapshot OS, final round: each layer of the status document gains `changed` (the last
  change of the master switch, or null) and `critical` (the keys whose switch-off is
  critical: security `ModuleSwitch::CRITICAL`, SEO the master only, new
  `ModuleSwitch::CRITICAL_SEO` and `ModuleSwitch::criticalKeys()`). `two_factor` gains
  `emergency_off` (`SNAPSHOT_SECURITY_2FA_DISABLED` is set). The hash also covers the layer
  `changed` records.
- Snapshot OS, last round (security review):
  - Two factor: a session that Snapshot OS signed in (`snapshot_2fa_remote`) cannot change
    two factor on the profile screen: add or remove an authenticator app or a passkey,
    make new recovery codes, or reset another account. It is refused with "Sign in on this
    site with your own second factor to change it" and logs the new event
    `twofactor.remote_refused` (warning). New `TwoFactor::remoteSession()` and
    `Profile::remoteMessage()`.
  - `TwoFactor::enforceSession` changes: while `twofactor_trust_remote` is off, a session
    that only Snapshot OS marked is not verified. It is destroyed and signed out like a
    session with no mark. Nothing else in the check changes.
  - The Security and SEO control panels send the switch values as the page loaded them
    (`snapshot_security_loaded[...]`, `snapshot_seo_loaded[...]`) and apply only the
    switches the person changed, through `ModuleSwitch` (source `wp-admin`, the person as
    actor, their own log line per module). A switch that changed somewhere else after the
    page loaded is left as it is, and a warning names it. New `ModuleSwitch::fromForm()`,
    `ModuleSwitch::saved()` and `ModuleSwitch::labels()`.
  - Each module in the status document gains `booted`: its hooks registered on this
    request. `on` is true only when `booted` is true. Hardening, headers, passwords and two
    factor boot before the security tables are checked; every other security module needs
    the tables. Redirects and the 404 monitor need the SEO tables. Two factor reports
    `booted` false while `SNAPSHOT_SECURITY_2FA_DISABLED` is set. New
    `Shield::moduleBooted()`, `Shield::EARLY`, `Engine::moduleBooted()` and `Engine::TABLES`.
  - `ModuleSwitch::critical()` reads `criticalKeys()`, so the SEO master switch off is now
    critical and emailed. `audit` is no longer in `ModuleSwitch::CRITICAL` (no module has
    that key). Each `apply()` call sends one summary alert, not one per module. New
    `Alerts::hold()` and `Alerts::booted()`.
  - Every `security.module_changed`, `seo.module_changed`, `*.settings_changed` and
    `twofactor.remote_trust_on|off` event has `source` (`snapshot-os`, `wp-admin` or
    `wp-cli`) in its context. A wp-admin module change now logs the person who made it.
  - `Remote::addRedirect()`: a source that is not a regex is decoded with `rawurldecode`
    and normalized with `Redirects::normalize()`, so `%20` and other octets are kept. An
    encoded control character is refused (`invalid_source`).
  - `Remote::postMeta()` adds `robots_effective` (`index`, `follow`) from Snapshot Core's
    resolver, with the content type defaults applied.
- Docs: `README.md` is rewritten in ASD-STE100 Simplified Technical English. No code changes.

## [2.0.0] - 2026-10-20

Snapshot Core is now a WordPress plugin, released from
[snapshotinteractive/wp-snapshot-core](https://github.com/snapshotinteractive/wp-snapshot-core)
and updated from the Plugins screen. This is a major release: the theme changes below are
required.

- Plugin: `snapshot-core.php` with a plugin header and `Update URI`, so every installed
  copy gets **Update now** and auto-updates from the public GitHub releases. Each copy only
  offers versions within its own major (`updates/<major>.json`) and links to the upgrade
  notes when a new major is out. `SNAPSHOT_CORE_UPDATES` false turns the check off.
- Licence: GPL-2.0-or-later, without warranty. Updates are free for every site.
- Bundled libraries (bacon-qr-code, zxcvbn-php, WebAuthn, otphp and their dependencies)
  are prefixed under `Snapshot\Vendor\`, so they never clash with another plugin's copy.
  Acorn now comes from the theme and is no longer a requirement of the package.
- Assets: the admin, sign in and editor CSS and JS are built with the plugin (`build/`)
  instead of by the theme's Vite. The theme's `tokens.css` still brands them: its `@theme`
  values are printed as CSS variables after the plugin's styles (filter
  `snap/core/tokens_path`). `Core::assets()` and `Core::asset()` replace
  `Vite::withEntryPoints(Core::entries(...))`; `Core::base()`, `entry()` and `entries()`
  are gone, and `Core::path()` and `Core::url()` now resolve inside the plugin.
- The block page (`security/blocked`) loads its stylesheet from the plugin; it pointed at
  an entry the theme never built.
- File integrity: the release zip carries its own `security-manifest.json`, and the scan
  checks the plugin against it (`plugin.modified`, `plugin.unknown`) instead of asking
  WordPress.org. Plugins whose `Update URI` points elsewhere are no longer looked up on
  WordPress.org at all.
- Snapshot → Status shows the plugin version from its header and a **snapshot-core assets**
  check.
- Admin notices when the plugin is active but the theme does not load it, or when the
  plugin was installed from source without its built files.

### Upgrading a site from 1.x

1. Install the 2.0.0 release zip under Plugins and activate it. The 1.x theme keeps
   working on it in the meantime.
2. In the theme's `composer.json`, remove the `snapshotinteractive/snapshot-core`
   requirement and its repository entry, then `composer update snapshotinteractive/snapshot-core`.
3. `functions.php`: wrap `Core::load(...)` and `Core::boot()` in `class_exists(Core::class)`,
   as in the starter, so the site renders if the plugin is ever off.
4. `vite.config.js`: remove the `vite.mjs` import, `...core.entries`, `...core.alias` and
   `preserveSymlinks`.
5. `bin/acf-check.php`, `bin/seed-check.php`, `bin/blade-check.php`: read the package
   through `bin/core-path.php`, as in the starter.
6. Remove any Dependabot or CI configuration that installed the 1.x package.

## [1.2.0] - 2026-10-20

- MCP sign in from the app: OAuth 2.1 with PKCE, discovery (`/.well-known/oauth-protected-resource`,
  `/.well-known/oauth-authorization-server`), dynamic client registration, refresh token
  rotation with reuse detection and revocation. `claude mcp add --transport http <name>
  https://site/mcp` or a claude.ai custom connector opens the site, the administrator signs
  in (two factor included) and approves on a consent screen. Connected apps are listed under
  Snapshot, AI access with Disconnect. `SNAPSHOT_MCP_OAUTH` false keeps tokens only.
- `/mcp` is a short address for the MCP server (pretty permalinks; filter `snap/mcp/alias`).
- MCP works with two factor sign in on: only connections and tokens made in a session that
  passed two factor are accepted, and older ones are revoked when two factor is switched on.
  The two factor session check is unchanged.
- The security status route reports MCP connections and whether OAuth is on.
- A session signed out by the two factor check is sent back to the page it was on after
  signing in again (`redirect_to`), so an MCP consent in progress is not lost.

## [1.1.0] - 2026-10-20

- AI access over MCP: a Model Context Protocol server at `/wp-json/snapshot/v1/mcp` that
  lets an AI assistant read and edit content, ACF fields (page builder included), media,
  terms, menus and Site Options as the administrator who created its token. Off unless
  `SNAPSHOT_MCP_ENABLED` is true in `wp-config.php`; `SNAPSHOT_MCP_ACCESS` sets `read`,
  `edit` (default) or `publish`. New content is always a draft. Personal, hashed, expiring
  tokens under Snapshot, AI access. Tools are WordPress abilities (`snapshot/*`). Every
  edit saves a revision with its fields and an `mcp.tool_called` activity event. Paused
  while two factor sign in is on, which also revokes every token. Token requests never
  hold `unfiltered_html`; everything MCP writes, ACF fields included, is kses filtered.
  The security status route reports `mcp`.

## [1.0.1] - 2026-10-20

- Sitemaps: post and term sitemaps, the sitemap counts and the llms.txt entry list skip
  noindex and sitemap-excluded entries through one ID lookup (`post__not_in`, `exclude`)
  instead of `!=` meta queries. The old queries joined the whole postmeta table three
  times, so pages with page-builder meta never finished. The same entries are skipped.
- `Sitemaps::excludedPostIds()` and `Sitemaps::excludedTermIds()` are new;
  `indexableMetaQuery()` and `notExcludedMetaQuery()` stay for sites that call them, but
  nothing in the package uses them any more.

## [1.0.0] - 2026-10-09

First release, extracted from the Snapshot Sage 11 starter theme.

- Security: firewall, sign in protection and lockouts, two factor sign in (passkeys,
  TOTP, recovery and emailed codes, off by default), threat feeds, optional Cloudflare,
  file integrity scanning and vulnerability checks, each behind its own switch.
- Activity log: always on, records every change people make, filterable, CSV export.
- SEO: title and description templates, robots and canonicals, Open Graph and X cards,
  a JSON-LD graph for locations, staff, services, events and FAQs, curated sitemaps,
  redirects with a 404 monitor, robots.txt AI crawler rules, llms.txt, IndexNow, an editor
  panel, a bulk editor and a health audit.
- Snapshot System: server, WordPress, database, Acorn and Blade diagnostics, rebuild tools
  and the admin experience settings (sign in screen, notices, clean admin, footer).
- Admin: Snapshot colour scheme, admin bar mark and footer credit; optional grouped admin
  menu.
- Site flags: `snapshot.php` in the theme turns the SEO layer and the grouped admin menu
  on or off and sets each site's default settings.
- Integrity scan reports cut short core files as `core.truncated`; `bin/` scripts are CLI
  only and never deployed.
