<?php
/**
 * Plugin Name: CWV Speed
 * Description: Speeds up WordPress for Core Web Vitals and Lighthouse. Page cache, LCP preload, modern images, delayed third-party tags, and safe WordPress cleanup.
 * Version: 1.0.2
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Author: OG Web Solutions
 * Author URI: https://ogwebsolutions.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-cwv-speed
 *
 * @package CWV_Speed
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CWV_SPEED_VERSION', '1.0.2' );
define( 'CWV_SPEED_FILE', __FILE__ );
define( 'CWV_SPEED_DIR', plugin_dir_path( __FILE__ ) );
define( 'CWV_SPEED_URL', plugin_dir_url( __FILE__ ) );

require_once CWV_SPEED_DIR . 'includes/class-settings.php';
require_once CWV_SPEED_DIR . 'includes/class-minifier.php';
require_once CWV_SPEED_DIR . 'includes/class-cache.php';
require_once CWV_SPEED_DIR . 'includes/class-images.php';
require_once CWV_SPEED_DIR . 'includes/class-fonts.php';
require_once CWV_SPEED_DIR . 'includes/class-embeds.php';
require_once CWV_SPEED_DIR . 'includes/class-cleanup.php';
require_once CWV_SPEED_DIR . 'includes/class-server-rules.php';
require_once CWV_SPEED_DIR . 'includes/class-maintenance.php';
require_once CWV_SPEED_DIR . 'includes/class-optimizer.php';
require_once CWV_SPEED_DIR . 'includes/class-admin.php';
require_once CWV_SPEED_DIR . 'includes/class-plugin.php';

register_activation_hook( __FILE__, array( 'CWV_Speed_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'CWV_Speed_Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'CWV_Speed_Plugin', 'instance' ) );
