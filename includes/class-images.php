<?php
/**
 * Image delivery: LCP priority, dimensions, lazy loading, WebP, and AVIF.
 *
 * @package CWV_Speed
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rewrites front-end images and creates modern variants on upload.
 */
class CWV_Speed_Images {

	/**
	 * Singleton.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Preload markup discovered while rewriting the document.
	 *
	 * @var string
	 */
	public $preload = '';

	/**
	 * Lookup cap so a large gallery cannot query the database once per image.
	 *
	 * @var int
	 */
	private $lookups = 0;

	/**
	 * Local file reads used to discover missing width and height.
	 *
	 * @var int
	 */
	private $file_reads = 0;

	/**
	 * WebP files created during this document.
	 *
	 * @var int
	 */
	private $conversions = 0;

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
	 * Upload and delete hooks.
	 *
	 * @return void
	 */
	public function hooks() {
		add_filter( 'wp_generate_attachment_metadata', array( $this, 'convert_attachment' ), 20, 2 );
		add_action( 'delete_attachment', array( $this, 'delete_sidecars' ) );
	}

	/**
	 * Rewrite images in a full HTML document.
	 *
	 * @param string $html Document.
	 * @return string
	 */
	public function optimize_html( $html ) {
		$this->preload     = '';
		$this->lookups     = 0;
		$this->file_reads  = 0;
		$this->conversions = 0;
		$settings      = CWV_Speed_Settings::instance();
		$active = $settings->enabled( 'lazy_images' )
			|| $settings->enabled( 'lcp_priority' )
			|| $settings->enabled( 'lcp_preload' )
			|| $settings->enabled( 'image_dimensions' )
			|| $settings->enabled( 'missing_sizes' )
			|| $settings->enabled( 'webp' )
			|| $settings->enabled( 'avif' );
		if ( ! $active ) {
			return $html;
		}

		if ( ! preg_match_all( '#<img\b[^>]*>#i', $html, $found, PREG_OFFSET_CAPTURE ) ) {
			$this->preload_manual();
			return $html;
		}

		$tags      = $found[0];
		$lcp_index = $this->choose_lcp( $tags );
		$delta     = 0;
		foreach ( $tags as $index => $found_tag ) {
			$tag    = $found_tag[0];
			$offset = $found_tag[1] + $delta;
			$state  = array(
				'index'  => $index + 1,
				'is_lcp' => $index === $lcp_index,
			);
			$replacement = $this->optimize_tag( $tag, $state, ! $this->inside_picture( $html, $offset ) );
			$html        = substr_replace( $html, $replacement, $offset, strlen( $tag ) );
			$delta      += strlen( $replacement ) - strlen( $tag );
		}

		$this->preload_manual();
		return $html;
	}

	/**
	 * Pick the likely LCP image from the first few images.
	 *
	 * @param array<int, array<int, mixed>> $tags Matches from preg_match_all.
	 * @return int Index, or -1 when none qualify.
	 */
	private function choose_lcp( array $tags ) {
		if ( ! CWV_Speed_Settings::instance()->enabled( 'lcp_priority' ) ) {
			return -1;
		}
		$manual = (string) CWV_Speed_Settings::instance()->get( 'lcp_image_url' );
		if ( '' !== $manual ) {
			foreach ( $tags as $i => $found ) {
				$src = (string) $this->attr( $found[0], 'src' );
				if ( '' !== $src && false !== strpos( $src, $manual ) ) {
					return (int) $i;
				}
			}
			return -1;
		}
		$best      = -1;
		$best_area = -1;
		$limit     = min( count( $tags ), 8 );
		for ( $i = 0; $i < $limit; $i++ ) {
			$tag = $tags[ $i ][0];
			$src = (string) $this->attr( $tag, 'src' );
			if ( '' === $src || 0 === strpos( $src, 'data:' ) ) {
				continue;
			}
			if ( 'high' === strtolower( (string) $this->attr( $tag, 'fetchpriority' ) ) ) {
				return $i;
			}
			if ( ! $this->is_content_image( $tag, $src ) ) {
				continue;
			}
			$width  = (int) $this->attr( $tag, 'width' );
			$height = (int) $this->attr( $tag, 'height' );
			$area   = ( $width > 0 && $height > 0 ) ? $width * $height : 100000;
			if ( $area > $best_area ) {
				$best_area = $area;
				$best      = $i;
			}
		}
		return $best;
	}

