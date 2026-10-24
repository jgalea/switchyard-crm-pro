<?php

declare( strict_types=1 );

namespace Switchyard\Pro\Feature;

use Switchyard\Logger\Logger;
use Switchyard\Sync\ContactResolver;
use Switchyard\Sync\UserSync;

/**
 * Keeps one CRM contact per person across a network.
 *
 * Without this each site resolves its own contact ID for the same user, which
 * costs an API lookup per site and drifts when an email changes.
 */
final class MultisiteSync implements Feature {

	private const USER_META = 'switchyard_contact_id_network';

	public function __construct(
		private UserSync $sync,
		private ContactResolver $resolver,
		private Logger $logger,
	) {}

	public function register_hooks(): void {
		if ( ! is_multisite() ) {
			return;
		}

		add_action( 'switchyard_contact_created', array( $this, 'share' ), 10, 2 );
		add_filter( 'switchyard_should_sync_user', array( $this, 'adopt_shared_id' ), 10, 2 );
		add_action( 'add_user_to_blog', array( $this, 'on_added_to_site' ), 10, 3 );
	}

	/**
	 * User meta is network-wide, so writing it once makes the mapping visible
	 * from every site.
	 */
	public function share( int $user_id, string $contact_id ): void {
		update_user_meta( $user_id, self::USER_META, $contact_id );
	}

	public function adopt_shared_id( bool $should_sync, int $user_id ): bool {
		if ( null !== $this->resolver->cached_id( $user_id ) ) {
			return $should_sync;
		}

		$shared = get_user_meta( $user_id, self::USER_META, true );

		if ( is_string( $shared ) && '' !== $shared ) {
			$this->resolver->store_id( $user_id, $shared );

			$this->logger->debug(
				'Adopted the network-wide contact mapping.',
				array( 'user_id' => $user_id ),
				'multisite-sync'
			);
		}

		return $should_sync;
	}

	public function on_added_to_site( int $user_id, string $role, int $blog_id ): void {
		$this->adopt_shared_id( true, $user_id );
	}
}
