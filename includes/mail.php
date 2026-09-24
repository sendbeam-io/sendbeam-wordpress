<?php
/**
 * Site email through SendBeam.
 *
 * Everything WordPress sends with wp_mail() — order confirmations, password
 * resets, form notifications, plugin alerts — can go out through the
 * workspace's verified domain instead of the web server's mail() function.
 * The `pre_wp_mail` filter hands each message to SendBeam's transactional
 * API over HTTPS; nothing talks SMTP. Messages with attachments, and any
 * message SendBeam cannot take (when the fallback is on), are left to
 * WordPress's own mailer.
 *
 * @package SendBeam
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'pre_wp_mail', 'sendbeam_pre_wp_mail', 10, 2 );
add_action( 'admin_post_sendbeam_test_mail', 'sendbeam_handle_test_mail' );

/** How many results to keep for the settings page. */
const SENDBEAM_MAIL_LOG_SIZE = 20;

/**
 * The API key: the wp-config.php constant wins over the saved setting, so a
 * key never has to live in the database.
 *
 * @return string
 */
function sendbeam_api_key() {
	if ( defined( 'SENDBEAM_API_KEY' ) && SENDBEAM_API_KEY ) {
		return (string) SENDBEAM_API_KEY;
	}
	$settings = sendbeam_settings();
	return (string) $settings['api_key'];
}

/**
 * Whether site email is switched on and usable.
 *
 * @return bool
 */
function sendbeam_mail_enabled() {
	$settings = sendbeam_settings();
	return ! empty( $settings['mail_enabled'] ) && '' !== sendbeam_api_key();
}

/**
 * Turn wp_mail()'s headers (string or array) into what the API wants.
 *
 * @param string|string[] $headers Raw headers.
 * @return array{content_type:string, from:string, reply_to:string, cc:string[], bcc:string[], extra:array<string,string>}
 */
function sendbeam_parse_mail_headers( $headers ) {
	$out = array(
		'content_type' => '',
		'from'         => '',
		'reply_to'     => '',
		'cc'           => array(),
		'bcc'          => array(),
		'extra'        => array(),
	);
	if ( empty( $headers ) ) {
		return $out;
	}
	$lines = is_array( $headers ) ? $headers : preg_split( "/\r\n|\r|\n/", (string) $headers );
	foreach ( $lines as $line ) {
		$line = trim( (string) $line );
		if ( '' === $line || false === strpos( $line, ':' ) ) {
			continue;
		}
		list( $name, $value ) = explode( ':', $line, 2 );
		$name                 = strtolower( trim( $name ) );
		$value                = trim( $value );
		switch ( $name ) {
			case 'content-type':
				$out['content_type'] = strtolower( trim( explode( ';', $value )[0] ) );
				break;
			case 'from':
				$out['from'] = $value;
				break;
			case 'reply-to':
				$out['reply_to'] = $value;
				break;
			case 'cc':
				$out['cc'] = array_merge( $out['cc'], array_map( 'trim', explode( ',', $value ) ) );
				break;
			case 'bcc':
				$out['bcc'] = array_merge( $out['bcc'], array_map( 'trim', explode( ',', $value ) ) );
				break;
			default:
				if ( 0 === strpos( $name, 'x-' ) && count( $out['extra'] ) < 10 ) {
					$out['extra'][ implode( '-', array_map( 'ucfirst', explode( '-', $name ) ) ) ] = $value;
				}
		}
	}
	return $out;
}

/**
 * Split "Name <address>" into its parts.
 *
 * @param string $value Address, with or without a display name.
 * @return array{email:string, name:string}
 */
function sendbeam_split_address( $value ) {
	$value = trim( (string) $value );
	if ( preg_match( '/^(?:"?([^"<]*)"?\s*)?<([^>]+)>$/', $value, $m ) ) {
		return array(
			'email' => trim( $m[2] ),
			'name'  => trim( $m[1] ),
		);
	}
	return array(
		'email' => $value,
		'name'  => '',
	);
}

