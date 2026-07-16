<?php

declare(strict_types=1);

namespace PattonWebz\SignedReleasesForEDD\Tests;

use PattonWebz\SignedReleasesForEDD\SignatureStore;
use PHPUnit\Framework\TestCase;

final class SignatureStoreTest extends TestCase {

	private SignatureStore $store;

	/** @var string[] Paths created under the sandbox, removed in tearDown. */
	private array $created = array();

	protected function setUp(): void {
		srfe_shims_reset();
		$this->store = new SignatureStore();
	}

	protected function tearDown(): void {
		foreach ( $this->created as $path ) {
			if ( file_exists( $path ) ) {
				unlink( $path );
			}
		}
		$this->created = array();
	}

	/**
	 * A structurally valid minisig: 74-byte payload ('Ed' + 8-byte key id +
	 * 64-byte signature), trusted comment, comment signature line.
	 */
	private function makeMinisig( string $key_id = 'ABCDEFGH', string $comment = 'slug:x version:1.0.0 signed:2026-07-16T00:00:00Z' ): string {
		$payload = 'Ed' . $key_id . str_repeat( "\x01", 64 );

		return "untrusted comment: test signature\n"
			. base64_encode( $payload ) . "\n"
			. "trusted comment: {$comment}\n"
			. base64_encode( str_repeat( "\x02", 64 ) ) . "\n";
	}

	/** Create a zip + optional .minisig inside the sandbox uploads dir. */
	private function placeFile( string $name, ?string $minisig ): string {
		$path = SRFE_TEST_SANDBOX . '/uploads/edd/' . $name;
		file_put_contents( $path, 'zip-bytes' );
		$this->created[] = $path;

		if ( null !== $minisig ) {
			file_put_contents( $path . '.minisig', $minisig );
			$this->created[] = $path . '.minisig';
		}

		return $path;
	}

	private function setupDownload( int $id, string $version, array $file_paths ): void {
		update_post_meta( $id, '_edd_sl_version', $version );
		$GLOBALS['__edd_download_files'][ $id ] = array_map(
			static function ( $path ) {
				return array( 'file' => $path );
			},
			$file_paths
		);
	}

	// ---- Structural validation ------------------------------------------------

	public function testLooksLikeMinisigAcceptsWellFormed(): void {
		$this->assertTrue( $this->store->looks_like_minisig( $this->makeMinisig() ) );
	}

	public function testLooksLikeMinisigRejectsGarbage(): void {
		$this->assertFalse( $this->store->looks_like_minisig( 'not a signature' ) );
		$this->assertFalse( $this->store->looks_like_minisig( '' ) );
		$this->assertFalse( $this->store->looks_like_minisig( str_repeat( 'x', 9000 ) ) );
	}

	public function testLooksLikeMinisigRejectsWrongPayloadLength(): void {
		$bad = "untrusted comment: t\n" . base64_encode( 'too-short' ) . "\ntrusted comment: c\n" . base64_encode( 'sig' ) . "\n";
		$this->assertFalse( $this->store->looks_like_minisig( $bad ) );
	}

	public function testKeyIdExtractsLittleEndianHex(): void {
		$key_id = $this->store->key_id( $this->makeMinisig( 'ABCDEFGH' ) );

		// 8 raw bytes, reversed (minisign displays little-endian), upper hex.
		$this->assertSame( strtoupper( bin2hex( strrev( 'ABCDEFGH' ) ) ), $key_id );
	}

	// ---- Discovery --------------------------------------------------------------

	public function testDiscoverFindsLocalSignatureNextToFile(): void {
		$path = $this->placeFile( 'my-plugin-1.2.3.zip', $this->makeMinisig() );
		$this->setupDownload( 10, '1.2.3', array( $path ) );

		$result = $this->store->discover( 10 );

		$this->assertSame( SignatureStore::STATUS_FOUND, $result['status'] );
		$this->assertSame( $this->makeMinisig(), $result['minisig'] );
	}

	public function testDiscoverResolvesUploadsUrlToLocalPath(): void {
		$this->placeFile( 'my-plugin-1.2.3.zip', $this->makeMinisig() );
		$this->setupDownload( 10, '1.2.3', array( 'https://store.example/wp-content/uploads/edd/my-plugin-1.2.3.zip' ) );

		$result = $this->store->discover( 10 );

		$this->assertSame( SignatureStore::STATUS_FOUND, $result['status'] );
		$this->assertSame( array(), $GLOBALS['__wp_http_requests'], 'Uploads URLs must be read from disk, not fetched.' );
	}

