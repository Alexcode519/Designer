<?php
/**
 * The [snippets_album_designer] shortcode: the client-facing editor.
 */

defined( 'ABSPATH' ) || exit;

add_shortcode( 'snippets_album_designer', 'sad_render_shortcode' );
add_filter( 'wp_robots', 'sad_noindex_designer_page' );

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
		return '<div class="sad-message">Open this page using the album link we emailed you.</div>';
	}

	wp_enqueue_style( 'sad-editor', SAD_URL . 'assets/editor.css', array(), SAD_VERSION );
	wp_enqueue_script( 'sad-editor', SAD_URL . 'assets/editor.js', array(), SAD_VERSION, true );

	$layouts = array();
	foreach ( sad_layouts() as $id => $layout ) {
		$layouts[] = array( 'id' => $id, 'name' => $layout['name'], 'slots' => $layout['slots'] );
	}

	// Leave headroom under the server's upload limit for the other form fields.
	$chunk = (int) apply_filters( 'sad_chunk_size', min( 2 * MB_IN_BYTES, max( 256 * KB_IN_BYTES, wp_max_upload_size() - 64 * KB_IN_BYTES ) ) );

	wp_add_inline_script(
		'sad-editor',
		'window.SAD_CONFIG = ' . wp_json_encode(
			array(
				'api'       => esc_url_raw( rest_url( SAD_REST_NS . '/album/' . $token ) ),
				'layouts'   => $layouts,
				'chunkSize' => $chunk,
			)
		) . ';',
		'before'
	);

	return '<div id="sad-app" class="sad-app"><div class="sad-loading">Loading your album…</div></div>';
}
