<?php
/**
 * WP-Cron log report.
 *
 * Parses wp-cron.log into timestamped cron runs with status and details.
 *
 * @package DBTN_Subscriber
 */

declare( strict_types=1 );

namespace dbtn\Admin\Traffic;

defined( 'ABSPATH' ) || exit;

/**
 * Renders recent wp-cron.log entries grouped by run timestamp.
 */
final class DBTN_Traffic_Report_WP_Cron {

	/**
	 * Render recent WP-Cron runs.
	 *
	 * @param string $log_path Path to wp-cron.log.
	 * @param int    $lines    Number of lines to tail before grouping.
	 * @return string Rendered HTML.
	 */
	public static function render( string $log_path, int $lines = 5000 ): string {
		if ( ! is_readable( $log_path ) ) {
			return '<p class="dbtn-lt-error">Cannot read WP-Cron log at <code>' . esc_html( $log_path ) . '</code>.</p>';
		}

		$lines   = max( 100, min( 50000, $lines ) );
		$raw     = DBTN_Traffic_Log_Reader::tail_file( $log_path, $lines );
		$entries = self::split_entries( $raw );

		if ( empty( $entries ) ) {
			return '<p>WP-Cron log is empty or no timestamped entries were found.</p>';
		}

		$entries = array_reverse( $entries );

		ob_start();
		?>
		<div class="dbtn-lt-report dbtn-lt-report-wp-cron">
			<p class="dbtn-lt-report-summary">
				Source: <code>wp-cron.log</code>. Showing the most recent cron runs found in the last <?php echo esc_html( number_format_i18n( $lines ) ); ?> tailed lines.
			</p>

			<div class="dbtn-lt-cron-grid" role="table" aria-label="WP-Cron log">
				<div class="dbtn-lt-cron-head dbtn-lt-cron-time" role="columnheader">Time</div>
				<div class="dbtn-lt-cron-head dbtn-lt-cron-status" role="columnheader">Status</div>
				<div class="dbtn-lt-cron-head dbtn-lt-cron-details" role="columnheader">Details</div>

				<?php foreach ( $entries as $entry ) : ?>
					<div class="dbtn-lt-cron-row" role="row">
						<div class="dbtn-lt-cron-cell dbtn-lt-cron-time" role="cell">
							<?php echo esc_html( $entry['time'] ); ?>
						</div>
						<div class="dbtn-lt-cron-cell dbtn-lt-cron-status" role="cell">
							<span class="dbtn-lt-cron-status-badge <?php echo esc_attr( $entry['status_class'] ); ?>">
								<?php echo esc_html( $entry['status'] ); ?>
							</span>
						</div>
						<div class="dbtn-lt-cron-cell dbtn-lt-cron-details" role="cell">
							<?php if ( '' !== $entry['details'] ) : ?>
								<pre class="dbtn-lt-cron-pre"><?php echo esc_html( $entry['details'] ); ?></pre>
							<?php else : ?>
								<span class="dbtn-lt-muted">No details.</span>
							<?php endif; ?>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Split raw log lines into timestamped cron entries.
	 *
	 * Handles both normal entries:
	 *   2026-06-22 19:40:22 Running cron for site
	 * and blocked entries where the timestamp is on its own line followed by
	 *   Access terminated. Invalid host.Running cron for site
	 *
	 * @param string[] $lines Raw tailed lines, oldest first.
	 * @return array<int, array{time: string, status: string, status_class: string, details: string}>
	 */
	private static function split_entries( array $lines ): array {
		$entries = array();
		$current = null;

		foreach ( $lines as $line ) {
			$line = rtrim( $line, "\r\n" );

			if ( preg_match( '/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})(.*)$/', $line, $m ) ) {
				if ( is_array( $current ) ) {
					$entries[] = self::normalize_entry( $current );
				}

				$current = array(
					'time'  => $m[1],
					'lines' => array(),
				);

				$remainder = trim( $m[2] );

				if ( '' !== $remainder ) {
					$current['lines'][] = $remainder;
				}

				continue;
			}

			if ( is_array( $current ) ) {
				$current['lines'][] = $line;
			}
		}

		if ( is_array( $current ) ) {
			$entries[] = self::normalize_entry( $current );
		}

		return $entries;
	}

	/**
	 * Build display fields for one cron entry.
	 *
	 * @param array{time: string, lines: string[]} $entry Raw entry.
	 * @return array{time: string, status: string, status_class: string, details: string}
	 */
	private static function normalize_entry( array $entry ): array {
		$lines        = $entry['lines'];
		$status       = '';
		$status_class = 'dbtn-lt-cron-status-unknown';
		$details      = array();
		$full_text    = implode( "\n", $lines );

		foreach ( $lines as $line ) {
			$trimmed = trim( $line );

			if ( '' === $trimmed || 'Running cron for site' === $trimmed ) {
				continue;
			}

			if ( str_starts_with( $trimmed, 'Success:' ) ) {
				$status       = $trimmed;
				$status_class = 'dbtn-lt-cron-status-success';
				continue;
			}

			if ( '' === $status && str_starts_with( $trimmed, 'Error:' ) ) {
				$status       = $trimmed;
				$status_class = 'dbtn-lt-cron-status-error';
				continue;
			}

			$details[] = $line;
		}

		if ( '' === $status && str_contains( $full_text, 'Access terminated. Invalid host.' ) ) {
			$status       = 'Access terminated. Invalid host.';
			$status_class = 'dbtn-lt-cron-status-error';
		}

		if ( '' === $status ) {
			$status = 'No success line found.';
		}

		return array(
			'time'         => $entry['time'],
			'status'       => $status,
			'status_class' => $status_class,
			'details'      => trim( implode( "\n", $details ) ),
		);
	}
}
