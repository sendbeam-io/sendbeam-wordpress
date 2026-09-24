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
add_action( 'admin_enqueue_scripts', 'sendbeam_admin_assets' );
add_action( 'admin_post_sendbeam_refresh', 'sendbeam_handle_refresh' );

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

	add_settings_section( 'sendbeam_connect', '', 'sendbeam_section_connect_intro', 'sendbeam_connect_page' ); // The card supplies the heading.
	add_settings_field( 'api_key', __( 'API key', 'sendbeam' ), 'sendbeam_field_api_key', 'sendbeam_connect_page', 'sendbeam_connect' );

	add_settings_section( 'sendbeam_forms', '', 'sendbeam_section_forms_intro', 'sendbeam_forms_page' ); // The card supplies the heading.
	add_settings_field(
		'default_form',
		__( 'Default signup form', 'sendbeam' ),
		'sendbeam_field_form_id',
		'sendbeam_forms_page',
		'sendbeam_forms',
		array(
			'key'  => 'default_form',
			'kind' => 'signup',
			'help' => __( 'Used by the SendBeam Form block and [sendbeam_form] when no ID is given.', 'sendbeam' ),
		)
	);
	add_settings_field(
		'contact_form',
		__( 'Contact form', 'sendbeam' ),
		'sendbeam_field_form_id',
		'sendbeam_forms_page',
		'sendbeam_forms',
		array(
			'key'  => 'contact_form',
			'kind' => 'contact',
			'help' => __( 'Used by [sendbeam_contact]. Messages are emailed to you by SendBeam.', 'sendbeam' ),
		)
	);

	add_settings_section( 'sendbeam_mail', '', 'sendbeam_section_mail_intro', 'sendbeam_mail_page' ); // The card supplies the heading.
	add_settings_field(
		'mail_enabled',
		__( 'Site email', 'sendbeam' ),
		'sendbeam_field_checkbox',
		'sendbeam_mail_page',
		'sendbeam_mail',
		array(
			'key'   => 'mail_enabled',
			'label' => __( 'Send this site\'s email through SendBeam', 'sendbeam' ),
			'help'  => __( 'Everything WordPress sends with wp_mail(): order confirmations, password resets, comment and form notifications, plugin alerts.', 'sendbeam' ),
		)
	);
	add_settings_field(
		'mail_from_name',
		__( 'From name', 'sendbeam' ),
		'sendbeam_field_text',
		'sendbeam_mail_page',
		'sendbeam_mail',
		array(
			'key'         => 'mail_from_name',
			'placeholder' => get_bloginfo( 'name' ),
			'class'       => 'sendbeam-when-mail',
			'help'        => __( 'Leave empty to use the name the sending plugin sets, or the workspace sender.', 'sendbeam' ),
		)
	);
	add_settings_field(
		'mail_from_email',
		__( 'From address', 'sendbeam' ),
		'sendbeam_field_text',
		'sendbeam_mail_page',
		'sendbeam_mail',
		array(
			'key'         => 'mail_from_email',
			'placeholder' => 'orders@yourdomain.com',
			'class'       => 'sendbeam-when-mail',
			'help'        => __( 'Must be on a domain verified in the SendBeam workspace. Leave empty to use the workspace sender.', 'sendbeam' ),
		)
	);
	add_settings_field(
		'mail_fallback',
		__( 'If SendBeam cannot send', 'sendbeam' ),
		'sendbeam_field_checkbox',
		'sendbeam_mail_page',
		'sendbeam_mail',
		array(
			'key'   => 'mail_fallback',
			'label' => __( 'Fall back to the server\'s own mailer', 'sendbeam' ),
			'class' => 'sendbeam-when-mail',
			'help'  => __( 'On: a refused or failed message goes out the way it did before this plugin (recommended). Off: it fails and the sending plugin is told.', 'sendbeam' ),
		)
	);
}

/**
 * Clean everything the form posts. Unknown keys are dropped.
 *
 * @param mixed $input Raw POST value.
 * @return array<string, string|int>
 */
