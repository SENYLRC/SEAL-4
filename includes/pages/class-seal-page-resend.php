<?php
/**
 * SEAL_Page_resend — ILL Admin: re-send one request from what is saved in the database.
 *
 * Enter an ILL number, check the request, then either re-send the request emails (to the lending
 * library and/or a confirmation copy to the borrower) or re-submit it to the lender's ILLiad.
 * Replaces the hand-run scripts cdlc_resend-request.php and cdlc_illiad_manually_resend.php.
 * Administrators only (route capability seal_admin); every action is nonce-checked and logged.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class SEAL_Page_resend extends SEAL_Page_Base {

    /**
     * Entry point: SEAL_Router calls this to print the page into the theme's content area. Looks up the ILL
     * number in num=, handles a posted resend, then shows the request, the ILLiad waiting list and the recent-
     * resends log.
     */
    public function render(): void {
        $self = esc_url( home_url( '/resend' ) );
        $num  = trim( $this->req_str( 'num' ) );
        $req  = $num !== '' ? SEAL_Resend::find( $num ) : null;

        echo '<div class="seal-page-wrap seal-resend">';
        echo '<h3>Resend a Request</h3>';
        echo '<p class="seal-ss-note">Sends an existing request again, using what is saved for it: the request email to the lending library, a copy of the confirmation to the borrower, or the submission to the lender\'s ILLiad.</p>';

        if ( $this->is_post() ) {
            $this->verify_nonce( 'seal_resend' );
            if ( ! $req ) {
                $this->alert( 'No request was found for ' . esc_html( $num ) . '.', 'error' );
            } else {
                $this->handle_post( $req );
                $req = SEAL_Resend::find( (string) $req['illNUB'] ); // show the saved result (e.g. new ILLiad #)
            }
        } elseif ( $num !== '' && ! $req ) {
            $this->alert( 'No request was found for <strong>' . esc_html( $num ) . '</strong>. Enter the ILL number (for example 2026-17815) or the request\'s index number.', 'error' );
        }
        ?>
        <form method="get" action="<?php echo $self; ?>" class="filter-bar">
            <div class="filter-grid">
                <div class="form-group">
                    <label for="resend_num">ILL number</label>
                    <input id="resend_num" type="text" name="num" value="<?php echo esc_attr( $num ); ?>" placeholder="2026-17815" autocomplete="off" required>
                </div>
            </div>
            <div class="filter-actions"><button type="submit" class="btn-primary">Find request</button></div>
        </form>
        <?php
        if ( $req ) { $this->render_request( $req, $self ); }
        $this->render_waiting( $self );
        $this->render_log();
        echo '</div>';
    }

    /**
     * Carries out a posted action for one request: do=emails (lender and/or borrower copy) or do=illiad (send
     * to ILLiad; confirm=1 is required when it already has a transaction number). Every action is logged with
     * SEAL_Resend::log().
     */
    private function handle_post( array $req ): void {
        $ill = (string) $req['illNUB'];
        $do  = $this->post_str( 'do' );

        if ( $do === 'emails' ) {
            $lender    = $this->post_str( 'to_lender' ) === '1';
            $requester = $this->post_str( 'to_requester' ) === '1';
            if ( ! $lender && ! $requester ) {
                $this->alert( 'Choose who should get the email.', 'error' );
                return;
            }
            foreach ( array_filter( [ 'lender' => $lender, 'requester' => $requester ] ) as $who => $_ ) {
                $r = $who === 'lender' ? SEAL_Resend::email_lender( $req ) : SEAL_Resend::email_requester( $req );
                SEAL_Resend::log( $ill, $who === 'lender' ? 'Email to lender' : 'Email to borrower', $r['ok'], $r['msg'] );
                $this->alert( esc_html( $r['msg'] ), $r['ok'] ? 'success' : 'error' );
            }
            return;
        }

        if ( $do === 'illiad' ) {
            $r = SEAL_Resend::send_to_illiad( $req, $this->post_str( 'confirm_dup' ) === '1' );
            SEAL_Resend::log( $ill, 'Send to ILLiad', $r['ok'], $r['msg'] );
            $html = esc_html( $r['msg'] );
            if ( ! empty( $r['detail'] ) ) {
                $html .= '<details style="margin-top:6px"><summary>What ILLiad sent back</summary><pre style="white-space:pre-wrap;max-height:240px;overflow:auto">' . esc_html( $r['detail'] ) . '</pre></details>';
            }
            $this->alert( $html, $r['ok'] ? 'success' : 'error' );
        }
    }

    /**
     * Shows one request's details with the resend-emails form and, for ILLiad lenders, the send-to-ILLiad form
     * (with a duplicate warning when it already has a transaction number).
     */
    private function render_request( array $req, string $self ): void {
        $lib     = SEAL_Resend::lender( $req );
        $illiad  = SEAL_Resend::lender_uses_illiad( $lib );
        $pending = SEAL_Resend::is_pending( $req );
        $txn     = trim( (string) ( $req['IlliadTransID'] ?? '' ) );
        $art     = SEAL_Resend::article_fields( (string) ( $req['article'] ?? '' ) );
        $ill     = (string) $req['illNUB'];
        $rows    = [
            'ILL #'           => $ill,
            'Title'           => (string) ( $req['Title'] ?? '' ) . ( $art['title'] !== '' ? ' — article: ' . $art['title'] : '' ),
            'Author'          => (string) ( $req['Author'] ?? '' ),
            'Borrower'        => trim( ( $req['Requester person'] ?? '' ) . ', ' . ( $req['Requester lib'] ?? '' ) . ' (' . ( $req['Requester LOC'] ?? '' ) . ')', ', ' ),
            'Borrower email'  => (string) ( $req['requesterEMAIL'] ?? '' ),
            'Lending library' => $lib ? $lib['Name'] . ' (' . $lib['loc'] . ')' : ( (string) ( $req['Destination'] ?? '' ) . ' — not in the library directory' ),
            'Lender email'    => $lib ? (string) $lib['ill_email'] : '',
            'Uses ILLiad'     => $illiad ? 'Yes' : 'No',
            'ILLiad #'        => $txn !== '' ? $txn . ( ! empty( $req['IlliadStatus'] ) ? ' (' . $req['IlliadStatus'] . ')' : '' ) : '—',
            'Requested'       => (string) ( $req['Timestamp'] ?? '' ),
        ];
        ?>
        <div class="section-card">
            <h4>Request <?php echo esc_html( $ill ); ?></h4>
            <table class="rh-table seal-resend-summary">
                <tbody>
                <?php foreach ( $rows as $label => $value ) : ?>
                    <tr><th scope="row"><?php echo esc_html( $label ); ?></th><td><?php echo esc_html( $value !== '' ? $value : '—' ); ?></td></tr>
                <?php endforeach; ?>
                    <tr><th scope="row">Status</th><td><?php echo SEAL_Functions::item_status( $req ); // escaped in item_status() ?></td></tr>
                </tbody>
            </table>
            <?php if ( ! $pending ) : ?>
                <div class="seal-alert seal-alert-warning" role="note">This request is no longer waiting for an answer. A lender who gets it again will be asked to fill a request that is already closed.</div>
            <?php endif; ?>
        </div>

        <div class="section-card">
            <h4>Resend the request emails</h4>
            <form method="post" action="<?php echo $self; ?>">
                <?php $this->nonce_field( 'seal_resend' ); ?>
                <input type="hidden" name="num" value="<?php echo esc_attr( $ill ); ?>">
                <input type="hidden" name="do" value="emails">
                <fieldset class="seal-plain-fieldset">
                    <legend class="seal-sr-only">Send the email to</legend>
                    <p><label class="seal-choice-plain"><input type="checkbox" name="to_lender" value="1" <?php checked( $lib && trim( (string) $lib['ill_email'] ) !== '' ); ?> <?php disabled( ! $lib || trim( (string) $lib['ill_email'] ) === '' ); ?>>
                        The lending library<?php echo $lib ? ' — ' . esc_html( $lib['Name'] ) : ''; ?> (the request with Yes / No links)</label></p>
                    <p><label class="seal-choice-plain"><input type="checkbox" name="to_requester" value="1">
                        The borrower (a copy of their confirmation)</label></p>
                </fieldset>
                <p><button type="submit" class="btn-primary">Resend emails</button></p>
            </form>
        </div>

        <div class="section-card">
            <h4>Send to ILLiad</h4>
            <?php if ( ! $illiad ) : ?>
                <p>Not available: <?php echo esc_html( $lib ? $lib['Name'] . ' does not use ILLiad (or its ILLiad URL / API key is missing on its library record).' : 'the lending library is not in the library directory.' ); ?></p>
            <?php else : ?>
            <form method="post" action="<?php echo $self; ?>">
                <?php $this->nonce_field( 'seal_resend' ); ?>
                <input type="hidden" name="num" value="<?php echo esc_attr( $ill ); ?>">
                <input type="hidden" name="do" value="illiad">
                <p>Creates the request in <?php echo esc_html( $lib['Name'] ); ?>'s ILLiad<?php echo $art['title'] !== '' ? ' as an article (photocopy) request' : ' as a loan'; ?> and saves the ILLiad transaction number here.</p>
                <?php if ( $txn !== '' ) : ?>
                    <p><label class="seal-choice-plain"><input type="checkbox" name="confirm_dup" value="1" required aria-describedby="resend_dup_help">
                        Send it anyway</label>
                        <span id="resend_dup_help" class="helper">ILLiad already has transaction <?php echo esc_html( $txn ); ?> for this request; sending again creates a second one.</span></p>
                <?php endif; ?>
                <p><button type="submit" class="btn-primary">Send to ILLiad</button></p>
            </form>
            <?php endif; ?>
        </div>
        <?php
    }

    /** Open requests to ILLiad lenders with no ILLiad transaction: a submission that failed or was skipped. */
    private function render_waiting( string $self ): void {
        $rows = SEAL_Resend::waiting_for_illiad( 60, 50 );
        ?>
        <div class="section-card">
            <h4>Waiting to be sent to ILLiad</h4>
            <p class="seal-ss-note">Open requests from the last 60 days whose lending library uses ILLiad but that have no ILLiad transaction yet — usually a submission that failed, or a request moved by Automatic Fallback after the lender didn't answer.</p>
            <?php if ( ! $rows ) : ?>
                <p>None.</p>
            <?php else : ?>
            <div class="seal-table-wrap"><table class="rh-table">
                <thead><tr><th scope="col">ILL #</th><th scope="col">Title</th><th scope="col">Lender</th><th scope="col">Requested</th><th scope="col"><span class="seal-sr-only">Action</span></th></tr></thead>
                <tbody>
                <?php foreach ( $rows as $r ) : ?>
                    <tr>
                        <td><?php echo esc_html( $r['illNUB'] ); ?></td>
                        <td><?php echo esc_html( $r['Title'] ?? '' ); ?></td>
                        <td><?php echo esc_html( $r['Destination'] ?? '' ); ?></td>
                        <td><?php echo esc_html( $r['Timestamp'] ?? '' ); ?></td>
                        <td><a href="<?php echo esc_url( add_query_arg( 'num', $r['illNUB'], $self ) ); ?>">Open<span class="seal-sr-only"> <?php echo esc_html( $r['illNUB'] ); ?></span></a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <?php endif; ?>
        </div>
        <?php
    }

    /** The last 25 entries of the resend log: when, who, which request and what happened. */
    private function render_log(): void {
        $log = array_slice( array_reverse( (array) get_option( SEAL_Resend::LOG_OPTION, [] ) ), 0, 25 );
        if ( ! $log ) { return; }
        ?>
        <div class="section-card">
            <h4>Recent resends</h4>
            <div class="seal-table-wrap"><table class="rh-table">
                <thead><tr><th scope="col">When</th><th scope="col">Who</th><th scope="col">ILL #</th><th scope="col">What</th><th scope="col">Result</th></tr></thead>
                <tbody>
                <?php foreach ( $log as $e ) : ?>
                    <tr>
                        <td><?php echo esc_html( wp_date( 'Y-m-d H:i', (int) ( $e['time'] ?? 0 ) ) ); ?></td>
                        <td><?php echo esc_html( $e['user'] ?? '' ); ?></td>
                        <td><?php echo esc_html( $e['ill'] ?? '' ); ?></td>
                        <td><?php echo esc_html( $e['action'] ?? '' ); ?></td>
                        <td><?php echo ! empty( $e['ok'] ) ? 'Done' : 'Failed'; ?> — <?php echo esc_html( $e['msg'] ?? '' ); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        </div>
        <?php
    }
}
