<?php

declare( strict_types=1 );

namespace Switchyard\Pro\Integration\Membership;

use Switchyard\Integration\AbstractIntegration;
use Switchyard\Sync\UserSync;

/**
 * MemberPress: membership level, status and expiry.
 *
 * Status transitions matter more than the initial purchase here: a lapsed
 * membership that keeps its tag keeps its access.
 */
final class MemberPress extends AbstractIntegration {

	public function get_slug(): string {
		return 'memberpress';
	}

	public function get_name(): string {
		return 'MemberPress';
	}

	public function is_available(): bool {
		return defined( 'MEPR_VERSION' );
	}

	public function register_hooks(): void {
		add_action( 'mepr-txn-status-complete', array( $this, 'on_active' ), 20 );
		add_action( 'mepr-txn-status-confirmed', array( $this, 'on_active' ), 20 );
		add_action( 'mepr-txn-status-refunded', array( $this, 'on_inactive' ), 20 );
		add_action( 'mepr-txn-status-failed', array( $this, 'on_inactive' ), 20 );
		add_action( 'mepr-subscription-expired', array( $this, 'on_expired' ), 20 );
		add_action( 'mepr-event-subscription-stopped', array( $this, 'on_stopped' ), 20 );

		add_filter( 'switchyard_wp_fields', array( $this, 'add_fields' ) );
	}

	/**
	 * @param array<string, array<string, string>> $fields
	 * @return array<string, array<string, string>>
	 */
	public function add_fields( array $fields ): array {
		$fields[ __( 'MemberPress', 'switchyard-crm-pro' ) ] = array(
			'switchyard_mepr_level'   => __( 'Membership Level', 'switchyard-crm-pro' ),
			'switchyard_mepr_status'  => __( 'Membership Status', 'switchyard-crm-pro' ),
			'switchyard_mepr_expires' => __( 'Membership Expires', 'switchyard-crm-pro' ),
		);

		return $fields;
	}

	public function on_active( mixed $txn ): void {
		$this->guard(
			function () use ( $txn ): void {
				[ $user_id, $product_id ] = $this->identify( $txn );

				if ( $user_id <= 0 ) {
					return;
				}

				update_user_meta( $user_id, 'switchyard_mepr_level', get_the_title( $product_id ) );
				update_user_meta( $user_id, 'switchyard_mepr_status', 'active' );

				$this->tag_user( $user_id, $this->tags_for( $product_id, 'active' ) );

				$lapsed = $this->tags_for( $product_id, 'lapsed' );

				if ( ! empty( $lapsed ) ) {
					$this->queue->dispatch( 'switchyard_remove_tags_async', array( $user_id, $lapsed ) );
				}

				$this->queue->dispatch( UserSync::HOOK_PUSH, array( $user_id, array() ) );
			}
		);
	}

	public function on_inactive( mixed $txn ): void {
		$this->lapse( $txn, 'cancelled' );
	}

	public function on_expired( mixed $txn ): void {
		$this->lapse( $txn, 'expired' );
	}

	public function on_stopped( mixed $event ): void {
		$this->lapse( is_object( $event ) && isset( $event->event_data ) ? $event->event_data : $event, 'cancelled' );
	}

	private function lapse( mixed $txn, string $status ): void {
		$this->guard(
			function () use ( $txn, $status ): void {
				[ $user_id, $product_id ] = $this->identify( $txn );

				if ( $user_id <= 0 ) {
					return;
				}

				update_user_meta( $user_id, 'switchyard_mepr_status', $status );

				// Active tags come off first, so access is revoked even if the
				// lapsed tag is not configured.
				$active = $this->tags_for( $product_id, 'active' );

				if ( ! empty( $active ) ) {
					$this->queue->dispatch( 'switchyard_remove_tags_async', array( $user_id, $active ) );
				}

				$this->tag_user( $user_id, $this->tags_for( $product_id, 'lapsed' ) );

				$this->queue->dispatch( UserSync::HOOK_PUSH, array( $user_id, array() ) );
			}
		);
	}

	/**
	 * @return array{0: int, 1: int}
	 */
	private function identify( mixed $txn ): array {
		if ( ! is_object( $txn ) ) {
			return array( 0, 0 );
		}

		return array(
			(int) ( $txn->user_id ?? 0 ),
			(int) ( $txn->product_id ?? 0 ),
		);
	}

	/**
	 * @return list<string>
	 */
	private function tags_for( int $product_id, string $state ): array {
		$stored = get_post_meta( $product_id, '_switchyard_tags_' . $state, true );

		return is_array( $stored ) ? array_values( array_map( 'strval', $stored ) ) : array();
	}
}
