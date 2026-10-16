<?php
/**
 * Plugin bootstrap.
 *
 * @package CWV_Speed
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires the modules together.
 */
class CWV_Speed_Plugin {

	/**
	 * Singleton.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Boot the plugin once WordPress is ready.
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
	 * Register modules.
	 */
	private function __construct() {
		CWV_Speed_Cache::instance()->hooks();
		CWV_Speed_Cleanup::instance()->hooks();
		CWV_Speed_Images::instance()->hooks();
		CWV_Speed_Optimizer::instance()->hooks();
		CWV_Speed_Admin::instance()->hooks();
		CWV_Speed_Maintenance::instance()->hooks();

		add_filter( 'plugin_action_links_' . plugin_basename( CWV_SPEED_FILE ), array( $this, 'links' ) );
		add_action( 'init', array( $this, 'upgrade' ) );
	}

	/**
	 * Clear cached HTML after a plugin update so old markup is not replayed.
	 *
	 * @return void
	 */
	public function upgrade() {
		$seen = get_option( 'cwv_speed_version', '' );
		if ( CWV_SPEED_VERSION === $seen ) {
			return;
		}
		update_option( 'cwv_speed_version', CWV_SPEED_VERSION, true );
		if ( is_string( $seen ) && '' !== $seen ) {
			CWV_Speed_Cache::instance()->purge();
		}
	}

	/**
	 * Settings link on the plugins screen.
	 *
	 * @param string[] $links Existing links.
	 * @return string[]
	 */
	public function links( $links ) {
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'admin.php?page=cwv-speed' ) ) . '">' . esc_html__( 'Settings', 'wp-cwv-speed' ) . '</a>'
		);
		return $links;
	}

	/**
	 * Create cache files and default to the balanced preset.
	 *
	 * @return void
	 */
	public static function activate() {
		if ( false === get_option( 'cwv_speed_settings', false ) ) {
			update_option( 'cwv_speed_settings', CWV_Speed_Settings::defaults(), true );
		}
		CWV_Speed_Cache::instance()->sync();
		CWV_Speed_Server_Rules::instance()->sync();
	}

	/**
	 * Remove the drop-in and server rules. Settings are kept until uninstall.
	 *
	 * @return void
	 */
	public static function deactivate() {
		$cache = CWV_Speed_Cache::instance();
		$cache->remove_dropin();
		$cache->remove_wp_cache_constant();
		$cache->purge();
		CWV_Speed_Server_Rules::instance()->remove();
	}
}
