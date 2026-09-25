<?php
/**
 * E-commerce events for the three native SendBeam automation triggers
 * (GitHub issue #10 on sendbeam-io/sendbeam): Cart Abandoned, Product Viewed
 * and Order Placed. Each is its own toggle, mirroring includes/sync.php's
 * per-source pattern, and posts to POST /api/v1/ecommerce/events through
 * sendbeam_api_post() — the same stored key and HTTP helper as everything
 * else in this plugin, nothing new invented.
 *
 * Order placed is the reliable one: WooCommerce's own order-processed hook,
 * queued as a single cron event (the same pattern includes/bridges.php
 * already uses for form-plugin subscriptions) so a slow or failed call to
 * SendBeam never delays the customer's "Thank you" page.
 *
 * Product viewed only ever fires for a KNOWN contact — a logged-in customer,
 * or one whose email is already in the WooCommerce customer session from
 * earlier in the same visit. There is no anonymous visitor tracking here on
 * purpose: inventing cookie-based tracking for people who have given no
 * email at all would be a different, bigger feature this plugin does not do.
 *
 * Cart abandoned is genuinely the hardest of the three. WooCommerce core has
 * no "abandoned cart" event — that gap is why dedicated cart-recovery
 * plugins exist as a whole category of plugin. This is a real, working
 * best-effort heuristic: adding to cart timestamps the shopper's session, a
 * one-off wp-cron event checks after a configurable window (default 60
 * minutes, "Cart abandoned window" on the settings screen) whether an order
 * followed, and fires once if not — and only ever for a cart where an email
 * became known at some point (logged in, or entered at an earlier step).
 * This is a heuristic, not a guarantee, and is documented as such both here
 * and on the settings screen.
 *
 * @package SendBeam
 */

defined( 'ABSPATH' ) || exit;

const SENDBEAM_ECOMMERCE_OPTION = 'sendbeam_ecommerce';
const SENDBEAM_ECOMMERCE_LOG    = 'sendbeam_ecommerce_log';
const SENDBEAM_ECOMMERCE_HOOK   = 'sendbeam_ecommerce_send_event';
const SENDBEAM_CART_CHECK_HOOK  = 'sendbeam_check_cart_abandonment';
const SENDBEAM_CART_WINDOW_MIN  = 5;
const SENDBEAM_CART_WINDOW_MAX  = 10080; // 7 days.
/** Order meta that says Order placed has already gone out for this order. */
const SENDBEAM_ORDER_PLACED_META = '_sendbeam_order_placed_sent';

add_action( 'admin_post_sendbeam_save_ecommerce', 'sendbeam_handle_save_ecommerce' );
add_action( SENDBEAM_ECOMMERCE_HOOK, 'sendbeam_ecommerce_run_event' );
add_action( SENDBEAM_CART_CHECK_HOOK, 'sendbeam_ecommerce_check_cart_abandonment' );

// WooCommerce. Every callback is a no-op the moment WooCommerce, or the
// specific function/class it needs, is not there — exactly how sync.php's
// own checkout hook already degrades.

/*
 * Order placed means PAID. The checkout hook fires while WooCommerce is still
 * creating the order — before any gateway has taken a penny, and whatever
 * the order ends up as — so a card that is declined had already produced an
 * "order placed" event and a lifetime-value increment. Payment complete
 * covers gateways; processing and completed cover cash on delivery and a
 * merchant marking an order paid by hand. One order reaches more than one of
 * these, so the send is recorded on the order and made once.
 */
add_action( 'woocommerce_payment_complete', 'sendbeam_ecommerce_on_order_paid', 10, 1 );
add_action( 'woocommerce_order_status_processing', 'sendbeam_ecommerce_on_order_paid', 10, 1 );
add_action( 'woocommerce_order_status_completed', 'sendbeam_ecommerce_on_order_paid', 10, 1 );
add_action( 'woocommerce_single_product_summary', 'sendbeam_ecommerce_on_product_viewed', 1 );
add_action( 'woocommerce_add_to_cart', 'sendbeam_ecommerce_on_add_to_cart', 10, 6 );
add_action( 'woocommerce_before_checkout_form', 'sendbeam_ecommerce_refresh_cart_email' );

