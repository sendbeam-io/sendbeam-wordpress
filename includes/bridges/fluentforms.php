<?php
/**
 * Fluent Forms: a handler for new entries.
 *
 * Fluent Forms has no settings panel an outside plugin can add to, so which of
 * its forms send to SendBeam is chosen on the Audience tab of Settings →
 * SendBeam, and kept in this plugin's own option.
 *
 * @package SendBeam
 */

defined( 'ABSPATH' ) || exit;

add_action( 'fluentform/submission_inserted', 'sendbeam_fluentforms_submit', 20, 3 );
add_action( 'fluentform_submission_inserted', 'sendbeam_fluentforms_submit', 20, 3 ); // Fluent Forms before 5.0.

/**
 * Settings for one Fluent form, with Fluent Forms' own default field names.
 *
 * @param int $form_id Form ID.
 * @return array<string,mixed>
 */
function sendbeam_fluentforms_config( $form_id ) {
	$settings = sendbeam_bridge_settings();
	$forms    = isset( $settings['fluentforms'] ) && is_array( $settings['fluentforms'] ) ? $settings['fluentforms'] : array();
	$saved    = isset( $forms[ (string) (int) $form_id ] ) ? $forms[ (string) (int) $form_id ] : array(
		'email_field'   => 'email',
		'first_field'   => 'names.first_name',
		'last_field'    => 'names.last_name',
		'consent_field' => 'gdpr-agreement',
	);
	return sendbeam_bridge_config( $saved );
}

/**
 * A new entry.
 *
 * @param int          $entry_id  Entry ID.
 * @param array        $form_data Submitted values, keyed by field name.
 * @param object|array $form      The form.
 */
function sendbeam_fluentforms_submit( $entry_id, $form_data, $form ) {
	static $seen = array();
	// Newer releases fire the old hook name as well; send each entry once.
	if ( isset( $seen[ (string) $entry_id ] ) ) {
		return;
	}
	$seen[ (string) $entry_id ] = true;

	$form_id = 0;
	if ( is_object( $form ) && isset( $form->id ) ) {
		$form_id = (int) $form->id;
	} elseif ( is_array( $form ) && isset( $form['id'] ) ) {
		$form_id = (int) $form['id'];
	}
	if ( ! $form_id || ! is_array( $form_data ) ) {
		return;
	}

	$config = sendbeam_fluentforms_config( $form_id );
	if ( ! $config['enabled'] ) {
		return;
	}
	sendbeam_bridge_submit( 'fluent-forms', $config, sendbeam_bridge_values_from_map( $form_data, $config ) );
}

/**
 * The site's Fluent forms as id => title, or null when they cannot be listed.
 *
 * @return array<int,string>|null
 */
function sendbeam_fluentforms_forms() {
	if ( ! class_exists( '\FluentForm\App\Models\Form' ) ) {
		return null;
	}
	$out = array();
	foreach ( \FluentForm\App\Models\Form::select( array( 'id', 'title' ) )->orderBy( 'id', 'DESC' )->get() as $form ) {
		$out[ (int) $form->id ] = (string) $form->title;
	}
	return $out;
}
