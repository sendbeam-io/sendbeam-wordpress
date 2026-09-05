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
		'default_form'  => '',
		'contact_form'  => '',
		'popup_form'    => '',
		'popup_where'   => 'off',
		'popup_trigger' => 'timer',
		'popup_delay'   => 5,
		'popup_once'    => 'day',
		'popup_label'   => '',
		'mail_enabled'    => 0,
		'api_key'         => '',
		'mail_from_name'  => '',
		'mail_from_email' => '',
		'mail_fallback'   => 1,
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
	return $embed ? $url . '?embed=1' : $url;
}

/**
 * The pop-up loader script for a form.
 *
 * @param string $form_id Form ID.
 * @return string
 */
function sendbeam_popup_script_url( $form_id ) {
	return sendbeam_app_url() . '/f/' . rawurlencode( strtolower( $form_id ) ) . '/popup.js';
}
