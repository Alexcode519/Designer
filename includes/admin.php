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
add_filter( 'manage_edit-sad_album_sortable_columns', 'sad_album_sortable_columns' );
add_action( 'pre_get_posts', 'sad_album_sort_query' );
add_filter( 'enter_title_here', 'sad_title_placeholder', 10, 2 );

function sad_title_placeholder( $text, $post ) {
	return 'sad_album' === $post->post_type ? 'Album name, e.g. Anna & Ben wedding album' : $text;
}

function sad_add_meta_boxes() {
	add_meta_box( 'sad_album_details', 'Client and album', 'sad_render_details_box', 'sad_album', 'normal', 'high' );
	add_meta_box( 'sad_album_status', 'Status and print files', 'sad_render_status_box', 'sad_album', 'side', 'high' );
	add_meta_box( 'sad_album_photos', 'Photos', 'sad_render_photos_box', 'sad_album', 'normal', 'default' );
	add_meta_box( 'sad_album_notes', 'Notes on spreads', 'sad_render_notes_box', 'sad_album', 'normal', 'default' );
}

/**
 * Lets the studio load photos into a client's album, e.g. the edited gallery, so the client only designs.
 */
function sad_render_photos_box( $post ) {
	$photos = sad_get_photos( $post->ID );
	if ( 'publish' !== $post->post_status ) {
		echo '<p>Publish the album first, then you can add photos here.</p>';
		return;
	}
	?>
	<div id="sad-photos" data-album="<?php echo (int) $post->ID; ?>" data-chunk="<?php echo (int) min( 2 * MB_IN_BYTES, max( 256 * KB_IN_BYTES, wp_max_upload_size() - 64 * KB_IN_BYTES ) ); ?>">
		<p>Load the client's photos here and they will be waiting in their photo tray. JPEG or PNG, full size. The client can still add their own.</p>
		<p>
			<label class="button button-primary">Add photos<input type="file" id="sad-photos-input" accept="image/jpeg,image/png" multiple style="display:none"></label>
			<span id="sad-photos-progress" class="description" aria-live="polite"></span>
		</p>
		<p class="description"><span id="sad-photos-count"><?php echo esc_html( count( $photos ) ); ?></span> photos in this album.</p>
		<div id="sad-photos-grid" style="display:flex;flex-wrap:wrap;gap:4px;max-height:260px;overflow:auto">
			<?php foreach ( $photos as $photo ) : ?>
				<img src="<?php echo esc_url( sad_preview_url( $post->ID, $photo ) ); ?>" alt="<?php echo esc_attr( $photo['name'] ); ?>" title="<?php echo esc_attr( $photo['name'] ); ?>" style="height:64px;width:auto;border-radius:2px" loading="lazy">
			<?php endforeach; ?>
		</div>
	</div>
	<?php
}

/**
 * Proofing notes from the client and the studio, newest first, with a form to add one.
 */
