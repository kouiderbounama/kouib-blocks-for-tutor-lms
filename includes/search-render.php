<?php
/**
 * Renders the quick course search block (kouib/courses-search).
 *
 * Outputs a search field + results container, and converts display options into data-attributes
 * read by the frontend script (assets/js/search.js), which then sends REST requests.
 * The result-item generation functions (kouib_search_courses_html) are shared between REST
 * and rendering so the look is identical across all paths.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Search results cache duration (in seconds). Deliberately short; it expires on its own and is not registered in the
 * shared invalidation keys so the cache log is not polluted with thousands of varied queries.
 * Adjustable via: add_filter( 'kouib_search_cache_ttl', fn() => 3 * MINUTE_IN_SECONDS );
 */
function kouib_get_search_cache_ttl() {
	return (int) apply_filters( 'kouib_search_cache_ttl', 2 * MINUTE_IN_SECONDS );
}

/**
 * Truncates and sanitizes the search text for the query, up to a reasonable maximum.
 */
function kouib_sanitize_search_query( $q ) {
	$q = sanitize_text_field( is_string( $q ) ? $q : '' );
	return function_exists( 'mb_substr' ) ? mb_substr( $q, 0, 100 ) : substr( $q, 0, 100 );
}

/**
 * Sanitizes a custom form color (hex/rgb/rgba) or returns '' when invalid/empty.
 */
function kouib_sanitize_search_color( $color ) {
	$color = is_string( $color ) ? trim( $color ) : '';
	if ( preg_match( '/^#[0-9a-fA-F]{3,8}$/', $color )
		|| preg_match( '/^rgba?\(\s*\d+\s*,\s*\d+\s*,\s*\d+\s*(,\s*[\d.]+\s*)?\)$/', $color ) ) {
		return $color;
	}
	return '';
}

/**
 * Normalizes Arabic text for search: strips diacritics/tashkeel/tatweel, unifies similar letters
 * (alef variants to plain alef, teh marbuta to heh, alef maqsura to yeh, waw-with-hamza to waw, yeh-with-hamza to yeh),
 * lowercases Latin text, and squeezes whitespace. Applied on both sides of the comparison (search text + the SQL column expression).
 */
