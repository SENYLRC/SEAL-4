<?php
/**
 * SEAL_Module_FileMonitor — file change detection.
 *
 *  - Baseline: SHA-256 of every code file (php, js, htaccess, ini … configurable)
 *    in WordPress core, plugins, themes and mu-plugins. Stored in a
 *    non-autoloaded option (hashes only — no file contents).
 *  - Scheduled scan (WP-Cron) compares the disk to the baseline and reports
 *    ADDED / MODIFIED / DELETED files, and emails the alert addresses.
 *  - Core files are also verified against WordPress.org's official checksums,
 *    so a site that was already tampered with when the baseline was taken is
 *    still caught.
 *  - PHP files found in wp-content/uploads are always flagged.
 *  - After a legitimate core/plugin/theme update the baseline refreshes itself.
 *    "Accept changes" in the admin re-baselines manually.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class SEAL_Module_FileMonitor {

    const OPT_BASELINE = 'seal_fm_baseline';
    const OPT_RESULT   = 'seal_fm_result';
    const HOOK_SCAN    = 'seal_fm_scan';
    const HOOK_REBASE  = 'seal_fm_rebaseline';
    const LIST_CAP     = 500;

    /**
     * Hooks the scheduled scan, the re-baseline after updates, and keeps the scan schedule in step with the
     * chosen frequency.
     */
    public static function init(): void {
        add_action( self::HOOK_SCAN,   [ __CLASS__, 'run_scheduled' ] );
        add_action( self::HOOK_REBASE, [ __CLASS__, 'rebaseline' ] );
        add_action( 'init',            [ __CLASS__, 'ensure_schedule' ] );
        add_action( 'upgrader_process_complete', [ __CLASS__, 'on_upgrade' ], 10, 0 );
    }

    // ── Scheduling ────────────────────────────────────────────────────────────

    /**
     * (Re)schedules the scan at the frequency set under Security → File Monitor (hourly, twice daily, daily or
     * weekly; default daily).
     */
    public static function ensure_schedule(): void {
        $want = seal_config( 'fm_frequency' );
        if ( ! in_array( $want, [ 'hourly', 'twicedaily', 'daily', 'weekly' ], true ) ) { $want = 'daily'; }
        if ( wp_get_schedule( self::HOOK_SCAN ) !== $want ) {
            wp_clear_scheduled_hook( self::HOOK_SCAN );
            wp_schedule_event( time() + 300, $want, self::HOOK_SCAN );
        }
    }

    /**
     * After WordPress, a plugin or a theme is updated, schedules a new baseline a minute later so the update
     * itself isn't reported as a change.
     */
    public static function on_upgrade(): void {
        if ( ! wp_next_scheduled( self::HOOK_REBASE ) ) {
            wp_schedule_single_event( time() + 60, self::HOOK_REBASE );
        }
    }

    // ── What to scan ──────────────────────────────────────────────────────────

    /** File extensions to watch, from the settings (default: php). */
    private static function extensions(): array {
        $e = array_filter( array_map( 'trim', explode( ',', strtolower( seal_config( 'fm_extensions' ) ) ) ) );
        return $e ?: [ 'php' ];
    }

    /** Path prefixes to skip, relative to the WordPress root, one per settings line. */
    private static function excluded_prefixes(): array {
        $out = [];
        foreach ( preg_split( '/\R/', seal_config( 'fm_exclude' ) ) as $l ) {
            $l = trim( str_replace( '\\', '/', $l ), " /\t" );
            if ( $l !== '' && strpos( $l, '..' ) === false ) { $out[] = $l . '/'; }
        }
        return $out;
    }

    /** An absolute path as a path relative to the WordPress root (forward slashes). */
    private static function rel( string $abs ): string {
        $root = rtrim( str_replace( '\\', '/', ABSPATH ), '/' ) . '/';
        $abs  = str_replace( '\\', '/', $abs );
        return strpos( $abs, $root ) === 0 ? substr( $abs, strlen( $root ) ) : $abs;
    }

    /**
     * True when a file should be fingerprinted: its extension is watched (.htaccess and .user.ini count as
     * htaccess / ini).
     */
    private static function wanted( string $name, array $exts ): bool {
        $lower = strtolower( $name );
        if ( $lower === '.htaccess' || $lower === '.user.ini' ) { return in_array( 'htaccess', $exts, true ) || in_array( 'ini', $exts, true ); }
        return in_array( strtolower( pathinfo( $lower, PATHINFO_EXTENSION ) ), $exts, true );
    }

    /** @return array<string,string> relative path => sha256 */
    public static function scan_files(): array {
        @set_time_limit( 0 );
        $exts    = self::extensions();
        $exclude = self::excluded_prefixes();
        $skip    = [ '.git', 'node_modules', 'cache', 'upgrade', 'backups', 'uploads' ];
        $root    = rtrim( ABSPATH, '/\\' );
        $out     = [];

        // Top-level files in the WordPress root (wp-config.php, index.php, .htaccess …)
        // and in wp-content (drop-ins such as advanced-cache.php, db.php).
        foreach ( [ $root, WP_CONTENT_DIR ] as $top ) {
            foreach ( (array) @scandir( $top ) as $name ) {
                $f = $top . '/' . $name;
                if ( $name !== '.' && $name !== '..' && is_file( $f ) && self::wanted( $name, $exts ) ) {
                    $out[ self::rel( $f ) ] = (string) hash_file( 'sha256', $f );
                }
            }
        }

        $dirs = [ $root . '/wp-admin', $root . '/wp-includes', WP_CONTENT_DIR . '/plugins', WP_CONTENT_DIR . '/themes', WP_CONTENT_DIR . '/mu-plugins' ];
        foreach ( $dirs as $dir ) {
            if ( ! is_dir( $dir ) ) { continue; }
            $it = new RecursiveIteratorIterator(
                new RecursiveCallbackFilterIterator(
                    new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
                    static function ( SplFileInfo $c ) use ( $skip ): bool {
                        return ! ( $c->isDir() && ( $c->isLink() || in_array( $c->getFilename(), $skip, true ) ) );
                    }
                )
            );
            foreach ( $it as $file ) {
                if ( ! $file->isFile() || ! self::wanted( $file->getFilename(), $exts ) ) { continue; }
                $rel = self::rel( $file->getPathname() );
                foreach ( $exclude as $ex ) {
                    if ( strpos( $rel . '/', $ex ) === 0 || strpos( $rel, $ex ) === 0 ) { continue 2; }
                }
                $h = @hash_file( 'sha256', $file->getPathname() );
                if ( $h !== false ) { $out[ $rel ] = $h; }
            }
        }

        ksort( $out );
        return $out;
    }

    /** @return string[] PHP-like files sitting in uploads (should never be there) */
    private static function uploads_code(): array {
        $up = wp_upload_dir( null, false );
        $dir = $up['basedir'] ?? '';
        if ( ! $dir || ! is_dir( $dir ) ) { return []; }
        $found = [];
        $it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
        foreach ( $it as $file ) {
            if ( $file->isFile() && preg_match( '/\.(php\d?|phtml|phar)$/i', $file->getFilename() ) ) {
                $found[] = self::rel( $file->getPathname() );
                if ( count( $found ) >= self::LIST_CAP ) { break; }
            }
        }
        sort( $found );
        return $found;
    }

    /** @return array{modified:string[],missing:string[],error:string} */
    private static function core_check(): array {
        global $wp_version;
        $res = [ 'modified' => [], 'missing' => [], 'error' => '' ];

        $cache_key = 'seal_fm_core_' . md5( $wp_version . get_locale() );
        $cached    = get_transient( $cache_key );
        if ( is_array( $cached ) ) { return $cached; }

        if ( ! function_exists( 'get_core_checksums' ) ) { require_once ABSPATH . 'wp-admin/includes/update.php'; }
        $sums = get_core_checksums( $wp_version, get_locale() );
        if ( ! is_array( $sums ) ) { $sums = get_core_checksums( $wp_version, 'en_US' ); }
        if ( ! is_array( $sums ) ) {
            $res['error'] = 'Could not fetch official WordPress checksums.';
            return $res;
        }

        foreach ( $sums as $path => $md5 ) {
            if ( strpos( $path, 'wp-content/' ) === 0 ) { continue; }
            $abs = ABSPATH . $path;
            if ( ! file_exists( $abs ) ) { $res['missing'][] = $path; continue; }
            if ( md5_file( $abs ) !== $md5 ) { $res['modified'][] = $path; }
        }
        $res['modified'] = array_slice( $res['modified'], 0, self::LIST_CAP );
        $res['missing']  = array_slice( $res['missing'], 0, self::LIST_CAP );
        set_transient( $cache_key, $res, 6 * HOUR_IN_SECONDS );
        return $res;
    }

    // ── Scan / baseline ───────────────────────────────────────────────────────

    /** Take a fresh baseline from disk. */
    public static function rebaseline(): void {
        update_option( self::OPT_BASELINE, [ 'time' => time(), 'files' => self::scan_files() ], false );
        delete_transient( 'seal_fm_core_' . md5( $GLOBALS['wp_version'] . get_locale() ) );
        update_option( self::OPT_RESULT, [
            'time' => time(), 'status' => 'clean', 'note' => 'Baseline created.',
            'added' => [], 'modified' => [], 'deleted' => [],
            'core_modified' => [], 'core_missing' => [], 'core_error' => '', 'uploads_code' => [],
            'total' => count( (array) ( get_option( self::OPT_BASELINE )['files'] ?? [] ) ), 'alerted' => '',
        ], false );
    }

    /** Cron entry point: runs a scan and emails the administrators if anything changed. */
    public static function run_scheduled(): void {
        self::run( true );
    }

    /** Run a scan; returns the stored result. */
    public static function run( bool $alert = false ): array {
        $base = get_option( self::OPT_BASELINE );
        if ( ! is_array( $base ) || empty( $base['files'] ) ) {
            self::rebaseline();
            return (array) get_option( self::OPT_RESULT );
        }

        $now     = self::scan_files();
        $old     = (array) $base['files'];
        $added   = array_keys( array_diff_key( $now, $old ) );
        $deleted = array_keys( array_diff_key( $old, $now ) );
        $mod     = [];
        foreach ( $now as $p => $h ) {
            if ( isset( $old[ $p ] ) && ! hash_equals( $old[ $p ], $h ) ) { $mod[] = $p; }
        }
        $core    = self::core_check();
        $uploads = self::uploads_code();

        $prev    = (array) get_option( self::OPT_RESULT, [] );
        $result  = [
            'time'          => time(),
            'added'         => array_slice( $added, 0, self::LIST_CAP ),
            'modified'      => array_slice( $mod, 0, self::LIST_CAP ),
            'deleted'       => array_slice( $deleted, 0, self::LIST_CAP ),
            'core_modified' => $core['modified'],
            'core_missing'  => $core['missing'],
            'core_error'    => $core['error'],
            'uploads_code'  => $uploads,
            'total'         => count( $now ),
            'alerted'       => (string) ( $prev['alerted'] ?? '' ),
            'note'          => '',
        ];
        $problems = count( $added ) + count( $mod ) + count( $deleted ) + count( $core['modified'] ) + count( $core['missing'] ) + count( $uploads );
        $result['status'] = $problems ? 'changes' : 'clean';

        if ( $problems && $alert ) {
            $hashes = [];
            foreach ( array_merge( $added, $mod ) as $p ) { $hashes[] = $p . '@' . $now[ $p ]; }
            $sig = sha1( wp_json_encode( [ $hashes, $deleted, $result['core_modified'], $result['core_missing'], $result['uploads_code'] ] ) );
            if ( $sig !== $result['alerted'] ) {
                self::email( $result );
                $result['alerted'] = $sig;
            }
        }

        update_option( self::OPT_RESULT, $result, false );
        return $result;
    }

    /**
     * Emails the scan result (files added, changed, removed, and core files that differ from the official
     * release) to the alert addresses.
     */
    private static function email( array $r ): void {
        $sys  = seal_config( 'system_name' );
        $site = esc_html( home_url() );
        $sec  = static function ( string $title, array $list ): string {
            if ( ! $list ) { return ''; }
            $li = '';
            foreach ( array_slice( $list, 0, 30 ) as $p ) { $li .= '<li><code>' . esc_html( $p ) . '</code></li>'; }
            $more = count( $list ) > 30 ? '<li>… and ' . ( count( $list ) - 30 ) . ' more</li>' : '';
            return '<h3>' . esc_html( $title ) . ' (' . count( $list ) . ')</h3><ul>' . $li . $more . '</ul>';
        };
        $body = '<p>The file monitor on <strong>' . $site . '</strong> found changes since the last approved baseline.</p>'
              . $sec( 'Modified files', $r['modified'] )
              . $sec( 'New files', $r['added'] )
              . $sec( 'Deleted files', $r['deleted'] )
              . $sec( 'WordPress core files that differ from the official release', $r['core_modified'] )
              . $sec( 'WordPress core files missing', $r['core_missing'] )
              . $sec( 'Code files inside uploads (should not exist)', $r['uploads_code'] )
              . '<p>Review them under <strong>' . esc_html( $sys ) . ' ILL → Security → File Monitor</strong>. '
              . 'If the changes are expected (an update or your own edit), choose “Accept changes”. '
              . 'If not, treat the site as compromised: change passwords, restore from a clean backup, and check for unknown admin users.</p>';
        SEAL_Functions::send_alert( 'File changes detected', $body );
    }
}
