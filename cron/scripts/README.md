# ILL scheduled scripts (cron)

The nine scheduled scripts, rebuilt from the current SEAL scripts in
[SENYLRC/SEAL-3 `seal_wp_script`](https://github.com/SENYLRC/SEAL-3/tree/main/seal_wp_script).

**Same files for every organization.** Script names, file names and variable names are generic (`expire.php`,
`cron_config.php`, `ill_db.inc`, `$illSTAT`, `ILL_FROM_EMAIL`, ...). What differs per organization:
- **the folder**, named after the org's short code: `/var/www/<code>_script` (e.g. `/var/www/town_script`). Set the code under ILL → Cron Jobs → **Short code** (blank = the Org Short Name);
  the crontab block on that page uses it.
- **`cron_local.php`**: this organization's addresses, links and alert subject (copy `cron_local.php.example`; not in
  version control). It overrides the generic defaults in `cron_config.php`, which is the same for everyone.
- **`ill_db.inc`**: the database logins (on the server only).

**Renamed on 2026-09-29.** These used to be `cdlc_*.php` (`cdlc_illiad_retun.php` is now `illiad_return.php`),
`cdlc_cron_config.php`, `cdlc_db.inc` with `$cdlcSTAT`/`$cdlcLIB`/..., and logged to `/var/log/cdlc_cron.log`.
See **Moving from the cdlc_ names** below — the crontab has to be replaced.

| Script | Schedule | What it does |
|---|---|---|
| `3day_reminder.php` | 07:01 daily | Reminds lenders who have not answered after 3 business days |
| `expire.php` | 07:06 daily | Expires requests unanswered after 5 business days |
| `check_suspend.php` | 07:05 daily | Ends a library's suspension when its end date passes |
| `due_date.php` | 07:05 daily | Emails requesters 5 days before an item is due |
| `stats_weekly.php` | Mondays 07:10 | Weekly statistics email |
| `check_illiad.php` | 08, 12, 16, 20:00 | Pulls ILLiad status (shipped / cancelled) into the request |
| `illiad_return.php` | 20:00 daily | Marks requests ILLiad finished as returned / checked in |
| `check_illiad_duedate.php` | 20:00 daily | Syncs due dates and cancellations from ILLiad |
| `anonymize.php` | 06:30 daily | Data retention: auto-expires very old pending requests, clears notes, anonymizes old requests (see below) |

Supporting files: `cron_config.php` (all organization settings), `tests/` (do not deploy).
`ill_db.inc` is **not** included (it lives only on the server). It holds **both** database logins — the ILL
database and (new in this batch) the WordPress database, used for Closure Dates and Email test mode:
```php
<?php
// ILL database
$dbhost = '...';  $dbuser = '...';  $dbpass = '...';  $dbname = '...';
$illSTAT = '...';  $illLIB = '...';  $illILLiadMapping = '...';  $illROTA = 'ILL-ROTA';
// WordPress database (DB_HOST, DB_USER, DB_PASSWORD, DB_NAME and $table_prefix from wp-config.php)
$wpDbHost = '...';  $wpDbUser = '...';  $wpDbPass = '...';  $wpDbName = '...';  $wpTablePrefix = 'wp_';
// Encrypted, verified connections (recommended when the database is on another host, e.g. RDS). Same CA file as
// WordPress → ILL System → Database → CA Certificate Path. Used for both databases ($wpDbSslCa overrides for WordPress's).
$dbSslCa = '/etc/mysql-certs/global-bundle.pem';
// Reading encrypted ILLiad API keys: the same base64 value as define('ILL_DATA_KEY', …) in wp-config.php.
$illDataKey = '…';
```
Check the connections (changes nothing): `php cron_config.php --check-db` in the scripts folder — it prints whether each
database connection is encrypted. If `$dbSslCa` names a file that's missing, the jobs refuse to connect (logged) rather than
connect unencrypted.
Owned by root, `chmod 600`, outside the web root. (A separate `wp_db.inc` with the `$wpDb…` lines still works.)

