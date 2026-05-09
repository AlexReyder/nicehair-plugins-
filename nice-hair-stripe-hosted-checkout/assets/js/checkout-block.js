( function() {
	const registry = window.wc && window.wc.wcBlocksRegistry;
	const settingsApi = window.wc && window.wc.wcSettings;
	const elementApi = window.wp && window.wp.element;
	const entitiesApi = window.wp && window.wp.htmlEntities;

	if ( ! registry || ! settingsApi || ! elementApi ) {
		return;
	}

	const settings = settingsApi.getSetting( 'nh_stripe_hosted_checkout_data', {} );
	const createElement = elementApi.createElement;
	const decodeEntities = entitiesApi && entitiesApi.decodeEntities ? entitiesApi.decodeEntities : function( value ) {
		return value;
	};
	const title = decodeEntities( settings.title || 'Pay via Stripe' );
	const description = decodeEntities(
		settings.description || 'You will be redirected to Stripe to securely complete your payment.'
	);
	const buttonLabel = decodeEntities( settings.placeOrderButtonLabel || 'Continue to Stripe' );

	const Content = function() {
		return createElement(
			'div',
			{ className: 'nh-stripe-hosted-checkout__content' },
			description
		);
	};

	const Label = function( props ) {
		if ( props && props.components && props.components.PaymentMethodLabel ) {
			return createElement( props.components.PaymentMethodLabel, { text: title } );
		}

		return createElement( 'span', null, title );
	};

	registry.registerPaymentMethod( {
		name: 'nh_stripe_hosted_checkout',
		label: createElement( Label, null ),
		content: createElement( Content, null ),
		edit: createElement( Content, null ),
		canMakePayment: function() {
			return true;
		},
		ariaLabel: title,
		supports: {
			features: settings.supports || [ 'products' ],
		},
		placeOrderButtonLabel: buttonLabel,
	} );
} )();
