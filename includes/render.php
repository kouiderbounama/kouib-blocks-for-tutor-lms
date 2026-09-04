<?php
/**
 * Rendering logic: a single query + grouping by category, caching, and the filter script.
 *
 * Performance improvement (v4.1.0):
 * - On the front end, categories are not all pre-rendered into HTML at once; only the
 *   active grid is displayed, and the remaining categories load on click via the REST
 *   endpoint (with per-category caching). This reduces page size and preloaded images.
 * - In the editor (ServerSideRender), all categories are shown as a full preview.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ==========================================================================
 * 1. Fetch courses with a single query and group them by category (solves N+1 problem)
 * ========================================================================== */

/**
 * Actually executes the grouped course query (without caching).
 *
 * Only two lightweight queries (v5.3.9): fetch the IDs of all matching courses and read
 * their relationships with course-category terms. No full object fetching at all —
 * the two arrays are returned as IDs:
 *   'all'     => all course IDs in order
 *   'by_term' => term_id => its course IDs in order
 * The render page then fetches only the objects it displays via kouib_get_posts_by_ids().
 * This keeps the cache very small and removes huge post__in queries on large sites.
 */
function kouib_run_grouped_query( $query_args ) {

	$ids_args                   = $query_args;
	$ids_args['posts_per_page'] = -1;
	$ids_args['fields']         = 'ids';

	$all_ids = get_posts( $ids_args );

	$result = array( 'all' => array(), 'by_term' => array() );

	if ( empty( $all_ids ) ) {
		return $result;
	}

	$term_map      = array();
	$relationships = wp_get_object_terms(
		$all_ids,
		'course-category',
		array( 'fields' => 'all_with_object_id' )
	);

	if ( ! is_wp_error( $relationships ) ) {
		foreach ( $relationships as $rel ) {
			$cid = (int) $rel->object_id;
			if ( ! isset( $term_map[ $cid ] ) ) {
				$term_map[ $cid ] = array();
			}
			$term_map[ $cid ][] = (int) $rel->term_id;
		}
	}

	$all_ids = array_values( array_map( 'absint', $all_ids ) );
	$result['all'] = $all_ids;

	foreach ( $all_ids as $cid ) {
		if ( ! empty( $term_map[ $cid ] ) ) {
			foreach ( $term_map[ $cid ] as $tid ) {
				if ( ! isset( $result['by_term'][ $tid ] ) ) {
					$result['by_term'][ $tid ] = array();
				}
				$result['by_term'][ $tid ][] = $cid;
			}
		}
	}

	return $result;
}

/**
 * Returns post objects in the exact order of $ids, using a single post__in query
 * bounded by their count instead of loading the full list in a huge query.
 */
function kouib_get_posts_by_ids( $ids ) {
	$ids = array_map( 'absint', (array) $ids );
	$ids = array_values( array_filter( $ids ) );
	if ( empty( $ids ) ) {
		return array();
	}

	$posts = get_posts( array(
		'post_type'      => 'courses',
		'post__in'       => $ids,
		'posts_per_page' => -1,
		'post_status'    => 'publish',
		'orderby'        => 'post__in',
		'no_found_rows'  => true,
	) );

	if ( empty( $posts ) ) {
		return array();
	}

	$by_id = array();
	foreach ( $posts as $p ) {
		$by_id[ $p->ID ] = $p;
	}

	$ordered = array();
	foreach ( $ids as $id ) {
		if ( isset( $by_id[ $id ] ) ) {
			$ordered[] = $by_id[ $id ];
		}
	}
	return $ordered;
}

/**
 * Fetches a bounded number of courses matching the requested order using a bounded
 * query (without loading the full list) — used by the carousel and other places that
 * only need the first page. Respects tax_query and custom ordering.
 */
function kouib_get_courses_bounded( $query_args, $limit ) {
	$limit = max( 1, (int) $limit );

	$ids_args = $query_args;
	$ids_args['posts_per_page'] = $limit;
	$ids_args['fields']         = 'ids';
	$ids_args['no_found_rows']  = true;

	$ids = get_posts( $ids_args );
	if ( empty( $ids ) ) {
		return array();
	}

	return kouib_get_posts_by_ids( $ids );
}

