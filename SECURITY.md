# ILL System — Security Audit Report
**Version:** 3.2.1 (audit); additions through 3.5.1 below  
**Audit Date:** June 2026  
**Auditors:** Zachary Spalding (SENYLRC) & Claude (Anthropic)

---

## Summary

Full code review and remediation pass completed. All issues below were found and fixed in v3.2.1.

---

## Issues Found & Fixed

### CRITICAL — Fixed

#### 1. Weak password encryption (XOR + base64)
**Was:** DB password stored using XOR with `AUTH_KEY` + base64. XOR with a repeating key is trivially reversible.  
**Fixed:** Replaced with **libsodium `sodium_crypto_secretbox`** (XSalsa20-Poly1305 AEAD). Falls back to **OpenSSL AES-256-GCM** if sodium is unavailable. Key derived from `AUTH_KEY + SECURE_AUTH_KEY` via HKDF-SHA256 — the raw WP keys are not used directly. Old XOR-encrypted values are detected by format prefix and decrypted for backward compatibility, with a logged warning to re-save.

#### 2. No MySQL TLS enforcement
**Was:** `new mysqli(...)` with no SSL flags — all SQL traffic between WordPress and MySQL transmitted in plaintext.  
**Fixed:** `SEAL_DB::get()` now uses `mysqli::ssl_set()` + `MYSQLI_CLIENT_SSL` when any SSL option is configured. A new admin setting **"Require Encrypted Connection"** (`seal_db_ssl_enforce`) causes the plugin to abort with a `RuntimeException` if the connection is not encrypted (verified via `SHOW STATUS LIKE 'Ssl_cipher'`). Mutual TLS (client cert + key) is also supported.

#### 3. Lender email address from untrusted POST value
**Was:** The `libdestination[]` POST field contained a colon-delimited string with the lender's email address baked in from the client side. This string was split and `$destemail` used directly to send email — allowing an attacker to inject any email address as the recipient.  
**Fixed:** Only the **LOC code** is extracted from the POST value (after stripping to `[A-Z0-9-]` only). The library name, email, and system code are **re-fetched from the database** on the server side and used exclusively. A spoofed POST cannot redirect ILL notifications.

### HIGH — Fixed

#### 4. Lender `fill` value not validated
**Was:** `$_REQUEST['fill']` accepted any value and was stored directly into the database Fill column.  
**Fixed:** Strict whitelist — only `'0'` or `'1'` are accepted. Any other value triggers an error and aborts. Applied to both the GET pre-fill (email link) and the POST submission.

#### 5. Module toggle stored as empty string when unchecked
**Was:** Unchecked checkbox POSTs nothing. WP settings API called `sanitize_text_field('')` → stored `''`. `seal_config()` defaults returned `'1'` (enabled), so a disabled module would re-enable itself on cache miss.  
**Fixed:** New `san_toggle()` sanitizer explicitly maps `''` → `'0'` and `'1'` → `'1'`. Module checks use strict `=== '1'`.

#### 6. Open redirect in login redirect module
**Was:** The WP-supplied `$redirect_to` from the `login_redirect` filter was returned as-is for non-staff roles, allowing a crafted login URL to redirect to an off-site destination.  
**Fixed:** All `$redirect_to` values for non-staff users are passed through `wp_validate_redirect()`. Fails safe to `admin_url()`. Staff redirect paths come from `seal_config()` server-side only, stripped via `wp_make_link_relative()`.

#### 7. Email header injection
**Was:** From: display name taken directly from `seal_config()` and embedded in the `From:` header without sanitization. A tampered option value containing `\r\n` could inject additional headers.  
**Fixed:** `str_replace(["\r","\n"], '', ...)` applied to display name. `sanitize_email()` applied to From: address. Applied in `SEAL_Functions::send_email()` before header construction.

### MEDIUM — Fixed

#### 8. `$_SERVER['REQUEST_URI']` used without sanitization
**Was:** `SEAL_Functions::self_url()` used `esc_url($_SERVER['REQUEST_URI'])` as a fallback. `REQUEST_URI` can be set by the client and `esc_url()` alone isn't sufficient before output.  
**Fixed:** Added `sanitize_url(wp_unslash($_SERVER['REQUEST_URI']))` before `esc_url()`.

