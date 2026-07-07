/**
 * ZevSend SMTP admin. Hand-written, unminified: the source you read is
 * the source that ships. Only job is the AJAX "send test email" button.
 */
( function ( $ ) {
	'use strict';

	$( function () {
		var $btn = $( '#zevsend_test_btn' );
		if ( ! $btn.length ) {
			return;
		}
		var $to = $( '#zevsend_test_to' );
		var $result = $( '#zevsend_test_result' );

		$btn.on( 'click', function () {
			var to = $.trim( $to.val() );
			$result.removeClass( 'is-ok is-error' ).text( '' );

			if ( ! to ) {
				$result.addClass( 'is-error' ).text( '⚠' );
				return;
			}

			$btn.prop( 'disabled', true ).text( ZevSendSMTP.testing );

			$.post( ZevSendSMTP.ajaxUrl, {
				action: 'zevsend_smtp_test',
				nonce: ZevSendSMTP.nonce,
				to: to
			} )
				.done( function ( res ) {
					if ( res && res.success ) {
						$result.addClass( 'is-ok' ).text( res.data.message );
					} else {
						var msg = res && res.data && res.data.message
							? res.data.message
							: 'Failed.';
						$result.addClass( 'is-error' ).text( msg );
					}
				} )
				.fail( function () {
					$result.addClass( 'is-error' ).text( 'Request failed.' );
				} )
				.always( function () {
					$btn.prop( 'disabled', false ).text( ZevSendSMTP.sendTest );
				} );
		} );
	} );
} )( jQuery );
