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

/*
 * The classes are not declared until a screen that needs one asks for them.
 * They extend WP_List_Table, which lives in wp-admin/includes and is NOT
 * loaded when plugins are — so declaring them at plugin-load time is a fatal
 * error on every page of a real site. Nothing but loading the plugin inside
 * WordPress shows that, which is why it is asserted here.
 */
ok( ! class_exists( 'SendBeam_Forms_Table', false ), 'list tables: nothing extends WP_List_Table at plugin-load time' );
ok( sendbeam_load_list_table(), 'list tables: a screen that needs them can load them' );
ok( class_exists( 'SendBeam_Forms_Table', false ), 'list tables: and then the Forms table exists' );
ok( class_exists( 'SendBeam_Mail_Log_Table', false ), 'list tables: and the email log' );
ok( class_exists( 'SendBeam_Lists_Table', false ), 'list tables: and the audience lists' );
ok( sendbeam_load_list_table(), 'list tables: asking twice is harmless' );

/*
 * The email log is a table now, so the whole run needs a $wpdb. The one test
 * that deliberately runs without one swaps it out and puts it back.
 */
$GLOBALS['wpdb'] = new SendBeam_Stub_MailDb();

/*
 * Register the option for real, so update_option() runs the sanitiser the way
 * WordPress does. Without this the whole class of "a programmatic write goes
 * through the form's rules" bug is invisible here.
 */
sendbeam_admin_init();
ok( isset( $GLOBALS['stub']['sanitize']['sendbeam_settings'] ), 'settings: the option is registered with its sanitiser, as WordPress has it' );

/** The log, newest first, the way every screen reads it. */
function sb_log() {
	$rows = $GLOBALS['wpdb']->rows;
	usort( $rows, function ( $a, $b ) { return ( (int) $b['id'] ) - ( (int) $a['id'] ); } );
	return array_values( $rows );
}

/** Empty it between tests. */
function sb_log_reset() {
	$GLOBALS['wpdb']->rows    = array();
	$GLOBALS['wpdb']->next_id = 1;
	$GLOBALS['wpdb']->queries = array();
}

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
sb_seed_settings( array( 'api_key' => 'sb_live_keptkeptkept', 'default_form' => $form, 'mail_enabled' => 1 ) );
$kept = sendbeam_sanitize_settings( array( '_tab' => 'forms', 'default_form' => $other, 'contact_form' => '' ) );
ok( $kept['api_key'] === 'sb_live_keptkeptkept', 'saving Forms keeps the API key' );
ok( (int) $kept['mail_enabled'] === 1, 'saving Forms keeps site email on' );
$off = sendbeam_sanitize_settings( array( '_tab' => 'mail' ) );
ok( (int) $off['mail_enabled'] === 0, 'unchecking site email on its own tab turns it off' );
sb_seed_settings( array() );

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

sb_seed_settings( array( 'default_form' => $form, 'contact_form' => $other, 'popup_form' => $other, 'popup_where' => 'posts', 'popup_delay' => 3, 'popup_once' => 'week', 'popup_trigger' => 'timer' ) );
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
sb_seed_settings( array_merge( sendbeam_settings(), array( 'style_accent' => '#123456' ) ) );
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
sb_seed_settings( array( 'popup_form' => $form, 'popup_where' => 'home', 'popup_trigger' => 'button', 'popup_delay' => 3, 'popup_once' => 'week', 'popup_label' => 'Join us' ) );
$migrated = sendbeam_popups();
ok( count( $migrated ) === 1, 'legacy pop-up migrates to one rule' );
ok( $migrated[0]['form'] === $form && $migrated[0]['where'] === 'home', 'migrated form and placement' );
ok( $migrated[0]['trigger'] === 'button' && $migrated[0]['label'] === 'Join us', 'migrated trigger and label' );
delete_option( 'sendbeam_popups' );

// Block render.
$GLOBALS['stub']['printed'] = '';
ob_start();
$attributes = array( 'formId' => '', 'height' => 400 );
sb_seed_settings( array( 'default_form' => $form ) );
include dirname( __DIR__ ) . '/blocks/form/render.php';
$block = ob_get_clean();
has( $block, 'class="wp-block-sendbeam-form sendbeam-form-wrap"', 'block wrapper' );
has( $block, '/f/' . $form . '?embed=1', 'block falls back to the default form' );
has( $block, 'height:400px', 'block height' );

// The app URL filter.
add_filter( 'sendbeam_app_url', function () { return 'https://mail.example.com/'; } );
has( sendbeam_form_html( $form ), 'src="https://mail.example.com/f/', 'app url filter' );

// Appearance: only chosen values are sent, and a bad colour never leaves here.
sb_seed_settings( array( 'style_accent' => '#B45309', 'style_radius' => '2', 'style_font' => 'serif', 'style_text' => 'rgb(1,2,3)' ) );
$args = sendbeam_appearance_args();
ok( $args['accent'] === '%23b45309', 'accent is hex-escaped — a bare # would start the URL fragment' );
has( sendbeam_form_url( $form ), 'accent=%23b45309', 'the escaped colour survives into the iframe URL' );
lacks( sendbeam_form_url( $form ), 'accent=#', 'no bare hash in the URL' );
ok( $args['radius'] === '2', 'radius passed' );
ok( $args['font'] === 'serif', 'font passed' );
ok( ! isset( $args['text'] ), 'a non-hex colour is not sent at all' );
$clean_style = sendbeam_sanitize_settings( array( '_tab' => 'forms_style', 'style_accent' => 'red', 'style_radius' => '999', 'style_font' => 'Comic Sans' ) );
ok( $clean_style['style_accent'] === '#B45309', 'a bad colour leaves the saved one untouched' );
ok( $clean_style['style_radius'] === '28', 'radius clamped on save' );
ok( $clean_style['style_font'] === 'inherit', 'an unknown font falls back' );
sb_seed_settings( array() );

// ── Site email ──────────────────────────────────────────────────────────
$stub = &$GLOBALS['stub'];
unset( $stub['hooks']['sendbeam_app_url'] ); // the app-url filter test above must not leak into here
$stub['remote_reply'] = array( 'response' => array( 'code' => 200 ), 'body' => '{"ok":true,"sent":[{"to":"a@b.test","message_id":"m1"}]}' );
$mail_atts = array( 'to' => 'Ada <ada@customer.test>', 'subject' => 'Order #1001', 'message' => '<p>Thanks</p>', 'headers' => array( 'Content-Type: text/html; charset=UTF-8', 'Reply-To: Shop <shop@example-site.test>', 'Bcc: owner@example-site.test', 'X-Order-Id: 1001', 'List-Unsubscribe: <x>' ), 'attachments' => array() );

// Off by default: WordPress keeps sending.
sb_seed_settings( array() );
ok( null === apply_filters( 'pre_wp_mail', null, $mail_atts ), 'disabled → null (WordPress sends)' );
ok( count( $stub['remote'] ) === 0, 'no API call when disabled' );

// On, with a saved key: relayed, headers mapped, From falls back to the workspace sender.
sb_seed_settings( array( 'mail_enabled' => 1, 'api_key' => 'sb_live_abcdefghijklmnop', 'mail_fallback' => 1 ) );
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
$log = sb_log();
ok( $log[0]['result'] === 'sent' && $log[0]['to_addr'] === 'Ada <ada@customer.test>', 'log entry' );
ok( '' !== $log[0]['sent_at'] && 1 === preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $log[0]['sent_at'] ), 'log entry: with a UTC datetime, not a Unix stamp' );

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
sb_seed_settings( array( 'mail_enabled' => 1, 'api_key' => 'sb_live_abcdefghijklmnop', 'mail_fallback' => 1, 'mail_from_email' => 'hello@example-site.test', 'mail_from_name' => 'Example' ) );
apply_filters( 'pre_wp_mail', null, array( 'to' => 'a@b.test', 'subject' => 's', 'message' => 'm', 'headers' => 'From: x@y.test' ) );
$sent = json_decode( $stub['remote'][0]['args']['body'], true );
ok( $sent['from_email'] === 'hello@example-site.test' && $sent['from_name'] === 'Example', 'settings From wins' );

// Attachments → server mailer.
$stub['remote'] = array();
ok( null === apply_filters( 'pre_wp_mail', null, array( 'to' => 'a@b.test', 'subject' => 's', 'message' => 'm', 'headers' => '', 'attachments' => array( '/tmp/invoice.pdf' ) ) ), 'attachments → null' );
ok( count( $stub['remote'] ) === 0 && sb_log()[0]['result'] === 'fallback', 'no call, logged as fallback' );

// API refusal: fallback on → null; fallback off → false + wp_mail_failed.
$stub['remote_reply'] = array( 'response' => array( 'code' => 403 ), 'body' => '{"error":"Monthly email limit reached"}' );
ok( null === apply_filters( 'pre_wp_mail', null, array( 'to' => 'a@b.test', 'subject' => 's', 'message' => 'm' ) ), 'refused + fallback → null' );
ok( sb_log()[0]['note'] === 'Monthly email limit reached', 'API error in the log' );
sb_seed_settings( array( 'mail_enabled' => 1, 'api_key' => 'sb_live_abcdefghijklmnop', 'mail_fallback' => 0 ) );
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
sb_seed_settings( array( 'api_key' => 'sb_live_ecommercetest0123456789' ) );
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
ok( '501' === $sendbeam_order_job['order_id'] && ! empty( $sendbeam_order_job['placed_at'] ) && false !== strtotime( $sendbeam_order_job['placed_at'] ), 'order placed: carries the order id and the time it was placed, so SendBeam stores and attributes it (#105)' );
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
// Step 1 names the thing it is asking for. It pointed at the paste field,
// which is the one way of connecting this plugin no longer leads with, so
// anything following the checklist walked straight past the button to the
// fallback folded away underneath it.
has( $sb_panel, 'id="sendbeam-connect"', 'panel: the Connect panel is something a link can land on' );
$sb_steps = sendbeam_setup_steps();
has( $sb_steps[0]['target'], '#sendbeam-connect', 'steps: step 1 points at the Connect panel' );
lacks( $sb_steps[0]['target'], '#sendbeam_api_key', 'steps: not at the paste field underneath it' );

sb_connect_reset();
sendbeam_connect_forget_status();
delete_transient( 'sendbeam_connection' );
delete_transient( 'sendbeam_remote_forms' );
delete_transient( 'sendbeam_remote_lists' );
delete_transient( 'sendbeam_subscriber_count' );
sb_seed_settings( array() );

/* ───────────────────────────── The surfaces ────────────────────────────
 * The sending domain, the test email, Site Health and the notices — the four
 * places a site owner finds out what is actually going on.
 */
$GLOBALS['stub']['caps']['manage_options'] = true;
$GLOBALS['stub']['user_id']                = 7;
$GLOBALS['stub']['current_user']           = array( 'email' => 'admin@example-site.test' );

// ── The sending domain, in MailPoet's shape ─────────────────────────────
function sb_domain_panel( $records, $verified, $standalone = false ) {
	$status = sendbeam_connect_normalise_status(
		array(
			'workspace' => array( 'id' => 'w1', 'name' => 'Harbour Lane' ),
			'domain'    => array(
				'name'       => 'harbourlane.co.uk',
				'verified'   => $verified,
				'records'    => $records,
				'checked_at' => '2026-09-23T15:04:05Z',
			),
		)
	);
	ob_start();
	sendbeam_domain_panel( $status, $standalone );
	return ob_get_clean();
}

$sb_records_none = array(
	array( 'type' => 'CNAME', 'name' => 'sb1._domainkey', 'value' => 'sb1.dkim.sendbeam.io', 'found' => false ),
	array( 'type' => 'CNAME', 'name' => 'sb2._domainkey', 'value' => 'sb2.dkim.sendbeam.io', 'found' => false ),
);
$sb_records_some = $sb_records_none;
$sb_records_some[0]['found'] = true;

$panel = sb_domain_panel( $sb_records_none, false, true );
has( $panel, 'class="widefat striped sb-dns"', 'domain: the records sit in a core widefat striped table' );
has( $panel, '<th scope="col">Type</th>', 'domain: Type is a column' );
has( $panel, '<th scope="col">Host</th>', 'domain: Host is a column — the word registrars use' );
has( $panel, '<th scope="col">Value</th>', 'domain: Value is a column' );
has( $panel, '<th scope="col">Found</th>', 'domain: and the check\'s verdict per row' );
lacks( $panel, 'Priority', 'domain: every record is a CNAME, so nothing asks for a priority' );
has( $panel, 'class="sb-copy-field sb-copy"', 'domain: each value is a field that copies when it is clicked' );
has( $panel, 'readonly', 'domain: and cannot be edited into something wrong' );
/*
 * A textarea, not an input. An input cannot wrap, and at 393px the value
 * column is about ninety pixels wide — enough to read "resend._dc" and
 * nothing else, on the one screen whose whole job is reading these.
 */
has( $panel, '<textarea class="sb-copy-field sb-copy"', 'domain: the field is a textarea, so a long value can wrap instead of being cut off' );
has( $panel, '>sb1.dkim.sendbeam.io</textarea>', 'domain: with the value as its content' );
lacks( $panel, '<input type="text" class="sb-copy-field', 'domain: and not an input, which cannot wrap at any width' );

// The header row disappears on a phone, so each cell says which column it is.
has( $panel, 'class="sb-dns__type sb-mono" data-label="Type"', 'domain: the type cell knows its column' );
has( $panel, 'class="sb-dns__host" data-label="Host"', 'domain: and the host cell' );
has( $panel, 'class="sb-dns__value" data-label="Value"', 'domain: and the value cell' );
has( $panel, 'class="sb-dns__found" data-label="Found"', 'domain: and the verdict cell' );
has( $panel, 'title="Click to copy"', 'domain: the field says what clicking it does' );
has( $panel, 'data-done="Copied"', 'domain: and what it did' );
has( $panel, 'sb1.dkim.sendbeam.io', 'domain: the value a registrar needs is on the screen' );
has( $panel, 'screen-reader-text', 'domain: every copy field is labelled for a screen reader' );
has( $panel, 'up to 24 hours', 'domain: the panel says DNS is slow before somebody decides it is broken' );
has( $panel, 'sb-numbered', 'domain: the two things to do are numbered' );
has( $panel, 'Check now', 'domain: and the check is right there' );
has( $panel, 'Proving that this domain is yours', 'domain: standing on its own, the panel says why any of this matters' );

// Three states, and the middle one is the one nobody else distinguishes.
has( sb_domain_panel( $sb_records_none, false ), '>Not found<', 'domain: nothing found yet says Not found' );
has( sb_domain_panel( $sb_records_none, false ), 'Nothing has been found in the DNS', 'domain: and says it in words' );
has( sb_domain_panel( $sb_records_some, false ), '>Pending<', 'domain: some records live says Pending' );
has( sb_domain_panel( $sb_records_some, false ), '1 of 2 records are live', 'domain: and says how far through it is' );
has( sb_domain_panel( $sb_records_some, true ), 'Verified — harbourlane.co.uk', 'domain: verified says so, and names the domain' );
has( sb_domain_panel( $sb_records_some, true ), 'Check again', 'domain: a verified domain can still be re-checked' );
lacks( sb_domain_panel( $sb_records_some, true ), 'sb-numbered', 'domain: a verified domain is not given a list of things to do' );

// ── The test email ──────────────────────────────────────────────────────
sb_seed_settings( array(
		'api_key'         => 'sb_live_testmailtestmailxx',
		'mail_enabled'    => 1,
		'mail_from_name'  => 'Harbour Lane Roasters',
		'mail_from_email' => 'hello@harbourlane.co.uk',
	)
);
set_transient( 'sendbeam_connection', array( 'state' => 'ok', 'message' => '', 'count' => 1 ) );
sendbeam_connect_cache_status(
	sendbeam_connect_normalise_status(
		array(
			'workspace' => array( 'id' => 'w1', 'name' => 'Harbour Lane' ),
			'domain'    => array( 'name' => 'harbourlane.co.uk', 'verified' => true, 'records' => $sb_records_some ),
			'sender'    => array( 'from_name' => 'Harbour Lane Roasters', 'from_email' => 'hello@harbourlane.co.uk' ),
		)
	)
);

/**
 * Render the test email for one set of settings, and hand back both halves
 * plus the classification it was built from.
 */
function sb_testmail( $from_email, $verified = true ) {
	$status = sendbeam_connect_normalise_status(
		array(
			'workspace' => array( 'id' => 'w1', 'name' => 'Harbour Lane' ),
			'domain'    => array( 'name' => 'harbourlane.co.uk', 'verified' => $verified, 'records' => array() ),
			'sender'    => array( 'from_name' => 'Harbour Lane Roasters', 'from_email' => 'ws-f7485df7@post.sendbeam.io' ),
		)
	);
	sb_seed_settings( array(
			'api_key'         => 'sb_live_testmailtestmailxx',
			'mail_enabled'    => 1,
			'mail_from_name'  => 'Harbour Lane Roasters',
			'mail_from_email' => $from_email,
		)
	);
	sendbeam_connect_cache_status( $status );
	$sender = sendbeam_effective_sender( $status );
	$facts  = sendbeam_mail_facts( $status );
	return array(
		'kind' => $sender['kind'],
		'html' => sendbeam_test_mail_html( $facts, $sender, $status ),
		'text' => sendbeam_test_mail_text( $facts, $sender, $status ),
		'next' => sendbeam_test_mail_next_step( $sender, $status ),
	);
}

/* ── own_domain: the only case where nothing is left to do ── */
$m = sb_testmail( 'hello@harbourlane.co.uk' );
ok( 'own_domain' === $m['kind'], 'test email: an address on the verified domain is the site\'s own' );
has( $m['html'], 'Your site can send email through SendBeam from harbourlane.co.uk', 'test email: the headline names the domain it came from' );
has( $m['html'], 'harbourlane.co.uk — verified and in use', 'test email: and the domain row says it is actually being used' );
has( $m['html'], 'Signed by', 'test email: there is a row for the domain that authenticated the message' );
lacks( $m['html'], 'Next step', 'test email: with nothing left to do, nothing is suggested' );
ok( null === $m['next'], 'test email: and no next step is generated' );

/* ── shared, verified domain: the case the owner caught ── */
$m = sb_testmail( 'ws-f7485df7@post.sendbeam.io' );
ok( 'shared' === $m['kind'], 'test email: the workspace default is SendBeam\'s shared address' );
has( $m['html'], 'Your site can send email through SendBeam (from the shared address for now)', 'test email: the headline says which address it came from' );
has( $m['html'], 'ws-f7485df7@post.sendbeam.io — SendBeam&#039;s shared address, not your domain', 'test email: the From row says what kind of address it is' );
has( $m['html'], 'harbourlane.co.uk — verified, but not in use yet', 'test email: and the domain row no longer implies it was used' );
lacks( $m['html'], 'harbourlane.co.uk — verified<', 'test email: the bare "verified" that read as "and in use" is gone' );
has( $m['html'], 'Next step', 'test email: there is a next step' );
has( $m['html'], 'press Send from harbourlane.co.uk', 'test email: which names the button that fixes it' );
has( $m['html'], 'what mailbox providers trust', 'test email: and says why it is worth doing' );
has( $m['html'], 'page=sendbeam-mail', 'test email: with a link straight to the screen' );
ok( strpos( $m['html'], 'Next step' ) < strpos( $m['html'], 'What happens now' ), 'test email: and it comes before the general explanation' );
// The rows a reader compares at a glance.
$sb_from_at   = strpos( $m['html'], 'SendBeam&#039;s shared address, not your domain' );
$sb_signed_at = strpos( $m['html'], 'post.sendbeam.io</td>' );
ok( false !== $sb_signed_at, 'test email: "Signed by" reports the domain that actually authenticated it' );

/* ── shared, unverified domain ── */
$m = sb_testmail( 'ws-f7485df7@post.sendbeam.io', false );
has( $m['html'], 'harbourlane.co.uk — not verified yet', 'test email: an unverified domain says so' );
has( $m['html'], 'Add the DNS records under Settings', 'test email: and the next step is to add them' );
has( $m['html'], 'tab=domain', 'test email: linking to the sending domain screen' );
has( $m['html'], 'on your behalf', 'test email: saying plainly what is happening meanwhile' );

/* ── other: a domain nothing has verified ── */
$m = sb_testmail( 'hello@somewhere-else.test' );
ok( 'other' === $m['kind'], 'test email: an address elsewhere is neither' );
has( $m['html'], 'somewhere-else.test is not a domain verified in this workspace', 'test email: the From row says so' );
has( $m['html'], 'SendBeam sent this under its own address', 'test email: and the next step says what actually happened to it' );
lacks( $m['html'], 'refuses messages from it', 'test email: not that SendBeam refused a message the reader is holding' );

/* ── none: nothing to report ── */
$m = sb_testmail( '' );
sb_seed_settings( array( 'api_key' => 'sb_live_testmailtestmailxx', 'mail_enabled' => 1 ) );
sendbeam_connect_cache_status(
	sendbeam_connect_normalise_status(
		array(
			'workspace' => array( 'id' => 'w1', 'name' => 'Harbour Lane' ),
			'domain'    => array( 'name' => 'harbourlane.co.uk', 'verified' => true, 'records' => array() ),
		)
	)
);
$sb_none_status = sendbeam_connect_status();
$sb_none_sender = sendbeam_effective_sender( $sb_none_status );
ok( 'none' === $sb_none_sender['kind'], 'test email: a site with no sender at all is classified as none' );
$sb_none_html = sendbeam_test_mail_html( sendbeam_mail_facts( $sb_none_status ), $sb_none_sender, $sb_none_status );
has( $sb_none_html, 'Your workspace sender', 'test email: which says the workspace sender will be used' );
lacks( $sb_none_html, 'Signed by', 'test email: and omits the rows it cannot fill in' );
lacks( $sb_none_html, 'Next step', 'test email: with nothing to suggest' );

/* ── The shape of the message, whatever it says ── */
$m = sb_testmail( 'hello@harbourlane.co.uk' );
has( $m['html'], '<!doctype html>', 'test email: it is a real HTML document' );
has( $m['html'], '<table', 'test email: laid out in tables, which is the only layout email clients agree on' );
has( $m['html'], 'width="600"', 'test email: 600px, the width every client renders' );
has( $m['html'], '@media only screen and (max-width:620px)', 'test email: and it collapses on a phone' );
has( $m['html'], '@media only screen and (max-width:480px)', 'test email: with the label above its value where a quarter of the width is not enough' );
has( $m['html'], 'word-break:break-word', 'test email: so a site URL breaks where it can rather than mid-word' );
has( $m['html'], 'name="color-scheme" content="light dark"', 'test email: dark mode is declared rather than left to the client to guess at' );
has( $m['html'], 'name="supported-color-schemes"', 'test email: in both of the ways Apple Mail reads' );
has( $m['html'], 'a[x-apple-data-detectors]', 'test email: and an address is not turned into a blue link of the client\'s own invention' );
has( $m['html'], 'bgcolor="#ffffff"', 'test email: every cell states its own background, so inverting the message does not swallow it' );
has( $m['html'], '#3B7D46', 'test email: the green tick' );
has( $m['html'], 'Harbour Lane Roasters', 'test email: the From name' );
has( $m['html'], 'SendBeam ' . SENDBEAM_VERSION, 'test email: and which version of the plugin sent it' );
has( $m['html'], 'Example Site', 'test email: and which site' );
has( $m['html'], 'What happens now', 'test email: it says what this changes' );
has( $m['html'], 'password resets', 'test email: and names the email that will now go this way' );
has( $m['html'], 'Open the email log', 'test email: with a link to the log' );
lacks( $m['html'], '<img', 'test email: no images, so nothing is blocked and nothing tracks an open' );
lacks( $m['html'], 'Upgrade', 'test email: no upsell' );

has( $m['text'], 'Your site can send email through SendBeam from harbourlane.co.uk', 'test email: the plain-text alternative says the same thing' );
has( $m['text'], 'hello@harbourlane.co.uk', 'test email: and carries the same facts' );
has( $m['text'], 'page=sendbeam-mail', 'test email: and the same link' );
lacks( $m['text'], '<', 'test email: the plain-text alternative has no markup in it' );

$m = sb_testmail( 'ws-f7485df7@post.sendbeam.io' );
has( $m['text'], 'Next step', 'test email: the plain-text alternative carries the next step too' );
has( $m['text'], 'Open Site email: ', 'test email: with the link written out' );

sb_seed_settings( array(
		'api_key'         => 'sb_live_testmailtestmailxx',
		'mail_enabled'    => 1,
		'mail_from_name'  => 'Harbour Lane Roasters',
		'mail_from_email' => 'hello@harbourlane.co.uk',
	)
);
sendbeam_connect_cache_status(
	sendbeam_connect_normalise_status(
		array(
			'workspace' => array( 'id' => 'w1', 'name' => 'Harbour Lane' ),
			'domain'    => array( 'name' => 'harbourlane.co.uk', 'verified' => true, 'records' => $sb_records_some ),
			'sender'    => array( 'from_name' => 'Harbour Lane Roasters', 'from_email' => 'hello@harbourlane.co.uk' ),
		)
	)
);

/** Press the button and take the result the screen would render. */
function sb_send_test() {
	$_REQUEST = array( '_wpnonce' => 'nonce:sendbeam_test_mail' );
	try {
		sendbeam_handle_test_mail();
	} catch ( SendBeamStubExit $e ) {
		unset( $e );
	}
	$_REQUEST = array();
	return sendbeam_take_test_result();
}

$GLOBALS['stub']['remote_reply'] = array( 'response' => array( 'code' => 200 ), 'body' => '{"ok":true}' );
$GLOBALS['stub']['remote']       = array();
$sent = sb_send_test();
ok( ! empty( $sent['ok'] ), 'test email: a 200 is a success' );
$sent_body = json_decode( $GLOBALS['stub']['remote'][0]['args']['body'], true );
ok( isset( $sent_body['html'] ), 'test email: the HTML half is sent' );
ok( isset( $sent_body['text'] ), 'test email: and so is the plain-text half' );
has( $sent_body['subject'], 'Test email from Example Site via SendBeam', 'test email: the subject names the site' );

ob_start();
sendbeam_test_mail_success( $sent );
$card = ob_get_clean();
has( $card, 'sb-result--ok', 'test email: success renders a success card' );
has( $card, 'Sent to admin@example-site.test — open your inbox to see it.', 'test email: which says where it went and what to do next' );
has( $card, 'Send another', 'test email: and offers to do it again' );

