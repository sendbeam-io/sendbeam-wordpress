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

// ── A site with no key anywhere ────────────────────────────────────────
// Everything from here on runs with SENDBEAM_API_KEY defined in wp-config.php,
// because that is what the status and disconnect tests are about — and a key
// pinned there cannot be un-defined again inside one process. These run first:
// a site with no key at all is the site the Connect button exists for.
//
// The helpers below are declared further down the file; PHP binds top-level
// functions before the first line runs, so they are callable from here.
sb_connect_reset();
ob_start();
sendbeam_connect_panel();
$sb_panel = ob_get_clean();
has( $sb_panel, 'Connect SendBeam', 'panel: a site with no key gets the button' );
has( $sb_panel, 'name="sendbeam_popup" value="0"', 'panel: the form defaults to "this tab" until the script says otherwise' );
has( $sb_panel, 'example-site.test', 'panel: the domain permission names this site\'s domain' );

$sb_ov = sb_overview( array(), array() );
has( $sb_ov, 'Connect SendBeam', 'overview: an unconnected site gets the button' );
has( $sb_ov, 'I already have an API key', 'overview: pasting a key is still possible' );
lacks( $sb_ov, 'Check now', 'overview: nothing offers to check a domain that does not exist' );
lacks( $sb_ov, 'value="sendbeam_disconnect"', 'overview: nothing offers to disconnect what is not connected' );
has( $sb_ov, 'Connect the site first', 'overview: the domain step says to connect first' );

sb_connect_reset();
sendbeam_connect_forget_status();
delete_transient( 'sendbeam_connection' );
delete_transient( 'sendbeam_remote_forms' );
delete_transient( 'sendbeam_remote_lists' );
delete_transient( 'sendbeam_subscriber_count' );
update_option( 'sendbeam_settings', array() );

// wp-config constant wins over the option.
define( 'SENDBEAM_API_KEY', 'sb_const_0123456789abcdef' );
ok( sendbeam_api_key() === 'sb_const_0123456789abcdef', 'constant wins' );

// ── Connect ────────────────────────────────────────────────────────────
//
// The plugin is the client: it mints the state, sends the person to
// sendbeam.io, and swaps a single-use grant for a key server-to-server. The
// SendBeam end is stubbed here — what is being proved is that this end builds
// exactly the URL the contract specifies, refuses anything whose state does
// not come back unchanged, and never writes a key by any path other than the
// one a pasted key goes through.

/**
 * Drive a Connect handler to the end WordPress would exit at, and return
 * whatever it rendered.
 *
 * @param callable $fn The handler.
 * @return string
 */
function sb_connect_run( $fn ) {
	$GLOBALS['stub']['throw_on']['sendbeam_connect_result'] = true;
	ob_start();
	try {
		$fn();
	} catch ( SendBeamStubExit $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- WordPress would have exited here.
		$GLOBALS['stub']['exit'] = $e->getMessage();
	}
	$html = ob_get_clean();
	unset( $GLOBALS['stub']['throw_on']['sendbeam_connect_result'] );
	return $html;
}

/** Put the world back to a signed-in administrator on an https site. */
function sb_connect_reset() {
	$GLOBALS['stub']['caps']['manage_options'] = true;
	$GLOBALS['stub']['user_id']                = 7;
	$GLOBALS['stub']['current_user']           = array( 'email' => 'admin@example-site.test' );
	$GLOBALS['stub']['site_url']               = 'https://www.example-site.test';
	$GLOBALS['stub']['bloginfo']               = array();
	$GLOBALS['stub']['remote']                 = array();
	$GLOBALS['stub']['remote_reply']           = null;
	$GLOBALS['stub']['redirect']               = null;
	$GLOBALS['stub']['exit']                   = '';
	$_GET                                      = array();
	$_POST                                     = array();
	$_REQUEST                                  = array();
	delete_transient( 'sendbeam_connect_7' );
	delete_transient( 'sendbeam_connect_9' );
}

// Only the scopes we offer survive, and `forms` is not optional.
ok( sendbeam_connect_clean_scopes( array( 'transactional:send', 'sudo' ) ) === array( 'forms', 'transactional:send' ), 'connect: unknown scopes are dropped and forms is always asked for' );
ok( sendbeam_connect_clean_scopes( null ) === array( 'forms' ), 'connect: ticking nothing still asks for forms' );
ok( array_keys( sendbeam_connect_scopes() ) === array( 'forms', 'contacts:write', 'transactional:send', 'ecommerce', 'domain' ), 'connect: the five scopes, in the order the consent page shows them' );

// The domain scope names the domain, not "your domain": a site owner has to
// recognise it to decide whether to tick it.
$GLOBALS['stub']['site_url'] = 'https://www.example-site.test';
ok( 'example-site.test' === sendbeam_connect_site_host(), 'connect: the sending domain is the site host with www. stripped' );
has( sendbeam_connect_scopes()['domain']['label'], 'example-site.test', 'connect: the domain scope says which domain it means' );
ok( ! sendbeam_connect_scopes()['domain']['always'], 'connect: the domain scope can be unticked' );
ok( sendbeam_connect_clean_scopes( array( 'domain' ) ) === array( 'forms', 'domain' ), 'connect: domain survives the scope clean-up' );
$GLOBALS['stub']['site_url'] = 'https://shop.example.co.uk';
ok( 'shop.example.co.uk' === sendbeam_connect_site_host(), 'connect: a host with no www. is left alone' );
unset( $GLOBALS['stub']['site_url'] );

// https everywhere, with a hole only for machines that cannot be reached
// from the internet. An http origin on a real domain must be refused, or the
// grant travels in clear.
ok( sendbeam_connect_origin_ok( 'https://example.com' ), 'connect: https is allowed' );
ok( sendbeam_connect_origin_ok( 'http://localhost:8080' ), 'connect: http on localhost is allowed for dev boxes' );
ok( sendbeam_connect_origin_ok( 'http://127.0.0.1' ), 'connect: http on 127.0.0.1 is allowed' );
ok( sendbeam_connect_origin_ok( 'http://wp.test' ), 'connect: http on a .test host is allowed' );
ok( sendbeam_connect_origin_ok( 'http://site.local' ), 'connect: http on a .local host is allowed' );
ok( ! sendbeam_connect_origin_ok( 'http://example.com' ), 'connect: plain http on a real domain is refused' );
ok( ! sendbeam_connect_origin_ok( 'ftp://example.com' ), 'connect: a non-http scheme is refused' );
ok( ! sendbeam_connect_origin_ok( 'nonsense' ), 'connect: a value that is not a URL is refused' );

// Starting: the exact URL from the contract, every parameter.
sb_connect_reset();
$GLOBALS['stub']['bloginfo']['name'] = str_repeat( 'Ä', 100 );   // over the 80 the contract allows.
$_POST                               = array(
	'sendbeam_scopes' => array( 'transactional:send', 'not-a-scope' ),
	'_wpnonce'        => 'nonce:sendbeam_connect_start',
);
$_REQUEST                            = $_POST;
try {
	sendbeam_connect_start();
} catch ( SendBeamStubExit $e ) {
	$GLOBALS['stub']['exit'] = $e->getMessage();
}
ok( 'wp_redirect' === $GLOBALS['stub']['exit'], 'connect: start redirects the pop-up' );
$sendbeam_start = (string) $GLOBALS['stub']['redirect']['url'];
ok( 302 === $GLOBALS['stub']['redirect']['status'], 'connect: start redirects with a 302' );
ok( 0 === strpos( $sendbeam_start, 'https://sendbeam.io/connect/wordpress?' ), "connect: start goes to the contract's page — got $sendbeam_start" );
parse_str( (string) wp_parse_url( $sendbeam_start, PHP_URL_QUERY ), $sendbeam_q );
ok( 'https://www.example-site.test' === ( $sendbeam_q['site_url'] ?? '' ), 'connect: site_url is the origin, with no path' );
ok( mb_strlen( $sendbeam_q['site_name'] ?? '' ) === 80, 'connect: site_name is clipped to 80 characters' );
ok( 'admin@example-site.test' === ( $sendbeam_q['email'] ?? '' ), "connect: the admin's address is sent to prefill signup" );
ok( 'forms,transactional:send' === ( $sendbeam_q['scopes'] ?? '' ), 'connect: scopes is the approved csv, forms first' );
ok( 'https://www.example-site.test/wp-admin/admin-post.php?action=sendbeam_connect_return' === ( $sendbeam_q['return_to'] ?? '' ), 'connect: return_to is this site, on the site_url origin' );
ok( 1 === preg_match( '/^[A-Za-z0-9_\-]{32,}$/', $sendbeam_q['state'] ?? '' ), 'connect: state is 32+ characters of [A-Za-z0-9_-]' );
ok( 43 === strlen( $sendbeam_q['state'] ), 'connect: state is 43 characters' );

$sendbeam_state   = $sendbeam_q['state'];
$sendbeam_stored  = get_transient( 'sendbeam_connect_7' );
ok( is_array( $sendbeam_stored ) && $sendbeam_stored['state'] === $sendbeam_state, 'connect: the state is remembered against the admin who started it' );
ok( $sendbeam_stored['scopes'] === array( 'forms', 'transactional:send' ), 'connect: the approved scopes are remembered too' );

// Two admins connecting at once must not share a state.
$GLOBALS['stub']['user_id'] = 9;
try {
	sendbeam_connect_start();
} catch ( SendBeamStubExit $e ) {
	$GLOBALS['stub']['exit'] = $e->getMessage();
}
ok( get_transient( 'sendbeam_connect_9' )['state'] !== $sendbeam_state, 'connect: a second administrator gets their own state, not the first one\'s' );
delete_transient( 'sendbeam_connect_9' );

// A site with no https address cannot be connected at all.
sb_connect_reset();
$GLOBALS['stub']['site_url'] = 'http://insecure.example.com';
$_POST                       = array( '_wpnonce' => 'nonce:sendbeam_connect_start' );
$_REQUEST                    = $_POST;
try {
	sendbeam_connect_start();
} catch ( SendBeamStubExit $e ) {
	$GLOBALS['stub']['exit'] = $e->getMessage();
}
ok( 0 === strpos( $GLOBALS['stub']['exit'], 'wp_die' ), 'connect: an http-only site is refused rather than sent off insecurely' );
ok( null === $GLOBALS['stub']['redirect'], 'connect: nothing is sent to SendBeam from an http-only site' );
ok( false === get_transient( 'sendbeam_connect_7' ), 'connect: an http-only site is not left holding a connection it can never finish' );

// Without the nonce, nothing starts.
sb_connect_reset();
$_POST    = array();
$_REQUEST = array();
try {
	sendbeam_connect_start();
} catch ( SendBeamStubExit $e ) {
	$GLOBALS['stub']['exit'] = $e->getMessage();
}
ok( 0 === strpos( $GLOBALS['stub']['exit'], 'wp_die' ), 'connect: start refuses a request with no nonce' );
ok( false === get_transient( 'sendbeam_connect_7' ), 'connect: a refused start stores no state' );

// ── Coming back ────────────────────────────────────────────────────────
$sendbeam_good_state = str_repeat( 'a', 43 );
$sendbeam_good_grant = str_repeat( 'g', 43 );
$sendbeam_new_key    = 'sb_live_' . str_repeat( 'k', 32 );

/** Arm a pending connection for user 7. */
function sb_connect_pending( $state ) {
	set_transient( 'sendbeam_connect_7', array( 'state' => $state, 'scopes' => array( 'forms' ) ), 900 );
}

