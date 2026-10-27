<?php
/**
 * SEAL_Module_Dashboard
 *
 * - Removes unwanted default WP dashboard widgets
 * - Adds a Quick Links dashboard widget for ILL staff
 * - Adds a Quick Links dropdown to the WP admin bar
 *
 * The link list can be customised via the 'seal_dashboard_links' filter.
 * The widget title uses seal_config('system_name') automatically.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class SEAL_Module_Dashboard {

    /**
     * Replaces the default WordPress dashboard widgets with an ILL Quick Links widget, and adds the same links
     * to the admin bar.
     */
    public static function init(): void {
        add_action( 'wp_dashboard_setup', [ __CLASS__, 'remove_default_widgets' ], 999 );
        add_action( 'wp_dashboard_setup', [ __CLASS__, 'add_widget'             ] );
        add_action( 'admin_bar_menu',     [ __CLASS__, 'add_admin_bar_links'    ], 100 );
    }

    // ── Remove clutter ────────────────────────────────────────────────────────

    /** Removes WordPress's activity, news and theme widgets from the dashboard. */
    public static function remove_default_widgets(): void {
        remove_meta_box( 'dashboard_activity', 'dashboard', 'normal' );
        remove_meta_box( 'dashboard_primary',  'dashboard', 'side'   );
        remove_meta_box( 'themeisle',          'dashboard', 'normal' );
    }

    // ── Link registry ─────────────────────────────────────────────────────────

    /** The Quick Links grouped by section (Profiles, Request Status, Statistics); filterable with seal_dashboard_links. */
    public static function link_sections(): array {
        $defaults = [
            'Profiles' => [
                [ 'label' => 'My Profile',             'href' => home_url( '/illuser'          ), 'primary' => true  ],
                [ 'label' => 'Library Profile',        'href' => home_url( '/libprofile'       ), 'primary' => false ],
                [ 'label' => 'Library Staff Accounts', 'href' => home_url( '/libstaffaccounts' ), 'primary' => false ],
            ],
            'Request Status' => [
                [ 'label' => 'Borrowing History', 'href' => home_url( '/requesthistory' ), 'primary' => true  ],
                [ 'label' => 'Lending History',   'href' => home_url( '/lender-history' ), 'primary' => false ],
            ],
            'Statistics' => [
                [ 'label' => 'Borrowing Stats', 'href' => home_url( '/libstats'      ), 'primary' => false ],
                [ 'label' => 'Lending Stats',   'href' => home_url( '/liblenderstat' ), 'primary' => false ],
            ],
        ];
        return (array) apply_filters( 'seal_dashboard_links', $defaults );
    }

    // ── Dashboard widget ──────────────────────────────────────────────────────

    /** Registers the Quick Links dashboard widget. */
    public static function add_widget(): void {
        $sys = seal_config( 'system_name' );
        wp_add_dashboard_widget(
            'seal_quick_links',
            esc_html( $sys ) . ' — Quick Links',
            [ __CLASS__, 'widget_content' ]
        );
    }

    /** Prints the Quick Links widget. */
    public static function widget_content(): void {
        foreach ( self::link_sections() as $section => $links ): ?>
        <h3 style="margin:14px 0 6px;font-size:.9em;text-transform:uppercase;color:#595959;letter-spacing:.05em;">
            <?php echo esc_html( $section ); ?>
        </h3>
        <p style="display:flex;flex-wrap:wrap;gap:8px;margin:0 0 4px;">
            <?php foreach ( $links as $link ):
                $cls = $link['primary'] ? 'button button-primary' : 'button';
            ?>
            <a class="<?php echo esc_attr( $cls ); ?>" href="<?php echo esc_url( $link['href'] ); ?>">
                <?php echo esc_html( $link['label'] ); ?>
            </a>
            <?php endforeach; ?>
        </p>
        <?php endforeach;
    }

    // ── Admin bar ─────────────────────────────────────────────────────────────

    /** Adds an ILL menu with the same links to the admin bar for logged-in users. */
    public static function add_admin_bar_links( WP_Admin_Bar $bar ): void {
        if ( ! is_user_logged_in() ) { return; }

        $sys = seal_config( 'system_name' );
        $bar->add_node( [
            'id'    => 'seal-quick-links',
            'title' => esc_html( $sys ) . ' Quick Links',
            'href'  => home_url( '/illuser' ),
            'meta'  => [ 'title' => $sys . ' Quick Links' ],
        ] );

        foreach ( self::link_sections() as $section => $links ) {
            $sec_id = 'seal-ql-' . sanitize_title( $section );
            $bar->add_node( [
                'id'     => $sec_id,
                'parent' => 'seal-quick-links',
                'title'  => '— ' . esc_html( $section ) . ' —',
                'href'   => false,
            ] );
            foreach ( $links as $i => $link ) {
                $bar->add_node( [
                    'id'     => $sec_id . '-' . $i,
                    'parent' => $sec_id,
                    'title'  => esc_html( $link['label'] ),
                    'href'   => esc_url( $link['href'] ),
                ] );
            }
        }
    }
}
