<?php
/**
 * Friendly reminder emails for clients who started an album but have not changed it for a while.
 * Checked once a day by WP-Cron. Saving the design resets the count (see sad_save_design()).
 */

defined( 'ABSPATH' ) || exit;

add_action( 'init', 'sad_schedule_reminders' );
add_action( 'sad_daily', 'sad_send_reminders' );

function sad_schedule_reminders() {
	if ( ! wp_next_scheduled( 'sad_daily' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'sad_daily' );
	}
}

function sad_unschedule_reminders() {
	wp_clear_scheduled_hook( 'sad_daily' );
}

/**
 * Albums due a reminder: published, not approved, with a client email, quiet for the set number
 * of days since the last change and since the last reminder, and not reminded too often yet.
 *
 * @return int[]
 */
function sad_albums_due_reminder() {
	$settings = sad_get_settings();
	$days     = (int) $settings['reminder_days'];
	if ( $days <= 0 ) {
		return array();
	}
	$cutoff = time() - $days * DAY_IN_SECONDS;
	$due    = array();
	$ids    = get_posts(
		array(
			'post_type'      => 'sad_album',
			'post_status'    => 'publish',
			'posts_per_page' => 200,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
				array(
					'key'     => '_sad_status',
					'value'   => 'approved',
					'compare' => '!=',
				),
				array(
					'key'     => '_sad_updated_at',
					'value'   => $cutoff,
					'compare' => '<',
					'type'    => 'NUMERIC',
				),
			),
		)
	);
	// Albums that never had a status saved are open too.
	$ids = array_merge(
		$ids,
		get_posts(
			array(
				'post_type'      => 'sad_album',
				'post_status'    => 'publish',
				'posts_per_page' => 200,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array(
						'key'     => '_sad_status',
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => '_sad_updated_at',
						'value'   => $cutoff,
						'compare' => '<',
						'type'    => 'NUMERIC',
					),
				),
			)
		)
	);
	foreach ( array_unique( $ids ) as $album_id ) {
		$sent = (int) get_post_meta( $album_id, '_sad_reminders_sent', true );
		$last = (int) get_post_meta( $album_id, '_sad_reminded_at', true );
		if ( $sent >= (int) $settings['reminder_max'] || $last > $cutoff ) {
			continue;
		}
		if ( ! is_email( get_post_meta( $album_id, '_sad_client_email', true ) ) ) {
			continue;
		}
		$due[] = (int) $album_id;
	}
	return $due;
}

function sad_send_reminders() {
	foreach ( sad_albums_due_reminder() as $album_id ) {
		sad_send_reminder( $album_id );
	}
}

function sad_send_reminder( $album_id ) {
	$email  = get_post_meta( $album_id, '_sad_client_email', true );
	$client = get_post_meta( $album_id, '_sad_client_name', true );
	$sent   = wp_mail(
		$email,
		sprintf( 'Your album "%s" is waiting for you', sad_album_title( $album_id ) ),
		sprintf(
			"Hi%s,\n\nJust a friendly reminder that your album is saved and waiting for you. Pick up where you left off here:\n%s\n\nWhen you are happy with every spread, press \"Approve for printing\" and we will take it from there. If you need any help, simply reply to this email.\n\n%s\n",
			$client ? ' ' . $client : '',
			sad_album_link( $album_id ),
			get_bloginfo( 'name' )
		)
	);
	if ( $sent ) {
		update_post_meta( $album_id, '_sad_reminded_at', time() );
		update_post_meta( $album_id, '_sad_reminders_sent', (int) get_post_meta( $album_id, '_sad_reminders_sent', true ) + 1 );
	}
	return $sent;
}