/**
 * The address WordPress would use by itself (wordpress@example.com). It is not
 * on a verified domain, so it is never passed to SendBeam as a From.
 *
 * @return string
 */
function sendbeam_wp_default_from() {
	$sitename = wp_parse_url( network_home_url(), PHP_URL_HOST );
	if ( 'www.' === substr( (string) $sitename, 0, 4 ) ) {
		$sitename = substr( $sitename, 4 );
	}
	return 'wordpress@' . $sitename;
}

/**
 * Build the API request body for one wp_mail() call.
 *
 * @param array $atts wp_mail() arguments (to, subject, message, headers, attachments).
 * @return array
 */
function sendbeam_build_mail_body( $atts ) {
	$settings = sendbeam_settings();
	$headers  = sendbeam_parse_mail_headers( isset( $atts['headers'] ) ? $atts['headers'] : '' );

	$content_type = $headers['content_type'] ? $headers['content_type'] : 'text/plain';
	/** This filter is documented in wp-includes/pluggable.php */
	$content_type = apply_filters( 'wp_mail_content_type', $content_type ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's own hook; a wp_mail() replacement must fire it so other plugins keep working.

	$body = array(
		'subject' => (string) $atts['subject'],
		'to'      => is_array( $atts['to'] ) ? array_values( $atts['to'] ) : (string) $atts['to'],
	);
	if ( 'text/html' === $content_type ) {
		$body['html'] = (string) $atts['message'];
	} else {
		$body['text'] = (string) $atts['message'];
	}
	if ( $headers['cc'] ) {
		$body['cc'] = $headers['cc'];
	}
	if ( $headers['bcc'] ) {
		$body['bcc'] = $headers['bcc'];
	}
	if ( $headers['reply_to'] ) {
		$body['reply_to'] = $headers['reply_to'];
	}
	if ( $headers['extra'] ) {
		$body['headers'] = $headers['extra'];
	}

	// From: the plugin's own setting, else a From: header the caller set, else
	// whatever the wp_mail_from filters say — but never WordPress's made-up
	// wordpress@ address, which SendBeam would refuse; the workspace sender is used then.
	$from       = $headers['from'] ? sendbeam_split_address( $headers['from'] ) : array(
		'email' => '',
		'name'  => '',
	);
	$from_email = $settings['mail_from_email'] ? $settings['mail_from_email'] : $from['email'];
	$from_name  = $settings['mail_from_name'] ? $settings['mail_from_name'] : $from['name'];
	/** This filter is documented in wp-includes/pluggable.php */
	$from_email = apply_filters( 'wp_mail_from', $from_email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's own hook; a wp_mail() replacement must fire it so other plugins keep working.
	/** This filter is documented in wp-includes/pluggable.php */
	$from_name = apply_filters( 'wp_mail_from_name', $from_name ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's own hook; a wp_mail() replacement must fire it so other plugins keep working.
	if ( $from_email && strtolower( $from_email ) !== sendbeam_wp_default_from() && is_email( $from_email ) ) {
		$body['from_email'] = $from_email;
	}
	if ( $from_name ) {
		$body['from_name'] = $from_name;
	}
	return $body;
}

/**
 * Send one message through the API.
 *
 * @param array $body Request body from sendbeam_build_mail_body().
 * @return array{ok:bool, error:string, status:int}
 */
function sendbeam_api_send( $body ) {
	$response = wp_remote_post(
		sendbeam_app_url() . '/api/v1/transactional',
		array(
			'timeout' => 15,
			'headers' => array(
				'Content-Type' => 'application/json',
				'x-api-key'    => sendbeam_api_key(),
				'User-Agent'   => 'SendBeam-WordPress/' . SENDBEAM_VERSION . ' (' . home_url() . ')',
			),
			'body'    => wp_json_encode( $body ),
		)
	);
	if ( is_wp_error( $response ) ) {
		return array(
			'ok'     => false,
			'error'  => $response->get_error_message(),
			'status' => 0,
		);
	}
	$status = (int) wp_remote_retrieve_response_code( $response );
	$data   = json_decode( (string) wp_remote_retrieve_body( $response ), true );
	if ( 200 === $status && ! empty( $data['ok'] ) ) {
		return array(
			'ok'     => true,
			'error'  => '',
			'status' => 200,
		);
	}
	$error = is_array( $data ) && ! empty( $data['error'] ) ? (string) $data['error'] : 'HTTP ' . $status;
	return array(
		'ok'     => false,
		'error'  => $error,
		'status' => $status,
	);
}

/**
 * Remember the outcome for the settings page (last few only).
 *
 * @param string $to      Recipient(s).
 * @param string $subject Subject.
 * @param string $result  'sent', 'fallback' or 'failed'.
 * @param string $note    Error or reason.
 */
function sendbeam_mail_log( $to, $subject, $result, $note = '' ) {
	$log = get_option( 'sendbeam_mail_log', array() );
	if ( ! is_array( $log ) ) {
		$log = array();
	}
	array_unshift(
		$log,
		array(
			'at'      => time(),
			'to'      => is_array( $to ) ? implode( ', ', $to ) : (string) $to,
			'subject' => (string) $subject,
			'result'  => $result,
			'note'    => (string) $note,
		)
	);
	update_option( 'sendbeam_mail_log', array_slice( $log, 0, SENDBEAM_MAIL_LOG_SIZE ), false );
}

/**
 * The pre_wp_mail filter. Returning null lets WordPress send the message
 * itself; true/false short-circuits with that result.
 *
 * @param null|bool $short_circuit Whatever an earlier filter decided.
 * @param array     $atts          wp_mail() arguments.
 * @return null|bool
 */
function sendbeam_pre_wp_mail( $short_circuit, $atts ) {
	if ( null !== $short_circuit || ! sendbeam_mail_enabled() ) {
		return $short_circuit;
	}
	$settings = sendbeam_settings();
	$to       = isset( $atts['to'] ) ? $atts['to'] : '';
	$subject  = isset( $atts['subject'] ) ? $atts['subject'] : '';

	if ( ! empty( $atts['attachments'] ) ) {
		// Attachments are not carried yet; the server's mailer keeps them intact.
		sendbeam_mail_log( $to, $subject, 'fallback', __( 'has attachments', 'sendbeam' ) );
		return null;
	}

	$result = sendbeam_api_send( sendbeam_build_mail_body( $atts ) );
	if ( $result['ok'] ) {
		sendbeam_mail_log( $to, $subject, 'sent' );
		return true;
	}

	if ( ! empty( $settings['mail_fallback'] ) ) {
		sendbeam_mail_log( $to, $subject, 'fallback', $result['error'] );
		return null;
	}
	sendbeam_mail_log( $to, $subject, 'failed', $result['error'] );
	/** This action is documented in wp-includes/pluggable.php */
	do_action( 'wp_mail_failed', new WP_Error( 'sendbeam_mail_failed', $result['error'], $atts ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's own hook; a wp_mail() replacement must fire it so other plugins keep working.
	return false;
}

/**
 * What the site owner should know about the connection, at the moment of asking.
 *
 * Gathered once and used twice: it is the body of the test email, and — when
 * the send fails — the bundle the failure card offers to copy. Collecting it
 * at failure time rather than at render time is the whole point: a support
 * request that describes the state of the site half an hour later describes a
 * different site.
 *
 * @return array<string,string>
 */
function sendbeam_mail_facts() {
	$settings = sendbeam_settings();
	$status   = sendbeam_is_connected() ? sendbeam_connect_status() : sendbeam_connect_empty_status();
	$domain   = $status['domain'];

	$domain_line = __( 'None set up', 'sendbeam' );
	if ( '' !== $domain['name'] ) {
		$domain_line = $domain['name'] . ' — ' . ( $domain['verified'] ? __( 'verified', 'sendbeam' ) : __( 'not verified yet', 'sendbeam' ) );
	} elseif ( '' !== (string) $status['domain_state'] ) {
		$domain_line = sprintf(
			/* translators: %s: a SendBeam domain state, e.g. not_granted */
			__( 'None set up (%s)', 'sendbeam' ),
			(string) $status['domain_state']
		);
	}

	$from_email = '' !== (string) $settings['mail_from_email'] ? (string) $settings['mail_from_email'] : (string) $status['sender']['from_email'];
	$from_name  = '' !== (string) $settings['mail_from_name'] ? (string) $settings['mail_from_name'] : (string) $status['sender']['from_name'];

	return array(
		__( 'Site', 'sendbeam' )           => get_bloginfo( 'name' ) . ' — ' . home_url(),
		__( 'From name', 'sendbeam' )      => '' !== $from_name ? $from_name : __( 'the workspace sender', 'sendbeam' ),
		__( 'From address', 'sendbeam' )   => '' !== $from_email ? $from_email : __( 'the workspace sender', 'sendbeam' ),
		__( 'Sending domain', 'sendbeam' ) => $domain_line,
		__( 'Workspace', 'sendbeam' )      => '' !== (string) $status['workspace']['name'] ? (string) $status['workspace']['name'] : __( 'not reported', 'sendbeam' ),
		__( 'Sent', 'sendbeam' )           => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ),
		__( 'Plugin', 'sendbeam' )         => 'SendBeam ' . SENDBEAM_VERSION,
	);
}

/**
 * The test email itself: one screenful a site owner can keep.
 *
 * Table-based and inline-styled, because that is still the only HTML an email
 * client can be relied on to render, and with a plain-text alternative that
 * says the same things — a test email that only exists as HTML proves less
 * than one that proves both halves arrived.
 *
 * No images: the three-square mark is three table cells with a background
 * colour, which needs no remote request, survives an image-blocking client
 * and cannot be used to tell whether the message was opened.
 *
 * @param array $facts Rows from sendbeam_mail_facts().
 * @return string
 */
function sendbeam_test_mail_html( $facts ) {
	$body = 'margin:0;padding:0;background:#EDEBE6;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;color:#121212;';
	$cell = 'padding:24px 28px;';

	$rows = '';
	foreach ( $facts as $label => $value ) {
		$rows .= '<tr>'
			. '<td style="padding:8px 0;border-bottom:1px solid #e2e0db;font-size:13px;color:#5c5c5c;white-space:nowrap;vertical-align:top;">' . esc_html( $label ) . '</td>'
			. '<td style="padding:8px 0 8px 18px;border-bottom:1px solid #e2e0db;font-size:13px;color:#121212;vertical-align:top;word-break:break-word;">' . esc_html( $value ) . '</td>'
			. '</tr>';
	}

	$log_url = sendbeam_page_url( 'sendbeam-mail' );

	$html = '<!doctype html><html><head><meta charset="utf-8" />'
		. '<meta name="viewport" content="width=device-width" />'
		. '<title>' . esc_html__( 'SendBeam test email', 'sendbeam' ) . '</title>'
		. '<style>@media only screen and (max-width:620px){.sb-container{width:100%!important}.sb-pad{padding:20px!important}}</style>'
		. '</head><body style="' . esc_attr( $body ) . '">'
		. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#EDEBE6;"><tr><td align="center" style="padding:24px 12px;">'
		. '<table role="presentation" class="sb-container" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px;max-width:600px;background:#ffffff;border:1px solid #121212;">'

		// The mark, on the ink band, as three coloured cells.
		. '<tr><td class="sb-pad" style="' . esc_attr( $cell ) . 'background:#121212;">'
		. '<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>'
		. '<td width="14" height="14" style="background:#E2442A;font-size:0;line-height:0;">&nbsp;</td>'
		. '<td width="6" style="font-size:0;line-height:0;">&nbsp;</td>'
		. '<td width="14" height="14" style="background:#1F3FBF;font-size:0;line-height:0;">&nbsp;</td>'
		. '<td width="6" style="font-size:0;line-height:0;">&nbsp;</td>'
		. '<td width="14" height="14" style="background:#3B7D46;font-size:0;line-height:0;">&nbsp;</td>'
		. '<td style="padding-left:14px;color:#EDEBE6;font-size:17px;font-weight:700;">SendBeam</td>'
		. '</tr></table></td></tr>'

		// The verdict.
		. '<tr><td class="sb-pad" style="' . esc_attr( $cell ) . 'padding-bottom:4px;">'
		. '<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>'
		. '<td width="26" height="26" align="center" style="background:#3B7D46;color:#ffffff;font-size:15px;line-height:26px;">&#10003;</td>'
		. '<td style="padding-left:12px;font-size:19px;font-weight:700;">' . esc_html__( 'Your site can send email through SendBeam', 'sendbeam' ) . '</td>'
		. '</tr></table>'
		. '<p style="margin:14px 0 0;font-size:14px;line-height:1.5;color:#121212;">'
		. esc_html__( 'This message was sent by your WordPress site, through SendBeam, using the settings below. Nothing else needs doing.', 'sendbeam' )
		. '</p></td></tr>'

		// The facts.
		. '<tr><td class="sb-pad" style="' . esc_attr( $cell ) . 'padding-top:14px;padding-bottom:14px;">'
		. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">' . $rows . '</table>'
		. '</td></tr>'

		// What happens now.
		. '<tr><td class="sb-pad" style="' . esc_attr( $cell ) . 'padding-top:6px;background:#EDEBE6;border-top:1px solid #121212;">'
		. '<p style="margin:0 0 8px;font-size:15px;font-weight:700;">' . esc_html__( 'What happens now', 'sendbeam' ) . '</p>'
		. '<p style="margin:0 0 8px;font-size:14px;line-height:1.5;">'
		. esc_html__( 'Every email this site already sends goes out this way from now on: order confirmations, password resets, and comment and form notifications.', 'sendbeam' )
		. '</p>'
		. '<p style="margin:0 0 16px;font-size:14px;line-height:1.5;">'
		. esc_html__( 'Each one is recorded in the email log in wp-admin, with whether it was sent, fell back to the server\'s own mailer, or failed.', 'sendbeam' )
		. '</p>'
		. '<p style="margin:0;"><a href="' . esc_url( $log_url ) . '" style="display:inline-block;padding:11px 18px;background:#2271b1;color:#ffffff;font-size:14px;font-weight:700;text-decoration:none;">'
		. esc_html__( 'Open the email log', 'sendbeam' ) . '</a></p>'
		. '</td></tr>'

		. '</table></td></tr></table></body></html>';

	return $html;
}

/**
 * The same thing in words, for a client that will not render the HTML.
 *
 * @param array $facts Rows from sendbeam_mail_facts().
 * @return string
 */
function sendbeam_test_mail_text( $facts ) {
	$lines = array(
		__( 'Your site can send email through SendBeam', 'sendbeam' ),
		'',
		__( 'This message was sent by your WordPress site, through SendBeam, using the settings below. Nothing else needs doing.', 'sendbeam' ),
		'',
	);
	foreach ( $facts as $label => $value ) {
		$lines[] = $label . ': ' . $value;
	}
	$lines[] = '';
	$lines[] = __( 'What happens now', 'sendbeam' );
	$lines[] = __( 'Every email this site already sends goes out this way from now on: order confirmations, password resets, and comment and form notifications.', 'sendbeam' );
	$lines[] = __( 'Each one is recorded in the email log in wp-admin, with whether it was sent, fell back to the server\'s own mailer, or failed.', 'sendbeam' );
	$lines[] = '';
	$lines[] = __( 'Open the email log:', 'sendbeam' ) . ' ' . sendbeam_page_url( 'sendbeam-mail' );

	return implode( "\n", $lines );
}

/**
 * Everything somebody would be asked for in a support reply, in one block.
 *
 * Captured at the moment of failure, with the raw error, so that the person
 * pasting it into an email is describing the send that went wrong rather than
 * the state of the site whenever they got round to writing.
 *
 * @param string $error The raw error from the API.
 * @param int    $status HTTP status, or 0 when the request never landed.
 * @return string
 */
function sendbeam_mail_error_bundle( $error, $status = 0 ) {
	$lines = array(
		'SendBeam ' . SENDBEAM_VERSION,
		'WordPress ' . get_bloginfo( 'version' ),
		'PHP ' . PHP_VERSION,
		'Site: ' . home_url(),
		'Key: ' . ( '' === sendbeam_api_key() ? 'none' : ( sendbeam_key_in_config() ? 'wp-config.php constant' : 'stored in options' ) ),
	);
	foreach ( sendbeam_mail_facts() as $label => $value ) {
		$lines[] = $label . ': ' . $value;
	}
	$lines[] = 'HTTP: ' . ( $status ? (int) $status : 'no response' );
	$lines[] = 'Error: ' . $error;

	return implode( "\n", $lines );
}

/**
 * "Send a test email" on the settings page.
 */
function sendbeam_handle_test_mail() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'sendbeam' ) );
	}
	check_admin_referer( 'sendbeam_test_mail' );

	$user  = wp_get_current_user();
	$to    = (string) $user->user_email;
	$facts = sendbeam_mail_facts();

	if ( ! sendbeam_mail_enabled() ) {
		$outcome = array(
			'ok'     => false,
			'title'  => __( 'Site email is not switched on yet', 'sendbeam' ),
			'means'  => __( 'Nothing was sent, because this site is still handing its email to the web server\'s own mailer.', 'sendbeam' ),
			'do'     => __( 'Tick "Send this site\'s email through SendBeam" above, save, and try again. It needs a working API key with the "Send site email" permission.', 'sendbeam' ),
			'to'     => $to,
			'bundle' => sendbeam_mail_error_bundle( 'site email is switched off', 0 ),
			'at'     => time(),
		);
		sendbeam_finish_test_mail( $outcome );
	}

	$result = sendbeam_api_send(
		array(
			'to'      => $to,
			'subject' => sprintf( /* translators: %s: site name */ __( 'Test email from %s via SendBeam', 'sendbeam' ), get_bloginfo( 'name' ) ),
			'html'    => sendbeam_test_mail_html( $facts ),
			'text'    => sendbeam_test_mail_text( $facts ),
		)
	);

	sendbeam_mail_log( $to, __( 'Test email', 'sendbeam' ), $result['ok'] ? 'sent' : 'failed', $result['ok'] ? '' : $result['error'] );

	if ( $result['ok'] ) {
		sendbeam_finish_test_mail(
			array(
				'ok' => true,
				'to' => $to,
				'at' => time(),
			)
		);
	}

	sendbeam_finish_test_mail(
		array(
			'ok'     => false,
			'title'  => __( 'SendBeam would not send that message', 'sendbeam' ),
			'means'  => sendbeam_test_mail_means( $result ),
			'do'     => sendbeam_test_mail_advice( $result ),
			'to'     => $to,
			'error'  => $result['error'],
			'status' => (int) $result['status'],
			// Captured now, with the answer still in hand: half an hour later
			// this site may be in a different state entirely.
			'bundle' => sendbeam_mail_error_bundle( $result['error'], (int) $result['status'] ),
			'at'     => time(),
		)
	);
}