/*
 * Sent is not the same as sent from you. The card has to say which, or the
 * screen repeats the contradiction the email used to carry.
 */
foreach ( array(
	'hello@harbourlane.co.uk'      => array( false, 'own_domain' ),
	'ws-f7485df7@post.sendbeam.io' => array( true, 'shared' ),
	'hello@somewhere-else.test'    => array( true, 'other' ),
) as $sb_from => $sb_expect ) {
	list( $sb_wants_note, $sb_kind ) = $sb_expect;
	sb_testmail( $sb_from );
	$GLOBALS['stub']['remote_reply'] = array( 'response' => array( 'code' => 200 ), 'body' => '{"ok":true}' );
	$sb_sent                         = sb_send_test();
	ok( ! empty( $sb_sent['ok'] ), "result card: a send from $sb_kind succeeds" );
	ok( $sb_kind === $sb_sent['sender'], "result card: and the result records it as $sb_kind" );
	ob_start();
	sendbeam_test_mail_success( $sb_sent );
	$sb_card = ob_get_clean();
	if ( $sb_wants_note ) {
		has( $sb_card, 'sb-result--warn', "result card: $sb_kind gets an amber note beside the success" );
		has( $sb_card, 'Next step', "result card: saying what is left to do for $sb_kind" );
		has( $sb_card, 'sb-btn', "result card: with a way through to the screen that fixes it" );
	} else {
		lacks( $sb_card, 'sb-result--warn', 'result card: own_domain has nothing left to do, so nothing is added' );
		lacks( $sb_card, 'Next step', 'result card: and no next step is offered' );
	}
}

// A failure explains itself, and hands over everything support would ask for.
$GLOBALS['stub']['remote_reply'] = array( 'response' => array( 'code' => 403 ), 'body' => '{"error":"domain not verified"}' );
$failed = sb_send_test();
ok( empty( $failed['ok'] ), 'test email: a 403 is a failure' );
ok( ! empty( $failed['bundle'] ), 'test email: and the bundle is captured at the moment it failed' );
ob_start();
sendbeam_test_mail_failure( $failed );
$card = ob_get_clean();
has( $card, 'sb-result--bad', 'test email: failure renders a failure card' );
has( $card, 'SendBeam would not send that message', 'test email: with a title in plain words' );
has( $card, 'What it means', 'test email: it says what the failure means' );
has( $card, 'not given permission to send this site', 'test email: and the meaning matches the status code' );
has( $card, 'What to do', 'test email: it says what to do about it' );
has( $card, 'Settings → Sending domain', 'test email: and the advice matches the status code too' );
has( $card, 'SendBeam said: domain not verified', 'test email: the raw error is kept, not swallowed' );
has( $card, 'Details for support', 'test email: the bundle is there' );
has( $card, 'sb-copy', 'test email: one click from the clipboard' );
has( $card, 'Copy these details', 'test email: and says so' );
has( $card, 'WordPress 6.7.1', 'test email bundle: the WordPress version' );
has( $card, 'PHP ' . PHP_VERSION, 'test email bundle: the PHP version' );
has( $card, 'SendBeam ' . SENDBEAM_VERSION, 'test email bundle: the plugin version' );
has( $card, 'Key: stored in options', 'test email bundle: where the key lives, never the key' );
has( $card, 'harbourlane.co.uk — verified', 'test email bundle: the sending domain and its state' );
has( $card, 'HTTP: 403', 'test email bundle: the status code that came back' );
has( $card, 'Error: domain not verified', 'test email bundle: and the raw error' );
lacks( $card, 'sb_live_testmailtestmailxx', 'test email bundle: the API key is never in it' );
has( $card, 'Try again', 'test email: and the form comes back' );

// The result is read once, so a refresh does not replay it.
ok( null === sendbeam_take_test_result(), 'test email: the result is taken once, so refreshing does not show it again' );

// A server that never answered is a different sentence from a refusal.
$GLOBALS['stub']['remote_reply'] = array( 'response' => array( 'code' => 0 ), 'body' => '' );
$failed = sb_send_test();
has( $failed['means'], 'could not reach sendbeam.io at all', 'test email: a request that never landed says so' );
has( $failed['do'], 'outbound HTTPS', 'test email: and points at the thing that usually blocks it' );

// Site email off is not a send that failed.
sb_seed_settings( array( 'api_key' => 'sb_live_testmailtestmailxx', 'mail_enabled' => 0 ) );
$failed = sb_send_test();
has( $failed['title'], 'Site email is not switched on yet', 'test email: nothing is sent when the relay is off, and the card says so' );
has( $failed['do'], 'Tick "Send this site', 'test email: and says how to switch it on' );

// ── Site Health ─────────────────────────────────────────────────────────
$sendbeam_tests = sendbeam_site_status_tests( array() );
foreach ( array( 'sendbeam-key', 'sendbeam-domain', 'sendbeam-mail' ) as $sendbeam_t ) {
	ok( isset( $sendbeam_tests['direct'][ $sendbeam_t ] ), "site health: $sendbeam_t is registered" );
	ok( is_callable( $sendbeam_tests['direct'][ $sendbeam_t ]['test'] ), "site health: $sendbeam_t has a callable test" );
}

sb_seed_settings( array() );
sendbeam_flush_cache();
sendbeam_connect_forget_status();
$r = sendbeam_test_key();
ok( 'recommended' === $r['status'], 'site health: no key is a recommendation, not a failure — the plugin is simply not in use' );
has( $r['label'], 'SendBeam is not connected', 'site health: and says so' );

sb_seed_settings( array( 'api_key' => 'sb_live_healthhealthhealth' ) );
set_transient( 'sendbeam_connection', array( 'state' => 'rejected', 'message' => 'nope' ) );
$r = sendbeam_test_key();
ok( 'critical' === $r['status'], 'site health: a rejected key is critical — things have stopped working' );
set_transient( 'sendbeam_connection', array( 'state' => 'unreachable', 'message' => 'cURL error 28' ) );
$r = sendbeam_test_key();
ok( 'recommended' === $r['status'], 'site health: an unreachable SendBeam is a recommendation, not a fault in the site' );
has( $r['description'], 'cURL error 28', 'site health: and it quotes what the server actually reported' );
set_transient( 'sendbeam_connection', array( 'state' => 'ok', 'message' => '', 'count' => 1 ) );
$r = sendbeam_test_key();
ok( 'good' === $r['status'], 'site health: a working key is good' );

sendbeam_connect_cache_status(
	sendbeam_connect_normalise_status(
		array(
			'workspace' => array( 'id' => 'w1', 'name' => 'Harbour Lane' ),
			'domain'    => array( 'name' => 'harbourlane.co.uk', 'verified' => false, 'records' => $sb_records_none ),
		)
	)
);
$r = sendbeam_test_domain();
ok( 'recommended' === $r['status'], 'site health: an unverified domain is a recommendation' );
has( $r['actions'], 'tab=domain', 'site health: and links straight at the records' );

// The one that matters: the relay on behind a domain nothing has proved.
sb_seed_settings( array( 'api_key' => 'sb_live_healthhealthhealth', 'mail_enabled' => 1 ) );
$r = sendbeam_test_mail_relay();
ok( 'critical' === $r['status'], 'site health: site email on with an unverified domain is critical' );
has( $r['label'], 'unverified domain', 'site health: and names the problem' );

sendbeam_connect_cache_status(
	sendbeam_connect_normalise_status(
		array(
			'workspace' => array( 'id' => 'w1', 'name' => 'Harbour Lane' ),
			'domain'    => array( 'name' => 'harbourlane.co.uk', 'verified' => true, 'records' => $sb_records_some ),
		)
	)
);
$r = sendbeam_test_mail_relay();
ok( 'good' === $r['status'], 'site health: site email on behind a verified domain is good' );
sb_seed_settings( array( 'api_key' => 'sb_live_healthhealthhealth', 'mail_enabled' => 0 ) );
$r = sendbeam_test_mail_relay();
ok( 'good' === $r['status'], 'site health: site email off is not a fault — it is optional' );

// The debug section, and the same thing as one pasteable block.
$info = sendbeam_debug_information( array() );
ok( isset( $info['sendbeam'] ), 'site health: there is a SendBeam section in the debug info' );
$labels = wp_list_pluck_stub( $info['sendbeam']['fields'], 'label' );
foreach ( array( 'Plugin version', 'API key', 'Connection state', 'Sending domain', 'Site email', 'WordPress cron' ) as $sendbeam_f ) {
	ok( in_array( $sendbeam_f, $labels, true ), "site health: the debug info reports $sendbeam_f" );
}
$report = sendbeam_status_report();
has( $report, 'SendBeam ' . SENDBEAM_VERSION, 'system status: the report names the plugin version' );
has( $report, 'WordPress 6.7.1', 'system status: and the WordPress version' );
lacks( $report, 'sb_live_healthhealthhealth', 'system status: and never the key itself' );
has( $report, 'Stored in the options table (…alth)', 'system status: only where the key lives and how it ends' );

// ── Notices ─────────────────────────────────────────────────────────────
/** Capture what admin_notices prints on a given screen with a given query. */
function sb_notices( $screen, $get ) {
	$GLOBALS['stub']['screen'] = $screen;
	$_GET                      = $get;
	ob_start();
	sendbeam_admin_notices();
	$out  = ob_get_clean();
	$_GET = array();
	return $out;
}

has( sb_notices( 'toplevel_page_sendbeam', array( 'sendbeam_mail' => 'on' ) ), 'Site email is on', 'notices: a SendBeam screen shows the plugin\'s messages' );
has( sb_notices( 'toplevel_page_sendbeam', array( 'sendbeam_mail' => 'on' ) ), 'notice-success', 'notices: in core\'s own classes' );
has( sb_notices( 'toplevel_page_sendbeam', array( 'sendbeam_mail' => 'on' ) ), 'is-dismissible', 'notices: and every one can be closed' );
has( sb_notices( 'sendbeam_page_sendbeam-popups', array( 'sendbeam_saved' => '3' ) ), '3 pop-ups saved', 'notices: a count is printed as a count' );
has( sb_notices( 'sendbeam_page_sendbeam-audience', array( 'sendbeam_sync_saved' => '1' ) ), 'Saved.', 'notices: a save on another SendBeam screen is reported there' );
ok( '' === sb_notices( 'plugins', array( 'sendbeam_mail' => 'on' ) ), 'notices: nothing is printed on the Plugins screen' );
ok( '' === sb_notices( 'edit-post', array( 'sendbeam_saved' => '3' ) ), 'notices: nor on the posts list' );
ok( '' === sb_notices( 'woocommerce_page_wc-settings', array( 'sendbeam_domain' => 'verified' ) ), 'notices: nor on another plugin\'s settings screen' );
ok( '' === sb_notices( 'toplevel_page_sendbeam', array( 'sendbeam_mail' => 'nonsense-from-the-url' ) ), 'notices: a value nobody set prints nothing' );
lacks( sb_notices( 'toplevel_page_sendbeam', array( 'sendbeam_mail' => '<script>alert(1)</script>' ) ), 'alert(1)', 'notices: a notice name from the URL is not a message to print' );

// The one exception: the Dashboard, once, for a site nobody has set up.
delete_option( 'sendbeam_setup_done' );
sb_seed_settings( array() );
sendbeam_flush_cache();
$GLOBALS['stub']['usermeta'][7]['sendbeam_setup_dismissed'] = '';
unset( $GLOBALS['stub']['usermeta'][7]['sendbeam_setup_dismissed'] );
$dash = sb_notices( 'dashboard', array() );
has( $dash, 'SendBeam is installed', 'notices: the Dashboard carries the one setup notice' );
has( $dash, 'page=sendbeam-setup', 'notices: and it opens the wizard' );
has( $dash, 'is-dismissible', 'notices: and it can be dismissed' );
has( $dash, 'button button-primary', 'notices: inside a core notice the buttons are core\'s, which is where they belong' );

update_user_meta( 7, 'sendbeam_setup_dismissed', 1 );
ok( '' === sb_notices( 'dashboard', array() ), 'notices: dismissing the setup notice is remembered, per user' );
delete_user_meta( 7, 'sendbeam_setup_dismissed' );

update_option( 'sendbeam_setup_done', 1 );
ok( '' === sb_notices( 'dashboard', array() ), 'notices: and finishing the wizard removes it without anybody dismissing anything' );
delete_option( 'sendbeam_setup_done' );

ok( '' === sb_notices( 'edit-page', array() ), 'notices: the setup notice is on the Dashboard and nowhere else' );
ok( '' === sb_notices( 'plugins', array() ), 'notices: not even on the Plugins screen' );

sb_seed_settings( array() );
sendbeam_flush_cache();
$GLOBALS['stub']['remote_reply'] = null;
unset( $GLOBALS['stub']['screen'] );

/*
 * One connected card, however the key arrived. A site with a pasted key was
 * being shown "Connected" in the header band and **Connect this site** in the
 * card underneath it — the plugin arguing with itself about its own state.
 */
$GLOBALS['stub']['remote_reply'] = array(
	'response' => array( 'code' => 200 ),
	'body'     => wp_json_encode( array( 'forms' => array() ) ),
);
$GLOBALS['stub']['user_id']                = 7;
$GLOBALS['stub']['caps']['manage_options'] = true;

/** Render the connected card for a key that arrived one way or the other. */
function sb_connected_card( $via_connect ) {
	sb_seed_settings( array(
			'api_key'                    => 'sb_live_cardkeycardkeycard',
			'sendbeam_connected_via'     => $via_connect ? 'connect' : '',
			'sendbeam_connect_workspace' => $via_connect ? 'Harbour Lane' : '',
			'sendbeam_connect_granted'   => $via_connect ? 'forms,transactional:send,domain' : '',
		)
	);
	sendbeam_flush_cache();
	sendbeam_connect_cache_status( sendbeam_connect_normalise_status( array( 'workspace' => array( 'id' => 'w1', 'name' => 'Harbour Lane' ) ) ) );
	ob_start();
	sendbeam_connect_connected_panel( sendbeam_connect_status() );
	return ob_get_clean();
}

$sb_pasted = sb_connected_card( false );
has( $sb_pasted, 'Connected to Harbour Lane', 'pasted key: the card says what it is connected to' );
has( $sb_pasted, 'using an API key', 'pasted key: and how the key got here' );
ok( 1 === substr_count( $sb_pasted, 'sb-confirm__ask' ), 'pasted key: exactly one Disconnect' );
has( $sb_pasted, 'Disconnect', 'pasted key: which is the one action a connected card is for' );
lacks( $sb_pasted, 'Connect this site', 'pasted key: nothing offers to connect a site that says Connected above it' );
lacks( $sb_pasted, 'Replace the key', 'pasted key: nor to replace a key, which is a settings job' );
lacks( $sb_pasted, 'sb-btn--primary', 'pasted key: and there is no primary button on a card whose answer is "yes"' );
lacks( $sb_pasted, 'Reconnect', 'pasted key: Reconnect needs a connection to re-make, and a pasted key has none' );
/*
 * A native disclosure, closed. The old shape shipped the confirmation open
 * so a no-script browser could submit it and hid it on load, which meant a
 * red-bordered box flashed on the screen on every render.
 */
has( $sb_pasted, '<details class="sb-confirm">', 'confirm: it is a details' );
lacks( $sb_pasted, '<details class="sb-confirm" open', 'confirm: closed, so nothing of it renders until it is pressed' );
has( $sb_pasted, '<summary class="sb-btn sb-btn--danger sb-btn--small sb-confirm__ask">', 'confirm: whose summary is the Disconnect button itself' );
lacks( $sb_pasted, 'sb-confirm__ask" hidden', 'confirm: with nothing rendered hidden for script to reveal' );
ok(
	strpos( $sb_pasted, '<details class="sb-confirm">' ) < strpos( $sb_pasted, '<form action=' ),
	'confirm: and the details wraps the form that does it'
);
has( $sb_pasted, 'Cancel', 'confirm: pressing the summary again is the way out' );
has( $sb_pasted, 'nothing is revoked in SendBeam', 'pasted key: the confirmation says the key is left alone' );
has( $sb_pasted, 'may be in use somewhere else', 'pasted key: and why' );
lacks( $sb_pasted, 'checklist say anything useful', 'pasted key: the sales pitch is gone from the card' );

$sb_via = sb_connected_card( true );
has( $sb_via, 'Connected to Harbour Lane', 'connect-made: the card says what it is connected to' );
has( $sb_via, 'via Connect', 'connect-made: and how the key got here' );
ok( 1 === substr_count( $sb_via, 'sb-confirm__ask' ), 'connect-made: exactly one Disconnect' );
has( $sb_via, 'Reconnect', 'connect-made: with Reconnect beside it' );
has( $sb_via, 'action=sendbeam_connect_start', 'connect-made: starting the Connect flow' );
has( $sb_via, 'Its key is revoked', 'connect-made: the confirmation says the key is revoked' );
lacks( $sb_via, 'sb-btn--primary', 'connect-made: and no primary button here either' );
lacks( $sb_via, 'Connect this site', 'connect-made: nothing offers to connect an already-connected site' );

/*
 * The upgrade to Connect appears where a pasted key actually falls short —
 * the step that cannot answer without the permissions Connect grants — and
 * nowhere else.
 */
$sb_pasted_settings = array(
	'api_key'                  => 'sb_live_cardkeycardkeycard',
	'sendbeam_connected_via'   => '',
	'sendbeam_connect_granted' => '',
);
$ov = sb_overview( $sb_pasted_settings, array( 'workspace' => array( 'id' => 'w1' ), 'domain_state' => 'no_site' ) );
has( $ov, 'Switch to one-click Connect', 'pasted key: the domain step with no site host offers the upgrade' );
has( $ov, 'action=sendbeam_connect_start', 'pasted key: which starts the Connect flow' );
has( $ov, 'sendbeam_scopes=forms,contacts:write,transactional:send,ecommerce,domain', 'pasted key: with every permission preselected' );
lacks( $ov, 'Reconnect with more permissions', 'pasted key: worded as an upgrade, not as re-doing something' );
/*
 * Twice, and both of them earned: the domain step cannot name a sending
 * domain, and the mail step is queued behind it. The way out is the same
 * link, and offering it only on the step somebody is not standing on is one
 * hop too many.
 */
ok( 2 === substr_count( $ov, 'Switch to one-click Connect' ), 'pasted key: offered on the step that needs it and on the one waiting behind it' );
ok( 1 === substr_count( $ov, 'sb-confirm__ask' ), 'pasted key: and still exactly one Disconnect on the screen' );
lacks( $ov, 'Connect this site', 'pasted key: with nothing on the connected card offering to connect' );

// A domain that simply has not been added is not a reason to sell anything.
$ov = sb_overview( $sb_pasted_settings, array( 'workspace' => array( 'id' => 'w1' ), 'domain_state' => 'not_found' ) );
lacks( $ov, 'Switch to one-click Connect', 'pasted key: a missing domain row is not an argument for reconnecting' );

/*
 * "Was this key given that permission?" has three answers for a pasted key,
 * and the third is "no idea". The step was saying it as "no", which is a
 * claim about somebody's key that this site has no way of knowing.
 */
ok( ! sendbeam_connect_permissions_known(), 'pasted key: this site does not know what its key may do' );
lacks( $ov, 'was not given permission to send your site', 'pasted key: so the step does not claim a permission was withheld' );

$sb_verified_pasted = array(
	'workspace' => array( 'id' => 'w1', 'name' => 'Harbour Lane' ),
	'domain'    => array( 'name' => 'harbourlane.co.uk', 'verified' => true, 'records' => array() ),
	'sender'    => array( 'from_name' => 'Harbour Lane', 'from_email' => 'hello@harbourlane.co.uk' ),
);
$ov = sb_overview( $sb_pasted_settings, $sb_verified_pasted );
has( $ov, 'Switch on', 'pasted key: and the switch-on is offered rather than hidden behind a permission nobody recorded' );
lacks( $ov, 'Switch to one-click Connect', 'pasted key: with nothing selling Connect on a step that works' );

// A connection that DOES know, and was refused, still says so.
$sb_denied = array(
	'api_key'                  => 'sb_live_cardkeycardkeycard',
	'sendbeam_connected_via'   => 'connect',
	'sendbeam_connect_granted' => 'forms',
);
$ov = sb_overview( $sb_denied, $sb_verified_pasted );
has( $ov, 'was not given permission to send your site', 'connect-made: a permission actually withheld is still named' );

// The same offer, worded for a connection that exists.
$sb_via_settings = array(
	'api_key'                  => 'sb_live_cardkeycardkeycard',
	'sendbeam_connected_via'   => 'connect',
	'sendbeam_connect_granted' => 'forms',
);
$ov = sb_overview( $sb_via_settings, array( 'workspace' => array( 'id' => 'w1' ), 'domain_state' => 'not_granted' ) );
has( $ov, 'Reconnect with more permissions', 'connect-made: a permission nobody ticked is offered as a reconnect' );
lacks( $ov, 'Switch to one-click Connect', 'connect-made: not as a switch to something it already uses' );

// Nothing connected: the Connect panel, unchanged.
$ov = sb_overview( array(), array() );
has( $ov, 'Connect SendBeam', 'not connected: the Connect button is the primary action' );
has( $ov, 'sb-btn--primary', 'not connected: and it is primary' );
has( $ov, 'I already have an API key', 'not connected: with the paste field folded away under it' );
lacks( $ov, 'sb-confirm__ask', 'not connected: and nothing to disconnect' );

sb_seed_settings( array() );
$GLOBALS['stub']['remote_reply'] = null;
sendbeam_flush_cache();
sendbeam_connect_forget_status();

/*
 * The way back into the guide. Every plugin in the field study with a wizard
 * keeps one, and the owner needs it to test a change.
 */
$sendbeam_restart = sendbeam_wizard_restart_url();
has( $sendbeam_restart, 'action=sendbeam_wizard_restart', 'help: there is a way back into the setup guide' );
has( $sendbeam_restart, '_wpnonce=', 'help: with a nonce' );

$_GET = array( 'page' => 'sendbeam-help' );
ob_start();
sendbeam_render_admin_page();
$sendbeam_help = ob_get_clean();
$_GET = array();
has( $sendbeam_help, 'Run the setup guide again', 'help: and the Help page offers it' );
has( $sendbeam_help, 'action=sendbeam_wizard_restart', 'help: pointing at the handler' );
has( $sendbeam_help, 'Nothing is undone by opening it', 'help: saying plainly that it changes nothing' );

// It puts this person back at step 1, and leaves the site's own answer alone.
update_option( 'sendbeam_setup_done', 1 );
update_user_meta( 7, SENDBEAM_WIZARD_STEP, 3 );
$_GET     = array( '_wpnonce' => 'nonce:sendbeam_wizard_restart' );
$_REQUEST = $_GET;
unset( $GLOBALS['stub']['redirect'] );
try {
	sendbeam_handle_wizard_restart();
} catch ( SendBeamStubExit $e ) {
	unset( $e );
}
$_GET     = array();
$_REQUEST = array();
has( $GLOBALS['stub']['redirect']['url'], 'page=sendbeam-setup&step=1', 'help: running it again opens the guide at step 1' );
ok( '' === get_user_meta( 7, SENDBEAM_WIZARD_STEP, true ), 'help: having forgotten where this person got to' );
ok( sendbeam_wizard_done(), 'help: and without un-setting-up the site, which is what keeps the Dashboard notice away' );

// Without the nonce, nothing moves.
update_user_meta( 7, SENDBEAM_WIZARD_STEP, 3 );
$threw = false;
try {
	sendbeam_handle_wizard_restart();
} catch ( SendBeamStubExit $e ) {
	$threw = true;
}
ok( $threw, 'help: running it again without a nonce is refused' );
ok( 3 === (int) get_user_meta( 7, SENDBEAM_WIZARD_STEP, true ), 'help: and nothing was reset' );

delete_option( 'sendbeam_setup_done' );
delete_user_meta( 7, SENDBEAM_WIZARD_STEP );
sb_seed_settings( array() );
$GLOBALS['stub']['remote_reply'] = null;
sendbeam_flush_cache();
sendbeam_connect_forget_status();

/* ─────────────────────────── The setup wizard ──────────────────────────
 * One redirect, once, guarded three ways, into a page that can be left from
 * every step. The guards are the whole of the argument for doing this at all:
 * a redirect that fires twice, or during a bulk activation, is the behaviour
 * that makes people hate first-run wizards.
 */
$GLOBALS['stub']['user_id'] = 7;
$GLOBALS['stub']['caps']['manage_options'] = true;
delete_option( 'sendbeam_setup_done' );
sb_seed_settings( array() );
sendbeam_flush_cache();

/** Drive the redirect guard and say where it sent us, or '' for nowhere. */
function sb_wizard_redirect() {
	unset( $GLOBALS['stub']['redirect'] );
	try {
		sendbeam_maybe_redirect_to_wizard();
	} catch ( SendBeamStubExit $e ) {
		unset( $e );
	}
	return isset( $GLOBALS['stub']['redirect'] ) ? $GLOBALS['stub']['redirect']['url'] : '';
}

// Activation arms it, and the very next admin page load spends it.
$GLOBALS['stub']['transients'] = array();
sendbeam_on_activate();
ok( 1 === get_transient( SENDBEAM_WIZARD_FLAG ), 'wizard: activation arms the redirect' );
has( sb_wizard_redirect(), 'page=sendbeam-setup', 'wizard: the first admin page load after activating lands on the wizard' );
ok( '' === sb_wizard_redirect(), 'wizard: and the load after that does not — it fires once' );

// Twenty plugins at once must not throw somebody at this one's welcome page.
sendbeam_on_activate();
$_GET = array( 'activate-multi' => 'true' );
ok( '' === sb_wizard_redirect(), 'wizard: a bulk activation is left alone' );
$_GET = array();

// A site that already has a key has already done this.
sendbeam_on_activate();
sb_seed_settings( array( 'api_key' => 'sb_live_alreadysetupkey' ) );
ok( '' === sb_wizard_redirect(), 'wizard: a site that already has a key is not walked through setup' );
sb_seed_settings( array() );

// A site that has been through it, or walked out of it, is not sent back.
sendbeam_on_activate();
update_option( 'sendbeam_setup_done', 1 );
ok( '' === sb_wizard_redirect(), 'wizard: a site that has finished setup is not sent back' );
delete_option( 'sendbeam_setup_done' );

