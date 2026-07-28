<?php
/**
 * Keeps the MaxMind GeoLite2 City database up to date.
 *
 * Expected constants:
 * DBTN_GEOIP_DIR, DBTN_GEOIP_AID, DBTN_GEOIP_LKY, DBTN_GEOIP_EID.
 *
 * @package DBTNSubscriber
 */

namespace dbtn\Support;

use DateTimeImmutable;
use DateTimeZone;
use PharData;
use RuntimeException;
use Throwable;

/**
 * Manages installation and scheduled updates of the MaxMind GeoLite2 City database.
 */
final class DBTN_Geoip_Update {

	private const CRON_HOOK              = 'dbtn_geoip_weekly_update';
	private const CRON_SCHEDULE          = 'dbtn_geoip_weekly';
	private const DATABASE_FILENAME      = 'GeoLite2-City.mmdb';
	private const UPDATE_CHECK_TRANSIENT = 'dbtn_geoip_update_available';

	/** Register this method from the plugin bootstrap after loading the class. */
	public static function init(): void {
		if ( ! function_exists( 'add_action' ) ) {
			return;
		}

		add_filter( 'cron_schedules', array( self::class, 'add_weekly_schedule' ) );
		add_action( self::CRON_HOOK, array( self::class, 'cron_update' ) );
		add_action( 'init', array( self::class, 'schedule_update' ) );
	}

	/**
	 * Adds the weekly GeoIP update interval to WordPress cron schedules.
	 *
	 * @param array<string, array<string, mixed>> $schedules Existing cron schedules.
	 * @return array<string, array<string, mixed>> Filtered cron schedules.
	 */
	public static function add_weekly_schedule( array $schedules ): array {
		if ( ! isset( $schedules[ self::CRON_SCHEDULE ] ) ) {
			$schedules[ self::CRON_SCHEDULE ] = array(
				'interval' => 7 * DAY_IN_SECONDS,
				'display'  => 'Once Weekly (DBTN GeoIP)',
			);
		}

		return $schedules;
	}

