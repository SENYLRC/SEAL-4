<?php
/**
 * SEAL_Audit — change history for email text, user accounts and security settings.
 *
 * What is recorded (who, when, from which IP address, and the value before and after):
 *   - Email text: every saved change on ILL → Email Text (SEAL_Emails hooks its options in here).
 *   - User accounts: every change to an account — registered or created, approved or denied, deleted, role, email,
 *     name, password changed / reset email sent / reset by link, library / LOC / system fields, two-factor set up,
 *     removed or reset. Changes people make to their own account are recorded too and marked "own account".
 *     Works on the wp-admin Users screens and on the ILL pages (User Accounts, Manage Accounts, My Profile),
 *     because it listens to WordPress's own user hooks.
 *   - Security settings: every setting on ILL → Security, Modules and Users & Registration, plus the actions on
 *     the Security screen (unlock an IP address, force password resets, accept file changes).
 * Passwords and secrets are never stored: a password shows as "changed", the invite code as "(changed)".
 *
 * Changes made in one request are gathered and written at the end of it: one entry per account, email or
 * settings screen, so one Save is one line. Kept in a non-autoloaded option, newest first, for KEEP_DAYS (two years:
 * older entries are deleted by a daily job and whenever the history is saved, and never shown), and at most MAX per area.
 * Shown on ILL → Change History (all, filterable) and under the Email Text form (email only).
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class SEAL_Audit {

    const OPT  = 'seal_audit_log';
    const MAX  = 1000;   // per area, so a flood in one area (e.g. registrations) can't push out security or email entries
    const KEEP_DAYS  = 730;   // retention: entries older than two years are deleted (personal data: IPs, earlier profile values)
    const PRUNE_HOOK = 'seal_audit_prune';
    const CATS = [ 'email' => 'Email text', 'users' => 'User accounts', 'security' => 'Security settings' ];

    /** User meta that is recorded when someone else changes it: key => label. */
    const USER_META = [
        'first_name' => 'First name', 'last_name' => 'Last name', 'institution' => 'Library name', 'address_loc_code' => 'LOC code',
        'seal_extra_locs' => 'Additional LOC codes', 'home_system' => 'Home system', 'oclc_symbol' => 'OCLC symbol', 'phone' => 'Phone',
        'work_phone' => 'Work phone', 'alt_email' => 'Alternate email', 'additional_email' => 'Additional email',
        'filter_own_system' => 'Show own system only', 'seal_2fa_on' => 'Two-factor (authenticator app)',
        'seal_2fa_keys' => 'Two-factor (security keys)',
    ];

    /** Settings groups recorded as security settings: group => screen name ('seal_sec_<tab>' is added from the Security tabs). */
    const SETTING_GROUPS = [ 'seal_ill_modules' => 'Modules', 'seal_ill_users' => 'Users & Registration', 'seal_ill_cron' => 'Cron Jobs' ];

    /** Options whose value is never shown. */
    const SECRET = [ 'seal_invite_code' ];

    /** Changes gathered during this request: "cat|target" => entry. */
    private static array $pending = [];

    /** Who to show for changes made with nobody signed in by a scheduled job (e.g. "scheduled job"); null = normal rules. */
    public static ?string $actor = null;

    /** Hooks for user accounts (every request: the ILL account pages are not in wp-admin) and settings (admin). */
    public static function init(): void {
        add_action( self::PRUNE_HOOK, [ __CLASS__, 'prune' ] );
        add_action( 'init', static function () { if ( ! wp_next_scheduled( self::PRUNE_HOOK ) ) { wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::PRUNE_HOOK ); } } );
        add_action( 'user_register',     [ __CLASS__, 'on_user_register' ], 99, 1 );
        add_action( 'profile_update',    [ __CLASS__, 'on_profile_update' ], 10, 2 );
        add_action( 'set_user_role',     [ __CLASS__, 'on_set_role' ], 10, 3 );
        add_action( 'add_user_role',     [ __CLASS__, 'on_add_role' ], 10, 2 );
        add_action( 'remove_user_role',  [ __CLASS__, 'on_remove_role' ], 10, 2 );
        add_action( 'update_user_meta',  [ __CLASS__, 'on_meta_update' ], 10, 4 );
        add_action( 'added_user_meta',   [ __CLASS__, 'on_meta_added' ], 10, 4 );
        add_action( 'deleted_user_meta', [ __CLASS__, 'on_meta_deleted' ], 10, 4 );
        add_action( 'delete_user',       [ __CLASS__, 'on_delete_user' ], 10, 1 );
        add_action( 'retrieve_password', [ __CLASS__, 'on_reset_sent' ], 10, 1 );
        add_action( 'after_password_reset', static function ( $user ) {
            if ( $user instanceof WP_User ) { self::event( 'users', (string) $user->user_login, 'Password reset using the emailed link', [ 'uid' => (int) $user->ID ] ); }
        }, 10, 1 );
        if ( is_admin() ) {
            add_action( 'updated_option', [ __CLASS__, 'on_option' ], 10, 3 );
            add_action( 'added_option',   static function ( $option, $value ) { self::on_option( (string) $option, '', $value ); }, 10, 2 );
        }
    }

    // ── Recording ─────────────────────────────────────────────────────────────

    /** A value as text for the history (arrays joined, booleans as on / off). */
    private static function text( $v ): string {
        if ( is_array( $v ) ) { return implode( ', ', array_map( static fn( $x ) => is_scalar( $x ) ? (string) $x : wp_json_encode( $x ), $v ) ); }
        if ( is_bool( $v ) ) { return $v ? 'on' : 'off'; }
        return mb_substr( trim( (string) $v ), 0, 2000 );
    }

    /** Adds one changed field to this request's entry for that target. Unchanged values are ignored. */
    public static function stage( string $cat, string $target, string $field, $old, $new, array $extra = [] ): void {
        $o = self::text( $old ); $n = self::text( $new );
        if ( $o === $n ) { return; }
        self::entry( $cat, $target, $extra )['changes'][] = [ 'field' => $field, 'old' => $o, 'new' => $n ];
    }

    /** Records something that happened (no before / after), e.g. "Account deleted". */
    public static function event( string $cat, string $target, string $what, array $extra = [] ): void {
        self::entry( $cat, $target, $extra )['events'][] = $what;
    }

    /** This request's entry for one target, created on first use (and the end-of-request write scheduled). */
    private static function &entry( string $cat, string $target, array $extra ): array {
        if ( ! self::$pending ) { add_action( 'shutdown', [ __CLASS__, 'flush' ] ); }
        $k = $cat . '|' . $target;
        if ( ! isset( self::$pending[ $k ] ) ) { self::$pending[ $k ] = [ 'cat' => $cat, 'target' => $target, 'changes' => [], 'events' => [] ] + $extra; }
        return self::$pending[ $k ];
    }

    /** Writes this request's entries (who, when, IP), newest first, keeping MAX. */
    public static function flush(): void {
        if ( ! self::$pending ) { return; }
        $user = wp_get_current_user();
        $ip   = class_exists( 'SEAL_Module_LoginProtection' ) ? SEAL_Module_LoginProtection::client_ip() : (string) ( $_SERVER['REMOTE_ADDR'] ?? '' );
        $log  = self::entries();
        foreach ( array_reverse( self::$pending ) as $e ) {
            if ( ! $e['changes'] && ! $e['events'] ) { continue; }
            $own = self::$actor === null && ( $e['cat'] === 'users' ) && ( ! $user->ID || (int) ( $e['uid'] ?? 0 ) === (int) $user->ID );   // own change, or not signed in (registering, reset link)
            $who = $user->ID ? (string) $user->user_login : ( self::$actor ?? 'not signed in' );
            array_unshift( $log, $e + [ 'time' => time(), 'user' => $who, 'own' => $own, 'ip' => self::$actor !== null && ! $user->ID ? '' : sanitize_text_field( $ip ) ] );
        }
        self::$pending = [];
        update_option( self::OPT, self::trim( $log ), false );
    }

    /** Oldest time an entry may have (two years ago). */
    public static function cutoff(): int { return time() - self::KEEP_DAYS * DAY_IN_SECONDS; }

    /** Drops entries older than KEEP_DAYS and keeps the newest MAX per area. */
    private static function trim( array $log ): array {
        $seen = []; $cut = self::cutoff();
        return array_values( array_filter( $log, static function ( $e ) use ( &$seen, $cut ) {
            if ( (int) ( $e['time'] ?? 0 ) < $cut ) { return false; }
            $c = (string) ( $e['cat'] ?? '' );
            $seen[ $c ] = ( $seen[ $c ] ?? 0 ) + 1;
            return $seen[ $c ] <= self::MAX;
        } ) );
    }

    /** Daily job: deletes entries past the retention period even when nothing new is being recorded. */
    public static function prune(): void {
        $log = get_option( self::OPT, [] );
        if ( ! is_array( $log ) ) { return; }
        $kept = self::trim( $log );
        if ( count( $kept ) !== count( $log ) ) { update_option( self::OPT, $kept, false ); }
    }

    /**
     * Every stored entry, newest first; only one category when $cat is given, one account when $uid is given,
     * and only targets containing $search (account username, email name, settings screen) when it is given.
     */
    public static function entries( string $cat = '', int $uid = 0, string $search = '' ): array {
        $log = get_option( self::OPT, [] );
        $log = is_array( $log ) ? $log : [];
        $search = strtolower( trim( $search ) );
        $cut    = self::cutoff();
        return array_values( array_filter( $log, static fn( $e ) => (int) ( $e['time'] ?? 0 ) >= $cut && ( $cat === '' || ( $e['cat'] ?? '' ) === $cat )
            && ( ! $uid || (int) ( $e['uid'] ?? 0 ) === $uid )
            && ( $search === '' || strpos( strtolower( (string) ( $e['target'] ?? '' ) ), $search ) !== false ) ) );
    }

    // ── User accounts ─────────────────────────────────────────────────────────

    /** True for a real account (every change to an account is recorded, whoever makes it). */
    private static function watched( int $uid ): bool {
        return $uid > 0;
    }

    /** The history target for an account: its username (and id, so it can be found after a rename). */
    private static function user_target( int $uid ): array {
        $u = get_userdata( $uid );
        return [ $u ? (string) $u->user_login : 'user #' . $uid, [ 'uid' => $uid ] ];
    }

    private static function role_names( array $roles ): string {
        $names = wp_roles()->get_names();
        return implode( ', ', array_map( static fn( $r ) => translate_user_role( $names[ $r ] ?? $r ), $roles ) );
    }

    public static function on_user_register( $uid ): void {
        $uid = (int) $uid;
        if ( ! self::watched( $uid ) ) { return; }
        [ $t, $x ] = self::user_target( $uid );
        $u    = get_userdata( $uid );
        $what = get_current_user_id() && get_current_user_id() !== $uid ? 'Account created' : 'Registered';
        if ( get_user_meta( $uid, 'seal_approval_status', true ) === 'pending' ) { $what .= ', waiting for approval'; }
        self::event( 'users', $t, $what . ( $u ? ' (' . $u->user_email . ', ' . self::role_names( (array) $u->roles ) . ')' : '' ), $x );
    }

    public static function on_profile_update( $uid, $old ): void {
        $uid = (int) $uid;
        if ( ! self::watched( $uid ) || ! $old instanceof WP_User ) { return; }
        $new = get_userdata( $uid );
        if ( ! $new ) { return; }
        [ $t, $x ] = self::user_target( $uid );
        self::stage( 'users', $t, 'Email', $old->user_email, $new->user_email, $x );
        self::stage( 'users', $t, 'Display name', $old->display_name, $new->display_name, $x );
        if ( $old->user_pass !== $new->user_pass ) { self::event( 'users', $t, 'Password changed', $x ); }
    }

    public static function on_set_role( $uid, $role, $old_roles ): void {
        $uid = (int) $uid;
        if ( ! self::watched( $uid ) ) { return; }
        [ $t, $x ] = self::user_target( $uid );
        self::stage( 'users', $t, 'Role', self::role_names( (array) $old_roles ), self::role_names( [ (string) $role ] ), $x );
    }

    public static function on_add_role( $uid, $role ): void {
        if ( ! self::watched( (int) $uid ) ) { return; }
        [ $t, $x ] = self::user_target( (int) $uid );
        self::event( 'users', $t, 'Role added: ' . self::role_names( [ (string) $role ] ), $x );
    }

    public static function on_remove_role( $uid, $role ): void {
        if ( ! self::watched( (int) $uid ) ) { return; }
        [ $t, $x ] = self::user_target( (int) $uid );
        self::event( 'users', $t, 'Role removed: ' . self::role_names( [ (string) $role ] ), $x );
    }

    /** Fires before an existing meta value changes, so the old value can still be read. */
    public static function on_meta_update( $mid, $uid, $key, $value ): void {
        if ( ! isset( self::USER_META[ $key ] ) || ! self::watched( (int) $uid ) ) { return; }
        [ $t, $x ] = self::user_target( (int) $uid );
        self::stage( 'users', $t, self::USER_META[ $key ], self::meta_text( $key, get_user_meta( (int) $uid, $key, true ) ), self::meta_text( $key, $value ), $x );
    }

    public static function on_meta_added( $mid, $uid, $key, $value ): void {
        if ( ! isset( self::USER_META[ $key ] ) || ! self::watched( (int) $uid ) ) { return; }
        [ $t, $x ] = self::user_target( (int) $uid );
        self::stage( 'users', $t, self::USER_META[ $key ], '', self::meta_text( $key, $value ), $x );
    }

    public static function on_meta_deleted( $mids, $uid, $key, $value ): void {
        if ( ! isset( self::USER_META[ $key ] ) || ! self::watched( (int) $uid ) ) { return; }
        [ $t, $x ] = self::user_target( (int) $uid );
        self::stage( 'users', $t, self::USER_META[ $key ], self::meta_text( $key, $value ) ?: 'set', '(removed)', $x );
    }

    /** Meta values as readable text (security keys by name, never their data). */
    private static function meta_text( string $key, $v ): string {
        if ( $key === 'seal_2fa_keys' ) {
            $v = maybe_unserialize( $v );
            return is_array( $v ) ? implode( ', ', array_map( static fn( $k ) => (string) ( $k['name'] ?? 'key' ), array_filter( $v, 'is_array' ) ) ) : '';
        }
        if ( $key === 'seal_2fa_on' ) { return (string) $v === '1' ? 'on' : ''; }
        return self::text( maybe_unserialize( $v ) );
    }

    public static function on_delete_user( $uid ): void {
        $uid = (int) $uid;
        $u   = get_userdata( $uid );
        if ( ! $u || ! self::watched( $uid ) ) { return; }
        self::event( 'users', (string) $u->user_login, 'Account deleted (' . trim( $u->first_name . ' ' . $u->last_name ) . ', ' . $u->user_email . ', '
            . self::role_names( (array) $u->roles ) . ', ' . (string) get_user_meta( $uid, 'institution', true ) . ')', [ 'uid' => $uid ] );
    }

    public static function on_reset_sent( $login ): void {
        $u = get_user_by( 'login', (string) $login );
        // Anyone can ask for a reset email for any username, so only resets sent by someone signed in are recorded:
        // otherwise an outsider could fill the history with requests and push real entries out.
        if ( ! $u || ! get_current_user_id() || ! self::watched( (int) $u->ID ) ) { return; }
        self::event( 'users', (string) $u->user_login, get_current_user_id() && get_current_user_id() !== (int) $u->ID ? 'Password reset email sent' : 'Password reset email requested', [ 'uid' => (int) $u->ID ] );
    }

    // ── Security settings ─────────────────────────────────────────────────────

    /** The screen a registered setting belongs to, or '' when it isn't a recorded security setting. */
    private static function screen_of( string $option ): string {
        $reg   = get_registered_settings();
        $group = (string) ( $reg[ $option ]['group'] ?? '' );
        if ( isset( self::SETTING_GROUPS[ $group ] ) ) { return self::SETTING_GROUPS[ $group ]; }
        if ( strpos( $group, 'seal_sec_' ) === 0 ) {
            $tab = substr( $group, 9 );
            return 'Security → ' . ( class_exists( 'SEAL_Security_Admin' ) ? ( SEAL_Security_Admin::TABS[ $tab ] ?? $tab ) : $tab );
        }
        return '';
    }

    /** The label of a setting's field on its screen (falls back to the option name). */
    private static function field_label( string $option ): string {
        global $wp_settings_fields;
        foreach ( (array) $wp_settings_fields as $sections ) {
            foreach ( (array) $sections as $fields ) {
                if ( isset( $fields[ $option ]['title'] ) ) { return wp_strip_all_tags( (string) $fields[ $option ]['title'] ); }
            }
        }
        return $option;
    }

    public static function on_option( $option, $old, $new ): void {
        $option = (string) $option;
        if ( strpos( $option, 'seal_' ) !== 0 ) { return; }
        $screen = self::screen_of( $option );
        if ( $screen === '' ) { return; }
        if ( in_array( $option, self::SECRET, true ) ) {
            if ( self::text( $old ) !== self::text( $new ) ) { self::event( 'security', $screen, self::field_label( $option ) . ' changed' ); }
            return;
        }
        self::stage( 'security', $screen, self::field_label( $option ), $old, $new );
    }

    // ── Display ───────────────────────────────────────────────────────────────

    /** Before / after side by side ("(blank)" for an empty value; email text says "(default)"). */
    private static function value( string $v, string $cat ): string {
        if ( $v === '' ) { return '<em>' . ( $cat === 'email' ? '(default)' : '(blank)' ) . '</em>'; }
        if ( $cat === 'email' && $v === '-' ) { return '<em>(left out)</em>'; }
        return nl2br( esc_html( $v ) );
    }

    /**
     * The history table: newest first, one row per entry with who / when / IP and a summary that opens to show
     * every change. $cat limits it to one category; $limit rows at most.
     */
    public static function render( string $cat = '', int $limit = 300, string $empty = 'No changes recorded yet.', int $uid = 0, string $search = '', bool $front = false ): void {
        $log = array_slice( self::entries( $cat, $uid, $search ), 0, $limit );
        if ( ! $log ) { echo '<p><em>' . esc_html( $empty ) . '</em></p>'; return; }
        echo ( $front ? '<div class="seal-table-wrap"><table class="seal-table seal-audit">' : '<table class="widefat striped seal-audit" style="max-width:1120px">' )
           . '<thead><tr><th scope="col" style="width:140px">When</th><th scope="col" style="width:150px">Who</th>'
           . ( $cat === '' ? '<th scope="col" style="width:130px">Area</th>' : '' ) . '<th scope="col">What changed</th></tr></thead><tbody>';
        foreach ( $log as $e ) {
            $ec      = (string) ( $e['cat'] ?? '' );
            $changes = (array) ( $e['changes'] ?? [] );
            $events  = (array) ( $e['events'] ?? [] );
            $parts   = array_merge( $events, array_map( static fn( $c ) => (string) $c['field'], $changes ) );
            $summary = (string) ( $e['target'] ?? '' ) . ': ' . ( count( $parts ) > 4 ? count( $parts ) . ' changes' : implode( '; ', $parts ) );
            echo '<tr><td>' . esc_html( wp_date( 'Y-m-d H:i', (int) ( $e['time'] ?? 0 ) ) ) . '</td><td>' . esc_html( (string) ( $e['user'] ?? '' ) )
               . ( ! empty( $e['own'] ) ? ' <small>(own account)</small>' : '' )
               . '<br><small>' . esc_html( (string) ( $e['ip'] ?? '' ) ) . '</small></td>'
               . ( $cat === '' ? '<td>' . esc_html( self::CATS[ $ec ] ?? $ec ) . '</td>' : '' ) . '<td>';
            if ( ! $changes ) { echo esc_html( $summary ); }
            else {
                echo '<details><summary>' . esc_html( $summary ) . '</summary>';
                foreach ( $events as $ev ) { echo '<p style="margin:8px 0 2px">' . esc_html( (string) $ev ) . '</p>'; }
                foreach ( $changes as $c ) {
                    echo '<div style="margin:10px 0 4px"><strong>' . esc_html( (string) $c['field'] ) . '</strong></div><div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">'
                       . '<div style="background:#fbeaea;padding:6px 8px;border-radius:4px"><small>Before</small><br>' . self::value( (string) $c['old'], $ec ) . '</div>'
                       . '<div style="background:#e6f4ea;padding:6px 8px;border-radius:4px"><small>After</small><br>' . self::value( (string) $c['new'], $ec ) . '</div></div>';
                }
                echo '</details>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>' . ( $front ? '</div>' : '' );
    }

    /** ILL → Change History: every recorded change, with a filter by area. Administrators only. */
    public static function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        $cat = isset( $_GET['area'] ) ? sanitize_key( wp_unslash( $_GET['area'] ) ) : '';
        if ( ! isset( self::CATS[ $cat ] ) ) { $cat = ''; }
        $q   = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
        echo '<div class="wrap"><h1>' . esc_html( seal_config( 'system_name' ) ) . ' ILL — 🕘 Change History</h1>'
           . '<p>Changes to email text, to user accounts (including people\'s changes to their own account, marked “own account”), and to security settings: who made them, when, from which IP address, and the values before and after. '
           . 'Passwords are never shown. Org Rules changes have their own history on the Org Rules screen.</p>'
           . '<p><strong>Retention:</strong> entries are kept for <strong>two years</strong>, then deleted automatically (at most the newest ' . number_format( self::MAX ) . ' in each area). '
           . 'The history holds IP addresses and earlier profile values, so it is personal data.</p>'
           . '<form method="get"><input type="hidden" name="page" value="seal-ill-history"><label for="seal-audit-area">Show </label><select id="seal-audit-area" name="area"><option value="">Everything</option>';
        foreach ( self::CATS as $k => $label ) { echo '<option value="' . esc_attr( $k ) . '"' . selected( $cat, $k, false ) . '>' . esc_html( $label ) . '</option>'; }
        echo '</select> <label for="seal-audit-q">Account or item </label><input type="search" id="seal-audit-q" name="q" value="' . esc_attr( $q ) . '" placeholder="username, email name, screen">'
           . ' <button class="button">Filter</button></form><br>';
        self::render( $cat, 500, 'Nothing matches.', 0, $q );
        echo '</div>';
    }
}
