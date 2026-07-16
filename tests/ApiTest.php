<?php

declare(strict_types=1);

namespace PattonWebz\SignedReleasesForEDD\Tests;

use PattonWebz\SignedReleasesForEDD\Api;
use PattonWebz\SignedReleasesForEDD\SignatureStore;
use PHPUnit\Framework\TestCase;
use WP_Post;

final class ApiTest extends TestCase {

	private SignatureStore $store;

	private Api $api;

	protected function setUp(): void {
		srfe_shims_reset();
		$this->store = new SignatureStore();
		$this->api   = new Api( $this->store );
	}

	private function minisig(): string {
		$payload = 'Ed' . 'ABCDEFGH' . str_repeat( "\x01", 64 );

		return "untrusted comment: test signature\n"
			. base64_encode( $payload ) . "\n"
			. "trusted comment: slug:x version:1.2.3 signed:2026-07-16T00:00:00Z\n"
			. base64_encode( str_repeat( "\x02", 64 ) ) . "\n";
	}

	public function testHookRegistersFilterAndEndpointAction(): void {
		$this->api->hook();

		$tags = array_column( $GLOBALS['__wp_hooks'], 'tag' );

		$this->assertContains( 'edd_sl_license_response', $tags );
		$this->assertContains( 'edd_get_release_signature', $tags );
	}

	public function testInjectAddsSignatureFieldsForArchivedVersion(): void {
		$this->store->archive_signature( 30, '1.2.3', $this->minisig() );

		$response = $this->api->inject_signature(
			array( 'new_version' => '1.2.3' ),
			new WP_Post( 30 )
		);

		$this->assertSame( $this->minisig(), $response['signature'] );
		$this->assertSame( 'minisign', $response['signature_format'] );
		$this->assertSame( strtoupper( bin2hex( strrev( 'ABCDEFGH' ) ) ), $response['signature_key_id'] );
	}

	public function testInjectMatchesTheOfferedVersionNotJustLatest(): void {
		// The response must carry the signature for the version EDD is
		// offering, so a stale-but-still-served response stays internally
		// consistent (file/signature pairing is the whole contract).
		$this->store->archive_signature( 31, '1.2.2', "old\n" . $this->minisig() );
		$this->store->archive_signature( 31, '1.2.3', $this->minisig() );

		$response = $this->api->inject_signature(
			array( 'new_version' => '1.2.2' ),
			new WP_Post( 31 )
		);

		$this->assertSame( "old\n" . $this->minisig(), $response['signature'] );
	}

	public function testInjectLeavesResponseUntouchedWithoutNewVersion(): void {
		$this->store->archive_signature( 32, '1.2.3', $this->minisig() );

		$response = array( 'license' => 'valid' );

		$this->assertSame( $response, $this->api->inject_signature( $response, new WP_Post( 32 ) ) );
	}

	public function testInjectLeavesResponseUntouchedForNonPost(): void {
		$response = array( 'new_version' => '1.2.3' );

		$this->assertSame( $response, $this->api->inject_signature( $response, null ) );
		$this->assertSame( $response, $this->api->inject_signature( $response, 'download' ) );
	}

	public function testInjectLeavesResponseUntouchedWhenUnsigned(): void {
		$response = array( 'new_version' => '9.9.9' );

		$result = $this->api->inject_signature( $response, new WP_Post( 33 ) );

		$this->assertSame( $response, $result );
		$this->assertArrayNotHasKey( 'signature', $result );
	}
}
