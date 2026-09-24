<?php
/**
 * SendBeam, in Tools → Site Health.
 *
 * Site Health is where a site owner — or the person they have asked for help
 * — goes when something is wrong and they do not yet know what. A plugin that
 * routes a site's entire outbound email and then says nothing there is a
 * plugin that can break a site silently.
 *
 * Three tests, each one a thing that can be true or false on its own:
 * the key works, the sending domain is verified, and the relay is actually
 * switched on behind a verified domain. Plus a debug section, so the Site
 * Health report somebody pastes into a support request already answers the
 * first four questions anyone would ask.
 *
 * Nothing here calls the API: every value comes from the same minute-old
 * cache the Overview reads. A Site Health page that makes three HTTP requests
 * is a Site Health page that times out on a slow host.
 *
 * @package SendBeam
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'site_status_tests', 'sendbeam_site_status_tests' );
add_filter( 'debug_information', 'sendbeam_debug_information' );

/**
 * Register the three tests.
 *
 * Direct rather than async: none of them makes a request, so there is nothing
 * to wait for and no reason to make the page fetch them one at a time.
 *
 * @param array $tests Core's tests.
 * @return array
 */
function sendbeam_site_status_tests( $tests ) {
	$tests['direct']['sendbeam-key']    = array(
		'label' => __( 'SendBeam connection', 'sendbeam' ),
		'test'  => 'sendbeam_test_key',
	);
	$tests['direct']['sendbeam-domain'] = array(
		'label' => __( 'SendBeam sending domain', 'sendbeam' ),
		'test'  => 'sendbeam_test_domain',
	);
	$tests['direct']['sendbeam-mail']   = array(
		'label' => __( 'SendBeam site email', 'sendbeam' ),
		'test'  => 'sendbeam_test_mail_relay',
	);
	return $tests;
}

/**
 * The shape every Site Health test returns, filled in.
 *
 * @param string $label       The heading.
 * @param string $status      good, recommended or critical.
 * @param string $colour      blue, orange, red or gray.
 * @param string $description One paragraph of HTML.
 * @param string $action      A link, or ''.
 * @param string $test        The test's id.
 * @return array
 */
function sendbeam_health_result( $label, $status, $colour, $description, $action, $test ) {
	return array(
		'label'       => $label,
		'status'      => $status,
		'badge'       => array(
			'label' => __( 'Email', 'sendbeam' ),
			'color' => $colour,
		),
		'description' => '<p>' . $description . '</p>',
		'actions'     => $action,
		'test'        => $test,
	);
}

/**
 * A link to one of the plugin's own screens, in the markup Site Health uses.
 *
 * @param string $slug  Page slug.
 * @param string $label Link text.
 * @param string $tab   Optional tab on that page.
 * @return string
 */
function sendbeam_health_link( $slug, $label, $tab = '' ) {
	$url = sendbeam_page_url( $slug );
	if ( '' !== $tab ) {
		$url = add_query_arg( 'tab', $tab, $url );
	}
	return sprintf( '<p><a href="%s">%s</a></p>', esc_url( $url ), esc_html( $label ) );
}

/**
 * Does this site's key work?
 *
 * @return array
 */
