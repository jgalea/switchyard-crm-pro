<?php

declare( strict_types=1 );

namespace Switchyard\Pro\Feature;

use Switchyard\Crm\Contracts\SupportsEvents;
use Switchyard\Crm\Exception\CrmException;
use Switchyard\Crm\Order;
use Switchyard\Crm\ProviderRegistry;
use Switchyard\Logger\Logger;
use Switchyard\Sync\ContactResolver;
use Switchyard\Sync\Queue;

/**
 * Pushes site activity onto the CRM's timeline.
 *
 * Only CRMs with an activity or metric concept accept these, so everything
 * checks capability first rather than failing per call.
 */
final class EventTracking implements Feature {

	public const HOOK = 'switchyard_pro_track_event';

	public function __construct(
		private ProviderRegistry $registry,
		private ContactResolver $resolver,
		private Queue $queue,
		private Logger $logger,
	) {}

	public function register_hooks(): void {
		add_action( self::HOOK, array( $this, 'send' ), 10, 3 );

		add_action( 'switchyard_order_synced', array( $this, 'on_order' ), 10, 2 );
		add_action( 'switchyard_form_submitted', array( $this, 'on_form' ), 10, 5 );
		add_action( 'switchyard_pro_course_completed', array( $this, 'on_course' ), 10, 3 );
	}

	public function on_order( Order $order, int $user_id ): void {
		$this->track(
			$user_id,
			$order->email,
			'Order Placed',
			array(
				'order_id' => $order->source_id,
				'total'    => $order->total,
				'currency' => $order->currency,
				'source'   => $order->source,
			)
		);
	}

	/**
	 * @param array<string, mixed> $fields
	 * @param list<string>         $tags
	 */
	public function on_form( string $form_id, string $email, array $fields, array $tags, string $integration ): void {
		$this->track(
			0,
			$email,
			'Form Submitted',
			array(
				'form_id' => $form_id,
				'plugin'  => $integration,
			)
		);
	}

	public function on_course( int $user_id, string $course, string $source ): void {
		$user = get_userdata( $user_id );

		$this->track(
			$user_id,
			$user ? $user->user_email : '',
			'Course Completed',
			array(
				'course' => $course,
				'source' => $source,
			)
		);
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function track( int $user_id, string $email, string $event, array $data = array() ): void {
		if ( ! $this->registry->active() instanceof SupportsEvents ) {
			return;
		}

		if ( '' === $email && $user_id > 0 ) {
			$user  = get_userdata( $user_id );
			$email = $user ? $user->user_email : '';
		}

		if ( '' === $email ) {
			return;
		}

		$data['email'] = $email;

		$this->queue->dispatch( self::HOOK, array( $user_id, $event, $data ) );
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function send( int $user_id, string $event, array $data ): void {
		$provider = $this->registry->active();

		if ( ! $provider instanceof SupportsEvents ) {
			return;
		}

		$email = isset( $data['email'] ) && is_string( $data['email'] ) ? $data['email'] : '';

		$contact_id = $user_id > 0
			? $this->resolver->resolve( $user_id )
			: $this->resolver->resolve_by_email( $email );

		if ( null === $contact_id ) {
			return;
		}

		try {
			$provider->track_event( $contact_id, $event, $data );
		} catch ( CrmException $e ) {
			$this->logger->error(
				'Could not send an event to the CRM.',
				array(
					'event' => $event,
					'error' => $e->getMessage(),
				),
				'event-tracking'
			);
		}
	}
}
