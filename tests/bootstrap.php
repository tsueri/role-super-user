<?php
/**
 * PHPUnit bootstrap for the Super-User Role test suite.
 *
 * Requires the mu-plugin at the same point of the boot where real mu-plugins
 * load (muplugins_loaded), then hands off to the WordPress core test suite
 * provided by the wp-env environment (WP_TESTS_DIR).
 *
 * Run through wp-env: npm test (single site) / npm run test:multisite.
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	echo "WP_TESTS_DIR is not set — run the suite through wp-env: npm test\n";
	exit( 1 );
}

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	echo "Could not find {$_tests_dir}/includes/functions.php\n";
	exit( 1 );
}

// Give access to tests_add_filter().
require_once $_tests_dir . '/includes/functions.php';

/*
 * Use this repo's wp-tests-config instead of the wp-env-provided one. The
 * wp-env default defines MULTISITE (and DOMAIN_CURRENT_SITE) up front in its
 * multisite environments, which makes install.php's populate_network() skip
 * the main site's wp_blogs row and breaks every multisite test boot. See
 * tests/wp-tests-config.php for the details.
 */
if ( ! defined( 'WP_TESTS_CONFIG_FILE_PATH' ) ) {
	define( 'WP_TESTS_CONFIG_FILE_PATH', __DIR__ . '/wp-tests-config.php' );
}

/*
 * The WP core test suite requires the Yoast PHPUnit Polyfills; they arrive via
 * Composer (see composer.json). Point the core bootstrap at the installed copy.
 */
if ( ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills' );
}

/*
 * Load the plugin where real mu-plugins load during the boot. In the wp-env
 * environment the repo is also mapped into wp-content/mu-plugins/ itself, so
 * the file has already loaded as a real mu-plugin and this require_once is a
 * no-op safety net for bare WP_TESTS_DIR setups.
 */
tests_add_filter(
	'muplugins_loaded',
	static function () {
		require_once dirname( __DIR__ ) . '/role-super-user.php';
	}
);

// Start up the WordPress testing environment.
require_once $_tests_dir . '/includes/bootstrap.php';