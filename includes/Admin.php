<?php
/**
 * Store-side visibility and guard rails:
 *
 *  - A metabox on the download edit screen showing signature status, the
 *    archived versions, and a "check now" button.
 *  - Discovery runs inline on save when every file resolves to disk (cheap,
 *    no network); a save with a genuinely offsite file schedules a one-off
 *    event instead, so a slow fetch never blocks the editor, block-editor
 *    REST saves, quick-edit, or programmatic imports. A manual "check now"
 *    always runs inline on explicit admin request.
 *  - A per-user notice warns when a versioned release has no signature (or an
 *    ambiguous / offsite-unreachable one).
 *  - A late .minisig uploaded straight to the media library (after the
 *    download was already saved) re-triggers discovery for its parent
 *    download, and a NONE/OFFSITE result reschedules its own retry instead
 *    of waiting for the next save or a manual "check now".
 *
 * @package PattonWebz\SignedReleasesForEDD
 */

namespace PattonWebz\SignedReleasesForEDD;

use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Admin UI: signature status metabox, off-request discovery, upload support.
 */
class Admin {

	const CRON_HOOK   = 'srfe_refresh_signature';
	const NOTICE_META = '_srfe_admin_notice'; // Per-user meta: array of notices keyed by download ID, not a single value.
	const NONCE       = 'srfe_check_now';
	const RETRY_META  = '_srfe_refresh_retry_count';

	/** Initial delay before the first automatic retry of a failed check. */
	const RETRY_BASE_DELAY = 300; // 5 minutes.

	/** Retry delay ceiling: a NONE/OFFSITE download still gets rechecked, just slowly. */
	const RETRY_MAX_DELAY = 21600; // 6 hours.

	/**
	 * Signature archive access.
	 *
	 * @var SignatureStore
	 */
	private $store;

	/**
	 * Constructor.
	 *
	 * @param SignatureStore $store Signature archive access.
	 */
	public function __construct( SignatureStore $store ) {
		$this->store = $store;
	}

	/**
	 * Attach all admin hooks.
	 */
	public function hook() {
		add_action( 'add_meta_boxes', [ $this, 'register_metabox' ] );
		add_action( 'save_post_download', [ $this, 'on_download_save' ], 20 );
		add_action( self::CRON_HOOK, [ $this, 'run_refresh' ] );
		add_action( 'admin_post_srfe_check_now', [ $this, 'handle_check_now' ] );
		add_action( 'admin_notices', [ $this, 'render_notice' ] );
		add_action( 'add_attachment', [ $this, 'on_minisig_attachment_saved' ] );
		add_action( 'edit_attachment', [ $this, 'on_minisig_attachment_saved' ] );
		// phpcs:ignore WordPressVIPMinimum.Hooks.RestrictedHooks.upload_mimes -- allow_minisig_upload() only ever adds the `minisig` extension as text/plain, gated on edit_products/manage_options; no SVG/executable types are added.
		add_filter( 'upload_mimes', [ $this, 'allow_minisig_upload' ] );
		add_filter( 'wp_check_filetype_and_ext', [ $this, 'check_minisig_filetype' ], 10, 3 );
	}

	/**
	 * A .minisig uploaded straight to the media library (the documented
	 * "upload the signature next to the file" workflow) never fires
	 * save_post_download, so without this hook discovery would only pick it
	 * up at the next product save or a manual "check now". If the attachment
	 * is parented to a download — the normal case when it's uploaded from
	 * that download's edit screen — re-run discovery for it.
	 *
	 * @param int $attachment_id Attachment post ID.
	 */
	public function on_minisig_attachment_saved( $attachment_id ) {
		$file = get_attached_file( $attachment_id );

		if ( ! is_string( $file ) || '.minisig' !== substr( strtolower( $file ), -8 ) ) {
			return;
		}

		$download_id = (int) get_post_field( 'post_parent', $attachment_id );

		if ( $download_id <= 0 || 'download' !== get_post_type( $download_id ) ) {
			return;
		}

		$this->on_download_save( $download_id );
	}

	/**
	 * Let media-library uploads accept .minisig files, which is how release
	 * signatures get placed next to their zips. WordPress rejects unknown
	 * file types outright, so without this the documented workflow ("upload
	 * the signature next to the file") fails at the first step.
	 *
	 * Signatures are public material (useless without the matching zip and
	 * only verifiable against the published key), but there is no reason for
	 * non-shop users to upload them, so gate on the EDD product capability.
	 *
	 * @param array<string, string> $mimes Allowed extension => mime map.
	 *
	 * @return array<string, string>
	 */
	public function allow_minisig_upload( $mimes ) {
		if ( current_user_can( 'edit_products' ) || current_user_can( 'manage_options' ) ) {
			$mimes['minisig'] = 'text/plain';
		}

		return $mimes;
	}