register_deactivation_hook( SENDBEAM_FILE, 'sendbeam_ecommerce_clear_scheduled' );

/**
 * Defaults.
 *
 * @return array<string,int>
 */
function sendbeam_ecommerce_defaults() {
	return array(
		'order_placed'          => 0,
		'product_viewed'        => 0,
		'cart_abandoned'        => 0,
		'cart_abandoned_window' => 60,
	);
}

/**
 * Saved settings, merged over the defaults with the window clamped to a
 * sane range (5 minutes to 7 days) whatever is stored.
 *
 * @return array<string,int>
 */
function sendbeam_ecommerce_settings() {
	$saved = get_option( SENDBEAM_ECOMMERCE_OPTION, array() );
	if ( ! is_array( $saved ) ) {
		$saved = array();
	}
	$out                          = array_merge( sendbeam_ecommerce_defaults(), $saved );
	$out['cart_abandoned_window'] = max( SENDBEAM_CART_WINDOW_MIN, min( SENDBEAM_CART_WINDOW_MAX, (int) $out['cart_abandoned_window'] ) );
	foreach ( array( 'order_placed', 'product_viewed', 'cart_abandoned' ) as $flag ) {
		$out[ $flag ] = empty( $out[ $flag ] ) ? 0 : 1;
	}
	return $out;
}

/**
 * Whether one event type is switched on and usable: a key is saved and
 * WooCommerce is active. (Every one of these events is WooCommerce-only —
 * there is no non-WooCommerce e-commerce integration in this plugin.)
 *
 * @param string $event 'order_placed' | 'product_viewed' | 'cart_abandoned'.
 * @return bool
 */
function sendbeam_ecommerce_active( $event ) {
	if ( '' === sendbeam_api_key() || ! class_exists( 'WooCommerce' ) ) {
		return false;
	}
	$settings = sendbeam_ecommerce_settings();
	return ! empty( $settings[ $event ] );
}

/**
 * Save the settings.
 */
function sendbeam_handle_save_ecommerce() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'sendbeam' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'sendbeam_save_ecommerce' );

	// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked above.
	$window = isset( $_POST['sendbeam_ecommerce']['cart_abandoned_window'] ) ? (int) $_POST['sendbeam_ecommerce']['cart_abandoned_window'] : 60;
	$out    = array(
		'order_placed'          => empty( $_POST['sendbeam_ecommerce']['order_placed'] ) ? 0 : 1,
		'product_viewed'        => empty( $_POST['sendbeam_ecommerce']['product_viewed'] ) ? 0 : 1,
		'cart_abandoned'        => empty( $_POST['sendbeam_ecommerce']['cart_abandoned'] ) ? 0 : 1,
		'cart_abandoned_window' => max( SENDBEAM_CART_WINDOW_MIN, min( SENDBEAM_CART_WINDOW_MAX, $window ) ),
	);
	// phpcs:enable

	update_option( SENDBEAM_ECOMMERCE_OPTION, $out );
	wp_safe_redirect( add_query_arg( 'sendbeam_ecommerce_saved', '1', sendbeam_tab_url( 'ecommerce' ) ) );
	exit;
}

/**
 * Best-known email for "this is a contact SendBeam can act on" — a logged-in
 * customer, or one WooCommerce's customer session already has an email for
 * from earlier in this visit. Deliberately nothing more: this plugin does
 * not invent anonymous tracking to find an email nobody has given yet.
 *
 * @return string Empty when nobody is known.
 */
function sendbeam_ecommerce_known_email() {
	if ( is_user_logged_in() ) {
		$user = wp_get_current_user();
		if ( $user && is_email( $user->user_email ) ) {
			return $user->user_email;
		}
	}
	if ( function_exists( 'WC' ) && WC()->customer ) {
		$email = WC()->customer->get_billing_email();
		if ( is_email( $email ) ) {
			return $email;
		}
	}
	return '';
}

