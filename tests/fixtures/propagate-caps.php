<?php
/**
 * Test fixture: a "role-adding plugin" (WooCommerce-style) that injects
 * capabilities into the current site's Administrator role at runtime.
 *
 * The sync's job is to pick such changes up on the next boot — the tests call
 * super_user_role_sync() to simulate that boot. Each test calls the injector
 * with its own explicit cap set, so no test's precondition depends on another
 * test's injection:
 *
 * - test_new_administrator_cap_propagates_on_next_sync() injects:
 *   - 'rsu_fake_cap' — a brand-new capability, so it must propagate to
 *     super_user.
 *   - 'add_users'    — a Blocklist capability the default Administrator lacks,
 *     so it must NOT propagate even though the Administrator now has it.
 *
 * - test_secret_management_stays_with_administrators() injects:
 *   - 'manage_secrets' and 'manage_network_secrets' — the WordPress 7.2
 *     secret-management capabilities, Blocklisted so credentials never reach
 *     Super-Users. Before 7.2 this simulates the grant core will make; on 7.2
 *     and later it is redundant with it.
 */

/**
 * Inject capabilities into the current site's Administrator role, as a
 * runtime role-adding plugin would.
 *
 * @param string[] $caps Capability names to grant.
 */
function rsu_fixture_inject_caps( array $caps ) {
	$administrator = get_role( 'administrator' );
	if ( ! $administrator ) {
		return;
	}

	foreach ( $caps as $cap ) {
		$administrator->add_cap( $cap, true );
	}
}
