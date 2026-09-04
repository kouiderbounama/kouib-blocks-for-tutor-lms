<?php
/**
 * Central settings page (Settings → Kouib Blocks) and option helpers.
 *
 * - Default cache TTL for all blocks.
 * - Global primary color (applied to every block unless the editor sets its own).
 * - Show/hide each block (it disappears from the editor and frontend without deleting content).
 *
 * All of them are stored in a single "kouib_settings" option (an array) via the Settings API.
 * Any settings save flushes the cache automatically because the color/size is printed inline in
 * the cached HTML, and because a new TTL must take effect immediately.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'KOUIB_SETTINGS_OPTION' ) ) {
	define( 'KOUIB_SETTINGS_OPTION', 'kouib_settings' );
}

/**
 * Loads the color picker (wp-color-picker) only on the settings page.
 */
function kouib_admin_enqueue_settings_assets( $hook_suffix ) {
	if ( 'settings_page_kouib-settings' !== $hook_suffix ) {
		return;
	}
	wp_enqueue_style( 'wp-color-picker' );
	wp_enqueue_script( 'wp-color-picker' );

	// Toggle the custom thumbnail size fields when "Custom" is selected.
	wp_add_inline_script(
		'wp-color-picker',
		'( function() {
			var sel = document.getElementById( "kouib-thumb-size" );
			var wrap = document.getElementById( "kouib-thumb-custom-fields" );
			if ( ! sel || ! wrap ) { return; }
			wrap.style.display = ( "custom" === sel.value ) ? "block" : "none";
			sel.addEventListener( "change", function() {
				wrap.style.display = ( "custom" === sel.value ) ? "block" : "none";
			} );
		}() );'
	);

	// Bind our color-picker inputs to the WP color picker once.
	wp_add_inline_script(
		'wp-color-picker',
		'( function() {
			var inputs = document.querySelectorAll( ".kouib-color-picker" );
			if ( ! window.wp || ! window.wp.wpColorPicker ) { return; }
			inputs.forEach( function( input ) {
				var wrap = input.closest( "td" ) || input.parentNode;
				if ( wrap && ! wrap.querySelector( ".wp-picker-container" ) ) {
					jQuery( input ).wpColorPicker( { defaultColor: input.getAttribute( "data-default-color" ) || "" } );
				}
			} );
		}() );'
	);
}
add_action( 'admin_enqueue_scripts', 'kouib_admin_enqueue_settings_assets' );

/**
 * Default starting values for all options.
 */
function kouib_settings_defaults() {
	return array(
		'cache_ttl'          => 30 * MINUTE_IN_SECONDS,
		'primary_color'      => '#2a7be4',
		'lazy_images'        => true,
		'thumb_size'         => 'medium_large',
		'thumb_custom_width' => 400,
		'thumb_custom_height'=> 300,
		'block_filter'       => true,
		'block_carousel'     => true,
		'block_categories'   => true,
		'block_search'       => true,
		'block_stats'        => true,
	);
}

/**
 * Returns the kouib_settings option merged with the defaults (once per request).
 */
function kouib_get_settings() {
	static $settings = null;
	if ( null === $settings ) {
		$stored  = get_option( KOUIB_SETTINGS_OPTION, array() );
		$stored  = is_array( $stored ) ? $stored : array();
		$settings = wp_parse_args( $stored, kouib_settings_defaults() );
	}
	return $settings;
}

/**
 * Returns a single setting value with an optional manual fallback.
 */
function kouib_get_setting( $key, $default = '' ) {
	$settings = kouib_get_settings();
	return isset( $settings[ $key ] ) ? $settings[ $key ] : $default;
}

/**
 * Sanitizes a color (hex or rgb/rgba) or returns '' when invalid/empty.
 */
function kouib_sanitize_style_color( $color ) {
	$color = is_string( $color ) ? trim( $color ) : '';
	if ( '' === $color ) {
		return '';
	}
	if ( preg_match( '/^#[0-9a-fA-F]{3,8}$/', $color )
		|| preg_match( '/^rgba?\(\s*\d+\s*,\s*\d+\s*,\s*\d+\s*(,\s*[\d.]+\s*)?\)$/', $color ) ) {
		return $color;
	}
	return '';
}

