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

/**
 * Overview tab: how far through set-up the site is, what the workspace holds, and the API key.
 */
function sendbeam_screen_overview() {
	$forms = sendbeam_remote_forms();
	$lists = sendbeam_lists();

	// Asked for directly, not summed from the lists: one person on three lists
	// is one subscriber, and adding the lists up counted them three times.
	$subscribers = sendbeam_subscriber_count();
	$connected   = sendbeam_is_connected();
	$status      = $connected ? sendbeam_connect_status() : sendbeam_connect_empty_status();

	sendbeam_overview_notices();

	echo '<div class="sb-grid">';

	sendbeam_card_open( __( 'Setup', 'sendbeam' ) );
	echo '<ol class="sb-steps">';
	foreach ( sendbeam_setup_steps() as $i => $step ) {
		printf(
			'<li class="%1$s"><span class="sb-num" aria-hidden="true">%2$s</span><div class="sb-step">',
			$step['done'] ? 'is-done' : '',
			$step['done'] ? '&#10003;' : (int) ( $i + 1 )
		);

		// The connect step is not a link to a field any more when there is
		// nothing connected: it is the thing itself, done here, in one button.
		$linked = ! ( 'connect' === $step['key'] && ! $connected );
		if ( $linked ) {
			printf( '<strong><a href="%1$s">%2$s</a></strong>', esc_url( $step['target'] ), esc_html( $step['label'] ) );
		} else {
			printf( '<strong>%s</strong>', esc_html( $step['label'] ) );
		}
		printf( '<span class="sb-step__note">%s</span>', esc_html( $step['detail'] ) );

		sendbeam_overview_step_panel( $step, $status, $connected );

		echo '</div></li>';
	}
	echo '</ol>';
	sendbeam_card_close();

	sendbeam_card_open( __( 'This workspace', 'sendbeam' ) );
	echo '<div class="sb-grid" style="gap:14px">';
	sendbeam_stat( __( 'Forms', 'sendbeam' ), is_array( $forms ) ? count( $forms ) : null );
	sendbeam_stat( __( 'Lists', 'sendbeam' ), is_array( $lists ) ? count( $lists ) : null );
	sendbeam_stat( __( 'Subscribers', 'sendbeam' ), $subscribers );
	echo '</div>';
	echo '<p class="sb-note" style="margin-top:12px">' . esc_html__( 'Subscribers counts people, not list memberships — someone on three lists is one subscriber. Figures come from your SendBeam workspace and are cached for five minutes.', 'sendbeam' ) . '</p>';
	sendbeam_card_close();

	echo '</div>';

	// A pasted key still gets its own card: there is a field to edit. A key
	// that arrived through Connect has nothing to edit, and its Disconnect
	// button lives in step 1 where someone looking at the connection will
	// actually find it — the old card was below the fold and titled
	// "Connect", which read as an invitation to connect something.
	if ( $connected && ! sendbeam_connected_via_connect() ) {
		sendbeam_card_open( __( 'API key', 'sendbeam' ) );
		sendbeam_form_open( 'connect' );
		do_settings_sections( 'sendbeam_connect_page' );
		sendbeam_form_close();
		sendbeam_card_close();
	}
}

/**
 * Whatever the last button did, said in one line at the top of the screen.
 *
 * Every one of these arrives as a query argument on a redirect this plugin
 * issued itself, so the only thing read out of the URL is which of a fixed
 * list of sentences to print.
 */
function sendbeam_overview_notices() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- notice text only, chosen from a fixed list, set by our own redirect.
	$domain = isset( $_GET['sendbeam_domain'] ) ? sanitize_key( wp_unslash( $_GET['sendbeam_domain'] ) ) : '';
	$mail   = isset( $_GET['sendbeam_mail'] ) ? sanitize_key( wp_unslash( $_GET['sendbeam_mail'] ) ) : '';
	$gone   = isset( $_GET['sendbeam_disconnected'] ) ? sanitize_key( wp_unslash( $_GET['sendbeam_disconnected'] ) ) : '';
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	$messages = array(
		'domain' => array(
			'verified'      => array( 'ok', __( 'Verified. SendBeam can now send email as your domain.', 'sendbeam' ) ),
			'verified_mail' => array( 'ok', __( 'Verified, and this site\'s email has been switched on — it was waiting on exactly this. Send yourself a test below.', 'sendbeam' ) ),
			'pending'       => array( 'warn', __( 'Not verified yet. DNS changes can take anything from a few minutes to a day to spread; the records are below, unchanged.', 'sendbeam' ) ),
			'nodomain'      => array( 'warn', __( 'There is no sending domain on this workspace yet. Add one under Sending in SendBeam.', 'sendbeam' ) ),
			'unreachable'   => array( 'bad', __( 'Could not ask SendBeam to check just now. Nothing was changed.', 'sendbeam' ) ),
		),
		'mail'   => array(
			'on'         => array( 'ok', __( 'Site email is on. Password resets, receipts and notifications now go out through SendBeam.', 'sendbeam' ) ),
			'noscope'    => array( 'bad', __( 'This site\'s key was not given permission to send your site\'s email, so it was not switched on. Reconnect and tick that box.', 'sendbeam' ) ),
			'unverified' => array( 'bad', __( 'The sending domain is not verified, so site email was not switched on. Finish step 2 first.', 'sendbeam' ) ),
		),
		'gone'   => array(
			'ok'          => array( 'ok', __( 'Disconnected. The key was revoked in SendBeam.', 'sendbeam' ) ),
			'unreachable' => array( 'warn', __( 'Disconnected, but this server could not reach SendBeam; revoke it under Settings → API keys.', 'sendbeam' ) ),
			'constant'    => array( 'warn', __( 'Disconnected here, and nothing was revoked: this site sends with the key defined as SENDBEAM_API_KEY in wp-config.php, which is not the one this button issued. Remove it there, and revoke it under Settings → API keys.', 'sendbeam' ) ),
		),
	);

	foreach ( array(
		'domain' => $domain,
		'mail'   => $mail,
		'gone'   => $gone,
	) as $group => $value ) {
		if ( '' === $value || ! isset( $messages[ $group ][ $value ] ) ) {
			continue;
		}
		list( $tone, $text ) = $messages[ $group ][ $value ];
		echo '<p class="sb-msg sb-msg--' . esc_attr( $tone ) . '">' . esc_html( $text ) . '</p>';
	}
}

/**
 * The working part of a checklist step, under its sentence.
 *
 * Four steps, four panels, and nothing shared between them but the wrapper —
 * a step whose panel is a button and a step whose panel is a table of DNS
 * records have nothing in common except that they both sit in an <li>.
 *
 * @param array $step      One entry from sendbeam_setup_steps().
 * @param array $status    Normalised Connect status.
 * @param bool  $connected Whether the key works.
 */
function sendbeam_overview_step_panel( $step, $status, $connected ) {
	switch ( $step['key'] ) {
		case 'connect':
			if ( ! $connected ) {
				sendbeam_connect_panel();
				sendbeam_connect_paste_disclosure();
			} elseif ( sendbeam_connected_via_connect() ) {
				sendbeam_connect_connected_panel( $status );
			}
			break;
		case 'domain':
			sendbeam_overview_domain_panel( $status, $connected );
			break;
		case 'form':
			sendbeam_overview_form_panel( $step, $connected );
			break;
		case 'mail':
			sendbeam_overview_mail_panel( $status, $connected );
			break;
	}

	/*
	 * A step whose sentence names a permission this site was never given ends
	 * in the same place: the consent page, with that box ticked. The link
	 * starts the ordinary Connect flow with everything preselected, and the
	 * key that comes back replaces the one this site holds.
	 */
	if ( $connected && ! empty( $step['reconnect'] ) ) {
		echo '<div class="sb-step__panel"><div class="sb-actions">';
		printf(
			'<a class="sb-btn sb-btn--small sb-btn--ghost" href="%s">%s</a>',
			esc_url( sendbeam_connect_start_url( true ) ),
			esc_html__( 'Reconnect with more permissions', 'sendbeam' )
		);
		echo '</div></div>';
	}
}

