<?php
/**
 * Removes WordPress front-end work that does not help the page render.
 *
 * @package CWV_Speed
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Head cleanup, heartbeat control, and speculation rules.
 */
class CWV_Speed_Cleanup {

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
	 * Register cleanup hooks.
	 *
	 * @return void
	 */
	public function hooks() {
		add_action( 'init', array( $this, 'init' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'assets' ), 100 );
		add_filter( 'wp_default_scripts', array( $this, 'jquery_migrate' ) );
		add_filter( 'heartbeat_settings', array( $this, 'heartbeat' ) );
		add_filter( 'wp_revisions_to_keep', array( $this, 'revisions' ), 10, 2 );
		add_filter( 'wp_resource_hints', array( $this, 'resource_hints' ), 10, 2 );
		add_filter( 'wp_speculation_rules_configuration', array( $this, 'speculation' ) );
		add_filter( 'wp_speculation_rules_href_exclude_paths', array( $this, 'speculation_paths' ) );
	}

	/**
	 * Remove front-end head links and scripts.
	 *
	 * @return void
	 */
	public function init() {
		$settings = CWV_Speed_Settings::instance();

		if ( $settings->enabled( 'disable_emojis' ) && ! is_admin() ) {
			remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
			remove_action( 'wp_print_styles', 'print_emoji_styles' );
			remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
			remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
			add_filter( 'emoji_svg_url', '__return_false' );
		}

		if ( $settings->enabled( 'disable_embeds' ) ) {
			remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
			remove_action( 'wp_head', 'wp_oembed_add_host_js' );
		}

		if ( $settings->enabled( 'disable_rsd' ) ) {
			remove_action( 'wp_head', 'rsd_link' );
		}
		if ( $settings->enabled( 'disable_wlw' ) ) {
			remove_action( 'wp_head', 'wlwmanifest_link' );
		}
		if ( $settings->enabled( 'disable_generator' ) ) {
			remove_action( 'wp_head', 'wp_generator' );
		}
		if ( $settings->enabled( 'disable_shortlink' ) ) {
			remove_action( 'wp_head', 'wp_shortlink_wp_head' );
		}
		if ( $settings->enabled( 'disable_feeds' ) ) {
			remove_action( 'wp_head', 'feed_links', 2 );
			remove_action( 'wp_head', 'feed_links_extra', 3 );
		}

		if ( $settings->enabled( 'disable_xmlrpc' ) ) {
			add_filter( 'xmlrpc_enabled', '__return_false' );
			add_filter(
				'wp_headers',
				static function ( $headers ) {
					unset( $headers['X-Pingback'] );
					return $headers;
				}
			);
		}
	}

	/**
	 * Dequeue front-end assets that are safe to drop.
	 *
	 * @return void
	 */
	public function assets() {
		$settings = CWV_Speed_Settings::instance();

		if ( $settings->enabled( 'disable_embeds' ) ) {
			wp_deregister_script( 'wp-embed' );
		}

		if ( $settings->enabled( 'disable_heartbeat_front' ) && ! is_user_logged_in() ) {
			wp_deregister_script( 'heartbeat' );
		}

		if ( ! is_admin() ) {
			$this->footer_jquery();
		}

		if ( ! is_user_logged_in() ) {
			wp_deregister_style( 'dashicons' );
			wp_dequeue_style( 'dashicons' );
			$this->dequeue_guest_admin_styles();
		}

		if ( $settings->enabled( 'disable_cart_fragments' ) && function_exists( 'is_cart' ) && ! is_cart() && ! is_checkout() && ! is_account_page() ) {
			wp_dequeue_script( 'wc-cart-fragments' );
		}
	}

	/**
	 * Print jQuery with the footer scripts so it does not block first paint.
	 *
	 * @return void
	 */
	private function footer_jquery() {
		$scripts = wp_scripts();
		foreach ( array( 'jquery', 'jquery-core', 'jquery-migrate' ) as $handle ) {
			$scripts->add_data( $handle, 'group', 1 );
		}
	}

	/**
	 * Drop admin-only stylesheets that logged-out visitors never see.
	 *
	 * @return void
	 */
	private function dequeue_guest_admin_styles() {
		$styles = wp_styles();
		foreach ( (array) $styles->queue as $handle ) {
			if ( ! isset( $styles->registered[ $handle ] ) ) {
				continue;
			}
			$src = (string) $styles->registered[ $handle ]->src;
			$hay = $handle . ' ' . $src;
			$admin = false !== stripos( $hay, 'admin-bar' ) || false !== stripos( $hay, 'dashicons' );
			$kit   = false !== stripos( $hay, 'googlesitekit' ) && false !== stripos( $hay, 'admin' );
			if ( $admin || $kit ) {
				wp_dequeue_style( $handle );
			}
		}
	}

