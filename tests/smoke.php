<?php
/**
 * Output checks against the WordPress stubs. Run: php tests/smoke.php
 */
require __DIR__ . '/wp-stubs.php';
require dirname( __DIR__ ) . '/sendbeam.php';

$pass = 0;
function ok( $cond, $what ) { global $pass; if ( ! $cond ) { fwrite( STDERR, "FAIL: $what\n" ); exit( 1 ); } $pass++; }
/**
 * Everything the footer hook emits: the stubs record wp_print_*_tag calls,
 * but the hidden opener is echoed straight out, so both must be collected or
 * the assertion passes on half the output.
 */
function popup_output() {
	$GLOBALS['stub']['printed'] = '';
	ob_start();
	sendbeam_print_popup_loader();
	$echoed = ob_get_clean();
	return $GLOBALS['stub']['printed'] . $echoed;
}
function has( $hay, $needle, $what ) { ok( false !== strpos( $hay, $needle ), "$what — expected to find: $needle\nGOT: $hay" ); }
function lacks( $hay, $needle, $what ) { ok( false === strpos( $hay, $needle ), "$what — did not expect: $needle\nGOT: $hay" ); }

$form = '8f3c1a2e-3b1d-4c55-9a0e-1f2d3c4b5a69';
$other = '11111111-2222-4333-8444-555555555555';

// Sanitising settings: bad IDs are dropped with a notice.
$clean = sendbeam_sanitize_settings( array( '_tab' => 'forms', 'default_form' => strtoupper( $form ), 'contact_form' => 'not-an-id' ) );
ok( $clean['default_form'] === $form, 'form id lower-cased' );
ok( $clean['contact_form'] === '', 'bad contact id dropped' );
ok( count( $GLOBALS['stub']['errors'] ) === 1, 'one settings error for the bad id' );
ok( ! isset( $clean['_tab'] ), 'the tab marker is not stored' );

// Saving one tab must not flatten the others: an unchecked box and a field
// that was never on screen look identical in $_POST.
update_option( 'sendbeam_settings', array( 'api_key' => 'sb_live_keptkeptkept', 'default_form' => $form, 'mail_enabled' => 1 ) );
$kept = sendbeam_sanitize_settings( array( '_tab' => 'forms', 'default_form' => $other, 'contact_form' => '' ) );
ok( $kept['api_key'] === 'sb_live_keptkeptkept', 'saving Forms keeps the API key' );
ok( (int) $kept['mail_enabled'] === 1, 'saving Forms keeps site email on' );
$off = sendbeam_sanitize_settings( array( '_tab' => 'mail' ) );
ok( (int) $off['mail_enabled'] === 0, 'unchecking site email on its own tab turns it off' );
update_option( 'sendbeam_settings', array() );

// Pop-up rules: unknown enums fall back, numbers are clamped, tags are stripped.
$rule = sendbeam_clean_popup( array( 'form' => $other, 'trigger' => 'moon', 'where' => 'moon', 'delay' => '999', 'scroll' => '999', 'once' => 'week', 'label' => '<b>Join</b>', 'enabled' => '1' ) );
ok( $rule['form'] === $other, 'rule keeps a valid form id' );
ok( $rule['trigger'] === 'timer', 'unknown trigger falls back to timer' );
ok( $rule['where'] === 'everywhere', 'unknown placement falls back to everywhere' );
ok( $rule['once'] === 'week', 'known frequency kept' );
ok( $rule['delay'] === 120, 'delay clamped to 120' );
ok( $rule['scroll'] === 100, 'scroll depth clamped to 100' );
ok( $rule['label'] === 'Join', 'label stripped of tags' );
ok( sendbeam_clean_popup( array( 'form' => 'not-an-id' ) )['form'] === '', 'rule drops a bad form id' );

// The look of one pop-up: a known style and an https picture survive; an
// unknown style, and a picture served over http, do not.
$look = sendbeam_clean_popup(
	array(
		'form'    => $other,
		'style'   => 'bold',
		'image'   => 'https://example-site.test/wp-content/uploads/letter.jpg',
		'eyebrow' => str_repeat( 'e', 60 ),
		'button'  => str_repeat( 'b', 50 ),
		'proof'   => '1',
	)
);
ok( $look['style'] === 'bold', 'a known style is kept' );
ok( $look['image'] === 'https://example-site.test/wp-content/uploads/letter.jpg', 'an https image is kept' );
ok( mb_strlen( $look['eyebrow'] ) === 40, 'eyebrow capped at 40' );
ok( mb_strlen( $look['button'] ) === 30, 'button label capped at 30' );
ok( 1 === $look['proof'], 'subscriber count is a one' );

$plain = sendbeam_clean_popup( array( 'form' => $other, 'style' => 'neon', 'image' => 'http://example-site.test/letter.jpg' ) );
ok( $plain['style'] === 'split', 'an unknown style falls back to split' );
ok( $plain['image'] === '', 'an http image is refused' );
ok( 0 === $plain['proof'], 'no subscriber count unless asked for' );
ok( sendbeam_clean_popup( array( 'form' => $other ) )['style'] === 'split', 'split is the default style' );

// Form HTML.
ok( sendbeam_form_html( 'nope' ) === '', 'invalid id renders nothing' );
$html = sendbeam_form_html( $form, 50, 'A "quoted" title' );
has( $html, 'src="https://sendbeam.io/f/' . $form . '?embed=1', 'iframe src' );
has( $html, 'width=0', 'the embed fills the column the theme gives it' );
has( $html, 'height:200px', 'height clamped up to 200' );
has( $html, 'title="A &quot;quoted&quot; title"', 'title escaped' );
ok( ! empty( $GLOBALS['stub']['inline']['sendbeam-relay'] ), 'relay script added once' );
$relay = $GLOBALS['stub']['inline']['sendbeam-relay'][0];
has( $relay, 'e.origin!==SB_ORIGIN', 'the relay ignores messages from any other origin' );
has( $relay, 'sendbeam.io', 'the expected origin is pinned to the app URL' );
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

