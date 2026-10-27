<?php
/**
 * SEAL_Page_illiad_mapping — ILL Admin: manage the ILLiad address mapping table.
 *
 * Each row says: "when the borrowing library <LOC> sends a request to the lending library
 * <illiadID> (a library that runs ILLiad), file it under ILLiad address number <illiadADDnumb>
 * with library symbol <illiadLIBSymbol>." The request page and the ILLiad cron scripts read it.
 *
 * Library codes are picked from drop-downs that show library names. Names are matched in PHP, not
 * with a SQL JOIN, because the mapping table is often latin1 while the library table is utf8mb4.
 * Table name: ILL → Settings → Database Tables → ILLiad Mapping Table.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class SEAL_Page_illiad_mapping extends SEAL_Page_Base {

    private const PER_PAGE = 50;

    private string $tbl  = '';
    private string $self = '';
    /** @var array<string,array{loc:string,name:string,illiad:bool}> keyed by lower-case LOC */
    private array $libs = [];

    /**
     * Entry point: SEAL_Router calls this to print the page into the theme's content area. Lists, adds, edits
     * and deletes ILLiad address-mapping rows (borrower LOC + lender ILLiad id → address number and library
     * symbol). Explains the problem if the mapping table can't be read.
     */
    public function render(): void {
        $this->tbl  = SEAL_DB::$sealILLiadMapping;
        $this->self = esc_url( home_url( '/illiad-mapping' ) );

        $probe = $this->tbl !== '' ? SEAL_DB::query( "SELECT `num` FROM %t LIMIT 1", $this->tbl ) : false;
        if ( $probe === false ) {
            echo '<div class="adminlib-wrapper">';
            $this->alert( 'The ILLiad mapping table could not be read. Check the table name under ILL &rarr; Settings &rarr; Database Tables &rarr; ILLiad Mapping Table (for CDLC this is <code>CDLC-EFORM-ILLIAD-ADD-MAPPING</code>).', 'error' );
            echo '</div>';
            return;
        }

        $q = SEAL_DB::query( "SELECT `loc`, `Name`, `IlliadURL` FROM %t WHERE `loc`<>'' ORDER BY `Name` ASC", SEAL_DB::$sealLIB );
        while ( $q && ( $r = $q->fetch_assoc() ) ) {
            $this->libs[ strtolower( trim( (string) $r['loc'] ) ) ] = [
                'loc' => trim( (string) $r['loc'] ), 'name' => (string) $r['Name'], 'illiad' => trim( (string) ( $r['IlliadURL'] ?? '' ) ) !== '',
            ];
        }

        switch ( $this->req_int( 'action', 0 ) ) {
            case 1:  $this->render_form( 0 ); break;
            case 2:  $this->render_form( $this->req_int( 'num', 0 ) ); break;
            case 3:  $this->render_delete(); break;
            default: $this->render_list(); break;
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** "Library name (code)" for a stored code, or the bare code when it is not in the directory. */
    private function label( string $code ): string {
        $l = $this->libs[ strtolower( trim( $code ) ) ] ?? null;
        return $l ? $l['name'] . ' (' . $l['loc'] . ')' : trim( $code );
    }

    /** <option> list; the current value is always present, even if it is not in the directory. */
    private function options( string $current, bool $only_illiad ): string {
        $out = '<option value="">Choose a library&hellip;</option>';
        $seen = false;
        foreach ( $this->libs as $key => $l ) {
            if ( $only_illiad && ! $l['illiad'] && strcasecmp( $l['loc'], $current ) !== 0 ) { continue; }
            $sel = strcasecmp( $l['loc'], $current ) === 0;
            $seen = $seen || $sel;
            $out .= '<option value="' . esc_attr( $l['loc'] ) . '"' . ( $sel ? ' selected' : '' ) . '>' . esc_html( $l['name'] . ' (' . $l['loc'] . ')' ) . '</option>';
        }
        if ( $current !== '' && ! $seen ) {
            $out .= '<option value="' . esc_attr( $current ) . '" selected>' . esc_html( $current . ' (not in the library directory)' ) . '</option>';
        }
        return $out;
    }

    /** The "Back to the mapping list" button. */
    private function back(): string { return '<a class="btn-secondary" href="' . $this->self . '">Back to the mapping list</a>'; }

    // ── List ──────────────────────────────────────────────────────────────────

    /** The mapping rows, filterable by borrower and lender, with paging and Edit / Delete actions. */
    private function render_list( string $notice = '', string $notice_type = 'success' ): void {
        $f_b = sanitize_text_field( $this->get_str( 'borrower' ) );
        $f_l = sanitize_text_field( $this->get_str( 'lender' ) );
        $f_s = sanitize_text_field( $this->get_str( 'symbol' ) );
        $pg  = max( 1, $this->req_int( 'pg', 1 ) );

        // A name search becomes a list of matching codes (the mapping table stores codes only).
        $codes = function ( string $term ): array {
            $out = [];
            foreach ( $this->libs as $l ) {
                if ( stripos( $l['name'], $term ) !== false || stripos( $l['loc'], $term ) !== false ) { $out[] = "'" . SEAL_DB::esc( $l['loc'] ) . "'"; }
            }
            return $out ?: [ "''" ];
        };
        $where = '1=1';
        if ( $f_b !== '' ) { $where .= ' AND `LOC` IN (' . implode( ',', $codes( $f_b ) ) . ')'; }
        if ( $f_l !== '' ) { $where .= ' AND `illiadID` IN (' . implode( ',', $codes( $f_l ) ) . ')'; }
        if ( $f_s !== '' ) { $where .= " AND `illiadLIBSymbol` LIKE '%" . SEAL_DB::esc( $f_s ) . "%'"; }

        $cq    = SEAL_DB::get()->query( "SELECT COUNT(*) AS c FROM `{$this->tbl}` WHERE {$where}" );
        $total = $cq ? (int) $cq->fetch_assoc()['c'] : 0;
        $pages = max( 1, (int) ceil( $total / self::PER_PAGE ) );
        $pg    = min( $pg, $pages );
        $rows  = SEAL_DB::get()->query( "SELECT * FROM `{$this->tbl}` WHERE {$where} ORDER BY `illiadID` ASC, `LOC` ASC, `illiadADDnumb` ASC LIMIT " . ( ( $pg - 1 ) * self::PER_PAGE ) . ',' . self::PER_PAGE );
        $qs    = array_filter( [ 'borrower' => $f_b, 'lender' => $f_l, 'symbol' => $f_s ] );
        ?>
        <div class="adminlib-wrapper">
        <div class="adminlib-header">
            <h3>ILLiad Mapping</h3>
            <div class="actions"><a class="btn-primary" href="<?php echo esc_url( add_query_arg( 'action', 1, $this->self ) ); ?>">Add mapping</a></div>
        </div>
        <p class="seal-ss-note">Tells the system how a borrowing library is known inside a lending library's ILLiad: its address number and its library symbol. Requests to an ILLiad library cannot be sent without a matching row.</p>
        <?php if ( $notice !== '' ) { $this->alert( $notice, $notice_type ); } ?>

        <form method="get" action="<?php echo $this->self; ?>" class="filter-bar">
            <div class="filter-grid">
                <div class="form-group"><label for="im_b">Borrowing library (name or code)</label><input id="im_b" type="text" name="borrower" value="<?php echo esc_attr( $f_b ); ?>"></div>
                <div class="form-group"><label for="im_l">Lending library (name or code)</label><input id="im_l" type="text" name="lender" value="<?php echo esc_attr( $f_l ); ?>"></div>
                <div class="form-group"><label for="im_s">Library symbol</label><input id="im_s" type="text" name="symbol" value="<?php echo esc_attr( $f_s ); ?>"></div>
            </div>
            <div class="filter-actions">
                <button type="submit" class="btn-primary">Search</button>
                <a class="btn-secondary" href="<?php echo $this->self; ?>">Reset</a>
            </div>
        </form>

        <div class="pagination-info"><?php echo number_format( $total ); ?> mappings</div>
        <div class="seal-table-wrap">
        <table class="rh-table">
            <thead><tr><th scope="col">Lending library (ILLiad)</th><th scope="col">Borrowing library</th><th scope="col">Address #</th><th scope="col">Library symbol</th><th scope="col">Actions</th></tr></thead>
            <tbody>
            <?php if ( ! $rows || $total === 0 ) : ?>
                <tr><td colspan="5">No mappings found.</td></tr>
            <?php else : while ( $r = $rows->fetch_assoc() ) :
                $num = (int) $r['num']; ?>
                <tr>
                    <td><?php echo esc_html( $this->label( (string) $r['illiadID'] ) ); ?></td>
                    <td><?php echo esc_html( $this->label( (string) $r['LOC'] ) ); ?></td>
                    <td><?php echo (int) $r['illiadADDnumb']; ?></td>
                    <td><?php echo esc_html( trim( (string) $r['illiadLIBSymbol'] ) ); ?></td>
                    <td class="table-actions">
                        <a class="edit" href="<?php echo esc_url( add_query_arg( [ 'action' => 2, 'num' => $num ], $this->self ) ); ?>">Edit<span class="seal-sr-only"> mapping <?php echo $num; ?></span></a>
                        <a class="delete" href="<?php echo esc_url( add_query_arg( [ 'action' => 3, 'num' => $num ], $this->self ) ); ?>">Delete<span class="seal-sr-only"> mapping <?php echo $num; ?></span></a>
                    </td>
                </tr>
            <?php endwhile; endif; ?>
            </tbody>
        </table>
        </div>
        <?php if ( $pages > 1 ) : ?>
        <div style="margin-top:12px;display:flex;gap:8px;align-items:center;">
            <?php if ( $pg > 1 ) : ?><a class="btn-secondary" href="<?php echo esc_url( add_query_arg( $qs + [ 'pg' => $pg - 1 ], $this->self ) ); ?>">&larr; Previous</a><?php endif; ?>
            <span>Page <?php echo $pg; ?> of <?php echo $pages; ?></span>
            <?php if ( $pg < $pages ) : ?><a class="btn-primary" href="<?php echo esc_url( add_query_arg( $qs + [ 'pg' => $pg + 1 ], $this->self ) ); ?>">Next &rarr;</a><?php endif; ?>
        </div>
        <?php endif; ?>
        </div>
        <?php
    }

    // ── Add / edit ────────────────────────────────────────────────────────────

    /**
     * The add / edit form for one mapping row (borrower LOC, lender, ILLiad address number, library symbol).
     */
    private function render_form( int $num ): void {
        $row = [ 'LOC' => '', 'illiadID' => '', 'illiadADDnumb' => '', 'illiadLIBSymbol' => '' ];
        if ( $num > 0 ) {
            $q = SEAL_DB::query( "SELECT * FROM %t WHERE `num`=%d LIMIT 1", $this->tbl, $num );
            $found = $q ? $q->fetch_assoc() : null;
            if ( ! $found ) { echo '<div class="adminlib-wrapper">'; $this->alert( 'That mapping was not found.', 'error' ); echo $this->back() . '</div>'; return; }
            $row = $found;
        }

        $error = '';
        if ( $this->is_post() ) {
            $this->verify_nonce( 'seal_illiad_map' );
            $t   = fn( string $k ): string => trim( sanitize_text_field( wp_unslash( $_POST[ $k ] ?? '' ) ) );
            $loc = $t( 'loc' ); $lender = $t( 'lender' ); $add = $t( 'addnum' ); $sym = $t( 'symbol' );
            $row = [ 'LOC' => $loc, 'illiadID' => $lender, 'illiadADDnumb' => $add, 'illiadLIBSymbol' => $sym ];

            if ( ! isset( $this->libs[ strtolower( $loc ) ] ) )         { $error = 'Choose a borrowing library from the list.'; }
            elseif ( ! isset( $this->libs[ strtolower( $lender ) ] ) )  { $error = 'Choose a lending library from the list.'; }
            elseif ( ! ctype_digit( $add ) || (int) $add < 1 )          { $error = 'The address number must be a whole number of 1 or more.'; }
            elseif ( $sym === '' || strlen( $sym ) > 30 )               { $error = 'Enter the library symbol (up to 30 characters).'; }
            else {
                // The runtime looks a row up by borrower + lender, so a pair may appear only once.
                $dup = SEAL_DB::query( "SELECT `num` FROM %t WHERE `LOC`=%s AND `illiadID`=%s AND `num`<>%d LIMIT 1", $this->tbl, $loc, $lender, $num );
                if ( $dup && $dup->num_rows > 0 ) {
                    $error = 'A mapping for this borrowing library and lending library already exists. Edit that one instead.';
                }
            }

            if ( $error === '' ) {
                if ( $num > 0 ) {
                    $ok = SEAL_DB::query( "UPDATE %t SET `LOC`=%s, `illiadID`=%s, `illiadADDnumb`=%d, `illiadLIBSymbol`=%s WHERE `num`=%d LIMIT 1", $this->tbl, $loc, $lender, (int) $add, $sym, $num );
                } else {
                    $ok = SEAL_DB::query( "INSERT INTO %t (`LOC`,`illiadID`,`illiadADDnumb`,`illiadLIBSymbol`) VALUES (%s,%s,%d,%s)", $this->tbl, $loc, $lender, (int) $add, $sym );
                }
                if ( $ok === false ) {
                    $error = 'The save failed. Please try again or check the table with your database administrator.';
                } else {
                    $this->render_list( ( $num > 0 ? 'Mapping updated: ' : 'Mapping added: ' ) . esc_html( $this->label( $loc ) . ' → ' . $this->label( $lender ) ) . '.' );
                    return;
                }
            }
        }
        ?>
        <div class="adminlib-wrapper LibProfile_Form">
        <div class="adminlib-header"><h3><?php echo $num > 0 ? 'Edit mapping' : 'Add mapping'; ?></h3></div>
        <?php if ( $error !== '' ) { $this->alert( esc_html( $error ), 'error' ); } ?>
        <form method="post" action="<?php echo esc_url( $num > 0 ? add_query_arg( [ 'action' => 2, 'num' => $num ], $this->self ) : add_query_arg( 'action', 1, $this->self ) ); ?>">
            <?php wp_nonce_field( 'seal_illiad_map', 'seal_nonce' ); ?>
            <div class="section-card"><h4>Mapping</h4>
              <div class="form-section">
                <div class="form-group"><label for="m_lender">Lending library (the one running ILLiad)</label>
                    <select id="m_lender" name="lender" required aria-describedby="m_lender_h"><?php echo $this->options( (string) $row['illiadID'], true ); // built from escaped parts ?></select>
                    <small id="m_lender_h">Only libraries with an ILLiad URL are listed.</small></div>
                <div class="form-group"><label for="m_loc">Borrowing library</label>
                    <select id="m_loc" name="loc" required><?php echo $this->options( (string) $row['LOC'], false ); // built from escaped parts ?></select></div>
                <div class="form-group"><label for="m_add">ILLiad address number</label>
                    <input id="m_add" type="text" name="addnum" inputmode="numeric" pattern="[0-9]+" aria-describedby="m_add_h" value="<?php echo esc_attr( (string) $row['illiadADDnumb'] ); ?>" required>
                    <small id="m_add_h">The borrower's address record number inside the lender's ILLiad.</small></div>
                <div class="form-group"><label for="m_sym">Library symbol</label>
                    <input id="m_sym" type="text" name="symbol" maxlength="30" aria-describedby="m_sym_h" value="<?php echo esc_attr( trim( (string) $row['illiadLIBSymbol'] ) ); ?>" required>
                    <small id="m_sym_h">The borrower's library symbol inside the lender's ILLiad.</small></div>
              </div>
            </div>
            <div class="actions" style="margin-top:16px;">
                <button type="submit" class="btn-primary"><?php echo $num > 0 ? 'Save changes' : 'Add mapping'; ?></button>
                <a class="btn-secondary" href="<?php echo $this->self; ?>">Cancel</a>
            </div>
        </form>
        </div>
        <?php
    }

    // ── Delete ────────────────────────────────────────────────────────────────

    /** Asks for confirmation before deleting a mapping row. */
    private function render_delete(): void {
        $num = $this->req_int( 'num', 0 );
        $q   = $num > 0 ? SEAL_DB::query( "SELECT * FROM %t WHERE `num`=%d LIMIT 1", $this->tbl, $num ) : false;
        $row = $q ? $q->fetch_assoc() : null;
        if ( ! $row ) { echo '<div class="adminlib-wrapper">'; $this->alert( 'That mapping was not found.', 'error' ); echo $this->back() . '</div>'; return; }
        $what = $this->label( (string) $row['LOC'] ) . ' → ' . $this->label( (string) $row['illiadID'] );

        if ( $this->is_post() && isset( $_POST['confirm_delete'] ) ) {
            $this->verify_nonce( 'seal_illiad_map_delete' );
            $ok = SEAL_DB::query( "DELETE FROM %t WHERE `num`=%d LIMIT 1", $this->tbl, $num );
            $this->render_list( $ok === false ? 'The delete failed. Please try again.' : 'Mapping deleted: ' . esc_html( $what ) . '.', $ok === false ? 'error' : 'success' );
            return;
        }
        ?>
        <div class="adminlib-wrapper">
        <div class="adminlib-header"><h3>Confirm delete</h3></div>
        <div class="adminlib-panel">
            <p>Delete the mapping <strong><?php echo esc_html( $what ); ?></strong> (address # <?php echo (int) $row['illiadADDnumb']; ?>, symbol <?php echo esc_html( trim( (string) $row['illiadLIBSymbol'] ) ); ?>)?
               Requests from this library to that lender will no longer be sent to ILLiad.</p>
            <form method="post" action="<?php echo esc_url( add_query_arg( [ 'action' => 3, 'num' => $num ], $this->self ) ); ?>">
                <?php wp_nonce_field( 'seal_illiad_map_delete', 'seal_nonce' ); ?>
                <input type="hidden" name="confirm_delete" value="1">
                <button type="submit" class="btn-danger">Confirm delete</button>
                <a class="btn-secondary" href="<?php echo $this->self; ?>">Cancel</a>
            </form>
        </div>
        </div>
        <?php
    }
}
