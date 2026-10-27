<?php
/**
 * SEAL_Page_bulkaction — /bulkaction: apply one action to every ticked request (capability seal_bulkaction).
 *
 * Posted from the history pages with bulkaction=<code> and check_list[]=<ILL numbers>:
 * - borrower side: 1 Cancel, 2 Renew (no reason asked; /renew asks for one), 3 Receive, 4 Return
 * - lender side:   5 Mark Not Filled, 6 Check Item Back In
 * Every ILL number is checked with authorize_request(), so ticking a request that belongs to another library
 * only produces a "skipped" message. Emails go to the other library where the single-request pages would send
 * one.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class SEAL_Page_bulkaction extends SEAL_Page_Base {
    /** Entry point: SEAL_Router calls this to print the page into the theme's content area. */
    public function render(): void {
        if ( ! $this->is_post() ) {
            echo '<p>No bulk action submitted. <a href="' . esc_url(home_url('/lender-history')) . '">Back to lender history</a>.</p>';
            return;
        }
        $this->verify_nonce('seal_bulkaction');

        $db        = SEAL_DB::get();
        $stat_tbl  = SEAL_DB::$sealSTAT;
        $lib_tbl   = SEAL_DB::$sealLIB;
        $wholename = trim( $this->user->first_name . ' ' . $this->user->last_name );
        $timestamp = current_time('mysql');
        $today     = current_time('Y-m-d');
        $sys       = seal_config('system_name');
        $sysurl    = rtrim( seal_config('system_url'), '/' );

        $action    = $this->req_int('bulkaction', 0);
        $check_list = isset($_POST['check_list']) && is_array($_POST['check_list']) ? $_POST['check_list'] : [];

        if ( empty($check_list) || $action === 0 ) {
            $this->alert('No requests selected for bulk action.','error');
            echo '<p><a href="' . esc_url(home_url('/lender-history')) . '">Back to lender history</a></p>';
            return;
        }

        $messages = [];
        foreach ( $check_list as $raw_id ) {
            $id = sanitize_text_field( wp_unslash($raw_id) );
            if ( ! preg_match('/^[\d\-]+$/', $id) ) { continue; }

            // Cancel, renew, received and return are the borrower's; not-filled and check-in the lender's.
            // Every request is checked, so ticking a number that is not yours does nothing.
            $side = in_array( $action, [ 5, 6 ], true ) ? 'lender' : 'borrower';
            $row  = $this->authorize_request( $id, $side, true );
            if ( ! $row ) {
                $messages[] = ['type'=>'error','msg'=>"ILL #$id was skipped: it is not one of your library's requests, or this action is not yours to take."];
                continue;
            }
            $title = $row['Title']; $req_email = $row['requesterEMAIL']; $dest = $row['Destination'];

            $dest_q = SEAL_DB::query("SELECT Name, ill_email FROM %t WHERE loc=%s LIMIT 1", $lib_tbl, $dest);
            $dest_r = $dest_q ? $dest_q->fetch_assoc() : [];
            $dest_name  = $dest_r['Name']      ?? $dest;
            $dest_email = str_replace(';',',', $dest_r['ill_email'] ?? '');

            switch ($action) {
                case 1: // Cancel
                    SEAL_DB::query("UPDATE %t SET `Fill`='6' WHERE `illNUB`=%s", $stat_tbl, $id);
                    if (SEAL_Rota::active()) { SEAL_Rota::close($id, 'cancelled'); }
                    $messages[] = ['type'=>'success','msg'=>"ILL #$id (" . esc_html($title) . ") — cancelled."];
                    if ($dest_email) {
                        $inst = (string)get_user_meta($this->user->ID,'institution',true);
                        SEAL_Functions::send_email($dest_email, SEAL_Emails::subject('request_cancelled', ['ill' => $id, 'title' => (string)$title]),
                            SEAL_Emails::heading('request_cancelled', $tv = ['ill' => $id, 'title' => (string)$title, 'borrower_library' => $inst])
                            . SEAL_Emails::message('request_cancelled', $tv)
                            . SEAL_Functions::email_details(['ILL #' => $id, 'Title' => (string)$title])
                            . SEAL_Emails::closing('request_cancelled', $tv));
                    }
                    break;
                case 2: // Renew
                    // A new request: clear the earlier answer and notes so it shows as Renew Pending again
                    // (same as /renew). Bulk renewals carry no reason; /renew asks for one.
                    SEAL_DB::query("UPDATE %t SET `renewTimeStamp`=%s, `renewAccountRequester`=%s, `renewNote`='', `renewAnswer`='0', `renewNoteLender`='' WHERE `illNUB`=%s", $stat_tbl, $timestamp, $wholename, $id);
                    $messages[] = ['type'=>'success','msg'=>"ILL #$id — renewal requested."];
                    $inst = (string)get_user_meta($this->user->ID,'institution',true);
                    if ($dest_email) {
                        SEAL_Functions::send_email($dest_email, SEAL_Emails::subject('renew_request', ['ill' => $id, 'title' => (string)$title]),
                            SEAL_Emails::heading('renew_request', $tv = ['ill' => $id, 'title' => (string)$title, 'borrower_library' => $inst])
                            . SEAL_Emails::message('renew_request', $tv)
                            . SEAL_Functions::email_buttons([[add_query_arg(['num' => $id, 'a' => 1], home_url('/renew')), 'Approve renewal', '#0a6b35'],
                                                             [add_query_arg(['num' => $id, 'a' => 2], home_url('/renew')), 'Deny renewal', '#b32d2e']])
                            . SEAL_Functions::email_details(['ILL #' => $id, 'Title' => (string)$title, 'Requested by' => $wholename])
                            . SEAL_Emails::closing('renew_request', $tv));
                    }
                    break;
                case 3: // Received
                    SEAL_DB::query("UPDATE %t SET `receiveTimeStamp`=%s, `receiveAccount`=%s, `receiveDate`=%s WHERE `illNUB`=%s", $stat_tbl, $timestamp, $wholename, $today, $id);
                    $messages[] = ['type'=>'success','msg'=>"ILL #$id — marked received."];
                    break;
                case 4: // Return
                    SEAL_DB::query("UPDATE %t SET `returnTimeStamp`=%s, `returnAccount`=%s, `returnDate`=%s WHERE `illNUB`=%s", $stat_tbl, $timestamp, $wholename, $today, $id);
                    $messages[] = ['type'=>'success','msg'=>"ILL #$id — marked returned."];
                    break;
                case 5: // Not filled
                    SEAL_DB::query("UPDATE %t SET `emailsent`='1', `Fill`='0' WHERE `illNUB`=%s", $stat_tbl, $id);
                    // Enrolled in automatic fallback? Let it move to the next library instead of the
                    // plain "not filled" notice below — same rule respond.php's decline path follows.
                    $advanced = SEAL_Rota::active() ? SEAL_Rota::advance($id, 'declined', '') : ['ok' => false];
                    if (empty($advanced['ok'])) {
                        $messages[] = ['type'=>'warning','msg'=>"ILL #$id — marked not filled."];
                        if ($req_email) {
                            SEAL_Functions::send_email($req_email, SEAL_Emails::subject('request_not_filled', ['ill' => $id, 'title' => (string)$title]),
                                SEAL_Emails::heading('request_not_filled', $tv = ['ill' => $id, 'title' => (string)$title, 'lender_library' => $dest_name])
                                . SEAL_Emails::message('request_not_filled', $tv)
                                . SEAL_Functions::email_details(['ILL #' => $id, 'Title' => (string)$title, 'Lending library' => $dest_name, 'Lender contact*' => SEAL_Functions::email_mailto($dest_email)])
                                . SEAL_Emails::closing('request_not_filled', $tv)
                                . SEAL_Functions::email_button(home_url('/'), 'Search the catalog'));
                        }
                    } else {
                        $messages[] = ['type'=>'warning','msg'=>"ILL #$id — not filled by " . esc_html($dest_name) . "; automatically routed to the next library."];
                    }
                    break;
                case 6: // Check in
                    SEAL_DB::query("UPDATE %t SET `checkinTimeStamp`=%s, `checkinAccount`=%s WHERE `illNUB`=%s", $stat_tbl, $timestamp, $wholename, $id);
                    $messages[] = ['type'=>'success','msg'=>"ILL #$id — checked in."];
                    break;
                default:
                    $messages[] = ['type'=>'error','msg'=>"Unknown action for ILL #$id."];
            }
        }

        echo '<div class="seal-page-wrap"><h3>Bulk Action Results</h3>';
        foreach ($messages as $m) {
            $this->alert($m['msg'], $m['type']);
        }
        echo '<p><a href="' . esc_url(home_url('/lender-history')) . '" class="seal-btn-primary">Back to Lender History</a></p></div>';
    }
}