function sad_render_notes_box( $post ) {
	if ( 'publish' !== $post->post_status ) {
		echo '<p>Publish the album first.</p>';
		return;
	}
	$design   = sad_get_design( $post->ID );
	$comments = array_reverse( sad_get_comments( $post->ID ) );
	$client   = get_post_meta( $post->ID, '_sad_client_name', true );
	?>
	<div id="sad-notes" data-album="<?php echo (int) $post->ID; ?>">
		<?php if ( ! $comments ) : ?>
			<p>No notes yet. Notes you add here show under the spread in the client's editor, and the client gets an email.</p>
		<?php endif; ?>
		<?php foreach ( $comments as $comment ) : ?>
			<div style="border-left:3px solid <?php echo 'studio' === $comment['author'] ? '#a8875a' : '#2271b1'; ?>;padding:4px 10px;margin:0 0 10px;<?php echo $comment['resolved'] ? 'opacity:.55' : ''; ?>">
				<p style="margin:0 0 2px">
					<strong><?php echo esc_html( 'studio' === $comment['author'] ? 'You' : ( $client ? $client : 'Client' ) ); ?></strong>
					on <?php echo esc_html( sad_spread_label( $comment['spread'], $design ) ); ?>
					<span class="description">&middot; <?php echo esc_html( sprintf( '%s ago', human_time_diff( $comment['time'] ) ) ); ?></span>
					<?php if ( $comment['resolved'] ) : ?><span class="description">&middot; done</span><?php endif; ?>
				</p>
				<p style="margin:0 0 4px;white-space:pre-wrap"><?php echo esc_html( $comment['text'] ); ?></p>
				<button type="button" class="button-link sad-note-resolve" data-comment="<?php echo esc_attr( $comment['id'] ); ?>" data-resolved="<?php echo $comment['resolved'] ? '0' : '1'; ?>"><?php echo $comment['resolved'] ? 'Reopen' : 'Mark as done'; ?></button>
			</div>
		<?php endforeach; ?>
		<hr>
		<p>
			<label for="sad-note-spread">Add a note on</label>
			<select id="sad-note-spread">
				<option value="cover">Cover</option>
				<?php foreach ( $design['spreads'] as $n => $spread ) : ?>
					<option value="<?php echo esc_attr( $spread['id'] ); ?>"><?php echo esc_html( sprintf( 'Spread %d', $n + 1 ) ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<textarea id="sad-note-text" rows="3" class="large-text" placeholder="e.g. Could we swap this photo for the one by the lake?"></textarea>
		<p><button type="button" class="button" id="sad-note-add">Add note</button> <span id="sad-note-status" class="description"></span></p>
	</div>
	<?php
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
			<th><label for="sad_cover">Cover</label></th>
			<td>
				<select id="sad_cover" name="sad_cover">
					<?php $cover = sad_get_design( $post->ID )['cover']; ?>
					<?php foreach ( sad_cover_materials() as $cover_id => $material ) : ?>
						<option value="<?php echo esc_attr( $cover_id ); ?>" <?php selected( $cover['type'], $cover_id ); ?>><?php echo esc_html( $material['name'] ); ?></option>
					<?php endforeach; ?>
				</select>
				<p class="description">The client can change this in the editor. Add or change cover types under Client albums &gt; Settings.</p>
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
	<p><strong>Cover:</strong> <?php echo esc_html( sad_cover_label( $design['cover'] ) ); ?><?php echo sad_cover_is_printed( $design['cover'] ) ? '' : ' <span class="description">(no print file; tell your printer this choice)</span>'; ?></p>
	<?php $extras = sad_album_extras( $post->ID, $design ); ?>
	<?php if ( $extras['lines'] ) : ?>
		<p><strong>Extras:</strong>
		<?php foreach ( $extras['lines'] as $line ) : ?>
			<br><?php echo esc_html( $line['label'] . ': ' . sad_money( $line['amount'] ) ); ?>
		<?php endforeach; ?>
		<br><strong><?php echo esc_html( 'Total extras: ' . sad_money( $extras['total'] ) ); ?></strong></p>
	<?php endif; ?>
	<?php $open_notes = count( wp_list_filter( sad_get_comments( $post->ID ), array( 'resolved' => false ) ) ); ?>
	<?php if ( $open_notes ) : ?>
		<p><a href="#sad_album_notes"><?php echo esc_html( sprintf( '%d open note%s', $open_notes, 1 === $open_notes ? '' : 's' ) ); ?></a></p>
	<?php endif; ?>
	<p class="description"><?php echo esc_html( sprintf( 'Each spread exports at %d x %d px (%d dpi, bleed included).', $geo['canvas_w'], $geo['canvas_h'], $geo['dpi'] ) ); ?>
	<?php if ( sad_cover_is_printed( $design['cover'] ) ) : ?>
		<?php $cover_geo = sad_spread_geometry( $post->ID, 'cover' ); ?>
		<?php echo esc_html( sprintf( 'The cover is %s x %s mm with wrap%s.', round( $cover_geo['width_mm'], 1 ), round( $cover_geo['height_mm'], 1 ), $cover_geo['spine_mm'] ? sprintf( ' and a %s mm spine', $cover_geo['spine_mm'] ) : '' ) ); ?>
	<?php endif; ?></p>

	<?php if ( 'publish' === $post->post_status ) : ?>
		<p><a href="<?php echo esc_url( sad_album_link( $post->ID ) ); ?>" target="_blank" rel="noopener">View the design</a></p>
		<hr>
		<div id="sad-export" data-album="<?php echo (int) $post->ID; ?>" data-spreads="<?php echo count( $design['spreads'] ); ?>" data-cover="<?php echo sad_cover_is_printed( $design['cover'] ) ? '1' : '0'; ?>">
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

	$settings = sad_get_settings();
	$min      = max( 1, min( 100, (int) ( $_POST['sad_min_spreads'] ?? $settings['min_spreads'] ) ) );
	$max      = max( $min, min( 100, (int) ( $_POST['sad_max_spreads'] ?? $settings['max_spreads'] ) ) );
	update_post_meta( $post_id, '_sad_min_spreads', $min );
	update_post_meta( $post_id, '_sad_max_spreads', $max );

	sad_album_token( $post_id );
	if ( ! get_post_meta( $post_id, '_sad_updated_at', true ) ) {
		update_post_meta( $post_id, '_sad_updated_at', time() );
	}
	if ( isset( $_POST['sad_cover'] ) && 'approved' !== sad_album_status( $post_id ) ) {
		sad_set_album_cover_type( $post_id, sanitize_title( wp_unslash( $_POST['sad_cover'] ) ) );
	}
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
		'cb'          => $columns['cb'],
		'title'       => 'Album',
		'sad_client'  => 'Client',
		'sad_status'  => 'Status',
		'sad_album'   => 'Size',
		'sad_cover'   => 'Cover',
		'sad_photos'  => 'Photos',
		'sad_spreads' => 'Spreads',
		'sad_updated' => 'Last change',
		'date'        => 'Started',
	);
}