function sendbeam_sanitize_settings( $input ) {
	$defaults = sendbeam_default_settings();
	$saved    = sendbeam_settings();
	$out      = $saved;

	if ( ! is_array( $input ) ) {
		return $out;
	}

	// Each tab posts only its own fields. Starting from the saved values and
	// touching only the keys belonging to the posted tab is what stops saving
	// the pop-up from silently clearing the API key — an unchecked checkbox and
	// a field that was never on screen look identical in $_POST otherwise.
	$groups = array(
		'connect' => array( 'api_key' ),
		'forms'   => array( 'default_form', 'contact_form', 'style_accent', 'style_text', 'style_field', 'style_border', 'style_radius', 'style_font', 'style_size', 'style_bare' ),
		'mail'    => array( 'mail_enabled', 'mail_from_name', 'mail_from_email', 'mail_fallback' ),
	);
	$tab    = isset( $input['_tab'] ) ? sanitize_key( $input['_tab'] ) : '';
	$keys   = isset( $groups[ $tab ] ) ? $groups[ $tab ] : array_merge( ...array_values( $groups ) );
	$touch  = array_flip( $keys );

	foreach ( array( 'default_form', 'contact_form' ) as $key ) {
		if ( ! isset( $touch[ $key ] ) ) {
			continue;
		}
		$value = isset( $input[ $key ] ) ? trim( sanitize_text_field( wp_unslash( $input[ $key ] ) ) ) : '';
		if ( '' !== $value && ! sendbeam_is_form_id( $value ) ) {
			add_settings_error(
				'sendbeam_settings',
				'sendbeam_bad_' . $key,
				/* translators: %s: the offending value */
				sprintf( __( '"%s" is not a SendBeam form ID. It looks like 8f3c1a2e-…, and is shown under Forms in SendBeam.', 'sendbeam' ), $value )
			);
			$value = (string) $saved[ $key ];
		}
		$out[ $key ] = strtolower( $value );
	}

	if ( isset( $touch['style_accent'] ) ) {
		foreach ( array( 'style_accent', 'style_text', 'style_field', 'style_border' ) as $key ) {
			$value       = isset( $input[ $key ] ) ? trim( sanitize_text_field( wp_unslash( $input[ $key ] ) ) ) : '';
			$out[ $key ] = ( '' === $value || preg_match( '/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i', $value ) ) ? strtolower( $value ) : (string) $saved[ $key ];
		}
		foreach ( array(
			'style_radius' => 28,
			'style_size'   => 20,
		) as $key => $max ) {
			$value       = isset( $input[ $key ] ) ? trim( sanitize_text_field( wp_unslash( $input[ $key ] ) ) ) : '';
			$out[ $key ] = ( '' === $value ) ? '' : (string) max( 0, min( $max, (int) $value ) );
		}
		$font              = isset( $input['style_font'] ) ? sanitize_key( $input['style_font'] ) : 'inherit';
		$out['style_font'] = in_array( $font, array( 'inherit', 'system', 'sans', 'serif', 'mono' ), true ) ? $font : 'inherit';
		$out['style_bare'] = empty( $input['style_bare'] ) ? 0 : 1;
	}

	if ( isset( $touch['mail_enabled'] ) ) {
		$out['mail_enabled'] = empty( $input['mail_enabled'] ) ? 0 : 1;

		/*
		 * Turning site email off by hand is a decision, and it cancels the
		 * one Connect made on the site owner's behalf. Without this, the next
		 * domain check would helpfully switch it straight back on.
		 */
		if ( empty( $out['mail_enabled'] ) ) {
			$out['sendbeam_mail_deferred'] = 0;
		}
		$out['mail_from_name']  = isset( $input['mail_from_name'] ) ? sanitize_text_field( wp_unslash( $input['mail_from_name'] ) ) : '';
		$from_email             = isset( $input['mail_from_email'] ) ? sanitize_email( wp_unslash( $input['mail_from_email'] ) ) : '';
		$out['mail_from_email'] = $from_email && is_email( $from_email ) ? $from_email : '';
		$out['mail_fallback']   = empty( $input['mail_fallback'] ) ? 0 : 1;
	}

	// A blank key field keeps the saved key; "remove" clears it.
	if ( isset( $touch['api_key'] ) ) {
		$key = isset( $input['api_key'] ) ? trim( sanitize_text_field( wp_unslash( $input['api_key'] ) ) ) : '';
		if ( ! empty( $input['api_key_remove'] ) ) {
			$out['api_key'] = '';
		} elseif ( '' !== $key ) {
			if ( ! preg_match( '/^[A-Za-z0-9_\-]{16,200}$/', $key ) ) {
				add_settings_error( 'sendbeam_settings', 'sendbeam_bad_key', __( 'That does not look like a SendBeam API key. Copy it from Settings → API keys in SendBeam.', 'sendbeam' ) );
				$out['api_key'] = (string) $saved['api_key'];
			} else {
				$out['api_key'] = $key;
			}
		} else {
			$out['api_key'] = (string) $saved['api_key'];
		}
	}

	/*
	 * Saving a tab by hand is the owner taking those settings back. They come
	 * off the list of things Connect filled, so disconnecting later puts back
	 * only what the plugin actually put there — a From address somebody typed
	 * is not Connect's to clear, whoever typed it first.
	 */
	$out['sendbeam_connect_filled'] = isset( $out['sendbeam_connect_filled'] ) && is_array( $out['sendbeam_connect_filled'] ) ? $out['sendbeam_connect_filled'] : array();
	$owned                          = array();
	if ( isset( $touch['mail_enabled'] ) ) {
		$owned = array( 'mail_enabled', 'mail_from_name', 'mail_from_email' );
	} elseif ( isset( $touch['default_form'] ) ) {
		$owned = array( 'default_form' );
	}
	if ( $owned ) {
		$out['sendbeam_connect_filled'] = array_values( array_diff( $out['sendbeam_connect_filled'], $owned ) );
	}

	// A different key means a different workspace, so anything remembered about
	// the old one — whether it worked, which forms it could see — is now a lie.
	// That includes the Connect marker: a key pasted or removed by hand did not
	// come from the Connect button, and the screen must stop claiming it did.
	if ( $out['api_key'] !== (string) $saved['api_key'] ) {
		// Named explicitly, because the cache belongs to the key that wrote
		// it and that key is on its way out.
		sendbeam_connect_forget_status( (string) $saved['api_key'] );
		sendbeam_flush_cache();
		$out['sendbeam_connected_via']        = '';
		$out['sendbeam_connect_workspace']    = '';
		$out['sendbeam_connect_workspace_id'] = '';
		$out['sendbeam_connect_notes']        = array();
		// The permissions and the held-back site email belonged to the old
		// key. A pasted key's permissions are unknown, and claiming the old
		// ones would offer buttons that cannot work.
		$out['sendbeam_connect_granted'] = '';
		$out['sendbeam_mail_deferred']   = 0;
		// Nor is anything this site's settings hold still Connect's doing.
		$out['sendbeam_connect_filled'] = array();
	}

	// A form chosen or cleared changes what the checklist should be looking
	// for on the site, so the cached answer is no longer about this site.
	if ( isset( $touch['default_form'] ) ) {
		delete_transient( 'sendbeam_form_placed' );
	}

	unset( $out['_tab'] );
	return $out;
}