#### 9. TOS checkbox read without sanitization
**Was:** `$_POST['tos_agree']` compared directly without `sanitize_text_field()`.  
**Fixed:** Value sanitized before comparison.

#### 10. `$_GET['action']` on registration page
**Was:** `$_GET['action'] === 'register'` comparison without sanitization.  
**Fixed:** `sanitize_key($_GET['action']) === 'register'`.

---

## Items Reviewed and Confirmed Safe

| Area | Finding |
|------|---------|
| All `SEAL_DB::query()` calls | All use `%s`/`%d`/`%t` parameterised placeholders. No raw string concatenation. |
| `$_POST` in request page | All values pass through `sanitize_text_field(wp_unslash(...))` before use. Nonce verified before any POST processing. |
| `$_POST` in respond page | All values sanitized. Nonce verified. `resfill` strictly validated. |
| Output escaping | All `echo` output uses `esc_html()`, `esc_attr()`, `wp_kses_post()`, or `wp_kses()`. No raw variable output found. |
| File includes | All `require_once` calls use the `SEAL_ILL_PLUGIN_DIR` constant. No user input in file paths. |
| `wp_remote_get/post()` | Used for Z39.50 and ILLiad API. `sslverify => true` on all calls. No `shell_exec`. |
| Admin settings | All options use `register_setting()` with typed `sanitize_callback`. |
| CSRF | `wp_nonce_field()` + `wp_verify_nonce()` on all POST forms. WP admin forms use `settings_fields()`. Export uses `check_admin_referer()`. |
| Capability checks | `SEAL_Roles::require_cap()` called at router level for every route before any code runs. |
| `eval()` in vendor JS | In `mkws-complete.js` (Index Data ApS, LGPL 2015). Third-party vendored library — not our code. |

---

## SQL Traffic Encryption — Setup Guide

### Option A: Encrypt without server verification (simplest)
Leave CA cert blank and enable "Require Encrypted Connection" in admin:
- **Database → Require Encrypted Connection:** ✅ Enabled  
- **CA Certificate Path:** *(leave blank)*

Traffic is encrypted (TLS) but the server certificate is not verified. Suitable for same-datacenter MySQL where MITM is unlikely.

### Option B: Full TLS with server verification (recommended for separate hosts)
1. Obtain or generate a CA certificate for your MySQL server.
2. Place it on the WordPress server (e.g. `/etc/ssl/mysql/ca.pem`).
3. In admin:
   - **Database → CA Certificate Path:** `/etc/ssl/mysql/ca.pem`
   - **Require Encrypted Connection:** ✅ Enabled

### Option C: Mutual TLS (client certificate authentication)
In addition to Option B:
4. Generate a client certificate and key signed by the MySQL CA.
5. In admin:
   - **Client Certificate Path:** `/etc/ssl/mysql/client-cert.pem`
   - **Client Key Path:** `/etc/ssl/mysql/client-key.pem`

### MySQL server configuration
Ensure your MySQL/MariaDB server has SSL enabled:
```sql
-- Check current SSL status
SHOW VARIABLES LIKE '%ssl%';
SHOW STATUS LIKE 'Ssl_cipher';

-- Require SSL for the ILL database user
ALTER USER 'seal_user'@'%' REQUIRE SSL;
FLUSH PRIVILEGES;
```

### For local socket connections
If WordPress and MySQL are on the same server communicating via Unix socket (e.g. `/var/run/mysqld/mysqld.sock`), TLS is not needed — traffic never crosses a network. Set **Require Encrypted Connection** to **Disabled** in this case.

---

## Credential Storage Architecture

```
wp-config.php (AUTH_KEY + SECURE_AUTH_KEY)
         │
         ▼
    HKDF-SHA256
         │
         ▼
  256-bit derived key
         │
    ┌────┴────┐
    │         │
sodium?    openssl?
    │         │
XSalsa20  AES-256-GCM
-Poly1305   (AEAD)
    │         │
    └────┬────┘
         ▼
  base64("sod:" or "osl:" + nonce + ciphertext)
         │
         ▼
    wp_options table
    (seal_db_pass)
```

