<?php
/**
 * Runs when the plugin is DELETED (not merely deactivated). Removes the
 * settings row and the log table so an uninstall leaves nothing behind.
 *
 * @package ZevSend_SMTP
 */

// Only WordPress may include this file, and only during uninstall.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'zevsend_smtp_settings' );
delete_option( 'zevsend_smtp_welcome' );
delete_option( 'zevsend_smtp_test_passed' );

// Drop the log table.
global $wpdb;
$table = $wpdb->prefix . 'zevsend_smtp_log';
// Identifier is built from the trusted table prefix, not user input.
$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- one-time uninstall cleanup of the plugin's own table.

// Clear any scheduled purge event.
$timestamp = wp_next_scheduled( 'zevsend_smtp_purge_logs' );
if ( $timestamp ) {
	wp_unschedule_event( $timestamp, 'zevsend_smtp_purge_logs' );
}
