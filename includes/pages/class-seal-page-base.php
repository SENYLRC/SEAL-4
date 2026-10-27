<?php
/**
 * SEAL_Page_Base — abstract base for all SEAL page handlers.
 *
 * Provides:
 *  - Shared assets (enqueue jQuery UI once via WP, not via hardcoded CDN tags)
 *  - A standard alert() helper
 *  - Table rendering helpers
 *  - Current-user shortcut properties
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

abstract class SEAL_Page_Base {

    /** @var WP_User */
    protected WP_User $user;

    /** @var array<string> */
    protected array $user_roles;

    /**
     * Captures the signed-in user and their roles for the page. Assets are enqueued by SEAL_Router, not here.
     */
    public function __construct() {
        $this->user       = wp_get_current_user();
        $this->user_roles = (array) $this->user->roles;

        // Note: asset enqueueing is handled by SEAL_Router::enqueue_assets(),
        // registered on wp_enqueue_scripts during the 'wp' hook. Enqueuing from
        // here would be too late — this class is instantiated during the_content,
        // after wp_enqueue_scripts has already fired.
    }

    // ── Required by subclasses ────────────────────────────────────────────────

    abstract public function render(): void;

    // ── Asset enqueueing ──────────────────────────────────────────────────────

    /** Loads the date picker and the plugin's stylesheets (called by SEAL_Router for ILL pages). */
    public function enqueue_assets(): void {
        // Date fields use the browser's own date picker (see datepicker_js), so no jQuery UI.
        wp_enqueue_style(
            'seal-style',
            SEAL_ILL_PLUGIN_URL . 'assets/seal-style.css',
            [],
            SEAL_ILL_VERSION
        );
    }

    // ── Output helpers ────────────────────────────────────────────────────────

    /** HTML-escapes a value for output (see SEAL_Functions::h()). */
    protected function h( $s ): string {
        return SEAL_Functions::h( $s );
    }

    /**
     * Prints a status message box (success | error | warning | info) with role="alert" so screen readers
     * announce it. The message may contain basic HTML; it is passed through wp_kses_post().
     */
    protected function alert( string $message, string $type = 'success' ): void {
        $types = [ 'success', 'error', 'warning', 'info' ];
        $type  = in_array( $type, $types, true ) ? $type : 'info';
        echo '<div class="seal-alert seal-alert-' . esc_attr( $type ) . '" role="alert">'
             . wp_kses_post( $message )
             . '</div>';
    }

    /**
     * Turn the matching text fields into the browser's own date picker (<input type="date">).
     * It submits YYYY-MM-DD, the same format the old jQuery UI picker used, so no server code changes;
     * it needs no library or stylesheet, and works with the keyboard and screen readers. A field whose
     * current value is not YYYY-MM-DD (e.g. an old MM/DD/YYYY suspension date) is left as text, because
     * a date field would show it blank and save it empty.
     */
    protected function datepicker_js( string $selector = '#datepicker' ): void {
        ?>
        <script>
        (function(){
            /**
             * Turns each matching empty or YYYY-MM-DD text field into a native date picker (type=date). Fields
             * holding another format are left as text so their value isn't lost.
             */
            function go(){
                document.querySelectorAll(<?php echo wp_json_encode( $selector ); ?>).forEach(function(el){
                    var v = String(el.value || '').trim();
                    if (v !== '' && !/^\d{4}-\d{2}-\d{2}$/.test(v)) { return; }
                    el.value = v; el.type = 'date'; el.classList.add('seal-date');
                    el.removeAttribute('placeholder');
                });
            }
            if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', go); } else { go(); }
        })();
        </script>
        <?php
    }

    /**
     * Item-type breakdowns for the borrowing / lending statistics pages (as SEAL-3's libstats.php):
     * an overall "Breakdown by Item Type", then each system with its item types and how each went.
     * One grouped query instead of SEAL-3's query per item type per status.
     *
     * @param mysqli $db
     * @param string $base     "FROM `STATS` WHERE ..." (already escaped by the caller)
     * @param string $sys_col  DestSystem (borrowing) or ReqSystem (lending)
     * @param string $heading  e.g. "Breakdown by Destination System"
     * @param string $prep     "to" or "from"
     */
    protected function stats_breakdown( mysqli $db, string $base, string $sys_col, string $heading, string $prep ): void {
        $statuses = [ '1' => 'filled', '0' => 'not filled', '3' => 'pending (no answer yet)', '4' => 'expired', '6' => 'cancelled' ];
        $q = $db->query( 'SELECT `' . str_replace( '`', '', $sys_col ) . '` AS bd_sys, TRIM(`itype`) AS bd_type, `Fill` AS bd_fill, COUNT(*) AS c ' . $base . ' GROUP BY bd_sys, bd_type, bd_fill' );
        if ( ! $q ) { return; }
        $tree = []; $types = [];   // $tree[sys]['n'], ['types'][itype]['n'|'fill'][f]; $types[itype]['n'|'fill'][f]
        while ( $r = $q->fetch_assoc() ) {
            $sys  = trim( (string) $r['bd_sys'] );
            $it   = trim( (string) $r['bd_type'] );
            $it   = $it === '' ? 'no item type' : strtolower( $it );
            $fill = trim( (string) $r['bd_fill'] );
            if ( $fill === '' ) { $fill = '3'; }                      // old rows: blank = no answer yet
            $n    = (int) $r['c'];
            $types[ $it ]['n'] = ( $types[ $it ]['n'] ?? 0 ) + $n;
            $types[ $it ]['fill'][ $fill ] = ( $types[ $it ]['fill'][ $fill ] ?? 0 ) + $n;
            if ( $sys === '' ) { continue; }
            $tree[ $sys ]['n'] = ( $tree[ $sys ]['n'] ?? 0 ) + $n;
            $tree[ $sys ]['types'][ $it ]['n'] = ( $tree[ $sys ]['types'][ $it ]['n'] ?? 0 ) + $n;
            $tree[ $sys ]['types'][ $it ]['fill'][ $fill ] = ( $tree[ $sys ]['types'][ $it ]['fill'][ $fill ] ?? 0 ) + $n;
        }
        $total = array_sum( array_column( $types, 'n' ) );
        $by_n  = static function ( array $a ): array { uasort( $a, static fn( $x, $y ) => $y['n'] <=> $x['n'] ); return $a; };
        $outcomes = function ( array $node ) use ( $statuses ): string {
            $li = '';
            foreach ( $statuses as $f => $label ) {
                $c = (int) ( $node['fill'][ $f ] ?? 0 );
                if ( $c === 0 ) { continue; }
                $li .= '<li>' . number_format( $c ) . ' (' . SEAL_Functions::pct2( $c, (int) $node['n'] ) . ') ' . esc_html( $label ) . '</li>';
            }
            return $li !== '' ? '<ul class="seal-stats-outcomes">' . $li . '</ul>' : '';
        };

        echo '<hr><h4>Breakdown by Item Type</h4><ul class="seal-stats-breakdown">';
        foreach ( $by_n( $types ) as $it => $node ) {
            echo '<li><strong>' . number_format( $node['n'] ) . ' (' . SEAL_Functions::pct2( $node['n'], $total ) . ')</strong> ' . esc_html( $it ) . $outcomes( $node ) . '</li>';
        }
        echo '</ul>';

        echo '<hr><h4>' . esc_html( $heading ) . '</h4>';
        if ( ! $tree ) { echo '<p><em>No system recorded on these requests.</em></p>'; return; }
        echo '<ul class="seal-stats-breakdown">';
        foreach ( $by_n( $tree ) as $sys => $snode ) {
            echo '<li><strong>' . number_format( $snode['n'] ) . ' (' . SEAL_Functions::pct2( $snode['n'], $total ) . ')</strong> ' . esc_html( $prep ) . ' <strong>' . esc_html( SEAL_Functions::system_name( $sys ) ) . '</strong>';
            echo '<ul>';
            foreach ( $by_n( $snode['types'] ) as $it => $node ) {
                echo '<li>' . number_format( $node['n'] ) . ' request' . ( $node['n'] === 1 ? '' : 's' ) . ' for <strong>' . esc_html( $it ) . '</strong>' . $outcomes( $node ) . '</li>';
            }
            echo '</ul></li>';
        }
        echo '</ul>';
    }

    /** Open a standard data table. */
    protected function table_open( array $headers ): void {
        echo '<div class="seal-table-wrap"><table class="seal-table">';
        echo '<thead><tr>';
        foreach ( $headers as $h ) {
            echo '<th scope="col">' . esc_html( $h ) . '</th>';
        }
        echo '</tr></thead><tbody>';
    }

    /** Closes the table and wrapper opened by table_open(). */
    protected function table_close(): void {
        echo '</tbody></table></div>';
    }

    // ── Form helpers ──────────────────────────────────────────────────────────

    /** Prints the hidden seal_nonce field for $action inside a form (pair with verify_nonce()). */
    protected function nonce_field( string $action = 'seal_form' ): void {
        SEAL_Functions::nonce_field( $action );
    }

    // ── Who may act on a request ──────────────────────────────────────────────

    /**
     * Load a request and check the current user's relationship to it.
     *
     * $side 'lender'   = the lending library (Destination) or an administrator
     * $side 'borrower' = the borrowing library (Requester LOC) or an administrator
     * $side 'either'   = either library or an administrator
     *
     * ILL numbers are sequential (YYYY-1234), so every page that reads or changes a request by its
     * number must call this first; a logged-in user must never be able to act on another library's
     * request just by typing its number. Returns the row, or null (after printing an error).
     */
    protected function authorize_request( string $ill, string $side, bool $quiet = false ): ?array {
        $ill = trim( $ill );
        $row = null;
        if ( $ill !== '' ) {
            $q   = SEAL_DB::query( "SELECT * FROM %t WHERE `illNUB`=%s LIMIT 1", SEAL_DB::$sealSTAT, $ill );
            $row = $q ? $q->fetch_assoc() : null;
        }
        $ok = false;
        if ( $row ) {
            if ( SEAL_Roles::is_admin() ) {
                $ok = true;
            } else {
                $locs = SEAL_Functions::all_locs( $this->user->ID );
                $dest = strtoupper( trim( (string) ( $row['Destination'] ?? '' ) ) );
                $req  = strtoupper( trim( (string) ( $row['Requester LOC'] ?? '' ) ) );
                $ok   = ( $side !== 'borrower' && in_array( $dest, $locs, true ) )
                     || ( $side !== 'lender'   && in_array( $req,  $locs, true ) );
            }
        }
        if ( ! $ok ) {
            if ( ! $quiet ) {
                $this->alert( 'That request was not found for your library, or you are not allowed to change it.', 'error' );
            }
            return null;
        }
        return $row;
    }

    // ── Stand-alone row forms (avoid nested <form>) ───────────────────────────

    /** @var string[] */
    private array $row_forms = [];
    private int   $row_form_seq = 0;

    /**
     * Queue a stand-alone POST form and return a submit button tied to it with the
     * HTML form="" attribute. <form> elements can't be nested (the browser closes the
     * outer form at the first inner </form>), so per-row actions inside a bulk-action
     * form print their forms with flush_row_forms() after the outer form ends.
     */
    protected function row_action( string $url, string $nonce_action, array $fields, string $label, string $class ): string {
        $id  = 'seal-rowform-' . ( ++$this->row_form_seq );
        $out = '<form id="' . esc_attr( $id ) . '" method="post" action="' . esc_url( $url ) . '" class="seal-row-form">'
             . '<input type="hidden" name="seal_nonce" value="' . esc_attr( wp_create_nonce( $nonce_action ) ) . '">';
        foreach ( $fields as $name => $value ) {
            $out .= '<input type="hidden" name="' . esc_attr( (string) $name ) . '" value="' . esc_attr( (string) $value ) . '">';
        }
        $this->row_forms[] = $out . '</form>';
        return '<button type="submit" form="' . esc_attr( $id ) . '" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button>';
    }

    /** "Print Slip" button: opens the printable pull/route slip for one or more ILL numbers in a new tab. */
    protected function slip_link( string $ill, string $class = 'seal-btn-secondary seal-row-btn' ): string {
        return '<a href="' . esc_url( add_query_arg( 'num', rawurlencode( $ill ), home_url( '/pullslip' ) ) ) . '" target="_blank" rel="noopener" class="' . esc_attr( $class ) . '">'
             . 'Print Slip<span class="seal-sr-only"> for ILL # ' . esc_html( $ill ) . ' (opens in a new tab)</span></a>';
    }

    /**
     * Prints the stand-alone forms queued by row_action(). Call once, after the page's outer form has closed.
     */
    protected function flush_row_forms(): void {
        echo implode( '', $this->row_forms ); // built from escaped parts in row_action()
        $this->row_forms = [];
    }

    /**
     * Stops the request with an error unless the posted seal_nonce matches $action (CSRF protection). See
     * SEAL_Functions::verify_nonce().
     */
    protected function verify_nonce( string $action = 'seal_form' ): void {
        SEAL_Functions::verify_nonce( $action );
    }

    // ── Input sanitisation shortcuts ──────────────────────────────────────────

    /** A sanitized $_POST value, or $default when it is missing. */
    protected function post_str( string $key, string $default = '' ): string {
        return isset( $_POST[ $key ] )
            ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) )
            : $default;
    }

    /** A sanitized $_GET value, or $default when it is missing. */
    protected function get_str( string $key, string $default = '' ): string {
        return isset( $_GET[ $key ] )
            ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) )
            : $default;
    }

    /** A sanitized $_REQUEST (GET or POST) value, or $default when it is missing. */
    protected function req_str( string $key, string $default = '' ): string {
        return isset( $_REQUEST[ $key ] )
            ? sanitize_text_field( wp_unslash( $_REQUEST[ $key ] ) )
            : $default;
    }

    /** An integer $_REQUEST value, or $default when it is missing. */
    protected function req_int( string $key, int $default = 0 ): int {
        return isset( $_REQUEST[ $key ] ) ? (int) $_REQUEST[ $key ] : $default;
    }

    // ── POST method check ─────────────────────────────────────────────────────

    /** True when the page was requested with POST. */
    protected function is_post(): bool {
        return $_SERVER['REQUEST_METHOD'] === 'POST';
    }
}
