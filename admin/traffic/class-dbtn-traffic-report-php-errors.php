<?php
/**
 * PHP error-log report (last N days).
 *
 * Self-contained: depends only on DBTN_Traffic_Log_Reader within this module.
 * (Formerly dbtn\Admin\DBTN_Admin_PHP_Errors_Parser.)
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
 * Renders recent php_errors.log entries.
 */
final class DBTN_Traffic_Report_PHP_Errors {

	/**
	 * Render PHP errors from the last N days.
	 *
	 * @param string $log_path Path to php_errors.log.
	 * @param int    $days     Lookback period.
	 * @return string Rendered HTML.
	 */
	public static function render( string $log_path, int $days = 7 ): string {
		if ( ! is_readable( $log_path ) ) {
			return '<p class="dbtn-lt-error">Cannot read PHP error log at <code>' . esc_html( $log_path ) . '</code>.</p>';
		}

		$days   = max( 1, min( 30, $days ) );
		$now    = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
		$cutoff = $now->modify( '-' . $days . ' days' );
		$lines  = DBTN_Traffic_Log_Reader::tail_file( $log_path, 20000 );
		$recent = array();
		$last   = '';

		foreach ( $lines as $line ) {
			$line = rtrim( $line, "\r\n" );

			if ( '' === trim( $line ) ) {
				continue;
			}

			$dt = self::parse_log_datetime( $line );

			if ( null !== $dt ) {
				$last = $line;

				if ( $dt >= $cutoff ) {
					$recent[] = $line;
				}
			}
		}

		if ( ! empty( $recent ) ) {
			$recent = array_reverse( $recent );
		}

		ob_start();
		?>
		<div class="dbtn-lt-report dbtn-lt-report-php-errors">
			<p class="dbtn-lt-report-summary">
				Source: <code>php_errors.log</code>. Showing errors from the last <?php echo esc_html( (string) $days ); ?> days.
			</p>

			<?php if ( empty( $recent ) ) : ?>
				<p>No PHP errors found in the last <?php echo esc_html( (string) $days ); ?> days.</p>
				<?php if ( '' !== $last ) : ?>
					<p>Last dated entry found:</p>
					<pre class="dbtn-lt-log-pre"><?php echo esc_html( $last ); ?></pre>
				<?php endif; ?>
			<?php else : ?>
				<pre class="dbtn-lt-log-pre"><?php echo esc_html( implode( "\n", $recent ) ); ?></pre>
			<?php endif; ?>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Parse a PHP log timestamp at the start of a line.
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
