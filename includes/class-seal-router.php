<?php
/**
 * SEAL_Router — maps WordPress virtual pages to SEAL handlers.
 *
 * Uses a "virtual post" technique: instead of calling get_header/get_footer
 * directly (which bypasses the theme's sidebar and layout), we hook into
 * WP's template system and inject our content through the_content filter.
 * This means the theme renders exactly as it does for any other page —
 * sidebar, widgets, navigation — and our content appears in the content area.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

// Load page handlers
require_once SEAL_ILL_PLUGIN_DIR . 'includes/pages/class-seal-page-base.php';
require_once SEAL_ILL_PLUGIN_DIR . 'includes/pages/class-seal-page-request.php';
require_once SEAL_ILL_PLUGIN_DIR . 'includes/pages/class-seal-page-respond.php';
require_once SEAL_ILL_PLUGIN_DIR . 'includes/pages/class-seal-page-status.php';
require_once SEAL_ILL_PLUGIN_DIR . 'includes/pages/class-seal-page-status-confirmation.php';
require_once SEAL_ILL_PLUGIN_DIR . 'includes/pages/class-seal-page-renew.php';
require_once SEAL_ILL_PLUGIN_DIR . 'includes/pages/class-seal-page-requesthistory.php';
require_once SEAL_ILL_PLUGIN_DIR . 'includes/pages/class-seal-page-allrequests.php';
require_once SEAL_ILL_PLUGIN_DIR . 'includes/pages/class-seal-page-allrequests-ls.php';
require_once SEAL_ILL_PLUGIN_DIR . 'includes/pages/class-seal-page-bulkaction.php';
require_once SEAL_ILL_PLUGIN_DIR . 'includes/pages/class-seal-page-lenderhistory.php';
require_once SEAL_ILL_PLUGIN_DIR . 'includes/pages/class-seal-page-adminlib.php';
require_once SEAL_ILL_PLUGIN_DIR . 'includes/pages/class-seal-page-adminlib-ls.php';
require_once SEAL_ILL_PLUGIN_DIR . 'includes/pages/class-seal-page-adminaccounts.php';
require_once SEAL_ILL_PLUGIN_DIR . 'includes/pages/class-seal-page-libprofile.php';
require_once SEAL_ILL_PLUGIN_DIR . 'includes/pages/class-seal-page-libstaffaccounts.php';
require_once SEAL_ILL_PLUGIN_DIR . 'includes/pages/class-seal-page-libstats.php';
require_once SEAL_ILL_PLUGIN_DIR . 'includes/pages/class-seal-page-liblenderstat.php';
require_once SEAL_ILL_PLUGIN_DIR . 'includes/pages/class-seal-page-illdir.php';
require_once SEAL_ILL_PLUGIN_DIR . 'includes/pages/class-seal-page-illuser.php';
require_once SEAL_ILL_PLUGIN_DIR . 'includes/pages/class-seal-page-sealstats.php';
require_once SEAL_ILL_PLUGIN_DIR . 'includes/pages/class-seal-page-sealstats-ls.php';
require_once SEAL_ILL_PLUGIN_DIR . 'includes/pages/class-seal-page-systemstats.php';
require_once SEAL_ILL_PLUGIN_DIR . 'includes/pages/class-seal-page-export.php';
require_once SEAL_ILL_PLUGIN_DIR . 'includes/pages/class-seal-page-email-harvest.php';
require_once SEAL_ILL_PLUGIN_DIR . 'includes/pages/class-seal-page-pullslip.php';
require_once SEAL_ILL_PLUGIN_DIR . 'includes/pages/class-seal-page-illiad-mapping.php';
require_once SEAL_ILL_PLUGIN_DIR . 'includes/pages/class-seal-page-resend.php';
require_once SEAL_ILL_PLUGIN_DIR . 'includes/pages/class-seal-page-rota.php';
require_once SEAL_ILL_PLUGIN_DIR . 'includes/pages/class-seal-page-adminaccounts-ls.php';

class SEAL_Router {

    /** slug => [ class, required_cap ] */
    /** Routes reachable without logging in (the page itself limits what guests see). */
    const PUBLIC_ROUTES = [ 'illdir' ];

    /** Routes that print a complete document of their own (no theme, no sidebar). */
    const STANDALONE_ROUTES = [ 'pullslip' ];

    /** Old address => new address. Old bookmarks and menu links keep working (301). */
    const LEGACY_ROUTES = [ 'sealstats' => 'stats', 'sealstats-ls' => 'stats-ls' ];

    const ROUTES = [
        'request'             => [ 'SEAL_Page_Request',             'seal_request'       ],
        'respond'             => [ 'SEAL_Page_respond',             'seal_respond'       ],
        'status'              => [ 'SEAL_Page_status',              'seal_status'        ],
        'status-confirmation' => [ 'SEAL_Page_status_confirmation', 'seal_status'        ],
        'renew'               => [ 'SEAL_Page_renew',               'seal_renew'         ],
        'requesthistory'      => [ 'SEAL_Page_requesthistory',      'seal_view_own'      ],
        'allrequests'         => [ 'SEAL_Page_allrequests',         'seal_admin'         ],
        'allrequests-ls'      => [ 'SEAL_Page_allrequests_ls',      'seal_view_system'   ],
        'bulkaction'          => [ 'SEAL_Page_bulkaction',          'seal_bulkaction'    ],
        'lender-history'      => [ 'SEAL_Page_lenderhistory',       'seal_view_own'      ],
        'adminlib'            => [ 'SEAL_Page_adminlib',            'seal_admin'         ],
        'adminlib-ls'         => [ 'SEAL_Page_adminlib_ls',         'seal_adminlib_ls'   ],
        'adminlib-accounts'   => [ 'SEAL_Page_adminaccounts',       'seal_admin'         ],
        'adminlib-accounts-ls'=> [ 'SEAL_Page_adminaccounts_ls',    'seal_view_system'   ],
        'libprofile'          => [ 'SEAL_Page_libprofile',          'seal_request'       ],
        'libstaffaccounts'    => [ 'SEAL_Page_libstaffaccounts',    'seal_view_own'      ],
        'libstats'            => [ 'SEAL_Page_libstats',            'seal_view_own'      ],
        'liblenderstat'       => [ 'SEAL_Page_liblenderstat',       'seal_view_own'      ],
        'illdir'              => [ 'SEAL_Page_illdir',              'seal_request'       ],
        'illuser'             => [ 'SEAL_Page_illuser',             'seal_view_own'      ],
        'stats'               => [ 'SEAL_Page_sealstats',           'seal_sealstats'     ],
        'stats-ls'            => [ 'SEAL_Page_sealstats_ls',        'seal_stats_system'  ],
        'systemstats'         => [ 'SEAL_Page_systemstats',         'seal_systemstats'   ],
        'export'              => [ 'SEAL_Page_export',              'seal_export'        ],
        'email-harvest'       => [ 'SEAL_Page_email_harvest',       'seal_email_harvest' ],
        'pullslip'            => [ 'SEAL_Page_pullslip',            'seal_view_own'      ],
        'illiad-mapping'      => [ 'SEAL_Page_illiad_mapping',      'seal_admin'         ],
        'rota'                => [ 'SEAL_Page_rota',              'seal_admin'         ],
        'resend'              => [ 'SEAL_Page_resend',            'seal_admin'         ],
    ];

    /** Page titles shown in <title> and theme breadcrumbs */
    const TITLES = [
        'request'             => 'Submit ILL Request',
        'respond'             => 'Respond to ILL Request',
        'status'              => 'Update Request Status',
        'status-confirmation' => 'Status Confirmation',
        'renew'               => 'Renewal Request',
        'requesthistory'      => 'Borrowing Requests',
        'allrequests'         => 'All Requests',
        'allrequests-ls'      => 'System Requests',
        'bulkaction'          => 'Bulk Actions',
        'lender-history'      => 'Lending History',
        'adminlib'            => 'Library Directory',
        'adminlib-ls'         => 'Library Administration',
        'adminlib-accounts'   => 'User Accounts',
        'adminlib-accounts-ls'=> 'Manage Accounts',
        'libprofile'          => 'Library Profile',
        'libstaffaccounts'    => 'Library Staff Accounts',
        'libstats'            => 'Borrowing Statistics',
        'liblenderstat'       => 'Lending Statistics',
        'illdir'              => 'Directory of Participating Libraries',
        'illuser'             => 'My Profile',
        'stats'               => '{org} Statistics',
        'stats-ls'            => 'System Statistics',
        'systemstats'         => 'Overall System Statistics',
        'export'              => 'Export Data',
        'email-harvest'       => 'Staff Email Export',
        'pullslip'            => 'Pull Slip',
        'illiad-mapping'      => 'ILLiad Mapping',
        'rota'                => 'Automatic Fallback (Rota)',
        'resend'              => 'Resend a Request',
    ];

    /**
     * Resolve a route's display title, substituting the configured
     * System Name for the {system} placeholder.
     */
    public static function title( string $slug ): string {
        $title = self::TITLES[ $slug ] ?? ucwords( str_replace( '-', ' ', $slug ) );
        return str_replace( [ '{system}', '{org}' ], [ seal_config( 'system_name' ), seal_config( 'org_name' ) ?: 'System' ], $title );
    }

    // ── Current active route (set during dispatch) ─────────────────────────
    private static ?string $active_slug  = null;
    private static ?string $active_class = null;

    /**
     * Registers the rewrite rules, the seal_page query variable and the hooks that turn a matched route into a
     * page inside the theme (with the ILL sidebar).
     */
    public static function init(): void {
        add_action( 'init',              [ __CLASS__, 'add_rewrite_rules' ] );
        add_filter( 'query_vars',        [ __CLASS__, 'add_query_vars'   ] );

        // Fallback route matching that works even when pretty permalinks are
        // off ("Plain") or the rewrite rules were never flushed. Without this,
        // an unmatched /request etc. becomes a 404 and WP's 404-guess redirect
        // can silently send the visitor somewhere else with nothing logged.
        add_filter( 'request',           [ __CLASS__, 'map_request_path' ] );

        // Never let WP canonical-redirect one of our virtual routes away.
        add_filter( 'redirect_canonical', [ __CLASS__, 'no_canonical_redirect' ], 10, 2 );

        // We hook at wp (after query is resolved) rather than template_redirect
        // so we can inject the virtual post before the template is chosen.
        add_action( 'wp', [ __CLASS__, 'maybe_intercept' ] );
    }

    /**
     * 'request' filter — if the URL path's single segment matches one of our
     * routes, claim the request regardless of permalink settings.
     */
    public static function map_request_path( array $qv ): array {
        if ( ! empty( $qv['seal_page'] ) ) {
            return $qv; // rewrite rule already matched
        }

        $path = (string) wp_parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH );

        // Strip a subdirectory install prefix (e.g. /wp) if present
        $home_path = (string) wp_parse_url( home_url(), PHP_URL_PATH );
        if ( $home_path && $home_path !== '/' && str_starts_with( $path, $home_path ) ) {
            $path = substr( $path, strlen( $home_path ) );
        }

        $slug = trim( $path, '/' );
        if ( isset( self::LEGACY_ROUTES[ $slug ] ) ) {
            wp_safe_redirect( home_url( '/' . self::LEGACY_ROUTES[ $slug ] . '/' ), 301 );
            exit;
        }
        if ( $slug !== '' && isset( self::ROUTES[ $slug ] ) ) {
            return [ 'seal_page' => $slug ];
        }
        return $qv;
    }

    /**
     * For our virtual routes, allow canonical redirects that only fix the
     * scheme/host (http→https, www) but cancel any that change the PATH —
     * those are WP's 404-permalink guesses and would hijack the route.
     */
    public static function no_canonical_redirect( $redirect_url, $requested_url = '' ) {
        if ( ! get_query_var( 'seal_page' ) ) {
            return $redirect_url;
        }
        $rp = rtrim( (string) wp_parse_url( (string) $redirect_url,  PHP_URL_PATH ), '/' );
        $qp = rtrim( (string) wp_parse_url( (string) $requested_url, PHP_URL_PATH ), '/' );
        return ( $redirect_url && $rp === $qp ) ? $redirect_url : false;
    }

    /**
     * Maps each route slug (e.g. /request) to index.php?seal_page=<slug>. Flushed once after activation via the
     * seal_ill_flush_rewrite option.
     */
    public static function add_rewrite_rules(): void {
        foreach ( array_keys( self::ROUTES ) as $slug ) {
            add_rewrite_rule(
                '^' . preg_quote( $slug, '/' ) . '/?$',
                'index.php?seal_page=' . $slug,
                'top'
            );
        }
        // A new or removed page needs WordPress's rewrite rules rebuilt (otherwise its URL is a 404 until
        // someone saves Settings -> Permalinks). Done once, automatically, whenever the list of pages changes.
        $sig = md5( implode( ',', array_keys( self::ROUTES ) ) );
        if ( get_option( 'seal_routes_sig' ) !== $sig ) {
            flush_rewrite_rules( false );
            update_option( 'seal_routes_sig', $sig, true );
        }
    }

    /** Lets WordPress accept the seal_page query variable. */
    public static function add_query_vars( array $vars ): array {
        $vars[] = 'seal_page';
        return $vars;
    }

    /**
     * Called on the 'wp' action after WP has determined the query.
     * If this is an ILL route, we:
     *  1. Authenticate / check capability
     *  2. Set up a virtual WP_Post so the theme renders page.php (with sidebar)
     *  3. Hook into the_content to inject our page output
     *  4. Hook into wp_title / document_title_parts for the correct <title>
     */
    public static function maybe_intercept(): void {
        $slug = get_query_var( 'seal_page' );
        if ( ! $slug || ! isset( self::ROUTES[ $slug ] ) ) {
            return;
        }

        [ $class, $cap ] = self::ROUTES[ $slug ];

        // ── Scheme fix ──────────────────────────────────────────────────────
        // If the site runs on https but the handoff link used http (catalog
        // configs sometimes do), hop to https BEFORE the auth check. Secure
        // auth cookies are never sent over http, so without this the visitor
        // ping-pongs forever between wp-login and this page.
        if ( ! is_ssl() && str_starts_with( (string) home_url(), 'https://' ) ) {
            $target = set_url_scheme( home_url( wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' . $slug ) ), 'https' );
            wp_safe_redirect( $target, 301 );
            exit;
        }

        // ── Auth ────────────────────────────────────────────────────────────
        if ( ! in_array( $slug, self::PUBLIC_ROUTES, true ) ) {
            if ( ! is_user_logged_in() ) {
                auth_redirect();
                exit;
            }
            SEAL_Roles::require_cap( $cap );
        }

        // ── DB connection ────────────────────────────────────────────────────
        try {
            SEAL_DB::get();
        } catch ( RuntimeException $e ) {
            wp_die( esc_html( $e->getMessage() ), 'Database Error', [ 'response' => 503 ] );
        }

        // ── Standalone documents (pull slips) ───────────────────────────────
        if ( in_array( $slug, self::STANDALONE_ROUTES, true ) ) {
            ( new $class() )->render(); // prints the whole document and exits
            exit;
        }

        // ── Store for use in filters ─────────────────────────────────────────
        self::$active_slug  = $slug;
        self::$active_class = $class;

        // ── Enqueue assets ───────────────────────────────────────────────────
        // Must register here (on the 'wp' hook) because wp_enqueue_scripts has
        // not fired yet. Enqueuing from the page handler (which runs during
        // the_content) is too late and the stylesheet never loads.
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );

        // ── Virtual post — makes the theme think it is rendering a page ─────
        self::setup_virtual_post( $slug );

        // ── Inject content via the_content filter ────────────────────────────
        add_filter( 'the_content', [ __CLASS__, 'inject_content' ], 1 );

        add_filter( 'body_class', static function ( array $classes ) use ( $slug ): array {
            $classes[] = 'seal-route';
            $classes[] = 'seal-route-' . sanitize_html_class( $slug );
            return $classes;
        } );

        // ── Page title ───────────────────────────────────────────────────────
        add_filter( 'document_title_parts', [ __CLASS__, 'filter_title' ] );
        add_filter( 'the_title',            [ __CLASS__, 'filter_post_title' ], 10, 2 );
    }

    /**
     * Enqueue plugin CSS/JS for ILL pages. Registered on wp_enqueue_scripts
     * by maybe_intercept(), so it only loads on actual ILL routes.
     */
    public static function enqueue_assets(): void {
        // Date fields use the browser's own date picker (SEAL_Page_Base::datepicker_js), so no jQuery UI.
        wp_enqueue_style(
            'seal-style',
            SEAL_ILL_PLUGIN_URL . 'assets/seal-style.css',
            [],
            SEAL_ILL_VERSION
        );
    }

    /**
     * Inject a WP_Post object into the global $post and the main query so
     * the theme's page.php / singular.php / index.php template renders with
     * the_content() available and the sidebar intact.
     */
    private static function setup_virtual_post( string $slug ): void {
        global $wp_query, $post;

        $title = self::title( $slug );

        $virtual = new WP_Post( (object) [
            'ID'                    => 0,
            'post_author'           => 1,
            'post_date'             => current_time( 'mysql' ),
            'post_date_gmt'         => current_time( 'mysql', 1 ),
            'post_content'          => '',   // filled by the_content filter
            'post_title'            => $title,
            'post_excerpt'          => '',
            'post_status'           => 'publish',
            'comment_status'        => 'closed',
            'ping_status'           => 'closed',
            'post_name'             => $slug,
            'post_modified'         => current_time( 'mysql' ),
            'post_modified_gmt'     => current_time( 'mysql', 1 ),
            'post_content_filtered' => '',
            'post_parent'           => 0,
            'guid'                  => home_url( '/' . $slug ),
            'menu_order'            => 0,
            'post_type'             => 'page',
            'post_mime_type'        => '',
            'comment_count'         => 0,
            'filter'                => 'raw',
        ] );

        // Push into WP's query system
        $wp_query->posts                 = [ $virtual ];
        $wp_query->post                  = $virtual;
        $wp_query->post_count            = 1;
        $wp_query->found_posts           = 1;
        $wp_query->is_page               = true;
        $wp_query->is_singular           = true;
        $wp_query->is_home               = false;
        $wp_query->is_archive            = false;
        $wp_query->is_category           = false;
        $wp_query->is_404                = false;
        $wp_query->queried_object        = $virtual;
        $wp_query->queried_object_id     = 0;

        $post = $virtual;
        setup_postdata( $virtual );
    }

    /**
     * the_content filter: run the ILL page handler and return its output.
     * Only fires once (we remove ourselves after the first call).
     */
    public static function inject_content( string $content ): string {
        remove_filter( 'the_content', [ __CLASS__, 'inject_content' ], 1 );

        if ( ! self::$active_class ) {
            return $content;
        }

        // Our output is finished HTML. wpautop (priority 10) would otherwise add stray
        // <br> and <p> tags inside forms, flex/grid containers and buttons, which shows
        // up as random gaps in the layout.
        remove_filter( 'the_content', 'wpautop' );
        remove_filter( 'the_content', 'shortcode_unautop' );

        // Render the page handler output
        ob_start();
        $handler = new self::$active_class();
        $handler->render();
        $page_output = ob_get_clean();

        // Wrap in the two-column layout (sidebar nav + content), matching the
        // original standalone site. The sidebar is capability-aware and travels with
        // the plugin. Can be turned off via the seal_show_sidebar filter or by
        // disabling the layout option.
        $show_sidebar = apply_filters( 'seal_show_sidebar', is_user_logged_in(), self::$active_slug );

        if ( ! $show_sidebar ) {
            return $page_output;
        }

        $sidebar = SEAL_Sidebar::render();
        if ( $sidebar === '' ) {
            return $page_output;
        }

        return '<div class="seal-layout">'
             . '<aside class="seal-layout-sidebar">' . $sidebar . '</aside>'
             . '<div class="seal-layout-main">' . $page_output . '</div>'
             . '</div>';
    }

    /** Document <title> filter */
    public static function filter_title( array $parts ): array {
        if ( self::$active_slug ) {
            $parts['title'] = self::title( self::$active_slug );
        }
        return $parts;
    }

    /** the_title filter — updates the H1 the theme outputs */
    public static function filter_post_title( string $title, $id ): string {
        if ( self::$active_slug && $id === 0 ) {
            return self::title( self::$active_slug );
        }
        return $title;
    }
}