// A state that is not the one we minted: no request, nothing changed.
sb_connect_reset();
update_option( 'sendbeam_settings', array( 'api_key' => 'sb_live_existingexistingexisting' ) );
sb_connect_pending( $sendbeam_good_state );
$_GET  = array( 'state' => str_repeat( 'b', 43 ), 'grant' => $sendbeam_good_grant );
$sendbeam_html = sb_connect_run( 'sendbeam_connect_return' );
has( $sendbeam_html, 'Not connected', 'connect: a mismatched state is not connected' );
ok( empty( $GLOBALS['stub']['remote'] ), 'connect: a mismatched state makes no request to SendBeam' );
ok( false === get_transient( 'sendbeam_connect_7' ), 'connect: the state is spent even when it did not match' );
ok( 'sb_live_existingexistingexisting' === sendbeam_settings()['api_key'], 'connect: a mismatched state leaves the saved key alone' );
lacks( $sendbeam_html, 'postMessage', 'connect: a failure does not tell the opener it succeeded' );

// No state at all — a bare visit to the return URL.
sb_connect_reset();
sb_connect_pending( $sendbeam_good_state );
$_GET          = array();
$sendbeam_html = sb_connect_run( 'sendbeam_connect_return' );
has( $sendbeam_html, 'Not connected', 'connect: a bare visit to the return URL is not connected' );
ok( empty( $GLOBALS['stub']['remote'] ), 'connect: a bare visit makes no request' );

// Refused on the consent page.
sb_connect_reset();
sb_connect_pending( $sendbeam_good_state );
$_GET          = array( 'state' => $sendbeam_good_state, 'error' => 'denied' );
$sendbeam_html = sb_connect_run( 'sendbeam_connect_return' );
has( $sendbeam_html, 'Not connected', 'connect: refusing on the consent page is not connected' );
ok( empty( $GLOBALS['stub']['remote'] ), 'connect: refusing makes no request to exchange anything' );
ok( false === get_transient( 'sendbeam_connect_7' ), 'connect: the state is spent after a refusal' );
ok( 'sb_live_existingexistingexisting' === sendbeam_settings()['api_key'], 'connect: refusing leaves the saved key alone' );

// The happy path: the grant is exchanged, server to server, exactly once.
sb_connect_reset();
update_option( 'sendbeam_settings', array( 'api_key' => 'sb_live_existingexistingexisting', 'default_form' => $form ) );
sb_connect_pending( $sendbeam_good_state );
$GLOBALS['stub']['remote_reply'] = array(
	'response' => array( 'code' => 200 ),
	'body'     => json_encode(
		array(
			'api_key'    => $sendbeam_new_key,
			'key_prefix' => 'sb_live_kkkk',
			'workspace'  => array( 'id' => 'ws_1', 'name' => 'Example Site' ),
			'scopes'     => array( 'forms' ),
		)
	),
);
$_GET          = array( 'state' => $sendbeam_good_state, 'grant' => $sendbeam_good_grant );
$sendbeam_html = sb_connect_run( 'sendbeam_connect_return' );

ok( 1 === count( $GLOBALS['stub']['remote'] ), 'connect: exactly one call is made to exchange the grant' );
$sendbeam_req = $GLOBALS['stub']['remote'][0];
ok( 'POST' === $sendbeam_req['method'], 'connect: the exchange is a POST' );
ok( 'https://sendbeam.io/api/v1/connect/exchange' === $sendbeam_req['url'], 'connect: the exchange goes to the contract URL' );
ok( 'application/json' === $sendbeam_req['args']['headers']['Content-Type'], 'connect: the exchange sends JSON' );
ok( ! isset( $sendbeam_req['args']['headers']['x-api-key'] ), 'connect: the exchange carries no API key — the grant is the credential' );
ok( ! isset( $sendbeam_req['args']['sslverify'] ), 'connect: certificate verification is left at the WordPress default' );
ok( 15 === $sendbeam_req['args']['timeout'], 'connect: the exchange has a bounded timeout' );
$sendbeam_body = json_decode( $sendbeam_req['args']['body'], true );
ok(
	$sendbeam_body === array( 'grant' => $sendbeam_good_grant, 'state' => $sendbeam_good_state, 'site_url' => 'https://www.example-site.test' ),
	'connect: the exchange body is exactly grant, state and site_url — got ' . $sendbeam_req['args']['body']
);

ok( $sendbeam_new_key === sendbeam_settings()['api_key'], 'connect: the key SendBeam minted is saved' );
ok( 'connect' === sendbeam_settings()['sendbeam_connected_via'], 'connect: the connection is marked as made through Connect' );
ok( 'Example Site' === sendbeam_settings()['sendbeam_connect_workspace'], 'connect: the workspace name is remembered for the screen' );
ok( $form === sendbeam_settings()['default_form'], 'connect: storing the key leaves the other tabs\' settings alone' );
ok( sendbeam_connected_via_connect(), 'connect: the screen can tell this was the button' );
ok( false === get_transient( 'sendbeam_connect_7' ), 'connect: the state is spent after a success' );
ok( false === get_transient( SENDBEAM_CACHE_CONN ), 'connect: the cached connection state is dropped, so the screen re-checks' );

has( $sendbeam_html, 'Connected', 'connect: the pop-up says it worked' );
has( $sendbeam_html, 'postMessage', 'connect: the pop-up tells the opener to reload' );
has( $sendbeam_html, '"https:\/\/www.example-site.test"', 'connect: the message is addressed to this site\'s origin, not "*"' );
has( $sendbeam_html, 'window.close()', 'connect: the pop-up closes itself' );
lacks( $sendbeam_html, $sendbeam_new_key, 'connect: the key is never rendered into the pop-up' );

// A replay of the same return URL: the grant is gone, so nothing happens.
$GLOBALS['stub']['remote'] = array();
$sendbeam_html             = sb_connect_run( 'sendbeam_connect_return' );
has( $sendbeam_html, 'Not connected', 'connect: replaying the return URL is not connected' );
ok( empty( $GLOBALS['stub']['remote'] ), 'connect: a replayed return URL makes no request' );

// Pasting a key by hand afterwards must stop the screen claiming it was the button.
$sendbeam_pasted = sendbeam_sanitize_settings( array( '_tab' => 'connect', 'api_key' => 'sb_live_pastedpastedpastedpasted' ) );
ok( '' === $sendbeam_pasted['sendbeam_connected_via'], 'connect: a key pasted by hand clears the Connect marker' );
ok( '' === $sendbeam_pasted['sendbeam_connect_workspace'], 'connect: a key pasted by hand clears the workspace name' );
$sendbeam_removed = sendbeam_sanitize_settings( array( '_tab' => 'connect', 'api_key_remove' => '1' ) );
ok( '' === $sendbeam_removed['api_key'] && '' === $sendbeam_removed['sendbeam_connected_via'], 'connect: Disconnect forgets the key and the marker together' );

// SendBeam says the grant is spent or expired.
foreach ( array(
	410 => array( '{"error":"grant_expired_or_used"}', 'already been used' ),
	400 => array( '{"error":"invalid"}', 'refused' ),
	429 => array( '{"error":"rate_limited"}', 'rate-limiting' ),
	500 => array( 'not json at all', 'refused' ),
) as $sendbeam_code => $sendbeam_case ) {
	sb_connect_reset();
	update_option( 'sendbeam_settings', array( 'api_key' => 'sb_live_existingexistingexisting' ) );
	sb_connect_pending( $sendbeam_good_state );
	$GLOBALS['stub']['remote_reply'] = array( 'response' => array( 'code' => $sendbeam_code ), 'body' => $sendbeam_case[0] );
	$_GET                            = array( 'state' => $sendbeam_good_state, 'grant' => $sendbeam_good_grant );
	$sendbeam_html                   = sb_connect_run( 'sendbeam_connect_return' );

	has( $sendbeam_html, 'Not connected', "connect: HTTP $sendbeam_code is not connected" );
	has( $sendbeam_html, $sendbeam_case[1], "connect: HTTP $sendbeam_code says why in plain words" );
	ok( 'sb_live_existingexistingexisting' === sendbeam_settings()['api_key'], "connect: HTTP $sendbeam_code leaves the saved key untouched" );
	ok( '' === sendbeam_settings()['sendbeam_connected_via'], "connect: HTTP $sendbeam_code does not mark the site as connected" );
	ok( false === get_transient( 'sendbeam_connect_7' ), "connect: HTTP $sendbeam_code spends the state" );
	lacks( $sendbeam_html, 'postMessage', "connect: HTTP $sendbeam_code does not tell the opener it succeeded" );
}

// The server could not be reached at all.
sb_connect_reset();
update_option( 'sendbeam_settings', array( 'api_key' => 'sb_live_existingexistingexisting' ) );
sb_connect_pending( $sendbeam_good_state );
$GLOBALS['stub']['remote_reply'] = new WP_Error( 'http_request_failed', 'cURL error 28' );
$_GET                            = array( 'state' => $sendbeam_good_state, 'grant' => $sendbeam_good_grant );
$sendbeam_html                   = sb_connect_run( 'sendbeam_connect_return' );
has( $sendbeam_html, 'could not reach sendbeam.io', 'connect: an unreachable server is reported plainly' );
ok( 'sb_live_existingexistingexisting' === sendbeam_settings()['api_key'], 'connect: an unreachable server leaves the saved key untouched' );

// Something that is not a key must never be written to the option.
sb_connect_reset();
update_option( 'sendbeam_settings', array( 'api_key' => 'sb_live_existingexistingexisting' ) );
sb_connect_pending( $sendbeam_good_state );
$GLOBALS['stub']['remote_reply'] = array( 'response' => array( 'code' => 200 ), 'body' => '{"api_key":"nope nope <script>","workspace":{"name":"X"}}' );
$_GET                            = array( 'state' => $sendbeam_good_state, 'grant' => $sendbeam_good_grant );
$sendbeam_html                   = sb_connect_run( 'sendbeam_connect_return' );
has( $sendbeam_html, 'Not connected', 'connect: a reply that is not a key is not a connection' );
ok( 'sb_live_existingexistingexisting' === sendbeam_settings()['api_key'], 'connect: a reply that is not a key never reaches the option' );

// A grant that is not shaped like one is never sent anywhere.
sb_connect_reset();
sb_connect_pending( $sendbeam_good_state );
$_GET          = array( 'state' => $sendbeam_good_state, 'grant' => 'short' );
$sendbeam_html = sb_connect_run( 'sendbeam_connect_return' );
has( $sendbeam_html, 'Not connected', 'connect: a malformed grant is not connected' );
ok( empty( $GLOBALS['stub']['remote'] ), 'connect: a malformed grant is never sent to SendBeam' );

// Someone without the capability gets nowhere, nonce or no nonce.
sb_connect_reset();
$GLOBALS['stub']['caps']['manage_options'] = false;
sb_connect_pending( $sendbeam_good_state );
$_GET = array( 'state' => $sendbeam_good_state, 'grant' => $sendbeam_good_grant );
sb_connect_run( 'sendbeam_connect_return' );
ok( 0 === strpos( $GLOBALS['stub']['exit'], 'wp_die' ), 'connect: the return handler still checks the capability' );
ok( empty( $GLOBALS['stub']['remote'] ), 'connect: a user without the capability exchanges nothing' );
ok( is_array( get_transient( 'sendbeam_connect_7' ) ), 'connect: a refused caller does not spend somebody else\'s state' );
delete_transient( 'sendbeam_connect_7' );

// ── Connect v2: what the exchange sets up, and what it refuses to ──────
/**
 * The v2 exchange body, with the parts each test wants overridden.
 *
 * @param array $over Keys to replace.
 * @return string JSON.
 */
