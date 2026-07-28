<?php
/**
 * Shared log reader for the Live Traffic module.
 *
 * The one piece every tab leans on: tailing files efficiently, parsing access
 * log lines, summarising user agents, and mapping HTTP codes to CSS classes.
 * Has no host dependencies, so the report tabs that use only this class are
 * fully self-contained.
 *
 * (Formerly dbtn\Admin\DBTN_Live_Traffic_Parser.)
 *
 * @package DBTN_Subscriber
 */

declare( strict_types=1 );

namespace dbtn\Admin\Traffic;

use DateTime;
use DateTimeZone;

defined( 'ABSPATH' ) || exit;

/**
 * Access-log reader and line parser.
 */
final class DBTN_Traffic_Log_Reader {

	/**
	 * Parse one access-log line.
	 *
	 * @param string $line Raw log line.
	 * @return array<string, string>|null Parsed row, or null on failure.
	 */
	public static function parse_access_log_line( string $line ): ?array {
		$pattern = '/^(\S+)\s+\S+\s+\S+\s+\[([^\]]+)\]\s+"([^"]*?)"\s+(\d+)\s+(\S+)\s+"([^"]*)"\s+"([^"]*)"\s+"[^"]*"\s+\[([^\]]+)\]\s+"[^"]*"\s+"[^"]*"\s+"([^"]*)"/';

		if ( ! preg_match( $pattern, $line, $m ) ) {
			return null;
		}

		[
			,
			$ip,
			$time_raw,
			$request,
			$status,
			$bytes,
			$referer,
			$ua,
			$duration,
			$host,
		] = $m;

		$host = self::normalize_host( $host );

		$ts           = 0;
		$time_display = $time_raw;
		$time_parsed  = \DateTime::createFromFormat( 'd/M/Y:H:i:s O', $time_raw );

		if ( $time_parsed ) {
			$ts = $time_parsed->getTimestamp();

			try {
				$la_tz = new \DateTimeZone( 'America/Los_Angeles' );
				$time_parsed->setTimezone( $la_tz );
				$time_display = $time_parsed->format( 'm/d H:i:s' );
			} catch ( \Exception $e ) {
				$time_display = $time_raw;
			}
		}

		$request_parts = explode( ' ', $request, 3 );
		$method        = $request_parts[0] ?? '';
		$path          = $request_parts[1] ?? $request;

		return array(
			'ip'       => $ip,
			'time_raw' => $time_display,
			'ts'       => (string) $ts,
			'method'   => $method,
			'path'     => $path,
			'host'     => $host,
			'status'   => $status,
			'bytes'    => $bytes,
			'duration' => $duration,
			'referer'  => ( '-' === $referer ) ? '' : $referer,
			'ua'       => $ua,
			'ua_short' => self::summarise_user_agent( $ua ),
		);
	}
	/**
	 * Normalize a request host captured from the access log.
	 *
	 * @param string $host Raw host field.
	 * @return string Normalized host.
	 */
	public static function normalize_host( string $host ): string {
		$host = strtolower( trim( $host ) );

		if ( '' === $host || '-' === $host ) {
			return '';
		}

		$host = preg_replace( '/:\d+$/', '', $host );

		return rtrim( (string) $host, '.' );
	}

	/**
	 * Check whether a request host is one of the canonical site hosts.
	 *
	 * Uses the site_url() hostname by default; also accepts www. variant.
	 *
	 * @param string $host Raw or normalized host.
	 * @return bool True when the host is canonical.
	 */
	public static function is_canonical_host( string $host ): bool {
		$host = self::normalize_host( $host );

		$parsed    = wp_parse_url( site_url() );
		$base      = isset( $parsed['host'] ) ? strtolower( (string) $parsed['host'] ) : '';
		$base      = ltrim( $base, 'www.' );
		$canonical = array_filter( array( $base, 'www.' . $base ) );

		return in_array( $host, $canonical, true );
	}

