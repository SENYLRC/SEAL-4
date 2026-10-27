<?php
/**
 * SEAL_Page_rota — ILL Admin: Automatic Fallback (Rota) status.
 *
 * Read-only view of every rota-enrolled request: which library currently has it, how many
 * libraries have been tried, and the full history (who declined it or timed out, when, and why).
 * Administrators only — a decline reason can be frank staff feedback about another library.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class SEAL_Page_rota extends SEAL_Page_Base {

    private const PER_PAGE = 50;

    /**
     * Entry point: SEAL_Router calls this to print the page into the theme's content area. Read-only list of
     * requests using Automatic Fallback: current library, attempt number and full history.
     */
    public function render(): void {
        $tbl = SEAL_Rota::table();
        $probe = SEAL_DB::query( "SELECT `illNUB` FROM %t LIMIT 1", $tbl );
        if ( $probe === false ) {
            echo '<div class="seal-page-wrap">';
            if ( SEAL_Rota::active() ) {
                $this->alert( 'The Rota table could not be read yet. It is created automatically the first time a request uses Automatic Fallback — nothing has used it yet.', 'info' );
            } else {
                $this->alert( 'Automatic Fallback (Rota) is turned off. Turn it on under ILL &rarr; Modules to let requests use it.', 'info' );
            }
            echo '</div>';
            return;
        }

        $self   = esc_url( home_url( '/rota' ) );
        $f_stat = sanitize_key( $this->get_str( 'status', 'active' ) );
        if ( ! in_array( $f_stat, [ 'active', 'exhausted', 'all' ], true ) ) { $f_stat = 'active'; }
        $f_q = sanitize_text_field( $this->get_str( 'q' ) );
        $pg  = max( 1, $this->req_int( 'pg', 1 ) );

        $where = '1=1';
        if ( $f_stat !== 'all' ) { $where .= " AND r.`status`='" . SEAL_DB::esc( $f_stat ) . "'"; }
        if ( $f_q !== '' ) {
            $qe = SEAL_DB::esc( $f_q );
            $where .= " AND (r.`illNUB` LIKE '%{$qe}%' OR s.`Title` LIKE '%{$qe}%' OR s.`Requester lib` LIKE '%{$qe}%')";
        }

        $stat_tbl = SEAL_DB::esc_id( SEAL_DB::$sealSTAT );
        $rota_tbl = SEAL_DB::esc_id( $tbl );
        $join     = "FROM {$rota_tbl} r LEFT JOIN {$stat_tbl} s ON s.`illNUB`=r.`illNUB`";

        $cq    = SEAL_DB::get()->query( "SELECT COUNT(*) AS c {$join} WHERE {$where}" );
        $total = $cq ? (int) $cq->fetch_assoc()['c'] : 0;
        $pages = max( 1, (int) ceil( $total / self::PER_PAGE ) );
        $pg    = min( $pg, $pages );
        $rows  = SEAL_DB::get()->query(
            "SELECT r.*, s.`Title`, s.`Author`, s.`Requester lib`, s.`Fill`
             {$join} WHERE {$where} ORDER BY r.`updated` DESC LIMIT " . ( ( $pg - 1 ) * self::PER_PAGE ) . ',' . self::PER_PAGE
        );
        $qs = array_filter( [ 'status' => $f_stat !== 'active' ? $f_stat : '', 'q' => $f_q ] );
        ?>
        <div class="seal-page-wrap">
        <h3>Automatic Fallback (Rota) — Status</h3>
        <p class="seal-ss-note">Every request enrolled in automatic fallback: which library has it now, how many have been tried, and the full history of declines and timeouts. The patron note is never shown here.</p>

        <form method="get" action="<?php echo $self; ?>" class="filter-bar">
            <div class="filter-grid">
                <div class="form-group"><label for="rota_q">ILL #, title, or requester</label><input id="rota_q" type="text" name="q" value="<?php echo esc_attr( $f_q ); ?>"></div>
                <div class="form-group"><label for="rota_status">Status</label>
                    <select id="rota_status" name="status">
                        <option value="active"    <?php selected( $f_stat, 'active' );    ?>>In progress</option>
                        <option value="exhausted" <?php selected( $f_stat, 'exhausted' ); ?>>Exhausted (none could fill it)</option>
                        <option value="all"       <?php selected( $f_stat, 'all' );       ?>>All</option>
                    </select>
                </div>
            </div>
            <div class="filter-actions">
                <button type="submit" class="btn-primary">Search</button>
                <a class="btn-secondary" href="<?php echo $self; ?>">Reset</a>
            </div>
        </form>

        <div class="pagination-info"><?php echo number_format( $total ); ?> requests</div>

        <div class="seal-table-wrap">
        <table class="rh-table">
            <thead><tr>
                <th scope="col">ILL #</th><th scope="col">Title</th><th scope="col">Requester</th>
                <th scope="col">Status</th><th scope="col">Progress</th><th scope="col">Current library</th><th scope="col">Last update</th>
            </tr></thead>
            <tbody>
            <?php if ( ! $rows || $total === 0 ) : ?>
                <tr><td colspan="7">No requests found.</td></tr>
            <?php else : while ( $r = $rows->fetch_assoc() ) :
                $history = json_decode( (string) ( $r['history'] ?? '' ), true ) ?: [];
                $queue   = json_decode( (string) ( $r['queue']   ?? '' ), true ) ?: [];
                $hop     = (int) ( $r['hop'] ?? 1 );
                $total_n = $hop + count( $queue );
                $current = end( $history ) ?: [];
                $status_label = $r['status'] === 'exhausted' ? 'Exhausted' : 'In progress';
                ?>
                <tr>
                    <td><?php echo esc_html( $r['illNUB'] ); ?></td>
                    <td><?php echo esc_html( $r['Title'] ?? '' ); ?><?php if ( ! empty( $r['Author'] ) ) : ?><br><em style="font-size:.85rem"><?php echo esc_html( $r['Author'] ); ?></em><?php endif; ?></td>
                    <td><?php echo esc_html( $r['Requester lib'] ?? '' ); ?></td>
                    <td><span class="<?php echo $r['status'] === 'exhausted' ? 'status-no' : 'status-yes'; ?>"><?php echo esc_html( $status_label ); ?></span></td>
                    <td><?php echo $r['status'] === 'exhausted' ? esc_html( 'Tried ' . $hop . ' of ' . $hop ) : esc_html( "Library $hop of $total_n" ); ?></td>
                    <td><?php echo esc_html( $current['name'] ?? $current['loc'] ?? '' ); ?></td>
                    <td><?php echo esc_html( (string) ( $r['updated'] ?? '' ) ); ?></td>
                </tr>
                <?php if ( $history ) : ?>
                <tr class="rh-subrow rh-notes-row">
                    <td colspan="7" class="rh-subcell">
                        <details>
                            <summary>History (<?php echo count( $history ); ?> librar<?php echo count( $history ) === 1 ? 'y' : 'ies'; ?><?php echo $queue ? ', ' . count( $queue ) . ' still queued' : ''; ?>)</summary>
                            <div class="rh-subwrap">
                                <ol style="margin:8px 0 0 18px;">
                                    <?php foreach ( $history as $h ) : ?>
                                    <li style="margin-bottom:6px;">
                                        <strong><?php echo esc_html( $h['name'] ?? $h['loc'] ?? '' ); ?></strong>
                                        — sent <?php echo esc_html( (string) ( $h['sent'] ?? '' ) ); ?><br>
                                        <?php if ( ! empty( $h['outcome'] ) ) : ?>
                                            <?php echo $h['outcome'] === 'declined' ? 'Declined' : 'Timed out (no answer)'; ?>
                                            <?php echo esc_html( (string) ( $h['outcome_at'] ?? '' ) ); ?>
                                            <?php if ( ! empty( $h['note'] ) ) : ?> — <em><?php echo esc_html( (string) $h['note'] ); ?></em><?php endif; ?>
                                        <?php else : ?>
                                            <em>Currently with this library, awaiting a response.</em>
                                        <?php endif; ?>
                                    </li>
                                    <?php endforeach; ?>
                                    <?php foreach ( $queue as $q ) : ?>
                                    <li style="margin-bottom:6px;color:#50575e;">
                                        <?php echo esc_html( $q['loc'] ?? '' ); ?> — <em>not tried yet</em>
                                    </li>
                                    <?php endforeach; ?>
                                </ol>
                            </div>
                        </details>
                    </td>
                </tr>
                <?php endif; ?>
            <?php endwhile; endif; ?>
            </tbody>
        </table>
        </div>

        <?php if ( $pages > 1 ) : ?>
        <div style="margin-top:12px;display:flex;gap:8px;align-items:center;">
            <?php if ( $pg > 1 ) : ?><a class="btn-secondary" href="<?php echo esc_url( add_query_arg( $qs + [ 'pg' => $pg - 1 ], $self ) ); ?>">&larr; Previous</a><?php endif; ?>
            <span>Page <?php echo $pg; ?> of <?php echo $pages; ?></span>
            <?php if ( $pg < $pages ) : ?><a class="btn-primary" href="<?php echo esc_url( add_query_arg( $qs + [ 'pg' => $pg + 1 ], $self ) ); ?>">Next &rarr;</a><?php endif; ?>
        </div>
        <?php endif; ?>
        </div>
        <?php
    }
}
