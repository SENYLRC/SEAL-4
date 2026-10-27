# Org Rules guide

Org Rules (**ILL → Org Rules** in the WordPress admin) hold everything that differs from one organization to the next: your library systems, couriers, which catalog software each library uses, how a copy in a catalog is matched to a participating library, and which item types each library will lend. They replace the hard-coded lists in the original `cdlc_function.php` / `seal_function.php`.

Every install keeps its **own** rules: nothing is pre-filled for a new site, and there are no presets. Until you fill in **Item types**, item types are not checked at all (every library is offered for every type). To copy a finished set of rules from one site to another (for example from the test site to the live site) use **Move these rules to another site** at the bottom of the page.

## How the request page uses these rules

1. A patron finds a record in the catalog search and clicks Request. The request page receives the record and, for every library that owns a copy, the list of holdings (copies).
2. For each holding the plugin works out which participating library it belongs to: it reads the catalog's location name, looks up the Catalog system (Catalog for each location), and uses "How a holding is matched" to pick the text that identifies the library (the alias).
3. It then looks that alias up in the library records (Manage Libraries → Alias). "Special matching rules" change how that lookup is done for a particular catalog.
4. The library found is checked: is the copy available, does the library participate, does it have an ILL email, is it suspended, does it loan this item type (Item types, Exempt, Special cases), and is it in the patron's own system (if they asked to hide those).
5. Result: offered (patron can pick it), greyed out with the reason (suspended, or does not loan this item type), or hidden (unavailable, not participating, no email, no alias match, own system).
6. Administrators can add ?seal_debug=1 to a request page address to see every holding and why it was offered, greyed out or hidden.

## Working with the page

- Each list is a table. Change a cell, use **Add row** or **Remove**, then **Save Org Rules**. **Sort A-Z** and **Filter** help with long lists. **Edit as text** lets you paste a whole list (one row per line, cells separated by `|`).
- A row missing a required cell is outlined in red and is **not saved** until it is completed.
- **Move rules between sites:** the export box at the bottom holds every list and the crontab settings as one block of text. Copy it, paste it into the Import box on the other site, tick the confirmation and import. Only the lists in the pasted text are replaced, and each change is logged.
- **Change history** at the bottom of the page records every change: who, when, from which IP address, and exactly which rows were added, removed or reordered.

## The lists

### Library Systems

The library systems (consortia, school library systems, councils) your libraries belong to. The Code must match the System value on each library record in Manage Libraries; the Name is what people see.

**Use it when**

- A new system joins: add a row with the same code you will use on its library records.
- A system changes its name: change the Name only and keep the Code.
- This list also feeds the Library System dropdowns (directory, registration, statistics) and the "hide libraries in my own system" preference.

**Columns:** *Code*, *Name*.

**Example: Show a code as a readable name**

| Code | Name |
| --- | --- |
| MVLS | Mohawk Valley Library System |

Libraries whose System is MVLS appear as "Mohawk Valley Library System" in the directory, statistics and the request page.

**Good to know**

- Do not change a Code that libraries already use: they would show the raw code and the "hide my own system" filter would stop matching them.

### Couriers / Ship Methods

The ways an item can travel between libraries. The lender picks one when they answer a request, and the code is saved with the request.

**Use it when**

- A new courier or delivery service starts: add a row.
- A courier stops: remove the row. Requests that already used it keep the saved code and show it as plain text.

**Columns:** *Code*, *Name*.

**Example: A courier only one organization has**

| Code | Name |
| --- | --- |
| crb | Capital Region BOCES Courier |

CDLC lenders can choose "Capital Region BOCES Courier". SENYLRC does not list it, so it never appears there.

### Catalog Type per Location

For each library location that appears in the search results, which catalog system it runs. The request page needs this to read that catalog's holdings correctly.

**Use it when**

- A new library or catalog starts showing up in the search results: add it.
- A library moves to different catalog software: change its Catalog system.

**Columns:** *Location name in the search results*, *Catalog system*.

**Example: A college on Alma**

| Location name in the search results | Catalog system |
| --- | --- |
| Siena College | Alma |

