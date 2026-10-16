<?php
/**
 * Conservative HTML, CSS, and JS minifiers.
 *
 * These avoid rewriting strings, template literals, and calc() expressions.
 *
 * @package CWV_Speed
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Minify helpers with no WordPress dependency except wp_generate_password when present.
 */
class CWV_Speed_Minifier {

	/**
	 * Minify a full HTML document without touching protected elements.
	 *
	 * @param string $html Document.
	 * @return string
	 */
	public static function html( $html ) {
		$token = 'CWVHOLD' . ( function_exists( 'wp_generate_password' ) ? wp_generate_password( 6, false, false ) : 'TOKEN' );
		$holds = array();
		$index = 0;

		$html = preg_replace_callback(
			'#<(pre|textarea|script|style|code|noscript)\b[^>]*>.*?</\1>#is',
			static function ( $match ) use ( &$holds, &$index, $token ) {
				$key           = '%%' . $token . $index . '%%';
				$holds[ $key ] = $match[0];
				++$index;
				return $key;
			},
			$html
		);

		if ( ! is_string( $html ) ) {
			return '';
		}

		$html = preg_replace( '/<!--(?!\s*(?:\[if|<!|noindex|#))[\s\S]*?-->/', '', $html );
		$html = preg_replace( '/>\s+</', '><', (string) $html );
		$html = preg_replace( '/\s{2,}/', ' ', (string) $html );

		if ( $holds ) {
			$html = strtr( (string) $html, $holds );
		}

		return trim( (string) $html );
	}

	/**
	 * Minify CSS and force a font swap when requested.
	 *
	 * @param string $css CSS.
	 * @param bool   $font_swap Add font-display when missing.
	 * @return string
	 */
	public static function css( $css, $font_swap = false ) {
		$css = preg_replace( '!/\*[\s\S]*?\*/!', '', (string) $css );
		if ( $font_swap ) {
			$css = self::font_display( (string) $css );
		}
		$css = preg_replace( '/\s+/', ' ', (string) $css );
		$css = preg_replace( '/\s*([{}:;,])\s*/', '$1', (string) $css );
		$css = str_replace( ';}', '}', (string) $css );
		return trim( (string) $css );
	}

	/**
	 * Add font-display:swap to @font-face rules that omit it.
	 *
	 * @param string $css CSS.
	 * @return string
	 */
	public static function font_display( $css ) {
		$updated = preg_replace_callback(
			'/@font-face\s*\{[^}]*\}/i',
			static function ( $match ) {
				if ( false !== stripos( $match[0], 'font-display' ) ) {
					return $match[0];
				}
				return preg_replace( '/@font-face\s*\{/i', '@font-face{font-display:swap;', $match[0], 1 );
			},
			$css
		);
		return is_string( $updated ) ? $updated : $css;
	}

	/**
	 * Light JS cleanup. Line comments are left alone so URLs stay valid.
	 *
	 * @param string $js JavaScript.
	 * @return string
	 */
	public static function js( $js ) {
		$js = preg_replace( '!/\*[\s\S]*?\*/!', '', (string) $js );
		$js = preg_replace( '/^[ \t]+/m', '', (string) $js );
		$js = preg_replace( '/[ \t]+$/m', '', (string) $js );
		$js = preg_replace( "/\n{2,}/", "\n", (string) $js );
		return trim( (string) $js );
	}
}
