<?php
/**
 * Tests that Approve / Deny can't run before the two-factor set-up gate: both modules are started in bootstrap
 * order (User Approval, then Two-Factor) and admin_init is run the way WordPress runs it (priority, then the order
 * callbacks were added). Covers the Users-screen quick links, the emailed links and the bulk action, plus the
 * handlers' own check when the gate is not there.
 *
 *   php tests/test-approval-gate.php
 */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 403 ); exit; }
define( 'ABSPATH', __DIR__ . '/' );

class WP_Error { public function __construct( public string $code = '', public string $message = '' ) {} }
class WP_User {
    public string $user_login; public string $user_email = 'x@library.org'; public string $first_name = 'A'; public string $last_name = 'B'; public string $display_name = 'A B';
    public function __construct( public int $ID, public array $roles ) { $this->user_login = 'u' . $ID; }
    public function exists(): bool { return $this->ID > 0; }
}
class Halt extends Exception {}

// ── A small hook system that runs callbacks like WordPress: by priority, then in the order they were added ──
$GLOBALS['hooks'] = [];
function add_action( $h, $cb, $prio = 10, $n = 1 ): void { $GLOBALS['hooks'][ $h ][ $prio ][] = $cb; }
function add_filter( $h, $cb, $prio = 10, $n = 1 ): void { add_action( $h, $cb, $prio, $n ); }
function remove_action( $h, $cb, $prio = 10 ): void {}
function run_hook( string $h ): string {
    $by = $GLOBALS['hooks'][ $h ] ?? []; ksort( $by );
    try { foreach ( $by as $cbs ) { foreach ( $cbs as $cb ) { $cb(); } } return 'finished'; }
    catch ( Halt $e ) { return $e->getMessage(); }
}

$GLOBALS['cfg']  = [ 'module_2fa' => '1', 'tfa_roles' => 'illadmin', 'module_user_approval' => '1', 'system_name' => 'Town ILL' ];
$GLOBALS['meta'] = [];
$GLOBALS['me']   = new WP_User( 2, [ 'illadmin' ] );
$GLOBALS['pagenow'] = 'users.php';
function seal_config( $k ): string { return $GLOBALS['cfg'][ $k ] ?? ''; }
function seal_security_disabled(): bool { return false; }
function get_user_meta( $uid, $k, $s ) { return $GLOBALS['meta'][ $uid ][ $k ] ?? ''; }
function update_user_meta( $uid, $k, $v ): void { $GLOBALS['meta'][ $uid ][ $k ] = $v; }
function delete_user_meta( $uid, $k ): void { unset( $GLOBALS['meta'][ $uid ][ $k ] ); }
function get_userdata( $uid ) { return $uid === 9 ? new WP_User( 9, [ 'libstaff' ] ) : false; }
function wp_get_current_user(): WP_User { return $GLOBALS['me']; }
function get_current_user_id(): int { return $GLOBALS['me']->ID; }
function is_user_logged_in(): bool { return true; }
function current_user_can( $cap, ...$a ): bool { return true; }
function wp_verify_nonce( $n, $a ): bool { return $n === 'valid'; }
function sanitize_key( $s ): string { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $s ) ); }
function sanitize_text_field( $s ): string { return trim( (string) $s ); }
function wp_unslash( $s ) { return $s; }
function wp_doing_ajax(): bool { return false; }
function wp_doing_cron(): bool { return false; }
function is_admin(): bool { return true; }
function get_query_var( $k ) { return ''; }
function home_url( $p = '' ): string { return 'https://ill.example' . $p; }
function admin_url( $p = '' ): string { return 'https://ill.example/wp-admin/' . $p; }
function wp_login_url(): string { return home_url( '/wp-login.php' ); }
function add_query_arg( $a, $b, $c = null ): string { return is_array( $a ) ? $b . '?' . http_build_query( $a ) : $c . '?' . $a . '=' . $b; }
function wp_redirect( $u ): void { throw new Halt( 'redirect:' . $u ); }
function wp_safe_redirect( $u ): void { throw new Halt( 'redirect:' . $u ); }
function wp_die( $m = '', $t = '', $a = [] ): void { throw new Halt( 'die:' . ( $a['response'] ?? 500 ) ); }
function esc_html( $s ): string { return htmlspecialchars( (string) $s ); }
function esc_html__( $s, $d = '' ): string { return $s; }
function __( $s, $d = '' ): string { return $s; }
class SEAL_Functions {
    public static function __callStatic( $n, $a ) { return ''; }
    public static function send_email( ...$a ): bool { $GLOBALS['mails'][] = $a[1]; return true; }
}
class SEAL_Emails { public static function subject( ...$a ): string { return 'subject'; } }

