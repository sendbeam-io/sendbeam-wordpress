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
	'sendbeam_mail_log',
	'sendbeam_activated_at',
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

// Multisite: the same clean-up on every site in the network.
if ( is_multisite() ) {
	$sendbeam_sites = get_sites( array( 'fields' => 'ids', 'number' => 0 ) );
	foreach ( $sendbeam_sites as $sendbeam_site_id ) {
		switch_to_blog( $sendbeam_site_id );
		foreach ( $sendbeam_options as $sendbeam_option ) {
			delete_option( $sendbeam_option );
		}
		foreach ( $sendbeam_transients as $sendbeam_transient ) {
			delete_transient( $sendbeam_transient );
		}
		restore_current_blog();
	}
}