function sendbeam_test_key() {
	$connection = sendbeam_connection();
	$link       = sendbeam_health_link( 'sendbeam-settings', __( 'Open SendBeam settings', 'sendbeam' ), 'connection' );

	if ( 'none' === $connection['state'] ) {
		return sendbeam_health_result(
			__( 'SendBeam is not connected', 'sendbeam' ),
			'recommended',
			'orange',
			esc_html__( 'The SendBeam plugin is active but this site has no API key, so its forms, lists and site email are not available. Nothing is broken by this — the plugin simply does nothing until it is connected.', 'sendbeam' ),
			$link,
			'sendbeam-key'
		);
	}

	if ( 'rejected' === $connection['state'] ) {
		return sendbeam_health_result(
			__( 'SendBeam rejected this site\'s key', 'sendbeam' ),
			'critical',
			'red',
			esc_html__( 'SendBeam will not accept the key this site is using. Any form, list or email that depends on it has stopped working. The usual causes are a key that was revoked in SendBeam and a key copied only in part.', 'sendbeam' ),
			$link,
			'sendbeam-key'
		);
	}

	if ( 'unreachable' === $connection['state'] ) {
		return sendbeam_health_result(
			__( 'SendBeam could not be reached from this server', 'sendbeam' ),
			'recommended',
			'orange',
			esc_html(
				sprintf(
					/* translators: %s: the error this server reported */
					__( 'The last attempt to reach sendbeam.io failed: %s. That is usually the host blocking outbound requests, or a temporary outage. Stored settings are unaffected.', 'sendbeam' ),
					$connection['message']
				)
			),
			$link,
			'sendbeam-key'
		);
	}

	if ( 'no_scope' === $connection['state'] ) {
		return sendbeam_health_result(
			__( 'SendBeam is connected with a limited key', 'sendbeam' ),
			'recommended',
			'orange',
			esc_html__( 'The key works, but it was not given permission to read this workspace\'s forms, so they cannot be listed in wp-admin. Everything the key was given permission for still works.', 'sendbeam' ),
			$link,
			'sendbeam-key'
		);
	}

	return sendbeam_health_result(
		__( 'SendBeam is connected', 'sendbeam' ),
		'good',
		'blue',
		esc_html__( 'This site\'s API key works and SendBeam is answering to it.', 'sendbeam' ),
		'',
		'sendbeam-key'
	);
}

/**
 * Is the sending domain verified?
 *
 * @return array
 */
function sendbeam_test_domain() {
	$link = sendbeam_health_link( 'sendbeam-settings', __( 'Open the sending domain', 'sendbeam' ), 'domain' );

	if ( ! sendbeam_is_connected() ) {
		return sendbeam_health_result(
			__( 'SendBeam has no sending domain yet', 'sendbeam' ),
			'recommended',
			'orange',
			esc_html__( 'This site is not connected to SendBeam, so there is no sending domain to check.', 'sendbeam' ),
			$link,
			'sendbeam-domain'
		);
	}

	$status = sendbeam_connect_status();
	$domain = $status['domain'];

	if ( ! empty( $domain['verified'] ) ) {
		return sendbeam_health_result(
			__( 'The SendBeam sending domain is verified', 'sendbeam' ),
			'good',
			'blue',
			esc_html(
				sprintf(
					/* translators: %s: the sending domain */
					__( 'SendBeam has found the DNS records for %s, so email can be sent as that domain.', 'sendbeam' ),
					$domain['name']
				)
			),
			'',
			'sendbeam-domain'
		);
	}

	if ( '' === $domain['name'] ) {
		return sendbeam_health_result(
			__( 'SendBeam has no sending domain yet', 'sendbeam' ),
			'recommended',
			'orange',
			esc_html( sendbeam_domain_sentence( '', false ) ),
			$link,
			'sendbeam-domain'
		);
	}

	return sendbeam_health_result(
		__( 'The SendBeam sending domain is not verified', 'sendbeam' ),
		'recommended',
		'orange',
		esc_html(
			sprintf(
				/* translators: %s: the sending domain */
				__( 'SendBeam has not yet found the DNS records for %s. Until it does, email cannot be sent as that domain. The records, and a button to check again, are on the SendBeam settings screen.', 'sendbeam' ),
				$domain['name']
			)
		),
		$link,
		'sendbeam-domain'
	);
}

/**
 * Is this site's email actually going through SendBeam, safely?
 *
 * The interesting failure is the middle one: the relay on with an unverified
 * domain. That is a site whose password resets are being handed to a sender
 * nothing has proved, which is worse than not using the plugin at all.
 *
 * @return array
 */