	public function testDiscoverReportsNoneWhenNoSignature(): void {
		$path = $this->placeFile( 'unsigned.zip', null );
		$this->setupDownload( 11, '1.0.0', array( $path ) );

		$this->assertSame( SignatureStore::STATUS_NONE, $this->store->discover( 11 )['status'] );
	}

	public function testDiscoverReportsAmbiguousOnDifferingSignatures(): void {
		$a = $this->placeFile( 'standard.zip', $this->makeMinisig( 'AAAAAAAA' ) );
		$b = $this->placeFile( 'pro.zip', $this->makeMinisig( 'BBBBBBBB' ) );
		$this->setupDownload( 12, '2.0.0', array( $a, $b ) );

		$result = $this->store->discover( 12 );

		$this->assertSame( SignatureStore::STATUS_AMBIGUOUS, $result['status'] );
		$this->assertNull( $result['minisig'] );
	}

	public function testDiscoverAcceptsIdenticalSignatureOnSeveralFiles(): void {
		$sig = $this->makeMinisig();
		$a   = $this->placeFile( 'a.zip', $sig );
		$b   = $this->placeFile( 'b.zip', $sig );
		$this->setupDownload( 13, '2.0.0', array( $a, $b ) );

		$this->assertSame( SignatureStore::STATUS_FOUND, $this->store->discover( 13 )['status'] );
	}

	public function testDiscoverFetchesPublicOffsiteUrl(): void {
		$url = 'https://cdn.example/releases/my-plugin-1.2.3.zip';
		$GLOBALS['__wp_http_responses'][ $url . '.minisig' ] = array(
			'code' => 200,
			'body' => $this->makeMinisig(),
		);
		$this->setupDownload( 14, '1.2.3', array( $url ) );

		$result = $this->store->discover( 14 );

		$this->assertSame( SignatureStore::STATUS_FOUND, $result['status'] );
		$this->assertSame( array( $url . '.minisig' ), $GLOBALS['__wp_http_requests'] );
		$this->assertSame(
			array( $url . '.minisig' ),
			$GLOBALS['__wp_safe_http_requests'],
			'Offsite discovery must use wp_safe_remote_get, not the unfiltered wp_remote_get.'
		);
	}

	public function testDiscoverReportsOffsiteUnreachableForBareObjectKeys(): void {
		$this->setupDownload( 15, '1.2.3', array( 's3://bucket/my-plugin-1.2.3.zip' ) );

		$this->assertSame( SignatureStore::STATUS_OFFSITE, $this->store->discover( 15 )['status'] );
	}

	public function testDiscoverRefusesPathsOutsideContainmentRoot(): void {
		// A valid signature sitting outside wp-content must never be read —
		// an admin-set file reference must not become a traversal primitive.
		// The path resolves as "unreachable" (an actionable admin status)
		// rather than silently unsigned, but its CONTENT stays unread.
		$outside = sys_get_temp_dir() . '/srfe-outside-' . getmypid();
		mkdir( $outside, 0700, true );
		file_put_contents( $outside . '/x.zip', 'zip' );
		file_put_contents( $outside . '/x.zip.minisig', $this->makeMinisig() );

		$this->setupDownload( 16, '1.0.0', array( $outside . '/x.zip' ) );

		$result = $this->store->discover( 16 );

		$this->assertSame( SignatureStore::STATUS_OFFSITE, $result['status'] );
		$this->assertNull( $result['minisig'], 'Out-of-root signature content must never be read.' );

		$this->assertSame( SignatureStore::STATUS_OFFSITE, $this->store->refresh( 16 ) );
		$this->assertNull( $this->store->get_signature( 16, '1.0.0' ), 'Nothing may be archived from outside the root.' );

		unlink( $outside . '/x.zip' );
		unlink( $outside . '/x.zip.minisig' );
		rmdir( $outside );
	}

