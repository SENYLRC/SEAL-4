<?php
/**
 * Plugin Name:       Southeastern ILL System
 * Plugin URI:        https://github.com/SENYLRC/SEAL-4
 * Description:       Complete Interlibrary Loan management system. Includes the ILL request workflow, lender response, renewals, statistics, user profile fields, login redirect, Terms of Service gate, catalog search header, dashboard quick links, and user CSV export — all in one plugin, fully configurable for any region without code changes.
 * Version:           3.14.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Zachary Spalding (SENYLRC) & Claude (Anthropic)
 * Author URI:        https://senylrc.org
 * License:           Apache-2.0
 * License URI:       https://www.apache.org/licenses/LICENSE-2.0
 * Text Domain:       seal-ill
 *
 * Originally authored by Zachary Spalding, SENYLRC Systems Department.
 * Security refactor, WordPress plugin port, module consolidation, and
 * co-authorship by Claude (Anthropic AI assistant), June 2026.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'SEAL_ILL_VERSION',    '3.14.0' );   // bump on every deploy that changes CSS/JS: it is the ?ver= cache-buster
define( 'SEAL_ILL_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SEAL_ILL_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// ─── Emergency switches (wp-config.php) ────────────────────────────────────────
/**
 * True when every security module is switched off from wp-config.php, for recovering from a lockout:
 *   define( 'ILL_DISABLE_SECURITY', true );
 * The older name SEAL_DISABLE_SECURITY works too.
 */
function seal_security_disabled(): bool {
    return ( defined( 'ILL_DISABLE_SECURITY' ) && ILL_DISABLE_SECURITY )
        || ( defined( 'SEAL_DISABLE_SECURITY' ) && SEAL_DISABLE_SECURITY );
}

/**
 * True when only the custom login URL is switched off from wp-config.php (log in at /wp-login.php again):
 *   define( 'ILL_DISABLE_LOGIN_URL', true );
 * The older name SEAL_DISABLE_LOGIN_URL works too.
 */
function seal_login_url_disabled(): bool {
    return ( defined( 'ILL_DISABLE_LOGIN_URL' ) && ILL_DISABLE_LOGIN_URL )
        || ( defined( 'SEAL_DISABLE_LOGIN_URL' ) && SEAL_DISABLE_LOGIN_URL );
}

// ─── Global config helper ──────────────────────────────────────────────────────
/**
 * seal_config( $key ) — retrieve any plugin option with its default.
 *
 * Covers branding, database, email, catalog search, user/registration,
 * and module-toggle options. All editable via the admin panel.
 */
