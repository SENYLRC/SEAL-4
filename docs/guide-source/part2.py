def build(g):
    g.h1("4. Configuration reference")
    g.p("All settings are in the WordPress dashboard under the **ILL System** menu (labelled with your System Short Name "
        "once it is set). Only Administrators see this menu. Each screen has its own **Save** button; settings take effect "
        "on the next page load.")

    # 4.1
    g.h2("4.1 Dashboard")
    g.p("A summary page: the active identity (system and organization names, domain, web address, support addresses), "
        "a tile for each settings screen, links to the ILL administration pages on the site, and the ILL database connection status. "
        "Check the connection status first after any change on the Database screen.")

    # 4.2
    g.h2("4.2 Branding")
    g.p("Names, logo, colors and the site footer. Every page title, email subject, email footer and menu label uses these, "
        "so set them first.")
    g.table(["Setting", "What it does"], [
        ["System Short Name", "Short name of the service, used in menus, page titles and email subjects (e.g. ILL)."],
        ["System Full Name", "The service's full name, shown where there is room for it."],
        ["Org Short Name / Org Full Name", ["The organization that runs the service.",
                                            "Emails end with “Sent by {System Short Name}, a service hosted by {Org Full Name}”."]],
        ["Org Domain", "Your domain without https:// (e.g. example.org)."],
        ["System Base URL", "The site's full address with https://. Used for links in emails."],
        ["Header Logo URL / Logo Alt Text", "Logo at the top of pages and emails. Blank = the System Short Name as text. Always give alt text."],
        ["Favicon URL", "Browser-tab icon (square PNG or ICO). A Site Icon set in Appearance → Customize takes priority."],
        ["Email Footer Text", "An optional line added to the bottom of every email."],
        ["Link & Button Color / Dark Color / Accent Color",
         ["Colors in #rrggbb form.", "The first two must have at least 4.5:1 contrast with white text (WCAG 2.1 AA); "
          "a color that fails is rejected with a message. The accent is used behind dark text."]],
        ["Sidebar Quick Links", "Extra links in the ILL sidebar, one per line as Label|URL. Off-site links open in a new tab."],
        ["Site Footer (logo, about line, address, phone, website, links, copyright)",
         ["The organization footer on every page. Turn it on with the Site Footer module (section 4.7).",
          "In Footer Links, `{login}` stands for the login page; in the copyright line `{year}` and `{system}` are filled in."]],
    ], [2.0, 4.5])

    # 4.3
    g.h2("4.3 Contacts & Email")
    g.table(["Setting", "What it does"], [
        ["Admin / Alert Email(s)", "Who receives system alerts (failed database saves, ILLiad errors, file-monitor findings). Separate several with commas."],
        ["Support CC Email(s)", "Copied on every system alert."],
        ["Not-Filled CC(s)", "Copied when a lender answers that it cannot fill a request."],
        ["From: Address", "The sender address on all email. Your mail relay must be allowed to send for it (SPF / DKIM)."],
        ["From: Display Name", "The sender name. Blank = the System Short Name."],
        ["Email test mode", ["**Send normally**: live operation.",
                             "**Send all email to the test address**: every email goes to the test address instead, with [TEST] in the subject "
                             "and the real recipients listed at the top.",
                             "**Don't send**: emails are logged but not sent.",
                             "System alerts to the admin addresses are still sent in every mode."]],
        ["Test address", "Where email goes in the second mode. Several addresses: separate with commas."],
    ], [1.8, 4.7])
    g.note("While Email test mode is on, every dashboard screen shows a warning and administrators see a bar across the top of "
           "the site, so it can't be left on by accident. The scheduled scripts follow the same setting (section 5.6). "
           "The page also lists the last 100 emails that were held or redirected.", "tip")

    g.h3("Email Subjects & Text")
    g.p("**ILL System → Email Text** lists every email people receive, grouped as Requests, Renewals, Automatic Fallback, Accounts "
        "and Scheduled jobs. Open one to change its **subject**, and for the request, renewal, fallback and scheduled-job emails also its "
        "**heading**, opening **message** and **closing** line. The details table (ILL number, title, libraries, dates) and the "
        "buttons are always added by the system between the message and the closing. Account emails (welcome, password reset, "
        "approval) have an editable subject only.")
    g.bullets([
        "A blank box uses the default, which is shown greyed out in it. **Copy the default text into the boxes** fills them so you can "
        "edit the wording instead of retyping it; text saved unchanged still counts as the default.",
        "Enter a single `-` to leave a message or closing out.",
        "In the message and closing, `**text**` is bold, `*text*` is italic, and a blank line starts a new paragraph. Anything else is "
        "shown exactly as typed (HTML is not allowed).",
        "An email that has been changed shows a **changed** label and opens automatically.",
        "**Change history** under the form lists every saved change: who, when, from which address, and the wording before and after "
        "(also on Change History, section 4.12).",
        "Some emails share a subject: the 3-day reminder to the lender and its copy to the borrower; the two ILLiad “filled” emails; "
        "and the Automatic Fallback emails after a “no” and after no answer.",
    ])
    g.p("Words in braces are filled in when the email is sent; each email lists the ones it can use:")
    g.table(["Token", "Becomes"], [
        ["{system}", "The System Short Name (every email)."],
        ["{ill}", "The ILL number."],
        ["{title}", "The item title."],
        ["{name} / {username}", "The person's name / username (account emails)."],
        ["{library} / {borrower_library}", "The borrowing library."],
        ["{lender_library}", "The lending library."],
        ["{start} / {end}", "The first and last day of the week (weekly statistics)."],
    ], [1.8, 4.7])
    g.bullets([
        "Emails sent from the site get the System Short Name in brackets in front, e.g. [ILL], unless the subject already contains it. "
        "Scheduled-job subjects are used exactly as typed.",
        "The scheduled scripts read these settings (subjects and text) from the WordPress database, so a change applies to them on their next run. "
        "If they can't reach the WordPress database they use the defaults.",
        "Internal alerts to the administrators (database failures, a library with no email address) keep fixed subjects.",
    ])

    # 4.4
    g.h2("4.4 Database")
    g.table(["Setting", "What it does"], [
        ["DB Host, Username, Name, Password", "The ILL database login (section 3.4). The password is stored encrypted."],
        ["Stats / History Table", "The request table. Default: STATS."],
        ["Library Data Table", "The library directory. Default: Library-Data."],
        ["ILLiad Mapping Table", "ILLiad address mapping. Default: ILLIAD-ADD-MAPPING."],
        ["Rota (Fallback) Table", "Automatic Fallback tracking. Default: ILL-ROTA. Created automatically."],
        ["Rota: Max Libraries in Chain", "How many libraries one fallback chain may include, counting the first choice. Default and maximum: 3."],
        ["Rota: Keep Finished History (Days)", "How long fallback tracking rows are kept after a request finishes. Default: 180. The requests themselves are never deleted."],
        ["Z39.50 Gateway URL", "Base address of the catalog record service used by the request page."],
        ["MySQL TLS / SSL", "Require an encrypted connection, with CA, client certificate and key paths for verified or mutual TLS."],
    ], [2.2, 4.3])
    g.h3("ILLiad API keys")
    g.p("Below the form, this screen shows whether ILLiad libraries' API keys are encrypted: whether the encryption key is set, how many keys "
        "are stored, encrypted or still in plain text, and whether the database column is wide enough. **Encrypt plain-text keys** encrypts "
        "the rest in one step (recorded in Change History).")
    g.steps([
        "On the server, make a key: `php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'`",
        "Add it to `wp-config.php` (above “That's all, stop editing”): `define( 'ILL_DATA_KEY', '…' );` and the same value to the scripts' "
        "`ill_db.inc`: `$illDataKey = '…';` (section 5.4).",
        "If the screen says the column is too narrow, widen it once in the database (the screen shows the `ALTER TABLE` statement).",
        "Select **Encrypt plain-text keys**. Then run `php cron_config.php --check-db` in the scripts folder: it should report every key as readable.",
    ])
    g.note("Keep a copy of the key somewhere safe and separate from the database backups. Without it the stored ILLiad keys can't be read, "
           "and each ILLiad library would have to send its key again. With it, a database login, export or backup shows only scrambled keys.", "warn")
    g.h3("Database check")
    g.p("**Run database check**, at the bottom of this screen, compares the ILL database with what this version of the plugin needs. It only "
        "reads (the table structure, and a few counts in the library table). Each line is marked OK, Note, Warning or Problem, and most come "
        "with the SQL that fixes them, to run yourself after a backup. Run it after setting up a site, after moving the database to another "
        "server, and after each plugin update.")
    g.table(["It checks", "Why it matters"], [
        ["Connection and encryption", "Whether the site can reach the database, and whether the traffic is encrypted (TLS)."],
        ["Strict SQL mode", "With strict mode (MySQL's default) the database refuses a row that leaves out a required value or holds one "
         "that doesn't fit, instead of guessing. The other findings say which columns matter."],
        ["Each table exists", "A missing table is a Problem; **Create the missing tables** creates it, empty. Existing tables are never changed. "
         "The fallback table is only needed when Automatic Fallback is on."],
        ["Required and optional columns", "A missing required column breaks the pages or jobs that use it (Problem, with ALTER TABLE … ADD COLUMN). "
         "Missing optional columns only hide a feature (Note)."],
        ["Columns that need a default", "Request-table columns that are NOT NULL with no default and that a new request doesn't fill. "
         "With strict SQL mode every new request fails to save; without it, MySQL fills in a blank."],
        ["Sizes", "The ILLiad API key column must hold an encrypted key; the request table's library-system columns must hold the "
         "longest system code; an ILL email exactly as long as its column was probably cut off."],
        ["Index on the ILL number", "Without it the request table is read in full for nearly every page."],
        ["Character set and engine", "Tables in latin1 lose characters outside Western European languages; utf8mb4 keeps everything. "
         "MyISAM tables aren't crash-safe; InnoDB is the MySQL default."],
        ["Library data", "LOC codes used by more than one library, libraries without a LOC code, participating libraries with no ILL email, "
         "and ILLiad mappings for a LOC code that isn't in the library table."],
    ], [2.0, 4.5])
    g.note("Character-set and engine changes rewrite the whole table. Back it up first and run them at a quiet time.", "warn")

    # 4.5
    g.h2("4.5 Search & Catalog")
    g.p("Connects the site to your union catalog search. Put the shortcode `[ill_search]` on the home page (or any page) "
        "to show the search box; the optional Catalog Search Header module adds a search bar to every page.")
    g.table(["Setting", "What it does"], [
        ["Catalog Base URL", "Your catalog search host."],
        ["Search Results Page", "Full address of the page that shows search results."],
        ["Advanced Search URL / Help URL", "Optional links under the search box. Blank hides them."],
        ["Catalog Login URL", "Used by the catalog search toolkit to start a session."],
        ["Categories JS URL", "Your vendor's categories script, if they provide one. Blank turns it off."],
        ["Category List", "The search dropdown, one value|Label per line. Start a line with * to make it the default."],
    ], [2.0, 4.5])

    # 4.6
    g.h2("4.6 Users & Registration")
    g.table(["Setting", "What it does"], [
        ["Home System Code", "The library-system code that stands for your own organization in the system dropdown on profiles."],
        ["Staff Login Redirect", "Where staff land after logging in when they weren't going anywhere in particular. Default: /illuser/ (My Profile)."],
        ["Terms of Service", "Add a required checkbox, with a link to your terms page, to the registration form."],
        ["Registration Invite Code", "A shared code new users must enter to register, which keeps the public out. Not case-sensitive. Change it whenever you like; existing accounts are unaffected."],
        ["Require Approval for New Users", "New registrations wait as “pending” until an administrator approves them. Admins get an email with Approve / Deny links."],
        ["Password Link Valid For", ["How long the link in the welcome (set your password) email and in password reset emails keeps working: 1, 2, 3, 5, 7, 10, 14 or 30 days. "
                                     "Default **7 days** (WordPress on its own allows 1 day). The emails say how long the link lasts.",
                                     "Use a long time while loading a batch of accounts (for example on a Friday, so people can set their password during the next week), "
                                     "then shorten it to suit your users. Each link still works only once.",
                                     "The age is checked when a link is used, so a change also affects links already sent: shortening it makes older unused links stop working "
                                     "(people use **Lost your password?** to get a new one)."]],
        ["Set-up Reminders / Send Reminders At", ["On by default. People who were sent a welcome email but haven't set a password get a reminder "
                                                  "on the day **3 days** before their link expires and again on the day **1 day** before, at the hour chosen "
                                                  "(default **9:00 am**, site time zone). A missed day is caught up the next day.",
                                                  "Each reminder has a fresh **Set your password** button that keeps the original deadline (it replaces the earlier "
                                                  "link). Reminders stop as soon as the person sets a password; none go out for registrations waiting for approval, "
                                                  "or within 12 hours of the welcome email.",
                                                  "The wording is on **Email Text → Accounts → Set-up reminder**. Each reminder sent is recorded in Change History "
                                                  "(“scheduled job”). On User Accounts, the Password column shows **Not set up yet** or **Link expired** for these people."]],
    ], [2.0, 4.5])
    g.note("WordPress runs its scheduled jobs when someone visits the site. For reminders right at the chosen hour on a quiet site, have the "
           "server run WordPress's scheduler every few minutes, e.g. `*/5 * * * * php /path/to/wordpress/wp-cron.php > /dev/null 2>&1` "
           "in the web server user's crontab. This also keeps the other daily jobs (file monitor, Automatic Fallback clean-up) on time.", "tip")

    # 4.7
    g.h2("4.7 Modules")
    g.p("Optional features. Turning a module off removes its effect completely.")
    g.table(["Module", "What it adds"], [
        ["User Profile Fields", "Institution, LOC code, phone and alternate email on user profiles and the registration form."],
        ["Staff Login Redirect", "Sends staff to the redirect page after login (section 4.6)."],
        ["Terms of Service", "The terms checkbox on registration."],
        ["Invite Code", "The registration invite code."],
        ["Dashboard Tweak", "A Quick Links widget and admin-bar menu for staff."],
        ["Export Users", "A CSV download of users under the Users menu."],
        ["Catalog Search Header", "The catalog search bar and logo at the top of every page."],
        ["Last Login Tracking", "Records and shows when each user last logged in."],
        ["Role Editor", "Change a user's ILL role from their profile; bulk-assign roles from the Users list."],
        ["User Approval", "Holds new registrations for approval (section 4.6)."],
        ["Site Footer", "The organization footer from Branding."],
        ["Automatic Fallback (Rota)", ["Lets a borrower name up to two backup libraries. If the lender says No, or never answers, "
                                       "the request moves to the next library automatically.",
                                       "Off by default. The borrower opts in per request."]],
    ], [2.0, 4.5])

    # 4.8
    g.h2("4.8 Org Rules")
    g.p("Org Rules hold everything that differs between organizations: library systems, couriers, which catalog software each "
        "library uses, how a copy in the catalog is matched to a participating library, and which item types each library lends. "
        "Nothing is pre-filled on a new site.")
    g.h3("How the request page uses the rules")
    g.steps([
        "A borrower clicks Request on a catalog record. The request page receives the record and, for each library that owns a copy, its holdings.",
        "For each holding it looks up the catalog's software (**Catalog Type per Location**) and uses **How the Library Alias Is Chosen** "
        "to pick the text that identifies the library (its alias).",
        "It finds the library whose Alias matches (Manage All Libraries → Alias). **Alias Matching Rules** change how that comparison is made for a given catalog.",
        "It checks the library: is the copy available, does the library participate and have an ILL email, is it suspended, "
        "does it lend this item type, and is it in the borrower's own system (if they chose to hide those)?",
        "Each library is then offered, shown greyed out with the reason (suspended, doesn't lend this type), or hidden.",
    ])
    g.note("Administrators can add `?ill_debug=1` to the request page address to see every holding and why it was offered, greyed out or hidden.", "tip")
    g.h3("The lists")
    g.table(["List", "Columns", "Use"], [
        ["Library Systems", "Code | Name", "Your library systems. The code must match the System on each library record. Feeds dropdowns, the directory and statistics."],
        ["Couriers / Ship Methods", "Code | Name", "How items travel. The lender picks one when answering a request."],
        ["Catalog Type per Location", "Catalog location name | Catalog type", "Which catalog software (Koha, Alma, Polaris, OPALS, ...) each catalog location runs."],
        ["How the Library Alias Is Chosen", "Catalog type | name / local / trimmed", "Which text identifies the library: the catalog's location name, the holding's own location, or the location trimmed at its last “-”."],
        ["Alias Matching Rules", "Catalog location | exact / prefix / contains | split yes/no | systems", "How strictly the alias is compared, for catalogs whose location text doesn't match the library alias exactly."],
        ["Item Types a Library Loans", "Item type | library column | deny:SYS,...", "Ties an item type to a Yes/No column on the library record (book_loan, av_loan, ...). Until this list is filled in, item types are not checked."],
        ["Libraries Exempt from Item-Type Checks", "Library name", "Libraries offered for every item type."],
        ["Special Cases", "require | allow-type rules", "Exceptions for particular catalogs or holding locations."],
        ["Extra Holdings Lookups", "Catalog | MARC field | location | call no. | availability",
         ["For catalogs whose full holdings are only in the MARC record. Match a catalog system (type) or one catalog location (name); "
          "the first matching row wins, so put a name row above a type row.",
          "Codes as the search service names them: field `d909`, subfields `sa`, `sh`… Typing `909` or a single letter such as `a` also works. "
          "Availability `-` (or blank) means the record has no status, so every copy counts as available.",
          "Example: holdings in 909 with the location in $a and the call number in $h → `d909 | sa | sh | -`."]],
        ["Closure Dates", "Date | Label", "Days your libraries are closed. They don't count as business days for reminders, expiry and fallback timeouts (section 5.5)."],
    ], [1.6, 1.8, 3.1])
    g.h3("Working with the page")
    g.bullets([
        "Each list is an editable table: change a cell, **Add row**, **Remove**, then **Save Org Rules**. **Sort A–Z** and **Filter** help with long lists, "
        "and **Edit as text** accepts a whole list pasted as one row per line with cells separated by |.",
        "A row missing a required cell is outlined in red and is not saved until it is completed.",
        "**Move rules between sites**: the export box holds every list as one block of text. Paste it into the Import box on another "
        "site (for example from test to live), confirm and import.",
        "**Change history** records every change: who, when, from which address, and which rows were added, removed or reordered.",
        "Each list has a help panel on the page with worked examples.",
    ])

    # 4.9
    g.h2("4.9 Cron Jobs")
    g.p("Builds the crontab for the scheduled scripts (section 5). This screen is reference only: the plugin does not run the jobs.")
    g.table(["Setting", "What it does"], [
        ["Scheduled Jobs", "One row per job: schedule (cron format), script file and a description. Pre-filled with the recommended schedule."],
        ["Short code", "Names the scripts folder: `{code}` in the folder path becomes this, e.g. /var/www/{code}_script → /var/www/abc_script. Blank = the Org Short Name."],
        ["Scripts folder", "Where the script files live on the server."],
        ["PHP program", "Full path to the PHP command-line program (run `which php` on the server)."],
        ["Job output", "Discard output, or append it to the log file."],
        ["Log file", "Where output goes when appending. The user that runs cron must be able to write to it."],
        ["Copy and paste", "The finished crontab block, ready to paste into `crontab -e`."],
        ["Data retention (anonymize.php)", ["How long request details are kept (section 5): expire unanswered requests after **18** months, clear the "
                                            "request note of expired requests after **30** days, anonymize requests older than **60** months. "
                                            "0 turns a step off. The job reads these every run; changes are recorded in Change History."]],
    ], [1.6, 4.9])

    # 4.10
    g.h2("4.10 Security")
    g.p("One screen with a tab per feature. Everything is off until you turn it on.")
    g.table(["Tab", "What it does"], [
        ["Login Screen", "Branded login, registration and lost-password pages: logo (with alt text), background image, split layout, welcome message, button color."],
        ["Login URL", "Serves the login form at an address you choose. /wp-login.php and /wp-admin return “not found” to logged-out visitors. This cuts bot traffic; it doesn't replace the other protections."],
        ["Login Protection", ["**Login lockout**: lock an address out after too many failed logins within a time window (temporary or permanent).",
                              "**404 detection**: lock out addresses that hit too many missing pages, as vulnerability scanners do.",
                              "**IP lists**: an allow list that is never locked out, and a block list.",
                              "**Real-IP header**: set this (usually X-Forwarded-For) if the site is behind a CDN or load balancer, so lockouts apply to the visitor "
                              "and not the proxy. The plugin uses the **last** public address in the header, the one the CDN itself added, so addresses a "
                              "visitor puts in front are ignored. Only set it if the proxy is the only way to reach the server (section 8.1). If the web server "
                              "already replaces the address (Apache mod_remoteip with a trusted-proxy list), leave it at None. The top of this tab shows "
                              "\u201cYour address as this site sees it\u201d: it should be your real public address, not the CDN's.",
                              "**Disable XML-RPC**: recommended, because XML-RPC bypasses two-factor."]],
        ["Passwords", ["**Pwned passwords**: block passwords found in public breach lists at login and email a reset link. "
                       "Only a 5-character prefix of a one-way hash leaves the server.",
                       "**Strong passwords**: minimum length and character mix when a password is set.",
                       "**Force password reset**: make every user in the chosen roles set a new password at their next login."]],
        ["Two-Factor", ["A second sign-in step after the password, set up by each person on My Profile (section 6.1). Methods, in any mix: "
                         "a **security key** such as a YubiKey (touch only, no key PIN), a **passkey** on their computer or phone "
                         "(Windows Hello, Touch ID, Android), or 6-digit codes from an **authenticator app**. 10 one-time backup codes come with the first method.",
                         "It can be required for chosen roles; those users are sent to My Profile until they add a method.",
                         "Every change emails the person and signs out their other sessions. Failed attempts count toward Login Protection's lockout."]],
        ["File Monitor", "Fingerprints WordPress core, plugin and theme code files on a schedule and reports anything added, changed or removed. Core files are also checked against the official release. Findings go to the alert addresses."],
        ["Activity", "Permanent bans, recent lockouts, and a form to unlock an address."],
    ], [1.4, 5.1])
    g.note("Security keys and passkeys are tied to the site's address (its host name). A key registered on a test site, or before the site "
           "moves to a new address, does not work on the new one: people add their keys again there. Backup codes and authenticator apps keep working.")
    g.note("If you lock yourself out, add one of these lines to wp-config.php, log in, fix the setting, then remove the line. "
           "`define( 'ILL_DISABLE_LOGIN_URL', true );` turns off only the custom login address. "
           "`define( 'ILL_DISABLE_SECURITY', true );` turns off every security module.", "warn")

    # 4.11
    g.h2("4.11 Routes")
    g.p("A read-only list of every ILL page address, the role needed to open it, and whether permalinks are set correctly. "
        "Use it to check access when a user reports “you are not allowed to view this page”.")

    # 4.12
    g.h2("4.12 Change History")
    g.p("**ILL System → Change History** keeps an audit trail of changes made by administrators: who made each change, when, from which "
        "IP address, and the value before and after. Filter it by area, and search by account (username) or item. Entries are kept for "
        "**two years** and then deleted automatically by a daily job (the page says so); at most the newest 1,000 entries in each area are kept, "
        "so a burst of account entries can't push out security or email entries; "
        "Org Rules has its own history (section 4.8).")
    g.table(["Area", "What is recorded"], [
        ["Email text", "Every saved change to an email subject, heading, message or closing (section 4.3)."],
        ["User accounts", ["Every change to an account, on the WordPress Users screens or the ILL pages: registered or created, "
                           "**approved or denied**, deleted, role, email, name, library, LOC codes, home system, phone, two-factor set up, "
                           "removed or reset, password changed, password reset email sent by an administrator, password reset with the emailed link.",
                           "Changes people make to their own account are included and marked “own account”; registering and resetting a "
                           "password by link show “not signed in”.",
                           "Requests for a reset email made on the sign-in page are not recorded: anyone can make them for any username.",
                           "Each account's history is also one click away: **History** beside it on User Accounts and Manage Accounts "
                           "(only for accounts you can manage)."]],
        ["Security settings", ["Every setting on the Security tabs, Modules and Users & Registration (module on / off, lockout limits, "
                               "two-factor roles, invite code and so on).",
                               "The Security screen actions: unlocking an IP address, forcing password changes, accepting file changes."]],
    ], [1.6, 4.9])
    g.note("Passwords are never recorded: a password change shows only as “Password changed”, and the invite code only as “changed”. "
           "One Save is one entry per account or screen; open it to see each field before and after. The history holds IP addresses and "
           "earlier profile values, so treat it as personal data (Administrators only); the two-year limit is its retention period.", "tip")

    # 4.13
    g.h2("4.13 Import Users")
    g.p("**ILL System → Import Users** creates many accounts at once from a spreadsheet saved as CSV, for example when moving staff "
        "from an older ILL site. Nothing is created until you have seen a preview.")
    g.steps([
        "Save the spreadsheet as CSV. The first row must be the column headers; columns can be in any order and other columns are ignored. "
        "Recognised headers: **Username**, **Email** (required), **First Name**, **Last Name**, **Institution** (or Your Institution), "
        "**LOC Location Code**, **OCLC Symbol**, **Created**. “(Optional)” in a header is ignored.",
        "Upload it. The preview lists every row as **Create**, **Skip** (that email already has an account), **Check LOC** (the LOC code "
        "isn't in the library directory) or **Error** (no valid email, or the same email twice), with notes. Row numbers match the spreadsheet. "
        "If the library directory (ILL database) can't be reached, the file is refused: LOC codes must be checkable.",
        "Choose the role (Library Staff or Library System Staff), what to do with **Check LOC** rows (default: don't import them; or import "
        "them without a library), and whether to email everyone a **Set your password** link now, then select **Create accounts**. Large "
        "files are processed in batches; keep the page open until it says the import is finished.",
        "LOC codes are checked again as each account is created. Rows left out are listed at the end: fix the code in the spreadsheet (or add "
        "the library) and import the same file again; accounts already created are skipped.",
    ])
    g.table(["Column", "What happens"], [
        ["Username", "Kept, so people can sign in with their old username (or their email address, which always works). If it is taken or "
                     "contains characters WordPress does not allow, the email address becomes the username; the preview says so."],
        ["LOC Location Code", "Validated against the library directory, at the preview and again at import: the library's system becomes the "
                              "person's **Home system**, and its name is used when the Institution column is empty. Rows with a code that isn't in the "
                              "directory are not imported unless you choose to import them without a library (then set it later on User Accounts). "
                              "A row with no LOC code is imported without a library."],
        ["Created", "Becomes the account's creation date, so “Reg:” shows when the person first joined. Formats such as "
                    "“Fri, 09/09/2022 - 10:25”, 09/09/2022 or 2022-09-09 are understood."],
        ["Last access, Last login", "Ignored (they belong to the old site)."],
    ], [1.8, 4.7])
    g.note("Imported accounts are approved straight away and get a random password; people set their own with the emailed link "
           "(valid for the time set under Users & Registration, 7 days by default) or with **Lost your password?**; anyone who hasn't set one "
           "gets a reminder 3 days and 1 day before the link expires (section 4.6). The welcome emails "
           "are sent even while Email test mode is on. Every account created is recorded in Change History. The uploaded file is not "
           "kept on the server.", "tip")