	public function testDiscoverRejectsNonSignatureContent(): void {
		$path = $this->placeFile( 'fake.zip', '<html>404 page pretending to be a signature</html>' );
		$this->setupDownload( 17, '1.0.0', array( $path ) );

		$this->assertSame( SignatureStore::STATUS_NONE, $this->store->discover( 17 )['status'] );
	}

	// ---- Sync vs. async discovery decision -----------------------------------------

	public function testResolvesLocallyTrueForLocalFile(): void {
		$path = $this->placeFile( 'p-1.0.0.zip', $this->makeMinisig() );
		$this->setupDownload( 26, '1.0.0', array( $path ) );

		$this->assertTrue( $this->store->resolves_locally( 26 ) );
	}

	public function testResolvesLocallyTrueForUploadsUrl(): void {
		$this->placeFile( 'p-1.0.0.zip', $this->makeMinisig() );
		$this->setupDownload( 27, '1.0.0', array( 'https://store.example/wp-content/uploads/edd/p-1.0.0.zip' ) );

		$this->assertTrue( $this->store->resolves_locally( 27 ) );
	}

	public function testResolvesLocallyTrueWithNoFilesYet(): void {
		$this->setupDownload( 28, '1.0.0', array() );

		$this->assertTrue( $this->store->resolves_locally( 28 ) );
	}

	public function testResolvesLocallyTrueForBareObjectKeyNotFalseNetworkFetch(): void {
		// Unreachable-but-not-a-network-fetch: cheap to resolve (instantly
		// "can't read this"), so still safe to run inline.
		$this->setupDownload( 29, '1.0.0', array( 's3://bucket/p-1.0.0.zip' ) );

		$this->assertTrue( $this->store->resolves_locally( 29 ) );
	}

	public function testResolvesLocallyFalseForOffsiteHttpUrl(): void {
		$this->setupDownload( 35, '1.0.0', array( 'https://cdn.example/releases/p-1.0.0.zip' ) );

		$this->assertFalse( $this->store->resolves_locally( 35 ) );
	}

	public function testResolvesLocallyFalseWhenAnyOfSeveralFilesIsOffsite(): void {
		$local = $this->placeFile( 'standard.zip', $this->makeMinisig() );
		$this->setupDownload( 36, '1.0.0', array( $local, 'https://cdn.example/pro.zip' ) );

		$this->assertFalse( $this->store->resolves_locally( 36 ) );
	}

	// ---- Explicit plugin-slug mapping ------------------------------------------------

	public function testPluginSlugDefaultsEmpty(): void {
		$this->assertSame( '', $this->store->plugin_slug( 70 ) );
		$this->assertSame( 0, $this->store->find_by_plugin_slug( 'anything' ) );
	}

	public function testSetPluginSlugIsFindableAndReadable(): void {
		$this->store->set_plugin_slug( 70, 'my-plugin' );

		$this->assertSame( 'my-plugin', $this->store->plugin_slug( 70 ) );
		$this->assertSame( 70, $this->store->find_by_plugin_slug( 'my-plugin' ) );
	}

	public function testReassigningSlugRemovesTheOldMapping(): void {
		$this->store->set_plugin_slug( 71, 'old-slug' );
		$this->store->set_plugin_slug( 71, 'new-slug' );

		$this->assertSame( 0, $this->store->find_by_plugin_slug( 'old-slug' ), 'Stale mapping must not linger.' );
		$this->assertSame( 71, $this->store->find_by_plugin_slug( 'new-slug' ) );
	}

	public function testClearingSlugRemovesTheMapping(): void {
		$this->store->set_plugin_slug( 72, 'temp-slug' );
		$this->store->set_plugin_slug( 72, '' );

		$this->assertSame( '', $this->store->plugin_slug( 72 ) );
		$this->assertSame( 0, $this->store->find_by_plugin_slug( 'temp-slug' ) );
	}

	public function testSetPluginSlugRefusesSlugClaimedByAnotherLiveDownload(): void {
		// The index drives the public endpoint's slug resolution, so letting
		// one product's editor take over another's slug would re-point that
		// plugin's signature serving.
		$GLOBALS['__wp_post_types'][70] = 'download';
		$this->assertTrue( $this->store->set_plugin_slug( 70, 'contested-slug' ) );

		$this->assertFalse( $this->store->set_plugin_slug( 71, 'contested-slug' ) );
		$this->assertSame( 70, $this->store->find_by_plugin_slug( 'contested-slug' ), 'Original mapping must survive the takeover attempt.' );
		$this->assertSame( '', $this->store->plugin_slug( 71 ) );
	}

