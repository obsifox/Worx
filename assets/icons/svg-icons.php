<?php
/**
 * Worx inline SVG icon store.
 *
 * Google Material icon path data, inlined - no icon fonts, no woff2 files,
 * no CDN requests. Renders at 0 external cost and works fully offline.
 *
 * @package Worx
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'worx_icon' ) ) {
	/**
	 * Return an inline SVG for a known icon name.
	 *
	 * @param string $name  Icon key (see the map below).
	 * @param int    $size  Square size in px.
	 * @param string $class Extra CSS classes.
	 * @return string SVG markup ('' for unknown names).
	 */
	function worx_icon( $name, $size = 20, $class = '' ) {
		static $paths = array(
			// Google Material Symbols (24x24, Apache-2.0).
			'check'         => 'M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z',
			'check_circle'  => 'M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z',
			'info'          => 'M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-6h2v6zm0-8h-2V7h2v2z',
			'bolt'          => 'M7 2v11h3v9l7-12h-4l4-8z',
			'security'      => 'M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm-2 16l-4-4 1.41-1.41L10 14.17l6.59-6.59L18 9l-8 8z',
			'settings'      => 'M19.14 12.94c.04-.3.06-.61.06-.94 0-.32-.02-.64-.07-.94l2.03-1.58c.18-.14.23-.41.12-.61l-1.92-3.32c-.12-.22-.37-.29-.59-.22l-2.39.96c-.5-.38-1.03-.7-1.62-.94l-.36-2.54c-.04-.24-.24-.41-.48-.41h-3.84c-.24 0-.43.17-.47.41l-.36 2.54c-.59.24-1.13.57-1.62.94l-2.39-.96c-.22-.08-.47 0-.59.22L2.74 8.87c-.12.21-.08.47.12.61l2.03 1.58c-.05.3-.09.63-.09.94s.02.64.07.94l-2.03 1.58c-.18.14-.23.41-.12.61l1.92 3.32c.12.22.37.29.59.22l2.39-.96c.5.38 1.03.7 1.62.94l.36 2.54c.05.24.24.41.48.41h3.84c.24 0 .44-.17.47-.41l.36-2.54c.59-.24 1.13-.56 1.62-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32c.12-.22.07-.47-.12-.61l-2.01-1.58zM12 15.6c-1.98 0-3.6-1.62-3.6-3.6s1.62-3.6 3.6-3.6 3.6 1.62 3.6 3.6-1.62 3.6-3.6 3.6z',
			'refresh'       => 'M17.65 6.35C16.2 4.9 14.21 4 12 4c-4.42 0-7.99 3.58-7.99 8s3.57 8 7.99 8c3.73 0 6.84-2.55 7.73-6h-2.08c-.82 2.33-3.04 4-5.65 4-3.31 0-6-2.69-6-6s2.69-6 6-6c1.66 0 3.14.69 4.22 1.78L13 11h7V4l-2.35 2.35z',
			'trash'         => 'M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z',
			'image'         => 'M21 19V5c0-1.1-.9-2-2-2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2zM8.5 13.5l2.5 3.01L14.5 12l4.5 6H5l3.5-4.5z',
			'photo_library' => 'M22 16V4c0-1.1-.9-2-2-2H8c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2zm-11-4l2.03 2.71L16 11l4 5H8l3-4zM2 6v14c0 1.1.9 2 2 2h14v-2H4V6H2z',
			'compress'      => 'M10 22v-6h4v6h5v-8h5L12 3 3 14h5v8z',
			'water_drop'    => 'M12 2c-5.33 4.55-8 8.48-8 11.8 0 4.98 3.8 8.2 8 8.2s8-3.22 8-8.2c0-3.32-2.67-7.25-8-11.8zm0 18c-3.35 0-6-2.57-6-6.2 0-2.34 1.95-5.44 6-9.14 4.05 3.7 6 6.79 6 9.14 0 3.63-2.65 6.2-6 6.2z',
			'chevron'       => 'M10 6L8.59 7.41 13.17 12l-4.58 4.59L10 18l6-6z',
		);

		$size = max( 8, (int) $size );
		$class = $class ? ' ' . esc_attr( $class ) : '';

		if ( 'logo' === $name ) {
			// Built-in neon WX logo (pure vector, in source).
			return '<svg xmlns="http://www.w3.org/2000/svg" class="wx-icon' . $class . '" width="' . $size . '" height="' . $size . '" viewBox="0 0 64 64" aria-hidden="true">'
				. '<defs><linearGradient id="wxGradLogo" x1="0" y1="0" x2="1" y2="1">'
				. '<stop offset="0" stop-color="#7C4DFF"/><stop offset="1" stop-color="#00E5FF"/>'
				. '</linearGradient></defs>'
				. '<rect x="3" y="3" width="58" height="58" rx="15" fill="url(#wxGradLogo)"/>'
				. '<text x="32" y="42" font-family="Arial, Helvetica, sans-serif" font-size="26" font-weight="700" fill="#ffffff" text-anchor="middle">WX</text>'
				. '</svg>';
		}

		if ( ! isset( $paths[ $name ] ) ) {
			return '';
		}

		return '<svg xmlns="http://www.w3.org/2000/svg" class="wx-icon' . $class . '" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">'
			. '<path d="' . $paths[ $name ] . '"/>'
			. '</svg>';
	}
}
