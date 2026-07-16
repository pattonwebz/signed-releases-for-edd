<?php
/**
 * Plugin Name: Signed Releases for EDD
 * Plugin URI:  https://github.com/pattonwebz/signed-releases-for-edd
 * Description: Serves minisign signatures for EDD download files: injects them into the Software Licensing get_version API response and exposes a public signature endpoint for manual verification.
 * Version:     0.1.0
 * Author:      William Patton
 * Author URI:  https://www.pattonwebz.com
 * License:     GPL-2.0-or-later
 * Text Domain: signed-releases-for-edd
 * Requires PHP: 7.4
 *
 * Signatures are produced in CI by signing each release zip with minisign;
 * the resulting <file>.minisig is uploaded next to the zip. This plugin only
 * ever handles public material — the signing key never touches the store.
 *
 * @package EDD_Signed_Releases
 */

defined( 'ABSPATH' ) || exit;

define( 'PATTONWEBZ_SRFE_VERSION', '0.1.0' );
define( 'PATTONWEBZ_SRFE_DIR', plugin_dir_path( __FILE__ ) );

require_once PATTONWEBZ_SRFE_DIR . 'includes/SignatureStore.php';
require_once PATTONWEBZ_SRFE_DIR . 'includes/Api.php';
require_once PATTONWEBZ_SRFE_DIR . 'includes/Admin.php';

add_action(
	'plugins_loaded',
	static function () {
		load_plugin_textdomain( 'signed-releases-for-edd', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

		if ( ! function_exists( 'edd_get_download_files' ) ) {
			add_action(
				'admin_notices',
				static function () {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'Signed Releases for EDD requires Easy Digital Downloads.', 'signed-releases-for-edd' ) . '</p></div>';
				}
			);

			return;
		}

		$store = new PattonWebz\SignedReleasesForEDD\SignatureStore();

		( new PattonWebz\SignedReleasesForEDD\Api( $store ) )->hook();
		( new PattonWebz\SignedReleasesForEDD\Admin( $store ) )->hook();
	}
);
