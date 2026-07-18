<?php

declare(strict_types=1);

namespace PattonWebz\SignedReleasesForEDD\Tests;

use PattonWebz\SignedReleasesForEDD\RevocationStore;
use PattonWebz\SignedReleasesForEDD\SignatureStore;
use PHPUnit\Framework\TestCase;

final class RevocationStoreTest extends TestCase {

	private RevocationStore $revocations;

	protected function setUp(): void {
		srfe_shims_reset();
		$this->revocations = new RevocationStore( new SignatureStore() );
	}

	private function minisig(): string {
		$payload = 'ED' . 'ABCDEFGH' . str_repeat( "\x01", 64 );

		return "untrusted comment: test revocation signature\n"
			. base64_encode( $payload ) . "\n"
			. "trusted comment: revocation-manifest sequence:3 format:pattonwebz-revocation-v1\n"
			. base64_encode( str_repeat( "\x02", 64 ) ) . "\n";
	}

	private function manifest( int $sequence = 3 ): string {
		return json_encode(
			[
				'format'       => 'pattonwebz-revocation-v1',
				'sequence'     => $sequence,
				'issued_at'    => '2026-07-18T14:00:00Z',
				'revoked_keys' => [
					[
						'key_id' => 'A3C8A30944668DB8',
						'reason' => 'ci_secret_compromise',
					],
				],
			]
		);
	}

	public function testEmptyStoreHasNoManifestAndNoEnvelope(): void {
		$this->assertNull( $this->revocations->get() );
		$this->assertNull( $this->revocations->envelope() );
		$this->assertSame( 0, $this->revocations->revoked_count() );
	}

	public function testValidPairSavesAndServes(): void {
		$this->assertSame( RevocationStore::SAVED, $this->revocations->save( $this->manifest(), $this->minisig() ) );

		$stored = $this->revocations->get();

		$this->assertSame( 3, $stored['sequence'] );
		$this->assertSame( $this->manifest(), $stored['manifest'] );
		$this->assertSame( 1, $this->revocations->revoked_count() );
	}

	public function testEnvelopeCarriesTheExactSignedBytes(): void {
		// Deliberately odd formatting: the signature covers these exact bytes,
		// so the envelope must never normalise or re-encode the manifest.
		$manifest = "{ \"format\": \"pattonwebz-revocation-v1\",\n  \"sequence\": 3,\n  \"revoked_keys\": [] }";

		$this->assertSame( RevocationStore::SAVED, $this->revocations->save( $manifest, $this->minisig() ) );

		$envelope = json_decode( $this->revocations->envelope(), true );

		$this->assertSame( 'pattonwebz-revocation-envelope-v1', $envelope['format'] );
		$this->assertSame( $manifest, $envelope['manifest'] );
		$this->assertSame( $this->minisig(), $envelope['minisig'] );
	}

	public function testEnvelopePreservesCrlfNonAsciiAndEscapedBytes(): void {
		// The exact concern that drove the file-upload admin design: a manifest
		// whose signed bytes contain CRLF line endings, non-ASCII, and already-
		// escaped JSON characters must survive storage + envelope encoding
		// byte-for-byte, or the client's root-signature check fails.
		$manifest = "{\r\n  \"format\": \"pattonwebz-revocation-v1\",\r\n"
			. "  \"sequence\": 7,\r\n"
			. "  \"reason_note\": \"caf\xC3\xA9 \xF0\x9F\x94\x91 \\\"quoted\\\" \\\\ backslash\",\r\n"
			. "  \"revoked_keys\": []\r\n}";

		$this->assertSame( RevocationStore::SAVED, $this->revocations->save( $manifest, $this->minisig() ) );

		$envelope = json_decode( $this->revocations->envelope(), true );

		$this->assertSame( $manifest, $envelope['manifest'], 'Manifest bytes must round-trip exactly (CRLF, UTF-8, escapes).' );
	}

