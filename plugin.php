<?php

/*
Plugin Name: Private Files (MPH)
Description: Upload files that are only accessable to logged in users.
Author: Matthew Haines-Young
Version: 1.0.6
Author URI: http://www.matth.eu
*/

/*
	TODO
	nonce verification?
 */

// Admin Notices Abstraction.
require( 'class.mph-minify-notices.php' );

// Hash for obscuring upload dir.
// An extra level of security but a little bit of a hassle.
define( 'MPHPF_KEY', hash( 'md5', AUTH_KEY ) );

// Action;
$var = new Mattheu_Private_Files();

class Mattheu_Private_Files {

	private $prefix = 'mphpf';
	private $admin_notices;

	function __construct() {

		add_action( 'init', array( $this, 'rewrite_rules' ) );

		add_filter( 'wp_get_attachment_url', array( $this, 'private_file_url' ), 10, 2 );

		// Is Private Checkbox
		add_action( 'attachment_submitbox_misc_actions', array( $this, 'private_attachment_field' ) , 11 );
		add_filter( 'attachment_fields_to_save', array( $this, 'private_attachment_field_save' ), 10, 2 );

		// Display private posts filter & query filter.
		add_filter( 'pre_get_posts', array( $this, 'hide_private_from_query' ) );
		add_filter( 'restrict_manage_posts', array( $this, 'filter_posts_toggle' ) );

		// Shortcode
		add_action( 'attachment_submitbox_misc_actions', array( $this, 'shortcode_field' ) , 11 );
		add_shortcode('file', array( $this, 'shortcode_function' ) );

		// Styles
		add_action( 'admin_head', array( $this, 'post_edit_style' ) );

		$this->admin_notices = new MPH_Admin_Notices( $this->prefix . '_private_media' );

	}

	/**
	 * Check if attachment is private
	 *
	 * @param  int $attachment_id
	 * @return boolean
	 */
	function is_attachment_private( $attachment_id ) {

		return get_post_meta( $attachment_id, 'mphpf_is_private', true );

	}

	/**
	 * Check if current user can view attachment
	 *
	 * @todo  allow this to be filtered for more advanced use.
	 *
	 * @param  int $attachment_id
	 * @param  int $user_id (if not passed, assumed current user)
	 * @return boolean
	 */
	function can_user_view( $attachment_id, $user_id = null ) {

		$user_id = ( $user_id ) ? $user_id : get_current_user_id();

		if ( ! $attachment_id )
			return false;

		$private_status = $this->is_attachment_private( $attachment_id );

		if ( ! empty( $private_status ) && ! is_user_logged_in() )
			return false;

		return true;

	}

	/**
	 * Get attachment id from attachment name
	 *
	 * @todo  surely this isn't the best way to do this?
	 * @param  [type] $attachment [description]
	 * @return [type]             [description]
	 */
	function get_attachment_id_from_name( $attachment ) {

		$attachment_post = new WP_Query( array(
			'post_type' => 'attachment',
			'showposts' => 1,
			'post_status' => 'inherit',
			'name' => $attachment,
			'show_private' => true
		) );

		if ( empty( $attachment_post->posts ) )
			return;

		return reset( $attachment_post->posts )->ID;

	}

	/**
	 * Redirect if authentication is required.
	 *
	 * @return null
	 */
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

		$htaccess = trailingslashit( $upload_dir['basedir'] ) . $dirname . '/.htaccess';

		if ( ! file_exists( $htaccess ) && function_exists( 'insert_with_markers' ) && is_writable( dirname( $htaccess ) ) ) {

			$contents[]	= "# This .htaccess file ensures that other people cannot download your private files.\n\n";
			$contents[] = "deny from all";

			insert_with_markers( $htaccess, 'mphpf', $contents );

		}

		if ( $path )
			return trailingslashit( $upload_dir['basedir'] ) . $dirname;

