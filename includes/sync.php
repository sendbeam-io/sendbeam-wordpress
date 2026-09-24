<?php
/**
 * Subscribing people who are already doing something else on the site:
 * registering an account, leaving a comment, or checking out in WooCommerce.
 *
 * Three rules this file will not bend on, because getting them wrong is a
 * legal problem for the site owner rather than a bug:
 *
 *   1. Nobody is subscribed without ticking a box. There is no "subscribe
 *      everyone" switch and the checkbox defaults to unticked, because a
 *      pre-ticked consent box is not consent under the GDPR.
 *   2. Existing users are never bulk-imported. They never agreed to anything,
 *      and a plugin that quietly uploads a customer table is how a site ends
 *      up sending mail nobody asked for.
 *   3. A failure here never breaks the thing the visitor was actually doing.
 *      Registration completes, the comment posts, the order goes through;
 *      the subscription is attempted afterwards and only logged if it fails.
 *
 * Which list someone lands on is chosen here; whether they must confirm by
 * email is the list's own double opt-in setting in SendBeam.
 *
 * @package SendBeam
 */

defined( 'ABSPATH' ) || exit;

const SENDBEAM_SYNC_OPTION = 'sendbeam_sync';
const SENDBEAM_SYNC_LOG    = 'sendbeam_sync_log';

add_action( 'admin_post_sendbeam_save_sync', 'sendbeam_handle_save_sync' );

// Registration.
add_action( 'register_form', 'sendbeam_sync_registration_field' );
add_action( 'user_register', 'sendbeam_sync_on_register' );

// Comments. The box goes immediately above the submit button, where someone is
// actually deciding — `comment_form_submit_field` is the one hook that fires
// for logged-in and logged-out visitors alike, so it needs no companion.
add_filter( 'comment_form_submit_field', 'sendbeam_sync_comment_field' );
add_action( 'comment_post', 'sendbeam_sync_on_comment', 10, 2 );

// WooCommerce.
add_action( 'woocommerce_review_order_before_submit', 'sendbeam_sync_checkout_field' );
add_action( 'woocommerce_checkout_order_processed', 'sendbeam_sync_on_checkout', 10, 1 );

/**
 * Defaults.
 *
 * @return array<string,mixed>
 */
function sendbeam_sync_defaults() {
	return array(
		'registration' => 0,
		'comments'     => 0,
		'woocommerce'  => 0,
		'lists'        => array(),
		'label'        => '',
	);
}

/**
 * Saved sync settings.
 *
 * @return array<string,mixed>
 */
function sendbeam_sync_settings() {
	$saved = get_option( SENDBEAM_SYNC_OPTION, array() );
	if ( ! is_array( $saved ) ) {
		$saved = array();
	}
	$out          = array_merge( sendbeam_sync_defaults(), $saved );
	$out['lists'] = is_array( $out['lists'] ) ? array_values( array_filter( array_map( 'strval', $out['lists'] ) ) ) : array();
	return $out;
}

/**
 * The consent wording shown next to the box.
 *
 * @return string
 */
function sendbeam_sync_label() {
	$settings = sendbeam_sync_settings();
	$label    = trim( (string) $settings['label'] );
	return '' !== $label ? $label : __( 'Email me occasional updates. You can unsubscribe at any time.', 'sendbeam' );
}

/**
 * Is a source switched on and actually usable?
 *
 * @param string $source registration|comments|woocommerce.
 * @return bool
 */
function sendbeam_sync_active( $source ) {
	$settings = sendbeam_sync_settings();
	return ! empty( $settings[ $source ] ) && $settings['lists'] && '' !== sendbeam_api_key();
}

/**
 * The checkbox itself. Never pre-ticked.
 *
 * @param string $id Field id, so several forms on a page stay distinct.
 */
