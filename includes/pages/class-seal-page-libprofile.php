<?php
/**
 * SEAL_Page_libprofile — Library Profile Editor
 *
 * Uses section-card / form-section layout to match the production ILL site.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class SEAL_Page_libprofile extends SEAL_Page_Base {
    /**
     * Entry point: SEAL_Router calls this to print the page into the theme's content area. Edit a library's
     * name, ILL email, phone, address, OCLC symbol and suspension. Staff edit their own libraries; Library
     * System Staff any library in their system; administrators any library.
     */
    public function render(): void {
        $db        = SEAL_DB::get();
        $lib_tbl   = SEAL_DB::$sealLIB;
        $uid       = $this->user->ID;

        // Privilege tiers:
        //  - seal_admin       → may view/edit ANY library profile
        //  - seal_adminlib_ls → may view/edit any library in their home system
        //  - everyone else    → restricted to their own LOC(s)
        $is_admin    = current_user_can( 'seal_admin' );
        $is_sys_staff= current_user_can( 'seal_adminlib_ls' );
        $home_system = strtoupper( (string) get_user_meta( $uid, 'home_system', true ) );

        $primary_loc  = SEAL_Functions::primary_loc( $uid );
        $allowed_locs = SEAL_Functions::all_locs( $uid );
        $has_multi    = count( $allowed_locs ) > 1;

        // Helper: may the current user act on a given LOC?
        $can_access_loc = function( string $loc ) use ( $is_admin, $is_sys_staff, $home_system, $allowed_locs, $db, $lib_tbl ): bool {
            $loc = strtoupper( trim( $loc ) );
            if ( $loc === '' ) { return false; }
            if ( $is_admin ) { return true; }
            if ( in_array( $loc, $allowed_locs, true ) ) { return true; }
            if ( $is_sys_staff && $home_system !== '' ) {
                $q = $db->query(
                    "SELECT 1 FROM `{$lib_tbl}` WHERE UPPER(`loc`)=UPPER('"
                    . SEAL_DB::esc( $loc ) . "') AND UPPER(`system`)=UPPER('"
                    . SEAL_DB::esc( $home_system ) . "') LIMIT 1"
                );
                return (bool) ( $q && $q->num_rows );
            }
            return false;
        };

        // Resolve names for multi-loc switcher (loc casing in the DB is
        // inconsistent, so compare on UPPER(loc)).
        $loc_name_map = [];
        if ( ! empty( $allowed_locs ) ) {
            $in = implode( ',', array_map( fn($l) => "'" . SEAL_DB::esc( strtoupper($l) ) . "'", $allowed_locs ) );
            $rn = $db->query( "SELECT `loc`, `Name` FROM `{$lib_tbl}` WHERE UPPER(`loc`) IN ($in)" );
            if ( $rn ) { while ( $rr = $rn->fetch_assoc() ) { $loc_name_map[ strtoupper($rr['loc']) ] = $rr['Name']; } }
        }

        $self           = esc_url( home_url( '/libprofile' ) );
        $last_mod_email = esc_html( $this->user->user_email );

        // ── Handle POST update ────────────────────────────────────────────────
        if ( $this->is_post() ) {
            $this->verify_nonce( 'seal_libprofile' );
            $post_loc = strtoupper( sanitize_text_field( wp_unslash( $_POST['loc'] ?? $primary_loc ) ) );
            if ( ! $can_access_loc( $post_loc ) ) {
                $this->alert( 'Access denied.', 'error' ); return;
            }

            $n       = fn($k, $d='') => sanitize_text_field( wp_unslash( $_POST[$k] ?? $d ) );
            $yn      = fn($k) => in_array( $n($k,'No'), ['Yes','No'], true ) ? $n($k,'No') : 'No';
            $ts      = current_time( 'mysql' );
            $suspend = in_array( $n('suspend'), ['0','1'], true ) ? $n('suspend') : '0';
            $enddate = $n('enddate');
            if ( $suspend === '1' && ( $enddate === '' || strtotime($enddate) === false ) ) {
                $enddate = date( 'Y-m-d', strtotime('+7 days') );
            } elseif ( $suspend !== '1' ) {
                $enddate = null;   // NULL, not '': an empty string is rejected by a DATE column in MySQL strict mode
            }
            if ( $enddate !== null ) { $enddate = date( 'Y-m-d', strtotime( $enddate ) ); }

            // Only columns this install's table has (CDLC's has no lbsyscourier etc.); only questions that were on the form.
            $sets = [
                'Name' => $n('libname'), 'ill_email' => $n('libemail'), 'suspend' => $suspend, 'phone' => $n('phone'),
                'address1' => $n('address1'), 'address2' => $n('address2'), 'address3' => $n('address3'), 'oclc' => $n('oclc'),
                'SuspendDateEnd' => $enddate, 'ModifyDate' => $ts, 'ModEmail' => $last_mod_email,
            ];
            foreach ( [ 'book' => 'book_loan', 'journal' => 'periodical_loan', 'av' => 'av_loan', 'ebook' => 'ebook_request', 'ejournal' => 'ejournal_request',
                        'lbsyscourier' => 'lbsyscourier', 'lbUSPS' => 'lbUSPS', 'lbEmpire' => 'lbEmpire' ] as $field => $col ) {
                if ( isset( $_POST[ $field ] ) ) { $sets[ $col ] = $yn( $field ); }
            }
            $ok = SEAL_DB::update_existing( $lib_tbl, $sets, 'UPPER(`loc`)=UPPER(%s)', $post_loc );
            if ( $ok === false ) {
                $why = current_user_can( 'seal_admin' ) ? ' Database said: ' . esc_html( SEAL_DB::last_error() ) : '';
                $this->alert( 'The library profile could not be saved. Nothing was changed. Please try again or contact an administrator.' . $why, 'error' );
                return;
            }
            $this->alert( 'Library profile updated.', 'success' );
            echo '<p><a href="' . esc_url( add_query_arg( 'loc', $post_loc, $self ) ) . '" class="btn-secondary">Return to Library Profile</a></p>';
            return;
        }

        // ── Determine active LOC ──────────────────────────────────────────────
        $req_loc    = strtoupper( sanitize_text_field( $this->get_str( 'loc', $primary_loc ) ) );
        $active_loc = $can_access_loc( $req_loc ) ? $req_loc : $primary_loc;

        if ( $active_loc === '' ) {
            $this->alert( 'No library is associated with your account. Please contact a ' . seal_config( 'system_name' ) . ' administrator.', 'error' );
            return;
        }

        // ── Load library data ─────────────────────────────────────────────────
        // LOC codes are stored in their original (often lower/mixed) case, while
        // $active_loc is upper-cased for display/ownership checks. Match case-
        // insensitively so the form populates regardless of stored casing.
        $row = [];
        $lib_q = SEAL_DB::query(
            "SELECT * FROM %t WHERE UPPER(`loc`)=UPPER(%s) LIMIT 1",
            $lib_tbl, $active_loc
        );
        if ( $lib_q ) { $row = $lib_q->fetch_assoc() ?: []; }

        if ( empty( $row ) ) {
            $this->alert(
                'No library record found for LOC "' . esc_html( $active_loc ) . '". '
                . 'The code may be misspelled or the library has not been added yet.',
                'error'
            );
            return;
        }

        $v       = fn($k,$d='') => esc_attr( $row[$k] ?? $d );
        $yn_sel  = fn($fld,$val) => selected( $row[$fld] ?? 'No', $val, false );
        $suspend_val = ( ( $row['suspend'] ?? 0 ) == 1 || ( $row['suspend'] ?? '' ) === 'Yes' ) ? '1' : '0';
        ?>
        <div class='LibProfile_Form'>

        <h3>Library Profile — <?php echo esc_html( ( $row['Name'] ?? $active_loc ) . " ({$active_loc})" ); ?></h3>

        <?php // ── Multi-loc switcher ────────────────────────────────────────
        if ( $has_multi ): ?>
        <form method='get' action='<?php echo $self; ?>' class='section-card' style='margin-bottom:15px;'>
            <label for='locSwitchLP' style='font-weight:bold;'>Library Profile for:</label>
            <select id='locSwitchLP' name='loc' style='margin-left:8px;' aria-describedby='locSwitchLP_h'>
                <?php foreach ( $allowed_locs as $lc ): ?>
                <option value='<?php echo esc_attr( $lc ); ?>' <?php selected( $lc, $active_loc ); ?>>
                    <?php echo esc_html( ( $loc_name_map[ strtoupper($lc) ] ?? $lc ) . " ($lc)" ); ?>
                </option>
                <?php endforeach; ?>
            </select>
            <button type='submit' class='btn-secondary' style='margin-left:8px;'>Show this library</button>
            <span id='locSwitchLP_h' class='helper' style='display:block;margin-top:6px;'>Choose a library, then select the button to load its profile.</span>
        </form>
        <?php endif; ?>

        <form method='post' action='<?php echo $self; ?>'>
            <?php $this->nonce_field( 'seal_libprofile' ); ?>
            <input type='hidden' name='loc' value='<?php echo esc_attr( $active_loc ); ?>'>

            <?php // ── Basic Info ─────────────────────────────────────────── ?>
            <div class='section-card'>
                <h4>Basic Information</h4>
                <div class='form-section'>
                    <div class='form-group'>
                        <label for='lp_name'>Library Name</label>
                        <input type='text' id='lp_name' name='libname' value='<?php echo $v('Name'); ?>'>
                    </div>
                    <div class='form-group'>
                        <label for='lp_oclc'>OCLC Symbol</label>
                        <input type='text' id='lp_oclc' name='oclc' value='<?php echo $v('oclc'); ?>'>
                    </div>
                    <div class='form-group' style='grid-column:1/-1;'>
                        <label for='lp_email'>ILL Email</label>
                        <input type='text' id='lp_email' name='libemail' value='<?php echo $v('ill_email'); ?>'>
                        <small style='color:#666;'>Separate multiple addresses with semicolons.</small>
                    </div>
                    <div class='form-group'>
                        <label for='lp_phone'>Phone</label>
                        <input type='text' id='lp_phone' name='phone' value='<?php echo $v('phone'); ?>'>
                    </div>
                    <div class='form-group'>
                        <label for='lp_addr1'>Address Line 1</label>
                        <input type='text' id='lp_addr1' name='address1' value='<?php echo $v('address1'); ?>'>
                    </div>
                    <div class='form-group'>
                        <label for='lp_addr2'>Address Line 2</label>
                        <input type='text' id='lp_addr2' name='address2' value='<?php echo $v('address2'); ?>'>
                    </div>
                    <div class='form-group'>
                        <label for='lp_addr3'>City, State Zip</label>
                        <input type='text' id='lp_addr3' name='address3' value='<?php echo $v('address3'); ?>'>
                    </div>
                </div>
            </div>

            <?php // ── Status ─────────────────────────────────────────────── ?>
            <div class='section-card'>
                <h4>ILL Status</h4>
                <div class='form-section two-col'>
                    <div class='form-group'>
                        <label for='lp_suspend'>Suspended</label>
                        <select id='lp_suspend' name='suspend'>
                            <option value='0' <?php selected( $suspend_val, '0' ); ?>>No — Active</option>
                            <option value='1' <?php selected( $suspend_val, '1' ); ?>>Yes — Suspended</option>
                        </select>
                    </div>
                    <div class='form-group'>
                        <label for='datepicker_lp'>Suspend Until (YYYY-MM-DD)</label>
                        <input type='text' id='datepicker_lp' name='enddate'
                               value='<?php echo $v('SuspendDateEnd'); ?>'
                               autocomplete='off'
                               placeholder='Leave blank for permanent'>
                    </div>
                </div>
            </div>

            <?php // ── Loan Types ─────────────────────────────────────────── ?>
            <div class='section-card'>
                <h4>Loan Types</h4>
                <table class='option-table'>
                    <?php
                    $loan_fields = [
                        'book'    => [ 'book_loan',        'Books' ],
                        'journal' => [ 'periodical_loan',  'Periodicals' ],
                        'av'      => [ 'av_loan',          'AV Materials' ],
                        'ebook'   => [ 'ebook_request',    'eBooks' ],
                        'ejournal'=> [ 'ejournal_request', 'eJournals' ],
                    ];
                    foreach ( $loan_fields as $post_key => [ $db_key, $label ] ): if ( ! SEAL_DB::has_column( $lib_tbl, $db_key ) ) { continue; } ?>
                    <tr>
                        <td><b><?php echo esc_html($label); ?></b></td>
                        <td>
                            <div class='inline-options'>
                                <label class='choice'>
                                    <input type='radio' name='<?php echo esc_attr($post_key); ?>' value='Yes'
                                        <?php checked( $row[$db_key] ?? 'No', 'Yes' ); ?>> Yes
                                </label>
                                <label class='choice'>
                                    <input type='radio' name='<?php echo esc_attr($post_key); ?>' value='No'
                                        <?php checked( $row[$db_key] ?? 'No', 'No' ); ?>> No
                                </label>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            </div>

            <?php // ── Delivery Methods (only the ones this install's table has) ── ?>
            <?php if ( array_filter( [ 'lbsyscourier', 'lbUSPS', 'lbEmpire' ], static fn( $c ) => SEAL_DB::has_column( $lib_tbl, $c ) ) ) : ?>
            <div class='section-card'>
                <h4>Delivery Methods</h4>
                <table class='option-table'>
                    <?php
                    $delivery = [
                        'lbsyscourier' => 'Library System Courier',
                        'lbUSPS'       => 'US Mail (USPS)',
                        'lbEmpire'     => 'Empire Library Delivery',
                    ];
                    foreach ( $delivery as $post_key => $label ): if ( ! SEAL_DB::has_column( $lib_tbl, $post_key ) ) { continue; } ?>
                    <tr>
                        <td><b><?php echo esc_html($label); ?></b></td>
                        <td>
                            <div class='inline-options'>
                                <label class='choice'>
                                    <input type='radio' name='<?php echo esc_attr($post_key); ?>' value='Yes'
                                        <?php checked( $row[$post_key] ?? 'No', 'Yes' ); ?>> Yes
                                </label>
                                <label class='choice'>
                                    <input type='radio' name='<?php echo esc_attr($post_key); ?>' value='No'
                                        <?php checked( $row[$post_key] ?? 'No', 'No' ); ?>> No
                                </label>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            </div>
            <?php endif; ?>

            <?php // ── Read-only metadata ─────────────────────────────────── ?>
            <?php if ( ! empty( $row['ModifyDate'] ) ): ?>
            <p style='font-size:.85rem;color:#595959;margin-bottom:8px;'>
                Last modified: <?php echo esc_html($row['ModifyDate']); ?>
                <?php if ( ! empty($row['ModEmail']) ) echo ' by ' . esc_html($row['ModEmail']); ?>
            </p>
            <?php endif; ?>

            <div class='actions'>
                <button type='submit' class='btn-primary'>Save Profile</button>
                <a href='<?php echo $self; ?>' class='btn-secondary' style='margin-left:8px;'>Cancel</a>
            </div>

        </form>
        </div>
        <?php
        $this->datepicker_js( '#datepicker_lp' );
    }
}
