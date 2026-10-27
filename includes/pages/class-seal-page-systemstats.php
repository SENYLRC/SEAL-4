<?php
/**
 * SEAL_Page_systemstats — /systemstats, "Overall System Statistics" (capability seal_systemstats).
 *
 * An overview for administrators: library counts (participating, suspended, missing an ILL email), libraries
 * and staff active in the last 30 days, whether the ILL database connection is encrypted (TLS cipher), and
 * requests borrowed and lent per library system for a date range.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class SEAL_Page_systemstats extends SEAL_Page_Base {
    /** COUNT(*) with an optional WHERE, on the library table (participant may be stored as 1 or "Yes"). */
    private function lib_count( string $where = '1=1' ): int {
        $res = SEAL_DB::query( "SELECT COUNT(*) AS c FROM %t WHERE {$where}", SEAL_DB::$sealLIB );
        $row = $res ? $res->fetch_assoc() : null;
        return (int) ( $row['c'] ?? 0 );
    }

    /** Number of distinct libraries seen in one STATS column over the last 30 days. */
    private function active_libs( string $column, string $extra = '' ): int {
        $col = SEAL_DB::esc_id( $column );
        $res = SEAL_DB::query( "SELECT COUNT(DISTINCT {$col}) AS c FROM %t WHERE `Timestamp` BETWEEN DATE_SUB(NOW(), INTERVAL 30 DAY) AND NOW() {$extra}", SEAL_DB::$sealSTAT );
        $row = $res ? $res->fetch_assoc() : null;
        return (int) ( $row['c'] ?? 0 );
    }

    /** part / whole as a whole-number percentage string, '0%' when whole is 0. */
    private static function pct( int $part, int $whole ): string {
        return number_format( $whole > 0 ? $part / $whole * 100 : 0, 2 ) . '%';
    }

    /** One encryption check: returns [ok, text]. */
    private function cipher_status( ?string $cipher ): string {
        if ( $cipher !== null && $cipher !== '' ) {
            return '<p class="seal-ss-ok"><span aria-hidden="true">&#10004;</span> Encrypted via <strong>' . esc_html( $cipher ) . '</strong></p>';
        }
        return '<p class="seal-ss-bad"><span aria-hidden="true">&#10008;</span> <strong>Not encrypted</strong> &mdash; this connection is sent in clear text.</p>';
    }

    /** The overview cards: libraries, recent activity and database encryption. */
    private function render_overview( string $sys ): void {
        global $wpdb;

        // Libraries. "Participating" = participant flag set (stored as 1 or "Yes").
        // Participant / suspended are numbers (1/0) here but 'Yes'/'No' text in older tables. Never compare a number column
        // with 'Yes': MySQL turns 'Yes' into 0, so `participant`='Yes' matched every non-participant. CAST AS CHAR works for both.
        $part_sql = "(CAST(`participant` AS CHAR) IN ('1','Yes'))";
        $total    = $this->lib_count();
        $closed   = $this->lib_count( "NOT {$part_sql} OR `ill_email`='' OR `alias`=''" );
        $open     = $total - $closed;
        $nonpart  = $this->lib_count( "NOT {$part_sql}" );
        $susp     = $this->lib_count( "CAST(`suspend` AS CHAR) IN ('1','Yes') AND {$part_sql}" );
        $no_email = $this->lib_count( "`ill_email`='' AND {$part_sql}" );
        $no_alias = $this->lib_count( "`alias`='' AND {$part_sql}" );

        $requested = $this->active_libs( 'Requester LOC' );
        $responded = $this->active_libs( 'Destination', 'AND `Fill`<2' );
        ?>
        <h2><?php echo $sys; ?> System Statistics</h2>

        <section class="seal-ss-card" aria-labelledby="ss-libs">
            <h3 id="ss-libs">Libraries</h3>
            <dl class="seal-ss-list">
                <dt>Total library count</dt><dd><?php echo number_format( $total ); ?></dd>
                <dt>Loaning libraries</dt><dd><?php echo number_format( $open ); ?> <span class="seal-ss-pct">(<?php echo self::pct( $open, $total ); ?>)</span></dd>
                <dt>Non-loaning libraries</dt><dd><?php echo number_format( $closed ); ?> <span class="seal-ss-pct">(<?php echo self::pct( $closed, $total ); ?>)</span></dd>
            </dl>
            <p class="seal-ss-sub">Why libraries are non-loaning, and what needs attention</p>
            <dl class="seal-ss-list seal-ss-nested">
                <dt>Non-participating libraries</dt><dd><?php echo number_format( $nonpart ); ?></dd>
                <dt>Suspended libraries</dt><dd><?php echo number_format( $susp ); ?></dd>
                <dt>Configuration problems: missing email</dt><dd class="<?php echo $no_email ? 'seal-ss-warn' : ''; ?>"><?php echo number_format( $no_email ); ?></dd>
                <dt>Configuration problems: missing alias</dt><dd class="<?php echo $no_alias ? 'seal-ss-warn' : ''; ?>"><?php echo number_format( $no_alias ); ?></dd>
            </dl>
        </section>

        <section class="seal-ss-card" aria-labelledby="ss-active">
            <h3 id="ss-active">Active library users (last 30 days)</h3>
            <dl class="seal-ss-list">
                <dt>Requested materials</dt><dd><?php echo number_format( $requested ); ?> <span class="seal-ss-pct">(<?php echo self::pct( $requested, $open ); ?>)</span></dd>
                <dt>Responded to requests</dt><dd><?php echo number_format( $responded ); ?> <span class="seal-ss-pct">(<?php echo self::pct( $responded, $open ); ?>)</span></dd>
            </dl>
            <p class="seal-ss-note">Percentages are of loaning libraries.</p>
        </section>

        <?php if ( SEAL_Roles::is_admin() ) :
            $wp_row  = ( isset( $wpdb ) && $wpdb ) ? $wpdb->get_row( "SHOW SESSION STATUS LIKE 'Ssl_cipher'" ) : null;
            $seal_r  = SEAL_DB::query( "SHOW SESSION STATUS LIKE 'Ssl_cipher'" );
            $seal_row = $seal_r ? $seal_r->fetch_assoc() : null;
            ?>
        <section class="seal-ss-card" aria-labelledby="ss-ssl">
            <h3 id="ss-ssl">Database SSL/TLS connection</h3>
            <h4>WordPress database</h4>
            <?php echo $this->cipher_status( $wp_row->Value ?? null ); // built from escaped parts ?>
            <h4>Application (ILL plugin) database</h4>
            <?php echo $this->cipher_status( $seal_row['Value'] ?? null ); // built from escaped parts ?>
        </section>
        <?php endif; ?>

        <h3>Requests by library system</h3>
        <?php
    }

    /** Entry point: SEAL_Router calls this to print the page into the theme's content area. */
    public function render(): void {
        $db       = SEAL_DB::get();
        $stat_tbl = SEAL_DB::$sealSTAT;
        $sys      = esc_html(seal_config('org_name') ?: 'System');
        $submitted = $this->is_post();
        $sd = sanitize_text_field($this->req_str('startdate', date('Y-m-d',strtotime('-1 year'))));
        $ed = sanitize_text_field($this->req_str('enddate',   date('Y-m-d')));
        $self = esc_url(home_url('/systemstats'));
        ?>
        <div class="seal-page-wrap seal-ss">
        <?php $this->render_overview( $sys ); ?>
        <form method="post" action="<?php echo $self; ?>">
            <?php $this->nonce_field('seal_stats'); ?>
            <label>Start: <input type="text" id="datepicker"  name="startdate" value="<?php echo esc_attr($sd); ?>" autocomplete="off" style="width:140px;"></label>&nbsp;
            <label>End:   <input type="text" id="datepicker2" name="enddate"   value="<?php echo esc_attr($ed); ?>" autocomplete="off" style="width:140px;"></label>&nbsp;
            <button type="submit" class="seal-btn-primary">View Statistics</button>
        </form>
        <?php if ($submitted):
            $sd_e = SEAL_DB::esc($sd.' 00:00:00'); $ed_e = SEAL_DB::esc($ed.' 23:59:59');
            $base  = "FROM `{$stat_tbl}` WHERE `Timestamp` BETWEEN '{$sd_e}' AND '{$ed_e}'";
            $total_q = $db->query("SELECT COUNT(*) AS c $base");
            $total   = $total_q ? (int)$total_q->fetch_assoc()['c'] : 0;
            echo "<h3>From ".esc_html($sd)." to ".esc_html($ed)."</h3>";
            if ($total===0) { echo '<p>No data.</p>'; }
            else {
                echo "<p><strong>Total requests:</strong> ".number_format($total)."</p>";
                $this->table_open(['System','Requests Borrowing','Requests Lending','Total']);
                foreach (SEAL_Functions::system_options() as $code => $name) {
                    $code_e = SEAL_DB::esc($code);
                    $bq = $db->query("SELECT COUNT(*) AS c $base AND `ReqSystem`='{$code_e}'");
                    $bc = $bq ? (int)$bq->fetch_assoc()['c'] : 0;
                    $lq = $db->query("SELECT COUNT(*) AS c $base AND `DestSystem`='{$code_e}'");
                    $lc = $lq ? (int)$lq->fetch_assoc()['c'] : 0;
                    if ($bc===0 && $lc===0) continue;
                    echo "<tr><td>".esc_html($name)."</td><td>".number_format($bc)."</td><td>".number_format($lc)."</td><td>".number_format($bc+$lc)."</td></tr>";
                }
                $this->table_close();
            }
        endif; ?>
        </div>
        <?php $this->datepicker_js('#datepicker, #datepicker2');
    }
}
