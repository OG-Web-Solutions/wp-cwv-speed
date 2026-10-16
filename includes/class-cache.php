<?php
/**
 * Full-page cache for anonymous GET requests.
 *
 * @package CWV_Speed
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Writes the drop-in, stores optimized HTML, and purges on content changes.
 */
class CWV_Speed_Cache {

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
	 * Cache directory.
	 *
	 * @return string
	 */
	public static function dir() {
		return WP_CONTENT_DIR . '/cache/cwv-speed';
	}

	/**
	 * Register purge hooks and keep the drop-in in sync.
	 *
	 * @return void
	 */
	public function hooks() {
		add_action( 'init', array( $this, 'sync' ), 20 );
		add_action( 'save_post', array( $this, 'purge' ) );
		add_action( 'deleted_post', array( $this, 'purge' ) );
		add_action( 'wp_trash_post', array( $this, 'purge' ) );
		add_action( 'comment_post', array( $this, 'purge' ) );
		add_action( 'transition_comment_status', array( $this, 'purge' ) );
		add_action( 'switch_theme', array( $this, 'purge' ) );
		add_action( 'customize_save_after', array( $this, 'purge' ) );
		add_action( 'wp_update_nav_menu', array( $this, 'purge' ) );
		add_action( 'update_option_sidebars_widgets', array( $this, 'purge' ) );
		add_action( 'activated_plugin', array( $this, 'purge' ) );
		add_action( 'deactivated_plugin', array( $this, 'purge' ) );
		add_action( 'upgrader_process_complete', array( $this, 'purge' ) );
		add_action( 'admin_bar_menu', array( $this, 'admin_bar' ), 100 );
		add_action( 'admin_post_cwv_speed_purge', array( $this, 'handle_purge' ) );
	}

	/**
	 * Install or refresh the drop-in and config when caching is enabled.
	 *
	 * @return void
	 */
	public function sync() {
		$this->protect_dirs();
		if ( ! CWV_Speed_Settings::instance()->enabled( 'page_cache' ) ) {
			$this->remove_dropin();
			$this->write_config( false );
			return;
		}

		$this->ensure_wp_cache_constant();
		$this->write_dropin();
		$this->write_config( true );
	}

	/**
	 * Whether the current response may be stored.
	 *
	 * @return bool
	 */
	public function can_store() {
		if ( ! CWV_Speed_Settings::instance()->enabled( 'page_cache' ) ) {
			return false;
		}
		if ( is_user_logged_in() || is_preview() || is_customize_preview() || is_feed() || is_search() || is_robots() ) {
			return false;
		}
		if ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) {
			return false;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}
		if ( function_exists( 'is_cart' ) && ( is_cart() || is_checkout() || is_account_page() ) ) {
			return false;
		}
		if ( 'GET' !== strtoupper( (string) ( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : 'GET' ) ) ) {
			return false;
		}
		$home = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$host = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( (string) $_SERVER['HTTP_HOST'] ) : '';
		if ( 1 === substr_count( $host, ':' ) ) {
			$host = (string) strstr( $host, ':', true );
		}
		if ( '' !== $home && $host !== $home ) {
			return false;
		}

		foreach ( headers_list() as $header ) {
			$lower = strtolower( $header );
			if ( 0 === strpos( $lower, 'set-cookie:' ) ) {
				if ( preg_match( '/wordpress_logged_in|comment_author|woocommerce|wp-postpass/i', $header ) ) {
					return false;
				}
			}
			if ( 0 === strpos( $lower, 'cache-control:' ) && ( false !== strpos( $lower, 'no-store' ) || false !== strpos( $lower, 'private' ) ) ) {
				return false;
			}
			if ( 0 === strpos( $lower, 'content-type:' ) && false === strpos( $lower, 'text/html' ) ) {
				return false;
			}
		}

