<?php
/**
 * SEAL_System_Check — what the plugin needs from the web server: PHP version, PHP extensions and a way to send
 * mail. facts() reads this server; analyze() turns facts into findings (no server access, so it can be tested).
 * Shown first in ILL System → Database → Database check. The cron jobs run under the command-line PHP, which can
 * differ: `php cron_config.php --check-system` checks that one.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class SEAL_System_Check {

    /** Extension => [ level when missing, what needs it ]. */
    const EXTENSIONS = [
        'mysqli'    => [ 'fail', 'the ILL database connection' ],
        'openssl'   => [ 'fail', 'encrypted database connections, security keys and ILLiad key encryption' ],
        'mbstring'  => [ 'fail', 'password checks and email text (sign-in and password changes can fail without it)' ],
        'simplexml' => [ 'fail', 'reading catalog records on the request page' ],
        'json'      => [ 'fail', 'ILLiad requests and settings' ],
        'sodium'    => [ 'info', 'ILLiad key encryption with libsodium (OpenSSL is used instead without it)' ],
    ];

    /** This web server's facts. */
    public static function facts(): array {
        $sendmail = trim( (string) ini_get( 'sendmail_path' ) );
        $bin      = $sendmail === '' ? '' : (string) strtok( $sendmail, " \t" );
        return [
            'php'         => PHP_VERSION,
            'wp'          => (string) get_bloginfo( 'version' ),
            'ext'         => array_fill_keys( array_map( 'strtolower', get_loaded_extensions() ), true ),
            'mail_plugin' => self::mail_plugin(),
            'sendmail'    => $sendmail,
            'sendmail_ok' => $bin !== '' && @is_file( $bin ) && @is_executable( $bin ),
        ];
    }

    /**
     * The mail plugin that handles WordPress mail: '' when none. Plugins either replace wp_mail() itself
     * (FluentSMTP, Post SMTP) or set up PHPMailer on phpmailer_init / pre_wp_mail (WP Mail SMTP and others).
     */
    private static function mail_plugin(): string {
        if ( function_exists( 'wp_mail' ) ) {
            $file = (string) ( new ReflectionFunction( 'wp_mail' ) )->getFileName();
            if ( $file !== '' && ! str_ends_with( str_replace( '\\', '/', $file ), 'wp-includes/pluggable.php' ) ) {
                return preg_match( '#/plugins/([^/]+)/#', str_replace( '\\', '/', $file ), $m ) ? $m[1] : basename( $file );
            }
        }
        return ( has_action( 'phpmailer_init' ) || has_filter( 'pre_wp_mail' ) ) ? 'a mail plugin' : '';
    }

    /** Findings for $f, in the Database check's shape (level ok | info | warn | fail). */
    public static function analyze( array $f ): array {
        $out   = [];
        $out[] = version_compare( (string) $f['php'], '8.0', '>=' )
            ? self::f( 'ok', 'PHP ' . $f['php'], 'WordPress ' . $f['wp'] . '.' )
            : self::f( 'fail', 'PHP ' . $f['php'] . ' is too old', 'This plugin needs PHP 8.0 or newer.', 'Ask your host to upgrade PHP.' );

        $missing = [];
        foreach ( self::EXTENSIONS as $name => [ $level, $why ] ) {
            if ( isset( $f['ext'][ $name ] ) ) { continue; }
            $missing[] = $name;
            $out[]     = self::f( $level, 'PHP extension ' . $name . ' is missing', 'Needed for ' . $why . '.',
                $level === 'fail' ? 'Install it (e.g. apt install php-' . ( $name === 'simplexml' ? 'xml' : ( $name === 'mysqli' ? 'mysql' : $name ) ) . ') and restart the web server.' : '' );
        }
        if ( ! $missing ) {
            $out[] = self::f( 'ok', 'PHP extensions', 'All present: ' . implode( ', ', array_keys( self::EXTENSIONS ) ) . '.' );
        }

        if ( $f['mail_plugin'] !== '' ) {
            $out[] = self::f( 'ok', 'Email', 'WordPress mail goes through ' . ( $f['mail_plugin'] === 'a mail plugin' ? 'a mail plugin' : 'the mail plugin ' . $f['mail_plugin'] ) . ' (SMTP).' );
        } elseif ( $f['sendmail_ok'] ) {
            $out[] = self::f( 'ok', 'Email', 'WordPress mail goes to the server\'s mail program (' . $f['sendmail'] . ').' );
        } else {
            $out[] = self::f( 'fail', 'No way to send email',
                'No SMTP mail plugin is active and the server\'s mail program ' . ( $f['sendmail'] === '' ? '(sendmail_path) is not set' : '(' . $f['sendmail'] . ') was not found' )
                . ', so request, account and password emails are not sent.',
                'Install and set up an SMTP plugin, or a mail program such as Postfix that relays for your From address.' );
        }

        $out[] = self::f( 'info', 'Scheduled jobs use the command-line PHP',
            'It can have different extensions and needs its own mail program: the jobs don\'t run inside WordPress, so a mail plugin (FluentSMTP, WP Mail SMTP…) doesn\'t send for them.',
            'On the server, in the scripts folder: php cron_config.php --check-system' );
        return $out;
    }

    /** Findings for this server. */
    public static function check(): array {
        return self::analyze( self::facts() );
    }

    private static function f( string $level, string $title, string $detail = '', string $hint = '' ): array {
        return [ 'level' => $level, 'table' => 'server', 'title' => $title, 'detail' => $detail, 'sql' => '', 'hint' => $hint ];
    }
}
