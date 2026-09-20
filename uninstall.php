<?php
/**
 * Uninstall routine.
 *
 * Policy, and why it is not simply "delete everything this plugin touched":
 *
 * Removed on uninstall (small, and the plugin regenerates all of it):
 *   - the slug index option, rebuilt by scanning downloads
 *   - the per-download signature *status* and retry-counter meta
 *   - per-user problem notices
 *   - pending signature-refresh cron events
 *
 * Kept by default:
 *   - the revocation manifest option, because its sequence number ratchets
 *     forward on every client that has seen it. A store that loses this
 *     value cannot publish a revocation that those clients will accept, so
 *     deleting it can silently disable a security control rather than tidy
 *     up after one.
 *   - the archived signatures, which are the store's serving copy for each
 *     version and are only recoverable while the original .minisig files are
 *     still where they were uploaded.
 *   - the plugin slug entered on each download, which is configuration a
 *     human typed rather than something the plugin derives.
 *
 * Set SRFE_UNINSTALL_REMOVE_STORE_DATA to true (in wp-config.php or a
 * must-use plugin) to remove those three as well. Only do that if you hold
 * the signed manifest offline and can re-publish it with a higher sequence,
 * or if no site is verifying against this store yet.
 *
 * Easy Digital Downloads' own data — downloads, licences, orders and
 * `_edd_sl_version` — is never touched either way.
 *
 * @package EDD_Signed_Releases
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Delete this plugin's regenerable data for the current site.
 *
 * @return void
 */
function srfe_uninstall_site() {
	// Derived index; rebuilt from the downloads on next use.
	delete_option( 'srfe_plugin_slug_index' );

	// Bookkeeping that the next discovery pass rewrites.
	delete_post_meta_by_key( '_srfe_signature_status' );
	delete_post_meta_by_key( '_srfe_refresh_retry_count' );

	// Per-user problem notices, for every user.
	delete_metadata( 'user', 0, '_srfe_admin_notice', '', true );

	// Signature-refresh events, scheduled per download with the download ID as
	// an argument. wp_clear_scheduled_hook() only matches the exact argument
	// list it is given (the args are hashed into the event's key), so with no
	// arguments it would clear nothing here; wp_unschedule_hook() clears every
	// event for the hook regardless of arguments.
	wp_unschedule_hook( 'srfe_refresh_signature' );

	if ( defined( 'SRFE_UNINSTALL_REMOVE_STORE_DATA' ) && SRFE_UNINSTALL_REMOVE_STORE_DATA ) {
		// Published revocation state: deleting this strands clients that have
		// already ratcheted past the sequence stored here.
		delete_option( 'srfe_revocation_manifest' );

		// Archived signatures: the serving copy for each version.
		delete_post_meta_by_key( '_srfe_signature_archive' );

		// Manually entered per-download configuration.
		delete_post_meta_by_key( '_srfe_plugin_slug' );
	}
}

if ( is_multisite() ) {
	// 'number' => 0 removes the LIMIT clause; WP_Site_Query's default of 100
	// would silently skip every site after the first 100 on a large network.
	$srfe_site_ids = get_sites(
		[
			'fields' => 'ids',
			'number' => 0,
		]
	);

	foreach ( $srfe_site_ids as $srfe_site_id ) {
		switch_to_blog( (int) $srfe_site_id );
		srfe_uninstall_site();
		restore_current_blog();
	}

	unset( $srfe_site_ids, $srfe_site_id );
} else {
	srfe_uninstall_site();
}
