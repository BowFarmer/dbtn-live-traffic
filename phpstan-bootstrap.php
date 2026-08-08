<?php

// ── Constants ─────────────────────────────────────────────────────────────────

if ( function_exists( 'plugin_basename' ) ) {
	define( 'DBTN_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
} else {
	define( 'DBTN_PLUGIN_BASENAME', 'dbtn-live-traffic/dbtn-live-traffic.php' );
}

if ( function_exists( 'plugin_dir_path' ) ) {
	define( 'DBTN_LT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
} else {
	define( 'DBTN_LT_PLUGIN_DIR', __DIR__ . '/' );
}

if ( function_exists( 'plugin_dir_url' ) ) {
	define( 'DBTN_LT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
} else {
	define( 'DBTN_LT_PLUGIN_URL', '' );
}

define( 'DBTN_LT_VERSION',    '1.0.15' );
define( 'DBTN_LT_PLUGIN_FILE', __FILE__ );

/** Path used by Traffic module to locate its own assets. */
define( 'DBTN_ADMIN_DIR', DBTN_LT_PLUGIN_DIR . 'admin/' );
define( 'DBTN_ADMIN_URL', DBTN_LT_PLUGIN_URL . 'admin/' );

/** Path used by visitor validator to locate its own JS asset. */
define( 'DBTN_ASSETS_JS_DIR', DBTN_LT_PLUGIN_DIR . 'assets/js/' );
define( 'DBTN_ASSETS_JS_URL', DBTN_LT_PLUGIN_URL . 'assets/js/' );

define( 'DBTN_PLUGIN_VERSION', DBTN_LT_VERSION );

define('ARRAY_A','dummy');

// ── MaxMind GeoIP configuration ───────────────────────────────────────────────
define( 'DBTN_GEOIP_DIR', DBTN_LT_PLUGIN_DIR . 'GeoLite2/' );
define( 'DBTN_GEOIP_EID', 'GeoLite2-City' );

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 60 * MINUTE_IN_SECONDS );
}

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 24 * HOUR_IN_SECONDS );
}