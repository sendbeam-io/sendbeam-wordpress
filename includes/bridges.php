<?php
/**
 * Form plugins: sending submissions from forms built with Contact Form 7,
 * Elementor Pro, WPForms, Gravity Forms and Fluent Forms to SendBeam.
 *
 * Each form plugin is configured in its own place — a tab in the Contact Form 7
 * editor, an action after submit in Elementor, a settings panel in the WPForms
 * builder, a feed in Gravity Forms — and every one of them ends up here, in the
 * same three steps:
 *
 *   1. The form's own settings become one plain configuration array.
 *   2. The submission is checked for consent. Either the owner named a consent
 *      field and it was ticked, or the owner said the form exists to sign people
 *      up. Nothing else counts, and nothing is ever pre-ticked by this plugin.
 *   3. The subscription is queued as a single cron event, so the visitor's form
 *      finishes at its normal speed and a slow or failed request to SendBeam
 *      can never stop the form's own email or entry from being saved.
 *
 * The bridge for a form plugin is only loaded when that plugin is active.
 *
 * @package SendBeam
 */

defined( 'ABSPATH' ) || exit;

const SENDBEAM_BRIDGE_HOOK   = 'sendbeam_bridge_subscribe';
const SENDBEAM_BRIDGE_OPTION = 'sendbeam_bridges';

add_action( SENDBEAM_BRIDGE_HOOK, 'sendbeam_bridge_run' );
add_action( 'plugins_loaded', 'sendbeam_bridges_load', 20 );

// These two fire only when their host plugin exists, and load nothing until
// they do. Gravity Forms announces itself while plugins load, so its hook has
// to be listened for from the start rather than from plugins_loaded.
add_action( 'gform_loaded', 'sendbeam_bridge_load_gravityforms', 5 );
add_action( 'elementor_pro/forms/actions/register', 'sendbeam_bridge_register_elementor' );

/**
 * Which form plugins are active on this site.
 *
 * @return array<string,bool>
 */
function sendbeam_bridge_hosts() {
	return array(
		'cf7'          => defined( 'WPCF7_VERSION' ),
		'elementor'    => defined( 'ELEMENTOR_PRO_VERSION' ),
		'wpforms'      => function_exists( 'wpforms' ),
		'gravityforms' => class_exists( 'GFForms' ),
		'fluentforms'  => defined( 'FLUENTFORM' ) || defined( 'FLUENTFORM_VERSION' ),
	);
}

/**
 * Load the bridges for the form plugins that are active.
 */
function sendbeam_bridges_load() {
	$hosts = sendbeam_bridge_hosts();
	if ( $hosts['cf7'] ) {
		require_once SENDBEAM_DIR . 'includes/bridges/cf7.php';
	}
	if ( $hosts['wpforms'] ) {
		require_once SENDBEAM_DIR . 'includes/bridges/wpforms.php';
	}
	if ( $hosts['fluentforms'] ) {
		require_once SENDBEAM_DIR . 'includes/bridges/fluentforms.php';
	}
	if ( $hosts['gravityforms'] && did_action( 'gform_loaded' ) ) {
		sendbeam_bridge_load_gravityforms();
	}
}

/**
 * Register the Gravity Forms add-on.
 */
function sendbeam_bridge_load_gravityforms() {
	static $done = false;
	if ( $done || ! class_exists( 'GFForms' ) || ! method_exists( 'GFForms', 'include_feed_addon_framework' ) ) {
		return;
	}
	$done = true;
	GFForms::include_feed_addon_framework();
	require_once SENDBEAM_DIR . 'includes/bridges/class-sendbeam-gf-addon.php';
	GFAddOn::register( 'Sendbeam_GF_Addon' );
}

/**
 * Register the Elementor Pro form action.
 *
 * @param object $registrar Elementor Pro's form actions registrar.
 */
function sendbeam_bridge_register_elementor( $registrar ) {
	if ( ! class_exists( '\ElementorPro\Modules\Forms\Classes\Action_Base' ) || ! is_object( $registrar ) ) {
		return;
	}
	require_once SENDBEAM_DIR . 'includes/bridges/class-sendbeam-elementor-action.php';
	$registrar->register( new Sendbeam_Elementor_Action() );
}

/**
 * A field reference as the form plugin names it: a field name, an ID, or a
 * dotted path into a group field such as `names.first_name`.
 *
 * @param mixed $value Raw value.
 * @return string
 */
function sendbeam_bridge_field_ref( $value ) {
	if ( ! is_scalar( $value ) ) {
		return '';
	}
	return substr( (string) preg_replace( '/[^A-Za-z0-9_\-\.\[\]:]/', '', (string) $value ), 0, 100 );
}

