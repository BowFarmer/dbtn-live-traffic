<?php
/**
 * Plugin Name:       DBTN Live Traffic
 * Plugin URI:
 * Description:       Live Traffic admin panel with Cloudflare Turnstile visitor validation. Standalone version for WPMU Dev sites.
 * Version:           1.0.20
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Daniel Voran
 * Author URI:
 * License:           GPL-2.0-or-later
 * Text Domain:       dbtn-live-traffic
 *
 * @package DBTN_Live_Traffic
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

// ── Constants ─────────────────────────────────────────────────────────────────

define( 'DBTN_LT_VERSION', '1.0.20' );
define( 'DBTN_LT_PLUGIN_FILE', __FILE__ );
define( 'DBTN_LT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'DBTN_LT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/** Path used by Traffic module to locate its own assets. */
define( 'DBTN_ADMIN_DIR', DBTN_LT_PLUGIN_DIR . 'admin/' );
define( 'DBTN_ADMIN_URL', DBTN_LT_PLUGIN_URL . 'admin/' );

/** Path used by visitor validator to locate its own JS asset. */
define( 'DBTN_ASSETS_JS_DIR', DBTN_LT_PLUGIN_DIR . 'assets/js/' );
define( 'DBTN_ASSETS_JS_URL', DBTN_LT_PLUGIN_URL . 'assets/js/' );

define( 'DBTN_PLUGIN_VERSION', DBTN_LT_VERSION );

// ── MaxMind GeoIP configuration ───────────────────────────────────────────────
define( 'DBTN_GEOIP_DIR', DBTN_LT_PLUGIN_DIR . 'GeoLite2/' );
define( 'DBTN_GEOIP_EID', 'GeoLite2-City' );

// ── Credential constants (pulled from options at runtime) ────────────────────

add_action(
	'plugins_loaded',
	function (): void {
		$opts = get_option( 'dbtn_lt_settings', array() );

		$site_key            = is_array( $opts ) && ! empty( $opts['turnstile_site_key'] )
			? (string) $opts['turnstile_site_key'] : '';
		$secret_key          = is_array( $opts ) && ! empty( $opts['turnstile_secret_key'] )
			? (string) $opts['turnstile_secret_key'] : '';
		$maxmind_account_id  = is_array( $opts ) && ! empty( $opts['maxmind_account_id'] )
			? (string) $opts['maxmind_account_id'] : '';
		$maxmind_license_key = is_array( $opts ) && ! empty( $opts['maxmind_license_key'] )
			? (string) $opts['maxmind_license_key'] : '';

		if ( $site_key && ! defined( 'DBTN_TURNSTILE_INVISIBLE_SITE_KEY' ) ) {
			define( 'DBTN_TURNSTILE_INVISIBLE_SITE_KEY', $site_key );
		}

		if ( $secret_key && ! defined( 'DBTN_TURNSTILE_INVISIBLE_SECRET_KEY' ) ) {
			define( 'DBTN_TURNSTILE_INVISIBLE_SECRET_KEY', $secret_key );
		}

		if ( $maxmind_account_id && ! defined( 'DBTN_GEOIP_AID' ) ) {
			define( 'DBTN_GEOIP_AID', $maxmind_account_id );
		}

		if ( $maxmind_license_key && ! defined( 'DBTN_GEOIP_LKY' ) ) {
			define( 'DBTN_GEOIP_LKY', $maxmind_license_key );
		}
	},
	1 // Priority 1 — before any init hook that needs these.
);

// ── Autoload ──────────────────────────────────────────────────────────────────