/**
 * Returns all courses matching $query_args (no limit) with a cache for the grouped
 * (non-random) query to reduce load when requests repeat via REST or when the grid
 * cache is skipped (e.g. when parameters change). The key is registered as active so
 * it is cleared automatically along with the cache invalidation triggers (saving a
 * course / editing a category / enrollment / rating).
 *
 * Random ordering (rand) is now stored for a very short time (60 seconds by default
 * via the kouib_rand_cache_ttl filter) — so a heavy grouped query is not re-run on
 * every visit, while retaining a reasonable degree of freshness.
 */
function kouib_get_grouped_courses( $query_args ) {
	$orderby = isset( $query_args['orderby'] ) ? $query_args['orderby'] : 'date';

	if ( 'rand' === $orderby ) {
		$cache_key = 'kouib_grp_' . KOUIB_VERSION . '_rand_' . md5( wp_json_encode( $query_args ) );
		kouib_register_active_key( $cache_key );

		$cached = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$result = kouib_run_grouped_query( $query_args );
		set_transient( $cache_key, $result, (int) apply_filters( 'kouib_rand_cache_ttl', MINUTE_IN_SECONDS ) );
		return $result;
	}

	$cache_bits = array( 'orderby' => $orderby );
	if ( isset( $query_args['order'] ) ) {
		$cache_bits['order'] = $query_args['order'];
	}
	if ( isset( $query_args['meta_key'] ) ) {
		$cache_bits['meta_key'] = $query_args['meta_key'];
	}
	if ( isset( $query_args['tax_query'] ) ) {
		$cache_bits['tax_query'] = $query_args['tax_query'];
	}
	ksort( $cache_bits );
	$cache_key = 'kouib_grp_' . KOUIB_VERSION . '_' . md5( wp_json_encode( $cache_bits ) );
	kouib_register_active_key( $cache_key );

	$cached = get_transient( $cache_key );
	if ( false !== $cached ) {
		return $cached;
	}

	$result = kouib_run_grouped_query( $query_args );
	set_transient( $cache_key, $result, kouib_get_cache_ttl() );

	return $result;
}

/**
 * Builds query_args according to the requested ordering option, and when categories
 * are passed it constrains the query to them (used by both the filter grid and the
 * carousel).
 */
function kouib_build_query_args( $orderby_attr, $category_ids = array() ) {
	$query_args = array(
		'post_type'     => 'courses',
		'post_status'   => 'publish',
		'no_found_rows' => true,
	);

	switch ( $orderby_attr ) {
		case 'date_asc':
			$query_args['orderby'] = 'date';
			$query_args['order']   = 'ASC';
			break;
		case 'title':
			$query_args['orderby'] = 'title';
			$query_args['order']   = 'ASC';
			break;
		case 'rand':
			$query_args['orderby'] = 'rand';
			break;
		case 'students':
			$query_args['meta_query'] = array(
				'relation' => 'OR',
				array(
					'key'     => KOUIB_META_STUDENTS,
					'compare' => 'EXISTS',
				),
				array(
					'key'     => KOUIB_META_STUDENTS,
					'compare' => 'NOT EXISTS',
				),
			);
			$query_args['orderby']  = 'meta_value_num';
			$query_args['meta_key'] = KOUIB_META_STUDENTS;
			$query_args['order']    = 'DESC';
			break;
		default:
			$query_args['orderby'] = 'date';
			$query_args['order']   = 'DESC';
	}

	$category_ids = array_map( 'absint', (array) $category_ids );
	$category_ids = array_values( array_filter( $category_ids ) );

	if ( ! empty( $category_ids ) ) {
		$query_args['tax_query'] = array(
			array(
				'taxonomy' => 'course-category',
				'field'    => 'term_id',
				'terms'    => $category_ids,
				'operator' => 'IN',
			),
		);
	}

	return $query_args;
}

/**
 * Returns the ordered list of terms (or an empty array on error).
 *
 * @param bool $hide_empty Whether to hide categories with no courses (default true).
 */
function kouib_get_terms_list( $hide_empty = true ) {
	$terms = get_terms( array(
		'taxonomy'   => 'course-category',
		'hide_empty' => (bool) $hide_empty,
		'orderby'    => 'name',
		'order'      => 'ASC',
		'number'     => 100,
	) );

	if ( is_wp_error( $terms ) ) {
		return array();
	}

	return $terms;
}

/* ==========================================================================
 * 2. The render callback function
 * ========================================================================== */
