<?php
/**
 * Live Traffic admin module — single entry point.
 *
 * This is the only class the host plugin needs to touch. Call
 * {@see DBTN_Traffic::init()} once (e.g. from your admin bootstrap) and the
 * module wires up everything it needs: the REST routes, the page markup, and
 * the admin assets.
 *
 * ---------------------------------------------------------------------------
 * SPIN-OFF NOTES
 * ---------------------------------------------------------------------------
 * Everything for the Live Traffic panel lives under admin/traffic/. To lift it
 * into its own plugin later, you would:
 *
 *   1. Add a plugin header file that defines the constants this module reads
 *      (see "Configuration" below) and then calls DBTN_Traffic::init().
 *   2. Provide the three host dependencies the live tab needs (see
 *      "Hard dependencies").
 *
 * Configuration (must be defined by the host before init() runs):
 *   - DBTN_ADMIN_DIR / DBTN_ADMIN_URL : used to locate this module's assets.
 *     (Already defined by the DBTN Subscriber main plugin file.)
 *
 * Hard dependencies (host must provide these classes — by design, per the
 * current build; the live tab will fatal without them):
 *   - dbtn\Support\DBTN_Utilities::get_client_ip()
 *   - dbtn\Support\DBTN_Visitor_Validator::is_ip_valid()/mark_ip_valid()
 *                                        /get_ip_user()/mark_ip_user()
 *   - dbtn\Admin\DBTN_GeoIP::lookup_string()/lookup()
 *
 * The four report tabs (403-404, PHP errors, PHP slow, WAF) have NO host
 * dependencies — they rely only on DBTN_Traffic_Log_Reader inside this module.
 *
 * @package DBTN_Subscriber
 */

declare( strict_types=1 );



namespace dbtn\Admin\Traffic;

defined( 'ABSPATH' ) || exit;

/**
 * Orchestrates the Live Traffic admin panel.
 */
final class DBTN_Traffic {

	/**
	 * Version for the Traffic Module.
	 */
	public const VERSION = '2026.08.10';

	/**
	 * REST namespace for all module routes.
	 */
	public const REST_NS = 'dbtn/v2';

	/**
	 * Guard so init() is idempotent.
	 *
	 * @var bool
	 */
	private static bool $booted = false;

	/**
	 * Page hook.
	 *
	 * @var string
	 */
	private static string $page_hook = '';

	/**
	 * Page slug.
	 *
	 * @var string
	 */
	private static string $page_slug = '';

	/**
	 * Required tab.
	 *
	 * @var string
	 */
	private static ?string $required_tab = null;

