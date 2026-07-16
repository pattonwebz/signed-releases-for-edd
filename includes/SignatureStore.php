<?php
/**
 * Finds, caches, and archives .minisig signatures for EDD download files.
 *
 * Reads and writes are separated so the request path never blocks:
 *  - get_signature() is a pure read from the per-version archive in post meta.
 *    It never fetches, so it is safe on the public / get_version API path.
 *  - refresh() performs discovery (a possible outbound HTTP fetch for offsite
 *    files) and updates the archive. It runs OFF the request path — a
 *    scheduled event on save, or an admin-initiated "check now".
 *
 * Discovery looks for "<file>.minisig" next to each of the download's files:
 * on disk for local storage, over HTTP for publicly-reachable offsite URLs.
 * Archived signatures persist per version, so superseded versions stay
 * servable after the files are replaced with a new release.
 *
 * @package PattonWebz\SignedReleasesForEDD
 */

namespace PattonWebz\SignedReleasesForEDD;

defined( 'ABSPATH' ) || exit;

/**
 * Signature discovery, per-version archive, and read access for downloads.
 */
class SignatureStore {

	const META_ARCHIVE      = '_srfe_signature_archive';
	const META_STATUS       = '_srfe_signature_status';
	const META_PLUGIN_SLUG  = '_srfe_plugin_slug';
	const OPTION_SLUG_INDEX = 'srfe_plugin_slug_index';

	/** Keep signatures for this many past versions per download. */
	const ARCHIVE_LIMIT = 20;

	/** Discovery outcomes, also stored in META_STATUS for the admin UI. */
	const STATUS_FOUND     = 'found';
	const STATUS_NONE      = 'none';
	const STATUS_AMBIGUOUS = 'ambiguous';
	const STATUS_OFFSITE   = 'offsite_unreachable';

	/**
	 * Read the archived signature for a download at a version.
	 *
	 * Pure read: never triggers discovery or an outbound fetch, so it is safe
	 * on the public / API request path. Signatures land in the archive via
	 * refresh(), which runs off the request path.
	 *
	 * @param int    $download_id Download (post) ID.
	 * @param string $version     Version string; '' means the current version.
	 *
	 * @return string|null Full .minisig text, or null when not archived.
	 */
	public function get_signature( $download_id, $version = '' ) {
		if ( '' === $version ) {
			$version = $this->current_version( $download_id );
		}

		if ( '' === $version ) {
			return null;
		}

		$archive = $this->archive( $download_id );

		return isset( $archive[ $version ] ) && is_string( $archive[ $version ] )
			? $archive[ $version ]
			: null;
	}

	/**
	 * Discover the current version's signature and archive it. Runs off the
	 * request path because discovery may perform a blocking HTTP fetch.
	 *
	 * @param int $download_id Download (post) ID.
	 *
	 * @return string One of the STATUS_* constants.
	 */
	public function refresh( $download_id ) {
		$version = $this->current_version( $download_id );

		if ( '' === $version ) {
			$this->set_status( $download_id, self::STATUS_NONE );

			return self::STATUS_NONE;
		}

		$result = $this->discover( $download_id );

		if ( self::STATUS_FOUND === $result['status'] ) {
			$this->archive_signature( $download_id, $version, $result['minisig'] );
		}

		$this->set_status( $download_id, $result['status'] );

		return $result['status'];
	}

