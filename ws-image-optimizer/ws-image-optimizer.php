<?php
/**
 * Plugin Name:       WS Image Optimizer
 * Plugin URI:        https://github.com/prorabra/wp
 * Description:       Стискає зображення під час завантаження в медіатеку, за потреби конвертує JPEG/PNG у WebP і видаляє оригінали, щоб вони не займали місце на сервері. Вміє пакетно обробити вже завантажені зображення й оновити посилання на них у записах.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Zaichenko Andrey
 * Author URI:        https://websites-service.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ws-image-optimizer
 */

defined( 'ABSPATH' ) || exit;

final class WS_Image_Optimizer {

	const OPTION     = 'wsio_settings';
	const META       = '_wsio_status';
	const META_SAVED = '_wsio_saved';
	const SLUG       = 'ws-image-optimizer';

	/** Formats the plugin processes. GIF (animation) and SVG are left untouched on purpose. */
	const SOURCE_MIMES = array( 'image/jpeg', 'image/png', 'image/webp' );

	/** Formats converted by the bulk tool (existing WebP files are not re-compressed). */
	const BULK_MIMES = array( 'image/jpeg', 'image/png' );

	public static function init(): void {
		add_filter( 'wp_handle_upload', array( __CLASS__, 'on_upload' ), 20, 2 );
		add_filter( 'wp_editor_set_quality', array( __CLASS__, 'editor_quality' ), 20, 2 );
		add_filter( 'big_image_size_threshold', array( __CLASS__, 'big_image_threshold' ), 20 );

		if ( is_admin() ) {
			add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
			add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
			add_action( 'wp_ajax_wsio_bulk', array( __CLASS__, 'ajax_bulk' ) );
			add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( __CLASS__, 'action_links' ) );
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'ws-image bulk', array( __CLASS__, 'cli_bulk' ) );
		}
	}

	/* ---------------------------------------------------------------------
	 * Settings
	 * ------------------------------------------------------------------- */

	public static function defaults(): array {
		return array(
			'on_upload'       => 1,    // optimize new uploads
			'webp'            => 1,    // convert JPEG/PNG to WebP
			'quality'         => 82,   // 82 is visually indistinguishable for photos
			'max_side'        => 2560, // longest side in px, 0 = keep size (2560 = WordPress default)
			'delete_original' => 1,    // remove the source file after a successful conversion
			'replace_urls'    => 1,    // bulk tool: update links in posts
		);
	}

	public static function settings(): array {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	public static function sanitize_settings( $input ): array {
		$input = is_array( $input ) ? $input : array();
		$out   = array();
		foreach ( array( 'on_upload', 'webp', 'delete_original', 'replace_urls' ) as $key ) {
			$out[ $key ] = empty( $input[ $key ] ) ? 0 : 1;
		}
		$out['quality']  = min( 100, max( 40, absint( $input['quality'] ?? 82 ) ) );
		$max             = absint( $input['max_side'] ?? 0 );
		$out['max_side'] = 0 === $max ? 0 : min( 10000, max( 320, $max ) );
		return $out;
	}

	public static function webp_supported(): bool {
		return wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) );
	}

	/* ---------------------------------------------------------------------
	 * Hooks for new uploads
	 * ------------------------------------------------------------------- */

	/** The same quality for the main file and for every sub-size WordPress generates. */
	public static function editor_quality( $quality, $mime = '' ) {
		return in_array( $mime, self::SOURCE_MIMES, true ) ? self::settings()['quality'] : $quality;
	}

	/**
	 * The plugin limits the size itself. WordPress' own threshold would keep the
	 * full-size original next to a "-scaled" copy, which is what we want to avoid.
	 */
	public static function big_image_threshold( $threshold ) {
		return self::settings()['on_upload'] ? false : $threshold;
	}

	/** Runs right after the file lands in /uploads and before the attachment is created. */
	public static function on_upload( $upload, $context = 'upload' ) {
		$s = self::settings();
		if ( ! $s['on_upload'] || ! empty( $upload['error'] ) || empty( $upload['file'] ) || empty( $upload['type'] ) ) {
			return $upload;
		}
		if ( ! in_array( $upload['type'], self::SOURCE_MIMES, true ) || ! self::is_in_uploads( $upload['file'] ) ) {
			return $upload;
		}

		$result = self::optimize_file( $upload['file'], $upload['type'], ! $s['delete_original'] );
		if ( is_wp_error( $result ) || empty( $result['changed'] ) ) {
			return $upload; // Keep the untouched upload on any failure.
		}

		$upload['url']  = trailingslashit( dirname( $upload['url'] ) ) . wp_basename( $result['path'] );
		$upload['file'] = $result['path'];
		$upload['type'] = $result['mime'];
		return $upload;
	}

	/* ---------------------------------------------------------------------
	 * Core: optimize one file
	 * ------------------------------------------------------------------- */

	/**
	 * Compresses (and optionally converts) one image file.
	 *
	 * @return array|WP_Error ['changed' => bool, 'path' => string, 'mime' => string, 'saved' => int]
	 */
	public static function optimize_file( string $src, string $src_mime, bool $keep_original ) {
		$s = self::settings();
		if ( ! is_file( $src ) || ! self::is_in_uploads( $src ) ) {
			return new WP_Error( 'wsio_missing', __( 'Файл не знайдено.', 'ws-image-optimizer' ) );
		}

		$editor = wp_get_image_editor( $src );
		if ( is_wp_error( $editor ) ) {
			return $editor;
		}
		if ( method_exists( $editor, 'maybe_exif_rotate' ) ) {
			$editor->maybe_exif_rotate(); // Fix phone photos before EXIF is lost.
		}
		$editor->set_quality( $s['quality'] );

		$resized = false;
		$size    = $editor->get_size();
		if ( $s['max_side'] > 0 && max( (int) $size['width'], (int) $size['height'] ) > $s['max_side'] ) {
			$r = $editor->resize( $s['max_side'], $s['max_side'], false );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
			$resized = true;
		}

		$to_webp = $s['webp'] && 'image/webp' !== $src_mime && self::webp_supported();
		$mime    = $to_webp ? 'image/webp' : $src_mime;
		$ext     = $to_webp ? 'webp' : strtolower( pathinfo( $src, PATHINFO_EXTENSION ) );
		$dir     = dirname( $src );
		$name    = preg_replace( '/-scaled$/', '', pathinfo( $src, PATHINFO_FILENAME ) );

		$tmp   = $dir . '/' . wp_unique_filename( $dir, $name . '-wsio-tmp.' . $ext );
		$saved = $editor->save( $tmp, $mime );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		$tmp = $saved['path'];

		clearstatcache();
		$old_bytes = (int) filesize( $src );
		$new_bytes = (int) filesize( $tmp );

		// Nothing gained (already well optimized): keep the original as it is.
		if ( ! $resized && $new_bytes >= $old_bytes ) {
			wp_delete_file( $tmp );
			return array( 'changed' => false, 'path' => $src, 'mime' => $src_mime, 'saved' => 0 );
		}

		$final     = $dir . '/' . $name . '.' . $ext;
		$overwrite = ( $final === $src && ! $keep_original );
		if ( ! $overwrite && file_exists( $final ) ) {
			$final = $dir . '/' . wp_unique_filename( $dir, $name . '.' . $ext );
		}
		if ( $overwrite ) {
			wp_delete_file( $src ); // Windows cannot rename over an existing file.
		}
		if ( ! @rename( $tmp, $final ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			wp_delete_file( $tmp );
			return new WP_Error( 'wsio_rename', __( 'Не вдалося зберегти оптимізований файл.', 'ws-image-optimizer' ) );
		}
		self::fix_permissions( $final );

		if ( ! $keep_original && $final !== $src ) {
			self::delete_file( $src );
		}

		return array(
			'changed' => true,
			'path'    => $final,
			'mime'    => $mime,
			'saved'   => max( 0, $old_bytes - $new_bytes ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Existing attachments (bulk)
	 * ------------------------------------------------------------------- */

	/** @return string|WP_Error Short human-readable result. */
	public static function process_attachment( int $id ) {
		$s    = self::settings();
		$mime = get_post_mime_type( $id );
		$file = get_attached_file( $id );

		if ( ! $file || ! in_array( $mime, self::BULK_MIMES, true ) || ! is_file( $file ) ) {
			update_post_meta( $id, self::META, 'skipped' );
			return __( 'пропущено (файл відсутній або формат не підтримується)', 'ws-image-optimizer' );
		}

		$meta     = wp_get_attachment_metadata( $id );
		$meta     = is_array( $meta ) ? $meta : array();
		$dir      = dirname( $file );
		$base_url = trailingslashit( dirname( wp_get_attachment_url( $id ) ) );
		$old_main = wp_get_attachment_url( $id );

		// Use the untouched original (from a "-scaled" upload) as the source when it exists.
		$source = $file;
		if ( ! empty( $meta['original_image'] ) && is_file( $dir . '/' . $meta['original_image'] ) ) {
			$source = $dir . '/' . $meta['original_image'];
		}

		$old_files = array_unique( array( $file, $source ) );
		$old_sizes = array();
		foreach ( (array) ( $meta['sizes'] ?? array() ) as $size => $data ) {
			if ( ! empty( $data['file'] ) ) {
				$old_files[]        = $dir . '/' . $data['file'];
				$old_sizes[ $size ] = $base_url . $data['file'];
			}
		}

		$keep   = ! $s['delete_original'];
		$result = self::optimize_file( $source, $mime, $keep );
		if ( is_wp_error( $result ) ) {
			update_post_meta( $id, self::META, 'error' );
			return $result;
		}
		if ( empty( $result['changed'] ) ) {
			update_post_meta( $id, self::META, 'skipped' );
			return __( 'вже оптимізовано, без змін', 'ws-image-optimizer' );
		}

		// Old sub-sizes go first: with the same extension the new ones reuse their names.
		if ( ! $keep ) {
			foreach ( array_unique( $old_files ) as $old ) {
				if ( $old !== $result['path'] ) {
					self::delete_file( $old );
				}
			}
		}

		update_attached_file( $id, $result['path'] );
		wp_update_post( array( 'ID' => $id, 'post_mime_type' => $result['mime'] ) );

		require_once ABSPATH . 'wp-admin/includes/image.php';
		$new_meta = wp_generate_attachment_metadata( $id, $result['path'] );
		wp_update_attachment_metadata( $id, $new_meta );

		if ( $s['replace_urls'] ) {
			$new_main = wp_get_attachment_url( $id );
			$map      = array( $old_main => $new_main );
			if ( $source !== $file ) {
				$map[ $base_url . wp_basename( $source ) ] = $new_main;
			}
			foreach ( $old_sizes as $size => $old_url ) {
				$new_file        = $new_meta['sizes'][ $size ]['file'] ?? '';
				$map[ $old_url ] = $new_file ? $base_url . $new_file : $new_main;
			}
			self::replace_urls( $map );
		}

		$saved = (int) $result['saved'];
		update_post_meta( $id, self::META, 'done' );
		update_post_meta( $id, self::META_SAVED, $saved );

		/* translators: %s: saved size. */
		return sprintf( __( 'готово, зекономлено %s', 'ws-image-optimizer' ), size_format( $saved ) );
	}

	/** Attachments the bulk tool has not touched yet. */
	public static function pending_query( int $limit ): WP_Query {
		return new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => self::BULK_MIMES,
				'posts_per_page' => $limit,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array( 'key' => self::META, 'compare' => 'NOT EXISTS' ),
				),
			)
		);
	}

	public static function ajax_bulk(): void {
		check_ajax_referer( 'wsio_bulk', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Недостатньо прав.', 'ws-image-optimizer' ) ), 403 );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		$q = self::pending_query( 1 );
		if ( empty( $q->posts ) ) {
			wp_send_json_success( array( 'remaining' => 0, 'message' => '' ) );
		}

		$id     = (int) $q->posts[0];
		$result = self::process_attachment( $id );
		$text   = is_wp_error( $result ) ? __( 'помилка: ', 'ws-image-optimizer' ) . $result->get_error_message() : $result;

		wp_send_json_success(
			array(
				'remaining' => max( 0, (int) $q->found_posts - 1 ),
				'message'   => sprintf( '#%d %s — %s', $id, get_the_title( $id ), $text ),
			)
		);
	}

	/**
	 * Optimize all existing JPEG/PNG images.
	 *
	 * ## EXAMPLES
	 *     wp ws-image bulk
	 */
	public static function cli_bulk(): void {
		while ( true ) {
			$q = self::pending_query( 20 );
			if ( empty( $q->posts ) ) {
				break;
			}
			foreach ( $q->posts as $id ) {
				$r = self::process_attachment( (int) $id );
				WP_CLI::log( sprintf( '#%d %s', $id, is_wp_error( $r ) ? 'ERROR: ' . $r->get_error_message() : $r ) );
			}
		}
		WP_CLI::success( 'Done.' );
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------- */

	/** Deletes only files that really live inside /uploads. */
	private static function delete_file( string $path ): void {
		if ( is_file( $path ) && self::is_in_uploads( $path ) ) {
			wp_delete_file( $path );
		}
	}

	private static function is_in_uploads( string $path ): bool {
		$uploads = wp_get_upload_dir();
		$base    = realpath( $uploads['basedir'] );
		$real    = realpath( $path );
		if ( ! $base || ! $real ) {
			return false;
		}
		$base = trailingslashit( wp_normalize_path( $base ) );
		return 0 === strpos( wp_normalize_path( $real ), $base );
	}

	private static function fix_permissions( string $path ): void {
		$stat = @stat( dirname( $path ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( $stat ) {
			@chmod( $path, $stat['mode'] & 0000666 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
	}

	/**
	 * Replace old file URLs in post content and plain (non-serialized) post meta,
	 * e.g. Elementor JSON. Only the URL path is used, so http/https both match.
	 */
	public static function replace_urls( array $map ): void {
		global $wpdb;
		$changed = false;
		foreach ( $map as $old => $new ) {
			$old = (string) wp_parse_url( $old, PHP_URL_PATH );
			$new = (string) wp_parse_url( $new, PHP_URL_PATH );
			if ( '' === $old || '' === $new || $old === $new ) {
				continue;
			}
			$pairs = array(
				array( $old, $new ),
				array( str_replace( '/', '\/', $old ), str_replace( '/', '\/', $new ) ), // JSON-escaped.
			);
			foreach ( $pairs as $pair ) {
				$like = '%' . $wpdb->esc_like( $pair[0] ) . '%';
				// phpcs:disable WordPress.DB.DirectDatabaseQuery
				$a = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->posts} SET post_content = REPLACE(post_content, %s, %s) WHERE post_content LIKE %s", $pair[0], $pair[1], $like ) );
				$b = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->postmeta} SET meta_value = REPLACE(meta_value, %s, %s) WHERE meta_value LIKE %s AND meta_value NOT LIKE 'a:%%' AND meta_value NOT LIKE 'O:%%' AND meta_value NOT LIKE 's:%%'", $pair[0], $pair[1], $like ) );
				// phpcs:enable
				$changed = $changed || $a || $b;
			}
		}
		if ( $changed ) {
			wp_cache_flush();
		}
	}

	/* ---------------------------------------------------------------------
	 * Admin page
	 * ------------------------------------------------------------------- */

	public static function action_links( array $links ): array {
		$url = admin_url( 'upload.php?page=' . self::SLUG );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Налаштування', 'ws-image-optimizer' ) . '</a>' );
		return $links;
	}

	public static function admin_menu(): void {
		add_media_page(
			__( 'WS Image Optimizer', 'ws-image-optimizer' ),
			__( 'Оптимізація зображень', 'ws-image-optimizer' ),
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	public static function register_settings(): void {
		register_setting(
			'wsio',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ),
				'default'           => self::defaults(),
			)
		);
	}

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s       = self::settings();
		$pending = (int) self::pending_query( 1 )->found_posts;
		$opt     = self::OPTION;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'WS Image Optimizer', 'ws-image-optimizer' ); ?></h1>
			<p><?php esc_html_e( 'Стискає зображення, конвертує їх у WebP і видаляє оригінали, щоб звільнити місце на сервері.', 'ws-image-optimizer' ); ?></p>

			<?php if ( ! self::webp_supported() ) : ?>
				<div class="notice notice-warning"><p><?php esc_html_e( 'Сервер не підтримує WebP (немає Imagick або GD з WebP). Зображення лише стискатимуться у своєму форматі. Зверніться до хостингу.', 'ws-image-optimizer' ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'wsio' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Нові завантаження', 'ws-image-optimizer' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[on_upload]" value="1" <?php checked( $s['on_upload'] ); ?>> <?php esc_html_e( 'Оптимізувати зображення під час завантаження', 'ws-image-optimizer' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'WebP', 'ws-image-optimizer' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[webp]" value="1" <?php checked( $s['webp'] ); ?>> <?php esc_html_e( 'Конвертувати JPEG і PNG у WebP', 'ws-image-optimizer' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><label for="wsio-quality"><?php esc_html_e( 'Якість (40–100)', 'ws-image-optimizer' ); ?></label></th>
						<td><input id="wsio-quality" type="number" min="40" max="100" name="<?php echo esc_attr( $opt ); ?>[quality]" value="<?php echo esc_attr( $s['quality'] ); ?>" class="small-text">
							<p class="description"><?php esc_html_e( '80–85: різниці з оригіналом на око не видно, а файл у 3–5 разів менший.', 'ws-image-optimizer' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="wsio-max"><?php esc_html_e( 'Найбільша сторона, px', 'ws-image-optimizer' ); ?></label></th>
						<td><input id="wsio-max" type="number" min="0" max="10000" name="<?php echo esc_attr( $opt ); ?>[max_side]" value="<?php echo esc_attr( $s['max_side'] ); ?>" class="small-text">
							<p class="description"><?php esc_html_e( 'Більші зображення зменшуються до цього розміру. 0 — не змінювати розмір.', 'ws-image-optimizer' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Оригінали', 'ws-image-optimizer' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[delete_original]" value="1" <?php checked( $s['delete_original'] ); ?>> <?php esc_html_e( 'Видаляти оригінальні файли після оптимізації', 'ws-image-optimizer' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Посилання', 'ws-image-optimizer' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[replace_urls]" value="1" <?php checked( $s['replace_urls'] ); ?>> <?php esc_html_e( 'Під час пакетної обробки замінювати старі посилання в записах і сторінках', 'ws-image-optimizer' ); ?></label></td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<hr>
			<h2><?php esc_html_e( 'Вже завантажені зображення', 'ws-image-optimizer' ); ?></h2>
			<p><?php
				/* translators: %d: number of images. */
				printf( esc_html__( 'Необроблених JPEG/PNG: %d', 'ws-image-optimizer' ), absint( $pending ) );
			?></p>
			<p><strong><?php esc_html_e( 'Перед запуском зробіть резервну копію файлів і бази даних: оригінали буде видалено без можливості відновлення.', 'ws-image-optimizer' ); ?></strong></p>
			<p>
				<button type="button" class="button button-primary" id="wsio-start" <?php disabled( 0 === $pending ); ?>><?php esc_html_e( 'Оптимізувати всі', 'ws-image-optimizer' ); ?></button>
				<button type="button" class="button" id="wsio-stop" disabled><?php esc_html_e( 'Зупинити', 'ws-image-optimizer' ); ?></button>
				<span id="wsio-left"></span>
			</p>
			<ol id="wsio-log" style="max-height:320px;overflow:auto;background:#fff;padding:8px 8px 8px 32px;border:1px solid #c3c4c7"></ol>
		</div>
		<script>
		(function () {
			var start = document.getElementById('wsio-start'),
				stop = document.getElementById('wsio-stop'),
				left = document.getElementById('wsio-left'),
				log = document.getElementById('wsio-log'),
				running = false;
			var body = new URLSearchParams({ action: 'wsio_bulk', nonce: <?php echo wp_json_encode( wp_create_nonce( 'wsio_bulk' ) ); ?> });

			function line(text) {
				var li = document.createElement('li');
				li.textContent = text;
				log.appendChild(li);
				log.scrollTop = log.scrollHeight;
			}
			function finish(text) {
				running = false;
				start.disabled = false;
				stop.disabled = true;
				if (text) { line(text); }
			}
			function next() {
				if (!running) { return finish(<?php echo wp_json_encode( __( 'Зупинено.', 'ws-image-optimizer' ) ); ?>); }
				fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: body })
					.then(function (r) { return r.json(); })
					.then(function (r) {
						if (!r.success) { return finish((r.data && r.data.message) || 'Error'); }
						if (r.data.message) { line(r.data.message); }
						left.textContent = <?php echo wp_json_encode( __( 'Залишилось: ', 'ws-image-optimizer' ) ); ?> + r.data.remaining;
						if (r.data.remaining > 0) { next(); } else { finish(<?php echo wp_json_encode( __( 'Усе оброблено.', 'ws-image-optimizer' ) ); ?>); }
					})
					.catch(function (e) { finish('Error: ' + e.message); });
			}
			start.addEventListener('click', function () {
				running = true; start.disabled = true; stop.disabled = false; next();
			});
			stop.addEventListener('click', function () { running = false; stop.disabled = true; });
		})();
		</script>
		<?php
	}
}

WS_Image_Optimizer::init();
