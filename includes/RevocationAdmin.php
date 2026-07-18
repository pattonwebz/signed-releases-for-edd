<?php
/**
 * Admin page for publishing the key-revocation manifest.
 *
 * Deliberately a direct two-file-upload form rather than a media-library
 * workflow or paste fields: the manifest JSON must reach clients byte-exact
 * (the root signature covers the exact bytes) and textareas cannot deliver
 * that — browsers normalize textarea submissions to CRLF line endings and
 * trailing whitespace is easy to lose, either of which silently breaks
 * client-side verification of a perfectly good manifest. File uploads
 * preserve the signed bytes exactly. The files are read straight from the
 * request and stored in an option — the media library is never involved, so
 * no upload-mime changes are needed for .json.
 *
 * Capability: manage_options, deliberately stricter than the edit_products
 * gate used elsewhere. A manifest influences update trust for every product
 * and every customer site at once; product editors have no business here.
 *
 * @package PattonWebz\SignedReleasesForEDD
 */

namespace PattonWebz\SignedReleasesForEDD;

defined( 'ABSPATH' ) || exit;

/**
 * The "Key Revocation" submenu page under Downloads.
 */
class RevocationAdmin {

	const NONCE      = 'srfe_save_revocation_manifest';
	const PAGE_SLUG  = 'srfe-revocation';
	const CAPABILITY = 'manage_options';

	/**
	 * Revocation-manifest storage.
	 *
	 * @var RevocationStore
	 */
	private $revocations;

	/**
	 * Constructor.
	 *
	 * @param RevocationStore $revocations Revocation-manifest storage.
	 */
	public function __construct( RevocationStore $revocations ) {
		$this->revocations = $revocations;
	}

	/**
	 * Attach the admin hooks.
	 */
	public function hook() {
		add_action( 'admin_menu', [ $this, 'register_page' ] );
		add_action( 'admin_post_srfe_save_revocation_manifest', [ $this, 'handle_save' ] );
	}

	/**
	 * Register the submenu page under Downloads.
	 */
	public function register_page() {
		add_submenu_page(
			'edit.php?post_type=download',
			esc_html__( 'Key Revocation', 'signed-releases-for-edd' ),
			esc_html__( 'Key Revocation', 'signed-releases-for-edd' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			[ $this, 'render_page' ]
		);
	}

	/**
	 * Render the page: current manifest status, then the publish form.
	 */
	public function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		echo '<div class="wrap"><h1>' . esc_html__( 'Key Revocation', 'signed-releases-for-edd' ) . '</h1>';

		$this->render_result_notice();

		$stored = $this->revocations->get();

		if ( null === $stored ) {
			echo '<p>' . esc_html__( 'No revocation manifest is published. Clients trust every pinned key.', 'signed-releases-for-edd' ) . '</p>';
		} else {
			printf(
				'<p>%s</p>',
				esc_html(
					sprintf(
						/* translators: 1: manifest sequence number, 2: number of revoked keys, 3: date */
						__( 'Serving manifest sequence %1$d (%2$d revoked key(s)), published %3$s.', 'signed-releases-for-edd' ),
						$stored['sequence'],
						$this->revocations->revoked_count(),
						gmdate( 'Y-m-d H:i \U\T\C', $stored['updated'] )
					)
				)
			);
		}

		printf(
			'<p>%s</p>',
			esc_html__( 'Author the manifest offline, sign it with the cold-stored revocation root key, then upload both files exactly as produced — the signature covers the exact manifest bytes, which is why this is a file upload and not a paste field. Clients verify against their pinned root key and only ever ratchet forward: a sequence at or below the published one is refused.', 'signed-releases-for-edd' )
		);

		printf(
			'<form method="post" action="%s" enctype="multipart/form-data">',
			esc_url( admin_url( 'admin-post.php' ) )
		);
		echo '<input type="hidden" name="action" value="srfe_save_revocation_manifest" />';
		wp_nonce_field( self::NONCE );

		printf(
			'<p><label for="srfe-rev-manifest"><strong>%s</strong></label><br /><input type="file" id="srfe-rev-manifest" name="srfe_rev_manifest" accept=".json,application/json" /></p>',
			esc_html__( 'Manifest file (revocation.json, exactly as signed)', 'signed-releases-for-edd' )
		);
		printf(
			'<p><label for="srfe-rev-minisig"><strong>%s</strong></label><br /><input type="file" id="srfe-rev-minisig" name="srfe_rev_minisig" accept=".minisig" /></p>',
			esc_html__( 'Signature file (revocation.json.minisig)', 'signed-releases-for-edd' )
		);
		printf(
			'<p><button type="submit" class="button button-primary">%s</button></p>',
			esc_html__( 'Publish revocation manifest', 'signed-releases-for-edd' )
		);
		echo '</form></div>';
	}

	/** Outcome code for a submission missing one or both files. */
	const ERR_MISSING_UPLOAD = 'missing_upload';

	/** Neither artifact has any business being bigger than this. */
	const MAX_UPLOAD_BYTES = 16384;

