<?php

declare( strict_types=1 );

namespace Switchyard\Pro\Integration\Lms;

use Switchyard\Integration\AbstractIntegration;

/**
 * LifterLMS: enrolment, completion and membership levels.
 */
final class LifterLms extends AbstractIntegration {

	public function get_slug(): string {
		return 'lifterlms';
	}

	public function get_name(): string {
		return 'LifterLMS';
	}

	public function is_available(): bool {
		return class_exists( 'LifterLMS' );
	}

	public function register_hooks(): void {
		add_action( 'llms_user_enrolled_in_course', array( $this, 'on_enrolled' ), 20, 2 );
		add_action( 'llms_user_removed_from_course', array( $this, 'on_removed' ), 20, 2 );
		add_action( 'lifterlms_course_completed', array( $this, 'on_completed' ), 20, 2 );
		add_action( 'lifterlms_lesson_completed', array( $this, 'on_lesson' ), 20, 2 );
	}

	public function on_enrolled( int $user_id, int $course_id ): void {
		$this->guard(
			fn () => $this->tag_user( $user_id, $this->tags_for( $course_id, 'enrolled' ) )
		);
	}

	public function on_removed( int $user_id, int $course_id ): void {
		$this->guard(
			function () use ( $user_id, $course_id ): void {
				$tags = $this->tags_for( $course_id, 'enrolled' );

				if ( ! empty( $tags ) ) {
					$this->queue->dispatch( 'switchyard_remove_tags_async', array( $user_id, $tags ) );
				}
			}
		);
	}

	public function on_completed( int $user_id, int $course_id ): void {
		$this->guard(
			function () use ( $user_id, $course_id ): void {
				$this->tag_user( $user_id, $this->tags_for( $course_id, 'completed' ) );

				do_action(
					'switchyard_pro_course_completed',
					$user_id,
					get_the_title( $course_id ),
					'lifterlms'
				);
			}
		);
	}

	public function on_lesson( int $user_id, int $lesson_id ): void {
		$this->guard(
			fn () => $this->tag_user( $user_id, $this->tags_for( $lesson_id, 'completed' ) )
		);
	}

	/**
	 * @return list<string>
	 */
	private function tags_for( int $post_id, string $event ): array {
		$stored = get_post_meta( $post_id, '_switchyard_tags_' . $event, true );

		return is_array( $stored ) ? array_values( array_map( 'strval', $stored ) ) : array();
	}
}