/**
 * Global primary color from settings (always returns a safe result).
 */
function kouib_get_primary_color() {
	$color = kouib_sanitize_style_color( (string) kouib_get_setting( 'primary_color', '#2a7be4' ) );
	return ( '' === $color ) ? '#2a7be4' : $color;
}

/**
 * Resolves the primary color for a block:
 * - If the user set a custom color in the editor (different from the plugin default)
 *   that color is honored.
 * - Otherwise (not customized, or reset to default) the global settings color is used.
 */
function kouib_resolve_primary_color( $attributes ) {
	$value = isset( $attributes['primaryColor'] ) ? $attributes['primaryColor'] : '';
	$value = kouib_sanitize_style_color( $value );
	if ( '' === $value || '#2a7be4' === strtolower( $value ) ) {
		return kouib_get_primary_color();
	}
	return $value;
}

/**
 * Is a given block allowed to show (available in the editor and visible on the frontend)?
 *
 * @param string $which 'filter' | 'carousel' | 'categories' | 'search' | 'stats'
 */
function kouib_block_enabled( $which ) {
	if ( ! in_array( $which, array( 'filter', 'carousel', 'categories', 'search', 'stats' ), true ) ) {
		return true;
	}
	return (bool) kouib_get_setting( 'block_' . $which, true );
}

/**
 * Output for a hidden block: a clear notice in the editor and full emptiness on the frontend (not rendered).
 */
function kouib_block_disabled_output() {
	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		return '<p class="kouib-block-disabled">' . esc_html__( 'This block is hidden from Settings → Kouib Blocks.', 'kouib-blocks-for-tutor-lms' ) . '</p>';
	}
	return '';
}

/* ==========================================================================
 * 2b. Image settings (size and lazy loading)
 * ========================================================================== */

/**
 * The thumbnail size chosen in settings (or 'medium_large' by default).
 */
function kouib_get_image_size_setting() {
	$size = (string) kouib_get_setting( 'thumb_size', 'medium_large' );
	if ( ! in_array( $size, array( 'thumbnail', 'medium', 'medium_large', 'large', 'full', 'custom' ), true ) ) {
		$size = 'medium_large';
	}
	return $size;
}

/**
 * Dimensions of the custom size (when 'custom' is chosen) with allowed values.
 */
function kouib_get_custom_thumb_size() {
	$w = (int) kouib_get_setting( 'thumb_custom_width', 400 );
	$h = (int) kouib_get_setting( 'thumb_custom_height', 300 );

	$w = max( 0, min( 1920, $w ) );
	$h = max( 0, min( 1920, $h ) );
	if ( 0 === $w ) {
		$w = 400;
	}
	return array( $w, $h );
}

/**
 * Is lazy loading enabled? (Enabled by default.)
 */
function kouib_lazy_images_enabled() {
	return (bool) kouib_get_setting( 'lazy_images', true );
}

/**
 * Registers the custom size as an intermediate size so it is generated for newly uploaded images.
 */
function kouib_register_custom_thumb_size() {
	$custom = kouib_get_custom_thumb_size();
	add_image_size( 'kouib-thumb-custom', $custom[0], $custom[1], false );
}
add_action( 'init', 'kouib_register_custom_thumb_size' );

/**
 * Renders a course thumbnail according to the image settings (size + lazy loading).
 */
function kouib_render_course_thumbnail( $course_id ) {
	$course_id = absint( $course_id );
	if ( ! $course_id || ! has_post_thumbnail( $course_id ) ) {
		return '';
	}

	$args = array( 'decoding' => 'async' );
	if ( kouib_lazy_images_enabled() ) {
		$args['loading'] = 'lazy';
	}

	$size = kouib_get_image_size_setting();
	if ( 'custom' === $size ) {
		$size = 'kouib-thumb-custom';
	}

	return get_the_post_thumbnail( $course_id, $size, $args );
}

/* ==========================================================================
 * 1. Register the option and elements through the Settings API
 * ========================================================================== */