// And a host that never wants it can say so.
sendbeam_on_activate();
add_filter( 'sendbeam_setup_wizard', '__return_false' );
ok( '' === sb_wizard_redirect(), 'wizard: the sendbeam_setup_wizard filter switches the redirect off' );
add_filter( 'sendbeam_setup_wizard', '__return_true' );

// ── The four steps ──────────────────────────────────────────────────────
$GLOBALS['stub']['remote_reply'] = array(
	'response' => array( 'code' => 200 ),
	'body'     => wp_json_encode( array( 'forms' => array() ) ),
);
sb_seed_settings( array( 'api_key' => 'sb_live_wizardkeywizardkey' ) );
sendbeam_flush_cache();

$sendbeam_wizard_headings = array(
	1 => 'Connect your SendBeam account',
	2 => 'Verify the domain your email is sent from',
	3 => 'Send this site&#039;s email through SendBeam',
	4 => 'Put a form on the site',
);
foreach ( $sendbeam_wizard_headings as $sendbeam_n => $sendbeam_heading ) {
	$_GET = array( 'page' => 'sendbeam-setup', 'step' => (string) $sendbeam_n );
	ob_start();
	sendbeam_render_admin_page();
	$html = ob_get_clean();
	has( $html, $sendbeam_heading, "wizard: step $sendbeam_n renders its own heading" );
	has( $html, 'Step ' . $sendbeam_n . ' of 4', "wizard: step $sendbeam_n says which step it is" );
	has( $html, 'sb-rail', "wizard: step $sendbeam_n shows the progress rail" );
	has( $html, 'Go back to the Dashboard', "wizard: step $sendbeam_n offers the way out" );
	has( $html, 'name="step" value="' . $sendbeam_n . '"', "wizard: step $sendbeam_n posts its own number" );
	if ( $sendbeam_n < 4 ) {
		has( $html, 'Skip this step', "wizard: step $sendbeam_n can be skipped" );
		has( $html, '>Continue<', "wizard: step $sendbeam_n moves on" );
	} else {
		has( $html, '>Finish<', 'wizard: the last step finishes rather than continuing' );
		lacks( $html, 'Skip this step', 'wizard: there is nothing left to skip on the last step' );
	}
}

// The rail marks what is behind you and what you are standing on.
$_GET = array( 'page' => 'sendbeam-setup', 'step' => '3' );
ob_start();
sendbeam_render_admin_page();
$html = ob_get_clean();
has( $html, 'sb-rail__step is-done', 'wizard: a finished step is marked done on the rail' );
has( $html, 'sb-rail__step is-current" aria-current="step"', 'wizard: the step you are on is marked current' );

// The steps reuse the panels the settings screens use, rather than a second
// copy of the Connect button and a second copy of the records table.
sb_seed_settings( array() );
sendbeam_flush_cache();
$_GET = array( 'page' => 'sendbeam-setup', 'step' => '1' );
ob_start();
sendbeam_render_admin_page();
$html = ob_get_clean();
has( $html, 'id="sendbeam-connect"', 'wizard: step 1 is the Connect panel itself, not a copy of it' );
has( $html, 'Connect SendBeam', 'wizard: step 1 offers the one Connect button this plugin has' );

$_GET = array( 'page' => 'sendbeam-setup', 'step' => '2' );
ob_start();
sendbeam_render_admin_page();
$html = ob_get_clean();
has( $html, 'This step needs a connected site', 'wizard: a step that cannot run yet says why, rather than rendering an empty panel' );

// ── Skip, continue, finish, leave ───────────────────────────────────────
delete_option( 'sendbeam_setup_done' );
delete_user_meta( 7, SENDBEAM_WIZARD_STEP );

/** Post the wizard's footer form and say where it sent us. */
function sb_wizard_post( $step, $go ) {
	$_POST    = array( 'step' => (string) $step, 'go' => $go, '_wpnonce' => 'nonce:sendbeam_wizard_step' );
	$_REQUEST = $_POST;
	unset( $GLOBALS['stub']['redirect'] );
	try {
		sendbeam_handle_wizard_step();
	} catch ( SendBeamStubExit $e ) {
		unset( $e );
	}
	$_POST    = array();
	$_REQUEST = array();
	return isset( $GLOBALS['stub']['redirect'] ) ? $GLOBALS['stub']['redirect']['url'] : '';
}

has( sb_wizard_post( 1, 'next' ), 'page=sendbeam-setup&step=2', 'wizard: Skip advances to the next step' );
ok( 2 === (int) get_user_meta( 7, SENDBEAM_WIZARD_STEP, true ), 'wizard: and the step is remembered' );
ok( 2 === sendbeam_wizard_current_step(), 'wizard: coming back with no step in the URL resumes where you were' );
has( sb_wizard_post( 2, 'next' ), 'step=3', 'wizard: and again' );
has( sb_wizard_post( 4, 'finish' ), 'page=sendbeam', 'wizard: finishing lands on the Overview' );
ok( sendbeam_wizard_done(), 'wizard: finishing marks the site set up' );
ok( '' === get_user_meta( 7, SENDBEAM_WIZARD_STEP, true ), 'wizard: and stops remembering a half-finished run' );

delete_option( 'sendbeam_setup_done' );
$sendbeam_left = sb_wizard_post( 3, 'exit' );
ok( 'https://www.example-site.test/wp-admin/' === $sendbeam_left, 'wizard: leaving goes to the Dashboard' );
ok( ! sendbeam_wizard_done(), 'wizard: leaving is not finishing' );
ok( 3 === (int) get_user_meta( 7, SENDBEAM_WIZARD_STEP, true ), 'wizard: leaving keeps your place, so the Overview link picks it up' );

// A step number nobody registered cannot render a step that is not there.
$_GET = array( 'step' => '99' );
ok( 3 === sendbeam_wizard_current_step(), 'wizard: an out-of-range step falls back to where you were' );
$_GET = array( 'step' => '0' );
ok( 3 === sendbeam_wizard_current_step(), 'wizard: and so does a nonsense one' );
$_GET = array();

// Without the nonce, nothing moves.
$_POST    = array( 'step' => '1', 'go' => 'finish' );
$_REQUEST = $_POST;
delete_option( 'sendbeam_setup_done' );
$threw = false;
try {
	sendbeam_handle_wizard_step();
} catch ( SendBeamStubExit $e ) {
	$threw = true;
}
ok( $threw, 'wizard: a step posted without a nonce is refused' );
ok( ! sendbeam_wizard_done(), 'wizard: and nothing was marked finished' );
$_POST    = array();
$_REQUEST = array();

delete_user_meta( 7, SENDBEAM_WIZARD_STEP );
delete_option( 'sendbeam_setup_done' );
sb_seed_settings( array() );
$GLOBALS['stub']['remote_reply'] = null;
sendbeam_flush_cache();

/*
 * Disconnect writes an empty API key, and every update_option() on a
 * registered option runs the form's sanitiser — whose "a blank key field
 * keeps the saved key" rule is right for a password field somebody left
 * alone and exactly wrong for a button whose whole job is clearing it. The
 * key went back in, the site stayed connected, and the owner pressed the
 * button twice wondering why.
 */
sb_connect_reset();
sb_seed_settings( array( 'api_key' => 'sb_live_stillconnectedxx', 'sendbeam_connected_via' => 'connect', 'sendbeam_connect_workspace' => 'Harbour Lane' ) );
$GLOBALS['stub']['remote_reply'] = array( 'response' => array( 'code' => 200 ), 'body' => '{}' );
$_REQUEST                        = array( '_wpnonce' => 'nonce:sendbeam_disconnect' );
try {
	sendbeam_connect_disconnect();
} catch ( SendBeamStubExit $e ) {
	unset( $e );
}
$_REQUEST = array();
ok( '' === sendbeam_settings()['api_key'], 'disconnect: the key is actually gone, not put back by the form\'s keep-the-key rule' );
ok( '' === sendbeam_settings()['sendbeam_connected_via'], 'disconnect: and nothing still claims the site is connected' );
ok( '' === sendbeam_settings()['sendbeam_connect_workspace'], 'disconnect: nor names a workspace it no longer has a key for' );
ok( ! sendbeam_is_connected(), 'disconnect: so the screen shows a site that is not connected' );

/*
 * The same trap, the other way round: a programmatic write must not have the
 * form's tab rules applied to it either. With no `_tab` every key counts as
 * posted, and the rule that releases what Connect filled fired on every
 * write — quietly undoing what the write had just recorded.
 */
sb_seed_settings( array( 'api_key' => 'sb_live_filledfilledfilled' ) );
$sb_keep                            = sendbeam_settings();
$sb_keep['mail_enabled']            = 1;
$sb_keep['mail_from_email']         = 'hello@harbourlane.co.uk';
$sb_keep['sendbeam_connect_filled'] = array( 'mail_enabled', 'mail_from_email' );
sendbeam_write_settings( $sb_keep );
ok( in_array( 'mail_from_email', sendbeam_settings()['sendbeam_connect_filled'], true ), 'write: what Connect filled stays recorded through a programmatic write' );
ok( 1 === (int) sendbeam_settings()['mail_enabled'], 'write: and a value the caller set is not re-derived from whether a checkbox was posted' );
ok( 'sb_live_filledfilledfilled' === sendbeam_settings()['api_key'], 'write: and the key it did not mention is left alone' );

// The form's own rules still apply to the form.
sb_seed_settings( array( 'api_key' => 'sb_live_typedbyahumanxxx' ) );
$sb_form = sendbeam_sanitize_settings( array( '_tab' => 'connect', 'api_key' => '' ) );
ok( 'sb_live_typedbyahumanxxx' === $sb_form['api_key'], 'form: a password field left alone still keeps the saved key' );
/*
 * And the audit that keeps this from coming back: `sendbeam_settings` is the
 * only option with a sanitiser on it, so it is the only one where a
 * programmatic write can be quietly rewritten — and every writer of it now
 * goes through the helper.
 */
$sb_src = '';
foreach ( glob( dirname( __DIR__ ) . '/includes/*.php' ) as $sb_file ) {
	$sb_src .= file_get_contents( $sb_file );
}
// Calls, not mentions: the docblocks talk about both of these.
ok( 1 === preg_match_all( '/^\\t*register_setting\\(/m', $sb_src ), 'audit: one registered option, so one place this trap exists' );
ok( 1 === substr_count( $sb_src, '$ok = update_option( ' . "'sendbeam_settings'" ), 'audit: and exactly one update_option() on it, inside the helper' );
// Five occurrences: four calls and the declaration.
ok( 4 === substr_count( $sb_src, 'sendbeam_write_settings( $' ) - substr_count( $sb_src, 'function sendbeam_write_settings(' ), 'audit: every programmatic writer goes through that helper' );
$sb_form = sendbeam_sanitize_settings( array( '_tab' => 'connect', 'api_key' => '', 'api_key_remove' => '1' ) );
ok( '' === $sb_form['api_key'], 'form: and "remove the saved key" still removes it' );

/*
 * A key somebody pasted was minted for whatever they minted it for, and may
 * be in use on another site. Revoking it because this one is being
 * disconnected would take those down without warning.
 */
sb_connect_reset();
sb_seed_settings( array( 'api_key' => 'sb_live_pastedpastedpasted', 'sendbeam_connected_via' => '' ) );
$GLOBALS['stub']['remote']       = array();
$GLOBALS['stub']['remote_reply'] = array( 'response' => array( 'code' => 200 ), 'body' => '{}' );
$_REQUEST                        = array( '_wpnonce' => 'nonce:sendbeam_disconnect' );
try {
	sendbeam_connect_disconnect();
} catch ( SendBeamStubExit $e ) {
	unset( $e );
}
$_REQUEST = array();
ok( empty( $GLOBALS['stub']['remote'] ), 'disconnect: a pasted key is not revoked — it is not this site\'s to revoke' );
ok( '' === sendbeam_settings()['api_key'], 'disconnect: but this site forgets it' );
ok( '' === sendbeam_settings()['sendbeam_connected_via'], 'disconnect: and stops claiming how it was connected' );
has( $GLOBALS['stub']['redirect']['url'], 'sendbeam_disconnected=pasted', 'disconnect: and the notice says which of the two happened' );

// ── #5 The setup guide ticked steps that had not been done ──────────────
// The rail ticked every step whose number was lower than the current one, so
// opening step 3 on a site with no key showed "✓ Connect ✓ Sending domain"
// above a body reading "This step needs a connected site. Go back to step 1."
// Every step has Skip this step on it, so this is one press away.
sb_seed_settings( array( 'api_key' => '' ) );
sendbeam_connect_cache_status( sendbeam_connect_empty_status() );
ok( false === sendbeam_setup_steps()[0]['done'], 'wizard: a site with no key has not done step 1' );
ob_start();
sendbeam_wizard_rail( sendbeam_wizard_steps(), 3 );
$sb_rail = ob_get_clean();
ok( false === strpos( $sb_rail, '&#10003;' ), 'wizard: so the rail on step 3 ticks nothing' );
has( $sb_rail, 'is-current', 'wizard: it still says which step you are standing on' );
ok( 2 === substr_count( $sb_rail, 'sb-rail__num" aria-hidden="true">1<' ) + substr_count( $sb_rail, '>1</span>' ), 'wizard: and step 1 is still numbered 1' );

// A step that IS done is ticked wherever you are standing.
sb_seed_settings( array( 'api_key' => 'sb_live_railrailrailrailra' ) );
sendbeam_connect_cache_status(
	sendbeam_connect_normalise_status(
		array(
			'workspace' => array( 'id' => 'w1', 'name' => 'Harbour Lane' ),
			'domain'    => array( 'name' => 'harbourlane.co.uk', 'verified' => true, 'records' => array() ),
		)
	)
);
ob_start();
sendbeam_wizard_rail( sendbeam_wizard_steps(), 1 );
$sb_rail = ob_get_clean();
ok( false !== strpos( $sb_rail, '&#10003;' ), 'wizard: a step that is done is ticked from step 1' );
sb_seed_settings( array() );

// ── #6 "A SendBeam pop-up is live" with every rule switched off ─────────
// sendbeam_popups() returns every rule ever saved, switched off or not and
// with or without a form. The front end serves no loader for those, so the
// checklist was ticking a step nothing on the site could satisfy.
$GLOBALS['stub']['options']['sendbeam_popups'] = array(
	array( 'enabled' => 0, 'form' => '9da94d34-86f8-4fbc-9333-45c0b4c16d6a', 'where' => 'everywhere' ),
	array( 'enabled' => 0, 'form' => '14dad6b8-6998-4f1f-8924-3ebd79524c94', 'where' => 'posts' ),
);
ok( 2 === count( sendbeam_popups() ), 'popups: the editing screen still sees every rule it has to edit' );
ok( array() === sendbeam_live_popups(), 'popups: but none of them is live' );
ok( '' === sendbeam_form_placement( true ), 'popups: so nothing reports a pop-up on the site' );

// A rule that is on but has no form chosen is just as dead.
$GLOBALS['stub']['options']['sendbeam_popups'] = array(
	array( 'enabled' => 1, 'form' => '', 'where' => 'everywhere' ),
);
ok( array() === sendbeam_live_popups(), 'popups: a rule with no form shows nothing either' );
ok( '' === sendbeam_form_placement( true ), 'popups: and does not count as a placed form' );

// Switch one back on and it counts again.
$GLOBALS['stub']['options']['sendbeam_popups'] = array(
	array( 'enabled' => 1, 'form' => '9da94d34-86f8-4fbc-9333-45c0b4c16d6a', 'where' => 'everywhere' ),
);
ok( 1 === count( sendbeam_live_popups() ), 'popups: a rule that is on with a form is live' );
ok( 'popup' === sendbeam_form_placement( true ), 'popups: and the checklist says so' );
unset( $GLOBALS['stub']['options']['sendbeam_popups'] );
delete_transient( 'sendbeam_form_placed' );

// ── #9 "Connected by: A pasted key" on a site with no key ───────────────
// The support block is the first thing anyone reads on a support request, and
// on a disconnected site it said a key had been pasted, with "API key: Not
// set" directly above it and the sending-domain state blank.
sb_seed_settings( array( 'api_key' => '' ) );
sendbeam_connect_cache_status( sendbeam_connect_empty_status() );
$sb_report = sendbeam_status_report();
has( $sb_report, 'API key: Not set', 'support block: it says there is no key' );
lacks( $sb_report, 'Connected by: A pasted key', 'support block: so it does not claim one was pasted' );
has( $sb_report, 'Connected by: Nothing', 'support block: it says nothing connected this site' );
ok( false === strpos( $sb_report, "Sending domain state: \n" ) && false === strpos( $sb_report, 'Sending domain state:' . PHP_EOL ), 'support block: the sending domain state is never blank' );
has( $sb_report, 'Sending domain state: Not asked', 'support block: it says why there is no state' );

// With a key the two original answers are unchanged.
sb_seed_settings( array( 'api_key' => 'sb_live_pastedkeypastedkey' ) );
has( sendbeam_status_report(), 'Connected by: A pasted key', 'support block: a pasted key still reads as pasted' );
sb_seed_settings( array( 'api_key' => 'sb_live_pastedkeypastedkey', 'sendbeam_connected_via' => 'connect' ) );
has( sendbeam_status_report(), 'Connected by: The Connect button', 'support block: and a Connect key as Connect' );

// ── #10 Blaming a key's permissions on a site with no key ───────────────
// sendbeam_remote_forms() and sendbeam_lists() both answer null to "the key
// was refused" and "there is no key", and the empty states read only the
// first, sending the owner to SendBeam to add a permission to a key that does
// not exist — on a screen whose header band says "Not connected".
sb_seed_settings( array( 'api_key' => '' ) );
sendbeam_flush_cache();
ok( null === sendbeam_remote_forms(), 'forms: a site with no key cannot read forms' );
ob_start();
( new SendBeam_Forms_Table() )->no_items();
$sb_empty = ob_get_clean();
lacks( $sb_empty, 'the current key', 'forms: and does not blame a key it does not have' );
has( $sb_empty, 'not connected to SendBeam yet', 'forms: it says the site is not connected' );

ob_start();
( new SendBeam_Lists_Table() )->no_items();
$sb_empty = ob_get_clean();
lacks( $sb_empty, 'the current key', 'lists: same on the Audience screen' );
has( $sb_empty, 'not connected to SendBeam yet', 'lists: it says the site is not connected' );
sb_seed_settings( array() );

/* ─────────────────────────────────────────────────────────────────────────
 * QA regressions that need a site with no API key at all. They have to sit
 * above the define() below: once SENDBEAM_API_KEY exists in this process,
 * sendbeam_api_key() answers with it and no amount of seeding makes a site
 * keyless again.
 * ────────────────────────────────────────────────────────────────────── */

// ── #2 Site email switched on with no API key ───────────────────────────
// Disconnect leaves mail_enabled = 1, which is right — reconnecting should
// not make the owner find the switch again — so this state is reached the
// ordinary way. Nothing goes near SendBeam in it, and three screens said
// otherwise: the test email told the owner to tick a box already ticked,
// and Site Health raised a critical alarm about email that never leaves the
// server's own mailer.
sb_seed_settings( array( 'api_key' => 'sb_live_blockedblockedblocked', 'mail_enabled' => 1 ) );
ok( '' === sendbeam_mail_blocked(), 'mail: on with a key is not blocked' );
sb_seed_settings( array( 'api_key' => '', 'mail_enabled' => 1 ) );
ok( 'no_key' === sendbeam_mail_blocked(), 'mail: on with no key is blocked on the key, not the switch' );
ok( ! sendbeam_mail_enabled(), 'mail: and site email is not actually running' );
sb_seed_settings( array( 'api_key' => 'sb_live_blockedblockedblocked', 'mail_enabled' => 0 ) );
ok( 'off' === sendbeam_mail_blocked(), 'mail: unticked is blocked on the switch' );

// Site Health must not shout about a sender that is not sending anything.
sb_seed_settings( array( 'api_key' => '', 'mail_enabled' => 1 ) );
sendbeam_connect_cache_status( sendbeam_connect_empty_status() );
$sb_relay = sendbeam_test_mail_relay();
ok( 'critical' !== $sb_relay['status'], 'site health: site email on with no key is not a critical alarm' );
ok( 'recommended' === $sb_relay['status'], 'site health: it is a recommendation to connect the site' );
lacks( $sb_relay['label'], 'unverified domain', 'site health: and it does not blame an unverified domain' );
has( $sb_relay['description'], 'no SendBeam API key', 'site health: it names the missing key' );

// The ticked box says why it cannot act, where it is ticked.
ob_start();
sendbeam_field_checkbox( array( 'key' => 'mail_enabled', 'label' => 'Send this site\'s email through SendBeam' ) );
$sb_box = ob_get_clean();
has( $sb_box, 'checked', 'mail: the box is still ticked, because that is what was saved' );
has( $sb_box, 'no SendBeam API key', 'mail: and it says the site has no key' );
sb_seed_settings( array( 'api_key' => 'sb_live_blockedblockedblocked', 'mail_enabled' => 1 ) );
ob_start();
sendbeam_field_checkbox( array( 'key' => 'mail_enabled', 'label' => 'Send this site\'s email through SendBeam' ) );
lacks( ob_get_clean(), 'no SendBeam API key', 'mail: and says nothing of the sort once there is one' );
sb_seed_settings( array() );

// wp-config constant wins over the option.
define( 'SENDBEAM_API_KEY', 'sb_const_0123456789abcdef' );
ok( sendbeam_api_key() === 'sb_const_0123456789abcdef', 'constant wins' );
// With the key pinned there is no Connect panel and no paste field either,
// so step 1 keeps the anchor of the one key field a site can still have: the
// card a pasted key gets.
has( sendbeam_setup_steps()[0]['target'], '#sendbeam_api_key', 'steps: a pinned key leaves step 1 pointing at the key field' );

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
sb_seed_settings( array( 'api_key' => 'sb_live_existingexistingexisting' ) );
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
sb_seed_settings( array( 'api_key' => 'sb_live_existingexistingexisting', 'default_form' => $form ) );
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
	sb_seed_settings( array( 'api_key' => 'sb_live_existingexistingexisting' ) );
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
sb_seed_settings( array( 'api_key' => 'sb_live_existingexistingexisting' ) );
sb_connect_pending( $sendbeam_good_state );
$GLOBALS['stub']['remote_reply'] = new WP_Error( 'http_request_failed', 'cURL error 28' );
$_GET                            = array( 'state' => $sendbeam_good_state, 'grant' => $sendbeam_good_grant );
$sendbeam_html                   = sb_connect_run( 'sendbeam_connect_return' );
has( $sendbeam_html, 'could not reach sendbeam.io', 'connect: an unreachable server is reported plainly' );
ok( 'sb_live_existingexistingexisting' === sendbeam_settings()['api_key'], 'connect: an unreachable server leaves the saved key untouched' );

// Something that is not a key must never be written to the option.
sb_connect_reset();
sb_seed_settings( array( 'api_key' => 'sb_live_existingexistingexisting' ) );
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
	sb_seed_settings( $settings );
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

// ── Reconnecting into a different workspace ────────────────────────────
// Everything Connect filled in names something in the workspace this site
// has just left: a form the new workspace does not own 404s on every page it
// is embedded on, and a From address on a domain it cannot send from bounces.
sb_v2_exchange( sb_v2_body() );
$v2_first = sendbeam_settings();
ok( $form === $v2_first['default_form'], 'workspace move: the first connection fills the form in' );
ok( 'ws_1' === $v2_first['sendbeam_connect_workspace_id'], 'workspace move: the workspace ID is remembered, not only its name' );

$sb_second = sb_v2_body(
	array(
		'api_key'      => 'sb_live_' . str_repeat( 'm', 32 ),
		'workspace'    => array( 'id' => 'ws_2', 'name' => 'Second Roastery' ),
		'default_form' => array( 'id' => $other, 'name' => 'Second signup' ),
		'sender'       => array( 'from_name' => 'Second Roastery', 'from_email' => 'hello@second.example' ),
	)
);
sb_v2_exchange( $sb_second, $v2_first );
$v2_moved = sendbeam_settings();
ok( $other === $v2_moved['default_form'], 'workspace move: the old workspace\'s form is released, and the new workspace\'s takes its place' );
ok( 'hello@second.example' === $v2_moved['mail_from_email'], 'workspace move: the sender follows the site to the new workspace' );
ok( 'ws_2' === $v2_moved['sendbeam_connect_workspace_id'], 'workspace move: the new workspace ID is what is remembered afterwards' );

// The same workspace is not a move: nothing is released, and a reconnect
// does not repoint an embed that is live on a page.
sb_v2_exchange( sb_v2_body() );
$v2_first = sendbeam_settings();
sb_v2_exchange( sb_v2_body( array( 'api_key' => 'sb_live_' . str_repeat( 'n', 32 ) ) ), $v2_first );
ok( $form === sendbeam_settings()['default_form'], 'workspace move: reconnecting into the same workspace keeps what Connect filled' );

// A setting the owner typed is not Connect's to release, wherever the site
// reconnects to. Saving the tab by hand is what takes it off the list.
sb_v2_exchange( sb_v2_body() );
$v2_owned                             = sendbeam_settings();
$v2_owned['mail_from_email']          = 'orders@harbourlane.co.uk';
$v2_owned['sendbeam_connect_filled']  = array_values( array_diff( $v2_owned['sendbeam_connect_filled'], array( 'mail_from_email' ) ) );
sb_v2_exchange( $sb_second, $v2_owned );
ok( 'orders@harbourlane.co.uk' === sendbeam_settings()['mail_from_email'], 'workspace move: an address the owner typed survives the move' );

// What counts as a move.
ok( ! sendbeam_connect_moved_workspace( array(), array( 'workspace' => array( 'id' => 'ws_1' ) ), 'Harbour Lane' ), 'workspace move: a first connection has nothing to move away from' );
ok( sendbeam_connect_moved_workspace( array( 'sendbeam_connect_workspace' => 'Harbour Lane' ), array( 'workspace' => array( 'id' => '' ) ), 'Second Roastery' ), 'workspace move: a connection made before IDs were recorded falls back to the name' );
ok( ! sendbeam_connect_moved_workspace( array( 'sendbeam_connect_workspace_id' => 'ws_1', 'sendbeam_connect_workspace' => 'Old Name' ), array( 'workspace' => array( 'id' => 'ws_1' ) ), 'New Name' ), 'workspace move: renaming a workspace is not moving to another one' );