function sb_v2_body( $over = array() ) {
	global $sendbeam_new_key, $form;
	$body = array(
		'api_key'      => $sendbeam_new_key,
		'key_prefix'   => 'sb_live_kkkk',
		'workspace'    => array( 'id' => 'ws_1', 'name' => 'Harbour Lane' ),
		'scopes'       => array( 'forms', 'contacts:write', 'transactional:send', 'domain' ),
		'default_form' => array( 'id' => $form, 'name' => 'Newsletter signup' ),
		'domain'       => array(
			'name'               => 'harbourlane.co.uk',
			'verified'           => false,
			'records'            => array(
				array( 'type' => 'TXT', 'name' => '_sendbeam', 'value' => 'v=sb1 k=abc' ),
				array( 'type' => 'cname', 'name' => 'sb1._domainkey', 'value' => 'sb1.dkim.sendbeam.io' ),
			),
			'domain_connect_url' => 'https://sendbeam.io/domain-connect/xyz',
			'checked_at'         => null,
		),
		'sender'       => array( 'from_name' => 'Harbour Lane Roasters', 'from_email' => 'hello@harbourlane.co.uk' ),
	);
	foreach ( $over as $k => $v ) {
		$body[ $k ] = $v;
	}
	return json_encode( $body );
}

/** Drive the return handler with one exchange reply. */
function sb_v2_exchange( $body, $settings = array() ) {
	global $sendbeam_good_state, $sendbeam_good_grant;
	sb_connect_reset();
	sendbeam_connect_forget_status();
	update_option( 'sendbeam_settings', $settings );
	sb_connect_pending( $sendbeam_good_state );
	$GLOBALS['stub']['remote_reply'] = array( 'response' => array( 'code' => 200 ), 'body' => $body );
	$_GET                            = array( 'state' => $sendbeam_good_state, 'grant' => $sendbeam_good_grant );
	return sb_connect_run( 'sendbeam_connect_return' );
}

// An unverified domain: everything is stored, and site email stays off.
sb_v2_exchange( sb_v2_body() );
$v2 = sendbeam_settings();
ok( $form === $v2['default_form'], 'connect v2: the form SendBeam made becomes this site\'s default' );
ok( 'Harbour Lane Roasters' === $v2['mail_from_name'], 'connect v2: the workspace sender name fills the empty From name' );
ok( 'hello@harbourlane.co.uk' === $v2['mail_from_email'], 'connect v2: the workspace sender address fills the empty From address' );
ok( empty( $v2['mail_enabled'] ), 'connect v2: site email is NOT switched on while the domain is unverified' );
ok( ! empty( $v2['sendbeam_mail_deferred'] ), 'connect v2: site email is remembered as held back, not forgotten' );
ok( 'forms,contacts:write,transactional:send,domain' === $v2['sendbeam_connect_granted'], 'connect v2: the granted permissions are stored as the key actually carries them' );
ok( sendbeam_connect_granted( 'domain' ), 'connect v2: the screen can ask whether a permission was granted' );
ok( ! sendbeam_connect_granted( 'ecommerce' ), 'connect v2: a permission that was not granted reads false' );

$v2_status = get_transient( sendbeam_connect_status_key() );
ok( is_array( $v2_status ), 'connect v2: the status is cached for the Overview instead of being fetched again' );
ok( 'harbourlane.co.uk' === $v2_status['domain']['name'], 'connect v2: the sending domain is cached' );
ok( 2 === count( $v2_status['domain']['records'] ), 'connect v2: the DNS records are cached' );
ok( 'CNAME' === $v2_status['domain']['records'][1]['type'], 'connect v2: a record type is upper-cased for display' );
ok( 'https://sendbeam.io/domain-connect/xyz' === $v2_status['domain']['domain_connect_url'], 'connect v2: the registrar one-click link is kept' );
ok( 'Newsletter signup' === $v2_status['default_form']['name'], 'connect v2: the form name is cached for the checklist' );

// A domain-connect link pointing anywhere but SendBeam is a phishing link
// wearing the plugin's chrome. It never becomes a button.
sb_v2_exchange( sb_v2_body( array( 'domain' => array( 'name' => 'harbourlane.co.uk', 'verified' => false, 'records' => array(), 'domain_connect_url' => 'https://evil.example.com/dns' ) ) ) );
ok( '' === get_transient( sendbeam_connect_status_key() )['domain']['domain_connect_url'], 'connect v2: a domain-connect link on another host is dropped' );
sb_v2_exchange( sb_v2_body( array( 'domain' => array( 'name' => 'harbourlane.co.uk', 'verified' => false, 'records' => array(), 'domain_connect_url' => 'http://sendbeam.io/dns' ) ) ) );
ok( '' === get_transient( sendbeam_connect_status_key() )['domain']['domain_connect_url'], 'connect v2: an http domain-connect link is dropped' );

// A form ID that is not a form ID never becomes this site's default.
sb_v2_exchange( sb_v2_body( array( 'default_form' => array( 'id' => 'nonsense', 'name' => 'X' ) ) ) );
ok( '' === sendbeam_settings()['default_form'], 'connect v2: a malformed default form ID is dropped, not stored' );

// A verified domain: site email goes on, because consent asked for it.
sb_v2_exchange( sb_v2_body( array( 'domain' => array( 'name' => 'harbourlane.co.uk', 'verified' => true, 'records' => array(), 'checked_at' => '2026-09-23T15:04:05Z' ) ) ) );
$v2 = sendbeam_settings();
ok( ! empty( $v2['mail_enabled'] ), 'connect v2: site email IS switched on when the domain is already verified' );
ok( empty( $v2['sendbeam_mail_deferred'] ), 'connect v2: nothing is held back once it is on' );

// Without the transactional scope, nothing about site email is touched at all.
sb_v2_exchange( sb_v2_body( array( 'scopes' => array( 'forms', 'domain' ), 'domain' => array( 'name' => 'harbourlane.co.uk', 'verified' => true, 'records' => array() ) ) ) );
$v2 = sendbeam_settings();
ok( empty( $v2['mail_enabled'] ), 'connect v2: a verified domain does not switch on site email the key cannot send' );
ok( '' === $v2['mail_from_name'], 'connect v2: the sender is not filled in for a key that cannot send' );

// Provisioning defaults the workspace sender to hello@<sending host> when the
// workspace has none. Whatever it comes back as, it fills an empty From
// address here and never overwrites one that is set.
sb_v2_exchange( sb_v2_body( array( 'sender' => array( 'from_name' => 'Harbour Lane', 'from_email' => 'hello@mail.brand.co.uk' ) ) ) );
ok( 'hello@mail.brand.co.uk' === sendbeam_settings()['mail_from_email'], 'connect v2: the sender address SendBeam set up lands in the empty From address' );
sb_v2_exchange( sb_v2_body( array( 'sender' => array( 'from_name' => 'Harbour Lane', 'from_email' => 'not an address' ) ) ) );
ok( '' === sendbeam_settings()['mail_from_email'], 'connect v2: something that is not an address never becomes the From address' );

// A form the site already chose is never repointed: an embed may be live on a page.
sb_v2_exchange( sb_v2_body(), array( 'default_form' => $other ) );
ok( $other === sendbeam_settings()['default_form'], 'connect v2: reconnecting does not repoint a form the site already chose' );

// A From address the owner typed is theirs, not SendBeam's to overwrite.
sb_v2_exchange( sb_v2_body(), array( 'mail_from_email' => 'orders@harbourlane.co.uk', 'mail_from_name' => 'Orders' ) );
$v2 = sendbeam_settings();
ok( 'orders@harbourlane.co.uk' === $v2['mail_from_email'] && 'Orders' === $v2['mail_from_name'], 'connect v2: a sender the owner set is left alone' );

// ── /api/v1/connect/status ─────────────────────────────────────────────
sb_connect_reset();
sendbeam_connect_forget_status();
update_option( 'sendbeam_settings', array( 'api_key' => 'sb_live_statusstatusstatus' ) );
$GLOBALS['stub']['remote_reply'] = array(
	'response' => array( 'code' => 200 ),
	'body'     => json_encode(
		array(
			'workspace'    => array( 'id' => 'ws_1', 'name' => 'Harbour Lane' ),
			'default_form' => array( 'id' => $form, 'name' => 'Newsletter signup' ),
			'domain'       => array( 'name' => 'harbourlane.co.uk', 'verified' => false, 'records' => array( array( 'type' => 'TXT', 'name' => '_sendbeam', 'value' => 'v=sb1' ) ), 'checked_at' => '2026-09-23T15:04:05Z' ),
			'sender'       => array( 'from_name' => 'Harbour Lane Roasters', 'from_email' => 'hello@harbourlane.co.uk' ),
		)
	),
);
$st = sendbeam_connect_status();
ok( 1 === count( $GLOBALS['stub']['remote'] ), 'status: one request the first time' );
ok( 'GET' === $GLOBALS['stub']['remote'][0]['method'], 'status: a plain read is a GET' );
ok( 'https://sendbeam.io/api/v1/connect/status' === $GLOBALS['stub']['remote'][0]['url'], 'status: goes to the contract URL' );
// SENDBEAM_API_KEY is defined earlier in this file, and a key in wp-config.php
// deliberately beats the saved one — so that, not the option, is what travels.
ok( SENDBEAM_API_KEY === $GLOBALS['stub']['remote'][0]['args']['headers']['x-api-key'], 'status: authenticates with the site\'s own key' );
ok( $st['ok'] && 'harbourlane.co.uk' === $st['domain']['name'], 'status: the domain comes back' );

sendbeam_connect_status();
ok( 1 === count( $GLOBALS['stub']['remote'] ), 'status: the second read inside a minute is served from the cache' );

$checked = sendbeam_connect_status( true );
ok( 2 === count( $GLOBALS['stub']['remote'] ), 'status: a check bypasses the cache' );
ok( 'POST' === $GLOBALS['stub']['remote'][1]['method'], 'status: a check is a POST' );
ok( array( 'check_domain' => true ) === json_decode( $GLOBALS['stub']['remote'][1]['args']['body'], true ), 'status: a check asks for check_domain' );

// The Overview renders for a site that was never connected: every key a
// template reads is present, and nothing claims to be true.
$st = sendbeam_connect_empty_status();
ok( ! $st['ok'] && '' === $st['domain']['name'] && array() === $st['domain']['records'], 'status: an unconnected site gets the empty shape, not a missing key' );
ok( '' === $st['workspace']['name'] && '' === $st['default_form']['id'] && '' === $st['sender']['from_email'], 'status: the empty shape has every key the Overview indexes into' );
ok( array() === sendbeam_connect_normalise_status( 'not json' )['domain']['records'], 'status: a reply that is not an object normalises to empties rather than warning' );

// A rejected key is reported, cached, and never crashes a template.
sb_connect_reset();
sendbeam_connect_forget_status();
update_option( 'sendbeam_settings', array( 'api_key' => 'sb_live_revokedrevokedrevoked' ) );
$GLOBALS['stub']['remote_reply'] = array( 'response' => array( 'code' => 401 ), 'body' => '{"error":"unauthorised"}' );
$st = sendbeam_connect_status();
ok( ! $st['ok'] && '' !== $st['error'], 'status: a rejected key is reported in plain words' );
ok( '' === $st['sender']['from_name'] && '' === $st['default_form']['id'], 'status: a failure still returns every key a template reads' );
sendbeam_connect_status();
ok( 1 === count( $GLOBALS['stub']['remote'] ), 'status: a failure is cached too, so a revoked key is not retried on every page load' );

// A 200 carrying nothing usable is a failure, not an answer: caching it as
// one would blank the DNS records somebody is halfway through copying.
sb_connect_reset();
sendbeam_connect_forget_status();
$GLOBALS['stub']['remote_reply'] = array( 'response' => array( 'code' => 200 ), 'body' => '<html>proxy error</html>' );
$st = sendbeam_connect_status();
ok( ! $st['ok'] && '' !== $st['error'], 'status: a 200 that is not JSON is treated as a failure, not as an empty workspace' );

