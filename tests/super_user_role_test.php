<?php
/**
 * Tests for the Super-User role mu-plugin.
 *
 * One spec over the single-site and multisite wp-env configs (the map's
 * test-plan ticket). Assertions cover both layers the plugin owns: the stored
 * role (`get_role()->capabilities`) and runtime effectiveness (`user_can()`).
 *
 * Every expectation that depends on the Administrator role is derived from the
 * live role, so tests stay order-independent — including after the propagation
 * fixture has injected capabilities into the Administrator.
 */

class Super_User_Role_Test extends WP_UnitTestCase {

	/**
	 * The Blocklist, hardcoded here as the locked spec (GLOSSARY.md:
	 * "Blocklist"). The suite asserts against this list, not against the
	 * plugin's copy of it — and pins the two copies together
	 * (test_locked_blocklist_matches_the_plugin_blocklist()), so neither can
	 * drift apart silently.
	 *
	 * @var string[]
	 */
	private static $blocklist = array(
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

	/**
	 * The current site's role option key.
	 *
	 * @var string
	 */
	private $role_key;

	public function set_up() {
		parent::set_up();
		$this->role_key = $GLOBALS['wpdb']->get_blog_prefix() . 'user_roles';
	}

	/**
	 * The suite's locked Blocklist above and the plugin's Blocklist must be
	 * the same list: this pins the two copies together so neither can drift
	 * silently — e.g. a capability added to one and forgotten in the other.
	 * Compared order-insensitively: order is presentation, membership is spec.
	 */
	public function test_locked_blocklist_matches_the_plugin_blocklist() {
		$expected = self::$blocklist;
		$actual   = super_user_role_blocklist();

		sort( $expected );
		sort( $actual );

		$this->assertSame( $expected, $actual, 'The locked Blocklist and the plugin Blocklist have drifted apart.' );
	}

	/**
	 * The stored-caps invariant: the super_user role equals the Administrator's
	 * capabilities minus the Blocklist. Locked entries: edit_theme_options
	 * stays, update_core and edit_files are stripped.
	 */
	public function test_stored_role_equals_administrator_minus_blocklist() {
		$administrator = get_role( 'administrator' );
		$this->assertNotNull( $administrator, 'The site must have an Administrator role to mirror.' );

		$expected = $administrator->capabilities;
		foreach ( self::$blocklist as $cap ) {
			unset( $expected[ $cap ] );
		}

		$super_user = get_role( 'super_user' );
		$this->assertNotNull( $super_user, 'The Capability sync created the super_user role during boot.' );

		$actual = $super_user->capabilities;
		ksort( $expected );
		ksort( $actual );
		$this->assertSame( $expected, $actual );

		$this->assertArrayHasKey( 'edit_theme_options', $actual, 'Menus, widgets, and the Customizer stay.' );
		$this->assertArrayNotHasKey( 'update_core', $actual, 'Core updates stay with admins/network.' );
		$this->assertArrayNotHasKey( 'edit_files', $actual, 'Code editing stays blocked.' );

		foreach ( self::$blocklist as $cap ) {
			$this->assertArrayNotHasKey( $cap, $actual, "Blocklist cap '{$cap}' must never enter the role." );
		}
	}

	/**
	 * Runtime effectiveness: what the role holds is what the user can do.
	 */
	public function test_effective_caps_match_the_synced_role() {
		$user_id = self::factory()->user->create( array( 'role' => 'super_user' ) );

		// Content and site settings stay in reach.
		$this->assertTrue( user_can( $user_id, 'read' ) );
		$this->assertTrue( user_can( $user_id, 'edit_posts' ) );
		$this->assertTrue( user_can( $user_id, 'manage_options' ), 'Site settings stay available.' );

		// The Blocklist is effective at runtime, not merely absent from the role.
		$this->assertFalse( user_can( $user_id, 'edit_users' ) );
		$this->assertFalse( user_can( $user_id, 'edit_plugins' ) );
		$this->assertFalse( user_can( $user_id, 'switch_themes' ), 'Theme switching stays blocked.' );
		$this->assertFalse( user_can( $user_id, 'update_core' ) );
	}

	/**
	 * Own profile editable, other users' not (meta-cap behavior on both configs).
	 */
	public function test_own_profile_editable_but_not_other_users() {
		$user_id = self::factory()->user->create( array( 'role' => 'super_user' ) );
		$other   = self::factory()->user->create();

		$this->assertTrue( user_can( $user_id, 'edit_user', $user_id ) );
		$this->assertFalse( user_can( $user_id, 'edit_user', $other ) );
	}

	/**
	 * unfiltered_html: stored on both configs (cloned from the Administrator);
	 * effective on single site, denied on subsites by core's map_meta_cap() —
	 * a denial that is core's, not the plugin's. The invariant this plugin owns
	 * is only "never granted beyond what the site's Administrator already has".
	 */
	public function test_unfiltered_html_stored_everywhere_effective_per_config() {
		$administrator = get_role( 'administrator' );
		$super_user    = get_role( 'super_user' );

		$this->assertArrayHasKey( 'unfiltered_html', $administrator->capabilities );
		$this->assertArrayHasKey( 'unfiltered_html', $super_user->capabilities );

		$user_id = self::factory()->user->create( array( 'role' => 'super_user' ) );
		$this->assertSame( ! is_multisite(), user_can( $user_id, 'unfiltered_html' ) );
	}

	/**
	 * The plugin never grants a Super-User anything the site's Administrator
	 * does not hold — including after fixture injections.
	 */
	public function test_super_user_is_never_granted_beyond_administrator() {
		$administrator = get_role( 'administrator' );
		$super_user    = get_role( 'super_user' );

		foreach ( $super_user->capabilities as $cap => $granted ) {
			if ( ! array_key_exists( $cap, $administrator->capabilities ) ) {
				$this->fail( "Super-User holds '{$cap}', which the Administrator role does not." );
			}
			$this->assertSame(
				$administrator->capabilities[ $cap ],
				$granted,
				"Cap '{$cap}' must mirror the Administrator's grant."
			);
		}
	}

	/**
	 * Secrets stay with Administrators. WordPress 7.2 grants `manage_secrets`
	 * to the Administrator role, and network-level plugins can grant
	 * `manage_network_secrets`; the fixture simulates both grants, so the
	 * stored-role assertions hold on every WordPress version the suite runs
	 * against — no version gate, no skip.
	 *
	 * What each layer proves:
	 *
	 * - Stored role, both configs: the fixture's grant reaches the
	 *   Administrator while the sync keeps both capabilities out of the
	 *   Super-User role — the Blocklist wins at the mirror, whatever core
	 *   does at check time.
	 * - Super-User runtime denial, both configs: the Super-User role holds
	 *   neither primitive, so `user_can()` stays false whether or not 7.2
	 *   adds a check-time subsite gate. The plugin's denial does not depend
	 *   on core's mapping, so it is asserted unconditionally.
	 * - Administrator runtime allow, single site only: proves the Blocklist
	 *   costs the Administrator nothing where WordPress exposes the
	 *   capability — on single site, the role grant satisfies the check. On
	 *   multisite, 7.2's check-time mapping for subsite users is unspecified:
	 *   core may gate the capability on the site, as it does for `edit_users`
	 *   on others and the privacy caps, which resolve against
	 *   `manage_network`. A multisite allow-assertion would codify a guess,
	 *   so none is made.
	 *
	 * `manage_network_secrets` gets the stored-role check and the Super-User
	 * denial, but no runtime allow-check on any config — inventing one would
	 * assert the same unverifiable guess. The Administrator-facing guarantee
	 * this plugin owns is the stored mirror (never granted beyond the site's
	 * Administrator); whether WordPress exposes a capability at check time on
	 * every config is core's call, so the suite asserts the allow only where
	 * it is verifiable today: single site, `manage_secrets`.
	 */
	public function test_secret_management_stays_with_administrators() {
		require_once __DIR__ . '/fixtures/propagate-caps.php';

		$secret_caps = array( 'manage_secrets', 'manage_network_secrets' );

		foreach ( $secret_caps as $cap ) {
			$this->assertArrayNotHasKey( $cap, get_role( 'super_user' )->capabilities, "Precondition: '{$cap}' has not propagated yet." );
		}

		rsu_fixture_inject_caps( $secret_caps );
		super_user_role_sync();

		$administrator = get_role( 'administrator' );
		$super_user    = get_role( 'super_user' );

		foreach ( $secret_caps as $cap ) {
			$this->assertArrayHasKey( $cap, $administrator->capabilities, "Precondition: the Administrator now holds '{$cap}'." );
			$this->assertArrayNotHasKey( $cap, $super_user->capabilities, "Blocklist cap '{$cap}' must never enter the role." );
		}

		// Runtime, not merely stored: the Super-User role holds neither
		// primitive, so the denial holds on both configs whether or not 7.2
		// adds a check-time subsite gate.
		$super_id = self::factory()->user->create( array( 'role' => 'super_user' ) );
		foreach ( $secret_caps as $cap ) {
			$this->assertFalse( user_can( $super_id, $cap ), "'{$cap}' must stay out of a Super-User's runtime checks." );
		}

		/*
		 * Administrator allow-check, single site only — deliberately not
		 * `assertSame( ! is_multisite(), ... )`: that would assert a multisite
		 * denial this suite cannot verify, because 7.2's check-time mapping
		 * for subsite users is core's call (see the docblock above).
		 */
		if ( ! is_multisite() ) {
			$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
			$this->assertTrue( user_can( $admin_id, 'manage_secrets' ), 'Administrators keep managing secrets where WordPress exposes the capability.' );
		}
	}

	/**
	 * Idempotence: a boot with no Administrator change leaves the stored role
	 * option untouched — not even a write attempt.
	 */
	public function test_sync_in_sync_state_writes_nothing() {
		$before = get_option( $this->role_key );

		$writes  = 0;
		$counter = static function ( $value ) use ( &$writes ) {
			$writes++;
			return $value;
		};
		add_filter( 'pre_update_option_' . $this->role_key, $counter, 10, 3 );

		super_user_role_sync();

		remove_filter( 'pre_update_option_' . $this->role_key, $counter, 10 );

		$this->assertSame( 0, $writes, 'An in-sync boot must not attempt a role rewrite.' );
		$this->assertSame( $before, get_option( $this->role_key ), 'The stored role option is byte-identical after an in-sync boot.' );
	}

	/**
	 * Propagation, positive: a runtime role-adding plugin (WooCommerce-style)
	 * injects a new cap into the Administrator; the next boot's sync picks it up.
	 */
	public function test_new_administrator_cap_propagates_on_next_sync() {
		require_once __DIR__ . '/fixtures/propagate-caps.php';

		$this->assertArrayNotHasKey( 'rsu_fake_cap', get_role( 'super_user' )->capabilities, 'Precondition: the cap has not propagated yet.' );

		rsu_fixture_inject_caps( array( 'rsu_fake_cap', 'add_users' ) );
		super_user_role_sync();

		$super_user = get_role( 'super_user' );
		$this->assertArrayHasKey( 'rsu_fake_cap', $super_user->capabilities, 'New Administrator caps reach Super-Users on the next boot.' );
		$this->assertArrayNotHasKey( 'add_users', $super_user->capabilities, 'The Blocklist wins even over Administrator-held caps.' );
	}

	/**
	 * Propagation, removal direction: a cap the Administrator loses is lost by
	 * the Super-User on the next sync.
	 */
	public function test_removed_administrator_cap_is_removed_from_super_user() {
		$administrator = get_role( 'administrator' );
		$this->assertArrayHasKey( 'moderate_comments', $administrator->capabilities, 'Precondition: the cap is part of the default Administrator role.' );

		$administrator->remove_cap( 'moderate_comments' );
		super_user_role_sync();
		$this->assertArrayNotHasKey( 'moderate_comments', get_role( 'super_user' )->capabilities );

		$administrator->add_cap( 'moderate_comments' );
		super_user_role_sync();
		$this->assertArrayHasKey( 'moderate_comments', get_role( 'super_user' )->capabilities );
	}

	/**
	 * Per-site isolation (multisite only): booting site A must not sync
	 * untouched site B — asserted by reading B's role option directly, without
	 * booting B.
	 */
	public function test_untouched_subsite_has_no_super_user_role() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only.' );
		}

