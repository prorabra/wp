<?php
/**
 * Plugin Name:       WS Video Optimizer
 * Plugin URI:        https://github.com/prorabra/wp
 * Description:       Стискає відео з медіатеки у MP4 (H.264 + AAC) через FFmpeg без видимої втрати якості та видаляє оригінал, щоб він не займав місце на сервері. Обробка йде у фоні, по одному відео, також можна обробити вже завантажені відео.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Zaichenko Andrey
 * Author URI:        https://websites-service.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ws-video-optimizer
 */

defined( 'ABSPATH' ) || exit;

final class WS_Video_Optimizer {

	const OPTION     = 'wsvo_settings';
	const LOCK       = 'wsvo_lock';
	const HOOK       = 'wsvo_run_queue';
	const META       = '_wsvo_status';
	const META_SAVED = '_wsvo_saved';
	const META_ERROR = '_wsvo_error';
	const META_TMP   = '_wsvo_tmp';
	const SLUG       = 'ws-video-optimizer';
	const LOCK_TTL   = 3 * HOUR_IN_SECONDS;

	/** WebM is skipped on purpose: it is already compressed well (VP8/VP9/AV1). */
	const VIDEO_MIMES = array(
		'video/mp4',
		'video/quicktime',
		'video/x-msvideo',
		'video/avi',
		'video/x-ms-wmv',
		'video/x-matroska',
		'video/mpeg',
		'video/3gpp',
		'video/3gpp2',
		'video/x-flv',
		'video/x-m4v',
	);

	const PRESETS = array( 'ultrafast', 'superfast', 'veryfast', 'faster', 'fast', 'medium', 'slow', 'slower', 'veryslow' );