function sendbeam_sync_checkbox( $id ) {
	printf(
		'<p class="sendbeam-optin"><label for="%1$s"><input type="checkbox" name="sendbeam_optin" id="%1$s" value="1" /> %2$s</label></p>',
		esc_attr( $id ),
		esc_html( sendbeam_sync_label() )
	);
}

/** Registration form. */
function sendbeam_sync_registration_field() {
	if ( sendbeam_sync_active( 'registration' ) ) {
		sendbeam_sync_checkbox( 'sendbeam_optin_register' );
	}
}

/**
 * Comment form, prepended to the submit button's field.
 *
 * @param string $field The submit button markup.
 * @return string
 */
function sendbeam_sync_comment_field( $field = '' ) {
	if ( ! sendbeam_sync_active( 'comments' ) ) {
		return $field;
	}
	ob_start();
	sendbeam_sync_checkbox( 'sendbeam_optin_comment' );
	return ob_get_clean() . $field;
}

/** WooCommerce checkout. */
function sendbeam_sync_checkout_field() {
	if ( sendbeam_sync_active( 'woocommerce' ) ) {
		sendbeam_sync_checkbox( 'sendbeam_optin_checkout' );
	}
}

/**
 * Was the box ticked on this submission?
 *
 * @return bool
 */
function sendbeam_sync_consented() {
	// A passenger on a form WordPress or WooCommerce has already validated and
	// nonce-checked. We read one checkbox and never act on it alone: the caller
	// has established which user or order the submission belongs to, and the
	// address subscribed is one the submitter already controls.
	// phpcs:ignore WordPress.Security.NonceVerification.Missing
	return ! empty( $_POST['sendbeam_optin'] );
}

/**
 * New user registered.
 *
 * @param int $user_id User ID.
 */
function sendbeam_sync_on_register( $user_id ) {
	if ( ! sendbeam_sync_active( 'registration' ) || ! sendbeam_sync_consented() ) {
		return;
	}
	$user = get_userdata( $user_id );
	if ( ! $user || ! is_email( $user->user_email ) ) {
		return;
	}
	sendbeam_subscribe( $user->user_email, $user->first_name, $user->last_name, 'wordpress-registration' );
}

/**
 * Comment posted.
 *
 * @param int        $comment_id Comment ID.
 * @param int|string $approved   Approval state.
 */
function sendbeam_sync_on_comment( $comment_id, $approved ) {
	if ( 'spam' === $approved || ! sendbeam_sync_active( 'comments' ) || ! sendbeam_sync_consented() ) {
		return;
	}
	$comment = get_comment( $comment_id );
	if ( ! $comment || ! is_email( $comment->comment_author_email ) ) {
		return;
	}
	$parts = explode( ' ', trim( (string) $comment->comment_author ), 2 );
	sendbeam_subscribe(
		$comment->comment_author_email,
		isset( $parts[0] ) ? $parts[0] : '',
		isset( $parts[1] ) ? $parts[1] : '',
		'wordpress-comment'
	);
}

/**
 * WooCommerce order placed.
 *
 * @param int $order_id Order ID.
 */
function sendbeam_sync_on_checkout( $order_id ) {
	if ( ! sendbeam_sync_active( 'woocommerce' ) || ! sendbeam_sync_consented() ) {
		return;
	}
	if ( ! function_exists( 'wc_get_order' ) ) {
		return;
	}
	$order = wc_get_order( $order_id );
	if ( ! $order ) {
		return;
	}
	$email = $order->get_billing_email();
	if ( ! is_email( $email ) ) {
		return;
	}
	sendbeam_subscribe( $email, $order->get_billing_first_name(), $order->get_billing_last_name(), 'woocommerce-checkout' );
}

