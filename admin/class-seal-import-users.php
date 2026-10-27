<?php
/**
 * SEAL_Import_Users — ILL System → Import Users: create many accounts from a CSV (e.g. moving users from an older site).
 *
 * Three steps, Administrators only (manage_options), each POST with its own nonce:
 *   1. Upload a CSV. Columns are found by their header (case and "(Optional)" ignored): Username, Email, First Name,
 *      Last Name, Institution ("Your Institution"), LOC code ("LOC Location Code"), OCLC Symbol, Created. Other columns
 *      (Last access, Last login …) are ignored. The parsed rows are kept in a transient for this administrator (2 hours);
 *      the file itself is not stored.
 *   2. Preview: every row with what will happen — Create, Skip (the email already has an account) or Error — and notes:
 *      username replaced by the email address when it is invalid or taken, LOC code not in the library directory.
 *      The LOC code's library gives the home system (and the library name when the CSV has none). If the library
 *      directory (ILL database) can't be reached, nothing can be imported: LOC codes must be checkable.
 *      Rows whose LOC code isn't in the directory are skipped by default (or imported without a library, if chosen);
 *      LOC codes are checked again at import time, in case the directory changed after the preview.
 *   3. Import, BATCH rows per request (so a slow mail server can't hit the CDN / PHP time limit), continuing on its
 *      own until done. Each account: Library Staff (or the chosen role), approved, random password, the ILL profile
 *      fields, the original creation date; optionally WordPress's welcome email (branded, "Set your password", link
 *      lifetime from Users & Registration). Every account is recorded in Change History ("Account created").
 * Welcome emails are not held by Email test mode (account emails never are).
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class SEAL_Import_Users {

    const PAGE  = 'seal-ill-import';
    const BATCH = 15;
    const MAX   = 2000;   // rows per file

    /** CSV header (normalized) => field. */
    const COLUMNS = [
        'username' => 'login', 'user name' => 'login', 'login' => 'login', 'user login' => 'login',
        'email' => 'email', 'e mail' => 'email', 'email address' => 'email',
        'first name' => 'first', 'firstname' => 'first', 'given name' => 'first',
        'last name' => 'last', 'lastname' => 'last', 'surname' => 'last', 'family name' => 'last',
        'your institution' => 'inst', 'institution' => 'inst', 'library' => 'inst', 'library name' => 'inst',
        'loc location code' => 'loc', 'loc code' => 'loc', 'loc' => 'loc',
        'oclc symbol' => 'oclc', 'oclc' => 'oclc',
        'created' => 'created', 'member since' => 'created',
    ];

    /** Roles an import may give (never Administrator / ILL Administrator). */
    const ROLES = [ 'libstaff' => 'Library Staff', 'libsys' => 'Library System Staff' ];

    private static function key(): string { return 'seal_import_' . get_current_user_id(); }

    /** True when the library directory (ILL database) answers, so LOC codes can be checked. */
    public static function directory_ok(): bool {
        try {
            $q = SEAL_DB::query( 'SELECT 1 FROM %t LIMIT 1', SEAL_DB::$sealLIB );
            return (bool) $q;
        } catch ( Throwable $e ) {
            return false;
        }
    }

    /** The library for a LOC code: [ name, system ] or null when it isn't in the directory. Cached per request. */
    private static function library( string $loc ): ?array {
        static $cache = [];
        if ( ! array_key_exists( $loc, $cache ) ) {
            $l = SEAL_Functions::fetch_lib_profile( $loc );
            $cache[ $loc ] = ! empty( $l['ok'] ) ? [ (string) $l['Name'], (string) $l['system'] ] : null;
        }
        return $cache[ $loc ];
    }

    /** The screen: dispatches on the posted step. */
    public static function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        echo '<div class="wrap"><h1>' . esc_html( seal_config( 'system_name' ) ) . ' ILL — 📥 Import Users</h1>';
        $step = isset( $_POST['seal_import_step'] ) ? sanitize_key( wp_unslash( $_POST['seal_import_step'] ) ) : '';
        if ( $step !== '' ) { check_admin_referer( 'seal_import_' . $step ); }
        switch ( $step ) {
            case 'upload': self::do_upload(); break;
            case 'import': self::do_import(); break;
            case 'cancel': delete_transient( self::key() ); self::form(); break;
            default:       self::form();
        }
        echo '</div>';
    }

    // ── Step 1: upload ────────────────────────────────────────────────────────

    private static function form( string $error = '' ): void {
        if ( $error !== '' ) { echo '<div class="notice notice-error"><p>' . esc_html( $error ) . '</p></div>'; }
        echo '<p>Create accounts in bulk from a spreadsheet saved as CSV, for example when moving users from an older site. '
           . 'You will see a preview before anything is created.</p>'
           . '<p><strong>Columns</strong> (first row = headers, any order): <code>Username</code>, <code>Email</code>, <code>First Name</code>, <code>Last Name</code>, '
           . '<code>Institution</code> (or <code>Your Institution</code>), <code>LOC Location Code</code>, <code>OCLC Symbol</code>, <code>Created</code>. '
           . 'Only Email is required. Other columns are ignored.</p>'
           . '<form method="post" enctype="multipart/form-data">';
        wp_nonce_field( 'seal_import_upload' );
        echo '<input type="hidden" name="seal_import_step" value="upload">'
           . '<p><label for="seal_import_file"><strong>CSV file</strong></label><br><input type="file" id="seal_import_file" name="seal_import_file" accept=".csv,text/csv" required></p>'
           . '<p><button class="button button-primary">Upload and preview</button></p></form>';
    }

    /** Normalizes a header cell: lower case, "(optional)" and punctuation removed. */
    private static function norm( string $h ): string {
        $h = strtolower( preg_replace( '/^\xEF\xBB\xBF/', '', $h ) );
        $h = str_replace( '(optional)', '', $h );
        return trim( preg_replace( '/\s+/', ' ', preg_replace( '/[^a-z0-9]+/', ' ', $h ) ) );
    }

    /** Reads the CSV into rows of fields. @return array|string rows, or an error message */
    public static function parse( string $path ) {
        $fh = fopen( $path, 'r' );
        if ( ! $fh ) { return 'The file could not be read.'; }
        $head = fgetcsv( $fh, 0, ',', '"', '' );
        if ( ! $head ) { fclose( $fh ); return 'The file is empty.'; }
        $map = [];
        foreach ( $head as $i => $h ) {
            $f = self::COLUMNS[ self::norm( (string) $h ) ] ?? null;
            if ( $f && ! in_array( $f, $map, true ) ) { $map[ $i ] = $f; }
        }
        if ( ! in_array( 'email', $map, true ) ) { fclose( $fh ); return 'No Email column was found in the first row.'; }
        $rows = [];
        while ( ( $r = fgetcsv( $fh, 0, ',', '"', '' ) ) !== false ) {
            if ( count( array_filter( $r, static fn( $c ) => trim( (string) $c ) !== '' ) ) === 0 ) { continue; }
            $row = [];
            foreach ( $map as $i => $f ) { $row[ $f ] = trim( (string) ( $r[ $i ] ?? '' ) ); }
            $rows[] = $row;
            if ( count( $rows ) > self::MAX ) { fclose( $fh ); return 'The file has more than ' . self::MAX . ' rows. Split it into smaller files.'; }
        }
        fclose( $fh );
        return $rows ?: 'The file has headers but no rows.';
    }

    /** "Fri, 09/09/2022 - 10:25", "2022-09-09", "09/09/2022" … in the site's time zone; 0 when unreadable. */
    public static function created_ts( string $v ): int {
        $v = trim( preg_replace( '/^[A-Za-z]{3},\s*/', '', $v ) );
        if ( $v === '' ) { return 0; }
        foreach ( [ 'm/d/Y - H:i', 'm/d/Y H:i', 'm/d/Y', 'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d' ] as $fmt ) {
            $d = DateTime::createFromFormat( '!' . $fmt, $v, wp_timezone() );
            if ( $d && $d->format( $fmt ) === $v ) { return $d->getTimestamp() <= time() ? $d->getTimestamp() : 0; }
        }
        return 0;
    }

    /**
     * What will happen to each row: [ status create|skip|error, login, notes[], lib name, system code ] added to the row.
     * Checks are repeated at import time (another admin may have created one of the accounts in between).
     */
    public static function analyse( array $rows ): array {
        $seen = []; $logins = [];
        foreach ( $rows as &$r ) {
            $r += [ 'login' => '', 'email' => '', 'first' => '', 'last' => '', 'inst' => '', 'loc' => '', 'oclc' => '', 'created' => '' ];
            $r['email'] = strtolower( $r['email'] );
            $r['loc']   = strtoupper( preg_replace( '/\s+/', '', $r['loc'] ) );
            $r['notes'] = [];
            $r['status'] = 'create';
            if ( ! is_email( $r['email'] ) ) { $r['status'] = 'error'; $r['notes'][] = 'Not a valid email address.'; continue; }
            if ( isset( $seen[ $r['email'] ] ) ) { $r['status'] = 'error'; $r['notes'][] = 'The same email is on an earlier row.'; continue; }
            $seen[ $r['email'] ] = true;
            if ( $id = email_exists( $r['email'] ) ) {
                $u = get_userdata( $id );
                $r['status'] = 'skip'; $r['notes'][] = 'Already has an account' . ( $u ? ' (' . $u->user_login . ')' : '' ) . '.';
                continue;
            }
            $want  = sanitize_user( $r['login'], true );
            $login = $want;
            if ( $want === '' || $want !== $r['login'] || ! validate_username( $want ) || username_exists( $want ) || isset( $logins[ strtolower( $want ) ] ) ) {
                $login = $r['email'];
                if ( $r['login'] !== '' ) { $r['notes'][] = 'Username “' . $r['login'] . '” ' . ( username_exists( $want ) || isset( $logins[ strtolower( $want ) ] ) ? 'is taken' : 'is not allowed' ) . ', so the email address is the username.'; }
            }
            if ( username_exists( $login ) || isset( $logins[ strtolower( $login ) ] ) ) { $r['status'] = 'error'; $r['notes'][] = 'No free username.'; continue; }
            $logins[ strtolower( $login ) ] = true;
            $r['user_login'] = $login;
            $r['lib'] = ''; $r['sys'] = ''; $r['loc_bad'] = false;
            if ( $r['loc'] !== '' ) {
                $lib = self::library( $r['loc'] );
                if ( $lib ) { [ $r['lib'], $r['sys'] ] = $lib; }
                else { $r['loc_bad'] = true; $r['notes'][] = 'LOC ' . $r['loc'] . ' is not in the library directory.'; }
            } else {
                $r['notes'][] = 'No LOC code: add the library later.';
            }
            if ( $r['inst'] === '' ) { $r['inst'] = $r['lib']; }
            $r['ts'] = self::created_ts( $r['created'] );
        }
        unset( $r );
        return $rows;
    }

    private static function do_upload(): void {
        $f = $_FILES['seal_import_file'] ?? null;
        if ( ! $f || ! empty( $f['error'] ) || ! is_uploaded_file( (string) $f['tmp_name'] ) ) { self::form( 'Choose a CSV file to upload.' ); return; }
        if ( (int) $f['size'] > 2 * MB_IN_BYTES ) { self::form( 'The file is larger than 2 MB.' ); return; }
        if ( strtolower( pathinfo( (string) $f['name'], PATHINFO_EXTENSION ) ) !== 'csv' ) { self::form( 'The file must be a .csv file.' ); return; }
        $rows = self::parse( (string) $f['tmp_name'] );
        @unlink( (string) $f['tmp_name'] );
        if ( is_string( $rows ) ) { self::form( $rows ); return; }
        if ( ! self::directory_ok() ) {
            self::form( 'The library directory (ILL database) can\'t be reached, so LOC codes can\'t be checked. Check ILL System → Database, then upload the file again.' );
            return;
        }
        $rows = self::analyse( $rows );
        set_transient( self::key(), [ 'rows' => $rows, 'file' => sanitize_file_name( (string) $f['name'] ) ], 2 * HOUR_IN_SECONDS );
        self::preview( $rows );
    }

    // ── Step 2: preview ───────────────────────────────────────────────────────

    private static function preview( array $rows ): void {
        $n = array_count_values( array_column( $rows, 'status' ) ) + [ 'create' => 0, 'skip' => 0, 'error' => 0 ];
        $bad = count( array_filter( $rows, static fn( $r ) => $r['status'] === 'create' && ! empty( $r['loc_bad'] ) ) );
        $systems = SEAL_Functions::system_options();
        echo '<h2>Preview</h2><p><strong>' . (int) $n['create'] . '</strong> to create, <strong>' . (int) $n['skip'] . '</strong> skipped (already have an account), '
           . '<strong>' . (int) $n['error'] . '</strong> with errors (not imported). Nothing has been created yet.</p>';
        if ( $n['create'] ) {
            echo '<form method="post" style="background:#fff;border:1px solid #c3c4c7;padding:12px 16px;max-width:760px;margin:0 0 16px">';
            wp_nonce_field( 'seal_import_import' );
            echo '<input type="hidden" name="seal_import_step" value="import"><input type="hidden" name="seal_import_start" value="1">'
               . '<p><label for="seal_import_role"><strong>Role</strong></label> <select id="seal_import_role" name="seal_import_role">';
            foreach ( self::ROLES as $k => $label ) { echo '<option value="' . esc_attr( $k ) . '">' . esc_html( $label ) . '</option>'; }
            echo '</select></p>';
            if ( $bad ) {
                echo '<fieldset style="margin:0 0 12px"><legend><strong>' . (int) $bad . ' row' . ( $bad === 1 ? ' has a LOC code' : 's have LOC codes' ) . ' that aren\'t in the library directory</strong></legend>'
                   . '<label><input type="radio" name="seal_import_badloc" value="skip" checked> Don\'t import ' . ( $bad === 1 ? 'it' : 'them' ) . ' (fix the LOC code in the spreadsheet, or add the library first, then import the file again: accounts already created are skipped)</label><br>'
                   . '<label><input type="radio" name="seal_import_badloc" value="keep"> Import ' . ( $bad === 1 ? 'it' : 'them' ) . ' without a library (set it later on User Accounts)</label></fieldset>';
            }
            echo '<p><label><input type="checkbox" name="seal_import_send" value="1" checked> <strong>Email each person a “Set your password” link now</strong></label><br>'
               . '<span class="description">The link works for ' . esc_html( SEAL_Emails::link_life() ) . ' (Users &amp; Registration). These emails are sent even while Email test mode is on. '
               . 'Untick to create the accounts quietly; people can then use “Lost your password?” or you can send resets from the Users screen.</span></p>'
               . '<p><button class="button button-primary">Create ' . (int) $n['create'] . ' account' . ( $n['create'] === 1 ? '' : 's' ) . '</button></p></form>';
        }
        echo '<form method="post" style="margin:0 0 16px">';
        wp_nonce_field( 'seal_import_cancel' );
        echo '<input type="hidden" name="seal_import_step" value="cancel"><button class="button">Cancel and upload another file</button></form>';
        echo '<table class="widefat striped" style="max-width:1200px"><thead><tr><th scope="col">#</th><th scope="col">Result</th><th scope="col">Name</th><th scope="col">Username / Email</th>'
           . '<th scope="col">Library</th><th scope="col">Created</th><th scope="col">Notes</th></tr></thead><tbody>';
        $label = [ 'create' => [ 'Create', '#0a5c2e', '#d4edda' ], 'skip' => [ 'Skip', '#3c434a', '#e9ecef' ], 'error' => [ 'Error', '#8a1f1f', '#fbeaea' ],
                   'loc' => [ 'Check LOC', '#7a4b00', '#fcf0d9' ] ];
        foreach ( $rows as $i => $r ) {
            [ $t, $fg, $bg ] = $label[ $r['status'] === 'create' && ! empty( $r['loc_bad'] ) ? 'loc' : $r['status'] ];
            echo '<tr><td>' . ( $i + 2 ) . '</td><td><span style="background:' . $bg . ';color:' . $fg . ';padding:2px 8px;border-radius:12px;font-size:.85em">' . $t . '</span></td>'
               . '<td>' . esc_html( trim( $r['first'] . ' ' . $r['last'] ) ) . '</td><td>' . esc_html( $r['user_login'] ?? $r['login'] ) . '<br><small>' . esc_html( $r['email'] ) . '</small></td>'
               . '<td>' . esc_html( $r['inst'] ) . ( $r['loc'] !== '' ? '<br><small>' . esc_html( $r['loc'] ) . ( ! empty( $r['sys'] ) ? ' · ' . esc_html( $systems[ $r['sys'] ] ?? $r['sys'] ) : '' ) . '</small>' : '' ) . '</td>'
               . '<td>' . ( ! empty( $r['ts'] ) ? esc_html( wp_date( 'Y-m-d', (int) $r['ts'] ) ) : '<small>today</small>' ) . '</td>'
               . '<td>' . implode( '<br>', array_map( 'esc_html', $r['notes'] ) ) . '</td></tr>';
        }
        echo '</tbody></table><p class="description">Row numbers match the spreadsheet (row 1 is the headers).</p>';
    }

    // ── Step 3: import in batches ─────────────────────────────────────────────

    private static function do_import(): void {
        $job = get_transient( self::key() );
        if ( ! is_array( $job ) || empty( $job['rows'] ) ) { self::form( 'The preview has expired (2 hours). Upload the file again.' ); return; }
        if ( ! empty( $_POST['seal_import_start'] ) && ! isset( $job['pos'] ) ) {
            $role = sanitize_key( wp_unslash( $_POST['seal_import_role'] ?? 'libstaff' ) );
            $job['role'] = isset( self::ROLES[ $role ] ) ? $role : 'libstaff';
            $job['send'] = ! empty( $_POST['seal_import_send'] );
            $job['badloc'] = ( $_POST['seal_import_badloc'] ?? 'skip' ) === 'keep' ? 'keep' : 'skip';
            $job['pos']  = 0;
            $job['done'] = [ 'created' => 0, 'skipped' => 0, 'failed' => [], 'badloc' => [] ];
        }
        if ( ! isset( $job['pos'] ) ) { self::preview( $job['rows'] ); return; }
        @set_time_limit( 120 );
        if ( ! self::directory_ok() ) {   // LOC codes are re-checked below, so the directory must answer
            set_transient( self::key(), $job, 2 * HOUR_IN_SECONDS );
            echo '<div class="notice notice-error"><p>The library directory (ILL database) can\'t be reached right now, so the import has paused after '
               . (int) $job['pos'] . ' of ' . count( $job['rows'] ) . ' rows. Nothing is lost: select Continue to try again.</p></div><form method="post">';
            wp_nonce_field( 'seal_import_import' );
            echo '<input type="hidden" name="seal_import_step" value="import"><button class="button button-primary">Continue</button></form>';
            return;
        }

        $end = min( count( $job['rows'] ), $job['pos'] + self::BATCH );
        add_filter( 'seal_user_needs_approval', '__return_false' );
        for ( $i = $job['pos']; $i < $end; $i++ ) {
            $r = $job['rows'][ $i ];
            if ( $r['status'] !== 'create' ) { continue; }
            if ( email_exists( $r['email'] ) || username_exists( $r['user_login'] ) ) { $job['done']['skipped']++; continue; }
            // LOC code checked again now (the directory may have changed since the preview).
            $r['sys'] = ''; $r['lib'] = '';
            if ( $r['loc'] !== '' ) {
                $lib = self::library( $r['loc'] );
                if ( $lib ) {
                    [ $r['lib'], $r['sys'] ] = $lib;
                    if ( $r['inst'] === '' ) { $r['inst'] = $r['lib']; }
                } elseif ( $job['badloc'] !== 'keep' ) {
                    $job['done']['badloc'][] = 'Row ' . ( $i + 2 ) . ' (' . $r['email'] . '): LOC ' . $r['loc'] . ' is not in the library directory.';
                    continue;
                }
            }
            $first = sanitize_text_field( $r['first'] );
            $last  = sanitize_text_field( $r['last'] );
            $data = [
                'user_login' => $r['user_login'], 'user_email' => $r['email'], 'user_pass' => wp_generate_password( 24, true, true ),
                'first_name' => $first, 'last_name' => $last, 'display_name' => trim( $first . ' ' . $last ) ?: $r['user_login'],
                'role' => $job['role'],
            ];
            if ( ! empty( $r['ts'] ) ) { $data['user_registered'] = gmdate( 'Y-m-d H:i:s', (int) $r['ts'] ); }
            $uid = wp_insert_user( $data );
            if ( is_wp_error( $uid ) ) { $job['done']['failed'][] = 'Row ' . ( $i + 2 ) . ' (' . $r['email'] . '): ' . $uid->get_error_message(); continue; }
            $loc = preg_replace( '/[^A-Z0-9\-]/', '', $r['loc'] );   // LOC codes are letters, digits and hyphens
            foreach ( [ 'institution' => $r['inst'], 'address_loc_code' => $loc, 'oclc_symbol' => strtoupper( $r['oclc'] ), 'home_system' => $r['sys'] ] as $k => $v ) {
                if ( $v !== '' ) { update_user_meta( $uid, $k, sanitize_text_field( $v ) ); }
            }
            update_user_meta( $uid, 'seal_approval_status', 'approved' );
            update_user_meta( $uid, 'seal_created_by', get_current_user_id() . '|' . time() );
            update_user_meta( $uid, 'seal_imported', $job['file'] . '|' . time() );
            if ( $job['send'] ) { wp_new_user_notification( $uid, null, 'user' ); }
            $job['done']['created']++;
        }
        remove_filter( 'seal_user_needs_approval', '__return_false' );
        $job['pos'] = $end;

        if ( $end < count( $job['rows'] ) ) {
            set_transient( self::key(), $job, 2 * HOUR_IN_SECONDS );
            echo '<h2>Importing…</h2><p>Processed ' . (int) $end . ' of ' . count( $job['rows'] ) . ' rows (' . (int) $job['done']['created'] . ' accounts created so far). '
               . 'This continues on its own; please keep this page open.</p><form method="post" id="seal-import-next">';
            wp_nonce_field( 'seal_import_import' );
            echo '<input type="hidden" name="seal_import_step" value="import"><button class="button button-primary">Continue</button></form>'
               . '<script>setTimeout(function(){document.getElementById("seal-import-next").submit();},400);</script>';
            return;
        }

        delete_transient( self::key() );   // done: the uploaded data (personal data) is not kept
        $d = $job['done'];
        echo '<div class="notice notice-success"><p><strong>Import finished.</strong> ' . (int) $d['created'] . ' account' . ( $d['created'] === 1 ? '' : 's' ) . ' created'
           . ( $job['send'] ? ' and emailed a “Set your password” link' : '' ) . '.'
           . ( $d['skipped'] ? ' ' . (int) $d['skipped'] . ' skipped because an account appeared in the meantime.' : '' ) . '</p></div>';
        if ( $d['badloc'] ) {
            echo '<div class="notice notice-warning"><p>Not imported because the LOC code isn\'t in the library directory (fix the code or add the library, then import the same file again; '
               . 'accounts already created are skipped):</p><ul style="margin-left:18px;list-style:disc">' . implode( '', array_map( static fn( $m ) => '<li>' . esc_html( $m ) . '</li>', $d['badloc'] ) ) . '</ul></div>';
        }
        if ( $d['failed'] ) { echo '<div class="notice notice-error"><p>Not created:</p><ul style="margin-left:18px;list-style:disc">' . implode( '', array_map( static fn( $m ) => '<li>' . esc_html( $m ) . '</li>', $d['failed'] ) ) . '</ul></div>'; }
        echo '<p>Each new account is in <a href="' . esc_url( admin_url( 'admin.php?page=seal-ill-history&area=users' ) ) . '">Change History</a> and on '
           . '<a href="' . esc_url( home_url( '/adminlib-accounts' ) ) . '">User Accounts</a>. Accounts without a LOC code or home system need their library set before they can request.</p>'
           . '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ) . '">Import another file</a></p>';
    }
}