// Pop-up loader: not on pages, on posts with the rule as data attributes.
update_option( 'sendbeam_popups', array( array( 'enabled' => 1, 'form' => $other, 'trigger' => 'timer', 'delay' => 3, 'scroll' => 50, 'label' => '', 'once' => 'week', 'where' => 'posts', 'url' => '' ) ) );
$GLOBALS['stub']['query'] = array( 'page' => true );
sendbeam_print_popup_loader();
ok( $GLOBALS['stub']['printed'] === '', 'no loader on a page when set to posts' );
$GLOBALS['stub']['query'] = array( 'singular:post' => true );
sendbeam_print_popup_loader();
$tag = $GLOBALS['stub']['printed'];
has( $tag, '/f/' . $other . '/popup.js?v=', 'loader src carries an appearance fingerprint' );
$first_stamp = sendbeam_popup_script_url( $other );
update_option( 'sendbeam_settings', array_merge( sendbeam_settings(), array( 'style_accent' => '#123456' ) ) );
ok( sendbeam_popup_script_url( $other ) !== $first_stamp, 'changing a colour changes the loader URL' );
// The style is part of the same fingerprint: a pop-up switched from split to
// bold has to reach a visitor still holding the cached loader.
$split_stamp = sendbeam_popup_script_url( $other, array( 'style' => 'split' ) );
ok( sendbeam_popup_script_url( $other, array( 'style' => 'bold' ) ) !== $split_stamp, 'changing the style changes the loader URL' );
ok( sendbeam_popup_script_url( $other, array( 'style' => 'split' ) ) === $split_stamp, 'the same style keeps the loader URL' );
has( $tag, 'data-style="split"', 'the style always travels with the loader' );
lacks( $tag, 'data-image', 'no image attribute when no image was chosen' );
lacks( $tag, 'data-proof', 'no count attribute unless it was asked for' );
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
unset( $GLOBALS['sendbeam_popup_buttons'] );
update_option( 'sendbeam_popups', array( array( 'enabled' => 1, 'form' => $other, 'trigger' => 'button', 'delay' => 5, 'scroll' => 50, 'label' => 'Get the letter', 'once' => 'day', 'where' => 'everywhere', 'url' => '' ) ) );
sendbeam_print_popup_loader();
has( $GLOBALS['stub']['printed'], 'data-trigger="button"', 'button trigger' );
has( $GLOBALS['stub']['printed'], 'data-label="Get the letter"', 'button label' );

// A styled pop-up: the five look attributes travel with the loader.
$GLOBALS['stub']['printed'] = '';
unset( $GLOBALS['sendbeam_popup_buttons'] );
update_option(
	'sendbeam_popups',
	array(
		array(
			'enabled' => 1,
			'form'    => $other,
			'trigger' => 'timer',
			'delay'   => 5,
			'scroll'  => 50,
			'label'   => '',
			'once'    => 'day',
			'where'   => 'everywhere',
			'url'     => '',
			'style'   => 'editorial',
			'image'   => 'https://example-site.test/letter.jpg',
			'eyebrow' => 'Monthly - Free',
			'button'  => 'Join the letter',
			'proof'   => 1,
		),
	)
);
$styled = popup_output();
has( $styled, 'data-style="editorial"', 'the chosen style travels' );
has( $styled, 'data-image="https://example-site.test/letter.jpg"', 'the image travels' );
has( $styled, 'data-eyebrow="Monthly - Free"', 'the eyebrow travels' );
has( $styled, 'data-button="Join the letter"', 'the button label travels' );
has( $styled, 'data-proof="1"', 'the subscriber count is asked for' );

// Several rules: the first that matches wins, and nothing else is printed.
$GLOBALS['stub']['printed'] = '';
unset( $GLOBALS['sendbeam_popup_buttons'] );
update_option( 'sendbeam_popups', array(
	array( 'enabled' => 0, 'form' => $form,  'trigger' => 'timer', 'delay' => 1, 'scroll' => 50, 'label' => '', 'once' => 'day', 'where' => 'everywhere', 'url' => '' ),
	array( 'enabled' => 1, 'form' => $other, 'trigger' => 'timer', 'delay' => 2, 'scroll' => 50, 'label' => '', 'once' => 'day', 'where' => 'everywhere', 'url' => '' ),
	array( 'enabled' => 1, 'form' => $form,  'trigger' => 'timer', 'delay' => 9, 'scroll' => 50, 'label' => '', 'once' => 'day', 'where' => 'everywhere', 'url' => '' ),
) );
sendbeam_print_popup_loader();
has( $GLOBALS['stub']['printed'], '/f/' . $other . '/popup.js', 'a disabled rule is skipped and the next one wins' );
lacks( $GLOBALS['stub']['printed'], '/f/' . $form . '/popup.js', 'the later matching rule is not also printed' );
ok( substr_count( $GLOBALS['stub']['printed'], '<script' ) === 1, 'exactly one loader' );

// Scroll and exit triggers open through the hosted loader's manual mode.
$GLOBALS['stub']['printed'] = '';
unset( $GLOBALS['sendbeam_popup_buttons'] );
update_option( 'sendbeam_popups', array( array( 'enabled' => 1, 'form' => $other, 'trigger' => 'scroll', 'delay' => 5, 'scroll' => 75, 'label' => '', 'once' => 'day', 'where' => 'everywhere', 'url' => '' ) ) );
$out = popup_output();
has( $out, 'data-trigger="scroll"', 'scroll is declared to the loader, not faked with a click' );
has( $out, 'data-scroll="75"', 'scroll depth travels with it' );
lacks( $out, 'data-sendbeam-open', 'no synthetic opener — that path ignores suppression' );

$GLOBALS['stub']['printed'] = '';
unset( $GLOBALS['sendbeam_popup_buttons'] );
update_option( 'sendbeam_popups', array( array( 'enabled' => 1, 'form' => $other, 'trigger' => 'exit', 'delay' => 5, 'scroll' => 50, 'label' => '', 'once' => 'day', 'where' => 'everywhere', 'url' => '' ) ) );
has( popup_output(), 'data-trigger="exit"', 'exit intent is declared to the loader' );

// Address targeting.
$GLOBALS['stub']['printed'] = '';
unset( $GLOBALS['sendbeam_popup_buttons'] );
update_option( 'sendbeam_popups', array( array( 'enabled' => 1, 'form' => $other, 'trigger' => 'timer', 'delay' => 5, 'scroll' => 50, 'label' => '', 'once' => 'day', 'where' => 'url', 'url' => '/pricing' ) ) );
$_SERVER['REQUEST_URI'] = '/about/';
sendbeam_print_popup_loader();
ok( $GLOBALS['stub']['printed'] === '', 'address rule does not match /about/' );
$_SERVER['REQUEST_URI'] = '/pricing/plans';
sendbeam_print_popup_loader();
has( $GLOBALS['stub']['printed'], '/f/' . $other . '/popup.js', 'address rule matches /pricing/plans' );

