<?php
/**
 * The first run: four steps, and a way out of every one of them.
 *
 * The plugin used to do nothing on activation but leave a notice on the
 * Dashboard. That is the most polite option and it is also the one that
 * leaves a site owner with a plugin they have not connected — which is a
 * plugin that does nothing at all.
 *
 * So: one redirect, once, guarded three ways, into a page that can be left
 * from every step. Nothing here is a takeover. The admin menu and the admin
 * bar stay exactly where they are, "Skip this step" is on every step, and
 * "Go back to the Dashboard" is under all of them. Where the wizard needs to
 * ask something it asks it with the same panel the settings screens use, so
 * there is one Connect button in this plugin, not two.
 *
 * @package SendBeam
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_init', 'sendbeam_maybe_redirect_to_wizard', 9999 );
add_action( 'admin_post_sendbeam_wizard_step', 'sendbeam_handle_wizard_step' );
add_action( 'admin_post_sendbeam_wizard_restart', 'sendbeam_handle_wizard_restart' );

/** How long the activation flag lives. Long enough for the next page load, and no longer. */
const SENDBEAM_WIZARD_FLAG = 'sendbeam_activation_redirect';

/** Where the wizard has got to, per user: a wizard is a person's progress, not a site's. */
const SENDBEAM_WIZARD_STEP = 'sendbeam_wizard_step';

/**
 * The four steps, in order.
 *
 * @return array<int,array{key:string,label:string}>
 */
function sendbeam_wizard_steps() {
	return array(
		array(
			'key'   => 'connect',
			'label' => __( 'Connect', 'sendbeam' ),
		),
		array(
			'key'   => 'domain',
			'label' => __( 'Sending domain', 'sendbeam' ),
		),
		array(
			'key'   => 'mail',
			'label' => __( 'Site email', 'sendbeam' ),
		),
		array(
			'key'   => 'form',
			'label' => __( 'Place a form', 'sendbeam' ),
		),
	);
}

/**
 * Which step is showing: the URL, else where this person got to, else the first.
 *
 * @return int A 1-based step number.
 */
function sendbeam_wizard_current_step() {
	$max = count( sendbeam_wizard_steps() );
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation between steps of a form nobody has submitted yet.
	$asked = isset( $_GET['step'] ) ? (int) $_GET['step'] : 0;
	if ( $asked >= 1 && $asked <= $max ) {
		return $asked;
	}
	$saved = (int) get_user_meta( get_current_user_id(), SENDBEAM_WIZARD_STEP, true );
	return ( $saved >= 1 && $saved <= $max ) ? $saved : 1;
}

/**
 * The URL of one step.
 *
 * @param int $step 1-based step number.
 * @return string
 */
function sendbeam_wizard_url( $step = 1 ) {
	return add_query_arg( 'step', max( 1, (int) $step ), sendbeam_page_url( 'sendbeam-setup' ) );
}

/**
 * Has this site finished, or deliberately left, the wizard?
 *
 * @return bool
 */
function sendbeam_wizard_done() {
	return (bool) get_option( 'sendbeam_setup_done' );
}

/**
 * Mark the wizard finished, for everyone on the site.
 *
 * Finishing is about the site rather than the person: a second administrator
 * arriving at a connected site should not be walked through connecting it.
 */
function sendbeam_wizard_finish() {
	update_option( 'sendbeam_setup_done', 1, false );
	delete_user_meta( get_current_user_id(), SENDBEAM_WIZARD_STEP );
}

/**
 * Send someone to the wizard once, on the page load after activation.
 *
 * Three guards, and each one is a real case rather than defensive padding:
 * `activate-multi` is somebody activating twenty plugins at once and being
 * thrown at the first one's welcome screen, which is the behaviour that makes
 * people hate this pattern; a site that already has a key has already done
 * this; and a host or an agency that never wants it can switch it off with
 * one filter.
 */
