<?php
/**
 * SEAL_Page_illuser — /illuser, "My Profile" (capability seal_view_own).
 *
 * Lets the signed-in user change their name, email, work phone, additional email, password (current password
 * required) and the "hide libraries in my own system" preference on the request page. When the Two-Factor
 * module is on it also shows the authenticator set-up panel and backup codes; users whose role requires two-
 * factor are sent here until it is set up. Library (LOC) codes are not editable here — only administrators
 * change them.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class SEAL_Page_illuser extends SEAL_Page_Base {
    /** Entry point: SEAL_Router calls this to print the page into the theme's content area. */
    public function render(): void {
        $uid  = $this->user->ID;
        $sys  = seal_config('system_name');

        $notice = '';
        $notice_type = 'success';

        // Two-factor panel form (separate from the profile form)
        $tfa_codes = [];
        $tfa_on    = class_exists( 'SEAL_Module_2FA' ) && SEAL_Module_2FA::active();
        if ( $tfa_on && $this->is_post() && isset( $_POST['seal_2fa_action'] ) ) {
            [ $notice, $notice_type, $tfa_codes ] = SEAL_Module_2FA::handle_post( $this->user );
        }

        // Handle profile update
        if ( $this->is_post() && ! isset( $_POST['seal_2fa_action'] ) ) {
            if ( ! isset($_POST['wp_profile_nonce']) || ! wp_verify_nonce( sanitize_text_field(wp_unslash($_POST['wp_profile_nonce'])), 'update_wp_profile' ) ) {
                wp_die('Security check failed.', 'Invalid Request', ['response'=>403]);
            }

            $new_first = sanitize_text_field( wp_unslash($_POST['first_name']  ?? '') );
            $new_last  = sanitize_text_field( wp_unslash($_POST['last_name']   ?? '') );
            $new_email = sanitize_email(      wp_unslash($_POST['email']       ?? $this->user->user_email) );
            $new_nick  = sanitize_text_field( wp_unslash($_POST['nickname']    ?? '') ) ?: ( $this->user->nickname ?: $new_email );
            $new_disp  = sanitize_text_field( wp_unslash($_POST['display_name']?? '') ) ?: $this->user->display_name;
            $new_phone = sanitize_text_field( wp_unslash($_POST['work_phone']      ?? '') );
            $new_alt   = sanitize_email(      wp_unslash($_POST['additional_email']?? '') );
            $new_pass  = (string)( $_POST['new_password']         ?? '' );
            $new_pass2 = (string)( $_POST['new_password_confirm'] ?? '' );

            $errors = new WP_Error();
            if ( ! $new_email || ! is_email($new_email) ) { $errors->add('email_invalid','Please enter a valid email.'); }
            if ( $new_alt !== '' && ! is_email($new_alt) ) { $errors->add('alt_email_invalid','Please enter a valid additional email (or leave it blank).'); }
            // Changing the email address or password needs the current password, so a borrowed or hijacked
            // session cannot be turned into a permanent takeover (a new email also receives password resets).
            $cur_pass       = (string)( $_POST['current_password'] ?? '' );
            $changing_email = strtolower( (string) $new_email ) !== strtolower( (string) $this->user->user_email );
            if ( ( $changing_email || $new_pass !== '' || $new_pass2 !== '' ) && ( $cur_pass === '' || ! wp_check_password( $cur_pass, $this->user->user_pass, $uid ) ) ) {
                $errors->add( 'current_pass', 'To change your email address or password, enter your current password.' );
            }
            if ( $new_pass || $new_pass2 ) {
                if ( $new_pass !== $new_pass2 ) { $errors->add('pass_mismatch','Passwords do not match.'); }
                elseif ( strlen($new_pass) < 8 )  { $errors->add('pass_short','Password must be at least 8 characters.'); }
                elseif ( class_exists( 'SEAL_Module_PasswordRules' ) && SEAL_Module_PasswordRules::active() ) {
                    foreach ( SEAL_Module_PasswordRules::validate( $new_pass, $this->user->user_login, $new_email ) as $rule_msg ) {
                        $errors->add( 'pass_rule', $rule_msg );
                    }
                }
            }

            if ( empty($errors->errors) ) {
                $userdata = ['ID'=>$uid,'user_email'=>$new_email,'first_name'=>$new_first,'last_name'=>$new_last,'nickname'=>$new_nick,'display_name'=>$new_disp];
                if ( $new_pass ) { $userdata['user_pass'] = $new_pass; }
                $updated = wp_update_user( $userdata );
                if ( is_wp_error($updated) ) {
                    $notice = $updated->get_error_message(); $notice_type = 'error';
                } else {
                    // Personal contact fields live on the user account, not the
                    // shared library record.
                    // Canonical keys match registration, the request page and the export
                    // ('phone', 'alt_email'). The legacy keys are kept in step for old data.
                    update_user_meta( $uid, 'filter_own_system', isset( $_POST['filter_own_system'] ) ? '1' : '' );
                    update_user_meta( $uid, 'phone',            $new_phone );
                    update_user_meta( $uid, 'alt_email',        $new_alt );
                    update_user_meta( $uid, 'work_phone',       $new_phone );
                    update_user_meta( $uid, 'additional_email', $new_alt );
                    $notice = 'Profile updated successfully.';
                    // Refresh user object
                    $this->user = get_userdata($uid);
                }
            } else {
                $notice = implode('<br>', $errors->get_error_messages()); $notice_type = 'error';
            }
        }

        // Load current values
        $email       = esc_attr( $this->user->user_email );
        $first_name  = esc_attr( (string)get_user_meta($uid,'first_name',true) );
        $last_name   = esc_attr( (string)get_user_meta($uid,'last_name', true) );
        $nickname    = esc_attr( $this->user->nickname );
        $display_nm  = esc_attr( $this->user->display_name );
        $institution = esc_attr( (string)get_user_meta($uid,'institution',      true) );
        $home_system = esc_attr( (string)get_user_meta($uid,'home_system',      true) );
        $work_phone  = esc_attr( (string)( get_user_meta($uid,'phone',     true) ?: get_user_meta($uid,'work_phone',       true) ) );
        $alt_email   = esc_attr( (string)( get_user_meta($uid,'alt_email', true) ?: get_user_meta($uid,'additional_email', true) ) );
        $loc_code    = esc_attr( (string)get_user_meta($uid,'address_loc_code', true) );
        $oclc_symbol = esc_attr( (string)get_user_meta($uid,'oclc_symbol',      true) );
        $systems     = SEAL_Functions::system_options();

        if ( $notice ) { $this->alert($notice, $notice_type); }
        ?>
        <div class="LibProfile_Form seal-profile">
        <h2 id="profile-form-title" class="seal-sr-only">Edit User Profile</h2>

        <form method="post" action="<?php echo esc_url(home_url('/illuser')); ?>" aria-labelledby="profile-form-title">
            <?php wp_nonce_field('update_wp_profile','wp_profile_nonce'); ?>

            <div class="section-card">
                <h4 id="account-heading">Account</h4>
                <div class="form-section" role="group" aria-labelledby="account-heading">
                    <div class="form-group">
                        <label id="username-label">Username</label>
                        <div class="pill" role="textbox" aria-labelledby="username-label" aria-readonly="true"><?php echo esc_html($this->user->user_login); ?></div>
                        <div class="helper">Usernames cannot be changed.</div>
                    </div>
                    <div class="form-group">
                        <label for="iu_email">Email <span aria-hidden="true">(required)</span></label>
                        <input type="email" id="iu_email" name="email" value="<?php echo $email; ?>" required aria-required="true" autocomplete="email">
                    </div>
                    <div class="form-group">
                        <label for="iu_fname">First Name</label>
                        <input type="text" id="iu_fname" name="first_name" value="<?php echo $first_name; ?>" autocomplete="given-name">
                    </div>
                    <div class="form-group">
                        <label for="iu_lname">Last Name</label>
                        <input type="text" id="iu_lname" name="last_name" value="<?php echo $last_name; ?>" autocomplete="family-name">
                    </div>
                    <div class="form-group">
                        <label for="iu_curpass">Current Password</label>
                        <input type="password" id="iu_curpass" name="current_password" autocomplete="current-password" aria-describedby="iu_curpass_help">
                        <div class="helper" id="iu_curpass_help">Only needed to change your email address or password.</div>
                    </div>
                    <div class="form-group">
                        <fieldset class="seal-plain-fieldset">
                            <legend class="seal-sr-only">Change Password</legend>
                            <label for="iu_pass">New Password</label>
                            <input type="password" id="iu_pass" name="new_password" autocomplete="new-password" placeholder="Set New Password" aria-describedby="iu_pass_help">
                            <label for="iu_pass2" class="seal-sr-only">Confirm New Password</label>
                            <input type="password" id="iu_pass2" name="new_password_confirm" autocomplete="new-password" placeholder="Confirm New Password" aria-describedby="iu_pass_help">
                            <div class="helper" id="iu_pass_help">Leave blank to keep your current password.</div>
                        </fieldset>
                    </div>
                </div>
            </div>

            <div class="section-card">
                <h4 id="contact-heading">Contact Info</h4>
                <div class="form-section" role="group" aria-labelledby="contact-heading">
                    <div class="form-group">
                        <label for="iu_phone">Work Phone</label>
                        <input type="tel" id="iu_phone" name="work_phone" value="<?php echo $work_phone; ?>" autocomplete="tel">
                    </div>
                    <div class="form-group">
                        <label for="iu_alt">Additional Email</label>
                        <input type="email" id="iu_alt" name="additional_email" value="<?php echo $alt_email; ?>" autocomplete="email">
                    </div>
                </div>
            </div>

            <div class="section-card">
                <h4 id="org-heading">Organization</h4>
                <div class="form-section" role="group" aria-labelledby="org-heading">
                    <div class="form-group">
                        <label id="institution-label">Institution</label>
                        <div class="form-control-static" role="textbox" aria-labelledby="institution-label" aria-readonly="true"><?php echo esc_html($institution); ?></div>
                    </div>
                    <div class="form-group">
                        <label id="home-system-label">Home Library System</label>
                        <div class="form-control-static" role="textbox" aria-labelledby="home-system-label" aria-readonly="true"><?php echo esc_html( $systems[$home_system] ?? $home_system ); ?></div>
                    </div>
                    <div class="form-group">
                        <label id="loc-code-label">LOC Location Code</label>
                        <div class="form-control-static" role="textbox" aria-labelledby="loc-code-label" aria-readonly="true"><?php echo esc_html($loc_code); ?></div>
                    </div>
                    <div class="form-group">
                        <label id="oclc-label">OCLC Symbol</label>
                        <div class="form-control-static" role="textbox" aria-labelledby="oclc-label" aria-readonly="true"><?php echo esc_html($oclc_symbol); ?></div>
                    </div>
                </div>
                <p class="helper">These come from your library record. To change them, visit the <a href="<?php echo esc_url(home_url('/libprofile')); ?>">Library Profile</a> page.</p>
            </div>

            <div class="section-card">
                <h4 id="prefs-heading">Request Preferences</h4>
                <div role="group" aria-labelledby="prefs-heading">
                    <label class="choice" for="iu_filter_own">
                        <input type="checkbox" id="iu_filter_own" name="filter_own_system" value="1" <?php checked( get_user_meta( $uid, 'filter_own_system', true ), '1' ); ?>>
                        Hide libraries in my own library system when I place a request
                    </label>
                    <p class="helper">Libraries in your own system can usually be requested through your local ILS/LMS.</p>
                </div>
            </div>

            <div class="actions">
                <input class="btn-primary" type="submit" value="Save Profile">
            </div>
        </form>

        <?php if ( $tfa_on ) { SEAL_Module_2FA::render_panel( $this->user, home_url( '/illuser' ), $tfa_codes ); } ?>

        <?php if ( class_exists('SEAL_Module_LastLogin') ):
            $last_login_ts  = SEAL_Module_LastLogin::get_last_login_ts($uid);
            $last_login_fmt = SEAL_Module_LastLogin::get_last_login($uid);
            if ( $last_login_fmt && $last_login_fmt !== 'Never' ): ?>
        <p class="description" style="margin-bottom:16px;">
            <strong>Last login:</strong> <?php echo esc_html($last_login_fmt); ?>
        </p>
        <?php endif; endif; ?>
        </div>
        <?php
    }
}
