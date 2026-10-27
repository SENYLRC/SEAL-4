<?php
/**
 * SEAL_Module_LoginURL — serves the login form at a custom slug and returns a
 * normal 404 for /wp-login.php and for logged-out visitors to /wp-admin.
 *
 * Every URL WordPress builds for wp-login.php (login, logout, lost password,
 * register, reset links in emails, redirects) is rewritten to the new slug.
 *
 * This reduces bot noise; it is not a substitute for lockouts, strong
 * passwords and 2FA.
 *
 * Requires pretty permalinks and a single-site install. Emergency off switch:
 * define ILL_DISABLE_LOGIN_URL as true in wp-config.php (the older SEAL_DISABLE_LOGIN_URL also works).
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class SEAL_Module_LoginURL {

    private static bool $serve_login = false;
    private static bool $block_login = false;

    /** The custom login address slug (default staff-login). */
    public static function slug(): string {
        $s = sanitize_title( seal_config( 'lu_slug' ) );
        return $s !== '' ? $s : 'staff-login';
    }

    /** The full custom login address. */
    public static function login_url( $scheme = null ): string {
        return set_url_scheme( home_url( '/' . self::slug() . '/' ), $scheme );
    }

    /**
     * True when the custom login URL can work: not switched off in wp-config.php, pretty permalinks on, not
     * multisite.
     */
    public static function is_active(): bool {
        return ! seal_login_url_disabled()
            && (bool) get_option( 'permalink_structure' )
            && ! is_multisite();
    }

    /**
     * Serves the login form at the custom address and hides /wp-login.php and /wp-admin from logged-out
     * visitors.
     */
    public static function init(): void {
        if ( ! self::is_active() ) { return; }

        global $pagenow;

        $home = trim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
        $req  = trim( rawurldecode( (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ) ), '/' );
        if ( $home !== '' && strpos( $req . '/', $home . '/' ) === 0 ) {
            $req = trim( substr( $req, strlen( $home ) ), '/' );
        }

        $action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';

        if ( basename( $req ) === 'wp-login.php' || $req === 'wp-login' ) {
            if ( $action !== 'postpass' ) {
                self::$block_login = true;
                $pagenow = 'index.php';
            }
        } elseif ( $req === self::slug() ) {
            self::$serve_login = true;
            $pagenow = 'wp-login.php';
        }

        add_action( 'wp_loaded',  [ __CLASS__, 'wp_loaded' ] );
        add_filter( 'site_url',   [ __CLASS__, 'filter_site_url' ], 100, 4 );
        add_filter( 'wp_redirect', [ __CLASS__, 'filter_redirect' ], 100, 1 );
        remove_action( 'template_redirect', 'wp_redirect_admin_locations', 1000 );
    }

    /** Sends logged-out visitors to /wp-admin or /wp-login.php to a normal 404 page. */
    public static function wp_loaded(): void {
        global $pagenow;

        if ( is_admin() && ! is_user_logged_in()
            && ! wp_doing_ajax() && ! wp_doing_cron()
            && ! ( defined( 'WP_CLI' ) && WP_CLI )
            && $pagenow !== 'admin-post.php' ) {
            self::serve_404();
        }

        if ( self::$block_login ) {
            self::serve_404();
        }

        if ( self::$serve_login ) {
            global $error, $interim_login, $action, $user_login;
            require_once ABSPATH . 'wp-login.php';
            exit;
        }
    }

    /** Renders the theme's 404 page for the current request and stops. */
    private static function serve_404(): void {
        global $pagenow;
        $_SERVER['REQUEST_URI']  = '/' . str_repeat( '-/', 10 );
        $_SERVER['QUERY_STRING'] = '';
        $_GET = $_POST = $_REQUEST = [];
        $pagenow = 'index.php';
        if ( ! defined( 'WP_USE_THEMES' ) ) { define( 'WP_USE_THEMES', true ); }
        wp();
        status_header( 404 );
        nocache_headers();
        require_once ABSPATH . WPINC . '/template-loader.php';
        exit;
    }

    /** Rewrites links WordPress builds to wp-login.php so they use the custom address. */
    public static function filter_site_url( $url, $path = '', $scheme = null, $blog_id = null ) {
        $url = (string) $url;
        if ( strpos( $url, 'wp-login.php' ) === false || strpos( $url, 'wp-login.php?action=postpass' ) !== false ) {
            return $url;
        }
        $query = (string) wp_parse_url( $url, PHP_URL_QUERY );
        $new   = self::login_url( $scheme );
        return $query !== '' ? $new . '?' . $query : $new;
    }

    /** Rewrites redirects to wp-login.php so they use the custom address. */
    public static function filter_redirect( $location ) {
        $location = (string) $location;
        if ( strpos( $location, 'wp-login.php' ) === false || strpos( $location, 'wp-login.php?action=postpass' ) !== false ) {
            return $location;
        }
        $query = (string) wp_parse_url( $location, PHP_URL_QUERY );
        $new   = self::login_url();
        return $query !== '' ? $new . '?' . $query : $new;
    }
}
