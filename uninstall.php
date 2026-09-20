<?php
/**
 * Uninstall routine.
 *
 * Removes the options, download metadata, per-user notices and scheduled
 * events this plugin created. Easy Digital Downloads' own data — downloads,
 * licences, orders and `_edd_sl_version` — is deliberately left untouched.
 *
 * @package EDD_Signed_Releases
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Delete this plugin's data for the current site.
 *
 * @return void
 */
function srfe_uninstall_site() {
	// Options created by the plugin.
	delete_option( 'srfe_plugin_slug_index' );
	delete_option( 'srfe_revocation_manifest' );

	// Per-download metadata created by the plugin.
	delete_post_meta_by_key( '_srfe_signature_archive' );
	delete_post_meta_by_key( '_srfe_signature_status' );
	delete_post_meta_by_key( '_srfe_plugin_slug' );
	delete_post_meta_by_key( '_srfe_refresh_retry_count' );

	// Per-user status notices, for every user.
	delete_metadata( 'user', 0, '_srfe_admin_notice', '', true );

	// Signature-refresh events pending per download.
	wp_clear_scheduled_hook( 'srfe_refresh_signature' );
}

if ( is_multisite() ) {
	$srfe_site_ids = get_sites( [ 'fields' => 'ids' ] );

	foreach ( $srfe_site_ids as $srfe_site_id ) {
		switch_to_blog( (int) $srfe_site_id );
		srfe_uninstall_site();
		restore_current_blog();
	}

	unset( $srfe_site_ids, $srfe_site_id );
} else {
	srfe_uninstall_site();
}
