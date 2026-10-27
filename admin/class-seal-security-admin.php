<?php
/**
 * SEAL_Security_Admin — ILL → Security. One tabbed screen for:
 *   Login Screen · Login URL · Login Protection · Passwords · Two-Factor ·
 *   File Monitor · Activity
 *
 * Every option on a tab is rendered on that tab (WordPress clears registered
 * options that are missing from a submitted form).
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class SEAL_Security_Admin {

    const PAGE = 'seal-ill-security';

    const TABS = [
        'login-screen' => 'Login Screen',
        'login-url'    => 'Login URL',
        'protection'   => 'Login Protection',
        'passwords'    => 'Passwords',
        '2fa'          => 'Two-Factor',
        'files'        => 'File Monitor',
        'activity'     => 'Activity',
    ];

    // ── Boot ──────────────────────────────────────────────────────────────────

    /** Adds ILL System → Security (Administrators only). */
    public static function register_menu(): void {
        add_submenu_page( 'seal-ill-dashboard', 'Security', '🛡️ Security', 'manage_options', self::PAGE, [ __CLASS__, 'render' ] );
    }

    /**
     * Registers the settings and the admin-post handlers for the file-monitor scan / accept, unlocking an
     * address and forcing password resets.
     */
    public static function init(): void {
        self::register_settings();
        add_action( 'admin_post_seal_fm_scan',   [ __CLASS__, 'do_fm_scan' ] );
        add_action( 'admin_post_seal_fm_accept', [ __CLASS__, 'do_fm_accept' ] );
        add_action( 'admin_post_seal_lp_unlock', [ __CLASS__, 'do_lp_unlock' ] );
        add_action( 'admin_post_seal_pw_force',  [ __CLASS__, 'do_pw_force' ] );
        add_action( 'admin_enqueue_scripts',     [ __CLASS__, 'enqueue' ] );
    }

    /** On the Security screen only: loads the Media Library picker used by the image fields. */
    public static function enqueue( string $hook ): void {
        if ( strpos( $hook, self::PAGE ) === false ) { return; }
        wp_enqueue_media();
        wp_add_inline_script( 'jquery-core', <<<'JS'
jQuery(function ($) {
  $('.seal-media-btn').on('click', function (e) {
    e.preventDefault();
    var target = $($(this).data('target'));
    var frame = wp.media({ title: 'Choose image', multiple: false, library: { type: 'image' } });
    frame.on('select', function () { target.val(frame.state().get('selection').first().toJSON().url); });
    frame.open();
  });
});
JS
        );
    }

    // ── Settings registration ─────────────────────────────────────────────────

    /** The settings group for one tab (each tab saves only its own options). */
    private static function group( string $tab ): string { return 'seal_sec_' . $tab; }
    /** The settings page slug for one tab. */
    private static function slug( string $tab ): string  { return 'seal-sec-' . $tab; }

    /** Registers a tab's options with their sanitize callbacks. */
    private static function reg( string $tab, array $map ): void {
        foreach ( $map as $k => $cb ) {
            register_setting( self::group( $tab ), $k, [ 'sanitize_callback' => $cb ] );
        }
    }

    /** Adds one field to a tab; $type picks the field_<type>() renderer. */
    private static function f( string $tab, string $section, string $name, string $label, string $type, array $args = [] ): void {
        add_settings_field( $name, $label, [ __CLASS__, 'field_' . $type ], self::slug( $tab ), $section, $args + [ 'name' => $name, 'label_for' => $name ] );
    }

    /** Adds a section to a tab, with an optional intro paragraph (basic tags only). */
    private static function section( string $tab, string $id, string $title, string $html = '' ): void {
        add_settings_section( $id, $title, $html === '' ? '__return_false' : static function () use ( $html ) {
            echo '<p>' . wp_kses( $html, [ 'code' => [], 'em' => [], 'strong' => [], 'br' => [] ] ) . '</p>';
        }, self::slug( $tab ) );
    }

    /** Every option, section and field on every Security tab. */
    public static function register_settings(): void {
        $tog = [ 'SEAL_Admin', 'san_toggle' ];
        $txt = 'sanitize_text_field';

        // Login screen
        self::reg( 'login-screen', [
            'seal_module_login_screen' => $tog,
            'seal_ls_logo_url'   => 'esc_url_raw',
            'seal_ls_logo_alt'   => $txt,
            'seal_ls_logo_link'  => 'esc_url_raw',
            'seal_ls_bg_url'     => 'esc_url_raw',
            'seal_ls_message'    => 'wp_kses_post',
            'seal_ls_split'      => $tog,
            'seal_ls_button_color' => static fn( $v ) => sanitize_hex_color( (string) $v ) ?: '#1f3a5f',
        ] );
        self::section( 'login-screen', 'sec_ls_on', 'Branded login screen', 'Styles the login, registration and lost-password pages. Works with or without the custom login URL.' );
        self::f( 'login-screen', 'sec_ls_on', 'seal_module_login_screen', 'Enable', 'toggle' );
        self::f( 'login-screen', 'sec_ls_on', 'seal_ls_logo_url', 'Logo', 'image', [ 'desc' => 'Preferred width about 320px.' ] );
        self::f( 'login-screen', 'sec_ls_on', 'seal_ls_logo_alt', 'Logo alt text', 'text', [ 'desc' => 'Required for accessibility (WCAG 1.1.1). Describe the logo, e.g. <em>SEAL – Southeastern Access to Libraries</em>.' ] );
        self::f( 'login-screen', 'sec_ls_on', 'seal_ls_logo_link', 'Logo link', 'url', [ 'desc' => 'Where the logo goes when clicked. Blank = the site home page.' ] );
        self::f( 'login-screen', 'sec_ls_on', 'seal_ls_bg_url', 'Background image', 'image', [ 'desc' => 'Decorative. At least 1024px wide works best.' ] );
        self::f( 'login-screen', 'sec_ls_on', 'seal_ls_split', 'Split layout', 'toggle', [ 'desc' => 'Form on the left, background image filling the right side. Off = image behind a centered form.' ] );
        self::f( 'login-screen', 'sec_ls_on', 'seal_ls_message', 'Welcome message', 'textarea', [ 'rows' => 4, 'desc' => 'Shown above the login form. Basic formatting allowed.' ] );
        self::f( 'login-screen', 'sec_ls_on', 'seal_ls_button_color', 'Button / accent color', 'color', [ 'desc' => 'Keep it dark enough for white text (contrast 4.5:1 or better).' ] );

        // Login URL
        self::reg( 'login-url', [
            'seal_module_login_url' => $tog,
            'seal_lu_slug'          => [ __CLASS__, 'san_slug' ],
        ] );
        self::section( 'login-url', 'sec_lu', 'Custom login URL', 'Serves the login form at a URL you choose and returns a normal "not found" page for <code>/wp-login.php</code> and for logged-out visitors to <code>/wp-admin</code>. This cuts bot noise; it is not a substitute for lockouts, strong passwords and two-factor.' );
        self::f( 'login-url', 'sec_lu', 'seal_module_login_url', 'Enable', 'toggle' );
        self::f( 'login-url', 'sec_lu', 'seal_lu_slug', 'Login slug', 'text', [ 'desc' => 'Letters, numbers and dashes only. Not a slug used by an ILL page or WordPress.' ] );

        // Login protection
        self::reg( 'protection', [
            'seal_module_login_protection' => $tog,
            'seal_lp_failed_logins' => self::int_cb( 1, 100 ),
            'seal_lp_timeframe'     => self::int_cb( 30, 86400 ),
            'seal_lp_permanent'     => $tog,
            'seal_lp_duration'      => self::int_cb( 1, 9999 ),
            'seal_lp_duration_unit' => self::enum_cb( [ 'minutes', 'hours', 'days' ], 'minutes' ),
            'seal_lp_message'       => $txt,
            'seal_lp_404_enabled'   => $tog,
            'seal_lp_404_hits'      => self::int_cb( 1, 1000 ),
            'seal_lp_404_timeframe' => self::int_cb( 30, 86400 ),
            'seal_lp_404_permanent' => $tog,
            'seal_lp_404_duration'  => self::int_cb( 1, 9999 ),
            'seal_lp_404_duration_unit' => self::enum_cb( [ 'minutes', 'hours', 'days' ], 'hours' ),
            'seal_lp_404_logged_in' => $tog,
            'seal_lp_404_ignore'    => [ __CLASS__, 'san_ext_list' ],
            'seal_lp_404_message'   => $txt,
            'seal_lp_allowlist'     => [ __CLASS__, 'san_ip_list' ],
            'seal_lp_blocklist'     => [ __CLASS__, 'san_ip_list' ],
            'seal_lp_ip_header'     => self::enum_cb( [ '', 'HTTP_X_FORWARDED_FOR', 'HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP' ], '' ),
            'seal_lp_disable_xmlrpc' => $tog,
        ] );
        $ip = SEAL_Module_LoginProtection::client_ip();
        self::section( 'protection', 'sec_lp_on', 'Login protection', 'Your address as this site sees it right now: <strong>' . esc_html( $ip ) . '</strong>. Libraries often share one public address, so add yours (and your libraries\') to the allow list before turning on strict limits.' );
        self::f( 'protection', 'sec_lp_on', 'seal_module_login_protection', 'Enable', 'toggle' );
        self::section( 'protection', 'sec_lp_login', 'Login lockout', 'Locks an address out of the login form after too many wrong passwords or codes.' );
        self::f( 'protection', 'sec_lp_login', 'seal_lp_failed_logins', 'Failed logins allowed', 'number', [ 'min' => 1, 'max' => 100 ] );
        self::f( 'protection', 'sec_lp_login', 'seal_lp_timeframe', 'Within (seconds)', 'number', [ 'min' => 30, 'max' => 86400 ] );
        self::f( 'protection', 'sec_lp_login', 'seal_lp_permanent', 'Ban permanently', 'toggle', [ 'desc' => 'Off = temporary lockout for the duration below.' ] );
        self::f( 'protection', 'sec_lp_login', 'seal_lp_duration', 'Lockout duration', 'number', [ 'min' => 1, 'max' => 9999 ] );
        self::f( 'protection', 'sec_lp_login', 'seal_lp_duration_unit', 'Duration unit', 'select', [ 'options' => [ 'minutes' => 'Minutes', 'hours' => 'Hours', 'days' => 'Days' ] ] );
        self::f( 'protection', 'sec_lp_login', 'seal_lp_message', 'Lockout message', 'text' );
        self::section( 'protection', 'sec_lp_404', '404 detection', 'Locks an address out of the whole site after too many "page not found" hits — typical of scanners hunting for vulnerable files.' );
        self::f( 'protection', 'sec_lp_404', 'seal_lp_404_enabled', 'Enable 404 detection', 'toggle' );
        self::f( 'protection', 'sec_lp_404', 'seal_lp_404_hits', '404 hits allowed', 'number', [ 'min' => 1, 'max' => 1000 ] );
        self::f( 'protection', 'sec_lp_404', 'seal_lp_404_timeframe', 'Within (seconds)', 'number', [ 'min' => 30, 'max' => 86400 ] );
        self::f( 'protection', 'sec_lp_404', 'seal_lp_404_permanent', 'Ban permanently', 'toggle' );
        self::f( 'protection', 'sec_lp_404', 'seal_lp_404_duration', 'Lockout duration', 'number', [ 'min' => 1, 'max' => 9999 ] );
        self::f( 'protection', 'sec_lp_404', 'seal_lp_404_duration_unit', 'Duration unit', 'select', [ 'options' => [ 'minutes' => 'Minutes', 'hours' => 'Hours', 'days' => 'Days' ] ] );
        self::f( 'protection', 'sec_lp_404', 'seal_lp_404_logged_in', 'Include logged-in users', 'toggle' );
        self::f( 'protection', 'sec_lp_404', 'seal_lp_404_ignore', 'Ignore file types', 'text', [ 'desc' => 'Comma-separated extensions that never count (images, styles, favicon…).' ] );
        self::f( 'protection', 'sec_lp_404', 'seal_lp_404_message', 'Lockout message', 'text' );
        self::section( 'protection', 'sec_lp_ip', 'IP lists and proxy', 'One address or CIDR range per line (e.g. <code>203.0.113.7</code> or <code>203.0.113.0/24</code>). The allow list is never locked out.' );
        self::f( 'protection', 'sec_lp_ip', 'seal_lp_allowlist', 'Allow list', 'textarea', [ 'rows' => 4 ] );
        self::f( 'protection', 'sec_lp_ip', 'seal_lp_blocklist', 'Block list', 'textarea', [ 'rows' => 4 ] );
        self::f( 'protection', 'sec_lp_ip', 'seal_lp_ip_header', 'Real-IP header', 'select', [
            'options' => [ '' => 'None (use the connection address)', 'HTTP_X_FORWARDED_FOR' => 'X-Forwarded-For', 'HTTP_CF_CONNECTING_IP' => 'CF-Connecting-IP (Cloudflare)', 'HTTP_X_REAL_IP' => 'X-Real-IP' ],
            'desc'    => 'Only choose one if the site sits behind a proxy or CDN you control. Anyone can forge these headers on a directly reachable server.',
        ] );
        self::section( 'protection', 'sec_lp_misc', 'Other' );
        self::f( 'protection', 'sec_lp_misc', 'seal_lp_disable_xmlrpc', 'Disable XML-RPC', 'toggle', [ 'desc' => 'Recommended. XML-RPC is a common brute-force target. (While Two-Factor is on, XML-RPC sign-in is always off.)' ] );

        // Passwords
        self::reg( 'passwords', [
            'seal_module_password_rules' => $tog,
            'seal_pw_pwned'         => $tog,
            'seal_pw_pwned_roles'   => self::roles_cb( true ),
            'seal_pw_pwned_message' => $txt,
            'seal_pw_strong'        => $tog,
            'seal_pw_min_length'    => self::int_cb( 8, 64 ),
        ] );
        self::section( 'passwords', 'sec_pw_on', 'Password rules' );
        self::f( 'passwords', 'sec_pw_on', 'seal_module_password_rules', 'Enable', 'toggle' );
        self::section( 'passwords', 'sec_pw_pwned', 'Pwned passwords', 'At login, the password is checked against the Have I Been Pwned breach list. Only the first 5 characters of a one-way hash are sent — the password never leaves this server. A breached password blocks the login and emails a reset link. If the service is unreachable the login proceeds.' );
        self::f( 'passwords', 'sec_pw_pwned', 'seal_pw_pwned', 'Check for breached passwords', 'toggle' );
        self::f( 'passwords', 'sec_pw_pwned', 'seal_pw_pwned_roles', 'Apply to roles', 'roles', [ 'allow_all' => true ] );
        self::f( 'passwords', 'sec_pw_pwned', 'seal_pw_pwned_message', 'Message', 'text' );
        self::section( 'passwords', 'sec_pw_strong', 'Strong passwords', 'Applies when a password is set or changed (profile, ILL My Profile, password reset).' );
        self::f( 'passwords', 'sec_pw_strong', 'seal_pw_strong', 'Require strong passwords', 'toggle', [ 'desc' => 'Minimum length, at least three of: lowercase, uppercase, numbers, symbols, and no username or email name inside the password.' ] );
        self::f( 'passwords', 'sec_pw_strong', 'seal_pw_min_length', 'Minimum length', 'number', [ 'min' => 8, 'max' => 64 ] );

        // Two-factor
        self::reg( '2fa', [
            'seal_module_2fa' => $tog,
            'seal_tfa_roles'  => self::roles_cb( false ),
            'seal_tfa_issuer' => $txt,
        ] );
        self::section( '2fa', 'sec_tfa', 'Two-factor authentication', 'Users add an authenticator app (Google/Microsoft Authenticator, Authy, 1Password…) on their <strong>My Profile</strong> page and get 10 one-time backup codes. Backup codes keep working even if the WordPress secret keys are ever rotated.' );
        self::f( '2fa', 'sec_tfa', 'seal_module_2fa', 'Enable', 'toggle' );
        self::f( '2fa', 'sec_tfa', 'seal_tfa_roles', 'Require 2FA for', 'roles', [ 'allow_all' => false, 'desc' => 'Users in these roles are sent to My Profile until they finish setup, and cannot turn it off. Leave all unchecked to make it optional.' ] );
        self::f( '2fa', 'sec_tfa', 'seal_tfa_issuer', 'App label', 'text', [ 'desc' => 'Name shown in the authenticator app. Blank = the System Short Name.' ] );

        // File monitor
        self::reg( 'files', [
            'seal_module_file_monitor' => $tog,
            'seal_fm_frequency'  => self::enum_cb( [ 'hourly', 'twicedaily', 'daily', 'weekly' ], 'daily' ),
            'seal_fm_extensions' => [ __CLASS__, 'san_ext_list' ],
            'seal_fm_exclude'    => [ __CLASS__, 'san_paths' ],
        ] );
        self::section( 'files', 'sec_fm', 'File change detection', 'Takes a fingerprint of every code file in WordPress core, plugins, themes and mu-plugins, then reports anything added, changed or removed. Core files are also compared with the official WordPress.org release. Alerts go to the Admin / Alert email addresses.' );
        self::f( 'files', 'sec_fm', 'seal_module_file_monitor', 'Enable', 'toggle' );
        self::f( 'files', 'sec_fm', 'seal_fm_frequency', 'Scan frequency', 'select', [ 'options' => [ 'hourly' => 'Hourly', 'twicedaily' => 'Twice daily', 'daily' => 'Daily', 'weekly' => 'Weekly' ] ] );
        self::f( 'files', 'sec_fm', 'seal_fm_extensions', 'File types to watch', 'text', [ 'desc' => 'Comma-separated extensions. <code>htaccess</code> and <code>ini</code> cover .htaccess and .user.ini.' ] );
        self::f( 'files', 'sec_fm', 'seal_fm_exclude', 'Ignore paths', 'textarea', [ 'rows' => 3, 'desc' => 'One path prefix per line, relative to the WordPress root, e.g. <code>wp-content/plugins/my-dev-plugin</code>.' ] );
    }

    // ── Sanitizers ────────────────────────────────────────────────────────────

    /** A sanitize callback that clamps a number between $min and $max. */
    public static function int_cb( int $min, int $max ): callable {
        return static fn( $v ) => (string) max( $min, min( $max, (int) $v ) );
    }

    /** A sanitize callback that only accepts one of $allowed, else $default. */
    public static function enum_cb( array $allowed, string $default ): callable {
        return static fn( $v ) => in_array( (string) $v, $allowed, true ) ? (string) $v : $default;
    }

    /**
     * A sanitize callback for a set of role checkboxes, stored comma-separated ('all' when allowed and ticked).
     */
    public static function roles_cb( bool $allow_all ): callable {
        return static function ( $v ) use ( $allow_all ): string {
            $v = is_array( $v ) ? array_map( 'sanitize_key', $v ) : [];
            if ( $allow_all && in_array( 'all', $v, true ) ) { return 'all'; }
            $valid = array_keys( wp_roles()->get_names() );
            return implode( ',', array_values( array_intersect( $v, $valid ) ) );
        };
    }

    /**
     * Sanitizes the custom login slug, refusing WordPress's own paths and the ILL page addresses so the login
     * page can't hide a real page.
     */
    public static function san_slug( $v ): string {
        $s   = sanitize_title( (string) $v );
        $bad = array_merge(
            [ 'wp-admin', 'wp-login', 'wp-content', 'wp-includes', 'wp-json', 'login', 'admin', 'dashboard', 'feed', 'xmlrpc', 'wp-cron', 'wp-signup', 'wp-activate' ],
            array_keys( SEAL_Router::ROUTES )
        );
        if ( strlen( $s ) < 3 || in_array( $s, $bad, true ) || strpos( $s, '/' ) !== false ) {
            add_settings_error( 'seal_lu_slug', 'seal_slug', 'That login slug is too short or already used by WordPress or an ILL page. The previous slug was kept.', 'error' );
            return sanitize_title( (string) get_option( 'seal_lu_slug', 'staff-login' ) ) ?: 'staff-login';
        }
        if ( get_page_by_path( $s ) ) {
            add_settings_error( 'seal_lu_slug', 'seal_slug_page', 'Note: a WordPress page with the slug "' . esc_html( $s ) . '" exists; the login form will take over that URL.', 'warning' );
        }
        return $s;
    }

    /** Keeps only valid IP addresses and CIDR ranges, one per line. */
    public static function san_ip_list( $v ): string {
        $out = [];
        foreach ( preg_split( '/\R/', (string) $v ) as $line ) {
            $line = trim( $line );
            if ( $line === '' ) { continue; }
            [ $addr, $bits ] = array_pad( explode( '/', $line, 2 ), 2, null );
            if ( ! filter_var( $addr, FILTER_VALIDATE_IP ) ) { continue; }
            if ( $bits !== null ) {
                $max = strpos( $addr, ':' ) !== false ? 128 : 32;
                if ( ! ctype_digit( $bits ) || (int) $bits > $max ) { continue; }
            }
            $out[] = $line;
        }
        return implode( "\n", array_unique( $out ) );
    }

    /** A clean comma-separated list of file extensions (letters and digits only). */
    public static function san_ext_list( $v ): string {
        $e = array_filter( array_map( static fn( $x ) => preg_replace( '/[^a-z0-9]/', '', strtolower( trim( $x ) ) ), explode( ',', (string) $v ) ) );
        return implode( ',', array_unique( $e ) );
    }

    /** A clean list of path prefixes, one per line, relative to the WordPress root. */
    public static function san_paths( $v ): string {
        $out = [];
        foreach ( preg_split( '/\R/', (string) $v ) as $l ) {
            $l = trim( str_replace( '\\', '/', $l ), " /\t" );
            if ( $l !== '' && strpos( $l, '..' ) === false && preg_match( '#^[A-Za-z0-9._\-/]+$#', $l ) ) { $out[] = $l; }
        }
        return implode( "\n", array_unique( $out ) );
    }

    // ── Field renderers ───────────────────────────────────────────────────────

    /** The saved value (or default) of the field's option. */
    private static function val( array $a ): string {
        return seal_config( preg_replace( '/^seal_/', '', $a['name'] ) );
    }

    /** Prints a field's help text with an id, so the control can point to it with aria-describedby. */
    private static function desc( array $a ): void {
        if ( ! empty( $a['desc'] ) ) {
            echo '<p class="description" id="' . esc_attr( $a['name'] ) . '-desc">' . wp_kses( $a['desc'], [ 'code' => [], 'em' => [], 'strong' => [] ] ) . '</p>';
        }
    }

    /** The aria-describedby attribute for a field that has help text. */
    private static function described( array $a ): string {
        return empty( $a['desc'] ) ? '' : ' aria-describedby="' . esc_attr( $a['name'] ) . '-desc"';
    }

    /** Text input. */
    public static function field_text( array $a ): void {
        printf( '<input type="text" id="%1$s" name="%1$s" value="%2$s" class="regular-text"%3$s>', esc_attr( $a['name'] ), esc_attr( self::val( $a ) ), self::described( $a ) );
        self::desc( $a );
    }

    /** URL input. */
    public static function field_url( array $a ): void {
        printf( '<input type="url" id="%1$s" name="%1$s" value="%2$s" class="regular-text" placeholder="https://"%3$s>', esc_attr( $a['name'] ), esc_attr( self::val( $a ) ), self::described( $a ) );
        self::desc( $a );
    }

    /** URL input with a "Choose from Media Library" button. */
    public static function field_image( array $a ): void {
        printf( '<input type="url" id="%1$s" name="%1$s" value="%2$s" class="regular-text" placeholder="https://"%3$s> ', esc_attr( $a['name'] ), esc_attr( self::val( $a ) ), self::described( $a ) );
        printf( '<button type="button" class="button seal-media-btn" data-target="#%s">Choose from Media Library</button>', esc_attr( $a['name'] ) );
        self::desc( $a );
    }

    /** Number input with min and max. */
    public static function field_number( array $a ): void {
        printf( '<input type="number" id="%1$s" name="%1$s" value="%2$s" min="%3$d" max="%4$d" class="small-text"%5$s>',
            esc_attr( $a['name'] ), esc_attr( self::val( $a ) ), (int) ( $a['min'] ?? 0 ), (int) ( $a['max'] ?? 9999 ), self::described( $a ) );
        self::desc( $a );
    }

    /** Multi-line input. */
    public static function field_textarea( array $a ): void {
        printf( '<textarea id="%1$s" name="%1$s" rows="%2$d" class="large-text code"%3$s>%4$s</textarea>',
            esc_attr( $a['name'] ), (int) ( $a['rows'] ?? 5 ), self::described( $a ), esc_textarea( self::val( $a ) ) );
        self::desc( $a );
    }

    /** #rrggbb color input. */
    public static function field_color( array $a ): void {
        printf( '<input type="text" id="%1$s" name="%1$s" value="%2$s" class="small-text" maxlength="7" pattern="#[0-9a-fA-F]{6}"%3$s>', esc_attr( $a['name'] ), esc_attr( self::val( $a ) ), self::described( $a ) );
        self::desc( $a );
    }

    /** Dropdown; $a['options'] holds value => label pairs. */
    public static function field_select( array $a ): void {
        $cur = self::val( $a );
        printf( '<select id="%1$s" name="%1$s"%2$s>', esc_attr( $a['name'] ), self::described( $a ) );
        foreach ( (array) $a['options'] as $k => $label ) {
            printf( '<option value="%s"%s>%s</option>', esc_attr( (string) $k ), selected( $cur, (string) $k, false ), esc_html( $label ) );
        }
        echo '</select>';
        self::desc( $a );
    }

    /** Enabled checkbox. */
    public static function field_toggle( array $a ): void {
        printf( '<label><input type="checkbox" id="%1$s" name="%1$s" value="1"%2$s%4$s> %3$s</label>',
            esc_attr( $a['name'] ), checked( '1', self::val( $a ), false ), 'Enabled', self::described( $a ) );
        self::desc( $a );
    }

    /** A checkbox per role (plus All when allowed), for options that apply to chosen roles. */
    public static function field_roles( array $a ): void {
        $cur   = self::val( $a );
        $sel   = $cur === 'all' ? [ 'all' ] : array_filter( array_map( 'trim', explode( ',', $cur ) ) );
        $name  = esc_attr( $a['name'] );
        echo '<fieldset><legend class="screen-reader-text">Roles</legend>';
        if ( ! empty( $a['allow_all'] ) ) {
            printf( '<label><input type="checkbox" name="%s[]" value="all"%s> <strong>All user roles</strong></label><br>', $name, checked( in_array( 'all', $sel, true ), true, false ) );
        }
        foreach ( wp_roles()->get_names() as $slug => $label ) {
            printf( '<label><input type="checkbox" name="%s[]" value="%s"%s> %s</label><br>', $name, esc_attr( $slug ),
                checked( in_array( $slug, $sel, true ), true, false ), esc_html( translate_user_role( $label ) ) );
        }
        echo '</fieldset>';
        self::desc( $a );
    }

    // ── Page ──────────────────────────────────────────────────────────────────

    /**
     * The Security screen: tab bar, the current tab's settings form, and its extra panel (status, actions,
     * logs).
     */
    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) { return; }

        $tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'login-screen';
        if ( ! isset( self::TABS[ $tab ] ) ) { $tab = 'login-screen'; }
        $sys = esc_html( seal_config( 'system_name' ) );
        ?>
        <div class="wrap">
            <h1><?php echo $sys; ?> ILL — 🛡️ Security</h1>
            <?php self::notices(); ?>
            <?php if ( seal_security_disabled() ) : ?>
                <div class="notice notice-warning"><p><strong>Emergency switch active:</strong> <code>ILL_DISABLE_SECURITY</code> is set in wp-config.php, so all security modules are switched off.</p></div>
            <?php endif; ?>
            <h2 class="nav-tab-wrapper">
                <?php foreach ( self::TABS as $slug => $label ) : ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE . '&tab=' . $slug ) ); ?>"
                       class="nav-tab<?php echo $slug === $tab ? ' nav-tab-active' : ''; ?>"<?php echo $slug === $tab ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
                <?php endforeach; ?>
            </h2>

            <?php if ( $tab !== 'activity' ) : ?>
                <form action="options.php" method="post" style="max-width:820px;">
                    <?php
                    settings_fields( self::group( $tab ) );
                    do_settings_sections( self::slug( $tab ) );
                    submit_button( 'Save ' . self::TABS[ $tab ] );
                    ?>
                </form>
            <?php endif; ?>

            <?php
            switch ( $tab ) {
                case 'login-url':  self::extra_login_url(); break;
                case 'passwords':  self::extra_passwords(); break;
                case '2fa':        self::extra_2fa();       break;
                case 'files':      self::extra_files();     break;
                case 'activity':   self::extra_activity();  break;
            }
            ?>
        </div>
        <?php
    }

    /** Shows the result message passed back from an admin-post action. */
    private static function notices(): void {
        $map = [
            'fm_scanned' => [ 'success', 'Scan finished.' ],
            'fm_accepted' => [ 'success', 'Changes accepted. A new baseline was taken.' ],
            'lp_unlocked' => [ 'success', 'Address unlocked.' ],
            'lp_badip'    => [ 'error', 'That is not a valid IP address.' ],
        ];
        $k = isset( $_GET['seal_msg'] ) ? sanitize_key( wp_unslash( $_GET['seal_msg'] ) ) : '';
        if ( isset( $map[ $k ] ) ) {
            printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $map[ $k ][0] ), esc_html( $map[ $k ][1] ) );
        }
        if ( $k === 'pw_forced' && isset( $_GET['n'] ) ) {
            printf( '<div class="notice notice-success is-dismissible"><p>%d user(s) must choose a new password at their next login.</p></div>', absint( $_GET['n'] ) );
        }
    }

    /** Returns to a Security tab with a result message, and stops. */
    private static function back( string $tab, string $msg, array $extra = [] ): void {
        wp_safe_redirect( add_query_arg( array_merge( [ 'page' => self::PAGE, 'tab' => $tab, 'seal_msg' => $msg ], $extra ), admin_url( 'admin.php' ) ) );
        exit;
    }

    /** Stops unless the user is an Administrator and the action's nonce is valid. */
    private static function guard( string $action ): void {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Not allowed.', '', [ 'response' => 403 ] ); }
        check_admin_referer( $action );
    }

    // ── Tab extras ────────────────────────────────────────────────────────────

    /**
     * Login URL tab panel: the address the login page will have, and how to switch the feature off from wp-
     * config.php if locked out.
     */
    private static function extra_login_url(): void {
        $on = seal_config( 'module_login_url' ) === '1';
        echo '<div style="max-width:820px;background:#fff;border:1px solid #ccd0d4;padding:12px 16px;">';
        if ( ! get_option( 'permalink_structure' ) ) {
            echo '<p><strong>Pretty permalinks are required.</strong> Set them under Settings → Permalinks (e.g. "Post name"); until then this feature stays inactive.</p>';
        }
        if ( is_multisite() ) { echo '<p><strong>Multisite is not supported</strong> by this feature.</p>'; }
        echo '<p>Your login page will be: <code>' . esc_html( SEAL_Module_LoginURL::login_url() ) . '</code>' . ( $on ? '' : ' (not active — enable it above)' ) . '</p>';
        echo '<p><strong>Save that address before you log out.</strong> If you get locked out, add this line to <code>wp-config.php</code> above the "That\'s all" comment to switch the feature off, then log in normally at <code>/wp-login.php</code>:</p>';
        echo '<p><code>define( \'ILL_DISABLE_LOGIN_URL\', true );</code></p>';
        echo '<p>In footer links and menus you can use <code>{login}</code> instead of a login address where the plugin supports it (Site Footer links).</p>';
        echo '</div>';
    }

    /** Passwords tab panel: force a password reset for every user in the chosen roles. */
    private static function extra_passwords(): void {
        ?>
        <hr>
        <h2>Force password reset</h2>
        <p>Make users choose a new password at their next login (they get a reset link by email). Leave every role unchecked to include all users.</p>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:820px;"
              onsubmit="return confirm('Require the selected users to reset their passwords?');">
            <input type="hidden" name="action" value="seal_pw_force">
            <?php wp_nonce_field( 'seal_pw_force' ); ?>
            <fieldset><legend class="screen-reader-text">Roles to reset</legend>
            <?php foreach ( wp_roles()->get_names() as $slug => $label ) : ?>
                <label style="margin-right:16px;"><input type="checkbox" name="roles[]" value="<?php echo esc_attr( $slug ); ?>"> <?php echo esc_html( translate_user_role( $label ) ); ?></label>
            <?php endforeach; ?>
            </fieldset>
            <p><label><input type="checkbox" name="include_self" value="1"> Include my own account</label></p>
            <p><button type="submit" class="button">Force reset</button></p>
        </form>
        <?php
    }

    /**
     * Two-Factor tab panel: how many users have it on, and how to reset a user who lost their phone and backup
     * codes.
     */
    private static function extra_2fa(): void {
        $on = get_users( [ 'meta_key' => SEAL_Module_2FA::M_ON, 'meta_value' => '1', 'fields' => 'ID', 'number' => -1 ] );
        echo '<hr><h2>Status</h2><p>' . (int) count( $on ) . ' user(s) have two-factor authentication turned on.</p>';
        echo '<p>To reset a user who lost their phone and backup codes, open their profile under Users and tick <em>Reset this user\'s two-factor authentication</em>.</p>';
        echo '<p>If the WordPress secret keys in <code>wp-config.php</code> are ever changed, authenticator codes stop working for everyone; users then sign in with a backup code and set up their app again.</p>';
    }

    /** File Monitor tab panel: last scan result, Scan now and Accept changes buttons. */
    private static function extra_files(): void {
        $r      = (array) get_option( SEAL_Module_FileMonitor::OPT_RESULT, [] );
        $active = seal_config( 'module_file_monitor' ) === '1';
        echo '<hr><h2>Status</h2>';
        if ( ! $active ) {
            echo '<p>The file monitor is off. Enable it above and save.</p>';
            return;
        }
        if ( ! $r ) {
            echo '<p>No scan has run yet. The first scan creates the baseline.</p>';
        } else {
            $changes = $r['status'] === 'changes';
            printf( '<div class="notice notice-%s inline"><p>%s Last scan: %s · %s files tracked.</p></div>',
                $changes ? 'warning' : 'success',
                $changes ? '<strong>Changes found since the baseline.</strong>' : '<strong>No changes.</strong>',
                esc_html( wp_date( 'Y-m-d H:i', (int) ( $r['time'] ?? 0 ) ) ),
                (int) ( $r['total'] ?? 0 )
            );
            if ( ! empty( $r['core_error'] ) ) { echo '<p><em>' . esc_html( $r['core_error'] ) . '</em></p>'; }
            foreach ( [
                'modified' => 'Modified files', 'added' => 'New files', 'deleted' => 'Deleted files',
                'core_modified' => 'Core files that differ from the official WordPress release', 'core_missing' => 'Core files missing',
                'uploads_code' => 'Code files inside uploads (should not exist)',
            ] as $key => $title ) {
                $list = (array) ( $r[ $key ] ?? [] );
                if ( ! $list ) { continue; }
                echo '<details open><summary><strong>' . esc_html( $title ) . ' (' . count( $list ) . ')</strong></summary><ul style="margin:8px 0 16px 20px;list-style:disc;">';
                foreach ( $list as $p ) { echo '<li><code>' . esc_html( $p ) . '</code></li>'; }
                echo '</ul></details>';
            }
        }
        $next = wp_next_scheduled( SEAL_Module_FileMonitor::HOOK_SCAN );
        if ( $next ) { echo '<p>Next scheduled scan: ' . esc_html( wp_date( 'Y-m-d H:i', $next ) ) . '</p>'; }
        ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:12px;">
            <input type="hidden" name="action" value="seal_fm_scan"><?php wp_nonce_field( 'seal_fm_scan' ); ?>
            <button type="submit" class="button button-primary">Scan now</button>
        </form>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;"
              onsubmit="return confirm('Accept the current state of all files as the new trusted baseline?');">
            <input type="hidden" name="action" value="seal_fm_accept"><?php wp_nonce_field( 'seal_fm_accept' ); ?>
            <button type="submit" class="button">Accept changes (new baseline)</button>
        </form>
        <p class="description">Only accept changes you recognize — an update you ran, or a file you edited yourself.</p>
        <?php
    }

    /** Activity tab: permanent bans, recent lockouts, and a form to unlock an address. */
    private static function extra_activity(): void {
        $bans = (array) get_option( SEAL_Module_LoginProtection::OPT_BANS, [] );
        $log  = array_reverse( (array) get_option( SEAL_Module_LoginProtection::OPT_LOG, [] ) );
        $labels = [ 'login' => 'Failed logins', '404' => '404 hits', 'manual' => 'Block list' ];
        ?>
        <h2>Permanent bans</h2>
        <?php if ( ! $bans ) : ?><p>None.</p><?php else : ?>
            <table class="widefat striped" style="max-width:720px;"><thead><tr><th scope="col">Address</th><th scope="col">Reason</th><th scope="col">Since</th><th scope="col">Action</th></tr></thead><tbody>
            <?php foreach ( $bans as $ip => $b ) : ?>
                <tr>
                    <td><code><?php echo esc_html( (string) $ip ); ?></code></td>
                    <td><?php echo esc_html( $labels[ $b['type'] ?? 'login' ] ?? 'Lockout' ); ?></td>
                    <td><?php echo esc_html( wp_date( 'Y-m-d H:i', (int) ( $b['time'] ?? 0 ) ) ); ?></td>
                    <td><?php self::unlock_form( (string) $ip, 'Unblock' ); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody></table>
        <?php endif; ?>

        <h2 style="margin-top:24px;">Unlock an address</h2>
        <p>Clears a temporary lockout, the failed-attempt counters, and any permanent ban for one address.</p>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="seal_lp_unlock"><?php wp_nonce_field( 'seal_lp_unlock' ); ?>
            <label for="seal_unlock_ip" class="screen-reader-text">IP address</label>
            <input type="text" id="seal_unlock_ip" name="ip" class="regular-text" placeholder="203.0.113.7" required>
            <button type="submit" class="button">Unlock</button>
        </form>

        <h2 style="margin-top:24px;">Recent lockouts</h2>
        <?php if ( ! $log ) : ?><p>No lockouts recorded.</p><?php else : ?>
            <table class="widefat striped" style="max-width:720px;"><thead><tr><th scope="col">When</th><th scope="col">Address</th><th scope="col">Reason</th><th scope="col">Type</th></tr></thead><tbody>
            <?php foreach ( array_slice( $log, 0, 50 ) as $e ) : ?>
                <tr>
                    <td><?php echo esc_html( wp_date( 'Y-m-d H:i', (int) ( $e['time'] ?? 0 ) ) ); ?></td>
                    <td><code><?php echo esc_html( (string) ( $e['ip'] ?? '' ) ); ?></code></td>
                    <td><?php echo esc_html( $labels[ $e['type'] ?? 'login' ] ?? 'Lockout' ); ?></td>
                    <td><?php echo ! empty( $e['permanent'] ) ? 'Permanent' : 'Temporary'; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody></table>
        <?php endif;
    }

    /** A one-button form that unlocks one address. */
    private static function unlock_form( string $ip, string $label ): void {
        ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
            <input type="hidden" name="action" value="seal_lp_unlock"><input type="hidden" name="ip" value="<?php echo esc_attr( $ip ); ?>">
            <?php wp_nonce_field( 'seal_lp_unlock' ); ?>
            <button type="submit" class="button button-small"><?php echo esc_html( $label ); ?></button>
        </form>
        <?php
    }

    // ── Actions ───────────────────────────────────────────────────────────────

    /** Scan now: runs the file monitor without emailing and shows the result. */
    public static function do_fm_scan(): void {
        self::guard( 'seal_fm_scan' );
        SEAL_Module_FileMonitor::run( false );
        self::back( 'files', 'fm_scanned' );
    }

    /** Accept changes: takes a new baseline so the current files count as expected. */
    public static function do_fm_accept(): void {
        self::guard( 'seal_fm_accept' );
        SEAL_Module_FileMonitor::rebaseline();
        SEAL_Audit::event( 'security', 'Security → File Monitor', 'Accepted the current files as the new baseline' );
        self::back( 'files', 'fm_accepted' );
    }

    /** Unlocks the posted IP address (temporary lockout or permanent ban). */
    public static function do_lp_unlock(): void {
        self::guard( 'seal_lp_unlock' );
        $ip = isset( $_POST['ip'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['ip'] ) ) ) : '';
        if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) { self::back( 'activity', 'lp_badip' ); }
        SEAL_Module_LoginProtection::unlock( $ip );
        SEAL_Audit::event( 'security', 'Security → Login Protection', 'Unlocked IP address ' . $ip );
        self::back( 'activity', 'lp_unlocked' );
    }

    /**
     * Flags every user in the chosen roles so their next login requires a new password. The current user is
     * skipped unless the include-yourself box was ticked.
     */
    public static function do_pw_force(): void {
        self::guard( 'seal_pw_force' );
        $valid = array_keys( wp_roles()->get_names() );
        $roles = isset( $_POST['roles'] ) ? array_values( array_intersect( array_map( 'sanitize_key', (array) wp_unslash( $_POST['roles'] ) ), $valid ) ) : [];
        $skip  = empty( $_POST['include_self'] ) ? get_current_user_id() : 0;
        $n     = SEAL_Module_PasswordRules::force_reset( $roles, $skip );
        SEAL_Audit::event( 'security', 'Security → Passwords', 'Forced a password change at next sign-in for ' . ( $roles ? implode( ', ', $roles ) : 'no roles' ) . " ($n accounts)" );
        self::back( 'passwords', 'pw_forced', [ 'n' => $n ] );
    }
}
