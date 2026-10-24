<?php

declare( strict_types=1 );

namespace Switchyard\Pro\Integration\Lms;

use Switchyard\Integration\AbstractIntegration;

/**
 * LearnDash: enrolment, completion and quiz results.
 */
final class LearnDash extends AbstractIntegration {

	public function get_slug(): string {
		return 'learndash';
	}

	public function get_name(): string {
		return 'LearnDash';
	}

	public function is_available(): bool {
		return defined( 'LEARNDASH_VERSION' );
	}

	public function register_hooks(): void {
		add_action( 'learndash_update_course_access', array( $this, 'on_access_change' ), 20, 4 );
		add_action( 'learndash_course_completed', array( $this, 'on_course_completed' ), 20 );
		add_action( 'learndash_lesson_completed', array( $this, 'on_lesson_completed' ), 20 );
		add_action( 'learndash_quiz_completed', array( $this, 'on_quiz_completed' ), 20, 2 );

		add_filter( 'switchyard_wp_fields', array( $this, 'add_fields' ) );
	}

	/**
	 * @param array<string, array<string, string>> $fields
	 * @return array<string, array<string, string>>
	 */
	public function add_fields( array $fields ): array {
		$fields[ __( 'LearnDash', 'switchyard-crm-pro' ) ] = array(
			'switchyard_ld_courses_enrolled' => __( 'Courses Enrolled', 'switchyard-crm-pro' ),
			'switchyard_ld_courses_completed' => __( 'Courses Completed', 'switchyard-crm-pro' ),
			'switchyard_ld_last_activity'    => __( 'Last Course Activity', 'switchyard-crm-pro' ),
		);

		return $fields;
	}

	public function on_access_change( int $user_id, int $course_id, mixed $access_list = null, bool $removed = false ): void {
		$this->guard(
			function () use ( $user_id, $course_id, $removed ): void {
				$tags = $this->course_tags( $course_id, 'enrolled' );

				if ( empty( $tags ) ) {
					return;
				}

				// Losing access has to remove the tag, or a cancelled student
				// keeps whatever the enrolment tag unlocked.
				$hook = $removed ? 'switchyard_remove_tags_async' : 'switchyard_apply_tags_async';

				$this->queue->dispatch( $hook, array( $user_id, $tags ) );
			}
		);
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function on_course_completed( array $data ): void {
		$this->guard(
			function () use ( $data ): void {
				$user_id   = (int) ( $data['user']->ID ?? 0 );
				$course_id = (int) ( $data['course']->ID ?? 0 );

				if ( $user_id <= 0 || $course_id <= 0 ) {
					return;
				}

				$this->tag_user( $user_id, $this->course_tags( $course_id, 'completed' ) );

				update_user_meta( $user_id, 'switchyard_ld_last_activity', time() );

				do_action(
					'switchyard_pro_course_completed',
					$user_id,
					get_the_title( $course_id ),
					'learndash'
				);
			}
		);
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function on_lesson_completed( array $data ): void {
		$this->guard(
			function () use ( $data ): void {
				$user_id   = (int) ( $data['user']->ID ?? 0 );
				$lesson_id = (int) ( $data['lesson']->ID ?? 0 );

				if ( $user_id <= 0 || $lesson_id <= 0 ) {
					return;
				}

				$this->tag_user( $user_id, $this->course_tags( $lesson_id, 'completed' ) );
			}
		);
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function on_quiz_completed( array $data, mixed $user = null ): void {
		$this->guard(
			function () use ( $data, $user ): void {
				$user_id = is_object( $user ) && isset( $user->ID ) ? (int) $user->ID : 0;
				$quiz_id = (int) ( $data['quiz'] ?? 0 );

				if ( $user_id <= 0 || $quiz_id <= 0 ) {
					return;
				}

				$passed = ! empty( $data['pass'] );

				$this->tag_user( $user_id, $this->course_tags( $quiz_id, $passed ? 'passed' : 'failed' ) );
			}
		);
	}

	/**
	 * Tags are stored per course, lesson or quiz post.
	 *
	 * @return list<string>
	 */
	private function course_tags( int $post_id, string $event ): array {
		$stored = get_post_meta( $post_id, '_switchyard_tags_' . $event, true );

		return is_array( $stored ) ? array_values( array_map( 'strval', $stored ) ) : array();
	}
}