	/**
	 * Summarize a user-agent string.
	 *
	 * @param string $ua Full user-agent string.
	 * @return string Short label.
	 */
	public static function summarise_user_agent( string $ua ): string {
		if ( '' === $ua || '-' === $ua ) {
			return '—';
		}

		$bots = array(
			'Googlebot'           => 'Googlebot',
			'bingbot'             => 'Bingbot',
			'YandexBot'           => 'YandexBot',
			'DuckDuckBot'         => 'DuckDuckBot',
			'Baiduspider'         => 'Baiduspider',
			'Applebot-Extended'   => 'Applebot-Extended',
			'Applebot'            => 'Applebot',
			'facebookexternalhit' => 'Facebook',
			'Twitterbot'          => 'Twitterbot',
			'LinkedInBot'         => 'LinkedInBot',
			'AhrefsBot'           => 'AhrefsBot',
			'SemrushBot'          => 'SemrushBot',
			'MJ12bot'             => 'MJ12bot',
			'DotBot'              => 'DotBot',
			'PetalBot'            => 'PetalBot',
			'GPTBot'              => 'GPTBot',
			'ClaudeBot'           => 'ClaudeBot',
			'ia_archiver'         => 'Wayback',
			'archive.org_bot'     => 'Wayback',
			'curl'                => 'curl',
			'wget'                => 'wget',
			'python-requests'     => 'Python',
			'Go-http-client'      => 'Go HTTP',
			'Java/'               => 'Java',
			'Cloudflare'          => 'Cloudflare',
		);

		foreach ( $bots as $token => $label ) {
			if ( false !== stripos( $ua, $token ) ) {
				return $label;
			}
		}

		$browser = '';

		if ( false !== strpos( $ua, 'Edg/' ) || false !== strpos( $ua, 'Edge/' ) ) {
			$browser = 'Edge';
		} elseif ( false !== strpos( $ua, 'OPR/' ) || false !== strpos( $ua, 'Opera' ) ) {
			$browser = 'Opera';
		} elseif ( false !== strpos( $ua, 'Chrome/' ) ) {
			$browser = 'Chrome';
		} elseif ( false !== strpos( $ua, 'Firefox/' ) ) {
			$browser = 'Firefox';
		} elseif ( false !== strpos( $ua, 'Safari/' ) && false === strpos( $ua, 'Chrome' ) ) {
			$browser = 'Safari';
		} elseif ( false !== strpos( $ua, 'MSIE' ) || false !== strpos( $ua, 'Trident/' ) ) {
			$browser = 'IE';
		}

		$os = '';

		if ( false !== strpos( $ua, 'Windows' ) ) {
			$os = 'Windows';
		} elseif ( false !== strpos( $ua, 'iPhone' ) || false !== strpos( $ua, 'iPad' ) ) {
			$os = 'iOS';
		} elseif ( false !== strpos( $ua, 'Android' ) ) {
			$os = 'Android';
		} elseif ( false !== strpos( $ua, 'Macintosh' ) || false !== strpos( $ua, 'Mac OS X' ) ) {
			$os = 'macOS';
		} elseif ( false !== strpos( $ua, 'Linux' ) ) {
			$os = 'Linux';
		}

		if ( $browser && $os ) {
			return "{$browser} / {$os}";
		}

		if ( $browser ) {
			return $browser;
		}

		if ( $os ) {
			return $os;
		}

		$token = strtok( $ua, ' /' );

		return false !== $token ? $token : '?';
	}

	/**
	 * Return a CSS class for an HTTP status.
	 *
	 * @param string $status HTTP status code.
	 * @return string CSS class.
	 */
	public static function status_class( string $status ): string {
		$code = (int) $status;

		if ( $code >= 500 ) {
			return 'dbtn-lt-status-5xx';
		}

		if ( $code >= 400 ) {
			return 'dbtn-lt-status-4xx';
		}

		if ( $code >= 300 ) {
			return 'dbtn-lt-status-3xx';
		}

		if ( $code >= 200 ) {
			return 'dbtn-lt-status-2xx';
		}

		return 'dbtn-lt-status-other';
	}