function sendbeam_maybe_redirect_to_wizard() {
	if ( ! get_transient( SENDBEAM_WIZARD_FLAG ) ) {
		return;
	}
	// Once, whatever happens next — including a redirect somebody's browser
	// never follows.
	delete_transient( SENDBEAM_WIZARD_FLAG );

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- core's own flag on the Plugins screen; read, never acted on.
	if ( isset( $_GET['activate-multi'] ) ) {
		return;
	}
	if ( ! current_user_can( 'manage_options' ) || wp_doing_ajax() || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
		return;
	}
	if ( sendbeam_wizard_done() || '' !== sendbeam_api_key() ) {
		return;
	}
	/**
	 * Whether to offer the first-run wizard at all.
	 *
	 * A host that installs this plugin for its customers, or an agency
	 * rolling it out across fifty sites, has already done the setting up.
	 *
	 * @param bool $show Whether to redirect to the wizard on activation.
	 */
	if ( ! apply_filters( 'sendbeam_setup_wizard', true ) ) {
		return;
	}

	wp_safe_redirect( sendbeam_wizard_url( 1 ) );
	exit;
}

/**
 * Skip, continue, or leave.
 *
 * All three are the same POST, because all three are "remember where I am and
 * put me somewhere else" — and a skip that used a GET link would be followed
 * by a link prefetcher on somebody's behalf.
 */
function sendbeam_handle_wizard_step() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'sendbeam' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'sendbeam_wizard_step' );

	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked immediately above.
	$step = isset( $_POST['step'] ) ? (int) $_POST['step'] : 1;
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked immediately above.
	$go = isset( $_POST['go'] ) ? sanitize_key( wp_unslash( $_POST['go'] ) ) : 'next';

	$max  = count( sendbeam_wizard_steps() );
	$next = min( $max, max( 1, $step + 1 ) );

	if ( 'exit' === $go ) {
		// Left, not finished. Where they got to is kept, so the link on the
		// Overview picks the wizard up rather than restarting it.
		update_user_meta( get_current_user_id(), SENDBEAM_WIZARD_STEP, max( 1, min( $max, $step ) ) );
		wp_safe_redirect( admin_url() );
		exit;
	}

	if ( 'finish' === $go || $step >= $max ) {
		sendbeam_wizard_finish();
		wp_safe_redirect( sendbeam_page_url( 'sendbeam' ) );
		exit;
	}

	update_user_meta( get_current_user_id(), SENDBEAM_WIZARD_STEP, $next );
	wp_safe_redirect( sendbeam_wizard_url( $next ) );
	exit;
}

/**
 * The wizard page.
 *
 * Inside `.wrap`, with the admin bar and menu left alone. Full-screen routes
 * are the other convention and they are the one that traps people: a wizard
 * that hides the way out is a wizard somebody closes the tab on.
 */
function sendbeam_screen_wizard() {
	$steps   = sendbeam_wizard_steps();
	$current = sendbeam_wizard_current_step();
	$total   = count( $steps );
	$step    = $steps[ $current - 1 ];
	$last    = $current >= $total;
	?>
	<div class="wrap sendbeam-app sendbeam-wizard">
		<h1 class="screen-reader-text">
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: step number, 2: total steps, 3: the step's name */
					__( 'Set up SendBeam — step %1$d of %2$d, %3$s', 'sendbeam' ),
					$current,
					$total,
					$step['label']
				)
			);
			?>
		</h1>

		<div class="sb-head">
			<div class="sb-head__brand">
				<span class="sb-mark" aria-hidden="true"></span>
				<span class="sb-wordmark">SendBeam</span>
				<span class="sb-head__where" aria-hidden="true">/</span>
				<span class="sb-head__title"><?php esc_html_e( 'Set up', 'sendbeam' ); ?></span>
			</div>
			<div class="sb-head__right">
				<span class="sb-note sb-wizard__count">
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: step number, 2: total steps */
							__( 'Step %1$d of %2$d', 'sendbeam' ),
							$current,
							$total
						)
					);
					?>
				</span>
			</div>
		</div>

		<?php sendbeam_wizard_rail( $steps, $current ); ?>

		<div class="sb-wrap">
			<?php
			sendbeam_card_open( sendbeam_wizard_heading( $step['key'] ) );
			call_user_func( 'sendbeam_wizard_step_' . $step['key'] );
			sendbeam_card_close();
			sendbeam_wizard_foot( $current, $last );
			?>
		</div>
	</div>
	<?php
}

