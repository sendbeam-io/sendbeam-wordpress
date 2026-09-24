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

/**
 * Close a settings form.
 *
 * The plugin's own button rather than `submit_button()`: core's renders a
 * `.button-primary`, and a core blue pill in the middle of a screen built out
 * of square ink-ruled cards is the one element that looks pasted in. It is
 * the admin theme colour either way — this one just wears the rest of the
 * plugin's shape.
 */
function sendbeam_form_close() {
	printf( '<p class="submit"><button type="submit" class="sb-btn sb-btn--primary">%s</button></p>', esc_html__( 'Save changes', 'sendbeam' ) );
	echo '</form>';
}

/* -------------------------------------------------------------- Overview */

/**
 * Overview: how far through set-up the site is, and what the workspace holds.
 */
function sendbeam_screen_overview() {
	$forms = sendbeam_remote_forms();
	$lists = sendbeam_lists();

	// Asked for directly, not summed from the lists: one person on three lists
	// is one subscriber, and adding the lists up counted them three times.
	$subscribers = sendbeam_subscriber_count();
	$connected   = sendbeam_is_connected();

	/*
	 * Straight back from the registrar. The records were written a second
	 * ago, so this is the one page load that has a reason to ask SendBeam to
	 * look again rather than read the minute-old cache — and if that check
	 * verifies the domain, it finishes the held-back switch-on exactly as
	 * pressing Check now would.
	 */
	$returned = sendbeam_overview_dns_return();
	$status   = $connected ? sendbeam_connect_status( 'done' === $returned ) : sendbeam_connect_empty_status();
	if ( $connected && 'done' === $returned ) {
		sendbeam_connect_finish_deferred( $status );
	}

	sendbeam_overview_connection_card( $status, $connected );

	echo '<div class="sb-grid">';

	sendbeam_overview_setup_card( $status, $connected );

	sendbeam_workspace_card( $forms, $lists, $subscribers );

	echo '</div>';
}

/**
 * What the workspace holds, and what this site has been doing with it.
 *
 * Three figures across the top rather than stacked down the left: a column of
 * three numbers in the top third of a card leaves the other two thirds blank,
 * which reads as something that failed to load. Under them, the last few
 * messages this site sent — which is the question somebody comes to a
 * dashboard with, and which costs nothing to answer because the log is an
 * option this site already holds.
 *
 * @param array<int,mixed>|null $forms       The workspace's forms, or null.
 * @param array<int,mixed>|null $lists       The workspace's lists, or null.
 * @param int|null              $subscribers How many people, or null.
 */
function sendbeam_workspace_card( $forms, $lists, $subscribers ) {
	$open = sprintf(
		'<a class="sb-btn sb-btn--ghost sb-btn--small" href="%s" target="_blank" rel="noopener">%s</a>',
		esc_url( sendbeam_app_url() ),
		esc_html__( 'Open SendBeam', 'sendbeam' )
	);

	sendbeam_card_open( __( 'This workspace', 'sendbeam' ), '', $open );

	echo '<div class="sb-stats">';
	sendbeam_stat( __( 'Forms', 'sendbeam' ), is_array( $forms ) ? count( $forms ) : null );
	sendbeam_stat( __( 'Lists', 'sendbeam' ), is_array( $lists ) ? count( $lists ) : null );
	sendbeam_stat( __( 'Subscribers', 'sendbeam' ), $subscribers );
	echo '</div>';
	echo '<p class="sb-note">' . esc_html__( 'Subscribers counts people, not list memberships. Cached for five minutes.', 'sendbeam' ) . '</p>';

	sendbeam_workspace_recent();

	sendbeam_card_close();
}

/**
 * The last few things this site sent, from the log it already keeps.
 *
 * Five rows, because the point is "is it working?" rather than "what has it
 * ever done" — the Site email screen has the whole log, searchable.
 */
function sendbeam_workspace_recent() {
	$log = get_option( 'sendbeam_mail_log', array() );
	$log = is_array( $log ) ? array_slice( array_values( $log ), 0, 5 ) : array();

	echo '<p class="sb-label" style="margin-top:18px">' . esc_html__( 'Recent site email', 'sendbeam' ) . '</p>';

	if ( ! $log ) {
		echo '<p class="sb-note" style="margin:0">' . esc_html__( 'Nothing has gone out through SendBeam from this site yet.', 'sendbeam' ) . '</p>';
		return;
	}

	$results = array(
		'sent'     => array( 'ok', __( 'Sent', 'sendbeam' ) ),
		'fallback' => array( 'warn', __( 'Server mailer', 'sendbeam' ) ),
		'failed'   => array( 'bad', __( 'Failed', 'sendbeam' ) ),
	);

	$today = wp_date( 'Y-m-d' );

	echo '<ul class="sb-recent">';
	foreach ( $log as $row ) {
		$key = isset( $results[ $row['result'] ] ) ? $row['result'] : 'failed';

		/*
		 * Two dates, one shown at a time by CSS. "September 24, 2026" is the
		 * site's own format and right on a desktop; on a phone it took half
		 * the row and left the address with nowhere to go. Today's messages
		 * get a time instead, which is what somebody checking whether the
		 * last one went out actually wants.
		 */
		$long  = wp_date( get_option( 'date_format' ), (int) $row['at'] );
		$short = wp_date( 'Y-m-d', (int) $row['at'] ) === $today
			? wp_date( get_option( 'time_format' ), (int) $row['at'] )
			: wp_date( 'j M', (int) $row['at'] );

		printf(
			'<li><span class="sb-recent__when"><span class="sb-recent__long">%1$s</span>' .
			'<span class="sb-recent__short">%2$s</span></span>' .
			'<span class="sb-recent__who">%3$s</span>' .
			'<span class="sb-recent__what sb-recent__what--%4$s">%5$s</span></li>',
			esc_html( $long ),
			esc_html( $short ),
			esc_html( (string) $row['to'] ),
			esc_attr( $results[ $key ][0] ),
			esc_html( $results[ $key ][1] )
		);
	}
	echo '</ul>';

	printf(
		'<p class="sb-note" style="margin-top:10px"><a href="%s">%s</a></p>',
		esc_url( sendbeam_page_url( 'sendbeam-mail' ) ),
		esc_html__( 'View the log', 'sendbeam' )
	);
}

/**
 * The connection, at the top, where somebody arriving looks first.
 *
 * It used to live inside step 1 of the checklist, which meant that on a
 * finished site — the state most sites are in most of the time — the one
 * question the Overview is asked ("what is this connected to?") was answered
 * by a ticked line with nothing under it.
 *
 * @param array $status    Normalised Connect status.
 * @param bool  $connected Whether the key works.
 */
function sendbeam_overview_connection_card( $status, $connected ) {
	if ( ! $connected ) {
		sendbeam_card_open( __( 'Connection', 'sendbeam' ), __( 'Not connected yet.', 'sendbeam' ) );
		sendbeam_connect_panel();
		sendbeam_connect_paste_disclosure();
		sendbeam_card_close();
		return;
	}

	sendbeam_card_open( __( 'Connection', 'sendbeam' ), sendbeam_sends_as_line( $status ) );
	sendbeam_connect_connected_panel( $status );
	sendbeam_card_close();
}

/**
 * "Sends as …", and what kind of address that is.
 *
 * The address on its own was the second half of a contradiction: a card
 * saying "Sends as ws-…@post.sendbeam.io" above a checklist saying the site's
 * own domain was verified told somebody two true things and left them to work
 * out that only one of them was about their email. Saying which kind of
 * address it is settles it in three words.
 *
 * @param array $status Normalised Connect status.
 * @return string
 */