function sendbeam_test_mail_relay() {
	$settings = sendbeam_settings();
	$link     = sendbeam_health_link( 'sendbeam-mail', __( 'Open the site email settings', 'sendbeam' ) );

	$blocked = sendbeam_mail_blocked();

	if ( 'off' === $blocked ) {
		return sendbeam_health_result(
			__( 'This site\'s email does not go through SendBeam', 'sendbeam' ),
			'good',
			'blue',
			esc_html__( 'Site email is switched off, so WordPress sends its own email the way it did before this plugin was installed. This is not a fault; it is optional.', 'sendbeam' ),
			$link,
			'sendbeam-mail'
		);
	}

	/*
	 * Switched on with no key. The critical alarm below is about email being
	 * handed to an unverified sender, and none of this site's email goes near
	 * SendBeam at all: sendbeam_pre_wp_mail() cannot short-circuit without a
	 * key, so every message leaves by the server's own mailer exactly as it
	 * did before. Raising a red flag about a thing that is not happening is
	 * how a Site Health page teaches people to ignore it.
	 */
	if ( 'no_key' === $blocked ) {
		return sendbeam_health_result(
			__( 'This site\'s email does not go through SendBeam yet', 'sendbeam' ),
			'recommended',
			'blue',
			esc_html__( 'Site email is switched on, but this site has no SendBeam API key, so WordPress is still sending its own email the way it did before. Connect the site and it will start going through SendBeam.', 'sendbeam' ),
			$link,
			'sendbeam-mail'
		);
	}

	$verified = sendbeam_is_connected() && ! empty( sendbeam_connect_status()['domain']['verified'] );

	if ( ! $verified ) {
		return sendbeam_health_result(
			__( 'This site\'s email is being sent from an unverified domain', 'sendbeam' ),
			'critical',
			'red',
			esc_html__( 'Site email is switched on, but SendBeam has not verified the sending domain. Messages this site sends — password resets included — are likely to be refused or filed as spam. Either finish verifying the domain or switch site email off until it is done.', 'sendbeam' ),
			$link,
			'sendbeam-mail'
		);
	}

	$fallback = ! empty( $settings['mail_fallback'] )
		? __( 'A message SendBeam cannot take falls back to the server\'s own mailer, so nothing is lost.', 'sendbeam' )
		: __( 'The fallback to the server\'s own mailer is off, so a message SendBeam cannot take is not sent at all and the plugin that tried is told.', 'sendbeam' );

	return sendbeam_health_result(
		__( 'This site\'s email goes through SendBeam', 'sendbeam' ),
		'good',
		'blue',
		esc_html__( 'Site email is on and the sending domain is verified.', 'sendbeam' ) . ' ' . esc_html( $fallback ),
		'',
		'sendbeam-mail'
	);
}

/**
 * The SendBeam section of Tools → Site Health → Info.
 *
 * Everything here is a state, never a secret: the key is reported as where it
 * lives and how it ends, because the whole point of this panel is that it can
 * be copied in one press and pasted into a support request.
 *
 * @param array $info Core's sections.
 * @return array
 */
