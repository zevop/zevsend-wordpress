<?php
/**
 * Small, dependency-free helpers shared across the plugin.
 *
 * @package ZevSend_SMTP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Strip anything that could smuggle a second header into a value we
 * later hand to an email field (CR/LF and the header separators).
 *
 * We build the API payload as JSON — not raw MIME — so classic SMTP
 * header injection can't reach the wire. But a plugin that lets an
 * unauthenticated visitor influence a subject or recipient is a real
 * thing, and a newline in a "name" or "subject" is never legitimate.
 * Cheap to strip, so we strip.
 *
 * @param string $value Raw value.
 * @return string Value with control chars removed.
 */
function zevsend_smtp_strip_header_breaks( $value ) {
	return preg_replace( '/[\r\n\t]+/', ' ', (string) $value );
}

/**
 * Parse a single RFC 5322 address into [email, name].
 *
 * Accepts:
 *   "Jane Doe <jane@example.com>"
 *   <jane@example.com>
 *   jane@example.com
 *
 * @param string $address Raw address fragment.
 * @return array{email:string,name:string} email is '' when unparseable.
 */
function zevsend_smtp_parse_address( $address ) {
	$address = trim( zevsend_smtp_strip_header_breaks( $address ) );
	$name    = '';
	$email   = '';

	if ( preg_match( '/^\s*(.*?)\s*<\s*([^>]+)\s*>\s*$/', $address, $m ) ) {
		$name  = trim( $m[1], " \t\"'" );
		$email = trim( $m[2] );
	} else {
		$email = trim( $address, " \t<>" );
	}

	$email = sanitize_email( $email );
	return array(
		'email' => $email,
		'name'  => sanitize_text_field( $name ),
	);
}

/**
 * Split a comma-separated recipient list into an array of clean email
 * strings, dropping anything that doesn't sanitise to a valid address.
 *
 * @param string|array $list Raw list (wp_mail passes either).
 * @return string[] De-duplicated, lower-cased-domain email addresses.
 */
function zevsend_smtp_parse_recipient_list( $list ) {
	if ( ! is_array( $list ) ) {
		$list = explode( ',', (string) $list );
	}

	$out = array();
	foreach ( $list as $item ) {
		$parsed = zevsend_smtp_parse_address( $item );
		if ( '' !== $parsed['email'] && is_email( $parsed['email'] ) ) {
			$out[] = $parsed['email'];
		}
	}

	// De-dupe case-insensitively but preserve the first-seen casing.
	$seen   = array();
	$unique = array();
	foreach ( $out as $email ) {
		$key = strtolower( $email );
		if ( ! isset( $seen[ $key ] ) ) {
			$seen[ $key ] = true;
			$unique[]     = $email;
		}
	}
	return $unique;
}

/**
 * Compose a `from` string in the form ZevSend accepts: a bare email,
 * or `"Display Name" <email>` when a name is present. The name is
 * quoted and its quotes/backslashes escaped so a stray character can't
 * break the address grammar.
 *
 * @param string $email From email (already sanitised).
 * @param string $name  Optional display name.
 * @return string
 */
function zevsend_smtp_format_from( $email, $name = '' ) {
	$email = sanitize_email( $email );
	$name  = trim( sanitize_text_field( zevsend_smtp_strip_header_breaks( $name ) ) );

	if ( '' === $name ) {
		return $email;
	}

	$escaped_name = str_replace( array( '\\', '"' ), array( '\\\\', '\\"' ), $name );
	return sprintf( '"%s" <%s>', $escaped_name, $email );
}

/**
 * The shared ZEV mark as inline SVG. Same paths used across every Zev
 * product (source: ZEV ICONS/ICON_Black.svg). Inherits color from the
 * surrounding `color`/`fill` via currentColor so CSS controls the brand
 * tint. Safe, static markup — no dynamic data.
 *
 * @param int $size Rendered pixel height/width box.
 * @return string SVG markup.
 */
