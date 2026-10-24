<?php

declare( strict_types=1 );

namespace Switchyard\Pro\Feature;

use Switchyard\Logger\Logger;
use Switchyard\Sync\TagEngine;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Tags people on video engagement.
 *
 * Progress is reported from the browser, so the endpoint only accepts
 * thresholds that were configured server-side. Otherwise anyone could post
 * their way to a completion tag.
 */
final class MediaTools implements Feature {

	private const OPTION = 'switchyard_integration_media';

	public function __construct(
		private TagEngine $tags,
		private Logger $logger,
	) {}

	public function register_hooks(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	public function register_routes(): void {
		register_rest_route(
			'switchyard/v1',
			'/media-progress',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => static fn (): bool => is_user_logged_in(),
				'args'                => array(
					'media_id' => array(
						'type'     => 'string',
						'required' => true,
					),
					'percent'  => array(
						'type'     => 'integer',
						'required' => true,
						'minimum'  => 0,
						'maximum'  => 100,
					),
					'post_id'  => array(
						'type'     => 'integer',
						'required' => true,
					),
				),
			)
		);
	}

	public function handle( WP_REST_Request $request ): WP_REST_Response {
		$user_id  = get_current_user_id();
		$media_id = (string) $request->get_param( 'media_id' );
		$percent  = max( 0, min( 100, (int) $request->get_param( 'percent' ) ) );
		$post_id  = (int) $request->get_param( 'post_id' );

		// Progress is self-reported by the browser, so the endpoint has to
		// establish that this user could legitimately have watched this media
		// at all. Without it any subscriber could POST percent=100 for a
		// media ID and collect whatever tags it grants, including tags that
		// gate paid content.
		if ( ! $this->media_is_on_post( $media_id, $post_id ) ) {
			return new WP_REST_Response( array( 'message' => 'unknown media for post' ), 400 );
		}

		if ( function_exists( 'switchyard_can_access' ) && ! switchyard_can_access( $post_id, $user_id ) ) {
			return new WP_REST_Response( array( 'message' => 'forbidden' ), 403 );
		}

		$rules = $this->rules_for( $media_id );

		if ( empty( $rules ) ) {
			return new WP_REST_Response( array( 'message' => 'no rules' ), 200 );
		}

		// Monotonic per user and media, so a client cannot replay a lower
		// value and re-fire, nor skip straight to the completion threshold
		// without having reported the ones below it.
		$seen = $this->progress( $user_id, $media_id );

		if ( $percent <= $seen ) {
			return new WP_REST_Response( array( 'applied' => array() ), 200 );
		}

		$this->store_progress( $user_id, $media_id, $percent );

		$applied = array();

		foreach ( $rules as $rule ) {
			$threshold = (int) ( $rule['percent'] ?? 100 );
			$tags      = is_array( $rule['tags'] ?? null ) ? array_map( 'strval', $rule['tags'] ) : array();

			// Thresholds come from stored configuration, never from the
			// request, so a forged percent cannot invent a new tag.
			if ( $percent < $threshold || $seen >= $threshold || empty( $tags ) ) {
				continue;
			}

			$this->tags->apply_tags( $user_id, $tags );

			$applied = array_merge( $applied, $tags );
		}

		return new WP_REST_Response(
			array(
				'applied' => array_values( array_unique( $applied ) ),
			),
			200
		);
	}

	/**
	 * Whether the configuration says this media belongs to this post.
	 */
	private function media_is_on_post( string $media_id, int $post_id ): bool {
		if ( '' === $media_id || $post_id <= 0 ) {
			return false;
		}

		$options = get_option( self::OPTION, array() );

		if ( ! is_array( $options ) ) {
			return false;
		}

		$declared = $options['posts'][ (string) $post_id ] ?? array();

		return is_array( $declared ) && in_array( $media_id, array_map( 'strval', $declared ), true );
	}

	private function progress_key( string $media_id ): string {
		return 'switchyard_media_' . md5( $media_id );
	}

	private function progress( int $user_id, string $media_id ): int {
		return (int) get_user_meta( $user_id, $this->progress_key( $media_id ), true );
	}

	private function store_progress( int $user_id, string $media_id, int $percent ): void {
		update_user_meta( $user_id, $this->progress_key( $media_id ), $percent );
	}

	/**
	 * @return list<array{percent: int, tags: list<string>}>
	 */
	private function rules_for( string $media_id ): array {
		$options = get_option( self::OPTION, array() );

		if ( ! is_array( $options ) ) {
			return array();
		}

		$rules = $options['media'][ $media_id ] ?? array();

		return is_array( $rules ) ? array_values( $rules ) : array();
	}

	public function enqueue(): void {
		if ( ! is_user_logged_in() || is_admin() ) {
			return;
		}

		wp_register_script( 'switchyard-media-tools', '', array(), SWITCHYARD_PRO_VERSION, true );
		wp_enqueue_script( 'switchyard-media-tools' );

		wp_add_inline_script(
			'switchyard-media-tools',
			sprintf(
				'(function(){var s=%s;document.querySelectorAll("video[data-switchyard-media]").forEach(function(v){var sent={};v.addEventListener("timeupdate",function(){if(!v.duration)return;var p=Math.floor(v.currentTime/v.duration*100),b=Math.floor(p/25)*25;if(b<=0||sent[b])return;sent[b]=true;fetch(s.url,{method:"POST",headers:{"Content-Type":"application/json","X-WP-Nonce":s.nonce},credentials:"same-origin",body:JSON.stringify({media_id:v.dataset.switchyardMedia,percent:b,post_id:s.post})});});});})();',
				wp_json_encode(
					array(
						'url'   => rest_url( 'switchyard/v1/media-progress' ),
						'nonce' => wp_create_nonce( 'wp_rest' ),
						'post'  => get_the_ID() ? (int) get_the_ID() : 0,
					)
				)
			)
		);
	}
}
