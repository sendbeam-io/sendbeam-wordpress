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
	var RangeControl = wp.components.RangeControl;
	var Placeholder = wp.components.Placeholder;
	var ExternalLink = wp.components.ExternalLink;
	var ServerSideRender = wp.serverSideRender;
	var __ = wp.i18n.__;
	var UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
	var config = window.sendbeamBlock || {};

	registerBlockType( 'sendbeam/form', {
		edit: function ( props ) {
			var a = props.attributes;
			var set = props.setAttributes;
			var effectiveId = a.formId || config.defaultForm || '';
			var idField = el( TextControl, {
				label: __( 'Form ID', 'sendbeam' ),
				value: a.formId,
				placeholder: config.defaultForm ? __( 'Default form (from Settings → SendBeam)', 'sendbeam' ) : '8f3c1a2e-0000-4000-8000-000000000000',
				onChange: function ( v ) { set( { formId: v.trim().toLowerCase() } ); },
				help: __( 'From SendBeam → Forms → your form → Embed. Leave empty to use the default form.', 'sendbeam' ),
				__nextHasNoMarginBottom: true,
			} );
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
