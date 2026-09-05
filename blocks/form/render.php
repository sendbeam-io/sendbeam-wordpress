<?php
/**
 * Front-end output for the SendBeam Form block.
 *
 * @package SendBeam
 * @var array $attributes Block attributes.
 */

defined( 'ABSPATH' ) || exit;

$sendbeam_settings = sendbeam_settings();
$sendbeam_form_id  = isset( $attributes['formId'] ) && '' !== $attributes['formId'] ? strtolower( (string) $attributes['formId'] ) : $sendbeam_settings['default_form'];
$sendbeam_height   = isset( $attributes['height'] ) ? (int) $attributes['height'] : 520;
$sendbeam_html     = sendbeam_form_html( $sendbeam_form_id, $sendbeam_height );

if ( '' === $sendbeam_html ) {
	echo sendbeam_editor_notice( __( 'SendBeam: choose a form ID in the block settings, or set a default form under Settings → SendBeam.', 'sendbeam' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
	return;
}

echo '<div ' . get_block_wrapper_attributes( array( 'class' => 'sendbeam-form-wrap' ) ) . '>' . $sendbeam_html . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- both parts are escaped when built.