function kouib_normalize_arabic( $text ) {
	if ( ! is_string( $text ) || '' === $text ) {
		return '';
	}

	// Strip diacritics, tashkeel and tatweel
	$text = preg_replace( '/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $text );

	// Unify similar letters
	$map = array(
		'أ' => 'ا',
		'إ' => 'ا',
		'آ' => 'ا',
		'ٱ' => 'ا',
		'ة' => 'ه',
		'ى' => 'ي',
		'ؤ' => 'و',
		'ئ' => 'ي',
	);
	$text = strtr( $text, $map );

	// Lowercase + squeeze whitespace
	$text = function_exists( 'mb_strtolower' ) ? mb_strtolower( $text ) : strtolower( $text );

	return trim( preg_replace( '/\s+/u', ' ', $text ) );
}

/**
 * Returns an SQL expression that normalizes a text column using the same rules as kouib_normalize_arabic
 * (a light nested REPLACE chain), so it can be compared against the normalized pattern with plain LIKE.
 * Note: diacritic removal only happens on the query side; heavy diacritics in stored titles are a very rare
 * case that does not justify the extra REPLACE weight per row.
 *
 * @param string $column Fully qualified column reference like "wp_posts.post_title".
 * @return string SQL expression ready to be placed in WHERE/ORDER BY.
 */
function kouib_sql_normalize_column( $column ) {
	$replaces = array(
		array( 'أ', 'ا' ),
		array( 'إ', 'ا' ),
		array( 'آ', 'ا' ),
		array( 'ٱ', 'ا' ),
		array( 'ة', 'ه' ),
		array( 'ى', 'ي' ),
		array( 'ؤ', 'و' ),
		array( 'ئ', 'ي' ),
	);

	$expr = $column;
	foreach ( $replaces as $pair ) {
		$expr = 'REPLACE(' . $expr . ",'" . $pair[0] . "','" . $pair[1] . "')";
	}

	return 'LOWER(' . $expr . ')';
}

/**
 * Collects matching course IDs via LIKE on normalized columns, matching either the whole
 * phrase or splitting it into words (two or more characters each, any word is enough).
 * Ordering via relevance within the title stage: exact match > starts with the phrase > contains it.
 *
 * @param string $q        The search text (normalized inside the function).
 * @param int    $per_page Maximum number of IDs.
 * @param array  $exclude  Excluded IDs (from earlier stages).
 * @param array  $fields   Fields: post_title / post_excerpt / post_content.
 * @param bool   $phrase   true = the whole phrase as one text; false = any word.
 * @return array Valid IDs.
 */
function kouib_search_ids_query( $q, $per_page, $exclude = array(), $fields = array( 'post_title' ), $phrase = false ) {
	global $wpdb;

	$q = kouib_normalize_arabic( $q );
	if ( '' === $q ) {
		return array();
	}

	$terms = array();
	if ( $phrase ) {
		$terms[] = $q;
	} else {
		foreach ( preg_split( '/\s+/u', $q, -1, PREG_SPLIT_NO_EMPTY ) as $t ) {
			// Ignore single-character letters/words so they do not pollute the results
			if ( ( function_exists( 'mb_strlen' ) ? mb_strlen( $t ) : strlen( $t ) ) >= 2 ) {
				$terms[] = $t;
			}
		}
	}
	if ( empty( $terms ) ) {
		return array();
	}

	$all_fields = array_values( array_intersect( array( 'post_title', 'post_excerpt', 'post_content' ), (array) $fields ) );
	if ( empty( $all_fields ) ) {
		return array();
	}

	$clauses = array();
	$params  = array();
	foreach ( $all_fields as $f ) {
		$col = kouib_sql_normalize_column( "{$wpdb->posts}.{$f}" );
		foreach ( $terms as $t ) {
			$clauses[] = "{$col} LIKE %s";
			$params[]  = '%' . $wpdb->esc_like( $t ) . '%';
		}
	}

	$sql    = "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'courses' AND post_status = 'publish' AND (" . implode( ' OR ', $clauses ) . ')';

	if ( ! empty( $exclude ) ) {
		$exclude = array_values( array_unique( array_map( 'intval', $exclude ) ) );
		$sql    .= ' AND ID NOT IN (' . implode( ',', $exclude ) . ')';
	}

	if ( in_array( 'post_title', $all_fields, true ) ) {
		// Relevance: exact title match > starts with the phrase > contains it > then newest
		$ttl      = kouib_sql_normalize_column( "{$wpdb->posts}.post_title" );
		$sql     .= ' ORDER BY CASE'
			. ' WHEN ' . $ttl . ' = %s THEN 0'
			. ' WHEN ' . $ttl . ' LIKE %s THEN 1'
			. ' WHEN ' . $ttl . ' LIKE %s THEN 2'
			. ' ELSE 3 END'
			. ", {$wpdb->posts}.post_date DESC";
		$params[]  = $q;
		$params[]  = $wpdb->esc_like( $q ) . '%';
		$params[]  = '%' . $wpdb->esc_like( $q ) . '%';
	} else {
		$sql .= " ORDER BY {$wpdb->posts}.post_date DESC";
	}

	$sql      .= ' LIMIT %d';
	$params[]  = max( 1, min( 12, (int) $per_page ) );

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dynamic query built only from fixed internal column expressions; every user value is bound via placeholders.
	$query = $wpdb->prepare( $sql, $params );

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $query is the prepared statement assigned above.
	return array_map( 'intval', $wpdb->get_col( $query ) );
}

/**
 * Core rendering of the search field.
 */
function kouib_render_courses_search( $attributes ) {
	// Block hidden from Settings → Kouib Blocks: not rendered on the frontend (notice only in the editor).
	if ( ! kouib_block_enabled( 'search' ) ) {
		return kouib_block_disabled_output();
	}
	$placeholder = isset( $attributes['placeholder'] ) ? sanitize_text_field( $attributes['placeholder'] ) : '';
// Treat the English default stored in the block attributes as "not customized"
// so the Arabic translation is used on Arabic sites (the block.json default is
// English and older posts keep it saved in the database).
	if ( 'Search for a course...' === $placeholder ) {
		$placeholder = '';
	}
// Guard against a corrupted placeholder (double-encoded mojibake like Ø§Ø¨Ø­Ø«...) stored in the block
// attributes by an old buggy version: if it contains Latin C2/C3 bytes without any
// Arabic characters, treat it as empty and fall back to the clean text.
	if ( '' !== $placeholder
		&& 1 === preg_match( '/[\xC2\xC3]/', $placeholder )
		&& ! preg_match( '/[\x{0620}-\x{06FF}]/u', $placeholder ) ) {
		$placeholder = '';
	}
	if ( '' === $placeholder ) {
		$placeholder = __( 'Search for a course...', 'kouib-blocks-for-tutor-lms' );
	}

	$per_page      = isset( $attributes['perPage'] ) ? max( 1, min( 12, (int) $attributes['perPage'] ) ) : 6;
	$field_width   = isset( $attributes['fieldWidth'] ) ? max( 160, min( 1200, (int) $attributes['fieldWidth'] ) ) : 460;
	$form_align    = isset( $attributes['formAlign'] ) ? $attributes['formAlign'] : 'left';
	if ( ! in_array( $form_align, array( 'left', 'center', 'right' ), true ) ) {
		$form_align = 'left';
	}
	$overlay_results = ! empty( $attributes['overlayResults'] );
	$show_thumb    = ! empty( $attributes['showThumb'] );
	$full_phrase   = ! empty( $attributes['fullPhrase'] );
	$show_price    = ! empty( $attributes['showPrice'] );
	$show_rating   = ! empty( $attributes['showRating'] );
	$show_students = ! empty( $attributes['showStudents'] );
	$open_in_new   = ! empty( $attributes['openInNewTab'] );
	// Primary color: the designer's color if customized, or the global color from the settings page.
	$primary_color = kouib_resolve_primary_color( $attributes );
	// Search field colors are read exclusively from the block attributes (their source is within the block in the editor).
	$field_bg      = kouib_sanitize_style_color( isset( $attributes['fieldBg'] ) ? $attributes['fieldBg'] : '' );
	$field_border  = kouib_sanitize_style_color( isset( $attributes['fieldBorder'] ) ? $attributes['fieldBorder'] : '' );
	$field_text    = kouib_sanitize_style_color( isset( $attributes['fieldText'] ) ? $attributes['fieldText'] : '' );
	$icon_color    = kouib_sanitize_style_color( isset( $attributes['iconColor'] ) ? $attributes['iconColor'] : '' );

	kouib_enqueue_frontend_assets( 'search' );

	$our_style  = '--kouib-primary:' . esc_attr( $primary_color ) . ';';
	if ( '' !== $field_bg ) {
		$our_style .= '--kouib-search-bg:' . esc_attr( $field_bg ) . ';';
	}
	if ( '' !== $field_border ) {
		$our_style .= '--kouib-search-border:' . esc_attr( $field_border ) . ';';
	}
	if ( '' !== $field_text ) {
		$our_style .= '--kouib-search-text:' . esc_attr( $field_text ) . ';';
	}
	if ( '' !== $icon_color ) {
		$our_style .= '--kouib-search-icon:' . esc_attr( $icon_color ) . ';';
	}

	// Field width is user-controllable (does not take the full page width) + alignment
	$our_style .= 'width:' . absint( $field_width ) . 'px;max-width:100%;';
	if ( 'center' === $form_align ) {
		$our_style .= 'margin:0 auto;';
	} elseif ( 'right' === $form_align ) {
		$our_style .= 'margin-left:auto;margin-right:0;';
	} else {
		$our_style .= 'margin-left:0;margin-right:auto;';
	}
	$direction  = is_rtl() ? 'rtl' : 'ltr';
	$our_style .= 'direction:' . $direction . ';';

	if ( function_exists( 'get_block_wrapper_attributes' ) ) {
		$wrapper_attrs = get_block_wrapper_attributes( array(
			'class' => 'kouib-search-wrapper',
			'style' => $our_style,
		) );
	} else {
		$wrapper_attrs = sprintf(
			'class="kouib-search-wrapper" style="%s"',
			esc_attr( $our_style )
		);
	}

	// JS data: REST endpoint + display options + translated error message
	$wrapper_attrs .= sprintf(
		' dir="%1$s" data-kouib-search="1" data-kouibs-rest="%2$s" data-kouibs-perpage="%3$d" data-kouibs-showthumb="%4$s" data-kouibs-showprice="%5$s" data-kouibs-showrating="%6$s" data-kouibs-showstudents="%7$s" data-kouibs-open="%8$s" data-kouibs-phrase="%9$s" data-kouibs-error="%10$s"',
		esc_attr( $direction ),
		esc_url( rest_url( 'kouib/v1/search' ) ),
		absint( $per_page ),
		$show_thumb ? '1' : '0',
		$show_price ? '1' : '0',
		$show_rating ? '1' : '0',
		$show_students ? '1' : '0',
		$open_in_new ? '1' : '0',
		$full_phrase ? '1' : '0',
		esc_attr( __( 'Search failed right now, try again later.', 'kouib-blocks-for-tutor-lms' ) )
	);

	ob_start();
	?>
	<div <?php echo $wrapper_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped internally ?>>
		<div class="kouib-search-field">
			<svg class="kouib-search-icon" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false">
				<path fill="currentColor" d="M15.5 14h-.79l-.28-.27A6.47 6.47 0 0 0 16 9.5 6.5 6.5 0 1 0 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/>
			</svg>
			<input
				type="search"
				class="kouib-search-input"
				value=""
				placeholder="<?php echo esc_attr( $placeholder ); ?>"
				autocomplete="off"
				enterkeyhint="search"
				role="combobox"
				aria-autocomplete="list"
				aria-expanded="false"
				aria-controls="kouib-search-results"
				aria-label="<?php echo esc_attr( $placeholder ); ?>"
			/>
			<span class="kouib-search-spinner" hidden></span>
		</div>
		<div class="kouib-search-results<?php echo $overlay_results ? '' : ' kouib-search-inline'; ?>" id="kouib-search-results" role="listbox" hidden></div>
	</div>
	<?php
	return ob_get_clean();
}

/**
 * Builds the HTML for a single result item (consistent with the plugin's light card style).
 *
 * @param WP_Post $post         The course.
 * @param array   $args         Display options (show_thumb, show_price, show_rating, show_students, open_new).
 * @return string Safe HTML.
 */
function kouib_search_result_item( $post, $args ) {
	$course_id  = $post->ID;
	$permalink  = get_permalink( $course_id );
	$open_new   = ! empty( $args['open_new'] );
	$show_thumb = ! empty( $args['show_thumb'] );
	$show_price = ! empty( $args['show_price'] );
	$show_rate  = ! empty( $args['show_rating'] );
	$show_stud  = ! empty( $args['show_students'] );

	$thumb_html = '';
	if ( $show_thumb && has_post_thumbnail( $course_id ) ) {
		$img_args = array( 'decoding' => 'async' );
		if ( kouib_lazy_images_enabled() ) {
			$img_args['loading'] = 'lazy';
		}
		$thumb_html = '<span class="kouib-search-thumb">' . get_the_post_thumbnail( $course_id, 'thumbnail', $img_args ) . '</span>';
	}

	$meta = array();

	if ( $show_rate ) {
		$rating = kouib_card_rating( $course_id );
		if ( $rating && ! empty( $rating->rating_avg ) ) {
			$meta[] = '<span class="kouib-search-meta kouib-search-rating">★ ' . esc_html( number_format_i18n( $rating->rating_avg, 1 ) ) . '</span>';
		}
	}

	if ( $show_stud ) {
		$students = (int) get_post_meta( $course_id, KOUIB_META_STUDENTS, true );
		if ( $students > 0 ) {
			$meta[] = '<span class="kouib-search-meta kouib-search-students">' . esc_html( sprintf(
				/* translators: %s: number of students */
				__( '%s student', 'kouib-blocks-for-tutor-lms' ),
				number_format_i18n( $students )
			) ) . '</span>';
		}
	}

	if ( $show_price ) {
		$price = kouib_get_course_price_text( $course_id );
		if ( '' !== $price ) {
			$is_free = ( 'free' === get_post_meta( $course_id, '_tutor_course_price_type', true ) );
			$meta[]  = '<span class="kouib-search-meta kouib-search-price ' . ( $is_free ? 'kouib-search-price-free' : 'kouib-search-price-paid' ) . '">' . esc_html( $price ) . '</span>';
		}
	}

	$extra = $open_new ? ' target="_blank" rel="noopener noreferrer"' : '';

	return sprintf(
		'<a class="kouib-search-item" href="%s" role="option"%s>%s<span class="kouib-search-body"><span class="kouib-search-title">%s</span>%s</span></a>',
		esc_url( $permalink ),
		$extra,
		$thumb_html,
		esc_html( get_the_title( $course_id ) ),
		$meta ? '<span class="kouib-search-meta-wrap">' . implode( '', $meta ) . '</span>' : ''
	);
}

/**
 * Runs the search and returns the ready-made list HTML (or a "no results" message).
 * Shared between the REST endpoint and the render path.
 *
 * Stages: Arabic-normalized titles ordered by relevance (exact > prefix > contains), then
 * fill-in from title/excerpt/content, then completion from categories matching
 * their names — all on normalized text (alef variants to plain alef, teh marbuta to heh,
 * alef maqsura to yeh, no diacritics).
 *
 * @param string $q         The search text.
 * @param int    $per_page  Number of results (1-12).
 * @param array  $flags     Display options (+ sentence for the whole phrase).
 * @return string Safe HTML.
 */
function kouib_search_courses_html( $q, $per_page = 6, $flags = array() ) {
	global $wpdb;

	$q        = kouib_normalize_arabic( kouib_sanitize_search_query( $q ) );
	$per_page = max( 1, min( 12, (int) $per_page ) );

	if ( '' === $q ) {
		return '<p class="kouib-search-no-results">' . esc_html__( 'Type to search courses...', 'kouib-blocks-for-tutor-lms' ) . '</p>';
	}

// Short self-expiring cache for common queries (and most importantly: the parts typed while typing).
// The key uses the normalized text — so differently-spelled variants of the same word share the same result and cache.
// Not registered in the shared invalidation keys — it expires via TTL with no side effects.
	$cacheable = ( function_exists( 'mb_strlen' ) ? mb_strlen( $q ) : strlen( $q ) ) >= 3;
	$cache_key = null;
	if ( $cacheable ) {
		$bits      = array( 'q' => $q, 'pp' => $per_page, 'f' => $flags );
		ksort( $bits );
		$cache_key = 'kouib_srch_' . KOUIB_VERSION . '_' . md5( wp_json_encode( $bits ) );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}
	}

	// 1) Stage one — title-only matching: the most precise and closest to user intent
	$sentence = ! empty( $flags['sentence'] );
	$ids      = kouib_search_ids_query( $q, $per_page, array(), array( 'post_title' ), $sentence );

	// 2) Stage two — fill the rest from titles + excerpt + content
	if ( count( $ids ) < $per_page ) {
		$more = kouib_search_ids_query(
			$q,
			$per_page - count( $ids ),
			$ids,
			array( 'post_title', 'post_excerpt', 'post_content' ),
			$sentence
		);
		$ids = array_merge( $ids, $more );
	}

	// 3) Stage three — courses from categories whose (Arabic-normalized) names match, to complete the result set
	if ( count( $ids ) < $per_page ) {
		$col    = kouib_sql_normalize_column( "{$wpdb->terms}.name" );
		$sql    = "SELECT tt.term_id FROM {$wpdb->term_taxonomy} tt"
			. " INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id"
			. " WHERE tt.taxonomy = 'course-category' AND tt.count > 0 AND {$col} LIKE %s"
			. ' ORDER BY t.name ASC LIMIT 5';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$col} is whitelisted via kouib_sql_normalize_column().
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Dynamic query; the only user-supplied value is bound via the %s placeholder.
		$query   = $wpdb->prepare( $sql, '%' . $wpdb->esc_like( $q ) . '%' );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $query is the prepared statement assigned above.
		$term_ids = array_map( 'intval', (array) $wpdb->get_col( $query ) );

		if ( ! empty( $term_ids ) ) {
			$by_cat = get_posts( array(
				'post_type'      => 'courses',
				'post_status'    => 'publish',
				'tax_query'      => array(
					array(
						'taxonomy' => 'course-category',
						'field'    => 'term_id',
						'terms'    => $term_ids,
					),
				),
				'post__not_in' => $ids,
				'posts_per_page' => $per_page - count( $ids ),
				'no_found_rows'  => true,
			) );

			foreach ( $by_cat as $p ) {
				$ids[] = (int) $p->ID;
			}
		}
	}

	$ids = array_values( array_unique( array_slice( $ids, 0, $per_page ) ) );

	if ( empty( $ids ) ) {
		$html = '<p class="kouib-search-no-results">' . esc_html__( 'No matching results', 'kouib-blocks-for-tutor-lms' ) . '</p>';
	} else {
		$posts = kouib_get_posts_by_ids( $ids );

		$item_html = '';
		foreach ( $posts as $post ) {
			$item_html .= kouib_search_result_item( $post, $flags );
		}
		$html = $item_html;
	}

	if ( null !== $cache_key ) {
		set_transient( $cache_key, $html, kouib_get_search_cache_ttl() );
	}

	return $html;
}