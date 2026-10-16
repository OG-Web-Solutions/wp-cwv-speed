<?php
/**
 * Settings, defaults, and presets.
 *
 * @package CWV_Speed
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores one option array and the three score presets.
 */
class CWV_Speed_Settings {

	/**
	 * Cached settings.
	 *
	 * @var array<string, mixed>|null
	 */
	private $settings = null;

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
	 * All settings merged over defaults.
	 *
	 * @return array<string, mixed>
	 */
	public function all() {
		if ( null === $this->settings ) {
			$stored         = get_option( 'cwv_speed_settings', array() );
			$this->settings = wp_parse_args( is_array( $stored ) ? $stored : array(), self::defaults() );
		}
		return $this->settings;
	}

	/**
	 * One setting.
	 *
	 * @param string $key Setting key.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	public function get( $key, $default = null ) {
		$all = $this->all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/**
	 * Enabled flag.
	 *
	 * @param string $key Setting key.
	 * @return bool
	 */
	public function enabled( $key ) {
		return ! empty( $this->get( $key ) );
	}

	/**
	 * Replace settings and refresh runtime files.
	 *
	 * @param array<string, mixed> $settings Sanitized settings.
	 * @return void
	 */
	public function save( array $settings ) {
		$settings       = wp_parse_args( $settings, self::defaults() );
		$this->settings = $settings;
		update_option( 'cwv_speed_settings', $settings, true );
		CWV_Speed_Cache::instance()->sync();
		CWV_Speed_Server_Rules::instance()->sync();
		CWV_Speed_Cache::instance()->purge();
	}

	/**
	 * Apply a named preset.
	 *
	 * @param string $preset safe|balanced|aggressive.
	 * @return void
	 */
	public function apply_preset( $preset ) {
		$presets = self::presets();
		if ( ! isset( $presets[ $preset ] ) ) {
			return;
		}
		$settings           = wp_parse_args( $presets[ $preset ], self::defaults() );
		$settings['preset'] = $preset;
		$this->save( $settings );
	}

	/**
	 * Sanitize a posted settings array.
	 *
	 * @param array<string, mixed> $input Raw input.
	 * @return array<string, mixed>
	 */
	public function sanitize( array $input ) {
		$defaults = self::defaults();
		$clean    = $defaults;

		foreach ( $defaults as $key => $default ) {
			if ( is_bool( $default ) ) {
				$clean[ $key ] = ! empty( $input[ $key ] );
				continue;
			}
			if ( ! array_key_exists( $key, $input ) ) {
				continue;
			}
			$value = $input[ $key ];
			if ( is_int( $default ) ) {
				$clean[ $key ] = absint( $value );
				continue;
			}
			if ( is_string( $default ) ) {
				$clean[ $key ] = is_string( $value ) ? $value : '';
			}
		}

		$clean['cache_ttl']     = min( 168, max( 1, (int) $clean['cache_ttl'] ) );
		$clean['lazy_skip']     = min( 8, max( 0, (int) $clean['lazy_skip'] ) );
		$clean['delay_timeout'] = min( 60, max( 0, (int) $clean['delay_timeout'] ) );
		$clean['revision_max']  = min( 50, max( 1, (int) $clean['revision_max'] ) );

		$modes = array( 'off', 'third-party', 'all' );
		if ( ! in_array( $clean['delay_js'], $modes, true ) ) {
			$clean['delay_js'] = 'third-party';
		}

		$speculation = array( 'core', 'prefetch-conservative', 'prerender-moderate', 'prerender-eager', 'off' );
		if ( ! in_array( $clean['speculation'], $speculation, true ) ) {
			$clean['speculation'] = 'prerender-moderate';
		}

		$clean['critical_css']  = self::sanitize_css( $clean['critical_css'] );
		$clean['lcp_image_url'] = esc_url_raw( $clean['lcp_image_url'] );
		$clean['css_excludes']  = sanitize_textarea_field( $clean['css_excludes'] );
		$clean['js_excludes']   = sanitize_textarea_field( $clean['js_excludes'] );
		$clean['delay_patterns'] = sanitize_textarea_field( $clean['delay_patterns'] );
		$clean['preload_fonts'] = self::sanitize_url_list( $clean['preload_fonts'] );
		$clean['preset']        = 'custom';

		return $clean;
	}

