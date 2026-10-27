<?php
/**
 * SEAL_Module_2FA — two-factor authentication. Methods, in any mix, each person's own choice:
 *   - security keys (YubiKey and other FIDO2 keys) and passkeys (Windows Hello, Touch ID, Android) — WebAuthn,
 *     checked by SEAL_WebAuthn (includes/class-seal-webauthn.php) with the bundled lbuchs/webauthn library;
 *   - 6-digit codes from an authenticator app (TOTP, SEAL_TOTP);
 *   - 10 one-time backup codes, created with the first method and deleted with the last.
 * "On" (is_enrolled) means an authenticator app or at least one key.
 *
 * Login flow (wp-login.php): the password is verified first; if the user has two-factor on, the session WordPress
 * just issued is withdrawn (cookie and server-side token) and a second screen asks for a key touch
 * ("Use security key or passkey", JSON at wp-login.php?action=seal_2fa_key) or a code (action=seal_2fa). Both
 * share one challenge key (10 minutes, 5 tries) and a per-user throttle (10 failures / 15 minutes); failures also
 * fire wp_login_failed, so Login Protection's lockout applies.
 *
 * Set-up lives on the ILL "My Profile" page (/illuser) so library staff never need wp-admin: add or remove keys
 * (current password required; adding uses admin-ajax actions seal_2fa_key_options / seal_2fa_key_add), set up or
 * remove the authenticator app, new backup codes, turn everything off. Every change emails the person and signs
 * out their other sessions. Roles listed under "Require 2FA for" are sent to that page until they add a method,
 * and can't remove their last one.
 *
 * TOTP secrets are stored encrypted (SEAL_DB::encrypt_pass). Backup codes are stored as password hashes and keep
 * working even if the WordPress secret keys are rotated. Key records hold only public keys. Administrators can
 * reset a user's two-factor (keys, app and codes) from that user's WordPress profile.
 *
 * XML-RPC and application passwords would sign in with the password alone, so while this module is on it turns
 * both off itself (whatever the Login Protection and Registration Fields settings say).
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class SEAL_Module_2FA {

    const M_SECRET  = 'seal_2fa_secret';
    const M_PENDING = 'seal_2fa_pending';
    const M_ON      = 'seal_2fa_on';
    const M_BACKUP  = 'seal_2fa_backup';
    const M_LAST    = 'seal_2fa_last';
    const M_CHAL    = 'seal_2fa_chal';
    const M_FAILS   = 'seal_2fa_fails';
    const MAX_TRIES = 5;
    const THROTTLE_MAX    = 10;   // wrong codes allowed per user…
    const THROTTLE_WINDOW = 900;  // …within this many seconds

    /** admin-ajax calls a user who still has to set up two-factor may make: adding a security key. */
    const SETUP_AJAX = [ 'seal_2fa_key_options', 'seal_2fa_key_add' ];

    /** Session token issued during this request's password step, captured so it can be destroyed. */
    private static string $captured_token = '';

    /**
     * True when the Two-Factor module is on and not switched off from wp-config.php (seal_security_disabled()).
     */
    public static function active(): bool {
        return seal_config( 'module_2fa' ) === '1' && ! seal_security_disabled();
    }

    /**
     * Hooks the second login step (on_login → challenge form → handle_challenge), the set-up gate for roles
     * that require two-factor, the login notices, and the admin reset on user profiles.
     */
    public static function init(): void {
        add_action( 'set_logged_in_cookie',        [ __CLASS__, 'capture_token' ], 10, 6 );
        add_action( 'wp_login',                    [ __CLASS__, 'on_login' ], 1, 2 );
        add_action( 'login_form_seal_2fa',         [ __CLASS__, 'handle_challenge' ] );
        add_action( 'login_form_seal_2fa_key',     [ __CLASS__, 'handle_key_login' ] );      // security key at sign-in (JSON)
        add_action( 'wp_ajax_seal_2fa_key_options', [ __CLASS__, 'ajax_key_options' ] );     // add a key: options (JSON)
        add_action( 'wp_ajax_seal_2fa_key_add',     [ __CLASS__, 'ajax_key_add' ] );         // add a key: finish (JSON)
        add_filter( 'wp_login_errors',             [ __CLASS__, 'login_notices' ] );
        add_action( 'template_redirect',           [ __CLASS__, 'gate' ], 5 );
        add_action( 'admin_init',                  [ __CLASS__, 'gate' ], -9999 );   // before any handler that changes something (e.g. Approve / Deny links)

        add_action( 'show_user_profile',           [ __CLASS__, 'profile_section' ] );
        add_action( 'edit_user_profile',           [ __CLASS__, 'profile_section' ] );
        add_action( 'edit_user_profile_update',    [ __CLASS__, 'profile_admin_reset' ] );

        // Sign-ins that never reach the second step: XML-RPC (wp_authenticate without wp_login) and application passwords.
        add_filter( 'xmlrpc_enabled',                        '__return_false' );
        add_filter( 'wp_is_application_passwords_available', '__return_false' );
        add_filter( 'authenticate',                          [ __CLASS__, 'no_xmlrpc_login' ], 100 );
    }

    /** Refuses any password sign-in made through XML-RPC (backstop in case something turns XML-RPC back on). */
    public static function no_xmlrpc_login( $user ) {
        if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST && $user instanceof WP_User ) {
            return new WP_Error( 'seal_2fa_xmlrpc', __( 'Sign-in through XML-RPC is turned off on this site.', 'seal-ill' ) );
        }
        return $user;
    }

    /** True when two-factor is on, the user's role requires it and they haven't set up a method yet. */
    public static function needs_setup( WP_User $user ): bool {
        return self::active() && $user->exists() && self::role_required( $user ) && ! self::is_enrolled( $user->ID );
    }

    // ── State ─────────────────────────────────────────────────────────────────

    /** True when the user has two-factor on: an authenticator app or at least one security key / passkey. */
    public static function is_enrolled( int $uid ): bool {
        return self::app_on( $uid ) || self::keys_on( $uid );
    }

    /** True when the authenticator-app method is set up. */
    public static function app_on( int $uid ): bool {
        return get_user_meta( $uid, self::M_ON, true ) === '1';
    }

    /** True when the user has at least one security key or passkey (and the server can check them). */
    public static function keys_on( int $uid ): bool {
        return class_exists( 'SEAL_WebAuthn' ) && SEAL_WebAuthn::has_keys( $uid );
    }

    /** True when one of the user's roles is listed under Security → Two-Factor → Require 2FA for. */
    public static function role_required( WP_User $user ): bool {
        $req = array_filter( array_map( 'trim', explode( ',', seal_config( 'tfa_roles' ) ) ) );
        return $req && array_intersect( $req, (array) $user->roles );
    }

    /**
     * The user's decrypted authenticator secret (or the pending one, with $meta = M_PENDING); '' when none is
     * stored. Secrets are stored encrypted with SEAL_DB::encrypt_pass().
     */
    private static function secret( int $uid, string $meta = self::M_SECRET ): string {
        $enc = (string) get_user_meta( $uid, $meta, true );
        return $enc === '' ? '' : SEAL_DB::decrypt_pass( $enc );
    }

    /**
     * Removes the user's two-factor set-up completely (secret, backup codes, throttle and challenge state).
     * Used by the admin reset and when the user turns it off.
     */
    public static function reset_user( int $uid ): void {
        foreach ( [ self::M_SECRET, self::M_PENDING, self::M_ON, self::M_BACKUP, self::M_LAST, self::M_CHAL, self::M_FAILS ] as $m ) {
            delete_user_meta( $uid, $m );
        }
        if ( class_exists( 'SEAL_WebAuthn' ) ) { SEAL_WebAuthn::remove_all( $uid ); }
    }

    /**
     * After any change to someone's sign-in methods: sign out their other sessions (this one stays) and email
     * them a notice, so a change they didn't make doesn't go unnoticed.
     */
    private static function security_changed( WP_User $user, string $what ): void {
        if ( class_exists( 'WP_Session_Tokens' ) ) {
            $mgr = WP_Session_Tokens::get_instance( $user->ID );
            get_current_user_id() === (int) $user->ID ? $mgr->destroy_others( wp_get_session_token() ) : $mgr->destroy_all();
        }
        if ( ! $user->user_email ) { return; }
        $ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );
        SEAL_Functions::send_email( $user->user_email, SEAL_Emails::subject( 'security_changed', [ 'name' => $user->display_name, 'username' => $user->user_login ] ),
            SEAL_Functions::email_heading( 'Your sign-in security settings changed' )
            . SEAL_Functions::email_p( esc_html( $what ) . ' Your other signed-in sessions have been signed out.' )
            . SEAL_Functions::email_details( [ 'Account' => $user->user_login, 'When' => wp_date( 'F j, Y g:i a' ), 'From address' => $ip ] )
            . SEAL_Functions::email_p( '<strong>If you didn\'t make this change,</strong> change your password now and contact your ILL office.' )
            . SEAL_Functions::email_button( home_url( '/illuser#two-factor' ), 'Review your sign-in settings' ) );
    }

    /** Re-check the current password before a key change; a wrong one counts toward Login Protection's lockout. */
    private static function password_ok( WP_User $user, string $password ): bool {
        if ( $password !== '' && wp_check_password( $password, $user->user_pass, $user->ID ) ) { return true; }
        self::note_failure( $user->ID );
        do_action( 'wp_login_failed', $user->user_login, new WP_Error( 'seal_2fa_password', 'Wrong password while changing two-factor settings.' ) );
        return false;
    }

    /** Backup codes for someone whose first method this is (or who has none left); returns the plain codes or []. */
    private static function codes_if_needed( int $uid ): array {
        return self::backup_left( $uid ) > 0 ? [] : self::make_backup_codes( $uid );
    }

    /** Unused backup codes. (get_user_meta() returns '' when there are none, and (array) '' is a one-item list.) */
    public static function backup_left( int $uid ): int {
        $h = get_user_meta( $uid, self::M_BACKUP, true );
        return is_array( $h ) ? count( array_filter( $h, 'is_string' ) ) : 0;
    }

    // ── Code verification (TOTP or backup) ────────────────────────────────────

    /**
     * Checks a code typed at the second login step. Six digits are checked as an authenticator code (a code's
     * time step can't be reused, so a seen code can't be replayed); anything else is checked against the unused
     * backup codes, and a matching backup code is used up.
     */
    private static function verify_code( WP_User $user, string $code ): bool {
        $code   = trim( $code );
        $secret = self::secret( $user->ID );

        if ( $secret !== '' && preg_match( '/^\s*\d[\d\s]{5,}$/', $code ) ) {
            $last = (int) get_user_meta( $user->ID, self::M_LAST, true );
            $step = null;
            if ( SEAL_TOTP::verify( $secret, $code, $last, $step ) ) {
                update_user_meta( $user->ID, self::M_LAST, (int) $step );
                return true;
            }
            return false;
        }

        $norm   = strtolower( preg_replace( '/[^a-z0-9]/i', '', $code ) );
        $hashes = (array) get_user_meta( $user->ID, self::M_BACKUP, true );
        if ( strlen( $norm ) < 8 ) { return false; }
        foreach ( $hashes as $i => $hash ) {
            if ( is_string( $hash ) && password_verify( $norm, $hash ) ) {
                unset( $hashes[ $i ] );
                update_user_meta( $user->ID, self::M_BACKUP, array_values( $hashes ) );
                return true;
            }
        }
        return false;
    }

    /** @return string[] plain codes (shown once); hashes are stored. */
    private static function make_backup_codes( int $uid ): array {
        $plain  = [];
        $hashes = [];
        for ( $i = 0; $i < 10; $i++ ) {
            $c        = strtolower( substr( str_replace( [ '=', '+', '/' ], '', base64_encode( random_bytes( 9 ) ) ), 0, 10 ) );
            $plain[]  = substr( $c, 0, 5 ) . '-' . substr( $c, 5 );
            $hashes[] = password_hash( $c, PASSWORD_DEFAULT );
        }
        update_user_meta( $uid, self::M_BACKUP, $hashes );
        return $plain;
    }

    // ── Login: step 2 ─────────────────────────────────────────────────────────

    /**
     * Second login step. Runs right after the password is accepted for an enrolled user: withdraws the session
     * WordPress just created (cookie and server-side token, so the password alone never gives a working login)
     * and shows the code form instead.
     */
    public static function on_login( $user_login, $user ): void {
        if ( ! ( $user instanceof WP_User ) || ! self::is_enrolled( $user->ID ) ) { return; }
        if ( defined( 'XMLRPC_REQUEST' ) || defined( 'REST_REQUEST' ) ) { return; }

        // Withdraw the session WordPress just issued. Clearing the browser cookie is not
        // enough: the valid cookie is still in this response's headers, so the server-side
        // session token must be destroyed too or the password alone would be a working login.
        if ( self::$captured_token !== '' ) {
            WP_Session_Tokens::get_instance( $user->ID )->destroy( self::$captured_token );
            self::$captured_token = '';
        }
        wp_clear_auth_cookie();

        if ( ! function_exists( 'login_header' ) ) {
            wp_die( esc_html__( 'Two-factor authentication is required. Please sign in from the login page.', 'seal-ill' ), '', [ 'response' => 403 ] );
        }

        $redirect = isset( $_REQUEST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_REQUEST['redirect_to'] ) ) : '';
        self::render_challenge( $user, self::new_challenge( $user->ID ), $redirect, ! empty( $_REQUEST['rememberme'] ) );
        exit;
    }

    /** Remembers the session token WordPress just issued, so on_login() can destroy it. */
    public static function capture_token( $cookie = '', $expire = 0, $expiration = 0, $user_id = 0, $scheme = '', $token = '' ): void {
        self::$captured_token = (string) $token;
    }

    // ── Per-user throttle (independent of Login Protection) ───────────────────

    /**
     * True when the user has had THROTTLE_MAX wrong codes within THROTTLE_WINDOW seconds (separate from Login
     * Protection's per-address lockout).
     */
    private static function throttled( int $uid ): bool {
        $f = get_user_meta( $uid, self::M_FAILS, true );
        return is_array( $f )
            && (int) ( $f['n'] ?? 0 ) >= self::THROTTLE_MAX
            && time() - (int) ( $f['first'] ?? 0 ) < self::THROTTLE_WINDOW;
    }

    /** Counts one wrong code towards the per-user throttle. */
    private static function note_failure( int $uid ): void {
        $f = get_user_meta( $uid, self::M_FAILS, true );
        if ( ! is_array( $f ) || time() - (int) ( $f['first'] ?? 0 ) >= self::THROTTLE_WINDOW ) {
            $f = [ 'n' => 0, 'first' => time() ];
        }
        $f['n'] = (int) $f['n'] + 1;
        update_user_meta( $uid, self::M_FAILS, $f );
    }

    /**
     * Starts a 10-minute code challenge for the user and returns its one-time key (only a SHA-256 hash of the
     * key is stored).
     */
    private static function new_challenge( int $uid ): string {
        $key = wp_generate_password( 32, false );
        update_user_meta( $uid, self::M_CHAL, [ 'hash' => hash( 'sha256', $key ), 'expires' => time() + 10 * MINUTE_IN_SECONDS, 'tries' => 0 ] );
        return $key;
    }

    /** True when $key matches the user's current challenge and it hasn't expired or run out of tries. */
    private static function challenge_ok( int $uid, string $key ): bool {
        $c = get_user_meta( $uid, self::M_CHAL, true );
        if ( ! is_array( $c ) || empty( $c['hash'] ) || (int) $c['expires'] < time() || (int) $c['tries'] >= self::MAX_TRIES ) {
            return false;
        }
        return hash_equals( (string) $c['hash'], hash( 'sha256', $key ) );
    }

    /**
     * The "enter your code" form on the WordPress login screen, carrying the challenge key, redirect and
     * remember-me choice.
     */
    private static function render_challenge( WP_User $user, string $key, string $redirect, bool $remember, ?WP_Error $error = null ): void {
        $action  = esc_url( site_url( 'wp-login.php?action=seal_2fa', 'login_post' ) );
        $has_key = self::keys_on( $user->ID );
        $has_app = self::app_on( $user->ID );
        $msg = $has_key && $has_app ? 'Touch your security key (or use your passkey), or enter the 6-digit code from your authenticator app. Lost both? Enter one of your backup codes.'
             : ( $has_key ? 'Touch your security key or use your passkey. Lost it? Enter one of your backup codes.'
                          : 'Enter the 6-digit code from your authenticator app. Lost your device? Enter one of your backup codes instead.' );
        login_header( esc_html__( 'Two-factor authentication', 'seal-ill' ), '<p class="message">' . esc_html( $msg ) . '</p>', $error );
        ?>
        <form name="seal2faform" id="seal2faform" action="<?php echo $action; ?>" method="post">
            <?php if ( $has_key ) : // inside the login form's own box, so it uses the login screen's layout ?>
            <p class="seal-wa-login">
                <button type="button" class="button button-primary button-large" style="display:block;width:100%;float:none;margin:0;"
                    data-webauthn-login
                    data-url="<?php echo esc_url( site_url( 'wp-login.php?action=seal_2fa_key', 'login_post' ) ); ?>"
                    data-uid="<?php echo (int) $user->ID; ?>" data-key="<?php echo esc_attr( $key ); ?>"
                    data-redirect="<?php echo esc_attr( $redirect ); ?>" data-remember="<?php echo $remember ? '1' : ''; ?>">
                    <?php esc_html_e( 'Use security key or passkey', 'seal-ill' ); ?></button>
            </p>
            <p data-webauthn-status role="status" aria-live="polite" style="margin:8px 0 0;"></p>
            <p class="seal-wa-or" style="margin:16px 0 12px;text-align:center;color:#50575e;">&mdash; <?php esc_html_e( 'or enter a code', 'seal-ill' ); ?> &mdash;</p>
            <?php endif; ?>
            <p>
                <label for="seal_2fa_code"><?php echo esc_html( $has_app ? __( 'Authentication code', 'seal-ill' ) : __( 'Backup code', 'seal-ill' ) ); ?></label>
                <input type="text" name="seal_2fa_code" id="seal_2fa_code" class="input" value="" size="20"
                       autocomplete="one-time-code" inputmode="text" autocapitalize="off" spellcheck="false" required>
            </p>
            <input type="hidden" name="seal_2fa_uid" value="<?php echo (int) $user->ID; ?>">
            <input type="hidden" name="seal_2fa_key" value="<?php echo esc_attr( $key ); ?>">
            <input type="hidden" name="redirect_to"  value="<?php echo esc_attr( $redirect ); ?>">
            <?php if ( $remember ) : ?><input type="hidden" name="rememberme" value="forever"><?php endif; ?>
            <p class="submit">
                <input type="submit" class="button <?php echo $has_key ? '' : 'button-primary'; ?> button-large" value="<?php esc_attr_e( 'Verify', 'seal-ill' ); ?>">
            </p>
        </form>
        <p id="nav"><a href="<?php echo esc_url( wp_login_url() ); ?>"><?php esc_html_e( 'Cancel and sign in again', 'seal-ill' ); ?></a></p>
        <?php if ( $has_key ) : ?>
        <script src="<?php echo esc_url( SEAL_ILL_PLUGIN_URL . 'assets/js/seal-webauthn.js?ver=' . SEAL_ILL_VERSION ); ?>"></script>
        <?php endif;
        login_footer( $has_key ? '' : 'seal_2fa_code' );
    }

    /** JSON reply for the key endpoints, then stop. */
    private static function json( array $data, int $status = 200 ): void {
        nocache_headers();
        wp_send_json( $data, $status );
    }

    /**
     * Security key at the second sign-in step (wp-login.php?action=seal_2fa_key, POST, JSON). op=options returns
     * the browser options for this user's keys; op=verify checks the touch and signs the person in. Uses the same
     * challenge key, try limit and throttle as the code form; failures count toward Login Protection.
     */
    public static function handle_key_login(): void {
        if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' || ! class_exists( 'SEAL_WebAuthn' ) ) { self::json( [ 'ok' => false, 'error' => 'Not available.' ], 400 ); }
        $uid  = isset( $_POST['uid'] ) ? absint( $_POST['uid'] ) : 0;
        $key  = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';
        $user = $uid ? get_userdata( $uid ) : false;
        $expired = add_query_arg( 'seal_2fa', 'expired', wp_login_url() );

        if ( ! $user || ! self::challenge_ok( $uid, $key ) || ! self::keys_on( $uid ) ) { self::json( [ 'ok' => false, 'redirect' => $expired ] ); }
        if ( self::throttled( $uid ) ) { self::json( [ 'ok' => false, 'error' => 'Too many failed attempts. Please wait 15 minutes and try again.' ] ); }

        $op = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';
        if ( $op === 'options' ) {
            self::json( [ 'ok' => true, 'options' => SEAL_WebAuthn::login_options( $user ) ] );
        }

        $b = static fn( string $k ): string => (string) base64_decode( (string) wp_unslash( $_POST[ $k ] ?? '' ), true );
        if ( $op === 'verify' && SEAL_WebAuthn::verify_login( $user, $b( 'credentialId' ), $b( 'clientDataJSON' ), $b( 'authenticatorData' ), $b( 'signature' ) ) ) {
            delete_user_meta( $uid, self::M_CHAL );
            delete_user_meta( $uid, self::M_FAILS );
            $remember = ! empty( $_POST['remember'] );
            $redirect = isset( $_POST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) ) : '';
            wp_set_auth_cookie( $uid, $remember );
            $to = apply_filters( 'login_redirect', $redirect !== '' ? $redirect : admin_url(), $redirect, $user );
            self::json( [ 'ok' => true, 'redirect' => wp_validate_redirect( $to, admin_url() ) ] );
        }

        self::note_failure( $uid );
        $c = (array) get_user_meta( $uid, self::M_CHAL, true );
        $c['tries'] = (int) ( $c['tries'] ?? 0 ) + 1;
        update_user_meta( $uid, self::M_CHAL, $c );
        do_action( 'wp_login_failed', $user->user_login, new WP_Error( 'seal_2fa_failed', 'Security key not accepted.' ) );
        if ( $c['tries'] >= self::MAX_TRIES ) { self::json( [ 'ok' => false, 'redirect' => $expired ] ); }
        self::json( [ 'ok' => false, 'error' => 'That key was not accepted. Use a key registered to your account, or enter a code.' ] );
    }

    // ── My Profile: adding a key (admin-ajax, JSON) ───────────────────────────

    /** Step 1 of adding a key: re-check the password, return browser options. */
    public static function ajax_key_options(): void {
        check_ajax_referer( 'seal_2fa_keys', 'nonce' );
        $user = wp_get_current_user();
        if ( ! $user->exists() || ! class_exists( 'SEAL_WebAuthn' ) || ! SEAL_WebAuthn::available() ) { self::json( [ 'ok' => false, 'error' => 'Security keys are not available.' ], 400 ); }
        if ( self::throttled( $user->ID ) ) { self::json( [ 'ok' => false, 'error' => 'Too many failed attempts. Please wait 15 minutes and try again.' ] ); }
        if ( ! self::password_ok( $user, (string) wp_unslash( $_POST['password'] ?? '' ) ) ) { self::json( [ 'ok' => false, 'error' => 'Your current password is not correct.' ] ); }
        $kind = sanitize_key( wp_unslash( $_POST['kind'] ?? 'key' ) ) === 'passkey' ? 'passkey' : 'key';
        self::json( [ 'ok' => true, 'options' => SEAL_WebAuthn::registration_options( $user, $kind ) ] );
    }

    /** Step 2: verify the key's response and save it; first method also gets backup codes (shown once on reload). */
    public static function ajax_key_add(): void {
        check_ajax_referer( 'seal_2fa_keys', 'nonce' );
        $user = wp_get_current_user();
        if ( ! $user->exists() || ! class_exists( 'SEAL_WebAuthn' ) ) { self::json( [ 'ok' => false, 'error' => 'Security keys are not available.' ], 400 ); }
        $b    = static fn( string $k ): string => (string) base64_decode( (string) wp_unslash( $_POST[ $k ] ?? '' ), true );
        $kind = sanitize_key( wp_unslash( $_POST['kind'] ?? 'key' ) ) === 'passkey' ? 'passkey' : 'key';
        $name = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
        try {
            $added = SEAL_WebAuthn::finish_registration( $user, $name, $kind, $b( 'clientDataJSON' ), $b( 'attestationObject' ) );
        } catch ( RuntimeException $e ) {
            self::json( [ 'ok' => false, 'error' => $e->getMessage() ] );
        }
        $codes = self::codes_if_needed( $user->ID );
        if ( $codes ) { set_transient( 'seal_2fa_codes_' . $user->ID, $codes, 10 * MINUTE_IN_SECONDS ); }
        self::security_changed( $user, ( $added['kind'] === 'passkey' ? 'A passkey' : 'A security key' ) . ' named "' . $added['name'] . '" was added to your account.' );
        self::json( [ 'ok' => true, 'redirect' => add_query_arg( [ 'seal_2fa' => 'keyadded', 'k' => time() ], home_url( '/illuser' ) ) . '#two-factor' ] );
    }

    /**
     * Handles the posted code form: checks the challenge and throttle, verifies the code, then logs the user in
     * properly (or shows the form again with an error).
     */
    public static function handle_challenge(): void {
        if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' ) {
            wp_safe_redirect( wp_login_url() );
            exit;
        }

        $uid  = isset( $_POST['seal_2fa_uid'] ) ? absint( $_POST['seal_2fa_uid'] ) : 0;
        $key  = isset( $_POST['seal_2fa_key'] ) ? sanitize_text_field( wp_unslash( $_POST['seal_2fa_key'] ) ) : '';
        $user = $uid ? get_userdata( $uid ) : false;

        if ( ! $user || ! self::challenge_ok( $uid, $key ) ) {
            wp_safe_redirect( add_query_arg( 'seal_2fa', 'expired', wp_login_url() ) );
            exit;
        }

        $code     = isset( $_POST['seal_2fa_code'] ) ? sanitize_text_field( wp_unslash( $_POST['seal_2fa_code'] ) ) : '';
        $redirect = isset( $_POST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) ) : '';
        $remember = ! empty( $_POST['rememberme'] );

        if ( self::throttled( $uid ) ) {
            self::render_challenge(
                $user, $key, $redirect, $remember,
                new WP_Error( 'seal_2fa_throttled', '<strong>' . esc_html__( 'Error:', 'seal-ill' ) . '</strong> ' . esc_html__( 'Too many incorrect codes. Please wait 15 minutes and try again.', 'seal-ill' ) )
            );
            exit;
        }

        if ( self::verify_code( $user, $code ) ) {
            delete_user_meta( $uid, self::M_CHAL );
            delete_user_meta( $uid, self::M_FAILS );
            wp_set_auth_cookie( $uid, $remember );
            $to = apply_filters( 'login_redirect', $redirect !== '' ? $redirect : admin_url(), $redirect, $user );
            wp_safe_redirect( $to );
            exit;
        }

        self::note_failure( $uid );
        $c = (array) get_user_meta( $uid, self::M_CHAL, true );
        $c['tries'] = (int) ( $c['tries'] ?? 0 ) + 1;
        update_user_meta( $uid, self::M_CHAL, $c );

        do_action( 'wp_login_failed', $user->user_login, new WP_Error( 'seal_2fa_failed', 'Invalid two-factor code.' ) );

        if ( $c['tries'] >= self::MAX_TRIES ) {
            wp_safe_redirect( add_query_arg( 'seal_2fa', 'expired', wp_login_url() ) );
            exit;
        }

        self::render_challenge(
            $user, $key, $redirect, $remember,
            new WP_Error( 'seal_2fa_failed', '<strong>' . esc_html__( 'Error:', 'seal-ill' ) . '</strong> ' . esc_html__( 'That code is not valid. Please try again.', 'seal-ill' ) )
        );
        exit;
    }

    /**
     * Adds the "session expired or too many wrong codes" message to the login screen after a failed challenge.
     */
    public static function login_notices( $errors ) {
        if ( isset( $_GET['seal_2fa'] ) && sanitize_key( wp_unslash( $_GET['seal_2fa'] ) ) === 'expired' && $errors instanceof WP_Error ) {
            $errors->add( 'seal_2fa_expired', esc_html__( 'Your sign-in session expired or had too many wrong codes. Please sign in again.', 'seal-ill' ) );
        }
        return $errors;
    }

    // ── Enforcement for required roles ────────────────────────────────────────

    /**
     * Sends users whose role requires two-factor, and who haven't set it up yet, to My Profile until they do.
     * Only ILL pages and wp-admin are gated; public pages and My Profile itself stay reachable. admin-post and
     * admin-ajax actions (exports, imports…) are refused, except the calls that add a security key. Also called by
     * SEAL_Router before any ILL route, so stand-alone pages (pull slips) are covered too.
     */
    public static function gate(): void {
        if ( ! is_user_logged_in() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) { return; }

        $user = wp_get_current_user();
        if ( ! self::needs_setup( $user ) ) { return; }

        global $pagenow;
        if ( wp_doing_ajax() || $pagenow === 'admin-post.php' ) {
            $action = sanitize_key( wp_unslash( $_REQUEST['action'] ?? '' ) );
            if ( wp_doing_ajax() && in_array( $action, self::SETUP_AJAX, true ) ) { return; }
            wp_die( esc_html__( 'Set up two-factor authentication on My Profile first.', 'seal-ill' ), '', [ 'response' => 403 ] );
        }

        // Only ILL pages and wp-admin are gated; public pages stay browsable.
        $route = (string) get_query_var( 'seal_page' );
        if ( $route === 'illuser' || ( $route === '' && ! is_admin() ) ) { return; }

        wp_safe_redirect( add_query_arg( 'seal_2fa', 'setup', home_url( '/illuser' ) ) );
        exit;
    }

    // ── Enrolment UI (used by /illuser) ───────────────────────────────────────

    /** Handle a POST from the panel. @return array{0:string,1:string,2:string[]} notice, type, backup codes */
    public static function handle_post( WP_User $user ): array {
        if ( ! isset( $_POST['seal_2fa_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['seal_2fa_nonce'] ) ), 'seal_2fa_manage' ) ) {
            wp_die( 'Security check failed.', 'Invalid Request', [ 'response' => 403 ] );
        }

        $do   = isset( $_POST['seal_2fa_action'] ) ? sanitize_key( wp_unslash( $_POST['seal_2fa_action'] ) ) : '';
        $code = isset( $_POST['seal_2fa_code'] ) ? sanitize_text_field( wp_unslash( $_POST['seal_2fa_code'] ) ) : '';
        $pass = isset( $_POST['seal_2fa_password'] ) ? (string) wp_unslash( $_POST['seal_2fa_password'] ) : '';
        $any  = isset( $_POST['seal_2fa_proof'] ) ? (string) wp_unslash( $_POST['seal_2fa_proof'] ) : '';   // "your password or a current code" 
        $uid  = $user->ID;

        if ( self::throttled( $uid ) && $do !== '' ) {
            return [ 'Too many failed attempts. Please wait 15 minutes and try again.', 'error', [] ];
        }

        // Add the authenticator app (on its own, or next to security keys). The current password comes first, as for a
        // key: a code from the new app only proves who is holding this session, not whose account it is.
        if ( $do === 'enable' && ! self::app_on( $uid ) ) {
            if ( ! self::password_ok( $user, $pass ) ) {
                return [ 'Your current password is not correct.', 'error', [] ];
            }
            $pending = self::secret( $uid, self::M_PENDING );
            $step    = null;
            if ( $pending !== '' && SEAL_TOTP::verify( $pending, $code, 0, $step ) ) {
                update_user_meta( $uid, self::M_SECRET, SEAL_DB::encrypt_pass( $pending ) );
                update_user_meta( $uid, self::M_LAST, (int) $step );
                update_user_meta( $uid, self::M_ON, '1' );
                delete_user_meta( $uid, self::M_PENDING );
                $codes = self::codes_if_needed( $uid );
                self::security_changed( $user, 'An authenticator app was added to your account.' );
                return [ 'Your authenticator app is set up.' . ( $codes ? ' Save your backup codes below.' : '' ), 'success', $codes ];
            }
            return [ 'That code was not valid. Check the time on your phone and try again.', 'error', [] ];
        }

        // Remove one security key: current password required.
        if ( $do === 'remove_key' && self::keys_on( $uid ) ) {
            $id = sanitize_key( wp_unslash( $_POST['seal_2fa_key_id'] ?? '' ) );
            if ( ! self::password_ok( $user, $pass ) ) { return [ 'Your current password is not correct.', 'error', [] ]; }
            if ( self::role_required( $user ) && ! self::app_on( $uid ) && count( SEAL_WebAuthn::keys( $uid ) ) === 1 ) {
                return [ 'Your role requires two-factor authentication. Add another key or an authenticator app before removing your last key.', 'error', [] ];
            }
            $name = SEAL_WebAuthn::remove( $uid, $id );
            if ( $name === '' ) { return [ 'That key was not found.', 'error', [] ]; }
            if ( ! self::is_enrolled( $uid ) ) { delete_user_meta( $uid, self::M_BACKUP ); }   // last method gone: codes go too
            self::security_changed( $user, 'The security key "' . $name . '" was removed from your account.' );
            return [ 'Removed "' . $name . '".', 'success', [] ];
        }

        // Changes that need proof: a current code, or (for someone with keys) the current password.
        if ( self::is_enrolled( $uid ) && in_array( $do, [ 'disable', 'regen', 'remove_app' ], true ) ) {
            if ( $any !== '' ) {
                // Password first (so a backup code is only used up when it is one), then a code.
                $proof = wp_check_password( $any, $user->user_pass, $user->ID ) || self::verify_code( $user, $any );
                if ( ! $proof ) {
                    self::note_failure( $uid );
                    do_action( 'wp_login_failed', $user->user_login, new WP_Error( 'seal_2fa_proof', 'Wrong password or code while changing two-factor settings.' ) );
                }
            } else {
                $proof = $code !== '' ? self::verify_code( $user, $code ) : ( self::keys_on( $uid ) && self::password_ok( $user, $pass ) );
                if ( ! $proof && $code !== '' ) { self::note_failure( $uid ); }
            }
            if ( ! $proof ) {
                return [ 'That password or code was not correct.', 'error', [] ];
            }
            if ( $do === 'disable' ) {
                if ( self::role_required( $user ) ) { return [ 'Your role requires two-factor authentication, so it cannot be turned off.', 'error', [] ]; }
                self::reset_user( $uid );
                self::security_changed( $user, 'Two-factor authentication was turned off (keys, authenticator app and backup codes removed).' );
                return [ 'Two-factor authentication has been turned off.', 'success', [] ];
            }
            if ( $do === 'remove_app' ) {
                if ( ! self::keys_on( $uid ) ) { return [ 'Add a security key first, or use "Turn off two-factor".', 'error', [] ]; }
                foreach ( [ self::M_SECRET, self::M_PENDING, self::M_ON, self::M_LAST ] as $m ) { delete_user_meta( $uid, $m ); }
                self::security_changed( $user, 'The authenticator app was removed from your account.' );
                return [ 'Authenticator app removed. Your security keys still work.', 'success', [] ];
            }
            self::security_changed( $user, 'New backup codes were generated; the old ones no longer work.' );
            return [ 'New backup codes generated. The old ones no longer work.', 'success', self::make_backup_codes( $uid ) ];
        }

        return [ '', 'info', [] ];
    }

    /**
     * The two-factor panel on My Profile: QR code and set-up form, or status, new backup codes and the turn-off
     * button (hidden when the user's role requires it).
     */
    /** One "Your password or a current code" confirmation form for a panel action. */
    private static function proof_form( string $post_url, string $action, string $button, string $btn_class, string $label = 'Your password or a current code' ): void {
        $id = 'seal_2fa_proof_' . $action; ?>
        <form method="post" action="<?php echo esc_url( $post_url ); ?>#two-factor" class="seal-2fa-confirm-form">
            <?php wp_nonce_field( 'seal_2fa_manage', 'seal_2fa_nonce' ); ?>
            <label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label>
            <div class="seal-2fa-inline">
                <input type="password" id="<?php echo esc_attr( $id ); ?>" name="seal_2fa_proof" autocomplete="current-password" required>
                <button type="submit" name="seal_2fa_action" value="<?php echo esc_attr( $action ); ?>" class="<?php echo esc_attr( $btn_class ); ?>"><?php echo esc_html( $button ); ?></button>
            </div>
        </form>
        <?php
    }

    /**
     * The two-factor panel on My Profile, one card per method: Security keys and passkeys (a line per key with its
     * own Remove, and an Add button), Authenticator app, Backup codes, and Turn off. Confirmations open in place
     * (<details>) and ask for one thing: the password or a current code.
     */
    public static function render_panel( WP_User $user, string $post_url, array $backup_codes = [] ): void {
        $uid      = $user->ID;
        $enrolled = self::is_enrolled( $uid );
        $required = self::role_required( $user );
        $has_app  = self::app_on( $uid );
        $wa_ok    = class_exists( 'SEAL_WebAuthn' ) && SEAL_WebAuthn::available();
        $keys     = $wa_ok ? SEAL_WebAuthn::keys( $uid ) : [];
        $left     = self::backup_left( $uid );
        $flag     = isset( $_GET['seal_2fa'] ) ? sanitize_key( wp_unslash( $_GET['seal_2fa'] ) ) : '';
        $did      = isset( $_POST['seal_2fa_action'] ) ? sanitize_key( wp_unslash( $_POST['seal_2fa_action'] ) ) : '';
        // Backup codes made when the first key was added (that request was JSON): shown once, here.
        if ( ! $backup_codes ) {
            $t = get_transient( 'seal_2fa_codes_' . $uid );
            if ( is_array( $t ) && $t ) { $backup_codes = $t; delete_transient( 'seal_2fa_codes_' . $uid ); }
        }
        // Collapsed by default. Open it when the user must act or has just done something.
        $open = ! empty( $backup_codes ) || $did !== '' || in_array( $flag, [ 'setup', 'keyadded' ], true ) || ( $required && ! $enrolled );
        $date = static fn( $ts ) => $ts ? esc_html( wp_date( 'M j, Y', (int) $ts ) ) : 'never';
        $chip = static fn( bool $on, string $text ) => '<span class="seal-2fa-status ' . ( $on ? 'is-on' : 'is-off' ) . '">' . esc_html( $text ) . '</span>';
        ?>
        <div class="seal-2fa-panel section-card" id="two-factor">
        <div class="seal-2fa-head">
            <h4 id="seal-2fa-heading">Two-Factor Authentication <?php echo $chip( $enrolled, $enrolled ? 'Enabled' : 'Not enabled' ); ?></h4>
            <label class="seal-switch" for="seal-2fa-toggle">
                <input type="checkbox" id="seal-2fa-toggle" role="switch" aria-controls="seal-2fa-body" <?php checked( $open ); ?>>
                <span class="seal-switch-track" aria-hidden="true"></span>
                <span class="seal-switch-label">Show settings</span>
            </label>
        </div>

        <div id="seal-2fa-body" class="seal-2fa-body" role="region" aria-labelledby="seal-2fa-heading">
        <?php if ( $flag === 'setup' && ! $enrolled ) : ?>
            <div class="seal-alert seal-alert-warning" role="alert">Your role requires two-factor authentication. Add a security key or set up an authenticator app below to continue using the site.</div>
        <?php endif; ?>
        <?php if ( $flag === 'keyadded' ) : ?>
            <div class="seal-alert seal-alert-success" role="status">Your key was added. Next time you sign in, touch it after entering your password.</div>
        <?php endif; ?>
        <?php if ( $backup_codes ) : ?>
            <div class="seal-alert seal-alert-info" role="status">
                <strong>Your backup codes</strong>: each works once if you lose your key or phone. Save them somewhere safe now; they won't be shown again.
                <ul class="seal-2fa-codes"><?php foreach ( $backup_codes as $c ) : ?><li><?php echo esc_html( $c ); ?></li><?php endforeach; ?></ul>
            </div>
        <?php endif; ?>

        <p class="helper">After your password you'll be asked for one of the methods below. You can use any mix; two-factor is on as soon as you add the first.</p>

        <?php if ( $wa_ok ) : $nk = count( $keys ); ?>
        <section class="seal-2fa-card" aria-labelledby="seal-2fa-keys-h">
            <div class="seal-2fa-card-head">
                <h5 id="seal-2fa-keys-h">Security keys and passkeys</h5>
                <?php echo $chip( $nk > 0, $nk ? $nk . ' added' : 'None' ); ?>
            </div>
            <p class="helper">A YubiKey you touch, or a passkey on this computer or phone (Windows Hello, Touch ID, Android). They work only on <?php echo esc_html( SEAL_WebAuthn::rp_id() ); ?>.</p>
            <?php if ( $keys ) : ?>
            <ul class="seal-2fa-keys">
                <?php foreach ( $keys as $k ) : $kid = (string) $k['id']; $kname = (string) $k['name']; ?>
                <li class="seal-2fa-key-row">
                    <div class="seal-2fa-key-info">
                        <strong><?php echo esc_html( $kname ); ?></strong>
                        <span class="seal-2fa-type"><?php echo ( $k['kind'] ?? '' ) === 'passkey' ? 'Passkey' : 'Security key'; ?></span>
                        <span class="seal-2fa-meta">Added <?php echo $date( $k['created'] ?? 0 ); ?> &middot; last used <?php echo $date( $k['used'] ?? 0 ); ?></span>
                    </div>
                    <details class="seal-2fa-confirm">
                        <summary class="btn-secondary">Remove<span class="seal-sr-only"> <?php echo esc_html( $kname ); ?></span></summary>
                        <form method="post" action="<?php echo esc_url( $post_url ); ?>#two-factor" class="seal-2fa-confirm-form">
                            <?php wp_nonce_field( 'seal_2fa_manage', 'seal_2fa_nonce' ); ?>
                            <input type="hidden" name="seal_2fa_key_id" value="<?php echo esc_attr( $kid ); ?>">
                            <label for="seal_rm_<?php echo esc_attr( $kid ); ?>">Your password, to remove &ldquo;<?php echo esc_html( $kname ); ?>&rdquo;</label>
                            <div class="seal-2fa-inline">
                                <input type="password" id="seal_rm_<?php echo esc_attr( $kid ); ?>" name="seal_2fa_password" autocomplete="current-password" required>
                                <button type="submit" name="seal_2fa_action" value="remove_key" class="btn-danger">Remove key</button>
                            </div>
                        </form>
                    </details>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>

            <details class="seal-2fa-add" <?php echo ( ! $enrolled && $flag === 'setup' ) ? 'open' : ''; ?>>
                <summary class="btn-primary"><?php echo $keys ? 'Add another key' : 'Add a security key or passkey'; ?></summary>
                <form class="seal-2fa-confirm-form seal-wa-add" data-webauthn-register
                      data-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'seal_2fa_keys' ) ); ?>" onsubmit="return false;"><?php /* never a normal submit: the password would land in the address */ ?>
                    <fieldset class="seal-2fa-choice">
                        <legend>What are you adding?</legend>
                        <label><input type="radio" name="kind" value="key" checked> A YubiKey or other security key</label>
                        <label><input type="radio" name="kind" value="passkey"> A passkey on this computer or phone</label>
                    </fieldset>
                    <label for="seal_wa_name">Name it <span class="seal-2fa-meta">(so you can tell your keys apart)</span></label>
                    <input type="text" id="seal_wa_name" name="name" maxlength="80" placeholder="e.g. Blue YubiKey, Work laptop">
                    <label for="seal_wa_pw">Your password</label>
                    <div class="seal-2fa-inline">
                        <input type="password" id="seal_wa_pw" name="password" autocomplete="current-password" required>
                        <button type="submit" class="btn-primary" data-webauthn-add>Continue and touch your key</button>
                    </div>
                    <p class="seal-2fa-live" data-webauthn-status role="status" aria-live="polite"></p>
                    <p class="seal-2fa-meta">If a password manager (Bitwarden, 1Password) offers to save a passkey, choose its &ldquo;use a hardware key&rdquo; option to use your YubiKey instead.</p>
                </form>
            </details>
            <script src="<?php echo esc_url( SEAL_ILL_PLUGIN_URL . 'assets/js/seal-webauthn.js?ver=' . SEAL_ILL_VERSION ); ?>" defer></script>
        </section>
        <?php endif; ?>

        <section class="seal-2fa-card" aria-labelledby="seal-2fa-app-h">
            <div class="seal-2fa-card-head">
                <h5 id="seal-2fa-app-h">Authenticator app</h5>
                <?php echo $chip( $has_app, $has_app ? 'On' : 'Off' ); ?>
            </div>
            <?php if ( $has_app ) : ?>
                <p class="helper">6-digit codes from your authenticator app are accepted at sign-in.</p>
                <?php if ( $keys ) : ?>
                <details class="seal-2fa-confirm">
                    <summary class="btn-secondary">Remove authenticator app</summary>
                    <?php self::proof_form( $post_url, 'remove_app', 'Remove app', 'btn-danger' ); ?>
                </details>
                <?php endif; ?>
            <?php else :
                $pending = self::secret( $uid, self::M_PENDING );
                if ( $pending === '' ) {
                    $pending = SEAL_TOTP::generate_secret();
                    update_user_meta( $uid, self::M_PENDING, SEAL_DB::encrypt_pass( $pending ) );
                }
                $issuer = seal_config( 'tfa_issuer' ) ?: seal_config( 'system_name' );
                $uri    = SEAL_TOTP::uri( $pending, $user->user_email ?: $user->user_login, $issuer );
                wp_enqueue_script( 'seal-qrcode', SEAL_ILL_PLUGIN_URL . 'assets/js/qrcode.js', [], SEAL_ILL_VERSION, true );
                wp_enqueue_script( 'seal-2fa', SEAL_ILL_PLUGIN_URL . 'assets/js/seal-2fa.js', [ 'seal-qrcode' ], SEAL_ILL_VERSION, true );
                ?>
                <p class="helper">Codes from an app such as Google Authenticator, Microsoft Authenticator, Authy or 1Password.</p>
                <details class="seal-2fa-add" <?php echo ( $did === 'enable' || ( ! $enrolled && ! $wa_ok ) ) ? 'open' : ''; ?>>
                    <summary class="btn-secondary">Set up an authenticator app</summary>
                    <div class="seal-2fa-setup">
                        <div id="seal-2fa-qr" class="seal-2fa-qr" data-uri="<?php echo esc_attr( $uri ); ?>"></div>
                        <div class="seal-2fa-steps">
                            <p><strong>1.</strong> Scan the QR code with the app, or type this setup key into it:<br>
                                <code class="seal-2fa-key"><?php echo esc_html( trim( chunk_split( $pending, 4, ' ' ) ) ); ?></code></p>
                            <form method="post" action="<?php echo esc_url( $post_url ); ?>#two-factor" class="seal-2fa-confirm-form">
                                <?php wp_nonce_field( 'seal_2fa_manage', 'seal_2fa_nonce' ); ?>
                                <label for="seal_2fa_code_e"><strong>2.</strong> Enter the 6-digit code the app shows</label>
                                <div class="seal-2fa-inline">
                                    <input type="text" id="seal_2fa_code_e" name="seal_2fa_code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,7}" class="seal-2fa-code" required>
                                </div>
                                <label for="seal_2fa_password_e"><strong>3.</strong> Your current password</label>
                                <div class="seal-2fa-inline">
                                    <input type="password" id="seal_2fa_password_e" name="seal_2fa_password" autocomplete="current-password" required>
                                    <button type="submit" name="seal_2fa_action" value="enable" class="btn-primary">Verify and turn on</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </details>
            <?php endif; ?>
        </section>

        <?php if ( $enrolled ) : ?>
        <section class="seal-2fa-card" aria-labelledby="seal-2fa-codes-h">
            <div class="seal-2fa-card-head">
                <h5 id="seal-2fa-codes-h">Backup codes</h5>
                <?php echo $chip( $left > 0, $left . ' left' ); ?>
            </div>
            <p class="helper">One-time codes for when you don't have your key or phone. Making new ones cancels the old ones.</p>
            <details class="seal-2fa-confirm">
                <summary class="btn-secondary">Generate new backup codes</summary>
                <?php self::proof_form( $post_url, 'regen', 'Generate codes', 'btn-primary' ); ?>
            </details>
        </section>

            <?php if ( ! $required ) : ?>
        <section class="seal-2fa-card seal-2fa-danger" aria-labelledby="seal-2fa-off-h">
            <div class="seal-2fa-card-head"><h5 id="seal-2fa-off-h">Turn off two-factor</h5></div>
            <p class="helper">Removes all your keys, the authenticator app and your backup codes. Your account will be protected by your password only.</p>
            <details class="seal-2fa-confirm">
                <summary class="btn-danger">Turn off two-factor</summary>
                <?php self::proof_form( $post_url, 'disable', 'Turn off', 'btn-danger' ); ?>
            </details>
        </section>
            <?php else : ?>
        <p class="seal-2fa-meta">Your role requires two-factor authentication, so it can't be turned off.</p>
            <?php endif; ?>
        <?php endif; ?>
        </div>
        <script>
        (function () {
            var t = document.getElementById('seal-2fa-toggle'), b = document.getElementById('seal-2fa-body');
            if (!t || !b) { return; }
            if (window.location.hash === '#two-factor') { t.checked = true; }
            /** Shows the settings only while the switch is on. */
            function sync() { b.hidden = !t.checked; }
            t.addEventListener('change', sync);
            sync();
        })();
        </script>
        </div>
        <?php
    }

    // ── wp-admin profile: status + admin reset ────────────────────────────────

    /** Two-factor status on the WordPress user profile screen, with a reset checkbox for administrators. */
    public static function profile_section( $profile_user ): void {
        if ( ! ( $profile_user instanceof WP_User ) ) { return; }
        $on   = self::is_enrolled( $profile_user->ID );
        $self = get_current_user_id() === $profile_user->ID;
        ?>
        <h2>Two-Factor Authentication</h2>
        <table class="form-table" role="presentation"><tr>
            <th scope="row">Status</th>
            <td>
                <?php
                $methods = [];
                if ( self::app_on( $profile_user->ID ) ) { $methods[] = 'authenticator app'; }
                if ( self::keys_on( $profile_user->ID ) ) { $n = count( SEAL_WebAuthn::keys( $profile_user->ID ) ); $methods[] = $n . ' security key' . ( $n === 1 ? '' : 's' ) . '/passkey' . ( $n === 1 ? '' : 's' ); }
                echo $on ? '<strong>On</strong> (' . esc_html( implode( ', ', $methods ) ) . ')' : 'Off';
                ?>
                <?php if ( $self ) : ?>
                    — <a href="<?php echo esc_url( home_url( '/illuser#two-factor' ) ); ?>">Manage on your ILL profile page</a>
                <?php elseif ( $on && self::can_reset( $profile_user->ID ) ) : ?>
                    <p><label><input type="checkbox" name="seal_2fa_admin_reset" value="1"> Reset this user's two-factor authentication: removes their security keys, authenticator app and backup codes (they will need to set it up again)</label></p>
                <?php endif; ?>
            </td>
        </tr></table>
        <?php
    }

    /**
     * Who may reset someone else's two-factor (for a person locked out with no key, phone or backup codes):
     * Administrators, and ILL Administrators for accounts they may edit. SEAL_Roles::guard_protected_users() already
     * denies edit_user on Administrators and other ILL Administrators to anyone but a full Administrator, and nobody
     * resets their own here (they use My Profile).
     */
    public static function can_reset( int $user_id ): bool {
        return $user_id !== get_current_user_id()
            && ( current_user_can( 'manage_options' ) || current_user_can( 'seal_admin' ) )
            && current_user_can( 'edit_user', $user_id );
    }

    /** Saves the reset checkbox from the user profile screen (Administrators, and ILL Administrators per can_reset()). */
    public static function profile_admin_reset( $user_id ): void {
        if ( ! self::can_reset( (int) $user_id ) ) { return; }
        if ( isset( $_POST['seal_2fa_admin_reset'] ) && $_POST['seal_2fa_admin_reset'] === '1' ) {
            self::reset_user( (int) $user_id );
            $u = get_userdata( (int) $user_id );
            if ( $u ) { self::security_changed( $u, 'An administrator reset your two-factor authentication (security keys, authenticator app and backup codes removed). Sign in with your password and set it up again on My Profile.' ); }
        }
    }
}
