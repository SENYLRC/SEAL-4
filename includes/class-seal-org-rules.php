<?php
/**
 * SEAL_Org_Rules — the organisation-specific data and rules behind the request page.
 *
 * Ported from the original cdlc_function.php / seal_function.php. Everything that used to
 * be a hard-coded switch (library systems, couriers, catalog vendor per location, how a
 * union catalog's holdings map to a library alias, which item types a library loans, ...)
 * is now plain text edited under ILL → Org Rules. Every install keeps its own lists (there are
 * no presets); a list that has never been filled in is simply empty, and empty means "no
 * restriction" (see check_itemtype). One-time seeding for the existing LiNX/CDLC install is in
 * maybe_seed().
 *
 * Line formats (one per line, "|" separated, "#" starts a comment):
 *   systems     code|Label
 *   couriers    code|Label
 *   catalogs    Catalog location name|Catalog type
 *   behaviour   Catalog type|name   (use the catalog's location name as the alias)
 *               Catalog type|local  (use the holding's own location as the alias)
 *               Catalog type|uhls   (holding location trimmed at its last "-", UHLS style)
 *   alias       Catalog location name|exact/prefix/contains|split yes/no|systems (comma list or *)
 *   itemtypes   item type|library column or "any"[|deny:SYS,SYS]
 *   exempt      Library name that may be asked for any item type
 *   special     require|Catalog name contains|Holding location must contain
 *               allow-type|Catalog type|Holding location   (skip the item-type check)
 *   extra       type:CATALOG or name:NAME|MARC field|location subfield|call subfield|availability subfield[|Name=altsubfield]
 *   crons       When|Script|What it does   (reference list only; the plugin never runs these)
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class SEAL_Org_Rules {

    public const KEYS = [ 'systems', 'couriers', 'catalogs', 'behaviour', 'alias', 'itemtypes', 'exempt', 'special', 'extra', 'crons', 'closures' ];

    // ── Editor schema (drives the row-based editor under ILL → Org Rules) ─────

    /** Catalog types offered as suggestions while typing. */
    public static function known_catalog_types(): array {
        $types = [ 'Koha', 'Alexandria', 'Alma', 'OPALS', 'Polaris', 'Worldcat', 'Millennium', 'Innovative', 'Voyager', 'Folio', 'Symphony', 'SirsiDynix', 'TLC' ];
        foreach ( self::pairs( 'catalogs' ) as $t ) { $types[] = $t; }
        foreach ( array_keys( self::pairs( 'behaviour' ) ) as $t ) { $types[] = $t; }
        $types = array_values( array_unique( array_filter( $types, 'strlen' ) ) );
        sort( $types, SORT_NATURAL | SORT_FLAG_CASE );
        return $types;
    }

    /**
     * Column layout for each rule set. type: text | select | yesno.
     * split: stored cell at "at" is really two editor columns joined by "sep".
     * prefix: text stored with this prefix when not empty. empty_as: stored value for an empty cell.
     */
    public static function schema( string $key ): array {
        $types     = self::known_catalog_types();
        $type_opts = [ '' => '— Choose —' ] + array_combine( $types, $types );
        $all = [
            'systems' => [
                'title' => 'Library systems',
                'hint'  => 'The code is what is stored on each library record; the label is what people see in menus, the directory and statistics.',
                'cols'  => [
                    [ 'label' => 'Code',  'type' => 'text', 'width' => '130px', 'placeholder' => 'CDLC', 'required' => true ],
                    [ 'label' => 'Name',  'type' => 'text', 'placeholder' => 'Capital District Library Council', 'required' => true ],
                ],
            ],
            'couriers' => [
                'title' => 'Couriers and ship methods',
                'hint'  => 'The code is saved on each request; the name is what people see when a lender chooses how the item ships.',
                'cols'  => [
                    [ 'label' => 'Code', 'type' => 'text', 'width' => '150px', 'placeholder' => 'usps', 'required' => true ],
                    [ 'label' => 'Name', 'type' => 'text', 'placeholder' => 'US Mail', 'required' => true ],
                ],
            ],
            'catalogs' => [
                'title' => 'Catalog for each location',
                'hint'  => 'Type the location name exactly as the search service reports it, then choose its catalog system. A system that is not in the list can be added with Edit as text; it will then appear in the list.',
                'cols'  => [
                    [ 'label' => 'Location name in the search results', 'type' => 'text', 'placeholder' => 'Siena College', 'required' => true ],
                    [ 'label' => 'Catalog system', 'type' => 'select', 'width' => '200px', 'options' => $type_opts, 'required' => true ],
                ],
            ],
            'behaviour' => [
                'title' => 'How a holding is matched to a library',
                'hint'  => 'Catalog systems not listed here use the holding\'s own location.',
                'cols'  => [
                    [ 'label' => 'Catalog system', 'type' => 'select', 'width' => '220px', 'options' => $type_opts, 'required' => true ],
                    [ 'label' => 'Use', 'type' => 'select', 'options' => [
                        'name'  => 'The catalog location name',
                        'local' => 'The holding\'s own location',
                        'uhls'  => 'The holding location, trimmed at its last "-" (UHLS style)',
                    ] ],
                ],
            ],
            'alias' => [
                'title' => 'Special matching rules',
                'hint'  => 'Only for catalogs that need more than an exact name match. "Split" uses the text before the first ":" or "-" and requires one of them to be present.',
                'cols'  => [
                    [ 'label' => 'Catalog location name', 'type' => 'text', 'placeholder' => 'Upper Hudson Library System', 'required' => true ],
                    [ 'label' => 'Name match', 'type' => 'select', 'width' => '140px', 'options' => [ 'exact' => 'Exactly', 'prefix' => 'Starts with', 'contains' => 'Contains' ] ],
                    [ 'label' => 'Split', 'type' => 'yesno', 'width' => '70px' ],
                    [ 'label' => 'Only these systems', 'type' => 'text', 'width' => '180px', 'placeholder' => 'any', 'empty_as' => '*' ],
                ],
            ],
            'itemtypes' => [
                'title' => 'What each item type needs',
                'hint'  => 'A library loans an item type only if the chosen setting is "Yes" on its library record. Types missing from this list are refused; a request with no item type is always allowed.',
                'cols'  => [
                    [ 'label' => 'Item type', 'type' => 'text', 'width' => '200px', 'placeholder' => 'book', 'required' => true ],
                    [ 'label' => 'Library setting', 'type' => 'select', 'options' => [
                        'book_loan'        => 'Loans books',
                        'av_loan'          => 'Loans audio / video',
                        'ejournal_request' => 'Electronic journals',
                        'ebook_request'    => 'Electronic books',
                        'theses_loan'      => 'Reference / theses / other',
                        'any'              => 'Always allowed',
                    ] ],
                    [ 'label' => 'Never for systems', 'type' => 'text', 'width' => '170px', 'placeholder' => 'e.g. RC', 'prefix' => 'deny:' ],
                ],
            ],
            'exempt' => [
                'title' => 'Libraries asked for every item type',
                'hint'  => 'Item-type checks are skipped for these libraries.',
                'cols'  => [ [ 'label' => 'Library name', 'type' => 'text', 'placeholder' => 'New York State Library', 'required' => true ] ],
            ],
            'special' => [
                'title' => 'Special cases',
                'hint'  => '"Require" - for catalog locations whose name contains the text, only holdings whose location contains the second text count as available. "Allow type" - skip the item-type check for that catalog system at that holding location.',
                'cols'  => [
                    [ 'label' => 'Rule', 'type' => 'select', 'width' => '150px', 'options' => [ 'require' => 'Require', 'allow-type' => 'Allow type' ] ],
                    [ 'label' => 'Catalog name contains / Catalog system', 'type' => 'text' ],
                    [ 'label' => 'Holding location', 'type' => 'text' ],
                ],
            ],
            'extra' => [
                'title' => 'Full holdings from the MARC record',
                'hint'  => 'For catalogs that only list every holding in the MARC record. Enter the field and subfield codes, for example field d952, location sb, call number so, availability s7. Start the call number with @ to read a field outside the holding (e.g. @d050.sa); use - if there is no availability subfield.',
                'split' => [ [ 'at' => 5, 'sep' => '=' ], [ 'at' => 0, 'sep' => ':' ] ],
                'cols'  => [
                    [ 'label' => 'Match', 'type' => 'select', 'width' => '140px', 'options' => [ 'type' => 'Catalog system', 'name' => 'Location name' ] ],
                    [ 'label' => 'Value', 'type' => 'text', 'width' => '160px', 'placeholder' => 'Koha', 'required' => true ],
                    [ 'label' => 'Field', 'type' => 'text', 'width' => '64px', 'placeholder' => 'd952' ],
                    [ 'label' => 'Location', 'type' => 'text', 'width' => '74px', 'placeholder' => 'sb' ],
                    [ 'label' => 'Call no.', 'type' => 'text', 'width' => '84px', 'placeholder' => 'so' ],
                    [ 'label' => 'Availability', 'type' => 'text', 'width' => '84px', 'placeholder' => 's7' ],
                    [ 'label' => 'Exception: catalog', 'type' => 'text', 'width' => '130px', 'placeholder' => 'optional' ],
                    [ 'label' => 'Availability there', 'type' => 'text', 'width' => '104px', 'placeholder' => 'sk' ],
                ],
            ],
        ];
        $all['crons'] = [
            'title' => 'Scheduled jobs (cron)',
            'hint'  => 'Each job\'s schedule as a cron expression: minute hour day-of-month month weekday (for example 0 20 * * * is every day at 8 PM). Use manual for scripts you run by hand.',
            'cols'  => [
                [ 'label' => 'Schedule', 'type' => 'text', 'width' => '170px', 'placeholder' => '0 20 * * *', 'required' => true,
                  'pattern' => '^(manual|@(reboot|yearly|annually|monthly|weekly|daily|midnight|hourly)|([0-9*/,A-Za-z-]+\\s+){4}[0-9*/,A-Za-z-]+)$' ],
                [ 'label' => 'Script', 'type' => 'text', 'width' => '240px', 'placeholder' => 'expire.php', 'required' => true ],
                [ 'label' => 'What it does', 'type' => 'text', 'placeholder' => 'Expires requests unanswered after 5 working days' ],
            ],
        ];
        $all['closures'] = [
            'title' => 'Closure dates',
            'hint'  => 'One closed date per line (weekends are always skipped automatically, so don\'t list them). The reminder, expire and Rota-timeout cron jobs read this list to count business days. US federal holidays are added automatically and do not need to be listed here — use this for days your libraries close that are not federal holidays (winter break, a staff day, ...).',
            'cols'  => [
                [ 'label' => 'Date', 'type' => 'text', 'width' => '150px', 'placeholder' => '2026-12-24', 'required' => true, 'pattern' => '^\\d{4}-\\d{2}-\\d{2}$' ],
                [ 'label' => 'Label', 'type' => 'text', 'placeholder' => 'Winter Break' ],
            ],
        ];
        return $all[ $key ] ?? [ 'title' => $key, 'hint' => '', 'cols' => [ [ 'label' => 'Value', 'type' => 'text' ] ] ];
    }

    // ── Crontab generator (the "copy and paste" block) ────────────────────────

    public const CRON_RE = '/^(manual|@(reboot|yearly|annually|monthly|weekly|daily|midnight|hourly)|([0-9*\/,A-Za-z-]+\s+){4}[0-9*\/,A-Za-z-]+)$/';

    /** Written in the Scripts folder or Log file, replaced by the short code (/var/www/{code}_script -> /var/www/cdlc_script). */
    public const CODE_TOKEN = '{code}';

    /** Built-in value of each crontab setting. Paths keep the {code} token; it is filled in when the block is built. */
    public static function cron_default( string $key ): string {
        return [
            'code'   => self::code_from( seal_config( 'org_name' ) ),
            'php'    => '/usr/bin/php',
            'dir'    => '/var/www/{code}_script',
            'output' => 'discard',
            'log'    => '/var/log/ill_cron.log',
        ][ $key ] ?? '';
    }

    /** Effective crontab setting: code | php | dir | output (discard|log) | log. */
    public static function cron_setting( string $key ): string {
        $saved = trim( (string) get_option( 'seal_cron_' . $key, '' ) );
        return $saved !== '' ? $saved : self::cron_default( $key );
    }

    /** A name reduced to a folder code: lower-case letters and digits only ("CDLC" -> cdlc, "Due North" -> duenorth). */
    public static function code_from( string $name ): string {
        $code = substr( (string) preg_replace( '/[^a-z0-9]/', '', strtolower( $name ) ), 0, 20 );
        return $code !== '' ? $code : 'ill';
    }

    /** The organization's short code, used to name the scripts folder. */
    public static function cron_code(): string { return self::code_from( self::cron_setting( 'code' ) ); }

    /** Fill in the {code} token. */
    public static function with_code( string $s, ?string $code = null ): string {
        return str_replace( self::CODE_TOKEN, $code ?? self::cron_code(), $s );
    }

    /** Keep only the characters allowed in a path, plus the {code} token. */
    public static function clean_path( string $p ): string {
        $parts = explode( self::CODE_TOKEN, trim( $p ) );
        return implode( self::CODE_TOKEN, array_map( static fn( $x ) => (string) preg_replace( '#[^A-Za-z0-9._/\-]#', '', $x ), $parts ) );
    }

    /**
     * True when a path from the Cron Jobs screen holds only safe characters (letters, digits, . _ / -), so it
     * can be pasted into a crontab line.
     */
    private static function safe_path( string $p ): bool { return (bool) preg_match( '#^[A-Za-z0-9._/\-]+$#', $p ); }

    /** The crontab lines for the Scheduled Jobs list, ready to paste into `crontab -e`. */
    public static function crontab_text( array $override = [] ): string {
        $code = self::code_from( $override['code'] ?? self::cron_code() );
        $php = $override['php'] ?? self::cron_setting( 'php' );
        $dir = rtrim( self::with_code( $override['dir'] ?? self::cron_setting( 'dir' ), $code ), '/' );
        $out = ( $override['output'] ?? self::cron_setting( 'output' ) ) === 'log' ? 'log' : 'discard';
        $log = self::with_code( $override['log'] ?? self::cron_setting( 'log' ), $code );
        $redirect = $out === 'log' && self::safe_path( $log ) ? '>> ' . $log . ' 2>&1' : '>/dev/null 2>&1';

        $lines = [
            '# Scheduled jobs for ' . seal_config( 'system_name' ) . ' (' . seal_config( 'org_name' ) . ')',
            '# Install: run "crontab -e" as the user that owns the script files, paste the lines below, save.',
            '# Check:   crontab -l',
            $out === 'log' ? '# Output is appended to the log file so a failing job leaves a trace.' : '# Output is discarded (a failing job is silent). Choose "Append to a log file" above to keep it.',
            '',
        ];
        $manual = [];
        foreach ( self::rows( self::text( 'crons' ) ) as $r ) {
            $when = $r[0] ?? ''; $script = $r[1] ?? ''; $desc = $r[2] ?? '';
            if ( $script === '' ) { continue; }
            if ( ! self::safe_path( $script ) || ! self::safe_path( $php ) || ! self::safe_path( $dir ) ) {
                $lines[] = '# SKIPPED (unsafe characters in a path or script name): ' . $script; continue;
            }
            if ( strtolower( $when ) === 'manual' ) { $manual[] = [ $script, $desc ]; continue; }
            if ( ! preg_match( self::CRON_RE, $when ) ) { $lines[] = '# SKIPPED (not a valid schedule "' . $when . '"): ' . $script; continue; }
            if ( $desc !== '' ) { $lines[] = '# ' . $desc; }
            $lines[] = $when . ' ' . $php . ' ' . $dir . '/' . $script . ' ' . $redirect;
            $lines[] = '';
        }
        if ( $manual ) {
            $lines[] = '# Run by hand when needed (not scheduled):';
            foreach ( $manual as [ $script, $desc ] ) { $lines[] = '#   ' . $php . ' ' . $dir . '/' . $script . ( $desc !== '' ? '   # ' . $desc : '' ); }
        }
        return rtrim( implode( "\n", $lines ) ) . "\n";
    }

    // ── One-time starting values (existing LiNX/CDLC install only) ───────────────────────────────────────────────────────────

    /** Starting values used once for the existing LiNX/CDLC install (see maybe_seed). */
    private static function seed_cdlc(): array {
        $item_common = "book|book_loan\nbook (large print)|book_loan\njournal|ejournal_request\njournal (electronic)|ejournal_request\n"
                     . "book (electronic)|ebook_request\nweb|ebook_request\nrecording|av_loan\nvideo|av_loan\nvideo-dvd|av_loan\naudio|av_loan\n"
                     . "music-score|theses_loan\nmap|theses_loan\nother (electronic)|theses_loan\n";

        return [
            'cdlc' => [
                'label' => 'CDLC (Capital District Library Council)',
                'systems' => "CDLC|Capital District Library Council\nCRB|Capital Region BOCES\nHFM|Hamilton-Fulton-Montgomery BOCES\n"
                           . "MVLS|Mohawk Valley Library System\nQ3S|Questar III SLS\nSALS|Southern Adirondack Library System\n"
                           . "SCPL|Schenectady County Public Library\nUHLS|Upper Hudson Library System\nWSWHE|WSWHE BOCES",
                'couriers' => "usps|US Mail\nmhls|Mid-Hudson Courier\nrcls|RCLS Courier\nempire|Empire Delivery\nups|UPS\nfedex|FedEx\n"
                            . "crb|Capital Region BOCES Courier\nOCLC Article Exchange|OCLC Article Exchange\nother|Other",
                'catalogs' => "CDLC Partial Union Catalog (PUC)|Koha\nHFM BOCES|Alexandria\nAlbany College of Pharmacy and Health Sciences|Worldcat\n"
                            . "NYS Dept. of Environmental Conservation|OPALS\nNYS Dept. of Transportation|OPALS\nMaria College|OPALS\n"
                            . "New York State Department of Health|OPALS\nSiena College|Alma\nAlbany Law School|Symphony\n"
                            . "Hudson Valley Community College|Alma\nUniversity at Albany|Alma\nFulton Montgomery Community College|Alma\n"
                            . "Rudolf Steiner Library|OPALS\nUnion College|Alma\nSUNY Adirondack|Alma\nSUNY Cobleskill|Alma\n"
                            . "Schenectady County Community College|Alma\nRussell Sage College|Alma\nSkidmore College|Folio\n"
                            . "College of Saint Rose|Voyager\nRensselaer Polytechnic Institute|Worldcat\nMVLS and SALS combined catalog|Polaris\n"
                            . "Schenectady County Public Library|Polaris\nUpper Hudson Library System|InnovativeUHLS\n"
                            . "WSWHE BOCES School Library System|TLC\nScotia Glenville School District|OPALS\nQuestar School Library System|OPALS\n"
                            . "Capital Region BOCES|SirsiDynix\nEmma Willard|SirsiDynix\nNew York State Library|SirsiDynix\n"
                            . "Albany Institute of History and Art|OPALS\nHart Cluet Museum Library|OPALS",
                'behaviour' => "Worldcat|name\ncdlc|name\nMillennium|name\nAlma|name\nVoyager|name\nFolio|name\nSymphony|name\nSirsiDynix|name\n"
                             . "OPALS|local\nPolaris|local\nInnovativeUHLS|uhls",
                'alias' => "MVLS and SALS combined catalog|contains|yes|mvls,sals\nUpper Hudson Library System|prefix|no|UHLS\n"
                         . "Schenectady County Public Library|prefix|yes|SCPL\nWSWHE BOCES School Library System|prefix|yes|WSWHE",
                'itemtypes' => $item_common . "other|any",
                'exempt' => "New York State Library",
                'special' => "",
                'extra' => "type:Koha|d952|sb|so|s7\ntype:Alexandria|d952|sb|so|s7\nname:Albany Law School|d994|sb|@d050.sa|-",
                'cron_dir' => '',   // the default, /var/www/{code}_script
                'crons' => "1 7 * * *|3day_reminder.php|Emails lenders who have not answered after 3 working days and copies the requester. Marks the request Reminder Sent.
"
                         . "6 7 * * *|expire.php|Expires requests still unanswered after 5 working days: emails both sides and sets the status to Expired.
"
                         . "5 7 * * *|check_suspend.php|Ends a library's ILL suspension once its end date has passed.
"
                         . "5 7 * * *|due_date.php|Emails requesters 5 days before the due date of items that were received and not yet returned.
"
                         . "10 7 * * 1|stats_weekly.php|Emails the weekly statistics summary (Mondays).
"
                         . "30 6 * * *|anonymize.php|Data retention: expires requests unanswered for 18 months, clears notes on expired requests after 30 days, and blanks item details and notes on requests older than 5 years (limits in cron_config.php).
"
                         . "0 8,12,16,20 * * *|check_illiad.php|Asks ILLiad for the status of requests sent to ILLiad libraries and updates status, due date and ship method.
"
                         . "0 20 * * *|illiad_return.php|Checks ILLiad for items that were returned and marks them returned and checked in.
"
                         . "0 20 * * *|check_illiad_duedate.php|Copies due dates changed in ILLiad back to the request; closes requests ILLiad reports as not filled.",
            ],
        ];
    }

    /** The saved list (empty until someone fills it in). */
    public static function text( string $key ): string {
        return (string) get_option( 'seal_rules_' . $key, '' );
    }

    /**
     * One-time step for installs that existed before presets were removed: if the site already has
     * its database configured and looks like LiNX/CDLC, copy the old CDLC starting values into this
     * install's own saved lists so nothing changes. Fresh installs (no database configured yet)
     * start empty. Runs once; afterwards the lists are only ever changed by people.
     */
    public static function maybe_seed(): void {
        if ( get_option( 'seal_rules_seeded' ) ) { return; }
        update_option( 'seal_rules_seeded', gmdate( 'c' ), true );

        $chosen = (string) get_option( 'seal_org_preset', '' );
        delete_option( 'seal_org_preset' );
        $existing = seal_config( 'db_name' ) !== '';
        $is_cdlc  = $chosen === 'cdlc' || ( $chosen === '' && stripos( seal_config( 'org_name' ) . ' ' . seal_config( 'org_domain' ), 'cdlc' ) !== false );
        if ( ! $existing || ! $is_cdlc ) { return; }

        $seed = self::seed_cdlc()['cdlc'];
        if ( class_exists( 'SEAL_Rules_Log' ) ) { SEAL_Rules_Log::pause( true ); }
        $filled = [];
        foreach ( self::KEYS as $k ) {
            if ( trim( (string) get_option( 'seal_rules_' . $k, '' ) ) === '' && ! empty( $seed[ $k ] ) ) {
                update_option( 'seal_rules_' . $k, $seed[ $k ], false );
                $filled[] = $k;
            }
        }
        if ( trim( (string) get_option( 'seal_cron_dir', '' ) ) === '' && ! empty( $seed['cron_dir'] ) ) {
            update_option( 'seal_cron_dir', $seed['cron_dir'], false );
        }
        if ( class_exists( 'SEAL_Rules_Log' ) ) {
            SEAL_Rules_Log::pause( false );
            if ( $filled ) { SEAL_Rules_Log::note( 'Existing install: Org Rules lists were filled in once from the old CDLC starting values (' . implode( ', ', $filled ) . '). Presets no longer exist.' ); }
        }
    }

    /** Old CDLC script names -> the generic names every organization now uses. */
    public const OLD_SCRIPT_NAMES = [
        'cdlc_3day_reminder.php' => '3day_reminder.php', 'cdlc_expire.php' => 'expire.php', 'cdlc_check_suspend.php' => 'check_suspend.php',
        'cdlc_due_date.php' => 'due_date.php', 'cdlc_stats_weekly.php' => 'stats_weekly.php', 'cdlc_check_illiad.php' => 'check_illiad.php',
        'cdlc_illiad_retun.php' => 'illiad_return.php', 'cdlc_check_illiad_duedate.php' => 'check_illiad_duedate.php',
    ];
    /** Hand-run scripts replaced by ILL Admin -> Resend a Request. */
    public const RETIRED_SCRIPTS = [ 'cdlc_illiad_manually_resend.php', 'cdlc_resend-request.php' ];

    /**
     * One-time step: the cron scripts were renamed from cdlc_*.php to generic names (the scripts
     * folder is what carries the org's short code now). Renames those rows in the saved Scheduled
     * Jobs list, drops the two retired hand-run scripts, and lets a scripts folder that matches the
     * short code follow the default (/var/www/{code}_script). Other rows are left alone. Logged.
     */
    public static function maybe_migrate_cron_names(): void {
        if ( get_option( 'seal_cron_names_v2' ) ) { return; }
        update_option( 'seal_cron_names_v2', gmdate( 'c' ), true );

        $old = self::text( 'crons' );
        if ( trim( $old ) !== '' ) {
            $out = [];
            foreach ( preg_split( '/\r?\n/', $old ) as $line ) {
                $c = explode( '|', $line );
                $script = trim( $c[1] ?? '' );
                if ( in_array( $script, self::RETIRED_SCRIPTS, true ) ) { continue; }
                if ( isset( self::OLD_SCRIPT_NAMES[ $script ] ) ) { $c[1] = self::OLD_SCRIPT_NAMES[ $script ]; $line = implode( '|', $c ); }
                $out[] = $line;
            }
            $new = implode( "\n", $out );
            if ( $new !== $old ) { update_option( 'seal_rules_crons', $new, false ); }   // recorded in Change history like any edit
        }
        $dir = rtrim( trim( (string) get_option( 'seal_cron_dir', '' ) ), '/' );
        if ( $dir !== '' && $dir === self::with_code( self::cron_default( 'dir' ) ) ) { update_option( 'seal_cron_dir', '', false ); }
    }

    // ── Parsing ───────────────────────────────────────────────────────────────

    /** @return array<int, string[]> rows of trimmed cells */
    public static function rows( string $text ): array {
        $out = [];
        foreach ( preg_split( '/\R/', $text ) as $line ) {
            $line = trim( $line );
            if ( $line === '' || $line[0] === '#' ) { continue; }
            $out[] = array_map( 'trim', explode( '|', $line ) );
        }
        return $out;
    }

    /** @return array<string,string> */
    public static function pairs( string $key ): array {
        $out = [];
        foreach ( self::rows( self::text( $key ) ) as $r ) {
            if ( isset( $r[0], $r[1] ) && $r[0] !== '' ) { $out[ $r[0] ] = $r[1]; }
        }
        return $out;
    }

    /**
     * Library-system code => name pairs from Org Rules → Library Systems (filterable with seal_system_options).
     */
    public static function systems(): array  { return (array) apply_filters( 'seal_system_options', self::pairs( 'systems' ) ); }
    /** Ship-method code => name pairs from Org Rules → Couriers / Ship Methods. */
    public static function couriers(): array { return self::pairs( 'couriers' ); }

    // Same source and rules the cron jobs use (cron/scripts/cron_config.php — keep in step).
    public const HOLIDAY_COUNTRY = 'US';
    public const HOLIDAY_REGION  = 'US-NY';

    /**
     * Nationwide + HOLIDAY_REGION public holidays for one year from Nager.Date, cached 7 days.
     * Returns null if the service couldn't be reached (a failure is cached for an hour so the
     * admin page doesn't wait on it every load).
     *
     * @return array<string,string>|null date => name
     */
    public static function public_holidays( int $year ): ?array {
        $key    = 'seal_holidays_' . self::HOLIDAY_COUNTRY . '_' . $year;
        $cached = get_transient( $key );
        if ( is_array( $cached ) ) { return $cached['ok'] ? $cached['days'] : null; }

        $res = wp_remote_get( 'https://date.nager.at/api/v3/PublicHolidays/' . $year . '/' . self::HOLIDAY_COUNTRY, [ 'timeout' => 5, 'sslverify' => true ] );
        $data = is_wp_error( $res ) || (int) wp_remote_retrieve_response_code( $res ) !== 200
            ? null
            : json_decode( (string) wp_remote_retrieve_body( $res ), true );
        if ( ! is_array( $data ) ) {
            set_transient( $key, [ 'ok' => false, 'days' => [] ], HOUR_IN_SECONDS );
            return null;
        }
        $days = self::filter_holidays( $data );
        set_transient( $key, [ 'ok' => true, 'days' => $days ], WEEK_IN_SECONDS );
        return $days;
    }

    /**
     * Keep nationwide holidays and HOLIDAY_REGION holidays of type Public or Bank; drop other
     * states' days, observances and school-only days. First matching name per date wins.
     *
     * @return array<string,string> date => name
     */
    public static function filter_holidays( array $data ): array {
        $out = [];
        foreach ( $data as $h ) {
            $d = trim( (string) ( $h['date'] ?? '' ) );
            if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) !== 1 || isset( $out[ $d ] ) ) { continue; }
            if ( ! array_intersect( (array) ( $h['types'] ?? [] ), [ 'Public', 'Bank' ] ) ) { continue; }
            $counties = $h['counties'] ?? null;
            if ( ! empty( $h['global'] ) || ( is_array( $counties ) && in_array( self::HOLIDAY_REGION, $counties, true ) ) ) {
                $out[ $d ] = (string) ( $h['name'] ?? $h['localName'] ?? 'Holiday' );
            }
        }
        return $out;
    }

    /**
     * Every non-business day from today through $days ahead, from all three sources the cron jobs
     * combine: public holidays (Nager.Date), Winter Break (Dec 24 – Jan 1) and the Closure Dates list.
     *
     * @return array{days: array<string,array{name:string,source:string}>, fetch_failed: bool}
     */
    public static function upcoming_closures( int $days = 365 ): array {
        $today  = current_time( 'Y-m-d' );
        $end    = gmdate( 'Y-m-d', strtotime( $today . ' +' . $days . ' days' ) );
        $year   = (int) substr( $today, 0, 4 );
        $out    = [];
        $failed = false;

        foreach ( range( $year - 1, (int) substr( $end, 0, 4 ) ) as $y ) {
            for ( $t = strtotime( $y . '-12-24' ); $t <= strtotime( ( $y + 1 ) . '-01-01' ); $t += DAY_IN_SECONDS ) {
                $out[ gmdate( 'Y-m-d', $t ) ] = [ 'name' => 'Winter Break', 'source' => 'Winter Break' ];
            }
        }
        foreach ( range( $year, (int) substr( $end, 0, 4 ) ) as $y ) {
            $ph = self::public_holidays( $y );
            if ( $ph === null ) { $failed = true; continue; }
            foreach ( $ph as $d => $name ) { $out[ $d ] = [ 'name' => $name, 'source' => 'US holiday' ]; }
        }
        foreach ( self::closures() as $c ) {
            if ( ! isset( $out[ $c['date'] ] ) ) {
                $out[ $c['date'] ] = [ 'name' => $c['label'] !== '' ? $c['label'] : 'Closed', 'source' => 'Your closure' ];
            }
        }

        // Weekends are always non-business days, so they are not listed.
        $out = array_filter( $out, static fn( $v, $d ) => $d >= $today && $d <= $end && (int) gmdate( 'N', strtotime( $d ) ) < 6, ARRAY_FILTER_USE_BOTH );
        ksort( $out );
        return [ 'days' => $out, 'fetch_failed' => $failed ];
    }

    /**
     * Closure dates entered under ILL → Org Rules — malformed or blank date cells are skipped
     * here rather than at save time, the same convention every other free-text list uses.
     *
     * @return array<int,array{date:string,label:string}> sorted, valid YYYY-MM-DD only
     */
    public static function closures(): array {
        $out = [];
        foreach ( self::rows( self::text( 'closures' ) ) as $r ) {
            $date = $r[0] ?? '';
            if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) !== 1 ) { continue; }
            $out[] = [ 'date' => $date, 'label' => $r[1] ?? '' ];
        }
        usort( $out, static fn( array $a, array $b ): int => $a['date'] <=> $b['date'] );
        return $out;
    }

    // ── Lookups (ported from find_catalog / find_locationinfo / check_itemtype) ─

    /**
     * The catalog type (Koha, Alma, ...) for a catalog location name, from Org Rules → Catalog Type per
     * Location; '' when it isn't listed.
     */
    public static function find_catalog( string $location_name ): string {
        $name = trim( $location_name );
        foreach ( self::pairs( 'catalogs' ) as $loc => $type ) {
            if ( strcasecmp( trim( $loc ), $name ) === 0 ) { return $type; }
        }
        return '';
    }

    /** name | local | uhls — how a holding's library alias is chosen for this catalog type. */
    public static function behaviour( string $catalog_type ): string {
        foreach ( self::pairs( 'behaviour' ) as $type => $how ) {
            if ( strcasecmp( $type, $catalog_type ) === 0 ) { return strtolower( $how ); }
        }
        return 'local';
    }

    /**
     * The text that identifies the library for one holding, chosen by Org Rules → How the Library Alias Is
     * Chosen: the catalog's location name (name), the holding's own location (local, the default), or the
     * holding location cut at its last '-' (uhls).
     */
    public static function holding_alias( string $catalog_type, string $catalog_location_name, string $holding_location ): string {
        switch ( self::behaviour( $catalog_type ) ) {
            case 'name':
                return $catalog_location_name;
            case 'uhls':
                if ( str_contains( $holding_location, 'Albany' ) ) {
                    $p = strrpos( $holding_location, '-' );
                    return $p === false ? $holding_location : substr( $holding_location, 0, $p );
                }
                if ( $holding_location === 'UHLS' ) { return $holding_location; }
                $p = strpos( $holding_location, '-' );
                return $p === false ? $holding_location : substr( $holding_location, 0, $p );
            default:
                return $holding_location;
        }
    }

    /**
     * Find the participating-library record for a holding.
     * @return array{loc:string,participant:int,email:string,suspend:int,system:string,name:string,alias:string}|null
     */
    public static function find_locationinfo( string $alias, string $catalog_location_name ): ?array {
        $alias = trim( $alias );
        $cat   = trim( $catalog_location_name );
        $match = 'exact'; $split = false; $systems = [];

        foreach ( self::rows( self::text( 'alias' ) ) as $r ) {
            if ( isset( $r[0] ) && strcasecmp( $r[0], $cat ) === 0 ) {
                $match   = strtolower( $r[1] ?? 'exact' );
                $split   = strtolower( $r[2] ?? 'no' ) === 'yes';
                $systems = ( isset( $r[3] ) && $r[3] !== '*' ) ? array_values( array_filter( array_map( 'trim', explode( ',', $r[3] ) ) ) ) : [];
                break;
            }
        }

        if ( $split ) {
            $parts = preg_split( '/[:\-]/', $alias, 2 );
            if ( count( $parts ) !== 2 ) { return null; }
            $alias = trim( $parts[0] );
        }
        if ( $alias === '' ) { return null; }

        $lib_tbl = SEAL_DB::$sealLIB;
        $cols    = '`loc`,`participant`,`ill_email`,`suspend`,`system`,`Name`,`alias`';
        $args    = [ $lib_tbl ];

        if ( $match === 'exact' ) {
            $sql    = "SELECT {$cols} FROM %t WHERE `alias` = %s LIMIT 1";
            $args[] = $alias;
        } else {
            $like   = addcslashes( $alias, '%_\\' );
            $sql    = "SELECT {$cols} FROM %t WHERE `alias` LIKE %s";
            $args[] = ( $match === 'contains' ? '%' : '' ) . $like . '%';
            if ( $systems ) {
                $sql .= ' AND `system` IN (' . implode( ',', array_fill( 0, count( $systems ), '%s' ) ) . ')';
                array_push( $args, ...$systems );
            }
            $sql .= ' LIMIT 1';
        }

        $q   = SEAL_DB::query( $sql, ...$args );
        $row = $q ? $q->fetch_assoc() : null;
        if ( ! $row ) { return null; }
        return [
            'loc'         => (string) $row['loc'],
            'participant' => (int) $row['participant'],
            'email'       => (string) $row['ill_email'],
            'suspend'     => (int) $row['suspend'],
            'system'      => (string) $row['system'],
            'name'        => (string) $row['Name'],
            'alias'       => (string) $row['alias'],
        ];
    }

    /** 1 = the library loans this item type, 0 = it does not. */
    public static function check_itemtype( string $dest_loc, string $item_type, string $dest_system = '', string $catalog_type = '', string $holding_location = '' ): int {
        $item_type = strtolower( trim( $item_type ) );
        if ( $item_type === '' ) { return 1; }
        if ( ! self::rows( self::text( 'itemtypes' ) ) ) { return 1; }   // not configured yet: no restriction

        foreach ( self::rows( self::text( 'special' ) ) as $r ) {
            if ( ( $r[0] ?? '' ) === 'allow-type' && strcasecmp( $r[1] ?? '', $catalog_type ) === 0 && strcasecmp( $r[2] ?? '', $holding_location ) === 0 ) {
                return 1;
            }
        }

        $q   = SEAL_DB::query( 'SELECT `Name`,`book_loan`,`av_loan`,`ejournal_request`,`theses_loan`,`ebook_request` FROM %t WHERE `loc` = %s LIMIT 1', SEAL_DB::$sealLIB, $dest_loc );
        $row = $q ? $q->fetch_assoc() : null;
        if ( ! $row ) { return 0; }

        foreach ( self::rows( self::text( 'exempt' ) ) as $r ) {
            if ( strcasecmp( $r[0], (string) $row['Name'] ) === 0 ) { return 1; }
        }

        foreach ( self::rows( self::text( 'itemtypes' ) ) as $r ) {
            if ( strtolower( $r[0] ?? '' ) !== $item_type ) { continue; }
            $col = $r[1] ?? '';
            foreach ( array_slice( $r, 2 ) as $opt ) {
                if ( stripos( $opt, 'deny:' ) === 0 ) {
                    $deny = array_map( 'trim', explode( ',', substr( $opt, 5 ) ) );
                    if ( in_array( $dest_system, $deny, true ) ) { return 0; }
                }
            }
            if ( strtolower( $col ) === 'any' ) { return 1; }
            return ( $col !== '' && isset( $row[ $col ] ) && $row[ $col ] === 'Yes' ) ? 1 : 0;
        }
        return 0;
    }

    /** Extra "require" rule (e.g. only one campus of a multi-campus college takes part): is this holding allowed? */
    public static function holding_allowed( string $catalog_location_name, string $holding_location ): bool {
        foreach ( self::rows( self::text( 'special' ) ) as $r ) {
            if ( ( $r[0] ?? '' ) === 'require' && $r[1] !== '' && stripos( $catalog_location_name, $r[1] ) !== false ) {
                return stripos( $holding_location, $r[2] ?? '' ) !== false;
            }
        }
        return true;
    }

    /** @return array<int, array{match:string,field:string,loc:string,call:string,avail:string,alt:array}> */
    public static function extra_rules(): array {
        $out = [];
        // Forgiving about how the codes are typed: "909" means d909 and a single subfield letter "a" means sa (the catalog
        // search names data fields dNNN and subfields sX); a missing or blank availability means none (-).
        $field = static fn( string $v ): string => preg_match( '/^\d{3}$/', $v ) ? 'd' . $v : $v;
        $sub   = static fn( string $v ): string => preg_match( '/^[a-z0-9]$/i', $v ) ? 's' . strtolower( $v ) : $v;
        $call  = static fn( string $v ): string => str_starts_with( $v, '@' ) ? $v : $sub( $v );
        foreach ( self::rows( self::text( 'extra' ) ) as $r ) {
            if ( count( $r ) < 4 ) { continue; }
            $r = array_pad( $r, 5, '' );
            if ( $r[4] === '' ) { $r[4] = '-'; }
            [ $r[1], $r[2], $r[3], $r[4] ] = [ $field( $r[1] ), $sub( $r[2] ), $call( $r[3] ), $r[4] === '-' ? '-' : $sub( $r[4] ) ];
            $alt = [];
            if ( isset( $r[5] ) && str_contains( $r[5], '=' ) ) {
                [ $n, $asub ] = array_map( 'trim', explode( '=', $r[5], 2 ) );
                $alt[ $n ] = $asub === '-' ? '-' : $sub( $asub );
            }
            $out[] = [ 'match' => $r[0], 'field' => $r[1], 'loc' => $r[2], 'call' => $r[3], 'avail' => $r[4], 'alt' => $alt ];
        }
        return $out;
    }

    /**
     * The Org Rules → Extra Holdings Lookups rule that applies to this catalog (matched as type:<catalog type>
     * or name:<location name>), or null.
     */
    public static function extra_rule_for( string $catalog_type, string $catalog_location_name ): ?array {
        foreach ( self::extra_rules() as $rule ) {
            [ $kind, $val ] = array_pad( explode( ':', $rule['match'], 2 ), 2, '' );
            if ( ( $kind === 'type' && strcasecmp( $val, $catalog_type ) === 0 ) || ( $kind === 'name' && strcasecmp( $val, $catalog_location_name ) === 0 ) ) {
                return $rule;
            }
        }
        return null;
    }

    // ── Availability ──────────────────────────────────────────────────────────

    /** Generic holdings: 1 = available, 0 = not. */
    public static function normalize_availability( $raw ): int {
        $v = str_replace( [ ' ', "\n", "\r", "\t" ], '', (string) $raw );
        return in_array( $v, [ '-', 'AVAILABLE', 'Available', 'CheckedIn', 'CHECKEDIN' ], true ) ? 1 : 0;
    }

    /** Label for an availability code: 1 Available, 0 Unavailable, anything else UNKNOWN. */
    public static function availability_text( int $code ): string {
        return [ 1 => 'Available', 0 => 'Unavailable', 2 => 'UNKNOWN' ][ $code ] ?? 'UNKNOWN';
    }

    /** MARC-holdings availability (Koha etc.): @return array{text:string,ok:bool} */
    public static function koha_availability( $raw ): array {
        $v = trim( (string) $raw );
        $l = strtolower( $v );
        if ( $v === '1' )                                   { return [ 'text' => 'Unavailable', 'ok' => false ]; }
        if ( $v === '2' )                                   { return [ 'text' => 'UNKNOWN',     'ok' => true ];  }
        if ( $v === '0' || $v === '' )                      { return [ 'text' => 'Available',   'ok' => true ];  }
        if ( str_contains( $l, 'not available' ) || str_contains( $l, 'unavailable' ) ) { return [ 'text' => 'Unavailable', 'ok' => false ]; }
        if ( str_contains( $l, 'available' ) )              { return [ 'text' => 'Available',   'ok' => true ];  }
        foreach ( [ 'checked out' => 'Checked out', 'on hold' => 'On hold', 'lost' => 'Lost', 'in transit' => 'In transit' ] as $needle => $label ) {
            if ( str_contains( $l, $needle ) ) { return [ 'text' => $label, 'ok' => false ]; }
        }
        return [ 'text' => $v, 'ok' => true ];
    }

    // ── One holding → shown / hidden / greyed-out ─────────────────────────────

    /**
     * Apply the request checks to a holding, in the same order as the original request.php
     * (later checks win). fail: 0 = offer it, 1 = hide it, 2 = show it greyed-out with a reason.
     *
     * @param array{catalog:string,catalog_name:string,alias:string,avail_ok:bool,item_type:string,holding_location:string,requester_system:string,filter_own:bool} $h
     * @return array{fail:int,message:string,loc:string,name:string,system:string,email:string,alias:string}
     */
    public static function evaluate( array $h ): array {
        $info = self::find_locationinfo( $h['alias'], $h['catalog_name'] );
        $sys  = seal_config( 'system_name' );

        $loc = $info['loc'] ?? '';   $name = $info['name'] ?? '';  $system = $info['system'] ?? '';
        $email = $info['email'] ?? ''; $part = $info['participant'] ?? 0; $suspend = $info['suspend'] ?? 0;
        $typeloan = ( $info && $part === 1 ) ? self::check_itemtype( $loc, $h['item_type'], $system, $h['catalog'], $h['holding_location'] ) : 1;

        $fail = 0; $msg = '';
        if ( ! $h['avail_ok'] )                                   { $fail = 1; $msg = 'Material unavailable, see source ILS/LMS for details'; }
        if ( $info && $part === 0 )                               { $fail = 1; $msg = 'Library not participating in ' . $sys; }
        if ( strlen( $email ) < 2 )                               { $fail = 1; $msg = 'Library has no ILL email configured'; }
        if ( $suspend === 1 && strtolower( $loc ) !== 'ntr' )     { $fail = 2; $msg = 'Library not loaning / ' . $sys . ' ILL Suspend'; }
        if ( $typeloan === 0 )                                    { $fail = 2; $msg = 'Library not loaning this material type'; }
        if ( $h['filter_own'] && $system !== '' && strcasecmp( $system, $h['requester_system'] ) === 0 ) {
            $fail = 1; $msg = 'Library a member of your system, please request through your ILS/LMS';
        }
        if ( $loc === '' ) {
            $fail = 1; $name = $h['alias']; $system = 'Unknown'; $msg = 'No alias match in ' . $sys . ' directory';
        }

        return [ 'fail' => $fail, 'message' => $msg, 'loc' => $loc, 'name' => $name, 'system' => $system, 'email' => $email, 'alias' => $info['alias'] ?? '' ];
    }
}
