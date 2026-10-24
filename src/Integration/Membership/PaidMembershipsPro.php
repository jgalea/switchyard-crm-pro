<?php

declare( strict_types=1 );

namespace Switchyard\Pro\Integration\Membership;

use Switchyard\Integration\AbstractIntegration;
use Switchyard\Sync\UserSync;

/**
 * Paid Memberships Pro.
 *
 * PMPro fires one hook for every level change, including cancellation, where
 * the new level is zero.
 */
final class PaidMembershipsPro extends AbstractIntegration {

	public function get_slug(): string {
		return 'pmpro';
	}

	public function get_name(): string {
		return 'Paid Memberships Pro';
	}

	public function is_available(): bool {
		return defined( 'PMPRO_VERSION' );
	}

	public function register_hooks(): void {
		add_action( 'pmpro_after_change_membership_level', array( $this, 'on_level_change' ), 20, 3 );

		add_filter( 'switchyard_wp_fields', array( $this, 'add_fields' ) );
	}

	/**
	 * @param array<string, array<string, string>> $fields
	 * @return array<string, array<string, string>>
	 */
	public function add_fields( array $fields ): array {
		$fields[ __( 'Paid Memberships Pro', 'switchyard-crm-pro' ) ] = array(
			'switchyard_pmpro_level'  => __( 'Membership Level', 'switchyard-crm-pro' ),
			'switchyard_pmpro_status' => __( 'Membership Status', 'switchyard-crm-pro' ),
		);

		return $fields;
	}

	public function on_level_change( int $level_id, int $user_id, mixed $cancel_level = null ): void {
		$this->guard(
			function () use ( $level_id, $user_id, $cancel_level ): void {
				if ( $user_id <= 0 ) {
					return;
				}

				$old_level = (int) $cancel_level;

				// Level zero means the membership ended.
				if ( 0 === $level_id ) {
					update_user_meta( $user_id, 'switchyard_pmpro_level', '' );
					update_user_meta( $user_id, 'switchyard_pmpro_status', 'cancelled' );

					if ( $old_level > 0 ) {
						$this->remove( $user_id, $old_level );
					}

					$this->tag_user( $user_id, $this->tags_for( $old_level, 'cancelled' ) );
					$this->queue->dispatch( UserSync::HOOK_PUSH, array( $user_id, array() ) );

					return;
				}

				// Moving between levels must drop the previous level's tags,
				// otherwise a downgrade keeps the higher tier's access.
				if ( $old_level > 0 && $old_level !== $level_id ) {
					$this->remove( $user_id, $old_level );
				}

				$level = function_exists( 'pmpro_getLevel' ) ? pmpro_getLevel( $level_id ) : null;

				update_user_meta( $user_id, 'switchyard_pmpro_level', is_object( $level ) ? ( $level->name ?? '' ) : '' );
				update_user_meta( $user_id, 'switchyard_pmpro_status', 'active' );

				$this->tag_user( $user_id, $this->tags_for( $level_id, 'active' ) );
				$this->queue->dispatch( UserSync::HOOK_PUSH, array( $user_id, array() ) );
			}
		);
	}

	private function remove( int $user_id, int $level_id ): void {
		$tags = $this->tags_for( $level_id, 'active' );

		if ( ! empty( $tags ) ) {
			$this->queue->dispatch( 'switchyard_remove_tags_async', array( $user_id, $tags ) );
		}
	}

	/**
	 * PMPro levels live in their own table, not as posts, so level tags are
	 * kept in one option keyed by level ID.
	 *
	 * @return list<string>
	 */
	private function tags_for( int $level_id, string $state ): array {
		$options = $this->options();
		$tags    = $options['levels'][ (string) $level_id ][ $state ] ?? array();

		return is_array( $tags ) ? array_values( array_map( 'strval', $tags ) ) : array();
	}
}
