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
	$size     = sad_get_size( get_post_meta( $album_id, '_sad_size', true ) );
	$min      = (int) get_post_meta( $album_id, '_sad_min_spreads', true );
	$max      = (int) get_post_meta( $album_id, '_sad_max_spreads', true );
	$settings = sad_get_settings();
	$min      = $min > 0 ? $min : (int) $settings['min_spreads'];
	$max      = $max > 0 ? $max : (int) $settings['max_spreads'];
	return array(
		'size'        => $size,
		'min_spreads' => $min,
		'max_spreads' => max( $min, $max ),
	);
}

/**
 * The client's design: spreads, each holding photo boxes in stacking order (last on top).
 *
 * A box is { photo, x, y, w, h, rot, fit, zoom, px, py }. x, y, w and h are fractions of the
 * trimmed spread (both pages side by side), so a box can run out into the bleed with values
 * below 0 or above 1. rot is degrees clockwise around the box centre. With fit "fill" the photo
 * covers the box, enlarged by zoom, and px/py (0 to 1) choose which part of the overflow shows;
 * with "fit" the whole photo shows, centred. A text box has type "text" (see sad_sanitize_text_item()).
 * Each spread also has an id that stays the same when spreads are moved (comments use it) and a
 * background colour, bg ("" for white).
 */
function sad_get_design( $album_id ) {
	$design = json_decode( (string) get_post_meta( $album_id, '_sad_design', true ), true );
	if ( ! is_array( $design ) || empty( $design['spreads'] ) ) {
		$config  = sad_album_config( $album_id );
		$spreads = array();
		for ( $i = 0; $i < $config['min_spreads']; $i++ ) {
			$spreads[] = array( 'layout' => 'two-full', 'items' => sad_layout_items( $album_id, 'two-full' ) );
		}
		$design = array( 'cover' => $design['cover'] ?? null, 'spreads' => $spreads );
	}
	$clean = sad_sanitize_design( $album_id, $design );
	// Keep the spread ids that were just given out (new album, or saved before spreads had ids).
	if ( wp_list_pluck( $clean['spreads'], 'id' ) !== array_map( function ( $spread ) {
		return $spread['id'] ?? null;
	}, array_slice( $design['spreads'], 0, count( $clean['spreads'] ) ) ) ) {
		update_post_meta( $album_id, '_sad_design', wp_slash( wp_json_encode( $clean ) ) );
	}
	return $clean;
}

/**
 * Bleed as a fraction of the trimmed spread width and height.
 */
function sad_bleed_fractions( $album_id ) {
	$size  = sad_album_config( $album_id )['size'];
	$bleed = (float) sad_get_settings()['bleed_mm'];
	return array( $bleed / ( $size['width_mm'] * 2 ), $bleed / $size['height_mm'] );
}

function sad_new_item( $photo, $x, $y, $w, $h ) {
	return array(
		'type'  => 'photo',
		'photo' => $photo,
		'x'     => $x,
		'y'     => $y,
		'w'     => $w,
		'h'     => $h,
		'rot'   => 0,
		'fit'   => 'fill',
		'zoom'  => 1,
		'px'    => 0.5,
		'py'    => 0.5,
	);
}

/**
 * Photo boxes for a layout. Boxes touching a trim edge run out into the bleed. Matches layoutItems() in editor.js.
 */
function sad_layout_items( $album_id, $layout_id, $photo_ids = array() ) {
	list( $bx, $by ) = sad_bleed_fractions( $album_id );
	$eps             = 0.001;
	$items           = array();
	foreach ( sad_layout_slots( $layout_id ) as $i => $slot ) {
		list( $x, $y, $w, $h ) = $slot;
		$x2 = $x + $w;
		$y2 = $y + $h;
		$x  = $x <= $eps ? -$bx : $x;
		$y  = $y <= $eps ? -$by : $y;
		$x2 = $x2 >= 1 - $eps ? 1 + $bx : $x2;
		$y2 = $y2 >= 1 - $eps ? 1 + $by : $y2;
		$items[] = sad_new_item( $photo_ids[ $i ] ?? null, $x, $y, $x2 - $x, $y2 - $y );
	}
	return $items;
}

function sad_clamp( $value, $min, $max ) {
	return min( $max, max( $min, (float) $value ) );
}

/**
 * Cleans a list of photo boxes (see sad_get_design() for the fields).
 */