/**
 * One value out of an allow-list.
 *
 * @param array    $input   Posted values.
 * @param string   $key     Key.
 * @param string[] $allowed Allowed values.
 * @param string   $fallback Value to use when the posted one is not allowed.
 * @return string
 */
function sendbeam_pick( $input, $key, $allowed, $fallback ) {
	$value = isset( $input[ $key ] ) ? sanitize_key( $input[ $key ] ) : '';
	return in_array( $value, $allowed, true ) ? $value : $fallback;
}

/** Intro for the Forms section. */
function sendbeam_section_forms_intro() {
	echo '<p>' . wp_kses(
		sprintf(
			/* translators: %s: link to the SendBeam forms page */
			__( 'Form IDs are under %s in SendBeam: open a form and copy the ID from its Embed panel. Forms load from sendbeam.io, so any change you make there shows on your site straight away.', 'sendbeam' ),
			'<a href="' . esc_url( sendbeam_app_url() . '/forms' ) . '" target="_blank" rel="noopener">' . esc_html__( 'Forms', 'sendbeam' ) . '</a>'
		),
		array(
			'a' => array(
				'href'   => array(),
				'target' => array(),
				'rel'    => array(),
			),
		)
	) . '</p>';
}

/** Intro for the Site email section. */
function sendbeam_section_mail_intro() {
	echo '<p>' . esc_html__( 'Optional. Routes the email this site sends to its own users — password resets, receipts, comment and form notifications — through your verified SendBeam domain, without SMTP. The key above needs the "Send site email" permission. Messages with attachments are left to the server\'s mailer.', 'sendbeam' ) . '</p>';
}

