<?php
/**
 * Public-facing signature delivery:
 *
 *  1. Injects signature fields into the Software Licensing get_version API
 *     response (the `edd_sl_license_response` filter), so the updater on the
 *     customer site receives the signature alongside the package URL.
 *  2. A public endpoint, ?edd_action=get_release_signature, serving the raw
 *     .minisig for a given item + version — used as the updater's fallback
 *     and for manual verification by customers.
 *
 * Signatures are public material: they are useless without a matching file,
 * so neither path requires authentication.
 *
 * @package PattonWebz\SignedReleasesForEDD
 */

namespace PattonWebz\SignedReleasesForEDD;

use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Signature delivery: SL API response injection and the public endpoint.
 */
class Api {

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
	 * Attach the response filter and the endpoint action.
	 *
	 * Priority 20: Software Licensing's bundled staged-rollouts add-on
	 * rewrites `new_version` on this same filter at priority 11, so we must
	 * run after it to sign the version as finally offered.
	 */
	public function hook() {
		add_filter( 'edd_sl_license_response', array( $this, 'inject_signature' ), 20, 2 );
		add_action( 'edd_get_release_signature', array( $this, 'serve_signature' ) );
	}

	/**
	 * Add signature fields to the get_version response array.
	 *
	 * @param array          $response The API response about to be JSON-encoded.
	 * @param WP_Post|object $download The download: SL >= 3.8 passes its
	 *                                 LicensedProduct model (extends
	 *                                 EDD_Download), older versions a
	 *                                 WP_Post. Only the ID is needed.
	 *
	 * @return array
	 */
	public function inject_signature( $response, $download ) {
		if ( empty( $response['new_version'] ) || ! is_object( $download ) || empty( $download->ID ) ) {
			return $response;
		}

		$minisig = $this->store->get_signature( $download->ID, (string) $response['new_version'] );

		if ( null === $minisig ) {
			return $response;
		}

		$response['signature']        = $minisig;
		$response['signature_format'] = 'minisign';
		$response['signature_key_id'] = $this->store->key_id( $minisig );

		return $response;
	}

	/**
	 * Handle ?edd_action=get_release_signature&item_id=..&version=..
	 * (or slug=.. instead of item_id). Responds with the raw .minisig as
	 * text/plain, 404 when unknown.
	 *
	 * @param array $data Request data from EDD's action router.
	 */
	public function serve_signature( $data ) {
		$item_id = isset( $data['item_id'] ) ? absint( $data['item_id'] ) : 0;
		$slug    = isset( $data['slug'] ) ? sanitize_title( wp_unslash( $data['slug'] ) ) : '';
		$version = isset( $data['version'] ) ? sanitize_text_field( wp_unslash( $data['version'] ) ) : '';

		if ( 0 === $item_id && '' !== $slug ) {
			// Prefer the admin-configured mapping (the signed plugin's actual
			// slug) over guessing from the download's post_name, which is set
			// independently and frequently differs.
			$item_id = $this->store->find_by_plugin_slug( $slug );
		}

		if ( 0 === $item_id && '' !== $slug ) {
			$download = get_page_by_path( $slug, OBJECT, 'download' );
			$item_id  = $download instanceof WP_Post ? $download->ID : 0;
		}

		// One 404 message for both "no such item" and "no signature" so the
		// endpoint can't be used to enumerate which item IDs are real.
		if ( 0 === $item_id || 'download' !== get_post_type( $item_id ) ) {
			$this->respond( 404, __( 'No signature available.', 'signed-releases-for-edd' ) );
		}

		$minisig = $this->store->get_signature( $item_id, $version );

		if ( null === $minisig ) {
			$this->respond( 404, __( 'No signature available.', 'signed-releases-for-edd' ) );
		}

		nocache_headers();
		status_header( 200 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo $minisig; // phpcs:ignore WordPress.Security.EscapeOutput -- plain-text signature body.
		exit;
	}

	/**
	 * Send a plain-text response and end the request.
	 *
	 * @param int    $code    HTTP status.
	 * @param string $message Plain-text body.
	 */
	private function respond( $code, $message ) {
		nocache_headers();
		status_header( $code );
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo esc_html( $message );
		exit;
	}
}
