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

// Form HTML.
ok( sendbeam_form_html( 'nope' ) === '', 'invalid id renders nothing' );
$html = sendbeam_form_html( $form, 50, 'A "quoted" title' );
has( $html, 'src="https://sendbeam.io/f/' . $form . '?embed=1', 'iframe src' );
has( $html, 'width=0', 'the embed fills the column the theme gives it' );
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

echo "smoke: $pass checks passed\n";
