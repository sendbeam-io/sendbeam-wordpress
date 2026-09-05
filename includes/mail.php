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
	$out = array( 'content_type' => '', 'from' => '', 'reply_to' => '', 'cc' => array(), 'bcc' => array(), 'extra' => array() );
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
		$name  = strtolower( trim( $name ) );
		$value = trim( $value );
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
		return array( 'email' => trim( $m[2] ), 'name' => trim( $m[1] ) );
	}
	return array( 'email' => $value, 'name' => '' );
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
	$content_type = apply_filters( 'wp_mail_content_type', $content_type );

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
	$from       = $headers['from'] ? sendbeam_split_address( $headers['from'] ) : array( 'email' => '', 'name' => '' );
	$from_email = $settings['mail_from_email'] ? $settings['mail_from_email'] : $from['email'];
	$from_name  = $settings['mail_from_name'] ? $settings['mail_from_name'] : $from['name'];
	/** This filter is documented in wp-includes/pluggable.php */
	$from_email = apply_filters( 'wp_mail_from', $from_email );
	/** This filter is documented in wp-includes/pluggable.php */
	$from_name = apply_filters( 'wp_mail_from_name', $from_name );
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
		return array( 'ok' => false, 'error' => $response->get_error_message(), 'status' => 0 );
	}
	$status = (int) wp_remote_retrieve_response_code( $response );
	$data   = json_decode( (string) wp_remote_retrieve_body( $response ), true );
	if ( 200 === $status && ! empty( $data['ok'] ) ) {
		return array( 'ok' => true, 'error' => '', 'status' => 200 );
	}
	$error = is_array( $data ) && ! empty( $data['error'] ) ? (string) $data['error'] : 'HTTP ' . $status;
	return array( 'ok' => false, 'error' => $error, 'status' => $status );
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
	array_unshift( $log, array( 'at' => time(), 'to' => is_array( $to ) ? implode( ', ', $to ) : (string) $to, 'subject' => (string) $subject, 'result' => $result, 'note' => (string) $note ) );
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
	do_action( 'wp_mail_failed', new WP_Error( 'sendbeam_mail_failed', $result['error'], $atts ) );
	return false;
}

/**
 * "Send a test email" on the settings page.
 */
function sendbeam_handle_test_mail() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'sendbeam' ) );
	}
	check_admin_referer( 'sendbeam_test_mail' );
	$user = wp_get_current_user();
	$ok   = false;
	if ( ! sendbeam_mail_enabled() ) {
		$note = __( 'Turn on "Send this site\'s email through SendBeam" and save an API key first.', 'sendbeam' );
	} else {
		$result = sendbeam_api_send(
			array(
				'to'      => $user->user_email,
				'subject' => sprintf( /* translators: %s: site name */ __( 'Test email from %s via SendBeam', 'sendbeam' ), get_bloginfo( 'name' ) ),
				'text'    => __( "This is a test email from your WordPress site, sent through SendBeam.\n\nIf you can read this, order confirmations, password resets and every other email this site sends will go out the same way.", 'sendbeam' ),
			)
		);
		$ok   = $result['ok'];
		$note = $ok ? sprintf( /* translators: %s: email address */ __( 'Sent to %s.', 'sendbeam' ), $user->user_email ) : $result['error'];
		sendbeam_mail_log( $user->user_email, 'Test email', $ok ? 'sent' : 'failed', $ok ? '' : $result['error'] );
	}
	wp_safe_redirect( add_query_arg( array( 'page' => 'sendbeam', 'sendbeam_test' => $ok ? 'ok' : 'error', 'sendbeam_note' => rawurlencode( $note ) ), admin_url( 'options-general.php' ) ) );
	exit;
}