/**
 * One form's SendBeam settings, cleaned, with every key present.
 *
 * @param mixed $raw Settings as the form plugin stored them.
 * @return array{enabled:int,lists:string[],tag:string,consent:string,email_field:string,first_field:string,last_field:string,consent_field:string}
 */
function sendbeam_bridge_config( $raw ) {
	$raw   = is_array( $raw ) ? $raw : array();
	$lists = isset( $raw['lists'] ) && is_array( $raw['lists'] ) ? $raw['lists'] : array();
	if ( isset( $raw['list'] ) && is_scalar( $raw['list'] ) && '' !== (string) $raw['list'] ) {
		$lists[] = (string) $raw['list'];
	}
	$clean = array();
	foreach ( $lists as $list ) {
		$list = is_scalar( $list ) ? sanitize_text_field( (string) $list ) : '';
		if ( '' !== $list && ! in_array( $list, $clean, true ) ) {
			$clean[] = $list;
		}
	}

	return array(
		'enabled'       => empty( $raw['enabled'] ) ? 0 : 1,
		'lists'         => $clean,
		'tag'           => isset( $raw['tag'] ) && is_scalar( $raw['tag'] ) ? substr( sanitize_text_field( (string) $raw['tag'] ), 0, 60 ) : '',
		'consent'       => isset( $raw['consent'] ) && 'signup' === $raw['consent'] ? 'signup' : 'field',
		'email_field'   => isset( $raw['email_field'] ) ? sendbeam_bridge_field_ref( $raw['email_field'] ) : '',
		'first_field'   => isset( $raw['first_field'] ) ? sendbeam_bridge_field_ref( $raw['first_field'] ) : '',
		'last_field'    => isset( $raw['last_field'] ) ? sendbeam_bridge_field_ref( $raw['last_field'] ) : '',
		'consent_field' => isset( $raw['consent_field'] ) ? sendbeam_bridge_field_ref( $raw['consent_field'] ) : '',
	);
}

/**
 * A submitted value as one line of text. Checkbox and acceptance fields arrive
 * as arrays of the ticked options.
 *
 * @param mixed $value Submitted value.
 * @return string
 */
function sendbeam_bridge_text( $value ) {
	if ( is_array( $value ) ) {
		$parts = array();
		foreach ( $value as $part ) {
			if ( is_scalar( $part ) && '' !== trim( (string) $part ) ) {
				$parts[] = trim( (string) $part );
			}
		}
		return implode( ' ', $parts );
	}
	return is_scalar( $value ) ? trim( (string) $value ) : '';
}

/**
 * Whether a consent field was ticked.
 *
 * @param mixed $value Submitted value.
 * @return bool
 */
function sendbeam_bridge_truthy( $value ) {
	$text = strtolower( sendbeam_bridge_text( $value ) );
	return '' !== $text && ! in_array( $text, array( '0', 'false', 'off', 'no' ), true );
}

/**
 * Look a field up in submitted data, following a dotted path into groups.
 *
 * @param array  $data Submitted data, keyed by field name or ID.
 * @param string $ref  Field reference.
 * @return mixed
 */
function sendbeam_bridge_lookup( $data, $ref ) {
	if ( '' === $ref || ! is_array( $data ) ) {
		return '';
	}
	if ( array_key_exists( $ref, $data ) ) {
		return $data[ $ref ];
	}
	$node = $data;
	foreach ( explode( '.', $ref ) as $step ) {
		if ( ! is_array( $node ) || ! array_key_exists( $step, $node ) ) {
			return '';
		}
		$node = $node[ $step ];
	}
	return $node;
}

/**
 * The values SendBeam needs, read out of a submission by the field references
 * in a form's settings.
 *
 * @param array $data   Submitted data.
 * @param array $config Settings from sendbeam_bridge_config().
 * @return array{email:string,first_name:string,last_name:string,consented:bool}
 */
function sendbeam_bridge_values_from_map( $data, $config ) {
	$first = sendbeam_bridge_text( sendbeam_bridge_lookup( $data, $config['first_field'] ) );
	$last  = sendbeam_bridge_text( sendbeam_bridge_lookup( $data, $config['last_field'] ) );

	// One "Your name" field is the most common form there is: split it rather
	// than filing the whole name as a first name.
	if ( '' === $config['last_field'] && false !== strpos( $first, ' ' ) ) {
		list( $first, $last ) = explode( ' ', $first, 2 );
	}

	return array(
		'email'      => sendbeam_bridge_text( sendbeam_bridge_lookup( $data, $config['email_field'] ) ),
		'first_name' => $first,
		'last_name'  => trim( $last ),
		'consented'  => '' !== $config['consent_field'] && sendbeam_bridge_truthy( sendbeam_bridge_lookup( $data, $config['consent_field'] ) ),
	);
}

