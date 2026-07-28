<?php
/**
 * DBTN_Visitor_Validator — identifies human visitors via Cloudflare Turnstile
 * and stores their IPs in WordPress transients for the Live Traffic display.
 *
 * Flow:
 *   1. Frontend JS (dbtn-visitor-validate.js) mints an invisible Turnstile token
 *      after page load and POSTs it to /dbtn/v2/validation/ip.
 *   2. The REST handler verifies the token with Cloudflare, calls mark_ip_valid(),
 *      and issues a portable HMAC "human grant" cookie (issue_grant_cookie()).
 *   3. On later page loads the JS pings /dbtn/v2/validation/assert (rest_assert()).
 *      That endpoint trusts the signed cookie — not a fresh Turnstile token — and
 *      re-marks whatever IP the request currently arrives from. This is what lets
 *      a single human stay "green" as Private Relay rotates them across
 *      Cloudflare / Akamai / Fastly egress IPs within a session.
 *   4. The Live Traffic admin panel calls is_ip_valid() per row to highlight
 *      confirmed-human IPs in green.
 *
 * The grant is an attestation ("this browser passed Turnstile"), deliberately
 * NOT bound to an IP — propagation across IPs is the feature. The client never
 * supplies an IP; the server only ever marks the IP it resolved for the request,
 * so a leaked cookie can mark someone's *own* current IP and nothing else.
 *
 * @package  DBTN_Subscriber
 * @author   Daniel Voran
 * @license  GPLv2 or later
 * @requires PHP 8.0 or higher
 */

declare( strict_types=1 );

namespace dbtn\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Class DBTN_Visitor_Validator
 */
class DBTN_Visitor_Validator {

	/** Transient key prefix for validated IPs. */
	private const TRANSIENT_PREFIX = 'dbtn_valid_ip_';

	/**
	 * How long a validated IP stays trusted.
	 * Server-side transient TTL — keep in sync with client localStorage intent.
	 * Default: 7 days.
	 */
	private const EXPIRY = 7 * DAY_IN_SECONDS;

	/** Cookie name for the portable human grant. */
	private const GRANT_COOKIE = 'dbtn_human';


	/**
	 * Register hooks. Call once from bootstrap.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'wp_enqueue_scripts', [ self::class, 'enqueue_validation_script' ] );
	}


	/**
	 * Enqueue the passport + visitor-validate scripts on all public pages.
	 *
	 * The scripts are lightweight and load in the footer after the page renders,
	 * so they have zero impact on Time-to-First-Byte or LCP.
	 *
	 * @return void
	 */
	public static function enqueue_validation_script(): void {

		// Bail if the invisible site key isn't configured.
		if ( ! defined( 'DBTN_TURNSTILE_INVISIBLE_SITE_KEY' ) ) {
			return;
		}

		// 1. Passport script — mints invisible Turnstile tokens.
		$passport_src  = DBTN_ASSETS_JS_URL . 'dbtn-passport.js';
		$passport_path = DBTN_ASSETS_JS_DIR . 'dbtn-passport.js';
		$passport_ver  = file_exists( $passport_path ) ? (string) filemtime( $passport_path ) : DBTN_PLUGIN_VERSION;

		wp_enqueue_script(
			'dbtn-turnstile-passport',
			$passport_src,
			array(),
			$passport_ver,
			true // footer.
		);

		wp_localize_script(
			'dbtn-turnstile-passport',
			'dbtnPassport',
			array(
				'siteKey' => DBTN_TURNSTILE_INVISIBLE_SITE_KEY,
			)
		);

		// 2. Our thin validation wrapper — depends on passport being ready.
		$src     = DBTN_ASSETS_JS_URL . 'dbtn-visitor-validate.js';
		$path    = DBTN_ASSETS_JS_DIR . 'dbtn-visitor-validate.js';
		$version = file_exists( $path ) ? (string) filemtime( $path ) : DBTN_PLUGIN_VERSION;
		wp_enqueue_script(
			'dbtn-visitor-validate',
			$src,
			array( 'dbtn-turnstile-passport' ),
			$version,
			true // footer.
		);

		wp_localize_script(
			'dbtn-visitor-validate',
			'dbtnValidate',
			array(
				'restUrl'   => rest_url( 'dbtn/v2/validation/ip' ),
				'assertUrl' => rest_url( 'dbtn/v2/validation/assert' ),
				'nonce'     => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
			)
		);
	}


