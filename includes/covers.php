<?php
/**
 * Album cover choices. A cover is shown and designed as one spread: back cover on the left,
 * front cover on the right.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Cover materials. A material with colours is a bound cover (no print file, only the choice);
 * "photo" is a printed cover the client designs with photo boxes like a spread.
 */
function sad_cover_materials() {
	$materials = array(
		'leather' => array(
			'name'    => 'Leather',
			'colours' => array(
				'black'    => array( 'name' => 'Black', 'hex' => '#1c1a19' ),
				'espresso' => array( 'name' => 'Espresso', 'hex' => '#3b2619' ),
				'cognac'   => array( 'name' => 'Cognac', 'hex' => '#8a4f2a' ),
				'tan'      => array( 'name' => 'Tan', 'hex' => '#b0835a' ),
				'navy'     => array( 'name' => 'Navy', 'hex' => '#1f2a3d' ),
				'burgundy' => array( 'name' => 'Burgundy', 'hex' => '#5a1d25' ),
				'ivory'    => array( 'name' => 'Ivory', 'hex' => '#e9e1d2' ),
			),
		),
		'linen'   => array(
			'name'    => 'Linen',
			'colours' => array(
				'natural'  => array( 'name' => 'Natural', 'hex' => '#cfc2ab' ),
				'stone'    => array( 'name' => 'Stone', 'hex' => '#9c968b' ),
				'sage'     => array( 'name' => 'Sage', 'hex' => '#8e9a83' ),
				'dusty-blue' => array( 'name' => 'Dusty blue', 'hex' => '#7f91a3' ),
				'charcoal' => array( 'name' => 'Charcoal', 'hex' => '#3e3d3b' ),
			),
		),
		'photo'   => array(
			'name'    => 'Printed photo cover',
			'colours' => array(),
		),
	);

	/**
	 * Filters the cover materials and colours clients can choose from.
	 *
	 * @param array $materials Material id => array( 'name', 'colours' => colour id => array( 'name', 'hex' ) ).
	 */
	return apply_filters( 'sad_cover_materials', $materials );
}

function sad_default_cover() {
	return array( 'type' => 'leather', 'colour' => 'espresso', 'items' => array() );
}

function sad_sanitize_cover( $album_id, $cover ) {
	$materials = sad_cover_materials();
	$default   = sad_default_cover();
	$cover     = is_array( $cover ) ? $cover : array();
	$type      = isset( $cover['type'] ) && is_string( $cover['type'] ) && isset( $materials[ $cover['type'] ] ) ? $cover['type'] : key( $materials );
	$colours   = $materials[ $type ]['colours'];
	$colour    = isset( $cover['colour'] ) && is_string( $cover['colour'] ) && isset( $colours[ $cover['colour'] ] ) ? $cover['colour'] : ( $colours ? key( $colours ) : '' );
	if ( ! isset( $cover['type'] ) && isset( $materials[ $default['type'] ] ) ) {
		$type   = $default['type'];
		$colour = $default['colour'];
	}
	return array(
		'type'   => $type,
		'colour' => $colour,
		'items'  => sad_sanitize_items( $album_id, $cover['items'] ?? array() ),
	);
}

function sad_cover_is_printed( $cover ) {
	return 'photo' === $cover['type'];
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
