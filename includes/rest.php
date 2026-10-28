<?php
/**
 * REST API. Client routes are authorised by the album's secret token in the URL;
 * admin routes need a logged-in user who can edit the album.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'rest_api_init', 'sad_register_routes' );

const SAD_REST_NS = 'snippets-album/v1';

function sad_register_routes() {
	$token = '/album/(?P<token>[a-z0-9]{32})';

	register_rest_route(
		SAD_REST_NS,
		$token,
		array(
			'methods'             => 'GET',
			'callback'            => 'sad_rest_get_album',
			'permission_callback' => '__return_true',
		)
	);
	register_rest_route(
		SAD_REST_NS,
		$token . '/design',
		array(
			'methods'             => 'POST',
			'callback'            => 'sad_rest_save_design',
			'permission_callback' => '__return_true',
		)
	);
	register_rest_route(
		SAD_REST_NS,
		$token . '/photos',
		array(
			'methods'             => 'POST',
			'callback'            => 'sad_rest_upload_chunk',
			'permission_callback' => '__return_true',
		)
	);
	register_rest_route(
		SAD_REST_NS,
		$token . '/photos/(?P<photo>[a-z0-9]{16})',
		array(
			'methods'             => 'DELETE',
			'callback'            => 'sad_rest_delete_photo',
			'permission_callback' => '__return_true',
		)
	);
	register_rest_route(
		SAD_REST_NS,
		$token . '/comments',
		array(
			'methods'             => 'POST',
			'callback'            => 'sad_rest_add_comment',
			'permission_callback' => '__return_true',
		)
	);
	register_rest_route(
		SAD_REST_NS,
		$token . '/comments/(?P<comment>[a-z0-9]{10})',
		array(
			'methods'             => 'POST',
			'callback'            => 'sad_rest_resolve_comment',
			'permission_callback' => '__return_true',
		)
	);
	register_rest_route(
		SAD_REST_NS,
		$token . '/approve',
		array(
			'methods'             => 'POST',
			'callback'            => 'sad_rest_approve',
			'permission_callback' => '__return_true',
		)
	);

	$admin = '/admin/albums/(?P<id>\d+)';
	$can   = function ( WP_REST_Request $request ) {
		return current_user_can( 'edit_post', (int) $request['id'] ) && 'sad_album' === get_post_type( (int) $request['id'] );
	};
	register_rest_route(
		SAD_REST_NS,
		$admin . '/render/(?P<spread>\d+|cover)',
		array(
			'methods'             => 'POST',
			'callback'            => 'sad_rest_admin_render',
			'permission_callback' => $can,
		)
	);
	register_rest_route(
		SAD_REST_NS,
		$admin . '/photos',
		array(
			'methods'             => 'POST',
			'callback'            => 'sad_rest_admin_upload_chunk',
			'permission_callback' => $can,
		)
	);
	register_rest_route(
		SAD_REST_NS,
		$admin . '/comments',
		array(
			'methods'             => 'POST',
			'callback'            => 'sad_rest_admin_add_comment',
			'permission_callback' => $can,
		)
	);
	register_rest_route(
		SAD_REST_NS,
		$admin . '/comments/(?P<comment>[a-z0-9]{10})',
		array(
			'methods'             => 'POST',
			'callback'            => 'sad_rest_admin_resolve_comment',
			'permission_callback' => $can,
		)
	);
	register_rest_route(
		SAD_REST_NS,
		$admin . '/package',
		array(
			'methods'             => 'POST',
			'callback'            => 'sad_rest_admin_package',
			'permission_callback' => $can,
		)
	);
}

/**
 * Looks up the album for a client request, or returns an error the editor can show.
 *
 * @return WP_Post|WP_Error
 */
function sad_rest_album( WP_REST_Request $request, $must_be_open = false ) {
	$album = sad_get_album_by_token( $request['token'] );
	if ( ! $album || 'trash' === $album->post_status ) {
		return new WP_Error( 'sad_not_found', 'This album link is not valid. Please check the link from your email.', array( 'status' => 404 ) );
	}
	if ( $must_be_open && 'approved' === sad_album_status( $album->ID ) ) {
		return new WP_Error( 'sad_locked', 'This album has been approved and can no longer be changed. Contact us if you need a change.', array( 'status' => 423 ) );
	}
	return $album;
}

