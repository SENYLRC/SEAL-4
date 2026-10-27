<?php
/**
 * SEAL_Admin — Admin menu, dashboard, and all settings pages.
 *
 * Settings organised into six tabs:
 *   1. Branding & Identity      — system name, org, domain, logo, footer
 *   2. Contacts & Email         — admin/support addresses, From: name/address
 *   3. Database & Tables        — DB credentials, table names, gateway URL
 *   4. Search & Catalog         — IndexData/MKWS URLs, category list
 *   5. Users & Registration     — system dropdown, TOS, login redirect
 *   6. Modules                  — enable/disable each merged feature
 *
 * @author  Zachary Spalding (SENYLRC) & Claude (Anthropic)
 * @version 3.2.0
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class SEAL_Admin {

    const GROUPS = [
        'branding' => 'seal_ill_branding',
        'contacts' => 'seal_ill_contacts',
        'database' => 'seal_ill_database',
        'search'   => 'seal_ill_search',
        'users'    => 'seal_ill_users',
        'modules'  => 'seal_ill_modules',
        'rules'    => 'seal_ill_rules',
        'cron'     => 'seal_ill_cron',    // own group: saving one screen must not blank the other's settings
        'subjects' => 'seal_ill_subjects',
    ];
    const PAGES = [
        'branding' => 'seal-ill-branding',
        'contacts' => 'seal-ill-contacts',
        'database' => 'seal-ill-database',
        'search'   => 'seal-ill-search',
        'users'    => 'seal-ill-users',
        'modules'  => 'seal-ill-modules',
        'rules'    => 'seal-ill-rules',
        'cron'     => 'seal-ill-cron',
        'subjects' => 'seal-ill-subjects',
    ];

    // ── Menu ─────────────────────────────────────────────────────────────────

    /**
     * Adds the ILL System menu (labelled with the System Short Name) and one submenu per settings screen.
     * Administrators only (manage_options).
     */
    public static function register_menu(): void {
        $sys        = seal_config( 'system_name' );
        $menu_title = ( strcasecmp( trim( $sys ), 'ILL' ) === 0 ) ? 'ILL System' : $sys . ' ILL';
        add_menu_page( $menu_title . ' System', $menu_title, 'manage_options', 'seal-ill-dashboard',
            [ __CLASS__, 'render_dashboard' ], 'dashicons-book-alt', 30 );

        foreach ( [
            'seal-ill-dashboard'       => [ 'Dashboard',              'render_dashboard' ],
            self::PAGES['branding']    => [ '🎨 Branding',            'render_branding'  ],
            self::PAGES['contacts']    => [ '✉️ Contacts & Email',    'render_contacts'  ],
            self::PAGES['subjects']    => [ '📨 Email Text',          'render_subjects'  ],
            self::PAGES['database']    => [ '🗄️ Database',            'render_database'  ],
            self::PAGES['search']      => [ '🔍 Search & Catalog',    'render_search'    ],
            self::PAGES['users']       => [ '👤 Users & Registration','render_users'     ],
            self::PAGES['modules']     => [ '🧩 Modules',             'render_modules'   ],
            self::PAGES['rules']       => [ '⚖️ Org Rules',           'render_rules'     ],
            self::PAGES['cron']        => [ '⏰ Cron Jobs',           'render_cron'      ],
            'seal-ill-routes'          => [ '🗺️ Routes',              'render_routes'    ],
            'seal-ill-history'         => [ '🕘 Change History',      'render_history'   ],
            SEAL_Import_Users::PAGE    => [ '📥 Import Users',        'render_import'    ],
        ] as $slug => [ $label, $cb ] ) {
            add_submenu_page( 'seal-ill-dashboard', strip_tags($label), $label, 'manage_options', $slug, [ __CLASS__, $cb ] );
        }
    }

    // ── Settings registration ─────────────────────────────────────────────────

    /** Registers every option, section and field on every settings screen (one reg_*() per screen). */
    public static function register_settings(): void {
        self::reg_branding();
        self::reg_contacts();
        self::reg_subjects();
        self::reg_database();
        self::reg_search();
        self::reg_users();
        self::reg_modules();
        self::reg_rules();
        self::reg_cron();
    }

    /**
     * Branding & Identity: names, logos, favicon, brand colors (checked for WCAG contrast), sidebar Quick Links
     * and the site footer.
     */
    private static function reg_branding(): void {
        $g = self::GROUPS['branding']; $p = self::PAGES['branding'];
        foreach ( [
            'seal_system_name'      => 'sanitize_text_field',
            'seal_system_full_name' => 'sanitize_text_field',
            'seal_org_name'         => 'sanitize_text_field',
            'seal_org_full_name'    => 'sanitize_text_field',
            'seal_org_domain'       => 'sanitize_text_field',
            'seal_system_url'       => 'esc_url_raw',
            'seal_header_logo_url'  => 'esc_url_raw',
            'seal_header_logo_alt'  => 'sanitize_text_field',
            'seal_favicon_url'      => 'esc_url_raw',
            'seal_footer_text'      => 'sanitize_text_field',
            'seal_quick_links'      => 'sanitize_textarea_field',
            'seal_footer_logo_url'  => 'esc_url_raw',
            'seal_footer_logo_alt'  => 'sanitize_text_field',
            'seal_footer_logo_link' => 'esc_url_raw',
            'seal_footer_about'     => 'sanitize_text_field',
            'seal_footer_address'   => 'sanitize_textarea_field',
            'seal_footer_phone'     => 'sanitize_text_field',
            'seal_footer_website'   => 'esc_url_raw',
            'seal_footer_links'     => 'sanitize_textarea_field',
            'seal_footer_copyright' => 'sanitize_text_field',
            'seal_brand_primary'    => [ __CLASS__, 'san_brand_text_color' ],
            'seal_brand_dark'       => [ __CLASS__, 'san_brand_text_color' ],
            'seal_brand_accent'     => [ __CLASS__, 'san_brand_accent' ],
        ] as $k => $cb ) { register_setting( $g, $k, [ 'sanitize_callback' => $cb ] ); }

        add_settings_section( 'seal_brand_id',   'System Identity', fn() => print '<p>Change these once and every label, email subject, and link updates sitewide. No code edits needed.</p>', $p );
        add_settings_section( 'seal_brand_logo', 'Logo &amp; Footer', '__return_false', $p );
        add_settings_section( 'seal_brand_colors', 'Brand Colors', fn() => print '<p>Colors for links, buttons, headings and focus outlines across the ILL pages and the theme. Enter <code>#rrggbb</code>. The first two are used for text and for buttons with white text, so they must have at least <strong>4.5:1</strong> contrast with white (WCAG 2.1 AA); a color that fails is rejected. The accent is used only for secondary buttons, which always carry dark text.</p>', $p );
        add_settings_section( 'seal_brand_sidebar', 'Sidebar Quick Links', fn() => print '<p>Links shown in the <strong>Quick Links</strong> section of the ILL page sidebar. One per line as <code>Label|URL</code>. Off-site URLs open in a new tab. Relative paths like <code>/illdir</code> stay on-site.</p>', $p );

        self::af( $p,'seal_brand_id',  'seal_system_name',      'System Short Name',      'Short acronym used in menus, email subjects, and page titles. e.g. <code>TOWNILL</code> or <code>ILL</code>' );
        self::af( $p,'seal_brand_id',  'seal_system_full_name', 'System Full Name',       'e.g. <em>Town Library Interlibrary Loan</em>' );
        self::af( $p,'seal_brand_id',  'seal_org_name',         'Org Short Name',         'e.g. <code>TLC</code>' );
        self::af( $p,'seal_brand_id',  'seal_org_full_name',    'Org Full Name',          '' );
        self::af( $p,'seal_brand_id',  'seal_org_domain',       'Org Domain',             'Domain only, no https://. e.g. <code>library.org</code>' );
        self::af( $p,'seal_brand_id',  'seal_system_url',       'System Base URL',        'Full URL with https://. Used in email links.', 'url' );
        self::af( $p,'seal_brand_logo','seal_header_logo_url',  'Header Logo URL',        'Full URL to logo image. Leave blank to display system short name as text.', 'url' );
        self::af( $p,'seal_brand_logo','seal_favicon_url',      'Favicon URL',            'Browser-tab icon: full URL to a square PNG or ICO (upload <code>favicon-512.png</code> to the Media Library and paste its URL). Leave blank to use the bundled icon. A Site Icon set under Appearance &rarr; Customize always wins.', 'url' );
        self::af( $p,'seal_brand_logo','seal_header_logo_alt',  'Logo Alt Text',          'Screen-reader description of the logo.' );
        self::af( $p,'seal_brand_colors','seal_brand_primary', 'Link &amp; Button Color', 'Default <code>#4a4180</code> (purple). Must be dark enough for white text.' );
        self::af( $p,'seal_brand_colors','seal_brand_dark',    'Dark Color',            'Default <code>#1d1f40</code> (navy). Used for hover states and headings.' );
        self::af( $p,'seal_brand_colors','seal_brand_accent',  'Accent Color',          'Default <code>#3fbb86</code> (green). Secondary buttons, with dark text.' );
        self::af( $p,'seal_brand_logo','seal_footer_text',      'Email Footer Text',      'Optional line appended to every outbound email.' );
        self::af( $p,'seal_brand_sidebar','seal_quick_links',   'Quick Links',            'One per line: <code>Label|URL</code>', 'textarea',
            "Example:\n<code>Feedback/Support|https://example.org/form\nFAQ|https://libguides.example.org/faq\nILL Directory|/illdir</code>" );

        add_settings_section( 'seal_brand_sitefooter', 'Site Footer', fn() => print '<p>Organization footer shown at the bottom of every page. Turn it on with the <strong>Site Footer</strong> module on the Modules tab.</p>', $p );
        self::af( $p,'seal_brand_sitefooter','seal_footer_logo_url',  'Footer Logo URL',   'Full URL to the footer logo. Blank = no logo.', 'url' );
        self::af( $p,'seal_brand_sitefooter','seal_footer_logo_alt',  'Footer Logo Alt Text', 'Screen-reader description. e.g. <em>Town Library Council logo</em>' );
        self::af( $p,'seal_brand_sitefooter','seal_footer_logo_link', 'Footer Logo Link',  'Where the logo links. Blank = not a link.', 'url' );
        self::af( $p,'seal_brand_sitefooter','seal_footer_about',     'About Line',        'e.g. <em>Town ILL is a service of the Town Library Council</em>' );
        self::af( $p,'seal_brand_sitefooter','seal_footer_address',   'Address',           'One line per row.', 'textarea' );
        self::af( $p,'seal_brand_sitefooter','seal_footer_phone',     'Phone',             'e.g. <code>555-555-0100</code>. Becomes a tap-to-call link.' );
        self::af( $p,'seal_brand_sitefooter','seal_footer_website',   'Website',           'Full URL, e.g. <code>https://www.library.org</code>', 'url' );
        self::af( $p,'seal_brand_sitefooter','seal_footer_links',     'Footer Links',      'One per line: <code>Label|URL</code>. Use <code>{login}</code> as the URL for the login page (it follows the custom login URL).', 'textarea',
            "Example:\n<code>Library Staff Login|{login}\nILL Directory|/illdir</code>" );
        self::af( $p,'seal_brand_sitefooter','seal_footer_copyright', 'Copyright Line',    'Leave blank for the default. Tokens <code>{year}</code> and <code>{system}</code> are replaced with the current year and the System Short Name.' );
    }

    /** Contacts & Email: alert and CC addresses, the From address and name, and Email test mode. */
    private static function reg_contacts(): void {
        $g = self::GROUPS['contacts']; $p = self::PAGES['contacts'];
        foreach ( [
            'seal_admin_email'     => [ __CLASS__, 'san_emails' ],
            'seal_support_emails'  => [ __CLASS__, 'san_emails' ],
            'seal_email_from'      => 'sanitize_email',
            'seal_email_from_name' => 'sanitize_text_field',
            'seal_email_nofill_cc' => [ __CLASS__, 'san_emails' ],
            'seal_email_mode'      => static fn( $v ) => in_array( (string) $v, [ 'redirect', 'off' ], true ) ? (string) $v : '',
            'seal_email_test_to'   => [ __CLASS__, 'san_emails' ],   // one or more, comma-separated (sanitize_email mangled lists into one bogus address)
        ] as $k => $cb ) { register_setting( $g, $k, [ 'sanitize_callback' => $cb ] ); }

        add_settings_section( 'seal_con_alerts', 'Alert Recipients', function() {
            $d = esc_html( seal_config('org_domain') );
            echo "<p>These addresses receive all system alerts (DB failures, ILLiad errors, etc.). For example <code>ill@$d</code>.</p>";
        }, $p );
        add_settings_section( 'seal_con_from', 'Outbound Mail', fn() => print '<p>Uses <code>wp_mail()</code> — works with any SMTP plugin (WP Mail SMTP, FluentSMTP, etc.).</p>', $p );

        self::af( $p,'seal_con_alerts','seal_admin_email',    'Admin / Alert Email(s)',   'Comma-separate multiples. e.g. <code>ill@library.org</code>', 'emails' );
        self::af( $p,'seal_con_alerts','seal_support_emails', 'Support CC Email(s)',      'CC\'d on every system alert. e.g. <code>support@library.org, noc@library.org</code>', 'emails' );
        self::af( $p,'seal_con_alerts','seal_email_nofill_cc','Not-Filled CC(s)',        'CC\'d when a request cannot be filled.', 'emails' );
        self::af( $p,'seal_con_from',  'seal_email_from',     'From: Address',           'Must be an authorised sender on your mail server. e.g. <code>donotreply@library.org</code>' );
        self::af( $p,'seal_con_from',  'seal_email_from_name','From: Display Name',      'e.g. <em>Town ILL System</em>. Blank = the System Short Name.' );

        add_settings_section( 'seal_con_test', 'Email Test Mode', fn() => print '<p>For testing: stop the ILL system emailing real libraries and borrowers. Covers every email the plugin sends (requests, responses, renewals, Automatic Fallback, resends, approvals, alerts) and the server\'s scheduled jobs (reminders, expiry notices), which read this same setting. WordPress\'s own emails (password resets, new-user notices) still go out so people can log in. A warning shows on every admin page while it is on.</p>', $p );
        add_settings_field( 'seal_email_mode', 'Email test mode', [ __CLASS__, 'field_select' ], $p, 'seal_con_test', [
            'name' => 'seal_email_mode', 'label_for' => 'seal_email_mode',
            'options' => [ '' => 'Off — send email normally', 'redirect' => 'Send all email to the test address below instead', 'off' => 'Don\'t send any email (record only) — system alerts to the admin addresses still go out' ],
        ] );
        self::af( $p,'seal_con_test',  'seal_email_test_to',  'Test address',            'Where every email goes while "Send all email to the test address" is chosen. Several addresses: separate them with commas. The original recipients are listed at the top of each message.' );
    }

    /**
     * Email Subjects & Text: for every email (SEAL_Emails::all() subjects and SEAL_Emails::texts() message text),
     * grouped by kind, one row with a collapsible editor. Blank = the default, shown as the placeholder.
     */
    private static function reg_subjects(): void {
        $g = self::GROUPS['subjects']; $p = self::PAGES['subjects'];
        $subjects = SEAL_Emails::all();
        $texts    = SEAL_Emails::texts();
        $emails   = [];   // group => [ key => label ]
        foreach ( $texts as $key => $t ) {   // texts() order first, so emails that share a subject sit together
            foreach ( [ 'heading' => 2, 'message' => 3, 'closing' => 4 ] as $part => $i ) {
                $clean = [ 'SEAL_Emails', $part === 'heading' ? 'sanitize_line' : 'sanitize_text' ];
                $def   = (string) $t[ $i ];
                // Text left the same as the default is stored blank, so it keeps following the default.
                register_setting( $g, SEAL_Emails::TEXT_OPT . $key . '_' . $part, [ 'sanitize_callback' => static function ( $v ) use ( $clean, $def ) {
                    $v = call_user_func( $clean, $v );
                    return trim( str_replace( "\r", '', $v ) ) === trim( $def ) ? '' : $v;
                } ] );
            }
            $emails[ $t[0] ][ $key ] = $t[1];
        }
        foreach ( $subjects as $key => $s ) {
            $def = $s[2];
            register_setting( $g, SEAL_Emails::OPT . $key, [ 'sanitize_callback' => static function ( $v ) use ( $def ) {
                $v = SEAL_Emails::sanitize( $v );
                return $v === $def ? '' : $v;
            } ] );
            $emails[ $s[0] ][ $key ] = $emails[ $s[0] ][ $key ] ?? $s[1];
        }
        $intro = [
            'Scheduled jobs' => '<p>Sent by the server\'s cron scripts, which read these settings on every run. Their subjects are used exactly as typed (no <code>[System]</code> is added).</p>',
            'Accounts'       => '<p>Only the subject can be changed here, except for the set-up reminder, whose text can be changed too. The welcome (set your password) and password reset emails are sent by WordPress; the plugin sends them in the same layout as its other emails.</p>',
        ];
        foreach ( [ 'Requests', 'Renewals', 'Automatic Fallback', 'Accounts', 'Scheduled jobs' ] as $group ) {
            if ( empty( $emails[ $group ] ) ) { continue; }
            $sid  = 'seal_subj_' . sanitize_key( $group );
            $desc = $intro[ $group ] ?? '';
            add_settings_section( $sid, esc_html( $group ), static fn() => print $desc, $p );
            foreach ( $emails[ $group ] as $key => $label ) {
                add_settings_field( 'seal_email_' . $key, esc_html( $label ), [ __CLASS__, 'field_email' ], $p, $sid, [ 'key' => $key ] );
            }
        }
    }

    /**
     * Database: the ILL database login (password stored encrypted), table names, Automatic Fallback limits, the
     * catalog gateway and MySQL TLS.
     */
    private static function reg_database(): void {
        $g = self::GROUPS['database']; $p = self::PAGES['database'];
        foreach ( [
            'seal_db_host'        => 'sanitize_text_field',
            'seal_db_user'        => 'sanitize_text_field',
            'seal_db_name'        => 'sanitize_text_field',
            'seal_db_pass'        => [ __CLASS__, 'san_db_pass' ],
            'seal_table_stat'     => 'sanitize_text_field',
            'seal_table_lib'      => 'sanitize_text_field',
            'seal_table_illiad'   => 'sanitize_text_field',
            'seal_table_rota'     => 'sanitize_text_field',
            'seal_rota_max_candidates' => [ __CLASS__, 'san_rota_max' ],
            'seal_rota_retention_days' => 'absint',
            'seal_gateway_url'    => 'esc_url_raw',
            'seal_db_ssl_ca'      => 'sanitize_text_field',
            'seal_db_ssl_cert'    => 'sanitize_text_field',
            'seal_db_ssl_key'     => 'sanitize_text_field',
            'seal_db_ssl_enforce' => [ __CLASS__, 'san_toggle' ],
        ] as $k => $cb ) { register_setting( $g, $k, [ 'sanitize_callback' => $cb ] ); }

        add_settings_section( 'seal_db_conn',   'Connection', fn() => print '<p>Credentials stored encrypted in <code>wp_options</code> using libsodium (AEAD) or OpenSSL AES-256-GCM. Never stored in flat files.</p>', $p );
        add_settings_section( 'seal_db_tables', 'Table Names', '__return_false', $p );
        add_settings_section( 'seal_db_api',    'API &amp; Gateway', '__return_false', $p );

        self::af( $p,'seal_db_conn',  'seal_db_host',      'DB Host',               '' );
        self::af( $p,'seal_db_conn',  'seal_db_user',      'DB Username',           '' );
        self::af( $p,'seal_db_conn',  'seal_db_name',      'DB Name',               '' );
        self::af( $p,'seal_db_conn',  'seal_db_pass',      'DB Password',           '', 'password' );
        self::af( $p,'seal_db_tables','seal_table_stat',   'Stats / History Table', 'Default: <code>STATS</code>' );
        self::af( $p,'seal_db_tables','seal_table_lib',    'Library Data Table',    'Default: <code>Library-Data</code>' );
        self::af( $p,'seal_db_tables','seal_table_illiad', 'ILLiad Mapping Table',  'Default: <code>ILLIAD-ADD-MAPPING</code>' );
        self::af( $p,'seal_db_tables','seal_table_rota',    'Rota (Fallback) Table', 'Default: <code>ILL-ROTA</code>. Created automatically the first time the Automatic Fallback module is turned on. Only matters if you enable that module (Modules tab).' );
        self::af( $p,'seal_db_tables','seal_rota_max_candidates', 'Rota: Max Libraries in Chain', 'Default and maximum: <code>3</code>. Total libraries in one automatic-fallback chain, including the library the borrower picked. Values over 3 are reduced to 3.' );
        self::af( $p,'seal_db_tables','seal_rota_retention_days', 'Rota: Keep Finished History (Days)', 'Default: <code>180</code>. A daily job deletes fallback tracking rows for requests that finished (filled, cancelled, or ran out of libraries) this many days ago. The requests themselves stay in the Stats table forever, same as always — this only prunes the per-hop history shown on ILL Admin &rarr; Automatic Fallback (Rota).' );
        self::af( $p,'seal_db_api',   'seal_gateway_url',  'Z39.50 Gateway URL',    'Base URL for the catalog record proxy.', 'url' );

        add_settings_section( 'seal_db_ssl', 'MySQL TLS / SSL Encryption',
            fn() => print '<p>Encrypt all traffic between WordPress and the MySQL server. '
                . 'Recommended for any setup where MySQL is on a separate host. '
                . 'Leave blank to use an unencrypted connection (local socket only).</p>',
            $p );

        self::af( $p,'seal_db_ssl','seal_db_ssl_enforce','Require Encrypted Connection',
            'When enabled, the plugin refuses to connect if TLS cannot be established. '
            . 'Recommended. Disable only for local-socket connections where encryption is unnecessary.',
            'toggle' );
        self::af( $p,'seal_db_ssl','seal_db_ssl_ca',  'CA Certificate Path',
            'Absolute server path to the MySQL CA certificate (e.g. <code>/etc/ssl/mysql/ca.pem</code>). '
            . 'Required to verify the server identity. Leave blank to encrypt without server verification. '
            . 'For AWS RDS, download the region bundle from <code>https://truststore.pki.rds.amazonaws.com/us-east-2/us-east-2-bundle.pem</code> '
            . '(or the global bundle) to a path readable by the web server and enter that path here.' );
        self::af( $p,'seal_db_ssl','seal_db_ssl_cert','Client Certificate Path',
            'Absolute server path to the client certificate. Only needed for mutual TLS.' );
        self::af( $p,'seal_db_ssl','seal_db_ssl_key', 'Client Key Path',
            'Absolute server path to the client private key. Only needed for mutual TLS.' );
    }

    /** Search & Catalog: catalog addresses and the search category list. */
    private static function reg_search(): void {
        $g = self::GROUPS['search']; $p = self::PAGES['search'];
        foreach ( [
            'seal_catalog_search_url'           => 'esc_url_raw',
            'seal_catalog_search_page'          => 'esc_url_raw',
            'seal_catalog_advanced_url'         => 'esc_url_raw',
            'seal_catalog_login_url'            => 'esc_url_raw',
            'seal_catalog_help_url'             => 'esc_url_raw',
            'seal_catalog_categories_js_url'    => 'esc_url_raw',
            'seal_catalog_categories'           => 'sanitize_textarea_field',
        ] as $k => $cb ) { register_setting( $g, $k, [ 'sanitize_callback' => $cb ] ); }

        add_settings_section( 'seal_srch_urls', 'Catalog URLs', fn() => print '<p>Update these when deploying for a different catalog host.</p>', $p );
        add_settings_section( 'seal_srch_cats', 'Search Categories', fn() => print '<p>The dropdown categories shown in the header search bar and in the homepage search block. Add the search block to any page or the homepage with the <code>[ill_search]</code> shortcode — it is built entirely from these settings.</p>', $p );

        self::af( $p,'seal_srch_urls','seal_catalog_search_url',         'Catalog Base URL',          'e.g. <code>https://catalog.library.org</code>', 'url' );
        self::af( $p,'seal_srch_urls','seal_catalog_search_page',        'Search Results Page',       'Full URL to the search results page.', 'url' );
        self::af( $p,'seal_srch_urls','seal_catalog_advanced_url',       'Advanced Search URL',       'Leave blank to hide the Advanced Search link.', 'url' );
        self::af( $p,'seal_srch_urls','seal_catalog_login_url',          'Catalog Login URL',         'Used by MKWS auth_check().', 'url' );
        self::af( $p,'seal_srch_urls','seal_catalog_help_url',           'Help / LibGuide URL',       'Leave blank to hide the Help link.', 'url' );
        self::af( $p,'seal_srch_urls','seal_catalog_categories_js_url',  'Categories JS URL',         'URL of categories.js from your catalog vendor. Leave blank to disable.', 'url' );
        self::af( $p,'seal_srch_cats','seal_catalog_categories',         'Category List',
            'One per line: <code>value|Label</code>. Start a line with <code>*</code> (or append <code>|selected</code>) to make it the pre-selected default. Leave blank for auto-defaults based on Org Short Name.',
            'textarea',
            "Example:\n<code>all|All\ncategory~tlc_participant|All Lenders\ncategory~tlc_academics|Academic Lenders</code>" );
    }

    /**
     * Users & Registration: home system code, staff login redirect, Terms of Service, invite code and user
     * approval.
     */
    private static function reg_users(): void {
        $g = self::GROUPS['users']; $p = self::PAGES['users'];
        foreach ( [
            'seal_home_system_code'    => 'sanitize_text_field',
            'seal_login_redirect_path' => 'sanitize_text_field',
            'seal_tos_enabled'         => [ __CLASS__, 'san_toggle' ],
            'seal_tos_page_url'        => 'sanitize_text_field',
            'seal_tos_label'           => 'sanitize_text_field',
            'seal_user_approval_required' => [ __CLASS__, 'san_toggle' ],
            'seal_invite_code'         => 'sanitize_text_field',
            'seal_pw_link_days'        => static fn( $v ) => in_array( (int) $v, SEAL_Emails::LINK_DAYS, true ) ? (string) (int) $v : '7',
            'seal_setup_reminders'     => [ __CLASS__, 'san_toggle' ],
            'seal_setup_reminder_hour' => static fn( $v ) => (string) max( 0, min( 23, (int) $v ) ),
        ] as $k => $cb ) { register_setting( $g, $k, [ 'sanitize_callback' => $cb ] ); }

        add_settings_section( 'seal_usr_sys',   'Library System Dropdown', fn() => print '<p>Controls the "Home Library System" select on user profiles and the registration form.</p>', $p );
        add_settings_section( 'seal_usr_login', 'Login Redirect',          '__return_false', $p );
        add_settings_section( 'seal_usr_tos',   'Terms of Service',        fn() => print '<p>Checkbox shown on the WordPress registration form.</p>', $p );

        self::af( $p,'seal_usr_sys',  'seal_home_system_code',   '"Home" System Code',     'The option code that maps to your org in the system dropdown. Default: <code>SE</code>' );
        self::af( $p,'seal_usr_login','seal_login_redirect_path','Staff Login Redirect',   'Path staff land on after login when they had no specific destination. Default: <code>/illuser/</code>' );
        self::af( $p,'seal_usr_tos',  'seal_tos_enabled',        'Enable TOS Checkbox',   '', 'toggle' );
        self::af( $p,'seal_usr_tos',  'seal_tos_page_url',       'TOS Page URL',          'Path or full URL. e.g. <code>/terms-of-service</code>' );
        self::af( $p,'seal_usr_tos',  'seal_tos_label',          'TOS Link Label',        'Checkbox link text. Blank defaults to "<em>{System Name} Terms of Service</em>".' );

        add_settings_section( 'seal_usr_pwlink', 'Set-Password Links', fn() => print '<p>How long the link in the new-account (welcome) email and in password reset emails keeps working. Use a longer time while loading a batch of new accounts (e.g. on a Friday, so people can set their password the next week), then shorten it for day-to-day use. Each link still works only once. WordPress checks a link\'s age when it is used, so a change also applies to links already sent: shortening it makes older unused links stop working (people can use <em>Lost your password?</em> to get a new one).</p>', $p );
        add_settings_field( 'seal_pw_link_days', 'Password Link Valid For', [ __CLASS__, 'field_select' ], $p, 'seal_usr_pwlink', [
            'name' => 'seal_pw_link_days', 'label_for' => 'seal_pw_link_days',
            'options' => array_combine( array_map( 'strval', SEAL_Emails::LINK_DAYS ), array_map( static fn( $d ) => $d === 1 ? '1 day (WordPress default)' : $d . ' days', SEAL_Emails::LINK_DAYS ) ),
        ] );
        self::af( $p,'seal_usr_pwlink','seal_setup_reminders', 'Set-up Reminders', 'Email people who haven\'t set their password yet: once 3 days and once 1 day before their link expires. Each reminder has a fresh “Set your password” button that keeps the original deadline. Stops as soon as they set a password. Text: ILL System &rarr; Email Text &rarr; Accounts.', 'toggle' );
        add_settings_field( 'seal_setup_reminder_hour', 'Send Reminders At', [ __CLASS__, 'field_select' ], $p, 'seal_usr_pwlink', [
            'name' => 'seal_setup_reminder_hour', 'label_for' => 'seal_setup_reminder_hour',
            'options' => array_combine( array_map( 'strval', range( 0, 23 ) ), array_map( static fn( $h ) => wp_date( 'g:00 a', mktime( $h, 0, 0 ) ), range( 0, 23 ) ) ),
            'desc' => 'Site time zone. WordPress runs scheduled jobs when someone visits the site; for sends right on the hour, have the server run <code>php /path/to/wordpress/wp-cron.php</code> from cron every few minutes.',
        ] );

        add_settings_section( 'seal_usr_invite', 'Invite Code', fn() => print '<p>A shared code new users must enter on the registration form to keep the general public out. Reusable; change it when you want to rotate it (e.g. each January). Leave blank to turn the requirement off. Requires the <strong>Invite Code</strong> module on the Modules tab.</p>', $p );
        self::af( $p,'seal_usr_invite','seal_invite_code','Registration Invite Code','Not case-sensitive. Existing accounts are not affected when you change it.' );

        add_settings_section( 'seal_usr_approval', 'User Approval', fn() => print '<p>When enabled, new registrations are held "pending" until an admin approves. Admins get an email with one-click Approve/Deny links. Also enable the <strong>User Approval</strong> module on the Modules tab.</p>', $p );
        self::af( $p,'seal_usr_approval','seal_user_approval_required','Require Approval for New Users','','toggle' );
    }

    /**
     * Org Rules: one editable list per SEAL_Org_Rules key (except the cron list, which lives on Cron Jobs),
     * plus Closure Dates and its upcoming-closures preview.
     */
    private static function reg_rules(): void {
        $g = self::GROUPS['rules']; $p = self::PAGES['rules'];
        foreach ( SEAL_Org_Rules::KEYS as $k ) {
            if ( $k === 'crons' ) { continue; }   // on the Cron Jobs screen (reg_cron)
            register_setting( $g, 'seal_rules_' . $k, [ 'sanitize_callback' => [ __CLASS__, 'san_rules' ] ] );
        }

        add_settings_section( 'seal_rules_start', 'How this page works', fn() => print self::rules_flow_html() . '<p>Every install keeps its own rules, so nothing here is pre-filled for a new site. Each list is a table: change a cell, use <strong>Add row</strong> or <strong>Remove</strong>, and <strong>Save</strong>. <em>Edit as text</em> lets you paste a whole list. To copy all rules to another site, use <strong>Move these rules to another site</strong> at the bottom of the page.</p><p>Until <strong>Item types</strong> has rows, item types are not checked at all. Under <strong>Change history</strong> every change is recorded.</p>', $p );

        add_settings_section( 'seal_rules_lists', 'Systems &amp; Couriers', '__return_false', $p );
        self::af( $p,'seal_rules_lists','seal_rules_systems',  'Library Systems', 'One per line: <code>code|Label</code>. Used in dropdowns, the directory and statistics.', 'rules' );
        self::af( $p,'seal_rules_lists','seal_rules_couriers', 'Couriers / Ship Methods', 'One per line: <code>code|Label</code>. Used when a lender responds and in request lists.', 'rules' );

        add_settings_section( 'seal_rules_cat', 'Catalogs &amp; Library Matching', fn() => print '<p>How the request page turns a catalog record\'s holdings into a participating library.</p>', $p );
        self::af( $p,'seal_rules_cat','seal_rules_catalogs',  'Catalog Type per Location', 'One per line: <code>Catalog location name|Catalog type</code> (Koha, Alma, OPALS, Polaris, ...).', 'rules' );
        self::af( $p,'seal_rules_cat','seal_rules_behaviour', 'How the Library Alias Is Chosen', '<code>Catalog type|name</code> uses the catalog location name; <code>|local</code> uses the holding\'s own location; <code>|uhls</code> trims it at the last "-". Types not listed use <code>local</code>.', 'rules' );
        self::af( $p,'seal_rules_cat','seal_rules_alias',     'Alias Matching Rules', '<code>Catalog location name|exact / prefix / contains|split yes / no|systems</code>. "split yes" uses the text before the first ":" or "-". Systems is a comma list or <code>*</code>. Catalogs not listed match the alias exactly.', 'rules' );

        add_settings_section( 'seal_rules_loan', 'Item Types &amp; Special Cases', '__return_false', $p );
        self::af( $p,'seal_rules_loan','seal_rules_itemtypes', 'Item Types a Library Loans', '<code>item type|library column|deny:SYS,SYS</code>. The column is one of <code>book_loan, av_loan, ejournal_request, ebook_request, theses_loan</code> (must be "Yes" on the library record) or <code>any</code>. A request with no item type is always allowed; a type not listed is refused.', 'rules' );
        self::af( $p,'seal_rules_loan','seal_rules_exempt',    'Libraries Exempt from Item-Type Checks', 'One library name per line.', 'rules' );
        self::af( $p,'seal_rules_loan','seal_rules_special',   'Special Cases', '<code>require|Catalog name contains|Holding location must contain</code> and <code>allow-type|Catalog type|Holding location</code>.', 'rules' );
        self::af( $p,'seal_rules_loan','seal_rules_extra',     'Extra Holdings Lookups', 'For catalogs whose full holdings are only in the MARC record: <code>type:Koha or name:Library|MARC field|location subfield|call number subfield|availability subfield</code>. Start a call subfield with <code>@</code> to read a field outside the holding, e.g. <code>@d050.sa</code>; use <code>-</code> for no availability field. Optional 6th column <code>Catalog name=subfield</code> overrides the availability subfield.', 'rules' );

        add_settings_section( 'seal_rules_holidays', 'Closure Dates', fn() => print '<p>Days your libraries are closed that count as non-business days for the reminder, expire and Rota-timeout cron jobs (weekends are always excluded). US federal holidays are added automatically — list only your own closures here (winter break, a staff day, ...). This list is authoritative: the cron jobs read it directly, so there is no file on the server to keep in sync.</p>', $p );
        self::af( $p,'seal_rules_holidays','seal_rules_closures', 'Closure Dates', 'One per line: <code>Date|Label</code>, e.g. <code>2026-12-24|Winter Break</code>. The Label is for your own reference only.', 'rules' );
        add_settings_field( 'seal_holiday_list', 'Upcoming closures', [ __CLASS__, 'field_holidays' ], $p, 'seal_rules_holidays' );
    }

    /** ILL → Cron Jobs: the Scheduled Jobs list and the settings the copy-and-paste crontab is built from. */
    private static function reg_cron(): void {
        $g = self::GROUPS['cron']; $p = self::PAGES['cron'];
        foreach ( [ 'dir', 'php', 'log' ] as $ck ) { register_setting( $g, 'seal_cron_' . $ck, [ 'sanitize_callback' => [ __CLASS__, 'san_cron_path' ] ] ); }
        register_setting( $g, 'seal_cron_code', [ 'sanitize_callback' => [ __CLASS__, 'san_cron_code' ] ] );
        register_setting( $g, 'seal_cron_output', [ 'sanitize_callback' => [ __CLASS__, 'san_cron_output' ] ] );
        register_setting( $g, 'seal_rules_crons', [ 'sanitize_callback' => [ __CLASS__, 'san_rules' ] ] );
        foreach ( [ 'seal_anon_expire_months' => 240, 'seal_anon_note_days' => 3650, 'seal_anon_months' => 600 ] as $k => $max ) {
            register_setting( $g, $k, [ 'sanitize_callback' => static fn( $v ) => (string) max( 0, min( $max, (int) $v ) ) ] );
        }

        add_settings_section( 'seal_rules_cron', 'Scheduled Jobs', fn() => print '<p>The automatic jobs that run on the server (reminders, expiring requests, ILLiad checks, weekly statistics). <strong>Reference only</strong>: the plugin does not run or change them. Fill in the list and settings, save, then copy the block at the bottom into the server\'s crontab.</p>'
            . '<p>Business days for the reminder, expire and Rota-timeout jobs come from <a href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGES['rules'] . '#seal-rules-closures' ) ) . '">Org Rules &rarr; Closure Dates</a> plus US federal holidays.</p>', $p );
        self::af( $p,'seal_rules_cron','seal_rules_crons', 'Scheduled Jobs', '', 'rules' );
        add_settings_field( 'seal_cron_code',   'Short code',     [ __CLASS__, 'field_cronset' ], $p, 'seal_rules_cron', [ 'label_for' => 'seal_cron_code', 'name' => 'seal_cron_code',   'desc' => 'Names the scripts folder: <code>{code}</code> in the Scripts folder (or Log file) becomes this, e.g. <code>/var/www/{code}_script</code> &rarr; <code>/var/www/cdlc_script</code>. Lower-case letters and digits (cdlc, seal, duenorth). Blank = the Org Short Name. The script file names are the same for every organization.' ] );
        add_settings_field( 'seal_cron_dir',    'Scripts folder', [ __CLASS__, 'field_cronset' ], $p, 'seal_rules_cron', [ 'label_for' => 'seal_cron_dir', 'name' => 'seal_cron_dir',    'desc' => 'Where the script files live on the server. <code>{code}</code> is replaced by the Short code.' ] );
        add_settings_field( 'seal_cron_php',    'PHP program',    [ __CLASS__, 'field_cronset' ], $p, 'seal_rules_cron', [ 'label_for' => 'seal_cron_php', 'name' => 'seal_cron_php',    'desc' => 'Full path to PHP on the server (run <code>which php</code> to check).' ] );
        add_settings_field( 'seal_cron_output', 'Job output',     [ __CLASS__, 'field_cronoutput' ], $p, 'seal_rules_cron', [ 'label_for' => 'seal_cron_output', 'name' => 'seal_cron_output' ] );
        add_settings_field( 'seal_cron_log',    'Log file',       [ __CLASS__, 'field_cronset' ], $p, 'seal_rules_cron', [ 'label_for' => 'seal_cron_log', 'name' => 'seal_cron_log',    'desc' => 'Used when job output is appended to a log file. The user running the jobs must be able to write to it.' ] );
        add_settings_field( 'seal_cron_paste',  'Copy and paste', [ __CLASS__, 'field_cronpaste' ], $p, 'seal_rules_cron', [ 'name' => 'seal_cron_paste' ] );

        add_settings_section( 'seal_cron_anon', 'Data retention (anonymize.php)', fn() => print '<p>The <code>anonymize.php</code> job reads these every time it runs (it must be in the crontab: e.g. <code>30 6 * * *</code>). '
            . 'Enter <code>0</code> to turn a step off. Step 3 can\'t be undone: run <code>php anonymize.php --dry-run</code> on the server after changing it to see what it would do. '
            . 'If the job can\'t read WordPress\'s settings it uses the values in its <code>cron_config.php</code>.</p>', $p );
        self::af( $p,'seal_cron_anon','seal_anon_expire_months', 'Expire unanswered requests after (months)', 'Requests still waiting for an answer this long are marked Expired (no emails). Default <code>18</code>.' );
        self::af( $p,'seal_cron_anon','seal_anon_note_days',     'Clear the request note of expired requests after (days)', 'Counted from when the request expired. Default <code>30</code>.' );
        self::af( $p,'seal_cron_anon','seal_anon_months',        'Anonymize requests older than (months)', 'Blanks title, author, ISBN / ISSN, item type, call number, article, dates and every note; the request itself stays for statistics. Default <code>60</code> (5 years).' );
    }

    /** Modules: one on/off toggle per optional module. */
    private static function reg_modules(): void {
        $g = self::GROUPS['modules']; $p = self::PAGES['modules'];
        $modules = [
            'seal_module_user_fields'     => 'User Profile Fields — institution, LOC code, delivery address, phone, alt email',
            'seal_module_login_redirect'  => 'Staff Login Redirect — send libstaff to /illuser/ after login',
            'seal_module_tos'             => 'Terms of Service — checkbox gate on the registration form',
            'seal_module_invite_code'     => 'Invite Code — require a shared code on the registration form',
            'seal_module_dashboard_tweak' => 'Dashboard Tweak — Quick Links widget and admin bar menu',
            'seal_module_export_users'    => 'Export Users — CSV download under Users menu',
            'seal_module_catalog_search'  => 'Catalog Search Header — MKWS search bar and logo at page top',
            'seal_module_last_login'      => 'Last Login Tracking — records and displays when each user last logged in',
            'seal_module_role_editor'     => 'Role Editor — change user roles from profile + bulk assign from Users list',
            'seal_module_user_approval'   => 'User Approval — hold new registrations pending until admin approves',
            'seal_module_site_footer'     => 'Site Footer — organization footer (logo, address, links, copyright) on every page',
            'seal_module_rota'             => 'Automatic Fallback (Rota) — when a lender declines or never answers, move the request to the next available library automatically. Borrower opts in per request.',
        ];
        foreach ( $modules as $k => $label ) {
            register_setting( $g, $k, [ 'sanitize_callback' => [ __CLASS__, 'san_toggle' ] ] );
        }
        add_settings_section( 'seal_mod_sec', '',
            fn() => print '<p>Disabling a module removes its hooks entirely. Changes take effect on next page load.</p>', $p );
        foreach ( $modules as $k => $label ) {
            add_settings_field( $k, $label, [ __CLASS__, 'field_toggle' ], $p, 'seal_mod_sec', [ 'name' => $k, 'desc' => '', 'label_for' => $k ] );
        }
    }

    // ── Shared field adder ────────────────────────────────────────────────────

    /**
     * Adds one settings field. $type picks the field_<type>() renderer; label_for ties the row label to the
     * control for screen readers.
     */
    private static function af( string $page, string $section, string $name, string $label, string $desc = '', string $type = 'text', string $extra = '' ): void {
        add_settings_field( $name, $label, [ __CLASS__, 'field_' . $type ], $page, $section,
            [ 'name' => $name, 'desc' => $desc, 'extra' => $extra, 'label_for' => $name ] );   // label_for: WordPress ties the row label to the control
    }

    // ── Page renderers ────────────────────────────────────────────────────────

    /**
     * The ILL System dashboard: active identity, a tile per settings screen, links to the ILL admin pages, and
     * the database connection status.
     */
    public static function render_dashboard(): void {
        if ( ! current_user_can('manage_options') ) { return; }
        $sys    = esc_html( seal_config('system_name') );
        $org    = esc_html( seal_config('org_name') );
        $domain = esc_html( seal_config('org_domain') );
        $sysurl = esc_url(  seal_config('system_url') );
        $from   = esc_html( seal_config('email_from') );
        $admin  = esc_html( seal_config('admin_email') );
        $sup    = esc_html( seal_config('support_emails') );
        ?>
        <div class="wrap">
            <h1><?php echo $sys; ?> ILL System — Dashboard
                <span style="font-size:.5em;font-weight:normal;color:#595959;">v<?php echo SEAL_ILL_VERSION; ?></span>
            </h1>
            <p style="color:#595959;margin-top:-8px;">By <strong>Zachary Spalding</strong>, SENYLRC &amp; <strong>Claude</strong> (Anthropic) · Apache 2.0</p>

            <div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:18px 22px;margin:16px 0;max-width:620px;">
                <h3 style="margin:0 0 10px;">Active Identity</h3>
                <table style="border-collapse:collapse;width:100%;font-size:.95em;">
                <?php foreach ([
                    'System name'   => $sys,
                    'Organization'  => $org,
                    'Domain'        => $domain,
                    'System URL'    => $sysurl ? "<a href='{$sysurl}'>{$sysurl}</a>" : '<em>not set</em>',
                    'Admin email(s)'=> $admin  ?: '<em>not set</em>',
                    'Support CC'    => $sup    ?: '<em>none</em>',
                    'From: address' => $from   ?: '<em>not set</em>',
                ] as $k => $v): ?>
                <tr>
                    <th scope="row" style="text-align:left;padding:4px 14px 4px 0;width:150px;color:#555;font-weight:normal;"><?php echo esc_html($k); ?></th>
                    <td><strong><?php echo wp_kses($v,['a'=>['href'=>[]],'em'=>[]]); ?></strong></td>
                </tr>
                <?php endforeach; ?>
                </table>
                <p style="margin:12px 0 0;">
                    <a href="<?php echo esc_url(admin_url('admin.php?page='.self::PAGES['branding'])); ?>" class="button button-primary">Change Branding</a>
                </p>
            </div>

            <h2>Settings</h2>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:10px;max-width:800px;margin-bottom:24px;">
            <?php foreach ([
                [self::PAGES['branding'],'🎨','Branding'],
                [self::PAGES['contacts'],'✉️','Contacts & Email'],
                [self::PAGES['subjects'],'📨','Email Subjects & Text'],
                [self::PAGES['database'],'🗄️','Database'],
                [self::PAGES['search'],  '🔍','Search & Catalog'],
                [self::PAGES['users'],   '👤','Users & Registration'],
                [self::PAGES['modules'], '🧩','Modules'],
                [self::PAGES['rules'],   '⚖️','Org Rules'],
                [self::PAGES['cron'],    '⏰','Cron Jobs'],
                ['seal-ill-security',    '🛡️','Security'],
                ['seal-ill-routes',      '🗺️','Routes'],
                ['seal-ill-history',     '🕘','Change History'],
                [SEAL_Import_Users::PAGE, '📥','Import Users'],
            ] as [$pg,$icon,$lbl]): ?>
                <a href="<?php echo esc_url(admin_url('admin.php?page='.$pg)); ?>"
                   style="display:block;background:#fff;border:1px solid #ddd;border-radius:5px;padding:12px;text-decoration:none;color:#23282d;">
                    <span style="font-size:1.3em;"><?php echo $icon; ?></span><br>
                    <strong><?php echo esc_html($lbl); ?></strong>
                </a>
            <?php endforeach; ?>
            </div>

            <h2>ILL Admin Links</h2>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:10px;max-width:800px;">
            <?php foreach ([
                [home_url('/allrequests'),    '📋','All Requests'],
                [home_url('/adminlib'),       '🏛️','Library Directory'],
                [home_url('/adminlib-accounts'),'👤','User Accounts'],
                [home_url('/stats'),      '📊','Statistics'],
                [home_url('/export'),         '⬇️','CSV Export'],
                [home_url('/email-harvest'),  '✉️','Email Harvest'],
                [home_url('/resend'),       '🔁','Resend a Request'],
            ] as [$url,$icon,$lbl]): ?>
                <a href="<?php echo esc_url($url); ?>"
                   style="display:block;background:#fff;border:1px solid #ddd;border-radius:5px;padding:12px;text-decoration:none;color:#23282d;">
                    <span style="font-size:1.3em;"><?php echo $icon; ?></span><br>
                    <strong><?php echo esc_html($lbl); ?></strong>
                </a>
            <?php endforeach; ?>
            </div>

            <h2 style="margin-top:28px;">Database Connection</h2>
            <?php
            try {
                SEAL_DB::get();
                echo '<div class="notice notice-success inline" style="margin:0;"><p>✅ Connected to <strong>'.esc_html(seal_config('db_name')?: '(not configured)').'</strong> on <code>'.esc_html(seal_config('db_host')).'</code>.</p></div>';
            } catch (RuntimeException $e) {
                echo '<div class="notice notice-error inline" style="margin:0;"><p>❌ '.esc_html($e->getMessage()).' — <a href="'.esc_url(admin_url('admin.php?page='.self::PAGES['database'])).'">Check settings</a></p></div>';
            }
            ?>
        </div>
        <?php
    }

    /** Settings screen: prints the Branding tab (fields registered in reg_branding()) through tab(). */
    public static function render_branding(): void { self::tab('branding','🎨 Branding &amp; Identity','Save Branding','Re-deploying for a different system? Change the fields here — every email, label, and link updates automatically.','info'); }
    /**
     * Settings screen: prints the Contacts & Email tab (fields registered in reg_contacts()) through tab().
     * Below it, the last 50 emails held or redirected by Email test mode.
     */
    public static function render_contacts(): void {
        self::tab('contacts','✉️ Contacts &amp; Email','Save Contacts');
        if ( ! current_user_can('manage_options') ) { return; }
        $log = array_slice( array_reverse( (array) get_option( 'seal_mail_log', [] ) ), 0, 50 );
        echo '<div class="wrap"><h2>Emails held or redirected by test mode</h2>';
        if ( ! $log ) { echo '<p>None yet. Emails appear here while test mode is on.</p></div>'; return; }
        echo '<table class="widefat striped" style="max-width:1000px"><thead><tr><th scope="col">When</th><th scope="col">Would have gone to</th><th scope="col">Subject</th><th scope="col">What happened</th></tr></thead><tbody>';
        foreach ( $log as $e ) {
            echo '<tr><td>' . esc_html( wp_date( 'Y-m-d H:i', (int) ( $e['time'] ?? 0 ) ) ) . '</td><td>' . esc_html( (string) ( $e['to'] ?? '' ) )
               . '</td><td>' . esc_html( (string) ( $e['subject'] ?? '' ) ) . '</td><td>' . esc_html( (string) ( $e['result'] ?? '' ) ) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    /** Settings screen: prints the Email Subjects tab (fields registered in reg_subjects()) through tab(). */
    public static function render_subjects(): void {
        self::tab( 'subjects', '📨 Email Subjects &amp; Text', 'Save Email Text',
            'Open an email to change its subject, heading, message or closing line. Every saved change is listed under <strong>Change history</strong> below. Leave a box blank to use the default shown in it; enter a single <code>-</code> to leave a message or closing out. Words in braces are filled in when the email is sent, e.g. <code>{ill}</code> becomes the ILL number. In the message and closing, <code>**text**</code> is bold, <code>*text*</code> is italic and a blank line starts a new paragraph. Subjects of emails sent from the site get the System Short Name in brackets in front, e.g. <code>[ILL]</code>, unless they already contain it.', 'info', [ 'SEAL_Admin', 'render_subjects_style' ] );
    }
    /** Styles and script for the Email Subjects & Text screen, then its change history (printed after the form). */
    public static function render_subjects_style(): void {
        echo '<style>.seal-email-edit summary{cursor:pointer;padding:4px 0}.seal-email-edit summary .seal-email-subj{color:#1d2327}'
           . '.seal-email-changed{display:inline-block;margin-left:6px;padding:0 7px;border-radius:9px;background:#dff0d8;color:#1e4620;font-size:12px}'
           . '.seal-email-fields{padding:6px 0 4px}.seal-email-fields label{display:block;font-weight:600;margin:10px 0 3px}.seal-email-fields label:first-child{margin-top:4px}'
           . '.seal-email-fields label .description{font-weight:normal}</style>'
           . '<script>document.addEventListener("click",function(e){var b=e.target.closest(".seal-email-fill");if(!b){return;}'
           . 'b.closest(".seal-email-fields").querySelectorAll("input[type=text],textarea").forEach(function(f){if(f.value===""&&f.placeholder&&f.placeholder!=="(none)"){f.value=f.placeholder;}});});</script>';
        SEAL_Emails::render_log();
    }
    /** ILL → Change History (SEAL_Audit). */
    public static function render_history(): void { SEAL_Audit::render_page(); }
    /** ILL → Import Users (SEAL_Import_Users). */
    public static function render_import(): void { SEAL_Import_Users::render_page(); }
    /** Settings screen: prints the Database tab (fields registered in reg_database()) through tab(). */
    public static function render_database(): void { self::tab('database','🗄️ Database &amp; Tables','Save Database','DB credentials are stored encrypted in <code>wp_options</code> (libsodium AEAD or AES-256-GCM keyed from your WordPress secret keys).','warning', static function () { self::render_illiad_keys(); self::render_db_check(); } ); }

    /**
     * Below the Database form: the state of the ILLiad API keys (SEAL_Secret) — encryption key present, how many keys are
     * encrypted, whether the column is wide enough — and a button that encrypts the plain-text ones.
     */
    public static function render_illiad_keys(): void {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        echo '<h2 id="illiad-keys">ILLiad API keys</h2><p>Each ILLiad library\'s API key is stored in the library table. Encrypted, a database login, export or backup '
           . 'shows only scrambled text. The encryption key is kept outside the database: in <code>wp-config.php</code> for the site and in the scripts\' '
           . '<code>ill_db.inc</code> for the scheduled jobs (both must have the same value). Keys are entered on each library\'s record (Manage All Libraries &rarr; Edit &rarr; ILLiad).</p>';
        if ( isset( $_GET['seal_keys'] ) ) {
            $d = (int) ( $_GET['done'] ?? 0 ); $s = sanitize_text_field( wp_unslash( $_GET['skipped'] ?? '' ) );
            echo '<div class="notice notice-' . ( $s === '' ? 'success' : 'warning' ) . ' inline"><p>' . $d . ' key' . ( $d === 1 ? '' : 's' ) . ' encrypted.'
               . ( $s !== '' ? ' Not encrypted: ' . esc_html( $s ) . '.' : '' ) . '</p></div>';
        }
        $status = SEAL_Secret::key_status();
        $c      = SEAL_DB::$sealLIB !== '' ? SEAL_Secret::key_counts() : [ 'total' => 0, 'encrypted' => 0, 'plain' => 0, 'unreadable' => 0 ];
        $width  = SEAL_Secret::column_width();
        $need   = SEAL_Secret::sealed_length( 64 );
        $rows   = [
            'Encryption key (ILL_DATA_KEY)' => [ 'ok' => '<span style="color:#0a5c2e">&#10004; Set in wp-config.php</span>', 'missing' => '<span style="color:#8a1f1f">Not set</span>', 'invalid' => '<span style="color:#8a1f1f">Set, but not a valid key (must be base64 of 32 bytes)</span>' ][ $status ],
            'Keys stored'                   => (int) $c['total'],
            'Encrypted'                     => (int) $c['encrypted'] . ( $c['unreadable'] ? ' <span style="color:#8a1f1f">(' . (int) $c['unreadable'] . ' can\'t be decrypted with this key)</span>' : '' ),
            'Still in plain text'           => $c['plain'] ? '<strong style="color:#8a1f1f">' . (int) $c['plain'] . '</strong>' : '0',
            'APIkey column'                 => $width === 0 ? 'Text (no limit)' : ( $width > 0 ? $width . ' characters' . ( $width < $need ? ' <span style="color:#8a1f1f">— too narrow for encrypted keys (needs ' . $need . ')</span>' : '' ) : 'unknown' ),
        ];
        echo '<table class="widefat striped" style="max-width:740px"><tbody>';
        foreach ( $rows as $k => $v ) { echo '<tr><th scope="row" style="width:260px">' . esc_html( $k ) . '</th><td>' . $v . '</td></tr>'; }
        echo '</tbody></table>';
        if ( $status !== 'ok' ) {
            echo '<p><strong>To set it up:</strong> on the server, make a key with <code>php -r \'echo base64_encode(random_bytes(32)), PHP_EOL;\'</code>, then add '
               . '<code>define( \'ILL_DATA_KEY\', \'…\' );</code> to <code>wp-config.php</code> (above "That\'s all, stop editing") and <code>$illDataKey = \'…\';</code> to the scripts\' '
               . '<code>ill_db.inc</code>. <strong>Keep a copy of the key somewhere safe</strong>: without it the stored ILLiad keys can\'t be read and each library would have to send its key again.</p>';
        }
        if ( $width > 0 && $width < $need ) {
            echo '<p>Widen the column first (in the database, once): <code>ALTER TABLE `' . esc_html( SEAL_DB::$sealLIB ) . '` MODIFY `APIkey` VARCHAR(255);</code></p>';
        }
        if ( $status === 'ok' && $c['plain'] > 0 && ( $width === 0 || $width >= $need || $width < 0 ) ) {
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:10px">';
            wp_nonce_field( 'seal_encrypt_illiad_keys' );
            echo '<input type="hidden" name="action" value="seal_encrypt_illiad_keys"><button class="button button-primary">Encrypt ' . (int) $c['plain'] . ' plain-text key' . ( $c['plain'] === 1 ? '' : 's' ) . '</button> '
               . '<span class="description">Add <code>$illDataKey</code> to ill_db.inc first, so the scheduled ILLiad checks can still read them.</span></form>';
        }
    }

    /**
     * Below the ILLiad keys: the Database check (SEAL_DB_Schema). Runs on request (it reads table structure and counts a few
     * things in the library table), lists each finding with the SQL that fixes it, and offers to create tables that don't exist.
     * Nothing else is changed: ALTERs are for an administrator to run, after a backup.
     */
    public static function render_db_check(): void {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        $url = add_query_arg( [ 'page' => self::PAGES['database'], 'seal_dbcheck' => 1 ], admin_url( 'admin.php' ) ) . '#db-check';
        echo '<h2 id="db-check">Database check</h2><p style="max-width:740px">Checks that the ILL database has the tables and columns this version uses, '
           . 'with sizes and settings that work on any MySQL server, and looks for a few data problems (duplicate LOC codes, libraries with no ILL email). '
           . 'It only reads. Each problem comes with the SQL that fixes it; run that yourself, after a backup. Run it after setting up a new site, '
           . 'after moving the database, and after upgrading the plugin.</p>';
        if ( isset( $_GET['seal_created'] ) ) {
            $c = sanitize_text_field( wp_unslash( $_GET['seal_created'] ) ); $x = sanitize_text_field( wp_unslash( $_GET['seal_failed'] ?? '' ) );
            echo '<div class="notice notice-' . ( $x === '' ? 'success' : 'error' ) . ' inline"><p>' . ( $c !== '' ? 'Created: ' . esc_html( $c ) . '. ' : 'No table was created. ' )
               . ( $x !== '' ? 'Could not create: ' . esc_html( $x ) . '.' : '' ) . '</p></div>';
        }
        if ( empty( $_GET['seal_dbcheck'] ) ) {
            echo '<p><a class="button button-primary" href="' . esc_url( $url ) . '">Run database check</a></p>';
            return;
        }
        $found  = SEAL_DB_Schema::check();
        $counts = array_count_values( array_column( $found, 'level' ) ) + [ 'fail' => 0, 'warn' => 0, 'info' => 0, 'ok' => 0 ];
        $icon   = [ 'ok' => [ '&#10004;', '#0a5c2e', 'OK' ], 'info' => [ '&#9432;', '#1d4f91', 'Note' ], 'warn' => [ '&#9888;', '#7a4b00', 'Warning' ], 'fail' => [ '&#10006;', '#8a1f1f', 'Problem' ] ];
        $sum    = $counts['fail'] ? $counts['fail'] . ' problem' . ( $counts['fail'] === 1 ? '' : 's' ) . ' to fix' : 'No problems';
        $sum   .= $counts['warn'] ? ', ' . $counts['warn'] . ' warning' . ( $counts['warn'] === 1 ? '' : 's' ) : '';
        echo '<p role="status"><strong>' . esc_html( $sum ) . '.</strong> Checked ' . esc_html( wp_date( 'M j, Y g:i a' ) ) . '. <a href="' . esc_url( $url ) . '">Check again</a></p>';
        echo '<table class="widefat striped" style="max-width:1000px"><thead><tr><th scope="col" style="width:90px">Result</th><th scope="col">Finding</th></tr></thead><tbody>';
        $creatable = false;
        foreach ( $found as $i => $x ) {
            [ $sym, $color, $word ] = $icon[ $x['level'] ];
            echo '<tr><td style="color:' . $color . ';font-weight:600;white-space:nowrap"><span aria-hidden="true">' . $sym . '</span> ' . esc_html( $word ) . '</td><td>'
               . '<strong>' . esc_html( $x['title'] ) . '</strong>' . ( $x['detail'] !== '' ? '<br>' . esc_html( $x['detail'] ) : '' )
               . ( $x['hint'] !== '' ? '<br><em>' . esc_html( $x['hint'] ) . '</em>' : '' );
            if ( $x['sql'] !== '' ) {
                $id = 'seal-sql-' . $i;
                echo '<details style="margin-top:6px"><summary>SQL</summary><label for="' . esc_attr( $id ) . '" class="screen-reader-text">SQL for: ' . esc_html( $x['title'] ) . '</label>'
                   . '<textarea id="' . esc_attr( $id ) . '" readonly rows="' . min( 14, substr_count( $x['sql'], "\n" ) + 2 ) . '" class="large-text code" spellcheck="false">' . esc_textarea( $x['sql'] ) . '</textarea></details>';
            }
            echo '</td></tr>';
            if ( str_starts_with( $x['sql'], 'CREATE TABLE' ) ) { $creatable = true; }
        }
        echo '</tbody></table>';
        if ( $creatable ) {
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:12px">';
            wp_nonce_field( 'seal_create_ill_tables' );
            echo '<input type="hidden" name="action" value="seal_create_ill_tables"><button class="button">Create the missing tables</button> '
               . '<span class="description">Creates empty tables under the names set above. Tables that exist are never changed.</span></form>';
        }
    }

    /** Create the ILL tables that don't exist (button above). Administrators only; recorded in Change History. */
    public static function handle_create_tables(): void {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Not allowed.', '', [ 'response' => 403 ] ); }
        check_admin_referer( 'seal_create_ill_tables' );
        try {
            $r = SEAL_DB_Schema::create_missing();
        } catch ( \Throwable $e ) {
            $r = [ 'created' => [], 'failed' => [ $e->getMessage() ] ];
        }
        if ( class_exists( 'SEAL_Audit' ) && ( $r['created'] || $r['failed'] ) ) {
            SEAL_Audit::event( 'security', 'ILL database tables', ( $r['created'] ? 'Created ' . implode( ', ', $r['created'] ) : 'Nothing created' ) . ( $r['failed'] ? '; failed: ' . implode( ', ', $r['failed'] ) : '' ) );
        }
        wp_safe_redirect( add_query_arg( [ 'page' => self::PAGES['database'], 'seal_dbcheck' => 1, 'seal_created' => implode( ', ', $r['created'] ), 'seal_failed' => implode( ', ', $r['failed'] ) ], admin_url( 'admin.php' ) ) . '#db-check' );
        exit;
    }

    /** Encrypt every plain-text ILLiad key (button above). Administrators only; recorded in Change History. */
    public static function handle_encrypt_keys(): void {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Not allowed.', '', [ 'response' => 403 ] ); }
        check_admin_referer( 'seal_encrypt_illiad_keys' );
        try {
            $r = SEAL_Secret::encrypt_all();
        } catch ( RuntimeException $e ) {
            $r = [ 'done' => 0, 'skipped' => [ $e->getMessage() ] ];
        }
        if ( class_exists( 'SEAL_Audit' ) ) { SEAL_Audit::event( 'security', 'ILLiad API keys', 'Encrypted ' . $r['done'] . ' plain-text key(s)' . ( $r['skipped'] ? '; not encrypted: ' . implode( ', ', $r['skipped'] ) : '' ) ); }
        wp_safe_redirect( add_query_arg( [ 'page' => self::PAGES['database'], 'seal_keys' => 1, 'done' => $r['done'], 'skipped' => implode( ', ', $r['skipped'] ) ], admin_url( 'admin.php' ) ) . '#illiad-keys' );
        exit;
    }
    /** Settings screen: prints the Search & Catalog tab (fields registered in reg_search()) through tab(). */
    public static function render_search():   void { self::tab('search','🔍 Search &amp; Catalog','Save Search'); }
    /**
     * Settings screen: prints the Users & Registration tab (fields registered in reg_users()) through tab().
     */
    public static function render_users():    void { self::tab('users','👤 Users &amp; Registration','Save Users'); }
    /**
     * Settings screen: prints the Org Rules tab (fields registered in reg_rules()) through tab(). Followed by
     * import / export and the change history.
     */
    public static function render_rules():    void { self::tab('rules','⚖️ Org Rules','Save Org Rules','The organization-specific rules the request page uses: library systems, couriers, which catalog each location uses, how holdings match a library, and which item types each library loans. These replace the switch statements in the original <code>cdlc_function.php</code> / <code>seal_function.php</code>. Every change is logged under <strong>Change history</strong> below.','info', [ 'SEAL_Admin', 'render_rules_after' ]); }
    /**
     * Settings screen: prints the Cron Jobs tab (fields registered in reg_cron()) through tab(). Followed by
     * render_cron_after().
     */
    public static function render_cron():     void { self::tab('cron','⏰ Cron Jobs','Save Cron Jobs','','info', [ 'SEAL_Admin', 'render_cron_after' ]); }
    /** Settings screen: prints the Modules tab (fields registered in reg_modules()) through tab(). */
    public static function render_modules():  void { self::tab('modules','🧩 Modules','Save Modules'); }

    /**
     * Shared frame for a settings screen: heading, optional notice, the options.php form with its sections and
     * Save button, and an optional $after callback for extra content below the form.
     */
    private static function tab(string $tab,string $title,string $btn,string $notice='',string $notice_type='info', ?callable $after=null): void {
        if (!current_user_can('manage_options')) { return; }
        $sys = esc_html(seal_config('system_name'));
        ?>
        <div class="wrap">
            <h1><?php echo $sys; ?> ILL — <?php echo wp_kses_post($title); ?></h1>
            <?php if ($notice): ?>
            <div class="notice notice-<?php echo esc_attr($notice_type); ?>" style="max-width:680px;">
                <p><?php echo wp_kses($notice,['code'=>[],'em'=>[],'strong'=>[]]); ?></p>
            </div>
            <?php endif; ?>
            <form action="options.php" method="post" style="max-width:<?php echo in_array( $tab, [ 'rules', 'cron' ], true ) ? '1120' : ( $tab === 'subjects' ? '960' : '740' ); ?>px;">
                <?php settings_fields(self::GROUPS[$tab]); do_settings_sections(self::PAGES[$tab]); submit_button($btn); ?>
            </form>
            <?php if ( $after ) { $after(); } ?>
        </div>
        <?php
    }

    /**
     * Routes screen: every ILL page address with the role it needs, and a warning when permalinks are Plain.
     */
    public static function render_routes(): void {
        if (!current_user_can('manage_options')) { return; }
        $sys = esc_html(seal_config('system_name'));
        $role_map = ['seal_request'=>'libstaff','seal_respond'=>'libstaff','seal_status'=>'libstaff',
            'seal_renew'=>'libstaff','seal_view_own'=>'libstaff','seal_bulkaction'=>'libstaff',
            'seal_view_system'=>'libsys','seal_adminlib_ls'=>'libsys','seal_stats_system'=>'libsys',
            'seal_admin'=>'administrator','seal_export'=>'administrator',
            'seal_sealstats'=>'administrator','seal_systemstats'=>'administrator','seal_email_harvest'=>'administrator'];
        $colors = ['administrator'=>'#c0392b','libsys'=>'#d68910','libstaff'=>'#196f3d'];
        ?>
        <div class="wrap">
            <h1>🗺️ <?php echo $sys; ?> ILL — Pages &amp; Routes</h1>
            <p>Virtual WP URLs handled by the plugin. No physical PHP files at these paths.</p>
            <?php if ( ! get_option( 'permalink_structure' ) ): ?>
            <div class="notice notice-warning inline" style="margin:0 0 12px;max-width:860px;"><p>
                Permalinks are set to <strong>Plain</strong>. These routes still work (the plugin
                matches the URL path directly), but enabling pretty permalinks under
                <a href="<?php echo esc_url( admin_url('options-permalink.php') ); ?>">Settings → Permalinks</a>
                (e.g. "Post name") is recommended for the rest of the site.
            </p></div>
            <?php endif; ?>
            <table class="wp-list-table widefat fixed striped" style="max-width:860px;">
                <thead><tr><th scope="col">URL</th><th scope="col">Handler</th><th scope="col">Required Capability</th><th scope="col">Min. Role</th></tr></thead>
                <tbody>
                <?php foreach (SEAL_Router::ROUTES as $slug => [$class,$cap]):
                    $role  = $role_map[$cap] ?? $cap;
                    $color = $colors[$role]  ?? '#555';
                ?>
                <tr>
                    <td><a href="<?php echo esc_url(home_url('/'.$slug)); ?>">/<?php echo esc_html($slug); ?></a></td>
                    <td><code><?php echo esc_html($class); ?></code></td>
                    <td><code><?php echo esc_html($cap); ?></code></td>
                    <td><span style="color:<?php echo esc_attr($color); ?>;font-weight:bold;"><?php echo esc_html($role); ?></span></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p style="margin-top:14px;">Routes broken? Visit <a href="<?php echo esc_url(admin_url('options-permalink.php')); ?>">Settings → Permalinks</a> and click Save.</p>
        </div>
        <?php
    }

    // ── Field renderers ───────────────────────────────────────────────────────

    /** Text input for an option, showing the saved value or the seal_config() default. */
    public static function field_text( array $a ): void {
        $key = str_replace('seal_','',$a['name']);
        $v   = get_option($a['name'], seal_config($key));
        echo '<input type="text" id="'.esc_attr($a['name']).'" name="'.esc_attr($a['name']).'" value="'.esc_attr($v).'" class="regular-text">';
        self::desc($a);
    }
    /** URL input for an option. */
    public static function field_url( array $a ): void {
        $key = str_replace('seal_','',$a['name']);
        $v   = get_option($a['name'], seal_config($key));
        echo '<input type="url" id="'.esc_attr($a['name']).'" name="'.esc_attr($a['name']).'" value="'.esc_attr($v).'" class="regular-text" placeholder="https://">';
        self::desc($a);
    }
    /** Wide text input for a comma-separated list of email addresses. */
    public static function field_emails( array $a ): void {
        $key = str_replace('seal_','',$a['name']);
        $v   = get_option($a['name'], seal_config($key));
        echo '<input type="text" id="'.esc_attr($a['name']).'" name="'.esc_attr($a['name']).'" value="'.esc_attr($v).'" class="large-text" placeholder="email@example.org, another@example.org">';
        self::desc($a);
    }
    /** Password input that is always blank; leaving it blank keeps the stored (encrypted) password. */
    public static function field_password( array $a ): void {
        echo '<input type="password" id="'.esc_attr($a['name']).'" name="'.esc_attr($a['name']).'" value="" class="regular-text" placeholder="Enter new password to change" autocomplete="new-password">';
        echo '<p class="description">Leave blank to keep current password. Stored encrypted.</p>';
    }
    /** Multi-line input for list-style options (one entry per line). */
    public static function field_textarea( array $a ): void {
        $key = str_replace('seal_','',$a['name']);
        $v   = get_option($a['name'], seal_config($key));
        echo '<textarea id="'.esc_attr($a['name']).'" name="'.esc_attr($a['name']).'" rows="8" class="large-text code">'.esc_textarea($v).'</textarea>';
        self::desc($a);
        if (!empty($a['extra'])) {
            echo '<p class="description">'.nl2br(wp_kses($a['extra'],self::kses())).'</p>';
        }
    }
    /**
     * One email on the Email Subjects & Text screen: a collapsible box with its subject (if it has its own) and its
     * heading / message / closing (if it has editable text). The summary shows the subject and whether anything
     * was changed from the defaults.
     */
    public static function field_email( array $a ): void {
        $key  = $a['key'];
        $subj = SEAL_Emails::all()[ $key ] ?? null;
        $text = SEAL_Emails::texts()[ $key ] ?? null;
        $help = SEAL_Emails::token_help();
        $tok  = static fn( array $t ) => implode( ' ', array_map( static fn( $k ) => '<code title="' . esc_attr( $help[ $k ] ?? '' ) . '">{' . esc_html( $k ) . '}</code>', array_merge( [ 'system' ], $t ) ) );

        $opts = $subj ? [ SEAL_Emails::OPT . $key ] : [];
        if ( $text ) { foreach ( array_keys( SEAL_Emails::PARTS ) as $part ) { $opts[] = SEAL_Emails::TEXT_OPT . $key . '_' . $part; } }
        $changed = (bool) array_filter( $opts, static fn( $o ) => trim( (string) get_option( $o, '' ) ) !== '' );
        $shown   = $subj ? ( trim( (string) get_option( SEAL_Emails::OPT . $key, '' ) ) ?: $subj[2] ) : 'Subject: same as “' . ( SEAL_Emails::all()[ $text[6] ][1] ?? $text[6] ) . '”';

        echo '<details class="seal-email-edit"' . ( $changed ? ' open' : '' ) . '><summary><span class="seal-email-subj">' . esc_html( $shown ) . '</span>'
           . ( $changed ? ' <span class="seal-email-changed">changed</span>' : '' ) . '</summary><div class="seal-email-fields">';
        if ( $subj ) {
            $id = SEAL_Emails::OPT . $key;
            echo '<label for="' . esc_attr( $id ) . '">Subject</label><input type="text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $id ) . '" value="'
               . esc_attr( (string) get_option( $id, '' ) ) . '" class="large-text" maxlength="200" placeholder="' . esc_attr( $subj[2] ) . '">'
               . '<p class="description">Can use: ' . $tok( $subj[3] ) . '</p>';
        }
        if ( $text ) {
            foreach ( SEAL_Emails::PARTS as $part => $label ) {
                $id  = SEAL_Emails::TEXT_OPT . $key . '_' . $part;
                $def = (string) $text[ [ 'heading' => 2, 'message' => 3, 'closing' => 4 ][ $part ] ];
                $val = (string) get_option( $id, '' );
                echo '<label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . ( $part === 'closing' ? ' <span class="description">(after the details)</span>' : '' ) . '</label>';
                if ( $part === 'heading' ) {
                    echo '<input type="text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $id ) . '" value="' . esc_attr( $val ) . '" class="large-text" maxlength="200" placeholder="' . esc_attr( $def ) . '">';
                } else {
                    echo '<textarea id="' . esc_attr( $id ) . '" name="' . esc_attr( $id ) . '" rows="' . ( $part === 'message' ? 3 : 2 ) . '" class="large-text" maxlength="2000" placeholder="'
                       . esc_attr( $def !== '' ? $def : '(none)' ) . '">' . esc_textarea( $val ) . '</textarea>';
                }
            }
            echo '<p class="description">Can use: ' . $tok( $text[5] ) . '. The details table and buttons are added automatically between the message and the closing.</p>';
        }
        echo '<p><button type="button" class="button button-small seal-email-fill">Copy the default text into the boxes</button> <span class="description">then change what you need. Clear a box to go back to the default.</span></p>';
        echo '</div></details>';
    }
    /** Dropdown for an option; $a['options'] holds value => label pairs. */
    public static function field_select( array $a ): void {
        $key = str_replace( 'seal_', '', $a['name'] );
        $cur = seal_config( $key );
        echo '<select id="' . esc_attr( $a['name'] ) . '" name="' . esc_attr( $a['name'] ) . '">';
        foreach ( (array) $a['options'] as $v => $label ) {
            echo '<option value="' . esc_attr( (string) $v ) . '"' . selected( $cur, (string) $v, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select>';
        self::desc( $a );
    }

    /** On/off checkbox for an option, checked from the saved value or its default. */
    public static function field_toggle( array $a ): void {
        $v = seal_config( str_replace('seal_','',$a['name']) );
        echo '<label><input type="checkbox" id="'.esc_attr($a['name']).'" name="'.esc_attr($a['name']).'" value="1" '.checked('1',$v,false).'> Enabled</label>';
        self::desc($a);
    }

    /** Prints a field's help text, allowing only basic tags (code, em, strong, br, a). */
    private static function desc( array $a ): void {
        if (!empty($a['desc'])) {
            echo '<p class="description">'.wp_kses($a['desc'],self::kses()).'</p>';
        }
    }
    /** The tags allowed in field help text. */
    private static function kses(): array {
        return ['code'=>[],'em'=>[],'strong'=>[],'br'=>[],'a'=>['href'=>[],'target'=>[]]];
    }

    // ── Sanitizers ────────────────────────────────────────────────────────────

    /**
     * Sanitize a toggle/checkbox value.
     * Checkboxes don't POST when unchecked; WP's settings API calls the
     * sanitize callback with an empty string in that case. We convert both
     * '' and anything not '1' to '0' so the stored value is always explicit.
     */
    public static function san_brand_text_color( $val, $option = '' ): string {
        $opt = current_filter() ? str_replace( 'sanitize_option_seal_', '', current_filter() ) : '';
        $key = str_replace( 'brand_', '', $opt );
        $defaults = [ 'primary' => '#4a4180', 'dark' => '#1d1f40' ];
        $hex = sanitize_hex_color( (string) $val );
        if ( ! $hex ) {
            add_settings_error( 'seal_' . $opt, 'seal_brand_invalid', 'Brand color must be a hex value like #4a4180. The default was kept.' );
            return $defaults[ $key ] ?? '#4a4180';
        }
        $ratio = SEAL_Functions::contrast_ratio( $hex, '#ffffff' );
        if ( $ratio < 4.5 ) {
            add_settings_error( 'seal_' . $opt, 'seal_brand_contrast', sprintf( '%s has only %.1f:1 contrast with white; WCAG 2.1 AA needs 4.5:1. The default was kept.', $hex, $ratio ) );
            return $defaults[ $key ] ?? '#4a4180';
        }
        return strtolower( $hex );
    }

    /**
     * Sanitizes the accent color (#rrggbb, lower-case; default #3fbb86). It sits behind dark text, so it isn't
     * held to the white-text contrast rule.
     */
    public static function san_brand_accent( $val ): string {
        $hex = sanitize_hex_color( (string) $val );
        return $hex ? strtolower( $hex ) : '#3fbb86';
    }

    /** Sanitizes an Org Rules list: multi-line text with Unix line endings, trimmed. */
    public static function san_rules( $val ): string {
        return str_replace( "\r\n", "\n", trim( sanitize_textarea_field( (string) $val ) ) );
    }

    /**
     * One Org Rules list: the row editor (built by seal-rules-editor.js from the list's schema) over a plain
     * textarea that still works without JavaScript, plus the list's help panel.
     */
    public static function field_rules( array $a ): void {
        $key    = str_replace( 'seal_rules_', '', $a['name'] );
        $schema = SEAL_Org_Rules::schema( $key );
        echo '<div class="seal-rules" id="seal-rules-' . esc_attr( $key ) . '" data-schema="' . esc_attr( wp_json_encode( $schema ) ) . '">';
        echo '<p class="description seal-rules-hint">' . esc_html( $schema['hint'] ) . '</p>';
        self::render_help( $key, $schema );
        echo '<textarea id="' . esc_attr( $a['name'] ) . '" name="' . esc_attr( $a['name'] ) . '" rows="8" class="large-text code" spellcheck="false" aria-label="' . esc_attr( $schema['title'] . ' as text' ) . '">'
           . esc_textarea( SEAL_Org_Rules::text( $key ) ) . '</textarea>';
        echo '</div>';
    }

    /** The collapsible "How the request page uses these rules" steps at the top of Org Rules. */
    public static function rules_flow_html(): string {
        $out = '<details class="seal-rules-help"><summary>How the request page uses these rules</summary><ol>';
        foreach ( SEAL_Org_Rules_Help::flow() as $step ) { $out .= '<li>' . esc_html( $step ) . '</li>'; }
        return $out . '</ol></details>';
    }

    /** "When to use this, with examples" panel under an editor. */
    private static function render_help( string $key, array $schema ): void {
        $h = SEAL_Org_Rules_Help::for_key( $key );
        if ( ! $h['what'] ) { return; }
        $labels = array_map( static fn( $c ) => $c['label'], $schema['cols'] );
        echo '<details class="seal-rules-help"><summary>When to use this, with examples</summary>';
        echo '<p>' . esc_html( $h['what'] ) . '</p>';
        if ( $h['when'] ) {
            echo '<p><strong>Use it when</strong></p><ul>';
            foreach ( $h['when'] as $w ) { echo '<li>' . esc_html( $w ) . '</li>'; }
            echo '</ul>';
        }
        foreach ( $h['examples'] as $ex ) {
            echo '<div class="seal-rules-example"><p><strong>' . esc_html( $ex['title'] ) . '</strong></p>';
            echo '<div class="seal-rules-example-scroll"><table><thead><tr>';
            foreach ( $labels as $i => $l ) { if ( isset( $ex['cells'][ $i ] ) && $ex['cells'][ $i ] !== '' ) { echo '<th scope="col">' . esc_html( $l ) . '</th>'; } }
            echo '</tr></thead><tbody><tr>';
            foreach ( $labels as $i => $l ) { if ( isset( $ex['cells'][ $i ] ) && $ex['cells'][ $i ] !== '' ) { echo '<td>' . esc_html( $ex['cells'][ $i ] ) . '</td>'; } }
            echo '</tr></tbody></table></div><p>' . esc_html( $ex['text'] ) . '</p></div>';
        }
        if ( $h['notes'] ) {
            echo '<p><strong>Good to know</strong></p><ul>';
            foreach ( $h['notes'] as $n ) { echo '<li>' . esc_html( $n ) . '</li>'; }
            echo '</ul>';
        }
        echo '</details>';
    }

    /**
     * Sanitizes a Cron Jobs path (scripts folder, PHP program, log file). A value equal to the default, or to an
     * older built-in default, is stored as blank so the setting keeps following the default.
     */
    public static function san_cron_path( $val ): string {
        $val = SEAL_Org_Rules::clean_path( (string) $val );
        $key = str_replace( 'sanitize_option_seal_cron_', '', current_filter() );
        $old = [ 'php' => [], 'log' => [ '/var/log/ill-cron.log' ], 'dir' => [ '/var/www/ill_scripts' ] ];   // earlier built-in defaults
        return ( $val === SEAL_Org_Rules::cron_default( $key ) || in_array( $val, $old[ $key ] ?? [], true ) ) ? '' : $val;   // matches a default: keep following it
    }

    /** Short code: lower-case letters and digits. Blank, or the same as the Org Short Name's code, follows the Org Short Name. */
    public static function san_cron_code( $val ): string {
        $val = trim( (string) $val );
        if ( $val === '' ) { return ''; }
        $code = SEAL_Org_Rules::code_from( $val );
        return $code === SEAL_Org_Rules::cron_default( 'code' ) ? '' : $code;
    }

    /** Cron job output: 'log' (append to the log file) or '' (discard). */
    public static function san_cron_output( $val ): string {
        return (string) $val === 'log' ? 'log' : '';
    }

    /**
     * The full calendar the cron jobs will use for the next 12 months: public holidays fetched
     * from Nager.Date (same source and filter as the cron jobs), Winter Break, and the Closure
     * Dates list above. Weekends are left out; they are always non-business days.
     */
    public static function field_holidays(): void {
        $cal  = SEAL_Org_Rules::upcoming_closures( 365 );
        $rows = $cal['days'];
        $own  = count( SEAL_Org_Rules::closures() );

        echo '<p class="description">Weekday closures for the next 12 months, combining US federal holidays (fetched from date.nager.at, '
           . esc_html( SEAL_Org_Rules::HOLIDAY_REGION ) . ' state holidays included), Winter Break (Dec 24 &ndash; Jan 1) and the '
           . (int) $own . ' date' . ( $own === 1 ? '' : 's' ) . ' in your list above. The cron jobs build the same calendar each time they run.</p>';

        if ( $cal['fetch_failed'] ) {
            echo '<div class="notice notice-warning inline" style="margin:8px 0 12px;"><p>Couldn\'t reach date.nager.at from this web server, so federal holidays are missing from this preview (it tries again within an hour). '
               . 'The cron jobs fetch the list themselves and keep a cached copy, so they are not affected by this message.</p></div>';
        }
        if ( ! $rows ) { echo '<p><em>No weekday closures found in the next 12 months.</em></p>'; return; }

        echo '<div class="seal-table-wrap"><table class="widefat striped" style="max-width:640px"><thead><tr>'
           . '<th scope="col">Date</th><th scope="col">Day</th><th scope="col">Closed for</th><th scope="col">Source</th></tr></thead><tbody>';
        foreach ( $rows as $d => $r ) {
            echo '<tr><td>' . esc_html( $d ) . '</td><td>' . esc_html( wp_date( 'D', strtotime( $d . ' 12:00:00' ) ) ) . '</td><td>'
               . esc_html( $r['name'] ) . '</td><td>' . esc_html( $r['source'] ) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    /** Text input for a Cron Jobs setting, showing the current value or default. */
    public static function field_cronset( array $a ): void {
        $key = str_replace( 'seal_cron_', '', $a['name'] );
        echo '<input type="text" class="regular-text code" id="' . esc_attr( $a['name'] ) . '" name="' . esc_attr( $a['name'] ) . '" value="' . esc_attr( SEAL_Org_Rules::cron_setting( $key ) ) . '" autocomplete="off" spellcheck="false">';
        self::desc( $a );
    }

    /** Dropdown: discard job output, or append it to the log file. */
    public static function field_cronoutput( array $a ): void {
        $cur = SEAL_Org_Rules::cron_setting( 'output' );
        echo '<select id="seal_cron_output" name="seal_cron_output">'
           . '<option value=""' . selected( $cur, 'discard', false ) . '>Discard (the original behavior; a failing job is silent)</option>'
           . '<option value="log"' . selected( $cur, 'log', false ) . '>Append to a log file (recommended)</option></select>';
    }

    /**
     * The finished crontab block, rebuilt live in the browser as the list and settings change, with a copy
     * button.
     */
    public static function field_cronpaste( array $a ): void {
        $defaults = [ 'code' => SEAL_Org_Rules::cron_code(), 'php' => SEAL_Org_Rules::cron_setting( 'php' ), 'dir' => SEAL_Org_Rules::cron_setting( 'dir' ), 'output' => SEAL_Org_Rules::cron_setting( 'output' ), 'log' => SEAL_Org_Rules::cron_setting( 'log' ),
                      'title' => seal_config( 'system_name' ) . ' (' . seal_config( 'org_name' ) . ')' ];
        echo '<div class="seal-cron-box" data-list="seal_rules_crons" data-code="seal_cron_code" data-php="seal_cron_php" data-dir="seal_cron_dir" data-output="seal_cron_output" data-log="seal_cron_log" data-defaults="' . esc_attr( wp_json_encode( $defaults ) ) . '">';
        echo '<textarea class="seal-cron-text" readonly rows="20" spellcheck="false" aria-label="Crontab lines to copy">' . esc_textarea( SEAL_Org_Rules::crontab_text() ) . '</textarea>';
        echo '<p><button type="button" class="button button-primary seal-cron-copy">Copy to clipboard</button><span class="seal-cron-status" role="status"></span></p>';
        echo '<p class="description">Built from the list and settings above; it updates as you edit them (save to keep your changes). Install it on the server with <code>crontab -e</code>, then check with <code>crontab -l</code>. Rows marked <code>manual</code> are listed as comments, not scheduled.</p>';
        echo '</div>';
    }

    /** Everything on this page as one JSON document (for moving rules to another site). */
    public static function rules_export(): array {
        $lists = [];
        foreach ( SEAL_Org_Rules::KEYS as $k ) { $lists[ $k ] = SEAL_Org_Rules::text( $k ); }
        return [
            'format'   => 'seal-org-rules',
            'version'  => 1,
            'exported' => gmdate( 'c' ),
            'site'     => home_url( '/' ),
            'lists'    => $lists,
            'cron'     => [ 'code' => (string) get_option( 'seal_cron_code', '' ), 'dir' => SEAL_Org_Rules::cron_setting( 'dir' ), 'php' => SEAL_Org_Rules::cron_setting( 'php' ), 'output' => SEAL_Org_Rules::cron_setting( 'output' ), 'log' => SEAL_Org_Rules::cron_setting( 'log' ) ],
        ];
    }

    /** Under the Cron Jobs form: where changes and the export live (shared with Org Rules). */
    public static function render_cron_after(): void {
        $u = admin_url( 'admin.php?page=' . self::PAGES['rules'] );
        echo '<p class="description" style="max-width:1120px">Changes to this screen are recorded under <a href="' . esc_url( $u . '#rules-history' ) . '">Org Rules &rarr; Change history</a>, '
           . 'and <a href="' . esc_url( $u . '#seal-rules-export' ) . '">Org Rules &rarr; Move these rules to another site</a> includes the Scheduled Jobs list and these settings.</p>';
    }

    /** Export box, import form and the change history (printed under the Org Rules form). */
    public static function render_rules_after(): void {
        if ( isset( $_GET['seal_rules_imported'] ) ) {
            echo '<div class="notice notice-success inline"><p>Imported ' . (int) $_GET['seal_rules_imported'] . ' list(s). Each change is recorded under Change history.</p></div>';
        }
        if ( isset( $_GET['seal_rules_import_error'] ) ) {
            echo '<div class="notice notice-error inline"><p>The pasted text is not a valid Org Rules export, so nothing was changed.</p></div>';
        }
        echo '<h2 id="rules-move">Move these rules to another site</h2>';
        echo '<p class="description">Copy the text below and paste it into the same box on the other site (for example from the test site to the live site). Importing replaces the lists in the pasted text; each change is logged.</p>';
        echo '<p><label for="seal-rules-export"><strong>Export (copy this)</strong></label></p>';
        echo '<textarea id="seal-rules-export" class="seal-export-text" readonly rows="8" spellcheck="false">' . esc_textarea( wp_json_encode( self::rules_export(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) . '</textarea>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="max-width:1040px;margin-top:14px">';
        echo '<input type="hidden" name="action" value="seal_rules_import">';
        wp_nonce_field( 'seal_rules_import', 'seal_rules_nonce' );
        echo '<p><label for="seal-rules-import"><strong>Import (paste here)</strong></label></p>';
        echo '<textarea id="seal-rules-import" name="seal_rules_json" class="large-text code" rows="6" spellcheck="false"></textarea>';
        echo '<p><label><input type="checkbox" name="seal_rules_confirm" value="1" required> I understand this replaces the lists included in the pasted text.</label></p>';
        submit_button( 'Import rules', 'secondary', 'submit', false );
        echo '</form>';
        SEAL_Rules_Log::render();
    }

    /**
     * Imports Org Rules pasted from another site (admin-post handler). Only the lists in the pasted text are
     * replaced, the confirmation box must be ticked, and each change is logged.
     */
    public static function handle_import(): void {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Not allowed.', 403 ); }
        check_admin_referer( 'seal_rules_import', 'seal_rules_nonce' );
        $back = admin_url( 'admin.php?page=' . self::PAGES['rules'] );
        $data = json_decode( wp_unslash( (string) ( $_POST['seal_rules_json'] ?? '' ) ), true );
        if ( ! is_array( $data ) || ( $data['format'] ?? '' ) !== 'seal-org-rules' || ! isset( $data['lists'] ) || ! is_array( $data['lists'] ) || empty( $_POST['seal_rules_confirm'] ) ) {
            wp_safe_redirect( add_query_arg( 'seal_rules_import_error', 1, $back ) ); exit;
        }
        $n = 0;
        foreach ( SEAL_Org_Rules::KEYS as $k ) {
            if ( ! array_key_exists( $k, $data['lists'] ) || ! is_string( $data['lists'][ $k ] ) ) { continue; }
            update_option( 'seal_rules_' . $k, self::san_rules( $data['lists'][ $k ] ), false );
            $n++;
        }
        $c = $data['cron'] ?? [];
        if ( is_array( $c ) ) {
            foreach ( [ 'dir', 'php', 'log' ] as $ck ) {
                if ( isset( $c[ $ck ] ) && is_string( $c[ $ck ] ) ) { update_option( 'seal_cron_' . $ck, SEAL_Org_Rules::clean_path( $c[ $ck ] ), false ); }
            }
            // The short code belongs to the site: only taken when the export set one on purpose.
            if ( isset( $c['code'] ) && is_string( $c['code'] ) && trim( $c['code'] ) !== '' ) { update_option( 'seal_cron_code', self::san_cron_code( $c['code'] ), false ); }
            if ( isset( $c['output'] ) ) { update_option( 'seal_cron_output', $c['output'] === 'log' ? 'log' : '', false ); }
        }
        wp_safe_redirect( add_query_arg( 'seal_rules_imported', $n, $back ) ); exit;
    }

    /** Loads the row editor's script and styles on the Org Rules and Cron Jobs screens only. */
    public static function enqueue_rules_assets(): void {
        if ( ! isset( $_GET['page'] ) || ! in_array( sanitize_key( wp_unslash( $_GET['page'] ) ), [ self::PAGES['rules'], self::PAGES['cron'] ], true ) ) { return; }
        wp_enqueue_style(  'seal-rules-editor', SEAL_ILL_PLUGIN_URL . 'assets/seal-rules-editor.css', [], SEAL_ILL_VERSION );
        wp_enqueue_script( 'seal-rules-editor', SEAL_ILL_PLUGIN_URL . 'assets/js/seal-rules-editor.js', [], SEAL_ILL_VERSION, true );
    }

    /** Stores a toggle as '1' or '0' (an unchecked box would otherwise save nothing). */
    public static function san_toggle( $val ): string {
        return ( $val === '1' || $val === 1 || $val === true ) ? '1' : '0';
    }

    /** Rota chain length: at least 1, hard-capped at 3 (policy decision, not just a default). */
    public static function san_rota_max( $val ): string {
        return (string) max( 1, min( 3, absint( $val ) ) );
    }

    /** Keeps only valid addresses from a comma-separated list. */
    public static function san_emails( string $raw ): string {
        $valid = [];
        foreach (array_map('trim',explode(',',$raw)) as $e) {
            $e = sanitize_email($e);
            if ($e && is_email($e)) { $valid[] = $e; }
        }
        return implode(', ',$valid);
    }
    /** Encrypts a newly entered ILL database password; a blank field keeps the stored one. */
    public static function san_db_pass( string $new ): string {
        if ($new === '') { return (string) get_option('seal_db_pass',''); }
        return SEAL_DB::encrypt_pass($new);
    }
}
