<?php
/**
 * Just enough of WordPress to exercise the plugin's output functions
 * without a WordPress install. Each stub does what the real function does
 * for the inputs the plugin passes it.
 */
define( 'ABSPATH', '/stub/' );
$GLOBALS['stub'] = array( 'options' => array(), 'hooks' => array(), 'shortcodes' => array(), 'scripts' => array(), 'inline' => array(), 'printed' => '', 'caps' => array(), 'query' => array(), 'errors' => array() );

function plugin_dir_path( $f ) { return dirname( $f ) . '/'; }
function plugin_basename( $f ) { return 'sendbeam/sendbeam.php'; }
function apply_filters( $tag, $value ) { return isset( $GLOBALS['stub']['hooks'][ $tag ] ) ? call_user_func( $GLOBALS['stub']['hooks'][ $tag ], $value ) : $value; }
function add_filter( $tag, $fn ) { $GLOBALS['stub']['hooks'][ $tag ] = $fn; }
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
