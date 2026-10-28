<?php
/**
 * Album cover choices. A cover is shown and designed as one spread: back cover on the left,
 * front cover on the right. Clients choose the cover type when they start and can change it
 * in the editor.
 */

defined( 'ABSPATH' ) || exit;

/**
 * The cover types set up in Settings, one per line:
 *   "Name | printed"                         a printed cover the client designs with photos
 *   "Name | Colour #hex, Colour #hex, ..."   a bound cover (leather, linen...) in those colours
 * An optional third part is the upgrade price, e.g. "Leather | Black #1c1a19 | 800".
 */
function sad_default_cover_text() {
	return implode(
		"\n",
		array(
			'Hardcover photo | printed',
			'Softcover photo | printed',
			'Leather | Black #1c1a19, Espresso #3b2619, Cognac #8a4f2a, Tan #b0835a, Navy #1f2a3d, Burgundy #5a1d25, Ivory #e9e1d2',
			'Linen | Natural #cfc2ab, Stone #9c968b, Sage #8e9a83, Dusty blue #7f91a3, Charcoal #3e3d3b',
		)
	);
}

function sad_parse_cover_types( $text ) {
	$types = array();
	foreach ( preg_split( '/\r\n|\r|\n/', (string) $text ) as $line ) {
		$parts = array_map( 'trim', explode( '|', $line, 3 ) );
		$name  = sanitize_text_field( $parts[0] );
		$id    = sanitize_title( $name );
		if ( '' === $id || isset( $types[ $id ] ) ) {
			continue;
		}
		$spec    = $parts[1] ?? '';
		$printed = 0 === strcasecmp( $spec, 'printed' );
		$colours = array();
		if ( ! $printed ) {
			foreach ( explode( ',', $spec ) as $colour ) {
				if ( preg_match( '/^(.*?)\s*(#[0-9a-f]{6}|#[0-9a-f]{3})$/i', trim( $colour ), $m ) && '' !== trim( $m[1] ) ) {
					$colours[ sanitize_title( $m[1] ) ] = array( 'name' => sanitize_text_field( $m[1] ), 'hex' => strtolower( $m[2] ) );
				}
			}
		}
		$types[ $id ] = array(
			'name'    => $name,
			'printed' => $printed,
			// Fabric-like names get a woven texture in the editor; everything else looks like leather.
			'texture' => preg_match( '/linen|fabric|cloth|canvas|velvet|silk|buckram/i', $name ) ? 'linen' : 'leather',
			'colours' => $colours,
			'price'   => isset( $parts[2] ) ? max( 0, (float) preg_replace( '/[^0-9.]/', '', $parts[2] ) ) : 0,
		);
	}
	return $types;
}

/**
 * Cover types clients can choose from. A printed type has no colours; a bound type has colours.
 */
function sad_cover_materials() {
	$types = sad_parse_cover_types( sad_get_settings()['cover_types'] );
	if ( ! $types ) {
		$types = sad_parse_cover_types( sad_default_cover_text() );
	}

	/**
	 * Filters the cover types and colours clients can choose from.
	 *
	 * @param array $types Type id => array( 'name', 'printed' => bool, 'texture' => 'leather'|'linen', 'colours' => colour id => array( 'name', 'hex' ), 'price' => float ).
	 */
	return apply_filters( 'sad_cover_materials', $types );
}

function sad_default_cover() {
	return array( 'type' => key( sad_cover_materials() ), 'colour' => '', 'items' => array() );
}

function sad_sanitize_cover( $album_id, $cover ) {
	$materials = sad_cover_materials();
	$cover     = is_array( $cover ) ? $cover : array();
	$type      = isset( $cover['type'] ) && is_string( $cover['type'] ) ? $cover['type'] : '';
	if ( ! isset( $materials[ $type ] ) ) {
		// Covers saved before cover types were editable: "photo" was the printed cover.
		$type = key( $materials );
		if ( 'photo' === ( $cover['type'] ?? '' ) ) {
			foreach ( $materials as $id => $material ) {
				if ( $material['printed'] ) {
					$type = $id;
					break;
				}
			}
		}
	}
	$colours = $materials[ $type ]['colours'];
	$colour  = isset( $cover['colour'] ) && is_string( $cover['colour'] ) && isset( $colours[ $cover['colour'] ] ) ? $cover['colour'] : ( $colours ? key( $colours ) : '' );
	return array(
		'type'   => $type,
		'colour' => $colour,
		'items'  => sad_sanitize_items( $album_id, $cover['items'] ?? array() ),
		// The spine and wrap the editor last laid the cover out with.
		'spec'   => array(
			'spine' => round( sad_clamp( $cover['spec']['spine'] ?? 0, 0, 200 ), 2 ),
			'b'     => round( sad_clamp( $cover['spec']['b'] ?? sad_get_settings()['bleed_mm'], 0, 100 ), 2 ),
		),
	);
}

function sad_cover_is_printed( $cover ) {
	$materials = sad_cover_materials();
	return ! empty( $materials[ $cover['type'] ]['printed'] );
}

/**
 * Sets an album's cover type (keeping any photos already on a printed cover).
 */
function sad_set_album_cover_type( $album_id, $type ) {
	$materials = sad_cover_materials();
	if ( ! isset( $materials[ $type ] ) ) {
		return;
	}
	$design = sad_get_design( $album_id );
	if ( $design['cover']['type'] === $type && ( $design['cover']['items'] || ! $materials[ $type ]['printed'] ) ) {
		return;
	}
	$design['cover']['type']   = $type;
	$design['cover']['colour'] = '';
	if ( $materials[ $type ]['printed'] && ! $design['cover']['items'] ) {
		$design['cover']['items'] = array( sad_cover_front_item( $album_id, count( $design['spreads'] ) ) );
	}
	sad_save_design( $album_id, $design );
}

/**
 * The cover choice in words, e.g. "Leather, Espresso".
 */
function sad_cover_label( $cover ) {
	$materials = sad_cover_materials();
	$material  = $materials[ $cover['type'] ] ?? null;
	if ( ! $material ) {
		return '';
	}
	$colour = $material['colours'][ $cover['colour'] ]['name'] ?? '';
	return $material['name'] . ( $colour ? ', ' . $colour : '' );
}

/**
 * An empty photo box covering the front cover, out to the wrap. Matches frontCoverItem() in editor.js.
 */
function sad_cover_front_item( $album_id, $spread_count ) {
	$g = sad_mm_geometry( $album_id, true, $spread_count );
	$x = ( $g['page_w'] + $g['spine'] ) / $g['tw'];
	return sad_new_item( null, $x, -$g['b'] / $g['th'], 1 + $g['b'] / $g['tw'] - $x, 1 + 2 * $g['b'] / $g['th'] );
}
