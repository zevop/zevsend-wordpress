<?php
/**
 * Plugin Name:       ZevSend SMTP
 * Plugin URI:        https://zevsend.com/integrations/wordpress
 * Description:       Route all WordPress email through ZevSend's email delivery API for reliable inbox placement. Works with WooCommerce, contact forms, and any plugin or theme that uses wp_mail().
 * Version:           1.0.0
 * Requires at least: 5.7
 * Requires PHP:      7.4
 * Author:            ZevSend
 * Author URI:        https://zevsend.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       zevsend-smtp
 * Domain Path:       /languages
 *
 * ZevSend is API-based (not a traditional SMTP relay). The "SMTP" in the
 * name reflects what it does for you — fixes WordPress email deliverability —
 * not the transport. No host/port to configure; just your API key.
 *
 * @package ZevSend_SMTP
 */

// Hard block direct access. Every PHP file in this plugin repeats this
// guard so a file requested directly (a common probing technique) does
// nothing instead of executing in an uninitialised context.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ─── Constants ──────────────────────────────────────────────────────
// Prefix everything with ZEVSEND_SMTP_ so we never collide with another
// plugin's globals. Version drives cache-busting on admin assets and
// gates any future data migrations.
define( 'ZEVSEND_SMTP_VERSION', '1.0.0' );
define( 'ZEVSEND_SMTP_FILE', __FILE__ );
define( 'ZEVSEND_SMTP_DIR', plugin_dir_path( __FILE__ ) );
define( 'ZEVSEND_SMTP_URL', plugin_dir_url( __FILE__ ) );
define( 'ZEVSEND_SMTP_BASENAME', plugin_basename( __FILE__ ) );

// Option key under which non-secret settings live in wp_options. The
// API key is handled separately (see class-zevsend-settings.php) so it
// can be sourced from wp-config.php and kept out of the DB.
define( 'ZEVSEND_SMTP_OPTION', 'zevsend_smtp_settings' );

// The wp-config.php constant a security-conscious admin can define to
// keep the API key out of the database entirely. Documented in readme.
// Example: define( 'ZEVSEND_SMTP_API_KEY', 'zs_live_...' );
// (constant is read in class-zevsend-settings.php)

// ─── Autoload plugin classes ────────────────────────────────────────
require_once ZEVSEND_SMTP_DIR . 'includes/helpers.php';
require_once ZEVSEND_SMTP_DIR . 'includes/class-zevsend-settings.php';
require_once ZEVSEND_SMTP_DIR . 'includes/class-zevsend-logger.php';
require_once ZEVSEND_SMTP_DIR . 'includes/class-zevsend-api-client.php';
require_once ZEVSEND_SMTP_DIR . 'includes/class-zevsend-mailer.php';
require_once ZEVSEND_SMTP_DIR . 'includes/class-zevsend-admin.php';
require_once ZEVSEND_SMTP_DIR . 'includes/class-zevsend-plugin.php';

// ─── Boot ───────────────────────────────────────────────────────────
// Single entry point. The plugin class wires the mail hook + admin UI
// on `plugins_loaded` so every dependency (including WooCommerce, form
// plugins, etc.) is present before we register anything.
add_action(
	'plugins_loaded',
	static function () {
		ZevSend_SMTP_Plugin::instance()->boot();
	}
);

// ─── Lifecycle ──────────────────────────────────────────────────────
register_activation_hook(
	__FILE__,
	array( 'ZevSend_SMTP_Plugin', 'on_activation' )
);
register_deactivation_hook(
	__FILE__,
	array( 'ZevSend_SMTP_Plugin', 'on_deactivation' )
);
