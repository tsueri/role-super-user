<?php
/**
 * Plugin Name:  Super-User Role
 * Description:  Maintains a per-site Super-User role that mirrors the site's Administrator capabilities minus the Blocklist. Drop this file into wp-content/mu-plugins/ to install.
 * Version:      1.1.0
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
 * Administrator. To stop syncing, remove this file (revokes nothing); for
 * complete removal run `wp super-user abolish` — it strips holders, deletes
 * the role, and removes the file itself, in that order. The README keeps the
 * manual procedure for when the file is already gone.
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
 * Whether a user is a Pure Super-User (GLOSSARY.md): they hold the Super-User
 * role without also being an Administrator on the site, and they are not a
 * Super Admin in WordPress's sense (is_super_admin()).
 *
 * The escalation guards apply to exactly this class; everyone else passes
 * through untouched. On multisite, `is_super_admin()` reads the network's
 * super-admin list; on single site, core also counts holders of `delete_users`
 * as Super Admins. The Blocklist never grants `delete_users` through the role,
 * so only a per-user grant can put a Super-User there — and such a user has
 * left the "Administrator minus the Blocklist" model the guards police.
 * Logged-out users hold no roles and fail the role check.
 *
 * @param int $user_id User ID.
 * @return bool True when the user is a Pure Super-User.
 */
function super_user_role_is_restricted( $user_id ) {
	$user = get_userdata( $user_id );
	if ( ! $user || is_super_admin( $user_id ) ) {
		return false;
	}

	$roles = (array) $user->roles;

	return in_array( 'super_user', $roles, true ) && ! in_array( 'administrator', $roles, true );
}

/**
 * Options that would let a Super-User create an Administrator if they could
 * write them, and the capability normally required to change each one.
 *
 * This map is the registration: every entry is enforced by the single
 * `pre_update_option` filter, so adding a guarded option means adding an
 * entry here — and nothing else. The two can never drift apart silently.
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
 * Deny a Pure Super-User's attempt to write an option that can mint Administrators.
 *
 * Runs on the generic `pre_update_option` filter: every option write passes
 * through, and the `super_user_role_guarded_options()` lookup is the first
 * statement, so unguarded options cost one isset(). Returning the old value
 * makes `update_option()` a no-op without erroring, so a crafted Settings POST
 * leaves the stored option untouched. The filters also run before core's
 * `add_option()` fallback for a missing option row, and the same returned
 * old value forces the equality check that short-circuits that path too.
 *
 * @param mixed  $value     The new option value.
 * @param string $option    The option name.
 * @param mixed  $old_value The current option value.
 * @return mixed The new value, or the old value when the change is denied.
 */
