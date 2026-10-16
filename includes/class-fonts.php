<?php
/**
 * Font loading: display swap, self-hosted Google Fonts, and preloads.
 *
 * @package CWV_Speed
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Removes the Google Fonts round trip when self-hosting is enabled.
 */
class CWV_Speed_Fonts {

	/**
	 * Singleton.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Font stylesheet or file URLs discovered in this document.
	 *
	 * @var string[]
	 */
	public $preloads = array();

	/**
	 * Whether a Google Fonts stylesheet is still remote.
	 *
	 * @var bool
	 */
	public $needs_preconnect = false;

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
	 * Rewrite font tags in the document.
	 *
	 * @param string $html Document.
	 * @return string
	 */
	public function optimize_html( $html ) {
		$this->preloads         = array();
		$this->needs_preconnect = false;
		$settings               = CWV_Speed_Settings::instance();

		if ( $settings->enabled( 'self_host_google_fonts' ) ) {
			$html = preg_replace_callback(
				'#<link\b[^>]*>#i',
				array( $this, 'replace_google_link' ),
				$html
			);
			$html = preg_replace_callback(
				'#@import\s+url\(\s*[\'"]?(https://fonts\.googleapis\.com/css2?[^\'"\)]+)[\'"]?\s*\)\s*;?#i',
				array( $this, 'replace_google_import' ),
				(string) $html
			);
		} else {
			$html = preg_replace_callback(
				'#https://fonts\.googleapis\.com/css2?\?[^"\'\s>]+#i',
				array( $this, 'add_display_swap' ),
				(string) $html
			);
			if ( false !== stripos( (string) $html, 'fonts.googleapis.com' ) || false !== stripos( (string) $html, 'fonts.gstatic.com' ) ) {
				$this->needs_preconnect = true;
			}
		}

		if ( $settings->enabled( 'font_display_swap' ) ) {
			$html = preg_replace_callback(
				'#<style\b[^>]*>.*?</style>#is',
				static function ( $match ) {
					return CWV_Speed_Minifier::font_display( $match[0] );
				},
				(string) $html
			);
		}

		$manual = CWV_Speed_Settings::instance()->lines( (string) $settings->get( 'preload_fonts' ) );
		foreach ( $manual as $url ) {
			$this->preloads[] = $url;
		}
		$this->preloads = array_slice( array_values( array_unique( $this->preloads ) ), 0, 2 );

		return (string) $html;
	}

	/**
	 * Head tags for fonts.
	 *
	 * @return string
	 */
	public function head_markup() {
		$html = '';
		if ( $this->needs_preconnect ) {
			$html .= '<link rel="preconnect" href="https://fonts.googleapis.com">';
			$html .= '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>';
		}
		foreach ( $this->preloads as $url ) {
			$html .= '<link rel="preload" as="font" type="font/woff2" href="' . esc_url( $url ) . '" crossorigin>';
		}
		return $html;
	}

	/**
	 * Replace a Google Fonts stylesheet link.
	 *
	 * @param array<int, string> $match Match.
	 * @return string
	 */
	private function replace_google_link( $match ) {
		$tag = $match[0];
		if ( ! preg_match( '#href\s*=\s*(["\'])(https://fonts\.googleapis\.com/css2?[^"\']+)\1#i', $tag, $href ) ) {
			return $tag;
		}
		$css = $this->local_css( html_entity_decode( $href[2] ) );
		if ( '' === $css ) {
			$this->needs_preconnect = true;
			$fixed = $this->add_display_swap( array( html_entity_decode( $href[2] ) ) );
			return str_replace( $href[2], esc_attr( $fixed ), $tag );
		}
		return '<style class="cwv-speed-fonts">' . $css . '</style>';
	}

	/**
	 * Replace an @import of Google Fonts.
	 *
	 * @param array<int, string> $match Match.
	 * @return string
	 */
	private function replace_google_import( $match ) {
		$css = $this->local_css( html_entity_decode( $match[1] ) );
		if ( '' === $css ) {
			$this->needs_preconnect = true;
			return $match[0];
		}
		return $css;
	}

