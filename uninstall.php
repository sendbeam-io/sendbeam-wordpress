<?php
/**
 * Remove the one option the plugin stores. Nothing else is written.
 *
 * @package SendBeam
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'sendbeam_settings' );