/**
 * Step 2: the sending domain, its records, and the two ways to finish it.
 *
 * The records are the whole point of this step existing. A site owner is
 * being asked to go and edit DNS — the least forgiving thing in this entire
 * product — so every value is one click to the clipboard rather than
 * something to retype, and **Check now** is right there so they can find out
 * whether it worked without leaving wp-admin.
 *
 * @param array $status    Normalised Connect status.
 * @param bool  $connected Whether the key works.
 */
function sendbeam_overview_domain_panel( $status, $connected ) {
	if ( ! $connected ) {
		return;
	}

	$domain   = $status['domain'];
	$verified = ! empty( $domain['verified'] );
	$state    = sendbeam_connect_domain_state( $status );

	/*
	 * No domain, so no records and nothing to check — the step's own sentence
	 * has already said why. What it cannot do is hand over the page where the
	 * owner fixes it, so that is all this panel is in that case.
	 */
	if ( '' === $domain['name'] ) {
		if ( in_array( $state, array( 'no_site', 'not_found', 'managed_host' ), true ) ) {
			echo '<div class="sb-step__panel"><div class="sb-actions">';
			printf(
				'<a class="sb-btn sb-btn--small sb-btn--ghost" href="%s" target="_blank" rel="noopener">%s</a>',
				esc_url( sendbeam_app_url() . '/settings/domains' ),
				esc_html__( 'Open Settings → Domains', 'sendbeam' )
			);
			echo '</div></div>';
		}
		return;
	}

	echo '<div class="sb-step__panel">';

	if ( $verified ) {
		echo '<p class="sb-verified"><span class="sb-tick" aria-hidden="true">&#10003;</span> ';
		printf(
			/* translators: %s: the sending domain */
			esc_html__( 'Verified — %s', 'sendbeam' ),
			esc_html( $domain['name'] )
		);
		echo '</p>';
	}

	if ( $domain['records'] ) {
		if ( $verified ) {
			echo '<details class="sb-paste"><summary>' . esc_html__( 'The records SendBeam is using', 'sendbeam' ) . '</summary><div style="margin-top:10px">';
		}
		sendbeam_domain_records_table( $domain['records'] );
		if ( $verified ) {
			echo '</div></details>';
		}
	} elseif ( ! $verified ) {
		echo '<p class="sb-note">' . esc_html__( 'SendBeam has not sent the DNS records for this domain. Open Sending in SendBeam to see them.', 'sendbeam' ) . '</p>';
	}

	echo '<div class="sb-actions">';
	echo '<form action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post">';
	wp_nonce_field( 'sendbeam_domain_check' );
	echo '<input type="hidden" name="action" value="sendbeam_domain_check" />';
	printf(
		'<button type="submit" class="sb-btn sb-btn--small">%s</button>',
		esc_html( $verified ? __( 'Check again', 'sendbeam' ) : __( 'Check now', 'sendbeam' ) )
	);
	echo '</form>';

	if ( ! $verified && '' !== $domain['domain_connect_url'] ) {
		printf(
			'<a class="sb-btn sb-btn--small sb-btn--ghost" href="%s" target="_blank" rel="noopener">%s</a>',
			esc_url( $domain['domain_connect_url'] ),
			esc_html__( 'Set up DNS automatically', 'sendbeam' )
		);
	}
	echo '</div>';

	if ( ! $verified && '' !== $domain['domain_connect_url'] ) {
		echo '<p class="sb-note">' . esc_html__( 'Your registrar supports one-click set-up: that button signs you in there and adds the records for you. It opens in a new tab.', 'sendbeam' ) . '</p>';
	}

	if ( '' !== $domain['checked_at'] ) {
		echo '<p class="sb-note">' . esc_html(
			sprintf(
				/* translators: %s: when the DNS was last checked, in the site's own date and time format */
				__( 'Last checked %s.', 'sendbeam' ),
				sendbeam_connect_when( $domain['checked_at'] )
			)
		) . '</p>';
	}

	echo '</div>';
}

/**
 * The DNS records, one row each, every value one click from the clipboard.
 *
 * Every record is a CNAME under sendbeam.io, so there is no priority to enter
 * anywhere and the table says nothing about one: a Priority column that is
 * always empty is a box a site owner goes looking for in their registrar.
 *
 * The last column is the check's verdict for that row. Two records right out
 * of three looks exactly like none until something says which is which.
 *
 * @param array<int,array{type:string,name:string,value:string,found:?bool}> $records Records.
 */