		$blog_id = self::factory()->blog->create();
		$prefix  = $GLOBALS['wpdb']->get_blog_prefix( $blog_id );
		$roles   = get_blog_option( $blog_id, $prefix . 'user_roles' );

		$this->assertIsArray( $roles, 'Site B has a role set of its own.' );
		$this->assertArrayHasKey( 'administrator', $roles );
		$this->assertArrayNotHasKey( 'super_user', $roles, 'Booting site A must not sync untouched site B.' );
	}

	/**
	 * The applicability predicate (GLOSSARY.md, "Pure Super-User"): the
	 * escalation guards restrict exactly the users holding the Super-User role
	 * without an Administrator role and without network Super Admin status —
	 * and never anyone else.
	 */
	public function test_pure_super_user_predicate() {
		$this->assertFalse( super_user_role_is_restricted( 0 ), 'Logged out: no roles, no restriction.' );
		$this->assertFalse( super_user_role_is_restricted( PHP_INT_MAX ), 'A nonexistent user is never restricted.' );

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->assertFalse( super_user_role_is_restricted( $admin_id ), 'An Administrator is never restricted.' );

		$super_id = self::factory()->user->create( array( 'role' => 'super_user' ) );
		$this->assertTrue( super_user_role_is_restricted( $super_id ), 'A Pure Super-User is restricted.' );

		// An Administrator who also holds the Super-User role is never restricted.
		get_userdata( $admin_id )->add_role( 'super_user' );
		$this->assertFalse( super_user_role_is_restricted( $admin_id ) );

		// Neither is a Super Admin in WordPress's sense (is_super_admin()): on
		// multisite via the network's super-admin list; on single site core
		// counts holders of `delete_users` as Super Admins, and only a per-user
		// grant can put that capability on a Super-User (the Blocklist never
		// grants it through the role).
		if ( is_multisite() ) {
			grant_super_admin( $super_id );
		} else {
			get_userdata( $super_id )->add_cap( 'delete_users' );
		}
		$this->assertFalse( super_user_role_is_restricted( $super_id ) );
	}

	/**
	 * Escalation guard: a Super-User cannot turn the default role into
	 * Administrator or enable registration with that default, even though
	 * `manage_options` lets them reach options.php. A crafted Settings POST must
	 * be a no-op.
	 */
	public function test_super_user_cannot_create_administrators_via_settings() {
		wp_set_current_user( 0 );

		$user_id  = self::factory()->user->create( array( 'role' => 'super_user' ) );
		$original = get_option( 'default_role' );

		update_option( 'default_role', 'subscriber' );
		update_option( 'users_can_register', 0 );

		wp_set_current_user( $user_id );

		try {
			$this->assertFalse( update_option( 'default_role', 'administrator' ), 'The guarded option write must be a no-op.' );
			$this->assertSame( 'subscriber', get_option( 'default_role' ) );

			$this->assertFalse( update_option( 'users_can_register', 1 ), 'The guarded option write must be a no-op.' );
			$this->assertSame( 0, (int) get_option( 'users_can_register' ) );
		} finally {
			wp_set_current_user( 0 );
			update_option( 'default_role', $original );
			update_option( 'users_can_register', 0 );
		}
	}

	/**
	 * The guard is role-specific: an Administrator keeps full control.
	 */
	public function test_administrator_can_still_change_default_role() {
		wp_set_current_user( 0 );

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$original = get_option( 'default_role' );

		update_option( 'default_role', 'subscriber' );
		wp_set_current_user( $admin_id );

		try {
			$this->assertTrue( update_option( 'default_role', 'author' ) );
			$this->assertSame( 'author', get_option( 'default_role' ) );
		} finally {
			wp_set_current_user( 0 );
			update_option( 'default_role', $original );
		}
	}

	/**
	 * Escalation guard: `setup_network` maps to `manage_options` on single site.
	 * A Super-User must not satisfy it; an Administrator still does.
	 */
	public function test_super_user_cannot_setup_network() {
		$super_id = self::factory()->user->create( array( 'role' => 'super_user' ) );
		$this->assertFalse( user_can( $super_id, 'setup_network' ) );

		if ( ! is_multisite() ) {
			$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
			$this->assertTrue( user_can( $admin_id, 'setup_network' ), 'The guard must not remove Administrator access.' );
		}
	}

	/**
	 * Escalation guard: `export_others_personal_data` maps to `manage_options`
	 * on single site, so without the guard a Super-User could export another
	 * user's personal data — an Administrator's included — bypassing the
	 * Blocklist's `edit_users`/`list_users` intent. Administrators keep the
	 * tool; on multisite core denies the privacy tools to non-network users,
	 * and the guard changes nothing there.
	 */
	public function test_super_user_cannot_export_others_personal_data() {
		$super_id = self::factory()->user->create( array( 'role' => 'super_user' ) );
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$this->assertFalse( user_can( $super_id, 'export_others_personal_data' ) );
		$this->assertSame(
			! is_multisite(),
			user_can( $admin_id, 'export_others_personal_data' ),
			'Administrators keep the tool on single site; the multisite denial is core\'s and must not change.'
		);
	}

	/**
	 * The legacy All Settings screen writes arbitrary options (active_plugins,
	 * template, stylesheet), so a Super-User must not reach it. Administrators
	 * keep the page's normal `manage_options` requirement.
	 */
	public function test_super_user_cannot_reach_all_settings() {
		wp_set_current_user( 0 );

		$super_id = self::factory()->user->create( array( 'role' => 'super_user' ) );
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		try {
			$this->assertSame(
				'manage_options',
				apply_filters( 'option_page_capability_options', 'manage_options' ),
				'Logged-out system updates keep the normal capability.'
			);

			wp_set_current_user( $super_id );
			$this->assertSame(
				'do_not_allow',
				apply_filters( 'option_page_capability_options', 'manage_options' ),
				'A Super-User must not satisfy the All Settings capability.'
			);

			wp_set_current_user( $admin_id );
			$this->assertSame(
				'manage_options',
				apply_filters( 'option_page_capability_options', 'manage_options' ),
				'Administrators keep the All Settings screen.'
			);
		} finally {
			wp_set_current_user( 0 );
		}
	}

	/**
	 * Missing-Administrator guard: with the administrator role gone, the sync
	 * must not fatal, must not create a super_user role, and must not revoke an
	 * existing one — sync stops, revocation never happens.
	 */
	public function test_missing_administrator_role_is_guarded() {
		$snapshot     = get_option( $this->role_key );
		$super_before = get_role( 'super_user' );

		try {
			wp_roles()->remove_role( 'administrator' );
			super_user_role_sync();

			if ( $super_before ) {
				$this->assertSame(
					$super_before->capabilities,
					get_role( 'super_user' )->capabilities,
					'An existing Super-User role is left untouched when the Administrator role is missing.'
				);
			} else {
				$this->assertNull( get_role( 'super_user' ), 'A boot with no Administrator role must not create a Super-User role.' );
			}
		} finally {
			update_option( $this->role_key, $snapshot );
			$GLOBALS['wp_roles'] = null;
		}
	}
}