/**
 * The progress rail: where you are, what is behind you, what is left.
 *
 * @param array $steps   Every step.
 * @param int   $current 1-based step number.
 */
function sendbeam_wizard_rail( $steps, $current ) {
	/*
	 * A tick means done, not "behind us". Walking forward past a step — and
	 * every step has Skip this step on it — used to tick it, so opening
	 * step 3 on a site with no key at all showed "✓ Connect ✓ Sending
	 * domain" above a body reading "This step needs a connected site". The
	 * Overview's checklist has always been able to answer this properly;
	 * the rail reads the same answer now.
	 */
	$done = array();
	foreach ( sendbeam_setup_steps() as $known ) {
		$done[ $known['key'] ] = ! empty( $known['done'] );
	}

	echo '<ol class="sb-rail">';
	foreach ( $steps as $i => $step ) {
		$number    = $i + 1;
		$is_done   = ! empty( $done[ $step['key'] ] );
		$state     = 'sb-rail__step';
		if ( $is_done ) {
			$state .= ' is-done';
		}
		if ( $number === $current ) {
			$state .= ' is-current';
		}
		printf(
			'<li class="%1$s"%2$s><span class="sb-rail__num" aria-hidden="true">%3$s</span><span class="sb-rail__label">%4$s</span></li>',
			esc_attr( $state ),
			$number === $current ? ' aria-current="step"' : '',
			$is_done ? '&#10003;' : (int) $number,
			esc_html( $step['label'] )
		);
	}
	echo '</ol>';
}

/**
 * The heading above one step.
 *
 * @param string $key Step key.
 * @return string
 */
function sendbeam_wizard_heading( $key ) {
	$headings = array(
		'connect' => __( 'Connect your SendBeam account', 'sendbeam' ),
		'domain'  => __( 'Verify the domain your email is sent from', 'sendbeam' ),
		'mail'    => __( 'Send this site\'s email through SendBeam', 'sendbeam' ),
		'form'    => __( 'Put a form on the site', 'sendbeam' ),
	);
	return isset( $headings[ $key ] ) ? $headings[ $key ] : __( 'Set up SendBeam', 'sendbeam' );
}

/**
 * The controls under every step.
 *
 * Skip and Continue are the same button in two moods: both move on, and the
 * only difference is whether the step was finished. Saying so plainly is
 * better than hiding Skip on the steps somebody most wants to skip.
 *
 * @param int  $current 1-based step number.
 * @param bool $last    Whether this is the final step.
 */
function sendbeam_wizard_foot( $current, $last ) {
	echo '<form class="sb-wizard__foot" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post">';
	wp_nonce_field( 'sendbeam_wizard_step' );
	echo '<input type="hidden" name="action" value="sendbeam_wizard_step" />';
	printf( '<input type="hidden" name="step" value="%d" />', (int) $current );

	printf(
		'<button type="submit" class="sb-btn sb-btn--primary" name="go" value="%1$s">%2$s</button>',
		esc_attr( $last ? 'finish' : 'next' ),
		esc_html( $last ? __( 'Finish', 'sendbeam' ) : __( 'Continue', 'sendbeam' ) )
	);

	if ( ! $last ) {
		printf(
			'<button type="submit" class="sb-btn sb-btn--ghost" name="go" value="next">%s</button>',
			esc_html__( 'Skip this step', 'sendbeam' )
		);
	}

	printf(
		'<button type="submit" class="sb-link" name="go" value="exit">%s</button>',
		esc_html__( 'Go back to the Dashboard', 'sendbeam' )
	);
	echo '</form>';
	echo '<p class="sb-note sb-wizard__note">' . esc_html__( 'Nothing here has to be done now. Every step is also on the Overview, and skipping one leaves it there for later.', 'sendbeam' ) . '</p>';
}

/**
 * Step 1: the Connect panel, exactly as the Settings page shows it.
 */
