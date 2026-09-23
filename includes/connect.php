<?php
/**
 * Connect SendBeam: getting a key without anyone copying one.
 *
 * The old first step was four steps: make an account on sendbeam.io, find the
 * API keys screen, work out which permissions this plugin needs, paste the key
 * back here. Every one of those is a place to stop. Connect replaces it with a
 * button: the site says who it is and what it wants to do, the person creates
 * the account or signs in on sendbeam.io in a pop-up, ticks what the site may
 * do, and SendBeam hands this site a key with exactly those permissions.
 *
 * The plugin is the *client* in that exchange, and it never trusts the browser
 * with the key:
 *
 *   - the pop-up comes back with a single-use grant, not a key;
 *   - the key is fetched server-to-server, from this site's own PHP;
 *   - `state` is the CSRF token. There is no nonce on the return, because the
 *     return is a redirect from another origin and a nonce cannot survive one.
 *     The state is minted here, kept in a transient belonging to one admin
 *     user, and must come back unchanged. It is deleted the moment it is read,
 *     so a replayed return URL is worth nothing.
 *
 * @package SendBeam
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_post_sendbeam_connect_start', 'sendbeam_connect_start' );
add_action( 'admin_post_sendbeam_connect_return', 'sendbeam_connect_return' );

/** How long the person has to finish signing up in the pop-up. */
const SENDBEAM_CONNECT_TTL = 15 * MINUTE_IN_SECONDS;

/**
 * What the site can ask SendBeam for, in the words the consent page uses.
 *
 * The wording is shared with the SendBeam end deliberately: someone ticking a
 * box here and reading a differently-worded version of it on the next screen
 * has no way to know they are the same permission.
 *
 * @return array<string,array{label:string,always:bool}>
 */
function sendbeam_connect_scopes() {
	return array(
		'forms'              => array(
			'label'  => __( 'Show your forms on the site', 'sendbeam' ),
			'always' => true,
		),
		'contacts:write'     => array(
			'label'  => __( 'Add people who tick an opt-in box at registration, in comments or at checkout', 'sendbeam' ),
			'always' => false,
		),
		'transactional:send' => array(
			'label'  => __( 'Send the site\'s own email (password resets, order emails, notifications) through SendBeam', 'sendbeam' ),
			'always' => false,
		),
		'ecommerce'          => array(
			'label'  => __( 'Send WooCommerce order, cart and product events to SendBeam', 'sendbeam' ),
			'always' => false,
		),
	);
}

/**
 * This site's origin — scheme, host and port, nothing else.
 *
 * SendBeam binds the grant to this exact string and refuses to hand the key to
 * anything else, so it must be the origin and not a path: a site installed in
 * a subdirectory would otherwise present a different value on the way out than
 * on the way back.
 *
 * @return string
 */
function sendbeam_connect_site_origin() {
	$parts = wp_parse_url( site_url() );
	if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
		return '';
	}
	$origin = $parts['scheme'] . '://' . $parts['host'];
	if ( ! empty( $parts['port'] ) ) {
		$origin .= ':' . (int) $parts['port'];
	}
	return $origin;
}

/**
 * Is this origin one SendBeam will accept?
 *
 * Https everywhere, because the grant travels over it: http is allowed only on
 * the hostnames a development machine uses, which cannot be reached from the
 * internet — refusing those outright would make the feature untestable on a
 * local install without weakening it anywhere that matters.
 *
 * @param string $origin Origin from sendbeam_connect_site_origin().
 * @return bool
 */
function sendbeam_connect_origin_ok( $origin ) {
	$parts = wp_parse_url( (string) $origin );
	if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
		return false;
	}
	if ( 'https' === $parts['scheme'] ) {
		return true;
	}
	if ( 'http' !== $parts['scheme'] ) {
		return false;
	}
	$host = strtolower( $parts['host'] );
	if ( in_array( $host, array( 'localhost', '127.0.0.1', '::1', '[::1]' ), true ) ) {
		return true;
	}
	return (bool) preg_match( '/\.(test|local)$/', $host );
}