// ── Per-record verdict ─────────────────────────────────────────────────
// Every record SendBeam asks for is a CNAME under sendbeam.io, so there is no
// priority to enter and the table must not invent a column for one. What the
// table does need is the check's answer per row: two records right out of
// three looks exactly like none without it.
$v3_records = sendbeam_connect_normalise_status(
	array(
		'domain' => array(
			'name'    => 'harbourlane.co.uk',
			'records' => array(
				array( 'type' => 'CNAME', 'name' => 'sb1._domainkey', 'value' => 'sb1.dkim.sendbeam.io', 'found' => true ),
				array( 'type' => 'CNAME', 'name' => 'sb2._domainkey', 'value' => 'sb2.dkim.sendbeam.io', 'found' => false ),
				array( 'type' => 'CNAME', 'name' => 'sbmail', 'value' => 'mail.sendbeam.io' ),
			),
		),
	)
)['domain']['records'];
ok( true === $v3_records[0]['found'], 'records: a record the check found reads true' );
ok( false === $v3_records[1]['found'], 'records: a record the check did not find reads false, not missing' );
ok( null === $v3_records[2]['found'], 'records: a record nothing has looked at reads null, not false' );

ob_start();
sendbeam_domain_records_table( $v3_records );
$v3_table = ob_get_clean();
has( $v3_table, '>Found<', 'records: the table has a verdict column' );
has( $v3_table, 'Not found yet', 'records: a record that is not in DNS says so on its own row' );
has( $v3_table, 'Not checked yet', 'records: a record nothing has checked is not reported as wrong' );
has( $v3_table, 'sb-tick', 'records: a record that is live is ticked' );
lacks( $v3_table, 'Priority', 'records: there is no Priority column — every record is a CNAME' );
lacks( $v3_table, 'priority', 'records: nothing on the table mentions priority' );

// ── When the check last ran ────────────────────────────────────────────
// The API answers in ISO 8601 UTC. That is the right thing to send and the
// wrong thing to print at somebody: it is not a time anybody reads, and it is
// not their hour either.
ok( '23 September 2026, 3:04 pm' === sendbeam_connect_when( '2026-09-23T15:04:05Z' ), 'checked at: an ISO timestamp is rendered in the site\'s own date and time format' );
ok( '24 September 2026, 5:49 am' === sendbeam_connect_when( '2026-09-24T05:49:24.291+00:00' ), 'checked at: fractional seconds and an offset are understood too' );
ok( '' === sendbeam_connect_when( '' ), 'checked at: nothing in, nothing out' );
ok( 'whenever' === sendbeam_connect_when( 'whenever' ), 'checked at: something that will not parse is shown as it came rather than dropped' );
update_option( 'date_format', 'Y/m/d' );
update_option( 'time_format', 'H:i' );
ok( '2026/09/23, 15:04' === sendbeam_connect_when( '2026-09-23T15:04:05Z' ), 'checked at: the formats the site owner chose are the ones used' );
delete_option( 'date_format' );
delete_option( 'time_format' );

// ── Why there is no sending domain ─────────────────────────────────────
// `domain: null` meant four different things and the screen could only say
// "not set up" to all four. The state says which, and every sentence on step
// 2 now comes out of the answer rather than out of what this site remembers
// granting — the two disagree on any site whose key was pasted by hand.
ok( 'not_granted' === sendbeam_connect_normalise_status( array( 'domain_state' => 'not_granted' ) )['domain_state'], 'domain state: the state comes back as SendBeam sent it' );
ok( '' === sendbeam_connect_clean_domain_state( 'made_up' ), 'domain state: a state that is not in the contract is dropped' );
ok( 'ok' === sendbeam_connect_domain_state( sendbeam_connect_normalise_status( array( 'domain' => array( 'name' => 'harbourlane.co.uk' ) ) ) ), 'domain state: an answer with a domain and no state reads as ok' );
ok( 'not_found' === sendbeam_connect_domain_state( sendbeam_connect_normalise_status( array( 'workspace' => array( 'id' => 'ws_1' ) ) ) ), 'domain state: an answer with neither falls back to not_found rather than to nothing' );
ok( 'The domain is on another workspace.' === sendbeam_connect_normalise_status( array( 'domain_state' => 'not_found', 'domain_note' => 'The domain is on another workspace.' ) )['domain_note'], 'domain state: the note is one sentence for the owner' );
ok( 'Your plan allows one sending domain.' === sendbeam_connect_normalise_status( array( 'domain_state' => 'not_found', 'notes' => array( 'Your plan allows one sending domain.' ) ) )['domain_note'], 'domain state: a SendBeam that predates domain_note still gets its sentence across' );

/**
 * The checklist sentences for one status body, keyed by step.
 *
 * @param array $status   Status payload as the API would send it.
 * @param array $settings Saved settings.
 * @return array<string,string>
 */
function sb_step_details( $status, $settings = null ) {
	$GLOBALS['stub']['caps']['manage_options'] = true;
	update_option( 'sendbeam_settings', null === $settings ? array( 'api_key' => 'sb_live_connectedconnectedxx', 'sendbeam_connected_via' => 'connect', 'sendbeam_connect_granted' => 'forms,transactional:send,domain' ) : $settings );
	set_transient( 'sendbeam_connection', array( 'state' => '' === (string) ( sendbeam_settings()['api_key'] ) ? 'none' : 'ok', 'message' => '', 'count' => 1 ) );
	sendbeam_connect_cache_status( sendbeam_connect_normalise_status( $status ) );
	$out = array();
	foreach ( sendbeam_setup_steps() as $step ) {
		$out[ $step['key'] ] = $step['detail'];
		$out[ $step['key'] . ':reconnect' ] = ! empty( $step['reconnect'] ) ? '1' : '';
	}
	return $out;
}

$sb_dom = sb_step_details( array( 'domain_state' => 'not_granted' ) );
has( $sb_dom['domain'], 'You did not allow SendBeam to set up a sending domain', 'domain state: not_granted says the box was never ticked' );
ok( '1' === $sb_dom['domain:reconnect'], 'domain state: not_granted offers a way to reconnect' );

$sb_dom = sb_step_details( array( 'domain_state' => 'no_permission' ) );
has( $sb_dom['domain'], 'made before domains could be read', 'domain state: no_permission says the key is too old' );
ok( '1' === $sb_dom['domain:reconnect'], 'domain state: no_permission offers a way to reconnect' );

$sb_dom = sb_step_details( array( 'domain_state' => 'no_site' ) );
has( $sb_dom['domain'], 'No sending domain is set up for this site', 'domain state: no_site says there is no domain' );
has( $sb_dom['domain'], 'Settings → Domains', 'domain state: no_site says where to add one' );

$sb_dom = sb_step_details( array( 'domain_state' => 'not_found', 'domain_note' => 'It was removed from the workspace.' ) );
has( $sb_dom['domain'], 'It was removed from the workspace.', 'domain state: not_found carries SendBeam\'s own reason' );

$sb_dom = sb_step_details( array( 'domain_state' => 'managed_host', 'domain_note' => 'example.wordpress.com is run by a hosting company; mail cannot be sent from it.' ) );
has( $sb_dom['domain'], 'run by a hosting company', 'domain state: managed_host is the note, in SendBeam\'s words' );

$sb_dom = sb_step_details( array( 'domain_state' => 'unavailable', 'domain' => array( 'name' => 'harbourlane.co.uk', 'verified' => true ) ) );
has( $sb_dom['domain'], 'showing the last known state', 'domain state: unavailable says the answer is old' );
has( $sb_dom['domain'], 'harbourlane.co.uk is verified', 'domain state: unavailable still shows what was last known' );
$sb_dom = sb_step_details( array( 'domain_state' => 'unavailable' ) );
has( $sb_dom['domain'], 'showing the last known state', 'domain state: unavailable with nothing cached is the sentence on its own' );
lacks( $sb_dom['domain'], 'is verified', 'domain state: unavailable claims nothing it does not know' );

// The defect this replaced: a key pasted by hand records no permissions, so
// the step told those sites they had no permission to read a domain — while
// the records for that very domain rendered underneath.
$sb_pasted = array( 'api_key' => 'sb_live_pastedpastedpasted' );
$sb_dom    = sb_step_details(
	array(
		'domain' => array( 'name' => 'harbourlane.co.uk', 'verified' => false, 'records' => array( array( 'type' => 'CNAME', 'name' => 'sb1._domainkey', 'value' => 'sb1.dkim.sendbeam.io', 'found' => false ) ) ),
	),
	$sb_pasted
);
has( $sb_dom['domain'], 'Add these records to the DNS for harbourlane.co.uk', 'domain state: a pasted key that can read the domain is simply ok' );
lacks( $sb_dom['domain'], 'not given permission', 'domain state: nothing claims a permission is missing while the domain is on screen' );

// The sending host is the owner's choice at consent time and need not be
// this site's domain at all. Whatever the answer says is what is shown.
$GLOBALS['stub']['site_url'] = 'https://www.example-site.test';
$sb_dom = sb_step_details( array( 'domain' => array( 'name' => 'mail.brand.co.uk', 'verified' => true ) ) );
has( $sb_dom['domain'], 'mail.brand.co.uk is verified', 'domain state: the domain shown is the one SendBeam reported' );
lacks( $sb_dom['domain'], 'example-site.test', 'domain state: the step never derives the host from site_url' );
unset( $GLOBALS['stub']['site_url'] );
update_option( 'sendbeam_settings', array() );
sendbeam_connect_forget_status();
delete_transient( 'sendbeam_connection' );

// ── Reconnecting ───────────────────────────────────────────────────────
// Un-ticking a permission at consent used to be fixable only by disconnect
// and connect again, which is a frightening thing to tell someone whose site
// is working. A link starts the same flow with the boxes already ticked.
sb_connect_reset();
update_option( 'sendbeam_settings', array( 'api_key' => 'sb_live_connectedconnectedxx', 'sendbeam_connect_granted' => 'forms,contacts:write' ) );
$sb_all = sendbeam_connect_start_url( true );
has( $sb_all, 'action=sendbeam_connect_start', 'reconnect: the link starts the ordinary Connect flow' );
has( $sb_all, 'nonce:sendbeam_connect_start', 'reconnect: the link carries the same nonce the panel does' );
has( rawurldecode( $sb_all ), 'forms,contacts:write,transactional:send,ecommerce,domain', 'reconnect: with more permissions preselects every scope' );
$sb_same = sendbeam_connect_start_url();
has( rawurldecode( $sb_same ), 'sendbeam_scopes=forms,contacts:write', 'reconnect: a plain Reconnect asks for what the key already has' );
update_option( 'sendbeam_settings', array( 'api_key' => 'sb_live_pastedpastedpasted' ) );
has( rawurldecode( sendbeam_connect_start_url() ), 'ecommerce', 'reconnect: a key that recorded no permissions falls back to asking for all of them' );

// The link's csv reaches the consent page as the panel's checkboxes would.
sb_connect_reset();
$_GET     = array( 'sendbeam_scopes' => 'forms,domain,not-a-scope', '_wpnonce' => 'nonce:sendbeam_connect_start' );
$_REQUEST = $_GET;
try {
	sendbeam_connect_start();
} catch ( SendBeamStubExit $e ) {
	$GLOBALS['stub']['exit'] = $e->getMessage();
}
parse_str( (string) wp_parse_url( (string) $GLOBALS['stub']['redirect']['url'], PHP_URL_QUERY ), $sb_rq );
ok( 'forms,domain' === ( $sb_rq['scopes'] ?? '' ), 'reconnect: the link\'s scopes are cleaned exactly as the panel\'s are' );
ok( 0 === get_transient( 'sendbeam_connect_7' )['popup'], 'reconnect: a link is not a pop-up, so the return page offers a way back' );
sb_connect_reset();
update_option( 'sendbeam_settings', array() );

