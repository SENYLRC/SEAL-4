<?php
/**
 * SEAL_Module_TOS
 *
 * Adds a Terms of Service checkbox to the WP registration form.
 * The TOS page URL and the label text are configurable in the admin panel
 * under ILL System → User & Registration Settings.
 * Can be enabled/disabled from the admin without deactivating the plugin.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class SEAL_Module_TOS {

    /** Adds the Terms of Service checkbox to the registration form and checks it, when it is enabled. */
    public static function init(): void {
        // Only hook if TOS is enabled in settings
        if ( seal_config( 'tos_enabled' ) !== '1' ) { return; }

        add_action( 'register_form',     [ __CLASS__, 'render_checkbox' ] );
        add_filter( 'registration_errors', [ __CLASS__, 'validate'      ], 10, 3 );
        add_action( 'login_head',        [ __CLASS__, 'inline_css'      ] );
    }

    /**
     * The required "I agree to the … Terms of Service" checkbox with a link to the terms page (opens in a new
     * tab).
     */
    public static function render_checkbox(): void {
        $tos_url  = esc_url( seal_config( 'tos_page_url' ) ?: '/terms-of-service' );
        $sys      = seal_config( 'system_name' );
        $tos_label = str_ireplace(
            [ '{System Name}', '{system_name}', '{system}' ],
            $sys,
            seal_config( 'tos_label' ) ?: $sys . ' Terms of Service'
        );
        ?>
        <p style="margin:12px 0 18px;">
            <label for="tos_agree" style="display:inline;font-weight:normal;">
                <input type="checkbox" name="tos_agree" id="tos_agree" value="1"
                       required aria-required="true" aria-describedby="tos_desc">
                I agree to the
                <a href="<?php echo $tos_url; ?>" target="_blank" rel="noopener noreferrer">
                    <?php echo esc_html( $tos_label ); ?>
                    <span class="seal-sr-only">(opens in a new tab)</span>
                </a>
            </label>
            <span id="tos_desc" class="seal-sr-only">You must agree to continue.</span>
        </p>
        <?php
    }

    /** Rejects the registration unless the checkbox was ticked. */
    public static function validate( WP_Error $errors, string $login, string $email ): WP_Error {
        $tos_val = isset( $_POST['tos_agree'] ) ? sanitize_text_field( wp_unslash( $_POST['tos_agree'] ) ) : '';
        if ( $tos_val !== '1' ) {
            $errors->add( 'tos_error', '<strong>Error:</strong> You must agree to the Terms of Service.' );
        }
        return $errors;
    }

    /** Styles for the checkbox on the registration screen. */
    public static function inline_css(): void {
        echo '<style>p label{display:inline;}'
           . '.seal-sr-only{position:absolute!important;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0;}</style>';
    }
}
