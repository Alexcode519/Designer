<?php
/**
 * File storage for client photos and exports.
 *
 * Layout under wp-content/uploads/snippets-albums/album-{id}-{key}/:
 *   originals/  full-size photos as uploaded (blocked from direct web access)
 *   previews/   small JPEGs used by the editor
 *   chunks/     partial uploads while a photo is being sent
 *   export/     print-ready files (blocked from direct web access)
 */

defined( 'ABSPATH' ) || exit;

const SAD_MAX_PHOTO_BYTES   = 80 * 1024 * 1024;
const SAD_MAX_PHOTOS        = 500;
const SAD_PREVIEW_LONG_EDGE = 1600;

function sad_storage_base_dir() {
	$uploads = wp_upload_dir( null, false );
	$dir     = trailingslashit( $uploads['basedir'] ) . 'snippets-albums';
	if ( ! is_dir( $dir ) ) {
		wp_mkdir_p( $dir );
	}
	sad_protect_dir( $dir, false );
	return $dir;
}

/**
 * Stops directory listing, and when $deny is true blocks direct downloads on Apache servers.
 */
function sad_protect_dir( $dir, $deny ) {
	if ( ! file_exists( $dir . '/index.php' ) ) {
		file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" );
	}
	if ( $deny && ! file_exists( $dir . '/.htaccess' ) ) {
		file_put_contents( $dir . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" );
	}
}

function sad_album_dir( $album_id ) {
	$key = get_post_meta( $album_id, '_sad_folder_key', true );
	if ( ! $key ) {
		$key = wp_generate_password( 12, false, false );
		update_post_meta( $album_id, '_sad_folder_key', $key );
	}
	return sad_storage_base_dir() . '/album-' . (int) $album_id . '-' . strtolower( $key );
}

function sad_album_subdir( $album_id, $sub ) {
	$dir = sad_album_dir( $album_id ) . '/' . $sub;
	if ( ! is_dir( $dir ) ) {
		wp_mkdir_p( $dir );
	}
	sad_protect_dir( sad_album_dir( $album_id ), false );
	sad_protect_dir( $dir, 'previews' !== $sub );
	return $dir;
}

function sad_preview_url( $album_id, $photo ) {
	$uploads = wp_upload_dir( null, false );
	$base    = trailingslashit( $uploads['basedir'] );
	$path    = sad_album_dir( $album_id ) . '/previews/' . $photo['preview'];
	return trailingslashit( $uploads['baseurl'] ) . ltrim( substr( $path, strlen( $base ) ), '/' );
}

function sad_original_path( $album_id, $photo ) {
	return sad_album_dir( $album_id ) . '/originals/' . $photo['file'];
}

/**
 * Photos are stored one meta row each, so parallel uploads cannot overwrite each other.
 */
function sad_get_photos( $album_id ) {
	$photos = array();
	foreach ( get_post_meta( $album_id, '_sad_photo' ) as $photo ) {
		if ( is_array( $photo ) && isset( $photo['id'] ) ) {
			$photos[ $photo['id'] ] = $photo;
		}
	}
	return $photos;
}

function sad_photo_for_client( $album_id, $photo ) {
	return array(
		'id'      => $photo['id'],
		'name'    => $photo['name'],
		'width'   => (int) $photo['width'],
		'height'  => (int) $photo['height'],
		'preview' => sad_preview_url( $album_id, $photo ),
	);
}

/**
 * Appends one piece of an upload. When the last piece arrives the photo is checked,
 * straightened, given a preview and added to the album.
 *
 * @return array|WP_Error|null The new photo when finished, null while more pieces are expected.
 */
function sad_receive_chunk( $album_id, $upload_id, $index, $total, $filename, $tmp_path ) {
	if ( ! preg_match( '/^[a-z0-9]{8,40}$/', $upload_id ) ) {
		return new WP_Error( 'sad_bad_upload', 'Invalid upload id.', array( 'status' => 400 ) );
	}
	if ( $index < 0 || $total < 1 || $index >= $total ) {
		return new WP_Error( 'sad_bad_upload', 'Invalid upload piece.', array( 'status' => 400 ) );
	}
	if ( 0 === $index && count( sad_get_photos( $album_id ) ) >= SAD_MAX_PHOTOS ) {
		return new WP_Error( 'sad_too_many', 'This album already has the maximum number of photos.', array( 'status' => 400 ) );
	}

	$part = sad_album_subdir( $album_id, 'chunks' ) . '/' . $upload_id . '.part';
	if ( 0 === $index && file_exists( $part ) ) {
		unlink( $part );
	}
	if ( $index > 0 && ( ! file_exists( $part ) || (int) get_transient( 'sad_chunk_' . $upload_id ) !== $index ) ) {
		return new WP_Error( 'sad_bad_upload', 'Upload pieces arrived out of order. Please try again.', array( 'status' => 409 ) );
	}

	$data = file_get_contents( $tmp_path );
	if ( false === $data ) {
		return new WP_Error( 'sad_bad_upload', 'Could not read the upload.', array( 'status' => 400 ) );
	}
	file_put_contents( $part, $data, FILE_APPEND );
	clearstatcache( true, $part );
	if ( filesize( $part ) > SAD_MAX_PHOTO_BYTES ) {
		unlink( $part );
		delete_transient( 'sad_chunk_' . $upload_id );
		return new WP_Error( 'sad_too_big', 'This photo is too large.', array( 'status' => 413 ) );
	}

	if ( $index < $total - 1 ) {
		set_transient( 'sad_chunk_' . $upload_id, $index + 1, HOUR_IN_SECONDS );
		return null;
	}
	delete_transient( 'sad_chunk_' . $upload_id );

	$photo = sad_store_photo( $album_id, $part, $filename );
	if ( file_exists( $part ) ) {
		unlink( $part );
	}
	return $photo;
}

function sad_store_photo( $album_id, $source, $filename ) {
	$info = @getimagesize( $source ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	if ( ! $info || ! in_array( $info[2], array( IMAGETYPE_JPEG, IMAGETYPE_PNG ), true ) ) {
		return new WP_Error( 'sad_bad_type', 'Only JPEG and PNG photos can be used.', array( 'status' => 415 ) );
	}

	$id       = strtolower( wp_generate_password( 16, false, false ) );
	$ext      = IMAGETYPE_PNG === $info[2] ? 'png' : 'jpg';
	$original = sad_album_subdir( $album_id, 'originals' ) . '/' . $id . '.' . $ext;
	if ( ! rename( $source, $original ) ) {
		return new WP_Error( 'sad_store_failed', 'Could not save the photo.', array( 'status' => 500 ) );
	}

	$width  = (int) $info[0];
	$height = (int) $info[1];

	// Phone and camera photos are often stored sideways with an EXIF rotation flag. Bake it in once,
	// so the editor preview and the print export agree.
	if ( 'jpg' === $ext && function_exists( 'exif_read_data' ) ) {
		$exif = @exif_read_data( $original ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! empty( $exif['Orientation'] ) && 1 !== (int) $exif['Orientation'] ) {
			$editor = wp_get_image_editor( $original );
			if ( ! is_wp_error( $editor ) && $editor->maybe_exif_rotate() ) {
				$editor->set_quality( 95 );
				$saved = $editor->save( $original, 'image/jpeg' );
				if ( ! is_wp_error( $saved ) ) {
					$width  = (int) $saved['width'];
					$height = (int) $saved['height'];
				}
			}
		}
	}

	$editor = wp_get_image_editor( $original );
	if ( is_wp_error( $editor ) ) {
		unlink( $original );
		return new WP_Error( 'sad_bad_image', 'This photo could not be opened.', array( 'status' => 415 ) );
	}
	$editor->resize( SAD_PREVIEW_LONG_EDGE, SAD_PREVIEW_LONG_EDGE, false );
	$editor->set_quality( 82 );
	$preview = $id . '.jpg';
	$saved   = $editor->save( sad_album_subdir( $album_id, 'previews' ) . '/' . $preview, 'image/jpeg' );
	if ( is_wp_error( $saved ) ) {
		unlink( $original );
		return new WP_Error( 'sad_preview_failed', 'Could not prepare this photo.', array( 'status' => 500 ) );
	}

	$photo = array(
		'id'      => $id,
		'file'    => basename( $original ),
		'preview' => $preview,
		'width'   => $width,
		'height'  => $height,
		'name'    => sanitize_file_name( $filename ),
		'added'   => time(),
	);
	add_post_meta( $album_id, '_sad_photo', $photo );
	return $photo;
}

function sad_delete_photo( $album_id, $photo_id ) {
	$photos = sad_get_photos( $album_id );
	if ( ! isset( $photos[ $photo_id ] ) ) {
		return false;
	}
	$photo = $photos[ $photo_id ];
	delete_post_meta( $album_id, '_sad_photo', $photo );
	foreach ( array( sad_original_path( $album_id, $photo ), sad_album_dir( $album_id ) . '/previews/' . $photo['preview'] ) as $path ) {
		if ( file_exists( $path ) ) {
			unlink( $path );
		}
	}
	return true;
}

function sad_delete_album_files( $album_id ) {
	$dir = sad_album_dir( $album_id );
	if ( is_dir( $dir ) ) {
		sad_rmdir( $dir );
	}
}

function sad_rmdir( $dir ) {
	foreach ( scandir( $dir ) as $item ) {
		if ( '.' === $item || '..' === $item ) {
			continue;
		}
		$path = $dir . '/' . $item;
		is_dir( $path ) ? sad_rmdir( $path ) : unlink( $path );
	}
	rmdir( $dir );
}
