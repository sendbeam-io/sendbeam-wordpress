<?php
/**
 * The light-touch part of onboarding: one notice, and a sense of progress.
 *
 * Deliberately *not* a takeover. WordPress.org's guideline 11 asks plugins not
 * to hijack the dashboard, and a forced redirect on activation is the most
 * common way to do exactly that. So:
 *
 *   - no redirect on activation;
 *   - one notice, only for people who could act on it (manage_options);
 *   - never shown on the SendBeam page itself, where it would be nagging
 *     someone already doing the thing it asks for;
 *   - dismissible per user, and it self-dismisses the moment the plugin is
 *     connected, so it cannot become permanent furniture.
 *
 * @package SendBeam
 */

defined( 'ABSPATH' ) || exit;

register_activation_hook( SENDBEAM_FILE, 'sendbeam_on_activate' );
add_action( 'admin_notices', 'sendbeam_setup_notice' );
add_action( 'admin_post_sendbeam_dismiss_setup', 'sendbeam_dismiss_setup' );

/**
 * Remember when we were switched on, so the notice can be new-install-only.
 */
function sendbeam_on_activate() {
	if ( ! get_option( 'sendbeam_activated_at' ) ) {
		add_option( 'sendbeam_activated_at', time(), '', false );
	}
	sendbeam_flush_cache();
}

/**
 * Has the user finished the one step that unlocks everything else?
 *
 * @return bool
 */
function sendbeam_is_connected() {
	$connection = sendbeam_connection();
	return in_array( $connection['state'], array( 'ok', 'no_scope' ), true );
}

/**
 * How far through setup are they? Drives the checklist on the settings page.
 *
 * @return array<int,array{done:bool,label:string,detail:string}>
 */
function sendbeam_setup_steps() {
	$settings   = sendbeam_settings();
	$connection = sendbeam_connection();
	$connected  = in_array( $connection['state'], array( 'ok', 'no_scope' ), true );

	$using_form  = '' !== $settings['default_form'] || '' !== $settings['contact_form'];
	$using_popup = (bool) sendbeam_popups();
	$using_mail  = ! empty( $settings['mail_enabled'] );

	return array(
		array(
			'done'   => $connected,
			'label'  => __( 'Connect your SendBeam account', 'sendbeam' ),
			'detail' => $connected
				? __( 'Your key works.', 'sendbeam' )
				: __( 'Paste an API key so this plugin can list your forms for you.', 'sendbeam' ),
		),
		array(
			'done'   => $using_form || $using_popup,
			'label'  => __( 'Put a form on the site', 'sendbeam' ),
			'detail' => ( $using_form || $using_popup )
				? __( 'A form is chosen. Add the SendBeam block to any page, or use the pop-up.', 'sendbeam' )
				: __( 'Choose a signup, contact or pop-up form below.', 'sendbeam' ),
		),
		array(
			'done'   => $using_mail,
			'label'  => __( 'Send this site\'s email through SendBeam', 'sendbeam' ),
			'detail' => $using_mail
				? __( 'On. Send yourself a test to be sure.', 'sendbeam' )
				: __( 'Optional. Routes password resets, receipts and notifications through your verified domain.', 'sendbeam' ),
		),
	);
}

/**
 * The one notice.
 */
function sendbeam_setup_notice() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( $screen && 'settings_page_sendbeam' === $screen->id ) {
		return; // They are already here.
	}

	if ( get_user_meta( get_current_user_id(), 'sendbeam_setup_dismissed', true ) ) {
		return;
	}

	if ( sendbeam_is_connected() ) {
		return; // Resolved: the notice removes itself.
	}

	$settings_url = admin_url( 'options-general.php?page=sendbeam' );
	$dismiss_url  = wp_nonce_url( admin_url( 'admin-post.php?action=sendbeam_dismiss_setup' ), 'sendbeam_dismiss_setup' );
	?>
	<div class="notice notice-info is-dismissible">
		<p>
			<strong><?php esc_html_e( 'SendBeam is installed.', 'sendbeam' ); ?></strong>
			<?php esc_html_e( 'Connect your account and the plugin will list your forms for you, so you never have to copy an ID by hand.', 'sendbeam' ); ?>
		</p>
		<p>
			<a href="<?php echo esc_url( $settings_url ); ?>" class="button button-primary"><?php esc_html_e( 'Set up SendBeam', 'sendbeam' ); ?></a>
			<a href="<?php echo esc_url( $dismiss_url ); ?>" class="button-link" style="margin-left:.75rem"><?php esc_html_e( 'Not now', 'sendbeam' ); ?></a>
		</p>
	</div>
	<?php
}

/**
 * "Not now" — remembered per user, not per site.
 */
function sendbeam_dismiss_setup() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'sendbeam' ) );
	}
	check_admin_referer( 'sendbeam_dismiss_setup' );
	update_user_meta( get_current_user_id(), 'sendbeam_setup_dismissed', 1 );
	wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
	exit;
}
