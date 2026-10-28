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
		$admin . '/render/(?P<spread>\d+)',
		array(
			'methods'             => 'POST',
			'callback'            => 'sad_rest_admin_render',
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
	$result   = sad_render_spread_file( $album_id, (int) $request['spread'] );
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
