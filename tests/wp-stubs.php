<?php
/**
 * Just enough of WordPress to exercise the plugin's output functions
 * without a WordPress install. Each stub does what the real function does
 * for the inputs the plugin passes it.
 */
define( 'ABSPATH', '/stub/' );
$GLOBALS['stub'] = array( 'remote' => array(), 'remote_reply' => null, 'actions' => array(), 'options' => array(), 'hooks' => array(), 'shortcodes' => array(), 'cron' => array(), 'scripts' => array(), 'inline' => array(), 'printed' => '', 'caps' => array(), 'query' => array(), 'errors' => array() );

function plugin_dir_path( $f ) { return dirname( $f ) . '/'; }
function plugin_basename( $f ) { return 'sendbeam/sendbeam.php'; }
function apply_filters( $tag, $value, ...$args ) { return isset( $GLOBALS['stub']['hooks'][ $tag ] ) ? call_user_func( $GLOBALS['stub']['hooks'][ $tag ], $value, ...$args ) : $value; }
/**
 * Hooks. The last callback for a tag wins (which is all the output tests
 * need), but every callback ever registered is also kept, so a test can ask
 * the one question WordPress answers with a fatal error: does the function
 * this hook names actually exist?
 */
function add_filter( $tag, $fn, $p = 10, $a = 1 ) { $GLOBALS['stub']['hooks'][ $tag ] = $fn; $GLOBALS['stub']['callbacks'][] = array( $tag, $fn ); }
function add_action( $tag, $fn, $p = 10, $a = 1 ) { $GLOBALS['stub']['hooks'][ $tag ] = $fn; $GLOBALS['stub']['callbacks'][] = array( $tag, $fn ); }
function add_shortcode( $tag, $fn ) { $GLOBALS['stub']['shortcodes'][ $tag ] = $fn; }
function do_shortcode_tag( $tag, $atts = array() ) { return call_user_func( $GLOBALS['stub']['shortcodes'][ $tag ], $atts ); }
function untrailingslashit( $s ) { return rtrim( $s, '/' ); }
function esc_url_raw( $s ) { return $s; }
function esc_url( $s ) { return htmlspecialchars( $s, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_html__( $s, $d = null ) { return esc_html( $s ); }
function esc_html_e( $s, $d = null ) { echo esc_html( $s ); }
function __( $s, $d = null ) { return $s; }
function wp_kses( $s, $allowed ) { return $s; }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) ); }
function wp_unslash( $s ) { return is_array( $s ) ? array_map( 'wp_unslash', $s ) : stripslashes( (string) $s ); }
function get_option( $k, $default = false ) { return isset( $GLOBALS['stub']['options'][ $k ] ) ? $GLOBALS['stub']['options'][ $k ] : $default; }
/**
 * Options, and the sanitise callback WordPress runs on the way in.
 *
 * `register_setting()` hangs a callback on `sanitize_option_{$option}` and
 * `update_option()` applies it — so every write to a registered option goes
 * through it, including the ones a plugin makes on its own behalf. The stub
 * did not, which is precisely why Disconnect could write an empty API key,
 * have the form's "a blank field keeps the saved key" rule put the old one
 * back, and pass every test while leaving the site connected.
 *
 * Fixtures that only want to put a value in place use sb_seed_settings().
 */
