<?php
/**
 * Tests SEAL_DB_Schema::analyze() without a database: a schema dump (mysqldump --no-data) is turned into what gather()
 * would read, then the findings are checked. Also checks that a database built from the bundled CREATE TABLEs is clean.
 *
 *   php tests/test-db-schema.php [schema.sql]
 *
 * With a dump of an existing (pre-plugin) ILL database it also checks the findings for that database. Its tables may share a
 * prefix ({prefix}STATS, {prefix}Library-Data, {prefix}ILLIAD-ADD-MAPPING, plus ILL-ROTA); the prefix is read from the dump.
 */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 403 ); exit; }
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['cfg'] = [ 'table_stat' => 'STATS', 'table_lib' => 'Library-Data', 'table_illiad' => 'ILLIAD-ADD-MAPPING', 'table_rota' => 'ILL-ROTA' ];
function seal_config( string $k ): string { return $GLOBALS['cfg'][ $k ] ?? ''; }
require dirname( __DIR__ ) . '/includes/class-seal-db-schema.php';
require __DIR__ . '/lib-schema.php';

$fail = 0;
function t( string $name, bool $ok, string $extra = '' ): void { global $fail; if ( ! $ok ) { $fail++; } echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( $ok ? '' : "  $extra" ) . "\n"; }

function find( array $f, string $level, string $needle ): ?array {
    foreach ( $f as $x ) { if ( $x['level'] === $level && stripos( $x['title'] . ' ' . $x['detail'], $needle ) !== false ) { return $x; } }
    return null;
}
function levels( array $f ): array { return array_count_values( array_column( $f, 'level' ) ); }

