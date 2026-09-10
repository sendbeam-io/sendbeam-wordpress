<?php
/**
 * Just enough of WordPress to exercise the plugin's output functions
 * without a WordPress install. Each stub does what the real function does
 * for the inputs the plugin passes it.
 */
define( 'ABSPATH', '/stub/' );
$GLOBALS['stub'] = array( 'remote' => array(), 'remote_reply' => null, 'actions' => array(), 'options' => array(), 'hooks' => array(), 'shortcodes' => array(), 'scripts' => array(), 'inline' => array(), 'printed' => '', 'caps' => array(), 'query' => array(), 'errors' => array() );

function plugin_dir_path( $f ) { return dirname( $f ) . '/'; }
function plugin_basename( $f ) { return 'sendbeam/sendbeam.php'; }
function apply_filters( $tag, $value, ...$args ) { return isset( $GLOBALS['stub']['hooks'][ $tag ] ) ? call_user_func( $GLOBALS['stub']['hooks'][ $tag ], $value, ...$args ) : $value; }
function add_filter( $tag, $fn, $p = 10, $a = 1 ) { $GLOBALS['stub']['hooks'][ $tag ] = $fn; }
function add_action( $tag, $fn ) { $GLOBALS['stub']['hooks'][ $tag ] = $fn; }
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
function wp_unslash( $s ) { return stripslashes( (string) $s ); }
function get_option( $k, $default = false ) { return isset( $GLOBALS['stub']['options'][ $k ] ) ? $GLOBALS['stub']['options'][ $k ] : $default; }
function update_option( $k, $v ) { $GLOBALS['stub']['options'][ $k ] = $v; }
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
function get_user_meta( $user_id, $key = '', $single = false ) { return $single ? '' : array(); }

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
	return isset( $GLOBALS['stub']['remote_reply'] )
		? $GLOBALS['stub']['remote_reply']
		: array( 'response' => array( 'code' => 200 ), 'body' => '{}' );
}
function update_user_meta( $user_id, $key, $value ) { return true; }

// ── Site email stubs ────────────────────────────────────────────────────
class WP_Error {
	public $code; public $message; public $data;
	public function __construct( $code = '', $message = '', $data = null ) { $this->code = $code; $this->message = $message; $this->data = $data; }
	public function get_error_message() { return $this->message; }
}
function is_wp_error( $t ) { return $t instanceof WP_Error; }
function wp_remote_post( $url, $args ) { $GLOBALS['stub']['remote'][] = array( 'url' => $url, 'args' => $args ); $r = $GLOBALS['stub']['remote_reply']; return is_callable( $r ) ? $r( $url, $args ) : $r; }
function wp_remote_retrieve_response_code( $r ) { return $r['response']['code']; }
function wp_remote_retrieve_body( $r ) { return $r['body']; }
function do_action( $tag, ...$args ) { $GLOBALS['stub']['actions'][] = array( $tag, $args ); }
function network_home_url() { return 'https://www.example-site.test/'; }
function home_url() { return 'https://www.example-site.test'; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function is_email( $e ) { return (bool) filter_var( $e, FILTER_VALIDATE_EMAIL ); }
function sanitize_email( $e ) { return trim( (string) $e ); }
function checked( $a, $b = true, $echo = true ) { return $a == $b ? ' checked="checked"' : ''; }
function get_bloginfo( $k ) { return 'Example Site'; }
function selected( $a, $b, $echo = true ) { return $a == $b ? ' selected="selected"' : ''; }
