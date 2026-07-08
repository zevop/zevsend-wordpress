<?php
/**
 * The interception layer.
 *
 * WordPress routes essentially all outbound email through wp_mail():
 * WooCommerce order emails, password resets, comment notifications,
 * and every well-behaved form / membership / newsletter plugin. By
 * short-circuiting the `pre_wp_mail` filter (WordPress 5.7+) we capture
 * all of it and deliver it through the ZevSend API instead — without
 * redefining the pluggable wp_mail() function, which only one plugin
 * can do and which breaks the moment two mail plugins are active.
 *
 * Return contract of `pre_wp_mail`:
 *   - return null  → let WordPress fall through to its native mailer.
 *   - return true  → we handled it; wp_mail() reports success.
 *   - return false → we handled it; wp_mail() reports failure.
 *
 * @package ZevSend_SMTP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZevSend_SMTP_Mailer {

	/** Combined to+cc+bcc ceiling enforced by the API. */
	const MAX_RECIPIENTS = 50;

	/** Attachment ceilings enforced by the API (raw bytes, pre-base64). */
	const MAX_ATTACHMENTS      = 20;
	const MAX_ATTACHMENT_BYTES = 10485760;  // 10 MB per file.
	const MAX_TOTAL_BYTES      = 26214400;  // 25 MB across all files.

	/**
	 * Register the hook.
	 *
	 * @return void
	 */
	public function register() {
		// Priority 5 so we run before any late-priority filters that a
		// site might use to inspect the (already-short-circuited) result.
		add_filter( 'pre_wp_mail', array( $this, 'intercept' ), 5, 2 );
	}

	/**
	 * @param null|bool           $short_circuit Always null when we get it.
	 * @param array<string,mixed> $atts          compact(to,subject,message,headers,attachments).
	 * @return null|bool
	 */
	public function intercept( $short_circuit, $atts ) {
		// Not configured → stay out of the way entirely.
		if ( ! ZevSend_SMTP_Settings::is_configured() ) {
			return null;
		}

		try {
			$payload = $this->build_payload( $atts );
		} catch ( Exception $e ) {
			// A build error (e.g. no valid recipients, attachment too big)
			// is a hard failure for this message.
			return $this->fail(
				$atts,
				'build_error',
				$e->getMessage(),
				array()
			);
		}

		$client = new ZevSend_SMTP_Api_Client( ZevSend_SMTP_Settings::api_key() );

		try {
			$result = $client->send_email( $payload );
		} catch ( ZevSend_SMTP_Api_Exception $e ) {
			$code = $e->get_error_code();
			return $this->fail(
				$atts,
				'' !== $code ? $code : 'api_error',
				$e->getMessage(),
				$payload
			);
		} catch ( Exception $e ) {
			return $this->fail(
				$atts,
				'api_error',
				$e->getMessage(),
				$payload
			);
		}

		ZevSend_SMTP_Logger::record(
			array(
				'status'     => 'sent',
				'to'         => $this->recipients_for_log( $payload ),
				'subject'    => isset( $payload['subject'] ) ? $payload['subject'] : '',
				'message_id' => isset( $result['id'] ) ? $result['id'] : '',
			)
		);

		/**
		 * Fires after a message is accepted by ZevSend.
		 *
		 * @param array $result  Parsed API response.
		 * @param array $payload The sent payload.
		 */
		do_action( 'zevsend_smtp_email_sent', $result, $payload );

		return true;
	}

	/**
	 * Build the whitelisted API payload from wp_mail's atts. The API
	 * rejects unknown fields, so we include ONLY documented keys.
	 *
	 * @param array<string,mixed> $atts wp_mail atts.
	 * @return array<string,mixed>
	 *
	 * @throws Exception When the message can't be sent as-is.
	 */
	private function build_payload( array $atts ) {
		$to_raw      = isset( $atts['to'] ) ? $atts['to'] : array();
		$subject     = isset( $atts['subject'] ) ? (string) $atts['subject'] : '';
		$message     = isset( $atts['message'] ) ? (string) $atts['message'] : '';
		$headers_raw = isset( $atts['headers'] ) ? $atts['headers'] : array();
		$attach_raw  = isset( $atts['attachments'] ) ? $atts['attachments'] : array();

		$headers = $this->parse_headers( $headers_raw );

		// Recipients.
		$to  = zevsend_smtp_parse_recipient_list( $to_raw );
		$cc  = $headers['cc'];
		$bcc = $headers['bcc'];

		if ( empty( $to ) ) {
			throw new Exception(
				esc_html__( 'No valid recipient address.', 'zevsend-smtp' )
			);
		}

		$combined = count( $to ) + count( $cc ) + count( $bcc );
		if ( $combined > self::MAX_RECIPIENTS ) {
			throw new Exception(
				esc_html(
					sprintf(
						/* translators: 1: number of recipients, 2: allowed max. */
						__( 'This message has %1$d recipients; ZevSend allows up to %2$d per send.', 'zevsend-smtp' ),
						$combined,
						self::MAX_RECIPIENTS
					)
				)
			);
		}

		$payload = array(
			'to'      => $to,
			'subject' => zevsend_smtp_strip_header_breaks( $subject ),
		);
		if ( ! empty( $cc ) ) {
			$payload['cc'] = $cc;
		}
		if ( ! empty( $bcc ) ) {
			$payload['bcc'] = $bcc;
		}
		if ( '' !== $headers['reply_to'] ) {
			$payload['reply_to'] = $headers['reply_to'];
		}

		// From address + sender name.
		//
		// Sandbox keys lock the sender to ZevSend's platform address, so
		// we send no `from` at all there. In live mode we send the From
		// address, and handle the display name ZevSend's way: it must be
		// the domain's approved brand name or an approved alternate, or
		// the send is rejected. So we NEVER pass through a display name
		// set by another plugin (WooCommerce etc.) — that would fail. We
		// send only what the admin configured here:
		//   - a Display ID (dn_…) → the typo-proof, recommended path, OR
		//   - an exact approved brand name in "From name", OR
		//   - nothing → ZevSend auto-fills the approved brand name.
		if ( 'sandbox' !== ZevSend_SMTP_Settings::mode() ) {
			$from_email = $this->resolve_from_email( $headers['from'] );
			if ( '' !== $from_email ) {
				$display_id = (string) ZevSend_SMTP_Settings::get( 'from_display_id', '' );
				$from_name  = (string) ZevSend_SMTP_Settings::get( 'from_name', '' );

				/**
				 * Filter the sender display name in live mode. Return an
				 * empty string to let ZevSend auto-fill the approved brand
				 * name. Whatever you return must be an approved name for
				 * the domain or the send is rejected.
				 *
				 * @param string $from_name  Admin-configured name.
				 * @param array  $atts       Original wp_mail atts.
				 */
				$from_name = (string) apply_filters( 'zevsend_smtp_from_display_name', $from_name, $atts );

				if ( '' !== $display_id ) {
					// Display ID wins; send a bare From address so the id
					// alone controls the name.
					$payload['from']            = zevsend_smtp_format_from( $from_email, '' );
					$payload['from_display_id'] = $display_id;
				} else {
					$payload['from'] = zevsend_smtp_format_from( $from_email, $from_name );
				}
			}
		}

		// Body. wp_mail sends a single body; the Content-Type header
		// tells us whether it's HTML or plain text.
		if ( $headers['is_html'] ) {
			$payload['html'] = $message;
		} else {
			$payload['text'] = $message;
		}

		// Attachments.
		$attachments = $this->build_attachments( $attach_raw );
		if ( ! empty( $attachments ) ) {
			$payload['attachments'] = $attachments;
		}

		return $payload;
	}

	/**
	 * Decide the effective From ADDRESS (not the display name, which is
	 * resolved separately against ZevSend's approved brand identity).
	 *
	 * Precedence:
	 *   1. If "force from" is on, always use the configured address.
	 *   2. Otherwise use the From the caller set in its headers.
	 *   3. Otherwise fall back to the configured address.
	 *
	 * In live mode the returned address must be on a domain verified in
	 * ZevSend, or the API rejects the send.
	 *
	 * @param array{email:string,name:string} $header_from Parsed header From.
	 * @return string Bare email, or '' when none is configured.
	 */
	private function resolve_from_email( $header_from ) {
		$cfg_email  = (string) ZevSend_SMTP_Settings::get( 'from_email', '' );
		$force_addr = (bool) ZevSend_SMTP_Settings::get( 'force_from', false );

		if ( $force_addr && '' !== $cfg_email ) {
			return $cfg_email;
		}
		if ( '' !== $header_from['email'] ) {
			return $header_from['email'];
		}
		return $cfg_email;
	}

	/**
	 * Parse wp_mail headers (string or array) into the pieces we need.
	 *
	 * @param string|array $headers_raw Raw headers.
	 * @return array{
	 *   is_html:bool,
	 *   from:array{email:string,name:string},
	 *   cc:string[], bcc:string[], reply_to:string
	 * }
	 */
	private function parse_headers( $headers_raw ) {
		$out = array(
			'is_html'  => false,
			'from'     => array(
				'email' => '',
				'name'  => '',
			),
			'cc'       => array(),
			'bcc'      => array(),
			'reply_to' => '',
		);

		if ( empty( $headers_raw ) ) {
			return $out;
		}

		if ( ! is_array( $headers_raw ) ) {
			// Split a raw header blob on newlines.
			$headers_raw = explode( "\n", str_replace( "\r\n", "\n", (string) $headers_raw ) );
		}

		foreach ( $headers_raw as $header ) {
			$header = trim( (string) $header );
			if ( '' === $header || false === strpos( $header, ':' ) ) {
				continue;
			}
			list( $name, $value ) = explode( ':', $header, 2 );
			$name  = strtolower( trim( $name ) );
			$value = trim( $value );

			switch ( $name ) {
				case 'content-type':
					if ( false !== stripos( $value, 'text/html' ) ) {
						$out['is_html'] = true;
					}
					break;
				case 'from':
					$out['from'] = zevsend_smtp_parse_address( $value );
					break;
				case 'cc':
					$out['cc'] = array_merge( $out['cc'], zevsend_smtp_parse_recipient_list( $value ) );
					break;
				case 'bcc':
					$out['bcc'] = array_merge( $out['bcc'], zevsend_smtp_parse_recipient_list( $value ) );
					break;
				case 'reply-to':
					$parsed = zevsend_smtp_parse_address( $value );
					if ( '' !== $parsed['email'] ) {
						$out['reply_to'] = $parsed['email'];
					}
					break;
				// Any other header (custom X-*, etc.) is intentionally
				// dropped: the API accepts a fixed field set, not
				// arbitrary MIME headers.
			}
		}

		return $out;
	}

	/**
	 * Turn wp_mail's attachment paths into the API's
	 * {filename, content(base64), content_type} shape, enforcing the
	 * documented size and count ceilings.
	 *
	 * @param string|array $attach_raw Paths (or name => path).
	 * @return array<int,array{filename:string,content:string,content_type:string}>
	 *
	 * @throws Exception When a limit is exceeded.
	 */
	private function build_attachments( $attach_raw ) {
		if ( empty( $attach_raw ) ) {
			return array();
		}
		if ( ! is_array( $attach_raw ) ) {
			$attach_raw = array( $attach_raw );
		}

		if ( count( $attach_raw ) > self::MAX_ATTACHMENTS ) {
			throw new Exception(
				esc_html(
					sprintf(
						/* translators: %d: allowed attachment count. */
						__( 'Too many attachments; ZevSend allows up to %d.', 'zevsend-smtp' ),
						self::MAX_ATTACHMENTS
					)
				)
			);
		}

		$out   = array();
		$total = 0;

		foreach ( $attach_raw as $name => $path ) {
			$path = (string) $path;
			if ( '' === $path || ! is_readable( $path ) ) {
				// A missing file is worth failing on: the caller expected
				// it to go out (e.g. a WooCommerce invoice PDF).
				throw new Exception(
					esc_html__( 'An attachment could not be read.', 'zevsend-smtp' )
				);
			}

			$size = (int) filesize( $path );
			if ( $size > self::MAX_ATTACHMENT_BYTES ) {
				throw new Exception(
					esc_html__( 'An attachment exceeds the 10 MB per-file limit.', 'zevsend-smtp' )
				);
			}
			$total += $size;
			if ( $total > self::MAX_TOTAL_BYTES ) {
				throw new Exception(
					esc_html__( 'Attachments exceed the 25 MB total limit.', 'zevsend-smtp' )
				);
			}

			// Read via WP_Filesystem when available; fall back to a direct
			// read for the common case.
			$contents = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local attachment the caller already staged; no HTTP.
			if ( false === $contents ) {
				throw new Exception(
					esc_html__( 'An attachment could not be read.', 'zevsend-smtp' )
				);
			}

			$filename = is_string( $name ) && '' !== $name
				? $name
				: basename( $path );
			// Strip any path components and control chars from the name.
			$filename = zevsend_smtp_strip_header_breaks( basename( $filename ) );

			$out[] = array(
				'filename'     => $filename,
				'content'      => base64_encode( $contents ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- API requires base64 attachment content, not obfuscation.
				'content_type' => $this->guess_mime( $path, $filename ),
			);
		}

		return $out;
	}

	/**
	 * Best-effort MIME detection: finfo when present, else WordPress's
	 * extension map, else a safe generic type.
	 *
	 * @param string $path     File path.
	 * @param string $filename Display filename.
	 * @return string
	 */
	private function guess_mime( $path, $filename ) {
		if ( function_exists( 'finfo_open' ) ) {
			$finfo = finfo_open( FILEINFO_MIME_TYPE );
			if ( $finfo ) {
				$type = finfo_file( $finfo, $path );
				finfo_close( $finfo );
				if ( is_string( $type ) && '' !== $type ) {
					return $type;
				}
			}
		}
		$checked = wp_check_filetype( $filename );
		if ( ! empty( $checked['type'] ) ) {
			return $checked['type'];
		}
		return 'application/octet-stream';
	}

	/**
	 * Common failure path: log, notify listeners, and choose whether to
	 * hand back to WordPress's native mailer.
	 *
	 * @param array<string,mixed> $atts    Original wp_mail atts.
	 * @param string              $code    Short error code for the log.
	 * @param string              $message Human error message.
	 * @param array<string,mixed> $payload Built payload (may be empty).
	 * @return null|false null → fall back to native; false → hard fail.
	 */
	private function fail( $atts, $code, $message, $payload ) {
		$to = ! empty( $payload['to'] )
			? $this->recipients_for_log( $payload )
			: zevsend_smtp_parse_recipient_list( isset( $atts['to'] ) ? $atts['to'] : array() );

		ZevSend_SMTP_Logger::record(
			array(
				'status'        => 'failed',
				'to'            => $to,
				'subject'       => isset( $atts['subject'] ) ? (string) $atts['subject'] : '',
				'error_code'    => $code,
				'error_message' => $message,
			)
		);

		// Let anything listening on wp_mail_failed react (WP core would
		// fire this on a native failure; we short-circuited, so we fire
		// it ourselves). We keep the atts as the error data (as core
		// does) and add our error code so the settings screen can show a
		// plain-language hint.
		$data = is_array( $atts ) ? $atts : array();
		$data['zevsend_error_code'] = $code;
		$error = new WP_Error( 'zevsend_smtp_failed', $message, $data );
		/** This is the same action WordPress fires for native failures. */
		do_action( 'wp_mail_failed', $error );

		if ( ZevSend_SMTP_Settings::get( 'fallback_native', false ) ) {
			// Returning null lets WordPress try its own mailer. Off by
			// default: a site that installed us because native mail was
			// broken usually does not want a silent, likely-failing
			// retry that reports success.
			return null;
		}
		return false;
	}

	/**
	 * Flatten to+cc+bcc for the log's recipients column.
	 *
	 * @param array<string,mixed> $payload Payload.
	 * @return string[]
	 */
	private function recipients_for_log( $payload ) {
		$all = array();
		foreach ( array( 'to', 'cc', 'bcc' ) as $field ) {
			if ( ! empty( $payload[ $field ] ) && is_array( $payload[ $field ] ) ) {
				$all = array_merge( $all, $payload[ $field ] );
			}
		}
		return $all;
	}
}
