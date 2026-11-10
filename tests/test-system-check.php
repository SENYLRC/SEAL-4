<?php
/**
 * Tests SEAL_System_Check::analyze() (no server access): a complete server, missing extensions, no way to send
 * email (no SMTP plugin and no sendmail), an SMTP plugin without sendmail, which plugin replaced wp_mail(), old PHP.
 *
 *   php tests/test-system-check.php
 */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 403 ); exit; }
define( 'ABSPATH', __DIR__ . '/' );
require dirname( __DIR__ ) . '/includes/class-seal-system-check.php';

$fail = 0;
function t( string $name, bool $ok, string $extra = '' ): void { global $fail; if ( ! $ok ) { $fail++; } echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( $ok ? '' : "  $extra" ) . "\n"; }
$by = static function ( array $f ): array { $o = []; foreach ( SEAL_System_Check::analyze( $f ) as $x ) { $o[ $x['title'] ] = $x; } return $o; };
$levels = static fn( array $r ): array => array_count_values( array_column( $r, 'level' ) );
$good = [ 'php' => '8.3.6', 'wp' => '6.8.1', 'ext' => array_fill_keys( array_keys( SEAL_System_Check::EXTENSIONS ), true ),
          'mail_plugin' => '', 'sendmail' => '/usr/sbin/sendmail -t -i', 'sendmail_ok' => true ];

$r = $by( $good );
t( 'complete server: no problems or warnings', ! isset( $levels( $r )['fail'] ) && ! isset( $levels( $r )['warn'] ) && isset( $r['PHP extensions'] ), json_encode( $levels( $r ) ) );
t( 'reminds about the command-line PHP check', str_contains( $r['Scheduled jobs use the command-line PHP']['hint'] ?? '', '--check-system' ) );

$r = $by( [ 'sendmail_ok' => false ] + $good );
t( 'no SMTP plugin and no sendmail: problem, suggests Postfix', ( $r['No way to send email']['level'] ?? '' ) === 'fail' && str_contains( $r['No way to send email']['hint'], 'Postfix' ) );
$r = $by( [ 'sendmail_ok' => false, 'mail_plugin' => 'fluent-smtp' ] + $good );
t( 'FluentSMTP (replaces wp_mail), no sendmail: email ok, plugin named', ( $r['Email']['level'] ?? '' ) === 'ok' && str_contains( $r['Email']['detail'], 'fluent-smtp' ) );

$ext = $good['ext']; unset( $ext['mbstring'], $ext['sodium'], $ext['simplexml'] );
$r = $by( [ 'ext' => $ext ] + $good );
t( 'missing mbstring / simplexml: problems with the package to install', ( $r['PHP extension mbstring is missing']['level'] ?? '' ) === 'fail'
    && str_contains( $r['PHP extension simplexml is missing']['hint'], 'php-xml' ) );
t( 'missing sodium: only a note', ( $r['PHP extension sodium is missing']['level'] ?? '' ) === 'info' );
t( 'no "all present" line when something is missing', ! isset( $r['PHP extensions'] ) );

// facts(): a wp_mail() defined by a plugin (as FluentSMTP does) is found and named.
function get_bloginfo( $k ) { return '6.8.1'; }
function has_action( ...$a ) { return false; } function has_filter( ...$a ) { return false; }
$dir = sys_get_temp_dir() . '/seal-' . getmypid() . '/wp-content/plugins/fluent-smtp'; @mkdir( $dir, 0700, true );
file_put_contents( "$dir/pluggable.php", '<?php function wp_mail( $to, $s, $m, $h = "", $a = [] ) { return true; }' );
require "$dir/pluggable.php";
t( 'facts(): FluentSMTP found from where wp_mail() is defined', SEAL_System_Check::facts()['mail_plugin'] === 'fluent-smtp', SEAL_System_Check::facts()['mail_plugin'] );
unlink( "$dir/pluggable.php" );

$r = $by( [ 'php' => '7.4.33' ] + $good );
t( 'PHP 7.4: problem', ( $r['PHP 7.4.33 is too old']['level'] ?? '' ) === 'fail' );

echo $fail ? "\nFAILURES: $fail\n" : "\nAll passed.\n";
exit( $fail ? 1 : 0 );
