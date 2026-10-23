<?php
/**
 * Plugin Name:  Super-User Role
 * Description:  Maintains a per-site Super-User role that mirrors the site's Administrator capabilities minus the Blocklist. Drop this file into wp-content/mu-plugins/ to install.
 * Version:      1.0.2
 * Requires at least: 4.9
 * Requires PHP: 7.0
 * License:      GPL-2.0-or-later
 *
 * A per-site role for trusted powerusers. On every full WordPress boot (normal
 * requests, AJAX, REST, WP-CLI), the Capability sync re-derives the expected set
 * — the current site's Administrator capabilities minus the Blocklist — and
 * rewrites the super_user role only when it has drifted. On multisite the sync
 * is lazy and per-site: each site is synced by the requests that load it, in
 * that site's context. There is no network-admin UI.
 *
 * The role is a delegation of trust, not a security boundary, and mu-plugins
 * have no deactivate switch. Break-glass: always keep at least one real
 * Administrator; remove this file to stop syncing (revokes nothing — run the
 * Abolish procedure in README.md for that).
 */

defined( 'ABSPATH' ) || exit;

/**
 * The Blocklist: capabilities never granted to a Super-User, no matter what the
 * site's Administrator holds. Covers user management, plugin and theme
 * management, code editing, core updates, and credentials.
 *
 * `manage_secrets` (WordPress 7.2) and `manage_network_secrets` keep the site's
 * credential store — API keys, payment, SMTP, AI secrets — out of Super-User
 * reach even when WordPress or a plugin grants the capability to the site's
 * Administrator role.
 *
 * `edit_theme_options` is deliberately not blocklisted: menus, widgets, and the
 * Customizer stay, while theme switching, installing, and file editing do not.
 * This set is fixed by design — see GLOSSARY.md ("Blocklist").
 *
 * @return string[] The blocklisted capability names.
 */
function super_user_role_blocklist() {
	return array(
		'activate_plugins',
		'install_plugins',
		'delete_plugins',
		'update_plugins',
		'edit_plugins',
		'switch_themes',
		'install_themes',
		'update_themes',
		'delete_themes',
		'edit_themes',
		'edit_files',
		'edit_users',
		'create_users',
		'delete_users',
		'list_users',
		'promote_users',
		'remove_users',
		'add_users',
		'update_core',
		'manage_secrets',
		'manage_network_secrets',
	);
}

/**
 * The capability set a Super-User should hold on the current site: the site's
 * Administrator capabilities minus the Blocklist, mirrored exactly as stored
 * on the Administrator (including capabilities a plugin has revoked, which are
 * kept as false). This keeps the invariant that a Super-User is never granted
 * beyond what the site's Administrator already has.
 *
 * @return array<string, bool>|null Expected capabilities, or null when the site has no Administrator role.
 */
function super_user_role_expected_caps() {
	$administrator = get_role( 'administrator' );
	if ( ! $administrator ) {
		return null;
	}

	$expected = $administrator->capabilities;
	foreach ( super_user_role_blocklist() as $cap ) {
		unset( $expected[ $cap ] );
	}

	return $expected;
}

/**
 * Whether two capability arrays describe the same role, order-insensitively.
 *
 * @param mixed $a A capability map (cap name => granted) or anything else.
 * @param mixed $b A capability map (cap name => granted) or anything else.
 * @return bool True when both are the same capability map.
 */
function super_user_role_caps_equal( $a, $b ) {
	if ( ! is_array( $a ) || ! is_array( $b ) ) {
		return false;
	}

	ksort( $a );
	ksort( $b );

	return $a === $b;
}

/**
 * Capability sync: rewrite the current site's super_user role when it has
 * drifted from the expected set (Administrator minus the Blocklist).
 *
 * When the site is already in sync, nothing is read, written, or hooked — the
 * stored role option is left byte-identical. When the role has drifted (or is
 * missing), it is replaced; role holders keep the super_user entry in their
 * capabilities meta and regain it when the role is re-added.
 *
 * With no Administrator role on the site there is nothing to mirror: the sync
 * never creates a Super-User from nothing and never revokes one.
 */
function super_user_role_sync() {
	$expected = super_user_role_expected_caps();
	if ( null === $expected ) {
		return;
	}

	$super_user = get_role( 'super_user' );
	if ( $super_user && super_user_role_caps_equal( $super_user->capabilities, $expected ) ) {
		return;
	}

	remove_role( 'super_user' );
	add_role( 'super_user', 'Super-User', $expected );

	/*
	 * The current user's computed capabilities (allcaps) were derived from the
	 * old role state before wp_loaded fired. Rebind them so this request sees
	 * the synced set too; other holders pick the new set up on their next
	 * request. No-op for anyone not holding the super_user role.
	 */
	$current_user = wp_get_current_user();
	if ( $current_user && array_key_exists( 'super_user', $current_user->caps ) ) {
		$current_user->for_site( get_current_blog_id() );
	}
}