	/**
	 * Drop jQuery Migrate from the public jQuery bundle.
	 *
	 * @param WP_Scripts $scripts Scripts registry.
	 * @return void
	 */
	public function jquery_migrate( $scripts ) {
		if ( is_admin() || ! CWV_Speed_Settings::instance()->enabled( 'disable_jquery_migrate' ) ) {
			return;
		}
		if ( isset( $scripts->registered['jquery'] ) && $scripts->registered['jquery'] instanceof _WP_Dependency ) {
			$scripts->registered['jquery']->deps = array_values(
				array_diff( $scripts->registered['jquery']->deps, array( 'jquery-migrate' ) )
			);
		}
	}

	/**
	 * Slow the admin heartbeat so it is not a constant request.
	 *
	 * @param array<string, mixed> $settings Heartbeat settings.
	 * @return array<string, mixed>
	 */
	public function heartbeat( $settings ) {
		if ( CWV_Speed_Settings::instance()->enabled( 'slow_heartbeat' ) ) {
			$settings['interval'] = 60;
		}
		return $settings;
	}

	/**
	 * Cap stored revisions.
	 *
	 * @param int     $number Current cap.
	 * @param WP_Post $post Post object.
	 * @return int
	 */
	public function revisions( $number, $post ) {
		unset( $post );
		if ( ! CWV_Speed_Settings::instance()->enabled( 'limit_revisions' ) ) {
			return $number;
		}
		return (int) CWV_Speed_Settings::instance()->get( 'revision_max' );
	}

	/**
	 * Drop the emoji DNS prefetch after emoji scripts are removed.
	 *
	 * @param array<int, mixed> $urls URLs.
	 * @param string            $relation Hint relation.
	 * @return array<int, mixed>
	 */
	public function resource_hints( $urls, $relation ) {
		if ( 'dns-prefetch' !== $relation || ! CWV_Speed_Settings::instance()->enabled( 'disable_emojis' ) ) {
			return $urls;
		}
		return array_values(
			array_filter(
				$urls,
				static function ( $url ) {
					return ! is_string( $url ) || false === strpos( $url, 's.w.org' );
				}
			)
		);
	}

	/**
	 * Raise speculative loading above the core default when the preset asks for it.
	 *
	 * @param array<string, string>|null $config Core configuration.
	 * @return array<string, string>|null
	 */
	public function speculation( $config ) {
		$mode = (string) CWV_Speed_Settings::instance()->get( 'speculation' );
		if ( 'core' === $mode ) {
			return $config;
		}
		if ( 'off' === $mode ) {
			return null;
		}
		$map = array(
			'prefetch-conservative' => array( 'prefetch', 'conservative' ),
			'prerender-moderate'    => array( 'prerender', 'moderate' ),
			'prerender-eager'       => array( 'prerender', 'eager' ),
		);
		if ( ! isset( $map[ $mode ] ) ) {
			return $config;
		}
		return array(
			'mode'      => $map[ $mode ][0],
			'eagerness' => $map[ $mode ][1],
		);
	}

	/**
	 * Keep cart, checkout, and account pages out of prerender.
	 *
	 * @param string[] $paths Exclude paths.
	 * @return string[]
	 */
	public function speculation_paths( $paths ) {
		$extra = array( '/cart', '/cart/*', '/checkout', '/checkout/*', '/my-account', '/my-account/*' );
		if ( function_exists( 'wc_get_page_id' ) ) {
			$home = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
			$home = is_string( $home ) ? untrailingslashit( $home ) : '';
			foreach ( array( 'cart', 'checkout', 'myaccount' ) as $page ) {
				$id = (int) wc_get_page_id( $page );
				if ( $id < 1 ) {
					continue;
				}
				$permalink = get_permalink( $id );
				$path      = is_string( $permalink ) ? wp_parse_url( $permalink, PHP_URL_PATH ) : '';
				if ( ! is_string( $path ) || '' === $path ) {
					continue;
				}
				if ( '' !== $home && 0 === strpos( $path, $home ) ) {
					$path = substr( $path, strlen( $home ) );
				}
				$path = '/' . ltrim( untrailingslashit( $path ), '/' );
				if ( '/' !== $path ) {
					$extra[] = $path;
					$extra[] = $path . '/*';
				}
			}
		}
		return array_values( array_unique( array_merge( $paths, $extra ) ) );
	}
}