// ── A slow SendBeam must not be a slow wp-admin ────────────────────────
sb_connect_reset();
sendbeam_connect_forget_status();
update_option( 'sendbeam_settings', array( 'api_key' => 'sb_live_statusstatusstatus' ) );
$GLOBALS['stub']['remote_reply'] = array( 'response' => array( 'code' => 200 ), 'body' => sb_status_body( false ) );
$sb_first = sendbeam_connect_status();
ok( 4 === $GLOBALS['stub']['remote'][0]['args']['timeout'], 'status: a screen render waits four seconds, not the shared ten' );

// The answer goes stale after a minute, but it is kept far longer than that:
// when SendBeam cannot be reached, a minute-old copy of the DNS records is
// enormously better than an empty panel to somebody halfway through copying
// them into a registrar.
$sb_kept               = get_transient( sendbeam_connect_status_key() );
$sb_kept['fetched_at'] = time() - 300;
set_transient( sendbeam_connect_status_key(), $sb_kept, 60 );
$GLOBALS['stub']['remote_reply'] = new WP_Error( 'http_request_failed', 'cURL error 28' );
$sb_stale = sendbeam_connect_status();
ok( 'harbourlane.co.uk' === $sb_stale['domain']['name'], 'status: a failed request serves the last answer rather than blanking the panel' );
ok( ! empty( $sb_stale['stale'] ), 'status: and says it is doing so' );
ok( 'unavailable' === $sb_stale['domain_state'], 'status: the state says SendBeam could not be reached' );
ok( '' !== $sb_stale['error'], 'status: the failure is still reported' );

// Nothing cached and nothing reachable: empties, and the state that says why.
sendbeam_connect_forget_status();
$sb_cold = sendbeam_connect_status();
ok( '' === $sb_cold['domain']['name'] && empty( $sb_cold['stale'] ), 'status: with nothing cached there is nothing to fall back on' );
ok( 'unavailable' === $sb_cold['domain_state'], 'status: and the state still says why' );

// One transient per key, so two administrators on different workspaces cannot
// read each other's sending domain — and the key itself is not the name.
$sb_key_a = sendbeam_connect_status_key( 'sb_live_aaaaaaaaaaaaaaaaaaaa' );
$sb_key_b = sendbeam_connect_status_key( 'sb_live_bbbbbbbbbbbbbbbbbbbb' );
ok( $sb_key_a !== $sb_key_b, 'status: two keys do not share one cached answer' );
ok( 0 === strpos( $sb_key_a, 'sendbeam_connect_status_' ), 'status: the transient is still swept by prefix on uninstall' );
lacks( $sb_key_a, 'sb_live_', 'status: the key is hashed, not used — a transient name ends up in backups and logs' );

// Pasting a different key takes the old key's answer with it.
sb_connect_reset();
update_option( 'sendbeam_settings', array( 'api_key' => 'sb_live_aaaaaaaaaaaaaaaaaaaa' ) );
set_transient( $sb_key_a, sendbeam_connect_empty_status(), 60 );
update_option( 'sendbeam_settings', sendbeam_sanitize_settings( array( '_tab' => 'connect', 'api_key' => 'sb_live_bbbbbbbbbbbbbbbbbbbb' ) ) );
ok( false === get_transient( $sb_key_a ), 'status: changing the key deletes what the old one had cached' );

sb_connect_reset();
sendbeam_connect_forget_status();
update_option( 'sendbeam_settings', array() );

// ── Check now ──────────────────────────────────────────────────────────
/** Drive an admin-post handler that ends in a redirect. */
function sb_run_admin_post( $fn ) {
	try {
		$fn();
	} catch ( SendBeamStubExit $e ) {
		$GLOBALS['stub']['exit'] = $e->getMessage();
	}
	return isset( $GLOBALS['stub']['redirect']['url'] ) ? (string) $GLOBALS['stub']['redirect']['url'] : '';
}

/** A connected site with site email approved but held back. */
function sb_deferred_site() {
	sb_connect_reset();
	sendbeam_connect_forget_status();
	update_option(
		'sendbeam_settings',
		array(
			'api_key'                  => 'sb_live_connectedconnectedxx',
			'sendbeam_connected_via'   => 'connect',
			'sendbeam_connect_granted' => 'forms,transactional:send,domain',
			'sendbeam_mail_deferred'   => 1,
			'mail_enabled'             => 0,
		)
	);
	$_POST    = array( '_wpnonce' => 'nonce:sendbeam_domain_check' );
	$_REQUEST = $_POST;
}

/** A status reply body with the domain verified or not. */
function sb_status_body( $verified ) {
	return json_encode(
		array(
			'workspace' => array( 'id' => 'ws_1', 'name' => 'Harbour Lane' ),
			'domain'    => array( 'name' => 'harbourlane.co.uk', 'verified' => $verified, 'records' => array(), 'checked_at' => '2026-09-23T15:04:05Z' ),
			'sender'    => array( 'from_name' => 'Harbour Lane Roasters', 'from_email' => 'hello@harbourlane.co.uk' ),
		)
	);
}

sb_deferred_site();
$GLOBALS['stub']['remote_reply'] = array( 'response' => array( 'code' => 200 ), 'body' => sb_status_body( false ) );
$url = sb_run_admin_post( 'sendbeam_handle_domain_check' );
has( $url, 'sendbeam_domain=pending', 'check: a domain that is still unverified says so' );
ok( empty( sendbeam_settings()['mail_enabled'] ), 'check: an unverified domain never switches site email on' );
ok( ! empty( sendbeam_settings()['sendbeam_mail_deferred'] ), 'check: site email stays held back' );

sb_deferred_site();
$GLOBALS['stub']['remote_reply'] = array( 'response' => array( 'code' => 200 ), 'body' => sb_status_body( true ) );
$url = sb_run_admin_post( 'sendbeam_handle_domain_check' );
has( $url, 'sendbeam_domain=verified_mail', 'check: a verified domain finishes the job consent started' );
$v2 = sendbeam_settings();
ok( ! empty( $v2['mail_enabled'] ), 'check: site email goes on once the domain is verified' );
ok( empty( $v2['sendbeam_mail_deferred'] ), 'check: nothing is left held back' );
ok( 'hello@harbourlane.co.uk' === $v2['mail_from_email'], 'check: the workspace sender fills the empty From address' );

// Site email switched off by hand is a decision. A later check must not undo it.
sb_deferred_site();
$off = sendbeam_sanitize_settings( array( '_tab' => 'mail' ) );
update_option( 'sendbeam_settings', $off );
ok( empty( $off['sendbeam_mail_deferred'] ), 'check: turning site email off by hand cancels the held-back switch-on' );
$_POST                           = array( '_wpnonce' => 'nonce:sendbeam_domain_check' );
$_REQUEST                        = $_POST;
sendbeam_connect_forget_status();
$GLOBALS['stub']['remote_reply'] = array( 'response' => array( 'code' => 200 ), 'body' => sb_status_body( true ) );
$url = sb_run_admin_post( 'sendbeam_handle_domain_check' );
has( $url, 'sendbeam_domain=verified', 'check: a verified domain is still reported' );
ok( empty( sendbeam_settings()['mail_enabled'] ), 'check: a verified domain does NOT re-enable site email the owner switched off' );

// A check that never got through is not a check. Serving the last answer to
// a screen is a kindness; telling somebody who pressed the button that their
// domain is still unverified, when the request never left, is not.
sb_deferred_site();
$GLOBALS['stub']['remote_reply'] = array( 'response' => array( 'code' => 200 ), 'body' => sb_status_body( true ) );
sendbeam_connect_status();
$GLOBALS['stub']['remote_reply'] = new WP_Error( 'http_request_failed', 'cURL error 28' );
$_POST    = array( '_wpnonce' => 'nonce:sendbeam_domain_check' );
$_REQUEST = $_POST;
$url = sb_run_admin_post( 'sendbeam_handle_domain_check' );
has( $url, 'sendbeam_domain=unreachable', 'check: a check that failed says so, even with a verified answer in the cache' );
ok( empty( sendbeam_settings()['mail_enabled'] ), 'check: and switches nothing on off the back of a cached claim' );

// Nonce and capability.
sb_deferred_site();
$_POST    = array();
$_REQUEST = array();
sb_run_admin_post( 'sendbeam_handle_domain_check' );
ok( 0 === strpos( $GLOBALS['stub']['exit'], 'wp_die' ), 'check: no nonce, no check' );
sb_deferred_site();
$GLOBALS['stub']['caps']['manage_options'] = false;
sb_run_admin_post( 'sendbeam_handle_domain_check' );
ok( 0 === strpos( $GLOBALS['stub']['exit'], 'wp_die' ), 'check: the capability is required' );

// ── Switch on ──────────────────────────────────────────────────────────
sb_deferred_site();
$_POST    = array( '_wpnonce' => 'nonce:sendbeam_mail_switch_on' );
$_REQUEST = $_POST;
$GLOBALS['stub']['remote_reply'] = array( 'response' => array( 'code' => 200 ), 'body' => sb_status_body( false ) );
$url = sb_run_admin_post( 'sendbeam_handle_mail_switch_on' );
has( $url, 'sendbeam_mail=unverified', 'switch on: refuses while the domain is unverified' );
ok( empty( sendbeam_settings()['mail_enabled'] ), 'switch on: nothing is enabled while the domain is unverified' );

sb_deferred_site();
$_POST    = array( '_wpnonce' => 'nonce:sendbeam_mail_switch_on' );
$_REQUEST = $_POST;
$GLOBALS['stub']['remote_reply'] = array( 'response' => array( 'code' => 200 ), 'body' => sb_status_body( true ) );
$url = sb_run_admin_post( 'sendbeam_handle_mail_switch_on' );
has( $url, 'sendbeam_mail=on', 'switch on: turns site email on once the domain is verified' );
ok( ! empty( sendbeam_settings()['mail_enabled'] ), 'switch on: site email is on' );

// A key without the scope cannot be talked into it by a replayed form.
sb_deferred_site();
$noscope = sendbeam_settings();
$noscope['sendbeam_connect_granted'] = 'forms';
update_option( 'sendbeam_settings', $noscope );
$_POST    = array( '_wpnonce' => 'nonce:sendbeam_mail_switch_on' );
$_REQUEST = $_POST;
$GLOBALS['stub']['remote_reply'] = array( 'response' => array( 'code' => 200 ), 'body' => sb_status_body( true ) );
$url = sb_run_admin_post( 'sendbeam_handle_mail_switch_on' );
has( $url, 'sendbeam_mail=noscope', 'switch on: refuses a key that was never given the permission' );
ok( empty( sendbeam_settings()['mail_enabled'] ), 'switch on: nothing is enabled without the permission' );
ok( empty( $GLOBALS['stub']['remote'] ), 'switch on: a key without the permission is not even asked about' );

// ── Disconnect ─────────────────────────────────────────────────────────
/**
 * A connected site, armed for a disconnect.
 *
 * The saved key is SENDBEAM_API_KEY's value on purpose. This file defines
 * that constant earlier, and a key in wp-config.php beats the saved one — so
 * a site where the two differ is a site whose live key Connect never issued,
 * which has its own test below.
 */
function sb_connected_site() {
	sb_connect_reset();
	update_option(
		'sendbeam_settings',
		array(
			'api_key'                    => SENDBEAM_API_KEY,
			'sendbeam_connected_via'     => 'connect',
			'sendbeam_connect_workspace' => 'Harbour Lane',
			'sendbeam_connect_granted'   => 'forms,transactional:send,domain',
			'sendbeam_mail_deferred'     => 1,
		)
	);
	sendbeam_connect_cache_status( sendbeam_connect_empty_status() );
	$_POST    = array( '_wpnonce' => 'nonce:sendbeam_disconnect' );
	$_REQUEST = $_POST;
}

