<?php
/**
 * Apache cache headers and a copy-ready nginx snippet.
 *
 * @package CWV_Speed
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Long-cache static assets and compress text responses.
 */
class CWV_Speed_Server_Rules {

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
	 * Write or remove the Apache block to match the setting.
	 *
	 * @return void
	 */
	public function sync() {
		if ( ! $this->htaccess_writable() ) {
			return;
		}
		if ( CWV_Speed_Settings::instance()->enabled( 'browser_cache' ) ) {
			$this->install();
			return;
		}
		$this->remove();
	}

	/**
	 * Nginx rules the admin can copy. Nginx ignores .htaccess.
	 *
	 * @return string
	 */
	public function nginx_snippet() {
		return <<<'NGINX'
location ~* \.(?:css|js|mjs|jpg|jpeg|png|gif|webp|avif|svg|ico|woff|woff2)$ {
	expires 1y;
	add_header Cache-Control "public, max-age=31536000";
	access_log off;
	try_files $uri =404;
}
gzip on;
gzip_comp_level 6;
gzip_min_length 256;
gzip_types text/plain text/css text/javascript application/javascript application/json image/svg+xml application/xml;
NGINX;
	}

	/**
	 * Whether Apache rules can be written.
	 *
	 * @return bool
	 */
	public function htaccess_writable() {
		$path = ABSPATH . '.htaccess';
		if ( is_writable( $path ) ) {
			return true;
		}
		$apache = isset( $_SERVER['SERVER_SOFTWARE'] ) && false !== stripos( (string) $_SERVER['SERVER_SOFTWARE'], 'apache' );
		return $apache && is_writable( ABSPATH );
	}

	/**
	 * Insert the marked block.
	 *
	 * @return void
	 */
	private function install() {
		$path     = ABSPATH . '.htaccess';
		$contents = is_readable( $path ) ? (string) file_get_contents( $path ) : '';
		$block    = $this->block();
		if ( false !== strpos( $contents, '# BEGIN CWV Speed' ) ) {
			$updated = preg_replace( '/# BEGIN CWV Speed.*?# END CWV Speed\n?/s', $block, $contents );
		} else {
			$updated = $block . $contents;
		}
		if ( is_string( $updated ) && $updated !== $contents ) {
			file_put_contents( $path, $updated, LOCK_EX );
		}
	}

	/**
	 * Remove the marked block.
	 *
	 * @return void
	 */
	public function remove() {
		$path = ABSPATH . '.htaccess';
		if ( ! is_readable( $path ) || ! is_writable( $path ) ) {
			return;
		}
		$contents = (string) file_get_contents( $path );
		if ( false === strpos( $contents, '# BEGIN CWV Speed' ) ) {
			return;
		}
		$updated = preg_replace( '/# BEGIN CWV Speed.*?# END CWV Speed\n?/s', '', $contents );
		if ( is_string( $updated ) ) {
			file_put_contents( $path, $updated, LOCK_EX );
		}
	}

	/**
	 * Apache rules.
	 *
	 * @return string
	 */
	private function block() {
		return <<<'HTACCESS'
# BEGIN CWV Speed
<IfModule mod_expires.c>
	ExpiresActive On
	ExpiresByType text/html "access plus 0 seconds"
	ExpiresByType text/css "access plus 1 year"
	ExpiresByType text/javascript "access plus 1 year"
	ExpiresByType application/javascript "access plus 1 year"
	ExpiresByType application/json "access plus 0 seconds"
	ExpiresByType image/jpeg "access plus 1 year"
	ExpiresByType image/png "access plus 1 year"
	ExpiresByType image/gif "access plus 1 year"
	ExpiresByType image/webp "access plus 1 year"
	ExpiresByType image/avif "access plus 1 year"
	ExpiresByType image/svg+xml "access plus 1 year"
	ExpiresByType image/x-icon "access plus 1 year"
	ExpiresByType font/woff2 "access plus 1 year"
	ExpiresByType font/woff "access plus 1 year"
	ExpiresByType application/font-woff2 "access plus 1 year"
</IfModule>
<IfModule mod_deflate.c>
	AddOutputFilterByType DEFLATE text/html text/plain text/css text/javascript application/javascript application/json image/svg+xml application/xml
</IfModule>
<IfModule mod_headers.c>
	<FilesMatch "\.(css|js|mjs|webp|avif|woff2|woff|jpg|jpeg|png|gif|svg|ico)$">
		Header set Cache-Control "public, max-age=31536000"
	</FilesMatch>
	<FilesMatch "\.(html|htm)$">
		Header set Cache-Control "public, max-age=0, must-revalidate"
	</FilesMatch>
</IfModule>
# END CWV Speed

HTACCESS;
	}
}