	/**
	 * Whether the offset sits inside an existing picture element.
	 *
	 * @param string $html Document.
	 * @param int    $offset Byte offset.
	 * @return bool
	 */
	private function inside_picture( $html, $offset ) {
		$before = strtolower( substr( $html, 0, $offset ) );
		return substr_count( $before, '<picture' ) > substr_count( $before, '</picture' );
	}

	/**
	 * Preload a manually configured hero when no image tag won.
	 *
	 * @return void
	 */
	private function preload_manual() {
		$settings = CWV_Speed_Settings::instance();
		$url      = (string) $settings->get( 'lcp_image_url' );
		if ( '' !== $this->preload || ! $settings->enabled( 'lcp_preload' ) || '' === $url ) {
			return;
		}
		$this->preload = '<link rel="preload" as="image" href="' . esc_url( $url ) . '" fetchpriority="high">';
	}

	/**
	 * Optimize one image tag.
	 *
	 * @param string               $tag Image tag.
	 * @param array<string, mixed> $state Running counters.
	 * @param bool                 $allow_picture Whether a picture wrapper may be added.
	 * @return string
	 */
	private function optimize_tag( $tag, array &$state, $allow_picture ) {
		$settings = CWV_Speed_Settings::instance();
		$src      = $this->attr( $tag, 'src' );
		if ( null === $src || '' === $src || 0 === strpos( $src, 'data:' ) ) {
			return $tag;
		}

		$skip_lazy = $this->should_skip_lazy( $tag, $src );
		$is_lcp    = ! empty( $state['is_lcp'] );

		if ( $settings->enabled( 'image_dimensions' ) ) {
			$tag = $this->add_dimensions( $tag, $src );
		}
		if ( $settings->enabled( 'missing_sizes' ) ) {
			$tag = $this->add_sizes( $tag );
		}

		if ( $is_lcp ) {
			$tag = $this->remove_attr( $tag, 'loading' );
			$tag = $this->set_attr( $tag, 'fetchpriority', 'high' );
			$tag = $this->remove_attr( $tag, 'decoding' );
			if ( $settings->enabled( 'lcp_preload' ) && '' === $this->preload ) {
				$this->preload = $this->preload_tag( $tag, $src );
			}
		}

		$priority    = strtolower( (string) $this->attr( $tag, 'fetchpriority' ) );
		$eager_until = (int) $settings->get( 'lazy_skip' );
		if ( 'high' === $priority ) {
			$tag = $this->remove_attr( $tag, 'loading' );
		} elseif ( $settings->enabled( 'lazy_images' ) && ! $is_lcp && ! $skip_lazy && $state['index'] > $eager_until ) {
			if ( null === $this->attr( $tag, 'loading' ) ) {
				$tag = $this->set_attr( $tag, 'loading', 'lazy' );
			}
			if ( null === $this->attr( $tag, 'decoding' ) ) {
				$tag = $this->set_attr( $tag, 'decoding', 'async' );
			}
			if ( null === $this->attr( $tag, 'fetchpriority' ) && 'lazy' === $this->attr( $tag, 'loading' ) ) {
				$tag = $this->set_attr( $tag, 'fetchpriority', 'low' );
			}
		} elseif ( ! $is_lcp && 'lazy' === $this->attr( $tag, 'loading' ) && ( $skip_lazy || $state['index'] <= $eager_until ) ) {
			$tag = $this->remove_attr( $tag, 'loading' );
		}

		if ( $allow_picture ) {
			$tag = $this->wrap_picture( $tag, $src );
		}

		return $tag;
	}