/**
 * Where the pop-up's state lives while the person is on sendbeam.io.
 *
 * Per user, not per site: two administrators connecting at once must not
 * overwrite each other's state, and a state minted by one of them must not be
 * usable by the other.
 *
 * @param int $user_id User ID.
 * @return string
 */
function sendbeam_connect_transient_key( $user_id ) {
	return 'sendbeam_connect_' . (int) $user_id;
}

/**
 * The pop-up URL, exactly as the contract specifies it.
 *
 * Nothing here is left to add_query_arg(), which does not encode: every value
 * is rawurlencode()d first. A site name with a space or an ampersand would
 * otherwise cut the query in half and SendBeam would read the wrong return
 * address.
 *
 * @param string[] $scopes Approved scope names.
 * @param string   $state  The CSRF token.
 * @return string Empty when this site cannot be connected (bad origin).
 */
function sendbeam_connect_start_url( $scopes, $state ) {
	$origin = sendbeam_connect_site_origin();
	if ( '' === $origin || ! sendbeam_connect_origin_ok( $origin ) ) {
		return '';
	}

	$user = wp_get_current_user();
	$name = trim( (string) get_bloginfo( 'name' ) );
	if ( function_exists( 'mb_substr' ) ) {
		$name = mb_substr( $name, 0, 80 );
	} else {
		$name = substr( $name, 0, 80 );
	}

	return add_query_arg(
		array(
			'site_url'  => rawurlencode( $origin ),
			'site_name' => rawurlencode( $name ),
			'email'     => rawurlencode( isset( $user->user_email ) ? (string) $user->user_email : '' ),
			'scopes'    => rawurlencode( implode( ',', $scopes ) ),
			'state'     => rawurlencode( $state ),
			'return_to' => rawurlencode( sendbeam_connect_return_url() ),
		),
		sendbeam_app_url() . '/connect/wordpress'
	);
}

/**
 * Where SendBeam sends the pop-up back to. Always on this site's own origin.
 *
 * @return string
 */
function sendbeam_connect_return_url() {
	return admin_url( 'admin-post.php?action=sendbeam_connect_return' );
}

/**
 * Only the scopes we offer, and `forms` whether it was ticked or not.
 *
 * Nothing works without `forms` — the settings screen cannot list a single
 * form — so it is presented as fixed rather than as a choice that quietly
 * breaks the plugin.
 *
 * @param mixed $raw Posted scope list.
 * @return string[]
 */
function sendbeam_connect_clean_scopes( $raw ) {
	$known = sendbeam_connect_scopes();
	$out   = array();
	foreach ( $known as $scope => $meta ) {
		if ( $meta['always'] ) {
			$out[] = $scope;
			continue;
		}
		if ( is_array( $raw ) && in_array( $scope, array_map( 'strval', $raw ), true ) ) {
			$out[] = $scope;
		}
	}
	return $out;
}

/**
 * Step one: mint the state, remember it, and send the pop-up to SendBeam.
 */
function sendbeam_connect_start() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'sendbeam' ) );
	}
	check_admin_referer( 'sendbeam_connect_start' );

	$raw = array();
	if ( isset( $_POST['sendbeam_scopes'] ) && is_array( $_POST['sendbeam_scopes'] ) ) {
		$raw = array_map( 'sanitize_text_field', wp_unslash( $_POST['sendbeam_scopes'] ) );
	}
	$scopes = sendbeam_connect_clean_scopes( $raw );

	// [A-Za-z0-9] only, and 43 of them: comfortably past the 32 the contract
	// asks for, and safe in a query string without escaping surprises.
	$state = wp_generate_password( 43, false, false );

	// Built before anything is remembered: a site that cannot be connected at
	// all should not be left holding a pending connection it can never finish.
	$url = sendbeam_connect_start_url( $scopes, $state );
	if ( '' === $url ) {
		wp_die( esc_html__( 'This site has no https address, so it cannot be connected to SendBeam. Add a certificate, or paste an API key instead.', 'sendbeam' ) );
	}

	set_transient(
		sendbeam_connect_transient_key( get_current_user_id() ),
		array(
			'state'  => $state,
			'scopes' => $scopes,
		),
		SENDBEAM_CONNECT_TTL
	);

	// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- deliberately off-site: this is the whole point of the button, and the destination is sendbeam_app_url().
	wp_redirect( $url, 302 );
	exit;
}

