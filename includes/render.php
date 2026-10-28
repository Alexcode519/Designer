<?php
/**
 * Renders print-ready spreads from the full-size originals with GD.
 *
 * Each spread is rendered in its own request so that big albums do not run out of time or memory
 * on shared hosting.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Pixel sizes for one spread at the export resolution. A spread is two pages side by side,
 * plus bleed on all four outside edges.
 */
function sad_spread_geometry( $album_id ) {
	$settings = sad_get_settings();
	$size     = sad_album_config( $album_id )['size'];
	$dpi      = (int) $settings['dpi'];
	$mm       = $dpi / 25.4;

	$trim_w = (int) round( $size['width_mm'] * 2 * $mm );
	$trim_h = (int) round( $size['height_mm'] * $mm );
	$bleed  = (int) round( (float) $settings['bleed_mm'] * $mm );

	return array(
		'dpi'      => $dpi,
		'bleed'    => $bleed,
		'trim_w'   => $trim_w,
		'trim_h'   => $trim_h,
		'canvas_w' => $trim_w + 2 * $bleed,
		'canvas_h' => $trim_h + 2 * $bleed,
	);
}

/**
 * Where a slot lands on the canvas, in pixels. Slots touching a trim edge run out into the bleed.
 */
function sad_slot_rect( $slot, $geo ) {
	list( $x, $y, $w, $h ) = $slot;
	$eps = 0.001;

	$left   = $geo['bleed'] + $x * $geo['trim_w'];
	$top    = $geo['bleed'] + $y * $geo['trim_h'];
	$right  = $geo['bleed'] + ( $x + $w ) * $geo['trim_w'];
	$bottom = $geo['bleed'] + ( $y + $h ) * $geo['trim_h'];

	if ( $x <= $eps ) {
		$left = 0;
	}
	if ( $y <= $eps ) {
		$top = 0;
	}
	if ( $x + $w >= 1 - $eps ) {
		$right = $geo['canvas_w'];
	}
	if ( $y + $h >= 1 - $eps ) {
		$bottom = $geo['canvas_h'];
	}

	$left = (int) round( $left );
	$top  = (int) round( $top );
	return array( $left, $top, (int) round( $right ) - $left, (int) round( $bottom ) - $top );
}

/**
 * The part of the photo that fills a slot, matching the editor: the photo covers the slot,
 * is enlarged by `zoom`, and `x`/`y` (0 to 1) choose which part of the overflow shows.
 *
 * @return array [ src_x, src_y, src_w, src_h ]
 */
function sad_source_crop( $img_w, $img_h, $slot_w, $slot_h, $zoom, $pan_x, $pan_y ) {
	$scale  = max( $slot_w / $img_w, $slot_h / $img_h ) * max( 1, $zoom );
	$src_w  = $slot_w / $scale;
	$src_h  = $slot_h / $scale;
	$over_x = $img_w - $src_w;
	$over_y = $img_h - $src_h;
	return array( $over_x * $pan_x, $over_y * $pan_y, $src_w, $src_h );
}

function sad_export_basename( $album_id ) {
	$slug = sanitize_title( sad_album_title( $album_id ) );
	return $slug ? $slug : 'album-' . (int) $album_id;
}

function sad_spread_filename( $album_id, $index ) {
	return sprintf( '%s-spread-%02d.jpg', sad_export_basename( $album_id ), $index + 1 );
}

/**
 * Rough check that GD has room for the canvas plus one photo, so we can explain instead of
 * the request dying with a fatal error.
 */
function sad_memory_ok( $pixels ) {
	$limit = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );
	if ( $limit <= 0 ) {
		return true;
	}
	return memory_get_usage() + $pixels * 5 < $limit;
}

/**
 * Renders spread $index to a JPEG in the album's export folder.
 *
 * @return string|WP_Error Path to the JPEG.
 */
