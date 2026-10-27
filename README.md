# ILL System — WordPress Plugin

**Version:** 3.14.0  
**Author:** SENYLRC Systems Department  
**Source:** https://github.com/SENYLRC/SEAL-4 (successor to [SEAL-3](https://github.com/SENYLRC/SEAL-3))  

---

## Overview

This plugin converts the standalone SEAL (Southeastern Access to Libraries) PHP codebase into a **secure, fully integrated WordPress plugin**. Every page is now served through WordPress's routing system, theme, authentication, and escaping APIs.

---

## Installation

1. Upload the `seal-ill-system/` folder to `/wp-content/plugins/`.
2. Activate the plugin in **Plugins → Installed Plugins**.
3. Go to the **ILL → Settings** menu (labeled with your configured System Name) and configure your database connection.
4. Visit **Settings → Permalinks** and click **Save** to flush rewrite rules.
5. The SEAL routes are now live (see `/seal-ill/pages-routes` in the admin).

---

## Configuration

All settings live in the **ILL → Settings** menu. Nothing is stored in flat `.inc` files.

| Setting | Description | Default |
|---------|-------------|---------|
| DB Host | MySQL host | `localhost` |
| DB User | Database username | — |
| DB Password | Stored encrypted (libsodium secretbox, or AES-256-GCM, keyed from the WordPress secret keys) | — |
| DB Name | Database name | — |
| Stats Table | ILL stats table | `STATS` |
| Library Table | Library profile table | `Library-Data` |
| ILLiad Mapping | ILLiad address mapping table | `ILLIAD-ADD-MAPPING` |
| Z39.50 Gateway URL | Base URL for catalog search service | `https://catalog.library.org/service-proxy/` |
| Admin Email | Alert address for DB/API failures | — |
| From Address | Outbound email `From:` | `donotreply@library.org` |
| From Name | Outbound email `From:` name | *(System Short Name)* |

---

## User Roles

The plugin creates three custom WordPress roles on activation:

| Role | Display Name | Description |
|------|-------------|-------------|
| `libstaff` | Library Staff | Submit/manage own ILL requests, respond as lender |
| `libsys` | Library System Staff | All libstaff capabilities + system-wide views + edit libraries in own system |
| `illadmin` | ILL Administrator | Every ILL Admin page and user-account management, without WordPress site administration |

Administrators inherit all capabilities automatically. The user who activates the plugin is also given the ILL
Administrator role (as an extra role; they stay an Administrator).

---

## URL Routes

The plugin registers these virtual WordPress URLs:

| URL | Access | Description |
|-----|--------|-------------|
| `/request` | libstaff+ | Submit an ILL request |
| `/respond` | libstaff+ | Lender fill/no-fill response |
| `/status` | libstaff+ | Mark item received/returned |
| `/status-confirmation` | libstaff+ | Status action confirmation |
| `/renew` | libstaff+ | Renewal requests |
| `/requesthistory` | libstaff+ | Own request history |
| `/lender-history` | libstaff+ | Lender history |
| `/illdir` | libstaff+ | ILL directory |
| `/illuser` | libstaff+ | ILL user lookup |
| `/libprofile` | libstaff+ | Library profile |
| `/libstats` | libstaff+ | Borrower stats |
| `/liblenderstat` | libstaff+ | Lender stats |
| `/libstaffaccounts` | libstaff+ | Staff account list |
| `/bulkaction` | libstaff+ | Bulk status actions |
| `/allrequests-ls` | libsys+ | All requests (system scope) |
| `/adminlib-ls` | libsys+ | Library editor (system scope) |
| `/stats-ls` | libsys+ | Stats (system scope) |
| `/allrequests` | admin | All system requests |
| `/adminlib` | admin | Library directory admin |
| `/adminlib-accounts` | admin | User account admin |
| `/stats` | admin | System-wide statistics |
| `/systemstats` | admin | System statistics |
| `/export` | admin | CSV export |
| `/email-harvest` | admin | Staff email export |

---

## Security Improvements Over Original

| Area | Original | This Plugin |
|------|----------|-------------|
| **Authentication** | Custom `die()` checks; required `wp-load.php` manually | WP `current_user_can()` + `SEAL_Roles::require_cap()` |
| **CSRF** | No CSRF protection | `wp_nonce_field()` on every form, verified on POST |
| **DB Credentials** | Flat `/var/www/seal_wp_script/seal_db.inc` file on disk | Stored encrypted in `wp_options` |
| **DB Queries** | `mysqli_real_escape_string()` concatenation | `SEAL_DB::query()` with parameterised `%s/%d/%t` |
| **HTTP Calls** | `shell_exec("curl ...")` | `wp_remote_get()` with SSL verify enabled |
| **Output** | Mixed `echo` with unescaped vars | All output via `esc_html()` / `esc_attr()` / `wp_kses_post()` |
| **ILLiad API** | `curl_exec()` with hardcoded `define()` inside script | `wp_remote_post()` with key from `wp_options` |
| **Email** | `mail()` with manual header injection | `wp_mail()` with WordPress's sanitized headers |
| **Routing** | Direct PHP file access (bypasses WP auth) | WordPress rewrite rules → `template_redirect` |
| **Hardcoded paths** | `/var/www/wpSEAL/wp-load.php`, `/var/www/seal_wp_script/` | All via `SEAL_ILL_PLUGIN_DIR` constant |
| **Error disclosure** | DB errors echoed to page | Errors logged; generic message shown to user |

---

## File Structure