// Upgrading from the single pop-up: the old settings become rule one.
delete_option( 'sendbeam_popups' );
update_option( 'sendbeam_settings', array( 'popup_form' => $form, 'popup_where' => 'home', 'popup_trigger' => 'button', 'popup_delay' => 3, 'popup_once' => 'week', 'popup_label' => 'Join us' ) );
$migrated = sendbeam_popups();
ok( count( $migrated ) === 1, 'legacy pop-up migrates to one rule' );
ok( $migrated[0]['form'] === $form && $migrated[0]['where'] === 'home', 'migrated form and placement' );
ok( $migrated[0]['trigger'] === 'button' && $migrated[0]['label'] === 'Join us', 'migrated trigger and label' );
delete_option( 'sendbeam_popups' );

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

// Appearance: only chosen values are sent, and a bad colour never leaves here.
update_option( 'sendbeam_settings', array( 'style_accent' => '#B45309', 'style_radius' => '2', 'style_font' => 'serif', 'style_text' => 'rgb(1,2,3)' ) );
$args = sendbeam_appearance_args();
ok( $args['accent'] === '%23b45309', 'accent is hex-escaped — a bare # would start the URL fragment' );
has( sendbeam_form_url( $form ), 'accent=%23b45309', 'the escaped colour survives into the iframe URL' );
lacks( sendbeam_form_url( $form ), 'accent=#', 'no bare hash in the URL' );
ok( $args['radius'] === '2', 'radius passed' );
ok( $args['font'] === 'serif', 'font passed' );
ok( ! isset( $args['text'] ), 'a non-hex colour is not sent at all' );
$clean_style = sendbeam_sanitize_settings( array( '_tab' => 'forms', 'style_accent' => 'red', 'style_radius' => '999', 'style_font' => 'Comic Sans' ) );
ok( $clean_style['style_accent'] === '#B45309', 'a bad colour leaves the saved one untouched' );
ok( $clean_style['style_radius'] === '28', 'radius clamped on save' );
ok( $clean_style['style_font'] === 'inherit', 'an unknown font falls back' );
update_option( 'sendbeam_settings', array() );

// ── Site email ──────────────────────────────────────────────────────────
$stub = &$GLOBALS['stub'];
unset( $stub['hooks']['sendbeam_app_url'] ); // the app-url filter test above must not leak into here
$stub['remote_reply'] = array( 'response' => array( 'code' => 200 ), 'body' => '{"ok":true,"sent":[{"to":"a@b.test","message_id":"m1"}]}' );
$mail_atts = array( 'to' => 'Ada <ada@customer.test>', 'subject' => 'Order #1001', 'message' => '<p>Thanks</p>', 'headers' => array( 'Content-Type: text/html; charset=UTF-8', 'Reply-To: Shop <shop@example-site.test>', 'Bcc: owner@example-site.test', 'X-Order-Id: 1001', 'List-Unsubscribe: <x>' ), 'attachments' => array() );

// Off by default: WordPress keeps sending.
update_option( 'sendbeam_settings', array() );
ok( null === apply_filters( 'pre_wp_mail', null, $mail_atts ), 'disabled → null (WordPress sends)' );
ok( count( $stub['remote'] ) === 0, 'no API call when disabled' );

// On, with a saved key: relayed, headers mapped, From falls back to the workspace sender.
update_option( 'sendbeam_settings', array( 'mail_enabled' => 1, 'api_key' => 'sb_live_abcdefghijklmnop', 'mail_fallback' => 1 ) );
ok( true === apply_filters( 'pre_wp_mail', null, $mail_atts ), 'enabled → true (sent)' );
$req = $stub['remote'][0];
ok( $req['url'] === 'https://sendbeam.io/api/v1/transactional', 'posts to the transactional endpoint' );
ok( $req['args']['headers']['x-api-key'] === 'sb_live_abcdefghijklmnop', 'key header' );
$sent = json_decode( $req['args']['body'], true );
ok( $sent['to'] === 'Ada <ada@customer.test>' && $sent['subject'] === 'Order #1001', 'to + subject' );
ok( $sent['html'] === '<p>Thanks</p>' && ! isset( $sent['text'] ), 'html content type → html' );
ok( $sent['reply_to'] === 'Shop <shop@example-site.test>', 'reply-to' );
ok( $sent['bcc'] === array( 'owner@example-site.test' ), 'bcc' );
ok( $sent['headers'] === array( 'X-Order-Id' => '1001' ), 'only X-* headers forwarded' );
ok( ! isset( $sent['from_email'] ), 'no From when WordPress would invent wordpress@…' );
$log = get_option( 'sendbeam_mail_log' );
ok( $log[0]['result'] === 'sent' && $log[0]['to'] === 'Ada <ada@customer.test>', 'log entry' );

// Plain text (WordPress default), From header on the site's domain, wp_mail_from_name filter honoured.
$stub['remote'] = array();
add_filter( 'wp_mail_from_name', function ( $n ) { return 'Filtered Name'; } );
apply_filters( 'pre_wp_mail', null, array( 'to' => array( 'a@b.test', 'c@d.test' ), 'subject' => 'Reset', 'message' => "Line 1\nLine 2", 'headers' => 'From: Orders <orders@example-site.test>', 'attachments' => array() ) );
$sent = json_decode( $stub['remote'][0]['args']['body'], true );
ok( $sent['text'] === "Line 1\nLine 2" && ! isset( $sent['html'] ), 'plain text stays text' );
ok( $sent['to'] === array( 'a@b.test', 'c@d.test' ), 'array of recipients' );
ok( $sent['from_email'] === 'orders@example-site.test' && $sent['from_name'] === 'Filtered Name', 'From header + name filter' );
unset( $stub['hooks']['wp_mail_from_name'] );

// The settings' From wins over the caller's header.
$stub['remote'] = array();
update_option( 'sendbeam_settings', array( 'mail_enabled' => 1, 'api_key' => 'sb_live_abcdefghijklmnop', 'mail_fallback' => 1, 'mail_from_email' => 'hello@example-site.test', 'mail_from_name' => 'Example' ) );
apply_filters( 'pre_wp_mail', null, array( 'to' => 'a@b.test', 'subject' => 's', 'message' => 'm', 'headers' => 'From: x@y.test' ) );
$sent = json_decode( $stub['remote'][0]['args']['body'], true );
ok( $sent['from_email'] === 'hello@example-site.test' && $sent['from_name'] === 'Example', 'settings From wins' );

