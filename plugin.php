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

class UNDPRK_Private_Files {

	function init() {

		add_action( 'init', array( __CLASS__, 'rewrite_rules' ) );

		add_filter( 'wp_get_attachment_url', array( __CLASS__, 'private_file_url' ), 10, 2 );

		// Admin filters.
		add_filter( 'attachment_fields_to_edit', array( __CLASS__, 'private_attachment_field' ), 10, 2 );
		add_filter( 'attachment_fields_to_save', array( __CLASS__, 'private_attachment_field_save' ), 10, 2 );
		add_filter( 'pre_get_posts', array( __CLASS__, 'hide_private_from_admin_query' ) );


		add_action( 'admin_menu', function() {

			add_media_page( 'My Plugin Media', 'My Plugin', 'read', 'my-unique-identifier', array( __CLASS__, 'admin_screen' ) );

		} );


	}

	function admin_screen() {

		include( trailingslashit( dirname( __FILE__ ) ) . 'admin.php' );

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
	 * Filter query to hide private posts in admin
	 *
	 * @param  object $query
	 * @return object $query
	 */
	function hide_private_from_admin_query( $query ) {

		if ( ! $query->is_main_query() || 'upload' !== get_current_screen()->id )
			return $query;

		$private_posts = get_posts( array( 'meta_key' => 'mphpf_is_private', 'meta_value' => '1', 'meta_compare' => '==', 'numberposts' => -1, 'post_type' => 'attachment' ) );

		$private_post_ids = array(0);
		foreach( $private_posts as $exclude_post ) {
			$private_post_ids[] =  $exclude_post->ID;
		}

		if ( isset( $_GET['private_posts'] ) && $_GET['private_posts'] )
			$query->set('post__in', $private_post_ids );
		else
			$query->set('post__not_in', $private_post_ids );

		return $query;

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


}

UNDPRK_Private_Files::init();


/**
 * Filter by private files link above list table.
 */
function mphpf_filter_list_table_views( $views ) {

	$url = add_query_arg( array( 'private_posts'=>'true', 'post_mime_type'=>'all', 'paged'=>false ), 'upload.php' );
	$private_posts = get_posts( array( 'meta_key' => 'mphpf_is_private', 'meta_value' => '1', 'meta_compare' => '==', 'numberposts' => -1, 'post_type' => 'attachment' ) );
	$views[] = '<a class="' . ( ( isset( $_GET['private_posts'] ) ) ? 'current' : null ) . '" href="' . esc_url( $url ) . '">Private <span class="count">(' . count( $private_posts ) . ')</span></a>';

	return $views;

}
add_filter( 'views_upload', 'mphpf_filter_list_table_views' );