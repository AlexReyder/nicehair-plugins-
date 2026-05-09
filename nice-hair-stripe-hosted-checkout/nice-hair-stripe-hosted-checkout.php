<?php
/**
 * Plugin Name: Nice Hair Stripe Hosted Checkout
 * Description: Adds a Stripe-hosted checkout flow for WooCommerce orders using Checkout Sessions tied to individual orders.
 * Version: 1.0.0
 * Author: Nice Hair
 * Requires Plugins: woocommerce, woocommerce-gateway-stripe
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'NH_STRIPE_HOSTED_CHECKOUT_VERSION', '1.0.0' );
define( 'NH_STRIPE_HOSTED_CHECKOUT_FILE', __FILE__ );
define( 'NH_STRIPE_HOSTED_CHECKOUT_PATH', plugin_dir_path( __FILE__ ) );
define( 'NH_STRIPE_HOSTED_CHECKOUT_URL', plugin_dir_url( __FILE__ ) );

require_once NH_STRIPE_HOSTED_CHECKOUT_PATH . 'includes/class-nh-stripe-hosted-api.php';
require_once NH_STRIPE_HOSTED_CHECKOUT_PATH . 'includes/class-nh-stripe-hosted-session-manager.php';
require_once NH_STRIPE_HOSTED_CHECKOUT_PATH . 'includes/class-nh-stripe-hosted-webhook-controller.php';
require_once NH_STRIPE_HOSTED_CHECKOUT_PATH . 'includes/class-nh-stripe-hosted-checkout-plugin.php';

register_activation_hook(
	__FILE__,
	static function () {
		$option_name = 'woocommerce_nh_stripe_hosted_checkout_settings';
		$current     = get_option( $option_name, [] );

		if ( ! is_array( $current ) ) {
			$current = [];
		}

		$defaults = [
			'enabled'              => 'yes',
			'title'                => __( 'Pay via Stripe', 'nice-hair' ),
			'description'          => __( 'You will be redirected to Stripe to securely complete your payment.', 'nice-hair' ),
			'order_button_text'    => __( 'Continue to Stripe', 'nice-hair' ),
			'hide_official_stripe' => 'yes',
		];

		update_option( $option_name, wp_parse_args( $current, $defaults ), false );
	}
);

add_action(
	'plugins_loaded',
	static function () {
		NH_Stripe_Hosted_Checkout_Plugin::instance();
	},
	20
);
