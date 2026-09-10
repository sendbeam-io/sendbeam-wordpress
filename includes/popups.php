<?php
/**
 * Pop-ups: several of them, each with its own targeting and trigger.
 *
 * Stored in their own option as an ordered list. Order is the tie-breaker:
 * the first enabled rule whose conditions match the current request wins, and
 * nothing else is printed. Two modals fighting over the same page is never
 * what anyone wanted, so "first match wins" is a feature, not a limitation.
 *
 * Triggers are honest about what the hosted loader can do. It implements a
 * timer, a floating button, and manual opening from any element carrying
 * `data-sendbeam-open="<form id>"`. Scroll depth and exit intent are built
 * here on top of that last one — a hidden opener plus a few lines of script —
 * rather than by inventing attributes the loader would ignore.
 *
 * @package SendBeam
 */

defined( 'ABSPATH' ) || exit;

const SENDBEAM_POPUPS_OPTION = 'sendbeam_popups';

add_action( 'admin_post_sendbeam_save_popups', 'sendbeam_handle_save_popups' );

/**
 * A blank rule.
 *
 * @return array<string,mixed>
 */
function sendbeam_popup_defaults() {
	return array(
		'enabled' => 1,
		'form'    => '',
		'trigger' => 'timer',
		'delay'   => 5,
		'scroll'  => 50,
		'label'   => '',
		'once'    => 'day',
		'where'   => 'everywhere',
		'url'     => '',
	);
}

/** Trigger choices. @return array<string,string> */
function sendbeam_popup_triggers() {
	return array(
		'timer'  => __( 'After a delay', 'sendbeam' ),
		'scroll' => __( 'When they scroll far enough', 'sendbeam' ),
		'exit'   => __( 'When they move to leave (desktop)', 'sendbeam' ),
		'button' => __( 'From a floating button', 'sendbeam' ),
		'manual' => __( 'Only from a button I place', 'sendbeam' ),
	);
}

/** Placement choices. @return array<string,string> */
function sendbeam_popup_wheres() {
	return array(
		'everywhere' => __( 'Every page', 'sendbeam' ),
		'home'       => __( 'Home page only', 'sendbeam' ),
		'posts'      => __( 'Single posts', 'sendbeam' ),
		'pages'      => __( 'Pages', 'sendbeam' ),
		'url'        => __( 'Pages whose address contains…', 'sendbeam' ),
	);
}

/** Frequency choices. @return array<string,string> */
function sendbeam_popup_frequencies() {
	return array(
		'day'     => __( 'Stay hidden for a day', 'sendbeam' ),
		'week'    => __( 'Stay hidden for a week', 'sendbeam' ),
		'forever' => __( 'Never show it again', 'sendbeam' ),
		'never'   => __( 'Show it on every visit', 'sendbeam' ),
	);
}

/**
 * Every pop-up rule.
 *
 * A site upgrading from the single-pop-up version has its old settings folded
 * into rule one, so nothing stops working the moment the plugin updates.
 *
 * @return array<int,array<string,mixed>>
 */
function sendbeam_popups() {
	$stored = get_option( SENDBEAM_POPUPS_OPTION, null );

	if ( ! is_array( $stored ) ) {
		$legacy   = sendbeam_settings();
		$migrated = array();
		if ( sendbeam_is_form_id( $legacy['popup_form'] ) ) {
			$migrated[] = array_merge(
				sendbeam_popup_defaults(),
				array(
					'enabled' => 'off' === $legacy['popup_where'] ? 0 : 1,
					'form'    => $legacy['popup_form'],
					'trigger' => 'button' === $legacy['popup_trigger'] ? 'button' : 'timer',
					'delay'   => (int) $legacy['popup_delay'],
					'label'   => (string) $legacy['popup_label'],
					'once'    => (string) $legacy['popup_once'],
					'where'   => 'off' === $legacy['popup_where'] ? 'everywhere' : (string) $legacy['popup_where'],
				)
			);
		}
		return $migrated;
	}

	$out = array();
	foreach ( $stored as $rule ) {
		if ( is_array( $rule ) ) {
			$out[] = array_merge( sendbeam_popup_defaults(), $rule );
		}
	}
	return $out;
}

