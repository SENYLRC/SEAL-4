<?php
/**
 * SEAL_Page_requesthistory — /requesthistory, "Borrowing Requests" (capability seal_view_own).
 *
 * Lists the requests the user's library has made (Requester LOC = one of the user's LOC codes), with filters by
 * status, date range and ILL number. Each row offers the borrower's next step: Cancel (no answer yet), Mark
 * Received (filled), Request Renewal / Return Item (received), Print Slip. Ticked rows can be cancelled,
 * renewed, received or returned together through /bulkaction. Notes from both sides, renewal notes and return
 * details show under each row. Row buttons post to /status, /renew and /bulkaction (see
 * SEAL_Page_Base::row_action()).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class SEAL_Page_requesthistory extends SEAL_Page_Base {
    /** Entry point: SEAL_Router calls this to print the page into the theme's content area. */
    public function render(): void {
        $db        = SEAL_DB::get();
        $stat_tbl  = SEAL_DB::$sealSTAT;
        $lib_tbl   = SEAL_DB::$sealLIB;
        $uid       = $this->user->ID;

        // LOC scope
        $primary_loc = SEAL_Functions::primary_loc($uid);
        $all_locs    = SEAL_Functions::all_locs($uid);
        $has_multi   = count($all_locs) > 1;

        $filter_loc = sanitize_text_field( $this->req_str('filter_loc','') );
        if ($has_multi) {
            if ($filter_loc === '') { $filter_loc = 'all'; }
            if ($filter_loc !== 'all' && !in_array(strtoupper($filter_loc), $all_locs, true)) { $filter_loc = 'all'; }
        } else {
            $filter_loc = $all_locs[0] ?? $primary_loc;
        }

        // Filters
        $filter_yes      = $this->req_str('filter_yes',     'yes');
        $filter_no       = $this->req_str('filter_no',      'yes');
        $filter_noans    = $this->req_str('filter_noans',   'yes');
        $filter_expire   = $this->req_str('filter_expire',  '');
        $filter_cancel   = $this->req_str('filter_cancel',  '');
        $filter_recevied = $this->req_str('filter_recevied','');
        $filter_return   = $this->req_str('filter_return',  '');
        $filter_checkin  = $this->req_str('filter_checkin', '');
        $filter_days     = $this->req_str('filter_days',    '365');
        $filter_illnum   = $this->req_str('filter_illnum',  '');

        if ($filter_illnum !== '') {
            $filter_yes = $filter_no = $filter_noans = $filter_expire = $filter_cancel = $filter_recevied = $filter_return = $filter_checkin = 'yes';
            $filter_days = 'all';
        }

        // First-load defaults (when no form submitted)
        if (!isset($_REQUEST['filter_yes'])) {
            $filter_yes = $filter_no = $filter_noans = 'yes';
            $filter_expire = $filter_cancel = $filter_recevied = $filter_return = $filter_checkin = '';
            $filter_days = '365';
        }

        // Build WHERE for loc scope
        if ($filter_loc === 'all') {
            $in = implode(',', array_map(fn($l)=>"'".SEAL_DB::esc($l)."'", $all_locs));
            $where_loc = "`Requester LOC` IN ($in)";
        } else {
            $where_loc = "`Requester LOC`='" . SEAL_DB::esc(strtoupper($filter_loc)) . "'";
        }

        // Build status conditions
        $conds = [];
        if ($filter_yes      === 'yes') { $conds[] = "`Fill`=1"; }
        if ($filter_no       === 'yes') { $conds[] = "`Fill`=0"; }
        if ($filter_noans    === 'yes') { $conds[] = "`Fill`=3"; }
        if ($filter_expire   === 'yes') { $conds[] = "`Fill`=4"; }
        if ($filter_cancel   === 'yes') { $conds[] = "`Fill`=6"; }
        if ($filter_checkin  === 'yes') { $conds[] = "`checkinAccount` IS NOT NULL"; }
        if ($filter_recevied === 'yes') { $conds[] = "`receiveAccount` IS NOT NULL AND `returnAccount` IS NULL"; }
        if ($filter_return   === 'yes') { $conds[] = "`returnAccount` IS NOT NULL AND `checkinAccount` IS NULL"; }
        if (empty($conds)) { $conds[] = "`Fill`=''"; }
        $where_fill = "(" . implode(" OR ", $conds) . ")";

        $where_days = ($filter_days === 'all') ? '' : " AND (DATE(`Timestamp`) BETWEEN NOW() - INTERVAL " . max(1,(int)$filter_days) . " DAY AND NOW())";
        $where_ill  = (strlen($filter_illnum) > 2) ? " AND `illNUB` LIKE '%" . SEAL_DB::esc($filter_illnum) . "%'" : '';

        $sql = "SELECT *, DATE_FORMAT(`Timestamp`,'%Y/%m/%d') AS ts_fmt FROM `{$stat_tbl}` WHERE {$where_loc}{$where_days}{$where_ill} AND {$where_fill} ORDER BY `Timestamp` DESC";
        $rows = $db->query($sql);
        $total = $rows ? $rows->num_rows : 0;

        $self = esc_url(home_url('/requesthistory'));
        $status_url  = esc_url(home_url('/status'));
        $renew_url   = esc_url(home_url('/renew'));

        // Loc name map
        $loc_name_map = [];
        if (!empty($all_locs)) {
            $in = implode(',', array_map(fn($l)=>"'".SEAL_DB::esc($l)."'", $all_locs));
            $rn = $db->query("SELECT loc, Name FROM `{$lib_tbl}` WHERE loc IN ($in)");
            if ($rn) { while($rr=$rn->fetch_assoc()) { $loc_name_map[strtoupper($rr['loc'])]=$rr['Name']; } }
        }

        // Nofill map
        $nofill_map = ['0'=>'Not Specified','20'=>'In Use','21'=>'Lost','22'=>'Non-Circulating','23'=>'Not on shelf','24'=>'Poor condition','25'=>'Too New','26'=>'Not owned','27'=>'Not found as cited','28'=>'Lack volume/issue'];
        ?>
        <div class="seal-page-wrap">
        <div aria-live="polite" id="sr-status" style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0);"></div>
        <h3>Borrowing Requests Submitted By Your Library</h3>

        <form method="post" action="<?php echo $self; ?>" aria-label="Filter request history" class="seal-limit-form">
            <input type="hidden" name="loc" value="<?php echo esc_attr($primary_loc); ?>">
            <?php $this->nonce_field('seal_filter'); ?>

            <div class="section-card">
                <h4>Limit Results</h4>

                <?php if ($has_multi): ?>
                <div class="form-group" style="max-width:340px;margin-bottom:14px;">
                    <label for="filter_loc2">Library:</label>
                    <select id="filter_loc2" name="filter_loc">
                        <option value="all" <?php selected($filter_loc,'all'); ?>>All My Libraries</option>
                        <?php foreach ($all_locs as $lc):
                            $lbl = ($loc_name_map[strtoupper($lc)]??$lc) . " ($lc)"; ?>
                        <option value="<?php echo esc_attr($lc); ?>" <?php selected(strtoupper($filter_loc),strtoupper($lc)); ?>><?php echo esc_html($lbl); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php else: ?>
                <input type="hidden" name="filter_loc" value="<?php echo esc_attr($filter_loc); ?>">
                <?php endif; ?>

                <fieldset class="seal-status-fieldset">
                    <legend>By Fill Status</legend>
                    <div class="seal-checkbox-grid">
                        <label class="choice"><input type="checkbox" name="filter_yes"      value="yes" <?php checked($filter_yes,'yes'); ?>> Filled</label>
                        <label class="choice"><input type="checkbox" name="filter_no"       value="yes" <?php checked($filter_no,'yes'); ?>> Not Filled</label>
                        <label class="choice"><input type="checkbox" name="filter_noans"    value="yes" <?php checked($filter_noans,'yes'); ?>> Pending</label>
                        <label class="choice"><input type="checkbox" name="filter_expire"   value="yes" <?php checked($filter_expire,'yes'); ?>> Expired</label>
                        <label class="choice"><input type="checkbox" name="filter_cancel"   value="yes" <?php checked($filter_cancel,'yes'); ?>> Cancelled</label>
                        <label class="choice"><input type="checkbox" name="filter_recevied" value="yes" <?php checked($filter_recevied,'yes'); ?>> Received (not returned)</label>
                        <label class="choice"><input type="checkbox" name="filter_return"   value="yes" <?php checked($filter_return,'yes'); ?>> Returned (not checked in)</label>
                        <label class="choice"><input type="checkbox" name="filter_checkin"  value="yes" <?php checked($filter_checkin,'yes'); ?>> Checked In</label>
                    </div>
                </fieldset>

                <div class="seal-limit-controls">
                    <div class="form-group">
                        <label for="fdays">Time Frame</label>
                        <select id="fdays" name="filter_days">
                            <?php foreach(['30'=>'30 days','90'=>'90 days','180'=>'180 days','365'=>'365 days','all'=>'All time'] as $v=>$l): ?>
                            <option value="<?php echo esc_attr($v); ?>" <?php selected($filter_days,$v); ?>><?php echo esc_html($l); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="fillnum">ILL #</label>
                        <input type="text" id="fillnum" name="filter_illnum" value="<?php echo esc_attr($filter_illnum); ?>">
                    </div>
                </div>

                <div class="seal-limit-actions">
                    <a href="<?php echo $self; ?>" class="seal-btn-secondary">Reset Filters</a>
                    <span class="seal-or">OR</span>
                    <button type="submit" class="seal-btn-primary">Update Results</button>
                </div>
            </div>
        </form>

        <div class="section-card">
        <h4>Perform Bulk Action</h4>
        <form id="bulk_form" method="post" action="<?php echo esc_url(home_url('/bulkaction')); ?>">
            <?php wp_nonce_field('seal_bulkaction','seal_nonce'); ?>
            <label for="bulkaction" class="screen-reader-text">Bulk action</label>
            <select id="bulkaction" name="bulkaction" style="margin-right:8px;">
                <option value="">-- Select Action --</option>
                <option value="1">Cancel Requests</option>
                <option value="3">Receive Items</option>
                <option value="2">Renew Requests</option>
                <option value="4">Return Items</option>
            </select>
            <button id="bulkSubmit" type="submit" class="seal-btn-primary">Submit</button>

            <p aria-live="polite" style="margin-top:10px;"><strong><?php echo $total; ?></strong> results</p>

            <?php if ($total === 0): ?>
            <p>No results found.</p>
            <?php else: ?>
            <div class="seal-table-wrap"><table class="seal-table history-table">
            <thead><tr>
                <th scope="col"><label for="check_all" class="seal-sr-only">Select all</label><input type="checkbox" id="check_all"></th>
                <th scope="col">ILL #</th><th scope="col">Title / Author</th><th scope="col">Type / Need By</th>
                <th scope="col">Lender</th><th scope="col">Due Date / Ship</th><th scope="col">Date / Status</th><th scope="col">Action</th>
            </tr></thead>
            <tbody>
            <?php while ($r = $rows->fetch_assoc()):
                $ill  = $r['illNUB'];
                $fill = (int)($r['Fill']??0);
                $dest = $r['Destination']??'';
                // Lookup lender name
                $dest_q = SEAL_DB::query("SELECT Name, ill_email FROM %t WHERE loc=%s LIMIT 1", $lib_tbl, $dest);
                $dest_r = $dest_q ? $dest_q->fetch_assoc() : null;
                $lname  = $dest_r ? $dest_r['Name'] : $dest;
                $lemail = $dest_r ? $dest_r['ill_email'] : '';
                $shiptxt = SEAL_Functions::shipmt_txt($r['shipMethod']??'');
                $cb_id  = 'cb_' . preg_replace('/[^A-Za-z0-9_\-]/','_',$ill);
                $status_lbl = SEAL_Functions::fill_label($r['Fill'] ?? '');
            ?>
            <tr>
                <td><label for="<?php echo esc_attr($cb_id); ?>" class="screen-reader-text">Select ILL# <?php echo esc_html($ill); ?></label>
                    <input type="checkbox" id="<?php echo esc_attr($cb_id); ?>" class="check_item" name="check_list[]" value="<?php echo esc_attr($ill); ?>"></td>
                <td><?php echo esc_html($ill); ?></td>
                <td><?php echo esc_html($r['Title']); ?><br><em><?php echo esc_html($r['Author']??''); ?></em></td>
                <td><?php echo esc_html($r['itype']??''); ?><br><?php echo esc_html($r['needbydate']??''); ?></td>
                <td><?php echo esc_html($lname);
                    // A library may list several ILL addresses (";" or "," separated): one link per line so they wrap.
                    foreach ( array_filter( array_map( 'trim', preg_split( '/[;,]+/', (string) $lemail ) ) ) as $em ): ?><br><a class="seal-email" href="mailto:<?php echo esc_attr($em); ?>"><?php echo str_replace( '@', '@<wbr>', esc_html($em) ); ?></a><?php endforeach; ?></td>
                <td><?php echo esc_html($r['DueDate']??''); ?><?php if($shiptxt): ?><br><em><?php echo esc_html($shiptxt); ?></em><?php endif; ?></td>
                <td><?php echo esc_html($r['ts_fmt']??''); ?><br><?php echo esc_html($status_lbl); ?>
                    <?php if(!empty($r['IlliadTransID'])): ?><br><small><?php echo esc_html($r['IlliadTransID']); ?></small><?php endif; ?></td>
                <td>
                    <?php
                    // One container per cell so the buttons are evenly spaced.
                    $acts = [];
                    $renew_waiting = trim((string)($r['renewAccountRequester']??'')) !== '' && !in_array((string)($r['renewAnswer']??''), ['1','2'], true);
                    if ($fill==3) {
                        $acts[] = $this->row_action($status_url,'seal_status',['a'=>'6','num'=>$ill],'Cancel','seal-btn-danger seal-row-btn');
                    } elseif ($fill==1 && strlen($r['receiveAccount']??'')<2) {
                        $acts[] = $this->row_action($status_url,'seal_status',['a'=>'1','num'=>$ill],'Mark Received','seal-btn-primary seal-row-btn');
                    } elseif ($fill==1 && strlen($r['receiveAccount']??'')>1 && strlen($r['returnAccount']??'')<1) {
                        $acts[] = $renew_waiting
                            ? '<span class="seal-row-note">Renewal requested — waiting for the lender</span>'
                            : $this->row_action($renew_url,'seal_renew',['a'=>'0','num'=>$ill],'Request Renewal','seal-btn-secondary seal-row-btn');
                        $acts[] = $this->row_action($status_url,'seal_status',['a'=>'2','num'=>$ill],'Return Item','seal-btn-primary seal-row-btn');
                    }
                    if ($fill==1) { $acts[] = $this->slip_link($ill); }
                    if ($acts) { echo '<div class="seal-row-actions">'.implode('',$acts).'</div>'; } // parts built from escaped values
                    ?>
                </td>
            </tr>
            <?php
                // Notes subrows
                $notes = '';
                if (SEAL_Rota::active()) { $rota_line = SEAL_Rota::status_line($ill); if ($rota_line !== '') { $notes .= '<strong>'.esc_html($rota_line).'</strong><br>'; } }
                if (strlen($r['reqnote']??'')>2)       { $notes .= '<strong>Requester Note:</strong> '.esc_html($r['reqnote']).'<br>'; }
                if (strlen($r['patronnote']??'')>2)     { $notes .= '<strong>Patron Note:</strong> '.esc_html($r['patronnote']).'<br>'; }
                if (strlen($r['responderNOTE']??'')>2)  { $notes .= '<strong>Lender Note:</strong> '.esc_html($r['responderNOTE']).'<br>'; }
                $nfr = preg_replace('/\D+/','', $r['reasonNotFilled']??'');
                if ($nfr!=='' && $nfr!=='0' && $fill===0 && isset($nofill_map[$nfr])) { $notes .= '<strong>Reason Not Filled:</strong> '.esc_html($nofill_map[$nfr]).'<br>'; }
                if ($notes): ?>
            <tr><td></td><td></td><td colspan="6" style="background:#f9f9f9;padding:6px;border-left:3px solid #ccc;font-size:.9em;"><?php echo $notes; ?></td></tr>
            <?php endif;
                // Return/renewal notes
                $rn = '';
                if (strlen($r['returnNote']??'')>2)     { $rn .= '<strong>Return Note:</strong> '.esc_html($r['returnNote']).'<br>'; }
                if (strlen($r['returnmethod']??'')>2)   { $rn .= '<strong>Return Method:</strong> '.esc_html($r['returnmethod']).'<br>'; }
                if ($rn): ?>
            <tr><td></td><td></td><td colspan="6" style="background:#eef8ff;padding:6px;border-left:3px solid #6ba4d9;font-size:.9em;"><?php echo $rn; ?></td></tr>
            <?php endif;
                $rnl = '';
                if (strlen($r['renewNote']??'')>2)       { $rnl .= '<strong>Renewal Note (Borrower):</strong> '.esc_html($r['renewNote']).'<br>'; }
                if (strlen($r['renewNoteLender']??'')>2) { $rnl .= '<strong>Renewal Note (Lender):</strong> '.esc_html($r['renewNoteLender']); }
                if ($rnl): ?>
            <tr><td></td><td></td><td colspan="6" style="background:#f3fff1;padding:6px;border-left:3px solid #76c67a;font-size:.9em;"><?php echo $rnl; ?></td></tr>
            <?php endif; endwhile; ?>
            </tbody></table></div>
            <?php endif; ?>
        </form>
        <?php $this->flush_row_forms(); ?>
        </div><?php // .section-card (bulk action) ?>
        </div><?php // .seal-page-wrap ?>
        <script>
        (function(){
            var checkAll=document.getElementById('check_all');
            if(checkAll){checkAll.addEventListener('click',function(e){
                document.querySelectorAll('.check_item').forEach(function(cb){cb.checked=e.target.checked;});
            });}
        })();
        </script>
        <?php
    }
}
