<?php
/**
 * SEAL_Emails — editable email subjects and message text, and WordPress's own account emails in the branded layout.
 *
 * Message text: the heading, opening message and closing line of each ILL and scheduled-job email (texts()),
 * stored as seal_emailtext_<key>_<heading|message|closing>. Blank = default; "-" = leave that part out. The
 * details table and buttons are always added by the code. The cron scripts read the same options (ill_text()).
 *
 * Subjects: every email a person receives has a subject template under ILL → Email Subjects, stored as one
 * option per email (seal_subject_<key>). Blank = the built-in default from all(). Tokens in braces ({ill},
 * {title}, {system} ...) are filled in when the email is sent; an unknown token is left as typed. The server's
 * scheduled jobs read the same options (cron_config.php → ill_subject()), so the "Scheduled jobs" defaults
 * here must match the ones passed in the cron scripts.
 *
 * Account emails: WordPress builds the "set your password" email for a new account and the password reset
 * email itself, as plain text from wordpress@<host>. The filters below rebuild both in the plugin's layout
 * (logo, brand colour, button) from the plugin's From: address, keeping WordPress's own one-time link. These
 * are not held by Email test mode, so people can always set a password.
 *
 * Internal alerts to the administrators (database failures, missing library email and so on) keep fixed
 * subjects and are not listed.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class SEAL_Emails {

    const OPT = 'seal_subject_';

    /**
     * Every editable subject: key => [ group, label (who receives it), default, tokens besides {system} ].
     * Group names are the section headings on the settings screen.
     */
    public static function all(): array {
        return [
            // Requests
            'request_new'          => [ 'Requests', 'New request (to the lending library)',                 'ILL #{ill} — New Request for "{title}"',             [ 'ill', 'title' ] ],
            'request_confirm'      => [ 'Requests', 'Request confirmation (to the borrower)',               'ILL #{ill} — Confirmation',                          [ 'ill', 'title' ] ],
            'request_resent'       => [ 'Requests', 'Request resent by an ILL admin (to the lending library)', 'ILL #{ill} — Request (resent) for "{title}"',      [ 'ill', 'title' ] ],
            'request_confirm_copy' => [ 'Requests', 'Confirmation copy resent by an ILL admin (to the borrower)', 'ILL #{ill} — Confirmation (copy)',          [ 'ill', 'title' ] ],
            'request_filled'       => [ 'Requests', 'Request filled (to the borrower)',                     'ILL Request Filled — ILL# {ill}',                    [ 'ill', 'title' ] ],
            'request_not_filled'   => [ 'Requests', 'Request can\'t be filled (to the borrower)',           'ILL Request Cannot be Filled — ILL# {ill}',          [ 'ill', 'title' ] ],
            'request_cancelled'    => [ 'Requests', 'Request cancelled (to the borrower and the lender)',   '{system} ILL Request Cancelled — ILL# {ill}',        [ 'ill', 'title' ] ],
            // Renewals
            'renew_request'        => [ 'Renewals', 'Renewal request (to the lending library)',             '{system} Renewal Request — ILL# {ill}',              [ 'ill', 'title' ] ],
            'renew_approved'       => [ 'Renewals', 'Renewal approved (to the borrower)',                   '{system} Renewal Approved — ILL# {ill}',             [ 'ill', 'title' ] ],
            'renew_denied'         => [ 'Renewals', 'Renewal denied (to the borrower)',                     '{system} Renewal Denied — ILL# {ill}',               [ 'ill', 'title' ] ],
            // Automatic Fallback
            'rota_new_lender'      => [ 'Automatic Fallback', 'Request moved to the next library (to that library)', '{system} ILL Request (auto-routed) — ILL# {ill} for "{title}"', [ 'ill', 'title' ] ],
            'rota_update'          => [ 'Automatic Fallback', 'Request moved to the next library (to the borrower)', '{system} ILL Request Update — ILL# {ill}',    [ 'ill', 'title' ] ],
            'rota_exhausted'       => [ 'Automatic Fallback', 'No library on the list could fill it (to the borrower)', '{system} ILL Request Could Not Be Filled — ILL# {ill}', [ 'ill', 'title' ] ],
            // Accounts
            'account_welcome'      => [ 'Accounts', 'New account: set your password (to the new user)',     'Welcome to {system} — set your password',            [ 'name', 'username' ] ],
            'password_reset'       => [ 'Accounts', 'Password reset link (to the user)',                    '{system} password reset',                            [ 'name', 'username' ] ],
            'account_pending'      => [ 'Accounts', 'New registration waiting for approval (to the administrators)', 'New user registration — {username}',     [ 'name', 'username' ] ],
            'account_approved'     => [ 'Accounts', 'Registration approved (to the new user)',              'Your {system} account has been approved',            [ 'name', 'username' ] ],
            'account_denied'       => [ 'Accounts', 'Registration not approved (to the new user)',          'Your {system} account registration status',          [ 'name', 'username' ] ],
            'account_reminder'     => [ 'Accounts', 'Set-up reminder, 3 days and 1 day before the link expires (to people who haven\'t set a password)', 'Reminder: set your {system} password — your link expires in {left}', [ 'name', 'username', 'left', 'expires' ] ],
            'security_changed'     => [ 'Accounts', 'Two-factor settings changed (to the user)',            'Your sign-in security settings changed',             [ 'name', 'username' ] ],
            // Scheduled jobs (server cron scripts; keep these defaults in step with the scripts)
            'cron_reminder'        => [ 'Scheduled jobs', '3-day reminder (to the lender and the borrower)', 'REMINDER: ILL Request from {library} — ILL# {ill}', [ 'ill', 'library' ] ],
            'cron_expired'         => [ 'Scheduled jobs', 'Request expired (to the borrower)',               'ILL Request Expired — ILL# {ill}',                   [ 'ill', 'title' ] ],
            'cron_expired_lender'  => [ 'Scheduled jobs', 'Request expired (to the lending library)',        '{system} ILL Expired — ILL# {ill}',                  [ 'ill', 'title' ] ],
            'cron_due_date'        => [ 'Scheduled jobs', 'Due date approaching (to the borrower)',          '{system} Approaching Due Date — ILL# {ill}',         [ 'ill', 'title' ] ],
            'cron_new_due_date'    => [ 'Scheduled jobs', 'ILLiad lender set a new due date (to the borrower)', 'Request {ill} has a new due date',             [ 'ill' ] ],
            'cron_illiad_filled'   => [ 'Scheduled jobs', 'ILLiad lender filled it (to the borrower)',       'ILL Request Filled ILL# {ill}',                      [ 'ill', 'title' ] ],
            'cron_illiad_not_filled' => [ 'Scheduled jobs', 'ILLiad lender can\'t fill it (to the borrower)', 'ILL Request Not Filled ILL# {ill}',                [ 'ill', 'title' ] ],
            'cron_weekly_stats'    => [ 'Scheduled jobs', 'Weekly statistics (to each library)',             '{system} Stats — {start} to {end}',                  [ 'start', 'end' ] ],
        ];
    }

    /** What each token means, for the settings screen. */
    public static function token_help(): array {
        return [
            'system'   => 'System Short Name',
            'ill'      => 'ILL number',
            'title'    => 'item title',
            'name'     => 'person\'s name',
            'username' => 'username',
            'library'  => 'borrowing library',
            'start'    => 'first day of the week',
            'end'      => 'last day of the week',
            'left'     => 'time left: “3 days”, “1 day” or “less than a day”',
            'expires'  => 'when the link stops working, e.g. Friday, November 6 at 2:15 pm',
            'borrower_library' => 'borrowing library',
            'lender_library'   => 'lending library',
        ];
    }

    /**
     * The subject for one email: the saved template (or the default), with tokens filled in. Always one line.
     * Plugin emails sent through SEAL_Functions::send_email() still get "[System]" in front when the subject
     * doesn't already contain the System Short Name.
     */
    public static function subject( string $key, array $vars = [] ): string {
        $all = self::all();
        $tpl = trim( (string) get_option( self::OPT . $key, '' ) );
        if ( $tpl === '' ) { $tpl = $all[ $key ][2] ?? $key; }
        $vars += [ 'system' => seal_config( 'system_name' ) ];
        $map = [];
        foreach ( $vars as $k => $v ) { $map[ '{' . $k . '}' ] = wp_strip_all_tags( (string) $v ); }
        return trim( preg_replace( '/\s+/', ' ', strtr( $tpl, $map ) ) );
    }

    /** Sanitizer for a subject field: one line of plain text, at most 200 characters. */
    public static function sanitize( $v ): string {
        return mb_substr( sanitize_text_field( (string) $v ), 0, 200 );
    }

    // ── Message text (ILL and scheduled-job emails) ──────────────────────────

    const TEXT_OPT = 'seal_emailtext_';
    const PARTS    = [ 'heading' => 'Heading', 'message' => 'Message', 'closing' => 'Closing' ];

    /**
     * The editable text of each ILL and scheduled-job email: key => [ group, label, heading, message, closing,
     * tokens besides {system}, subject key ]. The details table (ILL #, title ...) and the buttons are added by
     * the code, so they're not here. Where the key matches a key in all(), the subject is edited alongside;
     * otherwise the email shares the named subject. Message and closing use the light formatting in render().
     * The "Scheduled jobs" defaults must match the ones passed in the cron scripts (ill_text()).
     */
    public static function texts(): array {
        $search = 'You can search the catalog again and request the item from another library.';
        $answer = 'Please answer with one of the buttons below.';
        return [
            'request_new'          => [ 'Requests', 'New request (to the lending library)', 'New interlibrary loan request',
                                        "**{borrower_library}** has asked your library to lend this item. $answer", '', [ 'ill', 'title', 'borrower_library' ], 'request_new' ],
            'request_confirm'      => [ 'Requests', 'Request confirmation (to the borrower)', 'Your request has been sent',
                                        'Dear {name}, your interlibrary loan request has been sent. Your request number is **{ill}**. You will get another email when the lending library answers.', '',
                                        [ 'ill', 'title', 'name' ], 'request_confirm' ],
            'request_resent'       => [ 'Requests', 'Request resent by an ILL admin (to the lending library)', 'Interlibrary loan request (sent again)',
                                        "*The ILL office is sending this request again. If you have already answered it, you can ignore this message.*\n\n**{borrower_library}** has asked your library to lend this item. $answer", '',
                                        [ 'ill', 'title', 'borrower_library' ], 'request_resent' ],
            'request_confirm_copy' => [ 'Requests', 'Confirmation copy resent by an ILL admin (to the borrower)', 'Your request confirmation (copy)',
                                        '*The ILL office is sending you a copy of your request confirmation.*', '', [ 'ill', 'title' ], 'request_confirm_copy' ],
            'request_filled'       => [ 'Requests', 'Request filled (to the borrower)', 'Your request will be filled',
                                        'Good news: **{lender_library}** will fill your interlibrary loan request.', '', [ 'ill', 'title', 'lender_library' ], 'request_filled' ],
            'request_not_filled'   => [ 'Requests', 'Request can\'t be filled (to the borrower)', 'Your request can\'t be filled',
                                        '**{lender_library}** is not able to fill your interlibrary loan request.', $search, [ 'ill', 'title', 'lender_library' ], 'request_not_filled' ],
            'request_cancelled'    => [ 'Requests', 'Request cancelled (to the borrower and the lender)', 'Request cancelled',
                                        '**{borrower_library}** has cancelled this interlibrary loan request. No further action is needed.', '', [ 'ill', 'title', 'borrower_library' ], 'request_cancelled' ],
            'renew_request'        => [ 'Renewals', 'Renewal request (to the lending library)', 'Renewal requested',
                                        '**{borrower_library}** has asked to renew an item your library lent. Please approve or deny it.', '', [ 'ill', 'title', 'borrower_library' ], 'renew_request' ],
            'renew_approved'       => [ 'Renewals', 'Renewal approved (to the borrower)', 'Renewal approved',
                                        'Your renewal request has been **approved**. Please return the item by the new due date.', '', [ 'ill', 'title' ], 'renew_approved' ],
            'renew_denied'         => [ 'Renewals', 'Renewal denied (to the borrower)', 'Renewal denied',
                                        'Your renewal request has been **denied**. Please return the item by its current due date.', '', [ 'ill', 'title' ], 'renew_denied' ],
            'rota_new_lender'      => [ 'Automatic Fallback', 'Moved to the next library after a "no" (to that library)', 'Interlibrary loan request (automatically routed)',
                                        "This request was **automatically routed to your library** because the previous library on the borrower's list could not fill it. $answer", '',
                                        [ 'ill', 'title' ], 'rota_new_lender' ],
            'rota_update'          => [ 'Automatic Fallback', 'Moved to the next library after a "no" (to the borrower)', 'Your request is being tried at another library',
                                        'The previous library could not fill your request, so it has automatically moved to the next library on your list. You don\'t need to do anything.', '',
                                        [ 'ill', 'title' ], 'rota_update' ],
            'rota_exhausted'       => [ 'Automatic Fallback', 'No library on the list could fill it (to the borrower)', 'Your request can\'t be filled',
                                        'Every library on your automatic list was asked, and none of them could fill your request.', $search, [ 'ill', 'title' ], 'rota_exhausted' ],
            'cron_rota_new_lender' => [ 'Automatic Fallback', 'Moved to the next library after no answer (to that library)', 'Interlibrary loan request (automatically routed)',
                                        "This request was **automatically routed to your library** because the previous library on the borrower's list did not answer within 5 business days. $answer", '',
                                        [ 'ill', 'title' ], 'rota_new_lender' ],
            'cron_rota_update'     => [ 'Automatic Fallback', 'Moved to the next library after no answer (to the borrower)', 'Your request is being tried at another library',
                                        'The previous library did not answer within 5 business days, so your request has automatically moved to the next library on your list. You don\'t need to do anything.', '',
                                        [ 'ill', 'title' ], 'rota_update' ],
            'cron_reminder'        => [ 'Scheduled jobs', '3-day reminder (to the lending library)', 'Reminder: a request is waiting for your answer',
                                        "**{library}** asked your library to lend this item a few business days ago and has not had an answer yet. $answer", '', [ 'ill', 'title', 'library' ], 'cron_reminder' ],
            'cron_reminder_borrower' => [ 'Scheduled jobs', '3-day reminder (copy to the borrower)', 'Still waiting for an answer',
                                        'The lending library has not answered your request yet, so it has been sent a reminder. You don\'t need to do anything.', '', [ 'ill', 'title' ], 'cron_reminder' ],
            'cron_expired'         => [ 'Scheduled jobs', 'Request expired (to the borrower)', 'Your request has expired',
                                        'The lending library did not answer your request within five business days, so it has expired.', $search, [ 'ill', 'title', 'lender_library' ], 'cron_expired' ],
            'cron_expired_lender'  => [ 'Scheduled jobs', 'Request expired (to the lending library)', 'A request to your library has expired',
                                        'Your library did not answer this request within five business days, so it has expired and is now closed. No further action is needed.', '',
                                        [ 'ill', 'title' ], 'cron_expired_lender' ],
            'cron_due_date'        => [ 'Scheduled jobs', 'Due date approaching (to the borrower)', 'An item you borrowed is due soon',
                                        'This interlibrary loan item is due back in five days. Please return it on time, or ask the lending library for a renewal.', '', [ 'ill', 'title' ], 'cron_due_date' ],
            'cron_new_due_date'    => [ 'Scheduled jobs', 'ILLiad lender set a new due date (to the borrower)', 'New due date',
                                        'The lending library has changed the due date of an item you borrowed.', '', [ 'ill', 'title' ], 'cron_new_due_date' ],
            'cron_illiad_filled'   => [ 'Scheduled jobs', 'ILLiad lender filled it (to the borrower)', 'Your request will be filled',
                                        'Good news: **{lender_library}** will fill your interlibrary loan request.', '', [ 'ill', 'title', 'lender_library' ], 'cron_illiad_filled' ],
            'cron_illiad_article'  => [ 'Scheduled jobs', 'ILLiad lender sent an article (to the borrower)', 'Your article is ready',
                                        '**{lender_library}** has filled your request through OCLC Article Exchange. Use the link and password below to download it.', '',
                                        [ 'ill', 'title', 'lender_library' ], 'cron_illiad_filled' ],
            'cron_illiad_not_filled' => [ 'Scheduled jobs', 'ILLiad lender can\'t fill it (to the borrower)', 'Your request can\'t be filled',
                                        '**{lender_library}** is not able to fill your interlibrary loan request.', $search, [ 'ill', 'title', 'lender_library' ], 'cron_illiad_not_filled' ],
            'account_reminder'     => [ 'Accounts', 'Set-up reminder, 3 days and 1 day before the link expires (to people who haven\'t set a password)', 'Your {system} account isn\'t set up yet',
                                        "Hello {name}, an account was created for you on {system}, but no password has been set yet. Your link to set one expires in **{left}** ({expires}).", '',
                                        [ 'name', 'username', 'left', 'expires' ], 'account_reminder' ],
            'cron_weekly_stats'    => [ 'Scheduled jobs', 'Weekly statistics (to each library)', 'Weekly statistics',
                                        'Requests placed from **{start}** to **{end}**.', '', [ 'start', 'end' ], 'cron_weekly_stats' ],
        ];
    }

    /** The saved text for one part of one email, or its default. */
    private static function raw( string $key, string $part ): string {
        $t   = self::texts()[ $key ] ?? null;
        $def = $t ? (string) $t[ [ 'heading' => 2, 'message' => 3, 'closing' => 4 ][ $part ] ] : '';
        $v   = trim( (string) get_option( self::TEXT_OPT . $key . '_' . $part, '' ) );
        if ( $v === '-' ) { return ''; }   // "-" = leave this part out
        return $v !== '' ? $v : $def;
    }

    /**
     * Turns saved text into email HTML: everything escaped, tokens filled in (escaped), **bold**, *italic*,
     * a blank line starts a new paragraph, a single line break stays a line break. The same rules are in the
     * cron scripts (ill_render_text()).
     */
    public static function render( string $text, array $vars, bool $paragraphs = true ): string {
        $text = trim( str_replace( "\r", '', $text ) );
        if ( $text === '' ) { return ''; }
        $vars += [ 'system' => seal_config( 'system_name' ) ];
        $map = [];
        foreach ( $vars as $k => $v ) { $map[ '{' . $k . '}' ] = esc_html( (string) $v ); }
        $html = strtr( esc_html( $text ), $map );
        $html = preg_replace( '/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $html );
        $html = preg_replace( '/(?<![\w*])\*(?!\s)(.+?)(?<!\s)\*(?![\w*])/s', '<em>$1</em>', $html );
        if ( ! $paragraphs ) { return $html; }
        $out = '';
        foreach ( preg_split( '/\n\s*\n/', $html ) as $para ) {
            $out .= '<p style="margin:0 0 14px;">' . nl2br( trim( $para ), false ) . '</p>';
        }
        return $out;
    }

    /** The heading of an email, as HTML. */
    public static function heading( string $key, array $vars = [] ): string {
        $h = self::raw( $key, 'heading' );
        return $h === '' ? '' : '<h1 style="margin:0 0 12px;font-size:20px;line-height:1.3;color:#1d2327;">' . self::render( $h, $vars, false ) . '</h1>';
    }

    /** The opening message of an email, as HTML paragraphs ('' when left out). */
    public static function message( string $key, array $vars = [] ): string {
        return self::render( self::raw( $key, 'message' ), $vars );
    }

    /** The closing text of an email, as HTML paragraphs ('' when there is none). */
    public static function closing( string $key, array $vars = [] ): string {
        return self::render( self::raw( $key, 'closing' ), $vars );
    }

    /** Sanitizer for a heading: one line, at most 200 characters. */
    public static function sanitize_line( $v ): string {
        return mb_substr( sanitize_text_field( (string) $v ), 0, 200 );
    }

    /** Sanitizer for a message or closing: plain text with line breaks, at most 2000 characters. */
    public static function sanitize_text( $v ): string {
        return mb_substr( sanitize_textarea_field( (string) $v ), 0, 2000 );
    }

    // ── Change history (Email Text screen) ───────────────────────────────────

    /** Records every saved change to a subject or text option in the change history (SEAL_Audit, area "Email text"). */
    public static function init_log(): void {
        $watch = [];
        foreach ( self::all() as $key => $s ) { $watch[ self::OPT . $key ] = [ $key, 'Subject' ]; }
        foreach ( self::texts() as $key => $t ) {
            foreach ( self::PARTS as $part => $label ) { $watch[ self::TEXT_OPT . $key . '_' . $part ] = [ $key, $label ]; }
        }
        foreach ( $watch as $opt => [ $key, $label ] ) {
            $name = (string) ( self::texts()[ $key ][1] ?? self::all()[ $key ][1] ?? $key );
            add_action( 'update_option_' . $opt, static function ( $old, $new ) use ( $name, $label ) { SEAL_Audit::stage( 'email', $name, $label, (string) $old, (string) $new ); }, 10, 2 );
            add_action( 'add_option_' . $opt,    static function ( $o, $new ) use ( $name, $label ) { SEAL_Audit::stage( 'email', $name, $label, '', (string) $new ); }, 10, 2 );
        }
    }

    /** The Change history under the Email Text form (email changes only; everything is on ILL → Change History). */
    public static function render_log(): void {
        echo '<h2 id="email-history">Change history</h2>';
        echo '<p class="description">Every saved change to an email subject or text: who, when, and the wording before and after. "(default)" means the built-in text. '
           . 'Kept for two years. Also on <a href="' . esc_url( admin_url( 'admin.php?page=seal-ill-history&area=email' ) ) . '">ILL → Change History</a>.</p>';
        SEAL_Audit::render( 'email', 300 );
    }

    // ── WordPress's account emails in the branded layout ─────────────────────

    public static function init(): void {
        add_filter( 'password_reset_expiration',            [ __CLASS__, 'link_seconds' ] );
        add_filter( 'wp_new_user_notification_email',       [ __CLASS__, 'new_user_email' ], 20, 3 );
        add_filter( 'retrieve_password_notification_email', [ __CLASS__, 'reset_email' ],    20, 4 );
    }

    /** Allowed values for Users & Registration → Password Link Valid For (days). */
    const LINK_DAYS = [ 1, 2, 3, 5, 7, 10, 14, 30 ];

    /**
     * How long the set-password / reset link works (WordPress's password_reset_expiration, default one day):
     * the Users & Registration setting in days. Covers new-account emails and every password reset.
     */
    public static function link_seconds( $seconds ): int {
        $d = (int) seal_config( 'pw_link_days' );
        return in_array( $d, self::LINK_DAYS, true ) ? $d * DAY_IN_SECONDS : (int) $seconds;
    }

    /** That lifetime in words for emails and messages: "7 days", "24 hours". */
    public static function link_life(): string {
        $s = (int) apply_filters( 'password_reset_expiration', DAY_IN_SECONDS );
        if ( $s % DAY_IN_SECONDS === 0 && $s > DAY_IN_SECONDS ) { return ( $s / DAY_IN_SECONDS ) . ' days'; }
        $h = (int) round( $s / HOUR_IN_SECONDS );
        return $h === 1 ? '1 hour' : $h . ' hours';
    }

    /** WordPress's one-time "set your password" link inside its plain-text message ('' if not found). */
    private static function reset_link( string $message ): string {
        return preg_match( '#https?://\S*action=rp\S*#', $message, $m ) ? rtrim( $m[0], '>.' ) : '';
    }

    /** A wp_mail() array rebuilt as a branded HTML email from the plugin's From: address. */
    private static function rebuild( array $email, string $subject, string $body ): array {
        $from_name  = str_replace( [ "\r", "\n" ], '', seal_config( 'email_from_name' ) ) ?: seal_config( 'system_name' );
        $from_email = sanitize_email( seal_config( 'email_from' ) );
        $headers    = [ 'Content-Type: text/html; charset=UTF-8' ];
        if ( $from_email !== '' ) { $headers[] = "From: {$from_name} <{$from_email}>"; }
        $system = seal_config( 'system_name' );
        if ( $system !== '' && strpos( $subject, $system ) === false ) { $subject = "[{$system}] {$subject}"; }   // same rule as send_email()
        $email['subject'] = $subject;
        $email['message'] = SEAL_Functions::email_layout( $body, $subject );
        $email['headers'] = $headers;
        return $email;
    }

    /** Library name and code for the user's primary LOC, '' when none. */
    private static function library( int $uid ): string {
        $loc = SEAL_Functions::primary_loc( $uid );
        if ( $loc === '' ) { return (string) get_user_meta( $uid, 'institution', true ); }
        $lib = SEAL_Functions::fetch_lib_profile( $loc );
        return ! empty( $lib['ok'] ) ? $lib['Name'] . ' (' . $loc . ')' : $loc;
    }

    /** New account (self-registered, created in wp-admin, or by system staff): a welcome with a Set your password button. */
    public static function new_user_email( array $email, $user, $blogname ): array {
        if ( ! $user instanceof WP_User ) { return $email; }
        $link = self::reset_link( (string) ( $email['message'] ?? '' ) );
        if ( $link === '' ) { return $email; }   // not WordPress's usual message: leave it alone
        if ( class_exists( 'SEAL_Setup_Reminders' ) ) { SEAL_Setup_Reminders::mark_sent( (int) $user->ID ); }   // remind them if they don't set a password

        $sys     = seal_config( 'system_name' );
        $full    = trim( (string) seal_config( 'system_full_name' ) );
        $org     = trim( (string) seal_config( 'org_full_name' ) ) ?: trim( (string) seal_config( 'org_name' ) );
        $name    = trim( $user->first_name . ' ' . $user->last_name );
        $pending = get_user_meta( $user->ID, 'seal_approval_status', true ) === 'pending';

        $intro = 'An account has been created for you on <strong>' . esc_html( $sys ) . '</strong>'
               . ( $full !== '' && strcasecmp( $full, $sys ) !== 0 ? ' (' . esc_html( $full ) . ')' : '' )
               . ', the interlibrary loan system' . ( $org !== '' ? ' of ' . esc_html( $org ) : '' ) . '. '
               . 'To get started, choose a password.';

        $body = SEAL_Functions::email_heading( 'Welcome to ' . $sys . ( trim( $user->first_name ) !== '' ? ', ' . trim( $user->first_name ) : '' ) )
              . SEAL_Functions::email_p( $intro )
              . ( $pending
                    ? '<p style="margin:0 0 14px;padding:10px 14px;background:#fcf9e8;border-left:4px solid #dba617;"><strong>Your registration is waiting for approval.</strong> '
                      . 'You can set your password now; you\'ll be able to sign in once an administrator approves the account, and we\'ll email you when that happens.</p>'
                    : '' )
              . SEAL_Functions::email_button( $link, 'Set your password' )
              . '<p style="margin:6px 0 18px;font-size:13px;color:#50575e;">This link works once and expires in ' . esc_html( self::link_life() )
              . '. If it has expired, use <em>Lost your password?</em> on the <a href="' . esc_url( wp_login_url() ) . '">sign-in page</a> to get a new one.</p>'
              . SEAL_Functions::email_subheading( 'Your account' )
              . SEAL_Functions::email_details( [
                    'Name'     => $name,
                    'Username' => $user->user_login,
                    'Library'  => self::library( $user->ID ),
                    'Sign in*' => '<a href="' . esc_url( wp_login_url() ) . '">' . esc_html( preg_replace( '#^https?://#', '', wp_login_url() ) ) . '</a>',
                ] )
              . ( $pending ? '' : SEAL_Functions::email_p( 'Once you\'ve signed in you can search the catalog, request items from other libraries and answer requests sent to your library.' ) )
              . '<p style="margin:0;font-size:13px;color:#50575e;">Didn\'t expect this email? You can ignore it; nothing happens until the link is used.</p>';

        return self::rebuild( $email, self::subject( 'account_welcome', [ 'name' => $name, 'username' => $user->user_login ] ), $body );
    }

    /** Password reset (Lost your password?, or an administrator's "Send password reset"). */
    public static function reset_email( array $email, $key, $user_login, $user ): array {
        if ( ! $user instanceof WP_User ) { return $email; }
        $link = self::reset_link( (string) ( $email['message'] ?? '' ) );
        if ( $link === '' ) { return $email; }

        $sys   = seal_config( 'system_name' );
        $name  = trim( $user->first_name . ' ' . $user->last_name );
        $body  = SEAL_Functions::email_heading( 'Reset your password' )
               . SEAL_Functions::email_p( 'Someone asked to reset the password for your ' . esc_html( $sys ) . ' account.' )
               . SEAL_Functions::email_details( [ 'Username' => $user->user_login ] )
               . SEAL_Functions::email_button( $link, 'Choose a new password' )
               . '<p style="margin:6px 0 18px;font-size:13px;color:#50575e;">This link works once and expires in ' . esc_html( self::link_life() ) . '.</p>'
               . '<p style="margin:0;font-size:13px;color:#50575e;">If you didn\'t ask for this, you can ignore this email; your password stays the same.</p>';

        return self::rebuild( $email, self::subject( 'password_reset', [ 'name' => $name, 'username' => $user->user_login ] ), $body );
    }
}