/**
 * Step two: the pop-up comes back. Swap the grant for a key, server to server.
 */
function sendbeam_connect_return() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'sendbeam' ) );
	}

	/*
	 * No nonce, by design (see the file header): this is a redirect arriving
	 * from sendbeam.io, and `state` is the token. It is read once and the
	 * transient is deleted immediately, in every branch below, so the same
	 * return URL cannot be replayed.
	 */
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- `state`, checked below against a per-user transient, is the CSRF token for this cross-site return.
	$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
	$grant = isset( $_GET['grant'] ) ? sanitize_text_field( wp_unslash( $_GET['grant'] ) ) : '';
	$error = isset( $_GET['error'] ) ? sanitize_key( wp_unslash( $_GET['error'] ) ) : '';
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	$key    = sendbeam_connect_transient_key( get_current_user_id() );
	$stored = get_transient( $key );
	delete_transient( $key );

	$expected = is_array( $stored ) && isset( $stored['state'] ) ? (string) $stored['state'] : '';

	if ( '' === $expected || '' === $state || ! hash_equals( $expected, $state ) ) {
		sendbeam_connect_render_result(
			false,
			__( 'Not connected', 'sendbeam' ),
			__( 'This window did not come back from the request this site started. Nothing was changed. Close it and press Connect SendBeam again.', 'sendbeam' )
		);
		exit;
	}

	if ( '' !== $error ) {
		sendbeam_connect_render_result(
			false,
			__( 'Not connected', 'sendbeam' ),
			__( 'You did not approve the connection, so this site was given nothing. You can press Connect SendBeam again whenever you like.', 'sendbeam' )
		);
		exit;
	}

	if ( ! preg_match( '/^[A-Za-z0-9_\-]{20,128}$/', $grant ) ) {
		sendbeam_connect_render_result(
			false,
			__( 'Not connected', 'sendbeam' ),
			__( 'SendBeam did not send anything this site could use. Nothing was changed.', 'sendbeam' )
		);
		exit;
	}

	$result = sendbeam_connect_exchange( $grant, $state );
	if ( $result['ok'] ) {
		sendbeam_connect_render_result(
			true,
			__( 'Connected', 'sendbeam' ),
			$result['workspace']
				/* translators: %s: the SendBeam workspace name */
				? sprintf( __( 'This site is connected to %s. You can close this window.', 'sendbeam' ), $result['workspace'] )
				: __( 'This site is connected. You can close this window.', 'sendbeam' )
		);
		exit;
	}

	sendbeam_connect_render_result( false, __( 'Not connected', 'sendbeam' ), $result['error'] );
	exit;
}

/**
 * The server-to-server half: grant in, key out, once.
 *
 * The key never touches the browser and is never written to a log — not the
 * error branch either, which is where that usually happens.
 *
 * @param string $grant Single-use grant ID from the redirect.
 * @param string $state The state it is bound to.
 * @return array{ok:bool,workspace:string,error:string}
 */
