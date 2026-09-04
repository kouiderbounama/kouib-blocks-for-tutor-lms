<?php
/**
 * Renders the course categories boxes block (kouib/courses-categories).
 *
 * Draws a responsive grid of course-category terms; each box shows:
 * a custom icon (an image uploaded from the media library in attributes['icons'],
 * keyed termId => url) above the name, the course count, and a link to the term
 * archive. It borrows the app helpers kouibc_clamp() and kouibc_sanitize_primary_color()
 * defined in carousel-render.php. The cache is separate with the kouibcat_ prefix and
 * is registered as an active key so it is invalidated automatically along with the
 * shared invalidation triggers.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * List of image formats allowed as a category icon (safe raster formats).
 *
 * Raster formats {'extension' => mime} stay as <img>; SVG is handled with strict
 * sanitization (kouib_sanitize_svg_markup) and embedded inline; any other format is
 * rejected and falls back to the built-in default icon.
 *
 * @return array
 */
function kouib_allowed_icon_extension_map() {
	return array(
		'png'  => 'png',
		'jpg'  => 'jpeg',
		'jpeg' => 'jpeg',
		'webp' => 'webp',
		'gif'  => 'gif',
	);
}

/**
 * Strict sanitization of SVG content: removes any node or attribute that could carry
 * a script.
 *
 * Loads the document via DOMDocument, then:
 *  1) Removes any element outside the allowlist or in the blacklist
 *     (script, foreignObject, iframe, embed, object, style, ...).
 *  2) Removes any attribute whose name starts with on/ or data-, or that is on the
 *     dangerous list (style, href, xlink:href, src, action, form, ...).
 *  3) Excludes values carrying dangerous protocols or external url( references.
 * On any parsing failure it returns '' so the caller uses the default icon.
 *
 * @param string $svg Raw SVG content.
 * @return string Safe SVG ready for output, or '' on failure/when DOMDocument is unavailable.
 */
function kouib_sanitize_svg_markup( $svg ) {
	if ( ! class_exists( 'DOMDocument' ) || '' === trim( (string) $svg ) ) {
		return '';
	}

	// Strip the DOCTYPE declaration and all external entities (prevents billion-laughs).
	$svg = preg_replace( '/<!DOCTYPE[^>]*>/i', '', $svg );
	if ( null === $svg || '' === trim( $svg ) ) {
		return '';
	}

	$prev_errors = libxml_use_internal_errors( true );
	$dom         = new DOMDocument();
	$loaded      = $dom->loadXML( $svg, LIBXML_NONET | LIBXML_NOERROR );
	if ( ! $loaded ) {
		libxml_clear_errors();
		libxml_use_internal_errors( $prev_errors );
		return '';
	}

	$safe_tags = array(
		'svg', 'g', 'defs', 'path', 'circle', 'rect', 'ellipse', 'line',
		'polyline', 'polygon', 'lineargradient', 'radialgradient', 'stop',
		'clippath', 'mask', 'pattern',
		'symbol', 'use', 'text', 'tspan', 'image', 'marker', 'metadata',
		'title', 'desc',
	);
	$bad_tags  = array(
		'script', 'foreignobject', 'iframe', 'embed', 'object', 'applet',
		'style', 'link', 'base', 'meta', 'frame', 'frameset', 'form', 'input',
		'button', 'a', 'audio', 'video',
	);
	$bad_attrs = array(
		'style', 'href', 'xlink:href', 'src', 'action', 'form', 'formaction',
	);

	$xpath = new DOMXPath( $dom );

	// Take a static snapshot (a live DOMNodeList during deletion may cause unexpected behavior).
	$nodes = iterator_to_array( $xpath->query( '//*' ) );
	foreach ( $nodes as $node ) {
		$tag = strtolower( $node->nodeName );
		if ( in_array( $tag, $bad_tags, true ) || ! in_array( $tag, $safe_tags, true ) ) {
			if ( $node->parentNode ) {
				$node->parentNode->removeChild( $node );
			}
		}
	}

	$nodes = iterator_to_array( $xpath->query( '//*' ) );
	foreach ( $nodes as $node ) {
		/** @var DOMElement $node */
		/** @var DOMAttr $attr */
		foreach ( iterator_to_array( $node->attributes ) as $attr ) {
			$name  = strtolower( $attr->nodeName );
			$value = trim( $attr->nodeValue );

			// Attributes that are dangerously named, event handlers (on*), or custom data.
			if ( in_array( $name, $bad_attrs, true )
				|| strpos( $name, 'on' ) === 0
				|| strpos( $name, 'data-' ) === 0 ) {
				$node->removeAttribute( $attr->nodeName );
				continue;
			}

			// Values with dangerous protocols or external url( references.
			if ( preg_match( '/(javascript|vbscript|expression|@import)\s*[:(]/i', $value )
				|| preg_match( '/^\s*data\s*:/i', $value )
				|| ( false !== strpos( $value, 'url(' ) && ! preg_match( '/url\(\s*[\'"]?#/', $value ) ) ) {
				$node->removeAttribute( $attr->nodeName );
				continue;
			}
		}
	}

	libxml_clear_errors();
	libxml_use_internal_errors( $prev_errors );

	$clean = $dom->saveXML( $dom->documentElement );
	return ( false === $clean || '' === trim( $clean ) ) ? '' : $clean;
}

