<?php

declare( strict_types=1 );

namespace Switchyard\Pro\Feature;

use Switchyard\Crm\Contracts\SupportsEcommerce;
use Switchyard\Crm\Exception\CrmException;
use Switchyard\Crm\Order;
use Switchyard\Crm\ProviderRegistry;
use Switchyard\Logger\Logger;
use Switchyard\Sync\Queue;

/**
 * Sends full order objects to CRMs that model ecommerce natively.
 *
 * The free plugin already normalises WooCommerce and EDD orders into one
 * shape and fires them on switchyard_order_synced. This turns that into a real
 * CRM order record with line items, rather than flattening a total into a
 * custom field.
 */
final class EnhancedEcommerce implements Feature {

	public const HOOK = 'switchyard_pro_send_order';

	private const META_REMOTE_ID = '_switchyard_crm_order_id';

	public function __construct(
		private ProviderRegistry $registry,
		private Queue $queue,
		private Logger $logger,
	) {}

	public function register_hooks(): void {
		add_action( 'switchyard_order_synced', array( $this, 'on_order' ), 10, 2 );
		add_action( 'switchyard_order_refunded', array( $this, 'on_refund' ), 10, 2 );
		add_action( self::HOOK, array( $this, 'send' ) );
	}

	public function on_order( Order $order, int $user_id ): void {
		if ( ! $this->supported() ) {
			return;
		}

		// The queue serialises arguments as JSON, so the order crosses it as
		// an array rather than as an object.
		$this->queue->dispatch( self::HOOK, array( $order->to_array() ) );
	}

	/**
	 * @param array<string, mixed> $order_data
	 */
	public function send( array $order_data ): void {
		if ( ! $this->supported() ) {
			return;
		}

		$order = Order::from_array( $order_data );

		/** @var SupportsEcommerce $provider */
		$provider = $this->registry->active();

		$existing = $this->remote_id( $order );

		try {
			if ( null !== $existing ) {
				$provider->update_order( $existing, $order );

				return;
			}

			$remote_id = $provider->add_order( $order );

			$this->store_remote_id( $order, $remote_id );
		} catch ( CrmException $e ) {
			$this->logger->error(
				'Could not send the order to the CRM.',
				array(
					'order' => $order->reference(),
					'error' => $e->getMessage(),
				),
				'enhanced-ecommerce'
			);
		}
	}

	public function on_refund( Order $order, int $user_id ): void {
		if ( ! $this->supported() ) {
			return;
		}

		$remote_id = $this->remote_id( $order );

		if ( null === $remote_id ) {
			return;
		}

		/** @var SupportsEcommerce $provider */
		$provider = $this->registry->active();

		try {
			$provider->update_order( $remote_id, $order );
		} catch ( CrmException $e ) {
			$this->logger->error(
				'Could not update the refunded order in the CRM.',
				array(
					'order' => $order->reference(),
					'error' => $e->getMessage(),
				),
				'enhanced-ecommerce'
			);
		}
	}

	private function supported(): bool {
		return $this->registry->active() instanceof SupportsEcommerce;
	}

	private function remote_id( Order $order ): ?string {
		$stored = get_post_meta( (int) $order->source_id, self::META_REMOTE_ID, true );

		return is_string( $stored ) && '' !== $stored ? $stored : null;
	}

	private function store_remote_id( Order $order, string $remote_id ): void {
		update_post_meta( (int) $order->source_id, self::META_REMOTE_ID, $remote_id );
	}
}
