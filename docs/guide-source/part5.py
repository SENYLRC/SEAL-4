def build(g):
    # ── 7. ILLiad ─────────────────────────────────────────────────────────────
    g.h1("7. ILLiad integration")
    g.p("Lending libraries that run ILLiad can receive requests directly in ILLiad instead of (or as well as) by email. "
        "The system uses the ILLiad Web Platform API.")
    g.h2("7.1 Setting up a lender")
    g.steps([
        "On the library's record (**Manage All Libraries → Edit → ILLiad**, Administrators and ILL Administrators only), set "
        "**ILLiad library** to Yes and enter its ILLiad Web Platform address (https://…), its default loan period in days and its "
        "**API key**. The key is saved encrypted (section 4.4) and is never shown again: the form only says whether one is stored. Enter a "
        "new key to replace it, or tick **Remove the stored key**.",
        "Under **ILLiad Mapping**, add a row for each borrowing library that may send to it: borrower LOC code, the lender's "
        "ILLiad identifier, the borrower's ILLiad address number and library symbol in that lender's ILLiad. "
        "If a borrower has no row for that lender, the borrower's general mapping row is used, and the request carries a note saying so.",
        "Send a test request and confirm it arrives in ILLiad. Its ILLiad transaction number appears under the request's status.",
    ])
    g.h2("7.2 What happens automatically")
    g.bullets([
        "**On request**: the request is posted to the lender's ILLiad. If that fails, the administrators are alerted, and the request "
        "appears under **Resend a Request → Waiting to be sent to ILLiad**.",
        "**check_illiad.php** (four times a day): reads each open ILLiad request's status and records shipped (with due date) or cancelled (with the reason).",
        "**illiad_return.php** (nightly): marks requests ILLiad has finished as returned and checked in.",
        "**check_illiad_duedate.php** (nightly): copies due-date changes back, records cancellations, and closes shipped requests older "
        "than the auto-close limit (cron_config.php).",
        "**Automatic Fallback**: when a decline moves a request to an ILLiad library, it is sent to ILLiad straight away. "
        "When the move happens overnight because of a timeout, send it from **Resend a Request**.",
    ])
    g.note("The ILLiad API key is stored in the library table and is never shown on a page. Limit who can read the ILL database.", "warn")

    # ── 8. Hosting, security and maintenance ──────────────────────────────────
    g.h1("8. Hosting, security and maintenance")
    g.h2("8.1 Recommended hosting set-up")
    g.bullets([
        "HTTPS everywhere, with automatic certificate renewal. Test renewal (e.g. `certbot renew --dry-run`) after any change to DNS or the web server.",
        "The ILL database account has only the rights it needs (section 3.1). If the database is on another host, use TLS (section 4.4).",
        "Keep `ill_db.inc` and the scripts folder outside the web root, `chmod 600` on the login file.",
        "Turn on the security modules: login lockout, 404 detection, strong and breached-password checks, two-factor for administrators "
        "and staff (security keys or passkeys are the strongest method), the file monitor, and disable XML-RPC.",
        "**Behind a CDN or web application firewall** (for example CloudFront with AWS WAF):",
        ["Forward the original `Host` header to the server on every cache behavior, including the one for static files. "
         "The server's certificate must match that host name.",
         "Lock the server so it only accepts traffic from the CDN (security group / firewall rules, and a secret header the CDN adds), "
         "so the firewall can't be bypassed. Any exceptions to the secret-header rule (the server itself, an internal host) must test the "
         "address actually connected — in Apache `%{CONN_REMOTE_ADDR}`, not `%{REMOTE_ADDR}`, which can come from a forwarded header.",
         "Set the **Real-IP header** under Security → Login Protection (e.g. X-Forwarded-For), so lockouts apply to visitors and not to the CDN. "
         "Check: Security → Activity should list real visitor addresses, never the CDN's; a request sent with a made-up "
         "`X-Forwarded-For: <your office address>` from outside must still be refused.",
         "Other sites on the same server that use Apache's `RemoteIPHeader` with an IP-restricted area should also list the CDN as the "
         "only trusted proxy (`RemoteIPTrustedProxyList`), or anyone can claim an allowed address in that header.",
         "Don't cache logged-in pages or anything under /wp-admin, /wp-login.php or the custom login address."],
        "Send the **security response headers** below on every page (next section).",
    ])

    g.h3("Security response headers")
    g.p("These tell browsers to use HTTPS only, not to guess file types, not to let other sites frame the pages, and not to offer features the "
        "service never uses. The simplest place is the web server's own configuration, which covers every page, file and error response on "
        "every site it serves. A CDN in front can send them too (on CloudFront, the managed **SecurityHeadersPolicy** sends the first five); "
        "that does no harm.")
    g.table(["Header", "Value"], [
        ["Strict-Transport-Security", "max-age=31536000"],
        ["X-Content-Type-Options", "nosniff"],
        ["X-Frame-Options", "SAMEORIGIN"],
        ["Referrer-Policy", "strict-origin-when-cross-origin"],
        ["X-XSS-Protection", "1; mode=block (older browsers only)"],
        ["Content-Security-Policy", "frame-ancestors 'self'"],
        ["Permissions-Policy", "camera=(), microphone=(), geolocation=(), payment=(), usb=()"],
    ], [2.2, 4.3])
    g.p("On Apache, create `/etc/apache2/conf-available/security-headers.conf` with the block below, then enable and reload "
        "(`a2enmod headers`, `a2enconf security-headers`, `apachectl configtest`, `systemctl reload apache2`). The same block also works in "
        "the **WordPress root** `.htaccess` above `# BEGIN WordPress`, but not in the plugin's own `.htaccess`, which only covers files inside "
        "the plugin folder. `setifempty` leaves the first headers alone when they are already set:")
    g.code("""<IfModule mod_headers.c>
    Header always setifempty Strict-Transport-Security "max-age=31536000"
    Header always setifempty X-Content-Type-Options "nosniff"
    Header always setifempty X-Frame-Options "SAMEORIGIN"
    Header always setifempty Referrer-Policy "strict-origin-when-cross-origin"
    Header always set Content-Security-Policy "frame-ancestors 'self'"
    Header always set Permissions-Policy "camera=(), microphone=(), geolocation=(), payment=(), usb=()"
</IfModule>""")
    g.p("Also hide the server version: in `/etc/apache2/conf-available/security.conf` set `ServerTokens Prod` and `ServerSignature Off`, then "
        "reload. The `Server` header then says only “Apache”.")
    g.note("Don't add a full Content-Security-Policy without testing: the theme and the catalog search use inline and third-party scripts that "
           "a strict policy would block. `usb=()` does not affect security keys (they use WebAuthn, not WebUSB). Give a new site address "
           "the same headers before it goes live (automatic if the same web server serves it), and check with "
           "`curl -sI https://your-site/ | grep -iE \"strict-transport|content-security|permissions-policy\"`.", "tip")
    g.h2("8.2 Backups")
    g.bullets([
        "Both databases (WordPress and ILL) nightly, kept off the server.",
        "wp-config.php: it holds the secret keys that unlock the stored ILL database password.",
        "The scripts folder, including cron_config.php and ill_db.inc (store the copy securely).",
        "The uploads folder (logos, images).",
    ])
    g.h2("8.3 Deploying an update")
    g.steps([
        "Try it on the test site first, with Email test mode on.",
        "Back up the plugin folder, then copy the new files over it. Keep any local changes you made to the .htaccess file. "
        "A copy tool that sends only changed files (for example rsync with a dry run first, never deleting on the server, and leaving out "
        "notes, docs and tests) keeps updates small and repeatable.",
        "If the update changes styles or scripts, its version number is bumped so browsers load the new files. If pages look unstyled after an update, "
        "clear any CDN or page cache.",
        "Update the scheduled scripts separately if they changed (section 5.8).",
        "Open the Dashboard (database connection), make a test request, and check the cron log the next morning.",
    ])
    g.h2("8.4 Routine checks")
    g.table(["How often", "Check"], [
        ["Daily", "Alert emails; the cron log (`tail /var/log/ill_cron.log`)."],
        ["Weekly", "The weekly statistics email arrived; the Resend page's “Waiting to be sent to ILLiad” list is empty; pending user approvals."],
        ["Monthly", "Security → Activity for lockouts; File Monitor findings; Change History for unexpected account or security changes; WordPress, theme and plugin updates."],
        ["After any server or CDN change", "The security response headers are still sent (section 8.1); the plugin's README.md still gives 403 or 404."],
        ["Each year", "Add next year's Closure Dates; rotate the registration invite code; review accounts of staff who have left, and accounts "
                      "with weak passwords or two-factor not set up (the Password and Two-factor columns on User Accounts)."],
    ], [1.2, 5.3])

    # ── 9. Troubleshooting ────────────────────────────────────────────────────
    g.h1("9. Troubleshooting")
    g.table(["Problem", "Likely cause and fix"], [
        ["ILL pages show “Page not found”", "Rewrite rules not registered. Settings → Permalinks → Save. Check the Routes screen."],
        ["Pages load but look unstyled; Org Rules lists show as plain text boxes",
         "The browser could not load the plugin's CSS or JavaScript. Behind a CDN, the static-file behavior is probably not forwarding the Host header "
         "(often a 502 on /wp-content/). Fix the CDN settings, then clear its cache."],
        ["“Your request was not submitted”", ["The page gives the reason: the library is suspended or not participating, its LOC code doesn't match a library "
                                              "record, or the database save failed.", "Administrators also get an alert with the database's error message."]],
        ["A library isn't offered for a title it owns",
         "Add `?ill_debug=1` to the request page address (administrators only). It lists each holding and why it was hidden: no alias match, "
         "not available, no ILL email, suspended, item type, or own system. Fix the alias or the Org Rules. "
         "If the box says no holdings were found for a catalog that keeps its copies in the MARC record, check the "
         "**Extra Holdings Lookups** row for it: the field and subfields must be the ones the record really uses (look at the record's MARC view; "
         "for example 909 $a / $h rather than 952 $b / $o)."],
        ["A lender says they never got a request", "Check the ILL email on their library record, then use Resend a Request. Check your mail relay's log for bounces."],
        ["Emails show stray characters such as “< /a>”", "Long lines broken by a mail server. The current scripts send base64-encoded bodies; update cron_config.php."],
        ["No reminders or expiries are happening", ["Run `crontab -l` as the cron user, then run the script by hand and read the log.",
                                                     "Check that ill_db.inc exists and has the right logins, and that the log file is writable."]],
        ["Scripts stop with a database exception", "PHP 8.1+ turns database errors into exceptions. The current cron_config.php switches that off; update it. "
                                                    "Also check that the Rota table name in ill_db.inc matches the database."],
        ["Holidays aren't being skipped", "Check outbound HTTPS to date.nager.at, that the scripts folder is writable for the cache files, and the country/region codes. "
                                           "Closure Dates need the WordPress login in ill_db.inc."],
        ["Test site sent real email", "Email test mode is off, or the scripts can't read WordPress (missing WordPress login in ill_db.inc). Check both before testing."],
        ["“Use security key or passkey” does nothing, or says keys only work on the site's https address",
         ["The page must be opened over HTTPS on the site's own address; keys don't work on another host name, an IP address or plain http.",
          "A key registered on another address (e.g. the test site) must be added again here."]],
        ["Adding a key opens a password manager instead of the key", "Bitwarden, 1Password and similar offer to save a passkey. Choose their “use a hardware key” option, or turn off their passkey prompts."],
        ["Someone lost their key and their backup codes", "An Administrator or ILL Administrator resets their two-factor on their WordPress profile (section 6.8); they set it up again after signing in."],
        ["Locked out of the admin", "Use the wp-config.php switches in section 4.10, or unlock your address from another administrator's session (Security → Activity)."],
        ["ILL database login stopped working after a WordPress change", "The WordPress secret keys changed. Re-enter the ILL database password under Database."],
        ["A staff member can't see their library's requests", "The LOC code on their profile doesn't match the library record, or their role is wrong. Check under User Accounts."],
    ], [2.1, 4.4])

    # ── 10. Reference ─────────────────────────────────────────────────────────
    g.h1("10. Reference")
    g.h2("10.1 Request statuses")
    g.table(["Status", "Stored as (Fill)", "Meaning"], [
        ["No Answer", "3 (or blank on old rows)", "Sent; the lender hasn't answered yet."],
        ["Filled", "1", "The lender will send the item."],
        ["Not Filled", "0", "The lender can't fill it. The reason is recorded."],
        ["Expired", "4", "No answer within 5 business days."],
        ["Canceled", "6", "Cancelled by the borrower."],
    ], [1.3, 1.6, 3.6])
    g.p("Alongside the status, each request records whether the reminder and expiry emails went out (shown as the "
        "**Reminder Sent** and **Expired** badges), and the received, returned, checked-in and renewal steps, each with who did it and when.")
    g.h2("10.2 Timing rules")
    g.table(["Event", "When"], [
        ["Reminder to lender", "More than 3 business days with no answer"],
        ["Request expires (or moves on, with fallback)", "More than 5 business days with no answer"],
        ["Due-date reminder to borrower", "5 days before the due date, for items received and not returned"],
        ["Lender can change Filled / Not Filled", "Within 14 days of answering"],
        ["Check In offered to lender without a return", "More than 14 days after the request"],
        ["Suspension ends", "The day after the Suspend Until date"],
        ["Shipped ILLiad requests auto-closed", "After ILL_AUTOCLOSE_MONTHS (default 7)"],
    ], [3.0, 3.5])
    g.h2("10.3 Emails the system sends")
    g.table(["Email", "To", "Sent by"], [
        ["New interlibrary loan request (Yes / No buttons)", "Lender", "Request page"],
        ["Your request confirmation (copy)", "Borrower", "Request page"],
        ["Your request will be filled / can't be filled", "Borrower (Not-Filled CC on a no)", "Lender's answer"],
        ["Your request is being tried at another library", "Borrower", "Automatic Fallback"],
        ["Interlibrary loan request (automatically routed)", "Next lender", "Automatic Fallback"],
        ["Renewal requested / approved / denied", "Lender / borrower", "Renewal page, bulk renew"],
        ["New due date", "Borrower", "Lender edits the due date; check_illiad_duedate.php"],
        ["Request cancelled", "Lender", "Borrower cancels"],
        ["Reminder: a request is waiting for your answer", "Lender, copy to borrower", "3day_reminder.php"],
        ["Your request has expired / A request to your library has expired", "Borrower / lender", "expire.php"],
        ["An item you borrowed is due soon", "Borrower", "due_date.php"],
        ["Weekly statistics", "ILL_STATS_TO", "stats_weekly.php"],
        ["Welcome: set your password", "New user", "Registration; account created by an administrator or system staff; Import Users"],
        ["Reminder: set your password (3 days and 1 day before the link expires)", "New user who hasn't set a password", "Daily scheduled job (Users & Registration)"],
        ["Reset your password", "The account's owner", "Lost your password?; an administrator's password reset"],
        ["New account waiting for approval; Your account is ready; Registration not approved", "Admins / new user", "User Approval"],
        ["Your sign-in security settings changed", "The account's owner", "Adding or removing a key, app or backup codes; admin reset"],
        ["System alerts (database, ILLiad, file monitor, failed reminders, anonymizer)", "Alert, support and NOC addresses", "Plugin and scripts"],
    ], [3.0, 1.9, 1.6])
    g.p("Every email uses the same branded layout, and comes from the From: address in Contacts & Email (the welcome and password "
        "emails too, which WordPress would otherwise send as plain text from its own address). Subjects and wording can be changed under "
        "**Email Subjects & Text** (section 4.3).")

    # ── Appendices ────────────────────────────────────────────────────────────
    g.h1("Appendix A. Database columns")
    g.h2("A.1 Request table (default STATS)")
    g.table(["Column(s)", "Holds"], [
        ["index, illNUB", "Internal row number; the ILL number shown to people (year-index)."],
        ["Title, Author, pubdate, reqisbn, reqissn, itype, Call Number, Location, Available, article", "The item requested; article details as labelled lines."],
        ["needbydate, reqnote, patronnote", "Need-by date; note to the lender; the borrower's own patron reference."],
        ["Destination, DestSystem", "Lending library's LOC code and library system."],
        ["Requester lib, Requester LOC, ReqSystem, Requester person, requesterEMAIL, requesterPhone, saddress, saddress2, caddress", "Who asked, and where to send it."],
        ["Timestamp", "When the request was made."],
        ["Fill, emailsent, fillNofillDate", "Status (section 10.1); reminder / expiry flags; when the lender answered."],
        ["responderNOTE, ReasonNotFilled, DueDate, shipMethod", "The lender's answer."],
        ["receive*, return*, checkin*", "Received, returned (method, note) and checked-in steps: timestamp, account and date."],
        ["renewTimeStamp, renewAccountRequester, renewNote, renewAnswer, renewAccountLender, renewNoteLender",
         "Renewal: who asked and why, the answer (1 approved, 2 denied), who answered and their note."],
        ["IlliadTransID, IlliadStatus", "ILLiad transaction number and last status."],
    ], [3.2, 3.3])
    g.h2("A.2 Library table (default Library-Data)")
    g.table(["Column(s)", "Holds"], [
        ["recnum", "Internal row number."],
        ["Name, loc, alias, system", "Library name, LOC code, catalog alias, library system code."],
        ["ill_email, phone, oclc, address1–3", "Contact details. ill_email may hold several addresses."],
        ["participant, suspend, SuspendDateEnd, SuspendCheckDate", "Whether it takes part; suspension and its end date; when the suspension job last checked it."],
        ["book_loan, periodical_loan, av_loan, ebook_request, ejournal_request, theses_loan", "Yes / No: item types it lends."],
        ["Delivery columns (optional)", "Yes / No: delivery methods it accepts; shown on Library Profile, the library list and the ILL Directory."],
        ["Illiad, IlliadURL, APIkey, IlliadDATE", "ILLiad: in use, web platform address, API key, default loan days."],
        ["ModifyDate, ModEmail", "When the record was last changed, and by whom."],
    ], [3.2, 3.3])
    g.h2("A.3 Other tables")
    g.bullets([
        "**ILLiad mapping** (default ILLIAD-ADD-MAPPING): num, LOC (borrower), illiadID (lender), illiadADDnumb, illiadLIBSymbol.",
        "**Fallback** (default ILL-ROTA): illNUB, status (active / filled / cancelled / exhausted), hop, hop_started, queue (libraries still to try), "
        "history (every hop with its reason), created, updated. Created automatically.",
    ])

    g.h1("Appendix B. Files")
    g.table(["Path (inside the plugin folder)", "Contents"], [
        ["Main plugin file", "Plugin header, default settings, start-up hooks."],
        ["admin/", "The ILL System settings screens and the Security screen."],
        ["includes/", "Shared classes: database access, roles, routing, sidebar, shortcodes, Org Rules, email helpers, Automatic Fallback, resend, authenticator-app codes, security keys."],
        ["includes/lib/WebAuthn/", "Bundled third-party library (lbuchs/webauthn, MIT licence) that checks security keys and passkeys. Do not edit."],
        ["includes/pages/", "One class per ILL page (request, respond, renew, histories, admin pages, statistics)."],
        ["includes/modules/", "Optional modules (profile fields, registration, login, security, footer, ...)."],
        ["assets/", "Styles, scripts and icons."],
        ["cron/scripts/", "The scheduled scripts and their README. Copied to the server's scripts folder; never served from the web."],
        ["docs/", "Internal notes. Never served from the web."],
        ["deploy/", "The copy-to-server script used for updates. Not needed on the server."],
        ["tests/", "Developer tests (for example of the database check). Not needed on the server."],
    ], [2.4, 4.1])

    g.h1("Appendix C. Glossary")
    g.table(["Term", "Meaning"], [
        ["Borrower / requester", "The library asking for an item."],
        ["Lender / destination", "The library asked to supply it."],
        ["LOC code", "Short code that identifies a library (often its MARC organization code). Links user accounts, library records and requests."],
        ["Alias", "The name the catalog uses for a library's holdings, used to match a copy to a participating library."],
        ["Library system", "The group a library belongs to (a public library system, school library system, council, ...)."],
        ["Automatic Fallback / Rota", "Moving a request to the next library when a lender says no or doesn't answer."],
        ["Business day", "A weekday that is not a public holiday, Winter Break day, or Closure Date."],
        ["Pull slip", "Printed slip used to pull and route an item for shipping."],
        ["Email test mode", "A setting that stops the system emailing real people while testing."],
        ["Security key", "A small device (for example a YubiKey) that a person touches as the second sign-in step. The secret never leaves the key."],
        ["Passkey", "The same idea built into a computer or phone (Windows Hello, Touch ID, Android), unlocked with the device's own sign-in."],
    ], [1.8, 4.7])
