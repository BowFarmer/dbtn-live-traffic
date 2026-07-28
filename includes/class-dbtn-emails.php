<?php
/**
 * DBTN_Emails is the class that handles sending email alerts
 *
 * @category DBTN_Emails
 * @package  DBTN_Subscriber
 * @author   Daniel Voran
 * @license  GPLv2 or later
 * @requires PHP 8.0 or higher
 */

declare(strict_types=1);

namespace dbtn\Support;

defined( 'ABSPATH' ) || exit;

/**
 * DBTN_Emails class implements sending email alerts.
 */
class DBTN_Emails {

	/**
	 * Function build_email_alert
	 * Builds the body of an email
	 *
	 * @param array{count:int,count_min:int,count2_min:int} $counts Array of counts to check.
	 * @param string                                        $user_ip_address IP address of customer.
	 * @param string                                        $logged_in_user  Name of logged in user.
	 *
	 * @return string
	 */
	public static function build_email_alert( array $counts, string $user_ip_address, string $logged_in_user ): string {
		$user_ip_address = sanitize_text_field( $user_ip_address );
		$logged_in_user  = sanitize_text_field( $logged_in_user );

		$email_alert  = "{$counts['count']} add to cart requests within the last 20 seconds from IP {$user_ip_address}\n";
		$email_alert .= "{$counts['count_min']} add to cart requests within the last minute\n";
		$email_alert .= "{$counts['count2_min']} add to cart requests within the two minutes";

		if ( $logged_in_user ) {
			$email_alert = "{$logged_in_user} unusual cart activity.\n{$email_alert}";
		}

		return $email_alert;
	}

	/**
	 * Function build_email_subject
	 * Builds the subject of an email
	 *
	 * @param string $user_ip_address IP address of customer.
	 * @param string $logged_in_user  Name of logged in user.
	 *
	 * @return string
	 */
	public static function build_email_subject( string $user_ip_address, string $logged_in_user ): string {
		$user_ip_address = sanitize_text_field( $user_ip_address );
		$logged_in_user  = sanitize_text_field( $logged_in_user );

		$email_subj = "IP {$user_ip_address} adding many items to cart";
		if ( $logged_in_user ) {
			$email_subj = "{$logged_in_user} at {$email_subj}";
		}
		return $email_subj;
	}

	/**
	 * Function should_send_email
	 * Checks if it is OK to send email. Avoids sending too many emails in a short span of time
	 *
	 * @param string $user_ip_address IP address of customer.
	 *
	 * @return boolean
	 */
	public static function should_send_email( string $user_ip_address ): bool {
		$user_ip_address = sanitize_text_field( $user_ip_address );

		$last_email_time = get_transient( "ip_{$user_ip_address}_cart_email_warning" );
		$now             = time();

		if ( $last_email_time && ( $now - $last_email_time <= 120 ) ) {
			return false;
		}

		set_transient( "ip_{$user_ip_address}_cart_email_warning", $now, 120 );

		return true;
	}

	/**
	 * Function get_admin_email
	 * Return the admin email.
	 *
	 * @return string
	 */
	private static function get_admin_email(): string {
		$email = get_option( 'admin_email' );

		return is_email( $email ) ? $email : '';
	}

	/**
	 * Function send_email_to_admin
	 * Sends email from admin to admin
	 *
	 * @param string $email_subj  Subject of email.
	 * @param string $email_alert Body of email.
	 * @param bool   $as_html     Indicator whether to send email as HTML or text.
	 *
	 * @return void
	 */
	public static function send_email_to_admin( string $email_subj, string $email_alert, bool $as_html = false ): void {
		$admin_email = apply_filters( 'dbtn_email_admin_recipient', get_option( 'admin_email' ) );

		$headers = array(
			'From: ' . get_bloginfo( 'name' ) . ' <' . self::get_admin_email() . '>',
			( $as_html ? 'Content-Type: text/html; charset=UTF-8' : 'Content-Type: text/plain; charset=UTF-8' ),
		);

		if ( $as_html ) {
			$email_alert = wpautop( $email_alert );
		}

		wp_mail( $admin_email, $email_subj, $email_alert, $headers );
	}
}