function sendbeam_connect_exchange( $grant, $state ) {
	$origin   = sendbeam_connect_site_origin();
	$response = wp_remote_post(
		sendbeam_app_url() . '/api/v1/connect/exchange',
		array(
			'timeout' => 15,
			'headers' => array(
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
				'User-Agent'   => 'SendBeam-WordPress/' . SENDBEAM_VERSION . ' (' . $origin . ')',
			),
			'body'    => wp_json_encode(
				array(
					'grant'    => $grant,
					'state'    => $state,
					'site_url' => $origin,
				)
			),
		)
	);

	if ( is_wp_error( $response ) ) {
		return array(
			'ok'        => false,
			'workspace' => '',
			'error'     => __( 'This server could not reach sendbeam.io to finish the connection. Nothing was changed. Try again, or paste an API key instead.', 'sendbeam' ),
		);
	}

	$status = (int) wp_remote_retrieve_response_code( $response );
	$body   = json_decode( (string) wp_remote_retrieve_body( $response ), true );
	$data   = is_array( $body ) ? $body : array();

	if ( 410 === $status ) {
		return array(
			'ok'        => false,
			'workspace' => '',
			'error'     => __( 'That connection had already been used, or it was left too long. Nothing was changed. Press Connect SendBeam again to start a fresh one.', 'sendbeam' ),
		);
	}
	if ( 429 === $status ) {
		return array(
			'ok'        => false,
			'workspace' => '',
			'error'     => __( 'SendBeam is rate-limiting this site. Wait a few minutes and press Connect SendBeam again.', 'sendbeam' ),
		);
	}
	if ( 200 !== $status || empty( $data['api_key'] ) || ! is_string( $data['api_key'] ) ) {
		return array(
			'ok'        => false,
			'workspace' => '',
			'error'     => __( 'SendBeam refused to finish the connection. Nothing was changed. Press Connect SendBeam again, or paste an API key instead.', 'sendbeam' ),
		);
	}

	$workspace = '';
	if ( isset( $data['workspace']['name'] ) && is_string( $data['workspace']['name'] ) ) {
		$workspace = sanitize_text_field( $data['workspace']['name'] );
	}

	if ( ! sendbeam_connect_store_key( $data['api_key'], $workspace ) ) {
		return array(
			'ok'        => false,
			'workspace' => '',
			'error'     => __( 'SendBeam sent something that does not look like an API key, so nothing was saved. Press Connect SendBeam again, or paste a key instead.', 'sendbeam' ),
		);
	}

	return array(
		'ok'        => true,
		'workspace' => $workspace,
		'error'     => '',
	);
}

/**
 * Save the key the way a pasted one is saved.
 *
 * Through sendbeam_sanitize_settings() rather than straight into the option:
 * that function is where the key is validated, where the other tabs' settings
 * are preserved and where the cached workspace data is dropped. A second way
 * of writing the same option is a second set of rules to keep in step, and
 * the one that skips the validation is the one that stores rubbish.
 *
 * @param string $api_key   The key SendBeam minted.
 * @param string $workspace Workspace name, for the screen to show.
 * @return bool False when the key did not survive validation — nothing saved.
 */
function sendbeam_connect_store_key( $api_key, $workspace ) {
	$clean = sendbeam_sanitize_settings(
		array(
			'_tab'    => 'connect',
			'api_key' => $api_key,
		)
	);

	if ( trim( (string) $api_key ) !== (string) $clean['api_key'] || '' === (string) $clean['api_key'] ) {
		return false;
	}

	$clean['sendbeam_connected_via']     = 'connect';
	$clean['sendbeam_connect_workspace'] = $workspace;

	update_option( 'sendbeam_settings', $clean );
	sendbeam_flush_cache();
	return true;
}

/**
 * Was this site connected through the button rather than a pasted key?
 *
 * @return bool
 */
function sendbeam_connected_via_connect() {
	$settings = sendbeam_settings();
	return 'connect' === (string) $settings['sendbeam_connected_via'] && '' !== sendbeam_api_key();
}

/**
 * The workspace the button connected this site to, if it was the button.
 *
 * @return string
 */
function sendbeam_connect_workspace_name() {
	$settings = sendbeam_settings();
	return (string) $settings['sendbeam_connect_workspace'];
}

/**
 * The little page the pop-up ends on.
 *
 * It tells the opener what happened and closes itself. The link underneath is
 * not decoration: a browser will only close a window that script opened, and
 * if the person reached this through something other than the pop-up — a
 * blocker, a middle-click — the window stays and the link is the way out.
 *
 * @param bool   $connected Whether a key was stored.
 * @param string $heading   Heading.
 * @param string $detail    One sentence under it.
 */