function kouib_register_settings_page() {
	add_options_page(
		__( 'Course Blocks Settings', 'kouib-blocks-for-tutor-lms' ),
		__( 'Kouib Blocks', 'kouib-blocks-for-tutor-lms' ),
		'manage_options',
		'kouib-settings',
		'kouib_render_settings_page'
	);
}
add_action( 'admin_menu', 'kouib_register_settings_page' );

function kouib_register_settings_fields() {
	register_setting(
		'kouib_settings_group',
		KOUIB_SETTINGS_OPTION,
		array(
			'sanitize_callback' => 'kouib_sanitize_settings',
			'default'           => kouib_settings_defaults(),
		)
	);

	add_settings_section(
		'kouib_settings_general',
		__( 'General Settings', 'kouib-blocks-for-tutor-lms' ),
		'kouib_settings_section_cb',
		'kouib_settings_page'
	);

	add_settings_field(
		'cache_ttl',
		__( 'Cache duration (seconds)', 'kouib-blocks-for-tutor-lms' ),
		'kouib_settings_field_cache_ttl',
		'kouib_settings_page',
		'kouib_settings_general'
	);

	add_settings_field(
		'primary_color',
		__( 'Default primary color', 'kouib-blocks-for-tutor-lms' ),
		'kouib_settings_field_primary_color',
		'kouib_settings_page',
		'kouib_settings_general'
	);

	add_settings_section(
		'kouib_settings_images',
		__( 'Images', 'kouib-blocks-for-tutor-lms' ),
		'kouib_settings_images_section_cb',
		'kouib_settings_page'
	);

	add_settings_field(
		'lazy_images',
		__( 'Lazy loading', 'kouib-blocks-for-tutor-lms' ),
		'kouib_settings_field_lazy_images',
		'kouib_settings_page',
		'kouib_settings_images'
	);

	add_settings_field(
		'thumb_size',
		__( 'Thumbnail size', 'kouib-blocks-for-tutor-lms' ),
		'kouib_settings_field_thumb_size',
		'kouib_settings_page',
		'kouib_settings_images'
	);

	add_settings_section(
		'kouib_settings_blocks',
		__( 'Blocks', 'kouib-blocks-for-tutor-lms' ),
		'kouib_settings_blocks_section_cb',
		'kouib_settings_page'
	);

	$block_fields = array(
		'block_filter'     => __( 'Courses Filter', 'kouib-blocks-for-tutor-lms' ),
		'block_carousel'   => __( 'Courses Carousel', 'kouib-blocks-for-tutor-lms' ),
		'block_categories' => __( 'Category Boxes', 'kouib-blocks-for-tutor-lms' ),
		'block_search'     => __( 'Quick Search', 'kouib-blocks-for-tutor-lms' ),
		'block_stats'      => __( 'Platform Stats', 'kouib-blocks-for-tutor-lms' ),
	);
	foreach ( $block_fields as $key => $label ) {
		add_settings_field(
			$key,
			$label,
			'kouib_settings_field_block_visible',
			'kouib_settings_page',
			'kouib_settings_blocks',
			array( 'key' => $key )
		);
	}
}
add_action( 'admin_init', 'kouib_register_settings_fields' );

/* ==========================================================================
 * 2. Page and field rendering functions
 * ========================================================================== */

function kouib_settings_section_cb() {
	echo '<p>' . esc_html__( 'General block behavior; values here apply to any block without its own override.', 'kouib-blocks-for-tutor-lms' ) . '</p>';
}

function kouib_settings_blocks_section_cb() {
	echo '<p>' . esc_html__( 'Hidden blocks: they no longer appear in the block inserter (cannot be added), are not rendered on the frontend, and disappear from your pages without deleting content; re-showing the block restores everything.', 'kouib-blocks-for-tutor-lms' ) . '</p>';
}

function kouib_settings_images_section_cb() {
	echo '<p>' . esc_html__( 'Applies to course images in filter, carousel and search result cards.', 'kouib-blocks-for-tutor-lms' ) . '</p>';
}

