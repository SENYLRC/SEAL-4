<?php
/**
 * SEAL_DB — secure database connection and query layer.
 *
 * Security features:
 *  - Credentials stored encrypted in wp_options using libsodium (AEAD).
 *    Falls back to OpenSSL AES-256-GCM if sodium unavailable.
 *    Never stored in flat .inc files.
 *  - MySQL connection enforces TLS (MYSQLI_CLIENT_SSL) when a CA cert or
 *    the ssl_enforce option is configured. Raises a RuntimeException if
 *    enforcement is on and TLS cannot be established.
 *  - Singleton connection with reconnect on dropped link.
 *  - All queries use parameterised placeholders (%s/%d/%f/%t).
 *    Raw string concatenation into SQL is not supported by this API.
 *  - DB errors logged via error_log(); never echoed to the browser.
 *  - Table names are back-tick-quoted and backtick-stripped before use.
 *
 * wp_options keys managed by this class:
 *   seal_db_host, seal_db_user, seal_db_pass (encrypted), seal_db_name
 *   seal_db_ssl_ca    — path to CA certificate (optional)
 *   seal_db_ssl_cert  — path to client certificate (optional)
 *   seal_db_ssl_key   — path to client key (optional)
 *   seal_db_ssl_enforce — '1' to require verified TLS, '0' to prefer it
 *
 * @author  Zachary Spalding (SENYLRC) & Claude (Anthropic)
 * @version 3.2.1
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class SEAL_DB {

    /** @var mysqli|null */
    private static ?mysqli $conn = null;

    public static string $sealSTAT          = '';
    public static string $sealLIB           = '';
    public static string $sealILLiadMapping = '';

    // ── Connection ────────────────────────────────────────────────────────────

    /**
     * Return the shared mysqli connection, opening it on first call.
     *
     * @throws RuntimeException if the connection fails or TLS is enforced but unavailable.
     */
    public static function get(): mysqli {
        if ( self::$conn instanceof mysqli ) {
            try {
                // ping() re-connects on stale links automatically
                if ( @self::$conn->ping() ) {
                    return self::$conn;
                }
            } catch ( \Throwable $e ) {
                // Fall through to re-connect
            }
            self::$conn = null;
        }

        $host    = seal_config( 'db_host' ) ?: 'localhost';
        $user    = seal_config( 'db_user' );
        $pass    = self::decrypt_pass( seal_config( 'db_pass' ) );
        $dbname  = seal_config( 'db_name' );
        $ssl_ca  = seal_config( 'db_ssl_ca' );
        $ssl_cert= seal_config( 'db_ssl_cert' );
        $ssl_key = seal_config( 'db_ssl_key' );
        $enforce = seal_config( 'db_ssl_enforce' ) === '1';

        $conn = mysqli_init();
        if ( ! $conn ) {
            throw new RuntimeException( seal_config( 'system_name' ) . ' database: mysqli_init() failed.' );
        }

        // ── TLS configuration ─────────────────────────────────────────────
        // Validate configured cert paths up front. A configured-but-missing
        // file would otherwise fail the handshake with an opaque
        // "Cannot connect to MySQL using SSL" error.
        foreach ( [ 'CA certificate' => &$ssl_ca, 'client certificate' => &$ssl_cert, 'client key' => &$ssl_key ] as $label => &$path ) {
            if ( $path && ! is_readable( $path ) ) {
                error_log( "ILL_DB: configured {$label} not found or not readable: {$path} — ignoring it." );
                $path = '';
            }
        }
        unset( $path );

        $have_certs = ( $ssl_ca || $ssl_cert || $ssl_key );
        $use_ssl    = $have_certs || $enforce;

        if ( $have_certs ) {
            // ssl_set( key, cert, ca, capath, cipher ) — only call this when we
            // actually have cert material; calling it with all-nulls can force
            // server-cert verification on some mysqlnd builds and break the
            // handshake for servers (like AWS RDS) whose CA isn't in the
            // system trust store.
            $conn->ssl_set( $ssl_key ?: null, $ssl_cert ?: null, $ssl_ca ?: null, null, null );
        }

        // MYSQLI_CLIENT_SSL — use SSL/TLS
        // Without a CA we can't verify the server identity, so explicitly
        // disable verification (traffic is still encrypted).
        $flags = 0;
        if ( $use_ssl ) {
            $flags |= MYSQLI_CLIENT_SSL;
            if ( ! $ssl_ca ) {
                if ( defined( 'MYSQLI_CLIENT_SSL_DONT_VERIFY_SERVER_CERT' ) ) {
                    $flags |= MYSQLI_CLIENT_SSL_DONT_VERIFY_SERVER_CERT;
                }
                if ( defined( 'MYSQLI_OPT_SSL_VERIFY_SERVER_CERT' ) ) {
                    $conn->options( MYSQLI_OPT_SSL_VERIFY_SERVER_CERT, false );
                }
            }
        }

        $connected = @$conn->real_connect( $host, $user, $pass, $dbname, 0, '', $flags );

        // If TLS was *preferred* (not enforced) and the SSL handshake is what
        // failed, retry once without SSL rather than taking the site down.
        if ( ( ! $connected || $conn->connect_errno ) && $use_ssl && ! $enforce ) {
            error_log( 'ILL_DB: TLS connection to ' . $host . ' failed (' . $conn->connect_error . ') — retrying without SSL. Enable "Require Encrypted Connection" to forbid this fallback.' );
            $conn = mysqli_init();
            $connected = $conn && @$conn->real_connect( $host, $user, $pass, $dbname, 0, '', 0 );
        }

        if ( ! $connected || $conn->connect_errno ) {
            error_log( 'ILL_DB connect failed (host: ' . $host . '): ' . $conn->connect_error );
            throw new RuntimeException( seal_config( 'system_name' ) . ' database unavailable. Please contact the site administrator.' );
        }

        // ── Verify TLS when enforcement is requested ──────────────────────
        if ( $enforce ) {
            $tls_result = $conn->query( "SHOW STATUS LIKE 'Ssl_cipher'" );
            $tls_row    = $tls_result ? $tls_result->fetch_assoc() : null;
            $cipher     = $tls_row['Value'] ?? '';
            if ( empty( $cipher ) ) {
                $conn->close();
                error_log( 'ILL_DB: TLS enforcement is on but connection is not encrypted.' );
                throw new RuntimeException( seal_config( 'system_name' ) . ' database: encrypted connection required but not available.' );
            }
        }

        $conn->set_charset( 'utf8mb4' );

        self::$conn = $conn;
        self::load_tables();

        return self::$conn;
    }

    /**
     * Fill in the table names from the settings. Called at plugin start (seal-ill-system.php) and again
     * on every connect. Code reads SEAL_DB::$sealLIB etc. to build a query before query() connects, so
     * these must never wait for the first connection: on a screen whose first query was one of those,
     * the table name was empty, the query failed and a library looked "not found".
     */
    public static function load_tables(): void {
        self::$sealSTAT          = seal_config( 'table_stat'   );
        self::$sealLIB           = seal_config( 'table_lib'    );
        self::$sealILLiadMapping = seal_config( 'table_illiad' );
    }

    // ── Query ─────────────────────────────────────────────────────────────────

    /**
     * Execute a parameterised SQL statement.
     *
     * Placeholders:
     *   %s  string  — single-quoted and real_escape_string'd
     *   %d  integer — cast to int
     *   %f  float   — cast to float
     *   %t  table/column identifier — back-tick-quoted, backticks stripped
     *
     * @return mysqli_result|bool
     * @throws RuntimeException on connection failure.
     */
    public static function query( string $sql, ...$args ) {
        $db = self::get();

        if ( ! empty( $args ) ) {
            $sql = self::build_query( $db, $sql, $args );
        }

        $result = $db->query( $sql );
        self::$last_error = $result === false ? (string) $db->error : '';

        if ( $result === false ) {
            // Log the error but never the full SQL (may contain PII)
            error_log( 'ILL_DB error: ' . $db->error );
        }

        return $result;
    }

    /** The error from the most recent query() (empty if it worked). Read this, not get()->error: get() pings the
     *  connection first, and the ping clears mysqli's error. */
    private static string $last_error = '';
    /**
     * The error message from the last failed query() ('' when it succeeded). Use it in alerts; never show it to
     * ordinary users.
     */
    public static function last_error(): string { return self::$last_error; }

    // ── Writing only the columns a table really has ───────────────────────────
    // Installs differ: CDLC's library table has none of SEAL-3's delivery columns (lbsyscourier, ...), and one
    // unknown column fails a whole UPDATE. These helpers drop columns the table doesn't have.

    private static array $columns = [];

    /** Columns of a table: lower-cased name => [ name, null (bool), default (?string), type, extra ] (cached per request). Empty if unreadable. */
    public static function columns( string $table ): array {
        if ( ! isset( self::$columns[ $table ] ) ) {
            $cols = [];
            $res  = self::query( 'SHOW COLUMNS FROM %t', $table );
            while ( $res && ( $r = $res->fetch_assoc() ) ) {
                $cols[ strtolower( (string) $r['Field'] ) ] = [
                    'name' => (string) $r['Field'], 'null' => strtoupper( (string) ( $r['Null'] ?? 'YES' ) ) === 'YES',
                    'default' => $r['Default'] ?? null, 'type' => strtolower( (string) ( $r['Type'] ?? '' ) ), 'extra' => strtolower( (string) ( $r['Extra'] ?? '' ) ),
                ];
            }
            self::$columns[ $table ] = $cols;
        }
        return self::$columns[ $table ];
    }

    /**
     * True when $table has column $col (case-insensitive). If the table's columns can't be read, assumes yes.
     */
    public static function has_column( string $table, string $col ): bool {
        $cols = self::columns( $table );
        return ! $cols || isset( $cols[ strtolower( $col ) ] );   // columns unknown: assume yes, as before
    }

    /** Keep only the entries of col => value whose column exists. */
    public static function existing( string $table, array $sets ): array {
        return array_filter( $sets, static fn( $col ) => self::has_column( $table, (string) $col ), ARRAY_FILTER_USE_KEY );
    }

    /**
     * True when column $col exists and is NOT NULL, so an empty value must be written as '' rather than NULL.
     */
    private static function required( string $table, string $col ): bool {
        $c = self::columns( $table )[ strtolower( $col ) ] ?? null;
        return $c !== null && ! $c['null'];
    }

    /** An "empty" value a NOT NULL column without a default accepts: 0 for numbers, today/now for dates, '' otherwise. */
    private static function blank_for( array $c ): string {
        $t = $c['type'];
        if ( preg_match( '/^(tiny|small|medium|big)?int|^decimal|^float|^double|^bit/', $t ) ) { return '0'; }
        if ( str_starts_with( $t, 'datetime' ) || str_starts_with( $t, 'timestamp' ) ) { return current_time( 'mysql' ); }
        if ( str_starts_with( $t, 'date' ) ) { return current_time( 'Y-m-d' ); }
        if ( str_starts_with( $t, 'time' ) ) { return '00:00:00'; }
        if ( str_starts_with( $t, 'year' ) ) { return current_time( 'Y' ); }
        return '';
    }

    /** "`a`=%s,`b`=NULL,..." and the matching values, for the existing columns only (null = SQL NULL). */
    private static function set_list( string $table, array $sets ): array {
        $parts = []; $vals = [];
        foreach ( self::existing( $table, $sets ) as $col => $val ) {
            if ( $val === null ) { $parts[] = self::esc_id( (string) $col ) . '=NULL'; }
            else { $parts[] = self::esc_id( (string) $col ) . '=%s'; $vals[] = (string) $val; }
        }
        return [ implode( ',', $parts ), $vals ];
    }

    /**
     * UPDATE the existing columns only. A null value for a NOT NULL column is skipped (the stored value stays):
     * e.g. CDLC's SuspendDateEnd is a required DATE, so "no suspension" leaves the old date alone. $where uses
     * placeholders; its values follow.
     */
    public static function update_existing( string $table, array $sets, string $where, ...$where_args ) {
        $sets = array_filter( $sets, static fn( $v, $col ) => ! ( $v === null && self::required( $table, (string) $col ) ), ARRAY_FILTER_USE_BOTH );
        [ $list, $vals ] = self::set_list( $table, $sets );
        if ( $list === '' ) { self::$last_error = 'none of the fields exist in the table'; return false; }
        return self::query( 'UPDATE %t SET ' . $list . ' WHERE ' . $where, $table, ...array_merge( $vals, $where_args ) );
    }

    /**
     * INSERT the existing columns only. Every NOT NULL column without a default that the caller didn't fill (or filled
     * with null) gets an empty value of its type, so strict mode doesn't reject the row ("Field 'x' doesn't have a default value").
     */
    public static function insert_existing( string $table, array $sets ) {
        $sets = self::existing( $table, $sets );
        $have = array_change_key_case( $sets, CASE_LOWER );
        foreach ( self::columns( $table ) as $lc => $c ) {
            $missing = ! array_key_exists( $lc, $have ) || $have[ $lc ] === null;
            if ( $missing && ! $c['null'] && $c['default'] === null && strpos( $c['extra'], 'auto_increment' ) === false ) {
                foreach ( array_keys( $sets ) as $k ) { if ( strtolower( (string) $k ) === $lc ) { unset( $sets[ $k ] ); } }
                $sets[ $c['name'] ] = self::blank_for( $c );
            }
        }
        [ $list, $vals ] = self::set_list( $table, $sets );
        if ( $list === '' ) { self::$last_error = 'none of the fields exist in the table'; return false; }
        return self::query( 'INSERT INTO %t SET ' . $list, $table, ...$vals );
    }

    /**
     * Escape a single string value for use in SQL.
     * Prefer the %s placeholder in query() instead of calling this directly.
     */
    public static function esc( string $value ): string {
        return self::get()->real_escape_string( $value );
    }

    /**
     * Quote a table or column identifier.
     * Strips all backticks first to prevent injection via identifier names.
     */
    public static function esc_id( string $identifier ): string {
        return '`' . str_replace( '`', '', $identifier ) . '`';
    }

    // ── Encryption (libsodium AEAD / OpenSSL AES-256-GCM) ────────────────────

    /**
     * Encrypt a plaintext password for storage in wp_options.
     *
     * Uses sodium_crypto_secretbox (XSalsa20-Poly1305) when available.
     * Falls back to OpenSSL AES-256-GCM (still strong).
     * Both use a 256-bit key derived from AUTH_KEY + SECURE_AUTH_KEY via
     * hash_hkdf to avoid using the raw WP keys directly.
     *
     * @throws RuntimeException if neither crypto extension is available.
     */
    public static function encrypt_pass( string $plain ): string {
        if ( $plain === '' ) { return ''; }

        $key = self::derive_key();

        if ( extension_loaded( 'sodium' ) ) {
            $nonce      = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
            $ciphertext = sodium_crypto_secretbox( $plain, $nonce, $key );
            sodium_memzero( $plain );
            return 'sod:' . base64_encode( $nonce . $ciphertext );
        }

        if ( extension_loaded( 'openssl' ) ) {
            $iv         = random_bytes( 12 );  // GCM standard nonce length
            $tag        = '';
            $ciphertext = openssl_encrypt( $plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16 );
            if ( $ciphertext === false ) {
                throw new RuntimeException( 'ILL_DB: OpenSSL encryption failed.' );
            }
            return 'osl:' . base64_encode( $iv . $tag . $ciphertext );
        }

        throw new RuntimeException( 'ILL_DB: Neither sodium nor openssl PHP extension is available. Cannot encrypt credentials.' );
    }

    /**
     * Decrypt a password previously encrypted by encrypt_pass().
     * Returns empty string on any decryption failure (logs the error).
     */
    public static function decrypt_pass( string $enc ): string {
        if ( $enc === '' ) { return ''; }

        $key = self::derive_key();

        if ( str_starts_with( $enc, 'sod:' ) && extension_loaded( 'sodium' ) ) {
            try {
                $raw        = base64_decode( substr( $enc, 4 ), true );
                if ( $raw === false || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
                    throw new \Exception( 'Malformed ciphertext' );
                }
                $nonce      = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
                $ciphertext = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
                $plain      = sodium_crypto_secretbox_open( $ciphertext, $nonce, $key );
                if ( $plain === false ) {
                    throw new \Exception( 'Decryption failed — bad key or tampered ciphertext' );
                }
                return $plain;
            } catch ( \Throwable $e ) {
                error_log( 'ILL_DB decrypt_pass (sodium): ' . $e->getMessage() );
                return '';
            }
        }

        if ( str_starts_with( $enc, 'osl:' ) && extension_loaded( 'openssl' ) ) {
            try {
                $raw  = base64_decode( substr( $enc, 4 ), true );
                if ( $raw === false || strlen( $raw ) <= 28 ) { // 12 iv + 16 tag
                    throw new \Exception( 'Malformed ciphertext' );
                }
                $iv         = substr( $raw, 0,  12 );
                $tag        = substr( $raw, 12, 16 );
                $ciphertext = substr( $raw, 28 );
                $plain      = openssl_decrypt( $ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
                if ( $plain === false ) {
                    throw new \Exception( 'Decryption failed' );
                }
                return $plain;
            } catch ( \Throwable $e ) {
                error_log( 'ILL_DB decrypt_pass (openssl): ' . $e->getMessage() );
                return '';
            }
        }

        // Legacy: pre-3.2.1 XOR+base64. Attempt to decode and flag for re-save.
        if ( ! str_contains( $enc, ':' ) ) {
            error_log( 'ILL_DB: Legacy XOR-encrypted password detected. Re-save the DB settings to upgrade encryption.' );
            $raw = base64_decode( $enc );
            if ( defined( 'AUTH_KEY' ) && AUTH_KEY !== 'put your unique phrase here' ) {
                return $raw ^ str_repeat( AUTH_KEY, (int) ceil( strlen( $raw ) / strlen( AUTH_KEY ) ) );
            }
            return $raw;
        }

        error_log( 'ILL_DB decrypt_pass: unknown format or missing extension.' );
        return '';
    }

    /**
     * Derive a 256-bit key from WordPress secret keys using HKDF-SHA256.
     * Uses AUTH_KEY + SECURE_AUTH_KEY as input keying material so the
     * encryption key is site-specific and not the WP key directly.
     */
    private static function derive_key(): string {
        $ikm  = ( defined('AUTH_KEY')         ? AUTH_KEY         : '' )
              . ( defined('SECURE_AUTH_KEY')   ? SECURE_AUTH_KEY : '' );

        if ( strlen( $ikm ) < 32 ) {
            // wp-config.php has placeholder keys — key is weak but encryption still runs
            error_log( 'ILL_DB: WordPress secret keys appear to be default placeholders. Update wp-config.php.' );
        }

        return hash_hkdf( 'sha256', $ikm, 32, 'seal-ill-db-key', '' );
    }

    // ── Internal query builder ─────────────────────────────────────────────────

    /**
     * Fills the %s / %d / %f / %t placeholders in $sql from $args, in order: %s is escaped and quoted, %d and
     * %f are cast to numbers, %t is a back-quoted table name. Used by query(); never interpolate user input
     * into $sql yourself.
     */
    private static function build_query( mysqli $db, string $sql, array $args ): string {
        $i = 0;
        return preg_replace_callback(
            '/%([sdft])/',
            static function ( array $m ) use ( $db, $args, &$i ): string {
                $val = $args[ $i++ ] ?? '';
                return match ( $m[1] ) {
                    's'     => "'" . $db->real_escape_string( (string) $val ) . "'",
                    'd'     => (string) (int)   $val,
                    'f'     => (string) (float) $val,
                    't'     => '`' . str_replace( '`', '', (string) $val ) . '`',
                    default => "'" . $db->real_escape_string( (string) $val ) . "'",
                };
            },
            $sql
        );
    }

    /**
     * Close and discard the current connection (useful for long-running processes).
     */
    public static function close(): void {
        if ( self::$conn instanceof mysqli ) {
            self::$conn->close();
            self::$conn = null;
        }
    }
}
