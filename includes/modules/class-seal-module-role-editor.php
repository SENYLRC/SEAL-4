<?php
/**
 * SEAL_Module_RoleEditor
 *
 * English-only, essential features: assign/change a user's role from their
 * profile page without leaving the site, and bulk-assign roles from the
 * Users list. ILL-specific roles (libstaff, libsys) are first-class citizens.
 *
 * What this adds:
 *  - "Change Role" panel on user Edit Profile page (admin only)
 *  - Bulk role assignment on Users → Bulk Actions
 *  - A "Roles" admin page under the ILL menu showing all roles + capabilities
 *  - [seal_roles] shortcode (admin-only) for role reference
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class SEAL_Module_RoleEditor {

    /**
     * Hooks the role changer on user profiles, the bulk role change on the Users list, and the Roles &
     * Capabilities screen.
     */
    public static function init(): void {
        // Profile: role changer (admin only)
        add_action( 'edit_user_profile',        [ __CLASS__, 'role_field'        ] );
        add_action( 'edit_user_profile_update', [ __CLASS__, 'save_role'         ] );

        // Users list: bulk action
        add_filter( 'bulk_actions-users',       [ __CLASS__, 'add_bulk_actions'  ] );
        add_filter( 'handle_bulk_actions-users',[ __CLASS__, 'handle_bulk'       ], 10, 3 );
        add_action( 'admin_notices',            [ __CLASS__, 'bulk_notice'       ] );

        // Admin page under the ILL admin menu
        add_action( 'admin_menu', [ __CLASS__, 'admin_menu' ] );
    }

    // ── Which roles the current user may give ─────────────────────────────────

    /**
     * Role slug => name for the roles the current user may hand out: WordPress's editable roles, which
     * SEAL_Roles::guard_editable_roles() trims so only a full Administrator can give the Administrator or
     * ILL Administrator role. Every dropdown and every save in this module goes through this list.
     */
    private static function allowed_roles(): array {
        if ( ! function_exists( 'get_editable_roles' ) ) { require_once ABSPATH . 'wp-admin/includes/user.php'; }
        return array_map( static fn( $r ) => (string) ( $r['name'] ?? '' ), get_editable_roles() );
    }

    /** True when the current user may change \$uid's role (never their own; see SEAL_Roles::guard_protected_users()). */
    private static function can_change( int $uid ): bool {
        return $uid !== get_current_user_id() && current_user_can( 'promote_user', $uid );
    }

    // ── Profile role changer (admin only) ─────────────────────────────────────

    /**
     * A role dropdown on another user's profile, for users who may promote that user. Nobody can change their
     * own role here, and the list only holds roles the current user may give (allowed_roles()).
     */
    public static function role_field( WP_User $user ): void {
        if ( ! self::can_change( $user->ID ) ) { return; }   // includes: not your own role

        $current_roles = $user->roles;
        $all_roles     = self::allowed_roles();
        ?>
        <h3>Role Management</h3>
        <table class="form-table">
            <tr>
                <th scope="row"><label for="seal_new_role">Change Role</label></th>
                <td>
                    <?php wp_nonce_field( 'seal_change_role_' . $user->ID, 'seal_role_nonce' ); ?>
                    <select name="seal_new_role" id="seal_new_role">
                        <option value="">— No change —</option>
                        <?php foreach ( $all_roles as $slug => $name ): ?>
                        <option value="<?php echo esc_attr( $slug ); ?>"
                            <?php selected( in_array( $slug, $current_roles, true ) ); ?>>
                            <?php echo esc_html( translate_user_role( $name ) ); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description">
                        Current role(s): <strong><?php echo esc_html( implode( ', ', $current_roles ) ); ?></strong><br>
                        Changing role <em>replaces</em> all existing roles with the selected one.
                    </p>
                </td>
            </tr>
        </table>
        <?php
    }

    /** Saves the role chosen on the profile after the nonce and permission checks (never your own role). */
    public static function save_role( int $user_id ): void {
        if ( ! self::can_change( $user_id ) ) { return; }
        if ( ! isset( $_POST['seal_role_nonce'] ) ||
             ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['seal_role_nonce'] ) ), 'seal_change_role_' . $user_id )
        ) { return; }

        $new_role = sanitize_key( wp_unslash( $_POST['seal_new_role'] ?? '' ) );
        if ( $new_role === '' ) { return; }

        if ( ! array_key_exists( $new_role, self::allowed_roles() ) ) { return; }   // e.g. an ILL Administrator can't grant Administrator

        $user = get_userdata( $user_id );
        if ( ! $user ) { return; }

        // Replace all roles with the new one
        $user->set_role( $new_role );
    }

    // ── Bulk role assignment ───────────────────────────────────────────────────

    /** Adds a "Change role → …" bulk action for each role to the Users list. */
    public static function add_bulk_actions( array $actions ): array {
        foreach ( self::allowed_roles() as $slug => $name ) {
            $actions[ 'seal_role_' . $slug ] = 'Change role → ' . translate_user_role( $name );
        }
        return $actions;
    }

    /**
     * Applies a bulk role change to the ticked users, skipping yourself and anyone you may not edit, and
     * reports how many changed.
     */
    public static function handle_bulk( string $redirect, string $action, array $user_ids ): string {
        if ( ! str_starts_with( $action, 'seal_role_' ) ) { return $redirect; }
        if ( ! current_user_can( 'promote_users' ) ) { return $redirect; }

        $new_role  = sanitize_key( substr( $action, 10 ) ); // strip 'seal_role_'
        if ( ! array_key_exists( $new_role, self::allowed_roles() ) ) { return $redirect; }

        $changed = 0;
        foreach ( $user_ids as $uid ) {
            $uid = (int) $uid;
            if ( ! self::can_change( $uid ) ) { continue; }   // yourself, or an account you may not promote (e.g. an Administrator)
            $u = get_userdata( $uid );
            if ( $u ) { $u->set_role( $new_role ); $changed++; }
        }

        return add_query_arg( 'seal_role_changed', $changed, $redirect );
    }

    /** Shows how many users had their role changed. */
    public static function bulk_notice(): void {
        if ( ! isset( $_GET['seal_role_changed'] ) ) { return; }
        $n = (int) $_GET['seal_role_changed'];
        echo '<div class="notice notice-success is-dismissible"><p>'
             . esc_html( sprintf( 'Role updated for %d user(s).', $n ) )
             . '</p></div>';
    }

    // ── Admin page: Roles reference ───────────────────────────────────────────

    /** Adds the Roles & Capabilities screen under the ILL System menu. */
    public static function admin_menu(): void {
        add_submenu_page(
            'seal-ill-dashboard',
            'Roles & Capabilities',
            '🔑 Roles',
            'manage_options',
            'seal-ill-roles-ref',
            [ __CLASS__, 'render_roles_page' ]
        );
    }

    /** A read-only table of every role and the ILL capabilities it has. */
    public static function render_roles_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        $sys = esc_html( seal_config( 'system_name' ) );
        $all_roles = wp_roles()->roles;
        ?>
        <div class="wrap">
        <h1><?php echo $sys; ?> ILL — Roles &amp; Capabilities</h1>
        <p>All WordPress roles currently active on this site. <?php echo $sys; ?>-specific capabilities are highlighted.</p>

        <?php foreach ( $all_roles as $slug => $role ):
            $caps = array_filter( $role['capabilities'] ?? [] );
            $seal_caps = array_filter( array_keys( $caps ), fn($c) => str_starts_with( $c, 'seal_' ) );
            $wp_caps   = array_filter( array_keys( $caps ), fn($c) => ! str_starts_with( $c, 'seal_' ) );
        ?>
        <div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:16px;margin-bottom:20px;max-width:900px;">
            <h3 style="margin:0 0 8px;">
                <?php echo esc_html( translate_user_role( $role['name'] ) ); ?>
                <code style="font-size:.8em;color:#666;margin-left:8px;"><?php echo esc_html( $slug ); ?></code>
            </h3>

            <?php if ( ! empty( $seal_caps ) ): ?>
            <p style="margin:0 0 6px;"><strong><?php echo $sys; ?> capabilities:</strong></p>
            <p style="margin:0 0 12px;">
                <?php foreach ( $seal_caps as $c ): ?>
                <code style="background:#e8f5e9;padding:2px 6px;border-radius:4px;margin:2px;display:inline-block;"><?php echo esc_html( $c ); ?></code>
                <?php endforeach; ?>
            </p>
            <?php endif; ?>

            <details style="margin-top:6px;">
                <summary style="cursor:pointer;color:#555;font-size:.9em;">WordPress capabilities (<?php echo count($wp_caps); ?>)</summary>
                <p style="margin:8px 0 0;">
                    <?php foreach ( $wp_caps as $c ): ?>
                    <code style="background:#f4f4f4;padding:2px 5px;border-radius:3px;margin:2px;display:inline-block;font-size:.85em;"><?php echo esc_html( $c ); ?></code>
                    <?php endforeach; ?>
                </p>
            </details>
        </div>
        <?php endforeach; ?>
        </div>
        <?php
    }
}
