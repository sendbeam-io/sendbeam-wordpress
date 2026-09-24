<?php
/**
 * The admin menu and the one router behind it.
 *
 * SendBeam used to live at Settings → SendBeam with seven tabs that were
 * invisible from the admin menu: to find the pop-up settings you had to know
 * they existed, find the plugin under Settings, and then find the tab. A
 * top-level menu with a real submenu item per section is what every
 * established plugin of this kind does, and it is the only way the sections
 * are discoverable at all.
 *
 * Every page is registered against the same callback. The router below reads
 * `page` and calls the screen, so adding a section is one row in
 * sendbeam_pages() rather than a new callback, a new capability check and a
 * new copy of the page shell.
 *
 * @package SendBeam
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', 'sendbeam_admin_menu' );
add_action( 'admin_init', 'sendbeam_redirect_old_urls', 1 );

/** The slug of the top-level page, which is also the Overview. */
const SENDBEAM_MENU_SLUG = 'sendbeam';

/**
 * Every SendBeam admin page: its slug, its menu label, its heading and what renders it.
 *
 * `hidden` pages are routable and titled but never appear in the menu — the
 * setup wizard is reached from a redirect and from the Overview, not from a
 * menu item somebody would press a second time.
 *
 * @return array<string,array{menu:string,title:string,render:string,hidden?:bool}>
 */
function sendbeam_pages() {
	return array(
		'sendbeam'           => array(
			'menu'   => __( 'Overview', 'sendbeam' ),
			'title'  => __( 'Overview', 'sendbeam' ),
			'render' => 'sendbeam_screen_overview',
		),
		'sendbeam-forms'     => array(
			'menu'   => __( 'Forms', 'sendbeam' ),
			'title'  => __( 'Forms', 'sendbeam' ),
			'render' => 'sendbeam_screen_forms',
			'list'   => array( 'SendBeam_Forms_Table', 'sendbeam_forms_per_page', __( 'Forms per page', 'sendbeam' ) ),
		),
		'sendbeam-popups'    => array(
			'menu'   => __( 'Pop-ups', 'sendbeam' ),
			'title'  => __( 'Pop-ups', 'sendbeam' ),
			'render' => 'sendbeam_screen_popup',
		),
		'sendbeam-audience'  => array(
			'menu'   => __( 'Audience', 'sendbeam' ),
			'title'  => __( 'Audience', 'sendbeam' ),
			'render' => 'sendbeam_screen_audience',
			'list'   => array( 'SendBeam_Lists_Table', 'sendbeam_lists_per_page', __( 'Lists per page', 'sendbeam' ) ),
		),
		'sendbeam-mail'      => array(
			'menu'   => __( 'Site email', 'sendbeam' ),
			'title'  => __( 'Site email', 'sendbeam' ),
			'render' => 'sendbeam_screen_mail',
			'list'   => array( 'SendBeam_Mail_Log_Table', 'sendbeam_mail_log_per_page', __( 'Messages per page', 'sendbeam' ) ),
		),
		'sendbeam-ecommerce' => array(
			'menu'   => __( 'E-commerce', 'sendbeam' ),
			'title'  => __( 'E-commerce events', 'sendbeam' ),
			'render' => 'sendbeam_screen_ecommerce',
		),
		'sendbeam-settings'  => array(
			'menu'   => __( 'Settings', 'sendbeam' ),
			'title'  => __( 'Settings', 'sendbeam' ),
			'render' => 'sendbeam_screen_settings',
		),
		'sendbeam-help'      => array(
			'menu'   => __( 'Help', 'sendbeam' ),
			'title'  => __( 'Help', 'sendbeam' ),
			'render' => 'sendbeam_screen_help',
		),
		'sendbeam-setup'     => array(
			'menu'   => __( 'Set up SendBeam', 'sendbeam' ),
			'title'  => __( 'Set up SendBeam', 'sendbeam' ),
			'render' => 'sendbeam_screen_wizard',
			'hidden' => true,
		),
	);
}

