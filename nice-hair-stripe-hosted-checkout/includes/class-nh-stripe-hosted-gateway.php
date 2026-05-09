<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NH_Stripe_Hosted_Gateway extends WC_Payment_Gateway {
	const ID = 'nh_stripe_hosted_checkout';

	/**
	 * @var NH_Stripe_Hosted_Api
	 */
	protected $api;

	/**
	 * @var NH_Stripe_Hosted_Session_Manager
	 */
	protected $session_manager;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id                 = self::ID;
		$this->method_title       = __( 'NH Stripe Hosted Checkout', 'nice-hair' );
		$this->method_description = __( 'Redirects the customer to a Stripe-hosted payment page tied to the WooCommerce order.', 'nice-hair' );
		$this->has_fields         = false;
		$this->supports           = [ 'products' ];

		$this->api             = NH_Stripe_Hosted_Checkout_Plugin::instance()->get_api();
		$this->session_manager = NH_Stripe_Hosted_Checkout_Plugin::instance()->get_session_manager();

		$this->init_form_fields();
		$this->init_settings();

		$this->title                 = (string) $this->get_option( 'title', __( 'Pay via Stripe', 'nice-hair' ) );
		$this->description           = (string) $this->get_option( 'description', __( 'You will be redirected to Stripe to securely complete your payment.', 'nice-hair' ) );
		$this->enabled               = (string) $this->get_option( 'enabled', 'yes' );
		$this->order_button_text     = (string) $this->get_option( 'order_button_text', __( 'Continue to Stripe', 'nice-hair' ) );
		$this->method_description   .= '<br><strong>' . esc_html__( 'Webhook URL:', 'nice-hair' ) . '</strong> ' . esc_url( NH_Stripe_Hosted_Checkout_Plugin::instance()->get_webhook_url() );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );
	}

	/**
	 * @return void
	 */
	public function init_form_fields() {
		$this->form_fields = [
			'enabled' => [
				'title'   => __( 'Enable/Disable', 'nice-hair' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable NH Stripe Hosted Checkout', 'nice-hair' ),
				'default' => 'yes',
			],
			'title'   => [
				'title'       => __( 'Title', 'nice-hair' ),
				'type'        => 'text',
				'default'     => __( 'Pay via Stripe', 'nice-hair' ),
				'description' => __( 'Shown to customers at checkout.', 'nice-hair' ),
				'desc_tip'    => true,
			],
			'description' => [
				'title'       => __( 'Description', 'nice-hair' ),
				'type'        => 'textarea',
				'default'     => __( 'You will be redirected to Stripe to securely complete your payment.', 'nice-hair' ),
				'description' => __( 'Shown below the payment method on checkout.', 'nice-hair' ),
				'desc_tip'    => true,
			],
			'order_button_text' => [
				'title'       => __( 'Place order button text', 'nice-hair' ),
				'type'        => 'text',
				'default'     => __( 'Continue to Stripe', 'nice-hair' ),
				'description' => __( 'Used by the block checkout integration when supported.', 'nice-hair' ),
				'desc_tip'    => true,
			],
			'hide_official_stripe' => [
				'title'       => __( 'Hide official Stripe methods', 'nice-hair' ),
				'type'        => 'checkbox',
				'label'       => __( 'Hide official Stripe payment methods on checkout when this gateway is enabled', 'nice-hair' ),
				'default'     => 'yes',
				'description' => __( 'Prevents customers from seeing both embedded Stripe and hosted Stripe options at the same time.', 'nice-hair' ),
				'desc_tip'    => true,
			],
		];
	}

	/**
	 * @return void
	 */
	public function payment_fields() {
		if ( $this->description ) {
			echo wp_kses_post( wpautop( $this->description ) );
		}
	}

	/**
	 * @return bool
	 */
	public function is_available() {
		return parent::is_available() && $this->api->is_configured();
	}

	/**
	 * @param int $order_id
	 * @return array<string,string>|void
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order ) {
			wc_add_notice( __( 'Unable to create a Stripe payment session for this order.', 'nice-hair' ), 'error' );
			return;
		}

		$session = $this->session_manager->get_or_create_checkout_session( $order );

		if ( is_wp_error( $session ) ) {
			wc_add_notice( $session->get_error_message(), 'error' );
			return;
		}

		$order->set_payment_method( $this->id );
		$order->set_payment_method_title( $this->title );
		$order->save();

		return [
			'result'   => 'success',
			'redirect' => $session['checkout_url'],
		];
	}
}
