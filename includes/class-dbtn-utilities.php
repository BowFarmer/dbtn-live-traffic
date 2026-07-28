<?php
/**
 * Utility helpers for DBTN Live Traffic.
 *
 * Provides get_client_ip() (Cloudflare-aware) and verify_turnstile().
 * These are the two methods the Traffic REST controller and Visitor Validator
 * reference as dbtn\Support\DBTN_Utilities::.
 *
 * @package DBTN_Live_Traffic
 */

declare( strict_types=1 );

namespace dbtn\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Static helpers.
 */
final class DBTN_Utilities {

	/**
	 * Resolve the real client IP, respecting Cloudflare headers when
	 * REMOTE_ADDR is a known Cloudflare range (set via nginx real_ip).
	 *
	 * In practice, if you're behind Cloudflare at the nginx layer your
	 * REMOTE_ADDR will already be the visitor's IP (nginx realip module),
	 * so this is a belt-and-suspenders fallback.
	 *
	 * @return string Validated IP, or empty string if unresolvable.
	 */
	public static function get_client_ip(): string {
		// CF-Connecting-IP is set by Cloudflare and forwarded by nginx realip.
		$candidates = array(
			isset( $_SERVER['HTTP_CF_CONNECTING_IP'] )
				? sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) )
				: '',
			isset( $_SERVER['REMOTE_ADDR'] )
				? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
				: '',
		);

		foreach ( $candidates as $ip ) {
			$ip = is_string( $ip ) ? trim( $ip ) : '';

			if ( '' !== $ip && false !== filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				return $ip;
			}
		}

		return '';
	}

	/**
	 * Verify a Cloudflare Turnstile token against the siteverify API.
	 *
	 * @param string $token      Token from the client.
	 * @param string $secret_key Turnstile secret key.
	 * @param string $ip         Client IP (optional, passed as remoteip).
	 * @return bool True when Cloudflare confirms the token is valid.
	 */
	public static function verify_turnstile( string $token, string $secret_key, string $ip = '' ): bool {
		if ( '' === $token || '' === $secret_key ) {
			return false;
		}

		$body = array(
			'secret'   => $secret_key,
			'response' => $token,
		);

		if ( '' !== $ip ) {
			$body['remoteip'] = $ip;
		}

		$response = wp_remote_post(
			'https://challenges.cloudflare.com/turnstile/v0/siteverify',
			array(
				'timeout' => 10,
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		return is_array( $data ) && ! empty( $data['success'] );
	}
}