Key properties:
- Random nonce per encryption — same password encrypted twice produces different ciphertext
- Authenticated encryption (AEAD) — tampered ciphertext is detected and rejected
- Key is site-specific (derived from WP secret keys, not hardcoded)
- Legacy XOR values are readable but trigger a warning to re-save

---

*This audit covers the PHP plugin code. Separate hardening of the WordPress installation, web server, OS, and MySQL server is recommended per standard practice.*


---

## v3.3.0 additions (not part of the June 2026 audit)

New modules: Login Screen, Login URL, Login Protection, Password Rules, Two-Factor, File Monitor (ILL → Security). Design notes relevant to review:

- **2FA session handling:** `wp_signon()` sets the auth cookie before the `wp_login` hook. The module destroys that server-side session token (captured from `set_logged_in_cookie`) and clears the cookie before showing the code screen, so a password alone never yields a usable cookie. Codes are single-use per time step; a challenge token is bound to the user, expires in 10 minutes and dies after 5 wrong codes; a per-user throttle (10 wrong codes / 15 min) applies even when IP lockouts are off.
- **Secrets at rest:** TOTP secrets are encrypted with `SEAL_DB::encrypt_pass` (libsodium / AES-256-GCM, key derived from the WP secret keys). Backup codes are stored only as `password_hash` values.
- **Pwned passwords:** k-anonymity range API (5-char SHA-1 prefix, `Add-Padding`, TLS verified, 3 s timeout, fails open).
- **Lockouts:** counters/locks in transients; permanent bans and the log in non-autoloaded options. Login lockouts block the login form; 404 and manual blocks cover the whole site. A lock check runs at `authenticate` priority 101 so a correct password cannot bypass it.
- **Login URL:** obscurity only; paired with lockouts/2FA. Emergency constants `SEAL_DISABLE_LOGIN_URL` / `SEAL_DISABLE_SECURITY`.
- **File monitor:** stores hashes only; admin actions require `manage_options` + nonce; alert emails escape all paths.
- **Output escaping / input:** all settings have typed sanitizers (hex color, URL, enum, integer ranges, IP/CIDR lists, path lists); CSS values from settings are sanitized before being placed in inline CSS; `login_headertext` (printed raw by core) is escaped.
- **Third-party code:** `assets/js/qrcode.js` — qrcode-generator 1.4.4 (Kazuhiko Arase, MIT), runs in the browser so the TOTP secret is never sent to an external service.
- **Verification status:** unit-tested with stubs and cross-checked against WordPress 7.1.1 source; **live end-to-end testing on a real site is still outstanding.**

---

## v3.5 additions: security keys and passkeys (WebAuthn)

Two-factor gains security keys (YubiKey and other FIDO2 keys) and passkeys alongside authenticator-app codes. Ported from the Argus
project's reviewed design. Design notes relevant to review:

- **Protocol checks are not hand-written.** `SEAL_WebAuthn` (`includes/class-seal-webauthn.php`) uses the bundled library
  `includes/lib/WebAuthn/` — lbuchs/webauthn v2.2.0 (MIT; GitHub release tarball sha256 `33eb82b72a1c22034a8e7bf0faf0b7c30eb125705e2ce1095121b0440329d38a`),
  which verifies origin, challenge, relying-party id hash, signature and signature counter. Its one network function (FIDO metadata
  download) is never called.
- **Relying party = the site's host name.** Keys are bound to it: they don't work on another host, an IP address or plain http, so a
  phishing site can't use them. Moving the site to a new address means re-registering keys.
- **Challenges:** stored in user meta (WordPress has no PHP session), single-use (deleted when read), valid 120 s, bound to the user and
  to "registration" or "sign-in". Sign-in options list only that user's credentials.
- **Touch only:** user presence required; user verification "discouraged" (no key PIN — the password is the first factor). Resident keys
  not required (second factor, not passwordless). Any FIDO2 maker accepted (attestation roots not checked).
- **Clone detection:** the stored signature counter is passed to every check; a key whose counter doesn't rise is refused.
- **Nothing secret stored:** key records hold the credential id and public key (PEM) only; the private key never leaves the authenticator.
- **Adding or removing a key needs the current password**; a wrong password counts toward the per-user throttle and fires
  `wp_login_failed` (Login Protection). Adding uses admin-ajax with a nonce; removal uses the My Profile form with a nonce.
