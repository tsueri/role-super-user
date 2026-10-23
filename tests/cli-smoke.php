<?php
/**
 * WP-CLI smoke test.
 *
 * `wp eval-file` boots WordPress fully — wp_loaded fires during the bootstrap,
 * so the mu-plugin's Capability sync has already run for this site before this
 * file executes, exactly as on a real site managed through WP-CLI.
 *
 * Run (from the repo root):
 *   npm run test:cli               (single site)
 *   npm run test:cli:multisite     (multisite main site)
 *
 * Exits 0 when the current site's super_user role exists and satisfies the
 * stored-caps invariant; non-zero otherwise.
 */

$administrator = get_role( 'administrator' );
$super_user    = get_role( 'super_user' );
$site          = get_current_blog_id();

if ( ! $administrator ) {
	echo "FAIL: this site has no administrator role to mirror.\n";
	exit( 1 );
}

if ( ! $super_user ) {
	echo "FAIL: super_user role does not exist on site {$site} after boot.\n";
	exit( 1 );
}

if ( ! super_user_role_caps_equal( $super_user->capabilities, super_user_role_expected_caps() ) ) {
	echo "FAIL: super_user capabilities have drifted from Administrator minus the Blocklist on site {$site}.\n";
	exit( 1 );
}

echo 'OK: super_user in sync on site ' . $site . ' (' . count( $super_user->capabilities ) . " caps)\n";

/*
 * The Abolish command is registered, and its plan mode changes nothing: the
 * plan prints (with its --yes hint) and the role is still here afterwards.
 */
$plan = WP_CLI::runcommand(
	'super-user abolish',
	array( 'return' => 'stdout', 'exit_error' => false )
);

if ( ! is_string( $plan ) || false === strpos( $plan, '--yes' ) ) {
	echo "FAIL: 'wp super-user abolish' did not print a plan (expected the --yes hint).\n";
	exit( 1 );
}

if ( ! get_role( 'super_user' ) ) {
	echo "FAIL: plan mode must not abolish anything.\n";
	exit( 1 );
}

echo "OK: 'wp super-user abolish' plans without changing anything\n";