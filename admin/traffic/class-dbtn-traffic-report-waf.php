<?php
/**
 * WAF log report.
 *
 * Parses ModSecurity JSON audit lines and renders each event as a readable
 * card (Time / IP / Country / Request / Status / Rule + expandable Details).
 * Self-contained: depends only on DBTN_Traffic_Log_Reader within this module.
 * (Formerly dbtn\Admin\DBTN_Admin_WAF_Log_Parser.)
 *
 * @package DBTN_Subscriber
 */

declare( strict_types=1 );

namespace dbtn\Admin\Traffic;

defined( 'ABSPATH' ) || exit;

/**
 * Renders recent WAF events from waf.log.
 */
final class DBTN_Traffic_Report_WAF {

	/**
	 * Maximum number of parsed events to display.
	 */
	private const MAX_ROWS = 200;

	/**
	 * Render recent WAF log entries.
	 *
	 * @param string $log_path Path to waf.log.
	 * @param int    $lines    Number of raw lines to inspect from the end of the file.
	 * @return string Rendered HTML.
	 */
	public static function render( string $log_path, int $lines = 2000 ): string {
		if ( ! is_readable( $log_path ) ) {
			return '<p class="dbtn-lt-error">Cannot read WAF log at <code>' . esc_html( $log_path ) . '</code>.</p>';
		}

		$lines = max( 100, min( 10000, $lines ) );
		$raw   = DBTN_Traffic_Log_Reader::tail_file( $log_path, $lines );
		$rows  = array();

		foreach ( $raw as $line ) {
			$row = self::parse_line( $line );

			if ( null !== $row ) {
				$rows[] = $row;
			}
		}

		$rows = array_reverse( $rows );
		$rows = array_slice( $rows, 0, self::MAX_ROWS );

		ob_start();
		?>
		<div class="dbtn-lt-report dbtn-lt-report-waf">
			<p class="dbtn-lt-report-summary">
				Source: <code>waf.log</code>, version 2026.05.29 using Traffic module. Showing up to <?php echo esc_html( number_format_i18n( self::MAX_ROWS ) ); ?> recent parsed WAF events from the last <?php echo esc_html( number_format_i18n( $lines ) ); ?> raw lines.
			</p>

			<?php if ( empty( $rows ) ) : ?>
				<p>No parseable WAF records found.</p>
			<?php else : ?>
				<table class="widefat striped dbtn-lt-report-table dbtn-lt-waf-table">
					<thead>
						<tr>
							<th colspan="9">WAF Event</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $rows as $row ) : ?>
							<?php
							$request = trim( $row['method'] . ' ' . $row['uri'] );
							$rule    = '' !== $row['message'] ? $row['message'] : $row['rule_id'];
							?>
							<tr class="dbtn-lt-waf-row">
								<td colspan="9">
									<div class="dbtn-lt-waf-card">
										<div class="dbtn-lt-waf-line">
											<span class="dbtn-lt-waf-label">Time:</span>
											<span><?php echo esc_html( $row['time'] ); ?></span>
										</div>

										<div class="dbtn-lt-waf-line">
											<span class="dbtn-lt-waf-label">IP:</span>
											<span class="dbtn-lt-col-ip"><?php echo esc_html( $row['ip'] ); ?></span>
										</div>

										<div class="dbtn-lt-waf-line">
											<span class="dbtn-lt-waf-label">Country:</span>
											<span><?php echo esc_html( $row['country'] ); ?></span>
										</div>

										<div class="dbtn-lt-waf-line">
											<span class="dbtn-lt-waf-label">Request:</span>
											<span class="dbtn-lt-col-path" title="<?php echo esc_attr( $row['uri'] ); ?>">
												<?php echo esc_html( $request ); ?>
											</span>
										</div>

