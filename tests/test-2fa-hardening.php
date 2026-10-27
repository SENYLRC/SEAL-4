<?php
/**
 * Tests the two-factor hardening with WordPress stubs: XML-RPC and application passwords off while the module is on,
 * the set-up gate for admin-post / admin-ajax / ILL routes, and the current password needed to add an authenticator app.
 *
 *   php tests/test-2fa-hardening.php
 */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 403 ); exit; }
define( 'ABSPATH', __DIR__ . '/' );

// ── WordPress stand-ins ──
class WP_Error { public function __construct( public string $code = '', public string $message = '' ) {} }
class WP_User {
    public string $user_login = 'pat'; public string $user_email = ''; public string $display_name = 'Pat'; public string $user_pass = 'right';
    public function __construct( public int $ID = 7, public array $roles = [ 'illadmin' ] ) {}
    public function exists(): bool { return $this->ID > 0; }
}
class Halt extends Exception {}
class SEAL_DB { public static function encrypt_pass( string $s ): string { return 'enc:' . $s; } public static function decrypt_pass( string $s ): string { return substr( $s, 4 ); } }

$GLOBALS['cfg']   = [ 'module_2fa' => '1', 'tfa_roles' => 'illadmin' ];
$GLOBALS['meta']  = [];
$GLOBALS['hooks'] = [];
$GLOBALS['ajax']  = false;
$GLOBALS['qv']    = '';
$GLOBALS['user']  = new WP_User();
function seal_config( string $k ): string { return $GLOBALS['cfg'][ $k ] ?? ''; }
function seal_security_disabled(): bool { return false; }
function add_action( $h, $cb, ...$a ): void { $GLOBALS['hooks'][ $h ][] = $cb; }
function add_filter( $h, $cb, ...$a ): void { $GLOBALS['hooks'][ $h ][] = $cb; }
function do_action( ...$a ): void {}
function get_user_meta( $uid, $k, $single ) { return $GLOBALS['meta'][ $uid ][ $k ] ?? ''; }
function update_user_meta( $uid, $k, $v ): void { $GLOBALS['meta'][ $uid ][ $k ] = $v; }
function delete_user_meta( $uid, $k ): void { unset( $GLOBALS['meta'][ $uid ][ $k ] ); }
function wp_verify_nonce( $n, $a ): bool { return $n === 'ok'; }
function sanitize_text_field( $s ): string { return trim( (string) $s ); }
function sanitize_key( $s ): string { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $s ) ); }
function wp_unslash( $s ) { return $s; }
function wp_check_password( $p, $hash, $uid ): bool { return $p === $hash; }
function wp_die( $m = '', $t = '', $a = [] ): void { throw new Halt( 'die:' . ( $a['response'] ?? 500 ) ); }
function wp_safe_redirect( $u ): void { throw new Halt( 'redirect:' . $u ); }
function is_user_logged_in(): bool { return $GLOBALS['user']->exists(); }
function wp_get_current_user(): WP_User { return $GLOBALS['user']; }
function wp_doing_ajax(): bool { return $GLOBALS['ajax']; }
function wp_doing_cron(): bool { return false; }
function get_query_var( $k ) { return $GLOBALS['qv']; }
function is_admin(): bool { return $GLOBALS['pagenow'] !== 'index.php'; }
function home_url( $p = '' ): string { return 'https://ill.example' . $p; }
function add_query_arg( $k, $v, $u ): string { return $u . '?' . $k . '=' . $v; }
function esc_html__( $s, $d = '' ): string { return $s; }
function __( $s, $d = '' ): string { return $s; }

require dirname( __DIR__ ) . '/includes/class-seal-totp.php';
require dirname( __DIR__ ) . '/includes/modules/class-seal-module-2fa.php';

$fail = 0;
function t( string $name, bool $ok, string $extra = '' ): void { global $fail; if ( ! $ok ) { $fail++; } echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( $ok ? '' : "  $extra" ) . "\n"; }
function gate_result(): string { try { SEAL_Module_2FA::gate(); return 'through'; } catch ( Halt $h ) { return $h->getMessage(); } }

