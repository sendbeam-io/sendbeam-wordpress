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
	var ServerSideRender = wp.serverSideRender;
	var __ = wp.i18n.__;
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
			var body;
			if ( UUID.test( effectiveId ) ) {
				body = el( ServerSideRender, { block: 'sendbeam/form', attributes: a } );
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