										<div class="dbtn-lt-waf-line">
											<span class="dbtn-lt-waf-label">Status:</span>
											<span>
												<span class="dbtn-lt-http-status <?php echo esc_attr( DBTN_Traffic_Log_Reader::status_class( $row['http_code'] ) ); ?>">
													<?php echo esc_html( $row['http_code'] ); ?>
												</span>
											</span>
										</div>

										<div class="dbtn-lt-waf-line">
											<span class="dbtn-lt-waf-label">Rule:</span>
											<span><?php echo esc_html( $rule ); ?></span>
										</div>

										<details class="dbtn-lt-waf-details">
											<summary>Details</summary>
											<pre class="dbtn-lt-log-pre"><?php echo esc_html( self::detail_text( $row ) ); ?></pre>
										</details>
									</div>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Parse one WAF JSON line.
	 *
	 * @param string $line Raw JSON line.
	 * @return array<string, string>|null Parsed row.
	 */
	private static function parse_line( string $line ): ?array {
		$line = trim( $line );

		if ( '' === $line ) {
			return null;
		}

		$data = json_decode( $line, true );

		if ( ! is_array( $data ) || ! isset( $data['transaction'] ) || ! is_array( $data['transaction'] ) ) {
			return null;
		}

		$transaction = $data['transaction'];
		$request     = isset( $transaction['request'] ) && is_array( $transaction['request'] ) ? $transaction['request'] : array();
		$response    = isset( $transaction['response'] ) && is_array( $transaction['response'] ) ? $transaction['response'] : array();
		$headers     = isset( $request['headers'] ) && is_array( $request['headers'] ) ? $request['headers'] : array();
		$messages    = isset( $transaction['messages'] ) && is_array( $transaction['messages'] ) ? $transaction['messages'] : array();
		$message     = isset( $messages[0] ) && is_array( $messages[0] ) ? $messages[0] : array();
		$details     = isset( $message['details'] ) && is_array( $message['details'] ) ? $message['details'] : array();

		$tags = '';

		if ( isset( $details['tags'] ) && is_array( $details['tags'] ) ) {
			$tags = implode( ', ', array_map( 'strval', $details['tags'] ) );
		}

		return array(
			'time'      => self::string_value( $transaction['time_stamp'] ?? '' ),
			'ip'        => self::string_value( $transaction['client_ip'] ?? '' ),
			'country'   => self::string_value( $headers['cf-ipcountry'] ?? '' ),
			'cf_ray'    => self::string_value( $headers['cf-ray'] ?? '' ),
			'method'    => self::string_value( $request['method'] ?? '' ),
			'uri'       => self::string_value( $request['uri'] ?? '' ),
			'http_code' => self::string_value( $response['http_code'] ?? '' ),
			'ua'        => self::string_value( $headers['user-agent'] ?? '' ),
			'message'   => trim( self::string_value( $message['message'] ?? '' ) ),
			'rule_id'   => self::string_value( $details['ruleId'] ?? '' ),
			'severity'  => self::string_value( $details['severity'] ?? '' ),
			'tags'      => $tags,
			'data'      => self::string_value( $details['data'] ?? '' ),
			'match'     => self::string_value( $details['match'] ?? '' ),
		);
	}

	/**
	 * Build detail text for an expandable WAF row.
	 *
	 * @param array<string, string> $row Parsed row.
	 * @return string Detail text.
	 */
	private static function detail_text( array $row ): string {
		$parts = array();

		$parts[] = 'CF-Ray: ' . $row['cf_ray'];
		$parts[] = 'Tags: ' . $row['tags'];
		$parts[] = 'Data: ' . $row['data'];
		$parts[] = 'Match: ' . $row['match'];
		$parts[] = 'User-Agent: ' . $row['ua'];

		return implode( "\n\n", $parts );
	}

	/**
	 * Convert scalar value to string.
	 *
	 * @param mixed $value Value.
	 * @return string String value.
	 */
	private static function string_value( mixed $value ): string {
		if ( is_scalar( $value ) ) {
			return (string) $value;
		}

		return '';
	}
}