function sendbeam_domain_records_table( $records ) {
	echo '<div class="sb-scroll"><table class="sb-table sb-dns"><thead><tr>';
	echo '<th>' . esc_html__( 'Type', 'sendbeam' ) . '</th>';
	echo '<th>' . esc_html__( 'Name', 'sendbeam' ) . '</th>';
	echo '<th>' . esc_html__( 'Value', 'sendbeam' ) . '</th>';
	echo '<th>' . esc_html__( 'Found', 'sendbeam' ) . '</th>';
	echo '<th><span class="screen-reader-text">' . esc_html__( 'Copy', 'sendbeam' ) . '</span></th>';
	echo '</tr></thead><tbody>';
	foreach ( $records as $record ) {
		echo '<tr>';
		echo '<td class="sb-mono">' . esc_html( $record['type'] ) . '</td>';
		echo '<td><code>' . esc_html( '' !== $record['name'] ? $record['name'] : '@' ) . '</code></td>';
		echo '<td><code class="sb-dns__value">' . esc_html( $record['value'] ) . '</code></td>';
		echo '<td class="sb-dns__found">' . sendbeam_record_verdict( isset( $record['found'] ) ? $record['found'] : null ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside sendbeam_record_verdict().
		printf(
			'<td><button type="button" class="sb-btn sb-btn--small sb-btn--ghost sb-copy" data-copy="%1$s" data-done="%2$s" aria-label="%3$s">%4$s</button></td>',
			esc_attr( $record['value'] ),
			esc_attr__( 'Copied', 'sendbeam' ),
			esc_attr(
				sprintf(
					/* translators: %s: a DNS record type, e.g. TXT */
					__( 'Copy the %s record value', 'sendbeam' ),
					$record['type']
				)
			),
			esc_html__( 'Copy', 'sendbeam' )
		);
		echo '</tr>';
	}
	echo '</tbody></table></div>';
}

/**
 * One record's verdict, in the three states the check actually has.
 *
 * @param bool|null $found True when the record is live, false when it is not
 *                         there, null when nothing has looked yet.
 * @return string Escaped HTML.
 */
function sendbeam_record_verdict( $found ) {
	if ( null === $found ) {
		return '<span class="sb-note">' . esc_html__( 'Not checked yet', 'sendbeam' ) . '</span>';
	}
	if ( $found ) {
		return '<span class="sb-tick" aria-hidden="true">&#10003;</span><span class="screen-reader-text">' . esc_html__( 'Found', 'sendbeam' ) . '</span>';
	}
	return '<span class="sb-note">' . esc_html__( 'Not found yet', 'sendbeam' ) . '</span>';
}

/**
 * Step 3: the shortcode, for people who are not using the block editor.
 *
 * @param array $step      The step.
 * @param bool  $connected Whether the key works.
 */
function sendbeam_overview_form_panel( $step, $connected ) {
	if ( ! $connected || $step['done'] ) {
		return;
	}
	$settings = sendbeam_settings();
	if ( '' === (string) $settings['default_form'] && '' === (string) $settings['contact_form'] ) {
		return;
	}

	echo '<div class="sb-actions"><code>[sendbeam_form]</code>';
	printf(
		'<button type="button" class="sb-btn sb-btn--small sb-btn--ghost sb-copy" data-copy="%1$s" data-done="%2$s">%3$s</button>',
		esc_attr( '[sendbeam_form]' ),
		esc_attr__( 'Copied', 'sendbeam' ),
		esc_html__( 'Copy', 'sendbeam' )
	);
	echo '</div>';
}

/**
 * Step 4: one button when everything is in place, and why not when it is not.
 *
 * @param array $status    Normalised Connect status.
 * @param bool  $connected Whether the key works.
 */
function sendbeam_overview_mail_panel( $status, $connected ) {
	if ( ! $connected ) {
		return;
	}
	$settings = sendbeam_settings();

	if ( ! empty( $settings['mail_enabled'] ) ) {
		echo '<div class="sb-actions">';
		echo '<form action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post">';
		wp_nonce_field( 'sendbeam_test_mail' );
		echo '<input type="hidden" name="action" value="sendbeam_test_mail" />';
		printf( '<button type="submit" class="sb-btn sb-btn--small">%s</button>', esc_html__( 'Send a test email', 'sendbeam' ) );
		echo '</form>';
		echo '</div>';
		return;
	}

	if ( ! sendbeam_connect_granted( 'transactional:send' ) || empty( $status['domain']['verified'] ) ) {
		return; // The step's own sentence already says which one is missing.
	}

	echo '<div class="sb-actions">';
	echo '<form action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post">';
	wp_nonce_field( 'sendbeam_mail_switch_on' );
	echo '<input type="hidden" name="action" value="sendbeam_mail_switch_on" />';
	printf( '<button type="submit" class="sb-btn sb-btn--small">%s</button>', esc_html__( 'Switch on', 'sendbeam' ) );
	echo '</form>';
	echo '</div>';
	echo '<p class="sb-note">' . esc_html(
		sprintf(
			/* translators: %s: the From address site email will use */
			__( 'Email will be sent as %s. You can change that on the Site email tab.', 'sendbeam' ),
			'' !== $status['sender']['from_email'] ? $status['sender']['from_email'] : __( 'your workspace sender', 'sendbeam' )
		)
	) . '</p>';
}

/**
 * The paste field, tucked away for the people who already have a key.
 *
 * Open by default would make the screen look like it still wants a key
 * copied across, which is the friction Connect exists to remove.
 */
function sendbeam_connect_paste_disclosure() {
	echo '<details class="sb-paste" style="margin-top:14px">';
	echo '<summary>' . esc_html__( 'I already have an API key', 'sendbeam' ) . '</summary>';
	echo '<div style="margin-top:10px">';
	sendbeam_form_open( 'connect' );
	do_settings_sections( 'sendbeam_connect_page' );
	sendbeam_form_close();
	echo '</div></details>';
}

/**
 * What step 1 says once the button has done its work.
 *
 * Disconnect no longer goes through the settings form's "Remove the saved
 * key" checkbox. That only ever forgot this site's copy, and left a live key
 * in the workspace for the owner to go and revoke by hand — which is exactly
 * what nobody does. It now calls SendBeam and revokes the key first.
 *
 * The confirmation is inline rather than window.confirm(): a browser dialog
 * cannot say what disconnecting costs, and half of them are suppressed after
 * the first one on a page. With scripts off the confirmation box is simply
 * already open, so the button still works.
 *
 * @param array $status Normalised Connect status, for the workspace name.
 */
function sendbeam_connect_connected_panel( $status = null ) {
	$workspace = sendbeam_connect_workspace_name();
	if ( '' === $workspace && is_array( $status ) ) {
		$workspace = (string) $status['workspace']['name'];
	}

	echo '<div class="sb-step__panel">';

	if ( '' !== $workspace ) {
		/* translators: %s: the SendBeam workspace name */
		echo '<p style="margin-top:0"><strong>' . esc_html( sprintf( __( 'Connected to %s', 'sendbeam' ), $workspace ) ) . '</strong></p>';
	}

	$granted = sendbeam_connect_granted_list();
	if ( $granted ) {
		echo '<ul class="sb-granted">';
		foreach ( $granted as $label ) {
			echo '<li>' . esc_html( $label ) . '</li>';
		}
		echo '</ul>';
	}

	echo '<form class="sb-confirm" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post">';
	wp_nonce_field( 'sendbeam_disconnect' );
	echo '<input type="hidden" name="action" value="sendbeam_disconnect" />';

	/*
	 * Reconnect sits beside Disconnect because it is what people reach for
	 * Disconnect to do: change workspace, pick a different sending host,
	 * re-approve something. It asks for the permissions this site already
	 * has, so the consent page comes up saying what is already true.
	 */
	printf(
		'<a class="sb-btn sb-btn--ghost sb-btn--small" href="%s">%s</a> ',
		esc_url( sendbeam_connect_start_url() ),
		esc_html__( 'Reconnect', 'sendbeam' )
	);
	printf(
		'<button type="button" class="sb-btn sb-btn--ghost sb-btn--small sb-confirm__ask" hidden>%s</button>',
		esc_html__( 'Disconnect', 'sendbeam' )
	);
	echo '<div class="sb-confirm__box">';
	echo '<p class="sb-note">' . esc_html__( 'Disconnect this site? Its key is revoked. Your list, form and sending domain stay in SendBeam. Forms already on your pages stop loading until you connect again.', 'sendbeam' ) . '</p>';
	printf( '<button type="submit" class="sb-btn sb-btn--small">%s</button> ', esc_html__( 'Yes, disconnect', 'sendbeam' ) );
	printf( '<button type="button" class="sb-btn sb-btn--small sb-btn--ghost sb-confirm__cancel">%s</button>', esc_html__( 'Cancel', 'sendbeam' ) );
	echo '</div></form>';

	echo '</div>';
}

/**
 * The permissions this site's key holds, in the consent page's own words.
 *
 * @return string[]
 */
function sendbeam_connect_granted_list() {
	$out = array();
	foreach ( sendbeam_connect_scopes() as $scope => $meta ) {
		if ( sendbeam_connect_granted( $scope ) ) {
			$out[] = $meta['label'];
		}
	}
	return $out;
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

/**
 * Forms tab: the default forms, how embedded forms look, and every form in the workspace.
 */
function sendbeam_screen_forms() {
	sendbeam_card_open( __( 'Defaults', 'sendbeam' ), __( 'Used when a block or shortcode does not name a form.', 'sendbeam' ) );
	sendbeam_form_open( 'forms' );
	do_settings_sections( 'sendbeam_forms_page' );
	sendbeam_form_close();
	sendbeam_card_close();

	sendbeam_card_open( __( 'Appearance', 'sendbeam' ), __( 'How the form looks on your pages.', 'sendbeam' ) );
	sendbeam_form_open( 'forms' );
	$s = sendbeam_settings();
	echo '<p style="margin-top:0">' . esc_html__( 'Forms are served by SendBeam, so give them your site\'s colours and they will stop looking like something pasted in from elsewhere. Leave a field blank to keep the SendBeam default.', 'sendbeam' ) . '</p>';
	echo '<div class="sb-rule__grid">';
	$colours = array(
		'style_accent' => __( 'Button colour', 'sendbeam' ),
		'style_text'   => __( 'Text colour', 'sendbeam' ),
		'style_field'  => __( 'Field background', 'sendbeam' ),
		'style_border' => __( 'Field border', 'sendbeam' ),
	);
	foreach ( $colours as $key => $label ) {
		printf(
			'<label><span class="sb-label">%1$s</span><input type="text" name="sendbeam_settings[%2$s]" value="%3$s" placeholder="#000000" spellcheck="false" class="regular-text code" /></label>',
			esc_html( $label ),
			esc_attr( $key ),
			esc_attr( $s[ $key ] )
		);
	}
	printf(
		'<label><span class="sb-label">%1$s</span><input type="number" min="0" max="28" name="sendbeam_settings[style_radius]" value="%2$s" placeholder="6" class="small-text" /></label>',
		esc_html__( 'Corner radius (px)', 'sendbeam' ),
		esc_attr( $s['style_radius'] )
	);
	printf(
		'<label><span class="sb-label">%1$s</span><input type="number" min="12" max="20" name="sendbeam_settings[style_size]" value="%2$s" placeholder="14" class="small-text" /></label>',
		esc_html__( 'Text size (px)', 'sendbeam' ),
		esc_attr( $s['style_size'] )
	);
	$fonts = array(
		'inherit' => __( 'Match my theme', 'sendbeam' ),
		'system'  => __( 'System UI', 'sendbeam' ),
		'sans'    => __( 'Sans serif', 'sendbeam' ),
		'serif'   => __( 'Serif', 'sendbeam' ),
		'mono'    => __( 'Monospace', 'sendbeam' ),
	);
	echo '<label><span class="sb-label">' . esc_html__( 'Typeface', 'sendbeam' ) . '</span><select name="sendbeam_settings[style_font]">';
	foreach ( $fonts as $k => $v ) {
		printf( '<option value="%s"%s>%s</option>', esc_attr( $k ), selected( $s['style_font'], $k, false ), esc_html( $v ) );
	}
	echo '</select></label>';
	echo '</div>';
	printf(
		'<p style="margin-top:14px"><label class="sb-inline"><input type="checkbox" name="sendbeam_settings[style_bare]" value="1" %s /> %s</label></p>',
		checked( ! empty( $s['style_bare'] ), true, false ),
		esc_html__( 'Hide the form name and subtitle inside the embed (your page already has a heading)', 'sendbeam' )
	);
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
			array(
				'a' => array(
					'href'   => array(),
					'target' => array(),
					'rel'    => array(),
				),
			)
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

/**
 * Audience tab: the workspace's lists, and where to ask people to subscribe.
 */
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

	sendbeam_screen_sync( $lists );
	sendbeam_screen_form_plugins( $lists );
}

/**
 * Where each form plugin on the site is connected to SendBeam.
 *
 * @param array<int,array<string,mixed>>|null $lists Lists, or null when unreadable.
 */
function sendbeam_screen_form_plugins( $lists ) {
	$hosts = sendbeam_bridge_hosts();
	$rows  = array(
		'cf7'          => array( 'Contact Form 7', __( 'Open a form and use its SendBeam tab.', 'sendbeam' ) ),
		'elementor'    => array( 'Elementor Pro', __( 'In the Form widget, add SendBeam under Actions After Submit.', 'sendbeam' ) ),
		'wpforms'      => array( 'WPForms', __( 'In the form builder, open Settings, then SendBeam.', 'sendbeam' ) ),
		'gravityforms' => array( 'Gravity Forms', __( 'Open a form, then Settings, then SendBeam, and add a feed.', 'sendbeam' ) ),
		'fluentforms'  => array( 'Fluent Forms', __( 'Choose the forms below.', 'sendbeam' ) ),
	);

	echo '<div id="sendbeam-form-plugins">';

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- notice only.
	if ( isset( $_GET['sendbeam_bridges_saved'] ) ) {
		echo '<p class="sb-msg sb-msg--ok">' . esc_html__( 'Saved.', 'sendbeam' ) . '</p>';
	}

	sendbeam_card_open( __( 'Form plugins', 'sendbeam' ) );
	echo '<p style="margin-top:0">' . esc_html__( 'Send the people who fill in forms built with another plugin to SendBeam. Each form is switched on where that plugin keeps its settings, and sends someone only when they ticked the consent field you name — or every submission, when you mark the form as a signup form.', 'sendbeam' ) . '</p>';

	echo '<table class="sb-table"><thead><tr>';
	echo '<th>' . esc_html__( 'Form plugin', 'sendbeam' ) . '</th><th>' . esc_html__( 'On this site', 'sendbeam' ) . '</th><th>' . esc_html__( 'Where to set it up', 'sendbeam' ) . '</th>';
	echo '</tr></thead><tbody>';
	foreach ( $rows as $key => $row ) {
		printf(
			'<tr><td><strong>%1$s</strong></td><td>%2$s</td><td>%3$s</td></tr>',
			esc_html( $row[0] ),
			$hosts[ $key ] ? '<span class="sb-chip">' . esc_html__( 'Active', 'sendbeam' ) . '</span>' : '<span class="sb-note">' . esc_html__( 'Not installed', 'sendbeam' ) . '</span>',
			esc_html( $row[1] )
		);
	}
	echo '</tbody></table>';
	echo '<p class="sb-note" style="margin-top:12px">' . esc_html__( 'The key needs Contacts (read and write), Lists (write) to add people to a list, and Tags (read and write) when a form adds a tag. Each person\'s source in SendBeam names the form plugin they came from.', 'sendbeam' ) . '</p>';

	if ( $hosts['fluentforms'] && function_exists( 'sendbeam_fluentforms_forms' ) ) {
		sendbeam_screen_fluentforms( $lists );
	}

	sendbeam_card_close();
	echo '</div>';
}

/**
 * Which Fluent forms send to SendBeam, and how.
 *
 * @param array<int,array<string,mixed>>|null $lists Lists, or null when unreadable.
 */
function sendbeam_screen_fluentforms( $lists ) {
	$forms = sendbeam_fluentforms_forms();

	echo '<p class="sb-label" style="margin-top:18px">' . esc_html__( 'Fluent Forms', 'sendbeam' ) . '</p>';
	if ( null === $forms ) {
		echo '<p class="sb-note">' . esc_html__( 'Fluent Forms is active, but this version does not let its forms be listed here.', 'sendbeam' ) . '</p>';
		return;
	}
	if ( ! $forms ) {
		echo '<p class="sb-note">' . esc_html__( 'There are no Fluent forms on this site yet.', 'sendbeam' ) . '</p>';
		return;
	}

	echo '<form action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post">';
	echo '<input type="hidden" name="action" value="sendbeam_save_bridges" />';
	wp_nonce_field( 'sendbeam_save_bridges' );

	foreach ( $forms as $form_id => $title ) {
		$config = sendbeam_fluentforms_config( $form_id );
		$name   = 'sendbeam_fluentforms[' . (int) $form_id . ']';

		echo '<fieldset style="border-top:1px solid #ddd;padding:12px 0">';
		printf(
			'<p><label class="sb-inline"><input type="checkbox" name="%1$s[enabled]" value="1"%2$s /> <strong>%3$s</strong></label></p>',
			esc_attr( $name ),
			checked( $config['enabled'], 1, false ),
			esc_html( '' !== $title ? $title : sprintf( /* translators: %d: form ID */ __( 'Form %d', 'sendbeam' ), $form_id ) )
		);
		foreach ( array(
			'email_field'   => __( 'Email field name', 'sendbeam' ),
			'first_field'   => __( 'First name field name', 'sendbeam' ),
			'last_field'    => __( 'Last name field name', 'sendbeam' ),
			'consent_field' => __( 'Consent field name', 'sendbeam' ),
			'tag'           => __( 'Tag (optional)', 'sendbeam' ),
		) as $key => $label ) {
			printf(
				'<p><label><span class="sb-label">%1$s</span><input type="text" class="regular-text code" name="%2$s[%3$s]" value="%4$s" /></label></p>',
				esc_html( $label ),
				esc_attr( $name ),
				esc_attr( $key ),
				esc_attr( $config[ $key ] )
			);
		}
		printf(
			'<p><label class="sb-inline"><input type="checkbox" name="%1$s[consent]" value="signup"%2$s /> %3$s</label></p>',
			esc_attr( $name ),
			checked( $config['consent'], 'signup', false ),
			esc_html__( 'Everyone who submits this form is asking to subscribe, so no consent field is needed', 'sendbeam' )
		);
		if ( is_array( $lists ) && $lists ) {
			printf( '<p><label><span class="sb-label">%1$s</span><select name="%2$s[list]">', esc_html__( 'Add them to', 'sendbeam' ), esc_attr( $name ) );
			printf( '<option value="">%s</option>', esc_html__( '— No list —', 'sendbeam' ) );
			foreach ( $lists as $list ) {
				printf(
					'<option value="%1$s"%2$s>%3$s</option>',
					esc_attr( $list['id'] ),
					selected( in_array( $list['id'], $config['lists'], true ), true, false ),
					esc_html( $list['name'] )
				);
			}
			echo '</select></label></p>';
		}
		echo '</fieldset>';
	}

	echo '<p class="sb-note">' . esc_html__( 'Field names are the names shown in each field\'s settings in Fluent Forms. A name field is written as names.first_name and names.last_name.', 'sendbeam' ) . '</p>';
	echo '<p><button type="submit" class="sb-btn">' . esc_html__( 'Save', 'sendbeam' ) . '</button></p>';
	echo '</form>';
}

/**
 * Subscribing people who are already doing something else on the site.
 *
 * @param array<int,array<string,mixed>>|null $lists Lists, or null when unreadable.
 */
function sendbeam_screen_sync( $lists ) {
	$sync = sendbeam_sync_settings();

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- notice only.
	if ( isset( $_GET['sendbeam_sync_saved'] ) ) {
		echo '<p class="sb-msg sb-msg--ok">' . esc_html__( 'Saved.', 'sendbeam' ) . '</p>';
	}

	sendbeam_card_open( __( 'Collect subscribers elsewhere on the site', 'sendbeam' ) );

	echo '<p style="margin-top:0">' . esc_html__( 'Adds an opt-in tick box to things people already do here. Nobody is subscribed without ticking it, and the box is never pre-ticked.', 'sendbeam' ) . '</p>';

	if ( null === $lists || ! $lists ) {
		echo '<p class="sb-msg sb-msg--warn">' . esc_html__( 'This needs at least one list, read with a key that has the Lists (read) permission. Add one on the Overview tab.', 'sendbeam' ) . '</p>';
		sendbeam_card_close();
		return;
	}

	echo '<form action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post">';
	echo '<input type="hidden" name="action" value="sendbeam_save_sync" />';
	wp_nonce_field( 'sendbeam_save_sync' );

	$sources = array(
		'registration' => array( __( 'When someone registers an account', 'sendbeam' ), true ),
		'comments'     => array( __( 'When someone leaves a comment', 'sendbeam' ), true ),
		'woocommerce'  => array( __( 'When someone checks out in WooCommerce', 'sendbeam' ), class_exists( 'WooCommerce' ) ),
	);

	echo '<p class="sb-label">' . esc_html__( 'Ask on', 'sendbeam' ) . '</p>';
	foreach ( $sources as $key => $meta ) {
		printf(
			'<p class="sb-inline" style="display:flex"><label class="sb-inline"><input type="checkbox" name="sendbeam_sync[%1$s]" value="1" %2$s %3$s /> %4$s</label>%5$s</p>',
			esc_attr( $key ),
			checked( ! empty( $sync[ $key ] ), true, false ),
			$meta[1] ? '' : 'disabled',
			esc_html( $meta[0] ),
			$meta[1] ? '' : ' <span class="sb-note" style="margin-left:8px">' . esc_html__( 'WooCommerce is not active on this site.', 'sendbeam' ) . '</span>'
		);
	}

	echo '<p class="sb-label" style="margin-top:18px">' . esc_html__( 'Add them to', 'sendbeam' ) . '</p>';
	foreach ( $lists as $list ) {
		printf(
			'<p><label class="sb-inline"><input type="checkbox" name="sendbeam_sync[lists][]" value="%1$s" %2$s /> %3$s%4$s</label></p>',
			esc_attr( $list['id'] ),
			checked( in_array( $list['id'], $sync['lists'], true ), true, false ),
			esc_html( $list['name'] ),
			$list['double_optin'] ? ' <span class="sb-chip">' . esc_html__( 'Double opt-in', 'sendbeam' ) . '</span>' : ''
		);
	}
	echo '<p class="sb-note">' . esc_html__( 'A list set to double opt-in still sends its own confirmation email before anyone receives anything.', 'sendbeam' ) . '</p>';

	printf(
		'<p style="margin-top:18px"><label><span class="sb-label">%1$s</span>' .
		'<input type="text" name="sendbeam_sync[label]" value="%2$s" placeholder="%3$s" class="regular-text" style="width:100%%;max-width:36em" /></label></p>',
		esc_html__( 'Wording next to the tick box', 'sendbeam' ),
		esc_attr( $sync['label'] ),
		esc_attr( sendbeam_sync_label() )
	);

	echo '<p><button type="submit" class="sb-btn">' . esc_html__( 'Save', 'sendbeam' ) . '</button></p>';
	echo '</form>';
	sendbeam_card_close();

	$log = get_option( SENDBEAM_SYNC_LOG, array() );
	if ( is_array( $log ) && $log ) {
		sendbeam_card_open( __( 'Recent subscriptions', 'sendbeam' ) );
		echo '<table class="sb-table"><thead><tr>';
		echo '<th>' . esc_html__( 'When', 'sendbeam' ) . '</th><th>' . esc_html__( 'Who', 'sendbeam' ) . '</th>';
		echo '<th>' . esc_html__( 'From', 'sendbeam' ) . '</th><th>' . esc_html__( 'Result', 'sendbeam' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $log as $row ) {
			echo '<tr>';
			echo '<td class="sb-mono">' . esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $row['at'] ) ) . '</td>';
			echo '<td>' . esc_html( $row['email'] ) . '</td>';
			echo '<td><span class="sb-chip">' . esc_html( $row['source'] ) . '</span></td>';
			echo '<td>' . ( $row['ok'] ? esc_html__( 'Subscribed', 'sendbeam' ) : esc_html( __( 'Failed', 'sendbeam' ) . ' — ' . $row['note'] ) ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
		sendbeam_card_close();
	}
}

/* --------------------------------------------------------------- Pop-ups */

/**
 * Pop-ups tab: the ordered rules, each with its own form, targeting and trigger.
 */
function sendbeam_screen_popup() {
	$rules = sendbeam_popups();

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- notice text only.
	if ( isset( $_GET['sendbeam_saved'] ) ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$n = (int) $_GET['sendbeam_saved'];
		echo '<p class="sb-msg sb-msg--ok">' . esc_html(
			sprintf( /* translators: %d: number of pop-ups */ _n( '%d pop-up saved.', '%d pop-ups saved.', $n, 'sendbeam' ), $n )
		) . '</p>';
	}

	sendbeam_card_open( __( 'Pop-ups', 'sendbeam' ), __( 'First match wins, top to bottom.', 'sendbeam' ) );

	echo '<p class="sb-note" style="margin-top:0">' . esc_html__( 'Add as many as you like and target each one. Only the first rule that matches a page opens by itself, so two pop-ups can never fight over the same visitor.', 'sendbeam' ) . '</p>';

	echo '<form action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post">';
	echo '<input type="hidden" name="action" value="sendbeam_save_popups" />';
	wp_nonce_field( 'sendbeam_save_popups' );

	echo '<div id="sb-popups">';
	foreach ( $rules as $i => $rule ) {
		sendbeam_popup_row( $i, $rule );
	}
	echo '</div>';

	echo '<p style="margin-top:14px"><button type="button" class="sb-btn sb-btn--ghost" id="sb-add-popup">' . esc_html__( '+ Add a pop-up', 'sendbeam' ) . '</button></p>';
	echo '<p><button type="submit" class="sb-btn">' . esc_html__( 'Save pop-ups', 'sendbeam' ) . '</button></p>';
	echo '</form>';

	// The blank row the "add" button clones. __i__ is swapped for the index.
	echo '<template id="sb-popup-template">';
	sendbeam_popup_row( '__i__', sendbeam_popup_defaults() );
	echo '</template>';

	sendbeam_card_close();
}

/**
 * One pop-up rule as a fieldset.
 *
 * @param int|string $i    Index, or __i__ for the template.
 * @param array      $rule Rule values.
 */
function sendbeam_popup_row( $i, $rule ) {
	$name    = 'sendbeam_popup[' . $i . ']';
	$choices = sendbeam_form_choices( 'signup' );
	?>
	<fieldset class="sb-rule">
		<legend class="sb-label"><?php esc_html_e( 'Pop-up', 'sendbeam' ); ?></legend>

		<div class="sb-rule__grid">
			<label>
				<span class="sb-label"><?php esc_html_e( 'Form', 'sendbeam' ); ?></span>
				<?php if ( null === $choices ) : ?>
					<input type="text" name="<?php echo esc_attr( $name ); ?>[form]" value="<?php echo esc_attr( $rule['form'] ); ?>"
						placeholder="8f3c1a2e-0000-4000-8000-000000000000" spellcheck="false" class="regular-text code" />
				<?php else : ?>
					<select name="<?php echo esc_attr( $name ); ?>[form]">
						<option value=""><?php esc_html_e( '— choose a form —', 'sendbeam' ); ?></option>
						<?php foreach ( $choices as $id => $label ) : ?>
							<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $rule['form'], $id ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
						<?php if ( '' !== $rule['form'] && ! isset( $choices[ $rule['form'] ] ) ) : ?>
							<option value="<?php echo esc_attr( $rule['form'] ); ?>" selected><?php echo esc_html( sprintf( /* translators: %s: id */ __( 'Saved form %s', 'sendbeam' ), substr( $rule['form'], 0, 8 ) ) ); ?></option>
						<?php endif; ?>
					</select>
				<?php endif; ?>
			</label>

			<label>
				<span class="sb-label"><?php esc_html_e( 'Show on', 'sendbeam' ); ?></span>
				<select name="<?php echo esc_attr( $name ); ?>[where]" class="sb-where">
					<?php foreach ( sendbeam_popup_wheres() as $k => $v ) : ?>
						<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $rule['where'], $k ); ?>><?php echo esc_html( $v ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>

			<label class="sb-when-url" <?php echo 'url' === $rule['where'] ? '' : 'hidden'; ?>>
				<span class="sb-label"><?php esc_html_e( 'Address contains', 'sendbeam' ); ?></span>
				<input type="text" name="<?php echo esc_attr( $name ); ?>[url]" value="<?php echo esc_attr( $rule['url'] ); ?>" placeholder="/pricing" class="regular-text" />
			</label>

			<label>
				<span class="sb-label"><?php esc_html_e( 'Open', 'sendbeam' ); ?></span>
				<select name="<?php echo esc_attr( $name ); ?>[trigger]" class="sb-trigger">
					<?php foreach ( sendbeam_popup_triggers() as $k => $v ) : ?>
						<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $rule['trigger'], $k ); ?>><?php echo esc_html( $v ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>

			<label class="sb-when-timer" <?php echo 'timer' === $rule['trigger'] ? '' : 'hidden'; ?>>
				<span class="sb-label"><?php esc_html_e( 'Delay (seconds)', 'sendbeam' ); ?></span>
				<input type="number" min="0" max="120" name="<?php echo esc_attr( $name ); ?>[delay]" value="<?php echo esc_attr( $rule['delay'] ); ?>" class="small-text" />
			</label>

			<label class="sb-when-scroll" <?php echo 'scroll' === $rule['trigger'] ? '' : 'hidden'; ?>>
				<span class="sb-label"><?php esc_html_e( 'Scroll depth (%)', 'sendbeam' ); ?></span>
				<input type="number" min="5" max="100" name="<?php echo esc_attr( $name ); ?>[scroll]" value="<?php echo esc_attr( $rule['scroll'] ); ?>" class="small-text" />
			</label>

			<label style="grid-column:1/-1">
				<span class="sb-label"><?php esc_html_e( 'Headline shown in the pop-up', 'sendbeam' ); ?></span>
				<input type="text" maxlength="80" name="<?php echo esc_attr( $name ); ?>[heading]" value="<?php echo esc_attr( $rule['heading'] ); ?>"
					placeholder="<?php esc_attr_e( 'Get the monthly roast notes', 'sendbeam' ); ?>" class="regular-text" style="width:100%" />
			</label>

			<label style="grid-column:1/-1">
				<span class="sb-label"><?php esc_html_e( 'A line underneath it', 'sendbeam' ); ?></span>
				<input type="text" maxlength="200" name="<?php echo esc_attr( $name ); ?>[blurb]" value="<?php echo esc_attr( $rule['blurb'] ); ?>"
					placeholder="<?php esc_attr_e( 'One email a month. Unsubscribe any time.', 'sendbeam' ); ?>" class="regular-text" style="width:100%" />
			</label>

			<label>
				<span class="sb-label"><?php esc_html_e( 'Style', 'sendbeam' ); ?></span>
				<select name="<?php echo esc_attr( $name ); ?>[style]">
					<?php foreach ( sendbeam_popup_styles() as $k => $v ) : ?>
						<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $rule['style'], $k ); ?>><?php echo esc_html( $v ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>

			<label style="grid-column:1/-1">
				<span class="sb-label"><?php esc_html_e( 'Image', 'sendbeam' ); ?></span>
				<span style="display:flex;gap:8px;align-items:center">
					<input type="url" name="<?php echo esc_attr( $name ); ?>[image]" value="<?php echo esc_attr( $rule['image'] ); ?>"
						placeholder="https://example.com/wp-content/uploads/letter.jpg" class="regular-text sb-image" style="width:100%" />
					<button type="button" class="sb-btn sb-btn--small sb-btn--ghost sb-image-pick"><?php esc_html_e( 'Choose', 'sendbeam' ); ?></button>
				</span>
				<span class="sb-note" style="display:block;margin-top:4px"><?php esc_html_e( 'Shown beside the form in the split and slide-in styles. Pick one from your Media Library, or paste an https address.', 'sendbeam' ); ?></span>
			</label>

			<label>
				<span class="sb-label"><?php esc_html_e( 'Eyebrow', 'sendbeam' ); ?></span>
				<input type="text" maxlength="40" name="<?php echo esc_attr( $name ); ?>[eyebrow]" value="<?php echo esc_attr( $rule['eyebrow'] ); ?>" class="regular-text" />
				<span class="sb-note" style="display:block;margin-top:4px"><?php esc_html_e( 'A short label above the headline, e.g. Monthly · Free', 'sendbeam' ); ?></span>
			</label>

			<label>
				<span class="sb-label"><?php esc_html_e( 'Button label', 'sendbeam' ); ?></span>
				<input type="text" maxlength="30" name="<?php echo esc_attr( $name ); ?>[button]" value="<?php echo esc_attr( $rule['button'] ); ?>"
					placeholder="<?php esc_attr_e( 'Subscribe', 'sendbeam' ); ?>" class="regular-text" />
			</label>

			<label class="sb-inline" style="grid-column:1/-1;display:flex;align-items:center;gap:6px;flex-wrap:wrap">
				<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[proof]" value="1" <?php checked( ! empty( $rule['proof'] ) ); ?> />
				<span><?php esc_html_e( 'Show subscriber count', 'sendbeam' ); ?></span>
				<span class="sb-note"><?php esc_html_e( 'Shows "Joined by N readers" once the list has 50 people.', 'sendbeam' ); ?></span>
			</label>

			<label class="sb-when-button" <?php echo 'button' === $rule['trigger'] ? '' : 'hidden'; ?>>
				<span class="sb-label"><?php esc_html_e( 'Button label', 'sendbeam' ); ?></span>
				<input type="text" name="<?php echo esc_attr( $name ); ?>[label]" value="<?php echo esc_attr( $rule['label'] ); ?>" placeholder="<?php esc_attr_e( 'Subscribe', 'sendbeam' ); ?>" class="regular-text" />
			</label>

			<label>
				<span class="sb-label"><?php esc_html_e( 'After it is closed', 'sendbeam' ); ?></span>
				<select name="<?php echo esc_attr( $name ); ?>[once]">
					<?php foreach ( sendbeam_popup_frequencies() as $k => $v ) : ?>
						<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $rule['once'], $k ); ?>><?php echo esc_html( $v ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
		</div>

		<div class="sb-rule__foot">
			<label class="sb-inline">
				<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[enabled]" value="1" <?php checked( ! empty( $rule['enabled'] ) ); ?> />
				<?php esc_html_e( 'Active', 'sendbeam' ); ?>
			</label>
			<button type="button" class="sb-btn sb-btn--small sb-btn--ghost sb-remove"><?php esc_html_e( 'Remove', 'sendbeam' ); ?></button>
		</div>
	</fieldset>
	<?php
}

/* ------------------------------------------------------------ Site email */

/**
 * Site email tab: the wp_mail() relay, a test send, and what recently went out.
 */
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

/* ------------------------------------------------------------ E-commerce */

/**
 * E-commerce events tab: the three toggles, the abandoned-cart window, and
 * a log of recent attempts — the same shape as the Audience tab's sync log.
 */
function sendbeam_screen_ecommerce() {
	$settings = sendbeam_ecommerce_settings();

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- notice only.
	if ( isset( $_GET['sendbeam_ecommerce_saved'] ) ) {
		echo '<p class="sb-msg sb-msg--ok">' . esc_html__( 'Saved.', 'sendbeam' ) . '</p>';
	}

	sendbeam_card_open(
		__( 'Cart Abandoned, Product Viewed, Order Placed', 'sendbeam' ),
		__( 'Feeds the three native SendBeam automation triggers of the same names.', 'sendbeam' )
	);

	if ( ! class_exists( 'WooCommerce' ) ) {
		echo '<p class="sb-msg sb-msg--warn">' . esc_html__( 'WooCommerce is not active on this site. Every event here is a WooCommerce event, so none of them can fire without it.', 'sendbeam' ) . '</p>';
		sendbeam_card_close();
		return;
	}

	echo '<form action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post">';
	echo '<input type="hidden" name="action" value="sendbeam_save_ecommerce" />';
	wp_nonce_field( 'sendbeam_save_ecommerce' );

	printf(
		'<p class="sb-inline" style="display:flex"><label class="sb-inline"><input type="checkbox" name="sendbeam_ecommerce[order_placed]" value="1" %1$s /> %2$s</label></p>',
		checked( ! empty( $settings['order_placed'] ), true, false ),
		esc_html__( 'Order placed — reliable, fires from WooCommerce\'s own order-processed hook. Also adds the order total to the contact\'s lifetime_value.', 'sendbeam' )
	);
	printf(
		'<p class="sb-inline" style="display:flex"><label class="sb-inline"><input type="checkbox" name="sendbeam_ecommerce[product_viewed]" value="1" %1$s /> %2$s</label></p>',
		checked( ! empty( $settings['product_viewed'] ), true, false ),
		esc_html__( 'Product viewed — only for a known contact (logged in, or an email already entered this visit). No anonymous visitor tracking.', 'sendbeam' )
	);
	printf(
		'<p class="sb-inline" style="display:flex"><label class="sb-inline"><input type="checkbox" name="sendbeam_ecommerce[cart_abandoned]" value="1" %1$s /> %2$s</label></p>',
		checked( ! empty( $settings['cart_abandoned'] ), true, false ),
		esc_html__( 'Cart abandoned — best effort. WooCommerce has no native "abandoned cart" event, so this is a heuristic: no order within the window below, and only when an email became known at some point.', 'sendbeam' )
	);

	printf(
		'<p style="margin-top:14px"><label><span class="sb-label">%1$s</span>' .
		'<input type="number" min="%2$d" max="%3$d" name="sendbeam_ecommerce[cart_abandoned_window]" value="%4$d" class="small-text" /> %5$s</label></p>',
		esc_html__( 'Cart abandoned window (minutes)', 'sendbeam' ),
		(int) SENDBEAM_CART_WINDOW_MIN,
		(int) SENDBEAM_CART_WINDOW_MAX,
		(int) $settings['cart_abandoned_window'],
		esc_html__( 'How long to wait after an item is added before deciding, if no order has followed, that the cart was abandoned.', 'sendbeam' )
	);

	echo '<p class="sb-note" style="margin-top:10px">' . esc_html__( 'None of this is a guarantee. "Abandoned" here means "no order followed within the window" — a shopper who orders later, on another device, still counts as abandoned by this measure. Treat the trigger as a nudge, not a fact.', 'sendbeam' ) . '</p>';

	echo '<p style="margin-top:10px"><button type="submit" class="sb-btn">' . esc_html__( 'Save', 'sendbeam' ) . '</button></p>';
	echo '</form>';
	sendbeam_card_close();

	$log = get_option( SENDBEAM_ECOMMERCE_LOG, array() );
	if ( is_array( $log ) && $log ) {
		sendbeam_card_open( __( 'Recent e-commerce events', 'sendbeam' ) );
		echo '<table class="sb-table"><thead><tr>';
		echo '<th>' . esc_html__( 'When', 'sendbeam' ) . '</th><th>' . esc_html__( 'Event', 'sendbeam' ) . '</th>';
		echo '<th>' . esc_html__( 'Who', 'sendbeam' ) . '</th><th>' . esc_html__( 'Result', 'sendbeam' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $log as $row ) {
			echo '<tr>';
			echo '<td class="sb-mono">' . esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $row['at'] ) ) . '</td>';
			echo '<td><span class="sb-chip">' . esc_html( $row['type'] ) . '</span></td>';
			echo '<td>' . esc_html( $row['email'] ) . '</td>';
			echo '<td>' . ( $row['ok'] ? esc_html__( 'Sent', 'sendbeam' ) : esc_html( __( 'Failed', 'sendbeam' ) . ' — ' . $row['note'] ) ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
		sendbeam_card_close();
	}
}

/* ------------------------------------------------------------------ Docs */

/**
 * A reference that works without leaving WordPress.
 *
 * Deliberately a summary rather than the whole manual: the shortcodes and
 * permissions are the two things people come back for, and everything else
 * links out to the full documentation, which is versioned with the product
 * rather than with the plugin.
 *
 * @param string $slug Page under /docs/wordpress, or '' for the section.
 * @return string
 */
function sendbeam_docs_url( $slug = '' ) {
	return sendbeam_app_url() . '/docs/wordpress' . ( $slug ? '/' . $slug : '' );
}

/**
 * A row with a copyable snippet.
 *
 * @param string $code What to copy.
 * @param string $what What it does.
 */
function sendbeam_doc_snippet( $code, $what ) {
	printf(
		'<div class="sb-doc__row"><code>%1$s</code><span class="sb-note">%2$s</span>' .
		'<button type="button" class="sb-btn sb-btn--small sb-copy" data-copy="%3$s">%4$s</button></div>',
		esc_html( $code ),
		esc_html( $what ),
		esc_attr( $code ),
		esc_html__( 'Copy', 'sendbeam' )
	);
}

/**
 * Docs tab: the shortcodes, the API key permissions, and links to the full documentation.
 */
function sendbeam_screen_docs() {
	sendbeam_card_open( __( 'Documentation', 'sendbeam' ) );
	echo '<div class="sb-doc">';
	echo '<p>' . esc_html__( 'The essentials are below. The full documentation is kept with SendBeam itself, so it stays current with the product rather than with whichever version of this plugin you happen to have installed.', 'sendbeam' ) . '</p>';
	printf(
		'<p><a class="sb-btn" href="%1$s" target="_blank" rel="noopener">%2$s</a></p>',
		esc_url( sendbeam_docs_url() ),
		esc_html__( 'Open the full documentation', 'sendbeam' )
	);
	echo '<div class="sb-grid" style="margin-bottom:0">';
	foreach ( array(
		'forms'      => array( __( 'Forms on a page', 'sendbeam' ), __( 'The block, the shortcodes, and giving forms your own colours.', 'sendbeam' ) ),
		'popups'     => array( __( 'Pop-ups', 'sendbeam' ), __( 'Targeting, the five triggers, and how often someone sees one.', 'sendbeam' ) ),
		'audience'   => array( __( 'Collecting opt-ins', 'sendbeam' ), __( 'The tick box at registration, comments and checkout.', 'sendbeam' ) ),
		'site-email' => array( __( 'Site email', 'sendbeam' ), __( 'Sending WordPress mail from your verified domain.', 'sendbeam' ) ),
	) as $slug => $meta ) {
		printf(
			'<div><span class="sb-label">%1$s</span><p class="sb-note" style="margin:.2rem 0 .4rem">%2$s</p>' .
			'<a href="%3$s" target="_blank" rel="noopener">%4$s</a></div>',
			esc_html( $meta[0] ),
			esc_html( $meta[1] ),
			esc_url( sendbeam_docs_url( $slug ) ),
			esc_html__( 'Read', 'sendbeam' )
		);
	}
	echo '</div></div>';
	printf(
		'<p class="sb-note" style="margin-top:12px"><a href="%1$s" target="_blank" rel="noopener">%2$s</a> — %3$s</p>',
		esc_url( sendbeam_app_url() . '/docs/ecommerce' ),
		esc_html__( 'E-commerce events', 'sendbeam' ),
		esc_html__( 'Cart Abandoned, Product Viewed and Order Placed, plus the Shopify webhook.', 'sendbeam' )
	);
	sendbeam_card_close();

	sendbeam_card_open( __( 'Shortcodes', 'sendbeam' ), __( 'Anywhere shortcodes work: a block, a widget, a page builder.', 'sendbeam' ) );
	echo '<div class="sb-doc">';
	sendbeam_doc_snippet( '[sendbeam_form]', __( 'The default signup form', 'sendbeam' ) );
	sendbeam_doc_snippet( '[sendbeam_form id="8f3c1a2e-…"]', __( 'Any form, as often as you like', 'sendbeam' ) );
	sendbeam_doc_snippet( '[sendbeam_contact]', __( 'The contact form chosen on the Forms tab', 'sendbeam' ) );
	sendbeam_doc_snippet( '[sendbeam_popup_button label="Subscribe"]', __( 'A button that opens a pop-up', 'sendbeam' ) );
	echo '<p class="sb-note" style="margin-top:12px">' . esc_html__( 'A height attribute works on all of them, but you rarely want one: an embedded form measures itself and the frame follows.', 'sendbeam' ) . '</p>';
	echo '</div>';
	sendbeam_card_close();

	sendbeam_card_open( __( 'API key permissions', 'sendbeam' ), __( 'Only what you use.', 'sendbeam' ) );
	echo '<div class="sb-doc">';
	echo '<p>' . esc_html__( 'Placing a form or a pop-up needs no key at all. Everything else asks for one thing:', 'sendbeam' ) . '</p>';
	echo '<table class="sb-table"><thead><tr><th>' . esc_html__( 'Permission', 'sendbeam' ) . '</th><th>' . esc_html__( 'Needed for', 'sendbeam' ) . '</th></tr></thead><tbody>';
	foreach ( array(
		'forms:read'                                 => __( 'Listing your forms here and in the block', 'sendbeam' ),
		'lists:read'                                 => __( 'The Audience tab and its counts', 'sendbeam' ),
		'contacts:read, contacts:write, lists:write' => __( 'The opt-in box at registration, comments or checkout, and forms from Contact Form 7, Elementor Pro, WPForms, Gravity Forms and Fluent Forms', 'sendbeam' ),
		'tags:read, tags:write'                      => __( 'A form plugin form that adds a tag', 'sendbeam' ),
		'transactional:send'                         => __( 'Site email', 'sendbeam' ),
		'ecommerce:write'                            => __( 'E-commerce events: Cart Abandoned, Product Viewed, Order Placed', 'sendbeam' ),
	) as $perm => $why ) {
		printf( '<tr><td><code>%s</code></td><td>%s</td></tr>', esc_html( $perm ), esc_html( $why ) );
	}
	echo '</tbody></table>';
	printf(
		'<p class="sb-note" style="margin-top:12px">%s</p>',
		esc_html__( 'Give each site its own key so one can be revoked without disturbing the others. Defining SENDBEAM_API_KEY in wp-config.php keeps the key out of the database entirely.', 'sendbeam' )
	);
	echo '</div>';
	sendbeam_card_close();

	sendbeam_card_open( __( 'For developers', 'sendbeam' ) );
	echo '<div class="sb-doc">';
	echo '<p>' . esc_html__( 'A page can react to a submission, and a self-hosted SendBeam can be pointed at with a filter.', 'sendbeam' ) . '</p>';
	sendbeam_doc_snippet( "document.addEventListener('sendbeam:submitted', e => console.log(e.detail.formId))", __( 'Fires when a form in the page is submitted', 'sendbeam' ) );
	sendbeam_doc_snippet( "add_filter('sendbeam_app_url', fn() => 'https://mail.example.com')", __( 'Point at your own SendBeam', 'sendbeam' ) );
	printf(
		'<p style="margin-top:12px">%s <a href="%s" target="_blank" rel="noopener">%s</a></p>',
		esc_html__( 'Source, issues and releases:', 'sendbeam' ),
		esc_url( 'https://github.com/sendbeam-io/sendbeam-wordpress' ),
		esc_html__( 'sendbeam-io/sendbeam-wordpress', 'sendbeam' )
	);
	echo '</div>';
	sendbeam_card_close();
}
