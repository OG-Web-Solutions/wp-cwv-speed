<?php
/**
 * Early page-cache reader.
 *
 * Loaded from the advanced-cache drop-in before plugins boot.
 * Keep this file free of WordPress APIs.
 *
 * @package CWV_Speed
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'CWV_SPEED_CACHE_LOADER' ) ) {
	define( 'CWV_SPEED_CACHE_LOADER', true );
}

/**
 * Serve a cached HTML document and stop WordPress when the request is public.
 *
 * @return void
 */
function cwv_speed_cache_serve() {
	if ( 'cli' === PHP_SAPI ) {
		return;
	}

	$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : 'GET';
	if ( 'GET' !== $method && 'HEAD' !== $method ) {
		return;
	}

	$config_file = WP_CONTENT_DIR . '/cache/cwv-speed/config.php';
	if ( ! is_readable( $config_file ) ) {
		return;
	}

	$config = include $config_file;
	if ( ! is_array( $config ) || empty( $config['enabled'] ) ) {
		return;
	}

	$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '/';
	$path = parse_url( $uri, PHP_URL_PATH );
	$path = is_string( $path ) && '' !== $path ? $path : '/';

	if ( cwv_speed_cache_bypass( $path, $uri, $config ) ) {
		return;
	}

	$host = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( (string) $_SERVER['HTTP_HOST'] ) : '';
	if ( 1 === substr_count( $host, ':' ) ) {
		$host = (string) strstr( $host, ':', true );
	}
	$expected = isset( $config['host'] ) ? strtolower( (string) $config['host'] ) : '';
	if ( '' !== $expected && $host !== $expected ) {
		return;
	}
	$mobile = '';
	if ( ! empty( $config['separate_mobile'] ) ) {
		$agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
		if ( preg_match( '/Mobile|Android|iPhone|iPod|webOS|BlackBerry|IEMobile|Opera Mini/i', $agent ) ) {
			$mobile = ':m';
		}
	}

	$key  = md5( $host . '|' . $path . $mobile );
	$file = WP_CONTENT_DIR . '/cache/cwv-speed/pages/' . $key . '.html';
	if ( ! is_file( $file ) ) {
		return;
	}

	$ttl = isset( $config['ttl'] ) ? (int) $config['ttl'] : 0;
	if ( $ttl > 0 && ( time() - (int) filemtime( $file ) ) > $ttl ) {
		return;
	}

	$accept = isset( $_SERVER['HTTP_ACCEPT_ENCODING'] ) ? (string) $_SERVER['HTTP_ACCEPT_ENCODING'] : '';
	$body   = $file;
	$encode = '';
	if ( ! empty( $config['gzip'] ) ) {
		if ( false !== strpos( $accept, 'br' ) && is_file( $file . '.br' ) ) {
			$body   = $file . '.br';
			$encode = 'br';
		} elseif ( false !== strpos( $accept, 'gzip' ) && is_file( $file . '.gz' ) ) {
			$body   = $file . '.gz';
			$encode = 'gzip';
		}
	}

	if ( function_exists( 'ini_set' ) ) {
		ini_set( 'zlib.output_compression', '0' );
	}

	if ( ! headers_sent() ) {
		header( 'Content-Type: text/html; charset=UTF-8' );
		header( 'Cache-Control: public, max-age=0, must-revalidate' );
		header( 'X-CWV-Cache: HIT' );
		header( 'Vary: Accept-Encoding' );
		if ( '' !== $encode ) {
			header( 'Content-Encoding: ' . $encode );
		}
		header( 'Content-Length: ' . (string) filesize( $body ) );
	}

	if ( 'HEAD' !== $method ) {
		readfile( $body );
	}
	exit;
}

/**
 * Whether this request must reach WordPress.
 *
 * @param string               $path Request path.
 * @param string               $uri Full request URI.
 * @param array<string, mixed> $config Cache config.
 * @return bool
 */
function cwv_speed_cache_bypass( $path, $uri, array $config ) {
	$cache_control = isset( $_SERVER['HTTP_CACHE_CONTROL'] ) ? (string) $_SERVER['HTTP_CACHE_CONTROL'] : '';
	$pragma        = isset( $_SERVER['HTTP_PRAGMA'] ) ? (string) $_SERVER['HTTP_PRAGMA'] : '';
	if ( false !== strpos( $cache_control, 'no-cache' ) || false !== strpos( $pragma, 'no-cache' ) ) {
		return true;
	}

	if ( preg_match( '/\.(?:xml|xsl|json|txt|ico|gz|br|map)$/i', $path ) ) {
		return true;
	}
	if ( preg_match( '/\.php$/i', $path ) && ! preg_match( '#/index\.php$#i', $path ) ) {
		return true;
	}

	$needles = isset( $config['bypass_contains'] ) && is_array( $config['bypass_contains'] ) ? $config['bypass_contains'] : array();
	foreach ( $needles as $needle ) {
		$needle = (string) $needle;
		if ( '' !== $needle && false !== strpos( $uri, $needle ) ) {
			return true;
		}
	}

	if ( preg_match( '#/(?:feed|comments/feed)/?$#', $path ) ) {
		return true;
	}

	$cookies = isset( $config['bypass_cookies'] ) && is_array( $config['bypass_cookies'] ) ? $config['bypass_cookies'] : array();
	if ( ! empty( $_COOKIE ) && $cookies ) {
		foreach ( array_keys( $_COOKIE ) as $name ) {
			foreach ( $cookies as $prefix ) {
				$prefix = (string) $prefix;
				if ( '' !== $prefix && 0 === strpos( (string) $name, $prefix ) ) {
					return true;
				}
			}
		}
	}

	$query = parse_url( $uri, PHP_URL_QUERY );
	if ( ! is_string( $query ) || '' === $query ) {
		return false;
	}

	$args = array();
	parse_str( $query, $args );
	$ignored = isset( $config['ignore_query'] ) && is_array( $config['ignore_query'] ) ? $config['ignore_query'] : array();
	foreach ( array_keys( $args ) as $key ) {
		if ( ! in_array( (string) $key, $ignored, true ) ) {
			return true;
		}
	}

	return false;
}

cwv_speed_cache_serve();