Holdings from the "Siena College" location are read the Alma way.

**Example: A school system on OPALS**

| Location name in the search results | Catalog system |
| --- | --- |
| Questar School Library System | OPALS |

One catalog serving many school libraries; see "How a holding is matched" for how each school is found.

**Good to know**

- Type the location name exactly as the search service reports it (upper/lower case and trailing spaces do not matter; spelling and punctuation do).
- A location missing from this list is treated as a catalog whose holdings name the library directly, which often ends in "No alias match".

### How the Library Alias Is Chosen

Every copy must be tied to one library record. This says which text identifies the library for a catalog system: the catalog's location name, or the copy's own location. That text is called the alias.

**Use it when**

- Use "The catalog location name" when the whole catalog is one library (a college): every copy in it belongs to that library.
- Use "The holding's own location" when one catalog serves many libraries (a school system, a public library consortium): each copy names the library that owns it.
- Use the trimmed form only for catalogs whose locations look like "Albany Public Library-Main" (UHLS style).

**Columns:** *Catalog system*, *Use*.

**Example: One library per catalog**

| Catalog system | Use |
| --- | --- |
| Alma | The catalog location name |

Siena's copies are in "Main Library", "Music Library" and so on. They are all Siena, so the alias is "Siena College".

**Example: Many libraries per catalog**

| Catalog system | Use |
| --- | --- |
| OPALS | The holding's own location |

A copy in "Cohoes High School" uses "Cohoes High School" as the alias.

**Example: UHLS style**

| Catalog system | Use |
| --- | --- |
| InnovativeUHLS | The holding location, trimmed at its last "-" (UHLS style) |

"Albany Public Library-Main" becomes "Albany Public Library"; "Cohoes-Adult" becomes "Cohoes"; a location that is just "UHLS" is left as it is.

**Good to know**

- A catalog system that is not listed uses the copy's own location.

### Alias Matching Rules

After the alias text is chosen, the request page looks for a library whose Alias field (Manage Libraries → Alias) matches it. By default the match must be exact. A rule here changes how that search works for one catalog. You only need a rule when the catalog's wording does not exactly equal the alias in your directory.

**Use it when**

- The catalog adds extra text after the library name ("Amsterdam Free Library - Adult"): turn on Split so only the part before the "-" or ":" is used.
- Several systems share one combined catalog: restrict the search to those systems so a same-named library in another system is never picked.
- Your directory alias is longer or shorter than the catalog's text: use Starts with or Contains instead of Exactly.
- Two systems each have a library with the same name: restrict by system.

**Columns:** *Catalog location name*, *Name match*, *Split*, *Only these systems*.

**Example: Combined catalog for two systems (CDLC)**

| Catalog location name | Name match | Split | Only these systems |
| --- | --- | --- | --- |
| MVLS and SALS combined catalog | Contains | Yes | mvls,sals |

A copy at "Amsterdam Free Library - Adult" is split at the "-", giving "Amsterdam Free Library". The lookup then finds a library in the MVLS or SALS system whose Alias contains that. A copy with no "-" or ":" finds nothing (No alias match).

**Example: One consortium, names that start the same (CDLC)**

| Catalog location name | Name match | Split | Only these systems |
| --- | --- | --- | --- |
| Upper Hudson Library System | Starts with | No | UHLS |

After the UHLS trimming, "Albany Public Library" finds the UHLS library whose Alias begins with it, such as "Albany Public Library - Bach Branch". Libraries outside UHLS are ignored.

**Example: School system with sub-locations (CDLC)**

| Catalog location name | Name match | Split | Only these systems |
| --- | --- | --- | --- |
| WSWHE BOCES School Library System | Starts with | Yes | WSWHE |

"Ballston Spa High School - Reference" becomes "Ballston Spa High School" and matches that school in the WSWHE system.

**Example: Alias is a piece of the catalog wording (SENYLRC)**

| Catalog location name | Name match | Split | Only these systems |
| --- | --- | --- | --- |
| Mid-Hudson Library System | Contains | No | MH |

A copy location is matched against any MH library whose Alias contains that text.

**Good to know**

