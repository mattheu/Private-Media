<?php

/*
Plugin Name: Private Files (MPH)
Description: Upload files that are only accessable to logged in users.
Author: Matthew Haines-Young
Version: 1.0.5
Author URI: http://www.matth.eu
*/

// Hash for obscuring upload dir.
// An extra level of security but a little bit of a hassle.
define( 'MPHPF_KEY', hash( 'crc32', AUTH_KEY ) );

class Mattheu_Private_Files {

	private static $prefix = 'mattheu';

	function init() {

		add_action( 'init', array( __CLASS__, 'rewrite_rules' ) );

		add_filter( 'wp_get_attachment_url', array( __CLASS__, 'private_file_url' ), 10, 2 );

		// Is Private Checkbox
		add_filter( 'attachment_fields_to_edit', array( __CLASS__, 'private_attachment_field' ), 10, 2 );
		add_filter( 'attachment_fields_to_save', array( __CLASS__, 'private_attachment_field_save' ), 10, 2 );

		// Display private posts filter & query filter.
		add_filter( 'pre_get_posts', array( __CLASS__, 'hide_private_from_query' ) );
		add_filter( 'restrict_manage_posts', array( __CLASS__, 'filter_posts_toggle' ) );

		// Shortcode
		add_action( 'attachment_submitbox_misc_actions', array( __CLASS__, 'shortcode_field' ) , 11 );
		add_shortcode('file', array( __CLASS__, 'shortcode_function' ) );

		// Styles
		add_action( 'admin_head', array( __CLASS__, 'post_edit_style' ) );

	}

	function is_attachment_private( $attachment_id ) {

		return get_post_meta( $attachment_id, 'mphpf_is_private', true );

	}

	function auth_redirect() {

		auth_redirect();

	}

	/**
	 * Get Private Directory URL
	 *
	 * If $path is true return path not url.
	 *
	 * @param  boolean $path return path not url.
	 * @return string path or url
	 */
	function mphpf_get_private_dir( $path = false ) {

		$dirname = 'private-files-' . MPHPF_KEY;
		$upload_dir = wp_upload_dir();

		// Maybe create the directory.
		if ( ! is_dir( trailingslashit( $upload_dir['basedir'] ) . $dirname ) )
			wp_mkdir_p( trailingslashit( $upload_dir['basedir'] ) . $dirname );

		// @todo maybe create .htaccess

		if ( $path )
			return trailingslashit( $upload_dir['basedir'] ) . $dirname;

		return trailingslashit( $upload_dir['baseurl'] ) . $dirname;

	}

	function rewrite_rules() {

		$private_dir = self::mphpf_get_private_dir( true );

		hm_add_rewrite_rule( array(
			'regex' => '^content/uploads/private-files/([^*]+)/([^*]+)?$',
			'query' => 'file_id=$matches[1]&file_name=$matches[2]',
			'request_method' => 'get',
			'request_callback' => array( __class__, 'rewrite_callback' )
		) );

	}

	function rewrite_callback( WP $wp ) {

		$file_id = $wp->query_vars['file_id'];
		$file_name = $wp->query_vars['file_name'];

		if ( ! $file = get_post( $file_id ) )
			self::auth_redirect();

		$wp_attached_file = get_post_meta( $file_id, '_wp_attached_file', true );

		if ( ( self::is_attachment_private( $file_id ) && ! is_user_logged_in() ) || empty( $wp_attached_file ) )
			self::auth_redirect();

		$uploads = wp_upload_dir();
		$file_path = trailingslashit( $uploads['basedir'] ) . $wp_attached_file;
		$mime_type = get_post_mime_type( $file_id );

		$file = fopen( $file_path, 'rb' );
		header( 'Content-Type: ' . $mime_type );
		header( 'Content-Length: ' . filesize( $file_path ) );
		fpassthru( $file );
		exit;

	}

	/**
	 * Add 'is private' checkbox to edit attachment screen
	 *
	 * @return [type]         [description]
	 */
	function private_attachment_field( $fields, $post ) {

		$is_private = self::is_attachment_private( $post->ID );

		$html  = '<input type="checkbox" id="mphpf1" name="attachments[' . $post->ID . '][mphpf]" ' . checked( $is_private, true, false ) . 'style="margin-right: 5px;"/> ';
		$html .= '<label for="mphpf1">Make this file private</label>';

		$fields['mphpf'] = array(
	    	'label' => 'Private Files',
	    	'input' => 'html',
	    	'html' => $html
	    );

	    return $fields;

	}

