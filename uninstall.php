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
	'sendbeam_connect_recheck_runs',
);
foreach ( $sendbeam_options as $sendbeam_option ) {
	delete_option( $sendbeam_option );
}

$sendbeam_transients = array(
	'sendbeam_connection',
	'sendbeam_remote_forms',
	'sendbeam_remote_lists',
	'sendbeam_subscriber_count',
	'sendbeam_form_placed',
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
wp_unschedule_hook( 'sendbeam_connect_recheck' );

// Two families of transient are named after something this file cannot list
// up front — a shopper's session (sendbeam_cart_<md5>) and an administrator's
// user ID (sendbeam_connect_<id>) — so a prefix sweep is the only way to find
// them all. delete_transient() for the current user would leave every other
// administrator's behind.
$sendbeam_transient_prefixes = array( 'sendbeam_cart_', 'sendbeam_connect_' );

/**
 * Delete every transient whose name starts with one of the prefixes.
 *
 * @param string[] $prefixes Transient name prefixes.
 */
function sendbeam_uninstall_sweep_transients( $prefixes ) {
	global $wpdb;
	if ( ! isset( $wpdb ) ) {
		return;
	}
	foreach ( $prefixes as $sendbeam_prefix ) {
		foreach ( array( '_transient_', '_transient_timeout_' ) as $sendbeam_key_prefix ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off cleanup, no equivalent WP API for a prefix sweep.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $sendbeam_key_prefix . $sendbeam_prefix ) . '%' ) );
		}
	}
}

sendbeam_uninstall_sweep_transients( $sendbeam_transient_prefixes );

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
		wp_unschedule_hook( 'sendbeam_connect_recheck' );
		sendbeam_uninstall_sweep_transients( $sendbeam_transient_prefixes );
		restore_current_blog();
	}
}
