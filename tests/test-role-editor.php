<?php
/**
 * Tests the Role Editor with WordPress stubs: an account can be given several roles from the profile checkboxes
 * (e.g. Administrator + ILL Administrator); WordPress's own single Role dropdown can't undo that on the same save;
 * an ILL Administrator can't tick roles only an Administrator may give, and roles they can't give are kept;
 * unticking everything changes nothing; bulk Add role / Remove role.
 *
 *   php tests/test-role-editor.php
 */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 403 ); exit; }
define( 'ABSPATH', __DIR__ . '/' );

class WP_Error { public array $errors = []; public function add( $c, $m ): void { $this->errors[ $c ] = $m; } }
class WP_User {
    public function __construct( public int $ID, public array $roles ) {}
    public function add_role( $r ): void { if ( ! in_array( $r, $this->roles, true ) ) { $this->roles[] = $r; } }
    public function remove_role( $r ): void { $this->roles = array_values( array_diff( $this->roles, [ $r ] ) ); }
    public function set_role( $r ): void { $this->roles = $r === '' ? [] : [ $r ]; }
}
class Roles { public function get_names(): array { return ALL; } }
const ALL = [ 'administrator' => 'Administrator', 'illadmin' => 'ILL Administrator', 'libsys' => 'Library System Staff', 'libstaff' => 'Library Staff' ];
$GLOBALS['users'] = []; $GLOBALS['me'] = 1; $GLOBALS['site_admin'] = true;
function add_action( ...$a ): void {} function add_filter( ...$a ): void {}
function get_userdata( $id ) { return $GLOBALS['users'][ $id ] ?? false; }
function get_current_user_id(): int { return $GLOBALS['me']; }
function current_user_can( $cap, $uid = 0 ): bool {   // like SEAL_Roles::guard_protected_users()
    if ( $GLOBALS['site_admin'] ) { return true; }
    return ! array_intersect( [ 'administrator', 'illadmin' ], $GLOBALS['users'][ $uid ]->roles ?? [] );
}
function get_editable_roles(): array {                 // like SEAL_Roles::guard_editable_roles()
    $r = array_map( fn( $n ) => [ 'name' => $n ], ALL );
    if ( ! $GLOBALS['site_admin'] ) { unset( $r['administrator'], $r['illadmin'] ); }
    return $r;
}
function wp_roles() { return new Roles(); }
function translate_user_role( $n ) { return $n; }
function wp_verify_nonce( $n, $a ): bool { return $n === 'ok'; }
function wp_nonce_field( ...$a ): void { echo '<input type="hidden" name="seal_role_nonce">'; }
function sanitize_text_field( $s ) { return trim( (string) $s ); }
function sanitize_key( $s ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $s ) ); }
function wp_unslash( $s ) { return $s; }
function esc_attr( $s ) { return htmlspecialchars( (string) $s ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s ); }
function checked( $b ): void { if ( $b ) { echo ' checked'; } }
function add_query_arg( $k, $v, $u ) { return "$u?$k=$v"; }

require dirname( __DIR__ ) . '/includes/modules/class-seal-module-role-editor.php';

$fail = 0;
function t( string $name, bool $ok, string $extra = '' ): void { global $fail; if ( ! $ok ) { $fail++; } echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( $ok ? '' : "  $extra" ) . "\n"; }
/** Saves the profile the way user-edit.php does: our hook first, then edit_user() applies $_POST['role'] if still set. */
function save( int $uid, array $ticked, string $core_dropdown ): WP_Error {
    $_POST = [ 'seal_role_nonce' => 'ok', 'seal_roles' => $ticked, 'role' => $core_dropdown ];
    SEAL_Module_RoleEditor::save_role( $uid );
    if ( isset( $_POST['role'] ) ) { $GLOBALS['users'][ $uid ]->set_role( $_POST['role'] ); }
    $e = new WP_Error(); SEAL_Module_RoleEditor::role_errors( $e ); return $e;
}
$roles = fn( $id ) => implode( ',', $GLOBALS['users'][ $id ]->roles );
$reset = function () { ( new ReflectionProperty( 'SEAL_Module_RoleEditor', 'no_role' ) )->setValue( null, false ); };

