<?php

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( AbstractPaymentMethodType::class ) ) {
	class NH_Stripe_Hosted_Blocks_Support extends AbstractPaymentMethodType {
		/**
		 * @var string
		 */
		protected $name = NH_Stripe_Hosted_Gateway::ID;

		/**
		 * @var array<string,mixed>
		 */
		protected $settings = [];

		/**
		 * @var NH_Stripe_Hosted_Gateway|null
		 */
		protected $gateway = null;

		/**
		 * @return void
		 */
		public function initialize() {
			$this->settings = get_option( 'woocommerce_' . NH_Stripe_Hosted_Gateway::ID . '_settings', [] );

			if ( function_exists( 'WC' ) && WC()->payment_gateways() ) {
				$gateways      = WC()->payment_gateways()->payment_gateways();
				$this->gateway = isset( $gateways[ NH_Stripe_Hosted_Gateway::ID ] ) ? $gateways[ NH_Stripe_Hosted_Gateway::ID ] : null;
			}
		}

		/**
		 * @return bool
		 */
		public function is_active() {
			return ! isset( $this->settings['enabled'] ) || 'yes' === $this->settings['enabled'];
		}

		/**
		 * @return array<int,string>
		 */
		public function get_payment_method_script_handles() {
			$handle = 'nh-stripe-hosted-checkout-blocks';

			wp_register_script(
				$handle,
				NH_STRIPE_HOSTED_CHECKOUT_URL . 'assets/js/checkout-block.js',
				[ 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities' ],
				NH_STRIPE_HOSTED_CHECKOUT_VERSION,
				true
			);

			return [ $handle ];
		}

		/**
		 * @return array<int,string>
		 */
		public function get_payment_method_script_handles_for_admin() {
			return $this->get_payment_method_script_handles();
		}

		/**
		 * @return array<string,mixed>
		 */
		public function get_payment_method_data() {
			$title = isset( $this->settings['title'] ) ? (string) $this->settings['title'] : __( 'Pay via Stripe', 'nice-hair' );

			return [
				'title'               => $title,
				'description'         => isset( $this->settings['description'] ) ? (string) $this->settings['description'] : __( 'You will be redirected to Stripe to securely complete your payment.', 'nice-hair' ),
				'supports'            => $this->gateway ? $this->gateway->supports : [ 'products' ],
				'placeOrderButtonLabel' => isset( $this->settings['order_button_text'] ) ? (string) $this->settings['order_button_text'] : __( 'Continue to Stripe', 'nice-hair' ),
				'gatewayId'           => NH_Stripe_Hosted_Gateway::ID,
			];
		}
	}
}