/**
 * Reads an SVG locally from the filesystem and returns it sanitized.
 *
 * @param string $url SVG icon URL.
 * @return string Safe inline SVG, or '' if it is not a readable and allowed local file.
 */
function kouib_get_sanitized_svg_markup( $url ) {
	if ( '' === $url ) {
		return '';
	}

	$parts = wp_parse_url( $url );
	$home  = wp_parse_url( home_url( '/' ) );
	if ( empty( $parts['path'] ) ) {
		return '';
	}
	if ( ! empty( $parts['scheme'] ) && ! in_array( $parts['scheme'], array( 'http', 'https' ), true ) ) {
		return '';
	}
	// Accept only files from this site itself (no external SVG — it would be unreadable anyway).
	if ( ! empty( $parts['host'] ) ) {
		$site_host = isset( $home['host'] ) ? strtolower( $home['host'] ) : '';
		if ( strtolower( $parts['host'] ) !== $site_host ) {
			return '';
		}
	}

	$uploads = wp_upload_dir();
	$rel     = ltrim( $parts['path'], '/' );
	$base    = isset( $uploads['basedir'] ) ? $uploads['basedir'] : '';
	if ( '' === $base ) {
		return '';
	}

	// Constrain the path strictly to within the uploads directory (prevents traversal).
	$base_real = realpath( $base );
	$path      = realpath( $base . '/' . $rel );
	if ( false === $base_real || false === $path
		|| 0 !== strpos( $path, $base_real . DIRECTORY_SEPARATOR ) ) {
		return '';
	}
	if ( ! is_file( $path ) ) {
		return '';
	}
	// Size limit (an icon SVG should not need more than 300KB).
	if ( filesize( $path ) > 300 * 1024 ) {
		return '';
	}

	$content = file_get_contents( $path );
	if ( false === $content || '' === trim( $content ) ) {
		return '';
	}
	return kouib_sanitize_svg_markup( $content );
}

/**
 * Builds the final category icon HTML safely.
 *
 * - Raster format in the allowlist => <img> with url and alt sanitized.
 * - Local SVG => strict sanitization and inline embedding.
 * - Anything else, or failure => the built-in default icon.
 *
 * @param string $url   Icon URL (empty = default).
 * @param string $label Alternate text for the raster image.
 * @return string Safe icon HTML.
 */