echo "=== Administrator editing another account ===\n";
$GLOBALS['users'] = [ 1 => new WP_User( 1, [ 'administrator', 'illadmin' ] ), 5 => new WP_User( 5, [ 'libstaff' ] ) ];
ob_start(); SEAL_Module_RoleEditor::role_field( $GLOBALS['users'][5] ); $html = ob_get_clean();
t( 'profile shows a checkbox per role, current one ticked', substr_count( $html, 'type="checkbox"' ) === 4 && preg_match( '/value="libstaff"\s*checked/', $html ) && ! preg_match( '/value="illadmin"\s*checked/', $html ) );
save( 5, [ 'administrator', 'illadmin' ], 'administrator' );
t( 'tick Administrator + ILL Administrator: both kept (WordPress\'s dropdown ignored)', $roles( 5 ) === 'administrator,illadmin', $roles( 5 ) );
save( 5, [ 'illadmin' ], 'illadmin' );
t( 'untick Administrator: only ILL Administrator left', $roles( 5 ) === 'illadmin', $roles( 5 ) );
$e = save( 5, [], '' );
t( 'untick everything: refused with a message, roles unchanged', $roles( 5 ) === 'illadmin' && isset( $e->errors['seal_no_role'] ), $roles( 5 ) );
$reset();
ob_start(); SEAL_Module_RoleEditor::role_field( $GLOBALS['users'][1] ); $own = ob_get_clean();
t( 'own profile: no role checkboxes', $own === '' );
$_POST = [ 'seal_role_nonce' => 'ok', 'seal_roles' => [ 'libstaff' ], 'role' => 'libstaff' ]; SEAL_Module_RoleEditor::save_role( 1 );
t( 'own roles can\'t be changed by a forged post', $roles( 1 ) === 'administrator,illadmin' );
$_POST = [ 'seal_role_nonce' => 'bad', 'seal_roles' => [ 'libsys' ] ]; SEAL_Module_RoleEditor::save_role( 5 );
t( 'bad nonce: nothing changes', $roles( 5 ) === 'illadmin' );

echo "=== ILL Administrator (not a full Administrator) ===\n";
$GLOBALS['site_admin'] = false; $GLOBALS['me'] = 2;
$GLOBALS['users'] += [ 2 => new WP_User( 2, [ 'illadmin' ] ), 6 => new WP_User( 6, [ 'libstaff' ] ) ];
ob_start(); SEAL_Module_RoleEditor::role_field( $GLOBALS['users'][6] ); $html = ob_get_clean();
t( 'only Library roles offered', substr_count( $html, 'type="checkbox"' ) === 2 && ! str_contains( $html, 'value="administrator"' ) );
save( 6, [ 'libstaff', 'libsys', 'administrator' ], 'libsys' );
t( 'Library Staff + Library System Staff given; Administrator ignored', $roles( 6 ) === 'libstaff,libsys', $roles( 6 ) );
ob_start(); SEAL_Module_RoleEditor::role_field( $GLOBALS['users'][5] ); $p = ob_get_clean();
t( 'can\'t touch an ILL Administrator\'s account', $p === '' && $roles( 5 ) === 'illadmin' );

echo "=== bulk actions ===\n";
$GLOBALS['site_admin'] = true; $GLOBALS['me'] = 1;
$GLOBALS['users'][7] = new WP_User( 7, [ 'libstaff' ] );
$acts = SEAL_Module_RoleEditor::add_bulk_actions( [] );
t( 'Change / Add / Remove for every role', isset( $acts['seal_role_illadmin'], $acts['seal_addrole_illadmin'], $acts['seal_rmrole_illadmin'] ) && count( $acts ) === 12 );
$r = SEAL_Module_RoleEditor::handle_bulk( 'users.php', 'seal_addrole_libsys', [ 6, 7, 1 ] );
t( 'Add role: added to others, not to yourself', $roles( 7 ) === 'libstaff,libsys' && $roles( 6 ) === 'libstaff,libsys' && $roles( 1 ) === 'administrator,illadmin' && str_ends_with( $r, '=1' ), $r );
SEAL_Module_RoleEditor::handle_bulk( 'users.php', 'seal_rmrole_libstaff', [ 7 ] );
t( 'Remove role: removed', $roles( 7 ) === 'libsys' );
SEAL_Module_RoleEditor::handle_bulk( 'users.php', 'seal_rmrole_libsys', [ 7 ] );
t( 'Remove role: never leaves an account with no role', $roles( 7 ) === 'libsys' );
SEAL_Module_RoleEditor::handle_bulk( 'users.php', 'seal_role_libstaff', [ 6 ] );
t( 'Change role still replaces', $roles( 6 ) === 'libstaff' );

echo $fail ? "\nFAILURES: $fail\n" : "\nAll passed.\n";
exit( $fail ? 1 : 0 );
