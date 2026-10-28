<?php
/**
 * Spread layouts. Each slot is [x, y, width, height] as a fraction of the trimmed spread
 * (both pages side by side). Slots touching an edge are extended into the bleed when exported.
 */

defined( 'ABSPATH' ) || exit;

function sad_layouts() {
	$layouts = array(
		'full'              => array(
			'name'  => 'One photo across both pages',
			'slots' => array( array( 0, 0, 1, 1 ) ),
		),
		'two-full'          => array(
			'name'  => 'One full photo per page',
			'slots' => array( array( 0, 0, 0.5, 1 ), array( 0.5, 0, 0.5, 1 ) ),
		),
		'two-framed'        => array(
			'name'  => 'One framed photo per page',
			'slots' => array( array( 0.03, 0.06, 0.44, 0.88 ), array( 0.53, 0.06, 0.44, 0.88 ) ),
		),
		'left-full-right-framed' => array(
			'name'  => 'Full left, framed right',
			'slots' => array( array( 0, 0, 0.5, 1 ), array( 0.53, 0.06, 0.44, 0.88 ) ),
		),
		'left-framed-right-full' => array(
			'name'  => 'Framed left, full right',
			'slots' => array( array( 0.03, 0.06, 0.44, 0.88 ), array( 0.5, 0, 0.5, 1 ) ),
		),
		'left-full-right-two' => array(
			'name'  => 'Full left, two right',
			'slots' => array( array( 0, 0, 0.5, 1 ), array( 0.53, 0.06, 0.44, 0.425 ), array( 0.53, 0.515, 0.44, 0.425 ) ),
		),
		'left-two-right-full' => array(
			'name'  => 'Two left, full right',
			'slots' => array( array( 0.03, 0.06, 0.44, 0.425 ), array( 0.03, 0.515, 0.44, 0.425 ), array( 0.5, 0, 0.5, 1 ) ),
		),
		'two-and-two'       => array(
			'name'  => 'Two photos per page',
			'slots' => array(
				array( 0.03, 0.06, 0.44, 0.425 ),
				array( 0.03, 0.515, 0.44, 0.425 ),
				array( 0.53, 0.06, 0.44, 0.425 ),
				array( 0.53, 0.515, 0.44, 0.425 ),
			),
		),
		'left-full-right-four' => array(
			'name'  => 'Full left, grid of four right',
			'slots' => array(
				array( 0, 0, 0.5, 1 ),
				array( 0.53, 0.06, 0.215, 0.425 ),
				array( 0.755, 0.06, 0.215, 0.425 ),
				array( 0.53, 0.515, 0.215, 0.425 ),
				array( 0.755, 0.515, 0.215, 0.425 ),
			),
		),
		'right-only'        => array(
			'name'  => 'Blank left, framed right',
			'slots' => array( array( 0.53, 0.06, 0.44, 0.88 ) ),
		),
	);

	/**
	 * Filters the available spread layouts.
	 *
	 * @param array $layouts Layout id => array( 'name' => string, 'slots' => array of [x, y, w, h] fractions ).
	 */
	return apply_filters( 'sad_layouts', $layouts );
}

function sad_layout_slots( $layout_id ) {
	$layouts = sad_layouts();
	return isset( $layouts[ $layout_id ] ) ? $layouts[ $layout_id ]['slots'] : $layouts['two-full']['slots'];
}

/**
 * Auto-fill templates. Each lists the layouts it prefers, in the order they repeat through the album.
 * Auto-fill picks from this list the layout whose number of spaces matches the photos for each spread,
 * falling back to any layout with that many spaces.
 */
function sad_templates() {
	$templates = array(
		'classic' => array(
			'name'        => 'Classic',
			'description' => 'Framed photos with white borders, calm and timeless.',
			'layouts'     => array( 'two-framed', 'left-full-right-framed', 'right-only', 'two-and-two', 'left-framed-right-full', 'left-full-right-two' ),
		),
		'bold'    => array(
			'name'        => 'Bold',
			'description' => 'Big edge-to-edge photos, fewer per page.',
			'layouts'     => array( 'full', 'two-full', 'left-full-right-two', 'two-full', 'left-two-right-full', 'full' ),
		),
		'story'   => array(
			'name'        => 'Story',
			'description' => 'More photos per spread to tell the whole day.',
			'layouts'     => array( 'left-full-right-four', 'two-and-two', 'left-full-right-two', 'left-two-right-full', 'two-and-two', 'two-full' ),
		),
	);

	/**
	 * Filters the auto-fill templates.
	 *
	 * @param array $templates Template id => array( 'name', 'description', 'layouts' => layout ids ).
	 */
	return apply_filters( 'sad_templates', $templates );
}