- The first library the search finds is the one used. A broad rule (Contains "Albany") can pick the wrong library, so make rules as specific as you can and restrict the systems.
- Split needs a "-" or ":" in the text. Without one, nothing matches.
- A catalog not in this list is matched exactly. If a real library comes back as "No alias match", either correct its Alias on the library record or add a rule here.
- After any change, test with a real record (see "Testing a change" in the guide).

### Item Types a Library Loans

Which library setting must be "Yes" before a library can be asked for each item type. The item type comes from the catalog record (book, video, journal and so on). The settings are the loaning options on each library record.

**Use it when**

- The catalog starts sending a new item type: add a row, otherwise it is refused everywhere.
- You want a type to be always allowed regardless of library settings: choose "Always allowed".
- A whole system should never be asked for a type: use "Never for systems".

**Columns:** *Item type*, *Library setting*, *Never for systems*.

**Example: Videos need the audio/video setting**

| Item type | Library setting |
| --- | --- |
| video | Loans audio / video |

A library whose audio/video loaning is "No" is shown greyed out with "Library not loaning this material type".

**Example: "Other" is allowed except for one system (SENYLRC)**

| Item type | Library setting | Never for systems |
| --- | --- | --- |
| other | Always allowed | RC |

Any library can be asked for an "other" item, but never a library in the RC system.

**Good to know**

- While this list is empty, item types are not checked at all. Once it has rows, a type missing from it is refused. A request with no item type at all is always allowed.
- Matching ignores upper/lower case.

### Libraries Exempt from Item-Type Checks

Libraries that are offered every item type, whatever their loan settings say.

**Use it when**

- A library has told you it will consider any request and does not want to maintain the loan settings.

**Columns:** *Library name*.

**Example: A state library**

| Library name |
| --- |
| New York State Library |

It is offered for books, videos, journals and everything else.

**Good to know**

- Use the library's name exactly as it appears in Manage Libraries.

### Special Cases

Two rare exceptions for catalogs that do not behave like the rest. Most organizations need none or one of each. If you are not sure you need one, leave this list alone.

**Use it when**

- Require: one catalog location covers a large institution, but only one part of it takes part in ILL.
- Allow type: one particular holding location must be offered even when the library's loan settings would refuse the item type.

**Columns:** *Rule*, *Catalog name contains / Catalog system*, *Holding location*.

**Example: Require: only one campus participates (made-up names, for illustration)**

| Rule | Catalog name contains / Catalog system | Holding location |
| --- | --- | --- |
| Require | Example University | Downtown Campus |

Suppose the catalog location "Example University" lists copies from every campus, but only the Downtown Campus takes part in ILL. For any catalog location whose name contains "Example University", a copy counts only if its own location contains "Downtown Campus"; a copy at any other campus is treated as unavailable and hidden.

**Example: Allow type: skip the item-type check at one location**

| Rule | Catalog name contains / Catalog system | Holding location |
| --- | --- | --- |
| Allow type | Innovative | ODY Folio |

This is the exception found in the original request page (SEAL-3 and CDLC). In an "Innovative" catalog, copies at the location "ODY Folio" skip the item-type check. The original code does not say why it exists, so check with whoever maintains that catalog before relying on it. Availability, participation, ILL email and suspension are still checked.

**Good to know**

- Require: both texts are matched as "contains", ignoring case. Every copy from a matching catalog is checked.
- Allow type: the holding location must equal the text exactly (case does not matter) and the catalog system must match.
- Both only widen or narrow what is offered; they never change the library records.

### Extra Holdings Lookups

Some catalogs only show a summary in the search results; the copy-by-copy holdings (location, call number, availability) are in the MARC record. This tells the request page where in that record to look. It is for technical staff: wrong codes mean no holdings from that catalog.

**Use it when**

- A catalog's copies do not show up on the request page but exist in the catalog.
- A catalog uses different MARC subfields than the standard ones.

**Columns:** *Match*, *Value*, *Field*, *Location*, *Call no.*, *Availability*, *Exception: catalog*, *Availability there*.

**Example: Koha items (CDLC)**

