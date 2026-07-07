<?php
/**
 * Optional email log.
 *
 * OFF by default. When an admin turns it on we record one row per send
 * attempt so they can answer "did the order email actually go out?"
 * without digging through a mail server. We deliberately store metadata
 * only — recipients, subject, status, and any error code — and NEVER
 * the message body (it routinely contains password-reset links, order
 * details, and other PII) nor the API key.
 *
 * Rows are purged by a daily cron according to the retention setting.
 *
 * @package ZevSend_SMTP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZevSend_SMTP_Logger {

	const CRON_HOOK = 'zevsend_smtp_purge_logs';

	/**
	 * Fully-qualified log table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'zevsend_smtp_log';
	}

	/**
	 * Create the log table. Called on activation.
	 *
	 * @return void
	 */
	public static function install_table() {
		global $wpdb;
		$table           = self::table();
		$charset_collate = $wpdb->get_charset_collate();

		// dbDelta wants this exact formatting.
		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			created_at DATETIME NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'sent',
			to_addresses TEXT NULL,
			subject VARCHAR(255) NULL,
			message_id VARCHAR(64) NULL,
			error_code VARCHAR(64) NULL,
			error_message VARCHAR(255) NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Record a send attempt. No-op unless logging is enabled.
	 *
	 * @param array{
	 *   status:string,
	 *   to:string[],
	 *   subject:string,
	 *   message_id?:string,
	 *   error_code?:string,
	 *   error_message?:string
	 * } $entry Metadata only. Never pass a body here.
	 * @return void
	 */
	public static function record( array $entry ) {
		if ( ! ZevSend_SMTP_Settings::get( 'logging_enabled' ) ) {
			return;
		}
		global $wpdb;

		$to = isset( $entry['to'] ) && is_array( $entry['to'] )
			? implode( ', ', $entry['to'] )
			: '';

		// Truncate defensively so a huge recipient list or subject can't
		// bloat a row.
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- plugin's own table, no caching layer applies to an append-only log.
			self::table(),
			array(
				'created_at'    => current_time( 'mysql' ),
				'status'        => substr( (string) ( $entry['status'] ?? 'sent' ), 0, 20 ),
				'to_addresses'  => substr( $to, 0, 5000 ),
				'subject'       => substr( (string) ( $entry['subject'] ?? '' ), 0, 255 ),
				'message_id'    => substr( (string) ( $entry['message_id'] ?? '' ), 0, 64 ),
				'error_code'    => substr( (string) ( $entry['error_code'] ?? '' ), 0, 64 ),
				'error_message' => substr( (string) ( $entry['error_message'] ?? '' ), 0, 255 ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Most-recent rows for the admin log view.
	 *
	 * @param int $limit Max rows.
	 * @return array<int,object>
	 */
	public static function recent( $limit = 50 ) {
		global $wpdb;
		$limit = max( 1, min( (int) $limit, 200 ) );
		$table = self::table();
		// $table is built from $wpdb->prefix, not user input; $limit is an int.
		return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- plugin's own table; identifier + int only.
			$wpdb->prepare( "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT %d", $limit )
		);
	}

	/**
	 * Purge rows older than the retention window. Bound to a daily cron.
	 * Retention of 0 means "keep forever" — the cron becomes a no-op.
	 *
	 * @return void
	 */
	public static function purge() {
		$days = (int) ZevSend_SMTP_Settings::get( 'log_retention', 30 );
		if ( $days <= 0 ) {
			return;
		}
		global $wpdb;
		$table  = self::table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- plugin's own table; value is bound.
			$wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cutoff )
		);
	}

	/**
	 * Schedule the daily purge if not already scheduled.
	 *
	 * @return void
	 */
	public static function schedule_cron() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Remove the cron. Called on deactivation.
	 *
	 * @return void
	 */
	public static function unschedule_cron() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}
}
