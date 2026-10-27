<?php
/**
 * SEAL_Page_sealstats — statistics console for ILL administrators (all systems, all libraries).
 *
 * Follows SEAL-3's sealstats.php: seven reports, each with its own date range and its result
 * directly underneath it:
 *   borrowing / lending by system (one system or all)     borrowing / lending by library
 *   list of expired requests                             top 10 requesters / top 10 fillers
 * Canceled requests (Fill 6) are left out of the totals, as in SEAL-3. Page text uses the Org
 * Short Name (ILL → Branding), never a hard-coded network name.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class SEAL_Page_sealstats extends SEAL_Page_Base {

    private const REPORTS = [
        'borrow_system'    => [ 'Borrowing statistics by system',       'Requests sent by libraries in a system, and how they turned out.',            'system' ],
        'lend_system'      => [ 'Lending statistics by system',         'Requests received by libraries in a system, and how they turned out.',        'system' ],
        'borrow_library'   => [ 'Borrowing statistics by library',      'Requests sent by one library, and how they turned out.',                      'library' ],
        'lend_library'     => [ 'Lending statistics by library',        'Requests received by one library, and how they turned out.',                  'library' ],
        'expired_list'     => [ 'Expired requests',                     'Every request that expired without an answer in the date range.',             '' ],
        'top10_requesters' => [ 'Top 10 libraries making requests',     'The libraries that sent the most requests (canceled requests not counted).',  '' ],
        'top10_fillers'    => [ 'Top 10 libraries filling requests',    'The libraries that filled the most requests.',                                '' ],
        'rota_usage'       => [ 'Automatic Fallback (Rota) usage',      'How often borrowers turned on automatic fallback, how often requests moved to another library, and how they ended. Counted by the date the request was placed.', '' ],
    ];

    /** Fill states shown in every breakdown: [ key => [ label, SQL condition ] ]. */
    private const STATES = [
        'filled'    => [ 'Filled',       "`Fill`='1'" ],
        'notfilled' => [ 'Not filled',   "`Fill`='0'" ],
        'expired'   => [ 'Expired',      "`Fill`='4'" ],
        'noanswer'  => [ 'Not answered', "`Fill`='3'" ],
    ];

    /** A typed date as YYYY-MM-DD, or $fallback when it can't be read. */
    private static function ymd( string $in, string $fallback ): string {
        $ts = strtotime( str_replace( '-', '/', trim( $in ) ) );
        return $ts ? date( 'Y-m-d', $ts ) : $fallback;
    }

    /**
     * Entry point: SEAL_Router calls this to print the page into the theme's content area. Organization-wide
     * statistics for a date range: totals by status, fill and expiry rates, per-system and per-library tables,
     * top borrowers and lenders, and expired requests.
     */
    public function render(): void {
        $org      = (string) ( seal_config( 'org_name' ) ?: 'System' );
        $systems  = SEAL_Functions::system_options();
        $self     = esc_url( home_url( '/stats' ) );
        $libs     = [];
        $lq       = SEAL_DB::query( "SELECT `loc`, `Name` FROM %t WHERE `loc`<>'' ORDER BY `Name` ASC", SEAL_DB::$sealLIB );
        while ( $lq && ( $r = $lq->fetch_assoc() ) ) { $libs[ (string) $r['loc'] ] = (string) $r['Name']; }

        $default_sd = date( 'Y-m-d', strtotime( '-1 year' ) );
        $default_ed = date( 'Y-m-d' );
        $posted = ''; $sd = $default_sd; $ed = $default_ed; $pick = '';
        if ( $this->is_post() ) {
            $this->verify_nonce( 'seal_stats' );
            $t = sanitize_key( $this->post_str( 'action' ) );
            if ( isset( self::REPORTS[ $t ] ) ) {
                $posted = $t;
                $sd     = self::ymd( $this->post_str( 'startdate' ), $default_sd );
                $ed     = self::ymd( $this->post_str( 'enddate' ),   $default_ed );
                $kind   = self::REPORTS[ $t ][2];
                $pick   = $this->post_str( $kind === 'library' ? 'loc' : 'system' );
                if ( $kind === 'system'  && $pick !== '' && ! isset( $systems[ $pick ] ) ) { $pick = ''; }
                if ( $kind === 'library' && ! isset( $libs[ $pick ] ) )                     { $pick = ''; }
            }
        }
        ?>
        <div class="seal-page-wrap seal-ss">
        <h3><?php echo esc_html( $org ); ?> Statistics</h3>
        <p class="seal-ss-note">Pick a date range for any report. Both dates are included. Canceled requests are not counted.</p>
        <?php
        $ids = [];
        foreach ( self::REPORTS as $key => [ $title, $help, $kind ] ) :
            $mine  = ( $posted === $key );
            $v_sd  = $mine ? $sd : $default_sd;
            $v_ed  = $mine ? $ed : $default_ed;
            $v_pick = $mine ? $pick : '';
            $ids[] = '#sd_' . $key . ', #ed_' . $key;
            ?>
        <section class="seal-ss-card seal-ss-wide" aria-labelledby="h_<?php echo esc_attr( $key ); ?>">
            <h3 id="h_<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $title ); ?></h3>
            <p class="seal-ss-help"><?php echo esc_html( $help ); ?></p>
            <form method="post" action="<?php echo $self; ?>#h_<?php echo esc_attr( $key ); ?>" class="seal-ss-form">
                <?php $this->nonce_field( 'seal_stats' ); ?>
                <input type="hidden" name="action" value="<?php echo esc_attr( $key ); ?>">
                <?php if ( $kind === 'system' ) : ?>
                <div class="form-group"><label for="sys_<?php echo esc_attr( $key ); ?>">Library system</label>
                    <select id="sys_<?php echo esc_attr( $key ); ?>" name="system">
                        <option value="">All systems</option>
                        <?php foreach ( $systems as $code => $name ) : ?>
                        <option value="<?php echo esc_attr( $code ); ?>" <?php selected( $v_pick, $code ); ?>><?php echo esc_html( $name ); ?></option>
                        <?php endforeach; ?>
                    </select></div>
                <?php elseif ( $kind === 'library' ) : ?>
                <div class="form-group"><label for="loc_<?php echo esc_attr( $key ); ?>">Library</label>
                    <select id="loc_<?php echo esc_attr( $key ); ?>" name="loc" required>
                        <option value="">Choose a library&hellip;</option>
                        <?php foreach ( $libs as $loc => $name ) : ?>
                        <option value="<?php echo esc_attr( $loc ); ?>" <?php selected( $v_pick, $loc ); ?>><?php echo esc_html( $name . ' (' . $loc . ')' ); ?></option>
                        <?php endforeach; ?>
                    </select></div>
                <?php endif; ?>
                <div class="form-group"><label for="sd_<?php echo esc_attr( $key ); ?>">Start date</label>
                    <input type="text" id="sd_<?php echo esc_attr( $key ); ?>" name="startdate" value="<?php echo esc_attr( $v_sd ); ?>" autocomplete="off"></div>
                <div class="form-group"><label for="ed_<?php echo esc_attr( $key ); ?>">End date</label>
                    <input type="text" id="ed_<?php echo esc_attr( $key ); ?>" name="enddate" value="<?php echo esc_attr( $v_ed ); ?>" autocomplete="off"></div>
                <div class="form-group"><button type="submit" class="btn-primary">View</button></div>
            </form>
            <?php
            if ( $mine ) {
                echo '<div class="seal-ss-result" aria-live="polite">';
                $this->result( $key, $title, $sd, $ed, $pick, $systems, $libs );
                echo '</div>';
            }
            ?>
        </section>
        <?php endforeach; ?>
        </div>
        <?php
        $this->datepicker_js( implode( ', ', $ids ) );
    }

    // ── Results ───────────────────────────────────────────────────────────────

    /**
     * Prints one report ($key from REPORTS) for the date range, scoped to a system or library when the report
     * needs one.
     */
    private function result( string $key, string $title, string $sd, string $ed, string $pick, array $systems, array $libs ): void {
        $from = $sd . ' 00:00:00';
        $to   = $ed . ' 23:59:59';

        $scope = '';
        if ( self::REPORTS[ $key ][2] === 'system' )  { $scope = $pick === '' ? 'all systems' : SEAL_Functions::system_name( $pick ); }
        if ( self::REPORTS[ $key ][2] === 'library' ) { $scope = $libs[ $pick ] ?? ''; if ( $pick === '' ) { echo '<p>Please choose a library first.</p>'; return; } }
        echo '<h4>' . esc_html( $title . ( $scope !== '' ? ' &mdash; ' : '' ) ) . esc_html( $scope ) . '</h4>';
        echo '<p class="seal-ss-note">' . esc_html( $sd ) . ' to ' . esc_html( $ed ) . '</p>';

        switch ( $key ) {
            case 'borrow_system':  $this->breakdown( 'ReqSystem',     'DestSystem', $pick, $from, $to, 'Total requests', 'Lending system', $systems ); break;
            case 'lend_system':    $this->breakdown( 'DestSystem',    'ReqSystem',  $pick, $from, $to, 'Total requests received', 'Borrowing system', $systems ); break;
            case 'borrow_library': $this->breakdown( 'Requester LOC', 'DestSystem', $pick, $from, $to, 'Total requests', 'Lending system', $systems ); break;
            case 'lend_library':   $this->breakdown( 'Destination',   'ReqSystem',  $pick, $from, $to, 'Total requests received', 'Borrowing system', $systems ); break;
            case 'expired_list':   $this->expired( $from, $to ); break;
            case 'top10_requesters': $this->top10( 'Requester LOC', '`Fill`<>\'6\' OR `Fill` IS NULL', $from, $to, 'Requests' ); break;
            case 'top10_fillers':    $this->top10( 'Destination', "`Fill`='1'", $from, $to, 'Requests filled' ); break;
            case 'rota_usage':       $this->rota_usage( $from, $to ); break;
        }
    }

    /**
     * Totals by outcome, then by the other side's system and item type.
     * $by_col filters (one system / one library; '' = all), $group_col is the other side's system.
     */
    private function breakdown( string $by_col, string $group_col, string $pick, string $from, string $to, string $total_label, string $group_label, array $systems ): void {
        $sel = 'COUNT(*) AS total';
        foreach ( self::STATES as $k => [ , $cond ] ) { $sel .= ", SUM(CASE WHEN {$cond} THEN 1 ELSE 0 END) AS {$k}"; }
        $bc = SEAL_DB::esc_id( $by_col ); $gc = SEAL_DB::esc_id( $group_col );

        $sql  = "SELECT IFNULL({$gc},'') AS grp, IFNULL(`itype`,'') AS itype, {$sel} FROM %t WHERE (`Fill` IS NULL OR `Fill`<>'6') AND `Timestamp` BETWEEN %s AND %s";
        $args = [ SEAL_DB::$sealSTAT, $from, $to ];
        if ( $pick !== '' ) { $sql .= " AND {$bc}=%s"; $args[] = $pick; }
        $sql .= " GROUP BY grp, itype ORDER BY grp ASC, itype ASC";

        $res = SEAL_DB::query( $sql, ...$args );
        $groups = []; $all = array_fill_keys( array_merge( [ 'total' ], array_keys( self::STATES ) ), 0 );
        while ( $res && ( $r = $res->fetch_assoc() ) ) {
            $g = (string) $r['grp'];
            $groups[ $g ]['rows'][] = $r;
            foreach ( $all as $k => $_ ) {
                $all[ $k ] += (int) $r[ $k ];
                $groups[ $g ]['sum'][ $k ] = ( $groups[ $g ]['sum'][ $k ] ?? 0 ) + (int) $r[ $k ];
            }
        }
        if ( $all['total'] === 0 ) { echo '<p>No results found.</p>'; return; }

        // Summary
        ?>
        <table class="rh-table seal-ss-table seal-ss-summary">
            <thead><tr><th scope="col">Outcome</th><th scope="col" class="num">Requests</th><th scope="col" class="num">Percent</th></tr></thead>
            <tbody>
                <tr class="seal-ss-total"><th scope="row"><?php echo esc_html( $total_label ); ?></th><td class="num"><?php echo number_format( $all['total'] ); ?></td><td class="num">100%</td></tr>
                <?php foreach ( self::STATES as $k => [ $label ] ) : ?>
                <tr><td><?php echo esc_html( $label ); ?></td><td class="num"><?php echo number_format( $all[ $k ] ); ?></td><td class="num"><?php echo esc_html( SEAL_Functions::pct2( $all[ $k ], $all['total'] ) ); ?></td></tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <h4 style="margin-top:18px;">By <?php echo esc_html( strtolower( $group_label ) ); ?> and item type</h4>
        <div class="seal-table-wrap">
        <table class="rh-table seal-ss-table">
            <thead><tr>
                <th scope="col"><?php echo esc_html( $group_label ); ?> / item type</th><th scope="col" class="num">Total</th>
                <?php foreach ( self::STATES as [ $label ] ) : ?><th scope="col" class="num"><?php echo esc_html( $label ); ?></th><?php endforeach; ?>
            </tr></thead>
            <tbody>
            <?php foreach ( $groups as $g => $data ) :
                $name = $g === '' ? '(no system recorded)' : ( $systems[ $g ] ?? $g ); ?>
                <tr class="seal-ss-group">
                    <th scope="row"><?php echo esc_html( $name ); ?></th>
                    <td class="num"><?php echo number_format( $data['sum']['total'] ); ?></td>
                    <?php foreach ( array_keys( self::STATES ) as $k ) : ?>
                    <td class="num"><?php echo number_format( $data['sum'][ $k ] ); ?> <span class="seal-ss-pct">(<?php echo esc_html( SEAL_Functions::pct2( $data['sum'][ $k ], $data['sum']['total'] ) ); ?>)</span></td>
                    <?php endforeach; ?>
                </tr>
                <?php foreach ( $data['rows'] as $r ) : ?>
                <tr>
                    <td class="seal-ss-indent"><?php echo esc_html( $r['itype'] !== '' ? ucfirst( (string) $r['itype'] ) : '(unspecified)' ); ?></td>
                    <td class="num"><?php echo number_format( (int) $r['total'] ); ?></td>
                    <?php foreach ( array_keys( self::STATES ) as $k ) : ?>
                    <td class="num"><?php echo number_format( (int) $r[ $k ] ); ?> <span class="seal-ss-pct">(<?php echo esc_html( SEAL_Functions::pct2( (int) $r[ $k ], (int) $r['total'] ) ); ?>)</span></td>
                    <?php endforeach; ?>
                </tr>
                <?php endforeach; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php
    }

    /** Table of requests that expired between $from and $to, with both libraries' names. */
    private function expired( string $from, string $to ): void {
        $res = SEAL_DB::query(
            "SELECT s.`illNUB`, s.`Title`, s.`Requester LOC` AS req_loc, s.`Destination` AS dest_loc, s.`Timestamp`,
                    COALESCE(l.`Name`, s.`Requester LOC`) AS reqname, COALESCE(l2.`Name`, s.`Destination`) AS destname
             FROM %t s
             LEFT JOIN %t l  ON l.`loc`  = s.`Requester LOC`
             LEFT JOIN %t l2 ON l2.`loc` = s.`Destination`
             WHERE s.`Fill`='4' AND s.`Timestamp` BETWEEN %s AND %s
             ORDER BY s.`Timestamp` DESC LIMIT 1000",
            SEAL_DB::$sealSTAT, SEAL_DB::$sealLIB, SEAL_DB::$sealLIB, $from, $to
        );
        $n = $res ? $res->num_rows : 0;
        if ( $n === 0 ) { echo '<p>No expired requests found.</p>'; return; }
        echo '<p><strong>Total expired:</strong> ' . number_format( $n ) . ( $n >= 1000 ? ' (showing the newest 1,000)' : '' ) . '</p>';
        ?>
        <div class="seal-table-wrap">
        <table class="rh-table seal-ss-table">
            <thead><tr><th scope="col">ILL #</th><th scope="col">Title</th><th scope="col">Requester</th><th scope="col">Destination</th><th scope="col">Date</th></tr></thead>
            <tbody>
            <?php while ( $r = $res->fetch_assoc() ) : ?>
                <tr>
                    <td><?php echo esc_html( $r['illNUB'] ); ?></td>
                    <td><?php echo esc_html( $r['Title'] ); ?></td>
                    <td><?php echo esc_html( $r['reqname'] ); ?></td>
                    <td><?php echo esc_html( $r['destname'] ); ?></td>
                    <td><?php echo esc_html( str_replace( '-', '/', substr( (string) $r['Timestamp'], 0, 10 ) ) ); ?></td>
                </tr>
            <?php endwhile; ?>
            </tbody>
        </table>
        </div>
        <?php
    }

    /**
     * Automatic Fallback usage. Reads the Rota tracking table (one row per request that had fallback turned
     * on, with its hop history) joined to STATS for how the request really ended: a request filled through
     * ILLiad or by the cron jobs doesn't always update the Rota row's own status, but STATS.Fill is always right.
     */
    private function rota_usage( string $from, string $to ): void {
        $tbl = SEAL_Rota::table();
        $res = SEAL_DB::query(
            "SELECT r.`illNUB`, r.`status`, r.`hop`, r.`history`, s.`Fill` AS fill
             FROM %t r LEFT JOIN %t s ON s.`illNUB` = r.`illNUB`
             WHERE r.`created` BETWEEN %s AND %s",
            $tbl, SEAL_DB::$sealSTAT, $from, $to
        );
        if ( ! $res ) {
            echo '<p>Automatic Fallback has not been used on this site yet' . ( SEAL_Rota::active() ? '' : ' (it is turned off under ILL &rarr; Modules)' ) . '.</p>';
            return;
        }
        $o = [ 'first' => 0, 'later' => 0, 'none' => 0, 'notfilled' => 0, 'waiting' => 0, 'cancelled' => 0 ];
        $moves = [ 'declined' => 0, 'expired' => 0 ]; $n = 0; $filled_at = []; $libs = [];
        while ( $r = $res->fetch_assoc() ) {
            $n++;
            $hop  = max( 1, (int) $r['hop'] );
            $fill = trim( (string) $r['fill'] );
            $hist = json_decode( (string) $r['history'], true ) ?: [];
            // Each finished hop that has a next one is a move; its outcome says why the request moved on.
            foreach ( $hist as $i => $h ) {
                if ( $i < count( $hist ) - 1 ) {
                    $why = (string) ( $h['outcome'] ?? '' );
                    if ( isset( $moves[ $why ] ) ) { $moves[ $why ]++; } else { $moves['declined']++; }
                }
                if ( $i >= 1 ) {   // a library reached by fallback (not the borrower's first pick)
                    $loc = (string) ( $h['loc'] ?? '' );
                    $libs[ $loc ]['name'] = (string) ( $h['name'] ?? $loc );
                    $libs[ $loc ]['reached'] = ( $libs[ $loc ]['reached'] ?? 0 ) + 1;
                    if ( $fill === '1' && $i === count( $hist ) - 1 ) { $libs[ $loc ]['filled'] = ( $libs[ $loc ]['filled'] ?? 0 ) + 1; }
                }
            }
            if ( $fill === '6' )                       { $o['cancelled']++; }
            elseif ( $fill === '1' )                   { $o[ $hop === 1 ? 'first' : 'later' ]++; $filled_at[ $hop ] = ( $filled_at[ $hop ] ?? 0 ) + 1; }
            elseif ( $r['status'] === 'exhausted' )    { $o['none']++; }
            elseif ( in_array( $fill, [ '0', '4' ], true ) ) { $o['notfilled']++; }
            else                                       { $o['waiting']++; }
        }
        // All requests placed in the period (cancelled left out, as everywhere on this page), for the share.
        $tq    = SEAL_DB::query( "SELECT COUNT(*) AS c FROM %t WHERE `Timestamp` BETWEEN %s AND %s AND (`Fill`<>'6' OR `Fill` IS NULL)", SEAL_DB::$sealSTAT, $from, $to );
        $all   = $tq ? (int) $tq->fetch_assoc()['c'] : 0;
        $used  = $n - $o['cancelled'];
        $pct   = static fn( int $a, int $b ): string => $b > 0 ? number_format( $a / $b * 100, 1 ) . '%' : '0.0%';

        if ( $n === 0 ) {
            echo '<p>No requests used Automatic Fallback in this date range.</p>';
        } else {
            $filled = $o['first'] + $o['later'];
            $rows = [
                [ 'Requests with fallback turned on', number_format( $used ), $pct( $used, $all ) . ' of ' . number_format( $all ) . ' requests' ],
                [ 'Filled by the first library', number_format( $o['first'] ), $pct( $o['first'], $used ) ],
                [ 'Filled after moving to another library', number_format( $o['later'] ), $pct( $o['later'], $used ) ],
                [ 'No library on the list could fill it', number_format( $o['none'] ), $pct( $o['none'], $used ) ],
                [ 'Not filled (other)', number_format( $o['notfilled'] ), $pct( $o['notfilled'], $used ) ],
                [ 'Still waiting for an answer', number_format( $o['waiting'] ), $pct( $o['waiting'], $used ) ],
                [ 'Cancelled (not counted above)', number_format( $o['cancelled'] ), '' ],
            ];
            echo '<table class="rh-table seal-ss-table"><thead><tr><th scope="col">Requests</th><th scope="col" class="num">Count</th><th scope="col" class="num">Share</th></tr></thead><tbody>';
            foreach ( $rows as [ $label, $count, $share ] ) {
                echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td class="num">' . esc_html( $count ) . '</td><td class="num">' . esc_html( $share ) . '</td></tr>';
            }
            echo '</tbody></table>';
            echo '<p><strong>Moves to another library:</strong> ' . number_format( $moves['declined'] + $moves['expired'] )
               . ' (' . number_format( $moves['declined'] ) . ' after a library declined, ' . number_format( $moves['expired'] ) . ' after no answer in 5 business days). '
               . '<strong>Rescued by fallback:</strong> ' . number_format( $o['later'] ) . ' request' . ( $o['later'] === 1 ? '' : 's' ) . ' filled that the first library did not fill'
               . ( $filled > 0 ? ' (' . $pct( $o['later'], $filled ) . ' of filled fallback requests)' : '' ) . '.</p>';
            if ( $libs ) {
                uasort( $libs, static fn( $a, $b ) => ( $b['reached'] <=> $a['reached'] ) ?: strcmp( $a['name'], $b['name'] ) );
                echo '<table class="rh-table seal-ss-table"><caption class="seal-sr-only">Libraries that received requests through fallback</caption><thead><tr><th scope="col">Library reached by fallback</th><th scope="col">LOC</th><th scope="col" class="num">Requests received</th><th scope="col" class="num">Filled</th></tr></thead><tbody>';
                foreach ( $libs as $loc => $l ) {
                    echo '<tr><td>' . esc_html( $l['name'] ) . '</td><td>' . esc_html( $loc ) . '</td><td class="num">' . number_format( (int) $l['reached'] ) . '</td><td class="num">' . number_format( (int) ( $l['filled'] ?? 0 ) ) . '</td></tr>';
                }
                echo '</tbody></table>';
            }
        }
        // Finished tracking rows are deleted after a while, so say how far back the numbers can go.
        $days = max( 1, (int) seal_config( 'rota_retention_days' ) );
        $eq   = SEAL_DB::query( 'SELECT MIN(`created`) AS m FROM %t', $tbl );
        $min  = $eq ? (string) ( $eq->fetch_assoc()['m'] ?? '' ) : '';
        echo '<p class="seal-ss-note">Finished fallback records are kept for ' . number_format( $days ) . ' days (ILL &rarr; Database &rarr; Rota: Keep Finished History), '
           . 'so a range reaching further back than that will be incomplete.' . ( $min !== '' ? ' Oldest record on file: ' . esc_html( substr( $min, 0, 10 ) ) . '.' : '' ) . '</p>';
    }

    /**
     * The ten libraries with the most requests matching $cond, counted on column $col (borrower or lender)
     * between $from and $to.
     */
    private function top10( string $col, string $cond, string $from, string $to, string $count_label ): void {
        $c   = SEAL_DB::esc_id( $col );
        $res = SEAL_DB::query(
            "SELECT s.{$c} AS loc, COALESCE(MAX(l.`Name`), s.{$c}) AS name, COUNT(*) AS cnt
             FROM %t s LEFT JOIN %t l ON l.`loc` = s.{$c}
             WHERE s.`Timestamp` BETWEEN %s AND %s AND s.{$c}<>'' AND ({$cond})
             GROUP BY s.{$c} ORDER BY cnt DESC, name ASC LIMIT 10",
            SEAL_DB::$sealSTAT, SEAL_DB::$sealLIB, $from, $to
        );
        if ( ! $res || $res->num_rows === 0 ) { echo '<p>No results found.</p>'; return; }
        ?>
        <table class="rh-table seal-ss-table">
            <thead><tr><th scope="col" class="num">Rank</th><th scope="col">LOC</th><th scope="col">Library</th><th scope="col" class="num"><?php echo esc_html( $count_label ); ?></th></tr></thead>
            <tbody>
            <?php $i = 0; while ( $r = $res->fetch_assoc() ) : $i++; ?>
                <tr><td class="num"><?php echo $i; ?></td><td><?php echo esc_html( $r['loc'] ); ?></td><td><?php echo esc_html( $r['name'] ); ?></td><td class="num"><?php echo number_format( (int) $r['cnt'] ); ?></td></tr>
            <?php endwhile; ?>
            </tbody>
        </table>
        <?php
    }
}
