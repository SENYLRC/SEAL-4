<?php
/**
 * SEAL_Page_Request — ILL Request submission form.
 *
 * Ported from request.php. Security improvements:
 *  - All DB writes use SEAL_DB parameterised queries.
 *  - CSRF via wp_nonce.
 *  - No shell_exec / direct curl for XML — uses wp_remote_get() instead.
 *  - All output escaped with esc_html() / esc_attr() / wp_kses().
 *  - DB credentials come from wp_options, not a flat .inc file.
 *  - ILLiad API key never echoed to page.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class SEAL_Page_Request extends SEAL_Page_Base {

    private string $sys_host = '';
    private string $jsessionid = '';
    private string $windowid   = '';
    private string $record_id  = '';

    // Bib data from Z39.50 record
    private string $req_title  = '';
    private string $req_title2 = '';
    private string $req_author = '';
    private string $item_type  = '';
    private string $pub_date   = '';
    private string $isbn       = '';
    private string $issn       = '';

    // Requester profile
    private string $primary_loc      = '';
    private string $selected_loc     = '';
    private array  $all_locs         = [];
    private array  $profile          = [];
    private string $field_institution = '';
    private string $field_req_system  = '';
    private string $field_street1     = '';
    private string $field_street2     = '';
    private string $field_city_st_zip = '';

    /**
     * Entry point: SEAL_Router calls this to print the page into the theme's content area. Without a submitted
     * request it shows the item and the libraries that can lend it (from the catalog record and Org Rules);
     * when the form is posted it saves and sends the request (process_submission()).
     */
    public function render(): void {
        $this->sys_host = sanitize_text_field( $_SERVER['SERVER_NAME'] ?? '' );

        // ── Resolve requester LOC codes ──────────────────────────────────────
        $uid             = $this->user->ID;
        $this->primary_loc = SEAL_Functions::primary_loc( $uid );
        $extra_locs        = SEAL_Functions::extra_locs( $uid );
        $this->all_locs    = SEAL_Functions::all_locs( $uid );
        $has_multi         = count( $this->all_locs ) > 1;

        // Selected LOC from request
        $requested_loc = strtoupper( trim( $this->req_str( 'requester_loc_select' ) ) );
        if ( $requested_loc === '' || ! in_array( $requested_loc, $this->all_locs, true ) ) {
            $this->selected_loc = $this->all_locs[0] ?? $this->primary_loc;
        } else {
            $this->selected_loc = $requested_loc;
        }

        // Fetch lib profile
        $this->profile = SEAL_Functions::fetch_lib_profile( $this->selected_loc );
        $this->field_institution = $this->profile['ok'] ? $this->profile['Name']     : $this->selected_loc;
        $this->field_req_system  = $this->profile['ok'] ? $this->profile['system']   : '';
        $this->field_street1     = $this->profile['ok'] ? $this->profile['street1']  : '';
        $this->field_street2     = $this->profile['ok'] ? $this->profile['street2']  : '';
        $this->field_city_st_zip = $this->profile['ok'] ? $this->profile['citystzip']: '';

        // ── Fetch bib record from Z39.50 gateway ─────────────────────────────
        $jsessionid = sanitize_text_field( $this->get_str( 'jsessionid' ) );
        $windowid   = sanitize_text_field( $this->get_str( 'windowid' ) );
        $idc        = sanitize_text_field( $this->get_str( 'id' ) );
        $this->jsessionid = $jsessionid; $this->windowid = $windowid; $this->record_id = $idc;

        $gateway_url = seal_config( 'gateway_url' );
        $record_url  = add_query_arg( [
            'command'  => 'record',
            'windowid' => $windowid,
            'id'       => $idc,
        ], $gateway_url );

        $response = wp_remote_get( $record_url, [
            'headers'     => [ 'Cookie' => 'JSESSIONID=' . $jsessionid ],
            'timeout'     => 8,
            'redirection' => 3,
            'sslverify'   => true,
        ] );

        $xml_body = '';
        if ( is_wp_error( $response ) ) {
            error_log( 'ILL request: bib record fetch failed (' . $record_url . '): ' . $response->get_error_message() );
        } else {
            $code = wp_remote_retrieve_response_code( $response );
            if ( (int) $code !== 200 ) {
                error_log( 'ILL request: bib record fetch returned HTTP ' . $code . ' from ' . $record_url );
            }
            $xml_body = wp_remote_retrieve_body( $response );
        }

        $records = $xml_body ? $this->parse_xml( $xml_body ) : null;

        if ( $records ) {
            $this->req_title  = esc_html( (string) $records->{'md-title-complete'} );
            $this->req_title2 = esc_html( (string) $records->{'md-title-number-section'} );
            $this->req_author = esc_html( (string) $records->{'md-author'} );
            $this->item_type  = trim( (string) $records->{'md-medium'} );
            $this->pub_date   = esc_html( (string) $records->{'md-date'} );
            $this->isbn       = esc_html( (string) $records->{'md-isbn'} );
            $this->issn       = esc_html( (string) ( $records->location->{'md-issn'} ?? '' ) );
        }

        // ── Handle form submission ────────────────────────────────────────────
        $library_error    = false;
        $submission_success = false;

        if ( $this->is_post() && ! isset( $_POST['switch_profile'] ) ) {
            $this->verify_nonce( 'seal_request' );
            if ( empty( $_POST['libdestination'] ) || ! is_array( $_POST['libdestination'] ) ) {
                $library_error = true;
            } else {
                $this->process_submission();
                return; // process_submission() handles output and returns
            }
        }

        // ── Render form ───────────────────────────────────────────────────────
        $this->enqueue_assets();
        $this->render_form( $has_multi, $library_error, $records );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Form output
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * The request form: the item, the libraries that can lend it (render_library_list()), the Automatic
     * Fallback picker, need-by date, notes and article fields.
     */
    private function render_form( bool $has_multi, bool $library_error, ?SimpleXMLElement $records ): void {
        $uid          = $this->user->ID;
        $first_name   = esc_html( get_user_meta( $uid, 'first_name', true ) );
        $last_name    = esc_html( get_user_meta( $uid, 'last_name',  true ) );
        $email        = esc_attr( $this->user->user_email );
        $work_phone   = esc_attr( (string) ( get_user_meta( $uid, 'work_phone', true ) ?: get_user_meta( $uid, 'phone', true ) ) );
        $self_url     = esc_url( SEAL_Functions::self_url() );
        ?>
        <div class="seal-request seal-req">
            <p class="seal-req-intro">Please review the details of your request, then choose a library to send it to.</p>

            <?php if ( $library_error ): ?>
            <div class="seal-alert seal-alert-error" role="alert">
                Please select at least one library to send your request to.
            </div>
            <?php endif; ?>

            <form action="<?php echo $self_url; ?>" method="post" novalidate>
                <?php $this->nonce_field( 'seal_request' ); ?>

                <section class="section-card seal-req-card" aria-labelledby="req-h-requester">
                    <h4 id="req-h-requester">Requester Details</h4>

                    <?php if ( $has_multi ): ?>
                    <div class="form-group seal-req-profile">
                        <label for="requester_loc_select">Use library profile</label>
                        <select id="requester_loc_select" name="requester_loc_select" aria-describedby="requester_loc_help">
                            <?php foreach ( $this->all_locs as $lc ):
                                $label = ( $lc === $this->selected_loc )
                                    ? esc_html( $this->field_institution . " ($lc)" )
                                    : esc_html( $lc );
                            ?>
                            <option value="<?php echo esc_attr( $lc ); ?>"
                                <?php selected( $lc, $this->selected_loc ); ?>><?php echo $label; ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" name="switch_profile" value="1" class="btn-secondary" formnovalidate>Use this profile</button>
                        <p class="seal-req-hint" id="requester_loc_help">Choose a library, then select the button. The page reloads with that library's details.</p>
                    </div>
                    <?php else: ?>
                    <input type="hidden" name="requester_loc_select" value="<?php echo esc_attr( $this->selected_loc ); ?>">
                    <?php endif; ?>

                    <dl class="seal-facts">
                        <div><dt>Name</dt><dd><?php echo "$first_name $last_name"; ?></dd></div>
                        <div><dt>Email</dt><dd><?php echo esc_html( $this->user->user_email ); ?></dd></div>
                        <div><dt>Institution</dt><dd><?php echo esc_html( $this->field_institution ); ?></dd></div>
                        <div><dt>Work phone</dt><dd><?php echo esc_html( (string) ( get_user_meta( $uid, 'work_phone', true ) ?: get_user_meta( $uid, 'phone', true ) ) ); ?></dd></div>
                        <div class="seal-fact-wide">
                            <dt>Mailing address</dt>
                            <dd>
                            <?php if ( trim( $this->field_street1 . $this->field_city_st_zip ) === '' ): ?>
                                <em class="seal-grayout">No mailing address on file for <?php echo esc_html( $this->selected_loc ); ?>.</em>
                            <?php else: ?>
                                <?php echo esc_html( $this->field_street1 ); ?><br>
                                <?php if ( $this->field_street2 ): echo esc_html( $this->field_street2 ) . '<br>'; endif; ?>
                                <?php echo esc_html( $this->field_city_st_zip ); ?>
                            <?php endif; ?>
                            </dd>
                        </div>
                    </dl>
                </section>

                <!-- Hidden requester fields -->
                <input type="hidden" name="fname"       value="<?php echo esc_attr( (string) get_user_meta( $uid, 'first_name', true ) ); ?>">
                <input type="hidden" name="lname"       value="<?php echo esc_attr( (string) get_user_meta( $uid, 'last_name',  true ) ); ?>">
                <input type="hidden" name="email"       value="<?php echo esc_attr( $this->user->user_email ); ?>">
                <input type="hidden" name="inst"        value="<?php echo esc_attr( $this->field_institution ); ?>">
                <input type="hidden" name="address"     value="<?php echo esc_attr( $this->field_street1 ); ?>">
                <input type="hidden" name="address2"    value="<?php echo esc_attr( $this->field_street2 ); ?>">
                <input type="hidden" name="caddress"    value="<?php echo esc_attr( $this->field_city_st_zip ); ?>">
                <input type="hidden" name="wphone"      value="<?php echo esc_attr( (string) ( get_user_meta( $uid, 'work_phone', true ) ?: get_user_meta( $uid, 'phone', true ) ) ); ?>">
                <input type="hidden" name="reqLOCcode"  value="<?php echo esc_attr( $this->selected_loc ); ?>">
                <input type="hidden" name="reqsystem"   value="<?php echo esc_attr( $this->field_req_system ); ?>">

                <section class="section-card seal-req-card" aria-labelledby="req-h-details">
                    <h4 id="req-h-details">Request Details</h4>
                    <div class="seal-req-grid">
                        <div class="form-group">
                            <label for="needbydate">Need by date</label>
                            <input type="text" id="needbydate" name="needbydate" autocomplete="off" placeholder="YYYY-MM-DD"
                                   aria-describedby="needby-help">
                            <span class="helper" id="needby-help">Optional. Format YYYY-MM-DD.</span>
                        </div>
                        <div class="form-group">
                            <label for="patronnote">Patron name or barcode <span class="seal-optional">(optional)</span></label>
                            <input type="text" id="patronnote" name="patronnote" maxlength="255" aria-describedby="patron-help">
                            <span class="helper" id="patron-help">Please follow your local policies regarding patron privacy.</span>
                        </div>
                        <div class="form-group seal-req-wide">
                            <label for="reqnote">Note</label>
                            <input type="text" id="reqnote" name="reqnote" maxlength="255">
                        </div>

                        <div class="seal-req-wide seal-req-article">
                        <fieldset>
                            <legend>Is this a request for an article?</legend>
                            <div class="seal-choices">
                                <label class="seal-choice" for="yesCheck">
                                    <input type="radio" name="yesno" id="yesCheck" onclick="sealYesNoCheck();"> Yes
                                </label>
                                <label class="seal-choice" for="noCheck">
                                    <input type="radio" name="yesno" id="noCheck" checked onclick="sealYesNoCheck();"> No
                                </label>
                            </div>
                            <div id="ifYes" class="seal-req-grid seal-req-articlefields" style="display:none;">
                                <div class="form-group seal-req-wide"><label for="arttile">Article title</label><input type="text" id="arttile" name="arttile"></div>
                                <div class="form-group seal-req-wide"><label for="artauthor">Article author</label><input type="text" id="artauthor" name="artauthor"></div>
                                <div class="form-group"><label for="artvolume">Volume</label><input type="text" id="artvolume" name="artvolume"></div>
                                <div class="form-group"><label for="artissue">Issue</label><input type="text" id="artissue" name="artissue"></div>
                                <div class="form-group"><label for="artpage">Pages</label><input type="text" id="artpage" name="artpage"></div>
                                <div class="form-group"><label for="artmonth">Issue month</label><input type="text" id="artmonth" name="artmonth"></div>
                                <div class="form-group"><label for="artyear">Issue year</label><input type="text" id="artyear" name="artyear"></div>
                                <div class="form-group">
                                    <label for="artcopyright">Copyright compliance</label>
                                    <select id="artcopyright" name="artcopyright">
                                        <option value=""></option>
                                        <option value="ccl">CCL</option>
                                        <option value="ccg">CCG</option>
                                    </select>
                                </div>
                            </div>
                        </fieldset>
                        </div>
                    </div>
                </section>

                <section class="section-card seal-req-card" aria-labelledby="req-h-bib">
                    <h4 id="req-h-bib">Bibliographic Details</h4>
                    <dl class="seal-facts">
                        <div class="seal-fact-wide"><dt>Requested title</dt><dd><?php echo esc_html( trim( $this->req_title . ' ' . $this->req_title2 ) ?: '—' ); ?></dd></div>
                        <div><dt>Author</dt><dd><?php echo esc_html( $this->req_author ?: '—' ); ?></dd></div>
                        <div><dt>Item type</dt><dd><?php echo esc_html( $this->item_type ?: '—' ); ?></dd></div>
                        <div><dt>Publication date</dt><dd><?php echo esc_html( $this->pub_date ?: '—' ); ?></dd></div>
                        <?php if ( $this->isbn ): ?><div><dt>ISBN</dt><dd><?php echo esc_html( $this->isbn ); ?></dd></div><?php endif; ?>
                        <?php if ( $this->issn ): ?><div><dt>ISSN</dt><dd><?php echo esc_html( $this->issn ); ?></dd></div><?php endif; ?>
                    </dl>
                </section>

                <!-- Hidden bib fields -->
                <input type="hidden" name="bibtitle"  value="<?php echo esc_attr( $this->req_title . ' ' . $this->req_title2 ); ?>">
                <input type="hidden" name="bibauthor" value="<?php echo esc_attr( $this->req_author ); ?>">
                <input type="hidden" name="bibtype"   value="<?php echo esc_attr( $this->item_type ); ?>">
                <input type="hidden" name="pubdate"   value="<?php echo esc_attr( $this->pub_date ); ?>">
                <input type="hidden" name="isbn"      value="<?php echo esc_attr( $this->isbn ); ?>">
                <input type="hidden" name="issn"      value="<?php echo esc_attr( $this->issn ); ?>">

                <section class="section-card seal-req-card" aria-labelledby="req-h-lib">
                    <h4 id="req-h-lib">Library Selection</h4>
                    <fieldset class="seal-req-copies">
                        <legend>This is a request for</legend>
                        <div class="seal-choices">
                            <label class="seal-choice" for="singleCheck">
                                <input type="radio" name="singlemulti" id="singleCheck" checked onclick="sealMultiRequest();"> A single copy
                            </label>
                            <label class="seal-choice" for="multiCheck">
                                <input type="radio" name="singlemulti" id="multiCheck" onclick="sealMultiRequest();"> Multiple copies
                            </label>
                        </div>
                        <p class="helper">Please limit multiple-copy requests to classroom sets or book clubs.</p>
                    </fieldset>

                    <?php $offered = $this->render_library_list( $records ); ?>

                    <?php if ( $offered > 0 ): ?>
                    <div class="seal-req-submit">
                        <button type="submit" class="seal-btn-primary seal-req-submit-btn">Submit Request</button>
                        <span class="helper">A confirmation is emailed to you.</span>
                    </div>
                    <?php endif; ?>
                </section>
            </form>
        </div>

        <script>
        document.addEventListener('DOMContentLoaded', function() {
            sealMultiRequest();
        });
        /**
         * Switches the library list between single-choice (radio) and multi-library (checkbox) mode, clearing
         * ticks when switching.
         */
        function sealMultiRequest() {
            var multi = document.getElementById('multiCheck') && document.getElementById('multiCheck').checked;
            document.querySelectorAll('.seal-lib-check').forEach(function(el){ el.checked = false; });
            document.querySelectorAll('.seal-multi').forEach(function(el){ el.style.display = multi ? 'block' : 'none'; });
            document.querySelectorAll('.seal-single').forEach(function(el){ el.style.display = multi ? 'none' : 'block'; });
        }
        /** Shows the extra fields under a Yes/No question only while Yes is ticked. */
        function sealYesNoCheck() {
            var yes = document.getElementById('yesCheck') && document.getElementById('yesCheck').checked;
            var d = document.getElementById('ifYes');
            if (d) d.style.display = yes ? 'block' : 'none';
        }
        </script>
        <?php
        $this->datepicker_js( '#needbydate' );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Library list (rendered from XML location records)
    // ─────────────────────────────────────────────────────────────────────────

    /** Parse gateway XML defensively (no DOCTYPE, no network access). */
    private function parse_xml( string $xml ): ?SimpleXMLElement {
        if ( stripos( $xml, '<!DOCTYPE' ) !== false ) { return null; }
        libxml_use_internal_errors( true );
        try {
            return new SimpleXMLElement( $xml, LIBXML_NONET );
        } catch ( Exception $e ) {
            return null;
        }
    }

    /** Full MARC holdings for one catalog location (needs the same search session). */
    private function fetch_marc( string $checksum ): ?SimpleXMLElement {
        if ( $checksum === '' ) { return null; }
        $url = add_query_arg( [
            'command'  => 'record',
            'windowid' => $this->windowid,
            'id'       => $this->record_id,
            'checksum' => $checksum,
            'offset'   => 1,
        ], seal_config( 'gateway_url' ) );
        $resp = wp_remote_get( $url, [
            'headers' => [ 'Cookie' => 'JSESSIONID=' . $this->jsessionid ],
            'timeout' => 8, 'redirection' => 3, 'sslverify' => true,
        ] );
        if ( is_wp_error( $resp ) || (int) wp_remote_retrieve_response_code( $resp ) !== 200 ) {
            error_log( 'ILL request: MARC holdings fetch failed' );
            return null;
        }
        return $this->parse_xml( wp_remote_retrieve_body( $resp ) );
    }

    /**
     * Turn the catalog record's holdings into lender choices, using the rules under ILL → Org Rules
     * (ported from the original request.php). Returns how many libraries can be chosen.
     */
    private function render_library_list( ?SimpleXMLElement $records ): int {
        if ( ! $records ) {
            echo '<p>Unable to load library availability data.</p>';
            return 0;
        }

        $uid        = $this->user->ID;
        $req_system = (string) get_user_meta( $uid, 'home_system', true );
        if ( $req_system === '' ) { $req_system = $this->field_req_system; }
        $filter_own = get_user_meta( $uid, 'filter_own_system', true ) === '1';
        $item_type  = $this->item_type;

        $offers = [];   // libraries that can be chosen
        $greyed = [];   // libraries shown greyed-out with the reason
        $trace  = [];   // every holding and what happened to it (administrators, ?ill_debug=1)
        $consider = function ( string $catalog, string $cat_name, string $alias, bool $avail_ok, string $avail_text, string $call, string $holding_loc ) use ( &$offers, &$greyed, &$trace, $item_type, $req_system, $filter_own ) {
            $r = SEAL_Org_Rules::evaluate( [
                'catalog' => $catalog, 'catalog_name' => $cat_name, 'alias' => $alias, 'avail_ok' => $avail_ok,
                'item_type' => $item_type, 'holding_location' => $holding_loc,
                'requester_system' => $req_system, 'filter_own' => $filter_own,
            ] );
            $sys_label = SEAL_Functions::system_name( $r['system'] );
            $trace[] = [
                'catalog_name' => $cat_name, 'catalog' => $catalog, 'holding' => $holding_loc, 'alias' => $alias,
                'available' => $avail_text, 'library' => $r['name'], 'system' => $r['system'],
                'result' => [ 0 => 'Offered', 1 => 'Hidden', 2 => 'Greyed out' ][ $r['fail'] ] ?? '', 'reason' => $r['message'],
            ];
            if ( $r['fail'] === 0 ) {
                $clean = static fn( string $v ): string => str_replace( ':', ' ', $v );
                $offers[] = [
                    'name' => $r['name'], 'system' => $sys_label, 'avail' => $avail_text, 'call' => $call,
                    'loc' => $r['loc'], 'alias' => $alias,
                    'value' => implode( ':', [ $clean( $alias ), $clean( $r['name'] ), $clean( $r['system'] ), $clean( $avail_text ), $clean( $call ), $clean( $alias ), $clean( $r['email'] ), $clean( $r['loc'] ) ] ),
                ];
            } elseif ( $r['fail'] === 2 ) {
                $greyed[] = trim( $r['name'] . ' (' . $sys_label . '), ' . $r['message'] );
            }
        };

        foreach ( $records->location as $location ) {
            $cat_name = (string) ( $location['name'] ?? '' );
            $cat_type = SEAL_Org_Rules::find_catalog( $cat_name );

            foreach ( $location->holdings->holding as $holding ) {
                $local = (string) $holding->localLocation;
                $avail = SEAL_Org_Rules::normalize_availability( (string) $holding->localAvailability );
                if ( ! SEAL_Org_Rules::holding_allowed( $cat_name, $local ) ) { $avail = 0; }
                $alias = SEAL_Org_Rules::holding_alias( $cat_type, $cat_name, $local );
                $consider( $cat_type, $cat_name, $alias, $avail === 1, SEAL_Org_Rules::availability_text( $avail ), (string) $holding->callNumber, $local );
            }

            // Catalogs whose real holdings live in the MARC record (Koha, Alexandria, Albany Law, ...)
            $rule = SEAL_Org_Rules::extra_rule_for( $cat_type, $cat_name );
            if ( $rule ) {
                $marc = $this->fetch_marc( (string) ( $location['checksum'] ?? '' ) );
                if ( $marc ) {
                    foreach ( $marc->{ $rule['field'] } as $f ) {
                        $alias = trim( (string) $f->{ $rule['loc'] } );
                        if ( $alias === '' ) { continue; }
                        if ( str_starts_with( $rule['call'], '@' ) ) {
                            $node = $marc;
                            foreach ( explode( '.', substr( $rule['call'], 1 ) ) as $step ) { $node = $node->{ $step }; }
                            $call = (string) $node;
                        } else {
                            $call = (string) $f->{ $rule['call'] };
                        }
                        $call = str_replace( ':', '.', $call );
                        $sub  = $rule['alt'][ $cat_name ] ?? $rule['avail'];
                        if ( $sub === '-' || $sub === '' ) {
                            $av = [ 'text' => 'Available', 'ok' => true ];
                        } else {
                            $av = SEAL_Org_Rules::koha_availability( (string) $f->{ $sub } );
                        }
                        $consider( $cat_type, $cat_name, $alias, $av['ok'], $av['text'], $call, $alias );
                    }
                }
            }
        }

        $rota_active  = SEAL_Rota::active() && count( $offers ) > 1;
        $rota_picks   = max( 0, SEAL_Rota::chain_max() - 1 ); // slots left after the borrower's own pick

        if ( $rota_active ) {
            $rota_list = array_map(
                static fn( array $o ): array => [ 'loc' => $o['loc'], 'avail' => $o['avail'], 'call' => $o['call'], 'alias' => $o['alias'] ],
                $offers
            );
            $plural = $rota_picks === 1 ? 'library' : 'libraries';
            echo '<div class="seal-single seal-rota-opt">'
               . '<label class="seal-choice-plain"><input type="checkbox" name="rota_enabled" value="1" id="seal-rota-enabled" aria-describedby="seal-rota-help"> '
               . '<span><strong>Automatic fallback:</strong> if the library I choose below can\'t fill it, send it on to '
               . ( $rota_picks > 0 ? 'up to ' . (int) $rota_picks . ' backup ' . $plural : 'the next available library' ) . ' automatically</span></label>'
               . '<p class="seal-rota-help description" id="seal-rota-help">'
               . ( $rota_picks > 0
                   ? 'After ticking this, choose your main library, then tick up to ' . (int) $rota_picks . ' backup ' . $plural
                     . '. If you don\'t tick any, the next available ' . ( $rota_picks === 1 ? 'library' : $rota_picks . ' libraries' ) . ' in the order shown are used.'
                   : 'The request moves to the next available library in the order shown.' )
               . '</p>'
               . '<p class="seal-rota-count" id="seal-rota-count" role="status" aria-live="polite" hidden></p>'
               . '<input type="hidden" name="rota_candidates" value="' . esc_attr( wp_json_encode( $rota_list ) ) . '">'
               . '</div>';
        }

        $n = 0;
        echo '<div class="seal-lib-list">';
        foreach ( $offers as $o ) {
            $n++;
            $al   = strtolower( $o['avail'] );
            $cls  = $al === 'available' ? 'seal-avail-yes' : ( in_array( $al, [ 'unknown', '' ], true ) ? 'seal-avail-unknown' : 'seal-avail-no' );
            $label = '<span class="seal-lib-main"><strong class="seal-lib-name">' . esc_html( $o['name'] ) . '</strong>'
                   . '<span class="seal-lib-system">' . esc_html( $o['system'] ) . '</span></span>'
                   . '<span class="seal-lib-meta"><span class="seal-avail ' . esc_attr( $cls ) . '">' . esc_html( $o['avail'] ) . '</span>'
                   . ( $o['call'] !== '' ? '<span class="seal-lib-call"><span class="seal-lib-call-label">Call number</span> ' . esc_html( $o['call'] ) . '</span>' : '' )
                   . '</span>';
            ?>
            <div class="seal-single">
                <label class="seal-lib-option" for="libsingle_<?php echo (int) $n; ?>">
                    <input type="radio" id="libsingle_<?php echo (int) $n; ?>" class="seal-lib-check" name="libdestination[]" value="<?php echo esc_attr( $o['value'] ); ?>" data-loc="<?php echo esc_attr( $o['loc'] ); ?>">
                    <?php echo $label; // escaped above ?>
                </label>
                <?php if ( $rota_active && $rota_picks > 0 ) : ?>
                <label class="seal-rota-pick" for="rotapick_<?php echo (int) $n; ?>">
                    <input type="checkbox" id="rotapick_<?php echo (int) $n; ?>" name="rota_picks[]" value="<?php echo esc_attr( $o['loc'] ); ?>">
                    <span>Use <?php echo esc_html( $o['name'] ); ?> as a backup</span>
                </label>
                <?php endif; ?>
            </div>
            <div class="seal-multi" style="display:none;">
                <label class="seal-lib-option" for="libmulti_<?php echo (int) $n; ?>">
                    <input type="checkbox" id="libmulti_<?php echo (int) $n; ?>" class="seal-lib-check" name="libdestination[]" value="<?php echo esc_attr( $o['value'] ); ?>">
                    <?php echo $label; // escaped above ?>
                </label>
            </div>
            <?php
        }
        echo '</div>';

        if ( $rota_active && $rota_picks > 0 ) :
            ?>
            <script>
            (function () {
                var max    = <?php echo (int) $rota_picks; ?>;
                var toggle = document.getElementById('seal-rota-enabled');
                var status = document.getElementById('seal-rota-count');
                var picks  = Array.prototype.slice.call(document.querySelectorAll('input[name="rota_picks[]"]'));
                var radios = Array.prototype.slice.call(document.querySelectorAll('.seal-single input[type="radio"][name="libdestination[]"]'));
                if (!toggle || !picks.length) { return; }
                /**
                 * Automatic Fallback picker: hides and clears backup choices while fallback is off, never lets
                 * the main library be its own backup, disables the remaining boxes once the backup limit is
                 * reached, and updates the live count for screen readers.
                 */
                function update() {
                    var on = toggle.checked, main = '', n = 0;
                    radios.forEach(function (r) { if (r.checked) { main = r.getAttribute('data-loc'); } });
                    picks.forEach(function (p) {
                        var isMain = p.value === main;
                        if (!on || isMain) { p.checked = false; }   // no backups without fallback; main library can't be its own backup
                        p.closest('.seal-rota-pick').hidden = !on || isMain;
                        if (p.checked) { n++; }
                    });
                    picks.forEach(function (p) { p.disabled = !on || p.value === main || (!p.checked && n >= max); });
                    status.hidden = !on;
                    status.textContent = n === 0
                        ? 'No backups chosen: the next available libraries will be used automatically.'
                        : n + ' of ' + max + ' backup ' + (max === 1 ? 'library' : 'libraries') + ' chosen' + (n >= max ? ' (the most allowed; untick one to choose another)' : '') + '.';
                }
                toggle.addEventListener('change', update);
                picks.forEach(function (p) { p.addEventListener('change', update); });
                radios.forEach(function (r) { r.addEventListener('change', update); });
                update();
            })();
            </script>
            <?php
        endif;

        $greyed = array_unique( $greyed );
        if ( $greyed ) {
            echo '<div class="seal-lib-unavail"><p class="seal-lib-unavail-title">Not available for this request</p><ul>';
            foreach ( $greyed as $line ) { echo '<li class="seal-grayout">' . esc_html( $line ) . '</li>'; }
            echo '</ul></div>';
        }

        if ( $n === 0 ) {
            echo '<p><strong>Sorry, no available library to route your request at this time.</strong> '
               . '<a href="' . esc_url( home_url( '/' ) ) . '">Would you like to try another search?</a></p>';
        }
        if ( ( isset( $_GET['ill_debug'] ) || isset( $_GET['seal_debug'] ) ) && current_user_can( 'manage_options' ) ) {   // seal_debug: older name
            $this->render_trace( $trace, $item_type );
        }
        return $n;
    }

    /** Administrators only: every holding and why it was offered, greyed out or hidden. */
    private function render_trace( array $trace, string $item_type ): void {
        echo '<details class="seal-debug" open style="margin:16px 0;padding:8px 12px;background:#f6f7f7;border:1px solid #c3c4c7"><summary><strong>Administrator view: how each holding was handled</strong> (item type: '
           . esc_html( $item_type !== '' ? $item_type : 'none' ) . ')</summary>'
           . '<div style="overflow-x:auto"><table class="seal-table" style="margin-top:8px"><thead><tr>';
        foreach ( [ 'Catalog location', 'Catalog system', 'Holding location', 'Alias used', 'Copy', 'Library found', 'Result', 'Reason' ] as $h ) {
            echo '<th scope="col">' . esc_html( $h ) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ( $trace as $t ) {
            echo '<tr><td>' . esc_html( $t['catalog_name'] ) . '</td><td>' . esc_html( $t['catalog'] !== '' ? $t['catalog'] : '(not listed)' ) . '</td><td>' . esc_html( $t['holding'] )
               . '</td><td>' . esc_html( $t['alias'] ) . '</td><td>' . esc_html( $t['available'] )
               . '</td><td>' . esc_html( $t['library'] !== '' ? $t['library'] . ' (' . $t['system'] . ')' : '-' )
               . '</td><td>' . esc_html( $t['result'] ) . '</td><td>' . esc_html( $t['reason'] ) . '</td></tr>';
        }
        if ( ! $trace ) { echo '<tr><td colspan="8">No holdings were found in this record.</td></tr>'; }
        echo '</tbody></table></div></details>';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Form processing (POST)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Saves and sends a submitted request: one row per chosen library, ILL number, lender and borrower emails,
     * ILLiad submission for ILLiad lenders, the fallback queue, then the confirmation page (or a clear "not
     * submitted" message with the reason).
     */
    private function process_submission(): void {
        $db        = SEAL_DB::get();
        $stat_tbl  = SEAL_DB::$sealSTAT;
        $lib_tbl   = SEAL_DB::$sealLIB;
        $illiad_tbl= SEAL_DB::$sealILLiadMapping;
        $today     = current_time( 'mysql' );
        $uid       = $this->user->ID;

        // Sanitise bib inputs
        $ititle    = sanitize_text_field( wp_unslash( $_POST['bibtitle']  ?? '' ) );
        $iauthor   = sanitize_text_field( wp_unslash( $_POST['bibauthor'] ?? '' ) );
        $itype     = sanitize_text_field( wp_unslash( $_POST['bibtype']   ?? '' ) );
        $pubdate   = sanitize_text_field( wp_unslash( $_POST['pubdate']   ?? '' ) );
        $isbn      = sanitize_text_field( wp_unslash( $_POST['isbn']      ?? '' ) );
        $issn      = sanitize_text_field( wp_unslash( $_POST['issn']      ?? '' ) );
        $needbydate= sanitize_text_field( wp_unslash( $_POST['needbydate']?? '' ) );
        $reqnote   = sanitize_text_field( wp_unslash( $_POST['reqnote']   ?? '' ) );
        $patronnote= sanitize_text_field( wp_unslash( $_POST['patronnote']?? '' ) );
        // Most request columns are VARCHAR(255): cut to fit (strict SQL mode rejects a longer value instead of cutting it)
        $cut       = static fn( string $s ): string => mb_substr( $s, 0, 255 );

        // Requester identity always comes from the server (the user's own account and the library
        // profile chosen from their own LOC codes), never from the form. Otherwise anyone could post
        // a request that claims to come from another library or person.
        $inst      = $this->field_institution;
        $req_loc   = $this->selected_loc;
        $req_sys   = $this->field_req_system;
        $fname     = sanitize_text_field( (string) get_user_meta( $uid, 'first_name', true ) );
        $lname     = sanitize_text_field( (string) get_user_meta( $uid, 'last_name',  true ) );
        $wphone    = sanitize_text_field( (string) ( get_user_meta( $uid, 'work_phone', true ) ?: get_user_meta( $uid, 'phone', true ) ) );
        $saddress  = $this->field_street1;
        $saddress2 = $this->field_street2;
        $caddress  = $this->field_city_st_zip;

        // Email (primary + alt)
        $primary_email = $this->user->user_email;
        $alt_email     = sanitize_email( (string) get_user_meta( $uid, 'alt_email', true ) );
        $combined_email = $primary_email;
        if ( $alt_email && is_email( $alt_email ) ) {
            $combined_email .= ', ' . $alt_email;
        }

        // Article fields
        $arttile     = sanitize_text_field( wp_unslash( $_POST['arttile']     ?? '' ) );
        $artauthor   = sanitize_text_field( wp_unslash( $_POST['artauthor']   ?? '' ) );
        $artissue    = sanitize_text_field( wp_unslash( $_POST['artissue']    ?? '' ) );
        $artvolume   = sanitize_text_field( wp_unslash( $_POST['artvolume']   ?? '' ) );
        $artpage     = sanitize_text_field( wp_unslash( $_POST['artpage']     ?? '' ) );
        $artmonth    = sanitize_text_field( wp_unslash( $_POST['artmonth']    ?? '' ) );
        $artyear     = sanitize_text_field( wp_unslash( $_POST['artyear']     ?? '' ) );
        $artcopyright= sanitize_text_field( wp_unslash( $_POST['artcopyright']?? '' ) );

        $article_str = "Article Title: $arttile <br>Article Author: $artauthor <br>Volume: $artvolume "
                     . "<br>Issue: $artissue <br>Pages: $artpage <br>Month: $artmonth "
                     . "<br>Year: $artyear <br>Copyright: $artcopyright";

        $last_illnum = '';
        $sent        = [];   // one entry per library the request was sent to, for the confirmation page
        $skipped     = [];   // library codes that could not be used (not found / suspended / not participating)
        $failed      = [];   // libraries whose request row could not be saved

        // Rota (automatic fallback) only makes sense for a single destination — a multi-copy
        // request already goes to several libraries at once on purpose.
        $rota_enabled    = SEAL_Rota::active()
            && count( $_POST['libdestination'] ) === 1
            && sanitize_text_field( wp_unslash( $_POST['rota_enabled'] ?? '' ) ) === '1';
        $rota_offered = [];
        if ( $rota_enabled ) {
            // Cap the raw string before decoding it — this is the borrower's own browser echoing back
            // what the server rendered, but never decode an unbounded payload just because it's POST.
            $raw     = substr( (string) wp_unslash( $_POST['rota_candidates'] ?? '[]' ), 0, 8000 );
            $decoded = json_decode( $raw, true );
            if ( is_array( $decoded ) ) {
                // The full ranked list the page offered — an absolute safety cap only (DoS/size), not
                // the 3-library chain limit. That limit is enforced below, after the borrower's pick
                // is removed, whether they hand-picked backups or we fall back to ranked order.
                foreach ( array_slice( $decoded, 0, 50 ) as $c ) {
                    if ( is_array( $c ) && ! empty( $c['loc'] ) ) {
                        $rota_offered[] = [
                            'loc'   => strtoupper( sanitize_text_field( (string) $c['loc'] ) ),
                            'avail' => sanitize_text_field( (string) ( $c['avail'] ?? '' ) ),
                            'call'  => sanitize_text_field( (string) ( $c['call']  ?? '' ) ),
                            'alias' => sanitize_text_field( (string) ( $c['alias'] ?? '' ) ),
                        ];
                    }
                }
            }
        }

        foreach ( $_POST['libdestination'] as $destination ) {
            // Extract only the LOC code from the colon-delimited POST value.
            // All other data (email, name, system) is re-fetched from the DB —
            // never trusted from the POST to prevent email header injection or
            // spoofing of library identity.
            $parts = explode( ':', sanitize_text_field( wp_unslash( $destination ) ) );
            if ( count( $parts ) < 8 ) { continue; }

            // LOC codes are MARC organization codes: letters (mixed case, e.g. NAlU), digits, dashes.
            // Strip anything else, but keep lower-case letters — matched case-insensitively below.
            $destloc  = preg_replace( '/[^A-Za-z0-9\-]/', '', $parts[7] );
            $itemavail    = sanitize_text_field( $parts[3] ?? '' );
            $itemcall     = sanitize_text_field( $parts[4] ?? '' );
            $itemlocation = sanitize_text_field( $parts[5] ?? '' );

            if ( $destloc === '' ) { continue; }

            // Re-fetch library from DB — this is the authoritative source.
            // Ensures email, name, and system cannot be injected via POST.
            $lib_verify_q = SEAL_DB::query(
                "SELECT `loc`,`Name`,`ill_email`,`system`,`participant`,`suspend`
                 FROM %t WHERE UPPER(TRIM(`loc`)) = %s AND `participant` = 1 AND `suspend` = 0 LIMIT 1",
                $lib_tbl, strtoupper( $destloc )
            );
            $lib_verify = $lib_verify_q ? $lib_verify_q->fetch_assoc() : null;
            if ( ! $lib_verify ) { // Not found, suspended, or not a participant
                $skipped[] = $destloc;
                continue;
            }
            $destloc = trim( (string) $lib_verify['loc'] ); // the library's own code, exactly as stored

            $library    = $lib_verify['Name'];
            $destsystem = $lib_verify['system'];
            $destemail  = $lib_verify['ill_email'];


            // Fetch ILLiad integration details for this lender
            $illiad_q = SEAL_DB::query(
                "SELECT IlliadDATE, IlliadURL, Illiad, APIkey, LibEmailAlert FROM %t WHERE `loc` = %s LIMIT 1",
                $lib_tbl, $destloc
            );
            $illiad_row = $illiad_q ? $illiad_q->fetch_assoc() : [];

            // INSERT request
            $result = SEAL_DB::query(
                "INSERT INTO %t
                 (`illNUB`,`Title`,`Author`,`pubdate`,`reqisbn`,`reqissn`,`itype`,
                  `Call Number`,`Location`,`Available`,`article`,`needbydate`,`reqnote`,
                  `patronnote`,`Destination`,`DestSystem`,`Requester lib`,`Requester LOC`,
                  `ReqSystem`,`Requester person`,`requesterEMAIL`,`Timestamp`,`Fill`,
                  `responderNOTE`,`requesterPhone`,`saddress`,`saddress2`,`caddress`,
                  `returnNote`,`checkinTimeStamp`,`shipMethod`,`IlliadStatus`,`IlliadTransID`)
                 VALUES ('0',%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,'3','',%s,%s,%s,%s,'','','','','')",
                $stat_tbl,
                $ititle, $cut( $iauthor ), $cut( $pubdate ), $cut( $isbn ), $cut( $issn ), $cut( $itype ),
                $cut( $itemcall ), $itemlocation, $cut( $itemavail ), $article_str,
                $cut( $needbydate ), $cut( $reqnote ), $cut( $patronnote ),
                $cut( $destloc ), $destsystem, $cut( $inst ), $cut( $req_loc ), $req_sys,
                $cut( "$fname $lname" ), $cut( $combined_email ), $today,
                $cut( $wphone ), $cut( $saddress ), $cut( $saddress2 ), $cut( $caddress )
            );

            if ( ! $result ) {
                $failed[] = $library;
                SEAL_Functions::send_alert(
                    'ILL Request DB Insert Failure',
                    '<p>Saving a request failed for ' . esc_html( "$fname $lname" ) . ' (' . esc_html( $inst ) . ') to ' . esc_html( $library ) . '.</p>'
                    . '<p><strong>Database said:</strong> ' . esc_html( SEAL_DB::last_error() ) . '</p>'
                );
                continue;
            }

            $insert_id = $db->insert_id;
            $illnum    = SEAL_Functions::make_ill_number( $insert_id );
            $last_illnum = $illnum;

            if ( $rota_enabled ) {
                // Ranked list of everything on offer, minus the library just chosen above.
                $remaining = array_values( array_filter(
                    $rota_offered,
                    static fn( array $c ): bool => strcasecmp( $c['loc'], $destloc ) !== 0
                ) );

                // Borrower's explicit backup picks, if any — never trust a POSTed LOC code on its
                // own; SEAL_Rota::build_queue() validates each against $remaining before using it.
                $picked_locs = array_map(
                    static fn( $l ): string => strtoupper( sanitize_text_field( (string) $l ) ),
                    array_slice( (array) wp_unslash( $_POST['rota_picks'] ?? [] ), 0, 20 )
                );

                $queue = SEAL_Rota::build_queue( $remaining, $picked_locs );
                if ( $queue ) {
                    SEAL_Rota::start( $illnum, [ 'loc' => $destloc, 'name' => $library ], $queue );
                }
            }
            $backup_names = [];
            foreach ( ( $rota_enabled ? ( $queue ?? [] ) : [] ) as $b ) {
                $bq = SEAL_DB::query( "SELECT `Name` FROM %t WHERE UPPER(TRIM(`loc`)) = %s LIMIT 1", $lib_tbl, strtoupper( (string) $b['loc'] ) );
                $br = $bq ? $bq->fetch_assoc() : null;
                $backup_names[] = $br ? (string) $br['Name'] : (string) $b['loc'];
            }

            SEAL_DB::query(
                "UPDATE %t SET `illNUB` = %s WHERE `index` = %d",
                $stat_tbl, $illnum, $insert_id
            );

            // ── ILLiad API submission ──────────────────────────────────────
            if ( ! empty( $illiad_row['Illiad'] ) && $illiad_row['Illiad'] == '1' ) {
                $this->submit_to_illiad( $illiad_row, $illnum, $insert_id, $stat_tbl, $lib_tbl, $illiad_tbl,
                    $req_loc, $ititle, $iauthor, $itemcall, $pubdate, $isbn, $issn, $needbydate, $reqnote,
                    $arttile, $artauthor, $artvolume, $artissue, $artpage, $artyear );
            }

            // ── Emails ────────────────────────────────────────────────────
            $this->send_requester_email( $combined_email, $illnum, $fname, $lname, $ititle, $iauthor,
                $pubdate, $isbn, $needbydate, $reqnote, $arttile, $artauthor, $artvolume,
                $artissue, $artpage, $artmonth, $artyear, $artcopyright );

            $lender_ok = $this->send_lender_email( $destemail, $illnum, $fname, $lname, $combined_email, $inst,
                $wphone, $saddress, $saddress2, $caddress, $ititle, $iauthor, $itype, $pubdate,
                $isbn, $itemcall, $needbydate, $reqnote, $arttile, $artauthor, $artvolume,
                $artissue, $artpage, $artmonth, $artyear, $artcopyright );

            $sent[] = [
                'ill'     => $illnum,
                'lib'     => $library,
                'email'   => implode( ', ', array_filter( array_map( 'trim', explode( ';', str_replace( ',', ';', (string) $destemail ) ) ) ) ),
                'ok'      => $lender_ok,
                'backups' => $backup_names,
                'call'    => $itemcall,
            ];
        }

        // ── Nothing saved: say so instead of reporting success ───────────────
        if ( $last_illnum === '' ) {
            $why = $failed
                ? 'The request could not be saved (a database error). The ILL office has been alerted.'
                : ( $skipped
                    ? 'The library you chose (' . implode( ', ', array_map( 'esc_html', $skipped ) ) . ') can\'t take requests right now — it may be suspended or not participating.'
                    : 'No library was selected.' );
            SEAL_Functions::send_alert(
                'ILL request not created',
                '<p>A request from ' . esc_html( "$fname $lname" ) . ' (' . esc_html( $inst ) . ') for <em>' . esc_html( $ititle ) . '</em> was not created. '
                . ( $skipped ? 'Library codes not accepted: ' . esc_html( implode( ', ', $skipped ) ) . '. ' : '' )
                . ( $failed ? 'Database save failed for: ' . esc_html( implode( ', ', $failed ) ) . '.' : '' ) . '</p>'
            );
            $this->alert( '<strong>Your request was not submitted.</strong> ' . $why . ' Use your browser\'s Back button to choose another library, or contact the ILL office.', 'error' );
            return;
        }

        // ── Confirmation ──────────────────────────────────────────────────────
        $mode = SEAL_Functions::email_mode();
        ?>
        <div class="seal-sent" role="status">
            <h2 class="seal-sent-title">Your Request Has Been Sent</h2>
            <?php foreach ( $sent as $x ) : ?>
                <?php if ( $x['ok'] ) : ?>
                <p>Request <strong><?php echo esc_html( $x['ill'] ); ?></strong> has been emailed to <strong><?php echo esc_html( $x['lib'] ); ?></strong><?php
                    echo $x['email'] !== '' ? ' at <a href="mailto:' . esc_attr( str_replace( ' ', '', $x['email'] ) ) . '">' . esc_html( $x['email'] ) . '</a>' : ''; ?>.</p>
                <?php else : ?>
                <p class="seal-alert seal-alert-warning">Request <strong><?php echo esc_html( $x['ill'] ); ?></strong> to <strong><?php echo esc_html( $x['lib'] ); ?></strong> is saved, but the email to the library could not be sent<?php
                    echo $x['email'] === '' ? ' (it has no ILL email address on file)' : ' (to ' . esc_html( $x['email'] ) . ')'; ?>. Please contact the library directly.</p>
                <?php endif; ?>
                <?php if ( $x['backups'] ) : ?>
                    <p>If <?php echo esc_html( $x['lib'] ); ?> can't fill it, it will automatically go to <?php echo esc_html( implode( ', then ', $x['backups'] ) ); ?>.</p>
                <?php endif; ?>
            <?php endforeach; ?>

            <h3>Details of your request<?php echo count( $sent ) > 1 ? 's' : ''; ?></h3>
            <ul class="seal-sent-details">
                <li><strong>Requested From:</strong> <?php echo esc_html( implode( ', ', array_column( $sent, 'lib' ) ) ); ?></li>
                <li><strong>Title:</strong> <?php echo esc_html( $ititle ); ?></li>
                <li><strong>Author:</strong> <?php echo esc_html( $iauthor ); ?></li>
                <li><strong>Publication Date:</strong> <?php echo esc_html( $pubdate ); ?></li>
                <?php if ( $isbn ) : ?><li><strong>ISBN:</strong> <?php echo esc_html( $isbn ); ?></li><?php endif; ?>
                <?php if ( $issn ) : ?><li><strong>ISSN:</strong> <?php echo esc_html( $issn ); ?></li><?php endif; ?>
                <li><strong>Item Type:</strong> <?php echo esc_html( $itype ); ?></li>
                <?php if ( $needbydate ) : ?><li><strong>Need By:</strong> <?php echo esc_html( $needbydate ); ?></li><?php endif; ?>
                <?php foreach ( $sent as $x ) : if ( trim( (string) $x['call'] ) === '' ) { continue; } ?>
                <li><strong>Call Number<?php echo count( $sent ) > 1 ? ' (' . esc_html( $x['lib'] ) . ')' : ''; ?>:</strong> <?php echo esc_html( $x['call'] ); ?></li>
                <?php endforeach; ?>
            </ul>

            <h3>Requester Info</h3>
            <ul class="seal-sent-details">
                <li><strong>Name:</strong> <?php echo esc_html( trim( "$fname $lname" ) ); ?></li>
                <li><strong>Email:</strong> <?php echo esc_html( $combined_email ); ?></li>
                <li><strong>Institution:</strong> <?php echo esc_html( $inst ); ?></li>
            </ul>

            <p>A copy of this request has also been emailed to the requester <?php echo esc_html( trim( "$fname $lname" ) ); ?> at <?php echo esc_html( $combined_email ); ?>.</p>

            <?php if ( $mode === 'off' ) : ?>
                <p class="seal-alert seal-alert-warning">Email test mode is on: none of these emails were actually sent.</p>
            <?php elseif ( $mode === 'redirect' ) : ?>
                <p class="seal-alert seal-alert-warning">Email test mode is on: these emails went to the test address (<?php echo esc_html( seal_config( 'email_test_to' ) ); ?>) instead.</p>
            <?php endif; ?>

            <p><a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="seal-btn-secondary">Would you like to make another request?</a></p>
        </div>
        <?php
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ILLiad API
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Posts a new request to the lender's ILLiad Web Platform API using the borrower's ILLiad address mapping,
     * and saves the returned transaction number. Failures alert the administrators; the request can be sent
     * again from /resend.
     */
    private function submit_to_illiad(
        array $illiad_row, string $illnum, int $insert_id, string $stat_tbl,
        string $lib_tbl, string $illiad_tbl, string $req_loc,
        string $title, string $author, string $callnum, string $pubdate,
        string $isbn, string $issn, string $needby, string $reqnote,
        string $arttile, string $artauthor, string $artvol,
        string $artissue, string $artpage, string $artyear
    ): void {

        $illiad_url  = $illiad_row['IlliadURL']  ?? '';
        $illiad_days = $illiad_row['IlliadDATE'] ?? 14;
        $api_key     = SEAL_Secret::open( (string) ( $illiad_row['APIkey'] ?? '' ) );   // stored encrypted (ILL_DATA_KEY)

        if ( ! $illiad_url || ! $api_key ) { return; }

        // Requester details from library table
        $req_q = SEAL_DB::query(
            "SELECT loc,Name,ill_email,address2,address3,OCLC,system FROM %t WHERE `loc` = %s LIMIT 1",
            $lib_tbl, $req_loc
        );
        $req_row = $req_q ? $req_q->fetch_assoc() : [];

        // ILLiad address mapping
        $map_q = SEAL_DB::query(
            "SELECT * FROM %t WHERE `LOC` = %s LIMIT 1",
            $illiad_tbl, $req_loc
        );
        $map_row = $map_q ? $map_q->fetch_assoc() : [];
        $illiad_add = $map_row['illiadADDnumb'] ?? '';
        $illiad_sym = $map_row['illiadLIBSymbol'] ?? '';

        $due_date = date( 'Y-m-d', strtotime( '+' . (int) $illiad_days . ' days' ) );
        $addr3    = trim( (string) ( $req_row['address3'] ?? '' ) );
        $addr3    = str_replace( ',', '', $addr3 );
        $pieces   = explode( ' ', $addr3 );
        $city     = $pieces[0] ?? '';
        $state    = $pieces[1] ?? '';
        $zip      = $pieces[2] ?? '';

        $payload = empty( $arttile )
            ? [
                'Username'           => 'Lending',
                'LendingString'      => $reqnote,
                'RequestType'        => 'Loan',
                'DueDate'            => $due_date . 'T00:00:00-04:00',
                'ProcessType'        => 'Lending',
                'LenderAddressNumber'=> $illiad_add,
                'LendingLibrary'     => $illiad_sym,
                'TransactionStatus'  => 'Awaiting Lending Request Processing',
                'LoanTitle'          => $title,
                'LoanAuthor'         => $author,
                'CallNumber'         => $callnum,
                'LoanDate'           => $pubdate,
                'ISSN'               => $isbn,
                'ILLNumber'          => $illnum,
                'TAddress'           => $req_row['Name'] ?? '',
                'TAddress2'          => $req_row['address2'] ?? '',
                'TCity'              => $city,
                'TState'             => $state,
                'TZip'               => $zip,
                'TEMailAddress'      => $req_row['ill_email'] ?? '',
            ]
            : [
                'Username'             => 'Lending',
                'LendingString'        => $reqnote,
                'ProcessType'          => 'Lending',
                'LenderAddressNumber'  => $illiad_add,
                'LendingLibrary'       => $illiad_sym,
                'TransactionStatus'    => 'Awaiting Lending Request Processing',
                'LoanTitle'            => $title,
                'LoanAuthor'           => $author,
                'CallNumber'           => $callnum,
                'LoanDate'             => $pubdate,
                'PhotoArticleTitle'    => $arttile,
                'PhotoArticleAuthor'   => $artauthor,
                'PhotoJournalVolume'   => $artvol,
                'PhotoJournalIssue'    => $artissue,
                'PhotoJournalYear'     => $artyear,
                'PhotoJournalInclusivePages' => $artpage,
                'ISSN'                 => $issn,
                'ILLNumber'            => $illnum,
                'TAddress'             => $req_row['Name'] ?? '',
                'TAddress2'            => $req_row['address2'] ?? '',
                'TCity'                => $city,
                'TState'               => $state,
                'TZip'                 => $zip,
                'TEMailAddress'        => $req_row['ill_email'] ?? '',
            ];

        $response = wp_remote_post( $illiad_url, [
            'headers' => [
                'Content-Type' => 'application/json',
                'ApiKey'       => $api_key,
            ],
            'body'    => wp_json_encode( $payload ),
            'timeout' => 20,
            'sslverify' => true,
        ] );

        $txn_num = '';
        $txn_status = '';

        if ( ! is_wp_error( $response ) ) {
            $decoded = json_decode( wp_remote_retrieve_body( $response ), true );
            $txn_num    = (string) ( $decoded['TransactionNumber'] ?? '' );
            $txn_status = (string) ( $decoded['TransactionStatus'] ?? '' );
        }

        // Validate transaction number
        if ( ! $txn_num || ! ctype_digit( $txn_num ) || strlen( $txn_num ) < 4 ) {
            SEAL_Functions::send_alert(
                "ILLiad Failure (no TransactionNumber) — " . seal_config('system_name') . " ILL $illnum",
                '<p>ILLiad API did not return a valid TransactionNumber.</p>'
                . '<p><strong>ILL #:</strong> ' . esc_html( $illnum ) . '</p>'
                . '<p><strong>Returned TransactionNumber:</strong> ' . esc_html( $txn_num ) . '</p>'
                . '<p><strong>Returned Status:</strong> ' . esc_html( $txn_status ) . '</p>'
            );
        }

        SEAL_DB::query(
            "UPDATE %t SET `IlliadStatus` = %s, `IlliadTransID` = %s WHERE `index` = %d",
            $stat_tbl, $txn_status, $txn_num, $insert_id
        );
    }

    // ── Email senders ─────────────────────────────────────────────────────────

    /** Article fields as their own details block (empty when this is not an article request). */
    private static function article_details( string $title, string $author, string $vol, string $issue, string $pages, string $month, string $year, string $copyright ): string {
        if ( trim( $title ) === '' ) { return ''; }
        return SEAL_Functions::email_subheading( 'Article details' )
             . SEAL_Functions::email_details( [ 'Article title' => $title, 'Article author' => $author, 'Volume' => $vol, 'Issue' => $issue,
                                                'Pages' => $pages, 'Month' => $month, 'Year' => $year, 'Copyright' => $copyright ] );
    }

    /**
     * Emails the borrower a copy of the request (item, need-by date, note and article details). Returns true
     * when the email was handed to the mailer.
     */
    private function send_requester_email(
        string $to, string $illnum, string $fname, string $lname,
        string $title, string $author, string $pubdate, string $isbn,
        string $needby, string $reqnote,
        string $arttile, string $artauthor, string $artvol, string $artissue,
        string $artpage, string $artmonth, string $artyear, string $artcopyright
    ): void {
        $cancel_url = add_query_arg( [ 'num' => $illnum, 'a' => 6 ], home_url( '/status' ) );
        $tv   = [ 'ill' => $illnum, 'title' => $title, 'name' => trim( "$fname $lname" ) ];
        $body = SEAL_Emails::heading( 'request_confirm', $tv )
              . SEAL_Emails::message( 'request_confirm', $tv )
              . SEAL_Functions::email_details( [
                    'ILL #'            => $illnum,
                    'Title'            => $title,
                    'Author'           => $author,
                    'Publication date' => $pubdate,
                    'ISBN'             => $isbn,
                    'Needed by'        => SEAL_Functions::email_date( $needby ),
                    'Your note'        => $reqnote,
                ] )
              . self::article_details( $arttile, $artauthor, $artvol, $artissue, $artpage, $artmonth, $artyear, $artcopyright )
              . SEAL_Functions::email_buttons( [ [ home_url( '/requesthistory' ), 'View your borrowing requests', '' ], [ $cancel_url, 'Cancel this request', '#50575e' ] ] )
              . SEAL_Emails::closing( 'request_confirm', $tv );

        SEAL_Functions::send_email( $to, SEAL_Emails::subject( 'request_confirm', [ 'ill' => $illnum, 'title' => $title ] ), $body );
    }

    /**
     * Emails the lending library the new request with Yes / No answer links, the borrower's contact and
     * delivery address, and the item and article details. Returns false when the library has no ILL email
     * address or sending failed, so the confirmation page can say so.
     */
    private function send_lender_email(
        string $to, string $illnum, string $fname, string $lname, string $req_email,
        string $inst, string $phone, string $addr1, string $addr2, string $addr3,
        string $title, string $author, string $itype, string $pubdate, string $isbn,
        string $callnum, string $needby, string $reqnote,
        string $arttile, string $artauthor, string $artvol, string $artissue,
        string $artpage, string $artmonth, string $artyear, string $artcopyright
    ): bool {
        $yes_url = add_query_arg( [ 'num' => $illnum, 'a' => 1 ], home_url( '/respond' ) );
        $no_url  = add_query_arg( [ 'num' => $illnum, 'a' => 0 ], home_url( '/respond' ) );

        // Semicolon-delimited emails → comma for wp_mail
        $to_clean = implode( ',', array_map( 'trim', explode( ';', $to ) ) );

        $tv   = [ 'ill' => $illnum, 'title' => $title, 'borrower_library' => $inst ];
        $body = SEAL_Emails::heading( 'request_new', $tv )
              . SEAL_Emails::message( 'request_new', $tv )
              . SEAL_Functions::email_buttons( [ [ $yes_url, 'Yes, we will fill it', '#0a6b35' ], [ $no_url, 'No, we cannot fill it', '#b32d2e' ] ] )
              . SEAL_Functions::email_subheading( 'Requested item' )
              . SEAL_Functions::email_details( [
                    'ILL #'            => $illnum,
                    'Title'            => $title,
                    'Author'           => $author,
                    'Type'             => $itype,
                    'Publication date' => $pubdate,
                    'ISBN'             => $isbn,
                    'Call number'      => $callnum,
                    'Needed by'        => SEAL_Functions::email_date( $needby ),
                    'Note'             => $reqnote,
                ] )
              . self::article_details( $arttile, $artauthor, $artvol, $artissue, $artpage, $artmonth, $artyear, $artcopyright )
              . SEAL_Functions::email_subheading( 'Requested by' )
              . SEAL_Functions::email_details( [
                    'Name'     => trim( "$fname $lname" ),
                    'Library'  => $inst,
                    'Email*'   => SEAL_Functions::email_mailto( $req_email ),
                    'Phone'    => $phone,
                    'Ship to'  => implode( "\n", array_filter( array_map( 'trim', [ $addr1, $addr2, $addr3 ] ), 'strlen' ) ),
                ] );

        $body .= SEAL_Emails::closing( 'request_new', $tv );
        if ( trim( $to_clean, ', ' ) === '' ) { return false; }
        return SEAL_Functions::send_email( $to_clean, SEAL_Emails::subject( 'request_new', [ 'ill' => $illnum, 'title' => $title ] ), $body );
    }
}
