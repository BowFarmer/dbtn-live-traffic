<?php
/**
 * GeoIP lookup stub for DBTN Live Traffic.
 *
 * The Traffic module's live-traffic REST route calls
 * dbtn\Admin\DBTN_GeoIP::lookup_string( $ip ) to annotate each row with a
 * location string. This file provides a lightweight implementation that uses
 * the free ip-api.com JSON endpoint with a short in-memory cache.
 *
 * If your hosting blocks outbound HTTP to ip-api.com, or if you have a
 * MaxMind GeoLite2 database available, swap the body of resolve() below.
 *
 * @package DBTN_Live_Traffic
 */

declare( strict_types=1 );

namespace dbtn\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * GeoIP helper.
 */
final class DBTN_GeoIP {

	/**
	 * In-memory cache for the current request: ip → "City, CC" or "CC".
	 *
	 * @var array<string, string>
	 */
	private static array $cache = array();

	/**
	 * Return a short human-readable location string for an IP address.
	 *
	 * @param string $ip IPv4 or IPv6 address.
	 * @return string e.g. "Seattle, US" or "DE" or "" on failure.
	 */
	public static function lookup_string( string $ip ): string {
		if ( '' === $ip ) {
			return '';
		}

		if ( isset( self::$cache[ $ip ] ) ) {
			return self::$cache[ $ip ];
		}

		// Transient cache: 24 h — GeoIP data is stable.
		$transient_key    = 'dbtn_geoip_' . md5( $ip );
		$cached_transient = get_transient( $transient_key );

		if ( is_string( $cached_transient ) ) {
			self::$cache[ $ip ] = $cached_transient;
			return $cached_transient;
		}

		$result = self::resolve( $ip );

		set_transient( $transient_key, $result, DAY_IN_SECONDS );
		self::$cache[ $ip ] = $result;

		return $result;
	}

	/**
	 * Alias kept for API compatibility with the host plugin.
	 *
	 * @param string $ip IPv4 or IPv6 address.
	 * @return array{city: string, country: string, label: string}
	 */
	public static function lookup( string $ip ): array {
		$label = self::lookup_string( $ip );

		// Parse "City, CC" back into parts for callers that want them.
		$parts   = array_map( 'trim', explode( ',', $label, 2 ) );
		$country = array_pop( $parts );
		$city    = array_pop( $parts ) ?? '';

		return array(
			'city'    => $city,
			'country' => $country,
			'label'   => $label,
		);
	}

	/**
	 * Make the actual GeoIP HTTP request. Swap this method to use MaxMind
	 * or another provider.
	 *
	 * @param string $ip IP address to resolve.
	 * @return string
	 */
	private static function resolve( string $ip ): string {
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return '';
		}

		$path = DBTN_LT_PLUGIN_DIR . 'GeoLite2/GeoLite2-City.mmdb';

		if ( ! file_exists( $path ) ) {
			return '';
		}

		require_once DBTN_LT_PLUGIN_DIR . 'vendor/autoload.php';

		try {
			$reader = new \GeoIp2\Database\Reader( $path );
			$record = $reader->city( $ip );

			// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			return implode(
				', ',
				array_filter(
					array(
						$record->city->name ?? '',
						$record->mostSpecificSubdivision->isoCode ?? '',
						$record->country->isoCode ?? '',
					)
				)
			);
			// phpcs:enable
		} catch ( \GeoIp2\Exception\AddressNotFoundException $e ) {
			return '';
		} catch ( \Exception $e ) {
			return '';
		}
	}
}
