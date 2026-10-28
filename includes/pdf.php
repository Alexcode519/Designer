<?php
/**
 * A minimal PDF writer: one JPEG per page, embedded as-is (no re-compression), with the
 * TrimBox and BleedBox set so printers know where to cut.
 */

defined( 'ABSPATH' ) || exit;

/**
 * @param string   $path     Where to write the PDF.
 * @param string[] $jpegs    JPEG files, one per page, in order.
 * @param float    $width    Page width in points, bleed included.
 * @param float    $height   Page height in points, bleed included.
 * @param float    $bleed    Bleed per edge in points.
 * @param string   $title    Document title.
 * @return true|WP_Error
 */
function sad_write_jpeg_pdf( $path, $jpegs, $width, $height, $bleed, $title ) {
	$out = fopen( $path, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	if ( ! $out ) {
		return new WP_Error( 'sad_pdf_failed', 'Could not create the PDF file.', array( 'status' => 500 ) );
	}

	$offsets = array();
	$pos     = 0;
	$write   = function ( $data ) use ( $out, &$pos ) {
		fwrite( $out, $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$pos += strlen( $data );
	};
	$begin   = function ( $num ) use ( &$offsets, &$pos, $write ) {
		$offsets[ $num ] = $pos;
		$write( $num . " 0 obj\n" );
	};
	$num     = function ( $value ) {
		return rtrim( rtrim( sprintf( '%.3F', $value ), '0' ), '.' );
	};

	$write( "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n" );

	// Objects: 1 catalog, 2 pages, 3 info, then three per page (page, content, image).
	$count    = count( $jpegs );
	$page_ids = array();
	for ( $i = 0; $i < $count; $i++ ) {
		$page_ids[] = 4 + $i * 3;
	}

	$begin( 1 );
	$write( "<< /Type /Catalog /Pages 2 0 R >>\nendobj\n" );

	$begin( 2 );
	$kids = implode( ' ', array_map( function ( $id ) { return $id . ' 0 R'; }, $page_ids ) );
	$write( '<< /Type /Pages /Count ' . $count . ' /Kids [' . $kids . "] >>\nendobj\n" );

	$begin( 3 );
	$write( '<< /Title ' . sad_pdf_string( $title ) . ' /Producer (Snippets Album Designer) /CreationDate (D:' . gmdate( 'YmdHis' ) . "Z) >>\nendobj\n" );

	$media = '[0 0 ' . $num( $width ) . ' ' . $num( $height ) . ']';
	$trim  = '[' . $num( $bleed ) . ' ' . $num( $bleed ) . ' ' . $num( $width - $bleed ) . ' ' . $num( $height - $bleed ) . ']';

	foreach ( $jpegs as $i => $jpeg ) {
		$info = getimagesize( $jpeg );
		$data = file_get_contents( $jpeg ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! $info || IMAGETYPE_JPEG !== $info[2] || false === $data ) {
			fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			unlink( $path );
			return new WP_Error( 'sad_pdf_failed', 'A spread file is not a valid JPEG.', array( 'status' => 500 ) );
		}
		$channels = isset( $info['channels'] ) ? (int) $info['channels'] : 3;
		$space    = 1 === $channels ? '/DeviceGray' : ( 4 === $channels ? '/DeviceCMYK /Decode [1 0 1 0 1 0 1 0]' : '/DeviceRGB' );

		$page_id    = $page_ids[ $i ];
		$content_id = $page_id + 1;
		$image_id   = $page_id + 2;

		$begin( $page_id );
		$write( "<< /Type /Page /Parent 2 0 R /MediaBox $media /BleedBox $media /TrimBox $trim /Resources << /XObject << /Im0 $image_id 0 R >> >> /Contents $content_id 0 R >>\nendobj\n" );

		$content = 'q ' . $num( $width ) . ' 0 0 ' . $num( $height ) . " 0 0 cm /Im0 Do Q\n";
		$begin( $content_id );
		$write( '<< /Length ' . strlen( $content ) . " >>\nstream\n" . $content . "endstream\nendobj\n" );

		$begin( $image_id );
		$write( "<< /Type /XObject /Subtype /Image /Width {$info[0]} /Height {$info[1]} /ColorSpace $space /BitsPerComponent 8 /Filter /DCTDecode /Length " . strlen( $data ) . " >>\nstream\n" );
		$write( $data );
		$write( "\nendstream\nendobj\n" );
		unset( $data );
	}

	$total = 4 + $count * 3;
	$xref  = $pos;
	$write( "xref\n0 $total\n0000000000 65535 f \n" );
	for ( $n = 1; $n < $total; $n++ ) {
		$write( sprintf( "%010d 00000 n \n", $offsets[ $n ] ) );
	}
	$write( "trailer\n<< /Size $total /Root 1 0 R /Info 3 0 R >>\nstartxref\n$xref\n%%EOF\n" );
	fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	return true;
}

/**
 * Encodes text as a PDF string, using UTF-16 when it is not plain ASCII.
 */
function sad_pdf_string( $text ) {
	if ( preg_match( '/^[\x20-\x7E]*$/', $text ) ) {
		return '(' . strtr( $text, array( '\\' => '\\\\', '(' => '\\(', ')' => '\\)' ) ) . ')';
	}
	$utf16 = function_exists( 'mb_convert_encoding' ) ? mb_convert_encoding( $text, 'UTF-16BE', 'UTF-8' ) : '';
	return '<FEFF' . strtoupper( bin2hex( $utf16 ) ) . '>';
}
