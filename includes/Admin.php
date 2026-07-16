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
	const NOTICE_META = '_srfe_admin_notice'; // Per-user meta, not a global transient.
	const NONCE       = 'srfe_check_now';

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
		add_action( 'add_meta_boxes', array( $this, 'register_metabox' ) );
		add_action( 'save_post_download', array( $this, 'on_download_save' ), 20 );
		add_action( self::CRON_HOOK, array( $this, 'run_refresh' ) );
		add_action( 'admin_post_srfe_check_now', array( $this, 'handle_check_now' ) );
		add_action( 'admin_notices', array( $this, 'render_notice' ) );
		add_filter( 'upload_mimes', array( $this, 'allow_minisig_upload' ) );
		add_filter( 'wp_check_filetype_and_ext', array( $this, 'check_minisig_filetype' ), 10, 3 );
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
			'Release Signatures',
			array( $this, 'render_metabox' ),
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
		$version = $this->store->current_version( $post->ID );

		if ( '' === $version ) {
			echo '<p>Software Licensing version not set; nothing to sign.</p>';

			return;
		}

		$minisig = $this->store->get_signature( $post->ID, $version );

		if ( null !== $minisig ) {
			printf(
				'<p style="color:#008a20;">&#10003; v%s signed (key <code>%s</code>)</p>',
				esc_html( $version ),
				esc_html( (string) $this->store->key_id( $minisig ) )
			);
		} else {
			echo '<p style="color:#d63638;">&#10007; ' . esc_html( $this->status_message( $this->store->status( $post->ID ), $version ) ) . '</p>';
		}

		$archived = array_keys( $this->store->archive( $post->ID ) );

		if ( ! empty( $archived ) ) {
			printf(
				'<p>Archived signatures: %s</p>',
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

		if ( '' === $this->store->current_version( $post_id ) ) {
			return;
		}

		if ( $this->store->resolves_locally( $post_id ) ) {
			$this->refresh_and_notify( (int) $post_id );

			return;
		}

		if ( ! wp_next_scheduled( self::CRON_HOOK, array( $post_id ) ) ) {
			wp_schedule_single_event( time() + 5, self::CRON_HOOK, array( $post_id ) );
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
			return;
		}

		$author = (int) get_post_field( 'post_author', $post_id );

		if ( $author > 0 ) {
			update_user_meta(
				$author,
				self::NOTICE_META,
				array(
					'download_id' => $post_id,
					'version'     => $version,
					'status'      => $status,
				)
			);
		}
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
		$notice  = get_user_meta( $user_id, self::NOTICE_META, true );

		if ( ! is_array( $notice ) ) {
			return;
		}

		delete_user_meta( $user_id, self::NOTICE_META );

		printf(
			'<div class="notice notice-warning is-dismissible"><p><strong>%s</strong> %s</p></div>',
			esc_html__( 'Release signature problem:', 'signed-releases-for-edd' ),
			esc_html( $this->status_message( $notice['status'], (string) $notice['version'], get_the_title( $notice['download_id'] ) ) )
		);
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
			case SignatureStore::STATUS_AMBIGUOUS:
				return sprintf( '%sv%s has several files with different signatures; the correct one cannot be determined automatically. Split the packages into separate downloads, or sign a single distributable file.', $who, $version );

			case SignatureStore::STATUS_OFFSITE:
				return sprintf( '%sv%s is stored offsite and its .minisig could not be fetched. Upload the signature next to the file at a publicly reachable URL (same path + ".minisig").', $who, $version );

			default:
				return sprintf( '%sv%s has no .minisig signature. Upload the one produced by the release workflow next to the file.', $who, $version );
		}
	}

	/** Internal marker for "no problem to report". */
	const STATUS_OK = 'ok';

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