| Match | Value | Field | Location | Call no. | Availability |
| --- | --- | --- | --- | --- | --- |
| Catalog system | Koha | d952 | sb | so | s7 |

Each field d952 is one copy: location in subfield sb, call number in so, availability in s7 (0 means available).

**Example: A catalog with a different location subfield (SENYLRC)**

| Match | Value | Field | Location | Call no. | Availability | Exception: catalog | Availability there |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Catalog system | Koha | d952 | sa | so | s7 | Ramapo-Catskill Library System | sk |

Location is in sa. For the Ramapo-Catskill catalog only, availability is read from sk instead of s7.

**Example: One library's catalog, shared call number**

| Match | Value | Field | Location | Call no. | Availability |
| --- | --- | --- | --- | --- | --- |
| Location name | Albany Law School | d994 | sb | @d050.sa | - |

Each field d994 is a copy location. The call number is not inside the copy, so @d050.sa reads it from field d050 subfield a. A "-" means there is no availability subfield; copies are treated as available.

**Good to know**

- Change this only with a sample MARC record in front of you, and test with a real request afterwards.

### Scheduled Jobs (cron)

*Since 2026-09-30 this list has its own screen, **ILL → Cron Jobs**, instead of being part of Org Rules. It is still included in Org Rules' Change history and Export/Import.*

The jobs that run automatically on the server (reminders, expiring old requests, ILLiad checks, weekly stats). They are separate scripts started by the server's crontab, not by WordPress. This list is the source for a ready-to-paste crontab: fill in the schedule, the scripts folder and how output is handled, then copy the block underneath and paste it into <crontab -e>. The plugin never runs or changes them.

**Use it when**

- Setting up or moving to a new server: copy the block and paste it into the crontab of the user who owns the script files.
- A job is added, removed or rescheduled: update the row, then copy the new block.
- Someone asks "why did this request change by itself?": check the jobs that update requests (reminder, expire, ILLiad checks).
- You want failures to be visible: switch Job output to "Append to a log file".

**Columns:** *Schedule*, *Script*, *What it does*.

**Example: A job that runs every day at 7:01 AM**

| Schedule | Script | What it does |
| --- | --- | --- |
| 1 7 * * * | 3day_reminder.php | Emails lenders who have not answered after 3 working days. |

The five schedule fields are: minute, hour, day of month, month, day of week. "1 7 * * *" means minute 1 of hour 7 on every day. With Short code cdlc it becomes: 1 7 * * * /usr/bin/php /var/www/cdlc_script/3day_reminder.php >/dev/null 2>&1

**Example: Several times a day**

| Schedule | Script | What it does |
| --- | --- | --- |
| 0 8,12,16,20 * * * | check_illiad.php | Asks ILLiad for the status of requests. |

A comma list in the hour field runs the job at 8, 12, 4 and 8 PM.

**Example: Only on Mondays**

| Schedule | Script | What it does |
| --- | --- | --- |
| 10 7 * * 1 | stats_weekly.php | Emails the weekly statistics summary. |

The last field is the day of the week (0 or 7 = Sunday, 1 = Monday).

**Example: A script you run by hand**

| Schedule | Script | What it does |
| --- | --- | --- |
| manual | fix_one_request.php | A one-off script you keep in the same folder. |

Written into the block as a comment showing the command, and never scheduled.

**Good to know**

- A row with a schedule that is not valid is outlined in red and not saved, so it can never end up in the block.
- The script names are the same for every organization; only the folder is named after the Short code (/var/www/{code}_script, e.g. /var/www/cdlc_script or /var/www/seal_script).
- The scripts read their own settings files in that folder (cron_config.php for addresses and links, ill_db.inc for the database logins), not these Org Rules.
- Choosing "Discard" copies the original behavior (>/dev/null 2>&1): if a job breaks, nothing tells you. "Append to a log file" writes each job's output to the file you name.
- The 3-day reminder, expire, and Rota-timeout jobs count working days using the Closure Dates list below, plus US federal holidays fetched automatically — not a file on the server.
- The block does not check that the PHP path or script files exist; run each command once by hand after installing.

### Closure Dates

