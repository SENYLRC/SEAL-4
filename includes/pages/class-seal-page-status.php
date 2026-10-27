<?php
/**
 * SEAL_Page_status — /status: one step in a request's life (capability seal_status).
 *
 * Action code a=:
 * - 1  borrower marks the item received
 * - 2  borrower returns it (asks for the return method and a note first)
 * - 3  lender checks it back in (asks for confirmation first)
 * - 6  borrower cancels a request that has no answer yet; the bibliographic details are cleared, the lender is
 *   emailed, and any Automatic Fallback chain is closed
 * authorize_request() makes sure only the borrowing library (or the lender, for a=3) can act on the request.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class SEAL_Page_status extends SEAL_Page_Base {
    /** Entry point: SEAL_Router calls this to print the page into the theme's content area. */
    public function render(): void {
        $db       = SEAL_DB::get();
        $stat_tbl = SEAL_DB::$sealSTAT;
        $lib_tbl  = SEAL_DB::$sealLIB;
        $uid      = $this->user->ID;
        $wholename = esc_html( $this->user->first_name . ' ' . $this->user->last_name );

        $reqnumb  = sanitize_text_field( wp_unslash( $_REQUEST['num']         ?? '' ) );
        $recanswer= sanitize_text_field( wp_unslash( $_REQUEST['a']           ?? '' ) );
        $timestamp= current_time('mysql');
        $today    = current_time('Y-m-d');

        // Validate action code strictly
        $valid_actions = ['1','2','3','6'];
        if ( $reqnumb === '' || ! in_array( $recanswer, $valid_actions, true ) ) {
            echo '<p>Invalid request. <a href="' . esc_url(home_url('/requesthistory')) . '">Back to your request history</a>.</p>';
            return;
        }

        $self = esc_url( home_url('/status') );

        // Received / return / cancel belong to the borrowing library; check-in belongs to the lender.
        // Nobody can change another library's request by typing its number.
        if ( ! $this->authorize_request( $reqnumb, $recanswer === '3' ? 'lender' : 'borrower' ) ) { return; }

        // ── Action 1: Mark received ──────────────────────────────────────────
        if ( $recanswer === '1' && $this->is_post() ) {
            $this->verify_nonce('seal_status');
            SEAL_DB::query( "UPDATE %t SET `receiveTimeStamp`=%s, `receiveAccount`=%s, `receiveDate`=%s WHERE `illNUB`=%s",
                $stat_tbl, $timestamp, $wholename, $today, $reqnumb );
            $this->alert( "ILL #{$reqnumb} has been marked received. <a href='" . esc_url(home_url('/requesthistory')) . "'>Back to request history</a>", 'success' );
            return;
        }

        // ── Action 2: Return ─────────────────────────────────────────────────
        if ( $recanswer === '2' ) {
            $returnmethod = sanitize_text_field( wp_unslash( $_REQUEST['shipmethod'] ?? '' ) );
            if ( $this->is_post() && $returnmethod !== '' ) {
                $this->verify_nonce('seal_status');
                $returnnote = sanitize_text_field( wp_unslash( $_POST['returnnote'] ?? '' ) );
                SEAL_DB::query( "UPDATE %t SET `returnTimeStamp`=%s, `returnMethod`=%s, `returnNote`=%s, `returnAccount`=%s, `returnDate`=%s, `patronnote`='' WHERE `illNUB`=%s",
                    $stat_tbl, $timestamp, $returnmethod, $returnnote, $wholename, $today, $reqnumb );
                $this->alert( "ILL #{$reqnumb} has been marked returned. <a href='" . esc_url(home_url('/requesthistory')) . "'>Back to request history</a>", 'success' );
            } else {
                ?>
                <div class="seal-page-wrap">
                <h3>Return Item — ILL# <?php echo esc_html($reqnumb); ?></h3>
                <form method="post" action="<?php echo $self; ?>">
                    <?php $this->nonce_field('seal_status'); ?>
                    <input type="hidden" name="a" value="2">
                    <input type="hidden" name="num" value="<?php echo esc_attr($reqnumb); ?>">
                    <label for="shipmethod2"><strong>How are you returning the item?</strong></label><br>
                    <select name="shipmethod" id="shipmethod2" required style="margin:8px 0;">
                        <option value=""></option>
                        <option value="lc">Library Courier</option>
                        <option value="usps">US Mail</option>
                        <option value="upsfx">UPS / FedEx</option>
                        <option value="empire">Empire Library Delivery</option>
                        <option value="other">Other</option>
                    </select><br>
                    <label for="returnnote2"><strong>Return Notes</strong></label><br>
                    <input type="text" name="returnnote" id="returnnote2" style="width:100%;margin:8px 0;">
                    <button type="submit" class="seal-btn-primary">Submit</button>
                </form>
                </div>
                <?php
            }
            return;
        }

        // ── Action 3: Lender check-in ────────────────────────────────────────
        if ( $recanswer === '3' ) {
            $itemreturn = sanitize_text_field( wp_unslash( $_REQUEST['itemreturn'] ?? '' ) );
            if ( $this->is_post() && $itemreturn !== '' ) {
                $this->verify_nonce('seal_status');
                if ( $itemreturn === '1' ) {
                    SEAL_DB::query( "UPDATE %t SET `checkinTimeStamp`=%s, `checkinAccount`=%s WHERE `illNUB`=%s",
                        $stat_tbl, $timestamp, $wholename, $reqnumb );
                    $this->alert( "ILL #{$reqnumb} has been checked in. <a href='" . esc_url(home_url('/lender-history')) . "'>Back to lending history</a>", 'success' );
                } else {
                    $this->alert( "Item was NOT marked returned at your request. <a href='" . esc_url(home_url('/lender-history')) . "'>Back to lending history</a>", 'info' );
                }
            } else {
                ?>
                <div class="seal-page-wrap">
                <h3>Check In — ILL# <?php echo esc_html($reqnumb); ?></h3>
                <form method="post" action="<?php echo $self; ?>">
                    <?php $this->nonce_field('seal_status'); ?>
                    <input type="hidden" name="a" value="3">
                    <input type="hidden" name="num" value="<?php echo esc_attr($reqnumb); ?>">
                    <p>Mark this item as returned to your library?</p>
                    <label><input type="radio" name="itemreturn" value="1" checked> Yes</label><br>
                    <label><input type="radio" name="itemreturn" value="0"> No</label><br><br>
                    <button type="submit" class="seal-btn-primary">Submit</button>
                </form>
                </div>
                <?php
            }
            return;
        }

        // ── Action 6: Cancel ─────────────────────────────────────────────────
        if ( $recanswer === '6' ) {
            if ( $this->is_post() ) {
                $this->verify_nonce('seal_status');
                $respondnote = sanitize_text_field( wp_unslash( $_POST['respondnote'] ?? '' ) );

                $sel_q = SEAL_DB::query( "SELECT `requesterEMAIL`, `Title`, `Author`, `Destination`, `Requester lib` FROM %t WHERE `illNUB`=%s LIMIT 1", $stat_tbl, $reqnumb );
                $row   = $sel_q ? $sel_q->fetch_assoc() : [];

                SEAL_DB::query( "UPDATE %t SET `Fill`='6', `Title`='', `Author`='', `pubdate`='', `reqisbn`='', `reqissn`='', `itype`='', `Call Number`='', `article`='', `needbydate`='', `patronnote`='', `DueDate`='', `emailsent`='1', `responderNOTE`=%s, `IlliadStatus`='' WHERE `illNUB`=%s",
                    $stat_tbl, $respondnote, $reqnumb );
                if ( SEAL_Rota::active() ) { SEAL_Rota::close( $reqnumb, 'cancelled' ); }

                if ( $row ) {
                    $tv   = [ 'ill' => $reqnumb, 'title' => (string) $row['Title'], 'borrower_library' => (string) $row['Requester lib'] ];
                    $body = SEAL_Emails::heading( 'request_cancelled', $tv )
                          . SEAL_Emails::message( 'request_cancelled', $tv )
                          . SEAL_Functions::email_details( [
                                'ILL #'  => $reqnumb,
                                'Title'  => (string) $row['Title'],
                                'Author' => (string) ( $row['Author'] ?? '' ),
                                'Note from the requesting library' => $respondnote,
                            ] );
                    $body .= SEAL_Emails::closing( 'request_cancelled', $tv );
                    $sub  = SEAL_Emails::subject( 'request_cancelled', [ 'ill' => $reqnumb, 'title' => (string) $row['Title'] ] );
                    SEAL_Functions::send_email( $row['requesterEMAIL'], $sub, $body );

                    // Also notify lender
                    $dest_q = SEAL_DB::query( "SELECT `ill_email` FROM %t WHERE `loc`=%s LIMIT 1", $lib_tbl, $row['Destination'] );
                    $dest_r = $dest_q ? $dest_q->fetch_assoc() : [];
                    if ( ! empty($dest_r['ill_email']) ) {
                        SEAL_Functions::send_email( str_replace(';',',',$dest_r['ill_email']), $sub, $body );
                    }
                }
                $this->alert( "ILL #{$reqnumb} has been cancelled. <a href='" . esc_url(home_url('/requesthistory')) . "'>Back to request history</a>", 'success' );
            } else {
                ?>
                <div class="seal-page-wrap">
                <h3>Cancel Request — ILL# <?php echo esc_html($reqnumb); ?></h3>
                <p>Please confirm you want to cancel this request.</p>
                <form method="post" action="<?php echo $self; ?>">
                    <?php $this->nonce_field('seal_status'); ?>
                    <input type="hidden" name="a" value="6">
                    <input type="hidden" name="num" value="<?php echo esc_attr($reqnumb); ?>">
                    <label for="cancel_note"><strong>Cancellation notes (optional):</strong></label><br>
                    <textarea name="respondnote" id="cancel_note" rows="4" style="width:100%;margin:8px 0;"></textarea><br>
                    <button type="submit" class="seal-btn-danger">Cancel This Request</button>
                    <a href="<?php echo esc_url(home_url('/requesthistory')); ?>" style="margin-left:12px;" class="seal-btn-secondary">Go Back</a>
                </form>
                </div>
                <?php
            }
        }
    }
}