sb_connected_site();
$GLOBALS['stub']['remote_reply'] = array( 'response' => array( 'code' => 204 ), 'body' => '' );
$url = sb_run_admin_post( 'sendbeam_connect_disconnect' );
ok( 1 === count( $GLOBALS['stub']['remote'] ), 'disconnect: SendBeam is asked to revoke the key' );
ok( 'https://sendbeam.io/api/v1/connect/disconnect' === $GLOBALS['stub']['remote'][0]['url'], 'disconnect: the revoke goes to the contract URL' );
ok( 'POST' === $GLOBALS['stub']['remote'][0]['method'], 'disconnect: the revoke is a POST' );
ok( SENDBEAM_API_KEY === $GLOBALS['stub']['remote'][0]['args']['headers']['x-api-key'], 'disconnect: the key revokes itself' );
ok( 10 === $GLOBALS['stub']['remote'][0]['args']['timeout'], 'disconnect: the revoke has the short timeout the contract asks for' );
$v2 = sendbeam_settings();
ok( '' === $v2['api_key'], 'disconnect: the key is forgotten' );
ok( '' === $v2['sendbeam_connected_via'] && '' === $v2['sendbeam_connect_workspace'], 'disconnect: the Connect marker and workspace name go with it' );
ok( '' === $v2['sendbeam_connect_granted'] && empty( $v2['sendbeam_mail_deferred'] ), 'disconnect: the permissions and the held-back switch-on are forgotten' );
ok( false === get_transient( sendbeam_connect_status_key() ), 'disconnect: the cached status is dropped' );
has( $url, 'sendbeam_disconnected=ok', 'disconnect: the notice says the key was revoked' );

// SendBeam unreachable: the site still forgets the key, and says so.
sb_connected_site();
$GLOBALS['stub']['remote_reply'] = new WP_Error( 'http_request_failed', 'cURL error 28' );
$url = sb_run_admin_post( 'sendbeam_connect_disconnect' );
ok( '' === sendbeam_settings()['api_key'], 'disconnect: an unreachable SendBeam still forgets the key here' );
has( $url, 'sendbeam_disconnected=unreachable', 'disconnect: an unreachable SendBeam is reported so the key can be revoked by hand' );

// A key SendBeam has already forgotten is not a live key.
sb_connected_site();
$GLOBALS['stub']['remote_reply'] = array( 'response' => array( 'code' => 401 ), 'body' => '{"error":"unauthorised"}' );
$url = sb_run_admin_post( 'sendbeam_connect_disconnect' );
has( $url, 'sendbeam_disconnected=ok', 'disconnect: a key SendBeam already rejects counts as revoked' );

// A key defined in wp-config.php is not this button's to revoke: it beats the
// saved key everywhere, so revoking it would take down something Connect
// never issued and something else is probably using.
sb_connected_site();
$const_site            = sendbeam_settings();
$const_site['api_key'] = 'sb_live_storedbutnotusedxxx';
update_option( 'sendbeam_settings', $const_site );
$GLOBALS['stub']['remote'] = array();
$url = sb_run_admin_post( 'sendbeam_connect_disconnect' );
ok( empty( $GLOBALS['stub']['remote'] ), 'disconnect: a key from wp-config.php is never revoked by this button' );
ok( '' === sendbeam_settings()['api_key'], 'disconnect: the stored key is still forgotten' );
has( $url, 'sendbeam_disconnected=constant', 'disconnect: the screen is told the live key is in wp-config.php' );

// Nonce and capability.
sb_connected_site();
$_POST    = array();
$_REQUEST = array();
sb_run_admin_post( 'sendbeam_connect_disconnect' );
ok( 0 === strpos( $GLOBALS['stub']['exit'], 'wp_die' ), 'disconnect: no nonce, no disconnect' );
ok( SENDBEAM_API_KEY === sendbeam_settings()['api_key'], 'disconnect: a request with no nonce keeps the key' );
sb_connected_site();
$GLOBALS['stub']['caps']['manage_options'] = false;
sb_run_admin_post( 'sendbeam_connect_disconnect' );
ok( 0 === strpos( $GLOBALS['stub']['exit'], 'wp_die' ), 'disconnect: the capability is required' );
ok( SENDBEAM_API_KEY === sendbeam_settings()['api_key'], 'disconnect: a caller without the capability changes nothing' );

// ── What Disconnect puts back ──────────────────────────────────────────
// The workspace keeps its list, form, domain and sender. What this site puts
// back is what Connect filled in here — and only that, because a form ID
// belonging to a workspace this site no longer has a key for makes every
// embed 404 without a word.
sb_v2_exchange( sb_v2_body( array( 'domain' => array( 'name' => 'harbourlane.co.uk', 'verified' => true, 'records' => array() ) ) ) );
$sb_filled = sendbeam_settings()['sendbeam_connect_filled'];
ok( in_array( 'default_form', $sb_filled, true ), 'filled: the form Connect chose is recorded as Connect\'s' );
ok( in_array( 'mail_from_name', $sb_filled, true ) && in_array( 'mail_from_email', $sb_filled, true ), 'filled: the sender Connect filled is recorded' );
ok( in_array( 'mail_enabled', $sb_filled, true ), 'filled: site email switched on at consent is recorded' );

$_POST    = array( '_wpnonce' => 'nonce:sendbeam_disconnect' );
$_REQUEST = $_POST;
$GLOBALS['stub']['remote_reply'] = array( 'response' => array( 'code' => 204 ), 'body' => '' );
sb_run_admin_post( 'sendbeam_connect_disconnect' );
$sb_after = sendbeam_settings();
ok( '' === $sb_after['default_form'], 'disconnect: the form Connect chose is let go' );
ok( '' === $sb_after['mail_from_name'] && '' === $sb_after['mail_from_email'], 'disconnect: the sender Connect filled is let go' );
ok( empty( $sb_after['mail_enabled'] ), 'disconnect: site email Connect switched on goes off with it' );
ok( array() === $sb_after['sendbeam_connect_filled'], 'disconnect: the list of what was filled is emptied too' );

// The same site, but the owner chose the form and the From address. Those are
// theirs: Disconnect leaves both exactly where they are.
sb_v2_exchange( sb_v2_body(), array( 'default_form' => $other, 'mail_from_email' => 'orders@harbourlane.co.uk', 'mail_from_name' => 'Orders' ) );
$_POST    = array( '_wpnonce' => 'nonce:sendbeam_disconnect' );
$_REQUEST = $_POST;
$GLOBALS['stub']['remote_reply'] = array( 'response' => array( 'code' => 204 ), 'body' => '' );
sb_run_admin_post( 'sendbeam_connect_disconnect' );
$sb_after = sendbeam_settings();
ok( $other === $sb_after['default_form'], 'disconnect: a form the owner chose is left alone' );
ok( 'orders@harbourlane.co.uk' === $sb_after['mail_from_email'] && 'Orders' === $sb_after['mail_from_name'], 'disconnect: a sender the owner typed is left alone' );

// Saving the Site email tab by hand is the owner taking those settings back.
sb_v2_exchange( sb_v2_body( array( 'domain' => array( 'name' => 'harbourlane.co.uk', 'verified' => true, 'records' => array() ) ) ) );
update_option( 'sendbeam_settings', sendbeam_sanitize_settings( array( '_tab' => 'mail', 'mail_enabled' => '1', 'mail_from_name' => 'Harbour Lane', 'mail_from_email' => 'hello@harbourlane.co.uk' ) ) );
$sb_filled = sendbeam_settings()['sendbeam_connect_filled'];
ok( ! in_array( 'mail_from_email', $sb_filled, true ) && ! in_array( 'mail_enabled', $sb_filled, true ), 'filled: saving Site email by hand takes those settings off Connect\'s list' );
ok( in_array( 'default_form', $sb_filled, true ), 'filled: saving one tab does not release the settings on another' );
$_POST    = array( '_wpnonce' => 'nonce:sendbeam_disconnect' );
$_REQUEST = $_POST;
$GLOBALS['stub']['remote_reply'] = array( 'response' => array( 'code' => 204 ), 'body' => '' );
sb_run_admin_post( 'sendbeam_connect_disconnect' );
$sb_after = sendbeam_settings();
ok( ! empty( $sb_after['mail_enabled'] ), 'disconnect: site email the owner switched on themselves stays on' );
ok( 'hello@harbourlane.co.uk' === $sb_after['mail_from_email'], 'disconnect: and the sender they typed stays with it' );

// A key pasted over the top of a connection leaves nothing of Connect's behind.
sb_v2_exchange( sb_v2_body() );
update_option( 'sendbeam_settings', sendbeam_sanitize_settings( array( '_tab' => 'connect', 'api_key' => 'sb_live_pastedoverthetopxxxx' ) ) );
ok( array() === sendbeam_settings()['sendbeam_connect_filled'], 'filled: a key changed by hand ends Connect\'s claim on anything' );

sb_connect_reset();
update_option( 'sendbeam_settings', array() );

// ── The hourly re-check ────────────────────────────────────────────────
// Records almost never spread while somebody is sitting in wp-admin: they are
// entered at the registrar and the tab is closed. Without this the step
// stayed unticked and the site's email stayed held back until a human pressed
// Check now — waiting for the one thing the plugin had just said was unneeded.
$GLOBALS['stub']['cron'] = array();
sb_v2_exchange( sb_v2_body() );
ok( false !== wp_next_scheduled( 'sendbeam_connect_recheck' ), 'recheck: connecting with an unverified domain starts the hourly check' );
ok( 'hourly' === $GLOBALS['stub']['cron'][0]['recurrence'], 'recheck: it runs hourly' );

$GLOBALS['stub']['cron'] = array();
sb_v2_exchange( sb_v2_body( array( 'domain' => array( 'name' => 'harbourlane.co.uk', 'verified' => true, 'records' => array() ) ) ) );
ok( false === wp_next_scheduled( 'sendbeam_connect_recheck' ), 'recheck: nothing is scheduled for a domain that is already verified' );

$GLOBALS['stub']['cron'] = array();
sb_v2_exchange( sb_v2_body( array( 'scopes' => array( 'forms' ), 'domain' => null ) ) );
ok( false === wp_next_scheduled( 'sendbeam_connect_recheck' ), 'recheck: nothing is scheduled when there is no domain to wait for' );

/** A connected site mid-wait, with one reply armed for the re-check. */
function sb_recheck_site( $verified ) {
	sb_connect_reset();
	sendbeam_connect_forget_status();
	update_option(
		'sendbeam_settings',
		array(
			'api_key'                  => 'sb_live_connectedconnectedxx',
			'sendbeam_connected_via'   => 'connect',
			'sendbeam_connect_granted' => 'forms,transactional:send,domain',
			'sendbeam_mail_deferred'   => 1,
			'mail_enabled'             => 0,
		)
	);
	$GLOBALS['stub']['cron'] = array( array( 'hook' => 'sendbeam_connect_recheck', 'args' => array(), 'recurrence' => 'hourly' ) );
	update_option( 'sendbeam_connect_recheck_runs', 0 );
	$GLOBALS['stub']['remote_reply'] = array( 'response' => array( 'code' => 200 ), 'body' => sb_status_body( $verified ) );
}

sb_recheck_site( false );
sendbeam_connect_run_recheck();
ok( 'POST' === $GLOBALS['stub']['remote'][0]['method'], 'recheck: a run asks SendBeam to look the DNS up now' );
ok( false !== wp_next_scheduled( 'sendbeam_connect_recheck' ), 'recheck: an unverified domain is worth asking about again' );
ok( empty( sendbeam_settings()['mail_enabled'] ), 'recheck: nothing is switched on while the domain is unverified' );
ok( 1 === (int) get_option( 'sendbeam_connect_recheck_runs' ), 'recheck: the run is counted' );

sb_recheck_site( true );
sendbeam_connect_run_recheck();
ok( ! empty( sendbeam_settings()['mail_enabled'] ), 'recheck: a verified domain finishes the held-back switch-on with nobody watching' );
ok( empty( sendbeam_settings()['sendbeam_mail_deferred'] ), 'recheck: nothing is left held back' );
ok( false === wp_next_scheduled( 'sendbeam_connect_recheck' ), 'recheck: it unschedules itself once there is nothing left to find out' );

