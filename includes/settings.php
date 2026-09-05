<?php
/**
 * Settings → SendBeam.
 *
 * One option (`sendbeam_settings`, an array) holds everything, registered
 * through the Settings API so nonces, capability checks and the "Settings
 * saved" notice come for free.
 *
 * @package SendBeam
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', 'sendbeam_admin_menu' );
add_action( 'admin_init', 'sendbeam_admin_init' );
add_filter( 'plugin_action_links_' . plugin_basename( SENDBEAM_FILE ), 'sendbeam_action_links' );

/**
 * Add the page under Settings.
 */
function sendbeam_admin_menu() {
	add_options_page(
		__( 'SendBeam', 'sendbeam' ),
		__( 'SendBeam', 'sendbeam' ),
		'manage_options',
		'sendbeam',
		'sendbeam_render_settings_page'
	);
}

/**
 * "Settings" link on the Plugins screen.
 *
 * @param string[] $links Existing links.
 * @return string[]
 */
function sendbeam_action_links( $links ) {
	$url = admin_url( 'options-general.php?page=sendbeam' );
	array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'sendbeam' ) . '</a>' );
	return $links;
}

/**
 * Register the option, sections and fields.
 */
function sendbeam_admin_init() {
	register_setting(
		'sendbeam',
		'sendbeam_settings',
		array(
			'type'              => 'array',
			'sanitize_callback' => 'sendbeam_sanitize_settings',
			'default'           => array(),
		)
	);

	add_settings_section( 'sendbeam_forms', __( 'Forms', 'sendbeam' ), 'sendbeam_section_forms_intro', 'sendbeam' );
	add_settings_field( 'default_form', __( 'Default signup form', 'sendbeam' ), 'sendbeam_field_form_id', 'sendbeam', 'sendbeam_forms', array( 'key' => 'default_form', 'help' => __( 'Used by the SendBeam Form block and [sendbeam_form] when no ID is given.', 'sendbeam' ) ) );
	add_settings_field( 'contact_form', __( 'Contact form', 'sendbeam' ), 'sendbeam_field_form_id', 'sendbeam', 'sendbeam_forms', array( 'key' => 'contact_form', 'help' => __( 'Used by [sendbeam_contact]. Messages are emailed to you by SendBeam.', 'sendbeam' ) ) );

	add_settings_section( 'sendbeam_popup', __( 'Pop-up', 'sendbeam' ), 'sendbeam_section_popup_intro', 'sendbeam' );
	add_settings_field( 'popup_form', __( 'Pop-up form', 'sendbeam' ), 'sendbeam_field_form_id', 'sendbeam', 'sendbeam_popup', array( 'key' => 'popup_form', 'help' => __( 'The signup form to show in the pop-up.', 'sendbeam' ) ) );
	add_settings_field( 'popup_where', __( 'Show on', 'sendbeam' ), 'sendbeam_field_select', 'sendbeam', 'sendbeam_popup', array(
		'key'     => 'popup_where',
		'options' => array(
			'off'        => __( 'Nowhere (off)', 'sendbeam' ),
			'everywhere' => __( 'Every page', 'sendbeam' ),
			'posts'      => __( 'Single posts only', 'sendbeam' ),
			'pages'      => __( 'Pages only', 'sendbeam' ),
			'home'       => __( 'Home page only', 'sendbeam' ),
		),
	) );
	add_settings_field( 'popup_trigger', __( 'Open', 'sendbeam' ), 'sendbeam_field_select', 'sendbeam', 'sendbeam_popup', array(
		'key'     => 'popup_trigger',
		'options' => array(
			'timer'  => __( 'After a delay', 'sendbeam' ),
			'button' => __( 'From a floating button', 'sendbeam' ),
		),
	) );
	add_settings_field( 'popup_delay', __( 'Delay (seconds)', 'sendbeam' ), 'sendbeam_field_number', 'sendbeam', 'sendbeam_popup', array( 'key' => 'popup_delay', 'min' => 0, 'max' => 120, 'help' => __( '0 opens it as soon as the page loads. Ignored when opening from a button.', 'sendbeam' ) ) );
	add_settings_field( 'popup_once', __( 'After it is closed', 'sendbeam' ), 'sendbeam_field_select', 'sendbeam', 'sendbeam_popup', array(
		'key'     => 'popup_once',
		'options' => array(
			'day'     => __( 'Stay hidden for a day', 'sendbeam' ),
			'week'    => __( 'Stay hidden for a week', 'sendbeam' ),
			'forever' => __( 'Never show it again', 'sendbeam' ),
			'never'   => __( 'Show it on every visit', 'sendbeam' ),
		),
		'help'    => __( 'Remembered in the visitor\'s browser. A submission counts as a close.', 'sendbeam' ),
	) );
	add_settings_field( 'popup_label', __( 'Button label', 'sendbeam' ), 'sendbeam_field_text', 'sendbeam', 'sendbeam_popup', array( 'key' => 'popup_label', 'placeholder' => __( 'Subscribe', 'sendbeam' ), 'help' => __( 'Text on the floating button, when that trigger is used.', 'sendbeam' ) ) );
}