function seal_config( string $key ): string {
    static $defaults = [
        // ── Branding & Identity ────────────────────────────────────────────
        'system_name'             => 'ILL',
        'system_full_name'        => 'Town Library Interlibrary Loan',
        'org_name'                => 'TLC',
        'org_full_name'           => 'Town Library Council',
        'org_domain'              => 'library.org',
        'system_url'              => 'https://ill.library.org',
        'footer_text'             => '',

        // ── Contacts & Alerts ──────────────────────────────────────────────
        'admin_email'             => '',   // blank = the WordPress administrator email (Settings → General)
        'support_emails'          => '',
        'email_from'              => 'donotreply@library.org',
        'email_from_name'         => '',   // blank = falls back to the System Short Name
        'email_nofill_cc'         => '',
        'email_mode'              => '',   // '' send normally | 'redirect' all plugin email to email_test_to | 'off' send nothing (log only)
        'email_test_to'           => '',

        // ── Database ───────────────────────────────────────────────────────
        'db_host'                 => 'localhost',
        'db_user'                 => '',
        'db_name'                 => '',
        'db_pass'                 => '',
        'table_stat'              => 'STATS',
        'table_lib'               => 'Library-Data',
        'table_illiad'            => 'ILLIAD-ADD-MAPPING',
        'table_rota'              => 'ILL-ROTA',   // automatic lender fallback state (see class-seal-rota.php)

        // ── Gateway / API ──────────────────────────────────────────────────
        'gateway_url'             => 'https://catalog.library.org/service-proxy/',

        // ── MySQL TLS / SSL ────────────────────────────────────────────────
        // Set db_ssl_enforce to '1' in the admin to require encrypted connections.
        // Provide db_ssl_ca for server certificate verification (recommended).
        'db_ssl_ca'               => '',   // absolute path to CA cert
        'db_ssl_cert'             => '',   // absolute path to client cert (mutual TLS)
        'db_ssl_key'              => '',   // absolute path to client key  (mutual TLS)
        'db_ssl_enforce'          => '0',  // '1' = refuse unencrypted connections

        // ── Catalog Search ─────────────────────────────────────────────────
        'catalog_search_url'      => 'https://catalog.library.org',
        'catalog_search_page'     => 'https://catalog.library.org/search.html',
        'catalog_advanced_url'    => 'https://catalog.library.org/advanced.html',
        'catalog_login_url'       => 'https://catalog.library.org/search.html/login.html',
        'catalog_help_url'        => 'https://library.org/ill-help',
        'catalog_categories_js_url' => 'https://catalog.library.org/mk2-ui-core/js/categories.js',
        'catalog_categories'      => '',   // newline-separated value|Label pairs; blank = defaults

        // ── Header / Logo ──────────────────────────────────────────────────
        'header_logo_url'         => '',
        'favicon_url'             => '',   // blank = bundled generic icon (assets/img)
        'header_logo_alt'         => '',

        // ── Brand colours (links, buttons, focus, headings) ────────────────
        // Defaults: purple / navy / green. Text/button colours must keep >= 4.5:1
        // contrast with white (WCAG 2.1 AA); the admin rejects values that don't.
        'brand_primary'           => '#4a4180',   // links + primary buttons
        'brand_dark'              => '#1d1f40',   // hover, headings, table headers
        'brand_accent'            => '#3fbb86',   // secondary buttons (dark text), accents

        // ── User & Registration ────────────────────────────────────────────
        'home_system_code'        => 'SE',
        'login_redirect_path'     => '/illuser/',
        'tos_enabled'             => '1',
        'tos_page_url'            => '/terms-of-service',
        'tos_label'               => '',   // blank = "{system_name} Terms of Service"
        'invite_code'             => '',   // blank = registration is not gated by a code
        'pw_link_days'            => '7',
        'setup_reminders'         => '1',  // remind people who haven't set their password, 3 days and 1 day before the link expires
        'setup_reminder_hour'     => '9',  // at 09:00 site time

        // ── Data retention (cron anonymize.php reads these; 0 = that step off) ──
        'anon_expire_months'      => '18', // requests still unanswered after this many months are marked Expired
        'anon_note_days'          => '30', // request note of an expired request cleared this many days after it expired
        'anon_months'             => '60', // item details and notes of requests older than this are blanked (5 years)  // how long the set-password / password reset link works (WordPress default: 1 day)

        // ── User approval ──────────────────────────────────────────────────
        'user_approval_required'  => '0',  // '1' = require admin approval on registration

        // ── Site footer (module_site_footer must be on) ─────────────────────
        'footer_logo_url'         => '',
        'footer_logo_alt'         => '',
        'footer_logo_link'        => '',
        'footer_about'            => '',
        'footer_address'          => '',
        'footer_phone'            => '',
        'footer_website'          => '',
        'footer_links'            => '',   // Label|URL per line
        'footer_copyright'        => '',   // blank = "© {year} {system_name}, All rights reserved."

        // ── Sidebar Quick Links (Label|URL per line) ────────────────────────
        'quick_links'             => "Frequently Asked Questions|https://library.org/ill-faq\nILL Directory|/illdir\nTraining Resources|https://library.org/ill-training",

        // ── Module toggles ─────────────────────────────────────────────────
        'module_catalog_search'   => '1',
        'module_dashboard_tweak'  => '1',
        'module_login_redirect'   => '1',
        'module_tos'              => '1',
        'module_invite_code'      => '1',
        'module_user_fields'      => '1',
        'module_export_users'     => '1',
        'module_last_login'       => '1',
        'module_role_editor'      => '1',
        'module_user_approval'    => '1',
        'module_site_footer'      => '0',
        'module_rota'             => '0',   // automatic lender fallback ("rota") on decline / 5-business-day expiry
        'rota_max_candidates'     => '3',   // total libraries in one rota chain (the borrower's pick + fallbacks); hard-capped at 3
        'rota_retention_days'     => '180', // finished rota tracking rows (filled/cancelled/exhausted) older than this are deleted daily

        // ── Security modules (all off until enabled under ILL → Security) ─────
        'module_login_screen'     => '0',
        'module_login_url'        => '0',
        'module_login_protection' => '0',
        'module_password_rules'   => '0',
        'module_2fa'              => '0',
        'module_file_monitor'     => '0',

        // Login screen
        'ls_logo_url'             => '',
        'ls_logo_alt'             => '',
        'ls_logo_link'            => '',
        'ls_bg_url'               => '',
        'ls_message'              => '',
        'ls_button_color'         => '#1f3a5f',
        'ls_split'                => '1',

        // Login URL
        'lu_slug'                 => 'staff-login',

        // Login protection
        'lp_failed_logins'        => '10',
        'lp_timeframe'            => '600',
        'lp_permanent'            => '0',
        'lp_duration'             => '10',
        'lp_duration_unit'        => 'minutes',
        'lp_message'              => 'You have been locked out due to too many invalid login attempts.',
        'lp_404_enabled'          => '0',
        'lp_404_hits'             => '20',
        'lp_404_timeframe'        => '300',
        'lp_404_permanent'        => '0',
        'lp_404_duration'         => '4',
        'lp_404_duration_unit'    => 'hours',
        'lp_404_logged_in'        => '1',
        'lp_404_message'          => 'You have been locked out due to too many attempts to access a page that does not exist.',
        'lp_404_ignore'           => 'css,js,map,ico,png,jpg,jpeg,gif,svg,webp,woff,woff2,ttf,txt,xml',
        'lp_allowlist'            => '',
        'lp_blocklist'            => '',
        'lp_ip_header'            => '',
        'lp_disable_xmlrpc'       => '1',

        // Password rules
        'pw_pwned'                => '1',
        'pw_pwned_roles'          => 'all',
        'pw_pwned_message'        => 'You are required to change your password because the password you are using exists in known data breach records.',
        'pw_strong'               => '1',
        'pw_min_length'           => '12',

        // Two-factor authentication
        'tfa_roles'               => '',
        'tfa_issuer'              => '',

        // File monitor
        'fm_frequency'            => 'daily',
        'fm_extensions'           => 'php,phtml,phar,inc,js,htaccess,ini',
        'fm_exclude'              => '',
    ];

    $val = (string) get_option( 'seal_' . $key, $defaults[ $key ] ?? '' );
    // Alerts must never go to a hard-coded person: fall back to this site's own administrator address.
    if ( $key === 'admin_email' && trim( $val ) === '' ) {
        $val = (string) get_option( 'admin_email', '' );
    }
    return $val;
}

