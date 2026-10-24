<?php

declare( strict_types=1 );

namespace Switchyard\Pro\Feature;

use Switchyard\Logger\Logger;
use Switchyard\Sync\Queue;
use Switchyard\Sync\TagEngine;
use WP_User;

/**
 * Tags stale accounts and redirects people by tag after login.
 */
final class Logins implements Feature {

	public const HOOK_SWEEP   = 'switchyard_pro_sweep_logins';
	private const META_LAST   = 'switchyard_last_login';
	private const OPTION      = 'switchyard_integration_logins';

	public function __construct(
		private TagEngine $tags,
		private Queue $queue,
		private Logger $logger,
	) {}

	public function register_hooks(): void {
		add_action( 'wp_login', array( $this, 'on_login' ), 10, 2 );
		add_filter( 'login_redirect', array( $this, 'redirect' ), 20, 3 );
		add_action( self::HOOK_SWEEP, array( $this, 'sweep' ) );

		add_action(
			'init',
			static function (): void {
				if ( ! wp_next_scheduled( self::HOOK_SWEEP ) ) {
					wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK_SWEEP );
				}
			}
		);
	}

	public function on_login( string $user_login, mixed $user = null ): void {
		if ( ! $user instanceof WP_User ) {
			$user = get_user_by( 'login', $user_login );
		}

		if ( ! $user instanceof WP_User ) {
			return;
		}

		update_user_meta( $user->ID, self::META_LAST, time() );

		$inactive = $this->option_tags( 'inactive_tags' );

		// Coming back clears the stale marker, or the tag would stick forever.
		if ( ! empty( $inactive ) ) {
			$this->queue->dispatch( 'switchyard_remove_tags_async', array( $user->ID, $inactive ) );
		}
	}

	/**
	 * @param WP_User|\WP_Error $user
	 */
	public function redirect( string $redirect_to, string $requested, mixed $user ): string {
		if ( ! $user instanceof WP_User ) {
			return $redirect_to;
		}

		$rules = get_option( self::OPTION, array() );
		$rules = is_array( $rules ) ? ( $rules['redirects'] ?? array() ) : array();

		if ( ! is_array( $rules ) ) {
			return $redirect_to;
		}

		foreach ( $rules as $rule ) {
			$tag = (string) ( $rule['tag'] ?? '' );
			$url = (string) ( $rule['url'] ?? '' );

			if ( '' === $tag || '' === $url ) {
				continue;
			}

			if ( $this->tags->has_tag( $user->ID, $tag ) ) {
				return $url;
			}
		}

		return $redirect_to;
	}

	/**
	 * Tags accounts that have not logged in for the configured period.
	 */
	public function sweep(): void {
		$tags = $this->option_tags( 'inactive_tags' );
		$days = $this->option_int( 'inactive_days', 90 );

		if ( empty( $tags ) || $days <= 0 ) {
			return;
		}

		$cutoff = time() - ( $days * DAY_IN_SECONDS );

		$users = get_users(
			array(
				'fields'     => 'ID',
				'number'     => 500,
				'meta_query' => array(
					'relation' => 'OR',
					array(
						'key'     => self::META_LAST,
						'value'   => $cutoff,
						'compare' => '<',
						'type'    => 'NUMERIC',
					),
					array(
						'key'     => self::META_LAST,
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);

		foreach ( $users as $user_id ) {
			$this->queue->dispatch( 'switchyard_apply_tags_async', array( (int) $user_id, $tags ) );
		}

		$this->logger->info(
			'Tagged inactive accounts.',
			array(
				'count' => count( $users ),
				'days'  => $days,
			),
			'logins'
		);
	}

	/** @return list<string> */
	private function option_tags( string $key ): array {
		$options = get_option( self::OPTION, array() );
		$tags    = is_array( $options ) ? ( $options[ $key ] ?? array() ) : array();

		return is_array( $tags ) ? array_values( array_map( 'strval', $tags ) ) : array();
	}

	private function option_int( string $key, int $fallback ): int {
		$options = get_option( self::OPTION, array() );
		$value   = is_array( $options ) ? (int) ( $options[ $key ] ?? 0 ) : 0;

		return $value > 0 ? $value : $fallback;
	}
}
