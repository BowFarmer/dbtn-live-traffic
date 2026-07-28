<?php
/**
 * Live Traffic REST routes.
 *
 * Registers only ten routes the Live Traffic panel uses. Split out from
 * the host plugin's shared admin REST controller so the module owns its own
 * surface.
 *
 * Routes (all GET, manage_options only):
 *   /dbtn/v2/admin/live-traffic   tail of access.log, parsed + geo/validated
 *   /dbtn/v2/admin/ip-traffic     recent access.log rows matching an ip
 *   /dbtn/v2/admin/url-traffic    recent access.log rows matching one URL path
 *   /dbtn/v2/admin/log-403-404    yesterday's gzipped log, 403/404 tally
 *   /dbtn/v2/admin/php-errors     php_errors.log, last 7 days
 *   /dbtn/v2/admin/php-slow       php_slow.log, last 7 days
 *   /dbtn/v2/admin/waf-log        waf.log, recent parsed events
 *   /dbtn/v2/admin/wp-cron        wp-cron.log, grouped cron runs
 *   /dbtn/v2/admin/visitors       count of visitors
 *   /dbtn/v2/admin/downloads      files available in the logs directory
 *
 * @package DBTN_Subscriber
 */

declare( strict_types=1 );

namespace dbtn\Admin\Traffic;

use WP_REST_Request;
use WP_REST_Response;
use WP_User;
use dbtn\Admin\DBTN_GeoIP;
use dbtn\Support\DBTN_Utilities;
use dbtn\Support\DBTN_Visitor_Validator;

defined( 'ABSPATH' ) || exit;

/**
 * Live Traffic REST controller.
 */
final class DBTN_Traffic_REST {

