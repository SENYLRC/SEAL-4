<?php
/**
 * SEAL_Module_PublicRequest — "Request a Book" form for the public (no login needed).
 *
 * Put the shortcode [ill_book_request] on any WordPress page. A visitor enters a book title, an email
 * address and/or phone number, their library card number, picks the library to collect the book from, and may
 * add a short note to the library (NOTE_MAX characters).
 * The request is emailed to that library's ILL email address (Library-Data `ill_email`); nothing is stored.
 *
 * The pickup list is every participating library (participant = 1) that has an ILL email address, is not
 * suspended (suspend = 1: not taking ILL requests; the check_suspend cron lifts it on its end date) and belongs
 * to a library system that has opted in (ILL System → Public Requests; none by default), grouped by library
 * system. A library suspended after the form was shown is refused on submit. Because the form is public it has its own spam guards: a hidden honeypot field, a
 * signed "form shown at" time (submissions faster than MIN_SECONDS are refused) and a per-address limit
 * of RATE_MAX submissions an hour. These work on cached pages, unlike a WordPress nonce for visitors.
 *
 * Turned on and off with the "Public Book Requests" module (ILL System → Modules).
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class SEAL_Module_PublicRequest {

    const SHORTCODE   = 'ill_book_request';
    const ACTION      = 'seal_public_request';
    const SENT_ARG    = 'seal_pr';
    const MIN_SECONDS = 3;      // a person takes longer than this to fill in the form
    const RATE_MAX    = 5;      // submissions per address per RATE_WINDOW
    const RATE_WINDOW = HOUR_IN_SECONDS;
    const NOTE_MAX    = 256;    // characters in the optional note to the library

    /** Field values and errors from a failed submission, shown again by the shortcode in the same request. */
    private static array $values = [];
    private static array $errors = [];

    /** Registers the shortcode and the form handler. */
    public static function init(): void {
        add_shortcode( self::SHORTCODE, [ __CLASS__, 'render' ] );
        add_action( 'template_redirect', [ __CLASS__, 'handle_post' ] );
    }

    // ── Libraries ─────────────────────────────────────────────────────────────

    /**
     * Library-system codes opted in under ILL System → Public Requests, lower-cased (codes are matched without
     * regard to case, as SEAL_Functions::system_name() does).
     */
    public static function opted_systems(): array {
        return array_values( array_filter( array_map( static fn( $c ) => strtolower( trim( $c ) ), explode( ',', seal_config( 'pr_systems' ) ) ), 'strlen' ) );
    }

    /**
     * Sanitizes the seal_pr_systems setting to a comma list of codes ('' = none), de-duplicated without regard
     * to case. Takes the checkbox array or an already-saved comma list: WordPress runs a setting's sanitizer a
     * second time on the first save (update_option → add_option), so the comma list must split back into codes
     * rather than be squashed into one ("SE,MH" → "SEMH").
     */
    public static function sanitize_systems( $val ): string {
        $codes = [];
        foreach ( is_array( $val ) ? $val : explode( ',', (string) $val ) as $c ) {
            $c = trim( str_replace( ',', '', sanitize_text_field( (string) $c ) ) );
            if ( $c !== '' ) { $codes[ strtolower( $c ) ] = $c; }
        }
        return implode( ',', $codes );
    }

    /**
     * Pickup libraries: recnum => [ 'name', 'system', 'email', 'suspended', 'until' ]. Participants with an ILL
     * email that aren't suspended and whose library system has opted in, sorted by system then name. Empty when
     * there are none, or the database can't be reached.
     */
    public static function libraries(): array {
        return self::filter_opted( self::eligible_libraries() ?? [], self::opted_systems() );
    }

    /** The $libs the form offers: not suspended, and system code in $opted (lower-cased codes). Kept separate for tests. */
    public static function filter_opted( array $libs, array $opted ): array {
        return array_filter( $libs, static fn( array $l ): bool => empty( $l['suspended'] ) && in_array( strtolower( trim( $l['system'] ) ), $opted, true ) );
    }

    /** True for a Library-Data `suspend` value meaning suspended ('1', or 'Yes' on older rows, as the library profile reads it). */
    public static function is_suspended( $val ): bool {
        return in_array( strtolower( trim( (string) $val ) ), [ '1', 'yes' ], true );
    }

    /**
     * Every participant with an ILL email address, whatever its system or suspension: recnum => [ 'name',
     * 'system', 'email', 'suspended' (bool), 'until' (suspension end date, YYYY-MM-DD or '') ], sorted by system
     * then name. Null when the ILL database can't be reached or the query fails (the
     * Public Requests screen says so).
     */
    public static function eligible_libraries(): ?array {
        static $libs = false;
        if ( $libs !== false ) { return $libs; }
        $libs = null;
        try {
            $until = SEAL_DB::has_column( SEAL_DB::$sealLIB, 'SuspendDateEnd' ) ? ', `SuspendDateEnd`' : '';
            $r = SEAL_DB::query(
                "SELECT `recnum`, `Name`, `system`, `ill_email`, `suspend`{$until} FROM %t WHERE `participant`='1' AND `ill_email` IS NOT NULL AND `ill_email`<>'' ORDER BY `system`, `Name`",
                SEAL_DB::$sealLIB
            );
        } catch ( RuntimeException $e ) {
            error_log( 'ILL public request: library list unavailable: ' . $e->getMessage() );
            return $libs;
        }
        if ( ! $r ) { return $libs; }
        $libs = [];
        while ( $row = $r->fetch_assoc() ) {
            $libs[ (int) $row['recnum'] ] = [
                'name'      => (string) $row['Name'],
                'system'    => (string) $row['system'],
                'email'     => (string) $row['ill_email'],
                'suspended' => self::is_suspended( $row['suspend'] ?? '0' ),
                'until'     => (string) ( $row['SuspendDateEnd'] ?? '' ),
            ];
        }
        return $libs;
    }

    // ── Validation (no WordPress state; tests/test-public-request.php) ───────

    /**
     * Checks a submission. $in is the raw (unslashed) POST, $libs the libraries() list.
     * Returns [ errors (field => message), clean values ].
     */
    public static function validate( array $in, array $libs ): array {
        $v = [
            'title'   => trim( sanitize_text_field( (string) ( $in['seal_pr_title'] ?? '' ) ) ),
            'email'   => trim( sanitize_text_field( (string) ( $in['seal_pr_email'] ?? '' ) ) ),
            'phone'   => trim( sanitize_text_field( (string) ( $in['seal_pr_phone'] ?? '' ) ) ),
            'card'    => trim( sanitize_text_field( (string) ( $in['seal_pr_card']  ?? '' ) ) ),
            'library' => (int) ( $in['seal_pr_library'] ?? 0 ),
            // Line breaks kept, but \r\n counted as one character, as the browser's maxlength does.
            'note'    => trim( str_replace( [ "\r\n", "\r" ], "\n", sanitize_textarea_field( (string) ( $in['seal_pr_note'] ?? '' ) ) ) ),
        ];
        $e = [];

        if ( $v['title'] === '' ) {
            $e['title'] = 'Enter the title of the book.';
        } elseif ( mb_strlen( $v['title'] ) > 255 ) {
            $e['title'] = 'The title is too long (255 characters at most).';
        }

        if ( $v['email'] !== '' && ! is_email( $v['email'] ) ) {
            $e['email'] = 'Enter a valid email address, like name@example.com.';
        }
        $digits = preg_replace( '/\D/', '', $v['phone'] );
        if ( $v['phone'] !== '' && ( ! preg_match( '/^[0-9 ()+.\-]+(\s*(x|ext\.?)\s*\d+)?$/i', $v['phone'] ) || strlen( $digits ) < 7 || strlen( $digits ) > 20 ) ) {
            $e['phone'] = 'Enter a valid phone number, like 555-555-0100.';
        }
        if ( $v['email'] === '' && $v['phone'] === '' ) {
            $e['contact'] = 'Enter an email address or a phone number so the library can reach you.';
        }

        if ( $v['card'] === '' ) {
            $e['card'] = 'Enter your library card number.';
        } elseif ( ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9 \-]{2,29}$/', $v['card'] ) ) {
            $e['card'] = 'Enter your library card number using letters and numbers only.';
        }

        if ( ! isset( $libs[ $v['library'] ] ) ) {
            $e['library'] = 'Choose the library where you will pick up the book.';
        }

        if ( mb_strlen( $v['note'] ) > self::NOTE_MAX ) {
            $e['note'] = 'The note is too long (' . self::NOTE_MAX . ' characters at most).';
        }

        return [ $e, $v ];
    }

    /** A "form shown at" token: time plus a signature, so the submission time can't be faked. */
    public static function make_token( int $time ): string {
        return $time . '.' . substr( wp_hash( self::ACTION . '|' . $time, 'nonce' ), 0, 20 );
    }

    /** Seconds since the form was shown, or -1 when the token is missing or forged. */
    public static function token_age( string $token, int $now ): int {
        if ( ! preg_match( '/^(\d{9,11})\.([a-f0-9]{20})$/', $token, $m ) ) { return -1; }
        if ( ! hash_equals( self::make_token( (int) $m[1] ), $token ) ) { return -1; }
        return $now - (int) $m[1];
    }

    // ── Submission ────────────────────────────────────────────────────────────

    /**
     * Handles a submitted form before the page is drawn: on success emails the library and redirects back
     * with ?seal_pr=sent (so reloading doesn't send it twice); on errors leaves them for render().
     */
    public static function handle_post(): void {
        if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' || ( $_POST['seal_pr_action'] ?? '' ) !== self::ACTION ) {
            return;
        }
        $in      = wp_unslash( $_POST );
        $back    = remove_query_arg( self::SENT_ARG, SEAL_Functions::self_url() );
        $sent_to = add_query_arg( self::SENT_ARG, 'sent', $back );

        // Honeypot filled in: a bot. Pretend it worked so it learns nothing.
        if ( trim( (string) ( $in['seal_pr_website'] ?? '' ) ) !== '' ) {
            wp_safe_redirect( $sent_to );
            exit;
        }

        $libs           = self::libraries();
        [ $err, $vals ] = self::validate( $in, $libs );
        self::$values   = $vals;

        $age = self::token_age( (string) ( $in['seal_pr_t'] ?? '' ), time() );
        if ( $age < self::MIN_SECONDS ) {
            $err['form'] = 'Your request could not be sent. Please check the form and send it again.';
        }

        $rate_key = 'seal_pr_rl_' . md5( SEAL_Module_LoginProtection::client_ip() );
        $count    = (int) get_transient( $rate_key );
        if ( ! $err && $count >= self::RATE_MAX ) {
            $err['form'] = 'Too many requests have been sent from your connection. Please try again in an hour, or contact your library.';
        }

        if ( $err ) {
            self::$errors = $err;
            return;
        }

        $lib = $libs[ $vals['library'] ];
        if ( ! self::send( $lib, $vals ) ) {
            self::$errors = [ 'form' => 'Your request could not be sent right now. Please try again later, or contact ' . $lib['name'] . ' directly.' ];
            return;
        }
        set_transient( $rate_key, $count + 1, self::RATE_WINDOW );

        wp_safe_redirect( $sent_to );
        exit;
    }

    /** Emails the request to the pickup library. Replies go to the patron when they gave an email address. */
    private static function send( array $lib, array $v ): bool {
        $to = implode( ',', array_filter( array_map( 'trim', preg_split( '/[,;]/', $lib['email'] ) ), 'is_email' ) );
        if ( $to === '' ) { return false; }

        $tel  = preg_replace( '/[^0-9+]/', '', $v['phone'] );
        $body = SEAL_Functions::email_heading( 'Book request from a patron' )
              . SEAL_Functions::email_p( 'A patron used the public <strong>Request a Book</strong> form to ask for this title, to be picked up at <strong>' . esc_html( $lib['name'] ) . '</strong>. Please check their library card and follow up with them.' )
              . SEAL_Functions::email_details( [
                    'Title'               => $v['title'],
                    'Library card number' => $v['card'],
                    'Email*'              => $v['email'] !== '' ? SEAL_Functions::email_mailto( $v['email'] ) : '',
                    'Phone*'              => $v['phone'] !== '' ? '<a href="tel:' . esc_attr( $tel ) . '">' . esc_html( $v['phone'] ) . '</a>' : '',
                    'Pickup library'      => $lib['name'],
                    'Note from patron'    => $v['note'],
                    'Submitted'           => wp_date( 'F j, Y g:i a' ),
                ] )
              . ( $v['email'] !== '' ? SEAL_Functions::email_p( 'Replying to this email will reach the patron.' ) : '' );

        $reply_to = $v['email'] !== '' && is_email( $v['email'] ) ? $v['email'] : '';
        return SEAL_Functions::send_email( $to, 'Book request: ' . mb_strimwidth( $v['title'], 0, 80, '…' ), $body, false, false, $reply_to );
    }

    // ── Form ──────────────────────────────────────────────────────────────────

    /** [ill_book_request]: the form, the "request sent" message, or a notice when no libraries are available. */
    public static function render( $atts = [] ): string {
        wp_enqueue_style( 'seal-style', SEAL_ILL_PLUGIN_URL . 'assets/seal-style.css', [], SEAL_ILL_VERSION );

        if ( ( $_GET[ self::SENT_ARG ] ?? '' ) === 'sent' && ! self::$errors ) {
            $again = remove_query_arg( self::SENT_ARG, SEAL_Functions::self_url() );
            return '<div class="seal-alert seal-alert-success seal-pr-done" role="status"><p><strong>Thank you! Your request has been sent.</strong></p>'
                 . '<p>The library you chose will contact you about your book.</p>'
                 . '<p><a href="' . esc_url( $again ) . '">Request another book</a></p></div>';
        }

        $libs = self::libraries();
        if ( ! $libs ) {
            $why = '';
            if ( current_user_can( 'manage_options' ) ) {   // tell an administrator why; visitors just see the notice
                $why = '<p><strong>Administrators only:</strong> ' . ( self::eligible_libraries() === null
                    ? 'the ILL database could not be read (see ILL System &rarr; Database).'
                    : 'no library system has opted in, or the opted-in systems have no participating, unsuspended library with an ILL email address.' )
                    . ' <a href="' . esc_url( admin_url( 'admin.php?page=seal-ill-pubreq' ) ) . '">Public Requests settings</a></p>';
            }
            return '<div class="seal-alert seal-alert-info" role="status"><p>Book requests are not available right now. Please contact your library.</p>' . $why . '</div>';
        }

        $v   = self::$values + [ 'title' => '', 'email' => '', 'phone' => '', 'card' => '', 'library' => 0, 'note' => '' ];
        $e   = self::$errors;
        $grp = [];
        foreach ( $libs as $id => $lib ) {
            $grp[ $lib['system'] !== '' ? SEAL_Functions::system_name( $lib['system'] ) : 'Other' ][ $id ] = $lib['name'];
        }

        $err_id = static fn( string $f ): string => 'seal-pr-' . $f . '-err';
        $msg    = static fn( string $f ): string => isset( $e[ $f ] ) ? '<span class="seal-pr-err" id="' . $err_id( $f ) . '">' . esc_html( $e[ $f ] ) . '</span>' : '';
        $aria   = static function ( string $f, string $hint = '' ) use ( $e, $err_id ): string {
            $ids = trim( $hint . ( isset( $e[ $f ] ) ? ' ' . $err_id( $f ) : '' ) );
            return ( isset( $e[ $f ] ) ? ' aria-invalid="true"' : '' ) . ( $ids !== '' ? ' aria-describedby="' . esc_attr( $ids ) . '"' : '' );
        };
        $contact_err = isset( $e['contact'] ) ? ' aria-invalid="true" aria-describedby="' . $err_id( 'contact' ) . '"' : '';

        ob_start();
        ?>
        <div class="seal-pr">
        <?php if ( $e ) : ?>
            <div class="seal-alert seal-alert-error" role="alert" tabindex="-1" id="seal-pr-errors">
                <p><strong>Please fix the following:</strong></p>
                <ul><?php foreach ( $e as $m ) : ?><li><?php echo esc_html( $m ); ?></li><?php endforeach; ?></ul>
            </div>
        <?php endif; ?>

        <form method="post" action="<?php echo esc_url( remove_query_arg( self::SENT_ARG, SEAL_Functions::self_url() ) ); ?>#seal-pr-form" id="seal-pr-form" class="seal-pr-form" novalidate>
            <input type="hidden" name="seal_pr_action" value="<?php echo esc_attr( self::ACTION ); ?>">
            <input type="hidden" name="seal_pr_t" value="<?php echo esc_attr( self::make_token( time() ) ); ?>">
            <div class="seal-pr-hp" aria-hidden="true">
                <label for="seal_pr_website">Leave this field empty</label>
                <input type="text" id="seal_pr_website" name="seal_pr_website" value="" tabindex="-1" autocomplete="off">
            </div>

            <p class="seal-pr-note">Fields marked <span aria-hidden="true">*</span><span class="seal-sr-only">with an asterisk</span> are required.</p>

            <p class="seal-pr-row">
                <label for="seal_pr_title">Book title <span aria-hidden="true">*</span></label>
                <input type="text" id="seal_pr_title" name="seal_pr_title" maxlength="255" required value="<?php echo esc_attr( $v['title'] ); ?>"<?php echo $aria( 'title' ); ?>>
                <?php echo $msg( 'title' ); ?>
            </p>

            <fieldset class="seal-pr-contact"<?php echo $contact_err; ?>>
                <legend>How can the library contact you? <span aria-hidden="true">*</span></legend>
                <p class="seal-pr-hint" id="seal-pr-contact-hint">Enter an email address, a phone number, or both.</p>
                <?php echo $msg( 'contact' ); ?>
                <p class="seal-pr-row">
                    <label for="seal_pr_email">Email address</label>
                    <input type="email" id="seal_pr_email" name="seal_pr_email" maxlength="254" autocomplete="email" value="<?php echo esc_attr( $v['email'] ); ?>"<?php echo $aria( 'email', 'seal-pr-contact-hint' ); ?>>
                    <?php echo $msg( 'email' ); ?>
                </p>
                <p class="seal-pr-row">
                    <label for="seal_pr_phone">Phone number</label>
                    <input type="tel" id="seal_pr_phone" name="seal_pr_phone" maxlength="30" autocomplete="tel" value="<?php echo esc_attr( $v['phone'] ); ?>"<?php echo $aria( 'phone', 'seal-pr-contact-hint' ); ?>>
                    <?php echo $msg( 'phone' ); ?>
                </p>
            </fieldset>

            <p class="seal-pr-row">
                <label for="seal_pr_card">Library card number <span aria-hidden="true">*</span></label>
                <input type="text" id="seal_pr_card" name="seal_pr_card" maxlength="30" required autocomplete="off" inputmode="text" value="<?php echo esc_attr( $v['card'] ); ?>"<?php echo $aria( 'card' ); ?>>
                <?php echo $msg( 'card' ); ?>
            </p>

            <p class="seal-pr-row">
                <label for="seal_pr_library">Pick up at <span aria-hidden="true">*</span></label>
                <select id="seal_pr_library" name="seal_pr_library" required<?php echo $aria( 'library' ); ?>>
                    <option value="">Choose a library…</option>
                    <?php foreach ( $grp as $system => $opts ) : ?>
                        <?php if ( count( $grp ) > 1 ) : ?><optgroup label="<?php echo esc_attr( $system ); ?>"><?php endif; ?>
                        <?php foreach ( $opts as $id => $name ) : ?>
                            <option value="<?php echo (int) $id; ?>" <?php selected( (int) $v['library'], (int) $id ); ?>><?php echo esc_html( $name ); ?></option>
                        <?php endforeach; ?>
                        <?php if ( count( $grp ) > 1 ) : ?></optgroup><?php endif; ?>
                    <?php endforeach; ?>
                </select>
                <?php echo $msg( 'library' ); ?>
            </p>

            <p class="seal-pr-row">
                <label for="seal_pr_note">Note to the library <span class="seal-pr-optional">(optional)</span></label>
                <textarea id="seal_pr_note" name="seal_pr_note" rows="3" maxlength="<?php echo (int) self::NOTE_MAX; ?>"<?php echo $aria( 'note', 'seal-pr-note-hint' ); ?>><?php echo esc_textarea( $v['note'] ); ?></textarea>
                <span class="seal-pr-hint" id="seal-pr-note-hint">For example: the author, edition, or large print. Up to <?php echo (int) self::NOTE_MAX; ?> characters.</span>
                <?php echo $msg( 'note' ); ?>
            </p>

            <p><button type="submit" class="btn-primary seal-pr-submit">Send request</button></p>
        </form>
        </div>
        <?php if ( $e ) : ?>
        <script>document.getElementById('seal-pr-errors').focus();</script>
        <?php endif; ?>
        <?php
        return (string) ob_get_clean();
    }
}
