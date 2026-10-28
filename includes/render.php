<?php
/**
 * Renders print-ready spreads from the full-size originals with GD.
 *
 * Each spread is rendered in its own request so that big albums do not run out of time or memory
 * on shared hosting.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sizes in mm of a spread, or of the printed cover. A spread is two pages side by side; the cover
 * is back cover, spine and front cover, with the spine growing with the number of spreads (from the
 * printer specs in Settings). tw x th is the trimmed size, b the bleed (the wrap, for the cover) on
 * each edge. Matches geo() in editor.js.
 */
function sad_mm_geometry( $album_id, $is_cover = false, $spread_count = 0 ) {
	$settings = sad_get_settings();
	$size     = sad_album_config( $album_id )['size'];
	$page_w   = (float) $size['width_mm'];
	$spine    = 0.0;
	$bleed    = (float) $settings['bleed_mm'];
	if ( $is_cover ) {
		$spine = round( (float) $settings['spine_base_mm'] + (float) $settings['spine_per_spread_mm'] * $spread_count, 1 );
		$bleed = (float) $settings['cover_wrap_mm'] > 0 ? (float) $settings['cover_wrap_mm'] : $bleed;
	}
	return array(
		'tw'     => 2 * $page_w + $spine,
		'th'     => (float) $size['height_mm'],
		'b'      => $bleed,
		'page_w' => $page_w,
		'spine'  => $spine,
	);
}

/**
 * Pixel sizes for one spread (or the cover when $index is "cover") at the export resolution,
 * bleed included on all four outside edges.
 */
function sad_spread_geometry( $album_id, $index = null ) {
	$settings = sad_get_settings();
	$dpi      = (int) $settings['dpi'];
	$mm       = $dpi / 25.4;
	$is_cover = 'cover' === $index;
	$g        = sad_mm_geometry( $album_id, $is_cover, $is_cover ? count( sad_get_design( $album_id )['spreads'] ) : 0 );

	$trim_w = (int) round( $g['tw'] * $mm );
	$trim_h = (int) round( $g['th'] * $mm );
	$bleed  = (int) round( $g['b'] * $mm );

	return array(
		'dpi'      => $dpi,
		'mm'       => $mm,
		'bleed'    => $bleed,
		'trim_w'   => $trim_w,
		'trim_h'   => $trim_h,
		'canvas_w' => $trim_w + 2 * $bleed,
		'canvas_h' => $trim_h + 2 * $bleed,
		'spine_mm' => $g['spine'],
		'width_mm' => $g['tw'] + 2 * $g['b'],
		'height_mm'=> $g['th'] + 2 * $g['b'],
	);
}

/**
 * The part of the photo that fills a box in "fill" mode, matching the editor: the photo covers the box,
 * is enlarged by `zoom`, and `x`/`y` (0 to 1) choose which part of the overflow shows.
 *
 * @return array [ src_x, src_y, src_w, src_h ]
 */
function sad_source_crop( $img_w, $img_h, $box_w, $box_h, $zoom, $pan_x, $pan_y ) {
	$scale  = max( $box_w / $img_w, $box_h / $img_h ) * max( 1, $zoom );
	$src_w  = $box_w / $scale;
	$src_h  = $box_h / $scale;
	$over_x = $img_w - $src_w;
	$over_y = $img_h - $src_h;
	return array( $over_x * $pan_x, $over_y * $pan_y, $src_w, $src_h );
}

function sad_export_basename( $album_id ) {
	$slug = sanitize_title( sad_album_title( $album_id ) );
	return $slug ? $slug : 'album-' . (int) $album_id;
}