	public function testSetPluginSlugReclaimAllowedWhenHolderIsGone(): void {
		// Download 70 never gets a post type registered here — it stands in
		// for a deleted download whose stale index entry must not squat on
		// the slug forever.
		$this->assertTrue( $this->store->set_plugin_slug( 70, 'orphaned-slug' ) );
		$this->assertTrue( $this->store->set_plugin_slug( 71, 'orphaned-slug' ) );
		$this->assertSame( 71, $this->store->find_by_plugin_slug( 'orphaned-slug' ) );
	}

	public function testSetPluginSlugResaveOfSameDownloadIsAllowed(): void {
		$GLOBALS['__wp_post_types'][70] = 'download';

		$this->assertTrue( $this->store->set_plugin_slug( 70, 'my-plugin' ) );
		$this->assertTrue( $this->store->set_plugin_slug( 70, 'my-plugin' ) );
		$this->assertSame( 70, $this->store->find_by_plugin_slug( 'my-plugin' ) );
	}

	// ---- Refresh + archive --------------------------------------------------------

	public function testRefreshArchivesUnderCurrentVersion(): void {
		$path = $this->placeFile( 'p-3.0.0.zip', $this->makeMinisig() );
		$this->setupDownload( 20, '3.0.0', array( $path ) );

		$this->assertSame( SignatureStore::STATUS_FOUND, $this->store->refresh( 20 ) );
		$this->assertSame( $this->makeMinisig(), $this->store->get_signature( 20, '3.0.0' ) );
		$this->assertSame( SignatureStore::STATUS_FOUND, $this->store->status( 20 ) );
	}

	public function testRefreshWithoutVersionIsNone(): void {
		$this->assertSame( SignatureStore::STATUS_NONE, $this->store->refresh( 21 ) );
	}

	public function testGetSignatureDefaultsToCurrentVersion(): void {
		update_post_meta( 22, '_edd_sl_version', '2.5.0' );
		$this->store->archive_signature( 22, '2.5.0', $this->makeMinisig() );

		$this->assertSame( $this->makeMinisig(), $this->store->get_signature( 22 ) );
		$this->assertSame( $this->makeMinisig(), $this->store->get_signature( 22, '' ) );
	}

	public function testGetSignatureNeverFetches(): void {
		// The read path must stay pure even when the download's file is
		// offsite — fetch amplification on the public API path was a review
		// finding and must not regress.
		update_post_meta( 23, '_edd_sl_version', '1.0.0' );
		$GLOBALS['__edd_download_files'][23] = array( array( 'file' => 'https://cdn.example/p.zip' ) );

		$this->assertNull( $this->store->get_signature( 23, '1.0.0' ) );
		$this->assertSame( array(), $GLOBALS['__wp_http_requests'] );
	}

	public function testSupersededVersionsStayServable(): void {
		$old = $this->makeMinisig( 'AAAAAAAA' );
		$new = $this->makeMinisig( 'BBBBBBBB' );
		$this->store->archive_signature( 24, '1.0.0', $old );
		$this->store->archive_signature( 24, '1.1.0', $new );

		$this->assertSame( $old, $this->store->get_signature( 24, '1.0.0' ) );
		$this->assertSame( $new, $this->store->get_signature( 24, '1.1.0' ) );
	}

	public function testArchivePrunesOldestBeyondLimit(): void {
		for ( $i = 0; $i <= SignatureStore::ARCHIVE_LIMIT + 1; $i++ ) {
			$this->store->archive_signature( 25, '1.0.' . $i, $this->makeMinisig() );
		}

		$archive = $this->store->archive( 25 );

		$this->assertCount( SignatureStore::ARCHIVE_LIMIT, $archive );
		$this->assertArrayNotHasKey( '1.0.0', $archive, 'Oldest version pruned.' );
		$this->assertArrayNotHasKey( '1.0.1', $archive );
		$this->assertArrayHasKey( '1.0.' . ( SignatureStore::ARCHIVE_LIMIT + 1 ), $archive, 'Newest version kept.' );
	}
}
