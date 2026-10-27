<?php
/**
 * SEAL_WebAuthn — security keys (YubiKey and other FIDO2 keys) and passkeys as a second sign-in step.
 *
 * Ported from Argus (docs/yubikey-passkey-two-step-guide.md in 2026_09_se_exp_request). The protocol checks
 * (origin, challenge, relying-party hash, signature, signature counter) come from the bundled library
 * lbuchs/webauthn v2.2.0 (includes/lib/WebAuthn, MIT). This class only stores keys and challenges.
 *
 * WordPress differences from Argus:
 *   - Keys live in user meta (seal_2fa_keys), not a table: [ {id, name, kind, cred (base64), pem, count, aaguid,
 *     created, used}, ... ]. Nothing in it is secret — the private key never leaves the YubiKey.
 *   - WordPress has no PHP session, so each challenge is kept in user meta (seal_2fa_wa_chal): single-use,
 *     120 seconds, bound to the user and to "reg" or "login".
 *
 * Origins: the library accepts any https host ending in the relying-party id; SEAL_WebAuthn::origin_ok() also
 * requires the exact site origin (scheme, host and port) for registration and sign-in.
 *
 * Decisions kept from Argus: touch only (userVerification "discouraged", no key PIN — the password is already
 * the first factor); user presence required; resident keys not required (second factor, not passwordless);
 * any FIDO2 maker accepted (attestation roots not checked); the stored counter is passed so a cloned key with a
 * counter that doesn't rise is refused. The relying-party id is this site's host name, so a key registered on
 * a test site does not work on the live site (and a phishing site can't use it).
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

use lbuchs\WebAuthn\WebAuthn;
use lbuchs\WebAuthn\WebAuthnException;

class SEAL_WebAuthn {

    const M_KEYS = 'seal_2fa_keys';
    const M_CHAL = 'seal_2fa_wa_chal';
    const CHALLENGE_TTL = 120;

    /** Loads the bundled library on first use (PSR-4: lbuchs\WebAuthn\X → includes/lib/WebAuthn/X.php). */
    private static function load(): void {
        static $done = false;
        if ( $done ) { return; }
        $done = true;
        spl_autoload_register( static function ( string $class ): void {
            $prefix = 'lbuchs\\WebAuthn\\';
            if ( strncmp( $class, $prefix, strlen( $prefix ) ) !== 0 ) { return; }
            $file = SEAL_ILL_PLUGIN_DIR . 'includes/lib/WebAuthn/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
            if ( is_file( $file ) ) { require_once $file; }
        } );
    }

    /** False when the server can't run WebAuthn (PHP's OpenSSL extension is missing). */
    public static function available(): bool {
        return function_exists( 'openssl_verify' );
    }

    /** This site's host name: the relying-party id every key is bound to. */
    public static function rp_id(): string {
        return (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
    }

    /**
     * The browser origins allowed to use this site's keys: the site's own address (home and site URL) as
     * https://host[:port]. Checked exactly, because the bundled library only checks that the host ends with the
     * relying-party id (so any sub-domain, or any port, would pass).
     */
    public static function allowed_origins(): array {
        $out = [];
        foreach ( [ home_url( '/' ), site_url( '/' ) ] as $url ) {
            $p    = wp_parse_url( $url );
            $host = strtolower( trim( (string) ( $p['host'] ?? '' ), '.' ) );
            if ( $host === '' ) { continue; }
            // WebAuthn needs https except on localhost, so an http:// site address means its https:// origin.
            $scheme = $host === 'localhost' ? strtolower( (string) ( $p['scheme'] ?? 'https' ) ) : 'https';
            $port   = isset( $p['port'] ) && (int) $p['port'] !== ( $scheme === 'https' ? 443 : 80 ) ? ':' . (int) $p['port'] : '';
            $out[ $scheme . '://' . $host . $port ] = true;
        }
        return array_keys( $out );
    }

    /** True when the browser's clientDataJSON comes from an allowed origin, top level (not from inside another site's frame). */
    public static function origin_ok( string $client_data_json ): bool {
        $c = json_decode( $client_data_json, true );
        if ( ! is_array( $c ) || ! empty( $c['crossOrigin'] ) ) { return false; }
        $origin = strtolower( (string) ( $c['origin'] ?? '' ) );
        return $origin !== '' && in_array( $origin, self::allowed_origins(), true );
    }

    /** A library instance for this site: relying-party name from the System / Org Short Names, id = this host. */
    private static function wa(): WebAuthn {
        self::load();
        $name = trim( seal_config( 'system_name' ) . ' — ' . seal_config( 'org_name' ), ' —' ) ?: 'ILL';
        return new WebAuthn( $name, self::rp_id(), null, false );
    }

    // ── Stored keys ───────────────────────────────────────────────────────────

    /** @return array[] the user's keys */
    public static function keys( int $uid ): array {
        $k = get_user_meta( $uid, self::M_KEYS, true );
        return is_array( $k ) ? array_values( array_filter( $k, 'is_array' ) ) : [];
    }

    /** True when the user has registered at least one security key or passkey. */
    public static function has_keys( int $uid ): bool {
        return (bool) self::keys( $uid );
    }

    /** Stores the user's key list (deletes the meta row when the list is empty). */
    private static function save_keys( int $uid, array $keys ): void {
        if ( $keys ) { update_user_meta( $uid, self::M_KEYS, array_values( $keys ) ); }
        else { delete_user_meta( $uid, self::M_KEYS ); }
    }

    /** Raw credential ids, for "exclude these" at registration and "allow these" at sign-in. */
    private static function credential_ids( int $uid ): array {
        return array_values( array_filter( array_map( static fn( $k ) => base64_decode( (string) ( $k['cred'] ?? '' ), true ), self::keys( $uid ) ) ) );
    }

    /** Removes one key. @return string the removed key's name, or '' if there was none with that id. */
    public static function remove( int $uid, string $id ): string {
        $keys = self::keys( $uid );
        foreach ( $keys as $i => $k ) {
            if ( hash_equals( (string) ( $k['id'] ?? '' ), $id ) ) {
                unset( $keys[ $i ] );
                self::save_keys( $uid, $keys );
                return (string) ( $k['name'] ?? '' );
            }
        }
        return '';
    }

    /** Removes every key and any pending challenge (two-factor turned off, or the admin reset). */
    public static function remove_all( int $uid ): void {
        delete_user_meta( $uid, self::M_KEYS );
        delete_user_meta( $uid, self::M_CHAL );
    }

    // ── Challenges: single-use, short-lived, bound to the user ───────────────

    /** Keeps the challenge the library just made, with its purpose ("reg" or "login") and time, for take(). */
    private static function remember( string $kind, WebAuthn $wa, int $uid ): void {
        update_user_meta( $uid, self::M_CHAL, [ 'kind' => $kind, 'c' => base64_encode( $wa->getChallenge()->getBinaryString() ), 'at' => time() ] );
    }

    /** Returns the challenge and deletes it (it can be used once). @throws RuntimeException when missing, stale or the wrong kind. */
    private static function take( string $kind, int $uid ): string {
        $c = get_user_meta( $uid, self::M_CHAL, true );
        delete_user_meta( $uid, self::M_CHAL );
        if ( ! is_array( $c ) || ( $c['kind'] ?? '' ) !== $kind || time() - (int) ( $c['at'] ?? 0 ) > self::CHALLENGE_TTL ) {
            throw new RuntimeException( 'That took too long or the page was out of date. Please try again.' );
        }
        return (string) base64_decode( (string) $c['c'], true );
    }

    // ── Registration ──────────────────────────────────────────────────────────

    /**
     * Browser options for adding a key. $kind 'key' = cross-platform (YubiKey), 'passkey' = this computer or phone
     * (Windows Hello, Touch ID, Android). Keys already registered are excluded.
     */
    public static function registration_options( WP_User $user, string $kind ): object {
        $wa   = self::wa();
        $args = $wa->getCreateArgs(
            pack( 'N', (int) $user->ID ),
            (string) ( $user->user_email ?: $user->user_login ),
            (string) ( $user->display_name ?: $user->user_login ),
            60,                                   // seconds
            false,                                // resident key not required
            'discouraged',                        // touch only: don't make a PIN-protected key ask for its PIN
            $kind === 'passkey' ? false : true,   // false = platform, true = cross-platform
            self::credential_ids( (int) $user->ID )
        );
        self::remember( 'reg', $wa, (int) $user->ID );
        return $args;
    }

    /** Verifies the browser's response and stores the key. @throws RuntimeException with a message for the person. */
    public static function finish_registration( WP_User $user, string $name, string $kind, string $client_data_json, string $attestation ): array {
        $challenge = self::take( 'reg', (int) $user->ID );
        if ( ! self::origin_ok( $client_data_json ) ) {
            throw new RuntimeException( 'That security key could not be added: the request did not come from this site\'s address.' );
        }
        try {
            // requireUserVerification=false, requireUserPresent=true, failIfRootMismatch=false
            $data = self::wa()->processCreate( $client_data_json, $attestation, $challenge, false, true, false );
        } catch ( WebAuthnException $e ) {
            throw new RuntimeException( 'That security key could not be added: ' . $e->getMessage() );
        }
        $cred = base64_encode( (string) $data->credentialId );
        foreach ( self::keys( (int) $user->ID ) as $k ) {
            if ( hash_equals( (string) ( $k['cred'] ?? '' ), $cred ) ) { throw new RuntimeException( 'That key is already registered.' ); }
        }
        $key = [
            'id'      => bin2hex( random_bytes( 8 ) ),
            'name'    => $name !== '' ? mb_substr( $name, 0, 80 ) : ( $kind === 'passkey' ? 'Passkey' : 'Security key' ),
            'kind'    => $kind === 'passkey' ? 'passkey' : 'key',
            'cred'    => $cred,
            'pem'     => (string) $data->credentialPublicKey,
            'count'   => (int) $data->signatureCounter,
            'aaguid'  => bin2hex( (string) $data->AAGUID ),
            'created' => time(),
            'used'    => 0,
        ];
        $keys   = self::keys( (int) $user->ID );
        $keys[] = $key;
        self::save_keys( (int) $user->ID, $keys );
        return $key;
    }

    // ── Sign-in ───────────────────────────────────────────────────────────────

    /** Browser options for the second sign-in step: only this user's keys are allowed. */
    public static function login_options( WP_User $user ): object {
        $wa   = self::wa();
        $args = $wa->getGetArgs( self::credential_ids( (int) $user->ID ), 60, true, true, true, true, true, 'discouraged' );
        self::remember( 'login', $wa, (int) $user->ID );
        return $args;
    }

    /** True when the response is a valid touch of one of this user's keys (and records its counter). */
    public static function verify_login( WP_User $user, string $credential_id, string $client_data_json, string $authenticator_data, string $signature ): bool {
        try {
            $challenge = self::take( 'login', (int) $user->ID );
        } catch ( RuntimeException $e ) {
            return false;
        }
        if ( ! self::origin_ok( $client_data_json ) ) { return false; }   // before the library's own (looser) origin check
        $keys = self::keys( (int) $user->ID );
        $cred = base64_encode( $credential_id );
        foreach ( $keys as $i => $k ) {
            if ( ! hash_equals( (string) ( $k['cred'] ?? '' ), $cred ) ) { continue; }
            $wa = self::wa();
            try {
                $wa->processGet( $client_data_json, $authenticator_data, $signature, (string) $k['pem'], $challenge, (int) $k['count'], false, true );
            } catch ( WebAuthnException $e ) {
                return false;
            }
            $keys[ $i ]['count'] = (int) $wa->getSignatureCounter();
            $keys[ $i ]['used']  = time();
            self::save_keys( (int) $user->ID, $keys );
            return true;
        }
        return false;
    }
}
