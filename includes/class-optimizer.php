<?php
/**
 * Front-end HTML pipeline.
 *
 * @package CWV_Speed
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Defers assets, rewrites the document, and stores the page cache.
 */
class CWV_Speed_Optimizer {

	/**
	 * Singleton.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Whether a script was converted to a delayed script.
	 *
	 * @var bool
	 */
	private $delayed = false;

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
	 * Register output and asset hooks.
	 *
	 * @return void
	 */
	public function hooks() {
		add_action( 'template_redirect', array( $this, 'start' ), 0 );
		add_filter( 'style_loader_src', array( $this, 'minify_style_src' ), 20, 2 );
		add_filter( 'style_loader_src', array( $this, 'prepare_font_css' ), 21, 2 );
		add_filter( 'script_loader_src', array( $this, 'minify_script_src' ), 20, 2 );
	}

	/**
	 * Start the document buffer for anonymous front-end requests.
	 *
	 * @return void
	 */
	public function start() {
		if ( is_admin() || is_feed() || is_preview() || is_customize_preview() || is_embed() || is_robots() ) {
			return;
		}
		if ( wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		if ( ! $this->should_buffer() ) {
			return;
		}
		ob_start( array( $this, 'process' ) );
	}

	/**
	 * Transform the buffered document.
	 *
	 * @param string $html Original HTML.
	 * @return string
	 */
	public function process( $html ) {
		$this->delayed = false;
		if ( ! is_string( $html ) || false === stripos( $html, '<html' ) ) {
			return is_string( $html ) ? $html : '';
		}

		try {
			$optimized = $this->transform( $html );
		} catch ( Throwable $error ) {
			unset( $error );
			$optimized = $html;
		}

		if ( ! is_string( $optimized ) || false === stripos( $optimized, '<html' ) ) {
			$optimized = $html;
		}

		CWV_Speed_Cache::instance()->store( $optimized );
		return $optimized;
	}

	/**
	 * Minify a local stylesheet URL.
	 *
	 * @param string $src Source URL.
	 * @param string $handle Style handle.
	 * @return string
	 */
	public function minify_style_src( $src, $handle ) {
		if ( is_admin() || ! is_string( $src ) ) {
			return $src;
		}
		if ( ! CWV_Speed_Settings::instance()->enabled( 'minify_css' ) ) {
			return $src;
		}
		$settings = CWV_Speed_Settings::instance();
		if ( $this->excluded( $handle . ' ' . $src, $settings->lines( (string) $settings->get( 'css_excludes' ) ) ) ) {
			return $src;
		}
		return $this->minify_local( $src, 'css' );
	}

	/**
	 * Minify a local script URL.
	 *
	 * @param string $src Source URL.
	 * @param string $handle Script handle.
	 * @return string
	 */
	public function minify_script_src( $src, $handle ) {
		unset( $handle );
		if ( is_admin() || ! is_string( $src ) || ! CWV_Speed_Settings::instance()->enabled( 'minify_js' ) ) {
			return $src;
		}
		return $this->minify_local( $src, 'js' );
	}

	/**
	 * Whether any HTML feature needs the buffer.
	 *
	 * @return bool
	 */
	private function should_buffer() {
		$settings = CWV_Speed_Settings::instance();
		$keys     = array(
			'page_cache',
			'minify_html',
			'defer_css',
			'defer_js',
			'lazy_images',
			'lcp_priority',
			'lcp_preload',
			'image_dimensions',
			'missing_sizes',
			'webp',
			'avif',
			'iframe_lazy',
			'video_facade',
			'font_display_swap',
			'self_host_google_fonts',
			'content_visibility',
		);
		foreach ( $keys as $key ) {
			if ( $settings->enabled( $key ) ) {
				return true;
			}
		}
		if ( 'off' !== (string) $settings->get( 'delay_js' ) ) {
			return true;
		}
		return '' !== (string) $settings->get( 'critical_css' ) || '' !== (string) $settings->get( 'lcp_image_url' ) || '' !== (string) $settings->get( 'preload_fonts' );
	}

	/**
	 * Run the HTML passes in an order that keeps LCP discovery intact.
	 *
	 * @param string $html Document.
	 * @return string
	 */
	private function transform( $html ) {
		$settings = CWV_Speed_Settings::instance();

		if ( $settings->enabled( 'video_facade' ) || $settings->enabled( 'iframe_lazy' ) ) {
			$html = CWV_Speed_Embeds::instance()->optimize_html( $html );
		}
		if ( $settings->enabled( 'self_host_google_fonts' ) || $settings->enabled( 'font_display_swap' ) || '' !== (string) $settings->get( 'preload_fonts' ) ) {
			$html = CWV_Speed_Fonts::instance()->optimize_html( $html );
		}
		$html = CWV_Speed_Images::instance()->optimize_html( $html );
		$html = $this->optimize_scripts( $html );
		$html = $this->defer_plugin_css( $html );
		if ( $settings->enabled( 'defer_css' ) && '' !== trim( (string) $settings->get( 'critical_css' ) ) ) {
			$html = $this->defer_css( $html );
		}

		$head = CWV_Speed_Fonts::instance()->head_markup();
		$head .= CWV_Speed_Images::instance()->preload;
		$head .= $this->lcp_preconnect();
		$head .= CWV_Speed_Embeds::instance()->head_css();
		if ( '' !== trim( (string) $settings->get( 'critical_css' ) ) ) {
			$head .= '<style id="cwv-speed-critical">' . $settings->get( 'critical_css' ) . '</style>';
		}
		if ( $settings->enabled( 'content_visibility' ) ) {
			$head .= '<style id="cwv-speed-cv">footer,.site-footer,#colophon,#comments,.wp-block-comments{content-visibility:auto;contain-intrinsic-size:auto 520px}</style>';
		}
		if ( '' !== $head ) {
			$html = (string) preg_replace( '/<head\b[^>]*>/i', '$0' . $head, $html, 1 );
		}

		$footer = CWV_Speed_Embeds::instance()->footer_script();
		if ( $this->delayed ) {
			$footer .= $this->delay_loader();
		}
		if ( '' !== $footer && false !== stripos( $html, '</body>' ) ) {
			$html = (string) preg_replace( '/<\/body>/i', $footer . '</body>', $html, 1 );
		} elseif ( '' !== $footer ) {
			$html .= $footer;
		}

		if ( $settings->enabled( 'minify_html' ) ) {
			$html = CWV_Speed_Minifier::html( $html );
		}

		return $html;
	}

	/**
	 * Preconnect the LCP image host when it is not this site.
	 *
	 * @return string
	 */
	private function lcp_preconnect() {
		$preload = CWV_Speed_Images::instance()->preload;
		if ( ! preg_match( '/\shref="([^"]+)"/', $preload, $match ) ) {
			return '';
		}
		$host = wp_parse_url( $match[1], PHP_URL_HOST );
		$home = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( ! is_string( $host ) || '' === $host || $host === $home ) {
			return '';
		}
		return '<link rel="preconnect" href="' . esc_url( 'https://' . $host ) . '">';
	}

	/**
	 * Delay or defer scripts.
	 *
	 * @param string $html Document.
	 * @return string
	 */
	private function optimize_scripts( $html ) {
		$settings = CWV_Speed_Settings::instance();
		$delay    = (string) $settings->get( 'delay_js' );
		$defer    = $settings->enabled( 'defer_js' );
		if ( 'off' === $delay && ! $defer ) {
			return $html;
		}

		$updated = preg_replace_callback(
			'#<script\b([^>]*)>(.*?)</script>#is',
			function ( $match ) use ( $delay, $defer, $settings ) {
				return $this->rewrite_script( $match[0], $match[1], $match[2], $delay, $defer, $settings );
			},
			$html
		);

		return is_string( $updated ) ? $updated : $html;
	}

	/**
	 * Rewrite one script tag.
	 *
	 * @param string             $tag Full tag.
	 * @param string             $attrs Attribute string.
	 * @param string             $code Inline code.
	 * @param string             $delay Delay mode.
	 * @param bool               $defer Whether to add defer.
	 * @param CWV_Speed_Settings $settings Settings.
	 * @return string
	 */
	private function rewrite_script( $tag, $attrs, $code, $delay, $defer, $settings ) {
		$type = strtolower( (string) $this->attr( $attrs, 'type' ) );
		$skip = array( 'application/ld+json', 'application/json', 'importmap', 'speculationrules', 'module', 'text/template', 'text/cwvdelay', 'text/html' );
		if ( '' !== $type && ! in_array( $type, array( 'text/javascript', 'application/javascript', 'application/ecmascript', 'text/ecmascript' ), true ) ) {
			if ( in_array( $type, $skip, true ) || '' !== $type ) {
				return $tag;
			}
		}
		if ( false !== stripos( $attrs, 'data-cwv-skip' ) || false !== stripos( $attrs, 'data-no-optimize' ) || false !== stripos( $tag, 'cwv-speed-facade-js' ) ) {
			return $tag;
		}

		$src      = (string) $this->attr( $attrs, 'src' );
		$identity = $src . ' ' . $this->attr( $attrs, 'id' ) . ' ' . substr( $code, 0, 180 );
		$excluded = $this->excluded( $identity, $this->script_excludes( $settings ) );

		if ( 'off' !== $delay && ! $excluded && $this->should_delay( $src, $code, $delay, $settings ) ) {
			$this->delayed = true;
			return $this->delay_tag( $tag );
		}

		if ( $defer && '' !== $src && ! $excluded && ! preg_match( '/\s(?:defer|async)(?:\s|=|>|$)/i', $attrs ) ) {
			$updated = preg_replace( '/<script\b/i', '<script defer', $tag, 1 );
			return is_string( $updated ) ? $updated : $tag;
		}

		return $tag;
	}

	/**
	 * Whether a script belongs to the delay list.
	 *
	 * @param string             $src Script URL.
	 * @param string             $code Inline source.
	 * @param string             $mode Delay mode.
	 * @param CWV_Speed_Settings $settings Settings.
	 * @return bool
	 */
	private function should_delay( $src, $code, $mode, $settings ) {
		if ( 'all' === $mode ) {
			return true;
		}
		if ( 'third-party' !== $mode || '' === $src ) {
			return false;
		}
		foreach ( $settings->lines( (string) $settings->get( 'delay_patterns' ) ) as $pattern ) {
			if ( false !== stripos( $src, $pattern ) ) {
				return true;
			}
		}
		unset( $code );
		return false;
	}

	/**
	 * Convert a script so the browser will not execute it yet.
	 *
	 * @param string $tag Script tag.
	 * @return string
	 */
	private function delay_tag( $tag ) {
		$updated = preg_replace( '/\stype\s*=\s*(["\']).*?\1/i', '', $tag, 1 );
		$updated = preg_replace( '/<script\b/i', '<script type="text/cwvdelay"', (string) $updated, 1 );
		$updated = preg_replace_callback(
			'/\ssrc\s*=\s*(["\'])([^"\']+)\1/i',
			static function ( $match ) {
				return ' data-cwv-src="' . esc_attr( $match[2] ) . '"';
			},
			(string) $updated,
			1
		);
		return is_string( $updated ) ? $updated : $tag;
	}

	/**
	 * Small loader that restores delayed scripts after the first interaction.
	 *
	 * @return string
	 */
	private function delay_loader() {
		$timeout = (int) CWV_Speed_Settings::instance()->get( 'delay_timeout' ) * 1000;
		$script  = <<<'JS'
(function(){
	var fired=false,events=["pointerdown","keydown","touchstart","scroll","wheel"];
	var opts={passive:true,capture:true};
	function run(){
		if(fired){return;}
		fired=true;
		for(var i=0;i<events.length;i++){window.removeEventListener(events[i],run,opts);}
		var nodes=document.querySelectorAll('script[type="text/cwvdelay"]'),index=0;
		function next(){
			if(index>=nodes.length){return;}
			var node=nodes[index++],script=document.createElement("script"),src=node.getAttribute("data-cwv-src"),a;
			for(a=0;a<node.attributes.length;a++){
				var attr=node.attributes[a];
				if(attr.name==="type"||attr.name==="data-cwv-src"){continue;}
				script.setAttribute(attr.name,attr.value);
			}
			if(src){script.async=false;script.src=src;script.onload=script.onerror=next;node.parentNode.replaceChild(script,node);}
			else{script.text=node.text||node.textContent||"";node.parentNode.replaceChild(script,node);next();}
		}
		next();
	}
	for(var n=0;n<events.length;n++){window.addEventListener(events[n],run,opts);}
JS;
		if ( $timeout > 0 ) {
			$script .= 'window.setTimeout(run,' . $timeout . ');';
		}
		$script .= '})();';
		return '<script id="cwv-speed-delay">' . CWV_Speed_Minifier::js( $script ) . '</script>';
	}

	/**
	 * Add font-display:swap inside Gravity Forms stylesheets.
	 *
	 * @param string $src Source URL.
	 * @param string $handle Style handle.
	 * @return string
	 */
	public function prepare_font_css( $src, $handle ) {
		unset( $handle );
		if ( is_admin() || ! is_string( $src ) || ! CWV_Speed_Settings::instance()->enabled( 'font_display_swap' ) ) {
			return $src;
		}
		if ( ! preg_match( '/gravityforms|gform/i', $src ) || false !== strpos( $src, '/cache/cwv-speed/min/' ) ) {
			return $src;
		}
		$path = CWV_Speed_Images::instance()->url_to_path( $src );
		if ( '' === $path || ! is_file( $path ) || filesize( $path ) > 1500000 ) {
			return $src;
		}
		$raw = file_get_contents( $path );
		if ( ! is_string( $raw ) || false === stripos( $raw, '@font-face' ) || false !== stripos( $raw, 'font-display' ) ) {
			return $src;
		}
		$hash = md5( $path . '|' . (string) filemtime( $path ) . '|fontdisplay|' . CWV_SPEED_VERSION );
		$file = CWV_Speed_Cache::dir() . '/min/' . $hash . '.css';
		if ( ! is_file( $file ) ) {
			wp_mkdir_p( dirname( $file ) );
			$css = $this->absolutize_css_urls( CWV_Speed_Minifier::font_display( $raw ), $src );
			file_put_contents( $file, $css, LOCK_EX );
		}
		return content_url( 'cache/cwv-speed/min/' . $hash . '.css' );
	}

	/**
	 * Defer plugin styles that are not the theme, so the first paint stays styled.
	 *
	 * @param string $html Document.
	 * @return string
	 */
	private function defer_plugin_css( $html ) {
		$needles  = array( 'dashicons', 'admin-bar', 'googlesitekit', 'gravityforms', 'gravity-forms', 'gravity_forms' );
		$excludes = CWV_Speed_Settings::instance()->lines( (string) CWV_Speed_Settings::instance()->get( 'css_excludes' ) );
		$updated  = preg_replace_callback(
			'#<link\b[^>]*>#i',
			function ( $match ) use ( $needles, $excludes ) {
				$tag = $match[0];
				if ( ! preg_match( '/\srel\s*=\s*(["\'])([^"\']+)\1/i', $tag, $rel ) || ! preg_match( '/\bstylesheet\b/i', $rel[2] ) ) {
					return $tag;
				}
				if ( false !== stripos( $tag, 'onload=' ) || false !== stripos( $tag, 'data-cwv-skip' ) ) {
					return $tag;
				}
				if ( ! $this->excluded( $tag, $needles ) || $this->excluded( $tag, $excludes ) ) {
					return $tag;
				}
				$async = preg_replace( '/\smedia\s*=\s*(["\']).*?\1/i', '', $tag, 1 );
				$async = preg_replace( '/<link\b/i', '<link media="print" onload="this.onload=null;this.media=\'all\'"', (string) $async, 1 );
				if ( ! is_string( $async ) ) {
					return $tag;
				}
				return $async . '<noscript>' . $tag . '</noscript>';
			},
			$html
		);
		return is_string( $updated ) ? $updated : $html;
	}

	/**
	 * Load non-critical stylesheets without blocking first paint.
	 *
	 * @param string $html Document.
	 * @return string
	 */
	private function defer_css( $html ) {
		$excludes = array_merge(
			array( 'admin-bar', 'dashicons', 'customize-preview' ),
			CWV_Speed_Settings::instance()->lines( (string) CWV_Speed_Settings::instance()->get( 'css_excludes' ) )
		);
		$updated  = preg_replace_callback(
			'#<link\b[^>]*>#i',
			function ( $match ) use ( $excludes ) {
				$tag = $match[0];
				if ( ! preg_match( '/\srel\s*=\s*(["\'])([^"\']+)\1/i', $tag, $rel ) || ! preg_match( '/\bstylesheet\b/i', $rel[2] ) ) {
					return $tag;
				}
				if ( false !== stripos( $tag, 'onload=' ) || false !== stripos( $tag, 'data-cwv-skip' ) ) {
					return $tag;
				}
				$media = strtolower( (string) $this->attr( $tag, 'media' ) );
				if ( '' !== $media && 'all' !== $media && 'screen' !== $media ) {
					return $tag;
				}
				if ( $this->excluded( $tag, $excludes ) ) {
					return $tag;
				}
				$async = preg_replace( '/\smedia\s*=\s*(["\']).*?\1/i', '', $tag, 1 );
				$async = preg_replace( '/<link\b/i', '<link media="print" onload="this.onload=null;this.media=\'all\'"', (string) $async, 1 );
				if ( ! is_string( $async ) ) {
					return $tag;
				}
				return $async . '<noscript>' . $tag . '</noscript>';
			},
			$html
		);
		return is_string( $updated ) ? $updated : $html;
	}

	/**
	 * Cache a minified local file and return its URL.
	 *
	 * @param string $src Original URL.
	 * @param string $type css or js.
	 * @return string
	 */
	private function minify_local( $src, $type ) {
		if ( preg_match( '/\.min\.(?:css|js)(?:$|\?)/i', $src ) ) {
			return $src;
		}
		$path = CWV_Speed_Images::instance()->url_to_path( $src );
		if ( '' === $path || ! is_file( $path ) || filesize( $path ) > 1500000 ) {
			return $src;
		}

		$hash = md5( $path . '|' . (string) filemtime( $path ) . '|' . $type . '|' . CWV_SPEED_VERSION );
		$file = CWV_Speed_Cache::dir() . '/min/' . $hash . '.' . $type;
		if ( ! is_file( $file ) ) {
			$raw = file_get_contents( $path );
			if ( ! is_string( $raw ) || '' === $raw ) {
				return $src;
			}
			if ( 'css' === $type ) {
				$min = $this->absolutize_css_urls( $raw, $src );
				if ( CWV_Speed_Settings::instance()->enabled( 'font_display_swap' ) ) {
					$min = CWV_Speed_Minifier::font_display( $min );
				}
				if ( CWV_Speed_Settings::instance()->enabled( 'minify_css' ) ) {
					$min = CWV_Speed_Minifier::css( $min, false );
				}
			} else {
				$min = CWV_Speed_Minifier::js( $raw );
			}
			if ( '' === $min ) {
				return $src;
			}
			wp_mkdir_p( dirname( $file ) );
			file_put_contents( $file, $min, LOCK_EX );
		}

		return content_url( 'cache/cwv-speed/min/' . $hash . '.' . $type );
	}

	/**
	 * Point relative CSS urls at the original stylesheet directory.
	 *
	 * A cached copy lives under wp-content/cache, so url(assets/fonts/a.woff2)
	 * would otherwise request a file that is not there.
	 *
	 * @param string $css Stylesheet contents.
	 * @param string $src Original stylesheet URL.
	 * @return string
	 */
	private function absolutize_css_urls( $css, $src ) {
		$src = strtok( html_entity_decode( (string) $src ), '?' );
		if ( ! is_string( $src ) || '' === $src ) {
			return $css;
		}
		$base = preg_replace( '#/[^/]*$#', '/', $src );
		if ( ! is_string( $base ) ) {
			return $css;
		}
		$updated = preg_replace_callback(
			'#url\(\s*(["\']?)([^"\')]+)\1\s*\)#i',
			function ( $match ) use ( $base ) {
				$url = trim( $match[2] );
				if ( '' === $url || 0 === strpos( $url, 'data:' ) || '#' === substr( $url, 0, 1 ) || false !== strpos( $url, '://' ) || 0 === strpos( $url, '//' ) || '/' === substr( $url, 0, 1 ) ) {
					return $match[0];
				}
				return 'url(' . $match[1] . $this->resolve_relative_url( $base, $url ) . $match[1] . ')';
			},
			$css
		);
		return is_string( $updated ) ? $updated : $css;
	}

	/**
	 * Resolve a relative URL against a directory URL.
	 *
	 * @param string $base Directory URL, with a trailing slash.
	 * @param string $relative Path from that directory.
	 * @return string
	 */
	private function resolve_relative_url( $base, $relative ) {
		$prefix = '';
		$path   = $base;
		if ( preg_match( '#^(https?:)//#i', $base, $scheme ) ) {
			$prefix = $scheme[1] . '//';
			$path   = substr( $base, strlen( $prefix ) );
		}
		$segments = explode( '/', $path . $relative );
		$out      = array();
		foreach ( $segments as $index => $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				if ( '' === $segment && 0 === $index ) {
					$out[] = '';
				}
				continue;
			}
			if ( '..' === $segment ) {
				if ( count( $out ) > 1 ) {
					array_pop( $out );
				}
				continue;
			}
			$out[] = $segment;
		}
		return $prefix . implode( '/', $out );
	}

	/**
	 * Script fragments that must keep running immediately.
	 *
	 * @param CWV_Speed_Settings $settings Settings.
	 * @return string[]
	 */
	private function script_excludes( $settings ) {
		return array_merge(
			array( 'jquery', 'jquery-core', 'jquery-migrate', 'admin-bar', 'customize-preview', 'wp-auth-check' ),
			$settings->lines( (string) $settings->get( 'js_excludes' ) )
		);
	}

	/**
	 * Case-insensitive substring exclusion.
	 *
	 * @param string   $haystack Text to test.
	 * @param string[] $needles Exclusion lines.
	 * @return bool
	 */
	private function excluded( $haystack, array $needles ) {
		foreach ( $needles as $needle ) {
			if ( '' !== $needle && false !== stripos( $haystack, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Read an attribute from a tag or attribute string.
	 *
	 * @param string $tag Tag fragment.
	 * @param string $name Attribute.
	 * @return string|null
	 */
	private function attr( $tag, $name ) {
		$pattern = '/\s' . preg_quote( $name, '/' ) . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+))/i';
		if ( ! preg_match( $pattern, $tag, $match ) ) {
			return null;
		}
		if ( isset( $match[1] ) && '' !== $match[1] ) {
			return $match[1];
		}
		if ( isset( $match[2] ) && '' !== $match[2] ) {
			return $match[2];
		}
		return isset( $match[3] ) ? $match[3] : '';
	}
}