/**
 * What a refusal means, in the site owner's terms rather than the API's.
 *
 * @param array $result From sendbeam_api_send().
 * @return string
 */
function sendbeam_test_mail_means( $result ) {
	$status = (int) $result['status'];
	if ( 0 === $status ) {
		return __( 'This server could not reach sendbeam.io at all, so SendBeam never saw the message.', 'sendbeam' );
	}
	if ( 401 === $status ) {
		return __( 'SendBeam did not accept this site\'s API key.', 'sendbeam' );
	}
	if ( 403 === $status ) {
		return __( 'The key works, but it was not given permission to send this site\'s email, or the From address is not on a verified domain.', 'sendbeam' );
	}
	if ( 429 === $status ) {
		return __( 'SendBeam is rate-limiting this site, or the workspace has reached its sending limit.', 'sendbeam' );
	}
	if ( $status >= 500 ) {
		return __( 'SendBeam accepted the request and then failed on it. That is an error at SendBeam\'s end, not at yours.', 'sendbeam' );
	}
	return __( 'SendBeam refused the message. The reason it gave is below.', 'sendbeam' );
}

/**
 * What to do about it.
 *
 * @param array $result From sendbeam_api_send().
 * @return string
 */
function sendbeam_test_mail_advice( $result ) {
	$status = (int) $result['status'];
	if ( 0 === $status ) {
		return __( 'Check that this server can make outbound HTTPS requests. A firewall, a proxy or a host that blocks outbound traffic will stop this and nothing else on the site will look wrong.', 'sendbeam' );
	}
	if ( 401 === $status ) {
		return __( 'Reconnect this site under Settings → Connection, or paste a fresh key under Settings → Advanced.', 'sendbeam' );
	}
	if ( 403 === $status ) {
		return __( 'Check the sending domain is verified under Settings → Sending domain, and that the From address above is on it. If the key is missing the permission, reconnect and tick "Send the site\'s own email".', 'sendbeam' );
	}
	if ( 429 === $status ) {
		return __( 'Wait a few minutes and try again. If it keeps happening, check the workspace\'s plan and usage in SendBeam.', 'sendbeam' );
	}
	if ( $status >= 500 ) {
		return __( 'Try again in a few minutes. If it persists, send the details below to hello@sendbeam.io.', 'sendbeam' );
	}
	return __( 'Copy the details below and send them to hello@sendbeam.io — they say exactly what this site asked for and what came back.', 'sendbeam' );
}

