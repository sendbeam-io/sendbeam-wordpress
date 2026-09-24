<?php
/**
 * The three lists, as WordPress renders lists.
 *
 * The plugin used to print `<table class="sb-table">` by hand: no search, no
 * pagination, no bulk actions, no empty state, and — the part that mattered
 * most on a phone — none of core's column collapsing, so the Forms and Site
 * email screens were the two that scrolled sideways.
 *
 * `WP_List_Table` is marked `@access private` in core, which every plugin that
 * has a list ignores, because there is no public alternative and core's own
 * screens are the convention people expect. The risk is a core change to the
 * class; the mitigation is that these subclasses override only the documented
 * extension points (`get_columns`, `prepare_items`, `column_*`,
 * `get_bulk_actions`, `no_items`) and nothing else.
 *
 * @package SendBeam
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'set-screen-option', 'sendbeam_set_screen_option', 10, 3 );
add_action( 'admin_head', 'sendbeam_list_table_columns' );

/**
 * Load core's WP_List_Table, and then this plugin's three subclasses.
 *
 * Both halves have to happen here rather than at the top of the file.
 * `WP_List_Table` lives in wp-admin/includes and is not loaded when plugins
 * are, so a subclass declared at plugin-load time is a fatal error on every
 * page of the site. Requiring the subclasses only once core's class is in
 * memory is the whole of the fix — and it is why this is a function a screen
 * calls rather than three requires in sendbeam.php.
 *
 * @return bool Whether the list tables are available.
 */
function sendbeam_load_list_table() {
	if ( ! class_exists( 'WP_List_Table' ) && defined( 'ABSPATH' ) && file_exists( ABSPATH . 'wp-admin/includes/class-wp-list-table.php' ) ) {
		require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
	}
	if ( ! class_exists( 'WP_List_Table' ) ) {
		return false;
	}
	// One class per file, because that is what the WordPress standard asks
	// for and what the plugin review tooling checks.
	require_once __DIR__ . '/class-sendbeam-forms-table.php';
	require_once __DIR__ . '/class-sendbeam-mail-log-table.php';
	require_once __DIR__ . '/class-sendbeam-lists-table.php';
	return true;
}

/**
 * Keep the per-page choice the Screen Options tab offers.
 *
 * Without this filter WordPress throws the value away on save and the box
 * silently snaps back to the default, which reads as a broken control.
 *
 * @param mixed  $status Whatever an earlier filter decided.
 * @param string $option The option being saved.
 * @param mixed  $value  The value chosen.
 * @return mixed
 */
function sendbeam_set_screen_option( $status, $option, $value ) {
	if ( in_array( $option, array( 'sendbeam_forms_per_page', 'sendbeam_mail_log_per_page', 'sendbeam_lists_per_page' ), true ) ) {
		return max( 1, min( 200, (int) $value ) );
	}
	return $status;
}

/**
 * Register the per-page screen option for a list screen.
 *
 * Called from `load-<hook>`, which is the only point late enough for
 * `get_current_screen()` to exist and early enough for Screen Options to be
 * rendered with it.
 *
 * @param string $option  Option name.
 * @param string $label   Label on the Screen Options tab.
 * @param int    $default Rows per page out of the box.
 */
function sendbeam_add_per_page_option( $option, $label, $default = 20 ) {
	if ( ! function_exists( 'add_screen_option' ) ) {
		return;
	}
	add_screen_option(
		'per_page',
		array(
			'label'   => $label,
			'default' => $default,
			'option'  => $option,
		)
	);
}

/**
 * How many rows this screen shows.
 *
 * @param string $option  Option name.
 * @param int    $default Fallback.
 * @return int
 */
function sendbeam_per_page( $option, $default = 20 ) {
	$user = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
	$per  = $user ? get_user_meta( $user, $option, true ) : '';
	$per  = '' === $per ? 0 : (int) $per;
	return $per > 0 ? $per : $default;
}

/**
 * The search term for a list screen.
 *
 * @return string
 */
function sendbeam_list_search() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a search box is a GET form; core's own list tables read `s` the same way.
	return isset( $_REQUEST['s'] ) ? trim( sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) ) : '';
}

/**
 * Give core the column widths it needs to collapse a row on a phone.
 *
 * Core's responsive list table hides every column but the primary one behind
 * a toggle, and works out which is primary from `get_primary_column_name()`.
 * Nothing else is needed — this only pins the checkbox column, which core
 * otherwise lets stretch.
 */
function sendbeam_list_table_columns() {
	if ( ! sendbeam_is_our_screen() ) {
		return;
	}
	echo '<style>.sendbeam-app .wp-list-table .column-cb{width:2.2em}</style>';
}
