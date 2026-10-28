<?php
/**
 * Text boxes: the fonts clients can use, and drawing text into the print files.
 *
 * The editor wraps the text into lines (it measures with the same font files) and saves them,
 * so the print file breaks lines in exactly the same places. Each line sits on a baseline worked
 * out the same way here and in textBaseline() in editor.js.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Fonts bundled in assets/fonts (all under the SIL Open Font License).
 */
function sad_fonts() {
	$fonts = array(
		'prata'         => array( 'name' => 'Prata (elegant serif)', 'file' => 'Prata-Regular.ttf' ),
		'italiana'      => array( 'name' => 'Italiana (fine)', 'file' => 'Italiana-Regular.ttf' ),
		'lato'          => array( 'name' => 'Lato (clean)', 'file' => 'Lato-Regular.ttf' ),
		'poppins-light' => array( 'name' => 'Poppins Light (modern)', 'file' => 'Poppins-Light.ttf' ),
		'great-vibes'   => array( 'name' => 'Great Vibes (script)', 'file' => 'GreatVibes-Regular.ttf' ),
		'allura'        => array( 'name' => 'Allura (handwritten)', 'file' => 'Allura-Regular.ttf' ),
	);

	/**
	 * Filters the text fonts. Each font's file must be a TrueType font in assets/fonts.
	 */
	return apply_filters( 'sad_fonts', $fonts );
}

/**
 * Fonts for the editor: id => array( 'name', 'url' ).
 */
function sad_fonts_for_client() {
	$fonts = array();
	foreach ( sad_fonts() as $id => $font ) {
		$fonts[ $id ] = array( 'name' => $font['name'], 'url' => SAD_URL . 'assets/fonts/' . $font['file'] );
	}
	return $fonts;
}

const SAD_LINE_HEIGHT = 1.25;

/**
 * Cleans a text box. Fields: type "text", text, lines (the wrapped lines), font, size (points),
 * color, align, plus the box position like a photo box.
 */
function sad_sanitize_text_item( $item ) {
	$fonts = sad_fonts();
	$text  = substr( sanitize_textarea_field( (string) ( $item['text'] ?? '' ) ), 0, 500 );
	$lines = array();
	foreach ( array_slice( (array) ( $item['lines'] ?? preg_split( '/\n/', $text ) ), 0, 40 ) as $line ) {
		$lines[] = substr( sanitize_text_field( (string) $line ), 0, 300 );
	}
	$color = isset( $item['color'] ) && is_string( $item['color'] ) && preg_match( '/^#[0-9a-f]{6}$/i', $item['color'] ) ? strtolower( $item['color'] ) : '#222222';
	return array(
		'type'  => 'text',
		'text'  => $text,
		'lines' => $lines,
		'font'  => isset( $item['font'] ) && is_string( $item['font'] ) && isset( $fonts[ $item['font'] ] ) ? $item['font'] : key( $fonts ),
		'size'  => round( sad_clamp( $item['size'] ?? 24, 4, 300 ), 1 ),
		'color' => $color,
		'align' => in_array( $item['align'] ?? '', array( 'left', 'center', 'right' ), true ) ? $item['align'] : 'center',
	);
}

/**
 * Draws a text box onto the canvas, turned around its centre like a photo box.
 */
function sad_draw_text( $canvas, $item, $geo ) {
	$fonts = sad_fonts();
	$file  = SAD_DIR . 'assets/fonts/' . basename( $fonts[ $item['font'] ]['file'] ?? '' );
	if ( ! function_exists( 'imagettftext' ) || ! file_exists( $file ) || ! $item['lines'] ) {
		return;
	}
	$em    = $item['size'] * 25.4 / 72 * $geo['mm'];
	$box_w = $item['w'] * $geo['trim_w'];
	$box_h = $item['h'] * $geo['trim_h'];
	$cx    = $geo['bleed'] + ( $item['x'] + $item['w'] / 2 ) * $geo['trim_w'];
	$cy    = $geo['bleed'] + ( $item['y'] + $item['h'] / 2 ) * $geo['trim_h'];
	// GD's FreeType sizes are points at 96 dpi.
	$gd_size        = $em * 0.75;
	list( $r, $g, $b ) = sad_hex_rgb( $item['color'] );

	$rotated = abs( $item['rot'] ) >= 0.01;
	// Draw onto a layer with room round the box for swashes and descenders, then turn it.
	$pad    = $rotated ? $em * 1.5 : 0;
	$target = $canvas;
	$ox     = $cx - $box_w / 2;
	$oy     = $cy - $box_h / 2;
	if ( $rotated ) {
		$target = imagecreatetruecolor( max( 1, (int) round( $box_w + 2 * $pad ) ), max( 1, (int) round( $box_h + 2 * $pad ) ) );
		imagealphablending( $target, false );
		imagesavealpha( $target, true );
		imagefill( $target, 0, 0, imagecolorallocatealpha( $target, $r, $g, $b, 127 ) );
		imagealphablending( $target, true );
		$ox = $pad;
		$oy = $pad;
	}
	$color = imagecolorallocate( $target, $r, $g, $b );

	foreach ( $item['lines'] as $i => $line ) {
		if ( '' === trim( $line ) ) {
			continue;
		}
		$bbox  = imagettfbbox( $gd_size, 0, $file, $line );
		$width = $bbox ? $bbox[2] - $bbox[0] : 0;
		$x     = 'left' === $item['align'] ? 0 : ( 'right' === $item['align'] ? $box_w - $width : ( $box_w - $width ) / 2 );
		$x    -= $bbox ? $bbox[0] : 0;
		imagettftext( $target, $gd_size, 0, (int) round( $ox + $x ), (int) round( $oy + sad_text_baseline( $i, $em ) ), $color, $file, $line );
	}

	if ( ! $rotated ) {
		return;
	}
	$turned = imagerotate( $target, -$item['rot'], imagecolorallocatealpha( $target, $r, $g, $b, 127 ) );
	imagedestroy( $target );
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
 * Baseline of line $i from the top of the box, in the same units as $em.
 */
function sad_text_baseline( $i, $em ) {
	return ( $i + 0.5 ) * $em * SAD_LINE_HEIGHT + 0.32 * $em;
}