/**
 * Checkbox field.
 *
 * @param array $args Field args (key, label, help).
 */
function sendbeam_field_checkbox( $args ) {
	$settings = sendbeam_settings();
	$key      = $args['key'];
	printf(
		'<label><input type="checkbox" id="sendbeam_%1$s" name="sendbeam_settings[%1$s]" value="1"%2$s /> %3$s</label>',
		esc_attr( $key ),
		checked( ! empty( $settings[ $key ] ), true, false ),
		esc_html( $args['label'] )
	);
	if ( ! empty( $args['help'] ) ) {
		echo '<p class="description">' . esc_html( $args['help'] ) . '</p>';
	}
}

/**
 * The API key field: never echoes the saved key back.
 */
function sendbeam_field_api_key() {
	if ( defined( 'SENDBEAM_API_KEY' ) && SENDBEAM_API_KEY ) {
		echo '<p><code>SENDBEAM_API_KEY</code> ' . esc_html__( 'is defined in wp-config.php and is being used.', 'sendbeam' ) . '</p>';
		return;
	}
	$settings = sendbeam_settings();
	$saved    = (string) $settings['api_key'];
	printf(
		'<input type="password" class="regular-text code" id="sendbeam_api_key" name="sendbeam_settings[api_key]" value="" placeholder="%s" autocomplete="new-password" spellcheck="false" />',
		esc_attr( $saved ? str_repeat( '•', 12 ) . substr( $saved, -4 ) : 'sb_…' )
	);
	if ( $saved ) {
		echo ' <label><input type="checkbox" name="sendbeam_settings[api_key_remove]" value="1" /> ' . esc_html__( 'Remove the saved key', 'sendbeam' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'A key is saved. Paste a new one to replace it, or leave this empty to keep it.', 'sendbeam' ) . '</p>';
	} else {
		echo '<p class="description">' . esc_html__( 'Stored in this site\'s options table. For a key that never touches the database, define SENDBEAM_API_KEY in wp-config.php.', 'sendbeam' ) . '</p>';
	}
}


/**
 * Form chooser.
 *
 * A dropdown of the workspace's own forms when we are able to list them, and
 * the original free-text ID field when we are not — because a key limited to
 * `transactional:send` cannot read forms, and that is a perfectly reasonable
 * key to use. Never becomes less capable than it was before it could ask.
 *
 * @param array $args Field args (key, help, kind).
 */
