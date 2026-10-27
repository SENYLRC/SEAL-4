<?php
/**
 * SEAL_Page_renew — renewals, following SEAL-3 renew.php.
 *
 *   a=0 (or 3)  borrower asks for a renewal — a reason is required; nothing is saved until it is given
 *   a=1         lender approves: new due date (required) + optional note to the borrower
 *   a=2         lender denies: optional note to the borrower
 *   a=4         lender edits the due date of an item already out (no renewal involved)
 *
 * Both notes are kept on the request: renewNote (borrower) and renewNoteLender (lender), and both
 * show under the request on Borrowing / Lending history. renewAnswer: 1 approved, 2 denied (as SEAL-3).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class SEAL_Page_renew extends SEAL_Page_Base {

    private const NOTE_MAX = 255;

    /**
     * Entry point: SEAL_Router calls this to print the page into the theme's content area. Reads a= (0/3
     * borrower asks, 1 approve, 2 deny, 4 edit due date) and num= (ILL number), checks who may act, and runs
     * that step.
     */
    public function render(): void {
        $stat_tbl  = SEAL_DB::$sealSTAT;
        $wholename = trim( $this->user->first_name . ' ' . $this->user->last_name );
        $timestamp = current_time( 'mysql' );
        $sys       = seal_config( 'system_name' );

        $reqnumb = sanitize_text_field( wp_unslash( $_REQUEST['num'] ?? '' ) );
        $action  = sanitize_text_field( wp_unslash( $_REQUEST['a'] ?? '0' ) );
        if ( $action === '3' ) { $action = '0'; } // SEAL-3 used 3 for the borrower's request
        if ( ! in_array( $action, [ '0', '1', '2', '4' ], true ) ) { $action = '0'; }

        if ( $reqnumb === '' ) {
            $this->alert( 'No ILL number was given.', 'error' );
            return;
        }
        // Only the borrowing library may ask; only the lending library (or an admin) may answer or edit.
        if ( ! $this->authorize_request( $reqnumb, $action === '0' ? 'borrower' : 'lender' ) ) { return; }

        $req_q = SEAL_DB::query( "SELECT * FROM %t WHERE `illNUB`=%s LIMIT 1", $stat_tbl, $reqnumb );
        $req   = $req_q ? $req_q->fetch_assoc() : null;
        if ( ! $req ) {
            $this->alert( 'Request ' . esc_html( $reqnumb ) . ' was not found.', 'error' );
            return;
        }

        // The row buttons on the history pages post a= and num= only; a step is completed only when
        // its own form was submitted (marked by `step`), so a note or date can always be entered first.
        $submitted = $this->is_post() && ( $_POST['step'] ?? '' ) === 'save';
        if ( $submitted ) { $this->verify_nonce( 'seal_renew' ); }

        switch ( $action ) {
            case '0': $this->borrower_request( $req, $submitted, $wholename, $timestamp, $sys ); break;
            case '1': $this->lender_answer( $req, true,  $submitted, $wholename, $timestamp, $sys ); break;
            case '2': $this->lender_answer( $req, false, $submitted, $wholename, $timestamp, $sys ); break;
            case '4': $this->edit_due_date( $req, $submitted, $wholename, $timestamp ); break;
        }
    }

    /** A posted note field, sanitized as multi-line text and cut to NOTE_MAX characters. */
    private function note( string $field ): string {
        $v = sanitize_textarea_field( wp_unslash( $_POST[ $field ] ?? '' ) );
        return mb_substr( trim( $v ), 0, self::NOTE_MAX );
    }

    /** True for a real calendar date written exactly as YYYY-MM-DD (rejects e.g. 2026-02-30). */
    private function valid_date( string $d ): bool {
        $dt = DateTime::createFromFormat( 'Y-m-d', $d );
        return $dt && $dt->format( 'Y-m-d' ) === $d;
    }

    // ── Borrower asks for a renewal ───────────────────────────────────────────

    /**
     * Borrower asks for a renewal (a=0). Shows the reason form; once a reason is posted, saves it, clears any
     * earlier answer and emails the lender Approve / Deny links.
     */
    private function borrower_request( array $req, bool $submitted, string $wholename, string $timestamp, string $sys ): void {
        $ill   = (string) $req['illNUB'];
        $note  = $submitted ? $this->note( 'renewNote' ) : '';
        $error = '';

        if ( $submitted && $note === '' ) {
            $error = 'Please give a reason for the renewal.';
        } elseif ( $submitted ) {
            // A new request clears any earlier answer, so it shows as Renew Pending again.
            SEAL_DB::query( "UPDATE %t SET `renewTimeStamp`=%s, `renewAccountRequester`=%s, `renewNote`=%s, `renewAnswer`='0', `renewNoteLender`='' WHERE `illNUB`=%s",
                SEAL_DB::$sealSTAT, $timestamp, $wholename, $note, $ill );

            $dest_q = SEAL_DB::query( "SELECT `ill_email`, `Name` FROM %t WHERE `loc`=%s LIMIT 1", SEAL_DB::$sealLIB, (string) $req['Destination'] );
            $dest   = $dest_q ? $dest_q->fetch_assoc() : null;
            if ( $dest && trim( (string) $dest['ill_email'] ) !== '' ) {
                $inst    = (string) get_user_meta( $this->user->ID, 'institution', true );
                $tv      = [ 'ill' => $ill, 'title' => (string) $req['Title'], 'borrower_library' => $inst ];
                $body    = SEAL_Emails::heading( 'renew_request', $tv )
                         . SEAL_Emails::message( 'renew_request', $tv )
                         . SEAL_Functions::email_buttons( [ [ add_query_arg( [ 'num' => $ill, 'a' => 1 ], home_url( '/renew' ) ), 'Approve renewal', '#0a6b35' ],
                                                            [ add_query_arg( [ 'num' => $ill, 'a' => 2 ], home_url( '/renew' ) ), 'Deny renewal', '#b32d2e' ] ] )
                         . SEAL_Functions::email_details( [
                               'ILL #'            => $ill,
                               'Title'            => (string) $req['Title'],
                               'Current due date' => SEAL_Functions::email_date( (string) ( $req['DueDate'] ?? '' ) ),
                               'Reason given'     => $note,
                               'Requested by'     => $wholename,
                           ] );
                $body .= SEAL_Emails::closing( 'renew_request', $tv );
                SEAL_Functions::send_email( str_replace( ';', ',', (string) $dest['ill_email'] ), SEAL_Emails::subject( 'renew_request', [ 'ill' => $ill, 'title' => (string) $req['Title'] ] ), $body );
            }
            $this->alert( 'Renewal request submitted for ILL# ' . esc_html( $ill ) . '. ' . esc_html( $dest['Name'] ?? 'The lending library' ) . ' has been asked to approve it.', 'success' );
            echo '<p><a href="' . esc_url( home_url( '/requesthistory' ) ) . '">Back to Borrowing Requests</a></p>';
            return;
        }

        $this->form_open( 'Request a Renewal — ILL# ' . $ill, $req, '0', $error );
        ?>
            <div class="form-group">
                <label for="renewNote">Reason for the renewal <span aria-hidden="true">*</span></label>
                <textarea id="renewNote" name="renewNote" rows="5" maxlength="<?php echo (int) self::NOTE_MAX; ?>" required aria-required="true" aria-describedby="renewNote_help"><?php echo esc_textarea( $note ); ?></textarea>
                <span id="renewNote_help" class="helper">Sent to the lending library with the request. Up to <?php echo (int) self::NOTE_MAX; ?> characters.</span>
            </div>
            <p><button type="submit" class="btn-primary">Request Renewal</button>
               <a class="btn-secondary" href="<?php echo esc_url( home_url( '/requesthistory' ) ); ?>">Cancel</a></p>
        <?php
        $this->form_close();
    }

    // ── Lender approves or denies ─────────────────────────────────────────────

    /**
     * Lender approves ($approve = true: new due date required) or denies a renewal, with an optional note.
     * Shows the borrower's reason, saves the answer and emails the borrower.
     */
    private function lender_answer( array $req, bool $approve, bool $submitted, string $wholename, string $timestamp, string $sys ): void {
        $ill     = (string) $req['illNUB'];
        $note    = $submitted ? $this->note( 'renewNoteLender' ) : '';
        $duedate = $submitted ? sanitize_text_field( wp_unslash( $_POST['duedate'] ?? '' ) ) : '';
        $error   = '';

        if ( $submitted && $approve && ! $this->valid_date( $duedate ) ) {
            $error = 'Please enter the new due date (YYYY-MM-DD).';
        } elseif ( $submitted ) {
            if ( $approve ) {
                SEAL_DB::query( "UPDATE %t SET `renewNoteLender`=%s, `renewAnswer`='1', `renewTimeStamp`=%s, `renewAccountLender`=%s, `DueDate`=%s WHERE `illNUB`=%s",
                    SEAL_DB::$sealSTAT, $note, $timestamp, $wholename, $duedate, $ill );
            } else {
                SEAL_DB::query( "UPDATE %t SET `renewNoteLender`=%s, `renewAnswer`='2', `renewTimeStamp`=%s, `renewAccountLender`=%s WHERE `illNUB`=%s",
                    SEAL_DB::$sealSTAT, $note, $timestamp, $wholename, $ill );
            }
            $tk   = $approve ? 'renew_approved' : 'renew_denied';
            $tv   = [ 'ill' => $ill, 'title' => (string) $req['Title'] ];
            $body = SEAL_Emails::heading( $tk, $tv )
                  . SEAL_Emails::message( $tk, $tv )
                  . SEAL_Functions::email_details( [
                        'ILL #'                => $ill,
                        'Title'                => (string) $req['Title'],
                        ( $approve ? 'New due date' : 'Due date' ) => SEAL_Functions::email_date( $approve ? $duedate : (string) ( $req['DueDate'] ?? '' ) ),
                        'Note from the lender' => $note,
                    ] )
                  . SEAL_Functions::email_button( home_url( '/requesthistory' ), 'View your borrowing requests' )
                  . SEAL_Emails::closing( $tk, $tv );
            SEAL_Functions::send_email( (string) $req['requesterEMAIL'], SEAL_Emails::subject( $approve ? 'renew_approved' : 'renew_denied', [ 'ill' => $ill, 'title' => (string) $req['Title'] ] ), $body );

            $this->alert( $approve
                ? 'Renewal approved for ILL# ' . esc_html( $ill ) . '. New due date: ' . esc_html( $duedate ) . '. The borrower has been emailed.'
                : 'Renewal denied for ILL# ' . esc_html( $ill ) . '. The borrower has been emailed.', 'success' );
            echo '<p><a href="' . esc_url( home_url( '/lender-history' ) ) . '">Back to Lending Requests</a></p>';
            return;
        }

        $this->form_open( ( $approve ? 'Approve' : 'Deny' ) . ' Renewal — ILL# ' . $ill, $req, $approve ? '1' : '2', $error );
        if ( trim( (string) ( $req['renewNote'] ?? '' ) ) !== '' ) {
            echo '<div class="seal-renew-borrower-note"><strong>Borrower note:</strong><br>' . nl2br( esc_html( (string) $req['renewNote'] ) ) . '</div>';
        }
        if ( $approve ) : ?>
            <div class="form-group">
                <label for="datepicker">New due date <span aria-hidden="true">*</span></label>
                <input id="datepicker" name="duedate" required aria-required="true" autocomplete="off" placeholder="YYYY-MM-DD" value="<?php echo esc_attr( $duedate ); ?>" aria-describedby="duedate_help">
                <span id="duedate_help" class="helper">Current due date: <?php echo esc_html( (string) ( $req['DueDate'] ?? '—' ) ); ?>. Format YYYY-MM-DD; a date picker opens on the field.</span>
            </div>
        <?php endif; ?>
            <div class="form-group">
                <label for="renewNoteLender"><?php echo $approve ? 'Note to the borrower (optional)' : 'Reason for denying (optional)'; ?></label>
                <textarea id="renewNoteLender" name="renewNoteLender" rows="5" maxlength="<?php echo (int) self::NOTE_MAX; ?>" aria-describedby="renewNoteLender_help"><?php echo esc_textarea( $note ); ?></textarea>
                <span id="renewNoteLender_help" class="helper">Emailed to the borrower. Up to <?php echo (int) self::NOTE_MAX; ?> characters.</span>
            </div>
            <p><button type="submit" class="<?php echo $approve ? 'btn-primary' : 'btn-danger'; ?>"><?php echo $approve ? 'Approve Renewal' : 'Deny Renewal'; ?></button>
               <a class="btn-secondary" href="<?php echo esc_url( home_url( '/lender-history' ) ); ?>">Cancel</a></p>
        <?php
        $this->form_close();
        if ( $approve ) { $this->datepicker_js( '#datepicker' ); }
    }

    // ── Lender edits the due date (no renewal) ────────────────────────────────

    /**
     * Lender changes the due date of an item already out, without a renewal (a=4). Emails the borrower the new
     * date.
     */
    private function edit_due_date( array $req, bool $submitted, string $wholename, string $timestamp ): void {
        $ill     = (string) $req['illNUB'];
        $duedate = $submitted ? sanitize_text_field( wp_unslash( $_POST['duedate'] ?? '' ) ) : '';
        $error   = '';

        if ( $submitted && ! $this->valid_date( $duedate ) ) {
            $error = 'Please enter the new due date (YYYY-MM-DD).';
        } elseif ( $submitted ) {
            SEAL_DB::query( "UPDATE %t SET `renewTimeStamp`=%s, `renewAccountLender`=%s, `DueDate`=%s WHERE `illNUB`=%s",
                SEAL_DB::$sealSTAT, $timestamp, $wholename, $duedate, $ill );
            $this->alert( 'Due date for ILL# ' . esc_html( $ill ) . ' changed to ' . esc_html( $duedate ) . '.', 'success' );
            echo '<p><a href="' . esc_url( home_url( '/lender-history' ) ) . '">Back to Lending Requests</a></p>';
            return;
        }

        $this->form_open( 'Edit Due Date — ILL# ' . $ill, $req, '4', $error );
        ?>
            <div class="form-group">
                <label for="datepicker">New due date <span aria-hidden="true">*</span></label>
                <input id="datepicker" name="duedate" required aria-required="true" autocomplete="off" placeholder="YYYY-MM-DD" value="<?php echo esc_attr( $duedate ); ?>" aria-describedby="duedate_help">
                <span id="duedate_help" class="helper">Current due date: <?php echo esc_html( (string) ( $req['DueDate'] ?? '—' ) ); ?>. Format YYYY-MM-DD.</span>
            </div>
            <p><button type="submit" class="btn-primary">Save Due Date</button>
               <a class="btn-secondary" href="<?php echo esc_url( home_url( '/lender-history' ) ); ?>">Cancel</a></p>
        <?php
        $this->form_close();
        $this->datepicker_js( '#datepicker' );
    }

    // ── Shared form frame ─────────────────────────────────────────────────────

    /**
     * Opens the shared form frame: title, the item and its due date, any error, the nonce and the hidden a /
     * num / step fields.
     */
    private function form_open( string $title, array $req, string $action, string $error ): void {
        echo '<div class="seal-page-wrap"><div class="section-card seal-renew">';
        echo '<h3>' . esc_html( $title ) . '</h3>';
        echo '<p class="seal-renew-item"><strong>' . esc_html( (string) $req['Title'] ) . '</strong>'
           . ( ! empty( $req['Author'] ) ? ' — ' . esc_html( (string) $req['Author'] ) : '' )
           . ( ! empty( $req['DueDate'] ) ? '<br>Due: ' . esc_html( (string) $req['DueDate'] ) : '' ) . '</p>';
        if ( $error !== '' ) { $this->alert( esc_html( $error ), 'error' ); }
        echo '<form method="post" action="' . esc_url( home_url( '/renew' ) ) . '" novalidate>';
        $this->nonce_field( 'seal_renew' );
        echo '<input type="hidden" name="a" value="' . esc_attr( $action ) . '">'
           . '<input type="hidden" name="num" value="' . esc_attr( (string) $req['illNUB'] ) . '">'
           . '<input type="hidden" name="step" value="save">';
    }

    /** Closes the form and wrappers opened by form_open(). */
    private function form_close(): void {
        echo '</form></div></div>';
    }
}
