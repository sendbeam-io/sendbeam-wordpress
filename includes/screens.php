<?php
/**
 * The tab screens.
 *
 * Each one is a small function so the router stays readable. Anything that
 * saves posts to options.php in the 'sendbeam' option group and carries a
 * hidden `_tab`, which is what lets a tab save its own fields without
 * flattening the settings belonging to the other tabs.
 *
 * @package SendBeam
 */

defined( 'ABSPATH' ) || exit;

/**
 * Open a settings form for one tab.
 *
 * @param string $tab Tab key.
 */
function sendbeam_form_open( $tab ) {
	echo '<form action="' . esc_url( admin_url( 'options.php' ) ) . '" method="post">';
	settings_fields( 'sendbeam' );
	printf( '<input type="hidden" name="sendbeam_settings[_tab]" value="%s" />', esc_attr( $tab ) );
}

/** Close a settings form. */
function sendbeam_form_close() {
	submit_button( __( 'Save changes', 'sendbeam' ) );
	echo '</form>';
}

/* -------------------------------------------------------------- Overview */

function sendbeam_screen_overview() {
	$settings = sendbeam_settings();
	$forms    = sendbeam_remote_forms();
	$lists    = sendbeam_lists();

	$subscribers = null;
	if ( is_array( $lists ) ) {
		$subscribers = 0;
		foreach ( $lists as $list ) {
			$subscribers += (int) $list['count'];
		}
	}

	echo '<div class="sb-grid">';

	sendbeam_card_open( __( 'Setup', 'sendbeam' ) );
	$steps = sendbeam_setup_steps();
	echo '<ol class="sb-steps">';
	$targets = array( sendbeam_tab_url( 'overview' ) . '#sendbeam_api_key', sendbeam_tab_url( 'forms' ), sendbeam_tab_url( 'mail' ) );
	foreach ( $steps as $i => $step ) {
		printf(
			'<li class="%1$s"><span class="sb-num" aria-hidden="true">%2$s</span><span><strong><a href="%3$s">%4$s</a></strong><span>%5$s</span></span></li>',
			$step['done'] ? 'is-done' : '',
			$step['done'] ? '&#10003;' : (int) ( $i + 1 ),
			esc_url( $targets[ $i ] ),
			esc_html( $step['label'] ),
			esc_html( $step['detail'] )
		);
	}
	echo '</ol>';
	sendbeam_card_close();

	sendbeam_card_open( __( 'This workspace', 'sendbeam' ) );
	echo '<div class="sb-grid" style="gap:14px">';
	sendbeam_stat( __( 'Forms', 'sendbeam' ), is_array( $forms ) ? count( $forms ) : null );
	sendbeam_stat( __( 'Lists', 'sendbeam' ), is_array( $lists ) ? count( $lists ) : null );
	sendbeam_stat( __( 'Subscribers', 'sendbeam' ), $subscribers );
	echo '</div>';
	echo '<p class="sb-note" style="margin-top:12px">' . esc_html__( 'Counts come from your SendBeam workspace and are cached for five minutes.', 'sendbeam' ) . '</p>';
	sendbeam_card_close();

	echo '</div>';

	sendbeam_card_open( __( 'Connect', 'sendbeam' ) );
	sendbeam_form_open( 'connect' );
	do_settings_sections( 'sendbeam_connect_page' );
	sendbeam_form_close();
	sendbeam_card_close();
}

/**
 * One figure in a card.
 *
 * @param string   $label Label.
 * @param int|null $value Value, or null when it cannot be read.
 */
function sendbeam_stat( $label, $value ) {
	echo '<div><span class="sb-label">' . esc_html( $label ) . '</span>';
	echo '<span class="sb-fig">' . ( null === $value ? '&mdash;' : esc_html( number_format_i18n( $value ) ) ) . '</span></div>';
}

/* ----------------------------------------------------------------- Forms */