	/**
	 * Build a high-priority preload for the LCP image.
	 *
	 * @param string $tag Image tag.
	 * @param string $src Fallback URL.
	 * @return string
	 */
	private function preload_tag( $tag, $src ) {
		$best = $src;
		$type = '';
		if ( CWV_Speed_Settings::instance()->enabled( 'avif' ) && $this->modern_url( $src, 'avif' ) ) {
			$best = $this->modern_url( $src, 'avif' );
			$type = 'image/avif';
		} elseif ( CWV_Speed_Settings::instance()->enabled( 'webp' ) && $this->modern_url( $src, 'webp' ) ) {
			$best = $this->modern_url( $src, 'webp' );
			$type = 'image/webp';
		}

		$html = '<link rel="preload" as="image" href="' . esc_url( $best ) . '" fetchpriority="high"';
		if ( $type ) {
			$html .= ' type="' . esc_attr( $type ) . '"';
		}
		$srcset = (string) $this->attr( $tag, 'srcset' );
		$sizes  = (string) $this->attr( $tag, 'sizes' );
		if ( $type && $srcset ) {
			$modern = $this->map_srcset( $srcset, 'avif' === substr( $type, -4 ) ? 'avif' : 'webp' );
			if ( $modern ) {
				$html .= ' imagesrcset="' . esc_attr( $modern ) . '"';
				if ( $sizes ) {
					$html .= ' imagesizes="' . esc_attr( $sizes ) . '"';
				}
			}
		} elseif ( $srcset ) {
			$html .= ' imagesrcset="' . esc_attr( $srcset ) . '"';
			if ( $sizes ) {
				$html .= ' imagesizes="' . esc_attr( $sizes ) . '"';
			}
		}
		return $html . '>';
	}

	/**
	 * Wrap an image in picture sources when modern files exist.
	 *
	 * @param string $tag Image tag.
	 * @param string $src Image URL.
	 * @return string
	 */
	private function wrap_picture( $tag, $src ) {
		$settings = CWV_Speed_Settings::instance();
		$sources  = '';
		$srcset   = (string) $this->attr( $tag, 'srcset' );
		$sizes    = (string) $this->attr( $tag, 'sizes' );

		foreach ( array( 'avif' => 'image/avif', 'webp' => 'image/webp' ) as $ext => $mime ) {
			if ( ! $settings->enabled( $ext ) ) {
				continue;
			}
			$url = $this->modern_url( $src, $ext );
			if ( ! $url ) {
				continue;
			}
			$set = $srcset ? $this->map_srcset( $srcset, $ext ) : '';
			$sources .= '<source type="' . esc_attr( $mime ) . '" srcset="' . esc_attr( $set ? $set : $url ) . '"';
			if ( $sizes ) {
				$sources .= ' sizes="' . esc_attr( $sizes ) . '"';
			}
			$sources .= '>';
		}

		if ( '' === $sources ) {
			return $tag;
		}

		return '<picture>' . $sources . $tag . '</picture>';
	}

	/**
	 * Map every srcset candidate to a modern sibling.
	 *
	 * @param string $srcset Srcset attribute.
	 * @param string $ext avif or webp.
	 * @return string
	 */
	private function map_srcset( $srcset, $ext ) {
		$out = array();
		foreach ( explode( ',', $srcset ) as $candidate ) {
			$candidate = trim( $candidate );
			if ( '' === $candidate ) {
				continue;
			}
			$bits = preg_split( '/\s+/', $candidate );
			if ( ! is_array( $bits ) || empty( $bits[0] ) ) {
				continue;
			}
			$modern = $this->modern_url( $bits[0], $ext );
			if ( ! $modern ) {
				continue;
			}
			$bits[0] = $modern;
			$out[]   = implode( ' ', $bits );
		}
		return implode( ', ', $out );
	}

	/**
	 * Public URL of a modern sibling, when the file exists inside the site.
	 *
	 * @param string $url Original URL.
	 * @param string $ext avif or webp.
	 * @return string
	 */
	public function modern_url( $url, $ext ) {
		$path = $this->url_to_path( $url );
		if ( '' === $path ) {
			return '';
		}
		$sidecar = $path . '.' . $ext;
		if ( ! is_file( $sidecar ) && 'webp' === $ext && $this->conversions < 4 && CWV_Speed_Settings::instance()->enabled( 'webp' ) ) {
			$size = filesize( $path );
			if ( is_int( $size ) && $size > 40000 && $size < 3 * MB_IN_BYTES ) {
				++$this->conversions;
				$this->convert_file( $path );
			}
		}
		if ( ! is_file( $sidecar ) ) {
			return '';
		}
		$clean = strtok( $url, '?' );
		return is_string( $clean ) ? $clean . '.' . $ext : '';
	}

