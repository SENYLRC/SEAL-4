<?php
/**
 * SEAL_Page_allrequests_ls — /allrequests-ls, "System Requests" (capability seal_view_system).
 *
 * Every request to or from a library in the user's home library system (home_system profile field), with
 * filters for date range, ILL number, title, lender and status, status badges, and the notes under each
 * request. The system-wide equivalent for administrators is SEAL_Page_allrequests (/allrequests).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class SEAL_Page_allrequests_ls extends SEAL_Page_Base {
    /** Entry point: SEAL_Router calls this to print the page into the theme's content area. */
    public function render(): void {
        $db       = SEAL_DB::get();
        $stat_tbl = SEAL_DB::$sealSTAT;
        $lib_tbl  = SEAL_DB::$sealLIB;
        $uid      = $this->user->ID;

        $home_system = sanitize_text_field( (string) get_user_meta( $uid, 'home_system', true ) );
        if ( empty( $home_system ) ) {
            $this->alert( 'No home system set on your account. Please update your profile.', 'error' );
            return;
        }

        $sd     = sanitize_text_field( $this->get_str( 'filter_startdate', date('Y-m-d', strtotime('-1 year')) ) );
        $ed     = sanitize_text_field( $this->get_str( 'filter_enddate',   date('Y-m-d') ) );
        $illnum = sanitize_text_field( $this->get_str( 'filter_ill'   ) );
        $title  = sanitize_text_field( $this->get_str( 'filter_title' ) );
        $lender = sanitize_text_field( $this->get_str( 'filter_lender') );
        $status = sanitize_text_field( $this->get_str( 'filter_status') );

        $hs = SEAL_DB::esc( $home_system );
        $sd_e = SEAL_DB::esc($sd); $ed_e = SEAL_DB::esc($ed);

        $sql = "SELECT s.*, DATE_FORMAT(s.`Timestamp`,'%Y/%m/%d') AS ts_fmt,
                       lender.`Name` AS lender_name, borrower.`Name` AS borrower_name
                FROM `{$stat_tbl}` s
                LEFT JOIN `{$lib_tbl}` lender  ON s.`Destination`    = lender.`loc`
                LEFT JOIN `{$lib_tbl}` borrower ON s.`Requester LOC` = borrower.`loc`
                WHERE (s.`ReqSystem`='{$hs}' OR s.`DestSystem`='{$hs}')
                  AND DATE(s.`Timestamp`) BETWEEN '{$sd_e}' AND '{$ed_e}'";

        if ( $illnum ) { $sql .= " AND s.`illNUB` LIKE '%" . SEAL_DB::esc($illnum) . "%'"; }
        if ( $title  ) { $sql .= " AND s.`Title`  LIKE '%" . SEAL_DB::esc($title)  . "%'"; }
        if ( $lender ) { $sql .= " AND (lender.`Name` LIKE '%" . SEAL_DB::esc($lender) . "%' OR s.`Destination` LIKE '%" . SEAL_DB::esc($lender) . "%')"; }
        if ( $status === 'filled'    ) { $sql .= " AND s.`Fill`=1"; }
        elseif ( $status === 'notfilled' ) { $sql .= " AND s.`Fill`=0"; }
        elseif ( $status === 'pending'   ) { $sql .= " AND (s.`Fill` IS NULL OR s.`Fill`=3)"; }
        elseif ( $status === 'expired'   ) { $sql .= " AND (s.`emailsent`=3 OR LOWER(COALESCE(s.`responderNOTE`,'')) LIKE '%expire%')"; }

        $sql .= " ORDER BY s.`Timestamp` DESC LIMIT 500";
        $rows = $db->query( $sql );
        $total = $rows ? $rows->num_rows : 0;

        $self      = esc_url( home_url('/allrequests-ls') );
        $sys_label = esc_html( SEAL_Functions::system_name($home_system) );
        $nofill_map = ['0'=>'Not specified','20'=>'In Use','21'=>'Lost','22'=>'Non-Circulating',
                       '23'=>'Not on shelf','24'=>'Poor condition','25'=>'Too New',
                       '26'=>'Not owned','27'=>'Not found as cited','28'=>'Lack volume/issue'];
        ?>
        <div class='adminlib-wrapper'>

        <div class='adminlib-header'>
            <h3><?php echo $sys_label; ?> System Requests</h3>
        </div>

        <form method='get' action='<?php echo $self; ?>' class='filter-bar'>
            <div class='filter-grid'>
                <div class='form-group'>
                    <label for='fsd2'>Start Date</label>
                    <input id='fsd2' name='filter_startdate' value='<?php echo esc_attr($sd); ?>' autocomplete='off'>
                </div>
                <div class='form-group'>
                    <label for='fed2'>End Date</label>
                    <input id='fed2' name='filter_enddate' value='<?php echo esc_attr($ed); ?>' autocomplete='off'>
                </div>
                <div class='form-group'>
                    <label for='fill2'>ILL #</label>
                    <input id='fill2' name='filter_ill' value='<?php echo esc_attr($illnum); ?>'>
                </div>
                <div class='form-group'>
                    <label for='ftitle2'>Title</label>
                    <input id='ftitle2' name='filter_title' value='<?php echo esc_attr($title); ?>'>
                </div>
                <div class='form-group'>
                    <label for='flend2'>Lender</label>
                    <input id='flend2' name='filter_lender' value='<?php echo esc_attr($lender); ?>'>
                </div>
                <div class='form-group'>
                    <label for='fstat2'>Status</label>
                    <select id='fstat2' name='filter_status'>
                        <option value=''>Any</option>
                        <option value='filled'    <?php selected($status,'filled');    ?>>Filled</option>
                        <option value='notfilled' <?php selected($status,'notfilled'); ?>>Not Filled</option>
                        <option value='pending'   <?php selected($status,'pending');   ?>>Pending</option>
                        <option value='expired'   <?php selected($status,'expired');   ?>>Expired</option>
                    </select>
                </div>
            </div>
            <div class='filter-actions'>
                <button type='submit' class='btn-primary'>Search</button>
                <a href='<?php echo $self; ?>' class='btn-secondary'>Reset</a>
            </div>
        </form>

        <?php if ( $total === 0 ): ?>
        <div class='seal-alert seal-alert-info'>No results found for the selected filters.</div>
        <?php else: ?>
        <div class='pagination-info'><?php echo number_format($total); ?> total results<?php echo $total >= 500 ? ' (showing the newest 500; narrow the dates or filters to see others)' : ''; ?></div>

        <div class='seal-table-wrap'>
        <table class='rh-table allreq-table allreq-ls'>
            <thead><tr>
                <th scope="col">ILL #</th>
                <th scope="col">Title / Author</th>
                <th scope="col">Need By</th>
                <th scope="col">Lender</th>
                <th scope="col">Borrower</th>
                <th scope="col">Due Date / Shipping</th>
                <th scope="col">Timestamp</th>
                <th scope="col">Status / ILLiad #</th>
            </tr></thead>
            <tbody>
            <?php while ( $r = $rows->fetch_assoc() ):
                $fill_raw  = (string)($r['Fill'] ?? '');
                $shiptxt   = SEAL_Functions::shipmt_txt( $r['shipMethod'] ?? '' );
                $emailsent = (int)($r['emailsent'] ?? 0);
                $rnote     = strtolower((string)($r['responderNOTE'] ?? ''));
                $badge     = null;
                if ( $fill_raw === '1' )                                       { $badge = ['filled',    '✅ Filled',        'Request filled successfully']; }
                elseif ( $fill_raw === '0' )                                   { $badge = ['notfilled', '❌ Not Filled',    'Request not filled']; }
                elseif ( $emailsent === 2 || str_contains($rnote,'reminder') ) { $badge = ['reminder',  '⚠️ Reminder Sent', '3-Day Reminder sent automatically']; }
                elseif ( $emailsent === 3 || str_contains($rnote,'expire') )   { $badge = ['expired',   '⏰ Expired',       '5-Day Expired automatically']; }
            ?>
            <tr>
                <td><?php echo esc_html($r['illNUB']); ?></td>
                <td><?php echo esc_html($r['Title']); ?><br><em style='font-size:.85rem'><?php echo esc_html($r['Author'] ?? ''); ?></em></td>
                <td><?php echo esc_html($r['needbydate'] ?? ''); ?></td>
                <td><?php echo esc_html($r['lender_name'] ?: ($r['Destination'] ?? '')); ?></td>
                <td><?php echo esc_html($r['borrower_name'] ?: ($r['Requester lib'] ?? '')); ?><?php if (!empty($r['Requester person'])): ?><br><em style='font-size:.85rem'><?php echo esc_html($r['Requester person']); ?></em><?php endif; ?></td>
                <td><?php echo esc_html($r['DueDate'] ?? ''); ?></td>
                <td><?php echo esc_html($r['ts_fmt'] ?? ''); ?></td>
                <td class='status-cell'>
                    <div class='status-text'><?php echo SEAL_Functions::item_status($r); // escaped in item_status() ?></div>
                    <?php if ($shiptxt): ?><div class='shiptxt'><?php echo esc_html($shiptxt); ?></div><?php endif; ?>
                    <?php if ($badge): ?><div class='status-badge <?php echo esc_attr($badge[0]); ?>' title='<?php echo esc_attr($badge[2]); ?>'><?php echo esc_html($badge[1]); ?></div><?php endif; ?>
                    <?php if (!empty($r['IlliadTransID'])): ?><div class='illiad-id'><strong>ILLiad ID:</strong> <?php echo esc_html($r['IlliadTransID']); ?></div><?php endif; ?>
                </td>
            </tr>
            <?php
                // Notes subrow: same notes as SEAL-3 (the patron note is never shown)
                $notes = [];
                foreach ( [ 'Request Note' => 'reqnote', 'Lender Note' => 'responderNOTE', 'Return Note' => 'returnNote',
                            'Return Method' => 'returnmethod', 'Renew Note (Borrower)' => 'renewNote',
                            'Renew Note (Lender)' => 'renewNoteLender' ] as $lbl => $col ) {
                    $v = trim( (string)( $r[$col] ?? '' ) );
                    if ( $v !== '' ) { $notes[$lbl] = $v; }
                }
                $reason = trim( (string)( $r['reasonNotFilled'] ?? '' ) );
                if ( $reason !== '' && isset($nofill_map[$reason]) && ( $fill_raw === '0' || $reason !== '0' ) ) {
                    $notes['Reason Not Filled'] = $nofill_map[$reason];
                }
                if ( ! empty($notes) ):
            ?>
            <tr class='rh-subrow rh-notes-row'>
                <td colspan='8' class='rh-subcell'>
                    <div class='rh-subwrap'>
                        <div class='rh-subtitle'><strong>Notes</strong> <span class='rh-muted'>(<?php echo count($notes); ?>)</span></div>
                        <div class='rh-notes-grid'>
                            <?php foreach ($notes as $lbl => $val): ?>
                            <div class='rh-note-item<?php echo $lbl === 'Reason Not Filled' ? ' rh-note-warning' : ''; ?>'>
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
        <?php endif; ?>

        </div>
        <?php
        $this->datepicker_js('#fsd2, #fed2');
    }
}
