<?php
/**
 * SEAL_Resend — re-send an existing request: the request emails (to the lending library and/or a
 * copy of the confirmation to the borrower) and the ILLiad submission for lenders that use ILLiad.
 *
 * Replaces the two hand-run scripts cdlc_resend-request.php and cdlc_illiad_manually_resend.php.
 * Used by the ILL Admin page /resend and by SEAL_Rota when it moves a request to a new library.
 * Everything is read from the saved request (STATS row) and the library table, never from a form.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class SEAL_Resend {

    const LOG_OPTION = 'seal_resend_log';
    const LOG_MAX    = 200;

    // ── Lookups ───────────────────────────────────────────────────────────────

    /** Find a request by ILL number (e.g. 2026-17815) or by its numeric STATS index. */
    public static function find( string $key ): ?array {
        $key = trim( $key );
        if ( $key === '' || strlen( $key ) > 40 ) { return null; }
        $q = ctype_digit( $key )
            ? SEAL_DB::query( "SELECT * FROM %t WHERE `index`=%d OR `illNUB`=%s LIMIT 1", SEAL_DB::$sealSTAT, (int) $key, $key )
            : SEAL_DB::query( "SELECT * FROM %t WHERE `illNUB`=%s LIMIT 1", SEAL_DB::$sealSTAT, $key );
        $row = $q ? $q->fetch_assoc() : null;
        return $row ?: null;
    }

    /** The current lending library (the request's Destination) with its ILLiad settings. */
    public static function lender( array $req ): ?array {
        $loc = trim( (string) ( $req['Destination'] ?? '' ) );
        if ( $loc === '' ) { return null; }
        $q = SEAL_DB::query(
            "SELECT `loc`,`Name`,`ill_email`,`system`,`Illiad`,`IlliadURL`,`IlliadDATE`,`APIkey` FROM %t WHERE `loc`=%s LIMIT 1",
            SEAL_DB::$sealLIB, $loc
        );
        $row = $q ? $q->fetch_assoc() : null;
        return $row ?: null;
    }

    /**
     * True when the library record is set up for ILLiad: the ILLiad flag is on and it has an ILLiad URL and API
     * key.
     */
    public static function lender_uses_illiad( ?array $lib ): bool {
        return $lib && (string) ( $lib['Illiad'] ?? '' ) === '1' && trim( (string) ( $lib['IlliadURL'] ?? '' ) ) !== '' && trim( (string) ( $lib['APIkey'] ?? '' ) ) !== '';
    }

    /** A request is still waiting for the lender's answer (Fill '3' = no answer yet, '' = legacy pending). */
    public static function is_pending( array $req ): bool {
        return in_array( (string) ( $req['Fill'] ?? '' ), [ '3', '' ], true );
    }

    /**
     * The request page stores article details as one string:
     * "Article Title: … <br>Article Author: … <br>Volume: … <br>Issue: … <br>Pages: … <br>Month: … <br>Year: … <br>Copyright: …"
     *
     * @return array{title:string,author:string,volume:string,issue:string,pages:string,month:string,year:string,copyright:string}
     */
    public static function article_fields( string $article ): array {
        $map = [ 'article title' => 'title', 'article author' => 'author', 'volume' => 'volume', 'issue' => 'issue',
                 'pages' => 'pages', 'month' => 'month', 'year' => 'year', 'copyright' => 'copyright' ];
        $out = array_fill_keys( array_values( $map ), '' );
        foreach ( preg_split( '/<br\s*\/?>/i', $article ) as $part ) {
            $bits = explode( ':', $part, 2 );
            if ( count( $bits ) !== 2 ) { continue; }
            $label = strtolower( trim( wp_strip_all_tags( $bits[0] ) ) );
            if ( isset( $map[ $label ] ) ) { $out[ $map[ $label ] ] = trim( wp_strip_all_tags( $bits[1] ) ); }
        }
        return $out;
    }

    // ── Emails ────────────────────────────────────────────────────────────────

    /** @return array{ok:bool,msg:string} */
    public static function email_lender( array $req ): array {
        $lib = self::lender( $req );
        if ( ! $lib ) { return [ 'ok' => false, 'msg' => 'The lending library (' . ( $req['Destination'] ?? '?' ) . ') is not in the library directory.' ]; }
        $to = implode( ',', array_filter( array_map( 'trim', explode( ';', str_replace( ',', ';', (string) $lib['ill_email'] ) ) ) ) );
        if ( $to === '' ) { return [ 'ok' => false, 'msg' => $lib['Name'] . ' has no ILL email address on its library record.' ]; }

        $illnum = (string) $req['illNUB'];
        $r      = static fn( $k ) => (string) ( $req[ $k ] ?? '' );
        $tv     = [ 'ill' => $illnum, 'title' => $r( 'Title' ), 'borrower_library' => $r( 'Requester lib' ) ];
        $body   = SEAL_Emails::heading( 'request_resent', $tv )
                . SEAL_Emails::message( 'request_resent', $tv )
                . SEAL_Functions::email_buttons( [ [ add_query_arg( [ 'num' => $illnum, 'a' => 1 ], home_url( '/respond' ) ), 'Yes, we will fill it', '#0a6b35' ],
                                                   [ add_query_arg( [ 'num' => $illnum, 'a' => 0 ], home_url( '/respond' ) ), 'No, we cannot fill it', '#b32d2e' ] ] )
                . SEAL_Functions::email_subheading( 'Requested item' )
                . SEAL_Functions::email_details( [
                      'ILL #' => $illnum, 'Title' => $r( 'Title' ), 'Author' => $r( 'Author' ), 'Type' => (string) ( $req['itype'] ?? $req['Itype'] ?? '' ),
                      'Publication date' => $r( 'pubdate' ), 'ISBN' => $r( 'reqisbn' ), 'Call number' => $r( 'Call Number' ),
                      'Needed by' => SEAL_Functions::email_date( $r( 'needbydate' ) ), 'Note' => $r( 'reqnote' ),
                  ] )
                . self::article_html( $r( 'article' ) )
                . SEAL_Functions::email_subheading( 'Requested by' )
                . SEAL_Functions::email_details( [
                      'Name' => $r( 'Requester person' ), 'Library' => $r( 'Requester lib' ), 'Email*' => SEAL_Functions::email_mailto( $r( 'requesterEMAIL' ) ),
                      'Phone' => $r( 'requesterPhone' ), 'Ship to' => implode( "\n", array_filter( array_map( 'trim', [ $r( 'saddress' ), $r( 'saddress2' ), $r( 'caddress' ) ] ), 'strlen' ) ),
                  ] );

        $body .= SEAL_Emails::closing( 'request_resent', $tv );
        $sent = SEAL_Functions::send_email( $to, SEAL_Emails::subject( 'request_resent', [ 'ill' => $illnum, 'title' => (string) ( $req['Title'] ?? '' ) ] ), $body );
        return $sent
            ? [ 'ok' => true,  'msg' => 'Request email sent to ' . $lib['Name'] . ' (' . $to . ').' ]
            : [ 'ok' => false, 'msg' => 'WordPress could not send the email to ' . $to . '. Check the site\'s mail settings.' ];
    }

    /** @return array{ok:bool,msg:string} */
    public static function email_requester( array $req ): array {
        $to = implode( ',', array_filter( array_map( 'trim', explode( ',', str_replace( ';', ',', (string) ( $req['requesterEMAIL'] ?? '' ) ) ) ), 'is_email' ) );
        if ( $to === '' ) { return [ 'ok' => false, 'msg' => 'The request has no valid borrower email address.' ]; }

        $illnum = (string) $req['illNUB'];
        $lib    = self::lender( $req );
        $r      = static fn( $k ) => (string) ( $req[ $k ] ?? '' );
        $tv     = [ 'ill' => $illnum, 'title' => $r( 'Title' ) ];
        $body   = SEAL_Emails::heading( 'request_confirm_copy', $tv )
                . SEAL_Emails::message( 'request_confirm_copy', $tv )
                . SEAL_Functions::email_details( [
                      'ILL #' => $illnum, 'Title' => $r( 'Title' ), 'Author' => $r( 'Author' ), 'Publication date' => $r( 'pubdate' ), 'ISBN' => $r( 'reqisbn' ),
                      'Needed by' => SEAL_Functions::email_date( $r( 'needbydate' ) ), 'Requested from' => $lib['Name'] ?? $r( 'Destination' ), 'Your note' => $r( 'reqnote' ),
                  ] )
                . self::article_html( $r( 'article' ) )
                . SEAL_Functions::email_buttons( [ [ home_url( '/requesthistory' ), 'View your borrowing requests', '' ],
                                                   [ add_query_arg( [ 'num' => $illnum, 'a' => 6 ], home_url( '/status' ) ), 'Cancel this request', '#50575e' ] ] );

        $body .= SEAL_Emails::closing( 'request_confirm_copy', $tv );
        $sent = SEAL_Functions::send_email( $to, SEAL_Emails::subject( 'request_confirm_copy', [ 'ill' => $illnum, 'title' => (string) ( $req['Title'] ?? '' ) ] ), $body );
        return $sent
            ? [ 'ok' => true,  'msg' => 'Confirmation copy sent to the borrower (' . $to . ').' ]
            : [ 'ok' => false, 'msg' => 'WordPress could not send the email to ' . $to . '. Check the site\'s mail settings.' ];
    }

    /** The 'Article details' email section for a request's stored article string, or '' for a loan. */
    private static function article_html( string $article ): string {
        $a = self::article_fields( $article );
        if ( $a['title'] === '' ) { return ''; }
        return SEAL_Functions::email_subheading( 'Article details' )
             . SEAL_Functions::email_details( [ 'Article title' => $a['title'], 'Article author' => $a['author'], 'Volume' => $a['volume'], 'Issue' => $a['issue'],
                                                'Pages' => $a['pages'], 'Month' => $a['month'], 'Year' => $a['year'], 'Copyright' => $a['copyright'] ] );
    }

    // ── ILLiad ────────────────────────────────────────────────────────────────

    /**
     * ILLiad address mapping for this borrower → lender pair. Falls back to the borrower's first
     * mapping row (the request page's old behaviour) and says so, so the mapping can be fixed.
     *
     * @return array{row:?array,exact:bool}
     */
    public static function mapping( string $borrower_loc, string $lender_loc ): array {
        $q = SEAL_DB::query( "SELECT `illiadADDnumb`,`illiadLIBSymbol` FROM %t WHERE `LOC`=%s AND `illiadID`=%s LIMIT 1", SEAL_DB::$sealILLiadMapping, $borrower_loc, $lender_loc );
        $row = $q ? $q->fetch_assoc() : null;
        if ( $row ) { return [ 'row' => $row, 'exact' => true ]; }
        $q = SEAL_DB::query( "SELECT `illiadADDnumb`,`illiadLIBSymbol` FROM %t WHERE `LOC`=%s LIMIT 1", SEAL_DB::$sealILLiadMapping, $borrower_loc );
        $row = $q ? $q->fetch_assoc() : null;
        return [ 'row' => $row ?: null, 'exact' => false ];
    }

    /** The JSON body ILLiad's lending API expects — loan shape, or photocopy shape for articles. */
    public static function illiad_payload( array $req, array $lib, array $map, array $borrower_lib ): array {
        $due   = wp_date( 'Y-m-d', strtotime( '+' . max( 1, (int) ( $lib['IlliadDATE'] ?: 14 ) ) . ' days' ) );
        $addr3 = str_replace( ',', '', trim( (string) ( $borrower_lib['address3'] ?? '' ) ) );
        [ $city, $state, $zip ] = array_pad( explode( ' ', $addr3, 3 ), 3, '' );
        $art   = self::article_fields( (string) ( $req['article'] ?? '' ) );

        $common = [
            'Username'            => 'Lending',
            'LendingString'       => (string) ( $req['reqnote'] ?? '' ),
            'ProcessType'         => 'Lending',
            'LenderAddressNumber' => (string) ( $map['illiadADDnumb'] ?? '' ),
            'LendingLibrary'      => (string) ( $map['illiadLIBSymbol'] ?? '' ),
            'TransactionStatus'   => 'Awaiting Lending Request Processing',
            'LoanTitle'           => (string) ( $req['Title'] ?? '' ),
            'LoanAuthor'          => (string) ( $req['Author'] ?? '' ),
            'CallNumber'          => (string) ( $req['Call Number'] ?? '' ),
            'LoanDate'            => (string) ( $req['pubdate'] ?? '' ),
            'ILLNumber'           => (string) $req['illNUB'],
            'TAddress'            => (string) ( $borrower_lib['Name'] ?? '' ),
            'TAddress2'           => (string) ( $borrower_lib['address2'] ?? '' ),
            'TCity'               => $city,
            'TState'              => $state,
            'TZip'                => $zip,
            'TEMailAddress'       => (string) ( $borrower_lib['ill_email'] ?? '' ),
        ];

        if ( $art['title'] === '' ) {
            return $common + [
                'RequestType' => 'Loan',
                'DueDate'     => $due . 'T00:00:00' . wp_date( 'P' ),
                'ISSN'        => (string) ( $req['reqisbn'] ?? '' ),
            ];
        }
        return $common + [
            'PhotoArticleTitle'          => $art['title'],
            'PhotoArticleAuthor'         => $art['author'],
            'PhotoJournalVolume'         => $art['volume'],
            'PhotoJournalIssue'          => $art['issue'],
            'PhotoJournalYear'           => $art['year'],
            'PhotoJournalInclusivePages' => $art['pages'],
            'ISSN'                       => (string) ( $req['reqissn'] ?? '' ),
        ];
    }

    /**
     * Submit the request to the lender's ILLiad and save the transaction number on success.
     * $force allows a second submission when ILLiad already has a transaction for it.
     *
     * @return array{ok:bool,code:string,msg:string,txn?:string,status?:string,detail?:string}
     */
    public static function send_to_illiad( array $req, bool $force = false ): array {
        $lib = self::lender( $req );
        if ( ! self::lender_uses_illiad( $lib ) ) {
            return [ 'ok' => false, 'code' => 'not_illiad', 'msg' => 'The lending library does not use ILLiad (or its ILLiad URL / API key is missing on its library record).' ];
        }
        $existing = trim( (string) ( $req['IlliadTransID'] ?? '' ) );
        if ( $existing !== '' && ! $force ) {
            return [ 'ok' => false, 'code' => 'duplicate', 'msg' => 'ILLiad already has transaction ' . $existing . ' for this request. Sending again creates a second transaction.' ];
        }

        $borrower = trim( (string) ( $req['Requester LOC'] ?? '' ) );
        $m        = self::mapping( $borrower, (string) $lib['loc'] );
        if ( ! $m['row'] ) {
            return [ 'ok' => false, 'code' => 'no_mapping', 'msg' => 'There is no ILLiad mapping for borrower ' . $borrower . ' → lender ' . $lib['loc'] . '. Add one under ILL Admin → ILLiad Mapping, then try again.' ];
        }

        $bq  = SEAL_DB::query( "SELECT `Name`,`ill_email`,`address2`,`address3` FROM %t WHERE `loc`=%s LIMIT 1", SEAL_DB::$sealLIB, $borrower );
        $bl  = $bq ? ( $bq->fetch_assoc() ?: [] ) : [];
        $payload = self::illiad_payload( $req, $lib, $m['row'], $bl );

        $resp = wp_remote_post( (string) $lib['IlliadURL'], [
            'headers'   => [ 'Content-Type' => 'application/json', 'ApiKey' => SEAL_Secret::open( (string) $lib['APIkey'] ) ],
            'body'      => wp_json_encode( $payload ),
            'timeout'   => 20,
            'sslverify' => true,
        ] );
        if ( is_wp_error( $resp ) ) {
            return [ 'ok' => false, 'code' => 'http_error', 'msg' => 'Could not reach ' . $lib['Name'] . '\'s ILLiad: ' . $resp->get_error_message() ];
        }

        $raw     = (string) wp_remote_retrieve_body( $resp );
        $decoded = json_decode( $raw, true );
        $txn     = (string) ( $decoded['TransactionNumber'] ?? '' );
        $status  = (string) ( $decoded['TransactionStatus'] ?? '' );
        if ( $txn === '' || ! ctype_digit( $txn ) ) {
            return [ 'ok' => false, 'code' => 'no_txn', 'msg' => 'ILLiad answered (HTTP ' . (int) wp_remote_retrieve_response_code( $resp ) . ') but did not return a transaction number. Nothing was saved.', 'detail' => substr( $raw, 0, 1000 ) ];
        }

        SEAL_DB::query( "UPDATE %t SET `IlliadStatus`=%s, `IlliadTransID`=%s WHERE `illNUB`=%s", SEAL_DB::$sealSTAT, $status, $txn, (string) $req['illNUB'] );
        $note = $m['exact'] ? '' : ' Note: no mapping row for this exact borrower/lender pair, so the borrower\'s first mapping row was used — check ILL Admin → ILLiad Mapping.';
        return [ 'ok' => true, 'code' => 'ok', 'msg' => 'Sent to ' . $lib['Name'] . '\'s ILLiad: transaction ' . $txn . ( $status !== '' ? ' (' . $status . ')' : '' ) . '.' . $note, 'txn' => $txn, 'status' => $status ];
    }

    // ── Log ───────────────────────────────────────────────────────────────────

    /**
     * Appends one entry to the resend log shown on /resend (time, user, ILL number, action, success, message),
     * keeping the newest LOG_MAX entries.
     */
    public static function log( string $illnum, string $action, bool $ok, string $msg ): void {
        $log   = (array) get_option( self::LOG_OPTION, [] );
        $user  = wp_get_current_user();
        $log[] = [ 'time' => time(), 'user' => $user && $user->exists() ? $user->user_login : 'system', 'ill' => $illnum, 'action' => $action, 'ok' => $ok, 'msg' => $msg ];
        if ( count( $log ) > self::LOG_MAX ) { $log = array_slice( $log, -self::LOG_MAX ); }
        update_option( self::LOG_OPTION, $log, false );
    }

    /** Pending requests to ILLiad lenders that have no ILLiad transaction yet (failed or skipped submissions). */
    public static function waiting_for_illiad( int $days = 60, int $limit = 50 ): array {
        $since = wp_date( 'Y-m-d H:i:s', strtotime( '-' . $days . ' days' ) );
        $q = SEAL_DB::query(
            "SELECT * FROM %t WHERE `Fill`='3' AND (`IlliadTransID` IS NULL OR `IlliadTransID`='') AND `Timestamp` >= %s ORDER BY `Timestamp` DESC LIMIT 500",
            SEAL_DB::$sealSTAT, $since
        );
        $illiad = [];
        $lq = SEAL_DB::query( "SELECT `loc` FROM %t WHERE `Illiad`='1' AND `IlliadURL`<>'' AND `APIkey`<>''", SEAL_DB::$sealLIB );
        while ( $lq && ( $l = $lq->fetch_assoc() ) ) { $illiad[ strtolower( trim( (string) $l['loc'] ) ) ] = true; }

        $out = [];
        while ( $q && ( $r = $q->fetch_assoc() ) ) {
            if ( isset( $illiad[ strtolower( trim( (string) $r['Destination'] ) ) ] ) ) {
                $out[] = $r;
                if ( count( $out ) >= $limit ) { break; }
            }
        }
        return $out;
    }
}