function sendbeam_screen_forms() {
	sendbeam_card_open( __( 'Defaults', 'sendbeam' ), __( 'Used when a block or shortcode does not name a form.', 'sendbeam' ) );
	sendbeam_form_open( 'forms' );
	do_settings_sections( 'sendbeam_forms_page' );
	sendbeam_form_close();
	sendbeam_card_close();

	$forms = sendbeam_remote_forms();

	sendbeam_card_open( __( 'Your forms', 'sendbeam' ), is_array( $forms ) ? sprintf( /* translators: %d: count */ _n( '%d form', '%d forms', count( $forms ), 'sendbeam' ), count( $forms ) ) : '' );

	if ( null === $forms ) {
		echo '<p>' . esc_html__( 'Add an API key with the Forms (read) permission on the Overview tab and every form in this workspace will be listed here, ready to drop into a page.', 'sendbeam' ) . '</p>';
	} elseif ( ! $forms ) {
		echo '<p>' . wp_kses(
			sprintf(
				/* translators: %s: link */
				__( 'No forms in this workspace yet. %s.', 'sendbeam' ),
				'<a href="' . esc_url( sendbeam_app_url() . '/forms' ) . '" target="_blank" rel="noopener">' . esc_html__( 'Create your first form', 'sendbeam' ) . '</a>'
			),
			array( 'a' => array( 'href' => array(), 'target' => array(), 'rel' => array() ) )
		) . '</p>';
	} else {
		echo '<table class="sb-table"><thead><tr>';
		echo '<th>' . esc_html__( 'Form', 'sendbeam' ) . '</th>';
		echo '<th>' . esc_html__( 'Kind', 'sendbeam' ) . '</th>';
		echo '<th>' . esc_html__( 'Shortcode', 'sendbeam' ) . '</th>';
		echo '<th></th></tr></thead><tbody>';
		foreach ( $forms as $form ) {
			$id        = (string) $form['id'];
			$kind      = isset( $form['kind'] ) ? (string) $form['kind'] : 'signup';
			$name      = isset( $form['name'] ) && '' !== $form['name'] ? (string) $form['name'] : __( '(untitled form)', 'sendbeam' );
			$shortcode = 'contact' === $kind ? '[sendbeam_contact]' : '[sendbeam_form id="' . $id . '"]';
			?>
			<tr>
				<td><strong><?php echo esc_html( $name ); ?></strong></td>
				<td><span class="sb-chip sb-chip--<?php echo esc_attr( 'contact' === $kind ? 'contact' : 'signup' ); ?>"><?php echo esc_html( $kind ); ?></span></td>
				<td><code><?php echo esc_html( $shortcode ); ?></code></td>
				<td style="text-align:right;white-space:nowrap">
					<button type="button" class="sb-btn sb-btn--small sb-copy" data-copy="<?php echo esc_attr( $shortcode ); ?>"><?php esc_html_e( 'Copy', 'sendbeam' ); ?></button>
					<a class="sb-btn sb-btn--small sb-btn--ghost" href="<?php echo esc_url( sendbeam_form_url( $id, false ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Preview', 'sendbeam' ); ?></a>
				</td>
			</tr>
			<?php
		}
		echo '</tbody></table>';
		echo '<p class="sb-note" style="margin-top:12px">' . esc_html__( 'Paste a shortcode anywhere, or add the SendBeam Form block and pick the form from its dropdown. Every form on this list can be used as many times as you like, on as many pages as you like.', 'sendbeam' ) . '</p>';
	}
	sendbeam_card_close();
}

/* -------------------------------------------------------------- Audience */

