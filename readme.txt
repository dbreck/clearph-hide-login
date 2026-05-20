=== Clear pH Hide Login ===
Contributors: clearphdesign
Tags: security, login, hide login, brute force, wp-login
Requires at least: 5.5
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later

Hide the WordPress login URL behind a custom slug. 404 every hit to wp-login.php and wp-admin while logged out. Manage from wp-admin — no wp-config edits required.

== Description ==

Clear pH Hide Login is an in-house replacement for WPS Hide Login built for Clear pH Design sites on Flywheel (where `wp-config.php` rewrites are not allowed).

**What it does**

* Hides the WordPress login form behind a slug you choose (e.g. `/cph-portal-7g3xq`).
* Serves a real `404` (no redirect, no signal) on:
  * `wp-login.php`
  * `wp-register.php`, `wp-signup.php`, `wp-activate.php`
  * `/wp-admin/` for logged-out users
  * `/login`, `/admin`, `/dashboard` shortcut redirects
* Rewrites every internally generated login link — password-reset emails, logout flows, "you must log in" prompts — to use your custom slug.
* Optional: kill the lost-password flow entirely. Stops password-reset spam at the source.
* Optional: log every blocked attempt (IP, UA, request URI, reason).
* Multisite: network-wide default with per-site override.
* Compatible with the `cph-hidden-login` mu-plugin (same option key + slug-resolution chain). If both are installed the mu-plugin wins.

**What it does not do**

* No wp-config rewrites. Pure PHP hooks.
* No bundled translations / i18n.
* No advertising or upsell notices.

**Slug resolution order**

1. `CPH_HIDE_LOGIN_SLUG` constant
2. `cph_hide_login_slug` filter
3. Site option `cph_hide_login_slug`
4. Network option `cph_hide_login_slug` (multisite default)
5. Empty → plugin no-ops, default `wp-login.php` works normally

== Installation ==

1. Drop the `cph-hide-login` folder into `wp-content/plugins/`.
2. Activate from the Plugins screen.
3. Go to **Settings → Clear pH Hide Login** and set a slug.
4. Bookmark the new login URL.

For multisite: activate network-wide, then go to **Network Admin → Settings → Clear pH Hide Login** to set the default slug.

== Emergency disable ==

If you lock yourself out:

* SFTP/SSH: rename the plugin folder to disable it.
* WP-CLI: `wp option delete cph_hide_login_slug && wp cache flush`
* Database: `DELETE FROM wp_options WHERE option_name = 'cph_hide_login_slug';`

== Changelog ==

= 1.0.0 =
* Initial release.