function sendbeam_sends_as_line( $status ) {
	$sender = sendbeam_effective_sender( $status );
	if ( '' === $sender['email'] ) {
		return '';
	}

	$kinds = array(
		'own_domain' => __( 'your domain', 'sendbeam' ),
		'shared'     => __( 'SendBeam\'s shared address', 'sendbeam' ),
		'other'      => __( 'not a verified domain', 'sendbeam' ),
	);

	if ( ! isset( $kinds[ $sender['kind'] ] ) ) {
		/* translators: %s: the address this site sends as */
		return sprintf( __( 'Sends as %s', 'sendbeam' ), $sender['email'] );
	}

	return sprintf(
		/* translators: 1: the address this site sends as, 2: what kind of address it is */
		__( 'Sends as %1$s (%2$s)', 'sendbeam' ),
		$sender['email'],
		$kinds[ $sender['kind'] ]
	);
}

/**
 * The four-step checklist, and the way to put it away once it is finished.
 *
 * A checklist with four ticks on it is furniture. It stays until every step
 * is done and then offers to go — per user, because one administrator
 * hiding it should not hide it from the next person who installs something.
 *
 * @param array $status    Normalised Connect status.
 * @param bool  $connected Whether the key works.
 */
function sendbeam_overview_setup_card( $status, $connected ) {
	$steps = sendbeam_setup_steps();
	$left  = 0;
	foreach ( $steps as $step ) {
		if ( empty( $step['done'] ) ) {
			++$left;
		}
	}

	if ( 0 === $left && sendbeam_checklist_hidden() ) {
		sendbeam_card_open( __( 'Setup', 'sendbeam' ) );
		echo '<p class="sb-verified" style="margin:0"><span class="sb-tick" aria-hidden="true">&#10003;</span> ' . esc_html__( 'Set up and sending.', 'sendbeam' ) . '</p>';
		printf(
			'<p class="sb-note"><a href="%s">%s</a></p>',
			esc_url( sendbeam_checklist_url( false ) ),
			esc_html__( 'Show the checklist again', 'sendbeam' )
		);
		sendbeam_card_close();
		return;
	}

	sendbeam_card_open(
		__( 'Setup', 'sendbeam' ),
		$left > 0
			/* translators: %d: how many setup steps are left */
			? sprintf( _n( '%d step left', '%d steps left', $left, 'sendbeam' ), $left )
			: __( 'All done', 'sendbeam' )
	);
	echo '<ol class="sb-steps">';
	foreach ( $steps as $i => $step ) {
		/*
		 * Three states, not two. A step that is switched on and doing the
		 * wrong thing is not finished, and it is not unstarted either — a
		 * plain number beside it reads as "you have not got to this yet".
		 */
		$attention = ! empty( $step['attention'] );
		printf(
			'<li class="%1$s"><span class="sb-num" aria-hidden="true">%2$s</span><div class="sb-step">',
			esc_attr( $step['done'] ? 'is-done' : ( $attention ? 'needs-attention' : '' ) ),
			$step['done'] ? '&#10003;' : ( $attention ? '!' : (int) ( $i + 1 ) )
		);

		// The connect step links to the card above rather than to a field:
		// that card is where connecting is actually done now.
		printf( '<strong><a href="%1$s">%2$s</a></strong>', esc_url( $step['target'] ), esc_html( $step['label'] ) );
		printf( '<span class="sb-step__note">%s</span>', esc_html( $step['detail'] ) );

		sendbeam_overview_step_panel( $step, $status, $connected );

		echo '</div></li>';
	}
	echo '</ol>';

	if ( 0 === $left ) {
		printf(
			'<p class="sb-note" style="margin-top:12px"><a href="%s">%s</a></p>',
			esc_url( sendbeam_checklist_url( true ) ),
			esc_html__( 'Hide this checklist', 'sendbeam' )
		);
	}
	sendbeam_card_close();
}

/**
 * Did this page load arrive back from the registrar, and how did it go?
 *
 * The two values come from a redirect SendBeam issued, and the only thing
 * read out of them is which of two sentences to print — and, for `done`,
 * whether to spend one check. The script strips both from the address bar
 * afterwards so a refresh does not check again.
 *
 * @return string 'done', 'error', or empty.
 */
