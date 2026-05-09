<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NH_Stripe_Hosted_Checkout_Plugin {
	/**
	 * @var NH_Stripe_Hosted_Checkout_Plugin|null
	 */
	protected static $instance = null;

	/**
	 * @var NH_Stripe_Hosted_Api
	 */
	protected $api;

	/**
	 * @var NH_Stripe_Hosted_Session_Manager
	 */
	protected $session_manager;

	/**
	 * @var NH_Stripe_Hosted_Webhook_Controller
	 */
	protected $webhook_controller;

	/**
	 * @return NH_Stripe_Hosted_Checkout_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	protected function __construct() {
		if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'WC_Payment_Gateway' ) ) {
			return;
		}

		require_once NH_STRIPE_HOSTED_CHECKOUT_PATH . 'includes/class-nh-stripe-hosted-gateway.php';

		$this->api                = new NH_Stripe_Hosted_Api();
		$this->session_manager    = new NH_Stripe_Hosted_Session_Manager( $this->api );
		$this->webhook_controller = new NH_Stripe_Hosted_Webhook_Controller( $this->api, $this->session_manager );

		add_filter( 'woocommerce_payment_gateways', [ $this, 'register_gateway' ] );
		add_filter( 'woocommerce_available_payment_gateways', [ $this, 'filter_available_payment_gateways' ], 100 );
		add_filter( 'option_woocommerce_stripe_settings', [ $this, 'filter_official_stripe_settings' ] );
		add_filter( 'wc_stripe_show_payment_request_on_checkout', [ $this, 'hide_stripe_express_checkout_on_checkout' ], 100 );
		add_action( 'wp_enqueue_scripts', [ $this, 'dequeue_official_stripe_checkout_assets' ], 999 );
		add_action( 'rest_api_init', [ $this->webhook_controller, 'register_routes' ] );
		add_action( 'add_meta_boxes', [ $this, 'register_order_meta_boxes' ] );
		add_action( 'add_meta_boxes_woocommerce_page_wc-orders', [ $this, 'register_order_meta_boxes' ] );
		add_action( 'admin_post_nh_regenerate_stripe_checkout', [ $this, 'handle_admin_regenerate_checkout_link' ] );
		add_action( 'template_redirect', [ $this, 'handle_frontend_order_return' ] );

		if ( class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
			require_once NH_STRIPE_HOSTED_CHECKOUT_PATH . 'includes/class-nh-stripe-hosted-blocks-support.php';
			add_action( 'woocommerce_blocks_payment_method_type_registration', [ $this, 'register_blocks_support' ] );
		}
	}

	/**
	 * @return NH_Stripe_Hosted_Api
	 */
	public function get_api() {
		return $this->api;
	}

	/**
	 * @return NH_Stripe_Hosted_Session_Manager
	 */
	public function get_session_manager() {
		return $this->session_manager;
	}

	/**
	 * @return string
	 */
	public function get_webhook_url() {
		return rest_url( 'nh-stripe-hosted/v1/webhook' );
	}

	/**
	 * @param array<int,string> $gateways
	 * @return array<int,string>
	 */
	public function register_gateway( $gateways ) {
		$gateways[] = NH_Stripe_Hosted_Gateway::class;

		return $gateways;
	}

	/**
	 * @param array<string,WC_Payment_Gateway> $gateways
	 * @return array<string,WC_Payment_Gateway>
	 */
	public function filter_available_payment_gateways( $gateways ) {
		if ( ! $this->should_hide_official_stripe_gateways() ) {
			return $gateways;
		}

		foreach ( array_keys( $gateways ) as $gateway_id ) {
			if ( NH_Stripe_Hosted_Gateway::ID === $gateway_id ) {
				continue;
			}

			if ( 'stripe' === $gateway_id || 0 === strpos( $gateway_id, 'stripe_' ) ) {
				unset( $gateways[ $gateway_id ] );
			}
		}

		return $gateways;
	}

	/**
	 * @param mixed $show
	 * @return bool
	 */
	public function hide_stripe_express_checkout_on_checkout( $show ) {
		if ( $this->should_hide_official_stripe_gateways() && is_checkout() ) {
			return false;
		}

		return (bool) $show;
	}

	/**
	 * @param mixed $settings
	 * @return array<string,mixed>|mixed
	 */
	public function filter_official_stripe_settings( $settings ) {
		if ( ! $this->should_hide_official_stripe_gateways() || ! is_array( $settings ) ) {
			return $settings;
		}

		$is_checkout_like_request = false;

		if ( function_exists( 'is_checkout' ) && ( is_checkout() || is_wc_endpoint_url( 'order-pay' ) ) ) {
			$is_checkout_like_request = true;
		}

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';

		if ( false !== strpos( $request_uri, '/wc/store/v1/checkout' ) ) {
			$is_checkout_like_request = true;
		}

		if ( ! $is_checkout_like_request ) {
			return $settings;
		}

		$settings['enabled']                          = 'no';
		$settings['express_checkout']                 = 'no';
		$settings['upe_checkout_experience_enabled']  = 'no';
		$settings['express_checkout_button_locations'] = [];

		return $settings;
	}

	/**
	 * @param Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $registry
	 * @return void
	 */
	public function register_blocks_support( $registry ) {
		if ( class_exists( 'NH_Stripe_Hosted_Blocks_Support' ) ) {
			$registry->register( new NH_Stripe_Hosted_Blocks_Support() );
		}
	}

	/**
	 * @return void
	 */
	public function dequeue_official_stripe_checkout_assets() {
		if ( ! $this->should_hide_official_stripe_gateways() ) {
			return;
		}

		if ( ! ( is_checkout() || is_wc_endpoint_url( 'order-pay' ) ) ) {
			return;
		}

		wp_dequeue_script( 'wc-stripe-blocks-integration' );
		wp_dequeue_script( 'wc-stripe-upe-classic' );
		wp_dequeue_style( 'wc-stripe-blocks-checkout-style' );
	}

	/**
	 * @return void
	 */
	public function register_order_meta_boxes() {
		add_meta_box(
			'nh-stripe-hosted-checkout',
			__( 'Stripe Hosted Checkout', 'nice-hair' ),
			[ $this, 'render_order_meta_box' ],
			'shop_order',
			'side',
			'default'
		);

		add_meta_box(
			'nh-stripe-hosted-checkout',
			__( 'Stripe Hosted Checkout', 'nice-hair' ),
			[ $this, 'render_order_meta_box' ],
			'woocommerce_page_wc-orders',
			'side',
			'default'
		);
	}

	/**
	 * @param WC_Order|WP_Post $order_or_post
	 * @return void
	 */
	public function render_order_meta_box( $order_or_post ) {
		$order = $order_or_post instanceof WC_Order ? $order_or_post : wc_get_order( $order_or_post );

		if ( ! $order instanceof WC_Order ) {
			echo '<p>' . esc_html__( 'Order not found.', 'nice-hair' ) . '</p>';
			return;
		}

		$session = $this->session_manager->get_session_data( $order );

		if ( NH_Stripe_Hosted_Gateway::ID !== $order->get_payment_method() && empty( $session['session_id'] ) ) {
			echo '<p>' . esc_html__( 'This order is not using NH Stripe Hosted Checkout.', 'nice-hair' ) . '</p>';
			return;
		}

		$regenerate_url = wp_nonce_url(
			add_query_arg(
				[
					'action'   => 'nh_regenerate_stripe_checkout',
					'order_id' => $order->get_id(),
				],
				admin_url( 'admin-post.php' )
			),
			'nh_regenerate_stripe_checkout_' . $order->get_id()
		);

		echo '<p><strong>' . esc_html__( 'Session status:', 'nice-hair' ) . '</strong> ' . esc_html( $session['status'] ? $session['status'] : __( 'Not created yet', 'nice-hair' ) ) . '</p>';

		if ( ! empty( $session['session_id'] ) ) {
			echo '<p><strong>' . esc_html__( 'Session ID:', 'nice-hair' ) . '</strong><br>' . esc_html( $session['session_id'] ) . '</p>';
		}

		if ( ! empty( $session['payment_intent'] ) ) {
			echo '<p><strong>' . esc_html__( 'Payment Intent:', 'nice-hair' ) . '</strong><br>' . esc_html( $session['payment_intent'] ) . '</p>';
		}

		if ( ! empty( $session['expires_at'] ) ) {
			echo '<p><strong>' . esc_html__( 'Expires:', 'nice-hair' ) . '</strong> ' . esc_html( wp_date( 'Y-m-d H:i:s', (int) $session['expires_at'] ) ) . '</p>';
		}

		if ( ! empty( $session['checkout_url'] ) ) {
			echo '<p><label for="nh-stripe-hosted-checkout-url"><strong>' . esc_html__( 'Payment link', 'nice-hair' ) . '</strong></label></p>';
			echo '<input type="text" readonly id="nh-stripe-hosted-checkout-url" value="' . esc_attr( $session['checkout_url'] ) . '" style="width:100%;margin-bottom:8px;" />';
			echo '<p style="display:flex;gap:8px;flex-wrap:wrap;">';
			echo '<button type="button" class="button" id="nh-copy-stripe-hosted-checkout-url">' . esc_html__( 'Copy payment link', 'nice-hair' ) . '</button>';
			echo '<a class="button button-secondary" href="' . esc_url( $session['checkout_url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Open link', 'nice-hair' ) . '</a>';
			echo '</p>';
			echo '<script>document.addEventListener("click",function(e){if(e.target&&e.target.id==="nh-copy-stripe-hosted-checkout-url"){var input=document.getElementById("nh-stripe-hosted-checkout-url");if(input){input.select();input.setSelectionRange(0,99999);navigator.clipboard&&navigator.clipboard.writeText?navigator.clipboard.writeText(input.value):document.execCommand("copy");e.target.textContent="' . esc_js( __( 'Copied', 'nice-hair' ) ) . '";setTimeout(function(){e.target.textContent="' . esc_js( __( 'Copy payment link', 'nice-hair' ) ) . '";},1500);}}});</script>';
		}

		if ( ! $order->is_paid() ) {
			echo '<p><a class="button button-primary" href="' . esc_url( $regenerate_url ) . '">' . esc_html__( 'Regenerate payment link', 'nice-hair' ) . '</a></p>';
		}
	}

	/**
	 * @return void
	 */
	public function handle_admin_regenerate_checkout_link() {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'You are not allowed to regenerate payment links.', 'nice-hair' ) );
		}

		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;

		if ( $order_id < 1 ) {
			wp_die( esc_html__( 'Order not found.', 'nice-hair' ) );
		}

		check_admin_referer( 'nh_regenerate_stripe_checkout_' . $order_id );

		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order ) {
			wp_die( esc_html__( 'Order not found.', 'nice-hair' ) );
		}

		if ( $order->is_paid() ) {
			wp_safe_redirect( $this->get_order_edit_url( $order_id ) );
			exit;
		}

		$session = $this->session_manager->get_or_create_checkout_session( $order, true );

		if ( is_wp_error( $session ) ) {
			$order->add_order_note(
				sprintf(
					/* translators: %s error message. */
					__( 'Stripe hosted checkout link regeneration failed: %s', 'nice-hair' ),
					$session->get_error_message()
				)
			);
		}

		wp_safe_redirect( $this->get_order_edit_url( $order_id ) );
		exit;
	}

	/**
	 * @return void
	 */
	public function handle_frontend_order_return() {
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		if ( is_wc_endpoint_url( 'order-pay' ) && isset( $_GET['nh_stripe_checkout'] ) && 'cancel' === sanitize_text_field( wp_unslash( $_GET['nh_stripe_checkout'] ) ) ) {
			wc_add_notice( __( 'Stripe payment was cancelled. You can try again when you are ready.', 'nice-hair' ), 'notice' );
		}

		if ( ! is_wc_endpoint_url( 'order-received' ) || empty( $_GET['nh_stripe_checkout_session'] ) ) {
			return;
		}

		$order_id = absint( get_query_var( 'order-received' ) );
		$order    = $order_id ? wc_get_order( $order_id ) : false;

		if ( ! $order instanceof WC_Order || NH_Stripe_Hosted_Gateway::ID !== $order->get_payment_method() || $order->is_paid() ) {
			return;
		}

		$session = $this->api->retrieve_checkout_session( sanitize_text_field( wp_unslash( $_GET['nh_stripe_checkout_session'] ) ) );

		if ( ! is_wp_error( $session ) && isset( $session['payment_status'] ) && 'paid' === $session['payment_status'] ) {
			$this->session_manager->mark_session_paid( $order, $session, 'return' );
		}
	}

	/**
	 * @return bool
	 */
	protected function should_hide_official_stripe_gateways() {
		$settings = get_option( 'woocommerce_' . NH_Stripe_Hosted_Gateway::ID . '_settings', [] );

		if ( ! is_array( $settings ) ) {
			return true;
		}

		$enabled              = isset( $settings['enabled'] ) ? $settings['enabled'] : 'yes';
		$hide_official_stripe = isset( $settings['hide_official_stripe'] ) ? $settings['hide_official_stripe'] : 'yes';

		return 'yes' === $enabled && 'yes' === $hide_official_stripe;
	}

	/**
	 * @param int $order_id
	 * @return string
	 */
	protected function get_order_edit_url( $order_id ) {
		if ( function_exists( 'wc_get_container' ) && class_exists( '\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController' ) ) {
			$controller = wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class );

			if ( $controller && $controller->custom_orders_table_usage_is_enabled() ) {
				return admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $order_id );
			}
		}

		return admin_url( 'post.php?post=' . $order_id . '&action=edit' );
	}
}