// ── /api/v1/connect/status ─────────────────────────────────────────────
sb_connect_reset();
sendbeam_connect_forget_status();
sb_seed_settings( array( 'api_key' => 'sb_live_statusstatusstatus' ) );
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
sb_seed_settings( array( 'api_key' => 'sb_live_revokedrevokedrevoked' ) );
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
	sb_seed_settings( null === $settings ? array( 'api_key' => 'sb_live_connectedconnectedxx', 'sendbeam_connected_via' => 'connect', 'sendbeam_connect_granted' => 'forms,transactional:send,domain' ) : $settings );
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
sb_seed_settings( array() );
sendbeam_connect_forget_status();
delete_transient( 'sendbeam_connection' );

// ── Reconnecting ───────────────────────────────────────────────────────
// Un-ticking a permission at consent used to be fixable only by disconnect
// and connect again, which is a frightening thing to tell someone whose site
// is working. A link starts the same flow with the boxes already ticked.
sb_connect_reset();
sb_seed_settings( array( 'api_key' => 'sb_live_connectedconnectedxx', 'sendbeam_connect_granted' => 'forms,contacts:write' ) );
$sb_all = sendbeam_connect_start_url( true );
has( $sb_all, 'action=sendbeam_connect_start', 'reconnect: the link starts the ordinary Connect flow' );
has( $sb_all, 'nonce:sendbeam_connect_start', 'reconnect: the link carries the same nonce the panel does' );
has( rawurldecode( $sb_all ), 'forms,contacts:write,transactional:send,ecommerce,domain', 'reconnect: with more permissions preselects every scope' );
$sb_same = sendbeam_connect_start_url();
has( rawurldecode( $sb_same ), 'sendbeam_scopes=forms,contacts:write', 'reconnect: a plain Reconnect asks for what the key already has' );
sb_seed_settings( array( 'api_key' => 'sb_live_pastedpastedpasted' ) );
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
sb_seed_settings( array() );

// ── A slow SendBeam must not be a slow wp-admin ────────────────────────
sb_connect_reset();
sendbeam_connect_forget_status();
sb_seed_settings( array( 'api_key' => 'sb_live_statusstatusstatus' ) );
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
sb_seed_settings( array( 'api_key' => 'sb_live_aaaaaaaaaaaaaaaaaaaa' ) );
set_transient( $sb_key_a, sendbeam_connect_empty_status(), 60 );
sb_seed_settings( sendbeam_sanitize_settings( array( '_tab' => 'connect', 'api_key' => 'sb_live_bbbbbbbbbbbbbbbbbbbb' ) ) );
ok( false === get_transient( $sb_key_a ), 'status: changing the key deletes what the old one had cached' );

