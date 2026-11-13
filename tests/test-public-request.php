<?php
/**
 * Tests the public "Request a Book" form's checks (SEAL_Module_PublicRequest) with WordPress stubs: required
 * fields, "email or phone", email / phone / card formats, a pickup library that must be on the list, and the
 * optional note (256 characters at most), which libraries opted-in library systems offer (never suspended ones), and the signed "form shown at" token (too fast, forged, malformed).
 *
 *   php tests/test-public-request.php
 */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 403 ); exit; }
define( 'ABSPATH', __DIR__ . '/' );
define( 'HOUR_IN_SECONDS', 3600 );

function sanitize_text_field( $s ): string { return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $s ) ) ); }
function sanitize_textarea_field( $s ): string { return trim( strip_tags( (string) $s ) ); }
function is_email( $e ) { return filter_var( $e, FILTER_VALIDATE_EMAIL ) ? $e : false; }
$GLOBALS['cfg'] = [];
function seal_config( $k ): string { return $GLOBALS['cfg'][ $k ] ?? ''; }
function wp_hash( $data, $scheme = 'auth' ): string { return hash_hmac( 'md5', $data, 'test-salt-' . $scheme ); }

require dirname( __DIR__ ) . '/includes/modules/class-seal-module-public-request.php';

$fail = 0;
function t( string $name, bool $ok, string $extra = '' ): void { global $fail; if ( ! $ok ) { $fail++; } echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( $ok ? '' : "  $extra" ) . "\n"; }

$libs = [ 12 => [ 'name' => 'Town Library', 'system' => 'SE', 'email' => 'ill@town.example' ] ];
$good = [ 'seal_pr_title' => 'The Hobbit', 'seal_pr_email' => 'pat@example.com', 'seal_pr_phone' => '',
          'seal_pr_card' => '21234000123456', 'seal_pr_library' => '12' ];
$v = static fn( array $over ) => SEAL_Module_PublicRequest::validate( $over + $good, $libs );

[ $e, $c ] = $v( [] );
t( 'complete request: no errors', $e === [], json_encode( $e ) );
t( 'library id comes back as an integer', $c['library'] === 12 );
[ $e ] = $v( [ 'seal_pr_email' => '', 'seal_pr_phone' => '(555) 555-0100' ] );
t( 'phone only is enough', $e === [], json_encode( $e ) );
[ $e ] = $v( [ 'seal_pr_phone' => '555-555-0100 ext 12' ] );
t( 'phone with extension accepted', $e === [], json_encode( $e ) );

[ $e ] = $v( [ 'seal_pr_title' => '   ' ] );
t( 'blank title refused', isset( $e['title'] ) );
[ $e ] = $v( [ 'seal_pr_title' => str_repeat( 'a', 256 ) ] );
t( 'title over 255 characters refused', isset( $e['title'] ) );
[ $e ] = $v( [ 'seal_pr_email' => '', 'seal_pr_phone' => '' ] );
t( 'neither email nor phone: contact error only', array_keys( $e ) === [ 'contact' ], json_encode( $e ) );
[ $e ] = $v( [ 'seal_pr_email' => 'not-an-email' ] );
t( 'bad email refused', isset( $e['email'] ) && ! isset( $e['contact'] ) );
[ $e ] = $v( [ 'seal_pr_email' => '', 'seal_pr_phone' => '12345' ] );
t( 'too-short phone refused', isset( $e['phone'] ) );
[ $e ] = $v( [ 'seal_pr_email' => '', 'seal_pr_phone' => 'call me' ] );
t( 'phone with letters refused', isset( $e['phone'] ) );
[ $e ] = $v( [ 'seal_pr_card' => '' ] );
t( 'blank card refused', isset( $e['card'] ) );
[ $e ] = $v( [ 'seal_pr_card' => '12<script>' ] );
t( 'card with symbols refused', isset( $e['card'] ) );
[ $e ] = $v( [ 'seal_pr_library' => '99' ] );
t( 'library not on the list refused', isset( $e['library'] ) );
[ $e ] = $v( [ 'seal_pr_library' => '' ] );
t( 'no library chosen refused', isset( $e['library'] ) );
[ $e, $c ] = $v( [ 'seal_pr_title' => "<b>Dune</b>\r\nBcc: x@y.z" ] );
t( 'title is stripped of tags and line breaks', $c['title'] === 'Dune Bcc: x@y.z', $c['title'] );
[ $e, $c ] = $v( [] );
t( 'no note is fine', $e === [] && $c['note'] === '' );
[ $e, $c ] = $v( [ 'seal_pr_note' => "Large print\r\nplease" ] );
t( 'note keeps its line break (as \\n)', $e === [] && $c['note'] === "Large print\nplease", json_encode( $c['note'] ) );
[ $e ] = $v( [ 'seal_pr_note' => str_repeat( 'a', 256 ) ] );
t( 'note of 256 characters accepted', $e === [] );
[ $e ] = $v( [ 'seal_pr_note' => str_repeat( 'a', 257 ) ] );
t( 'note of 257 characters refused', isset( $e['note'] ) );
[ $e ] = $v( [ 'seal_pr_note' => str_repeat( "abc\r\n", 63 ) . 'abcd' ] );   // 319 bytes as sent, 256 characters with \n
t( '256 characters with Windows line breaks accepted (\\r\\n counts as one)', $e === [], json_encode( $e ) );
[ $e ] = $v( [ 'seal_pr_note' => str_repeat( 'é', 256 ) ] );
t( '256 accented characters accepted (counted as characters, not bytes)', $e === [] );