/**
 * Park the result where the screen can render it, and go back there.
 *
 * A transient rather than a query argument, because the failure card carries a
 * raw error, a version list and a domain state — none of which belongs in an
 * address bar, a server log or somebody's browser history. It is keyed to the
 * person who pressed the button and read exactly once.
 *
 * @param array $outcome What happened.
 */
function sendbeam_finish_test_mail( $outcome ) {
	set_transient( 'sendbeam_test_result_' . get_current_user_id(), $outcome, 5 * MINUTE_IN_SECONDS );
	wp_safe_redirect( sendbeam_tab_url( 'mail' ) . '#sendbeam-test' );
	exit;
}

/**
 * The result of the last test send, once.
 *
 * @return array|null
 */
function sendbeam_take_test_result() {
	$key    = 'sendbeam_test_result_' . get_current_user_id();
	$result = get_transient( $key );
	if ( ! is_array( $result ) ) {
		return null;
	}
	delete_transient( $key );
	return $result;
}

/**
 * One answer to "what does this site actually send as?".
 *
 * The Overview was capable of telling a site owner two true things that added
 * up to a lie: step 2 said the sending domain was verified and that email
 * "will be sent from your own domain", while the From address in the site's
 * own settings was still SendBeam's shared one. Both sentences were right.
 * Together they were wrong, and the owner had no way to see which.
 *
 * So there is one function that works out the address the next message would
 * go out as, and classifies it:
 *
 *   own_domain — on the verified sending domain, which is the point of all this
 *   shared     — on SendBeam's own host, which works but is not your domain
 *   other      — somewhere else, which SendBeam refuses
 *   none       — nothing to say yet
 *
 * @param array|null $status Normalised Connect status, fetched if omitted.
 * @return array{email:string,host:string,kind:string,domain:string,verified:bool}
 */
