<?php
/**
 * Worx core: plugin lifecycle, settings, conditional module wiring.
 *
 * - Stores the entire configuration in ONE wp_options row (`worx_settings`),
 *   read once at boot (Single-Query Architecture).
 * - Wires modules only when the admin area runs and features are enabled.
 * - Sends admins to the setup wizard right after activation.
 *
 * @package Worx
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Worx_Core {

	const OPTION       = 'worx_settings';
	const REDIRECT_KEY = '_worx_wizard_redirect';

	/**
	 * Singleton holder.
	 *
	 * @var Worx_Core|null
	 */
	private static $instance = null;

	/**
	 * Merged settings (defaults + saved values).
	 *
	 * @var array
	 */
	private $settings = array();

	/**
	 * Optimization pipeline instance.
	 *
	 * @var Worx_Optimizer
	 */
	public $optimizer = null;

	/**
	 * Setup wizard instance.
	 *
	 * @var Worx_Wizard
	 */
	public $wizard = null;

	/**
	 * Modern media hub instance.
	 *
	 * @var Worx_Media_Hub
	 */
	public $hub = null;

	/**
	 * Get (and lazily build) the shared instance.
	 *
	 * @return Worx_Core
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Build the instance, load i18n, wire modules.
	 */
	private function __construct() {
		$this->settings = $this->load_settings();

		$i18n = new Worx_I18n();
		$i18n->load();

		$this->optimizer = new Worx_Optimizer( $this->settings );
		$this->wizard    = new Worx_Wizard( $this->settings );
		$this->hub       = new Worx_Media_Hub( $this->settings );

		if ( is_admin() ) {
			add_action( 'admin_init', array( $this, 'maybe_redirect_to_wizard' ) );
			$this->optimizer->hooks();
			$this->wizard->hooks();
			$this->hub->hooks();
		}
	}

	/**
	 * Default configuration values.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'auto_webp'           => true,
			'lossless_quality'    => 100,
			'max_width'           => 1920,
			'delete_original'     => true,
			'watermark_enabled'   => true,
			'watermark_position'  => 'pos-br',
			'watermark_opacity'   => 85,
			'watermark_custom_id' => 0,
			'wizard_completed'    => false,
		);
	}

	/**
	 * Saved settings merged over defaults.
	 *
	 * @return array
	 */
	public function settings() {
		return $this->settings;
	}

	/**
	 * Read the single settings row and merge it over the defaults.
	 *
	 * @return array
	 */
	private function load_settings() {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( (array) $saved, self::defaults() );
	}

	/**
	 * Activation: seed the settings row and flag the wizard redirect.
	 *
	 * @return void
	 */
	public static function activate() {
		$existing = get_option( self::OPTION, array() );
		add_option(
			self::OPTION,
			wp_parse_args( (array) $existing, self::defaults() ),
			'',
			'no'
		);
		// Land admins on the setup wizard with their next admin load.
		update_option( self::REDIRECT_KEY, 1 );
	}

	/**
	 * Deactivation: drop runtime flags only (data is kept for re-activation).
	 *
	 * @return void
	 */
	public static function deactivate() {
		delete_option( self::REDIRECT_KEY );
	}

	/**
	 * One-shot redirect to the setup wizard after activation.
	 *
	 * Never fires inside AJAX/Cron/network admin, and is cleared as soon as
	 * the wizard page itself is reached (or the flag goes stale).
	 *
	 * @return void
	 */
	public function maybe_redirect_to_wizard() {
		if ( ! get_option( self::REDIRECT_KEY ) ) {
			return;
		}
		if ( wp_doing_ajax() || wp_doing_cron() || is_network_admin() ) {
			return;
		}
		if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			// Flag belongs to an admin session; nobody with rights is here.
			delete_option( self::REDIRECT_KEY );
			return;
		}
		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
		if ( Worx_Wizard::PAGE === $page ) {
			delete_option( self::REDIRECT_KEY );
			return;
		}
		delete_option( self::REDIRECT_KEY );
		wp_safe_redirect( admin_url( 'admin.php?page=' . Worx_Wizard::PAGE ) );
		exit;
	}
}
