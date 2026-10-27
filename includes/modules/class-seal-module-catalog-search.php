<?php
/**
 * SEAL_Module_CatalogSearch
 *
 *   - senylrc-seal-search / senylrc-seal-search.php  (header search bar + logo)
 *   - ill_mkws / ill_mkws.php                         (MKWS JS loader)
 *   - ill_mkws / ill.js                               (form submit / ILL UI helpers)
 *   - ill_mkws / mkws-complete.js                     (Index Data MKWS vendor library)
 *
 * All previously hardcoded URLs (indexdata domain, search URL, logo URL,
 * help guide URL, advanced search URL) are now stored in seal_config() and
 * editable from ILL System → Search & Catalog Settings in the admin panel.
 *
 * The search category options are also configurable — admins enter them as
 * a newline-separated list of  value|Label  pairs in the admin, so a new
 * deployment can point to a completely different catalog partition scheme.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class SEAL_Module_CatalogSearch {

    /** Adds the catalog search bar to the top of every front-end page and loads the search scripts. */
    public static function init(): void {
        add_action( 'get_header',        [ __CLASS__, 'render_header_bar' ] );
        add_action( 'wp_enqueue_scripts',[ __CLASS__, 'enqueue_scripts'   ] );
    }

    // ── Script / style enqueueing ─────────────────────────────────────────────

    /** Loads the catalog search toolkit (MKWS) and the ILL page helpers on front-end pages. */
    public static function enqueue_scripts(): void {
        if ( is_admin() ) { return; }

        wp_enqueue_script(
            'seal-mkws-complete',
            SEAL_ILL_PLUGIN_URL . 'assets/js/mkws-complete.js',
            [],
            SEAL_ILL_VERSION,
            true
        );

        // ill.js is now output inline so the catalog search URL can be
        // injected from PHP/seal_config() at render time. See render_inline_js().
        add_action( 'wp_footer', [ __CLASS__, 'render_inline_js' ] );
    }

    // ── Inline JS (replaces ill.js with configurable URLs) ───────────────────

    /**
     * Prints the glue between the search form and the catalog toolkit: submitting a search, keeping the user's
     * choices, the catalog login check, and small show/hide helpers. All addresses come from ILL System →
     * Search & Catalog.
     */
    public static function render_inline_js(): void {
        $search_base   = rtrim( seal_config( 'catalog_search_url' ), '/' );
        $login_url     = seal_config( 'catalog_login_url'   ) ?: $search_base . '/login.html';
        $search_page   = seal_config( 'catalog_search_page' ) ?: $search_base . '/search.html';
        ?>
        <script id="seal-ill-js">
        /* ILL — ill.js (inline, URLs injected from admin settings) */
        var sealSearchPage = <?php echo wp_json_encode( $search_page ); ?>;

        /** Sends the search to the results page with the chosen category filter. */
        function formSubmit() {
            var catFilter = "";
            if (document.search && document.search.category_filter) {
                catFilter = "&categoryfilter=" + encodeURIComponent(document.search.category_filter.value);
            }
            if (document.search && document.search.query.value) {
                document.location = sealSearchPage + "?" +
                    "query=" + encodeURIComponent(document.search.query.value) +
                    catFilter + "&sort=&perpage=&torusquery=";
            }
        }

        /** Keeps the user's search choices (categories, limits) for the next page. */
        function saveUserSelections() {
            var setts = {};
            if (typeof controlRegister !== 'undefined') {
                controlRegister.populateArray(setts);
                saveSettings(setts);
            }
        }

        /** Starts a catalog session; if it can't, sends the user to the catalog login page. */
        function auth_check(methods) {
            if (typeof auth !== 'undefined') {
                auth.check(indexMain, function() {
                    window.location = <?php echo wp_json_encode( $login_url ); ?>;
                }, methods);
            }
        }

        /**
         * Shows the search page once the catalog session is ready, using the catalog's own stylesheet when it
         * supplies one.
         */
        function indexMain() {
            if (typeof mk_showPage === 'function') mk_showPage();
            if (typeof auth !== 'undefined' && auth.indexCss && document.getElementById("stylesheet")) {
                document.getElementById("stylesheet").href = auth.indexCss;
            }
            if (typeof loadComponents === 'function') loadComponents();
            if (typeof renderComponent === 'function') {
                renderComponent("authInfoComp", auth);
                renderComponent("authLogoComp", auth);
            }
            if (typeof controlRegister !== 'undefined') controlRegister.loadControls();
            if (typeof loadSettings === 'function') {
                loadSettings(function(setts) {
                    if (typeof controlRegister !== 'undefined') controlRegister.populateControls(setts);
                });
            }
            if (document.search) document.search.query.focus();
        }

        /* ILL article request toggle */
        function yesnoCheck() {
            var el = document.getElementById('ifYes');
            if (el) el.style.display = document.getElementById('yesCheck') && document.getElementById('yesCheck').checked ? 'block' : 'none';
        }
        /** Older name for yesnoCheck(), kept for pages that still call it. */
        function yesnoCheck2() { yesnoCheck(); }

        /* ILL directory show/hide */
        function showHide(elem) {
            var x = document.getElementById('showhide-' + elem);
            if (x) x.style.display = (x.style.display === 'none') ? 'block' : 'none';
        }

        /* Multi-copy request toggle */
        function multiRequest() {
            var checks = document.getElementsByClassName("librarycheck");
            for (var i = 0; i < checks.length; i++) checks[i].checked = false;
            var isMulti = document.getElementById('multiCheck') && document.getElementById('multiCheck').checked;
            var multi  = document.getElementsByClassName("multiplereq");
            var single = document.getElementsByClassName("singlereq");
            for (var i = 0; i < multi.length;  i++) multi[i].style.display  = isMulti ? 'block' : 'none';
            for (var i = 0; i < single.length; i++) single[i].style.display = isMulti ? 'none'  : 'block';
        }

        /** Warns when a multi-library request is submitted with no library ticked. */
        function failcheck() {
            var boxes = document.getElementsByClassName('librarycheck');
            var ok = false;
            for (var i = 0; i < boxes.length; i++) { if (boxes[i].checked) { ok = true; break; } }
            if (!ok) alert('Please select at least one library!');
        }
        </script>
        <?php
    }

    // ── The search form itself ────────────────────────────────────────────────
    // Shared by the header bar and the homepage [ill_search] block. Only one
    // instance per page is rendered (the form uses fixed id/name attributes
    // that the catalog JS depends on).

    private static bool $form_rendered = false;

    /**
     * The search form (box, category dropdown, Advanced Search and Help links). Shared by the header bar and
     * the [ill_search] block, and printed at most once per page because it uses fixed ids.
     */
    public static function render_search_form(): void {
        if ( self::$form_rendered ) { return; }
        self::$form_rendered = true;

        $sys          = seal_config( 'system_name' );
        $adv_search   = seal_config( 'catalog_advanced_url' );
        $help_url     = seal_config( 'catalog_help_url'   );
        $cats_js_url  = seal_config( 'catalog_categories_js_url' );
        $cat_options  = self::build_category_options();
        ?>
        <form role="search" aria-label="<?php echo esc_attr( 'Search the ' . $sys . ' Union Catalog' ); ?>"
              onsubmit="formSubmit();return false;"
              onchange="saveUserSelections();"
              id="search" name="search">

            <div class="seal-query-row">
                <label for="query" class="seal-sr-only"><?php echo esc_html( 'Search ' . $sys . ' Union Catalog' ); ?></label>
                <input type="text" id="query" name="query" class="seal-input"
                       aria-label="<?php echo esc_attr( 'Search ' . $sys . ' Union Catalog' ); ?>"
                       autocomplete="off" inputmode="search">
            </div>

            <div class="seal-controls-row" aria-label="Search filters">
                <label for="category_filter" class="seal-inline-label">Select category:</label>
                <select name="category_filter" id="category_filter" class="seal-select"
                        title="Select library-type category">
                    <?php echo $cat_options; ?>
                </select>
                <input type="submit" value="SEARCH" id="submit" name="submit" class="seal-btn">
            </div>

            <div class="seal-links-row">
                <?php if ( $adv_search ): ?>
                <span class="seal-or" aria-hidden="true">or</span>
                <a class="seal-link" href="<?php echo esc_url( $adv_search ); ?>">Advanced Search</a>
                <?php endif; ?>
                <?php if ( $help_url ): ?>
                <span class="seal-sep" aria-hidden="true"> – </span>
                <a class="seal-link" target="_blank" rel="noopener noreferrer"
                   href="<?php echo esc_url( $help_url ); ?>">
                    Need help? <span class="seal-sr-only">(opens in a new tab)</span>
                </a>
                <?php endif; ?>
            </div>

            <?php if ( $cats_js_url ): ?>
            <div id="categoriesSelect">
                <script src="<?php echo esc_url( $cats_js_url ); ?>"></script>
                <script>
                if (typeof CategorySelectList !== 'undefined') {
                    var cats = new CategorySelectList("search","category_filter","categoriesComp","categoryfilter");
                    controlRegister.addControl(cats);
                }
                </script>
                <span id="categoriesComp" class="componentTemplate"></span>
            </div>
            <?php endif; ?>

        </form>
        <?php
    }

    // ── Homepage search block ─────────────────────────────────────────────────
    // Replaces the raw PHP/HTML search block previously pasted into the home
    // page. Place the [ill_search] shortcode where the old block was; every
    // URL, label, and category comes from ILL → Search & Catalog settings.

    public static function render_home_block(): string {
        ob_start();
        ?>
        <div class="seal-home-search">
            <div class="seal-home-search-inner">
                <?php self::render_search_form(); ?>
            </div>
        </div>
        <?php
        self::inline_css();
        return ob_get_clean();
    }

    // ── Header search bar ─────────────────────────────────────────────────────

    /** The bar at the top of each page: logo or system name, and the search form. */
    public static function render_header_bar(): void {
        if ( is_admin() ) { return; }

        $sys          = seal_config( 'system_name' );
        $logo_url     = seal_config( 'header_logo_url'    );
        $logo_alt     = seal_config( 'header_logo_alt'    ) ?: $sys . ' logo';
        $show_search  = ! is_front_page() && ! is_home();
        ?>
        <div class="seal-search-header" aria-label="<?php echo esc_attr( $sys ); ?> header search and staff access">

            <!-- Logo -->
            <div class="seal-search-left">
                <a href="<?php echo esc_url( home_url( '/' ) ); ?>"
                   aria-label="<?php echo esc_attr( 'Go to ' . $sys . ' home' ); ?>">
                    <?php if ( $logo_url ): ?>
                    <img src="<?php echo esc_url( $logo_url ); ?>"
                         alt="<?php echo esc_attr( $logo_alt ); ?>"
                         width="125" height="150" loading="eager" decoding="async">
                    <?php else: ?>
                    <span style="font-size:1.4em;font-weight:bold;"><?php echo esc_html( $sys ); ?></span>
                    <?php endif; ?>
                </a>
            </div>

            <!-- Search form (hidden on homepage — the homepage uses the
                 [ill_search] block rendered from the same settings) -->
            <div class="seal-search-center">
                <?php if ( $show_search ): ?>
                <h2 class="seal-search-title" id="seal-search-title">Search the Catalog</h2>
                <?php self::render_search_form(); ?>
                <?php endif; ?>
            </div>

            <!-- Login / Logout -->
            <div class="seal-search-right">
                <?php if ( is_user_logged_in() ): ?>
                <a href="<?php echo esc_url( wp_logout_url( home_url() ) ); ?>" class="seal-btn">
                    Staff Logout
                </a>
                <?php else: ?>
                <a href="<?php echo esc_url( wp_login_url() ); ?>" class="seal-btn">
                    Staff Login
                </a>
                <?php endif; ?>
            </div>

        </div>
        <?php self::inline_css(); ?>
        <?php
    }

    // ── Category options ──────────────────────────────────────────────────────
    // Reads newline-separated  value|Label  pairs from seal_config('catalog_categories').
    // Falls back to the standard categories, named after the Org Short Name, if nothing is configured.

    private static function build_category_options(): string {
        $raw = seal_config( 'catalog_categories' );

        if ( $raw ) {
            $html = '';
            foreach ( array_filter( array_map( 'trim', explode( "\n", $raw ) ) ) as $line ) {
                // A leading * or a trailing |selected marks the default option.
                $selected = false;
                if ( str_starts_with( $line, '*' ) ) {
                    $selected = true;
                    $line     = ltrim( substr( $line, 1 ) );
                }
                $parts = array_map( 'trim', explode( '|', $line, 3 ) );
                if ( strtolower( $parts[2] ?? '' ) === 'selected' || strtolower( $parts[1] ?? '' ) === 'selected' ) {
                    $selected = true;
                    if ( strtolower( $parts[1] ?? '' ) === 'selected' ) { unset( $parts[1] ); $parts = array_values( $parts ); }
                }
                $val   = esc_attr( $parts[0] );
                $label = esc_html( $parts[1] ?? $parts[0] );
                $sel   = $selected ? ' selected' : '';
                $html .= "<option value=\"{$val}\"{$sel}>{$label}</option>\n";
            }
            return $html;
        }

        // Standard categories: category~<org short name>_participant etc. ("CDLC" -> cdlc_participant)
        $prefix = preg_replace( '/[^a-z0-9]/', '', strtolower( seal_config( 'org_name' ) ) ) ?: 'ill';
        return "
            <option value=\"all\">All</option>
            <option value=\"category~{$prefix}_participant\" selected>All Lenders</option>
            <option value=\"category~{$prefix}_academics\">Academic Library Lenders</option>
            <option value=\"category~{$prefix}_public\">Public Library Lenders</option>
            <option value=\"category~{$prefix}_school\">School Library Lenders</option>
            <option value=\"category~{$prefix}_special\">Special Library Lenders</option>
            <option value=\"category~{$prefix}_state\">State Library</option>
        ";
    }

    // ── Inline CSS ────────────────────────────────────────────────────────────

    private static bool $css_rendered = false;

    /** Styles for the search bar and form (printed once per page). */
    private static function inline_css(): void {
        if ( self::$css_rendered ) { return; }
        self::$css_rendered = true;
        ?>
        <style>
        .seal-home-search{text-align:center;margin:20px auto;max-width:800px;}
        .seal-home-search .seal-input{width:100%;min-width:0;max-width:640px;}
        .seal-home-search-inner{display:inline-block;width:100%;}
        .seal-search-header{display:flex;justify-content:space-between;align-items:flex-start;gap:20px;padding:10px 0;max-width:1200px;margin:0 auto;}
        .seal-search-left{flex:0 0 auto;}
        .seal-search-center{flex:1;text-align:center;line-height:1.25;}
        .seal-search-right{flex:0 0 auto;text-align:right;}
        .seal-search-left img{max-height:80px;width:auto;}
        .seal-search-title{margin:0 0 8px;font-size:1.25rem;}
        .seal-query-row{margin:0 0 8px;}
        .seal-controls-row{margin:0 0 6px;display:inline-flex;align-items:center;justify-content:center;gap:10px;}
        .seal-links-row{margin:0;}
        .seal-input{width:50%;min-width:340px;max-width:640px;padding:8px 10px;border:1px solid #767676;border-radius:4px;font-size:16px;}
        .seal-inline-label{margin:0;font-weight:400;white-space:nowrap;}
        .seal-select{width:200px;max-width:100%;padding:8px 10px;border:1px solid #767676;border-radius:4px;font-size:16px;min-height:40px;}
        .seal-btn,
        .seal-search-center input[type="submit"]{display:inline-block;background:var(--seal-brand,#4a4180);color:#fff;padding:8px 14px;text-decoration:none;border:none;border-radius:4px;font-size:14px;font-weight:700;cursor:pointer;transition:background .2s;}
        .seal-btn:hover,
        .seal-btn:focus,
        .seal-search-center input[type="submit"]:hover{background:var(--seal-brand-dark,#1d1f40);color:#fff;}
        .seal-link{text-decoration:underline;}
        .seal-or{margin-right:8px;}
        .seal-sep{margin:0 8px;}
        .seal-sr-only{position:absolute!important;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0;}
        @media(max-width:768px){
            .seal-search-header{flex-wrap:wrap;}
            .seal-input{width:100%;min-width:0;max-width:100%;}
            .seal-controls-row{display:flex;flex-wrap:wrap;gap:10px;}
            .seal-select{width:100%;}
        }
        .seal-btn:focus-visible,.seal-link:focus-visible,.seal-input:focus-visible,.seal-select:focus-visible{outline:3px solid var(--seal-brand,#4a4180);outline-offset:2px;}
        </style>
        <?php
    }
}
