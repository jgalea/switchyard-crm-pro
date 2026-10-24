<?php

declare( strict_types=1 );

namespace Switchyard\Pro;

use Switchyard\Container;
use Switchyard\Features;
use Switchyard\Integration\Registry as IntegrationRegistry;
use Switchyard\Logger\Logger;
use Switchyard\Plugin as Core;
use Switchyard\Sync\GuestSync;
use Switchyard\Sync\Queue;
use Switchyard\Sync\TagEngine;
use Switchyard\Sync\UserSync;

/**
 * Registers everything the pro plugin adds.
 *
 * The free plugin holds no knowledge of these features. Each one announces
 * itself through the feature registry, and integrations go through the same
 * registry the built-in ones use, so an inactive host plugin costs nothing.
 */
final class Plugin {

	public static function boot( Core $core ): void {
		$container = $core->container();

		/** @var Features $features */
		$features = $core->get( Features::class );

		self::register_integrations( $core, $container );
		self::register_features( $features, $container );
	}

	private static function register_integrations( Core $core, Container $container ): void {
		/** @var IntegrationRegistry $registry */
		$registry = $core->get( IntegrationRegistry::class );

		$integrations = array(
			'learndash'  => Integration\Lms\LearnDash::class,
			'lifterlms'  => Integration\Lms\LifterLms::class,
			'memberpress' => Integration\Membership\MemberPress::class,
			'pmpro'      => Integration\Membership\PaidMembershipsPro::class,
		);

		foreach ( $integrations as $slug => $class ) {
			$registry->add(
				$slug,
				static fn ( Container $c ): object => new $class(
					$c->get( UserSync::class ),
					$c->get( TagEngine::class ),
					$c->get( Queue::class ),
					$c->get( Features::class ),
					$c->get( Logger::class )
				)
			);
		}
	}

	private static function register_features( Features $features, Container $container ): void {
		$built = array(
			'enhanced-ecommerce' => new Feature\EnhancedEcommerce(
				$container->get( \Switchyard\Crm\ProviderRegistry::class ),
				$container->get( Queue::class ),
				$container->get( Logger::class )
			),
			'abandoned-cart'     => new Feature\AbandonedCart(
				$container->get( GuestSync::class ),
				$container->get( TagEngine::class ),
				$container->get( Queue::class ),
				$container->get( Logger::class )
			),
			'media-tools'        => new Feature\MediaTools(
				$container->get( TagEngine::class ),
				$container->get( Logger::class )
			),
			'event-tracking'     => new Feature\EventTracking(
				$container->get( \Switchyard\Crm\ProviderRegistry::class ),
				$container->get( \Switchyard\Sync\ContactResolver::class ),
				$container->get( Queue::class ),
				$container->get( Logger::class )
			),
			'logins'             => new Feature\Logins(
				$container->get( TagEngine::class ),
				$container->get( Queue::class ),
				$container->get( Logger::class )
			),
			'multisite-sync'     => new Feature\MultisiteSync(
				$container->get( UserSync::class ),
				$container->get( \Switchyard\Sync\ContactResolver::class ),
				$container->get( Logger::class )
			),
		);

		foreach ( $built as $slug => $feature ) {
			if ( ! apply_filters( 'switchyard_pro_enable_feature', true, $slug ) ) {
				continue;
			}

			$feature->register_hooks();
			$features->register( $slug, $feature );
		}
	}
}