	/**
	 * Save private attachment field settings.
	 *
	 * On save - update settings and move files.
	 * Uses WP_Filesystem
	 *
	 * @todo check this out. Might need to handle edge cases.
	 */
	function private_attachment_field_save( $post, $attachment ) {

		$uploads = wp_upload_dir();
		$creds   = request_filesystem_credentials( add_query_arg( null, null ) );

		if ( ! $creds ) {


		}

		if ( $creds && WP_Filesystem( $creds ) ) {

			global $wp_filesystem;

			if ( isset( $attachment['mphpf'] ) && $attachment['mphpf'] == 'on' ) {

				$old_location = get_post_meta( $post['ID'], '_wp_attached_file', true );

				if ( $old_location && false === strpos( $old_location, 'private-files-' . MPHPF_KEY ) )
					$new_location = 'private-files-' . MPHPF_KEY . '/' . $old_location;

			} else {

				// Update location of file in meta.
				$old_location = get_post_meta( $post['ID'], '_wp_attached_file', true );
				if ( $old_location && false !== strpos( $old_location, 'private-files-' . MPHPF_KEY ) )
					$new_location = str_replace( 'private-files-' . MPHPF_KEY . '/', '', $old_location );

			}

			$metadata = get_post_meta( $post['ID'], '_wp_attachment_metadata', true );

			if ( ! $new_location )
				return $post;

			$old_path = trailingslashit( $uploads['basedir'] ) . $old_location;
			$new_path = trailingslashit( $uploads['basedir'] ) . $new_location;

			// Create destination
			if ( ! is_dir( dirname( $new_path ) ) )
				wp_mkdir_p( dirname( $new_path ) );

			$move = $wp_filesystem->move( $old_path, $new_path );

			if ( isset( $metadata['sizes'] ) )
				foreach ( $metadata['sizes'] as $key => $size ) {
					$old_image_size_path = trailingslashit( dirname( $old_path ) ) . $size['file'];
					$new_image_size_path = trailingslashit( dirname( $new_path ) ) . $size['file'];
					$move = $wp_filesystem->move( $old_image_size_path, $new_image_size_path );
				}


			if ( ! $move ) {
				// @todo handle errors.
			}

			if ( isset( $attachment['mphpf'] ) && $attachment['mphpf'] == 'on' )
				update_post_meta( $post['ID'], 'mphpf_is_private', true );
			else
				delete_post_meta( $post['ID'], 'mphpf_is_private' );

			update_post_meta( $post['ID'], '_wp_attached_file', $new_location );

			$metadata['file' ] = $new_location;
			update_post_meta( $post['ID'], '_wp_attachment_metadata', $metadata );

		}

		return $post;

	}

	/**
	 * Filter query to hide private posts.
	 *
	 * Hide from any query by default.
	 * Set 404 for attachments in front end is not logged in.
	 *
	 * @param  object $query
	 * @return object $query
	 */
	function hide_private_from_query( $query ) {


		if ( ! is_admin() && $attachment_id = $query->get( 'attachment_id')  )
			if ( self::is_attachment_private( $attachment_id ) && ! is_user_logged_in() ) {
				$query->set_404();
				return;
			}


		if ( 'attachment' == $query->get('post_type') ) {

			if ( isset( $_GET['private_posts'] ) && 'private' == $_GET['private_posts']  )
				$query->set( 'meta_query', array(
					array(
						'key'   => 'mphpf_is_private',
						'compare' => 'EXISTS'
					)
				));
			else
				$query->set( 'meta_query', array(
					array(
						'key'   => 'mphpf_is_private',
						'compare' => 'NOT EXISTS'
					)
				));

		}

		return $query;

	}

	/**
	 * Output toggle for filtering private/public posts in list table.
	 */
	function filter_posts_toggle() {

		$is_private_filter_on = isset( $_GET['private_posts'] ) && 'private' == $_GET['private_posts'];
		echo '<label style="margin: 0 5px 0 10px;"><input type="radio" name="private_posts" value="public" ' . checked( $is_private_filter_on, false, false ) . ' style="margin-top: -1px; margin-right: 2px;"/> Public</label>';
		echo '<label style="margin: 0 10px 0 5px;"><input type="radio" name="private_posts" value="private" ' . checked( $is_private_filter_on, true, false ) . ' style="margin-top: -1px; margin-right: 2px;"/> Private</label>';

	}

	/**
	 * Filter attachment url.
	 * If private return public facing private file url - uses rewrite to serve file content.
	 * 'Real' file location should be obscured.
	 *
	 * @param  [type] $url           [description]
	 * @param  [type] $attachment_id [description]
	 * @return [type]                [description]
	 */
	function private_file_url( $url, $attachment_id ) {

		if ( self::is_attachment_private( $attachment_id ) ) {

			$uploads = wp_upload_dir();
			return trailingslashit( $uploads['baseurl'] ) . 'private-files/' . $attachment_id . '/' . basename($url);

		}

		return $url;

	}

	function shortcode_field() {

		$shortcode = '[file id="' . get_the_ID() . '" ]';

		?>
		<div class="misc-pub-section">
			<label for="attachment_url"><?php _e( 'File Shortcode:' ); ?></label>
			<input type="text" class="widefat urlfield" readonly="readonly" name="attachment_url" value="<?php echo esc_attr($shortcode); ?>" />
		</div>
		<?php
	}

	/**
	 * Output link to file if user is logged in.
	 * @param  [type] $atts [description]
	 * @return [type]       [description]
	 */
	function shortcode_function($atts) {

		if ( ! isset( $atts['id'] ) )
			return;

		if ( self::is_attachment_private( $atts['id'] ) && ! is_user_logged_in() )
			$link = 'You must be logged in to access this file.';
		elseif ( isset( $atts['attachment_page'] ) )
			$link = wp_get_attachment_link( $atts['id'] );
		else
			$link = sprintf( '<a href="%s">%s</a>', esc_url( wp_get_attachment_url( $atts['id'] ) ), esc_html( basename( wp_get_attachment_url( $atts['id'] ) ) ) );

		return $link;

	}

	function post_edit_style() {

		if ( self::is_attachment_private( get_the_ID() ) )
			echo '<style>#titlediv { padding-left: 60px;}</style>';

	}

}

Mattheu_Private_Files::init();



// add_filter( 'bulk_actions-upload', function( $actions ) {
// 	$actions['make_private'] = 'Set as Private';
// 	$actions['make_public'] = 'Set as Public';
// 	return $actions;
// } );