/**
 * Whether a user holds the Super-User role without also being an Administrator
 * (or a network Super Admin, which the caller handles separately).
 *
 * @param int $user_id User ID.
 * @return bool True when the user is a pure Super-User.
 */
function super_user_role_is_restricted( $user_id ) {
	$user = get_userdata( $user_id );
	if ( ! $user ) {
		return false;
	}

	$roles = (array) $user->roles;

	return in_array( 'super_user', $roles, true ) && ! in_array( 'administrator', $roles, true );
}

/**
 * Options that would let a Super-User create an Administrator if they could
 * write them, and the capability normally required to change each one.
 *
 * `options.php` gates Settings writes on `manage_options` alone, so the Settings
 * screen hides these fields from Super-Users but a crafted POST can still submit
 * them. The Blocklist removes the capabilities below, which is why the write is
 * denied here rather than relying on the UI.
 *
 * @return array<string, string> Option name => required capability.
 */
function super_user_role_guarded_options() {
	return array(
		'default_role'       => 'promote_users',
		'users_can_register' => 'create_users',
	);
}

/**
 * Deny a Super-User's attempt to write an option that can mint Administrators.
 *
 * Returning the old value makes `update_option()` a no-op without erroring, so
 * a crafted Settings POST leaves the stored option untouched.
 *
 * @param mixed  $value     The new option value.
 * @param mixed  $old_value The current option value.
 * @param string $option    The option name.
 * @return mixed The new value, or the old value when the change is denied.
 */
function super_user_role_guard_option_write( $value, $old_value, $option ) {
	$guarded = super_user_role_guarded_options();
	if ( ! isset( $guarded[ $option ] ) || ! is_user_logged_in() ) {
		return $value;
	}

	if ( ! super_user_role_is_restricted( get_current_user_id() ) ) {
		return $value;
	}

	if ( current_user_can( $guarded[ $option ] ) ) {
		return $value;
	}

	return $old_value;
}

/**
 * Deny `setup_network` to a Super-User.
 *
 * On single site this meta capability maps to `manage_options`, and the Network
 * Setup screen accepts an arbitrary network-admin email. Without this guard a
 * Super-User could run Network Setup, name themselves, and become a network
 * Super Admin — a role that bypasses every capability check, including the
 * Blocklist.
 *
 * @param string[] $caps    Required primitive capabilities.
 * @param string   $cap     The capability being checked.
 * @param int      $user_id User ID.
 * @return string[] Primitive capabilities.
 */
function super_user_role_deny_meta_caps( $caps, $cap, $user_id ) {
	if ( 'setup_network' !== $cap ) {
		return $caps;
	}

	if ( is_super_admin( $user_id ) || ! super_user_role_is_restricted( $user_id ) ) {
		return $caps;
	}

	return array( 'do_not_allow' );
}

/**
 * Keep a Super-User out of the legacy "All Settings" screen.
 *
 * `options.php` renders and writes arbitrary options when it is requested
 * directly (`option_page=options`, or no page at all). That bypasses the
 * Blocklist: `active_plugins`, `template`, and `stylesheet` are all writable
 * there. Normal Settings screens still POST to `options.php` with their own
 * `option_page`, so they keep working.
 */
function super_user_role_restrict_all_settings() {
	$user_id = get_current_user_id();
	if ( is_super_admin( $user_id ) || ! super_user_role_is_restricted( $user_id ) ) {
		return;
	}

	$method      = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : 'GET';
	$option_page = isset( $_REQUEST['option_page'] ) && is_string( $_REQUEST['option_page'] )
		? sanitize_text_field( wp_unslash( $_REQUEST['option_page'] ) )
		: '';

	if ( 'POST' !== $method || '' === $option_page || 'options' === $option_page ) {
		wp_die(
			'Sorry, you are not allowed to manage these options.',
			'',
			array( 'response' => 403 )
		);
	}
}

/**
 * Keep a Super-User out of the "All Settings" page.
 *
 * The page's capability is `manage_options`; returning `do_not_allow` for a
 * Super-User rejects the check without changing what Administrators need. On
 * WordPress older than 7.0 this filter does not apply to direct access, so
 * `super_user_role_restrict_all_settings()` is the second line of defence.
 *
 * @param string $capability Capability required by options.php.
 * @return string Capability required by options.php.
 */
function super_user_role_restrict_all_settings_cap( $capability ) {
	$user_id = get_current_user_id();
	if ( ! is_super_admin( $user_id ) && super_user_role_is_restricted( $user_id ) ) {
		return 'do_not_allow';
	}

	return $capability;
}

add_filter( 'pre_update_option_default_role', 'super_user_role_guard_option_write', 10, 3 );
add_filter( 'pre_update_option_users_can_register', 'super_user_role_guard_option_write', 10, 3 );
add_filter( 'map_meta_cap', 'super_user_role_deny_meta_caps', 10, 3 );
add_filter( 'option_page_capability_options', 'super_user_role_restrict_all_settings_cap' );
add_action( 'load-options.php', 'super_user_role_restrict_all_settings' );

add_action( 'wp_loaded', 'super_user_role_sync' );