	/**
	 * Read the last N lines of a file.
	 *
	 * @param string $filepath Path to file.
	 * @param int    $n        Number of lines.
	 * @return string[] Lines, oldest first.
	 */
	public static function tail_file( string $filepath, int $n ): array {
		if ( $n < 1 ) {
			return array();
		}

		$fp = fopen( $filepath, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $fp ) {
			return array();
		}
			fseek( $fp, 0, SEEK_END );
			$file_size = ftell( $fp );
		if ( 0 === $file_size ) {
			fclose( $fp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return array();
		}
			$chunk_size = 8192;
			$buffer     = '';
			$lines      = array();
			$line_count = 0;
			$pos        = $file_size;
		while ( $pos > 0 && $line_count < $n + 1 ) {
			$read_size = min( $chunk_size, $pos );
			$pos      -= $read_size;
			fseek( $fp, $pos );
			$chunk      = fread( $fp, $read_size ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			$buffer     = ( false !== $chunk ? $chunk : '' ) . $buffer;
			$lines      = explode( "\n", $buffer );
			$line_count = count( $lines );
		}
			fclose( $fp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		if ( '' === end( $lines ) ) {
			array_pop( $lines );
		}
			return array_slice( $lines, -$n );
	}

	/**
	 * Read a seamless tail across the daily access-log rotation.
	 *
	 * When access.log contains fewer than the requested number of lines, the
	 * missing older lines are taken from the end of access.log.1.gz. The last
	 * 5,000 archived lines are cached per archive version so the large gzip is
	 * scanned only once after each rotation.
	 *
	 * @param string $current_path Current access.log path.
	 * @param string $archive_path Rotated access.log.1.gz path.
	 * @param int    $n            Number of combined lines to return.
	 * @return string[] Lines, oldest first.
	 */
	public static function tail_rotated_access_log( string $current_path, string $archive_path, int $n ): array {
		if ( $n < 1 ) {
			return array();
		}

		$current_lines = self::tail_file( $current_path, $n );
		$missing       = $n - count( $current_lines );

		if ( $missing < 1 || ! is_readable( $archive_path ) ) {
			return $current_lines;
		}

		$archive_lines = self::tail_gzip_cached( $archive_path, 5000 );

		if ( empty( $archive_lines ) ) {
			return $current_lines;
		}

		return array_slice( array_merge( $archive_lines, $current_lines ), -$n );
	}

	/**
	 * Read and cache the last N lines of a gzip file.
	 *
	 * The cache signature changes when the archive's size or modification time
	 * changes. Cached text is compressed and base64-encoded so it is safe for
	 * both database-backed and persistent-object-cache transients.
	 *
	 * @param string $filepath Path to gzip file.
	 * @param int    $n        Number of lines.
	 * @return string[] Lines, oldest first.
	 */
	private static function tail_gzip_cached( string $filepath, int $n ): array {
		clearstatcache( true, $filepath );

		$file_stat = stat( $filepath );

		if ( false === $file_stat ) {
			return array();
		}

		$signature = hash(
			'sha256',
			implode(
				'|',
				array(
					$filepath,
					(string) $file_stat['size'],
					(string) $file_stat['mtime'],
					(string) $file_stat['ctime'],
					(string) $file_stat['ino'],
					(string) $n,
				)
			)
		);
		$cache_key = 'dbtn_lt_gz_tail_' . md5( $filepath . '|' . $n );
		$cached    = get_transient( $cache_key );

		if (
			is_array( $cached )
			&& isset( $cached['signature'], $cached['payload'] )
			&& hash_equals( $signature, (string) $cached['signature'] )
		) {
			$decoded = base64_decode( (string) $cached['payload'], true );
			$text    = false !== $decoded ? gzdecode( $decoded ) : false;

			if ( false !== $text ) {
				return self::split_cached_lines( $text, $n );
			}
		}

		$handle = gzopen( $filepath, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		if ( false === $handle ) {
			return array();
		}

		$lines      = array();
		$line_count = 0;

		while ( ! gzeof( $handle ) ) {
			$line = gzgets( $handle );

			if ( false === $line ) {
				continue;
			}

			$lines[ $line_count % $n ] = rtrim( $line, "\r\n" );
			++$line_count;
		}

		gzclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		if ( $line_count > $n ) {
			$start = $line_count % $n;
			$lines = array_merge(
				array_slice( $lines, $start, null, true ),
				array_slice( $lines, 0, $start, true )
			);
		}

		$lines      = array_values( $lines );
		$text       = implode( "\n", $lines );
		$compressed = gzencode( $text, 6 );

		if ( false !== $compressed ) {
			set_transient(
				$cache_key,
				array(
					'signature' => $signature,
					'payload'   => base64_encode( $compressed ),
				),
				2 * DAY_IN_SECONDS
			);
		}

		return $lines;
	}

	/**
	 * Restore cached log lines.
	 *
	 * @param string $text Cached newline-delimited text.
	 * @param int    $n    Maximum number of lines.
	 * @return string[] Lines, oldest first.
	 */
	private static function split_cached_lines( string $text, int $n ): array {
		if ( '' === $text ) {
			return array();
		}

		return array_slice( explode( "\n", $text ), -$n );
	}
}
