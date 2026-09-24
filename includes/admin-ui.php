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
 * One admin notice, in core's own markup.
 *
 * `wp_admin_notice()` arrived in WordPress 6.4 and this plugin supports 6.1,
 * so the fallback is not decoration: it is what a third of sites would see.
 * Both paths emit the same classes, because the point of using core's notice
 * is that it looks and behaves like every other notice on the screen —
 * including being dismissible by core's own script.
 *
 * @param string $message     The sentence. Already-escaped HTML.
 * @param string $type        info, success, warning or error.
 * @param bool   $dismissible Whether core's dismiss button is offered.
 */
function sendbeam_notice( $message, $type = 'info', $dismissible = true ) {
	$args = array(
		'type'               => $type,
		'dismissible'        => $dismissible,
		'additional_classes' => array( 'sendbeam-notice' ),
		'paragraph_wrap'     => true,
	);

	if ( function_exists( 'wp_admin_notice' ) ) {
		wp_admin_notice( $message, $args );
		return;
	}

	printf(
		'<div class="notice notice-%1$s sendbeam-notice%2$s"><p>%3$s</p></div>',
		esc_attr( $type ),
		$dismissible ? ' is-dismissible' : '',
		wp_kses_post( $message )
	);
}

/**
 * Open a card.
 *
 * @param string $title  Card heading.
 * @param string $note   Optional muted note on the right.
 * @param string $action Optional single action, right-aligned in the header.
 *                       A card's one secondary action belongs beside its
 *                       title, not adrift at the bottom of its body.
 */