	/**
	 * Handle the form submission and redirect back with the outcome.
	 */
	public function handle_save() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Permission denied.', 'signed-releases-for-edd' ) );
		}

		check_admin_referer( self::NONCE );

		// Read the uploaded files' bytes directly — no sanitizer, no trim, no
		// normalization of any kind: the root signature covers the manifest's
		// exact bytes, and any mutation here silently breaks verification on
		// every client while this page reports success. (This is also why
		// these are file uploads, not textareas — textarea submissions CRLF-
		// normalize line endings.) Both are structurally validated by
		// RevocationStore::save() before persisting and output-escaped
		// wherever rendered.
		$manifest = $this->uploaded_bytes( 'srfe_rev_manifest' );
		$minisig  = $this->uploaded_bytes( 'srfe_rev_minisig' );

		if ( null === $manifest || null === $minisig ) {
			$this->redirect_with_result( self::ERR_MISSING_UPLOAD );
		}

		$result = $this->revocations->save( $manifest, $minisig );

		$this->redirect_with_result( $result );
	}

	/**
	 * Raw bytes of an uploaded file, or null when absent/oversized/invalid.
	 *
	 * @param string $field The $_FILES field name.
	 *
	 * @return string|null
	 */
	private function uploaded_bytes( $field ) {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput, WordPress.Security.NonceVerification.Missing -- raw byte handling by design and the sole caller (handle_save) has already passed check_admin_referer(); tmp_name is server-generated and gated by is_uploaded_file().
		$file = isset( $_FILES[ $field ] ) && is_array( $_FILES[ $field ] ) ? $_FILES[ $field ] : null;

		if ( null === $file
			|| ! isset( $file['tmp_name'], $file['error'], $file['size'] )
			|| UPLOAD_ERR_OK !== $file['error']
			|| $file['size'] > self::MAX_UPLOAD_BYTES
			|| ! is_uploaded_file( $file['tmp_name'] ) ) {
			return null;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- reading the request's own size-capped upload tmp file; WP_Filesystem adds nothing here.
		$contents = file_get_contents( $file['tmp_name'] );

		return is_string( $contents ) && '' !== $contents ? $contents : null;
	}

	/**
	 * Redirect back to the page carrying a save outcome, and end the request.
	 *
	 * @param string $result A RevocationStore save outcome or ERR_MISSING_UPLOAD.
	 */
	private function redirect_with_result( $result ) {
		wp_safe_redirect(
			add_query_arg(
				[
					'post_type'       => 'download',
					'page'            => self::PAGE_SLUG,
					'srfe_rev_result' => rawurlencode( $result ),
				],
				admin_url( 'edit.php' )
			)
		);
		exit;
	}

	/**
	 * Render the outcome notice after a save redirect.
	 */
	private function render_result_notice() {
		if ( ! isset( $_GET['srfe_rev_result'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- feedback display only, no state change.
			return;
		}

		$result  = sanitize_key( wp_unslash( $_GET['srfe_rev_result'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- as above.
		$message = $this->result_message( $result );

		printf(
			'<div class="notice %s"><p>%s</p></div>',
			RevocationStore::SAVED === $result ? 'notice-success' : 'notice-error',
			esc_html( $message )
		);
	}

	/**
	 * Human-readable explanation for a save outcome.
	 *
	 * @param string $result A RevocationStore save outcome.
	 *
	 * @return string
	 */
	private function result_message( $result ) {
		switch ( $result ) {
			case RevocationStore::SAVED:
				return __( 'Revocation manifest published. Clients pick it up on their next update check.', 'signed-releases-for-edd' );

			case self::ERR_MISSING_UPLOAD:
				return __( 'Not published: both files are required (each under 16 KB) — the manifest JSON and its .minisig.', 'signed-releases-for-edd' );

			case RevocationStore::ERR_BAD_ENCODING:
				return __( 'Not published: one of the uploaded files is not valid UTF-8. Upload the exact files minisign produced, without re-encoding them.', 'signed-releases-for-edd' );

			case RevocationStore::ERR_BAD_MINISIG:
				return __( 'Not published: the signature is not structurally valid .minisig text. Upload the full 4-line file produced by minisign.', 'signed-releases-for-edd' );

			case RevocationStore::ERR_BAD_JSON:
				return __( 'Not published: the manifest is not valid JSON.', 'signed-releases-for-edd' );

			case RevocationStore::ERR_BAD_FORMAT:
				return __( 'Not published: the manifest format tag is missing or unrecognised (expected pattonwebz-revocation-v1).', 'signed-releases-for-edd' );

			case RevocationStore::ERR_BAD_SEQUENCE:
				return __( 'Not published: the manifest needs an integer sequence >= 1 and a revoked_keys list.', 'signed-releases-for-edd' );

			case RevocationStore::ERR_SEQUENCE_REPLAY:
				return __( 'Not published: the sequence is not higher than the manifest already being served. Re-issue with a higher sequence — clients ratchet forward and would ignore this one.', 'signed-releases-for-edd' );

			default:
				return __( 'Unknown result.', 'signed-releases-for-edd' );
		}
	}
}
