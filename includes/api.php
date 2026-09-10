<?php
/**
 * Talking to the SendBeam API from the admin screen.
 *
 * Only ever used to *help the user configure the plugin*: to tell them whether
 * their key works and to list their forms so they never have to copy a UUID by
 * hand. The public-facing form embed does not need a key and does not go
 * through here, so a missing or wrong key degrades the settings page and
 * nothing else.
 *
 * Results are cached in transients because these run on every page load of the
 * settings screen, and a slow API should never make wp-admin feel slow.
 *
 * @package SendBeam
 */

defined( 'ABSPATH' ) || exit;

const SENDBEAM_CACHE_CONN  = 'sendbeam_connection';
const SENDBEAM_CACHE_FORMS = 'sendbeam_remote_forms';
const SENDBEAM_CACHE_TTL   = 300; // 5 minutes.

/**
 * GET a path on the SendBeam API with the saved key.
 *
 * @param string $path Path beginning with a slash, e.g. '/api/v1/forms'.
 * @return array{ok:bool,status:int,data:array<mixed>,error:string}
 */
function sendbeam_api_get( $path ) {
	$key = sendbeam_api_key();
	if ( '' === $key ) {
		return array( 'ok' => false, 'status' => 0, 'data' => array(), 'error' => __( 'No API key saved.', 'sendbeam' ) );
	}

	$response = wp_remote_get(
		sendbeam_app_url() . $path,
		array(
			'timeout' => 10,
			'headers' => array(
				'x-api-key' => $key,
				'Accept'    => 'application/json',
			),
		)
	);

	if ( is_wp_error( $response ) ) {
		return array( 'ok' => false, 'status' => 0, 'data' => array(), 'error' => $response->get_error_message() );
	}

	$status = (int) wp_remote_retrieve_response_code( $response );
	$body   = json_decode( wp_remote_retrieve_body( $response ), true );
	$data   = is_array( $body ) ? $body : array();
	$error  = isset( $data['error'] ) ? (string) $data['error'] : '';

	return array(
		'ok'     => $status >= 200 && $status < 300,
		'status' => $status,
		'data'   => $data,
		'error'  => $error,
	);
}

/**
 * What state is the connection in?
 *
 * The distinction between "wrong key" and "right key, missing permission"
 * matters: they need completely different fixes, and a plugin that says only
 * "connection failed" sends people to reissue a key that was fine.
 *
 * @param bool $force Skip the cache.
 * @return array{state:string,message:string,count:int}
 */
function sendbeam_connection( $force = false ) {
	if ( '' === sendbeam_api_key() ) {
		return array( 'state' => 'none', 'message' => '', 'count' => 0 );
	}

	if ( ! $force ) {
		$cached = get_transient( SENDBEAM_CACHE_CONN );
		if ( is_array( $cached ) ) {
			return $cached;
		}
	}

	$result = sendbeam_api_get( '/api/v1/forms' );

	if ( $result['ok'] ) {
		$forms  = isset( $result['data']['forms'] ) && is_array( $result['data']['forms'] ) ? $result['data']['forms'] : array();
		$status = array(
			'state'   => 'ok',
			'message' => '',
			'count'   => count( $forms ),
		);
		set_transient( SENDBEAM_CACHE_FORMS, $forms, SENDBEAM_CACHE_TTL );
	} elseif ( 401 === $result['status'] ) {
		$status = array(
			'state'   => 'rejected',
			'message' => __( 'SendBeam rejected this key. Check you copied all of it, and that it belongs to the workspace you meant.', 'sendbeam' ),
			'count'   => 0,
		);
	} elseif ( 403 === $result['status'] ) {
		$status = array(
			'state'   => 'no_scope',
			'message' => __( 'The key works, but it cannot read your forms, so they cannot be listed here. Either add the Forms (read) permission to it in SendBeam, or enter form IDs by hand below — both work.', 'sendbeam' ),
			'count'   => 0,
		);
	} elseif ( 429 === $result['status'] ) {
		$status = array(
			'state'   => 'unreachable',
			'message' => __( 'SendBeam is rate-limiting this site. Wait a minute and refresh.', 'sendbeam' ),
			'count'   => 0,
		);
	} else {
		$status = array(
			'state'   => 'unreachable',
			'message' => $result['error'] ? $result['error'] : __( 'Could not reach sendbeam.io from this server.', 'sendbeam' ),
			'count'   => 0,
		);
	}

	set_transient( SENDBEAM_CACHE_CONN, $status, SENDBEAM_CACHE_TTL );
	return $status;
}

/**
 * The workspace's forms, or null when they cannot be listed.
 *
 * Null is meaningful and is not the same as an empty array: null means "we
 * could not ask", empty means "you have no forms yet". The settings page says
 * something different in each case.
 *
 * @param bool $force Skip the cache.
 * @return array<int,array<string,mixed>>|null
 */
function sendbeam_remote_forms( $force = false ) {
	if ( ! $force ) {
		$cached = get_transient( SENDBEAM_CACHE_FORMS );
		if ( is_array( $cached ) ) {
			return $cached;
		}
	}

	$connection = sendbeam_connection( $force );
	if ( 'ok' !== $connection['state'] ) {
		return null;
	}

	$cached = get_transient( SENDBEAM_CACHE_FORMS );
	return is_array( $cached ) ? $cached : array();
}

/**
 * Forms of one kind, as id => label, ready for a <select>.
 *
 * @param string $kind 'signup' or 'contact'. Empty for all.
 * @return array<string,string>|null
 */
function sendbeam_form_choices( $kind = '' ) {
	$forms = sendbeam_remote_forms();
	if ( null === $forms ) {
		return null;
	}

	$out = array();
	foreach ( $forms as $form ) {
		if ( ! isset( $form['id'] ) ) {
			continue;
		}
		$form_kind = isset( $form['kind'] ) ? (string) $form['kind'] : 'signup';
		if ( '' !== $kind && $form_kind !== $kind ) {
			continue;
		}
		$name             = isset( $form['name'] ) && '' !== $form['name'] ? (string) $form['name'] : __( '(untitled form)', 'sendbeam' );
		$out[ $form['id'] ] = $name;
	}
	return $out;
}

/**
 * Forget everything cached about the account.
 */
function sendbeam_flush_cache() {
	delete_transient( SENDBEAM_CACHE_CONN );
	delete_transient( SENDBEAM_CACHE_FORMS );
}