/**
 * seal_alert_emails() — merged admin + support addresses for system alerts.
 */
function seal_alert_emails(): string {
    $all = array_filter( array_unique( array_map( 'trim', array_merge(
        explode( ',', seal_config( 'admin_email'    ) ),
        explode( ',', seal_config( 'support_emails' ) )
    ) ) ) );
    return implode( ', ', $all );
}

// ─── Core includes ─────────────────────────────────────────────────────────────
require_once SEAL_ILL_PLUGIN_DIR . 'includes/class-seal-db.php';
require_once SEAL_ILL_PLUGIN_DIR . 'includes/class-seal-roles.php';
require_once SEAL_ILL_PLUGIN_DIR . 'includes/class-seal-functions.php';
require_once SEAL_ILL_PLUGIN_DIR . 'includes/class-seal-org-rules.php';
require_once SEAL_ILL_PLUGIN_DIR . 'includes/class-seal-org-rules-help.php';
require_once SEAL_ILL_PLUGIN_DIR . 'includes/class-seal-rules-log.php';
require_once SEAL_ILL_PLUGIN_DIR . 'includes/class-seal-sidebar.php';
require_once SEAL_ILL_PLUGIN_DIR . 'includes/class-seal-router.php';
require_once SEAL_ILL_PLUGIN_DIR . 'includes/class-seal-rota.php';
require_once SEAL_ILL_PLUGIN_DIR . 'includes/class-seal-secret.php';   // ILLiad API keys encrypted with ILL_DATA_KEY
require_once SEAL_ILL_PLUGIN_DIR . 'includes/class-seal-db-schema.php';   // expected tables / columns; Database check
require_once SEAL_ILL_PLUGIN_DIR . 'includes/class-seal-resend.php';
require_once SEAL_ILL_PLUGIN_DIR . 'includes/class-seal-emails.php';   // editable subjects; branded account emails
require_once SEAL_ILL_PLUGIN_DIR . 'includes/class-seal-setup-reminders.php';   // reminders before the set-password link expires
require_once SEAL_ILL_PLUGIN_DIR . 'includes/class-seal-audit.php';    // change history: email text, user accounts, security settings
require_once SEAL_ILL_PLUGIN_DIR . 'includes/class-seal-shortcodes.php';

