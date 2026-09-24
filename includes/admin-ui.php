<?php
/**
 * The admin shell: SendBeam's own design language inside wp-admin.
 *
 * The product is "Switchboard" — warm paper, hard 1px ink rules, mono labels
 * in caps, and three accent hues that mean something (vermilion = attention,
 * cobalt = forms, moss = healthy). Bringing that here means someone moving
 * between sendbeam.io and this plugin does not feel like they changed product.
 *
 * Fonts are deliberately *not* loaded from Google. WordPress.org expects a
 * plugin's assets to ship with it, remote font loading in wp-admin is a
 * privacy question in the EU, and the mono/sans distinction survives the
 * system fallbacks perfectly well.
 *
 * @package SendBeam
 */

defined( 'ABSPATH' ) || exit;

/**
 * The old tab keys, and the page each one became.
 *
 * Every handler in the plugin redirects to `sendbeam_tab_url( 'overview' )`
 * and friends, and every bookmark anyone made points at
 * `options-general.php?page=sendbeam&tab=X`. Keeping one table of what became
 * what means the move is a change of address rather than a change of
 * behaviour — and the 301 in menu.php reads the same table.
 *
 * @return array<string,string> Old tab key => new page slug.
 */
function sendbeam_tab_pages() {
	return array(
		'overview'  => 'sendbeam',
		'forms'     => 'sendbeam-forms',
		'audience'  => 'sendbeam-audience',
		'popup'     => 'sendbeam-popups',
		'mail'      => 'sendbeam-mail',
		'ecommerce' => 'sendbeam-ecommerce',
		// The Docs tab is gone; the Help page carries what it held.
		'docs'      => 'sendbeam-help',
	);
}

/**
 * URL for what used to be a tab.
 *
 * @param string $tab Tab key.
 * @return string
 */
function sendbeam_tab_url( $tab ) {
	$pages = sendbeam_tab_pages();
	return sendbeam_page_url( isset( $pages[ $tab ] ) ? $pages[ $tab ] : 'sendbeam' );
}

/**
 * The branded band at the top of every SendBeam screen.
 *
 * It replaces the visual `<h1>` (the screen-reader one is in the router), and
 * it names the section it is above: a header that says only "SendBeam" on
 * eight different pages tells you the plugin you are in and nothing about
 * where in it you are.
 *
 * @param string $title The section name.
 */
function sendbeam_render_header( $title = '' ) {
	$connection = sendbeam_connection();
	$states     = array(
		'ok'          => array( 'moss', __( 'Connected', 'sendbeam' ) ),
		'no_scope'    => array( 'amber', __( 'Limited key', 'sendbeam' ) ),
		'rejected'    => array( 'vermilion', __( 'Key rejected', 'sendbeam' ) ),
		'unreachable' => array( 'vermilion', __( 'Unreachable', 'sendbeam' ) ),
		'none'        => array( 'ink', __( 'Not connected', 'sendbeam' ) ),
	);
	$state      = isset( $states[ $connection['state'] ] ) ? $states[ $connection['state'] ] : $states['none'];
	?>
	<div class="sb-head">
		<div class="sb-head__brand">
			<span class="sb-mark" aria-hidden="true"></span>
			<span class="sb-wordmark">SendBeam</span>
			<?php if ( '' !== $title ) : ?>
				<span class="sb-head__where" aria-hidden="true">/</span>
				<span class="sb-head__title"><?php echo esc_html( $title ); ?></span>
			<?php endif; ?>
		</div>
		<div class="sb-head__right">
			<span class="sb-state sb-state--<?php echo esc_attr( $state[0] ); ?>"><?php echo esc_html( $state[1] ); ?></span>
			<a class="sb-btn sb-btn--ghost" href="<?php echo esc_url( sendbeam_app_url() ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open SendBeam', 'sendbeam' ); ?></a>
		</div>
	</div>
	<?php
}

/**
 * An in-page tab bar, for the one screen that still has tabs.
 *
 * @param array<string,string> $tabs    Key => label.
 * @param string               $current The active key.
 * @param string               $slug    The page the tabs live on.
 * @param string               $label   Accessible name for the nav.
 */
function sendbeam_render_tabs( $tabs, $current, $slug, $label ) {
	echo '<nav class="sb-tabs" aria-label="' . esc_attr( $label ) . '">';
	foreach ( $tabs as $key => $text ) {
		printf(
			'<a class="sb-tab%1$s" href="%2$s"%3$s>%4$s</a>',
			esc_attr( $key === $current ? ' is-active' : '' ),
			esc_url( add_query_arg( 'tab', $key, sendbeam_page_url( $slug ) ) ),
			$key === $current ? ' aria-current="page"' : '',
			esc_html( $text )
		);
	}
	echo '</nav>';
}

