<?php
/**
 * SEAL_Module_InviteCode
 *
 * Requires a shared invite code on the WP registration form to keep the general
 * public from creating accounts. One code, reusable, rotated by an admin (e.g.
 * each January) under ILL System → User & Registration Settings.
 * A blank code means the check is not enforced.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class SEAL_Module_InviteCode {

    /** Adds the invite-code field to the registration form and checks it on submit. */
    public static function init(): void {
        add_action( 'register_form',       [ __CLASS__, 'render_field' ] );
        add_filter( 'registration_errors', [ __CLASS__, 'validate'     ], 10, 3 );
        add_action( 'login_head',          [ __CLASS__, 'inline_css'   ] );
    }

    /** Styles the field's help text on the login / registration screen. */
    public static function inline_css(): void {
        echo '<style>#registerform .seal-invite-help{display:block;margin:6px 0 18px;padding:10px 12px;'
           . 'background:#eef0f8;border-left:4px solid var(--seal-brand,#4a4180);color:#1d2327;font-size:14px;line-height:1.5;}</style>';
    }

    /** The current invite code ('' = none required). */
    private static function code(): string {
        return trim( seal_config( 'invite_code' ) );
    }

    /** The Invite Code field on the registration form (only when a code is set). */
    public static function render_field(): void {
        if ( self::code() === '' ) { return; }
        ?>
        <p><label for="seal_invite_code">Invite Code <span aria-hidden="true">*</span><br>
            <input type="text" name="seal_invite_code" id="seal_invite_code" class="input"
                   autocomplete="off" autocapitalize="off" spellcheck="false"
                   required aria-required="true" aria-describedby="seal_invite_code_desc">
        </label>
        <?php $org = esc_html( seal_config( 'org_name' ) ); ?>
        <span id="seal_invite_code_desc" class="seal-invite-help"><strong>Provided by your library system or <?php echo $org; ?>.<br>
            If you need a code, contact the staff at <?php echo $org; ?>.</strong></span></p>
        <?php
    }

    /**
     * Rejects the registration unless the typed code matches (case-insensitive, constant-time comparison).
     * After 10 wrong codes from one address, that address must wait 15 minutes.
     */
    public static function validate( WP_Error $errors, string $login, string $email ): WP_Error {
        $expected = self::code();
        if ( $expected === '' ) { return $errors; }

        // Slow down guessing: after 10 wrong codes from one address, wait 15 minutes.
        $ip   = class_exists( 'SEAL_Module_LoginProtection' ) ? SEAL_Module_LoginProtection::client_ip() : (string) ( $_SERVER['REMOTE_ADDR'] ?? '' );
        $tkey = 'seal_invite_fail_' . md5( $ip );
        $fails = (int) get_transient( $tkey );
        if ( $fails >= 10 ) {
            $errors->add( 'seal_invite_code_error', '<strong>Error:</strong> Too many incorrect invite codes. Please wait 15 minutes and try again.' );
            return $errors;
        }

        $given = isset( $_POST['seal_invite_code'] )
            ? trim( sanitize_text_field( wp_unslash( $_POST['seal_invite_code'] ) ) )
            : '';

        if ( $given === '' || ! hash_equals( strtolower( $expected ), strtolower( $given ) ) ) {
            $errors->add( 'seal_invite_code_error', '<strong>Error:</strong> The invite code is missing or incorrect.' );
            if ( $given !== '' ) { set_transient( $tkey, $fails + 1, 15 * MINUTE_IN_SECONDS ); }
        }
        return $errors;
    }
}
