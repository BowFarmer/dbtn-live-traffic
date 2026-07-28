<?php
/**
 * Validation REST routes.
 *
 * @package DBTN_Subscriber
 */

declare( strict_types=1 );

namespace dbtn\Support;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Registers and handles validation REST endpoints.
 */
final class DBTN_Validation_REST {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register validation REST routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			'dbtn/v2',
			'/validation/ip',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'validate_ip' ),
				'permission_callback' => '__return_true',
				'show_in_index'       => false,
				'args'                => array(
					'token' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			'dbtn/v2',
			'/validation/assert',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'assert_ip' ),
				'permission_callback' => '__return_true',
				'show_in_index'       => false,
			)
		);
	}

	/**
	 * Verify a Turnstile token and mark the caller's IP as validated.
	 *
	 * Security for this public endpoint comes from the Turnstile token,
	 * not from WordPress authentication. On success it also issues the portable
	 * human-grant cookie so the caller can stay validated as their IP rotates.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function validate_ip( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$token = (string) $request->get_param( 'token' );

		if ( '' === $token ) {
			return new WP_Error(
				'missing_token',
				'Token required.',
				array( 'status' => 400 )
			);
		}

		if ( ! defined( 'DBTN_TURNSTILE_INVISIBLE_SECRET_KEY' ) ) {
			return new WP_Error(
				'misconfigured',
				'Server not configured for Turnstile.',
				array( 'status' => 500 )
			);
		}

		$ip = DBTN_Utilities::get_client_ip();

		if ( '' === $ip ) {
			return new WP_Error(
				'missing_ip',
				'Could not determine client IP.',
				array( 'status' => 400 )
			);
		}

		if ( ! DBTN_Utilities::verify_turnstile( $token, DBTN_TURNSTILE_INVISIBLE_SECRET_KEY, $ip ) ) {
			return new WP_Error(
				'turnstile_failed',
				'Verification failed.',
				array( 'status' => 403 )
			);
		}

		DBTN_Visitor_Validator::mark_ip_valid( $ip );
		DBTN_Visitor_Validator::issue_grant_cookie();

		return rest_ensure_response(
			array(
				'success' => true,
			)
		);
	}

	/**
	 * Re-mark the caller's current IP as validated using the human-grant cookie.
	 *
	 * Unlike validate_ip(), this trusts the signed dbtn_human cookie rather than
	 * a fresh Turnstile token, so a visitor who already passed Turnstile stays
	 * "green" as their egress IP rotates — Private Relay, or moving between
	 * networks. The grant carries no IP; only the request's own resolved IP is
	 * ever marked, so a copied cookie cannot validate an arbitrary address. With
	 * no valid grant the call is a harmless no-op that returns success=false.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response
	 */
	public function assert_ip( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		DBTN_Visitor_Validator::touch_current_ip();

		$ip = DBTN_Utilities::get_client_ip();

		return new WP_REST_Response(
			array(
				'success' => '' !== $ip && DBTN_Visitor_Validator::is_ip_valid( $ip ),
			),
			200
		);
	}
}