	public function testStructurallyInvalidMinisigIsRefused(): void {
		$this->assertSame(
			RevocationStore::ERR_BAD_MINISIG,
			$this->revocations->save( $this->manifest(), "not a minisig\n" )
		);
		$this->assertNull( $this->revocations->get() );
	}

	public function testInvalidJsonIsRefused(): void {
		$this->assertSame( RevocationStore::ERR_BAD_JSON, $this->revocations->save( '{nope', $this->minisig() ) );
	}

	public function testUnknownFormatTagIsRefused(): void {
		$manifest = json_encode(
			[
				'format'   => 'pattonwebz-revocation-v9',
				'sequence' => 3,
			]
		);

		$this->assertSame( RevocationStore::ERR_BAD_FORMAT, $this->revocations->save( $manifest, $this->minisig() ) );
	}

	public function testNonIntegerSequenceIsRefused(): void {
		$manifest = json_encode(
			[
				'format'       => 'pattonwebz-revocation-v1',
				'sequence'     => '3',
				'revoked_keys' => [],
			]
		);

		$this->assertSame( RevocationStore::ERR_BAD_SEQUENCE, $this->revocations->save( $manifest, $this->minisig() ) );
	}

	public function testSequenceAtOrBelowStoredIsRefused(): void {
		$this->revocations->save( $this->manifest( 3 ), $this->minisig() );

		$this->assertSame( RevocationStore::ERR_SEQUENCE_REPLAY, $this->revocations->save( $this->manifest( 3 ), $this->minisig() ) );
		$this->assertSame( RevocationStore::ERR_SEQUENCE_REPLAY, $this->revocations->save( $this->manifest( 2 ), $this->minisig() ) );

		$this->assertSame( 3, $this->revocations->get()['sequence'] );
	}

	public function testHigherSequenceReplacesTheStoredManifest(): void {
		$this->revocations->save( $this->manifest( 3 ), $this->minisig() );

		$this->assertSame( RevocationStore::SAVED, $this->revocations->save( $this->manifest( 4 ), $this->minisig() ) );
		$this->assertSame( 4, $this->revocations->get()['sequence'] );
	}

	public function testInvalidUtf8MinisigIsRefused(): void {
		// An invalid UTF-8 byte in a comment line would later make
		// wp_json_encode() fail; reject it at save time instead.
		$bad = "untrusted comment: bad \xC3\x28 byte\n"
			. base64_encode( 'ED' . 'ABCDEFGH' . str_repeat( "\x01", 64 ) ) . "\n"
			. "trusted comment: revocation-manifest sequence:3 format:pattonwebz-revocation-v1\n"
			. base64_encode( str_repeat( "\x02", 64 ) ) . "\n";

		$this->assertSame( RevocationStore::ERR_BAD_ENCODING, $this->revocations->save( $this->manifest(), $bad ) );
		$this->assertNull( $this->revocations->get() );
	}

	public function testEnvelopeReturnsNullRatherThanEmptyStringOnEncodeFailure(): void {
		// Defence in depth: even if invalid-UTF-8 bytes somehow reached the
		// stored option (bypassing save()), envelope() must return null — the
		// endpoint's 404 path — never '' which would serve a 200 OK empty body
		// indistinguishable from "no revocation".
		update_option(
			RevocationStore::OPTION_MANIFEST,
			[
				'manifest' => $this->manifest(),
				'minisig'  => "untrusted comment: \xC3\x28\n" . $this->minisig(),
				'sequence' => 3,
				'updated'  => time(),
			]
		);

		$this->assertNull( $this->revocations->envelope() );
	}

	public function testCorruptOptionReadsAsAbsent(): void {
		update_option( RevocationStore::OPTION_MANIFEST, 'scalar garbage' );

		$this->assertNull( $this->revocations->get() );

		// And a valid save recovers cleanly.
		$this->assertSame( RevocationStore::SAVED, $this->revocations->save( $this->manifest(), $this->minisig() ) );
	}
}
