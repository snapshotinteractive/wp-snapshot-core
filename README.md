# Snapshot Core

Snapshot Core gives built-in security, SEO, the Snapshot System panel, the activity log and the
Snapshot admin to WordPress sites. These sites use the Snapshot starter theme (Sage 11, Acorn 6).
It is one plugin. We release it here. You update it from the WordPress **Plugins** screen, the
same as other plugins.

- **Security**: a firewall, sign in protection and lockouts, and two factor sign in (passkeys,
  TOTP, recovery codes and emailed codes, off by default). It also has an audit log with email
  alerts, threat feeds, optional Cloudflare, file integrity scans and vulnerability checks.
- **SEO**: title and description templates, robots and canonicals, Open Graph and X cards,
  a JSON-LD graph, sitemaps, redirects with a 404 monitor, robots.txt AI crawler rules,
  llms.txt, IndexNow, an editor panel, a bulk editor and a health audit.
- **Snapshot System**: server, WordPress, database, Acorn and Blade checks, one click
  maintenance tools, sign in screen and admin settings, and an activity log of each change.

To see the changes in each version, read [CHANGELOG.md](CHANGELOG.md).

## Requirements

| | |
|---|---|
| WordPress | 6.9 or newer |
| PHP | 8.3 or newer, with `openssl` and `sodium` |
| Theme | A theme built from the Snapshot starter. The theme gives Acorn and calls `Snapshot\Core::load()` and `::boot()`. With a different theme, the plugin does nothing and shows a message about it. |

## Install