/**
 * The menu mark: the three squares, in the grey wp-admin gives every other icon.
 *
 * A data URI rather than a dashicon, because none of the 300-odd dashicons is
 * this product and a borrowed envelope is worse than a shape people already
 * recognise from sendbeam.io. WordPress paints menu icons by swapping the
 * whole image, so the colour is baked in at the one value core uses for a
 * menu at rest; the active state is handled by opacity.
 *
 * @return string
 */
function sendbeam_menu_icon() {
	$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">'
		. '<rect x="0.5" y="7.5" width="5" height="5" fill="#a7aaad"/>'
		. '<rect x="7.5" y="7.5" width="5" height="5" fill="#a7aaad"/>'
		. '<rect x="14.5" y="7.5" width="5" height="5" fill="#a7aaad"/>'
		. '</svg>';
	return 'data:image/svg+xml;base64,' . base64_encode( $svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- add_menu_page() takes the icon as a base64 data URI; this is not obfuscation.
}

/**
 * Register the top-level menu and one submenu per section.
 */
function sendbeam_admin_menu() {
	$pages = sendbeam_pages();

	add_menu_page(
		__( 'SendBeam', 'sendbeam' ),
		__( 'SendBeam', 'sendbeam' ),
		'manage_options',
		SENDBEAM_MENU_SLUG,
		'sendbeam_render_admin_page',
		sendbeam_menu_icon(),
		58.9
	);

	foreach ( $pages as $slug => $page ) {
		if ( ! empty( $page['hidden'] ) ) {
			/*
			 * `options.php` as the parent is the documented way to register a
			 * routable page that appears in no menu, now that passing null is
			 * deprecated. An empty string works for the routing and then
			 * breaks the title: `get_admin_page_parent()` returns '',
			 * `get_admin_page_title()` takes its no-parent branch, finds
			 * nothing, and WordPress hands a null to strip_tags() — a
			 * deprecation notice on PHP 8 and a browser tab that reads
			 * "— WordPress" with nothing in front of it.
			 */
			$hook = add_submenu_page( 'options.php', $page['title'], $page['menu'], 'manage_options', $slug, 'sendbeam_render_admin_page' );
		} else {
			$hook = add_submenu_page(
				SENDBEAM_MENU_SLUG,
				$page['title'],
				$page['menu'],
				'manage_options',
				$slug,
				'sendbeam_render_admin_page'
			);
		}
		if ( ! $hook ) {
			continue;
		}
		if ( ! empty( $page['list'] ) ) {
			add_action( 'load-' . $hook, 'sendbeam_load_list_screen' );
		}
	}
}

/**
 * Prepare a screen that has a list on it, before anything is rendered.
 *
 * Three things have to happen here rather than in the screen function:
 * Screen Options is built before the page body, a bulk action has to be acted
 * on before the rows are read, and `WP_List_Table` wants constructing while
 * `get_current_screen()` is the screen it belongs to.
 */
function sendbeam_load_list_screen() {
	$pages = sendbeam_pages();
	$slug  = sendbeam_current_page();
	if ( empty( $pages[ $slug ]['list'] ) || ! sendbeam_load_list_table() ) {
		return;
	}
	list( $class, $option, $label ) = $pages[ $slug ]['list'];

	sendbeam_add_per_page_option( $option, $label );

	$GLOBALS['sendbeam_list_table'] = new $class();
	$GLOBALS['sendbeam_list_table']->process_bulk_action();
	$GLOBALS['sendbeam_list_table']->prepare_items();
}

/**
 * The list table this screen prepared, if it has one.
 *
 * @return WP_List_Table|null
 */
function sendbeam_list_table() {
	return isset( $GLOBALS['sendbeam_list_table'] ) ? $GLOBALS['sendbeam_list_table'] : null;
}

/**
 * Which SendBeam page is being rendered.
 *
 * @return string A slug from sendbeam_pages(), defaulting to the Overview.
 */
function sendbeam_current_page() {
	$pages = sendbeam_pages();
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation.
	$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
	return isset( $pages[ $page ] ) ? $page : SENDBEAM_MENU_SLUG;
}

/**
 * The URL of one SendBeam page.
 *
 * @param string $slug Page slug.
 * @return string
 */
function sendbeam_page_url( $slug ) {
	return admin_url( 'admin.php?page=' . rawurlencode( $slug ) );
}

/**
 * Is this admin screen one of ours?
 *
 * Screen ids are `toplevel_page_sendbeam` for the Overview and
 * `sendbeam_page_<slug>` for every submenu — plus, on a translated site,
 * `<sanitised menu title>_page_<slug>`, which is why the slug rather than the
 * prefix is what this matches on.
 *
 * @param string|WP_Screen|null $screen A screen, a hook suffix, or null for the current screen.
 * @return bool
 */
function sendbeam_is_our_screen( $screen = null ) {
	if ( null === $screen ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	}
	$id = is_object( $screen ) && isset( $screen->id ) ? (string) $screen->id : (string) $screen;
	if ( '' === $id ) {
		return false;
	}
	foreach ( array_keys( sendbeam_pages() ) as $slug ) {
		$suffix = '_page_' . $slug;
		if ( substr( $id, -strlen( $suffix ) ) === $suffix ) {
			return true;
		}
	}
	return false;
}

/**
 * The one router.
 *
 * Every page registered above points here; this decides which screen function
 * runs and wraps it in the shell they all share.
 */
function sendbeam_render_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$slug  = sendbeam_current_page();
	$pages = sendbeam_pages();
	$page  = $pages[ $slug ];

	// The wizard supplies its own shell: it has a progress rail where the
	// other screens have a section name, and no card grid at all.
	if ( 'sendbeam-setup' === $slug ) {
		call_user_func( $page['render'] );
		return;
	}

	?>
	<div class="wrap sendbeam-app">
		<h1 class="screen-reader-text"><?php echo esc_html( sprintf( /* translators: %s: the section name, e.g. Site email */ __( 'SendBeam — %s', 'sendbeam' ), $page['title'] ) ); ?></h1>
		<?php sendbeam_render_header( $page['title'] ); ?>
		<div class="sb-wrap">
			<?php
			settings_errors( 'sendbeam_settings' );
			call_user_func( $page['render'] );
			?>
		</div>
	</div>
	<?php
}

