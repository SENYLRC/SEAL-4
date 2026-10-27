<?php
/**
 * SEAL_Module_UserFields
 *
 * Adds ILL-specific fields to WP user profiles, the Add User screen,
 * and the public registration form. All labels and system-option lists
 * adapt automatically to seal_config() branding values so a re-deployment
 * (e.g. LINX / CDLC or any other region) requires zero code changes.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class SEAL_Module_UserFields {

    /**
     * Hooks the ILL profile fields (institution, library system, LOC code, phone, alternate email, extra LOC
     * codes) on profile screens and the registration form, and adds Institution / Home System columns to the
     * Users list.
     */
    public static function init(): void {
        // Profile screens
        add_action( 'show_user_profile',        [ __CLASS__, 'render_fields' ] );
        add_action( 'edit_user_profile',         [ __CLASS__, 'render_fields' ] );
        add_action( 'user_new_form',             [ __CLASS__, 'render_fields' ] );
        // Save
        add_action( 'personal_options_update',   [ __CLASS__, 'save_fields'   ] );
        add_action( 'edit_user_profile_update',  [ __CLASS__, 'save_fields'   ] );
        add_action( 'user_register',             [ __CLASS__, 'save_fields'   ] );
        // Registration form
        add_action( 'register_form',             [ __CLASS__, 'render_registration_fields' ] );
        add_filter( 'registration_errors',       [ __CLASS__, 'validate_registration' ], 10, 3 );
        // Users list columns
        add_filter( 'manage_users_columns',          [ __CLASS__, 'add_columns'      ] );
        add_filter( 'manage_users_custom_column',    [ __CLASS__, 'column_data'      ], 10, 3 );
        add_filter( 'manage_users_sortable_columns', [ __CLASS__, 'sortable_columns' ] );
        add_action( 'pre_get_users',                 [ __CLASS__, 'sort_by_meta'     ] );
        // Users list search also covers name, library name and LOC codes
        add_filter( 'user_search_columns',           [ __CLASS__, 'search_columns'   ], 10, 3 );
        add_action( 'pre_user_query',                [ __CLASS__, 'search_meta'      ] );
        // Registration page CSS fix
        add_action( 'login_head', [ __CLASS__, 'registration_spacing_css' ] );
        add_filter( 'login_message', [ __CLASS__, 'drop_register_notice' ], 5 );
        // Profile screen: drop the WordPress parts nobody here uses
        add_action( 'admin_head-profile.php',   [ __CLASS__, 'hide_unused_profile_rows' ] );
        add_action( 'admin_head-user-edit.php', [ __CLASS__, 'hide_unused_profile_rows' ] );
        remove_action( 'admin_color_scheme_picker', 'admin_color_scheme_picker' );
        add_filter( 'wp_is_application_passwords_available', '__return_false' );   // also blocks logins that would skip two-factor
    }

    /**
     * Website, Biographical Info, Display name and Language are not used by the ILL system. Hidden
     * (not removed) so WordPress keeps whatever it already stores for them; saving the profile leaves
     * those values unchanged. The colour scheme picker and Application Passwords are switched off in init().
     */
    public static function hide_unused_profile_rows(): void {
        echo '<style>.user-url-wrap,.user-description-wrap,.user-display-name-wrap,.user-language-wrap{display:none!important}</style>';
    }

    // ── Profile fields ────────────────────────────────────────────────────────

    /** @param WP_User|string $user  WP_User on profile screens; a string ('add-new-user') on the Add User screen. */
    public static function render_fields( $user ): void {
        $uid     = $user instanceof WP_User ? (int) $user->ID : 0;
        $sys     = seal_config( 'system_name' );
        $home_sys  = get_user_meta( $uid, 'home_system',    true ) ?: 'SE';
        $systems   = self::system_options();
        $ro        = ! current_user_can( 'edit_users' );   // library / system fields: administrators only
        $rattr     = $ro ? ' readonly aria-readonly="true"' : '';
        ?>
        <h3><?php echo esc_html( $sys ); ?> — Additional User Information</h3>
        <?php if ( $ro ) : ?><p class="description">Your library, system and location codes are set by an administrator. Contact them to change these.</p><?php endif; ?>
        <table class="form-table">

            <tr><th scope="row"><label for="institution">Institution / Library Name</label></th>
                <td><input type="text" name="institution" id="institution"<?php echo $rattr; ?>
                    value="<?php echo esc_attr( get_user_meta( $uid, 'institution', true ) ); ?>"
                    class="regular-text"></td></tr>

            <tr><th scope="row"><label for="home_system">Home Library System</label></th>
                <td>
                    <select name="home_system" id="home_system"<?php echo $ro ? ' disabled' : ''; ?>>
                        <?php foreach ( $systems as $code => $label ): ?>
                        <option value="<?php echo esc_attr( $code ); ?>"
                            <?php selected( $home_sys, $code ); ?>>
                            <?php echo esc_html( $label ); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </td></tr>

            <tr><th scope="row"><label for="phone">Work Phone</label></th>
                <td><input type="text" name="phone" id="phone"
                    value="<?php echo esc_attr( get_user_meta( $uid, 'phone', true ) ); ?>"
                    class="regular-text"></td></tr>

            <tr><th scope="row"><label for="alt_email">Additional / CC Email</label></th>
                <td><input type="email" name="alt_email" id="alt_email"
                    value="<?php echo esc_attr( get_user_meta( $uid, 'alt_email', true ) ); ?>"
                    class="regular-text">
                    <p class="description">Confirmation emails are also sent here.</p></td></tr>

            <tr><th scope="row"><label for="address_loc_code">LOC Location Code</label></th>
                <td><input type="text" name="address_loc_code" id="address_loc_code"<?php echo $rattr; ?>
                    value="<?php echo esc_attr( get_user_meta( $uid, 'address_loc_code', true ) ); ?>"
                    class="regular-text">
                    <p class="description">Primary MARC Organization Code.
                    <a href="https://www.loc.gov/marc/organizations/" target="_blank" rel="noopener">Look up<span class="screen-reader-text"> (opens in a new tab)</span></a></p></td></tr>

            <tr><th scope="row"><label for="seal_extra_locs">Additional Managed LOC Codes</label></th>
                <td><input type="text" name="seal_extra_locs" id="seal_extra_locs"<?php echo $rattr; ?>
                    value="<?php echo esc_attr( get_user_meta( $uid, 'seal_extra_locs', true ) ); ?>"
                    class="regular-text">
                    <p class="description">Comma-separated codes for libraries this person manages beyond their primary.
                    Example: <code>NHIGS,NWATTJ</code></p></td></tr>

            <tr><th scope="row"><label for="oclc_symbol">OCLC Symbol</label></th>
                <td><input type="text" name="oclc_symbol" id="oclc_symbol"<?php echo $rattr; ?>
                    value="<?php echo esc_attr( get_user_meta( $uid, 'oclc_symbol', true ) ); ?>"
                    class="regular-text"></td></tr>

            <tr><th scope="row">Mailing Address</th>
                <td><?php self::render_library_address( $uid ); ?></td></tr>

        </table>
        <?php
    }

    /**
     * The mailing address is not kept per person: requests use the address on the library's profile
     * (Library-Data table) for the LOC code chosen on the request page, as SEAL-3 does. Shown here
     * read-only so staff can see what will be used and where to change it.
     */
    public static function render_library_address( int $uid ): void {
        $locs = $uid ? SEAL_Functions::all_locs( $uid ) : [];
        echo '<p class="description">Comes from the library\'s profile for the LOC code above (and any additional codes), not from this account. '
           . 'Change it under <a href="' . esc_url( home_url( '/libprofile' ) ) . '">Library Profile</a>.</p>';
        if ( ! $locs ) { echo '<p><em>No LOC code yet, so no library address.</em></p>'; return; }
        foreach ( $locs as $loc ) {
            $p = SEAL_Functions::fetch_lib_profile( $loc );
            echo '<p style="margin:8px 0 0"><strong>' . esc_html( $p['ok'] ? $p['Name'] . ' (' . $loc . ')' : $loc ) . '</strong><br>';
            if ( ! $p['ok'] ) {
                echo '<em>No library with this code in the directory.</em>';
            } elseif ( trim( $p['street1'] . $p['street2'] . $p['citystzip'] ) === '' ) {
                echo '<em>No mailing address on the library profile.</em>';
            } else {
                echo implode( '<br>', array_map( 'esc_html', array_filter( [ $p['street1'], $p['street2'], $p['citystzip'] ], 'strlen' ) ) );
            }
            echo '</p>';
        }
    }

    // ── Save fields ───────────────────────────────────────────────────────────

    /**
     * Fields that decide which libraries and system a person may act for. On the self-service profile
     * screen (wp-admin/profile.php) these are read-only: a member of staff must not be able to give
     * themselves another library's LOC code or another system by posting the form.
     * Administrators (edit_users) can change them; a new registrant supplies them once at sign-up.
     */
    private const SCOPE_FIELDS = [ 'institution', 'home_system', 'address_loc_code', 'seal_extra_locs', 'oclc_symbol' ];

    /**
     * True when the current user may set the fields that decide which library an account acts for (LOC code,
     * home system): users who can edit users, or a visitor registering themselves.
     */
    private static function can_set_scope( int $user_id ): bool {
        return doing_action( 'user_register' ) ? ! is_user_logged_in() || current_user_can( 'edit_users' ) : current_user_can( 'edit_users' );
    }

    /**
     * Saves the ILL profile fields from a profile screen or the registration form. Scope fields need
     * can_set_scope(); extra LOC codes are administrators only.
     */
    public static function save_fields( int $user_id ): void {
        if ( ! doing_action( 'user_register' ) && ! current_user_can( 'edit_user', $user_id ) ) { return; }
        if ( ! isset( $_POST['_wpnonce'] ) && ! doing_action( 'user_register' ) ) { return; }

        $scope_ok = self::can_set_scope( $user_id );

        // No delivery_* fields: the mailing address comes from the library profile (see render_library_address).
        // Values saved by older versions are left in place, just no longer edited or used.
        $text = [ 'institution', 'home_system', 'phone', 'address_loc_code', 'oclc_symbol' ];
        foreach ( $text as $f ) {
            if ( in_array( $f, self::SCOPE_FIELDS, true ) && ! $scope_ok ) { continue; }
            if ( isset( $_POST[ $f ] ) ) {
                update_user_meta( $user_id, $f, sanitize_text_field( wp_unslash( $_POST[ $f ] ) ) );
            }
        }
        if ( isset( $_POST['alt_email'] ) ) {
            update_user_meta( $user_id, 'alt_email', sanitize_email( wp_unslash( $_POST['alt_email'] ) ) );
        }
        // Extra LOC codes give access to other libraries: administrators only, never at self-registration.
        if ( isset( $_POST['seal_extra_locs'] ) && ( doing_action( 'user_register' ) ? current_user_can( 'edit_users' ) : $scope_ok ) ) {
            $locs = strtoupper( sanitize_text_field( wp_unslash( $_POST['seal_extra_locs'] ) ) );
            $locs = preg_replace( '/\s+/',  '',  $locs );
            $locs = preg_replace( '/,+/',   ',', $locs );
            $locs = trim( $locs, ',' );
            update_user_meta( $user_id, 'seal_extra_locs', $locs );
        }
        // first_name / last_name (from registration form)
        foreach ( [ 'first_name', 'last_name' ] as $f ) {
            if ( isset( $_POST[ $f ] ) ) {
                update_user_meta( $user_id, $f, sanitize_text_field( wp_unslash( $_POST[ $f ] ) ) );
            }
        }
    }

    // ── Registration form ─────────────────────────────────────────────────────

    /** The extra fields on the registration form: name, library name, library system, LOC code, phone. */
    public static function render_registration_fields(): void {
        $systems = self::system_options();
        $sel     = sanitize_text_field( wp_unslash( $_POST['home_system'] ?? 'SE' ) );
        ?>
        <p><label for="institution">Library Name <span aria-hidden="true">*</span><br>
            <input type="text" name="institution" id="institution" class="input"
                value="<?php echo esc_attr( wp_unslash( $_POST['institution'] ?? '' ) ); ?>"
                required aria-required="true">
        </label></p>
        <p><label for="first_name">First Name <span aria-hidden="true">*</span><br>
            <input type="text" name="first_name" id="first_name" class="input"
                value="<?php echo esc_attr( wp_unslash( $_POST['first_name'] ?? '' ) ); ?>"
                required aria-required="true">
        </label></p>
        <p><label for="last_name">Last Name <span aria-hidden="true">*</span><br>
            <input type="text" name="last_name" id="last_name" class="input"
                value="<?php echo esc_attr( wp_unslash( $_POST['last_name'] ?? '' ) ); ?>"
                required aria-required="true">
        </label></p>
        <p><label for="home_system">Home Library System<br>
            <select name="home_system" id="home_system">
                <?php foreach ( $systems as $code => $label ): ?>
                <option value="<?php echo esc_attr( $code ); ?>" <?php selected( $sel, $code ); ?>>
                    <?php echo esc_html( $label ); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </label></p>
        <p><label for="phone">Work Phone<br>
            <input type="text" name="phone" id="phone" class="input"
                value="<?php echo esc_attr( wp_unslash( $_POST['phone'] ?? '' ) ); ?>">
        </label></p>
        <p><label for="alt_email">Additional Email<br>
            <input type="email" name="alt_email" id="alt_email" class="input"
                value="<?php echo esc_attr( wp_unslash( $_POST['alt_email'] ?? '' ) ); ?>">
        </label></p>
        <p><label for="address_loc_code">LOC Location Code (optional)<br>
            <input type="text" name="address_loc_code" id="address_loc_code" class="input"
                value="<?php echo esc_attr( wp_unslash( $_POST['address_loc_code'] ?? '' ) ); ?>"
                aria-describedby="address_loc_code_help">
        </label>
        <span id="address_loc_code_help" class="description seal-field-help">You can check the
            <a href="<?php echo esc_url( home_url( '/illdir/' ) ); ?>" target="_blank" rel="noopener noreferrer">ILL Directory<span class="screen-reader-text"> (opens in a new tab)</span></a>
            if you are unsure of your location.</span></p>
        <p><label for="oclc_symbol">OCLC Symbol (optional)<br>
            <input type="text" name="oclc_symbol" id="oclc_symbol" class="input"
                value="<?php echo esc_attr( wp_unslash( $_POST['oclc_symbol'] ?? '' ) ); ?>">
        </label></p>
        <?php
    }

    /** Requires library name, first name and last name on the registration form. */
    public static function validate_registration( WP_Error $errors, string $login, string $email ): WP_Error {
        foreach ( [ 'institution' => 'Library Name', 'first_name' => 'First Name', 'last_name' => 'Last Name' ] as $f => $label ) {
            if ( empty( trim( wp_unslash( $_POST[ $f ] ?? '' ) ) ) ) {
                $errors->add( $f . '_error', "<strong>Error:</strong> {$label} is required." );
            }
        }
        return $errors;
    }

    // ── Users list columns ────────────────────────────────────────────────────

    /** Adds Institution and Home System columns to the Users list (and removes Posts). */
    public static function add_columns( array $cols ): array {
        unset( $cols['posts'] );
        $out = [];
        foreach ( $cols as $k => $v ) {
            $out[ $k ] = $v;
            if ( $k === 'role' ) {
                $out['institution']      = 'Library Name';
                $out['home_system']      = 'Home System';
                $out['address_loc_code'] = 'LOC Code';
                $out['seal_extra_locs']  = 'Extra LOCs';
            }
        }
        return $out;
    }

    /** The cell contents for those columns. */
    public static function column_data( string $val, string $col, int $uid ): string {
        $map = [
            'institution'      => 'institution',
            'home_system'      => 'home_system',
            'address_loc_code' => 'address_loc_code',
            'seal_extra_locs'  => 'seal_extra_locs',
        ];
        return isset( $map[ $col ] )
            ? esc_html( (string) get_user_meta( $uid, $map[ $col ], true ) )
            : $val;
    }

    /** Makes those columns sortable. */
    public static function sortable_columns( array $cols ): array {
        $cols['institution'] = 'institution';
        $cols['home_system'] = 'home_system';
        return $cols;
    }

    /**
     * Sorts the Users list by institution or home system, and lets the Users search also match those fields.
     */
    public static function sort_by_meta( WP_User_Query $q ): void {
        global $pagenow;
        if ( ! is_admin() || $pagenow !== 'users.php' ) { return; }
        $ob = $q->get( 'orderby' );
        if ( in_array( $ob, [ 'institution', 'home_system' ], true ) ) {
            $q->set( 'meta_key', $ob );
            $q->set( 'orderby', 'meta_value' );
        }
    }

    // ── Users list search ─────────────────────────────────────────────────────

    /** Meta fields the Users list search also looks in. */
    private const SEARCH_META = [ 'first_name', 'last_name', 'institution', 'address_loc_code', 'seal_extra_locs' ];

    /** True when the Users screen is running a search. */
    private static function is_users_search( $q ): bool {
        global $pagenow;
        return is_admin() && $pagenow === 'users.php' && trim( (string) $q->get( 'search' ), '* ' ) !== '';
    }

    /** WordPress picks columns from the look of the term (an "@" means email only); always search all of these. */
    public static function search_columns( array $cols, string $search, $q ): array {
        return self::is_users_search( $q ) ? array_values( array_unique( array_merge( $cols, [ 'user_login', 'user_email', 'user_nicename', 'display_name' ] ) ) ) : $cols;
    }

    /** Add "or a matching name / library name / LOC code" to WordPress's own search condition. */
    public static function search_meta( WP_User_Query $q ): void {
        if ( ! self::is_users_search( $q ) ) { return; }
        global $wpdb;
        $term = trim( (string) $q->get( 'search' ), '* ' );
        $keys = "'" . implode( "','", array_map( 'esc_sql', self::SEARCH_META ) ) . "'";
        $sub  = $wpdb->prepare( "{$wpdb->users}.ID IN (SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key IN ({$keys}) AND meta_value LIKE %s)", '%' . $wpdb->esc_like( $term ) . '%' );
        // WordPress's search condition is " AND (col LIKE ... OR col LIKE ...)": put ours inside it as one more OR.
        $new = preg_replace( '/ AND \((?=\s*(?:user_login|user_email|user_url|user_nicename|display_name|ID)\b)/', ' AND (' . $sub . ' OR ', $q->query_where, 1, $n );
        if ( $n === 1 ) { $q->query_where = $new; }
    }

    /** Spacing so the longer registration form fits the login screen. */
    public static function registration_spacing_css(): void {
        if ( isset( $_GET['action'] ) && sanitize_key( $_GET['action'] ) === 'register' ) {
            echo '<style>body.login{padding-top:5%!important;overflow-y:auto!important;}body.login #login{margin-top:20px!important;}'
               . '#registerform .seal-field-help{display:block;margin:4px 0 16px;font-size:13px;line-height:1.4;color:#50575e;}</style>';
        }
    }

    /** Removes WordPress's default "Register For This Site" heading from the registration form. */
    public static function drop_register_notice( $message ): string {
        return trim( wp_strip_all_tags( (string) $message ) ) === 'Register For This Site' ? '' : (string) $message;
    }

    // ── System options list ───────────────────────────────────────────────────
    // Built from seal_config() so a new deployment gets the right org name.
    // Filter 'seal_system_options' to customise the list.

    public static function system_options(): array {
        return SEAL_Org_Rules::systems();
    }
}