function super_user_role_guard_option_write( $value, $option, $old_value ) {
	$guarded = super_user_role_guarded_options();
	if ( ! isset( $guarded[ $option ] ) ) {
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
 * Meta capabilities denied to a Pure Super-User even though the site's
 * Administrator holds what they map to. Every entry maps to `manage_options`
 * on single site — a primitive the Super-User keeps — so without this list
 * each would be a route around the Blocklist's intent that a capability check
 * alone allows:
 *
 * - `setup_network`: the Network Setup screen accepts an arbitrary
 *   network-admin email; a Super-User could run Network Setup, name
 *   themselves, and become a network Super Admin — a role that bypasses every
 *   capability check, including the Blocklist.
 * - `export_others_personal_data`: the privacy tools would let a Super-User
 *   export another user's personal data, an Administrator's included,
 *   bypassing `edit_users`/`list_users`. Administrators keep the tool.
 *
 * `erase_others_personal_data` needs no entry: the erase action also requires
 * `delete_users`, which the Blocklist already removes.
 *
 * Adding an entry here is a data change, not a new branch.
 *
 * @return string[] The denied meta capability names.
 */
function super_user_role_denied_meta_caps() {
	return array(
		'setup_network',
		'export_others_personal_data',
	);
}

/**
 * Deny the meta capabilities listed in super_user_role_denied_meta_caps()
 * to a Pure Super-User.
 *
 * Returning `do_not_allow` rejects the check outright, without changing what
 * anyone else needs. `super_user_role_is_restricted()` already exempts
 * Administrators and everyone WordPress counts as a Super Admin (ADR-0002),
 * so only a Pure Super-User is denied.
 *
 * @param string[] $caps    Required primitive capabilities.
 * @param string   $cap     The capability being checked.
 * @param int      $user_id User ID.
 * @return string[] Primitive capabilities.
 */
function super_user_role_deny_meta_caps( $caps, $cap, $user_id ) {
	if ( ! in_array( $cap, super_user_role_denied_meta_caps(), true ) ) {
		return $caps;
	}

	if ( ! super_user_role_is_restricted( $user_id ) ) {
		return $caps;
	}

	return array( 'do_not_allow' );
}

/**
 * The All-Settings request decision: the rule behind the load-options.php
 * guard, as a pure function (an internal seam of this module).
 *
 * Both arguments arrive canonical: `$method` uppercased, `$option_page`
 * sanitized — the request plumbing lives in the adapter. Blocked is the
 * default: the request must be a POST to a named Settings screen other than
 * the All Settings pseudo-page itself (`option_page=options`, or no page at
 * all, is how options.php renders and writes arbitrary options). A caller
 * that skips the uppercasing fails closed, which is the safe direction here.
 *
 * @param string $method      Request method, uppercased.
 * @param string $option_page Sanitized `option_page` request value, '' if absent.
 * @return bool True when the request is blocked from the All Settings screen.
 */
function super_user_role_all_settings_blocked( $method, $option_page ) {
	return 'POST' !== $method || '' === $option_page || 'options' === $option_page;
}

/**
 * Keep a Pure Super-User out of the legacy "All Settings" screen.
 *
 * The adapter: reads the request, applies the applicability predicate, and
 * hands canonical values to `super_user_role_all_settings_blocked()` — the
 * rule itself lives there, tested as a table. `options.php` renders and
 * writes arbitrary options when it is requested directly (`option_page=options`,
 * or no page at all). That bypasses the Blocklist: `active_plugins`, `template`,
 * and `stylesheet` are all writable there. Normal Settings screens still POST
 * to `options.php` with their own `option_page`, so they keep working.
 */
function super_user_role_restrict_all_settings() {
	if ( ! super_user_role_is_restricted( get_current_user_id() ) ) {
		return;
	}

	$method      = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : 'GET';
	$option_page = isset( $_REQUEST['option_page'] ) && is_string( $_REQUEST['option_page'] )
		? sanitize_text_field( wp_unslash( $_REQUEST['option_page'] ) )
		: '';

	if ( super_user_role_all_settings_blocked( $method, $option_page ) ) {
		wp_die(
			'Sorry, you are not allowed to manage these options.',
			'',
			array( 'response' => 403 )
		);
	}
}

/**
 * Keep a Pure Super-User out of the "All Settings" page.
 *
 * The page's capability is `manage_options`; returning `do_not_allow` for a
 * Pure Super-User rejects the check without changing what Administrators need.
 * On WordPress older than 7.0 this filter does not apply to direct access, so
 * `super_user_role_restrict_all_settings()` is the second line of defence.
 *
 * @param string $capability Capability required by options.php.
 * @return string Capability required by options.php.
 */
function super_user_role_restrict_all_settings_cap( $capability ) {
	if ( ! super_user_role_is_restricted( get_current_user_id() ) ) {
		return $capability;
	}

	return 'do_not_allow';
}

/**
 * The sites an Abolish covers: the current site, or — with the network flag —
 * every live site of a multisite network. Archived, spam, and deleted sites
 * are skipped: they are inert, matching the documented manual procedure.
 *
 * @param bool $network Whether to cover every live site of the network.
 * @return int[] Blog IDs to abolish.
 */
function super_user_role_abolish_site_ids( $network ) {
	if ( ! $network || ! is_multisite() ) {
		return array( get_current_blog_id() );
	}

	$site_ids = array();
	foreach ( get_sites( array( 'archived' => 0, 'deleted' => 0, 'spam' => 0, 'number' => 0 ) ) as $site ) {
		$site_ids[] = (int) $site->blog_id;
	}

	return $site_ids;
}

/**
 * What an Abolish of one site would do, without changing anything: the plan.
 *
 * @param int $blog_id Blog ID.
 * @return array<string, mixed> holders: count of Super-User holders; role_exists: whether the role definition is present; url: the site's URL.
 */
function super_user_role_abolish_plan_site( $blog_id ) {
	$blog_id  = (int) $blog_id;
	$switched = false;

	if ( is_multisite() && get_current_blog_id() !== $blog_id ) {
		switch_to_blog( $blog_id );
		$switched = true;
	}

	$plan = array(
		'holders'     => count( get_users( array( 'role' => 'super_user', 'fields' => 'ids' ) ) ),
		'role_exists' => (bool) get_role( 'super_user' ),
		'url'         => home_url(),
	);

	if ( $switched ) {
		restore_current_blog();
	}

	return $plan;
}

/**
 * Abolish one site: strip the Super-User role from its holders, then delete
 * the role definition.
 *
 * The order is the whole point: `remove_role()` — and WP-CLI's `wp role delete`
 * — touches no user meta, so deleting first would leave an inert stale
 * `super_user` entry in holders' capabilities meta, and reinstalling the
 * plugin would lazily re-create the role and instantly re-power those users.
 * Stripping first removes that foothold: naming the role keeps holders' other
 * roles and site membership, and a sync that runs while the file is still
 * present can only re-create an empty definition no holder would fill.
 *
 * @param int $blog_id Blog ID.
 * @return array<string, mixed> holders_stripped: count; role_deleted: whether a role definition was deleted.
 */
function super_user_role_abolish_site( $blog_id ) {
	$blog_id  = (int) $blog_id;
	$stripped = 0;
	$switched = false;

	if ( is_multisite() && get_current_blog_id() !== $blog_id ) {
		switch_to_blog( $blog_id );
		$switched = true;
	}

	foreach ( get_users( array( 'role' => 'super_user', 'fields' => 'ids' ) ) as $user_id ) {
		$user = get_userdata( $user_id );
		if ( $user ) {
			$user->remove_role( 'super_user' );
			$stripped++;
		}
	}

	$role_existed = (bool) get_role( 'super_user' );
	remove_role( 'super_user' );

	if ( $switched ) {
		restore_current_blog();
	}

	return array(
		'holders_stripped' => $stripped,
		'role_deleted'     => $role_existed,
	);
}

/**
 * Abolish the whole network: every live site, each via
 * super_user_role_abolish_site(). On single site this covers the one site.
 *
 * @return array<int, array<string, mixed>> Blog ID => super_user_role_abolish_site() result.
 */
function super_user_role_abolish_network() {
	$results = array();
	foreach ( super_user_role_abolish_site_ids( true ) as $blog_id ) {
		$results[ $blog_id ] = super_user_role_abolish_site( $blog_id );
	}

	return $results;
}

add_filter( 'pre_update_option', 'super_user_role_guard_option_write', 10, 3 );
add_filter( 'map_meta_cap', 'super_user_role_deny_meta_caps', 10, 3 );
add_filter( 'option_page_capability_options', 'super_user_role_restrict_all_settings_cap' );
add_action( 'load-options.php', 'super_user_role_restrict_all_settings' );

add_action( 'wp_loaded', 'super_user_role_sync' );

if ( defined( 'WP_CLI' ) && WP_CLI ) {

	/**
	 * WP-CLI command: the Abolish procedure as one command.
	 */
	class Super_User_Role_Abolish_Command {

		/**
		 * Completely remove the Super-User role: strip holders, delete the role definition, remove this mu-plugin file.
		 *
		 * The order is enforced here: holders are stripped before the role is
		 * deleted (deleting first leaves the stale super_user entry in holders'
		 * capabilities meta, which reinstalling the plugin would instantly
		 * re-power), and the mu-plugin file is removed last — there is no
		 * window in which the lazy sync could re-create the role. Without
		 * --yes nothing is changed: only the plan is printed.
		 *
		 * [--network]
		 * : Every live site of the network; archived, spam, and deleted sites are skipped.
		 *
		 * [--yes]
		 * : Execute. Without it, print the plan and change nothing.
		 *
		 * ## EXAMPLES
		 *
		 *     # Plan only
		 *     wp super-user abolish
		 *
		 *     # Abolish this site (or the one selected with --url)
		 *     wp super-user abolish --yes
		 *
		 *     # Abolish every live site of the network
		 *     wp super-user abolish --network --yes
		 */
		public function abolish( $args, $assoc_args ) {
			$network = isset( $assoc_args['network'] );
			$yes     = isset( $assoc_args['yes'] );

			if ( $network && ! is_multisite() ) {
				WP_CLI::line( 'Single site: --network covers this one site.' );
			}

			foreach ( super_user_role_abolish_site_ids( $network ) as $blog_id ) {
				$plan = super_user_role_abolish_plan_site( $blog_id );
				WP_CLI::line(
					sprintf(
						'  site %d (%s): %d holder(s), role definition %s.',
						$blog_id,
						$plan['url'],
						$plan['holders'],
						$plan['role_exists'] ? 'present' : 'absent'
					)
				);
			}

			if ( ! $yes ) {
				WP_CLI::line( 'Nothing changed. Run again with --yes to execute.' );
				return;
			}

			foreach ( super_user_role_abolish_site_ids( $network ) as $blog_id ) {
				$result = super_user_role_abolish_site( $blog_id );
				WP_CLI::line(
					sprintf(
						'  site %d: stripped %d holder(s), role definition %s.',
						$blog_id,
						$result['holders_stripped'],
						$result['role_deleted'] ? 'deleted' : 'was already absent'
					)
				);
			}

			/*
			 * File out, last: the mu-plugin file is the sync itself. Removing
			 * it mid-run is safe — PHP keeps executing the loaded script.
			 */
			if ( @unlink( __FILE__ ) ) {
				WP_CLI::success( 'Abolished. The mu-plugin file was removed: ' . __FILE__ );
				return;
			}

			WP_CLI::error(
				'Holders stripped and role definitions deleted, but the mu-plugin file could not be removed. Delete it by hand, or the lazy sync will re-create empty role definitions: ' . __FILE__
			);
		}
	}

	WP_CLI::add_command( 'super-user', 'Super_User_Role_Abolish_Command' );
}
