<?php
/**
 * SEAL_Module_LoginScreen — brands the WordPress login, registration and
 * lost-password screens: logo (with real alt text for screen readers), welcome
 * message, and an optional split layout with a full-height background image.
 *
 * All values come from ILL → Security → Login Screen.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class SEAL_Module_LoginScreen {

    /**
     * Hooks the branded styles, logo link, logo text, welcome message and body classes on the login screens.
     */
    public static function init(): void {
        add_action( 'login_enqueue_scripts', [ __CLASS__, 'styles' ] );
        add_filter( 'login_headerurl',       [ __CLASS__, 'header_url' ] );
        add_filter( 'login_headertext',      [ __CLASS__, 'header_text' ] );
        add_filter( 'login_message',         [ __CLASS__, 'welcome' ] );
        add_filter( 'login_body_class',      [ __CLASS__, 'body_class' ] );
    }

    /** A URL made safe for use inside CSS url("..."). */
    private static function css_url( string $url ): string {
        $url = esc_url_raw( $url );
        return $url === '' ? '' : str_replace(
            [ '"', '\\', "\n", "\r", '(', ')' ],
            [ '%22', '%5C', '', '', '%28', '%29' ],
            $url
        );
    }

    /** Where the login logo links (the configured link, or the home page). */
    public static function header_url( $url ): string {
        $link = seal_config( 'ls_logo_link' );
        return $link !== '' ? esc_url( $link ) : esc_url( home_url( '/' ) );
    }

    /**
     * The logo's accessible text (the configured alt text, or the site name), escaped because wp-login.php
     * prints it raw.
     */
    public static function header_text( $text ): string {
        // wp-login.php prints this value without escaping, so escape it here.
        $alt = seal_config( 'ls_logo_alt' );
        if ( $alt !== '' ) { return esc_html( $alt ); }
        return seal_config( 'ls_logo_url' ) !== '' ? esc_html( seal_config( 'system_full_name' ) ) : (string) $text;
    }

    /** Adds the configured welcome message above the login form. */
    public static function welcome( $message ): string {
        $msg = trim( seal_config( 'ls_message' ) );
        if ( $msg === '' ) { return (string) $message; }
        return '<div class="seal-login-welcome">' . wpautop( wp_kses_post( $msg ) ) . '</div>' . $message;
    }

    /** Adds classes for the branded and split layouts. */
    public static function body_class( $classes ): array {
        $classes   = (array) $classes;
        $classes[] = 'seal-login';
        if ( seal_config( 'ls_split' ) === '1' && seal_config( 'ls_bg_url' ) !== '' ) {
            $classes[] = 'seal-login-split';
        }
        return $classes;
    }

    /** Prints the login screen CSS: logo, background image, layout and button color. */
    public static function styles(): void {
        $logo   = self::css_url( seal_config( 'ls_logo_url' ) );
        $bg     = self::css_url( seal_config( 'ls_bg_url' ) );
        $accent = sanitize_hex_color( seal_config( 'ls_button_color' ) ) ?: '#1f3a5f';
        $split  = seal_config( 'ls_split' ) === '1' && $bg !== '';

        $css = '
        body.seal-login .button-primary{background:' . $accent . ';border-color:' . $accent . ';color:#fff;text-shadow:none;box-shadow:none;}
        body.seal-login .button-primary:hover,body.seal-login .button-primary:focus{background:' . $accent . ';border-color:' . $accent . ';filter:brightness(1.15);color:#fff;}
        body.seal-login a:focus-visible,body.seal-login input:focus-visible,body.seal-login .button:focus-visible{outline:3px solid #005ea2;outline-offset:2px;box-shadow:none;}
        .seal-login-welcome{border-left:4px solid ' . $accent . ';padding:6px 0 6px 14px;margin:0 0 24px;color:#1d2327;font-weight:600;line-height:1.5;}
        .seal-login-welcome p{margin:0 0 .6em;}
        .seal-login-welcome p:last-child{margin-bottom:0;}';

        if ( $logo !== '' ) {
            $css .= '
        body.seal-login #login h1{text-align:center;}
        body.seal-login #login h1 a{background-image:url("' . $logo . '");background-size:contain;background-position:center;background-repeat:no-repeat;width:100%;max-width:320px;height:150px;margin:0 auto 20px;}';
        }

        if ( $split ) {
            $css .= '
        body.seal-login-split{background:#fff;min-height:100vh;display:flex;align-items:flex-start;}
        body.seal-login-split::after{content:"";position:fixed;top:0;right:0;bottom:0;width:55%;background:#dfe3e8 url("' . $bg . '") center/cover no-repeat;}
        body.seal-login-split #login{position:relative;z-index:1;box-sizing:border-box;width:45%;max-width:none;margin:auto 0;padding:4vh 0;}
        body.seal-login-split #login > *{max-width:360px;margin-left:auto;margin-right:auto;}
        body.seal-login-split #loginform,body.seal-login-split #registerform,body.seal-login-split #lostpasswordform,body.seal-login-split #resetpassform{border:0;box-shadow:none;background:transparent;padding:0;margin-top:0;}
        body.seal-login-split #nav,body.seal-login-split #backtoblog{text-align:left;padding-left:0;}
        @media (max-width:900px){
            body.seal-login-split{justify-content:center;}
            body.seal-login-split::after{display:none;}
            body.seal-login-split #login{width:100%;max-width:400px;padding:5vh 20px;}
        }';
        } elseif ( $bg !== '' ) {
            $css .= '
        body.seal-login{background:#dfe3e8 url("' . $bg . '") center/cover no-repeat fixed;}';
        }

        wp_add_inline_style( 'login', $css );
    }
}