function sendbeam_field_form_id( $args ) {
	$settings = sendbeam_settings();
	$key      = $args['key'];
	$current  = (string) $settings[ $key ];
	$kind     = isset( $args['kind'] ) ? $args['kind'] : '';
	$choices  = sendbeam_form_choices( $kind );

	if ( null === $choices ) {
		printf(
			'<input type="text" class="regular-text code" id="sendbeam_%1$s" name="sendbeam_settings[%1$s]" value="%2$s" placeholder="8f3c1a2e-0000-4000-8000-000000000000" spellcheck="false" autocomplete="off" />',
			esc_attr( $key ),
			esc_attr( $current )
		);
		if ( ! empty( $args['help'] ) ) {
			echo '<p class="description">' . esc_html( $args['help'] ) . '</p>';
		}
		return;
	}

	if ( ! $choices ) {
		echo '<p class="description">' . wp_kses(
			sprintf(
				/* translators: %s: link to create a form */
				__( 'This workspace has no forms yet. %s, then come back and refresh.', 'sendbeam' ),
				'<a href="' . esc_url( sendbeam_app_url() . '/forms' ) . '" target="_blank" rel="noopener">' . esc_html__( 'Create one in SendBeam', 'sendbeam' ) . '</a>'
			),
			array(
				'a' => array(
					'href'   => array(),
					'target' => array(),
					'rel'    => array(),
				),
			)
		) . '</p>';
		printf( '<input type="hidden" name="sendbeam_settings[%1$s]" value="%2$s" />', esc_attr( $key ), esc_attr( $current ) );
		return;
	}

	// One form and nothing chosen yet: preselect it. Making someone pick from a
	// list of one is a question with no information in it.
	$preselect = ( '' === $current && 1 === count( $choices ) ) ? (string) array_key_first( $choices ) : $current;

	printf( '<select id="sendbeam_%1$s" name="sendbeam_settings[%1$s]" class="regular-text">', esc_attr( $key ) );
	printf( '<option value="">%s</option>', esc_html__( '— none —', 'sendbeam' ) );
	foreach ( $choices as $id => $label ) {
		printf(
			'<option value="%s"%s>%s</option>',
			esc_attr( $id ),
			selected( $preselect, $id, false ),
			esc_html( $label )
		);
	}
	// A form saved earlier that this key can no longer see, or of another kind:
	// keep it selectable rather than silently dropping their configuration.
	if ( '' !== $current && ! isset( $choices[ $current ] ) ) {
		printf(
			'<option value="%s" selected>%s</option>',
			esc_attr( $current ),
			esc_html( sprintf( /* translators: %s: form ID */ __( 'Saved form %s', 'sendbeam' ), substr( $current, 0, 8 ) ) )
		);
	}
	echo '</select>';

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
	$tab     = sendbeam_current_tab();
	$screens = array(
		'overview'  => 'sendbeam_screen_overview',
		'forms'     => 'sendbeam_screen_forms',
		'audience'  => 'sendbeam_screen_audience',
		'popup'     => 'sendbeam_screen_popup',
		'mail'      => 'sendbeam_screen_mail',
		'ecommerce' => 'sendbeam_screen_ecommerce',
		'docs'      => 'sendbeam_screen_docs',
	);
	?>
	<div class="wrap sendbeam-app">
		<h1 class="screen-reader-text"><?php esc_html_e( 'SendBeam', 'sendbeam' ); ?></h1>
		<?php sendbeam_render_header(); ?>
		<div class="sb-wrap">
			<?php
			settings_errors( 'sendbeam_settings' );
			call_user_func( $screens[ $tab ] );

			if ( 'forms' === $tab || 'popup' === $tab ) {
				sendbeam_card_open( __( 'Placing forms', 'sendbeam' ) );
				echo '<ul style="list-style:disc;padding-left:1.3em;margin:0">';
				echo '<li>' . wp_kses( __( 'Add the <strong>SendBeam Form</strong> block to any post or page and pick the form from its dropdown — search "SendBeam" in the block inserter.', 'sendbeam' ), array( 'strong' => array() ) ) . '</li>';
				echo '<li>' . wp_kses( __( '<code>[sendbeam_form id="…"]</code> places any form, as often as you like. <code>height="600"</code> sets a different height.', 'sendbeam' ), array( 'code' => array() ) ) . '</li>';
				echo '<li>' . wp_kses( __( '<code>[sendbeam_contact]</code> shows the contact form chosen above.', 'sendbeam' ), array( 'code' => array() ) ) . '</li>';
				echo '<li>' . wp_kses( __( '<code>[sendbeam_popup_button label="Subscribe"]</code> opens the pop-up on click, on pages where it is not shown by itself.', 'sendbeam' ), array( 'code' => array() ) ) . '</li>';
				echo '</ul>';
				sendbeam_card_close();
			}
			?>
		</div>
	</div>
	<?php
}

/**
 * A three-line "where am I" at the top of the page.
 *
 * Not a wizard with its own screens: the steps link to the sections already
 * below them. Someone who knows what they are doing scrolls straight past;
 * someone who does not gets an order of operations. That is the whole of the
 * onboarding, deliberately.
 */

