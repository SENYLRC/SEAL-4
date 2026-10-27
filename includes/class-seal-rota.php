<?php
/**
 * SEAL_Rota — automatic lender fallback ("rota") for single-copy requests.
 *
 * Opt-in per request: a checkbox on /request, single-copy requests only (a multi-copy request
 * already goes to several libraries at once, which is a different job). When checked, the
 * borrower's radio pick becomes hop 1 and the rest of the libraries the request page offered
 * (in the same order they were shown, already ranked by ILL → Org Rules) become the fallback
 * queue. The SAME ILL number moves to the next library automatically when the current one:
 *   - explicitly declines ("No, Can't Fill" on /respond), or
 *   - never answers within 5 business days (the existing cron/scripts/expire.php).
 * so the borrower never has to notice and resubmit. When the queue runs out, the request is
 * marked unfillable and the borrower is told which libraries were tried.
 *
 * Modelled on the "rota" / "second broker" idea in Ex Libris Rapido and in ReShare/FOLIO's
 * resource-sharing modules: an ordered list of partners, escalated automatically on non-fill.
 *
 * State lives in its own table (STATS itself is never restructured), one row per rota-enabled
 * request, keyed by the same ILL number:
 *   status        active | filled | cancelled | exhausted — filled/cancelled are set by close(),
 *                 called when the request resolves WITHOUT moving to another library; exhausted
 *                 is set by advance() when the fallback queue runs out. Finished rows (anything
 *                 but active) are deleted after rota_retention_days by the daily cleanup job.
 *   hop           1-based count of libraries tried so far (current one included)
 *   hop_started   when the CURRENT lender was sent the request — the 5-business-day clock for
 *                 automatic advancing runs from here, not from the original request date
 *   queue         JSON list of remaining candidates, in order: [{loc,avail,call,alias}, ...]
 *   history       JSON list of completed hops: [{loc,name,sent,outcome,outcome_at,note}, ...]
 *
 * cron/scripts/expire.php re-implements the "advance on expiry" half of this in plain SQL — it
 * is a standalone CLI script and cannot load WordPress. Keep the two in step if this file
 * changes; see the comment block at the top of that script.
 *
 * ILLiad re-submission on a hop goes through SEAL_Resend::send_to_illiad() (the same code as
 * ILL Admin → Resend a Request), which sends article requests as photocopy requests.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class SEAL_Rota {

    private const OPTION_SCHEMA  = 'seal_rota_schema_version';
    private const SCHEMA_VERSION = 1;

    /** True when the Automatic Fallback module is switched on (ILL System → Modules). */
    public static function active(): bool {
        return seal_config( 'module_rota' ) === '1';
    }

    /** The fallback table's name (ILL System → Database → Rota table; default ILL-ROTA). */
    public static function table(): string {
        return seal_config( 'table_rota' ) ?: 'ILL-ROTA';
    }

    /** Total libraries allowed in one chain (the borrower's pick + fallbacks) — hard-capped at 3. */
    public static function chain_max(): int {
        return max( 1, min( 3, (int) seal_config( 'rota_max_candidates' ) ) );
    }

    /**
     * Build the fallback queue for one request, given the ranked list of everything else on offer
     * (the library the borrower just chose already removed) and, optionally, which of those the
     * borrower explicitly picked as backups.
     *
     * If the borrower picked any valid backups, those are used (in the page's ranked order, not
     * necessarily the order they were checked). Otherwise the next libraries in ranked order are
     * used automatically. Either way the result never exceeds the configured chain length.
     *
     * @param array    $remaining   ranked candidates {loc,avail,call,alias}, destination already removed
     * @param string[] $picked_locs LOC codes the borrower checked, if any (unvalidated — filtered here)
     */
    public static function build_queue( array $remaining, array $picked_locs = [] ): array {
        $slots = max( 0, self::chain_max() - 1 );
        if ( $slots === 0 || ! $remaining ) { return []; }

        $picked_locs = array_unique( array_map( 'strtoupper', $picked_locs ) );
        $picked = $picked_locs
            ? array_values( array_filter( $remaining, static fn( array $c ): bool => in_array( strtoupper( $c['loc'] ), $picked_locs, true ) ) )
            : [];

        return array_slice( $picked ?: $remaining, 0, $slots );
    }

    /** Create the rota table once. Safe to call on every plugins_loaded (cheap after the first run). */
    public static function maybe_create_table(): void {
        if ( (int) get_option( self::OPTION_SCHEMA, 0 ) >= self::SCHEMA_VERSION ) { return; }
        try {
            $db  = SEAL_DB::get();
            $tbl = SEAL_DB::esc_id( self::table() );
            $ok  = $db->query(
                "CREATE TABLE IF NOT EXISTS {$tbl} (
                    `illNUB` VARCHAR(40) NOT NULL,
                    `status` VARCHAR(20) NOT NULL DEFAULT 'active',
                    `hop` INT UNSIGNED NOT NULL DEFAULT 1,
                    `hop_started` DATETIME NOT NULL,
                    `queue` MEDIUMTEXT NULL,
                    `history` MEDIUMTEXT NULL,
                    `created` DATETIME NOT NULL,
                    `updated` DATETIME NOT NULL,
                    PRIMARY KEY (`illNUB`),
                    KEY `status` (`status`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
        } catch ( \Throwable $e ) {
            error_log( 'SEAL_Rota: could not create table: ' . $e->getMessage() );
            return;
        }
        if ( $ok ) { update_option( self::OPTION_SCHEMA, self::SCHEMA_VERSION, false ); }
    }

    // ── Reading ───────────────────────────────────────────────────────────────

    /** @return array{illNUB:string,status:string,hop:int,queue:array,history:array}|null */
    public static function get( string $illNUB ): ?array {
        if ( $illNUB === '' ) { return null; }
        $res = SEAL_DB::query( "SELECT * FROM %t WHERE `illNUB`=%s LIMIT 1", self::table(), $illNUB );
        $row = $res ? $res->fetch_assoc() : null;
        if ( ! $row ) { return null; }
        $row['queue']   = json_decode( (string) ( $row['queue']   ?? '' ), true ) ?: [];
        $row['history'] = json_decode( (string) ( $row['history'] ?? '' ), true ) ?: [];
        $row['hop']     = (int) ( $row['hop'] ?? 1 );
        return $row;
    }

    /** Short status line for Borrowing/Lending history: "Automatic fallback: library 2 of 4 ...". */
    public static function status_line( string $illNUB ): string {
        $rota = self::get( $illNUB );
        if ( ! $rota ) { return ''; }
        $total = $rota['hop'] + count( $rota['queue'] );
        if ( $rota['status'] === 'exhausted' ) {
            return 'Automatic fallback: tried ' . (int) $rota['hop'] . ' ' . ( $rota['hop'] === 1 ? 'library' : 'libraries' ) . ', none could fill it.';
        }
        return 'Automatic fallback: library ' . (int) $rota['hop'] . ' of ' . (int) $total . '.';
    }

    // ── Starting a rota (called from the request page on submission) ───────────

    /**
     * @param array $started {loc,name} — the library the request was actually sent to (hop 1)
     * @param array $queue   remaining candidates, each {loc,avail,call,alias}, in fallback order
     */
    public static function start( string $illNUB, array $started, array $queue ): void {
        $now     = current_time( 'mysql' );
        $history = [ [
            'loc' => $started['loc'], 'name' => $started['name'] ?? $started['loc'], 'sent' => $now,
            'outcome' => null, 'outcome_at' => null, 'note' => '',
        ] ];
        SEAL_DB::query(
            "INSERT INTO %t (`illNUB`,`status`,`hop`,`hop_started`,`queue`,`history`,`created`,`updated`)
             VALUES (%s,'active',1,%s,%s,%s,%s,%s)",
            self::table(), $illNUB, $now, wp_json_encode( array_values( $queue ) ), wp_json_encode( $history ), $now, $now
        );
    }

    /**
     * Close out a rota-enrolled request's tracking row when it resolves WITHOUT moving to another
     * library — the current lender said yes, or the borrower cancelled. (A decline or timeout goes
     * through advance() instead, which either moves to the next library or marks it 'exhausted'.)
     * Does nothing if the request was never enrolled, or is already closed.
     */
    public static function close( string $illNUB, string $status ): void {
        $rota = self::get( $illNUB );
        if ( ! $rota || $rota['status'] !== 'active' ) { return; }
        SEAL_DB::query( "UPDATE %t SET `status`=%s, `updated`=%s WHERE `illNUB`=%s", self::table(), $status, current_time( 'mysql' ), $illNUB );
    }

    // ── Housekeeping: don't keep finished tracking rows forever ─────────────────

    public const CLEANUP_HOOK = 'seal_rota_cleanup';

    /** Schedule the daily cleanup if it isn't already (mirrors SEAL_Module_FileMonitor::ensure_schedule()). */
    public static function ensure_schedule(): void {
        if ( ! wp_next_scheduled( self::CLEANUP_HOOK ) ) {
            wp_schedule_event( time() + 3600, 'daily', self::CLEANUP_HOOK );
        }
    }

    /**
     * Delete tracking rows for requests that finished (filled, cancelled, or exhausted) more than
     * `rota_retention_days` ago (default 180). The finished request itself stays in STATS forever,
     * same as always — this only prunes the small per-hop history this class keeps on the side.
     */
    public static function cleanup(): void {
        $days = max( 1, (int) seal_config( 'rota_retention_days' ) );
        SEAL_DB::query(
            "DELETE FROM %t WHERE `status` IN ('filled','cancelled','exhausted') AND `updated` < DATE_SUB(NOW(), INTERVAL %d DAY)",
            self::table(), $days
        );
    }

    // ── Advancing (called on decline, and by the expire cron on timeout) ───────

    /**
     * Move a rota-enabled request to the next candidate library, or mark it exhausted.
     *
     * @param string $reason 'declined' | 'expired' — why the current hop ended (wording + final Fill code only)
     * @param string $note   the decline reason / free-text note from the current lender, if any
     * @return array{ok:bool,exhausted?:bool,to?:string,why?:string}
     */
    public static function advance( string $illNUB, string $reason, string $note = '' ): array {
        $rota = self::get( $illNUB );
        if ( ! $rota || $rota['status'] !== 'active' ) { return [ 'ok' => false, 'why' => 'not_active' ]; }

        $req_q = SEAL_DB::query( "SELECT * FROM %t WHERE `illNUB`=%s LIMIT 1", SEAL_DB::$sealSTAT, $illNUB );
        $req   = $req_q ? $req_q->fetch_assoc() : null;
        if ( ! $req ) { return [ 'ok' => false, 'why' => 'request_not_found' ]; }

        $now     = current_time( 'mysql' );
        $history = $rota['history'];
        $last    = count( $history ) - 1;
        if ( $last >= 0 ) {
            $history[ $last ]['outcome']    = $reason;
            $history[ $last ]['outcome_at'] = $now;
            $history[ $last ]['note']       = $note;
        }

        $queue = $rota['queue'];
        $next  = null;
        while ( $queue ) {
            $cand = array_shift( $queue );
            $loc  = strtoupper( trim( (string) ( $cand['loc'] ?? '' ) ) );
            if ( $loc === '' ) { continue; }
            $chk  = SEAL_DB::query(
                "SELECT `loc`,`Name`,`ill_email`,`system` FROM %t WHERE UPPER(TRIM(`loc`))=%s AND `participant`=1 AND `suspend`=0 LIMIT 1",
                SEAL_DB::$sealLIB, $loc
            );
            $lib = $chk ? $chk->fetch_assoc() : null;
            if ( $lib ) {
                $next = [
                    'loc' => $lib['loc'], 'name' => $lib['Name'], 'system' => $lib['system'], 'email' => $lib['ill_email'] ?? '',
                    'avail' => $cand['avail'] ?? '', 'call' => $cand['call'] ?? '', 'alias' => $cand['alias'] ?? '',
                ];
                break;
            }
            // No longer eligible (closed / suspended since the request was made) — skip it, try the next.
        }

        if ( ! $next ) {
            SEAL_DB::query(
                "UPDATE %t SET `status`='exhausted', `queue`='[]', `history`=%s, `updated`=%s WHERE `illNUB`=%s",
                self::table(), wp_json_encode( $history ), $now, $illNUB
            );
            $fill = ( $reason === 'expired' ) ? '4' : '0';
            SEAL_DB::query(
                "UPDATE %t SET `Fill`=%s, `emailsent`='3', `responderNOTE`=%s WHERE `illNUB`=%s",
                SEAL_DB::$sealSTAT, $fill,
                'No library in the automatic fallback list could fill this (' . count( $history ) . ' tried).',
                $illNUB
            );
            self::notify_borrower_exhausted( $req, $history );
            return [ 'ok' => true, 'exhausted' => true ];
        }

        $history[] = [ 'loc' => $next['loc'], 'name' => $next['name'], 'sent' => $now, 'outcome' => null, 'outcome_at' => null, 'note' => '' ];
        SEAL_DB::query(
            "UPDATE %t SET `hop`=%d, `hop_started`=%s, `queue`=%s, `history`=%s, `updated`=%s WHERE `illNUB`=%s",
            self::table(), $rota['hop'] + 1, $now, wp_json_encode( array_values( $queue ) ), wp_json_encode( $history ), $now, $illNUB
        );
        SEAL_DB::query(
            "UPDATE %t SET `Destination`=%s, `DestSystem`=%s, `Call Number`=%s, `Location`=%s, `Available`=%s,
             `Fill`='3', `emailsent`='0', `responderNOTE`='', `DueDate`='', `ReasonNotFilled`='0',
             `IlliadTransID`='', `IlliadStatus`='' WHERE `illNUB`=%s",
            SEAL_DB::$sealSTAT, $next['loc'], $next['system'], $next['call'], $next['alias'], $next['avail'], $illNUB
        );

        self::email_new_lender( $req, $next );
        self::notify_borrower_hop( $req, $next, $rota['hop'] + 1 );

        // Re-read the row (now pointing at the new lender) and submit to its ILLiad if it uses ILLiad.
        $fresh = SEAL_Resend::find( $illNUB );
        if ( $fresh ) {
            $sent = SEAL_Resend::send_to_illiad( $fresh );
            if ( ! $sent['ok'] && $sent['code'] !== 'not_illiad' ) {
                SEAL_Functions::send_alert(
                    'ILLiad submission failed on automatic fallback — ILL# ' . $illNUB,
                    '<p>ILL# ' . esc_html( $illNUB ) . ' moved to ' . esc_html( $next['loc'] ) . ', but it could not be sent to that library\'s ILLiad: '
                    . esc_html( $sent['msg'] ) . '</p><p>Fix the problem, then send it from ILL Admin &rarr; Resend a Request.</p>'
                );
            }
            SEAL_Resend::log( $illNUB, 'Send to ILLiad (automatic fallback)', $sent['ok'] || $sent['code'] === 'not_illiad', $sent['msg'] );
        }

        return [ 'ok' => true, 'to' => $next['loc'] ];
    }

    // ── Notifications ────────────────────────────────────────────────────────

    /**
     * Sends the request to the library it has just moved to. If that library has no ILL email, alerts the
     * administrators instead.
     */
    private static function email_new_lender( array $req, array $lib ): void {
        if ( empty( $lib['email'] ) ) {
            SEAL_Functions::send_alert(
                'Rota hop has no lender email — ILL# ' . $req['illNUB'],
                '<p>The automatic fallback moved ILL# ' . esc_html( $req['illNUB'] ) . ' to <strong>' . esc_html( $lib['loc'] ) . '</strong>, which has no ILL email on file. Please contact that library directly.</p>'
            );
            return;
        }
        $sys    = seal_config( 'system_name' );
        $illnum = (string) $req['illNUB'];
        $tv     = [ 'ill' => $illnum, 'title' => (string) ( $req['Title'] ?? '' ) ];
        $body   = SEAL_Emails::heading( 'rota_new_lender', $tv )
                . SEAL_Emails::message( 'rota_new_lender', $tv )
                . SEAL_Functions::email_buttons( [ [ add_query_arg( [ 'num' => $illnum, 'a' => 1 ], home_url( '/respond' ) ), 'Yes, we will fill it', '#0a6b35' ],
                                                   [ add_query_arg( [ 'num' => $illnum, 'a' => 0 ], home_url( '/respond' ) ), 'No, we cannot fill it', '#b32d2e' ] ] )
                . SEAL_Functions::email_details( [
                      'ILL #'          => $illnum,
                      'Title'          => (string) ( $req['Title']  ?? '' ),
                      'Author'         => (string) ( $req['Author'] ?? '' ),
                      'Call number'    => (string) $lib['call'],
                      'Needed by'      => SEAL_Functions::email_date( (string) ( $req['needbydate'] ?? '' ) ),
                      'Requested by'   => (string) ( $req['Requester lib'] ?? '' ),
                      'Requester email*' => SEAL_Functions::email_mailto( (string) ( $req['requesterEMAIL'] ?? '' ) ),
                  ] );
        $body .= SEAL_Emails::closing( 'rota_new_lender', $tv );
        SEAL_Functions::send_email(
            str_replace( ';', ',', (string) $lib['email'] ),
            SEAL_Emails::subject( 'rota_new_lender', [ 'ill' => $illnum, 'title' => (string) ( $req['Title'] ?? '' ) ] ),
            $body
        );
    }

    /**
     * Tells the borrower their request has moved to another library (which one, and which attempt this is).
     */
    private static function notify_borrower_hop( array $req, array $lib, int $hop ): void {
        $to = (string) ( $req['requesterEMAIL'] ?? '' );
        if ( $to === '' ) { return; }
        $sys    = seal_config( 'system_name' );
        $illnum = (string) $req['illNUB'];
        $tv     = [ 'ill' => $illnum, 'title' => (string) ( $req['Title'] ?? '' ) ];
        $body   = SEAL_Emails::heading( 'rota_update', $tv )
                . SEAL_Emails::message( 'rota_update', $tv )
                . SEAL_Functions::email_details( [
                      'ILL #'       => $illnum,
                      'Title'       => (string) ( $req['Title'] ?? '' ),
                      'Now trying'  => ( $lib['name'] ?? $lib['loc'] ) . ' (library ' . (int) $hop . ' on your list)',
                  ] )
                . SEAL_Functions::email_button( home_url( '/requesthistory' ), 'View your borrowing requests' );
        $body .= SEAL_Emails::closing( 'rota_update', $tv );
        SEAL_Functions::send_email( $to, SEAL_Emails::subject( 'rota_update', [ 'ill' => $illnum, 'title' => (string) ( $req['Title'] ?? '' ) ] ), $body );
    }

    /**
     * Tells the borrower that every library in their fallback list declined or didn't answer, with each
     * library's outcome.
     */
    private static function notify_borrower_exhausted( array $req, array $history ): void {
        $to = (string) ( $req['requesterEMAIL'] ?? '' );
        if ( $to === '' ) { return; }
        $sys    = seal_config( 'system_name' );
        $illnum = (string) $req['illNUB'];
        $tried  = implode( "\n", array_map( static fn( $h ) => $h['name'] ?? $h['loc'], $history ) );
        $tv     = [ 'ill' => $illnum, 'title' => (string) ( $req['Title'] ?? '' ) ];
        $body   = SEAL_Emails::heading( 'rota_exhausted', $tv )
                . SEAL_Emails::message( 'rota_exhausted', $tv )
                . SEAL_Functions::email_details( [
                      'ILL #'           => $illnum,
                      'Title'           => (string) ( $req['Title'] ?? '' ),
                      'Libraries tried' => $tried,
                  ] )
                . SEAL_Emails::closing( 'rota_exhausted', $tv )
                . SEAL_Functions::email_button( home_url( '/' ), 'Search the catalog' );
        SEAL_Functions::send_email( $to, SEAL_Emails::subject( 'rota_exhausted', [ 'ill' => $illnum, 'title' => (string) ( $req['Title'] ?? '' ) ] ), $body );
    }

}
