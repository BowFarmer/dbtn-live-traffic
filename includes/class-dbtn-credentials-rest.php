<?php
/**
 * Admin REST routes for validating third-party credentials.
 *
 * @package DBTN_Live_Traffic
 */

declare( strict_types=1 );

namespace dbtn\Support;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Validates unsaved Turnstile and MaxMind credentials for administrators.
 */
final class DBTN_Credentials_REST {

	/**
	 * Register the REST hook.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register credential-validation routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			'dbtn/v2',
			'/admin/credentials/turnstile',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'validate_turnstile' ),
				'permission_callback' => array( $this, 'can_manage_options' ),
				'show_in_index'       => false,
			)
		);

		register_rest_route(
			'dbtn/v2',
			'/admin/credentials/maxmind',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'validate_maxmind' ),
				'permission_callback' => array( $this, 'can_manage_options' ),
				'show_in_index'       => false,
			)
		);
	}

	/**
	 * Require the same capability as the settings page.
	 *
	 * @return bool
	 */
	public function can_manage_options(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Verify a browser-generated token with the entered Turnstile secret.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function validate_turnstile( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$token      = sanitize_text_field( (string) $request->get_param( 'token' ) );
		$secret_key = sanitize_text_field( (string) $request->get_param( 'secret_key' ) );

		if ( '' === $token || '' === $secret_key ) {
			return new WP_Error(
				'dbtn_missing_turnstile_credentials',
				__( 'A Turnstile token and secret key are required.', 'dbtn-live-traffic' ),
				array( 'status' => 400 )
			);
		}

		$body = array(
			'secret'   => $secret_key,
			'response' => $token,
		);
		$ip   = DBTN_Utilities::get_client_ip();

		if ( '' !== $ip ) {
			$body['remoteip'] = $ip;
		}

		$response = wp_remote_post(
			'https://challenges.cloudflare.com/turnstile/v0/siteverify',
			array(
				'timeout' => 15,
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'dbtn_turnstile_unreachable',
				__( 'Cloudflare could not be reached. Please try again.', 'dbtn-live-traffic' ),
				array( 'status' => 502 )
			);
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $data ) || empty( $data['success'] ) ) {
			return new WP_Error(
				'dbtn_turnstile_invalid',
				__( 'Turnstile rejected these credentials. Check both keys and the widget hostname settings.', 'dbtn-live-traffic' ),
				array( 'status' => 400 )
			);
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => __( 'Turnstile credentials are valid.', 'dbtn-live-traffic' ),
			),
			200
		);
	}

	/**
	 * Make an authenticated request to MaxMind's GeoLite2 download endpoint.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function validate_maxmind( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$account_id  = sanitize_text_field( (string) $request->get_param( 'account_id' ) );
		$license_key = sanitize_text_field( (string) $request->get_param( 'license_key' ) );

		if ( '' === $account_id || '' === $license_key ) {
			return new WP_Error(
				'dbtn_missing_maxmind_credentials',
				__( 'A MaxMind account ID and license key are required.', 'dbtn-live-traffic' ),
				array( 'status' => 400 )
			);
		}

		$url = 'https://download.maxmind.com/geoip/databases/GeoLite2-City/download?suffix=tar.gz';

		// HTTP Basic Authentication requires Base64 encoding.
		$authorization = 'Basic ' . base64_encode( $account_id . ':' . $license_key );
		$response      = wp_remote_get(
			$url,
			array(
				'timeout'             => 20,
				'redirection'         => 0,
				'limit_response_size' => 1024,
				'user-agent'          => 'DBTN-Live-Traffic/' . DBTN_LT_VERSION,
				'headers'             => array( 'Authorization' => $authorization ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'dbtn_maxmind_unreachable',
				__( 'MaxMind could not be reached. Please try again.', 'dbtn-live-traffic' ),
				array( 'status' => 502 )
			);
		}

		$status = wp_remote_retrieve_response_code( $response );

		if ( 200 <= $status && 400 > $status ) {
			return new WP_REST_Response(
				array(
					'success' => true,
					'message' => __( 'MaxMind credentials are valid.', 'dbtn-live-traffic' ),
				),
				200
			);
		}

		if ( 401 === $status || 403 === $status ) {
			return new WP_Error(
				'dbtn_maxmind_invalid',
				__( 'MaxMind rejected these credentials. Check the account ID and license key.', 'dbtn-live-traffic' ),
				array( 'status' => 400 )
			);
		}

		return new WP_Error(
			'dbtn_maxmind_error',
			__( 'MaxMind could not validate these credentials right now. Please try again.', 'dbtn-live-traffic' ),
			array( 'status' => 502 )
		);
	}
}