require_once DBTN_LT_PLUGIN_DIR . 'includes/class-dbtn-utilities.php';
require_once DBTN_LT_PLUGIN_DIR . 'includes/class-dbtn-geoip-update.php';
require_once DBTN_LT_PLUGIN_DIR . 'includes/class-dbtn-visitor-validator.php';
require_once DBTN_LT_PLUGIN_DIR . 'includes/class-dbtn-validation-rest.php';
require_once DBTN_LT_PLUGIN_DIR . 'includes/class-dbtn-credentials-rest.php';
require_once DBTN_LT_PLUGIN_DIR . 'includes/class-dbtn-passport.php';
require_once DBTN_LT_PLUGIN_DIR . 'includes/class-dbtn-emails.php';
require_once DBTN_LT_PLUGIN_DIR . 'admin/traffic/class-dbtn-traffic-log-reader.php';
require_once DBTN_LT_PLUGIN_DIR . 'admin/traffic/class-dbtn-traffic-report-403-404.php';
require_once DBTN_LT_PLUGIN_DIR . 'admin/traffic/class-dbtn-traffic-report-php-errors.php';
require_once DBTN_LT_PLUGIN_DIR . 'admin/traffic/class-dbtn-traffic-report-php-slow.php';
require_once DBTN_LT_PLUGIN_DIR . 'admin/traffic/class-dbtn-traffic-report-waf.php';
require_once DBTN_LT_PLUGIN_DIR . 'admin/traffic/class-dbtn-traffic-report-wp-cron.php';
require_once DBTN_LT_PLUGIN_DIR . 'admin/traffic/class-dbtn-traffic-report-visitors.php';
require_once DBTN_LT_PLUGIN_DIR . 'admin/traffic/class-dbtn-traffic-rest.php';
require_once DBTN_LT_PLUGIN_DIR . 'admin/traffic/class-dbtn-traffic.php';
require_once DBTN_LT_PLUGIN_DIR . 'admin/class-dbtn-lt-admin.php';
require_once DBTN_LT_PLUGIN_DIR . 'admin/class-dbtn-geoip.php';

// ── Bootstrap ─────────────────────────────────────────────────────────────────

add_action(
	'plugins_loaded',
	function (): void {
		// Validation REST endpoints (public, used by front-end JS).
		new dbtn\Support\DBTN_Validation_REST();
		new dbtn\Support\DBTN_Credentials_REST();

		// Front-end visitor validation script.
		dbtn\Support\DBTN_Visitor_Validator::init();

		// Traffic REST routes must register on every request context —
		// REST API requests arrive via /wp-json/ and are NOT is_admin().
		// DBTN_Traffic::init() hooks rest_api_init (always fires) and
		// admin_enqueue_scripts (no-op outside admin), so it is safe to
		// call unconditionally.
		\dbtn\Admin\Traffic\DBTN_Traffic::init(
			'toplevel_page_dbtn-live-traffic',
			'dbtn-live-traffic'
		);

		// Admin-only UI: menus and settings page.
		if ( is_admin() ) {
			new DBTN_LT_Admin();
		}

		// Make sure that DBTN_Geoip_Update is initiated in admin and wp_doing_cron.
		if ( is_admin() || wp_doing_cron() ) {
			\dbtn\Support\DBTN_Geoip_Update::init();
		}
	}
);

// ── Disable activation on multisites ────────────────────────────────────────────

register_activation_hook( __FILE__, 'dbtn_live_traffic_activate' );

function dbtn_live_traffic_activate( bool $network_wide = false ): void {
	if ( is_multisite() || $network_wide ) {
		deactivate_plugins( plugin_basename( __FILE__ ) );

		wp_die(
			esc_html__(
				'DBTN Live Traffic does not support WordPress Multisite installations.',
				'dbtn-live-traffic'
			)
		);
	}
}

add_action(
	'admin_notices',
	function (): void {
		if ( get_transient( 'dbtn_live_traffic_multisite_notice' ) ) {
			delete_transient( 'dbtn_live_traffic_multisite_notice' );

			echo '<div class="notice notice-error"><p>';
			echo esc_html(
				'DBTN Live Traffic does not support WordPress Multisite installations.'
			);
			echo '</p></div>';
		}
	}
);
