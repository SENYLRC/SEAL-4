<?php
/**
 * SEAL_Functions — utility methods shared across all SEAL pages.
 *
 * This replaces the original flat seal_function.php include.
 * All database access goes through SEAL_DB; all output goes through
 * WordPress escaping helpers.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class SEAL_Functions {

    // ── Output helpers ────────────────────────────────────────────────────────

    /** HTML-escape for output. */
    public static function h( $s ): string {
        return htmlspecialchars( (string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
    }

    /** URL for the current WP page. */
    public static function self_url(): string {
        // get_permalink() is authoritative within WP routing.
        // REQUEST_URI fallback is sanitized through esc_url().
        $uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_url( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
        return get_permalink() ?: esc_url( $uri );
    }

    // ── CSRF protection ───────────────────────────────────────────────────────

    /**
     * Output a hidden nonce field named 'seal_nonce'.
     * Call inside every <form>.
     */
    public static function nonce_field( string $action = 'seal_form' ): void {
        wp_nonce_field( $action, 'seal_nonce', true, true );
    }

    /**
     * Verify nonce; wp_die() on failure.
     */
    public static function verify_nonce( string $action = 'seal_form' ): void {
        if ( ! isset( $_REQUEST['seal_nonce'] ) ||
             ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST['seal_nonce'] ) ), $action )
        ) {
            wp_die(
                esc_html__( 'Security check failed. Please go back and try again.', 'seal-ill' ),
                esc_html__( 'Invalid Request', 'seal-ill' ),
                [ 'response' => 403 ]
            );
        }
    }

    // ── User profile helpers ──────────────────────────────────────────────────

    /** Get the user's primary LOC code. */
    public static function primary_loc( int $user_id ): string {
        $keys = [ 'address_loc_code', 'loc_location_code', 'seal_primary_loc', 'seal_loc', 'loc' ];
        foreach ( $keys as $k ) {
            $v = get_user_meta( $user_id, $k, true );
            if ( is_string( $v ) && trim( $v ) !== '' ) {
                return strtoupper( trim( $v ) );
            }
        }
        return '';
    }

    /** Parse comma-separated extra LOC codes from user meta. */
    public static function extra_locs( int $user_id ): array {
        $raw = get_user_meta( $user_id, 'seal_extra_locs', true );
        $raw = is_string( $raw ) ? trim( $raw ) : '';
        if ( $raw === '' ) { return []; }
        $out = [];
        foreach ( explode( ',', $raw ) as $c ) {
            $c = strtoupper( trim( $c ) );
            if ( $c !== '' ) { $out[] = $c; }
        }
        return array_values( array_unique( $out ) );
    }

    /** All LOC codes for a user (primary + extras), de-duplicated. */
    public static function all_locs( int $user_id ): array {
        $primary = self::primary_loc( $user_id );
        $extras  = self::extra_locs( $user_id );
        return array_values( array_unique(
            array_filter( array_merge( [ $primary ], $extras ) )
        ) );
    }

    // ── Library profile ───────────────────────────────────────────────────────

    /**
     * Fetch library profile row from the Library-Data table.
     *
     * @return array  [ 'ok'=>bool, 'loc'=>string, 'Name'=>string, … ]
     */
    public static function fetch_lib_profile( string $loc ): array {
        $loc = strtoupper( trim( $loc ) );
        if ( $loc === '' ) { return [ 'ok' => false ]; }

        $table = SEAL_DB::$sealLIB;
        $result = SEAL_DB::query(
            "SELECT `loc`,`Name`,`system`,`ill_email`,`phone`,`address1`,`address2`,`address3`
             FROM %t WHERE UPPER(TRIM(`loc`)) = %s LIMIT 1",
            $table, $loc
        );

        if ( ! $result ) { return [ 'ok' => false ]; }
        $row = $result->fetch_assoc();
        if ( ! $row ) { return [ 'ok' => false ]; }

        return [
            'ok'       => true,
            'loc'      => $loc,
            'Name'     => trim( $row['Name'] ?? $loc ),
            'system'   => trim( $row['system'] ?? '' ),
            'ill_email'=> trim( $row['ill_email'] ?? '' ),
            'phone'    => trim( $row['phone'] ?? '' ),
            'street1'  => trim( $row['address1'] ?? '' ),
            'street2'  => trim( $row['address2'] ?? '' ),
            'citystzip'=> trim( $row['address3'] ?? '' ),
        ];
    }

    // ── Status / fill helpers ─────────────────────────────────────────────────

    /** Human-readable status label for a Fill code. */
    public static function fill_label( $fill ): string {
        // Codes match the STATS.Fill column as used by the original SEAL-3 code.
        // Pass the raw column value: an empty Fill means "awaiting response", not 0.
        $map = [
            ''  => 'Pending',
            '0' => 'Not Filled',
            '1' => 'Filled',
            '3' => 'No Answer',
            '4' => 'Expired',
            '6' => 'Canceled',
        ];
        return $map[ (string) $fill ] ?? 'Unknown';
    }

    /**
     * Full request status as safe HTML (ported from SEAL-3 itemstatus()):
     * fill state, overridden by received / returned / lender check-in.
     */
    public static function item_status( array $r ): string {
        $fill_date = (string) ( $r['fillNofillDate'] ?? '' );
        if ( $fill_date === '0000-00-00' ) { $fill_date = ''; }

        $fill = (string) ( $r['Fill'] ?? '' );
        $text = self::fill_label( $fill );
        $date = ( $fill === '1' || $fill === '0' ) ? $fill_date : '';

        $recv_acct = (string) ( $r['receiveAccount']  ?? '' );
        $ret_acct  = (string) ( $r['returnAccount']   ?? '' );
        $chk_acct  = (string) ( $r['checkinAccount']  ?? '' );

        if ( strlen( $recv_acct ) > 1 && strlen( $ret_acct ) < 1 && strlen( $chk_acct ) < 1 ) {
            $text = 'Loan Item Received';
            $date = (string) ( $r['receiveDate'] ?? '' );
        }
        if ( strlen( $chk_acct ) < 1 && strlen( $recv_acct ) > 1 && strlen( $ret_acct ) > 1 ) {
            $text = 'Loan Item Returned';
            $date = (string) ( $r['returnDate'] ?? '' );
        }
        if ( strlen( $chk_acct ) > 1 ) {
            $text = 'Item Checkin by Lender';
            $date = (string) ( $r['checkinTimeStamp'] ?? '' );
        }

        return esc_html( $text ) . ( $date !== '' ? '<br>' . esc_html( $date ) : '' );
    }

    /** Catalog availability string to 1 (available) / 0 (not), as in the original request page. */
    public static function normalize_availability( $raw ): int {
        return SEAL_Org_Rules::normalize_availability( $raw );
    }

    // ── Brand colours ─────────────────────────────────────────────────────────

    /** WCAG 2.1 contrast ratio between two #rrggbb colours. */
    public static function contrast_ratio( string $a, string $b ): float {
        $lum = static function ( string $hex ): float {
            $hex = ltrim( $hex, '#' );
            if ( strlen( $hex ) === 3 ) { $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2]; }
            $c = [ hexdec( substr( $hex, 0, 2 ) ) / 255, hexdec( substr( $hex, 2, 2 ) ) / 255, hexdec( substr( $hex, 4, 2 ) ) / 255 ];
            $c = array_map( static fn( $v ) => $v <= 0.03928 ? $v / 12.92 : pow( ( $v + 0.055 ) / 1.055, 2.4 ), $c );
            return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
        };
        $la = $lum( $a ); $lb = $lum( $b );
        return ( max( $la, $lb ) + 0.05 ) / ( min( $la, $lb ) + 0.05 );
    }

    /** Configured brand colour, or the default when the stored value is missing or fails contrast. */
    public static function brand_color( string $key ): string {
        $defaults = [ 'primary' => '#4a4180', 'dark' => '#1d1f40', 'accent' => '#3fbb86' ];
        $val = sanitize_hex_color( seal_config( 'brand_' . $key ) );
        if ( ! $val ) { return $defaults[ $key ]; }
        if ( $key !== 'accent' && self::contrast_ratio( $val, '#ffffff' ) < 4.5 ) { return $defaults[ $key ]; }
        return $val;
    }

    /** Print brand colours as CSS variables and point the theme's link/button colours at them. */
    public static function print_brand_css(): void {
        $p = self::brand_color( 'primary' ); $d = self::brand_color( 'dark' ); $a = self::brand_color( 'accent' );
        echo '<style id="seal-brand-colors">:root:root{--seal-brand:' . $p . ';--seal-brand-dark:' . $d . ';--seal-accent:' . $a . ';'
           . '--nv-primary-accent:' . $p . ';--nv-secondary-accent:' . $d . ';'
           . '--primarybtnbg:' . $p . ';--primarybtncolor:#fff;--primarybtnhoverbg:' . $d . ';--primarybtnhovercolor:#fff;'
           . '--secondarybtncolor:' . $p . ';}'
           . 'body.login a{color:' . $p . ';text-decoration:underline;}body.login a:hover,body.login a:focus{color:' . $d . ';}</style>' . "\n";
    }

    // ── Account security (account lists) ──────────────────────────────────────

    /** A small coloured label: text, text colour, background, hover text. Colours keep 4.5:1 contrast. */
    private static function pill( string $text, string $fg, string $bg, string $title = '' ): string {
        return '<span' . ( $title !== '' ? ' title="' . esc_attr( $title ) . '"' : '' ) . ' style="display:inline-block;background:' . $bg . ';color:' . $fg
             . ';padding:2px 8px;border-radius:12px;font-size:.85em;white-space:nowrap;">' . esc_html( $text ) . '</span>';
    }

    /**
     * Password strength and two-factor status for one account, as [ 'pw' => html, 'tfa' => html ] for the ILL
     * Admin User Accounts and the system staff Manage Accounts lists. Only the stored rating is shown (the
     * password itself is never stored); "Not rated yet" until the person next signs in or changes it.
     */
    public static function account_security( WP_User $u, bool $may_see = true ): array {
        $grey = '<span style="color:#595959;">%s</span>';
        // Only for accounts the viewer may manage: a weak password or missing two-factor on an administrator is
        // exactly what an attacker would look for.
        if ( ! $may_see ) { return [ 'pw' => sprintf( $grey, '—' ), 'tfa' => sprintf( $grey, '—' ) ]; }
        $b    = class_exists( 'SEAL_Module_LastLogin' ) ? SEAL_Module_LastLogin::rating_badge( (int) $u->ID ) : null;
        $pw   = $b ? self::pill( $b[0], $b[1], $b[2], $b[3] )
                   : '<span style="color:#595959;" title="Rated the next time they sign in or change their password">Not rated yet</span>';
        if ( class_exists( 'SEAL_Setup_Reminders' ) && SEAL_Setup_Reminders::waiting( $u ) ) {   // welcome sent, password never set
            $exp = SEAL_Setup_Reminders::expires( $u );
            $pw  = $exp > time()
                ? self::pill( 'Not set up yet', '#7a4b00', '#fcf0d9', 'Set-password link expires ' . wp_date( 'Y-m-d H:i', $exp ) )
                : self::pill( 'Link expired', '#8a1f1f', '#fbeaea', 'They never set a password; send a password reset' );
        }

        if ( seal_config( 'module_2fa' ) !== '1' || ! class_exists( 'SEAL_Module_2FA' ) ) {
            $tfa = sprintf( $grey, 'Not in use' );
        } elseif ( SEAL_Module_2FA::is_enrolled( (int) $u->ID ) ) {
            $how = [];
            $n   = class_exists( 'SEAL_WebAuthn' ) ? count( SEAL_WebAuthn::keys( (int) $u->ID ) ) : 0;
            if ( $n ) { $how[] = $n === 1 ? '1 key' : $n . ' keys'; }
            if ( SEAL_Module_2FA::app_on( (int) $u->ID ) ) { $how[] = 'app'; }
            $tfa = self::pill( 'On', '#0a5c2e', '#d4edda', 'Set up: ' . implode( ', ', $how ) ) . ' <small style="color:#595959;">' . esc_html( implode( ', ', $how ) ) . '</small>';
        } elseif ( SEAL_Module_2FA::role_required( $u ) ) {
            $tfa = self::pill( 'Not set up', '#8a1f1f', '#fbeaea', 'Required for this role: they will be asked to set it up when they next sign in' );
        } else {
            $tfa = self::pill( 'Off', '#3c434a', '#e9ecef', 'Not set up (optional for this role)' );
        }
        return [ 'pw' => $pw, 'tfa' => $tfa ];
    }

    // ── Email helper ──────────────────────────────────────────────────────────

    /**
     * Send a transactional email using wp_mail().
     *
     * Uses seal_config() so From:, footer, and prefix all reflect the
     * current branding without any code changes on re-deployment.
     *
     * @param string $to         Recipient(s) — comma-separated for multiple.
     * @param string $subject    Email subject.
     * @param string $html_body  HTML body content.
     * @param bool   $alert      If true, CC the support_emails list as well.
     */
    public static function send_email( string $to, string $subject, string $html_body, bool $alert = false, bool $system_alert = false ): bool {
        // Strip newlines from header values to prevent email header injection
        $from_name  = str_replace( [ "\r", "\n" ], '', seal_config( 'email_from_name' ) );
        if ( $from_name === '' ) {
            $from_name = seal_config( 'system_name' );
        }
        $from_email = sanitize_email( seal_config( 'email_from' ) );
        $system     = seal_config( 'system_name' );

        // Prepend system name to subject if not already present
        if ( $system && strpos( $subject, $system ) === false ) {
            $subject = "[{$system}] {$subject}";
        }

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            "From: {$from_name} <{$from_email}>",
        ];

        // CC support addresses on alert emails
        if ( $alert ) {
            $support = seal_config( 'support_emails' );
            foreach ( array_filter( array_map( 'trim', explode( ',', $support ) ) ) as $cc ) {
                if ( is_email( $cc ) ) {
                    $headers[] = "Cc: {$cc}";
                }
            }
        }

        // Email test mode (ILL → Contacts & Email). Covers every email this plugin sends; WordPress's
        // own emails (password reset, new-user notices) are not affected, so people can still log in.
        $mode = self::email_mode();
        if ( $mode === 'off' && $system_alert ) {
            $mode = '';   // system alerts only ever go to the admin/support addresses, so they still go out
        }
        if ( $mode === 'off' ) {
            self::log_mail( $to, $subject, 'Not sent (email test mode: off)' );
            return true;
        }
        if ( $mode === 'redirect' ) {
            // One or more test addresses, comma-separated.
            $test_to = array_values( array_filter( array_map( static fn( $e ) => sanitize_email( trim( $e ) ), explode( ',', seal_config( 'email_test_to' ) ) ), 'is_email' ) );
            if ( ! $test_to ) {
                self::log_mail( $to, $subject, 'Not sent (email test mode: no valid test address set)' );
                return true;
            }
            $cc = [];
            $headers = array_values( array_filter( $headers, static function ( $h ) use ( &$cc ) {
                if ( stripos( $h, 'Cc:' ) === 0 || stripos( $h, 'Bcc:' ) === 0 ) { $cc[] = trim( substr( $h, strpos( $h, ':' ) + 1 ) ); return false; }
                return true;
            } ) );
            $banner = '<div style="border:2px solid #b32d2e;padding:8px 12px;margin:0 0 16px;color:#1d2327;">'
                    . '<strong>TEST MODE</strong> — this email would have gone to: ' . esc_html( $to )
                    . ( $cc ? ' (Cc: ' . esc_html( implode( ', ', $cc ) ) . ')' : '' ) . '</div>';
            self::log_mail( $to, $subject, 'Sent to test address ' . implode( ', ', $test_to ) );
            return wp_mail( $test_to, '[TEST] ' . $subject, self::email_layout( $banner . $html_body, $subject ), $headers );
        }

        return wp_mail( $to, $subject, self::email_layout( $html_body, $subject ), $headers );
    }

    // ── Email layout ──────────────────────────────────────────────────────────

    /**
     * Wrap every plugin email in one branded layout: a header bar in the brand colour with the logo (ILL →
     * Branding → Header Logo URL) or the system name, a white card with the message, and a footer with the
     * footer text and a link to the site. Table-based with inline styles, because that is what email
     * programs (Outlook included) render reliably. Callers pass only the message itself; any <html>/<body>
     * wrapper they still include is stripped.
     */
    public static function email_layout( string $body, string $subject = '' ): string {
        $body   = preg_replace( '#</?(html|body)[^>]*>#i', '', $body );
        $brand  = sanitize_hex_color( seal_config( 'brand_primary' ) ) ?: '#4a4180';
        $system = seal_config( 'system_name' );
        $org    = trim( (string) seal_config( 'org_full_name' ) ) ?: trim( (string) seal_config( 'org_name' ) );   // Org Full Name, else Org Short Name
        $logo   = esc_url( seal_config( 'header_logo_url' ) );
        $site   = home_url( '/' );
        $footer = seal_config( 'footer_text' );
        $head   = $logo !== ''
            ? '<img src="' . $logo . '" alt="' . esc_attr( $system ) . '" style="max-height:48px;max-width:240px;border:0;display:block;">'
            : '<span style="font-size:20px;font-weight:bold;color:#ffffff;">' . esc_html( $system ) . '</span>';
        $head_bg = $logo !== '' ? '#ffffff' : $brand;   // a logo usually sits on white; the name sits on the brand colour

        return '<!doctype html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . esc_html( $subject ) . '</title></head>'
             . '<body style="margin:0;padding:0;background:#f2f3f5;">'
             . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f2f3f5;"><tr><td align="center" style="padding:24px 12px;">'
             . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border:1px solid #dcdcde;border-radius:6px;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.5;color:#1d2327;">'
             . '<tr><td style="background:' . $head_bg . ';padding:16px 24px;border-bottom:4px solid ' . $brand . ';border-radius:6px 6px 0 0;">' . $head . '</td></tr>'
             . '<tr><td style="padding:24px;">' . $body . '</td></tr>'
             . '<tr><td style="padding:14px 24px;border-top:1px solid #dcdcde;font-size:12px;color:#50575e;">'
             . ( $footer !== '' ? esc_html( $footer ) . '<br>' : '' )
             . 'Sent by ' . esc_html( $system ) . ( $org !== '' ? ', a service hosted by ' . esc_html( $org ) : '' )
             . ' &middot; <a href="' . esc_url( $site ) . '" style="color:' . $brand . ';">' . esc_html( preg_replace( '#^https?://#', '', untrailingslashit( $site ) ) ) . '</a>'
             . '</td></tr></table></td></tr></table></body></html>';
    }

    /** A heading for the top of an email message. */
    public static function email_heading( string $text ): string {
        return '<h1 style="margin:0 0 12px;font-size:20px;line-height:1.3;color:#1d2327;">' . esc_html( $text ) . '</h1>';
    }

    /** Label / value rows (values are escaped unless the label ends with '*', which marks pre-built HTML). Empty values are skipped. */
    public static function email_details( array $rows ): string {
        $out = '';
        foreach ( $rows as $label => $value ) {
            $html = substr( $label, -1 ) === '*';
            $label = rtrim( $label, '*' );
            if ( trim( wp_strip_all_tags( (string) $value ) ) === '' ) { continue; }
            $out .= '<tr><th align="left" valign="top" style="padding:8px 12px 8px 0;border-bottom:1px solid #eeeeee;font-weight:bold;white-space:nowrap;width:1%;">' . esc_html( $label ) . '</th>'
                  . '<td valign="top" style="padding:8px 0;border-bottom:1px solid #eeeeee;">' . ( $html ? $value : nl2br( esc_html( (string) $value ) ) ) . '</td></tr>';
        }
        return $out !== '' ? '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 18px;border-top:1px solid #eeeeee;">' . $out . '</table>' : '';
    }

    /** A smaller heading inside a message (e.g. "Article details"). */
    public static function email_subheading( string $text ): string {
        return '<h2 style="margin:18px 0 8px;font-size:16px;line-height:1.3;color:#1d2327;">' . esc_html( $text ) . '</h2>';
    }

    /** A paragraph of already-built HTML (callers escape their own values). */
    public static function email_p( string $html ): string {
        return '<p style="margin:0 0 14px;">' . $html . '</p>';
    }

    /** Several buttons side by side: [ [ url, label, colour or '' for the brand colour ], ... ]. */
    public static function email_buttons( array $buttons ): string {
        $brand = sanitize_hex_color( seal_config( 'brand_primary' ) ) ?: '#4a4180';
        $out   = '';
        foreach ( $buttons as [ $url, $label, $bg ] ) {
            $out .= '<a href="' . esc_url( $url ) . '" style="display:inline-block;background:' . ( $bg ?: $brand ) . ';color:#ffffff;text-decoration:none;font-weight:bold;padding:10px 18px;border-radius:4px;margin:0 10px 8px 0;">' . esc_html( $label ) . '</a>';
        }
        return '<p style="margin:18px 0 6px;">' . $out . '</p>';
    }

    /** One or more addresses (comma or semicolon separated) as mailto links, one per line. */
    public static function email_mailto( string $emails ): string {
        $out = [];
        foreach ( array_filter( array_map( 'trim', preg_split( '/[,;]/', $emails ) ) ) as $e ) {
            if ( is_email( $e ) ) { $out[] = '<a href="mailto:' . esc_attr( $e ) . '">' . esc_html( $e ) . '</a>'; }
        }
        return implode( '<br>', $out );
    }

    /** YYYY-MM-DD (or anything strtotime reads) as "November 1, 2026"; unreadable values come back unchanged. */
    public static function email_date( string $date ): string {
        $date = trim( $date );
        $ts   = $date !== '' ? strtotime( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? $date . ' 12:00:00' : $date ) : false;
        return $ts ? wp_date( 'F j, Y', $ts ) : $date;
    }

    /** A button-style link. */
    public static function email_button( string $url, string $label ): string {
        $brand = sanitize_hex_color( seal_config( 'brand_primary' ) ) ?: '#4a4180';
        return '<p style="margin:18px 0 6px;"><a href="' . esc_url( $url ) . '" style="display:inline-block;background:' . $brand . ';color:#ffffff;text-decoration:none;font-weight:bold;padding:10px 18px;border-radius:4px;">' . esc_html( $label ) . '</a></p>';
    }

    /** '' (send normally), 'redirect' or 'off'. */
    public static function email_mode(): string {
        $m = seal_config( 'email_mode' );
        return in_array( $m, [ 'redirect', 'off' ], true ) ? $m : '';
    }

    /** Record an email that test mode held back or redirected (last 100, shown on ILL → Contacts & Email). */
    private static function log_mail( string $to, string $subject, string $result ): void {
        $log   = (array) get_option( 'seal_mail_log', [] );
        $log[] = [ 'time' => time(), 'to' => $to, 'subject' => $subject, 'result' => $result ];
        if ( count( $log ) > 100 ) { $log = array_slice( $log, -100 ); }
        update_option( 'seal_mail_log', $log, false );
    }

    /** The one-line warning shown on admin screens and the front-end bar while Email test mode is on. */
    private static function email_mode_text(): string {
        return self::email_mode() === 'off'
            ? 'Email test mode is ON: the ILL system is not sending any email.'
            : 'Email test mode is ON: all ILL system email goes to ' . seal_config( 'email_test_to' ) . ' instead of libraries and borrowers.';
    }

    /** wp-admin warning on every screen while test mode is on. */
    public static function email_mode_notice(): void {
        if ( self::email_mode() === '' || ! current_user_can( 'manage_options' ) ) { return; }
        echo '<div class="notice notice-warning"><p><strong>' . esc_html( self::email_mode_text() ) . '</strong> '
           . '<a href="' . esc_url( admin_url( 'admin.php?page=seal-ill-contacts' ) ) . '">Change it under ILL &rarr; Contacts &amp; Email</a> before going live.</p></div>';
    }

    /** Same warning as a bar at the top of the site, for administrators only. */
    public static function email_mode_bar(): void {
        if ( self::email_mode() === '' || ! current_user_can( 'manage_options' ) ) { return; }
        echo '<div role="status" style="background:#fcf0f1;border-bottom:2px solid #b32d2e;color:#1d2327;padding:8px 16px;font:600 14px/1.4 sans-serif;text-align:center;">'
           . esc_html( self::email_mode_text() ) . '</div>';
    }

    /**
     * Send a system alert email (DB failures, ILLiad errors, etc.).
     * Always goes to admin_email + support_emails.
     */
    public static function send_alert( string $subject, string $html_body ): bool {
        $to = seal_alert_emails();
        if ( ! $to ) { return false; }
        $html_body = '<p style="margin:0 0 6px;font-size:12px;font-weight:bold;letter-spacing:.05em;text-transform:uppercase;color:#b32d2e;">System alert</p>'
                   . self::email_heading( $subject )
                   . '<div style="border-left:4px solid #b32d2e;padding:2px 0 2px 14px;">' . $html_body . '</div>';
        return self::send_email( $to, $subject, $html_body, false, true );
    }

    // ── ILL number helpers ────────────────────────────────────────────────────

    /** Generate an ILL number from DB insert ID. Format: YYYY-{id} */
    public static function make_ill_number( int $insert_id ): string {
        return date( 'Y' ) . '-' . $insert_id;
    }

    // ── Pagination ────────────────────────────────────────────────────────────

    /**
     * Build a simple pagination HTML bar.
     *
     * @param int    $total      Total number of rows.
     * @param int    $per_page   Rows per page.
     * @param int    $current    Current page (1-based).
     * @param string $base_url   URL without page param.
     */
    public static function pagination( int $total, int $per_page, int $current, string $base_url ): string {
        if ( $per_page <= 0 || $total <= $per_page ) { return ''; }
        $pages = (int) ceil( $total / $per_page );
        $html  = '<nav class="seal-pagination" aria-label="' . esc_attr__( 'Results pages', 'seal-ill' ) . '"><ul>';
        for ( $p = 1; $p <= $pages; $p++ ) {
            $url  = esc_url( add_query_arg( 'pg', $p, $base_url ) );
            $aria = ( $p === $current ) ? ' aria-current="page"' : '';
            $cls  = ( $p === $current ) ? ' class="current"' : '';
            $html .= "<li{$cls}><a href=\"{$url}\"{$aria}>{$p}</a></li>";
        }
        return $html . '</ul></nav>';
    }

    // ── Delivery method label ─────────────────────────────────────────────────

    /**
     * The readable name of a ship-method code, from Org Rules → Couriers (unknown codes are shown as typed).
     */
    public static function shipmt_txt( string $method ): string {
        if ( $method === '' ) { return ''; }
        $map = SEAL_Org_Rules::couriers();
        return $map[ $method ] ?? ucfirst( $method );
    }

    // ── System name map ───────────────────────────────────────────────────────

    /**
     * The readable name of a library-system code, from Org Rules → Library Systems (case-insensitive; unknown
     * codes come back unchanged).
     */
    public static function system_name( string $code ): string {
        foreach ( SEAL_Org_Rules::systems() as $k => $label ) {
            if ( strcasecmp( (string) $k, $code ) === 0 ) { return $label; }
        }
        return $code;
    }

    /** Library-system code => name pairs for dropdowns (Org Rules → Library Systems). */
    public static function system_options(): array {
        return SEAL_Org_Rules::systems();
    }

    // ── Action buttons (for lender-side tables) ───────────────────────────────

    /**
     * A small POST form with one submit button for a lender-side action on request $ill, including the nonce.
     * $extras become hidden fields.
     */
    private static function _action_form( string $action, string $ill, array $extras, string $label ): string {
        $nonce = wp_nonce_field( 'seal_form', 'seal_nonce', true, false );
        $fields = "<input type='hidden' name='num' value='{$ill}'>";
        foreach ( $extras as $k => $v ) {
            $fields .= "<input type='hidden' name='" . esc_attr( $k ) . "' value='" . esc_attr( $v ) . "'>";
        }
        return "<form method='post' action='{$action}'>{$nonce}{$fields}<button type='submit'>" . esc_html( $label ) . "</button></form>";
    }

    // ── Date helpers ──────────────────────────────────────────────────────────

    /** $n / $d as a percentage with two decimals ('0.00%' when $d is 0). */
    public static function pct2( int $n, int $d ): string {
        return $d > 0 ? number_format( ( $n / $d ) * 100, 2 ) . '%' : '0.00%';
    }

    // ── CSV safety ─────────────────────────────────────────────────────────────

    /**
     * Neutralise CSV formula injection.
     *
     * A spreadsheet treats a cell beginning with =, +, -, @, or certain control
     * characters as a formula, which can execute when the file is opened. We
     * prefix any such value with a single quote so it is rendered as literal text.
     *
     * @param array<int,mixed> $row
     * @return array<int,string>
     */
    public static function csv_safe_row( array $row ): array {
        return array_map( static function ( $cell ): string {
            $cell = (string) $cell;
            if ( $cell !== '' && in_array( $cell[0], [ '=', '+', '-', '@', "\t", "\r" ], true ) ) {
                return "'" . $cell;
            }
            return $cell;
        }, $row );
    }
}
