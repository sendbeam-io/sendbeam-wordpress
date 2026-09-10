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
	$settings = sendbeam_settings();
	$forms    = sendbeam_remote_forms();
	$lists    = sendbeam_lists();

	// Asked for directly, not summed from the lists: one person on three lists
	// is one subscriber, and adding the lists up counted them three times.
	$subscribers = sendbeam_subscriber_count();

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
	echo '<p class="sb-note" style="margin-top:12px">' . esc_html__( 'Subscribers counts people, not list memberships — someone on three lists is one subscriber. Figures come from your SendBeam workspace and are cached for five minutes.', 'sendbeam' ) . '</p>';
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
		'contacts:read, contacts:write, lists:write' => __( 'The opt-in box at registration, comments or checkout', 'sendbeam' ),
		'transactional:send'                         => __( 'Site email', 'sendbeam' ),
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