	/**
	 * Ensure display=swap is on a Google Fonts URL.
	 *
	 * @param array<int, string> $match Match.
	 * @return string
	 */
	private function add_display_swap( $match ) {
		$url = html_entity_decode( $match[0] );
		if ( false !== stripos( $url, 'display=' ) ) {
			return $match[0];
		}
		return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . 'display=swap';
	}

	/**
	 * Download, cache, and rewrite a Google Fonts stylesheet.
	 *
	 * @param string $url Stylesheet URL.
	 * @return string CSS, or empty when the download fails.
	 */
	private function local_css( $url ) {
		$url = $this->add_display_swap( array( $url ) );
		$key = md5( $url . '|' . CWV_SPEED_VERSION );
		$dir = CWV_Speed_Cache::dir() . '/fonts';
		$file = $dir . '/' . $key . '.css';
		if ( is_readable( $file ) ) {
			$cached = file_get_contents( $file );
			if ( is_string( $cached ) && '' !== $cached ) {
				$this->remember_preload( $cached );
				return $cached;
			}
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => 5,
				'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return '';
		}
		$css = (string) wp_remote_retrieve_body( $response );
		if ( '' === $css || false === stripos( $css, '@font-face' ) ) {
			return '';
		}

		if ( ! wp_mkdir_p( $dir ) ) {
			return '';
		}

		$css = preg_replace_callback(
			'#url\(\s*[\'"]?(https://fonts\.gstatic\.com/[^)\'"]+)[\'"]?\s*\)#i',
			function ( $match ) use ( $dir ) {
				$local = $this->download_font( $match[1], $dir );
				return $local ? 'url(' . esc_url( $local ) . ')' : $match[0];
			},
			$css
		);

		if ( ! is_string( $css ) ) {
			return '';
		}
		$css = CWV_Speed_Minifier::css( $css, true );
		file_put_contents( $file, $css, LOCK_EX );
		$this->remember_preload( $css );
		return $css;
	}

	/**
	 * Download one font file from fonts.gstatic.com.
	 *
	 * @param string $url Remote font.
	 * @param string $dir Destination directory.
	 * @return string Local URL or empty.
	 */
	private function download_font( $url, $dir ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( 'fonts.gstatic.com' !== $host ) {
			return '';
		}
		$path = wp_parse_url( $url, PHP_URL_PATH );
		$ext  = is_string( $path ) ? strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) : 'woff2';
		if ( ! in_array( $ext, array( 'woff2', 'woff', 'ttf' ), true ) ) {
			$ext = 'woff2';
		}
		$name = md5( $url ) . '.' . $ext;
		$file = $dir . '/' . $name;
		if ( ! is_file( $file ) ) {
			$response = wp_remote_get( $url, array( 'timeout' => 6 ) );
			if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
				return '';
			}
			$body = wp_remote_retrieve_body( $response );
			if ( ! is_string( $body ) || '' === $body ) {
				return '';
			}
			file_put_contents( $file, $body, LOCK_EX );
		}
		return content_url( 'cache/cwv-speed/fonts/' . $name );
	}

	/**
	 * Preload the first woff2 in a font stylesheet. One file is enough.
	 *
	 * @param string $css CSS.
	 * @return void
	 */
	private function remember_preload( $css ) {
		if ( $this->preloads ) {
			return;
		}
		$chosen   = '';
		$fallback = '';
		if ( preg_match_all( '/@font-face\s*\{[^}]*\}/i', $css, $blocks ) ) {
			foreach ( $blocks[0] as $block ) {
				if ( ! preg_match( '#https?://[^)\'"\s]+\.woff2#i', $block, $file ) ) {
					continue;
				}
				if ( '' === $fallback ) {
					$fallback = $file[0];
				}
				$latin   = false !== stripos( $block, 'U+0000-00FF' );
				$regular = preg_match( '/font-weight:\s*(?:400|normal)\b/i', $block );
				if ( $latin && $regular ) {
					$chosen = $file[0];
					break;
				}
				if ( $latin && '' === $chosen ) {
					$chosen = $file[0];
				}
			}
		}
		$url = '' !== $chosen ? $chosen : $fallback;
		if ( '' !== $url ) {
			$this->preloads[] = $url;
		}
	}
}
