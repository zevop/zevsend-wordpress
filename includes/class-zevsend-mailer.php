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

		// From. Sandbox keys lock the sender to ZevSend's platform
		// address, so sending any `from` there returns an error — omit
		// it and let ZevSend fill it in. In live mode we send a From.
		$from = $this->resolve_from( $headers['from'] );
		if ( 'sandbox' !== ZevSend_SMTP_Settings::mode() && '' !== $from ) {
			$payload['from'] = $from;
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
	 * Decide the effective From.
	 *
	 * Precedence:
	 *   1. If "force from" is on, always use the configured address.
	 *   2. Otherwise use the From the caller set in headers.
	 *   3. Otherwise fall back to the configured address.
	 *
	 * The name follows its own force toggle so an admin can pin the
	 * address but let each plugin keep its own display name (common for
	 * WooCommerce "Your Store" vs. "Your Store Receipts").
	 *
	 * @param array{email:string,name:string} $header_from Parsed header From.
	 * @return string Formatted From, or '' when none is configured.
	 */
	private function resolve_from( $header_from ) {
		$cfg_email  = (string) ZevSend_SMTP_Settings::get( 'from_email', '' );
		$cfg_name   = (string) ZevSend_SMTP_Settings::get( 'from_name', '' );
		$force_addr = (bool) ZevSend_SMTP_Settings::get( 'force_from', false );
		$force_name = (bool) ZevSend_SMTP_Settings::get( 'force_from_name', false );

		$email = '';
		if ( $force_addr && '' !== $cfg_email ) {
			$email = $cfg_email;
		} elseif ( '' !== $header_from['email'] ) {
			$email = $header_from['email'];
		} elseif ( '' !== $cfg_email ) {
			$email = $cfg_email;
		}

		if ( '' === $email ) {
			return '';
		}

		$name = '';
		if ( $force_name && '' !== $cfg_name ) {
			$name = $cfg_name;
		} elseif ( '' !== $header_from['name'] ) {
			$name = $header_from['name'];
		} else {
			$name = $cfg_name;
		}

		return zevsend_smtp_format_from( $email, $name );
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
		// it ourselves).
		$error = new WP_Error( 'zevsend_smtp_failed', $message, $atts );
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
