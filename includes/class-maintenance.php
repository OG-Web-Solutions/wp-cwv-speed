<?php
/**
 * Database cleanup and bulk image conversion.
 *
 * @package CWV_Speed
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manual maintenance tools. Nothing here runs on the front end.
 */
class CWV_Speed_Maintenance {

	/**
	 * Singleton.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Instance.
	 *
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register admin actions.
	 *
	 * @return void
	 */
	public function hooks() {
		add_action( 'admin_post_cwv_speed_clean', array( $this, 'handle_clean' ) );
		add_action( 'wp_ajax_cwv_speed_convert_images', array( $this, 'convert_batch' ) );
	}

	/**
	 * Delete a limited batch of expired rows.
	 *
	 * @param string $task revisions|transients|spam|trash|drafts.
	 * @return int Rows removed.
	 */
	public function clean( $task ) {
		global $wpdb;
		$removed = 0;

		if ( 'revisions' === $task ) {
			$ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'revision' ORDER BY ID ASC LIMIT 300" );
			foreach ( (array) $ids as $id ) {
				if ( wp_delete_post_revision( (int) $id ) ) {
					++$removed;
				}
			}
			return $removed;
		}

		if ( 'spam' === $task ) {
			$ids = $wpdb->get_col( "SELECT comment_ID FROM {$wpdb->comments} WHERE comment_approved = 'spam' ORDER BY comment_ID ASC LIMIT 300" );
			foreach ( (array) $ids as $id ) {
				if ( wp_delete_comment( (int) $id, true ) ) {
					++$removed;
				}
			}
			return $removed;
		}

		if ( 'trash' === $task ) {
			$ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'trash' ORDER BY ID ASC LIMIT 100" );
			foreach ( (array) $ids as $id ) {
				if ( wp_delete_post( (int) $id, true ) ) {
					++$removed;
				}
			}
			return $removed;
		}

		if ( 'drafts' === $task ) {
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_status = 'auto-draft' AND post_modified < %s ORDER BY ID ASC LIMIT 100",
					gmdate( 'Y-m-d H:i:s', time() - ( 7 * DAY_IN_SECONDS ) )
				)
			);
			foreach ( (array) $ids as $id ) {
				if ( wp_delete_post( (int) $id, true ) ) {
					++$removed;
				}
			}
			return $removed;
		}

		if ( 'transients' === $task ) {
			$names = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d LIMIT 300",
					$wpdb->esc_like( '_transient_timeout_' ) . '%',
					time()
				)
			);
			foreach ( (array) $names as $name ) {
				$key = substr( (string) $name, strlen( '_transient_timeout_' ) );
				if ( '' !== $key && delete_transient( $key ) ) {
					++$removed;
				}
			}
		}

		return $removed;
	}

	/**
	 * Run a cleanup task from the tools form.
	 *
	 * @return void
	 */
	public function handle_clean() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'wp-cwv-speed' ) );
		}
		check_admin_referer( 'cwv_speed_clean' );
		$task  = isset( $_POST['cwv_task'] ) ? sanitize_key( wp_unslash( $_POST['cwv_task'] ) ) : '';
		$count = $this->clean( $task );
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'      => 'cwv-speed-tools',
					'cwv_clean' => rawurlencode( $task ),
					'cwv_count' => $count,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Convert the next JPEG and PNG attachments to WebP or AVIF.
	 *
	 * @return void
	 */
	public function convert_batch() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Sorry, you are not allowed to do that.', 'wp-cwv-speed' ), 403 );
		}
		check_ajax_referer( 'cwv_speed_convert' );

		$offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
		$query  = new WP_Query(
			array(
				'post_type'              => 'attachment',
				'post_status'            => 'inherit',
				'post_mime_type'         => array( 'image/jpeg', 'image/png' ),
				'posts_per_page'         => 2,
				'offset'                 => $offset,
				'fields'                 => 'ids',
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$processed = 0;
		foreach ( $query->posts as $id ) {
			$meta = wp_get_attachment_metadata( $id );
			CWV_Speed_Images::instance()->convert_attachment( is_array( $meta ) ? $meta : array(), (int) $id );
			++$processed;
		}

		$total = (int) $query->found_posts;
		$next  = $offset + $processed;
		wp_send_json_success(
			array(
				'processed' => $processed,
				'next'      => $next,
				'total'     => $total,
				'done'      => $next >= $total || 0 === $processed,
			)
		);
	}
}
