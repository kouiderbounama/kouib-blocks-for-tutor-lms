<?php
/**
 * Plugin Name: Kouib Blocks for Tutor LMS
 * Description: Professional Gutenberg blocks for Tutor LMS courses: advanced filter, carousel, categories, instant search and platform stats - with a central settings panel compatible with Kadence and theme.json.
 * Version:     1.0.0
 * Requires at least: 6.3
 * Requires PHP: 7.4
 * Author:      kouider bounama
 * Author URI:  https://github.com/kouiderbounama
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires Plugins: tutor
 * Text Domain: kouib-blocks-for-tutor-lms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'KOUIB_META_STUDENTS', '_kouib_students_count' );
define( 'KOUIB_VERSION', '1.0.0' );
define( 'KOUIB_CACHE_KEYS_OPTION', 'kouib_active_cache_keys' );
define( 'KOUIB_URL', trailingslashit( plugin_dir_url( __FILE__ ) ) );

require_once __DIR__ . '/includes/settings.php';
require_once __DIR__ . '/includes/cache.php';
require_once __DIR__ . '/includes/meta.php';
require_once __DIR__ . '/includes/assets.php';
require_once __DIR__ . '/includes/render.php';
require_once __DIR__ . '/includes/carousel-render.php';
require_once __DIR__ . '/includes/categories-render.php';
require_once __DIR__ . '/includes/search-render.php';
require_once __DIR__ . '/includes/stats-render.php';
require_once __DIR__ . '/includes/rest.php';

/* ==========================================================================
 * 1. Translations
 *
 * WP 4.6+ auto-loads translations from translate.wordpress.org for directory
 * plugins, so no translation loader call is needed here.
 * ========================================================================== */

/* ==========================================================================
 * 2. Register blocks from block.json (the modern Gutenberg standard)
 * ========================================================================== */
function kouib_register_block() {
	// Block 1: Courses filter
	register_block_type_from_metadata(
		__DIR__ . '/build/courses-filter',
		array(
			'render_callback' => 'kouib_render_courses_filter',
		)
	);

	// Block 2: Courses carousel
	register_block_type_from_metadata(
		__DIR__ . '/build/courses-carousel',
		array(
			'render_callback' => 'kouib_render_courses_carousel',
		)
	);

	// Block 3: Course category boxes
	register_block_type_from_metadata(
		__DIR__ . '/build/courses-categories',
		array(
			'render_callback' => 'kouib_render_courses_categories',
		)
	);

	// Block 4: Quick course search
	register_block_type_from_metadata(
		__DIR__ . '/build/courses-search',
		array(
			'render_callback' => 'kouib_render_courses_search',
		)
	);

	// Block 5: Platform stats
	register_block_type_from_metadata(
		__DIR__ . '/build/courses-stats',
		array(
			'render_callback' => 'kouib_render_courses_stats',
		)
	);

	// Wire editor-script translation files (JSON/JED) — the handle is auto-generated
	// as {block-slug}-editor-script when registering from metadata.
	$editor_handle = 'kouib-courses-filter-editor-script';
	if ( wp_script_is( $editor_handle, 'registered' ) ) {
		wp_set_script_translations( $editor_handle, 'kouib-blocks-for-tutor-lms', plugin_dir_path( __FILE__ ) . 'languages' );
	}

	$carousel_editor_handle = 'kouib-courses-carousel-editor-script';
	if ( wp_script_is( $carousel_editor_handle, 'registered' ) ) {
		wp_set_script_translations( $carousel_editor_handle, 'kouib-blocks-for-tutor-lms', plugin_dir_path( __FILE__ ) . 'languages' );
	}

	$categories_editor_handle = 'kouib-courses-categories-editor-script';
	if ( wp_script_is( $categories_editor_handle, 'registered' ) ) {
		wp_set_script_translations( $categories_editor_handle, 'kouib-blocks-for-tutor-lms', plugin_dir_path( __FILE__ ) . 'languages' );
	}

	$search_editor_handle = 'kouib-courses-search-editor-script';
	if ( wp_script_is( $search_editor_handle, 'registered' ) ) {
		wp_set_script_translations( $search_editor_handle, 'kouib-blocks-for-tutor-lms', plugin_dir_path( __FILE__ ) . 'languages' );
	}

	$stats_editor_handle = 'kouib-courses-stats-editor-script';
	if ( wp_script_is( $stats_editor_handle, 'registered' ) ) {
		wp_set_script_translations( $stats_editor_handle, 'kouib-blocks-for-tutor-lms', plugin_dir_path( __FILE__ ) . 'languages' );
	}
}
add_action( 'init', 'kouib_register_block' );

/* ==========================================================================
 * 2b. Custom block category to group the plugin blocks in the Gutenberg editor
 * ========================================================================== */

/**
 * Adds a "Kouib Blocks" category at the top of the block category list in the
 * editor — every plugin block (block.json) uses category: kouib-blocks so they all
 * group under it and are easier to find. The callback alternative to
 * block_categories_all (5.8+).
 */
function kouib_register_block_category( $categories, $editor_context ) {
	// Not always the content editor? We add in all cases — the list is deduplicated by slug
	if ( ! is_array( $categories ) ) {
		$categories = array();
	}

	foreach ( $categories as $cat ) {
		if ( isset( $cat['slug'] ) && 'kouib-blocks' === $cat['slug'] ) {
			return $categories;
		}
	}

	array_unshift( $categories, array(
		'slug'  => 'kouib-blocks',
		'title' => __( 'Kouib Blocks', 'kouib-blocks-for-tutor-lms' ),
		'icon'  => 'layout',
	) );

	return $categories;
}
add_filter( 'block_categories_all', 'kouib_register_block_category', 10, 2 );