function update_option( $k, $v ) {
	if ( isset( $GLOBALS['stub']['sanitize'][ $k ] ) && is_callable( $GLOBALS['stub']['sanitize'][ $k ] ) ) {
		$v = call_user_func( $GLOBALS['stub']['sanitize'][ $k ], $v );
	}
	$GLOBALS['stub']['options'][ $k ] = $v;
}
function register_setting( $group, $option, $args = array() ) {
	if ( is_array( $args ) && ! empty( $args['sanitize_callback'] ) ) {
		$GLOBALS['stub']['sanitize'][ $option ] = $args['sanitize_callback'];
	}
}
function add_settings_section( $id, $title, $cb, $page, $args = array() ) {}
function add_settings_field( $id, $title, $cb, $page, $section = 'default', $args = array() ) {}
/** Put a value straight into the store, the way a fixture means to. */
function sb_seed_settings( $value ) { $GLOBALS['stub']['options']['sendbeam_settings'] = $value; }
function delete_option( $k ) { unset( $GLOBALS['stub']['options'][ $k ] ); }
function add_settings_error( $s, $c, $m ) { $GLOBALS['stub']['errors'][] = $m; }
function shortcode_atts( $pairs, $atts, $tag = '' ) { $atts = (array) $atts; $out = array(); foreach ( $pairs as $k => $v ) { $out[ $k ] = array_key_exists( $k, $atts ) ? $atts[ $k ] : $v; } return $out; }
function current_user_can( $cap ) { return ! empty( $GLOBALS['stub']['caps'][ $cap ] ); }
function is_admin() { return false; }
function is_singular( $t = '' ) { return ! empty( $GLOBALS['stub']['query'][ 'singular:' . $t ] ); }
function is_page() { return ! empty( $GLOBALS['stub']['query']['page'] ); }
function is_front_page() { return ! empty( $GLOBALS['stub']['query']['front'] ); }
function wp_register_script( $h, $src, $deps, $ver, $footer ) { $GLOBALS['stub']['scripts'][ $h ] = $src; }
function wp_enqueue_script( $h ) { $GLOBALS['stub']['scripts'][ $h . ':enqueued' ] = true; }
function wp_add_inline_script( $h, $js, $pos = 'after' ) { $GLOBALS['stub']['inline'][ $h ][] = $js; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function register_block_type( $dir ) { $GLOBALS['stub']['block'] = $dir; }
function get_block_wrapper_attributes( $extra = array() ) { return 'class="wp-block-sendbeam-form ' . esc_attr( $extra['class'] ) . '"'; }
function wp_print_script_tag( $attrs ) {
	$html = '<script';
	foreach ( $attrs as $k => $v ) { $html .= true === $v ? ' ' . $k : ' ' . $k . '="' . esc_attr( $v ) . '"'; }
	$GLOBALS['stub']['printed'] .= $html . '></script>';
}

function wp_print_inline_script_tag( $js, $attrs = array() ) {
	$GLOBALS['stub']['printed'] .= '<script>' . $js . '</script>';
}

/**
 * Activation/option helpers the plugin calls at load time. WordPress always has
 * these; the smoke test loads the plugin without WordPress, so it must too, or
 * the whole file dies before a single assertion runs.
 */
function register_activation_hook( $file, $callback ) {
	$GLOBALS['stub']['activation'][] = $callback;
}
function register_deactivation_hook( $file, $callback ) {
	$GLOBALS['stub']['deactivation'][] = $callback;
}
function add_option( $name, $value = '', $deprecated = '', $autoload = 'yes' ) {
	if ( ! isset( $GLOBALS['stub']['options'][ $name ] ) ) {
		return update_option( $name, $value );
	}
	return false;
}
function register_rest_route( $ns, $route, $args = array() ) {
	$GLOBALS['stub']['rest'][ $ns . $route ] = $args;
	return true;
}
function add_query_arg( ...$a ) {
	// WordPress accepts both add_query_arg( array, url ) and
	// add_query_arg( key, value, url ); the stub must too, or a caller using
	// the three-argument form silently builds a nonsense URL.
	if ( count( $a ) >= 3 ) {
		$args = array( $a[0] => $a[1] );
		$url  = (string) $a[2];
	} else {
		$args = $a[0];
		$url  = isset( $a[1] ) ? (string) $a[1] : '';
	}
	$parts = explode( '?', $url, 2 );
	$query = array();
	if ( isset( $parts[1] ) ) { parse_str( $parts[1], $query ); }
	$query = array_merge( $query, (array) $args );
	// WordPress's build_query() does NOT urlencode values; the stub must not either,
	// or the '#' escaping bug this guards against would be invisible here.
	$pairs = array();
	foreach ( $query as $k => $v ) { $pairs[] = $k . '=' . $v; }
	return $parts[0] . ( $pairs ? '?' . implode( '&', $pairs ) : '' );
}
function get_user_meta( $user_id, $key = '', $single = false ) {
	$all = isset( $GLOBALS['stub']['usermeta'][ $user_id ] ) ? $GLOBALS['stub']['usermeta'][ $user_id ] : array();
	if ( '' === $key ) { return $all; }
	if ( ! isset( $all[ $key ] ) ) { return $single ? '' : array(); }
	return $single ? $all[ $key ] : array( $all[ $key ] );
}

/**
 * Transients. The admin API client caches through these, and the settings
 * sanitiser drops the cache whenever the key changes.
 */
function get_transient( $key ) {
	return isset( $GLOBALS['stub']['transients'][ $key ] ) ? $GLOBALS['stub']['transients'][ $key ] : false;
}
function set_transient( $key, $value, $ttl = 0 ) { $GLOBALS['stub']['transients'][ $key ] = $value; return true; }
function delete_transient( $key ) { unset( $GLOBALS['stub']['transients'][ $key ] ); return true; }

/** GET counterpart of the wp_remote_post stub, sharing the same recorder. */
function wp_remote_get( $url, $args = array() ) {
	$GLOBALS['stub']['remote'][] = array( 'url' => $url, 'args' => $args, 'method' => 'GET' );
	$r = isset( $GLOBALS['stub']['remote_reply'] ) ? $GLOBALS['stub']['remote_reply'] : array( 'response' => array( 'code' => 200 ), 'body' => '{}' );
	return ( $r instanceof Closure ) ? $r( $url, $args ) : $r;
}
function update_user_meta( $user_id, $key, $value ) { $GLOBALS['stub']['usermeta'][ $user_id ][ $key ] = $value; return true; }

// ── Site email stubs ────────────────────────────────────────────────────
class WP_Error {
	public $code; public $message; public $data;
	public function __construct( $code = '', $message = '', $data = null ) { $this->code = $code; $this->message = $message; $this->data = $data; }
	public function get_error_message() { return $this->message; }
}
function is_wp_error( $t ) { return $t instanceof WP_Error; }
function wp_remote_post( $url, $args ) { $GLOBALS['stub']['remote'][] = array( 'url' => $url, 'args' => $args, 'method' => 'POST' ); $r = $GLOBALS['stub']['remote_reply']; return ( $r instanceof Closure ) ? $r( $url, $args ) : $r; }
function wp_remote_retrieve_response_code( $r ) { return $r['response']['code']; }
function wp_remote_retrieve_body( $r ) { return $r['body']; }
function do_action( $tag, ...$args ) {
	$GLOBALS['stub']['actions'][] = array( $tag, $args );
	// The Connect pop-up fires this immediately before the request ends; the
	// test uses it as the seam where WordPress would have called exit.
	if ( ! empty( $GLOBALS['stub']['throw_on'][ $tag ] ) ) { throw new SendBeamStubExit( $tag ); }
}
function network_home_url() { return 'https://www.example-site.test/'; }
function home_url() { return 'https://www.example-site.test'; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function is_email( $e ) { return (bool) filter_var( $e, FILTER_VALIDATE_EMAIL ); }
function sanitize_email( $e ) { return trim( (string) $e ); }
function checked( $a, $b = true, $echo = true ) { return $a == $b ? ' checked="checked"' : ''; }
function get_bloginfo( $k ) {
	if ( isset( $GLOBALS['stub']['bloginfo'][ $k ] ) ) { return $GLOBALS['stub']['bloginfo'][ $k ]; }
	return 'version' === $k ? '6.7.1' : 'Example Site';
}
function selected( $a, $b, $echo = true ) { return $a == $b ? ' selected="selected"' : ''; }

// ── Connect ─────────────────────────────────────────────────────────────
/**
 * The Connect flow ends a request in two ways WordPress can and the test
 * cannot: wp_redirect()+exit on the way out, and a rendered page + exit on the
 * way back. Both are turned into exceptions the test catches, so a handler can
 * be driven to its end without killing the run.
 */
class SendBeamStubExit extends RuntimeException {}
function site_url( $path = '' ) { $base = isset( $GLOBALS['stub']['site_url'] ) ? $GLOBALS['stub']['site_url'] : 'https://www.example-site.test'; return $base . $path; }
function admin_url( $path = '' ) { return 'https://www.example-site.test/wp-admin/' . ltrim( (string) $path, '/' ); }
function wp_redirect( $url, $status = 302 ) { $GLOBALS['stub']['redirect'] = array( 'url' => $url, 'status' => $status ); throw new SendBeamStubExit( 'wp_redirect' ); }
function wp_safe_redirect( $url, $status = 302 ) { return wp_redirect( $url, $status ); }
/**
 * Faithful enough to see the status code.
 *
 * Core defaults to 500 when no response is given, which is what every one of
 * this plugin's permission refusals used to send: a server-error line in
 * every access log and every uptime monitor, for a refusal that worked
 * perfectly.
 */
function wp_die( $message = '', $title = '', $args = array() ) {
	$status                    = is_array( $args ) && isset( $args['response'] ) ? (int) $args['response'] : 500;
	$GLOBALS['stub']['died']   = array( 'message' => (string) $message, 'status' => $status );
	throw new SendBeamStubExit( 'wp_die: ' . $message );
}
function check_admin_referer( $action = -1, $name = '_wpnonce' ) {
	$nonce = isset( $_REQUEST[ $name ] ) ? $_REQUEST[ $name ] : '';
	if ( ! wp_verify_nonce( $nonce, $action ) ) { wp_die( 'bad nonce' ); }
	return 1;
}
function get_current_user_id() { return isset( $GLOBALS['stub']['user_id'] ) ? (int) $GLOBALS['stub']['user_id'] : 0; }
function wp_generate_password( $length = 12, $special_chars = true, $extra_special_chars = false ) {
	$chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
	if ( $special_chars ) { $chars .= '!@#$%^&*()'; }
	$out = '';
	for ( $i = 0; $i < $length; $i++ ) { $out .= $chars[ random_int( 0, strlen( $chars ) - 1 ) ]; }
	return $out;
}
function nocache_headers() {}

// ── Form plugin bridges ────────────────────────────────────────────────
function wp_schedule_single_event( $timestamp, $hook, $args = array() ) { $GLOBALS['stub']['cron'][] = array( 'hook' => $hook, 'args' => $args ); return true; }
/** The recurring counterpart: the DNS re-check runs hourly for a day. */
function wp_schedule_event( $timestamp, $recurrence, $hook, $args = array() ) { $GLOBALS['stub']['cron'][] = array( 'hook' => $hook, 'args' => $args, 'recurrence' => $recurrence ); return true; }
function wp_clear_scheduled_hook( $hook, $args = array() ) { wp_unschedule_hook( $hook ); }
/** Whether the same hook+args is already queued — cart-abandonment tracking dedupes on this. */
function wp_next_scheduled( $hook, $args = array() ) {
	foreach ( $GLOBALS['stub']['cron'] as $c ) {
		if ( $c['hook'] === $hook && $c['args'] === $args ) {
			return time() + 60;
		}
	}
	return false;
}
function wp_unschedule_hook( $hook ) {
	$GLOBALS['stub']['cron'] = array_values( array_filter( $GLOBALS['stub']['cron'], function ( $c ) use ( $hook ) { return $c['hook'] !== $hook; } ) );
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
function esc_attr__( $s, $d = null ) { return esc_attr( $s ); }

/**
 * Dates. The real wp_date() renders in the site's timezone; the stub uses
 * UTC, which is what the tests assert against, and the plugin's job is only
 * to hand WordPress a format and a timestamp rather than print the raw ISO
 * string it got from the API.
 */
function wp_date( $format, $timestamp = null, $timezone = null ) { return gmdate( (string) $format, null === $timestamp ? time() : (int) $timestamp ); }

/**
 * Enough of the Settings API to render a tab. The fields themselves are
 * exercised elsewhere; what these let the smoke test see is the *screen* —
 * which is the one layer no test could reach before, and the one where a
 * missing function is a fatal error on a live wp-admin page.
 */
function do_settings_sections( $page ) { echo '<!--sections:' . esc_attr( $page ) . '-->'; }
function settings_fields( $group ) { echo '<!--fields:' . esc_attr( $group ) . '-->'; }
function submit_button( $text = '', $type = 'primary', $name = 'submit', $wrap = true ) { echo '<button type="submit">' . esc_html( $text ) . '</button>'; }
function number_format_i18n( $n, $decimals = 0 ) { return number_format( (float) $n, (int) $decimals ); }
function is_user_logged_in() { return ! empty( $GLOBALS['stub']['current_user'] ); }
function get_the_ID() { return isset( $GLOBALS['stub']['post_id'] ) ? $GLOBALS['stub']['post_id'] : 0; }
function wp_get_current_user() {
	$u              = new stdClass();
	$u->user_email  = isset( $GLOBALS['stub']['current_user']['email'] ) ? $GLOBALS['stub']['current_user']['email'] : '';
	return $u;
}
function did_action( $tag ) { return empty( $GLOBALS['stub']['did'][ $tag ] ) ? 0 : 1; }
function wp_doing_ajax() { return ! empty( $GLOBALS['stub']['ajax'] ); }
function wp_verify_nonce( $nonce, $action = -1 ) { return 'nonce:' . $action === $nonce ? 1 : false; }
function wp_nonce_url( $url, $action = -1, $name = '_wpnonce' ) { return add_query_arg( $name, 'nonce:' . $action, $url ); }
function wp_nonce_field( $action = -1, $name = '_wpnonce' ) { echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="nonce:' . esc_attr( $action ) . '" />'; }
function get_post_meta( $id, $key = '', $single = false ) { return isset( $GLOBALS['stub']['postmeta'][ $id ][ $key ] ) ? $GLOBALS['stub']['postmeta'][ $id ][ $key ] : ( $single ? '' : array() ); }
function update_post_meta( $id, $key, $value ) { $GLOBALS['stub']['postmeta'][ $id ][ $key ] = $value; return true; }
function absint( $v ) { return abs( (int) $v ); }
function get_post_types( $args = array(), $output = 'names' ) { return isset( $GLOBALS['stub']['post_types'] ) ? $GLOBALS['stub']['post_types'] : array( 'post', 'page', 'attachment' ); }

/**
 * Just enough of $wpdb for the "is a form on the site" query family.
 *
 * It records every statement so a test can assert what was looked for, and
 * answers from $GLOBALS['stub']['db'] — one flag per place a form can hide,
 * decided by which table and post types the statement names. The plugin's own
 * fallback (no $wpdb at all) is still the default: $GLOBALS['wpdb'] is set
 * only by the tests that want a database.
 */
class SendBeam_Stub_Db {
	public $posts    = 'wp_posts';
	public $postmeta = 'wp_postmeta';
	public $queries  = array();

	public function esc_like( $text ) { return addcslashes( (string) $text, '_%\\' ); }

	/**
	 * Fill the placeholders in order, whichever kind they are.
	 *
	 * %d has to be handled as well as %s: the log's DELETE names its rows
	 * with `id IN ( %d, %d )`, and a prepare() that only knew about strings
	 * left those untouched — which looks exactly like a delete that matched
	 * nothing.
	 */
	public function prepare( $sql, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) { $args = $args[0]; }
		foreach ( $args as $arg ) {
			$sql = preg_replace_callback(
				'/%[sdf]/',
				function ( $m ) use ( $arg ) {
					// Quotes only. addslashes() would double the backslash
					// esc_like() puts in front of an underscore, and the
					// point of this stub is SQL a test can read.
					return '%s' === $m[0] ? "'" . str_replace( "'", "\\'", (string) $arg ) . "'" : (string) ( 0 + $arg );
				},
				$sql,
				1
			);
		}
		return $sql;
	}

	public function get_var( $sql ) {
		$this->queries[] = $sql;
		$db              = isset( $GLOBALS['stub']['db'] ) ? $GLOBALS['stub']['db'] : array();
		if ( false !== strpos( $sql, 'wp_postmeta' ) ) { return empty( $db['meta'] ) ? null : 1; }
		if ( false !== strpos( $sql, 'wp_template' ) ) { return empty( $db['templates'] ) ? null : 1; }
		return empty( $db['posts'] ) ? null : 1;
	}
}

// ── The admin menu ──────────────────────────────────────────────────────
/**
 * Menu registration. The stubs record what was registered rather than
 * building a menu, because what the test needs to know is which slugs exist,
 * what they are called and which callback answers for them — which is exactly
 * what a wrong `add_submenu_page()` argument order gets wrong.
 */
function add_menu_page( $page_title, $menu_title, $cap, $slug, $cb = '', $icon = '', $position = null ) {
	$GLOBALS['stub']['menu'][ $slug ] = array(
		'page_title' => $page_title,
		'menu_title' => $menu_title,
		'cap'        => $cap,
		'callback'   => $cb,
		'icon'       => $icon,
		'position'   => $position,
		'parent'     => null,
	);
	return 'toplevel_page_' . $slug;
}
function add_submenu_page( $parent, $page_title, $menu_title, $cap, $slug, $cb = '', $position = null ) {
	// WordPress registers a top-level page's first submenu item under the
	// same slug, to rename it; recording both in one bucket would hide the
	// top-level registration behind it.
	$GLOBALS['stub']['submenu'][ $slug ] = array(
		'page_title' => $page_title,
		'menu_title' => $menu_title,
		'cap'        => $cap,
		'callback'   => $cb,
		'parent'     => $parent,
		'position'   => $position,
	);
	return ( $parent ? 'sendbeam_page_' : 'admin_page_' ) . $slug;
}
function get_current_screen() {
	return isset( $GLOBALS['stub']['screen'] ) ? (object) array( 'id' => $GLOBALS['stub']['screen'] ) : null;
}
function settings_errors( $setting = '', $sanitize = false, $hide_on_update = false ) {}
function _n( $single, $plural, $number, $domain = null ) { return 1 === (int) $number ? $single : $plural; }
function _x( $text, $context, $domain = null ) { return $text; }
function esc_textarea( $text ) { return esc_html( $text ); }
function disabled( $a, $b = true, $echo = true ) { return $a == $b ? ' disabled="disabled"' : ''; }
function wp_enqueue_media( $args = array() ) {}
function get_admin_page_title() { return 'SendBeam'; }
function submit_button_wrapper() {}
function esc_attr_e( $s, $d = null ) { echo esc_attr( $s ); }
function wp_normalize_path( $path ) { return str_replace( '\\', '/', (string) $path ); }
function dbDelta( $queries = '', $execute = true ) { $GLOBALS['stub']['dbdelta'][] = $queries; return array(); }
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }

// ── WP_List_Table ───────────────────────────────────────────────────────
/**
 * Enough of core's list table to render one.
 *
 * Not a copy of core: the point is to exercise the parts the plugin's
 * subclasses override — the columns, the primary column, the row callbacks,
 * the empty state and the bulk actions — and to produce markup shaped like
 * core's, so an assertion about `column-primary` or `wp-list-table` is an
 * assertion about what a browser would actually receive.
 */
#[AllowDynamicProperties]
class WP_List_Table {
	public $items           = array();
	public $_args           = array();
	public $_column_headers = array();
	public $_pagination     = array();

	public function __construct( $args = array() ) {
		$this->_args = array_merge(
			array(
				'singular' => '',
				'plural'   => '',
				'ajax'     => false,
			),
			$args
		);
	}

	public function get_columns() {
		return array(); }
	protected function get_bulk_actions() {
		return array(); }
	protected function get_views() {
		return array(); }
	protected function get_sortable_columns() {
		return array(); }
	/** Core prints the views as a .subsubsub list above the table. */
	public function views() {
		$views = $this->get_views();
		if ( ! $views ) { return; }
		echo '<ul class="subsubsub">';
		foreach ( $views as $key => $link ) {
			echo '<li class="' . esc_attr( $key ? $key : 'all' ) . '">' . $link . '</li>';
		}
		echo '</ul>';
	}
	public function no_items() {
		echo 'No items.'; }
	public function get_pagenum() {
		return isset( $_REQUEST['paged'] ) ? max( 1, (int) $_REQUEST['paged'] ) : 1; }
	public function set_pagination_args( $args ) {
		$this->_pagination = $args; }
	public function current_action() {
		foreach ( array( 'action', 'action2' ) as $key ) {
			if ( isset( $_REQUEST[ $key ] ) && '-1' !== $_REQUEST[ $key ] ) {
				return sanitize_key( $_REQUEST[ $key ] );
			}
		}
		return false;
	}

	protected function get_primary_column_name() {
		return method_exists( $this, 'get_default_primary_column_name' ) ? $this->get_default_primary_column_name() : key( $this->get_columns() );
	}

	protected function row_actions( $actions, $always_visible = false ) {
		$out = array();
		foreach ( $actions as $key => $link ) {
			$out[] = '<span class="' . esc_attr( $key ) . '">' . $link . '</span>';
		}
		return '<div class="row-actions">' . implode( ' | ', $out ) . '</div>';
	}

	public function search_box( $text, $input_id ) {
		printf(
			'<p class="search-box"><label class="screen-reader-text" for="%1$s-search-input">%2$s</label>' .
			'<input type="search" id="%1$s-search-input" name="s" value="%3$s" placeholder="%2$s" />' .
			'<input type="submit" class="button" value="%2$s" /></p>',
			esc_attr( $input_id ),
			esc_attr( $text ),
			esc_attr( isset( $_REQUEST['s'] ) ? $_REQUEST['s'] : '' )
		);
	}

	public function display() {
		$columns = $this->get_columns();
		$primary = $this->get_primary_column_name();
		$bulk    = $this->get_bulk_actions();

		if ( $bulk ) {
			echo '<div class="tablenav top"><select name="action"><option value="-1">Bulk actions</option>';
			foreach ( $bulk as $value => $label ) {
				echo '<option value="' . esc_attr( $value ) . '">' . esc_html( $label ) . '</option>';
			}
			echo '</select><input type="submit" class="button action" value="Apply" />';
			wp_nonce_field( 'bulk-' . $this->_args['plural'] );
			echo '</div>';
		}

		echo '<table class="wp-list-table widefat fixed striped table-view-list ' . esc_attr( $this->_args['plural'] ) . '"><thead><tr>';
		foreach ( $columns as $key => $label ) {
			$class = 'manage-column column-' . $key . ( $key === $primary ? ' column-primary' : '' );
			echo '<th scope="col" class="' . esc_attr( $class ) . '">' . $label . '</th>';
		}
		echo '</tr></thead><tbody>';
		if ( ! $this->items ) {
			echo '<tr class="no-items"><td class="colspanchange" colspan="' . count( $columns ) . '">';
			$this->no_items();
			echo '</td></tr>';
		}
		foreach ( $this->items as $item ) {
			echo '<tr>';
			foreach ( $columns as $key => $label ) {
				$method = 'column_' . $key;
				$value  = method_exists( $this, $method ) ? $this->$method( $item ) : $this->column_default( $item, $key );
				$class  = 'column-' . $key . ( $key === $primary ? ' has-row-actions column-primary' : '' );
				$tag    = 'cb' === $key ? 'th' : 'td';
				echo '<' . $tag . ' class="' . esc_attr( $class ) . '" data-colname="' . esc_attr( wp_strip_all_tags( $label ) ) . '">' . $value . '</' . $tag . '>';
			}
			echo '</tr>';
		}
		echo '</tbody></table>';
		printf( '<div class="tablenav bottom"><span class="displaying-num">%d items</span></div>', isset( $this->_pagination['total_items'] ) ? (int) $this->_pagination['total_items'] : 0 );
	}

	protected function column_default( $item, $column ) {
		return isset( $item[ $column ] ) ? esc_html( (string) $item[ $column ] ) : ''; }
}

function wp_strip_all_tags( $string, $remove_breaks = false ) { return strip_tags( (string) $string ); }
function add_screen_option( $option, $args = array() ) { $GLOBALS['stub']['screen_options'][ $option ] = $args; }
function delete_user_meta( $user_id, $key, $value = '' ) { unset( $GLOBALS['stub']['usermeta'][ $user_id ][ $key ] ); return true; }
function __return_false() { return false; }
function __return_true() { return true; }
function wp_kses_post( $data ) { return $data; }
function wp_list_pluck_stub( $rows, $field ) {
	$out = array();
	foreach ( $rows as $row ) { $out[] = $row[ $field ]; }
	return $out;
}
function wp_register_style( $h, $src, $deps = array(), $ver = false, $media = 'all' ) { $GLOBALS['stub']['styles'][ $h ] = $src; }
function wp_enqueue_style( $h ) { $GLOBALS['stub']['styles'][ $h . ':enqueued' ] = true; }
function wp_add_inline_style( $h, $css ) { $GLOBALS['stub']['inline_css'][ $h ][] = $css; }

// ── The email log's table ───────────────────────────────────────────────
/**
 * Enough of $wpdb to hold a log.
 *
 * Rows live in a PHP array and the handful of statements the plugin issues
 * are answered by reading it — which is not a SQL engine, but it is enough to
 * prove the shape of every query, that prepare() is reached with the right
 * values, and that a prune deletes what it says it deletes. The statements
 * themselves are recorded so a test can assert on them.
 */
class SendBeam_Stub_MailDb extends SendBeam_Stub_Db {
	public $prefix  = 'wp_';
	public $rows    = array();
	public $next_id = 1;
	public $created = '';

	public function get_charset_collate() { return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'; }

	public function insert( $table, $data, $format = null ) {
		$this->queries[] = 'INSERT INTO ' . $table;
		$data['id']      = $this->next_id++;
		$this->rows[]    = $data;
		return 1;
	}

	/** Everything the plugin asks of the log, answered off the array. */
	public function get_results( $sql, $output = OBJECT ) {
		$this->queries[] = $sql;
		if ( false !== strpos( $sql, 'GROUP BY result' ) ) {
			$by = array();
			foreach ( $this->rows as $r ) { $by[ $r['result'] ] = ( $by[ $r['result'] ] ?? 0 ) + 1; }
			$out = array();
			foreach ( $by as $result => $n ) { $out[] = array( 'result' => $result, 'n' => $n ); }
			return $out;
		}
		$rows = $this->filter( $sql );
		// ORDER BY: only the two columns the plugin allows.
		$col = ( false !== strpos( $sql, 'ORDER BY result' ) ) ? 'result' : 'sent_at';
		$dir = ( false !== strpos( $sql, "{$col} ASC" ) ) ? 1 : -1;
		usort( $rows, function ( $a, $b ) use ( $col, $dir ) {
			$c = strcmp( (string) $a[ $col ], (string) $b[ $col ] );
			if ( 0 === $c ) { $c = (int) $a['id'] - (int) $b['id']; }
			return $c * $dir;
		} );
		if ( preg_match( '/LIMIT (\d+) OFFSET (\d+)/', $sql, $m ) ) {
			$rows = array_slice( $rows, (int) $m[2], (int) $m[1] );
		}
		return array_values( $rows );
	}

	public function get_var( $sql ) {
		$this->queries[] = $sql;
		if ( false !== strpos( $sql, 'COUNT(*)' ) && false !== strpos( $sql, 'sendbeam_mail_log' ) ) {
			return count( $this->filter( $sql ) );
		}
		return parent::get_var( $sql );
	}

	public function query( $sql ) {
		$this->queries[] = $sql;
		if ( false !== strpos( $sql, 'DROP TABLE' ) ) { $this->rows = array(); return true; }
		if ( 0 !== strpos( ltrim( $sql ), 'DELETE' ) ) { return 0; }

		$keep = array();
		$gone = 0;
		$ids  = array();
		if ( preg_match( '/id IN \( ([^)]*) \)/', $sql, $m ) ) {
			$ids = array_map( 'intval', array_map( 'trim', explode( ',', str_replace( "'", '', $m[1] ) ) ) );
		}
		$before = null;
		if ( preg_match( "/sent_at < '([^']+)'/", $sql, $m ) ) { $before = $m[1]; }
		$limit = null;
		if ( preg_match( '/LIMIT (\d+)/', $sql, $m ) ) { $limit = (int) $m[1]; }

		$rows = $this->rows;
		if ( null !== $limit ) {
			usort( $rows, function ( $a, $b ) { return strcmp( $a['sent_at'], $b['sent_at'] ) ?: ( (int) $a['id'] - (int) $b['id'] ); } );
		}
		foreach ( $rows as $r ) {
			$hit = ( $ids && in_array( (int) $r['id'], $ids, true ) )
				|| ( null !== $before && $r['sent_at'] < $before )
				|| ( ! $ids && null === $before && null === $limit );
			if ( null !== $limit && ! $ids && null === $before ) { $hit = $gone < $limit; }
			if ( $hit && ( null === $limit || $gone < $limit || $ids || null !== $before ) ) { $gone++; continue; }
			$keep[] = $r;
		}
		$this->rows = array_values( $keep );
		return $gone;
	}

	/** The WHERE the plugin builds, applied to the array. */
	private function filter( $sql ) {
		$rows = $this->rows;
		if ( preg_match( "/result = '([^']+)'/", $sql, $m ) ) {
			$rows = array_values( array_filter( $rows, function ( $r ) use ( $m ) { return $r['result'] === $m[1]; } ) );
		}
		if ( preg_match( "/to_addr LIKE '%([^%]*)%'/", $sql, $m ) && '' !== $m[1] ) {
			$needle = $m[1];
			$rows   = array_values( array_filter( $rows, function ( $r ) use ( $needle ) {
				return false !== stripos( $r['to_addr'] . ' ' . $r['subject'] . ' ' . $r['note'], $needle );
			} ) );
		}
		return $rows;
	}
}
if ( ! defined( 'OBJECT' ) ) { define( 'OBJECT', 'OBJECT' ); }
if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }
