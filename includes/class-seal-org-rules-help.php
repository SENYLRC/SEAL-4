<?php
/**
 * SEAL_Org_Rules_Help — plain-language help for every list on ILL → Org Rules.
 *
 * Each entry: what (one paragraph), when (bullets), examples (title, cells in editor-column
 * order, explanation) and notes (cautions). Rendered under each editor and reused for the
 * written guide, so the two never drift apart.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class SEAL_Org_Rules_Help {

    /** The request flow, shown at the top of the page and in the guide. */
    public static function flow(): array {
        return [
            'A patron finds a record in the catalog search and clicks Request. The request page receives the record and, for every library that owns a copy, the list of holdings (copies).',
            'For each holding the plugin works out which participating library it belongs to: it reads the catalog\'s location name, looks up the Catalog system (Catalog for each location), and uses "How a holding is matched" to pick the text that identifies the library (the alias).',
            'It then looks that alias up in the library records (Manage Libraries → Alias). "Special matching rules" change how that lookup is done for a particular catalog.',
            'The library found is checked: is the copy available, does the library participate, does it have an ILL email, is it suspended, does it loan this item type (Item types, Exempt, Special cases), and is it in the patron\'s own system (if they asked to hide those).',
            'Result: offered (patron can pick it), greyed out with the reason (suspended, or does not loan this item type), or hidden (unavailable, not participating, no email, no alias match, own system).',
            'Administrators can add ?ill_debug=1 to a request page address to see every holding and why it was offered, greyed out or hidden.',
        ];
    }

    /**
     * Help for one Org Rules list: ['what' => intro, 'when' => when to use it, 'examples' => worked examples,
     * 'notes' => good to know]. Empty parts for an unknown key.
     */
    public static function for_key( string $key ): array {
        $all = self::all();
        return $all[ $key ] ?? [ 'what' => '', 'when' => [], 'examples' => [], 'notes' => [] ];
    }

    /**
     * The help text for every Org Rules list, keyed like SEAL_Org_Rules::KEYS. Shown in the help panel next to
     * each list.
     */
    private static function all(): array {
        return [
            'systems' => [
                'what' => 'The library systems (consortia, school library systems, councils) your libraries belong to. The Code must match the System value on each library record in Manage Libraries; the Name is what people see.',
                'when' => [
                    'A new system joins: add a row with the same code you will use on its library records.',
                    'A system changes its name: change the Name only and keep the Code.',
                    'This list also feeds the Library System dropdowns (directory, registration, statistics) and the "hide libraries in my own system" preference.',
                ],
                'examples' => [
                    [ 'title' => 'Show a code as a readable name', 'cells' => [ 'MVLS', 'Mohawk Valley Library System' ], 'text' => 'Libraries whose System is MVLS appear as "Mohawk Valley Library System" in the directory, statistics and the request page.' ],
                ],
                'notes' => [ 'Do not change a Code that libraries already use: they would show the raw code and the "hide my own system" filter would stop matching them.' ],
            ],
            'couriers' => [
                'what' => 'The ways an item can travel between libraries. The lender picks one when they answer a request, and the code is saved with the request.',
                'when' => [
                    'A new courier or delivery service starts: add a row.',
                    'A courier stops: remove the row. Requests that already used it keep the saved code and show it as plain text.',
                ],
                'examples' => [
                    [ 'title' => 'A courier only one organization has', 'cells' => [ 'crb', 'Capital Region BOCES Courier' ], 'text' => 'CDLC lenders can choose "Capital Region BOCES Courier". SENYLRC does not list it, so it never appears there.' ],
                ],
                'notes' => [],
            ],
            'catalogs' => [
                'what' => 'For each library location that appears in the search results, which catalog system it runs. The request page needs this to read that catalog\'s holdings correctly.',
                'when' => [
                    'A new library or catalog starts showing up in the search results: add it.',
                    'A library moves to different catalog software: change its Catalog system.',
                ],
                'examples' => [
                    [ 'title' => 'A college on Alma', 'cells' => [ 'Siena College', 'Alma' ], 'text' => 'Holdings from the "Siena College" location are read the Alma way.' ],
                    [ 'title' => 'A school system on OPALS', 'cells' => [ 'Questar School Library System', 'OPALS' ], 'text' => 'One catalog serving many school libraries; see "How a holding is matched" for how each school is found.' ],
                ],
                'notes' => [
                    'Type the location name exactly as the search service reports it (upper/lower case and trailing spaces do not matter; spelling and punctuation do).',
                    'A location missing from this list is treated as a catalog whose holdings name the library directly, which often ends in "No alias match".',
                ],
            ],
            'behaviour' => [
                'what' => 'Every copy must be tied to one library record. This says which text identifies the library for a catalog system: the catalog\'s location name, or the copy\'s own location. That text is called the alias.',
                'when' => [
                    'Use "The catalog location name" when the whole catalog is one library (a college): every copy in it belongs to that library.',
                    'Use "The holding\'s own location" when one catalog serves many libraries (a school system, a public library consortium): each copy names the library that owns it.',
                    'Use the trimmed form only for catalogs whose locations look like "Albany Public Library-Main" (UHLS style).',
                ],
                'examples' => [
                    [ 'title' => 'One library per catalog', 'cells' => [ 'Alma', 'The catalog location name' ], 'text' => 'Siena\'s copies are in "Main Library", "Music Library" and so on. They are all Siena, so the alias is "Siena College".' ],
                    [ 'title' => 'Many libraries per catalog', 'cells' => [ 'OPALS', 'The holding\'s own location' ], 'text' => 'A copy in "Cohoes High School" uses "Cohoes High School" as the alias.' ],
                    [ 'title' => 'UHLS style', 'cells' => [ 'InnovativeUHLS', 'The holding location, trimmed at its last "-" (UHLS style)' ], 'text' => '"Albany Public Library-Main" becomes "Albany Public Library"; "Cohoes-Adult" becomes "Cohoes"; a location that is just "UHLS" is left as it is.' ],
                ],
                'notes' => [ 'A catalog system that is not listed uses the copy\'s own location.' ],
            ],
            'alias' => [
                'what' => 'After the alias text is chosen, the request page looks for a library whose Alias field (Manage Libraries → Alias) matches it. By default the match must be exact. A rule here changes how that search works for one catalog. You only need a rule when the catalog\'s wording does not exactly equal the alias in your directory.',
                'when' => [
                    'The catalog adds extra text after the library name ("Amsterdam Free Library - Adult"): turn on Split so only the part before the "-" or ":" is used.',
                    'Several systems share one combined catalog: restrict the search to those systems so a same-named library in another system is never picked.',
                    'Your directory alias is longer or shorter than the catalog\'s text: use Starts with or Contains instead of Exactly.',
                    'Two systems each have a library with the same name: restrict by system.',
                ],
                'examples' => [
                    [ 'title' => 'Combined catalog for two systems (CDLC)', 'cells' => [ 'MVLS and SALS combined catalog', 'Contains', 'Yes', 'mvls,sals' ], 'text' => 'A copy at "Amsterdam Free Library - Adult" is split at the "-", giving "Amsterdam Free Library". The lookup then finds a library in the MVLS or SALS system whose Alias contains that. A copy with no "-" or ":" finds nothing (No alias match).' ],
                    [ 'title' => 'One consortium, names that start the same (CDLC)', 'cells' => [ 'Upper Hudson Library System', 'Starts with', 'No', 'UHLS' ], 'text' => 'After the UHLS trimming, "Albany Public Library" finds the UHLS library whose Alias begins with it, such as "Albany Public Library - Bach Branch". Libraries outside UHLS are ignored.' ],
                    [ 'title' => 'School system with sub-locations (CDLC)', 'cells' => [ 'WSWHE BOCES School Library System', 'Starts with', 'Yes', 'WSWHE' ], 'text' => '"Ballston Spa High School - Reference" becomes "Ballston Spa High School" and matches that school in the WSWHE system.' ],
                    [ 'title' => 'Alias is a piece of the catalog wording (SENYLRC)', 'cells' => [ 'Mid-Hudson Library System', 'Contains', 'No', 'MH' ], 'text' => 'A copy location is matched against any MH library whose Alias contains that text.' ],
                ],
                'notes' => [
                    'The first library the search finds is the one used. A broad rule (Contains "Albany") can pick the wrong library, so make rules as specific as you can and restrict the systems.',
                    'Split needs a "-" or ":" in the text. Without one, nothing matches.',
                    'A catalog not in this list is matched exactly. If a real library comes back as "No alias match", either correct its Alias on the library record or add a rule here.',
                    'After any change, test with a real record (see "Testing a change" in the guide).',
                ],
            ],
            'itemtypes' => [
                'what' => 'Which library setting must be "Yes" before a library can be asked for each item type. The item type comes from the catalog record (book, video, journal and so on). The settings are the loaning options on each library record.',
                'when' => [
                    'The catalog starts sending a new item type: add a row, otherwise it is refused everywhere.',
                    'You want a type to be always allowed regardless of library settings: choose "Always allowed".',
                    'A whole system should never be asked for a type: use "Never for systems".',
                ],
                'examples' => [
                    [ 'title' => 'Videos need the audio/video setting', 'cells' => [ 'video', 'Loans audio / video', '' ], 'text' => 'A library whose audio/video loaning is "No" is shown greyed out with "Library not loaning this material type".' ],
                    [ 'title' => '"Other" is allowed except for one system (SENYLRC)', 'cells' => [ 'other', 'Always allowed', 'RC' ], 'text' => 'Any library can be asked for an "other" item, but never a library in the RC system.' ],
                ],
                'notes' => [
                    'While this list is empty, item types are not checked at all. Once it has rows, a type missing from it is refused. A request with no item type at all is always allowed.',
                    'Matching ignores upper/lower case.',
                ],
            ],
            'exempt' => [
                'what' => 'Libraries that are offered every item type, whatever their loan settings say.',
                'when' => [ 'A library has told you it will consider any request and does not want to maintain the loan settings.' ],
                'examples' => [
                    [ 'title' => 'A state library', 'cells' => [ 'New York State Library' ], 'text' => 'It is offered for books, videos, journals and everything else.' ],
                ],
                'notes' => [ 'Use the library\'s name exactly as it appears in Manage Libraries.' ],
            ],
            'special' => [
                'what' => 'Two rare exceptions for catalogs that do not behave like the rest. Most organizations need none or one of each. If you are not sure you need one, leave this list alone.',
                'when' => [
                    'Require: one catalog location covers a large institution, but only one part of it takes part in ILL.',
                    'Allow type: one particular holding location must be offered even when the library\'s loan settings would refuse the item type.',
                ],
                'examples' => [
                    [ 'title' => 'Require: only one campus participates (made-up names, for illustration)', 'cells' => [ 'Require', 'Example University', 'Downtown Campus' ], 'text' => 'Suppose the catalog location "Example University" lists copies from every campus, but only the Downtown Campus takes part in ILL. For any catalog location whose name contains "Example University", a copy counts only if its own location contains "Downtown Campus"; a copy at any other campus is treated as unavailable and hidden.' ],
                    [ 'title' => 'Allow type: skip the item-type check at one location', 'cells' => [ 'Allow type', 'Innovative', 'ODY Folio' ], 'text' => 'This is the exception found in the original request page (SEAL-3 and CDLC). In an "Innovative" catalog, copies at the location "ODY Folio" skip the item-type check. The original code does not say why it exists, so check with whoever maintains that catalog before relying on it. Availability, participation, ILL email and suspension are still checked.' ],
                ],
                'notes' => [
                    'Require: both texts are matched as "contains", ignoring case. Every copy from a matching catalog is checked.',
                    'Allow type: the holding location must equal the text exactly (case does not matter) and the catalog system must match.',
                    'Both only widen or narrow what is offered; they never change the library records.',
                ],
            ],
            'extra' => [
                'what' => 'Some catalogs only show a summary in the search results; the copy-by-copy holdings (location, call number, availability) are in the MARC record. This tells the request page where in that record to look. It is for technical staff: wrong codes mean no holdings from that catalog.',
                'when' => [
                    'A catalog\'s copies do not show up on the request page but exist in the catalog.',
                    'A catalog uses different MARC subfields than the standard ones.',
                ],
                'examples' => [
                    [ 'title' => 'Koha items (CDLC)', 'cells' => [ 'Catalog system', 'Koha', 'd952', 'sb', 'so', 's7', '', '' ], 'text' => 'Each field d952 is one copy: location in subfield sb, call number in so, availability in s7 (0 means available).' ],
                    [ 'title' => 'A catalog with a different location subfield (SENYLRC)', 'cells' => [ 'Catalog system', 'Koha', 'd952', 'sa', 'so', 's7', 'Ramapo-Catskill Library System', 'sk' ], 'text' => 'Location is in sa. For the Ramapo-Catskill catalog only, availability is read from sk instead of s7.' ],
                    [ 'title' => 'One library\'s catalog, shared call number', 'cells' => [ 'Location name', 'Albany Law School', 'd994', 'sb', '@d050.sa', '-', '', '' ], 'text' => 'Each field d994 is a copy location. The call number is not inside the copy, so @d050.sa reads it from field d050 subfield a. A "-" means there is no availability subfield; copies are treated as available.' ],
                ],
                'notes' => [ 'Change this only with a sample MARC record in front of you, and test with a real request afterwards.' ],
            ],
            'crons' => [
                'what' => 'The jobs that run automatically on the server (reminders, expiring old requests, ILLiad checks, weekly stats). They are separate scripts started by the server\'s crontab, not by WordPress. This list is the source for a ready-to-paste crontab: fill in the schedule, the scripts folder and how output is handled, then copy the block underneath and paste it into <crontab -e>. The plugin never runs or changes them.',
                'when' => [
                    'Setting up or moving to a new server: copy the block and paste it into the crontab of the user who owns the script files.',
                    'A job is added, removed or rescheduled: update the row, then copy the new block.',
                    'Someone asks "why did this request change by itself?": check the jobs that update requests (reminder, expire, ILLiad checks).',
                    'You want failures to be visible: switch Job output to "Append to a log file".',
                ],
                'examples' => [
                    [ 'title' => 'A job that runs every day at 7:01 AM', 'cells' => [ '1 7 * * *', '3day_reminder.php', 'Emails lenders who have not answered after 3 working days.' ], 'text' => 'The five schedule fields are: minute, hour, day of month, month, day of week. "1 7 * * *" means minute 1 of hour 7 on every day. With Short code cdlc it becomes: 1 7 * * * /usr/bin/php /var/www/cdlc_script/3day_reminder.php >/dev/null 2>&1' ],
                    [ 'title' => 'Several times a day', 'cells' => [ '0 8,12,16,20 * * *', 'check_illiad.php', 'Asks ILLiad for the status of requests.' ], 'text' => 'A comma list in the hour field runs the job at 8, 12, 4 and 8 PM.' ],
                    [ 'title' => 'Only on Mondays', 'cells' => [ '10 7 * * 1', 'stats_weekly.php', 'Emails the weekly statistics summary.' ], 'text' => 'The last field is the day of the week (0 or 7 = Sunday, 1 = Monday).' ],
                    [ 'title' => 'A script you run by hand', 'cells' => [ 'manual', 'fix_one_request.php', 'A one-off script you keep in the same folder.' ], 'text' => 'Written into the block as a comment showing the command, and never scheduled.' ],
                ],
                'notes' => [
                    'A row with a schedule that is not valid is outlined in red and not saved, so it can never end up in the block.',
                    'The script names are the same for every organization; only the folder is named after the Short code (/var/www/{code}_script, e.g. /var/www/cdlc_script or /var/www/seal_script).',
                    'The scripts read their own settings files in that folder (cron_config.php for addresses and links, ill_db.inc for the database logins), not these Org Rules.',
                    'Choosing "Discard" copies the original behavior (>/dev/null 2>&1): if a job breaks, nothing tells you. "Append to a log file" writes each job\'s output to the file you name.',
                    'The 3-day reminder, expire, and Rota-timeout jobs count working days using the Closure Dates list below, plus US federal holidays fetched automatically — not a file on the server.',
                    'The block does not check that the PHP path or script files exist; run each command once by hand after installing.',
                ],
            ],
            'closures' => [
                'what' => 'The days your libraries are closed that are not already US federal holidays — winter break, a staff development day, a local observance. Weekends are always skipped automatically and do not need to be listed. US federal holidays (Independence Day, Labor Day, Thanksgiving, Christmas, MLK Day, and so on) are fetched automatically and also do not need to be listed here.',
                'when' => [
                    'Your library closes for a stretch that is not a federal holiday, e.g. the week between Christmas and New Year\'s.',
                    'A one-off closure comes up: a staff day, a weather closure you want reflected going forward, a local holiday.',
                    'A reminder or expiry email went out on a day your libraries were actually closed — check whether that date is missing here.',
                ],
                'examples' => [
                    [ 'title' => 'A multi-day closure', 'cells' => [ '2026-12-24', 'Winter Break' ], 'text' => 'Add one row per closed date, even for a stretch of days — 2026-12-24 through 2026-12-31 would be eight separate rows.' ],
                    [ 'title' => 'A closure with no special name', 'cells' => [ '2026-11-27', 'Day after Thanksgiving' ], 'text' => 'The Label is shown on this page and in the crontab/log warnings, but has no other effect — leave it blank if you don\'t need it.' ],
                ],
                'notes' => [
                    'This list, plus automatically-fetched US federal holidays, is the complete closure calendar the reminder, expire and Rota-timeout jobs use to count business days.',
                    'A row whose Date is not exactly YYYY-MM-DD is skipped when the jobs run, the same way an incomplete row in any other list here is skipped.',
                    'The count shown below this list is this list only, not the federal holidays added automatically — running low here is normal. The cron jobs log a warning in their own log file if the combined calendar (this list + federal holidays) ever runs short.',
                ],
            ],
        ];
    }
}