function sad_render_spread_file( $album_id, $index ) {
	if ( ! function_exists( 'imagecreatetruecolor' ) ) {
		return new WP_Error( 'sad_no_gd', 'The GD image library is not available on this server.', array( 'status' => 500 ) );
	}
	$design = sad_get_design( $album_id );
	if ( ! isset( $design['spreads'][ $index ] ) ) {
		return new WP_Error( 'sad_not_found', 'Spread not found.', array( 'status' => 404 ) );
	}

	wp_raise_memory_limit( 'image' );
	if ( function_exists( 'set_time_limit' ) ) {
		set_time_limit( 300 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions
	}

	$geo    = sad_spread_geometry( $album_id );
	$photos = sad_get_photos( $album_id );
	$spread = $design['spreads'][ $index ];
	$slots  = sad_layout_slots( $spread['layout'] );

	$largest = 0;
	foreach ( $spread['slots'] as $slot ) {
		if ( $slot['photo'] && isset( $photos[ $slot['photo'] ] ) ) {
			$largest = max( $largest, $photos[ $slot['photo'] ]['width'] * $photos[ $slot['photo'] ]['height'] );
		}
	}
	if ( ! sad_memory_ok( $geo['canvas_w'] * $geo['canvas_h'] + $largest ) ) {
		return new WP_Error(
			'sad_memory',
			sprintf( 'Not enough server memory to build spread %d at %d dpi. Lower the export resolution in Settings, or ask your host to raise the PHP memory limit.', $index + 1, $geo['dpi'] ),
			array( 'status' => 500 )
		);
	}

	$canvas = imagecreatetruecolor( $geo['canvas_w'], $geo['canvas_h'] );
	imagefill( $canvas, 0, 0, imagecolorallocate( $canvas, 255, 255, 255 ) );

	foreach ( $spread['slots'] as $i => $slot ) {
		if ( ! $slot['photo'] || ! isset( $photos[ $slot['photo'] ], $slots[ $i ] ) ) {
			continue;
		}
		$photo = $photos[ $slot['photo'] ];
		$path  = sad_original_path( $album_id, $photo );
		$img   = sad_load_image( $path );
		if ( ! $img ) {
			imagedestroy( $canvas );
			return new WP_Error( 'sad_missing_photo', sprintf( 'Could not open photo "%s" on spread %d.', $photo['name'], $index + 1 ), array( 'status' => 500 ) );
		}

		list( $dx, $dy, $dw, $dh ) = sad_slot_rect( $slots[ $i ], $geo );
		list( $sx, $sy, $sw, $sh ) = sad_source_crop( imagesx( $img ), imagesy( $img ), $dw, $dh, $slot['zoom'], $slot['x'], $slot['y'] );

		imagecopyresampled( $canvas, $img, $dx, $dy, (int) round( $sx ), (int) round( $sy ), $dw, $dh, (int) round( $sw ), (int) round( $sh ) );
		imagedestroy( $img );
	}

	if ( function_exists( 'imageresolution' ) ) {
		imageresolution( $canvas, $geo['dpi'], $geo['dpi'] );
	}
	$path = sad_album_subdir( $album_id, 'export' ) . '/' . sad_spread_filename( $album_id, $index );
	$ok   = imagejpeg( $canvas, $path, 95 );
	imagedestroy( $canvas );

	if ( ! $ok ) {
		return new WP_Error( 'sad_write_failed', 'Could not save the spread file.', array( 'status' => 500 ) );
	}
	return $path;
}

function sad_load_image( $path ) {
	if ( ! file_exists( $path ) ) {
		return null;
	}
	$info = getimagesize( $path );
	$type = $info ? $info[2] : 0;
	if ( IMAGETYPE_JPEG === $type ) {
		return imagecreatefromjpeg( $path );
	}
	if ( IMAGETYPE_PNG === $type ) {
		return imagecreatefrompng( $path );
	}
	return null;
}

/**
 * Combines the rendered spreads into one PDF and one ZIP of JPEGs.
 *
 * @return true|WP_Error
 */
function sad_build_package( $album_id ) {
	$design = sad_get_design( $album_id );
	$dir    = sad_album_subdir( $album_id, 'export' );
	$geo    = sad_spread_geometry( $album_id );
	$base   = sad_export_basename( $album_id );
	$files  = array();

	foreach ( array_keys( $design['spreads'] ) as $index ) {
		$path = $dir . '/' . sad_spread_filename( $album_id, $index );
		if ( ! file_exists( $path ) ) {
			return new WP_Error( 'sad_missing_spread', sprintf( 'Spread %d has not been built yet.', $index + 1 ), array( 'status' => 400 ) );
		}
		$files[] = $path;
	}

	// Remove spreads left over from a longer earlier version of the album.
	foreach ( glob( $dir . '/' . $base . '-spread-*.jpg' ) as $old ) {
		if ( ! in_array( $old, $files, true ) ) {
			unlink( $old );
		}
	}

	$points_w = $geo['canvas_w'] / $geo['dpi'] * 72;
	$points_h = $geo['canvas_h'] / $geo['dpi'] * 72;
	$bleed_pt = $geo['bleed'] / $geo['dpi'] * 72;
	$pdf      = sad_write_jpeg_pdf( $dir . '/' . $base . '.pdf', $files, $points_w, $points_h, $bleed_pt, sad_album_title( $album_id ) );
	if ( is_wp_error( $pdf ) ) {
		return $pdf;
	}

	if ( class_exists( 'ZipArchive' ) ) {
		$zip_path = $dir . '/' . $base . '-jpeg-spreads.zip';
		if ( file_exists( $zip_path ) ) {
			unlink( $zip_path );
		}
		$zip = new ZipArchive();
		if ( true === $zip->open( $zip_path, ZipArchive::CREATE ) ) {
			foreach ( $files as $file ) {
				$zip->addFile( $file, basename( $file ) );
				$zip->setCompressionName( basename( $file ), ZipArchive::CM_STORE );
			}
			$zip->close();
		}
	}

	update_post_meta( $album_id, '_sad_exported_at', time() );
	return true;
}

/**
 * Lists the finished export files with admin download links.
 */
function sad_export_links( $album_id ) {
	$dir   = sad_album_dir( $album_id ) . '/export';
	$base  = sad_export_basename( $album_id );
	$links = array();
	foreach ( array( $base . '.pdf' => 'PDF (all spreads)', $base . '-jpeg-spreads.zip' => 'JPEG spreads (ZIP)' ) as $file => $label ) {
		if ( file_exists( $dir . '/' . $file ) ) {
			$links[] = array(
				'label' => $label,
				'file'  => $file,
				'size'  => size_format( filesize( $dir . '/' . $file ) ),
				'url'   => add_query_arg(
					array(
						'action'   => 'sad_download',
						'album'    => (int) $album_id,
						'file'     => rawurlencode( $file ),
						'_wpnonce' => wp_create_nonce( 'sad_download_' . $album_id ),
					),
					admin_url( 'admin-post.php' )
				),
			);
		}
	}
	return array(
		'links'      => $links,
		'exportedAt' => (int) get_post_meta( $album_id, '_sad_exported_at', true ),
	);
}
