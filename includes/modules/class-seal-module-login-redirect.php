<?php
/**
 * SEAL_Module_LoginRedirect
 *
 * After login, sends libstaff / libsys users to /illuser/ (or whatever
 * the configured redirect path is). Admins go to their normal destination.
 * The redirect path is configurable in the admin panel under
 * ILL System → User & Registration Settings.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class SEAL_Module_LoginRedirect {

    /** Hooks the login redirect. */
    public static function init(): void {
        add_filter( 'login_redirect', [ __CLASS__, 'redirect' ], 10, 3 );
    }

    /**
     * After login, sends ILL staff who weren't heading anywhere in particular to the Staff Login Redirect page.
     * Other roles, and logins with a specific destination outside wp-admin, are left alone.
     */
    public static function redirect( string $redirect_to, string $request, $user ): string {
        if ( ! isset( $user->roles ) || ! is_array( $user->roles ) ) {
            return $redirect_to;
        }

        $is_staff  = in_array( 'libstaff', $user->roles, true );
        $is_libsys = in_array( 'libsys',   $user->roles, true );

        if ( $is_staff || $is_libsys ) {
            $going_to_admin = empty( $redirect_to ) || strpos( $redirect_to, 'wp-admin' ) !== false;
            if ( $going_to_admin ) {
                // Build redirect from a server-side config value, not user input
                $path = seal_config( 'login_redirect_path' ) ?: '/illuser/';
                // Strip any protocol/host from the configured path (defence-in-depth)
                $path = '/' . ltrim( wp_make_link_relative( esc_url_raw( $path ) ), '/' );
                return home_url( $path );
            }
        }

        // For other roles, allow the WP-supplied redirect only if it stays on this site
        if ( $redirect_to && ! wp_validate_redirect( $redirect_to, false ) ) {
            return admin_url();
        }

        return $redirect_to;
    }
}
