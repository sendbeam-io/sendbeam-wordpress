<?php
/**
 * Remove everything the plugin stored.
 *
 * Deleting a plugin should leave no trace of it in the database. The list
 * below is every option, transient and piece of user meta the plugin writes;
 * if a new one is added anywhere, it belongs here too.
 *
 * The API key lives inside `sendbeam_settings`, so removing that option is
 * what actually revokes this site's copy of it.
 *
 * @package SendBeam
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$sendbeam_options = array(
	'sendbeam_settings',      // Including the API key.
	'sendbeam_popups',
	'sendbeam_sync',
	'sendbeam_sync_log',
	'sendbeam_bridges',
	'sendbeam_mail_log',
	'sendbeam_activated_at',
	'sendbeam_ecommerce',
	'sendbeam_ecommerce_log',
);
foreach ( $sendbeam_options as $sendbeam_option ) {
	delete_option( $sendbeam_option );
}

$sendbeam_transients = array(
	'sendbeam_connection',
	'sendbeam_remote_forms',
	'sendbeam_remote_lists',
	'sendbeam_subscriber_count',
);
foreach ( $sendbeam_transients as $sendbeam_transient ) {
	delete_transient( $sendbeam_transient );
}

// Per-user, so it has to be cleared per user: whether they dismissed the
// set-up notice.
delete_metadata( 'user', 0, 'sendbeam_setup_dismissed', '', true );

// Contact Form 7 keeps each form's SendBeam tab as post meta, and a
// subscription queued a moment before deletion must not run afterwards.
delete_post_meta_by_key( '_sendbeam_cf7' );
wp_unschedule_hook( 'sendbeam_bridge_subscribe' );
wp_unschedule_hook( 'sendbeam_ecommerce_send_event' );
wp_unschedule_hook( 'sendbeam_check_cart_abandonment' );

// Cart-abandonment tracking is one transient per shopper session, keyed by
// a hash (sendbeam_cart_<md5>) rather than a name this file can list up
// front — the only way to find them all is a direct query.
global $wpdb;
if ( isset( $wpdb ) ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off cleanup, no equivalent WP API for a prefix sweep.
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_transient_sendbeam_cart_' ) . '%' ) );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_transient_timeout_sendbeam_cart_' ) . '%' ) );
}

// Multisite: the same clean-up on every site in the network.
if ( is_multisite() ) {
	$sendbeam_sites = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	foreach ( $sendbeam_sites as $sendbeam_site_id ) {
		switch_to_blog( $sendbeam_site_id );
		foreach ( $sendbeam_options as $sendbeam_option ) {
			delete_option( $sendbeam_option );
		}
		foreach ( $sendbeam_transients as $sendbeam_transient ) {
			delete_transient( $sendbeam_transient );
		}
		delete_post_meta_by_key( '_sendbeam_cf7' );
		wp_unschedule_hook( 'sendbeam_bridge_subscribe' );
		wp_unschedule_hook( 'sendbeam_ecommerce_send_event' );
		wp_unschedule_hook( 'sendbeam_check_cart_abandonment' );
		if ( isset( $wpdb ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_transient_sendbeam_cart_' ) . '%' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_transient_timeout_sendbeam_cart_' ) . '%' ) );
		}
		restore_current_blog();
	}
}
