def build(g):
    g.h1("5. Scheduled scripts (cron)")
    g.p("Nine PHP command-line scripts do the time-based work. They are separate from the plugin: copy them to a folder "
        "outside the web root and run them from cron. They connect to the ILL database directly, and read a few WordPress "
        "settings (branding, Email test mode, Closure Dates, email subjects and text) straight from the WordPress database. They never load WordPress itself.")
    g.h2("5.1 The jobs")
    g.table(["Script", "Suggested schedule", "What it does"], [
        ["3day_reminder.php", "Daily 07:01", "Reminds lenders that have not answered after 3 business days, copies the borrower, and marks the request “Reminder Sent”."],
        ["expire.php", "Daily 07:06", "Expires requests still unanswered after 5 business days and emails both sides. With Automatic Fallback on, moves the request to the next library instead."],
        ["check_suspend.php", "Daily 07:05", "Ends a library's suspension once its end date has passed. Suspensions with no end date stay in place."],
        ["due_date.php", "Daily 07:05", "Emails borrowers 5 days before an item they received is due."],
        ["stats_weekly.php", "Mondays 07:10", "Emails the weekly statistics summary (requests, fill rate, expiry rate)."],
        ["check_illiad.php", "08:00, 12:00, 16:00, 20:00", "Reads the status of requests sent to ILLiad lenders and updates status, due date and shipping."],
        ["illiad_return.php", "Daily 20:00", "Marks requests that ILLiad reports as finished as returned and checked in."],
        ["check_illiad_duedate.php", "Daily 20:00", "Copies due dates changed in ILLiad back to the request, records ILLiad cancellations, and closes shipped requests older than the auto-close limit."],
        ["anonymize.php", "Daily 06:30", "Data retention: expires requests unanswered for 18 months, clears the request note of expired requests after 30 days, and blanks item details and notes on requests older than 5 years."],
    ], [1.75, 1.35, 3.4])
    g.p("Supporting files: `cron_config.php` (all of your organization's script settings) and `ill_db.inc` "
        "(database logins, which you create on the server). The `tests/` folder is for developers and is not deployed.")

    g.h2("5.2 Install the scripts")
    g.steps([
        "Pick a short code for your organization (lower-case letters and digits, e.g. `abc`) and create the folder "
        "`/var/www/abc_script`. Enter the same code under **ILL System → Cron Jobs → Short code**.",
        "Copy every `.php` file from the plugin's `cron/scripts/` folder into it. Don't copy `tests/` or `README.md`.",
        "Edit `cron_config.php` (section 5.3).",
        "Create `ill_db.inc` (section 5.4), owned by the cron user, with `chmod 600`.",
        "Create the log file: `sudo touch /var/log/ill_cron.log && sudo chown <cron user> /var/log/ill_cron.log`.",
        "Run each script once by hand as the cron user and read its output and the log. Run the read-only ones first "
        "(`stats_weekly.php`, then the ILLiad checks). Turn Email test mode on first if the database holds real requests: "
        "the reminder, expire and due-date scripts email real libraries.",
        "Open **ILL System → Cron Jobs**, check the folder, PHP path and output settings, and copy the crontab block. "
        "Run `crontab -e` as the cron user, paste it, save, and check with `crontab -l`.",
    ])
    g.code("""cd /var/www/abc_script
php stats_weekly.php
php check_illiad.php
tail -50 /var/log/ill_cron.log""")

    g.h2("5.3 cron_local.php")
    g.p("`cron_config.php` holds every setting with a placeholder default and is the same for every organization. Put your own values "
        "in `cron_local.php` next to it: copy `cron_local.php.example`, then set at least the From address, NOC address, statistics "
        "recipients and site address. Anything you don't set keeps the default. `cron_local.php` is yours: plugin updates don't "
        "include it, so it is never overwritten.")
    g.table(["Setting", "What it is"], [
        ["ILL_TIMEZONE", "Your time zone (e.g. America/Chicago). Business days are counted in local calendar dates. Default America/New_York."],
        ["ILL_LOG_FILE", "Log file every script appends to. Default /var/log/ill_cron.log."],
        ["ILL_FROM_NAME / ILL_FROM_EMAIL", "The From name and address on script email."],
        ["ILL_ENVELOPE_FROM", "Envelope sender (bounce address). Leave empty unless your relay requires one."],
        ["ILL_NOC_EMAIL", "Where script failures are reported (database errors, a reminder that could not be sent)."],
        ["ILL_ALERT_PREFIX", "Start of the subject line of those failure emails. Default ILL ALERT."],
        ["ILL_STATS_TO", "Recipients of the weekly statistics, separated by commas."],
        ["ILL_OFFICE_EMAIL", "Optional copy of every expiry notice to the ILL office. Empty = off."],
        ["ILL_BASE_URL", "The site's address, used for the Yes / No and other links in emails."],
        ["ILL_ILLIAD_SHIP_TEXT", "How items filled through ILLiad ship, shown in the “will be filled” email."],
        ["ILL_AUTOCLOSE_MONTHS", "Shipped ILLiad requests older than this many months are closed as “Assumed complete”. 0 = never. Default 7."],
        ["ILL_ANON_EXPIRE_MONTHS", "anonymize.php: fallback for Cron Jobs → Data retention when WordPress can't be read. Requests still unanswered after this many months are marked Expired. 0 = off. Default 18."],
        ["ILL_ANON_NOTE_DAYS", "anonymize.php: the request note of an expired request is cleared this many days after it expired. 0 = off. Default 30."],
        ["ILL_ANON_MONTHS", "anonymize.php: item details and notes of requests older than this many months are blanked. 0 = off. Default 60 (5 years)."],
        ["ILL_HOLIDAY_AUTO_FETCH", "true = fetch public holidays online (section 5.5). false = use only Closure Dates and Winter Break."],
        ["ILL_HOLIDAY_COUNTRY / ILL_HOLIDAY_REGION", "Country and region codes for the holiday list, e.g. US and US-NY. Region holidays are added to nationwide ones."],
        ["ILL_EMAIL_MODE / ILL_EMAIL_TEST_TO", "'wordpress' (default) follows WordPress's Email test mode. 'off' or 'redirect' forces a mode for the scripts only."],
    ], [2.3, 4.2])

    g.h2("5.4 ill_db.inc")
    g.p("Holds both database logins: the ILL database, and the WordPress database (read-only use, for branding, "
        "Email test mode and Closure Dates). Copy the WordPress values from wp-config.php. Keep the file outside the web root, "
        "owned by the cron user, with `chmod 600`.")
    g.code("""<?php
// ILL database
$dbhost = 'db.example.org';
$dbuser = 'ill_app';
$dbpass = 'the-password';
$dbname = 'ill_data';
$illSTAT          = 'STATS';
$illLIB           = 'Library-Data';
$illILLiadMapping = 'ILLIAD-ADD-MAPPING';
$illROTA          = 'ILL-ROTA';        // must match ILL System -> Database -> Rota table

// WordPress database (DB_HOST, DB_USER, DB_PASSWORD, DB_NAME and $table_prefix from wp-config.php)
$wpDbHost      = 'db.example.org';
$wpDbUser      = 'wp_user';
$wpDbPass      = 'the-wordpress-db-password';
$wpDbName      = 'wordpress';
$wpTablePrefix = 'wp_';

// Encrypted, verified connections (when the database is on another host)
$dbSslCa = '/etc/ssl/mysql/ca-bundle.pem';   // same file as ILL System -> Database -> CA Certificate Path

// Reading encrypted ILLiad API keys: the same value as ILL_DATA_KEY in wp-config.php (section 4.4)
$illDataKey = 'base64-of-32-random-bytes';""")
    g.p("With `$dbSslCa` set, every job connects to both databases with TLS and checks the server's certificate, the same as the "
        "plugin's **Require Encrypted Connection** with a CA path. If the file is missing the jobs refuse to connect (and say so in the "
        "log) rather than connect unencrypted. Leave it out for a database on the same machine. To check, run "
        "`php cron_config.php --check-db` in the scripts folder: it shows whether each connection is encrypted, whether the ILLiad keys "
        "can be read, and whether each table has the columns the scripts use. It changes nothing.")
    g.note("If the WordPress login is missing or wrong, the jobs still run, but they ignore Closure Dates and Email test mode, "
           "and use default branding. The log says which login is missing. On any test server, check this file first: "
           "without it, test-mode settings are not honoured and real email can go out.", "warn")

    g.h2("5.5 Business days and holidays")
    g.p("The reminder (3 days), expiry (5 days) and fallback-timeout jobs count business days. Counting starts the day after "
        "the request, uses local calendar dates, and skips:")
    g.bullets([
        "Saturdays and Sundays.",
        "**Public holidays**, fetched from the free Nager.Date service for your country and region. Each year's list is cached "
        "for 7 days in the scripts folder (`holidays_YYYY.json`), so the folder must be writable by the cron user. "
        "If the fetch fails, the cached copy is used.",
        "**Winter Break**: December 24 to January 1. Always skipped, even with the online fetch turned off.",
        "**Closure Dates** entered under **ILL System → Org Rules → Closure Dates**: staff days, the day after Thanksgiving, "
        "weather closures and so on.",
    ])
    g.p("The Closure Dates screen shows the combined list of upcoming non-business days, so you can check what the scripts will skip.")

    g.h2("5.6 Email from the scripts")
    g.bullets([
        "All script email goes through one function in `cron_config.php`. It applies the same layout, colors, logo and footer as "
        "the plugin's email, read from WordPress branding.",
        "It follows WordPress's **Email test mode**: redirect sends everything to the test address with [TEST] in the subject; "
        "“don't send” writes each held email to the log instead.",
        "Subject lines and the heading, message and closing of each email come from **Email Subjects & Text** in WordPress (section 4.3); blank boxes use the defaults.",
        "Message bodies are sent base64-encoded, so long HTML lines are never broken by mail servers.",
        "The server's mail program (sendmail, or Postfix as a relay) must be able to deliver for your From address.",
    ])
    g.h3("Data retention (anonymize.php)")
    g.p("Keeps patron-related details only as long as they are needed. Three steps, each with a limit set under **ILL System → Cron Jobs → "
        "Data retention** (section 4.9; `cron_config.php` holds fallback values for when WordPress can't be read):")
    g.steps([
        "**Auto-expire:** requests still waiting for an answer after 18 months are marked Expired, with “Expired automatically” "
        "added to the responder note. No emails are sent.",
        "**Clear notes:** the request note of an expired request is cleared 30 days after it expired.",
        "**Anonymize:** requests older than 5 years have the title, author, ISBN / ISSN, item type, call number, article details, "
        "need-by and due dates, patron note and every note blanked. The request itself stays (ILL number, libraries, dates, outcome), "
        "so statistics still count it.",
    ])
    g.note("Step 3 can't be undone. Back up the request table, then run `php anonymize.php --dry-run` once: it only counts what "
           "each step would change and writes nothing. A failed step emails the NOC address and changes nothing.", "warn")

    g.h2("5.7 Logs and failures")
    g.bullets([
        "Each script appends what it did to the log file, one line per action, with a timestamp.",
        "A request that can't be reminded (no lender email, database error) is reported to the NOC address and marked "
        "“REMINDER FAILED …” so it isn't retried every day.",
        "The scripts refuse to run from a web browser; they run only from the command line.",
        "To see job errors as they happen, set **Job output** to append to the log on the Cron Jobs screen, and paste the crontab again.",
    ])
    g.h2("5.8 Updating the scripts")
    g.steps([
        "Back up the folder: `cp -a /var/www/abc_script /var/www/abc_script.bak-$(date +%F)`.",
        "Copy the new `.php` files in. Keep your `cron_config.php` settings: compare the new file with your old one and carry your "
        "values across. Never overwrite `ill_db.inc`.",
        "Run `php -l` on each file, then run one read-only script by hand.",
        "To roll back, restore the backup folder.",
    ])
