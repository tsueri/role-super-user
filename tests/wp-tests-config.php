<?php
/**
 * wp-tests-config.php for the Super-User Role test suite.
 *
 * Used instead of the wp-env-provided default (see WP_TESTS_CONFIG_FILE_PATH in
 * tests/bootstrap.php). Same shape as the default config, with two deliberate
 * differences:
 *
 * - The multisite constants (MULTISITE, SUBDOMAIN_INSTALL, DOMAIN_CURRENT_SITE)
 *   are NOT defined here. The WP core test bootstrap defines them itself, after
 *   install.php has run — and install.php needs MULTISITE undefined so that
 *   populate_network() inserts the main site's wp_blogs row. wp-env's default
 *   config defines MULTISITE up front for its multisite environments, which
 *   silently skips that insert and breaks every subsequent test boot.
 * - WP_TESTS_DOMAIN is 'localhost' without a port: the test rig never serves
 *   web requests, and install and boot must look the site up under the same
 *   name regardless of which wp-env environment's ports are in play.
 *
 * Database credentials resolve from the container environment (WORDPRESS_DB_*),
 * so the same file works in any wp-env environment.
 */

// a helper function to lookup "env_FILE", "env", then fallback
if ( ! function_exists( 'getenv_docker' ) ) {
	function getenv_docker( $env, $default ) {
		if ( $file_env = getenv( $env . '_FILE' ) ) {
			return rtrim( file_get_contents( $file_env ), "\r\n" );
		} elseif ( ( $val = getenv( $env ) ) !== false ) {
			return $val;
		} else {
			return $default;
		}
	}
}

// ** Database settings ** //
// DB_NAME is a dedicated test database (created by the wp-env afterStart
// lifecycle script), so the PHPUnit test install never clobbers the
// environment's own WordPress database — on multisite the test install drops
// and rebuilds the ms_global tables, which would otherwise break the site.
define( 'DB_NAME', getenv_docker( 'WP_TESTS_DB_NAME', 'wordpress_test' ) );
define( 'DB_USER', getenv_docker( 'WORDPRESS_DB_USER', 'root' ) );
define( 'DB_PASSWORD', getenv_docker( 'WORDPRESS_DB_PASSWORD', 'password' ) );
define( 'DB_HOST', getenv_docker( 'WORDPRESS_DB_HOST', 'mysql' ) );
define( 'DB_CHARSET', getenv_docker( 'WORDPRESS_DB_CHARSET', 'utf8mb4' ) );
define( 'DB_COLLATE', getenv_docker( 'WORDPRESS_DB_COLLATE', '' ) );

/** The database table prefix. */
$table_prefix = getenv_docker( 'WORDPRESS_TABLE_PREFIX', 'wp_' );

/** Test rig settings. */
define( 'FS_METHOD', 'direct' );
define( 'SCRIPT_DEBUG', true );
define( 'WP_ENVIRONMENT_TYPE', 'local' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Test Blog' );
define( 'WP_TESTS_DOMAIN', 'localhost' );
define( 'WP_SITEURL', 'http://localhost' );
define( 'WP_HOME', 'http://localhost' );
define( 'WP_DEBUG', true );

/** Absolute path to the WordPress directory. */
if ( ! defined( 'ABSPATH' ) ) {
	// This config lives at <wp-root>/wp-content/mu-plugins/tests/.
	define( 'ABSPATH', dirname( __DIR__, 3 ) . '/' );
	define( 'WP_DEFAULT_THEME', 'default' );
}