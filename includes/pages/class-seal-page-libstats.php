<?php
/**
 * SEAL_Page_libstats — /libstats, "Borrowing Statistics" (capability seal_view_own).
 *
 * Counts the user's library's requests (as borrower) by status for a date range (default: the last year), with
 * a library selector for users who have more than one LOC code.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class SEAL_Page_libstats extends SEAL_Page_Base {
    /** Entry point: SEAL_Router calls this to print the page into the theme's content area. */
    public function render(): void {
        $db        = SEAL_DB::get();
        $stat_tbl  = SEAL_DB::$sealSTAT;
        $lib_tbl   = SEAL_DB::$sealLIB;
        $uid       = $this->user->ID;

        $primary_loc = SEAL_Functions::primary_loc($uid);
        $all_locs    = SEAL_Functions::all_locs($uid);
        $has_multi   = count($all_locs) > 1;

        // Loc name map
        $loc_name_map = [];
        if (!empty($all_locs)) {
            $in = implode(',', array_map(fn($l)=>"'".SEAL_DB::esc($l)."'", $all_locs));
            $rn = $db->query("SELECT loc, Name FROM `{$lib_tbl}` WHERE loc IN ($in)");
            if ($rn) { while($rr=$rn->fetch_assoc()) { $loc_name_map[strtoupper($rr['loc'])]=$rr['Name']; } }
        }

        $submitted = $this->is_post();
        $filter_loc = sanitize_text_field($this->req_str('filter_loc',''));
        if ($has_multi && $filter_loc === '') { $filter_loc = 'all'; }
        if (!$has_multi) { $filter_loc = $all_locs[0] ?? $primary_loc; }
        // A chosen library must be one of the user's own (never trust the posted value).
        if ($has_multi && $filter_loc !== 'all' && !in_array(strtoupper($filter_loc), $all_locs, true)) { $filter_loc = 'all'; }

        $sd_raw = sanitize_text_field($this->req_str('startdate', date('Y-m-d', strtotime('-1 year'))));
        $ed_raw = sanitize_text_field($this->req_str('enddate',   date('Y-m-d')));
        $startdate = SEAL_DB::esc($sd_raw . ' 00:00:00');
        $enddate   = SEAL_DB::esc($ed_raw . ' 23:59:59');
        $self = esc_url(home_url('/libstats'));
        ?>
        <div class="seal-page-wrap">
        <h2>Borrowing Statistics</h2>
        <form method="post" action="<?php echo $self; ?>" aria-label="Borrowing statistics date range">
            <?php $this->nonce_field('seal_stats'); ?>
            <?php if ($has_multi): ?>
            <label for="sl_loc">Library:</label>
            <select id="sl_loc" name="filter_loc" style="margin:6px 0;">
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
            <br><br>
            <button type="submit" class="seal-btn-primary">View Statistics</button>
        </form>

        <?php if ($submitted):
            // Build WHERE
            if ($has_multi && $filter_loc === 'all') {
                $in = implode(',', array_map(fn($l)=>"'".SEAL_DB::esc($l)."'", $all_locs));
                $where_loc = "`Requester LOC` IN ($in)";
                $libname   = 'All My Libraries';
            } else {
                $chosen    = strtoupper($filter_loc ?: $primary_loc);
                $where_loc = "`Requester LOC`='" . SEAL_DB::esc($chosen) . "'";
                $libname   = $loc_name_map[$chosen] ?? $chosen;
            }
            $base = "FROM `{$stat_tbl}` WHERE {$where_loc} AND `Timestamp` BETWEEN '{$startdate}' AND '{$enddate}'";
            $total_q = $db->query("SELECT COUNT(*) AS c $base");
            $total   = $total_q ? (int)$total_q->fetch_assoc()['c'] : 0;

            if ($total === 0): ?>
            <div class="seal-alert seal-alert-info" style="margin-top:16px;">No results found for the selected date range.</div>
            <?php else:
                $counts = [];
                foreach ([1=>'Filled',0=>'Not Filled',3=>'Pending',4=>'Expired',6=>'Cancelled'] as $fillVal=>$label) {
                    $cq = $db->query("SELECT COUNT(*) AS c $base AND `Fill`='{$fillVal}'");
                    $counts[$label] = $cq ? (int)$cq->fetch_assoc()['c'] : 0;
                }
                echo "<h3>From " . esc_html($sd_raw) . " to " . esc_html($ed_raw) . "</h3>";
                echo "<h4>Borrower statistics for <strong>" . esc_html($libname) . "</strong></h4>";
                echo "<div style='background:#f9f9f9;padding:16px;border-radius:6px;border:1px solid #ddd;max-width:480px;'>";
                echo "<strong>Total Requests:</strong> " . number_format($total) . "<br><br>";
                foreach ($counts as $label => $n) {
                    echo "<strong>" . esc_html($label) . ":</strong> " . number_format($n) . " (" . SEAL_Functions::pct2($n,$total) . ")<br>";
                }
                echo "</div>";

                // By item type, then by destination system with its item types
                $this->stats_breakdown($db, $base, 'DestSystem', 'Breakdown by Destination System', 'to');
            endif;
        endif; ?>
        </div>
        <?php
        $this->datepicker_js('#datepicker, #datepicker2');
    }
}
