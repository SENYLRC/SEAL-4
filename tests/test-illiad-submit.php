<?php
/**
 * Tests ILLiad submission (SEAL_Resend::send_to_illiad(), also used by the request page) with WordPress stubs and a
 * fake ILLiad: incomplete library records, an unreadable API key, no / short transaction numbers (nothing saved),
 * borrower → lender mapping, a failed save, and the request page's alert email.
 *
 *   php tests/test-illiad-submit.php
 */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 403 ); exit; }
define( 'ABSPATH', __DIR__ . '/' );

class WP_Error { public function __construct( public string $code = '', public string $message = '' ) {} public function get_error_message(): string { return $this->message; } }
function is_wp_error( $x ): bool { return $x instanceof WP_Error; }
function wp_json_encode( $v ): string { return json_encode( $v ); }
function wp_date( $f, $t = null ): string { return date( $f, $t ?? time() ); }
function wp_strip_all_tags( $s ): string { return strip_tags( (string) $s ); }
function esc_html( $s ): string { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function add_query_arg( $k, $v, $u ): string { return $u . '?' . $k . '=' . $v; }
function home_url( $p = '' ): string { return 'https://ill.example' . $p; }
function seal_config( $k ): string { return $k === 'system_name' ? 'Town ILL' : ''; }
function get_option( $k, $d = null ) { return $GLOBALS['opt'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ): void { $GLOBALS['opt'][ $k ] = $v; }
function wp_get_current_user() { return null; }

// Fake ILLiad: the next answer is set per test; the request is recorded.
$GLOBALS['illiad'] = null; $GLOBALS['posted'] = null;
function wp_remote_post( $url, $args ) { $GLOBALS['posted'] = [ 'url' => $url ] + $args; return $GLOBALS['illiad']; }
function wp_remote_retrieve_body( $r ): string { return (string) ( $r['body'] ?? '' ); }
function wp_remote_retrieve_response_code( $r ): int { return (int) ( $r['code'] ?? 0 ); }
function answer( array $json, int $code = 200 ): array { return [ 'code' => $code, 'body' => json_encode( $json ) ]; }

class SEAL_Secret { public static function open( string $v ): string { return str_starts_with( $v, 'ik1:' ) ? '' : $v; } }
class SEAL_Functions {
    public static array $alerts = [];
    public static function send_alert( string $s, string $b ): bool { self::$alerts[] = [ $s, $b ]; return true; }
    public static function email_details( array $r ): string { $o = ''; foreach ( $r as $k => $v ) { $o .= "<p>$k: " . esc_html( $v ) . '</p>'; } return $o; }
    public static function email_button( string $u, string $l ): string { return "<a href=\"$u\">$l</a>"; }
}
// Fake database: library rows, mapping rows, one request row; UPDATEs recorded (and can be made to fail).
class SEAL_DB {
    public static string $sealSTAT = 'STATS', $sealLIB = 'LIB', $sealILLiadMapping = 'MAP';
    public static array $lib = [], $map = [], $req = [], $updates = [];
    public static bool $fail_update = false;
    public static function last_error(): string { return self::$fail_update ? 'Lost connection' : ''; }
    public static function query( string $sql, ...$a ) {
        if ( str_starts_with( $sql, 'UPDATE' ) ) { if ( self::$fail_update ) { return false; } self::$updates[] = $a; return true; }
        if ( str_contains( $sql, 'FROM %t WHERE `loc`=%s' ) ) { return new R( isset( self::$lib[ $a[1] ] ) ? [ self::$lib[ $a[1] ] ] : [] ); }
        if ( str_contains( $sql, '`illiadID`=%s' ) ) { return new R( array_values( array_filter( self::$map, fn( $m ) => $m['LOC'] === $a[1] && $m['illiadID'] === $a[2] ) ) ); }
        if ( str_contains( $sql, 'FROM %t WHERE `LOC`=%s' ) ) { return new R( array_values( array_filter( self::$map, fn( $m ) => $m['LOC'] === $a[1] ) ) ); }
        if ( str_contains( $sql, '`illNUB`=%s' ) ) { return new R( [ self::$req ] ); }
        fwrite( STDERR, "unexpected query: $sql\n" ); return false;
    }
}
class R { public function __construct( public array $rows ) {} public function fetch_assoc() { return array_shift( $this->rows ); } }
class SEAL_Page_Base {}

require dirname( __DIR__ ) . '/includes/class-seal-resend.php';
require dirname( __DIR__ ) . '/includes/pages/class-seal-page-request.php';

$fail = 0;
function t( string $name, bool $ok, string $extra = '' ): void { global $fail; if ( ! $ok ) { $fail++; } echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( $ok ? '' : "  $extra" ) . "\n"; }
function reset_all(): void {
    SEAL_DB::$lib = [
        'LEND' => [ 'loc' => 'LEND', 'Name' => 'Lender College', 'ill_email' => 'ill@lender.example', 'system' => 'X', 'Illiad' => '1', 'IlliadURL' => 'https://illiad.example/api', 'IlliadDATE' => '21', 'APIkey' => 'plainkey' ],
        'BORR' => [ 'loc' => 'BORR', 'Name' => 'Borrower Library', 'ill_email' => 'ill@borrower.example', 'address2' => '1 Main St', 'address3' => 'Town, NY 12345' ],
    ];
    SEAL_DB::$map = [ [ 'LOC' => 'BORR', 'illiadID' => 'OTHER', 'illiadADDnumb' => '111', 'illiadLIBSymbol' => 'WRONG' ],
                      [ 'LOC' => 'BORR', 'illiadID' => 'LEND',  'illiadADDnumb' => '222', 'illiadLIBSymbol' => 'RIGHT' ] ];
    SEAL_DB::$req = [ 'illNUB' => '2026-100', 'Destination' => 'LEND', 'Requester LOC' => 'BORR', 'Title' => 'A Book', 'Author' => 'Someone', 'IlliadTransID' => '', 'article' => '' ];
    SEAL_DB::$updates = []; SEAL_DB::$fail_update = false; SEAL_Functions::$alerts = []; $GLOBALS['posted'] = null; $GLOBALS['opt'] = [];
}
$send = static fn(): array => SEAL_Resend::send_to_illiad( SEAL_DB::$req );

echo "=== library record ===\n";
reset_all(); SEAL_DB::$lib['LEND']['IlliadURL'] = '';
$r = $send();
t( 'ILLiad library without URL: "incomplete", nothing posted', $r['code'] === 'incomplete' && str_contains( $r['msg'], 'no ILLiad URL' ) && $GLOBALS['posted'] === null, json_encode( $r ) );
reset_all(); SEAL_DB::$lib['LEND']['APIkey'] = 'ik1:garbage';
$r = $send();
t( 'API key that cannot be decrypted: "no_key", nothing posted', $r['code'] === 'no_key' && $GLOBALS['posted'] === null, json_encode( $r ) );
reset_all(); SEAL_DB::$lib['LEND']['Illiad'] = '0';
t( 'library not using ILLiad: "not_illiad"', $send()['code'] === 'not_illiad' );

echo "=== transaction number ===\n";
reset_all(); $GLOBALS['illiad'] = answer( [ 'Message' => 'The request is invalid.' ], 400 );
$r = $send();
t( 'no TransactionNumber: failure, nothing saved, response kept', ! $r['ok'] && $r['code'] === 'no_txn' && ! SEAL_DB::$updates && str_contains( $r['detail'], 'invalid' ), json_encode( $r ) );
reset_all(); $GLOBALS['illiad'] = answer( [ 'TransactionNumber' => '123', 'TransactionStatus' => 'Awaiting Lending Request Processing' ] );
$r = $send();
t( 'short number ("123"): failure and NOT saved', ! $r['ok'] && ! SEAL_DB::$updates && str_contains( $r['msg'], '"123"' ), json_encode( $r ) );
reset_all(); $GLOBALS['illiad'] = new WP_Error( 'http', 'Connection timed out' );
t( 'connection error: "http_error"', $send()['code'] === 'http_error' );
reset_all(); $GLOBALS['illiad'] = answer( [ 'TransactionNumber' => 987654, 'TransactionStatus' => 'Awaiting Lending Request Processing' ] );
$r = $send();
t( 'valid number: saved', $r['ok'] && SEAL_DB::$updates && SEAL_DB::$updates[0][2] === '987654', json_encode( SEAL_DB::$updates ) );
t( 'API key sent in the header, not in the payload', $GLOBALS['posted']['headers']['ApiKey'] === 'plainkey' && ! str_contains( $r['sent'], 'plainkey' ) );
reset_all(); SEAL_DB::$fail_update = true; $GLOBALS['illiad'] = answer( [ 'TransactionNumber' => '987654' ] );
$r = $send();
t( 'save fails: reported with the transaction number', ! $r['ok'] && $r['code'] === 'db' && str_contains( $r['msg'], '987654' ), json_encode( $r ) );

echo "=== borrower → lender mapping ===\n";
reset_all(); $GLOBALS['illiad'] = answer( [ 'TransactionNumber' => '987654' ] );
$send();
$p = json_decode( $GLOBALS['posted']['body'], true );
t( 'uses the row for this lender, not the borrower\'s first row', $p['LenderAddressNumber'] === '222' && $p['LendingLibrary'] === 'RIGHT', json_encode( $p ) );
reset_all(); SEAL_DB::$map = [ SEAL_DB::$map[0] ]; $GLOBALS['illiad'] = answer( [ 'TransactionNumber' => '987654' ] );
$r = $send();
t( 'no row for the pair: sent with the first row, note returned', $r['ok'] && $r['mapping_note'] !== '', json_encode( $r ) );

echo "=== request page alert ===\n";
$page = ( new ReflectionClass( 'SEAL_Page_request' ) )->newInstanceWithoutConstructor();
$sub  = new ReflectionMethod( 'SEAL_Page_request', 'submit_to_illiad' );
reset_all(); $GLOBALS['illiad'] = answer( [ 'TransactionNumber' => '987654' ] );
$sub->invoke( $page, '2026-100' );
t( 'success: no alert', ! SEAL_Functions::$alerts );
reset_all(); $GLOBALS['illiad'] = answer( [ 'Message' => 'Bad address <b>number</b>' ], 400 );
$sub->invoke( $page, '2026-100' );
$a = SEAL_Functions::$alerts[0] ?? [ '', '' ];
t( 'failure: alert with ILLiad\'s response (escaped), the payload and a resend link', str_starts_with( $a[0], 'ILLiad Failure' ) && str_contains( $a[1], 'Bad address &lt;b&gt;' )
    && str_contains( $a[1], 'LenderAddressNumber' ) && str_contains( $a[1], '/resend?num=2026-100' ) && ! str_contains( $a[1], 'plainkey' ), $a[1] );
t( 'failure: written to the resend log', ( $GLOBALS['opt']['seal_resend_log'][0]['ok'] ?? true ) === false );
reset_all(); SEAL_DB::$lib['LEND']['APIkey'] = '';
$sub->invoke( $page, '2026-100' );
t( 'incomplete library record: alert', str_contains( SEAL_Functions::$alerts[0][1] ?? '', 'no API key' ) );
reset_all(); SEAL_DB::$map = [ SEAL_DB::$map[0] ]; $GLOBALS['illiad'] = answer( [ 'TransactionNumber' => '987654' ] );
$sub->invoke( $page, '2026-100' );
t( 'fallback mapping: "mapping missing" alert, no resend button', str_starts_with( SEAL_Functions::$alerts[0][0] ?? '', 'ILLiad mapping missing' ) && ! str_contains( SEAL_Functions::$alerts[0][1], '/resend' ) );
t( 'valid_txn()', SEAL_Resend::valid_txn( '1234' ) && ! SEAL_Resend::valid_txn( '123' ) && ! SEAL_Resend::valid_txn( '12a45' ) && ! SEAL_Resend::valid_txn( '' ) );

echo $fail ? "\nFAILURES: $fail\n" : "\nAll passed.\n";
exit( $fail ? 1 : 0 );
