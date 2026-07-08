<?php
/**
 * Settings storage + resolution.
 *
 * Two design points worth stating up front:
 *
 *  1. The API key is a SECRET. The recommended place for it is a
 *     `ZEVSEND_SMTP_API_KEY` constant in wp-config.php, which keeps it
 *     out of the database and out of any DB backup that might leak. If
 *     that constant is set it ALWAYS wins and the DB value is ignored.
 *     Only when it isn't set do we fall back to an options-table value,
 *     which the admin UI writes but never reads back into the page.
 *
 *  2. Everything else (from address, toggles) is non-secret and lives
 *     in a single wp_options row so we do one autoloaded read per page.
 *
 * @package ZevSend_SMTP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZevSend_SMTP_Settings {

	/**
	 * Defaults for the non-secret settings blob.
	 *
	 * @var array<string,mixed>
	 */
	private static $defaults = array(
		'from_email'       => '',
		'from_name'        => '', // Must match an approved brand name in live mode; blank = auto.
		'from_display_id'  => '', // Optional dn_… for an approved alternate sender name.
		'force_from'       => false, // Override the From address other plugins set.
		'fallback_native'  => false, // On API failure, let WP try its own mailer.
		'logging_enabled'  => false,
		'log_retention'    => 30,    // Days. 0 = keep forever.
		'api_key'          => '',    // Only used when the wp-config constant is absent.
	);

	/**
	 * Cached, merged settings for the current request.
	 *
	 * @var array<string,mixed>|null
	 */
	private static $cache = null;

	/**
	 * Get the full merged settings array (defaults + stored).
	 *
	 * @return array<string,mixed>
	 */
	public static function all() {
		if ( null !== self::$cache ) {
			return self::$cache;
		}
		$stored      = get_option( ZEVSEND_SMTP_OPTION, array() );
		$stored      = is_array( $stored ) ? $stored : array();
		self::$cache = wp_parse_args( $stored, self::$defaults );
		return self::$cache;
	}

	/**
	 * Read one setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback if unset.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/**
	 * Persist a sanitised settings array. The API key is handled
	 * separately (see save_api_key) so this never touches it unless
	 * explicitly included by the caller.
	 *
	 * @param array<string,mixed> $values Raw values from the form.
	 * @return void
	 */
	public static function save( array $values ) {
		$current = self::all();
		$clean   = $current;

		$clean['from_email']      = isset( $values['from_email'] ) ? sanitize_email( $values['from_email'] ) : $current['from_email'];
		$clean['from_name']       = isset( $values['from_name'] ) ? sanitize_text_field( zevsend_smtp_strip_header_breaks( $values['from_name'] ) ) : $current['from_name'];

		// Display id must look like `dn_…`; anything else is stored blank
		// so a typo can't be sent to the API.
		$display_id = isset( $values['from_display_id'] ) ? trim( sanitize_text_field( $values['from_display_id'] ) ) : $current['from_display_id'];
		$clean['from_display_id'] = preg_match( '/^dn_[A-Za-z0-9]+$/', (string) $display_id ) ? $display_id : '';

		$clean['force_from']      = ! empty( $values['force_from'] );
		$clean['fallback_native'] = ! empty( $values['fallback_native'] );
		$clean['logging_enabled'] = ! empty( $values['logging_enabled'] );

		$retention = isset( $values['log_retention'] ) ? absint( $values['log_retention'] ) : $current['log_retention'];
		// Clamp to a sane ceiling so a fat-fingered value can't grow the
		// log table unbounded.
		$clean['log_retention'] = min( $retention, 365 );

		// Preserve the stored key; a separate path updates it.
		$clean['api_key'] = $current['api_key'];

		update_option( ZEVSEND_SMTP_OPTION, $clean, false );
		self::$cache = null;
	}

	/**
	 * Store the API key in the DB. Only used when the wp-config constant
	 * is NOT defined — if it is, we refuse so the admin isn't fooled into
	 * thinking a DB value takes effect when the constant overrides it.
	 *
	 * An empty string clears the stored key.
	 *
	 * @param string $key Raw key from the form.
	 * @return void
	 */
	public static function save_api_key( $key ) {
		if ( self::key_is_from_constant() ) {
			return;
		}
		$key     = trim( (string) $key );
		$current = self::all();

		// Persist only when the field actually changed. The UI submits an
		// empty field on every save (we never echo the key back), so a
		// blank submission means "leave it alone" UNLESS the admin used
		// the explicit clear action.
		$current['api_key'] = sanitize_text_field( $key );
		update_option( ZEVSEND_SMTP_OPTION, $current, false );
		self::$cache = null;
	}

	/**
	 * Clear the DB-stored key.
	 *
	 * @return void
	 */
	public static function clear_api_key() {
		if ( self::key_is_from_constant() ) {
			return;
		}
		$current            = self::all();
		$current['api_key'] = '';
		update_option( ZEVSEND_SMTP_OPTION, $current, false );
		self::$cache = null;
	}

	/**
	 * Resolve the effective API key: constant first, then DB.
	 *
	 * @return string Empty string when not configured.
	 */
	public static function api_key() {
		if ( self::key_is_from_constant() ) {
			return trim( (string) constant( 'ZEVSEND_SMTP_API_KEY' ) );
		}
		return trim( (string) self::get( 'api_key', '' ) );
	}

	/**
	 * Is the key sourced from the wp-config constant?
	 *
	 * @return bool
	 */
	public static function key_is_from_constant() {
		return defined( 'ZEVSEND_SMTP_API_KEY' ) && '' !== trim( (string) constant( 'ZEVSEND_SMTP_API_KEY' ) );
	}

	/**
	 * Is a usable key configured at all?
	 *
	 * @return bool
	 */
	public static function is_configured() {
		$key = self::api_key();
		return '' !== $key && 0 === strpos( $key, 'sk_' );
	}

	/**
	 * Sending mode implied by the key prefix.
	 *
	 * ZevSend locks the `from` to its platform sender in sandbox, so the
	 * mailer needs to know the mode to avoid a `sandbox_from_locked`
	 * error. We infer it from the key rather than a separate toggle so
	 * there's no way for the two to drift out of sync.
	 *
	 * @return string 'sandbox' | 'live' | 'unknown'
	 */
	public static function mode() {
		$key = self::api_key();
		if ( 0 === strpos( $key, 'sk_test_' ) ) {
			return 'sandbox';
		}
		if ( 0 === strpos( $key, 'sk_live_' ) ) {
			return 'live';
		}
		return 'unknown';
	}
}