function sad_client_payload( $album_id ) {
	$config   = sad_album_config( $album_id );
	$settings = sad_get_settings();
	$photos   = array();
	foreach ( sad_get_photos( $album_id ) as $photo ) {
		$photos[] = sad_photo_for_client( $album_id, $photo );
	}
	usort(
		$photos,
		function ( $a, $b ) {
			return strnatcasecmp( $a['name'], $b['name'] );
		}
	);

	return array(
		'title'      => sad_album_title( $album_id ),
		'client'     => get_post_meta( $album_id, '_sad_client_name', true ),
		'status'     => sad_album_status( $album_id ),
		'size'       => $config['size'],
		'minSpreads' => $config['min_spreads'],
		'maxSpreads' => $config['max_spreads'],
		'minDpi'     => (int) $settings['min_dpi_warn'],
		'bleedMm'    => (float) $settings['bleed_mm'],
		'safeMm'     => (float) $settings['safe_mm'],
		'covers'     => sad_cover_materials(),
		'fonts'      => sad_fonts_for_client(),
		'printer'    => array(
			'coverWrapMm'      => (float) $settings['cover_wrap_mm'],
			'spineBaseMm'      => (float) $settings['spine_base_mm'],
			'spinePerSpreadMm' => (float) $settings['spine_per_spread_mm'],
			'spreadStep'       => (int) $settings['spread_step'],
		),
		'pricing'    => array(
			'currency'         => $settings['currency'],
			'includedSpreads'  => (int) $settings['included_spreads'],
			'extraSpreadPrice' => (float) $settings['extra_spread_price'],
		),
		'studio'     => get_bloginfo( 'name' ),
		'comments'   => sad_get_comments( $album_id ),
		'photos'     => $photos,
		'design'     => sad_get_design( $album_id ),
	);
}

function sad_rest_get_album( WP_REST_Request $request ) {
	$album = sad_rest_album( $request );
	if ( is_wp_error( $album ) ) {
		return $album;
	}
	return rest_ensure_response( sad_client_payload( $album->ID ) );
}

function sad_rest_save_design( WP_REST_Request $request ) {
	$album = sad_rest_album( $request, true );
	if ( is_wp_error( $album ) ) {
		return $album;
	}
	$design = $request->get_json_params()['design'] ?? null;
	if ( ! is_array( $design ) ) {
		return new WP_Error( 'sad_bad_design', 'Missing design.', array( 'status' => 400 ) );
	}
	return rest_ensure_response( array( 'design' => sad_save_design( $album->ID, $design ) ) );
}

function sad_rest_upload_chunk( WP_REST_Request $request ) {
	$album = sad_rest_album( $request, true );
	if ( is_wp_error( $album ) ) {
		return $album;
	}
	return sad_rest_receive_upload( $album->ID, $request );
}

/**
 * Takes one piece of a photo upload, from the client or from the studio in WordPress.
 */
function sad_rest_receive_upload( $album_id, WP_REST_Request $request ) {
	$album = get_post( $album_id );
	$files = $request->get_file_params();
	if ( empty( $files['chunk']['tmp_name'] ) || ! is_uploaded_file( $files['chunk']['tmp_name'] ) ) {
		return new WP_Error( 'sad_bad_upload', 'No file received. The photo may be larger than the server allows.', array( 'status' => 400 ) );
	}

	$result = sad_receive_chunk(
		$album->ID,
		(string) $request->get_param( 'upload_id' ),
		(int) $request->get_param( 'index' ),
		(int) $request->get_param( 'total' ),
		(string) $request->get_param( 'name' ),
		$files['chunk']['tmp_name']
	);
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	return rest_ensure_response(
		array(
			'done'  => null !== $result,
			'photo' => $result ? sad_photo_for_client( $album->ID, $result ) : null,
		)
	);
}