/** Intro for the Connect section: the live state of the key. */
function sendbeam_section_connect_intro() {
	$connection = sendbeam_connection();

	echo '<p>' . wp_kses(
		sprintf(
			/* translators: %s: link to the API keys page */
			__( 'One key connects this site to a SendBeam workspace. Make one under %s. Give it <strong>Forms (read)</strong> so this page can list your forms, and <strong>Send site email</strong> if you want WordPress email to go through SendBeam.', 'sendbeam' ),
			'<a href="' . esc_url( sendbeam_app_url() . '/settings/api-keys' ) . '" target="_blank" rel="noopener">' . esc_html__( 'Settings → API keys', 'sendbeam' ) . '</a>'
		),
		array(
			'a'      => array(
				'href'   => array(),
				'target' => array(),
				'rel'    => array(),
			),
			'strong' => array(),
		)
	) . '</p>';

	if ( 'none' === $connection['state'] ) {
		return;
	}

	$refresh = wp_nonce_url( admin_url( 'admin-post.php?action=sendbeam_refresh' ), 'sendbeam_refresh' );

	if ( 'ok' === $connection['state'] ) {
		$text = sprintf(
			/* translators: %d: number of forms */
			_n( 'Connected. %d form found in this workspace.', 'Connected. %d forms found in this workspace.', $connection['count'], 'sendbeam' ),
			$connection['count']
		);
		$class = 'sendbeam-status is-ok';
	} elseif ( 'no_scope' === $connection['state'] ) {
		$text  = $connection['message'];
		$class = 'sendbeam-status is-warn';
	} else {
		$text  = $connection['message'];
		$class = 'sendbeam-status is-bad';
	}
	?>
	<p class="<?php echo esc_attr( $class ); ?>">
		<span><?php echo esc_html( $text ); ?></span>
		<a href="<?php echo esc_url( $refresh ); ?>"><?php esc_html_e( 'Check again', 'sendbeam' ); ?></a>
	</p>
	<?php
}

/** "Check again" — drop the cache and re-ask. */
function sendbeam_handle_refresh() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'sendbeam' ) );
	}
	check_admin_referer( 'sendbeam_refresh' );
	sendbeam_flush_cache();
	sendbeam_connection( true );
	wp_safe_redirect( admin_url( 'options-general.php?page=sendbeam' ) );
	exit;
}

/**
 * Styles and the progressive-disclosure script, on our page only.
 *
 * @param string $hook Current admin page.
 */