	/**
	 * Wire up the module. Safe to call more than once.
	 *
	 * @param string      $page_hook    Admin page hook.
	 * @param string      $page_slug    Admin page slug.
	 * @param string|null $required_tab Required tab, or null when not applicable.
	 * @return void
	 */
	public static function init(
		string $page_hook,
		string $page_slug,
		?string $required_tab = null
	): void {
		if ( self::$booted ) {
			return;
		}

		self::$booted = true;

		self::$page_hook    = $page_hook;
		self::$page_slug    = $page_slug;
		self::$required_tab = $required_tab;

		$rest = new DBTN_Traffic_REST();

		add_action( 'rest_api_init', array( $rest, 'register_routes' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_assets' ) );
		add_action( 'admin_post_dbtn_traffic_download', array( self::class, 'download_log_file' ) );
	}

	/**
	 * Render the Live Traffic panel markup (tabs, toolbar, content target).
	 *
	 * Call this from your tab callback. It echoes the static shell; the JS then
	 * fills #dbtn-live-traffic-content via REST.
	 *
	 * @return void
	 */
	public static function render_panel(): void {
		$geo_version          = \dbtn\Support\DBTN_Geoip_Update::get_version();
		$geo_update_available = false;

		try {
			$geo_update_available = \dbtn\Support\DBTN_Geoip_Update::has_newer_version();
			if ( $geo_update_available ) {
				\dbtn\Support\DBTN_Geoip_Update::queue_update();
			}
		} catch ( \Throwable $exception ) {
			unset( $exception );
			// Keep the panel available when MaxMind cannot be reached.
		}
		?>
		<div class="postbox">
			<div class="inside">
				<div class="dbtn-lt-wrapper">
					<div id="dbtn-lt-log-tabs" class="dbtn-lt-log-tabs" role="tablist">
						<button type="button" class="dbtn-lt-log-tab is-active" data-dbtn-log-tab="live">Live Traffic</button>
						<button type="button" class="dbtn-lt-log-tab" data-dbtn-log-tab="403-404">403-404</button>
						<button type="button" class="dbtn-lt-log-tab" data-dbtn-log-tab="php-errors">PHP Errors</button>
						<button type="button" class="dbtn-lt-log-tab" data-dbtn-log-tab="php-slow">PHP Slow</button>
						<button type="button" class="dbtn-lt-log-tab" data-dbtn-log-tab="waf-log">WAF Log</button>
						<button type="button" class="dbtn-lt-log-tab" data-dbtn-log-tab="wp-cron">WP-Cron</button>
						<?php if ( self::has_valid_turnstile_keys() ) : ?>
							<button type="button" class="dbtn-lt-log-tab" data-dbtn-log-tab="visitors">Visitors <?php echo esc_html( number_format_i18n( DBTN_Traffic_Report_Visitors::get_today_count() ) ); ?></button>
						<?php endif; ?>
						<button type="button" class="dbtn-lt-log-tab" data-dbtn-log-tab="downloads">Download</button>
						<div class="dbtn-lt-url-search" role="search">
							<label class="screen-reader-text" for="dbtn-lt-url-search">Search full URLs</label>
							<input type="search" id="dbtn-lt-url-search" placeholder="Search URLs" autocomplete="off">
							<button type="button" id="dbtn-lt-url-search-button" class="button" aria-label="Search full URLs" title="Search full URLs">
								<span class="dashicons dashicons-search" aria-hidden="true"></span>
							</button>
						</div>
						<span class="dbtn-lt-version">v<?php echo esc_html( self::VERSION ); ?></span>
					</div>
					<div id="dbtn-live-traffic-toolbar">
						<span id="dbtn-lt-status" class="dbtn-lt-status-live">&#9679; LIVE</span>
						<button id="dbtn-lt-pause" class="button button-secondary">Pause</button>
						<label class="dbtn-lt-filter-label">
							<input type="checkbox" id="dbtn-lt-hide-static" checked>
							Hide static assets
						</label>
						<label class="dbtn-lt-filter-label">
							<input type="checkbox" id="dbtn-lt-hide-me" checked>
							Hide me
						</label>
						<label class="dbtn-lt-filter-label">
							<input type="checkbox" id="dbtn-lt-hide-wp-json" checked>
							Hide validation
						</label>
						<label for="dbtn-lt-status-filter">Status:</label>
						<select id="dbtn-lt-status-filter">
							<option value="all" selected>All</option>
							<option value="2xx">200s</option>
							<option value="3xx">300s</option>
							<option value="4xx">400s</option>
						</select>
						<label for="dbtn-lt-lines">Lines:</label>
						<select id="dbtn-lt-lines">
							<option value="50">50</option>
							<option value="100">100</option>
							<option value="200">200</option>
							<option value="500" selected>500</option>
							<option value="1000">1,000</option>
							<option value="2500">2,500</option>
						</select>
						<span id="dbtn-db-version">
							GeoLite2-City.mmdb version <?php echo esc_html( $geo_version ?? 'not installed' ); ?>
							<?php if ( $geo_update_available ) : ?>
								&mdash; update available
							<?php endif; ?>
						</span>
						<span id="dbtn-lt-updated"></span>
					</div>
					<div id="dbtn-live-traffic-content">
						<p>Loading live traffic&hellip;</p>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Enqueue and localize the module's CSS + JS on the admin page.
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public static function enqueue_assets( string $hook ): void {

		/**
		 * Filter the admin page hook the Live Traffic panel renders on.
		 *
		 * @param string $hook Default page hook suffix.
		 */
		$target_hook = (string) apply_filters( 'dbtn_traffic_page_hook', self::$page_hook );

		if ( $hook !== $target_hook ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current_page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		if ( self::$page_slug !== $current_page ) {
			return;
		}

		if ( null !== self::$required_tab ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$current_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : self::$required_tab;

			if ( self::$required_tab !== $current_tab ) {
				return;
			}
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$css_dir = DBTN_ADMIN_DIR . 'traffic/css/';
		$css_url = DBTN_ADMIN_URL . 'traffic/css/';
		$js_dir  = DBTN_ADMIN_DIR . 'traffic/js/';
		$js_url  = DBTN_ADMIN_URL . 'traffic/js/';

		wp_enqueue_style(
			'dbtn-traffic',
			$css_url . 'dbtn-traffic.css',
			array(),
			self::asset_ver( $css_dir . 'dbtn-traffic.css' )
		);

		wp_enqueue_script(
			'dbtn-traffic',
			$js_url . 'dbtn-traffic.js',
			array( 'jquery' ),
			self::asset_ver( $js_dir . 'dbtn-traffic.js' ),
			true
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$lt_lines = isset( $_GET['lt_lines'] ) ? absint( $_GET['lt_lines'] ) : 500;

		wp_localize_script(
			'dbtn-traffic',
			'dbtn_traffic_obj',
			array(
				'rest_url'             => rest_url( self::REST_NS . '/admin/live-traffic' ),
				'rest_403_404_url'     => rest_url( self::REST_NS . '/admin/log-403-404' ),
				'rest_php_errors_url'  => rest_url( self::REST_NS . '/admin/php-errors' ),
				'rest_php_slow_url'    => rest_url( self::REST_NS . '/admin/php-slow' ),
				'rest_waf_log_url'     => rest_url( self::REST_NS . '/admin/waf-log' ),
				'rest_wp_cron_url'     => rest_url( self::REST_NS . '/admin/wp-cron' ),
				'rest_visitors_url'    => defined( 'DBTN_TURNSTILE_INVISIBLE_SECRET_KEY' )
					? rest_url( self::REST_NS . '/admin/visitors' )
					: '',
				'rest_downloads_url'   => rest_url( self::REST_NS . '/admin/downloads' ),
				'rest_ip_traffic_url'  => rest_url( self::REST_NS . '/admin/ip-traffic' ),
				'rest_url_traffic_url' => rest_url( self::REST_NS . '/admin/url-traffic' ),
				'replace_obj'          => '#dbtn-live-traffic-content',
				'nonce'                => wp_create_nonce( 'wp_rest' ),
				'current_user'         => wp_get_current_user()->user_login,
				'refresh_rate'         => 5000,
				'lines'                => $lt_lines,
			)
		);
	}

	/**
	 * Whether both Turnstile keys are configured with nonempty values.
	 *
	 * @return bool
	 */
	public static function has_valid_turnstile_keys(): bool {
		return defined( 'DBTN_TURNSTILE_INVISIBLE_SITE_KEY' )
			&& '' !== trim( (string) DBTN_TURNSTILE_INVISIBLE_SITE_KEY )
			&& defined( 'DBTN_TURNSTILE_INVISIBLE_SECRET_KEY' )
			&& '' !== trim( (string) DBTN_TURNSTILE_INVISIBLE_SECRET_KEY );
	}

	/**
	 * Absolute path to the configured logs directory.
	 *
	 * @return string Absolute directory path with no trailing slash.
	 */
	public static function get_logs_dir(): string {
		$opts     = get_option( 'dbtn_lt_settings', array() );
		$logs_dir = is_array( $opts ) && ! empty( $opts['logs_dir'] ) ? (string) $opts['logs_dir'] : '';

		if ( '' !== trim( $logs_dir ) ) {
			return rtrim( $logs_dir, '/\\' );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$document_root = isset( $_SERVER['DOCUMENT_ROOT'] ) ? (string) $_SERVER['DOCUMENT_ROOT'] : '';

		return dirname( $document_root ) . '/logs';
	}

	/**
	 * Stream one log file to an authorized administrator.
	 *
	 * @return void
	 */
	public static function download_log_file(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to download log files.', 'dbtn-live-traffic' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'dbtn_traffic_download' );

		$filename = isset( $_GET['file'] ) && is_string( $_GET['file'] )
			? sanitize_text_field( wp_unslash( $_GET['file'] ) )
			: '';

		if ( 'current' === $filename ) {
			$filename = 'access.log';
		}

		if ( '' === $filename || str_contains( $filename, "\0" ) || basename( $filename ) !== $filename ) {
			wp_die( esc_html__( 'Invalid log filename.', 'dbtn-live-traffic' ), '', array( 'response' => 400 ) );
		}

		$logs_dir  = realpath( self::get_logs_dir() );
		$file_path = false !== $logs_dir ? realpath( $logs_dir . DIRECTORY_SEPARATOR . $filename ) : false;

		if (
			false === $logs_dir
			|| false === $file_path
			|| dirname( $file_path ) !== $logs_dir
			|| ! is_file( $file_path )
			|| ! is_readable( $file_path )
		) {
			wp_die( esc_html__( 'The requested log file is not available.', 'dbtn-live-traffic' ), '', array( 'response' => 404 ) );
		}

		$file_size     = filesize( $file_path );
		$download_name = sanitize_file_name( $filename );

		if ( '' === $download_name ) {
			$download_name = 'log-download';
		}

		nocache_headers();
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename="' . $download_name . '"' );
		header( 'X-Content-Type-Options: nosniff' );

		if ( false !== $file_size ) {
			header( 'Content-Length: ' . (string) $file_size );
		}

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		readfile( $file_path );
		exit;
	}

	/**
	 * Cache-busting version for an asset: file mtime when readable, else a
	 * static fallback.
	 *
	 * @param string $path Absolute file path.
	 * @return string Version string.
	 */
	private static function asset_ver( string $path ): string {
		if ( is_readable( $path ) ) {
			$mtime = filemtime( $path );

			if ( false !== $mtime ) {
				return (string) $mtime;
			}
		}

		return self::VERSION;
	}
}
