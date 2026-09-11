<?php
/**
 * WPForms: a SendBeam panel under Settings in the form builder, and a handler
 * for completed entries.
 *
 * The panel's values are saved by WPForms with the rest of the form, behind the
 * builder's own nonce and capability checks, and read back here from the form
 * data it passes to every completed entry.
 *
 * @package SendBeam
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'wpforms_builder_settings_sections', 'sendbeam_wpforms_section', 20 );
add_action( 'wpforms_form_settings_panel_content', 'sendbeam_wpforms_panel', 20 );
add_action( 'wpforms_process_complete', 'sendbeam_wpforms_submit', 10, 3 );

/**
 * Add SendBeam to the builder's settings sections.
 *
 * @param array<string,string> $sections Sections.
 * @return array<string,string>
 */
function sendbeam_wpforms_section( $sections ) {
	$sections['sendbeam'] = esc_html__( 'SendBeam', 'sendbeam' );
	return $sections;
}

/**
 * The SendBeam settings panel.
 *
 * @param object $instance The builder's settings panel, carrying form_data.
 */
function sendbeam_wpforms_panel( $instance ) {
	if ( ! function_exists( 'wpforms_panel_field' ) || ! is_object( $instance ) ) {
		return;
	}
	$form_data = isset( $instance->form_data ) ? $instance->form_data : array();

	echo '<div class="wpforms-panel-content-section wpforms-panel-content-section-sendbeam">';
	echo '<div class="wpforms-panel-content-section-title">' . esc_html__( 'SendBeam', 'sendbeam' ) . '</div>';

	wpforms_panel_field( 'checkbox', 'settings', 'sendbeam_enabled', $form_data, esc_html__( 'Send entries from this form to SendBeam', 'sendbeam' ) );
	wpforms_panel_field(
		'select',
		'settings',
		'sendbeam_email_field',
		$form_data,
		esc_html__( 'Email field', 'sendbeam' ),
		array(
			'field_map'   => array( 'email' ),
			'placeholder' => esc_html__( '— Select a field —', 'sendbeam' ),
		)
	);
	wpforms_panel_field(
		'select',
		'settings',
		'sendbeam_name_field',
		$form_data,
		esc_html__( 'Name field', 'sendbeam' ),
		array(
			'field_map'   => array( 'name', 'text' ),
			'placeholder' => esc_html__( '— None —', 'sendbeam' ),
		)
	);
	wpforms_panel_field(
		'select',
		'settings',
		'sendbeam_consent',
		$form_data,
		esc_html__( 'Consent', 'sendbeam' ),
		array(
			'options' => array(
				'field'  => esc_html__( 'Only when the consent field below is ticked', 'sendbeam' ),
				'signup' => esc_html__( 'Everyone who submits it — this is a signup form', 'sendbeam' ),
			),
			'default' => 'field',
		)
	);
	wpforms_panel_field(
		'select',
		'settings',
		'sendbeam_consent_field',
		$form_data,
		esc_html__( 'Consent field', 'sendbeam' ),
		array(
			'field_map'   => array( 'checkbox', 'gdpr-checkbox' ),
			'placeholder' => esc_html__( '— Select a field —', 'sendbeam' ),
		)
	);
	wpforms_panel_field(
		'select',
		'settings',
		'sendbeam_list',
		$form_data,
		esc_html__( 'Add them to', 'sendbeam' ),
		array(
			'options' => array( '' => esc_html__( '— No list —', 'sendbeam' ) ) + sendbeam_bridge_list_choices(),
		)
	);
	wpforms_panel_field(
		'text',
		'settings',
		'sendbeam_tag',
		$form_data,
		esc_html__( 'Tag (optional)', 'sendbeam' )
	);

	echo '</div>';
}

/**
 * A completed entry.
 *
 * @param array $fields    Submitted fields, keyed by field ID.
 * @param array $entry     The raw entry; unused.
 * @param array $form_data The form, including its settings.
 */
function sendbeam_wpforms_submit( $fields, $entry = array(), $form_data = array() ) {
	$settings = isset( $form_data['settings'] ) && is_array( $form_data['settings'] ) ? $form_data['settings'] : array();
	if ( empty( $settings['sendbeam_enabled'] ) || ! is_array( $fields ) ) {
		return;
	}

	$field = function ( $key ) use ( $settings, $fields ) {
		$id = isset( $settings[ $key ] ) ? (string) $settings[ $key ] : '';
		return ( '' !== $id && isset( $fields[ $id ] ) && is_array( $fields[ $id ] ) ) ? $fields[ $id ] : array();
	};

	$name  = $field( 'sendbeam_name_field' );
	$first = isset( $name['first'] ) ? (string) $name['first'] : '';
	$last  = isset( $name['last'] ) ? (string) $name['last'] : '';
	if ( '' === $first && '' === $last && isset( $name['value'] ) ) {
		$parts = explode( ' ', trim( (string) $name['value'] ), 2 );
		$first = $parts[0];
		$last  = isset( $parts[1] ) ? $parts[1] : '';
	}
	$email   = $field( 'sendbeam_email_field' );
	$consent = $field( 'sendbeam_consent_field' );

	sendbeam_bridge_submit(
		'wpforms',
		array(
			'enabled' => 1,
			'list'    => isset( $settings['sendbeam_list'] ) ? $settings['sendbeam_list'] : '',
			'tag'     => isset( $settings['sendbeam_tag'] ) ? $settings['sendbeam_tag'] : '',
			'consent' => isset( $settings['sendbeam_consent'] ) ? $settings['sendbeam_consent'] : 'field',
		),
		array(
			'email'      => isset( $email['value'] ) ? (string) $email['value'] : '',
			'first_name' => $first,
			'last_name'  => $last,
			'consented'  => isset( $consent['value'] ) && sendbeam_bridge_truthy( $consent['value'] ),
		)
	);
}
