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
 * Why site email is not running, in one word.
 *
 * The setting and the ability are two different things, and every screen that
 * conflated them said something untrue about the other. A site that
 * disconnects keeps `mail_enabled = 1` — which is right, because reconnecting
 * should not make the owner find the switch again — and with no key
 * sendbeam_pre_wp_mail() never short-circuits, so every message goes out the
 * server's own mailer exactly as it did before the plugin was installed.
 * Nothing is broken; the screens simply have to say which of the two is
 * missing rather than blaming the one that is not.
 *
 * @return string '' when it is running, 'off' when the box is unticked,
 *                'no_key' when it is ticked with no key to send with.
 */
function sendbeam_mail_blocked() {
	$settings = sendbeam_settings();
	if ( empty( $settings['mail_enabled'] ) ) {
		return 'off';
	}
	return '' === sendbeam_api_key() ? 'no_key' : '';
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
 * Remember the outcome.
 *
 * A thin wrapper over the table, kept because every caller in the plugin
 * already speaks this shape and because "what asked for this send" is worked
 * out here rather than at each of them.
 *
 * @param string|string[] $to      Recipient(s).
 * @param string          $subject Subject.
 * @param string          $result  'sent', 'fallback' or 'failed'.
 * @param string          $note    Error or reason.
 * @param string          $source  What asked for it; worked out when omitted.
 */
function sendbeam_mail_log( $to, $subject, $result, $note = '', $source = null ) {
	sendbeam_mail_log_insert( $to, $subject, $result, $note, null === $source ? sendbeam_mail_source() : $source );
}

/**
 * Which plugin or part of WordPress asked for this message.
 *
 * Worth knowing and impossible to ask for directly: `wp_mail()` takes no
 * caller. The backtrace is the only place the answer exists, and it is read
 * once per send, only for the log, and never for a decision.
 *
 * The sentinel for "core itself" is `wp-core` rather than the product's name,
 * because the coding standard's own auto-fixer rewrites that word wherever it
 * finds it — including inside a string that is a stored value, which turns a
 * sentinel the screen matches on into one it does not.
 *
 * @return string A plugin folder name, 'wp-core', or '' when it cannot be told.
 */
function sendbeam_mail_source() {
	if ( ! function_exists( 'debug_backtrace' ) ) {
		return '';
	}
	// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- the only way to name the caller of wp_mail(), which takes none; used for one log column and never for a decision.
	$frames  = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 12 );
	$plugins = defined( 'WP_PLUGIN_DIR' ) ? wp_normalize_path( WP_PLUGIN_DIR ) : '';

	foreach ( (array) $frames as $frame ) {
		if ( empty( $frame['file'] ) ) {
			continue;
		}
		$file = wp_normalize_path( $frame['file'] );
		if ( '' !== $plugins && 0 === strpos( $file, $plugins . '/' ) ) {
			$rest = substr( $file, strlen( $plugins ) + 1 );
			$slug = strtok( $rest, '/' );
			// This plugin asking on somebody else's behalf is not an answer.
			if ( 'sendbeam' !== $slug && false !== $slug ) {
				return (string) $slug;
			}
		}
	}

	return 'wp-core';
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
 * Gathered once and used three times: the body of the test email, the note on
 * the in-page result, and — when the send fails — the bundle the failure card
 * offers to copy. Collecting it at the moment of the send rather than at
 * render time is the whole point: a support request that describes the state
 * of the site half an hour later describes a different site.
 *
 * Every row that can mislead is qualified. "From address
 * ws-…@post.sendbeam.io" two rows above "Sending domain harbourlane.co.uk —
 * verified" told a reader their domain was verified *and in use* when only
 * the first half was true, under a headline saying the site could send.
 *
 * @param array|null $status Normalised Connect status, fetched if omitted.
 * @return array<string,string>
 */
function sendbeam_mail_facts( $status = null ) {
	$settings = sendbeam_settings();
	if ( ! is_array( $status ) ) {
		$status = sendbeam_is_connected() ? sendbeam_connect_status() : sendbeam_connect_empty_status();
	}
	$sender = sendbeam_effective_sender( $status );
	$domain = $status['domain'];

	$from_name = '' !== (string) $settings['mail_from_name'] ? (string) $settings['mail_from_name'] : (string) $status['sender']['from_name'];

	$rows = array(
		__( 'Site', 'sendbeam' ) => get_bloginfo( 'name' ) . ' — ' . home_url(),
	);

	if ( 'none' === $sender['kind'] ) {
		$rows[ __( 'From address', 'sendbeam' ) ] = __( 'Your workspace sender — this site has not set one of its own', 'sendbeam' );
	} else {
		$rows[ __( 'From name', 'sendbeam' ) ]    = '' !== $from_name ? $from_name : __( 'the workspace sender', 'sendbeam' );
		$rows[ __( 'From address', 'sendbeam' ) ] = sendbeam_from_address_row( $sender );
		// So a reader can compare this with the sending-domain row at a
		// glance: this is the domain that actually authenticated the message,
		// which is not the one the site asked for when SendBeam substituted
		// its own sender. Telling somebody their domain signed a message it
		// took no part in is the one thing a test email must never do.
		$rows[ __( 'Signed by', 'sendbeam' ) ] = '' !== $sender['signed'] ? $sender['signed'] : __( 'not reported', 'sendbeam' );
	}

	$rows[ __( 'Sending domain', 'sendbeam' ) ] = sendbeam_sending_domain_row( $sender, $domain );
	$rows[ __( 'Workspace', 'sendbeam' ) ]      = '' !== (string) $status['workspace']['name'] ? (string) $status['workspace']['name'] : __( 'not reported', 'sendbeam' );
	$rows[ __( 'Sent', 'sendbeam' ) ]           = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
	$rows[ __( 'Plugin', 'sendbeam' ) ]         = 'SendBeam ' . SENDBEAM_VERSION;

	return $rows;
}

/**
 * The From address, and what kind of address it is.
 *
 * @param array $sender From sendbeam_effective_sender().
 * @return string
 */
function sendbeam_from_address_row( $sender ) {
	if ( 'own_domain' === $sender['kind'] ) {
		return $sender['email'];
	}
	if ( 'shared' === $sender['kind'] ) {
		/* translators: %s: the From address */
		return sprintf( __( '%s — SendBeam\'s shared address, not your domain', 'sendbeam' ), $sender['email'] );
	}
	if ( 'other' === $sender['kind'] ) {
		if ( ! empty( $sender['substituted'] ) ) {
			return sprintf(
				/* translators: 1: the From address this site asked for, 2: its domain, 3: the address SendBeam used instead */
				__( '%1$s was asked for, but %2$s is not a domain verified in this workspace, so SendBeam sent this as %3$s', 'sendbeam' ),
				$sender['email'],
				$sender['host'],
				$sender['used']
			);
		}
		return sprintf(
			/* translators: 1: the From address, 2: its domain */
			__( '%1$s — %2$s is not a domain verified in this workspace', 'sendbeam' ),
			$sender['email'],
			$sender['host']
		);
	}
	return __( 'the workspace sender', 'sendbeam' );
}

/**
 * The sending domain, and whether this message actually used it.
 *
 * @param array $sender From sendbeam_effective_sender().
 * @param array $domain The domain from the status body.
 * @return string
 */
function sendbeam_sending_domain_row( $sender, $domain ) {
	$name = (string) $domain['name'];
	if ( '' === $name ) {
		return __( 'None set up', 'sendbeam' );
	}
	if ( empty( $domain['verified'] ) ) {
		/* translators: %s: the sending domain */
		return sprintf( __( '%s — not verified yet', 'sendbeam' ), $name );
	}
	if ( 'own_domain' === $sender['kind'] ) {
		/* translators: %s: the sending domain */
		return sprintf( __( '%s — verified and in use', 'sendbeam' ), $name );
	}
	/* translators: %s: the sending domain */
	return sprintf( __( '%s — verified, but not in use yet', 'sendbeam' ), $name );
}

/**
 * The one line at the top of the test email.
 *
 * @param array $sender From sendbeam_effective_sender().
 * @return string
 */
function sendbeam_test_mail_headline( $sender ) {
	if ( 'own_domain' === $sender['kind'] ) {
		return sprintf(
			/* translators: %s: the sending domain */
			__( 'Your site can send email through SendBeam from %s', 'sendbeam' ),
			$sender['host']
		);
	}
	if ( 'shared' === $sender['kind'] ) {
		return __( 'Your site can send email through SendBeam (from the shared address for now)', 'sendbeam' );
	}
	return __( 'Your site can send email through SendBeam', 'sendbeam' );
}

/**
 * What to do next, when there is something.
 *
 * A message that arrives is not the same as a message that arrives from you,
 * and this is the difference between the two said in one block.
 *
 * @param array      $sender From sendbeam_effective_sender().
 * @param array|null $status Normalised Connect status, for the domain.
 * @return array{title:string,text:string,label:string,url:string}|null
 */
function sendbeam_test_mail_next_step( $sender, $status = null ) {
	if ( 'own_domain' === $sender['kind'] || 'none' === $sender['kind'] ) {
		return null;
	}

	if ( 'other' === $sender['kind'] ) {
		/*
		 * Not "SendBeam refuses messages from it". It does not: it accepts
		 * them and sends them under the workspace's own address, which is
		 * what the headers of this very message show. Saying otherwise in a
		 * message that has just arrived is the plugin contradicting the
		 * thing the reader is holding.
		 */
		return array(
			'title' => __( 'Next step', 'sendbeam' ),
			'text'  => sprintf(
				/* translators: 1: the From address's domain, 2: the address SendBeam used instead */
				__( '%1$s is not a domain this workspace has verified, so SendBeam sent this under its own address, %2$s, rather than yours. Change the From address to one on your verified sending domain, or verify that domain.', 'sendbeam' ),
				$sender['host'],
				'' !== $sender['used'] ? $sender['used'] : __( 'the workspace sender', 'sendbeam' )
			),
			'label' => __( 'Open Site email', 'sendbeam' ),
			'url'   => sendbeam_page_url( 'sendbeam-mail' ),
		);
	}

	if ( ! empty( $sender['verified'] ) ) {
		return array(
			'title' => __( 'Next step', 'sendbeam' ),
			'text'  => sprintf(
				/* translators: %s: the verified sending domain */
				__( 'Send from your own domain: open Site email in wp-admin and press Send from %s. Mail from your own domain is what mailbox providers trust.', 'sendbeam' ),
				$sender['domain']
			),
			'label' => __( 'Open Site email', 'sendbeam' ),
			'url'   => sendbeam_page_url( 'sendbeam-mail' ),
		);
	}

	return array(
		'title' => __( 'Next step', 'sendbeam' ),
		'text'  => __( 'Add the DNS records under Settings → Sending domain so SendBeam can send as you. Until then mail goes out from the shared address on your behalf.', 'sendbeam' ),
		'label' => __( 'Open the sending domain', 'sendbeam' ),
		'url'   => add_query_arg( 'tab', 'domain', sendbeam_page_url( 'sendbeam-settings' ) ),
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
 * Dark mode is declared rather than left to a client's guess. Apple Mail
 * inverts a message that says nothing, which turns the ink band into paper,
 * the green check into something unreadable, and every address in the table
 * into a blue auto-detected link.
 *
 * @param array      $facts  Rows from sendbeam_mail_facts().
 * @param array|null $sender From sendbeam_effective_sender(); derived if omitted.
 * @param array|null $status Normalised Connect status.
 * @return string
 */
function sendbeam_test_mail_html( $facts, $sender = null, $status = null ) {
	if ( ! is_array( $sender ) ) {
		$sender = sendbeam_effective_sender( $status );
	}
	$next = sendbeam_test_mail_next_step( $sender, $status );

	$ink   = '#121212';
	$paper = '#EDEBE6';
	$rule  = '#e2e0db';
	$muted = '#5c5c5c';

	$rows = '';
	foreach ( $facts as $label => $value ) {
		$rows .= '<tr>'
			. '<td class="sb-k" width="25%" bgcolor="#ffffff" style="padding:8px 0;border-bottom:1px solid ' . $rule . ';font-size:13px;color:' . $muted . ';vertical-align:top;background-color:#ffffff;">' . esc_html( $label ) . '</td>'
			. '<td class="sb-v" bgcolor="#ffffff" style="padding:8px 0 8px 14px;border-bottom:1px solid ' . $rule . ';font-size:13px;color:' . $ink . ';vertical-align:top;word-break:break-word;overflow-wrap:break-word;background-color:#ffffff;">' . esc_html( $value ) . '</td>'
			. '</tr>';
	}

	$step = '';
	if ( is_array( $next ) ) {
		$step = '<tr><td class="sb-pad" bgcolor="#FDF6E7" style="padding:20px 28px;background-color:#FDF6E7;border-top:1px solid ' . $ink . ';border-bottom:1px solid ' . $ink . ';">'
			. '<p style="margin:0 0 8px;font-size:15px;font-weight:700;color:' . $ink . ';">' . esc_html( $next['title'] ) . '</p>'
			. '<p style="margin:0 0 16px;font-size:14px;line-height:1.5;color:' . $ink . ';">' . esc_html( $next['text'] ) . '</p>'
			. '<p style="margin:0;"><a href="' . esc_url( $next['url'] ) . '" style="display:inline-block;padding:11px 18px;background-color:#B26B12;color:#ffffff;font-size:14px;font-weight:700;text-decoration:none;">'
			. esc_html( $next['label'] ) . '</a></p>'
			. '</td></tr>';
	}

	$style = '
		:root{color-scheme:light dark;supported-color-schemes:light dark}
		/* Apple Mail turns an address or a domain into a blue link of its own
		   accord; this puts the table\'s own colour back. */
		a[x-apple-data-detectors]{color:inherit!important;text-decoration:none!important;
			font-size:inherit!important;font-family:inherit!important;font-weight:inherit!important;line-height:inherit!important}
		@media only screen and (max-width:620px){.sb-container{width:100%!important}.sb-pad{padding:20px!important}}
		/* Below 480px the label above its value, because a quarter of 380px is
		   not enough for "Sending domain" and the URL wrapped mid-word. */
		@media only screen and (max-width:480px){
			.sb-k,.sb-v{display:block!important;width:100%!important;border-bottom:0!important;padding-left:0!important}
			.sb-k{padding-bottom:2px!important}
			.sb-v{padding-top:0!important;padding-bottom:10px!important;border-bottom:1px solid ' . $rule . '!important}
		}';

	$html = '<!doctype html><html><head><meta charset="utf-8" />'
		. '<meta name="viewport" content="width=device-width" />'
		. '<meta name="color-scheme" content="light dark" />'
		. '<meta name="supported-color-schemes" content="light dark" />'
		. '<title>' . esc_html__( 'SendBeam test email', 'sendbeam' ) . '</title>'
		. '<style>' . $style . '</style>'
		. '</head><body bgcolor="' . $paper . '" style="margin:0;padding:0;background-color:' . $paper . ';color:' . $ink . ';'
		. 'font-family:-apple-system,BlinkMacSystemFont,&quot;Segoe UI&quot;,Roboto,Helvetica,Arial,sans-serif;">'
		. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="' . $paper . '" style="background-color:' . $paper . ';"><tr><td align="center" style="padding:24px 12px;">'
		. '<table role="presentation" class="sb-container" width="600" cellpadding="0" cellspacing="0" border="0" bgcolor="#ffffff" style="width:600px;max-width:600px;background-color:#ffffff;border:1px solid ' . $ink . ';">'

		// The mark, on the ink band, as three coloured cells.
		. '<tr><td class="sb-pad" bgcolor="' . $ink . '" style="padding:24px 28px;background-color:' . $ink . ';">'
		. '<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>'
		. '<td width="14" height="14" bgcolor="#E2442A" style="background-color:#E2442A;font-size:0;line-height:0;">&nbsp;</td>'
		. '<td width="6" style="font-size:0;line-height:0;">&nbsp;</td>'
		. '<td width="14" height="14" bgcolor="#1F3FBF" style="background-color:#1F3FBF;font-size:0;line-height:0;">&nbsp;</td>'
		. '<td width="6" style="font-size:0;line-height:0;">&nbsp;</td>'
		. '<td width="14" height="14" bgcolor="#3B7D46" style="background-color:#3B7D46;font-size:0;line-height:0;">&nbsp;</td>'
		. '<td style="padding-left:14px;color:' . $paper . ';font-size:17px;font-weight:700;">SendBeam</td>'
		. '</tr></table></td></tr>'

		// The verdict.
		. '<tr><td class="sb-pad" bgcolor="#ffffff" style="padding:24px 28px 4px;background-color:#ffffff;">'
		// The tick keeps its 26x26 square: in a two-cell row it stretched to
		// the height of a headline that wraps to three lines on a phone.
		. '<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>'
		. '<td width="26" valign="top" style="width:26px;">'
		. '<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>'
		. '<td width="26" height="26" align="center" bgcolor="#3B7D46" style="width:26px;height:26px;background-color:#3B7D46;color:#ffffff;font-size:15px;line-height:26px;">&#10003;</td>'
		. '</tr></table></td>'
		. '<td valign="top" style="padding-left:12px;font-size:19px;font-weight:700;line-height:1.3;color:' . $ink . ';">' . esc_html( sendbeam_test_mail_headline( $sender ) ) . '</td>'
		. '</tr></table>'
		. '<p style="margin:14px 0 0;font-size:14px;line-height:1.5;color:' . $ink . ';">'
		. esc_html__( 'This message was sent by your WordPress site, through SendBeam, using the settings below.', 'sendbeam' )
		. '</p></td></tr>'

		// The facts.
		. '<tr><td class="sb-pad" bgcolor="#ffffff" style="padding:14px 28px;background-color:#ffffff;">'
		. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">' . $rows . '</table>'
		. '</td></tr>'

		. $step

		// What happens now.
		. '<tr><td class="sb-pad" bgcolor="' . $paper . '" style="padding:20px 28px;background-color:' . $paper . ';' . ( '' === $step ? 'border-top:1px solid ' . $ink . ';' : '' ) . '">'
		. '<p style="margin:0 0 8px;font-size:15px;font-weight:700;color:' . $ink . ';">' . esc_html__( 'What happens now', 'sendbeam' ) . '</p>'
		. '<p style="margin:0 0 8px;font-size:14px;line-height:1.5;color:' . $ink . ';">'
		. esc_html__( 'Every email this site already sends goes out this way from now on: order confirmations, password resets, and comment and form notifications.', 'sendbeam' )
		. '</p>'
		. '<p style="margin:0 0 16px;font-size:14px;line-height:1.5;color:' . $ink . ';">'
		. esc_html__( 'Each one is recorded in the email log in wp-admin, with whether it was sent, fell back to the server\'s own mailer, or failed.', 'sendbeam' )
		. '</p>'
		. '<p style="margin:0;"><a href="' . esc_url( sendbeam_page_url( 'sendbeam-mail' ) ) . '" style="display:inline-block;padding:11px 18px;background-color:#2271b1;color:#ffffff;font-size:14px;font-weight:700;text-decoration:none;">'
		. esc_html__( 'Open the email log', 'sendbeam' ) . '</a></p>'
		. '</td></tr>'

		. '</table></td></tr></table></body></html>';

	return $html;
}

/**
 * The same thing in words, for a client that will not render the HTML.
 *
 * @param array      $facts  Rows from sendbeam_mail_facts().
 * @param array|null $sender From sendbeam_effective_sender(); derived if omitted.
 * @param array|null $status Normalised Connect status.
 * @return string
 */
function sendbeam_test_mail_text( $facts, $sender = null, $status = null ) {
	if ( ! is_array( $sender ) ) {
		$sender = sendbeam_effective_sender( $status );
	}
	$next = sendbeam_test_mail_next_step( $sender, $status );

	$lines = array(
		sendbeam_test_mail_headline( $sender ),
		'',
		__( 'This message was sent by your WordPress site, through SendBeam, using the settings below.', 'sendbeam' ),
		'',
	);
	foreach ( $facts as $label => $value ) {
		$lines[] = $label . ': ' . $value;
	}

	if ( is_array( $next ) ) {
		$lines[] = '';
		$lines[] = $next['title'];
		$lines[] = $next['text'];
		$lines[] = $next['label'] . ': ' . $next['url'];
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
		wp_die( esc_html__( 'You do not have permission to do that.', 'sendbeam' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'sendbeam_test_mail' );

	$user   = wp_get_current_user();
	$to     = (string) $user->user_email;
	$status = sendbeam_is_connected() ? sendbeam_connect_status() : sendbeam_connect_empty_status();
	$sender = sendbeam_effective_sender( $status );
	$facts  = sendbeam_mail_facts( $status );

	$blocked = sendbeam_mail_blocked();

	// Two different reasons, and telling somebody to tick a box they have
	// just ticked is a loop with no way out of it.
	if ( 'no_key' === $blocked ) {
		sendbeam_finish_test_mail(
			array(
				'ok'     => false,
				'title'  => __( 'This site is not connected to SendBeam', 'sendbeam' ),
				'means'  => __( 'Site email is switched on, but there is no API key to send with, so this site is still handing its email to the web server\'s own mailer. Nothing was sent.', 'sendbeam' ),
				'do'     => __( 'Connect the site on the Overview, or paste a key under Settings → Advanced. The key needs the "Send site email" permission.', 'sendbeam' ),
				'to'     => $to,
				'bundle' => sendbeam_mail_error_bundle( 'no API key is saved', 0 ),
				'at'     => time(),
			)
		);
	}

	if ( 'off' === $blocked ) {
		sendbeam_finish_test_mail(
			array(
				'ok'     => false,
				'title'  => __( 'Site email is not switched on yet', 'sendbeam' ),
				'means'  => __( 'Nothing was sent, because this site is still handing its email to the web server\'s own mailer.', 'sendbeam' ),
				'do'     => __( 'Tick "Send this site\'s email through SendBeam" above, save, and try again. It needs a working API key with the "Send site email" permission.', 'sendbeam' ),
				'to'     => $to,
				'bundle' => sendbeam_mail_error_bundle( 'site email is switched off', 0 ),
				'at'     => time(),
			)
		);
	}

	$result = sendbeam_api_send(
		array(
			'to'      => $to,
			'subject' => sprintf( /* translators: %s: site name */ __( 'Test email from %s via SendBeam', 'sendbeam' ), get_bloginfo( 'name' ) ),
			'html'    => sendbeam_test_mail_html( $facts, $sender, $status ),
			'text'    => sendbeam_test_mail_text( $facts, $sender, $status ),
		)
	);

	sendbeam_mail_log( $to, __( 'Test email', 'sendbeam' ), $result['ok'] ? 'sent' : 'failed', $result['ok'] ? '' : $result['error'] );

	if ( $result['ok'] ) {
		/*
		 * Arrived is not the same as arrived from you. A message sent from
		 * SendBeam's shared address while the site's own domain sits verified
		 * and unused is a success with something left to do, and the card has
		 * to say which — WP Mail SMTP words the same case as "might have
		 * sent, but its deliverability should be improved".
		 */
		sendbeam_finish_test_mail(
			array(
				'ok'     => true,
				'to'     => $to,
				'at'     => time(),
				'sender' => $sender['kind'],
				'next'   => sendbeam_test_mail_next_step( $sender, $status ),
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
 *   other      — somewhere else, which SendBeam sends under its own address
 *   none       — nothing to say yet
 *
 * `kind` is what the site asked for. `used`, `signed` and `substituted`
 * are what happens to the message — see the note beside them.
 *
 * @param array|null $status Normalised Connect status, fetched if omitted.
 * @return array{email:string,host:string,kind:string,domain:string,verified:bool,used:string,signed:string,substituted:bool}
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

	/*
	 * The site's own verified domain settles it first and settles it for
	 * good, whatever that domain happens to be a subdomain of. A customer
	 * sending from a `*.sendbeam.io` domain they have verified is sending
	 * from their own domain, not from SendBeam's shared address.
	 */
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

	/*
	 * What the site asked for is not always what leaves the building.
	 * SendBeam does not refuse a message whose From sits on a domain the
	 * workspace has not verified — it accepts it and substitutes the
	 * workspace's own sender, which is what the received headers of a test
	 * email show: From ws-…@mail.sendbeam.io, DKIM d=mail.sendbeam.io. So
	 * the address a reader will see, and the domain that will actually sign
	 * the message, are worked out here too. The requested address is used
	 * only when it is on the sending domain SendBeam has verified.
	 */
	$workspace = trim( (string) $status['sender']['from_email'] );
	$as_asked  = ( 'own_domain' === $kind && $verified ) || 'shared' === $kind;
	$used      = ( $as_asked || '' === $workspace ) ? $email : $workspace;
	$signed    = '';
	if ( '' !== $used && false !== strpos( $used, '@' ) ) {
		$signed = strtolower( trim( substr( strrchr( $used, '@' ), 1 ) ) );
	}

	return array(
		'email'       => $email,
		'host'        => $host,
		'kind'        => $kind,
		'domain'      => $domain,
		'verified'    => $verified,
		// The address the message actually goes out as, the domain that
		// signs it, and whether those differ from what was asked for.
		'used'        => $used,
		'signed'      => $signed,
		'substituted' => ( '' !== $used && $used !== $email ),
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
 * The platform's shared sending hosts, and only those.
 *
 * SendBeam sends a workspace's default mail from `post.<app host>`. An
 * address there is the shared one: it works, and it is not the customer's
 * domain, which is the distinction the Overview and the test email are built
 * on.
 *
 * Derived from `sendbeam_app_url()` so a self-hosted SendBeam pointed at with
 * the `sendbeam_app_url` filter recognises its own, with the published pair
 * kept as a fallback for a filter pointing somewhere else entirely.
 *
 * @return string[] Lower-case hosts.
 */
function sendbeam_shared_sender_hosts() {
	$hosts = array( 'post.sendbeam.io', 'mail.sendbeam.io' );

	$app = strtolower( (string) wp_parse_url( sendbeam_app_url(), PHP_URL_HOST ) );
	if ( '' !== $app ) {
		$hosts[] = 'post.' . $app;
		$hosts[] = 'mail.' . $app;
	}

	return array_values( array_unique( $hosts ) );
}

/**
 * Is this address on one of those hosts?
 *
 * An exact match, never "somewhere under the app's domain". A customer whose
 * site is a subdomain of SendBeam's own — which every site on the staging
 * host is, and which any customer using a `*.sendbeam.io` address would be —
 * was being told that their own verified domain was SendBeam's shared
 * address. `post.` and `mail.` are the two hosts the platform actually sends
 * shared mail from; nothing else under that domain is shared, and a domain
 * that is the site's own verified sending domain is never reached by this at
 * all, because the caller settles that first.
 *
 * @param string $host The From address's host.
 * @return bool
 */
function sendbeam_is_shared_sender_host( $host ) {
	return in_array( strtolower( (string) $host ), sendbeam_shared_sender_hosts(), true );
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
