<?php
/**
 * Worx i18n loader.
 *
 * GNU gettext based, text domain "worx", translations bundled in /languages
 * (English is the code base, Persian is shipped ready to use).
 *
 * @package Worx
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Worx_I18n {

	/**
	 * Load the text domain from the plugin /languages folder.
	 *
	 * @return void
	 */
	public function load() {
		load_plugin_textdomain(
			'worx',
			false,
			WORX_PLUGIN_BASENAME . '/languages'
		);
	}
}
