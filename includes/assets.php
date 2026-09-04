<?php
/**
 * Block asset registration (registered on init).
 *
 * - CSS (kouib-style): registered as a handle and referenced from block.json via
 *   the `style` property, so it loads automatically on the frontend only when
 *   the block exists, and in the editor too.
 * - Frontend script (kouib-view): registered as a handle and referenced from
 *   block.json via the `viewScript` property, so it loads automatically on the
 *   frontend only when the block exists. In the editor we load it through
 *   enqueue_block_editor_assets so the filter buttons work in the
 *   ServerSideRender preview.
 *
 * Important timing note: WordPress starts loading block assets (style/viewScript)
 * from block.json inside WP_Block::render() before render_callback runs, so
 * late registration (inside the callback) leaves the handles unavailable and
 * they never load. Solution: register the assets early, on the init hook.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function kouib_register_assets() {
	if ( ! wp_style_is( 'kouib-style', 'registered' ) ) {
		wp_register_style( 'kouib-style', KOUIB_URL . 'assets/css/kouib.css', array(), KOUIB_VERSION );
	}

	if ( ! wp_script_is( 'kouib-view', 'registered' ) ) {
		wp_register_script( 'kouib-view', KOUIB_URL . 'assets/js/view.js', array(), KOUIB_VERSION, true );
	}

	// Carousel block assets
	if ( ! wp_style_is( 'kouib-carousel-style', 'registered' ) ) {
		wp_register_style( 'kouib-carousel-style', KOUIB_URL . 'assets/css/kouib-carousel.css', array(), KOUIB_VERSION );
	}

	if ( ! wp_script_is( 'kouib-carousel-view', 'registered' ) ) {
		wp_register_script( 'kouib-carousel-view', KOUIB_URL . 'assets/js/carousel.js', array(), KOUIB_VERSION, true );
	}

	// Categories grid block assets
	if ( ! wp_style_is( 'kouib-categories-style', 'registered' ) ) {
		wp_register_style( 'kouib-categories-style', KOUIB_URL . 'assets/css/kouib-categories.css', array(), KOUIB_VERSION );
	}

	// Quick search block assets
	if ( ! wp_style_is( 'kouib-search-style', 'registered' ) ) {
		wp_register_style( 'kouib-search-style', KOUIB_URL . 'assets/css/kouib-search.css', array(), KOUIB_VERSION );
	}

	if ( ! wp_script_is( 'kouib-search-view', 'registered' ) ) {
		wp_register_script( 'kouib-search-view', KOUIB_URL . 'assets/js/search.js', array(), KOUIB_VERSION, true );
	}

	// Platform stats block assets (CSS only — no JavaScript in the first release)
	if ( ! wp_style_is( 'kouib-stats-style', 'registered' ) ) {
		wp_register_style( 'kouib-stats-style', KOUIB_URL . 'assets/css/kouib-stats.css', array(), KOUIB_VERSION );
	}
}
add_action( 'init', 'kouib_register_assets' );

/**
 * Wire the block editor scripts to the plugin's text domain. Translations are
 * served automatically by translate.wordpress.org (WP 4.6+); WordPress looks
 * up {domain}-{locale}-{handle}.json for each editor handle. No translation
 * files are shipped with the plugin.
 */
function kouib_set_script_translations() {
	$languages = trailingslashit( dirname( __DIR__ ) ) . 'languages';
	$handles = array(
		'kouib-courses-filter-editor-script',
		'kouib-courses-carousel-editor-script',
		'kouib-courses-categories-editor-script',
		'kouib-courses-search-editor-script',
		'kouib-courses-stats-editor-script',
	);

	foreach ( $handles as $handle ) {
		wp_set_script_translations( $handle, 'kouib-blocks-for-tutor-lms', $languages );
	}
}
add_action( 'init', 'kouib_set_script_translations', 20 );

/**
 * Ensures frontend assets (style + viewScript) load even on paths where the
 * automatic loading from block.json does not kick in. Called from render_callback.
 *
 * Each block loads only its own assets: 'filter' loads the filter CSS + the
 * filtering script, and 'carousel' loads the carousel CSS + its script instead
 * of loading everything on every page. (The base kouib-style CSS is required for
 * both because it carries the course card styles.)
 */
function kouib_enqueue_frontend_assets( $which = 'all' ) {
	if ( 'filter' === $which ) {
		wp_enqueue_style( 'kouib-style' );
		wp_enqueue_script( 'kouib-view' );
		return;
	}

	if ( 'carousel' === $which ) {
		wp_enqueue_style( 'kouib-style' );
		wp_enqueue_style( 'kouib-carousel-style' );
		wp_enqueue_script( 'kouib-carousel-view' );
		return;
	}

	if ( 'search' === $which ) {
		wp_enqueue_style( 'kouib-search-style' );
		wp_enqueue_script( 'kouib-search-view' );
		return;
	}

	if ( 'categories' === $which ) {
		wp_enqueue_style( 'kouib-categories-style' );
		return;
	}

	if ( 'stats' === $which ) {
		wp_enqueue_style( 'kouib-stats-style' );
		return;
	}

	wp_enqueue_style( 'kouib-style' );
	wp_enqueue_style( 'kouib-carousel-style' );
	wp_enqueue_script( 'kouib-view' );
	wp_enqueue_script( 'kouib-carousel-view' );
}

/**
 * Loads the filtering script in the editor (with a ServerSideRender preview) so
 * the category buttons respond inside the editor's iframe. The code uses a
 * MutationObserver to reinitialize when the preview re-renders.
 */
function kouib_enqueue_editor_assets() {
	kouib_register_assets();
	wp_enqueue_script( 'kouib-view' );
	wp_enqueue_script( 'kouib-carousel-view' );
	wp_enqueue_style( 'kouib-carousel-style' );
	wp_enqueue_style( 'kouib-categories-style' );
	wp_enqueue_style( 'kouib-search-style' );
	wp_enqueue_style( 'kouib-stats-style' );
}
add_action( 'enqueue_block_editor_assets', 'kouib_enqueue_editor_assets' );
