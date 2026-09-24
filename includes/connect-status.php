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

/** The hourly DNS re-check, and where its tally lives. */
const SENDBEAM_RECHECK_HOOK  = 'sendbeam_connect_recheck';
const SENDBEAM_RECHECK_COUNT = 'sendbeam_connect_recheck_runs';

/**
 * A day of hourly re-checks, and then it gives up.
 *
 * DNS that has not spread in twenty-four hours has not been entered, and a
 * site quietly asking SendBeam about it every hour for the rest of its life
 * is a request nobody is waiting for.
 */
const SENDBEAM_RECHECK_MAX = 24;

add_action( SENDBEAM_RECHECK_HOOK, 'sendbeam_connect_run_recheck' );

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
 * How long the answer is kept after it stops being fresh.
 *
 * A minute is how long it is *trusted*; half a day is how long it is worth
 * *having*. When sendbeam.io cannot be reached, a minute-old copy of the DNS
 * records is enormously better than an empty panel — the owner is in the
 * middle of copying them into a registrar.
 */
const SENDBEAM_STATUS_KEEP = 12 * HOUR_IN_SECONDS;

/**
 * The seconds a screen render will wait for the status.
 *
 * The shared ten is right for a button somebody pressed. It is wrong for a
 * panel drawn on every Overview load: a slow API would hold wp-admin open for
 * ten seconds over something the owner may not even be looking at. Four is
 * long enough for a healthy request and short enough not to be noticed.
 */
const SENDBEAM_STATUS_TIMEOUT = 4;

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
		'notes'        => array(),
		// Why there is no sending domain, when there is none. `domain` being
		// null meant four different things before this — the box was never
		// ticked, the key is too old to read domains, the key has no site
		// host, the row was deleted by hand — and the screen could only say
		// "not set up" to all four, which is the one answer that helps
		// nobody.
		'domain_state' => '',
		'domain_note'  => '',
		// Served from the cache because the request failed, and how old it is.
		'stale'        => false,
		'fetched_at'   => 0,
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

	$out['domain_state'] = sendbeam_connect_clean_domain_state( isset( $data['domain_state'] ) ? $data['domain_state'] : '' );
	if ( isset( $data['domain_note'] ) && is_string( $data['domain_note'] ) ) {
		$out['domain_note'] = mb_substr( sanitize_text_field( $data['domain_note'] ), 0, 200 );
	}
	// v2 said the same thing in an array called `notes`. A site talking to a
	// SendBeam that predates `domain_note` still gets its sentence.
	if ( '' === $out['domain_note'] && $out['notes'] ) {
		$out['domain_note'] = (string) $out['notes'][0];
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

	$out['domain_state'] = sendbeam_connect_domain_state( $out );

	return $out;
}

/**
 * The states the contract defines, and nothing else.
 *
 * @param mixed $raw Value from the API.
 * @return string Empty when it is not one of them.
 */
function sendbeam_connect_clean_domain_state( $raw ) {
	$known = array( 'ok', 'not_granted', 'no_permission', 'no_site', 'not_found', 'managed_host', 'unavailable' );
	$raw   = is_string( $raw ) ? strtolower( trim( $raw ) ) : '';
	return in_array( $raw, $known, true ) ? $raw : '';
}

/**
 * A status's domain state, worked out when the answer did not carry one.
 *
 * A cached body written before this field existed, or an older SendBeam,
 * would otherwise leave every sentence on step 2 blank. A domain that came
 * back is `ok`; no domain and no reason is `not_found`, which is both the
 * commonest case and the one whose sentence is safe to show for any of them.
 *
 * @param array $status Normalised status.
 * @return string
 */