function sad_spread_filename( $album_id, $index ) {
	if ( 'cover' === $index ) {
		return sad_export_basename( $album_id ) . '-cover.jpg';
	}
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
 * Renders spread $index (from 0), or the printed cover when $index is "cover", to a JPEG in the
 * album's export folder. The cover is laid out like a spread: back cover left, front cover right.
 *
 * @return string|WP_Error Path to the JPEG.
 */
function sad_render_spread_file( $album_id, $index ) {
	if ( ! function_exists( 'imagecreatetruecolor' ) ) {
		return new WP_Error( 'sad_no_gd', 'The GD image library is not available on this server.', array( 'status' => 500 ) );
	}
	$design = sad_get_design( $album_id );
	if ( 'cover' === $index ) {
		if ( ! sad_cover_is_printed( $design['cover'] ) ) {
			return new WP_Error( 'sad_not_found', 'This album has a bound cover, which has no print file.', array( 'status' => 404 ) );
		}
		$spread = $design['cover'];
		$label  = 'the cover';
	} elseif ( isset( $design['spreads'][ $index ] ) ) {
		$spread = $design['spreads'][ $index ];
		$label  = sprintf( 'spread %d', $index + 1 );
	} else {
		return new WP_Error( 'sad_not_found', 'Spread not found.', array( 'status' => 404 ) );
	}

	wp_raise_memory_limit( 'image' );
	if ( function_exists( 'set_time_limit' ) ) {
		set_time_limit( 300 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions
	}

	$geo    = sad_spread_geometry( $album_id, $index );
	$photos = sad_get_photos( $album_id );

	$largest = 0;
	foreach ( $spread['items'] as $item ) {
		if ( ! empty( $item['photo'] ) && isset( $photos[ $item['photo'] ] ) ) {
			$box     = $item['w'] * $geo['trim_w'] * $item['h'] * $geo['trim_h'] * ( $item['rot'] ? 3 : 0 );
			$largest = max( $largest, $photos[ $item['photo'] ]['width'] * $photos[ $item['photo'] ]['height'] + $box );
		}
	}
	if ( ! sad_memory_ok( $geo['canvas_w'] * $geo['canvas_h'] + $largest ) ) {
		return new WP_Error(
			'sad_memory',
			sprintf( 'Not enough server memory to build %s at %d dpi. Lower the export resolution in Settings, or ask your host to raise the PHP memory limit.', $label, $geo['dpi'] ),
			array( 'status' => 500 )
		);
	}

	$canvas = imagecreatetruecolor( $geo['canvas_w'], $geo['canvas_h'] );
	list( $r, $g, $b ) = sad_hex_rgb( $spread['bg'] ? $spread['bg'] : '#ffffff' );
	imagefill( $canvas, 0, 0, imagecolorallocate( $canvas, $r, $g, $b ) );
	imagealphablending( $canvas, true );

	// Boxes are drawn in order, so later ones sit on top.
	foreach ( $spread['items'] as $item ) {
		if ( 'text' === $item['type'] ) {
			sad_draw_text( $canvas, $item, $geo );
			continue;
		}
		if ( empty( $item['photo'] ) || ! isset( $photos[ $item['photo'] ] ) ) {
			continue;
		}
		$photo = $photos[ $item['photo'] ];
		$img   = sad_load_image( sad_original_path( $album_id, $photo ) );
		if ( ! $img ) {
			imagedestroy( $canvas );
			return new WP_Error( 'sad_missing_photo', sprintf( 'Could not open photo "%s" on %s.', $photo['name'], $label ), array( 'status' => 500 ) );
		}
		sad_draw_item( $canvas, $img, $item, $geo );
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

/**
 * Draws one photo box onto the canvas: cropped to fill the box, or fitted inside it, then rotated
 * around the box centre. Matches the editor's CSS (background-size/position, then rotate()).
 */
function sad_draw_item( $canvas, $img, $item, $geo ) {
	$img_w = imagesx( $img );
	$img_h = imagesy( $img );
	$box_w = $item['w'] * $geo['trim_w'];
	$box_h = $item['h'] * $geo['trim_h'];
	$cx    = $geo['bleed'] + ( $item['x'] + $item['w'] / 2 ) * $geo['trim_w'];
	$cy    = $geo['bleed'] + ( $item['y'] + $item['h'] / 2 ) * $geo['trim_h'];

	if ( 'fit' === $item['fit'] ) {
		$scale = min( $box_w / $img_w, $box_h / $img_h );
		$dst   = array( ( $box_w - $img_w * $scale ) / 2, ( $box_h - $img_h * $scale ) / 2, $img_w * $scale, $img_h * $scale );
		$src   = array( 0, 0, $img_w, $img_h );
	} else {
		$dst = array( 0, 0, $box_w, $box_h );
		$src = sad_source_crop( $img_w, $img_h, $box_w, $box_h, $item['zoom'], $item['px'], $item['py'] );
	}

	if ( abs( $item['rot'] ) < 0.01 ) {
		sad_copy_clipped( $canvas, $img, $cx - $box_w / 2 + $dst[0], $cy - $box_h / 2 + $dst[1], $dst[2], $dst[3], $src );
		return;
	}

	// Rotated: draw the box on its own transparent layer, turn it, then lay it on the canvas.
	$layer = imagecreatetruecolor( max( 1, (int) round( $box_w ) ), max( 1, (int) round( $box_h ) ) );
	imagealphablending( $layer, false );
	imagesavealpha( $layer, true );
	imagefill( $layer, 0, 0, imagecolorallocatealpha( $layer, 255, 255, 255, 127 ) );
	imagealphablending( $layer, true );
	sad_copy_clipped( $layer, $img, $dst[0], $dst[1], $dst[2], $dst[3], $src );

	// GD turns anticlockwise; the editor's rotation is clockwise.
	$turned = imagerotate( $layer, -$item['rot'], imagecolorallocatealpha( $layer, 255, 255, 255, 127 ) );
	imagedestroy( $layer );
	if ( ! $turned ) {
		return;
	}
	imagesavealpha( $turned, true );
	$tw = imagesx( $turned );
	$th = imagesy( $turned );
	sad_copy_clipped( $canvas, $turned, $cx - $tw / 2, $cy - $th / 2, $tw, $th, array( 0, 0, $tw, $th ) );
	imagedestroy( $turned );
}

/**
 * imagecopyresampled() for a destination rectangle that may hang off the canvas: trims the part
 * outside and takes the matching part of the source.
 *
 * @param array $src [ x, y, w, h ] in source pixels.
 */
function sad_copy_clipped( $dst_img, $src_img, $dx, $dy, $dw, $dh, $src ) {
	list( $sx, $sy, $sw, $sh ) = $src;
	if ( $dw <= 0 || $dh <= 0 || $sw <= 0 || $sh <= 0 ) {
		return;
	}
	$left   = max( 0, $dx );
	$top    = max( 0, $dy );
	$right  = min( imagesx( $dst_img ), $dx + $dw );
	$bottom = min( imagesy( $dst_img ), $dy + $dh );
	if ( $right - $left < 0.5 || $bottom - $top < 0.5 ) {
		return;
	}
	$kx = $sw / $dw;
	$ky = $sh / $dh;
	$l  = (int) round( $left );
	$t  = (int) round( $top );
	imagecopyresampled(
		$dst_img,
		$src_img,
		$l,
		$t,
		(int) round( $sx + ( $left - $dx ) * $kx ),
		(int) round( $sy + ( $top - $dy ) * $ky ),
		(int) round( $right ) - $l,
		(int) round( $bottom ) - $t,
		max( 1, (int) round( ( $right - $left ) * $kx ) ),
		max( 1, (int) round( ( $bottom - $top ) * $ky ) )
	);
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
	$cover  = $dir . '/' . sad_spread_filename( $album_id, 'cover' );

	if ( sad_cover_is_printed( $design['cover'] ) ) {
		if ( ! file_exists( $cover ) ) {
			return new WP_Error( 'sad_missing_spread', 'The cover has not been built yet.', array( 'status' => 400 ) );
		}
		$files[] = $cover;
	} elseif ( file_exists( $cover ) ) {
		unlink( $cover );
	}

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

	// Page sizes in points, bleed included. A printed cover with a spine and wrap is bigger than a spread.
	$pages = array();
	foreach ( $files as $file ) {
		$page_geo = $file === $cover ? sad_spread_geometry( $album_id, 'cover' ) : $geo;
		$pages[]  = array(
			'file'   => $file,
			'width'  => $page_geo['canvas_w'] / $page_geo['dpi'] * 72,
			'height' => $page_geo['canvas_h'] / $page_geo['dpi'] * 72,
			'bleed'  => $page_geo['bleed'] / $page_geo['dpi'] * 72,
		);
	}
	$pdf = sad_write_jpeg_pdf( $dir . '/' . $base . '.pdf', $pages, sad_album_title( $album_id ) );
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

function sad_hex_rgb( $hex ) {
	$hex = ltrim( (string) $hex, '#' );
	if ( 3 === strlen( $hex ) ) {
		$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
	}
	$n = hexdec( str_pad( substr( $hex, 0, 6 ), 6, '0' ) );
	return array( ( $n >> 16 ) & 255, ( $n >> 8 ) & 255, $n & 255 );
}
