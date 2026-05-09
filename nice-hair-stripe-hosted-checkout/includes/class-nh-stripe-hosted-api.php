<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NH_Stripe_Hosted_Api {
	const API_BASE = 'https://api.stripe.com/v1/';

	/**
	 * @return array<string,mixed>
	 */
	public function get_stripe_settings() {
		$settings = get_option( 'woocommerce_stripe_settings', [] );

		return is_array( $settings ) ? $settings : [];
	}

	/**
	 * @return bool
	 */
	public function is_test_mode() {
		$settings = $this->get_stripe_settings();

		return isset( $settings['testmode'] ) && 'yes' === $settings['testmode'];
	}

	/**
	 * @return string
	 */
	public function get_secret_key() {
		$settings = $this->get_stripe_settings();
		$key      = $this->is_test_mode() ? 'test_secret_key' : 'secret_key';

		return isset( $settings[ $key ] ) ? trim( (string) $settings[ $key ] ) : '';
	}

	/**
	 * @return string
	 */
	public function get_publishable_key() {
		$settings = $this->get_stripe_settings();
		$key      = $this->is_test_mode() ? 'test_publishable_key' : 'publishable_key';

		return isset( $settings[ $key ] ) ? trim( (string) $settings[ $key ] ) : '';
	}

	/**
	 * @return string
	 */
	public function get_webhook_secret() {
		$settings = $this->get_stripe_settings();
		$key      = $this->is_test_mode() ? 'test_webhook_secret' : 'webhook_secret';

		return isset( $settings[ $key ] ) ? trim( (string) $settings[ $key ] ) : '';
	}

	/**
	 * @return bool
	 */
	public function is_configured() {
		return '' !== $this->get_secret_key();
	}

	/**
	 * @param string              $method HTTP method.
	 * @param string              $path   Stripe API path relative to /v1.
	 * @param array<string,mixed> $body   Request payload.
	 * @param string              $idempotency_key Optional Stripe idempotency key.
	 * @return array<string,mixed>|WP_Error
	 */
	public function request( $method, $path, array $body = [], $idempotency_key = '' ) {
		$secret_key = $this->get_secret_key();

		if ( '' === $secret_key ) {
			return new WP_Error( 'nh_stripe_missing_secret_key', __( 'Stripe secret key is not configured.', 'nice-hair' ) );
		}

		$url     = trailingslashit( self::API_BASE ) . ltrim( $path, '/' );
		$headers = [
			'Authorization' => 'Bearer ' . $secret_key,
		];

		if ( '' !== $idempotency_key ) {
			$headers['Idempotency-Key'] = $idempotency_key;
		}

		$args = [
			'method'      => strtoupper( $method ),
			'timeout'     => 45,
			'headers'     => $headers,
			'user-agent'  => 'NiceHairStripeHostedCheckout/' . NH_STRIPE_HOSTED_CHECKOUT_VERSION,
			'data_format' => 'body',
		];

		if ( ! empty( $body ) ) {
			$args['body'] = $body;
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code      = (int) wp_remote_retrieve_response_code( $response );
		$raw_body  = wp_remote_retrieve_body( $response );
		$parsed    = json_decode( $raw_body, true );
		$has_error = $code < 200 || $code >= 300;

		if ( $has_error ) {
			$message = __( 'Stripe API request failed.', 'nice-hair' );

			if ( is_array( $parsed ) && isset( $parsed['error']['message'] ) ) {
				$message = (string) $parsed['error']['message'];
			}

			return new WP_Error(
				'nh_stripe_api_error',
				$message,
				[
					'status' => $code,
					'body'   => $parsed,
					'raw'    => $raw_body,
				]
			);
		}

		return is_array( $parsed ) ? $parsed : [];
	}

	/**
	 * @param array<string,mixed> $payload
	 * @param string              $idempotency_key
	 * @return array<string,mixed>|WP_Error
	 */
	public function create_checkout_session( array $payload, $idempotency_key = '' ) {
		return $this->request( 'POST', 'checkout/sessions', $payload, $idempotency_key );
	}

	/**
	 * @param string $session_id
	 * @return array<string,mixed>|WP_Error
	 */
	public function retrieve_checkout_session( $session_id ) {
		return $this->request( 'GET', 'checkout/sessions/' . rawurlencode( $session_id ) );
	}

	/**
	 * @param string $session_id
	 * @return array<string,mixed>|WP_Error
	 */
	public function expire_checkout_session( $session_id ) {
		return $this->request( 'POST', 'checkout/sessions/' . rawurlencode( $session_id ) . '/expire' );
	}
}