/**
 * Queue an event to SendBeam as a single cron event, so a slow or failed
 * call never delays the page the customer is looking at — the same pattern
 * includes/bridges.php uses for form-plugin subscriptions.
 *
 * @param string $type  'order_placed' | 'product_viewed' | 'cart_abandoned'.
 * @param string $email Contact email.
 * @param array  $extra name / value / currency / source.
 */
function sendbeam_ecommerce_queue_event( $type, $email, $extra = array() ) {
	if ( ! is_email( $email ) ) {
		return;
	}
	$job = array_merge(
		array(
			'type'   => $type,
			'email'  => $email,
			'source' => 'woocommerce',
		),
		$extra
	);
	wp_schedule_single_event( time(), SENDBEAM_ECOMMERCE_HOOK, array( $job ) );
}

/**
 * The queued event itself: POST it, and log the attempt the same way
 * sync.php logs a subscription attempt.
 *
 * @param mixed $job What sendbeam_ecommerce_queue_event() queued.
 */
function sendbeam_ecommerce_run_event( $job ) {
	if ( ! is_array( $job ) || empty( $job['type'] ) || empty( $job['email'] ) || ! is_email( $job['email'] ) ) {
		return;
	}
	$body = $job;
	unset( $body['type'], $body['email'] );
	$result = sendbeam_api_post(
		'/api/v1/ecommerce/events',
		array_merge(
			array(
				'type'  => $job['type'],
				'email' => $job['email'],
			),
			array_filter(
				$body,
				static function ( $v ) {
					return '' !== $v && null !== $v;
				}
			)
		)
	);
	sendbeam_ecommerce_log( $job['type'], $job['email'], $result['ok'], $result['ok'] ? '' : ( $result['error'] ? $result['error'] : 'HTTP ' . $result['status'] ) );
}

/**
 * Record an attempt for the settings screen's "Recent e-commerce events" table.
 *
 * @param string $type  Event type.
 * @param string $email Contact email.
 * @param bool   $ok    Whether it was accepted.
 * @param string $note  Failure detail; ignored when $ok.
 */
function sendbeam_ecommerce_log( $type, $email, $ok, $note ) {
	$log = get_option( SENDBEAM_ECOMMERCE_LOG, array() );
	if ( ! is_array( $log ) ) {
		$log = array();
	}
	array_unshift(
		$log,
		array(
			'at'    => time(),
			'type'  => $type,
			'email' => $email,
			'ok'    => $ok ? 1 : 0,
			'note'  => $note,
		)
	);
	update_option( SENDBEAM_ECOMMERCE_LOG, array_slice( $log, 0, 20 ), false );
}

/* ------------------------------------------------------------ Order placed */

/**
 * A WooCommerce order has been placed. Deliberately a NEW function, not a
 * change to sendbeam_sync_on_checkout() in sync.php: that one subscribes the
 * customer to a mailing list on the OPT-IN CHECKBOX the shopper ticked, which
 * is a marketing-consent concern; this one reports the order itself as a
 * store event, which needs no marketing consent at all — a receipt is not a
 * newsletter. Overloading one function for both would tangle two rules that
 * must never depend on each other.
 *
 * @param int $order_id Order ID.
 */
function sendbeam_ecommerce_on_order_placed( $order_id ) {
	if ( ! sendbeam_ecommerce_active( 'order_placed' ) || ! function_exists( 'wc_get_order' ) ) {
		return;
	}
	$order = $order_id instanceof WC_Order ? $order_id : wc_get_order( $order_id );
	if ( ! $order ) {
		return;
	}
	$email = $order->get_billing_email();
	if ( ! is_email( $email ) ) {
		return;
	}
	sendbeam_ecommerce_queue_event(
		'order_placed',
		$email,
		array(
			'name'      => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
			'value'     => (float) $order->get_total(),
			'currency'  => $order->get_currency(),
			// The order id is what lets SendBeam store the order, attribute it
			// to the email that led to it, and treat a retried delivery as the
			// same order rather than a second one. Without it the event fires
			// automations and moves lifetime value but appears in no revenue
			// figure.
			'order_id'  => (string) $order->get_id(),
			'placed_at' => $order->get_date_created() ? $order->get_date_created()->format( DATE_ATOM ) : gmdate( DATE_ATOM ),
		)
	);
}