	/**
	 * Lines that should match a URL or handle.
	 *
	 * @param string $text Textarea contents.
	 * @return string[]
	 */
	public function lines( $text ) {
		$lines = preg_split( '/\r\n|\r|\n/', (string) $text );
		if ( ! is_array( $lines ) ) {
			return array();
		}
		$out = array();
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' !== $line && '#' !== substr( $line, 0, 1 ) ) {
				$out[] = $line;
			}
		}
		return $out;
	}

	/**
	 * Default settings. Cache TTL is stored in hours.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults() {
		return array(
			'preset'                   => 'balanced',
			'page_cache'               => true,
			'cache_ttl'                => 10,
			'cache_mobile'             => true,
			'gzip'                     => true,
			'browser_cache'            => true,
			'minify_html'              => true,
			'minify_css'               => false,
			'minify_js'                => false,
			'defer_css'                => false,
			'critical_css'             => '',
			'css_excludes'             => "admin-bar\ndashicons",
			'defer_js'                 => false,
			'delay_js'                 => 'third-party',
			'delay_timeout'            => 0,
			'js_excludes'              => "jquery\njquery-core\njquery-migrate\nwp-polyfill\nwp-hooks\nwp-i18n",
			'delay_patterns'           => self::default_delay_patterns(),
			'lazy_images'              => true,
			'lazy_skip'                => 2,
			'lcp_priority'             => true,
			'lcp_preload'              => true,
			'lcp_image_url'            => '',
			'image_dimensions'         => true,
			'missing_sizes'            => true,
			'webp'                     => true,
			'avif'                     => false,
			'iframe_lazy'              => true,
			'video_facade'             => true,
			'font_display_swap'        => true,
			'self_host_google_fonts'   => false,
			'preload_fonts'            => '',
			'content_visibility'       => false,
			'speculation'              => 'prerender-moderate',
			'disable_emojis'           => true,
			'disable_embeds'           => true,
			'disable_jquery_migrate'   => true,
			'disable_dashicons'        => false,
			'slow_heartbeat'           => true,
			'disable_heartbeat_front'  => true,
			'limit_revisions'          => true,
			'revision_max'             => 5,
			'disable_xmlrpc'           => false,
			'disable_cart_fragments'   => true,
			'disable_rsd'              => true,
			'disable_wlw'              => true,
			'disable_generator'        => true,
			'disable_shortlink'        => true,
			'disable_feeds'            => false,
		);
	}

	/**
	 * Preset overrides.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function presets() {
		$base = self::defaults();

		$safe                           = $base;
		$safe['delay_js']               = 'off';
		$safe['video_facade']           = false;
		$safe['disable_jquery_migrate'] = false;
		$safe['speculation']            = 'core';
		$safe['disable_cart_fragments'] = false;

		$aggressive                            = $base;
		$aggressive['minify_css']              = true;
		$aggressive['minify_js']               = true;
		$aggressive['defer_css']               = true;
		$aggressive['defer_js']                = true;
		$aggressive['self_host_google_fonts']  = true;
		$aggressive['avif']                    = true;
		$aggressive['delay_js']                = 'all';
		$aggressive['delay_timeout']        = 0;
		$aggressive['disable_dashicons']    = true;
		$aggressive['disable_xmlrpc']       = true;
		$aggressive['disable_feeds']        = true;
		$aggressive['content_visibility']   = true;
		$aggressive['speculation']          = 'prerender-eager';

		return array(
			'safe'       => $safe,
			'balanced'   => $base,
			'aggressive' => $aggressive,
		);
	}

	/**
	 * Vendors that should not run during the initial Lighthouse trace.
	 *
	 * @return string
	 */
	public static function default_delay_patterns() {
		$patterns = array(
			'googletagmanager.com',
			'google-analytics.com',
			'googleadservices.com',
			'googlesyndication.com',
			'doubleclick.net',
			'google.com/pagead',
			'facebook.net',
			'fbevents.js',
			'hotjar.com',
			'clarity.ms',
			'snap.licdn.com',
			'ads.linkedin.com',
			'analytics.tiktok.com',
			'bat.bing.com',
			'ct.pinterest.com',
			'pinimg.com/ct/',
			'js.hs-scripts.com',
			'js.hs-analytics.net',
			'widget.intercom.io',
			'client.crisp.chat',
			'embed.tawk.to',
			'static.zdassets.com',
			'js.driftt.com',
			'cdn.mouseflow.com',
			'cdn.segment.com',
			'plausible.io/js',
			'usefathom.com',
			'cloudflareinsights.com',
			'maps.googleapis.com',
			'maps.gstatic.com',
			'youtube.com/iframe_api',
			'youtu.be/iframe_api',
			's.ytimg.com',
			'platform.twitter.com',
			'platform.x.com',
			'static.ads-twitter.com',
			'mc.yandex.ru',
		);
		return implode( "\n", $patterns );
	}

	/**
	 * Strip a stylesheet breakout from admin-supplied CSS.
	 *
	 * @param string $css CSS.
	 * @return string
	 */
	private static function sanitize_css( $css ) {
		$css = str_ireplace( array( '</style', '<script', '</script', '<link' ), '', (string) $css );
		return trim( $css );
	}

	/**
	 * Keep one absolute URL per line.
	 *
	 * @param string $text URL list.
	 * @return string
	 */
	private static function sanitize_url_list( $text ) {
		$lines = preg_split( '/\r\n|\r|\n/', (string) $text );
		$out   = array();
		if ( ! is_array( $lines ) ) {
			return '';
		}
		foreach ( $lines as $line ) {
			$url = esc_url_raw( trim( $line ) );
			if ( $url ) {
				$out[] = $url;
			}
		}
		return implode( "\n", $out );
	}
}
