<?php
/**
 * Admin screens: album details, client link, unlock, and print-file export.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'add_meta_boxes_sad_album', 'sad_add_meta_boxes' );
add_action( 'save_post_sad_album', 'sad_save_album_meta', 10, 2 );
add_action( 'admin_enqueue_scripts', 'sad_admin_assets' );
add_action( 'admin_post_sad_download', 'sad_handle_download' );
add_action( 'admin_post_sad_unlock', 'sad_handle_unlock' );
add_filter( 'manage_sad_album_posts_columns', 'sad_album_columns' );
add_action( 'manage_sad_album_posts_custom_column', 'sad_album_column_content', 10, 2 );
add_filter( 'enter_title_here', 'sad_title_placeholder', 10, 2 );

function sad_title_placeholder( $text, $post ) {
	return 'sad_album' === $post->post_type ? 'Album name, e.g. Anna & Ben wedding album' : $text;
}

function sad_add_meta_boxes() {
	add_meta_box( 'sad_album_details', 'Client and album', 'sad_render_details_box', 'sad_album', 'normal', 'high' );
	add_meta_box( 'sad_album_status', 'Status and print files', 'sad_render_status_box', 'sad_album', 'side', 'high' );
}

function sad_render_details_box( $post ) {
	wp_nonce_field( 'sad_save_album', 'sad_album_nonce' );
	$size_id = get_post_meta( $post->ID, '_sad_size', true );
	$config  = sad_album_config( $post->ID );
	?>
	<table class="form-table" role="presentation">
		<tr>
			<th><label for="sad_client_name">Client name</label></th>
			<td><input id="sad_client_name" name="sad_client_name" class="regular-text" value="<?php echo esc_attr( get_post_meta( $post->ID, '_sad_client_name', true ) ); ?>"></td>
		</tr>
		<tr>
			<th><label for="sad_client_email">Client email</label></th>
			<td><input id="sad_client_email" name="sad_client_email" type="email" class="regular-text" value="<?php echo esc_attr( get_post_meta( $post->ID, '_sad_client_email', true ) ); ?>">
			<p class="description">They get a confirmation email when they approve.</p></td>
		</tr>
		<tr>
			<th><label for="sad_size">Album size</label></th>
			<td>
				<select id="sad_size" name="sad_size">
					<?php foreach ( sad_get_sizes() as $size ) : ?>
						<option value="<?php echo esc_attr( $size['id'] ); ?>" <?php selected( $size_id ? $size_id : $config['size']['id'], $size['id'] ); ?>><?php echo esc_html( $size['name'] ); ?></option>
					<?php endforeach; ?>
				</select>
				<p class="description">Add or change sizes under Client albums &gt; Settings.</p>
			</td>
		</tr>
		<tr>
			<th>Number of spreads</th>
			<td>
				<label>Minimum <input name="sad_min_spreads" type="number" min="1" max="100" class="small-text" value="<?php echo esc_attr( $config['min_spreads'] ); ?>"></label>
				&nbsp;
				<label>Maximum <input name="sad_max_spreads" type="number" min="1" max="100" class="small-text" value="<?php echo esc_attr( $config['max_spreads'] ); ?>"></label>
			</td>
		</tr>
		<?php if ( 'publish' === $post->post_status ) : ?>
		<tr>
			<th>Client link</th>
			<td>
				<input type="text" readonly class="large-text code" value="<?php echo esc_url( sad_album_link( $post->ID ) ); ?>" onclick="this.select()">
				<p class="description">Send this private link to your client. Anyone with the link can edit the album until it is approved.</p>
			</td>
		</tr>
		<?php else : ?>
		<tr><th>Client link</th><td>Publish the album to get the client link.</td></tr>
		<?php endif; ?>
	</table>
	<?php
}

function sad_render_status_box( $post ) {
	$status = sad_album_status( $post->ID );
	$design = sad_get_design( $post->ID );
	$photos = count( sad_get_photos( $post->ID ) );
	$geo    = sad_spread_geometry( $post->ID );
	?>
	<p><strong>Status:</strong> <?php echo 'approved' === $status ? 'Approved by client' : 'Client is designing'; ?></p>
	<?php if ( 'approved' === $status ) : ?>
		<p>Approved <?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) get_post_meta( $post->ID, '_sad_approved_at', true ) ) ); ?></p>
	<?php endif; ?>
	<p><?php echo esc_html( sprintf( '%d spreads, %d photos uploaded', count( $design['spreads'] ), $photos ) ); ?></p>
	<p class="description"><?php echo esc_html( sprintf( 'Each spread exports at %d x %d px (%d dpi, bleed included).', $geo['canvas_w'], $geo['canvas_h'], $geo['dpi'] ) ); ?></p>

	<?php if ( 'publish' === $post->post_status ) : ?>
		<p><a href="<?php echo esc_url( sad_album_link( $post->ID ) ); ?>" target="_blank" rel="noopener">View the design</a></p>
		<hr>
		<div id="sad-export" data-album="<?php echo (int) $post->ID; ?>" data-spreads="<?php echo count( $design['spreads'] ); ?>">
			<button type="button" class="button button-primary" id="sad-export-btn">Build print files</button>
			<p id="sad-export-progress" class="description"></p>
			<ul id="sad-export-links">
				<?php foreach ( sad_export_links( $post->ID )['links'] as $link ) : ?>
					<li><a href="<?php echo esc_url( $link['url'] ); ?>"><?php echo esc_html( $link['label'] ); ?></a> (<?php echo esc_html( $link['size'] ); ?>)</li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php if ( 'approved' === $status ) : ?>
			<hr>
			<p><a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=sad_unlock&album=' . (int) $post->ID ), 'sad_unlock_' . $post->ID ) ); ?>" onclick="return confirm('Let the client edit this album again? They will need to approve it again.');">Unlock for changes</a></p>
		<?php endif; ?>
	<?php endif; ?>
	<?php
}

function sad_save_album_meta( $post_id, $post ) {
	if ( ! isset( $_POST['sad_album_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['sad_album_nonce'] ), 'sad_save_album' ) ) {
		return;
	}
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	update_post_meta( $post_id, '_sad_client_name', sanitize_text_field( wp_unslash( $_POST['sad_client_name'] ?? '' ) ) );
	update_post_meta( $post_id, '_sad_client_email', sanitize_email( wp_unslash( $_POST['sad_client_email'] ?? '' ) ) );
	update_post_meta( $post_id, '_sad_size', sanitize_title( wp_unslash( $_POST['sad_size'] ?? '' ) ) );

	$min = max( 1, min( 100, (int) ( $_POST['sad_min_spreads'] ?? 10 ) ) );
	$max = max( $min, min( 100, (int) ( $_POST['sad_max_spreads'] ?? 30 ) ) );
	update_post_meta( $post_id, '_sad_min_spreads', $min );
	update_post_meta( $post_id, '_sad_max_spreads', $max );

	sad_album_token( $post_id );
}

function sad_admin_assets( $hook ) {
	$screen = get_current_screen();
	if ( ! $screen || 'sad_album' !== $screen->post_type || 'post.php' !== $hook ) {
		return;
	}
	wp_enqueue_script( 'sad-admin', SAD_URL . 'assets/admin.js', array( 'wp-api-fetch' ), SAD_VERSION, true );
}

function sad_handle_download() {
	$album_id = (int) ( $_GET['album'] ?? 0 );
	check_admin_referer( 'sad_download_' . $album_id );
	if ( ! current_user_can( 'edit_post', $album_id ) || 'sad_album' !== get_post_type( $album_id ) ) {
		wp_die( 'You cannot download this file.', 403 );
	}
	$file = basename( sanitize_file_name( wp_unslash( $_GET['file'] ?? '' ) ) );
	$path = sad_album_dir( $album_id ) . '/export/' . $file;
	if ( ! $file || ! preg_match( '/\.(pdf|zip)$/', $file ) || ! file_exists( $path ) ) {
		wp_die( 'File not found. Build the print files again.', 404 );
	}

	nocache_headers();
	header( 'Content-Type: ' . ( '.pdf' === substr( $file, -4 ) ? 'application/pdf' : 'application/zip' ) );
	header( 'Content-Disposition: attachment; filename="' . $file . '"' );
	header( 'Content-Length: ' . filesize( $path ) );
	while ( ob_get_level() ) {
		ob_end_clean();
	}
	readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	exit;
}

function sad_handle_unlock() {
	$album_id = (int) ( $_GET['album'] ?? 0 );
	check_admin_referer( 'sad_unlock_' . $album_id );
	if ( ! current_user_can( 'edit_post', $album_id ) || 'sad_album' !== get_post_type( $album_id ) ) {
		wp_die( 'You cannot change this album.', 403 );
	}
	update_post_meta( $album_id, '_sad_status', 'open' );
	wp_safe_redirect( admin_url( 'post.php?post=' . $album_id . '&action=edit' ) );
	exit;
}

function sad_album_columns( $columns ) {
	return array(
		'cb'         => $columns['cb'],
		'title'      => 'Album',
		'sad_client' => 'Client',
		'sad_status' => 'Status',
		'sad_size'   => 'Spreads',
		'date'       => $columns['date'],
	);
}

function sad_album_column_content( $column, $post_id ) {
	if ( 'sad_client' === $column ) {
		echo esc_html( get_post_meta( $post_id, '_sad_client_name', true ) );
	} elseif ( 'sad_status' === $column ) {
		echo 'approved' === sad_album_status( $post_id ) ? '<strong>Approved</strong>' : 'Designing';
	} elseif ( 'sad_size' === $column ) {
		echo esc_html( count( sad_get_design( $post_id )['spreads'] ) );
	}
}