/**
 * Clean everything the form posts. Unknown keys are dropped.
 *
 * @param mixed $input Raw POST value.
 * @return array<string, string|int>
 */
function sendbeam_sanitize_settings( $input ) {
	$defaults = sendbeam_default_settings();
	$out      = $defaults;
	if ( ! is_array( $input ) ) {
		return $out;
	}

	foreach ( array( 'default_form', 'contact_form', 'popup_form' ) as $key ) {
		$value = isset( $input[ $key ] ) ? trim( sanitize_text_field( wp_unslash( $input[ $key ] ) ) ) : '';
		if ( '' !== $value && ! sendbeam_is_form_id( $value ) ) {
			add_settings_error(
				'sendbeam_settings',
				'sendbeam_bad_' . $key,
				/* translators: %s: the setting's label */
				sprintf( __( '"%s" is not a SendBeam form ID. It looks like 8f3c1a2e-…, and is shown under Forms in SendBeam.', 'sendbeam' ), $value )
			);
			$value = '';
		}
		$out[ $key ] = strtolower( $value );
	}

	$out['popup_where']   = sendbeam_pick( $input, 'popup_where', array( 'off', 'everywhere', 'posts', 'pages', 'home' ), $defaults['popup_where'] );
	$out['popup_trigger'] = sendbeam_pick( $input, 'popup_trigger', array( 'timer', 'button' ), $defaults['popup_trigger'] );
	$out['popup_once']    = sendbeam_pick( $input, 'popup_once', array( 'day', 'week', 'forever', 'never' ), $defaults['popup_once'] );
	$out['popup_delay']   = isset( $input['popup_delay'] ) ? max( 0, min( 120, (int) $input['popup_delay'] ) ) : $defaults['popup_delay'];
	$out['popup_label']   = isset( $input['popup_label'] ) ? sanitize_text_field( wp_unslash( $input['popup_label'] ) ) : '';

	return $out;
}

/**
 * One value out of an allow-list.
 *
 * @param array    $input   Posted values.
 * @param string   $key     Key.
 * @param string[] $allowed Allowed values.
 * @param string   $default Fallback.
 * @return string
 */
function sendbeam_pick( $input, $key, $allowed, $default ) {
	$value = isset( $input[ $key ] ) ? sanitize_key( $input[ $key ] ) : '';
	return in_array( $value, $allowed, true ) ? $value : $default;
}

/** Intro for the Forms section. */
function sendbeam_section_forms_intro() {
	echo '<p>' . wp_kses(
		sprintf(
			/* translators: %s: link to the SendBeam forms page */
			__( 'Form IDs are under %s in SendBeam: open a form and copy the ID from its Embed panel. Forms load from sendbeam.io, so any change you make there shows on your site straight away.', 'sendbeam' ),
			'<a href="' . esc_url( sendbeam_app_url() . '/forms' ) . '" target="_blank" rel="noopener">' . esc_html__( 'Forms', 'sendbeam' ) . '</a>'
		),
		array( 'a' => array( 'href' => array(), 'target' => array(), 'rel' => array() ) )
	) . '</p>';
}

/** Intro for the Pop-up section. */
function sendbeam_section_popup_intro() {
	echo '<p>' . esc_html__( 'Shows a signup form in a modal on the pages you choose. Any link or button with data-sendbeam-open="<form id>" opens it too, and the [sendbeam_popup_button] shortcode makes one for you.', 'sendbeam' ) . '</p>';
}