	/**
	 * Register all Live Traffic routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {

		register_rest_route(
			DBTN_Traffic::REST_NS,
			'/admin/live-traffic',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_live_traffic' ),
				'show_in_index'       => false,
				'permission_callback' => array( $this, 'can_manage_options' ),
				'args'                => array(
					'lines' => array(
						'required'          => false,
						'default'           => 500,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			DBTN_Traffic::REST_NS,
			'/admin/ip-traffic',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_ip_traffic' ),
				'show_in_index'       => false,
				'permission_callback' => array( $this, 'can_manage_options' ),
				'args'                => array(
					'ip'         => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'lines'      => array(
						'required'          => false,
						'default'           => 500,
						'sanitize_callback' => 'absint',
					),
					'scan_lines' => array(
						'required'          => false,
						'default'           => 50000,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			DBTN_Traffic::REST_NS,
			'/admin/url-traffic',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_url_traffic' ),
				'show_in_index'       => false,
				'permission_callback' => array( $this, 'can_manage_options' ),
				'args'                => array(
					'path'       => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'lines'      => array(
						'required'          => false,
						'default'           => 500,
						'sanitize_callback' => 'absint',
					),
					'scan_lines' => array(
						'required'          => false,
						'default'           => 50000,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			DBTN_Traffic::REST_NS,
			'/admin/log-403-404',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_log_403_404' ),
				'show_in_index'       => false,
				'permission_callback' => array( $this, 'can_manage_options' ),
			)
		);

		register_rest_route(
			DBTN_Traffic::REST_NS,
			'/admin/php-errors',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_php_errors' ),
				'show_in_index'       => false,
				'permission_callback' => array( $this, 'can_manage_options' ),
			)
		);

		register_rest_route(
			DBTN_Traffic::REST_NS,
			'/admin/php-slow',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_php_slow' ),
				'show_in_index'       => false,
				'permission_callback' => array( $this, 'can_manage_options' ),
			)
		);

		register_rest_route(
			DBTN_Traffic::REST_NS,
			'/admin/waf-log',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_waf_log' ),
				'show_in_index'       => false,
				'permission_callback' => array( $this, 'can_manage_options' ),
				'args'                => array(
					'lines' => array(
						'required'          => false,
						'default'           => 2000,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			DBTN_Traffic::REST_NS,
			'/admin/wp-cron',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_wp_cron' ),
				'show_in_index'       => false,
				'permission_callback' => array( $this, 'can_manage_options' ),
				'args'                => array(
					'lines' => array(
						'required'          => false,
						'default'           => 5000,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		if ( DBTN_Traffic::has_valid_turnstile_keys() ) {
			register_rest_route(
				DBTN_Traffic::REST_NS,
				'/admin/visitors',
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_visitors' ),
					'show_in_index'       => false,
					'permission_callback' => array( $this, 'can_manage_options' ),
				)
			);
		}

		register_rest_route(
			DBTN_Traffic::REST_NS,
			'/admin/downloads',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_downloads' ),
				'show_in_index'       => false,
				'permission_callback' => array( $this, 'can_manage_options' ),
			)
		);
	}

	/**
	 * Permission check for every route.
	 *
	 * @return bool
	 */
	public function can_manage_options(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Absolute path to a known log file.
	 *
	 * Uses the custom logs directory from settings when configured, otherwise
	 * falls back to the module's standard logs directory above DOCUMENT_ROOT.
	 *
	 * @param string $filename Known log filename.
	 * @return string Absolute path.
	 */
	private function get_log_path( string $filename ): string {
		return DBTN_Traffic::get_logs_dir() . '/' . $filename;
	}

	/**
	 * Standard envelope for an HTML response.
	 *
	 * @param string               $html Rendered HTML.
	 * @param array<string, mixed> $extra Additional response fields.
	 * @return WP_REST_Response
	 */
	private function html_response( string $html, array $extra = array() ): WP_REST_Response {
		return rest_ensure_response(
			array_merge(
				array(
					'new_content' => $html,
					'nonce'       => wp_create_nonce( 'wp_rest' ),
				),
				$extra
			)
		);
	}

	/**
	 * Tail the access log and return parsed HTML rows (the "live" tab).
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response
	 */
	public function get_live_traffic( WP_REST_Request $request ): WP_REST_Response {

		$num_lines = (int) $request->get_param( 'lines' );
		$num_lines = max( 50, min( 2500, $num_lines ) ); // Clamp 50-2500.

		$log_path     = $this->get_log_path( 'access.log' );
		$archive_path = $this->get_log_path( 'access.log.1.gz' );

		if ( ! is_readable( $log_path ) && ! is_readable( $archive_path ) ) {
			return $this->html_response(
				'<p class="dbtn-lt-error">Cannot read the current or rotated access log.</p>'
			);
		}

		// The caller already holds manage_options. Mark their IP/user as valid
		// so their own requests highlight correctly in the table.
		$ip = DBTN_Utilities::get_client_ip();

		if ( ! empty( $ip ) ) {
			if ( false === DBTN_Visitor_Validator::is_ip_valid( $ip ) ) {
				DBTN_Visitor_Validator::mark_ip_valid( $ip );
			}
			if ( '' === DBTN_Visitor_Validator::get_ip_user( $ip ) ) {
				$user = wp_get_current_user();
				if ( $user instanceof WP_User && $user->exists() ) {
					DBTN_Visitor_Validator::mark_ip_user( $ip, $user->user_login );
				}
			}
		}

		$lines = DBTN_Traffic_Log_Reader::tail_rotated_access_log(
			$log_path,
			$archive_path,
			$num_lines
		);

		if ( empty( $lines ) ) {
			return $this->html_response( '<p>Log is empty or no recent entries.</p>' );
		}

		$rows              = array();
		$geo_cache         = array();
		$validated_cache   = array();
		$logged_user_cache = array();

		foreach ( array_reverse( $lines ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}

			$parsed = DBTN_Traffic_Log_Reader::parse_access_log_line( $line );
			if ( null === $parsed ) {
				continue;
			}

			if ( str_contains( $parsed['path'], '/wp-json/dbtn/v2/admin/live-traffic' ) ) {
				continue;
			}

			if ( str_contains( $parsed['ua_short'], 'Cloudflare' ) ) {
				continue;
			}

			$row_ip = $parsed['ip'];

			if ( ! isset( $geo_cache[ $row_ip ] ) ) {
				$geo_cache[ $row_ip ] = DBTN_GeoIP::lookup_string( $row_ip );
			}
			$parsed['geo'] = $geo_cache[ $row_ip ];

			if ( ! isset( $validated_cache[ $row_ip ] ) ) {
				$validated_cache[ $row_ip ] = DBTN_Visitor_Validator::is_ip_valid( $row_ip );
			}
			$parsed['validated'] = $validated_cache[ $row_ip ];

			if ( ! isset( $logged_user_cache[ $row_ip ] ) ) {
				$logged_user_cache[ $row_ip ] = DBTN_Visitor_Validator::get_ip_user( $row_ip );
			}
			$parsed['user_login'] = $logged_user_cache[ $row_ip ];

			$rows[] = $parsed;
		}

		if ( empty( $rows ) ) {
			return $this->html_response( '<p>No parseable log entries found.</p>' );
		}

		$geolite_version      = \dbtn\Support\DBTN_Geoip_Update::get_version() ?? 'not installed';
		$geo_update_available = false;

		try {
			$geo_update_available = \dbtn\Support\DBTN_Geoip_Update::has_newer_version();
		} catch ( \Throwable $exception ) {
			unset( $exception );
			// Keep live traffic polling available when MaxMind cannot be reached.
		}

		if ( $geo_update_available ) {
			$geolite_version .= ' — update available and queued';
			\dbtn\Support\DBTN_Geoip_Update::queue_update();
		}

		$extra         = array(
			'geolite_version' => $geolite_version,
		);
		$visitor_count = $request->get_param( 'visitor_count' );
		$visitor_count = is_scalar( $visitor_count ) ? (string) $visitor_count : '';

		if ( DBTN_Traffic::has_valid_turnstile_keys() && rest_sanitize_boolean( $visitor_count ) ) {
			$extra['today_visitors'] = DBTN_Traffic_Report_Visitors::get_today_count();
		}

		return $this->html_response( $this->render_access_rows( $rows ), $extra );
	}

	/**
	 * Return recent access-log rows for one IP address.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response
	 */
	public function get_ip_traffic( WP_REST_Request $request ): WP_REST_Response {
		$ip = trim( (string) $request->get_param( 'ip' ) );

		if ( false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return $this->html_response( '<p class="dbtn-lt-error">Invalid IP address.</p>' );
		}

		$num_lines = (int) $request->get_param( 'lines' );
		$num_lines = max( 50, min( 500, $num_lines ) );

		$scan_lines = (int) $request->get_param( 'scan_lines' );
		$scan_lines = max( $num_lines, min( 50000, $scan_lines ) );

		$log_path = $this->get_log_path( 'access.log' );

		if ( ! is_readable( $log_path ) ) {
			return $this->html_response(
				'<p class="dbtn-lt-error">Cannot read access log at <code>' . esc_html( $log_path ) . '</code>.</p>'
			);
		}

		$lines = DBTN_Traffic_Log_Reader::tail_file( $log_path, $scan_lines );

		if ( empty( $lines ) ) {
			return $this->html_response( '<p>Log is empty or no recent entries.</p>' );
		}

		$rows              = array();
		$geo_cache         = array();
		$validated_cache   = array();
		$logged_user_cache = array();

		foreach ( array_reverse( $lines ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}

			$parsed = DBTN_Traffic_Log_Reader::parse_access_log_line( $line );
			if ( null === $parsed || $ip !== $parsed['ip'] ) {
				continue;
			}

			$is_traffic_rest_request = str_contains( $parsed['path'], '/wp-json/dbtn/v2/admin/live-traffic' )
				|| str_contains( $parsed['path'], '/wp-json/dbtn/v2/admin/ip-traffic' );

			if ( $is_traffic_rest_request ) {
				continue;
			}

			if ( str_contains( $parsed['ua_short'], 'Cloudflare' ) ) {
				continue;
			}

			$row_ip = $parsed['ip'];

			if ( ! isset( $geo_cache[ $row_ip ] ) ) {
				$geo_cache[ $row_ip ] = DBTN_GeoIP::lookup_string( $row_ip );
			}
			$parsed['geo'] = $geo_cache[ $row_ip ];

			if ( ! isset( $validated_cache[ $row_ip ] ) ) {
				$validated_cache[ $row_ip ] = DBTN_Visitor_Validator::is_ip_valid( $row_ip );
			}
			$parsed['validated'] = $validated_cache[ $row_ip ];

			if ( ! isset( $logged_user_cache[ $row_ip ] ) ) {
				$logged_user_cache[ $row_ip ] = DBTN_Visitor_Validator::get_ip_user( $row_ip );
			}
			$parsed['user_login'] = $logged_user_cache[ $row_ip ];

			$rows[] = $parsed;

			if ( count( $rows ) >= $num_lines ) {
				break;
			}
		}

		if ( empty( $rows ) ) {
			return $this->html_response(
				'<p>No recent access.log entries found for <code>' . esc_html( $ip ) . '</code>.</p>'
			);
		}

		$html  = '<div class="dbtn-lt-ip-traffic-heading"><strong>Recent access.log entries for ' . esc_html( $ip ) . '</strong>';
		$html .= ' <span class="dbtn-lt-ip-traffic-note">Showing up to ' . esc_html( (string) $num_lines );
		$html .= ' matches from the last ' . esc_html( (string) $scan_lines ) . ' log lines.</span></div>';
		$html .= $this->render_access_rows( $rows );

		return $this->html_response( $html );
	}

	/**
	 * Return recent access-log rows matching one URL path.
	 *
	 * Query strings are intentionally ignored for both the requested path and
	 * the log rows, so /shop?page=1 and /shop?page=2 are treated as /shop.
	 * Root-query routes such as /?wc-ajax=checkout retain their query because
	 * it identifies the route itself.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response
	 */
	public function get_url_traffic( WP_REST_Request $request ): WP_REST_Response {
		$path = self::path_without_query( (string) $request->get_param( 'path' ) );

		if ( '' === $path || '/' !== $path[0] ) {
			return $this->html_response( '<p class="dbtn-lt-error">Invalid URL path.</p>' );
		}

		$num_lines  = max( 50, min( 500, (int) $request->get_param( 'lines' ) ) );
		$scan_lines = max( $num_lines, min( 50000, (int) $request->get_param( 'scan_lines' ) ) );
		$log_path   = $this->get_log_path( 'access.log' );

		if ( ! is_readable( $log_path ) ) {
			return $this->html_response(
				'<p class="dbtn-lt-error">Cannot read access log at <code>' . esc_html( $log_path ) . '</code>.</p>'
			);
		}

		$lines             = DBTN_Traffic_Log_Reader::tail_file( $log_path, $scan_lines );
		$rows              = array();
		$geo_cache         = array();
		$validated_cache   = array();
		$logged_user_cache = array();

		foreach ( array_reverse( $lines ) as $line ) {
			$parsed = DBTN_Traffic_Log_Reader::parse_access_log_line( trim( $line ) );

			if ( null === $parsed || self::path_without_query( $parsed['path'] ) !== $path ) {
				continue;
			}

			if ( str_contains( $parsed['ua_short'], 'Cloudflare' ) ) {
				continue;
			}

			$row_ip = $parsed['ip'];

			if ( ! isset( $geo_cache[ $row_ip ] ) ) {
				$geo_cache[ $row_ip ] = DBTN_GeoIP::lookup_string( $row_ip );
			}
			$parsed['geo'] = $geo_cache[ $row_ip ];

			if ( ! isset( $validated_cache[ $row_ip ] ) ) {
				$validated_cache[ $row_ip ] = DBTN_Visitor_Validator::is_ip_valid( $row_ip );
			}
			$parsed['validated'] = $validated_cache[ $row_ip ];

			if ( ! isset( $logged_user_cache[ $row_ip ] ) ) {
				$logged_user_cache[ $row_ip ] = DBTN_Visitor_Validator::get_ip_user( $row_ip );
			}
			$parsed['user_login'] = $logged_user_cache[ $row_ip ];
			$rows[]               = $parsed;

			if ( count( $rows ) >= $num_lines ) {
				break;
			}
		}

		if ( empty( $rows ) ) {
			return $this->html_response(
				'<p>No recent access.log entries found for <code>' . esc_html( $path ) . '</code>.</p>'
			);
		}

		$html  = '<div class="dbtn-lt-ip-traffic-heading"><strong>Recent access.log entries for ' . esc_html( $path ) . '</strong>';
		$html .= ' <span class="dbtn-lt-ip-traffic-note">Query strings ignored except for root-query URLs. Showing up to ' . esc_html( (string) $num_lines );
		$html .= ' matches from the last ' . esc_html( (string) $scan_lines ) . ' log lines.</span></div>';
		$html .= $this->render_access_rows( $rows );

		return $this->html_response( $html );
	}

	/**
	 * Normalize an access-log request target to its path without a query string.
	 *
	 * @param string $value Request target.
	 * @return string
	 */
	private static function path_without_query( string $value ): string {
		$value = trim( $value );
		$value = explode( '#', $value, 2 )[0];

		if ( str_starts_with( $value, '/?' ) ) {
			return $value;
		}

		$path = explode( '?', $value, 2 )[0];

		return $path;
	}

	/**
	 * Render parsed access-log rows as the Live Traffic table.
	 *
	 * @param array<int, array<string, mixed>> $rows Parsed access-log rows.
	 * @return string Rendered HTML table.
	 */
	private function render_access_rows( array $rows ): string {
		ob_start();
		?>
		<table class="dbtn-lt-table striped">
			<thead>
				<tr>
					<th class="db_col_header dbtn-lt-col-time">Time</th>
					<th class="db_col_header dbtn-lt-col-ip">IP</th>
					<th class="db_col_header dbtn-lt-col-geo">Location</th>
					<th class="db_col_header dbtn-lt-col-method">Method</th>
					<th class="db_col_header dbtn-lt-col-path">Path</th>
					<th class="db_col_header dbtn-lt-col-status">Status</th>
					<th class="db_col_header dbtn-lt-col-bytes">Bytes-Time</th>
					<th class="db_col_header dbtn-lt-col-ua">Browser / Bot</th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $rows as $r ) : ?>
				<?php
				$validated   = ! empty( $r['validated'] );
				$host        = is_string( $r['host'] ?? null ) ? $r['host'] : '';
				$is_bad_host = '' !== $host && ! DBTN_Traffic_Log_Reader::is_canonical_host( $host );
				$row_classes = array();

				if ( $validated ) {
					$row_classes[] = 'dbtn-lt-row-validated';
				}

				if ( $is_bad_host ) {
					$row_classes[] = 'dbtn-lt-row-bad-host';
				}

				$time_raw      = is_string( $r['time_raw'] ?? null ) ? $r['time_raw'] : '';
				$row_ip        = is_string( $r['ip'] ?? null ) ? $r['ip'] : '';
				$user_login    = is_string( $r['user_login'] ?? null ) ? $r['user_login'] : '';
				$ip_display    = '' !== $user_login ? $user_login . '-' . $row_ip : $row_ip;
				$geo           = is_string( $r['geo'] ?? null ) ? $r['geo'] : '';
				$method        = is_string( $r['method'] ?? null ) ? $r['method'] : '';
				$path          = is_string( $r['path'] ?? null ) ? trim( $r['path'] ) : '';
				$referer       = is_string( $r['referer'] ?? null ) ? $r['referer'] : '';
				$status        = is_string( $r['status'] ?? null ) ? $r['status'] : '';
				$bytes         = is_string( $r['bytes'] ?? null ) ? $r['bytes'] : '';
				$duration      = is_string( $r['duration'] ?? null ) ? $r['duration'] : '';
				$bytes_display = ( '' !== $bytes && '-' !== $bytes ) ? number_format( (int) $bytes ) : '—';
				$bytes_cell    = ( '' !== $duration ) ? $bytes_display . '-' . $duration : $bytes_display;
				$ua            = is_string( $r['ua'] ?? null ) ? $r['ua'] : '';
				$ua_short      = is_string( $r['ua_short'] ?? null ) ? $r['ua_short'] : '';

				if ( '/wp-json/dbtn/v2/cart/fukuro' === $path ) {
					$row_classes[] = 'dbtn-lt-row-fukuro';
				}

				if ( '/?wc-ajax=checkout' === $path ) {
					$row_classes[] = 'dbtn-lt-row-checkout';
				}

				$row_class_attr = ! empty( $row_classes ) ? ' class="' . esc_attr( implode( ' ', $row_classes ) ) . '"' : '';
				?>
				<tr data-status="<?php echo esc_attr( $status ); ?>"<?php echo $row_class_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
					<td class="dbtn-lt-col-time"><?php echo esc_html( $time_raw ); ?></td>
					<td class="dbtn-lt-col-ip"><?php echo esc_html( $ip_display ); ?></td>
					<td class="dbtn-lt-col-geo"><?php echo esc_html( $geo ); ?></td>
					<td class="dbtn-lt-col-method">
						<span class="dbtn-lt-method dbtn-lt-method-<?php echo esc_attr( strtolower( $method ) ); ?>"><?php echo esc_html( $method ); ?></span>
					</td>
					<td class="dbtn-lt-col-path" title="<?php echo esc_attr( $path ); ?>">
						<?php echo esc_html( $path ); ?>
						<?php if ( $is_bad_host ) : ?>
							<br><span class="dbtn-lt-host-warning">Host: <?php echo esc_html( $host ); ?></span>
						<?php endif; ?>
						<?php if ( ! empty( $referer ) ) : ?>
							<br><span class="dbtn-lt-referer"><?php echo esc_html( $referer ); ?></span>
						<?php endif; ?>
					</td>
					<td class="dbtn-lt-col-status">
						<span class="dbtn-lt-http-status <?php echo esc_attr( DBTN_Traffic_Log_Reader::status_class( $status ) ); ?>"><?php echo esc_html( $status ); ?></span>
					</td>
					<td class="dbtn-lt-col-bytes"><?php echo esc_html( $bytes_cell ); ?></td>
					<td class="dbtn-lt-col-ua" title="<?php echo esc_attr( $ua ); ?>"><?php echo esc_html( $ua_short ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Yesterday's 403/404 report.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response
	 */
	public function get_log_403_404( WP_REST_Request $request ): WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$html = DBTN_Traffic_Report_403_404::render( $this->get_log_path( 'access.log.1.gz' ) );

		return $this->html_response( $html );
	}

	/**
	 * Recent PHP errors.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response
	 */
	public function get_php_errors( WP_REST_Request $request ): WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$html = DBTN_Traffic_Report_PHP_Errors::render( $this->get_log_path( 'php_errors.log' ), 7 );

		return $this->html_response( $html );
	}

