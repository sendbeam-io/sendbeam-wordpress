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
	// Messages are only honoured from the origin serving the forms. Without
	// that check any other frame on the page could resize an embed, or fire a
	// sendbeam:submitted event that a site has wired to an analytics goal —
	// forged conversions, from markup an attacker only needs to get onto the
	// page once.
	wp_add_inline_script(
		'sendbeam-relay',
		'var SB_ORIGIN=' . wp_json_encode( sendbeam_app_url() ) . ';' .
		'window.addEventListener("message",function(e){if(e.origin!==SB_ORIGIN)return;var d=e.data;if(!d||d.sendbeam!=="form")return;if(d.event==="submitted"){document.dispatchEvent(new CustomEvent("sendbeam:submitted",{detail:{formId:d.id}}));return}if(d.event==="height"){var h=parseInt(d.height,10);if(!isFinite(h)||h<120||h>2000)return;var fr=document.querySelectorAll("iframe.sendbeam-form");for(var i=0;i<fr.length;i++){if(fr[i].src.indexOf(d.id)!==-1){fr[i].style.height=h+"px"}}}});'
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
	$html     = sendbeam_form_html( strtolower( trim( (string) $atts['id'] ) ), (int) $atts['height'], (string) $atts['title'] );
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
	$atts = shortcode_atts(
		array(
			'label' => __( 'Subscribe', 'sendbeam' ),
			'class' => '',
			'id'    => '',
		),
		$atts,
		'sendbeam_popup_button'
	);

	// An explicit id wins; otherwise the pop-up that owns this page, otherwise
	// the first rule that has a form at all.
	$form_id = strtolower( trim( (string) $atts['id'] ) );
	if ( ! sendbeam_is_form_id( $form_id ) ) {
		$active  = sendbeam_active_popup();
		$form_id = $active ? $active['form'] : '';
	}
	if ( ! sendbeam_is_form_id( $form_id ) ) {
		foreach ( sendbeam_popups() as $rule ) {
			if ( sendbeam_is_form_id( $rule['form'] ) ) {
				$form_id = $rule['form'];
				break;
			}
		}
	}
	if ( ! sendbeam_is_form_id( $form_id ) ) {
		return sendbeam_editor_notice( __( 'SendBeam: add a pop-up under Settings → SendBeam → Pop-ups first.', 'sendbeam' ) );
	}

	if ( ! isset( $GLOBALS['sendbeam_popup_buttons'] ) || ! is_array( $GLOBALS['sendbeam_popup_buttons'] ) ) {
		$GLOBALS['sendbeam_popup_buttons'] = array();
	}
	$GLOBALS['sendbeam_popup_buttons'][ $form_id ] = $form_id;

	return sprintf(
		'<button type="button" class="sendbeam-popup-button %s" data-sendbeam-open="%s">%s</button>',
		esc_attr( $atts['class'] ),
		esc_attr( $form_id ),
		esc_html( $atts['label'] )
	);
}

/**
 * Print the loader(s) in the footer.
 *
 * At most one pop-up opens by itself — the first rule that matches this page.
 * Any form targeted by a [sendbeam_popup_button] on the page is also loaded,
 * in manual mode, so the button has something to open.
 */
function sendbeam_print_popup_loader() {
	if ( is_admin() ) {
		return;
	}

	$load = array();

	$rule = sendbeam_active_popup();
	if ( $rule ) {
		$load[ $rule['form'] ] = $rule;
	}

	$buttons = isset( $GLOBALS['sendbeam_popup_buttons'] ) && is_array( $GLOBALS['sendbeam_popup_buttons'] )
		? $GLOBALS['sendbeam_popup_buttons']
		: array();
	foreach ( $buttons as $form_id ) {
		if ( ! isset( $load[ $form_id ] ) ) {
			$load[ $form_id ] = array_merge(
				sendbeam_popup_defaults(),
				array(
					'form'    => $form_id,
					'trigger' => 'manual',
				)
			);
		}
	}

	if ( ! $load ) {
		return;
	}

	sendbeam_enqueue_relay();

	foreach ( $load as $form_id => $popup ) {
		$trigger    = $popup['trigger'];
		$attributes = array(
			'src'       => sendbeam_popup_script_url( $form_id, $popup ),
			'async'     => true,
			'data-once' => $popup['once'],
		);
		// The pop-up builds its own iframe URL, so the appearance has to travel
		// with the loader or the modal ends up in SendBeam's default blue on a
		// site that is not blue.
		foreach ( sendbeam_appearance_args() as $param => $value ) {
			if ( 'width' === $param ) {
				continue; // The modal sets its own width.
			}
			$attributes[ 'data-' . str_replace( '_', '-', $param ) ] = rawurldecode( $value );
		}
		if ( ! empty( $popup['heading'] ) ) {
			$attributes['data-heading'] = (string) $popup['heading'];
		}
		if ( ! empty( $popup['blurb'] ) ) {
			$attributes['data-sub'] = (string) $popup['blurb'];
		}

		// The style always travels — the loader's own default is not
		// necessarily the one this plugin offers as its default.
		$attributes['data-style'] = isset( $popup['style'] ) && isset( sendbeam_popup_styles()[ $popup['style'] ] )
			? (string) $popup['style']
			: 'split';
		if ( ! empty( $popup['image'] ) ) {
			$attributes['data-image'] = (string) $popup['image'];
		}
		if ( ! empty( $popup['eyebrow'] ) ) {
			$attributes['data-eyebrow'] = (string) $popup['eyebrow'];
		}
		if ( ! empty( $popup['button'] ) ) {
			$attributes['data-button'] = (string) $popup['button'];
		}
		if ( ! empty( $popup['proof'] ) ) {
			$attributes['data-proof'] = '1';
		}

		if ( 'timer' === $trigger ) {
			$attributes['data-delay'] = (string) ( (int) $popup['delay'] * 1000 );
		} elseif ( 'button' === $trigger ) {
			$attributes['data-trigger'] = 'button';
			if ( '' !== $popup['label'] ) {
				$attributes['data-label'] = $popup['label'];
			}
		} elseif ( 'scroll' === $trigger || 'exit' === $trigger ) {
			// Declared to the loader rather than faked by clicking a hidden
			// opener: that route is the one a visitor takes when they press a
			// button, so it ignores "do not show this again" on purpose.
			$attributes['data-trigger'] = $trigger;
			if ( 'scroll' === $trigger ) {
				$attributes['data-scroll'] = (string) (int) $popup['scroll'];
			}
		} else {
			$attributes['data-trigger'] = 'manual';
		}

		wp_print_script_tag( $attributes );
	}
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