function sendbeam_debug_information( $info ) {
	$settings   = sendbeam_settings();
	$connection = sendbeam_connection();
	$connected  = sendbeam_is_connected();
	$status     = $connected ? sendbeam_connect_status() : sendbeam_connect_empty_status();
	$key        = sendbeam_api_key();

	$where = __( 'Not set', 'sendbeam' );
	if ( '' !== $key ) {
		$where  = sendbeam_key_in_config()
			? __( 'wp-config.php constant', 'sendbeam' )
			: __( 'Stored in the options table', 'sendbeam' );
		$where .= ' (…' . substr( $key, -4 ) . ')';
	}

	$recheck = wp_next_scheduled( SENDBEAM_RECHECK_HOOK );

	$fields = array(
		'version'       => array(
			'label' => __( 'Plugin version', 'sendbeam' ),
			'value' => SENDBEAM_VERSION,
		),
		'app_url'       => array(
			'label' => __( 'SendBeam address', 'sendbeam' ),
			'value' => sendbeam_app_url(),
		),
		'key'           => array(
			'label' => __( 'API key', 'sendbeam' ),
			'value' => $where,
		),
		'connected_via' => array(
			'label' => __( 'Connected by', 'sendbeam' ),
			'value' => sendbeam_connected_via_connect() ? __( 'The Connect button', 'sendbeam' ) : __( 'A pasted key', 'sendbeam' ),
		),
		'state'         => array(
			'label' => __( 'Connection state', 'sendbeam' ),
			'value' => $connection['state'],
		),
		'workspace'     => array(
			'label' => __( 'Workspace', 'sendbeam' ),
			'value' => '' !== (string) $status['workspace']['name'] ? (string) $status['workspace']['name'] : __( 'Not reported', 'sendbeam' ),
		),
		'granted'       => array(
			'label' => __( 'Permissions granted', 'sendbeam' ),
			'value' => '' !== (string) $settings['sendbeam_connect_granted'] ? (string) $settings['sendbeam_connect_granted'] : __( 'Not recorded', 'sendbeam' ),
		),
		'domain'        => array(
			'label' => __( 'Sending domain', 'sendbeam' ),
			'value' => '' !== (string) $status['domain']['name'] ? (string) $status['domain']['name'] : __( 'None', 'sendbeam' ),
		),
		'domain_state'  => array(
			'label' => __( 'Sending domain state', 'sendbeam' ),
			'value' => ! empty( $status['domain']['verified'] ) ? 'verified' : (string) $status['domain_state'],
		),
		'domain_check'  => array(
			'label' => __( 'Sending domain last checked', 'sendbeam' ),
			'value' => '' !== (string) $status['domain']['checked_at'] ? sendbeam_connect_when( (string) $status['domain']['checked_at'] ) : __( 'Never', 'sendbeam' ),
		),
		'mail'          => array(
			'label' => __( 'Site email', 'sendbeam' ),
			'value' => ! empty( $settings['mail_enabled'] ) ? __( 'On', 'sendbeam' ) : __( 'Off', 'sendbeam' ),
		),
		'mail_from'     => array(
			'label' => __( 'Site email From address', 'sendbeam' ),
			'value' => '' !== (string) $settings['mail_from_email'] ? (string) $settings['mail_from_email'] : __( 'The workspace sender', 'sendbeam' ),
		),
		'mail_fallback' => array(
			'label' => __( 'Site email fallback', 'sendbeam' ),
			'value' => ! empty( $settings['mail_fallback'] ) ? __( 'On', 'sendbeam' ) : __( 'Off', 'sendbeam' ),
		),
		'form_placed'   => array(
			'label' => __( 'A SendBeam form on the site', 'sendbeam' ),
			'value' => '' !== sendbeam_form_placement() ? sendbeam_form_placement() : __( 'Not found', 'sendbeam' ),
		),
		'recheck'       => array(
			'label' => __( 'Hourly domain re-check', 'sendbeam' ),
			'value' => $recheck ? sendbeam_connect_when( gmdate( 'c', (int) $recheck ) ) : __( 'Not scheduled', 'sendbeam' ),
		),
		'cron'          => array(
			'label' => __( 'WordPress cron', 'sendbeam' ),
			'value' => ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) ? __( 'Disabled in wp-config.php', 'sendbeam' ) : __( 'Enabled', 'sendbeam' ),
		),
	);

	$info['sendbeam'] = array(
		'label'       => __( 'SendBeam', 'sendbeam' ),
		'description' => __( 'What this site holds about its SendBeam connection. None of it is a secret: the API key is reported only as where it is kept and its last four characters.', 'sendbeam' ),
		'fields'      => $fields,
	);

	return $info;
}

/**
 * The same values, as one block of text somebody can paste into an email.
 *
 * @return string
 */
function sendbeam_status_report() {
	$info  = sendbeam_debug_information( array() );
	$lines = array(
		'SendBeam ' . SENDBEAM_VERSION,
		'WordPress ' . get_bloginfo( 'version' ) . ' / PHP ' . PHP_VERSION,
		'Site: ' . home_url(),
		'',
	);
	foreach ( $info['sendbeam']['fields'] as $field ) {
		$lines[] = $field['label'] . ': ' . $field['value'];
	}
	return implode( "\n", $lines );
}