		/**
		 * Short-circuit page-cache storage.
		 *
		 * @param bool $can_store Whether CWV Speed may store this response.
		 */
		return (bool) apply_filters( 'cwv_speed_can_cache', true );
	}

	/**
	 * Store an optimized document.
	 *
	 * @param string $html Full HTML.
	 * @return void
	 */
	public function store( $html ) {
		if ( ! $this->can_store() || false === stripos( $html, '</html>' ) ) {
			$this->mark( 'BYPASS' );
			return;
		}

		$code = function_exists( 'http_response_code' ) ? (int) http_response_code() : 200;
		if ( $code && 200 !== $code && 404 !== $code ) {
			$this->mark( 'BYPASS' );
			return;
		}

		$file = $this->file_for_request();
		if ( '' === $file ) {
			return;
		}

		$dir = dirname( $file );
		if ( ! wp_mkdir_p( $dir ) ) {
			return;
		}

		$tmp = $file . '.' . wp_unique_id( 'tmp' );
		if ( false === file_put_contents( $tmp, $html, LOCK_EX ) ) {
			return;
		}
		rename( $tmp, $file );

		if ( CWV_Speed_Settings::instance()->enabled( 'gzip' ) && function_exists( 'gzencode' ) ) {
			$gz = gzencode( $html, 6 );
			if ( is_string( $gz ) ) {
				file_put_contents( $file . '.gz', $gz, LOCK_EX );
			}
		}
		if ( CWV_Speed_Settings::instance()->enabled( 'gzip' ) && function_exists( 'brotli_compress' ) ) {
			$br = brotli_compress( $html, 6 );
			if ( is_string( $br ) ) {
				file_put_contents( $file . '.br', $br, LOCK_EX );
			}
		}

		$this->mark( 'MISS' );
	}

	/**
	 * Delete cached pages, minified copies, and self-hosted font files.
	 *
	 * @return void
	 */
	public function purge() {
		foreach ( array( 'pages', 'min', 'fonts' ) as $folder ) {
			$this->empty_dir( self::dir() . '/' . $folder );
		}
	}

	/**
	 * Admin bar purge link.
	 *
	 * @param WP_Admin_Bar $bar Admin bar.
	 * @return void
	 */
	public function admin_bar( $bar ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$bar->add_node(
			array(
				'id'    => 'cwv-speed-purge',
				'title' => __( 'Purge CWV cache', 'wp-cwv-speed' ),
				'href'  => wp_nonce_url( admin_url( 'admin-post.php?action=cwv_speed_purge' ), 'cwv_speed_purge' ),
			)
		);
	}

	/**
	 * Handle the purge request.
	 *
	 * @return void
	 */
	public function handle_purge() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'wp-cwv-speed' ) );
		}
		check_admin_referer( 'cwv_speed_purge' );
		$this->purge();
		$redirect = wp_get_referer();
		if ( ! $redirect ) {
			$redirect = admin_url( 'admin.php?page=cwv-speed' );
		}
		wp_safe_redirect( add_query_arg( 'cwv_purged', '1', $redirect ) );
		exit;
	}

	/**
	 * Number of cached HTML files.
	 *
	 * @return int
	 */
	public function page_count() {
		$files = glob( self::dir() . '/pages/*.html' );
		return is_array( $files ) ? count( $files ) : 0;
	}

	/**
	 * Send a debug header when headers are still open.
	 *
	 * @param string $value HIT, MISS, or BYPASS.
	 * @return void
	 */
	private function mark( $value ) {
		if ( ! headers_sent() ) {
			header( 'X-CWV-Cache: ' . $value );
		}
	}

	/**
	 * Cache file for the current front-end request.
	 *
	 * @return string
	 */
	private function file_for_request() {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '/';
		$path = parse_url( $uri, PHP_URL_PATH );
		$path = is_string( $path ) && '' !== $path ? $path : '/';
		$host = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( (string) $_SERVER['HTTP_HOST'] ) : 'localhost';
		if ( 1 === substr_count( $host, ':' ) ) {
			$host = (string) strstr( $host, ':', true );
		}
		$mobile = '';
		if ( CWV_Speed_Settings::instance()->enabled( 'cache_mobile' ) ) {
			$agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
			if ( preg_match( '/Mobile|Android|iPhone|iPod|webOS|BlackBerry|IEMobile|Opera Mini/i', $agent ) ) {
				$mobile = ':m';
			}
		}
		return self::dir() . '/pages/' . md5( $host . '|' . $path . $mobile ) . '.html';
	}

	/**
	 * Write config.php consumed by the early loader.
	 *
	 * @param bool $enabled Whether serving is enabled.
	 * @return void
	 */
	private function write_config( $enabled ) {
		$settings = CWV_Speed_Settings::instance();
		$config   = array(
			'version'         => CWV_SPEED_VERSION,
			'enabled'         => (bool) $enabled,
			'host'            => strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ),
			'ttl'             => (int) $settings->get( 'cache_ttl' ) * HOUR_IN_SECONDS,
			'separate_mobile' => (bool) $settings->enabled( 'cache_mobile' ),
			'gzip'            => (bool) $settings->enabled( 'gzip' ),
			'bypass_contains' => $this->bypass_needles(),
			'bypass_cookies'  => array(
				'wordpress_logged_in_',
				'wordpress_sec_',
				'wp-postpass_',
				'comment_author_',
				'woocommerce_items_in_cart',
				'woocommerce_cart_hash',
				'wp_woocommerce_session_',
				'wordpress_no_cache',
				'PHPSESSID',
			),
			'ignore_query'    => array(
				'utm_source',
				'utm_medium',
				'utm_campaign',
				'utm_content',
				'utm_term',
				'utm_id',
				'fbclid',
				'gclid',
				'gbraid',
				'wbraid',
				'msclkid',
				'mc_cid',
				'mc_eid',
				'_ga',
				'_gl',
				'ref',
				'yclid',
				'twclid',
				'li_fat_id',
				'igshid',
				'srsltid',
			),
		);

		$hash = md5( (string) wp_json_encode( $config ) );
		if ( get_option( 'cwv_speed_config_hash' ) === $hash && is_readable( self::dir() . '/config.php' ) ) {
			return;
		}

		wp_mkdir_p( self::dir() );
		$php = "<?php\nif ( ! defined( 'ABSPATH' ) && ! defined( 'CWV_SPEED_CACHE_LOADER' ) ) {\n\texit;\n}\nreturn " . var_export( $config, true ) . ";\n";
		file_put_contents( self::dir() . '/config.php', $php, LOCK_EX );
		if ( function_exists( 'opcache_invalidate' ) ) {
			opcache_invalidate( self::dir() . '/config.php', true );
		}
		update_option( 'cwv_speed_config_hash', $hash, false );
	}

	/**
	 * URI fragments that must never be served from cache.
	 *
	 * @return string[]
	 */
	private function bypass_needles() {
		$needles = array(
			'/wp-admin',
			'/wp-login.php',
			'/wp-json/',
			'/xmlrpc.php',
			'/wp-cron.php',
			'/wp-comments-post.php',
			'admin-ajax.php',
			'wc-ajax=',
			'rest_route=',
			'/wp-sitemap',
			'cwv_nocache=',
		);

		if ( function_exists( 'wc_get_page_permalink' ) ) {
			foreach ( array( 'cart', 'checkout', 'myaccount' ) as $page ) {
				$url  = wc_get_page_permalink( $page );
				$path = is_string( $url ) ? wp_parse_url( $url, PHP_URL_PATH ) : '';
				if ( is_string( $path ) && '' !== $path && '/' !== $path ) {
					$needles[] = untrailingslashit( $path );
				}
			}
		} else {
			$needles[] = '/cart';
			$needles[] = '/checkout';
			$needles[] = '/my-account';
		}

		return array_values( array_unique( $needles ) );
	}

	/**
	 * Install advanced-cache.php.
	 *
	 * @return void
	 */
	private function write_dropin() {
		$target = WP_CONTENT_DIR . '/advanced-cache.php';
		$loader = CWV_SPEED_DIR . 'includes/cache-loader.php';
		if ( is_readable( $target ) ) {
			$existing = file_get_contents( $target );
			if ( is_string( $existing ) && false === strpos( $existing, 'CWV Speed page cache drop-in' ) ) {
				set_transient( 'cwv_speed_dropin_blocked', 1, DAY_IN_SECONDS );
				return;
			}
		}
		delete_transient( 'cwv_speed_dropin_blocked' );

		$contents = "<?php\n/**\n * CWV Speed page cache drop-in.\n * Installed by the CWV Speed plugin. It is removed on deactivation.\n */\nif ( ! defined( 'ABSPATH' ) ) {\n\texit;\n}\nif ( ! defined( 'CWV_SPEED_CACHE_LOADER' ) ) {\n\tdefine( 'CWV_SPEED_CACHE_LOADER', true );\n}\n\$cwv_speed_loader = " . var_export( $loader, true ) . ";\nif ( is_readable( \$cwv_speed_loader ) ) {\n\trequire_once \$cwv_speed_loader;\n}\n";
		$current = is_readable( $target ) ? file_get_contents( $target ) : '';
		if ( $current !== $contents ) {
			file_put_contents( $target, $contents, LOCK_EX );
		}
	}

	/**
	 * Remove only our drop-in.
	 *
	 * @return void
	 */
	public function remove_dropin() {
		$target = WP_CONTENT_DIR . '/advanced-cache.php';
		if ( ! is_readable( $target ) ) {
			return;
		}
		$existing = file_get_contents( $target );
		if ( is_string( $existing ) && false !== strpos( $existing, 'CWV Speed page cache drop-in' ) ) {
			wp_delete_file( $target );
		}
	}

	/**
	 * Define WP_CACHE when the config file is writable and the constant is absent.
	 *
	 * @return bool
	 */
	public function ensure_wp_cache_constant() {
		$config = $this->wp_config_path();
		if ( '' === $config || ! is_readable( $config ) ) {
			return defined( 'WP_CACHE' ) && WP_CACHE;
		}

		$contents = file_get_contents( $config );
		if ( ! is_string( $contents ) ) {
			return false;
		}

		// WordPress defines WP_CACHE as false before plugins load when wp-config
		// does not set it. That runtime value is not an explicit opt-out.
		if ( preg_match( '/define\s*\(\s*[\'"]WP_CACHE[\'"]\s*,\s*true\s*\)/', $contents ) ) {
			return true;
		}
		if ( preg_match( '/define\s*\(\s*[\'"]WP_CACHE[\'"]\s*,\s*false\s*\)/', $contents ) ) {
			return false;
		}
		if ( ! is_writable( $config ) ) {
			return false;
		}

		$block  = "/* BEGIN CWV Speed */\ndefine( 'WP_CACHE', true ); // By CWV Speed.\n/* END CWV Speed */\n\n";
		$marker = "/* That's all, stop editing!";
		if ( false !== strpos( $contents, $marker ) ) {
			$contents = str_replace( $marker, $block . $marker, $contents );
		} else {
			$contents = $block . $contents;
		}

		return false !== file_put_contents( $config, $contents, LOCK_EX );
	}

	/**
	 * Whether wp-config.php enables the page-cache drop-in.
	 *
	 * The running request can still see WP_CACHE as false when the constant
	 * was added during this same request.
	 *
	 * @return bool
	 */
	public function config_has_wp_cache() {
		if ( defined( 'WP_CACHE' ) && WP_CACHE ) {
			return true;
		}
		$config = $this->wp_config_path();
		if ( '' === $config || ! is_readable( $config ) ) {
			return false;
		}
		$contents = file_get_contents( $config );
		return is_string( $contents ) && (bool) preg_match( '/define\s*\(\s*[\'"]WP_CACHE[\'"]\s*,\s*true\s*\)/', $contents );
	}

	/**
	 * Remove the constant block this plugin added.
	 *
	 * @return void
	 */
	public function remove_wp_cache_constant() {
		$config = $this->wp_config_path();
		if ( '' === $config || ! is_writable( $config ) ) {
			return;
		}
		$contents = file_get_contents( $config );
		if ( ! is_string( $contents ) || false === strpos( $contents, 'BEGIN CWV Speed' ) ) {
			return;
		}
		$updated = preg_replace( "/\\/\\* BEGIN CWV Speed \\*\\/.*?\\/\\* END CWV Speed \\*\\/\\n?/s", '', $contents );
		if ( is_string( $updated ) ) {
			file_put_contents( $config, $updated, LOCK_EX );
		}
	}

	/**
	 * Locate wp-config.php.
	 *
	 * @return string
	 */
	private function wp_config_path() {
		$candidates = array( ABSPATH . 'wp-config.php', dirname( ABSPATH ) . '/wp-config.php' );
		foreach ( $candidates as $path ) {
			if ( is_readable( $path ) ) {
				return $path;
			}
		}
		return '';
	}

	/**
	 * Create public and private cache folders.
	 *
	 * @return void
	 */
	private function protect_dirs() {
		foreach ( array( '', '/pages', '/min', '/fonts' ) as $folder ) {
			$dir = self::dir() . $folder;
			wp_mkdir_p( $dir );
			if ( ! is_file( $dir . '/index.php' ) ) {
				file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" );
			}
		}

		$deny = self::dir() . '/.htaccess';
		$rules = "<IfModule mod_authz_core.c>\n\t<FilesMatch \"^(config\\.php|.*\\.html|.*\\.gz|.*\\.br)$\">\n\t\tRequire all denied\n\t</FilesMatch>\n</IfModule>\n<IfModule !mod_authz_core.c>\n\t<FilesMatch \"^(config\\.php|.*\\.html|.*\\.gz|.*\\.br)$\">\n\t\tDeny from all\n\t</FilesMatch>\n</IfModule>\n";
		if ( ! is_file( $deny ) || file_get_contents( $deny ) !== $rules ) {
			file_put_contents( $deny, $rules );
		}
	}

	/**
	 * Delete files inside a directory.
	 *
	 * @param string $dir Directory.
	 * @return void
	 */
	private function empty_dir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$files = glob( trailingslashit( $dir ) . '*' );
		if ( ! is_array( $files ) ) {
			return;
		}
		foreach ( $files as $file ) {
			if ( is_file( $file ) && 'index.php' !== basename( $file ) ) {
				wp_delete_file( $file );
			}
		}
	}
}