// Attachments → server mailer.
$stub['remote'] = array();
ok( null === apply_filters( 'pre_wp_mail', null, array( 'to' => 'a@b.test', 'subject' => 's', 'message' => 'm', 'headers' => '', 'attachments' => array( '/tmp/invoice.pdf' ) ) ), 'attachments → null' );
ok( count( $stub['remote'] ) === 0 && get_option( 'sendbeam_mail_log' )[0]['result'] === 'fallback', 'no call, logged as fallback' );

// API refusal: fallback on → null; fallback off → false + wp_mail_failed.
$stub['remote_reply'] = array( 'response' => array( 'code' => 403 ), 'body' => '{"error":"Monthly email limit reached"}' );
ok( null === apply_filters( 'pre_wp_mail', null, array( 'to' => 'a@b.test', 'subject' => 's', 'message' => 'm' ) ), 'refused + fallback → null' );
ok( get_option( 'sendbeam_mail_log' )[0]['note'] === 'Monthly email limit reached', 'API error in the log' );
update_option( 'sendbeam_settings', array( 'mail_enabled' => 1, 'api_key' => 'sb_live_abcdefghijklmnop', 'mail_fallback' => 0 ) );
$stub['actions'] = array();
ok( false === apply_filters( 'pre_wp_mail', null, array( 'to' => 'a@b.test', 'subject' => 's', 'message' => 'm' ) ), 'refused, no fallback → false' );
ok( $stub['actions'][0][0] === 'wp_mail_failed' && $stub['actions'][0][1][0]->get_error_message() === 'Monthly email limit reached', 'wp_mail_failed fired with the reason' );
$stub['remote_reply'] = new WP_Error( 'http_request_failed', 'cURL error 28' );
ok( false === apply_filters( 'pre_wp_mail', null, array( 'to' => 'a@b.test', 'subject' => 's', 'message' => 'm' ) ), 'network error handled' );

// Settings sanitising: blank key keeps the saved one; remove clears; bad key rejected.
$stub['errors'] = array();
$clean = sendbeam_sanitize_settings( array( 'mail_enabled' => '1', 'api_key' => '', 'mail_from_email' => 'not-an-email', 'mail_fallback' => '' ) );
ok( $clean['api_key'] === 'sb_live_abcdefghijklmnop' && $clean['mail_enabled'] === 1 && $clean['mail_fallback'] === 0 && $clean['mail_from_email'] === '', 'blank key kept, bad from dropped' );
ok( sendbeam_sanitize_settings( array( 'api_key' => 'bad key!' ) )['api_key'] === 'sb_live_abcdefghijklmnop' && count( $stub['errors'] ) === 1, 'malformed key rejected with a notice' );
ok( sendbeam_sanitize_settings( array( 'api_key_remove' => '1' ) )['api_key'] === '', 'remove clears the key' );
ok( sendbeam_sanitize_settings( array( 'api_key' => 'sb_new_0123456789abcdef' ) )['api_key'] === 'sb_new_0123456789abcdef', 'new key saved' );

// wp-config constant wins over the option.
define( 'SENDBEAM_API_KEY', 'sb_const_0123456789abcdef' );
ok( sendbeam_api_key() === 'sb_const_0123456789abcdef', 'constant wins' );


// ── Form plugin bridges ─────────────────────────────────────────────────
$stub['remote'] = array();
$stub['cron']   = array();
$stub['caps']   = array();

// None of the form plugins is active: nothing is loaded.
call_user_func( $stub['hooks']['plugins_loaded'] );
ok( ! function_exists( 'sendbeam_cf7_submit' ) && ! function_exists( 'sendbeam_wpforms_submit' ) && ! function_exists( 'sendbeam_fluentforms_submit' ), 'no bridge loads without its form plugin' );
sendbeam_bridge_register_elementor( new stdClass() );
ok( ! class_exists( 'Sendbeam_Elementor_Action', false ), 'the Elementor action is not loaded without Elementor Pro' );
sendbeam_bridge_load_gravityforms();
ok( ! class_exists( 'Sendbeam_GF_Addon', false ), 'the Gravity Forms add-on is not loaded without Gravity Forms' );

require __DIR__ . '/host-stubs.php';
call_user_func( $stub['hooks']['plugins_loaded'] );
ok( function_exists( 'sendbeam_cf7_submit' ) && function_exists( 'sendbeam_wpforms_submit' ) && function_exists( 'sendbeam_fluentforms_submit' ), 'bridges load once their form plugins are active' );

// Contact Form 7.
$sendbeam_cf7_form = new class() { public function id() { return 7; } };
WPCF7_Submission::$posted = array( 'your-email' => 'ada@example.test', 'your-name' => 'Ada Lovelace', 'subscribe' => array( 'Yes' ) );
sendbeam_cf7_submit( $sendbeam_cf7_form );
ok( empty( $stub['cron'] ), 'CF7: a form not switched on for SendBeam sends nothing' );

$_POST = array( 'sendbeam_cf7_nonce' => 'nonce:sendbeam_cf7_save', 'sendbeam_cf7' => array( 'enabled' => '1', 'email_field' => 'your-email', 'first_field' => 'your-name', 'consent' => 'field', 'consent_field' => 'subscribe', 'lists' => array( 'list-1' ), 'tag' => 'contact <b>form</b>' ) );
sendbeam_cf7_save( $sendbeam_cf7_form );
ok( '' === get_post_meta( 7, '_sendbeam_cf7', true ), 'CF7: only administrators can change where a form sends people' );
$stub['caps']['manage_options'] = true;
$_POST['sendbeam_cf7_nonce'] = 'forged';
sendbeam_cf7_save( $sendbeam_cf7_form );
ok( '' === get_post_meta( 7, '_sendbeam_cf7', true ), 'CF7: a bad nonce saves nothing' );
$_POST['sendbeam_cf7_nonce'] = 'nonce:sendbeam_cf7_save';
sendbeam_cf7_save( $sendbeam_cf7_form );
$sendbeam_saved = get_post_meta( 7, '_sendbeam_cf7', true );
ok( 1 === $sendbeam_saved['enabled'] && array( 'list-1' ) === $sendbeam_saved['lists'] && 'contact form' === $sendbeam_saved['tag'], 'CF7: the tab saves cleaned settings' );
$_POST = array();