function sendbeam_effective_sender( $status = null ) {
	$settings = sendbeam_settings();
	if ( ! is_array( $status ) ) {
		$status = sendbeam_is_connected() ? sendbeam_connect_status() : sendbeam_connect_empty_status();
	}

	// What sendbeam_build_mail_body() would put in From: this site's own
	// setting, else the workspace sender it falls back to.
	$email = trim( (string) $settings['mail_from_email'] );
	if ( '' === $email ) {
		$email = trim( (string) $status['sender']['from_email'] );
	}

	$domain   = (string) $status['domain']['name'];
	$verified = ! empty( $status['domain']['verified'] );
	$host     = '';
	if ( '' !== $email && false !== strpos( $email, '@' ) ) {
		$host = strtolower( trim( substr( strrchr( $email, '@' ), 1 ) ) );
	}

	$kind = 'none';
	if ( '' !== $host ) {
		if ( '' !== $domain && sendbeam_host_is_within( $host, strtolower( $domain ) ) ) {
			$kind = 'own_domain';
		} elseif ( sendbeam_is_shared_sender_host( $host ) ) {
			$kind = 'shared';
		} else {
			$kind = 'other';
		}
	}

	return array(
		'email'    => $email,
		'host'     => $host,
		'kind'     => $kind,
		'domain'   => $domain,
		'verified' => $verified,
	);
}

