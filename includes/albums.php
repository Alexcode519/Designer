<?php
/**
 * The album post type and the data stored on each album.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'init', 'sad_register_post_type' );
add_action( 'before_delete_post', 'sad_on_delete_album' );

function sad_register_post_type() {
	register_post_type(
		'sad_album',
		array(
			'labels'          => array(
				'name'          => 'Client albums',
				'singular_name' => 'Client album',
				'add_new_item'  => 'Add client album',
				'edit_item'     => 'Edit client album',
				'menu_name'     => 'Client albums',
				'all_items'     => 'All albums',
			),
			'public'          => false,
			'show_ui'         => true,
			'show_in_menu'    => true,
			'menu_icon'       => 'dashicons-book-alt',
			'supports'        => array( 'title' ),
			'capability_type' => 'post',
			'map_meta_cap'    => true,
		)
	);
}

function sad_on_delete_album( $post_id ) {
	if ( 'sad_album' === get_post_type( $post_id ) ) {
		sad_delete_album_files( $post_id );
	}
}

function sad_album_token( $album_id ) {
	$token = get_post_meta( $album_id, '_sad_token', true );
	if ( ! $token ) {
		$token = strtolower( wp_generate_password( 32, false, false ) );
		update_post_meta( $album_id, '_sad_token', $token );
	}
	return $token;
}

function sad_get_album_by_token( $token ) {
	if ( ! is_string( $token ) || ! preg_match( '/^[a-z0-9]{32}$/', $token ) ) {
		return null;
	}
	$posts = get_posts(
		array(
			'post_type'      => 'sad_album',
			'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
			'meta_key'       => '_sad_token', // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_value'     => $token, // phpcs:ignore WordPress.DB.SlowDBQuery
			'posts_per_page' => 1,
			'no_found_rows'  => true,
		)
	);
	return $posts ? $posts[0] : null;
}

function sad_album_link( $album_id ) {
	$page_id = (int) get_option( 'sad_designer_page_id' );
	$base    = $page_id ? get_permalink( $page_id ) : home_url( '/album-designer/' );
	return add_query_arg( 'album', sad_album_token( $album_id ), $base );
}

function sad_album_status( $album_id ) {
	return 'approved' === get_post_meta( $album_id, '_sad_status', true ) ? 'approved' : 'open';
}

function sad_album_config( $album_id ) {
	$size = sad_get_size( get_post_meta( $album_id, '_sad_size', true ) );
	$min  = (int) get_post_meta( $album_id, '_sad_min_spreads', true );
	$max  = (int) get_post_meta( $album_id, '_sad_max_spreads', true );
	return array(
		'size'        => $size,
		'min_spreads' => $min > 0 ? $min : 10,
		'max_spreads' => $max > 0 ? $max : 30,
	);
}

/**
 * The client's design: spreads, each with a layout and one entry per photo slot.
 */
function sad_get_design( $album_id ) {
	$design = json_decode( (string) get_post_meta( $album_id, '_sad_design', true ), true );
	if ( ! is_array( $design ) || empty( $design['spreads'] ) ) {
		$config  = sad_album_config( $album_id );
		$spreads = array();
		for ( $i = 0; $i < $config['min_spreads']; $i++ ) {
			$spreads[] = array( 'layout' => 'two-full', 'slots' => array() );
		}
		$design = array( 'spreads' => $spreads );
	}
	return sad_sanitize_design( $album_id, $design );
}

function sad_sanitize_design( $album_id, $design ) {
	$layouts = sad_layouts();
	$photos  = sad_get_photos( $album_id );
	$config  = sad_album_config( $album_id );
	$spreads = array();

	foreach ( (array) ( $design['spreads'] ?? array() ) as $spread ) {
		if ( count( $spreads ) >= $config['max_spreads'] ) {
			break;
		}
		$layout = isset( $spread['layout'] ) && isset( $layouts[ $spread['layout'] ] ) ? $spread['layout'] : 'two-full';
		$slots  = array();
		$count  = count( $layouts[ $layout ]['slots'] );
		for ( $i = 0; $i < $count; $i++ ) {
			$slot    = $spread['slots'][ $i ] ?? array();
			$photo   = isset( $slot['photo'] ) && isset( $photos[ $slot['photo'] ] ) ? $slot['photo'] : null;
			$slots[] = array(
				'photo' => $photo,
				'zoom'  => min( 5, max( 1, (float) ( $slot['zoom'] ?? 1 ) ) ),
				'x'     => min( 1, max( 0, (float) ( $slot['x'] ?? 0.5 ) ) ),
				'y'     => min( 1, max( 0, (float) ( $slot['y'] ?? 0.5 ) ) ),
			);
		}
		$spreads[] = array( 'layout' => $layout, 'slots' => $slots );
	}

	return array( 'spreads' => $spreads );
}

function sad_save_design( $album_id, $design ) {
	$clean = sad_sanitize_design( $album_id, $design );
	update_post_meta( $album_id, '_sad_design', wp_slash( wp_json_encode( $clean ) ) );
	update_post_meta( $album_id, '_sad_updated_at', time() );
	return $clean;
}

/**
 * Returns a list of problems that stop the client from approving, empty when the album is ready.
 */
function sad_design_problems( $album_id, $design ) {
	$config   = sad_album_config( $album_id );
	$problems = array();
	if ( count( $design['spreads'] ) < $config['min_spreads'] ) {
		$problems[] = sprintf( 'Your album needs at least %d spreads.', $config['min_spreads'] );
	}
	foreach ( $design['spreads'] as $n => $spread ) {
		foreach ( $spread['slots'] as $slot ) {
			if ( ! $slot['photo'] ) {
				$problems[] = sprintf( 'Spread %d has an empty photo space.', $n + 1 );
				break;
			}
		}
	}
	return $problems;
}

function sad_approve_album( $album_id ) {
	update_post_meta( $album_id, '_sad_status', 'approved' );
	update_post_meta( $album_id, '_sad_approved_at', time() );

	$settings = sad_get_settings();
	$client   = get_post_meta( $album_id, '_sad_client_name', true );
	$to       = $settings['notify_email'] ? $settings['notify_email'] : get_option( 'admin_email' );
	$edit     = admin_url( 'post.php?post=' . (int) $album_id . '&action=edit' );

	wp_mail(
		$to,
		sprintf( 'Album approved: %s', sad_album_title( $album_id ) ),
		sprintf(
			"%s has approved their album design \"%s\".\n\nOpen it to download the print files:\n%s\n",
			$client ? $client : 'Your client',
			sad_album_title( $album_id ),
			$edit
		)
	);

	$client_email = get_post_meta( $album_id, '_sad_client_email', true );
	if ( $client_email && is_email( $client_email ) ) {
		wp_mail(
			$client_email,
			sprintf( 'Your album "%s" is approved', sad_album_title( $album_id ) ),
			sprintf(
				"Thank you%s! We have received your approved album design and will send it to print.\n\nYou can view it here:\n%s\n\n%s\n",
				$client ? ', ' . $client : '',
				sad_album_link( $album_id ),
				get_bloginfo( 'name' )
			)
		);
	}
}

/**
 * The album name as plain text (get_the_title() returns HTML entities, e.g. &#038;).
 */
function sad_album_title( $album_id ) {
	return html_entity_decode( get_the_title( $album_id ), ENT_QUOTES, 'UTF-8' );
}
