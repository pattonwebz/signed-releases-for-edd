<?php
/**
 * Stores and serves the shop's key-revocation manifest.
 *
 * The manifest is authored offline and signed with the revocation root key
 * (which never touches this server — the store only ever handles public
 * material, same as release signatures). An admin pastes the manifest JSON
 * and its .minisig into the Revocation admin page; this class validates the
 * pair structurally and persists it in a single option, from which the
 * public endpoint serves it inside a small JSON envelope.
 *
 * The store is untrusted by design: clients verify the manifest against
 * their own pinned root key, so validation here is operator feedback (catch
 * a mangled paste during an incident), not a security boundary — with one
 * deliberate exception: a sequence lower than the stored one is refused, so
 * an operator cannot accidentally roll the served manifest back to history.
 *
 * @package PattonWebz\SignedReleasesForEDD
 */

namespace PattonWebz\SignedReleasesForEDD;

defined( 'ABSPATH' ) || exit;

/**
 * Revocation-manifest storage, structural validation, and envelope building.
 */
class RevocationStore {

	const OPTION_MANIFEST = 'srfe_revocation_manifest';

	/** The manifest format this store recognises. */
	const FORMAT = 'pattonwebz-revocation-v1';

	/** The envelope format the endpoint serves (mirrored by the client). */
	const ENVELOPE_FORMAT = 'pattonwebz-revocation-envelope-v1';

	/** Save outcomes, used by the admin page for feedback. */
	const SAVED               = 'saved';
	const ERR_BAD_MINISIG     = 'bad_minisig';
	const ERR_BAD_JSON        = 'bad_json';
	const ERR_BAD_FORMAT      = 'bad_format';
	const ERR_BAD_SEQUENCE    = 'bad_sequence';
	const ERR_SEQUENCE_REPLAY = 'sequence_not_higher';
	const ERR_BAD_ENCODING    = 'bad_encoding';

	/**
	 * Structural sanity checks for .minisig text, shared with release
	 * signatures.
	 *
	 * @var SignatureStore
	 */
	private $signatures;

	/**
	 * Constructor.
	 *
	 * @param SignatureStore $signatures Structural .minisig validation.
	 */
	public function __construct( SignatureStore $signatures ) {
		$this->signatures = $signatures;
	}

	/**
	 * The stored manifest, or null when none has been uploaded.
	 *
	 * @return array{manifest: string, minisig: string, sequence: int, updated: int}|null
	 */
	public function get() {
		$stored = get_option( self::OPTION_MANIFEST, null );

		if ( ! is_array( $stored )
			|| ! isset( $stored['manifest'], $stored['minisig'], $stored['sequence'] )
			|| ! is_string( $stored['manifest'] )
			|| ! is_string( $stored['minisig'] )
			|| ! is_int( $stored['sequence'] ) ) {
			return null;
		}

		return [
			'manifest' => $stored['manifest'],
			'minisig'  => $stored['minisig'],
			'sequence' => $stored['sequence'],
			'updated'  => isset( $stored['updated'] ) && is_int( $stored['updated'] ) ? $stored['updated'] : 0,
		];
	}

	/**
	 * The JSON envelope body the public endpoint serves, or null when no
	 * manifest is stored. The manifest travels as the exact string that was
	 * signed — clients verify the root signature over these bytes before
	 * parsing, so re-encoding it here would break verification.
	 *
	 * @return string|null
	 */
	public function envelope() {
		$stored = $this->get();

		if ( null === $stored ) {
			return null;
		}

		$json = wp_json_encode(
			[
				'format'   => self::ENVELOPE_FORMAT,
				'manifest' => $stored['manifest'],
				'minisig'  => $stored['minisig'],
			]
		);

		// wp_json_encode() returns false if the stored bytes are not valid
		// UTF-8. save() rejects such input up front, so this is defence in
		// depth — but return null (→ the endpoint's 404 path) rather than let
		// `(string) false === ''` serve a 200 OK with an empty body, which
		// would read as "no revocation" while a real one sits unpublished.
		return is_string( $json ) ? $json : null;
	}

	/**
	 * Validate and persist a manifest + signature pair.
	 *
	 * @param string $manifest_json The manifest JSON exactly as signed.
	 * @param string $minisig       The .minisig text over those bytes.
	 *
	 * @return string SAVED, or one of the ERR_* outcomes.
	 */
	public function save( $manifest_json, $minisig ) {
		// Both artifacts must be valid UTF-8 or wp_json_encode() will later
		// fail to build the envelope (serving an empty body). json_decode()
		// already enforces this for the manifest, but the .minisig comment
		// lines are never JSON-parsed, so check both explicitly and reject
		// here — the operator finds out at upload time, not from silent
		// non-delivery during an incident.
		if ( ! $this->is_valid_utf8( $manifest_json ) || ! $this->is_valid_utf8( $minisig ) ) {
			return self::ERR_BAD_ENCODING;
		}

		if ( ! $this->signatures->looks_like_minisig( $minisig ) ) {
			return self::ERR_BAD_MINISIG;
		}

		$data = json_decode( $manifest_json, true, 8 );

		if ( ! is_array( $data ) ) {
			return self::ERR_BAD_JSON;
		}

		if ( self::FORMAT !== ( $data['format'] ?? null ) ) {
			return self::ERR_BAD_FORMAT;
		}

		if ( ! isset( $data['sequence'] ) || ! is_int( $data['sequence'] ) || $data['sequence'] < 1
			|| ! is_array( $data['revoked_keys'] ?? [] ) ) {
			return self::ERR_BAD_SEQUENCE;
		}

		$stored = $this->get();

		if ( null !== $stored && $data['sequence'] <= $stored['sequence'] ) {
			// Clients ratchet on sequence and would ignore this anyway; refuse
			// it here so the operator finds out now, not from support tickets.
			return self::ERR_SEQUENCE_REPLAY;
		}

		update_option(
			self::OPTION_MANIFEST,
			[
				'manifest' => $manifest_json,
				'minisig'  => $minisig,
				'sequence' => $data['sequence'],
				'updated'  => time(),
			]
		);

		return self::SAVED;
	}

	/**
	 * Whether a string is valid UTF-8. The 'u' modifier makes preg_match fail
	 * (return false, not 0) on malformed UTF-8; a valid string (including '')
	 * matches the empty pattern. Avoids the deprecated seems_utf8().
	 *
	 * @param string $str Candidate bytes.
	 *
	 * @return bool
	 */
	private function is_valid_utf8( $str ) {
		return '' === $str || 1 === preg_match( '//u', $str );
	}

	/**
	 * Revoked-key count from the stored manifest, for the admin page.
	 *
	 * @return int
	 */
	public function revoked_count() {
		$stored = $this->get();

		if ( null === $stored ) {
			return 0;
		}

		$data = json_decode( $stored['manifest'], true, 8 );

		return is_array( $data ) && is_array( $data['revoked_keys'] ?? null ) ? count( $data['revoked_keys'] ) : 0;
	}
}
