<?php
/**
 * Pull / route slip — a printable sheet that travels with a loaned item.
 *
 * Standalone page (no theme, no sidebar): SEAL_Router::STANDALONE_ROUTES sends the finished
 * document straight to the browser. /pullslip?num=2026-0123 prints one slip;
 * /pullslip?num=2026-0123,2026-0124 prints several, one per page.
 *
 * Who may print a slip: staff of the lending library (Destination), staff of the borrowing
 * library (Requester LOC), and administrators. The patron note is deliberately left off the slip
 * because it can hold a patron's name or barcode.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class SEAL_Page_pullslip extends SEAL_Page_Base {

    private const MAX_SLIPS = 100;

    /**
     * Entry point: SEAL_Router calls this to print the page into the theme's content area. Prints a complete
     * printable page (no theme) of pull / routing slips for the ILL numbers in num=, limited to requests the
     * user's library may see.
     */
    public function render(): void {
        $nums = [];
        foreach ( explode( ',', $this->req_str( 'num', '' ) ) as $n ) {
            $n = trim( sanitize_text_field( $n ) );
            if ( $n !== '' && preg_match( '/^[A-Za-z0-9._\-]{1,40}$/', $n ) ) { $nums[ $n ] = true; }
        }
        $nums = array_slice( array_keys( $nums ), 0, self::MAX_SLIPS );
        if ( ! $nums ) { wp_die( esc_html__( 'No request number was given.', 'seal-ill' ), '', [ 'response' => 400 ] ); }

        $my_locs  = SEAL_Functions::all_locs( $this->user->ID );
        $is_admin = SEAL_Roles::is_admin();
        $stat_tbl = SEAL_DB::$sealSTAT;
        $lib_cache = [];

        $slips = [];
        foreach ( $nums as $num ) {
            $res = SEAL_DB::query( "SELECT * FROM %t WHERE `illNUB`=%s LIMIT 1", $stat_tbl, $num );
            $r   = $res ? $res->fetch_assoc() : null;
            if ( ! $r ) { continue; }
            $dest = strtoupper( trim( (string) ( $r['Destination'] ?? '' ) ) );
            $req  = strtoupper( trim( (string) ( $r['Requester LOC'] ?? '' ) ) );
            if ( ! $is_admin && ! in_array( $dest, $my_locs, true ) && ! in_array( $req, $my_locs, true ) ) { continue; }

            foreach ( [ $dest, $req ] as $loc ) {
                if ( $loc !== '' && ! isset( $lib_cache[ $loc ] ) ) { $lib_cache[ $loc ] = SEAL_Functions::fetch_lib_profile( $loc ); }
            }
            $slips[] = [ 'row' => $r, 'dest' => $lib_cache[ $dest ] ?? [ 'ok' => false ], 'req' => $lib_cache[ $req ] ?? [ 'ok' => false ] ];
        }
        if ( ! $slips ) {
            wp_die( esc_html__( 'No printable request was found (it may not belong to your library).', 'seal-ill' ), '', [ 'response' => 404 ] );
        }

        while ( ob_get_level() ) { ob_end_clean(); }
        nocache_headers();
        header( 'Content-Type: text/html; charset=utf-8' );
        header( 'X-Robots-Tag: noindex, nofollow' );

        $system = (string) seal_config( 'system_name' );
        $who    = $this->user->display_name ?: $this->user->user_login;
        $when   = wp_date( 'Y-m-d H:i' );
        ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html( count( $slips ) > 1 ? 'Pull slips' : 'Pull slip ' . $slips[0]['row']['illNUB'] ); ?></title>
<style>
    * { box-sizing: border-box; }
    body { margin: 0; background: #eef0f3; color: #000; font: 14px/1.4 Arial, Helvetica, sans-serif; }
    .bar { position: sticky; top: 0; display: flex; gap: 10px; align-items: center; padding: 10px 16px; background: #1d2327; color: #fff; }
    .bar span { flex: 1; }
    .bar button { font: inherit; padding: 6px 14px; border: 1px solid #fff; border-radius: 4px; background: #fff; color: #1d2327; cursor: pointer; }
    .bar button:focus { outline: 3px solid #f0b849; outline-offset: 2px; }
    .slip { width: 7.5in; max-width: 100%; margin: 16px auto; padding: 0.4in; background: #fff; border: 1px solid #c3c4c7; page-break-after: always; break-after: page; }
    .slip:last-of-type { page-break-after: auto; break-after: auto; }
    .head { display: flex; justify-content: space-between; align-items: baseline; border-bottom: 2px solid #000; padding-bottom: 6px; margin-bottom: 12px; }
    .head h1 { margin: 0; font-size: 20px; }
    .head .num { font-size: 22px; font-weight: bold; letter-spacing: .5px; }
    .title { font-size: 18px; margin: 0 0 2px; }
    .author { margin: 0 0 12px; }
    dl { display: grid; grid-template-columns: 9em 1fr; gap: 4px 12px; align-items: baseline; margin: 0 0 14px; }
    dt { font-weight: bold; }
    dd { margin: 0; }
    .call { font-size: 20px; font-weight: bold; }
    .note { border: 1px solid #000; padding: 6px 10px; margin: 0 0 14px; }
    .to { border: 2px solid #000; padding: 10px 14px; margin: 0 0 12px; }
    .to .label { font-size: 12px; text-transform: uppercase; letter-spacing: .6px; margin: 0 0 4px; }
    .to .name { font-size: 18px; font-weight: bold; }
    .to p { margin: 1px 0; }
    .foot { margin-top: 10px; font-size: 11px; color: #333; display: flex; justify-content: space-between; gap: 12px; }
    @media print {
        body { background: #fff; }
        .bar { display: none; }
        .slip { width: auto; margin: 0; padding: 0; border: 0; }
        @page { margin: 0.5in; }
    }
</style>
</head>
<body>
<div class="bar">
    <span><?php echo esc_html( count( $slips ) . ( count( $slips ) === 1 ? ' slip' : ' slips' ) ); ?> ready to print</span>
    <button type="button" onclick="window.print()">Print</button>
    <button type="button" onclick="window.close()">Close</button>
</div>
<main>
<?php foreach ( $slips as $s ) { $this->slip( $s['row'], $s['dest'], $s['req'], $system, $who, $when ); } ?>
</main>
<script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 300); });</script>
</body>
</html>
        <?php
        exit;
    }

    /** One slip. $dest = lending library profile, $req = borrowing library profile. */
    private function slip( array $r, array $dest, array $req, string $system, string $who, string $when ): void {
        $v = static fn( string $k ): string => trim( (string) ( $r[ $k ] ?? '' ) );

        $isbn = trim( $v( 'reqisbn' ) . ( $v( 'reqissn' ) !== '' ? ( $v( 'reqisbn' ) !== '' ? ' / ' : '' ) . $v( 'reqissn' ) : '' ) );
        $rows = [
            'Item type'        => $v( 'itype' ),
            'Publication date' => $v( 'pubdate' ),
            'ISBN / ISSN'      => $isbn,
            'Location'         => $v( 'Location' ),
            'Need by'          => $v( 'needbydate' ),
            'Requested'        => $v( 'Timestamp' ),
        ];
        // Borrower's shipping address as entered on the request; the library record fills any gap.
        $req_addr = array_values( array_filter( [ $v( 'saddress' ), $v( 'saddress2' ), $v( 'caddress' ) ], 'strlen' ) );
        if ( ! $req_addr && ! empty( $req['ok'] ) ) {
            $req_addr = array_values( array_filter( [ $req['street1'], $req['street2'], $req['citystzip'] ], 'strlen' ) );
        }
        $dest_addr = ! empty( $dest['ok'] ) ? array_values( array_filter( [ $dest['street1'], $dest['street2'], $dest['citystzip'] ], 'strlen' ) ) : [];
        ?>
<section class="slip" aria-label="Pull slip <?php echo esc_attr( $v( 'illNUB' ) ); ?>">
    <div class="head">
        <h1><?php echo esc_html( $system ); ?> Pull / Route Slip</h1>
        <div class="num">ILL # <?php echo esc_html( $v( 'illNUB' ) ); ?></div>
    </div>

    <p class="title"><strong><?php echo esc_html( $v( 'Title' ) ?: '(no title)' ); ?></strong></p>
    <?php if ( $v( 'Author' ) !== '' ) : ?><p class="author"><?php echo esc_html( $v( 'Author' ) ); ?></p><?php endif; ?>

    <dl>
        <?php if ( $v( 'Call Number' ) !== '' ) : ?><dt>Call number</dt><dd class="call"><?php echo esc_html( $v( 'Call Number' ) ); ?></dd><?php endif; ?>
        <?php foreach ( $rows as $label => $val ) : if ( $val === '' ) { continue; } ?>
        <dt><?php echo esc_html( $label ); ?></dt><dd><?php echo esc_html( $val ); ?></dd>
        <?php endforeach; ?>
    </dl>

    <?php if ( $v( 'reqnote' ) !== '' ) : ?><p class="note"><strong>Request note:</strong> <?php echo esc_html( $v( 'reqnote' ) ); ?></p><?php endif; ?>
    <?php if ( $v( 'responderNOTE' ) !== '' ) : ?><p class="note"><strong>Lender note:</strong> <?php echo esc_html( $v( 'responderNOTE' ) ); ?></p><?php endif; ?>

    <div class="to">
        <p class="label">Return this item to</p>
        <p class="name"><?php echo esc_html( $dest['Name'] ?? $v( 'Destination' ) ); ?></p>
        <?php foreach ( $dest_addr as $line ) : ?><p><?php echo esc_html( $line ); ?></p><?php endforeach; ?>
        <?php if ( ! empty( $dest['phone'] ) ) : ?><p>Phone: <?php echo esc_html( $dest['phone'] ); ?></p><?php endif; ?>
        <p><?php echo esc_html( $system ); ?> ID: <strong><?php echo esc_html( $v( 'Destination' ) ); ?></strong><?php if ( ! empty( $dest['system'] ) ) : ?> &nbsp; Library system: <strong><?php echo esc_html( $dest['system'] ); ?></strong><?php endif; ?></p>
    </div>

    <div class="to">
        <p class="label">Deliver this item to</p>
        <p class="name"><?php echo esc_html( $v( 'Requester lib' ) ?: ( $req['Name'] ?? '' ) ); ?></p>
        <?php if ( $v( 'Requester person' ) !== '' ) : ?><p>Attn: <?php echo esc_html( $v( 'Requester person' ) ); ?></p><?php endif; ?>
        <?php foreach ( $req_addr as $line ) : ?><p><?php echo esc_html( $line ); ?></p><?php endforeach; ?>
        <?php if ( $v( 'requesterPhone' ) !== '' ) : ?><p>Phone: <?php echo esc_html( $v( 'requesterPhone' ) ); ?></p><?php endif; ?>
        <p><?php echo esc_html( $system ); ?> ID: <strong><?php echo esc_html( $v( 'Requester LOC' ) ); ?></strong><?php if ( $v( 'ReqSystem' ) !== '' ) : ?> &nbsp; Library system: <strong><?php echo esc_html( $v( 'ReqSystem' ) ); ?></strong><?php endif; ?></p>
    </div>

    <div class="foot">
        <span>Keep this slip with the item and send it back with the item.</span>
        <span>Printed <?php echo esc_html( $when ); ?> by <?php echo esc_html( $who ); ?></span>
    </div>
</section>
        <?php
    }
}
