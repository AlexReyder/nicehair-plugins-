<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NH_Stripe_Hosted_Webhook_Controller {
	/**
	 * @var NH_Stripe_Hosted_Api
	 */
	protected $api;

	/**
	 * @var NH_Stripe_Hosted_Session_Manager
	 */
	protected $session_manager;

	/**
	 * @param NH_Stripe_Hosted_Api             $api
	 * @param NH_Stripe_Hosted_Session_Manager $session_manager
	 */
	public function __construct( NH_Stripe_Hosted_Api $api, NH_Stripe_Hosted_Session_Manager $session_manager ) {
		$this->api             = $api;
		$this->session_manager = $session_manager;
	}

	/**
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			'nh-stripe-hosted/v1',
			'/webhook',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'handle_webhook' ],
				'permission_callback' => '__return_true',
			]
		);
	}

	/**
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public function handle_webhook( WP_REST_Request $request ) {
		$payload = $request->get_body();
		$secret  = $this->api->get_webhook_secret();

		if ( '' === $secret ) {
			return new WP_REST_Response(
				[
					'error' => 'missing_webhook_secret',
				],
				500
			);
		}

		if ( ! $this->is_valid_signature( (string) $request->get_header( 'stripe-signature' ), $payload, $secret ) ) {
			return new WP_REST_Response(
				[
					'error' => 'invalid_signature',
				],
				400
			);
		}

		$event = json_decode( $payload, true );

		if ( ! is_array( $event ) || empty( $event['type'] ) || empty( $event['data']['object'] ) ) {
			return new WP_REST_Response(
				[
					'error' => 'invalid_payload',
				],
				400
			);
		}

		$object = is_array( $event['data']['object'] ) ? $event['data']['object'] : [];
		$order  = $this->resolve_order( $object );

		if ( ! $order ) {
			return new WP_REST_Response(
				[
					'received' => true,
					'ignored'  => 'order_not_found',
				],
				200
			);
		}

		$last_event = (string) $order->get_meta( NH_Stripe_Hosted_Session_Manager::META_LAST_EVENT_ID );
		$event_id   = isset( $event['id'] ) ? (string) $event['id'] : '';

		if ( '' !== $event_id && $event_id === $last_event ) {
			return new WP_REST_Response(
				[
					'received'  => true,
					'duplicate' => true,
				],
				200
			);
		}

		$event_type = (string) $event['type'];

		switch ( $event_type ) {
			case 'checkout.session.completed':
			case 'checkout.session.async_payment_succeeded':
				if ( isset( $object['payment_status'] ) && 'paid' === $object['payment_status'] ) {
					$this->session_manager->mark_session_paid( $order, $object, 'webhook' );
				} else {
					$this->session_manager->mark_session_state(
						$order,
						$object,
						(string) ( $object['status'] ?? 'completed' ),
						__( 'Stripe hosted checkout session completed, waiting for paid status.', 'nice-hair' )
					);
				}
				break;

			case 'checkout.session.expired':
				$this->session_manager->mark_session_state(
					$order,
					$object,
					'expired',
					__( 'Stripe hosted checkout session expired.', 'nice-hair' )
				);
				break;

			case 'checkout.session.async_payment_failed':
				$this->session_manager->mark_session_state(
					$order,
					$object,
					'failed',
					__( 'Stripe hosted checkout payment failed.', 'nice-hair' )
				);
				break;

			case 'payment_intent.payment_failed':
				$this->session_manager->mark_session_state(
					$order,
					$object,
					'failed',
					__( 'Stripe payment intent failed for hosted checkout.', 'nice-hair' )
				);
				break;
		}

		if ( '' !== $event_id ) {
			$order->update_meta_data( NH_Stripe_Hosted_Session_Manager::META_LAST_EVENT_ID, $event_id );
			$order->save();
		}

		return new WP_REST_Response(
			[
				'received' => true,
			],
			200
		);
	}

	/**
	 * @param string $signature_header
	 * @param string $payload
	 * @param string $secret
	 * @return bool
	 */
	protected function is_valid_signature( $signature_header, $payload, $secret ) {
		if ( '' === $signature_header || '' === $payload || '' === $secret ) {
			return false;
		}

		if ( ! preg_match( '/^t=(?P<timestamp>\d+)(?P<signatures>(,v\d+=[a-z0-9]+){1,})$/', $signature_header, $matches ) ) {
			return false;
		}

		$timestamp = (int) $matches['timestamp'];

		if ( abs( time() - $timestamp ) > ( 5 * MINUTE_IN_SECONDS ) ) {
			return false;
		}

		$expected_signature = hash_hmac( 'sha256', $timestamp . '.' . $payload, $secret );

		return false !== strpos( $matches['signatures'], ',v1=' . $expected_signature );
	}

	/**
	 * @param array<string,mixed> $object
	 * @return WC_Order|false
	 */
	protected function resolve_order( array $object ) {
		$order_id = 0;

		if ( ! empty( $object['metadata']['order_id'] ) ) {
			$order_id = absint( $object['metadata']['order_id'] );
		} elseif ( ! empty( $object['client_reference_id'] ) ) {
			$order_id = absint( $object['client_reference_id'] );
		}

		if ( $order_id > 0 ) {
			$order = wc_get_order( $order_id );

			if ( $order instanceof WC_Order ) {
				return $order;
			}
		}

		if ( ! empty( $object['id'] ) ) {
			$orders = wc_get_orders(
				[
					'limit'      => 1,
					'meta_key'   => NH_Stripe_Hosted_Session_Manager::META_SESSION_ID,
					'meta_value' => (string) $object['id'],
				]
			);

			if ( ! empty( $orders[0] ) && $orders[0] instanceof WC_Order ) {
				return $orders[0];
			}
		}

		return false;
	}
}
