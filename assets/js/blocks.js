/* CigalPay in the block-based checkout. Plain script, no build step. */
( function () {
	const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
	const { getSetting } = window.wc.wcSettings;
	const { createElement: el } = window.wp.element;
	const { decodeEntities } = window.wp.htmlEntities;

	const settings = getSetting( 'cigalpay_data', {} );
	const title = decodeEntities( settings.title || 'Crypto' );
	const description = decodeEntities( settings.description || '' );

	const Label = () =>
		el(
			'span',
			{ style: { display: 'inline-flex', alignItems: 'center', gap: '8px', width: '100%' } },
			el( 'span', null, title ),
			el(
				'span',
				{ style: { display: 'inline-flex', gap: '4px', marginLeft: 'auto' } },
				( settings.icons || [] ).map( ( icon ) =>
					el( 'img', { key: icon.src, src: icon.src, alt: icon.alt, width: 24, height: 24, style: { width: 24, height: 24 } } )
				)
			)
		);

	const Content = () => el( 'p', null, description );

	registerPaymentMethod( {
		name: 'cigalpay',
		label: el( Label ),
		content: el( Content ),
		edit: el( Content ),
		canMakePayment: () => true,
		ariaLabel: title,
		supports: { features: settings.supports || [ 'products' ] },
	} );
} )();
