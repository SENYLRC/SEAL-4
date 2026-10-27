<?php
/**
 * SEAL_Setup_Reminders — reminds people who were sent a "Set your password" email but haven't set one yet.
 *
 * Who: accounts whose welcome email went out (SEAL_Emails marks them with M_SENT) and whose set-password link is
 * still waiting (WordPress's user_activation_key, "time:hash"). Setting a password clears that key, so reminders stop
 * by themselves; pending / denied registrations are left alone.
 *
 * When: a daily job at the hour set under Users & Registration (default 09:00, site time zone). It sends the
 * "3 days left" reminder on the day that falls 72 hours before the link expires, and the "1 day left" reminder on
 * the day 24 hours before (or at the next run if one was missed while the link still works). Each is sent once.
 *
 * The link: the welcome email's link can't be recovered (WordPress stores only a hash), so each reminder carries a
 * fresh link — but with the **original** request time written back, so the deadline doesn't move and the reminders
 * can't keep extending it. The earlier link stops working (one key per account); the email says so.
 *
 * WordPress runs its scheduled jobs when someone visits the site. For reminders at 9:00 sharp on a quiet site,
 * call wp-cron from the server's crontab at that time (see the Users & Registration help text).
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class SEAL_Setup_Reminders {

    const HOOK   = 'seal_setup_reminders';
    const M_SENT = 'seal_welcome_sent';     // time the welcome email went out
    const M_DONE = 'seal_setup_reminded';   // "72" / "24" reminders already sent for the current link (by link time)

    public static function init(): void {
        add_action( self::HOOK, [ __CLASS__, 'run' ] );
        add_action( 'init', [ __CLASS__, 'schedule' ] );
        add_action( 'update_option_seal_setup_reminder_hour', static function () { wp_clear_scheduled_hook( self::HOOK ); self::schedule(); } );
        add_action( 'after_password_reset', static function ( $u ) { if ( $u instanceof WP_User ) { self::clear( (int) $u->ID ); } } );
        add_action( 'wp_login', static function ( $login, $u ) { if ( $u instanceof WP_User ) { self::clear( (int) $u->ID ); } }, 10, 2 );
    }

    public static function enabled(): bool { return seal_config( 'setup_reminders' ) !== '0'; }

    private static function hour(): int { return max( 0, min( 23, (int) seal_config( 'setup_reminder_hour' ) ) ); }

    /** Next run at HH:00 site time (today if still ahead, else tomorrow), daily after that. */
    public static function schedule(): void {
        if ( ! self::enabled() ) { wp_clear_scheduled_hook( self::HOOK ); return; }
        if ( wp_next_scheduled( self::HOOK ) ) { return; }
        $next = new DateTime( 'today ' . sprintf( '%02d:00', self::hour() ), wp_timezone() );
        if ( $next->getTimestamp() <= time() ) { $next->modify( '+1 day' ); }
        wp_schedule_event( $next->getTimestamp(), 'daily', self::HOOK );
    }

    /** Called when the welcome email is built: this account now has a set-up link to remind about. */
    public static function mark_sent( int $uid ): void {
        update_user_meta( $uid, self::M_SENT, time() );
        delete_user_meta( $uid, self::M_DONE );
    }

    /** Password set or signed in: nothing more to remind. */
    public static function clear( int $uid ): void {
        delete_user_meta( $uid, self::M_SENT );
        delete_user_meta( $uid, self::M_DONE );
    }

    /** The waiting link's request time, or 0 when there is none (password already set). */
    private static function link_time( WP_User $u ): int {
        global $wpdb;
        $key = (string) $wpdb->get_var( $wpdb->prepare( "SELECT user_activation_key FROM {$wpdb->users} WHERE ID = %d", $u->ID ) );
        if ( strpos( $key, ':' ) === false ) { return 0; }
        return (int) strtok( $key, ':' );
    }

    /** True when this account is still waiting to be set up (welcome sent, link waiting, not pending / denied). */
    public static function waiting( WP_User $u ): bool {
        if ( ! get_user_meta( $u->ID, self::M_SENT, true ) ) { return false; }
        if ( in_array( get_user_meta( $u->ID, 'seal_approval_status', true ), [ 'pending', 'denied' ], true ) ) { return false; }
        return self::link_time( $u ) > 0;
    }

    /** When the waiting link expires (Unix time), or 0. */
    public static function expires( WP_User $u ): int {
        $t = self::link_time( $u );
        return $t ? $t + (int) apply_filters( 'password_reset_expiration', DAY_IN_SECONDS ) : 0;
    }

    /**
     * Which reminder is due today for this account: '72', '24' or ''. A reminder is due from the calendar day that
     * falls 72 (24) hours before expiry, while the link still works, and only once per link.
     */
    public static function due( int $expires, int $now, array $done, int $issued = 0 ): string {
        if ( $expires <= $now ) { return ''; }
        if ( $issued && $now - $issued < 12 * HOUR_IN_SECONDS ) { return ''; }   // never right after the welcome email (short link lifetimes)
        $tz    = wp_timezone();
        $today = ( new DateTime( '@' . $now ) )->setTimezone( $tz )->format( 'Y-m-d' );
        $day   = static fn( int $hours ) => ( new DateTime( '@' . ( $expires - $hours * HOUR_IN_SECONDS ) ) )->setTimezone( $tz )->format( 'Y-m-d' );
        if ( $today >= $day( 24 ) && ! in_array( '24', $done, true ) ) { return '24'; }
        if ( $today >= $day( 72 ) && ! in_array( '72', $done, true ) && ! in_array( '24', $done, true ) && $expires - $now > DAY_IN_SECONDS ) { return '72'; }
        return '';
    }

    /** The daily job. @return int reminders sent */
    public static function run(): int {
        if ( ! self::enabled() ) { return 0; }
        $sent = 0;
        foreach ( get_users( [ 'meta_key' => self::M_SENT, 'number' => 500, 'fields' => 'all' ] ) as $u ) {
            $t = self::link_time( $u );
            if ( ! $t ) { self::clear( (int) $u->ID ); continue; }                       // password set: done
            if ( ! self::waiting( $u ) ) { continue; }
            $exp  = self::expires( $u );
            $raw  = (string) get_user_meta( $u->ID, self::M_DONE, true );                // "linktime|72,24"
            [ $for, $list ] = array_pad( explode( '|', $raw, 2 ), 2, '' );
            $done = (int) $for === $t ? array_filter( explode( ',', $list ) ) : [];      // a new link (Lost password?) starts over
            $which = self::due( $exp, time(), $done, $t );
            if ( $which === '' ) { continue; }
            if ( self::send( $u, $t, $exp, $which ) ) {
                $done[] = $which;
                update_user_meta( $u->ID, self::M_DONE, $t . '|' . implode( ',', array_unique( $done ) ) );
                $sent++;
            }
        }
        return $sent;
    }

    /** A fresh link that keeps the original request time, so the deadline doesn't move. '' on failure. */
    private static function link( WP_User $u, int $orig ): string {
        global $wpdb;
        $key = get_password_reset_key( $u );
        if ( is_wp_error( $key ) ) { return ''; }
        $stored = (string) $wpdb->get_var( $wpdb->prepare( "SELECT user_activation_key FROM {$wpdb->users} WHERE ID = %d", $u->ID ) );
        $pos    = strpos( $stored, ':' );
        if ( $pos === false ) { return ''; }
        $wpdb->update( $wpdb->users, [ 'user_activation_key' => $orig . substr( $stored, $pos ) ], [ 'ID' => $u->ID ] );
        clean_user_cache( $u->ID );
        return network_site_url( 'wp-login.php?action=rp&key=' . $key . '&login=' . rawurlencode( $u->user_login ), 'login' );
    }

    private static function send( WP_User $u, int $orig, int $exp, string $which ): bool {
        $link = self::link( $u, $orig );
        if ( $link === '' ) { return false; }
        $days = intdiv( max( 0, $exp - time() ), DAY_IN_SECONDS );   // actual time left, in case a run was missed
        $left = $days < 1 ? 'less than a day' : ( $days === 1 ? '1 day' : $days . ' days' );
        $vars = [ 'name' => trim( $u->first_name . ' ' . $u->last_name ), 'username' => $u->user_login, 'left' => $left,
                  'expires' => wp_date( 'l, F j \a\t g:i a', $exp ) ];
        $body = SEAL_Emails::heading( 'account_reminder', $vars )
              . SEAL_Emails::message( 'account_reminder', $vars )
              . SEAL_Functions::email_button( $link, 'Set your password' )
              . '<p style="margin:6px 0 18px;font-size:13px;color:#50575e;">This replaces the link in your earlier email. It works once, until '
              . esc_html( $vars['expires'] ) . '. After that, use <em>Lost your password?</em> on the <a href="' . esc_url( wp_login_url() ) . '">sign-in page</a>.</p>'
              . SEAL_Functions::email_details( [ 'Username' => $u->user_login ] )
              . SEAL_Emails::closing( 'account_reminder', $vars );
        // Account email: sent like the welcome email (not held by Email test mode), from the plugin's From address.
        $from_name  = str_replace( [ "\r", "\n" ], '', seal_config( 'email_from_name' ) ) ?: seal_config( 'system_name' );
        $from_email = sanitize_email( seal_config( 'email_from' ) );
        $subject    = SEAL_Emails::subject( 'account_reminder', $vars );
        $system     = seal_config( 'system_name' );
        if ( $system !== '' && strpos( $subject, $system ) === false ) { $subject = "[{$system}] {$subject}"; }
        $ok = wp_mail( $u->user_email, $subject, SEAL_Functions::email_layout( $body, $subject ),
                       array_filter( [ 'Content-Type: text/html; charset=UTF-8', $from_email !== '' ? "From: {$from_name} <{$from_email}>" : '' ] ) );
        if ( $ok && class_exists( 'SEAL_Audit' ) ) {
            SEAL_Audit::$actor = 'scheduled job';
            SEAL_Audit::event( 'users', (string) $u->user_login, 'Set-up reminder sent (' . $left . ' left)', [ 'uid' => (int) $u->ID ] );
        }
        return $ok;
    }
}