echo "=== bundled schema (new install) ===\n";
$fresh_sql = '';
foreach ( array_keys( SEAL_DB_Schema::TABLES ) as $k ) { $fresh_sql .= SEAL_DB_Schema::create_sql( $k, seal_config( SEAL_DB_Schema::TABLES[ $k ]['config'] ) ) . "\n"; }
$fresh = parse_schema( $fresh_sql );
t( 'four CREATE TABLEs parse', count( $fresh ) === 4, implode( ',', array_keys( $fresh ) ) );
$info = [ 'server' => [ 'version' => '8.0.40', 'sql_mode' => 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION', 'database' => 'ill', 'cipher' => 'TLS_AES_256_GCM_SHA384', 'rota_active' => true ], 'tables' => [], 'data' => [ 'system_len' => 10 ] ];
foreach ( SEAL_DB_Schema::TABLES as $k => $spec ) { $info['tables'][ $k ] = $fresh[ seal_config( $spec['config'] ) ]; }
$f = SEAL_DB_Schema::analyze( $info );
$lv = levels( $f );
t( 'fresh install with strict mode: no fail / warn', empty( $lv['fail'] ) && empty( $lv['warn'] ), json_encode( array_values( array_filter( $f, fn( $x ) => in_array( $x['level'], [ 'fail', 'warn' ], true ) ) ) ) );
t( 'fresh: optional regional delivery columns reported as info only', (bool) find( $f, 'info', 'lbsyscourier' ) );
t( 'fresh: every NOT NULL column the request INSERT leaves out has a default', ! find( $f, 'fail', 'need a default' ) && ! find( $f, 'fail', 'needs a default' ) );
t( 'fresh: each table reported OK or info', count( array_filter( $f, fn( $x ) => $x['table'] !== '' ) ) >= 4 );
foreach ( SEAL_DB_Schema::TABLES as $k => $spec ) {
    foreach ( $spec['cols'] as $col => [ $def ] ) {
        if ( stripos( $def, 'NOT NULL' ) !== false && stripos( $def, 'DEFAULT' ) === false && stripos( $def, 'AUTO_INCREMENT' ) === false
            && ! preg_match( '/TEXT/i', $def ) && ! in_array( $col, [ 'illNUB', 'hop_started', 'created', 'updated' ], true ) ) {
            t( "fresh: $k.$col has a default", false, $def );
        }
    }
}
t( 'create_sql leaves out the regional columns', ! str_contains( $fresh_sql, 'lbEmpire' ) );
t( 'create_sql is utf8mb4 InnoDB', substr_count( $fresh_sql, 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' ) === 4 );

echo "=== missing table / columns ===\n";
$i2 = $info; unset( $i2['tables']['illiad'] ); $i2['tables']['illiad'] = [ 'name' => 'ILLIAD-ADD-MAPPING', 'exists' => false ];
$x = find( SEAL_DB_Schema::analyze( $i2 ), 'fail', 'does not exist' );
t( 'missing table: fail with CREATE TABLE', $x && str_starts_with( $x['sql'], 'CREATE TABLE `ILLIAD-ADD-MAPPING`' ) );
$i2 = $info; $i2['tables']['rota'] = [ 'name' => 'ILL-ROTA', 'exists' => false ]; $i2['server']['rota_active'] = false;
t( 'missing rota table with fallback off: info, not fail', (bool) find( SEAL_DB_Schema::analyze( $i2 ), 'info', 'does not exist yet' ) );
$i2 = $info; unset( $i2['tables']['lib']['columns']['libemailalert'], $i2['tables']['lib']['columns']['theses_loan'] );
$x = find( SEAL_DB_Schema::analyze( $i2 ), 'fail', 'required column' );
t( 'missing required columns: fail with ALTER ... ADD COLUMN', $x && str_contains( $x['sql'], 'ADD COLUMN `LibEmailAlert`' ) && str_contains( $x['sql'], 'ADD COLUMN `theses_loan`' ) );
$i2 = $info; $i2['tables']['stat']['name'] = '';
t( 'blank table name: fail', (bool) find( SEAL_DB_Schema::analyze( $i2 ), 'fail', 'no table name' ) );
$i2 = $info; $i2['server']['cipher'] = '';
t( 'no TLS: warn', (bool) find( SEAL_DB_Schema::analyze( $i2 ), 'warn', 'not encrypted' ) );
$i2 = $info; $i2['tables']['lib']['columns']['apikey']['type'] = 'varchar(64)';
t( 'narrow APIkey: fail', (bool) find( SEAL_DB_Schema::analyze( $i2 ), 'fail', 'APIkey column too narrow' ) );
$i2 = $info; $i2['data'] = [ 'dup_loc' => [ 'ABC' => 2 ], 'blank_loc' => 3, 'no_email' => 1, 'email_full' => 0, 'system_len' => 60, 'map_orphans' => [ 'ZZZ' ] ];
$f2 = SEAL_DB_Schema::analyze( $i2 );
t( 'duplicate LOC: warn', (bool) find( $f2, 'warn', 'ABC (2)' ) );
t( 'participant without email: warn', (bool) find( $f2, 'warn', 'no ILL email' ) );
t( 'library system code longer than DestSystem: fail in strict mode', (bool) find( $f2, 'fail', 'DestSystem is too short' ) );
t( 'mapping LOC not in library table: info', (bool) find( $f2, 'info', 'ZZZ' ) );

$dump = $argv[1] ?? '';
if ( $dump !== '' ) {
    echo "=== $dump (existing database) ===\n";
    $live = parse_schema( (string) file_get_contents( $dump ) );
    $pre  = '';
    foreach ( array_keys( $live ) as $t ) { if ( preg_match( '/^(.*)STATS$/', $t, $m ) ) { $pre = $m[1]; } }
    $GLOBALS['cfg'] = [ 'table_stat' => $pre . 'STATS', 'table_lib' => $pre . 'Library-Data', 'table_illiad' => $pre . 'ILLIAD-ADD-MAPPING', 'table_rota' => 'ILL-ROTA' ];
    $info = [ 'server' => [ 'version' => '8.0.x', 'sql_mode' => 'NO_ENGINE_SUBSTITUTION', 'database' => 'ill', 'cipher' => 'TLS_AES_256_GCM_SHA384', 'rota_active' => true ], 'tables' => [], 'data' => [ 'system_len' => 4 ] ];
    foreach ( SEAL_DB_Schema::TABLES as $k => $spec ) { $info['tables'][ $k ] = $live[ seal_config( $spec['config'] ) ] ?? [ 'name' => seal_config( $spec['config'] ), 'exists' => false ]; }
    $f = SEAL_DB_Schema::analyze( $info );
    t( 'no required column missing', ! find( $f, 'fail', 'required column' ), json_encode( find( $f, 'fail', 'required column' ) ) );
    $x = find( $f, 'warn', 'need a default' );
    t( 'non-strict: the six request columns need a default (warn)', $x && preg_match_all( '/fillNofillDate|returnNote|checkinTimeStamp|shipMethod|IlliadStatus|IlliadTransID/', $x['detail'] ) === 6, json_encode( $x ) );
    t( 'fix makes fillNofillDate a nullable DATE', $x && str_contains( $x['sql'], 'MODIFY `fillNofillDate` DATE NULL DEFAULT NULL' ) );
    $info['server']['sql_mode'] = 'STRICT_TRANS_TABLES';
    t( 'strict: the same finding is a fail', (bool) find( SEAL_DB_Schema::analyze( $info ), 'fail', 'need a default' ) );
    $info['server']['sql_mode'] = 'NO_ENGINE_SUBSTITUTION';
    t( 'no index on illNUB: warn', (bool) find( $f, 'warn', 'no index on illNUB' ) );
    t( 'requests table latin1: warn with CONVERT', ( $x = find( $f, 'warn', 'Requests (statistics) table: character set latin1' ) ) && str_contains( $x['sql'], 'CONVERT TO CHARACTER SET utf8mb4' ) );
    t( 'library table utf8mb3: warn', (bool) find( $f, 'warn', 'Library table: character set utf8mb3' ) );
    t( 'MyISAM tables: info', (bool) find( $f, 'info', 'Requests (statistics) table: MyISAM' ) && (bool) find( $f, 'info', 'Library table: MyISAM' ) );
    t( 'regional delivery columns: info', (bool) find( $f, 'info', 'lbsyscourier' ) );
    t( 'rota table: OK', (bool) find( $f, 'ok', 'Automatic Fallback (rota) table' ) );
    echo "\n--- findings for this dump ---\n";
    foreach ( $f as $x ) { echo str_pad( strtoupper( $x['level'] ), 5 ) . ' ' . $x['title'] . "\n"; }
}
echo $fail ? "\nFAILURES: $fail\n" : "\nAll passed.\n";
exit( $fail ? 1 : 0 );
