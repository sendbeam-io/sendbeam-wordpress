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
register_deactivation_hook( SENDBEAM_FILE, 'sendbeam_on_deactivate' );
add_action( 'admin_notices', 'sendbeam_setup_notice' );
add_action( 'admin_post_sendbeam_dismiss_setup', 'sendbeam_dismiss_setup' );
add_action( 'admin_post_sendbeam_form_placed', 'sendbeam_handle_form_placed' );

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
 * Switched off: stop the clock.
 *
 * A deactivated plugin that leaves a scheduled event behind is a site making
 * requests nobody can see, explain or stop from the screen they turned it off
 * on. The hourly DNS re-check is the only one Connect owns.
 */
function sendbeam_on_deactivate() {
	sendbeam_connect_unschedule_recheck();
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
 * Is a SendBeam form actually on the site?
 *
 * "A form is chosen in the settings" and "a visitor can see a form" are not
 * the same claim, and the checklist was making the first one while sounding
 * like the second.
 *
 * @param bool $force Skip the cache.
 * @return bool
 */
function sendbeam_form_is_placed( $force = false ) {
	return '' !== sendbeam_form_placement( $force );
}

/**
 * Where the form was found, or an empty string when it was not.
 *
 * The first version of this read published `post_content` and nothing else,
 * which is one of perhaps six places a form actually ends up. A site running
 * a block theme keeps its footer in `wp_template_part`; a classic theme keeps
 * it in a block widget; every page builder worth the name stores its layout
 * in post meta rather than in the post. All three tick nothing, and the owner
 * is left looking at an unfinished checklist beside a working form.
 *
 * So all of them are looked at — and, because no list of six will ever be
 * seven, the owner can simply say so and be believed.
 *
 * One query family, answered once and cached for half a day: this drives one
 * tick on one settings screen and is not worth a query per page load.
 *
 * @param bool $force Skip the cache.
 * @return string One of manual, popup, content, template, widget, meta; empty
 *                when no form could be found anywhere.
 */
function sendbeam_form_placement( $force = false ) {
	if ( get_option( 'sendbeam_form_placed' ) ) {
		return 'manual';
	}
	if ( sendbeam_popups() ) {
		return 'popup';
	}

	if ( ! $force ) {
		$cached = get_transient( 'sendbeam_form_placed' );
		if ( is_string( $cached ) && '' !== $cached ) {
			return 'no' === $cached ? '' : $cached;
		}
	}

	$found = sendbeam_find_form_placement();
	set_transient( 'sendbeam_form_placed', '' === $found ? 'no' : $found, 12 * HOUR_IN_SECONDS );
	return $found;
}

/**
 * The needles: the block, both shortcodes, and the form's own hosted URL.
 *
 * The URL is what a page builder or an HTML block holding an iframe contains,
 * and it is the only one of the four that names a particular form.
 *
 * @return string[]
 */
function sendbeam_form_needles() {
	$needles  = array( 'wp:sendbeam/form', '[sendbeam_form', '[sendbeam_contact' );
	$settings = sendbeam_settings();
	foreach ( array( 'default_form', 'contact_form' ) as $key ) {
		$id = (string) $settings[ $key ];
		if ( sendbeam_is_form_id( $id ) ) {
			$needles[] = sendbeam_app_url() . '/f/' . strtolower( $id );
		}
	}
	return $needles;
}

/**
 * Look in all six places, cheapest first.
 *
 * @return string
 */
function sendbeam_find_form_placement() {
	$needles = sendbeam_form_needles();

	$widget = sendbeam_form_in_widgets( $needles );
	if ( '' !== $widget ) {
		return $widget;
	}

	global $wpdb;
	if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) ) {
		// No database to ask (the smoke tests, WP-CLI oddities): fall back to
		// what the settings say rather than claiming the step is unfinished.
		$settings = sendbeam_settings();
		return ( '' !== $settings['default_form'] || '' !== $settings['contact_form'] ) ? 'content' : '';
	}

	$like = array();
	foreach ( $needles as $needle ) {
		$like[] = '%' . $wpdb->esc_like( $needle ) . '%';
	}
	$content_or = implode( ' OR ', array_fill( 0, count( $like ), 'post_content LIKE %s' ) );

	// Published content, in post types a visitor can actually reach.
	$types = get_post_types( array( 'public' => true ), 'names' );
	$types = array_values( array_filter( array_map( 'strval', (array) $types ) ) );
	if ( ! $types ) {
		$types = array( 'post', 'page' );
	}
	$type_in = implode( ', ', array_fill( 0, count( $types ), '%s' ) );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- cached in a transient by the caller; the two interpolated fragments are runs of %s placeholders built here, and every value goes through prepare().
	if ( $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ( $type_in ) AND ( $content_or ) LIMIT 1", array_merge( $types, $like ) ) ) ) {
		return 'content';
	}

	// A block theme keeps the header, the footer and every page layout here.
	// Templates are not "published" in the way a page is, so status is not
	// part of the question.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- as above.
	if ( $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ( 'wp_template', 'wp_template_part' ) AND post_status != 'trash' AND ( $content_or ) LIMIT 1", $like ) ) ) {
		return 'template';
	}

	// Page builders keep their layout beside the post, not in it.
	$meta_or = implode( ' OR ', array_fill( 0, count( $like ), 'meta_value LIKE %s' ) );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- as above.
	if ( $wpdb->get_var( $wpdb->prepare( "SELECT meta_id FROM {$wpdb->postmeta} WHERE $meta_or LIMIT 1", $like ) ) ) {
		return 'meta';
	}

	return '';
}

