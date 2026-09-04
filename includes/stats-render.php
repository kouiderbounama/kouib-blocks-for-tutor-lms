<?php
/**
 * Renders the platform stats block (kouib/courses-stats).
 *
 * Number sources (no heavy queries per request):
 * - Courses:     wp_count_posts('courses')->publish (cached internally by WP).
 * - Lessons:     wp_count_posts('lesson')->publish (or 0 if the type is not registered).
 * - Students:    total number of WordPress users (a single COUNT on wp_users) —
 *                no enrollment counting.
 * - Instructors: COUNT(DISTINCT post_author) for published courses — one query.
 *
 * Cache: a single transient holding only the actually computed numbers (only
 * the requested ones are computed; the stored ones are reused immediately). The
 * key is registered via kouib_register_active_key, so it is flushed automatically
 * with the whole plugin's cache on save_post_courses / deleted_post /
 * kouib_delayed_students_update / tutor_after_enrolled / tutor_after_enrollment /
 * tutor_after_enroll events (see includes/cache.php). TTL from kouib_get_cache_ttl().
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Computes a single stat value.
 *
 * @param string $key 'students' | 'instructors' | 'courses' | 'lessons'.
 * @return int
 */
function kouib_compute_stats_value( $key ) {
	global $wpdb;

	switch ( $key ) {
		case 'students':
			// Total WordPress users as the "students" count the user asked for —
			// one lightweight COUNT query, no enrollment counting.
			return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" );

		case 'instructors':
			return (int) $wpdb->get_var(
				"SELECT COUNT(DISTINCT post_author) FROM {$wpdb->posts}"
				. " WHERE post_type = 'courses' AND post_status = 'publish'"
			);

		case 'courses':
			$counted = wp_count_posts( 'courses' );
			return isset( $counted->publish ) ? (int) $counted->publish : 0;

		case 'lessons':
			$counted = wp_count_posts( 'lesson' );
			return isset( $counted->publish ) ? (int) $counted->publish : 0;

		default:
			return 0;
	}
}

/**
 * Returns the requested numbers from the cache (a single transient), computing
 * only the missing ones.
 *
 * The key is stored in the active keys list so any relevant event removes it
 * together with the rest of the plugin's cache (see cache.php). The TTL is an
 * extra safety net.
 *
 * @param array $wanted List of actually requested keys (based on show/hide).
 * @return array key => int
 */
function kouib_get_courses_stats( $wanted ) {
	$key   = 'kouib_stats_' . KOUIB_VERSION;
	$stats = get_transient( $key );
	if ( ! is_array( $stats ) ) {
		$stats = array();
	}

	$need = array();
	foreach ( $wanted as $k ) {
		if ( ! isset( $stats[ $k ] ) ) {
			$need[] = $k;
		}
	}

	if ( ! empty( $need ) ) {
		foreach ( $need as $k ) {
			$stats[ $k ] = kouib_compute_stats_value( $k );
		}
		set_transient( $key, $stats, kouib_get_cache_ttl() );
		kouib_register_active_key( $key );
	}

	// Return only what was requested, in the requested order.
	$result = array();
	foreach ( $wanted as $k ) {
		$result[ $k ] = isset( $stats[ $k ] ) ? (int) $stats[ $k ] : 0;
	}
	return $result;
}

/**
 * Inline SVG icon (outline or filled with the current color) — no external libraries.
 *
 * @param string $key    Stat key.
 * @param bool   $filled true = filled, false = outline.
 * @return string
 */
function kouib_stats_icon( $key, $filled ) {
	$icons = array(
		'students' => array(
			// Graduation cap
			'outline' => '<path d="M22 10 12 5 2 10l10 5 10-5z"/><path d="M6 12.5V17c0 1.5 2.7 3 6 3s6-1.5 6-3v-4.5"/><path d="M22 10v4"/>',
			'filled'  => '<path d="M22 10 12 5 2 10l10 5 10-5z"/><path d="M6 12.5V17c0 1.5 2.7 3 6 3s6-1.5 6-3v-4.5z"/>',
		),
		'instructors' => array(
			// User
			'outline' => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
			'filled'  => '<circle cx="12" cy="7" r="4.5"/><path d="M1.5 21a7 7 0 0 1 14 0z"/>',
		),
		'courses' => array(
			// Book
			'outline' => '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>',
			'filled'  => '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>',
		),
		'lessons' => array(
			// Lesson (play)
			'outline' => '<circle cx="12" cy="12" r="10"/><polygon points="10 8 16 12 10 16 10 8" fill="currentColor"/>',
			'filled'  => '<circle cx="12" cy="12" r="10"/><polygon points="10 8 16 12 10 16 10 8" fill="#ffffff"/>',
		),
	);

	$body  = '';
	$attrs = '';
	if ( $filled ) {
		if ( isset( $icons[ $key ]['filled'] ) ) {
			$body = $icons[ $key ]['filled'];
		}
		$attrs = 'fill="currentColor"';
	} else {
		if ( isset( $icons[ $key ]['outline'] ) ) {
			$body = $icons[ $key ]['outline'];
		}
		$attrs = 'fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"';
	}

	return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" ' . $attrs . ' aria-hidden="true" focusable="false">' . $body . '</svg>';
}

