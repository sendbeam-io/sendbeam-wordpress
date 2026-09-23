<?php
/**
 * Small shared helpers: settings with defaults, URL building, ID validation.
 *
 * @package SendBeam
 */

defined( 'ABSPATH' ) || exit;

/**
 * The SendBeam app the forms are served from.
 *
 * Hosted forms live at <app>/f/<form id>. Self-hosters can point this
 * elsewhere with the `sendbeam_app_url` filter.
 *
 * @return string Origin without a trailing slash.
 */
function sendbeam_app_url() {
	$url = apply_filters( 'sendbeam_app_url', 'https://sendbeam.io' );
	return untrailingslashit( esc_url_raw( $url ) );
}

/**
 * Default values for every setting, so callers never test for missing keys.
 *
 * @return array<string, string|int>
 */
function sendbeam_default_settings() {
	return array(
		'default_form'               => '',
		'contact_form'               => '',
		'popup_form'                 => '',
		'popup_where'                => 'off',
		'popup_trigger'              => 'timer',
		'popup_delay'                => 5,
		'popup_once'                 => 'day',
		'popup_label'                => '',
		'style_accent'               => '',
		'style_text'                 => '',
		'style_field'                => '',
		'style_border'               => '',
		'style_radius'               => '',
		'style_font'                 => 'inherit',
		'style_size'                 => '',
		'style_bare'                 => 1,
		'mail_enabled'               => 0,
		'api_key'                    => '',
		'mail_from_name'             => '',
		'mail_from_email'            => '',
		'mail_fallback'              => 1,
		// Set only when the key arrived through Connect, so the Overview can
		// name the workspace and offer Disconnect rather than a paste field.
		// Cleared the moment the key is changed or removed by hand.
		'sendbeam_connected_via'     => '',
		'sendbeam_connect_workspace' => '',
		// The permissions SendBeam actually granted this key, as a csv. What
		// was *asked* for is not the same thing — the person can untick a box
		// on the consent page — and the Overview has to say "you did not give
		// this site that permission" rather than guess.
		'sendbeam_connect_granted'   => '',
		// Site email was approved at consent time but held back because the
		// sending domain was not verified yet. The domain check switches it
		// on and clears this; turning site email off by hand clears it too,
		// so a later check never re-enables something deliberately switched
		// off.
		'sendbeam_mail_deferred'     => 0,
	);
}

/**
 * Saved settings merged over the defaults.
 *
 * @return array<string, string|int>
 */
function sendbeam_settings() {
	$saved = get_option( 'sendbeam_settings', array() );
	if ( ! is_array( $saved ) ) {
		$saved = array();
	}
	return array_merge( sendbeam_default_settings(), $saved );
}

/**
 * Whether a string looks like a SendBeam form ID (a UUID).
 *
 * @param mixed $id Candidate.
 * @return bool
 */
function sendbeam_is_form_id( $id ) {
	return is_string( $id ) && 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id );
}

/**
 * Hosted page for a form.
 *
 * @param string $form_id Form ID.
 * @param bool   $embed   Strip the page chrome (for iframes).
 * @return string
 */
function sendbeam_form_url( $form_id, $embed = true ) {
	$url = sendbeam_app_url() . '/f/' . rawurlencode( strtolower( $form_id ) );
	if ( ! $embed ) {
		return $url;
	}
	return add_query_arg( array_merge( array( 'embed' => '1' ), sendbeam_appearance_args() ), $url );
}

/**
 * The appearance parameters the hosted form understands.
 *
 * Only settings the site owner actually chose are sent; anything left blank is
 * omitted so the form keeps SendBeam's own default rather than being handed an
 * empty value. The hosted page validates all of this again on arrival — this
 * end just avoids sending nonsense in the first place.
 *
 * @return array<string,string>
 */
function sendbeam_appearance_args() {
	$s    = sendbeam_settings();
	$args = array();

	foreach ( array(
		'accent' => 'style_accent',
		'text'   => 'style_text',
		'field'  => 'style_field',
		'border' => 'style_border',
	) as $param => $key ) {
		$value = trim( (string) $s[ $key ] );
		if ( '' !== $value && preg_match( '/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i', $value ) ) {
			// add_query_arg() does not encode, and a bare '#' would start the
			// URL fragment — every parameter after it would silently never
			// reach the server.
			$args[ $param ] = rawurlencode( strtolower( $value ) );
		}
	}
	foreach ( array(
		'radius' => 'style_radius',
		'size'   => 'style_size',
	) as $param => $key ) {
		$value = trim( (string) $s[ $key ] );
		if ( '' !== $value && is_numeric( $value ) ) {
			$args[ $param ] = (string) (int) $value;
		}
	}
	$font = trim( (string) $s['style_font'] );
	if ( '' !== $font && in_array( $font, array( 'inherit', 'system', 'sans', 'serif', 'mono' ), true ) ) {
		$args['font'] = $font;
	}
	// The form fills whatever column it is placed in; the theme decides the width.
	$args['width'] = '0';
	if ( ! empty( $s['style_bare'] ) ) {
		$args['bare'] = '1';
	}
	return $args;
}

/**
 * The pop-up loader script for a form.
 *
 * @param string $form_id Form ID.
 * @param array  $popup   The pop-up rule, when there is one: its own look is
 *                        hashed alongside the site-wide appearance.
 * @return string
 */
function sendbeam_popup_script_url( $form_id, $popup = array() ) {
	$url = sendbeam_app_url() . '/f/' . rawurlencode( strtolower( $form_id ) ) . '/popup.js';

	// The loader is served with a four-hour public cache and no version in its
	// path, so without this a change to the pop-up's colours would not reach a
	// returning visitor until their browser felt like asking again. Hashing the
	// appearance (and the plugin version) means the URL changes exactly when
	// the result would change, and stays cacheable the rest of the time.
	// The per-pop-up look — style, image, eyebrow, button label, count — goes
	// into the same fingerprint, so switching a pop-up from split to bold
	// reaches a visitor who already has the old loader cached.
	$look = array();
	foreach ( array( 'style', 'image', 'eyebrow', 'button', 'proof' ) as $key ) {
		if ( isset( $popup[ $key ] ) ) {
			$look[ $key ] = (string) $popup[ $key ];
		}
	}

	$stamp = substr( md5( SENDBEAM_VERSION . wp_json_encode( sendbeam_appearance_args() ) . wp_json_encode( $look ) ), 0, 8 );
	return add_query_arg( 'v', $stamp, $url );
}
