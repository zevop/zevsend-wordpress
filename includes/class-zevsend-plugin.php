<?php
/**
 * Plugin orchestrator: one place that wires the mail interceptor, the
 * admin screen, and the log-purge cron, plus the activation and
 * deactivation lifecycle.
 *
 * @package ZevSend_SMTP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ZevSend_SMTP_Plugin {

	/**
	 * @var ZevSend_SMTP_Plugin|null
	 */
	private static $instance = null;

	/**
	 * @return ZevSend_SMTP_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Wire runtime hooks. Runs on plugins_loaded.
	 *
	 * @return void
	 */
	public function boot() {
		// Mail interception — the whole point of the plugin.
		( new ZevSend_SMTP_Mailer() )->register();

		// Admin UI, only in the dashboard.
		if ( is_admin() ) {
			( new ZevSend_SMTP_Admin() )->register();
		}

		// Bind the log-purge cron target on every load so WP-Cron can
		// fire it. The schedule itself is created on activation.
		add_action( ZevSend_SMTP_Logger::CRON_HOOK, array( 'ZevSend_SMTP_Logger', 'purge' ) );

		// Load translations.
		add_action(
			'init',
			static function () {
				load_plugin_textdomain( 'zevsend-smtp', false, dirname( ZEVSEND_SMTP_BASENAME ) . '/languages' );
			}
		);
	}

	/**
	 * Activation: create the log table, seed defaults, schedule cron.
	 *
	 * @return void
	 */
	public static function on_activation() {
		ZevSend_SMTP_Logger::install_table();

		// Seed an empty settings row so the first admin visit reads a
		// defined shape. Never overwrites an existing config.
		if ( false === get_option( ZEVSEND_SMTP_OPTION, false ) ) {
			add_option(
				ZEVSEND_SMTP_OPTION,
				array(
					// Blank by default so ZevSend auto-fills the approved
					// brand name; a mismatched guess would fail live sends.
					'from_email'      => '',
					'from_name'       => '',
					'from_display_id' => '',
					'force_from'      => false,
					'fallback_native' => false,
					'logging_enabled' => false,
					'log_retention'   => 30,
					'api_key'         => '',
				),
				'',
				false
			);
		}

		// Show the one-time welcome nudge on the next admin page load.
		add_option( 'zevsend_smtp_welcome', true, '', false );

		ZevSend_SMTP_Logger::schedule_cron();
	}

	/**
	 * Deactivation: tear down the cron. We deliberately KEEP settings and
	 * the log table so a temporary deactivate/reactivate doesn't wipe the
	 * admin's configuration. Full cleanup happens in uninstall.php.
	 *
	 * @return void
	 */
	public static function on_deactivation() {
		ZevSend_SMTP_Logger::unschedule_cron();
	}
}