// ─── Merged modules ────────────────────────────────────────────────────────────
require_once SEAL_ILL_PLUGIN_DIR . 'includes/modules/class-seal-module-userfields.php';
require_once SEAL_ILL_PLUGIN_DIR . 'includes/modules/class-seal-module-login-redirect.php';
require_once SEAL_ILL_PLUGIN_DIR . 'includes/modules/class-seal-module-tos.php';
require_once SEAL_ILL_PLUGIN_DIR . 'includes/modules/class-seal-module-invite-code.php';
require_once SEAL_ILL_PLUGIN_DIR . 'includes/modules/class-seal-module-dashboard.php';
require_once SEAL_ILL_PLUGIN_DIR . 'includes/modules/class-seal-module-export-users.php';
require_once SEAL_ILL_PLUGIN_DIR . 'includes/modules/class-seal-module-catalog-search.php';
require_once SEAL_ILL_PLUGIN_DIR . 'includes/modules/class-seal-module-last-login.php';
require_once SEAL_ILL_PLUGIN_DIR . 'includes/modules/class-seal-module-role-editor.php';
require_once SEAL_ILL_PLUGIN_DIR . 'includes/modules/class-seal-module-user-approval.php';
require_once SEAL_ILL_PLUGIN_DIR . 'includes/modules/class-seal-module-site-footer.php';
require_once SEAL_ILL_PLUGIN_DIR . 'includes/class-seal-totp.php';
require_once SEAL_ILL_PLUGIN_DIR . 'includes/class-seal-webauthn.php';   // security keys / passkeys (bundled lbuchs/webauthn, loaded on first use)
require_once SEAL_ILL_PLUGIN_DIR . 'includes/modules/class-seal-module-login-screen.php';
require_once SEAL_ILL_PLUGIN_DIR . 'includes/modules/class-seal-module-login-url.php';
require_once SEAL_ILL_PLUGIN_DIR . 'includes/modules/class-seal-module-login-protection.php';
require_once SEAL_ILL_PLUGIN_DIR . 'includes/modules/class-seal-module-password-rules.php';
require_once SEAL_ILL_PLUGIN_DIR . 'includes/modules/class-seal-module-2fa.php';
require_once SEAL_ILL_PLUGIN_DIR . 'includes/modules/class-seal-module-file-monitor.php';

// ─── Admin ─────────────────────────────────────────────────────────────────────
require_once SEAL_ILL_PLUGIN_DIR . 'admin/class-seal-admin.php';
require_once SEAL_ILL_PLUGIN_DIR . 'admin/class-seal-security-admin.php';
require_once SEAL_ILL_PLUGIN_DIR . 'admin/class-seal-import-users.php';   // ILL → Import Users (CSV)