	/**
	 * Read pixel size from a local image, including SVG width, height, and viewBox.
	 *
	 * @param string $path Absolute path.
	 * @return array{0: int, 1: int}
	 */
	private function file_dimensions( $path ) {
		$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		if ( 'svg' === $ext ) {
			$head = file_get_contents( $path, false, null, 0, 4096 );
			if ( ! is_string( $head ) ) {
				return array( 0, 0 );
			}
			$width  = 0;
			$height = 0;
			if ( preg_match( '/\bwidth=["\'](\d+(?:\.\d+)?)(?:px)?["\']/i', $head, $match ) ) {
				$width = (int) $match[1];
			}
			if ( preg_match( '/\bheight=["\'](\d+(?:\.\d+)?)(?:px)?["\']/i', $head, $match ) ) {
				$height = (int) $match[1];
			}
			if ( ( $width < 1 || $height < 1 ) && preg_match( '/\bviewBox=["\']\s*[\d.+-]+\s+[\d.+-]+\s+([\d.]+)\s+([\d.]+)/i', $head, $match ) ) {
				$width  = (int) $match[1];
				$height = (int) $match[2];
			}
			return array( $width, $height );
		}
		$info = getimagesize( $path );
		if ( ! is_array( $info ) ) {
			return array( 0, 0 );
		}
		return array( (int) $info[0], (int) $info[1] );
	}

	/**
	 * Add width and height from metadata or the resized filename.
	 *
	 * @param string $tag Image tag.
	 * @param string $src Image URL.
	 * @return string
	 */
	private function add_dimensions( $tag, $src ) {
		if ( null !== $this->attr( $tag, 'width' ) && null !== $this->attr( $tag, 'height' ) ) {
			return $tag;
		}

		$width  = 0;
		$height = 0;
		if ( preg_match( '/-(\d+)x(\d+)\.(?:jpe?g|png|webp|avif|gif)(?:\?|$)/i', $src, $match ) ) {
			$width  = (int) $match[1];
			$height = (int) $match[2];
		}

		if ( ( $width < 1 || $height < 1 ) && $this->file_reads < 160 ) {
			$path = $this->url_to_path( $src );
			if ( '' !== $path && is_file( $path ) ) {
				++$this->file_reads;
				$dims   = $this->file_dimensions( $path );
				$width  = $dims[0];
				$height = $dims[1];
			}
		}

		if ( ( $width < 1 || $height < 1 ) && $this->lookups < 20 ) {
			++$this->lookups;
			$id = 0;
			if ( preg_match( '/\bwp-image-(\d+)\b/', $tag, $class_match ) ) {
				$id = (int) $class_match[1];
			} elseif ( function_exists( 'attachment_url_to_postid' ) ) {
				$id = (int) attachment_url_to_postid( strtok( $src, '?' ) );
			}
			if ( $id ) {
				$meta = wp_get_attachment_metadata( $id );
				if ( is_array( $meta ) && ! empty( $meta['width'] ) && ! empty( $meta['height'] ) ) {
					$width  = (int) $meta['width'];
					$height = (int) $meta['height'];
					$file   = wp_basename( strtok( $src, '?' ) );
					if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
						foreach ( $meta['sizes'] as $size ) {
							if ( ! empty( $size['file'] ) && $size['file'] === $file && ! empty( $size['width'] ) && ! empty( $size['height'] ) ) {
								$width  = (int) $size['width'];
								$height = (int) $size['height'];
								break;
							}
						}
					}
				}
			}
		}

		if ( $width > 0 && $height > 0 ) {
			if ( null === $this->attr( $tag, 'width' ) ) {
				$tag = $this->set_attr( $tag, 'width', (string) $width );
			}
			if ( null === $this->attr( $tag, 'height' ) ) {
				$tag = $this->set_attr( $tag, 'height', (string) $height );
			}
		}

