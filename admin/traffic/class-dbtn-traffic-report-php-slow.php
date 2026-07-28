<?php
/**
 * PHP slow-log report (last N days).
 *
 * Self-contained: depends only on DBTN_Traffic_Log_Reader within this module.
 * (Formerly dbtn\Admin\DBTN_Admin_PHP_Slow_Parser.)
 *
 * @package DBTN_Subscriber
 */

declare( strict_types=1 );

namespace dbtn\Admin\Traffic;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

defined( 'ABSPATH' ) || exit;

/**
 * Renders recent php_slow.log entries grouped into timestamped blocks.
 */
final class DBTN_Traffic_Report_PHP_Slow {

	/**
	 * Render PHP slow-log entries from the last N days.
	 *
	 * @param string $log_path Path to php_slow.log.
	 * @param int    $days     Lookback period.
	 * @return string Rendered HTML.
	 */
	public static function render( string $log_path, int $days = 7 ): string {
		if ( ! is_readable( $log_path ) ) {
			return '<p class="dbtn-lt-error">Cannot read PHP slow log at <code>' . esc_html( $log_path ) . '</code>.</p>';
		}

		$days   = max( 1, min( 30, $days ) );
		$now    = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
		$cutoff = $now->modify( '-' . $days . ' days' );
		$lines  = DBTN_Traffic_Log_Reader::tail_file( $log_path, 20000 );
		$blocks = self::split_blocks( $lines );
		$recent = array();
		$last   = null;

		foreach ( $blocks as $block ) {
			$dt = $block['datetime'];

			if ( null !== $dt ) {
				$last = $block;

				if ( $dt >= $cutoff ) {
					$recent[] = $block;
				}
			}
		}

		ob_start();
		?>
		<div class="dbtn-lt-report dbtn-lt-report-php-slow">
			<p class="dbtn-lt-report-summary">
				Source: <code>php_slow.log</code>. Showing slow requests from the last <?php echo esc_html( (string) $days ); ?> days.
			</p>

			<?php if ( empty( $recent ) ) : ?>
				<p>No PHP slow-log entries found in the last <?php echo esc_html( (string) $days ); ?> days.</p>
				<?php if ( is_array( $last ) ) : ?>
					<p>Last slow-log entry found: <?php echo esc_html( $last['title'] ); ?></p>
				<?php endif; ?>
			<?php else : ?>
				<?php foreach ( array_reverse( $recent ) as $block ) : ?>
					<details class="dbtn-lt-slow-block" open>
						<summary><?php echo esc_html( $block['title'] ); ?></summary>
						<pre class="dbtn-lt-log-pre"><?php echo esc_html( implode( "\n", $block['lines'] ) ); ?></pre>
					</details>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Split php_slow.log into timestamped blocks.
	 *
	 * @param string[] $lines Log lines.
	 * @return array<int, array{title: string, datetime: DateTimeImmutable|null, lines: string[]}>
	 */
	private static function split_blocks( array $lines ): array {
		$blocks  = array();
		$current = null;

		foreach ( $lines as $line ) {
			$line = rtrim( $line, "\r\n" );

			if ( preg_match( '/^\[([^\]]+)\]/', $line, $m ) ) {
				if ( is_array( $current ) ) {
					$blocks[] = $current;
				}

				$current = array(
					'title'    => trim( $m[1] ),
					'datetime' => self::parse_log_datetime( $line ),
					'lines'    => array( $line ),
				);
				continue;
			}

			if ( is_array( $current ) ) {
				$current['lines'][] = $line;
			}
		}

		if ( is_array( $current ) ) {
			$blocks[] = $current;
		}

		return $blocks;
	}

	/**
	 * Parse a PHP slow-log timestamp at the start of a line.
	 *
	 * @param string $line Log line.
	 * @return DateTimeImmutable|null Parsed timestamp.
	 */
	private static function parse_log_datetime( string $line ): ?DateTimeImmutable {
		if ( ! preg_match( '/^\[([^\]]+)\]/', $line, $m ) ) {
			return null;
		}

		$raw = trim( $m[1] );
		$tz  = new DateTimeZone( 'UTC' );

		$formats = array(
			'd-M-Y H:i:s T',
			'd-M-Y H:i:s',
		);

		foreach ( $formats as $format ) {
			$dt = DateTimeImmutable::createFromFormat( $format, $raw, $tz );

			if ( $dt instanceof DateTimeInterface ) {
				return $dt->setTimezone( $tz );
			}
		}

		return null;
	}
}
