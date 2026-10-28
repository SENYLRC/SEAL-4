<?php
/**
 * Tests Password Rules' sign-in check with WordPress stubs: a forced reset (and a breached password) blocks the
 * account password on every channel, XML-RPC included; an application password skips only the breach check.
 * REST Basic auth is run the way WordPress runs it (determine_current_user → wp_validate_application_password(),
 * which never calls the 'authenticate' filter). Loads only this module, so the result doesn't depend on Two-Factor
 * or Login Protection being on. The two core functions are a short copy of WordPress 6.8's; to use the real ones:
 *
 *   php tests/test-password-rules.php
 *   WP_CORE_USER_PHP=/path/to/wp-includes/user.php php tests/test-password-rules.php
 */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 403 ); exit; }
define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );

class WP_Error {
    public array $errors = [];
    public function __construct( public string $code = '', public string $message = '' ) { if ( $code !== '' ) { $this->errors[ $code ] = $message; } }
    public function add( string $code, string $message ): void { $this->errors[ $code ] = $message; }
    public function has_errors(): bool { return (bool) $this->errors; }
}
class WP_User { public string $user_login = 'pat'; public function __construct( public int $ID = 7, public array $roles = [ 'libstaff' ] ) {} }
$GLOBALS['meta'] = []; $GLOBALS['hooks'] = []; $GLOBALS['hibp'] = 0; $GLOBALS['mails'] = 0;
$GLOBALS['cfg'] = [ 'module_password_rules' => '1', 'pw_pwned' => '1', 'pw_pwned_roles' => 'all', 'pw_pwned_message' => 'That password is in a breach list.' ];
function seal_config( $k ): string { return $GLOBALS['cfg'][ $k ] ?? ''; }
function seal_security_disabled(): bool { return false; }
function add_filter( $h, $cb, ...$a ): void { $GLOBALS['hooks'][ $h ][] = $cb; }
function add_action( $h, $cb, ...$a ): void { $GLOBALS['hooks'][ $h ][] = $cb; }
function do_action( $h, ...$a ): void { foreach ( $GLOBALS['hooks'][ $h ] ?? [] as $cb ) { $cb( ...$a ); } }
function apply_filters( $h, $v, ...$a ) { foreach ( $GLOBALS['hooks'][ $h ] ?? [] as $cb ) { $v = $cb( $v, ...$a ); } return $v; }
function get_user_meta( $uid, $k, $s ) { return $GLOBALS['meta'][ $uid ][ $k ] ?? ''; }
function update_user_meta( $uid, $k, $v ): void { $GLOBALS['meta'][ $uid ][ $k ] = $v; }
function delete_user_meta( $uid, $k ): void { unset( $GLOBALS['meta'][ $uid ][ $k ] ); }
function get_transient( $k ) { return false; }
function set_transient( $k, $v, $t ): void {}
function retrieve_password( $l ): void { $GLOBALS['mails']++; }
function esc_html( $s ): string { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function is_wp_error( $x ): bool { return $x instanceof WP_Error; }
// Breach API stand-in: "breached" is in the list.
function wp_remote_get( $url, $a ) { $GLOBALS['hibp']++; return [ 'code' => 200, 'body' => substr( strtoupper( sha1( 'breached' ) ), 5 ) . ":42\nABCDEF:1" ]; }
function wp_remote_retrieve_response_code( $r ): int { return $r['code']; }
function wp_remote_retrieve_body( $r ): string { return $r['body']; }

require dirname( __DIR__ ) . '/includes/modules/class-seal-module-password-rules.php';
SEAL_Module_PasswordRules::init();

$fail = 0;
function t( string $name, bool $ok, string $extra = '' ): void { global $fail; if ( ! $ok ) { $fail++; } echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( $ok ? '' : "  $extra" ) . "\n"; }
/** Runs the authenticate filters the way WordPress does (user already accepted at priority 20). */
$auth = static function ( string $password ) {
    $u = new WP_User();
    foreach ( $GLOBALS['hooks']['authenticate'] as $cb ) { $u = $cb( $u, 'pat', $password ); }
    return $u;
};
$force = static fn() => update_user_meta( 7, SEAL_Module_PasswordRules::META_FORCE, 'admin' );
$clear = static fn() => delete_user_meta( 7, SEAL_Module_PasswordRules::META_FORCE );
$app   = static function (): void { ( new ReflectionProperty( 'SEAL_Module_PasswordRules', 'app_password' ) )->setValue( null, false ); };

echo "=== browser sign-in ===\n";
$force();
t( 'forced reset: refused', $auth( 'good-password' ) instanceof WP_Error );
$clear();
t( 'normal password: allowed', $auth( 'good-password' ) instanceof WP_User );
t( 'breached password: refused and flagged', $auth( 'breached' ) instanceof WP_Error && get_user_meta( 7, SEAL_Module_PasswordRules::META_FORCE, true ) === 'pwned' );
$clear();

echo "=== XML-RPC (account password) ===\n";
define( 'XMLRPC_REQUEST', true );
$force();
t( 'forced reset through XML-RPC: refused (was allowed before)', $auth( 'good-password' ) instanceof WP_Error );
$clear();
t( 'breached password through XML-RPC: refused', $auth( 'breached' ) instanceof WP_Error );
$clear();
t( 'normal password through XML-RPC: allowed', $auth( 'good-password' ) instanceof WP_User );

echo "=== application password ===\n";
do_action( 'application_password_did_authenticate', new WP_User(), [] );
$before = $GLOBALS['hibp'];
t( 'app password: no breach lookup, allowed', $auth( 'breached' ) instanceof WP_User && $GLOBALS['hibp'] === $before );
$force();
t( 'app password with a forced reset: refused', $auth( 'abcd efgh ijkl mnop' ) instanceof WP_Error );
$clear(); $app();

echo "=== REST API, application password (Basic auth) ===\n";
// What WordPress needs for wp_authenticate_application_password(): user 'pat' has one application password.
class WP_Application_Passwords {
    public static array $used = [];
    public static function is_in_use(): bool { return true; }
    public static function get_user_application_passwords( int $uid ): array { return [ [ 'uuid' => 'u1', 'password' => 'abcdefghijklmnop' ] ]; }
    public static function check_password( string $p, string $hash ): bool { return $p === $hash; }
    public static function record_application_password_usage( int $uid, string $uuid ): void { self::$used[] = $uuid; }
}
function get_user_by( $f, $v ) { return $v === 'pat' ? new WP_User() : false; }
function is_email( $s ): bool { return str_contains( (string) $s, '@' ); }
function wp_is_application_passwords_available(): bool { return true; }
function wp_is_application_passwords_available_for_user( $u ): bool { return true; }
function __( $s, $d = '' ): string { return $s; }
if ( ( $core = getenv( 'WP_CORE_USER_PHP' ) ) !== false && $core !== '' ) {
    $src = (string) file_get_contents( $core );
    foreach ( [ 'wp_authenticate_application_password', 'wp_validate_application_password' ] as $fn ) {
        if ( ! preg_match( '/^function ' . $fn . '\(.*?^}$/ms', $src, $m ) ) { echo "FAIL $fn not found in $core\n"; exit( 1 ); }
        eval( $m[0] );
    }
    echo "(using WordPress's own functions from $core)\n";
} else {
    // Short copy of WordPress 6.8 (wp-includes/user.php): only the parts this path uses.
    function wp_authenticate_application_password( $input_user, $username, $password ) {
        if ( $input_user instanceof WP_User ) { return $input_user; }
        $user = get_user_by( 'login', $username );
        if ( ! $user ) { return new WP_Error( 'invalid_username', 'Unknown username.' ); }
        $password = preg_replace( '/[^a-z\d]/i', '', $password );
        foreach ( WP_Application_Passwords::get_user_application_passwords( $user->ID ) as $item ) {
            if ( ! WP_Application_Passwords::check_password( $password, $item['password'] ) ) { continue; }
            $error = new WP_Error();
            do_action( 'wp_authenticate_application_password_errors', $error, $user, $item, $password );
            if ( is_wp_error( $error ) && $error->has_errors() ) {
                do_action( 'application_password_failed_authentication', $error );
                return $error;
            }
            WP_Application_Passwords::record_application_password_usage( $user->ID, $item['uuid'] );
            do_action( 'application_password_did_authenticate', $user, $item );
            return $user;
        }
        return new WP_Error( 'incorrect_password', 'The provided password is an invalid application password.' );
    }
    function wp_validate_application_password( $input_user ) {
        if ( ! empty( $input_user ) ) { return $input_user; }
        if ( ! isset( $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'] ) ) { return $input_user; }
        $authenticated = wp_authenticate_application_password( null, $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'] );
        return $authenticated instanceof WP_User ? $authenticated->ID : $input_user;
    }
}
define( 'REST_REQUEST', true );
// As in default-filters.php: cookies first, then application passwords at priority 20. No 'authenticate' filter.
add_filter( 'determine_current_user', 'wp_validate_application_password', 20 );
add_action( 'application_password_failed_authentication', static function ( $e ) { $GLOBALS['rest_error'] = $e; } );
$rest = static function (): int {
    $GLOBALS['rest_error'] = null;
    $_SERVER['PHP_AUTH_USER'] = 'pat'; $_SERVER['PHP_AUTH_PW'] = 'abcd efgh ijkl mnop';
    return (int) apply_filters( 'determine_current_user', false );
};
$GLOBALS['hibp'] = 0; $GLOBALS['mails'] = 0; WP_Application_Passwords::$used = [];
$force();
t( 'REST, forced reset: refused (not signed in)', $rest() === 0 && WP_Application_Passwords::$used === [] );
t( 'REST, forced reset: WordPress gets our error (REST answers 401)', isset( $GLOBALS['rest_error']->errors['seal_pw_reset_required'] ), json_encode( $GLOBALS['rest_error'] ) );
t( 'REST, forced reset: reset link emailed', $GLOBALS['mails'] === 1 );
$clear(); $app();
t( 'REST, flag cleared: signed in', $rest() === 7 && WP_Application_Passwords::$used === [ 'u1' ] && $GLOBALS['rest_error'] === null );
t( 'REST: the application password never goes to the breach API', $GLOBALS['hibp'] === 0 );
$app();

echo "=== account password still checked after the REST hook was added ===\n";
$force();
t( 'forced reset, account password: refused', $auth( 'good-password' ) instanceof WP_Error );
$clear();
t( 'breached account password: refused', $auth( 'breached' ) instanceof WP_Error && $GLOBALS['hibp'] === 1 );
$clear();

echo $fail ? "\nFAILURES: $fail\n" : "\nAll passed.\n";
exit( $fail ? 1 : 0 );
