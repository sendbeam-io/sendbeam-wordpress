<?php
/**
 * What SendBeam knows about this site, once it is connected.
 *
 * Connect hands the site a key and, in the same breath, sets up the things a
 * site owner needs before SendBeam is any use: a sending domain, a list, a
 * signup form, a sender name. None of that is worth anything if the plugin
 * cannot then *show* it — a page of DNS records the owner has to go and find
 * on sendbeam.io is the same friction Connect exists to remove.
 *
 * So this file is the read side of the connection:
 *
 *   - `sendbeam_connect_status()` asks `/api/v1/connect/status` with the
 *     site's own key and caches the answer for a minute, because the Overview
 *     renders it on every load and a slow API must never make wp-admin slow;
 *   - a **Check now** button posts the same endpoint with `check_domain`,
 *     which makes SendBeam look the records up right now;
 *   - **Switch on** turns the wp_mail() relay on, but only once the domain is
 *     actually verified;
 *   - **Disconnect** revokes the key at SendBeam and then forgets it here.
 *
 * Everything arriving from the API is treated as untrusted text: it is
 * normalised into a fixed shape by sendbeam_connect_normalise_status() before
 * anything reads it, so no screen has to test whether a key exists, and a
 * malformed reply renders as "nothing yet" rather than a warning.
 *
 * @package SendBeam
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_post_sendbeam_domain_check', 'sendbeam_handle_domain_check' );
add_action( 'admin_post_sendbeam_mail_switch_on', 'sendbeam_handle_mail_switch_on' );

/** Where the status lives between renders. */
const SENDBEAM_CACHE_STATUS = 'sendbeam_connect_status';

/**
 * One minute.
 *
 * Long enough that clicking around the Overview does not make a request per
 * click; short enough that someone who has just added a DNS record and
 * pressed **Check now** — which bypasses this entirely — is not then told
 * something stale for the next five minutes.
 */
const SENDBEAM_STATUS_TTL = 60;

/**
 * An empty status: every key present, nothing claimed.
 *
 * Callers index into this without checking, which is the point. A status that
 * could be half-missing would put an isset() in front of every value on the
 * Overview, and the one that got forgotten is the one that emits a warning on
 * a live site.
 *
 * @return array<string,mixed>
 */
function sendbeam_connect_empty_status() {
	return array(
		'ok'           => false,
		'error'        => '',
		'workspace'    => array(
			'id'   => '',
			'name' => '',
		),
		'default_form' => array(
			'id'   => '',
			'name' => '',
		),
		'domain'       => array(
			'name'               => '',
			'verified'           => false,
			'records'            => array(),
			'domain_connect_url' => '',
			'checked_at'         => '',
		),
		'sender'       => array(
			'from_name'  => '',
			'from_email' => '',
		),
	);
}

/**
 * Turn an exchange or status reply into that fixed shape.
 *
 * The contract allows `default_form` and `domain` to be null — no form yet,
 * or the key was never given `domains:read` — and null is not a thing a
 * template can read a name out of. Absence becomes an empty string here, once,
 * rather than a null check at every point of use.
 *
 * @param mixed $data Decoded JSON body.
 * @return array<string,mixed>
 */
function sendbeam_connect_normalise_status( $data ) {
	$out = sendbeam_connect_empty_status();
	if ( ! is_array( $data ) ) {
		return $out;
	}

	$out['ok'] = true;

	if ( isset( $data['workspace'] ) && is_array( $data['workspace'] ) ) {
		$out['workspace']['id']   = isset( $data['workspace']['id'] ) ? sanitize_text_field( (string) $data['workspace']['id'] ) : '';
		$out['workspace']['name'] = isset( $data['workspace']['name'] ) ? sanitize_text_field( (string) $data['workspace']['name'] ) : '';
	}

	// What SendBeam could not set up, in its words, so step 2 can say why.
	$out['notes'] = array();
	if ( isset( $data['notes'] ) && is_array( $data['notes'] ) ) {
		foreach ( array_slice( $data['notes'], 0, 3 ) as $note ) {
			if ( is_string( $note ) && '' !== trim( $note ) ) {
				$out['notes'][] = mb_substr( sanitize_text_field( $note ), 0, 200 );
			}
		}
	}

	// A form ID that is not a form ID is dropped rather than stored: it would
	// become this site's default form and every embed would 404.
	if ( isset( $data['default_form'] ) && is_array( $data['default_form'] ) && sendbeam_is_form_id( isset( $data['default_form']['id'] ) ? $data['default_form']['id'] : null ) ) {
		$out['default_form']['id']   = strtolower( (string) $data['default_form']['id'] );
		$out['default_form']['name'] = isset( $data['default_form']['name'] ) ? sanitize_text_field( (string) $data['default_form']['name'] ) : '';
	}

	if ( isset( $data['domain'] ) && is_array( $data['domain'] ) && ! empty( $data['domain']['name'] ) ) {
		$out['domain']['name']       = sanitize_text_field( (string) $data['domain']['name'] );
		$out['domain']['verified']   = ! empty( $data['domain']['verified'] );
		$out['domain']['checked_at'] = isset( $data['domain']['checked_at'] ) ? sanitize_text_field( (string) $data['domain']['checked_at'] ) : '';
		$out['domain']['records']    = sendbeam_connect_clean_records( isset( $data['domain']['records'] ) ? $data['domain']['records'] : array() );

		/*
		 * The registrar's one-click DNS link. It is put in front of the site
		 * owner as a button, so it is held to the same rule as every other
		 * off-site link this plugin renders: https, and SendBeam's own host.
		 * A "set up my DNS" button pointing anywhere else is a phishing link
		 * with the plugin's face on it.
		 */
		$url = isset( $data['domain']['domain_connect_url'] ) ? esc_url_raw( (string) $data['domain']['domain_connect_url'] ) : '';
		if ( '' !== $url && sendbeam_connect_is_app_url( $url ) ) {
			$out['domain']['domain_connect_url'] = $url;
		}
	}

	if ( isset( $data['sender'] ) && is_array( $data['sender'] ) ) {
		$out['sender']['from_name']  = isset( $data['sender']['from_name'] ) ? sanitize_text_field( (string) $data['sender']['from_name'] ) : '';
		$email                       = isset( $data['sender']['from_email'] ) ? sanitize_email( (string) $data['sender']['from_email'] ) : '';
		$out['sender']['from_email'] = ( $email && is_email( $email ) ) ? $email : '';
	}

	return $out;
}

