<?php
/**
 * Thin HTTP client for the ZevSend email API.
 *
 * Built on the WordPress HTTP API only — no bundled Composer tree, no
 * Guzzle. One class, Bearer auth, 30s timeout, a single retry on a
 * transient failure (connection error or 5xx). Throws an Exception on
 * failure with an escaped, human-readable message.
 *
 * Security notes:
 *   - The API key travels in the Authorization header and is NEVER
 *     written to any log, error, or exception message, not even a
 *     prefix of it.
 *   - TLS verification is left at the WordPress default (on). We never
 *     disable sslverify.
 *
 * @package ZevSend_SMTP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exception carrying the ZevSend error code alongside the message, so
 * the UI can map it to a plain-language hint (see
 * zevsend_smtp_error_hint()).
 */
class ZevSend_SMTP_Api_Exception extends Exception {

	/**
	 * @var string
	 */
	private $error_code;

	/**
	 * @param string $message    Human message.
	 * @param string $error_code ZevSend error code (e.g. from_domain_unverified).
	 */
	public function __construct( $message, $error_code = '' ) {
		parent::__construct( $message );
		$this->error_code = (string) $error_code;
	}

	/**
	 * @return string
	 */
	public function get_error_code() {
		return $this->error_code;
	}
}

class ZevSend_SMTP_Api_Client {

	/**
	 * Base URL of the ZevSend developer API. This is a public, documented
	 * endpoint — safe to ship in an open-source plugin.
	 */
	const BASE_URL = 'https://api.zevsend.com/v1';

	const TIMEOUT_SECONDS = 30;

	/**
	 * Secret API key (sk_live_… / sk_test_…).
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * @param string $api_key Secret key.
	 */
	public function __construct( $api_key ) {
		$this->api_key = (string) $api_key;
	}

	/**
	 * Send one email.
	 *
	 * @param array<string,mixed> $payload Already-built API body (only
	 *                                     whitelisted keys — the API
	 *                                     rejects unknown fields).
	 * @return array{id:string,status:string,sandbox:bool,raw:array} Parsed success body.
	 *
	 * @throws Exception On configuration, transport, or API errors. The
	 *                   message is safe to surface to an admin.
	 */
	public function send_email( array $payload ) {
		return $this->request( 'POST', '/emails', $payload );
	}

	/**
	 * Core request with one retry on transient failure.
	 *
	 * @param string              $method HTTP method.
	 * @param string              $path   Path under BASE_URL.
	 * @param array<string,mixed> $body   JSON body.
	 * @return array<string,mixed> Decoded response body.
	 *
	 * @throws Exception On failure.
	 */
	private function request( $method, $path, array $body ) {
		if ( '' === $this->api_key ) {
			throw new Exception(
				esc_html__( 'ZevSend API key is not configured.', 'zevsend-smtp' )
			);
		}

		$url  = self::BASE_URL . $path;
		$args = array(
			'method'  => $method,
			'timeout' => self::TIMEOUT_SECONDS,
			'headers' => array(
				'Authorization' => 'Bearer ' . $this->api_key,
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
				// A descriptive UA helps ZevSend's side attribute traffic;
				// it carries no secret.
				'User-Agent'    => 'ZevSend-WordPress/' . ZEVSEND_SMTP_VERSION . '; ' . home_url( '/' ),
			),
			'body'    => wp_json_encode( $body ),
			// Never disable TLS verification.
			'sslverify' => true,
		);

		$attempts = 0;
		$max      = 2; // Initial try + one retry.
		$response = null;

		while ( $attempts < $max ) {
			$attempts++;
			$response = wp_remote_request( $url, $args );

			if ( is_wp_error( $response ) ) {
				// Connection-level failure: retry once, then give up.
				if ( $attempts < $max ) {
					continue;
				}
				throw new Exception(
					esc_html(
						sprintf(
							/* translators: %s: transport error message. */
							__( 'Could not reach ZevSend: %s', 'zevsend-smtp' ),
							$response->get_error_message()
						)
					)
				);
			}

			$status = (int) wp_remote_retrieve_response_code( $response );

			// Retry once on a server-side hiccup.
			if ( $status >= 500 && $attempts < $max ) {
				continue;
			}

			return $this->parse_response( $status, $response );
		}

		// Unreachable in practice, but keep the type contract honest.
		throw new Exception( esc_html__( 'ZevSend request failed.', 'zevsend-smtp' ) );
	}

	/**
	 * Turn a raw HTTP response into either a decoded body or an Exception
	 * carrying the API's error message.
	 *
	 * @param int   $status   HTTP status.
	 * @param array $response wp_remote_* response.
	 * @return array<string,mixed>
	 *
	 * @throws Exception On any non-2xx status.
	 */
	private function parse_response( $status, $response ) {
		$raw    = wp_remote_retrieve_body( $response );
		$parsed = json_decode( $raw, true );

		if ( $status >= 200 && $status < 300 ) {
			if ( ! is_array( $parsed ) ) {
				// 2xx with an unparseable body: treat as success but with
				// no id, so the caller still counts it as sent.
				return array(
					'id'      => '',
					'status'  => 'sending',
					'sandbox' => false,
					'raw'     => array(),
				);
			}
			return array(
				'id'      => isset( $parsed['id'] ) ? (string) $parsed['id'] : '',
				'status'  => isset( $parsed['status'] ) ? (string) $parsed['status'] : 'sending',
				'sandbox' => ! empty( $parsed['sandbox'] ),
				'raw'     => $parsed,
			);
		}

		// Error path. ZevSend returns { error: { code, message, type,
		// request_id } }. Surface code + message; fall back to the status.
		$code    = 'http_' . $status;
		$message = '';
		if ( is_array( $parsed ) && isset( $parsed['error'] ) && is_array( $parsed['error'] ) ) {
			$code    = isset( $parsed['error']['code'] ) ? (string) $parsed['error']['code'] : $code;
			$message = isset( $parsed['error']['message'] ) ? (string) $parsed['error']['message'] : '';
		}
		if ( '' === $message ) {
			$message = sprintf(
				/* translators: %d: HTTP status code. */
				__( 'ZevSend returned HTTP %d.', 'zevsend-smtp' ),
				$status
			);
		}

		throw new ZevSend_SMTP_Api_Exception( esc_html( $message ), $code );
	}
}
