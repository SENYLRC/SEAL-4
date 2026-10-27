<?php
/**
 * SEAL_DB_Schema — what the ILL database must look like, and a check of the one this site is connected to.
 *
 * TABLES lists, for each of the four ILL tables, every column the plugin or the cron scripts use, with the definition a
 * new install gets (CREATE TABLE) and whether the code needs it (required) or only turns a feature on with it (optional).
 * The definitions follow the original SEAL tables, but every NOT NULL column has a default, text is utf8mb4 and
 * the engine is InnoDB, so they also work with MySQL's strict mode (the default since 5.7).
 *
 * check() reads the live database (gather()) and returns a list of findings (analyze(): no database access, so it can be
 * tested with a saved schema). Nothing here changes the database except create_missing(), which only ever creates tables
 * that don't exist. Every other fix is shown as SQL for an administrator to run.
 *
 * Used by ILL System → Database → Database check (SEAL_Admin::render_db_check()).
 *
 * @author Zachary Spalding (SENYLRC) & Claude (Anthropic)
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class SEAL_DB_Schema {

    /** Columns the request page's INSERT writes (SEAL_Page_Request; keep in step). Any other NOT NULL column needs a default. */
    public const REQUEST_INSERT = [
        'illNUB', 'Title', 'Author', 'pubdate', 'reqisbn', 'reqissn', 'itype', 'Call Number', 'Location', 'Available', 'article',
        'needbydate', 'reqnote', 'patronnote', 'Destination', 'DestSystem', 'Requester lib', 'Requester LOC', 'ReqSystem',
        'Requester person', 'requesterEMAIL', 'Timestamp', 'Fill', 'responderNOTE', 'requesterPhone', 'saddress', 'saddress2', 'caddress',
        'returnNote', 'checkinTimeStamp', 'shipMethod', 'IlliadStatus', 'IlliadTransID',
    ];

    /**
     * The four tables. 'config' is the seal_config() key holding the table's name on this site; 'cols' maps column => [
     * definition for a new install, 'required' | 'optional', what it is for ]; 'keys' are the indexes a new install gets.
     */
    public const TABLES = [
        'stat' => [
            'config' => 'table_stat', 'label' => 'Requests (statistics) table', 'engine' => 'InnoDB',
            'cols' => [
                'index'                 => [ 'INT NOT NULL AUTO_INCREMENT', 'required', 'row number; the ILL number is built from it' ],
                'illNUB'                => [ "VARCHAR(255) NOT NULL DEFAULT ''", 'required', 'ILL number' ],
                'Title'                 => [ 'LONGTEXT NOT NULL', 'required', 'item title' ],
                'Author'                => [ "VARCHAR(255) NOT NULL DEFAULT ''", 'required', 'item author' ],
                'pubdate'               => [ "VARCHAR(255) NOT NULL DEFAULT ''", 'required', 'publication date' ],
                'reqisbn'               => [ "VARCHAR(255) NOT NULL DEFAULT ''", 'required', 'ISBN' ],
                'reqissn'               => [ "VARCHAR(255) NOT NULL DEFAULT ''", 'required', 'ISSN' ],
                'Itype'                 => [ "VARCHAR(255) NOT NULL DEFAULT ''", 'required', 'item type' ],
                'Call Number'           => [ "VARCHAR(255) NOT NULL DEFAULT ''", 'required', 'call number' ],
                'Location'              => [ 'TEXT NOT NULL', 'required', 'holding location' ],
                'Available'             => [ "VARCHAR(255) NOT NULL DEFAULT ''", 'required', 'availability' ],
                'article'               => [ 'LONGTEXT NOT NULL', 'required', 'article details' ],
                'needbydate'            => [ "VARCHAR(255) NOT NULL DEFAULT ''", 'required', 'need-by date' ],
                'reqnote'               => [ "VARCHAR(255) NOT NULL DEFAULT ''", 'required', 'request note' ],
                'patronnote'            => [ "VARCHAR(255) NOT NULL DEFAULT ''", 'required', 'patron note' ],
                'Destination'           => [ "VARCHAR(255) NOT NULL DEFAULT ''", 'required', 'lender LOC code' ],
                'DestSystem'            => [ "VARCHAR(50) NOT NULL DEFAULT ''", 'required', 'lender library system' ],
                'Requester lib'         => [ "VARCHAR(255) NOT NULL DEFAULT ''", 'required', 'borrowing library' ],
                'saddress'              => [ "VARCHAR(255) NOT NULL DEFAULT ''", 'required', 'delivery address' ],
                'saddress2'             => [ "VARCHAR(255) NOT NULL DEFAULT ''", 'required', 'delivery address' ],
                'caddress'              => [ "VARCHAR(255) NOT NULL DEFAULT ''", 'required', 'delivery address' ],
                'Requester LOC'         => [ "VARCHAR(255) NOT NULL DEFAULT ''", 'required', 'borrower LOC code' ],
                'ReqSystem'             => [ "VARCHAR(50) NOT NULL DEFAULT ''", 'required', 'borrower library system' ],
                'Requester person'      => [ "VARCHAR(255) NOT NULL DEFAULT ''", 'required', 'requester name' ],
                'requesterEMAIL'        => [ 'LONGTEXT NOT NULL', 'required', 'requester email(s)' ],
                'requesterPhone'        => [ "VARCHAR(255) NOT NULL DEFAULT ''", 'required', 'requester phone' ],
                'Timestamp'             => [ "VARCHAR(255) NOT NULL DEFAULT ''", 'required', 'request date' ],
                'Fill'                  => [ "TINYINT(1) NOT NULL DEFAULT '3'", 'required', 'answer (1 filled, 0 not filled, 3 waiting, ...)' ],
                'responderNOTE'         => [ 'LONGTEXT NOT NULL', 'required', 'lender note' ],
                'emailsent'             => [ "VARCHAR(1) NOT NULL DEFAULT '0'", 'required', 'reminder / expiry state' ],
                'receiveTimeStamp'      => [ 'VARCHAR(255) NULL DEFAULT NULL', 'required', 'received' ],
                'receiveAccount'        => [ 'VARCHAR(255) NULL DEFAULT NULL', 'required', 'received by' ],
                'receiveDate'           => [ 'DATE NULL DEFAULT NULL', 'required', 'received on' ],
                'returnDate'            => [ 'DATE NULL DEFAULT NULL', 'required', 'returned on' ],
                'fillNofillDate'        => [ 'DATE NULL DEFAULT NULL', 'required', 'answered on' ],
                'returnTimeStamp'       => [ 'VARCHAR(255) NULL DEFAULT NULL', 'required', 'returned' ],
                'returnAccount'         => [ 'VARCHAR(255) NULL DEFAULT NULL', 'required', 'returned by' ],
                'returnNote'            => [ "VARCHAR(255) NOT NULL DEFAULT ''", 'required', 'return note' ],
                'renewTimeStamp'        => [ 'VARCHAR(255) NULL DEFAULT NULL', 'required', 'renewal asked' ],
                'renewAnswer'           => [ "INT NOT NULL DEFAULT '0'", 'required', 'renewal answer' ],
                'renewNote'             => [ 'VARCHAR(255) NULL DEFAULT NULL', 'required', 'renewal note (borrower)' ],
                'renewNoteLender'       => [ 'VARCHAR(255) NULL DEFAULT NULL', 'required', 'renewal note (lender)' ],
                'renewAccountRequester' => [ 'VARCHAR(255) NULL DEFAULT NULL', 'required', 'renewal asked by' ],
                'renewAccountLender'    => [ 'VARCHAR(255) NULL DEFAULT NULL', 'required', 'renewal answered by' ],
                'checkinTimeStamp'      => [ "VARCHAR(255) NOT NULL DEFAULT ''", 'required', 'checked in' ],
                'checkinAccount'        => [ 'VARCHAR(255) NULL DEFAULT NULL', 'required', 'checked in by' ],
                'DueDate'               => [ 'VARCHAR(255) NULL DEFAULT NULL', 'required', 'due date' ],
                'reasonNotFilled'       => [ "INT NOT NULL DEFAULT '0'", 'required', 'reason not filled' ],
                'returnMethod'          => [ 'VARCHAR(11) NULL DEFAULT NULL', 'required', 'return method' ],
                'shipMethod'            => [ "VARCHAR(60) NOT NULL DEFAULT ''", 'required', 'ship method' ],
                'IlliadStatus'          => [ "VARCHAR(255) NOT NULL DEFAULT ''", 'required', 'ILLiad status' ],
                'IlliadTransID'         => [ "VARCHAR(255) NOT NULL DEFAULT ''", 'required', 'ILLiad transaction number' ],
            ],
            'keys' => [ 'PRIMARY KEY (`index`)', 'KEY `illNUB` (`illNUB`)', 'KEY `Destination` (`Destination`)', 'KEY `Requester LOC` (`Requester LOC`)' ],
        ],
        'lib' => [
            'config' => 'table_lib', 'label' => 'Library table', 'engine' => 'InnoDB',
            'cols' => [
                'recnum'           => [ 'INT NOT NULL AUTO_INCREMENT', 'required', 'row number' ],
                'Name'             => [ "VARCHAR(255) NOT NULL DEFAULT ''", 'required', 'library name' ],
                'system'           => [ 'VARCHAR(50) NULL DEFAULT NULL', 'required', 'library system code' ],
                'alias'            => [ "VARCHAR(255) NOT NULL DEFAULT ''", 'required', 'catalog alias' ],
                'oclc'             => [ 'VARCHAR(20) NULL DEFAULT NULL', 'required', 'OCLC symbol' ],
                'loc'              => [ 'VARCHAR(20) NULL DEFAULT NULL', 'required', 'LOC code' ],
                'suspend'          => [ "INT NOT NULL DEFAULT '0'", 'required', 'lending suspended' ],
                'participant'      => [ "INT NOT NULL DEFAULT '0'", 'required', 'takes part in ILL' ],
                'phone'            => [ 'VARCHAR(50) NULL DEFAULT NULL', 'required', 'phone' ],
                'ill_email'        => [ 'VARCHAR(500) NULL DEFAULT NULL', 'required', 'ILL email address(es)' ],
                'book_loan'        => [ 'VARCHAR(3) NULL DEFAULT NULL', 'required', 'loans books (Yes/No)' ],
                'periodical_loan'  => [ 'VARCHAR(3) NULL DEFAULT NULL', 'optional', 'loans periodicals (shown on the library forms when present)' ],
                'av_loan'          => [ 'VARCHAR(3) NULL DEFAULT NULL', 'required', 'loans AV (Yes/No)' ],
                'theses_loan'      => [ 'VARCHAR(3) NULL DEFAULT NULL', 'required', 'loans reference / theses (Yes/No)' ],
                'ebook_request'    => [ 'VARCHAR(3) NULL DEFAULT NULL', 'required', 'fills eBook requests (Yes/No)' ],
                'ejournal_request' => [ 'VARCHAR(3) NULL DEFAULT NULL', 'required', 'fills eJournal requests (Yes/No)' ],
                'address1'         => [ "VARCHAR(255) NOT NULL DEFAULT ''", 'required', 'address' ],
                'address2'         => [ 'VARCHAR(255) NULL DEFAULT NULL', 'required', 'address' ],
                'address3'         => [ "VARCHAR(255) NOT NULL DEFAULT ''", 'required', 'address' ],
                'SuspendDateEnd'   => [ 'DATE NULL DEFAULT NULL', 'required', 'suspension ends' ],
                'SuspendCheckDate' => [ 'DATE NULL DEFAULT NULL', 'required', 'suspension last checked (cron)' ],
                'LibEmailAlert'    => [ "INT NOT NULL DEFAULT '0'", 'required', 'read by the request page' ],
                'Illiad'           => [ "INT NOT NULL DEFAULT '0'", 'required', 'ILLiad library' ],
                'APIkey'           => [ "VARCHAR(255) NOT NULL DEFAULT ''", 'required', 'ILLiad API key (encrypted)' ],
                'IlliadURL'        => [ "VARCHAR(500) NOT NULL DEFAULT ''", 'required', 'ILLiad Web Platform address' ],
                'IlliadDATE'       => [ "INT NOT NULL DEFAULT '0'", 'required', 'ILLiad loan days' ],
                'ModifyDate'       => [ 'DATETIME NULL DEFAULT NULL', 'required', 'last changed' ],
                'ModEmail'         => [ "VARCHAR(255) NOT NULL DEFAULT ''", 'required', 'last changed by' ],
                'lbsyscourier'     => [ 'VARCHAR(3) NULL DEFAULT NULL', 'optional', 'delivery choice "Library System Courier" (shown when present)' ],
                'lbUSPS'           => [ 'VARCHAR(3) NULL DEFAULT NULL', 'optional', 'delivery choice "US Mail" (shown when present)' ],
                'lbEmpire'         => [ 'VARCHAR(3) NULL DEFAULT NULL', 'optional', 'delivery choice "Empire Library Delivery" (shown when present)' ],
            ],
            'keys' => [ 'PRIMARY KEY (`recnum`)', 'UNIQUE KEY `library_name` (`Name`)', 'KEY `loc` (`loc`)' ],
            'skip_create' => [ 'lbsyscourier', 'lbUSPS', 'lbEmpire' ],   // regional; add them if your libraries use them
        ],
        'illiad' => [
            'config' => 'table_illiad', 'label' => 'ILLiad address mapping table', 'engine' => 'InnoDB',
            'cols' => [
                'num'             => [ 'INT NOT NULL AUTO_INCREMENT', 'required', 'row number' ],
                'LOC'             => [ "VARCHAR(20) NOT NULL DEFAULT ''", 'required', 'borrower LOC code' ],
                'illiadID'        => [ "VARCHAR(20) NOT NULL DEFAULT ''", 'required', 'ILLiad lender' ],
                'illiadADDnumb'   => [ "INT NOT NULL DEFAULT '0'", 'required', 'ILLiad address number' ],
                'illiadLIBSymbol' => [ "VARCHAR(30) NOT NULL DEFAULT ''", 'required', 'ILLiad library symbol' ],
            ],
            'keys' => [ 'PRIMARY KEY (`num`)', 'KEY `LOC` (`LOC`)' ],
        ],
        'rota' => [
            'config' => 'table_rota', 'label' => 'Automatic Fallback (rota) table', 'engine' => 'InnoDB',
            'cols' => [
                'illNUB'      => [ 'VARCHAR(40) NOT NULL', 'required', 'ILL number' ],
                'status'      => [ "VARCHAR(20) NOT NULL DEFAULT 'active'", 'required', 'state' ],
                'hop'         => [ "INT UNSIGNED NOT NULL DEFAULT '1'", 'required', 'current lender number' ],
                'hop_started' => [ 'DATETIME NOT NULL', 'required', 'current lender asked at' ],
                'queue'       => [ 'MEDIUMTEXT NULL', 'required', 'lenders still to ask' ],
                'history'     => [ 'MEDIUMTEXT NULL', 'required', 'lenders asked' ],
                'created'     => [ 'DATETIME NOT NULL', 'required', 'created' ],
                'updated'     => [ 'DATETIME NOT NULL', 'required', 'updated' ],
            ],
            'keys' => [ 'PRIMARY KEY (`illNUB`)', 'KEY `status` (`status`)' ],
        ],
    ];

    /** The table name configured on this site for $key ('stat', 'lib', 'illiad', 'rota'). */
    public static function table_name( string $key ): string {
        return trim( (string) seal_config( self::TABLES[ $key ]['config'] ) );
    }

    /** CREATE TABLE for a new install of $key under the name $name. */
    public static function create_sql( string $key, string $name ): string {
        $t     = self::TABLES[ $key ];
        $lines = [];
        foreach ( $t['cols'] as $col => [ $def ] ) {
            if ( in_array( $col, $t['skip_create'] ?? [], true ) ) { continue; }
            $lines[] = '  ' . self::q( $col ) . ' ' . $def;
        }
        foreach ( $t['keys'] as $k ) { $lines[] = '  ' . $k; }
        return 'CREATE TABLE ' . self::q( $name ) . " (\n" . implode( ",\n", $lines ) . "\n) ENGINE=" . $t['engine'] . ' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;';
    }

    private static function q( string $id ): string { return '`' . str_replace( '`', '', $id ) . '`'; }

    // ── Reading the live database ─────────────────────────────────────────────

    /**
     * Everything analyze() looks at, read from the connected database. Throws RuntimeException when there is no connection.
     *
     * @return array{server: array, tables: array, data: array}
     */
    public static function gather(): array {
        SEAL_DB::get();   // throws when there is no connection
        $one  = static function ( string $sql, ...$args ) { $r = SEAL_DB::query( $sql, ...$args ); return $r ? $r->fetch_assoc() : null; };
        $vars = $one( 'SELECT VERSION() AS v, @@SESSION.sql_mode AS m, DATABASE() AS d' ) ?: [];
        $tls  = $one( "SHOW SESSION STATUS LIKE 'Ssl_cipher'" );
        $info = [
            'server' => [ 'version' => (string) ( $vars['v'] ?? '' ), 'sql_mode' => (string) ( $vars['m'] ?? '' ), 'database' => (string) ( $vars['d'] ?? '' ),
                          'cipher' => (string) ( $tls['Value'] ?? '' ), 'rota_active' => class_exists( 'SEAL_Rota' ) && SEAL_Rota::active() ],
            'tables' => [], 'data' => [],
        ];
        foreach ( array_keys( self::TABLES ) as $key ) {
            $name = self::table_name( $key );
            $st   = $name === '' ? null : $one( 'SHOW TABLE STATUS WHERE `Name` = %s', $name );
            $t    = [ 'name' => $name, 'exists' => (bool) $st, 'engine' => (string) ( $st['Engine'] ?? '' ), 'collation' => (string) ( $st['Collation'] ?? '' ),
                      'rows' => (int) ( $st['Rows'] ?? 0 ), 'columns' => [], 'indexes' => [] ];
            if ( $st ) {
                $t['columns'] = SEAL_DB::columns( $name );
                $res = SEAL_DB::query( 'SHOW INDEX FROM %t', $name );
                while ( $res && ( $r = $res->fetch_assoc() ) ) {
                    if ( (int) $r['Seq_in_index'] === 1 ) { $t['indexes'][ strtolower( (string) $r['Column_name'] ) ] = (string) $r['Key_name']; }
                }
            }
            $info['tables'][ $key ] = $t;
        }
        $lib = $info['tables']['lib'];
        if ( $lib['exists'] ) {
            $c = $lib['columns'];
            if ( isset( $c['loc'] ) ) {
                $dups = [];
                $res  = SEAL_DB::query( "SELECT UPPER(TRIM(`loc`)) AS l, COUNT(*) AS n FROM %t WHERE `loc` IS NOT NULL AND TRIM(`loc`) <> '' GROUP BY UPPER(TRIM(`loc`)) HAVING COUNT(*) > 1 LIMIT 50", $lib['name'] );
                while ( $res && ( $r = $res->fetch_assoc() ) ) { $dups[ (string) $r['l'] ] = (int) $r['n']; }
                $info['data']['dup_loc']   = $dups;
                $info['data']['blank_loc'] = (int) ( $one( "SELECT COUNT(*) AS n FROM %t WHERE `loc` IS NULL OR TRIM(`loc`) = ''", $lib['name'] )['n'] ?? 0 );
            }
            if ( isset( $c['ill_email'], $c['participant'] ) ) {
                $info['data']['no_email'] = (int) ( $one( "SELECT COUNT(*) AS n FROM %t WHERE CAST(`participant` AS CHAR) IN ('1','Yes') AND (`ill_email` IS NULL OR TRIM(`ill_email`) = '')", $lib['name'] )['n'] ?? 0 );
            }
            if ( isset( $c['ill_email'] ) && ( $w = self::width( $c['ill_email']['type'] ) ) > 0 ) {
                $info['data']['email_full'] = (int) ( $one( 'SELECT COUNT(*) AS n FROM %t WHERE CHAR_LENGTH(`ill_email`) >= %d', $lib['name'], $w )['n'] ?? 0 );
            }
            if ( isset( $c['system'] ) ) {
                $info['data']['system_len'] = (int) ( $one( 'SELECT MAX(CHAR_LENGTH(`system`)) AS n FROM %t', $lib['name'] )['n'] ?? 0 );
            }
            $map = $info['tables']['illiad'];
            if ( $map['exists'] && isset( $map['columns']['loc'], $c['loc'] ) ) {
                $orph = [];
                $res  = SEAL_DB::query( "SELECT DISTINCT m.`LOC` AS l FROM %t m LEFT JOIN %t l ON UPPER(TRIM(l.`loc`)) = UPPER(TRIM(m.`LOC`)) WHERE l.`loc` IS NULL LIMIT 50", $map['name'], $lib['name'] );
                while ( $res && ( $r = $res->fetch_assoc() ) ) { $orph[] = (string) $r['l']; }
                $info['data']['map_orphans'] = $orph;
            }
        }
        return $info;
    }

    /** Character length of a char/varchar column type ("varchar(50)" → 50); 0 for text types and anything without a length. */
    public static function width( string $type ): int {
        return preg_match( '/^(?:var)?char\((\d+)\)/i', $type, $m ) ? (int) $m[1] : 0;
    }

    /** Findings for the connected database (gather() + analyze()). */
    public static function check(): array {
        try {
            return self::analyze( self::gather() );
        } catch ( \Throwable $e ) {
            return [ self::f( 'fail', '', 'Database connection', 'Could not connect: ' . $e->getMessage(), '', 'Check the connection settings above.' ) ];
        }
    }

    // ── Analysis (no database access) ─────────────────────────────────────────

    /** One finding. level: ok | info | warn | fail. */
    private static function f( string $level, string $table, string $title, string $detail = '', string $sql = '', string $hint = '' ): array {
        return compact( 'level', 'table', 'title', 'detail', 'sql', 'hint' );
    }

    /**
     * Findings for a database described by $info (the shape gather() returns). Ordered: connection, then each table.
     *
     * @return array<int, array{level:string, table:string, title:string, detail:string, sql:string, hint:string}>
     */
    public static function analyze( array $info ): array {
        $out    = [];
        $server = $info['server'] ?? [];
        $mode   = strtoupper( (string) ( $server['sql_mode'] ?? '' ) );
        $strict = str_contains( $mode, 'STRICT_TRANS_TABLES' ) || str_contains( $mode, 'STRICT_ALL_TABLES' );

        $out[] = self::f( 'ok', '', 'Connected', trim( ( $server['database'] ?? '' ) !== '' ? 'Database ' . $server['database'] . ', MySQL ' . ( $server['version'] ?? '' ) : 'MySQL ' . ( $server['version'] ?? '' ) ) );
        $out[] = ( $server['cipher'] ?? '' ) !== ''
            ? self::f( 'ok', '', 'Encrypted connection', 'TLS cipher ' . $server['cipher'] . '.' )
            : self::f( 'warn', '', 'Connection is not encrypted', 'Database traffic (requests, emails, ILLiad keys) crosses the network in plain text.', '', 'Set a CA certificate and Require Encrypted Connection above.' );
        $out[] = self::f( 'info', '', $strict ? 'Strict SQL mode is on' : 'Strict SQL mode is off',
            $strict ? 'MySQL rejects a row that leaves out a required column or holds a value that doesn\'t fit, instead of guessing. The findings below say which columns matter.'
                    : 'MySQL fills in missing values and cuts values that don\'t fit, silently. The site works, but would break on a server with strict mode (the default), so the warnings below still apply.' );

        foreach ( self::TABLES as $key => $spec ) {
            $t     = $info['tables'][ $key ] ?? [ 'name' => '', 'exists' => false ];
            $name  = (string) ( $t['name'] ?? '' );
            $label = $spec['label'];
            if ( $name === '' ) {
                $out[] = self::f( 'fail', $key, $label . ': no table name set', '', '', 'Enter the table name above.' );
                continue;
            }
            if ( empty( $t['exists'] ) ) {
                if ( $key === 'rota' && empty( $server['rota_active'] ) ) {
                    $out[] = self::f( 'info', $key, $label . ' ' . self::q( $name ) . ' does not exist yet', 'Only needed for Automatic Fallback, which is off. It is created when Automatic Fallback is turned on.', self::create_sql( $key, $name ) );
                } else {
                    $out[] = self::f( 'fail', $key, $label . ' ' . self::q( $name ) . ' does not exist', 'Check the name above, or create an empty table (button below).', self::create_sql( $key, $name ) );
                }
                continue;
            }
            $cols   = $t['columns'] ?? [];
            $issues = count( $out );

            // Columns the code needs / can use.
            $missing_req = []; $missing_opt = [];
            foreach ( $spec['cols'] as $col => [ $def, $need, $why ] ) {
                if ( isset( $cols[ strtolower( $col ) ] ) ) { continue; }
                if ( $need === 'required' ) { $missing_req[ $col ] = $why; } else { $missing_opt[ $col ] = $why; }
            }
            if ( $missing_req ) {
                $sql = 'ALTER TABLE ' . self::q( $name ) . "\n  " . implode( ",\n  ", array_map( static fn( $c ) => 'ADD COLUMN ' . self::q( $c ) . ' ' . str_replace( ' AUTO_INCREMENT', '', $spec['cols'][ $c ][0] ), array_keys( $missing_req ) ) ) . ';';
                $out[] = self::f( 'fail', $key, $label . ': ' . count( $missing_req ) . ' required column' . ( count( $missing_req ) === 1 ? '' : 's' ) . ' missing',
                    implode( '; ', array_map( static fn( $c, $w ) => $c . ' (' . $w . ')', array_keys( $missing_req ), $missing_req ) ) . '. Pages or scheduled jobs that use them fail.', $sql );
            }
            if ( $missing_opt ) {
                $sql = 'ALTER TABLE ' . self::q( $name ) . "\n  " . implode( ",\n  ", array_map( static fn( $c ) => 'ADD COLUMN ' . self::q( $c ) . ' ' . $spec['cols'][ $c ][0], array_keys( $missing_opt ) ) ) . ';';
                $out[] = self::f( 'info', $key, $label . ': optional column' . ( count( $missing_opt ) === 1 ? '' : 's' ) . ' not present',
                    implode( '; ', array_map( static fn( $c, $w ) => $c . ' — ' . $w, array_keys( $missing_opt ), $missing_opt ) ) . '. Add only if you want the feature.', $sql );
            }

            // NOT NULL columns without a default that an INSERT leaves out.
            if ( $key === 'stat' ) {
                $written = array_map( 'strtolower', self::REQUEST_INSERT );
                $fix = [];
                foreach ( $cols as $lc => $c ) {
                    if ( $c['null'] || $c['default'] !== null || str_contains( (string) $c['extra'], 'auto_increment' ) || in_array( $lc, $written, true ) ) { continue; }
                    $fix[ $c['name'] ] = self::default_fix( $c, $spec['cols'] );
                }
                if ( $fix ) {
                    $out[] = self::f( $strict ? 'fail' : 'warn', $key,
                        $label . ': ' . count( $fix ) . ' column' . ( count( $fix ) === 1 ? '' : 's' ) . ' need' . ( count( $fix ) === 1 ? 's' : '' ) . ' a default',
                        implode( ', ', array_keys( $fix ) ) . ' — NOT NULL with no default, and new requests don\'t fill ' . ( count( $fix ) === 1 ? 'it' : 'them' ) . '. '
                        . ( $strict ? 'With strict SQL mode every new request fails to save.' : 'Works now only because strict SQL mode is off.' ),
                        self::default_sql( $name, $fix ) );
                }
                if ( ! isset( ( $t['indexes'] ?? [] )['illnub'] ) && isset( $cols['illnub'] ) ) {
                    $out[] = self::f( 'warn', $key, $label . ': no index on illNUB',
                        'Almost every page and scheduled job finds a request by its ILL number; without an index MySQL reads the whole table (' . number_format( (int) ( $t['rows'] ?? 0 ) ) . ' rows) each time.',
                        'ALTER TABLE ' . self::q( $name ) . ' ADD INDEX `illNUB` (`illNUB`);' );
                }
                $sys = (int) ( $info['data']['system_len'] ?? 0 );
                foreach ( [ 'destsystem' => 'DestSystem', 'reqsystem' => 'ReqSystem' ] as $sc => $canon ) {
                    $w = isset( $cols[ $sc ] ) ? self::width( $cols[ $sc ]['type'] ) : 0;
                    if ( $w > 0 && $sys > $w ) {
                        $out[] = self::f( $strict ? 'fail' : 'warn', $key, $label . ': ' . $cols[ $sc ]['name'] . ' is too short',
                            'It holds ' . $w . ' characters, but a library system code in the library table is ' . $sys . ' long. ' . ( $strict ? 'Requests to or from that system fail to save.' : 'The code is cut off.' ),
                            'ALTER TABLE ' . self::q( $name ) . ' MODIFY ' . self::q( $cols[ $sc ]['name'] ) . ' ' . $spec['cols'][ $canon ][0] . ';' );
                    }
                }
            }

            if ( $key === 'lib' ) {
                if ( isset( $cols['apikey'] ) && ( $w = self::width( $cols['apikey']['type'] ) ) > 0 && $w < 160 ) {
                    $out[] = self::f( 'fail', $key, $label . ': APIkey column too narrow', 'It holds ' . $w . ' characters; an encrypted ILLiad key needs about 160.', 'ALTER TABLE ' . self::q( $name ) . " MODIFY `APIkey` VARCHAR(255) NOT NULL DEFAULT '';" );
                }
                $d = $info['data'] ?? [];
                if ( ! empty( $d['dup_loc'] ) ) {
                    $out[] = self::f( 'warn', $key, $label . ': ' . count( $d['dup_loc'] ) . ' LOC code' . ( count( $d['dup_loc'] ) === 1 ? ' is' : 's are' ) . ' used by more than one library',
                        implode( ', ', array_map( static fn( $l, $n ) => $l . ' (' . $n . ')', array_keys( $d['dup_loc'] ), $d['dup_loc'] ) ) . '. Requests, emails and statistics pick one of them at random.', '', 'Give each library its own LOC code (Manage All Libraries).' );
                }
                if ( ! empty( $d['blank_loc'] ) ) {
                    $out[] = self::f( 'info', $key, $label . ': ' . $d['blank_loc'] . ' librar' . ( $d['blank_loc'] === 1 ? 'y has' : 'ies have' ) . ' no LOC code', 'They can\'t lend or borrow until they have one.' );
                }
                if ( ! empty( $d['no_email'] ) ) {
                    $out[] = self::f( 'warn', $key, $label . ': ' . $d['no_email'] . ' participating librar' . ( $d['no_email'] === 1 ? 'y has' : 'ies have' ) . ' no ILL email', 'Requests to them are saved but nobody is told.', '', 'Manage All Libraries → filter "Missing ILL Email".' );
                }
                if ( ! empty( $d['email_full'] ) && isset( $cols['ill_email'] ) ) {
                    $w = self::width( $cols['ill_email']['type'] );
                    $out[] = self::f( 'warn', $key, $label . ': ' . $d['email_full'] . ' ILL email' . ( $d['email_full'] === 1 ? ' fills' : 's fill' ) . ' the whole column',
                        'ill_email holds ' . $w . ' characters; values that long were probably cut off when saved (a second address lost or half an address).',
                        'ALTER TABLE ' . self::q( $name ) . ' MODIFY `ill_email` VARCHAR(500) NULL DEFAULT NULL;', 'Widen the column, then re-enter those addresses.' );
                }
            }

            if ( $key === 'illiad' && ! empty( $info['data']['map_orphans'] ) ) {
                $o = $info['data']['map_orphans'];
                $out[] = self::f( 'info', $key, $label . ': ' . count( $o ) . ' LOC code' . ( count( $o ) === 1 ? '' : 's' ) . ' not in the library table', implode( ', ', $o ) . '. Those mappings are never used.' );
            }

            // Storage: character set and engine.
            $coll = strtolower( (string) ( $t['collation'] ?? '' ) );
            if ( $coll !== '' && ! str_starts_with( $coll, 'utf8mb4' ) ) {
                $cs    = explode( '_', $coll )[0];
                $out[] = self::f( 'warn', $key, $label . ': character set ' . $cs,
                    $cs === 'latin1' ? 'Titles, names and notes with characters outside Western European (Polish, Greek, Chinese, emoji, some quotation marks) are '
                        . ( $strict ? 'rejected' : 'stored as "?"' ) . '. The site sends utf8mb4.'
                        : 'utf8mb3 can\'t store emoji and some rarer characters; the site sends utf8mb4.',
                    'ALTER TABLE ' . self::q( $name ) . ' CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;', 'Back up the table first; converting rewrites it.' );
            }
            if ( strcasecmp( (string) ( $t['engine'] ?? '' ), 'MyISAM' ) === 0 ) {
                $out[] = self::f( 'info', $key, $label . ': MyISAM engine', 'MyISAM isn\'t crash-safe (a server crash during a write can damage the table) and locks the whole table on every write. InnoDB is the MySQL default.',
                    'ALTER TABLE ' . self::q( $name ) . ' ENGINE=InnoDB;', 'Back up first; run at a quiet time.' );
            }
            if ( count( $out ) === $issues ) {
                $out[] = self::f( 'ok', $key, $label . ' ' . self::q( $name ), number_format( (int) ( $t['rows'] ?? 0 ) ) . ' rows; all required columns present.' );
            }
        }
        return $out;
    }

    /** Column definition for MODIFY that keeps $c's type and nullability but adds a default (DATE / DATETIME become nullable). */
    /**
     * ALTER TABLE giving each column its default. Date columns become nullable, and the zero dates ('0000-00-00') that
     * MySQL wrote without strict mode become NULL. The session drops strict mode first: copying a table that still holds
     * zero dates fails under it.
     */
    private static function default_sql( string $name, array $fix ): string {
        $sql   = 'ALTER TABLE ' . self::q( $name ) . "\n  " . implode( ",\n  ", array_map( static fn( $c, $d ) => 'MODIFY ' . self::q( $c ) . ' ' . $d, array_keys( $fix ), $fix ) ) . ';';
        $dates = array_keys( array_filter( $fix, static fn( $d ) => (bool) preg_match( '/^(DATE|DATETIME|TIMESTAMP)\b.*NULL DEFAULT NULL/i', $d ) ) );
        if ( ! $dates ) { return $sql; }
        $sql = "SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION';   -- this connection only\n" . $sql;
        foreach ( $dates as $c ) {
            $sql .= "\nUPDATE " . self::q( $name ) . ' SET ' . self::q( $c ) . ' = NULL WHERE YEAR(' . self::q( $c ) . ') = 0;';
        }
        return $sql;
    }

    private static function default_fix( array $c, array $spec_cols ): string {
        foreach ( $spec_cols as $col => [ $def ] ) {
            if ( strcasecmp( $col, $c['name'] ) === 0 ) { return $def; }
        }
        $type = strtoupper( (string) $c['type'] );
        if ( preg_match( '/^(TINY|SMALL|MEDIUM|BIG)?INT|^DECIMAL|^FLOAT|^DOUBLE/', $type ) ) { return $type . " NOT NULL DEFAULT '0'"; }
        if ( preg_match( '/^(DATE|DATETIME|TIMESTAMP|TIME|YEAR)/', $type ) ) { return $type . ' NULL DEFAULT NULL'; }
        if ( preg_match( '/TEXT|BLOB/', $type ) ) { return $type . ' NULL'; }
        return $type . " NOT NULL DEFAULT ''";
    }

    // ── Creating tables (the only change this class makes) ────────────────────

    /**
     * Create each of the four tables that doesn't exist yet, under its configured name. Existing tables are never touched.
     *
     * @return array{created: string[], failed: string[]}
     */
    public static function create_missing(): array {
        $done = [ 'created' => [], 'failed' => [] ];
        foreach ( array_keys( self::TABLES ) as $key ) {
            $name = self::table_name( $key );
            if ( $name === '' ) { continue; }
            $r = SEAL_DB::query( 'SHOW TABLE STATUS WHERE `Name` = %s', $name );
            if ( $r && $r->num_rows > 0 ) { continue; }
            if ( SEAL_DB::query( str_replace( 'CREATE TABLE ', 'CREATE TABLE IF NOT EXISTS ', self::create_sql( $key, $name ) ) ) ) {
                $done['created'][] = $name;
            } else {
                $done['failed'][] = $name . ' (' . SEAL_DB::last_error() . ')';
            }
        }
        return $done;
    }
}