/**
 * Queue a submission for SendBeam, if the form is set up for it and the person
 * consented.
 *
 * @param string $source Where the contact came from, recorded in SendBeam.
 * @param mixed  $config The form's settings.
 * @param array  $values Values from sendbeam_bridge_values_from_map() or the form plugin's own reader.
 * @return bool Whether a subscription was queued.
 */
function sendbeam_bridge_submit( $source, $config, $values ) {
	$config = sendbeam_bridge_config( $config );
	if ( ! $config['enabled'] || '' === sendbeam_api_key() ) {
		return false;
	}
	if ( 'signup' !== $config['consent'] && empty( $values['consented'] ) ) {
		return false;
	}

	$email = sanitize_email( isset( $values['email'] ) ? (string) $values['email'] : '' );
	if ( '' === $email || ! is_email( $email ) ) {
		return false;
	}

	$job = array(
		'email'      => $email,
		'first_name' => substr( sanitize_text_field( isset( $values['first_name'] ) ? (string) $values['first_name'] : '' ), 0, 100 ),
		'last_name'  => substr( sanitize_text_field( isset( $values['last_name'] ) ? (string) $values['last_name'] : '' ), 0, 100 ),
		'source'     => sanitize_key( $source ),
		'lists'      => $config['lists'],
		'tag'        => $config['tag'],
	);

	// Straight away, on the next request to the site: the visitor's form has
	// already finished by the time this runs.
	wp_schedule_single_event( time(), SENDBEAM_BRIDGE_HOOK, array( $job ) );
	return true;
}

/**
 * The queued subscription itself.
 *
 * @param mixed $job What sendbeam_bridge_submit() queued.
 */
function sendbeam_bridge_run( $job ) {
	if ( ! is_array( $job ) || empty( $job['email'] ) || ! is_email( $job['email'] ) ) {
		return;
	}
	sendbeam_subscribe(
		(string) $job['email'],
		isset( $job['first_name'] ) ? (string) $job['first_name'] : '',
		isset( $job['last_name'] ) ? (string) $job['last_name'] : '',
		isset( $job['source'] ) ? (string) $job['source'] : 'wordpress-form',
		isset( $job['lists'] ) && is_array( $job['lists'] ) ? $job['lists'] : array(),
		isset( $job['tag'] ) ? (string) $job['tag'] : ''
	);
}

/**
 * The workspace's lists as id => name, for a form plugin's dropdown.
 *
 * Form plugins can build their settings screens during a visitor's form
 * submission as well as in the editor, so outside the editor this only reads
 * what is already cached and never calls SendBeam.
 *
 * @return array<string,string>
 */
function sendbeam_bridge_list_choices() {
	$lists = wp_doing_ajax() ? get_transient( SENDBEAM_CACHE_LISTS ) : sendbeam_lists();
	$out   = array();
	if ( is_array( $lists ) ) {
		foreach ( $lists as $list ) {
			if ( isset( $list['id'], $list['name'] ) ) {
				$out[ (string) $list['id'] ] = (string) $list['name'];
			}
		}
	}
	return $out;
}

/**
 * Settings kept by this plugin for form plugins that have nowhere of their own
 * to put them (Fluent Forms).
 *
 * @return array<string,mixed>
 */
function sendbeam_bridge_settings() {
	$saved = get_option( SENDBEAM_BRIDGE_OPTION, array() );
	return is_array( $saved ) ? $saved : array();
}

add_action( 'admin_post_sendbeam_save_bridges', 'sendbeam_handle_save_bridges' );

/** Save the Fluent Forms settings from the Audience screen. */
function sendbeam_handle_save_bridges() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'sendbeam' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'sendbeam_save_bridges' );

	// Each value is cleaned by sendbeam_bridge_config().
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	$posted = isset( $_POST['sendbeam_fluentforms'] ) && is_array( $_POST['sendbeam_fluentforms'] ) ? wp_unslash( $_POST['sendbeam_fluentforms'] ) : array();

	$forms = array();
	foreach ( $posted as $form_id => $raw ) {
		$form_id = absint( $form_id );
		if ( $form_id ) {
			$forms[ (string) $form_id ] = sendbeam_bridge_config( $raw );
		}
	}

	$settings                = sendbeam_bridge_settings();
	$settings['fluentforms'] = $forms;
	update_option( SENDBEAM_BRIDGE_OPTION, $settings, false );
	wp_safe_redirect( add_query_arg( 'sendbeam_bridges_saved', '1', sendbeam_tab_url( 'audience' ) ) . '#sendbeam-form-plugins' );
	exit;
}