function kouib_settings_field_lazy_images() {
	$on = kouib_lazy_images_enabled();
	printf(
		'<label><input type="checkbox" name="%1$s[lazy_images]" value="1" %2$s /> %3$s</label>',
		esc_attr( KOUIB_SETTINGS_OPTION ),
		checked( $on, true, false ),
		esc_html__( 'Load images only when near the viewport (faster page load)', 'kouib-blocks-for-tutor-lms' )
	);
}

function kouib_settings_field_thumb_size() {
	$current = kouib_get_image_size_setting();
	$container = '<p><select name="%1$s[thumb_size]" id="kouib-thumb-size">';
	foreach ( array(
		'thumbnail'    => __( 'Thumbnail', 'kouib-blocks-for-tutor-lms' ),
		'medium'       => __( 'Medium (medium)', 'kouib-blocks-for-tutor-lms' ),
		'medium_large' => __( 'Medium large (medium_large)', 'kouib-blocks-for-tutor-lms' ),
		'large'        => __( 'Large (large)', 'kouib-blocks-for-tutor-lms' ),
		'full'         => __( 'Original (full — no resize)', 'kouib-blocks-for-tutor-lms' ),
		'custom'       => __( 'Custom size', 'kouib-blocks-for-tutor-lms' ),
	) as $value => $label ) {
		$container .= sprintf(
			'<option value="%1$s" %2$s>%3$s</option>',
			esc_attr( $value ),
			selected( $current, $value, false ),
			esc_html( $label )
		);
	}
	$container .= '</select></p>';
	printf( $container, esc_attr( KOUIB_SETTINGS_OPTION ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

	list( $w, $h ) = kouib_get_custom_thumb_size();
	printf(
		'<p id="kouib-thumb-custom-fields"><label>%1$s <input type="number" min="0" max="1920" step="10" name="%2$s[thumb_custom_width]" value="%3$d" class="small-text" /></label> × <label>%4$s <input type="number" min="0" max="1920" step="10" name="%2$s[thumb_custom_height]" value="%5$d" class="small-text" /></label></p>',
		esc_html__( 'Width (px)', 'kouib-blocks-for-tutor-lms' ),
		esc_attr( KOUIB_SETTINGS_OPTION ),
		absint( $w ),
		esc_html__( 'Height (px; 0 = auto by aspect ratio)', 'kouib-blocks-for-tutor-lms' ),
		absint( $h )
	);

	echo '<p class="description">' . esc_html__( 'New images are generated at the custom size automatically; existing images fall back to the closest large original size.', 'kouib-blocks-for-tutor-lms' ) . '</p>';
}

function kouib_settings_field_cache_ttl() {
	$value = (int) kouib_get_setting( 'cache_ttl', 30 * MINUTE_IN_SECONDS );
	printf(
		'<input type="number" min="30" max="%1$d" step="30" name="%2$s[cache_ttl]" value="%3$d" class="small-text" />',
		absint( 7 * DAY_IN_SECONDS ),
		esc_attr( KOUIB_SETTINGS_OPTION ),
		absint( $value )
	);
	echo '<p class="description">' . esc_html__( 'Duration between 30 seconds and 7 days. Default is 1800 (30 minutes).', 'kouib-blocks-for-tutor-lms' ) . '</p>';
}

function kouib_settings_field_primary_color() {
	$value = kouib_get_primary_color();
	printf(
		'<input type="text" name="%1$s[primary_color]" value="%2$s" class="kouib-color-picker" data-default-color="#2a7be4" />',
		esc_attr( KOUIB_SETTINGS_OPTION ),
		esc_attr( $value )
	);
	echo '<p class="description">' . esc_html__( 'Default color for enroll buttons, links, ribbons and stat numbers across all blocks.', 'kouib-blocks-for-tutor-lms' ) . '</p>';
}

function kouib_settings_field_block_visible( $args ) {
	$key = isset( $args['key'] ) ? $args['key'] : '';
	$on  = (bool) kouib_get_setting( $key, true );
	printf(
		'<label><input type="checkbox" name="%1$s[%2$s]" value="1" %3$s /> %4$s</label>',
		esc_attr( KOUIB_SETTINGS_OPTION ),
		esc_attr( $key ),
		checked( $on, true, false ),
		esc_html__( 'Visible', 'kouib-blocks-for-tutor-lms' )
	);
}

/**
 * Plugin health check: is Tutor LMS active, and are the content types, taxonomies and blocks ready.
 */
function kouib_render_health_check() {
	$registry  = WP_Block_Type_Registry::get_instance();
	$my_blocks = array( 'kouib/courses-filter', 'kouib/courses-carousel', 'kouib/courses-categories', 'kouib/courses-search', 'kouib/courses-stats' );

	$counts = (array) wp_count_posts( 'courses' );
	$published = isset( $counts['publish'] ) ? (int) $counts['publish'] : 0;

	$registered_count = 0;
	foreach ( $my_blocks as $name ) {
		if ( $registry->is_registered( $name ) ) {
			$registered_count++;
		}
	}

	$checks = array(
		array(
			'label'  => __( 'Tutor LMS plugin active', 'kouib-blocks-for-tutor-lms' ),
			'ok'     => class_exists( 'TUTOR' ),
			'detail' => class_exists( 'TUTOR' )
				? ''
				: __( 'Activated only when Tutor LMS is present (provides courses and categories).', 'kouib-blocks-for-tutor-lms' ),
		),
		array(
			'label'  => __( '"courses" post type registered', 'kouib-blocks-for-tutor-lms' ),
			'ok'     => post_type_exists( 'courses' ),
			'detail' => post_type_exists( 'courses' )
				? ''
				: __( 'Re-enable Tutor LMS to register the content type.', 'kouib-blocks-for-tutor-lms' ),
		),
		array(
			'label'  => __( '"course-category" taxonomy available', 'kouib-blocks-for-tutor-lms' ),
			'ok'     => taxonomy_exists( 'course-category' ),
			'detail' => taxonomy_exists( 'course-category' )
				? ''
				: __( 'Category not found — check Tutor LMS status or create it under "Courses → Categories".', 'kouib-blocks-for-tutor-lms' ),
		),
		array(
			'label'  => sprintf( /* translators: %d: number of published courses */ __( 'Published courses: %d', 'kouib-blocks-for-tutor-lms' ), $published ),
			'ok'     => $published > 0,
			'detail' => $published > 0
				? ''
				: __( 'Add at least one course for the blocks to display.', 'kouib-blocks-for-tutor-lms' ),
		),
		array(
			'label'  => sprintf( /* translators: %d: number of registered blocks */ __( 'Plugin blocks registered: %d/5', 'kouib-blocks-for-tutor-lms' ), $registered_count ),
			'ok'     => 5 === $registered_count,
			'detail' => 5 === $registered_count
				? ''
				: __( 'Some blocks were not registered — deactivate and reactivate the plugin.', 'kouib-blocks-for-tutor-lms' ),
		),
	);

	echo '<div class="postbox" style="max-width:920px;">';
	echo '<h2 class="hndle" style="padding:12px;margin:0;">' . esc_html__( 'Plugin Health Check', 'kouib-blocks-for-tutor-lms' ) . '</h2>';
	echo '<div class="inside" style="padding:8px 14px 14px;">';
	echo '<table class="widefat striped" style="max-width:640px;">';
	foreach ( $checks as $check ) {
		$icon = $check['ok']
			? '<span class="dashicons dashicons-yes-alt" style="color:#46b450;"></span>'
			: '<span class="dashicons dashicons-warning" style="color:#dba617;"></span>';
		echo '<tr><td style="width:34px;">' . wp_kses_post( $icon ) . '</td><td><strong>' . esc_html( $check['label'] ) . '</strong>';
		if ( '' !== $check['detail'] ) {
			echo '<br><span style="color:#646970;font-size:12px;">' . esc_html( $check['detail'] ) . '</span>';
		}
		echo '</td></tr>';
	}
	echo '</table>';
	echo '</div></div>';
}

/**
 * Final settings page + cache flush notice + quick flush button.
 */
function kouib_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$notice = get_transient( 'kouib_admin_notice' );
	if ( is_array( $notice ) ) {
		$type = ( 'success' === $notice['type'] ) ? 'updated' : 'error';
		echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( $notice['text'] ) . '</p></div>';
		delete_transient( 'kouib_admin_notice' );
	}
	?>
	<div class="wrap">
		<h1><?php echo esc_html__( 'Kouib Blocks Settings', 'kouib-blocks-for-tutor-lms' ); ?></h1>
		<p><?php echo esc_html__( 'Defaults for all plugin blocks (category: Kouib Blocks).', 'kouib-blocks-for-tutor-lms' ); ?></p>

		<?php kouib_render_health_check(); ?>

		<form action="options.php" method="post">
			<?php settings_fields( 'kouib_settings_group' ); ?>
			<?php do_settings_sections( 'kouib_settings_page' ); ?>
			<?php submit_button(); ?>
		</form>

		<hr>
		<h2><?php echo esc_html__( 'Manual cache flush', 'kouib-blocks-for-tutor-lms' ); ?></h2>
		<p><?php echo esc_html__( 'Cache flushes automatically on any course change or when this page is saved; this button flushes it instantly.', 'kouib-blocks-for-tutor-lms' ); ?></p>
		<a class="button" href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'kouib_flush', '1', admin_url( 'options-general.php?page=kouib-settings' ) ), 'kouib_flush_cache', 'kouib_nonce' ) ); ?>">
			<?php echo esc_html__( 'Flush cache now', 'kouib-blocks-for-tutor-lms' ); ?>
		</a>
	</div>
	<?php
}