function sad_album_sortable_columns( $columns ) {
	$columns['sad_updated'] = 'sad_updated';
	return $columns;
}

function sad_album_sort_query( $query ) {
	if ( is_admin() && $query->is_main_query() && 'sad_album' === $query->get( 'post_type' ) && 'sad_updated' === $query->get( 'orderby' ) ) {
		$query->set( 'meta_key', '_sad_updated_at' );
		$query->set( 'orderby', 'meta_value_num' );
	}
}

function sad_album_column_content( $column, $post_id ) {
	if ( 'sad_client' === $column ) {
		$email = get_post_meta( $post_id, '_sad_client_email', true );
		echo esc_html( get_post_meta( $post_id, '_sad_client_name', true ) );
		if ( $email ) {
			echo '<br><a href="mailto:' . esc_attr( $email ) . '">' . esc_html( $email ) . '</a>';
		}
	} elseif ( 'sad_status' === $column ) {
		echo 'approved' === sad_album_status( $post_id ) ? '<strong>Approved</strong>' : 'Designing';
		$open_notes = count( wp_list_filter( sad_get_comments( $post_id ), array( 'resolved' => false ) ) );
		if ( $open_notes ) {
			echo '<br>' . esc_html( sprintf( '%d open note%s', $open_notes, 1 === $open_notes ? '' : 's' ) );
		}
	} elseif ( 'sad_album' === $column ) {
		echo esc_html( sad_album_config( $post_id )['size']['name'] );
	} elseif ( 'sad_cover' === $column ) {
		echo esc_html( sad_cover_label( sad_get_design( $post_id )['cover'] ) );
	} elseif ( 'sad_photos' === $column ) {
		echo esc_html( count( sad_get_photos( $post_id ) ) );
	} elseif ( 'sad_spreads' === $column ) {
		echo esc_html( count( sad_get_design( $post_id )['spreads'] ) );
	} elseif ( 'sad_updated' === $column ) {
		$updated = (int) get_post_meta( $post_id, '_sad_updated_at', true );
		echo $updated ? esc_html( sprintf( '%s ago', human_time_diff( $updated ) ) ) : '&mdash;';
	}
}