	/**
	 * Recent PHP slow-log entries.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response
	 */
	public function get_php_slow( WP_REST_Request $request ): WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$html = DBTN_Traffic_Report_PHP_Slow::render( $this->get_log_path( 'php_slow.log' ), 7 );

		return $this->html_response( $html );
	}

	/**
	 * Recent WAF events.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response
	 */
	public function get_waf_log( WP_REST_Request $request ): WP_REST_Response {
		$num_lines = (int) $request->get_param( 'lines' );
		$num_lines = max( 100, min( 10000, $num_lines ) );

		$html = DBTN_Traffic_Report_WAF::render( $this->get_log_path( 'waf.log' ), $num_lines );

		return $this->html_response( $html );
	}

	/**
	 * Recent WP-Cron runs.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response
	 */
	public function get_wp_cron( WP_REST_Request $request ): WP_REST_Response {
		$num_lines = (int) $request->get_param( 'lines' );
		$num_lines = max( 100, min( 50000, $num_lines ) );

		$html = DBTN_Traffic_Report_WP_Cron::render( $this->get_log_path( 'wp-cron.log' ), $num_lines );

		return $this->html_response( $html );
	}

	/**
	 * Daily validated-human visitor counts.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response
	 */
	public function get_visitors( WP_REST_Request $request ): WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		if ( ! DBTN_Traffic::has_valid_turnstile_keys() ) {
			return $this->html_response( '<p class="dbtn-lt-error">Visitor counts are not enabled.</p>' );
		}

		return $this->html_response( DBTN_Traffic_Report_Visitors::render( 100 ) );
	}

	/**
	 * List downloadable files in the configured logs directory.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response
	 */
	public function get_downloads( WP_REST_Request $request ): WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$configured_dir = DBTN_Traffic::get_logs_dir();
		$logs_dir       = realpath( $configured_dir );

		if ( false === $logs_dir || ! is_dir( $logs_dir ) || ! is_readable( $logs_dir ) ) {
			return $this->html_response(
				'<p class="dbtn-lt-error">Cannot read logs directory at <code>' . esc_html( $configured_dir ) . '</code>.</p>'
			);
		}

		$entries = scandir( $logs_dir );
		$files   = array();

		if ( false !== $entries ) {
			foreach ( $entries as $entry ) {
				if ( '.' === $entry || '..' === $entry ) {
					continue;
				}

				$path          = $logs_dir . DIRECTORY_SEPARATOR . $entry;
				$resolved_path = realpath( $path );

				if (
					false === $resolved_path
					|| dirname( $resolved_path ) !== $logs_dir
					|| ! is_file( $resolved_path )
					|| ! is_readable( $resolved_path )
				) {
					continue;
				}

				$size  = filesize( $resolved_path );
				$mtime = filemtime( $resolved_path );

				$files[] = array(
					'name'  => $entry,
					'size'  => false !== $size ? $size : 0,
					'mtime' => false !== $mtime ? $mtime : 0,
				);
			}
		}

		usort(
			$files,
			static fn( array $a, array $b ): int => strnatcasecmp( (string) $a['name'], (string) $b['name'] )
		);

		return $this->html_response( $this->render_downloads( $files, $logs_dir ) );
	}

	/**
	 * Render the log download table.
	 *
	 * @param array<int, array{name: string, size: int, mtime: int}> $files Log files.
	 * @param string                                                 $logs_dir Log directory.
	 * @return string
	 */
	private function render_downloads( array $files, string $logs_dir ): string {
		ob_start();
		?>
		<div class="dbtn-lt-downloads">
			<p class="dbtn-lt-downloads-path">Files in <code><?php echo esc_html( $logs_dir ); ?></code></p>
			<?php if ( empty( $files ) ) : ?>
				<p>No downloadable files were found.</p>
			<?php else : ?>
				<table class="widefat striped dbtn-lt-download-table">
					<thead>
						<tr>
							<th scope="col">File</th>
							<th scope="col" class="dbtn-lt-download-size">Size</th>
							<th scope="col" class="dbtn-lt-download-modified">Last modified</th>
							<th scope="col" class="dbtn-lt-download-action"><span class="screen-reader-text">Download</span></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $files as $file ) : ?>
							<?php
							$download_url = wp_nonce_url(
								add_query_arg(
									array(
										'action' => 'dbtn_traffic_download',
										'file'   => 'access.log' === $file['name'] ? 'current' : $file['name'],
									),
									admin_url( 'admin-post.php' )
								),
								'dbtn_traffic_download'
							);
							$size_display = size_format( $file['size'], 2 );

							if ( false === $size_display ) {
								$size_display = number_format_i18n( $file['size'] ) . ' bytes';
							}

							$modified = 'Unknown';

							if ( $file['mtime'] > 0 ) {
								$modified_date = wp_date(
									get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
									$file['mtime']
								);

								if ( false !== $modified_date ) {
									$modified = $modified_date;
								}
							}
							?>
							<tr>
								<td><code><?php echo esc_html( $file['name'] ); ?></code></td>
								<td class="dbtn-lt-download-size"><?php echo esc_html( $size_display ); ?></td>
								<td class="dbtn-lt-download-modified"><?php echo esc_html( $modified ); ?></td>
								<td class="dbtn-lt-download-action">
									<a class="dbtn-lt-download-button" href="<?php echo esc_url( $download_url ); ?>" aria-label="<?php echo esc_attr( 'Download ' . $file['name'] ); ?>" title="<?php echo esc_attr( 'Download ' . $file['name'] ); ?>">
										<svg class="dbtn-icon-download" viewBox="0 0 32 32" aria-hidden="true" focusable="false">
											<!-- Rounded box with open bottom. -->
											<path d="M 10 23
												L 2 23
												A 2 2 0 0 1 0 21
												L 0 2
												A 2 2 0 0 1 2 0
												L 30 0
												A 2 2 0 0 1 32 2
												L 32 21
												A 2 2 0 0 1 30 23
												L 22 23
												L 22 18
												L 27 18
												L 27 5
												L 5 5
												L 5 18
												L 10 18
												Z" stroke="none" fill="currentColor"></path>
											<!-- Downward arrow. -->
											<path d="M 16 32
												L 8 25
												L 12 25
												L 12 12
												A 4 3.5 0 0 1 20 12
												L 20 25
												L 24 25
												Z" stroke="none" fill="currentColor"></path>
										</svg>
									</a>
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
}
