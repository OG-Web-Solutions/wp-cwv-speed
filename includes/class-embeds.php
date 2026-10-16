<?php
/**
 * Lazy iframes and click-to-play video facades.
 *
 * @package CWV_Speed
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Replaces heavy video players with a reserved-space poster.
 */
class CWV_Speed_Embeds {

	/**
	 * Singleton.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Whether the document contains a facade.
	 *
	 * @var bool
	 */
	public $used = false;

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
	 * Rewrite iframes.
	 *
	 * @param string $html Document.
	 * @return string
	 */
	public function optimize_html( $html ) {
		$this->used = false;
		$settings   = CWV_Speed_Settings::instance();
		if ( ! $settings->enabled( 'video_facade' ) && ! $settings->enabled( 'iframe_lazy' ) ) {
			return $html;
		}

		$index = 0;
		$html  = preg_replace_callback(
			'#<iframe\b[^>]*>(?:\s*</iframe>)?#i',
			function ( $match ) use ( &$index, $settings ) {
				++$index;
				$tag = $match[0];
				$src = $this->attr( $tag, 'src' );
				if ( null === $src || '' === $src ) {
					return $tag;
				}
				if ( false !== stripos( $tag, 'data-cwv-skip' ) ) {
					return $tag;
				}
				if ( $settings->enabled( 'video_facade' ) ) {
					$facade = $this->facade( $tag, $src );
					if ( '' !== $facade ) {
						$this->used = true;
						return $facade;
					}
				}
				if ( $settings->enabled( 'iframe_lazy' ) && $index > 1 && null === $this->attr( $tag, 'loading' ) ) {
					$tag = preg_replace( '/<iframe\b/i', '<iframe loading="lazy"', $tag, 1 );
				}
				return is_string( $tag ) ? $tag : $match[0];
			},
			$html
		);

		return (string) $html;
	}

	/**
	 * CSS for the facade button.
	 *
	 * @return string
	 */
	public function head_css() {
		if ( ! $this->used ) {
			return '';
		}
		return '<style id="cwv-speed-facade">.cwv-facade{position:relative;display:block;width:100%;max-width:100%;padding:0;border:0;background:#111;cursor:pointer;aspect-ratio:16/9;overflow:hidden}.cwv-facade img{width:100%;height:100%;object-fit:cover;display:block}.cwv-facade-play{position:absolute;left:50%;top:50%;width:68px;height:48px;transform:translate(-50%,-50%);background:#ff0033;border-radius:14px}.cwv-facade-play:before{content:"";position:absolute;left:26px;top:14px;border-style:solid;border-width:10px 0 10px 18px;border-color:transparent transparent transparent #fff}</style>';
	}

	/**
	 * Click handler printed once.
	 *
	 * @return string
	 */
	public function footer_script() {
		if ( ! $this->used ) {
			return '';
		}
		return '<script id="cwv-speed-facade-js">(function(){document.addEventListener("click",function(e){var el=e.target&&e.target.closest?e.target.closest(".cwv-facade"):null;if(!el||el.getAttribute("data-playing")){return;}var src=el.getAttribute("data-cwv-src");if(!src){return;}el.setAttribute("data-playing","1");if(src.indexOf("autoplay=")===-1){src+=(src.indexOf("?")===-1?"?":"&")+"autoplay=1";}var frame=document.createElement("iframe");frame.src=src;frame.title=el.getAttribute("aria-label")||"Video";frame.allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share";frame.allowFullscreen=true;frame.style.cssText="position:absolute;inset:0;width:100%;height:100%;border:0";el.textContent="";el.appendChild(frame);});})();</script>';
	}

	/**
	 * Build a facade for YouTube or Vimeo.
	 *
	 * @param string $tag Iframe tag.
	 * @param string $src Iframe URL.
	 * @return string
	 */
	private function facade( $tag, $src ) {
		$youtube = '';
		if ( preg_match( '#(?:youtube\.com|youtube-nocookie\.com)/embed/([A-Za-z0-9_-]{6,})#i', $src, $match ) ) {
			$youtube = $match[1];
		} elseif ( ! preg_match( '#player\.vimeo\.com/video/\d+#i', $src ) ) {
			return '';
		}

		$title = $this->attr( $tag, 'title' );
		$label = $title ? $title : __( 'Play video', 'wp-cwv-speed' );
		$html  = '<button type="button" class="cwv-facade" data-cwv-src="' . esc_url( $src ) . '" aria-label="' . esc_attr( $label ) . '">';
		if ( $youtube ) {
			$width  = (int) $this->attr( $tag, 'width' );
			$height = (int) $this->attr( $tag, 'height' );
			if ( $width < 1 || $height < 1 ) {
				$width  = 480;
				$height = 360;
			}
			$html .= '<img src="' . esc_url( 'https://i.ytimg.com/vi/' . rawurlencode( $youtube ) . '/hqdefault.jpg' ) . '" alt="" width="' . esc_attr( (string) $width ) . '" height="' . esc_attr( (string) $height ) . '">';
		}
		$html .= '<span class="cwv-facade-play" aria-hidden="true"></span></button>';
		$html .= '<noscript>' . $tag . '</noscript>';
		return $html;
	}

	/**
	 * Read an attribute.
	 *
	 * @param string $tag Tag.
	 * @param string $name Attribute name.
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