/**
 * Create the contact and put it on every chosen list.
 *
 * `source` is a first-class contact field in SendBeam and segments can filter
 * on it, which is why it carries where the person came from instead of this
 * plugin reaching for tags and a third API permission.
 *
 * @param string        $email      Address.
 * @param string        $first_name First name.
 * @param string        $last_name  Last name.
 * @param string        $source     Where they came from.
 * @param string[]|null $lists      Lists to join; null means the lists chosen under Audience.
 * @param string        $tag        A tag to add, by name; empty for none.
 * @return bool
 */
function sendbeam_subscribe( $email, $first_name, $last_name, $source, $lists = null, $tag = '' ) {
	$settings = sendbeam_sync_settings();
	$lists    = is_array( $lists ) ? $lists : $settings['lists'];

	$created = sendbeam_api_post(
		'/api/v1/contacts',
		array(
			'email'      => $email,
			'first_name' => (string) $first_name,
			'last_name'  => (string) $last_name,
			'source'     => $source,
		)
	);

	$contact_id = '';
	if ( isset( $created['data']['contact']['id'] ) ) {
		$contact_id = (string) $created['data']['contact']['id'];
	} elseif ( isset( $created['data']['id'] ) ) {
		$contact_id = (string) $created['data']['id'];
	}

	// A returning customer is the common case, not the edge case: the API
	// answers 409 for an address already on file and returns no ID with it, so
	// without this lookup every repeat buyer who ticked the box would fail.
	// They still consented; they just already exist.
	if ( 409 === $created['status'] ) {
		$contact_id = sendbeam_find_contact_id( $email );
	}

	if ( '' === $contact_id ) {
		sendbeam_sync_log(
			$email,
			$source,
			false,
			$created['error'] ? $created['error'] : sprintf( 'HTTP %d', $created['status'] )
		);
		return false;
	}

	$problems = array();
	foreach ( $lists as $list_id ) {
		$added = sendbeam_api_post( '/api/v1/lists/' . rawurlencode( $list_id ) . '/contacts', array( 'contact_id' => $contact_id ) );
		if ( ! $added['ok'] && 409 !== $added['status'] ) {
			$problems[] = $added['error'] ? $added['error'] : sprintf( 'HTTP %d', $added['status'] );
		}
	}

	if ( '' !== trim( (string) $tag ) ) {
		$tagged = sendbeam_tag_contact( $contact_id, $tag );
		if ( '' !== $tagged ) {
			$problems[] = $tagged;
		}
	}

	if ( $problems ) {
		sendbeam_sync_log( $email, $source, false, implode( '; ', array_unique( $problems ) ) );
		return false;
	}

	sendbeam_sync_log( $email, $source, true, '' );
	return true;
}

/**
 * Find an existing contact's ID by address.
 *
 * The search endpoint matches loosely, so the results are filtered back down
 * to an exact, case-insensitive address match — subscribing the wrong person
 * because their address merely contained this one would be worse than failing.
 *
 * @param string $email Address.
 * @return string Contact ID, or '' when not found.
 */
function sendbeam_find_contact_id( $email ) {
	$found = sendbeam_api_get( '/api/v1/contacts?limit=25&q=' . rawurlencode( $email ) );
	if ( ! $found['ok'] || empty( $found['data']['contacts'] ) || ! is_array( $found['data']['contacts'] ) ) {
		return '';
	}
	foreach ( $found['data']['contacts'] as $contact ) {
		if ( isset( $contact['email'], $contact['id'] ) && 0 === strcasecmp( (string) $contact['email'], $email ) ) {
			return (string) $contact['id'];
		}
	}
	return '';
}

/**
 * Add a tag to a contact by name, creating the tag the first time it is used.
 *
 * @param string $contact_id Contact ID.
 * @param string $name       Tag name.
 * @return string A problem to log, or '' when the contact carries the tag.
 */
