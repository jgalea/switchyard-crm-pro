<?php

declare( strict_types=1 );

namespace Switchyard\Pro\Feature;

use Switchyard\Logger\Logger;
use Switchyard\Sync\GuestSync;
use Switchyard\Sync\Queue;
use Switchyard\Sync\TagEngine;

/**
 * Captures identity before checkout completes, so an unfinished order can be
 * followed up from the CRM.
 *
 * The whole value is in capturing the email at the moment it is typed rather
 * than at the moment payment succeeds, so this listens to the checkout form
 * rather than to any order hook.
 */
final class AbandonedCart implements Feature {

	public const HOOK_CHECK   = 'switchyard_pro_check_abandoned';
	private const META_KEY    = '_switchyard_abandoned';
	private const OPTION      = 'switchyard_integration_abandoned_cart';
	private const DEFAULT_LAG = 900;

	public function __construct(
		private GuestSync $guests,
		private TagEngine $tags,
		private Queue $queue,
		private Logger $logger,
	) {}

	public function register_hooks(): void {
		add_action( 'wp_ajax_switchyard_capture_cart', array( $this, 'capture' ) );
		add_action( 'wp_ajax_nopriv_switchyard_capture_cart', array( $this, 'capture' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );

		add_action( self::HOOK_CHECK, array( $this, 'mark_abandoned' ), 10, 2 );

		// A completed order clears the pending capture before it can fire.
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'on_order' ), 5 );
		add_action( 'edd_complete_purchase', array( $this, 'on_order' ), 5 );
	}

	public function enqueue(): void {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}

		wp_register_script( 'switchyard-abandoned-cart', '', array(), SWITCHYARD_PRO_VERSION, true );
		wp_enqueue_script( 'switchyard-abandoned-cart' );

		wp_add_inline_script(
			'switchyard-abandoned-cart',
			sprintf(
				'(function(){var s=%s,f=document.querySelector("#billing_email,#edd-email,input[type=email]");if(!f)return;var sent=false;f.addEventListener("change",function(){if(sent||!f.value)return;sent=true;var d=new FormData();d.append("action","switchyard_capture_cart");d.append("nonce",s.nonce);d.append("email",f.value);fetch(s.url,{method:"POST",body:d,credentials:"same-origin"});});})();',
				wp_json_encode(
					array(
						'url'   => admin_url( 'admin-ajax.php' ),
						'nonce' => wp_create_nonce( 'switchyard_capture_cart' ),
					)
				)
			)
		);
	}

	public function capture(): void {
		check_ajax_referer( 'switchyard_capture_cart', 'nonce' );

		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';

		if ( ! is_email( $email ) ) {
			wp_send_json_error( array( 'message' => 'invalid email' ), 400 );
		}

		// The endpoint is open to logged-out visitors by necessity, since the
		// whole point is capturing an email before checkout completes. Two
		// checks keep it from becoming a way to inject arbitrary contacts into
		// someone's CRM: there has to be a real cart in progress, and a single
		// visitor cannot submit endlessly.
		if ( ! $this->has_active_cart() ) {
			wp_send_json_error( array( 'message' => 'no cart in progress' ), 400 );
		}

		if ( $this->is_rate_limited() ) {
			wp_send_json_error( array( 'message' => 'too many requests' ), 429 );
		}

		$tags = $this->configured_tags( 'capture_tags' );

		$this->queue->dispatch( GuestSync::HOOK, array( $email, array(), $tags ) );

		// Scheduled rather than immediate: an order placed inside the window
		// cancels it, so only a genuinely abandoned checkout gets tagged.
		$this->queue->schedule(
			time() + $this->lag(),
			self::HOOK_CHECK,
			array( $email, get_current_user_id() )
		);

		set_transient( 'switchyard_cart_' . md5( $email ), time(), DAY_IN_SECONDS );

		wp_send_json_success();
	}

	public function on_order( mixed $order_id ): void {
		$email = $this->email_for_order( $order_id );

		if ( '' === $email ) {
			return;
		}

		delete_transient( 'switchyard_cart_' . md5( $email ) );

		$recovered = $this->configured_tags( 'recovered_tags' );
		$user_id   = get_current_user_id();

		if ( ! empty( $recovered ) && $user_id > 0 ) {
			$this->queue->dispatch( 'switchyard_apply_tags_async', array( $user_id, $recovered ) );
		}

		$abandoned = $this->configured_tags( 'abandoned_tags' );

		if ( ! empty( $abandoned ) && $user_id > 0 ) {
			$this->queue->dispatch( 'switchyard_remove_tags_async', array( $user_id, $abandoned ) );
		}
	}

	public function mark_abandoned( string $email, int $user_id ): void {
		// The transient is cleared by a completed order, so its absence means
		// the checkout finished and there is nothing to tag.
		if ( false === get_transient( 'switchyard_cart_' . md5( $email ) ) ) {
			return;
		}

		delete_transient( 'switchyard_cart_' . md5( $email ) );

		$tags = $this->configured_tags( 'abandoned_tags' );

		if ( empty( $tags ) ) {
			return;
		}

		if ( $user_id > 0 ) {
			$this->tags->apply_tags( $user_id, $tags );

			return;
		}

		$this->guests->push( $email, array(), $tags );
	}

	/**
	 * Whether this visitor actually has something in a cart.
	 *
	 * Without this the endpoint would accept any email from anyone and create
	 * a contact for it.
	 */
	private function has_active_cart(): bool {
		if ( function_exists( 'WC' ) ) {
			$cart = WC()->cart;

			if ( $cart && ! $cart->is_empty() ) {
				return true;
			}
		}

		if ( function_exists( 'edd_get_cart_contents' ) ) {
			$contents = edd_get_cart_contents();

			if ( ! empty( $contents ) ) {
				return true;
			}
		}

		/** @var bool $allowed */
		$allowed = apply_filters( 'switchyard_pro_abandoned_cart_active', false );

		return $allowed;
	}

	/**
	 * Coarse per-visitor throttle, keyed on a hash so no address is stored.
	 */
	private function is_rate_limited(): bool {
		$ip = isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '';

		if ( '' === $ip ) {
			return false;
		}

		$key   = 'switchyard_cart_rl_' . md5( $ip );
		$count = (int) get_transient( $key );

		if ( $count >= 10 ) {
			return true;
		}

		set_transient( $key, $count + 1, HOUR_IN_SECONDS );

		return false;
	}

	private function email_for_order( mixed $order_id ): string {
		if ( function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( $order_id );

			if ( $order ) {
				return (string) $order->get_billing_email();
			}
		}

		if ( function_exists( 'edd_get_payment_user_email' ) ) {
			return (string) edd_get_payment_user_email( (int) $order_id );
		}

		return '';
	}

	private function lag(): int {
		$options = get_option( self::OPTION, array() );
		$lag     = is_array( $options ) ? (int) ( $options['delay'] ?? 0 ) : 0;

		return $lag > 0 ? $lag : self::DEFAULT_LAG;
	}

	/** @return list<string> */
	private function configured_tags( string $key ): array {
		$options = get_option( self::OPTION, array() );
		$tags    = is_array( $options ) ? ( $options[ $key ] ?? array() ) : array();

		return is_array( $tags ) ? array_values( array_map( 'strval', $tags ) ) : array();
	}
}
