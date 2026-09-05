<?php
/**
 * Output checks against the WordPress stubs. Run: php tests/smoke.php
 */
require __DIR__ . '/wp-stubs.php';
require dirname( __DIR__ ) . '/sendbeam.php';

$pass = 0;
function ok( $cond, $what ) { global $pass; if ( ! $cond ) { fwrite( STDERR, "FAIL: $what\n" ); exit( 1 ); } $pass++; }
function has( $hay, $needle, $what ) { ok( false !== strpos( $hay, $needle ), "$what — expected to find: $needle\nGOT: $hay" ); }
function lacks( $hay, $needle, $what ) { ok( false === strpos( $hay, $needle ), "$what — did not expect: $needle\nGOT: $hay" ); }

$form = '8f3c1a2e-3b1d-4c55-9a0e-1f2d3c4b5a69';
$other = '11111111-2222-4333-8444-555555555555';

// Sanitising settings: bad IDs are dropped with a notice, enums fall back, delay is clamped.
$clean = sendbeam_sanitize_settings( array( 'default_form' => strtoupper( $form ), 'contact_form' => 'not-an-id', 'popup_form' => $other, 'popup_where' => 'moon', 'popup_trigger' => 'button', 'popup_delay' => '999', 'popup_once' => 'week', 'popup_label' => '<b>Join</b>' ) );
ok( $clean['default_form'] === $form, 'form id lower-cased' );
ok( $clean['contact_form'] === '', 'bad contact id dropped' );
ok( count( $GLOBALS['stub']['errors'] ) === 1, 'one settings error for the bad id' );
ok( $clean['popup_where'] === 'off', 'unknown where falls back to off' );
ok( $clean['popup_trigger'] === 'button' && $clean['popup_once'] === 'week', 'enums kept' );
ok( $clean['popup_delay'] === 120, 'delay clamped to 120' );
ok( $clean['popup_label'] === 'Join', 'label stripped of tags' );
ok( sendbeam_sanitize_settings( 'garbage' ) === sendbeam_default_settings(), 'non-array input gives defaults' );

// Form HTML.
ok( sendbeam_form_html( 'nope' ) === '', 'invalid id renders nothing' );
$html = sendbeam_form_html( $form, 50, 'A "quoted" title' );
has( $html, 'src="https://sendbeam.io/f/' . $form . '?embed=1"', 'iframe src' );
has( $html, 'height:200px', 'height clamped up to 200' );
has( $html, 'title="A &quot;quoted&quot; title"', 'title escaped' );
ok( ! empty( $GLOBALS['stub']['inline']['sendbeam-relay'] ), 'relay script added once' );
sendbeam_form_html( $form );
ok( count( $GLOBALS['stub']['inline']['sendbeam-relay'] ) === 1, 'relay not duplicated' );

// Shortcodes with no settings: visitors see nothing, editors see a notice.
ok( do_shortcode_tag( 'sendbeam_form' ) === '', 'no default form → empty for visitors' );
$GLOBALS['stub']['caps']['edit_posts'] = true;
has( do_shortcode_tag( 'sendbeam_form' ), 'no form ID', 'editors get a notice' );
has( do_shortcode_tag( 'sendbeam_form', array( 'id' => $form, 'height' => '700' ) ), 'height:700px', 'explicit id + height' );

update_option( 'sendbeam_settings', array( 'default_form' => $form, 'contact_form' => $other, 'popup_form' => $other, 'popup_where' => 'posts', 'popup_delay' => 3, 'popup_once' => 'week', 'popup_trigger' => 'timer' ) );
has( do_shortcode_tag( 'sendbeam_form' ), '/f/' . $form . '?embed=1', 'default form used' );
has( do_shortcode_tag( 'sendbeam_contact' ), '/f/' . $other . '?embed=1', 'contact form used' );
has( do_shortcode_tag( 'sendbeam_contact' ), 'title="Contact form"', 'contact title' );

// Pop-up loader: not on pages, on posts with the settings as data attributes.
$GLOBALS['stub']['query'] = array( 'page' => true );
sendbeam_print_popup_loader();
ok( $GLOBALS['stub']['printed'] === '', 'no loader on a page when set to posts' );
$GLOBALS['stub']['query'] = array( 'singular:post' => true );
sendbeam_print_popup_loader();
$tag = $GLOBALS['stub']['printed'];
has( $tag, 'src="https://sendbeam.io/f/' . $other . '/popup.js"', 'loader src' );
has( $tag, 'data-delay="3000"', 'delay in ms' );
has( $tag, 'data-once="week"', 'once' );
has( $tag, ' async', 'async' );
lacks( $tag, 'data-trigger', 'timer mode has no trigger attr' );

// A popup button on a page where the pop-up is off → loader in manual mode.
$GLOBALS['stub']['printed'] = '';
$GLOBALS['stub']['query'] = array( 'page' => true );
$btn = do_shortcode_tag( 'sendbeam_popup_button', array( 'label' => 'Join <us>' ) );
has( $btn, 'data-sendbeam-open="' . $other . '"', 'button opener' );
has( $btn, 'Join &lt;us&gt;', 'button label escaped' );
sendbeam_print_popup_loader();
has( $GLOBALS['stub']['printed'], 'data-trigger="manual"', 'manual trigger when only the button needs it' );

// Floating-button mode carries the label.
$GLOBALS['stub']['printed'] = '';
unset( $GLOBALS['sendbeam_popup_button_used'] );
update_option( 'sendbeam_settings', array( 'popup_form' => $other, 'popup_where' => 'everywhere', 'popup_trigger' => 'button', 'popup_label' => 'Get the letter' ) );
sendbeam_print_popup_loader();
has( $GLOBALS['stub']['printed'], 'data-trigger="button"', 'button trigger' );
has( $GLOBALS['stub']['printed'], 'data-label="Get the letter"', 'button label' );

// Block render.
$GLOBALS['stub']['printed'] = '';
ob_start();
$attributes = array( 'formId' => '', 'height' => 400 );
update_option( 'sendbeam_settings', array( 'default_form' => $form ) );
include dirname( __DIR__ ) . '/blocks/form/render.php';
$block = ob_get_clean();
has( $block, 'class="wp-block-sendbeam-form sendbeam-form-wrap"', 'block wrapper' );
has( $block, '/f/' . $form . '?embed=1', 'block falls back to the default form' );
has( $block, 'height:400px', 'block height' );

// The app URL filter.
add_filter( 'sendbeam_app_url', function () { return 'https://mail.example.com/'; } );
has( sendbeam_form_html( $form ), 'src="https://mail.example.com/f/', 'app url filter' );

echo "smoke: $pass checks passed\n";
