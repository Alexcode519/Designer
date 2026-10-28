<?php
/**
 * Plugin settings: album sizes, bleed, resolution and notification email.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Standard album sizes, used until the sizes are edited in Settings. Sizes are per page (one side of a spread).
 */
function sad_default_sizes() {
	return array(
		array( 'id' => '8x8', 'name' => '8 x 8 in square (20 x 20 cm)', 'width_mm' => 203, 'height_mm' => 203 ),
		array( 'id' => '10x10', 'name' => '10 x 10 in square (25 x 25 cm)', 'width_mm' => 254, 'height_mm' => 254 ),
		array( 'id' => '12x12', 'name' => '12 x 12 in square (30 x 30 cm)', 'width_mm' => 305, 'height_mm' => 305 ),
		array( 'id' => '10x8', 'name' => '10 x 8 in landscape (25 x 20 cm)', 'width_mm' => 254, 'height_mm' => 203 ),
		array( 'id' => '12x8', 'name' => '12 x 8 in landscape (30 x 20 cm)', 'width_mm' => 305, 'height_mm' => 203 ),
		array( 'id' => '8x10', 'name' => '8 x 10 in portrait (20 x 25 cm)', 'width_mm' => 203, 'height_mm' => 254 ),
	);
}

function sad_get_settings() {
	$defaults = array(
		'sizes'        => sad_default_sizes(),
		'bleed_mm'     => 3,
		'safe_mm'      => 10,
		'dpi'          => 300,
		'notify_email' => get_option( 'admin_email' ),
		'min_dpi_warn' => 200,
		'self_start'   => 1,
		'start_code'   => '',
		'min_spreads'  => 10,
		'max_spreads'  => 30,
	);
	$saved = get_option( 'sad_settings', array() );
	return wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults );
}

function sad_get_sizes() {
	$sizes = sad_get_settings()['sizes'];
	return is_array( $sizes ) && $sizes ? $sizes : sad_default_sizes();
}

function sad_get_size( $size_id ) {
	foreach ( sad_get_sizes() as $size ) {
		if ( $size['id'] === $size_id ) {
			return $size;
		}
	}
	$sizes = sad_get_sizes();
	return $sizes[0];
}

/**
 * Parses the sizes textarea: one size per line, "Name | width mm | height mm".
 */
function sad_parse_sizes( $text ) {
	$sizes = array();
	foreach ( preg_split( '/\r\n|\r|\n/', (string) $text ) as $line ) {
		$parts = array_map( 'trim', explode( '|', $line ) );
		if ( count( $parts ) !== 3 ) {
			continue;
		}
		$width  = (float) $parts[1];
		$height = (float) $parts[2];
		if ( '' === $parts[0] || $width <= 0 || $height <= 0 ) {
			continue;
		}
		$sizes[] = array(
			'id'        => sanitize_title( $parts[0] ),
			'name'      => sanitize_text_field( $parts[0] ),
			'width_mm'  => $width,
			'height_mm' => $height,
		);
	}
	return $sizes;
}

function sad_sizes_to_text( $sizes ) {
	$lines = array();
	foreach ( $sizes as $size ) {
		$lines[] = $size['name'] . ' | ' . $size['width_mm'] . ' | ' . $size['height_mm'];
	}
	return implode( "\n", $lines );
}

add_action( 'admin_menu', 'sad_settings_menu', 20 );

function sad_settings_menu() {
	add_submenu_page(
		'edit.php?post_type=sad_album',
		'Album Designer settings',
		'Settings',
		'manage_options',
		'sad-settings',
		'sad_render_settings_page'
	);
}