function sad_sanitize_items( $album_id, $items_in, $photos = null ) {
	$photos = null === $photos ? sad_get_photos( $album_id ) : $photos;
	$items = array();
	foreach ( array_slice( (array) $items_in, 0, 60 ) as $item ) {
		if ( ! is_array( $item ) ) {
			continue;
		}
		if ( isset( $item['type'] ) && 'text' === $item['type'] ) {
			$items[] = array_merge(
				sad_sanitize_text_item( $item ),
				array(
					'x'   => sad_clamp( $item['x'] ?? 0, -1, 2 ),
					'y'   => sad_clamp( $item['y'] ?? 0, -1, 2 ),
					'w'   => sad_clamp( $item['w'] ?? 0.25, 0.005, 2 ),
					'h'   => sad_clamp( $item['h'] ?? 0.1, 0.005, 2 ),
					'rot' => sad_clean_rotation( $item['rot'] ?? 0 ),
				)
			);
			continue;
		}
		$photo = isset( $item['photo'] ) && is_string( $item['photo'] ) && isset( $photos[ $item['photo'] ] ) ? $item['photo'] : null;
		$items[] = array(
			'type'  => 'photo',
			'photo' => $photo,
			'x'     => sad_clamp( $item['x'] ?? 0, -1, 2 ),
			'y'     => sad_clamp( $item['y'] ?? 0, -1, 2 ),
			'w'     => sad_clamp( $item['w'] ?? 0.25, 0.005, 2 ),
			'h'     => sad_clamp( $item['h'] ?? 0.25, 0.005, 2 ),
			'rot'   => sad_clean_rotation( $item['rot'] ?? 0 ),
			'fit'   => isset( $item['fit'] ) && 'fit' === $item['fit'] ? 'fit' : 'fill',
			'zoom'  => sad_clamp( $item['zoom'] ?? 1, 1, 5 ),
			'px'    => sad_clamp( $item['px'] ?? 0.5, 0, 1 ),
			'py'    => sad_clamp( $item['py'] ?? 0.5, 0, 1 ),
		);
	}
	return $items;
}

/**
 * Degrees, from -180 to 180.
 */
function sad_clean_rotation( $rot ) {
	$rot = fmod( (float) $rot, 360 );
	if ( $rot > 180 ) {
		$rot -= 360;
	} elseif ( $rot <= -180 ) {
		$rot += 360;
	}
	return round( $rot, 2 );
}

function sad_clean_colour( $value ) {
	return is_string( $value ) && preg_match( '/^#[0-9a-f]{6}$/i', $value ) ? strtolower( $value ) : '';
}

function sad_new_spread_id() {
	return strtolower( wp_generate_password( 8, false, false ) );
}

function sad_sanitize_design( $album_id, $design ) {
	$layouts = sad_layouts();
	$photos  = sad_get_photos( $album_id );
	$config  = sad_album_config( $album_id );
	$spreads = array();
	$seen    = array();

	foreach ( (array) ( $design['spreads'] ?? array() ) as $spread ) {
		if ( count( $spreads ) >= $config['max_spreads'] ) {
			break;
		}
		$layout = isset( $spread['layout'] ) && is_string( $spread['layout'] ) && isset( $layouts[ $spread['layout'] ] ) ? $spread['layout'] : null;

		if ( ! isset( $spread['items'] ) && isset( $spread['slots'] ) ) {
			// Designs saved before free placement: one fixed space per layout slot.
			$items = sad_layout_items( $album_id, $layout ? $layout : 'two-full' );
			foreach ( $items as $i => $item ) {
				$slot = $spread['slots'][ $i ] ?? array();
				$items[ $i ]['photo'] = $slot['photo'] ?? null;
				$items[ $i ]['zoom']  = $slot['zoom'] ?? 1;
				$items[ $i ]['px']    = $slot['x'] ?? 0.5;
				$items[ $i ]['py']    = $slot['y'] ?? 0.5;
			}
			$spread['items'] = $items;
		}

		$id = isset( $spread['id'] ) && is_string( $spread['id'] ) && preg_match( '/^[a-z0-9]{8}$/', $spread['id'] ) && ! isset( $seen[ $spread['id'] ] ) ? $spread['id'] : sad_new_spread_id();
		$seen[ $id ] = true;
		$items       = sad_sanitize_items( $album_id, $spread['items'] ?? array(), $photos );
		$spreads[]   = array(
			'id'     => $id,
			'layout' => $layout,
			'bg'     => sad_clean_colour( $spread['bg'] ?? '' ),
			'items'  => $items,
		);
	}

	return array(
		'cover'   => sad_sanitize_cover( $album_id, $design['cover'] ?? null ),
		'spreads' => $spreads,
	);
}

