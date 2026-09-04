<?php
/**
 * REST endpoint to load category grids on demand (instead of embedding them all in the HTML).
 *
 * GET /wp-json/kouib/v1/courses?term=all|term-ID&orderby=...&perPage=...
 * Returns the HTML of a single category grid (cached per category).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'rest_api_init', 'kouib_register_rest_route' );
function kouib_register_rest_route() {
	// Both routes are intentionally public: any anonymous visitor loads the
	// block output on the frontend and inside ServerSideRender previews. They
	// are read-only, expose only published course data, and are guarded by a
	// per-IP rate limit inside each callback — no authentication is required.
	register_rest_route(
		'kouib/v1',
		'/courses',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'kouib_rest_get_courses',
			'permission_callback' => '__return_true',
			'args'                => array(
				'term'        => array(
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
					'validate_callback' => function( $param ) {
						return (bool) preg_match( '/^(all|\d+|term-\d+)$/', (string) $param );
					},
				),
				'orderby'     => array(
					'type'              => 'string',
					'default'           => 'date',
					'sanitize_callback' => 'sanitize_key',
					'validate_callback' => function( $param ) {
						return in_array( $param, array( 'date', 'date_asc', 'title', 'rand', 'students' ), true );
					},
				),
				'perPage'     => array(
					'type'              => 'integer',
					'default'           => 3,
					'validate_callback' => function( $param ) {
						return is_numeric( $param ) && (int) $param >= 1 && (int) $param <= 24;
					},
				),
				'page'        => array(
					'type'              => 'integer',
					'default'           => 1,
					'validate_callback' => function( $param ) {
						return is_numeric( $param ) && (int) $param >= 1 && (int) $param <= 1000;
					},
				),
				'showLevel'   => array( 'type' => 'boolean', 'default' => true ),
				'showRating'  => array( 'type' => 'boolean', 'default' => true ),
				'showLessons' => array( 'type' => 'boolean', 'default' => true ),
				'showDuration'=> array( 'type' => 'boolean', 'default' => true ),
				'showPrice'   => array( 'type' => 'boolean', 'default' => true ),
				'showStudents'=> array( 'type' => 'boolean', 'default' => true ),
				'showEnrollBtn'=> array( 'type' => 'boolean', 'default' => true ),
				'enrollBtnText'=> array(
					'type'              => 'string',
					'default'           => '',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'openInNewTab'=> array( 'type' => 'boolean', 'default' => false ),
			),
		)
	);

	// Quick search endpoint (kouib/courses-search block)
	register_rest_route(
		'kouib/v1',
		'/search',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'kouib_rest_search_courses',
			'permission_callback' => '__return_true',
			'args'                => array(
				'q'           => array(
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
				),
				'perPage'     => array(
					'type'              => 'integer',
					'default'           => 6,
					'validate_callback' => function( $param ) {
						return is_numeric( $param ) && (int) $param >= 1 && (int) $param <= 12;
					},
				),
				'showThumb'   => array( 'type' => 'boolean', 'default' => true ),
				'showPrice'   => array( 'type' => 'boolean', 'default' => true ),
				'showRating'  => array( 'type' => 'boolean', 'default' => true ),
				'showStudents'=> array( 'type' => 'boolean', 'default' => true ),
				'openInNewTab'=> array( 'type' => 'boolean', 'default' => false ),
				'sentence'   => array( 'type' => 'boolean', 'default' => false ),
			),
		)
	);
}

/**
 * Returns the visitor IP from REMOTE_ADDR only (not relying on proxy headers
 * to avoid spoofing). Used for per-IP rate limiting.
 */
function kouib_get_client_ip() {
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	return $ip ? $ip : 'unknown';
}

