<?php
/**
 * Worx modern media hub.
 *
 * Enhances the default Media Library (upload.php) without replacing it:
 *
 *  - A "Worx" column with savings %, WebP and watermark badges.
 *  - A per-row Reprocess action (wp_ajax_worx_reprocess_image, nonce +
 *    upload_files capability).
 *  - A compact library stat strip (totals cached in a 5-minute transient).
 *  - Assets enqueued on the Media screen only, Vanilla JS only.
 *
 * @package Worx
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Worx_Media_Hub {

	const NONCE_ACTION = 'worx_media_action';

	/**
	 * Plugin settings snapshot.
	 *
	 * @var array
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param array $settings Worx settings array.
	 */
	public function __construct( array $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Hook wiring.
	 *
	 * @return void
	 */
	public function hooks() {
		add_filter( 'manage_media_library_columns', array( $this, 'add_column' ) );
		add_action( 'manage_media_library_custom_column', array( $this, 'render_column' ), 10, 3 );
		add_filter( 'post_row_classes', array( $this, 'row_classes' ), 10, 2 );
		add_action( 'admin_notices', array( $this, 'render_stats_bar' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_worx_reprocess_image', array( $this, 'ajax_reprocess' ) );
	}

	/**
	 * Insert the Worx column right after the title column.
	 *
	 * @param array $cols Existing columns.
	 * @return array
	 */
	public function add_column( $cols ) {
		$new = array();
		foreach ( (array) $cols as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'title' === $key ) {
				$new['worx'] = worx_icon( 'water_drop', 14 ) . ' ' . __( 'Worx', 'worx' );
			}
		}
		return $new;
	}

	/**
	 * Render one row of the Worx column.
	 *
	 * @param string $column_name Column key.
	 * @param int    $post_id     Attachment id.
	 * @param bool   $echo        Echo instead of return (AJAX reuse).
	 * @return string|void HTML when $echo is false.
	 */
	public function render_column( $column_name, $post_id, $echo = true ) {
		if ( 'worx' !== $column_name ) {
			if ( $echo ) {
				return;
			}
			return '';
		}

		ob_start();
		?>
		<div class="wx-hub-cell">
			<?php if ( ! wp_attachment_is_image( $post_id ) ) : ?>
				<span class="wx-hub-muted">-</span>
			<?php else : ?>
				<?php
				$meta = get_post_meta( $post_id, Worx_Optimizer::META_KEY, true );
				if ( is_array( $meta ) && ! empty( $meta['is_webp'] ) ) :
					?>
					<span class="wx-badge wx-badge-save" title="<?php esc_attr_e( 'Space saved by Worx', 'worx' ); ?>">
						<?php echo worx_icon( 'compress', 12 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?>
						<?php echo esc_html( number_format_i18n( (float) $meta['savings_percent'], 1 ) ); ?>%
					</span>
					<span class="wx-badge wx-badge-webp">WebP</span>
					<?php if ( ! empty( $meta['watermarked'] ) ) : ?>
						<span class="wx-badge wx-badge-wm">
							<?php echo worx_icon( 'water_drop', 11 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?>
							WM
						</span>
					<?php endif; ?>
				<?php else : ?>
					<span class="wx-badge wx-badge-none"><?php esc_html_e( 'Not optimized', 'worx' ); ?></span>
				<?php endif; ?>
				<button type="button" class="button button-small wx-hub-reprocess" data-worx-reprocess="<?php echo (int) $post_id; ?>">
					<?php echo worx_icon( 'refresh', 12 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?>
					<span><?php esc_html_e( 'Reprocess', 'worx' ); ?></span>
				</button>
				<span class="wx-hub-status" aria-live="polite"></span>
			<?php endif; ?>
		</div>
		<?php
		$html = ob_get_clean();

		if ( $echo ) {
			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped fragments above.
		} else {
			return $html;
		}
	}

	/**
	 * Accent row for already-optimized images.
	 *
	 * @param array $classes Row classes.
	 * @param int   $post    Post id (or WP_Post; normalized by the caller).
	 * @return array
	 */
	public function row_classes( $classes, $post ) {
		$id = is_object( $post ) && isset( $post->ID ) ? (int) $post->ID : (int) $post;
		if ( $id && 'attachment' === get_post_type( $id ) && get_post_meta( $id, Worx_Optimizer::META_KEY, true ) ) {
			$classes[] = 'wx-webp-row';
		}
		return $classes;
	}

	/**
	 * Compact stat strip on top of the media library.
	 *
	 * @return void
	 */
	public function render_stats_bar() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'upload' !== $screen->id ) {
			return;
		}
		if ( ! current_user_can( 'upload_files' ) ) {
			return;
		}

		$stats = get_transient( 'worx_hub_stats' );
		if ( ! is_array( $stats ) ) {
			global $wpdb;
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_status = 'inherit' AND post_mime_type LIKE 'image/%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$opt   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s WHERE p.post_type = 'attachment' AND p.post_status = 'inherit'", Worx_Optimizer::META_KEY ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$stats = array(
				'total'     => $total,
				'optimized' => $opt,
			);
			set_transient( 'worx_hub_stats', $stats, 5 * MINUTE_IN_SECONDS );
		}

		if ( empty( $stats['total'] ) ) {
			return;
		}
		$pct = round( $stats['optimized'] / $stats['total'] * 100 );
		?>
		<div class="wx-hub-stats">
			<?php echo worx_icon( 'photo_library', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?>
			<span><?php echo esc_html( sprintf( __( '%1$d images - %2$d optimized (%3$d%%)', 'worx' ), number_format_i18n( (int) $stats['total'] ), number_format_i18n( (int) $stats['optimized'] ), $pct ) ); ?></span>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Worx_Wizard::PAGE ) ); ?>"><?php esc_html_e( 'Settings', 'worx' ); ?></a>
		</div>
		<?php
	}

	/**
	 * Enqueue hub assets on the Media Library screen only.
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( 'upload.php' !== $hook ) {
			return;
		}
		if ( ! current_user_can( 'upload_files' ) ) {
			return;
		}
		wp_enqueue_style( 'worx-hub', WORX_PLUGIN_URL . 'assets/css/media-hub.css', array(), WORX_VERSION );
		wp_enqueue_script( 'worx-hub', WORX_PLUGIN_URL . 'assets/js/media-hub.js', array(), WORX_VERSION, true );
		wp_localize_script(
			'worx-hub',
			'worxHub',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
				'i18n'    => array(
					'working' => __( 'Processing...', 'worx' ),
					'done'    => __( 'Done', 'worx' ),
					'error'   => __( 'Error', 'worx' ),
				),
			)
		);
	}

	/**
	 * AJAX: reprocess one attachment (resize + watermark + WebP + sub-sizes).
	 *
	 * @return void Sends JSON and exits.
	 */
	public function ajax_reprocess() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'worx' ) ), 403 );
		}

		$post_id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;
		if ( ! $post_id || 'attachment' !== get_post_type( $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid attachment.', 'worx' ) ), 400 );
		}

		$optimizer = Worx_Core::instance()->optimizer;
		try {
			$meta = $optimizer->reprocess_attachment( $post_id );
		} catch ( \Throwable $e ) {
			error_log( sprintf( 'Worx: reprocess failed for %d: %s', $post_id, $e->getMessage() ) );
			wp_send_json_error( array( 'message' => $e->getMessage() ), 500 );
		}

		delete_transient( 'worx_hub_stats' );

		wp_send_json_success(
			array(
				'meta' => $meta,
				'html' => $this->render_column( 'worx', $post_id, false ),
			)
		);
	}
}