function sad_rest_admin_upload_chunk( WP_REST_Request $request ) {
	return sad_rest_receive_upload( (int) $request['id'], $request );
}

function sad_rest_add_comment( WP_REST_Request $request ) {
	$album = sad_rest_album( $request );
	if ( is_wp_error( $album ) ) {
		return $album;
	}
	if ( ! sad_rate_limit_ok( 'note', 60 ) ) {
		return new WP_Error( 'sad_busy', 'Too many notes in a short time. Please wait a little and try again.', array( 'status' => 429 ) );
	}
	$params  = $request->get_json_params();
	$comment = sad_add_comment( $album->ID, (string) ( $params['spread'] ?? '' ), 'client', (string) ( $params['text'] ?? '' ) );
	if ( is_wp_error( $comment ) ) {
		return $comment;
	}
	return rest_ensure_response( array( 'comments' => sad_get_comments( $album->ID ) ) );
}

function sad_rest_resolve_comment( WP_REST_Request $request ) {
	$album = sad_rest_album( $request );
	if ( is_wp_error( $album ) ) {
		return $album;
	}
	if ( ! sad_set_comment_resolved( $album->ID, $request['comment'], ! empty( $request->get_json_params()['resolved'] ) ) ) {
		return new WP_Error( 'sad_not_found', 'Note not found.', array( 'status' => 404 ) );
	}
	return rest_ensure_response( array( 'comments' => sad_get_comments( $album->ID ) ) );
}

function sad_rest_admin_add_comment( WP_REST_Request $request ) {
	$album_id = (int) $request['id'];
	$params   = $request->get_json_params();
	$comment  = sad_add_comment( $album_id, (string) ( $params['spread'] ?? '' ), 'studio', (string) ( $params['text'] ?? '' ) );
	if ( is_wp_error( $comment ) ) {
		return $comment;
	}
	return rest_ensure_response( array( 'comments' => sad_get_comments( $album_id ) ) );
}

function sad_rest_admin_resolve_comment( WP_REST_Request $request ) {
	$album_id = (int) $request['id'];
	if ( ! sad_set_comment_resolved( $album_id, $request['comment'], ! empty( $request->get_json_params()['resolved'] ) ) ) {
		return new WP_Error( 'sad_not_found', 'Note not found.', array( 'status' => 404 ) );
	}
	return rest_ensure_response( array( 'comments' => sad_get_comments( $album_id ) ) );
}

function sad_rest_delete_photo( WP_REST_Request $request ) {
	$album = sad_rest_album( $request, true );
	if ( is_wp_error( $album ) ) {
		return $album;
	}
	if ( ! sad_delete_photo( $album->ID, $request['photo'] ) ) {
		return new WP_Error( 'sad_not_found', 'Photo not found.', array( 'status' => 404 ) );
	}
	return rest_ensure_response( array( 'deleted' => true ) );
}

function sad_rest_approve( WP_REST_Request $request ) {
	$album = sad_rest_album( $request, true );
	if ( is_wp_error( $album ) ) {
		return $album;
	}
	$design = $request->get_json_params()['design'] ?? null;
	$design = is_array( $design ) ? sad_save_design( $album->ID, $design ) : sad_get_design( $album->ID );

	$problems = sad_design_problems( $album->ID, $design );
	if ( $problems ) {
		return new WP_Error( 'sad_not_ready', implode( ' ', $problems ), array( 'status' => 400 ) );
	}
	sad_approve_album( $album->ID );
	return rest_ensure_response( sad_client_payload( $album->ID ) );
}

function sad_rest_admin_render( WP_REST_Request $request ) {
	$album_id = (int) $request['id'];
	$spread   = 'cover' === $request['spread'] ? 'cover' : (int) $request['spread'];
	$result   = sad_render_spread_file( $album_id, $spread );
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	return rest_ensure_response( array( 'file' => basename( $result ) ) );
}

function sad_rest_admin_package( WP_REST_Request $request ) {
	$album_id = (int) $request['id'];
	$result   = sad_build_package( $album_id );
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	return rest_ensure_response( sad_export_links( $album_id ) );
}
