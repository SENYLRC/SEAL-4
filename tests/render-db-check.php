<?php
/**
 * Renders ILL System → Database → Database check against a fake database built from a schema dump, to look at in a
 * browser. Exercises SEAL_DB_Schema::gather() (the queries it sends) as well as the page.
 *
 *   php tests/render-db-check.php schema.sql [strict] > out.html
 *
 * Table names: the dump's own (any prefix before STATS, Library-Data, ILLIAD-ADD-MAPPING), else the plugin defaults.
 */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 403 ); exit; }
define( 'ABSPATH', __DIR__ . '/' );
require __DIR__ . '/lib-schema.php';

$dump   = parse_schema( (string) file_get_contents( $argv[1] ?? '' ) );
$strict = ( $argv[2] ?? '' ) === 'strict';
$pre    = '';
foreach ( array_keys( $dump ) as $t ) { if ( preg_match( '/^(.*)STATS$/', $t, $m ) ) { $pre = $m[1]; } }
$GLOBALS['cfg'] = [ 'table_stat' => $pre . 'STATS', 'table_lib' => $pre . 'Library-Data', 'table_illiad' => $pre . 'ILLIAD-ADD-MAPPING', 'table_rota' => 'ILL-ROTA', 'system_name' => 'Town ILL' ];
$_GET = [ 'page' => 'seal-ill-database', 'seal_dbcheck' => '1' ];

// ── WordPress stand-ins ──
function seal_config( string $k ): string { return $GLOBALS['cfg'][ $k ] ?? ''; }
function current_user_can( $c ): bool { return true; }
function esc_html( $s ): string { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ): string { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ): string { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_textarea( $s ): string { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function admin_url( $p = '' ): string { return '/wp-admin/' . $p; }
function add_query_arg( array $a, string $u ): string { return $u . '?' . http_build_query( $a ); }
function wp_date( $f ): string { return date( $f ); }
function wp_nonce_field( $a ): void { echo '<input type="hidden" name="_wpnonce" value="x">'; }
function sanitize_text_field( $s ): string { return trim( (string) $s ); }
function wp_unslash( $s ) { return $s; }

// ── Fake ILL database answering the queries gather() sends ──
class FakeResult { public int $num_rows; public function __construct( public array $rows ) { $this->num_rows = count( $rows ); } public function fetch_assoc() { return array_shift( $this->rows ); } }
class SEAL_DB {
    public static array $log = [];
    public static function get() { return true; }
    public static function last_error(): string { return ''; }
    public static function columns( string $t ): array { return $GLOBALS['dump'][ $t ]['columns'] ?? []; }
    public static function query( string $sql, ...$a ) {
        $i = 0;
        $sql = preg_replace_callback( '/%([sdt])/', static function ( $m ) use ( $a, &$i ) { $v = $a[ $i++ ] ?? ''; return $m[1] === 's' ? "'" . addslashes( (string) $v ) . "'" : ( $m[1] === 't' ? '`' . $v . '`' : (string) (int) $v ); }, $sql );
        self::$log[] = $sql;
        $d = $GLOBALS['dump'];
        if ( str_starts_with( $sql, 'SELECT VERSION()' ) ) { return new FakeResult( [ [ 'v' => '8.0.40', 'm' => $GLOBALS['strict'] ? 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION' : 'NO_ENGINE_SUBSTITUTION', 'd' => 'ill' ] ] ); }
        if ( str_contains( $sql, 'Ssl_cipher' ) ) { return new FakeResult( [ [ 'Variable_name' => 'Ssl_cipher', 'Value' => 'TLS_AES_256_GCM_SHA384' ] ] ); }
        if ( preg_match( "/^SHOW TABLE STATUS WHERE `Name` = '(.+)'$/", $sql, $m ) ) {
            $t = $d[ $m[1] ] ?? null;
            return new FakeResult( $t ? [ [ 'Name' => $m[1], 'Engine' => $t['engine'], 'Collation' => $t['collation'], 'Rows' => $m[1] === $GLOBALS['cfg']['table_stat'] ? 17832 : 512 ] ] : [] );
        }
        if ( preg_match( '/^SHOW INDEX FROM `(.+)`$/', $sql, $m ) ) {
            $rows = [];
            foreach ( $d[ $m[1] ]['indexes'] ?? [] as $col => $key ) { $rows[] = [ 'Key_name' => $key, 'Column_name' => $col, 'Seq_in_index' => 1 ]; }
            return new FakeResult( $rows );
        }
        if ( str_contains( $sql, 'HAVING COUNT(*) > 1' ) ) { return new FakeResult( [ [ 'l' => 'NYALB', 'n' => 2 ] ] ); }
        if ( str_contains( $sql, "TRIM(`loc`) = ''" ) ) { return new FakeResult( [ [ 'n' => 0 ] ] ); }
        if ( str_contains( $sql, 'participant' ) ) { return new FakeResult( [ [ 'n' => 3 ] ] ); }
        if ( str_contains( $sql, 'CHAR_LENGTH(`ill_email`)' ) ) { return new FakeResult( [ [ 'n' => 2 ] ] ); }
        if ( str_contains( $sql, 'MAX(CHAR_LENGTH(`system`))' ) ) { return new FakeResult( [ [ 'n' => 4 ] ] ); }
        if ( str_contains( $sql, 'LEFT JOIN' ) ) { return new FakeResult( [ [ 'l' => 'OLDLIB' ] ] ); }
        fwrite( STDERR, "unexpected query: $sql\n" );
        return false;
    }
}
class SEAL_Rota { public static function active(): bool { return true; } }
$GLOBALS['dump'] = $dump; $GLOBALS['strict'] = $strict;

require dirname( __DIR__ ) . '/includes/class-seal-db-schema.php';
require dirname( __DIR__ ) . '/admin/class-seal-admin.php';

ob_start();
SEAL_Admin::render_db_check();
$body = ob_get_clean();
echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Database check</title>'
   . '<style>body{font:14px/1.5 -apple-system,Segoe UI,Roboto,sans-serif;margin:20px;color:#1d2327;background:#f0f0f1}.widefat{border-collapse:collapse;background:#fff;border:1px solid #c3c4c7}'
   . '.widefat td,.widefat th{padding:8px 10px;text-align:left;vertical-align:top}.striped tbody tr:nth-child(odd){background:#f6f7f7}.large-text{width:100%}.code{font-family:monospace;font-size:12px}'
   . '.screen-reader-text{position:absolute;clip:rect(1px,1px,1px,1px);height:1px;width:1px;overflow:hidden}.button{padding:4px 10px}</style></head><body>' . $body . '</body></html>';
fwrite( STDERR, count( SEAL_DB::$log ) . " queries\n" );