/**
 * Builds a single stat item.
 */
function kouib_stats_item( $key, $value, $filled, $show_label ) {
	$labels = array(
		'students'    => __( 'Students', 'kouib-blocks-for-tutor-lms' ),
		'instructors' => __( 'Instructors', 'kouib-blocks-for-tutor-lms' ),
		'courses'     => __( 'Courses', 'kouib-blocks-for-tutor-lms' ),
		'lessons'     => __( 'Lessons', 'kouib-blocks-for-tutor-lms' ),
	);

	$label_html = '';
	if ( $show_label && isset( $labels[ $key ] ) ) {
		$label_html = '<span class="kouib-stats-label">' . esc_html( $labels[ $key ] ) . '</span>';
	}

	return sprintf(
		'<div class="kouib-stats-item kouib-stats-item-%1$s">'
		. '<span class="kouib-stats-icon">%2$s</span>'
		. '<span class="kouib-stats-value">%3$s</span>'
		. '%4$s'
		. '</div>',
		esc_attr( $key ),
		kouib_stats_icon( $key, $filled ),
		esc_html( number_format_i18n( $value ) ),
		$label_html
	);
}

/**
 * Core platform stats rendering.
 */
function kouib_render_courses_stats( $attributes ) {
	// Block hidden in the settings (Kouib Blocks): not displayed on the frontend (editor-only notice).
	if ( ! kouib_block_enabled( 'stats' ) ) {
		return kouib_block_disabled_output();
	}
	kouib_enqueue_frontend_assets( 'stats' );

	$defaults = array(
		'showStudents'    => true,
		'showInstructors' => true,
		'showCourses'     => true,
		'showLessons'     => true,
		'columns'         => 4,
		'columnsTablet'   => 2,
		'columnsMobile'   => 1,
		'primaryColor'    => '#2a7be4',
		'showLabels'      => true,
		'iconStyle'       => 'outline',
	);
	$attr = wp_parse_args( $attributes, $defaults );

	// Columns and values
	$show = array(
		'students'    => ! empty( $attr['showStudents'] ),
		'instructors' => ! empty( $attr['showInstructors'] ),
		'courses'     => ! empty( $attr['showCourses'] ),
		'lessons'     => ! empty( $attr['showLessons'] ),
	);

	$wanted = array();
	foreach ( $show as $k => $on ) {
		if ( $on ) {
			$wanted[] = $k;
		}
	}

	if ( empty( $wanted ) ) {
		return '<p class="kouib-stats-empty">' . esc_html__( 'Enable at least one statistic to display', 'kouib-blocks-for-tutor-lms' ) . '</p>';
	}

	// Compute only what was requested (cached) — hidden ones are not queried.
	$stats = kouib_get_courses_stats( $wanted );

	$cols     = kouib_clamp( $attr['columns'], 2, 4 );
	$cols_tab = kouib_clamp( $attr['columnsTablet'], 1, 4 );
	$cols_mob = kouib_clamp( $attr['columnsMobile'], 1, 2 );
	// Color: the designer's custom color if set, otherwise the general color from the settings panel.
	$color    = kouib_resolve_primary_color( $attributes );
	$labels   = ! empty( $attr['showLabels'] );
	$filled   = ( 'filled' === $attr['iconStyle'] );

	$items = '';
	foreach ( $wanted as $k ) {
		$items .= kouib_stats_item( $k, $stats[ $k ], $filled, $labels );
	}

	$our_style  = '--kouib-primary:' . esc_attr( $color ) . ';';
	$our_style .= '--kouib-stats-cols:' . absint( $cols ) . ';';
	$our_style .= '--kouib-stats-cols-tablet:' . absint( $cols_tab ) . ';';
	$our_style .= '--kouib-stats-cols-mobile:' . absint( $cols_mob ) . ';';

	$direction = is_rtl() ? 'rtl' : 'ltr';
	$our_style .= 'direction:' . $direction . ';';

	if ( function_exists( 'get_block_wrapper_attributes' ) ) {
		$wrapper_attrs = get_block_wrapper_attributes( array(
			'class' => 'kouib-stats',
			'style' => $our_style,
		) );
	} else {
		$wrapper_attrs = sprintf(
			'class="kouib-stats" style="%s"',
			esc_attr( $our_style )
		);
	}

	return '<div ' . $wrapper_attrs . '>' . $items . '</div>';
}