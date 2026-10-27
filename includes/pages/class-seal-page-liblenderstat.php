<?php
/**
 * SEAL_Page_liblenderstat — /liblenderstat, "Lending Statistics" (capability seal_view_own).
 *
 * Counts requests sent to the user's library (as lender) by status for a date range (default: the last year),
 * with a library selector for users who have more than one LOC code.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class SEAL_Page_liblenderstat extends SEAL_Page_Base {
    /** Entry point: SEAL_Router calls this to print the page into the theme's content area. */
    public function render(): void {
        $db        = SEAL_DB::get();
        $stat_tbl  = SEAL_DB::$sealSTAT;
        $lib_tbl   = SEAL_DB::$sealLIB;
        $uid       = $this->user->ID;

        $primary_loc = SEAL_Functions::primary_loc($uid);
        $all_locs    = SEAL_Functions::all_locs($uid);
        $has_multi   = count($all_locs) > 1;

        $loc_name_map = [];
        if (!empty($all_locs)) {
            $in = implode(',', array_map(fn($l)=>"'".SEAL_DB::esc($l)."'", $all_locs));
            $rn = $db->query("SELECT loc, Name FROM `{$lib_tbl}` WHERE loc IN ($in)");
            if ($rn) { while($rr=$rn->fetch_assoc()) { $loc_name_map[strtoupper($rr['loc'])]=$rr['Name']; } }
        }

        $submitted  = $this->is_post();
        $filter_loc = sanitize_text_field($this->req_str('filter_loc',''));
        if ($has_multi && $filter_loc==='') { $filter_loc='all'; }
        if (!$has_multi) { $filter_loc = $all_locs[0]??$primary_loc; }
        // A chosen library must be one of the user's own (never trust the posted value).
        if ($has_multi && $filter_loc !== 'all' && !in_array(strtoupper($filter_loc), $all_locs, true)) { $filter_loc = 'all'; }

        $sd_raw    = sanitize_text_field($this->req_str('startdate', date('Y-m-d',strtotime('-1 year'))));
        $ed_raw    = sanitize_text_field($this->req_str('enddate',   date('Y-m-d')));
        $startdate = SEAL_DB::esc($sd_raw . ' 00:00:00');
        $enddate   = SEAL_DB::esc($ed_raw . ' 23:59:59');
        $self      = esc_url(home_url('/liblenderstat'));
        ?>
        <div class="seal-page-wrap">
        <h2>Lending Statistics</h2>
        <form method="post" action="<?php echo $self; ?>" aria-label="Lending statistics date range">
            <?php $this->nonce_field('seal_stats'); ?>
            <?php if ($has_multi): ?>
            <label for="ls_loc">Library:</label>
            <select id="ls_loc" name="filter_loc" style="margin:6px 0;">
                <option value="all" <?php selected($filter_loc,'all'); ?>>All My Libraries</option>
                <?php foreach ($all_locs as $lc): ?>
                <option value="<?php echo esc_attr($lc); ?>" <?php selected(strtoupper($filter_loc),strtoupper($lc)); ?>><?php echo esc_html(($loc_name_map[strtoupper($lc)]??$lc)." ($lc)"); ?></option>
                <?php endforeach; ?>
            </select><br>
            <?php else: ?>
            <input type="hidden" name="filter_loc" value="<?php echo esc_attr($filter_loc); ?>">
            <?php endif; ?>
            <label for="datepicker">Start Date:</label>
            <input type="text" id="datepicker"  name="startdate" value="<?php echo esc_attr($sd_raw); ?>" autocomplete="off" style="width:140px;">&nbsp;
            <label for="datepicker2">End Date:</label>
            <input type="text" id="datepicker2" name="enddate"   value="<?php echo esc_attr($ed_raw); ?>" autocomplete="off" style="width:140px;">
            <br><br><button type="submit" class="seal-btn-primary">View Statistics</button>
        </form>

        <?php if ($submitted):
            if ($has_multi && $filter_loc === 'all') {
                $in = implode(',', array_map(fn($l)=>"'".SEAL_DB::esc($l)."'", $all_locs));
                $where_loc = "`Destination` IN ($in)";
                $libname   = 'All My Libraries';
            } else {
                $chosen    = strtoupper($filter_loc ?: $primary_loc);
                $where_loc = "`Destination`='" . SEAL_DB::esc($chosen) . "'";
                $libname   = $loc_name_map[$chosen] ?? $chosen;
            }
            $base  = "FROM `{$stat_tbl}` WHERE {$where_loc} AND `Timestamp` BETWEEN '{$startdate}' AND '{$enddate}'";
            $total_q = $db->query("SELECT COUNT(*) AS c $base");
            $total   = $total_q ? (int)$total_q->fetch_assoc()['c'] : 0;

            if ($total === 0): ?>
            <div class="seal-alert seal-alert-info" style="margin-top:16px;">No results found.</div>
            <?php else:
                echo "<h3>From " . esc_html($sd_raw) . " to " . esc_html($ed_raw) . "</h3>";
                echo "<h4>Lender statistics for <strong>" . esc_html($libname) . "</strong></h4>";
                echo "<div style='background:#f9f9f9;padding:16px;border-radius:6px;border:1px solid #ddd;max-width:480px;'>";
                echo "<strong>Total Requests Received:</strong> " . number_format($total) . "<br><br>";
                foreach ([1=>'Filled',0=>'Not Filled',3=>'Pending',4=>'Expired',6=>'Cancelled'] as $fv=>$lbl) {
                    $cq = $db->query("SELECT COUNT(*) AS c $base AND `Fill`='{$fv}'");
                    $n  = $cq ? (int)$cq->fetch_assoc()['c'] : 0;
                    echo "<strong>" . esc_html($lbl) . ":</strong> " . number_format($n) . " (" . SEAL_Functions::pct2($n,$total) . ")<br>";
                }
                echo "</div>";

                // By item type, then by requesting system with its item types
                $this->stats_breakdown($db, $base, 'ReqSystem', 'Breakdown by Requesting System', 'from');
            endif;
        endif; ?>
        </div>
        <?php
        $this->datepicker_js('#datepicker, #datepicker2');
    }
}