// ─── Bootstrap ────────────────────────────────────────────────────────────────
add_action( 'plugins_loaded', function () {
    SEAL_DB::load_tables();   // table names are read before the first query connects
    SEAL_Org_Rules::maybe_seed();
    SEAL_Org_Rules::maybe_migrate_cron_names();
    if ( SEAL_Rota::active() ) { SEAL_Rota::maybe_create_table(); SEAL_Rota::ensure_schedule(); }
    add_action( SEAL_Rota::CLEANUP_HOOK, [ 'SEAL_Rota', 'cleanup' ] );
    SEAL_Router::init();
    if ( is_admin() ) { SEAL_Rules_Log::init(); SEAL_Emails::init_log(); }
    SEAL_Shortcodes::init();
    SEAL_Emails::init();
    SEAL_Setup_Reminders::init();
    SEAL_Audit::init();
    SEAL_Roles::init();

    // Module init — each checks its own toggle via seal_config()
    if ( seal_config( 'module_user_fields'     ) === '1' ) SEAL_Module_UserFields::init();
    if ( seal_config( 'module_login_redirect'  ) === '1' ) SEAL_Module_LoginRedirect::init();
    if ( seal_config( 'module_tos'             ) === '1' ) SEAL_Module_TOS::init();
    if ( seal_config( 'module_invite_code'     ) === '1' ) SEAL_Module_InviteCode::init();
    if ( seal_config( 'module_dashboard_tweak' ) === '1' ) SEAL_Module_Dashboard::init();
    if ( seal_config( 'module_export_users'    ) === '1' ) SEAL_Module_ExportUsers::init();
    if ( seal_config( 'module_catalog_search'  ) === '1' ) SEAL_Module_CatalogSearch::init();
    if ( seal_config( 'module_last_login'      ) === '1' ) SEAL_Module_LastLogin::init();
    if ( seal_config( 'module_role_editor'     ) === '1' ) SEAL_Module_RoleEditor::init();
    if ( seal_config( 'module_user_approval'   ) === '1' ) SEAL_Module_UserApproval::init();
    if ( seal_config( 'module_site_footer'     ) === '1' ) SEAL_Module_SiteFooter::init();

    // Security modules. Emergency off switch: define( 'ILL_DISABLE_SECURITY', true ) in wp-config.php (see seal_security_disabled()).
    if ( ! seal_security_disabled() ) {
        if ( seal_config( 'module_login_url'        ) === '1' ) SEAL_Module_LoginURL::init();
        if ( seal_config( 'module_login_screen'     ) === '1' ) SEAL_Module_LoginScreen::init();
        if ( seal_config( 'module_login_protection' ) === '1' ) SEAL_Module_LoginProtection::init();
        if ( seal_config( 'module_password_rules'   ) === '1' ) SEAL_Module_PasswordRules::init();
        if ( seal_config( 'module_2fa'              ) === '1' ) SEAL_Module_2FA::init();
        if ( seal_config( 'module_file_monitor'     ) === '1' ) SEAL_Module_FileMonitor::init();
    }
} );

/**
 * Favicon. Used only when no Site Icon is set under Appearance > Customize > Site Identity (that always
 * wins). Order: ILL > Branding > Favicon URL, else the bundled icon files in assets/img/.
 */
function seal_print_favicon(): void {
    if ( function_exists( 'has_site_icon' ) && has_site_icon() ) { return; }
    $custom = trim( (string) seal_config( 'favicon_url' ) );
    if ( $custom !== '' ) {
        echo '<link rel="icon" href="' . esc_url( $custom ) . '">' . "\n";
        echo '<link rel="apple-touch-icon" href="' . esc_url( $custom ) . '">' . "\n";
        return;
    }
    $u = SEAL_ILL_PLUGIN_URL . 'assets/img/';
    echo '<link rel="icon" href="' . esc_url( $u . 'favicon.ico' ) . '" sizes="any">' . "\n";
    echo '<link rel="icon" type="image/png" sizes="32x32" href="' . esc_url( $u . 'favicon-32.png' ) . '">' . "\n";
    echo '<link rel="icon" type="image/png" sizes="192x192" href="' . esc_url( $u . 'favicon-192.png' ) . '">' . "\n";
    echo '<link rel="apple-touch-icon" href="' . esc_url( $u . 'apple-touch-icon.png' ) . '">' . "\n";
}
/**
 * Don't let visitors list user accounts. Usernames here are email addresses, and WordPress reveals them
 * through /?author=N (redirects to /author/<name>/) and the REST users list. Signed-in staff with
 * list_users (administrators) and the block editor are unaffected.
 */