		return $tag;
	}

	/**
	 * Give srcset images a sizes value when the theme omitted one.
	 *
	 * @param string $tag Image tag.
	 * @return string
	 */
	private function add_sizes( $tag ) {
		if ( null === $this->attr( $tag, 'srcset' ) || null !== $this->attr( $tag, 'sizes' ) ) {
			return $tag;
		}
		$width = (int) $this->attr( $tag, 'width' );
		if ( $width < 1 ) {
			return $tag;
		}
		return $this->set_attr( $tag, 'sizes', '(max-width: ' . $width . 'px) 100vw, ' . $width . 'px' );
	}

	/**
	 * Images that should stay eager.
	 *
	 * @param string $tag Image tag.
	 * @param string $src Image URL.
	 * @return bool
	 */
	private function should_skip_lazy( $tag, $src ) {
		if ( false !== stripos( $tag, 'data-no-lazy' ) || false !== stripos( $tag, 'skip-lazy' ) || false !== stripos( $tag, 'no-lazy' ) ) {
			return true;
		}
		if ( preg_match( '/\b(avatar|emoji|smiley)\b/i', $tag ) ) {
			return false;
		}
		if ( false !== stripos( $src, 'gravatar.com' ) ) {
			return false;
		}
		return false;
	}

	/**
	 * Whether an image is a real content candidate rather than a pixel or emoji.
	 *
	 * @param string $tag Image tag.
	 * @param string $src Image URL.
	 * @return bool
	 */
	private function is_content_image( $tag, $src ) {
		if ( preg_match( '/\b(emoji|smiley)\b/i', $tag ) || false !== stripos( $src, 'gravatar.com' ) ) {
			return false;
		}
		$width = (int) $this->attr( $tag, 'width' );
		if ( $width > 0 && $width < 60 ) {
			return false;
		}
		return true;
	}

	/**
	 * Create modern variants for an attachment and its sizes.
	 *
	 * @param array<string, mixed> $meta Attachment metadata.
	 * @param int                  $attachment_id Attachment ID.
	 * @return array<string, mixed>
	 */
	public function convert_attachment( $meta, $attachment_id ) {
		$file = get_attached_file( $attachment_id );
		if ( is_string( $file ) ) {
			$this->convert_file( $file );
		}
		if ( is_string( $file ) && is_array( $meta ) && ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			$dir = dirname( $file );
			foreach ( $meta['sizes'] as $size ) {
				if ( ! empty( $size['file'] ) ) {
					$this->convert_file( $dir . '/' . $size['file'] );
				}
			}
		}
		return $meta;
	}

	/**
	 * Create WebP and AVIF siblings for a local file.
	 *
	 * @param string $file Absolute path.
	 * @return string[] Created extension names.
	 */
	public function convert_file( $file ) {
		$created = array();
		if ( ! is_string( $file ) || ! is_file( $file ) || filesize( $file ) > 12 * MB_IN_BYTES ) {
			return $created;
		}
		$ext = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, array( 'jpg', 'jpeg', 'png' ), true ) ) {
			return $created;
		}

		$settings = CWV_Speed_Settings::instance();
		$targets  = array();
		if ( $settings->enabled( 'webp' ) && wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ) ) {
			$targets['webp'] = 'image/webp';
		}
		if ( $settings->enabled( 'avif' ) && wp_image_editor_supports( array( 'mime_type' => 'image/avif' ) ) ) {
			$targets['avif'] = 'image/avif';
		}

		foreach ( $targets as $extension => $mime ) {
			$dest = $file . '.' . $extension;
			if ( is_file( $dest ) && filemtime( $dest ) >= filemtime( $file ) ) {
				$created[] = $extension;
				continue;
			}
			$editor = wp_get_image_editor( $file );
			if ( is_wp_error( $editor ) ) {
				continue;
			}
			$editor->set_quality( 'image/avif' === $mime ? 50 : 82 );
			$saved = $editor->save( $dest, $mime );
			if ( ! is_wp_error( $saved ) ) {
				$created[] = $extension;
			}
		}

		return $created;
	}

	/**
	 * Remove generated siblings when the attachment is deleted.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return void
	 */
	public function delete_sidecars( $attachment_id ) {
		$file = get_attached_file( $attachment_id );
		$meta = wp_get_attachment_metadata( $attachment_id );
		$files = array();
		if ( is_string( $file ) ) {
			$files[] = $file;
		}
		if ( is_string( $file ) && is_array( $meta ) && ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			$dir = dirname( $file );
			foreach ( $meta['sizes'] as $size ) {
				if ( ! empty( $size['file'] ) ) {
					$files[] = $dir . '/' . $size['file'];
				}
			}
		}
		foreach ( $files as $path ) {
			foreach ( array( 'webp', 'avif' ) as $ext ) {
				$sidecar = $path . '.' . $ext;
				if ( is_file( $sidecar ) ) {
					wp_delete_file( $sidecar );
				}
			}
		}
	}

	/**
	 * Map a local upload or content URL to a path.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public function url_to_path( $url ) {
		if ( 0 === strpos( $url, '//' ) ) {
			$url = ( is_ssl() ? 'https:' : 'http:' ) . $url;
		}
		$path = wp_parse_url( $url, PHP_URL_PATH );
		if ( ! is_string( $path ) || '' === $path || false !== strpos( $path, '..' ) ) {
			return '';
		}

		$uploads = wp_get_upload_dir();
		$bases   = array();
		if ( ! empty( $uploads['baseurl'] ) && ! empty( $uploads['basedir'] ) ) {
			$bases[] = array( wp_parse_url( $uploads['baseurl'], PHP_URL_PATH ), $uploads['basedir'] );
		}
		$content_path = wp_parse_url( content_url(), PHP_URL_PATH );
		$bases[]      = array( $content_path, WP_CONTENT_DIR );
		$bases[]      = array( wp_parse_url( site_url(), PHP_URL_PATH ), ABSPATH );

		foreach ( $bases as $base ) {
			$base_path = isset( $base[0] ) ? (string) $base[0] : '';
			$base_dir  = isset( $base[1] ) ? (string) $base[1] : '';
			if ( '' === $base_dir ) {
				continue;
			}
			$base_path = untrailingslashit( $base_path );
			if ( '' !== $base_path ) {
				if ( 0 !== strpos( $path, $base_path ) ) {
					continue;
				}
				$next = substr( $path, strlen( $base_path ), 1 );
				if ( '' !== $next && '/' !== $next ) {
					continue;
				}
			}
			$relative = '' === $base_path ? $path : substr( $path, strlen( $base_path ) );
			$root     = untrailingslashit( wp_normalize_path( $base_dir ) );
			$full     = wp_normalize_path( $base_dir . $relative );
			if ( $full !== $root && 0 !== strpos( $full, $root . '/' ) ) {
				continue;
			}
			return $full;
		}

		return '';
	}

	/**
	 * Read an attribute.
	 *
	 * @param string $tag Tag.
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

	/**
	 * Set an attribute.
	 *
	 * @param string $tag Tag.
	 * @param string $name Attribute.
	 * @param string $value Value.
	 * @return string
	 */
	private function set_attr( $tag, $name, $value ) {
		$rendered = ' ' . $name . '="' . esc_attr( $value ) . '"';
		$pattern  = '/\s' . preg_quote( $name, '/' ) . '\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s"\'=<>`]+)/i';
		if ( preg_match( $pattern, $tag ) ) {
			$updated = preg_replace( $pattern, $rendered, $tag, 1 );
			return is_string( $updated ) ? $updated : $tag;
		}
		$updated = preg_replace( '/<img\b/i', '<img' . $rendered, $tag, 1 );
		return is_string( $updated ) ? $updated : $tag;
	}

	/**
	 * Remove an attribute.
	 *
	 * @param string $tag Tag.
	 * @param string $name Attribute.
	 * @return string
	 */
	private function remove_attr( $tag, $name ) {
		$pattern = '/\s' . preg_quote( $name, '/' ) . '\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s"\'=<>`]+)/i';
		$updated = preg_replace( $pattern, '', $tag, 1 );
		return is_string( $updated ) ? $updated : $tag;
	}
}
