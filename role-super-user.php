<?php
/**
 * Plugin Name:  Super-User Role
 * Description:  Maintains a per-site Super-User role that mirrors the site's Administrator capabilities minus the Blocklist. Drop this file into wp-content/mu-plugins/ to install.
 * Version:      1.0.0
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
 * management, code editing, and core updates.
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

add_action( 'wp_loaded', 'super_user_role_sync' );