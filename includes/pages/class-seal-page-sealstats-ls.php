<?php
/**
 * SEAL_Page_sealstats_ls — statistics for Library System staff (their own home system only).
 *
 * Five reports, laid out like SEAL-3's sealstats_ls.php: borrowing totals, lending totals,
 * list of expired requests, top 10 borrowing libraries, top 10 lending libraries. Each report
 * has its own date range; its result appears directly under it. The system is always taken from
 * the logged-in user's home system, never from the request.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class SEAL_Page_sealstats_ls extends SEAL_Page_Base {

    private const FILLS = [ '1' => 'Filled', '0' => 'Not Filled', '4' => 'Expired', '6' => 'Canceled', '3' => 'Not Answered' ];

    private string $home = '';
    private string $sys_label = '';

    /** Normalise a user-typed date to Y-m-d (falls back to $fallback). */
    private static function ymd( string $in, string $fallback ): string {
        $ts = strtotime( str_replace( '-', '/', trim( $in ) ) );
        return $ts ? date( 'Y-m-d', $ts ) : $fallback;
    }

    /**
     * Entry point: SEAL_Router calls this to print the page into the theme's content area. Statistics for the
     * libraries in the user's home system for a date range.
     */
    public function render(): void {
        $this->home = sanitize_text_field( (string) get_user_meta( $this->user->ID, 'home_system', true ) );
        if ( $this->home === '' ) {
            $this->alert( 'Your account does not have a Home Library System assigned.', 'error' );
            return;
        }
        $this->sys_label = SEAL_Functions::system_name( $this->home );

        $sections = [
            'wholesystem'        => [ 'Borrowing statistics',              'How your libraries did as borrowers: requests they sent and what happened to them.' ],
            'wholesystemlending' => [ 'Lending statistics',                'How your libraries did as lenders: requests sent to them and what happened to them.' ],
            'expirestats'        => [ 'List of expired requests',          'Requests that expired without an answer, borrowed or lent by your libraries.' ],
            'top10stats'         => [ 'List of top 10 borrowing libraries', 'Your libraries that sent the most requests.' ],
            'top10fstats'        => [ 'List of top 10 lending libraries',   'Your libraries that received the most requests.' ],
        ];

        $posted = '';
        $sd = $ed = '';
        if ( $this->is_post() ) {
            $this->verify_nonce( 'seal_stats' );
            $t = sanitize_key( $this->post_str( 'stattype' ) );
            if ( isset( $sections[ $t ] ) ) {
                $posted = $t;
                $sd = self::ymd( $this->post_str( 'startdate' ), date( 'Y-m-d', strtotime( '-1 year' ) ) );
                $ed = self::ymd( $this->post_str( 'enddate' ),   date( 'Y-m-d' ) );
            }
        }
        $default_sd = date( 'Y-m-d', strtotime( '-1 year' ) );
        $default_ed = date( 'Y-m-d' );
        $self = esc_url( home_url( '/stats-ls' ) );
        ?>
        <div class="seal-page-wrap seal-ss">
        <h3>Statistics for <?php echo esc_html( $this->sys_label ); ?></h3>
        <p class="seal-ss-note">Pick a date range for any report. Both dates are included.</p>
        <?php
        $ids = [];
        foreach ( $sections as $type => [ $title, $help ] ) :
            $is_this = ( $posted === $type );
            $v_sd = $is_this ? $sd : $default_sd;
            $v_ed = $is_this ? $ed : $default_ed;
            $ids[] = '#sd_' . $type . ', #ed_' . $type;
            ?>
        <section class="seal-ss-card seal-ss-wide" aria-labelledby="h_<?php echo esc_attr( $type ); ?>">
            <h3 id="h_<?php echo esc_attr( $type ); ?>"><?php echo esc_html( $title ); ?></h3>
            <p class="seal-ss-help"><?php echo esc_html( $help ); ?></p>
            <form method="post" action="<?php echo $self; ?>#h_<?php echo esc_attr( $type ); ?>" class="seal-ss-form">
                <?php $this->nonce_field( 'seal_stats' ); ?>
                <input type="hidden" name="stattype" value="<?php echo esc_attr( $type ); ?>">
                <div class="form-group"><label for="sd_<?php echo esc_attr( $type ); ?>">Start date</label>
                    <input type="text" id="sd_<?php echo esc_attr( $type ); ?>" name="startdate" value="<?php echo esc_attr( $v_sd ); ?>" autocomplete="off"></div>
                <div class="form-group"><label for="ed_<?php echo esc_attr( $type ); ?>">End date</label>
                    <input type="text" id="ed_<?php echo esc_attr( $type ); ?>" name="enddate" value="<?php echo esc_attr( $v_ed ); ?>" autocomplete="off"></div>
                <div class="form-group"><button type="submit" class="btn-primary">View</button></div>
            </form>
            <?php
            if ( $is_this ) {
                echo '<div class="seal-ss-result" aria-live="polite">';
                echo '<h4>' . esc_html( $title ) . ': ' . esc_html( $sd ) . ' to ' . esc_html( $ed ) . '</h4>';
                switch ( $type ) {
                    case 'wholesystem':        $this->totals( 'ReqSystem',  $sd, $ed ); break;
                    case 'wholesystemlending': $this->totals( 'DestSystem', $sd, $ed ); break;
                    case 'expirestats':        $this->expired( $sd, $ed ); break;
                    case 'top10stats':         $this->top10( 'Requester LOC', 'ReqSystem',  $sd, $ed, 'Requests sent' ); break;
                    case 'top10fstats':        $this->top10( 'Destination',   'DestSystem', $sd, $ed, 'Requests received' ); break;
                }
                echo '</div>';
            }
            ?>
        </section>
        <?php endforeach; ?>
        </div>
        <?php
        $this->datepicker_js( implode( ', ', $ids ) );
    }

    /** Start/end as full-day timestamps. */
    private static function range( string $sd, string $ed ): array { return [ $sd . ' 00:00:00', $ed . ' 23:59:59' ]; }

    /** Request totals by outcome for one side (borrowing = ReqSystem, lending = DestSystem). */
    private function totals( string $col, string $sd, string $ed ): void {
        [ $from, $to ] = self::range( $sd, $ed );
        $c   = SEAL_DB::esc_id( $col );
        $res = SEAL_DB::query(
            "SELECT IFNULL(`Fill`,'') AS f, COUNT(*) AS c FROM %t WHERE {$c}=%s AND `Timestamp` BETWEEN %s AND %s GROUP BY `Fill`",
            SEAL_DB::$sealSTAT, $this->home, $from, $to
        );
        $by = []; $total = 0;
        while ( $res && ( $r = $res->fetch_assoc() ) ) { $by[ (string) $r['f'] ] = (int) $r['c']; $total += (int) $r['c']; }
        if ( $total === 0 ) { echo '<p>No results found.</p>'; return; }

        $rows = [];
        foreach ( self::FILLS as $code => $label ) { $rows[ $label ] = $by[ $code ] ?? 0; }
        $known = array_sum( $rows );
        if ( $total - $known > 0 ) { $rows['Other'] = $total - $known; }
        ?>
        <table class="rh-table seal-ss-table">
            <thead><tr><th scope="col">Outcome</th><th scope="col" class="num">Requests</th><th scope="col" class="num">Percent</th></tr></thead>
            <tbody>
            <?php foreach ( $rows as $label => $n ) : ?>
                <tr><td><?php echo esc_html( $label ); ?></td><td class="num"><?php echo number_format( $n ); ?></td><td class="num"><?php echo esc_html( SEAL_Functions::pct2( $n, $total ) ); ?></td></tr>
            <?php endforeach; ?>
                <tr class="seal-ss-total"><th scope="row">Total requests</th><td class="num"><?php echo number_format( $total ); ?></td><td class="num">100%</td></tr>
            </tbody>
        </table>
        <?php
    }

    /** Requests that expired, on either side of your system. */
    private function expired( string $sd, string $ed ): void {
        [ $from, $to ] = self::range( $sd, $ed );
        $res = SEAL_DB::query(
            "SELECT s.`illNUB`, s.`Title`, s.`Author`, s.`Requester LOC`, s.`Destination`, s.`Requester lib`,
                    s.`Timestamp`, lender.`Name` AS lender_name, borrower.`Name` AS borrower_name
             FROM %t s
             LEFT JOIN %t lender   ON lender.`loc`   = s.`Destination`
             LEFT JOIN %t borrower ON borrower.`loc` = s.`Requester LOC`
             WHERE (s.`ReqSystem`=%s OR s.`DestSystem`=%s)
               AND s.`Timestamp` BETWEEN %s AND %s
               AND (s.`Fill`=4 OR s.`emailsent`=3 OR LOWER(COALESCE(s.`responderNOTE`,'')) LIKE '%expire%')
             ORDER BY s.`Timestamp` DESC LIMIT 500",
            SEAL_DB::$sealSTAT, SEAL_DB::$sealLIB, SEAL_DB::$sealLIB, $this->home, $this->home, $from, $to
        );
        $n = $res ? $res->num_rows : 0;
        if ( $n === 0 ) { echo '<p>No expired requests found.</p>'; return; }
        echo '<p>' . number_format( $n ) . ( $n >= 500 ? ' (showing the newest 500)' : '' ) . ' expired requests</p>';
        ?>
        <div class="seal-table-wrap">
        <table class="rh-table seal-ss-table">
            <thead><tr><th scope="col">ILL #</th><th scope="col">Title / Author</th><th scope="col">Borrower</th><th scope="col">Lender</th><th scope="col">Date</th></tr></thead>
            <tbody>
            <?php while ( $r = $res->fetch_assoc() ) : ?>
                <tr>
                    <td><?php echo esc_html( $r['illNUB'] ); ?></td>
                    <td><?php echo esc_html( $r['Title'] ); ?><br><em><?php echo esc_html( $r['Author'] ?? '' ); ?></em></td>
                    <td><?php echo esc_html( $r['borrower_name'] ?: ( $r['Requester lib'] ?? '' ) ); ?></td>
                    <td><?php echo esc_html( $r['lender_name'] ?: ( $r['Destination'] ?? '' ) ); ?></td>
                    <td><?php echo esc_html( str_replace( '-', '/', substr( (string) ( $r['Timestamp'] ?? '' ), 0, 10 ) ) ); ?></td>
                </tr>
            <?php endwhile; ?>
            </tbody>
        </table>
        </div>
        <?php
    }

    /** Ten busiest libraries of your system, by requests sent ($lib_col = Requester LOC) or received (Destination). */
    private function top10( string $lib_col, string $sys_col, string $sd, string $ed, string $count_label ): void {
        [ $from, $to ] = self::range( $sd, $ed );
        $lc  = SEAL_DB::esc_id( $lib_col );
        $sc  = SEAL_DB::esc_id( $sys_col );
        $res = SEAL_DB::query(
            "SELECT s.{$lc} AS loc, COUNT(*) AS c, MAX(l.`Name`) AS lib_name
             FROM %t s LEFT JOIN %t l ON l.`loc` = s.{$lc}
             WHERE s.{$sc}=%s AND s.`Timestamp` BETWEEN %s AND %s AND s.{$lc}<>''
             GROUP BY s.{$lc} ORDER BY c DESC, lib_name ASC LIMIT 10",
            SEAL_DB::$sealSTAT, SEAL_DB::$sealLIB, $this->home, $from, $to
        );
        if ( ! $res || $res->num_rows === 0 ) { echo '<p>No results found.</p>'; return; }
        ?>
        <table class="rh-table seal-ss-table">
            <thead><tr><th scope="col" class="num">Rank</th><th scope="col">Library</th><th scope="col" class="num"><?php echo esc_html( $count_label ); ?></th></tr></thead>
            <tbody>
            <?php $i = 0; while ( $r = $res->fetch_assoc() ) : $i++; ?>
                <tr><td class="num"><?php echo $i; ?></td><td><?php echo esc_html( $r['lib_name'] ?: $r['loc'] ); ?></td><td class="num"><?php echo number_format( (int) $r['c'] ); ?></td></tr>
            <?php endwhile; ?>
            </tbody>
        </table>
        <?php
    }
}
