<?php
/**
 * SEAL_Module_LoginProtection
 *
 *  - Login lockout: N failed logins from one IP inside a time window locks
 *    that IP out of logging in (temporary or permanent).
 *  - 404 detection: N "page not found" hits from one IP inside a window locks
 *    that IP out of the whole site (scanner / bot behaviour).
 *  - IP allow list (never locked) and block list (always blocked, CIDR ok).
 *  - Optional: disable XML-RPC.
 *
 * Storage is WordPress-native: counters/locks in transients, permanent bans
 * and the activity log in non-autoloaded options.
 *
 * Libraries often share one public IP, so an allow list is provided and the
 * defaults are deliberately generous. Emergency off switch: define
 * ILL_DISABLE_SECURITY as true in wp-config.php (the older SEAL_DISABLE_SECURITY also works).
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class SEAL_Module_LoginProtection {

    const OPT_BANS = 'seal_lp_bans';
    const OPT_LOG  = 'seal_lp_log';
    const LOG_MAX  = 200;

    /** Error codes that count as a failed login attempt. */
    const COUNTED_CODES = [ 'incorrect_password', 'invalid_username', 'invalid_email', 'authentication_failed', 'seal_2fa_failed' ];

    /**
     * Hooks the site-wide and login-page lockout checks, failed-login and 404 counting, and the XML-RPC switch.
     */
    public static function init(): void {
        add_action( 'init',             [ __CLASS__, 'enforce_sitewide' ], 1 );
        add_action( 'login_init',       [ __CLASS__, 'enforce_login_page' ], 1 );
        add_filter( 'authenticate',     [ __CLASS__, 'enforce_authenticate' ], 101, 1 );
        add_action( 'wp_login_failed',  [ __CLASS__, 'record_failed_login' ], 10, 2 );
        add_action( 'template_redirect', [ __CLASS__, 'track_404' ], 999 );

        if ( seal_config( 'lp_disable_xmlrpc' ) === '1' ) {
            add_filter( 'xmlrpc_enabled', '__return_false' );
        }
    }

    // ── Client IP ─────────────────────────────────────────────────────────────

    /**
     * The visitor's IP address. With a Real-IP header set, takes the right-most public address in it (the one
     * our own proxy saw); otherwise REMOTE_ADDR. Only set the header when the proxy is the only way to reach
     * the server, or visitors could forge it.
     */
    public static function client_ip(): string {
        $header = trim( seal_config( 'lp_ip_header' ) );
        $ip     = '';

        if ( $header !== '' && preg_match( '/^HTTP_[A-Z0-9_]+$/', $header ) && ! empty( $_SERVER[ $header ] ) ) {
            $parts = array_map( 'trim', explode( ',', (string) $_SERVER[ $header ] ) );
            // Rightmost public address = the one our own proxy actually saw.
            foreach ( array_reverse( $parts ) as $p ) {
                if ( filter_var( $p, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
                    $ip = $p;
                    break;
                }
            }
            if ( $ip === '' ) {
                foreach ( $parts as $p ) {
                    if ( filter_var( $p, FILTER_VALIDATE_IP ) ) { $ip = $p; break; }
                }
            }
        }

        if ( $ip === '' ) { $ip = (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ); }
        return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '0.0.0.0';
    }

    // ── IP list matching (IPv4 + IPv6, exact or CIDR) ─────────────────────────

    /** True when $ip equals an address or falls inside a CIDR range (IPv4 or IPv6). */
    public static function ip_matches( string $ip, string $entry ): bool {
        $entry = trim( $entry );
        if ( $entry === '' ) { return false; }
        if ( strpos( $entry, '/' ) === false ) {
            return inet_pton( $ip ) !== false && inet_pton( $ip ) === inet_pton( $entry );
        }
        [ $net, $bits ] = explode( '/', $entry, 2 );
        $ip_bin  = inet_pton( $ip );
        $net_bin = inet_pton( $net );
        if ( $ip_bin === false || $net_bin === false || strlen( $ip_bin ) !== strlen( $net_bin ) || ! ctype_digit( $bits ) ) {
            return false;
        }
        $bits = (int) $bits;
        if ( $bits > strlen( $net_bin ) * 8 ) { return false; }
        $bytes = intdiv( $bits, 8 );
        if ( $bytes && substr( $ip_bin, 0, $bytes ) !== substr( $net_bin, 0, $bytes ) ) { return false; }
        $rem = $bits % 8;
        if ( $rem === 0 ) { return true; }
        $mask = ( 0xFF << ( 8 - $rem ) ) & 0xFF;
        return ( ord( $ip_bin[ $bytes ] ) & $mask ) === ( ord( $net_bin[ $bytes ] ) & $mask );
    }

    /** True when $ip matches any line of an allow or block list. */
    public static function in_list( string $ip, string $list ): bool {
        foreach ( preg_split( '/\R/', $list ) as $line ) {
            if ( self::ip_matches( $ip, $line ) ) { return true; }
        }
        return false;
    }

    // ── Lock state ────────────────────────────────────────────────────────────

    /** The transient name for one address's counter or lockout (the IP is hashed). */
    private static function key( string $prefix, string $ip ): string {
        return $prefix . md5( $ip );
    }

    /** @return array{type:string,message:string}|null */
    public static function lock_for( string $ip ): ?array {
        if ( self::in_list( $ip, seal_config( 'lp_allowlist' ) ) ) { return null; }

        if ( self::in_list( $ip, seal_config( 'lp_blocklist' ) ) ) {
            return [ 'type' => 'manual', 'message' => 'Access from your address has been blocked by the site administrator.' ];
        }

        $bans = (array) get_option( self::OPT_BANS, [] );
        if ( isset( $bans[ $ip ] ) ) {
            $t = (string) ( $bans[ $ip ]['type'] ?? 'login' );
            return [ 'type' => $t, 'message' => self::message_for( $t ) ];
        }

        $lock = get_transient( self::key( 'seal_lpl_', $ip ) );
        if ( is_array( $lock ) && ! empty( $lock['type'] ) ) {
            return [ 'type' => (string) $lock['type'], 'message' => self::message_for( (string) $lock['type'] ) ];
        }
        return null;
    }

    /** The lockout message for a login (default) or 404 lockout. */
    private static function message_for( string $type ): string {
        return $type === '404'
            ? seal_config( 'lp_404_message' )
            : seal_config( 'lp_message' );
    }

    /** The configured lockout length in seconds for the login or 404 lockout. */
    private static function duration_seconds( string $prefix ): int {
        $n    = max( 1, (int) seal_config( $prefix . '_duration' ) );
        $unit = seal_config( $prefix . '_duration_unit' );
        $mult = [ 'minutes' => 60, 'hours' => 3600, 'days' => 86400 ];
        return $n * ( $mult[ $unit ] ?? 60 );
    }

    /**
     * Locks out an address: a permanent ban stored in an option, or a temporary lockout stored as a transient.
     * Clears its counters and logs the lockout.
     */
    private static function lock( string $ip, string $type ): void {
        $prefix    = $type === '404' ? 'lp_404' : 'lp';
        $permanent = seal_config( $prefix . '_permanent' ) === '1';

        if ( $permanent ) {
            $bans        = (array) get_option( self::OPT_BANS, [] );
            $bans[ $ip ] = [ 'type' => $type, 'time' => time() ];
            update_option( self::OPT_BANS, $bans, false );
        } else {
            $secs = self::duration_seconds( $prefix );
            set_transient( self::key( 'seal_lpl_', $ip ), [ 'type' => $type, 'until' => time() + $secs ], $secs );
        }
        delete_transient( self::key( 'seal_lpf_', $ip ) );
        delete_transient( self::key( 'seal_lp4_', $ip ) );
        self::log( $ip, $type, $permanent );
    }

    /** Adds a lockout to the Activity log (newest LOG_MAX kept). */
    private static function log( string $ip, string $type, bool $permanent ): void {
        $log   = (array) get_option( self::OPT_LOG, [] );
        $log[] = [ 'time' => time(), 'ip' => $ip, 'type' => $type, 'permanent' => $permanent ];
        if ( count( $log ) > self::LOG_MAX ) { $log = array_slice( $log, -self::LOG_MAX ); }
        update_option( self::OPT_LOG, $log, false );
    }

    /**
     * Lifts a temporary lockout or permanent ban for an address and resets its counters (Security → Activity).
     */
    public static function unlock( string $ip ): void {
        delete_transient( self::key( 'seal_lpl_', $ip ) );
        delete_transient( self::key( 'seal_lpf_', $ip ) );
        delete_transient( self::key( 'seal_lp4_', $ip ) );
        $bans = (array) get_option( self::OPT_BANS, [] );
        if ( isset( $bans[ $ip ] ) ) {
            unset( $bans[ $ip ] );
            update_option( self::OPT_BANS, $bans, false );
        }
    }

    // ── Counting ──────────────────────────────────────────────────────────────

    /** Append a hit, drop hits outside the window, return the hit count. */
    private static function hit( string $transient, int $window ): int {
        $now  = time();
        $hits = get_transient( $transient );
        $hits = is_array( $hits ) ? $hits : [];
        $hits = array_values( array_filter( $hits, static fn( $t ) => $t > $now - $window ) );
        $hits[] = $now;
        set_transient( $transient, $hits, $window );
        return count( $hits );
    }

    /**
     * Counts a failed login for the visitor's address (only real wrong-password / unknown-user failures) and
     * locks it out at the limit.
     */
    public static function record_failed_login( $username = '', $error = null ): void {
        if ( $error instanceof WP_Error ) {
            if ( ! array_intersect( self::COUNTED_CODES, $error->get_error_codes() ) ) { return; }
        }
        $ip = self::client_ip();
        if ( self::in_list( $ip, seal_config( 'lp_allowlist' ) ) ) { return; }

        $limit  = max( 1, (int) seal_config( 'lp_failed_logins' ) );
        $window = max( 30, (int) seal_config( 'lp_timeframe' ) );
        if ( self::hit( self::key( 'seal_lpf_', $ip ), $window ) >= $limit ) {
            self::lock( $ip, 'login' );
        }
    }

    /**
     * Counts a page-not-found hit for the visitor's address (ignoring the listed file types and, unless set,
     * logged-in users) and locks it out at the limit.
     */
    public static function track_404(): void {
        if ( seal_config( 'lp_404_enabled' ) !== '1' || ! is_404() ) { return; }
        if ( is_user_logged_in() && seal_config( 'lp_404_logged_in' ) !== '1' ) { return; }

        $ext    = strtolower( pathinfo( (string) wp_parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ), PATHINFO_EXTENSION ) );
        $ignore = array_filter( array_map( 'trim', explode( ',', strtolower( seal_config( 'lp_404_ignore' ) ) ) ) );
        if ( $ext !== '' && in_array( $ext, $ignore, true ) ) { return; }

        $ip = self::client_ip();
        if ( self::in_list( $ip, seal_config( 'lp_allowlist' ) ) ) { return; }

        $limit  = max( 1, (int) seal_config( 'lp_404_hits' ) );
        $window = max( 30, (int) seal_config( 'lp_404_timeframe' ) );
        if ( self::hit( self::key( 'seal_lp4_', $ip ), $window ) >= $limit ) {
            self::lock( $ip, '404' );
        }
    }

    // ── Enforcement ───────────────────────────────────────────────────────────

    /** Stops the request with the lockout message (HTTP 403). */
    private static function deny( string $message ): void {
        wp_die(
            esc_html( $message ),
            esc_html__( 'Access Blocked', 'seal-ill' ),
            [ 'response' => 403 ]
        );
    }

    /** 404 lockouts, permanent bans of that kind, and manual blocks cover the whole site. */
    public static function enforce_sitewide(): void {
        if ( wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) { return; }
        $lock = self::lock_for( self::client_ip() );
        if ( $lock && in_array( $lock['type'], [ '404', 'manual' ], true ) ) {
            self::deny( $lock['message'] );
        }
    }

    /** Login lockouts stop the login form itself. */
    public static function enforce_login_page(): void {
        $lock = self::lock_for( self::client_ip() );
        if ( $lock ) { self::deny( $lock['message'] ); }
    }

    /** Runs after every other authenticate filter so a correct password cannot bypass a lock. */
    public static function enforce_authenticate( $user ) {
        $lock = self::lock_for( self::client_ip() );
        if ( $lock ) {
            return new WP_Error( 'seal_locked', esc_html( $lock['message'] ) );
        }
        return $user;
    }
}