ob_start();
sendbeam_cf7_panel( $sendbeam_cf7_form );
$sendbeam_panel = ob_get_clean();
has( $sendbeam_panel, 'name="sendbeam_cf7_nonce" value="nonce:sendbeam_cf7_save"', 'CF7: the tab carries its nonce' );
has( $sendbeam_panel, 'name="sendbeam_cf7[enabled]" value="1" checked="checked"', 'CF7: the tab shows the form is switched on' );

// The editor tab may ask SendBeam for the lists; a visitor's submission must not call it at all.
$stub['remote'] = array();
WPCF7_Submission::$posted = array( 'your-email' => 'ada@example.test', 'your-name' => 'Ada Lovelace', 'subscribe' => array() );
sendbeam_cf7_submit( $sendbeam_cf7_form );
ok( empty( $stub['cron'] ), 'CF7: nothing ticked in the consent field, nothing sent' );
WPCF7_Submission::$posted['subscribe'] = array( 'Yes' );
sendbeam_cf7_submit( $sendbeam_cf7_form );
ok( 1 === count( $stub['cron'] ) && 'sendbeam_bridge_subscribe' === $stub['cron'][0]['hook'], 'CF7: a ticked consent field queues one subscription' );
$sendbeam_job = $stub['cron'][0]['args'][0];
ok( 'ada@example.test' === $sendbeam_job['email'] && 'Ada' === $sendbeam_job['first_name'] && 'Lovelace' === $sendbeam_job['last_name'], 'CF7: one name field is split into first and last' );
ok( 'contact-form-7' === $sendbeam_job['source'] && array( 'list-1' ) === $sendbeam_job['lists'] && 'contact form' === $sendbeam_job['tag'], 'CF7: source, list and tag travel with it' );
ok( empty( $stub['remote'] ), 'CF7: nothing is sent to SendBeam while the visitor waits' );

update_post_meta( 7, '_sendbeam_cf7', array_merge( $sendbeam_saved, array( 'consent' => 'signup' ) ) );
$stub['cron'] = array();
WPCF7_Submission::$posted = array( 'your-email' => 'grace@example.test', 'your-name' => 'Grace' );
sendbeam_cf7_submit( $sendbeam_cf7_form );
ok( 1 === count( $stub['cron'] ), 'CF7: a form marked as a signup form sends without a consent field' );
$stub['cron'] = array();
WPCF7_Submission::$posted = array( 'your-email' => 'not an address', 'your-name' => 'Nobody' );
sendbeam_cf7_submit( $sendbeam_cf7_form );
ok( empty( $stub['cron'] ), 'CF7: an invalid address is never queued' );

// The queued job: contact, list, tag (created on first use).
$stub['remote']       = array();
$stub['remote_reply'] = function ( $url, $args ) {
	$path   = parse_url( $url, PHP_URL_PATH );
	$method = isset( $args['body'] ) ? 'POST' : 'GET';
	if ( 'POST' === $method && '/api/v1/contacts' === $path ) { return array( 'response' => array( 'code' => 201 ), 'body' => '{"contact":{"id":"c-1"}}' ); }
	if ( 'GET' === $method && '/api/v1/tags' === $path ) { return array( 'response' => array( 'code' => 200 ), 'body' => '{"tags":[{"id":"t-9","name":"Other"}]}' ); }
	if ( 'POST' === $method && '/api/v1/tags' === $path ) { return array( 'response' => array( 'code' => 201 ), 'body' => '{"tag":{"id":"t-1","name":"contact form"}}' ); }
	return array( 'response' => array( 'code' => 201 ), 'body' => '{}' );
};
sendbeam_bridge_run( $sendbeam_job );
$sendbeam_calls = array_map( function ( $r ) { return $r['method'] . ' ' . parse_url( $r['url'], PHP_URL_PATH ); }, $stub['remote'] );
ok( array( 'POST /api/v1/contacts', 'POST /api/v1/lists/list-1/contacts', 'GET /api/v1/tags', 'POST /api/v1/tags', 'POST /api/v1/contacts/c-1/tags' ) === $sendbeam_calls, 'the queued job creates the contact, joins the list, creates the tag and tags the contact — got ' . implode( ', ', $sendbeam_calls ) );
$sendbeam_body = json_decode( $stub['remote'][0]['args']['body'], true );
ok( 'contact-form-7' === $sendbeam_body['source'] && 'Ada' === $sendbeam_body['first_name'] && 'Lovelace' === $sendbeam_body['last_name'], 'the contact carries its name and source' );
ok( array( 'tag_id' => 't-1' ) === json_decode( $stub['remote'][4]['args']['body'], true ), 'the contact gets the new tag' );
ok( 1 === get_option( 'sendbeam_sync_log' )[0]['ok'] && 'contact-form-7' === get_option( 'sendbeam_sync_log' )[0]['source'], 'the attempt is logged under Recent subscriptions' );

// An existing tag is reused, not created again.
$stub['remote'] = array();
sendbeam_bridge_run( array_merge( $sendbeam_job, array( 'tag' => 'OTHER', 'lists' => array() ) ) );
$sendbeam_calls = array_map( function ( $r ) { return $r['method'] . ' ' . parse_url( $r['url'], PHP_URL_PATH ); }, $stub['remote'] );
ok( array( 'POST /api/v1/contacts', 'GET /api/v1/tags', 'POST /api/v1/contacts/c-1/tags' ) === $sendbeam_calls, 'an existing tag is matched by name, whatever its case' );

// The registration, comment and checkout opt-ins still use the Audience tab's lists.
update_option( 'sendbeam_sync', array( 'lists' => array( 'list-a' ) ) );
$stub['remote'] = array();
sendbeam_subscribe( 'reg@example.test', 'Reg', '', 'wordpress-registration' );
ok( 'POST /api/v1/lists/list-a/contacts' === $stub['remote'][1]['method'] . ' ' . parse_url( $stub['remote'][1]['url'], PHP_URL_PATH ), 'opt-ins without lists of their own join the Audience tab lists' );
update_option( 'sendbeam_sync', array() );