// Opted-in library systems (ILL System → Public Requests).
$all = [ 1 => [ 'name' => 'A', 'system' => 'SE', 'email' => 'a@x.example' ], 2 => [ 'name' => 'B', 'system' => 'rcls', 'email' => 'b@x.example' ],
         3 => [ 'name' => 'C', 'system' => '', 'email' => 'c@x.example' ] ];
t( 'nothing opted in: no libraries', SEAL_Module_PublicRequest::opted_systems() === [] && SEAL_Module_PublicRequest::filter_opted( $all, [] ) === [] );
$GLOBALS['cfg']['pr_systems'] = 'SE, ,RCLS';
t( 'opted codes are lower-cased, blanks dropped', SEAL_Module_PublicRequest::opted_systems() === [ 'se', 'rcls' ], json_encode( SEAL_Module_PublicRequest::opted_systems() ) );
t( 'codes match without regard to case; keys kept', array_keys( SEAL_Module_PublicRequest::filter_opted( $all, SEAL_Module_PublicRequest::opted_systems() ) ) === [ 1, 2 ] );
$GLOBALS['cfg']['pr_systems'] = 'se';
t( 'only the opted system is offered', array_keys( SEAL_Module_PublicRequest::filter_opted( $all, SEAL_Module_PublicRequest::opted_systems() ) ) === [ 1 ] );
$GLOBALS['cfg']['pr_systems'] = ',se,';
t( 'a library with no system is never offered (a blank code is never opted in)', ! isset( SEAL_Module_PublicRequest::filter_opted( $all, SEAL_Module_PublicRequest::opted_systems() )[3] ) );

// Suspended libraries are never offered.
$sus = [ 1 => [ 'name' => 'A', 'system' => 'SE', 'email' => 'a@x.example', 'suspended' => false ],
         4 => [ 'name' => 'D', 'system' => 'SE', 'email' => 'd@x.example', 'suspended' => true ] ];
t( 'suspended library left out, open one kept', array_keys( SEAL_Module_PublicRequest::filter_opted( $sus, [ 'se' ] ) ) === [ 1 ] );
t( "suspend values '1' and 'Yes' mean suspended", SEAL_Module_PublicRequest::is_suspended( '1' ) && SEAL_Module_PublicRequest::is_suspended( 'Yes' ) && SEAL_Module_PublicRequest::is_suspended( 1 ) );
t( "suspend values '0', 'No' and '' mean open", ! SEAL_Module_PublicRequest::is_suspended( '0' ) && ! SEAL_Module_PublicRequest::is_suspended( 'No' ) && ! SEAL_Module_PublicRequest::is_suspended( '' ) );
[ $e ] = SEAL_Module_PublicRequest::validate( [ 'seal_pr_library' => '4' ] + $good, SEAL_Module_PublicRequest::filter_opted( $sus, [ 'se' ] ) );
t( 'submitting a suspended library is refused', isset( $e['library'] ) );

$s = static fn( $v ) => SEAL_Module_PublicRequest::sanitize_systems( $v );
t( 'systems: checkbox array saved as a comma list', $s( [ 'SE', 'MH' ] ) === 'SE,MH', $s( [ 'SE', 'MH' ] ) );
t( 'systems: sanitizing twice (first save) keeps codes separate', $s( $s( [ 'SE', 'MH', 'RCLS' ] ) ) === 'SE,MH,RCLS', $s( $s( [ 'SE', 'MH', 'RCLS' ] ) ) );
t( 'systems: duplicates dropped regardless of case', $s( [ 'SE', 'se', ' MH ' ] ) === 'SE,MH' );
t( 'systems: nothing checked saves empty', $s( null ) === '' && $s( '' ) === '' && $s( [] ) === '' );

$now = 1_800_000_000;
$tok = SEAL_Module_PublicRequest::make_token( $now - 10 );
t( 'token age is the seconds since it was made', SEAL_Module_PublicRequest::token_age( $tok, $now ) === 10 );
t( 'token made just now is age 0 (too fast)', SEAL_Module_PublicRequest::token_age( SEAL_Module_PublicRequest::make_token( $now ), $now ) < SEAL_Module_PublicRequest::MIN_SECONDS );
$forged = ( $now - 3600 ) . substr( $tok, strpos( $tok, '.' ) );
t( 'token with a changed time is rejected', SEAL_Module_PublicRequest::token_age( $forged, $now ) === -1 );
t( 'missing token is rejected', SEAL_Module_PublicRequest::token_age( '', $now ) === -1 );
t( 'malformed token is rejected', SEAL_Module_PublicRequest::token_age( 'abc.def', $now ) === -1 );

echo $fail ? "\n$fail FAILED\n" : "\nAll passed\n";
exit( $fail ? 1 : 0 );
