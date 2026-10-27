<?php
/**
 * SEAL_Module_LastLogin
 *
 * Account activity for administrators, on the Users list and each user's profile:
 *   - Last Login   when the person last signed in with their password (seal_last_login).
 *   - Last Active  when they last loaded any page while signed in (seal_last_active). Different from
 *                  Last Login: WordPress keeps people signed in for days, so someone can use the site
 *                  daily without logging in again. Written at most every ACTIVE_EVERY seconds.
 *   - Created      when the account was made (WordPress's own user_registered).
 *   - Password     a strength rating (Weak / Fair / Good / Strong) taken when the password is typed in
 *                  plain text: at login, and when it is set or reset. WordPress only keeps a one-way hash,
 *                  so strength can't be read later; only the rating and its date are stored
 *                  (seal_pw_strength = "level|unix time|reason"), never the password.
 * The new columns and the profile section are shown to Administrators and ILL Administrators (seal_admin) only.
 *
 * No external dependencies — pure WP hooks.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class SEAL_Module_LastLogin {

    const META_KEY     = 'seal_last_login';
    const META_ACTIVE  = 'seal_last_active';
    const META_PW      = 'seal_pw_strength';
    const ACTIVE_EVERY = 300;   // seconds between Last Active writes for one person

    /**
     * Hooks login recording, last-activity tracking, the password-strength rating and the Users-screen columns.
     */
    public static function init(): void {
        // Record login timestamp
        add_action( 'wp_login',      [ __CLASS__, 'record_login'        ], 10, 2 );
        add_action( 'set_auth_cookie',[ __CLASS__, 'record_login_by_id' ], 10, 6 );

        // Admin: Users list column
        add_filter( 'manage_users_columns',       [ __CLASS__, 'add_column'      ] );
        add_filter( 'manage_users_custom_column', [ __CLASS__, 'column_data'     ], 10, 3 );
        add_filter( 'manage_users_sortable_columns',[ __CLASS__, 'sortable'      ] );
        add_action( 'pre_get_users',              [ __CLASS__, 'sort_query'      ] );

        // Last Active, throttled
        add_action( 'init', [ __CLASS__, 'record_active' ], 20 );

        // Password strength, rated while the plain password is in hand (never stored)
        add_filter( 'wp_authenticate_user', [ __CLASS__, 'rate_on_login' ], 99, 2 );
        add_action( 'wp_set_password',      [ __CLASS__, 'rate_on_set' ], 10, 2 );          // WordPress 6.2+
        add_action( 'password_reset',       [ __CLASS__, 'rate_on_reset' ], 10, 2 );
        add_action( 'profile_update',       [ __CLASS__, 'rate_on_profile' ], 10, 1 );

        // Admin: profile page display
        add_action( 'show_user_profile',  [ __CLASS__, 'profile_field' ] );
        add_action( 'edit_user_profile',  [ __CLASS__, 'profile_field' ] );
    }

    // ── Record login ──────────────────────────────────────────────────────────

    /** Stores the time of a successful login. */
    public static function record_login( string $login, WP_User $user ): void {
        update_user_meta( $user->ID, self::META_KEY, time() );
    }

    /** Catch cookie-set for cases where wp_login doesn't fire (e.g. API logins) */
    public static function record_login_by_id( string $auth_cookie, int $expire, int $expiration, int $user_id ): void {
        update_user_meta( $user_id, self::META_KEY, time() );
    }

    /** Last Active: any page load by a signed-in person, written at most every ACTIVE_EVERY seconds. */
    public static function record_active(): void {
        if ( ! is_user_logged_in() || wp_doing_cron() ) { return; }
        $uid  = get_current_user_id();
        $last = (int) get_user_meta( $uid, self::META_ACTIVE, true );
        if ( time() - $last >= self::ACTIVE_EVERY ) { update_user_meta( $uid, self::META_ACTIVE, time() ); }
    }

    // ── Password strength ─────────────────────────────────────────────────────

    /**
     * Rate a password: [ level 0-3, reason ]. 0 Weak, 1 Fair, 2 Good, 3 Strong. A rough guessing-cost
     * estimate in bits (length x character variety): under 40 Weak, 40-59 Fair, 60-79 Good, 80+ Strong.
     * Lowered for common passwords, repeats, sequences, few different characters, and the person's own
     * username or email name.
     */
    public static function rate( string $pw, string $login = '', string $email = '' ): array {
        $len = mb_strlen( $pw );
        if ( $len === 0 ) { return [ 0, 'empty' ]; }
        $lower = mb_strtolower( $pw );
        $common = [ 'password', 'passw0rd', '123456', '12345678', 'qwerty', 'letmein', 'welcome', 'admin', 'library', 'iloveyou', 'monkey', 'dragon', 'sunshine', 'abc123', 'changeme' ];
        foreach ( $common as $c ) { if ( strpos( $lower, $c ) !== false && $len < mb_strlen( $c ) + 6 ) { return [ 0, 'common password' ]; } }
        foreach ( array_filter( [ $login, (string) strstr( $email, '@', true ) ] ) as $n ) {
            if ( mb_strlen( $n ) >= 4 && strpos( $lower, mb_strtolower( $n ) ) !== false ) { return [ 0, 'contains username or email' ]; }
        }
        $pool = 0;
        if ( preg_match( '/[a-z]/', $pw ) )        { $pool += 26; }
        if ( preg_match( '/[A-Z]/', $pw ) )        { $pool += 26; }
        if ( preg_match( '/\d/', $pw ) )           { $pool += 10; }
        if ( preg_match( '/[^A-Za-z0-9]/', $pw ) ) { $pool += 32; }
        $bits = $len * log( max( 2, $pool ), 2 );
        $why  = $len . ' characters';
        if ( preg_match( '/(.)\1{2,}/', $pw ) )                                         { $bits *= 0.8; $why .= ', repeated characters'; }
        if ( preg_match( '/(0123|1234|2345|3456|4567|5678|6789|abcd|bcde|qwer|asdf)/i', $pw ) ) { $bits *= 0.8; $why .= ', sequence'; }
        $unique = count( array_unique( preg_split( '//u', $pw, -1, PREG_SPLIT_NO_EMPTY ) ) );
        if ( $unique <= 3 || $unique < $len / 3 )                                      { $bits *= 0.5; $why .= ', few different characters'; }
        $level = $bits < 40 ? 0 : ( $bits < 60 ? 1 : ( $bits < 80 ? 2 : 3 ) );
        return [ $level, $why ];
    }

    /**
     * Rates a password as it is set (weak / fair / strong, with the reason) and stores only the rating, never
     * the password, so administrators can see weak passwords on the Users screen.
     */
    private static function store_rating( int $uid, string $pw ): void {
        $u = get_userdata( $uid );
        if ( ! $u || $pw === '' ) { return; }
        [ $level, $why ] = self::rate( $pw, (string) $u->user_login, (string) $u->user_email );
        update_user_meta( $uid, self::META_PW, $level . '|' . time() . '|' . $why );
    }

    /** Successful password check at login (application-password and REST logins are random tokens, skipped). */
    public static function rate_on_login( $user, $password ) {
        if ( $user instanceof WP_User && is_string( $password ) && $password !== '' && ! ( defined( 'REST_REQUEST' ) && REST_REQUEST )
             && wp_check_password( $password, $user->user_pass, $user->ID ) ) {
            self::store_rating( (int) $user->ID, $password );
        }
        return $user;
    }

    /** Rates the password when it is set from a profile. */
    public static function rate_on_set( $password, $user_id ): void { self::store_rating( (int) $user_id, (string) $password ); }

    /** Rates the password when it is set through a password reset. */
    public static function rate_on_reset( $user, $new_pass ): void {
        if ( $user instanceof WP_User ) { self::store_rating( (int) $user->ID, (string) $new_pass ); }
    }

    /** Profile screen password change (the typed password is still in $_POST['pass1'] during this request). */
    public static function rate_on_profile( $user_id ): void {
        $pw = isset( $_POST['pass1'] ) ? (string) wp_unslash( $_POST['pass1'] ) : '';
        if ( $pw !== '' ) { self::store_rating( (int) $user_id, $pw ); }
    }

    /** [ label, colour, title text ] for the stored rating, or null if never rated. */
    public static function rating_badge( int $uid ): ?array {
        $raw = (string) get_user_meta( $uid, self::META_PW, true );
        if ( $raw === '' ) { return null; }
        [ $level, $ts, $why ] = array_pad( explode( '|', $raw, 3 ), 3, '' );
        $labels = [ [ 'Weak', '#8a1f1f', '#fbeaea' ], [ 'Fair', '#7a4b00', '#fcf0d9' ], [ 'Good', '#0a5c2e', '#e6f4ea' ], [ 'Strong', '#0a5c2e', '#d4edda' ] ];
        $l = $labels[ max( 0, min( 3, (int) $level ) ) ];
        return [ $l[0], $l[1], $l[2], 'Rated ' . date_i18n( 'Y-m-d', (int) $ts ) . ( $why !== '' ? ' (' . $why . ')' : '' ) ];
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** The user's last login as a formatted local date and time, or 'Never'. */
    public static function get_last_login( int $user_id ): string {
        $ts = get_user_meta( $user_id, self::META_KEY, true );
        if ( ! $ts ) { return 'Never'; }
        return date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $ts );
    }

    /** The user's last login as a Unix timestamp (0 = never). */
    public static function get_last_login_ts( int $user_id ): int {
        return (int) get_user_meta( $user_id, self::META_KEY, true );
    }

    // ── Users list column ─────────────────────────────────────────────────────

    /** Adds Last Login to the Users screen, plus Last Active, Created and Password (strength) for ILL admins. */
    public static function add_column( array $cols ): array {
        $cols['seal_last_login'] = 'Last Login';
        if ( current_user_can( 'seal_admin' ) ) {
            $cols['seal_last_active'] = 'Last Active';
            $cols['seal_created']     = 'Created';
            $cols['seal_pw']          = 'Password';
        }
        return $cols;
    }

    /** "3 hours ago" with the exact date and time on hover, or Never. */
    private static function ago( int $ts ): string {
        if ( ! $ts ) { return '<em style="color:#595959;">Never</em>'; }
        return '<span title="' . esc_attr( date_i18n( 'Y-m-d H:i', $ts ) ) . '">' . esc_html( human_time_diff( $ts, time() ) . ' ago' ) . '</span>';
    }

    /** The cell contents for those columns. */
    public static function column_data( string $val, string $col, int $uid ): string {
        switch ( $col ) {
            case 'seal_last_login':  return self::ago( (int) get_user_meta( $uid, self::META_KEY, true ) );
            case 'seal_last_active': return current_user_can( 'seal_admin' ) ? self::ago( (int) get_user_meta( $uid, self::META_ACTIVE, true ) ) : $val;
            case 'seal_created':
                if ( ! current_user_can( 'seal_admin' ) ) { return $val; }
                $u = get_userdata( $uid );
                $ts = $u ? strtotime( $u->user_registered . ' UTC' ) : 0;
                return $ts ? '<span title="' . esc_attr( date_i18n( 'Y-m-d H:i', $ts ) ) . '">' . esc_html( date_i18n( 'Y-m-d', $ts ) ) . '</span>' : '';
            case 'seal_pw':
                if ( ! current_user_can( 'seal_admin' ) ) { return $val; }
                $b = self::rating_badge( $uid );
                if ( ! $b ) { return '<em style="color:#595959;" title="Rated the next time they log in or change their password">Not rated yet</em>'; }
                return '<span title="' . esc_attr( $b[3] ) . '" style="background:' . $b[2] . ';color:' . $b[1] . ';padding:2px 8px;border-radius:12px;font-size:.85em;white-space:nowrap;">' . esc_html( $b[0] ) . '</span>';
        }
        return $val;
    }

    /** Makes those columns sortable. */
    public static function sortable( array $cols ): array {
        $cols['seal_last_login']  = 'seal_last_login';
        $cols['seal_last_active'] = 'seal_last_active';
        $cols['seal_created']     = 'registered';   // WordPress's own sort by registration date
        $cols['seal_pw']          = 'seal_pw_strength';
        return $cols;
    }

    /** Sorts the Users list by the stored meta value when one of those columns is clicked. */
    public static function sort_query( WP_User_Query $q ): void {
        global $pagenow;
        if ( ! is_admin() || $pagenow !== 'users.php' ) { return; }
        $map = [ 'seal_last_login' => self::META_KEY, 'seal_last_active' => self::META_ACTIVE, 'seal_pw_strength' => self::META_PW ];
        $ob  = (string) $q->get( 'orderby' );
        if ( isset( $map[ $ob ] ) ) {
            $q->set( 'meta_key', $map[ $ob ] );
            $q->set( 'orderby', $ob === 'seal_pw_strength' ? 'meta_value' : 'meta_value_num' );
        }
    }

    // ── Profile field ─────────────────────────────────────────────────────────

    /**
     * Last login, last activity and password-strength rating on a user's profile (ILL administrators only).
     */
    public static function profile_field( WP_User $user ): void {
        if ( ! current_user_can( 'seal_admin' ) ) { return; }
        $fmt = static fn( $ts ) => $ts ? esc_html( date_i18n( 'Y-m-d H:i', (int) $ts ) ) : 'Never';
        $b   = self::rating_badge( (int) $user->ID );
        ?>
        <h3>Account Activity <span style="font-weight:normal;font-size:13px;color:#50575e;">(administrators only)</span></h3>
        <table class="form-table" role="presentation">
            <tr><th scope="row">Created</th><td><?php echo $fmt( strtotime( $user->user_registered . ' UTC' ) ); ?></td></tr>
            <tr><th scope="row">Last Login</th><td><?php echo $fmt( get_user_meta( $user->ID, self::META_KEY, true ) ); ?>
                <p class="description">The last time they signed in with their password.</p></td></tr>
            <tr><th scope="row">Last Active</th><td><?php echo $fmt( get_user_meta( $user->ID, self::META_ACTIVE, true ) ); ?>
                <p class="description">The last time they used the site while signed in (they can stay signed in for days, so this is often later than Last Login).</p></td></tr>
            <tr><th scope="row">Password Strength</th><td><?php echo $b ? esc_html( $b[0] . ' — ' . $b[3] ) : 'Not rated yet'; ?>
                <p class="description">Rated when the password is typed at login or when it is changed. Only the rating is kept, never the password.</p></td></tr>
        </table>
        <?php
    }
}