- **Second step:** the key endpoint (`wp-login.php?action=seal_2fa_key`) shares the code form's challenge token (10 min, 5 tries) and
  throttle; failures fire `wp_login_failed`.
- **After any change** (key or app added/removed, codes regenerated, two-factor off, admin reset) the person's other sessions are destroyed
  and they are emailed. Roles that require two-factor can't remove their last method.
- **Verification status:** 24 stub tests with a software authenticator (real P-256 key pair): registration, sign-in, and refusal of a
  replayed counter, a wrong origin, a wrong or stale challenge and another key's signature; plus module flows. **Testing with a physical
  YubiKey in a browser is still outstanding.**


## v3.6–v3.8 additions (audited 2026-10-16, see docs/AUDIT-2026-10-16.md)

- **Email subjects and text** (ILL → Email Text): Administrators only; stored as plain text; on output everything is escaped and only `**bold**` / `*italic*` / paragraph breaks become markup; subjects collapsed to one line (no header injection). Cron `ill_text()` renders the same way.
- **Branded account emails**: WordPress's own reset link is reused; sent from the plugin's From address; not held by Email test mode (by design).
- **Change History** (`SEAL_Audit`): email text, every account change (own changes marked), security settings and Security actions; Administrators only (per-account view for ILL Admins / system staff only on accounts they can manage); passwords never stored, invite code masked; newest 1,000 entries **per area**; anonymous reset requests not recorded (flood protection). Holds IP addresses and before / after profile values: personal data, include in retention.
- **Account lists**: Security column (password rating, two-factor) and History only for accounts the viewer can manage; Approve / Deny with per-account nonces and the same permission rules as editing.
- **anonymize.php**: CLI only, no user input in SQL, `--dry-run`; irreversible (back up first).
- `.htaccess` also hides `includes/lib/`.

## v3.14.0: two-factor enforcement

- **Password-only channels:** while Two-Factor is on, the module itself turns off XML-RPC (`xmlrpc_enabled`) and application passwords, and an
  `authenticate` filter refuses any XML-RPC password sign-in, whatever the Login Protection and Registration Fields settings say.
- **Set-up gate:** for roles that require two-factor and haven't set it up, the gate runs before every ILL route (stand-alone pull slips included),
  refuses admin-post and admin-ajax actions except the two security-key setup calls, and shortcode-embedded ILL pages check it too.
- **Adding an authenticator app** needs the current password, like adding a key (a code from the new app only proves possession of the session).
- Stub test: `tests/test-2fa-hardening.php`.

## v3.15.0: security keys, forced resets, approvals

- **Security-key origins:** the bundled library accepts any https host ending in the relying-party id (and any port).
  `SEAL_WebAuthn::origin_ok()` also requires the exact site origin (https, host and port from the home / site URL) and refuses
  cross-origin frames, for registration and sign-in, before the library's own checks (signature, challenge, RP-ID hash,
  presence, counter), which are unchanged. The vendored library is not modified.
- **Forced password reset and breached passwords** apply to every sign-in channel, XML-RPC included. Only the breach lookup is
  skipped for a genuine application password (WordPress's `application_password_did_authenticate`).
- **Two-factor set-up gate** runs first on `admin_init` (priority -9999); the Approve / Deny handlers (Users-screen links,
  emailed links, bulk action, ILL account pages) also check it themselves.
- Stub tests: `tests/test-webauthn-origin.php` (real P-256 signatures), `tests/test-password-rules.php`,
  `tests/test-approval-gate.php` (full admin_init sequence).

## v3.15.1: forced reset over REST application passwords

- REST Basic auth calls `wp_authenticate_application_password()` directly (through `determine_current_user`), not the
  `authenticate` filter, so a forced password reset did not stop a valid application password there. Password Rules now
  also hooks `wp_authenticate_application_password_errors` and adds an error while the account is flagged (WordPress then
  refuses the request with 401). The token is never sent to the breach API. Only matters where application passwords are
  available (Two-Factor and the user-fields module normally switch them off).
- `tests/test-password-rules.php` runs the REST path through `determine_current_user`; set `WP_CORE_USER_PHP` to run it
  with WordPress's own functions.