	/**
	 * Back up the upload_mimes entry for hosts with strict real-content
	 * checking: fileinfo has no rule for .minisig, so tell WordPress what a
	 * file with this extension is instead of letting the check fall through
	 * to a mismatch rejection.
	 *
	 * @param array{ext: string|false, type: string|false, proper_filename: string|false} $types    Values determined so far.
	 * @param string                                                                      $file     Full path to the uploaded temp file.
	 * @param string                                                                      $filename The uploaded file's name.
	 *
	 * @return array{ext: string|false, type: string|false, proper_filename: string|false}
	 */
	public function check_minisig_filetype( $types, $file, $filename ) {
		unset( $file );

		if ( '.minisig' === substr( strtolower( $filename ), -8 )
			&& ( current_user_can( 'edit_products' ) || current_user_can( 'manage_options' ) ) ) {
			$types['ext']  = 'minisig';
			$types['type'] = 'text/plain';
		}

		return $types;
	}

	/**
	 * Register the signature-status metabox on the download edit screen.
	 */
	public function register_metabox() {
		add_meta_box(
			'srfe-signature-status',
			esc_html__( 'Release Signatures', 'signed-releases-for-edd' ),
			[ $this, 'render_metabox' ],
			'download',
			'side',
			'default'
		);
	}

	/**
	 * Render the signature-status metabox.
	 *
	 * @param WP_Post $post The download being edited.
	 */
	public function render_metabox( $post ) {
		$this->render_plugin_slug_field( $post );

		$version = $this->store->current_version( $post->ID );

		if ( '' === $version ) {
			echo '<p>' . esc_html__( 'Software Licensing version not set; nothing to sign.', 'signed-releases-for-edd' ) . '</p>';

			return;
		}

		$minisig = $this->store->get_signature( $post->ID, $version );

		if ( null !== $minisig ) {
			printf(
				/* translators: 1: version number, 2: signing key ID */
				'<p style="color:#008a20;">&#10003; ' . esc_html__( 'v%1$s signed (key %2$s)', 'signed-releases-for-edd' ) . '</p>',
				esc_html( $version ),
				'<code>' . esc_html( (string) $this->store->key_id( $minisig ) ) . '</code>'
			);
		} else {
			echo '<p style="color:#d63638;">&#10007; ' . esc_html( $this->status_message( $this->store->status( $post->ID ), $version ) ) . '</p>';
		}

		$archived = array_keys( $this->store->archive( $post->ID ) );

		if ( ! empty( $archived ) ) {
			printf(
				/* translators: %s: comma-separated list of archived version numbers */
				'<p>' . esc_html__( 'Archived signatures: %s', 'signed-releases-for-edd' ) . '</p>',
				esc_html( implode( ', ', array_reverse( $archived ) ) )
			);
		}

		printf(
			'<p><a class="button button-secondary" href="%s">%s</a></p>',
			esc_url(
				wp_nonce_url(
					admin_url( 'admin-post.php?action=srfe_check_now&download_id=' . $post->ID ),
					self::NONCE
				)
			),
			esc_html__( 'Check signature now', 'signed-releases-for-edd' )
		);
	}

	/**
	 * Render the plugin-slug mapping field. The endpoint's slug-only fallback
	 * otherwise resolves against post_name, which the shop admin sets
	 * independently and which frequently doesn't match the signed plugin's
	 * actual slug — this field makes that lookup reliable instead of a
	 * coincidence.
	 *
	 * @param WP_Post $post The download being edited.
	 */
	private function render_plugin_slug_field( $post ) {
		printf(
			'<p><label for="srfe-plugin-slug">%s</label><br /><input type="text" id="srfe-plugin-slug" name="srfe_plugin_slug" value="%s" class="widefat" placeholder="my-plugin-slug" /></p>',
			esc_html__( 'Plugin slug (for update-checker lookups)', 'signed-releases-for-edd' ),
			esc_attr( $this->store->plugin_slug( $post->ID ) )
		);

		wp_nonce_field( 'srfe_plugin_slug_' . $post->ID, 'srfe_plugin_slug_nonce' );
	}

