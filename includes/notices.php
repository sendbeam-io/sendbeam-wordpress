<?php
/**
 * Everything this plugin says to a site owner, in one place.
 *
 * Each screen used to print its own `<p class="sb-msg">` band somewhere in
 * the middle of its own markup, which meant the answer to "did that save?"
 * appeared in a different place on every screen and in the plugin's own
 * styling rather than WordPress's. They are all admin notices now, in core's
 * markup, above the page — which is where a person who has just pressed a
 * button is looking.
 *
 * Two rules, both from what other plugins get wrong:
 *
 *   - They appear only on SendBeam's own screens. The one exception is the
 *     setup notice, which has to be somewhere a new site owner will see it,
 *     and which goes away for good the first time it is dismissed.
 *   - Every one of them is dismissible. A notice that cannot be closed is the
 *     single most complained-about thing a WordPress plugin does.
 *
 * @package SendBeam
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_notices', 'sendbeam_admin_notices' );

/**
 * The sentence behind each query argument a handler redirects with.
 *
 * Every one of these arrives on a redirect this plugin issued itself, so the
 * only thing read out of the URL is which of a fixed list of sentences to
 * print. Nothing from the address bar is ever echoed.
 *
 * @return array<string,array<string,array{0:string,1:string}>>
 */
function sendbeam_notice_messages() {
	return array(
		'sendbeam_domain'          => array(
			'verified'      => array( 'success', __( 'Verified. SendBeam can now send email as your domain.', 'sendbeam' ) ),
			'verified_mail' => array( 'success', __( 'Verified, and this site\'s email has been switched on — it was waiting on exactly this. Send yourself a test below.', 'sendbeam' ) ),
			'pending'       => array( 'warning', __( 'Not verified yet. DNS changes can take anything from a few minutes to a day to spread; the records are below, unchanged.', 'sendbeam' ) ),
			'nodomain'      => array( 'warning', __( 'There is no sending domain on this workspace yet. Add one under Sending in SendBeam.', 'sendbeam' ) ),
			'unreachable'   => array( 'error', __( 'Could not ask SendBeam to check just now. Nothing was changed.', 'sendbeam' ) ),
		),
		'sendbeam_form'            => array(
			'placed'   => array( 'success', __( 'Noted — the form step is ticked. Say so again if you take the form off the site.', 'sendbeam' ) ),
			'unplaced' => array( 'success', __( 'The form step will look for itself again.', 'sendbeam' ) ),
		),
		'sendbeam_mail'            => array(
			'on'         => array( 'success', __( 'Site email is on. Password resets, receipts and notifications now go out through SendBeam.', 'sendbeam' ) ),
			'noscope'    => array( 'error', __( 'This site\'s key was not given permission to send your site\'s email, so it was not switched on. Reconnect and tick that box.', 'sendbeam' ) ),
			'unverified' => array( 'error', __( 'The sending domain is not verified, so site email was not switched on. Finish step 2 first.', 'sendbeam' ) ),
		),
		'sendbeam_disconnected'    => array(
			'ok'          => array( 'success', __( 'Disconnected. The key was revoked in SendBeam.', 'sendbeam' ) ),
			'unreachable' => array( 'warning', __( 'Disconnected, but this server could not reach SendBeam; revoke it under Settings → API keys.', 'sendbeam' ) ),
			'constant'    => array( 'warning', __( 'Disconnected here, and nothing was revoked: this site sends with the key defined as SENDBEAM_API_KEY in wp-config.php, which is not the one this button issued. Remove it there, and revoke it under Settings → API keys.', 'sendbeam' ) ),
		),
		'sendbeam_sender'          => array(
			'own'        => array( 'success', __( 'This site now sends as your own domain. Send yourself a test to be sure.', 'sendbeam' ) ),
			'unverified' => array( 'warning', __( 'There is no verified sending domain to send from yet, so nothing was changed. Finish step 2 first.', 'sendbeam' ) ),
		),
		'sendbeam_sync_saved'      => array(
			'1' => array( 'success', __( 'Saved. The opt-in box is live where you asked for it.', 'sendbeam' ) ),
		),
		'sendbeam_bridges_saved'   => array(
			'1' => array( 'success', __( 'Saved. Those forms now send their submissions to SendBeam.', 'sendbeam' ) ),
		),
		'sendbeam_ecommerce_saved' => array(
			'1' => array( 'success', __( 'Saved. The events you ticked will reach SendBeam from now on.', 'sendbeam' ) ),
		),
	);
}

/**
 * Print whatever the last button did.
 */
function sendbeam_admin_notices() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	if ( ! sendbeam_is_our_screen() ) {
		sendbeam_setup_notice();
		return;
	}

	sendbeam_dns_return_notice();

	foreach ( sendbeam_notice_messages() as $arg => $values ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- chooses one of a fixed list of sentences; set by this plugin's own redirect.
		$value = isset( $_GET[ $arg ] ) ? sanitize_key( wp_unslash( $_GET[ $arg ] ) ) : '';
		if ( '' === $value || ! isset( $values[ $value ] ) ) {
			continue;
		}
		sendbeam_notice( esc_html( $values[ $value ][1] ), $values[ $value ][0] );
	}

	// Pop-ups report a count, so they cannot come out of a fixed table.
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a number this plugin put on its own redirect.
	if ( isset( $_GET['sendbeam_saved'] ) ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- as above.
		$saved = max( 0, (int) $_GET['sendbeam_saved'] );
		sendbeam_notice(
			esc_html( sprintf( /* translators: %d: number of pop-ups */ _n( '%d pop-up saved.', '%d pop-ups saved.', $saved, 'sendbeam' ), $saved ) ),
			'success'
		);
	}

	if ( ! empty( $GLOBALS['sendbeam_deleted'] ) ) {
		$gone = (int) $GLOBALS['sendbeam_deleted'];
		sendbeam_notice(
			esc_html( sprintf( /* translators: %d: number of log rows */ _n( '%d message removed from the log.', '%d messages removed from the log.', $gone, 'sendbeam' ), $gone ) ),
			'success'
		);
	}
}

/**
 * Back from the registrar, and how it went.
 *
 * Not in the table above because the failure carries SendBeam's own reason
 * code, and the table is fixed sentences.
 */
function sendbeam_dns_return_notice() {
	$returned = sendbeam_overview_dns_return();
	if ( 'done' === $returned ) {
		// Past tense on purpose: the check runs as this screen renders, so by
		// the time anybody reads this the step underneath is already showing
		// what it found. "Checking now…" promised a page that was about to
		// change and then never changed.
		sendbeam_notice( esc_html__( 'Your registrar added the records. Checked just now.', 'sendbeam' ), 'success' );
		return;
	}
	if ( 'error' !== $returned ) {
		return;
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- SendBeam's own reason code, sanitised to a key and printed as words.
	$reason = isset( $_GET['sb_dc_reason'] ) ? sanitize_key( wp_unslash( $_GET['sb_dc_reason'] ) ) : '';
	sendbeam_notice(
		esc_html(
			sprintf(
				/* translators: %s: SendBeam's reason the registrar refused, e.g. "state mismatch" */
				__( 'Your registrar could not add the records (%s). Add them by hand below.', 'sendbeam' ),
				'' !== $reason ? str_replace( '_', ' ', substr( $reason, 0, 60 ) ) : __( 'no reason given', 'sendbeam' )
			)
		),
		'error'
	);
}
