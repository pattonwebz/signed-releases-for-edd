<?php

declare(strict_types=1);

namespace PattonWebz\SignedReleasesForEDD\Tests;

use PattonWebz\SignedReleasesForEDD\Admin;
use PattonWebz\SignedReleasesForEDD\SignatureStore;
use PHPUnit\Framework\TestCase;
use WP_Post;

final class AdminTest extends TestCase {

	private SignatureStore $store;

	private Admin $admin;

	/** @var string[] */
	private array $created = array();

	protected function setUp(): void {
		srfe_shims_reset();
		$this->store = new SignatureStore();
		$this->admin = new Admin( $this->store );
	}

	protected function tearDown(): void {
		foreach ( $this->created as $path ) {
			if ( file_exists( $path ) ) {
				unlink( $path );
			}
		}
		$this->created = array();
	}

	private function minisig(): string {
		$payload = 'Ed' . 'ABCDEFGH' . str_repeat( "\x01", 64 );

		return "untrusted comment: test signature\n"
			. base64_encode( $payload ) . "\n"
			. "trusted comment: slug:x version:1.0.0 signed:2026-07-16T00:00:00Z\n"
			. base64_encode( str_repeat( "\x02", 64 ) ) . "\n";
	}

	private function placeSignedFile( int $download_id, string $version ): void {
		$path = SRFE_TEST_SANDBOX . '/uploads/edd/dl-' . $download_id . '.zip';
		file_put_contents( $path, 'zip' );
		file_put_contents( $path . '.minisig', $this->minisig() );
		$this->created[] = $path;
		$this->created[] = $path . '.minisig';

		update_post_meta( $download_id, '_edd_sl_version', $version );
		$GLOBALS['__edd_download_files'][ $download_id ] = array( array( 'file' => $path ) );
	}

	// ---- Upload support -----------------------------------------------------------

	public function testMinisigUploadAllowedForShopManagers(): void {
		$mimes = $this->admin->allow_minisig_upload( array( 'zip' => 'application/zip' ) );

		$this->assertSame( 'text/plain', $mimes['minisig'] );
		$this->assertSame( 'application/zip', $mimes['zip'], 'Existing mimes untouched.' );
	}

	public function testMinisigUploadNotAllowedWithoutCapability(): void {
		$GLOBALS['__wp_user_caps'] = array();

		$this->assertArrayNotHasKey( 'minisig', $this->admin->allow_minisig_upload( array() ) );
	}

	public function testFiletypeCheckFixesMinisigForCapableUser(): void {
		$types = $this->admin->check_minisig_filetype(
			array( 'ext' => false, 'type' => false, 'proper_filename' => false ),
			'/tmp/upload',
			'my-plugin-1.2.3.zip.MINISIG'
		);

		$this->assertSame( 'minisig', $types['ext'] );
		$this->assertSame( 'text/plain', $types['type'] );
	}

	public function testFiletypeCheckIgnoresOtherExtensions(): void {
		$types = array( 'ext' => false, 'type' => false, 'proper_filename' => false );

		$this->assertSame( $types, $this->admin->check_minisig_filetype( $types, '/tmp/upload', 'archive.zip' ) );
		$this->assertSame( $types, $this->admin->check_minisig_filetype( $types, '/tmp/upload', 'minisig' ) );
	}

	public function testFiletypeCheckIgnoresMinisigWithoutCapability(): void {
		$GLOBALS['__wp_user_caps'] = array();
		$types                     = array( 'ext' => false, 'type' => false, 'proper_filename' => false );

		$this->assertSame( $types, $this->admin->check_minisig_filetype( $types, '/tmp/upload', 'x.zip.minisig' ) );
	}

	// ---- Save-time scheduling --------------------------------------------------------

	public function testSaveSchedulesDiscoveryOnce(): void {
		update_post_meta( 40, '_edd_sl_version', '1.0.0' );

		$this->admin->on_download_save( 40 );
		$this->admin->on_download_save( 40 );

		$this->assertCount( 1, $GLOBALS['__wp_scheduled'], 'Duplicate saves must not double-schedule.' );
		$this->assertSame( Admin::CRON_HOOK, $GLOBALS['__wp_scheduled'][0]['hook'] );
		$this->assertSame( array( 40 ), $GLOBALS['__wp_scheduled'][0]['args'] );
	}

	public function testSaveSkipsDownloadsWithoutVersion(): void {
		$this->admin->on_download_save( 41 );

		$this->assertSame( array(), $GLOBALS['__wp_scheduled'] );
	}

	public function testSaveSkipsRevisionsAndAutosaves(): void {
		update_post_meta( 42, '_edd_sl_version', '1.0.0' );
		$GLOBALS['__wp_revisions'] = array( 42 );

		$this->admin->on_download_save( 42 );

		$this->assertSame( array(), $GLOBALS['__wp_scheduled'] );
	}

	// ---- Refresh outcomes ---------------------------------------------------------------

	public function testRefreshWithSignatureLeavesNoNotice(): void {
		$this->placeSignedFile( 50, '1.0.0' );
		$GLOBALS['__wp_post_fields'][50] = array( 'post_author' => 7 );

		$this->admin->run_refresh( 50 );

		$this->assertSame( '', get_user_meta( 7, Admin::NOTICE_META ) );
	}

	public function testRefreshWithoutSignatureNotifiesAuthor(): void {
		update_post_meta( 51, '_edd_sl_version', '1.0.0' );
		$GLOBALS['__edd_download_files'][51] = array();
		$GLOBALS['__wp_post_fields'][51]     = array( 'post_author' => 7 );

		$this->admin->run_refresh( 51 );

		$notice = get_user_meta( 7, Admin::NOTICE_META );

		$this->assertIsArray( $notice );
		$this->assertSame( 51, $notice['download_id'] );
		$this->assertSame( SignatureStore::STATUS_NONE, $notice['status'] );
	}

	// ---- Metabox rendering ------------------------------------------------------------------

	public function testMetaboxShowsSignedStateWithKeyId(): void {
		$this->placeSignedFile( 60, '2.0.0' );
		$this->store->refresh( 60 );

		ob_start();
		$this->admin->render_metabox( new WP_Post( 60 ) );
		$html = ob_get_clean();

		$this->assertStringContainsString( 'v2.0.0 signed', $html );
		$this->assertStringContainsString( strtoupper( bin2hex( strrev( 'ABCDEFGH' ) ) ), $html );
	}

	public function testMetaboxShowsProblemStateWhenUnsigned(): void {
		update_post_meta( 61, '_edd_sl_version', '2.0.0' );
		$GLOBALS['__edd_download_files'][61] = array();
		$this->store->refresh( 61 );

		ob_start();
		$this->admin->render_metabox( new WP_Post( 61 ) );
		$html = ob_get_clean();

		$this->assertStringContainsString( 'has no .minisig signature', $html );
		$this->assertStringContainsString( 'Check signature now', $html );
	}
}