	/**
	 * Save the plugin-slug mapping submitted alongside the metabox, when
	 * present and the nonce for it verifies.
	 *
	 * @param int $post_id The download ID.
	 */
	private function maybe_save_plugin_slug( $post_id ) {
		if ( ! isset( $_POST['srfe_plugin_slug'], $_POST['srfe_plugin_slug_nonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['srfe_plugin_slug_nonce'] ) ), 'srfe_plugin_slug_' . $post_id )
			|| ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$saved = $this->store->set_plugin_slug( $post_id, sanitize_title( wp_unslash( $_POST['srfe_plugin_slug'] ) ) );

		if ( ! $saved ) {
			// The slug is already mapped to another live download; the mapping
			// was refused (it would have re-pointed that product's signature
			// lookups here). Tell the saving user rather than failing silently.
			$this->add_notice( get_current_user_id(), $post_id, self::STATUS_SLUG_CONFLICT );
		}
	}

	/**
	 * On save: run discovery inline when every file resolves to disk (a fast
	 * read, no network), otherwise schedule it. save_post_download fires for
	 * block-editor REST saves, quick-edit, and programmatic wp_update_post()
	 * (imports/migrations) too, so a genuine offsite fetch (up to 15s) must
	 * never run inline here — but deferring the common local-storage case to
	 * a cron event that WP-cron may not run for a while leaves a real window
	 * where an enforce-mode client sees "missing signature" for a release
	 * that is, in fact, already signed on disk. Running inline whenever it's
	 * cheap closes that window instead of just narrowing it.
	 *
	 * @param int $post_id The download ID.
	 */
	public function on_download_save( $post_id ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		$this->maybe_save_plugin_slug( $post_id );

		if ( '' === $this->store->current_version( $post_id ) ) {
			return;
		}

		if ( $this->store->resolves_locally( $post_id ) ) {
			$this->refresh_and_notify( (int) $post_id );

			return;
		}

		if ( ! wp_next_scheduled( self::CRON_HOOK, [ $post_id ] ) ) {
			wp_schedule_single_event( time() + 5, self::CRON_HOOK, [ $post_id ] );
		}
	}

	/**
	 * Scheduled-event callback: discover + archive, then notify the author if
	 * the current version ended up without a usable signature.
	 *
	 * @param int $post_id The download ID.
	 */
	public function run_refresh( $post_id ) {
		$this->refresh_and_notify( (int) $post_id );
	}

	/**
	 * Discover + archive, then notify the download's author if the current
	 * version ended up without a usable signature. Shared by the inline
	 * (local-storage) and scheduled-event (offsite) discovery paths.
	 *
	 * @param int $post_id The download ID.
	 */
	private function refresh_and_notify( $post_id ) {
		$status  = $this->store->refresh( $post_id );
		$version = $this->store->current_version( $post_id );

		if ( self::STATUS_OK === $this->normalise_status( $status ) ) {
			delete_post_meta( $post_id, self::RETRY_META );

			return;
		}

		// NONE/OFFSITE can resolve on their own (a late upload, storage
		// becoming reachable again) — automatically recheck with backoff
		// instead of leaving the miss permanent until the next save or a
		// manual "check now". AMBIGUOUS needs an admin decision (split the
		// download, pick one file); retrying can't fix that, so don't.
		if ( in_array( $status, [ SignatureStore::STATUS_NONE, SignatureStore::STATUS_OFFSITE ], true ) ) {
			$this->schedule_retry( $post_id );
		}

		$author = (int) get_post_field( 'post_author', $post_id );

		if ( $author > 0 ) {
			$this->add_notice( $author, $post_id, $status, $version );
		}
	}

	/**
	 * Queue an admin notice for a user about one download.
	 *
	 * Keyed by download ID: independent products' notices coexist instead of
	 * clobbering each other; a repeat hit on the same download just refreshes
	 * its own entry.
	 *
	 * @param int    $user_id Recipient user ID.
	 * @param int    $post_id The download ID the notice is about.
	 * @param string $status  A STATUS_* value understood by status_message().
	 * @param string $version Version string, when relevant to the message.
	 */
	private function add_notice( $user_id, $post_id, $status, $version = '' ) {
		$notices = get_user_meta( $user_id, self::NOTICE_META, true );

		if ( ! is_array( $notices ) ) {
			$notices = [];
		}

		$notices[ $post_id ] = [
			'download_id' => $post_id,
			'version'     => $version,
			'status'      => $status,
		];

		update_user_meta( $user_id, self::NOTICE_META, $notices );
	}

	/**
	 * Reschedule a discovery recheck with exponential backoff (capped), so a
	 * NONE/OFFSITE result gets retried automatically instead of staying
	 * permanently missed.
	 *
	 * @param int $post_id The download ID.
	 */
	private function schedule_retry( $post_id ) {
		if ( wp_next_scheduled( self::CRON_HOOK, [ $post_id ] ) ) {
			return;
		}

		$attempts = (int) get_post_meta( $post_id, self::RETRY_META, true );
		$delay    = (int) min( self::RETRY_BASE_DELAY * ( 2 ** $attempts ), self::RETRY_MAX_DELAY );

		update_post_meta( $post_id, self::RETRY_META, $attempts + 1 );
		wp_schedule_single_event( time() + $delay, self::CRON_HOOK, [ $post_id ] );
	}

	/**
	 * Handle the metabox "check signature now" button: run discovery inline
	 * for one download on explicit admin request (blocking here is fine — it
	 * is a deliberate action, not a passive page load or save).
	 */
	public function handle_check_now() {
		$download_id = isset( $_GET['download_id'] ) ? absint( $_GET['download_id'] ) : 0;

		if ( ! $download_id
			|| ! current_user_can( 'edit_post', $download_id )
			|| ! check_admin_referer( self::NONCE ) ) {
			wp_die( esc_html__( 'Permission denied.', 'signed-releases-for-edd' ) );
		}

		$this->store->refresh( $download_id );

		wp_safe_redirect( get_edit_post_link( $download_id, 'redirect' ) );
		exit;
	}

	/**
	 * Print (and clear) the current user's pending signature-problem notice.
	 */
	public function render_notice() {
		if ( ! current_user_can( 'edit_products' ) ) {
			return;
		}

		$user_id = get_current_user_id();
		$notices = get_user_meta( $user_id, self::NOTICE_META, true );

		if ( ! is_array( $notices ) || [] === $notices ) {
			return;
		}

		delete_user_meta( $user_id, self::NOTICE_META );

		foreach ( $notices as $notice ) {
			if ( ! is_array( $notice ) ) {
				continue;
			}

			printf(
				'<div class="notice notice-warning is-dismissible"><p><strong>%s</strong> %s</p></div>',
				esc_html__( 'Release signature problem:', 'signed-releases-for-edd' ),
				esc_html( $this->status_message( $notice['status'], (string) $notice['version'], get_the_title( $notice['download_id'] ) ) )
			);
		}
	}

	/**
	 * Human-readable explanation for a discovery status.
	 *
	 * @param string $status  A STATUS_* value.
	 * @param string $version Version string.
	 * @param string $title   Optional product title for the admin notice.
	 *
	 * @return string
	 */
	private function status_message( $status, $version, $title = '' ) {
		$who = '' !== $title ? $title . ' ' : '';

		switch ( $status ) {
			case self::STATUS_SLUG_CONFLICT:
				return sprintf(
					/* translators: %s: product title (may be empty) */
					__( '%splugin slug not saved: it is already mapped to another download. Each plugin slug can only point to one product — clear it there first if this is intentional.', 'signed-releases-for-edd' ),
					$who
				);

			case SignatureStore::STATUS_AMBIGUOUS:
				return sprintf(
					/* translators: 1: product title (may be empty), 2: version number */
					__( '%1$sv%2$s has several files with different signatures; the correct one cannot be determined automatically. Split the packages into separate downloads, or sign a single distributable file.', 'signed-releases-for-edd' ),
					$who,
					$version
				);

			case SignatureStore::STATUS_OFFSITE:
				return sprintf(
					/* translators: 1: product title (may be empty), 2: version number */
					__( '%1$sv%2$s is stored offsite and its .minisig could not be fetched. Upload the signature next to the file at a publicly reachable URL (same path + ".minisig").', 'signed-releases-for-edd' ),
					$who,
					$version
				);

			default:
				return sprintf(
					/* translators: 1: product title (may be empty), 2: version number */
					__( '%1$sv%2$s has no .minisig signature. Upload the one produced by the release workflow next to the file.', 'signed-releases-for-edd' ),
					$who,
					$version
				);
		}
	}

	/** Internal marker for "no problem to report". */
	const STATUS_OK = 'ok';

	/** Notice status: a plugin-slug mapping was refused as already claimed. */
	const STATUS_SLUG_CONFLICT = 'slug_conflict';

	/**
	 * Collapse "found" to the internal OK marker; problems pass through.
	 *
	 * @param string $status A STATUS_* value from discovery.
	 *
	 * @return string
	 */
	private function normalise_status( $status ) {
		return SignatureStore::STATUS_FOUND === $status ? self::STATUS_OK : $status;
	}
}
