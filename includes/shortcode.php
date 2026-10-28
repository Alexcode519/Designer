<?php
/**
 * The [snippets_album_designer] shortcode: the client-facing editor.
 */

defined( 'ABSPATH' ) || exit;

add_shortcode( 'snippets_album_designer', 'sad_render_shortcode' );
add_filter( 'wp_robots', 'sad_noindex_designer_page' );
add_filter( 'get_pages', 'sad_hide_designer_page_from_lists' );
add_filter( 'wp_list_pages_excludes', 'sad_exclude_designer_page_id' );
add_filter( 'wp_sitemaps_posts_query_args', 'sad_exclude_designer_page_from_sitemap', 10, 2 );
add_action( 'pre_get_posts', 'sad_exclude_designer_page_from_search' );

/*
 * The designer page is only reached through a client's private link, so keep it out of
 * automatic page menus, site search and the sitemap.
 */

function sad_designer_page_id() {
	return (int) get_option( 'sad_designer_page_id' );
}

function sad_hide_designer_page_from_lists( $pages ) {
	$page_id = sad_designer_page_id();
	if ( ! $page_id || is_admin() ) {
		return $pages;
	}
	return array_values(
		array_filter(
			$pages,
			function ( $page ) use ( $page_id ) {
				return (int) $page->ID !== $page_id;
			}
		)
	);
}

function sad_exclude_designer_page_id( $exclude ) {
	if ( sad_designer_page_id() ) {
		$exclude[] = sad_designer_page_id();
	}
	return $exclude;
}

function sad_exclude_designer_page_from_sitemap( $args, $post_type ) {
	if ( 'page' === $post_type && sad_designer_page_id() ) {
		$args['post__not_in']   = isset( $args['post__not_in'] ) ? (array) $args['post__not_in'] : array();
		$args['post__not_in'][] = sad_designer_page_id();
	}
	return $args;
}

function sad_exclude_designer_page_from_search( $query ) {
	if ( is_admin() || ! $query->is_main_query() || ! $query->is_search() || ! sad_designer_page_id() ) {
		return;
	}
	$excluded   = (array) $query->get( 'post__not_in' );
	$excluded[] = sad_designer_page_id();
	$query->set( 'post__not_in', $excluded );
}

function sad_noindex_designer_page( $robots ) {
	$page_id = (int) get_option( 'sad_designer_page_id' );
	if ( ( $page_id && is_page( $page_id ) ) || isset( $_GET['album'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		$robots['noindex']  = true;
		$robots['nofollow'] = true;
	}
	return $robots;
}

function sad_render_shortcode() {
	$token = isset( $_GET['album'] ) ? sanitize_key( wp_unslash( $_GET['album'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	if ( ! $token ) {
		return sad_render_start_page();
	}

	wp_enqueue_style( 'sad-editor', SAD_URL . 'assets/editor.css', array(), SAD_VERSION );
	wp_enqueue_script( 'sad-editor', SAD_URL . 'assets/editor.js', array(), SAD_VERSION, true );

	$layouts = array();
	foreach ( sad_layouts() as $id => $layout ) {
		$layouts[] = array( 'id' => $id, 'name' => $layout['name'], 'slots' => $layout['slots'] );
	}

	$templates = array();
	foreach ( sad_templates() as $id => $template ) {
		$templates[] = array( 'id' => $id, 'name' => $template['name'], 'description' => $template['description'], 'layouts' => $template['layouts'] );
	}

	// Leave headroom under the server's upload limit for the other form fields.
	$chunk = (int) apply_filters( 'sad_chunk_size', min( 2 * MB_IN_BYTES, max( 256 * KB_IN_BYTES, wp_max_upload_size() - 64 * KB_IN_BYTES ) ) );

	wp_add_inline_script(
		'sad-editor',
		'window.SAD_CONFIG = ' . wp_json_encode(
			array(
				'api'       => esc_url_raw( rest_url( SAD_REST_NS . '/album/' . $token ) ),
				'layouts'   => $layouts,
				'templates' => $templates,
				'chunkSize' => $chunk,
				'home'      => home_url( '/' ),
				'welcome'   => ! empty( $_GET['sad_welcome'] ), // phpcs:ignore WordPress.Security.NonceVerification
			)
		) . ';',
		'before'
	);

	return '<div id="sad-app" class="sad-app"><div class="sad-loading">Loading your album…</div></div>';
}