	/**
	 * Schedules the recurring GeoIP update event when it is not already scheduled.
	 */
	public static function schedule_update(): void {
		if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, self::CRON_SCHEDULE, self::CRON_HOOK );
		}
	}

	/**
	 * Removes all scheduled GeoIP update events.
	 */
	public static function unschedule_update(): void {
		if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
		}
	}

	/**
	 * Determines whether a readable, non-empty GeoLite2 City database exists.
	 *
	 * @return bool True when the database exists and is readable; otherwise false.
	 */
	public static function database_exists(): bool {
		$path = self::database_path();

		return is_file( $path ) && is_readable( $path ) && filesize( $path ) > 0;
	}

	/**
	 * Returns the MMDB build date in YYYY-MM-DD format, or null if absent.
	 * maxmind-db/reader is used when available; filemtime is the fallback.
	 *
	 * @return string|null Database build date, or null when no database exists.
	 */
	public static function get_version(): ?string {
		if ( ! self::database_exists() ) {
			return null;
		}

		$path = self::database_path();

		if ( class_exists( 'MaxMind\\Db\\Reader' ) ) {
			try {
				$reader   = new \MaxMind\Db\Reader( $path );
				$metadata = $reader->metadata();
				$reader->close();

				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Third-party MaxMind metadata property.
				if ( ! empty( $metadata->buildEpoch ) ) {
					// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Third-party MaxMind metadata property.
					return ( new DateTimeImmutable( '@' . (int) $metadata->buildEpoch ) )
						->setTimezone( new DateTimeZone( 'UTC' ) )
						->format( 'Y-m-d' );
				}
			} catch ( Throwable $exception ) {
				unset( $exception );
				// Fall through to the filesystem date for installations without a readable metadata API.
			}
		}

		$modified = filemtime( $path );

		return false === $modified ? null : gmdate( 'Y-m-d', $modified );
	}

	/**
	 * Queues an immediate database update unless one is already imminent.
	 *
	 * The regular weekly event remains scheduled.
	 *
	 * @return bool True when a new event was scheduled; otherwise false.
	 */
	public static function queue_update(): bool {
		$next = wp_next_scheduled( self::CRON_HOOK );

		// Avoid adding duplicates when an update will run shortly.
		if (
			false !== $next
			&& $next <= time() + ( 5 * MINUTE_IN_SECONDS )
		) {
			return false;
		}

		$result = wp_schedule_single_event(
			time() + 10,
			self::CRON_HOOK,
			array(),
			true
		);

		if ( is_wp_error( $result ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Scheduling failures require server-side logging.
			error_log(
				'Could not schedule DBTN GeoIP update: '
				. $result->get_error_message()
			);

			return false;
		}

		return true === $result;
	}

	/**
	 * Checks MaxMind's Last-Modified header without downloading the archive.
	 *
	 * @return bool True when a newer database is available; otherwise false.
	 * @throws RuntimeException When MaxMind cannot be reached or rejects credentials.
	 */
	public static function has_newer_version(): bool {
		if ( ! self::database_exists() ) {
			return true;
		}

		$cached = get_transient( self::UPDATE_CHECK_TRANSIENT );

		if ( 'yes' === $cached ) {
			return true;
		}

		if ( 'no' === $cached ) {
			return false;
		}

		self::require_wordpress_http();

		$response = wp_remote_head(
			self::download_url(),
			array(
				'timeout'     => 20,
				'redirection' => 0,
				'user-agent'  => 'DBTN-GeoIP-Updater/1.0',
				'headers'     => self::authentication_headers(),
			)
		);

		self::assert_http_success( $response );

		$last_modified = wp_remote_retrieve_header(
			$response,
			'last-modified'
		);

		if (
			! is_string( $last_modified )
			|| '' === $last_modified
		) {
			throw new RuntimeException(
				'MaxMind did not provide a Last-Modified header.'
			);
		}

		$remote_time = strtotime( $last_modified );
		$local_time  = filemtime( self::database_path() );

		if ( false === $remote_time || false === $local_time ) {
			throw new RuntimeException(
				'Could not compare local and remote database dates.'
			);
		}

		$available = $remote_time > $local_time;

		set_transient(
			self::UPDATE_CHECK_TRANSIENT,
			$available ? 'yes' : 'no',
			15 * MINUTE_IN_SECONDS
		);

		return $available;
	}

	/**
	 * Resolves MaxMind's authenticated endpoint to its signed download URL.
	 *
	 * @return string HTTPS URL for the database archive.
	 * @throws RuntimeException When the request fails or no safe redirect is returned.
	 */
	private static function resolve_download_url(): string {
		$response = wp_remote_get(
			self::download_url(),
			array(
				'timeout'     => 20,
				'redirection' => 0,
				'user-agent'  => 'DBTN-GeoIP-Updater/1.0',
				'headers'     => self::authentication_headers(),
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new RuntimeException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is not rendered as HTML.
				'MaxMind request failed: ' . $response->get_error_message()
			);
		}

		$status   = wp_remote_retrieve_response_code( $response );
		$location = wp_remote_retrieve_header( $response, 'location' );

		if (
			300 > $status
			|| 400 <= $status
			|| ! is_string( $location )
			|| '' === $location
		) {
			self::assert_http_success( $response );

			throw new RuntimeException(
				'MaxMind did not provide a database download redirect.'
			);
		}

		if ( 'https' !== wp_parse_url( $location, PHP_URL_SCHEME ) ) {
			throw new RuntimeException(
				'MaxMind returned an unsafe download URL.'
			);
		}

		return $location;
	}

	/**
	 * Downloads and atomically installs the database only when needed.
	 *
	 * @return bool True when a new database was installed; otherwise false.
	 * @throws RuntimeException On configuration, download, archive, or filesystem errors.
	 */
	public static function update(): bool {
		if ( self::database_exists() && ! self::has_newer_version() ) {
			return false;
		}

		self::require_wordpress_http();
		$directory = self::database_directory();
		if ( ! is_dir( $directory ) && ! wp_mkdir_p( $directory ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is not rendered as HTML.
			throw new RuntimeException( 'Could not create GeoIP directory: ' . $directory );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- A direct writability check is required before atomic installation.
		if ( ! is_writable( $directory ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is not rendered as HTML.
			throw new RuntimeException( 'GeoIP directory is not writable: ' . $directory );
		}

		if ( ! function_exists( 'wp_tempnam' ) ) {
			// @phpstan-ignore-next-line -- WordPress defines ABSPATH before loading plugins.
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$temporary = \wp_tempnam( 'dbtn-geoip' );
		if ( ! $temporary ) {
			throw new RuntimeException( 'Could not create a temporary archive file.' );
		}
		$archive = $temporary . '.tar.gz';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- A local atomic filesystem operation is required.
		if ( ! rename( $temporary, $archive ) ) {
			wp_delete_file( $temporary );
			throw new RuntimeException( 'Could not prepare the temporary archive file.' );
		}

		$extract_dir = $archive . '-extracted';
		$tar_file    = substr( $archive, 0, -3 );
		try {
			$response = wp_remote_get(
				self::resolve_download_url(),
				array(
					'timeout'     => 120,
					'redirection' => 5,
					'stream'      => true,
					'filename'    => $archive,
					'user-agent'  => 'DBTN-GeoIP-Updater/1.0',
				)
			);
			self::assert_http_success( $response );

			if ( ! wp_mkdir_p( $extract_dir ) ) {
				throw new RuntimeException( 'Could not create the temporary extraction directory.' );
			}

			try {
				$compressed = new PharData( $archive );
				$compressed->decompress();
				$tar = new PharData( $tar_file );
				$tar->extractTo( $extract_dir, null, true );
			} catch ( Throwable $exception ) {
				throw new RuntimeException( 'Could not extract MaxMind archive: ' . $exception->getMessage(), 0, $exception );
			}

			$matches = glob( $extract_dir . '/*/' . self::DATABASE_FILENAME );
			if ( ! is_array( $matches ) || count( $matches ) !== 1 || ! self::is_mmdb( $matches[0] ) ) {
				throw new RuntimeException( 'The MaxMind archive did not contain a valid GeoLite2 City MMDB file.' );
			}

			$staged = $directory . DIRECTORY_SEPARATOR . '.' . self::DATABASE_FILENAME . '.new';
			if ( ! copy( $matches[0], $staged ) ) {
				throw new RuntimeException( 'Could not stage the downloaded database.' );
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Sets safe permissions on the staged local database.
			chmod( $staged, 0644 );

			// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- rename() provides atomic replacement on the local filesystem.
			if ( ! rename( $staged, self::database_path() ) ) {
				wp_delete_file( $staged );
				throw new RuntimeException( 'Could not replace the existing GeoIP database.' );
			}

			delete_transient( self::UPDATE_CHECK_TRANSIENT );

			$email_alert = self::DATABASE_FILENAME . ' updated successfully.';
			DBTN_Emails::send_email_to_admin( self::DATABASE_FILENAME . ' updated', $email_alert );

			return true;

		} finally {
			wp_delete_file( $archive );
			wp_delete_file( $tar_file );
			self::remove_directory( $extract_dir );
		}
	}

	/**
	 * Runs the database update from WP-Cron without allowing exceptions to escape.
	 */
	public static function cron_update(): void {
		try {
			self::update();
		} catch ( Throwable $exception ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Cron failures require server-side logging.
			error_log( 'DBTN GeoIP update failed: ' . $exception->getMessage() );
		}
	}

	/**
	 * Gets the absolute path to the installed database.
	 *
	 * @return string Absolute database path.
	 */
	private static function database_path(): string {
		return self::database_directory() . DIRECTORY_SEPARATOR . self::DATABASE_FILENAME;
	}

	/**
	 * Gets the configured database directory.
	 *
	 * @return string Absolute database directory without a trailing separator.
	 * @throws RuntimeException When the directory constant is missing or empty.
	 */
	private static function database_directory(): string {
		$directory = self::configuration( 'DBTN_GEOIP_DIR' );

		return rtrim( $directory, '/\\' );
	}

	/**
	 * Builds the authenticated MaxMind database download endpoint.
	 *
	 * @return string MaxMind database download URL.
	 * @throws RuntimeException When the edition constant is missing or empty.
	 */
	private static function download_url(): string {
		$edition = self::configuration( 'DBTN_GEOIP_EID' );
		$query   = http_build_query( array( 'suffix' => 'tar.gz' ), '', '&', PHP_QUERY_RFC3986 );

		return 'https://download.maxmind.com/geoip/databases/'
			. rawurlencode( $edition )
			. '/download?'
			. $query;
	}

	/**
	 * Builds the HTTP Basic Authentication header for MaxMind requests.
	 *
	 * @return array<string, string> Request headers keyed by header name.
	 * @throws RuntimeException When an authentication constant is missing or empty.
	 */
	private static function authentication_headers(): array {
		$credentials = self::configuration( 'DBTN_GEOIP_AID' )
			. ':'
			. self::configuration( 'DBTN_GEOIP_LKY' );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic Authentication requires Base64 encoding.
		return array( 'Authorization' => 'Basic ' . base64_encode( $credentials ) );
	}

	/**
	 * Reads and validates a required configuration constant.
	 *
	 * @param string $name Constant name.
	 * @return string Non-empty constant value.
	 * @throws RuntimeException When the requested constant is missing or empty.
	 */
	private static function configuration( string $name ): string {
		if ( ! defined( $name ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is not rendered as HTML.
			throw new RuntimeException( 'Required constant is not defined: ' . $name );
		}

		$value = trim( (string) constant( $name ) );
		if ( '' === $value ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is not rendered as HTML.
			throw new RuntimeException( 'Required constant is empty: ' . $name );
		}

		return $value;
	}

	/**
	 * Ensures that the required WordPress HTTP API functions are available.
	 *
	 * @throws RuntimeException When WordPress HTTP functions are unavailable.
	 */
	private static function require_wordpress_http(): void {
		if ( ! function_exists( 'wp_remote_get' ) || ! function_exists( 'wp_remote_head' ) ) {
			throw new RuntimeException( 'DBTN_Geoip must run in a loaded WordPress environment.' );
		}
	}

	/**
	 * Verifies that a WordPress HTTP response was successful.
	 *
	 * @param array<string, mixed>|\WP_Error $response WordPress HTTP response.
	 * @throws RuntimeException When the request failed or returned a non-success status.
	 */
	private static function assert_http_success( $response ): void {
		if ( is_wp_error( $response ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is not rendered as HTML.
			throw new RuntimeException( 'MaxMind request failed: ' . $response->get_error_message() );
		}

		$status = wp_remote_retrieve_response_code( $response );
		if ( 200 > $status || 300 <= $status ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is not rendered as HTML.
			throw new RuntimeException( 'MaxMind request returned HTTP ' . $status . '.' );
		}
	}

	/**
	 * Validates that a file contains the MaxMind MMDB metadata marker.
	 *
	 * @param string $path Absolute path to the candidate database.
	 * @return bool True when the file appears to be a valid MMDB database.
	 */
	private static function is_mmdb( string $path ): bool {
		if ( ! is_file( $path ) || filesize( $path ) < 1024 ) {
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Tail inspection requires a seekable stream.
		$handle = fopen( $path, 'rb' );
		if ( ! $handle ) {
			return false;
		}

		fseek( $handle, -min( (int) filesize( $path ), 131072 ), SEEK_END );
		$tail = stream_get_contents( $handle );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the stream opened above.
		fclose( $handle );

		return is_string( $tail ) && strpos( $tail, "\xAB\xCD\xEFMaxMind.com" ) !== false;
	}

	/**
	 * Recursively removes a temporary extraction directory.
	 *
	 * @param string $directory Absolute directory path to remove.
	 */
	private static function remove_directory( string $directory ): void {
		if ( ! is_dir( $directory ) ) {
			return;
		}

		$items = scandir( $directory );
		if ( ! is_array( $items ) ) {
			return;
		}

		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $directory . DIRECTORY_SEPARATOR . $item;
			is_dir( $path ) ? self::remove_directory( $path ) : wp_delete_file( $path );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Removes the now-empty temporary directory.
		rmdir( $directory );
	}
}
