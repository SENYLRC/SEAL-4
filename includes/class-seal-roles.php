<?php
/**
 * SEAL_Roles — creates and manages the three custom WP roles used by SEAL.
 *
 * Roles:
 *   libstaff  — Library Staff:  can submit and manage own ILL requests.
 *   libsys    — Library System: can view all requests for their home system.
 *   illadmin  — ILL Administrator: every ILL Admin page and user-account management, but none of
 *               WordPress's own admin powers (plugins, themes, settings) and not the ILL settings
 *               screens in wp-admin (database password, security). Guards in init() stop it editing,
 *               deleting or re-roling Administrators / other ILL Administrators, or granting those roles.
 *   (administrator inherits everything)
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class SEAL_Roles {

    const ROLES = [
        'libstaff' => [
            'display_name' => 'Library Staff',
            'capabilities' => [
                'read'              => true,
                'seal_request'      => true,  // submit ILL requests
                'seal_view_own'     => true,  // view own request history
                'seal_respond'      => true,  // respond as lender
                'seal_renew'        => true,  // request renewals
                'seal_status'       => true,  // mark received / returned
                'seal_bulkaction'   => true,  // bulk status actions
            ],
        ],
        'libsys' => [
            'display_name' => 'Library System Staff',
            'capabilities' => [
                'read'              => true,
                'seal_request'      => true,
                'seal_view_own'     => true,
                'seal_respond'      => true,
                'seal_renew'        => true,
                'seal_status'       => true,
                'seal_bulkaction'   => true,
                'seal_view_system'  => true,  // view all requests for home system
                'seal_adminlib_ls'  => true,  // edit libraries in own system
                'seal_stats_system' => true,  // system-level stats
            ],
        ],
    ];

    /** ILL-only capabilities that used to be administrator-only. */
    public const ADMIN_CAPS = [
        'seal_admin'         => true,  // admin-only pages
        'seal_export'        => true,
        'seal_allrequests'   => true,
        'seal_email_harvest' => true,
        'seal_sealstats'     => true,
        'seal_systemstats'   => true,
    ];

    /** WordPress capabilities the ILL Administrator needs for user accounts (approvals, User Accounts, Users screen). */
    private const ILLADMIN_WP_CAPS = [
        'list_users' => true, 'create_users' => true, 'edit_users' => true, 'delete_users' => true, 'promote_users' => true,
    ];

    /** Bump when ROLES / ADMIN_CAPS change, so existing sites get the new roles without re-activating the plugin. */
    private const ROLES_VERSION = 2;

    /** Roles only a full Administrator may give out or change. */
    public const PROTECTED_ROLES = [ 'administrator', 'illadmin' ];

    /**
     * Runs on every load: (re)creates the ILL roles when ROLES_VERSION changed, and installs the guards that
     * stop ILL Administrators editing or creating Administrators.
     */
    public static function init(): void {
        if ( (int) get_option( 'seal_roles_version' ) !== self::ROLES_VERSION ) {
            self::create_roles();
            update_option( 'seal_roles_version', self::ROLES_VERSION, true );
        }
        add_filter( 'map_meta_cap',   [ __CLASS__, 'guard_protected_users' ], 10, 4 );
        add_filter( 'editable_roles', [ __CLASS__, 'guard_editable_roles' ] );
    }

    /** A full Administrator (WordPress's manage_options), as opposed to an ILL Administrator. */
    public static function is_site_admin(): bool {
        return current_user_can( 'manage_options' );
    }

    /**
     * Someone who isn't a full Administrator (e.g. an ILL Administrator) can't edit, delete, re-role or remove an
     * Administrator or ILL Administrator account, and can't re-role themselves. Their own profile stays editable.
     */
    public static function guard_protected_users( array $caps, string $cap, int $user_id, array $args ): array {
        if ( ! in_array( $cap, [ 'edit_user', 'delete_user', 'promote_user', 'remove_user' ], true ) || empty( $args[0] ) ) { return $caps; }
        $target = (int) $args[0];
        if ( user_can( $user_id, 'manage_options' ) ) { return $caps; }            // full Administrators: unchanged
        if ( $target === $user_id ) {
            return $cap === 'edit_user' ? $caps : [ 'do_not_allow' ];            // own profile yes; own role / deletion no
        }
        $t = get_userdata( $target );
        if ( $t && array_intersect( self::PROTECTED_ROLES, (array) $t->roles ) ) { return [ 'do_not_allow' ]; }
        return $caps;
    }

    /** Only a full Administrator can hand out the Administrator or ILL Administrator roles. */
    public static function guard_editable_roles( array $roles ): array {
        if ( ! self::is_site_admin() ) {
            foreach ( self::PROTECTED_ROLES as $r ) { unset( $roles[ $r ] ); }
        }
        return $roles;
    }

    /**
     * Adds the Library Staff, Library System Staff and ILL Administrator roles, refreshes their capabilities if
     * they already exist, and gives Administrators every ILL capability. Safe to run repeatedly (called on
     * activation and by init()).
     */
    public static function create_roles(): void {
        foreach ( self::ROLES as $slug => $data ) {
            if ( ! get_role( $slug ) ) {
                add_role( $slug, $data['display_name'], $data['capabilities'] );
            } else {
                // Update capabilities on an existing role
                $role = get_role( $slug );
                foreach ( $data['capabilities'] as $cap => $grant ) {
                    $role->add_cap( $cap, $grant );
                }
            }
        }

        $ill_caps = array_merge( self::ROLES['libstaff']['capabilities'], self::ROLES['libsys']['capabilities'], self::ADMIN_CAPS );

        // ILL Administrator: every ILL capability + user management, no WordPress site administration
        $illadmin_caps = $ill_caps + self::ILLADMIN_WP_CAPS;
        $ia = get_role( 'illadmin' );
        if ( ! $ia ) {
            add_role( 'illadmin', 'ILL Administrator', $illadmin_caps );
        } else {
            foreach ( $illadmin_caps as $cap => $grant ) { $ia->add_cap( $cap, $grant ); }
        }

        // Also give administrator all ILL caps
        $admin = get_role( 'administrator' );
        if ( $admin ) {
            foreach ( $ill_caps as $cap => $grant ) {
                $admin->add_cap( $cap, $grant );
            }
        }
    }

    // ── Convenience checkers ──────────────────────────────────────────────────

    /** May the current user act on every library's requests (Administrator or ILL Administrator)? */
    public static function is_admin(): bool {
        return current_user_can( 'seal_admin' );
    }

    /**
     * Abort with a styled error if the current user lacks a capability.
     *
     * @param string $capability WP capability to check.
     * @param bool   $die        If true, wp_die(); otherwise return false.
     */
    public static function require_cap( string $capability, bool $die = true ): bool {
        if ( ! is_user_logged_in() ) {
            if ( $die ) {
                wp_die(
                    '<p>' . esc_html__( 'Access Denied — you must be logged in.', 'seal-ill' ) . '</p>',
                    esc_html__( 'Access Denied', 'seal-ill' ),
                    [ 'response' => 403 ]
                );
            }
            return false;
        }

        if ( ! current_user_can( $capability ) ) {
            if ( $die ) {
                wp_die(
                    '<p>' . esc_html__( 'Access Denied — you do not have permission to view this page.', 'seal-ill' ) . '</p>',
                    esc_html__( 'Access Denied', 'seal-ill' ),
                    [ 'response' => 403 ]
                );
            }
            return false;
        }

        return true;
    }
}
