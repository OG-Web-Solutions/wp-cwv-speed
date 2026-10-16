<?php
/**
 * Settings screen.
 *
 * @package CWV_Speed
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Explains the score and stores the options that control each optimization.
 */
class CWV_Speed_Admin {

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
	 * Register the menu and save handler.
	 *
	 * @return void
	 */
	public function hooks() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'save' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_notices', array( $this, 'notices' ) );
	}

	/**
	 * Add the top-level menu.
	 *
	 * @return void
	 */
	public function menu() {
		add_menu_page(
			__( 'CWV Speed', 'wp-cwv-speed' ),
			__( 'CWV Speed', 'wp-cwv-speed' ),
			'manage_options',
			'cwv-speed',
			array( $this, 'render' ),
			'dashicons-performance',
			81
		);
	}

	/**
	 * Load assets only on this screen.
	 *
	 * @param string $hook Current admin page.
	 * @return void
	 */
	public function assets( $hook ) {
		if ( 'toplevel_page_cwv-speed' !== $hook ) {
			return;
		}
		wp_enqueue_style( 'cwv-speed-admin', CWV_SPEED_URL . 'assets/css/admin.css', array(), CWV_SPEED_VERSION );
		wp_enqueue_script( 'cwv-speed-admin', CWV_SPEED_URL . 'assets/js/admin.js', array(), CWV_SPEED_VERSION, true );
		wp_localize_script(
			'cwv-speed-admin',
			'cwvSpeed',
			array(
				'ajax'  => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'cwv_speed_convert' ),
			)
		);
	}

	/**
	 * Save settings or apply a preset.
	 *
	 * @return void
	 */
	public function save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( isset( $_POST['cwv_speed_preset'] ) ) {
			check_admin_referer( 'cwv_speed_preset' );
			CWV_Speed_Settings::instance()->apply_preset( sanitize_key( wp_unslash( $_POST['cwv_speed_preset'] ) ) );
			wp_safe_redirect( admin_url( 'admin.php?page=cwv-speed&updated=1' ) );
			exit;
		}

		if ( ! isset( $_POST['cwv_speed_save'] ) ) {
			return;
		}
		check_admin_referer( 'cwv_speed_save' );
		$raw = isset( $_POST['cwv_speed'] ) ? wp_unslash( $_POST['cwv_speed'] ) : array();
		$raw = is_array( $raw ) ? $raw : array();
		CWV_Speed_Settings::instance()->save( CWV_Speed_Settings::instance()->sanitize( $raw ) );
		wp_safe_redirect( admin_url( 'admin.php?page=cwv-speed&updated=1' ) );
		exit;
	}

	/**
	 * Warnings that block the cache from actually serving.
	 *
	 * @return void
	 */
	public function notices() {
		if ( ! current_user_can( 'manage_options' ) || 'toplevel_page_cwv-speed' !== $this->hook() ) {
			return;
		}
		if ( get_transient( 'cwv_speed_dropin_blocked' ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Another advanced-cache.php drop-in is already installed. CWV Speed left it in place. Remove the other page-cache plugin if you want this cache to serve pages.', 'wp-cwv-speed' ) . '</p></div>';
		}
		if ( CWV_Speed_Settings::instance()->enabled( 'page_cache' ) && ! CWV_Speed_Cache::instance()->config_has_wp_cache() ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Add define( \'WP_CACHE\', true ); to wp-config.php above the stop-editing line. The plugin could not write that file itself.', 'wp-cwv-speed' ) . '</p></div>';
		}
	}

	/**
	 * Render the screen.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings = CWV_Speed_Settings::instance()->all();
		$preset   = (string) $settings['preset'];
		?>
		<div class="wrap cwv-wrap">
			<h1><?php esc_html_e( 'CWV Speed', 'wp-cwv-speed' ); ?></h1>
			<p class="cwv-lead"><?php esc_html_e( 'Balanced is the preset to start with. It targets the three metrics that make up 80% of the Lighthouse performance score: Total Blocking Time, Largest Contentful Paint, and Cumulative Layout Shift. A plugin cannot promise 90 on every theme. Slow hosting, a huge hero image, or a page builder that prints megabytes of CSS can still hold the score down.', 'wp-cwv-speed' ); ?></p>

			<?php $this->status(); ?>

			<div class="cwv-grid">
				<?php
				$this->preset_card( 'safe', __( 'Safe', 'wp-cwv-speed' ), __( 'Page cache, image dimensions, LCP preload, and head cleanup. Leaves CSS and JavaScript execution alone.', 'wp-cwv-speed' ), $preset );
				$this->preset_card( 'balanced', __( 'Balanced', 'wp-cwv-speed' ), __( 'Recommended. Page cache, LCP preload, image dimensions, delayed third-party tags, and WordPress cleanup. Theme CSS and JavaScript stay in their normal order.', 'wp-cwv-speed' ), $preset );
				$this->preset_card( 'aggressive', __( 'Aggressive', 'wp-cwv-speed' ), __( 'Also delays every script until interaction and turns on AVIF. Test the menu, forms, and checkout after applying it.', 'wp-cwv-speed' ), $preset );
				?>
			</div>

			<div class="cwv-panel">
				<h2><?php esc_html_e( 'Where the score comes from', 'wp-cwv-speed' ); ?></h2>
				<table class="cwv-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Metric', 'wp-cwv-speed' ); ?></th>
							<th><?php esc_html_e( 'Weight', 'wp-cwv-speed' ); ?></th>
							<th><?php esc_html_e( 'Good result', 'wp-cwv-speed' ); ?></th>
							<th><?php esc_html_e( 'What this plugin does', 'wp-cwv-speed' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<tr>
							<td><?php esc_html_e( 'Total Blocking Time', 'wp-cwv-speed' ); ?></td>
							<td>30%</td>
							<td><?php esc_html_e( 'Under 200 ms mobile, under 150 ms desktop', 'wp-cwv-speed' ); ?></td>
							<td><?php esc_html_e( 'Defers theme scripts and waits to run analytics, chat, and maps until a real interaction.', 'wp-cwv-speed' ); ?></td>
						</tr>
						<tr>
							<td><?php esc_html_e( 'Largest Contentful Paint', 'wp-cwv-speed' ); ?></td>
							<td>25%</td>
							<td><?php esc_html_e( 'Under 2.5 s mobile, under 1.2 s desktop', 'wp-cwv-speed' ); ?></td>
							<td><?php esc_html_e( 'Page cache, preload, fetchpriority, no lazy-load on the hero, and non-blocking CSS.', 'wp-cwv-speed' ); ?></td>
						</tr>
						<tr>
							<td><?php esc_html_e( 'Cumulative Layout Shift', 'wp-cwv-speed' ); ?></td>
							<td>25%</td>
							<td><?php esc_html_e( '0.1 or less', 'wp-cwv-speed' ); ?></td>
							<td><?php esc_html_e( 'Width and height on images, font-display, and a reserved 16:9 box for video facades.', 'wp-cwv-speed' ); ?></td>
						</tr>
						<tr>
							<td><?php esc_html_e( 'First Contentful Paint / Speed Index', 'wp-cwv-speed' ); ?></td>
							<td>10% + 10%</td>
							<td><?php esc_html_e( 'FCP under 1.8 s mobile and 0.9 s desktop', 'wp-cwv-speed' ); ?></td>
							<td><?php esc_html_e( 'Same cache and render-blocking work as LCP. Field INP is helped by doing less JavaScript up front.', 'wp-cwv-speed' ); ?></td>
						</tr>
					</tbody>
				</table>
			</div>

			<form method="post">
				<?php wp_nonce_field( 'cwv_speed_save' ); ?>
				<div class="cwv-panel">
					<h2><?php esc_html_e( 'Cache and delivery', 'wp-cwv-speed' ); ?></h2>
					<?php
					$this->checkbox( 'page_cache', __( 'Page cache for anonymous visitors', 'wp-cwv-speed' ), __( 'Stores finished HTML and serves it before WordPress boots. Logged-in users, carts, and previews are skipped.', 'wp-cwv-speed' ) );
					$this->number( 'cache_ttl', __( 'Cache lifetime (hours)', 'wp-cwv-speed' ), 1, 168 );
					$this->checkbox( 'cache_mobile', __( 'Separate mobile cache', 'wp-cwv-speed' ), __( 'Use this when the theme prints different HTML for phones.', 'wp-cwv-speed' ) );
					$this->checkbox( 'gzip', __( 'Gzip and Brotli cache files', 'wp-cwv-speed' ), __( 'Brotli is written only when the PHP brotli extension is installed.', 'wp-cwv-speed' ) );
					$this->checkbox( 'browser_cache', __( 'Browser cache headers for static files', 'wp-cwv-speed' ), __( 'Writes an Apache block. Nginx rules are at the bottom of this screen.', 'wp-cwv-speed' ) );
					$this->checkbox( 'minify_html', __( 'Minify HTML', 'wp-cwv-speed' ) );
					?>
				</div>

				<div class="cwv-panel">
					<h2><?php esc_html_e( 'CSS and JavaScript', 'wp-cwv-speed' ); ?></h2>
					<?php
					$this->checkbox( 'minify_css', __( 'Minify CSS', 'wp-cwv-speed' ) );
					$this->checkbox( 'defer_css', __( 'Load CSS without blocking rendering', 'wp-cwv-speed' ), __( 'Runs only after Critical CSS is filled in. Without that CSS, delaying stylesheets drops the Lighthouse score.', 'wp-cwv-speed' ) );
					$this->textarea( 'critical_css', __( 'Critical CSS', 'wp-cwv-speed' ), 6 );
					$this->textarea( 'css_excludes', __( 'CSS handles or URLs to leave blocking', 'wp-cwv-speed' ), 3 );
					$this->checkbox( 'minify_js', __( 'Minify JavaScript', 'wp-cwv-speed' ) );
					$this->checkbox( 'defer_js', __( 'Defer theme and plugin scripts', 'wp-cwv-speed' ), __( 'jQuery stays parser-blocking so older inline scripts keep working. Add a handle below if a plugin breaks.', 'wp-cwv-speed' ) );
					$this->select(
						'delay_js',
						__( 'Delay JavaScript', 'wp-cwv-speed' ),
						array(
							'off'         => __( 'Off', 'wp-cwv-speed' ),
							'third-party' => __( 'Third-party tags only', 'wp-cwv-speed' ),
							'all'         => __( 'All scripts except the exclusion list', 'wp-cwv-speed' ),
						),
						__( 'Delayed scripts run on the first pointer, key, or scroll. Lighthouse does not do those, which is why this lowers Total Blocking Time. Scroll still loads them for real visitors.', 'wp-cwv-speed' )
					);
					$this->number( 'delay_timeout', __( 'Delay fallback (seconds, 0 = interaction only)', 'wp-cwv-speed' ), 0, 60 );
					$this->textarea( 'js_excludes', __( 'Scripts that must never be deferred or delayed', 'wp-cwv-speed' ), 4 );
					$this->textarea( 'delay_patterns', __( 'Third-party URL fragments to delay', 'wp-cwv-speed' ), 8 );
					?>
				</div>

				<div class="cwv-panel">
					<h2><?php esc_html_e( 'Images, video, and LCP', 'wp-cwv-speed' ); ?></h2>
					<?php
					$this->checkbox( 'lcp_priority', __( 'Prioritize the LCP image', 'wp-cwv-speed' ), __( 'Gives the largest early image fetchpriority="high" and removes loading="lazy" from it.', 'wp-cwv-speed' ) );
					$this->checkbox( 'lcp_preload', __( 'Preload the LCP image from the head', 'wp-cwv-speed' ) );
					$this->text( 'lcp_image_url', __( 'Manual LCP image URL', 'wp-cwv-speed' ), __( 'Use this when the hero is a CSS background and does not appear as an img tag.', 'wp-cwv-speed' ) );
					$this->checkbox( 'lazy_images', __( 'Lazy-load images below the first screen', 'wp-cwv-speed' ) );
					$this->number( 'lazy_skip', __( 'Eager images at the top of the page', 'wp-cwv-speed' ), 0, 8 );
					$this->checkbox( 'image_dimensions', __( 'Add missing width and height', 'wp-cwv-speed' ) );
					$this->checkbox( 'missing_sizes', __( 'Add a sizes attribute when srcset has none', 'wp-cwv-speed' ) );
					$this->checkbox( 'webp', __( 'Serve WebP', 'wp-cwv-speed' ), __( 'New uploads are converted automatically. Use the button below for the current library. The original file stays in place.', 'wp-cwv-speed' ) );
					$this->checkbox( 'avif', __( 'Serve AVIF', 'wp-cwv-speed' ), __( 'Only when this server’s image editor can write AVIF.', 'wp-cwv-speed' ) );
					$this->checkbox( 'iframe_lazy', __( 'Lazy-load iframes after the first one', 'wp-cwv-speed' ) );
					$this->checkbox( 'video_facade', __( 'Click-to-play YouTube and Vimeo', 'wp-cwv-speed' ), __( 'Keeps the player JavaScript off the initial load and reserves a 16:9 box so the page does not jump.', 'wp-cwv-speed' ) );
					?>
					<p>
						<button type="button" class="button" id="cwv-convert"><?php esc_html_e( 'Convert existing images', 'wp-cwv-speed' ); ?></button>
						<span id="cwv-convert-status"></span>
					</p>
					<p class="description">
						<?php
						echo esc_html(
							wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) )
								? __( 'WebP encoding is available.', 'wp-cwv-speed' )
								: __( 'WebP encoding is not available in this PHP build.', 'wp-cwv-speed' )
						);
						echo ' ';
						echo esc_html(
							wp_image_editor_supports( array( 'mime_type' => 'image/avif' ) )
								? __( 'AVIF encoding is available.', 'wp-cwv-speed' )
								: __( 'AVIF encoding is not available in this PHP build.', 'wp-cwv-speed' )
						);
						?>
					</p>
				</div>

				<div class="cwv-panel">
					<h2><?php esc_html_e( 'Fonts and next-page loads', 'wp-cwv-speed' ); ?></h2>
					<?php
					$this->checkbox( 'font_display_swap', __( 'font-display: swap', 'wp-cwv-speed' ), __( 'Stops invisible text while web fonts download.', 'wp-cwv-speed' ) );
					$this->checkbox( 'self_host_google_fonts', __( 'Self-host Google Fonts', 'wp-cwv-speed' ), __( 'Downloads the stylesheet and woff2 files so the page does not wait on fonts.googleapis.com.', 'wp-cwv-speed' ) );
					$this->textarea( 'preload_fonts', __( 'Extra font files to preload, one URL per line', 'wp-cwv-speed' ), 3 );
					$this->select(
						'speculation',
						__( 'Speculative loading', 'wp-cwv-speed' ),
						array(
							'core'                  => __( 'WordPress default', 'wp-cwv-speed' ),
							'prefetch-conservative' => __( 'Prefetch on pointer-down', 'wp-cwv-speed' ),
							'prerender-moderate'    => __( 'Prerender on hover', 'wp-cwv-speed' ),
							'prerender-eager'       => __( 'Prerender eagerly', 'wp-cwv-speed' ),
							'off'                   => __( 'Off', 'wp-cwv-speed' ),
						),
						__( 'Prerender makes the next click feel instant. Cart, checkout, and account URLs are excluded.', 'wp-cwv-speed' )
					);
					$this->checkbox( 'content_visibility', __( 'Skip rendering of the footer and comments until they are near the screen', 'wp-cwv-speed' ), __( 'Can reduce rendering work. Leave it off if the footer height jumps.', 'wp-cwv-speed' ) );
					?>
				</div>

				<div class="cwv-panel">
					<h2><?php esc_html_e( 'WordPress cleanup', 'wp-cwv-speed' ); ?></h2>
					<?php
					$this->checkbox( 'disable_emojis', __( 'Remove emoji scripts and styles', 'wp-cwv-speed' ) );
					$this->checkbox( 'disable_embeds', __( 'Remove the wp-embed script and oEmbed discovery links', 'wp-cwv-speed' ) );
					$this->checkbox( 'disable_jquery_migrate', __( 'Remove jQuery Migrate on the front end', 'wp-cwv-speed' ) );
					$this->checkbox( 'disable_dashicons', __( 'Remove Dashicons for logged-out visitors', 'wp-cwv-speed' ) );
					$this->checkbox( 'disable_heartbeat_front', __( 'Disable Heartbeat for logged-out visitors', 'wp-cwv-speed' ) );
					$this->checkbox( 'slow_heartbeat', __( 'Slow Heartbeat to 60 seconds in wp-admin', 'wp-cwv-speed' ) );
					$this->checkbox( 'limit_revisions', __( 'Limit post revisions', 'wp-cwv-speed' ) );
					$this->number( 'revision_max', __( 'Revisions to keep', 'wp-cwv-speed' ), 1, 50 );
					$this->checkbox( 'disable_cart_fragments', __( 'Disable WooCommerce cart fragments outside cart, checkout, and account', 'wp-cwv-speed' ) );
					$this->checkbox( 'disable_xmlrpc', __( 'Disable XML-RPC', 'wp-cwv-speed' ) );
					$this->checkbox( 'disable_rsd', __( 'Remove the RSD link', 'wp-cwv-speed' ) );
					$this->checkbox( 'disable_wlw', __( 'Remove the Windows Live Writer link', 'wp-cwv-speed' ) );
					$this->checkbox( 'disable_generator', __( 'Remove the generator meta tag', 'wp-cwv-speed' ) );
					$this->checkbox( 'disable_shortlink', __( 'Remove the shortlink', 'wp-cwv-speed' ) );
					$this->checkbox( 'disable_feeds', __( 'Remove feed links', 'wp-cwv-speed' ) );
					?>
				</div>

				<p><button type="submit" name="cwv_speed_save" class="button button-primary"><?php esc_html_e( 'Save settings', 'wp-cwv-speed' ); ?></button></p>
			</form>

			<div class="cwv-panel">
				<h2><?php esc_html_e( 'Maintenance', 'wp-cwv-speed' ); ?></h2>
				<p><?php esc_html_e( 'Each button deletes one batch so a large site does not time out. Run it again if more rows remain.', 'wp-cwv-speed' ); ?></p>
				<div class="cwv-actions">
					<?php
					$this->clean_button( 'revisions', __( 'Delete revisions', 'wp-cwv-speed' ) );
					$this->clean_button( 'transients', __( 'Delete expired transients', 'wp-cwv-speed' ) );
					$this->clean_button( 'spam', __( 'Delete spam comments', 'wp-cwv-speed' ) );
					$this->clean_button( 'trash', __( 'Delete trashed posts', 'wp-cwv-speed' ) );
					$this->clean_button( 'drafts', __( 'Delete old auto-drafts', 'wp-cwv-speed' ) );
					?>
					<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=cwv_speed_purge' ), 'cwv_speed_purge' ) ); ?>"><?php esc_html_e( 'Purge page cache', 'wp-cwv-speed' ); ?></a>
				</div>
			</div>

			<div class="cwv-panel">
				<h2><?php esc_html_e( 'Nginx', 'wp-cwv-speed' ); ?></h2>
				<p><?php esc_html_e( 'Put this in the server block if the site is not served by Apache. Do not add a long cache to PHP or HTML responses.', 'wp-cwv-speed' ); ?></p>
				<textarea class="cwv-nginx" rows="12" readonly><?php echo esc_textarea( CWV_Speed_Server_Rules::instance()->nginx_snippet() ); ?></textarea>
			</div>
		</div>
		<?php
	}

	/**
	 * Status pills.
	 *
	 * @return void
	 */
	private function status() {
		$cache_on = CWV_Speed_Cache::instance()->config_has_wp_cache();
		$dropin   = is_readable( WP_CONTENT_DIR . '/advanced-cache.php' );
		$pages    = CWV_Speed_Cache::instance()->page_count();
		echo '<div class="cwv-status">';
		$this->pill( $cache_on, $cache_on ? __( 'WP_CACHE is on', 'wp-cwv-speed' ) : __( 'WP_CACHE is off', 'wp-cwv-speed' ) );
		$this->pill( $dropin, $dropin ? __( 'Cache drop-in installed', 'wp-cwv-speed' ) : __( 'Cache drop-in missing', 'wp-cwv-speed' ) );
		$this->pill( true, sprintf( /* translators: %d: number of cached pages */ __( '%d cached pages', 'wp-cwv-speed' ), $pages ) );
		$this->pill( wp_is_writable( WP_CONTENT_DIR ), wp_is_writable( WP_CONTENT_DIR ) ? __( 'wp-content is writable', 'wp-cwv-speed' ) : __( 'wp-content is not writable', 'wp-cwv-speed' ) );
		echo '</div>';
	}

	/**
	 * One status pill.
	 *
	 * @param bool   $ok Healthy state.
	 * @param string $label Label.
	 * @return void
	 */
	private function pill( $ok, $label ) {
		echo '<span class="cwv-pill ' . esc_attr( $ok ? 'is-ok' : 'is-warn' ) . '">' . esc_html( $label ) . '</span>';
	}

	/**
	 * Preset card.
	 *
	 * @param string $id Preset id.
	 * @param string $title Title.
	 * @param string $text Description.
	 * @param string $current Active preset.
	 * @return void
	 */
	private function preset_card( $id, $title, $text, $current ) {
		echo '<div class="cwv-card' . esc_attr( $current === $id ? ' is-active' : '' ) . '">';
		echo '<h2>' . esc_html( $title ) . '</h2><p>' . esc_html( $text ) . '</p>';
		echo '<form method="post">';
		wp_nonce_field( 'cwv_speed_preset' );
		echo '<button class="button' . esc_attr( 'balanced' === $id ? ' button-primary' : '' ) . '" name="cwv_speed_preset" value="' . esc_attr( $id ) . '">';
		echo esc_html( $current === $id ? __( 'Applied', 'wp-cwv-speed' ) : __( 'Apply preset', 'wp-cwv-speed' ) );
		echo '</button></form></div>';
	}

	/**
	 * Checkbox row.
	 *
	 * @param string $key Setting key.
	 * @param string $label Label.
	 * @param string $help Help text.
	 * @return void
	 */
	private function checkbox( $key, $label, $help = '' ) {
		$settings = CWV_Speed_Settings::instance()->all();
		echo '<label class="cwv-toggle"><input type="checkbox" name="cwv_speed[' . esc_attr( $key ) . ']" value="1" ' . checked( ! empty( $settings[ $key ] ), true, false ) . '> <span>' . esc_html( $label ) . '</span></label>';
		if ( $help ) {
			echo '<p class="description">' . esc_html( $help ) . '</p>';
		}
	}

	/**
	 * Number row.
	 *
	 * @param string $key Setting key.
	 * @param string $label Label.
	 * @param int    $min Minimum.
	 * @param int    $max Maximum.
	 * @return void
	 */
	private function number( $key, $label, $min, $max ) {
		$settings = CWV_Speed_Settings::instance()->all();
		echo '<div class="cwv-field"><label for="cwv-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label>';
		echo '<input id="cwv-' . esc_attr( $key ) . '" type="number" min="' . esc_attr( (string) $min ) . '" max="' . esc_attr( (string) $max ) . '" name="cwv_speed[' . esc_attr( $key ) . ']" value="' . esc_attr( (string) $settings[ $key ] ) . '"></div>';
	}

	/**
	 * Text row.
	 *
	 * @param string $key Setting key.
	 * @param string $label Label.
	 * @param string $help Help text.
	 * @return void
	 */
	private function text( $key, $label, $help = '' ) {
		$settings = CWV_Speed_Settings::instance()->all();
		echo '<div class="cwv-field"><label for="cwv-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label>';
		echo '<input id="cwv-' . esc_attr( $key ) . '" type="url" name="cwv_speed[' . esc_attr( $key ) . ']" value="' . esc_attr( (string) $settings[ $key ] ) . '">';
		if ( $help ) {
			echo '<p class="description">' . esc_html( $help ) . '</p>';
		}
		echo '</div>';
	}

	/**
	 * Textarea row.
	 *
	 * @param string $key Setting key.
	 * @param string $label Label.
	 * @param int    $rows Rows.
	 * @return void
	 */
	private function textarea( $key, $label, $rows ) {
		$settings = CWV_Speed_Settings::instance()->all();
		echo '<div class="cwv-field"><label for="cwv-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label>';
		echo '<textarea id="cwv-' . esc_attr( $key ) . '" rows="' . esc_attr( (string) $rows ) . '" name="cwv_speed[' . esc_attr( $key ) . ']">' . esc_textarea( (string) $settings[ $key ] ) . '</textarea></div>';
	}

	/**
	 * Select row.
	 *
	 * @param string               $key Setting key.
	 * @param string               $label Label.
	 * @param array<string, string> $options Options.
	 * @param string               $help Help text.
	 * @return void
	 */
	private function select( $key, $label, array $options, $help = '' ) {
		$settings = CWV_Speed_Settings::instance()->all();
		echo '<div class="cwv-field"><label for="cwv-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label>';
		echo '<select id="cwv-' . esc_attr( $key ) . '" name="cwv_speed[' . esc_attr( $key ) . ']">';
		foreach ( $options as $value => $text ) {
			echo '<option value="' . esc_attr( $value ) . '" ' . selected( (string) $settings[ $key ], $value, false ) . '>' . esc_html( $text ) . '</option>';
		}
		echo '</select>';
		if ( $help ) {
			echo '<p class="description">' . esc_html( $help ) . '</p>';
		}
		echo '</div>';
	}

	/**
	 * Maintenance button.
	 *
	 * @param string $task Task key.
	 * @param string $label Button label.
	 * @return void
	 */
	private function clean_button( $task, $label ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'cwv_speed_clean' );
		echo '<input type="hidden" name="action" value="cwv_speed_clean">';
		echo '<input type="hidden" name="cwv_task" value="' . esc_attr( $task ) . '">';
		echo '<button class="button" type="submit">' . esc_html( $label ) . '</button></form>';
	}

	/**
	 * Current admin hook suffix.
	 *
	 * @return string
	 */
	private function hook() {
		return isset( $GLOBALS['hook_suffix'] ) ? (string) $GLOBALS['hook_suffix'] : '';
	}
}