Days your libraries are closed that are not already US federal holidays — winter break, a staff development day, a local observance. Weekends are always skipped automatically and do not need to be listed. US federal holidays (Independence Day, Labor Day, Thanksgiving, Christmas, MLK Day, and so on) are fetched automatically and also do not need to be listed here.

**Use it when**

- Your library closes for a stretch that is not a federal holiday, e.g. the week between Christmas and New Year's.
- A one-off closure comes up: a staff day, a weather closure you want reflected going forward, a local holiday.
- A reminder or expiry email went out on a day your libraries were actually closed — check whether that date is missing here.

**Examples**

| Date | Label |
|---|---|
| 2026-12-24 | Winter Break |
| 2026-11-27 | Day after Thanksgiving |

Add one row per closed date, even for a stretch of days — Dec 24 through Dec 31 would be eight separate rows. The Label is shown on this page and in the crontab/log warnings, but has no other effect.

**Good to know**

- This list, plus automatically-fetched US federal holidays, is the complete closure calendar the reminder, expire and Rota-timeout jobs use to count business days.
- A row whose Date is not exactly YYYY-MM-DD is skipped when the jobs run, the same way an incomplete row in any other list here is skipped.
- The count shown below this list on the page is this list only, not the federal holidays added automatically — running low here is normal. The cron jobs log a warning in their own log file if the combined calendar (this list + federal holidays) ever runs short.
- On the server, this list is read directly from the WordPress database by the cron scripts (WordPress login in `ill_db.inc`) — see `cron/scripts/README.md`.

## Alias Matching Rules at a glance

| Name match | The library's Alias field must... | Typical use |
| --- | --- | --- |
| Exactly | equal the catalog text | Default for any catalog with no rule |
| Starts with | begin with the catalog text | Aliases are longer, e.g. a branch name after the library name |
| Contains | contain the catalog text | The catalog text is only a piece of the alias |

| Split | What happens to the catalog text first |
| --- | --- |
| No | Used as it is |
| Yes | Cut at the first `:` or `-`; only the part before it is used. If there is neither, nothing matches |

| Only these systems | Effect |
| --- | --- |
| blank / any | Any library in the directory can match |
| `MVLS,SALS` | Only libraries whose System is one of these can match |

## Testing a change

1. Log in as an administrator and search the catalog for a record you know several libraries own.
2. Click **Request** to open the request page.
3. Add `&seal_debug=1` to the end of the address in the browser and reload. Only administrators see the extra table.
4. The table lists **every** copy found: the catalog location and system, the copy's own location, the alias that was used, the library it matched (or none), and whether it was **Offered**, **Greyed out** or **Hidden**, with the reason.
5. Compare with what you expect. Change a rule, save, reload and look again. Everything you change is written to **Change history**.

## What the messages mean

| You see | What it means | What to check |
| --- | --- | --- |
| Greyed out: *Library not loaning this material type* | The library's loan setting for this item type is No, or the item type is not in the list | **Item types a library loans**, the library's loaning options in Manage Libraries, or add it to **Exempt** |
| Greyed out: *Library not loaning / ... ILL Suspend* | The library is suspended | End the suspension in Manage Libraries (the nightly suspend job also clears it after the end date) |
| Hidden: *No alias match in ... directory* | No library's Alias matches the catalog text | The Alias on the library record; **Catalog for each location**; **How the library alias is chosen**; add or adjust an **Alias Matching Rule** |
| Hidden: *Library not participating* | The library record is not marked as participating | Manage Libraries |
| Hidden: *Library has no ILL email configured* | Nobody would receive the request | Add the ILL email in Manage Libraries |
| Hidden: *Material unavailable* | The copy is checked out or not available (or a **Special Case** "Require" rule excluded it) | The source catalog; the Special Cases list |
| Hidden: *Library a member of your system* | The patron chose to hide libraries in their own system | Profile → Request Preferences |
| Nothing offered at all: *Sorry, no available library* | Every copy was greyed out or hidden | Use the debug view above |
| A catalog's copies are missing completely from the debug table | The catalog needs a full-holdings lookup, or the location is not listed | **Extra Holdings Lookups** and **Catalog for each location** |

