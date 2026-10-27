<?php
/**
 * SEAL_Page_adminlib — ILL Admin Library Directory
 *
 * Handles the main library management interface for ILL Admins.
 * Actions: list (default), add (1), edit (2), delete (3), mass-suspend (5).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class SEAL_Page_adminlib extends SEAL_Page_Base {

    /**
     * Entry point: SEAL_Router calls this to print the page into the theme's content area. ILL Administrators
     * list, filter, add, edit, suspend and delete library records (Manage All Libraries).
     */
    public function render(): void {
        $action = $this->req_int( 'action', 0 );

        switch ( $action ) {
            case 1: $this->render_add();    break;
            case 2: $this->render_edit();   break;
            case 3: $this->render_delete(); break;
            case 5: $this->render_mass_suspend(); break;
            default: $this->render_list(); break;
        }
    }

    // ── List view ─────────────────────────────────────────────────────────────

    /**
     * The library directory with filters (system, name, alias, LOC, ILL email, participation, suspension,
     * missing alias or email) and paging.
     */
    private function render_list(): void {
        $db      = SEAL_DB::get();
        $lib_tbl = SEAL_DB::$sealLIB;
        $self    = esc_url( home_url( '/adminlib' ) );
        $sys     = esc_html( seal_config( 'system_name' ) );
        $systems = SEAL_Functions::system_options();

        // ── Read filters (POST on submit, GET params cleared on reload) ──────
        $is_post        = $this->is_post();
        $firstpass      = $this->req_str( 'firstpass', 'yes' );
        $f_aliasblank   = $is_post ? $this->post_str( 'filter_aliasblank' )   : '';
        $f_illemailblank= $is_post ? $this->post_str( 'filter_illemailblank') : '';
        $f_illpart      = $is_post ? $this->post_str( 'filter_illpart' )      : '';
        $f_suspend      = $is_post ? $this->post_str( 'filter_suspend' )      : '';
        $f_alllibs      = $is_post ? $this->post_str( 'filter_all_libs', '' ) : 'yes';
        $f_system       = $is_post ? $this->post_str( 'filter_system' )       : '';
        $f_library      = $is_post ? $this->post_str( 'library' )             : '';
        $f_alias        = $is_post ? $this->post_str( 'filter_alias' )        : '';
        $f_loc          = $is_post ? $this->post_str( 'loc' )                 : '';
        $f_illemail     = $is_post ? $this->post_str( 'filter_illemail' )     : '';
        $f_numresults   = $is_post ? $this->post_str( 'filter_numresults', '25' ) : '25';
        $f_offset_page  = $is_post ? (int) $this->post_str( 'filter_offset', '0' ) : 0;

        // ── Build SQL ─────────────────────────────────────────────────────────
        // "All Libraries" means no yes/no filters: it and the four checkboxes exclude each other (a small script does the
        // same in the browser). With none of them ticked the list is all libraries, so show All Libraries ticked.
        if ( $f_alllibs === 'yes' ) { $f_aliasblank = $f_illemailblank = $f_illpart = $f_suspend = ''; }
        if ( $f_aliasblank . $f_illemailblank . $f_illpart . $f_suspend === '' ) { $f_alllibs = 'yes'; }
        $where = '1=1';
        if ( $f_aliasblank === 'yes' ) {
            $where .= " AND (TRIM(IFNULL(`alias`,''))='' )";
        }
        if ( $f_illemailblank === 'yes' ) {
            $where .= " AND (TRIM(IFNULL(`ill_email`,''))='' )";
        }
        // Participant / suspended are numbers (1/0) here but 'Yes'/'No' text in older tables. Never compare a number column
        // with 'Yes': MySQL turns 'Yes' into 0, so `participant`='Yes' matched every non-participant. CAST AS CHAR works for both.
        if ( $f_illpart === 'yes' ) {
            $where .= " AND (CAST(`participant` AS CHAR) IN ('1','Yes'))";
        }
        if ( $f_suspend === 'yes' ) {
            $where .= " AND (CAST(`suspend` AS CHAR) IN ('1','Yes'))";
        }
        if ( $f_system !== '' ) {
            $where .= " AND `system`='" . SEAL_DB::esc( $f_system ) . "'";
        }
        if ( $f_library !== '' ) {
            $where .= " AND `Name` LIKE '%" . SEAL_DB::esc( $f_library ) . "%'";
        }
        if ( $f_alias !== '' ) {
            $where .= " AND `alias` LIKE '%" . SEAL_DB::esc( $f_alias ) . "%'";
        }
        if ( $f_loc !== '' ) {
            $where .= " AND `loc` LIKE '%" . SEAL_DB::esc( $f_loc ) . "%'";
        }
        if ( $f_illemail !== '' ) {
            $where .= " AND `ill_email` LIKE '%" . SEAL_DB::esc( $f_illemail ) . "%'";
        }

        $base_sql = "SELECT * FROM `{$lib_tbl}` WHERE {$where}";

        // ── Count ─────────────────────────────────────────────────────────────
        $count_r = $db->query( $base_sql );
        $total   = $count_r ? $count_r->num_rows : 0;

        // ── Pagination ────────────────────────────────────────────────────────
        $num_all   = ( $f_numresults === 'all' );
        $per_page  = $num_all ? $total : max( 1, (int) $f_numresults );
        $per_page  = ( $per_page < 1 ) ? 25 : $per_page;
        $max_pages = $num_all ? 1 : (int) ceil( $total / $per_page );
        $max_pages = max( 1, $max_pages );
        $cur_page  = min( $f_offset_page, $max_pages - 1 );
        $offset    = $cur_page * $per_page;

        $limit_sql = $num_all ? '' : " LIMIT {$offset}, {$per_page}";
        $rows      = $db->query( $base_sql . " ORDER BY `Name` ASC" . $limit_sql );
        ?>

        <div class='adminlib-wrapper'>

        <?php /* ── Header ── */ ?>
        <div class='adminlib-header'>
            <h3><?php echo $sys; ?> — Library Directory Admin</h3>
            <div class='actions'>
                <a class='btn-secondary' href='<?php echo $self; ?>'>Back to Library List</a>
                <a class='btn-secondary' href='<?php echo esc_url( add_query_arg( 'action', 1, $self ) ); ?>'>Add Library</a>
                <a class='btn-secondary' href='<?php echo esc_url( add_query_arg( 'action', 5, $self ) ); ?>'>Mass Suspend/Activate</a>
                <a class='btn-secondary' target='_blank' rel='noopener' href='<?php echo esc_url( home_url( '/export' ) ); ?>'>Export CSV<span class="seal-sr-only"> (opens in a new tab)</span></a>
            </div>
        </div>

        <?php /* ── Filter bar ── */ ?>
        <form action='<?php echo $self; ?>/' method='post' class='filter-bar'>
            <input type='hidden' name='firstpass' value='no'>
            <div class='filter-grid'>
                <div class='form-group'>
                    <label><input type='checkbox' name='filter_aliasblank' value='yes'<?php checked( $f_aliasblank, 'yes' ); ?>> Missing alias</label>
                </div>
                <div class='form-group'>
                    <label><input type='checkbox' name='filter_illemailblank' value='yes'<?php checked( $f_illemailblank, 'yes' ); ?>> Missing ILL Email</label>
                </div>
                <div class='form-group'>
                    <label><input type='checkbox' name='filter_illpart' value='yes'<?php checked( $f_illpart, 'yes' ); ?>> ILL Participant</label>
                </div>
                <div class='form-group'>
                    <label><input type='checkbox' name='filter_suspend' value='yes'<?php checked( $f_suspend, 'yes' ); ?>> ILL Suspended</label>
                </div>
                <div class='form-group'>
                    <label><input type='checkbox' name='filter_all_libs' value='yes'<?php checked( $f_alllibs, 'yes' ); ?>> All Libraries</label>
                </div>
                <div class='form-group'>
                    <label for='fld_filter_system'>Library System</label>
                    <select id='fld_filter_system' name='filter_system'>
                        <option value='' <?php selected( $f_system, '' ); ?>>All</option>
                        <?php foreach ( $systems as $code => $name ): ?>
                        <option value='<?php echo esc_attr( $code ); ?>' <?php selected( $f_system, $code ); ?>><?php echo esc_html( $name ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class='form-group'>
                    <label for='fld_library'>Library Name</label>
                    <input id='fld_library' name='library' type='text' value='<?php echo esc_attr( $f_library ); ?>'>
                </div>
                <div class='form-group'>
                    <label for='fld_filter_alias'>Library Alias</label>
                    <input id='fld_filter_alias' name='filter_alias' type='text' value='<?php echo esc_attr( $f_alias ); ?>'>
                </div>
                <div class='form-group'>
                    <label for='fld_loc'>ILL Code (LOC)</label>
                    <input id='fld_loc' name='loc' type='text' value='<?php echo esc_attr( $f_loc ); ?>'>
                </div>
                <div class='form-group'>
                    <label for='fld_filter_illemail'>ILL Email</label>
                    <input id='fld_filter_illemail' name='filter_illemail' type='text' value='<?php echo esc_attr( $f_illemail ); ?>'>
                </div>
                <div class='form-group'>
                    <label for='fld_filter_numresults'>Results per page</label>
                    <select id='fld_filter_numresults' name='filter_numresults'>
                        <?php foreach ( [ '25', '50', '100', 'all' ] as $n ): ?>
                        <option value='<?php echo esc_attr( $n ); ?>' <?php selected( $f_numresults, $n ); ?>><?php echo esc_html( $n === 'all' ? 'All' : $n ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class='form-group'>
                    <label for='fld_filter_offset'>Page</label>
                    <select id='fld_filter_offset' name='filter_offset'>
                        <?php for ( $p = 0; $p < $max_pages; $p++ ): ?>
                        <option value='<?php echo $p; ?>' <?php selected( $cur_page, $p ); ?>><?php echo ( $p + 1 ); ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
            </div>
            <div class='filter-actions'>
                <button class='btn-primary'>Update</button>
                <a class='btn-secondary' href='<?php echo $self; ?>'>Clear</a>
            </div>
        </form>
        <script>
        /* "All Libraries" and the four yes/no filters exclude each other; with none ticked, All Libraries comes back. */
        (function () {
            var all = document.querySelector('input[name=filter_all_libs]');
            var others = ['filter_aliasblank', 'filter_illemailblank', 'filter_illpart', 'filter_suspend'].map(function (n) { return document.querySelector('input[name=' + n + ']'); }).filter(Boolean);
            if (!all) { return; }
            all.addEventListener('change', function () { if (all.checked) { others.forEach(function (o) { o.checked = false; }); } else if (!others.some(function (o) { return o.checked; })) { all.checked = true; } });
            others.forEach(function (o) { o.addEventListener('change', function () { if (o.checked) { all.checked = false; } else if (!others.some(function (x) { return x.checked; })) { all.checked = true; } }); });
        })();
        </script>

        <?php /* ── Results count + table ── */ ?>
        <div class='pagination-info'>Total Results: <?php echo $total; ?></div>

        <div class='seal-table-wrap'>
        <table class='rh-table'>
            <thead><tr>
                <th scope="col">Library</th>
                <th class='col-alias' scope="col">Alias</th>
                <th scope="col">Participant</th>
                <th scope="col">Suspended</th>
                <th scope="col">System</th>
                <th scope="col">OCLC</th>
                <th scope="col">LOC</th>
                <th scope="col">Action</th>
            </tr></thead>
            <tbody>
            <?php if ( $rows && $rows->num_rows > 0 ):
                while ( $r = $rows->fetch_assoc() ):
                    $recnum   = (int) ( $r['recnum'] ?? 0 );
                    $part_val = $r['participant'] ?? 0;
                    $part_yes = ( $part_val == 1 || $part_val === 'Yes' );
                    $susp_val = $r['suspend'] ?? 0;
                    $susp_yes = ( $susp_val == 1 || $susp_val === 'Yes' );
                    $edit_url = esc_url( add_query_arg( [ 'action' => 2, 'librecnumb' => $recnum ], $self ) );
                    $del_url  = esc_url( add_query_arg( [ 'action' => 3, 'librecnumb' => $recnum ], $self ) );
            ?>
            <tr>
                <td><?php echo esc_html( $r['Name'] ?? '' ); ?></td>
                <td class='col-alias'><?php echo esc_html( $r['alias'] ?? '' ); ?></td>
                <td><?php echo $part_yes ? 'Yes' : 'No'; ?></td>
                <td><?php echo $susp_yes ? 'Yes' : 'No'; ?></td>
                <td><?php echo esc_html( $r['system'] ?? '' ); ?></td>
                <td><?php echo esc_html( $r['oclc'] ?? '' ); ?></td>
                <td><?php echo esc_html( $r['loc'] ?? '' ); ?></td>
                <td class='table-actions'>
                    <?php if ( $recnum > 0 ): ?>
                    <a class='edit'   href='<?php echo $edit_url; ?>'>Edit</a>
                    <a class='delete' href='<?php echo $del_url; ?>'>Delete</a>
                    <?php else: ?>
                    <a class='edit'   href='<?php echo esc_url( add_query_arg( 'loc', $r['loc'] ?? '', home_url( '/libprofile' ) ) ); ?>'>Edit</a>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endwhile;
            else: ?>
            <tr><td colspan='8'>No libraries found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        </div>

        <?php /* ── Pagination (Prev / page indicator / Next) ── */
        if ( $f_numresults !== 'all' && $max_pages > 1 ):
            // Hidden fields that replay the current filter state on each page jump.
            $hidden = function( int $page ) use (
                $f_aliasblank, $f_illemailblank, $f_illpart, $f_suspend, $f_alllibs,
                $f_system, $f_library, $f_alias, $f_loc, $f_illemail, $f_numresults
            ): string {
                $fields = [
                    'firstpass'           => 'no',
                    'filter_aliasblank'   => $f_aliasblank,
                    'filter_illemailblank'=> $f_illemailblank,
                    'filter_illpart'      => $f_illpart,
                    'filter_suspend'      => $f_suspend,
                    'filter_all_libs'     => $f_alllibs,
                    'filter_system'       => $f_system,
                    'library'             => $f_library,
                    'filter_alias'        => $f_alias,
                    'loc'                 => $f_loc,
                    'filter_illemail'     => $f_illemail,
                    'filter_numresults'   => $f_numresults,
                    'filter_offset'       => (string) $page,
                ];
                $out = '';
                foreach ( $fields as $k => $v ) {
                    if ( $v === '' ) { continue; }
                    $out .= "<input type='hidden' name='" . esc_attr( $k ) . "' value='" . esc_attr( $v ) . "'>";
                }
                return $out;
            };
        ?>
        <div class='seal-pagination'>
            <?php if ( $cur_page > 0 ): ?>
            <form method='post' action='<?php echo $self; ?>/' style='display:inline'>
                <?php echo $hidden( $cur_page - 1 ); ?>
                <button class='btn-secondary' type='submit'>← Previous</button>
            </form>
            <?php else: ?>
            <span class='btn-secondary is-disabled'>← Previous</span>
            <?php endif; ?>

            <span class='seal-page-indicator'>Page <?php echo ( $cur_page + 1 ); ?> of <?php echo $max_pages; ?></span>

            <?php if ( $cur_page < $max_pages - 1 ): ?>
            <form method='post' action='<?php echo $self; ?>/' style='display:inline'>
                <?php echo $hidden( $cur_page + 1 ); ?>
                <button class='btn-primary' type='submit'>Next →</button>
            </form>
            <?php else: ?>
            <span class='btn-primary is-disabled'>Next →</span>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        </div><?php // .adminlib-wrapper
    }

    // ── Add library ───────────────────────────────────────────────────────────

    /** A date typed as YYYY-MM-DD (or anything strtotime reads) as YYYY-MM-DD, or null. */
    private static function ymd_or_null( string $d ): ?string {
        $ts = trim( $d ) !== '' ? strtotime( str_replace( '-', '/', trim( $d ) ) ) : false;
        return $ts ? date( 'Y-m-d', $ts ) : null;
    }

    /** Loan and delivery questions on the form: [ form field => [ column, label ] ]. Shown only if the column exists. */
    private const LOAN_FIELDS = [
        'book'     => [ 'book_loan',        'Books' ],
        'journal'  => [ 'periodical_loan',  'Periodicals' ],
        'av'       => [ 'av_loan',          'AV Materials' ],
        'ebook'    => [ 'ebook_request',    'eBooks' ],
        'ejournal' => [ 'ejournal_request', 'eJournals' ],
    ];
    private const DELIVERY_FIELDS = [
        'lbsyscourier' => [ 'lbsyscourier', 'Library System Courier' ],
        'lbUSPS'       => [ 'lbUSPS',       'US Mail (USPS)' ],
        'lbEmpire'     => [ 'lbEmpire',     'Empire Delivery' ],
    ];

    /**
     * ILLiad settings from the edit form: ILLiad library yes/no, Web Platform address, loan days and the API key. Only
     * Administrators and ILL Administrators (seal_admin) may change them. The key is write-only: a new one replaces the
     * stored key (encrypted with ILL_DATA_KEY — SEAL_Secret), "Remove" clears it, blank keeps it. $note gets a warning to show.
     */
    private static function illiad_sets( string &$note ): array {
        if ( ! current_user_can( 'seal_admin' ) || ! isset( $_POST['illiad_present'] ) ) { return []; }
        $sets = [];
        $sets['Illiad'] = ( $_POST['illiad'] ?? '0' ) === '1' ? '1' : '0';
        $url = esc_url_raw( trim( (string) wp_unslash( $_POST['illiad_url'] ?? '' ) ), [ 'https' ] );
        if ( trim( (string) ( $_POST['illiad_url'] ?? '' ) ) !== '' && $url === '' ) { $note .= ' The ILLiad address was not saved: it must start with https://.'; }
        else { $sets['IlliadURL'] = $url; }
        $days = (int) ( $_POST['illiad_days'] ?? 0 );
        if ( $days > 0 ) { $sets['IlliadDATE'] = (string) min( 365, $days ); }
        $new = trim( (string) wp_unslash( $_POST['illiad_key'] ?? '' ) );
        if ( ! empty( $_POST['illiad_key_remove'] ) ) {
            $sets['APIkey'] = '';
        } elseif ( $new !== '' ) {
            if ( SEAL_Secret::key() ) {
                $sets['APIkey'] = SEAL_Secret::seal( preg_replace( '/[\r\n]/', '', $new ) );
            } else {
                $sets['APIkey'] = preg_replace( '/[\r\n]/', '', $new );
                $note .= ' The API key was saved but NOT encrypted: ILL_DATA_KEY is not set in wp-config.php (see ILL System → Database).';
            }
        }
        return $sets;
    }

    /** Column => value for a library save. SEAL_DB::*_existing() drops columns this install's table doesn't have. */
    private static function lib_sets( callable $n, string $part, string $suspend, ?string $enddate ): array {
        $yn   = static fn( string $k ): string => $n( $k ) === 'Yes' ? 'Yes' : 'No';
        $sets = [
            'Name' => $n( 'libname' ), 'loc' => $n( 'loc' ), 'system' => $n( 'filter_system' ), 'ill_email' => $n( 'libemail' ),
            'alias' => $n( 'alias' ), 'phone' => $n( 'phone' ), 'address1' => $n( 'address1' ), 'address2' => $n( 'address2' ),
            'address3' => $n( 'address3' ), 'oclc' => $n( 'oclc' ), 'participant' => (int) $part, 'suspend' => (int) $suspend,
            'SuspendDateEnd' => $enddate, 'ModifyDate' => current_time( 'mysql' ), 'ModEmail' => wp_get_current_user()->user_email,
        ];
        foreach ( self::LOAN_FIELDS + self::DELIVERY_FIELDS as $field => [ $col ] ) {
            if ( isset( $_POST[ $field ] ) ) { $sets[ $col ] = $yn( $field ); }   // only fields that were on the form
        }
        return $sets;
    }

    /** Save failed: say so, with the database's reason for administrators (it also goes to the PHP log). */
    private function save_failed( string $what ): void {
        $why = current_user_can( 'seal_admin' ) ? ' Database said: ' . esc_html( SEAL_DB::last_error() ) : '';
        $this->alert( 'The library could not be ' . $what . '. Nothing was changed.' . $why, 'error' );
        echo '<p><a href="' . esc_url( home_url( '/adminlib' ) ) . '" class="btn-secondary">&larr; Back to Library List</a></p>';
    }

    /** The form for adding a library. */
    private function render_add(): void {
        $self    = esc_url( home_url( '/adminlib' ) );
        $sys     = esc_html( seal_config( 'system_name' ) );
        $systems = SEAL_Functions::system_options();

        if ( $this->is_post() ) {
            $this->verify_nonce( 'seal_adminlib_add' );
            $lib_tbl = SEAL_DB::$sealLIB;
            $n = fn( $k ) => sanitize_text_field( wp_unslash( $_POST[ $k ] ?? '' ) );

            $suspend = in_array( $n( 'suspend' ), [ '0', '1' ], true ) ? $n( 'suspend' ) : '0';
            $part    = in_array( $n( 'participant' ), [ '0', '1' ], true ) ? $n( 'participant' ) : '0';
            $enddate = $suspend === '1' ? ( self::ymd_or_null( $n( 'enddate' ) ) ?? date( 'Y-m-d', strtotime( '+7 days' ) ) ) : null;   // null = leave the stored date (required column)

            $note = '';
            $ok   = SEAL_DB::insert_existing( $lib_tbl, self::lib_sets( $n, $part, $suspend, $enddate ) + self::illiad_sets( $note ) );

            if ( $ok === false ) { $this->save_failed( 'added' ); return; }
            $this->alert( 'Library added successfully.' . esc_html( $note ), $note === '' ? 'success' : 'warning' );
            echo '<p><a href="' . $self . '" class="btn-secondary">← Back to Library List</a></p>';
            return;
        }

        $this->render_lib_form( 'add', [], $systems );
    }

    // ── Edit library ──────────────────────────────────────────────────────────

    /** The edit form for one library record. */
    private function render_edit(): void {
        $lib_tbl = SEAL_DB::$sealLIB;
        $self    = esc_url( home_url( '/adminlib' ) );
        $recnum  = $this->req_int( 'librecnumb', 0 );
        $systems = SEAL_Functions::system_options();

        if ( $recnum < 1 ) {
            $this->alert( 'Invalid library record.', 'error' );
            return;
        }

        if ( $this->is_post() ) {
            $this->verify_nonce( 'seal_adminlib_edit' );
            $n = fn( $k ) => sanitize_text_field( wp_unslash( $_POST[ $k ] ?? '' ) );

            $suspend = in_array( $n( 'suspend' ), [ '0', '1' ], true ) ? $n( 'suspend' ) : '0';
            $part    = in_array( $n( 'participant' ), [ '0', '1' ], true ) ? $n( 'participant' ) : '0';
            // Not suspended: don't touch SuspendDateEnd (CDLC's is a required DATE; '' and NULL are both rejected in
            // strict mode). Suspended without a date: one week, as on Library Profile.
            $enddate = $suspend === '1' ? ( self::ymd_or_null( $n( 'enddate' ) ) ?? date( 'Y-m-d', strtotime( '+7 days' ) ) ) : null;   // null = leave the stored date (required column)

            $note = '';
            $ok   = SEAL_DB::update_existing( $lib_tbl, self::lib_sets( $n, $part, $suspend, $enddate ) + self::illiad_sets( $note ), '`recnum`=%d', $recnum );

            if ( $ok === false ) { $this->save_failed( 'updated' ); return; }
            $this->alert( 'Library updated successfully.' . esc_html( $note ), $note === '' ? 'success' : 'warning' );
            echo '<p><a href="' . $self . '" class="btn-secondary">← Back to Library List</a></p>';
            return;
        }

        $q   = SEAL_DB::query( "SELECT * FROM `{$lib_tbl}` WHERE `recnum`=%d LIMIT 1", $recnum );
        $row = ( $q && $q->num_rows ) ? $q->fetch_assoc() : [];

        if ( empty( $row ) ) {
            $this->alert( 'Library record not found.', 'error' );
            return;
        }

        $this->render_lib_form( 'edit', $row, $systems, $recnum );
    }

    // ── Delete library ────────────────────────────────────────────────────────

    /** Asks for confirmation before deleting a library record. */
    private function render_delete(): void {
        $lib_tbl = SEAL_DB::$sealLIB;
        $self    = esc_url( home_url( '/adminlib' ) );
        $recnum  = $this->req_int( 'librecnumb', 0 );

        if ( $recnum < 1 ) {
            $this->alert( 'Invalid library record.', 'error' );
            return;
        }

        if ( $this->is_post() ) {
            $this->verify_nonce( 'seal_adminlib_delete' );
            if ( isset( $_POST['confirm_delete'] ) ) {
                SEAL_DB::query( "DELETE FROM `{$lib_tbl}` WHERE `recnum`=%d LIMIT 1", $recnum );
                $this->alert( 'Library deleted.', 'success' );
                echo '<p><a href="' . $self . '" class="btn-secondary">← Back to Library List</a></p>';
                return;
            }
            // cancelled
            wp_safe_redirect( $self );
            exit;
        }

        // Show confirmation
        $q   = SEAL_DB::query( "SELECT `Name`, `loc` FROM `{$lib_tbl}` WHERE `recnum`=%d LIMIT 1", $recnum );
        $row = ( $q && $q->num_rows ) ? $q->fetch_assoc() : [];
        $lib_name = esc_html( $row['Name'] ?? "Record #{$recnum}" );
        ?>
        <div class='adminlib-wrapper'>
        <div class='adminlib-header'>
            <h3>Delete Library</h3>
        </div>
        <div class='adminlib-panel'>
            <h4>Confirm Deletion</h4>
            <p>Are you sure you want to permanently delete <strong><?php echo $lib_name; ?></strong>? This action cannot be undone.</p>
            <form method='post'>
                <?php wp_nonce_field( 'seal_adminlib_delete', 'seal_nonce' ); ?>
                <input type='hidden' name='confirm_delete' value='1'>
                <button type='submit' class='btn-danger'>Yes, Delete Library</button>
                &nbsp;<a href='<?php echo $self; ?>' class='btn-secondary'>Cancel</a>
            </form>
        </div>
        </div>
        <?php
    }

    // ── Mass suspend/activate ─────────────────────────────────────────────────

    /**
     * Suspend or reactivate every library in a chosen library system at once (posts to /status-confirmation).
     */
    private function render_mass_suspend(): void {
        $self = esc_url( home_url( '/adminlib' ) );
        ?>
        <div class='adminlib-wrapper'>
        <div class='adminlib-header'>
            <h3>Mass Suspend / Activate</h3>
        </div>
        <div class='adminlib-panel'>
            <h4>Bulk Status Change</h4>
            <p>Select an action to apply across all libraries in a system:</p>
            <p>
                <a href='<?php echo esc_url( add_query_arg( [ 'task' => 'suspend', 'scope' => 'all' ], home_url( '/status-confirmation' ) ) ); ?>'
                   class='btn-danger'>Suspend All Libraries</a>
                &nbsp;
                <a href='<?php echo esc_url( add_query_arg( [ 'task' => 'activate', 'scope' => 'all' ], home_url( '/status-confirmation' ) ) ); ?>'
                   class='btn-primary'>Activate All Libraries</a>
            </p>
            <p><a href='<?php echo $self; ?>' class='btn-secondary'>← Back to Library List</a></p>
        </div>
        </div>
        <?php
    }

    // ── Shared Add/Edit form ──────────────────────────────────────────────────

    /**
     * The library fields shared by the add and edit forms; loan and delivery questions only appear for columns
     * this install's table has.
     */
    private function render_lib_form( string $mode, array $row, array $systems, int $recnum = 0 ): void {
        $self      = esc_url( home_url( '/adminlib' ) );
        $nonce_act = ( $mode === 'add' ) ? 'seal_adminlib_add' : 'seal_adminlib_edit';
        $title     = ( $mode === 'add' ) ? 'Add New Library' : 'Edit Library: ' . esc_html( $row['Name'] ?? '' );
        $v         = fn( $k, $d = '' ) => esc_attr( $row[ $k ] ?? $d );
        $yn_sel    = fn( $fld, $yes_val ) => selected( $row[ $fld ] ?? '', $yes_val, false );

        $suspend_val = ( ( $row['suspend'] ?? 0 ) == 1 || ( $row['suspend'] ?? '' ) === 'Yes' ) ? '1' : '0';
        $part_val    = ( ( $row['participant'] ?? 0 ) == 1 || ( $row['participant'] ?? '' ) === 'Yes' ) ? '1' : '0';
        ?>
        <div class='adminlib-wrapper'>
        <div class='adminlib-header'>
            <h3><?php echo $title; ?></h3>
            <div class='actions'>
                <a class='btn-secondary' href='<?php echo $self; ?>'>← Back to Library List</a>
            </div>
        </div>
        <div class='adminlib-panel'>
            <form method='post'>
                <?php wp_nonce_field( $nonce_act, 'seal_nonce' ); ?>
                <?php if ( $recnum > 0 ): ?>
                <input type='hidden' name='librecnumb' value='<?php echo $recnum; ?>'>
                <?php endif; ?>

                <h4>Basic Information</h4>
                <div class='form-grid'>
                    <div class='form-group'>
                        <label for='fld_libname'>Library Name <span style='color:#b32d2e'>*</span></label>
                        <input type='text' id='fld_libname' name='libname' value='<?php echo $v('Name'); ?>' required>
                    </div>
                    <div class='form-group'>
                        <label for='fld_loc'>LOC / ILL Code</label>
                        <input type='text' id='fld_loc' name='loc' value='<?php echo $v('loc'); ?>'>
                    </div>
                    <div class='form-group'>
                        <label for='fld_filter_system'>Library System</label>
                        <select id='fld_filter_system' name='filter_system'>
                            <option value=''>-- Select --</option>
                            <?php foreach ( $systems as $code => $name ): ?>
                            <option value='<?php echo esc_attr($code); ?>' <?php selected( $row['system'] ?? '', $code ); ?>><?php echo esc_html($name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class='form-group'>
                        <label for='fld_oclc'>OCLC Symbol</label>
                        <input type='text' id='fld_oclc' name='oclc' value='<?php echo $v('oclc'); ?>'>
                    </div>
                    <div class='form-group full-row'>
                        <label for='fld_libemail'>ILL Email</label>
                        <input type='text' id='fld_libemail' name='libemail' value='<?php echo $v('ill_email'); ?>'>
                        <small style='color:#666'>Separate multiple addresses with commas or semicolons.</small>
                    </div>
                    <div class='form-group full-row'>
                        <label for='fld_alias'>Catalog Aliases</label>
                        <input type='text' id='fld_alias' name='alias' value='<?php echo $v('alias'); ?>'>
                        <small style='color:#666'>Comma-separated. E.g. "Fishkill Adult,Fishkill Children,Fishkill YA"</small>
                    </div>
                </div>

                <h4 style='margin-top:20px;'>Contact &amp; Address</h4>
                <div class='form-grid'>
                    <div class='form-group'>
                        <label for='fld_phone'>Phone</label>
                        <input type='text' id='fld_phone' name='phone' value='<?php echo $v('phone'); ?>'>
                    </div>
                    <div class='form-group'>
                        <label for='fld_address1'>Address Line 1</label>
                        <input type='text' id='fld_address1' name='address1' value='<?php echo $v('address1'); ?>'>
                    </div>
                    <div class='form-group'>
                        <label for='fld_address2'>Address Line 2</label>
                        <input type='text' id='fld_address2' name='address2' value='<?php echo $v('address2'); ?>'>
                    </div>
                    <div class='form-group'>
                        <label for='fld_address3'>City, State Zip</label>
                        <input type='text' id='fld_address3' name='address3' value='<?php echo $v('address3'); ?>'>
                    </div>
                </div>

                <h4 style='margin-top:20px;'>ILL Participation &amp; Status</h4>
                <div class='form-grid'>
                    <div class='form-group'>
                        <label for='fld_participant'>ILL Participant?</label>
                        <select id='fld_participant' name='participant'>
                            <option value='1' <?php selected( $part_val, '1' ); ?>>Yes</option>
                            <option value='0' <?php selected( $part_val, '0' ); ?>>No</option>
                        </select>
                    </div>
                    <div class='form-group'>
                        <label for='fld_suspend'>Suspended?</label>
                        <select id='fld_suspend' name='suspend'>
                            <option value='0' <?php selected( $suspend_val, '0' ); ?>>No</option>
                            <option value='1' <?php selected( $suspend_val, '1' ); ?>>Yes</option>
                        </select>
                    </div>
                    <div class='form-group'>
                        <label for='datepicker_adminlib'>Suspend Until (YYYY-MM-DD)</label>
                        <input type='text' id='datepicker_adminlib' name='enddate' value='<?php echo $v('SuspendDateEnd'); ?>' autocomplete='off'>
                    </div>
                </div>

                <?php $shown = array_filter( self::LOAN_FIELDS, static fn( $f ) => SEAL_DB::has_column( SEAL_DB::$sealLIB, $f[0] ) ); if ( $shown ) : ?>
                <h4 style='margin-top:20px;'>Loan Types</h4>
                <div class='form-grid'>
                    <?php
                    foreach ( self::LOAN_FIELDS as $post_key => [ $db_key, $label ] ): if ( ! SEAL_DB::has_column( SEAL_DB::$sealLIB, $db_key ) ) { continue; } ?>
                    <div class='form-group'>
                        <label for='fld_<?php echo esc_attr( $post_key ); ?>'><?php echo esc_html( $label ); ?></label>
                        <select id='fld_<?php echo esc_attr( $post_key ); ?>' name='<?php echo esc_attr( $post_key ); ?>'>
                            <option value='Yes' <?php selected( $row[ $db_key ] ?? 'No', 'Yes' ); ?>>Yes</option>
                            <option value='No'  <?php selected( $row[ $db_key ] ?? 'No', 'No' );  ?>>No</option>
                        </select>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <?php $shown = array_filter( self::DELIVERY_FIELDS, static fn( $f ) => SEAL_DB::has_column( SEAL_DB::$sealLIB, $f[0] ) ); if ( $shown ) : ?>
                <h4 style='margin-top:20px;'>Delivery Methods</h4>
                <div class='form-grid'>
                    <?php
                    foreach ( self::DELIVERY_FIELDS as $post_key => [ $db_key, $label ] ): if ( ! SEAL_DB::has_column( SEAL_DB::$sealLIB, $db_key ) ) { continue; } ?>
                    <div class='form-group'>
                        <label for='fld_<?php echo esc_attr( $post_key ); ?>'><?php echo esc_html( $label ); ?></label>
                        <select id='fld_<?php echo esc_attr( $post_key ); ?>' name='<?php echo esc_attr( $post_key ); ?>'>
                            <option value='Yes' <?php selected( $row[ $post_key ] ?? 'No', 'Yes' ); ?>>Yes</option>
                            <option value='No'  <?php selected( $row[ $post_key ] ?? 'No', 'No' );  ?>>No</option>
                        </select>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <?php if ( current_user_can( 'seal_admin' ) && SEAL_DB::has_column( SEAL_DB::$sealLIB, 'IlliadURL' ) ) :
                    $key_now = (string) ( $row['APIkey'] ?? '' ); ?>
                <h4 style='margin-top:20px;'>ILLiad</h4>
                <input type='hidden' name='illiad_present' value='1'>
                <p class='description' style='margin:0 0 8px'>For lenders that receive requests in ILLiad (Web Platform API). Only Administrators and ILL Administrators can see or change these.</p>
                <div class='form-grid'>
                    <div class='form-group'>
                        <label for='fld_illiad'>ILLiad library?</label>
                        <select id='fld_illiad' name='illiad'>
                            <option value='0' <?php selected( (string) ( $row['Illiad'] ?? '0' ) !== '1' ); ?>>No</option>
                            <option value='1' <?php selected( (string) ( $row['Illiad'] ?? '0' ), '1' ); ?>>Yes</option>
                        </select>
                    </div>
                    <div class='form-group'>
                        <label for='fld_illiad_url'>ILLiad Web Platform address</label>
                        <input type='url' id='fld_illiad_url' name='illiad_url' value='<?php echo $v( 'IlliadURL' ); ?>' placeholder='https://…/ILLiadWebPlatform/Transaction/'>
                    </div>
                    <div class='form-group'>
                        <label for='fld_illiad_days'>Default loan period (days)</label>
                        <input type='number' id='fld_illiad_days' name='illiad_days' min='1' max='365' value='<?php echo $v( 'IlliadDATE' ); ?>'>
                    </div>
                    <div class='form-group'>
                        <label for='fld_illiad_key'>API key</label>
                        <input type='password' id='fld_illiad_key' name='illiad_key' value='' autocomplete='new-password' placeholder='<?php echo $key_now === '' ? 'Not set' : 'Leave blank to keep the current key'; ?>' aria-describedby='fld_illiad_key_help'>
                        <small id='fld_illiad_key_help' style='color:#595959'><?php
                            echo $key_now === '' ? 'No key stored.' : ( SEAL_Secret::is_encrypted( $key_now ) ? 'A key is stored (encrypted). It is never shown; enter a new one to replace it.' : 'A key is stored in plain text: re-enter it (or use ILL System → Database → Encrypt) to encrypt it.' ); ?></small>
                        <?php if ( $key_now !== '' ) : ?><label style='font-weight:normal;margin-top:4px'><input type='checkbox' name='illiad_key_remove' value='1'> Remove the stored key</label><?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <div style='margin-top:25px;'>
                    <button type='submit' class='btn-primary'>
                        <?php echo ( $mode === 'add' ) ? 'Add Library' : 'Save Changes'; ?>
                    </button>
                    &nbsp;<a href='<?php echo $self; ?>' class='btn-secondary'>Cancel</a>
                </div>

                <?php if ( ! empty( $row['ModifyDate'] ) ): ?>
                <p style='margin-top:12px;font-size:.85rem;color:#595959;'>
                    Last modified: <?php echo esc_html( $row['ModifyDate'] ); ?>
                    <?php if ( ! empty( $row['ModEmail'] ) ) echo ' by ' . esc_html( $row['ModEmail'] ); ?>
                </p>
                <?php endif; ?>
            </form>
        </div>
        </div>
        <?php
        $this->datepicker_js( '#datepicker_adminlib' );
    }
}
