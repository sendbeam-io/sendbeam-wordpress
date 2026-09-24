/**
 * SendBeam Form block — editor side. Plain wp.* globals, no build step.
 */
( function ( wp ) {
	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var registerBlockType = wp.blocks.registerBlockType;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var PanelBody = wp.components.PanelBody;
	var TextControl = wp.components.TextControl;
	var SelectControl = wp.components.SelectControl;
	var RangeControl = wp.components.RangeControl;
	var Placeholder = wp.components.Placeholder;
	var ExternalLink = wp.components.ExternalLink;
	var __ = wp.i18n.__;
	var sprintf = wp.i18n.sprintf;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var apiFetch = wp.apiFetch;
	var UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
	var config = window.sendbeamBlock || {};

	registerBlockType( 'sendbeam/form', {
		edit: function ( props ) {
			var a = props.attributes;
			var set = props.setAttributes;
			var effectiveId = a.formId || config.defaultForm || '';
			// Ask WordPress (which asks SendBeam) for this workspace's forms, so a
			// form is chosen by name. Falls back to the ID field when the site's
			// key cannot list them — the block must keep working either way.
			var forms = useState( null );
			var setForms = forms[ 1 ];
			forms = forms[ 0 ];
			useEffect( function () {
				var live = true;
				apiFetch( { path: '/sendbeam/v1/forms' } ).then( function ( r ) {
					if ( live ) { setForms( r && r.available ? r.forms : [] ); }
				} ).catch( function () { if ( live ) { setForms( [] ); } } );
				return function () { live = false; };
			}, [] );

			var idField;
			if ( forms && forms.length ) {
				var options = [ {
					label: config.defaultForm ? __( 'Default form (from Settings → SendBeam)', 'sendbeam' ) : __( '— choose a form —', 'sendbeam' ),
					value: '',
				} ];
				forms.forEach( function ( f ) {
					options.push( { label: f.name + ( 'contact' === f.kind ? ' — ' + __( 'contact', 'sendbeam' ) : '' ), value: f.id } );
				} );
				if ( a.formId && ! forms.some( function ( f ) { return f.id === a.formId; } ) ) {
					options.push( { label: __( 'Saved form', 'sendbeam' ) + ' ' + a.formId.slice( 0, 8 ), value: a.formId } );
				}
				idField = el( SelectControl, {
					label: __( 'SendBeam form', 'sendbeam' ),
					value: a.formId,
					options: options,
					onChange: function ( v ) { set( { formId: v } ); },
					__nextHasNoMarginBottom: true,
				} );
			} else {
				idField = el( TextControl, {
					label: __( 'Form ID', 'sendbeam' ),
					value: a.formId,
					placeholder: config.defaultForm ? __( 'Default form (from Settings → SendBeam)', 'sendbeam' ) : '8f3c1a2e-0000-4000-8000-000000000000',
					onChange: function ( v ) { set( { formId: v.trim().toLowerCase() } ); },
					help: __( 'From SendBeam → Forms → your form → Embed. Leave empty to use the default form.', 'sendbeam' ),
					__nextHasNoMarginBottom: true,
				} );
			}
			/**
			 * The three-square mark, as three divs. No image request, and it
			 * survives an editor that has not loaded the plugin's admin CSS.
			 */
			function mark() {
				return el( 'div', { style: { display: 'flex', gap: '5px' }, 'aria-hidden': 'true' },
					[ '#E2442A', '#1F3FBF', '#3B7D46' ].map( function ( colour, i ) {
						return el( 'div', { key: i, style: { width: '12px', height: '12px', background: colour } } );
					} )
				);
			}

			/** What this block is pointing at, in words. */
			function chosen() {
				var match = ( forms || [] ).filter( function ( f ) { return f.id === effectiveId; } )[ 0 ];
				if ( ! match ) {
					return null;
				}
				return 'contact' === match.kind
					/* translators: %s: the form's name */
					? sprintf( __( '%s — contact form', 'sendbeam' ), match.name )
					/* translators: %s: the form's name */
					: sprintf( __( '%s — signup form', 'sendbeam' ), match.name );
			}

			var body;
			if ( UUID.test( effectiveId ) ) {
				/*
				 * A placeholder card, not the form itself.
				 *
				 * The form is a cross-origin iframe, and SendBeam only serves
				 * it to the sites its owner has listed — which it decides from
				 * the referrer. The block editor renders inside a nested
				 * iframe with an opaque origin, so there is no referrer to
				 * send and the hosted page answers "This form can only be
				 * shown on the sites its owner has listed" on a site that is
				 * listed. Loosening that check for a missing referrer would
				 * let anybody embed the form with referrerpolicy=no-referrer,
				 * so the editor stops asking: it says what will be on the page
				 * and offers to open the real thing in a tab, where there is a
				 * referrer. The published page is unchanged.
				 */
				var name = chosen();
				body = el( 'div', {
					style: {
						border: '1px solid #1e1e1e',
						background: '#fff',
						padding: '20px 22px',
						display: 'flex',
						flexDirection: 'column',
						gap: '10px',
					},
				},
					el( 'div', { style: { display: 'flex', alignItems: 'center', gap: '10px' } },
						mark(),
						el( 'strong', { style: { fontSize: '14px' } }, __( 'SendBeam form', 'sendbeam' ) )
					),
					el( 'div', { style: { fontSize: '14px', color: '#1e1e1e' } },
						name || ( a.formId ? a.formId : __( 'Your default form', 'sendbeam' ) )
					),
					el( 'div', { style: { fontSize: '13px', color: '#646970' } },
						__( 'The form appears here on the published page.', 'sendbeam' )
					),
					config.previewBase
						? el( ExternalLink, { href: config.previewBase + effectiveId }, __( 'Preview', 'sendbeam' ) )
						: null
				);
			} else {
				body = el( Placeholder, {
					icon: 'email-alt',
					label: __( 'SendBeam form', 'sendbeam' ),
					instructions: __( 'Paste a form ID, or set a default form under Settings → SendBeam.', 'sendbeam' ),
				}, el( 'div', { style: { width: '100%' } }, idField, config.formsUrl ? el( ExternalLink, { href: config.formsUrl }, __( 'Open your forms in SendBeam', 'sendbeam' ) ) : null ) );
			}
			return el( Fragment, null,
				el( InspectorControls, null,
					el( PanelBody, { title: __( 'Form', 'sendbeam' ) },
						idField,
						el( RangeControl, {
							label: __( 'Height (px)', 'sendbeam' ),
							value: a.height,
							min: 240,
							max: 1200,
							step: 10,
							onChange: function ( v ) { set( { height: v } ); },
							help: __( 'Enough to show every field without a scrollbar inside the form.', 'sendbeam' ),
							__nextHasNoMarginBottom: true,
						} )
					)
				),
				el( 'div', useBlockProps(), body )
			);
		},
		save: function () {
			return null; // Rendered by render.php.
		},
	} );
} )( window.wp );
