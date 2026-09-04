<?php
/**
 * Renders the courses carousel block (kouib/courses-carousel).
 *
 * Uses the same shared query layer from render.php
 * (kouib_build_query_args + kouib_get_grouped_courses) and the shared card
 * rendering kouib_render_course_card(). The cache uses the shared kouib_ prefix
 * (kouib_carousel_ key) and is registered as an active key, so it is
 * automatically flushed with the rest of the plugin's cache.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Clamps a numeric value within a given range.
 */
function kouib_clamp( $value, $min, $max ) {
	return max( $min, min( $max, (int) $value ) );
}

/**
 * Returns a safe primary color (hex or rgb/rgba) or the default when invalid.
 */
function kouib_sanitize_primary_color( $color ) {
	if ( preg_match( '/^#[0-9a-fA-F]{3,8}$/', $color )
		|| preg_match( '/^rgba?\(\s*\d+\s*,\s*\d+\s*,\s*\d+\s*(,\s*[\d.]+\s*)?\)$/', $color ) ) {
		return $color;
	}
	return '#2a7be4';
}

/**
 * Core carousel rendering.
 */
function kouib_render_courses_carousel( $attributes ) {

	// Block hidden in the settings (Kouib Blocks): not displayed on the frontend (editor-only notice).
	if ( ! kouib_block_enabled( 'carousel' ) ) {
		return kouib_block_disabled_output();
	}

	$is_editor_request = defined( 'REST_REQUEST' ) && REST_REQUEST;

	$orderby_attr = isset( $attributes['orderby'] ) ? $attributes['orderby'] : 'date';
	$category_ids = isset( $attributes['categories'] )
		? array_map( 'absint', (array) $attributes['categories'] )
		: array();

	// Cache: check early before any query or rendering (return directly on a hit).
	$cache_key = null;
	if ( ! $is_editor_request && 'rand' !== $orderby_attr ) {
		$cache_attributes = $attributes;
		ksort( $cache_attributes );
		$cache_key = 'kouib_carousel_' . KOUIB_VERSION . '_' . md5( wp_json_encode( $cache_attributes ) );
		kouib_register_active_key( $cache_key );

		$cached = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}
	}

	kouib_enqueue_frontend_assets( 'carousel' );

	$courses_to_show  = isset( $attributes['coursesToShow'] ) ? kouib_clamp( $attributes['coursesToShow'], 1, 24 ) : 3;
	$columns          = isset( $attributes['columns'] ) ? kouib_clamp( $attributes['columns'], 1, 4 ) : 3;
	$columns_tablet   = isset( $attributes['columnsTablet'] ) ? kouib_clamp( $attributes['columnsTablet'], 1, 4 ) : 2;
	$columns_mobile   = isset( $attributes['columnsMobile'] ) ? kouib_clamp( $attributes['columnsMobile'], 1, 4 ) : 1;
	$autoplay         = ! empty( $attributes['autoplay'] );
	$autoplay_speed   = isset( $attributes['autoplaySpeed'] ) ? kouib_clamp( $attributes['autoplaySpeed'], 1000, 10000 ) : 3000;
	$speed            = isset( $attributes['speed'] ) ? kouib_clamp( $attributes['speed'], 100, 2000 ) : 500;
	$infinite_loop    = ! empty( $attributes['infiniteLoop'] );
	$show_arrows      = ! empty( $attributes['showArrows'] );
	$show_dots        = ! empty( $attributes['showDots'] );
	$pause_on_hover   = ! empty( $attributes['pauseOnHover'] );
	$show_level       = ! empty( $attributes['showLevel'] );
	$show_rating      = ! empty( $attributes['showRating'] );
	$show_lessons     = ! empty( $attributes['showLessons'] );
	$show_duration    = ! empty( $attributes['showDuration'] );
	$show_price       = ! empty( $attributes['showPrice'] );
	$show_students    = ! empty( $attributes['showStudents'] );
	$show_enroll      = ! empty( $attributes['showEnrollBtn'] );
	$open_in_new_tab  = ! empty( $attributes['openInNewTab'] );
	// Color: the designer's custom color if set, otherwise the general color from the settings panel.
	$primary_color    = kouib_resolve_primary_color( $attributes );

	$enroll_btn_text = isset( $attributes['enrollBtnText'] ) ? sanitize_text_field( $attributes['enrollBtnText'] ) : '';
	if ( in_array( $enroll_btn_text, array( '', 'Enroll in course' ), true ) ) {
		$enroll_btn_text = __( 'Enroll in course', 'kouib-blocks-for-tutor-lms' );
	}

	$query_args = kouib_build_query_args( $orderby_attr, $category_ids );
	// Query limited to the requested number of courses only (no loading all courses in full):
	// Better performance on pages and in the cache, while respecting ordering and categories.
	$posts = kouib_get_courses_bounded( $query_args, $courses_to_show );

	if ( empty( $posts ) ) {
		return '<p style="text-align:center;padding:40px;color:#666;">' . esc_html__( 'No courses are available yet.', 'kouib-blocks-for-tutor-lms' ) . '</p>';
	}

	$our_style  = '--kouib-primary:' . esc_attr( $primary_color ) . ';';
	$our_style .= '--kouib-carousel-columns-desktop:' . absint( $columns ) . ';';
	$our_style .= '--kouib-carousel-columns-tablet:' . absint( $columns_tablet ) . ';';
	$our_style .= '--kouib-carousel-columns-mobile:' . absint( $columns_mobile ) . ';';
	$our_style .= '--kouib-carousel-gap:22px;';

	$direction = is_rtl() ? 'rtl' : 'ltr';
	$our_style .= 'direction:' . $direction . ';';

	$has_shadow = isset( $attributes['hasShadow'] ) ? ! empty( $attributes['hasShadow'] ) : true;
	$wrapper_class = 'kouib-carousel-wrapper' . ( $has_shadow ? '' : ' kouib-no-card-shadow' );

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

	// JS data: animation and navigation settings.
	$wrapper_attrs .= sprintf(
		' dir="%1$s" data-kouibc-autoplay="%2$s" data-kouibc-autoplay-speed="%3$d" data-kouibc-speed="%4$d" data-kouibc-infinite="%5$s" data-kouibc-arrows="%6$s" data-kouibc-dots="%7$s" data-kouibc-hover-pause="%8$s" data-kouibc-columns="%9$d" data-kouibc-cols-tablet="%10$d" data-kouibc-cols-mobile="%11$d"',
		esc_attr( $direction ),
		$autoplay ? '1' : '0',
		absint( $autoplay_speed ),
		absint( $speed ),
		$infinite_loop ? '1' : '0',
		$show_arrows ? '1' : '0',
		$show_dots ? '1' : '0',
		$pause_on_hover ? '1' : '0',
		absint( $columns ),
		absint( $columns_tablet ),
		absint( $columns_mobile )
	);

	ob_start();
	?>
	<div <?php echo $wrapper_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped internally ?>>
		<div class="kouib-carousel<?php echo $show_arrows ? '' : ' kouibc-no-arrows'; ?>">
			<div class="kouib-carousel-viewport">
				<div class="kouib-carousel-track">
					<?php foreach ( $posts as $post ) : ?>
						<div class="kouib-carousel-slide">
							<?php echo kouib_render_course_card( $post, $show_level, $show_rating, $show_lessons, $show_duration, $show_price, $show_students, $show_enroll, $enroll_btn_text, $open_in_new_tab ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</div>
					<?php endforeach; wp_reset_postdata(); ?>
				</div>
			</div>
			<?php if ( $show_arrows ) : ?>
				<?php
				// Arrows are flipped in RTL: prev (right) points right, next (left) points left.
				$chevron_left  = 'M15.41 7.41 14 6l-6 6 6 6 1.41-1.41L10.83 12z';
				$chevron_right = 'M8.59 16.59 10 18l6-6-6-6-1.41 1.41L13.17 12z';
				$prev_path     = ( 'rtl' === $direction ) ? $chevron_right : $chevron_left;
				$next_path     = ( 'rtl' === $direction ) ? $chevron_left : $chevron_right;
				?>
				<button type="button" class="kouib-carousel-prev" aria-label="<?php esc_attr_e( 'Prev', 'kouib-blocks-for-tutor-lms' ); ?>">
					<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><path fill="currentColor" d="<?php echo esc_attr( $prev_path ); ?>"/></svg>
				</button>
				<button type="button" class="kouib-carousel-next" aria-label="<?php esc_attr_e( 'Next', 'kouib-blocks-for-tutor-lms' ); ?>">
					<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><path fill="currentColor" d="<?php echo esc_attr( $next_path ); ?>"/></svg>
				</button>
			<?php endif; ?>
		</div>
		<?php if ( $show_dots ) : ?>
			<div class="kouib-carousel-dots" aria-label="<?php esc_attr_e( 'Course navigation', 'kouib-blocks-for-tutor-lms' ); ?>"></div>
		<?php endif; ?>
	</div>
	<?php
	$html = ob_get_clean();

	// Cache is not stored in the editor, nor with random ordering (to keep it fresh).
	if ( null !== $cache_key ) {
		set_transient( $cache_key, $html, kouib_get_cache_ttl() );
	}

	return $html;
}