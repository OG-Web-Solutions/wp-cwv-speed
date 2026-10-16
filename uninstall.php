<?php
/**
 * Remove CWV Speed data when the plugin is deleted.
 *
 * @package CWV_Speed
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'cwv_speed_settings' );
delete_option( 'cwv_speed_config_hash' );

$config = WP_CONTENT_DIR . '/advanced-cache.php';
if ( is_readable( $config ) ) {
	$contents = file_get_contents( $config );
	if ( is_string( $contents ) && false !== strpos( $contents, 'CWV Speed page cache drop-in' ) ) {
		wp_delete_file( $config );
	}
}

$roots = array( ABSPATH . 'wp-config.php', dirname( ABSPATH ) . '/wp-config.php' );
foreach ( $roots as $root ) {
	if ( ! is_readable( $root ) || ! is_writable( $root ) ) {
		continue;
	}
	$contents = file_get_contents( $root );
	if ( ! is_string( $contents ) || false === strpos( $contents, 'BEGIN CWV Speed' ) ) {
		continue;
	}
	$updated = preg_replace( "/\\/\\* BEGIN CWV Speed \\*\\/.*?\\/\\* END CWV Speed \\*\\/\\n?/s", '', $contents );
	if ( is_string( $updated ) ) {
		file_put_contents( $root, $updated );
	}
	break;
}

$htaccess = ABSPATH . '.htaccess';
if ( is_readable( $htaccess ) && is_writable( $htaccess ) ) {
	$contents = file_get_contents( $htaccess );
	if ( is_string( $contents ) && false !== strpos( $contents, 'BEGIN CWV Speed' ) ) {
		$updated = preg_replace( "/# BEGIN CWV Speed.*?# END CWV Speed\\n?/s", '', $contents );
		if ( is_string( $updated ) ) {
			file_put_contents( $htaccess, $updated );
		}
	}
}

$cache = WP_CONTENT_DIR . '/cache/cwv-speed';
if ( is_dir( $cache ) ) {
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $cache, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $iterator as $file ) {
		if ( $file->isDir() ) {
			rmdir( $file->getPathname() );
		} else {
			wp_delete_file( $file->getPathname() );
		}
	}
	rmdir( $cache );
}
