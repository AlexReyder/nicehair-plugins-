<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NH_Stripe_Hosted_Session_Manager {
	const META_SESSION_ID       = 'nh_stripe_checkout_session_id';
	const META_SESSION_URL      = 'nh_stripe_checkout_url';
	const META_PAYMENT_INTENT   = 'nh_stripe_payment_intent_id';
	const META_EXPIRES_AT       = 'nh_stripe_checkout_expires_at';
	const META_SESSION_STATUS   = 'nh_stripe_checkout_status';
	const META_ORDER_HASH       = 'nh_stripe_checkout_order_hash';
	const META_LAST_EVENT_ID    = 'nh_stripe_checkout_last_event_id';

	/**
	 * @var NH_Stripe_Hosted_Api
	 */
	protected $api;

	/**
	 * @param NH_Stripe_Hosted_Api $api
	 */
	public function __construct( NH_Stripe_Hosted_Api $api ) {
		$this->api = $api;
	}

	/**
	 * @param WC_Order $order
	 * @param bool     $force_regenerate
	 * @return array<string,mixed>|WP_Error
	 */
	public function get_or_create_checkout_session( WC_Order $order, $force_regenerate = false ) {
		if ( $order->is_paid() ) {
			return new WP_Error( 'nh_stripe_order_already_paid', __( 'This order is already paid.', 'nice-hair' ) );
		}

		if ( ! $this->api->is_configured() ) {
			return new WP_Error( 'nh_stripe_not_configured', __( 'Stripe is not configured yet. Add Stripe API keys in WooCommerce > Payments > Stripe.', 'nice-hair' ) );
		}

		$current_hash = $this->build_order_hash( $order );
		$current      = $this->get_session_data( $order );

		if ( ! $force_regenerate && $this->is_session_reusable( $current, $current_hash ) ) {
			return $current;
		}

		if ( ! empty( $current['session_id'] ) && ! empty( $current['status'] ) && 'expired' !== $current['status'] && 'paid' !== $current['status'] ) {
			$this->api->expire_checkout_session( $current['session_id'] );
		}

		$payload         = $this->build_checkout_session_payload( $order );
		$idempotency_key = 'nh_order_' . $order->get_id() . '_checkout_' . $current_hash;
		$session         = $this->api->create_checkout_session( $payload, $idempotency_key );

		if ( is_wp_error( $session ) ) {
			return $session;
		}

		$stored = $this->sync_session_meta( $order, $session, 'open', $current_hash );
		$order->add_order_note(
			sprintf(
				/* translators: %s Stripe Checkout session id. */
				__( 'Stripe hosted checkout session created: %s', 'nice-hair' ),
				$stored['session_id']
			)
		);

		return $stored;
	}

	/**
	 * @param WC_Order $order
	 * @return array<string,mixed>
	 */
	public function get_session_data( WC_Order $order ) {
		return [
			'session_id'     => (string) $order->get_meta( self::META_SESSION_ID ),
			'checkout_url'   => (string) $order->get_meta( self::META_SESSION_URL ),
			'payment_intent' => (string) $order->get_meta( self::META_PAYMENT_INTENT ),
			'expires_at'     => (int) $order->get_meta( self::META_EXPIRES_AT ),
			'status'         => (string) $order->get_meta( self::META_SESSION_STATUS ),
			'order_hash'     => (string) $order->get_meta( self::META_ORDER_HASH ),
		];
	}

	/**
	 * @param WC_Order            $order
	 * @param array<string,mixed> $session
	 * @param string              $status
	 * @param string|null         $order_hash
	 * @return array<string,mixed>
	 */
	public function sync_session_meta( WC_Order $order, array $session, $status = '', $order_hash = null ) {
		$session_id     = isset( $session['id'] ) ? (string) $session['id'] : '';
		$checkout_url   = isset( $session['url'] ) ? (string) $session['url'] : (string) $order->get_meta( self::META_SESSION_URL );
		$payment_intent = isset( $session['payment_intent'] ) && is_string( $session['payment_intent'] ) ? $session['payment_intent'] : (string) $order->get_meta( self::META_PAYMENT_INTENT );
		$expires_at     = isset( $session['expires_at'] ) ? (int) $session['expires_at'] : (int) $order->get_meta( self::META_EXPIRES_AT );
		$session_status = '' !== $status ? $status : ( isset( $session['status'] ) ? (string) $session['status'] : (string) $order->get_meta( self::META_SESSION_STATUS ) );

		$order->update_meta_data( self::META_SESSION_ID, $session_id );
		$order->update_meta_data( self::META_SESSION_URL, $checkout_url );
		$order->update_meta_data( self::META_PAYMENT_INTENT, $payment_intent );
		$order->update_meta_data( self::META_EXPIRES_AT, $expires_at );
		$order->update_meta_data( self::META_SESSION_STATUS, $session_status );
		$order->update_meta_data( self::META_ORDER_HASH, null !== $order_hash ? $order_hash : $this->build_order_hash( $order ) );
		$order->save();

		return $this->get_session_data( $order );
	}

	/**
	 * @param WC_Order            $order
	 * @param array<string,mixed> $session
	 * @param string              $source
	 * @return void
	 */
	public function mark_session_paid( WC_Order $order, array $session, $source = 'webhook' ) {
		$this->sync_session_meta( $order, $session, 'paid' );

		if ( ! $order->is_paid() ) {
			$transaction_id = isset( $session['payment_intent'] ) && is_string( $session['payment_intent'] ) ? $session['payment_intent'] : ( isset( $session['id'] ) ? (string) $session['id'] : '' );
			$order->payment_complete( $transaction_id );
		}

		$order->add_order_note(
			sprintf(
				/* translators: 1: data source, 2: session id, 3: payment intent id. */
				__( 'Stripe hosted checkout marked paid via %1$s. Session: %2$s. Payment intent: %3$s.', 'nice-hair' ),
				$source,
				isset( $session['id'] ) ? (string) $session['id'] : '',
				isset( $session['payment_intent'] ) && is_string( $session['payment_intent'] ) ? $session['payment_intent'] : ''
			)
		);
	}

	/**
	 * @param WC_Order            $order
	 * @param array<string,mixed> $session
	 * @param string              $status
	 * @param string              $note
	 * @return void
	 */
	public function mark_session_state( WC_Order $order, array $session, $status, $note = '' ) {
		$this->sync_session_meta( $order, $session, $status );

		if ( '' !== $note ) {
			$order->add_order_note( $note );
		}
	}

	/**
	 * @param WC_Order $order
	 * @return string
	 */
	public function build_order_hash( WC_Order $order ) {
		$signature = [
			$order->get_currency(),
			wc_format_decimal( $order->get_total(), wc_get_price_decimals() ),
			wc_format_decimal( $order->get_total_tax(), wc_get_price_decimals() ),
			wc_format_decimal( $order->get_shipping_total(), wc_get_price_decimals() ),
			wc_format_decimal( $order->get_discount_total(), wc_get_price_decimals() ),
			(string) $order->get_item_count(),
			(string) $order->get_order_key(),
		];

		return sha1( implode( '|', $signature ) );
	}

	/**
	 * @param array<string,mixed> $session
	 * @param string              $current_hash
	 * @return bool
	 */
	protected function is_session_reusable( array $session, $current_hash ) {
		if ( empty( $session['session_id'] ) || empty( $session['checkout_url'] ) ) {
			return false;
		}

		if ( ! empty( $session['order_hash'] ) && $current_hash !== $session['order_hash'] ) {
			return false;
		}

		if ( ! empty( $session['status'] ) && in_array( $session['status'], [ 'expired', 'paid', 'complete' ], true ) ) {
			return false;
		}

		if ( ! empty( $session['expires_at'] ) && (int) $session['expires_at'] <= ( time() + MINUTE_IN_SECONDS ) ) {
			return false;
		}

		return true;
	}

	/**
	 * @param WC_Order $order
	 * @return array<string,mixed>
	 */
	protected function build_checkout_session_payload( WC_Order $order ) {
		$currency     = strtolower( $order->get_currency() );
		$amount       = $this->amount_to_minor_units( (float) $order->get_total(), $currency );
		$order_number = $order->get_order_number();
		$success_url  = add_query_arg(
			[
				'nh_stripe_checkout'         => 'success',
				'nh_stripe_checkout_session' => '{CHECKOUT_SESSION_ID}',
			],
			$order->get_checkout_order_received_url()
		);
		$cancel_url   = add_query_arg(
			[
				'nh_stripe_checkout' => 'cancel',
			],
			$order->get_checkout_payment_url()
		);
		$payload      = [
			'mode'                                      => 'payment',
			'success_url'                               => $success_url,
			'cancel_url'                                => $cancel_url,
			'client_reference_id'                       => (string) $order->get_id(),
			'payment_method_types[0]'                   => 'card',
			'line_items[0][quantity]'                   => 1,
			'line_items[0][price_data][currency]'       => $currency,
			'line_items[0][price_data][unit_amount]'    => $amount,
			'line_items[0][price_data][product_data][name]' => sprintf(
				/* translators: %s order number. */
				__( 'Order #%s', 'nice-hair' ),
				$order_number
			),
			'line_items[0][price_data][product_data][description]' => sprintf(
				/* translators: %s site name. */
				__( 'WooCommerce order from %s', 'nice-hair' ),
				wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
			),
			'metadata[order_id]'                        => (string) $order->get_id(),
			'metadata[order_key]'                       => (string) $order->get_order_key(),
			'metadata[site_url]'                        => home_url(),
			'payment_intent_data[metadata][order_id]'   => (string) $order->get_id(),
			'payment_intent_data[metadata][order_key]'  => (string) $order->get_order_key(),
		];

		if ( $order->get_billing_email() ) {
			$payload['customer_email'] = $order->get_billing_email();
		}

		return $payload;
	}

	/**
	 * @param float  $amount
	 * @param string $currency
	 * @return int
	 */
	protected function amount_to_minor_units( $amount, $currency ) {
		$currency = strtoupper( $currency );

		$zero_decimal_currencies = [
			'BIF',
			'CLP',
			'DJF',
			'GNF',
			'JPY',
			'KMF',
			'KRW',
			'MGA',
			'PYG',
			'RWF',
			'UGX',
			'VND',
			'VUV',
			'XAF',
			'XOF',
			'XPF',
		];
		$three_decimal_currencies = [
			'BHD',
			'JOD',
			'KWD',
			'OMR',
			'TND',
		];

		if ( in_array( $currency, $zero_decimal_currencies, true ) ) {
			return (int) round( $amount, 0 );
		}

		if ( in_array( $currency, $three_decimal_currencies, true ) ) {
			return (int) round( $amount * 1000, 0 );
		}

		return (int) round( $amount * 100, 0 );
	}
}
