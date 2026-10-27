<?php
/**
 * SEAL_Module_UserApproval
 *
 * When a new user registers, their account is set to "pending" status.
 * Admins receive an email notification and can approve or deny from:
 *  - The Users admin list (new "Pending" view + bulk approve/deny)
 *  - Approve / Deny links in the notification email. Each registration gets a random single-use
 *    token (only its hash is stored, meta seal_approval_token, valid TOKEN_DAYS days). The links
 *    point into wp-admin, so WordPress asks for a sign-in first; an administrator (edit_users) with
 *    a valid token is then done in one click. A nonce can't be used here: it would be tied to the
 *    logged-out registrant's session and never verify for the administrator.
 *
 * Approved users: status set to "approved", they receive a welcome email
 *   with their login link.
 * Denied users: their account is deleted and they receive a denial notice.
 *
 * Status stored in user meta: seal_approval_status
 *   Values: 'pending' | 'approved' | 'denied'
 *
 * Blocking login: pending/denied users cannot log in.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class SEAL_Module_UserApproval {

    const META_KEY   = 'seal_approval_status';
    const TOKEN_META = 'seal_approval_token';     // "sha256 hash|expiry timestamp"
    const TOKEN_DAYS = 14;

    /**
     * Hooks the approval flow: new registrations are held as pending, pending and denied users can't log in,
     * admins approve or deny from email links, the Users list (quick and bulk actions, Pending view, Approval
     * column).
     */
    public static function init(): void {
        // Block pending/denied login
        add_filter( 'authenticate',       [ __CLASS__, 'block_unapproved'    ], 100, 3 );
        add_action( 'wp_login_failed',    [ __CLASS__, 'noop'                ] ); // prevent cascading hooks

        // On registration: mark pending + notify admin
        add_action( 'user_register',      [ __CLASS__, 'on_register'         ], 10, 1 );

        // Prevent auto-login right after registration for pending users
        add_filter( 'login_redirect',     [ __CLASS__, 'redirect_pending'    ], 100, 3 );

        // Admin: Users list views + bulk actions
        add_filter( 'views_users',        [ __CLASS__, 'add_pending_view'    ] );
        add_filter( 'bulk_actions-users', [ __CLASS__, 'add_bulk_actions'    ] );
        add_filter( 'handle_bulk_actions-users', [ __CLASS__, 'handle_bulk' ], 10, 3 );
        add_action( 'admin_notices',      [ __CLASS__, 'admin_notices'       ] );

        // Admin: make the "Pending Approval" view actually filter the list
        add_action( 'pre_get_users', [ __CLASS__, 'filter_pending_view' ] );

        // Admin: user list column for approval status
        add_filter( 'manage_users_columns',       [ __CLASS__, 'add_column'  ] );
        add_filter( 'manage_users_custom_column', [ __CLASS__, 'column_data' ], 10, 3 );

        // Admin: one-click approve/deny via GET (Users list links, and the email links)
        add_action( 'admin_init', [ __CLASS__, 'handle_quick_action' ] );
        add_action( 'admin_init', [ __CLASS__, 'handle_email_action' ] );

        // Our notification (with Approve / Deny links) replaces WordPress's own "New User Registration"
        // email to the site admin while approval is required. The new user still gets WordPress's email.
        if ( seal_config( 'user_approval_required' ) === '1' ) {
            remove_action( 'register_new_user', 'wp_send_new_user_notifications' );
            add_action( 'register_new_user', static function ( $user_id ) { wp_send_new_user_notifications( $user_id, 'user' ); } );
        }

        // Registration: show pending message instead of auto-login
        add_filter( 'registration_redirect', [ __CLASS__, 'registration_redirect' ] );
    }

    // ── Registration ──────────────────────────────────────────────────────────

    /**
     * Marks a self-registered account as pending and emails the administrators Approve / Deny links. Accounts
     * created by an administrator, or deliberately by another part of the plugin, are not held.
     */
    public static function on_register( int $user_id ): void {
        // Check if approval module is enabled and approval required
        // Approval off, an administrator is creating the account in wp-admin, or another part of the plugin
        // created it on purpose (system staff's Manage Accounts filters this to false): nothing to approve.
        if ( seal_config( 'user_approval_required' ) !== '1' || ( is_user_logged_in() && current_user_can( 'edit_users' ) )
             || ! apply_filters( 'seal_user_needs_approval', true, $user_id ) ) {
            update_user_meta( $user_id, self::META_KEY, 'approved' );
            return;
        }

        update_user_meta( $user_id, self::META_KEY, 'pending' );

        $user  = get_userdata( $user_id );
        $sys   = seal_config( 'system_name' );
        $token = self::new_token( $user_id );
        $link  = static fn( string $d ) => add_query_arg( [ 'seal_decide' => $user_id, 'seal_action' => $d, 'seal_token' => $token ], admin_url( 'users.php' ) );

        $loc  = SEAL_Functions::primary_loc( $user_id );
        $lib  = $loc !== '' ? SEAL_Functions::fetch_lib_profile( $loc ) : [ 'ok' => false ];
        $libtxt = $loc === '' ? '<em>none given</em>'
                : ( $lib['ok'] ? esc_html( $lib['Name'] . ' (' . $loc . ')' ) . '<br>' . implode( '<br>', array_map( 'esc_html', array_filter( [ $lib['street1'], $lib['street2'], $lib['citystzip'] ], 'strlen' ) ) )
                               : esc_html( $loc ) . ' <strong style="color:#b32d2e">&mdash; not found in the library directory</strong>' );
        $systems = SEAL_Org_Rules::systems();
        $home    = (string) get_user_meta( $user_id, 'home_system', true );
        $subject = SEAL_Emails::subject( 'account_pending', [ 'name' => trim( $user->first_name . ' ' . $user->last_name ), 'username' => $user->user_login ] );
        $body    = SEAL_Functions::email_heading( 'New account waiting for approval' )
            . SEAL_Functions::email_p( 'Someone has registered for ' . esc_html( $sys ) . ' and is waiting for approval.' )
            . SEAL_Functions::email_buttons( [ [ $link( 'approve' ), 'Approve', '#0a6b35' ], [ $link( 'deny' ), 'Deny', '#b32d2e' ] ] )
            . SEAL_Functions::email_details( [
                  'Name'                    => trim( $user->first_name . ' ' . $user->last_name ),
                  'Username'                => $user->user_login,
                  'Email*'                  => SEAL_Functions::email_mailto( $user->user_email ),
                  'Library name (as typed)' => (string) get_user_meta( $user_id, 'institution', true ),
                  'Library (LOC code)*'     => $libtxt,
                  'Home system'             => $systems[ $home ] ?? $home,
                  'Work phone'              => (string) get_user_meta( $user_id, 'phone', true ),
              ] )
            . '<p style="margin:0;font-size:13px;color:#50575e;">You will be asked to sign in if you are not already. Approving emails the person that they can log in; denying emails them that the registration was not approved. '
            . 'These links work once and expire in ' . self::TOKEN_DAYS . ' days; after that use '
            . '<a href="' . esc_url( admin_url( 'users.php?seal_filter=pending' ) ) . '">Users &rarr; Pending Approval</a>.</p>';

        // Admin-only mail: still sent while Email test mode is on, so approvals can be tested.
        SEAL_Functions::send_email( seal_alert_emails(), $subject, $body, false, true );
    }

    /** Create the email-link token for a registration; only its hash is stored. */
    private static function new_token( int $user_id ): string {
        $token = wp_generate_password( 32, false, false );
        update_user_meta( $user_id, self::TOKEN_META, hash( 'sha256', $token ) . '|' . ( time() + self::TOKEN_DAYS * DAY_IN_SECONDS ) );
        return $token;
    }

    /** True if $token is this user's current, unexpired email-link token. */
    public static function token_ok( int $user_id, string $token ): bool {
        [ $hash, $exp ] = array_pad( explode( '|', (string) get_user_meta( $user_id, self::TOKEN_META, true ), 2 ), 2, '' );
        return $token !== '' && $hash !== '' && (int) $exp >= time() && hash_equals( $hash, hash( 'sha256', $token ) );
    }

    /**
     * Approve / Deny links from the notification email (wp-admin/users.php?seal_decide=ID&seal_action=...&seal_token=...).
     * wp-admin has already made the visitor sign in. Needs edit_users and a valid token; the token is
     * used up either way, so the other link stops working too.
     */
    public static function handle_email_action(): void {
        if ( ! isset( $_GET['seal_decide'], $_GET['seal_action'], $_GET['seal_token'] ) ) { return; }
        $uid    = (int) $_GET['seal_decide'];
        $action = sanitize_key( wp_unslash( $_GET['seal_action'] ) );
        $token  = sanitize_text_field( wp_unslash( $_GET['seal_token'] ) );
        $back   = admin_url( 'users.php?seal_filter=pending' );

        if ( ! current_user_can( 'edit_users' ) ) {
            wp_die( 'Only an administrator can approve or deny new users.', 'Not allowed', [ 'response' => 403 ] );
        }
        $user = get_userdata( $uid );
        if ( ! $user || ! in_array( $action, [ 'approve', 'deny' ], true ) ) {
            wp_safe_redirect( add_query_arg( 'seal_approval_done', 'missing', $back ) ); exit;
        }
        $status = (string) get_user_meta( $uid, self::META_KEY, true );
        if ( $status !== 'pending' ) {
            wp_safe_redirect( add_query_arg( [ 'seal_approval_done' => 'already', 'seal_status' => $status === 'denied' ? 'denied' : 'approved' ], $back ) ); exit;
        }
        if ( ! self::token_ok( $uid, $token ) ) {
            wp_safe_redirect( add_query_arg( 'seal_approval_done', 'expired', $back ) ); exit;
        }
        self::process_action( $uid, $action );
        wp_safe_redirect( add_query_arg( 'seal_approval_done', $action === 'approve' ? 'approved' : 'denied', admin_url( 'users.php' ) ) ); exit;
    }

    /** After registering, shows the login screen with the "awaiting approval" message. */
    public static function registration_redirect( string $url ): string {
        // After registration, show pending message
        return add_query_arg( 'seal_pending', '1', wp_login_url() );
    }

    // ── Block login for unapproved users ──────────────────────────────────────

    /** Refuses login for pending or denied accounts with a message saying why. */
    public static function block_unapproved( $user, string $username = '', string $password = '' ) {
        if ( is_wp_error( $user ) || ! ( $user instanceof WP_User ) ) {
            return $user;
        }

        if ( seal_config( 'user_approval_required' ) !== '1' ) {
            return $user;
        }

        $status = get_user_meta( $user->ID, self::META_KEY, true );

        if ( $status === 'pending' ) {
            return new WP_Error( 'seal_pending',
                sprintf( '<strong>Error:</strong> Your account is awaiting approval. You will receive an email when it is approved.' )
            );
        }

        if ( $status === 'denied' ) {
            return new WP_Error( 'seal_denied',
                sprintf( '<strong>Error:</strong> Your account registration was not approved. Please contact your library system administrator.' )
            );
        }

        return $user;
    }

    /** Placeholder hook callback; does nothing. */
    public static function noop(): void {}

    /** Sends a pending user who somehow got a session back to the login screen with the waiting message. */
    public static function redirect_pending( string $redirect_to, string $requested, $user ): string {
        if ( $user instanceof WP_User ) {
            $status = get_user_meta( $user->ID, self::META_KEY, true );
            if ( $status === 'pending' ) {
                wp_logout();
                return add_query_arg( 'seal_pending', '1', wp_login_url() );
            }
        }
        return $redirect_to;
    }

    // ── Admin: one-click approve/deny from email link ─────────────────────────

    /** Approve / Deny from the links on the Users screen (needs edit_users and a valid nonce). */
    public static function handle_quick_action(): void {
        if ( ! current_user_can( 'edit_users' ) ) { return; }
        if ( ! isset( $_GET['seal_approve'], $_GET['seal_action'], $_GET['_nonce'] ) ) { return; }

        $uid    = (int) $_GET['seal_approve'];
        $action = sanitize_key( $_GET['seal_action'] );
        $nonce  = sanitize_text_field( wp_unslash( $_GET['_nonce'] ) );

        if ( ! wp_verify_nonce( $nonce, 'seal_approve_' . $uid ) ) { return; }
        if ( ! in_array( $action, [ 'approve', 'deny' ], true ) ) { return; }
        // Per account: an ILL Administrator may not approve or deny an Administrator / ILL Administrator
        // (SEAL_Roles::guard_protected_users()), or themselves.
        if ( $uid === get_current_user_id() || ! current_user_can( 'edit_user', $uid ) ) { return; }

        self::process_action( $uid, $action );

        $msg = $action === 'approve' ? 'approved' : 'denied';
        wp_redirect( add_query_arg( 'seal_approval_done', $msg, admin_url( 'users.php' ) ) );
        exit;
    }

    /**
     * Approves or denies a pending account from the ILL account pages (User Accounts, Manage Accounts). The page
     * has already checked that the person may manage this account. False when it isn't pending any more.
     */
    public static function decide( int $uid, string $action ): bool {
        if ( ! in_array( $action, [ 'approve', 'deny' ], true ) || $uid === get_current_user_id() ) { return false; }
        if ( get_user_meta( $uid, self::META_KEY, true ) !== 'pending' ) { return false; }
        self::process_action( $uid, $action );
        return true;
    }

    /** True when the account is waiting for approval. */
    public static function is_pending( int $uid ): bool {
        return get_user_meta( $uid, self::META_KEY, true ) === 'pending';
    }

    /**
     * Approve and Deny buttons for one pending account on an ILL page: two small POST forms with a per-account
     * nonce ("seal_decide_<id>"). $hidden adds fields the page needs to come back to the same list.
     */
    public static function buttons( WP_User $u, string $action_url, array $hidden = [] ): string {
        $out = '';
        foreach ( [ 'approve' => [ 'Approve', 'btn-primary', '' ], 'deny' => [ 'Deny', 'btn-secondary', 'return confirm(\'Deny the registration for ' . esc_js( $u->display_name ) . '? They will be emailed that it was not approved.\');' ] ] as $act => [ $label, $cls, $confirm ] ) {
            $out .= '<form method="post" action="' . esc_url( $action_url ) . '" class="seal-decide-form" style="display:inline-block;margin:0 4px 4px 0;"' . ( $confirm ? ' onsubmit="' . esc_attr( $confirm ) . '"' : '' ) . '>'
                  . wp_nonce_field( 'seal_decide_' . $u->ID, 'seal_nonce', true, false )
                  . '<input type="hidden" name="seal_decide" value="' . esc_attr( $act ) . '"><input type="hidden" name="uid" value="' . (int) $u->ID . '">';
            foreach ( $hidden as $k => $v ) { $out .= '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( (string) $v ) . '">'; }
            $out .= '<button type="submit" class="' . $cls . '">' . $label . '<span class="seal-sr-only"> ' . esc_html( $u->display_name ) . '</span></button></form>';
        }
        return $out;
    }

    /**
     * Approves or denies one account and emails the user the outcome. The one-time email links stop working
     * once a decision is made.
     */
    private static function process_action( int $uid, string $action ): void {
        $user = get_userdata( $uid );
        if ( ! $user ) { return; }
        delete_user_meta( $uid, self::TOKEN_META );   // decided, however it was done: the email links stop working
        update_user_meta( $uid, 'seal_approval_by', get_current_user_id() . '|' . time() );

        $sys = seal_config( 'system_name' );

        if ( class_exists( 'SEAL_Audit' ) ) {
            SEAL_Audit::event( 'users', (string) $user->user_login, $action === 'approve' ? 'Registration approved' : 'Registration denied', [ 'uid' => $uid ] );
        }
        if ( $action === 'approve' ) {
            update_user_meta( $uid, self::META_KEY, 'approved' );
            $body = SEAL_Functions::email_heading( 'Your account is ready' )
                  . SEAL_Functions::email_p( 'Your ' . esc_html( $sys ) . ' account has been approved. You can now log in and start making interlibrary loan requests.' )
                  . SEAL_Functions::email_details( [ 'Username' => $user->user_login ] )
                  . SEAL_Functions::email_button( wp_login_url(), 'Log in' );
            SEAL_Functions::send_email( $user->user_email, SEAL_Emails::subject( 'account_approved', [ 'name' => trim( $user->first_name . ' ' . $user->last_name ), 'username' => $user->user_login ] ), $body );
        } else {
            update_user_meta( $uid, self::META_KEY, 'denied' );
            $body = SEAL_Functions::email_heading( 'Your registration was not approved' )
                  . SEAL_Functions::email_p( 'Your ' . esc_html( $sys ) . ' account registration was reviewed and was not approved.' )
                  . SEAL_Functions::email_p( 'If you believe this is a mistake, please contact your library system administrator.' );
            SEAL_Functions::send_email( $user->user_email, SEAL_Emails::subject( 'account_denied', [ 'name' => trim( $user->first_name . ' ' . $user->last_name ), 'username' => $user->user_login ] ), $body );
        }
    }

    // ── Admin: bulk actions ───────────────────────────────────────────────────

    /** Adds Approve users / Deny users to the Users list bulk actions. */
    public static function add_bulk_actions( array $actions ): array {
        $actions['seal_bulk_approve'] = 'Approve users';
        $actions['seal_bulk_deny']    = 'Deny users';
        return $actions;
    }

    /** Approves or denies every ticked user. */
    public static function handle_bulk( string $redirect, string $action, array $user_ids ): string {
        if ( ! in_array( $action, [ 'seal_bulk_approve', 'seal_bulk_deny' ], true ) ) { return $redirect; }
        if ( ! current_user_can( 'edit_users' ) ) { return $redirect; }

        $act = $action === 'seal_bulk_approve' ? 'approve' : 'deny';
        $count = 0;
        foreach ( $user_ids as $uid ) {
            $uid = (int) $uid;
            if ( $uid === get_current_user_id() || ! current_user_can( 'edit_user', $uid ) ) { continue; }   // see handle_quick_action()
            self::process_action( $uid, $act );
            $count++;
        }
        return add_query_arg( 'seal_approval_done', $act . ':' . $count, $redirect );
    }

    /** Limits the Users list to pending accounts when the Pending view is selected. */
    public static function filter_pending_view( WP_User_Query $q ): void {
        global $pagenow;
        if ( ! is_admin() || $pagenow !== 'users.php' || ! current_user_can( 'edit_users' ) ) { return; }
        if ( ! isset( $_GET['seal_filter'] ) || sanitize_key( wp_unslash( $_GET['seal_filter'] ) ) !== 'pending' ) { return; }
        $q->set( 'meta_key', self::META_KEY );
        $q->set( 'meta_value', 'pending' );
    }

    // ── Admin: notices ────────────────────────────────────────────────────────

    /** The awaiting-approval message on the login screen, and result notices on the Users screen. */
    public static function admin_notices(): void {
        // Pending user on login page
        if ( isset( $_GET['seal_pending'] ) && $GLOBALS['pagenow'] === 'wp-login.php' ) {
            echo '<div class="message"><p>Your account is awaiting approval. You will receive an email when it is approved.</p></div>';
        }

        // Approval/denial confirmation
        if ( isset( $_GET['seal_approval_done'] ) && current_user_can( 'edit_users' ) ) {
            $done = sanitize_text_field( $_GET['seal_approval_done'] );
            if ( $done === 'approved' ) {
                $msg = 'User approved and notified.';
            } elseif ( $done === 'denied' ) {
                $msg = 'User denied and notified.';
            } elseif ( $done === 'already' ) {
                $msg = 'That user was already ' . ( ( $_GET['seal_status'] ?? '' ) === 'denied' ? 'denied' : 'approved' ) . ', so nothing was changed.';
            } elseif ( $done === 'expired' ) {
                $msg = 'That approval link has expired or was replaced. Approve or deny the user from this list instead.';
            } elseif ( $done === 'missing' ) {
                $msg = 'That user no longer exists.';
            } elseif ( str_starts_with( $done, 'approve:' ) ) {
                $n   = (int) substr( $done, 8 );
                $msg = "{$n} user(s) approved.";
            } elseif ( str_starts_with( $done, 'deny:' ) ) {
                $n   = (int) substr( $done, 5 );
                $msg = "{$n} user(s) denied.";
            } else {
                $msg = 'Action completed.';
            }
            $type = in_array( $done, [ 'already', 'expired', 'missing' ], true ) ? 'warning' : 'success';
            echo '<div class="notice notice-' . $type . ' is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
        }
    }

    // ── Admin: Users list column ──────────────────────────────────────────────

    /** Adds the Approval column to the Users list. */
    public static function add_column( array $cols ): array {
        $cols['seal_approval'] = 'Approval';
        return $cols;
    }

    /** The Approval cell: status, with Approve / Deny links for pending accounts. */
    public static function column_data( string $val, string $col, int $uid ): string {
        if ( $col !== 'seal_approval' ) { return $val; }

        if ( seal_config( 'user_approval_required' ) !== '1' ) {
            return '<em style="color:#595959;">—</em>';
        }

        $status = get_user_meta( $uid, self::META_KEY, true ) ?: 'approved';
        $colors = [
            'pending'  => [ 'bg' => '#fff3cd', 'color' => '#856404', 'label' => '⏳ Pending' ],
            'approved' => [ 'bg' => '#d4edda', 'color' => '#155724', 'label' => '✅ Approved' ],
            'denied'   => [ 'bg' => '#f8d7da', 'color' => '#721c24', 'label' => '❌ Denied'  ],
        ];
        $c = $colors[ $status ] ?? $colors['approved'];

        $badge = '<span style="background:' . $c['bg'] . ';color:' . $c['color']
               . ';padding:2px 8px;border-radius:12px;font-size:.85em;">'
               . esc_html( $c['label'] ) . '</span>';

        if ( $status === 'pending' ) {
            $approve = esc_url( add_query_arg( [
                'seal_approve' => $uid,
                'seal_action'  => 'approve',
                '_nonce'       => wp_create_nonce( 'seal_approve_' . $uid ),
            ], admin_url( 'users.php' ) ) );
            $deny = esc_url( add_query_arg( [
                'seal_approve' => $uid,
                'seal_action'  => 'deny',
                '_nonce'       => wp_create_nonce( 'seal_approve_' . $uid ),
            ], admin_url( 'users.php' ) ) );
            $badge .= '<br><a href="' . $approve . '" style="font-size:.8em;color:#0a5c2e;">Approve</a>'
                    . ' | <a href="' . $deny . '" style="font-size:.8em;color:#b32d2e;">Deny</a>';
        }

        return $badge;
    }

    // ── Admin: "Pending" view in Users list ───────────────────────────────────

    /** Adds a "Pending (n)" view link above the Users list. */
    public static function add_pending_view( array $views ): array {
        if ( seal_config( 'user_approval_required' ) !== '1' ) { return $views; }

        $pending = count( get_users( [
            'meta_key'   => self::META_KEY,
            'meta_value' => 'pending',
            'number'     => -1,
            'fields'     => 'ids',
        ] ) );

        if ( $pending > 0 ) {
            $url = add_query_arg( 'seal_filter', 'pending', admin_url( 'users.php' ) );
            $views['seal_pending'] = '<a href="' . esc_url( $url ) . '">'
                . 'Pending Approval <span class="count">(' . $pending . ')</span></a>';
        }

        return $views;
    }
}
