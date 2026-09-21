<?php
/**
 * Plugin Name:       Worx Image Optimizer & Smart Watermark
 * Plugin URI:        https://obsifox.studio/worx
 * Description:       Zero-lag image pipeline for WordPress: true-quality WebP conversion, smart resizing, real-time 9-position watermark and a modern media hub. Zero frontend bloat, zero external dependencies.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.2
 * Author:            obsifox studio
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       worx
 * Domain Path:       /languages
 *
 * @package Worx
 */

// No direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin constants (single source of truth for the whole plugin).
 */
define( 'WORX_VERSION', '1.0.0' );
define( 'WORX_PLUGIN_FILE', __FILE__ );
define( 'WORX_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'WORX_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WORX_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Module loading.
 *
 * Class files are required once (cheap, no I/O side effects); every runtime
 * behaviour is wired conditionally by Worx_Core based on the saved settings,
 * so a fully-disabled plugin leaves zero hooks in the WordPress runtime.
 */
require_once WORX_PLUGIN_DIR . 'assets/icons/svg-icons.php';
require_once WORX_PLUGIN_DIR . 'includes/class-worx-i18n.php';
require_once WORX_PLUGIN_DIR . 'includes/class-worx-watermark.php';
require_once WORX_PLUGIN_DIR . 'includes/class-worx-optimizer.php';
require_once WORX_PLUGIN_DIR . 'includes/class-worx-wizard.php';
require_once WORX_PLUGIN_DIR . 'includes/class-worx-media-hub.php';
require_once WORX_PLUGIN_DIR . 'includes/class-worx-core.php';

register_activation_hook( __FILE__, array( 'Worx_Core', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Worx_Core', 'deactivate' ) );

/**
 * Boot the plugin once every other plugin has loaded.
 */
add_action( 'plugins_loaded', array( 'Worx_Core', 'instance' ), 5 );
