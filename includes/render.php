<?php
/**
 * Front end: the form block, the shortcodes and the pop-up loader.
 *
 * Forms are shown as an iframe of the form's hosted page (`/f/<id>?embed=1`),
 * so the theme's CSS and the form's never meet and the form always matches
 * what is configured in SendBeam. The pop-up is SendBeam's own loader
 * script, printed in the footer with the settings as data attributes.
 *
 * @package SendBeam
 */

defined( 'ABSPATH' ) || exit;

add_action( 'init', 'sendbeam_register_block' );
add_action( 'enqueue_block_editor_assets', 'sendbeam_editor_defaults' );
add_shortcode( 'sendbeam_form', 'sendbeam_shortcode_form' );
add_shortcode( 'sendbeam_contact', 'sendbeam_shortcode_contact' );
add_shortcode( 'sendbeam_popup_button', 'sendbeam_shortcode_popup_button' );
add_action( 'wp_footer', 'sendbeam_print_popup_loader' );

/**
 * Register the block from its block.json.
 */
function sendbeam_register_block() {
	register_block_type( SENDBEAM_DIR . 'blocks/form' );
}

/**
 * Tell the block editor which form is the default, so the block previews
 * without an ID typed in.
 */
function sendbeam_editor_defaults() {
	$settings = sendbeam_settings();
	wp_add_inline_script(
		'sendbeam-form-editor-script',
		'window.sendbeamBlock = ' . wp_json_encode(
			array(
				'defaultForm' => $settings['default_form'],
				'formsUrl'    => sendbeam_app_url() . '/forms',
			)
		) . ';',
		'before'
	);
}

/**
 * The iframe for one form, or '' when the ID is not usable.
 *
 * @param string $form_id Form ID.
 * @param int    $height  Height in px.
 * @param string $title   Accessible name for the frame.
 * @return string HTML.
 */
function sendbeam_form_html( $form_id, $height = 520, $title = '' ) {
	if ( ! sendbeam_is_form_id( $form_id ) ) {
		return '';
	}
	$height = max( 200, min( 2000, (int) $height ) );
	if ( '' === $title ) {
		$title = __( 'Signup form', 'sendbeam' );
	}
	sendbeam_enqueue_relay();
	return sprintf(
		'<iframe class="sendbeam-form" src="%s" title="%s" loading="lazy" referrerpolicy="strict-origin-when-cross-origin" style="display:block;width:100%%;max-width:100%%;height:%dpx;border:0;background:transparent"></iframe>',
		esc_url( sendbeam_form_url( $form_id ) ),
		esc_attr( $title ),
		$height
	);
}

/**
 * A few lines of JS, once per page, that turn the hosted form's
 * "submitted" message into a DOM event a site can listen for
 * (`document.addEventListener('sendbeam:submitted', …)`) — for analytics
 * goals, a thank-you redirect, and so on.
 */
function sendbeam_enqueue_relay() {
	static $done = false;
	if ( $done || is_admin() ) {
		return;
	}
	$done = true;
	wp_register_script( 'sendbeam-relay', false, array(), SENDBEAM_VERSION, true );
	wp_enqueue_script( 'sendbeam-relay' );
	wp_add_inline_script(
		'sendbeam-relay',
		'window.addEventListener("message",function(e){var d=e.data;if(d&&d.sendbeam==="form"&&d.event==="submitted"){document.dispatchEvent(new CustomEvent("sendbeam:submitted",{detail:{formId:d.id}}));}});'
	);
}

/**
 * [sendbeam_form id="…" height="520" title="…"]
 *
 * @param array|string $atts Shortcode attributes.
 * @return string
 */
function sendbeam_shortcode_form( $atts ) {
	$settings = sendbeam_settings();
	$atts     = shortcode_atts(
		array(
			'id'     => $settings['default_form'],
			'height' => 520,
			'title'  => '',
		),
		$atts,
		'sendbeam_form'
	);
	$html = sendbeam_form_html( strtolower( trim( (string) $atts['id'] ) ), (int) $atts['height'], (string) $atts['title'] );
	if ( '' === $html ) {
		return sendbeam_editor_notice( __( 'SendBeam: no form ID. Add id="…" to the shortcode or set a default form under Settings → SendBeam.', 'sendbeam' ) );
	}
	return '<div class="sendbeam-form-wrap">' . $html . '</div>';
}