	/**
	 * Mark an IP address as human-validated. Stores a WordPress transient
	 * that auto-expires after EXPIRY seconds.
	 *
	 * @param string $ip IP address confirmed by Turnstile.
	 * @return void
	 */
	public static function mark_ip_valid( string $ip ): void {
		if ( '' === $ip ) {
			return;
		}

		$ip_hash = md5( $ip );

		set_transient( self::TRANSIENT_PREFIX . $ip_hash, true, self::EXPIRY );
		self::count_daily_human_visit( $ip_hash );
	}

	/**
	 * Count a validated IP once per WordPress calendar day.
	 *
	 * @param string $ip_hash MD5 hash of the validated IP address.
	 * @return void
	 */
	private static function count_daily_human_visit( string $ip_hash ): void {
		global $wpdb;

		$timezone        = wp_timezone();
		$now             = new \DateTimeImmutable( 'now', $timezone );
		$tomorrow        = $now->modify( 'tomorrow' )->setTime( 0, 0 );
		$date            = $now->format( 'Y-m-d' );
		$expiry          = max( 1, $tomorrow->getTimestamp() - $now->getTimestamp() );
		$daily_ip_key    = 'dbtn_human_visit_' . $date . '_' . $ip_hash;
		$daily_count_key = 'dbtn_human_visits_' . $date;

		if ( false !== get_transient( $daily_ip_key ) ) {
			return;
		}

		set_transient( $daily_ip_key, true, $expiry );

		if ( add_option( $daily_count_key, 1, '', false ) ) {
			return;
		}

		// Atomic increment prevents lost updates from concurrent requests.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options}
				SET option_value = CAST( option_value AS UNSIGNED ) + 1
				WHERE option_name = %s",
				$daily_count_key
			)
		);

		wp_cache_delete( $daily_count_key, 'options' );
	}


	/**
	 * Store the username associated with an IP at login time.
	 * TTL matches a typical session — expires automatically at 8 hours.
	 *
	 * @param string $ip        Client IP address.
	 * @param string $user_login WordPress user_login.
	 */
	public static function mark_ip_user( string $ip, string $user_login ): void {
		if ( '' === $ip ) {
			return;
		}
		set_transient(
			'dbtn_login_ip_' . md5( $ip ),
			$user_login,
			8 * HOUR_IN_SECONDS
		);
	}


	/**
	 * Check whether an IP is currently marked as validated.
	 *
	 * @param string $ip IP address to look up.
	 * @return bool True if the IP has a live validation transient.
	 */
	public static function is_ip_valid( string $ip ): bool {
		if ( '' === $ip ) {
			return false;
		}
		return (bool) get_transient( self::TRANSIENT_PREFIX . md5( $ip ) );
	}

	/**
	 * Return the username logged in from this IP, or empty string if none.
	 *
	 * @param string $ip IP address to look up.
	 * @return string
	 */
	public static function get_ip_user( string $ip ): string {
		if ( '' === $ip ) {
			return '';
		}
		$user_login = get_transient( 'dbtn_login_ip_' . md5( $ip ) );

		return is_string( $user_login ) ? $user_login : '';
	}


	/*
	 * ---------------------------------------------------------------------
	 * Portable human grant (survives Private Relay IP rotation)
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Issue the signed, HttpOnly human-grant cookie.
	 *
	 * Call this from the /validation/ip handler immediately after a Turnstile
	 * token verifies and you've called mark_ip_valid(). The cookie rides along
	 * automatically on every subsequent same-origin request, so the /assert
	 * endpoint can re-mark new egress IPs without another Turnstile challenge.
	 *
	 * @return void
	 */
	public static function issue_grant_cookie(): void {
		$exp     = time() + self::EXPIRY;
		$payload = self::b64url(
			(string) wp_json_encode(
				[
					'v'   => 1,
					'exp' => $exp,
				]
			)
		);
		$sig     = self::b64url( hash_hmac( 'sha256', $payload, self::grant_secret(), true ) );
		$token   = $payload . '.' . $sig;

		setcookie(
			self::GRANT_COOKIE,
			$token,
			[
				'expires'  => $exp,
				'path'     => defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => defined( 'COOKIE_DOMAIN' ) ? (string) COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			]
		);

		// setcookie() only affects the *next* request; mirror it into $_COOKIE
		// so verify_grant() works within this same request if needed.
		$_COOKIE[ self::GRANT_COOKIE ] = $token;
	}


	/**
	 * Verify the human-grant cookie (or an explicitly supplied token).
	 * Constant-time signature check + expiry check. No DB access.
	 *
	 * @param string|null $token Optional token; defaults to the request cookie.
	 * @return bool
	 */
	public static function verify_grant( ?string $token = null ): bool {
		if ( null === $token ) {
			$token = isset( $_COOKIE[ self::GRANT_COOKIE ] )
				? sanitize_text_field( wp_unslash( $_COOKIE[ self::GRANT_COOKIE ] ) )
				: '';
		}
		if ( ! is_string( $token ) || '' === $token || 1 !== substr_count( $token, '.' ) ) {
			return false;
		}

		[ $payload, $sig ] = explode( '.', $token, 2 );

		$expected = self::b64url( hash_hmac( 'sha256', $payload, self::grant_secret(), true ) );
		if ( ! hash_equals( $expected, $sig ) ) {
			return false;
		}

		$data = json_decode( self::b64url_decode( $payload ), true );
		if ( ! is_array( $data ) || empty( $data['exp'] ) || time() > (int) $data['exp'] ) {
			return false;
		}

		return true;
	}


	/**
	 * Re-mark the current request's IP if it carries a valid grant.
	 * Idempotent and cheap — steady state is one Redis read; a write
	 * only happens the first time a rotated IP shows up.
	 *
	 * @return void
	 */
	public static function touch_current_ip(): void {
		$ip = self::current_ip();
		if ( '' === $ip ) {
			return;
		}
		if ( ! self::is_ip_valid( $ip ) && self::verify_grant() ) {
			self::mark_ip_valid( $ip );
		}
		if ( is_user_logged_in() && '' === self::get_ip_user( $ip ) ) {
			self::mark_ip_user( $ip, wp_get_current_user()->user_login );
		}
	}


	/**
	 * REST callback for /dbtn/v2/validation/assert.
	 *
	 * Trusts the signed grant cookie (NOT a fresh Turnstile token) and re-marks
	 * the current request's resolved IP as human. Also refreshes the username→IP
	 * map for logged-in users so it follows them across rotation. Because this is
	 * an uncached POST it always reaches the origin, unlike a cached page view.
	 *
	 * @return \WP_REST_Response
	 */
	public static function rest_assert(): \WP_REST_Response {
		$marked = false;
		$ip     = self::current_ip();

		if ( '' !== $ip && self::verify_grant() ) {
			// Only a write when this egress IP is new to us (Redis hit otherwise).
			if ( ! self::is_ip_valid( $ip ) ) {
				self::mark_ip_valid( $ip );
			}
			$marked = true;

			if ( is_user_logged_in() && '' === self::get_ip_user( $ip ) ) {
				self::mark_ip_user( $ip, wp_get_current_user()->user_login );
			}
		}

		return new \WP_REST_Response( [ 'success' => $marked ], 200 );
	}


	/*
	 * ---------------------------------------------------------------------
	 * Internals
	 * ---------------------------------------------------------------------
	 */

	/**
	 * HMAC secret for the grant. Define DBTN_HUMAN_GRANT_SECRET for an isolated
	 * key; otherwise derive a stable one from the WP auth salt.
	 *
	 * @return string
	 */
	private static function grant_secret(): string {
		if ( defined( 'DBTN_HUMAN_GRANT_SECRET' ) && DBTN_HUMAN_GRANT_SECRET ) {
			return (string) DBTN_HUMAN_GRANT_SECRET;
		}
		return hash_hmac( 'sha256', 'dbtn-visitor-grant-v1', wp_salt( 'auth' ) );
	}


	/**
	 * Resolve the real client IP.
	 *
	 * TODO: wire this to your canonical Cloudflare-aware resolver — the one that
	 * confirms REMOTE_ADDR is in Cloudflare's ranges (you do this in nginx realip)
	 * before trusting CF-Connecting-IP. The fallback below is only a safety net.
	 *
	 * @return string
	 */
	private static function current_ip(): string {
		$ip = '';

		if ( isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
		} elseif ( isset( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}

		$ip = trim( $ip );

		return false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}


	/**
	 * URL-safe base64 encode without padding.
	 *
	 * @param string $bin Binary string to encode.
	 * @return string
	 */
	private static function b64url( string $bin ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Used for URL-safe token encoding, not code obfuscation.
		return rtrim( strtr( base64_encode( $bin ), '+/', '-_' ), '=' );
	}


	/**
	 * URL-safe base64 decode.
	 *
	 * @param string $s Encoded string to decode.
	 * @return string
	 */
	private static function b64url_decode( string $s ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Used for URL-safe token decoding, not code obfuscation.
		return (string) base64_decode( strtr( $s, '-_', '+/' ), true );
	}
}