// WPForms.
$stub['cron'] = array();
$sendbeam_wpform   = array( 'settings' => array( 'sendbeam_enabled' => '1', 'sendbeam_email_field' => '2', 'sendbeam_name_field' => '1', 'sendbeam_consent' => 'field', 'sendbeam_consent_field' => '3', 'sendbeam_list' => 'list-2', 'sendbeam_tag' => 'wpforms' ) );
$sendbeam_wpfields = array(
	1 => array( 'value' => 'Ada Lovelace', 'first' => 'Ada', 'last' => 'Lovelace' ),
	2 => array( 'value' => 'ada@example.test' ),
	3 => array( 'value' => '' ),
);
sendbeam_wpforms_submit( $sendbeam_wpfields, array(), $sendbeam_wpform, 11 );
ok( empty( $stub['cron'] ), 'WPForms: an unticked consent field sends nothing' );
$sendbeam_wpfields[3]['value'] = 'I agree to receive emails';
sendbeam_wpforms_submit( $sendbeam_wpfields, array(), $sendbeam_wpform, 11 );
$sendbeam_job = $stub['cron'][0]['args'][0];
ok( 'wpforms' === $sendbeam_job['source'] && 'Ada' === $sendbeam_job['first_name'] && 'Lovelace' === $sendbeam_job['last_name'] && array( 'list-2' ) === $sendbeam_job['lists'] && 'wpforms' === $sendbeam_job['tag'], 'WPForms: name parts, list and tag mapped' );
$stub['cron'] = array();
sendbeam_wpforms_submit( $sendbeam_wpfields, array(), array( 'settings' => array() ), 12 );
ok( empty( $stub['cron'] ), 'WPForms: a form without SendBeam switched on sends nothing' );
ob_start();
sendbeam_wpforms_panel( (object) array( 'form_data' => $sendbeam_wpform ) );
ob_end_clean();
ok( array( 'email' ) === $stub['wpforms_fields']['sendbeam_email_field']['args']['field_map'], 'WPForms: the builder offers only email fields for the address' );
ok( 'field' === $stub['wpforms_fields']['sendbeam_consent']['args']['default'], 'WPForms: consent defaults to requiring a ticked field' );

// Fluent Forms.
$stub['cron'] = array();
update_option( 'sendbeam_bridges', array( 'fluentforms' => array( '5' => array( 'enabled' => 1, 'email_field' => 'email', 'first_field' => 'names.first_name', 'last_field' => 'names.last_name', 'consent_field' => 'gdpr-agreement' ) ) ) );
$sendbeam_ff = array( 'email' => 'lin@example.test', 'names' => array( 'first_name' => 'Lin', 'last_name' => 'Wu' ) );
sendbeam_fluentforms_submit( 90, $sendbeam_ff, (object) array( 'id' => 5 ) );
ok( empty( $stub['cron'] ), 'Fluent Forms: no GDPR agreement, nothing sent' );
$sendbeam_ff['gdpr-agreement'] = 'on';
sendbeam_fluentforms_submit( 91, $sendbeam_ff, (object) array( 'id' => 5 ) );
sendbeam_fluentforms_submit( 91, $sendbeam_ff, (object) array( 'id' => 5 ) );
ok( 1 === count( $stub['cron'] ), 'Fluent Forms: one entry is sent once, even when both hook names fire' );
$sendbeam_job = $stub['cron'][0]['args'][0];
ok( 'Lin' === $sendbeam_job['first_name'] && 'Wu' === $sendbeam_job['last_name'] && 'fluent-forms' === $sendbeam_job['source'], 'Fluent Forms: nested name fields mapped' );
$stub['cron'] = array();
sendbeam_fluentforms_submit( 92, $sendbeam_ff, (object) array( 'id' => 6 ) );
ok( empty( $stub['cron'] ), 'Fluent Forms: a form not chosen on the Audience tab sends nothing' );
ok( 'names.first_name' === sendbeam_fluentforms_config( 6 )['first_field'], 'Fluent Forms: an unconfigured form starts from its default field names' );

// Elementor Pro.
$sendbeam_registrar = new Sendbeam_Test_Registrar();
sendbeam_bridge_register_elementor( $sendbeam_registrar );
ok( 1 === count( $sendbeam_registrar->actions ) && 'sendbeam' === $sendbeam_registrar->actions[0]->get_name(), 'Elementor: a SendBeam action after submit is registered' );
$sendbeam_widget = new Sendbeam_Test_Widget();
$sendbeam_registrar->actions[0]->register_settings_section( $sendbeam_widget );
ok( array( 'submit_actions' => 'sendbeam' ) === $sendbeam_widget->controls['section_sendbeam']['condition'], 'Elementor: the settings appear only when the action is chosen' );
ok( 'field' === $sendbeam_widget->controls['sendbeam_consent']['default'], 'Elementor: consent defaults to requiring a ticked field' );
$stub['cron']   = array();
$sendbeam_fields = array( 'email' => array( 'value' => 'kay@example.test' ), 'name' => array( 'value' => 'Kay' ), 'acceptance' => array( 'value' => '' ) );
$sendbeam_form_settings = array( 'sendbeam_email_field' => 'email', 'sendbeam_first_field' => 'name', 'sendbeam_consent' => 'field', 'sendbeam_consent_field' => 'acceptance', 'sendbeam_list' => 'list-3' );
$sendbeam_registrar->actions[0]->run( new Sendbeam_Test_Record( array( 'form_settings' => $sendbeam_form_settings, 'fields' => $sendbeam_fields ) ), null );
ok( empty( $stub['cron'] ), 'Elementor: an unticked Acceptance field sends nothing' );
$sendbeam_fields['acceptance']['value'] = 'on';
$sendbeam_registrar->actions[0]->run( new Sendbeam_Test_Record( array( 'form_settings' => $sendbeam_form_settings, 'fields' => $sendbeam_fields ) ), null );
ok( 1 === count( $stub['cron'] ) && 'elementor-form' === $stub['cron'][0]['args'][0]['source'] && array( 'list-3' ) === $stub['cron'][0]['args'][0]['lists'], 'Elementor: a ticked Acceptance field queues the subscription' );
$sendbeam_export = $sendbeam_registrar->actions[0]->on_export( array( 'settings' => array( 'sendbeam_list' => 'list-3', 'sendbeam_tag' => 'x', 'sendbeam_email_field' => 'email' ) ) );
ok( ! isset( $sendbeam_export['settings']['sendbeam_list'] ) && isset( $sendbeam_export['settings']['sendbeam_email_field'] ), 'Elementor: exported templates leave the workspace\'s list out' );