/**
 * Old bookmarks still work: `options-general.php?page=sendbeam&tab=X` → the new page.
 *
 * A 301 rather than a 302 because the move is permanent, and because anything
 * that remembers URLs — a browser, a monitoring check, a link in a support
 * reply from last year — should learn the new one rather than come back
 * through here for ever. The tab is preserved, so a link to the pop-up
 * settings still lands on the pop-up settings.
 */
function sendbeam_redirect_old_urls() {
	if ( ! isset( $GLOBALS['pagenow'] ) || 'options-general.php' !== $GLOBALS['pagenow'] ) {
		return;
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation; nothing is changed by arriving here.
	$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
	if ( SENDBEAM_MENU_SLUG !== $page ) {
		return;
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- as above.
	$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';

	$target = sendbeam_tab_url( $tab );

	/*
	 * Query arguments the old URL carried are the notices the handlers set
	 * ("sendbeam_test=ok", "sb_dc=done"). Dropping them on the way through
	 * would turn every redirect a handler issues into a silent one, so
	 * everything but `page` and `tab` is carried across.
	 */
	$carry = array();
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- carried verbatim to the new URL, read by the same code that set them.
	foreach ( (array) wp_unslash( $_GET ) as $key => $value ) {
		if ( in_array( $key, array( 'page', 'tab' ), true ) || ! is_scalar( $value ) ) {
			continue;
		}
		// add_query_arg() does not encode what it is given, so anything with
		// an ampersand or a space in it would split the URL in two.
		$carry[ sanitize_key( $key ) ] = rawurlencode( sanitize_text_field( (string) $value ) );
	}
	if ( $carry ) {
		$target = add_query_arg( $carry, $target );
	}

	wp_safe_redirect( $target, 301 );
	exit;
}