function kouib_render_courses_filter( $attributes ) {

	// Block disabled from the Kouib Blocks settings: not rendered on the front end (editor notice only).
	if ( ! kouib_block_enabled( 'filter' ) ) {
		return kouib_block_disabled_output();
	}

	kouib_enqueue_frontend_assets( 'filter' );

	$orderby_attr = isset( $attributes['orderby'] ) ? $attributes['orderby'] : 'date';

	$is_editor_request = defined( 'REST_REQUEST' ) && REST_REQUEST;
	$use_cache         = ! $is_editor_request && ( 'rand' !== $orderby_attr );

	// Cache: early check before any query or rendering — on a hit we return the full page HTML.
	$cache_key = null;
	if ( $use_cache ) {
		$cache_key = kouib_get_cache_key( $attributes );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}
	}

	$per_page      = isset( $attributes['perPage'] ) ? max( 1, (int) $attributes['perPage'] ) : 3;
	$columns       = isset( $attributes['columns'] ) ? max( 1, min( 4, (int) $attributes['columns'] ) ) : 3;
	$show_all      = isset( $attributes['showAll'] ) ? (bool) $attributes['showAll'] : true;
	$show_level    = isset( $attributes['showLevel'] ) ? (bool) $attributes['showLevel'] : true;
	$show_rating   = isset( $attributes['showRating'] ) ? (bool) $attributes['showRating'] : true;
	$show_lessons  = isset( $attributes['showLessons'] ) ? (bool) $attributes['showLessons'] : true;
	$show_duration = isset( $attributes['showDuration'] ) ? (bool) $attributes['showDuration'] : true;
	$show_price    = isset( $attributes['showPrice'] ) ? (bool) $attributes['showPrice'] : true;
	$show_students = isset( $attributes['showStudents'] ) ? (bool) $attributes['showStudents'] : true;
	$show_enroll   = isset( $attributes['showEnrollBtn'] ) ? (bool) $attributes['showEnrollBtn'] : true;
	$enroll_btn_text = isset( $attributes['enrollBtnText'] ) ? sanitize_text_field( $attributes['enrollBtnText'] ) : '';
	if ( in_array( $enroll_btn_text, array( '', 'Enroll in course' ), true ) ) {
		$enroll_btn_text = __( 'Enroll in course', 'kouib-blocks-for-tutor-lms' );
	}
	$open_in_new_tab = ! empty( $attributes['openInNewTab'] );
	// Color: the designer's color if set, otherwise the global color from the settings panel.
	$primary_color = kouib_resolve_primary_color( $attributes );

	$terms = kouib_get_terms_list();

	if ( empty( $terms ) ) {
		return '<p style="text-align:center;padding:40px;color:#666;">' . esc_html__( 'No course categories are available yet.', 'kouib-blocks-for-tutor-lms' ) . '</p>';
	}

	// Final attrs used for cache building and category rendering (all => null, categories => term objects).
	$render_attributes = array(
		'orderby'     => $orderby_attr,
		'perPage'     => $per_page,
		'showLevel'   => $show_level,
		'showRating'  => $show_rating,
		'showLessons' => $show_lessons,
		'showDuration'=> $show_duration,
		'showPrice'   => $show_price,
		'showStudents'=> $show_students,
		'showEnrollBtn'=> $show_enroll,
		'enrollBtnText'=> $enroll_btn_text,
		'openInNewTab'=> $open_in_new_tab,
	);

	$our_style = '--kouib-primary:' . esc_attr( $primary_color ) . ';--kouib-columns:' . absint( $columns ) . ';';

	// Page direction (supports RTL and LTR, instead of forcing RTL always).
	$direction = is_rtl() ? 'rtl' : 'ltr';
	$our_style .= 'direction:' . $direction . ';';

	$has_shadow = isset( $attributes['hasShadow'] ) ? ! empty( $attributes['hasShadow'] ) : true;
	$wrapper_class = 'tutor-courses-filter-wrapper' . ( $has_shadow ? '' : ' kouib-no-card-shadow' );

	if ( function_exists( 'get_block_wrapper_attributes' ) ) {
		$wrapper_attrs = get_block_wrapper_attributes( array(
			'class' => $wrapper_class,
			'style' => $our_style,
		) );
	} else {
		$wrapper_attrs = sprintf(
			'class="%1$s" style="%2$s"',
			esc_attr( $wrapper_class ),
			esc_attr( $our_style )
		);
	}

	// Note: get_block_wrapper_attributes() ignores keys other than class/style
	// (via shortcode_atts), so we add dir and the custom data attributes manually.
	$wrapper_attrs .= sprintf(
		' dir="%1$s" data-kouib-rest="%2$s" data-kouib-orderby="%3$s" data-kouib-perpage="%4$d" data-kouib-showlevel="%5$s" data-kouib-showrating="%6$s" data-kouib-showlessons="%7$s" data-kouib-showduration="%8$s" data-kouib-showprice="%9$s" data-kouib-showstudents="%10$s" data-kouib-showenrollbtn="%11$s" data-kouib-enrollbtntext="%12$s" data-kouib-openinnewtab="%13$s"',
		esc_attr( $direction ),
		esc_url( rest_url( 'kouib/v1/courses' ) ),
		esc_attr( $orderby_attr ),
		absint( $per_page ),
		$show_level ? '1' : '0',
		$show_rating ? '1' : '0',
		$show_lessons ? '1' : '0',
		$show_duration ? '1' : '0',
		$show_price ? '1' : '0',
		$show_students ? '1' : '0',
		$show_enroll ? '1' : '0',
		esc_attr( $enroll_btn_text ),
		$open_in_new_tab ? '1' : '0'
	);

	ob_start();
	?>
	<div <?php echo $wrapper_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped internally ?>>
		<div class="tutor-courses-filter-buttons">
			<?php if ( $show_all ) : ?>
				<button type="button" class="kouib-btn <?php echo $show_all ? 'active' : ''; ?>" data-term="all"><?php echo esc_html__( 'All', 'kouib-blocks-for-tutor-lms' ); ?></button>
			<?php endif; ?>
			<?php foreach ( $terms as $index => $term ) : ?>
				<button type="button" class="kouib-btn <?php echo ( ! $show_all && $index === 0 ) ? 'active' : ''; ?>" data-term="term-<?php echo esc_attr( $term->term_id ); ?>">
					<?php echo esc_html( $term->name ); ?>
				</button>
			<?php endforeach; ?>
		</div>

		<div class="tutor-courses-filter-lists">
			<?php
			// The default active term (All or the first category).
			if ( $show_all ) {
				$active_term    = null;
				$active_class   = 'kouib-all active';
			} else {
				$first          = reset( $terms );
				$active_term    = $first;
				$active_class   = 'kouib-term-' . $first->term_id . ' active';
			}

			?>
			<?php if ( $is_editor_request ) : ?>
				<?php
				$query_args     = kouib_build_query_args( $render_attributes['orderby'] );
				$grouped        = kouib_get_grouped_courses( $query_args );
				$all_ids_l      = isset( $grouped['all'] ) ? $grouped['all'] : array();
				$all_sliced     = kouib_get_posts_by_ids( array_slice( $all_ids_l, 0, $per_page ) );
				?>
				<?php if ( $show_all ) : ?>
					<div class="kouib-courses-grid kouib-all active">
						<?php echo kouib_render_courses_from_posts( $all_sliced, $show_level, $show_rating, $show_lessons, $show_duration, $show_price, $show_students, $show_enroll, null, $enroll_btn_text, $open_in_new_tab ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</div>
				<?php endif; ?>
				<?php foreach ( $terms as $index => $term ) :
					$term_ids          = isset( $grouped['by_term'][ $term->term_id ] ) ? $grouped['by_term'][ $term->term_id ] : array();
					$term_posts_sliced = kouib_get_posts_by_ids( array_slice( $term_ids, 0, $per_page ) );
					$is_first          = ( ! $show_all && $index === 0 );
					?>
					<div class="kouib-courses-grid kouib-term-<?php echo esc_attr( $term->term_id ); ?> <?php echo $is_first ? 'active' : ''; ?>">
						<?php echo kouib_render_courses_from_posts( $term_posts_sliced, $show_level, $show_rating, $show_lessons, $show_duration, $show_price, $show_students, $show_enroll, $term, $enroll_btn_text, $open_in_new_tab ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</div>
				<?php endforeach; ?>
			<?php else : ?>
				<div class="kouib-courses-grid <?php echo esc_attr( $active_class ); ?>" data-kouib-page="1">
					<?php
					$active_grid = kouib_render_category_grid_html( $active_term, $render_attributes );
					echo $active_grid['html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					?>
				</div>
			<?php endif; ?>
		</div>
	</div>
	<?php
	$html = ob_get_clean();

	if ( $use_cache ) {
		set_transient( $cache_key, $html, kouib_get_cache_ttl() );
	}

	return $html;
}

/**
 * Returns all course IDs for a given term (or all when null) ordered according to
 * $attributes — IDs only (from the cached grouped query), so the cache stays light.
 * Used for pagination (Load More): only the displayed page's objects are fetched at render.
 */
function kouib_render_posts_for_term( $term, $attributes ) {
	$query_args = kouib_build_query_args( $attributes['orderby'] );
	$grouped    = kouib_get_grouped_courses( $query_args );

	if ( $term ) {
		return isset( $grouped['by_term'][ $term->term_id ] ) ? $grouped['by_term'][ $term->term_id ] : array();
	}
	return $grouped['all'];
}

/**
 * Renders a single category's grid page (1-based) (or all when null) with per-page
 * cache. Returns an array: ['html' => the card HTML (+ "Load more" button on the first
 *              page), 'has_more' => whether more pages remain].
 * Used on the front end for the active grid and in the REST endpoint.
 */
function kouib_render_category_grid_html( $term, $attributes, $page = 1 ) {
	$page     = max( 1, (int) $page );
	$per_page = max( 1, (int) $attributes['perPage'] );

	// Random ordering is not cached to keep it fresh and avoid a stale cached order.
	$use_cache = ( isset( $attributes['orderby'] ) && 'rand' === $attributes['orderby'] ) ? false : true;

	$cache_bits = array( 'term' => $term ? (int) $term->term_id : 'all', 'page' => $page );
	if ( $use_cache ) {
		foreach ( array( 'orderby', 'perPage', 'showLevel', 'showRating', 'showLessons', 'showDuration', 'showPrice', 'showStudents', 'showEnrollBtn', 'enrollBtnText', 'openInNewTab' ) as $a ) {
			if ( isset( $attributes[ $a ] ) ) {
				$cache_bits[ $a ] = $attributes[ $a ];
			}
		}
		ksort( $cache_bits );
		$cache_key = 'kouib_catp_' . KOUIB_VERSION . '_' . md5( wp_json_encode( $cache_bits ) );
		kouib_register_active_key( $cache_key );

		$cached = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}
	}
	{
		$show_level    = ! empty( $attributes['showLevel'] );
		$show_rating   = ! empty( $attributes['showRating'] );
		$show_lessons  = ! empty( $attributes['showLessons'] );
		$show_duration = ! empty( $attributes['showDuration'] );
		$show_price    = ! empty( $attributes['showPrice'] );
		$show_students = ! empty( $attributes['showStudents'] );
		$show_enroll   = ! empty( $attributes['showEnrollBtn'] );
		$enroll_btn_text = isset( $attributes['enrollBtnText'] ) ? sanitize_text_field( $attributes['enrollBtnText'] ) : '';
		if ( in_array( $enroll_btn_text, array( '', 'Enroll in course' ), true ) ) {
			$enroll_btn_text = __( 'Enroll in course', 'kouib-blocks-for-tutor-lms' );
		}
		$open_in_new_tab = ! empty( $attributes['openInNewTab'] );

		$full   = kouib_render_posts_for_term( $term, $attributes );
		$offset = ( $page - 1 ) * $per_page;
		$ids    = array_slice( $full, $offset, $per_page );
		$has_more = count( $full ) > ( $offset + $per_page );

		// Fetch only the objects for the displayed page (not the full list) — a post__in
		// query bounded by the number of cards, safe for large sites.
		$posts = kouib_get_posts_by_ids( $ids );

		$html = kouib_render_courses_from_posts( $posts, $show_level, $show_rating, $show_lessons, $show_duration, $show_price, $show_students, $show_enroll, $term, $enroll_btn_text, $open_in_new_tab );

		if ( 1 === $page && $has_more ) {
			$html .= '<div class="kouib-more-wrap"><button type="button" class="kouib-load-more-btn">' . esc_html__( 'Load more', 'kouib-blocks-for-tutor-lms' ) . '</button></div>';
		}

		$cached = array( 'html' => $html, 'has_more' => $has_more );
		if ( $use_cache ) {
			set_transient( $cache_key, $cached, kouib_get_cache_ttl() );
		}
	}

	return $cached;
}

/**
 * Note: the front-end filter script moved to a standalone file (assets/js/view.js)
 * and is loaded via viewScript in block.json on the front end, and via
 * enqueue_block_editor_assets in the editor. No need to print an inline script here.
 */

/* ==========================================================================
 * 3. Rendering the cards
 * ========================================================================== */

/**
 * Returns the course price as plain text (localized string/number) that is cache-safe,
 * without any Tutor buttons or scripts. Relies on the course meta instead of the
 * interactive loop calls.
 */
function kouib_get_course_price_text( $course_id ) {
	$course_id = absint( $course_id );
	if ( ! $course_id ) {
		return '';
	}

	$price_type = (string) get_post_meta( $course_id, '_tutor_course_price_type', true );

	if ( 'free' === $price_type ) {
		return __( 'Free', 'kouib-blocks-for-tutor-lms' );
	}

	$raw = get_post_meta( $course_id, '_tutor_course_price', true );

	if ( ( '' === (string) $raw || null === $raw ) && function_exists( 'tutor_utils' ) && method_exists( tutor_utils(), 'get_course_price' ) ) {
		$price_obj = tutor_utils()->get_course_price( $course_id );
		if ( is_object( $price_obj ) ) {
			if ( isset( $price_obj->sale_price ) && $price_obj->sale_price ) {
				$raw = $price_obj->sale_price;
			} elseif ( isset( $price_obj->regular_price ) ) {
				$raw = $price_obj->regular_price;
			}
		}
	}

	$raw = trim( (string) $raw );
	if ( '' === $raw ) {
		return __( 'Free', 'kouib-blocks-for-tutor-lms' );
	}

	if ( is_numeric( $raw ) ) {
		return number_format_i18n( (float) $raw, 2 );
	}

	return esc_html( $raw );
}

/**
 * Lesson count for a course — cached per course within the same request (when the
 * same course appears under more than one category, the heavy Tutor queries are not
 * repeated).
 */
function kouib_card_lesson_count( $course_id ) {
	static $counts = array();
	if ( ! array_key_exists( $course_id, $counts ) ) {
		$counts[ $course_id ] = function_exists( 'tutor_utils' )
			? (int) tutor_utils()->get_lesson_count_by_course( $course_id )
			: 0;
	}
	return $counts[ $course_id ];
}

/**
 * Course rating — similar temporary caching per course within the same request.
 */
function kouib_card_rating( $course_id ) {
	static $ratings = array();
	if ( ! array_key_exists( $course_id, $ratings ) ) {
		$ratings[ $course_id ] = function_exists( 'tutor_utils' )
			? tutor_utils()->get_course_rating( $course_id )
			: null;
	}
	return $ratings[ $course_id ];
}

/**
 * Renders a single standalone course card — shared between the filter and the carousel.
 */
function kouib_render_course_card( $post, $show_level, $show_rating, $show_lessons, $show_duration, $show_price, $show_students = true, $show_enroll = true, $enroll_btn_text = '', $open_in_new_tab = false ) {

	if ( '' === $enroll_btn_text ) {
		$enroll_btn_text = __( 'Enroll in course', 'kouib-blocks-for-tutor-lms' );
	}

	ob_start();
	setup_postdata( $post );
	$course_id = $post->ID;

	$level = '';
	if ( $show_level ) {
		$level = function_exists( 'get_tutor_course_level' ) ? get_tutor_course_level( $course_id ) : get_post_meta( $course_id, '_tutor_course_level', true );
	}

	$rating = null;
	if ( $show_rating ) {
		$rating = kouib_card_rating( $course_id );
	}

	$lessons = 0;
	if ( $show_lessons ) {
		$lessons = kouib_card_lesson_count( $course_id );
	}

	$duration = '';
	if ( $show_duration ) {
		$duration = function_exists( 'get_tutor_course_duration_context' ) ? wp_strip_all_tags( get_tutor_course_duration_context( $course_id ) ) : get_post_meta( $course_id, '_course_duration', true );
	}

	/*
	 * Price: we display it as text only (cache-safe) instead of tutor_course_loop_price(),
	 * which outputs an interactive Tutor enrollment button that depends on user
	 * scripts/state and does not work inside the cache or with REST loading (and
	 * produces a link pointing to the homepage).
	 */
	$price_html = '';
	$is_free    = false;
	if ( $show_price ) {
		$price_html = kouib_get_course_price_text( $course_id );
		$is_free    = ( 'free' === get_post_meta( $course_id, '_tutor_course_price_type', true ) );
	}

	// Student count: ready meta data at zero cost — displayed as a badge.
	$students_count = $show_students ? (int) get_post_meta( $course_id, KOUIB_META_STUDENTS, true ) : 0;

	// Is the course new? (within the last 7 days)
	$is_new    = false;
	$published = get_post_time( 'U', true, $course_id );
	if ( $published && ( time() - $published ) <= ( 7 * DAY_IN_SECONDS ) ) {
		$is_new = true;
	}
	?>
	<div class="kouib-course">
		<div class="kouib-thumb">
			<?php echo wp_kses_post( kouib_render_course_thumbnail( $course_id ) ); ?>
			<?php if ( $is_new ) : ?>
				<span class="kouib-ribbon kouib-ribbon-new"><?php echo esc_html__( 'New', 'kouib-blocks-for-tutor-lms' ); ?></span>
			<?php endif; ?>
		</div>
		<div class="kouib-course-content">
			<h3 class="kouib-title">
				<a href="<?php echo esc_url( get_permalink( $course_id ) ); ?>"<?php echo $open_in_new_tab ? ' target="_blank" rel="noopener noreferrer"' : ''; ?>><?php echo esc_html( get_the_title( $course_id ) ); ?></a>
			</h3>
			<div class="kouib-meta-top">
				<?php if ( $level ) : ?><span class="kouib-badge"><?php echo esc_html( $level ); ?></span><?php endif; ?>
				<?php if ( $rating && ! empty( $rating->rating_avg ) ) : ?>
					<span class="kouib-rating">★ <?php echo esc_html( number_format_i18n( $rating->rating_avg, 1 ) ); ?>
						<span class="kouib-rating-count">(<?php echo esc_html( $rating->rating_count ); ?>)</span>
					</span>
				<?php endif; ?>
			</div>
			<div class="kouib-meta-bottom">
				<?php if ( $lessons ) : ?><span class="kouib-meta-item">📚 <?php echo esc_html( sprintf( /* translators: %d: Number of lessons */ __( '%d lesson', 'kouib-blocks-for-tutor-lms' ), $lessons ) ); ?></span><?php endif; ?>
				<?php if ( $duration ) : ?><span class="kouib-meta-item">⏱ <?php echo esc_html( $duration ); ?></span><?php endif; ?>
				<?php if ( $students_count > 0 ) : ?>
					<span class="kouib-meta-item kouib-students">👥 <?php echo esc_html( sprintf( /* translators: %s: Number of students */ __( '%s student', 'kouib-blocks-for-tutor-lms' ), number_format_i18n( $students_count ) ) ); ?></span>
				<?php endif; ?>
				<?php if ( $price_html ) : ?>
					<span class="kouib-meta-item kouib-price <?php echo $is_free ? 'kouib-price-free' : 'kouib-price-paid'; ?>"><?php echo esc_html( $price_html ); ?></span>
				<?php endif; ?>
			</div>
			<?php if ( $show_enroll ) : ?>
			<div class="kouib-course-action">
				<a href="<?php echo esc_url( get_permalink( $course_id ) ); ?>" class="kouib-enroll-btn"<?php echo $open_in_new_tab ? ' target="_blank" rel="noopener noreferrer"' : ''; ?>>
					<?php echo esc_html( $enroll_btn_text ); ?>
				</a>
			</div>
			<?php endif; ?>
		</div>
	</div>
	<?php
	return ob_get_clean();
}

function kouib_render_courses_from_posts( $posts, $show_level, $show_rating, $show_lessons, $show_duration, $show_price, $show_students = true, $show_enroll = true, $term = null, $enroll_btn_text = '', $open_in_new_tab = false ) {

	if ( '' === $enroll_btn_text ) {
		$enroll_btn_text = __( 'Enroll in course', 'kouib-blocks-for-tutor-lms' );
	}

	if ( empty( $posts ) ) {
		return '<div class="kouib-row"><div class="kouib-course" style="justify-content:center;align-items:center;min-height:240px;"><p style="color:#999;margin:0;">' . esc_html__( 'No courses in this category', 'kouib-blocks-for-tutor-lms' ) . '</p></div></div>';
	}

	ob_start();
	?>
	<div class="kouib-row">
		<?php foreach ( $posts as $post ) :
			echo kouib_render_course_card( $post, $show_level, $show_rating, $show_lessons, $show_duration, $show_price, $show_students, $show_enroll, $enroll_btn_text, $open_in_new_tab ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		endforeach; wp_reset_postdata(); ?>
	</div>

	<?php
	return ob_get_clean();
}
