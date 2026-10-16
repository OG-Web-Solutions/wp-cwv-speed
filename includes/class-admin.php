<?php
/**
 * Settings screens.
 *
 * @package CWV_Speed
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin pages for presets, delivery settings, and maintenance.
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
	 * Add the top-level menu and one submenu per section.
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
		foreach ( $this->pages() as $slug => $page ) {
			add_submenu_page(
				'cwv-speed',
				$page['title'],
				$page['menu'],
				'manage_options',
				$slug,
				array( $this, 'render' )
			);
		}
	}

	/**
	 * Load assets on every CWV Speed screen.
	 *
	 * @param string $hook Current admin page.
	 * @return void
	 */
	public function assets( $hook ) {
		unset( $hook );
		if ( ! $this->is_plugin_page() ) {
			return;
		}
		wp_enqueue_style( 'cwv-speed-admin', CWV_SPEED_URL . 'assets/css/admin.css', array(), CWV_SPEED_VERSION );
		wp_enqueue_script( 'cwv-speed-admin', CWV_SPEED_URL . 'assets/js/admin.js', array(), CWV_SPEED_VERSION, true );
		wp_localize_script(
			'cwv-speed-admin',
			'cwvSpeed',
			array(
				'ajax'   => admin_url( 'admin-ajax.php' ),
				'nonce'  => wp_create_nonce( 'cwv_speed_convert' ),
				'copied' => __( 'Copied', 'wp-cwv-speed' ),
			)
		);
	}

	/**
	 * Save the fields that belong to the submitted page.
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
		$page = isset( $_POST['cwv_speed_page'] ) ? sanitize_key( wp_unslash( $_POST['cwv_speed_page'] ) ) : '';
		$keys = $this->page_keys( $page );
		if ( ! $keys ) {
			return;
		}
		$raw = isset( $_POST['cwv_speed'] ) ? wp_unslash( $_POST['cwv_speed'] ) : array();
		$raw = is_array( $raw ) ? $raw : array();
		CWV_Speed_Settings::instance()->save( CWV_Speed_Settings::instance()->sanitize( $raw, $keys ) );
		wp_safe_redirect( admin_url( 'admin.php?page=' . $page . '&updated=1' ) );
		exit;
	}

	/**
	 * Warnings that block the cache from serving.
	 *
	 * @return void
	 */
	public function notices() {
		if ( ! current_user_can( 'manage_options' ) || ! $this->is_plugin_page() ) {
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
	 * Render the current section.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$page = $this->current_page();
		if ( '' === $page ) {
			return;
		}
		$meta = $this->pages()[ $page ];
		?>
		<div class="wrap cwv-wrap">
			<?php $this->header( $meta['kicker'], $meta['title'], $meta['intro'] ); ?>
			<?php $this->banner(); ?>
			<?php
			if ( 'cwv-speed' === $page ) {
				$this->render_overview();
			} elseif ( 'cwv-speed-tools' === $page ) {
				$this->render_tools();
			} else {
				$this->render_form( $page, $meta['fields'] );
			}
			?>
		</div>
		<?php
	}

	/**
	 * Overview: status, presets, and the score weights.
	 *
	 * @return void
	 */
	private function render_overview() {
		$settings = CWV_Speed_Settings::instance()->all();
		$preset   = (string) $settings['preset'];
		$this->status();
		if ( 'custom' === $preset ) {
			echo '<p class="cwv-note">' . esc_html__( 'Current settings are custom. Applying a preset replaces them.', 'wp-cwv-speed' ) . '</p>';
		}
		echo '<div class="cwv-presets">';
		$this->preset_card( 'safe', __( 'Safe', 'wp-cwv-speed' ), __( 'Page cache, image dimensions, LCP preload, and head cleanup. Leaves CSS and JavaScript execution alone.', 'wp-cwv-speed' ), $preset, false );
		$this->preset_card( 'balanced', __( 'Balanced', 'wp-cwv-speed' ), __( 'Page cache, LCP preload, image dimensions, delayed third-party tags, and WordPress cleanup. Theme CSS and JavaScript stay in their normal order.', 'wp-cwv-speed' ), $preset, true );
		$this->preset_card( 'aggressive', __( 'Aggressive', 'wp-cwv-speed' ), __( 'Also delays every script until interaction and turns on AVIF. Test the menu, forms, and checkout after applying it.', 'wp-cwv-speed' ), $preset, false );
		echo '</div>';
		?>
		<div class="cwv-panel">
			<h2><?php esc_html_e( 'Where the score comes from', 'wp-cwv-speed' ); ?></h2>
			<p class="cwv-panel-lead"><?php esc_html_e( 'Lighthouse weights these lab metrics. A good field visit is LCP within 2.5 seconds, INP within 200 milliseconds, and CLS at or below 0.1.', 'wp-cwv-speed' ); ?></p>
			<table class="cwv-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Metric', 'wp-cwv-speed' ); ?></th>
						<th><?php esc_html_e( 'Weight', 'wp-cwv-speed' ); ?></th>
						<th><?php esc_html_e( 'Good result', 'wp-cwv-speed' ); ?></th>
						<th><?php esc_html_e( 'What CWV Speed does', 'wp-cwv-speed' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<tr>
						<td><?php esc_html_e( 'Total Blocking Time', 'wp-cwv-speed' ); ?></td>
						<td><span class="cwv-weight">30%</span></td>
						<td><?php esc_html_e( 'Under 200 ms mobile, under 150 ms desktop', 'wp-cwv-speed' ); ?></td>
						<td><?php esc_html_e( 'Holds analytics, chat, and maps until the first real interaction.', 'wp-cwv-speed' ); ?></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Largest Contentful Paint', 'wp-cwv-speed' ); ?></td>
						<td><span class="cwv-weight">25%</span></td>
						<td><?php esc_html_e( 'Under 2.5 s mobile, under 1.2 s desktop', 'wp-cwv-speed' ); ?></td>
						<td><?php esc_html_e( 'Serves a page cache, preloads the hero, and leaves theme CSS in its normal order.', 'wp-cwv-speed' ); ?></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Cumulative Layout Shift', 'wp-cwv-speed' ); ?></td>
						<td><span class="cwv-weight">25%</span></td>
						<td><?php esc_html_e( '0.1 or less', 'wp-cwv-speed' ); ?></td>
						<td><?php esc_html_e( 'Adds width and height, uses font-display: swap, and reserves a 16:9 box for video.', 'wp-cwv-speed' ); ?></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'First Contentful Paint / Speed Index', 'wp-cwv-speed' ); ?></td>
						<td><span class="cwv-weight">20%</span></td>
						<td><?php esc_html_e( 'FCP under 1.8 s mobile and 0.9 s desktop', 'wp-cwv-speed' ); ?></td>
						<td><?php esc_html_e( 'Uses the same cache and hero work. Less JavaScript runs before the first click.', 'wp-cwv-speed' ); ?></td>
					</tr>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Settings form for one section.
	 *
	 * @param string               $page Page slug.
	 * @param array<int, array<string, mixed>> $fields Fields.
	 * @return void
	 */
	private function render_form( $page, array $fields ) {
		?>
		<form method="post" class="cwv-form">
			<?php wp_nonce_field( 'cwv_speed_save' ); ?>
			<input type="hidden" name="cwv_speed_page" value="<?php echo esc_attr( $page ); ?>">
			<div class="cwv-panel">
				<?php
				foreach ( $fields as $field ) {
					$this->render_field( $field );
				}
				?>
			</div>
			<div class="cwv-savebar">
				<p><?php esc_html_e( 'Saving clears the page cache so the next visit uses these settings.', 'wp-cwv-speed' ); ?></p>
				<button type="submit" name="cwv_speed_save" class="button button-primary button-hero"><?php esc_html_e( 'Save changes', 'wp-cwv-speed' ); ?></button>
			</div>
		</form>
		<?php
	}

	/**
	 * Maintenance tools and the nginx snippet.
	 *
	 * @return void
	 */
	private function render_tools() {
		?>
		<div class="cwv-panel">
			<h2><?php esc_html_e( 'Database cleanup', 'wp-cwv-speed' ); ?></h2>
			<p class="cwv-panel-lead"><?php esc_html_e( 'Each action deletes one batch so a large site does not time out. Run it again when more rows remain.', 'wp-cwv-speed' ); ?></p>
			<div class="cwv-tools">
				<?php
				$this->tool_row( 'revisions', __( 'Revisions', 'wp-cwv-speed' ), __( 'Delete stored revisions, one batch at a time.', 'wp-cwv-speed' ), __( 'Delete revisions', 'wp-cwv-speed' ) );
				$this->tool_row( 'transients', __( 'Transients', 'wp-cwv-speed' ), __( 'Delete expired transients.', 'wp-cwv-speed' ), __( 'Delete expired transients', 'wp-cwv-speed' ) );
				$this->tool_row( 'spam', __( 'Spam', 'wp-cwv-speed' ), __( 'Delete spam comments.', 'wp-cwv-speed' ), __( 'Delete spam comments', 'wp-cwv-speed' ) );
				$this->tool_row( 'trash', __( 'Trash', 'wp-cwv-speed' ), __( 'Delete trashed posts.', 'wp-cwv-speed' ), __( 'Delete trashed posts', 'wp-cwv-speed' ) );
				$this->tool_row( 'drafts', __( 'Auto-drafts', 'wp-cwv-speed' ), __( 'Delete old automatic drafts.', 'wp-cwv-speed' ), __( 'Delete old auto-drafts', 'wp-cwv-speed' ) );
				?>
			</div>
		</div>
		<div class="cwv-panel">
			<h2><?php esc_html_e( 'Page cache', 'wp-cwv-speed' ); ?></h2>
			<div class="cwv-setting">
				<div>
					<h3><?php esc_html_e( 'Purge cached HTML', 'wp-cwv-speed' ); ?></h3>
					<p><?php esc_html_e( 'The next anonymous visit rebuilds each page.', 'wp-cwv-speed' ); ?></p>
				</div>
				<div class="cwv-control">
					<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=cwv_speed_purge' ), 'cwv_speed_purge' ) ); ?>"><?php esc_html_e( 'Purge page cache', 'wp-cwv-speed' ); ?></a>
				</div>
			</div>
		</div>
		<div class="cwv-panel">
			<div class="cwv-panel-head">
				<div>
					<h2><?php esc_html_e( 'Nginx', 'wp-cwv-speed' ); ?></h2>
					<p class="cwv-panel-lead"><?php esc_html_e( 'Put this in the server block when Apache is not serving the site. Keep a short lifetime on PHP and HTML responses.', 'wp-cwv-speed' ); ?></p>
				</div>
				<button type="button" class="button" id="cwv-copy-nginx"><?php esc_html_e( 'Copy snippet', 'wp-cwv-speed' ); ?></button>
			</div>
			<textarea id="cwv-nginx" class="cwv-nginx" rows="12" readonly><?php echo esc_textarea( CWV_Speed_Server_Rules::instance()->nginx_snippet() ); ?></textarea>
		</div>
		<?php
	}

	/**
	 * Page header.
	 *
	 * @param string $kicker Small label.
	 * @param string $title Page title.
	 * @param string $intro Intro sentence.
	 * @return void
	 */
	private function header( $kicker, $title, $intro ) {
		echo '<header class="cwv-header">';
		echo '<div><p class="cwv-kicker">' . esc_html( $kicker ) . '</p>';
		echo '<h1>' . esc_html( $title ) . '</h1>';
		echo '<p class="cwv-lead">' . esc_html( $intro ) . '</p></div>';
		echo '<span class="cwv-version">' . esc_html( 'v' . CWV_SPEED_VERSION ) . '</span>';
		echo '</header>';
	}

	/**
	 * Result banner for the last action.
	 *
	 * @return void
	 */
	private function banner() {
		if ( isset( $_GET['updated'] ) ) {
			echo '<div class="cwv-banner is-ok">' . esc_html__( 'Settings saved. The page cache was cleared.', 'wp-cwv-speed' ) . '</div>';
		}
		if ( isset( $_GET['cwv_purged'] ) ) {
			echo '<div class="cwv-banner is-ok">' . esc_html__( 'Page cache purged.', 'wp-cwv-speed' ) . '</div>';
		}
		if ( isset( $_GET['cwv_clean'], $_GET['cwv_count'] ) ) {
			echo '<div class="cwv-banner is-ok">' . esc_html(
				sprintf(
					/* translators: %d: number of rows removed */
					__( 'Cleanup finished. %d rows removed in this batch.', 'wp-cwv-speed' ),
					absint( wp_unslash( $_GET['cwv_count'] ) )
				)
			) . '</div>';
		}
	}

	/**
	 * Status cards.
	 *
	 * @return void
	 */
	private function status() {
		$cache_on = CWV_Speed_Cache::instance()->config_has_wp_cache();
		$dropin   = is_readable( WP_CONTENT_DIR . '/advanced-cache.php' );
		$pages    = CWV_Speed_Cache::instance()->page_count();
		$writable = wp_is_writable( WP_CONTENT_DIR );
		echo '<div class="cwv-stats">';
		$this->stat( __( 'Page cache', 'wp-cwv-speed' ), $cache_on ? __( 'On', 'wp-cwv-speed' ) : __( 'Off', 'wp-cwv-speed' ), $cache_on ? __( 'WP_CACHE is set', 'wp-cwv-speed' ) : __( 'WP_CACHE is missing', 'wp-cwv-speed' ), $cache_on );
		$this->stat( __( 'Drop-in', 'wp-cwv-speed' ), $dropin ? __( 'Installed', 'wp-cwv-speed' ) : __( 'Missing', 'wp-cwv-speed' ), $dropin ? __( 'advanced-cache.php is in place', 'wp-cwv-speed' ) : __( 'The early cache file is missing', 'wp-cwv-speed' ), $dropin );
		$this->stat( __( 'Cached pages', 'wp-cwv-speed' ), (string) $pages, __( 'Anonymous HTML files on disk', 'wp-cwv-speed' ), true );
		$this->stat( __( 'wp-content', 'wp-cwv-speed' ), $writable ? __( 'Writable', 'wp-cwv-speed' ) : __( 'Locked', 'wp-cwv-speed' ), $writable ? __( 'Cache files can be stored', 'wp-cwv-speed' ) : __( 'The cache directory cannot be written', 'wp-cwv-speed' ), $writable );
		echo '</div>';
	}

	/**
	 * One status card.
	 *
	 * @param string $label Small label.
	 * @param string $value Main value.
	 * @param string $detail Second line.
	 * @param bool   $ok Healthy state.
	 * @return void
	 */
	private function stat( $label, $value, $detail, $ok ) {
		echo '<div class="cwv-stat ' . esc_attr( $ok ? 'is-ok' : 'is-warn' ) . '">';
		echo '<span>' . esc_html( $label ) . '</span>';
		echo '<strong>' . esc_html( $value ) . '</strong>';
		echo '<em>' . esc_html( $detail ) . '</em>';
		echo '</div>';
	}

	/**
	 * Preset card.
	 *
	 * @param string $id Preset id.
	 * @param string $title Title.
	 * @param string $text Description.
	 * @param string $current Active preset.
	 * @param bool   $recommended Show the recommended badge.
	 * @return void
	 */
	private function preset_card( $id, $title, $text, $current, $recommended ) {
		$active = $current === $id;
		echo '<article class="cwv-card' . esc_attr( $active ? ' is-active' : '' ) . '">';
		if ( $recommended ) {
			echo '<span class="cwv-badge">' . esc_html__( 'Recommended', 'wp-cwv-speed' ) . '</span>';
		}
		echo '<h2>' . esc_html( $title ) . '</h2><p>' . esc_html( $text ) . '</p>';
		echo '<form method="post">';
		wp_nonce_field( 'cwv_speed_preset' );
		echo '<button class="button' . esc_attr( ( $recommended && ! $active ) ? ' button-primary' : '' ) . '" name="cwv_speed_preset" value="' . esc_attr( $id ) . '">';
		echo esc_html( $active ? __( 'Applied', 'wp-cwv-speed' ) : __( 'Apply preset', 'wp-cwv-speed' ) );
		echo '</button></form></article>';
	}

	/**
	 * Render one settings field.
	 *
	 * @param array<string, mixed> $field Field definition.
	 * @return void
	 */
	private function render_field( array $field ) {
		$type = isset( $field['type'] ) ? (string) $field['type'] : '';
		if ( 'heading' === $type ) {
			echo '<h2 class="cwv-subhead">' . esc_html( (string) $field['label'] ) . '</h2>';
			if ( ! empty( $field['help'] ) ) {
				echo '<p class="cwv-subhead-help">' . esc_html( (string) $field['help'] ) . '</p>';
			}
			return;
		}
		if ( 'convert' === $type ) {
			$this->convert_row();
			return;
		}

		$key      = (string) $field['key'];
		$settings = CWV_Speed_Settings::instance()->all();
		$wide     = in_array( $type, array( 'textarea', 'url' ), true );
		echo '<div class="cwv-setting' . esc_attr( $wide ? ' is-wide' : '' ) . '">';
		echo '<div><h3>' . esc_html( (string) $field['label'] ) . '</h3>';
		if ( ! empty( $field['help'] ) ) {
			echo '<p>' . esc_html( (string) $field['help'] ) . '</p>';
		}
		echo '</div><div class="cwv-control">';

		$labelled = ' aria-label="' . esc_attr( (string) $field['label'] ) . '"';
		if ( 'checkbox' === $type ) {
			echo '<label class="cwv-switch"><input type="checkbox" name="cwv_speed[' . esc_attr( $key ) . ']" value="1"' . $labelled . ' ' . checked( ! empty( $settings[ $key ] ), true, false ) . '><i></i></label>';
		} elseif ( 'number' === $type ) {
			echo '<input class="cwv-number" id="cwv-' . esc_attr( $key ) . '" type="number" min="' . esc_attr( (string) $field['min'] ) . '" max="' . esc_attr( (string) $field['max'] ) . '" name="cwv_speed[' . esc_attr( $key ) . ']" value="' . esc_attr( (string) $settings[ $key ] ) . '"' . $labelled . '>';
		} elseif ( 'url' === $type ) {
			echo '<input class="cwv-text" id="cwv-' . esc_attr( $key ) . '" type="url" name="cwv_speed[' . esc_attr( $key ) . ']" value="' . esc_attr( (string) $settings[ $key ] ) . '"' . $labelled . '>';
		} elseif ( 'textarea' === $type ) {
			echo '<textarea id="cwv-' . esc_attr( $key ) . '" rows="' . esc_attr( (string) $field['rows'] ) . '" name="cwv_speed[' . esc_attr( $key ) . ']"' . $labelled . '>' . esc_textarea( (string) $settings[ $key ] ) . '</textarea>';
		} elseif ( 'select' === $type && isset( $field['options'] ) && is_array( $field['options'] ) ) {
			echo '<select id="cwv-' . esc_attr( $key ) . '" name="cwv_speed[' . esc_attr( $key ) . ']"' . $labelled . '>';
			foreach ( $field['options'] as $value => $text ) {
				echo '<option value="' . esc_attr( (string) $value ) . '" ' . selected( (string) $settings[ $key ], (string) $value, false ) . '>' . esc_html( (string) $text ) . '</option>';
			}
			echo '</select>';
		}

		echo '</div></div>';
	}

	/**
	 * Image conversion control.
	 *
	 * @return void
	 */
	private function convert_row() {
		echo '<div class="cwv-setting">';
		echo '<div><h3>' . esc_html__( 'Existing library', 'wp-cwv-speed' ) . '</h3>';
		echo '<p>' . esc_html__( 'New uploads are converted on their own. This walks the current library two images at a time. Originals stay in place.', 'wp-cwv-speed' ) . '</p>';
		echo '<p class="cwv-codec">';
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
		echo '</p></div>';
		echo '<div class="cwv-control cwv-control-stack"><button type="button" class="button" id="cwv-convert">' . esc_html__( 'Convert existing images', 'wp-cwv-speed' ) . '</button>';
		echo '<span id="cwv-convert-status"></span></div></div>';
	}

	/**
	 * One maintenance row.
	 *
	 * @param string $task Task key.
	 * @param string $title Title.
	 * @param string $help Help text.
	 * @param string $label Button label.
	 * @return void
	 */
	private function tool_row( $task, $title, $help, $label ) {
		echo '<div class="cwv-setting"><div><h3>' . esc_html( $title ) . '</h3><p>' . esc_html( $help ) . '</p></div>';
		echo '<div class="cwv-control"><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'cwv_speed_clean' );
		echo '<input type="hidden" name="action" value="cwv_speed_clean">';
		echo '<input type="hidden" name="cwv_task" value="' . esc_attr( $task ) . '">';
		echo '<button class="button" type="submit">' . esc_html( $label ) . '</button></form></div></div>';
	}

	/**
	 * Admin sections. The first slug matches the top-level menu.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function pages() {
		return array(
			'cwv-speed'         => array(
				'menu'   => __( 'Overview', 'wp-cwv-speed' ),
				'title'  => __( 'Overview', 'wp-cwv-speed' ),
				'kicker' => __( 'CWV Speed', 'wp-cwv-speed' ),
				'intro'  => __( 'Start with Balanced. It aims at the metrics that make up most of the Lighthouse performance score, and it leaves your theme CSS and JavaScript in their normal order.', 'wp-cwv-speed' ),
				'keys'   => array(),
				'fields' => array(),
			),
			'cwv-speed-cache'   => array(
				'menu'   => __( 'Cache', 'wp-cwv-speed' ),
				'title'  => __( 'Cache', 'wp-cwv-speed' ),
				'kicker' => __( 'Delivery', 'wp-cwv-speed' ),
				'intro'  => __( 'Anonymous visitors receive a finished HTML file before WordPress boots. Logged-in users, carts, and previews always hit PHP.', 'wp-cwv-speed' ),
				'fields' => array(
					$this->check( 'page_cache', __( 'Page cache', 'wp-cwv-speed' ), __( 'Store finished HTML for anonymous visitors.', 'wp-cwv-speed' ) ),
					$this->num( 'cache_ttl', __( 'Cache lifetime', 'wp-cwv-speed' ), __( 'Hours before a cached page is rebuilt. The browser still revalidates HTML.', 'wp-cwv-speed' ), 1, 168 ),
					$this->check( 'cache_mobile', __( 'Separate mobile cache', 'wp-cwv-speed' ), __( 'Use this when the theme prints different HTML for phones.', 'wp-cwv-speed' ) ),
					$this->check( 'gzip', __( 'Gzip and Brotli', 'wp-cwv-speed' ), __( 'Brotli is written when the PHP brotli extension is installed.', 'wp-cwv-speed' ) ),
					$this->check( 'browser_cache', __( 'Static file headers', 'wp-cwv-speed' ), __( 'Writes a one-year Apache cache for CSS, JavaScript, images, and fonts. The nginx snippet is on the Tools page.', 'wp-cwv-speed' ) ),
					$this->check( 'minify_html', __( 'Minify HTML', 'wp-cwv-speed' ), __( 'Removes extra whitespace from the cached document.', 'wp-cwv-speed' ) ),
				),
			),
			'cwv-speed-files'   => array(
				'menu'   => __( 'CSS & JavaScript', 'wp-cwv-speed' ),
				'title'  => __( 'CSS & JavaScript', 'wp-cwv-speed' ),
				'kicker' => __( 'Render path', 'wp-cwv-speed' ),
				'intro'  => __( 'Theme stylesheets stay render-blocking. Plugin CSS that is not needed for the first paint, and third-party tags, can wait.', 'wp-cwv-speed' ),
				'fields' => array(
					array(
						'type'  => 'heading',
						'label' => __( 'CSS', 'wp-cwv-speed' ),
					),
					$this->check( 'minify_css', __( 'Minify CSS', 'wp-cwv-speed' ), __( 'Rewrites local stylesheets to a smaller cached copy.', 'wp-cwv-speed' ) ),
					$this->check( 'defer_css', __( 'Defer CSS after critical CSS is set', 'wp-cwv-speed' ), __( 'This runs only when the critical CSS box below has rules in it. An empty box leaves stylesheets blocking, which protects the score.', 'wp-cwv-speed' ) ),
					$this->area( 'critical_css', __( 'Critical CSS', 'wp-cwv-speed' ), __( 'CSS for the first screen. Paste this before turning on deferred CSS.', 'wp-cwv-speed' ), 6 ),
					$this->area( 'css_excludes', __( 'CSS to leave blocking', 'wp-cwv-speed' ), __( 'One handle or URL fragment per line.', 'wp-cwv-speed' ), 3 ),
					array(
						'type'  => 'heading',
						'label' => __( 'JavaScript', 'wp-cwv-speed' ),
					),
					$this->check( 'minify_js', __( 'Minify JavaScript', 'wp-cwv-speed' ), __( 'Strips comments and extra whitespace. It does not rename variables.', 'wp-cwv-speed' ) ),
					$this->check( 'defer_js', __( 'Defer theme and plugin scripts', 'wp-cwv-speed' ), __( 'jQuery stays in the footer so scripts that depend on it still run in order. Add a handle below if a plugin breaks.', 'wp-cwv-speed' ) ),
					array(
						'type'    => 'select',
						'key'     => 'delay_js',
						'label'   => __( 'Delay JavaScript', 'wp-cwv-speed' ),
						'help'    => __( 'Delayed scripts run on the first pointer, key, or scroll. Lighthouse does not do those. Real visitors still receive the tags.', 'wp-cwv-speed' ),
						'options' => array(
							'off'         => __( 'Off', 'wp-cwv-speed' ),
							'third-party' => __( 'Third-party tags only', 'wp-cwv-speed' ),
							'all'         => __( 'All scripts except the exclusion list', 'wp-cwv-speed' ),
						),
					),
					$this->num( 'delay_timeout', __( 'Delay fallback', 'wp-cwv-speed' ), __( 'Seconds before delayed scripts load on their own. Use 0 to wait for an interaction.', 'wp-cwv-speed' ), 0, 60 ),
					$this->area( 'js_excludes', __( 'Scripts to leave immediate', 'wp-cwv-speed' ), __( 'One handle or URL fragment per line. These are never deferred or delayed.', 'wp-cwv-speed' ), 4 ),
					$this->area( 'delay_patterns', __( 'Third-party URL fragments', 'wp-cwv-speed' ), __( 'One fragment per line. Matching script URLs wait for an interaction.', 'wp-cwv-speed' ), 8 ),
				),
			),
			'cwv-speed-media'   => array(
				'menu'   => __( 'Images', 'wp-cwv-speed' ),
				'title'  => __( 'Images & video', 'wp-cwv-speed' ),
				'kicker' => __( 'Media', 'wp-cwv-speed' ),
				'intro'  => __( 'The hero image stays eager and preloaded. Images further down the page wait, and missing dimensions are filled in so the layout holds still.', 'wp-cwv-speed' ),
				'fields' => array(
					array(
						'type'  => 'heading',
						'label' => __( 'Largest Contentful Paint', 'wp-cwv-speed' ),
					),
					$this->check( 'lcp_priority', __( 'Prioritize the LCP image', 'wp-cwv-speed' ), __( 'Gives the largest early image fetchpriority="high" and removes loading="lazy" from it.', 'wp-cwv-speed' ) ),
					$this->check( 'lcp_preload', __( 'Preload the LCP image', 'wp-cwv-speed' ), __( 'Prints a preload link in the document head.', 'wp-cwv-speed' ) ),
					$this->url( 'lcp_image_url', __( 'Manual LCP image URL', 'wp-cwv-speed' ), __( 'Use this when the hero is a CSS background and does not appear as an img tag.', 'wp-cwv-speed' ) ),
					array(
						'type'  => 'heading',
						'label' => __( 'Layout', 'wp-cwv-speed' ),
					),
					$this->check( 'lazy_images', __( 'Lazy-load images below the first screen', 'wp-cwv-speed' ), '' ),
					$this->num( 'lazy_skip', __( 'Eager images at the top', 'wp-cwv-speed' ), __( 'This many images at the start of the page stay eager.', 'wp-cwv-speed' ), 0, 8 ),
					$this->check( 'image_dimensions', __( 'Add missing width and height', 'wp-cwv-speed' ), __( 'Reads the size from the file, including SVG viewBox values.', 'wp-cwv-speed' ) ),
					$this->check( 'missing_sizes', __( 'Add a sizes attribute', 'wp-cwv-speed' ), __( 'Fills sizes when a srcset is present and sizes is missing.', 'wp-cwv-speed' ) ),
					array(
						'type'  => 'heading',
						'label' => __( 'Formats', 'wp-cwv-speed' ),
					),
					$this->check( 'webp', __( 'Serve WebP', 'wp-cwv-speed' ), __( 'Browsers that understand WebP receive a sidecar. The original file stays in place.', 'wp-cwv-speed' ) ),
					$this->check( 'avif', __( 'Serve AVIF', 'wp-cwv-speed' ), __( 'Used when this server’s image editor can write AVIF.', 'wp-cwv-speed' ) ),
					array( 'type' => 'convert' ),
					array(
						'type'  => 'heading',
						'label' => __( 'Video and embeds', 'wp-cwv-speed' ),
					),
					$this->check( 'iframe_lazy', __( 'Lazy-load later iframes', 'wp-cwv-speed' ), __( 'The first iframe stays eager. Later ones wait.', 'wp-cwv-speed' ) ),
					$this->check( 'video_facade', __( 'Click-to-play YouTube and Vimeo', 'wp-cwv-speed' ), __( 'Keeps the player JavaScript off the initial load and reserves a 16:9 box.', 'wp-cwv-speed' ) ),
				),
			),
			'cwv-speed-fonts'   => array(
				'menu'   => __( 'Fonts', 'wp-cwv-speed' ),
				'title'  => __( 'Fonts & next page', 'wp-cwv-speed' ),
				'kicker' => __( 'Loading', 'wp-cwv-speed' ),
				'intro'  => __( 'Text stays visible while fonts download. Speculation rules make the next same-site click faster, and cart, checkout, and account URLs stay excluded.', 'wp-cwv-speed' ),
				'fields' => array(
					$this->check( 'font_display_swap', __( 'font-display: swap', 'wp-cwv-speed' ), __( 'Shows fallback text immediately, then swaps in the web font.', 'wp-cwv-speed' ) ),
					$this->check( 'self_host_google_fonts', __( 'Self-host Google Fonts', 'wp-cwv-speed' ), __( 'Downloads the stylesheet and woff2 files so the page does not wait on fonts.googleapis.com.', 'wp-cwv-speed' ) ),
					$this->area( 'preload_fonts', __( 'Font files to preload', 'wp-cwv-speed' ), __( 'One absolute URL per line.', 'wp-cwv-speed' ), 3 ),
					array(
						'type'    => 'select',
						'key'     => 'speculation',
						'label'   => __( 'Speculative loading', 'wp-cwv-speed' ),
						'help'    => __( 'Prerender prepares the next page on hover. Prefetch only fetches it.', 'wp-cwv-speed' ),
						'options' => array(
							'core'                  => __( 'WordPress default', 'wp-cwv-speed' ),
							'prefetch-conservative' => __( 'Prefetch on pointer-down', 'wp-cwv-speed' ),
							'prerender-moderate'    => __( 'Prerender on hover', 'wp-cwv-speed' ),
							'prerender-eager'       => __( 'Prerender eagerly', 'wp-cwv-speed' ),
							'off'                   => __( 'Off', 'wp-cwv-speed' ),
						),
					),
					$this->check( 'content_visibility', __( 'Defer footer rendering', 'wp-cwv-speed' ), __( 'Skips layout work for the footer and comments until they are near the screen. Leave this off if the footer height jumps.', 'wp-cwv-speed' ) ),
				),
			),
			'cwv-speed-cleanup' => array(
				'menu'   => __( 'Cleanup', 'wp-cwv-speed' ),
				'title'  => __( 'WordPress cleanup', 'wp-cwv-speed' ),
				'kicker' => __( 'Front end', 'wp-cwv-speed' ),
				'intro'  => __( 'Removes WordPress extras that logged-out visitors do not need. The admin screens keep their own scripts.', 'wp-cwv-speed' ),
				'fields' => array(
					array(
						'type'  => 'heading',
						'label' => __( 'Scripts', 'wp-cwv-speed' ),
					),
					$this->check( 'disable_emojis', __( 'Emoji scripts and styles', 'wp-cwv-speed' ), __( 'Removes the emoji loader from the front end.', 'wp-cwv-speed' ) ),
					$this->check( 'disable_embeds', __( 'Embed script', 'wp-cwv-speed' ), __( 'Removes wp-embed and the oEmbed discovery links.', 'wp-cwv-speed' ) ),
					$this->check( 'disable_jquery_migrate', __( 'jQuery Migrate', 'wp-cwv-speed' ), __( 'Drops the migrate helper on the front end.', 'wp-cwv-speed' ) ),
					$this->check( 'disable_dashicons', __( 'Dashicons', 'wp-cwv-speed' ), __( 'Logged-out visitors skip the icon font. Logged-in visits still defer it.', 'wp-cwv-speed' ) ),
					$this->check( 'disable_heartbeat_front', __( 'Heartbeat', 'wp-cwv-speed' ), __( 'Turns Heartbeat off for logged-out visitors.', 'wp-cwv-speed' ) ),
					$this->check( 'slow_heartbeat', __( 'Slower admin Heartbeat', 'wp-cwv-speed' ), __( 'Runs Heartbeat every 60 seconds in wp-admin.', 'wp-cwv-speed' ) ),
					$this->check( 'disable_cart_fragments', __( 'WooCommerce cart fragments', 'wp-cwv-speed' ), __( 'Stays on for the cart, checkout, and account pages.', 'wp-cwv-speed' ) ),
					array(
						'type'  => 'heading',
						'label' => __( 'Database and head links', 'wp-cwv-speed' ),
					),
					$this->check( 'limit_revisions', __( 'Limit post revisions', 'wp-cwv-speed' ), __( 'Caps how many revisions WordPress keeps from now on.', 'wp-cwv-speed' ) ),
					$this->num( 'revision_max', __( 'Revisions to keep', 'wp-cwv-speed' ), '', 1, 50 ),
					$this->check( 'disable_xmlrpc', __( 'XML-RPC', 'wp-cwv-speed' ), __( 'Turns off the XML-RPC endpoint.', 'wp-cwv-speed' ) ),
					$this->check( 'disable_rsd', __( 'RSD link', 'wp-cwv-speed' ), '' ),
					$this->check( 'disable_wlw', __( 'Windows Live Writer link', 'wp-cwv-speed' ), '' ),
					$this->check( 'disable_generator', __( 'Generator meta tag', 'wp-cwv-speed' ), '' ),
					$this->check( 'disable_shortlink', __( 'Shortlink', 'wp-cwv-speed' ), '' ),
					$this->check( 'disable_feeds', __( 'Feed links', 'wp-cwv-speed' ), __( 'Removes the feed links from the head. The feeds themselves still respond.', 'wp-cwv-speed' ) ),
				),
			),
			'cwv-speed-tools'   => array(
				'menu'   => __( 'Tools', 'wp-cwv-speed' ),
				'title'  => __( 'Tools', 'wp-cwv-speed' ),
				'kicker' => __( 'Maintenance', 'wp-cwv-speed' ),
				'intro'  => __( 'Clear the page cache, trim old database rows, and copy the nginx cache rules.', 'wp-cwv-speed' ),
				'keys'   => array(),
				'fields' => array(),
			),
		);
	}

	/**
	 * Checkbox field.
	 *
	 * @param string $key Setting key.
	 * @param string $label Label.
	 * @param string $help Help text.
	 * @return array<string, string>
	 */
	private function check( $key, $label, $help ) {
		return array(
			'type'  => 'checkbox',
			'key'   => $key,
			'label' => $label,
			'help'  => $help,
		);
	}

	/**
	 * Number field.
	 *
	 * @param string $key Setting key.
	 * @param string $label Label.
	 * @param string $help Help text.
	 * @param int    $min Minimum.
	 * @param int    $max Maximum.
	 * @return array<string, mixed>
	 */
	private function num( $key, $label, $help, $min, $max ) {
		return array(
			'type'  => 'number',
			'key'   => $key,
			'label' => $label,
			'help'  => $help,
			'min'   => $min,
			'max'   => $max,
		);
	}

	/**
	 * URL field.
	 *
	 * @param string $key Setting key.
	 * @param string $label Label.
	 * @param string $help Help text.
	 * @return array<string, string>
	 */
	private function url( $key, $label, $help ) {
		return array(
			'type'  => 'url',
			'key'   => $key,
			'label' => $label,
			'help'  => $help,
		);
	}

	/**
	 * Textarea field.
	 *
	 * @param string $key Setting key.
	 * @param string $label Label.
	 * @param string $help Help text.
	 * @param int    $rows Rows.
	 * @return array<string, mixed>
	 */
	private function area( $key, $label, $help, $rows ) {
		return array(
			'type'  => 'textarea',
			'key'   => $key,
			'label' => $label,
			'help'  => $help,
			'rows'  => $rows,
		);
	}

	/**
	 * Setting keys posted by one admin page.
	 *
	 * @param string $page Page slug.
	 * @return string[]
	 */
	private function page_keys( $page ) {
		$pages = $this->pages();
		if ( ! isset( $pages[ $page ]['fields'] ) || ! is_array( $pages[ $page ]['fields'] ) ) {
			return array();
		}
		$keys = array();
		foreach ( $pages[ $page ]['fields'] as $field ) {
			if ( ! empty( $field['key'] ) ) {
				$keys[] = (string) $field['key'];
			}
		}
		return $keys;
	}

	/**
	 * Whether the current request is one of our admin pages.
	 *
	 * @return bool
	 */
	private function is_plugin_page() {
		return '' !== $this->current_page();
	}

	/**
	 * Current page slug, or an empty string off these screens.
	 *
	 * @return string
	 */
	private function current_page() {
		if ( ! isset( $_GET['page'] ) ) {
			return '';
		}
		$page = sanitize_key( wp_unslash( $_GET['page'] ) );
		return isset( $this->pages()[ $page ] ) ? $page : '';
	}
}
