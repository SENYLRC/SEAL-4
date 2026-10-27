<?php
/**
 * Tests Password Rules' sign-in check with WordPress stubs: a forced reset (and a breached password) blocks the
 * account password on every channel, XML-RPC included; an application password skips only the breach check.
 * Loads only this module, so the result doesn't depend on Two-Factor or Login Protection being on.
 *
 *   php tests/test-password-rules.php
 */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 403 ); exit; }
define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );

class WP_Error { public function __construct( public string $code = '', public string $message = '' ) {} }
class WP_User { public string $user_login = 'pat'; public function __construct( public int $ID = 7, public array $roles = [ 'libstaff' ] ) {} }
$GLOBALS['meta'] = []; $GLOBALS['hooks'] = []; $GLOBALS['hibp'] = 0; $GLOBALS['mails'] = 0;
$GLOBALS['cfg'] = [ 'module_password_rules' => '1', 'pw_pwned' => '1', 'pw_pwned_roles' => 'all', 'pw_pwned_message' => 'That password is in a breach list.' ];
function seal_config( $k ): string { return $GLOBALS['cfg'][ $k ] ?? ''; }
function seal_security_disabled(): bool { return false; }
function add_filter( $h, $cb, ...$a ): void { $GLOBALS['hooks'][ $h ][] = $cb; }
function add_action( $h, $cb, ...$a ): void { $GLOBALS['hooks'][ $h ][] = $cb; }
function do_action( $h, ...$a ): void { foreach ( $GLOBALS['hooks'][ $h ] ?? [] as $cb ) { $cb( ...$a ); } }
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

echo $fail ? "\nFAILURES: $fail\n" : "\nAll passed.\n";
exit( $fail ? 1 : 0 );
