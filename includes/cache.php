<?php
/**
 * Cache layer (Transient).
 *
 * Stores the final rendered HTML for each combination of settings, along with a
 * log of active keys that allows clearing them all at once without a LIKE query
 * on the wp_options table.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * HTML cache lifetime. Read from the settings panel (Settings ← Kouib Blocks) and can
 * be overridden programmatically via the filter afterwards:
 * add_filter( 'kouib_cache_ttl', fn() => 10 * MINUTE_IN_SECONDS );
 */
function kouib_get_cache_ttl() {
	$ttl = (int) kouib_get_setting( 'cache_ttl', 30 * MINUTE_IN_SECONDS );
	$ttl = (int) apply_filters( 'kouib_cache_ttl', $ttl );
	return max( 30, $ttl );
}

/**
 * Returns the list of active keys (loaded only once per page request).
 */
function kouib_get_active_keys() {
	static $active_keys = null;
	if ( null === $active_keys ) {
		$active_keys = get_option( KOUIB_CACHE_KEYS_OPTION, array() );
		if ( ! is_array( $active_keys ) ) {
			$active_keys = array();
		}
	}
	return $active_keys;
}

/**
 * Prunes expired keys (transients that no longer exist) so the option does not
 * grow unboundedly as combinations of attributes or categories vary.
 *
 * Instead of checking the whole list (which can reach hundreds of keys) in a
 * single request, it inspects only a limited slice (30) and removes the expired
 * ones — the load is spread across requests and never becomes a burst of
 * repeated queries. Pruning runs only when the list exceeds the 150 limit.
 */
function kouib_prune_active_keys( $active_keys ) {
	if ( count( $active_keys ) < 150 ) {
		return $active_keys;
	}

	$sample = array_slice( $active_keys, 0, 30 );
	$dead   = array();
	foreach ( $sample as $k ) {
		if ( false === get_transient( $k ) ) {
			$dead[] = $k;
		}
	}

	if ( empty( $dead ) ) {
		return $active_keys;
	}

	$fresh = array();
	foreach ( $active_keys as $k ) {
		if ( ! in_array( $k, $dead, true ) ) {
			$fresh[] = $k;
		}
	}
	update_option( KOUIB_CACHE_KEYS_OPTION, $fresh, false );
	return $fresh;
}

/**
 * Returns an HTML/cat cache key and registers it in the active keys list so all
 * of them can be deleted later, pruning expired keys periodically.
 */
function kouib_register_active_key( $key ) {
	$active_keys = kouib_get_active_keys();
	if ( ! in_array( $key, $active_keys, true ) ) {
		$active_keys = kouib_prune_active_keys( $active_keys );
		$active_keys[] = $key;
		update_option( KOUIB_CACHE_KEYS_OPTION, $active_keys, false );
	}
}

/**
 * Returns the cache key for a given set of attributes (for the full page).
 *
 * The key deliberately includes the plugin version: on a code update the key
 * changes, so fresh HTML is rendered immediately instead of stale cached content.
 */
function kouib_get_cache_key( $attributes ) {
	ksort( $attributes );
	$key = 'kouib_html_' . KOUIB_VERSION . '_' . md5( wp_json_encode( $attributes ) );
	kouib_register_active_key( $key );
	return $key;
}

/**
 * Flushes all stored cache entries (called on any change affecting block output).
 *
 * Static guard: some events fire more than one similar hook for the same event
 * (e.g. student enrollment fires 3 hook names), so we flush only once per
 * request instead of repeating the same transient deletion 3 times (3 × the
 * number of keys queries).
 */
function kouib_flush_courses_cache() {
	static $flushed = false;
	if ( $flushed ) {
		return;
	}
	$flushed = true;

	$active_keys = get_option( KOUIB_CACHE_KEYS_OPTION, array() );
	if ( is_array( $active_keys ) ) {
		foreach ( $active_keys as $key ) {
			delete_transient( $key );
		}
	}
	update_option( KOUIB_CACHE_KEYS_OPTION, array(), false );
}

// Invalidate the cache on any change in relevant course data
add_action( 'save_post_courses', 'kouib_flush_courses_cache', 30 );
add_action( 'deleted_post', function( $post_id ) {
	// Deleting any other post (page/product...) should not needlessly flush the courses cache
	if ( get_post_type( $post_id ) === 'courses' ) {
		kouib_flush_courses_cache();
	}
}, 30 );
add_action( 'kouib_delayed_students_update', 'kouib_flush_courses_cache', 30 );
add_action( 'tutor_after_enrolled', 'kouib_flush_courses_cache', 30 );
add_action( 'tutor_after_enrollment', 'kouib_flush_courses_cache', 30 );
add_action( 'tutor_after_enroll', 'kouib_flush_courses_cache', 30 );
add_action( 'edited_course-category', 'kouib_flush_courses_cache' );
add_action( 'create_course-category', 'kouib_flush_courses_cache' );
add_action( 'delete_course-category', 'kouib_flush_courses_cache' );

// Fallback attempts to flush the cache when a course rating is updated — the
// hook names differ between Tutor LMS versions, and the cache TTL remains the
// real safety net.
add_action( 'tutor_after_rating_save', 'kouib_flush_courses_cache', 30 );
add_action( 'tutor_rating_saved', 'kouib_flush_courses_cache', 30 );
