<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * SEAL_Page_respond — Lender response page (fill / no-fill / due date / ship method)
 */
class SEAL_Page_respond extends SEAL_Page_Base {

    /**
     * Entry point: SEAL_Router calls this to print the page into the theme's content area. With FromLender=1
     * (or an emailed a=1|0 link) it shows the answer form; a posted answer saves Fill, due date, ship method or
     * not-filled reason, emails the borrower, and with Automatic Fallback moves a declined request on to the
     * next library.
     */
    public function render(): void {
        $db       = SEAL_DB::get();
        $stat_tbl = SEAL_DB::$sealSTAT;
        $lib_tbl  = SEAL_DB::$sealLIB;
        $uid      = $this->user->ID;
        $self_url = esc_url( SEAL_Functions::self_url() );

        $reqnumb    = sanitize_text_field( wp_unslash( $_REQUEST['num']        ?? '' ) );
        // fill value from GET (email link) or POST — must be exactly '0' or '1'
        // The 3-day reminder emails sent by the cron scripts link to /respond?num=N&a=1|0
        // ("a" = the lender's answer), so accept that on GET as well.
        $email_link = ! $this->is_post() && isset( $_GET['a'] ) && in_array( sanitize_text_field( wp_unslash( $_GET['a'] ) ), ['0', '1'], true );
        $raw_fill   = sanitize_text_field( wp_unslash( $_REQUEST['fill'] ?? ( $email_link ? $_GET['a'] : '' ) ) );
        $reqanswer  = in_array( $raw_fill, ['0', '1'], true ) ? $raw_fill : '';
        $from_lender= sanitize_text_field( wp_unslash( $_REQUEST['FromLender'] ?? ( $email_link ? '1' : '' ) ) );
        $whole_name = esc_html( $this->user->first_name . ' ' . $this->user->last_name );

        // ── Lender direct response (email link, FromLender=1) ────────────────
        if ( $from_lender === '1' && $reqnumb !== '' ) {
            // Show the full respond form for lender
            $this->render_lender_form( $reqnumb, $reqanswer );
            return;
        }

        // ── POST: process lender form submission ─────────────────────────────
        if ( $this->is_post() ) {
            $this->verify_nonce( 'seal_respond' );

            $respnote     = sanitize_text_field( wp_unslash( $_POST['respondnote']  ?? '' ) );
            // fill must be exactly '0' (no) or '1' (yes) — reject anything else
            $raw_resfill  = sanitize_text_field( wp_unslash( $_POST['fill'] ?? '' ) );
            $resfill      = in_array( $raw_resfill, ['0', '1'], true ) ? $raw_resfill : '';
            if ( $resfill === '' ) {
                $this->alert( 'Invalid fill value. Please go back and try again.', 'error' );
                return;
            }
            $duedate      = sanitize_text_field( wp_unslash( $_POST['duedate']      ?? '' ) );
            $nofillreason = sanitize_text_field( wp_unslash( $_POST['nofillreason'] ?? '' ) );
            $shipmethod   = sanitize_text_field( wp_unslash( $_POST['shipmethod']   ?? '' ) );
            $reqnumb      = sanitize_text_field( wp_unslash( $_POST['num']          ?? '' ) );
            $today        = current_time( 'Y-m-d' );

            // Only the lending library (or an administrator) may answer a request.
            if ( ! $this->authorize_request( $reqnumb, 'lender' ) ) { return; }

            // Accept only sensible values: a real date, a configured delivery method, a known reason.
            if ( $duedate !== '' ) {
                $ts = strtotime( str_replace( '-', '/', $duedate ) );
                if ( ! $ts ) { $this->alert( 'The due date is not a valid date. Please go back and use YYYY-MM-DD.', 'error' ); return; }
                $duedate = date( 'Y-m-d', $ts );
            }
            if ( $shipmethod !== '' && ! array_key_exists( $shipmethod, SEAL_Org_Rules::couriers() ) ) { $shipmethod = ''; }
            if ( ! in_array( $nofillreason, [ '', '20', '21', '22', '23', '24', '25', '26' ], true ) ) { $nofillreason = ''; }
            // Keep only what belongs to the answer given: no due date / ship method on a "No", no reason on a "Yes".
            if ( $resfill === '0' ) { $duedate = ''; $shipmethod = ''; } else { $nofillreason = ''; }

            $result = SEAL_DB::query(
                "UPDATE %t SET emailsent='1', Fill=%s, responderNOTE=%s, shipMethod=%s,
                 DueDate=%s, ReasonNotFilled=%s, fillNofillDate=%s WHERE illNUB=%s",
                $stat_tbl,
                $resfill, $respnote, $shipmethod, $duedate, $nofillreason, $today, $reqnumb
            );

            if ( $result ) {
                // Send notification email to requester
                $sel_q = SEAL_DB::query(
                    "SELECT requesterEMAIL, Title, Author, Destination FROM %t WHERE illNUB=%s LIMIT 1",
                    $stat_tbl, $reqnumb
                );
                $sel_row = $sel_q ? $sel_q->fetch_assoc() : [];

                if ( $sel_row ) {
                    $dest_q = SEAL_DB::query(
                        "SELECT Name, ill_email FROM %t WHERE loc=%s LIMIT 1",
                        $lib_tbl, $sel_row['Destination']
                    );
                    $dest_row = $dest_q ? $dest_q->fetch_assoc() : [];
                    $destname  = $dest_row['Name']      ?? '';
                    $destemail = $dest_row['ill_email']  ?? '';

                    if ( $resfill === '1' ) {
                        if ( SEAL_Rota::active() ) { SEAL_Rota::close( $reqnumb, 'filled' ); }
                        $shiptxt = SEAL_Functions::shipmt_txt( $shipmethod );
                        $tv   = [ 'ill' => $reqnumb, 'title' => $sel_row['Title'], 'lender_library' => $destname ];
                        $body = SEAL_Emails::heading( 'request_filled', $tv )
                              . SEAL_Emails::message( 'request_filled', $tv )
                              . SEAL_Functions::email_details( [
                                    'ILL #'           => $reqnumb,
                                    'Title'           => $sel_row['Title'],
                                    'Author'          => $sel_row['Author'] ?? '',
                                    'Lending library' => $destname,
                                    'Due date'        => SEAL_Functions::email_date( $duedate ),
                                    'Shipping'        => $shiptxt,
                                    'Note from the lender' => $respnote,
                                    'Lender contact*' => SEAL_Functions::email_mailto( $destemail ),
                                ] )
                              . SEAL_Functions::email_button( home_url( '/requesthistory' ), 'View your borrowing requests' )
                              . SEAL_Emails::closing( 'request_filled', $tv );
                        SEAL_Functions::send_email(
                            $sel_row['requesterEMAIL'],
                            SEAL_Emails::subject( 'request_filled', [ 'ill' => $reqnumb, 'title' => $sel_row['Title'] ] ),
                            $body
                        );
                    } else {
                        $reasons = [
                            '20' => 'In Use', '21' => 'Lost', '22' => 'Non-Circulating',
                            '23' => 'Not on Shelf', '24' => 'Poor Condition',
                            '25' => 'Too New', '26' => 'Not Owned',
                        ];
                        $reasontxt = $reasons[ $nofillreason ] ?? 'Unavailable';

                        // If this request is enrolled in the automatic fallback ("rota"), let it move
                        // to the next library instead of the plain "cannot be filled" notice — the
                        // advance itself emails the borrower and the next lender.
                        $rota_note = trim( $reasontxt . ( $respnote !== '' ? ' — ' . $respnote : '' ) );
                        $advanced  = SEAL_Rota::active()
                            ? SEAL_Rota::advance( $reqnumb, 'declined', $rota_note )
                            : [ 'ok' => false ];

                        if ( empty( $advanced['ok'] ) ) {
                            $tv   = [ 'ill' => $reqnumb, 'title' => $sel_row['Title'], 'lender_library' => $destname ];
                            $body = SEAL_Emails::heading( 'request_not_filled', $tv )
                                  . SEAL_Emails::message( 'request_not_filled', $tv )
                                  . SEAL_Functions::email_details( [
                                        'ILL #'           => $reqnumb,
                                        'Title'           => $sel_row['Title'],
                                        'Author'          => $sel_row['Author'] ?? '',
                                        'Lending library' => $destname,
                                        'Reason'          => $reasontxt,
                                        'Note from the lender' => $respnote,
                                        'Lender contact*' => SEAL_Functions::email_mailto( $destemail ),
                                    ] )
                                  . SEAL_Emails::closing( 'request_not_filled', $tv )
                                  . SEAL_Functions::email_button( home_url( '/' ), 'Search the catalog' );
                            SEAL_Functions::send_email(
                                $sel_row['requesterEMAIL'],
                                SEAL_Emails::subject( 'request_not_filled', [ 'ill' => $reqnumb, 'title' => $sel_row['Title'] ] ),
                                $body
                            );
                        }
                    }
                }

                echo '<div class="seal-alert seal-alert-success" role="status">Thank you. Your response has been recorded.</div>';
                echo '<p><a href="' . esc_url( home_url( '/lender-history' ) ) . '">View your lender history</a></p>';
            } else {
                $this->alert( 'There was an error recording your response. Please try again.', 'error' );
            }
            return;
        }

        // Default: show respond form based on GET
        $this->render_lender_form( $reqnumb, $reqanswer );
    }