/* ==========================================================================
 * 3. Input sanitization on save
 * ========================================================================== */

function kouib_sanitize_settings( $input ) {
	$input    = is_array( $input ) ? $input : array();
	$defaults = kouib_settings_defaults();
	$out      = array();

	$ttl = isset( $input['cache_ttl'] ) ? (int) $input['cache_ttl'] : (int) $defaults['cache_ttl'];
	$out['cache_ttl'] = max( 30, min( 7 * DAY_IN_SECONDS, $ttl ) );

	$color = kouib_sanitize_style_color( isset( $input['primary_color'] ) ? $input['primary_color'] : '' );
	$out['primary_color'] = ( '' === $color ) ? '#2a7be4' : $color;

	// Images: lazy loading, size, and custom dimensions
	$out['lazy_images'] = ! empty( $input['lazy_images'] );

	$thumb_size = isset( $input['thumb_size'] ) ? (string) $input['thumb_size'] : 'medium_large';
	$out['thumb_size'] = in_array( $thumb_size, array( 'thumbnail', 'medium', 'medium_large', 'large', 'full', 'custom' ), true )
		? $thumb_size
		: 'medium_large';

	$out['thumb_custom_width']  = max( 0, min( 1920, (int) ( isset( $input['thumb_custom_width'] ) ? $input['thumb_custom_width'] : 400 ) ) );
	$out['thumb_custom_height'] = max( 0, min( 1920, (int) ( isset( $input['thumb_custom_height'] ) ? $input['thumb_custom_height'] : 300 ) ) );

	foreach ( array( 'block_filter', 'block_carousel', 'block_categories', 'block_search', 'block_stats' ) as $key ) {
		$out[ $key ] = ! empty( $input[ $key ] );
	}

	// Color/TTL are printed inline in the cached HTML — any save flushes the cache so they take effect immediately.
	if ( function_exists( 'kouib_flush_courses_cache' ) ) {
		kouib_flush_courses_cache();
	}

	return $out;
}

/* ==========================================================================
 * 4. Manual cache flush via the page button
 * ========================================================================== */

function kouib_handle_cache_flush() {
	if ( ! isset( $_GET['page'] ) || 'kouib-settings' !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return;
	}
	if ( ! isset( $_GET['kouib_nonce'] ) || ! isset( $_GET['kouib_flush'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	check_admin_referer( 'kouib_flush_cache', 'kouib_nonce' );

	kouib_flush_courses_cache();
	set_transient( 'kouib_admin_notice', array(
		'type' => 'success',
		'text' => __( 'Blocks cache flushed.', 'kouib-blocks-for-tutor-lms' ),
	), 60 );

	wp_safe_redirect( admin_url( 'options-general.php?page=kouib-settings' ) );
	exit;
}
add_action( 'admin_init', 'kouib_handle_cache_flush' );