	/**
	 * Look for <file>.minisig next to each of the download's files.
	 *
	 * A download can carry several files (including per-price-tier files). If
	 * more than one *distinct* signature is found we cannot know which package
	 * the client will download, so we report STATUS_AMBIGUOUS rather than
	 * archive a signature that may not match the delivered file — serving the
	 * wrong signature would block a legitimate update in the client's enforce
	 * mode. The common single-file plugin download resolves unambiguously.
	 *
	 * @param int $download_id Download (post) ID.
	 *
	 * @return array{status:string, minisig:?string}
	 */
	public function discover( $download_id ) {
		$found           = [];
		$saw_unreachable = false;

		foreach ( (array) edd_get_download_files( $download_id ) as $file ) {
			if ( empty( $file['file'] ) ) {
				continue;
			}

			$candidate = $this->read_candidate( $file['file'] . '.minisig', $saw_unreachable );

			if ( null !== $candidate && ! in_array( $candidate, $found, true ) ) {
				$found[] = $candidate;
			}
		}

		if ( count( $found ) > 1 ) {
			return [
				'status'  => self::STATUS_AMBIGUOUS,
				'minisig' => null,
			];
		}

		if ( 1 === count( $found ) ) {
			return [
				'status'  => self::STATUS_FOUND,
				'minisig' => $found[0],
			];
		}

		// Nothing found. Distinguish "unsigned" from "offsite and unfetchable"
		// so the admin gets an actionable message instead of a silent miss.
		return [
			'status'  => $saw_unreachable ? self::STATUS_OFFSITE : self::STATUS_NONE,
			'minisig' => null,
		];
	}

	/**
	 * Read a candidate .minisig from disk or a public URL and sanity-check it.
	 *
	 * @param string $location         Path or URL of the expected signature.
	 * @param bool   $saw_unreachable  Set true (by-ref) if the file is offsite
	 *                                 and not fetchable from here.
	 *
	 * @return string|null
	 */
	private function read_candidate( $location, &$saw_unreachable ) {
		$contents = null;

		$local = $this->to_local_path( $location );

		if ( null !== $local ) {
			if ( is_readable( $local ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- Local disk read of a containment-checked path, not a remote fetch; off the request path; WP_Filesystem adds nothing here.
				$contents = file_get_contents( $local );
			}
		} elseif ( $this->requires_network_fetch( $location ) ) {
			// Offsite but publicly fetchable: the .minisig must be uploaded
			// next to the file and reachable at the same URL + ".minisig".
			$response = wp_safe_remote_get(
				$location,
				[
					// phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout -- deliberately generous: this only ever runs off a live request (deferred to cron for offsite files, or an explicit admin "check now"), never inline on a page load.
					'timeout'             => 15,
					'redirection'         => 2,
					// looks_like_minisig() rejects anything over 8 KB anyway;
					// cap the transfer so a hostile/compromised offsite host
					// can't feed an unbounded body into memory first.
					'limit_response_size' => 8192,
				]
			);

			if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
				$contents = wp_remote_retrieve_body( $response );
			}
		} else {
			// A non-HTTP reference we cannot resolve locally (bare S3 object
			// key, s3://, edd-cloud:// …). Not fetchable here.
			$saw_unreachable = true;
		}

		if ( ! is_string( $contents ) || '' === $contents ) {
			return null;
		}

		return $this->looks_like_minisig( $contents ) ? $contents : null;
	}

	/**
	 * Whether reading a candidate at this location requires a blocking
	 * network fetch (a publicly-reachable http(s) URL outside the uploads
	 * directory), as opposed to a disk read or an unreachable reference.
	 *
	 * @param string $location Path or URL of the expected signature.
	 *
	 * @return bool
	 */
	private function requires_network_fetch( $location ) {
		return (bool) preg_match( '#^https?://#i', $location );
	}