function kouib_build_category_icon_html( $icon_url, $label ) {
	$default = '<svg viewBox="0 0 24 24" width="40" height="40" aria-hidden="true" focusable="false"><path fill="currentColor" d="M21 19V5c0-1.1-.9-2-2-2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2zM8.5 13.5l2.5 3.01L14.5 12l4.5 6H5l3.5-4.5z"/></svg>';

	if ( '' === $icon_url ) {
		return $default;
	}

	$ext = strtolower( pathinfo( (string) wp_parse_url( $icon_url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );

	$raster = kouib_allowed_icon_extension_map();
	if ( isset( $raster[ $ext ] ) ) {
		return '<img src="' . esc_url( $icon_url ) . '" alt="' . esc_attr( $label ) . '" loading="lazy" decoding="async" />';
	}

	if ( 'svg' === $ext ) {
		$inline = kouib_get_sanitized_svg_markup( $icon_url );
		if ( '' !== $inline ) {
			return $inline;
		}
	}

	return $default;
}

/**
 * The main renderer for the course categories grid.
 */
function kouib_render_courses_categories( $attributes ) {

	// Block disabled from the Kouib Blocks settings: not rendered on the front end (editor notice only).
	if ( ! kouib_block_enabled( 'categories' ) ) {
		return kouib_block_disabled_output();
	}

	$is_editor_request = defined( 'REST_REQUEST' ) && REST_REQUEST;

	// Cache: early check before any query or rendering.
	$cache_key = null;
	if ( ! $is_editor_request ) {
		$cache_attributes = $attributes;
		ksort( $cache_attributes );
		$cache_key = 'kouibcat_' . KOUIB_VERSION . '_' . md5( wp_json_encode( $cache_attributes ) );
		kouib_register_active_key( $cache_key );

		$cached = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}
	}

	kouib_enqueue_frontend_assets( 'categories' );

	$columns        = isset( $attributes['columns'] ) ? kouibc_clamp( $attributes['columns'], 1, 6 ) : 3;
	$columns_tablet = isset( $attributes['columnsTablet'] ) ? kouibc_clamp( $attributes['columnsTablet'], 1, 6 ) : 2;
	$columns_mobile = isset( $attributes['columnsMobile'] ) ? kouibc_clamp( $attributes['columnsMobile'], 1, 6 ) : 1;
	$show_count     = ! empty( $attributes['showCount'] );
	$hide_empty     = ! empty( $attributes['hideEmpty'] );
	$open_in_new    = ! empty( $attributes['openInNewTab'] );
	// Color: the designer's color if set, otherwise the global color from the settings panel.
	$primary_color  = kouib_resolve_primary_color( $attributes );

	$terms = kouib_get_terms_list( $hide_empty );
	if ( empty( $terms ) ) {
		return '<p style="text-align:center;padding:40px;color:#666;">' . esc_html__( 'No course categories are available yet.', 'kouib-blocks-for-tutor-lms' ) . '</p>';
	}

	// Map of uploaded icons: termId => url.
	$icon_map = array();
	if ( ! empty( $attributes['icons'] ) && is_array( $attributes['icons'] ) ) {
		foreach ( $attributes['icons'] as $ic ) {
			if ( ! is_array( $ic ) ) {
				continue;
			}
			$term_id = isset( $ic['termId'] ) ? (int) $ic['termId'] : 0;
			$url     = isset( $ic['url'] ) ? $ic['url'] : '';
			if ( $term_id && '' !== $url ) {
				$icon_map[ $term_id ] = sanitize_url( $url );
			}
		}
	}

	$our_style  = '--kouib-primary:' . esc_attr( $primary_color ) . ';';
	$our_style .= '--kouib-cat-cols:' . absint( $columns ) . ';';
	$our_style .= '--kouib-cat-cols-tablet:' . absint( $columns_tablet ) . ';';
	$our_style .= '--kouib-cat-cols-mobile:' . absint( $columns_mobile ) . ';';

	$direction = is_rtl() ? 'rtl' : 'ltr';
	$our_style .= 'direction:' . $direction . ';';

	if ( function_exists( 'get_block_wrapper_attributes' ) ) {
		$wrapper_attrs = get_block_wrapper_attributes( array(
			'class' => 'kouib-categories-wrapper',
			'style' => $our_style,
		) );
	} else {
		$wrapper_attrs = sprintf(
			'class="kouib-categories-wrapper" style="%s"',
			esc_attr( $our_style )
		);
	}

	ob_start();
	?>
	<div <?php echo $wrapper_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped internally ?>>
		<div class="kouib-categories-grid">
			<?php foreach ( $terms as $term ) : ?>
				<?php
				$term_link = get_term_link( $term, 'course-category' );
				if ( is_wp_error( $term_link ) ) {
					$term_link = '';
				}
				$icon_url = isset( $icon_map[ $term->term_id ] ) ? $icon_map[ $term->term_id ] : '';
				$count    = (int) $term->count;
				?>
				<a
					class="kouib-cat-box"
					href="<?php echo esc_url( $term_link ); ?>"
					<?php echo $open_in_new ? 'target="_blank" rel="noopener"' : ''; ?>
				>
<span class="kouib-cat-icon">
					<?php
					echo kouib_build_category_icon_html( $icon_url, $term->name ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built internally and sanitized (raster img + SVG sanitized via DOM).
					?>
				</span>
					<span class="kouib-cat-name"><?php echo esc_html( $term->name ); ?></span>
					<?php if ( $show_count ) : ?>
						<span class="kouib-cat-count">
							<?php
							/* translators: %s: number of courses in the category */
							echo esc_html( sprintf( _n( '%s course', '%s courses', $count, 'kouib-blocks-for-tutor-lms' ), number_format_i18n( $count ) ) );
							?>
						</span>
					<?php endif; ?>
				</a>
			<?php endforeach; ?>
		</div>
	</div>
	<?php
	$html = ob_get_clean();

	if ( null !== $cache_key ) {
		set_transient( $cache_key, $html, kouib_get_cache_ttl() );
	}

	return $html;
}