// Gravity Forms.
sendbeam_bridge_load_gravityforms();
sendbeam_bridge_load_gravityforms();
ok( array( 'Sendbeam_GF_Addon' ) === $stub['gf_registered'] && ! empty( $stub['gf_framework'] ), 'Gravity Forms: the feed add-on registers once, after its framework is loaded' );
$sendbeam_gf   = Sendbeam_GF_Addon::get_instance();
$sendbeam_feed = array( 'meta' => array( 'feedName' => 'SendBeam', 'sendbeamFields_email' => '2', 'sendbeamFields_first_name' => '1.3', 'sendbeamFields_last_name' => '1.6', 'sendbeamFields_consent' => '4.1', 'sendbeamList' => 'list-4', 'sendbeamTag' => '' ) );
$stub['cron']  = array();
$sendbeam_gf->process_feed( $sendbeam_feed, array( '2' => 'mo@example.test', '1.3' => 'Mo', '1.6' => 'Salah', '4.1' => '' ), array() );
ok( empty( $stub['cron'] ), 'Gravity Forms: an unticked consent field sends nothing' );
$sendbeam_entry    = array( '2' => 'mo@example.test', '1.3' => 'Mo', '1.6' => 'Salah', '4.1' => '1' );
$sendbeam_returned = $sendbeam_gf->process_feed( $sendbeam_feed, $sendbeam_entry, array() );
ok( 1 === count( $stub['cron'] ) && 'gravity-forms' === $stub['cron'][0]['args'][0]['source'] && 'Salah' === $stub['cron'][0]['args'][0]['last_name'] && array( 'list-4' ) === $stub['cron'][0]['args'][0]['lists'], 'Gravity Forms: a consenting entry is queued with its mapped fields' );
ok( $sendbeam_entry === $sendbeam_returned, 'Gravity Forms: the entry is handed back unchanged' );
$sendbeam_feed['meta']['sendbeamSignup']         = '1';
$sendbeam_feed['meta']['sendbeamFields_consent'] = '';
$stub['cron'] = array();
$sendbeam_gf->process_feed( $sendbeam_feed, array( '2' => 'mo@example.test' ), array() );
ok( 1 === count( $stub['cron'] ), 'Gravity Forms: a feed marked as a signup form needs no consent field' );
$fields_setting = $sendbeam_gf->feed_settings_fields()[0]['fields'];
ok( 'feed_condition' === end( $fields_setting )['type'], 'Gravity Forms: feeds offer conditional logic' );

// ── E-commerce events (issue #10) ────────────────────────────────────────

// Settings: defaults, clamping, and the sanitised save.
$sendbeam_defaults = sendbeam_ecommerce_settings();
ok( array( 'order_placed' => 0, 'product_viewed' => 0, 'cart_abandoned' => 0, 'cart_abandoned_window' => 60 ) === $sendbeam_defaults, 'e-commerce: defaults are everything off, 60-minute window' );
update_option( SENDBEAM_ECOMMERCE_OPTION, array( 'cart_abandoned_window' => 999999 ) );
ok( 10080 === sendbeam_ecommerce_settings()['cart_abandoned_window'], 'e-commerce: an absurd window is clamped to the 7-day maximum' );
update_option( SENDBEAM_ECOMMERCE_OPTION, array() );

ok( ! sendbeam_ecommerce_active( 'order_placed' ), 'e-commerce: nothing is active with no API key' );
update_option( 'sendbeam_settings', array( 'api_key' => 'sb_live_ecommercetest0123456789' ) );
ok( ! sendbeam_ecommerce_active( 'order_placed' ), 'e-commerce: nothing is active without WooCommerce' );

// Every hook is a genuine no-op while WooCommerce is absent — same guarantee
// tests/host-stubs.php proves for the form-plugin bridges before they load.
$stub['cron'] = array();
sendbeam_ecommerce_on_order_placed( 1 );
sendbeam_ecommerce_on_product_viewed();
sendbeam_ecommerce_on_add_to_cart( 'key', 1, 1, 0, array(), array() );
ok( empty( $stub['cron'] ), 'e-commerce: nothing is queued without WooCommerce active' );

require __DIR__ . '/woocommerce-stubs.php';
update_option( SENDBEAM_ECOMMERCE_OPTION, array( 'order_placed' => 1, 'product_viewed' => 1, 'cart_abandoned' => 1, 'cart_abandoned_window' => 60 ) );
ok( sendbeam_ecommerce_active( 'order_placed' ), 'e-commerce: active once a key is saved, the toggle is on, and WooCommerce is active' );

// Order placed.
$stub['cron']            = array();
$stub['wc_orders'][501]  = array( 'email' => 'buyer@example.test', 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'total' => 84.5, 'currency' => 'GBP' );
sendbeam_ecommerce_on_order_placed( 501 );
ok( 1 === count( $stub['cron'] ) && SENDBEAM_ECOMMERCE_HOOK === $stub['cron'][0]['hook'], 'order placed: queued as a single cron event, like every other subscription' );
$sendbeam_order_job = $stub['cron'][0]['args'][0];
ok( 'order_placed' === $sendbeam_order_job['type'] && 'buyer@example.test' === $sendbeam_order_job['email'] && 'Ada Lovelace' === $sendbeam_order_job['name'] && 84.5 === $sendbeam_order_job['value'] && 'GBP' === $sendbeam_order_job['currency'], 'order placed: name, total and currency are read from the order' );

$stub['remote']       = array();
$stub['remote_reply'] = array( 'response' => array( 'code' => 200 ), 'body' => '{"ok":true}' );
sendbeam_ecommerce_run_event( $sendbeam_order_job );
ok( 1 === count( $stub['remote'] ) && false !== strpos( $stub['remote'][0]['url'], '/api/v1/ecommerce/events' ), 'order placed: the queued job posts to the ecommerce events endpoint' );
$sendbeam_posted = json_decode( $stub['remote'][0]['args']['body'], true );
ok( 'order_placed' === $sendbeam_posted['type'] && 84.5 === $sendbeam_posted['value'], 'order placed: the posted body carries the type and value' );
$sendbeam_log = get_option( SENDBEAM_ECOMMERCE_LOG );
ok( 1 === $sendbeam_log[0]['ok'] && 'order_placed' === $sendbeam_log[0]['type'], 'order placed: a successful attempt is logged' );

$stub['cron'] = array();
sendbeam_ecommerce_on_order_placed( 999 ); // no such order
ok( empty( $stub['cron'] ), 'order placed: an order that cannot be loaded queues nothing' );