**If you turn on the Automatic Fallback (Rota) module** (ILL → Modules), add one line to `ill_db.inc` alongside `$illSTAT`/`$illLIB`/`$illILLiadMapping`:
```php
$illROTA = 'ILL-ROTA';   // must match ILL → Database → "Rota (Fallback) Table" in WordPress
```
`expire.php` falls back to `'ILL-ROTA'` on its own if you forget, but keep the two in sync if you ever rename the table.

## Settings (`cron_local.php`, defaults in `cron_config.php`)
Log file (`/var/log/ill_cron.log`), time zone, From name/address, envelope sender, alert subject prefix, NOC address,
weekly-stats recipients, optional ILL-office copy of expiry notices (`ILL_OFFICE_EMAIL`, empty = off), site URL for email
links, `ILL_AUTOCLOSE_MONTHS` (default 7; 0 = never auto-close old shipped requests), and `ILL_ILLIAD_SHIP_TEXT` (how
ILLiad-filled items ship, shown in the "will be filled" email). The defaults are placeholders (`library.org`): set your
own in `cron_local.php` before installing.

## Data retention (`anonymize.php`)
Ported from SEAL-3 `seal_anonymizer.php`. Three steps; the limits are set under ILL System → Cron Jobs → Data retention in WordPress (read every run; the `ILL_ANON_*` values in `cron_config.php` are used only if WordPress can't be read; 0 = step off):
1. **Auto-expire** requests still pending (no answer) after `ILL_ANON_EXPIRE_MONTHS` (18): Fill = 4 and emailsent = 3 like
   `expire.php`, with "Expired automatically (18 months)" added to the responder note. No emails are sent.
2. **Clear the request note** (`reqNOTE`) of expired requests `ILL_ANON_NOTE_DAYS` (30) days after they expired.
3. **Anonymize** requests older than `ILL_ANON_MONTHS` (60 = 5 years): Title, Author, pubdate, ISBN/ISSN, item type, call
   number, article, need-by date, patron note, due date, and the return / renew / responder / request notes are blanked.
   The row stays (ILL number, libraries, dates, outcome), so statistics still count it. Columns the table doesn't have are skipped.

**Run a dry run first** — it only counts, nothing is written:
```bash
php /var/www/<code>_script/anonymize.php --dry-run
```
The changes can't be undone, so take a database backup before the first real run. A failed step emails `ILL_NOC_EMAIL`.

## Moving from the cdlc_ names (existing CDLC server, one time)
Run as root in `/var/www/cdlc_script` after the backup in step 1 below:
```bash
cp -a cdlc_db.inc ill_db.inc
sed -i -E 's/\$cdlc(STAT|LIB|ILLiadMapping|ROTA)\b/$ill\1/g' ill_db.inc
chmod 600 ill_db.inc
```
Then copy the new files in (step 2), paste the new crontab block from ILL → Cron Jobs (the plugin renames
the saved rows once, automatically), check `crontab -l` shows the new names, and only then remove the old files:
`rm cdlc_*.php cdlc_db.inc` (and `wp_db.inc` if its lines are now in `ill_db.inc`). The log moves from
`/var/log/cdlc_cron.log` to `/var/log/ill_cron.log` (step 4).

## Installing
1. Back up the folder: `cp -a /var/www/<code>_script /var/www/<code>_script.bak-$(date +%F)` (e.g. `cdlc_script`).
2. Copy everything in this folder except `tests/` and `README.md` into `/var/www/<code>_script/` (keep the server's `ill_db.inc`). For a new organization, create `cron_local.php` from `cron_local.php.example` first.
3. Add the WordPress login (`$wpDb…` lines) to `ill_db.inc`. Without it the jobs still run, but ignore Closure Dates and Email test mode.
4. Create the log file for the user that runs cron: `sudo touch /var/log/ill_cron.log && sudo chown <cron user> /var/log/ill_cron.log`.
5. Run each script once by hand as that user and read the output and `/var/log/ill_cron.log`. Do the read-only ones first (`stats_weekly.php`, then the ILLiad checks). The reminder, expire and due-date scripts send real email to real libraries.
6. Optionally switch the crontab redirect from `>/dev/null 2>&1` to `>> /var/log/ill_cron.log 2>&1` (ILL -> Cron Jobs builds the block for you).
7. Rollback: restore the `.bak` folder.

## Holiday calendar
No more `holiday.txt` — CDLC's own extra closures (Day after Thanksgiving, staff days, ...) are now entered
under **ILL → Org Rules → Closure Dates** in WordPress, the same editable-list pattern as the rest of Org Rules.
`ill_get_holidays()` combines three sources (weekends are always skipped on top of these):
- **Closure Dates from WordPress**, read with a direct MySQL query (`SELECT option_value FROM {prefix}options WHERE option_name = 'seal_rules_closures'`) — this script still never loads WordPress itself. The WordPress login comes from the `$wpDb…` lines in `ill_db.inc` (see above). If they are missing, or the connection or query fails, this source is silently empty for that run (logged as a note, not a warning) — the auto-fetched federal holidays below still apply.
- **Auto-fetched US federal holidays**, live from the [Nager.Date](https://date.nager.at) public API, ported from SEAL-3's `seal_3day_reminder.php` `getHolidaysAuto()` at the user's request. Fetches the previous, current, and (in December) next year, and caches each year's result to `holidays_YYYY.json` next to this script for up to 7 days. Only nationwide and New York holidays are kept (`ILL_HOLIDAY_REGION`). A failed fetch falls back to the cached copy, or an empty list for that year, so the jobs never hard-fail over it.
- **Winter Break (Dec 24 -> Jan 1)**, always added, even with the online fetch turned off.

Business days are counted by `isBusinessDay()` / `addBusinessDays()` in `cron_config.php` (one shared copy for the reminder and expire jobs). Counting starts the day after the request, uses calendar dates in New York time, and is tested across New Year's, daylight-saving changes and leap days (`tests/run.php`).

**This adds two new things to check on the server:** outbound HTTPS to `date.nager.at` and write access to this script's own folder (for the `holidays_YYYY.json` cache files, unrelated to the change above), and the WordPress login in `ill_db.inc`. If the server has no outbound internet access, or you'd rather not depend on a third-party API, set `ILL_HOLIDAY_AUTO_FETCH = false` in `cron_config.php` — the jobs keep working from the Closure Dates list and Winter Break.

## Email test mode
Every script sends email through `ill_mail()` (in `cron_config.php`) instead of `mail()`. By default
(`ILL_EMAIL_MODE = 'wordpress'`) it follows the WordPress setting **ILL → Contacts & Email → Email test mode**,
read with the WordPress login in `ill_db.inc`: *send normally*, *send everything to the test address* (subject gets `[TEST]`, the
original recipients are listed at the top of the message, Cc lines are dropped), or *don't send* (each held email is
written to `/var/log/ill_cron.log`). Only email is affected — requests are still marked reminded / expired.
If WordPress can't be reached, the scripts send normally (so production reminders never stop silently);
while testing you can force it with `ILL_EMAIL_MODE = 'off'` (or `'redirect'` plus `ILL_EMAIL_TEST_TO`).

## Automatic Fallback ("Rota")
Opt-in, per request, on the plugin's `/request` page: when a single-copy request's checkbox is
checked, `expire.php` moves it to the next library on the borrower's list automatically if the
current lender never answers within 5 business days, instead of expiring it. (The other trigger — a
lender explicitly clicking "No, Can't Fill" — is handled by the plugin itself, in `/respond`, not by
any cron script.) State lives in its own table (`ILL-ROTA` by default), never in `STATS`; a request
that never opted in behaves exactly as before. See the header comment in `includes/class-seal-rota.php`
in the plugin for the full design — this script's `ill_rota_get()` / `ill_rota_advance_on_timeout()`
functions are a plain-PHP mirror of that class's logic, kept in step by hand since this script cannot
load WordPress.

**Known limitation:** a hop triggered by this script (timeout, not decline) does not automatically
resubmit to ILLiad even if the new library uses it — send it from WordPress under ILL Admin → Resend a
Request, where it is listed under "Waiting to be sent to ILLiad". The plugin's own decline-triggered hops
(handled in `/respond`, not by this script) do resubmit automatically.

## What changed from today's CDLC scripts
**Read this list before installing: several are behavior changes.**

1. **Due-date reminders work now.** Today's `due_date.php` mails a variable that is never set, so requesters have not been getting them. After installing, requesters start receiving "approaching due date" emails. It also matches dates stored as either `MM/DD/YYYY` (old) or `YYYY-MM-DD` (what the WordPress plugin and ILLiad store); the SEAL original only matched the old format.
2. **Business days** are counted with SEAL's exact method (weekends and Closure Dates skipped): a reminder goes out once more than 3 business days have passed, expiry after more than 5. Today's approximation can differ by a day.
3. **Expire also catches requests that never got a reminder** (`emailsent` 0 or 2; today only 2), and it clears the "expired" flags on requests that were filled after expiring.
4. **Shorter emails, UTF-8.** Reminder, expiry and due-date emails use SEAL's shorter text with CDLC branding, not the long copy of the whole request. All mail is UTF-8 (was ISO-8859-1) and always From `CDLC Linx <donotreply@cdlc.org>` (the old reminder to lenders used a mistyped `dontreply@CDCL.org`). Reminder links are `https://linx.cdlc.org/respond?num=N&a=1|0`, which the new plugin's response page accepts.
5. **Failures are visible.** Everything is logged to `/var/log/ill_cron.log`. A request that cannot be reminded (no destination, no lender email, database error) alerts the NOC and is marked `REMINDER FAILED ...` so it is not retried forever.
6. **Suspensions with no end date stay suspended** (today they are ended the next morning).
7. **ILLiad fixes:** cancellation reasons are mapped correctly (today every cancellation is recorded as "Poor condition" because of an empty-string test); the "Searching" status is included; "checked in" and "returned" count as finished; bad or empty ILLiad responses are logged instead of producing PHP warnings; requests without an ILLiad number are skipped; NOC is emailed when a database update fails (that mail is commented out today).
8. **Due-date sync** (`check_illiad_duedate.php`) now also records ILLiad cancellations, and stops polling shipped requests older than `ILL_AUTOCLOSE_MONTHS` (status becomes "Assumed Complete after 7 months"). The first run will close every shipped request older than that.
9. **Weekly stats** are counted with `COUNT(*)`, add fill and expire rates, and use a comma-separated recipient list (today's `;` list is not valid for `mail()`).
10. **Manual resends are no longer scripts.** `cdlc_illiad_manually_resend.php` and `cdlc_resend-request.php` are replaced by ILL Admin → Resend a Request in WordPress (enter the ILL number; resend the request emails and/or send it to ILLiad).
11. Scripts refuse to run from a web request, and read `ill_db.inc` (both database logins) from their own folder.

Unchanged on purpose: the text "Shipped via: empire" in the ILLiad "filled" email (also hard-coded in today's script).

Not ported: SEAL's `seal_anonymizer.php` (CDLC does not run one).

## Testing
`php tests/run.php` runs all eight scripts against a fake database (including the second, WordPress, connection), fake sendmail and a fake ILLiad API (no MySQL, mail server or network needed) and checks the SQL and emails: 115 checks, including the rota advance-on-timeout/queue-exhausted paths and reading Closure Dates from the WordPress database. It cannot test real ILLiad servers or your real data, so do the manual run in step 5 above.

## Email layout
Every HTML email the scripts send goes through `ill_mail()`, which wraps it in the same layout as the WordPress plugin's
emails: a header with the logo or system name on the brand colour, a white card, and a footer with the site link.
The colour, logo, System Short Name / Full Name and Footer Text are read from WordPress (ILL → Branding) with the WordPress
login in `ill_db.inc`, so changing the branding changes both. If WordPress can't be reached, defaults are used
(`#4a4180`, `ILL_FROM_NAME`). The building blocks (`ill_email_heading()`, `ill_email_details()`, `ill_email_buttons()`,
`ill_email_alert()` for NOC alerts, ...) are in `cron_config.php`; they mirror `SEAL_Functions::email_*()` in the plugin,
so keep the two in step. Every value is HTML-escaped (the old messages inserted titles and notes unescaped).
Subjects start with the System Short Name instead of a hard-coded "Linx".
