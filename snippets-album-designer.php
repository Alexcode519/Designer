<?php
/**
 * Plugin Name:       Snippets Album Designer
 * Description:       Lets clients upload their photos and design their own album spreads online. Once a client approves, you download print-ready spreads (JPEG + PDF, with bleed) for your printer.
 * Version:           0.4.1
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Snippets Photography and Design
 * License:           GPL-2.0-or-later
 * Text Domain:       snippets-album-designer
 */

defined( 'ABSPATH' ) || exit;

define( 'SAD_VERSION', '0.4.1' );
define( 'SAD_FILE', __FILE__ );
define( 'SAD_DIR', plugin_dir_path( __FILE__ ) );
define( 'SAD_URL', plugin_dir_url( __FILE__ ) );

require_once SAD_DIR . 'includes/settings.php';
require_once SAD_DIR . 'includes/layouts.php';
require_once SAD_DIR . 'includes/storage.php';
require_once SAD_DIR . 'includes/covers.php';
require_once SAD_DIR . 'includes/albums.php';
require_once SAD_DIR . 'includes/rest.php';
require_once SAD_DIR . 'includes/shortcode.php';
require_once SAD_DIR . 'includes/start.php';
require_once SAD_DIR . 'includes/render.php';
require_once SAD_DIR . 'includes/pdf.php';
require_once SAD_DIR . 'includes/admin.php';

register_activation_hook( __FILE__, 'sad_activate' );

/**
 * Registers the album post type, creates the designer page and the protected upload folder.
 */
function sad_activate() {
	sad_register_post_type();
	sad_storage_base_dir();

	$page_id = (int) get_option( 'sad_designer_page_id' );
	if ( ! $page_id || ! get_post( $page_id ) ) {
		// Keep the new page out of menus that are set to add new pages automatically.
		remove_action( 'transition_post_status', '_wp_auto_add_pages_to_menu', 10 );
		$page_id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'Album Designer',
				'post_name'    => 'album-designer',
				'post_content' => '[snippets_album_designer]',
			)
		);
		if ( $page_id && ! is_wp_error( $page_id ) ) {
			update_option( 'sad_designer_page_id', $page_id );
		}
	}
}