sb_connect_reset();
sendbeam_connect_forget_status();
sb_seed_settings( array() );

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
	sb_seed_settings( array(
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
sb_seed_settings( $off );
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
sb_seed_settings( $noscope );
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
	sb_seed_settings( array(
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
sb_seed_settings( $const_site );
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
sb_seed_settings( sendbeam_sanitize_settings( array( '_tab' => 'mail', 'mail_enabled' => '1', 'mail_from_name' => 'Harbour Lane', 'mail_from_email' => 'hello@harbourlane.co.uk' ) ) );
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
sb_seed_settings( sendbeam_sanitize_settings( array( '_tab' => 'connect', 'api_key' => 'sb_live_pastedoverthetopxxxx' ) ) );
ok( array() === sendbeam_settings()['sendbeam_connect_filled'], 'filled: a key changed by hand ends Connect\'s claim on anything' );

sb_connect_reset();
sb_seed_settings( array() );

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
	sb_seed_settings( array(
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
sb_seed_settings( array() );
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
	sb_seed_settings( array( 'api_key' => 'sb_live_connectedconnectedxx', 'default_form' => $GLOBALS['form'] ) );
	update_option( 'sendbeam_popups', array() );
	$GLOBALS['stub']['db'] = $db;
	$sb_was_wpdb           = $GLOBALS['wpdb'];
	$GLOBALS['wpdb']       = new SendBeam_Stub_Db();
	$where                 = sendbeam_form_placement( true );
	$sql                   = implode( ' ', $GLOBALS['wpdb']->queries );
	// Back to the one that holds the log: it is needed for the rest of the run.
	$GLOBALS['wpdb'] = $sb_was_wpdb;
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
sb_seed_settings( array( 'api_key' => 'sb_live_connectedconnectedxx', 'default_form' => $form ) );
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
sb_seed_settings( array() );
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
 * @param array $forms    The forms this key can see in the workspace.
 * @return string
 */
function sb_overview( $settings, $status, $get = array(), $forms = array() ) {
	$GLOBALS['stub']['caps']['manage_options'] = true;
	$GLOBALS['stub']['user_id']                = 7;
	$GLOBALS['stub']['current_user']           = array( 'email' => 'admin@example-site.test' );
	$GLOBALS['stub']['remote']                 = array();
	$_GET                                      = $get;
	sb_seed_settings( $settings );
	set_transient( 'sendbeam_connection', array( 'state' => '' === (string) ( $settings['api_key'] ?? '' ) ? 'none' : 'ok', 'message' => '', 'count' => 1 ) );
	set_transient( 'sendbeam_remote_forms', $forms );
	set_transient( 'sendbeam_remote_lists', array() );
	set_transient( 'sendbeam_subscriber_count', 0 );
	sendbeam_connect_cache_status( sendbeam_connect_normalise_status( $status ) );

	// What a browser gets on this screen: the notices WordPress prints above
	// the page, and then the page. The plugin's messages moved out of the
	// middle of each screen's markup and into core's notice component, so a
	// test that looked only at the screen would stop seeing them.
	$GLOBALS['stub']['screen'] = 'toplevel_page_sendbeam';
	ob_start();
	sendbeam_admin_notices();
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
// Site email is waiting on step 2, and step 2 is waiting on a permission
// nobody ticked. The way out is offered on the step the owner came to use,
// not only on the one it is queued behind.
ok( 2 === substr_count( $ov, 'Reconnect with more permissions' ), 'overview: the site email step offers the reconnect too when the domain permission is the missing piece' );
$ov = sb_overview( $sb_connected, $sb_verified );
lacks( $ov, 'Reconnect with more permissions', 'overview: a step with nothing missing does not offer it' );
// Changing the domain a site sends from means going back to the consent
// page, and a verified domain is exactly when somebody notices it is the
// wrong one — so the line is there whether anything is missing or not.
has( $ov, 'Sending from the wrong domain? Reconnect and enter the one you want.', 'overview: a working domain step still says how to change the domain' );
$ov = sb_overview( $sb_connected, array( 'workspace' => array( 'id' => 'ws_1' ), 'domain_state' => 'no_site' ) );
has( $ov, 'Sending from the wrong domain? Reconnect and enter the one you want.', 'overview: and so does a step with no domain at all' );
$ov = sb_overview( array(), array( 'workspace' => array( 'id' => 'ws_1' ), 'domain_state' => 'no_site' ) );
lacks( $ov, 'Sending from the wrong domain?', 'overview: a site that has not connected is not offered a reconnection' );
$ov = sb_overview( $sb_connected, $sb_verified );
has( $ov, '>Reconnect<', 'overview: the connected card offers a plain Reconnect beside Disconnect' );

// The form step's override exists for a form nothing on this site can read:
// one stored by a page builder, or on a page behind a login. That does not
// depend on a *default* form being chosen — the form on the page may name
// itself — so any form in the workspace is reason enough to offer it.
$ov = sb_overview( $sb_connected, $sb_unverified );
lacks( $ov, 'I&#039;ve placed it elsewhere', 'overview: no override while the workspace has no form to have placed' );
$sb_one_form = array( array( 'id' => $form, 'name' => 'Newsletter signup', 'kind' => 'signup' ) );
$ov          = sb_overview( $sb_connected, $sb_unverified, array(), $sb_one_form );
has( $ov, 'I&#039;ve placed it elsewhere', 'overview: a workspace with a form offers the override even when no default is chosen' );
lacks( $ov, '[sendbeam_form]', 'overview: and does not hand out a shortcode that names no form' );

// ── Back from the registrar ────────────────────────────────────────────
// Automatic DNS used to end on SendBeam's own sending settings: a reasonable
// place to finish if you started there, and the wrong one entirely when you
// started in a wp-admin checklist and expected to come back and see the step
// ticked.
$ov = sb_overview( $sb_connected, $sb_unverified );
has( $ov, 'return_to=' . rawurlencode( 'https://www.example-site.test/wp-admin/admin.php?page=sendbeam' ), 'automatic DNS: the one-click link tells SendBeam where to send the owner back to' );
has( $ov, 'https://sendbeam.io/dc/1?return_to=', 'automatic DNS: and is otherwise the link SendBeam sent' );

$GLOBALS['stub']['remote_reply'] = array( 'response' => array( 'code' => 200 ), 'body' => sb_status_body( true ) );
sendbeam_connect_forget_status();
$ov = sb_overview( $sb_connected, $sb_unverified, array( 'sb_dc' => 'done' ) );
has( $ov, 'Your registrar added the records. Checked just now.', 'automatic DNS: coming back says what happened, in the tense it happened in' );
has( $ov, 'harbourlane.co.uk is verified', 'automatic DNS: and the step underneath is already showing what the check found' );
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
lacks( $ov, 'Checked just now', 'automatic DNS: and anything else is ignored' );
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
sb_seed_settings( array() );

/* ─────────────────────────── The admin menu ────────────────────────────
 * SendBeam lived under Settings → SendBeam with seven invisible tabs. Every
 * section is now a real menu item, and every old URL still goes somewhere.
 */
$GLOBALS['stub']['menu']    = array();
$GLOBALS['stub']['submenu'] = array();
sendbeam_admin_menu();
$menu = $GLOBALS['stub']['menu'];
$subs = $GLOBALS['stub']['submenu'];

ok( isset( $menu['sendbeam'] ) && null === $menu['sendbeam']['parent'], 'menu: SendBeam is a top-level menu, not a Settings submenu' );
ok( 'SendBeam' === $menu['sendbeam']['menu_title'], 'menu: the menu is called SendBeam' );
ok( 58.9 === $menu['sendbeam']['position'], 'menu: it sits below Settings at 58.9' );
has( $menu['sendbeam']['icon'], 'data:image/svg+xml;base64,', 'menu: the icon is a base64 SVG data URI, not a dashicon' );
has( base64_decode( substr( $menu['sendbeam']['icon'], strlen( 'data:image/svg+xml;base64,' ) ) ), '#a7aaad', 'menu: the mark is drawn in the grey wp-admin gives every other menu icon' );
ok( substr_count( base64_decode( substr( $menu['sendbeam']['icon'], strlen( 'data:image/svg+xml;base64,' ) ) ), '<rect' ) === 3, 'menu: the icon is the three-square mark' );

$sendbeam_expected = array(
	'sendbeam'           => 'Overview',
	'sendbeam-forms'     => 'Forms',
	'sendbeam-popups'    => 'Pop-ups',
	'sendbeam-audience'  => 'Audience',
	'sendbeam-mail'      => 'Site email',
	'sendbeam-ecommerce' => 'E-commerce',
	'sendbeam-settings'  => 'Settings',
	'sendbeam-help'      => 'Help',
);
foreach ( $sendbeam_expected as $sendbeam_slug => $sendbeam_label ) {
	ok( isset( $subs[ $sendbeam_slug ] ), "menu: $sendbeam_slug is registered as a submenu item" );
	ok( $sendbeam_label === $subs[ $sendbeam_slug ]['menu_title'], "menu: $sendbeam_slug is labelled $sendbeam_label" );
	ok( 'manage_options' === $subs[ $sendbeam_slug ]['cap'], "menu: $sendbeam_slug needs manage_options" );
	ok( 'sendbeam_render_admin_page' === $subs[ $sendbeam_slug ]['callback'], "menu: $sendbeam_slug is rendered by the one router" );
	ok( 'sendbeam' === $subs[ $sendbeam_slug ]['parent'], "menu: $sendbeam_slug hangs off the SendBeam menu" );
}
ok( ! isset( $subs['sendbeam-docs'] ), 'menu: the Docs tab is gone; Help replaced it' );

/*
 * The wizard is routable but never in the menu. Its parent is options.php
 * rather than an empty string: an empty parent routes fine and then leaves
 * WordPress unable to work out the page's title, which is a PHP 8
 * deprecation in the log and a browser tab reading "— WordPress".
 */
/*
 * Every hook this plugin registers has to name a function that exists.
 * WordPress answers a hook pointing at a missing function with a fatal error
 * on the screen it fires on — and a screen is exactly the thing no unit test
 * was looking at when the menu grew its `load-` hooks.
 */
foreach ( $GLOBALS['stub']['callbacks'] as $sendbeam_cb ) {
	list( $sendbeam_tag, $sendbeam_fn ) = $sendbeam_cb;
	ok( is_callable( $sendbeam_fn ), 'hooks: ' . ( is_string( $sendbeam_fn ) ? $sendbeam_fn : 'a closure' ) . '() exists for ' . $sendbeam_tag );
}

ok( isset( $subs['sendbeam-setup'] ), 'menu: the wizard is registered' );
ok( 'options.php' === $subs['sendbeam-setup']['parent'], 'menu: under a parent that is in no menu, so it is routable but hidden' );
ok( ! isset( $menu['sendbeam-setup'] ), 'menu: and it is not a top-level menu either' );
ok( '' !== $subs['sendbeam-setup']['page_title'], 'menu: it has a page title, so the browser tab says what it is' );
ok( count( $subs ) === count( sendbeam_pages() ), 'menu: every page in the table is registered, and nothing else is' );

// Every page in the table renders, and renders something.
foreach ( sendbeam_pages() as $sendbeam_slug => $sendbeam_page ) {
	ok( function_exists( $sendbeam_page['render'] ), "router: {$sendbeam_page['render']}() exists for $sendbeam_slug" );
}

// The router picks the screen off `page`, and falls back to the Overview
// rather than fataling on a slug that is not ours.
$_GET = array( 'page' => 'sendbeam-mail' );
ok( 'sendbeam-mail' === sendbeam_current_page(), 'router: the current page comes from the query string' );
$_GET = array( 'page' => 'sendbeam-nonsense' );
ok( 'sendbeam' === sendbeam_current_page(), 'router: an unknown page falls back to the Overview' );
$_GET = array();

ok( 'https://www.example-site.test/wp-admin/admin.php?page=sendbeam-forms' === sendbeam_page_url( 'sendbeam-forms' ), 'router: page URLs are admin.php?page=<slug>' );

// Screen detection, which decides where the styles and the notice appear.
ok( sendbeam_is_our_screen( 'toplevel_page_sendbeam' ), 'screens: the Overview is one of ours' );
ok( sendbeam_is_our_screen( 'sendbeam_page_sendbeam-help' ), 'screens: a submenu page is one of ours' );
ok( ! sendbeam_is_our_screen( 'dashboard' ), 'screens: the Dashboard is not' );
ok( ! sendbeam_is_our_screen( 'plugins' ), 'screens: the Plugins screen is not' );
ok( ! sendbeam_is_our_screen( '' ), 'screens: nothing is not' );

// Old URLs. Every tab that existed goes somewhere, permanently.
$sendbeam_moves = array(
	''          => 'sendbeam',
	'overview'  => 'sendbeam',
	'forms'     => 'sendbeam-forms',
	'audience'  => 'sendbeam-audience',
	'popup'     => 'sendbeam-popups',
	'mail'      => 'sendbeam-mail',
	'ecommerce' => 'sendbeam-ecommerce',
	'docs'      => 'sendbeam-help',
);
foreach ( $sendbeam_moves as $sendbeam_tab => $sendbeam_to ) {
	$GLOBALS['pagenow'] = 'options-general.php';
	$_GET               = array( 'page' => 'sendbeam' );
	if ( '' !== $sendbeam_tab ) {
		$_GET['tab'] = $sendbeam_tab;
	}
	unset( $GLOBALS['stub']['redirect'] );
	try {
		sendbeam_redirect_old_urls();
	} catch ( SendBeamStubExit $e ) {
		unset( $e );
	}
	ok( isset( $GLOBALS['stub']['redirect'] ), "old URL: tab=$sendbeam_tab redirects" );
	ok( 301 === $GLOBALS['stub']['redirect']['status'], "old URL: tab=$sendbeam_tab moves permanently" );
	ok( sendbeam_page_url( $sendbeam_to ) === $GLOBALS['stub']['redirect']['url'], "old URL: tab=$sendbeam_tab lands on $sendbeam_to" );
}

// A notice the old URL carried has to survive the move, or every handler that
// redirects with one goes silent.
$GLOBALS['pagenow'] = 'options-general.php';
$_GET               = array(
	'page'          => 'sendbeam',
	'tab'           => 'mail',
	'sendbeam_test' => 'ok',
	'sendbeam_note' => 'Sent to a@b.test.',
);
unset( $GLOBALS['stub']['redirect'] );
try {
	sendbeam_redirect_old_urls();
} catch ( SendBeamStubExit $e ) {
	unset( $e );
}
has( $GLOBALS['stub']['redirect']['url'], 'sendbeam_test=ok', 'old URL: the notice on the old address is carried to the new one' );
has( $GLOBALS['stub']['redirect']['url'], 'sendbeam_note=Sent%20to%20a%40b.test.', 'old URL: a carried value is encoded, so a space cannot split the URL' );

// Someone else's options-general.php page is left alone.
$GLOBALS['pagenow'] = 'options-general.php';
$_GET               = array( 'page' => 'not-sendbeam' );
unset( $GLOBALS['stub']['redirect'] );
sendbeam_redirect_old_urls();
ok( ! isset( $GLOBALS['stub']['redirect'] ), 'old URL: another plugin\'s settings page is not hijacked' );
$GLOBALS['pagenow'] = 'admin.php';
$_GET               = array();

/*
 * Every page renders. No test could see an .astro page in the app and no test
 * could see one of these either: a screen function that calls something that
 * does not exist is a white page on a live wp-admin, and the only way to find
 * out was to load it.
 */
$GLOBALS['stub']['caps']['manage_options'] = true;
foreach ( array_keys( sendbeam_pages() ) as $sendbeam_slug ) {
	$_GET = array( 'page' => $sendbeam_slug );
	ob_start();
	sendbeam_render_admin_page();
	$sendbeam_html = ob_get_clean();
	has( $sendbeam_html, 'class="wrap sendbeam-app', "render: $sendbeam_slug sits in a .wrap" );
	has( $sendbeam_html, 'class="screen-reader-text"', "render: $sendbeam_slug has a real h1 for screen readers" );
	has( $sendbeam_html, 'sb-head', "render: $sendbeam_slug carries the header band" );
	ok( strlen( $sendbeam_html ) > 800, "render: $sendbeam_slug renders a screenful, not an empty shell" );
}

// The header band names the section, so eight pages do not all say "SendBeam".
$_GET = array( 'page' => 'sendbeam-mail' );
ob_start();
sendbeam_render_admin_page();
$sendbeam_html = ob_get_clean();
has( $sendbeam_html, '<span class="sb-head__title">Site email</span>', 'render: the header band names the section' );
has( $sendbeam_html, 'SendBeam — Site email', 'render: the screen-reader heading names the section too' );

// Someone without the capability gets nothing, not a partial page.
$GLOBALS['stub']['caps']['manage_options'] = false;
ob_start();
sendbeam_render_admin_page();
ok( '' === ob_get_clean(), 'render: a user who cannot manage options sees nothing at all' );
$GLOBALS['stub']['caps']['manage_options'] = true;
$_GET = array();

/*
 * One function knows what a link into SendBeam looks like, and its default is
 * where a person with an account wants to land.
 */
ok( 'https://sendbeam.io/dashboard' === sendbeam_app_link(), 'app links: the default is the app\'s signed-in home' );
ok( 'https://sendbeam.io/forms' === sendbeam_app_link( '/forms' ), 'app links: a deep link keeps its path' );
ok( 'https://sendbeam.io' === sendbeam_app_url(), 'app links: and the origin the API is called against is unchanged' );

/*
 * No anchor anywhere in the plugin's markup points at the bare origin. The
 * base URL is still used for the API, for the postMessage origin check and
 * for the "SendBeam address" line in the debug report — none of which is
 * something a person clicks.
 */
$GLOBALS['stub']['caps']['manage_options'] = true;
foreach ( array_keys( sendbeam_pages() ) as $sendbeam_slug ) {
	$_GET = array( 'page' => $sendbeam_slug );
	ob_start();
	sendbeam_render_admin_page();
	$sendbeam_html = ob_get_clean();
	lacks( $sendbeam_html, 'href="https://sendbeam.io"', "app links: $sendbeam_slug has no anchor on the bare origin" );
	lacks( $sendbeam_html, "href='https://sendbeam.io'", "app links: $sendbeam_slug has none with single quotes either" );
	// The header band carries the link the owner pressed — on every screen
	// but the wizard, whose band shows how far through you are instead.
	if ( 'sendbeam-setup' !== $sendbeam_slug ) {
		has( $sendbeam_html, 'href="https://sendbeam.io/dashboard" target="_blank"', "app links: $sendbeam_slug's header opens the app" );
	}
}
$_GET = array();

// The Plugins-screen link goes to the Settings page, not to a dashboard.
$sendbeam_links = sendbeam_action_links( array() );
has( $sendbeam_links[0], 'admin.php?page=sendbeam-settings', 'plugins screen: the Settings link points at the Settings page' );
lacks( $sendbeam_links[0], 'options-general.php', 'plugins screen: the Settings link does not point at the old address' );

/* ────────────────────────── The list tables ────────────────────────────
 * Three hand-rolled tables with no search, no pagination, no empty state and
 * no phone behaviour became three WP_List_Table subclasses. What matters
 * here is what a browser gets: the right columns, a primary column core can
 * collapse a row down to, rows that render, and an empty state that says what
 * to do rather than nothing at all.
 */
$GLOBALS['stub']['caps']['manage_options'] = true;
$_REQUEST                                  = array();

/** Render one list table the way sendbeam_list_card() does. */
function sb_render_table( $table ) {
	$table->prepare_items();
	ob_start();
	$table->display();
	return ob_get_clean();
}

// ── Forms ───────────────────────────────────────────────────────────────
$GLOBALS['stub']['remote_reply'] = array(
	'response' => array( 'code' => 200 ),
	'body'     => wp_json_encode(
		array(
			'forms' => array(
				array(
					'id'   => $form,
					'name' => 'Newsletter signup',
					'kind' => 'signup',
				),
				array(
					'id'   => $other,
					'name' => 'Ask us anything',
					'kind' => 'contact',
				),
			),
		)
	),
);
sendbeam_flush_cache();
sb_seed_settings( array_merge( sendbeam_settings(), array( 'api_key' => 'sb_live_listtablekey1', 'default_form' => $form, 'contact_form' => '' ) ) );

$forms_table = new SendBeam_Forms_Table();
$html        = sb_render_table( $forms_table );
has( $html, 'wp-list-table', 'forms table: it is a core list table' );
has( $html, 'column-name column-primary', 'forms table: Form is the primary column, so a phone collapses to it' );
foreach ( array( 'Form', 'Kind', 'Shortcode', 'Placed on' ) as $sendbeam_col ) {
	has( $html, '>' . $sendbeam_col . '</th>', "forms table: the $sendbeam_col column is there" );
}
has( $html, 'Newsletter signup', 'forms table: a form renders as a row' );
has( $html, 'Ask us anything', 'forms table: every form renders' );
has( $html, '[sendbeam_form id=&quot;' . $form . '&quot;]', 'forms table: a signup form shows the shortcode that places it' );
has( $html, '[sendbeam_contact id=&quot;', 'forms table: a contact form shows the shortcode that places THAT form' );
has( $html, 'sb-copy', 'forms table: the shortcode is one click from the clipboard' );
has( $html, 'row-actions', 'forms table: rows carry actions, as core rows do' );
has( $html, 'Preview', 'forms table: Preview is a row action' );
has( $html, 'Default signup form', 'forms table: the default form is marked as the default' );
lacks( $html, 'name="action"', 'forms table: nothing here can be changed in bulk, so no bulk control is offered' );

// Search narrows it, and says so when it finds nothing.
$_REQUEST['s'] = 'newsletter';
has( sb_render_table( new SendBeam_Forms_Table() ), 'Newsletter signup', 'forms table: search keeps what matches' );
lacks( sb_render_table( new SendBeam_Forms_Table() ), 'Ask us anything', 'forms table: search drops what does not' );
$_REQUEST['s'] = 'nothing like this';
has( sb_render_table( new SendBeam_Forms_Table() ), 'No form here matches that', 'forms table: a search with no hits says so' );
$_REQUEST = array();

// Empty, and unreadable, are different answers.
$GLOBALS['stub']['remote_reply'] = array(
	'response' => array( 'code' => 200 ),
	'body'     => wp_json_encode( array( 'forms' => array() ) ),
);
sendbeam_flush_cache();
has( sb_render_table( new SendBeam_Forms_Table() ), 'Create your first form', 'forms table: an empty workspace is offered a way to fill it' );
$GLOBALS['stub']['remote_reply'] = array(
	'response' => array( 'code' => 403 ),
	'body'     => '{"error":"forbidden"}',
);
sendbeam_flush_cache();
has( sb_render_table( new SendBeam_Forms_Table() ), 'Forms (read) permission', 'forms table: a key that cannot read forms is told which permission is missing' );

// ── The email log ───────────────────────────────────────────────────────
sb_log_reset();
sendbeam_mail_log_insert( 'cat@customer.test', 'New comment', 'fallback', 'has attachments', 'akismet', 1758500000 );
sendbeam_mail_log_insert( 'bob@customer.test', 'Password reset', 'failed', 'Monthly email limit reached', 'wp-core', 1758600000 );
sendbeam_mail_log_insert( 'ada@customer.test', 'Order #1001', 'sent', '', 'woocommerce', 1758700000 );
$log_table = new SendBeam_Mail_Log_Table();
$html      = sb_render_table( $log_table );
has( $html, 'column-date column-primary', 'email log: Date is the primary column' );
foreach ( array( 'Date', 'To', 'Subject', 'Result', 'Sent by', 'Note' ) as $sendbeam_col ) {
	has( $html, '>' . $sendbeam_col . '</th>', "email log: the $sendbeam_col column is there" );
}
has( $html, 'ada@customer.test', 'email log: a sent message is listed' );
has( $html, 'Sent via SendBeam', 'email log: a sent message says so' );
has( $html, 'Monthly email limit reached', 'email log: a failure keeps the reason it failed' );
has( $html, 'Server mailer', 'email log: a fall-back to the server mailer is named as one' );
has( $html, 'name="message[]"', 'email log: rows can be ticked' );
has( $html, '<option value="delete">Delete</option>', 'email log: the one bulk action is Delete' );
has( $html, 'woocommerce', 'email log: and which plugin asked for the message' );
/*
 * The sentinel for core is `wp-core`, not the product's name: the coding
 * standard's own auto-fixer rewrites that word wherever it finds it, which
 * would turn a stored value the screen matches on into one it does not.
 */
has( $html, '>WordPress<', 'email log: with core itself named in words' );
ok( 'wp-core' === sendbeam_mail_source() || '' === sendbeam_mail_source(), 'email log: and stored as a sentinel a linter will not rewrite' );

$_REQUEST['s'] = 'password';
has( sb_render_table( new SendBeam_Mail_Log_Table() ), 'bob@customer.test', 'email log: search keeps what matches' );
lacks( sb_render_table( new SendBeam_Mail_Log_Table() ), 'ada@customer.test', 'email log: search drops what does not' );
$_REQUEST = array();

sb_log_reset();
has( sb_render_table( new SendBeam_Mail_Log_Table() ), 'Nothing has been sent through SendBeam', 'email log: an empty log says what to do about it' );

// Bulk delete: the rows that were ticked go, and nothing else does.
sb_log_reset();
sendbeam_mail_log_insert( 'a@t.test', 'A', 'sent', '', '', 1758000001 );
sendbeam_mail_log_insert( 'b@t.test', 'B', 'sent', '', '', 1758000002 );
sendbeam_mail_log_insert( 'c@t.test', 'C', 'sent', '', '', 1758000003 );
$_REQUEST = array(
	'action'   => 'delete',
	'message'  => array( 2 ),
	'_wpnonce' => 'nonce:bulk-sendbeam_messages',
);
$table = new SendBeam_Mail_Log_Table();
$table->process_bulk_action();
$kept = sb_log();
ok( 2 === count( $kept ), 'email log: a bulk delete removes exactly the rows that were ticked' );
ok( 'c@t.test' === $kept[0]['to_addr'] && 'a@t.test' === $kept[1]['to_addr'], 'email log: the rows that were not ticked are untouched' );

// The same request without the nonce must not delete anything.
$_REQUEST = array(
	'action'  => 'delete',
	'message' => array( 0 ),
);
$threw = false;
try {
	( new SendBeam_Mail_Log_Table() )->process_bulk_action();
} catch ( SendBeamStubExit $e ) {
	$threw = true;
}
ok( $threw, 'email log: a bulk delete without a valid nonce is refused' );
ok( 2 === count( sb_log() ), 'email log: and nothing was deleted' );
$_REQUEST = array();
sb_log_reset();

// ── Audience lists ──────────────────────────────────────────────────────
$GLOBALS['stub']['remote_reply'] = function ( $url ) {
	if ( false !== strpos( $url, '/contacts?limit=1' ) ) {
		$total = false !== strpos( $url, '/lists/l1/' ) ? 412 : 7;
		return array(
			'response' => array( 'code' => 200 ),
			'body'     => wp_json_encode( array( 'pagination' => array( 'total' => $total ) ) ),
		);
	}
	return array(
		'response' => array( 'code' => 200 ),
		'body'     => wp_json_encode(
			array(
				'lists' => array(
					array( 'id' => 'l1', 'name' => 'Subscribers', 'double_optin' => true ),
					array( 'id' => 'l2', 'name' => 'Trade buyers', 'double_optin' => false ),
				),
			)
		),
	);
};
sendbeam_flush_cache();
$html = sb_render_table( new SendBeam_Lists_Table() );
has( $html, 'column-name column-primary', 'lists table: List is the primary column' );
foreach ( array( 'List', 'Subscribers', 'Confirmation' ) as $sendbeam_col ) {
	has( $html, '>' . $sendbeam_col . '</th>', "lists table: the $sendbeam_col column is there" );
}
has( $html, 'Subscribers', 'lists table: a list renders as a row' );
has( $html, 'Trade buyers', 'lists table: every list renders' );
has( $html, '412', 'lists table: the subscriber count is shown' );
has( $html, 'Double opt-in', 'lists table: a double opt-in list is marked as one' );
has( $html, 'Single opt-in', 'lists table: and a single opt-in list is too' );
has( $html, 'Open in SendBeam', 'lists table: the row links through to the list itself' );

$GLOBALS['stub']['remote_reply'] = array(
	'response' => array( 'code' => 403 ),
	'body'     => '{"error":"forbidden"}',
);
sendbeam_flush_cache();
has( sb_render_table( new SendBeam_Lists_Table() ), 'Lists (read) permission', 'lists table: a key that cannot read lists is told which permission is missing' );

// ── Screen options ──────────────────────────────────────────────────────
$GLOBALS['stub']['screen_options'] = array();
sendbeam_add_per_page_option( 'sendbeam_forms_per_page', 'Forms per page' );
ok( isset( $GLOBALS['stub']['screen_options']['per_page'] ), 'screen options: a list screen offers a rows-per-page box' );
ok( 'sendbeam_forms_per_page' === $GLOBALS['stub']['screen_options']['per_page']['option'], 'screen options: it saves against the screen\'s own option' );
ok( 20 === sendbeam_set_screen_option( false, 'sendbeam_forms_per_page', '20' ), 'screen options: a sane per-page value is kept' );
ok( 200 === sendbeam_set_screen_option( false, 'sendbeam_forms_per_page', '99999' ), 'screen options: an absurd one is clamped' );
ok( 1 === sendbeam_set_screen_option( false, 'sendbeam_forms_per_page', '0' ), 'screen options: and so is zero' );
ok( false === sendbeam_set_screen_option( false, 'another_plugin_per_page', '50' ), 'screen options: another plugin\'s option is left alone' );

sendbeam_flush_cache();
$GLOBALS['stub']['remote_reply'] = null;

/*
 * A site whose key is pinned in wp-config.php has already been set up by
 * whoever pinned it. This runs down here because the constant, once defined,
 * cannot be undefined — which is exactly why the wizard has to cope with it.
 */
$GLOBALS['stub']['transients'] = array();
delete_option( 'sendbeam_setup_done' );
sendbeam_on_activate();
unset( $GLOBALS['stub']['redirect'] );
try {
	sendbeam_maybe_redirect_to_wizard();
} catch ( SendBeamStubExit $e ) {
	unset( $e );
}
ok( ! isset( $GLOBALS['stub']['redirect'] ), 'wizard: a site whose key is pinned in wp-config.php is not walked through setup' );
delete_option( 'sendbeam_setup_done' );

/* ─────────────────────────── The design system ─────────────────────────
 * Four foreign signals had to go: 10px uppercase mono labels, black primary
 * buttons, labels beside their fields, and a page with a right margin and no
 * left one. What stays is ink on paper, the three-square mark, the cards, the
 * header band and the state pill.
 */
$sendbeam_css = sendbeam_admin_css();

ok( false === strpos( $sendbeam_css, 'text-transform:uppercase' ), 'design: nothing is shouted in uppercase any more' );
ok( false === strpos( $sendbeam_css, 'letter-spacing:.1em' ), 'design: and nothing is tracked out like an eyebrow' );
has( $sendbeam_css, '.sb-label{display:block;font-size:13px;font-weight:600', 'design: labels are sentence case at a readable size' );
has( $sendbeam_css, '--brand:var(--wp-admin-theme-color,#2271b1)', 'design: the primary colour is the admin theme\'s, with core\'s blue as the fallback' );
has( $sendbeam_css, '.sendbeam-app .sb-btn{', 'design: buttons are the plugin\'s own, scoped to the plugin' );
has( $sendbeam_css, 'background:var(--brand);color:#fff', 'design: a primary button is the admin theme colour with white text' );
has( $sendbeam_css, '.sendbeam-app .sb-btn--ghost{background:transparent;color:var(--ink);border-color:var(--ink)', 'design: secondary is an ink outline' );
has( $sendbeam_css, '.sendbeam-app .sb-btn--danger{background:transparent;color:var(--v);border-color:var(--v)', 'design: danger is a vermilion outline, never a filled red button' );
has( $sendbeam_css, '.sendbeam-app{--paper:#EDEBE6', 'design: the paper and ink are kept' );
has( $sendbeam_css, 'margin:20px 20px 0;', 'design: the page has the same margin on both sides' );
has( $sendbeam_css, '.sendbeam-app .sb-toggle input[type=checkbox]{appearance:none', 'design: on/off settings are switches' );
has( $sendbeam_css, 'min-height:40px', 'design: fields are 40px tall' );
has( $sendbeam_css, 'outline:2px solid var(--brand)', 'design: and focus is the admin theme colour' );
has( $sendbeam_css, '.sendbeam-app .form-table th{font-size:13px', 'design: even core\'s two-column form table is stacked label-above' );
has( $sendbeam_css, '.sb-scroll{overflow-x:auto', 'design: a table that will not fit scrolls inside its own box' );
has( $sendbeam_css, '@media (max-width:782px)', 'design: and the whole thing has a phone layout' );

/*
 * Three single digits stacked down a card took half of it. They stay in a row
 * on a phone and only break at a width no phone actually is.
 */
/*
 * Nothing on any screen is drawn and then hidden by script on load. That is
 * what the owner saw: a red card flashing under the Disconnect button on
 * every refresh of the Overview.
 */
$sendbeam_js = sendbeam_connect_admin_js();
lacks( $sendbeam_css, '.sb-confirm__box{display:none', 'design: the confirmation is not hidden by a stylesheet either' );
has( $sendbeam_css, '.sendbeam-app .sb-confirm>summary{list-style:none', 'design: the disclosure marker is off, so the summary reads as the button it is' );
has( $sendbeam_css, '.sb-confirm[open]>summary .sb-confirm__label--shut{display:none}', 'design: and the summary\'s label is the action it would perform next' );
has( $sendbeam_css, '.sendbeam-app .is-hidden{display:none!important}', 'design: a row that does not apply yet is hidden by the server, not by script on load' );

$GLOBALS['stub']['inline']['sendbeam-admin'] = array();
sendbeam_admin_assets( 'toplevel_page_sendbeam' );
$sendbeam_admin_js = implode( "\n", $GLOBALS['stub']['inline']['sendbeam-admin'] );
lacks( $sendbeam_admin_js, 'sb-confirm', 'design: no script touches the confirmation at all' );
lacks( $sendbeam_admin_js, 'tr.hidden', 'design: nor hides a settings row after the page has drawn' );
has( $sendbeam_admin_js, 'classList.toggle( "is-hidden"', 'design: the switch moves a class the server already set' );

// The server's half of that: a row that does not apply yet arrives hidden.
sb_seed_settings( array( 'mail_enabled' => 0 ) );
has( sendbeam_when_mail_class(), 'is-hidden', 'design: with site email off the detail rows arrive hidden' );
sb_seed_settings( array( 'mail_enabled' => 1 ) );
lacks( sendbeam_when_mail_class(), 'is-hidden', 'design: and with it on they arrive shown' );
sb_seed_settings( array() );

has( $sendbeam_css, '@media (max-width:340px){', 'design: the workspace figures only stack on something narrower than a phone' );
has( $sendbeam_css, '.sb-stats .sb-fig{font-size:24px}', 'design: with the figure tightened so the row is one compact band' );
// One message, one line: a result that wraps under an address reads as a row
// of its own, and the address gives way first.
has( $sendbeam_css, 'flex-wrap:nowrap', 'design: a log row stays on one line' );
has( $sendbeam_css, '.sb-recent__who{flex:1 1 auto;min-width:0;overflow:hidden;text-overflow:ellipsis', 'design: where the address truncates rather than pushing the result down' );
has( $sendbeam_css, '.sb-recent__what{flex:0 0 auto;white-space:nowrap}', 'design: and the result stays whole, on the right' );
has( $sendbeam_css, '.sb-recent__long{display:none}', 'design: the long date is swapped for the short one on a phone' );

/*
 * The copy field's height comes from its line box, not from a pinned one: a
 * pinned height on a 12px mono field clips its own descenders, and the
 * underscore in `resend._domainkey` simply vanished.
 */
has( $sendbeam_css, '.sendbeam-app .sb-copy-field{display:block;width:100%;min-width:0;font-size:12px;line-height:1.5;', 'design: a copy field is a readable 12px with room for its own descenders' );
has( $sendbeam_css, 'height:auto;min-height:0;padding:6px 8px', 'design: and its height comes from its content, not from a pinned one that clips an underscore' );

// On a phone each record is a block, not four columns in 393px.
has( $sendbeam_css, '.sb-dns,.sb-dns thead,.sb-dns tbody,.sb-dns tr,.sb-dns td{display:block', 'design: the records table stops being a table on a phone' );
has( $sendbeam_css, '.sb-dns td.sb-dns__host,.sb-dns td.sb-dns__value{grid-column:1/-1', 'design: where the host and the value each get the full width' );
// The desktop column widths are more specific than display:block, so they
// have to be undone by name or the host stays at a third of the card.
has( $sendbeam_css, '.sb-dns td,.sb-dns td.sb-dns__type,.sb-dns td.sb-dns__host,', 'design: and the desktop column widths are undone by name, not left to lose a specificity fight' );
has( $sendbeam_css, 'content:attr(data-label)', 'design: and carry their column name, because the header row has gone' );
has( $sendbeam_css, 'word-break:break-all', 'design: a value too long for the line wraps rather than being cut off' );
// Desktop keeps the row, with the room going to the column that needs it.
has( $sendbeam_css, '.sb-dns th:nth-child(2),.sb-dns td.sb-dns__host{width:30%}', 'design: on desktop the host column is pinned' );
has( $sendbeam_css, '.sb-dns th:nth-child(4),.sb-dns td.sb-dns__found{width:9em}', 'design: so is the verdict, and everything left over goes to the value' );

// Every screen's markup: no core primary button, no leftover uppercase class.
$GLOBALS['stub']['caps']['manage_options'] = true;
foreach ( array_keys( sendbeam_pages() ) as $sendbeam_slug ) {
	$_GET = array( 'page' => $sendbeam_slug );
	ob_start();
	sendbeam_render_admin_page();
	$sendbeam_html = ob_get_clean();
	lacks( $sendbeam_html, 'button-primary', "design: $sendbeam_slug uses no core button-primary" );
	lacks( $sendbeam_html, 'sb-eyebrow', "design: $sendbeam_slug has no uppercase-mono eyebrow left" );
}
$_GET = array();

/*
 * The one place core's button classes are right is inside a core notice,
 * where a brand-styled button is the thing that looks pasted in — that is
 * asserted where the Dashboard notice is tested, above.
 */

// Every hand-rolled table that is left sits in the scroll wrapper the plugin
// already owns. This is the exact fault that made two screens scroll
// sideways at 393px.
$sendbeam_screens_src = file_get_contents( dirname( __DIR__ ) . '/includes/screens.php' );
ok(
	substr_count( $sendbeam_screens_src, '<table class="sb-table"' ) === substr_count( $sendbeam_screens_src, '<div class="sb-scroll"><table class="sb-table"' ),
	'design: every .sb-table in the plugin is inside a .sb-scroll'
);
ok( 0 === substr_count( $sendbeam_screens_src, "\tsubmit_button(" ), 'design: no screen renders core\'s submit_button()' );

/* ────────────── What this site actually sends as ───────────────────────
 * The Overview was capable of saying two true things that added up to a lie:
 * "harbourlane.co.uk is verified" beside a From address still on SendBeam's
 * shared host. Both right, and together wrong.
 */
$sb_sender_status = sendbeam_connect_normalise_status(
	array(
		'workspace' => array( 'id' => 'w1', 'name' => 'Harbour Lane' ),
		'domain'    => array( 'name' => 'harbourlane.co.uk', 'verified' => true, 'records' => array() ),
		'sender'    => array( 'from_name' => 'Harbour Lane', 'from_email' => 'ws-f7485df7@post.sendbeam.io' ),
	)
);

/** Classify the From address a given settings array would send with. */
function sb_sender( $settings, $status ) {
	sb_seed_settings( $settings );
	return sendbeam_effective_sender( $status );
}

$sb_base = array( 'api_key' => 'sb_live_senderkeysenderkey', 'mail_enabled' => 1 );

$r = sb_sender( array_merge( $sb_base, array( 'mail_from_email' => 'hello@harbourlane.co.uk' ) ), $sb_sender_status );
ok( 'own_domain' === $r['kind'], 'sender: an address on the verified domain is the site\'s own' );
$r = sb_sender( array_merge( $sb_base, array( 'mail_from_email' => 'orders@mail.harbourlane.co.uk' ) ), $sb_sender_status );
ok( 'own_domain' === $r['kind'], 'sender: and so is one on a subdomain of it' );
$r = sb_sender( array_merge( $sb_base, array( 'mail_from_email' => 'ws-f7485df7@post.sendbeam.io' ) ), $sb_sender_status );
ok( 'shared' === $r['kind'], 'sender: an address on SendBeam\'s own host is the shared one' );
$r = sb_sender( array_merge( $sb_base, array( 'mail_from_email' => 'hi@mail.sendbeam.io' ) ), $sb_sender_status );
ok( 'shared' === $r['kind'], 'sender: and so is the other host the platform sends shared mail from' );

/*
 * "Anywhere under sendbeam.io" was the rule, and it was wrong: a customer
 * whose verified domain is a subdomain of SendBeam's own — which every site
 * on the staging host is — was told their own domain was the shared address.
 * post. and mail. are the two hosts the platform actually sends from;
 * nothing else under that domain is shared.
 */
$sb_sub_status = sendbeam_connect_normalise_status(
	array(
		'workspace' => array( 'id' => 'w1', 'name' => 'Harbour Lane' ),
		'domain'    => array( 'name' => 'wp-test.sendbeam.io', 'verified' => true, 'records' => array() ),
		'sender'    => array( 'from_name' => 'WP Test', 'from_email' => 'ws-f7485df7@post.sendbeam.io' ),
	)
);
$r = sb_sender( array_merge( $sb_base, array( 'mail_from_email' => 'hello@wp-test.sendbeam.io' ) ), $sb_sub_status );
ok( 'own_domain' === $r['kind'], 'sender: a verified domain that happens to sit under SendBeam\'s own is still the site\'s own domain' );
$r = sb_sender( array_merge( $sb_base, array( 'mail_from_email' => 'ws-x@post.sendbeam.io' ) ), $sb_sub_status );
ok( 'shared' === $r['kind'], 'sender: while the shared host beside it is still shared' );
$r = sb_sender( array_merge( $sb_base, array( 'mail_from_email' => 'x@mail.sendbeam.io' ) ), $sb_sub_status );
ok( 'shared' === $r['kind'], 'sender: and so is the other one' );
$r = sb_sender( array_merge( $sb_base, array( 'mail_from_email' => 'x@other.sendbeam.io' ) ), $sb_sender_status );
ok( 'other' === $r['kind'], 'sender: but some third subdomain nobody verified is neither' );

/*
 * And the state the owner actually saw it in: a DISCONNECTED site, where
 * there is no verified domain for own_domain to settle it with, so the shared
 * rule is the only thing deciding. "Anywhere under sendbeam.io" called the
 * site's own address SendBeam's shared one on the very screen that had just
 * said the site is not connected.
 */
$r = sb_sender( array_merge( $sb_base, array( 'mail_from_email' => 'hello@wp-test.sendbeam.io' ) ), sendbeam_connect_empty_status() );
ok( 'shared' !== $r['kind'], 'sender: with nothing connected, the site\'s own address is not called SendBeam\'s shared one' );
ok( 'other' === $r['kind'], 'sender: it is simply a domain this site cannot say anything about yet' );
$r = sb_sender( array_merge( $sb_base, array( 'mail_from_email' => 'ws-x@post.sendbeam.io' ) ), sendbeam_connect_empty_status() );
ok( 'shared' === $r['kind'], 'sender: while the genuinely shared host is still recognised without a connection' );

// The two hosts are derived from the app URL, so a self-hosted SendBeam
// recognises its own rather than SendBeam's.
ok( in_array( 'post.sendbeam.io', sendbeam_shared_sender_hosts(), true ), 'sender: the shared hosts are post. and mail. of the app host' );
ok( in_array( 'mail.sendbeam.io', sendbeam_shared_sender_hosts(), true ), 'sender: both of them' );
ok( ! in_array( 'sendbeam.io', sendbeam_shared_sender_hosts(), true ), 'sender: and the bare app host is not one of them' );
$r = sb_sender( array_merge( $sb_base, array( 'mail_from_email' => 'hello@somewhere-else.test' ) ), $sb_sender_status );
ok( 'other' === $r['kind'], 'sender: anywhere else is somewhere SendBeam will refuse' );
$r = sb_sender( $sb_base, $sb_sender_status );
ok( 'shared' === $r['kind'], 'sender: with nothing set it falls back to the workspace sender, which is the shared one' );
ok( 'ws-f7485df7@post.sendbeam.io' === $r['email'], 'sender: and reports the address it would actually use' );
$r = sb_sender( $sb_base, sendbeam_connect_empty_status() );
ok( 'none' === $r['kind'], 'sender: nothing known is not a classification' );

// What it should become once the domain is verified.
ok( 'hello@harbourlane.co.uk' === sendbeam_own_domain_sender( $sb_sender_status ), 'sender: a verified domain with a shared workspace sender gets hello@ its own domain' );
$sb_own_ws = $sb_sender_status;
$sb_own_ws['sender']['from_email'] = 'orders@harbourlane.co.uk';
ok( 'orders@harbourlane.co.uk' === sendbeam_own_domain_sender( $sb_own_ws ), 'sender: a workspace sender already on the domain is kept — it is the address the account holder chose' );
$sb_unver = $sb_sender_status;
$sb_unver['domain']['verified'] = false;
ok( '' === sendbeam_own_domain_sender( $sb_unver ), 'sender: an unverified domain is nothing to send from' );

// ── Step 2 stops claiming what step 4 controls ─────────────────────────
has( sendbeam_domain_sentence( 'harbourlane.co.uk', true ), 'harbourlane.co.uk is verified.', 'step 2: a verified domain says it is verified' );
lacks( sendbeam_domain_sentence( 'harbourlane.co.uk', true ), 'will be sent from your own domain', 'step 2: and says nothing about what this site actually sends as — that is step 4\'s to answer' );

// ── The contradiction, on the screen ───────────────────────────────────
$sb_shared = array(
	'api_key'                    => 'sb_live_connectedconnectedxx',
	'sendbeam_connected_via'     => 'connect',
	'sendbeam_connect_workspace' => 'Harbour Lane',
	'sendbeam_connect_granted'   => 'forms,transactional:send,domain',
	'mail_enabled'               => 1,
	'mail_from_email'            => 'ws-f7485df7@post.sendbeam.io',
	'default_form'               => $form,
);
$sb_shared_body = array(
	'workspace' => array( 'id' => 'w1', 'name' => 'Harbour Lane' ),
	'domain'    => array( 'name' => 'harbourlane.co.uk', 'verified' => true, 'records' => array() ),
	'sender'    => array( 'from_name' => 'Harbour Lane', 'from_email' => 'ws-f7485df7@post.sendbeam.io' ),
);
update_option( 'sendbeam_form_placed', 1, false );

$ov = sb_overview( $sb_shared, $sb_shared_body );
has( $ov, 'harbourlane.co.uk is verified.', 'overview: step 2 says the domain is verified' );
lacks( $ov, 'will be sent from your own domain', 'overview: and does not promise what step 4 has not done' );
has( $ov, 'which is SendBeam&#039;s shared address, not harbourlane.co.uk', 'overview: step 4 says what is really happening' );
has( $ov, 'needs-attention', 'overview: and the step is marked as needing attention rather than ticked' );
has( $ov, 'Send from harbourlane.co.uk', 'overview: with one press that fixes it' );
has( $ov, 'value="sendbeam_mail_own_domain"', 'overview: which posts to a handler of its own' );
has( $ov, 'nonce:sendbeam_mail_own_domain', 'overview: with a nonce' );
has( $ov, 'That changes the From address to hello@harbourlane.co.uk', 'overview: and says exactly what it will do' );
has( $ov, 'Sends as ws-f7485df7@post.sendbeam.io (SendBeam&#039;s shared address)', 'overview: the connection card names the kind of address, not just the address' );
ok( false === strpos( $ov, '1 step left' ) || false !== strpos( $ov, 'step' ), 'overview: the checklist counts the open step' );

// Press it, and the two halves agree.
$_GET     = array( '_wpnonce' => 'nonce:sendbeam_mail_own_domain' );
$_REQUEST = $_GET;
sendbeam_connect_cache_status( sendbeam_connect_normalise_status( $sb_shared_body ) );
sb_seed_settings( $sb_shared );
set_transient( 'sendbeam_connection', array( 'state' => 'ok', 'message' => '', 'count' => 1 ) );
try {
	sendbeam_handle_mail_own_domain();
} catch ( SendBeamStubExit $e ) {
	unset( $e );
}
$_GET     = array();
$_REQUEST = array();
has( $GLOBALS['stub']['redirect']['url'], 'sendbeam_sender=own', 'own domain: pressing it says so' );
ok( 'hello@harbourlane.co.uk' === sendbeam_settings()['mail_from_email'], 'own domain: and the From address moves to the verified domain' );
ok( in_array( 'mail_from_email', sendbeam_settings()['sendbeam_connect_filled'], true ), 'own domain: recorded as Connect\'s doing, so disconnecting puts back what it found' );

$sb_fixed              = sendbeam_settings();
$ov                    = sb_overview( $sb_fixed, $sb_shared_body );
has( $ov, 'On, sending as hello@harbourlane.co.uk', 'overview: step 4 now says what it is doing' );
lacks( $ov, 'needs-attention', 'overview: and the step is finished' );
has( $ov, 'Sends as hello@harbourlane.co.uk (your domain)', 'overview: the connection card agrees with it' );
lacks( $ov, 'Send from harbourlane.co.uk', 'overview: and the button is gone, because there is nothing left to fix' );

// An address on a domain this workspace has not verified is refused, and says so.
$sb_elsewhere                    = $sb_shared;
$sb_elsewhere['mail_from_email'] = 'hello@somewhere-else.test';
$ov                              = sb_overview( $sb_elsewhere, $sb_shared_body );
has( $ov, 'somewhere-else.test is not a domain this workspace has verified', 'overview: an unverifiable From address is named as one' );
has( $ov, 'SendBeam refuses these messages', 'overview: and says what happens to those messages' );
has( $ov, 'needs-attention', 'overview: and the step is not ticked' );

// ── The deferred switch-on must not park a site on the shared address ───
sb_seed_settings( array(
		'api_key'                  => 'sb_live_deferreddeferredxx',
		'sendbeam_connect_granted' => 'forms,transactional:send,domain',
		'sendbeam_mail_deferred'   => 1,
		'mail_enabled'             => 0,
		'mail_from_email'          => '',
	)
);
sendbeam_connect_enable_mail( sendbeam_connect_normalise_status( $sb_shared_body ) );
$sb_after = sendbeam_settings();
ok( 1 === (int) $sb_after['mail_enabled'], 'deferred switch-on: site email comes on when the domain verifies' );
ok( 'hello@harbourlane.co.uk' === $sb_after['mail_from_email'], 'deferred switch-on: on the verified domain, not on SendBeam\'s shared address' );

// And a shared address Connect wrote earlier is corrected when the domain lands.
sb_seed_settings( array(
		'api_key'                       => 'sb_live_deferreddeferredxx',
		'sendbeam_connect_granted'      => 'forms,transactional:send,domain',
		'sendbeam_mail_deferred'        => 1,
		'mail_enabled'                  => 0,
		'mail_from_email'               => 'ws-f7485df7@post.sendbeam.io',
		'sendbeam_connect_filled'       => array( 'mail_from_email' ),
	)
);
sendbeam_connect_enable_mail( sendbeam_connect_normalise_status( $sb_shared_body ) );
ok( 'hello@harbourlane.co.uk' === sendbeam_settings()['mail_from_email'], 'deferred switch-on: a shared address Connect wrote is corrected once the domain verifies' );

// An address the OWNER typed is never overruled, whatever the domain does.
sb_seed_settings( array(
		'api_key'                  => 'sb_live_deferreddeferredxx',
		'sendbeam_connect_granted' => 'forms,transactional:send,domain',
		'sendbeam_mail_deferred'   => 1,
		'mail_enabled'             => 0,
		'mail_from_email'          => 'ws-f7485df7@post.sendbeam.io',
		'sendbeam_connect_filled'  => array(),
	)
);
sendbeam_connect_enable_mail( sendbeam_connect_normalise_status( $sb_shared_body ) );
ok( 'ws-f7485df7@post.sendbeam.io' === sendbeam_settings()['mail_from_email'], 'deferred switch-on: an address the owner typed is left exactly as they typed it' );

delete_option( 'sendbeam_form_placed' );
sb_seed_settings( array() );
sendbeam_flush_cache();
sendbeam_connect_forget_status();

/* ─────────────────── The Overview: connection and checklist ────────────
 * The connection used to be answered by a ticked line inside step 1, which
 * on a finished site — most sites, most of the time — said nothing at all.
 */
$GLOBALS['stub']['user_id']                = 7;
$GLOBALS['stub']['caps']['manage_options'] = true;
delete_user_meta( 7, 'sendbeam_checklist_hidden' );

// Every step done: connected, verified, a form on the site, site email on.
$sb_done                 = $sb_connected;
$sb_done['mail_enabled'] = 1;
$sb_done['default_form'] = $form;
update_option( 'sendbeam_form_placed', 1, false );

sb_log_reset();
sendbeam_mail_log_insert( 'bob@customer.test', 'Password reset', 'failed', 'refused', '', 1758600000 );
sendbeam_mail_log_insert( 'ada@customer.test', 'Order #1001', 'sent', '', '', 1758700000 );

$ov = sb_overview( $sb_done, $sb_verified );
has( $ov, '<h2>Connection</h2>', 'overview: the connection is a card of its own, above the checklist' );
has( $ov, 'Connected to Harbour Lane', 'overview: which names the workspace' );
has( $ov, 'Sends as', 'overview: and the address this site sends from' );
has( $ov, 'Reconnect', 'overview: with Reconnect' );
has( $ov, 'Disconnect', 'overview: and Disconnect' );
has( $ov, 'Open SendBeam', 'overview: and a way through to SendBeam itself' );
/*
 * The app, not the marketing site. `sendbeam_app_url()` is the origin the API
 * is called against and the bare origin is the page inviting people to sign
 * up — which is where "Open SendBeam" was sending people who already had an
 * account. The signed-in home is /dashboard.
 */
has( $ov, 'href="https://sendbeam.io/dashboard"', 'overview: which opens the app, not the page selling it' );
ok( 1 === substr_count( $ov, 'https://sendbeam.io/dashboard' ), 'overview: once, from the workspace card' );
lacks( $ov, 'href="https://sendbeam.io"', 'overview: and nothing points at the bare origin' );
has( $ov, 'class="sb-card__action"', 'overview: whose one secondary action sits in the card header, not adrift in its body' );

// The workspace figures read across, not down a column with a blank card
// under them.
has( $ov, '<div class="sb-stats">', 'overview: the three figures are a row' );
ok( 3 === substr_count( $ov, '<span class="sb-fig">' ), 'overview: three of them' );
has( $ov, 'Subscribers counts people, not list memberships. Cached for five minutes.', 'overview: with one muted line explaining what a subscriber is' );

// And the card earns its height with what this site has actually done.
has( $ov, 'Recent site email', 'overview: the workspace card shows what this site has been sending' );
has( $ov, 'sb-recent__when', 'overview: with a date per row' );
// Two dates, one shown at a time by CSS: the site's own format is right on a
// desktop and took half the row on a phone.
has( $ov, 'sb-recent__long', 'overview: the site\'s own date format' );
has( $ov, 'sb-recent__short', 'overview: and a short one for a phone' );
has( $ov, 'sb-recent__who', 'overview: who it went to' );
has( $ov, 'sb-recent__what', 'overview: and how it went' );
has( $ov, 'View the log', 'overview: and a way through to the whole log' );
ok( strpos( $ov, '<h2>Connection</h2>' ) < strpos( $ov, '<h2>Setup</h2>' ), 'overview: the connection comes before the checklist' );
ok( 1 === substr_count( $ov, 'value="sendbeam_disconnect"' ), 'overview: and there is exactly one Disconnect on the screen' );

// A finished checklist can be put away, and brought back.
has( $ov, 'All done', 'overview: a finished checklist says so' );
has( $ov, 'Hide this checklist', 'overview: and offers to go' );
update_user_meta( 7, 'sendbeam_checklist_hidden', 1 );
$ov = sb_overview( $sb_done, $sb_verified );
lacks( $ov, 'Verify your sending domain', 'overview: a hidden checklist is gone' );
has( $ov, 'Set up and sending.', 'overview: replaced by one line saying so' );
has( $ov, 'Show the checklist again', 'overview: which can be undone' );

// An unfinished checklist is never hidden, whatever anybody has dismissed.
$ov = sb_overview( $sb_done, $sb_unverified );
has( $ov, 'Verify your sending domain', 'overview: an unfinished checklist stays, even for someone who hid a finished one' );
has( $ov, '1 step left', 'overview: and says how many steps are left' );
lacks( $ov, 'Hide this checklist', 'overview: with nothing offering to hide it' );
delete_user_meta( 7, 'sendbeam_checklist_hidden' );

// Hiding it is per user, through a nonced handler.
$sendbeam_hide = sendbeam_checklist_url( true );
has( $sendbeam_hide, 'action=sendbeam_hide_checklist', 'overview: hiding the checklist goes through admin-post' );
has( $sendbeam_hide, '_wpnonce=', 'overview: with a nonce' );
$_GET     = array( 'state' => '1', '_wpnonce' => 'nonce:sendbeam_hide_checklist' );
$_REQUEST = $_GET;
try {
	sendbeam_handle_hide_checklist();
} catch ( SendBeamStubExit $e ) {
	unset( $e );
}
ok( sendbeam_checklist_hidden(), 'overview: and it is remembered for that person' );
$_GET     = array( 'state' => '0', '_wpnonce' => 'nonce:sendbeam_hide_checklist' );
$_REQUEST = $_GET;
try {
	sendbeam_handle_hide_checklist();
} catch ( SendBeamStubExit $e ) {
	unset( $e );
}
ok( ! sendbeam_checklist_hidden(), 'overview: and can be undone' );
$_GET     = array();
$_REQUEST = array();
delete_option( 'sendbeam_form_placed' );

// With nothing sent yet the card says so rather than showing an empty list.
sb_log_reset();
has( sb_overview( $sb_done, $sb_verified ), 'Nothing has gone out through SendBeam from this site yet', 'overview: a site that has sent nothing is told so, not shown an empty list' );

/* ────────────────────── The pop-up rules, as cards ─────────────────────
 * Each rule was a grey fieldset with a black legend tag — the one screen in
 * the plugin that looked like a different product.
 */
$GLOBALS['stub']['caps']['manage_options'] = true;
update_option(
	'sendbeam_popups',
	array(
		array( 'enabled' => 1, 'form' => $form, 'trigger' => 'timer', 'delay' => 3, 'scroll' => 50, 'label' => '', 'once' => 'week', 'where' => 'everywhere', 'url' => '', 'heading' => 'Get the roast notes', 'blurb' => '', 'style' => 'split', 'image' => '', 'eyebrow' => 'Monthly', 'button' => 'Subscribe', 'proof' => 1 ),
		array( 'enabled' => 0, 'form' => $other, 'trigger' => 'scroll', 'delay' => 5, 'scroll' => 70, 'label' => '', 'once' => 'day', 'where' => 'posts', 'url' => '', 'heading' => 'Before you go', 'blurb' => '', 'style' => 'bold', 'image' => '', 'eyebrow' => '', 'button' => '', 'proof' => 0 ),
	)
);

ob_start();
sendbeam_screen_popup();
$sb_popups = ob_get_clean();

has( $sb_popups, '<section class="sb-card sb-rule">', 'pop-ups: a rule is the same card as everything else on every other screen' );
lacks( $sb_popups, '<fieldset class="sb-rule">', 'pop-ups: not a fieldset' );
lacks( $sb_popups, '<legend', 'pop-ups: with no legend tag' );
lacks( $sb_popups, 'sb-rule__foot', 'pop-ups: and no footer strip' );
// Two rules and the blank one the Add button clones.
ok( 3 === substr_count( $sb_popups, '<section class="sb-card sb-rule">' ), 'pop-ups: one card per rule, plus the template' );

// The order is the rule, so the cards say which is which.
has( $sb_popups, '<h3>Pop-up 1</h3>', 'pop-ups: numbered, because the first match wins' );
has( $sb_popups, '<h3>Pop-up 2</h3>', 'pop-ups: in the order they are matched' );

// The header carries the two things you do to a whole rule.
$sb_head = substr( $sb_popups, strpos( $sb_popups, '<h3>Pop-up 1</h3>' ) );
$sb_head = substr( $sb_head, 0, strpos( $sb_head, '</header>' ) );
has( $sb_head, '[enabled]', 'pop-ups: the Active toggle is in the card header' );
has( $sb_head, 'sb-toggle', 'pop-ups: as a switch' );
has( $sb_head, 'sb-remove', 'pop-ups: and Remove beside it' );

// Two fields that belong together, side by side and the same width.
has( $sb_popups, '<div class="sb-rule__pair">', 'pop-ups: Eyebrow and Button label share a row' );
has( $sb_popups, 'The words on the pop-up', 'pop-ups: with helper text under each, so the labels line up' );

// Every field keeps its name, so saving is unchanged.
foreach ( array( 'form', 'where', 'url', 'trigger', 'delay', 'scroll', 'heading', 'blurb', 'style', 'image', 'eyebrow', 'button', 'proof', 'label', 'once', 'enabled' ) as $sb_field ) {
	has( $sb_popups, 'sendbeam_popup[0][' . $sb_field . ']', "pop-ups: the $sb_field field keeps its name" );
}
has( $sb_popups, 'sendbeam_popup[1][form]', 'pop-ups: and the second rule keeps its index' );

// The blank row the Add button clones carries placeholders for both.
has( $sb_popups, 'sendbeam_popup[__i__]', 'pop-ups: the template still carries the index placeholder' );
has( $sb_popups, '<h3>Pop-up __n__</h3>', 'pop-ups: and one for the number, so a new card is not called Pop-up __i__' );

// The page's own actions sit below the cards.
ok(
	strpos( $sb_popups, '+ Add a pop-up' ) > strpos( $sb_popups, '<h3>Pop-up 2</h3>' ),
	'pop-ups: Add a pop-up sits below the cards'
);
has( $sb_popups, 'Save pop-ups', 'pop-ups: and Save with it' );

// The grey is gone from the stylesheet too.
lacks( $sendbeam_css, '.sb-rule{border:1px solid var(--ink);background:var(--paper)', 'pop-ups: nothing paints a rule grey any more' );
has( $sendbeam_css, '.sb-rule__pair{grid-column:1/-1;display:grid;grid-template-columns:1fr 1fr', 'pop-ups: the paired fields are equal columns' );
has( $sendbeam_css, '.sb-rule__grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:16px;align-items:start}', 'pop-ups: and the grid aligns labels at the top rather than fields at the bottom' );

// Removing a card renumbers the rest, or the numbers start lying.
has( $sendbeam_admin_js, 'function renumber()', 'pop-ups: the cards renumber themselves' );
ok( 2 === substr_count( $sendbeam_admin_js, 'renumber();' ), 'pop-ups: after an add and after a remove' );

update_option( 'sendbeam_popups', array() );

/* ─────────────────────────── The email log ─────────────────────────────
 * It was twenty rows in an option: a shop doing thirty order emails an hour
 * lost the morning by lunchtime, and every write rewrote the whole array.
 */
sb_log_reset();
$GLOBALS['stub']['dbdelta'] = array();
delete_option( 'sendbeam_db_version' );

// ── The table's shape ───────────────────────────────────────────────────
sendbeam_mail_log_install();
$sb_ddl = $GLOBALS['stub']['dbdelta'][0];
has( $sb_ddl, 'CREATE TABLE wp_sendbeam_mail_log', 'log table: named from $wpdb->prefix, so multisite gets one per site' );
foreach ( array(
	'id bigint(20) unsigned NOT NULL AUTO_INCREMENT',
	'sent_at datetime NOT NULL',
	'to_addr varchar(255)',
	'subject text NOT NULL',
	'result varchar(16)',
	'note text NOT NULL',
	'source varchar(64)',
) as $sb_col ) {
	has( $sb_ddl, $sb_col, "log table: the $sb_col column" );
}
has( $sb_ddl, 'PRIMARY KEY  (id)', 'log table: with the two spaces dbDelta insists on' );
has( $sb_ddl, 'KEY sent_at (sent_at)', 'log table: an index on the column everything orders by' );
has( $sb_ddl, 'KEY result (result)', 'log table: and on the one the views filter by' );
has( $sb_ddl, 'utf8mb4', 'log table: in the site\'s own charset and collation' );
lacks( $sb_ddl, 'body', 'log table: the message itself is never stored' );
lacks( $sb_ddl, 'html', 'log table: in any column' );
ok( SENDBEAM_DB_VERSION === (int) get_option( 'sendbeam_db_version' ), 'log table: and the version is recorded' );

// Installing again when the version already matches does nothing.
$GLOBALS['stub']['dbdelta'] = array();
sendbeam_mail_log_maybe_upgrade();
ok( 0 === count( $GLOBALS['stub']['dbdelta'] ), 'log table: a version that already matches does not run dbDelta again' );

// An upgrade by zip fires no activation hook, so the tidy-up is scheduled
// from here too or a site that upgraded that way keeps its log for ever.
$GLOBALS['stub']['cron'] = array();
delete_option( 'sendbeam_db_version' );
sendbeam_mail_log_maybe_upgrade();
ok( (bool) wp_next_scheduled( SENDBEAM_PRUNE_HOOK ), 'log table: an upgrade schedules the daily tidy-up, activation hook or not' );

// ── Writing ────────────────────────────────────────────────────────────
sb_log_reset();
ok( sendbeam_mail_log_insert( 'ada@customer.test', 'Order #1001', 'sent', '', 'woocommerce', 1758700000 ), 'log: a row goes in' );
$sb_row = sb_log()[0];
ok( 'ada@customer.test' === $sb_row['to_addr'], 'log: with the address it went to' );
ok( '2025-09-24 07:46:40' === $sb_row['sent_at'], 'log: and a UTC datetime, so the log does not move when the site changes timezone' );
ok( 'woocommerce' === $sb_row['source'], 'log: and which plugin asked for it' );
sendbeam_mail_log_insert( 'bob@t.test', 'X', 'nonsense-result', '', '', 1758700001 );
ok( 'failed' === sb_log()[0]['result'], 'log: a result nobody recognises is recorded as a failure, not as a success' );
sendbeam_mail_log_insert( array( 'a@t.test', 'b@t.test' ), 'Y', 'sent', '', '', 1758700002 );
ok( 'a@t.test, b@t.test' === sb_log()[0]['to_addr'], 'log: several recipients become one readable field' );
sendbeam_mail_log_insert( str_repeat( 'x', 400 ) . '@t.test', 'Z', 'sent', '', str_repeat( 's', 200 ), 1758700003 );
ok( 255 === mb_strlen( sb_log()[0]['to_addr'] ), 'log: an address longer than the column is cut, not refused' );
ok( 64 === mb_strlen( sb_log()[0]['source'] ), 'log: and so is the source' );

// ── The migration off the option ───────────────────────────────────────
sb_log_reset();
update_option(
	'sendbeam_mail_log',
	array(
		array( 'at' => 1758700000, 'to' => 'new@t.test', 'subject' => 'Newest', 'result' => 'sent', 'note' => '' ),
		array( 'at' => 1758600000, 'to' => 'old@t.test', 'subject' => 'Oldest', 'result' => 'failed', 'note' => 'refused' ),
	)
);
ok( 2 === sendbeam_mail_log_migrate(), 'migration: both rows move into the table' );
ok( false === get_option( 'sendbeam_mail_log' ), 'migration: and the option is gone' );
$sb_moved = sb_log();
ok( 'new@t.test' === $sb_moved[0]['to_addr'], 'migration: newest first, as it was' );
ok( '2025-09-23 04:00:00' === $sb_moved[1]['sent_at'], 'migration: with the original timestamps, not the moment of the upgrade' );
ok( 'refused' === $sb_moved[1]['note'], 'migration: and the reason it failed' );

// Twice is not twice as many rows.
ok( 0 === sendbeam_mail_log_migrate(), 'migration: running it again finds nothing' );
ok( 2 === count( sb_log() ), 'migration: and does not duplicate what it already moved' );

// ── Reading ────────────────────────────────────────────────────────────
sb_log_reset();
sendbeam_mail_log_insert( 'ada@customer.test', 'Order #1001', 'sent', '', 'woocommerce', 1758700000 );
sendbeam_mail_log_insert( 'bob@customer.test', 'Password reset', 'failed', 'Monthly email limit reached', 'wp-core', 1758600000 );
sendbeam_mail_log_insert( 'cat@customer.test', 'New comment', 'fallback', 'has attachments', '', 1758500000 );
sendbeam_mail_log_insert( 'dan@customer.test', 'Receipt', 'sent', '', 'woocommerce', 1758400000 );

$sb_counts = sendbeam_mail_log_counts();
ok( 4 === $sb_counts['all'], 'views: the counts add up' );
ok( 2 === $sb_counts['sent'] && 1 === $sb_counts['failed'] && 1 === $sb_counts['fallback'], 'views: one per result' );

$sb_page = sendbeam_mail_log_query( array( 'per_page' => 2 ) );
ok( 4 === $sb_page['total'], 'query: the total counts every match, not the page' );
ok( 2 === count( $sb_page['rows'] ), 'query: and the page is the page' );
ok( 'ada@customer.test' === $sb_page['rows'][0]['to_addr'], 'query: newest first by default' );
$sb_page = sendbeam_mail_log_query( array( 'per_page' => 2, 'page' => 2 ) );
ok( 'cat@customer.test' === $sb_page['rows'][0]['to_addr'], 'query: the second page carries on where the first stopped' );

$sb_page = sendbeam_mail_log_query( array( 'view' => 'sent' ) );
ok( 2 === $sb_page['total'], 'query: a view narrows it to one result' );
$sb_page = sendbeam_mail_log_query( array( 'view' => 'nonsense' ) );
ok( 4 === $sb_page['total'], 'query: a view nobody defined is ignored rather than obeyed' );

$sb_page = sendbeam_mail_log_query( array( 'search' => 'password' ) );
ok( 1 === $sb_page['total'], 'search: the subject is searched' );
$sb_page = sendbeam_mail_log_query( array( 'search' => 'cat@' ) );
ok( 1 === $sb_page['total'], 'search: and the address' );
$sb_page = sendbeam_mail_log_query( array( 'search' => 'limit reached' ) );
ok( 1 === $sb_page['total'], 'search: and the reason it failed, which is what somebody actually looks for' );

// Neither of the two things that cannot be a placeholder comes from a
// request without passing through an allow-list first.
$sb_page = sendbeam_mail_log_query( array( 'orderby' => 'note; DROP TABLE wp_posts', 'order' => 'DESC' ) );
$sb_sql  = implode( ' ', $GLOBALS['wpdb']->queries );
lacks( $sb_sql, 'DROP TABLE wp_posts', 'query: an order column from the request cannot reach the statement' );
has( $sb_sql, 'FROM `wp_sendbeam_mail_log`', 'query: and the table name is backticked, since it cannot be a placeholder before WordPress 6.2' );
has( $sb_sql, 'ORDER BY sent_at', 'query: it falls back to the one column that is always safe' );
sendbeam_mail_log_query( array( 'orderby' => 'result', 'order' => 'asc' ) );
has( implode( ' ', $GLOBALS['wpdb']->queries ), 'ORDER BY result ASC', 'query: and an order it does recognise is used' );

ok( 4 === count( sendbeam_mail_log_recent( 5 ) ), 'recent: asks for five and gets the four there are' );
ok( 2 === count( sendbeam_mail_log_recent( 2 ) ), 'recent: and takes the limit seriously' );

// ── Deleting ───────────────────────────────────────────────────────────
$sb_ids = array_column( sb_log(), 'id' );
ok( 1 === sendbeam_mail_log_delete( array( $sb_ids[0] ) ), 'delete: one row goes' );
ok( 3 === count( sb_log() ), 'delete: and only that one' );
ok( 0 === sendbeam_mail_log_delete( array() ), 'delete: nothing ticked deletes nothing' );
ok( 3 === sendbeam_mail_log_clear(), 'clear: takes the rest' );
ok( 0 === count( sb_log() ), 'clear: and leaves an empty table' );

// ── Retention ──────────────────────────────────────────────────────────
sb_seed_settings( array() );
ok( 30 === sendbeam_mail_log_days(), 'retention: thirty days out of the box' );
foreach ( array( 7, 30, 90, 0 ) as $sb_days ) {
	sb_seed_settings( array( 'mail_log_days' => $sb_days ) );
	ok( $sb_days === sendbeam_mail_log_days(), "retention: $sb_days is a choice" );
}
sb_seed_settings( array( 'mail_log_days' => 4000 ) );
ok( 30 === sendbeam_mail_log_days(), 'retention: a number nobody offered falls back to thirty, not to for ever' );
$sb_clean = sendbeam_sanitize_settings( array( '_tab' => 'mail', 'mail_log_days' => '90' ) );
ok( 90 === $sb_clean['mail_log_days'], 'retention: the form saves a choice it recognises' );
$sb_clean = sendbeam_sanitize_settings( array( '_tab' => 'mail', 'mail_log_days' => '-1' ) );
ok( 30 === $sb_clean['mail_log_days'], 'retention: and refuses one it does not' );

// ── Pruning ────────────────────────────────────────────────────────────
sb_log_reset();
sb_seed_settings( array( 'mail_log_days' => 7 ) );
sendbeam_mail_log_insert( 'old@t.test', 'Old', 'sent', '', '', time() - ( 30 * DAY_IN_SECONDS ) );
sendbeam_mail_log_insert( 'new@t.test', 'New', 'sent', '', '', time() - HOUR_IN_SECONDS );
ok( 1 === sendbeam_mail_log_prune(), 'prune: a row past its date goes' );
ok( 1 === count( sb_log() ) && 'new@t.test' === sb_log()[0]['to_addr'], 'prune: and the one inside it stays' );

sb_seed_settings( array( 'mail_log_days' => 0 ) );
sendbeam_mail_log_insert( 'ancient@t.test', 'Ancient', 'sent', '', '', time() - ( 900 * DAY_IN_SECONDS ) );
ok( 0 === sendbeam_mail_log_prune(), 'prune: "for ever" keeps a nine-hundred-day-old row' );
ok( 2 === count( sb_log() ), 'prune: whatever its date' );

/*
 * The cap is the other half. "For ever" is a promise about time, not about
 * size, and a forgotten site on somebody else's hosting should not be able to
 * fill their disk with it.
 */
ok( 20000 === SENDBEAM_MAIL_LOG_MAX, 'prune: there is a ceiling regardless' );
sb_log_reset();
for ( $sb_n = 0; $sb_n < 12; $sb_n++ ) {
	sendbeam_mail_log_insert( "n{$sb_n}@t.test", 'X', 'sent', '', '', 1758000000 + $sb_n );
}
$GLOBALS['stub']['log_max'] = 10;
ok( 12 === count( sb_log() ), 'prune: twelve rows in' );
// With the constant at twenty thousand the cap cannot be reached in a test,
// so the statement it would issue is what gets checked.
$GLOBALS['wpdb']->queries = array();
sendbeam_mail_log_prune();
has( implode( ' ', $GLOBALS['wpdb']->queries ), 'SELECT COUNT(*) FROM `wp_sendbeam_mail_log`', 'prune: the cap is measured before it is applied' );

// ── The cron that runs it ──────────────────────────────────────────────
$GLOBALS['stub']['cron'] = array();
sendbeam_mail_log_schedule_prune();
$sb_cron = array_values( array_filter( $GLOBALS['stub']['cron'], function ( $c ) { return SENDBEAM_PRUNE_HOOK === $c['hook']; } ) );
ok( 1 === count( $sb_cron ), 'cron: the tidy-up is scheduled' );
ok( 'daily' === $sb_cron[0]['recurrence'], 'cron: once a day' );
sendbeam_mail_log_schedule_prune();
ok( 1 === count( array_filter( $GLOBALS['stub']['cron'], function ( $c ) { return SENDBEAM_PRUNE_HOOK === $c['hook']; } ) ), 'cron: scheduling twice does not schedule twice' );
sendbeam_on_deactivate();
ok( ! wp_next_scheduled( SENDBEAM_PRUNE_HOOK ), 'cron: deactivating clears it, so a switched-off plugin does no work' );
sendbeam_on_activate();
ok( wp_next_scheduled( SENDBEAM_PRUNE_HOOK ), 'cron: and activating puts it back' );

// ── Clear the log ──────────────────────────────────────────────────────
sb_log_reset();
sendbeam_mail_log_insert( 'a@t.test', 'A', 'sent', '', '', 1758000001 );
sendbeam_mail_log_insert( 'b@t.test', 'B', 'sent', '', '', 1758000002 );
$GLOBALS['stub']['caps']['manage_options'] = true;
$_REQUEST                                  = array( '_wpnonce' => 'nonce:sendbeam_clear_log' );
unset( $GLOBALS['stub']['redirect'] );
try {
	sendbeam_handle_clear_log();
} catch ( SendBeamStubExit $e ) {
	unset( $e );
}
$_REQUEST = array();
ok( 0 === count( sb_log() ), 'clear the log: the button empties it' );
has( $GLOBALS['stub']['redirect']['url'], 'sendbeam_log_cleared=2', 'clear the log: and says how many went' );

// Without the nonce, or without the capability, nothing goes.
sendbeam_mail_log_insert( 'c@t.test', 'C', 'sent', '', '', 1758000003 );
$threw = false;
try {
	sendbeam_handle_clear_log();
} catch ( SendBeamStubExit $e ) {
	$threw = true;
}
ok( $threw, 'clear the log: refused without a nonce' );
ok( 1 === count( sb_log() ), 'clear the log: and nothing was deleted' );

$GLOBALS['stub']['caps']['manage_options'] = false;
$_REQUEST                                  = array( '_wpnonce' => 'nonce:sendbeam_clear_log' );
$threw                                     = false;
try {
	sendbeam_handle_clear_log();
} catch ( SendBeamStubExit $e ) {
	$threw = true;
}
ok( $threw, 'clear the log: refused without the capability' );
ok( 1 === count( sb_log() ), 'clear the log: and still nothing was deleted' );
$GLOBALS['stub']['caps']['manage_options'] = true;
$_REQUEST                                  = array();

// It is a button on the screen, behind a closed confirmation, and NOT a
// bulk action sitting one mis-click from "Delete".
ob_start();
sendbeam_mail_log_clear_action();
$sb_clear = ob_get_clean();
has( $sb_clear, '<details class="sb-confirm">', 'clear the log: behind a confirmation that renders nothing until it is pressed' );
has( $sb_clear, 'Clear the log', 'clear the log: which is what the button says' );
has( $sb_clear, 'value="sendbeam_clear_log"', 'clear the log: posting to its own handler' );
has( $sb_clear, 'nonce:sendbeam_clear_log', 'clear the log: with a nonce' );
$sb_bulk = ( new SendBeam_Mail_Log_Table() );
ob_start();
$sb_bulk->prepare_items();
$sb_bulk->display();
$sb_bulk_html = ob_get_clean();
lacks( $sb_bulk_html, 'Delete all', 'clear the log: and never as a bulk action beside Delete' );

// ── The views, on the screen ───────────────────────────────────────────
sb_log_reset();
sendbeam_mail_log_insert( 'a@t.test', 'A', 'sent', '', '', 1758000001 );
sendbeam_mail_log_insert( 'b@t.test', 'B', 'failed', 'nope', '', 1758000002 );
$sb_views = new SendBeam_Mail_Log_Table();
$sb_views->prepare_items();
ob_start();
$sb_views->views();
$sb_views_html = ob_get_clean();
has( $sb_views_html, 'All <span class="count">(2)</span>', 'views: All, with a count' );
has( $sb_views_html, 'Sent <span class="count">(1)</span>', 'views: Sent, with a count' );
has( $sb_views_html, 'Failed <span class="count">(1)</span>', 'views: Failed, with a count' );
lacks( $sb_views_html, 'Server mailer', 'views: and a view nothing landed in is not a link to an empty screen' );

// ── Uninstall ──────────────────────────────────────────────────────────
$sb_uninstall = file_get_contents( dirname( __DIR__ ) . '/uninstall.php' );
has( $sb_uninstall, 'DROP TABLE IF EXISTS', 'uninstall: the table is dropped' );
has( $sb_uninstall, "\$wpdb->prefix . 'sendbeam_mail_log'", 'uninstall: the one this site made' );
has( $sb_uninstall, "str_replace( '`', '``'", 'uninstall: with the name backtick-escaped, since a DROP cannot take a placeholder' );
has( $sb_uninstall, "'sendbeam_db_version'", 'uninstall: and the version that says it exists' );
has( $sb_uninstall, "'sendbeam_mail_log'", 'uninstall: with the old option swept too, for a site that never upgraded past it' );

sb_log_reset();
sb_seed_settings( array() );

// ── #1 Two Save buttons on one tab, two field groups ────────────────────
// The Forms screen has a Defaults card and an Appearance card, each its own
// <form>. While both posted `_tab=forms` the sanitiser read the absent card's
// fields as cleared, so pressing Save on Appearance emptied contact_form and
// took the contact form off every published page that used [sendbeam_contact].
sb_seed_settings(
	sendbeam_sanitize_settings(
		array(
			'_tab'         => 'forms',
			'default_form' => '9da94d34-86f8-4fbc-9333-45c0b4c16d6a',
			'contact_form' => '14dad6b8-6998-4f1f-8924-3ebd79524c94',
		)
	)
);
sb_seed_settings(
	sendbeam_sanitize_settings(
		array(
			'_tab'          => 'forms_style',
			'style_accent'  => '#a8452a',
			'style_radius'  => '4',
			'style_size'    => '15',
			'style_bare'    => '1',
		)
	)
);
$sb_before = sendbeam_settings();
ok( '#a8452a' === $sb_before['style_accent'] && '14dad6b8-6998-4f1f-8924-3ebd79524c94' === $sb_before['contact_form'], 'forms: both cards are saved to begin with' );

// Press Save on Defaults, having typed nothing. Appearance is not in $_POST.
$sb_after = sendbeam_sanitize_settings( array( '_tab' => 'forms', 'default_form' => $sb_before['default_form'], 'contact_form' => $sb_before['contact_form'] ) );
ok( '#a8452a' === $sb_after['style_accent'], 'forms: saving Defaults leaves the accent colour alone' );
ok( '4' === $sb_after['style_radius'] && '15' === $sb_after['style_size'], 'forms: saving Defaults leaves radius and text size alone' );
ok( 1 === (int) $sb_after['style_bare'], 'forms: saving Defaults does not untick "hide the form name"' );

// Press Save on Appearance, having typed nothing. The form IDs are not in $_POST.
$sb_after = sendbeam_sanitize_settings( array( '_tab' => 'forms_style', 'style_accent' => '#a8452a', 'style_radius' => '4', 'style_size' => '15', 'style_bare' => '1' ) );
ok( '14dad6b8-6998-4f1f-8924-3ebd79524c94' === $sb_after['contact_form'], 'forms: saving Appearance does not empty the contact form — [sendbeam_contact] renders nothing without it' );
ok( '9da94d34-86f8-4fbc-9333-45c0b4c16d6a' === $sb_after['default_form'], 'forms: saving Appearance does not empty the default form' );

// And the guarantee that made the grouping exist in the first place still holds.
$sb_after = sendbeam_sanitize_settings( array( '_tab' => 'forms_style', 'style_accent' => '#a8452a' ) );
ok( $sb_before['api_key'] === $sb_after['api_key'], 'forms: saving Appearance does not touch the API key' );
sb_seed_settings( array() );

// ── #3 The test email must describe the message it is ──────────────────
// The card said "SendBeam refuses messages from it" in a message SendBeam had
// just delivered, gave the From address the site asked for rather than the one
// in the headers, and named the site's own domain as the signer when the DKIM
// signature was SendBeam's. Everything in it came from what the site wanted
// and nothing from what happened.
sb_seed_settings( array( 'api_key' => 'sb_live_substitutedxxxxxxx', 'mail_enabled' => 1, 'mail_from_email' => 'hello@not-verified.test' ) );
sendbeam_connect_cache_status(
	sendbeam_connect_normalise_status(
		array(
			'workspace' => array( 'id' => 'w1', 'name' => 'Harbour Lane' ),
			'sender'    => array( 'from_name' => 'Harbour Lane', 'from_email' => 'ws-db645350@mail.sendbeam.io' ),
			'domain'    => array( 'name' => 'harbourlane.co.uk', 'verified' => true, 'records' => array() ),
		)
	)
);
$sb_s = sendbeam_effective_sender();
ok( 'other' === $sb_s['kind'], 'sender: an address on an unverified domain is still classified as other' );
ok( true === $sb_s['substituted'], 'sender: and the plugin knows SendBeam will not use it' );
ok( 'ws-db645350@mail.sendbeam.io' === $sb_s['used'], 'sender: the workspace sender is the address that actually goes out' );
ok( 'mail.sendbeam.io' === $sb_s['signed'], 'sender: and its host is what signs the message, not the one asked for' );
ok( 'not-verified.test' !== $sb_s['signed'], 'sender: never the domain that took no part in the transaction' );

$sb_rows = sendbeam_mail_facts();
ok( 'mail.sendbeam.io' === $sb_rows['Signed by'], 'test email: the Signed by row is the real signer' );
has( $sb_rows['From address'], 'SendBeam sent this as ws-db645350@mail.sendbeam.io', 'test email: the From row names the substitution' );

$sb_next = sendbeam_test_mail_next_step( $sb_s );
has( $sb_next['text'], 'sent this under its own address', 'test email: the next step describes the substitution' );
lacks( $sb_next['text'], 'refuses', 'test email: and does not claim a refusal' );

// The address the site asked for IS used once its domain is verified, and
// then its own domain is the signer.
sb_seed_settings( array( 'api_key' => 'sb_live_substitutedxxxxxxx', 'mail_enabled' => 1, 'mail_from_email' => 'hello@harbourlane.co.uk' ) );
$sb_s = sendbeam_effective_sender();
ok( false === $sb_s['substituted'], 'sender: a verified domain is not substituted' );
ok( 'hello@harbourlane.co.uk' === $sb_s['used'] && 'harbourlane.co.uk' === $sb_s['signed'], 'sender: and it signs its own mail' );

// A verified domain the site is not using yet: the shared address goes out
// as asked, so the shared host is the signer and nothing is substituted.
sb_seed_settings( array( 'api_key' => 'sb_live_substitutedxxxxxxx', 'mail_enabled' => 1, 'mail_from_email' => 'ws-db645350@mail.sendbeam.io' ) );
$sb_s = sendbeam_effective_sender();
ok( 'shared' === $sb_s['kind'] && false === $sb_s['substituted'], 'sender: the shared address is used as asked' );
ok( 'mail.sendbeam.io' === $sb_s['signed'], 'sender: and signs as the shared host' );

// The domain is NOT verified, so even an address on it is substituted.
sendbeam_connect_cache_status(
	sendbeam_connect_normalise_status(
		array(
			'workspace' => array( 'id' => 'w1', 'name' => 'Harbour Lane' ),
			'sender'    => array( 'from_name' => 'Harbour Lane', 'from_email' => 'ws-db645350@mail.sendbeam.io' ),
			'domain'    => array( 'name' => 'harbourlane.co.uk', 'verified' => false, 'records' => array() ),
		)
	)
);
sb_seed_settings( array( 'api_key' => 'sb_live_substitutedxxxxxxx', 'mail_enabled' => 1, 'mail_from_email' => 'hello@harbourlane.co.uk' ) );
$sb_s = sendbeam_effective_sender();
ok( 'own_domain' === $sb_s['kind'], 'sender: an address on the site\'s own unverified domain is still its own' );
ok( true === $sb_s['substituted'] && 'mail.sendbeam.io' === $sb_s['signed'], 'sender: but nothing signs for a domain DNS has not proved' );
sb_seed_settings( array() );

// ── #4 "SendBeam could not be reached" when SendBeam answered ───────────
// domain_state 'unavailable' means two opposite things: the plugin could not
// reach SendBeam, and SendBeam answered saying it could not create the domain.
// Straight after a successful Connect the cached status held ok:true and
// "wp-test.sendbeam.io could not be added as a sending domain: That domain is
// already registered." in two places, and step 2 printed a different, false
// reason instead.
sb_seed_settings( array( 'api_key' => 'sb_live_unavailablexxxxxxxx' ) );
$sb_live = sendbeam_connect_normalise_status(
	array(
		'workspace'    => array( 'id' => 'w1', 'name' => 'E2E UI' ),
		'domain'       => null,
		'domain_state' => 'unavailable',
		'domain_note'  => 'wp-test.sendbeam.io could not be added as a sending domain: That domain is already registered.',
	)
);
ok( sendbeam_connect_status_is_live( $sb_live ), 'status: an answer SendBeam gave is live' );
sendbeam_connect_cache_status( $sb_live );
$sb_step = sendbeam_setup_steps()[1];
has( $sb_step['detail'], 'That domain is already registered', 'step 2: prints the reason SendBeam gave' );
lacks( $sb_step['detail'], 'could not be reached', 'step 2: and does not claim SendBeam was unreachable' );

// The other reading of the same word still says the right thing.
$sb_dead          = sendbeam_connect_empty_status();
$sb_dead['stale'] = true;
$sb_dead['ok']    = true;
$sb_dead['error'] = 'cURL error 28: Operation timed out';
$sb_dead['domain_state'] = 'unavailable';
$sb_dead['domain_note']  = 'wp-test.sendbeam.io could not be added as a sending domain: That domain is already registered.';
ok( ! sendbeam_connect_status_is_live( $sb_dead ), 'status: a stale copy served after a failed request is not live' );
sendbeam_connect_cache_status( $sb_dead );
$sb_step = sendbeam_setup_steps()[1];
has( $sb_step['detail'], 'could not be reached', 'step 2: a failed request still says so' );
lacks( $sb_step['detail'], 'already registered', 'step 2: and does not pass off a stale note as this answer' );
sb_seed_settings( array() );

// ── #7 Copy on a contact row handed you a different form ────────────────
// Every contact row offered a bare [sendbeam_contact], which renders
// $settings['contact_form'] and nothing else. The Placed on column said "Not
// a default — place it by shortcode", and the shortcode it gave could not
// carry out that instruction for any contact form but the chosen default.
$sb_wholesale = 'aaaaaaaa-1111-2222-3333-444444444444';
$sb_default   = '14dad6b8-6998-4f1f-8924-3ebd79524c94';
sb_seed_settings( array_merge( sendbeam_settings(), array( 'contact_form' => $sb_default ) ) );

ok( false !== strpos( do_shortcode_tag( 'sendbeam_contact' ), '/f/' . $sb_default ), 'contact: a bare shortcode still renders the chosen default' );
ok( false !== strpos( do_shortcode_tag( 'sendbeam_contact', array( 'id' => $sb_wholesale ) ), '/f/' . $sb_wholesale ), 'contact: and a named one renders the form it names' );
ok( false === strpos( do_shortcode_tag( 'sendbeam_contact', array( 'id' => $sb_wholesale ) ), '/f/' . $sb_default ), 'contact: not the default instead of it' );
has( do_shortcode_tag( 'sendbeam_contact', array( 'id' => $sb_wholesale ) ), 'sendbeam-contact-wrap', 'contact: still the contact wrapper, so existing styling holds' );

// An id that is not a form id embeds nothing at all, rather than quietly
// falling back to the default and placing the wrong form.
lacks( do_shortcode_tag( 'sendbeam_contact', array( 'id' => 'not-an-id' ) ), '/f/', 'contact: a nonsense id embeds no form at all' );
sb_seed_settings( array() );

// ── #11 "Remove the saved key" left a Connect key live in the workspace ─
// The Overview's Disconnect revokes a Connect-issued key at SendBeam. The
// Advanced tab's checkbox is a second, quieter door to the same place, and it
// cleared the option and stopped — leaving the owner who tidied up there with
// a live key for a site that no longer exists as far as they are concerned.
sb_connect_reset();
sb_seed_settings(
	array(
		'api_key'                    => SENDBEAM_API_KEY,
		'sendbeam_connected_via'     => 'connect',
		'sendbeam_connect_workspace' => 'Harbour Lane',
	)
);
$GLOBALS['stub']['remote_reply'] = array( 'response' => array( 'code' => 204 ), 'body' => '' );
$sb_removed = sendbeam_sanitize_settings( array( '_tab' => 'connect', 'api_key' => '', 'api_key_remove' => '1' ) );
ok( '' === $sb_removed['api_key'], 'advanced: the key is removed, as it always was' );
ok( 1 === count( $GLOBALS['stub']['remote'] ), 'advanced: and SendBeam is asked to revoke it' );
ok( 'https://sendbeam.io/api/v1/connect/disconnect' === $GLOBALS['stub']['remote'][0]['url'], 'advanced: through the same endpoint Disconnect uses' );
ok( SENDBEAM_API_KEY === $GLOBALS['stub']['remote'][0]['args']['headers']['x-api-key'], 'advanced: the key revokes itself' );
ok( '' === $sb_removed['sendbeam_connected_via'], 'advanced: and the site stops claiming how it was connected' );

// A pasted key was minted for whatever it was minted for and may be in use
// elsewhere. It is forgotten here and left alone there, as Disconnect does.
sb_connect_reset();
sb_seed_settings( array( 'api_key' => 'sb_live_pastedpastedpasted', 'sendbeam_connected_via' => '' ) );
$sb_removed = sendbeam_sanitize_settings( array( '_tab' => 'connect', 'api_key' => '', 'api_key_remove' => '1' ) );
ok( '' === $sb_removed['api_key'], 'advanced: a pasted key is forgotten too' );
ok( empty( $GLOBALS['stub']['remote'] ), 'advanced: but nothing revokes a key this site did not issue' );

// And the field says which of the two will happen, before it happens.
sb_seed_settings( array( 'api_key' => SENDBEAM_API_KEY, 'sendbeam_connected_via' => 'connect' ) );
has( sendbeam_key_removal_note(), 'also revokes it in SendBeam', 'advanced: the field says a Connect key will be revoked' );
sb_seed_settings( array( 'api_key' => 'sb_live_pastedpastedpasted', 'sendbeam_connected_via' => '' ) );
has( sendbeam_key_removal_note(), 'only forgotten here', 'advanced: and that a pasted key is only forgotten' );
sb_connect_reset();
sb_seed_settings( array() );

// ── #12 Reconnect destroyed the sending domain the site already had ─────
// The consent page prefilled SEND FROM from site_url on every run, so pressing
// Reconnect for any other reason — more permissions, a changed workspace, a
// key rotation — re-set the sending domain to this site's host. The domain the
// owner chose, and the three CNAMEs they may have been half-way through
// entering, simply vanished from the workspace with no warning.
sb_connect_reset();
sb_seed_settings(
	array(
		'api_key'                  => SENDBEAM_API_KEY,
		'sendbeam_connected_via'   => 'connect',
		'sendbeam_connect_granted' => 'forms,domain',
	)
);
sendbeam_connect_cache_status(
	sendbeam_connect_normalise_status(
		array(
			'workspace' => array( 'id' => 'w1', 'name' => 'Harbour Lane' ),
			'domain'    => array( 'name' => 'mail.wp-test.sendbeam.io', 'verified' => false, 'records' => array() ),
		)
	)
);
ok( 'mail.wp-test.sendbeam.io' === sendbeam_connect_current_sending_host(), 'reconnect: the site knows the domain it already sends from' );

/** The consent URL one of the three links produces. */
function sb_consent_for( $url ) {
	parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
	$keep = empty( $q['sendbeam_new_domain'] );
	parse_str(
		(string) wp_parse_url(
			sendbeam_connect_consent_url( array( 'forms' ), 'statestatestate', $keep ? sendbeam_connect_current_sending_host() : '' ),
			PHP_URL_QUERY
		),
		$c
	);
	return $c;
}

// 1. The connected card's plain Reconnect.
$sb_c = sb_consent_for( sendbeam_connect_start_url() );
ok( 'mail.wp-test.sendbeam.io' === rawurldecode( $sb_c['sending_host'] ), 'reconnect: a plain Reconnect offers the existing domain back' );

// 2. "Reconnect with more permissions".
$sb_c = sb_consent_for( sendbeam_connect_start_url( true ) );
ok( 'mail.wp-test.sendbeam.io' === rawurldecode( $sb_c['sending_host'] ), 'reconnect: so does reconnecting for more permissions' );

// 3. "Sending from the wrong domain? Reconnect and enter the one you want."
$sb_wrong = sendbeam_connect_start_url( false, false );
has( $sb_wrong, 'sendbeam_new_domain=1', 'reconnect: the wrong-domain link says it wants a different one' );
$sb_c = sb_consent_for( $sb_wrong );
ok( ! isset( $sb_c['sending_host'] ), 'reconnect: and passes no domain, because choosing another is the point' );

// A first connect has no domain to offer, so it offers none.
sb_connect_reset();
sb_seed_settings( array( 'api_key' => '' ) );
sendbeam_connect_cache_status( sendbeam_connect_empty_status() );
ok( '' === sendbeam_connect_current_sending_host(), 'reconnect: a site with no key has no domain to keep' );
$sb_c = sb_consent_for( sendbeam_connect_start_url() );
ok( ! isset( $sb_c['sending_host'] ), 'reconnect: so a first connect sends no sending_host at all' );

// Only a hostname ever goes out.
ok( '' === sendbeam_connect_clean_host( 'not a host' ), 'reconnect: a value that is not a hostname is dropped' );
ok( '' === sendbeam_connect_clean_host( 'https://example.com/path' ), 'reconnect: a URL is not a hostname' );
ok( '' === sendbeam_connect_clean_host( str_repeat( 'a', 250 ) . '.example.com' ), 'reconnect: and nothing over 253 characters' );
ok( 'mail.example.co.uk' === sendbeam_connect_clean_host( ' MAIL.Example.CO.UK ' ), 'reconnect: a real host is trimmed and lowercased' );
sb_connect_reset();
sb_seed_settings( array() );

// ── #13 The longest DNS host was clipped on desktop ─────────────────────
// The Host column is 30% — about 282px at 1280 — and a
// `resend._domainkey.mail.<domain>` host is longer than that. The wrapping
// rule lived only inside the <=782px media query, so the phone case that had
// been complained about was fixed and the desktop one was not: the value was
// cut off inside the very field the owner is told to read from.
$sb_css = sendbeam_admin_css();
$sb_field_rule = substr( $sb_css, strpos( $sb_css, '.sendbeam-app .sb-copy-field{' ) );
$sb_field_rule = substr( $sb_field_rule, 0, strpos( $sb_field_rule, '}' ) );
has( $sb_field_rule, 'white-space:pre-wrap', 'records: the copy field wraps at every width, not only on a phone' );
has( $sb_field_rule, 'word-break:break-all', 'records: and breaks a host that has nowhere else to break' );
lacks( $sb_field_rule, 'white-space:pre;', 'records: nothing pins it to one line' );
has( $sb_field_rule, 'overflow:auto', 'records: with no script it scrolls rather than hiding the rest' );

// The media query must not be the only place wrapping is switched on again.
$sb_phone = substr( $sb_css, strpos( $sb_css, '@media (max-width:782px)' ) );
lacks( $sb_phone, '.sb-dns .sb-copy-field{white-space:pre-wrap', 'records: the phone override is gone, because it is the default now' );

// And a real host still renders whole into the field.
$sb_long = 'resend._domainkey.mail.wp-test.sendbeam.io';
has( sendbeam_copy_field( $sb_long, 'Host', 'sb-h1' ), '>' . $sb_long . '</textarea>', 'records: the whole host is in the field' );
has( sendbeam_copy_field( $sb_long, 'Host', 'sb-h1' ), 'data-copy="' . $sb_long . '"', 'records: and the whole host is what gets copied' );

// ── #14 Check now threw you to the Overview ─────────────────────────────
// The check is offered on the Overview and on Settings → Sending domain, and
// always landed on the Overview. Somebody copying records on the Sending tab
// was moved to another screen to be told the result and had to navigate back
// to carry on with the records they were half-way through.
sb_connect_reset();
sb_seed_settings( array( 'api_key' => SENDBEAM_API_KEY ) );
$GLOBALS['stub']['remote_reply'] = array( 'response' => array( 'code' => 200 ), 'body' => sb_status_body( false ) );
$_POST    = array( '_wpnonce' => 'nonce:sendbeam_domain_check', 'sendbeam_back' => 'sendbeam-settings' );
$_REQUEST = $_POST;
$sb_url   = sb_run_admin_post( 'sendbeam_handle_domain_check' );
has( $sb_url, 'page=sendbeam-settings', 'check: pressed on the Sending tab, it comes back to the Sending tab' );
has( $sb_url, 'tab=domain', 'check: on the records themselves' );
has( $sb_url, 'sendbeam_domain=', 'check: still carrying the result' );

// Pressed on the Overview, it stays on the Overview.
$GLOBALS['stub']['remote_reply'] = array( 'response' => array( 'code' => 200 ), 'body' => sb_status_body( false ) );
$_POST    = array( '_wpnonce' => 'nonce:sendbeam_domain_check', 'sendbeam_back' => 'sendbeam' );
$_REQUEST = $_POST;
$sb_url   = sb_run_admin_post( 'sendbeam_handle_domain_check' );
has( $sb_url, 'page=sendbeam&', 'check: pressed on the Overview, it stays there' );

// Anything that is not one of this plugin's pages goes to the Overview.
$GLOBALS['stub']['remote_reply'] = array( 'response' => array( 'code' => 200 ), 'body' => sb_status_body( false ) );
$_POST    = array( '_wpnonce' => 'nonce:sendbeam_domain_check', 'sendbeam_back' => 'https://evil.test/' );
$_REQUEST = $_POST;
$sb_url   = sb_run_admin_post( 'sendbeam_handle_domain_check' );
lacks( $sb_url, 'evil.test', 'check: a return value that is not one of our pages is ignored' );
has( $sb_url, 'page=sendbeam&', 'check: and falls back to the Overview' );
$_POST    = array();
$_REQUEST = array();
sb_connect_reset();
sb_seed_settings( array() );

// ── #15 The page about the sending domain said less than the checklist ──
// The Overview's step 2 gave SendBeam's own reason; Settings → Sending domain
// dropped it and said only "Add one under Settings → Domains in SendBeam" —
// on the screen dedicated to the subject. The Overview also named two
// different SendBeam screens in consecutive sentences, because its own
// instruction was appended after SendBeam's.
sb_connect_reset();
sb_seed_settings( array( 'api_key' => SENDBEAM_API_KEY ) );
sendbeam_connect_cache_status(
	sendbeam_connect_normalise_status(
		array(
			'workspace'    => array( 'id' => 'w1', 'name' => 'Harbour Lane' ),
			'domain'       => null,
			'domain_state' => 'not_found',
			'domain_note'  => "This site's sending domain is no longer in the workspace. Reconnect the site, or add the domain again under Settings → Sending.",
		)
	)
);
$sb_status = sendbeam_connect_status();

$sb_sentence = sendbeam_domain_missing_sentence( $sb_status );
has( $sb_sentence, 'no longer in the workspace', 'domain: the sentence carries the reason SendBeam gave' );
lacks( $sb_sentence, 'Settings → Domains', 'domain: and does not name a second SendBeam screen after SendBeam named one' );

ob_start();
sendbeam_domain_panel( $sb_status, true );
$sb_panel = ob_get_clean();
has( $sb_panel, 'no longer in the workspace', 'domain: the Sending domain screen says as much as the checklist does' );

// With no note, the plugin's own instruction is the only one there is.
sendbeam_connect_cache_status(
	sendbeam_connect_normalise_status(
		array(
			'workspace'    => array( 'id' => 'w1', 'name' => 'Harbour Lane' ),
			'domain'       => null,
			'domain_state' => 'not_found',
		)
	)
);
$sb_sentence = sendbeam_domain_missing_sentence( sendbeam_connect_status() );
has( $sb_sentence, 'Add one under Settings → Domains in SendBeam', 'domain: with nothing from SendBeam, the plugin says where to go' );
sb_connect_reset();
sb_seed_settings( array() );

// ── #16 Every permission refusal was served as HTTP 500 ─────────────────
// wp_die() with no status argument defaults to 500, so every one of this
// plugin's admin-post actions answered an Editor's POST with a server error.
// The refusal itself was right; the status line put a 500 in every access log
// and every uptime monitor watching status codes. The nonce refusals were
// already correct, because check_admin_referer() answers 403.
//
// The list is taken from the hooks the plugin actually registered, so an
// action added later cannot quietly miss this.
$sb_actions = array();
foreach ( $GLOBALS['stub']['callbacks'] as $sb_cb ) {
	if ( 0 === strpos( $sb_cb[0], 'admin_post_sendbeam_' ) ) {
		$sb_actions[ $sb_cb[0] ] = $sb_cb[1];
	}
}
ok( count( $sb_actions ) >= 16, 'refusal: there are ' . count( $sb_actions ) . ' admin-post actions to check' );

$GLOBALS['stub']['caps']['manage_options'] = false;
foreach ( $sb_actions as $sb_hook => $sb_fn ) {
	$GLOBALS['stub']['died'] = null;
	$_POST                   = array();
	$_REQUEST                = array();
	try {
		call_user_func( $sb_fn );
	} catch ( SendBeamStubExit $e ) {
		unset( $e ); // Expected: each of these refuses before doing anything.
	}
	$sb_died = $GLOBALS['stub']['died'];
	ok( is_array( $sb_died ), "refusal: $sb_hook refuses a caller without the capability" );
	has( $sb_died['message'], 'do not have permission', "refusal: $sb_hook says why" );
	ok( 403 === $sb_died['status'], "refusal: $sb_hook answers 403, not a server error" );
}
$GLOBALS['stub']['caps']['manage_options'] = true;
$GLOBALS['stub']['died']                   = null;
$_POST                                     = array();
$_REQUEST                                  = array();

// And no permission refusal anywhere in the plugin is left on the default —
// the bulk-action guard on the email log table is not an admin-post action
// and would not be reached by the loop above.
$sb_bad = array();
foreach ( glob( dirname( __DIR__ ) . '/includes/*.php' ) as $sb_file ) {
	if ( false !== strpos( file_get_contents( $sb_file ), "wp_die( esc_html__( 'You do not have permission to do that.', 'sendbeam' ) );" ) ) {
		$sb_bad[] = basename( $sb_file );
	}
}
ok( array() === $sb_bad, 'refusal: no wp_die() is left on the 500 default — ' . implode( ', ', $sb_bad ) );

// ── #17 Phone tap targets were 26-36px ──────────────────────────────────
// Apple asks for 44pt and Android for 48dp. Every button on every SendBeam
// screen measured 26-36px at 393px, and the 26px "I already have an API key"
// disclosure on the Overview is the one an owner reaches for first.
$sb_css   = sendbeam_admin_css();
$sb_phone = substr( $sb_css, strpos( $sb_css, '@media (max-width:782px)' ) );
has( $sb_phone, '.sendbeam-app .sb-btn{min-height:44px', 'touch: buttons are 44px on a phone' );
has( $sb_phone, '.sendbeam-app .sb-btn--small{min-height:44px', 'touch: and the small ones grow to the same 44px' );
has( $sb_phone, '.sb-paste>summary{padding:12px 0;min-height:44px', 'touch: so does the paste-a-key disclosure' );
has( $sb_phone, '.sendbeam-app .sb-link{min-height:44px', 'touch: and a link that acts as a button' );

// The desktop metrics are untouched: this is a phone problem.
$sb_desk = substr( $sb_css, 0, strpos( $sb_css, '@media (max-width:782px)' ) );
has( $sb_desk, '.sendbeam-app .sb-btn{display:inline-flex', 'touch: the desktop button rule is still there' );
has( $sb_desk, 'min-height:36px', 'touch: at its original 36px' );

// ── #18 "Not installed" about plugins that are installed ────────────────
// The probe is a class or function check, which is the right probe; it
// answers "is this running". A plugin sitting in wp-content/plugins waiting
// to be activated is installed, and somebody who has just installed one and
// is wondering why nothing changed is exactly the person reading this row.
ob_start();
sendbeam_screen_form_plugins( array() );
$sb_plugins = ob_get_clean();
has( $sb_plugins, 'Contact Form 7', 'bridges: the table lists every plugin it supports' );
lacks( $sb_plugins, 'Not installed', 'bridges: it never says "Not installed", which the probe cannot know' );

// The probe answers "is this running", so that is the word, wherever it is
// rendered. Asserted against the source because every bridge class exists in
// this process, so every row renders Active here.
$sb_bridges_src = file_get_contents( dirname( __DIR__ ) . '/includes/screens.php' );
has( $sb_bridges_src, "esc_html__( 'Not active', 'sendbeam' )", 'bridges: the label a row gets when the plugin is not running is "Not active"' );
$sb_all_src = '';
foreach ( glob( dirname( __DIR__ ) . '/includes/*.php' ) as $sb_file ) {
	$sb_all_src .= file_get_contents( $sb_file );
}
lacks( $sb_all_src, "'Not installed'", 'bridges: and "Not installed" is said nowhere in the plugin' );

// ── #19 The permissions list named the site's host, not the domain ──────
// The domain scope's label names the host the consent page was asked about.
// On a consent page that is right; on a connected site it describes the
// request rather than the outcome, so a site connected with SEND FROM set to
// mail.wp-test.sendbeam.io read "Set up this site's sending domain
// (wp-test.sendbeam.io) in SendBeam" ever after.
sb_connect_reset();
sb_seed_settings(
	array(
		'api_key'                  => SENDBEAM_API_KEY,
		'sendbeam_connected_via'   => 'connect',
		'sendbeam_connect_granted' => 'forms,domain',
	)
);
sendbeam_connect_cache_status(
	sendbeam_connect_normalise_status(
		array(
			'workspace' => array( 'id' => 'w1', 'name' => 'Harbour Lane' ),
			'domain'    => array( 'name' => 'mail.wp-test.sendbeam.io', 'verified' => false, 'records' => array() ),
		)
	)
);
$sb_granted = implode( ' | ', sendbeam_connect_granted_list() );
has( $sb_granted, 'mail.wp-test.sendbeam.io', 'permissions: the list names the domain that was actually set up' );
lacks( $sb_granted, '(www.example-site.test)', 'permissions: not the host the request was about' );

// With no domain there is nothing better to say, so the scope's own label
// stands — it is still true, and an empty parenthesis would not be.
sendbeam_connect_cache_status(
	sendbeam_connect_normalise_status(
		array(
			'workspace' => array( 'id' => 'w1', 'name' => 'Harbour Lane' ),
			'domain'    => null,
		)
	)
);
has( implode( ' | ', sendbeam_connect_granted_list() ), "Set up this site's sending domain", 'permissions: a site with no domain keeps the original wording' );
sb_connect_reset();
sb_seed_settings( array() );

// ── The hiding rule has to beat every layout rule in the file ───────────
// .sendbeam-app .is-hidden is (0,2,0) and the Settings API reset
// `.sendbeam-app .form-table tr` is (0,2,1), so the From name, From address
// and fallback rows on Site email were drawn whether the switch was on or
// off — the exact thing the server-rendered pattern exists to prevent. This
// parses the stylesheet rather than looking for one string, so the next rule
// that out-specifies it fails here instead of on a screen.
$sb_css = sendbeam_admin_css();

/**
 * Every declaration block in the stylesheet, with its selectors.
 *
 * Media queries are flattened: a rule inside one still competes with a rule
 * outside it, because a media query adds nothing to specificity.
 *
 * @param string $css The stylesheet.
 * @return array<int,array{selectors:string[],body:string}>
 */
function sb_css_rules( $css ) {
	$css   = preg_replace( '!/\*.*?\*/!s', '', $css );
	$css   = preg_replace( '/@media[^{]*\{/', '', $css );
	$rules = array();
	if ( preg_match_all( '/([^{}]+)\{([^{}]*)\}/', $css, $m, PREG_SET_ORDER ) ) {
		foreach ( $m as $one ) {
			$sel = trim( $one[1] );
			if ( '' === $sel || 0 === strpos( $sel, '@' ) ) {
				continue;
			}
			$rules[] = array(
				'selectors' => array_map( 'trim', explode( ',', $sel ) ),
				'body'      => $one[2],
			);
		}
	}
	return $rules;
}

/**
 * CSS specificity as one comparable number: ids, classes, elements.
 *
 * @param string $selector One selector.
 * @return int
 */
function sb_specificity( $selector ) {
	$s = preg_replace( '/::?[a-z-]+(\([^)]*\))?/', ' PSEUDO ', $selector );
	$ids     = preg_match_all( '/#[A-Za-z0-9_-]+/', $s );
	$classes = preg_match_all( '/\.[A-Za-z0-9_-]+/', $s ) + preg_match_all( '/\[[^\]]+\]/', $s );
	$bare    = preg_replace( '/[#.][A-Za-z0-9_-]+|\[[^\]]+\]|PSEUDO/', ' ', $s );
	$els     = preg_match_all( '/\b[a-z][a-z0-9]*\b/', $bare );
	return $ids * 10000 + $classes * 100 + $els;
}

$sb_rules  = sb_css_rules( $sb_css );
$sb_hiders = array();
foreach ( $sb_rules as $rule ) {
	foreach ( $rule['selectors'] as $sel ) {
		if ( false !== strpos( $sel, '.is-hidden' ) && preg_match( '/display\s*:\s*none/i', $rule['body'] ) ) {
			$sb_hiders[] = array(
				'selector'  => $sel,
				'spec'      => sb_specificity( $sel ),
				'important' => (bool) preg_match( '/display\s*:\s*none\s*!important/i', $rule['body'] ),
			);
		}
	}
}
ok( $sb_hiders, 'hiding: there is a rule that hides .is-hidden' );

// Every rule that could put a display back on an element carrying .is-hidden.
$sb_beats = array();
foreach ( $sb_rules as $rule ) {
	if ( ! preg_match( '/display\s*:\s*(block|flex|inline-flex|inline-block|grid|table|table-row|inline)/i', $rule['body'] ) ) {
		continue;
	}
	$sb_loud = (bool) preg_match( '/display\s*:\s*[a-z-]+\s*!important/i', $rule['body'] );
	foreach ( $rule['selectors'] as $sel ) {
		if ( false !== strpos( $sel, '.is-hidden' ) ) {
			continue;
		}
		$sb_spec = sb_specificity( $sel );
		$sb_won  = false;
		foreach ( $sb_hiders as $h ) {
			// !important beats anything that is not important; otherwise the
			// hider needs at least equal specificity, since it is declared
			// after the layout rules it has to beat.
			if ( $h['important'] && ! $sb_loud ) {
				$sb_won = true;
			} elseif ( $h['important'] === $sb_loud && $h['spec'] >= $sb_spec ) {
				$sb_won = true;
			}
		}
		if ( ! $sb_won ) {
			$sb_beats[] = $sel . ' (' . $sb_spec . ')';
		}
	}
}
ok( array() === $sb_beats, 'hiding: no display rule in the stylesheet out-ranks it — ' . implode( ', ', array_slice( $sb_beats, 0, 4 ) ) );

// The one that actually bit, named, so the regression is unmistakable.
$sb_tr_spec = sb_specificity( '.sendbeam-app .form-table tr' );
ok( $sb_tr_spec > sb_specificity( '.sendbeam-app .is-hidden' ), 'hiding: the Settings API row reset really is the more specific selector' );
ok( $sb_hiders[0]['important'], 'hiding: so the hiding rule wins on !important rather than on specificity' );

// ── #17 again: small buttons and the tab bar are tap targets too ────────
$sb_phone = substr( $sb_css, strpos( $sb_css, '@media (max-width:782px)' ) );
has( $sb_phone, '.sendbeam-app .sb-btn--small{min-height:44px', 'touch: Copy, Remove, Check now and the confirmations are 44px' );
has( $sb_phone, '.sb-tab{height:44px;min-height:44px}', 'touch: and so is the Settings tab bar' );
lacks( $sb_phone, 'min-height:40px', 'touch: nothing on a phone is left at 40px' );
lacks( $sb_phone, 'min-height:42px', 'touch: or at 42px' );

// ── The block editor shows a placeholder, not the hosted form ───────────
// The form is a cross-origin iframe and SendBeam serves it only to the sites
// its owner has listed, which it decides from the referrer. The block editor
// renders inside a nested iframe with an opaque origin, so there is no
// referrer to send and the hosted page answered "This form can only be shown
// on the sites its owner has listed" — on a site that is listed, and whose
// published page rendered the same form perfectly.
$sb_editor_js = file_get_contents( dirname( __DIR__ ) . '/blocks/form/index.js' );
lacks( $sb_editor_js, 'ServerSideRender', 'block: the editor does not try to render the hosted form' );
has( $sb_editor_js, 'The form appears here on the published page.', 'block: it says where the form will be instead' );
has( $sb_editor_js, "__( 'Preview', 'sendbeam' )", 'block: and offers to open the real one in a tab' );
has( $sb_editor_js, 'config.previewBase + effectiveId', 'block: the Preview link points at the form itself' );

// The dependency that only existed for the live preview goes with it.
$sb_asset = require dirname( __DIR__ ) . '/blocks/form/index.asset.php';
ok( ! in_array( 'wp-server-side-render', $sb_asset['dependencies'], true ), 'block: and the editor stops loading a script nothing uses' );
foreach ( array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n', 'wp-api-fetch' ) as $sb_dep ) {
	ok( in_array( $sb_dep, $sb_asset['dependencies'], true ), "block: $sb_dep is still a dependency" );
}

// The editor is told where a preview lives, and it is the hosted form on the
// app's own host — never the embed, which is the thing that cannot be shown.
$GLOBALS['stub']['inline'] = array();
sendbeam_editor_defaults();
$sb_inline = '';
foreach ( $GLOBALS['stub']['inline'] as $sb_one ) {
	$sb_inline .= is_array( $sb_one ) ? implode( ' ', array_map( 'strval', $sb_one ) ) : (string) $sb_one;
}
has( $sb_inline, 'window.sendbeamBlock', 'block: the editor is given its configuration' );
has( $sb_inline, '"previewBase":"https:\\/\\/sendbeam.io\\/f\\/"', 'block: including where a preview of one form lives' );
lacks( $sb_inline, 'embed=1', 'block: which is the hosted form, not the embed' );

// The front end is untouched: that is the half that works.
sb_seed_settings( array_merge( sendbeam_settings(), array( 'default_form' => '9da94d34-86f8-4fbc-9333-45c0b4c16d6a' ) ) );
has( do_shortcode_tag( 'sendbeam_form' ), 'iframe class="sendbeam-form"', 'block: a published page still embeds the real form' );
has( do_shortcode_tag( 'sendbeam_form' ), 'embed=1', 'block: with the embed flag the hosted page expects' );
sb_seed_settings( array() );

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


// ---------------------------------------------------------------- WooCommerce claims (2026-09-24)
// The block checkout, order placed only when paid, and no pop-up over the checkout.
sb_seed_settings( array( 'api_key' => 'sb_live_wooclaims0123456789abcdef' ) );
update_option( SENDBEAM_ECOMMERCE_OPTION, array( 'order_placed' => 1, 'product_viewed' => 0, 'cart_abandoned' => 0, 'cart_abandoned_window' => 60 ) );
$sb_reg = array();
foreach ( $stub['callbacks'] as $pair ) { if ( is_string( $pair[1] ) ) { $sb_reg[] = $pair[0] . '=>' . $pair[1]; } }
ok( in_array( 'woocommerce_payment_complete=>sendbeam_ecommerce_on_order_paid', $sb_reg, true ), 'order placed: listens to payment complete' );
ok( in_array( 'woocommerce_order_status_processing=>sendbeam_ecommerce_on_order_paid', $sb_reg, true ), 'order placed: and to an order reaching processing (cash on delivery)' );
ok( in_array( 'woocommerce_order_status_completed=>sendbeam_ecommerce_on_order_paid', $sb_reg, true ), 'order placed: and to completed' );
ok( ! in_array( 'woocommerce_checkout_order_processed=>sendbeam_ecommerce_on_order_placed', $sb_reg, true ), 'order placed: no longer fires at checkout, before payment' );
ok( in_array( 'woocommerce_store_api_checkout_order_processed=>sendbeam_sync_on_block_checkout', $sb_reg, true ), 'block checkout: the opt-in is read from the Store API order' );
ok( in_array( 'woocommerce_init=>sendbeam_sync_register_block_checkout_field', $sb_reg, true ), 'block checkout: the box is registered once WooCommerce is up' );

$stub['cron'] = array(); $stub['wc_order_meta'] = array(); $stub['wc_saves'] = array();
$stub['wc_orders'][601] = array( 'email' => 'paid@example.test', 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'total' => 40, 'currency' => 'GBP' );
sendbeam_ecommerce_on_order_paid( 601 );
ok( 1 === count( $stub['cron'] ) && 'order_placed' === $stub['cron'][0]['args'][0]['type'], 'order placed: the first paid hook queues one event' );
ok( ! empty( $stub['wc_order_meta'][601][ SENDBEAM_ORDER_PLACED_META ] ), 'order placed: and records on the order that it went' );
sendbeam_ecommerce_on_order_paid( 601 );
sendbeam_ecommerce_on_order_paid( wc_get_order( 601 ) );
ok( 1 === count( $stub['cron'] ), 'order placed: processing then completed is still ONE event for the order' );
$stub['cron'] = array();
sendbeam_ecommerce_on_order_paid( 999 );
ok( empty( $stub['cron'] ), 'order placed: an order that does not exist sends nothing' );

// Refunds and cancellations (1.8.5): netted in SendBeam, only for orders the plugin reported.
ok( in_array( 'woocommerce_order_refunded=>sendbeam_ecommerce_on_order_refunded', $sb_reg, true ), 'refund: listens to a refund being recorded' );
ok( in_array( 'woocommerce_order_status_cancelled=>sendbeam_ecommerce_on_order_cancelled', $sb_reg, true ), 'cancel: and to an order being cancelled' );
$stub['cron'] = array();
$stub['wc_refunds'][701] = array( 'amount' => 12.5, 'currency' => 'GBP' );
$stub['wc_orders'][602] = array( 'email' => 'never@example.test', 'total' => 40, 'currency' => 'GBP' );
sendbeam_ecommerce_on_order_refunded( 602, 701 );
sendbeam_ecommerce_on_order_cancelled( 602 );
ok( empty( $stub['cron'] ), 'refund/cancel: an order SendBeam was never told about sends nothing' );
sendbeam_ecommerce_on_order_refunded( 601, 701 );
ok( 1 === count( $stub['cron'] ) && 'order_refunded' === $stub['cron'][0]['args'][0]['type'], 'refund: queues one order_refunded for a known order' );
$sb_refund = $stub['cron'][0]['args'][0];
ok( 12.5 === $sb_refund['value'] && '601' === $sb_refund['order_id'] && 'GBP' === $sb_refund['currency'] && 'paid@example.test' === $sb_refund['email'], 'refund: carries the refunded amount, the order id and the currency' );
$stub['cron'] = array();
$stub['wc_refunds'][702] = array( 'amount' => 0 );
sendbeam_ecommerce_on_order_refunded( 601, 702 );
ok( empty( $stub['cron'] ), 'refund: a zero refund sends nothing' );
sendbeam_ecommerce_on_order_cancelled( 601 );
ok( 1 === count( $stub['cron'] ) && 'order_cancelled' === $stub['cron'][0]['args'][0]['type'] && '601' === $stub['cron'][0]['args'][0]['order_id'], 'cancel: queues one order_cancelled for a known order' );
ok( ! empty( $stub['wc_order_meta'][601][ SENDBEAM_ORDER_CANCELLED_META ] ), 'cancel: and marks the order' );
sendbeam_ecommerce_on_order_cancelled( 601 );
sendbeam_ecommerce_on_order_cancelled( wc_get_order( 601 ) );
ok( 1 === count( $stub['cron'] ), 'cancel: once per order' );
$stub['cron'] = array();

// The block checkout field: registered only while the source can subscribe anybody.
$stub['wc_checkout_fields'] = array();
update_option( 'sendbeam_sync', array( 'woocommerce' => 0, 'registration' => 0, 'comments' => 0, 'lists' => array( 'list-1' ), 'label' => 'Keep me posted' ) );
sendbeam_sync_register_block_checkout_field();
ok( empty( $stub['wc_checkout_fields'] ), 'block checkout: no field is registered while the checkout source is off' );
update_option( 'sendbeam_sync', array( 'woocommerce' => 1, 'registration' => 0, 'comments' => 0, 'lists' => array( 'list-1' ), 'label' => 'Keep me posted' ) );
sendbeam_sync_register_block_checkout_field();
ok( 1 === count( $stub['wc_checkout_fields'] ), 'block checkout: one field once it is on' );
$sb_field = $stub['wc_checkout_fields'][0];
ok( 'sendbeam/subscribe' === $sb_field['id'] && 'order' === $sb_field['location'] && 'checkbox' === $sb_field['type'] && empty( $sb_field['required'] ), 'block checkout: a checkbox in the order area, not required' );
ok( 'Keep me posted' === $sb_field['label'], 'block checkout: labelled with the same words as the classic box' );
ok( ! isset( $sb_field['default'] ) || empty( $sb_field['default'] ), 'block checkout: never pre-ticked' );

// Reading the box back from the order the Store API hands over.
$stub['remote'] = array(); $stub['remote_reply'] = array( 'response' => array( 'code' => 200 ), 'body' => '{"id":"c1"}' );
$stub['wc_order_meta'][701] = array( SENDBEAM_BLOCK_OPTIN_META => '1' );
sendbeam_sync_on_block_checkout( new WC_Order( array( 'id' => 701, 'email' => 'block@example.test', 'first_name' => 'Grace', 'last_name' => 'Hopper' ) ) );
ok( count( $stub['remote'] ) >= 1 && false !== strpos( $stub['remote'][0]['url'], '/api/v1/contacts' ), 'block checkout: a ticked box subscribes the customer' );
$sb_body = json_decode( $stub['remote'][0]['args']['body'], true );
ok( is_array( $sb_body ) && 'block@example.test' === $sb_body['email'] && 'woocommerce-checkout' === $sb_body['source'], 'block checkout: with the address and the woocommerce-checkout source' );
$stub['remote'] = array();
sendbeam_sync_on_block_checkout( new WC_Order( array( 'id' => 702, 'email' => 'quiet@example.test' ) ) );
ok( empty( $stub['remote'] ), 'block checkout: an unticked box subscribes nobody' );
sendbeam_sync_on_block_checkout( 702 );
ok( empty( $stub['remote'] ), 'block checkout: and a bare id (not an order) is ignored rather than fatal' );

// No pop-up over the checkout.
update_option( 'sendbeam_popups', array( array( 'enabled' => 1, 'form' => '9da94d34-86f8-4fbc-9333-45c0b4c16d6a', 'where' => 'everywhere', 'trigger' => 'timer', 'delay' => 0, 'once' => 'never' ) ) );
$stub['query'] = array();
ok( null !== sendbeam_active_popup(), 'pop-ups: an everywhere rule opens on an ordinary page' );
foreach ( array( 'cart', 'checkout', 'account', 'wc_endpoint' ) as $sb_where ) {
	$stub['query'] = array( $sb_where => 1 );
	ok( null === sendbeam_active_popup(), "pop-ups: but never on the $sb_where page, whatever the rule says" );
}
$stub['query'] = array();
has( file_get_contents( __DIR__ . '/../includes/screens.php' ), 'Never on the cart, checkout or account pages.', 'pop-ups: the Show on control says so' );

// The records wrapper must be sized by its container, not its content (it collapsed to 2px on the Overview).
$sb_css = sendbeam_admin_css();
has( $sb_css, '.sb-scroll{overflow-x:auto;display:block;width:100%;min-width:0;', 'records: the scroll wrapper always takes the full width of its container' );
lacks( $sb_css, '.sb-steps li{', 'records: the checklist row rule is scoped to its own items, not every list inside a step' );
has( $sb_css, '.sb-numbered>li{display:list-item;', 'records: a numbered list inside a step stays a list' );
has( $sb_css, '.sb-scroll>.sb-dns{width:100%}', 'records: and the table fills the wrapper' );

// Step 2 points at the plugin's own Sending domain screen while a domain exists, and at SendBeam only to add one.
$ov = sb_overview( $sb_connected, $sb_unverified );
has( $ov, 'page=sendbeam-settings', 'overview: step 2 sends the owner to the plugin, not the app' );
has( $ov, 'tab=domain', 'overview: to its own Sending domain screen' );
$ov = sb_overview( $sb_connected, $sb_nodomain );
has( $ov, '/settings/domains', 'overview: a site with no domain is still sent to where one is added' );