// Product viewed: nobody known, then a logged-in customer.
$stub['cron']    = array();
$GLOBALS['product'] = new WC_Product( 'Mechanical Keyboard' );
sendbeam_ecommerce_on_product_viewed();
ok( empty( $stub['cron'] ), 'product viewed: an unknown visitor triggers nothing — no anonymous tracking' );
$stub['current_user'] = array( 'email' => 'known@example.test' );
sendbeam_ecommerce_on_product_viewed();
ok( 1 === count( $stub['cron'] ) && 'product_viewed' === $stub['cron'][0]['args'][0]['type'] && 'known@example.test' === $stub['cron'][0]['args'][0]['email'] && 'Mechanical Keyboard' === $stub['cron'][0]['args'][0]['name'], 'product viewed: a logged-in customer is sent with the product name' );
unset( $stub['current_user'] );

// Cart abandoned: add to cart starts tracking; the scheduled check fires
// only when no order has followed and an email is known.
$stub['cron']                    = array();
$stub['transients']              = array();
WC()->session->customer_id       = 'session-abc';
WC()->customer->billing_email    = '';
sendbeam_ecommerce_on_add_to_cart( 'key', 1, 1, 0, array(), array() );
$sendbeam_tracking_key = sendbeam_cart_tracking_key( 'session-abc' );
ok( is_array( get_transient( $sendbeam_tracking_key ) ) && '' === get_transient( $sendbeam_tracking_key )['email'], 'cart abandoned: adding to cart starts tracking, email unknown yet' );
ok( 1 === count( $stub['cron'] ) && SENDBEAM_CART_CHECK_HOOK === $stub['cron'][0]['hook'] && array( $sendbeam_tracking_key ) === $stub['cron'][0]['args'], 'cart abandoned: a single check is scheduled, keyed by this cart' );

sendbeam_ecommerce_on_add_to_cart( 'key2', 2, 1, 0, array(), array() );
ok( 1 === count( $stub['cron'] ), 'cart abandoned: a second item in the same session does not schedule a second check' );

// Reached checkout and the email is now known: refresh fills it in.
WC()->customer->billing_email = 'shopper@example.test';
sendbeam_ecommerce_refresh_cart_email();
ok( 'shopper@example.test' === get_transient( $sendbeam_tracking_key )['email'], 'cart abandoned: an email entered at checkout is captured before the cart is checked' );

// No order followed: the check fires cart_abandoned.
$stub['cron']                = array();
$stub['wc_completed_orders'] = array();
sendbeam_ecommerce_check_cart_abandonment( $sendbeam_tracking_key );
ok( false === get_transient( $sendbeam_tracking_key ), 'cart abandoned: tracking is cleared once checked' );
ok( 1 === count( $stub['cron'] ) && 'cart_abandoned' === $stub['cron'][0]['args'][0]['type'] && 'shopper@example.test' === $stub['cron'][0]['args'][0]['email'], 'cart abandoned: fires once, for the known email, when no order followed' );

// An order DID follow: no event.
WC()->session->customer_id = 'session-xyz';
sendbeam_ecommerce_on_add_to_cart( 'key3', 3, 1, 0, array(), array() );
$sendbeam_tracking_key2      = sendbeam_cart_tracking_key( 'session-xyz' );
$sendbeam_tracked            = get_transient( $sendbeam_tracking_key2 );
$sendbeam_tracked['email']   = 'finished@example.test';
set_transient( $sendbeam_tracking_key2, $sendbeam_tracked, 3600 );
$stub['wc_completed_orders'] = array( array( 'email' => 'finished@example.test', 'created_at' => $sendbeam_tracked['started_at'] + 60, 'id' => 1 ) );
$stub['cron']                = array();
sendbeam_ecommerce_check_cart_abandonment( $sendbeam_tracking_key2 );
ok( empty( $stub['cron'] ), 'cart abandoned: an order placed after the cart started means no abandonment event' );

// Never a known email: nothing to send, ever.
$stub['cron'] = array();
set_transient( 'sendbeam_cart_noone', array( 'email' => '', 'started_at' => time() - 3600 ), 3600 );
sendbeam_ecommerce_check_cart_abandonment( 'sendbeam_cart_noone' );
ok( empty( $stub['cron'] ), 'cart abandoned: a cart nobody\'s email was ever known for sends nothing' );

// Deactivation clears the scheduled hooks (uninstall.php sweeps the rest).
$stub['cron'] = array( array( 'hook' => SENDBEAM_CART_CHECK_HOOK, 'args' => array( 'x' ) ), array( 'hook' => 'something_else', 'args' => array() ) );
sendbeam_ecommerce_clear_scheduled();
ok( 1 === count( $stub['cron'] ) && 'something_else' === $stub['cron'][0]['hook'], 'deactivation: only this plugin\'s own scheduled checks are cleared' );

// One version number, five files. 1.6.2 shipped with the block's asset
// version still on 1.6.1, which is how WordPress decides whether the editor
// may reuse a cached copy of the block script.
$sendbeam_root = dirname( __DIR__ );
preg_match( "/^ \\* Version:\\s+(\\S+)/m", file_get_contents( $sendbeam_root . '/sendbeam.php' ), $m );
$declared = $m[1];
$sendbeam_versions = array(
	'sendbeam.php SENDBEAM_VERSION' => SENDBEAM_VERSION,
	'readme.txt Stable tag'         => ( preg_match( '/^Stable tag: (\\S+)/m', file_get_contents( $sendbeam_root . '/readme.txt' ), $m2 ) ? $m2[1] : '' ),
	'block.json version'            => ( json_decode( file_get_contents( $sendbeam_root . '/blocks/form/block.json' ), true )['version'] ?? '' ),
	'index.asset.php version'       => ( ( require $sendbeam_root . '/blocks/form/index.asset.php' )['version'] ?? '' ),
);
foreach ( $sendbeam_versions as $where => $found ) {
	ok( $found === $declared, "version $declared matches $where" . ( $found === $declared ? '' : " (found $found)" ) );
}

// The newest changelog entry is the version being shipped. Search from the
// Changelog heading down: readme.txt uses "= … =" for its FAQ headings too.
$sendbeam_readme = file_get_contents( $sendbeam_root . '/readme.txt' );
$sendbeam_log    = substr( $sendbeam_readme, (int) strpos( $sendbeam_readme, '== Changelog ==' ) );
preg_match( '/^= (\\S+) =$/m', $sendbeam_log, $m3 );
ok( ( $m3[1] ?? '' ) === $declared, "readme.txt changelog opens with $declared" );

echo "smoke: $pass checks passed\n";
