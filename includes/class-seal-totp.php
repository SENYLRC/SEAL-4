<?php
/**
 * SEAL_TOTP — RFC 6238 time-based one-time passwords (SHA-1, 6 digits, 30 s),
 * compatible with Google Authenticator, Microsoft Authenticator, Authy, 1Password, etc.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class SEAL_TOTP {

    private const B32    = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    private const PERIOD = 30;

    /** A new random two-factor secret, Base32-encoded (20 random bytes = 160 bits, as RFC 4226 recommends). */
    public static function generate_secret( int $bytes = 20 ): string {
        return self::b32_encode( random_bytes( $bytes ) );
    }

    /** Base32 (RFC 4648, no padding) encoding, the format authenticator apps expect. */
    public static function b32_encode( string $bin ): string {
        $bits = '';
        foreach ( str_split( $bin ) as $c ) {
            $bits .= str_pad( decbin( ord( $c ) ), 8, '0', STR_PAD_LEFT );
        }
        $out = '';
        foreach ( str_split( $bits, 5 ) as $chunk ) {
            $out .= self::B32[ bindec( str_pad( $chunk, 5, '0', STR_PAD_RIGHT ) ) ];
        }
        return $out;
    }

    /** Decodes Base32, ignoring spaces, dashes and other characters outside the alphabet. */
    public static function b32_decode( string $s ): string {
        $s    = strtoupper( preg_replace( '/[^A-Za-z2-7]/', '', $s ) );
        $bits = '';
        foreach ( str_split( $s ) as $c ) {
            $bits .= str_pad( decbin( strpos( self::B32, $c ) ), 5, '0', STR_PAD_LEFT );
        }
        $out = '';
        foreach ( str_split( $bits, 8 ) as $byte ) {
            if ( strlen( $byte ) === 8 ) { $out .= chr( bindec( $byte ) ); }
        }
        return $out;
    }

    /**
     * The RFC 4226 one-time code for $counter: HMAC-SHA1, dynamic truncation, $digits digits with leading
     * zeros.
     */
    public static function hotp( string $key_bin, int $counter, int $digits = 6 ): string {
        $h   = hash_hmac( 'sha1', pack( 'J', $counter ), $key_bin, true );
        $o   = ord( $h[19] ) & 0x0f;
        $bin = ( ( ord( $h[ $o ] ) & 0x7f ) << 24 ) | ( ord( $h[ $o + 1 ] ) << 16 )
             | ( ord( $h[ $o + 2 ] ) << 8 )          |   ord( $h[ $o + 3 ] );
        return str_pad( (string) ( $bin % ( 10 ** $digits ) ), $digits, '0', STR_PAD_LEFT );
    }

    /**
     * Verify a 6-digit code. Accepts ±$window time steps for clock drift and
     * rejects any step at or before $last_step (replay protection).
     * On success $matched_step holds the accepted step.
     */
    public static function verify( string $secret_b32, string $code, int $last_step = 0, ?int &$matched_step = null, int $window = 1, ?int $time = null ): bool {
        $code = preg_replace( '/\s+/', '', $code );
        if ( ! preg_match( '/^\d{6}$/', $code ) ) { return false; }

        $key = self::b32_decode( $secret_b32 );
        if ( $key === '' ) { return false; }

        $now   = intdiv( $time ?? time(), self::PERIOD );
        $found = false;
        for ( $i = -$window; $i <= $window; $i++ ) {
            $step = $now + $i;
            if ( $step > $last_step && hash_equals( self::hotp( $key, $step ), $code ) ) {
                $found        = true;
                $matched_step = $step;
            }
        }
        return $found;
    }

    /**
     * The otpauth:// address encoded in the set-up QR code: account, issuer, SHA1, 6 digits, PERIOD seconds.
     */
    public static function uri( string $secret, string $account, string $issuer ): string {
        return 'otpauth://totp/' . rawurlencode( $issuer . ':' . $account )
            . '?secret=' . rawurlencode( $secret )
            . '&issuer=' . rawurlencode( $issuer )
            . '&algorithm=SHA1&digits=6&period=' . self::PERIOD;
    }
}
