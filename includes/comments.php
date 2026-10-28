<?php
/**
 * Notes on spreads, for proofing: the client and the studio can each leave notes on the cover or
 * any spread, and tick them off when done. Notes are tied to the spread's id, so they follow the
 * spread when it is moved.
 */

defined( 'ABSPATH' ) || exit;

const SAD_NOTE_EMAIL_GAP = 15 * MINUTE_IN_SECONDS;

/**
 * @return array[] Each: id, spread ("cover" or a spread id), author ("client" or "studio"), text, time, resolved.
 */
function sad_get_comments( $album_id ) {
	$comments = json_decode( (string) get_post_meta( $album_id, '_sad_comments', true ), true );
	return is_array( $comments ) ? $comments : array();
}

function sad_save_comments( $album_id, $comments ) {
	update_post_meta( $album_id, '_sad_comments', wp_slash( wp_json_encode( array_values( $comments ) ) ) );
}

/**
 * "Cover", "Spread 3", or "A deleted spread" for a note's spread key.
 */
function sad_spread_label( $key, $design ) {
	if ( 'cover' === $key ) {
		return 'Cover';
	}
	foreach ( $design['spreads'] as $n => $spread ) {
		if ( $spread['id'] === $key ) {
			return sprintf( 'Spread %d', $n + 1 );
		}
	}
	return 'A deleted spread';
}

/**
 * @return array|WP_Error The new note.
 */
function sad_add_comment( $album_id, $spread, $author, $text ) {
	$text   = trim( sanitize_textarea_field( (string) $text ) );
	$design = sad_get_design( $album_id );
	if ( '' === $text ) {
		return new WP_Error( 'sad_empty', 'Write a note first.', array( 'status' => 400 ) );
	}
	if ( 'cover' !== $spread && ! in_array( $spread, wp_list_pluck( $design['spreads'], 'id' ), true ) ) {
		return new WP_Error( 'sad_not_found', 'That spread no longer exists. Reload the page and try again.', array( 'status' => 404 ) );
	}
	$comments = sad_get_comments( $album_id );
	if ( count( $comments ) >= 500 ) {
		return new WP_Error( 'sad_full', 'This album has too many notes. Please tick some off first.', array( 'status' => 400 ) );
	}
	$comment    = array(
		'id'       => strtolower( wp_generate_password( 10, false, false ) ),
		'spread'   => $spread,
		'author'   => 'studio' === $author ? 'studio' : 'client',
		'text'     => substr( $text, 0, 2000 ),
		'time'     => time(),
		'resolved' => false,
	);
	$comments[] = $comment;
	sad_save_comments( $album_id, $comments );
	sad_notify_comment( $album_id, $comment, $design );
	return $comment;
}

function sad_set_comment_resolved( $album_id, $comment_id, $resolved ) {
	$comments = sad_get_comments( $album_id );
	foreach ( $comments as $i => $comment ) {
		if ( $comment['id'] === $comment_id ) {
			$comments[ $i ]['resolved'] = (bool) $resolved;
			sad_save_comments( $album_id, $comments );
			return true;
		}
	}
	return false;
}

/**
 * Emails the other side about a new note, at most once every 15 minutes per album so a burst of
 * notes sends one email.
 */
function sad_notify_comment( $album_id, $comment, $design ) {
	$key = 'sad_note_mail_' . $comment['author'] . '_' . $album_id;
	if ( get_transient( $key ) ) {
		return;
	}
	set_transient( $key, 1, SAD_NOTE_EMAIL_GAP );

	$where  = sad_spread_label( $comment['spread'], $design );
	$client = get_post_meta( $album_id, '_sad_client_name', true );
	if ( 'client' === $comment['author'] ) {
		$settings = sad_get_settings();
		wp_mail(
			$settings['notify_email'] ? $settings['notify_email'] : get_option( 'admin_email' ),
			sprintf( 'New note on %s', sad_album_title( $album_id ) ),
			sprintf(
				"%s left a note on %s:\n\n%s\n\nReply or tick it off here:\n%s\n",
				$client ? $client : 'Your client',
				strtolower( $where ),
				$comment['text'],
				admin_url( 'post.php?post=' . (int) $album_id . '&action=edit' )
			)
		);
		return;
	}
	$email = get_post_meta( $album_id, '_sad_client_email', true );
	if ( $email && is_email( $email ) ) {
		wp_mail(
			$email,
			sprintf( 'A note on your album "%s"', sad_album_title( $album_id ) ),
			sprintf(
				"Hi%s,\n\nWe left a note on %s of your album:\n\n%s\n\nOpen your album to see it (notes are under the spread):\n%s\n\n%s\n",
				$client ? ' ' . $client : '',
				strtolower( $where ),
				$comment['text'],
				sad_album_link( $album_id ),
				get_bloginfo( 'name' )
			)
		);
	}
}
