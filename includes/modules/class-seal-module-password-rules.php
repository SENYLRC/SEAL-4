<?php
/**
 * SEAL_Module_PasswordRules
 *
 *  - Pwned-password check: at login, the password is checked against the
 *    Have I Been Pwned range API using k-anonymity (only the first 5 hex
 *    characters of the SHA-1 hash leave the server; the password never does).
 *    A breached password blocks the login and emails a reset link.
 *  - Strong passwords: minimum length, mixed character classes, and no
 *    username/email inside the password, on profile edits and password resets.
 *  - Force reset: admin can require every user (or selected roles) to choose
 *    a new password at their next login.
 *
 * If the breach API is unreachable the check fails open (login proceeds).
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class SEAL_Module_PasswordRules {

    const META_FORCE = 'seal_force_pw_reset';

    /** True once WordPress has accepted an application password in this request (not the account password). */
    private static bool $app_password = false;

    /** True when the Passwords module is on and not switched off from wp-config.php. */
    public static function active(): bool {
        return seal_config( 'module_password_rules' ) === '1' && ! seal_security_disabled();
    }

    /**
     * Hooks the login check (forced reset and breached-password check), the strong-password rules on profile
     * and reset forms, and clearing the forced-reset flag once a new password is set.
     */
    public static function init(): void {
        add_filter( 'authenticate',              [ __CLASS__, 'check_login' ], 50, 3 );
        add_action( 'application_password_did_authenticate', [ __CLASS__, 'note_app_password' ] );   // fires at authenticate priority 20
        add_action( 'after_password_reset',      [ __CLASS__, 'clear_flag' ], 10, 1 );
        add_action( 'profile_update',            [ __CLASS__, 'clear_flag_on_change' ], 10, 2 );

        if ( seal_config( 'pw_strong' ) === '1' || seal_config( 'pw_pwned' ) === '1' ) {
            add_action( 'user_profile_update_errors', [ __CLASS__, 'validate_profile' ], 10, 3 );
            add_action( 'validate_password_reset',    [ __CLASS__, 'validate_reset' ], 10, 2 );
        }
    }

    // ── Breach check ──────────────────────────────────────────────────────────

    /** @return bool|null true = breached, false = not found, null = could not check */
    public static function is_pwned( string $password ): ?bool {
        if ( $password === '' ) { return false; }
        $sha    = strtoupper( sha1( $password ) );
        $prefix = substr( $sha, 0, 5 );
        $suffix = substr( $sha, 5 );

        $res = wp_remote_get( 'https://api.pwnedpasswords.com/range/' . $prefix, [
            'timeout'     => 3,
            'redirection' => 0,
            'sslverify'   => true,
            'headers'     => [ 'Add-Padding' => 'true', 'User-Agent' => 'SEAL-ILL-System' ],
        ] );
        if ( is_wp_error( $res ) || (int) wp_remote_retrieve_response_code( $res ) !== 200 ) {
            return null;
        }
        foreach ( preg_split( '/\R/', (string) wp_remote_retrieve_body( $res ) ) as $line ) {
            [ $s, $count ] = array_pad( explode( ':', trim( $line ), 2 ), 2, '0' );
            if ( strcasecmp( $s, $suffix ) === 0 && (int) $count > 0 ) { return true; }
        }
        return false;
    }

    /**
     * True when the breached-password check applies to one of the user's roles (blank or 'all' = every role).
     */
    private static function role_applies( WP_User $user ): bool {
        $roles = seal_config( 'pw_pwned_roles' );
        if ( $roles === 'all' || $roles === '' ) { return true; }
        return (bool) array_intersect( array_map( 'trim', explode( ',', $roles ) ), (array) $user->roles );
    }

    // ── Login enforcement ─────────────────────────────────────────────────────

    /**
     * At login, after the password is accepted: refuses the login and emails a reset link when an administrator
     * forced a reset, or when the password is found in the breach list (for the chosen roles). Applies to every
     * way of signing in, XML-RPC included (it accepts the account password too). Only the breach check is skipped
     * for an application password, which is a random token, not a password the person chose.
     */
    public static function check_login( $user, $username = '', $password = '' ) {
        if ( ! ( $user instanceof WP_User ) || $password === '' || $password === null ) {
            return $user;
        }

        $forced = (string) get_user_meta( $user->ID, self::META_FORCE, true );
        $reason = '';

        if ( $forced !== '' ) {
            $reason = 'admin';
        } elseif ( ! self::$app_password && seal_config( 'pw_pwned' ) === '1' && self::role_applies( $user ) && self::is_pwned( (string) $password ) === true ) {
            $reason = 'pwned';
            update_user_meta( $user->ID, self::META_FORCE, 'pwned' );
        }

        if ( $reason === '' ) { return $user; }

        self::send_reset_link( $user );

        $msg = $reason === 'pwned'
            ? seal_config( 'pw_pwned_message' )
            : 'An administrator requires you to choose a new password.';

        return new WP_Error(
            'seal_pw_reset_required',
            esc_html( $msg . ' A password reset link has been emailed to the address on your account.' )
        );
    }

    /** Notes that this request signed in with an application password (WordPress's own check, before check_login()). */
    public static function note_app_password(): void {
        self::$app_password = true;
    }

    /** At most one reset email per user every 10 minutes. */
    private static function send_reset_link( WP_User $user ): void {
        $tkey = 'seal_pwmail_' . $user->ID;
        if ( get_transient( $tkey ) ) { return; }
        set_transient( $tkey, 1, 10 * MINUTE_IN_SECONDS );
        if ( function_exists( 'retrieve_password' ) ) {
            retrieve_password( $user->user_login );
        }
    }

    /** Removes the forced-reset flag (after a password reset). */
    public static function clear_flag( $user ): void {
        $id = is_object( $user ) ? (int) $user->ID : (int) $user;
        if ( $id ) { delete_user_meta( $id, self::META_FORCE ); }
    }

    /** Removes the forced-reset flag when the password is changed from a profile. */
    public static function clear_flag_on_change( int $user_id, $old_user = null ): void {
        if ( ! ( $old_user instanceof WP_User ) ) { return; }
        $new = get_userdata( $user_id );
        if ( $new && $new->user_pass !== $old_user->user_pass ) { self::clear_flag( $user_id ); }
    }

    // ── Strength rules ────────────────────────────────────────────────────────

    /** @return string[] human-readable problems; empty = acceptable */
    public static function validate( string $password, string $login = '', string $email = '' ): array {
        $errors = [];

        if ( seal_config( 'pw_strong' ) === '1' ) {
            $min = max( 8, (int) seal_config( 'pw_min_length' ) );
            if ( mb_strlen( $password ) < $min ) {
                $errors[] = sprintf( 'Password must be at least %d characters.', $min );
            }
            $classes = (int) (bool) preg_match( '/[a-z]/', $password )
                     + (int) (bool) preg_match( '/[A-Z]/', $password )
                     + (int) (bool) preg_match( '/\d/', $password )
                     + (int) (bool) preg_match( '/[^A-Za-z0-9]/', $password );
            if ( $classes < 3 ) {
                $errors[] = 'Password must include at least three of: lowercase letters, uppercase letters, numbers, symbols.';
            }
            $lower = mb_strtolower( $password );
            foreach ( array_filter( [ $login, strstr( $email, '@', true ) ] ) as $needle ) {
                if ( mb_strlen( $needle ) >= 4 && mb_strpos( $lower, mb_strtolower( $needle ) ) !== false ) {
                    $errors[] = 'Password must not contain your username or email name.';
                    break;
                }
            }
        }

        if ( ! $errors && seal_config( 'pw_pwned' ) === '1' && self::is_pwned( $password ) === true ) {
            $errors[] = 'That password appears in known data breaches. Please choose a different one.';
        }

        return $errors;
    }

    /**
     * Applies the strong-password rules when a password is set on a profile; adds an error for each rule it
     * breaks.
     */
    public static function validate_profile( $errors, $update, $user ): void {
        $pass = isset( $user->user_pass ) ? (string) $user->user_pass : '';
        if ( $pass === '' ) { return; }
        foreach ( self::validate( $pass, (string) ( $user->user_login ?? '' ), (string) ( $user->user_email ?? '' ) ) as $msg ) {
            $errors->add( 'seal_pw_rule', esc_html( $msg ) );
        }
    }

    /** Applies the strong-password rules on the password-reset form. */
    public static function validate_reset( $errors, $user ): void {
        $pass = isset( $_POST['pass1'] ) ? (string) wp_unslash( $_POST['pass1'] ) : '';
        if ( $pass === '' || ! ( $user instanceof WP_User ) ) { return; }
        foreach ( self::validate( $pass, $user->user_login, $user->user_email ) as $msg ) {
            $errors->add( 'seal_pw_rule', esc_html( $msg ) );
        }
    }

    // ── Admin: force reset ────────────────────────────────────────────────────

    /** @param string[] $roles empty = every user. @return int users flagged */
    public static function force_reset( array $roles = [], int $except_user = 0 ): int {
        $args = [ 'fields' => 'ID', 'number' => -1 ];
        if ( $roles ) { $args['role__in'] = $roles; }
        $n = 0;
        foreach ( get_users( $args ) as $id ) {
            if ( (int) $id === $except_user ) { continue; }
            update_user_meta( (int) $id, self::META_FORCE, 'admin' );
            $n++;
        }
        return $n;
    }
}