/**
 * Is this an https URL on the SendBeam app's own host?
 *
 * @param string $url Candidate URL.
 * @return bool
 */
function sendbeam_connect_is_app_url( $url ) {
	$parts = wp_parse_url( (string) $url );
	$app   = wp_parse_url( sendbeam_app_url() );
	if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) || empty( $app['host'] ) ) {
		return false;
	}
	if ( 'https' !== strtolower( $parts['scheme'] ) ) {
		return false;
	}
	return strtolower( $parts['host'] ) === strtolower( $app['host'] );
}

/**
 * The DNS records, cleaned for display.
 *
 * Nothing is dropped for being an unfamiliar record type — a record the site
 * owner has to enter and cannot see is worse than an odd-looking row — but
 * the type is reduced to the letters and digits a DNS type can contain, and
 * every field is plain text. Twenty is far more than any sending domain needs
 * and stops a malformed reply filling the screen.
 *
 * `found` is the check's verdict for that one record, and it has three
 * values rather than two: true, false, and never-checked. They are three
 * different sentences to a site owner — "this one is live", "this one is not
 * there yet", "nobody has looked" — and collapsing the last two into false
 * tells someone their DNS is wrong when in fact nothing has been asked.
 *
 * @param mixed $raw Records from the API.
 * @return array<int,array{type:string,name:string,value:string,found:?bool}>
 */
function sendbeam_connect_clean_records( $raw ) {
	if ( ! is_array( $raw ) ) {
		return array();
	}
	$out = array();
	foreach ( $raw as $record ) {
		if ( ! is_array( $record ) || count( $out ) >= 20 ) {
			continue;
		}
		$type = isset( $record['type'] ) ? strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) $record['type'] ) ) : '';
		$name = isset( $record['name'] ) ? sanitize_text_field( (string) $record['name'] ) : '';
		$val  = isset( $record['value'] ) ? sanitize_text_field( (string) $record['value'] ) : '';
		if ( '' === $type || '' === $val ) {
			continue;
		}
		$found = null;
		if ( array_key_exists( 'found', $record ) && null !== $record['found'] ) {
			$found = (bool) $record['found'];
		}
		$out[] = array(
			'type'  => substr( $type, 0, 10 ),
			'name'  => $name,
			'value' => $val,
			'found' => $found,
		);
	}
	return $out;
}

/**
 * Remember a status for a minute.
 *
 * @param array $status Normalised status.
 */
function sendbeam_connect_cache_status( $status ) {
	set_transient( SENDBEAM_CACHE_STATUS, $status, SENDBEAM_STATUS_TTL );
}

/** Forget it — after a disconnect, or when the key changes. */
function sendbeam_connect_forget_status() {
	delete_transient( SENDBEAM_CACHE_STATUS );
}

/**
 * The connection's status: workspace, default form, sending domain, sender.
 *
 * @param bool $check True to ask SendBeam to re-check the domain's DNS now.
 *                    That is a POST, it is rate-limited at the other end, and
 *                    it never reads the cache.
 * @return array<string,mixed> Always the full shape; `ok` says whether the
 *                             values came from SendBeam or are just empties.
 */