function zevsend_smtp_logo_svg( $size = 28 ) {
	$size = (int) $size;
	return sprintf(
		'<svg class="zevsend-smtp-mark" width="%1$d" height="%1$d" viewBox="0 0 495.23 347" fill="currentColor" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">'
			. '<path d="m311.65,285.54c0-11.55,2.02-22.66,5.73-33.09,2.39-6.72,5.5-13.16,9.23-19.24-12.05,2.08-24.42,4.48-37.06,7.18-33.32,7.13-68.5,16.4-104.56,27.8-40.19,12.7-77.99,26.94-112.23,41.95,27.08-6.67,59.31-10.53,93.91-10.5,50.91.04,96.7,8.48,128.44,21.9,17.08,7.22,30.1,15.88,37.65,25.46-7.77-10.18-13.66-21.6-17.22-33.9-2.55-8.8-3.91-18.03-3.9-27.56Z" />'
			. '<path d="m495.23,194.44v14.04c-.8-.22-1.6-.42-2.42-.62-16.09-3.96-36.23-5.91-59.55-5.88-8.69.01-17.81.31-27.34.87-18.38,1.09-38.24,3.2-59.25,6.32-24.48,3.64-50.52,8.64-77.62,15.02-27.13,6.37-55.31,14.1-84.04,23.18-69.87,22.07-132.52,48.8-181.8,76.16-1.08.6-2.15,1.2-3.21,1.79.83-.63,1.68-1.27,2.53-1.9,49.87-37.17,134.63-82.12,234.39-121.86,6.52-2.61,13.02-5.14,19.47-7.61,42.57-16.33,83.49-30,120.89-40.64l-1.54-1.16-29.66-22.39-8.8-6.64L174.16,0l275.12,127.47,12.54,5.8c-7.34,1.32-14.98,2.82-22.89,4.53,33.82,11.79,56.32,32.75,56.3,56.64Z" />'
			. '</svg>',
		$size
	);
}

/**
 * Turn a ZevSend API error code into a plain-language, actionable hint
 * a non-technical site owner can follow. Returns '' for unknown codes
 * so the caller can fall back to the raw API message.
 *
 * @param string $code API error code (e.g. from_domain_unverified).
 * @return string Translated hint, or '' when we have no mapping.
 */
function zevsend_smtp_error_hint( $code ) {
	switch ( $code ) {
		case 'from_domain_unverified':
			return __( 'Your From domain is not verified yet. Add and verify it in your ZevSend dashboard under Domains, then try again.', 'zevsend-smtp' );
		case 'no_approved_brand_domain':
			return __( 'You do not have an approved sending domain yet. Verify a domain in your ZevSend dashboard before sending in live mode.', 'zevsend-smtp' );
		case 'display_name_not_approved':
			return __( 'The sender name is not approved for this domain. Leave the Sender name blank to use your approved brand name, or register the name in your ZevSend dashboard.', 'zevsend-smtp' );
		case 'sandbox_from_locked':
			return __( 'You are using a sandbox key, which sends from the ZevSend test address. Switch to a live key to use your own From address.', 'zevsend-smtp' );
		case 'recipient_not_allowed':
		case 'recipient_not_verified':
			return __( 'Sandbox keys can only deliver to recipients you have verified in ZevSend. Add the recipient there, or switch to a live key.', 'zevsend-smtp' );
		case 'recipient_suppressed':
			return __( 'This recipient is on your suppression list (a past bounce or complaint). Remove them in your ZevSend dashboard if this is a mistake.', 'zevsend-smtp' );
		case 'invalid_from_address':
			return __( 'The From address could not be understood. Enter a plain email address like hello@yourdomain.com.', 'zevsend-smtp' );
		case 'invalid_api_key':
		case 'unauthorized':
			return __( 'Your API key was rejected. Copy a fresh secret key from your ZevSend dashboard under Settings, API keys.', 'zevsend-smtp' );
		case 'key_domain_scope':
			return __( 'This API key is restricted to a different domain than your From address. Use a key scoped to this domain, or an unrestricted key.', 'zevsend-smtp' );
		case 'rate_limited':
			return __( 'You are sending faster than the current limit allows. Wait a moment and try again.', 'zevsend-smtp' );
		default:
			return '';
	}
}

/**
 * Mask an API key for display. Reveals ONLY the non-secret type
 * prefix (`sk_live_` / `sk_test_`) and never a single byte of the
 * random secret that follows. If the key doesn't match the expected
 * shape we reveal nothing at all.
 *
 * @param string $key Full key.
 * @return string
 */
function zevsend_smtp_mask_key( $key ) {
	$key = (string) $key;
	// Match the two known non-secret prefixes exactly, then dots.
	if ( 0 === strpos( $key, 'sk_live_' ) ) {
		return 'sk_live_' . str_repeat( '•', 12 );
	}
	if ( 0 === strpos( $key, 'sk_test_' ) ) {
		return 'sk_test_' . str_repeat( '•', 12 );
	}
	return str_repeat( '•', 12 );
}
