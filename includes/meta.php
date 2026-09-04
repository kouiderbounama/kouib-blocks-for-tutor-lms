<?php
/**
 * Syncs the per-course student count into custom meta (_kouib_students_count)
 * so it can be used in ORDER BY without heavy queries.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function kouib_normalize_post_id( $value ) {
	if ( is_object( $value ) && isset( $value->ID ) ) {
		return absint( $value->ID );
	}
	if ( is_array( $value ) && isset( $value['ID'] ) ) {
		return absint( $value['ID'] );
	}
	return absint( $value );
}

function kouib_update_course_students_meta( $course_id ) {
	$course_id = kouib_normalize_post_id( $course_id );

	if ( ! $course_id || ! function_exists( 'tutor_utils' ) ) {
		return;
	}
	if ( get_post_type( $course_id ) !== 'courses' ) {
		return;
	}

	// Static guard: a single enrollment event fires more than one similar hook +
	// save_post_tutor_enrolled — without it the same counting query and meta
	// update run up to 4 times.
	static $updated = array();
	if ( isset( $updated[ $course_id ] ) ) {
		return;
	}
	$updated[ $course_id ] = true;

	$count = (int) tutor_utils()->count_enrolled_users_by_course( $course_id );
	update_post_meta( $course_id, KOUIB_META_STUDENTS, $count );
}

function kouib_ensure_students_meta( $post ) {
	// publish_courses passes a WP_Post object, not an ID
	$post_id = kouib_normalize_post_id( $post );
	if ( ! $post_id || get_post_type( $post_id ) !== 'courses' ) {
		return;
	}
	if ( ! metadata_exists( 'post', $post_id, KOUIB_META_STUDENTS ) ) {
		update_post_meta( $post_id, KOUIB_META_STUDENTS, 0 );
	}
}
add_action( 'save_post_courses', 'kouib_ensure_students_meta', 20 );
add_action( 'publish_courses', 'kouib_ensure_students_meta', 20 );

add_action( 'tutor_after_enrolled', 'kouib_update_course_students_meta', 10, 1 );
add_action( 'tutor_after_enrollment', 'kouib_update_course_students_meta', 10, 1 );
add_action( 'tutor_after_enroll', 'kouib_update_course_students_meta', 10, 1 );

// Saving an enrollment record (tutor_enrolled) → update the parent course counter
add_action( 'save_post_tutor_enrolled', function( $post_id ) {
	$course_id = wp_get_post_parent_id( $post_id );
	if ( $course_id ) {
		kouib_update_course_students_meta( $course_id );
	}
}, 20 );

// Deleting an enrollment record → deferred update once the deletion completes
add_action( 'before_delete_post', function( $post_id ) {
	if ( get_post_type( $post_id ) === 'tutor_enrolled' ) {
		$course_id = wp_get_post_parent_id( $post_id );
		if ( $course_id ) {
			wp_schedule_single_event( time() + 3, 'kouib_delayed_students_update', array( $course_id ) );
		}
	}
} );
add_action( 'kouib_delayed_students_update', 'kouib_update_course_students_meta' );

/**
 * Syncs all courses — called on plugin activation.
 *
 * Runs in the background in batches (100 courses per scheduled event) via
 * wp-cron instead of a huge loop that would stall activation on large sites.
 */
function kouib_activate_plugin_sync() {
	if ( function_exists( 'tutor_utils' ) && ! wp_next_scheduled( 'kouib_sync_students_batch' ) ) {
		wp_schedule_single_event( time() + 5, 'kouib_sync_students_batch', array( 0 ) );
	}
}

function kouib_sync_students_batch_cb( $offset ) {
	$offset  = max( 0, (int) $offset );
	$batch   = 100;
	$courses = get_posts( array(
		'post_type'      => 'courses',
		'post_status'    => 'any',
		'posts_per_page' => $batch,
		'fields'         => 'ids',
		'offset'         => $offset,
		'no_found_rows'  => true,
	) );

	foreach ( $courses as $course_id ) {
		kouib_update_course_students_meta( $course_id );
	}

	if ( count( $courses ) === $batch ) {
		wp_schedule_single_event( time() + 5, 'kouib_sync_students_batch', array( $offset + $batch ) );
	}
}
add_action( 'kouib_sync_students_batch', 'kouib_sync_students_batch_cb' );
