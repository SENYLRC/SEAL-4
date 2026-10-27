<?php
/**
 * SEAL_Module_ExportUsers
 *
 * Adds a "Export Users (CSV)" page under Users in the WP admin.
 * Exports all users with every ILL custom field.
 * Filename is prefixed with the system short name from seal_config().
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class SEAL_Module_ExportUsers {

    /** Adds the Export Users screen and its download handler. */
    public static function init(): void {
        add_action( 'admin_menu',                            [ __CLASS__, 'add_menu'    ] );
        add_action( 'admin_post_seal_export_users_csv',      [ __CLASS__, 'export_csv'  ] );
    }

    /** Adds "Export {System} Users" under the WordPress Users menu. */
    public static function add_menu(): void {
        $sys = seal_config( 'system_name' );
        add_users_page(
            'Export ' . $sys . ' Users',
            'Export Users (CSV)',
            'seal_export',
            'seal-export-users',
            [ __CLASS__, 'render_page' ]
        );
    }

    /** The export screen with the download button (capability seal_export). */
    public static function render_page(): void {
        if ( ! current_user_can( 'seal_export' ) ) { wp_die( 'Access denied.' ); }
        $sys = seal_config( 'system_name' );
        $url = esc_url( wp_nonce_url(
            admin_url( 'admin-post.php?action=seal_export_users_csv' ),
            'seal_export_users'
        ) );
        ?>
        <div class="wrap">
            <h1><?php echo esc_html( $sys ); ?> — Export Users</h1>
            <p>Downloads a CSV of all registered users including all ILL profile fields.</p>
            <a href="<?php echo $url; ?>" class="button button-primary">⬇️ Download CSV</a>
        </div>
        <?php
    }

    /**
     * Streams every user's account and ILL profile fields as CSV after the nonce check. Cells are made formula-
     * safe.
     */
    public static function export_csv(): void {
        if ( ! current_user_can( 'seal_export' ) ) { wp_die( 'Access denied.' ); }
        check_admin_referer( 'seal_export_users' );

        while ( ob_get_level() ) { ob_end_clean(); }

        $sys      = seal_config( 'system_name' );
        $filename = strtolower( $sys ) . '_users_' . gmdate( 'Y-m-d_His' ) . '.csv';

        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        $out = fopen( 'php://output', 'w' );

        fputcsv( $out, [
            'Username', 'Email', 'First Name', 'Last Name', 'Role(s)',
            'Institution / Library', 'Home System', 'LOC Code', 'Extra LOCs',
            'OCLC Symbol', 'Work Phone', 'Alt Email',
            'Library Address 1', 'Library Address 2', 'Library City/State/Zip',
            'Registered',
        ] );

        $profiles = [];   // one Library-Data lookup per LOC code
        foreach ( get_users( [ 'number' => -1 ] ) as $user ) {
            $uid = $user->ID;
            $loc = SEAL_Functions::primary_loc( $uid );
            if ( $loc !== '' && ! isset( $profiles[ $loc ] ) ) { $profiles[ $loc ] = SEAL_Functions::fetch_lib_profile( $loc ); }
            $lib = $loc !== '' ? $profiles[ $loc ] : [ 'ok' => false ];
            fputcsv( $out, SEAL_Functions::csv_safe_row( [
                $user->user_login,
                $user->user_email,
                get_user_meta( $uid, 'first_name',        true ),
                get_user_meta( $uid, 'last_name',         true ),
                implode( ', ', $user->roles ),
                get_user_meta( $uid, 'institution',       true ),
                get_user_meta( $uid, 'home_system',       true ),
                get_user_meta( $uid, 'address_loc_code',  true ),
                get_user_meta( $uid, 'seal_extra_locs',   true ),
                get_user_meta( $uid, 'oclc_symbol',       true ),
                get_user_meta( $uid, 'phone',             true ),
                get_user_meta( $uid, 'alt_email',         true ),
                $lib['ok'] ? $lib['street1'] : '',    // mailing address is the library's (Library-Data), not the person's
                $lib['ok'] ? $lib['street2'] : '',
                $lib['ok'] ? $lib['citystzip'] : '',
                $user->user_registered,
            ] ) );
        }

        fclose( $out );
        exit;
    }
}