function sad_save_design( $album_id, $design ) {
	$clean = sad_sanitize_design( $album_id, $design );
	update_post_meta( $album_id, '_sad_design', wp_slash( wp_json_encode( $clean ) ) );
	update_post_meta( $album_id, '_sad_updated_at', time() );
	delete_post_meta( $album_id, '_sad_reminders_sent' );
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
	$step = (int) sad_get_settings()['spread_step'];
	if ( $step > 1 && count( $design['spreads'] ) % $step ) {
		$problems[] = sprintf( 'The number of spreads must be a multiple of %d.', $step );
	}
	if ( sad_cover_is_printed( $design['cover'] ) ) {
		if ( ! $design['cover']['items'] ) {
			$problems[] = 'The printed cover has no photos.';
		} elseif ( sad_has_empty_box( $design['cover'] ) ) {
			$problems[] = 'The cover has an empty photo box.';
		}
	}
	foreach ( $design['spreads'] as $n => $spread ) {
		if ( ! $spread['items'] ) {
			$problems[] = sprintf( 'Spread %d is blank.', $n + 1 );
		} elseif ( sad_has_empty_box( $spread ) ) {
			$problems[] = sprintf( 'Spread %d has an empty photo box.', $n + 1 );
		}
	}
	return $problems;
}

function sad_has_empty_box( $spread ) {
	foreach ( $spread['items'] as $item ) {
		if ( 'photo' === $item['type'] && ! $item['photo'] ) {
			return true;
		}
	}
	return false;
}

/**
 * Extras the client has chosen beyond the standard album, with prices from Settings.
 *
 * @return array( 'lines' => array( array( 'label', 'amount' ) ), 'total' => float )
 */
function sad_album_extras( $album_id, $design = null ) {
	$design   = $design ? $design : sad_get_design( $album_id );
	$settings = sad_get_settings();
	$lines    = array();
	$extra    = count( $design['spreads'] ) - (int) $settings['included_spreads'];
	if ( $extra > 0 && (float) $settings['extra_spread_price'] > 0 ) {
		$lines[] = array(
			'label'  => sprintf( '%d extra spread%s', $extra, 1 === $extra ? '' : 's' ),
			'amount' => $extra * (float) $settings['extra_spread_price'],
		);
	}
	$material = sad_covers_enabled() ? ( sad_cover_materials()[ $design['cover']['type'] ] ?? null ) : null;
	if ( $material && $material['price'] > 0 ) {
		$lines[] = array( 'label' => $material['name'] . ' cover', 'amount' => (float) $material['price'] );
	}
	return array(
		'lines' => $lines,
		'total' => array_sum( wp_list_pluck( $lines, 'amount' ) ),
	);
}

function sad_money( $amount ) {
	$currency = sad_get_settings()['currency'];
	return $currency . number_format( (float) $amount, floor( $amount ) == $amount ? 0 : 2, '.', ',' ); // phpcs:ignore Universal.Operators.StrictComparisons
}

/**
 * The extras in words for emails and the admin screen, or "" when there are none.
 */
function sad_extras_text( $album_id, $design = null ) {
	$extras = sad_album_extras( $album_id, $design );
	if ( ! $extras['lines'] ) {
		return '';
	}
	$text = '';
	foreach ( $extras['lines'] as $line ) {
		$text .= $line['label'] . ': ' . sad_money( $line['amount'] ) . "\n";
	}
	return $text . 'Total extras: ' . sad_money( $extras['total'] ) . "\n";
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
			"%s has approved their album design \"%s\".\n\n%sSpreads: %d\n%s\nOpen it to download the print files:\n%s\n",
			$client ? $client : 'Your client',
			sad_album_title( $album_id ),
			sad_covers_enabled() ? 'Cover: ' . sad_cover_label( sad_get_design( $album_id )['cover'] ) . "\n" : '',
			count( sad_get_design( $album_id )['spreads'] ),
			sad_extras_text( $album_id ) ? "\nExtras chosen:\n" . sad_extras_text( $album_id ) : '',
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
