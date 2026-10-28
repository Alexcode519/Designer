<?php
/**
 * Lets clients start their own album from the designer page, and get their link emailed again.
 *
 * The forms post to admin-post.php without a nonce on purpose: they are used by logged-out visitors
 * on pages that hosts often cache, where a nonce would expire and break the form. A honeypot field,
 * a per-visitor rate limit and the optional access code keep out bots instead.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_post_nopriv_sad_start', 'sad_handle_start' );
add_action( 'admin_post_sad_start', 'sad_handle_start' );
add_action( 'admin_post_nopriv_sad_resend', 'sad_handle_resend' );
add_action( 'admin_post_sad_resend', 'sad_handle_resend' );

const SAD_START_LIMIT  = 5;
const SAD_RESEND_LIMIT = 5;

function sad_designer_page_url() {
	$page_id = sad_designer_page_id();
	return $page_id ? get_permalink( $page_id ) : home_url( '/album-designer/' );
}

/**
 * Counts attempts per visitor (by hashed IP) and says whether this one is still allowed.
 */
function sad_rate_limit_ok( $action, $limit ) {
	$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
	$key = 'sad_rl_' . $action . '_' . md5( $ip . wp_salt( 'nonce' ) );
	$hits = (int) get_transient( $key );
	if ( $hits >= $limit ) {
		return false;
	}
	set_transient( $key, $hits + 1, HOUR_IN_SECONDS );
	return true;
}

function sad_start_redirect( $args ) {
	wp_safe_redirect( add_query_arg( $args, sad_designer_page_url() ) . '#sad-start' );
	exit;
}

