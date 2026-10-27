<?php
/**
 * Tests SEAL_WebAuthn's origin check with real P-256 signatures (a software authenticator): a correctly signed
 * assertion is accepted only from the site's exact origin, not from a sub-domain, another port or a cross-origin
 * frame; registration is refused from a sub-domain too. Also shows the bundled library alone accepts the sub-domain.
 *
 *   php tests/test-webauthn-origin.php
 */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 403 ); exit; }
define( 'ABSPATH', __DIR__ . '/' );
define( 'SEAL_ILL_PLUGIN_DIR', dirname( __DIR__ ) . '/' );

class WP_User { public string $user_login = 'pat'; public string $user_email = 'pat@library.org'; public string $display_name = 'Pat'; public function __construct( public int $ID = 7 ) {} }
$GLOBALS['meta'] = [];
$GLOBALS['home'] = 'https://ill.library.org/';
function get_user_meta( $uid, $k, $s ) { return $GLOBALS['meta'][ $uid ][ $k ] ?? ''; }
function update_user_meta( $uid, $k, $v ): void { $GLOBALS['meta'][ $uid ][ $k ] = $v; }
function delete_user_meta( $uid, $k ): void { unset( $GLOBALS['meta'][ $uid ][ $k ] ); }
function home_url( $p = '' ): string { return rtrim( $GLOBALS['home'], '/' ) . $p; }
function site_url( $p = '' ): string { return home_url( $p ); }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function seal_config( $k ): string { return 'Town ILL'; }

require dirname( __DIR__ ) . '/includes/class-seal-webauthn.php';

$fail = 0;
function t( string $name, bool $ok, string $extra = '' ): void { global $fail; if ( ! $ok ) { $fail++; } echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( $ok ? '' : "  $extra" ) . "\n"; }
$b64u = static fn( string $b ): string => rtrim( strtr( base64_encode( $b ), '+/', '-_' ), '=' );

// Software authenticator: one P-256 key registered for user 7.
$priv = openssl_pkey_new( [ 'curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC ] );
$pem  = openssl_pkey_get_details( $priv )['key'];
$cred = random_bytes( 16 );
$user = new WP_User();
update_user_meta( 7, SEAL_WebAuthn::M_KEYS, [ [ 'id' => 'k1', 'name' => 'Key', 'kind' => 'key', 'cred' => base64_encode( $cred ), 'pem' => $pem, 'count' => 5 ] ] );
$counter = 5;

/** Asks the wrapper for a sign-in challenge, signs it as the key would from $origin, and returns the parts. */
$assert = static function ( string $origin, array $extra = [] ) use ( $user, $priv, $b64u, &$counter ): array {
    SEAL_WebAuthn::login_options( $user );
    $chal = base64_decode( get_user_meta( 7, SEAL_WebAuthn::M_CHAL, true )['c'] );
    $cdj  = json_encode( [ 'type' => 'webauthn.get', 'challenge' => $b64u( $chal ), 'origin' => $origin ] + $extra );
    $auth = hash( 'sha256', 'ill.library.org', true ) . chr( 0x01 ) . pack( 'N', ++$counter );   // rpIdHash, user present, counter
    openssl_sign( $auth . hash( 'sha256', $cdj, true ), $sig, $priv, OPENSSL_ALGO_SHA256 );
    return [ $cdj, $auth, $sig ];
};
$login = static function ( string $origin, array $extra = [] ) use ( $assert, $user, $cred ): bool {
    [ $cdj, $auth, $sig ] = $assert( $origin, $extra );
    return SEAL_WebAuthn::verify_login( $user, $cred, $cdj, $auth, $sig );
};

echo "=== sign-in ===\n";
t( 'allowed origins: just the site', SEAL_WebAuthn::allowed_origins() === [ 'https://ill.library.org' ], json_encode( SEAL_WebAuthn::allowed_origins() ) );
t( 'correctly signed, site origin: accepted', $login( 'https://ill.library.org' ) );
t( 'correctly signed, sub-domain origin: refused', ! $login( 'https://evil.ill.library.org' ) );
t( 'correctly signed, other port: refused', ! $login( 'https://ill.library.org:8443' ) );
t( 'correctly signed, look-alike host: refused', ! $login( 'https://evilill.library.org' ) );
t( 'correctly signed, inside another site\'s frame: refused', ! $login( 'https://ill.library.org', [ 'crossOrigin' => true ] ) );
t( 'site origin still accepted afterwards (counter rising)', $login( 'https://ill.library.org' ) );

// The finding itself: the bundled library alone accepts the sub-domain.
[ $cdj, $auth, $sig ] = $assert( 'https://evil.ill.library.org' );
$chal = base64_decode( get_user_meta( 7, SEAL_WebAuthn::M_CHAL, true )['c'] );
$lib  = new lbuchs\WebAuthn\WebAuthn( 'Town ILL', 'ill.library.org', null, false );
try { $lib->processGet( $cdj, $auth, $sig, $pem, new lbuchs\WebAuthn\Binary\ByteBuffer( $chal ), $counter - 1, false, true ); $libok = true; } catch ( Throwable $e ) { $libok = false; }
t( 'bundled library alone accepts the sub-domain (why the wrapper checks)', $libok );

echo "=== site address variants ===\n";
$GLOBALS['home'] = 'https://ill.library.org:8443/wp/';
t( 'non-default port kept', SEAL_WebAuthn::allowed_origins() === [ 'https://ill.library.org:8443' ] );
$GLOBALS['home'] = 'http://ill.library.org/';
t( 'http:// site address means its https origin', SEAL_WebAuthn::allowed_origins() === [ 'https://ill.library.org' ] );
$GLOBALS['home'] = 'https://ill.library.org/';

echo "=== registration ===\n";
SEAL_WebAuthn::registration_options( $user, 'key' );
$chal = base64_decode( get_user_meta( 7, SEAL_WebAuthn::M_CHAL, true )['c'] );
$cdj  = json_encode( [ 'type' => 'webauthn.create', 'challenge' => $b64u( $chal ), 'origin' => 'https://evil.ill.library.org' ] );
try { SEAL_WebAuthn::finish_registration( $user, 'Evil', 'key', $cdj, '' ); $msg = ''; } catch ( RuntimeException $e ) { $msg = $e->getMessage(); }
t( 'registration from a sub-domain: refused before the attestation is read', str_contains( $msg, 'this site\'s address' ), $msg );
t( 'no key added', count( SEAL_WebAuthn::keys( 7 ) ) === 1 );

echo $fail ? "\nFAILURES: $fail\n" : "\nAll passed.\n";
exit( $fail ? 1 : 0 );