```
seal-ill-system/
├── seal-ill-system.php          # Plugin bootstrap & hooks
├── admin/
│   └── class-seal-admin.php     # WP admin menu, settings pages
├── assets/
│   └── seal-style.css           # Frontend CSS
└── includes/
    ├── class-seal-db.php         # DB connection singleton + parameterised query helper
    ├── class-seal-roles.php      # Role creation & capability checks
    ├── class-seal-functions.php  # Shared utility functions
    ├── class-seal-router.php     # URL routing via WP rewrite rules
    ├── class-seal-shortcodes.php # [seal_ill] shortcode support
    └── pages/
        ├── class-seal-page-base.php           # Abstract base page
        ├── class-seal-page-request.php        # ILL request form (full implementation)
        ├── class-seal-page-respond.php        # Lender response (full implementation)
        ├── class-seal-page-status.php         # Status actions
        ├── class-seal-page-status-confirmation.php
        ├── class-seal-page-renew.php          # Renewals
        ├── class-seal-page-requesthistory.php # Borrower history
        ├── class-seal-page-allrequests.php    # Admin: all requests
        ├── class-seal-page-allrequests-ls.php # LibSys: system requests
        ├── class-seal-page-bulkaction.php     # Bulk actions
        ├── class-seal-page-lenderhistory.php  # Lender history
        ├── class-seal-page-adminlib.php       # Admin: library directory
        ├── class-seal-page-adminlib-ls.php    # LibSys: library editor
        ├── class-seal-page-adminaccounts.php  # Admin: user accounts
        ├── class-seal-page-libprofile.php     # Library profile
        ├── class-seal-page-libstaffaccounts.php
        ├── class-seal-page-libstats.php
        ├── class-seal-page-liblenderstat.php
        ├── class-seal-page-illdir.php
        ├── class-seal-page-illuser.php
        ├── class-seal-page-sealstats.php
        ├── class-seal-page-sealstats-ls.php
        ├── class-seal-page-systemstats.php
        ├── class-seal-page-export.php
        └── class-seal-page-email-harvest.php
```

---

## Built-in modules (replace the old separate plugins)

What used to be separate plugins in SEAL-3's `Word_Press_Plugins/` folder is now built in, under **ILL → Modules**
(user fields, login redirect, terms of service, catalog search, user export, dashboard, user approval, last login)
and **ILL → Security** (login screen, login URL, login protection, password rules, two-factor, file monitor).
**Deactivate and delete the old plugins** (`senylrc-custom-user-fields`, `senylrc-login-redirect`, `senylrc-tos`,
`senylrc-seal-search`, `ill_mkws`, `senylrc-export-users`, `senylrc_dashboard_tweak`) so the same hooks don't run twice.

## Scheduled jobs (cron)

The cron scripts are separate from the plugin: see `cron/scripts/README.md`. Copy them to the server's scripts folder
(`/var/www/<code>_script`), not into the plugin folder, and set up the crontab from **ILL → Cron Jobs**. Your
organization's addresses and links go in `cron_local.php` (copy `cron_local.php.example`); database logins in `ill_db.inc`.
Neither file belongs in version control.

## Deploying changes

`deploy/rsync-to-node.sh` copies changed files from this folder to a server: the plugin into one folder and the cron
scripts into another (set both, and the SSH login, in `deploy/deploy.conf`; copy `deploy/deploy.conf.example`). It does
a **dry run** unless given `--go`, copies new and changed files only, **never deletes** on the server, leaves out notes,
docs and tests, and sets folders 755 / files 644.
Bump `SEAL_ILL_VERSION` in `seal-ill-system.php` whenever CSS or JavaScript changes; it is the `?ver=` cache-buster.

---

## License

Apache License 2.0 (see `LICENSE`) — Copyright © Southeastern New York Library Resources Council.
Bundled third-party code keeps its own license; see `NOTICE`.


## Homepage Search Block

The raw PHP/HTML catalog search block from the original home page is now built
by the plugin. Delete that block and put this shortcode in its place:

```
[ill_search]
```

Everything in it comes from **ILL → Search & Catalog**: the search results
page, Advanced Search and Help links, the categories.js URL, and the category
dropdown (one `value|Label` per line; start a line with `*` to pre-select it).
The `aria-label` on the search box uses the configured System Name
("Search {System Name} Union Catalog").


## Security suite (v3.3.0)

**ILL → Security** adds: branded login screen, custom login URL, login lockout + 404 detection + IP lists, password rules (pwned/strong/force-reset), two-factor authentication, and file change detection. Everything is off until enabled on its tab.

**Two-factor** (since 3.5.0): each person picks their own methods on My Profile, in any mix: **security keys** (YubiKey and other FIDO2 keys,
touch only), **passkeys** (Windows Hello, Touch ID, Android), or **authenticator-app** codes, plus 10 one-time backup codes. Keys are
checked by `includes/class-seal-webauthn.php` with the bundled library `includes/lib/WebAuthn/` (lbuchs/webauthn v2.2.0, MIT; needs
PHP's OpenSSL extension) and are tied to the site's host name: keys registered on a test site don't work on the live one.

Emergency off switches for `wp-config.php`:

```php
define( 'ILL_DISABLE_LOGIN_URL', true );  // custom login URL off (old name SEAL_DISABLE_LOGIN_URL still works)
define( 'ILL_DISABLE_SECURITY',  true );  // all security modules off (old name SEAL_DISABLE_SECURITY still works)
```

Third-party: `assets/js/qrcode.js` is qrcode-generator 1.4.4 (MIT, Kazuhiko Arase).
