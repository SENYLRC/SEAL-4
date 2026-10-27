<?php
/**
 * SEAL_Page_adminaccounts — /adminlib-accounts, "User Accounts" (capability seal_admin).
 *
 * Finds accounts across the whole service by library system, role, name, email or LOC code and lists them
 * with library, roles, last login and security (password strength, two-factor). Edit opens the WordPress user
 * profile, where the ILL profile fields, role (Role Editor module) and two-factor reset live. Pending
 * registrations can be approved or denied right here, from the approval email, or from the Pending view on
 * the WordPress Users screen (User Approval module).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class SEAL_Page_adminaccounts extends SEAL_Page_Base {
    /** Entry point: SEAL_Router calls this to print the page into the theme's content area. */
    public function render(): void {
        $systems     = SEAL_Functions::system_options();
        $self        = esc_url( home_url( '/adminlib-accounts' ) );
        $sys         = esc_html( seal_config( 'system_name' ) );

        $is_post     = $this->is_post();

        // One account's change history (?history=<id>): every recorded change to it, whoever made it.
        $hist = $is_post ? 0 : $this->req_int( 'history', 0 );
        if ( $hist && ( $hu = get_userdata( $hist ) ) && ( $hist === get_current_user_id() || current_user_can( 'edit_user', $hist ) ) ) {
            echo '<div class="adminlib-wrapper"><div class="adminlib-header"><h3>Change history: ' . esc_html( $hu->display_name ) . ' (' . esc_html( $hu->user_login ) . ')</h3>'
               . '<div class="actions"><a class="btn-secondary" href="' . $self . '">Return to list</a></div></div>'
               . '<p>Every recorded change to this account: registration, approval, role, library and profile fields, password and two-factor changes, '
               . 'whoever made them. Changes the person made themselves are marked “own account”. Passwords are never shown. Entries are kept for two years.</p>';
            SEAL_Audit::render( 'users', 200, 'No changes recorded for this account yet.', (int) $hu->ID, '', true );
            echo '</div>';
            return;
        }
        $filter_sys  = $is_post ? $this->post_str( 'filter_system' ) : $this->get_str( 'filter_system' );
        $filter_role = $is_post ? $this->post_str( 'filter_role'   ) : $this->get_str( 'filter_role' );
        $filter_name = $is_post ? $this->post_str( 'filter_name'   ) : $this->get_str( 'filter_name' );
        $filter_email= $is_post ? $this->post_str( 'filter_email'  ) : $this->get_str( 'filter_email' );
        $filter_loc  = $is_post ? $this->post_str( 'filter_loc'    ) : $this->get_str( 'filter_loc' );
        $filter_pend = ( $is_post ? $this->post_str( 'filter_pending' ) : $this->get_str( 'filter_pending' ) ) === '1';
        $keep        = array_filter( [ 'filter_system' => $filter_sys, 'filter_role' => $filter_role, 'filter_name' => $filter_name,
                                       'filter_email' => $filter_email, 'filter_loc' => $filter_loc, 'filter_pending' => $filter_pend ? '1' : '' ], 'strlen' );

        // Approve / Deny a pending registration (needs edit_user on that account: not Administrators /
        // ILL Administrators unless you are a full Administrator, never yourself).
        $notice = '';
        if ( $is_post && isset( $_POST['seal_decide'], $_POST['uid'] ) ) {
            $uid = (int) $_POST['uid'];
            $u   = get_userdata( $uid );
            $this->verify_nonce( 'seal_decide_' . $uid );
            $act = sanitize_key( (string) wp_unslash( $_POST['seal_decide'] ) );
            if ( ! $u || $uid === get_current_user_id() || ! current_user_can( 'edit_user', $uid ) ) {
                $this->alert( 'You can\'t approve or deny that account.', 'error' );
            } elseif ( SEAL_Module_UserApproval::decide( $uid, $act ) ) {
                $notice = esc_html( $u->display_name ) . ( $act === 'approve' ? ' was approved and has been emailed that they can log in.' : "'s registration was denied and they have been emailed." );
            } else {
                $notice = esc_html( $u->display_name ) . ' is no longer waiting for approval.';
            }
        }

        // Build WP_User_Query args
        $query_args = [ 'orderby' => 'display_name', 'order' => 'ASC', 'number' => -1 ];
        $meta_query = [];

        if ( $filter_sys ) {
            $meta_query[] = [ 'key' => 'home_system', 'value' => $filter_sys, 'compare' => '=' ];
        }
        if ( $filter_loc ) {
            $meta_query[] = [ 'key' => 'address_loc_code', 'value' => $filter_loc, 'compare' => 'LIKE' ];
        }
        if ( $filter_pend ) {
            $meta_query[] = [ 'key' => 'seal_approval_status', 'value' => 'pending' ];
        }
        if ( $meta_query ) {
            $query_args['meta_query'] = $meta_query;
        }
        if ( $filter_role ) {
            $query_args['role'] = $filter_role;
        }
        if ( $filter_name ) {
            $query_args['search']         = '*' . $filter_name . '*';
            $query_args['search_columns'] = [ 'display_name', 'first_name', 'last_name' ];
        }
        if ( $filter_email ) {
            $query_args['search']         = '*' . $filter_email . '*';
            $query_args['search_columns'] = [ 'user_email' ];
        }

        $users = get_users( $query_args );
        $total = count( $users );
        $n_pending = $filter_pend ? $total : count( get_users( [ 'meta_key' => 'seal_approval_status', 'meta_value' => 'pending', 'fields' => 'ID', 'number' => -1 ] ) );

        $roles_list = [
            'libstaff'      => 'Library Staff',
            'libsys'        => 'Library System Staff',
            'illadmin'      => 'ILL Administrator',
            'administrator' => 'Administrator',
        ];
        ?>
        <div class='adminlib-wrapper'>

        <div class='adminlib-header'>
            <h3><?php echo $sys; ?> — User Account Administration</h3>
            <div class='actions'>
                <a class='btn-secondary' href='<?php echo esc_url( admin_url( 'user-new.php' ) ); ?>'>Add User</a>
            </div>
        </div>

        <?php if ( $notice ) { $this->alert( $notice, 'success' ); } ?>
        <?php if ( $n_pending && ! $filter_pend ) : ?>
            <div class="seal-alert seal-alert-warning" role="status"><strong><?php echo (int) $n_pending; ?> account<?php echo $n_pending === 1 ? ' is' : 's are'; ?> waiting for approval.</strong>
                <a href="<?php echo esc_url( add_query_arg( 'filter_pending', '1', home_url( '/adminlib-accounts' ) ) ); ?>">Show <?php echo $n_pending === 1 ? 'it' : 'them'; ?></a></div>
        <?php endif; ?>
        <form action='<?php echo $self; ?>/' method='post' class='filter-bar'>
            <div class='filter-grid'>
                <div class='form-group'>
                    <label for='fld_filter_system'>Library System</label>
                    <select id='fld_filter_system' name='filter_system'>
                        <option value=''>All Systems</option>
                        <?php foreach ( $systems as $c => $n ): ?>
                        <option value='<?php echo esc_attr($c); ?>' <?php selected( $filter_sys, $c ); ?>><?php echo esc_html($n); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class='form-group'>
                    <label for='fld_filter_role'>Role</label>
                    <select id='fld_filter_role' name='filter_role'>
                        <option value=''>All Roles</option>
                        <?php foreach ( $roles_list as $r => $l ): ?>
                        <option value='<?php echo esc_attr($r); ?>' <?php selected( $filter_role, $r ); ?>><?php echo esc_html($l); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class='form-group'>
                    <label for='fld_filter_name'>Name</label>
                    <input id='fld_filter_name' name='filter_name' type='text' value='<?php echo esc_attr( $filter_name ); ?>'>
                </div>
                <div class='form-group'>
                    <label for='fld_filter_email'>Email</label>
                    <input id='fld_filter_email' name='filter_email' type='text' value='<?php echo esc_attr( $filter_email ); ?>'>
                </div>
                <div class='form-group'>
                    <label for='fld_filter_loc'>LOC Code</label>
                    <input id='fld_filter_loc' name='filter_loc' type='text' value='<?php echo esc_attr( $filter_loc ); ?>'>
                </div>
                <div class='form-group'>
                    <label for='fld_filter_pending'><input id='fld_filter_pending' name='filter_pending' type='checkbox' value='1' <?php checked( $filter_pend ); ?>> Waiting for approval only</label>
                </div>
            </div>
            <div class='filter-actions'>
                <button class='btn-primary'>Update</button>
                <a class='btn-secondary' href='<?php echo $self; ?>'>Clear</a>
            </div>
        </form>

        <div class='pagination-info'>Total Results: <?php echo $total; ?></div>

        <div class='seal-table-wrap'>
        <table class='rh-table'>
            <thead><tr>
                <th scope="col">Name / Email</th>
                <th scope="col">Library</th>
                <th scope="col">Role(s)</th>
                <th scope="col">Last Login</th>
                <th scope="col">Security</th>
                <th scope="col">Action</th>
            </tr></thead>
            <tbody>
            <?php if ( empty( $users ) ): ?>
            <tr><td colspan='6'>No users found.</td></tr>
            <?php else:
                foreach ( $users as $u ):
                    $fn    = (string) get_user_meta( $u->ID, 'first_name', true );
                    $ln    = (string) get_user_meta( $u->ID, 'last_name',  true );
                    $loc   = (string) get_user_meta( $u->ID, 'address_loc_code', true );
                    $hsys  = (string) get_user_meta( $u->ID, 'home_system', true );
                    $ll_ts = get_user_meta( $u->ID, 'wp-last-login', true );
                    $ll    = $ll_ts ? date( 'Y-m-d', (int) $ll_ts ) : '—';
                    $reg   = date( 'Y-m-d', strtotime( $u->user_registered ) );
                    $can   = $u->ID === get_current_user_id() || current_user_can( 'edit_user', $u->ID );   // not Administrators / ILL Administrators for an ILL Administrator
                    $sec   = SEAL_Functions::account_security( $u, $can );
                    $is_p  = SEAL_Module_UserApproval::is_pending( (int) $u->ID );
            ?>
            <tr>
                <td><strong><?php echo esc_html( trim( "$fn $ln" ) ?: $u->display_name ); ?></strong><?php if ( $is_p ) { echo ' <span class="status-badge">Pending approval</span>'; } ?><br>
                    <?php if ( strcasecmp( $u->user_login, $u->user_email ) !== 0 ) : ?><small><?php echo esc_html( $u->user_login ); ?></small><br><?php endif; ?>
                    <a href='mailto:<?php echo esc_attr( $u->user_email ); ?>' class='seal-acct-email'><?php echo esc_html( $u->user_email ); ?></a>
                </td>
                <td><?php echo esc_html( strtoupper( $loc ) ?: '—' ); ?><br><small><?php echo esc_html( $systems[ $hsys ] ?? $hsys ?: '' ); ?></small></td>
                <td><?php
                    $rlabels = array_map( fn($r) => $roles_list[$r] ?? ucfirst($r), $u->roles );
                    echo esc_html( implode( ', ', $rlabels ) );
                ?></td>
                <td class='seal-nowrap'><?php echo esc_html( $ll ); ?><br>
                    <small style='color:#595959'>Reg: <?php echo esc_html( $reg ); ?></small>
                </td>
                <td class='seal-acct-sec'><span><span class='seal-acct-lbl'>Password</span> <?php echo $sec['pw']; ?></span><span><span class='seal-acct-lbl'>2FA</span> <?php echo $sec['tfa']; ?></span></td>
                <td class='table-actions'>
                    <?php if ( $is_p && $u->ID !== get_current_user_id() && current_user_can( 'edit_user', $u->ID ) ) { echo SEAL_Module_UserApproval::buttons( $u, home_url( '/adminlib-accounts/' ), $keep ); } ?>
                    <a class='edit' href='<?php echo esc_url( get_edit_user_link( $u->ID ) ); ?>'>Edit<span class="seal-sr-only"> <?php echo esc_html( $u->display_name ); ?></span></a>
                    <?php if ( $can ) : ?><a href='<?php echo esc_url( add_query_arg( 'history', $u->ID, home_url( '/adminlib-accounts' ) ) ); ?>' class='seal-acct-hist'>History<span class="seal-sr-only"> of <?php echo esc_html( $u->display_name ); ?></span></a><?php endif; ?>
                </td>
            </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
        </div>

        </div>
        <?php
    }
}