function sendbeam_connect_render_result( $connected, $heading, $detail ) {
	$overview = admin_url( 'options-general.php?page=sendbeam' );
	$origin   = sendbeam_connect_site_origin();

	nocache_headers();
	if ( ! headers_sent() ) {
		header( 'Content-Type: text/html; charset=utf-8' );
	}

	echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8" />';
	echo '<meta name="viewport" content="width=device-width, initial-scale=1" />';
	echo '<title>' . esc_html( $heading ) . '</title>';
	echo '<style>body{margin:0;padding:48px 32px;font:15px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;color:#121212;background:#EDEBE6}';
	echo 'h1{font-size:20px;margin:0 0 8px}p{margin:0 0 16px;max-width:34em}a{color:#1F3FBF}</style></head><body>';
	printf( '<h1>%s</h1>', esc_html( $heading ) );
	printf( '<p>%s</p>', esc_html( $detail ) );
	printf(
		'<p><a href="%s">%s</a></p>',
		esc_url( $overview ),
		esc_html__( 'Back to SendBeam settings', 'sendbeam' )
	);

	// Only a success is announced; the opener reloads on it and would
	// otherwise throw away an unsaved settings form for nothing.
	if ( $connected && '' !== $origin ) {
		printf(
			'<script>try{window.opener&&window.opener.postMessage({sendbeam:"connected"},%s);}catch(e){}window.close();</script>',
			wp_json_encode( $origin, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT )
		);
	} else {
		echo '<script>window.close();</script>';
	}

	echo '</body></html>';

	/**
	 * Fires when the Connect pop-up has rendered its outcome, immediately
	 * before the request ends.
	 *
	 * @param bool   $connected Whether a key was stored.
	 * @param string $detail    The sentence shown to the person.
	 */
	do_action( 'sendbeam_connect_result', $connected, $detail );
}

/**
 * The Connect panel: what the site wants to do, and the button.
 *
 * @return void
 */
function sendbeam_connect_panel() {
	$origin = sendbeam_connect_site_origin();

	if ( ! sendbeam_connect_origin_ok( $origin ) ) {
		echo '<p class="sb-note">' . esc_html__( 'This site is not served over https, so SendBeam cannot connect to it. Paste an API key below instead.', 'sendbeam' ) . '</p>';
		return;
	}

	echo '<p style="margin-top:8px">' . esc_html__( 'Create your SendBeam account, or sign in, and this site is connected — no key to copy.', 'sendbeam' ) . '</p>';

	printf( '<form id="sb-connect-form" method="post" action="%s">', esc_url( admin_url( 'admin-post.php' ) ) );
	wp_nonce_field( 'sendbeam_connect_start' );
	echo '<input type="hidden" name="action" value="sendbeam_connect_start" />';
	echo '<p style="margin:0 0 6px"><strong>' . esc_html__( 'This site will ask SendBeam for permission to:', 'sendbeam' ) . '</strong></p>';
	echo '<ul class="sb-scopes">';
	foreach ( sendbeam_connect_scopes() as $scope => $meta ) {
		echo '<li><label class="sb-inline sb-scope">';
		printf(
			'<input type="checkbox" name="sendbeam_scopes[]" value="%s" checked="checked"%s /> ',
			esc_attr( $scope ),
			$meta['always'] ? ' disabled="disabled"' : ''
		);
		echo esc_html( $meta['label'] );
		if ( $meta['always'] ) {
			echo ' <span class="sb-note">' . esc_html__( '(always)', 'sendbeam' ) . '</span>';
		}
		echo '</label></li>';
	}
	echo '</ul>';
	printf(
		'<p><button type="submit" class="sb-btn">%s</button></p>',
		esc_html__( 'Connect SendBeam', 'sendbeam' )
	);
	echo '</form>';
}

/**
 * The two behaviours the panel needs, appended to the admin script.
 *
 * The window is opened in the submit handler — synchronously, inside the
 * click — because a pop-up opened any later is a pop-up the browser blocks.
 * If it is blocked anyway the form is left alone and simply navigates, which
 * still works: the return page has a link back.
 *
 * @return string
 */
function sendbeam_connect_admin_js() {
	$origin = wp_json_encode( sendbeam_connect_site_origin(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
	return '
	( function () {
		var origin = ' . $origin . ';
		var form   = document.getElementById( "sb-connect-form" );
		if ( form ) {
			form.addEventListener( "submit", function () {
				var win = window.open( "", "sendbeam-connect", "width=600,height=760" );
				if ( win ) { form.target = "sendbeam-connect"; }
			} );
		}
		window.addEventListener( "message", function ( e ) {
			if ( e.origin !== origin || ! e.data || "connected" !== e.data.sendbeam ) { return; }
			window.location.reload();
		} );
	}() );
	';
}
