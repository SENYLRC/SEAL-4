<?php
/**
 * SEAL_Page_lenderhistory — /lender-history, "Lending Requests" (capability seal_view_own).
 *
 * Lists requests sent to the user's library (Destination = one of the user's LOC codes), with filters by
 * status, date range, ILL number and borrowing library. The Action column follows SEAL-3 lender-history.php:
 * - no answer yet → Yes, Will Fill / No, Can't Fill (/respond)
 * - Filled / Not Filled within 14 days of answering → Edit Fill Status (opens the opposite answer)
 * - received → Edit Due Date (/renew a=4), plus Check In after 14 days
 * - renewal waiting → Approve / Deny Renewal (/renew a=1|2) + Check In
 * - returned, or filled over 14 days ago → Check Item Back In (/status a=3)
 * - expired, canceled or checked in → nothing
 * Ticked rows can be marked Not Filled or checked in through /bulkaction, or printed as pull slips.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class SEAL_Page_lenderhistory extends SEAL_Page_Base {
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

        $filter_loc = sanitize_text_field($this->req_str('filter_loc',''));
        if ($has_multi) {
            if ($filter_loc === '') { $filter_loc = 'all'; }
            if ($filter_loc !== 'all' && !in_array(strtoupper($filter_loc),$all_locs,true)) { $filter_loc='all'; }
        } else {
            $filter_loc = $all_locs[0] ?? $primary_loc;
        }

        // Default filters
        $defaults = !isset($_REQUEST['filter_yes']);
        $filter_yes      = $defaults ? 'yes' : $this->req_str('filter_yes','');
        $filter_no       = $defaults ? 'yes' : $this->req_str('filter_no','');
        $filter_noans    = $defaults ? 'yes' : $this->req_str('filter_noans','');
        $filter_expire   = $defaults ? 'yes' : $this->req_str('filter_expire','');
        $filter_cancel   = $defaults ? 'yes' : $this->req_str('filter_cancel','');
        $filter_recevied = $this->req_str('filter_recevied','');
        $filter_return   = $this->req_str('filter_return','');
        $filter_checkin  = $this->req_str('filter_checkin','');
        $filter_renew    = $this->req_str('filter_renew','');
        $filter_days     = $this->req_str('filter_days','365');
        $filter_illnum   = $this->req_str('filter_illnum','');
        $filter_destination = $this->req_str('filter_destination','');

        if ($filter_illnum !== '') {
            $filter_yes=$filter_no=$filter_noans=$filter_expire=$filter_cancel=$filter_recevied=$filter_return=$filter_checkin=$filter_renew='yes';
            $filter_days='all';
        }

        // Build lender WHERE (Destination = user's LOC)
        if ($filter_loc === 'all') {
            $in = implode(',', array_map(fn($l)=>"'".SEAL_DB::esc($l)."'", $all_locs));
            $where_loc = "`Destination` IN ($in)";
        } else {
            $where_loc = "`Destination`='" . SEAL_DB::esc(strtoupper($filter_loc)) . "'";
        }

        // Destination (borrower) search
        $sql_dest_extra = '';
        if (strlen($filter_destination)>2) {
            $destq = $db->query("SELECT loc FROM `{$lib_tbl}` WHERE Name LIKE '%" . SEAL_DB::esc($filter_destination) . "%'");
            $dc = [];
            if ($destq) { while($drow=$destq->fetch_assoc()) { $dc[]="'".SEAL_DB::esc($drow['loc'])."'"; } }
            if (!empty($dc)) { $sql_dest_extra = " AND (`Requester LOC` IN (".implode(',',$dc)."))"; }
        }

        $sql_days = ($filter_days === 'all') ? '' : " AND (DATE(`Timestamp`) BETWEEN NOW() - INTERVAL ".max(1,(int)$filter_days)." DAY AND NOW())";
        $sql_ill  = (strlen($filter_illnum)>2) ? " AND `illNUB` LIKE '%".SEAL_DB::esc($filter_illnum)."%'" : '';

        $conds = [];
        if ($filter_yes==='yes')      { $conds[]='`Fill`=1'; }
        if ($filter_no==='yes')       { $conds[]='`Fill`=0'; }
        if ($filter_noans==='yes')    { $conds[]='`Fill`=3'; }
        if ($filter_expire==='yes')   { $conds[]='`Fill`=4'; }
        if ($filter_cancel==='yes')   { $conds[]='`Fill`=6'; }
        if ($filter_checkin==='yes')  { $conds[]='`checkinAccount` IS NOT NULL'; }
        if ($filter_recevied==='yes') { $conds[]='`receiveAccount` IS NOT NULL AND `returnAccount` IS NULL'; }
        if ($filter_return==='yes')   { $conds[]='`returnAccount` IS NOT NULL AND `checkinAccount` IS NULL'; }
        // Renew Pending = the borrower asked and the lender has not approved (1) or denied (2) yet.
        if ($filter_renew==='yes')    { $conds[]="(IFNULL(`renewAccountRequester`,'')<>'' AND IFNULL(`renewAnswer`,'') NOT IN ('1','2'))"; }
        if (empty($conds)) { $conds[]='`Fill`=\'\''; }

        $sql = "SELECT *, DATE_FORMAT(`Timestamp`,'%Y/%m/%d') AS ts_fmt FROM `{$stat_tbl}` WHERE {$where_loc}{$sql_dest_extra}{$sql_days}{$sql_ill} AND (".implode(' OR ',$conds).") ORDER BY `Timestamp` DESC";
        $rows = $db->query($sql);
        $total = $rows ? $rows->num_rows : 0;

        $self     = esc_url(home_url('/lender-history'));
        $status_url = esc_url(home_url('/status'));
        $renew_url  = esc_url(home_url('/renew'));
        $respond_url= esc_url(home_url('/respond'));

        // Loc name map
        $loc_name_map = [];
        if (!empty($all_locs)) {
            $in = implode(',', array_map(fn($l)=>"'".SEAL_DB::esc($l)."'", $all_locs));
            $rn = $db->query("SELECT loc, Name FROM `{$lib_tbl}` WHERE loc IN ($in)");
            if ($rn) { while($rr=$rn->fetch_assoc()) { $loc_name_map[strtoupper($rr['loc'])]=$rr['Name']; } }
        }
        ?>
        <div class="seal-page-wrap">
        <div aria-live="polite" id="sr-status" style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0);"></div>
        <h3>Lending History</h3>

        <form method="post" action="<?php echo $self; ?>" aria-label="Filter lending history">
            <input type="hidden" name="loc" value="<?php echo esc_attr($primary_loc); ?>">
            <?php $this->nonce_field('seal_filter'); ?>

            <?php if ($has_multi): ?>
            <label for="lh_loc" style="font-weight:bold;">Library:</label>
            <select id="lh_loc" name="filter_loc" style="margin:6px 0;">
                <option value="all" <?php selected($filter_loc,'all'); ?>>All My Libraries</option>
                <?php foreach ($all_locs as $lc):
                    $lbl = ($loc_name_map[strtoupper($lc)]??$lc)." ($lc)"; ?>
                <option value="<?php echo esc_attr($lc); ?>" <?php selected(strtoupper($filter_loc),strtoupper($lc)); ?>><?php echo esc_html($lbl); ?></option>
                <?php endforeach; ?>
            </select><br><br>
            <?php else: ?>
            <input type="hidden" name="filter_loc" value="<?php echo esc_attr($filter_loc); ?>">
            <?php endif; ?>

            <fieldset style="border:0;padding:0;margin:0;">
            <legend style="font-weight:bold;margin-bottom:6px;">Filter by Status</legend>
            <label><input type="checkbox" name="filter_yes"    value="yes" <?php checked($filter_yes,'yes'); ?>> Filled</label>&nbsp;
            <label><input type="checkbox" name="filter_no"     value="yes" <?php checked($filter_no,'yes'); ?>> Not Filled</label>&nbsp;
            <label><input type="checkbox" name="filter_noans"  value="yes" <?php checked($filter_noans,'yes'); ?>> Pending</label>&nbsp;
            <label><input type="checkbox" name="filter_expire" value="yes" <?php checked($filter_expire,'yes'); ?>> Expired</label>&nbsp;
            <label><input type="checkbox" name="filter_cancel" value="yes" <?php checked($filter_cancel,'yes'); ?>> Cancelled</label>&nbsp;
            <label><input type="checkbox" name="filter_checkin" value="yes" <?php checked($filter_checkin,'yes'); ?>> Checked In</label>&nbsp;
            <label><input type="checkbox" name="filter_recevied" value="yes" <?php checked($filter_recevied,'yes'); ?>> Received (not returned)</label>&nbsp;
            <label><input type="checkbox" name="filter_return"  value="yes" <?php checked($filter_return,'yes'); ?>> Returned (not checked in)</label>&nbsp;
            <label><input type="checkbox" name="filter_renew"   value="yes" <?php checked($filter_renew,'yes'); ?>> Renewal</label>
            </fieldset>

            <div style="margin-top:10px;display:flex;gap:12px;flex-wrap:wrap;align-items:center;">
                <div><label for="lh_days"><strong>Days back:</strong></label><br>
                    <select id="lh_days" name="filter_days">
                        <?php foreach(['30'=>'30 days','90'=>'90 days','180'=>'180 days','365'=>'365 days','all'=>'All time'] as $v=>$l): ?>
                        <option value="<?php echo esc_attr($v); ?>" <?php selected($filter_days,$v); ?>><?php echo esc_html($l); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div><label for="lh_ill"><strong>ILL #:</strong></label><br>
                    <input type="text" id="lh_ill" name="filter_illnum" value="<?php echo esc_attr($filter_illnum); ?>" style="width:140px;">
                </div>
                <div><label for="lh_dest"><strong>Requesting Library:</strong></label><br>
                    <input type="text" id="lh_dest" name="filter_destination" value="<?php echo esc_attr($filter_destination); ?>" style="width:200px;">
                </div>
            </div>
            <div style="margin-top:12px;">
                <button type="submit" class="seal-btn-primary">Update Results</button>
                <a href="<?php echo $self; ?>" class="seal-btn-secondary" style="margin-left:8px;">Reset</a>
            </div>
        </form>

        <hr>
        <h4>Bulk Action</h4>
        <form id="bulk_form" method="post" action="<?php echo esc_url(home_url('/bulkaction')); ?>">
            <?php wp_nonce_field('seal_bulkaction','seal_nonce'); ?>
            <label for="bulkaction" class="seal-sr-only">Bulk action</label>
            <select id="bulkaction" name="bulkaction" style="margin-right:8px;">
                <option value="">-- Select Action --</option>
                <option value="5">Mark Not Filled</option>
                <option value="6">Check Item Back In</option>
            </select>
            <button type="submit" class="seal-btn-primary">Submit</button>

            <button type="button" id="print_slips" class="seal-btn-secondary" style="margin-left:8px;">Print Pull Slips for Selected</button>

            <p aria-live="polite" style="margin-top:10px;"><?php echo $total; ?> results</p>

            <?php if ($total === 0): ?>
            <p>No results found.</p>
            <?php else: ?>
            <div class="seal-table-wrap"><table class="seal-table history-table">
            <thead><tr>
                <th scope="col"><label for="check_all" class="seal-sr-only">Select all</label><input type="checkbox" id="check_all"></th>
                <th scope="col">ILL #</th><th scope="col">Title / Author</th><th scope="col">Type / Need By</th>
                <th scope="col">Borrower</th><th scope="col">Due Date / Ship</th><th scope="col">Date / Status</th><th scope="col">Action</th>
            </tr></thead>
            <tbody>
            <?php while ($r = $rows->fetch_assoc()):
                $ill      = $r['illNUB'];
                // Fill kept as a string: '' (legacy) and '3' are both "no answer yet"; an int cast would turn '' into 0 (Not Filled).
                $fill_raw = trim((string)($r['Fill']??''));
                $fill     = $fill_raw === '' ? 3 : (int)$fill_raw;
                $receive  = trim((string)($r['receiveAccount']??''));
                $ret      = trim((string)($r['returnAccount']??''));
                $checkin  = trim((string)($r['checkinAccount']??''));
                $renewReq = trim((string)($r['renewAccountRequester']??''));
                $renewPending = $renewReq !== '' && !in_array((string)($r['renewAnswer']??''), ['1','2'], true);
                $daysdiff = (time()-strtotime($r['Timestamp']??'now'))/86400;
                // A Filled / Not Filled answer can be changed for 14 days after it was given
                // (the answer date, or the request date for older rows that have none).
                $answered = (string)($r['fillNofillDate']??'');
                $answered_ts = ($answered !== '' && $answered !== '0000-00-00') ? strtotime($answered) : false;
                $days_since_answer = (time()-($answered_ts ?: strtotime($r['Timestamp']??'now')))/86400;
                $shiptxt  = SEAL_Functions::shipmt_txt($r['shipMethod']??'');
                $cb_id    = 'cb_'.preg_replace('/[^A-Za-z0-9_\-]/','_',$ill);
                $status_color = $fill===1?'color:#1a7a1a;font-weight:bold;':($fill===0?'color:#b91c1c;font-weight:bold;':'');
            ?>
            <tr>
                <td><label for="<?php echo esc_attr($cb_id); ?>" class="screen-reader-text">Select ILL# <?php echo esc_html($ill); ?></label>
                    <input type="checkbox" id="<?php echo esc_attr($cb_id); ?>" class="check_item" name="check_list[]" value="<?php echo esc_attr($ill); ?>"></td>
                <td><?php echo esc_html($ill); ?></td>
                <td><?php echo esc_html($r['Title']); ?><br><em><?php echo esc_html($r['Author']??''); ?></em></td>
                <td><?php echo esc_html($r['itype']??''); ?><br><?php echo esc_html($r['needbydate']??''); ?></td>
                <td><?php echo esc_html($r['Requester person']??''); ?><br>
                    <a href="mailto:<?php echo esc_attr($r['requesterEMAIL']??''); ?>?Subject=ILL%23%20<?php echo rawurlencode($ill); ?>"><?php echo esc_html($r['Requester lib']??''); ?></a></td>
                <td><?php echo esc_html($r['DueDate']??''); ?><?php if($shiptxt): ?><br><em><?php echo esc_html($shiptxt); ?></em><?php endif; ?></td>
                <td><?php echo esc_html($r['ts_fmt']??''); ?><br>
                    <span style="<?php echo esc_attr($status_color); ?>"><?php echo esc_html(SEAL_Functions::fill_label($r['Fill'] ?? '')); ?></span>
                    <?php if(!empty($r['IlliadTransID'])): ?><br><small><?php echo esc_html($r['IlliadTransID']); ?></small><?php endif; ?></td>
                <td>
                <?php
                // Actions, as SEAL-3 lender-history.php. One container per cell so the buttons are evenly spaced.
                $acts = [];
                $check_in = fn(string $label, string $cls) => $this->row_action($status_url,'seal_status',['num'=>$ill,'a'=>'3'],$label,$cls.' seal-row-btn');
                if ($checkin !== '' || $fill === 4 || $fill === 6) {
                    // Checked in, expired or canceled: nothing left to do.
                } elseif ($renewPending && $ret === '' && $fill === 1) {
                    $acts[] = $this->row_action($renew_url,'seal_renew',['num'=>$ill,'a'=>'1'],'Approve Renewal','seal-btn-primary seal-row-btn');
                    $acts[] = $this->row_action($renew_url,'seal_renew',['num'=>$ill,'a'=>'2'],'Deny Renewal','seal-btn-secondary seal-row-btn');
                    $acts[] = $check_in('Check Item Back In','seal-btn-secondary');
                } elseif ($ret !== '') {
                    $acts[] = $check_in('Check Item Back In','seal-btn-primary');
                } elseif ($receive !== '' && $fill === 1) {
                    $acts[] = $this->row_action($renew_url,'seal_renew',['num'=>$ill,'a'=>'4'],'Edit Due Date','seal-btn-secondary seal-row-btn');
                    if ($daysdiff > 14) { $acts[] = $check_in('Check Item Back In','seal-btn-primary'); }
                } elseif (($fill === 0 || $fill === 1) && $days_since_answer <= 14) {
                    // Filled / Not Filled within 14 days: the answer can still be changed.
                    $to   = $fill === 1 ? '0' : '1';
                    $lbl  = $fill === 1 ? "Change to No, Can't Fill" : 'Change to Yes, Will Fill';
                    $acts[] = '<details class="seal-row-toggle"><summary class="seal-btn-secondary seal-row-btn">Edit Fill Status'
                            . '<span class="seal-sr-only"> for ILL # '.esc_html($ill).'</span></summary>'
                            . $this->row_action($respond_url,'seal_respond',['num'=>$ill,'fill'=>$to,'FromLender'=>'1'],$lbl,($fill===1?'seal-btn-danger':'seal-btn-primary').' seal-row-btn')
                            . '</details>';
                } elseif ($fill === 1 && $daysdiff > 14) {
                    $acts[] = $check_in('Check Item Back In','seal-btn-primary');
                } elseif ($fill === 3) {
                    $acts[] = $this->row_action($respond_url,'seal_respond',['num'=>$ill,'fill'=>'1','FromLender'=>'1'],'Yes, Will Fill','seal-btn-primary seal-row-btn');
                    $acts[] = $this->row_action($respond_url,'seal_respond',['num'=>$ill,'fill'=>'0','FromLender'=>'1'],"No, Can't Fill",'seal-btn-secondary seal-row-btn');
                }
                if ($fill === 1 && $checkin === '') { $acts[] = $this->slip_link($ill); }
                echo $acts ? '<div class="seal-row-actions">'.implode('',$acts).'</div>' : '&nbsp;'; // parts built from escaped values
                ?>
                </td>
            </tr>
            <?php
                // Notes
                $notes='';
                if (SEAL_Rota::active()) { $rota_line = SEAL_Rota::status_line($ill); if ($rota_line !== '') { $notes.='<strong>'.esc_html($rota_line).'</strong><br>'; } }
                if (strlen($r['reqnote']??'')>2)       { $notes.='<strong>Requester Note:</strong> '.esc_html($r['reqnote']).'<br>'; }
                if (strlen($r['responderNOTE']??'')>2)  { $notes.='<strong>Lender Note:</strong> '.esc_html($r['responderNOTE']).'<br>'; }
                if ($notes): ?>
            <tr><td></td><td></td><td colspan="6" style="background:#f9f9f9;padding:6px;border-left:3px solid #ccc;font-size:.9em;"><?php echo $notes; ?></td></tr>
            <?php endif;
                $rn='';
                if (strlen($r['returnNote']??'')>2)    { $rn.='<strong>Return Note:</strong> '.esc_html($r['returnNote']).'<br>'; }
                if (strlen($r['returnMethod']??'')>2)  { $rn.='<strong>Return Method:</strong> '.esc_html($r['returnMethod']).'<br>'; }
                if ($rn): ?>
            <tr><td></td><td></td><td colspan="6" style="background:#eef8ff;padding:6px;border-left:3px solid #6ba4d9;font-size:.9em;"><?php echo $rn; ?></td></tr>
            <?php endif;
                $rnl='';
                if (strlen($r['renewNote']??'')>0)       { $rnl.='<strong>Renewal Note (Borrower):</strong> '.esc_html($r['renewNote']).'<br>'; }
                if (strlen($r['renewNoteLender']??'')>0) { $rnl.='<strong>Renewal Note (Lender):</strong> '.esc_html($r['renewNoteLender']); }
                if ($rnl): ?>
            <tr><td></td><td></td><td colspan="6" style="background:#f3fff1;padding:6px;border-left:3px solid #76c67a;font-size:.9em;"><?php echo $rnl; ?></td></tr>
            <?php endif;
            endwhile; ?>
            </tbody></table></div>
            <?php endif; ?>
        </form>
        <?php $this->flush_row_forms(); ?>
        </div>
        <script>
        (function(){var pb=document.getElementById('print_slips');if(pb){pb.addEventListener('click',function(){var n=[];document.querySelectorAll('.check_item:checked').forEach(function(c){n.push(c.value);});if(!n.length){alert('Select at least one request first.');return;}window.open(<?php echo wp_json_encode(home_url('/pullslip')); ?>+'?num='+encodeURIComponent(n.join(',')),'_blank','noopener');});}})();
        (function(){var ca=document.getElementById('check_all');if(ca){ca.addEventListener('click',function(e){document.querySelectorAll('.check_item').forEach(function(c){c.checked=e.target.checked;});});} })();
        </script>
        <?php
    }
}