function sendbeam_overview_dns_return() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a two-value flag on a redirect from the registrar; it chooses a sentence and one read-only check.
	$value = isset( $_GET['sb_dc'] ) ? sanitize_key( wp_unslash( $_GET['sb_dc'] ) ) : '';
	return in_array( $value, array( 'done', 'error' ), true ) ? $value : '';
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
			// Nothing: the connection card above the checklist is where this
			// step is done, and two Connect buttons on one screen is one too
			// many.
			break;
		case 'domain':
			if ( $connected ) {
				sendbeam_domain_panel( $status );
			}

			/*
			 * The domain this site sends from is chosen on the consent page,
			 * and going back there is the only way to change it. Nothing said
			 * so while the step was working — which is precisely when someone
			 * notices they set up the wrong domain, and the point at which
			 * every other sentence on the step has stopped talking to them.
			 */
			if ( $connected ) {
				printf(
					'<p class="sb-note"><a href="%s">%s</a></p>',
					esc_url( sendbeam_connect_start_url() ),
					esc_html__( 'Sending from the wrong domain? Reconnect and enter the one you want.', 'sendbeam' )
				);
			}
			break;
		case 'form':
			sendbeam_overview_form_panel( $step, $connected );
			break;
		case 'mail':
			sendbeam_overview_mail_panel( $status, $connected );
			break;
	}

	/*
	 * A step whose sentence names something this site's key cannot do ends in
	 * the same place: the consent page, with every box ticked. The link
	 * starts the ordinary Connect flow, and the key that comes back replaces
	 * the one this site holds. This is the only place the offer appears for a
	 * site with a pasted key — a connected card that says "Connected" and
	 * then offers to connect is the plugin arguing with itself.
	 */
	if ( $connected && ! empty( $step['reconnect'] ) ) {
		echo '<div class="sb-step__panel"><div class="sb-actions">';
		printf(
			'<a class="sb-btn sb-btn--small sb-btn--ghost" href="%s">%s</a>',
			esc_url( sendbeam_connect_start_url( true ) ),
			esc_html(
				sendbeam_connected_via_connect()
					// There is a connection to re-make, with more ticked.
					? __( 'Reconnect with more permissions', 'sendbeam' )
					// There is no connection to re-make: a pasted key is
					// being offered the thing it cannot do, where it cannot
					// do it, and nowhere else.
					: __( 'Switch to one-click Connect', 'sendbeam' )
			)
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
 * @param array $status     Normalised Connect status.
 * @param bool  $standalone True on the Settings page, where the panel is the
 *                          whole subject rather than one step in a checklist
 *                          that has already explained itself.
 */
function sendbeam_domain_panel( $status, $standalone = false ) {
	$domain   = $status['domain'];
	$verified = ! empty( $domain['verified'] );
	$state    = sendbeam_connect_domain_state( $status );

	/*
	 * No domain, so no records and nothing to check. On the Overview the
	 * step's own sentence has already said why; standing on its own, the
	 * panel has to say it itself.
	 */
	if ( '' === $domain['name'] ) {
		if ( $standalone ) {
			echo '<p>' . esc_html( sendbeam_domain_sentence( '', false ) ) . '</p>';
		}
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

	if ( $standalone ) {
		echo '<p style="margin-top:0">' . esc_html__( 'Proving that this domain is yours is what lets SendBeam send as you. It is also what keeps the mail out of spam folders: a mailbox provider that can check who sent a message treats it very differently from one it cannot.', 'sendbeam' ) . '</p>';
	}

	// The aggregate verdict, in the three states a site owner needs to tell
	// apart: done, waiting for DNS, and nothing found at all.
	sendbeam_domain_state_pill( $domain, $verified );

	if ( $domain['records'] ) {
		$hidden = $verified;
		if ( $hidden ) {
			echo '<details class="sb-paste"><summary>' . esc_html__( 'The records SendBeam is using', 'sendbeam' ) . '</summary><div style="margin-top:10px">';
		} else {
			echo '<ol class="sb-numbered"><li>';
			echo '<p style="margin-top:0">' . esc_html__( 'Add these records to the DNS for this domain, wherever you bought it. Every value copies when you click it.', 'sendbeam' ) . '</p>';
		}

		sendbeam_domain_records_table( $domain['records'] );

		if ( $hidden ) {
			echo '</div></details>';
		} else {
			echo '</li><li>';
			echo '<p style="margin-top:0">' . esc_html__( 'Then press Check now. DNS changes can take up to 24 hours to spread, so it is normal for the first check to find nothing.', 'sendbeam' ) . '</p>';
		}
	} elseif ( ! $verified ) {
		echo '<p class="sb-note">' . esc_html__( 'SendBeam has not sent the DNS records for this domain. Open Sending in SendBeam to see them.', 'sendbeam' ) . '</p>';
	}

	echo '<div class="sb-actions">';
	echo '<form action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post">';
	wp_nonce_field( 'sendbeam_domain_check' );
	echo '<input type="hidden" name="action" value="sendbeam_domain_check" />';
	printf(
		'<button type="submit" class="sb-btn sb-btn--small%1$s">%2$s</button>',
		esc_attr( $verified ? '' : ' sb-btn--primary' ),
		esc_html( $verified ? __( 'Check again', 'sendbeam' ) : __( 'Check now', 'sendbeam' ) )
	);
	echo '</form>';

	if ( ! $verified && '' !== $domain['domain_connect_url'] ) {
		printf(
			'<a class="sb-btn sb-btn--small sb-btn--ghost" href="%s" target="_blank" rel="noopener">%s</a>',
			esc_url( sendbeam_domain_connect_url( $domain['domain_connect_url'] ) ),
			esc_html__( 'Set up DNS automatically', 'sendbeam' )
		);
	}
	echo '</div>';

	if ( $domain['records'] && ! $verified ) {
		echo '</li></ol>';
	}

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
 * Where the domain has got to, in one line.
 *
 * Three states, because those are the three a site owner acts differently on:
 * verified is finished, pending means the records are out there and DNS has
 * not caught up, and not found means nothing has been added yet. "Pending"
 * and "not found" look identical without something that says which — and the
 * second one is the one where somebody still has work to do.
 *
 * @param array $domain   The domain from the status body.
 * @param bool  $verified Whether SendBeam has seen the records.
 */
function sendbeam_domain_state_pill( $domain, $verified ) {
	if ( $verified ) {
		echo '<p class="sb-verified"><span class="sb-tick" aria-hidden="true">&#10003;</span> ';
		printf(
			/* translators: %s: the sending domain */
			esc_html__( 'Verified — %s', 'sendbeam' ),
			esc_html( $domain['name'] )
		);
		echo '</p>';
		return;
	}

	$found = 0;
	foreach ( (array) $domain['records'] as $record ) {
		if ( ! empty( $record['found'] ) ) {
			++$found;
		}
	}

	if ( $found > 0 ) {
		echo '<p class="sb-state-line"><span class="sb-state sb-state--amber">' . esc_html__( 'Pending', 'sendbeam' ) . '</span> ';
		echo esc_html(
			sprintf(
				/* translators: 1: records found, 2: records wanted, 3: the sending domain */
				__( '%1$d of %2$d records are live for %3$s. DNS changes can take up to 24 hours to spread.', 'sendbeam' ),
				$found,
				count( (array) $domain['records'] ),
				$domain['name']
			)
		) . '</p>';
		return;
	}

	echo '<p class="sb-state-line"><span class="sb-state sb-state--ink">' . esc_html__( 'Not found', 'sendbeam' ) . '</span> ';
	echo esc_html(
		sprintf(
			/* translators: %s: the sending domain */
			__( 'Nothing has been found in the DNS for %s yet.', 'sendbeam' ),
			$domain['name']
		)
	) . '</p>';
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
	echo '<div class="sb-scroll"><table class="widefat striped sb-dns"><thead><tr>';
	echo '<th scope="col">' . esc_html__( 'Type', 'sendbeam' ) . '</th>';
	echo '<th scope="col">' . esc_html__( 'Host', 'sendbeam' ) . '</th>';
	echo '<th scope="col">' . esc_html__( 'Value', 'sendbeam' ) . '</th>';
	echo '<th scope="col">' . esc_html__( 'Found', 'sendbeam' ) . '</th>';
	echo '</tr></thead><tbody>';

	foreach ( $records as $i => $record ) {
		$host = '' !== $record['name'] ? $record['name'] : '@';

		/* translators: %s: a DNS record type, e.g. CNAME */
		$host_label = sprintf( __( 'Host for the %s record', 'sendbeam' ), $record['type'] );
		/* translators: %s: a DNS record type, e.g. CNAME */
		$value_label = sprintf( __( 'Value for the %s record', 'sendbeam' ), $record['type'] );

		/*
		 * Every cell carries the column it belongs to. On a phone the row
		 * becomes a block and those labels are the only thing left saying
		 * which field is the host and which is the value — the header row is
		 * gone by then.
		 */
		echo '<tr>';
		printf(
			'<td class="sb-dns__type sb-mono" data-label="%1$s">%2$s</td>',
			esc_attr__( 'Type', 'sendbeam' ),
			esc_html( $record['type'] )
		);
		printf( '<td class="sb-dns__host" data-label="%s">', esc_attr__( 'Host', 'sendbeam' ) );
		echo sendbeam_copy_field( $host, $host_label, 'sb-dns-host-' . (int) $i ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every value is escaped inside sendbeam_copy_field().
		echo '</td>';
		printf( '<td class="sb-dns__value" data-label="%s">', esc_attr__( 'Value', 'sendbeam' ) );
		echo sendbeam_copy_field( $record['value'], $value_label, 'sb-dns-value-' . (int) $i ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- as above.
		echo '</td>';
		printf( '<td class="sb-dns__found" data-label="%s">', esc_attr__( 'Found', 'sendbeam' ) );
		echo sendbeam_record_verdict( isset( $record['found'] ) ? $record['found'] : null ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside sendbeam_record_verdict().
		echo '</td>';
		echo '</tr>';
	}
	echo '</tbody></table></div>';
	echo '<p class="sb-note">' . esc_html__( 'Click any value to copy it. Your registrar may add the domain to the host for you — if it does, enter only the part shown here.', 'sendbeam' ) . '</p>';
}

/**
 * A value somebody has to retype into a registrar, made not-retypeable.
 *
 * A read-only field rather than a `<code>` block: it selects on focus, it is
 * reachable with a keyboard, and clicking it copies.
 *
 * A textarea rather than an `<input>`, for one reason: an input cannot wrap.
 * On a phone the value column is about ninety pixels wide, and a DKIM host in
 * a ninety-pixel input reads "resend._dc" — copyable and completely
 * unreadable, which is the thing somebody checking their DNS most needs to be
 * able to do. A textarea wraps the whole value onto as many lines as it takes.
 *
 * @param string $value The thing to copy.
 * @param string $label What it is, for screen readers.
 * @param string $id    A unique id for the field.
 * @return string Escaped HTML.
 */
function sendbeam_copy_field( $value, $label, $id ) {
	return sprintf(
		'<label class="screen-reader-text" for="%1$s">%2$s</label>' .
		'<textarea class="sb-copy-field sb-copy" id="%1$s" rows="1" readonly ' .
		'data-copy="%3$s" data-done="%4$s" title="%5$s" spellcheck="false" ' .
		'autocapitalize="off" autocorrect="off">%6$s</textarea>',
		esc_attr( $id ),
		esc_html( $label ),
		esc_attr( $value ),
		esc_attr__( 'Copied', 'sendbeam' ),
		esc_attr__( 'Click to copy', 'sendbeam' ),
		esc_textarea( $value )
	);
}

/**
 * The one-click DNS link, told where to send the owner afterwards.
 *
 * Without this the journey ends on SendBeam's own sending settings — which is
 * a reasonable place for it to end if you started there, and the wrong one
 * entirely when you started in wp-admin, pressed a button in a checklist, and
 * expected to come back and see the step ticked. SendBeam honours the return
 * address only when it belongs to a site one of its own keys was issued to.
 *
 * @param string $url The registrar link SendBeam sent.
 * @return string
 */
function sendbeam_domain_connect_url( $url ) {
	return add_query_arg( 'return_to', rawurlencode( sendbeam_tab_url( 'overview' ) ), $url );
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
	if ( ! $connected ) {
		return;
	}

	// Ticked because the owner said so, not because anything was found. The
	// step says which, and offers to stop believing it.
	if ( $step['done'] ) {
		if ( 'manual' === ( isset( $step['where'] ) ? $step['where'] : '' ) ) {
			echo '<div class="sb-actions">';
			printf(
				'<a class="sb-btn sb-btn--small sb-btn--ghost" href="%s">%s</a>',
				esc_url( sendbeam_form_placed_url( false ) ),
				esc_html__( 'Actually, it is not', 'sendbeam' )
			);
			echo '</div>';
		}
		return;
	}

	$settings = sendbeam_settings();
	$chosen   = '' !== (string) $settings['default_form'] || '' !== (string) $settings['contact_form'];
	$forms    = sendbeam_remote_forms();

	/*
	 * The override is the answer for a form this site cannot find — one in a
	 * page builder's own storage, or on a page behind a login. That is not
	 * conditional on a *default* form being chosen: the form on the page may
	 * be named in its own shortcode, and this step was leaving those sites
	 * with an untickable step and no way to say so. Any form in the workspace
	 * is enough of a reason to offer it.
	 */
	if ( ! $chosen && ! ( is_array( $forms ) && $forms ) ) {
		return;
	}

	echo '<div class="sb-actions">';
	if ( $chosen ) {
		echo '<code>[sendbeam_form]</code>';
		printf(
			'<button type="button" class="sb-btn sb-btn--small sb-btn--ghost sb-copy" data-copy="%1$s" data-done="%2$s">%3$s</button>',
			esc_attr( '[sendbeam_form]' ),
			esc_attr__( 'Copied', 'sendbeam' ),
			esc_html__( 'Copy', 'sendbeam' )
		);
	}
	printf(
		'<a class="sb-btn sb-btn--small sb-btn--ghost" href="%s">%s</a>',
		esc_url( sendbeam_form_placed_url() ),
		esc_html__( 'I\'ve placed it elsewhere', 'sendbeam' )
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
		$sender = sendbeam_effective_sender( $status );
		$own    = sendbeam_own_domain_sender( $status );

		echo '<div class="sb-actions">';

		// The fix first, where there is one to make: a site sending as
		// SendBeam from a verified domain is one press from sending as
		// itself, and that press is worth more than another test email.
		if ( 'shared' === $sender['kind'] && '' !== $own ) {
			echo '<form action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post">';
			wp_nonce_field( 'sendbeam_mail_own_domain' );
			echo '<input type="hidden" name="action" value="sendbeam_mail_own_domain" />';
			printf(
				'<button type="submit" class="sb-btn sb-btn--small sb-btn--primary">%s</button>',
				esc_html(
					sprintf(
						/* translators: %s: the verified sending domain */
						__( 'Send from %s', 'sendbeam' ),
						$status['domain']['name']
					)
				)
			);
			echo '</form>';
		}

		echo '<form action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post">';
		wp_nonce_field( 'sendbeam_test_mail' );
		echo '<input type="hidden" name="action" value="sendbeam_test_mail" />';
		printf( '<button type="submit" class="sb-btn sb-btn--small%s">%s</button>', esc_attr( 'shared' === $sender['kind'] && '' !== $own ? ' sb-btn--ghost' : '' ), esc_html__( 'Send a test email', 'sendbeam' ) );
		echo '</form>';
		echo '</div>';

		if ( 'shared' === $sender['kind'] && '' !== $own ) {
			echo '<p class="sb-note">' . esc_html(
				sprintf(
					/* translators: %s: the address this site would send as instead */
					__( 'That changes the From address to %s. Nothing else moves, and you can change it again under Site email.', 'sendbeam' ),
					$own
				)
			) . '</p>';
		}
		return;
	}

	// Granted, or unknown — a pasted key records no permissions, and hiding
	// the button on a site whose key may well have the permission leaves the
	// step with nothing on it and no way to find out. The handler checks
	// again and says so if SendBeam refuses.
	$may_send = sendbeam_connect_granted( 'transactional:send' ) || ! sendbeam_connect_permissions_known();
	if ( ! $may_send || empty( $status['domain']['verified'] ) ) {
		return; // The step's own sentence already says which one is missing.
	}

	echo '<div class="sb-actions">';
	echo '<form action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post">';
	wp_nonce_field( 'sendbeam_mail_switch_on' );
	echo '<input type="hidden" name="action" value="sendbeam_mail_switch_on" />';
	printf( '<button type="submit" class="sb-btn sb-btn--small sb-btn--primary">%s</button>', esc_html__( 'Switch on', 'sendbeam' ) );
	echo '</form>';
	echo '</div>';
	echo '<p class="sb-note">' . esc_html(
		sprintf(
			/* translators: %s: the From address site email will use */
			__( 'Email will be sent as %s. You can change that under Site email.', 'sendbeam' ),
			'' !== $status['sender']['from_email'] ? $status['sender']['from_email'] : __( 'your workspace sender', 'sendbeam' )
		)
	) . '</p>';
}

/**
 * The paste field, tucked away for the people who already have a key.
 *
 * Open by default would make the screen look like it still wants a key
 * copied across, which is the friction Connect exists to remove.
 *
 * On a site whose key is pinned in wp-config.php there is nothing to paste:
 * a key saved here would lose to the constant on every request. The panel
 * above has already said where the live key is, so this simply goes away
 * rather than inviting somebody to store a second one.
 */
function sendbeam_connect_paste_disclosure() {
	if ( sendbeam_key_in_config() ) {
		return;
	}
	echo '<details class="sb-paste" style="margin-top:14px">';
	echo '<summary>' . esc_html__( 'I already have an API key', 'sendbeam' ) . '</summary>';
	echo '<div style="margin-top:10px">';
	sendbeam_form_open( 'connect' );
	do_settings_sections( 'sendbeam_connect_page' );
	sendbeam_form_close();
	echo '</div></details>';
}

/**
 * The connected card: one shape, however the key got here.
 *
 * There used to be two. A site with a pasted key got a card offering
 * **Connect this site** and **Replace the key** — under a header band already
 * saying "Connected", which reads as the plugin not knowing its own state.
 * Connecting is an upgrade, not a correction, and an upgrade belongs where
 * the pasted key actually falls short: on the step that cannot answer without
 * the permissions Connect grants. Not here, where the answer is simply "yes,
 * connected".
 *
 * So: what it is connected to, how the key arrived, and the one thing a
 * connected card is for — disconnecting. Reconnect joins it only when there
 * is something to reconnect *to*, which a pasted key does not have.
 *
 * Disconnect no longer goes through the settings form's "Remove the saved
 * key" checkbox. That only ever forgot this site's copy, and left a live key
 * in the workspace for the owner to go and revoke by hand — which is exactly
 * what nobody does. It now calls SendBeam and revokes the key first, for a
 * key SendBeam issued to this site. A pasted key is somebody else's to
 * revoke: it may be shared with another site, so it is forgotten here and
 * left alone there, and the confirmation says so.
 *
 * The confirmation is inline rather than window.confirm(): a browser dialog
 * cannot say what disconnecting costs, and half of them are suppressed after
 * the first one on a page. With scripts off the confirmation box is simply
 * already open, so the button still works.
 *
 * @param array $status Normalised Connect status, for the workspace name.
 */
function sendbeam_connect_connected_panel( $status = null ) {
	$via_connect = sendbeam_connected_via_connect();
	$workspace   = sendbeam_connect_workspace_name();
	if ( '' === $workspace && is_array( $status ) ) {
		$workspace = (string) $status['workspace']['name'];
	}

	echo '<div class="sb-step__panel">';

	if ( '' !== $workspace ) {
		/* translators: %s: the SendBeam workspace name */
		echo '<p style="margin-top:0"><strong>' . esc_html( sprintf( __( 'Connected to %s', 'sendbeam' ), $workspace ) ) . '</strong></p>';
	} else {
		echo '<p style="margin-top:0"><strong>' . esc_html__( 'Connected to SendBeam', 'sendbeam' ) . '</strong></p>';
	}

	// How the key got here, quietly. It is the difference between a
	// connection this plugin can reason about and one it can only use.
	echo '<p class="sb-note" style="margin-top:-6px">' . esc_html(
		$via_connect
			? __( 'via Connect', 'sendbeam' )
			: __( 'using an API key', 'sendbeam' )
	) . '</p>';

	if ( sendbeam_key_in_config() ) {
		echo '<p class="sb-note">' . esc_html__( 'This site sends with the key defined as SENDBEAM_API_KEY in wp-config.php, not the one stored here. Change it there; Disconnect will not revoke it.', 'sendbeam' ) . '</p>';
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
	 * has, so the consent page comes up saying what is already true — which
	 * means it only makes sense for a connection that has permissions on
	 * record, and a pasted key has none.
	 */
	if ( $via_connect ) {
		printf(
			'<a class="sb-btn sb-btn--ghost sb-btn--small" href="%s">%s</a> ',
			esc_url( sendbeam_connect_start_url() ),
			esc_html__( 'Reconnect', 'sendbeam' )
		);
	}
	printf(
		'<button type="button" class="sb-btn sb-btn--danger sb-btn--small sb-confirm__ask" hidden>%s</button>',
		esc_html__( 'Disconnect', 'sendbeam' )
	);
	echo '<div class="sb-confirm__box">';
	echo '<p class="sb-note">' . esc_html(
		$via_connect
			? __( 'Disconnect this site? Its key is revoked. Your list, form and sending domain stay in SendBeam. Forms already on your pages stop loading until you connect again.', 'sendbeam' )
			: __( 'Disconnect this site? The saved key is removed from this site and nothing is revoked in SendBeam — a key you pasted may be in use somewhere else. Forms already on your pages stop loading until you connect again.', 'sendbeam' )
	) . '</p>';
	printf( '<button type="submit" class="sb-btn sb-btn--small sb-btn--danger">%s</button> ', esc_html__( 'Yes, disconnect', 'sendbeam' ) );
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
 * Forms: the default forms, how embedded forms look, and every form in the workspace.
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
		'<p style="margin-top:14px"><label class="sb-toggle"><input type="checkbox" name="sendbeam_settings[style_bare]" value="1" %s /> <span>%s</span></label></p>',
		checked( ! empty( $s['style_bare'] ), true, false ),
		esc_html__( 'Hide the form name and subtitle inside the embed (your page already has a heading)', 'sendbeam' )
	);
	sendbeam_form_close();
	sendbeam_card_close();

	sendbeam_list_card(
		__( 'Your forms', 'sendbeam' ),
		__( 'Every form in this workspace, ready to drop into a page.', 'sendbeam' ),
		__( 'Search forms', 'sendbeam' )
	);

	sendbeam_placing_forms_card();
}

/* -------------------------------------------------------------- Audience */

/**
 * Audience: the workspace's lists, and where to ask people to subscribe.
 */
function sendbeam_screen_audience() {
	$lists = sendbeam_lists();

	sendbeam_list_card(
		__( 'Lists', 'sendbeam' ),
		__( 'Which list a form subscribes people to is set on the form itself, in SendBeam.', 'sendbeam' ),
		__( 'Search lists', 'sendbeam' )
	);

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

	sendbeam_card_open( __( 'Form plugins', 'sendbeam' ) );
	echo '<p style="margin-top:0">' . esc_html__( 'Send the people who fill in forms built with another plugin to SendBeam. Each form is switched on where that plugin keeps its settings, and sends someone only when they ticked the consent field you name — or every submission, when you mark the form as a signup form.', 'sendbeam' ) . '</p>';

	echo '<div class="sb-scroll"><table class="sb-table"><thead><tr>';
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
	echo '</tbody></table></div>';
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
			'<p><label class="sb-toggle"><input type="checkbox" name="%1$s[enabled]" value="1"%2$s /> <strong>%3$s</strong></label></p>',
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
			'<p><label class="sb-toggle"><input type="checkbox" name="%1$s[consent]" value="signup"%2$s /> <span>%3$s</span></label></p>',
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
	echo '<p><button type="submit" class="sb-btn sb-btn--primary">' . esc_html__( 'Save', 'sendbeam' ) . '</button></p>';
	echo '</form>';
}

/**
 * Subscribing people who are already doing something else on the site.
 *
 * @param array<int,array<string,mixed>>|null $lists Lists, or null when unreadable.
 */
function sendbeam_screen_sync( $lists ) {
	$sync = sendbeam_sync_settings();

	sendbeam_card_open( __( 'Collect subscribers elsewhere on the site', 'sendbeam' ) );

	echo '<p style="margin-top:0">' . esc_html__( 'Adds an opt-in tick box to things people already do here. Nobody is subscribed without ticking it, and the box is never pre-ticked.', 'sendbeam' ) . '</p>';

	if ( null === $lists || ! $lists ) {
		echo '<p class="sb-msg sb-msg--warn">' . esc_html__( 'This needs at least one list, read with a key that has the Lists (read) permission. Connect this site under Settings, or add one in SendBeam.', 'sendbeam' ) . '</p>';
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
			'<p><label class="sb-toggle"><input type="checkbox" name="sendbeam_sync[%1$s]" value="1" %2$s %3$s /> <span>%4$s%5$s</span></label></p>',
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

	echo '<p><button type="submit" class="sb-btn sb-btn--primary">' . esc_html__( 'Save', 'sendbeam' ) . '</button></p>';
	echo '</form>';
	sendbeam_card_close();

	$log = get_option( SENDBEAM_SYNC_LOG, array() );
	if ( is_array( $log ) && $log ) {
		sendbeam_card_open( __( 'Recent subscriptions', 'sendbeam' ) );
		echo '<div class="sb-scroll"><table class="sb-table"><thead><tr>';
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
		echo '</tbody></table></div>';
		sendbeam_card_close();
	}
}

/* --------------------------------------------------------------- Pop-ups */

/**
 * Pop-ups tab: the ordered rules, each with its own form, targeting and trigger.
 */
function sendbeam_screen_popup() {
	$rules = sendbeam_popups();

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
	echo '<p><button type="submit" class="sb-btn sb-btn--primary">' . esc_html__( 'Save pop-ups', 'sendbeam' ) . '</button></p>';
	echo '</form>';

	// The blank row the "add" button clones. __i__ is swapped for the index.
	echo '<template id="sb-popup-template">';
	sendbeam_popup_row( '__i__', sendbeam_popup_defaults() );
	echo '</template>';

	sendbeam_card_close();

	sendbeam_placing_forms_card();
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

			<label class="sb-toggle" style="grid-column:1/-1">
				<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[proof]" value="1" <?php checked( ! empty( $rule['proof'] ) ); ?> />
				<span><?php esc_html_e( 'Show subscriber count', 'sendbeam' ); ?>
					<span class="sb-note"><?php esc_html_e( 'Shows "Joined by N readers" once the list has 50 people.', 'sendbeam' ); ?></span>
				</span>
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
			<label class="sb-toggle">
				<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[enabled]" value="1" <?php checked( ! empty( $rule['enabled'] ) ); ?> />
				<span><?php esc_html_e( 'Active', 'sendbeam' ); ?></span>
			</label>
			<button type="button" class="sb-btn sb-btn--small sb-btn--ghost sb-remove"><?php esc_html_e( 'Remove', 'sendbeam' ); ?></button>
		</div>
	</fieldset>
	<?php
}

/* ------------------------------------------------------------ Site email */

/**
 * Site email: the wp_mail() relay, a test send, and the log of what went out.
 */
function sendbeam_screen_mail() {
	sendbeam_card_open( __( 'Site email', 'sendbeam' ) );
	sendbeam_form_open( 'mail' );
	do_settings_sections( 'sendbeam_mail_page' );
	sendbeam_form_close();
	sendbeam_card_close();

	sendbeam_test_mail_card();

	sendbeam_list_card(
		__( 'Email log', 'sendbeam' ),
		__( 'The last few messages this site handed to SendBeam, and what became of each.', 'sendbeam' ),
		__( 'Search the log', 'sendbeam' )
	);
}

/**
 * Send a test email, and say what happened without leaving the page.
 *
 * The result replaces the form rather than appearing as a notice above it,
 * because a diagnostic is not an announcement: somebody who has just pressed
 * "Send a test email" is reading the space the button was in. A failure gets
 * three sentences — what went wrong, what it means, what to do — and a block
 * of detail they can copy into a support email in one click.
 */
function sendbeam_test_mail_card() {
	$result = sendbeam_take_test_result();

	sendbeam_card_open( __( 'Send a test email', 'sendbeam' ) );
	echo '<div id="sendbeam-test">';

	if ( is_array( $result ) && ! empty( $result['ok'] ) ) {
		sendbeam_test_mail_success( $result );
		echo '</div>';
		sendbeam_card_close();
		return;
	}
	if ( is_array( $result ) ) {
		sendbeam_test_mail_failure( $result );
		echo '</div>';
		sendbeam_card_close();
		return;
	}

	sendbeam_test_mail_form( __( 'Send a test email', 'sendbeam' ) );
	echo '</div>';
	sendbeam_card_close();
}

/**
 * The form: who it goes to, and one button.
 *
 * @param string $label Button text.
 */
function sendbeam_test_mail_form( $label ) {
	$to = (string) wp_get_current_user()->user_email;
	echo '<form action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post">';
	echo '<input type="hidden" name="action" value="sendbeam_test_mail" />';
	wp_nonce_field( 'sendbeam_test_mail' );
	echo '<p class="sb-field"><span class="sb-label">' . esc_html__( 'Send to', 'sendbeam' ) . '</span>';
	printf(
		'<input type="email" class="regular-text" value="%s" readonly aria-describedby="sendbeam-test-who" /></p>',
		esc_attr( $to )
	);
	echo '<p class="sb-note" id="sendbeam-test-who">' . esc_html__( 'Your own WordPress address. Changing it here would prove something about a different inbox.', 'sendbeam' ) . '</p>';
	printf( '<p><button type="submit" class="sb-btn sb-btn--primary">%s</button></p>', esc_html( $label ) );
	echo '</form>';
}

/**
 * It worked.
 *
 * @param array $result From the handler.
 */
function sendbeam_test_mail_success( $result ) {
	echo '<div class="sb-result sb-result--ok">';
	echo '<p class="sb-result__title"><span class="sb-tick" aria-hidden="true">&#10003;</span> ' . esc_html__( 'Sent', 'sendbeam' ) . '</p>';
	echo '<p>' . esc_html(
		sprintf(
			/* translators: %s: email address */
			__( 'Sent to %s — open your inbox to see it.', 'sendbeam' ),
			(string) $result['to']
		)
	) . '</p>';
	echo '<p class="sb-note">' . esc_html__( 'The message names the sending domain, the From address and the workspace it went through, so it is worth keeping. If it is not there in a minute, look in the spam folder — and then in the log below, which says whether SendBeam accepted it.', 'sendbeam' ) . '</p>';
	echo '</div>';

	/*
	 * Sent is not the same as sent from you. A message that went out on
	 * SendBeam's shared address while this site's own domain sits verified
	 * and unused arrived — and is not yet doing the thing the domain was set
	 * up for, which is the difference between a mailbox provider trusting it
	 * and tolerating it.
	 */
	if ( ! empty( $result['next'] ) && is_array( $result['next'] ) ) {
		$next = $result['next'];
		echo '<div class="sb-result sb-result--warn">';
		echo '<p class="sb-result__title">' . esc_html( (string) $next['title'] ) . '</p>';
		echo '<p>' . esc_html( (string) $next['text'] ) . '</p>';
		printf(
			'<p style="margin-bottom:0"><a class="sb-btn sb-btn--small sb-btn--ghost" href="%s">%s</a></p>',
			esc_url( (string) $next['url'] ),
			esc_html( (string) $next['label'] )
		);
		echo '</div>';
	}

	sendbeam_test_mail_form( __( 'Send another', 'sendbeam' ) );
}

/**
 * It did not.
 *
 * @param array $result From the handler.
 */
function sendbeam_test_mail_failure( $result ) {
	echo '<div class="sb-result sb-result--bad">';
	echo '<p class="sb-result__title">' . esc_html( (string) $result['title'] ) . '</p>';
	echo '<p><strong>' . esc_html__( 'What it means', 'sendbeam' ) . '</strong><br />' . esc_html( (string) $result['means'] ) . '</p>';
	echo '<p><strong>' . esc_html__( 'What to do', 'sendbeam' ) . '</strong><br />' . esc_html( (string) $result['do'] ) . '</p>';

	if ( ! empty( $result['error'] ) ) {
		echo '<p class="sb-note">' . esc_html(
			sprintf(
				/* translators: %s: the raw error SendBeam returned */
				__( 'SendBeam said: %s', 'sendbeam' ),
				(string) $result['error']
			)
		) . '</p>';
	}

	if ( ! empty( $result['bundle'] ) ) {
		echo '<details class="sb-paste"><summary>' . esc_html__( 'Details for support', 'sendbeam' ) . '</summary>';
		echo '<p class="sb-note">' . esc_html__( 'Taken at the moment this send failed, so it describes that attempt and not this page.', 'sendbeam' ) . '</p>';
		echo '<pre class="sb-bundle">' . esc_html( (string) $result['bundle'] ) . '</pre>';
		printf(
			'<p><button type="button" class="sb-btn sb-btn--small sb-btn--ghost sb-copy" data-copy="%1$s" data-done="%2$s">%3$s</button></p>',
			esc_attr( (string) $result['bundle'] ),
			esc_attr__( 'Copied', 'sendbeam' ),
			esc_html__( 'Copy these details', 'sendbeam' )
		);
		echo '</details>';
	}
	echo '</div>';
	sendbeam_test_mail_form( __( 'Try again', 'sendbeam' ) );
}

/**
 * A card whose body is the screen's list table.
 *
 * One function because all three are the same shape: a heading, a sentence,
 * the search box, and core's table. The table itself was prepared on
 * `load-<hook>` — before any of this ran — so a bulk action has already been
 * applied by the time the rows are read.
 *
 * @param string $title  Card heading.
 * @param string $note   The sentence under it.
 * @param string $search Placeholder and label for the search box.
 */
function sendbeam_list_card( $title, $note, $search ) {
	$table = function_exists( 'sendbeam_list_table' ) ? sendbeam_list_table() : null;

	sendbeam_card_open( $title );
	echo '<p style="margin-top:0">' . esc_html( $note ) . '</p>';

	if ( ! $table ) {
		echo '<p class="sb-note">' . esc_html__( 'This list cannot be shown on this version of WordPress.', 'sendbeam' ) . '</p>';
		sendbeam_card_close();
		return;
	}

	// GET, because a search is a place you can link to and come back to. The
	// hidden `page` keeps the search on this screen rather than throwing it
	// at the admin dashboard.
	echo '<form method="get">';
	printf( '<input type="hidden" name="page" value="%s" />', esc_attr( sendbeam_current_page() ) );
	$table->search_box( $search, 'sendbeam-search' );
	echo '</form>';

	// POST, for the bulk actions and their nonce. Core prints both inside
	// display(); the form around it is ours to provide.
	echo '<form method="post">';
	printf( '<input type="hidden" name="page" value="%s" />', esc_attr( sendbeam_current_page() ) );
	$table->display();
	echo '</form>';

	sendbeam_card_close();
}

/* ------------------------------------------------------------ E-commerce */

/**
 * E-commerce events tab: the three toggles, the abandoned-cart window, and
 * a log of recent attempts — the same shape as the Audience screen's sync log.
 */
function sendbeam_screen_ecommerce() {
	$settings = sendbeam_ecommerce_settings();

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
		'<p><label class="sb-toggle"><input type="checkbox" name="sendbeam_ecommerce[order_placed]" value="1" %1$s /> <span>%2$s</span></label></p>',
		checked( ! empty( $settings['order_placed'] ), true, false ),
		esc_html__( 'Order placed — reliable, fires from WooCommerce\'s own order-processed hook. Also adds the order total to the contact\'s lifetime_value.', 'sendbeam' )
	);
	printf(
		'<p><label class="sb-toggle"><input type="checkbox" name="sendbeam_ecommerce[product_viewed]" value="1" %1$s /> <span>%2$s</span></label></p>',
		checked( ! empty( $settings['product_viewed'] ), true, false ),
		esc_html__( 'Product viewed — only for a known contact (logged in, or an email already entered this visit). No anonymous visitor tracking.', 'sendbeam' )
	);
	printf(
		'<p><label class="sb-toggle"><input type="checkbox" name="sendbeam_ecommerce[cart_abandoned]" value="1" %1$s /> <span>%2$s</span></label></p>',
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

	echo '<p style="margin-top:10px"><button type="submit" class="sb-btn sb-btn--primary">' . esc_html__( 'Save', 'sendbeam' ) . '</button></p>';
	echo '</form>';
	sendbeam_card_close();

	$log = get_option( SENDBEAM_ECOMMERCE_LOG, array() );
	if ( is_array( $log ) && $log ) {
		sendbeam_card_open( __( 'Recent e-commerce events', 'sendbeam' ) );
		echo '<div class="sb-scroll"><table class="sb-table"><thead><tr>';
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
		echo '</tbody></table></div>';
		sendbeam_card_close();
	}
}

/* -------------------------------------------------------------- Settings */

/**
 * Which Settings tab is showing.
 *
 * @return string
 */
function sendbeam_settings_tab() {
	$tabs = array_keys( sendbeam_settings_tabs() );
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation.
	$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
	return in_array( $tab, $tabs, true ) ? $tab : 'connection';
}

/**
 * The three things this page is for.
 *
 * @return array<string,string>
 */
function sendbeam_settings_tabs() {
	return array(
		'connection' => __( 'Connection', 'sendbeam' ),
		'domain'     => __( 'Sending domain', 'sendbeam' ),
		'advanced'   => __( 'Advanced', 'sendbeam' ),
	);
}

/**
 * Settings: the connection itself, the domain it sends from, and the key.
 *
 * Everything on this page is about the account rather than about the site —
 * which is why the forms, the pop-ups and the site email settings are not
 * here. A settings page that holds every setting in the plugin is a settings
 * page nobody can find anything on.
 */
function sendbeam_screen_settings() {
	$tab       = sendbeam_settings_tab();
	$connected = sendbeam_is_connected();
	$status    = $connected ? sendbeam_connect_status() : sendbeam_connect_empty_status();

	sendbeam_render_tabs( sendbeam_settings_tabs(), $tab, 'sendbeam-settings', __( 'Settings sections', 'sendbeam' ) );

	if ( 'domain' === $tab ) {
		sendbeam_settings_domain_card( $status, $connected );
		return;
	}
	if ( 'advanced' === $tab ) {
		sendbeam_settings_advanced_card();
		return;
	}
	sendbeam_settings_connection_card( $status, $connected );
}

/**
 * Settings → Connection: one card, in the shape every connect screen has.
 *
 * @param array $status    Normalised Connect status.
 * @param bool  $connected Whether the key works.
 */
function sendbeam_settings_connection_card( $status, $connected ) {
	sendbeam_card_open( __( 'Connection', 'sendbeam' ), __( 'This site and the SendBeam workspace it talks to.', 'sendbeam' ) );
	if ( $connected ) {
		sendbeam_connect_connected_panel( $status );
	} else {
		sendbeam_connect_panel();
	}
	sendbeam_card_close();

	/*
	 * Changing the key is a settings job, not something to put on a card
	 * whose answer is "yes, connected". It lives one tab across, and this is
	 * the line that says so.
	 */
	if ( $connected && ! sendbeam_key_in_config() ) {
		printf(
			'<p class="sb-note"><a href="%s">%s</a></p>',
			esc_url( add_query_arg( 'tab', 'advanced', sendbeam_page_url( 'sendbeam-settings' ) ) ),
			esc_html__( 'Replace the API key', 'sendbeam' )
		);
	}
}

/**
 * Settings → Sending domain: the records and the two ways to finish them.
 *
 * @param array $status    Normalised Connect status.
 * @param bool  $connected Whether the key works.
 */
function sendbeam_settings_domain_card( $status, $connected ) {
	sendbeam_card_open( __( 'Sending domain', 'sendbeam' ), __( 'The domain this site\'s email is sent from.', 'sendbeam' ) );
	if ( ! $connected ) {
		echo '<p>' . esc_html__( 'Connect this site first and SendBeam will set the domain up and show you the records to add.', 'sendbeam' ) . '</p>';
		printf(
			'<p><a class="sb-btn" href="%s">%s</a></p>',
			esc_url( add_query_arg( 'tab', 'connection', sendbeam_page_url( 'sendbeam-settings' ) ) ),
			esc_html__( 'Go to Connection', 'sendbeam' )
		);
		sendbeam_card_close();
		return;
	}
	sendbeam_domain_panel( $status, true );
	sendbeam_card_close();
}

/**
 * Settings → Advanced: the key, where it can live, and what uninstalling takes with it.
 */
function sendbeam_settings_advanced_card() {
	sendbeam_card_open( __( 'API key', 'sendbeam' ), __( 'For a key you already have, or one you would rather keep out of the database.', 'sendbeam' ) );
	if ( sendbeam_key_in_config() ) {
		echo '<p>' . esc_html__( 'This site sends with the key defined as SENDBEAM_API_KEY in wp-config.php. It wins over anything saved here, so there is nothing to paste: change it in wp-config.php, or remove that line to manage the key from this screen.', 'sendbeam' ) . '</p>';
	} else {
		sendbeam_form_open( 'connect' );
		do_settings_sections( 'sendbeam_connect_page' );
		sendbeam_form_close();
	}
	sendbeam_card_close();

	sendbeam_card_open( __( 'When this plugin is deleted', 'sendbeam' ) );
	echo '<ul class="sb-bullets">';
	echo '<li>' . esc_html__( 'Everything this plugin stored on this site is removed: the settings, the saved key, the pop-up rules, the recent email and event logs, and the cached answers from SendBeam.', 'sendbeam' ) . '</li>';
	echo '<li>' . esc_html__( 'Nothing is removed from your SendBeam workspace. Your lists, forms, subscribers and sending domain stay exactly as they are.', 'sendbeam' ) . '</li>';
	echo '<li>' . esc_html__( 'Deactivating changes nothing at either end. Only deleting the plugin clears this site\'s settings.', 'sendbeam' ) . '</li>';
	echo '</ul>';
	sendbeam_card_close();
}

/* ------------------------------------------------------------------ Help */

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
 * Help: the documentation, the state of this site, and who to ask.
 */
function sendbeam_screen_help() {
	sendbeam_help_documentation_card();
	sendbeam_help_setup_again_card();
	sendbeam_help_status_card();
	sendbeam_help_support_card();
	sendbeam_help_shortcodes_card();
	sendbeam_help_permissions_card();
	sendbeam_help_developers_card();
}

/**
 * The way back into the four-step guide.
 *
 * Every plugin in the field study that has a wizard keeps one reachable, and
 * for the same two reasons: the first run is the quickest way to check a
 * change, and somebody setting up their second site wants the same
 * walk-through on it. It resets where this person got to and nothing else.
 */
function sendbeam_help_setup_again_card() {
	sendbeam_card_open( __( 'Set up SendBeam again', 'sendbeam' ) );
	echo '<p style="margin-top:0">' . esc_html__( 'The four-step guide a new site sees once: connect, verify the sending domain, switch on site email, place a form. Nothing is undone by opening it — it walks through what is already set up and shows you what is not.', 'sendbeam' ) . '</p>';
	printf(
		'<p style="margin-bottom:0"><a class="sb-btn sb-btn--ghost" href="%s">%s</a></p>',
		esc_url( sendbeam_wizard_restart_url() ),
		esc_html__( 'Run the setup guide again', 'sendbeam' )
	);
	sendbeam_card_close();
}

/**
 * What this site is doing, in one block somebody can hand to support.
 *
 * The same values Site Health reports, because a person who has a problem
 * should not have to know that Site Health exists, or find the SendBeam
 * section inside it, before they can describe their site.
 */
function sendbeam_help_status_card() {
	$report = sendbeam_status_report();

	sendbeam_card_open( __( 'System status', 'sendbeam' ), __( 'Nothing here is secret.', 'sendbeam' ) );
	echo '<p style="margin-top:0">' . esc_html__( 'What this site holds about its SendBeam connection. The API key is reported only as where it is kept and its last four characters, so this is safe to paste into an email.', 'sendbeam' ) . '</p>';
	echo '<pre class="sb-bundle">' . esc_html( $report ) . '</pre>';
	printf(
		'<p><button type="button" class="sb-btn sb-copy" data-copy="%1$s" data-done="%2$s">%3$s</button> ' .
		'<a class="sb-btn sb-btn--ghost" href="%4$s">%5$s</a></p>',
		esc_attr( $report ),
		esc_attr__( 'Copied', 'sendbeam' ),
		esc_html__( 'Copy for support', 'sendbeam' ),
		esc_url( admin_url( 'site-health.php?tab=debug' ) ),
		esc_html__( 'Open Site Health', 'sendbeam' )
	);
	sendbeam_card_close();
}

/**
 * Who to ask, and where.
 */
function sendbeam_help_support_card() {
	sendbeam_card_open( __( 'Support', 'sendbeam' ) );
	echo '<p style="margin-top:0">' . esc_html__( 'A real person reads these. Paste the system status above into whichever you use — it answers the first four questions anybody would ask.', 'sendbeam' ) . '</p>';
	echo '<ul class="sb-bullets">';
	printf(
		'<li><a href="mailto:%1$s">%1$s</a> — %2$s</li>',
		'hello@sendbeam.io',
		esc_html__( 'anything about your account, your sending domain or a message that did not arrive', 'sendbeam' )
	);
	printf(
		'<li><a href="%1$s" target="_blank" rel="noopener">%2$s</a> — %3$s</li>',
		esc_url( 'https://wordpress.org/support/plugin/sendbeam/' ),
		esc_html__( 'the WordPress.org support forum', 'sendbeam' ),
		esc_html__( 'for questions about the plugin itself, in public, where the next person with the same question can find the answer', 'sendbeam' )
	);
	printf(
		'<li><a href="%1$s" target="_blank" rel="noopener">%2$s</a> — %3$s</li>',
		esc_url( 'https://github.com/sendbeam-io/sendbeam-wordpress/issues' ),
		esc_html__( 'GitHub issues', 'sendbeam' ),
		esc_html__( 'for a bug with a reproduction, or a feature request', 'sendbeam' )
	);
	echo '</ul>';
	sendbeam_card_close();
}

/**
 * The documentation links.
 */
function sendbeam_help_documentation_card() {
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
}

/**
 * The four shortcodes.
 */
function sendbeam_help_shortcodes_card() {
	sendbeam_card_open( __( 'Shortcodes', 'sendbeam' ), __( 'Anywhere shortcodes work: a block, a widget, a page builder.', 'sendbeam' ) );
	echo '<div class="sb-doc">';
	sendbeam_doc_snippet( '[sendbeam_form]', __( 'The default signup form', 'sendbeam' ) );
	sendbeam_doc_snippet( '[sendbeam_form id="8f3c1a2e-…"]', __( 'Any form, as often as you like', 'sendbeam' ) );
	sendbeam_doc_snippet( '[sendbeam_contact]', __( 'The contact form chosen under Forms', 'sendbeam' ) );
	sendbeam_doc_snippet( '[sendbeam_popup_button label="Subscribe"]', __( 'A button that opens a pop-up', 'sendbeam' ) );
	echo '<p class="sb-note" style="margin-top:12px">' . esc_html__( 'A height attribute works on all of them, but you rarely want one: an embedded form measures itself and the frame follows.', 'sendbeam' ) . '</p>';
	echo '</div>';
	sendbeam_card_close();
}

/**
 * What each API key permission is for.
 */
function sendbeam_help_permissions_card() {
	sendbeam_card_open( __( 'API key permissions', 'sendbeam' ), __( 'Only what you use.', 'sendbeam' ) );
	echo '<div class="sb-doc">';
	echo '<p>' . esc_html__( 'Placing a form or a pop-up needs no key at all. Everything else asks for one thing:', 'sendbeam' ) . '</p>';
	echo '<div class="sb-scroll"><table class="sb-table"><thead><tr><th>' . esc_html__( 'Permission', 'sendbeam' ) . '</th><th>' . esc_html__( 'Needed for', 'sendbeam' ) . '</th></tr></thead><tbody>';
	foreach ( array(
		'forms:read'                                 => __( 'Listing your forms here and in the block', 'sendbeam' ),
		'lists:read'                                 => __( 'The Audience screen and its counts', 'sendbeam' ),
		'contacts:read, contacts:write, lists:write' => __( 'The opt-in box at registration, comments or checkout, and forms from Contact Form 7, Elementor Pro, WPForms, Gravity Forms and Fluent Forms', 'sendbeam' ),
		'tags:read, tags:write'                      => __( 'A form plugin form that adds a tag', 'sendbeam' ),
		'transactional:send'                         => __( 'Site email', 'sendbeam' ),
		'ecommerce:write'                            => __( 'E-commerce events: Cart Abandoned, Product Viewed, Order Placed', 'sendbeam' ),
	) as $perm => $why ) {
		printf( '<tr><td><code>%s</code></td><td>%s</td></tr>', esc_html( $perm ), esc_html( $why ) );
	}
	echo '</tbody></table></div>';
	printf(
		'<p class="sb-note" style="margin-top:12px">%s</p>',
		esc_html__( 'Give each site its own key so one can be revoked without disturbing the others. Defining SENDBEAM_API_KEY in wp-config.php keeps the key out of the database entirely.', 'sendbeam' )
	);
	echo '</div>';
	sendbeam_card_close();
}

/**
 * The two hooks and where the source is.
 */
function sendbeam_help_developers_card() {
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