/* ==========================================================================
 * 2c-bis. Hide disabled blocks from the editor (block inserter)
 *
 * When a block is hidden from Settings → Kouib Blocks it is removed from
 * allowed_block_types_all so it no longer appears in the inserter and cannot be
 * added, while its existing copies in pages still render in the editor (notice)
 * and disappear from the frontend via the render guard.
 * ========================================================================== */

/**
 * Prevents the plugin's hidden blocks from appearing in the Gutenberg editor.
 */
function kouib_hide_disabled_blocks( $allowed_block_types, $editor_context ) {
	static $allowed = null;
	if ( null !== $allowed ) {
		return $allowed;
	}

	$block_names = array(
		'filter'     => 'kouib/courses-filter',
		'carousel'   => 'kouib/courses-carousel',
		'categories' => 'kouib/courses-categories',
		'search'     => 'kouib/courses-search',
		'stats'      => 'kouib/courses-stats',
	);

	$hidden = array();
	foreach ( $block_names as $slug => $name ) {
		if ( ! kouib_block_enabled( $slug ) ) {
			$hidden[] = $name;
		}
	}

	// Nothing hidden → keep the original list (null = all blocks or theme/pattern constraints).
	if ( empty( $hidden ) ) {
		$allowed = $allowed_block_types;
		return $allowed;
	}

	// Full list of registered blocks, then exclude the hidden ones
	$all_names = array_keys( WP_Block_Type_Registry::get_instance()->get_all_registered() );
	$allowed   = array();
	foreach ( $all_names as $name ) {
		if ( ! in_array( $name, $hidden, true ) ) {
			$allowed[] = $name;
		}
	}

	return $allowed;
}
add_filter( 'allowed_block_types_all', 'kouib_hide_disabled_blocks', 10, 2 );

/* ==========================================================================
 * 2c. "View details" link in the plugins list screen
 *
 * WordPress only shows the View Details button for plugins installed from the
 * WordPress.org repository (where an API slug exists), so a locally installed
 * plugin does not get the button even though it ships readme.txt. We add it
 * here via plugin_row_meta to open readme.html in a thickbox window with the
 * same display quality as the repository.
 * ========================================================================== */

/**
 * Loads thickbox on the plugins screen only (it hosts the readme.html frame).
 */
function kouib_admin_enqueue_readme_assets( $hook_suffix ) {
	if ( 'plugins.php' === $hook_suffix ) {
		wp_enqueue_style( 'thickbox' );
		wp_enqueue_script( 'thickbox' );
	}
}
add_action( 'admin_enqueue_scripts', 'kouib_admin_enqueue_readme_assets' );

/**
 * Adds a "View details" link to the plugin row in /wp-admin/plugins.php.
 */
function kouib_add_plugin_readme_link( $plugin_meta, $plugin_file, $plugin_data, $status ) {
	if ( plugin_basename( __FILE__ ) !== $plugin_file ) {
		return $plugin_meta;
	}
	if ( ! current_user_can( 'install_plugins' ) ) {
		return $plugin_meta;
	}

	$settings_url = admin_url( 'options-general.php?page=kouib-settings' );
	$plugin_meta[] = '<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Settings', 'kouib-blocks-for-tutor-lms' ) . '</a>';

	$readme_url = add_query_arg( 'TB_iframe', 'true', plugins_url( 'readme.html', __FILE__ ) );

	$plugin_meta[] = sprintf(
		'<a href="%s" class="thickbox open-plugin-details-modal" aria-label="%s" data-title="%s">%s</a>',
		esc_url( $readme_url ),
		/* translators: %s: plugin name. */
		esc_attr( sprintf( __( 'More information about %s', 'kouib-blocks-for-tutor-lms' ), $plugin_data['Name'] ) ),
		esc_attr( $plugin_data['Name'] ),
		__( 'View details', 'kouib-blocks-for-tutor-lms' )
	);

	return $plugin_meta;
}
add_filter( 'plugin_row_meta', 'kouib_add_plugin_readme_link', 10, 4 );

/* ==========================================================================
 * 3. Activation / Uninstall
 * ========================================================================== */
register_activation_hook( __FILE__, 'kouib_activate_plugin_sync' );
// Uninstall cleanup lives in uninstall.php (standalone, direct $wpdb), so no
// register_uninstall_hook() is needed here.

/* ==========================================================================
 * 4. Flush the cache automatically when the plugin is upgraded
 *
 * When the plugin version changes we flush every stored cache copy, so pages
 * are never served stale HTML (such as the pre-fix enroll button) even though
 * the code was updated.
 * ========================================================================== */
function kouib_maybe_upgrade() {
	$stored = get_option( 'kouib_version', '' );
	if ( $stored !== KOUIB_VERSION ) {
		kouib_flush_courses_cache();

		// Remove the legacy "search field colors" keys that were dropped
		// (colors now live only inside the block controls in the editor) — runs once
		// on upgrade without waiting for the settings to be re-saved.
		$settings = get_option( KOUIB_SETTINGS_OPTION, array() );
		if ( is_array( $settings ) ) {
			$changed = false;
			foreach ( array( 'search_field_bg', 'search_field_border', 'search_field_text', 'search_icon_color' ) as $legacy ) {
				if ( array_key_exists( $legacy, $settings ) ) {
					unset( $settings[ $legacy ] );
					$changed = true;
				}
			}
			if ( $changed ) {
				update_option( KOUIB_SETTINGS_OPTION, $settings, false );
			}
		}

		update_option( 'kouib_version', KOUIB_VERSION, false );
	}
}
add_action( 'init', 'kouib_maybe_upgrade' );
