<?php
/**
 * Worx setup wizard.
 *
 * One-shot onboarding screen (Media > Worx) with a live watermark preview
 * driven by pure Vanilla JS (no jQuery, no server round-trips while the
 * user interacts). Assets are enqueued exclusively on this screen and the
 * save handler is protected by nonce + manage_options.
 *
 * @package Worx
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Worx_Wizard {

	const PAGE = 'worx-setup-wizard';

	/**
	 * The 9 anchors: code => {x: 0|5|10, y: 0|5|10} (preview percentages/10).
	 *
	 * @var array
	 */
	const PREVIEW_POSITIONS = array(
		'pos-tl' => array( 0, 0 ),
		'pos-tc' => array( 5, 0 ),
		'pos-tr' => array( 10, 0 ),
		'pos-cl' => array( 0, 5 ),
		'pos-cc' => array( 5, 5 ),
		'pos-cr' => array( 10, 5 ),
		'pos-bl' => array( 0, 10 ),
		'pos-bc' => array( 5, 10 ),
		'pos-br' => array( 10, 10 ),
	);

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
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_worx_save_wizard', array( $this, 'handle_save' ) );
	}

	/**
	 * Register the wizard as a sub-menu under Media (manage_options only).
	 *
	 * @return void
	 */
	public function register_menu() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		add_submenu_page(
			'upload.php',
			__( 'Worx Setup Wizard', 'worx' ),
			'Worx',
			'manage_options',
			self::PAGE,
			array( $this, 'render' )
		);
	}

	/**
	 * Enqueue wizard.css / wizard.js on the wizard screen only.
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( false === strpos( $hook, self::PAGE ) ) {
			return;
		}
		wp_enqueue_style( 'worx-wizard', WORX_PLUGIN_URL . 'assets/css/wizard.css', array(), WORX_VERSION );
		wp_enqueue_script( 'worx-wizard', WORX_PLUGIN_URL . 'assets/js/wizard.js', array(), WORX_VERSION, true );
		wp_localize_script(
			'worx-wizard',
			'worxWizard',
			array(
				'defaultWm' => WORX_PLUGIN_URL . 'assets/img/wx-watermark.png',
			)
		);
	}

	/**
	 * admin_post handler: validate nonce + capability, sanitize, persist,
	 * mark the wizard as completed, redirect back with a success flag.
	 *
	 * @return void
	 */
	public function handle_save() {
		check_admin_referer( 'worx_wizard_action', 'worx_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to save these settings.', 'worx' ) );
		}

		$def  = Worx_Core::defaults();
		$next = wp_parse_args( (array) get_option( Worx_Core::OPTION, array() ), $def );

		$next['auto_webp']         = ! empty( $_POST['auto_webp'] );
		$next['watermark_enabled'] = ! empty( $_POST['watermark_enabled'] );
		$next['delete_original']   = ! empty( $_POST['delete_original'] );

		$next['lossless_quality'] = isset( $_POST['lossless_quality'] )
			? max( 60, min( 100, (int) $_POST['lossless_quality'] ) )
			: (int) $def['lossless_quality'];

		$next['max_width'] = isset( $_POST['max_width'] )
			? max( 0, min( 8000, (int) $_POST['max_width'] ) )
			: (int) $def['max_width'];

		$next['watermark_opacity'] = isset( $_POST['watermark_opacity'] )
			? max( 10, min( 100, (int) $_POST['watermark_opacity'] ) )
			: (int) $def['watermark_opacity'];

		$position = isset( $_POST['watermark_position'] ) ? sanitize_text_field( wp_unslash( $_POST['watermark_position'] ) ) : $def['watermark_position'];
		$next['watermark_position'] = in_array( $position, Worx_Watermark::POSITIONS, true ) ? $position : $def['watermark_position'];

		$custom_id = isset( $_POST['watermark_custom_id'] ) ? (int) $_POST['watermark_custom_id'] : 0;
		$next['watermark_custom_id'] = ( $custom_id > 0 && wp_attachment_is_image( $custom_id ) ) ? $custom_id : 0;

		$next['wizard_completed'] = true;

		update_option( Worx_Core::OPTION, $next, false );

		wp_safe_redirect( add_query_arg( 'worx-updated', '1', admin_url( 'admin.php?page=' . self::PAGE ) ) );
		exit;
	}

	/**
	 * Render the wizard screen.
	 *
	 * @return void
	 */
	public function render() {
		$s       = $this->settings;
		$updated = isset( $_GET['worx-updated'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$positions = self::PREVIEW_POSITIONS;
		$code      = isset( $positions[ $s['watermark_position'] ] ) ? $s['watermark_position'] : 'pos-br';
		$cur       = $positions[ $code ];

		$wm_src = WORX_PLUGIN_URL . 'assets/img/wx-watermark.png';
		if ( ! empty( $s['watermark_custom_id'] ) ) {
			$u = wp_get_attachment_image_url( $s['watermark_custom_id'], 'full' );
			if ( $u ) {
				$wm_src = $u;
			}
		}

		// Recent image attachments offered as custom watermark sources.
		$wm_choices = array();
		$images     = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => 'image/*',
				'numberposts'    => 24,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);
		foreach ( $images as $att ) {
			$url = wp_get_attachment_url( $att->ID );
			if ( ! $url ) {
				continue;
			}
			$attached = get_attached_file( $att->ID );
			$wm_choices[ $att->ID ] = array(
				'title' => $att->post_title ? $att->post_title : wp_basename( $attached ? $attached : 'image' ),
				'url'   => $url,
			);
		}

		$engine_label = Worx_Core::instance()->optimizer->engine_label();

		$opacity       = max( 10, min( 100, (int) $s['watermark_opacity'] ) );
		$wm_style      = 'opacity:' . ( $opacity / 100 ) . ( ! empty( $s['watermark_enabled'] ) ? '' : ';display:none' );
		$wm_src_default = WORX_PLUGIN_URL . 'assets/img/wx-watermark.png';
		?>
		<div id="worx-wizard">
			<header class="wx-wiz-head">
				<span class="wx-wiz-logo"><?php echo worx_icon( 'logo', 44 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG from svg-icons.php. ?></span>
				<div class="wx-wiz-titles">
					<h1>Worx Image Optimizer</h1>
					<p class="wx-tagline"><?php esc_html_e( 'Zero-lag WebP pipeline - Smart watermark - Modern media hub', 'worx' ); ?></p>
				</div>
				<span class="wx-engine-badge">
					<?php echo worx_icon( 'bolt', 15 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?>
					<span><?php echo esc_html( sprintf( __( 'Image engine: %s', 'worx' ), $engine_label ) ); ?></span>
				</span>
			</header>

			<?php if ( $updated ) : ?>
				<div class="wx-notice ok">
					<?php echo worx_icon( 'check_circle', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?>
					<span><?php esc_html_e( 'Settings saved. New uploads are now processed automatically.', 'worx' ); ?></span>
				</div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wx-form">
				<input type="hidden" name="action" value="worx_save_wizard">
				<?php wp_nonce_field( 'worx_wizard_action', 'worx_nonce' ); ?>

				<div class="wx-grid">

					<section class="wx-panel wx-preview-panel">
						<h2><?php esc_html_e( 'Live watermark preview', 'worx' ); ?></h2>
						<p class="wx-hint"><?php esc_html_e( 'This preview mirrors the 9-anchor matrix used during upload.', 'worx' ); ?></p>

						<div class="wx-stage" id="wx-stage">
							<img class="wx-stage-img" src="<?php echo esc_url( WORX_PLUGIN_URL . 'assets/img/demo-product.jpg' ); ?>" alt="<?php esc_attr_e( 'Demo product', 'worx' ); ?>">
							<img class="wx-stage-wm" id="wx-wm" src="<?php echo esc_url( $wm_src ); ?>" alt=""
								data-x="<?php echo (int) $cur[0]; ?>" data-y="<?php echo (int) $cur[1]; ?>"
								style="<?php echo esc_attr( $wm_style ); ?>">
						</div>

						<div class="wx-pos-wrap">
							<span class="wx-pos-label"><?php esc_html_e( 'Watermark position', 'worx' ); ?></span>
							<div class="wx-pos-grid" id="wx-pos" role="group" aria-label="<?php esc_attr_e( 'Watermark position', 'worx' ); ?>">
								<?php foreach ( $positions as $pos_code => $xy ) : ?>
									<button type="button"
										class="wx-pos-btn<?php echo $pos_code === $code ? ' is-active' : ''; ?>"
										data-pos="<?php echo esc_attr( $pos_code ); ?>"
										data-x="<?php echo (int) $xy[0]; ?>"
										data-y="<?php echo (int) $xy[1]; ?>">
										<span class="wx-dot"></span>
									</button>
								<?php endforeach; ?>
							</div>
						</div>

						<div class="wx-field">
							<label for="wx-opacity"><?php esc_html_e( 'Watermark opacity', 'worx' ); ?> <output id="wx-op-out"><?php echo (int) $opacity; ?>%</output></label>
							<input type="range" id="wx-opacity" name="watermark_opacity" min="10" max="100" step="5" value="<?php echo (int) $opacity; ?>">
						</div>

						<div class="wx-field">
							<label for="wx-wm-select"><?php esc_html_e( 'Watermark image', 'worx' ); ?></label>
							<select name="watermark_custom_id" id="wx-wm-select">
								<option value="0" data-src="<?php echo esc_url( $wm_src_default ); ?>"><?php esc_html_e( 'Default WX logo', 'worx' ); ?></option>
								<?php foreach ( $wm_choices as $cid => $choice ) : ?>
									<option value="<?php echo (int) $cid; ?>" data-src="<?php echo esc_url( $choice['url'] ); ?>" <?php selected( (int) $s['watermark_custom_id'], (int) $cid ); ?>>
										<?php echo esc_html( $choice['title'] ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</div>

						<div class="wx-field wx-switch-row">
							<label class="wx-switch">
								<input type="checkbox" name="watermark_enabled" id="wx-enabled" <?php checked( ! empty( $s['watermark_enabled'] ) ); ?>>
								<span class="wx-slider"></span>
							</label>
							<span class="wx-switch-text"><?php esc_html_e( 'Enable watermark on upload', 'worx' ); ?></span>
						</div>

						<input type="hidden" name="watermark_position" id="wx-position" value="<?php echo esc_attr( $code ); ?>">
					</section>

					<section class="wx-panel">
						<h2><?php esc_html_e( 'Optimization engine', 'worx' ); ?></h2>

						<div class="wx-field wx-switch-row">
							<label class="wx-switch">
								<input type="checkbox" name="auto_webp" id="wx-auto-webp" <?php checked( ! empty( $s['auto_webp'] ) ); ?>>
								<span class="wx-slider"></span>
							</label>
							<span class="wx-switch-text"><?php esc_html_e( 'Convert uploads to WebP automatically', 'worx' ); ?></span>
						</div>

						<div class="wx-field">
							<label for="wx-quality"><?php esc_html_e( 'WebP quality', 'worx' ); ?> <output id="wx-q-out"><?php echo (int) $s['lossless_quality']; ?></output></label>
							<input type="range" id="wx-quality" name="lossless_quality" min="60" max="100" step="5" value="<?php echo (int) $s['lossless_quality']; ?>">
							<p class="wx-hint"><?php esc_html_e( '100 = maximum quality (true lossless on servers with Imagick).', 'worx' ); ?></p>
						</div>

						<div class="wx-field">
							<label for="wx-max-width"><?php esc_html_e( 'Maximum width', 'worx' ); ?> <span class="wx-unit">px</span></label>
							<input type="number" id="wx-max-width" name="max_width" min="0" max="8000" step="10" value="<?php echo (int) $s['max_width']; ?>">
							<p class="wx-hint"><?php esc_html_e( 'Uploads wider than this are resized down. Use 0 to disable resizing.', 'worx' ); ?></p>
						</div>

						<div class="wx-field wx-switch-row">
							<label class="wx-switch">
								<input type="checkbox" name="delete_original" id="wx-delete-orig" <?php checked( ! empty( $s['delete_original'] ) ); ?>>
								<span class="wx-slider"></span>
							</label>
							<span class="wx-switch-text"><?php esc_html_e( 'Delete the original file after WebP is generated', 'worx' ); ?></span>
						</div>
						<p class="wx-hint wx-hint-sub"><?php esc_html_e( 'When off, a copy is kept next to the WebP file with the "-original" suffix.', 'worx' ); ?></p>

						<div class="wx-actions">
							<button type="submit" class="wx-btn primary">
								<?php echo worx_icon( 'check', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?>
								<span><?php esc_html_e( 'Save & finish', 'worx' ); ?></span>
							</button>
							<a class="wx-btn ghost" href="<?php echo esc_url( admin_url( 'upload.php' ) ); ?>">
								<span><?php esc_html_e( 'Open media hub', 'worx' ); ?></span>
								<?php echo worx_icon( 'chevron', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?>
							</a>
						</div>

						<p class="wx-footnote"><?php echo worx_icon( 'logo', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?> <?php esc_html_e( 'Powered by obsifox studio', 'worx' ); ?></p>
					</section>

				</div>
			</form>
		</div>
		<?php
	}
}
