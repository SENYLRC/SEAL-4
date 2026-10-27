<?php
/**
 * SEAL_Page_adminlib_ls — Library Administration for Library System staff.
 *
 * Laid out and behaving like SEAL-3's adminlib_ls.php: a filterable list of the libraries in the
 * user's own home system, with Edit, Delete and Mass Suspend/Activate.
 *   default  list      action=2  edit      action=3  delete (confirm)      action=5  mass suspend/activate
 *
 * Every read and write is limited to libraries whose `system` is the user's home system. System
 * staff can change contact details, suspension, delivery and loan options; the library's name is
 * editable too (as in SEAL-3), but not its LOC, alias, system or participation (administrators do that).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class SEAL_Page_adminlib_ls extends SEAL_Page_Base {

    private string $home = '';
    private string $self = '';

    /** Loan / delivery choices: [ form field => [ column, label ] ]. */
    private const LOAN = [
        'book'      => [ 'book_loan',        'Print Books' ],
        'journal'   => [ 'periodical_loan',  'Print Journals / Articles' ],
        'av'        => [ 'av_loan',          'Audio / Video Materials' ],
        'reference' => [ 'theses_loan',      'Reference / Microfilm' ],
        'ebook'     => [ 'ebook_request',    'Electronic Books' ],
        'ejournal'  => [ 'ejournal_request', 'Electronic Journals' ],
    ];
    private const DELIVERY = [
        'lbEmpire'      => 'Empire Library Delivery',
        'lbsyscourier'  => 'System Courier',
        'lbUSPS'        => 'US Mail',
        'lbCommCourier' => 'Commercial Courier (UPS or FedEx)',
    ];

    /**
     * Entry point: SEAL_Router calls this to print the page into the theme's content area. Library System Staff
     * edit, add, suspend or remove libraries — only those whose system is the user's home system.
     */
    public function render(): void {
        $this->home = sanitize_text_field( (string) get_user_meta( $this->user->ID, 'home_system', true ) );
        if ( $this->home === '' ) {
            $this->alert( 'Your account does not have a Home Library System assigned.', 'error' );
            return;
        }
        $this->self = esc_url( home_url( '/adminlib-ls' ) );

        switch ( $this->req_int( 'action', 0 ) ) {
            case 2:  $this->render_edit();   break;
            case 3:  $this->render_delete(); break;
            case 5:  $this->render_mass();   break;
            default: $this->render_list();   break;
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** Load one library, only if it belongs to the user's home system. */
    private function load( int $recnum ): ?array {
        if ( $recnum < 1 ) { return null; }
        $q   = SEAL_DB::query( "SELECT * FROM %t WHERE `recnum`=%d AND `system`=%s LIMIT 1", SEAL_DB::$sealLIB, $recnum, $this->home );
        $row = $q ? $q->fetch_assoc() : null;
        return $row ?: null;
    }

    /** True for the stored 'Yes', '1' or 'y' (any case). */
    private static function is_yes( $v ): bool { return in_array( strtolower( trim( (string) $v ) ), [ 'yes', '1', 'y' ], true ); }
    /** True for the stored 'No', '0' or 'n' (any case). */
    private static function is_no( $v ): bool  { return in_array( strtolower( trim( (string) $v ) ), [ 'no', '0', 'n' ], true ); }

    /** The "Return to list" button. */
    private function back_link(): string {
        return '<a class="btn-secondary" href="' . $this->self . '">Return to list</a>';
    }

    /** A labelled Yes / No radio pair (role=radiogroup) for one library field, pre-selected from $current. */
    private function yes_no( string $name, string $label, $current ): void {
        ?>
        <div class="form-group">
            <label id="lbl_<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $label ); ?></label>
            <div class="inline-options" role="radiogroup" aria-labelledby="lbl_<?php echo esc_attr( $name ); ?>">
                <label class="choice"><input type="radio" name="<?php echo esc_attr( $name ); ?>" value="Yes" <?php checked( self::is_yes( $current ) ); ?>> Yes</label>
                <label class="choice"><input type="radio" name="<?php echo esc_attr( $name ); ?>" value="No" <?php checked( self::is_no( $current ) ); ?>> No</label>
            </div>
        </div>
        <?php
    }

    // ── List ──────────────────────────────────────────────────────────────────

    /** The libraries in the user's system, with filters and Edit / Delete actions. */
    private function render_list( string $notice = '' ): void {
        $is_post   = $this->is_post() && $this->post_str( 'firstpass' ) === 'no';
        $f_library = $is_post ? $this->post_str( 'library' )         : '';
        $f_alias   = $is_post ? $this->post_str( 'filter_alias' )    : '';
        $f_loc     = $is_post ? $this->post_str( 'loc' )             : '';
        $f_email   = $is_post ? $this->post_str( 'filter_illemail' ) : '';
        $f_noalias = $is_post ? $this->post_str( 'filter_aliasblank' )    : '';
        $f_noemail = $is_post ? $this->post_str( 'filter_illemailblank' ) : '';
        $f_part    = $is_post ? $this->post_str( 'filter_illpart' )  : '';
        $f_susp    = $is_post ? $this->post_str( 'filter_suspend' )  : '';

        $where = "`system`=" . "'" . SEAL_DB::esc( $this->home ) . "'";
        if ( $f_library !== '' ) { $where .= " AND `Name` LIKE '%" . SEAL_DB::esc( $f_library ) . "%'"; }
        if ( $f_alias   !== '' ) { $where .= " AND `alias` LIKE '%" . SEAL_DB::esc( $f_alias ) . "%'"; }
        if ( $f_loc     !== '' ) { $where .= " AND `loc` LIKE '%" . SEAL_DB::esc( $f_loc ) . "%'"; }
        if ( $f_email   !== '' ) { $where .= " AND `ill_email` LIKE '%" . SEAL_DB::esc( $f_email ) . "%'"; }
        if ( $f_noalias === 'yes' ) { $where .= " AND TRIM(IFNULL(`alias`,''))=''"; }
        if ( $f_noemail === 'yes' ) { $where .= " AND TRIM(IFNULL(`ill_email`,''))=''"; }
        // Participant / suspended are numbers (1/0) here but 'Yes'/'No' text in older tables. Never compare a number column
        // with 'Yes': MySQL turns 'Yes' into 0, so `participant`='Yes' matched every non-participant. CAST AS CHAR works for both.
        if ( $f_part    === 'yes' ) { $where .= " AND (CAST(`participant` AS CHAR) IN ('1','Yes'))"; }
        if ( $f_susp    === 'yes' ) { $where .= " AND (CAST(`suspend` AS CHAR) IN ('1','Yes'))"; }

        $rows  = SEAL_DB::get()->query( "SELECT * FROM `" . SEAL_DB::$sealLIB . "` WHERE {$where} ORDER BY `Name` ASC" );
        $total = $rows ? $rows->num_rows : 0;
        $sys   = esc_html( SEAL_Functions::system_name( $this->home ) );
        ?>
        <div class="adminlib-wrapper">

        <div class="adminlib-header">
            <h3>Libraries in <?php echo $sys; ?></h3>
            <div class="actions">
                <a class="btn-secondary" href="<?php echo esc_url( add_query_arg( 'action', 5, $this->self ) ); ?>">Mass Suspend/Activate</a>
            </div>
        </div>
        <p class="seal-ss-note">Showing the libraries within your system.</p>

        <?php if ( $notice !== '' ) { $this->alert( $notice, 'success' ); } ?>

        <form method="post" action="<?php echo $this->self; ?>" class="filter-bar">
            <input type="hidden" name="firstpass" value="no">
            <div class="filter-grid">
                <div class="form-group"><label for="al_lib">Library Name</label><input id="al_lib" name="library" type="text" value="<?php echo esc_attr( $f_library ); ?>"></div>
                <div class="form-group"><label for="al_alias">Alias</label><input id="al_alias" name="filter_alias" type="text" value="<?php echo esc_attr( $f_alias ); ?>"></div>
                <div class="form-group"><label for="al_loc">LOC Code</label><input id="al_loc" name="loc" type="text" value="<?php echo esc_attr( $f_loc ); ?>"></div>
                <div class="form-group"><label for="al_email">Email</label><input id="al_email" name="filter_illemail" type="text" value="<?php echo esc_attr( $f_email ); ?>"></div>
                <div class="form-group"><label><input type="checkbox" name="filter_aliasblank" value="yes" <?php checked( $f_noalias, 'yes' ); ?>> Missing alias</label></div>
                <div class="form-group"><label><input type="checkbox" name="filter_illemailblank" value="yes" <?php checked( $f_noemail, 'yes' ); ?>> Missing ILL email</label></div>
                <div class="form-group"><label><input type="checkbox" name="filter_illpart" value="yes" <?php checked( $f_part, 'yes' ); ?>> ILL participant</label></div>
                <div class="form-group"><label><input type="checkbox" name="filter_suspend" value="yes" <?php checked( $f_susp, 'yes' ); ?>> ILL suspended</label></div>
            </div>
            <div class="filter-actions">
                <button type="submit" class="btn-primary">Search</button>
                <a class="btn-secondary" href="<?php echo $this->self; ?>">Reset</a>
            </div>
        </form>

        <div class="pagination-info"><?php echo number_format( $total ); ?> libraries</div>

        <div class="seal-table-wrap">
        <table class="rh-table">
            <thead><tr>
                <th scope="col">Library</th><th scope="col" class="col-alias">Alias</th><th scope="col">Participant</th>
                <th scope="col">Suspend</th><th scope="col">System</th><th scope="col">OCLC</th><th scope="col">LOC</th><th scope="col">Actions</th>
            </tr></thead>
            <tbody>
            <?php if ( $total === 0 ) : ?>
                <tr><td colspan="8">No libraries found in this system.</td></tr>
            <?php else : while ( $r = $rows->fetch_assoc() ) :
                $rec      = (int) ( $r['recnum'] ?? 0 );
                $part_yes = ( ( $r['participant'] ?? 0 ) == 1 || ( $r['participant'] ?? '' ) === 'Yes' );
                $susp_yes = ( ( $r['suspend'] ?? 0 ) == 1 || ( $r['suspend'] ?? '' ) === 'Yes' );
                $name     = (string) ( $r['Name'] ?? '' );
                ?>
                <tr>
                    <td><?php echo esc_html( $name ); ?></td>
                    <td class="col-alias"><?php echo esc_html( $r['alias'] ?? '' ); ?></td>
                    <td class="<?php echo $part_yes ? 'status-yes' : 'status-no'; ?>"><?php echo $part_yes ? 'Yes' : 'No'; ?></td>
                    <td class="<?php echo $susp_yes ? 'status-no' : 'status-yes'; ?>"><?php echo $susp_yes ? 'Yes' : 'No'; ?></td>
                    <td><?php echo esc_html( $r['system'] ?? '' ); ?></td>
                    <td><?php echo esc_html( $r['oclc'] ?? '' ); ?></td>
                    <td><?php echo esc_html( $r['loc'] ?? '' ); ?></td>
                    <td class="table-actions">
                        <?php if ( $rec > 0 ) : ?>
                        <a class="edit" href="<?php echo esc_url( add_query_arg( [ 'action' => 2, 'librecnumb' => $rec ], $this->self ) ); ?>">Edit<span class="seal-sr-only"> <?php echo esc_html( $name ); ?></span></a>
                        <a class="delete" href="<?php echo esc_url( add_query_arg( [ 'action' => 3, 'librecnumb' => $rec ], $this->self ) ); ?>">Delete<span class="seal-sr-only"> <?php echo esc_html( $name ); ?></span></a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endwhile; endif; ?>
            </tbody>
        </table>
        </div>
        </div>
        <?php
    }

    // ── Edit ──────────────────────────────────────────────────────────────────

    /** The edit form for one library in the user's system (or an error when it belongs to another system). */
    private function render_edit(): void {
        $recnum = $this->req_int( 'librecnumb', 0 );
        $row    = $this->load( $recnum );
        if ( ! $row ) {
            echo '<div class="adminlib-wrapper">'; $this->alert( 'Library not found in your system.', 'error' ); echo $this->back_link() . '</div>';
            return;
        }

        if ( $this->is_post() && isset( $_POST['recnum'] ) ) {
            $this->verify_nonce( 'seal_adminlib_ls_edit' );
            $t  = fn( string $k ): string => sanitize_text_field( wp_unslash( $_POST[ $k ] ?? '' ) );
            $yn = fn( string $k, $cur ): string => in_array( $t( $k ), [ 'Yes', 'No' ], true ) ? $t( $k ) : (string) $cur;

            $suspend = $t( 'suspend' ) === '1' ? 1 : 0;
            $ts      = strtotime( str_replace( '-', '/', $t( 'enddate' ) ) );
            $enddate = $ts ? date( 'Y-m-d', $ts ) : date( 'Y-m-d', strtotime( '+7 days' ) );
            $name    = $t( 'libname' );
            if ( $name === '' ) { $name = (string) $row['Name']; }

            $sets = [
                'Name' => $name, 'ill_email' => $t( 'libemail' ), 'phone' => $t( 'phone' ),
                'address1' => $t( 'address1' ), 'address2' => $t( 'address2' ), 'address3' => $t( 'address3' ),
                'oclc' => $t( 'oclc' ), 'SuspendDateEnd' => $enddate,
                'ModEmail' => $this->user->user_email, 'ModifyDate' => current_time( 'mysql' ),
            ];
            foreach ( self::LOAN as $field => [ $col ] )      { $sets[ $col ]   = $yn( $field, $row[ $col ] ?? '' ); }
            foreach ( array_keys( self::DELIVERY ) as $col )  { $sets[ $col ]   = $yn( $col,   $row[ $col ] ?? '' ); }
            // Only columns this install's library table actually has ($row is SELECT *). CDLC's table has no
            // lbCommCourier (a SEAL-3 column), and one unknown column fails the whole UPDATE.
            $sets = array_intersect_key( $sets, $row );

            $sql = 'UPDATE %t SET `suspend`=%d';
            $args = [ SEAL_DB::$sealLIB, $suspend ];
            foreach ( $sets as $col => $val ) { $sql .= ', ' . SEAL_DB::esc_id( $col ) . '=%s'; $args[] = $val; }
            $sql .= ' WHERE `recnum`=%d AND `system`=%s LIMIT 1';
            $args[] = $recnum; $args[] = $this->home;

            $ok = SEAL_DB::query( $sql, ...$args );
            if ( $ok === false ) {
                $why = current_user_can( 'seal_admin' ) ? ' Database said: ' . esc_html( SEAL_DB::last_error() ) : '';   // detail for administrators only
                echo '<div class="adminlib-wrapper">'; $this->alert( 'The update failed. Please try again or contact an administrator.' . $why, 'error' ); echo $this->back_link() . '</div>';
                return;
            }
            $this->render_list( 'Library updated: ' . esc_html( $name ) . '. Your changes were saved.' );
            return;
        }

        $end = (string) ( $row['SuspendDateEnd'] ?? '' );
        if ( $end === '' || strpos( $end, '0000' ) === 0 || strpos( $end, '1970' ) === 0 ) { $end = date( 'Y-m-d', strtotime( '+7 days' ) ); }
        $susp_yes = ( ( $row['suspend'] ?? 0 ) == 1 || ( $row['suspend'] ?? '' ) === 'Yes' );
        $f = fn( string $k ): string => esc_attr( (string) ( $row[ $k ] ?? '' ) );
        ?>
        <div class="adminlib-wrapper LibProfile_Form">
        <div class="adminlib-header"><h3>Edit Library: <?php echo esc_html( (string) $row['Name'] ); ?></h3></div>
        <form method="post" action="<?php echo esc_url( add_query_arg( [ 'action' => 2, 'librecnumb' => $recnum ], $this->self ) ); ?>">
            <?php wp_nonce_field( 'seal_adminlib_ls_edit', 'seal_nonce' ); ?>
            <input type="hidden" name="recnum" value="<?php echo (int) $recnum; ?>">

            <div class="section-card"><h4>Library Details</h4>
              <div class="form-section">
                <div class="form-group"><label for="e_name">Library Name</label><input id="e_name" type="text" name="libname" value="<?php echo $f( 'Name' ); ?>"></div>
                <div class="form-group"><label for="e_email">ILL Email</label><input id="e_email" type="text" name="libemail" value="<?php echo $f( 'ill_email' ); ?>"></div>
                <div class="form-group"><label for="e_phone">Phone</label><input id="e_phone" type="text" name="phone" value="<?php echo $f( 'phone' ); ?>"></div>
                <div class="form-group"><label for="e_a1">Address Dept</label><input id="e_a1" type="text" name="address1" value="<?php echo $f( 'address1' ); ?>"></div>
                <div class="form-group"><label for="e_a2">Address Street</label><input id="e_a2" type="text" name="address2" value="<?php echo $f( 'address2' ); ?>"></div>
                <div class="form-group"><label for="e_a3">Address City/State/Zip</label><input id="e_a3" type="text" name="address3" value="<?php echo $f( 'address3' ); ?>"></div>
                <div class="form-group"><label for="e_oclc">OCLC</label><input id="e_oclc" type="text" name="oclc" value="<?php echo $f( 'oclc' ); ?>"></div>
              </div>
            </div>

            <div class="section-card"><h4>Suspension</h4>
              <div class="form-section">
                <div class="form-group"><label for="e_susp">Suspend Library?</label>
                    <select id="e_susp" name="suspend"><option value="0" <?php selected( $susp_yes, false ); ?>>No</option><option value="1" <?php selected( $susp_yes, true ); ?>>Yes</option></select></div>
                <div class="form-group"><label for="e_end">Suspension End Date</label><input id="e_end" type="text" name="enddate" value="<?php echo esc_attr( $end ); ?>" autocomplete="off"></div>
              </div>
            </div>

            <div class="section-card"><h4>Delivery &amp; Lending</h4>
              <div class="form-section two-col">
                <?php foreach ( self::DELIVERY as $col => $label ) { if ( array_key_exists( $col, $row ) ) { $this->yes_no( $col, $label, $row[ $col ] ); } } ?>
              </div>
            </div>

            <div class="section-card"><h4>Items Willing to Loan</h4>
              <div class="form-section two-col">
                <?php foreach ( self::LOAN as $field => [ $col, $label ] ) { if ( array_key_exists( $col, $row ) ) { $this->yes_no( $field, $label, $row[ $col ] ); } } ?>
              </div>
            </div>

            <div class="actions" style="margin-top:20px;">
                <button type="submit" class="btn-primary">Save Changes</button>
                <a class="btn-secondary" href="<?php echo $this->self; ?>">Cancel</a>
            </div>
        </form>
        </div>
        <?php
        $this->datepicker_js( '#e_end' );
    }

    // ── Delete ────────────────────────────────────────────────────────────────

    /** Asks for confirmation before deleting a library in the user's system. */
    private function render_delete(): void {
        $recnum = $this->req_int( 'librecnumb', 0 );
        $row    = $this->load( $recnum );
        echo '<div class="adminlib-wrapper">';
        if ( ! $row ) {
            $this->alert( 'Library not found in your system.', 'error' );
            echo $this->back_link() . '</div>';
            return;
        }
        $name = (string) $row['Name'];

        if ( $this->is_post() && isset( $_POST['confirm_delete'] ) ) {
            $this->verify_nonce( 'seal_adminlib_ls_delete' );
            $ok = SEAL_DB::query( "DELETE FROM %t WHERE `recnum`=%d AND `system`=%s LIMIT 1", SEAL_DB::$sealLIB, $recnum, $this->home );
            $this->alert( $ok === false ? 'The delete failed. Please try again or contact an administrator.' : 'Library deleted: ' . esc_html( $name ) . '.', $ok === false ? 'error' : 'success' );
            echo $this->back_link() . '</div>';
            return;
        }
        ?>
        <div class="adminlib-header"><h3>Confirm delete</h3></div>
        <div class="adminlib-panel">
            <p>Are you sure you want to permanently delete <strong><?php echo esc_html( $name ); ?></strong> (<?php echo esc_html( (string) ( $row['loc'] ?? '' ) ); ?>)? This cannot be undone.</p>
            <form method="post" action="<?php echo esc_url( add_query_arg( [ 'action' => 3, 'librecnumb' => $recnum ], $this->self ) ); ?>">
                <?php wp_nonce_field( 'seal_adminlib_ls_delete', 'seal_nonce' ); ?>
                <input type="hidden" name="confirm_delete" value="1">
                <button type="submit" class="btn-danger">Confirm delete</button>
                <a class="btn-secondary" href="<?php echo $this->self; ?>">Cancel</a>
            </form>
        </div>
        </div>
        <?php
    }

    // ── Mass suspend / activate ───────────────────────────────────────────────

    /**
     * Suspend or reactivate every participating library in the user's system at once (posts to /status-
     * confirmation).
     */
    private function render_mass(): void {
        $sys = SEAL_Functions::system_name( $this->home );
        ?>
        <div class="adminlib-wrapper">
        <div class="adminlib-header"><h3>Mass Suspend / Activate</h3></div>
        <div class="adminlib-panel">
            <p>Applies to all participating libraries in <strong><?php echo esc_html( $sys ); ?></strong>. You will be asked to confirm.</p>
            <form method="post" action="<?php echo esc_url( home_url( '/status-confirmation' ) ); ?>">
                <input type="hidden" name="system" value="<?php echo esc_attr( $this->home ); ?>">
                <fieldset class="seal-status-fieldset">
                    <legend>Action</legend>
                    <label class="choice"><input type="radio" name="task" value="suspend"> Suspend</label>
                    <label class="choice"><input type="radio" name="task" value="activate" checked> Activate</label>
                </fieldset>
                <div class="form-group" style="max-width:260px;margin-top:10px;">
                    <label for="suspend_enddate">Suspension End Date</label>
                    <input id="suspend_enddate" name="enddate" type="text" placeholder="YYYY-MM-DD" autocomplete="off" aria-describedby="suspend_enddate_h">
                    <small id="suspend_enddate_h">Only used when suspending. Blank means 7 days from today.</small>
                </div>
                <div style="margin-top:14px;">
                    <button type="submit" class="btn-primary">Submit</button>
                    <a class="btn-secondary" href="<?php echo $this->self; ?>">Cancel</a>
                </div>
            </form>
        </div>
        </div>
        <?php
        $this->datepicker_js( '#suspend_enddate' );
    }
}
