<?php
/**
 * Contact Form 7: a SendBeam tab in the form editor, and a submission handler.
 *
 * The tab is stored as post meta on the contact form. Contact Form 7 checks its
 * own nonce and capability before it saves a form; this plugin checks its own
 * as well, and only for site administrators, because the settings decide where
 * people's addresses are sent.
 *
 * @package SendBeam
 */

defined( 'ABSPATH' ) || exit;

const SENDBEAM_CF7_META = '_sendbeam_cf7';

add_filter( 'wpcf7_editor_panels', 'sendbeam_cf7_panels' );
add_action( 'wpcf7_save_contact_form', 'sendbeam_cf7_save' );
add_action( 'wpcf7_before_send_mail', 'sendbeam_cf7_submit', 10, 3 );

/**
 * Settings for one contact form, with Contact Form 7's own default field names.
 *
 * @param int $form_id Contact form ID.
 * @return array<string,mixed>
 */
function sendbeam_cf7_config( $form_id ) {
	$saved = get_post_meta( (int) $form_id, SENDBEAM_CF7_META, true );
	if ( ! is_array( $saved ) || ! $saved ) {
		$saved = array(
			'email_field' => 'your-email',
			'first_field' => 'your-name',
		);
	}
	return sendbeam_bridge_config( $saved );
}

/**
 * Add the SendBeam tab.
 *
 * @param array<string,array<string,mixed>> $panels Editor panels.
 * @return array<string,array<string,mixed>>
 */
function sendbeam_cf7_panels( $panels ) {
	if ( current_user_can( 'manage_options' ) ) {
		$panels['sendbeam-panel'] = array(
			'title'    => __( 'SendBeam', 'sendbeam' ),
			'callback' => 'sendbeam_cf7_panel',
		);
	}
	return $panels;
}

/**
 * The SendBeam tab.
 *
 * @param object $contact_form The contact form (WPCF7_ContactForm).
 */
function sendbeam_cf7_panel( $contact_form ) {
	$config = sendbeam_cf7_config( $contact_form->id() );
	$lists  = sendbeam_bridge_list_choices();

	echo '<h2>' . esc_html__( 'SendBeam', 'sendbeam' ) . '</h2>';
	echo '<fieldset><legend>' . esc_html__( 'Add the people who submit this form to SendBeam. Use the field names from the Form tab, without the square brackets.', 'sendbeam' ) . '</legend>';
	wp_nonce_field( 'sendbeam_cf7_save', 'sendbeam_cf7_nonce' );

	printf(
		'<p><label><input type="checkbox" name="sendbeam_cf7[enabled]" value="1"%1$s /> %2$s</label></p>',
		checked( $config['enabled'], 1, false ),
		esc_html__( 'Send submissions from this form to SendBeam', 'sendbeam' )
	);

	echo '<table class="form-table"><tbody>';
	sendbeam_cf7_text_row( 'email_field', __( 'Email field', 'sendbeam' ), $config['email_field'], 'your-email' );
	sendbeam_cf7_text_row( 'first_field', __( 'Name or first name field', 'sendbeam' ), $config['first_field'], 'your-name' );
	sendbeam_cf7_text_row( 'last_field', __( 'Last name field (optional)', 'sendbeam' ), $config['last_field'], '' );

	echo '<tr><th scope="row">' . esc_html__( 'Consent', 'sendbeam' ) . '</th><td>';
	printf(
		'<p><label><input type="radio" name="sendbeam_cf7[consent]" value="field"%1$s /> %2$s</label> <input type="text" class="regular-text code" name="sendbeam_cf7[consent_field]" value="%3$s" placeholder="acceptance-subscribe" /></p>',
		checked( $config['consent'], 'field', false ),
		esc_html__( 'Only when this acceptance or checkbox field is ticked:', 'sendbeam' ),
		esc_attr( $config['consent_field'] )
	);
	printf(
		'<p><label><input type="radio" name="sendbeam_cf7[consent]" value="signup"%1$s /> %2$s</label></p>',
		checked( $config['consent'], 'signup', false ),
		esc_html__( 'Everyone who submits this form is asking to subscribe — it is a signup form', 'sendbeam' )
	);
	echo '</td></tr>';

	echo '<tr><th scope="row">' . esc_html__( 'Add them to', 'sendbeam' ) . '</th><td>';
	if ( $lists ) {
		foreach ( $lists as $id => $name ) {
			printf(
				'<label style="display:block"><input type="checkbox" name="sendbeam_cf7[lists][]" value="%1$s"%2$s /> %3$s</label>',
				esc_attr( $id ),
				checked( in_array( (string) $id, $config['lists'], true ), true, false ),
				esc_html( $name )
			);
		}
	} else {
		echo '<p class="description">' . esc_html__( 'Your lists appear here once Settings → SendBeam has a key with the Lists (read) permission.', 'sendbeam' ) . '</p>';
	}
	echo '</td></tr>';

	sendbeam_cf7_text_row( 'tag', __( 'Tag (optional)', 'sendbeam' ), $config['tag'], 'contact-form' );
	echo '</tbody></table></fieldset>';
}

/**
 * One text row in the tab.
 *
 * @param string $key         Setting key.
 * @param string $label       Label.
 * @param string $value       Current value.
 * @param string $placeholder Placeholder.
 */
function sendbeam_cf7_text_row( $key, $label, $value, $placeholder ) {
	printf(
		'<tr><th scope="row"><label for="sendbeam_cf7_%1$s">%2$s</label></th><td><input type="text" class="regular-text code" id="sendbeam_cf7_%1$s" name="sendbeam_cf7[%1$s]" value="%3$s" placeholder="%4$s" /></td></tr>',
		esc_attr( $key ),
		esc_html( $label ),
		esc_attr( $value ),
		esc_attr( $placeholder )
	);
}

/**
 * Save the tab with the form.
 *
 * @param object $contact_form The contact form (WPCF7_ContactForm).
 */
function sendbeam_cf7_save( $contact_form ) {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( ! isset( $_POST['sendbeam_cf7_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['sendbeam_cf7_nonce'] ) ), 'sendbeam_cf7_save' ) ) {
		return;
	}
	// Each value is cleaned by sendbeam_bridge_config().
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	$raw = isset( $_POST['sendbeam_cf7'] ) && is_array( $_POST['sendbeam_cf7'] ) ? wp_unslash( $_POST['sendbeam_cf7'] ) : array();
	update_post_meta( (int) $contact_form->id(), SENDBEAM_CF7_META, sendbeam_bridge_config( $raw ) );
}

/**
 * A submission that passed Contact Form 7's validation and spam checks.
 *
 * @param object      $contact_form The contact form (WPCF7_ContactForm).
 * @param bool        $abort        Whether Contact Form 7 was told to stop; unused.
 * @param object|null $submission   The submission (WPCF7_Submission).
 */
function sendbeam_cf7_submit( $contact_form, $abort = false, $submission = null ) {
	if ( ! is_object( $contact_form ) || ! method_exists( $contact_form, 'id' ) ) {
		return;
	}
	$config = sendbeam_cf7_config( $contact_form->id() );
	if ( ! $config['enabled'] ) {
		return;
	}
	if ( ! is_object( $submission ) && class_exists( 'WPCF7_Submission' ) ) {
		$submission = WPCF7_Submission::get_instance();
	}
	if ( ! is_object( $submission ) || ! method_exists( $submission, 'get_posted_data' ) ) {
		return;
	}
	$posted = (array) $submission->get_posted_data();
	sendbeam_bridge_submit( 'contact-form-7', $config, sendbeam_bridge_values_from_map( $posted, $config ) );
}
