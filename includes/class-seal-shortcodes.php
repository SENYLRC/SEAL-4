<?php
/**
 * SEAL_Shortcodes — shortcodes for embedding SEAL pages in WP content.
 *
 * [seal_ill page="request" ...]  — embed any ILL page inline
 * [seal_user_info]               — UserDisplayTest: show current user's name/email/institution/phone
 *
 * These replace the xyz-ips snippet="UserDisplayTest" and
 * xyz-ips snippet="SearchBlock" calls used in the original theme.
 * [ill_search]                  — homepage catalog search block (settings-driven)
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class SEAL_Shortcodes {

    /**
     * Registers the shortcodes: [seal_ill page="..."] (an ILL page inside an ordinary WordPress page),
     * [seal_user_info] and the catalog search block [ill_search] (also [seal_search]).
     */
    public static function init(): void {
        add_shortcode( 'seal_ill',       [ __CLASS__, 'render_page'      ] );
        add_shortcode( 'seal_user_info', [ __CLASS__, 'render_user_info' ] );
        // Homepage catalog search block — replaces the raw PHP/HTML block
        // (xyz-ips snippet="SearchBlock") pasted into the original home page.
        add_shortcode( 'ill_search',     [ __CLASS__, 'render_search_block' ] );
        // Back-compat alias
        add_shortcode( 'seal_search',    [ __CLASS__, 'render_search_block' ] );
    }

    // ── [seal_ill page="..."] ─────────────────────────────────────────────────

    /**
     * [seal_ill page="<route>"]: renders that ILL page in place, applying the same capability check as the
     * route itself.
     */
    public static function render_page( array $atts ): string {
        $atts = shortcode_atts( [ 'page' => '' ], $atts, 'seal_ill' );
        $page = sanitize_key( $atts['page'] );

        if ( ! $page ) {
            return '<p>' . esc_html( seal_config( 'system_name' ) . ' ILL: No page specified.' ) . '</p>';
        }

        if ( ! isset( SEAL_Router::ROUTES[ $page ] ) ) {
            return '<p>' . esc_html( seal_config( 'system_name' ) . ' ILL: Unknown page.' ) . '</p>';
        }

        // Stand-alone documents (pull slips) print a whole page of their own; they cannot be embedded.
        if ( in_array( $page, SEAL_Router::STANDALONE_ROUTES, true ) ) {
            return '<p>' . esc_html( seal_config( 'system_name' ) . ' ILL: That page cannot be embedded.' ) . '</p>';
        }

        [ $class, $cap ] = SEAL_Router::ROUTES[ $page ];

        if ( ! is_user_logged_in() ) {
            return '<p>' . esc_html__( 'You must be logged in to access this page.', 'seal-ill' ) . '</p>';
        }

        if ( ! current_user_can( $cap ) ) {
            return '<p>' . esc_html__( 'Access Denied.', 'seal-ill' ) . '</p>';
        }

        // The two-factor gate lets ordinary WordPress pages through, so an embedded ILL page checks set-up itself.
        if ( $page !== 'illuser' && class_exists( 'SEAL_Module_2FA' ) && SEAL_Module_2FA::needs_setup( wp_get_current_user() ) ) {
            return '<p>' . esc_html__( 'Your role requires two-factor authentication.', 'seal-ill' ) . ' <a href="'
                . esc_url( add_query_arg( 'seal_2fa', 'setup', home_url( '/illuser' ) ) ) . '">'
                . esc_html__( 'Set it up on My Profile', 'seal-ill' ) . '</a> ' . esc_html__( 'first.', 'seal-ill' ) . '</p>';
        }

        try {
            SEAL_DB::get();
        } catch ( RuntimeException $e ) {
            return '<p>' . esc_html( $e->getMessage() ) . '</p>';
        }

        ob_start();
        $handler = new $class();
        $handler->render();
        return ob_get_clean();
    }

    // ── [seal_user_info] — replaces xyz-ips snippet="UserDisplayTest" ────────

    /** [seal_user_info]: the signed-in user's name, institution, phone and last login, or a log-in prompt. */
    public static function render_user_info( array $atts = [] ): string {
        if ( ! is_user_logged_in() ) {
            return '<p>Please log in to see your information.</p>';
        }

        $user        = wp_get_current_user();
        $institution = esc_html( (string) get_user_meta( $user->ID, 'institution', true ) );
        $phone       = esc_html( (string) get_user_meta( $user->ID, 'phone',       true ) );
        $last_login  = '';
        if ( class_exists( 'SEAL_Module_LastLogin' ) ) {
            $last_login = SEAL_Module_LastLogin::get_last_login( $user->ID );
        }

        $out  = '<div class="seal-user-info-block">';
        $out .= '<p><strong>Name:</strong> '        . esc_html( $user->display_name )   . '</p>';
        $out .= '<p><strong>Email:</strong> '       . esc_html( $user->user_email )      . '</p>';
        if ( $institution ) {
            $out .= '<p><strong>Institution:</strong> ' . $institution . '</p>';
        }
        if ( $phone ) {
            $out .= '<p><strong>Phone:</strong> ' . $phone . '</p>';
        }
        if ( $last_login ) {
            $out .= '<p><strong>Last Login:</strong> ' . esc_html( $last_login ) . '</p>';
        }
        $out .= '</div>';

        return $out;
    }

    // ── [ill_search] — homepage catalog search block ─────────────────────────
    // Fully driven by ILL → Search & Catalog settings: search page URL,
    // advanced search / help links, categories JS URL, and the category list.

    public static function render_search_block( array $atts = [] ): string {
        if ( class_exists( 'SEAL_Module_CatalogSearch' ) ) {
            return SEAL_Module_CatalogSearch::render_home_block();
        }
        return '';
    }
}