function kouib_rest_get_courses( $request ) {
	// Simple per-IP rate limit (short transient) — public endpoint with no auth,
	// protected from repeated hammering of the aggregated query.
	$rl_key   = 'kouib_rl_' . md5( kouib_get_client_ip() . KOUIB_VERSION );
	$rl_count = (int) get_transient( $rl_key ) + 1;
	set_transient( $rl_key, $rl_count, MINUTE_IN_SECONDS );
	if ( $rl_count > 60 ) {
		return new WP_Error( 'kouib_rate_limited', __( 'Too many requests, try again later.', 'kouib-blocks-for-tutor-lms' ), array( 'status' => 429 ) );
	}

	$term_param = $request->get_param( 'term' );

	// Category buttons carry data-term="term-123"; strip the prefix before conversion.
	if ( is_string( $term_param ) && 0 === strpos( $term_param, 'term-' ) ) {
		$term_param = substr( $term_param, 5 );
	}

	$term = null;
	if ( 'all' !== $term_param ) {
		$term_id = (int) $term_param;
		if ( ! $term_id ) {
			return new WP_Error( 'kouib_invalid_term', __( 'Invalid category', 'kouib-blocks-for-tutor-lms' ), array( 'status' => 404 ) );
		}
		$term = get_term( $term_id, 'course-category' );
		if ( is_wp_error( $term ) || ! $term ) {
			return new WP_Error( 'kouib_invalid_term', __( 'Invalid category', 'kouib-blocks-for-tutor-lms' ), array( 'status' => 404 ) );
		}
	}

	$attributes = array(
		'orderby'     => $request->get_param( 'orderby' ),
		'perPage'     => min( 24, max( 1, (int) $request->get_param( 'perPage' ) ) ),
		'showLevel'   => (bool) $request->get_param( 'showLevel' ),
		'showRating'  => (bool) $request->get_param( 'showRating' ),
		'showLessons' => (bool) $request->get_param( 'showLessons' ),
		'showDuration'=> (bool) $request->get_param( 'showDuration' ),
		'showPrice'   => (bool) $request->get_param( 'showPrice' ),
		'showStudents'=> (bool) $request->get_param( 'showStudents' ),
		'showEnrollBtn'=> (bool) $request->get_param( 'showEnrollBtn' ),
		'enrollBtnText' => (string) $request->get_param( 'enrollBtnText' ),
		'openInNewTab' => (bool) $request->get_param( 'openInNewTab' ),
	);

	$page = min( 1000, max( 1, (int) $request->get_param( 'page' ) ) );

	$grid = kouib_render_category_grid_html( $term, $attributes, $page );

	return rest_ensure_response( array( 'html' => $grid['html'], 'hasMore' => $grid['has_more'] ) );
}

/**
 * REST: quick course search.
 * GET /wp-json/kouib/v1/search?q=...&perPage=...
 *
 * Returns ready HTML for the result items (same card style as the plugin), with a separate
 * rate limit (60/min/IP) as a public unauthenticated route.
 */
function kouib_rest_search_courses( $request ) {
	// Rate limit independent of the /courses route (60 requests/minute per IP)
	$rl_key   = 'kouib_rls_' . md5( kouib_get_client_ip() . KOUIB_VERSION );
	$rl_count = (int) get_transient( $rl_key ) + 1;
	set_transient( $rl_key, $rl_count, MINUTE_IN_SECONDS );
	if ( $rl_count > 60 ) {
		return new WP_Error( 'kouib_rate_limited', __( 'Too many requests, try again later.', 'kouib-blocks-for-tutor-lms' ), array( 'status' => 429 ) );
	}

	$per_page = max( 1, min( 12, (int) $request->get_param( 'perPage' ) ) );
	$q        = kouib_sanitize_search_query( $request->get_param( 'q' ) );

	if ( '' === $q ) {
		return new WP_Error( 'kouib_empty_query', __( 'Search text is empty', 'kouib-blocks-for-tutor-lms' ), array( 'status' => 400 ) );
	}

	$flags = array(
		'show_thumb'    => (bool) $request->get_param( 'showThumb' ),
		'show_price'    => (bool) $request->get_param( 'showPrice' ),
		'show_rating'   => (bool) $request->get_param( 'showRating' ),
		'show_students' => (bool) $request->get_param( 'showStudents' ),
		'open_new'      => (bool) $request->get_param( 'openInNewTab' ),
		'sentence'      => (bool) $request->get_param( 'sentence' ),
	);

	$html = kouib_search_courses_html( $q, $per_page, $flags );

	return rest_ensure_response( array(
		'html' => $html,
		'q'    => $q,
	) );
}