function sendbeam_tag_contact( $contact_id, $name ) {
	$tag_id = sendbeam_tag_id( $name );
	if ( '' === $tag_id ) {
		return __( 'The tag could not be found or created. The key needs the Tags (read and write) permission.', 'sendbeam' );
	}
	$added = sendbeam_api_post( '/api/v1/contacts/' . rawurlencode( $contact_id ) . '/tags', array( 'tag_id' => $tag_id ) );
	if ( $added['ok'] || 409 === $added['status'] ) {
		return '';
	}
	return $added['error'] ? $added['error'] : sprintf( 'HTTP %d', $added['status'] );
}

/**
 * A tag's ID, matched by name without regard to case, creating the tag when
 * the workspace has none by that name.
 *
 * @param string $name Tag name.
 * @return string Tag ID, or '' when it can be neither read nor created.
 */
function sendbeam_tag_id( $name ) {
	$name = trim( (string) $name );
	if ( '' === $name ) {
		return '';
	}

	$found = sendbeam_find_tag_id( $name );
	if ( '' !== $found ) {
		return $found;
	}

	$created = sendbeam_api_post( '/api/v1/tags', array( 'name' => $name ) );
	if ( isset( $created['data']['tag']['id'] ) ) {
		return (string) $created['data']['tag']['id'];
	}

	// Created by another submission a moment ago: it exists now.
	return 409 === $created['status'] ? sendbeam_find_tag_id( $name ) : '';
}

/**
 * Look a tag up by name.
 *
 * @param string $name Tag name.
 * @return string Tag ID, or '' when not found.
 */
function sendbeam_find_tag_id( $name ) {
	$tags = sendbeam_api_get( '/api/v1/tags' );
	if ( ! $tags['ok'] || empty( $tags['data']['tags'] ) || ! is_array( $tags['data']['tags'] ) ) {
		return '';
	}
	foreach ( $tags['data']['tags'] as $tag ) {
		if ( isset( $tag['name'], $tag['id'] ) && 0 === strcasecmp( (string) $tag['name'], $name ) ) {
			return (string) $tag['id'];
		}
	}
	return '';
}

/**
 * Keep the last 20 attempts so a silent failure is visible.
 *
 * @param string $email  Address.
 * @param string $source Source.
 * @param bool   $ok     Whether it worked.
 * @param string $note   Failure detail.
 */
function sendbeam_sync_log( $email, $source, $ok, $note ) {
	$log = get_option( SENDBEAM_SYNC_LOG, array() );
	if ( ! is_array( $log ) ) {
		$log = array();
	}
	array_unshift(
		$log,
		array(
			'at'     => time(),
			'email'  => $email,
			'source' => $source,
			'ok'     => $ok ? 1 : 0,
			'note'   => $note,
		)
	);
	update_option( SENDBEAM_SYNC_LOG, array_slice( $log, 0, 20 ), false );
}

/** Save the Audience settings. */
function sendbeam_handle_save_sync() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'sendbeam' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'sendbeam_save_sync' );

	// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked above.
	$lists = isset( $_POST['sendbeam_sync']['lists'] ) && is_array( $_POST['sendbeam_sync']['lists'] )
		? array_map( 'sanitize_text_field', wp_unslash( $_POST['sendbeam_sync']['lists'] ) )
		: array();

	$out = array(
		'registration' => empty( $_POST['sendbeam_sync']['registration'] ) ? 0 : 1,
		'comments'     => empty( $_POST['sendbeam_sync']['comments'] ) ? 0 : 1,
		'woocommerce'  => empty( $_POST['sendbeam_sync']['woocommerce'] ) ? 0 : 1,
		'lists'        => array_values( array_filter( $lists ) ),
		'label'        => isset( $_POST['sendbeam_sync']['label'] ) ? sanitize_text_field( wp_unslash( $_POST['sendbeam_sync']['label'] ) ) : '',
	);
	// phpcs:enable

	update_option( SENDBEAM_SYNC_OPTION, $out, false );
	wp_safe_redirect( add_query_arg( 'sendbeam_sync_saved', '1', sendbeam_tab_url( 'audience' ) ) );
	exit;
}