/**
 * [sendbeam_contact height="640"]
 *
 * @param array|string $atts Shortcode attributes.
 * @return string
 */
function sendbeam_shortcode_contact( $atts ) {
	$settings = sendbeam_settings();
	$atts     = shortcode_atts( array( 'height' => 640 ), $atts, 'sendbeam_contact' );
	$html     = sendbeam_form_html( $settings['contact_form'], (int) $atts['height'], __( 'Contact form', 'sendbeam' ) );
	if ( '' === $html ) {
		return sendbeam_editor_notice( __( 'SendBeam: no contact form chosen under Settings → SendBeam.', 'sendbeam' ) );
	}
	return '<div class="sendbeam-form-wrap sendbeam-contact-wrap">' . $html . '</div>';
}

/**
 * [sendbeam_popup_button label="Subscribe" class="…"]
 *
 * @param array|string $atts Shortcode attributes.
 * @return string
 */
function sendbeam_shortcode_popup_button( $atts ) {
	$settings = sendbeam_settings();
	$atts     = shortcode_atts(
		array(
			'label' => __( 'Subscribe', 'sendbeam' ),
			'class' => 'wp-element-button',
		),
		$atts,
		'sendbeam_popup_button'
	);
	if ( ! sendbeam_is_form_id( $settings['popup_form'] ) ) {
		return sendbeam_editor_notice( __( 'SendBeam: no pop-up form chosen under Settings → SendBeam.', 'sendbeam' ) );
	}
	$GLOBALS['sendbeam_popup_button_used'] = true;
	return sprintf(
		'<button type="button" class="sendbeam-popup-button %s" data-sendbeam-open="%s">%s</button>',
		esc_attr( $atts['class'] ),
		esc_attr( $settings['popup_form'] ),
		esc_html( $atts['label'] )
	);
}

/**
 * Whether the pop-up belongs on the current request, per the "Show on" setting.
 *
 * @param array $settings Plugin settings.
 * @return bool
 */
function sendbeam_popup_wanted_here( $settings ) {
	switch ( $settings['popup_where'] ) {
		case 'everywhere':
			return true;
		case 'posts':
			return is_singular( 'post' );
		case 'pages':
			return is_page();
		case 'home':
			return is_front_page();
		default:
			return false;
	}
}

/**
 * Print the loader in the footer when the pop-up is on for this page, or
 * when a [sendbeam_popup_button] on the page needs it (then the modal only
 * opens from the button).
 */
function sendbeam_print_popup_loader() {
	$settings = sendbeam_settings();
	if ( ! sendbeam_is_form_id( $settings['popup_form'] ) || is_admin() ) {
		return;
	}
	$auto   = sendbeam_popup_wanted_here( $settings );
	$button = ! empty( $GLOBALS['sendbeam_popup_button_used'] );
	if ( ! $auto && ! $button ) {
		return;
	}
	sendbeam_enqueue_relay();
	$attributes = array(
		'src'        => sendbeam_popup_script_url( $settings['popup_form'] ),
		'async'      => true,
		'data-once'  => $settings['popup_once'],
		'data-delay' => (string) ( (int) $settings['popup_delay'] * 1000 ),
	);
	if ( ! $auto ) {
		$attributes['data-trigger'] = 'manual';
	} elseif ( 'button' === $settings['popup_trigger'] ) {
		$attributes['data-trigger'] = 'button';
		if ( '' !== $settings['popup_label'] ) {
			$attributes['data-label'] = $settings['popup_label'];
		}
	}
	wp_print_script_tag( $attributes );
}

/**
 * A message only people who can edit content see; visitors get nothing.
 *
 * @param string $text Message.
 * @return string
 */
function sendbeam_editor_notice( $text ) {
	if ( ! current_user_can( 'edit_posts' ) ) {
		return '';
	}
	return '<p class="sendbeam-notice" style="padding:.75em 1em;border:1px dashed #c3c4c7;color:#50575e">' . esc_html( $text ) . '</p>';
}