		return trailingslashit( $upload_dir['baseurl'] ) . $dirname;

	}

	function rewrite_rules() {

		$uploads = wp_upload_dir();
		$base_url = parse_url( $uploads['baseurl'] );
		$base_url = trim( $base_url['path'], ' /' );

		hm_add_rewrite_rule( array(
			'regex' => '^'.$base_url.'/private-files/([^*]+)/([^*]+)?$',
			'query' => 'file_id=$matches[1]&file_name=$matches[2]',
			'request_method' => 'get',
			'request_callback' => array( $this, 'rewrite_callback' )
		) );

	}

	function rewrite_callback( $wp ) {

		if ( ! empty( $wp->query_vars['file_id'] ) )
			$file_id = $wp->query_vars['file_id'];

		if ( ! empty( $wp->query_vars['file_name'] ) )
			$file_name = $wp->query_vars['file_name'];

		// Legagcy
		if ( empty( $file_id ) ) {
 			preg_match( "#(&|^)file_id=([^&$]+)#", $wp->matched_query, $file_id_matches );
 			if ( $file_id_matches )
 				$file_id = $file_id_matches[2];
			preg_match( "#(&|^)file_name=([^&$]+)#", $wp->matched_query, $file_name_matches );
				$file_name = $file_name_matches[2];
		}

		if ( ! isset( $file_id ) || isset( $file_id ) && ! $file = get_post( $file_id ) )
			$this->auth_redirect();

		$wp_attached_file = get_post_meta( $file_id, '_wp_attached_file', true );

		if ( ( $this->is_attachment_private( $file_id ) && ! is_user_logged_in() ) || empty( $wp_attached_file ) )
			$this->auth_redirect();

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
	 * Add 'Make file private' checkbox to edit attachment screen
	 *
	 * Adds the setting field to the submit box.
	 */
	function private_attachment_field() {

		$is_private = $this->is_attachment_private( get_the_id() );

		?>
		<div class="misc-pub-section">
			<label for="mphpf2"><input type="checkbox" id="mphpf2" name="<?php echo $this->prefix; ?>_is_private" <?php checked( $is_private, true ); ?> style="margin-right: 5px;"/>
			Make this file private</label>
		</div>
		<?php
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

		$this->mphpf_get_private_dir( true );

		if ( ! $creds ) {
			// Handle Error.
			// We can't actually display the form here because this is a filter and the page redirects and it will not be shown.
			$message = '<strong>Private Media Error</strong> WordPress is not able to write files';
			$this->admin_notices->add_notice( $message, false, 'error' );
			return $post;
		}

		if ( $creds && WP_Filesystem( $creds ) ) {

			global $wp_filesystem;

			$make_private = isset( $_POST[$this->prefix .'_is_private'] ) && 'on' == $_POST[$this->prefix .'_is_private'];

			$new_location = null;

			if ( $make_private ) {

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

			if ( $make_private )
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
	 * Set 404 for attachments in front end if user does not have permission to view file.
	 * Hide from any attachment query by default.
	 * If the 'show_private' query var is set, show only private.
	 *
	 * @param  object $query
	 * @return object $query
	 */
	function hide_private_from_query( $query ) {

		if ( ! is_admin() ) {

			$attachment = ( $query->get( 'attachment_id') ) ? $query->get( 'attachment_id') : $query->get( 'attachment');

			if ( $attachment && ! is_numeric( $attachment ) )
				$attachment = $this->get_attachment_id_from_name( $attachment );

			if ( $attachment && ! $this->can_user_view( $attachment ) ) {

				$query->set_404();
				return $query;

			}

		}

		if ( 'attachment' == $query->get('post_type') && ! $query->get('show_private') ) {

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
	 * Output select field for filtering media by public/private.
	 *
	 * @return null
	 */
	function filter_posts_toggle() {

		$is_private_filter_on = isset( $_GET['private_posts'] ) && 'private' == $_GET['private_posts'];

		?>

		<select name="private_posts">
			<option <?php selected( $is_private_filter_on, false ); ?> value="public">Public</option>
			<option <?php selected( $is_private_filter_on, true ); ?> value="private">Private</option>
		</select>

		<?php

	}

	/**
	 * Filter attachment url.
	 * If private return the 'public' private file url
	 * Rewrite rule used to serve file content and 'Real' file location is obscured.
	 *
	 * @param  string $url
	 * @param  int $attachment_id
	 * @return string file url.
	 */
	function private_file_url( $url, $attachment_id ) {

		if ( $this->is_attachment_private( $attachment_id ) ) {

			$uploads = wp_upload_dir();
			return trailingslashit( $uploads['baseurl'] ) . 'private-files/' . $attachment_id . '/' . basename($url);

		}

		return $url;

	}

	/**
	 * Shortcode Field
	 *
	 * Add a readonly input field containing the current file shortcode to the submitbox of the edit attachment page.
	 *
	 * @return null
	 */
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
	 * Else output a message.
	 *
	 * @param  array $atts shortcode attributes
	 * @return string shortcode output.
	 */
	function shortcode_function($atts) {

		if ( ! isset( $atts['id'] ) )
			return;

		if ( $this->is_attachment_private( $atts['id'] ) && ! is_user_logged_in() )
			$link = 'You must be logged in to access this file.';
		elseif ( isset( $atts['attachment_page'] ) )
			$link = wp_get_attachment_link( $atts['id'] );
		else
			$link = sprintf( '<a href="%s">%s</a>', esc_url( wp_get_attachment_url( $atts['id'] ) ), esc_html( basename( wp_get_attachment_url( $atts['id'] ) ) ) );

		return $link;

	}

	/**
	 * Insert CSS into admin head on edit attachment screen fro private files.
	 *
	 * @return null
	 */
	function post_edit_style() {

		$icon_url = trailingslashit( plugin_dir_url( __FILE__ ) ) . 'assets/icon_lock.png';
		$icon_url_2x = trailingslashit( plugin_dir_url( __FILE__ ) ) . 'assets/icon_lock@2x.png';

		if ( is_admin() && 'attachment' == get_current_screen()->id && $this->is_attachment_private( get_the_id() ) ) : ?>

			<style>
				#titlediv { padding-left: 60px; }
				#titlediv::before { content: ' '; display: block; height: 26px; width: 21px; background: url(<?php echo $icon_url; ?>) no-repeat center center; position: relative; float: left; margin-left: -40px; top: 4px; }
				@media only screen and ( -webkit-min-device-pixel-ratio : 1.5 ), only screen and ( min-device-pixel-ratio : 1.5 ) {
					#titlediv::before { background-image: url(<?php echo $icon_url_2x; ?>); }
				}
			</style>

		<?php endif;

	}

}