add_action( 'template_redirect', static function () {
    if ( is_user_logged_in() ) { return; }
    if ( isset( $_GET['author'] ) || is_author() ) {
        global $wp_query;
        $wp_query->set_404(); status_header( 404 ); nocache_headers();
        $tpl = get_404_template();
        if ( $tpl ) { include $tpl; } else { echo 'Not found.'; }
        exit;   // before redirect_canonical can send /?author=N on to /author/<name>/
    }
}, 1 );
add_filter( 'rest_endpoints', static function ( $endpoints ) {
    if ( ! current_user_can( 'list_users' ) ) {
        unset( $endpoints['/wp/v2/users'], $endpoints['/wp/v2/users/(?P<id>[\d]+)'] );
    }
    return $endpoints;
} );
add_filter( 'oembed_response_data', static function ( $data ) { unset( $data['author_name'], $data['author_url'] ); return $data; } );

add_action( 'wp_head',    'seal_print_favicon', 2 );
add_action( 'login_head', 'seal_print_favicon', 2 );
add_action( 'admin_head', 'seal_print_favicon', 2 );

add_action( 'wp_head',    [ 'SEAL_Functions', 'print_brand_css' ], 99 );
add_action( 'login_head', [ 'SEAL_Functions', 'print_brand_css' ], 99 );
add_action( 'admin_menu',  [ 'SEAL_Admin', 'register_menu'     ] );
add_action( 'admin_enqueue_scripts', [ 'SEAL_Admin', 'enqueue_rules_assets' ] );
add_action( 'admin_post_seal_rules_import', [ 'SEAL_Admin', 'handle_import' ] );
add_action( 'admin_post_seal_encrypt_illiad_keys', [ 'SEAL_Admin', 'handle_encrypt_keys' ] );
add_action( 'admin_post_seal_create_ill_tables', [ 'SEAL_Admin', 'handle_create_tables' ] );
add_action( 'admin_init',  [ 'SEAL_Admin', 'register_settings' ] );
add_action( 'admin_notices', [ 'SEAL_Functions', 'email_mode_notice' ] );
add_action( 'wp_body_open',  [ 'SEAL_Functions', 'email_mode_bar' ] );
add_action( 'admin_menu',  [ 'SEAL_Security_Admin', 'register_menu' ], 20 );
add_action( 'admin_init',  [ 'SEAL_Security_Admin', 'init' ] );

// ─── Activation / Deactivation ────────────────────────────────────────────────
register_activation_hook( __FILE__, function () {
    SEAL_Roles::create_roles();
    // The person activating the plugin also becomes an ILL Administrator (an extra role; they keep Administrator)
    $u = wp_get_current_user();
    if ( $u->exists() && ! in_array( 'illadmin', (array) $u->roles, true ) ) { $u->add_role( 'illadmin' ); }
    update_option( 'seal_ill_flush_rewrite', 1 );
} );

register_deactivation_hook( __FILE__, function () {
    wp_clear_scheduled_hook( 'seal_fm_scan' );
    wp_clear_scheduled_hook( 'seal_fm_rebaseline' );
    wp_clear_scheduled_hook( SEAL_Rota::CLEANUP_HOOK );
    wp_clear_scheduled_hook( SEAL_Setup_Reminders::HOOK );
    wp_clear_scheduled_hook( SEAL_Audit::PRUNE_HOOK );
    flush_rewrite_rules();
} );

add_action( 'init', function () {
    if ( get_option( 'seal_ill_flush_rewrite' ) ) {
        flush_rewrite_rules();
        delete_option( 'seal_ill_flush_rewrite' );
    }
} );