/**
 * Block widgets, and the text widgets a sidebar actually holds.
 *
 * Widgets live in options rather than in posts, so no amount of looking at
 * `post_content` will ever find one. A text widget that is in `widget_text`
 * but in no sidebar is not on the site — it is a leftover — so the sidebars
 * decide which of them count.
 *
 * @param string[] $needles What a placed form looks like.
 * @return string 'widget', or empty.
 */
function sendbeam_form_in_widgets( $needles ) {
	$haystacks = array();

	$blocks = get_option( 'widget_block' );
	if ( is_array( $blocks ) ) {
		foreach ( $blocks as $block ) {
			if ( is_array( $block ) && isset( $block['content'] ) ) {
				$haystacks[] = (string) $block['content'];
			}
		}
	}

	$sidebars = get_option( 'sidebars_widgets' );
	$text     = get_option( 'widget_text' );
	if ( is_array( $sidebars ) && is_array( $text ) ) {
		foreach ( $sidebars as $key => $ids ) {
			if ( 'wp_inactive_widgets' === $key || ! is_array( $ids ) ) {
				continue;
			}
			foreach ( $ids as $id ) {
				if ( ! is_string( $id ) || 0 !== strpos( $id, 'text-' ) ) {
					continue;
				}
				$number = (int) substr( $id, 5 );
				if ( isset( $text[ $number ]['text'] ) ) {
					$haystacks[] = (string) $text[ $number ]['text'];
				}
			}
		}
	}

	foreach ( $haystacks as $haystack ) {
		foreach ( $needles as $needle ) {
			if ( false !== strpos( $haystack, $needle ) ) {
				return 'widget';
			}
		}
	}
	return '';
}

/**
 * What step 1 says.
 *
 * A key pinned in wp-config.php is the one this site sends with, so telling
 * its owner to press a button that is deliberately not on the screen — and
 * which would store a second key nothing would use — is the wrong
 * instruction twice over.
 *
 * @param bool $connected Whether the key works.
 * @return string
 */
function sendbeam_connect_step_sentence( $connected ) {
	if ( $connected ) {
		return __( 'Your key works.', 'sendbeam' );
	}
	if ( sendbeam_key_in_config() ) {
		return __( 'This site\'s key is the one in wp-config.php, and SendBeam is not answering to it. Change it there.', 'sendbeam' );
	}
	return __( 'Press Connect SendBeam, approve what this site may do, and the key arrives on its own.', 'sendbeam' );
}

/**
 * What step 2 says once there really is a sending domain.
 *
 * The name is always the one the answer carried, never one derived from
 * site_url: the owner chooses the sending host at consent time and it is
 * often not this site's domain at all.
 *
 * @param string $name     The sending domain, from the response.
 * @param bool   $verified Whether SendBeam has seen its records.
 * @return string
 */
function sendbeam_domain_sentence( $name, $verified ) {
	if ( '' === (string) $name ) {
		return __( 'No sending domain is set up for this site. Add one under Settings → Domains in SendBeam.', 'sendbeam' );
	}
	if ( $verified ) {
		/* translators: %s: the sending domain, e.g. harbourlane.co.uk */
		return sprintf( __( '%s is verified. Email from this site will be sent from your own domain.', 'sendbeam' ), $name );
	}
	/* translators: %s: the sending domain, e.g. harbourlane.co.uk */
	return sprintf( __( 'Add these records to the DNS for %s, then press Check now. Until they are in place SendBeam cannot send as you.', 'sendbeam' ), $name );
}

