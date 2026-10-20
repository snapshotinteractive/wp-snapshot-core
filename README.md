# Snapshot Core

Built-in security, SEO, the Snapshot System panel, the activity log and the Snapshot admin
for WordPress sites built from the Snapshot starter theme (Sage 11, Acorn 6). One plugin,
released here, updated from the WordPress **Plugins** screen like any other plugin.

- **Security**: firewall, sign in protection and lockouts, two factor sign in (passkeys,
  TOTP, recovery and emailed codes, off by default), audit log with email alerts, threat
  feeds, optional Cloudflare, file integrity scanning and vulnerability checks.
- **SEO**: title and description templates, robots and canonicals, Open Graph and X cards,
  a JSON-LD graph, sitemaps, redirects with a 404 monitor, robots.txt AI crawler rules,
  llms.txt, IndexNow, an editor panel, a bulk editor and a health audit.
- **Snapshot System**: server, WordPress, database, Acorn and Blade checks, one click
  maintenance tools, sign in screen and admin settings, and an activity log of every change.

What changed in each version: [CHANGELOG.md](CHANGELOG.md).

## Requirements

| | |
|---|---|
| WordPress | 6.9 or newer |
| PHP | 8.3 or newer, with `openssl` and `sodium` |
| Theme | One built from the Snapshot starter. The theme provides Acorn and calls `Snapshot\Core::load()` and `::boot()`; with any other theme the plugin stays idle and says so. |

## Install

1. Download `snapshot-core.zip` from the [latest release](https://github.com/snapshotinteractive/wp-snapshot-core/releases/latest).
   Use the release zip, not GitHub's "Download ZIP" of the source: only the release zip has
   the built assets and bundled libraries.
2. **Plugins → Add New → Upload Plugin**, choose the zip, then **Activate**.
   Or `wp plugin install snapshot-core.zip --activate`.

The theme shows a notice in wp-admin while the plugin is missing or inactive. The site keeps
working without it; security, SEO and the Snapshot panel are simply off.

## Updates

New versions appear under **Dashboard → Updates** and on the **Plugins** screen with the
usual **Update now** button, and work with WordPress's own **Enable auto-updates** link. Each
installed copy checks once every twelve hours (**Check again** on the Updates screen checks
at once) and only ever offers versions with the same major number it runs:

| Release | What a site sees |
|---|---|
| Patch `2.0.1` or minor `2.1.0` | **Update now**. Nothing else to do. |
| Major `3.0.0` | A note on the Plugins screen linking to the upgrade notes. A major needs a matching change in the theme, so it is installed by hand from the release zip once the theme is ready. 2.x keeps getting security fixes as 2.x releases. |
| Pre-release `2.1.0-beta.1` | Nothing. Pre-releases are for testing and are never offered. |

To turn the update check off, for example on a site whose updates go through its own
deploy, add `define('SNAPSHOT_CORE_UPDATES', false);` to `wp-config.php`.

The version a site runs is on **Snapshot → Status** and in `wp snap system status`.

## Turning features off

Everything the plugin does can be switched off without touching code, one module at a time.

**Security** (**Security → Control panel**, or WP-CLI):

```bash
wp snap security modules                 # list modules and whether each is running
wp snap security disable firewall        # firewall, login, twofactor, passwords, hardening,
                                         # headers, feeds, alerts, status
wp snap security disable all             # the master switch: nothing security related runs
wp snap security enable all
```

**SEO** (**SEO → Control panel**, or WP-CLI). SEO modules also pause on their own while
Yoast, Rank Math or another overlapping SEO plugin is active.

```bash
wp snap seo modules
wp snap seo disable redirects            # head, social, schema, sitemaps, redirects, monitor,
                                         # robots, llms, cleanup, indexnow, editor
```

**Whole layers**, in the theme's `snapshot.php`: `'features' => ['seo' => false]` does not
load the SEO layer at all, and `'admin_menu' => false` keeps the standard WordPress menu.

**Locking a setting** per environment, in `wp-config.php`: any setting can be fixed with a
constant, `SNAPSHOT_SECURITY_<KEY>`, `SNAPSHOT_SEO_<KEY>` or `SNAPSHOT_SYSTEM_<KEY>`, for
example `define('SNAPSHOT_SECURITY_MODULE_TWOFACTOR', false);`. The admin then shows it as
locked.

**Locked out by two factor sign in** with email broken:
`define('SNAPSHOT_SECURITY_2FA_DISABLED', true);` in `wp-config.php` turns the second step off
for everyone until it is removed.

**The whole plugin**: **Plugins → Deactivate**, or `wp plugin deactivate snapshot-core`. If
wp-admin is unreachable, rename `wp-content/plugins/snapshot-core` over SFTP and WordPress
deactivates it. Deactivating keeps every setting, log and redirect in the database
(`snapshot_*` options and the `{prefix}snapshot_sec_*` and `{prefix}snapshot_seo_*` tables),
so reactivating picks up where it left off. While it is off, the site falls back to plain
WordPress: no firewall or lockouts, WordPress's own titles and sitemaps, and no redirects
from the redirect manager. `wp snap seo uninstall` removes the redirect and 404 tables and
the SEO settings for good (each entry's SEO fields stay in post meta); run it before
deactivating if the site will not use Snapshot SEO again.

## Licence, warranty and support

Snapshot Core is free software under the [GNU General Public License, version 2 or later](LICENSE).
Anyone running it may use, study, change and share it, and every installed copy keeps
receiving updates from this repository at no cost, with or without a relationship with
Snapshot Interactive.

It comes **without any warranty**, as the licence says. Snapshot Interactive keeps releasing
updates, security fixes included, but is not liable for anything that breaks or any
vulnerability on a site, and does not fix individual sites, unless that site is covered by a
current Snapshot retainer. Sites without one apply updates and handle issues themselves or
with their own developers; everything above works without us.

Retainers and support: [snapshot.agency](https://snapshot.agency/).

## For developers

| Path | What it holds |
|---|---|
| `snapshot-core.php` | Plugin header, autoloaders, `Snapshot\Plugin::register()` |
| `src/Core.php` | Loads the layers, reads the theme's `snapshot.php`, registers the `snapshot::` views, serves the built assets and the theme's design tokens |
| `src/Updater.php` | The update check against `updates/<major>.json` in this repository |
| `src/Security`, `src/Seo`, `src/System` | The three layers |
| `includes/*-defaults.php` | Data: settings schemas, modules, help text, presets. Every list is filterable |
| `resources/views` | Blade views, rendered as `snapshot::security.*`, `snapshot::seo.*`, `snapshot::system.*` |
| `resources/css`, `resources/js` | Admin, sign in and editor assets, built to `build/` with Vite |
| `resources/css/tokens.css` | Default design tokens for the build; at runtime the theme's own `tokens.css` overrides them |
| `bin/build` | Builds in place, or `bin/build 2.1.0` writes `dist/snapshot-core.zip` |

Bundled libraries (bacon-qr-code, zxcvbn-php, WebAuthn, otphp and their dependencies) live
in `vendor-prefixed/` under the `Snapshot\Vendor\` namespace, so they never clash with another
plugin's copy. Acorn and the Laravel components come from the theme and are not bundled.

```bash
bin/build            # composer install, prefix the libraries, build the assets
npm run watch        # rebuild the assets on change
```

The source is developed in the Snapshot starter repository and copied here on each release,
so pull requests are not merged in this repository; open an issue instead.
