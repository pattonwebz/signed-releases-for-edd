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

	public function testSaveSchedulesDiscoveryOnceForOffsiteFiles(): void {
		update_post_meta( 40, '_edd_sl_version', '1.0.0' );
		$GLOBALS['__edd_download_files'][40] = array( array( 'file' => 'https://cdn.example/p.zip' ) );

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
		$GLOBALS['__edd_download_files'][42] = array( array( 'file' => 'https://cdn.example/p.zip' ) );
		$GLOBALS['__wp_revisions']           = array( 42 );

		$this->admin->on_download_save( 42 );

		$this->assertSame( array(), $GLOBALS['__wp_scheduled'] );
	}

	public function testSaveRunsDiscoveryInlineForLocalFilesInsteadOfScheduling(): void {
		$this->placeSignedFile( 43, '1.0.0' );

		$this->admin->on_download_save( 43 );

		$this->assertSame( array(), $GLOBALS['__wp_scheduled'], 'Local files resolve inline; nothing to schedule.' );
		$this->assertSame( SignatureStore::STATUS_FOUND, $this->store->status( 43 ) );
	}

	public function testSaveRunsDiscoveryInlineAndNotifiesOnFailureForLocalFiles(): void {
		update_post_meta( 44, '_edd_sl_version', '1.0.0' );
		$GLOBALS['__edd_download_files'][44] = array();
		$GLOBALS['__wp_post_fields'][44]     = array( 'post_author' => 7 );

		$this->admin->on_download_save( 44 );

		// Discovery itself ran inline (no scheduling needed for that), but a
		// NONE result still schedules an automatic retry — a late upload via
		// SFTP/direct disk write wouldn't otherwise ever get picked up again.
		$this->assertCount( 1, $GLOBALS['__wp_scheduled'] );
		$this->assertSame( Admin::CRON_HOOK, $GLOBALS['__wp_scheduled'][0]['hook'] );
		$notices = get_user_meta( 7, Admin::NOTICE_META );
		$this->assertIsArray( $notices );
		$this->assertSame( 44, $notices[44]['download_id'] );
	}

	// ---- Late .minisig uploads --------------------------------------------------------

	public function testAttachmentSavedRetriggersDiscoveryForParentDownload(): void {
		update_post_meta( 45, '_edd_sl_version', '1.0.0' );
		$path = SRFE_TEST_SANDBOX . '/uploads/edd/late-1.0.0.zip';
		file_put_contents( $path, 'zip' );
		file_put_contents( $path . '.minisig', $this->minisig() );
		$this->created[]                         = $path;
		$this->created[]                         = $path . '.minisig';
		$GLOBALS['__edd_download_files'][45]      = array( array( 'file' => $path ) );
		$GLOBALS['__wp_post_types'][45]           = 'download';
		$GLOBALS['__wp_post_fields'][900]         = array( 'post_parent' => 45 );
		$GLOBALS['__wp_attached_files'][900]      = $path . '.minisig';

		$this->admin->on_minisig_attachment_saved( 900 );

		$this->assertSame( SignatureStore::STATUS_FOUND, $this->store->status( 45 ) );
	}

	public function testAttachmentSavedIgnoresNonMinisigFiles(): void {
		$GLOBALS['__wp_post_fields'][901]    = array( 'post_parent' => 45 );
		$GLOBALS['__wp_post_types'][45]      = 'download';
		$GLOBALS['__wp_attached_files'][901] = '/uploads/some-image.png';

		$this->admin->on_minisig_attachment_saved( 901 );

		$this->assertSame( '', $this->store->status( 45 ) );
	}

	public function testAttachmentSavedIgnoresUnparentedUploads(): void {
		$GLOBALS['__wp_attached_files'][902] = '/uploads/loose.zip.minisig';

		// No post_parent set at all — must not touch download 0 / error.
		$this->admin->on_minisig_attachment_saved( 902 );

		$this->assertSame( array(), $GLOBALS['__wp_scheduled'] );
	}

	// ---- Retry backoff on failed discovery ---------------------------------------------

	public function testFailedRefreshSchedulesBackoffRetry(): void {
		update_post_meta( 46, '_edd_sl_version', '1.0.0' );
		$GLOBALS['__edd_download_files'][46] = array();

		$this->admin->run_refresh( 46 );

		$this->assertCount( 1, $GLOBALS['__wp_scheduled'] );
		$first_delay = $GLOBALS['__wp_scheduled'][0]['time'] - time();
		$this->assertGreaterThanOrEqual( Admin::RETRY_BASE_DELAY, $first_delay );

		// Simulate the retry firing and failing again: backoff must grow.
		$GLOBALS['__wp_scheduled'] = array();
		$this->admin->run_refresh( 46 );

		$this->assertCount( 1, $GLOBALS['__wp_scheduled'] );
		$second_delay = $GLOBALS['__wp_scheduled'][0]['time'] - time();
		$this->assertGreaterThan( $first_delay, $second_delay );
	}

	public function testSuccessfulRefreshClearsRetryState(): void {
		update_post_meta( 47, '_edd_sl_version', '1.0.0' );
		$GLOBALS['__edd_download_files'][47] = array();
		$this->admin->run_refresh( 47 ); // Fails once, schedules a retry.

		$GLOBALS['__wp_scheduled'] = array();
		$this->placeSignedFile( 47, '1.0.0' );
		$this->admin->run_refresh( 47 ); // Now succeeds.

		$this->assertSame( '', get_post_meta( 47, Admin::RETRY_META ) );

		// A later failure must restart backoff from the base delay, not
		// continue escalating from the earlier failed run.
		$GLOBALS['__edd_download_files'][47] = array();
		$this->admin->run_refresh( 47 );

		$delay = $GLOBALS['__wp_scheduled'][0]['time'] - time();
		$this->assertGreaterThanOrEqual( Admin::RETRY_BASE_DELAY, $delay );
		$this->assertLessThan( Admin::RETRY_BASE_DELAY * 2, $delay );
	}

	public function testAmbiguousStatusDoesNotScheduleRetry(): void {
		$a = SRFE_TEST_SANDBOX . '/uploads/edd/amb-a.zip';
		$b = SRFE_TEST_SANDBOX . '/uploads/edd/amb-b.zip';
		file_put_contents( $a, 'zip' );
		file_put_contents( $b, 'zip' );
		file_put_contents( $a . '.minisig', $this->minisig() );
		file_put_contents(
			$b . '.minisig',
			str_replace( 'x version:1.0.0', 'y version:1.0.0', $this->minisig() )
		);
		foreach ( array( $a, $b, $a . '.minisig', $b . '.minisig' ) as $created ) {
			$this->created[] = $created;
		}
		update_post_meta( 48, '_edd_sl_version', '1.0.0' );
		$GLOBALS['__edd_download_files'][48] = array( array( 'file' => $a ), array( 'file' => $b ) );

		$this->admin->run_refresh( 48 );

		$this->assertSame( SignatureStore::STATUS_AMBIGUOUS, $this->store->status( 48 ) );
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

		$notices = get_user_meta( 7, Admin::NOTICE_META );

		$this->assertIsArray( $notices );
		$this->assertSame( 51, $notices[51]['download_id'] );
		$this->assertSame( SignatureStore::STATUS_NONE, $notices[51]['status'] );
	}

	public function testIndependentDownloadsBothKeepNotices(): void {
		update_post_meta( 52, '_edd_sl_version', '1.0.0' );
		update_post_meta( 53, '_edd_sl_version', '1.0.0' );
		$GLOBALS['__edd_download_files'][52] = array();
		$GLOBALS['__edd_download_files'][53] = array();
		$GLOBALS['__wp_post_fields'][52]     = array( 'post_author' => 7 );
		$GLOBALS['__wp_post_fields'][53]     = array( 'post_author' => 7 );

		$this->admin->run_refresh( 52 );
		$this->admin->run_refresh( 53 );

		$notices = get_user_meta( 7, Admin::NOTICE_META );

		$this->assertIsArray( $notices );
		$this->assertCount( 2, $notices, 'Both products must have a surviving notice, not just the most recent one.' );
		$this->assertSame( 52, $notices[52]['download_id'] );
		$this->assertSame( 53, $notices[53]['download_id'] );
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

	public function testMetaboxRendersPluginSlugFieldWithCurrentValue(): void {
		$this->store->set_plugin_slug( 62, 'my-existing-plugin' );

		ob_start();
		$this->admin->render_metabox( new WP_Post( 62 ) );
		$html = ob_get_clean();

		$this->assertStringContainsString( 'name="srfe_plugin_slug"', $html );
		$this->assertStringContainsString( 'value="my-existing-plugin"', $html );
	}

	// ---- Plugin-slug mapping save handling ---------------------------------------------

	public function testSaveStoresPluginSlugWithValidNonceAndCapability(): void {
		$GLOBALS['__wp_user_caps']            = array( 'edit_post' );
		$_POST['srfe_plugin_slug']            = 'accessibility-checker-pro';
		$_POST['srfe_plugin_slug_nonce']      = 'test-nonce';

		$this->admin->on_download_save( 63 );

		$this->assertSame( 'accessibility-checker-pro', $this->store->plugin_slug( 63 ) );
		$this->assertSame( 63, $this->store->find_by_plugin_slug( 'accessibility-checker-pro' ) );
	}

	public function testSaveIgnoresPluginSlugWithoutCapability(): void {
		$GLOBALS['__wp_user_caps']       = array();
		$_POST['srfe_plugin_slug']       = 'should-not-save';
		$_POST['srfe_plugin_slug_nonce'] = 'test-nonce';

		$this->admin->on_download_save( 64 );

		$this->assertSame( '', $this->store->plugin_slug( 64 ) );
	}

	public function testSaveIgnoresPluginSlugWithBadNonce(): void {
		$GLOBALS['__wp_user_caps']       = array( 'edit_post' );
		$_POST['srfe_plugin_slug']       = 'should-not-save';
		$_POST['srfe_plugin_slug_nonce'] = 'forged';

		$this->admin->on_download_save( 65 );

		$this->assertSame( '', $this->store->plugin_slug( 65 ) );
	}

	public function testSaveLeavesMappingUntouchedWhenFieldAbsent(): void {
		$this->store->set_plugin_slug( 66, 'already-set' );

		$this->admin->on_download_save( 66 );

		$this->assertSame( 'already-set', $this->store->plugin_slug( 66 ) );
	}
}
