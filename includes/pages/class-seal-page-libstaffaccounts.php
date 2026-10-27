<?php
/**
 * SEAL_Page_libstaffaccounts — /libstaffaccounts, "Library Staff Accounts" (capability seal_view_own).
 *
 * Read-only list of the user accounts registered to the user's library (matched on the address_loc_code profile
 * field). Users with extra LOC codes get a selector to view each library, or all of them together.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class SEAL_Page_libstaffaccounts extends SEAL_Page_Base {
    /** Entry point: SEAL_Router calls this to print the page into the theme's content area. */
    public function render(): void {
        $uid         = $this->user->ID;
        $primary_loc = strtoupper( (string) get_user_meta($uid,'address_loc_code',true) );
        $extra_raw   = (string) get_user_meta($uid,'seal_extra_locs',true);
        $extra_locs  = array_filter(array_map('strtoupper',array_map('trim',explode(',',$extra_raw))));
        $all_locs    = array_values(array_unique(array_filter(array_merge([$primary_loc],$extra_locs))));

        if ( empty($all_locs) ) {
            $this->alert('No location code set on your account.','error'); return;
        }

        $active_sel = sanitize_text_field( $this->get_str('loc', strtolower($primary_loc)) );
        $is_all     = ($active_sel === 'all');
        if (!$is_all) {
            $active_loc = strtoupper($active_sel);
            if (!in_array($active_loc,$all_locs,true)) { $active_loc = $primary_loc; }
        } else { $active_loc = null; }

        // Resolve names
        $loc_name_map = [];
        if (!empty($all_locs)) {
            $in = implode(',', array_map(fn($l)=>"'".SEAL_DB::esc($l)."'", $all_locs));
            $r  = SEAL_DB::query("SELECT loc, Name FROM %t WHERE loc IN ($in)", SEAL_DB::$sealLIB);
            if ($r) { while($row=$r->fetch_assoc()) { $loc_name_map[strtoupper($row['loc'])]=$row['Name']; } }
        }

        // Library switcher
        if (count($all_locs)>1): ?>
        <form method="get" action="<?php echo esc_url( home_url( '/libstaffaccounts' ) ); ?>" style="margin:12px 0;padding:12px;border:1px solid #ddd;border-radius:6px;background:#fafafa;">
            <label for="locSwitch" style="font-weight:bold;margin-right:8px;">View staff for:</label>
            <select id="locSwitch" name="loc" aria-describedby="locSwitch_h">
                <option value="all" <?php selected($is_all,true); ?>>All my libraries</option>
                <?php foreach ($all_locs as $l):
                    $sel = (!$is_all && $l===$active_loc)?'selected':'';
                ?>
                <option value="<?php echo esc_attr($l); ?>" <?php echo $sel; ?>><?php echo esc_html(($loc_name_map[$l]??$l)." ($l)"); ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn-secondary" style="margin-left:8px;">Show staff</button>
            <span id="locSwitch_h" class="helper" style="display:block;margin-top:6px;">Choose a library, then select the button to update the list.</span>
        </form>
        <?php endif;

        if ($is_all) {
            echo '<h3>Library Staff — All My Libraries</h3>';
            $users = get_users(['meta_query'=>[['key'=>'address_loc_code','value'=>$all_locs,'compare'=>'IN']],'orderby'=>'display_name','order'=>'ASC']);
        } else {
            $name = $loc_name_map[$active_loc??''] ?? ($active_loc??'');
            echo '<h3>Library Staff — ' . esc_html("$name ($active_loc)") . '</h3>';
            $users = get_users(['meta_key'=>'address_loc_code','meta_value'=>$active_loc,'orderby'=>'display_name','order'=>'ASC']);
        }

        if (empty($users)) { echo '<p>No users found for this selection.</p>'; return; }
        ?>
        <div class="seal-table-wrap"><table class="seal-table">
        <thead><tr><th scope="col">LOC</th><th scope="col">Name</th><th scope="col">Username</th><th scope="col">Email</th><th scope="col">Last Login</th><th scope="col">Registered</th></tr></thead>
        <tbody>
        <?php foreach ($users as $u):
            $uloc   = strtoupper((string)get_user_meta($u->ID,'address_loc_code',true));
            $fname  = (string)get_user_meta($u->ID,'first_name',true);
            $lname  = (string)get_user_meta($u->ID,'last_name', true);
            $ll_ts  = get_user_meta($u->ID,'wp-last-login',true);
            $ll     = $ll_ts ? date('Y-m-d H:i',(int)$ll_ts) : 'Unknown';
            $reg    = date('Y-m-d',strtotime($u->user_registered));
        ?>
        <tr>
            <td><?php echo esc_html($uloc?:'—'); ?></td>
            <td><?php echo esc_html("$fname $lname"); ?></td>
            <td><?php echo esc_html($u->user_login); ?></td>
            <td><a href="mailto:<?php echo esc_attr($u->user_email); ?>"><?php echo esc_html($u->user_email); ?></a></td>
            <td><?php echo esc_html($ll); ?></td>
            <td><?php echo esc_html($reg); ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody></table></div>
        <?php
    }
}