function sendbeam_screen_audience() {
	$lists = sendbeam_lists();

	sendbeam_card_open( __( 'Lists', 'sendbeam' ), is_array( $lists ) ? sprintf( /* translators: %d: count */ _n( '%d list', '%d lists', count( $lists ), 'sendbeam' ), count( $lists ) ) : '' );

	if ( null === $lists ) {
		echo '<p>' . esc_html__( 'Your lists cannot be read with the current key. Add the Lists (read) permission to it in SendBeam to see them here.', 'sendbeam' ) . '</p>';
	} elseif ( ! $lists ) {
		echo '<p>' . esc_html__( 'This workspace has no lists yet.', 'sendbeam' ) . '</p>';
	} else {
		echo '<table class="sb-table"><thead><tr>';
		echo '<th>' . esc_html__( 'List', 'sendbeam' ) . '</th>';
		echo '<th>' . esc_html__( 'Subscribers', 'sendbeam' ) . '</th>';
		echo '<th>' . esc_html__( 'Confirmation', 'sendbeam' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $lists as $list ) {
			?>
			<tr>
				<td><strong><?php echo esc_html( $list['name'] ); ?></strong></td>
				<td class="sb-mono"><?php echo null === $list['count'] ? '&mdash;' : esc_html( number_format_i18n( $list['count'] ) ); ?></td>
				<td>
					<?php if ( $list['double_optin'] ) : ?>
						<span class="sb-chip"><?php esc_html_e( 'Double opt-in', 'sendbeam' ); ?></span>
					<?php else : ?>
						<span class="sb-note"><?php esc_html_e( 'Single opt-in', 'sendbeam' ); ?></span>
					<?php endif; ?>
				</td>
			</tr>
			<?php
		}
		echo '</tbody></table>';
	}
	echo '<p class="sb-note" style="margin-top:12px">' . esc_html__( 'Which list a form subscribes people to is set on the form itself, in SendBeam.', 'sendbeam' ) . '</p>';
	sendbeam_card_close();
}

/* --------------------------------------------------------------- Pop-ups */

function sendbeam_screen_popup() {
	sendbeam_card_open( __( 'Pop-up', 'sendbeam' ) );
	sendbeam_form_open( 'popup' );
	do_settings_sections( 'sendbeam_popup_page' );
	sendbeam_form_close();
	sendbeam_card_close();
}

/* ------------------------------------------------------------ Site email */

function sendbeam_screen_mail() {
	sendbeam_card_open( __( 'Site email', 'sendbeam' ) );
	sendbeam_form_open( 'mail' );
	do_settings_sections( 'sendbeam_mail_page' );
	sendbeam_form_close();
	sendbeam_card_close();

	sendbeam_card_open( __( 'Test', 'sendbeam' ) );
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- notice text only, set by our own redirect.
	if ( isset( $_GET['sendbeam_test'] ) ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$ok = 'ok' === $_GET['sendbeam_test'];
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$note = isset( $_GET['sendbeam_note'] ) ? rawurldecode( sanitize_text_field( wp_unslash( $_GET['sendbeam_note'] ) ) ) : '';
		echo '<p class="sb-msg ' . ( $ok ? 'sb-msg--ok' : 'sb-msg--bad' ) . '">' . esc_html( $note ) . '</p>';
	}
	?>
	<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
		<input type="hidden" name="action" value="sendbeam_test_mail" />
		<?php wp_nonce_field( 'sendbeam_test_mail' ); ?>
		<p><?php echo esc_html( sprintf( /* translators: %s: email address */ __( 'Sends a short email to %s through SendBeam, using the settings above.', 'sendbeam' ), wp_get_current_user()->user_email ) ); ?></p>
		<button type="submit" class="sb-btn"><?php esc_html_e( 'Send a test email', 'sendbeam' ); ?></button>
	</form>
	<?php
	sendbeam_card_close();

	$log = get_option( 'sendbeam_mail_log', array() );
	if ( is_array( $log ) && $log ) {
		sendbeam_card_open( __( 'Recent site email', 'sendbeam' ) );
		echo '<table class="sb-table"><thead><tr>';
		echo '<th>' . esc_html__( 'When', 'sendbeam' ) . '</th><th>' . esc_html__( 'To', 'sendbeam' ) . '</th>';
		echo '<th>' . esc_html__( 'Subject', 'sendbeam' ) . '</th><th>' . esc_html__( 'Result', 'sendbeam' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $log as $row ) {
			$result = 'sent' === $row['result'] ? __( 'Sent via SendBeam', 'sendbeam' ) : ( 'fallback' === $row['result'] ? __( 'Server mailer', 'sendbeam' ) : __( 'Failed', 'sendbeam' ) );
			echo '<tr>';
			echo '<td class="sb-mono">' . esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $row['at'] ) ) . '</td>';
			echo '<td>' . esc_html( $row['to'] ) . '</td>';
			echo '<td>' . esc_html( $row['subject'] ) . '</td>';
			echo '<td>' . esc_html( $result . ( $row['note'] ? ' — ' . $row['note'] : '' ) ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
		sendbeam_card_close();
	}
}