/**
 * Open a card.
 *
 * @param string $title Card heading.
 * @param string $note  Optional muted note on the right.
 */
function sendbeam_card_open( $title = '', $note = '' ) {
	echo '<section class="sb-card">';
	if ( '' !== $title ) {
		echo '<header class="sb-card__head"><h2>' . esc_html( $title ) . '</h2>';
		if ( '' !== $note ) {
			echo '<span class="sb-note">' . esc_html( $note ) . '</span>';
		}
		echo '</header>';
	}
	echo '<div class="sb-card__body">';
}

/** Close a card. */
function sendbeam_card_close() {
	echo '</div></section>';
}

/**
 * The stylesheet. Scoped to .sendbeam-app so nothing leaks into wp-admin.
 *
 * @return string
 */
function sendbeam_admin_css() {
	return '
	.sendbeam-app{--paper:#EDEBE6;--ink:#121212;--v:#E2442A;--c:#1F3FBF;--m:#3B7D46;--a:#B26B12;--ink-60:#5c5c5c;
		background:var(--paper);margin:20px 20px 0 0;padding:0 0 28px;color:var(--ink);
		font-family:Archivo,ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;}
	.sendbeam-app *{box-sizing:border-box}
	.sendbeam-app .sb-mono,.sendbeam-app .sb-label,.sendbeam-app .sb-state,.sendbeam-app .sb-chip,.sendbeam-app .sb-tab,.sendbeam-app code
		{font-family:"Martian Mono",ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-variant-numeric:tabular-nums}

	.sb-scopes{list-style:none;margin:0;padding:0}
	.sb-scopes li{margin:0 0 6px}
	.sb-scopes label{display:flex;gap:8px;align-items:flex-start;line-height:1.45}
	.sb-paste>summary{cursor:pointer;font-size:12px;color:var(--ink-60)}

	.sb-head{display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;
		padding:18px 22px;background:var(--ink);color:var(--paper)}
	.sb-head__brand{display:flex;align-items:center;gap:10px}
	.sb-mark{width:16px;height:16px;background:var(--v);display:inline-block;
		box-shadow:22px 0 0 0 var(--c),44px 0 0 0 var(--m);margin-right:44px;flex:0 0 auto}
	.sb-wordmark{font-weight:700;font-size:18px;letter-spacing:-.01em}
	.sb-ver{font-size:11px;opacity:.6;letter-spacing:.06em}
	.sb-head__right{display:flex;align-items:center;gap:12px;flex-wrap:wrap}

	.sb-state{display:inline-block;font-size:10px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;
		padding:5px 8px 4px;color:#fff;background:var(--ink-60)}
	.sb-state--moss{background:var(--m)} .sb-state--vermilion{background:var(--v)}
	.sb-state--amber{background:var(--a)} .sb-state--ink{background:#3a3a3a}

	.sb-tabs{display:flex;gap:0;flex-wrap:wrap;background:var(--ink);padding:0 22px;border-bottom:1px solid var(--ink)}
	.sb-tab{display:inline-flex;align-items:center;height:40px;padding:0 16px;text-decoration:none;
		font-size:10px;letter-spacing:.1em;text-transform:uppercase;font-weight:600;
		color:rgba(237,235,230,.75);border-bottom:3px solid transparent}
	.sb-tab:hover{color:#fff}
	.sb-tab.is-active{color:var(--ink);background:var(--paper);border-bottom-color:var(--v)}
	.sb-tab:focus{box-shadow:none;outline:2px solid var(--v);outline-offset:-2px}

	.sb-wrap{padding:22px}
	/* Cards in a row share a height so their bottom rules line up; without this
	   each one ends wherever its own content does and the row looks ragged. */
	.sb-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:18px;align-items:stretch;margin-bottom:18px}
	.sb-grid>.sb-card{margin-bottom:0;display:flex;flex-direction:column}
	.sb-grid>.sb-card>.sb-card__body{flex:1 1 auto}

	.sb-card{background:#fff;border:1px solid var(--ink);margin:0 0 18px}
	.sb-card__head{display:flex;align-items:baseline;justify-content:space-between;gap:1rem;
		padding:12px 16px;border-bottom:1px solid var(--ink)}
	.sb-card__head h2{margin:0;font-size:14px;font-weight:700;letter-spacing:-.01em}
	.sb-card__body{padding:16px}
	.sb-note{font-size:11px;color:var(--ink-60)}

	.sb-label{display:block;font-size:10px;letter-spacing:.1em;text-transform:uppercase;font-weight:600;
		color:var(--ink-60);margin:0 0 4px}
	.sb-fig{font-size:30px;font-weight:600;line-height:1;letter-spacing:-.02em;
		font-family:"Martian Mono",ui-monospace,monospace;font-variant-numeric:tabular-nums}

	.sb-chip{display:inline-block;font-size:10px;letter-spacing:.06em;padding:4px 6px 3px;
		border:1px solid var(--ink);background:#fff;text-transform:uppercase;font-weight:600}
	.sb-chip--signup{border-color:var(--c);color:var(--c)}
	.sb-chip--contact{border-color:var(--a);color:var(--a)}

	.sb-btn{display:inline-flex;align-items:center;gap:6px;height:34px;padding:0 14px;cursor:pointer;
		font-size:13px;font-weight:700;text-decoration:none;border:1px solid var(--ink);
		background:var(--ink);color:var(--paper)}
	.sb-btn:hover{background:var(--v);border-color:var(--v);color:#fff}
	.sb-btn--ghost{background:transparent;color:inherit;border-color:currentColor;font-weight:600}
	.sb-btn--ghost:hover{background:var(--v);border-color:var(--v);color:#fff}
	.sb-btn--small{height:28px;padding:0 10px;font-size:12px}

	.sb-table{width:100%;border-collapse:collapse;border:1px solid var(--ink);background:#fff}
	.sb-table th{text-align:left;font-size:10px;letter-spacing:.1em;text-transform:uppercase;
		font-family:"Martian Mono",ui-monospace,monospace;color:var(--ink-60);font-weight:600;
		padding:9px 12px;border-bottom:1px solid var(--ink)}
	.sb-table td{padding:11px 12px;border-bottom:1px solid #e2e0db;vertical-align:middle}
	.sb-table tr:last-child td{border-bottom:0}
	.sb-table code{font-size:11px;background:var(--paper);padding:3px 5px;border:1px solid #d8d6d0}

	.sb-steps{list-style:none;margin:0;padding:0;counter-reset:none}
	.sb-steps li{display:flex;gap:12px;align-items:flex-start;padding:12px 0;border-bottom:1px solid #e2e0db}
	.sb-steps li:last-child{border-bottom:0}
	.sb-steps .sb-num{flex:0 0 auto;width:24px;height:24px;display:inline-flex;align-items:center;justify-content:center;
		font-size:11px;font-weight:700;background:var(--paper);border:1px solid var(--ink);
		font-family:"Martian Mono",ui-monospace,monospace}
	.sb-steps li.is-done .sb-num{background:var(--m);border-color:var(--m);color:#fff}
	.sb-steps strong{display:block;font-size:14px}
	/* Scoped to the note, not to every span in the step: a step now contains a
	   table, buttons and a disclosure, and `.sb-steps span` was greying all of
	   it out and forcing each one onto its own line. */
	.sb-steps .sb-step{flex:1 1 auto;min-width:0}
	.sb-steps .sb-step__note{display:block;font-size:12px;color:var(--ink-60);margin-top:2px}
	.sb-step__panel{margin-top:10px}
	.sb-step__panel>*:first-child{margin-top:0}
	.sb-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:10px 0 6px}
	.sb-actions form{margin:0}

	/* The sending domain. The value column is the one people copy, so it gets
	   the room; the whole table scrolls sideways rather than squeezing a TXT
	   record into three words per line on a phone. */
	.sb-scroll{overflow-x:auto;margin:0 0 10px}
	.sb-dns td code{display:inline-block;max-width:46ch;overflow-wrap:anywhere}
	.sb-dns__value{white-space:normal}
	.sb-verified{margin:0 0 8px;font-weight:700;color:var(--m);display:flex;gap:6px;align-items:center}
	.sb-tick{display:inline-flex;align-items:center;justify-content:center;width:18px;height:18px;
		background:var(--m);color:#fff;font-size:11px;flex:0 0 auto}
	.sb-granted{list-style:none;margin:0 0 12px;padding:0;font-size:12px;color:var(--ink-60)}
	.sb-granted li{position:relative;padding-left:14px;margin:0 0 3px}
	.sb-granted li::before{content:"\2713";position:absolute;left:0;color:var(--m)}

	/* Inline confirmation. With no script the box is simply already open and
	   the plain button beside it never appears, so the form still submits. */
	.sb-confirm{margin:0}
	.sb-confirm__box{border-left:4px solid var(--v);background:var(--paper);padding:10px 12px}
	.sb-confirm__box p{margin:0 0 8px}

	.sb-msg{padding:10px 14px;border-left:4px solid var(--ink-60);background:#fff;border-top:1px solid var(--ink);
		border-right:1px solid var(--ink);border-bottom:1px solid var(--ink);margin:0 0 14px;
		display:flex;gap:12px;align-items:baseline;flex-wrap:wrap}
	.sb-msg--ok{border-left-color:var(--m)} .sb-msg--warn{border-left-color:var(--a)}
	.sb-msg--bad{border-left-color:var(--v)}

	.sendbeam-app .form-table th{font-size:10px;letter-spacing:.1em;text-transform:uppercase;color:var(--ink-60);
		font-family:"Martian Mono",ui-monospace,monospace;font-weight:600;padding:16px 10px 16px 0}
	.sendbeam-app .form-table td{padding:12px 10px}
	.sendbeam-app input[type=text],.sendbeam-app input[type=number],.sendbeam-app input[type=password],
	.sendbeam-app select{border:1px solid var(--ink);border-radius:0;background:#fff}
	.sendbeam-app input:focus,.sendbeam-app select:focus{outline:2px solid var(--c);outline-offset:-2px;box-shadow:none}
	.sendbeam-app .description{font-size:12px;color:var(--ink-60)}
	.sendbeam-app h2{color:var(--ink)}

	.sb-rule{border:1px solid var(--ink);background:var(--paper);padding:14px 16px;margin:0 0 14px}
	.sb-rule legend{padding:0 6px;background:var(--ink);color:var(--paper);font-size:10px;letter-spacing:.1em;
		text-transform:uppercase;font-weight:700}
	.sb-rule__grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:14px;align-items:end}
	.sb-rule__grid label{display:block}
	.sb-rule__grid select,.sb-rule__grid input[type=text]{width:100%;max-width:100%}
	.sb-rule__foot{display:flex;align-items:center;justify-content:space-between;gap:12px;
		margin-top:14px;padding-top:12px;border-top:1px solid #d8d6d0}
	.sb-inline{display:inline-flex;align-items:center;gap:6px;font-size:13px}
	/* Connect: the permission list. The plugin colour on the native box, the
	   box aligned to the first line of a label that may wrap on a phone. */
	.sb-scopes{list-style:none;margin:0 0 14px;padding:0}
	.sb-scopes li{margin:0 0 8px}
	.sb-scope{align-items:flex-start;line-height:1.4}
	.sb-scope input[type=checkbox]{margin:2px 0 0;flex:0 0 auto;border-color:#121212;border-radius:0}
	.sb-scope input[type=checkbox]:checked::before{content:url("data:image/svg+xml;utf8,%3Csvg xmlns=%27http://www.w3.org/2000/svg%27 viewBox=%270 0 20 20%27%3E%3Cpath d=%27M14.83 4.89l1.34.94-5.81 8.38H9.02L5.78 9.67l1.34-1.25 2.57 2.4z%27 fill=%27%23121212%27/%3E%3C/svg%3E")}
	.sb-scope input[type=checkbox]:checked,.sb-scope input[type=checkbox]:disabled{background:#fff;opacity:1}
	.sb-scope input[type=checkbox]:disabled:checked::before{opacity:1}
	.sb-scope input[type=checkbox]:focus{box-shadow:0 0 0 1px #121212}
	.sb-scope .sb-note{margin-left:4px}
	.sendbeam-app [hidden]{display:none!important}

	.sb-doc h3{font-size:14px;margin:0 0 6px}
	.sb-doc p{margin:0 0 10px;max-width:62ch}
	.sb-doc ul{margin:0 0 10px 1.1em;list-style:disc}
	.sb-doc li{margin:4px 0}
	.sb-doc code{font-size:12px;background:var(--paper);padding:2px 5px;border:1px solid #d8d6d0}
	.sb-doc__row{display:flex;gap:12px;align-items:center;justify-content:space-between;
		padding:9px 0;border-bottom:1px solid #e2e0db;flex-wrap:wrap}
	.sb-doc__row:last-child{border-bottom:0}
	.sb-doc__row code{flex:1 1 22em}

	@media (max-width:782px){ .sb-head{padding:14px} .sb-tabs{padding:0 8px} .sb-wrap{padding:14px} }
	';
}
