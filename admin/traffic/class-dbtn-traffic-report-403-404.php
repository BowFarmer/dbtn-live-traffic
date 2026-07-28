<?php
/**
 * 403/404 report for yesterday's gzipped access log.
 *
 * Self-contained: depends only on DBTN_Traffic_Log_Reader within this module.
 * (Formerly dbtn\Admin\DBTN_Admin_Log_403_404_Parser.)
 *
 * @package DBTN_Subscriber
 */

declare( strict_types=1 );

namespace dbtn\Admin\Traffic;

defined( 'ABSPATH' ) || exit;

/**
 * Tallies 403/404 responses by URL from access.log.1.gz.
 */
final class DBTN_Traffic_Report_403_404 {

	/**
	 * Render the 403/404 report.
	 *
	 * @param string $log_path Path to access.log.1.gz.
	 * @return string Rendered HTML.
	 */
	public static function render( string $log_path ): string {
		if ( ! is_readable( $log_path ) ) {
			return '<p class="dbtn-lt-error">Cannot read access log at <code>' . esc_html( $log_path ) . '</code>.</p>';
		}

		$mtime = filemtime( $log_path );
		$size  = filesize( $log_path );

		$cache_key = 'dbtn_log_403_404_' . md5( $log_path . '|' . (string) $mtime . '|' . (string) $size );
		$cached    = get_transient( $cache_key );

		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$counts = self::parse_counts( $log_path );
		$html   = self::render_counts_table( $counts, $mtime );

		set_transient( $cache_key, $html, 2 * DAY_IN_SECONDS );

		return $html;
	}

	/**
	 * Parse counts of 403/404 URLs from a gzipped access log.
	 *
	 * @param string $log_path Path to access.log.1.gz.
	 * @return array{403: array<string, array{count: int, hosts: array<string, int>, has_non_canonical_host: bool}>, 404: array<string, array{count: int, hosts: array<string, int>, has_non_canonical_host: bool}>}
	 */
	private static function parse_counts( string $log_path ): array {
		$counts = array(
			403 => array(),
			404 => array(),
		);

		$handle = gzopen( $log_path, 'rb' );

		if ( false === $handle ) {
			return $counts;
		}

		while ( ! gzeof( $handle ) ) {
			$line = gzgets( $handle );

			if ( false === $line ) {
				continue;
			}

			$parsed = DBTN_Traffic_Log_Reader::parse_access_log_line( trim( $line ) );

			if ( null === $parsed ) {
				continue;
			}

			$status = (int) ( $parsed['status'] ?? 0 );

			if ( 403 !== $status && 404 !== $status ) {
				continue;
			}

			$url = is_string( $parsed['path'] ?? null ) ? $parsed['path'] : '';

			if ( '' === $url ) {
				$url = '(blank request path)';
			}

			$host = is_string( $parsed['host'] ?? null ) ? $parsed['host'] : '';
			$host = self::normalize_host( $host );

			if ( '' === $host ) {
				$host = '(unknown host)';
			}

			if ( ! isset( $counts[ $status ][ $url ] ) ) {
				$counts[ $status ][ $url ] = array(
					'count'                  => 0,
					'hosts'                  => array(),
					'has_non_canonical_host' => false,
				);
			}

			++$counts[ $status ][ $url ]['count'];

			if ( ! isset( $counts[ $status ][ $url ]['hosts'][ $host ] ) ) {
				$counts[ $status ][ $url ]['hosts'][ $host ] = 0;
			}

			++$counts[ $status ][ $url ]['hosts'][ $host ];

			if ( ! self::is_canonical_host( $host ) ) {
				$counts[ $status ][ $url ]['has_non_canonical_host'] = true;
			}
		}

		gzclose( $handle );

		ksort( $counts[403], SORT_NATURAL | SORT_FLAG_CASE );
		ksort( $counts[404], SORT_NATURAL | SORT_FLAG_CASE );

		return $counts;
	}

	/**
	 * Sum parsed record counts.
	 *
	 * @param array<string, array{count: int, hosts: array<string, int>, has_non_canonical_host: bool}> $items Parsed items.
	 * @return int
	 */
	private static function sum_counts( array $items ): int {
		$total = 0;

		foreach ( $items as $item ) {
			$total += (int) ( $item['count'] ?? 0 );
		}

		return $total;
	}