// A day of trying is enough: DNS that has not spread in 24 hours was not entered.
sb_recheck_site( false );
update_option( 'sendbeam_connect_recheck_runs', 24 );
sendbeam_connect_run_recheck();
ok( false === wp_next_scheduled( 'sendbeam_connect_recheck' ), 'recheck: it gives up after a day rather than asking forever' );
ok( empty( $GLOBALS['stub']['remote'] ), 'recheck: the run that gives up does not make the request first' );

// SendBeam being down for an hour is not the same as the DNS not being there.
sb_recheck_site( false );
$GLOBALS['stub']['remote_reply'] = new WP_Error( 'http_request_failed', 'cURL error 28' );
sendbeam_connect_run_recheck();
ok( false !== wp_next_scheduled( 'sendbeam_connect_recheck' ), 'recheck: a failed request is tried again next hour' );

// A domain that has gone from the workspace is not one to keep asking about.
sb_recheck_site( false );
$GLOBALS['stub']['remote_reply'] = array( 'response' => array( 'code' => 200 ), 'body' => json_encode( array( 'workspace' => array( 'id' => 'ws_1' ), 'domain' => null, 'domain_state' => 'not_found' ) ) );
sendbeam_connect_run_recheck();
ok( false === wp_next_scheduled( 'sendbeam_connect_recheck' ), 'recheck: a domain that is no longer there stops it asking' );

// Pressing Check now, disconnecting, or switching the plugin off all stop it.
sb_recheck_site( true );
$_POST    = array( '_wpnonce' => 'nonce:sendbeam_domain_check' );
$_REQUEST = $_POST;
sb_run_admin_post( 'sendbeam_handle_domain_check' );
ok( false === wp_next_scheduled( 'sendbeam_connect_recheck' ), 'recheck: a verified domain found by hand stops it too' );

sb_recheck_site( false );
$_POST    = array( '_wpnonce' => 'nonce:sendbeam_disconnect' );
$_REQUEST = $_POST;
$GLOBALS['stub']['remote_reply'] = array( 'response' => array( 'code' => 204 ), 'body' => '' );
sb_run_admin_post( 'sendbeam_connect_disconnect' );
ok( false === wp_next_scheduled( 'sendbeam_connect_recheck' ), 'recheck: disconnecting stops it' );
ok( false === get_option( 'sendbeam_connect_recheck_runs' ), 'recheck: and forgets the tally' );

sb_recheck_site( false );
sendbeam_on_deactivate();
ok( false === wp_next_scheduled( 'sendbeam_connect_recheck' ), 'recheck: deactivating the plugin stops it' );
ok( in_array( 'sendbeam_on_deactivate', $GLOBALS['stub']['deactivation'], true ), 'recheck: and WordPress is told to call that on deactivation' );

sb_connect_reset();
update_option( 'sendbeam_settings', array() );
$GLOBALS['stub']['cron'] = array();

// ── Where a form can actually be ───────────────────────────────────────
// Published post_content is one of perhaps six places a form ends up. A block
// theme keeps the footer in wp_template_part; a classic theme keeps it in a
// block widget; a page builder keeps its layout in post meta. All three used
// to tick nothing, leaving the owner with an unfinished checklist beside a
// working form.
/** A site using a form, with a database that answers as the test says. */
function sb_placement( $db, $options = array() ) {
	delete_transient( 'sendbeam_form_placed' );
	delete_option( 'sendbeam_form_placed' );
	delete_option( 'widget_block' );
	delete_option( 'sidebars_widgets' );
	delete_option( 'widget_text' );
	foreach ( $options as $name => $value ) {
		update_option( $name, $value );
	}
	update_option( 'sendbeam_settings', array( 'api_key' => 'sb_live_connectedconnectedxx', 'default_form' => $GLOBALS['form'] ) );
	update_option( 'sendbeam_popups', array() );
	$GLOBALS['stub']['db'] = $db;
	$GLOBALS['wpdb']       = new SendBeam_Stub_Db();
	$where                 = sendbeam_form_placement( true );
	$sql                   = implode( ' ', $GLOBALS['wpdb']->queries );
	unset( $GLOBALS['wpdb'] );
	return array( $where, $sql );
}

list( $sb_where, $sb_sql ) = sb_placement( array( 'posts' => true ) );
ok( 'content' === $sb_where, 'placed: a form on a published page is found' );
has( $sb_sql, 'wp:sendbeam/form', 'placed: the block is one of the things looked for' );
has( $sb_sql, '[sendbeam\\_form', 'placed: so is the shortcode, with the underscore escaped for LIKE' );
has( $sb_sql, 'https://sendbeam.io/f/' . $form, 'placed: and the form\'s own hosted URL, for an iframe a builder pasted in' );
has( $sb_sql, "post_type IN ( 'post', 'page', 'attachment' )", 'placed: every public post type is searched, not only posts and pages' );

list( $sb_where ) = sb_placement( array( 'templates' => true ) );
ok( 'template' === $sb_where, 'placed: a form in a block theme\'s template part is found' );

list( $sb_where ) = sb_placement( array( 'meta' => true ) );
ok( 'meta' === $sb_where, 'placed: a form a page builder stored beside the post is found' );

list( $sb_where, $sb_sql ) = sb_placement( array(), array( 'widget_block' => array( 2 => array( 'content' => '<!-- wp:sendbeam/form /-->' ) ) ) );
ok( 'widget' === $sb_where, 'placed: a form in a block widget is found' );
ok( '' === $sb_sql, 'placed: and found without asking the database at all' );

list( $sb_where ) = sb_placement(
	array(),
	array(
		'sidebars_widgets' => array( 'sidebar-1' => array( 'text-3' ) ),
		'widget_text'      => array( 3 => array( 'text' => 'Join us [sendbeam_form]' ) ),
	)
);
ok( 'widget' === $sb_where, 'placed: a text widget a sidebar actually holds is found' );

list( $sb_where ) = sb_placement(
	array(),
	array(
		'sidebars_widgets' => array( 'wp_inactive_widgets' => array( 'text-3' ) ),
		'widget_text'      => array( 3 => array( 'text' => 'Join us [sendbeam_form]' ) ),
	)
);
ok( '' === $sb_where, 'placed: a widget nobody has put in a sidebar is a leftover, not a form on the site' );

list( $sb_where ) = sb_placement( array() );
ok( '' === $sb_where, 'placed: a site with the form nowhere is not ticked off' );

// No list of six will ever be seven, so the owner gets the last word.
sb_connect_reset();
update_option( 'sendbeam_settings', array( 'api_key' => 'sb_live_connectedconnectedxx', 'default_form' => $form ) );
delete_option( 'sendbeam_form_placed' );
delete_transient( 'sendbeam_form_placed' );
$_GET     = array( '_wpnonce' => 'nonce:sendbeam_form_placed' );
$_REQUEST = $_GET;
$url      = sb_run_admin_post( 'sendbeam_handle_form_placed' );
has( $url, 'sendbeam_form=placed', 'placed: saying so is confirmed on the screen' );
ok( 'manual' === sendbeam_form_placement(), 'placed: and the step is ticked, without a database in sight' );

$_GET     = array( '_wpnonce' => 'nonce:sendbeam_form_placed', 'state' => '0' );
$_REQUEST = $_GET;
$url      = sb_run_admin_post( 'sendbeam_handle_form_placed' );
has( $url, 'sendbeam_form=unplaced', 'placed: and taking it back is possible' );
ok( 'manual' !== sendbeam_form_placement( true ), 'placed: the override really is gone' );

$_GET     = array();
$_REQUEST = array();
sb_run_admin_post( 'sendbeam_handle_form_placed' );
ok( 0 === strpos( $GLOBALS['stub']['exit'], 'wp_die' ), 'placed: no nonce, no override' );
$GLOBALS['stub']['caps']['manage_options'] = false;
$_GET     = array( '_wpnonce' => 'nonce:sendbeam_form_placed' );
$_REQUEST = $_GET;
sb_run_admin_post( 'sendbeam_handle_form_placed' );
ok( 0 === strpos( $GLOBALS['stub']['exit'], 'wp_die' ), 'placed: the capability is required' );

sb_connect_reset();
delete_option( 'sendbeam_form_placed' );
delete_transient( 'sendbeam_form_placed' );
update_option( 'sendbeam_settings', array() );
$GLOBALS['stub']['db'] = array();

// ── A blocked pop-up ───────────────────────────────────────────────────
sb_connect_reset();
$_POST    = array( '_wpnonce' => 'nonce:sendbeam_connect_start', 'sendbeam_popup' => '0' );
$_REQUEST = $_POST;
try {
	sendbeam_connect_start();
} catch ( SendBeamStubExit $e ) {
	$GLOBALS['stub']['exit'] = $e->getMessage();
}
$blocked_state = get_transient( 'sendbeam_connect_7' );
ok( isset( $blocked_state['popup'] ) && 0 === $blocked_state['popup'], 'pop-up blocked: the flow remembers it is happening in this tab' );

$GLOBALS['stub']['remote_reply'] = array( 'response' => array( 'code' => 200 ), 'body' => sb_v2_body() );
$_GET                            = array( 'state' => $blocked_state['state'], 'grant' => $sendbeam_good_grant );
$blocked_html                    = sb_connect_run( 'sendbeam_connect_return' );
has( $blocked_html, 'Connected', 'pop-up blocked: the connection still completes' );
has( $blocked_html, 'Back to the site', 'pop-up blocked: the page offers a way back instead of a dead end' );
lacks( $blocked_html, 'window.close()', 'pop-up blocked: nothing tries to close the tab the person is using' );
lacks( $blocked_html, 'postMessage', 'pop-up blocked: there is no opener to message' );

// The pop-up path is unchanged.
sb_connect_reset();
$_POST    = array( '_wpnonce' => 'nonce:sendbeam_connect_start', 'sendbeam_popup' => '1' );
$_REQUEST = $_POST;
try {
	sendbeam_connect_start();
} catch ( SendBeamStubExit $e ) {
	$GLOBALS['stub']['exit'] = $e->getMessage();
}
$popup_state                     = get_transient( 'sendbeam_connect_7' );
$GLOBALS['stub']['remote_reply'] = array( 'response' => array( 'code' => 200 ), 'body' => sb_v2_body() );
$_GET                            = array( 'state' => $popup_state['state'], 'grant' => $sendbeam_good_grant );
$popup_html                      = sb_connect_run( 'sendbeam_connect_return' );
has( $popup_html, 'window.close()', 'pop-up: a real pop-up still closes itself' );
has( $popup_html, 'Back to SendBeam settings', 'pop-up: the link underneath still points at the settings screen' );

// The script flips the flag the form carries.
has( sendbeam_connect_admin_js(), 'sb-connect-popup', 'pop-up: the script sets the flag it was given' );

// ── The Overview itself ────────────────────────────────────────────────
// No test could see a settings screen before, which is how a permission
// check or a form could drift on the page layer without anything noticing.
// These render the real function and read the real HTML.

/**
 * Render the Overview for a site in a given state.
 *
 * @param array $settings Saved settings.
 * @param array $status   Status payload, as the API would send it.
 * @param array $get      Query arguments on the screen.
 * @return string
 */
function sb_overview( $settings, $status, $get = array() ) {
	$GLOBALS['stub']['caps']['manage_options'] = true;
	$GLOBALS['stub']['user_id']                = 7;
	$GLOBALS['stub']['current_user']           = array( 'email' => 'admin@example-site.test' );
	$GLOBALS['stub']['remote']                 = array();
	$_GET                                      = $get;
	update_option( 'sendbeam_settings', $settings );
	set_transient( 'sendbeam_connection', array( 'state' => '' === (string) ( $settings['api_key'] ?? '' ) ? 'none' : 'ok', 'message' => '', 'count' => 1 ) );
	set_transient( 'sendbeam_remote_forms', array() );
	set_transient( 'sendbeam_remote_lists', array() );
	set_transient( 'sendbeam_subscriber_count', 0 );
	sendbeam_connect_cache_status( sendbeam_connect_normalise_status( $status ) );
	ob_start();
	sendbeam_screen_overview();
	return ob_get_clean();
}

