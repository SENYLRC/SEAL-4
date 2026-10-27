<?php
/**
 * SEAL_Secret — encryption for secrets kept in the ILL database (today: each ILLiad library's API key, Library-Data.APIkey).
 *
 * The key lives outside the database: `define( 'ILL_DATA_KEY', '<base64 of 32 random bytes>' );` in wp-config.php, and the
 * same value as `$illDataKey = '…';` in the cron scripts' ill_db.inc (they read the keys too). A database login, dump or
 * backup then shows only ciphertext. Lose the key and the stored ILLiad keys can't be read: keep a copy somewhere safe.
 * Make one with:  php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'
 *
 * Format: "ik1:" + base64(nonce . secretbox) with libsodium (XSalsa20-Poly1305), or "ik2:" + base64(iv . tag . ciphertext)
 * with OpenSSL AES-256-GCM when sodium is missing. cron_config.php (ill_secret_open) reads both. A value without a prefix
 * is an old plain-text key and is used as it is, so encryption can be switched on library by library.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class SEAL_Secret {

    /** The 32-byte key from ILL_DATA_KEY, or null when it isn't set or isn't valid. */
    public static function key(): ?string {
        if ( ! defined( 'ILL_DATA_KEY' ) ) { return null; }
        $k = base64_decode( (string) ILL_DATA_KEY, true );
        return ( $k !== false && strlen( $k ) === 32 ) ? $k : null;
    }

    /** 'ok', 'missing' or 'invalid' — for the Database screen. */
    public static function key_status(): string {
        if ( ! defined( 'ILL_DATA_KEY' ) ) { return 'missing'; }
        return self::key() ? 'ok' : 'invalid';
    }

    public static function is_encrypted( string $v ): bool {
        return str_starts_with( $v, 'ik1:' ) || str_starts_with( $v, 'ik2:' );
    }

    /** Encrypts a value. @throws RuntimeException when there is no key or no crypto extension. */
    public static function seal( string $plain ): string {
        if ( $plain === '' ) { return ''; }
        $key = self::key();
        if ( ! $key ) { throw new RuntimeException( 'ILL_DATA_KEY is not set in wp-config.php.' ); }
        if ( function_exists( 'sodium_crypto_secretbox' ) ) {
            $nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
            return 'ik1:' . base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, $key ) );
        }
        if ( function_exists( 'openssl_encrypt' ) ) {
            $iv = random_bytes( 12 ); $tag = '';
            $ct = openssl_encrypt( $plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16 );
            if ( $ct === false ) { throw new RuntimeException( 'OpenSSL encryption failed.' ); }
            return 'ik2:' . base64_encode( $iv . $tag . $ct );
        }
        throw new RuntimeException( 'Neither the sodium nor the openssl PHP extension is available.' );
    }

    /** The plain value: decrypted, or unchanged when it is an old plain-text value. '' when it can't be decrypted. */
    public static function open( string $v ): string {
        if ( ! self::is_encrypted( $v ) ) { return $v; }
        $key = self::key();
        $raw = base64_decode( substr( $v, 4 ), true );
        if ( ! $key || $raw === false ) { error_log( 'ILL: an encrypted ILLiad key could not be read (ILL_DATA_KEY missing or wrong).' ); return ''; }
        if ( str_starts_with( $v, 'ik1:' ) && function_exists( 'sodium_crypto_secretbox_open' ) && strlen( $raw ) > SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
            $p = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), $key );
            if ( $p !== false ) { return $p; }
        } elseif ( str_starts_with( $v, 'ik2:' ) && strlen( $raw ) > 28 ) {
            $p = openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ) );
            if ( $p !== false ) { return $p; }
        }
        error_log( 'ILL: an encrypted ILLiad key could not be decrypted (wrong ILL_DATA_KEY, or the value was changed).' );
        return '';
    }

    /** Characters an encrypted value of a key this long needs (to check the column is wide enough). */
    public static function sealed_length( int $plain_len ): int {
        return 4 + (int) ( ceil( ( 40 + $plain_len ) / 3 ) * 4 );   // prefix + base64(24-byte nonce + 16-byte tag + text)
    }

    /** Width of Library-Data.APIkey: characters, 0 = unlimited (TEXT), -1 = unknown / no such column. */
    public static function column_width(): int {
        $cols = SEAL_DB::columns( SEAL_DB::$sealLIB );
        $t    = $cols['apikey']['type'] ?? '';
        if ( $t === '' ) { return -1; }
        if ( preg_match( '/^(var)?char\((\d+)\)/', $t, $m ) ) { return (int) $m[2]; }
        return str_contains( $t, 'text' ) || str_contains( $t, 'blob' ) ? 0 : -1;
    }

    /** Counts of stored ILLiad keys: [ 'total', 'encrypted', 'plain', 'unreadable' ]. */
    public static function key_counts(): array {
        $out = [ 'total' => 0, 'encrypted' => 0, 'plain' => 0, 'unreadable' => 0 ];
        $q = SEAL_DB::query( "SELECT `APIkey` FROM %t WHERE `APIkey` IS NOT NULL AND `APIkey`<>''", SEAL_DB::$sealLIB );
        while ( $q && ( $r = $q->fetch_assoc() ) ) {
            $v = (string) $r['APIkey'];
            $out['total']++;
            if ( ! self::is_encrypted( $v ) ) { $out['plain']++; continue; }
            $out['encrypted']++;
            if ( self::key() && self::open( $v ) === '' ) { $out['unreadable']++; }
        }
        return $out;
    }

    /**
     * Encrypts every plain-text ILLiad key (Database screen button). Each value is checked to decrypt back to the original
     * before it is written; a key that wouldn't fit the column is skipped. @return array{done:int,skipped:array}
     */
    public static function encrypt_all(): array {
        $done = 0; $skipped = [];
        $width = self::column_width();
        $q = SEAL_DB::query( "SELECT `recnum`, `loc`, `APIkey` FROM %t WHERE `APIkey` IS NOT NULL AND `APIkey`<>''", SEAL_DB::$sealLIB );
        while ( $q && ( $r = $q->fetch_assoc() ) ) {
            $plain = (string) $r['APIkey'];
            if ( self::is_encrypted( $plain ) ) { continue; }
            $sealed = self::seal( $plain );
            if ( self::open( $sealed ) !== $plain ) { $skipped[] = $r['loc'] . ' (check failed)'; continue; }
            if ( $width > 0 && strlen( $sealed ) > $width ) { $skipped[] = $r['loc'] . ' (column too narrow)'; continue; }
            if ( SEAL_DB::query( 'UPDATE %t SET `APIkey`=%s WHERE `recnum`=%d LIMIT 1', SEAL_DB::$sealLIB, $sealed, (int) $r['recnum'] ) ) { $done++; }
            else { $skipped[] = $r['loc'] . ' (save failed)'; }
        }
        return [ 'done' => $done, 'skipped' => $skipped ];
    }
}