	/**
	 * Format host counts for display.
	 *
	 * @param array<string, int> $hosts Host counts.
	 * @return string
	 */
	private static function format_hosts( array $hosts ): string {
		if ( empty( $hosts ) ) {
			return '';
		}

		arsort( $hosts, SORT_NUMERIC );

		$parts = array();

		foreach ( $hosts as $host => $count ) {
			$parts[] = sprintf(
				'%s (%s)',
				(string) $host,
				number_format_i18n( (int) $count )
			);
		}

		return implode( ', ', $parts );
	}

	/**
	 * Normalize a request host for comparison/display.
	 *
	 * @param string $host Host value from the access log.
	 * @return string
	 */
	private static function normalize_host( string $host ): string {
		$host = strtolower( trim( $host ) );
		$host = preg_replace( '/:\d+$/', '', $host );
		$host = rtrim( (string) $host, '.' );

		return $host;
	}

	/**
	 * Check whether a request host is canonical for this site.
	 *
	 * @param string $host Request host.
	 * @return bool
	 */
	private static function is_canonical_host( string $host ): bool {
		$parsed    = wp_parse_url( site_url() );
		$base      = isset( $parsed['host'] ) ? strtolower( (string) $parsed['host'] ) : '';
		$base      = ltrim( $base, 'www.' );
		$canonical = array_filter( array( $base, 'www.' . $base ) );

		return in_array( $host, $canonical, true );
	}

	/**
	 * Render the 403/404 counts table.
	 *
	 * @param array{403: array<string, array{count: int, hosts: array<string, int>, has_non_canonical_host: bool}>, 404: array<string, array{count: int, hosts: array<string, int>, has_non_canonical_host: bool}>} $counts Parsed counts.
	 * @param int|false                                                                                                                                                                                             $mtime  Log modified time.
	 * @return string Rendered HTML.
	 */
	private static function render_counts_table( array $counts, int|false $mtime ): string {
		$total_403 = self::sum_counts( $counts[403] );
		$total_404 = self::sum_counts( $counts[404] );
		$total     = $total_403 + $total_404;

		ob_start();
		?>
		<style>
			.dbtn-lt-report-403-404 tr.dbtn-lt-report-bad-host td {
				background: #fff8e5;
			}

			.dbtn-lt-report-403-404 tr.dbtn-lt-report-bad-host td:first-child {
				border-left: 4px solid #dba617;
			}
		</style>
		<div class="dbtn-lt-report dbtn-lt-report-403-404">
			<p class="dbtn-lt-report-summary">
				<?php
					$modified = ( false !== $mtime ) ? wp_date( 'Y-m-d H:i:s T', $mtime ) : false;
					$modified = ( false !== $modified ) ? $modified : 'unknown';
				?>
				Source: <code>access.log.1.gz</code>, modified <?php echo esc_html( $modified ); ?>.<br>
				Found <?php echo esc_html( number_format_i18n( $total ) ); ?> total records:
				<?php echo esc_html( number_format_i18n( $total_403 ) ); ?> 403 and
				<?php echo esc_html( number_format_i18n( $total_404 ) ); ?> 404.
			</p>

			<?php if ( 0 === $total ) : ?>
				<p>No 403 or 404 records found in <code>access.log.1.gz</code>.</p>
			<?php else : ?>
				<table class="widefat striped dbtn-lt-report-table dbtn-lt-403-404-table">
					<thead>
						<tr>
							<th>Status</th>
							<th>Count</th>
							<th>URL</th>
							<th>Hosts</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( array( 403, 404 ) as $status ) : ?>
							<?php foreach ( $counts[ $status ] as $url => $data ) : ?>
								<?php
									$row_class = ! empty( $data['has_non_canonical_host'] ) ? 'dbtn-lt-report-bad-host' : '';
									$hosts     = self::format_hosts( $data['hosts'] ?? array() );
								?>
								<tr class="<?php echo esc_attr( $row_class ); ?>">
									<td><span class="dbtn-lt-http-status <?php echo esc_attr( DBTN_Traffic_Log_Reader::status_class( (string) $status ) ); ?>"><?php echo esc_html( (string) $status ); ?></span></td>
									<td><?php echo esc_html( number_format_i18n( (int) ( $data['count'] ?? 0 ) ) ); ?></td>
									<td class="dbtn-lt-col-path" title="<?php echo esc_attr( $url ); ?>"><?php echo esc_html( $url ); ?></td>
									<td class="dbtn-lt-col-host" title="<?php echo esc_attr( $hosts ); ?>"><?php echo esc_html( $hosts ); ?></td>
								</tr>
							<?php endforeach; ?>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php

		return (string) ob_get_clean();
	}
}