function sendbeam_connect_status( $check = false ) {
	if ( '' === sendbeam_api_key() ) {
		return sendbeam_connect_empty_status();
	}

	if ( ! $check ) {
		$cached = get_transient( SENDBEAM_CACHE_STATUS );
		if ( is_array( $cached ) && isset( $cached['domain'] ) ) {
			return $cached;
		}
	}

	$result = $check
		? sendbeam_api_post( '/api/v1/connect/status', array( 'check_domain' => true ) )
		: sendbeam_api_get( '/api/v1/connect/status' );

	if ( ! $result['ok'] ) {
		$status = sendbeam_connect_empty_status();
		if ( 429 === $result['status'] ) {
			$status['error'] = __( 'SendBeam is rate-limiting this site. Wait a few minutes and try again.', 'sendbeam' );
		} elseif ( 401 === $result['status'] || 403 === $result['status'] ) {
			$status['error'] = __( 'SendBeam would not answer with this site\'s key. Reconnect the site.', 'sendbeam' );
		} else {
			$status['error'] = __( 'Could not read this site\'s set-up from SendBeam just now.', 'sendbeam' );
		}
		// Cached like any other answer: a site whose key has been revoked must
		// not make a failing request on every single wp-admin page load.
		sendbeam_connect_cache_status( $status );
		return $status;
	}

	/*
	 * A 200 carrying nothing usable — an empty body, HTML from a proxy, a
	 * reply that did not parse — is a failure, not an answer. Normalising it
	 * would give every field an empty value and cache that for a minute, and
	 * the Overview would lose the DNS records someone was halfway through
	 * copying.
	 */
	if ( empty( $result['data'] ) ) {
		$status          = sendbeam_connect_empty_status();
		$status['error'] = __( 'SendBeam answered with nothing this site could read.', 'sendbeam' );
		sendbeam_connect_cache_status( $status );
		return $status;
	}

	$status = sendbeam_connect_normalise_status( $result['data'] );
	sendbeam_connect_cache_status( $status );
	return $status;
}

/**
 * **Check now**: ask SendBeam to look the DNS up again, then come back.
 *
 * If the domain turns out to be verified and site email was approved at
 * consent time but held back for exactly this reason, it is switched on here.
 * That is finishing a job the site owner already said yes to — it is not the
 * same as switching on something they turned off, which is why it happens
 * only while `sendbeam_mail_deferred` is set and why turning site email off by
 * hand clears that flag.
 */
function sendbeam_handle_domain_check() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'sendbeam' ) );
	}
	check_admin_referer( 'sendbeam_domain_check' );

	$status = sendbeam_connect_status( true );
	$note   = 'checked';

	if ( ! $status['ok'] ) {
		$note = 'unreachable';
	} elseif ( '' === $status['domain']['name'] ) {
		$note = 'nodomain';
	} elseif ( $status['domain']['verified'] ) {
		$note     = 'verified';
		$settings = sendbeam_settings();
		if ( ! empty( $settings['sendbeam_mail_deferred'] )
			&& empty( $settings['mail_enabled'] )
			&& sendbeam_connect_granted( 'transactional:send' ) ) {
			sendbeam_connect_enable_mail( $status );
			$note = 'verified_mail';
		}
	} else {
		$note = 'pending';
	}

	wp_safe_redirect( add_query_arg( 'sendbeam_domain', $note, sendbeam_tab_url( 'overview' ) ) );
	exit;
}

/**
 * **Switch on**: route this site's email through SendBeam.
 *
 * Both conditions are re-read from the saved settings and a fresh status
 * rather than trusted from the page that drew the button: a form can be
 * replayed, and "the domain was verified when the page rendered" is not the
 * same as "the domain is verified".
 */
function sendbeam_handle_mail_switch_on() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'sendbeam' ) );
	}
	check_admin_referer( 'sendbeam_mail_switch_on' );

	if ( ! sendbeam_connect_granted( 'transactional:send' ) ) {
		wp_safe_redirect( add_query_arg( 'sendbeam_mail', 'noscope', sendbeam_tab_url( 'overview' ) ) );
		exit;
	}

	$status = sendbeam_connect_status();
	if ( empty( $status['domain']['verified'] ) ) {
		wp_safe_redirect( add_query_arg( 'sendbeam_mail', 'unverified', sendbeam_tab_url( 'overview' ) ) );
		exit;
	}

	sendbeam_connect_enable_mail( $status );
	wp_safe_redirect( add_query_arg( 'sendbeam_mail', 'on', sendbeam_tab_url( 'overview' ) ) );
	exit;
}

/**
 * Switch the wp_mail() relay on, filling in the sender only where it is blank.
 *
 * A From name or address the site owner typed is never replaced: they may well
 * have chosen `orders@` deliberately, and this is not the place to argue.
 *
 * @param array $status Normalised status, for the workspace sender.
 */
function sendbeam_connect_enable_mail( $status ) {
	$settings = sendbeam_settings();

	$settings['mail_enabled']           = 1;
	$settings['sendbeam_mail_deferred'] = 0;
	if ( '' === trim( (string) $settings['mail_from_name'] ) && '' !== $status['sender']['from_name'] ) {
		$settings['mail_from_name'] = $status['sender']['from_name'];
	}
	if ( '' === trim( (string) $settings['mail_from_email'] ) && '' !== $status['sender']['from_email'] ) {
		$settings['mail_from_email'] = $status['sender']['from_email'];
	}

	update_option( 'sendbeam_settings', $settings );
}