function sad_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	if ( isset( $_POST['sad_settings_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['sad_settings_nonce'] ), 'sad_save_settings' ) ) {
		$sizes = sad_parse_sizes( wp_unslash( $_POST['sad_sizes'] ?? '' ) );
		update_option(
			'sad_settings',
			array(
				'sizes'        => $sizes ? $sizes : sad_default_sizes(),
				'bleed_mm'     => max( 0, (float) ( $_POST['sad_bleed_mm'] ?? 3 ) ),
				'safe_mm'      => max( 0, (float) ( $_POST['sad_safe_mm'] ?? 10 ) ),
				'dpi'          => min( 600, max( 150, (int) ( $_POST['sad_dpi'] ?? 300 ) ) ),
				'notify_email' => sanitize_email( wp_unslash( $_POST['sad_notify_email'] ?? '' ) ),
				'min_dpi_warn' => max( 72, (int) ( $_POST['sad_min_dpi_warn'] ?? 200 ) ),
				'self_start'   => empty( $_POST['sad_self_start'] ) ? 0 : 1,
				'start_code'   => sanitize_text_field( wp_unslash( $_POST['sad_start_code'] ?? '' ) ),
				'min_spreads'  => max( 1, min( 100, (int) ( $_POST['sad_default_min_spreads'] ?? 10 ) ) ),
				'max_spreads'  => max( 1, min( 100, (int) ( $_POST['sad_default_max_spreads'] ?? 30 ) ) ),
			)
		);
		echo '<div class="notice notice-success"><p>Settings saved.</p></div>';
	}

	$settings = sad_get_settings();
	$page_id  = (int) get_option( 'sad_designer_page_id' );
	?>
	<div class="wrap">
		<h1>Album Designer settings</h1>
		<form method="post">
			<?php wp_nonce_field( 'sad_save_settings', 'sad_settings_nonce' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="sad_sizes">Album sizes</label></th>
					<td>
						<textarea id="sad_sizes" name="sad_sizes" rows="8" class="large-text code"><?php echo esc_textarea( sad_sizes_to_text( $settings['sizes'] ) ); ?></textarea>
						<p class="description">One size per line: <code>Name | page width mm | page height mm</code>. Sizes are for one page; a spread is two pages side by side. Use the trimmed size from your printer, without bleed.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="sad_bleed_mm">Bleed (mm per edge)</label></th>
					<td><input id="sad_bleed_mm" name="sad_bleed_mm" type="number" step="0.5" min="0" value="<?php echo esc_attr( $settings['bleed_mm'] ); ?>" class="small-text"></td>
				</tr>
				<tr>
					<th scope="row"><label for="sad_dpi">Export resolution (dpi)</label></th>
					<td>
						<input id="sad_dpi" name="sad_dpi" type="number" min="150" max="600" value="<?php echo esc_attr( $settings['dpi'] ); ?>" class="small-text">
						<p class="description">300 is standard for album printing. If exports fail on large albums, your hosting may be low on memory; try 250.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="sad_min_dpi_warn">Warn clients below (dpi)</label></th>
					<td><input id="sad_min_dpi_warn" name="sad_min_dpi_warn" type="number" min="72" value="<?php echo esc_attr( $settings['min_dpi_warn'] ); ?>" class="small-text">
					<p class="description">Clients see a low-quality warning when a photo is enlarged past this.</p></td>
				</tr>
				<tr>
					<th scope="row">Clients start their own album</th>
					<td>
						<label><input type="checkbox" name="sad_self_start" value="1" <?php checked( $settings['self_start'] ); ?>> Show a start form on the designer page</label>
						<p class="description">Clients enter their names, email and album size, and get their own private link by email. Turn this off to create every album yourself.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="sad_start_code">Access code (optional)</label></th>
					<td>
						<input id="sad_start_code" name="sad_start_code" type="text" value="<?php echo esc_attr( $settings['start_code'] ); ?>" class="regular-text">
						<p class="description">If set, clients must enter this code to start an album, so only people you give it to can use it. Leave empty to let anyone with the page link start one.</p>
					</td>
				</tr>
				<tr>
					<th scope="row">Spreads for new albums</th>
					<td>
						<label>Minimum <input name="sad_default_min_spreads" type="number" min="1" max="100" class="small-text" value="<?php echo esc_attr( $settings['min_spreads'] ); ?>"></label>
						&nbsp;
						<label>Maximum <input name="sad_default_max_spreads" type="number" min="1" max="100" class="small-text" value="<?php echo esc_attr( $settings['max_spreads'] ); ?>"></label>
						<p class="description">Used for albums clients start themselves. You can change it per album afterwards.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="sad_notify_email">Notification emails go to</label></th>
					<td><input id="sad_notify_email" name="sad_notify_email" type="email" value="<?php echo esc_attr( $settings['notify_email'] ); ?>" class="regular-text"></td>
				</tr>
				<tr>
					<th scope="row">Designer page</th>
					<td>
						<?php if ( $page_id && get_post( $page_id ) ) : ?>
							<a href="<?php echo esc_url( get_edit_post_link( $page_id ) ); ?>"><?php echo esc_html( get_the_title( $page_id ) ); ?></a>
						<?php else : ?>
							Not found. Add the shortcode <code>[snippets_album_designer]</code> to any page.
						<?php endif; ?>
						<p class="description">Client links open this page. You can style it in Elementor; keep the shortcode on it.</p>
					</td>
				</tr>
				<tr>
					<th scope="row">Upload limit on this server</th>
					<td><?php echo esc_html( size_format( wp_max_upload_size() ) ); ?> per request. Photos are sent in smaller pieces, so larger photos still work.</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>
	</div>
	<?php
}