1. Download `snapshot-core.zip` from the [latest release](https://github.com/snapshotinteractive/wp-snapshot-core/releases/latest).
   Use the release zip. Do not use the GitHub "Download ZIP" of the source. Only the release
   zip has the built assets and the bundled libraries.
2. Go to **Plugins → Add New → Upload Plugin**, select the zip, then select **Activate**.
   Alternatively, run `wp plugin install snapshot-core.zip --activate`.

While the plugin is missing or not active, the theme shows a notice in wp-admin. The site
continues to work without the plugin, but security, SEO and the Snapshot panel are off.

## Updates

New versions show under **Dashboard → Updates** and on the **Plugins** screen, with the
usual **Update now** button. They also work with the WordPress **Enable auto-updates** link.
Each installed copy checks for updates one time every twelve hours. **Check again** on the
Updates screen checks immediately. A copy offers only versions with the same major number as
the version it runs:

| Release | What a site sees |
|---|---|
| Patch `2.0.1` or minor `2.1.0` | **Update now**. You do not have to do other work. |
| Major `3.0.0` | A note on the Plugins screen, with a link to the upgrade notes. A major version needs a related change in the theme. Thus, you install it manually from the release zip when the theme is ready. 2.x continues to get security fixes as 2.x releases. |
| Pre-release `2.1.0-beta.1` | Nothing. Pre-releases are for tests. The plugin never offers them. |

To stop the update check, add `define('SNAPSHOT_CORE_UPDATES', false);` to `wp-config.php`.
An example is a site that gets its updates through its own deploy.

The version that a site runs shows on **Snapshot → Status** and in `wp snap system status`.

## Turning features off

You can switch off each thing that the plugin does, one module at a time, without a change
to the code.

**Security** (**Security → Control panel**, or WP-CLI):

```bash
wp snap security modules                 # list modules and whether each is running
wp snap security disable firewall        # firewall, login, twofactor, passwords, hardening,
                                         # headers, feeds, alerts, status
wp snap security disable all             # the master switch: nothing security related runs
wp snap security enable all
```

**SEO** (**SEO → Control panel**, or WP-CLI). SEO modules also pause automatically while
Yoast, Rank Math or a different SEO plugin with the same functions is active.

```bash
wp snap seo modules
wp snap seo disable redirects            # head, social, schema, sitemaps, redirects, monitor,
                                         # robots, llms, cleanup, indexnow, editor
```

**Whole layers**, in the `snapshot.php` file of the theme:

- `'features' => ['seo' => false]` does not load the SEO layer.
- `'admin_menu' => false` keeps the standard WordPress menu.

**Lock a setting** for each environment, in `wp-config.php`. You can set each setting to a
fixed value with a constant: `SNAPSHOT_SECURITY_<KEY>`, `SNAPSHOT_SEO_<KEY>` or
`SNAPSHOT_SYSTEM_<KEY>`. An example is `define('SNAPSHOT_SECURITY_MODULE_TWOFACTOR', false);`.
The admin then shows the setting as locked.

**If two factor sign in locks you out and email does not work**: put
`define('SNAPSHOT_SECURITY_2FA_DISABLED', true);` in `wp-config.php`. This turns off the second
step for all users until you remove it.

**The whole plugin**: go to **Plugins → Deactivate**, or run `wp plugin deactivate snapshot-core`.
If you cannot get access to wp-admin, rename `wp-content/plugins/snapshot-core` through SFTP.
WordPress then deactivates the plugin.

When you deactivate the plugin, the database keeps all settings, logs and redirects
(`snapshot_*` options and the `{prefix}snapshot_sec_*` and `{prefix}snapshot_seo_*` tables).
When you activate it again, it continues from the same point. While the plugin is off, the site
is plain WordPress:

- There is no firewall and there are no lockouts.
- WordPress makes its own titles and sitemaps.
- There are no redirects from the redirect manager.

`wp snap seo uninstall` permanently removes the redirect and 404 tables and the SEO settings.
The SEO fields of each entry stay in post meta. If the site will not use Snapshot SEO again,
run this command before you deactivate the plugin.

## Licence, warranty and support

Snapshot Core is free software under the [GNU General Public License, version 2 or later](LICENSE).
All persons who run it can use, study, change and share it. Each installed copy continues to
get updates from this repository at no cost. This is true with or without a relationship with
Snapshot Interactive.

It comes **without any warranty**, as the licence says. Snapshot Interactive continues to
release updates, which include security fixes. But Snapshot Interactive is not liable for
damage or vulnerabilities on a site, and does not repair individual sites. The exception is a
site that has a current Snapshot retainer. Sites without a retainer apply updates and solve
problems themselves or with their own developers. All of the information above works without us.

For retainers and support, go to [snapshot.agency](https://snapshot.agency/).

## For developers

| Path | What it holds |
|---|---|
| `snapshot-core.php` | Plugin header, autoloaders, `Snapshot\Plugin::register()` |
| `src/Core.php` | Loads the layers, reads the `snapshot.php` of the theme, registers the `snapshot::` views, serves the built assets and the design tokens of the theme |
| `src/Updater.php` | The update check against `updates/<major>.json` in this repository |
| `src/Security`, `src/Seo`, `src/System` | The three layers |
| `includes/*-defaults.php` | Data: settings schemas, modules, help text, presets. Each list is filterable |
| `resources/views` | Blade views, rendered as `snapshot::security.*`, `snapshot::seo.*`, `snapshot::system.*` |
| `resources/css`, `resources/js` | Admin, sign in and editor assets, built to `build/` with Vite |
| `resources/css/tokens.css` | Default design tokens for the build. At runtime, the `tokens.css` of the theme overrides them |
| `bin/build` | Builds in place. `bin/build 2.1.0` writes `dist/snapshot-core.zip` |

The bundled libraries (bacon-qr-code, zxcvbn-php, WebAuthn, otphp and their dependencies) are
in `vendor-prefixed/` under the `Snapshot\Vendor\` namespace. Thus, they never conflict with a
copy of the same library in a different plugin. Acorn and the Laravel components come from the
theme. The plugin does not bundle them.

```bash
bin/build            # composer install, prefix the libraries, build the assets
npm run watch        # rebuild the assets on change
```

We develop the source in the Snapshot starter repository and copy it here for each release.
Thus, we do not merge pull requests in this repository. Open an issue instead.