/**
 * Is one host that host, or under it?
 *
 * @param string $host   Candidate.
 * @param string $parent The domain it might belong to.
 * @return bool
 */
function sendbeam_host_is_within( $host, $parent ) {
	if ( '' === $host || '' === $parent ) {
		return false;
	}
	if ( $host === $parent ) {
		return true;
	}
	$suffix = '.' . $parent;
	return substr( $host, -strlen( $suffix ) ) === $suffix;
}

/**
 * Is this address on SendBeam's own mail host rather than the site's domain?
 *
 * Derived from `sendbeam_app_url()` rather than hardcoded, so a self-hosted
 * SendBeam pointed at with the `sendbeam_app_url` filter classifies its own
 * shared addresses correctly. The two published hosts are kept as a fallback
 * for a site whose filter points somewhere else entirely.
 *
 * @param string $host The From address's host.
 * @return bool
 */
function sendbeam_is_shared_sender_host( $host ) {
	$app = (string) wp_parse_url( sendbeam_app_url(), PHP_URL_HOST );
	if ( '' !== $app && sendbeam_host_is_within( $host, strtolower( $app ) ) ) {
		return true;
	}
	foreach ( array( 'post.sendbeam.io', 'mail.sendbeam.io', 'sendbeam.io' ) as $known ) {
		if ( sendbeam_host_is_within( $host, $known ) ) {
			return true;
		}
	}
	return false;
}

/**
 * The address this site should be sending as, once its domain is verified.
 *
 * The workspace's own sender when that is already on the verified domain —
 * it is the address the account holder chose — and `hello@<domain>` when it
 * is not, which is what SendBeam sets up at consent time anyway.
 *
 * @param array $status Normalised Connect status.
 * @return string Empty when there is no verified domain to send from.
 */
function sendbeam_own_domain_sender( $status ) {
	$domain = strtolower( (string) $status['domain']['name'] );
	if ( '' === $domain || empty( $status['domain']['verified'] ) ) {
		return '';
	}

	$workspace = trim( (string) $status['sender']['from_email'] );
	if ( '' !== $workspace && false !== strpos( $workspace, '@' ) ) {
		$host = strtolower( trim( substr( strrchr( $workspace, '@' ), 1 ) ) );
		if ( sendbeam_host_is_within( $host, $domain ) ) {
			return $workspace;
		}
	}

	$candidate = 'hello@' . $domain;
	return is_email( $candidate ) ? $candidate : '';
}