function sendbeam_card_open( $title = '', $note = '', $action = '' ) {
	echo '<section class="sb-card">';
	if ( '' !== $title ) {
		echo '<header class="sb-card__head"><h2>' . esc_html( $title ) . '</h2>';
		if ( '' !== $note ) {
			echo '<span class="sb-note">' . esc_html( $note ) . '</span>';
		}
		if ( '' !== $action ) {
			echo '<span class="sb-card__action">' . wp_kses_post( $action ) . '</span>';
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
	/* ── Tokens ───────────────────────────────────────────────────────── */
	.sendbeam-app{--paper:#EDEBE6;--ink:#121212;--v:#E2442A;--c:#1F3FBF;--m:#3B7D46;--a:#B26B12;--ink-60:#5c5c5c;
		--rule:#d8d6d0;--hair:#e2e0db;
		/* The admin theme colour, so a site that has chosen Midnight or Ocean
		   gets its own primary rather than one this plugin picked. */
		--brand:var(--wp-admin-theme-color,#2271b1);
		--brand-dark:var(--wp-admin-theme-color-darker-10,#135e96);
		background:var(--paper);margin:20px 20px 0;padding:0 0 28px;color:var(--ink);
		font-family:Archivo,ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;}
	.sendbeam-app *{box-sizing:border-box}
	/* Mono is for values a person copies, and for nothing else. */
	.sendbeam-app .sb-mono,.sendbeam-app code,.sendbeam-app pre,.sendbeam-app .sb-fig,.sendbeam-app .sb-copy-field
		{font-family:"Martian Mono",ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-variant-numeric:tabular-nums}

	/* ── The header band ──────────────────────────────────────────────── */
	/* clear, because Screen Options and Help are floated right by core and an
	   unclear block sits beside them at two-thirds width. */
	.sb-head{display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;clear:both;
		padding:18px 22px;background:var(--ink);color:var(--paper)}
	.sb-head__brand{display:flex;align-items:center;gap:10px;min-width:0}
	.sb-mark{width:14px;height:14px;background:var(--v);display:inline-block;
		box-shadow:20px 0 0 0 var(--c),40px 0 0 0 var(--m);margin-right:40px;flex:0 0 auto}
	.sb-wordmark{font-weight:700;font-size:18px;letter-spacing:-.01em}
	.sb-head__where{opacity:.45}
	.sb-head__title{font-size:16px;opacity:.9;overflow-wrap:anywhere}
	.sb-head__right{display:flex;align-items:center;gap:12px;flex-wrap:wrap}

	.sb-state{display:inline-block;font-size:12px;font-weight:600;padding:4px 9px;color:#fff;background:var(--ink-60)}
	.sb-state--moss{background:var(--m)} .sb-state--vermilion{background:var(--v)}
	.sb-state--amber{background:var(--a)} .sb-state--ink{background:#3a3a3a}
	.sb-state-line{display:flex;gap:9px;align-items:baseline;flex-wrap:wrap;margin:0 0 12px}

	/* ── Tabs, on the one screen that still has them ──────────────────── */
	.sb-tabs{display:flex;gap:0;flex-wrap:wrap;background:#fff;border:1px solid var(--ink);margin:0 0 18px}
	.sb-tab{display:inline-flex;align-items:center;height:42px;padding:0 18px;text-decoration:none;
		font-size:14px;font-weight:500;color:var(--ink-60);border-bottom:3px solid transparent}
	.sb-tab:hover{color:var(--ink)}
	.sb-tab.is-active{color:var(--ink);font-weight:600;border-bottom-color:var(--brand)}
	.sb-tab:focus{box-shadow:none;outline:2px solid var(--brand);outline-offset:-2px}

	.sb-wrap{padding:22px 0 0}

	/* ── Cards ────────────────────────────────────────────────────────── */
	/* Cards are as tall as what is in them. Stretching them to match the
	   tallest in the row lines their bottom rules up and leaves the shorter
	   one two-thirds empty, which reads as something failing to load. */
	.sb-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:18px;align-items:start;margin-bottom:18px}
	.sb-grid>.sb-card{margin-bottom:0}

	/* Three figures across, each label above its number. */
	.sb-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin:0 0 4px}
	.sb-stats .sb-label{margin-bottom:2px}
	.sb-stats .sb-fig{font-size:32px}

	.sb-card{background:#fff;border:1px solid var(--ink);margin:0 0 18px}
	.sb-card__head{display:flex;align-items:baseline;justify-content:space-between;gap:1rem;
		padding:13px 16px;border-bottom:1px solid var(--ink)}
	.sb-card__head h2{margin:0;font-size:15px;font-weight:700;letter-spacing:-.01em;margin-right:auto}
	.sb-card__action{flex:0 0 auto}
	.sb-card__body{padding:16px}
	.sb-note{font-size:13px;color:var(--ink-60)}

	/* ── Type ─────────────────────────────────────────────────────────── */
	.sb-label{display:block;font-size:13px;font-weight:600;color:var(--ink);margin:0 0 5px}
	.sb-fig{font-size:30px;font-weight:600;line-height:1;letter-spacing:-.02em;display:block}
	.sb-bullets{margin:0;padding-left:1.3em;list-style:disc}
	.sb-bullets li{margin:0 0 6px}
	.sb-numbered{margin:0 0 4px;padding-left:1.4em;list-style:decimal}
	.sb-numbered>li{margin:0 0 14px}
	.sb-numbered>li::marker{font-weight:700}

	.sb-chip{display:inline-block;font-size:12px;padding:3px 8px;
		border:1px solid var(--rule);background:#fff;font-weight:500}
	.sb-chip--signup{border-color:var(--c);color:var(--c)}
	.sb-chip--contact{border-color:var(--a);color:var(--a)}

	/* ── Buttons ──────────────────────────────────────────────────────── */
	/* Primary is the admin theme colour, so a site running Midnight or Ocean
	   gets a button that belongs to it. Black read as disabled to half the
	   people who saw it, and as a third-party widget to the rest. */
	.sendbeam-app .sb-btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;
		min-height:36px;padding:0 16px;cursor:pointer;font-size:14px;font-weight:600;text-decoration:none;
		border:1px solid var(--brand);border-radius:0;background:var(--brand);color:#fff;line-height:1.2}
	.sendbeam-app .sb-btn:hover,.sendbeam-app .sb-btn:focus{background:var(--brand-dark);border-color:var(--brand-dark);color:#fff}
	.sendbeam-app .sb-btn:focus{outline:2px solid var(--ink);outline-offset:1px;box-shadow:none}
	.sendbeam-app .sb-btn--primary{background:var(--brand);border-color:var(--brand);color:#fff}
	/* Secondary: ink outline. It sits beside a primary without competing. */
	.sendbeam-app .sb-btn--ghost{background:transparent;color:var(--ink);border-color:var(--ink);font-weight:500}
	.sendbeam-app .sb-btn--ghost:hover,.sendbeam-app .sb-btn--ghost:focus{background:var(--ink);border-color:var(--ink);color:var(--paper)}
	/* On the ink band the outline has to be the band\'s own foreground. */
	.sb-head .sb-btn--ghost{color:inherit;border-color:currentColor}
	.sb-head .sb-btn--ghost:hover,.sb-head .sb-btn--ghost:focus{background:var(--paper);border-color:var(--paper);color:var(--ink)}
	/* Danger: outlined, never filled. A filled red button beside a filled
	   blue one is a coin toss at a glance. */
	.sendbeam-app .sb-btn--danger{background:transparent;color:var(--v);border-color:var(--v)}
	.sendbeam-app .sb-btn--danger:hover,.sendbeam-app .sb-btn--danger:focus{background:var(--v);border-color:var(--v);color:#fff}
	.sendbeam-app .sb-btn--small{min-height:30px;padding:0 12px;font-size:13px}
	.sendbeam-app .sb-link{background:none;border:0;padding:0;margin:0;cursor:pointer;
		color:var(--brand);font-size:14px;text-decoration:underline;font-family:inherit}
	.sendbeam-app .sb-link:hover{color:var(--brand-dark)}

	/* ── Fields ───────────────────────────────────────────────────────── */
	/* Label above, always: a label to the left of a field wraps to three
	   lines on a phone and the field ends up a thumb-width wide. */
	.sendbeam-app .sb-field{display:block;margin:0 0 14px}
	.sendbeam-app input[type=text],.sendbeam-app input[type=email],.sendbeam-app input[type=url],
	.sendbeam-app input[type=number],.sendbeam-app input[type=password],.sendbeam-app input[type=search],
	.sendbeam-app select,.sendbeam-app textarea
		{min-height:40px;border:1px solid var(--ink);border-radius:0;background:#fff;
		font-size:14px;padding:0 10px;max-width:100%}
	.sendbeam-app textarea{padding:8px 10px}
	.sendbeam-app input:focus,.sendbeam-app select:focus,.sendbeam-app textarea:focus
		{outline:2px solid var(--brand);outline-offset:-2px;box-shadow:none;border-color:var(--brand)}
	.sendbeam-app .description{font-size:13px;color:var(--ink-60);margin:4px 0 0}
	.sendbeam-app h2{color:var(--ink)}

	/* The Settings API renders label-beside-field; this turns its two columns
	   into one on every width, which is what the rest of the plugin does. */
	.sendbeam-app .form-table,.sendbeam-app .form-table tbody,.sendbeam-app .form-table tr,
	.sendbeam-app .form-table th,.sendbeam-app .form-table td{display:block;width:auto;padding:0}
	.sendbeam-app .form-table th{font-size:13px;font-weight:600;color:var(--ink);padding:0 0 5px}
	.sendbeam-app .form-table td{padding:0 0 18px}
	.sendbeam-app .form-table tr:last-child td{padding-bottom:0}

	/* ── Toggles ──────────────────────────────────────────────────────── */
	/* A switch, because an on/off setting reads as a state rather than as a
	   choice being made now — which is what a tick box says. */
	.sendbeam-app .sb-toggle{display:flex;gap:10px;align-items:flex-start;line-height:1.45;font-size:14px}
	.sendbeam-app .sb-toggle input[type=checkbox]{appearance:none;-webkit-appearance:none;
		flex:0 0 auto;width:38px;height:22px;margin:1px 0 0;border:1px solid var(--ink);border-radius:11px;
		background:#fff;position:relative;cursor:pointer;transition:background .12s ease}
	.sendbeam-app .sb-toggle input[type=checkbox]::before{content:"";position:absolute;top:2px;left:2px;
		width:16px;height:16px;border-radius:50%;background:var(--ink);margin:0;transition:transform .12s ease}
	.sendbeam-app .sb-toggle input[type=checkbox]:checked{background:var(--brand);border-color:var(--brand)}
	.sendbeam-app .sb-toggle input[type=checkbox]:checked::before{background:#fff;transform:translateX(16px)}
	.sendbeam-app .sb-toggle input[type=checkbox]:disabled{opacity:.5;cursor:not-allowed}
	.sendbeam-app .sb-toggle input[type=checkbox]:focus{outline:2px solid var(--brand);outline-offset:2px;box-shadow:none}
	.sendbeam-app .sb-toggle .sb-note{display:block;margin-top:2px}

	/* ── Tables ───────────────────────────────────────────────────────── */
	/* `contain: inline-size` is what makes the promise of this wrapper true.
	   Without it a table wider than the screen still widens the page in a
	   mobile browser — the wrapper scrolls, and the layout viewport grows to
	   the table anyway, which is the failure this class exists to prevent. */
	.sb-scroll{overflow-x:auto;margin:0 0 10px;max-width:100%;contain:layout inline-size}
	.sb-table{width:100%;border-collapse:collapse;border:1px solid var(--ink);background:#fff}
	.sb-table th{text-align:left;font-size:13px;color:var(--ink);font-weight:600;
		padding:9px 12px;border-bottom:1px solid var(--ink)}
	.sb-table td{padding:11px 12px;border-bottom:1px solid var(--hair);vertical-align:middle}
	.sb-table tr:last-child td{border-bottom:0}
	.sb-table code{font-size:12px;background:var(--paper);padding:3px 5px;border:1px solid var(--rule)}

	/* The DNS records. Values are fields rather than text, so they select on
	   focus, scroll rather than wrapping, and copy on click. */
	.sb-dns th{font-size:13px}
	/* Type and Found are as wide as their contents and no wider, so every
	   pixel left over goes to Value — which is the longest string on the
	   screen and the one that used to be clipped a character short of its
	   own end. */
	.sb-dns th:nth-child(1),.sb-dns td.sb-dns__type{width:5em}
	.sb-dns th:nth-child(2),.sb-dns td.sb-dns__host{width:30%}
	.sb-dns th:nth-child(4),.sb-dns td.sb-dns__found{width:9em}
	.sb-dns td{vertical-align:top}

	/* The field a value is copied out of. Height comes from the line box and
	   the padding rather than being pinned: a pinned height on a 12px mono
	   field clips its own descenders, and an underscore in
	   `resend._domainkey` simply disappeared. */
	.sendbeam-app .sb-copy-field{display:block;width:100%;min-width:0;font-size:12px;line-height:1.5;
		height:auto;min-height:0;padding:6px 8px;cursor:pointer;resize:none;overflow:auto;
		white-space:pre;border:1px solid var(--ink);border-radius:0;background:#fff}
	.sendbeam-app .sb-copy-field.is-copied{border-color:var(--m);outline:2px solid var(--m);outline-offset:-2px}
	.sb-dns__found{white-space:nowrap}
	.sb-verified{margin:0 0 10px;font-weight:600;color:var(--m);display:flex;gap:8px;align-items:center}
	.sb-tick{display:inline-flex;align-items:center;justify-content:center;width:18px;height:18px;
		background:var(--m);color:#fff;font-size:11px;flex:0 0 auto}
	.sb-granted{list-style:none;margin:0 0 12px;padding:0;font-size:13px;color:var(--ink-60)}
	.sb-granted li{position:relative;padding-left:16px;margin:0 0 4px}
	.sb-granted li::before{content:"\2713";position:absolute;left:0;color:var(--m)}

	/* Recent activity: a row per message, readable at a glance and narrow
	   enough for the column it sits in. */
	.sb-recent{list-style:none;margin:0;padding:0}
	.sb-recent li{display:flex;gap:10px;align-items:baseline;flex-wrap:wrap;
		padding:8px 0;border-bottom:1px solid var(--hair);font-size:13px}
	.sb-recent li:last-child{border-bottom:0}
	.sb-recent__when{flex:0 0 auto;color:var(--ink-60);font-family:"Martian Mono",ui-monospace,monospace;font-size:11px}
	.sb-recent__who{flex:1 1 12ch;min-width:0;overflow-wrap:anywhere}
	.sb-recent__what{flex:0 0 auto}
	.sb-recent__what--ok{color:var(--m)} .sb-recent__what--warn{color:var(--a)}
	.sb-recent__what--bad{color:var(--v)}

	/* Core list tables: their own look, inside our card, and core\'s phone
	   collapse left alone — it is the thing that makes them work at 393px. */
	.sendbeam-app .wp-list-table{border:1px solid var(--ink)}
	.sendbeam-app .wp-list-table th,.sendbeam-app .wp-list-table td{font-size:14px}
	.sendbeam-app .search-box{margin:0 0 12px;display:flex;gap:8px;flex-wrap:wrap}
	.sendbeam-app .tablenav{height:auto;margin:10px 0 0;display:flex;gap:8px;align-items:center;flex-wrap:wrap}
	.sendbeam-app .tablenav .actions{padding:0;display:flex;gap:8px;flex-wrap:wrap}

	/* ── Steps and the checklist ──────────────────────────────────────── */
	.sb-steps{list-style:none;margin:0;padding:0}
	.sb-steps li{display:flex;gap:12px;align-items:flex-start;padding:12px 0;border-bottom:1px solid var(--hair)}
	.sb-steps li:last-child{border-bottom:0}
	.sb-steps .sb-num{flex:0 0 auto;width:24px;height:24px;display:inline-flex;align-items:center;justify-content:center;
		font-size:12px;font-weight:700;background:var(--paper);border:1px solid var(--ink)}
	.sb-steps li.is-done .sb-num{background:var(--m);border-color:var(--m);color:#fff}
	/* Switched on and doing the wrong thing is neither done nor unstarted. */
	.sb-steps li.needs-attention .sb-num{background:var(--a);border-color:var(--a);color:#fff}
	.sb-steps li.needs-attention .sb-step__note{color:var(--ink)}
	.sb-steps strong{display:block;font-size:14px}
	/* Scoped to the note, not to every span in the step: a step now contains a
	   table, buttons and a disclosure, and `.sb-steps span` was greying all of
	   it out and forcing each one onto its own line. */
	.sb-steps .sb-step{flex:1 1 auto;min-width:0}
	.sb-steps .sb-step__note{display:block;font-size:13px;color:var(--ink-60);margin-top:3px}
	.sb-step__panel{margin-top:10px}
	.sb-step__panel>*:first-child{margin-top:0}
	.sb-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:10px 0 6px}
	.sb-actions form{margin:0}

	/* ── The wizard ───────────────────────────────────────────────────── */
	.sb-rail{display:flex;gap:0;list-style:none;margin:0;padding:0;background:#fff;
		border:1px solid var(--ink);border-top:0;flex-wrap:wrap}
	.sb-rail__step{display:flex;gap:9px;align-items:center;padding:12px 16px;flex:1 1 auto;min-width:0;
		font-size:13px;color:var(--ink-60);border-right:1px solid var(--hair)}
	.sb-rail__step:last-child{border-right:0}
	.sb-rail__num{flex:0 0 auto;width:22px;height:22px;display:inline-flex;align-items:center;justify-content:center;
		font-size:12px;font-weight:700;border:1px solid currentColor}
	.sb-rail__label{overflow-wrap:anywhere}
	.sb-rail__step.is-done{color:var(--m)}
	.sb-rail__step.is-done .sb-rail__num{background:var(--m);border-color:var(--m);color:#fff}
	.sb-rail__step.is-current{color:var(--ink);font-weight:600;background:var(--paper)}
	.sb-rail__step.is-current .sb-rail__num{background:var(--brand);border-color:var(--brand);color:#fff}
	.sb-wizard__count{color:rgba(237,235,230,.75)}
	.sb-wizard__foot{display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin:0 0 10px}
	.sb-wizard__note{margin:0}

	/* ── Results and details ──────────────────────────────────────────── */
	.sb-result{border:1px solid var(--ink);border-left-width:4px;background:#fff;padding:14px 16px;margin:0 0 16px}
	.sb-result--ok{border-left-color:var(--m)}
	.sb-result--bad{border-left-color:var(--v)}
	.sb-result--warn{border-left-color:var(--a);background:#FDF6E7}
	.sb-result__title{margin:0 0 8px;font-size:16px;font-weight:700;display:flex;gap:8px;align-items:center}
	.sb-result p{margin:0 0 10px}
	.sb-result p:last-child{margin-bottom:0}
	.sb-bundle{background:var(--paper);border:1px solid var(--rule);padding:12px;margin:10px 0;
		font-size:12px;line-height:1.5;white-space:pre-wrap;overflow-wrap:anywhere;max-height:34em;overflow:auto}
	.sb-paste>summary{cursor:pointer;font-size:13px;color:var(--ink-60);padding:4px 0}
	.sb-paste>summary:hover{color:var(--ink)}

	/* ── Confirmation, scopes, messages ───────────────────────────────── */
	/* Inline confirmation. With no script the box is simply already open and
	   the plain button beside it never appears, so the form still submits. */
	.sb-confirm{margin:0}
	.sb-confirm__box{border-left:4px solid var(--v);background:var(--paper);padding:12px 14px;margin-top:10px}
	.sb-confirm__box p{margin:0 0 10px}

	.sb-msg{padding:11px 14px;border-left:4px solid var(--ink-60);background:#fff;border-top:1px solid var(--ink);
		border-right:1px solid var(--ink);border-bottom:1px solid var(--ink);margin:0 0 14px;
		display:flex;gap:12px;align-items:baseline;flex-wrap:wrap}
	.sb-msg--ok{border-left-color:var(--m)} .sb-msg--warn{border-left-color:var(--a)}
	.sb-msg--bad{border-left-color:var(--v)}

	.sb-scopes{list-style:none;margin:0 0 14px;padding:0}
	.sb-scopes li{margin:0 0 8px}
	.sb-scope{align-items:flex-start;line-height:1.45}
	/* Square corners and an ink border, and then core\'s own checked state
	   left exactly alone: core fills the box with the admin theme colour and
	   draws a white tick on it, so forcing the background white made the tick
	   invisible and every ticked permission read as unticked. */
	.sb-scope input[type=checkbox]{margin:2px 0 0;flex:0 0 auto;border-color:var(--ink);border-radius:0;min-height:0}
	.sb-scope input[type=checkbox]:focus{box-shadow:0 0 0 1px var(--ink)}
	.sb-scope .sb-note{margin-left:4px}
	.sb-inline{display:inline-flex;align-items:center;gap:8px;font-size:14px}
	.sendbeam-app [hidden]{display:none!important}

	/* ── Pop-up rules ─────────────────────────────────────────────────── */
	.sb-rule{border:1px solid var(--ink);background:var(--paper);padding:14px 16px;margin:0 0 14px}
	.sb-rule legend{padding:0 6px;background:var(--ink);color:var(--paper);font-size:12px;font-weight:600}
	.sb-rule__grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:14px;align-items:end}
	.sb-rule__grid label{display:block}
	.sb-rule__grid select,.sb-rule__grid input[type=text]{width:100%;max-width:100%}
	.sb-rule__foot{display:flex;align-items:center;justify-content:space-between;gap:12px;
		margin-top:14px;padding-top:12px;border-top:1px solid var(--rule)}

	/* ── Help ─────────────────────────────────────────────────────────── */
	.sb-doc h3{font-size:15px;margin:0 0 6px}
	.sb-doc p{margin:0 0 10px;max-width:62ch}
	.sb-doc ul{margin:0 0 10px 1.1em;list-style:disc}
	.sb-doc li{margin:4px 0}
	.sb-doc code{font-size:12px;background:var(--paper);padding:2px 5px;border:1px solid var(--rule)}
	.sb-doc__row{display:flex;gap:12px;align-items:center;justify-content:space-between;
		padding:9px 0;border-bottom:1px solid var(--hair);flex-wrap:wrap}
	.sb-doc__row:last-child{border-bottom:0}
	.sb-doc__row code{flex:1 1 22em;min-width:0;overflow-wrap:anywhere}

	/* ── Phones ───────────────────────────────────────────────────────── */
	@media (max-width:782px){
		.sendbeam-app{margin:10px}
		.sb-head{padding:14px}
		.sb-mark{margin-right:36px}
		.sb-wrap{padding-top:16px}
		.sb-card__body{padding:14px}
		.sb-rail__step{flex:1 1 100%;border-right:0;border-bottom:1px solid var(--hair);padding:10px 14px}
		.sb-rail__step:last-child{border-bottom:0}
		.sb-wizard__foot .sb-btn{flex:1 1 100%}
		.sb-doc__row code{flex:1 1 100%}
		.sb-stats{grid-template-columns:1fr;gap:10px}
		.sb-stats .sb-fig{font-size:28px}

		/* The records, stacked. Four columns squeezed into 393px gave the
		   host and the value about ninety pixels each, which is enough to
		   read "resend._dc" and nothing else — and reading them is the whole
		   job on this screen. Each record becomes a block: what it is and
		   whether it is live on one line, then the host, then the value,
		   each the full width of the card and each free to wrap. */
		.sb-dns,.sb-dns thead,.sb-dns tbody,.sb-dns tr,.sb-dns td{display:block;width:auto}
		.sb-dns thead{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}
		.sb-dns tr{display:grid;grid-template-columns:1fr auto;gap:2px 10px;
			padding:12px;border-bottom:1px solid var(--ink)}
		.sb-dns tr:last-child{border-bottom:0}
		/* The desktop column widths are more specific than the `display:block`
		   above, so they have to be undone by name or the host field keeps
		   its 30% and sits at a third of the card. */
		.sb-dns td,.sb-dns td.sb-dns__type,.sb-dns td.sb-dns__host,
		.sb-dns td.sb-dns__found{width:auto;padding:0;border:0}
		.sb-dns td.sb-dns__type{grid-column:1;grid-row:1;font-weight:700;font-size:13px}
		.sb-dns td.sb-dns__found{grid-column:2;grid-row:1;text-align:right}
		.sb-dns td.sb-dns__host,.sb-dns td.sb-dns__value{grid-column:1/-1;margin-top:8px}
		/* The header row is gone, so each field says which one it is. */
		.sb-dns td.sb-dns__host::before,.sb-dns td.sb-dns__value::before{
			content:attr(data-label);display:block;font-size:12px;font-weight:600;color:var(--ink-60);margin:0 0 3px}
		.sendbeam-app .sb-dns .sb-copy-field{white-space:pre-wrap;word-break:break-all;overflow:hidden}
	}
	';
}
