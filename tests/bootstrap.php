<?php
/**
 * PHPUnit bootstrap: a filesystem sandbox standing in for wp-content, the
 * WordPress/EDD shims, then the plugin classes under test.
 */

declare(strict_types=1);

define( 'OBJECT', 'OBJECT' );

// A real directory tree the containment logic can realpath() into.
$sandbox = sys_get_temp_dir() . '/srfe-tests-' . getmypid();

if ( ! is_dir( $sandbox . '/uploads/edd' ) ) {
	mkdir( $sandbox . '/uploads/edd', 0700, true );
}

define( 'SRFE_TEST_SANDBOX', $sandbox );
define( 'WP_CONTENT_DIR', $sandbox );
define( 'ABSPATH', $sandbox . '/' );

$GLOBALS['__wp_uploads_basedir'] = $sandbox . '/uploads';

require __DIR__ . '/wp-shims.php';

srfe_shims_reset();

require dirname( __DIR__ ) . '/includes/SignatureStore.php';
require dirname( __DIR__ ) . '/includes/RevocationStore.php';
require dirname( __DIR__ ) . '/includes/Api.php';
require dirname( __DIR__ ) . '/includes/Admin.php';
require dirname( __DIR__ ) . '/includes/RevocationAdmin.php';

register_shutdown_function(
	static function () use ( $sandbox ): void {
		exec( 'rm -rf ' . escapeshellarg( $sandbox ) );
	}
);