/**
 * Clean one posted rule.
 *
 * @param array $raw Raw input.
 * @return array<string,mixed>
 */
function sendbeam_clean_popup( $raw ) {
	$d   = sendbeam_popup_defaults();
	$out = $d;

	$form         = isset( $raw['form'] ) ? strtolower( trim( sanitize_text_field( wp_unslash( $raw['form'] ) ) ) ) : '';
	$out['form']  = sendbeam_is_form_id( $form ) ? $form : '';
	$out['enabled'] = empty( $raw['enabled'] ) ? 0 : 1;

	$trigger        = isset( $raw['trigger'] ) ? sanitize_key( $raw['trigger'] ) : '';
	$out['trigger'] = isset( sendbeam_popup_triggers()[ $trigger ] ) ? $trigger : $d['trigger'];

	$where        = isset( $raw['where'] ) ? sanitize_key( $raw['where'] ) : '';
	$out['where'] = isset( sendbeam_popup_wheres()[ $where ] ) ? $where : $d['where'];

	$once        = isset( $raw['once'] ) ? sanitize_key( $raw['once'] ) : '';
	$out['once'] = isset( sendbeam_popup_frequencies()[ $once ] ) ? $once : $d['once'];

	$out['delay']  = isset( $raw['delay'] ) ? max( 0, min( 120, (int) $raw['delay'] ) ) : $d['delay'];
	$out['scroll'] = isset( $raw['scroll'] ) ? max( 5, min( 100, (int) $raw['scroll'] ) ) : $d['scroll'];
	$out['label']  = isset( $raw['label'] ) ? sanitize_text_field( wp_unslash( $raw['label'] ) ) : '';
	$out['url']    = isset( $raw['url'] ) ? sanitize_text_field( wp_unslash( $raw['url'] ) ) : '';

	return $out;
}

/** Save the posted rules. */
function sendbeam_handle_save_popups() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'sendbeam' ) );
	}
	check_admin_referer( 'sendbeam_save_popups' );

	$rules = array();
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above.
	$posted = isset( $_POST['sendbeam_popup'] ) && is_array( $_POST['sendbeam_popup'] ) ? wp_unslash( $_POST['sendbeam_popup'] ) : array();
	foreach ( $posted as $raw ) {
		if ( ! is_array( $raw ) ) {
			continue;
		}
		$clean = sendbeam_clean_popup( $raw );
		if ( '' === $clean['form'] ) {
			continue; // A rule with no form is an empty row, not a rule.
		}
		$rules[] = $clean;
	}

	update_option( SENDBEAM_POPUPS_OPTION, $rules, false );
	wp_safe_redirect( add_query_arg( 'sendbeam_saved', count( $rules ), sendbeam_tab_url( 'popup' ) ) );
	exit;
}

/**
 * Does this rule apply to the current request?
 *
 * @param array $rule Rule.
 * @return bool
 */
function sendbeam_popup_matches( $rule ) {
	if ( empty( $rule['enabled'] ) || ! sendbeam_is_form_id( $rule['form'] ) ) {
		return false;
	}
	switch ( $rule['where'] ) {
		case 'everywhere':
			return true;
		case 'home':
			return is_front_page();
		case 'posts':
			return is_singular( 'post' );
		case 'pages':
			return is_page();
		case 'url':
			$needle = trim( (string) $rule['url'] );
			if ( '' === $needle ) {
				return false;
			}
			$request = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
			return '' !== $request && false !== stripos( $request, $needle );
		default:
			return false;
	}
}

/**
 * The rule that owns this page, or null.
 *
 * @return array<string,mixed>|null
 */
function sendbeam_active_popup() {
	foreach ( sendbeam_popups() as $rule ) {
		if ( sendbeam_popup_matches( $rule ) ) {
			return $rule;
		}
	}
	return null;
}