/**
 * Text field for a form ID.
 *
 * @param array $args Field args (key, help).
 */
function sendbeam_field_form_id( $args ) {
	$settings = sendbeam_settings();
	$key      = $args['key'];
	printf(
		'<input type="text" class="regular-text code" id="sendbeam_%1$s" name="sendbeam_settings[%1$s]" value="%2$s" placeholder="8f3c1a2e-0000-4000-8000-000000000000" spellcheck="false" autocomplete="off" />',
		esc_attr( $key ),
		esc_attr( $settings[ $key ] )
	);
	if ( ! empty( $args['help'] ) ) {
		echo '<p class="description">' . esc_html( $args['help'] ) . '</p>';
	}
}

/**
 * Plain text field.
 *
 * @param array $args Field args (key, placeholder, help).
 */
function sendbeam_field_text( $args ) {
	$settings = sendbeam_settings();
	$key      = $args['key'];
	printf(
		'<input type="text" class="regular-text" id="sendbeam_%1$s" name="sendbeam_settings[%1$s]" value="%2$s" placeholder="%3$s" />',
		esc_attr( $key ),
		esc_attr( $settings[ $key ] ),
		esc_attr( isset( $args['placeholder'] ) ? $args['placeholder'] : '' )
	);
	if ( ! empty( $args['help'] ) ) {
		echo '<p class="description">' . esc_html( $args['help'] ) . '</p>';
	}
}

/**
 * Number field.
 *
 * @param array $args Field args (key, min, max, help).
 */
function sendbeam_field_number( $args ) {
	$settings = sendbeam_settings();
	$key      = $args['key'];
	printf(
		'<input type="number" class="small-text" id="sendbeam_%1$s" name="sendbeam_settings[%1$s]" value="%2$s" min="%3$d" max="%4$d" step="1" />',
		esc_attr( $key ),
		esc_attr( $settings[ $key ] ),
		(int) $args['min'],
		(int) $args['max']
	);
	if ( ! empty( $args['help'] ) ) {
		echo '<p class="description">' . esc_html( $args['help'] ) . '</p>';
	}
}

/**
 * Select field.
 *
 * @param array $args Field args (key, options, help).
 */
function sendbeam_field_select( $args ) {
	$settings = sendbeam_settings();
	$key      = $args['key'];
	echo '<select id="sendbeam_' . esc_attr( $key ) . '" name="sendbeam_settings[' . esc_attr( $key ) . ']">';
	foreach ( $args['options'] as $value => $label ) {
		printf( '<option value="%s"%s>%s</option>', esc_attr( $value ), selected( $settings[ $key ], $value, false ), esc_html( $label ) );
	}
	echo '</select>';
	if ( ! empty( $args['help'] ) ) {
		echo '<p class="description">' . esc_html( $args['help'] ) . '</p>';
	}
}

/**
 * The page itself.
 */
function sendbeam_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'SendBeam', 'sendbeam' ); ?></h1>
		<form action="options.php" method="post">
			<?php
			settings_fields( 'sendbeam' );
			do_settings_sections( 'sendbeam' );
			submit_button();
			?>
		</form>
		<h2><?php esc_html_e( 'How to use', 'sendbeam' ); ?></h2>
		<ul style="list-style:disc;padding-left:1.4em">
			<li><?php echo wp_kses( __( 'Add the <strong>SendBeam Form</strong> block to any post or page (search for "SendBeam" in the block inserter), or use <code>[sendbeam_form id="…"]</code> in a Shortcode block, a widget or a classic editor.', 'sendbeam' ), array( 'strong' => array(), 'code' => array() ) ); ?></li>
			<li><?php echo wp_kses( __( '<code>[sendbeam_contact]</code> shows the contact form chosen above; <code>[sendbeam_form id="…" height="600"]</code> sets a different height.', 'sendbeam' ), array( 'code' => array() ) ); ?></li>
			<li><?php echo wp_kses( __( '<code>[sendbeam_popup_button label="Subscribe"]</code> makes a button that opens the pop-up form on click, on pages where the pop-up is not shown on its own.', 'sendbeam' ), array( 'code' => array() ) ); ?></li>
		</ul>
	</div>
	<?php
}
