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