## Scheduled jobs (cron)

Edited under **ILL → Cron Jobs** (its own screen since 2026-09-30).

The automatic jobs (reminders, expiring old requests, ILLiad checks, weekly statistics) run on the server from its crontab, outside WordPress. The plugin never runs or changes them. The script file names are the same for every organization (`expire.php`, `3day_reminder.php`, ...); only the scripts folder is named after the organization's **Short code** (`/var/www/{code}_script`, e.g. `/var/www/cdlc_script`, `/var/www/seal_script`). The **Scheduled Jobs** list on the Org Rules page is the source for a **ready-to-paste crontab**: fill in each job's schedule, the scripts folder, the PHP path and how output is handled, then press **Copy to clipboard** under the list and paste it into `crontab -e` on the server (check with `crontab -l`).

**Schedule format:** five fields - minute, hour, day of month, month, day of week - for example `0 20 * * *` is every day at 8 PM and `10 7 * * 1` is Mondays at 7:10 AM. Use `manual` for scripts run by hand; they appear in the block only as comments. A row with an invalid schedule is not saved.

**Job output:** *Discard* (`>/dev/null 2>&1`) is what the original crontab does, so a failing job is silent. *Append to a log file* (`>> /var/log/ill_cron.log 2>&1`, the same file the scripts write their own log lines to) keeps a trace; the user running the jobs must be able to write to that file.

### The CDLC crontab (for reference)

What the page builds for CDLC (Short code `cdlc`) from the Scheduled Jobs list. Until 2026-09-29 the scripts were named `cdlc_*.php` (and `cdlc_illiad_retun.php`); an existing install's list is renamed once automatically — replace the server's crontab with this block when you install the renamed scripts.

```
# Scheduled jobs for Linx (CDLC)
# Install: run "crontab -e" as the user that owns the script files, paste the lines below, save.
# Check:   crontab -l
# Output is discarded (a failing job is silent). Choose "Append to a log file" above to keep it.

# Emails lenders who have not answered after 3 working days and copies the requester. Marks the request Reminder Sent.
1 7 * * * /usr/bin/php /var/www/cdlc_script/3day_reminder.php >/dev/null 2>&1

# Expires requests still unanswered after 5 working days: emails both sides and sets the status to Expired.
6 7 * * * /usr/bin/php /var/www/cdlc_script/expire.php >/dev/null 2>&1

# Ends a library's ILL suspension once its end date has passed.
5 7 * * * /usr/bin/php /var/www/cdlc_script/check_suspend.php >/dev/null 2>&1

# Emails requesters 5 days before the due date of items that were received and not yet returned.
5 7 * * * /usr/bin/php /var/www/cdlc_script/due_date.php >/dev/null 2>&1

# Emails the weekly statistics summary (Mondays).
10 7 * * 1 /usr/bin/php /var/www/cdlc_script/stats_weekly.php >/dev/null 2>&1

# Asks ILLiad for the status of requests sent to ILLiad libraries and updates status, due date and ship method.
0 8,12,16,20 * * * /usr/bin/php /var/www/cdlc_script/check_illiad.php >/dev/null 2>&1

# Checks ILLiad for items that were returned and marks them returned and checked in.
0 20 * * * /usr/bin/php /var/www/cdlc_script/illiad_return.php >/dev/null 2>&1

# Copies due dates changed in ILLiad back to the request; closes requests ILLiad reports as not filled.
0 20 * * * /usr/bin/php /var/www/cdlc_script/check_illiad_duedate.php >/dev/null 2>&1
```

Notes:

- The scripts read their own settings files in the scripts folder (`cron_config.php` for addresses and links, `ill_db.inc` for the database logins), not these Org Rules.
- The 3-day reminder, expire, and Rota-timeout jobs count working days using the Closure Dates list above (plus auto-fetched US federal holidays) — there is no file on the server to update each year.
- The reminder emails link to `/respond?num=N&a=1` (yes) or `a=0` (no); the request response page accepts those links.
- The block does not check that the PHP path or script files exist. Run each command once by hand after installing.
