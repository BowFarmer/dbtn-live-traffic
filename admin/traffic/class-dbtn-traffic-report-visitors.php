<?php
/**
 * Daily human visitor report.
 *
 * Reads the dbtn_human_visits_YYYY-MM-DD option records and renders the latest
 * dates as a sortable two-column table.
 *
 * @package Live_Traffic
 */

declare( strict_types=1 );

namespace dbtn\Admin\Traffic;

defined( 'ABSPATH' ) || exit;

/**
 * Renders daily validated-human visitor counts.
 */
final class DBTN_Traffic_Report_Visitors {

	/**
	 * Option-name prefix used by count_daily_human_visit().
	 */
	private const OPTION_PREFIX = 'dbtn_human_visits_';


	/**
	 * Get the current WordPress-day authenticated visitor count.
	 *
	 * @return int Current count.
	 */
	public static function get_today_count(): int {
		return max( 0, (int) get_option( self::OPTION_PREFIX . wp_date( 'Y-m-d' ), 0 ) );
	}

	/**
	 * Render the latest daily visitor-count records.
	 *
	 * @param int $limit Maximum number of records to return.
	 * @return string Rendered HTML.
	 */
	public static function render( int $limit = 100 ): string {
		global $wpdb;

		$limit        = max( 1, min( 100, $limit ) );
		$like_pattern = $wpdb->esc_like( self::OPTION_PREFIX ) . '%';

		// The options API cannot efficiently retrieve a prefix-matched, ordered,
		// limited set of option records, so this report intentionally queries the
		// options table directly.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$records = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value
				FROM {$wpdb->options}
				WHERE option_name LIKE %s
				ORDER BY option_name DESC
				LIMIT %d",
				$like_pattern,
				$limit
			),
			\ARRAY_A
		);

		if ( empty( $records ) ) {
			return '<p>No daily visitor counts have been recorded yet.</p>';
		}

		$rows = array();

		foreach ( $records as $record ) {
			$option_name = isset( $record['option_name'] ) ? (string) $record['option_name'] : '';
			$date        = substr( $option_name, strlen( self::OPTION_PREFIX ) );
			$parsed_date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date );

			if ( false === $parsed_date || $parsed_date->format( 'Y-m-d' ) !== $date ) {
				continue;
			}

			$rows[] = array(
				'date'  => $date,
				'count' => max( 0, (int) ( $record['option_value'] ?? 0 ) ),
			);
		}

		if ( empty( $rows ) ) {
			return '<p>No valid daily visitor-count records were found.</p>';
		}

		ob_start();
		?>
		<table class="dbtn-lt-table dbtn-lt-visitors-table" data-sort-column="date" data-sort-direction="desc">
			<thead>
				<tr>
					<th class="dbtn-lt-visitors-date">
						<button type="button" class="dbtn-lt-visitors-sort is-sorted-desc" data-sort-column="date">Date</button>
					</th>
					<th class="dbtn-lt-visitors-count">
						<button type="button" class="dbtn-lt-visitors-sort" data-sort-column="count">Count</button>
					</th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $rows as $row ) : ?>
				<tr data-date="<?php echo esc_attr( $row['date'] ); ?>" data-count="<?php echo esc_attr( (string) $row['count'] ); ?>">
					<td class="dbtn-lt-visitors-date"><?php echo esc_html( $row['date'] ); ?></td>
					<td class="dbtn-lt-visitors-count"><?php echo esc_html( number_format_i18n( $row['count'] ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php

		return (string) ob_get_clean();
	}
}