	/**
	 * Whether every one of the download's files resolves to disk (locally,
	 * or blocked/unreachable without a network round trip) rather than
	 * needing a live HTTP fetch. When true, discover() never blocks on
	 * network I/O, so it is cheap enough to run inline on save instead of
	 * deferring to cron — closing the window between "version bumped" and
	 * "the deferred check eventually ran" that pure async discovery leaves
	 * open for enforce-mode clients.
	 *
	 * @param int $download_id Download (post) ID.
	 *
	 * @return bool
	 */
	public function resolves_locally( $download_id ) {
		foreach ( (array) edd_get_download_files( $download_id ) as $file ) {
			if ( empty( $file['file'] ) ) {
				continue;
			}

			$local = $this->to_local_path( $file['file'] );

			if ( null === $local && $this->requires_network_fetch( $file['file'] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Map a file reference to a readable local path, or null if it isn't one.
	 *
	 * The resolved path is required to sit under the containment root
	 * (wp-content by default, filterable), which defeats traversal such as
	 * "…/uploads/../../etc/passwd" in an admin-set file reference.
	 *
	 * @param string $location Path or URL.
	 *
	 * @return string|null
	 */
	private function to_local_path( $location ) {
		if ( preg_match( '#^[a-z][a-z0-9+.-]*://#i', $location ) ) {
			// A URL. Only an uploads-dir URL maps to a local path.
			$uploads = wp_upload_dir();

			if ( ! empty( $uploads['baseurl'] ) && 0 === strpos( $location, $uploads['baseurl'] ) ) {
				return $this->contain( $uploads['basedir'] . substr( $location, strlen( $uploads['baseurl'] ) ) );
			}

			return null; // Offsite URL — handled by the HTTP branch.
		}

		return $this->contain( $location );
	}

	/**
	 * Canonicalise a candidate path and require its directory to sit within
	 * the containment root. Returns the safe path, or null if outside it.
	 *
	 * @param string $candidate Filesystem path (the .minisig may not exist yet).
	 *
	 * @return string|null
	 */
	private function contain( $candidate ) {
		$root = realpath( apply_filters( 'srfe_signature_path_root', WP_CONTENT_DIR ) );
		$dir  = realpath( dirname( $candidate ) );

		if ( false === $root || false === $dir ) {
			return null;
		}

		if ( 0 !== strpos( $dir . DIRECTORY_SEPARATOR, $root . DIRECTORY_SEPARATOR ) ) {
			return null; // Outside the containment root — refuse.
		}

		return $dir . DIRECTORY_SEPARATOR . basename( $candidate );
	}

	/**
	 * Last recorded discovery status for a download.
	 *
	 * @param int $download_id Download (post) ID.
	 *
	 * @return string Last discovery status (STATUS_* ), '' if never run.
	 */
	public function status( $download_id ) {
		return (string) get_post_meta( $download_id, self::META_STATUS, true );
	}

	/**
	 * Record the discovery status for the admin UI.
	 *
	 * @param int    $download_id Download (post) ID.
	 * @param string $status      One of the STATUS_* constants.
	 */
	private function set_status( $download_id, $status ) {
		update_post_meta( $download_id, self::META_STATUS, $status );
	}

	/**
	 * Cheap structural check before caching/serving: 4 lines, comment
	 * prefixes, base64 payload of the right length (74 bytes).
	 *
	 * @param string $contents Candidate file contents.
	 *
	 * @return bool
	 */
	public function looks_like_minisig( $contents ) {
		if ( strlen( $contents ) > 8192 ) {
			return false;
		}

		$lines = $this->lines( $contents );

		if ( count( $lines ) < 4 || 0 !== strpos( $lines[0], 'untrusted comment:' ) || 0 !== strpos( $lines[2], 'trusted comment:' ) ) {
			return false;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding a minisign signature payload for structural validation, not executing anything.
		$payload = base64_decode( $lines[1], true );

		return false !== $payload && 74 === strlen( $payload );
	}

	/**
	 * Key ID (minisign display form) from a signature, for API metadata.
	 *
	 * @param string $minisig Full .minisig text.
	 *
	 * @return string|null Uppercase hex key ID.
	 */
	public function key_id( $minisig ) {
		$lines = $this->lines( $minisig );

		if ( ! isset( $lines[1] ) ) {
			return null;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Extracting the key ID bytes from a minisign signature payload.
		$payload = base64_decode( $lines[1], true );

		if ( false === $payload || strlen( $payload ) < 10 ) {
			return null;
		}

		return strtoupper( bin2hex( strrev( substr( $payload, 2, 8 ) ) ) );
	}

	/**
	 * Non-empty trimmed lines. (Deliberate callback: plain array_filter
	 * would also drop a line of just "0".)
	 *
	 * @param string $text Raw file contents.
	 *
	 * @return string[]
	 */
	private function lines( $text ) {
		return array_values(
			array_filter(
				array_map( 'trim', preg_split( '/\R/', $text ) ),
				static function ( $line ) {
					return '' !== $line;
				}
			)
		);
	}

	/**
	 * The download's current Software Licensing version.
	 *
	 * @param int $download_id Download (post) ID.
	 *
	 * @return string Current Software Licensing version, '' when unset.
	 */
	public function current_version( $download_id ) {
		return (string) get_post_meta( $download_id, '_edd_sl_version', true );
	}

	/**
	 * The plugin slug explicitly mapped to this download, if any.
	 *
	 * @param int $download_id Download (post) ID.
	 *
	 * @return string Plugin slug, '' when unmapped.
	 */
	public function plugin_slug( $download_id ) {
		return (string) get_post_meta( $download_id, self::META_PLUGIN_SLUG, true );
	}

	/**
	 * Map a download to the plugin slug its updater is configured with.
	 *
	 * The store's post_name (what the slug-only endpoint fallback resolves
	 * against) is set independently by the shop admin — often for SEO/URL
	 * reasons — and frequently differs from the signed plugin's actual slug.
	 * An explicit mapping makes slug-only lookups reliable instead of a
	 * coincidence that only works when the two happen to match.
	 *
	 * A slug already mapped to a different, still-existing download is
	 * refused: the index is what the public endpoint trusts to resolve
	 * slug-only lookups, so letting any product's editor silently take over
	 * another product's slug would let them re-point that plugin's signature
	 * serving (a cross-product update-blocking denial of service, since the
	 * hijacked signature can never verify the other plugin's files).
	 *
	 * @param int    $download_id Download (post) ID.
	 * @param string $slug        Sanitized plugin slug; '' clears the mapping.
	 *
	 * @return bool True when saved (or cleared); false when the slug is
	 *              already claimed by another download.
	 */
	public function set_plugin_slug( $download_id, $slug ) {
		$index    = $this->slug_index();
		$previous = $this->plugin_slug( $download_id );

		if ( '' !== $slug ) {
			$holder = isset( $index[ $slug ] ) ? (int) $index[ $slug ] : 0;

			if ( $holder > 0 && $holder !== (int) $download_id && 'download' === get_post_type( $holder ) ) {
				return false; // Claimed by another live download — refuse.
			}
		}

		if ( '' !== $previous ) {
			unset( $index[ $previous ] );
		}

		if ( '' === $slug ) {
			delete_post_meta( $download_id, self::META_PLUGIN_SLUG );
		} else {
			update_post_meta( $download_id, self::META_PLUGIN_SLUG, $slug );
			$index[ $slug ] = (int) $download_id;
		}

		update_option( self::OPTION_SLUG_INDEX, $index );

		return true;
	}

	/**
	 * Resolve a download by its explicitly-mapped plugin slug.
	 *
	 * @param string $slug Plugin slug.
	 *
	 * @return int Download (post) ID, 0 when unmapped.
	 */
	public function find_by_plugin_slug( $slug ) {
		$index = $this->slug_index();

		return isset( $index[ $slug ] ) ? (int) $index[ $slug ] : 0;
	}

	/**
	 * The slug => download_id index backing find_by_plugin_slug().
	 *
	 * @return array<string, int>
	 */
	private function slug_index() {
		$index = get_option( self::OPTION_SLUG_INDEX, [] );

		return is_array( $index ) ? $index : [];
	}

	/**
	 * The full per-version signature archive for a download.
	 *
	 * @param int $download_id Download (post) ID.
	 *
	 * @return array<string, string> version => minisig text.
	 */
	public function archive( $download_id ) {
		$archive = get_post_meta( $download_id, self::META_ARCHIVE, true );

		return is_array( $archive ) ? $archive : [];
	}

	/**
	 * Store a version's signature in the archive, pruning the oldest past
	 * the ARCHIVE_LIMIT.
	 *
	 * @param int    $download_id Download (post) ID.
	 * @param string $version     Version the signature covers.
	 * @param string $minisig     Full .minisig text.
	 */
	public function archive_signature( $download_id, $version, $minisig ) {
		$archive             = $this->archive( $download_id );
		$archive[ $version ] = $minisig;

		if ( count( $archive ) > self::ARCHIVE_LIMIT ) {
			// Keep the newest versions, not merely the last-inserted keys.
			uksort( $archive, 'version_compare' );
			$archive = array_slice( $archive, -self::ARCHIVE_LIMIT, null, true );
		}

		update_post_meta( $download_id, self::META_ARCHIVE, $archive );
	}
}
