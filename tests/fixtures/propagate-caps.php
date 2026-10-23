<?php
/**
 * Test fixture: a "role-adding plugin" (WooCommerce-style) that injects
 * capabilities into the current site's Administrator role at runtime.
 *
 * The sync's job is to pick such changes up on the next boot — the tests call
 * super_user_role_sync() to simulate that boot. Two injections, one positive
 * and one negative:
 *
 * - 'rsu_fake_cap' — a brand-new capability, so it must propagate to super_user.
 * - 'add_users'    — a Blocklist capability the default Administrator lacks, so
 *                    it must NOT propagate even though the Administrator now has it.
 */

function rsu_fixture_inject_admin_caps() {
	$administrator = get_role( 'administrator' );
	if ( ! $administrator ) {
		return;
	}

	$administrator->add_cap( 'rsu_fake_cap' );
	$administrator->add_cap( 'add_users', true );
}