/**
 * The paid-order hooks land here. Whichever of them fires first sends the
 * event and marks the order; the rest find the mark and do nothing, so an
 * order that goes processing → completed is one Order placed, not two.
 *
 * @param int|WC_Order $order_id Order ID, or the order itself.
 */
function sendbeam_ecommerce_on_order_paid( $order_id ) {
	if ( ! sendbeam_ecommerce_active( 'order_placed' ) || ! function_exists( 'wc_get_order' ) ) {
		return;
	}
	$order = $order_id instanceof WC_Order ? $order_id : wc_get_order( $order_id );
	if ( ! $order || $order->get_meta( SENDBEAM_ORDER_PLACED_META ) ) {
		return;
	}
	$order->update_meta_data( SENDBEAM_ORDER_PLACED_META, time() );
	$order->save();
	sendbeam_ecommerce_on_order_placed( $order );
}

/* ---------------------------------------------------------- Product viewed */

/**
 * A single product page rendered, for a contact SendBeam can already put a
 * name to. Hooked early on woocommerce_single_product_summary so it fires
 * once per view regardless of what the theme does with the rest of the
 * summary.
 */
function sendbeam_ecommerce_on_product_viewed() {
	if ( ! sendbeam_ecommerce_active( 'product_viewed' ) ) {
		return;
	}
	$email = sendbeam_ecommerce_known_email();
	if ( '' === $email ) {
		return; // Nobody known — nothing sent, on purpose. See file header.
	}
	global $product;
	$viewed = ( $product instanceof WC_Product ) ? $product : ( function_exists( 'wc_get_product' ) ? wc_get_product( get_the_ID() ) : null );
	if ( ! $viewed ) {
		return;
	}
	sendbeam_ecommerce_queue_event(
		'product_viewed',
		$email,
		array( 'name' => $viewed->get_name() )
	);
}

/* ---------------------------------------------------------- Cart abandoned */

/**
 * The transient key tracking one shopper's cart, keyed by WooCommerce's own
 * session/customer id so it is stable across requests in the same session.
 *
 * @param string $session_key WC_Session::get_customer_id().
 * @return string
 */
function sendbeam_cart_tracking_key( $session_key ) {
	return 'sendbeam_cart_' . md5( (string) $session_key );
}

/**
 * Something was added to the cart: start (or continue) tracking this
 * session towards a possible abandoned-cart event.
 *
 * WooCommerce's own hook signature — see woocommerce_add_to_cart in
 * WC_Cart::add_to_cart(). Only the session identifies the shopper here; the
 * item itself does not matter to this heuristic.
 *
 * @param string $cart_item_key  The cart item's key.
 * @param int    $product_id     Product added.
 * @param int    $quantity       Quantity added.
 * @param int    $variation_id   Variation, if any.
 * @param array  $variation      Variation attributes.
 * @param array  $cart_item_data Extra cart item data.
 */
