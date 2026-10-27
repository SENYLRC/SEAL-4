<?php
/**
 * SEAL_Page_adminaccounts_ls — Manage Accounts for Library System staff (/adminlib-accounts-ls).
 *
 * Modelled on SEAL-3's adminlib_accounts.php: list the accounts in the staff member's own home system,
 * add a Library Staff account, edit one, delete one. SEAL-3 did not check whose account was being
 * edited or deleted (any user id worked, administrators included); here every action is limited to:
 *   - accounts whose home system is the staff member's own, and
 *   - Library Staff accounts only (administrators and other system staff are listed, never editable), and
 *   - LOC codes of libraries in that same system (checked against the library directory).
 * Passwords are never typed in by system staff: new accounts and "Send password reset" use WordPress's
 * own set-password email.
 *   default  list      action=new  add form      action=edit  edit form      action=delete  confirm delete
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class SEAL_Page_adminaccounts_ls extends SEAL_Page_Base {

    private string $home = '';
    private string $self = '';

    /**
     * Entry point: SEAL_Router calls this to print the page into the theme's content area. Library System Staff
     * list, edit, reset passwords for and delete Library Staff accounts in their own home system only.
     */
    public function render(): void {
        $this->home = sanitize_text_field( (string) get_user_meta( $this->user->ID, 'home_system', true ) );
        $this->self = home_url( '/adminlib-accounts-ls' );
        if ( $this->home === '' ) {
            $msg = 'Your account does not have a Home Library System assigned.';
            if ( current_user_can( 'seal_admin' ) ) { $msg .= ' Administrators manage every account under <a href="' . esc_url( home_url( '/adminlib-accounts' ) ) . '">User Accounts</a>.'; }
            $this->alert( $msg, 'error' );
            return;
        }

        $action = sanitize_key( $this->req_str( 'action', '' ) );
        if ( $this->is_post() && isset( $_POST['seal_decide'] ) ) { $this->handle_decide(); return; }
        if ( $this->is_post() ) {
            switch ( $action ) {
                case 'add':    $this->handle_add();    return;
                case 'save':   $this->handle_save();   return;
                case 'delete': $this->handle_delete(); return;
                case 'reset':  $this->handle_reset();  return;
            }
        }
        switch ( $action ) {
            case 'new':    $this->render_form( null ); break;
            case 'edit':   $this->render_edit();       break;
            case 'delete': $this->render_delete();     break;
            case 'history': $this->render_history();   break;
            default:       $this->render_list();       break;
        }
    }

    // ── Rules ─────────────────────────────────────────────────────────────────

    /** An account this staff member may change: same home system, Library Staff role, not themselves. */
    private function editable( ?WP_User $u ): bool {
        return $u instanceof WP_User
            && $u->ID !== $this->user->ID
            && strcasecmp( (string) get_user_meta( $u->ID, 'home_system', true ), $this->home ) === 0
            && in_array( 'libstaff', (array) $u->roles, true )
            && ! array_intersect( [ 'administrator', 'illadmin', 'libsys' ], (array) $u->roles );
    }

    /** The account named in uid=, or null when it isn't one this user may manage (see editable()). */
    private function target(): ?WP_User {
        $u = get_userdata( $this->req_int( 'uid', 0 ) );
        return $this->editable( $u ?: null ) ? $u : null;
    }

    /** Library name for a LOC code, only if that library is in this staff member's system; '' otherwise. */
    private function library_in_system( string $loc ): string {
        $loc = strtoupper( trim( $loc ) );
        if ( $loc === '' ) { return ''; }
        $q   = SEAL_DB::query( 'SELECT `Name` FROM %t WHERE UPPER(TRIM(`loc`)) = %s AND `system` = %s LIMIT 1', SEAL_DB::$sealLIB, $loc, $this->home );
        $row = $q ? $q->fetch_assoc() : null;
        return $row ? trim( (string) $row['Name'] ) : '';
    }

    /** The "Return to list" button. */
    private function back(): string { return '<a class="btn-secondary" href="' . esc_url( $this->self ) . '">Return to list</a>'; }
    /** This page's address with the given query arguments, escaped for an attribute. */
    private function url( array $args ): string { return esc_url( add_query_arg( $args, $this->self ) ); }

    // ── List ──────────────────────────────────────────────────────────────────

    /**
     * The searchable list of Library Staff accounts in the user's system, with Edit, Reset password and Delete
     * actions, and an optional result message.
     */
    private function render_list( string $notice = '' ): void {
        $search  = $this->get_str( 'search' );
        $only_p  = $this->get_str( 'pending' ) === '1';
        $users   = get_users( [ 'meta_key' => 'home_system', 'meta_value' => $this->home, 'orderby' => 'display_name', 'order' => 'ASC', 'number' => -1 ] );
        $pending = array_values( array_filter( $users, static fn( WP_User $u ) => SEAL_Module_UserApproval::is_pending( (int) $u->ID ) ) );
        if ( $only_p ) { $users = $pending; }
        if ( $search !== '' ) {
            $needle = strtolower( $search );
            $users  = array_values( array_filter( $users, static function ( WP_User $u ) use ( $needle ) {
                $hay = strtolower( implode( ' ', [ $u->display_name, $u->user_email, get_user_meta( $u->ID, 'institution', true ), get_user_meta( $u->ID, 'address_loc_code', true ) ] ) );
                return strpos( $hay, $needle ) !== false;
            } ) );
        }
        $roles = [ 'libstaff' => 'Library Staff', 'libsys' => 'Library System Staff', 'illadmin' => 'ILL Administrator', 'administrator' => 'Administrator' ];
        ?>
        <div class="adminlib-wrapper">
        <div class="adminlib-header">
            <h3>Accounts in <?php echo esc_html( SEAL_Functions::system_name( $this->home ) ); ?></h3>
            <div class="actions"><a class="btn-primary" href="<?php echo $this->url( [ 'action' => 'new' ] ); ?>">Add Library Staff Account</a></div>
        </div>
        <?php if ( $notice ) { $this->alert( $notice, 'success' ); } ?>
        <?php if ( $pending && ! $only_p ) : ?>
            <div class="seal-alert seal-alert-warning" role="status"><strong><?php echo count( $pending ); ?> account<?php echo count( $pending ) === 1 ? ' is' : 's are'; ?> waiting for approval.</strong>
                <a href="<?php echo $this->url( [ 'pending' => '1' ] ); ?>">Show <?php echo count( $pending ) === 1 ? 'it' : 'them'; ?></a></div>
        <?php elseif ( $only_p ) : ?>
            <p><strong>Showing accounts waiting for approval.</strong> <a href="<?php echo esc_url( $this->self ); ?>">Show all accounts</a></p>
        <?php endif; ?>
        <form method="get" action="<?php echo esc_url( $this->self ); ?>" class="filter-bar" role="search" style="margin:12px 0;">
            <label for="acct_search">Search</label>
            <input type="text" id="acct_search" name="search" value="<?php echo esc_attr( $search ); ?>" placeholder="Name, email, library or LOC code">
            <button type="submit" class="btn-primary">Search</button>
            <a class="btn-secondary" href="<?php echo esc_url( $this->self ); ?>">Reset</a>
        </form>
        <p><?php echo count( $users ); ?> account<?php echo count( $users ) === 1 ? '' : 's'; ?>. You can change Library Staff accounts; administrators and system staff are shown for reference.</p>
        <?php if ( ! $users ) : ?>
            <p><em>No accounts found.</em></p>
        <?php else : ?>
        <div class="seal-table-wrap"><table class="seal-table">
            <thead><tr><th scope="col">Name / Email</th><th scope="col">Library</th><th scope="col">Role</th><th scope="col">Security</th><th scope="col">Action</th></tr></thead>
            <tbody>
            <?php foreach ( $users as $u ) :
                $role = '';
                foreach ( $roles as $k => $label ) { if ( in_array( $k, (array) $u->roles, true ) ) { $role = $label; } }
                $is_p = SEAL_Module_UserApproval::is_pending( (int) $u->ID );
                $sec  = SEAL_Functions::account_security( $u, $this->editable( $u ) ); ?>
                <tr>
                    <td><strong><?php echo esc_html( $u->display_name ); ?></strong><?php if ( $is_p ) { echo ' <span class="status-badge">Pending approval</span>'; } ?><br>
                        <a href="mailto:<?php echo esc_attr( $u->user_email ); ?>" class="seal-acct-email"><?php echo esc_html( $u->user_email ); ?></a></td>
                    <td><?php echo esc_html( (string) get_user_meta( $u->ID, 'institution', true ) ); ?><br><small><?php echo esc_html( strtoupper( (string) get_user_meta( $u->ID, 'address_loc_code', true ) ) ); ?></small></td>
                    <td><?php echo esc_html( $role ?: implode( ', ', (array) $u->roles ) ); ?></td>
                    <td class="seal-acct-sec"><span><span class="seal-acct-lbl">Password</span> <?php echo $sec['pw']; ?></span><span><span class="seal-acct-lbl">2FA</span> <?php echo $sec['tfa']; ?></span></td>
                    <td><?php if ( $is_p && $this->editable( $u ) ) { echo SEAL_Module_UserApproval::buttons( $u, $this->self ); } ?>
                    <?php if ( $this->editable( $u ) ) : ?>
                        <a class="btn-secondary" href="<?php echo $this->url( [ 'action' => 'edit', 'uid' => $u->ID ] ); ?>">Edit<span class="seal-sr-only"> <?php echo esc_html( $u->display_name ); ?></span></a>
                        <a class="seal-acct-hist" href="<?php echo $this->url( [ 'action' => 'history', 'uid' => $u->ID ] ); ?>">History<span class="seal-sr-only"> of <?php echo esc_html( $u->display_name ); ?></span></a>
                    <?php else : ?>&mdash;<?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
        </div>
        <?php
    }

    // ── Add / edit form ───────────────────────────────────────────────────────

    /** The add / edit account form ($u = null to add), showing $error above it when the last save failed. */
    private function render_form( ?WP_User $u, string $error = '' ): void {
        $is_new = ! $u;
        $v = function ( string $k ) use ( $u ): string {
            if ( isset( $_POST[ $k ] ) ) { return sanitize_text_field( wp_unslash( $_POST[ $k ] ) ); }   // redisplay after an error
            if ( ! $u ) { return ''; }
            if ( $k === 'user_email' ) { return $u->user_email; }
            if ( in_array( $k, [ 'first_name', 'last_name' ], true ) ) { return (string) $u->$k; }
            return (string) get_user_meta( $u->ID, $k, true );
        };
        ?>
        <div class="adminlib-wrapper LibProfile_Form">
        <div class="adminlib-header"><h3><?php echo $is_new ? 'Add Library Staff Account' : 'Edit Account: ' . esc_html( $u->display_name ); ?></h3></div>
        <?php if ( $error ) { $this->alert( $error, 'error' ); } ?>
        <form method="post" action="<?php echo esc_url( $this->self ); ?>">
            <?php $this->nonce_field( $is_new ? 'seal_accounts_ls_add' : 'seal_accounts_ls_save_' . $u->ID ); ?>
            <input type="hidden" name="action" value="<?php echo $is_new ? 'add' : 'save'; ?>">
            <?php if ( ! $is_new ) : ?><input type="hidden" name="uid" value="<?php echo (int) $u->ID; ?>"><?php endif; ?>
            <div class="section-card"><h4>Account</h4>
              <div class="form-section">
                <div class="form-group"><label for="a_email">Email <span aria-hidden="true">*</span></label>
                    <input id="a_email" type="email" name="user_email" required aria-required="true" value="<?php echo esc_attr( $v( 'user_email' ) ); ?>" autocomplete="off">
                    <?php if ( $is_new ) : ?><div class="helper">Also the username. A "set your password" email is sent here.</div><?php endif; ?></div>
                <div class="form-group"><label for="a_first">First Name <span aria-hidden="true">*</span></label><input id="a_first" type="text" name="first_name" required aria-required="true" value="<?php echo esc_attr( $v( 'first_name' ) ); ?>"></div>
                <div class="form-group"><label for="a_last">Last Name <span aria-hidden="true">*</span></label><input id="a_last" type="text" name="last_name" required aria-required="true" value="<?php echo esc_attr( $v( 'last_name' ) ); ?>"></div>
                <div class="form-group"><label for="a_phone">Work Phone</label><input id="a_phone" type="text" name="phone" value="<?php echo esc_attr( $v( 'phone' ) ); ?>"></div>
                <div class="form-group"><label for="a_alt">Additional Email</label><input id="a_alt" type="email" name="alt_email" value="<?php echo esc_attr( $v( 'alt_email' ) ); ?>"></div>
              </div>
            </div>
            <div class="section-card"><h4>Library</h4>
              <div class="form-section">
                <div class="form-group"><label for="a_loc">LOC Location Code <span aria-hidden="true">*</span></label>
                    <input id="a_loc" type="text" name="address_loc_code" required aria-required="true" value="<?php echo esc_attr( strtoupper( $v( 'address_loc_code' ) ) ); ?>" aria-describedby="a_loc_h">
                    <div class="helper" id="a_loc_h">Must be a library in <?php echo esc_html( SEAL_Functions::system_name( $this->home ) ); ?>. The library name and mailing address come from its library profile.</div></div>
                <div class="form-group"><label for="a_oclc">OCLC Symbol</label><input id="a_oclc" type="text" name="oclc_symbol" value="<?php echo esc_attr( $v( 'oclc_symbol' ) ); ?>"></div>
              </div>
            </div>
            <div class="actions" style="margin-top:20px;">
                <button type="submit" class="btn-primary"><?php echo $is_new ? 'Create Account' : 'Save Changes'; ?></button>
                <a class="btn-secondary" href="<?php echo esc_url( $this->self ); ?>">Cancel</a>
            </div>
        </form>
        <?php if ( ! $is_new ) : ?>
        <div class="section-card" style="margin-top:20px;"><h4>Password and removal</h4>
            <form method="post" action="<?php echo esc_url( $this->self ); ?>" style="display:inline-block;margin-right:10px;">
                <?php $this->nonce_field( 'seal_accounts_ls_reset_' . $u->ID ); ?>
                <input type="hidden" name="action" value="reset"><input type="hidden" name="uid" value="<?php echo (int) $u->ID; ?>">
                <button type="submit" class="btn-secondary">Send password reset email</button>
            </form>
            <a class="btn-danger" href="<?php echo $this->url( [ 'action' => 'delete', 'uid' => $u->ID ] ); ?>">Delete account&hellip;</a>
        </div>
        <?php endif; ?>
        </div>
        <?php
    }

    /** Shows the edit form for the selected account, or an error when it isn't one this user may manage. */
    private function render_edit(): void {
        $u = $this->target();
        if ( ! $u ) { echo '<div class="adminlib-wrapper">'; $this->alert( 'That account is not a Library Staff account in your system.', 'error' ); echo $this->back() . '</div>'; return; }
        $this->render_form( $u );
    }

    /** Validate the shared fields; returns [ error, cleaned values ]. */
    private function read_form( int $uid ): array {
        $f = [
            'user_email' => sanitize_email( wp_unslash( $_POST['user_email'] ?? '' ) ),
            'first_name' => $this->post_str( 'first_name' ), 'last_name' => $this->post_str( 'last_name' ),
            'phone'      => $this->post_str( 'phone' ),      'alt_email' => sanitize_email( wp_unslash( $_POST['alt_email'] ?? '' ) ),
            'address_loc_code' => strtoupper( $this->post_str( 'address_loc_code' ) ), 'oclc_symbol' => $this->post_str( 'oclc_symbol' ),
        ];
        if ( ! is_email( $f['user_email'] ) || $f['first_name'] === '' || $f['last_name'] === '' || $f['address_loc_code'] === '' ) {
            return [ 'Email, first name, last name and LOC code are required.', $f ];
        }
        $owner = email_exists( $f['user_email'] );
        if ( $owner && (int) $owner !== $uid ) { return [ 'Another account already uses that email address.', $f ]; }
        $f['institution'] = $this->library_in_system( $f['address_loc_code'] );
        if ( $f['institution'] === '' ) {
            return [ 'LOC code ' . esc_html( $f['address_loc_code'] ) . ' is not a library in ' . esc_html( SEAL_Functions::system_name( $this->home ) ) . '. Check the <a href="' . esc_url( home_url( '/illdir' ) ) . '">ILL Directory</a>.', $f ];
        }
        return [ '', $f ];
    }

    /** Saves the edited profile fields; the home system is always set to the editor's own system. */
    private function save_meta( int $uid, array $f ): void {
        foreach ( [ 'phone', 'alt_email', 'address_loc_code', 'oclc_symbol', 'institution' ] as $k ) { update_user_meta( $uid, $k, $f[ $k ] ); }
        update_user_meta( $uid, 'home_system', $this->home );
    }

    // ── Actions ───────────────────────────────────────────────────────────────

    /**
     * Creates a Library Staff account in the user's own system from the add form. No approval email is sent:
     * system staff created it on purpose.
     */
    private function handle_add(): void {
        $this->verify_nonce( 'seal_accounts_ls_add' );
        [ $err, $f ] = $this->read_form( 0 );
        if ( ! $err && username_exists( $f['user_email'] ) ) { $err = 'An account with that username already exists.'; }
        if ( $err ) { $this->render_form( null, $err ); return; }

        add_filter( 'seal_user_needs_approval', '__return_false' );   // created by system staff: no approval email
        $uid = wp_insert_user( [
            'user_login' => $f['user_email'], 'user_email' => $f['user_email'], 'user_pass' => wp_generate_password( 24 ),
            'first_name' => $f['first_name'], 'last_name' => $f['last_name'], 'display_name' => trim( $f['first_name'] . ' ' . $f['last_name'] ),
            'role'       => 'libstaff',
        ] );
        remove_filter( 'seal_user_needs_approval', '__return_false' );
        if ( is_wp_error( $uid ) ) { $this->render_form( null, 'The account could not be created: ' . esc_html( $uid->get_error_message() ) ); return; }
        $this->save_meta( $uid, $f );
        update_user_meta( $uid, 'seal_approval_status', 'approved' );
        update_user_meta( $uid, 'seal_created_by', $this->user->ID . '|' . time() );
        wp_new_user_notification( $uid, null, 'user' );                   // WordPress's "set your password" email
        $this->render_list( 'Account created for ' . esc_html( $f['user_email'] ) . ' (' . esc_html( $f['institution'] ) . '). They have been emailed a link to set their password (valid for ' . esc_html( SEAL_Emails::link_life() ) . ').' );
    }

    /** Validates and saves the edit form (nonce per account), then returns to the list with a message. */
    private function handle_save(): void {
        $u = $this->target();
        if ( ! $u ) { $this->alert( 'That account is not a Library Staff account in your system.', 'error' ); return; }
        $this->verify_nonce( 'seal_accounts_ls_save_' . $u->ID );
        [ $err, $f ] = $this->read_form( $u->ID );
        if ( $err ) { $this->render_form( $u, $err ); return; }
        $r = wp_update_user( [ 'ID' => $u->ID, 'user_email' => $f['user_email'], 'first_name' => $f['first_name'], 'last_name' => $f['last_name'],
                               'display_name' => trim( $f['first_name'] . ' ' . $f['last_name'] ) ] );
        if ( is_wp_error( $r ) ) { $this->render_form( $u, 'The account could not be saved: ' . esc_html( $r->get_error_message() ) ); return; }
        $this->save_meta( $u->ID, $f );
        $this->render_list( 'Account updated: ' . esc_html( $f['user_email'] ) . '.' );
    }

    /** Sends the selected user WordPress's password-reset email. */
    private function handle_reset(): void {
        $u = $this->target();
        if ( ! $u ) { $this->alert( 'That account is not a Library Staff account in your system.', 'error' ); return; }
        $this->verify_nonce( 'seal_accounts_ls_reset_' . $u->ID );
        $r = retrieve_password( $u->user_login );
        $this->render_list( $r === true ? 'A password reset email was sent to ' . esc_html( $u->user_email ) . '.'
                                        : 'The password reset email could not be sent. Ask an administrator to check the site\'s email.' );
    }

    /** Approve / Deny a pending registration: Library Staff accounts in this staff member's own system only. */
    private function handle_decide(): void {
        $u = $this->target();
        if ( ! $u ) { $this->alert( 'That account is not a Library Staff account in your system.', 'error' ); return; }
        $this->verify_nonce( 'seal_decide_' . $u->ID );
        $act = sanitize_key( (string) wp_unslash( $_POST['seal_decide'] ?? '' ) );
        if ( ! SEAL_Module_UserApproval::decide( (int) $u->ID, $act ) ) { $this->render_list( esc_html( $u->display_name ) . ' is no longer waiting for approval.' ); return; }
        $this->render_list( esc_html( $u->display_name ) . ( $act === 'approve' ? ' was approved and has been emailed that they can log in.' : "'s registration was denied and they have been emailed." ) );
    }

    /** One account's change history: Library Staff accounts in this staff member's own system only. */
    private function render_history(): void {
        $u = $this->target();
        if ( ! $u ) { $this->alert( 'That account is not a Library Staff account in your system.', 'error' ); return; }
        echo '<div class="adminlib-wrapper"><div class="adminlib-header"><h3>Change history: ' . esc_html( $u->display_name ) . '</h3><div class="actions">' . $this->back() . '</div></div>'
           . '<p>Every recorded change to this account, whoever made it. Changes the person made themselves are marked “own account”. Passwords are never shown. Entries are kept for two years.</p>';
        SEAL_Audit::render( 'users', 200, 'No changes recorded for this account yet.', (int) $u->ID, '', true );
        echo '</div>';
    }

    /** Asks for confirmation before deleting the selected account. */
    private function render_delete(): void {
        $u = $this->target();
        if ( ! $u ) { echo '<div class="adminlib-wrapper">'; $this->alert( 'That account is not a Library Staff account in your system.', 'error' ); echo $this->back() . '</div>'; return; }
        ?>
        <div class="adminlib-wrapper">
            <div class="adminlib-header"><h3>Delete account?</h3></div>
            <p>Permanently delete the account for <strong><?php echo esc_html( $u->display_name ); ?></strong> (<?php echo esc_html( $u->user_email ); ?>)? They will no longer be able to log in. Their past requests are kept.</p>
            <form method="post" action="<?php echo esc_url( $this->self ); ?>">
                <?php $this->nonce_field( 'seal_accounts_ls_delete_' . $u->ID ); ?>
                <input type="hidden" name="action" value="delete"><input type="hidden" name="uid" value="<?php echo (int) $u->ID; ?>">
                <button type="submit" class="btn-danger">Yes, delete this account</button>
                <a class="btn-secondary" href="<?php echo $this->url( [ 'action' => 'edit', 'uid' => $u->ID ] ); ?>">Cancel</a>
            </form>
        </div>
        <?php
    }

    /** Deletes the selected account after the nonce check and returns to the list with a message. */
    private function handle_delete(): void {
        $u = $this->target();
        if ( ! $u ) { $this->alert( 'That account is not a Library Staff account in your system.', 'error' ); return; }
        $this->verify_nonce( 'seal_accounts_ls_delete_' . $u->ID );
        require_once ABSPATH . 'wp-admin/includes/user.php';
        $who = $u->user_email;
        $ok  = wp_delete_user( $u->ID );
        $this->render_list( $ok ? 'Account deleted: ' . esc_html( $who ) . '.' : 'The account could not be deleted.' );
    }
}