function sad_handle_start() {
	$settings = sad_get_settings();
	if ( ! $settings['self_start'] ) {
		sad_start_redirect( array( 'sad_error' => 'closed' ) );
	}

	// Bots fill in every field; people never see this one.
	if ( ! empty( $_POST['sad_website'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		sad_start_redirect( array( 'sad_error' => 'invalid' ) );
	}

	$names = sanitize_text_field( wp_unslash( $_POST['sad_names'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
	$email = sanitize_email( wp_unslash( $_POST['sad_email'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
	$size  = sanitize_title( wp_unslash( $_POST['sad_size'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
	$cover = sanitize_title( wp_unslash( $_POST['sad_cover'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
	$code  = trim( sanitize_text_field( wp_unslash( $_POST['sad_code'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
	$keep  = array(
		'sad_names' => rawurlencode( $names ),
		'sad_email' => rawurlencode( $email ),
		'sad_size'  => rawurlencode( $size ),
		'sad_cover' => rawurlencode( $cover ),
	);

	if ( '' !== $settings['start_code'] && 0 !== strcasecmp( $code, $settings['start_code'] ) ) {
		sad_start_redirect( array_merge( $keep, array( 'sad_error' => 'code' ) ) );
	}
	if ( '' === $names || ! is_email( $email ) ) {
		sad_start_redirect( array_merge( $keep, array( 'sad_error' => 'missing' ) ) );
	}
	$size_ids = wp_list_pluck( sad_get_sizes(), 'id' );
	if ( ! in_array( $size, $size_ids, true ) || ! isset( sad_cover_materials()[ $cover ] ) ) {
		sad_start_redirect( array_merge( $keep, array( 'sad_error' => 'missing' ) ) );
	}
	if ( ! sad_rate_limit_ok( 'start', SAD_START_LIMIT ) ) {
		sad_start_redirect( array( 'sad_error' => 'busy' ) );
	}

	$album_id = sad_create_client_album( $names, $email, $size, $cover );
	if ( is_wp_error( $album_id ) ) {
		sad_start_redirect( array_merge( $keep, array( 'sad_error' => 'failed' ) ) );
	}

	sad_email_album_link( $album_id, true );

	wp_safe_redirect( add_query_arg( array( 'album' => sad_album_token( $album_id ), 'sad_welcome' => 1 ), sad_designer_page_url() ) );
	exit;
}

/**
 * Creates and publishes an album for a client who started it themselves.
 *
 * @return int|WP_Error
 */
function sad_create_client_album( $names, $email, $size, $cover = '' ) {
	$settings = sad_get_settings();
	$album_id = wp_insert_post(
		array(
			'post_type'   => 'sad_album',
			'post_status' => 'publish',
			'post_title'  => $names . ' album',
		),
		true
	);
	if ( is_wp_error( $album_id ) ) {
		return $album_id;
	}
	update_post_meta( $album_id, '_sad_client_name', $names );
	update_post_meta( $album_id, '_sad_client_email', $email );
	update_post_meta( $album_id, '_sad_size', $size );
	update_post_meta( $album_id, '_sad_min_spreads', (int) $settings['min_spreads'] );
	update_post_meta( $album_id, '_sad_max_spreads', max( (int) $settings['min_spreads'], (int) $settings['max_spreads'] ) );
	update_post_meta( $album_id, '_sad_started_by', 'client' );
	update_post_meta( $album_id, '_sad_updated_at', time() );
	sad_album_token( $album_id );
	sad_set_album_cover_type( $album_id, $cover );

	$to = $settings['notify_email'] ? $settings['notify_email'] : get_option( 'admin_email' );
	wp_mail(
		$to,
		sprintf( 'New album started: %s', $names ),
		sprintf(
			"%s (%s) has started an album: %s.\n\nSee all albums:\n%s\n",
			$names,
			$email,
			sad_get_size( $size )['name'] . ( isset( sad_cover_materials()[ $cover ] ) ? ', ' . sad_cover_materials()[ $cover ]['name'] . ' cover' : '' ),
			admin_url( 'edit.php?post_type=sad_album' )
		)
	);

	return (int) $album_id;
}

/**
 * Emails the client their private album link.
 */
function sad_email_album_link( $album_id, $is_new ) {
	$email = get_post_meta( $album_id, '_sad_client_email', true );
	if ( ! is_email( $email ) ) {
		return;
	}
	$names = get_post_meta( $album_id, '_sad_client_name', true );
	$intro = $is_new ? 'Your album is ready to design.' : 'Here is the link to your album.';
	wp_mail(
		$email,
		sprintf( 'Your album link: %s', sad_album_title( $album_id ) ),
		sprintf(
			"Hi %s,\n\n%s Keep this email: the link below is private and takes you straight back to your album at any time. Your work saves automatically.\n\n%s\n\n%s\n",
			$names ? $names : 'there',
			$intro,
			sad_album_link( $album_id ),
			get_bloginfo( 'name' )
		)
	);
}

function sad_handle_resend() {
	$email = sanitize_email( wp_unslash( $_POST['sad_email'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
	if ( ! empty( $_POST['sad_website'] ) || ! is_email( $email ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		sad_start_redirect( array( 'sad_error' => 'email' ) );
	}
	if ( sad_rate_limit_ok( 'resend', SAD_RESEND_LIMIT ) ) {
		$albums = get_posts(
			array(
				'post_type'      => 'sad_album',
				'post_status'    => 'publish',
				'meta_key'       => '_sad_client_email', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'     => $email, // phpcs:ignore WordPress.DB.SlowDBQuery
				'posts_per_page' => 10,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		foreach ( $albums as $album_id ) {
			sad_email_album_link( $album_id, false );
		}
	}
	// Same answer whether or not the email has albums, so the form can't be used to look people up.
	sad_start_redirect( array( 'sad_sent' => 1 ) );
}

/**
 * The start page shown on the designer page when no album link is open.
 */
function sad_render_start_page() {
	$settings = sad_get_settings();
	if ( ! $settings['self_start'] ) {
		return '<div class="sad-message">Open this page using the album link we emailed you.</div>';
	}

	wp_enqueue_style( 'sad-editor', SAD_URL . 'assets/editor.css', array(), SAD_VERSION );

	// phpcs:disable WordPress.Security.NonceVerification
	$error = isset( $_GET['sad_error'] ) ? sanitize_key( $_GET['sad_error'] ) : '';
	$sent  = ! empty( $_GET['sad_sent'] );
	$names = isset( $_GET['sad_names'] ) ? sanitize_text_field( wp_unslash( $_GET['sad_names'] ) ) : '';
	$email = isset( $_GET['sad_email'] ) ? sanitize_email( wp_unslash( $_GET['sad_email'] ) ) : '';
	$size  = isset( $_GET['sad_size'] ) ? sanitize_title( wp_unslash( $_GET['sad_size'] ) ) : '';
	$cover = isset( $_GET['sad_cover'] ) ? sanitize_title( wp_unslash( $_GET['sad_cover'] ) ) : '';
	// phpcs:enable

	$messages = array(
		'missing' => 'Please fill in your names, a valid email address, an album size and a cover.',
		'code'    => 'That access code is not right. Please check the code we gave you.',
		'busy'    => 'Too many albums were started from this connection. Please try again in an hour.',
		'failed'  => 'Something went wrong creating your album. Please try again.',
		'email'   => 'Please enter a valid email address.',
		'closed'  => 'Starting a new album online is not available right now. Please contact us.',
		'invalid' => 'Something went wrong. Please try again.',
	);

	ob_start();
	?>
	<div class="sad-app sad-start" id="sad-start">
		<?php if ( $error && isset( $messages[ $error ] ) ) : ?>
			<div class="sad-notice sad-notice--error" role="alert"><?php echo esc_html( $messages[ $error ] ); ?></div>
		<?php endif; ?>
		<?php if ( $sent ) : ?>
			<div class="sad-notice" role="status">If we have an album for that email address, we have sent the link to it. Check your inbox and spam folder.</div>
		<?php endif; ?>

		<div class="sad-start__grid">
			<form class="sad-card" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<h2 class="sad-title">Design your album</h2>
				<p class="sad-help">Tell us who you are and choose your album size and cover. We will email you a private link so you can come back to your design at any time.</p>
				<input type="hidden" name="action" value="sad_start">
				<p class="sad-hp" aria-hidden="true"><label>Leave this empty <input type="text" name="sad_website" tabindex="-1" autocomplete="off"></label></p>
				<p>
					<label for="sad_names">Your names</label>
					<input id="sad_names" name="sad_names" type="text" required maxlength="120" placeholder="e.g. Sasha &amp; Alex" value="<?php echo esc_attr( $names ); ?>">
				</p>
				<p>
					<label for="sad_email">Email address</label>
					<input id="sad_email" name="sad_email" type="email" required maxlength="200" value="<?php echo esc_attr( $email ); ?>">
				</p>
				<p>
					<label for="sad_size">Album size</label>
					<select id="sad_size" name="sad_size" required>
						<option value="">Choose a size</option>
						<?php foreach ( sad_get_sizes() as $option ) : ?>
							<option value="<?php echo esc_attr( $option['id'] ); ?>" <?php selected( $size, $option['id'] ); ?>><?php echo esc_html( $option['name'] ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
				<fieldset class="sad-covers">
					<legend>Cover</legend>
					<?php foreach ( sad_cover_materials() as $cover_id => $material ) : ?>
						<label class="sad-cover-option">
							<input type="radio" name="sad_cover" value="<?php echo esc_attr( $cover_id ); ?>" required <?php checked( $cover, $cover_id ); ?>>
							<span class="sad-cover-option__swatch sad-material--<?php echo esc_attr( $material['printed'] ? 'printed' : $material['texture'] ); ?>" style="<?php echo $material['colours'] ? 'background-color:' . esc_attr( reset( $material['colours'] )['hex'] ) : ''; ?>" aria-hidden="true"></span>
							<span class="sad-cover-option__text">
								<strong><?php echo esc_html( $material['name'] ); ?></strong>
								<span class="sad-help"><?php echo esc_html( $material['printed'] ? 'Printed with your own photos' : sprintf( '%d colours', count( $material['colours'] ) ) ); ?></span>
							</span>
						</label>
					<?php endforeach; ?>
				</fieldset>
				<?php if ( '' !== $settings['start_code'] ) : ?>
					<p>
						<label for="sad_code">Access code</label>
						<input id="sad_code" name="sad_code" type="text" required autocomplete="off">
						<span class="sad-help">You'll find it in the email from us.</span>
					</p>
				<?php endif; ?>
				<p><button type="submit" class="sad-btn sad-btn--primary">Start my album</button></p>
			</form>

			<form class="sad-card" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<h3>Already started?</h3>
				<p class="sad-help">Enter the email address you used and we will send your album link again.</p>
				<input type="hidden" name="action" value="sad_resend">
				<p class="sad-hp" aria-hidden="true"><label>Leave this empty <input type="text" name="sad_website" tabindex="-1" autocomplete="off"></label></p>
				<p>
					<label for="sad_resend_email">Email address</label>
					<input id="sad_resend_email" name="sad_email" type="email" required maxlength="200">
				</p>
				<p><button type="submit" class="sad-btn">Email me my link</button></p>
			</form>
		</div>
	</div>
	<?php
	return ob_get_clean();
}