require dirname( __DIR__ ) . '/includes/modules/class-seal-module-user-approval.php';
require dirname( __DIR__ ) . '/includes/modules/class-seal-module-2fa.php';
SEAL_Module_UserApproval::init();   // seal-ill-system.php starts modules in this order on plugins_loaded
SEAL_Module_2FA::init();

$fail = 0;
function t( string $name, bool $ok, string $extra = '' ): void { global $fail; if ( ! $ok ) { $fail++; } echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( $ok ? '' : "  $extra" ) . "\n"; }
function pending(): void {
    $GLOBALS['meta'][9] = [ SEAL_Module_UserApproval::META_KEY => 'pending', SEAL_Module_UserApproval::TOKEN_META => hash( 'sha256', 'tok' ) . '|' . ( time() + 3600 ) ];
    $GLOBALS['mails'] = [];
}
function status(): string { return (string) get_user_meta( 9, SEAL_Module_UserApproval::META_KEY, true ); }
$quick = [ 'seal_approve' => '9', 'seal_action' => 'approve', '_nonce' => 'valid' ];
$email = [ 'seal_decide' => '9', 'seal_action' => 'approve', 'seal_token' => 'tok' ];

echo "=== ILL Administrator, two-factor required, not set up ===\n";
pending(); $_GET = $quick; $_REQUEST = $_GET;
$r = run_hook( 'admin_init' );
t( 'quick Approve link: stopped by the gate, account still pending', str_starts_with( $r, 'redirect:https://ill.example/illuser' ) && status() === 'pending' && ! $GLOBALS['mails'], "$r / " . status() );
pending(); $_GET = $email; $_REQUEST = $_GET;
$r = run_hook( 'admin_init' );
t( 'emailed Approve link: stopped by the gate, account still pending, token kept', str_starts_with( $r, 'redirect:https://ill.example/illuser' ) && status() === 'pending'
    && get_user_meta( 9, SEAL_Module_UserApproval::TOKEN_META, true ) !== '', "$r / " . status() );

echo "=== handlers' own check (gate not hooked) ===\n";
$saved = $GLOBALS['hooks']['admin_init'];
foreach ( $GLOBALS['hooks']['admin_init'] as $p => $cbs ) { $GLOBALS['hooks']['admin_init'][ $p ] = array_values( array_filter( $cbs, fn( $cb ) => $cb !== [ 'SEAL_Module_2FA', 'gate' ] ) ); }
pending(); $_GET = $quick; $r = run_hook( 'admin_init' );
t( 'quick link: refused (403), still pending', $r === 'die:403' && status() === 'pending', "$r / " . status() );
pending(); $_GET = $email; $r = run_hook( 'admin_init' );
t( 'email link: refused (403), still pending', $r === 'die:403' && status() === 'pending', "$r / " . status() );
pending(); $_GET = [];
try { SEAL_Module_UserApproval::handle_bulk( 'users.php', 'seal_bulk_approve', [ 9 ] ); $r = 'finished'; } catch ( Halt $e ) { $r = $e->getMessage(); }
t( 'bulk Approve: refused (403), still pending', $r === 'die:403' && status() === 'pending', "$r / " . status() );
pending();
try { SEAL_Module_UserApproval::decide( 9, 'approve' ); $r = 'finished'; } catch ( Halt $e ) { $r = $e->getMessage(); }
t( 'ILL account pages (decide()): refused (403), still pending', $r === 'die:403' && status() === 'pending', "$r / " . status() );
$GLOBALS['hooks']['admin_init'] = $saved;

echo "=== after setting up two-factor ===\n";
update_user_meta( 2, SEAL_Module_2FA::M_ON, '1' );
pending(); $_GET = $quick; $_REQUEST = $_GET;
$r = run_hook( 'admin_init' );
t( 'quick Approve link: approved', str_contains( $r, 'seal_approval_done=approved' ) && status() === 'approved' && $GLOBALS['mails'], "$r / " . status() );
pending(); $_GET = $email; $_REQUEST = $_GET;
$r = run_hook( 'admin_init' );
t( 'emailed Approve link: approved', str_contains( $r, 'seal_approval_done=approved' ) && status() === 'approved', "$r / " . status() );
pending(); $_GET = []; $_REQUEST = [];
t( 'ordinary admin page: nothing happens', run_hook( 'admin_init' ) === 'finished' && status() === 'pending' );

echo $fail ? "\nFAILURES: $fail\n" : "\nAll passed.\n";
exit( $fail ? 1 : 0 );