function sendbeam_connect_domain_state( $status ) {
	$state = isset( $status['domain_state'] ) ? sendbeam_connect_clean_domain_state( $status['domain_state'] ) : '';
	if ( '' !== $state ) {
		return $state;
	}
	return ( isset( $status['domain']['name'] ) && '' !== $status['domain']['name'] ) ? 'ok' : 'not_found';
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
 * A timestamp from the API, in the site's own words.
 *
 * SendBeam answers in ISO 8601 UTC, which is the right thing to send and the
 * wrong thing to show: "2026-09-24T05:49:24.291+00:00" is not a time anybody
 * reads, and it is not even the right hour for most of the people reading it.
 * WordPress already knows the site's timezone and the formats its owner
 * chose, so it does the rendering.
 *
 * Anything that will not parse is handed back untouched rather than dropped —
 * a strange-looking date is better than a step that silently stops saying
 * when it last ran.
 *
 * @param string $iso A timestamp from the API.
 * @return string
 */
function sendbeam_connect_when( $iso ) {
	$iso = trim( (string) $iso );
	if ( '' === $iso ) {
		return '';
	}
	$stamp = strtotime( $iso );
	if ( ! $stamp ) {
		return $iso;
	}
	return wp_date( get_option( 'date_format', 'j F Y' ) . ', ' . get_option( 'time_format', 'g:i a' ), $stamp );
}

/**
 * Where this key's status lives.
 *
 * Per key, not per site. Two administrators signed in to different SendBeam
 * workspaces — one testing, one live — shared a single transient, so for up
 * to a minute each of them was shown the other's sending domain and told to
 * put its records into their own DNS. The key is hashed rather than used:
 * a transient name ends up in the options table, in backups and in logs, and
 * an API key does not belong in any of them.
 *
 * @param string|null $api_key A particular key. Defaults to this site's.
 * @return string
 */
function sendbeam_connect_status_key( $api_key = null ) {
	$key = null === $api_key ? sendbeam_api_key() : (string) $api_key;
	return SENDBEAM_CACHE_STATUS . '_' . substr( sha1( $key ), 0, 12 );
}

/**
 * Remember a status, and when it was fetched.
 *
 * The timestamp is what separates "fresh" from "worth having anyway": the
 * transient outlives the minute it is trusted for, so a failed request has
 * something to fall back on.
 *
 * @param array $status Normalised status.
 */
function sendbeam_connect_cache_status( $status ) {
	$status['fetched_at'] = time();
	set_transient( sendbeam_connect_status_key(), $status, SENDBEAM_STATUS_KEEP );
}

/**
 * Forget it — after a disconnect, or when the key changes.
 *
 * @param string|null $api_key The key whose answer to forget. Defaults to
 *                             this site's, which is what every caller but the
 *                             key-change path wants.
 */
function sendbeam_connect_forget_status( $api_key = null ) {
	delete_transient( sendbeam_connect_status_key( $api_key ) );
	// Everything cached under the single shared name this replaced.
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

	$cached = get_transient( sendbeam_connect_status_key() );
	$cached = is_array( $cached ) && isset( $cached['domain'] ) ? $cached : null;

	if ( ! $check && null !== $cached && ( time() - (int) ( isset( $cached['fetched_at'] ) ? $cached['fetched_at'] : 0 ) ) < SENDBEAM_STATUS_TTL ) {
		return $cached;
	}

	$result = $check
		? sendbeam_api_post( '/api/v1/connect/status', array( 'check_domain' => true ) )
		: sendbeam_api_get( '/api/v1/connect/status', array( 'timeout' => SENDBEAM_STATUS_TIMEOUT ) );

	if ( ! $result['ok'] ) {
		if ( 429 === $result['status'] ) {
			$error = __( 'SendBeam is rate-limiting this site. Wait a few minutes and try again.', 'sendbeam' );
		} elseif ( 401 === $result['status'] || 403 === $result['status'] ) {
			$error = __( 'SendBeam would not answer with this site\'s key. Reconnect the site.', 'sendbeam' );
		} else {
			$error = __( 'Could not read this site\'s set-up from SendBeam just now.', 'sendbeam' );
		}

		/*
		 * An answer that has gone stale still beats no answer. Someone is in
		 * the middle of copying DNS records into a registrar, and blanking
		 * the panel because one request timed out takes them away from what
		 * they were doing for no gain. It is served with the state saying
		 * plainly that it is old.
		 *
		 * Either way it is cached, which is what stops a revoked key making a
		 * failing request on every wp-admin page load.
		 */
		$status                 = ( null !== $cached && ! empty( $cached['ok'] ) ) ? $cached : sendbeam_connect_empty_status();
		$status['error']        = $error;
		$status['stale']        = ( null !== $cached && ! empty( $cached['ok'] ) );
		$status['domain_state'] = 'unavailable';
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
		$status                 = ( null !== $cached && ! empty( $cached['ok'] ) ) ? $cached : sendbeam_connect_empty_status();
		$status['error']        = __( 'SendBeam answered with nothing this site could read.', 'sendbeam' );
		$status['stale']        = ( null !== $cached && ! empty( $cached['ok'] ) );
		$status['domain_state'] = 'unavailable';
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
		sendbeam_connect_unschedule_recheck();
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

	/*
	 * A held-back switch-on is Connect finishing the job consent started, so
	 * what it fills goes on the same list the exchange writes and Disconnect
	 * puts back. Pressing **Switch on** by hand is the owner's own decision
	 * and is recorded as nobody's but theirs.
	 */
	$connects = ! empty( $settings['sendbeam_mail_deferred'] );
	$filled   = isset( $settings['sendbeam_connect_filled'] ) && is_array( $settings['sendbeam_connect_filled'] ) ? $settings['sendbeam_connect_filled'] : array();

	if ( empty( $settings['mail_enabled'] ) && $connects ) {
		$filled[] = 'mail_enabled';
	}
	$settings['mail_enabled']           = 1;
	$settings['sendbeam_mail_deferred'] = 0;
	if ( '' === trim( (string) $settings['mail_from_name'] ) && '' !== $status['sender']['from_name'] ) {
		$settings['mail_from_name'] = $status['sender']['from_name'];
		if ( $connects ) {
			$filled[] = 'mail_from_name';
		}
	}
	if ( '' === trim( (string) $settings['mail_from_email'] ) && '' !== $status['sender']['from_email'] ) {
		$settings['mail_from_email'] = $status['sender']['from_email'];
		if ( $connects ) {
			$filled[] = 'mail_from_email';
		}
	}
	$settings['sendbeam_connect_filled'] = array_values( array_unique( $filled ) );

	update_option( 'sendbeam_settings', $settings );
}

/**
 * Start re-checking the DNS on this site's own, for a day.
 *
 * Records almost never propagate while somebody is sitting in wp-admin: they
 * enter them at the registrar, close the tab, and come back tomorrow. Until
 * now that meant step 2 stayed unticked and the site's email stayed held back
 * until a human pressed **Check now** — the plugin was waiting for the one
 * thing it had just told the owner they did not need to do.
 *
 * One request an hour is well inside the check's own rate limit, and it stops
 * the moment there is nothing left to find out.
 *
 * @param array $status The status that decided there was something to wait for.
 */
function sendbeam_connect_schedule_recheck( $status ) {
	if ( '' === sendbeam_api_key() || ! sendbeam_connect_granted( 'domain' ) ) {
		return;
	}
	// Nothing to wait for: no domain to check, or one that is already done.
	if ( '' === $status['domain']['name'] || ! empty( $status['domain']['verified'] ) ) {
		sendbeam_connect_unschedule_recheck();
		return;
	}

	update_option( SENDBEAM_RECHECK_COUNT, 0, false );
	if ( ! wp_next_scheduled( SENDBEAM_RECHECK_HOOK ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', SENDBEAM_RECHECK_HOOK );
	}
}

/** Stop re-checking: verified, disconnected, deactivated, or out of tries. */
function sendbeam_connect_unschedule_recheck() {
	wp_clear_scheduled_hook( SENDBEAM_RECHECK_HOOK );
	delete_option( SENDBEAM_RECHECK_COUNT );
}

/**
 * One hourly re-check.
 *
 * It does exactly what **Check now** does, including finishing the held-back
 * switch-on, and then decides whether there is any reason to run again. A
 * request that fails is not counted against the site — sendbeam.io being down
 * for an hour is not the same as the DNS not being there — but the tally is,
 * so a site cannot ask forever.
 */
function sendbeam_connect_run_recheck() {
	if ( '' === sendbeam_api_key() ) {
		sendbeam_connect_unschedule_recheck();
		return;
	}

	$runs = (int) get_option( SENDBEAM_RECHECK_COUNT, 0 ) + 1;
	update_option( SENDBEAM_RECHECK_COUNT, $runs, false );
	if ( $runs > SENDBEAM_RECHECK_MAX ) {
		sendbeam_connect_unschedule_recheck();
		return;
	}

	$status = sendbeam_connect_status( true );
	if ( ! $status['ok'] ) {
		return; // Ask again next hour.
	}
	if ( '' === $status['domain']['name'] ) {
		sendbeam_connect_unschedule_recheck();
		return;
	}
	if ( empty( $status['domain']['verified'] ) ) {
		return;
	}

	$settings = sendbeam_settings();
	if ( ! empty( $settings['sendbeam_mail_deferred'] )
		&& empty( $settings['mail_enabled'] )
		&& sendbeam_connect_granted( 'transactional:send' ) ) {
		sendbeam_connect_enable_mail( $status );
	}

	sendbeam_connect_unschedule_recheck();
}