	public static function init(): void {
		add_action( 'add_attachment', array( __CLASS__, 'on_new_attachment' ) );
		add_action( self::HOOK, array( __CLASS__, 'run_queue' ) );

		if ( is_admin() ) {
			add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
			add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
			add_action( 'admin_post_wsvo_queue_all', array( __CLASS__, 'action_queue_all' ) );
			add_action( 'admin_post_wsvo_retry', array( __CLASS__, 'action_retry' ) );
			add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( __CLASS__, 'action_links' ) );
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'ws-video queue-all', array( __CLASS__, 'cli_queue_all' ) );
			WP_CLI::add_command( 'ws-video run', array( __CLASS__, 'cli_run' ) );
		}
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::HOOK );
		delete_option( self::LOCK );
	}

	/* ---------------------------------------------------------------------
	 * Settings
	 * ------------------------------------------------------------------- */

	public static function defaults(): array {
		return array(
			'auto'            => 1,        // compress new uploads in the background
			'crf'             => 23,       // 18 = almost lossless, 23 = visually lossless for the web, 28 = smaller
			'preset'          => 'medium', // slower = smaller file at the same quality, but more CPU
			'max_height'      => 1080,     // 0 = keep resolution
			'audio_bitrate'   => 128,      // kbit/s AAC
			'delete_original' => 1,
			'replace_urls'    => 1,
		);
	}

	public static function settings(): array {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	public static function sanitize_settings( $input ): array {
		$input = is_array( $input ) ? $input : array();
		$out   = array();
		foreach ( array( 'auto', 'delete_original', 'replace_urls' ) as $key ) {
			$out[ $key ] = empty( $input[ $key ] ) ? 0 : 1;
		}
		$out['crf']           = min( 32, max( 16, absint( $input['crf'] ?? 23 ) ) );
		$preset               = sanitize_key( $input['preset'] ?? 'medium' );
		$out['preset']        = in_array( $preset, self::PRESETS, true ) ? $preset : 'medium';
		$height               = absint( $input['max_height'] ?? 0 );
		$out['max_height']    = 0 === $height ? 0 : min( 4320, max( 240, $height ) );
		$out['audio_bitrate'] = min( 320, max( 64, absint( $input['audio_bitrate'] ?? 128 ) ) );
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Environment
	 * ------------------------------------------------------------------- */

	public static function exec_available(): bool {
		if ( ! function_exists( 'exec' ) ) {
			return false;
		}
		$disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );
		return ! in_array( 'exec', $disabled, true );
	}

	/**
	 * The FFmpeg path is never taken from the admin form: a custom binary can only be
	 * set in wp-config.php via define( 'WSVO_FFMPEG', '/path/to/ffmpeg' ).
	 */
	public static function ffmpeg_path(): string {
		static $path = null;
		if ( null !== $path ) {
			return $path;
		}
		$path = '';
		if ( defined( 'WSVO_FFMPEG' ) ) {
			$p    = (string) WSVO_FFMPEG;
			$path = ( @is_file( $p ) && @is_executable( $p ) ) ? $p : ''; // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return $path;
		}
		foreach ( array( '/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg', '/opt/homebrew/bin/ffmpeg', '/bin/ffmpeg' ) as $p ) {
			if ( @is_executable( $p ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				$path = $p;
				return $path;
			}
		}
		if ( self::exec_available() ) {
			$out = array();
			@exec( self::is_windows() ? 'where ffmpeg' : 'command -v ffmpeg', $out ); // phpcs:ignore
			$p = trim( (string) ( $out[0] ?? '' ) );
			if ( '' !== $p && @is_executable( $p ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				$path = $p;
			}
		}
		return $path;
	}

	public static function ffmpeg_version(): string {
		$bin = self::ffmpeg_path();
		if ( '' === $bin || ! self::exec_available() ) {
			return '';
		}
		$out = array();
		@exec( escapeshellarg( $bin ) . ' -hide_banner -version 2>&1', $out ); // phpcs:ignore
		return trim( (string) ( $out[0] ?? '' ) );
	}

	private static function is_windows(): bool {
		return 'WIN' === strtoupper( substr( PHP_OS, 0, 3 ) );
	}

	/* ---------------------------------------------------------------------
	 * Queue
	 * ------------------------------------------------------------------- */

	public static function on_new_attachment( $id ): void {
		$id = (int) $id;
		if ( self::settings()['auto'] && in_array( get_post_mime_type( $id ), self::VIDEO_MIMES, true ) ) {
			self::enqueue( $id );
		}
	}

	public static function enqueue( int $id ): void {
		update_post_meta( $id, self::META, 'queued' );
		delete_post_meta( $id, self::META_ERROR );
		self::schedule();
	}

	private static function schedule( int $delay = 5 ): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + $delay, self::HOOK );
		}
	}

	private static function next_queued(): int {
		$ids = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'meta_key'       => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'     => 'queued',   // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		return (int) ( $ids[0] ?? 0 );
	}

	/** WP-Cron callback: one video per run, then reschedules itself while the queue is not empty. */
	public static function run_queue(): void {
		$id = self::next_queued();
		if ( ! $id ) {
			return;
		}
		$result = self::process( $id );
		if ( 'locked' === $result ) {
			self::schedule( 60 );
			return;
		}
		if ( self::next_queued() ) {
			self::schedule();
		}
	}

	/** Atomic lock: add_option() fails when the row already exists. */
	private static function acquire_lock( int $id ): bool {
		if ( add_option( self::LOCK, $id . '|' . time(), '', false ) ) {
			return true;
		}
		$lock = explode( '|', (string) get_option( self::LOCK, '' ) );
		if ( count( $lock ) === 2 && time() - (int) $lock[1] > self::LOCK_TTL ) {
			self::mark_interrupted( (int) $lock[0] );
			delete_option( self::LOCK );
			return add_option( self::LOCK, $id . '|' . time(), '', false );
		}
		return false;
	}

	/** A previous run was killed by the server (time or memory limit). */
	private static function mark_interrupted( int $id ): void {
		if ( ! $id ) {
			return;
		}
		$tmp = (string) get_post_meta( $id, self::META_TMP, true );
		if ( '' !== $tmp ) {
			self::delete_file( $tmp );
			delete_post_meta( $id, self::META_TMP );
		}
		if ( 'processing' === get_post_meta( $id, self::META, true ) ) {
			update_post_meta( $id, self::META, 'error' );
			update_post_meta( $id, self::META_ERROR, __( 'Обробку перервав сервер (ліміт часу). Налаштуйте системний cron або WP-CLI.', 'ws-video-optimizer' ) );
		}
	}

	/* ---------------------------------------------------------------------
	 * Core: compress one video
	 * ------------------------------------------------------------------- */

	/** @return string 'done' | 'skipped' | 'error' | 'locked' */
	public static function process( int $id ): string {
		if ( ! self::acquire_lock( $id ) ) {
			return 'locked';
		}
		try {
			update_post_meta( $id, self::META, 'processing' );
			$result = self::compress( $id );
			if ( is_wp_error( $result ) ) {
				update_post_meta( $id, self::META, 'error' );
				update_post_meta( $id, self::META_ERROR, $result->get_error_message() );
				return 'error';
			}
			update_post_meta( $id, self::META, $result );
			return $result;
		} finally {
			delete_post_meta( $id, self::META_TMP );
			delete_option( self::LOCK );
		}
	}

	/** @return string|WP_Error 'done' or 'skipped' */
	private static function compress( int $id ) {
		$s    = self::settings();
		$file = get_attached_file( $id );
		$bin  = self::ffmpeg_path();

		if ( ! self::exec_available() || '' === $bin ) {
			return new WP_Error( 'wsvo_env', __( 'FFmpeg недоступний на сервері.', 'ws-video-optimizer' ) );
		}
		if ( ! in_array( get_post_mime_type( $id ), self::VIDEO_MIMES, true ) ) {
			return new WP_Error( 'wsvo_type', __( 'Формат не підтримується.', 'ws-video-optimizer' ) );
		}
		if ( ! $file || ! is_file( $file ) || ! self::is_in_uploads( $file ) ) {
			return new WP_Error( 'wsvo_missing', __( 'Файл не знайдено.', 'ws-video-optimizer' ) );
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		ignore_user_abort( true );

		$dir  = dirname( $file );
		$name = pathinfo( $file, PATHINFO_FILENAME );
		$tmp  = $dir . '/' . wp_unique_filename( $dir, $name . '-wsvo-tmp.mp4' );
		update_post_meta( $id, self::META_TMP, $tmp );

		$out  = array();
		$code = 1;
		exec( self::build_command( $bin, $file, $tmp, $s ) . ' 2>&1', $out, $code ); // phpcs:ignore

		clearstatcache();
		if ( 0 !== $code || ! is_file( $tmp ) || filesize( $tmp ) < 1024 ) {
			self::delete_file( $tmp );
			$tail = sanitize_text_field( implode( ' ', array_slice( $out, -3 ) ) ); // Escaped on output.
			/* translators: %s: last lines of FFmpeg output. */
			return new WP_Error( 'wsvo_ffmpeg', sprintf( __( 'FFmpeg завершився з помилкою: %s', 'ws-video-optimizer' ), $tail ) );
		}

		$old_bytes = (int) filesize( $file );
		$new_bytes = (int) filesize( $tmp );
		if ( $new_bytes >= $old_bytes ) {
			self::delete_file( $tmp ); // The original is already smaller: keep it.
			return 'skipped';
		}

		$keep      = ! $s['delete_original'];
		$final     = $dir . '/' . $name . '.mp4';
		$overwrite = ( $final === $file && ! $keep ); // Same name = links stay the same.
		if ( ! $overwrite && file_exists( $final ) ) {
			$final = $dir . '/' . wp_unique_filename( $dir, $name . '.mp4' );
		}
		$old_url = wp_get_attachment_url( $id );

		if ( $overwrite ) {
			self::delete_file( $file );
		}
		if ( ! @rename( $tmp, $final ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			self::delete_file( $tmp );
			return new WP_Error( 'wsvo_rename', __( 'Не вдалося зберегти стиснений файл.', 'ws-video-optimizer' ) );
		}
		self::fix_permissions( $final );
		if ( ! $keep && $final !== $file ) {
			self::delete_file( $file );
		}

		update_attached_file( $id, $final );
		wp_update_post( array( 'ID' => $id, 'post_mime_type' => 'video/mp4' ) );

		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $final ) );

		$new_url = wp_get_attachment_url( $id );
		if ( $s['replace_urls'] && $old_url !== $new_url ) {
			self::replace_urls( array( $old_url => $new_url ) );
		}

		update_post_meta( $id, self::META_SAVED, $old_bytes - $new_bytes );
		return 'done';
	}

	/** Every value in the command is either a whitelisted setting, an integer, or escaped. */
	private static function build_command( string $bin, string $in, string $out, array $s ): string {
		$args = array(
			'-y', '-hide_banner', '-loglevel', 'error', '-nostdin',
			'-i', $in,
			'-map', '0:v:0', '-map', '0:a:0?',
			'-c:v', 'libx264',
			'-preset', $s['preset'],
			'-crf', (string) (int) $s['crf'],
			'-pix_fmt', 'yuv420p',
		);
		if ( (int) $s['max_height'] > 0 ) {
			$h      = (int) $s['max_height'];
			$args[] = '-vf';
			$args[] = "scale=-2:trunc(min({$h}\\,ih)/2)*2"; // Never upscale; keep even sizes for H.264.
		}
		array_push(
			$args,
			'-c:a', 'aac', '-b:a', ( (int) $s['audio_bitrate'] ) . 'k',
			'-movflags', '+faststart', // The video starts playing before it is fully downloaded.
			$out
		);

		$cmd = escapeshellarg( $bin ) . ' ' . implode( ' ', array_map( 'escapeshellarg', $args ) );
		if ( ! self::is_windows() && @is_executable( '/usr/bin/nice' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			$cmd = '/usr/bin/nice -n 19 ' . $cmd; // Lowest CPU priority: the site keeps responding.
		}
		return $cmd;
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------- */

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

	/** Same approach as in WS Image Optimizer: URL path only, plain and JSON-escaped, no serialized meta. */
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
				array( str_replace( '/', '\/', $old ), str_replace( '/', '\/', $new ) ),
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

	/** Videos that were never processed (or failed) and can be queued. */
	private static function unprocessed_ids(): array {
		return get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => self::VIDEO_MIMES,
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					'relation' => 'OR',
					array( 'key' => self::META, 'compare' => 'NOT EXISTS' ),
					array( 'key' => self::META, 'value' => 'error' ),
				),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Admin actions
	 * ------------------------------------------------------------------- */

	private static function guard( string $action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Недостатньо прав.', 'ws-video-optimizer' ), 403 );
		}
		check_admin_referer( $action );
	}

	private static function back( string $notice ): void {
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'wsvo_notice' => $notice ), admin_url( 'upload.php' ) ) );
		exit;
	}

	public static function action_queue_all(): void {
		self::guard( 'wsvo_queue_all' );
		foreach ( self::unprocessed_ids() as $id ) {
			self::enqueue( (int) $id );
		}
		self::back( 'queued' );
	}

	public static function action_retry(): void {
		self::guard( 'wsvo_retry' );
		$id = absint( $_GET['id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification -- checked in guard().
		if ( $id && 'attachment' === get_post_type( $id ) && in_array( get_post_mime_type( $id ), self::VIDEO_MIMES, true ) ) {
			self::enqueue( $id );
		}
		self::back( 'queued' );
	}

	/* ---------------------------------------------------------------------
	 * WP-CLI (recommended for big files: no web-server time limits)
	 * ------------------------------------------------------------------- */

	/**
	 * Queue all unprocessed videos.
	 *
	 * ## EXAMPLES
	 *     wp ws-video queue-all
	 */
	public static function cli_queue_all(): void {
		$ids = self::unprocessed_ids();
		foreach ( $ids as $id ) {
			self::enqueue( (int) $id );
		}
		WP_CLI::success( sprintf( 'Queued: %d', count( $ids ) ) );
	}

	/**
	 * Process the whole queue now.
	 *
	 * ## EXAMPLES
	 *     wp ws-video run
	 */
	public static function cli_run(): void {
		while ( $id = self::next_queued() ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition
			WP_CLI::log( sprintf( '#%d %s…', $id, get_the_title( $id ) ) );
			$r = self::process( $id );
			if ( 'locked' === $r ) {
				WP_CLI::error( 'Another video is being processed right now. Try again later.' );
			}
			$msg = 'error' === $r ? (string) get_post_meta( $id, self::META_ERROR, true ) : '';
			WP_CLI::log( '  ' . $r . ( $msg ? ': ' . $msg : '' ) );
		}
		WP_CLI::success( 'Queue is empty.' );
	}

	/* ---------------------------------------------------------------------
	 * Admin page
	 * ------------------------------------------------------------------- */

	public static function action_links( array $links ): array {
		$url = admin_url( 'upload.php?page=' . self::SLUG );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Налаштування', 'ws-video-optimizer' ) . '</a>' );
		return $links;
	}

	public static function admin_menu(): void {
		add_media_page(
			__( 'WS Video Optimizer', 'ws-video-optimizer' ),
			__( 'Оптимізація відео', 'ws-video-optimizer' ),
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	public static function register_settings(): void {
		register_setting(
			'wsvo',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ),
				'default'           => self::defaults(),
			)
		);
	}

	private static function status_label( string $status ): string {
		$labels = array(
			'queued'     => __( 'У черзі', 'ws-video-optimizer' ),
			'processing' => __( 'Обробляється', 'ws-video-optimizer' ),
			'done'       => __( 'Стиснуто', 'ws-video-optimizer' ),
			'skipped'    => __( 'Без змін (оригінал вже менший)', 'ws-video-optimizer' ),
			'error'      => __( 'Помилка', 'ws-video-optimizer' ),
		);
		return $labels[ $status ] ?? __( 'Не оброблено', 'ws-video-optimizer' );
	}

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s       = self::settings();
		$opt     = self::OPTION;
		$exec    = self::exec_available();
		$version = self::ffmpeg_version();
		$notice  = sanitize_key( $_GET['wsvo_notice'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
		$videos  = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => self::VIDEO_MIMES,
				'posts_per_page' => 30,
				'orderby'        => 'ID',
				'order'          => 'DESC',
			)
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'WS Video Optimizer', 'ws-video-optimizer' ); ?></h1>
			<p><?php esc_html_e( 'Стискає відео у MP4 (H.264) без видимої втрати якості та видаляє оригінал, щоб звільнити місце на сервері.', 'ws-video-optimizer' ); ?></p>

			<?php if ( 'queued' === $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Відео додано в чергу. Вони обробляються у фоні по одному.', 'ws-video-optimizer' ); ?></p></div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Перевірка сервера', 'ws-video-optimizer' ); ?></h2>
			<ul>
				<li><?php echo $exec ? '✅ ' : '❌ '; esc_html_e( 'PHP-функція exec() доступна', 'ws-video-optimizer' ); ?></li>
				<li><?php echo $version ? '✅ ' . esc_html( $version ) : '❌ ' . esc_html__( 'FFmpeg не знайдено. Попросіть хостинг встановити FFmpeg або вкажіть шлях у wp-config.php: define( \'WSVO_FFMPEG\', \'/path/to/ffmpeg\' );', 'ws-video-optimizer' ); ?></li>
				<li><?php echo ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) ? '✅ ' : 'ℹ️ '; esc_html_e( 'Для великих відео краще налаштувати системний cron або запускати «wp ws-video run» через WP-CLI.', 'ws-video-optimizer' ); ?></li>
			</ul>

			<form method="post" action="options.php">
				<?php settings_fields( 'wsvo' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Нові завантаження', 'ws-video-optimizer' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[auto]" value="1" <?php checked( $s['auto'] ); ?>> <?php esc_html_e( 'Автоматично стискати нові відео', 'ws-video-optimizer' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><label for="wsvo-crf"><?php esc_html_e( 'Якість (CRF 16–32)', 'ws-video-optimizer' ); ?></label></th>
						<td><input id="wsvo-crf" type="number" min="16" max="32" name="<?php echo esc_attr( $opt ); ?>[crf]" value="<?php echo esc_attr( $s['crf'] ); ?>" class="small-text">
							<p class="description"><?php esc_html_e( 'Менше число — вища якість і більший файл. 18–20: майже без втрат; 23: без видимої різниці для сайту (рекомендовано); 26–28: помітно менший файл.', 'ws-video-optimizer' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="wsvo-preset"><?php esc_html_e( 'Швидкість стиснення', 'ws-video-optimizer' ); ?></label></th>
						<td><select id="wsvo-preset" name="<?php echo esc_attr( $opt ); ?>[preset]">
							<?php foreach ( self::PRESETS as $p ) : ?>
								<option value="<?php echo esc_attr( $p ); ?>" <?php selected( $s['preset'], $p ); ?>><?php echo esc_html( $p ); ?></option>
							<?php endforeach; ?>
						</select>
							<p class="description"><?php esc_html_e( 'Повільніший пресет дає менший файл при тій самій якості, але довше навантажує процесор.', 'ws-video-optimizer' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="wsvo-height"><?php esc_html_e( 'Максимальна висота, px', 'ws-video-optimizer' ); ?></label></th>
						<td><input id="wsvo-height" type="number" min="0" max="4320" name="<?php echo esc_attr( $opt ); ?>[max_height]" value="<?php echo esc_attr( $s['max_height'] ); ?>" class="small-text">
							<p class="description"><?php esc_html_e( '1080 — Full HD (відео 4K зменшується, менші не збільшуються). 0 — зберегти роздільність оригіналу.', 'ws-video-optimizer' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="wsvo-audio"><?php esc_html_e( 'Аудіо, кбіт/с', 'ws-video-optimizer' ); ?></label></th>
						<td><input id="wsvo-audio" type="number" min="64" max="320" name="<?php echo esc_attr( $opt ); ?>[audio_bitrate]" value="<?php echo esc_attr( $s['audio_bitrate'] ); ?>" class="small-text"></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Оригінали', 'ws-video-optimizer' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[delete_original]" value="1" <?php checked( $s['delete_original'] ); ?>> <?php esc_html_e( 'Видаляти оригінал після успішного стиснення', 'ws-video-optimizer' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Посилання', 'ws-video-optimizer' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[replace_urls]" value="1" <?php checked( $s['replace_urls'] ); ?>> <?php esc_html_e( 'Замінювати посилання в записах, якщо змінилось ім\'я файлу (наприклад .mov → .mp4)', 'ws-video-optimizer' ); ?></label></td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<hr>
			<h2><?php esc_html_e( 'Вже завантажені відео', 'ws-video-optimizer' ); ?></h2>
			<p><strong><?php esc_html_e( 'Перед запуском зробіть резервну копію: оригінали буде видалено без можливості відновлення.', 'ws-video-optimizer' ); ?></strong></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="wsvo_queue_all">
				<?php wp_nonce_field( 'wsvo_queue_all' ); ?>
				<?php submit_button( __( 'Додати всі необроблені відео в чергу', 'ws-video-optimizer' ), 'primary', 'submit', false, $version ? array() : array( 'disabled' => 'disabled' ) ); ?>
			</form>

			<table class="widefat striped" style="margin-top:16px">
				<thead><tr>
					<th><?php esc_html_e( 'Відео', 'ws-video-optimizer' ); ?></th>
					<th><?php esc_html_e( 'Розмір', 'ws-video-optimizer' ); ?></th>
					<th><?php esc_html_e( 'Статус', 'ws-video-optimizer' ); ?></th>
					<th><?php esc_html_e( 'Зекономлено', 'ws-video-optimizer' ); ?></th>
					<th></th>
				</tr></thead>
				<tbody>
				<?php if ( ! $videos ) : ?>
					<tr><td colspan="5"><?php esc_html_e( 'Відео в медіатеці немає.', 'ws-video-optimizer' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $videos as $v ) :
					$status = (string) get_post_meta( $v->ID, self::META, true );
					$path   = get_attached_file( $v->ID );
					$bytes  = ( $path && is_file( $path ) ) ? filesize( $path ) : 0;
					$saved  = (int) get_post_meta( $v->ID, self::META_SAVED, true );
					$error  = (string) get_post_meta( $v->ID, self::META_ERROR, true );
					$retry  = wp_nonce_url( add_query_arg( array( 'action' => 'wsvo_retry', 'id' => $v->ID ), admin_url( 'admin-post.php' ) ), 'wsvo_retry' );
					?>
					<tr>
						<td><a href="<?php echo esc_url( get_edit_post_link( $v->ID ) ); ?>"><?php echo esc_html( get_the_title( $v ) ); ?></a><br><small><?php echo esc_html( wp_basename( (string) $path ) ); ?></small></td>
						<td><?php echo esc_html( $bytes ? size_format( $bytes ) : '—' ); ?></td>
						<td><?php echo esc_html( self::status_label( $status ) ); ?><?php if ( $error ) : ?><br><small><?php echo esc_html( $error ); ?></small><?php endif; ?></td>
						<td><?php echo esc_html( $saved ? size_format( $saved ) : '—' ); ?></td>
						<td><?php if ( in_array( $status, array( '', 'error', 'skipped' ), true ) && $version ) : ?><a class="button button-small" href="<?php echo esc_url( $retry ); ?>"><?php esc_html_e( 'Стиснути', 'ws-video-optimizer' ); ?></a><?php endif; ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}

register_deactivation_hook( __FILE__, array( 'WS_Video_Optimizer', 'deactivate' ) );
WS_Video_Optimizer::init();