    /**
     * The lender's answer form for one request: Yes (due date, ship method, note) or No (reason, note), pre-
     * selected from $fill. Only the lending library or an administrator may see it.
     */
    private function render_lender_form( string $reqnumb, string $fill ): void {
        $self_url = esc_url( SEAL_Functions::self_url() );

        // Fetch request details (only for the lending library or an administrator)
        $req = $this->authorize_request( $reqnumb, 'lender' );
        if ( ! $req ) { return; }
        ?>
        <div class="seal-respond">
            <h3>ILL Response — Request #<?php echo esc_html( $reqnumb ); ?></h3>

            <?php if ( $req ): ?>
            <table class="seal-bib-table">
                <tr><th scope="row">Title:</th><td><?php echo esc_html( $req['Title'] ); ?></td></tr>
                <tr><th scope="row">Author:</th><td><?php echo esc_html( $req['Author'] ); ?></td></tr>
                <tr><th scope="row">Requester:</th><td><?php echo esc_html( $req['Requester lib'] ); ?></td></tr>
                <tr><th scope="row">Note:</th><td><?php echo esc_html( $req['reqnote'] ); ?></td></tr>
            </table>
            <?php endif; ?>

            <form method="post" action="<?php echo $self_url; ?>">
                <?php $this->nonce_field( 'seal_respond' ); ?>
                <input type="hidden" name="num" value="<?php echo esc_attr( $reqnumb ); ?>">

                <fieldset>
                    <legend><strong>Will you fill this request?</strong></legend>
                    <label><input type="radio" name="fill" value="1" <?php checked( $fill, '1' ); ?>> Yes, I will fill</label><br>
                    <label><input type="radio" name="fill" value="0" <?php checked( $fill, '0' ); ?>> No, I cannot fill</label>
                </fieldset>

                <div id="seal-fill-fields"<?php echo $fill === '0' ? ' hidden' : ''; ?>>
                    <br>
                    <label for="datepicker"><strong>Due Date:</strong></label><br>
                    <input type="text" id="datepicker" name="duedate" placeholder="YYYY-MM-DD"
                           autocomplete="off" style="width:200px;"><br><br>

                    <label for="shipmethod"><strong>Ship Method:</strong></label><br>
                    <select name="shipmethod" id="shipmethod">
                        <option value="">-- Select --</option>
                        <?php foreach ( SEAL_Org_Rules::couriers() as $c_code => $c_label ): ?>
                        <option value="<?php echo esc_attr( $c_code ); ?>"><?php echo esc_html( $c_label ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div id="seal-nofill-fields"<?php echo $fill === '0' ? '' : ' hidden'; ?>>
                    <br>
                    <label for="nofillreason"><strong>Reason:</strong></label><br>
                    <select name="nofillreason" id="nofillreason">
                        <option value="">-- Select reason --</option>
                        <option value="20">In Use</option>
                        <option value="21">Lost</option>
                        <option value="22">Non-Circulating</option>
                        <option value="23">Not on Shelf</option>
                        <option value="24">Poor Condition</option>
                        <option value="25">Too New</option>
                        <option value="26">Not Owned</option>
                    </select>
                </div>

                <br>
                <label for="respondnote"><strong>Note to requester:</strong></label><br>
                <textarea id="respondnote" name="respondnote" rows="3" style="width:100%;"></textarea>

                <br><br>
                <button type="submit" class="seal-btn-primary">Submit Response</button>
            </form>
        </div>
        <script>
        // Yes shows Due Date + Ship Method, No shows Reason; the note is always there. Hidden fields are
        // disabled so they are not submitted. Plain JavaScript: jQuery is not loaded on ILL pages.
        (function(){
            var radios = document.querySelectorAll('input[name="fill"]');
            var yes = document.getElementById('seal-fill-fields'), no = document.getElementById('seal-nofill-fields');
            /**
             * Shows or hides one answer section and disables its fields while hidden, so hidden required fields
             * don't block submitting.
             */
            function show(box, on){ box.hidden = !on; box.style.display = on ? '' : 'none'; box.querySelectorAll('input,select,textarea').forEach(function(el){ el.disabled = !on; }); }
            /** Shows the Yes fields or the No fields to match the selected answer. */
            function sync(){
                var v = (document.querySelector('input[name="fill"]:checked') || {}).value;
                show(yes, v !== '0'); show(no, v === '0');
            }
            radios.forEach(function(r){ r.addEventListener('change', sync); });
            sync();
        })();
        </script>
        <?php
        $this->datepicker_js( '#datepicker' );
    }
}