$sb_connected = array(
	'api_key'                    => 'sb_live_connectedconnectedxx',
	'sendbeam_connected_via'     => 'connect',
	'sendbeam_connect_workspace' => 'Harbour Lane',
	'sendbeam_connect_granted'   => 'forms,transactional:send,domain',
	'sendbeam_mail_deferred'     => 1,
);
$sb_unverified = array(
	'workspace'    => array( 'id' => 'ws_1', 'name' => 'Harbour Lane' ),
	'default_form' => array( 'id' => $form, 'name' => 'Newsletter signup' ),
	'domain'       => array(
		'name'               => 'harbourlane.co.uk',
		'verified'           => false,
		'records'            => array(
			array( 'type' => 'TXT', 'name' => '_sendbeam', 'value' => 'v=sb1 k=abc' ),
			array( 'type' => 'MX', 'name' => 'send', 'value' => '10 feedback-smtp.eu-west-1.amazonses.com' ),
		),
		'domain_connect_url' => 'https://sendbeam.io/dc/1',
		'checked_at'         => '2026-09-23T15:04:05Z',
	),
	'sender'       => array( 'from_name' => 'Harbour Lane', 'from_email' => 'hello@harbourlane.co.uk' ),
);

$ov = sb_overview( $sb_connected, $sb_unverified );
has( $ov, 'Verify your sending domain', 'overview: the domain step is on the checklist' );
has( $ov, 'Put a form on the site', 'overview: the form step is on the checklist' );
has( $ov, 'Send this site&#039;s email through SendBeam', 'overview: the site email step is on the checklist' );
has( $ov, 'v=sb1 k=abc', 'overview: the DNS records are shown, not linked to' );
has( $ov, '10 feedback-smtp.eu-west-1.amazonses.com', 'overview: every record is shown' );
has( $ov, 'data-copy="10 feedback-smtp.eu-west-1.amazonses.com"', 'overview: each record value has its own Copy button' );
has( $ov, 'Check now', 'overview: the domain can be re-checked without leaving wp-admin' );
has( $ov, 'Last checked 23 September 2026, 3:04 pm', 'overview: the last check is a time the site owner can read, in their own format' );
lacks( $ov, '2026-09-23T15:04:05Z', 'overview: the raw ISO timestamp is never printed at anybody' );
has( $ov, 'value="sendbeam_domain_check"', 'overview: Check now posts to the check handler' );
has( $ov, 'nonce:sendbeam_domain_check', 'overview: Check now carries a nonce' );
has( $ov, 'Set up DNS automatically', 'overview: the registrar one-click button is offered' );
has( $ov, 'https://sendbeam.io/dc/1', 'overview: the one-click button points at the link SendBeam sent' );
has( $ov, 'target="_blank"', 'overview: the one-click button opens in a new tab' );
has( $ov, 'Disconnect', 'overview: Disconnect is visible on the connected card' );
has( $ov, 'value="sendbeam_disconnect"', 'overview: Disconnect posts to the disconnect handler' );
has( $ov, 'nonce:sendbeam_disconnect', 'overview: Disconnect carries a nonce' );
has( $ov, 'sb-confirm', 'overview: Disconnect is confirmed before it runs' );
has( $ov, 'Your list, form and sending domain stay in SendBeam', 'overview: the confirmation says what disconnecting does not take away' );
lacks( $ov, 'confirm(', 'overview: the confirmation is the plugin\'s own, not a browser dialog' );
lacks( $ov, 'Switch on', 'overview: site email cannot be switched on while the domain is unverified' );
has( $ov, 'Waiting on step 2', 'overview: the site email step says what it is waiting for' );
lacks( $ov, 'sb_live_connectedconnectedxx', 'overview: the key is never printed on the screen' );

$ov = sb_overview( $sb_connected, $sb_unverified, array( 'sendbeam_domain' => 'pending' ) );
has( $ov, 'Not verified yet', 'overview: a check that found nothing says so at the top' );
$ov = sb_overview( $sb_connected, $sb_unverified, array( 'sendbeam_disconnected' => 'unreachable' ) );
has( $ov, 'revoke it under Settings', 'overview: a disconnect SendBeam never heard about says where to finish the job' );
$ov = sb_overview( $sb_connected, $sb_unverified, array( 'sendbeam_domain' => '<script>alert(1)</script>' ) );
lacks( $ov, 'alert(1)', 'overview: a notice name from the URL is not a message to print' );

// Verified, site email approved but not yet on: one button.
$sb_verified                        = $sb_unverified;
$sb_verified['domain']['verified']  = true;
$ov = sb_overview( $sb_connected, $sb_verified );
has( $ov, 'Verified', 'overview: a verified domain is ticked off' );
has( $ov, 'Switch on', 'overview: site email offers one button once the domain is verified' );
has( $ov, 'value="sendbeam_mail_switch_on"', 'overview: Switch on posts to the switch-on handler' );
has( $ov, 'nonce:sendbeam_mail_switch_on', 'overview: Switch on carries a nonce' );
has( $ov, 'hello@harbourlane.co.uk', 'overview: Switch on says which address email will come from' );

// Verified, but the key was never given the permission.
$sb_noscope                             = $sb_connected;
$sb_noscope['sendbeam_connect_granted'] = 'forms,domain';
$ov = sb_overview( $sb_noscope, $sb_verified );
lacks( $ov, 'Switch on', 'overview: no Switch on button for a key that cannot send' );
has( $ov, 'not given permission to send', 'overview: the screen says the permission is what is missing' );

// Site email already on: the test send is right there.
$sb_on                 = $sb_connected;
$sb_on['mail_enabled'] = 1;
$ov = sb_overview( $sb_on, $sb_verified );
has( $ov, 'Send a test email', 'overview: site email that is on offers the test send' );
has( $ov, 'nonce:sendbeam_test_mail', 'overview: the test send carries a nonce' );

// The steps that name a missing permission offer the way out of it.
$sb_nodomain_scope = $sb_connected;
$sb_nodomain_scope['sendbeam_connect_granted'] = 'forms,transactional:send';
$ov = sb_overview( $sb_nodomain_scope, array( 'workspace' => array( 'id' => 'ws_1' ), 'domain_state' => 'not_granted' ) );
has( $ov, 'Reconnect with more permissions', 'overview: a step that names a missing permission offers to reconnect' );
has( $ov, 'action=sendbeam_connect_start', 'overview: the reconnect link starts the Connect flow' );
$ov = sb_overview( $sb_connected, $sb_verified );
lacks( $ov, 'Reconnect with more permissions', 'overview: a step with nothing missing does not offer it' );
has( $ov, '>Reconnect<', 'overview: the connected card offers a plain Reconnect beside Disconnect' );

// ── Back from the registrar ────────────────────────────────────────────
// Automatic DNS used to end on SendBeam's own sending settings: a reasonable
// place to finish if you started there, and the wrong one entirely when you
// started in a wp-admin checklist and expected to come back and see the step
// ticked.
$ov = sb_overview( $sb_connected, $sb_unverified );
has( $ov, 'return_to=' . rawurlencode( 'https://www.example-site.test/wp-admin/options-general.php?page=sendbeam&tab=overview' ), 'automatic DNS: the one-click link tells SendBeam where to send the owner back to' );
has( $ov, 'https://sendbeam.io/dc/1?return_to=', 'automatic DNS: and is otherwise the link SendBeam sent' );

$GLOBALS['stub']['remote_reply'] = array( 'response' => array( 'code' => 200 ), 'body' => sb_status_body( true ) );
sendbeam_connect_forget_status();
$ov = sb_overview( $sb_connected, $sb_unverified, array( 'sb_dc' => 'done' ) );
has( $ov, 'Your registrar added the records. Checking now', 'automatic DNS: coming back says what happened' );
$sb_posts = array_filter( $GLOBALS['stub']['remote'], function ( $r ) { return 'POST' === $r['method']; } );
ok( 1 === count( $sb_posts ), 'automatic DNS: coming back spends exactly one check' );
ok( ! empty( sendbeam_settings()['mail_enabled'] ), 'automatic DNS: a domain that verifies finishes the held-back switch-on' );

sendbeam_connect_forget_status();
$ov = sb_overview( $sb_connected, $sb_unverified, array( 'sb_dc' => 'error', 'sb_dc_reason' => 'state_mismatch' ) );
has( $ov, 'Your registrar could not add the records (state mismatch)', 'automatic DNS: a refusal says why, in SendBeam\'s own reason' );
has( $ov, 'Add them by hand below', 'automatic DNS: and points at the records that are right there' );
ok( empty( array_filter( $GLOBALS['stub']['remote'], function ( $r ) { return 'POST' === $r['method']; } ) ), 'automatic DNS: a refusal spends no check' );

$ov = sb_overview( $sb_connected, $sb_unverified, array( 'sb_dc' => '<script>alert(1)</script>' ) );
lacks( $ov, 'alert(1)', 'automatic DNS: the flag is one of two values, not a message to print' );
lacks( $ov, 'Checking now', 'automatic DNS: and anything else is ignored' );
has( sendbeam_connect_admin_js(), 'sb_dc', 'automatic DNS: the flag is taken off the address bar once it has been read' );

// A key pinned in wp-config.php beats anything stored here, so connecting
// would mint a real key, save it, and never use it.
$ov = sb_overview( array(), array() );
has( $ov, 'SENDBEAM_API_KEY in wp-config.php', 'overview: a site with the key pinned in wp-config is told where its key is' );
lacks( $ov, 'Connect SendBeam', 'overview: and is not offered a button that would store a key nothing uses' );
lacks( $ov, 'I already have an API key', 'overview: nor a paste field whose key wp-config.php would overrule' );
lacks( $ov, 'sendbeam_settings[api_key]', 'overview: the paste field itself is gone, not merely folded away' );
$ov = sb_overview( $sb_connected, $sb_verified );
has( $ov, 'Disconnect will not revoke it', 'overview: the connected card says the live key is the pinned one' );

// A site connected with a key pasted by hand: no recorded permissions, but a
// domain it can plainly read. The screen must not print a table of records
// under a sentence saying it has no permission to read them.
$ov = sb_overview( array( 'api_key' => 'sb_live_pastedpastedpasted' ), $sb_unverified );
has( $ov, 'v=sb1 k=abc', 'overview: a pasted key still gets its DNS records' );
lacks( $ov, 'not given permission to read', 'overview: nothing claims a missing permission while the records are on screen' );

// No domain at all: no table, no Check now, and the page where it is fixed.
$sb_nodomain = array( 'workspace' => array( 'id' => 'ws_1', 'name' => 'Harbour Lane' ), 'domain_state' => 'no_site' );
$ov = sb_overview( $sb_connected, $sb_nodomain );
has( $ov, 'No sending domain is set up for this site', 'overview: a site with no domain is told so once' );
has( $ov, '/settings/domains', 'overview: and handed the page where it is added' );
lacks( $ov, 'table class="sb-table sb-dns"', 'overview: no records table for a domain that does not exist' );
lacks( $ov, 'Check now', 'overview: nothing offers to check a domain that does not exist' );


sb_connect_reset();
sendbeam_connect_forget_status();
delete_transient( 'sendbeam_connection' );
delete_transient( 'sendbeam_remote_forms' );
delete_transient( 'sendbeam_remote_lists' );
delete_transient( 'sendbeam_subscriber_count' );
update_option( 'sendbeam_settings', array() );

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
