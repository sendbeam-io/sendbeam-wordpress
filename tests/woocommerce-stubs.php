<?php
/**
 * The parts of WooCommerce includes/ecommerce.php touches, shaped like the
 * real classes and functions. Loaded part-way through the smoke test, after
 * checking that every e-commerce hook is a no-op while WooCommerce is
 * absent — the same pattern tests/host-stubs.php uses for the form-plugin
 * bridges.
 */

class WooCommerce {}

/** A single product page's product. */
class WC_Product {
	private $name;
	public function __construct( $name = 'Test product' ) { $this->name = $name; }
	public function get_name() { return $this->name; }
}
function wc_get_product( $id ) { return isset( $GLOBALS['stub']['wc_product'] ) ? $GLOBALS['stub']['wc_product'] : null; }

/** One order, as wc_get_order() would return it. */
class WC_Order {
	private $data;
	public function __construct( $data ) { $this->data = $data; }
	public function get_billing_email() { return isset( $this->data['email'] ) ? $this->data['email'] : ''; }
	public function get_billing_first_name() { return isset( $this->data['first_name'] ) ? $this->data['first_name'] : ''; }
	public function get_billing_last_name() { return isset( $this->data['last_name'] ) ? $this->data['last_name'] : ''; }
	public function get_total() { return isset( $this->data['total'] ) ? $this->data['total'] : 0; }
	public function get_currency() { return isset( $this->data['currency'] ) ? $this->data['currency'] : 'USD'; }
	public function get_id() { return isset( $this->data['id'] ) ? $this->data['id'] : 0; }
	// Order meta lives in the shared stub store, keyed by order id, so a
	// second WC_Order for the same id sees what the first one saved.
	public function get_meta( $key ) { $id = $this->get_id(); return isset( $GLOBALS['stub']['wc_order_meta'][ $id ][ $key ] ) ? $GLOBALS['stub']['wc_order_meta'][ $id ][ $key ] : ''; }
	public function update_meta_data( $key, $value ) { $this->pending[ $key ] = $value; }
	public function save() { foreach ( $this->pending as $k => $v ) { $GLOBALS['stub']['wc_order_meta'][ $this->get_id() ][ $k ] = $v; } $this->pending = array(); $GLOBALS['stub']['wc_saves'][] = $this->get_id(); }
	private $pending = array();
}
function is_cart() { return ! empty( $GLOBALS['stub']['query']['cart'] ); }
function is_checkout() { return ! empty( $GLOBALS['stub']['query']['checkout'] ); }
function is_account_page() { return ! empty( $GLOBALS['stub']['query']['account'] ); }
function is_wc_endpoint_url() { return ! empty( $GLOBALS['stub']['query']['wc_endpoint'] ); }
function woocommerce_register_additional_checkout_field( $options ) { $GLOBALS['stub']['wc_checkout_fields'][] = $options; }
function wc_get_order( $id ) {
	return isset( $GLOBALS['stub']['wc_orders'][ $id ] ) ? new WC_Order( $GLOBALS['stub']['wc_orders'][ $id ] + array( 'id' => $id ) ) : false;
}

/**
 * Whether an order exists for an email created after a timestamp — enough
 * of WC_Order_Query's real shape (billing_email, date_created => '>N',
 * return => 'ids') to test the abandonment check without a database.
 */
function wc_get_orders( $args ) {
	$out = array();
	foreach ( ( isset( $GLOBALS['stub']['wc_completed_orders'] ) ? $GLOBALS['stub']['wc_completed_orders'] : array() ) as $o ) {
		if ( isset( $args['billing_email'] ) && $args['billing_email'] !== $o['email'] ) {
			continue;
		}
		$cutoff = null;
		if ( isset( $args['date_created'] ) && 0 === strpos( (string) $args['date_created'], '>' ) ) {
			$cutoff = (int) substr( $args['date_created'], 1 );
		}
		if ( null !== $cutoff && $o['created_at'] <= $cutoff ) {
			continue;
		}
		$out[] = $o['id'];
	}
	return $out;
}

/** WC()->session and WC()->customer, just enough for the cart-tracking code. */
class WC_Session {
	public $customer_id = '';
	public function get_customer_id() { return $this->customer_id; }
}
class WC_Customer {
	public $billing_email = '';
	public function get_billing_email() { return $this->billing_email; }
}
class Sendbeam_Test_WC_Store {
	public $session;
	public $customer;
	public function __construct() {
		$this->session  = new WC_Session();
		$this->customer = new WC_Customer();
	}
}
function WC() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- matching WooCommerce's own WC().
	if ( ! isset( $GLOBALS['stub']['wc'] ) ) {
		$GLOBALS['stub']['wc'] = new Sendbeam_Test_WC_Store();
	}
	return $GLOBALS['stub']['wc'];
}
