<?php
/**
 * Admin settings screen under Settings → ZevSend SMTP.
 *
 * Every state change is nonce-protected and capability-gated
 * (`manage_options`). Every dynamic value is escaped on output and
 * sanitised on input. The API key input is write-only: we render a
 * masked placeholder but never the real key, and a blank submission
 * leaves the stored key untouched.
 *
 * @package ZevSend_SMTP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZevSend_SMTP_Admin {

	const PAGE_SLUG    = 'zevsend-smtp';
	const NONCE_SAVE   = 'zevsend_smtp_save';
	const NONCE_TEST   = 'zevsend_smtp_test';
	const NONCE_CLEAR  = 'zevsend_smtp_clear_key';
	const CAPABILITY   = 'manage_options';

	/**
	 * Wire admin hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_post_zevsend_smtp_save', array( $this, 'handle_save' ) );
		add_action( 'admin_post_zevsend_smtp_clear_key', array( $this, 'handle_clear_key' ) );
		add_action( 'wp_ajax_zevsend_smtp_test', array( $this, 'handle_test' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'plugin_action_links_' . ZEVSEND_SMTP_BASENAME, array( $this, 'action_links' ) );
	}

	/**
	 * Add the settings link on the Plugins list row.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public function action_links( $links ) {
		$url      = admin_url( 'options-general.php?page=' . self::PAGE_SLUG );
		$settings = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'zevsend-smtp' ) . '</a>';
		array_unshift( $links, $settings );
		return $links;
	}

	/**
	 * Register the menu page.
	 *
	 * @return void
	 */
	public function add_menu() {
		add_options_page(
			__( 'ZevSend SMTP', 'zevsend-smtp' ),
			__( 'ZevSend SMTP', 'zevsend-smtp' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue assets only on our screen.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue( $hook ) {
		if ( 'settings_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}
		wp_enqueue_style(
			'zevsend-smtp-admin',
			ZEVSEND_SMTP_URL . 'assets/admin.css',
			array(),
			ZEVSEND_SMTP_VERSION
		);
		wp_enqueue_script(
			'zevsend-smtp-admin',
			ZEVSEND_SMTP_URL . 'assets/admin.js',
			array( 'jquery' ),
			ZEVSEND_SMTP_VERSION,
			true
		);
		wp_localize_script(
			'zevsend-smtp-admin',
			'ZevSendSMTP',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE_TEST ),
				'testing' => __( 'Sending…', 'zevsend-smtp' ),
				'sendTest' => __( 'Send test email', 'zevsend-smtp' ),
			)
		);
	}

	/**
	 * Persist settings. Nonce + capability enforced.
	 *
	 * @return void
	 */
	public function handle_save() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'zevsend-smtp' ) );
		}
		check_admin_referer( self::NONCE_SAVE );

		// Superglobals: unslash, then sanitise, per field.
		$values = array(
			'from_email'      => isset( $_POST['from_email'] ) ? sanitize_email( wp_unslash( $_POST['from_email'] ) ) : '',
			'from_name'       => isset( $_POST['from_name'] ) ? sanitize_text_field( wp_unslash( $_POST['from_name'] ) ) : '',
			'force_from'      => isset( $_POST['force_from'] ),
			'force_from_name' => isset( $_POST['force_from_name'] ),
			'fallback_native' => isset( $_POST['fallback_native'] ),
			'logging_enabled' => isset( $_POST['logging_enabled'] ),
			'log_retention'   => isset( $_POST['log_retention'] ) ? absint( wp_unslash( $_POST['log_retention'] ) ) : 30,
		);
		ZevSend_SMTP_Settings::save( $values );

		// API key: only when the field was actually filled and no
		// wp-config constant is in force.
		if ( ! ZevSend_SMTP_Settings::key_is_from_constant()
			&& isset( $_POST['api_key'] )
		) {
			$submitted = trim( sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) );
			if ( '' !== $submitted ) {
				ZevSend_SMTP_Settings::save_api_key( $submitted );
			}
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => self::PAGE_SLUG,
					'updated' => '1',
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * Remove the DB-stored key.
	 *
	 * @return void
	 */
	public function handle_clear_key() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'zevsend-smtp' ) );
		}
		check_admin_referer( self::NONCE_CLEAR );
		ZevSend_SMTP_Settings::clear_api_key();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'       => self::PAGE_SLUG,
					'key_cleared' => '1',
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * AJAX: send a real test email through the whole pipeline (wp_mail →
	 * our pre_wp_mail interceptor → ZevSend). Nonce + capability enforced.
	 *
	 * @return void
	 */
	public function handle_test() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'zevsend-smtp' ) ), 403 );
		}
		check_ajax_referer( self::NONCE_TEST, 'nonce' );

		$to = isset( $_POST['to'] ) ? sanitize_email( wp_unslash( $_POST['to'] ) ) : '';
		if ( '' === $to || ! is_email( $to ) ) {
			wp_send_json_error( array( 'message' => __( 'Enter a valid destination email.', 'zevsend-smtp' ) ) );
		}

		if ( ! ZevSend_SMTP_Settings::is_configured() ) {
			wp_send_json_error( array( 'message' => __( 'Add a valid ZevSend API key first.', 'zevsend-smtp' ) ) );
		}

		$subject = __( 'ZevSend SMTP test email', 'zevsend-smtp' );
		$body    = '<p>' . esc_html__( 'Success. This test email was delivered through ZevSend.', 'zevsend-smtp' ) . '</p>';
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );

		// Capture the failure message our mailer emits via wp_mail_failed.
		$captured = '';
		$listener = static function ( $wp_error ) use ( &$captured ) {
			if ( is_wp_error( $wp_error ) ) {
				$captured = $wp_error->get_error_message();
			}
		};
		add_action( 'wp_mail_failed', $listener );
		$ok = wp_mail( $to, $subject, $body, $headers );
		remove_action( 'wp_mail_failed', $listener );

		if ( $ok ) {
			wp_send_json_success(
				array(
					'message' => sprintf(
						/* translators: %s: destination email. */
						__( 'Test email sent to %s.', 'zevsend-smtp' ),
						$to
					),
				)
			);
		}

		wp_send_json_error(
			array(
				'message' => '' !== $captured
					? $captured
					: __( 'The test email could not be sent.', 'zevsend-smtp' ),
			)
		);
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$s              = ZevSend_SMTP_Settings::all();
		$from_constant  = ZevSend_SMTP_Settings::key_is_from_constant();
		$configured     = ZevSend_SMTP_Settings::is_configured();
		$mode           = ZevSend_SMTP_Settings::mode();
		$has_stored_key = '' !== ZevSend_SMTP_Settings::api_key();
		$masked         = $has_stored_key ? zevsend_smtp_mask_key( ZevSend_SMTP_Settings::api_key() ) : '';

		// Notices from redirects.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only status flags on a redirect target, no state change.
		$updated     = isset( $_GET['updated'] );
		$key_cleared = isset( $_GET['key_cleared'] );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap zevsend-smtp">
			<h1><?php esc_html_e( 'ZevSend SMTP', 'zevsend-smtp' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Deliver all WordPress email through ZevSend. Works with WooCommerce, contact forms, and anything that uses wp_mail().', 'zevsend-smtp' ); ?>
			</p>

			<?php if ( $updated ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'zevsend-smtp' ); ?></p></div>
			<?php endif; ?>
			<?php if ( $key_cleared ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'API key removed.', 'zevsend-smtp' ); ?></p></div>
			<?php endif; ?>

			<div class="zevsend-smtp-status">
				<?php if ( $configured ) : ?>
					<span class="zevsend-smtp-badge zevsend-smtp-badge--ok">
						<?php echo esc_html( 'live' === $mode ? __( 'Connected (live)', 'zevsend-smtp' ) : __( 'Connected (sandbox)', 'zevsend-smtp' ) ); ?>
					</span>
					<?php if ( 'sandbox' === $mode ) : ?>
						<span class="zevsend-smtp-hint">
							<?php esc_html_e( 'Sandbox keys only deliver to your verified test recipients, and the From address is set by ZevSend. Switch to a live key for production.', 'zevsend-smtp' ); ?>
						</span>
					<?php endif; ?>
				<?php else : ?>
					<span class="zevsend-smtp-badge zevsend-smtp-badge--warn">
						<?php esc_html_e( 'Not connected', 'zevsend-smtp' ); ?>
					</span>
					<span class="zevsend-smtp-hint">
						<?php esc_html_e( 'Add a secret API key (starts with sk_) from your ZevSend dashboard under Settings, API keys.', 'zevsend-smtp' ); ?>
					</span>
				<?php endif; ?>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="zevsend_smtp_save" />
				<?php wp_nonce_field( self::NONCE_SAVE ); ?>

				<h2 class="title"><?php esc_html_e( 'Connection', 'zevsend-smtp' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="zevsend_api_key"><?php esc_html_e( 'API key', 'zevsend-smtp' ); ?></label></th>
						<td>
							<?php if ( $from_constant ) : ?>
								<p><code><?php echo esc_html( $masked ); ?></code></p>
								<p class="description">
									<?php esc_html_e( 'Set via the ZEVSEND_SMTP_API_KEY constant in wp-config.php. This is the most secure option and overrides any value here.', 'zevsend-smtp' ); ?>
								</p>
							<?php else : ?>
								<input
									type="password"
									name="api_key"
									id="zevsend_api_key"
									class="regular-text"
									autocomplete="new-password"
									placeholder="<?php echo esc_attr( $has_stored_key ? $masked : 'sk_live_…' ); ?>"
									value=""
								/>
								<p class="description">
									<?php
									if ( $has_stored_key ) {
										esc_html_e( 'A key is saved. Leave blank to keep it, or paste a new key to replace it.', 'zevsend-smtp' );
									} else {
										esc_html_e( 'Paste your secret key (sk_live_… for production, sk_test_… for sandbox).', 'zevsend-smtp' );
									}
									?>
									<br />
									<?php esc_html_e( 'For maximum security, define ZEVSEND_SMTP_API_KEY in wp-config.php instead so the key never touches the database.', 'zevsend-smtp' ); ?>
								</p>
							<?php endif; ?>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'From address', 'zevsend-smtp' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="zevsend_from_email"><?php esc_html_e( 'From email', 'zevsend-smtp' ); ?></label></th>
						<td>
							<input type="email" name="from_email" id="zevsend_from_email" class="regular-text"
								value="<?php echo esc_attr( $s['from_email'] ); ?>" placeholder="hello@yourdomain.com" />
							<p class="description">
								<?php esc_html_e( 'In live mode this must be an address on a domain you have verified in ZevSend.', 'zevsend-smtp' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="zevsend_from_name"><?php esc_html_e( 'From name', 'zevsend-smtp' ); ?></label></th>
						<td>
							<input type="text" name="from_name" id="zevsend_from_name" class="regular-text"
								value="<?php echo esc_attr( $s['from_name'] ); ?>" placeholder="Your Store" />
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Force from', 'zevsend-smtp' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="force_from" value="1" <?php checked( $s['force_from'] ); ?> />
								<?php esc_html_e( 'Always use the From email above, overriding other plugins.', 'zevsend-smtp' ); ?>
							</label>
							<br />
							<label>
								<input type="checkbox" name="force_from_name" value="1" <?php checked( $s['force_from_name'] ); ?> />
								<?php esc_html_e( 'Always use the From name above.', 'zevsend-smtp' ); ?>
							</label>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Delivery & logging', 'zevsend-smtp' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'On failure', 'zevsend-smtp' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="fallback_native" value="1" <?php checked( $s['fallback_native'] ); ?> />
								<?php esc_html_e( 'If ZevSend cannot be reached, fall back to the default WordPress mailer.', 'zevsend-smtp' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'Off by default. Most sites install this because native mail was unreliable, so a silent fallback that reports success is usually not wanted.', 'zevsend-smtp' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Email log', 'zevsend-smtp' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="logging_enabled" value="1" <?php checked( $s['logging_enabled'] ); ?> />
								<?php esc_html_e( 'Record recipients, subject, and status for each send. Message bodies are never stored.', 'zevsend-smtp' ); ?>
							</label>
							<p>
								<label for="zevsend_log_retention">
									<?php esc_html_e( 'Keep logs for', 'zevsend-smtp' ); ?>
								</label>
								<input type="number" min="0" max="365" name="log_retention" id="zevsend_log_retention"
									class="small-text" value="<?php echo esc_attr( (string) $s['log_retention'] ); ?>" />
								<?php esc_html_e( 'days (0 = keep forever).', 'zevsend-smtp' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save settings', 'zevsend-smtp' ) ); ?>
			</form>

			<?php if ( ! $from_constant && $has_stored_key ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="zevsend-smtp-clear">
					<input type="hidden" name="action" value="zevsend_smtp_clear_key" />
					<?php wp_nonce_field( self::NONCE_CLEAR ); ?>
					<button type="submit" class="button-link zevsend-smtp-danger">
						<?php esc_html_e( 'Remove saved API key', 'zevsend-smtp' ); ?>
					</button>
				</form>
			<?php endif; ?>

			<h2 class="title"><?php esc_html_e( 'Send a test email', 'zevsend-smtp' ); ?></h2>
			<div class="zevsend-smtp-test">
				<input type="email" id="zevsend_test_to" class="regular-text"
					value="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>" />
				<button type="button" class="button button-secondary" id="zevsend_test_btn">
					<?php esc_html_e( 'Send test email', 'zevsend-smtp' ); ?>
				</button>
				<span id="zevsend_test_result" class="zevsend-smtp-test-result" role="status" aria-live="polite"></span>
			</div>

			<?php $this->render_log( $s ); ?>
		</div>
		<?php
	}

	/**
	 * Render the recent-log table when logging is on.
	 *
	 * @param array<string,mixed> $s Settings.
	 * @return void
	 */
	private function render_log( $s ) {
		if ( empty( $s['logging_enabled'] ) ) {
			return;
		}
		$rows = ZevSend_SMTP_Logger::recent( 25 );
		?>
		<h2 class="title"><?php esc_html_e( 'Recent emails', 'zevsend-smtp' ); ?></h2>
		<table class="widefat striped zevsend-smtp-log">
			<thead>
				<tr>
					<th><?php esc_html_e( 'When', 'zevsend-smtp' ); ?></th>
					<th><?php esc_html_e( 'To', 'zevsend-smtp' ); ?></th>
					<th><?php esc_html_e( 'Subject', 'zevsend-smtp' ); ?></th>
					<th><?php esc_html_e( 'Status', 'zevsend-smtp' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="4"><?php esc_html_e( 'No emails logged yet.', 'zevsend-smtp' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<td><?php echo esc_html( $row->created_at ); ?></td>
							<td><?php echo esc_html( $row->to_addresses ); ?></td>
							<td><?php echo esc_html( $row->subject ); ?></td>
							<td>
								<?php if ( 'sent' === $row->status ) : ?>
									<span class="zevsend-smtp-badge zevsend-smtp-badge--ok"><?php esc_html_e( 'Sent', 'zevsend-smtp' ); ?></span>
								<?php else : ?>
									<span class="zevsend-smtp-badge zevsend-smtp-badge--warn" title="<?php echo esc_attr( $row->error_message ); ?>">
										<?php esc_html_e( 'Failed', 'zevsend-smtp' ); ?>
									</span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
		<?php
	}
}
