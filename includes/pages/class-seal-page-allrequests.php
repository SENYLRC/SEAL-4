<?php
/**
 * SEAL_Page_allrequests — All Requests view (ILL Admin)
 * Full filter bar + rh-table to match production site style.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class SEAL_Page_allrequests extends SEAL_Page_Base {
    /**
     * Entry point: SEAL_Router calls this to print the page into the theme's content area. Every request in the
     * service with filters, status badges, notes and a Resend link per request.
     */
    public function render(): void {
        $db       = SEAL_DB::get();
        $stat_tbl = SEAL_DB::$sealSTAT;
        $lib_tbl  = SEAL_DB::$sealLIB;

        // ── Filters ──────────────────────────────────────────────────────────
        $sd     = sanitize_text_field( $this->req_str( 'filter_startdate', date('Y-m-d', strtotime('-1 year') ) ) );
        $ed     = sanitize_text_field( $this->req_str( 'filter_enddate',   date('Y-m-d') ) );
        $system = sanitize_text_field( $this->req_str( 'filter_system'   ) );
        $title  = sanitize_text_field( $this->req_str( 'filter_title'    ) );
        $illnum = sanitize_text_field( $this->req_str( 'filter_illnum'   ) );
        $lender = sanitize_text_field( $this->req_str( 'filter_lender'   ) );
        $borrow = sanitize_text_field( $this->req_str( 'filter_borrower' ) );
        $status = sanitize_text_field( $this->req_str( 'filter_status'   ) );
        if ( ! in_array( $status, [ '', 'filled', 'notfilled', 'cancel', 'expired' ], true ) ) { $status = ''; }
        $numres = sanitize_text_field( $this->req_str( 'filter_numresults', '25' ) );
        if ( ! in_array( $numres, [ '25', '50', '100', 'all' ], true ) ) { $numres = '25'; }
        $pg = max( 1, $this->req_int( 'pg', 1 ) );

        // ── Build WHERE ───────────────────────────────────────────────────────
        $conds = [];
        $sd_e  = SEAL_DB::esc( $sd ); $ed_e = SEAL_DB::esc( $ed );
        $conds[] = "s.`Timestamp` BETWEEN '{$sd_e} 00:00:00' AND '{$ed_e} 23:59:59'";
        if ( $title  ) { $conds[] = "s.`Title` LIKE '%"          . SEAL_DB::esc($title)  . "%'"; }
        if ( $illnum ) { $conds[] = "s.`illNUB` = '"             . SEAL_DB::esc($illnum) . "'"; }
        if ( $system ) { $conds[] = "(s.`ReqSystem`='"           . SEAL_DB::esc($system) . "' OR s.`DestSystem`='" . SEAL_DB::esc($system) . "')"; }
        if ( $borrow ) { $conds[] = "(s.`Requester person` LIKE '%" . SEAL_DB::esc($borrow) . "%' OR s.`Requester lib` LIKE '%" . SEAL_DB::esc($borrow) . "%')"; }
        if ( $lender ) { $conds[] = "(s.`Destination` LIKE '%"   . SEAL_DB::esc($lender) . "%' OR l.`Name` LIKE '%" . SEAL_DB::esc($lender) . "%')"; }
        if      ( $status === 'filled'    ) { $conds[] = "s.`Fill`=1"; }
        elseif  ( $status === 'notfilled' ) { $conds[] = "s.`Fill`=0"; }
        elseif  ( $status === 'cancel'    ) { $conds[] = "(s.`Fill`=2 OR LOWER(COALESCE(s.`responderNOTE`,'')) LIKE '%cancel%')"; }
        elseif  ( $status === 'expired'   ) { $conds[] = "(s.`emailsent`=3 OR LOWER(COALESCE(s.`responderNOTE`,'')) LIKE '%expire%')"; }

        $where    = implode( ' AND ', $conds );
        $join     = "FROM `{$stat_tbl}` s LEFT JOIN `{$lib_tbl}` l ON l.`loc`=s.`Destination`";

        // ── Count ─────────────────────────────────────────────────────────────
        $count_q = $db->query( "SELECT COUNT(*) AS total {$join} WHERE {$where}" );
        $total   = $count_q ? (int) $count_q->fetch_assoc()['total'] : 0;

        // ── Pagination ─────────────────────────────────────────────────────────
        if ( $numres === 'all' ) {
            $limit = max(1,$total); $total_pages = 1; $pg = 1; $offset = 0;
        } else {
            $limit       = (int) $numres;
            $total_pages = max( 1, (int) ceil( $total / $limit ) );
            $pg          = min( $pg, $total_pages );
            $offset      = ( $pg - 1 ) * $limit;
        }

        $data_sql = "SELECT s.*, DATE_FORMAT(s.`Timestamp`,'%Y/%m/%d') AS ts_fmt, l.`Name` AS lender_name
                     {$join} WHERE {$where} ORDER BY s.`Timestamp` DESC";
        if ( $numres !== 'all' ) { $data_sql .= " LIMIT {$offset}, {$limit}"; }
        $rows = $db->query( $data_sql );

        $self     = esc_url( home_url('/allrequests') );
        $sys_name = esc_html( seal_config('system_name') );
        $systems  = SEAL_Functions::system_options();
        $nofill_map = ['0'=>'Not specified','20'=>'In Use','21'=>'Lost','22'=>'Non-Circulating',
                       '23'=>'Not on shelf','24'=>'Poor condition','25'=>'Too New',
                       '26'=>'Not owned','27'=>'Not found as cited','28'=>'Lack volume/issue'];
        ?>
        <div class='adminlib-wrapper'>

        <div class='adminlib-header'>
            <h3><?php echo $sys_name; ?> — All Requests</h3>
            <div class='actions'>
                <a class='btn-secondary' href='<?php echo esc_url( home_url('/export') ); ?>'>Export CSV</a>
            </div>
        </div>

        <form method='get' action='<?php echo $self; ?>' class='filter-bar'>
            <input type='hidden' name='pg' value='1'>
            <div class='filter-grid'>
                <div class='form-group'>
                    <label for='fsd'>Start Date</label>
                    <input id='fsd' name='filter_startdate' value='<?php echo esc_attr($sd); ?>' autocomplete='off'>
                </div>
                <div class='form-group'>
                    <label for='fed'>End Date</label>
                    <input id='fed' name='filter_enddate' value='<?php echo esc_attr($ed); ?>' autocomplete='off'>
                </div>
                <div class='form-group'>
                    <label for='fld_filter_system'>Library System</label>
                    <select id='fld_filter_system' name='filter_system'>
                        <option value=''>All</option>
                        <?php foreach ( $systems as $code => $name ): ?>
                        <option value='<?php echo esc_attr($code); ?>' <?php selected($system,$code); ?>><?php echo esc_html($name); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class='form-group'>
                    <label for='fld_filter_title'>Title</label>
                    <input id='fld_filter_title' name='filter_title' value='<?php echo esc_attr($title); ?>'>
                </div>
                <div class='form-group'>
                    <label for='fld_filter_illnum'>ILL #</label>
                    <input id='fld_filter_illnum' name='filter_illnum' value='<?php echo esc_attr($illnum); ?>'>
                </div>
                <div class='form-group'>
                    <label for='fld_filter_lender'>Lender</label>
                    <input id='fld_filter_lender' name='filter_lender' value='<?php echo esc_attr($lender); ?>'>
                </div>
                <div class='form-group'>
                    <label for='fld_filter_borrower'>Borrower</label>
                    <input id='fld_filter_borrower' name='filter_borrower' value='<?php echo esc_attr($borrow); ?>'>
                </div>
                <div class='form-group'>
                    <label for='fld_filter_status'>Status</label>
                    <select id='fld_filter_status' name='filter_status'>
                        <option value=''>All</option>
                        <option value='filled'    <?php selected($status,'filled');    ?>>Filled</option>
                        <option value='notfilled' <?php selected($status,'notfilled'); ?>>Not Filled</option>
                        <option value='cancel'    <?php selected($status,'cancel');    ?>>Cancelled</option>
                        <option value='expired'   <?php selected($status,'expired');   ?>>Expired</option>
                    </select>
                </div>
                <div class='form-group'>
                    <label for='fld_filter_numresults'>Results per page</label>
                    <select id='fld_filter_numresults' name='filter_numresults'>
                        <?php foreach ( ['25','50','100','all'] as $opt ): ?>
                        <option value='<?php echo esc_attr($opt); ?>' <?php selected($numres,$opt); ?>><?php echo esc_html($opt==='all'?'All':$opt); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class='filter-actions'>
                <button type='submit' class='btn-primary'>Update Results</button>
                <a href='<?php echo $self; ?>' class='btn-secondary'>Reset</a>
            </div>
        </form>

        <div class='pagination-info'>Total Results: <?php echo number_format($total); ?></div>

        <?php if ( ! $rows || $total === 0 ): ?>
        <div class='seal-alert seal-alert-info'>No results found for the selected filters.</div>
        <?php else: ?>

        <div class='seal-table-wrap'>
        <table class='rh-table allreq-table'>
            <thead><tr>
                <th scope="col">ILL #</th>
                <th scope="col">Title / Author</th>
                <th scope="col">Type</th>
                <th scope="col">Need By</th>
                <th scope="col">Lender</th>
                <th scope="col">Borrower</th>
                <th scope="col">Due Date / Shipping</th>
                <th scope="col">Timestamp</th>
                <th scope="col">Status / ILLiad #</th>
            </tr></thead>
            <tbody>
            <?php while ( $r = $rows->fetch_assoc() ):
                $fill_raw    = (string)($r['Fill'] ?? '');
                $lender_name = $r['lender_name'] ?: ($r['Destination']??'');
                $shiptxt     = SEAL_Functions::shipmt_txt( $r['shipMethod']??'' );
                $emailsent   = (int)($r['emailsent'] ?? 0);
                $rnote       = strtolower((string)($r['responderNOTE'] ?? ''));
                $badge       = null;
                if ( $fill_raw === '1' )                                                   { $badge = ['filled',    '✅ Filled',        'Request filled successfully']; }
                elseif ( $fill_raw === '0' )                                               { $badge = ['notfilled', '❌ Not Filled',    'Request not filled']; }
                elseif ( $emailsent === 2 || str_contains($rnote,'reminder') )             { $badge = ['reminder',  '⚠️ Reminder Sent', '3-Day Reminder sent automatically']; }
                elseif ( $emailsent === 3 || str_contains($rnote,'expire') )               { $badge = ['expired',   '⏰ Expired',       '5-Day Expired automatically']; }
            ?>
            <tr>
                <td><?php echo esc_html($r['illNUB']); ?></td>
                <td><?php echo esc_html($r['Title']); ?><br><em style='font-size:.85rem'><?php echo esc_html($r['Author']??''); ?></em></td>
                <td><?php echo esc_html($r['Itype'] ?? $r['itype'] ?? ''); ?></td>
                <td><?php echo esc_html($r['needbydate']??''); ?></td>
                <td><?php echo esc_html($lender_name); ?></td>
                <td><?php echo esc_html($r['Requester person']??''); ?><br><?php echo esc_html($r['Requester lib']??''); ?></td>
                <td><?php echo esc_html($r['DueDate']??''); ?></td>
                <td><?php echo esc_html($r['ts_fmt']??''); ?></td>
                <td class='status-cell'>
                    <div class='status-text'><?php echo SEAL_Functions::item_status($r); // escaped in item_status() ?></div>
                    <?php if ($shiptxt): ?><div class='shiptxt'><?php echo esc_html($shiptxt); ?></div><?php endif; ?>
                    <?php if ($badge): ?><div class='status-badge <?php echo esc_attr($badge[0]); ?>' title='<?php echo esc_attr($badge[2]); ?>'><?php echo esc_html($badge[1]); ?></div><?php endif; ?>
                    <?php if(!empty($r['IlliadTransID'])): ?><div class='illiad-id'><strong>ILLiad ID:</strong> <?php echo esc_html($r['IlliadTransID']); ?></div><?php endif; ?>
                    <div class='seal-resend-link'><a href="<?php echo esc_url( add_query_arg( 'num', $r['illNUB'], home_url( '/resend' ) ) ); ?>">Resend<span class="seal-sr-only"> <?php echo esc_html($r['illNUB']); ?></span></a></div>
                </td>
            </tr>
            <?php
                // Notes subrow (same notes and order as SEAL-3 allrequests.php)
                $notes = [];
                $note_fields = [
                    'Request Note'          => 'reqnote',
                    'Lender Note'           => 'responderNOTE',
                    'Return Note'           => 'returnNote',
                    'Return Method'         => 'returnmethod',
                    'Renew Note (Borrower)' => 'renewNote',
                    'Renew Note (Lender)'   => 'renewNoteLender',
                ];
                foreach ( $note_fields as $lbl => $col ) {
                    $v = trim( (string)( $r[$col] ?? '' ) );
                    if ( $v !== '' ) { $notes[$lbl] = $v; }
                }
                // "Not specified" (0) only counts when the request is actually Not Filled.
                $reason = trim( (string)( $r['reasonNotFilled'] ?? '' ) );
                if ( $reason !== '' && isset($nofill_map[$reason]) && ( $fill_raw === '0' || $reason !== '0' ) ) {
                    $notes['Reason Not Filled'] = $nofill_map[$reason];
                }
                if ( ! empty($notes) ):
            ?>
            <tr class='rh-subrow rh-notes-row'>
                <td colspan='9' class='rh-subcell'>
                    <div class='rh-subwrap'>
                        <div class='rh-subtitle'><strong>Notes</strong> <span class='rh-muted'>(<?php echo count($notes); ?>)</span></div>
                        <div class='rh-notes-grid'>
                            <?php foreach ($notes as $lbl => $val): ?>
                            <div class='rh-note-item'>
                                <div class='rh-note-label'><?php echo esc_html($lbl); ?></div>
                                <div class='rh-note-text'><?php echo nl2br(esc_html($val)); ?></div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </td>
            </tr>
            <?php endif; endwhile; ?>
            </tbody>
        </table>
        </div>

        <?php if ( $numres !== 'all' && $total_pages > 1 ):
            $qs = array_filter(['filter_startdate'=>$sd,'filter_enddate'=>$ed,'filter_system'=>$system,
                                'filter_title'=>$title,'filter_illnum'=>$illnum,'filter_lender'=>$lender,
                                'filter_borrower'=>$borrow,'filter_status'=>$status,'filter_numresults'=>$numres]);
        ?>
        <div style='margin-top:12px;display:flex;gap:8px;align-items:center;'>
            <?php if ($pg>1): $pqs=$qs; $pqs['pg']=$pg-1; ?>
            <a href='<?php echo esc_url(add_query_arg($pqs,$self)); ?>' class='btn-secondary'>← Previous</a>
            <?php else: ?><span class='btn-secondary' style='opacity:.5'>← Previous</span><?php endif; ?>
            <span>Page <?php echo $pg; ?> of <?php echo $total_pages; ?></span>
            <?php if ($pg<$total_pages): $nqs=$qs; $nqs['pg']=$pg+1; ?>
            <a href='<?php echo esc_url(add_query_arg($nqs,$self)); ?>' class='btn-primary'>Next →</a>
            <?php else: ?><span class='btn-primary' style='opacity:.5'>Next →</span><?php endif; ?>
        </div>
        <?php endif; endif; ?>

        </div>
        <?php
        $this->datepicker_js('#fsd, #fed');
    }
}