function sendbeam_wizard_step_connect() {
	if ( sendbeam_is_connected() ) {
		$status    = sendbeam_connect_status();
		$workspace = sendbeam_connect_workspace_name();
		if ( '' === $workspace ) {
			$workspace = (string) $status['workspace']['name'];
		}
		echo '<p class="sb-msg sb-msg--ok">' . esc_html(
			'' !== $workspace
				/* translators: %s: the SendBeam workspace name */
				? sprintf( __( 'Connected to %s. Nothing more to do here.', 'sendbeam' ), $workspace )
				: __( 'This site is connected. Nothing more to do here.', 'sendbeam' )
		) . '</p>';
		return;
	}
	sendbeam_connect_panel();
	sendbeam_connect_paste_disclosure();
}

/**
 * Step 2: the sending-domain panel, exactly as the Settings page shows it.
 */
function sendbeam_wizard_step_domain() {
	if ( ! sendbeam_is_connected() ) {
		echo '<p class="sb-msg sb-msg--warn">' . esc_html__( 'This step needs a connected site. Go back to step 1, or skip this one and come back to it from the Overview.', 'sendbeam' ) . '</p>';
		return;
	}
	sendbeam_domain_panel( sendbeam_connect_status(), true );
}

/**
 * Step 3: the site-email settings and the test send, as the Site email screen has them.
 */
function sendbeam_wizard_step_mail() {
	if ( ! sendbeam_is_connected() ) {
		echo '<p class="sb-msg sb-msg--warn">' . esc_html__( 'This step needs a connected site. Go back to step 1, or skip this one and come back to it from the Overview.', 'sendbeam' ) . '</p>';
		return;
	}

	$status = sendbeam_connect_status();
	echo '<p style="margin-top:0">' . esc_html__( 'Optional, and easy to change later. With this on, the email WordPress already sends — password resets, receipts, comment and form notifications — goes out through your verified SendBeam domain instead of the web server\'s own mailer.', 'sendbeam' ) . '</p>';

	sendbeam_overview_mail_panel( $status, true );

	$settings = sendbeam_settings();
	if ( empty( $settings['mail_enabled'] ) && empty( $status['domain']['verified'] ) ) {
		echo '<p class="sb-note">' . esc_html__( 'Site email waits on step 2: a site must never have its password resets routed through a domain nothing has verified yet.', 'sendbeam' ) . '</p>';
	}
}

/**
 * Step 4: the form, and the two ways it gets onto a page.
 */
function sendbeam_wizard_step_form() {
	if ( ! sendbeam_is_connected() ) {
		echo '<p class="sb-msg sb-msg--warn">' . esc_html__( 'This step needs a connected site. Go back to step 1, or skip this one and come back to it from the Overview.', 'sendbeam' ) . '</p>';
		return;
	}

	$steps = sendbeam_setup_steps();
	$step  = $steps[2];

	echo '<p style="margin-top:0">' . esc_html( $step['detail'] ) . '</p>';
	echo '<p>' . wp_kses(
		__( 'In the editor, press the <strong>+</strong> button and search for "SendBeam", or type <code>/sendbeam</code> on a new line.', 'sendbeam' ),
		array(
			'strong' => array(),
			'code'   => array(),
		)
	) . '</p>';

	sendbeam_overview_form_panel( $step, true );
}

/**
 * The way back into the guide, once somebody has been through it.
 *
 * Every plugin in the field study with a wizard offers one — WooCommerce and
 * WP Mail SMTP both keep theirs reachable for good — because the first run is
 * also the best way to check a change, and because somebody setting up a
 * second site wants the same walk-through on it.
 *
 * It resets where *this person* got to and nothing else. `sendbeam_setup_done`
 * is the site's answer to "has anybody set this up?", which is what keeps the
 * Dashboard notice away, and re-reading the guide is not un-setting-up a site.
 *
 * @return string
 */
function sendbeam_wizard_restart_url() {
	return wp_nonce_url(
		add_query_arg( 'action', 'sendbeam_wizard_restart', admin_url( 'admin-post.php' ) ),
		'sendbeam_wizard_restart'
	);
}

/** Put this person back at step 1 and open the guide. */
function sendbeam_handle_wizard_restart() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'sendbeam' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'sendbeam_wizard_restart' );

	delete_user_meta( get_current_user_id(), SENDBEAM_WIZARD_STEP );

	wp_safe_redirect( sendbeam_wizard_url( 1 ) );
	exit;
}