function sendbeam_admin_assets( $hook ) {
	if ( 'settings_page_sendbeam' !== $hook ) {
		return;
	}

	// The pop-up tab offers a picture; that is the only screen that needs the
	// media frame, so it is the only one that loads it.
	if ( 'popup' === sendbeam_current_tab() ) {
		wp_enqueue_media();
	}

	wp_register_style( 'sendbeam-admin', false, array(), SENDBEAM_VERSION );
	wp_enqueue_style( 'sendbeam-admin' );
	wp_add_inline_style( 'sendbeam-admin', sendbeam_admin_css() );

	// Two small behaviours: hide the detail fields of a feature that is off,
	// and copy a shortcode without selecting it by hand.
	$js = '
	( function () {
		function toggle( cls, on ) {
			document.querySelectorAll( "tr." + cls ).forEach( function ( tr ) { tr.hidden = ! on; } );
		}
		var mail = document.getElementById( "sendbeam_mail_enabled" );
		function sync() {
			if ( mail ) { toggle( "sendbeam-when-mail", mail.checked ); }
		}
		if ( mail ) { mail.addEventListener( "change", sync ); }
		sync();

		// Repeatable pop-up rules.
		var host = document.getElementById( "sb-popups" );
		var tpl  = document.getElementById( "sb-popup-template" );
		var addBtn = document.getElementById( "sb-add-popup" );
		function syncRule( rule ) {
			var where = rule.querySelector( ".sb-where" ), trig = rule.querySelector( ".sb-trigger" );
			var urlBox = rule.querySelector( ".sb-when-url" );
			if ( where && urlBox ) { urlBox.hidden = where.value !== "url"; }
			if ( trig ) {
				var map = { timer: ".sb-when-timer", scroll: ".sb-when-scroll", button: ".sb-when-button" };
				Object.keys( map ).forEach( function ( k ) {
					var box = rule.querySelector( map[ k ] );
					if ( box ) { box.hidden = trig.value !== k; }
				} );
			}
		}
		if ( host ) {
			host.querySelectorAll( ".sb-rule" ).forEach( syncRule );
			host.addEventListener( "change", function ( e ) {
				var rule = e.target.closest( ".sb-rule" );
				if ( rule ) { syncRule( rule ); }
			} );
			host.addEventListener( "click", function ( e ) {
				if ( ! e.target.closest( ".sb-remove" ) ) { return; }
				var rule = e.target.closest( ".sb-rule" );
				if ( rule ) { rule.remove(); }
			} );
		}
		if ( addBtn && tpl && host ) {
			addBtn.addEventListener( "click", function () {
				var i = host.querySelectorAll( ".sb-rule" ).length;
				var html = tpl.innerHTML.split( "__i__" ).join( String( i ) );
				var box = document.createElement( "div" );
				box.innerHTML = html;
				var rule = box.firstElementChild;
				host.appendChild( rule );
				syncRule( rule );
				var first = rule.querySelector( "select, input" );
				if ( first ) { first.focus(); }
			} );
		}

		// "Choose" opens the Media Library and writes the URL into the field
		// beside it. Delegated, so a pop-up added after the page loaded works.
		document.addEventListener( "click", function ( e ) {
			var pick = e.target.closest( ".sb-image-pick" );
			if ( ! pick || ! window.wp || ! wp.media ) { return; }
			e.preventDefault();
			var field = pick.parentNode.querySelector( ".sb-image" );
			var frame = wp.media( { title: "Choose an image", library: { type: "image" }, button: { text: "Use this image" }, multiple: false } );
			frame.on( "select", function () {
				var img = frame.state().get( "selection" ).first().toJSON();
				if ( field && img && img.url ) { field.value = img.url; }
			} );
			frame.open();
		} );

		/*
		 * Inline confirmation, in place of window.confirm(): a browser dialog
		 * cannot explain what Disconnect costs, and Chrome suppresses repeat
		 * dialogs on a page anyway. The markup ships with the confirmation
		 * open and the plain button hidden, so a page with no JavaScript is
		 * still usable — this swaps them round the moment script runs.
		 */
		document.querySelectorAll( ".sb-confirm" ).forEach( function ( form ) {
			var ask = form.querySelector( ".sb-confirm__ask" );
			var box = form.querySelector( ".sb-confirm__box" );
			var no  = form.querySelector( ".sb-confirm__cancel" );
			if ( ! ask || ! box ) { return; }
			function shut() { box.hidden = true; ask.hidden = false; }
			shut();
			ask.addEventListener( "click", function () {
				ask.hidden = true;
				box.hidden = false;
				var yes = box.querySelector( "button[type=submit]" );
				if ( yes ) { yes.focus(); }
			} );
			if ( no ) { no.addEventListener( "click", function () { shut(); ask.focus(); } ); }
		} );

		document.addEventListener( "click", function ( e ) {
			var btn = e.target.closest( ".sb-copy" );
			if ( ! btn ) { return; }
			var text = btn.getAttribute( "data-copy" ), done = btn.getAttribute( "data-done" ) || "Copied";
			function flash() {
				var was = btn.textContent;
				btn.textContent = done;
				setTimeout( function () { btn.textContent = was; }, 1400 );
			}
			if ( navigator.clipboard && window.isSecureContext ) {
				navigator.clipboard.writeText( text ).then( flash );
			} else {
				var ta = document.createElement( "textarea" );
				ta.value = text; ta.style.position = "fixed"; ta.style.opacity = "0";
				document.body.appendChild( ta ); ta.select();
				try { document.execCommand( "copy" ); flash(); } catch ( err ) {}
				document.body.removeChild( ta );
			}
		} );
	}() );
	';
	wp_register_script( 'sendbeam-admin', '', array(), SENDBEAM_VERSION, true );
	wp_enqueue_script( 'sendbeam-admin' );
	wp_add_inline_script( 'sendbeam-admin', $js . sendbeam_connect_admin_js() );
}