/**
 * How far through setup are they? Drives the checklist on the settings page.
 *
 * Four steps now, not three, and the new one is second on purpose. Verifying
 * the sending domain is the step every other step quietly depends on — a form
 * that cannot send a confirmation email is a form that collects nothing, and
 * site email through an unverified domain is worse than no site email at all
 * — and it is the step a site owner cannot even start without the DNS records
 * Connect has just fetched. Burying it under "optional" was the gap the owner
 * found on the test site.
 *
 * Each step carries a `key`, and the Overview renders a panel per key. The
 * old version relied on position, which meant inserting a step in the middle
 * silently pointed every link after it at the wrong tab.
 *
 * @return array<int,array{key:string,done:bool,label:string,detail:string,target:string}>
 */
function sendbeam_setup_steps() {
	$settings   = sendbeam_settings();
	$connection = sendbeam_connection();
	$connected  = in_array( $connection['state'], array( 'ok', 'no_scope' ), true );
	$status     = $connected ? sendbeam_connect_status() : sendbeam_connect_empty_status();

	$using_form  = '' !== $settings['default_form'] || '' !== $settings['contact_form'];
	$using_popup = (bool) sendbeam_popups();
	$using_mail  = ! empty( $settings['mail_enabled'] );

	$domain   = $status['domain'];
	$verified = ! empty( $domain['verified'] );

	/*
	 * Everything step 2 says comes out of the answer SendBeam just gave, and
	 * nothing out of what this site remembers granting. The two disagree more
	 * often than they look like they would — a key pasted by hand has no
	 * recorded permissions at all, and the step used to tell those sites they
	 * had no permission to read a domain while printing that domain's DNS
	 * records directly underneath.
	 */
	$state            = $connected ? sendbeam_connect_domain_state( $status ) : '';
	$note             = isset( $status['domain_note'] ) ? trim( (string) $status['domain_note'] ) : '';
	$domain_reconnect = false;

	if ( ! $connected ) {
		$domain_detail = __( 'Connect the site first and SendBeam will set the domain up and show you the records.', 'sendbeam' );
	} elseif ( 'not_granted' === $state ) {
		$domain_detail    = __( 'You did not allow SendBeam to set up a sending domain, so this site has none.', 'sendbeam' );
		$domain_reconnect = true;
	} elseif ( 'no_permission' === $state ) {
		$domain_detail    = __( 'This connection was made before domains could be read, so this site cannot see its sending domain.', 'sendbeam' );
		$domain_reconnect = true;
	} elseif ( 'managed_host' === $state ) {
		$domain_detail = '' !== $note
			? $note
			: __( 'This site runs on a hosting company\'s own domain, so mail cannot be sent from it. Add a domain you own under Settings → Domains in SendBeam.', 'sendbeam' );
	} elseif ( 'unavailable' === $state ) {
		$domain_detail = __( 'SendBeam could not be reached; showing the last known state.', 'sendbeam' );
		if ( '' !== $domain['name'] ) {
			$domain_detail .= ' ' . sendbeam_domain_sentence( $domain['name'], $verified );
		}
	} elseif ( 'no_site' === $state || 'not_found' === $state ) {
		$domain_detail = __( 'No sending domain is set up for this site.', 'sendbeam' );
		if ( '' !== $note ) {
			$domain_detail .= ' ' . rtrim( $note, '.' ) . '.';
		}
		$domain_detail .= ' ' . __( 'Add one under Settings → Domains in SendBeam.', 'sendbeam' );
	} else {
		$domain_detail = sendbeam_domain_sentence( $domain['name'], $verified );
	}

	$where  = ( $using_form || $using_popup ) ? sendbeam_form_placement() : '';
	$placed = '' !== $where;

	if ( $placed ) {
		$found       = array(
			'manual'   => __( 'You said a SendBeam form is on the site.', 'sendbeam' ),
			'popup'    => __( 'A SendBeam pop-up is live on the site.', 'sendbeam' ),
			'content'  => __( 'A SendBeam form is on a published page or post.', 'sendbeam' ),
			'template' => __( 'A SendBeam form is in one of the theme\'s block templates.', 'sendbeam' ),
			'widget'   => __( 'A SendBeam form is in a widget.', 'sendbeam' ),
			'meta'     => __( 'A SendBeam form is in a layout stored with a page — a page builder, most likely.', 'sendbeam' ),
		);
		$form_detail = isset( $found[ $where ] ) ? $found[ $where ] : __( 'A SendBeam form is on the site.', 'sendbeam' );
	} elseif ( $using_form || $using_popup ) {
		$form_name = '' !== $status['default_form']['name'] ? $status['default_form']['name'] : __( 'Your form', 'sendbeam' );
		/* translators: %s: the form name, e.g. Newsletter signup */
		$form_detail = sprintf( __( '%s is ready — add the SendBeam Form block to a page. In the editor press the + button and search for "SendBeam", or type /sendbeam on a new line. The shortcode [sendbeam_form] does the same anywhere shortcodes work.', 'sendbeam' ), $form_name );
	} else {
		$form_detail = __( 'Choose a signup, contact or pop-up form below.', 'sendbeam' );
	}

	$mail_reconnect = false;

	if ( $using_mail ) {
		$mail_detail = __( 'On. Send yourself a test to be sure.', 'sendbeam' );
	} elseif ( $connected && ! sendbeam_connect_granted( 'transactional:send' ) ) {
		$mail_detail    = __( 'This site\'s key was not given permission to send your site\'s email. Reconnect and tick that box to use it.', 'sendbeam' );
		$mail_reconnect = true;
	} elseif ( ! $verified ) {
		$mail_detail = __( 'Waiting on step 2: this site\'s email can only go out once the sending domain is verified.', 'sendbeam' );
		/*
		 * Step 4 is waiting on step 2, and step 2 is waiting on a permission
		 * nobody ticked. Sending the owner to step 2 to read that is one hop
		 * too many when the way out is the same link either way: this step
		 * offers it as well, because this is the step they came to use.
		 */
		$mail_reconnect = $domain_reconnect;
	} else {
		$mail_detail = __( 'Ready. Switch it on and password resets, receipts and notifications go out through your own domain.', 'sendbeam' );
	}

	return array(
		array(
			'key'    => 'connect',
			'done'   => $connected,
			'label'  => __( 'Connect your SendBeam account', 'sendbeam' ),
			'detail' => sendbeam_connect_step_sentence( $connected ),
			'target' => sendbeam_tab_url( 'overview' ) . '#sendbeam_api_key',
		),
		array(
			'key'       => 'domain',
			'done'      => $verified,
			'label'     => __( 'Verify your sending domain', 'sendbeam' ),
			'detail'    => $domain_detail,
			'target'    => sendbeam_app_url() . '/settings/domains',
			'reconnect' => $domain_reconnect,
		),
		array(
			'key'    => 'form',
			'done'   => $placed,
			'label'  => __( 'Put a form on the site', 'sendbeam' ),
			'detail' => $form_detail,
			'target' => sendbeam_tab_url( 'forms' ),
			'where'  => $where,
		),
		array(
			'key'       => 'mail',
			'done'      => $using_mail,
			'label'     => __( 'Send this site\'s email through SendBeam', 'sendbeam' ),
			'detail'    => $mail_detail,
			'target'    => sendbeam_tab_url( 'mail' ),
			'reconnect' => $mail_reconnect,
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
			<?php esc_html_e( 'Connect your account in one click and the plugin will list your forms for you, so you never have to copy a key or an ID by hand.', 'sendbeam' ); ?>
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

/**
 * "I've placed it elsewhere" — and the way back from it.
 *
 * No list of places a form can be will ever be complete: a theme that renders
 * the shortcode in PHP, a form in a plugin this one has never heard of, a
 * cached page. The owner can see their own site, so they get the last word,
 * and the step says plainly that this is what is holding the tick.
 */
function sendbeam_handle_form_placed() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'sendbeam' ) );
	}
	check_admin_referer( 'sendbeam_form_placed' );

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked immediately above.
	$on = ! isset( $_GET['state'] ) || '0' !== sanitize_text_field( wp_unslash( $_GET['state'] ) );

	if ( $on ) {
		update_option( 'sendbeam_form_placed', 1, false );
	} else {
		delete_option( 'sendbeam_form_placed' );
	}
	delete_transient( 'sendbeam_form_placed' );

	wp_safe_redirect( add_query_arg( 'sendbeam_form', $on ? 'placed' : 'unplaced', sendbeam_tab_url( 'overview' ) ) );
	exit;
}

/**
 * The URL behind that link.
 *
 * @param bool $on True to say the form is placed, false to take it back.
 * @return string
 */
function sendbeam_form_placed_url( $on = true ) {
	return wp_nonce_url(
		add_query_arg(
			array(
				'action' => 'sendbeam_form_placed',
				'state'  => $on ? '1' : '0',
			),
			admin_url( 'admin-post.php' )
		),
		'sendbeam_form_placed'
	);
}