function sendbeam_ecommerce_on_add_to_cart( $cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data ) {
	if ( ! sendbeam_ecommerce_active( 'cart_abandoned' ) ) {
		return;
	}
	if ( ! function_exists( 'WC' ) || ! WC()->session ) {
		return;
	}
	$session_key = WC()->session->get_customer_id();
	if ( ! $session_key ) {
		return;
	}
	$tracking_key = sendbeam_cart_tracking_key( $session_key );
	$window       = sendbeam_ecommerce_settings()['cart_abandoned_window'];

	// Already tracking this cart: leave the original start time alone (the
	// window measures from the FIRST item added, not the latest), just top
	// up the transient's own expiry so it survives until the check runs.
	$existing = get_transient( $tracking_key );
	if ( is_array( $existing ) ) {
		set_transient( $tracking_key, $existing, ( $window + 30 ) * MINUTE_IN_SECONDS );
		return;
	}

	set_transient(
		$tracking_key,
		array(
			'email'      => sendbeam_ecommerce_known_email(),
			'started_at' => time(),
		),
		( $window + 30 ) * MINUTE_IN_SECONDS // outlives the scheduled check with margin.
	);

	if ( ! wp_next_scheduled( SENDBEAM_CART_CHECK_HOOK, array( $tracking_key ) ) ) {
		wp_schedule_single_event( time() + $window * MINUTE_IN_SECONDS, SENDBEAM_CART_CHECK_HOOK, array( $tracking_key ) );
	}
}

/**
 * On the checkout page: if this session is being tracked and had no known
 * email yet, and one is now known (the shopper logged in, or an earlier
 * visit to checkout left one in the session), fill it in. This is the
 * "entered at some checkout step" half of the honest constraint described
 * in the file header — it cannot catch an email typed and abandoned within
 * the same page view with no reload, which no server-side hook can.
 */
function sendbeam_ecommerce_refresh_cart_email() {
	if ( ! sendbeam_ecommerce_active( 'cart_abandoned' ) || ! function_exists( 'WC' ) || ! WC()->session ) {
		return;
	}
	$session_key = WC()->session->get_customer_id();
	if ( ! $session_key ) {
		return;
	}
	$tracking_key = sendbeam_cart_tracking_key( $session_key );
	$tracked      = get_transient( $tracking_key );
	if ( ! is_array( $tracked ) || ! empty( $tracked['email'] ) ) {
		return;
	}
	$email = sendbeam_ecommerce_known_email();
	if ( '' === $email ) {
		return;
	}
	$tracked['email'] = $email;
	$window           = sendbeam_ecommerce_settings()['cart_abandoned_window'];
	set_transient( $tracking_key, $tracked, ( $window + 30 ) * MINUTE_IN_SECONDS );
}

/**
 * The scheduled check: has an order followed since this cart started? If
 * not — and only when an email is known — fire cart_abandoned once.
 *
 * @param string $tracking_key From sendbeam_cart_tracking_key().
 */
function sendbeam_ecommerce_check_cart_abandonment( $tracking_key ) {
	$tracked = get_transient( $tracking_key );
	delete_transient( $tracking_key ); // one check per cart, whatever the outcome.

	if ( ! sendbeam_ecommerce_active( 'cart_abandoned' ) || ! is_array( $tracked ) ) {
		return;
	}
	$email = isset( $tracked['email'] ) ? (string) $tracked['email'] : '';
	if ( '' === $email || ! is_email( $email ) ) {
		return; // Never became known — nothing to send, per the honest constraint.
	}
	$started_at = isset( $tracked['started_at'] ) ? (int) $tracked['started_at'] : time();

	if ( function_exists( 'wc_get_orders' ) ) {
		$since = wc_get_orders(
			array(
				'billing_email' => $email,
				'date_created'  => '>' . $started_at,
				'limit'         => 1,
				'return'        => 'ids',
			)
		);
		if ( ! empty( $since ) ) {
			return; // An order followed: not abandoned.
		}
	}

	sendbeam_ecommerce_queue_event( 'cart_abandoned', $email );
}

/**
 * Deactivation: drop any pending cart-abandonment checks. The tracking
 * transients themselves expire on their own; there is no efficient way to
 * enumerate them here (they are keyed per session), and leaving a handful to
 * expire naturally is harmless.
 */
function sendbeam_ecommerce_clear_scheduled() {
	wp_unschedule_hook( SENDBEAM_CART_CHECK_HOOK );
	wp_unschedule_hook( SENDBEAM_ECOMMERCE_HOOK );
}
