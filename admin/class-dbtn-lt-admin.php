<?php
/**
 * Admin UI for DBTN Live Traffic.
 *
 * Registers two admin pages:
 *   - Live Traffic   (top-level menu, slug: dbtn-live-traffic)
 *   - Settings       (sub-menu, slug: dbtn-live-traffic-settings)
 *
 * Settings page allows entering Cloudflare Turnstile site key and secret key.
 * Those values are stored in the option `dbtn_lt_settings` and surfaced as the
 * DBTN_TURNSTILE_INVISIBLE_SITE_KEY / DBTN_TURNSTILE_INVISIBLE_SECRET_KEY
 * constants by the main plugin file on `plugins_loaded`.
 *
 * @package DBTN_Live_Traffic
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Admin UI controller.
 */
final class DBTN_LT_Admin {

	/** Option name for plugin settings. */
	private const OPTION = 'dbtn_lt_settings';

	/**
	 * Constructor — registers hooks.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menus' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_settings_assets' ) );
	}

	/**
	 * Load credential-validation assets on this plugin's settings page only.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public function enqueue_settings_assets( string $hook_suffix ): void {
		if ( 'live-traffic_page_dbtn-live-traffic-settings' !== $hook_suffix ) {
			return;
		}

		$script_path = DBTN_LT_PLUGIN_DIR . 'assets/js/dbtn-credential-validation.js';
		$style_path  = DBTN_LT_PLUGIN_DIR . 'assets/css/dbtn-credential-validation.css';

		wp_enqueue_style(
			'dbtn-credential-validation',
			DBTN_LT_PLUGIN_URL . 'assets/css/dbtn-credential-validation.css',
			array(),
			is_readable( $style_path ) ? (string) filemtime( $style_path ) : DBTN_LT_VERSION
		);

		wp_enqueue_script(
			'dbtn-credential-validation',
			DBTN_LT_PLUGIN_URL . 'assets/js/dbtn-credential-validation.js',
			array(),
			is_readable( $script_path ) ? (string) filemtime( $script_path ) : DBTN_LT_VERSION,
			true
		);

		wp_localize_script(
			'dbtn-credential-validation',
			'DBTNCredentialValidation',
			array(
				'turnstileUrl' => rest_url( 'dbtn/v2/admin/credentials/turnstile' ),
				'maxmindUrl'   => rest_url( 'dbtn/v2/admin/credentials/maxmind' ),
				'nonce'        => wp_create_nonce( 'wp_rest' ),
				'strings'      => array(
					'validating'     => __( 'Validating…', 'dbtn-live-traffic' ),
					'turnstileEmpty' => __( 'Enter both Turnstile keys before validating.', 'dbtn-live-traffic' ),
					'maxmindEmpty'   => __( 'Enter both MaxMind credentials before validating.', 'dbtn-live-traffic' ),
					'turnstileLoad'  => __( 'Cloudflare Turnstile could not be loaded.', 'dbtn-live-traffic' ),
					'turnstileToken' => __( 'Turnstile could not create a test token. Check the site key and allowed hostnames.', 'dbtn-live-traffic' ),
					'requestFailed'  => __( 'The validation request failed. Please try again.', 'dbtn-live-traffic' ),
				),
			)
		);
	}

	/**
	 * Register admin menu pages.
	 *
	 * @return void
	 */
	public function register_menus(): void {
		// Top-level: Live Traffic panel.
		add_menu_page(
			__( 'Live Traffic', 'dbtn-live-traffic' ),
			__( 'Live Traffic', 'dbtn-live-traffic' ),
			'manage_options',
			'dbtn-live-traffic',
			array( $this, 'render_traffic_page' ),
			'dashicons-chart-area',
			80
		);

		// Sub-menu: duplicate top-level entry renamed.
		add_submenu_page(
			'dbtn-live-traffic',
			__( 'Live Traffic', 'dbtn-live-traffic' ),
			__( 'Live Traffic', 'dbtn-live-traffic' ),
			'manage_options',
			'dbtn-live-traffic',
			array( $this, 'render_traffic_page' )
		);

		// Sub-menu: Settings.
		add_submenu_page(
			'dbtn-live-traffic',
			__( 'DBTN Live Traffic Settings', 'dbtn-live-traffic' ),
			__( 'Settings', 'dbtn-live-traffic' ),
			'manage_options',
			'dbtn-live-traffic-settings',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Register settings (for the Settings API, used on the settings page).
	 *
	 * @return void
	 */
	public function register_settings(): void {
		register_setting(
			'dbtn_lt_settings_group',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
			)
		);

		add_settings_section(
			'dbtn_lt_turnstile_section',
			__( 'Cloudflare Turnstile', 'dbtn-live-traffic' ),
			array( $this, 'render_turnstile_section_description' ),
			'dbtn-live-traffic-settings'
		);

		add_settings_field(
			'turnstile_site_key',
			__( 'Invisible Site Key', 'dbtn-live-traffic' ),
			array( $this, 'render_field_site_key' ),
			'dbtn-live-traffic-settings',
			'dbtn_lt_turnstile_section'
		);

		add_settings_field(
			'turnstile_secret_key',
			__( 'Secret Key', 'dbtn-live-traffic' ),
			array( $this, 'render_field_secret_key' ),
			'dbtn-live-traffic-settings',
			'dbtn_lt_turnstile_section'
		);

		add_settings_field(
			'turnstile_validate',
			__( 'Credential Check', 'dbtn-live-traffic' ),
			array( $this, 'render_turnstile_validate_button' ),
			'dbtn-live-traffic-settings',
			'dbtn_lt_turnstile_section'
		);

		add_settings_section(
			'dbtn_lt_logs_section',
			__( 'Server Logs', 'dbtn-live-traffic' ),
			array( $this, 'render_logs_section_description' ),
			'dbtn-live-traffic-settings'
		);

		add_settings_field(
			'logs_dir',
			__( 'Logs Directory', 'dbtn-live-traffic' ),
			array( $this, 'render_field_logs_dir' ),
			'dbtn-live-traffic-settings',
			'dbtn_lt_logs_section'
		);

		add_settings_section(
			'dbtn_lt_geoip_section',
			__( 'MaxMind GeoLite2 Database', 'dbtn-live-traffic' ),
			array( $this, 'render_geoip_section_description' ),
			'dbtn-live-traffic-settings'
		);

		add_settings_field(
			'maxmind_account_id',
			__( 'Account ID', 'dbtn-live-traffic' ),
			array( $this, 'render_field_maxmind_account_id' ),
			'dbtn-live-traffic-settings',
			'dbtn_lt_geoip_section'
		);

		add_settings_field(
			'maxmind_license_key',
			__( 'License Key', 'dbtn-live-traffic' ),
			array( $this, 'render_field_maxmind_license_key' ),
			'dbtn-live-traffic-settings',
			'dbtn_lt_geoip_section'
		);

		add_settings_field(
			'maxmind_validate',
			__( 'Credential Check', 'dbtn-live-traffic' ),
			array( $this, 'render_maxmind_validate_button' ),
			'dbtn-live-traffic-settings',
			'dbtn_lt_geoip_section'
		);
	}

	/**
	 * Sanitize settings values before saving.
	 *
	 * @param mixed $raw Raw submitted data.
	 * @return array<string, string> Sanitized values.
	 */
	public function sanitize_settings( mixed $raw ): array {
		$clean = array();

		if ( ! is_array( $raw ) ) {
			return $clean;
		}

		$clean['turnstile_site_key']   = sanitize_text_field( $raw['turnstile_site_key'] ?? '' );
		$clean['turnstile_secret_key'] = sanitize_text_field( $raw['turnstile_secret_key'] ?? '' );
		$clean['logs_dir']             = sanitize_text_field( $raw['logs_dir'] ?? '' );
		$clean['maxmind_account_id']   = sanitize_text_field( $raw['maxmind_account_id'] ?? '' );
		$clean['maxmind_license_key']  = sanitize_text_field( $raw['maxmind_license_key'] ?? '' );

		return $clean;
	}

	// ── Renderers ─────────────────────────────────────────────────────────────

	/**
	 * Render the Live Traffic admin panel page.
	 *
	 * @return void
	 */
	public function render_traffic_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'dbtn-live-traffic' ) );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Live Traffic', 'dbtn-live-traffic' ); ?></h1>
			<?php dbtn\Admin\Traffic\DBTN_Traffic::render_panel(); ?>
		</div>
		<?php
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'dbtn-live-traffic' ) );
		}

		$opts = get_option( self::OPTION, array() );
		if ( ! is_array( $opts ) ) {
			$opts = array();
		}

		$site_key_set   = ! empty( $opts['turnstile_site_key'] );
		$secret_key_set = ! empty( $opts['turnstile_secret_key'] );
		$both_set       = $site_key_set && $secret_key_set;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'DBTN Live Traffic — Settings', 'dbtn-live-traffic' ); ?></h1>

			<?php if ( $both_set ) : ?>
				<div class="notice notice-success inline">
					<p><?php esc_html_e( 'Turnstile is configured. Visitor validation is active on the front end.', 'dbtn-live-traffic' ); ?></p>
				</div>
			<?php else : ?>
				<div class="notice notice-warning inline">
					<p>
						<?php
						printf(
							/* translators: %s: Cloudflare dashboard URL */
							esc_html__( 'Turnstile keys are not yet set. Visitor validation will not run until both keys are saved. Get your keys from the %s.', 'dbtn-live-traffic' ),
							'<a href="https://dash.cloudflare.com/?to=/:account/turnstile" target="_blank" rel="noopener">' . esc_html__( 'Cloudflare dashboard', 'dbtn-live-traffic' ) . '</a>'
						);
						?>
					</p>
					<p>
						<?php esc_html_e( 'Create a new Turnstile widget with widget type "Invisible" and add your site hostname as an allowed origin.', 'dbtn-live-traffic' ); ?>
					</p>
				</div>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php
				settings_fields( 'dbtn_lt_settings_group' );
				do_settings_sections( 'dbtn-live-traffic-settings' );
				submit_button( __( 'Save Settings', 'dbtn-live-traffic' ) );
				?>
			</form>

			<hr>
			<h2><?php esc_html_e( 'Status', 'dbtn-live-traffic' ); ?></h2>
			<table class="widefat striped" style="max-width:640px;">
				<tbody>
					<tr>
						<td><strong><?php esc_html_e( 'Turnstile Site Key', 'dbtn-live-traffic' ); ?></strong></td>
						<td>
							<?php if ( $site_key_set ) : ?>
								<span style="color:#007017;">&#10003; <?php esc_html_e( 'Set', 'dbtn-live-traffic' ); ?></span>
							<?php else : ?>
								<span style="color:#b32d2e;">&#10007; <?php esc_html_e( 'Not set', 'dbtn-live-traffic' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Turnstile Secret Key', 'dbtn-live-traffic' ); ?></strong></td>
						<td>
							<?php if ( $secret_key_set ) : ?>
								<span style="color:#007017;">&#10003; <?php esc_html_e( 'Set', 'dbtn-live-traffic' ); ?></span>
							<?php else : ?>
								<span style="color:#b32d2e;">&#10007; <?php esc_html_e( 'Not set', 'dbtn-live-traffic' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'SITE_KEY constant', 'dbtn-live-traffic' ); ?></strong></td>
						<td><?php echo defined( 'DBTN_TURNSTILE_INVISIBLE_SITE_KEY' ) ? '<span style="color:#007017;">&#10003; Defined</span>' : '<span style="color:#b32d2e;">&#10007; Not defined</span>'; ?></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Client IP resolution', 'dbtn-live-traffic' ); ?></strong></td>
						<td><?php echo esc_html( dbtn\Support\DBTN_Utilities::get_client_ip() ? dbtn\Support\DBTN_Utilities::get_client_ip() : '(could not resolve)' ); ?></td>
					</tr>
					<tr>
						<?php $canonical_host = wp_parse_url( site_url(), PHP_URL_HOST ); ?>
						<td><?php echo esc_html( is_string( $canonical_host ) && '' !== $canonical_host ? $canonical_host : '(unknown)' ); ?></td>
					</tr>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Render the Turnstile section description.
	 *
	 * @return void
	 */
	public function render_turnstile_section_description(): void {
		?>
		<p>
			<?php
			printf(
				/* translators: %s: Cloudflare dashboard URL */
				esc_html__( 'Enter keys for a Cloudflare Turnstile widget set to %s mode. The site key is embedded in front-end JS; the secret key is used server-side only and never exposed to visitors.', 'dbtn-live-traffic' ),
				'<strong>' . esc_html__( 'Invisible', 'dbtn-live-traffic' ) . '</strong>'
			);
			?>
		</p>
		<?php
	}

	/**
	 * Render the logs directory section description.
	 *
	 * @return void
	 */
	public function render_logs_section_description(): void {
		$doc_root     = isset( $_SERVER['DOCUMENT_ROOT'] )
			? sanitize_text_field( wp_unslash( $_SERVER['DOCUMENT_ROOT'] ) )
			: '';
		$default_path = '' !== $doc_root ? dirname( $doc_root ) . '/logs/' : '/path/to/logs/';
		?>
		<p>
			<?php
			printf(
				/* translators: %s: example log path */
				esc_html__( 'Server logs are read from one directory above your document root by default (e.g. %s). Override this if your log path differs.', 'dbtn-live-traffic' ),
				'<code>' . esc_html( $default_path ) . '</code>'
			);
			?>
		</p>
		<?php
	}

	/**
	 * Render the site key input field.
	 *
	 * @return void
	 */
	public function render_field_site_key(): void {
		$opts = get_option( self::OPTION, array() );
		$val  = is_array( $opts ) ? ( $opts['turnstile_site_key'] ?? '' ) : '';
		?>
		<input
			type="text"
			id="turnstile_site_key"
			name="<?php echo esc_attr( self::OPTION ); ?>[turnstile_site_key]"
			value="<?php echo esc_attr( $val ); ?>"
			class="regular-text"
			placeholder="0x4AAAAAAA…"
			autocomplete="off"
			spellcheck="false"
		>
		<p class="description">
			<?php esc_html_e( 'Turnstile site key (public — embedded in page HTML). Widget type must be Invisible.', 'dbtn-live-traffic' ); ?>
		</p>
		<?php
	}

	/**
	 * Render the secret key input field.
	 *
	 * @return void
	 */
	public function render_field_secret_key(): void {
		$opts = get_option( self::OPTION, array() );
		$val  = is_array( $opts ) ? ( $opts['turnstile_secret_key'] ?? '' ) : '';
		?>
		<input
			type="password"
			id="turnstile_secret_key"
			name="<?php echo esc_attr( self::OPTION ); ?>[turnstile_secret_key]"
			value="<?php echo esc_attr( $val ); ?>"
			class="regular-text"
			placeholder="<?php esc_attr_e( 'Secret key (never exposed to visitors)', 'dbtn-live-traffic' ); ?>"
			autocomplete="new-password"
			spellcheck="false"
		>
		<p class="description">
			<?php esc_html_e( 'Turnstile secret key. Used for server-side token verification only.', 'dbtn-live-traffic' ); ?>
		</p>
		<?php
	}

	/**
	 * Render the Turnstile credential validation control.
	 *
	 * @return void
	 */
	public function render_turnstile_validate_button(): void {
		?>
		<button type="button" class="button button-secondary dbtn-validate-credentials" id="dbtn-validate-turnstile">
			<?php esc_html_e( 'Validate Turnstile', 'dbtn-live-traffic' ); ?>
		</button>
		<span class="spinner" aria-hidden="true"></span>
		<span id="dbtn-turnstile-validation-status" class="dbtn-validation-status" role="status" aria-live="polite"></span>
		<p class="description">
			<?php esc_html_e( 'Tests the values currently entered above; saving is not required first.', 'dbtn-live-traffic' ); ?>
		</p>
		<?php
	}


	/**
	 * Render the GeoIP section description.
	 *
	 * @return void
	 */
	public function render_geoip_section_description(): void {
		?>
		<p>
			<?php esc_html_e( 'Enter your MaxMind credentials to install and automatically update the GeoLite2 City database. Credentials are used only for database downloads and are never exposed to visitors.', 'dbtn-live-traffic' ); ?>
		</p>
		<?php
	}

	/**
	 * Render MaxMind account ID field.
	 *
	 * @return void
	 */
	public function render_field_maxmind_account_id(): void {
		$opts = get_option( self::OPTION, array() );
		$val  = is_array( $opts ) ? ( $opts['maxmind_account_id'] ?? '' ) : '';
		?>
		<input
			type="text"
			id="maxmind_account_id"
			name="<?php echo esc_attr( self::OPTION ); ?>[maxmind_account_id]"
			value="<?php echo esc_attr( $val ); ?>"
			class="regular-text"
			autocomplete="off"
			spellcheck="false"
		>
		<p class="description">
			<?php esc_html_e( 'MaxMind Account ID used for GeoLite2 database downloads.', 'dbtn-live-traffic' ); ?>
		</p>
		<?php
	}

	/**
	 * Render MaxMind license key field.
	 *
	 * @return void
	 */
	public function render_field_maxmind_license_key(): void {
		$opts = get_option( self::OPTION, array() );
		$val  = is_array( $opts ) ? ( $opts['maxmind_license_key'] ?? '' ) : '';
		?>
		<input
			type="password"
			id="maxmind_license_key"
			name="<?php echo esc_attr( self::OPTION ); ?>[maxmind_license_key]"
			value="<?php echo esc_attr( $val ); ?>"
			class="regular-text"
			autocomplete="new-password"
			spellcheck="false"
		>
		<p class="description">
			<?php esc_html_e( 'MaxMind license key. Used server-side only to download the database.', 'dbtn-live-traffic' ); ?>
		</p>
		<?php
	}

	/**
	 * Render the MaxMind credential validation control.
	 *
	 * @return void
	 */
	public function render_maxmind_validate_button(): void {
		?>
		<button type="button" class="button button-secondary dbtn-validate-credentials" id="dbtn-validate-maxmind">
			<?php esc_html_e( 'Validate MaxMind', 'dbtn-live-traffic' ); ?>
		</button>
		<span class="spinner" aria-hidden="true"></span>
		<span id="dbtn-maxmind-validation-status" class="dbtn-validation-status" role="status" aria-live="polite"></span>
		<p class="description">
			<?php esc_html_e( 'Tests the values currently entered above; saving is not required first.', 'dbtn-live-traffic' ); ?>
		</p>
		<?php
	}

	/**
	 * Render the logs directory input field.
	 *
	 * @return void
	 */
	public function render_field_logs_dir(): void {
		$opts         = get_option( self::OPTION, array() );
		$val          = is_array( $opts ) ? ( $opts['logs_dir'] ?? '' ) : '';
		$doc_root     = isset( $_SERVER['DOCUMENT_ROOT'] )
			? sanitize_text_field( wp_unslash( $_SERVER['DOCUMENT_ROOT'] ) )
			: '';
		$default_path = '' !== $doc_root ? dirname( $doc_root ) . '/logs/' : '';
		?>
		<input
			type="text"
			id="logs_dir"
			name="<?php echo esc_attr( self::OPTION ); ?>[logs_dir]"
			value="<?php echo esc_attr( $val ); ?>"
			class="regular-text"
			placeholder="<?php echo esc_attr( $default_path ); ?>"
			autocomplete="off"
			spellcheck="false"
		>
		<p class="description">
			<?php esc_html_e( 'Leave blank to use the default path above. Must be an absolute path with a trailing slash.', 'dbtn-live-traffic' ); ?>
		</p>
		<?php
	}
}