echo "=== sign-ins that skip the second step ===\n";
SEAL_Module_2FA::init();
$h = $GLOBALS['hooks'];
t( 'XML-RPC turned off by the module itself', in_array( '__return_false', $h['xmlrpc_enabled'] ?? [], true ) );
t( 'application passwords turned off by the module itself', in_array( '__return_false', $h['wp_is_application_passwords_available'] ?? [], true ) );
t( 'normal sign-in passes the authenticate backstop', SEAL_Module_2FA::no_xmlrpc_login( $GLOBALS['user'] ) instanceof WP_User );

echo "=== set-up gate (role requires two-factor, not set up) ===\n";
$GLOBALS['pagenow'] = 'admin-post.php'; $_REQUEST = [ 'action' => 'seal_export_users_csv' ];
t( 'admin-post export refused', gate_result() === 'die:403' );
$GLOBALS['pagenow'] = 'admin-ajax.php'; $GLOBALS['ajax'] = true; $_REQUEST = [ 'action' => 'seal_2fa_key_options' ];
t( 'admin-ajax: adding a security key allowed', gate_result() === 'through' );
$_REQUEST = [ 'action' => 'seal_something_else' ];
t( 'admin-ajax: other actions refused', gate_result() === 'die:403' );
$GLOBALS['ajax'] = false; $GLOBALS['pagenow'] = 'index.php'; $_REQUEST = [];
$GLOBALS['qv'] = 'pullslip';
t( 'ILL route (pull slip) sent to set-up', str_starts_with( gate_result(), 'redirect:' ) );
$GLOBALS['qv'] = 'illuser';
t( 'My Profile reachable', gate_result() === 'through' );
$GLOBALS['qv'] = '';
t( 'ordinary WordPress page reachable (shortcode pages check needs_setup())', gate_result() === 'through' );
t( 'needs_setup() true', SEAL_Module_2FA::needs_setup( $GLOBALS['user'] ) );
$GLOBALS['cfg']['tfa_roles'] = 'libsys';
$GLOBALS['pagenow'] = 'admin-post.php'; $_REQUEST = [ 'action' => 'seal_export_users_csv' ];
t( 'role not required: admin-post allowed', gate_result() === 'through' );
$GLOBALS['cfg']['tfa_roles'] = 'illadmin';

echo "=== adding an authenticator app ===\n";
$uid    = $GLOBALS['user']->ID;
$secret = SEAL_TOTP::generate_secret();
update_user_meta( $uid, SEAL_Module_2FA::M_PENDING, 'enc:' . $secret );
$code   = SEAL_TOTP::hotp( SEAL_TOTP::b32_decode( $secret ), intdiv( time(), 30 ) );
$post   = static function ( string $pass ) use ( $code ): array {
    $_POST = [ 'seal_2fa_nonce' => 'ok', 'seal_2fa_action' => 'enable', 'seal_2fa_code' => $code, 'seal_2fa_password' => $pass ];
    return SEAL_Module_2FA::handle_post( $GLOBALS['user'] );
};
$r = $post( '' );
t( 'valid code but no password: refused', $r[1] === 'error' && ! SEAL_Module_2FA::app_on( $uid ), json_encode( $r ) );
$r = $post( 'wrong' );
t( 'valid code, wrong password: refused and counted', $r[1] === 'error' && ! SEAL_Module_2FA::app_on( $uid ) && ( get_user_meta( $uid, SEAL_Module_2FA::M_FAILS, true )['n'] ?? 0 ) === 2, json_encode( $r ) );
$r = $post( 'right' );
t( 'valid code and current password: app on, backup codes issued', $r[1] === 'success' && SEAL_Module_2FA::app_on( $uid ) && count( $r[2] ) > 0, json_encode( $r ) );
t( 'enrolled: no longer needs set-up', ! SEAL_Module_2FA::needs_setup( $GLOBALS['user'] ) );

echo "=== XML-RPC backstop ===\n";
define( 'XMLRPC_REQUEST', true );
t( 'password sign-in through XML-RPC refused', SEAL_Module_2FA::no_xmlrpc_login( $GLOBALS['user'] ) instanceof WP_Error );
t( 'earlier errors passed on unchanged', ( $e = new WP_Error( 'x' ) ) === SEAL_Module_2FA::no_xmlrpc_login( $e ) );

echo $fail ? "\nFAILURES: $fail\n" : "\nAll passed.\n";
exit( $fail ? 1 : 0 );
