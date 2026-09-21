<?php
/**
 * Worx optimization pipeline.
 *
 * Single post-`wp_handle_upload` filter:
 *
 *   MIME sniff (real bytes) -> resize to max_width -> watermark (9 anchors)
 *   -> WebP encode (Imagick preferred, GD fallback) -> atomic file swap
 *   -> metadata stored in `_worx_metadata` postmeta.
 *
 * Fallback Engine: if ANY step fails, the original file stays intact and the
 * upload proceeds completely unchanged.
 *
 * @package Worx
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Worx_Optimizer {

	const PENDING_OPTION = 'worx_pending_meta';
	const META_KEY       = '_worx_metadata';

	/**
	 * Plugin settings snapshot.
	 *
	 * @var array
	 */
	private $settings;

	/**
	 * Watermark engine.
	 *
	 * @var Worx_Watermark
	 */
	private $watermark;

	/**
	 * Detected raster engine: 'imagick' | 'gd' | null.
	 *
	 * @var string|null
	 */
	private $engine;

	/**
	 * Constructor.
	 *
	 * @param array $settings Worx settings array.
	 */
	public function __construct( array $settings ) {
		$this->settings  = $settings;
		$this->watermark = new Worx_Watermark( $settings );
		$this->engine    = $this->detect_engine();
	}

	/**
	 * Conditional hook wiring. When both feature switches are off, the
	 * pipeline is not attached at all (zero footprint).
	 *
	 * @return void
	 */
	public function hooks() {
		if ( empty( $this->settings['auto_webp'] ) && empty( $this->settings['watermark_enabled'] ) ) {
			return;
		}
		add_filter( 'wp_handle_upload', array( $this, 'process_upload' ), 20, 2 );
		add_action( 'add_attachment', array( $this, 'consume_pending_metadata' ), 10, 1 );
	}

	/**
	 * Detected engine id.
	 *
	 * @return string|null 'imagick' | 'gd' | null.
	 */
	public function engine() {
		return $this->engine;
	}

	/**
	 * Human readable engine label (wizard + media hub).
	 *
	 * @return string
	 */
	public function engine_label() {
		if ( 'imagick' === $this->engine ) {
			$ver   = function_exists( 'imagick_version' ) ? imagick_version() : null;
			$label = '';
			if ( is_array( $ver ) && isset( $ver['versionString'] ) ) {
				$label = ' ' . $ver['versionString'];
			} elseif ( is_string( $ver ) && '' !== $ver ) {
				$label = ' ' . $ver;
			}
			return 'Imagick' . $label;
		}
		if ( 'gd' === $this->engine ) {
			$ver = function_exists( 'phpversion' ) ? phpversion( 'gd' ) : '';
			return 'GD' . ( $ver ? ' ' . $ver : '' );
		}
		return __( 'None detected', 'worx' );
	}

	/**
	 * Detect the best available raster engine: Imagick -> GD -> none.
	 *
	 * @return string|null
	 */
	private function detect_engine() {
		if ( class_exists( 'Imagick' ) ) {
			try {
				$probe = new Imagick();
				$probe->destroy();
				return 'imagick';
			} catch ( \Exception $e ) {
				// Fall through to GD.
			}
		}
		if ( function_exists( 'imagewebp' ) && function_exists( 'imagecreatetruecolor' ) && function_exists( 'imagecreatefromjpeg' ) ) {
			return 'gd';
		}
		return null;
	}

	/**
	 * Real-byte MIME sniff (security: declared name is never trusted).
	 *
	 * @param string $file Absolute file path.
	 * @return string MIME type or '' when unknown.
	 */
	private function sniff_mime( $file ) {
		if ( function_exists( 'finfo_open' ) ) {
			$fh = finfo_open( FILEINFO_MIME_TYPE );
			if ( $fh ) {
				$mime = finfo_file( $fh, $file );
				finfo_close( $fh );
				if ( $mime ) {
					return $mime;
				}
			}
		}
		$info = @getimagesize( $file );
		return $info ? $info['mime'] : '';
	}

	/**
	 * Savings percentage between two byte sizes.
	 *
	 * @param int $original  Original size in bytes.
	 * @param int $optimized Optimized size in bytes.
	 * @return float 0-100 with 2 decimals.
	 */
	private function savings_percent( $original, $optimized ) {
		if ( $original <= 0 ) {
			return 0;
		}
		return round( max( 0, ( $original - $optimized ) / $original * 100 ), 2 );
	}

	/**
	 * Build a public URL for an absolute uploads path.
	 *
	 * @param string $absolute Absolute file path.
	 * @return string
	 */
	private function url_for( $absolute ) {
		$uploads = wp_get_upload_dir();
		if ( ! empty( $uploads['basedir'] ) && 0 === strpos( $absolute, $uploads['basedir'] ) ) {
			return $uploads['baseurl'] . substr( $absolute, strlen( $uploads['basedir'] ) );
		}
		if ( defined( 'WP_CONTENT_DIR' ) && 0 === strpos( $absolute, WP_CONTENT_DIR ) ) {
			return content_url( substr( $absolute, strlen( WP_CONTENT_DIR ) ) );
		}
		return $absolute;
	}

	/**
	 * Path relative to the uploads directory root (how WP stores it).
	 *
	 * @param string $absolute Absolute file path.
	 * @return string
	 */
	private function uploads_relative_path( $absolute ) {
		$uploads = wp_get_upload_dir();
		if ( ! empty( $uploads['basedir'] ) && 0 === strpos( $absolute, $uploads['basedir'] ) ) {
			return ltrim( substr( $absolute, strlen( $uploads['basedir'] ) ), '/' );
		}
		if ( defined( 'WP_CONTENT_DIR' ) && 0 === strpos( $absolute, WP_CONTENT_DIR ) ) {
			return ltrim( substr( $absolute, strlen( WP_CONTENT_DIR ) ), '/' );
		}
		return wp_basename( $absolute );
	}

	/**
	 * wp_handle_upload filter callback.
	 *
	 * @param array $upload  WordPress upload result array.
	 * @param int   $post_id Parent post id (0 on async media uploads).
	 * @return array The (possibly rewritten) upload result.
	 */
	public function process_upload( $upload, $post_id = 0 ) {
		if ( ! is_array( $upload ) || is_wp_error( $upload ) ) {
			return $upload;
		}
		if ( empty( $upload['file'] ) || ! file_exists( $upload['file'] ) ) {
			return $upload;
		}
		if ( null === $this->engine ) {
			// Fallback Engine: no raster engine on this server, keep original.
			return $upload;
		}

		$ext = strtolower( pathinfo( $upload['file'], PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, array( 'jpg', 'jpeg', 'png' ), true ) ) {
			return $upload; // Already WebP / GIF / non-image: pass through.
		}

		$mime = $this->sniff_mime( $upload['file'] );
		if ( ! in_array( $mime, array( 'image/jpeg', 'image/png' ), true ) ) {
			// Declared type and real bytes disagree: leave the file untouched.
			return $upload;
		}

		$dest = dirname( $upload['file'] ) . '/' . pathinfo( $upload['file'], PATHINFO_FILENAME ) . '.webp';

		try {
			$result = $this->run_pipeline( $upload['file'], $this->settings, $dest );
		} catch ( \Throwable $e ) {
			error_log( sprintf( 'Worx: pipeline failed for "%s": %s', $upload['file'], $e->getMessage() ) );
			return $upload; // Fallback Engine: original untouched.
		}

		$meta = array(
			'is_webp'         => true,
			'original_size'   => $result['original_size'],
			'optimized_size'  => $result['size'],
			'savings_percent' => $this->savings_percent( $result['original_size'], $result['size'] ),
			'watermarked'     => ! empty( $this->settings['watermark_enabled'] ),
			'processed_at'    => time(),
			'engine'          => $this->engine,
		);

		if ( ! empty( $upload['post_ID'] ) ) {
			// Classic editor flow: the attachment row was inserted BEFORE this
			// filter with the old file, so repoint it and rebuild sub-sizes.
			$this->finalize_attachment( (int) $upload['post_ID'], $result['file'], $meta );
		} else {
			// Async media flow: the attachment does not exist yet; deliver the
			// metadata from the add_attachment hook instead.
			$this->queue_metadata( $result['file'], $meta );
		}

		$upload['file'] = $result['file'];
		$upload['type'] = 'image/webp';
		$upload['url']  = $this->url_for( $result['file'] );

		return $upload;
	}

	/**
	 * Repoint an already-inserted attachment at the converted file and
	 * regenerate its sub-sizes from the WebP master.
	 *
	 * @param int    $post_id  Attachment post id.
	 * @param string $new_file Absolute path of the new master file.
	 * @param array  $meta     Worx metadata payload.
	 * @return void
	 */
	private function finalize_attachment( $post_id, $new_file, array $meta ) {
		if ( ! $post_id || ! function_exists( 'get_post_type' ) || 'attachment' !== get_post_type( $post_id ) ) {
			$this->queue_metadata( $new_file, $meta );
			return;
		}

		// Remove orphan sub-sizes generated from the pre-conversion file.
		$old_file = function_exists( 'get_attached_file' ) ? get_attached_file( $post_id ) : '';
		$old_meta = function_exists( 'wp_get_attachment_metadata' ) ? wp_get_attachment_metadata( $post_id ) : false;
		if ( is_array( $old_meta ) && ! empty( $old_meta['sizes'] ) && $old_file ) {
			$dir = dirname( $old_file );
			foreach ( (array) $old_meta['sizes'] as $size ) {
				if ( ! empty( $size['file'] ) ) {
					$path = $dir . '/' . $size['file'];
					if ( file_exists( $path ) ) {
						@unlink( $path );
					}
				}
			}
		}

		update_attached_file( $post_id, $this->uploads_relative_path( $new_file ) );
		wp_update_post(
			array(
				'ID'             => $post_id,
				'post_mime_type' => 'image/webp',
			)
		);
		wp_update_attachment_metadata( $post_id, wp_generate_attachment_metadata( $post_id, $new_file ) );
		update_post_meta( $post_id, self::META_KEY, $meta );
	}

	/**
	 * Store metadata for the async flow, keyed by the absolute webp path.
	 *
	 * @param string $absolute_path New master file path.
	 * @param array  $meta          Worx metadata payload.
	 * @return void
	 */
	private function queue_metadata( $absolute_path, array $meta ) {
		$pending = get_option( self::PENDING_OPTION, array() );
		if ( ! is_array( $pending ) ) {
			$pending = array();
		}
		// Keep the row light: drop entries older than one hour.
		$now = time();
		foreach ( array_keys( $pending ) as $key ) {
			$queued_at = isset( $pending[ $key ]['queued_at'] ) ? (int) $pending[ $key ]['queued_at'] : 0;
			if ( ! $queued_at || ( $now - $queued_at ) > HOUR_IN_SECONDS ) {
				unset( $pending[ $key ] );
			}
		}
		$meta['queued_at'] = $now;
		$pending[ $absolute_path ] = $meta;
		update_option( self::PENDING_OPTION, $pending, false );
	}

	/**
	 * add_attachment hook: deliver queued metadata once the async-flow
	 * attachment row exists (matched by file basename).
	 *
	 * @param int $post_id New attachment id.
	 * @return void
	 */
	public function consume_pending_metadata( $post_id ) {
		$pending = get_option( self::PENDING_OPTION, array() );
		if ( ! is_array( $pending ) || empty( $pending ) ) {
			return;
		}
		$file = function_exists( 'get_attached_file' ) ? get_attached_file( $post_id ) : '';
		if ( ! $file ) {
			return;
		}
		$basename = wp_basename( $file );
		$changed  = false;
		foreach ( array_keys( $pending ) as $key ) {
			if ( wp_basename( (string) $key ) === $basename ) {
				$meta = $pending[ $key ];
				unset( $meta['queued_at'] );
				unset( $pending[ $key ] );
				$changed = true;
				update_post_meta( $post_id, self::META_KEY, $meta );
			}
		}
		if ( $changed ) {
			update_option( self::PENDING_OPTION, $pending, false );
		}
	}

	/**
	 * Reprocess an existing attachment (media hub "Reprocess" action).
	 *
	 * Rebuilds the WebP master from the untouched original when a
	 * "-original" sibling exists, otherwise re-runs the current master.
	 * Sub-sizes are regenerated from the new master.
	 *
	 * @param int $post_id Attachment post id.
	 * @return array Final metadata.
	 * @throws \RuntimeException When the operation cannot be completed.
	 */
	public function reprocess_attachment( $post_id ) {
		$file = get_attached_file( $post_id );
		if ( ! $file || ! file_exists( $file ) ) {
			throw new \RuntimeException( __( 'Attached file not found on disk.', 'worx' ) );
		}
		if ( null === $this->engine ) {
			throw new \RuntimeException( __( 'No image engine (Imagick/GD) available on this server.', 'worx' ) );
		}

		$info = pathinfo( $file );
		$dir  = $info['dirname'];
		$name = $info['filename'];
		$ext  = strtolower( $info['extension'] );

		$existing       = get_post_meta( $post_id, self::META_KEY, true );
		$true_original  = ( is_array( $existing ) && ! empty( $existing['original_size'] ) ) ? (int) $existing['original_size'] : (int) filesize( $file );

		if ( 'webp' === $ext ) {
			// The kept original carries its source extension (jpg/jpeg/png).
			$sib = null;
			foreach ( array( 'jpg', 'jpeg', 'png' ) as $oext ) {
				$candidate = $dir . '/' . $name . '-original.' . $oext;
				if ( file_exists( $candidate ) ) {
					$sib = $candidate;
					break;
				}
			}
			if ( $sib ) {
				// Rebuild from the untouched original (clean re-watermark).
				$src  = $sib;
				$dest = $file;
			} else {
				$src  = $file;
				$dest = $dir . '/' . $name . '.wx.webp';
			}
		} else {
			// Never optimized before (JPG/PNG master).
			$src  = $file;
			$dest = $dir . '/' . $name . '.webp';
		}

		$mime = $this->sniff_mime( $src );
		if ( ! in_array( $mime, array( 'image/jpeg', 'image/png', 'image/webp' ), true ) ) {
			throw new \RuntimeException( __( 'Unsupported image format.', 'worx' ) );
		}

		$result = $this->run_pipeline( $src, $this->settings, $dest );

		// Land the master file on the canonical <name>.webp path.
		$final = $dir . '/' . $name . '.webp';
		if ( $result['file'] !== $final ) {
			if ( file_exists( $final ) ) {
				@unlink( $final );
			}
			if ( ! rename( $result['file'], $final ) ) {
				throw new \RuntimeException( __( 'Could not move the WebP file into place.', 'worx' ) );
			}
			$result['file'] = $final;
			$result['size'] = (int) filesize( $final );
		}
		if ( $final !== $file && file_exists( $file ) ) {
			@unlink( $file );
			update_attached_file( $post_id, $this->uploads_relative_path( $final ) );
			wp_update_post(
				array(
					'ID'             => $post_id,
					'post_mime_type' => 'image/webp',
				)
			);
		}

		wp_update_attachment_metadata( $post_id, wp_generate_attachment_metadata( $post_id, $final ) );

		$meta = array(
			'is_webp'         => true,
			'original_size'   => $true_original,
			'optimized_size'  => $result['size'],
			'savings_percent' => $this->savings_percent( $true_original, $result['size'] ),
			'watermarked'     => ! empty( $this->settings['watermark_enabled'] ),
			'processed_at'    => time(),
			'engine'          => $this->engine,
		);
		update_post_meta( $post_id, self::META_KEY, $meta );

		return $meta;
	}

	/**
	 * Run the full conversion for one file.
	 *
	 * The output is encoded to a temporary slot first; the destination is
	 * only touched once the encode is verified (safe Fallback Engine).
	 *
	 * @param string $src      Source file (absolute).
	 * @param array  $settings Settings snapshot.
	 * @param string $dest     Final destination (absolute).
	 * @return array {file, size, original_size}.
	 * @throws \RuntimeException On any hard failure.
	 */
	private function run_pipeline( $src, array $settings, $dest ) {
		$src  = (string) realpath( $src );
		$dest = (string) $dest;
		if ( ! $src || ! is_file( $src ) ) {
			throw new \RuntimeException( __( 'Source file is missing.', 'worx' ) );
		}
		if ( $dest === $src ) {
			throw new \RuntimeException( __( 'Destination must differ from source.', 'worx' ) );
		}

		$original_size = (int) filesize( $src );
		$tmp           = $dest . '.wx-tmp.webp';
		if ( file_exists( $tmp ) ) {
			@unlink( $tmp );
		}

		$max_width = (int) $settings['max_width'];
		$quality   = max( 60, min( 100, (int) $settings['lossless_quality'] ) );
		$do_wm     = ! empty( $settings['watermark_enabled'] );

		if ( 'imagick' === $this->engine ) {
			$this->encode_imagick( $src, $tmp, $max_width, $quality, $do_wm, $settings );
		} else {
			$this->encode_gd( $src, $tmp, $max_width, $quality, $do_wm, $settings );
		}

		if ( ! file_exists( $tmp ) ) {
			throw new \RuntimeException( __( 'WebP encoding failed - no output file produced.', 'worx' ) );
		}

		if ( file_exists( $dest ) ) {
			@unlink( $dest );
		}
		if ( ! rename( $tmp, $dest ) ) {
			throw new \RuntimeException( __( 'Could not move the WebP file into place.', 'worx' ) );
		}

		// Dispose of the JPEG/PNG source according to the settings.
		$ext = strtolower( pathinfo( $src, PATHINFO_EXTENSION ) );
		if ( in_array( $ext, array( 'jpg', 'jpeg', 'png' ), true ) ) {
			if ( ! empty( $settings['delete_original'] ) ) {
				@unlink( $src );
			} else {
				$sib = dirname( $src ) . '/' . pathinfo( $src, PATHINFO_FILENAME ) . '-original.' . $ext;
				if ( file_exists( $sib ) ) {
					@unlink( $sib );
				}
				rename( $src, $sib );
			}
		}

		return array(
			'file'          => $dest,
			'size'          => (int) filesize( $dest ),
			'original_size' => $original_size,
		);
	}

	/**
	 * GD encode path (always available fallback).
	 *
	 * Note: GD's imagewebp() has no lossless mode; quality 100 yields the
	 * maximum-quality output GD can produce (near-lossless for photos).
	 *
	 * @param string $src       Source file.
	 * @param string $dest      Output file (temporary slot).
	 * @param int    $max_width Max width in px (0 = no resize).
	 * @param int    $quality   60-100.
	 * @param bool   $do_wm     Apply watermark.
	 * @param array  $settings  Settings snapshot.
	 * @return void
	 * @throws \RuntimeException When the image cannot be processed.
	 */
	private function encode_gd( $src, $dest, $max_width, $quality, $do_wm, array $settings ) {
		$mime = $this->sniff_mime( $src );
		$img  = null;
		switch ( $mime ) {
			case 'image/jpeg':
				$img = @imagecreatefromjpeg( $src );
				break;
			case 'image/png':
				$img = @imagecreatefrompng( $src );
				break;
			case 'image/webp':
				$img = @imagecreatefromwebp( $src );
				break;
		}
		if ( ! $img ) {
			throw new \RuntimeException( sprintf( 'GD could not read "%s".', $src ) );
		}

		// Normalize paletted images to truecolor (required for alpha + resample).
		if ( ! imageistruecolor( $img ) ) {
			$tmp_img = imagecreatetruecolor( imagesx( $img ), imagesy( $img ) );
			imagecopy( $tmp_img, $img, 0, 0, 0, 0, imagesx( $img ), imagesy( $img ) );
			imagedestroy( $img );
			$img = $tmp_img;
		}
		imagesavealpha( $img, true );

		// Step 1: resize when wider than the allowed maximum.
		$w = imagesx( $img );
		$h = imagesy( $img );
		if ( $max_width > 0 && $w > $max_width ) {
			$new_h = max( 1, (int) round( $h * $max_width / $w ) );
			$res   = imagecreatetruecolor( $max_width, $new_h );
			imagealphablending( $res, false );
			imagesavealpha( $res, true );
			$bg = imagecolorallocatealpha( $res, 0, 0, 0, 127 );
			imagefill( $res, 0, 0, $bg );
			imagecopyresampled( $res, $img, 0, 0, 0, 0, $max_width, $new_h, $w, $h );
			imagedestroy( $img );
			$img = $res;
			$w   = $max_width;
			$h   = $new_h;
		}

		// Step 2: watermark.
		if ( $do_wm ) {
			$img = $this->watermark->apply_gd( $img, $settings );
		}

		// Step 3: WebP encode (alpha channel is preserved for PNG sources).
		$ok = @imagewebp( $img, $dest, $quality );
		imagedestroy( $img );
		if ( ! $ok ) {
			throw new \RuntimeException( 'imagewebp() failed - WebP support missing from this GD build.' );
		}
	}

	/**
	 * Imagick encode path (preferred when available).
	 *
	 * Quality 100 uses the encoder's true lossless mode (webp:lossless).
	 *
	 * @param string  $src       Source file.
	 * @param string  $dest      Output file (temporary slot).
	 * @param int     $max_width Max width in px (0 = no resize).
	 * @param int     $quality   60-100.
	 * @param bool    $do_wm     Apply watermark.
	 * @param array   $settings  Settings snapshot.
	 * @return void
	 * @throws \RuntimeException When the image cannot be processed.
	 */
	private function encode_imagick( $src, $dest, $max_width, $quality, $do_wm, array $settings ) {
		try {
			$im = new Imagick( $src );
		} catch ( \Exception $e ) {
			throw new \RuntimeException( sprintf( 'Imagick could not read "%s".', $src ) );
		}

		// Normalize EXIF orientation before re-encoding (WebP stores it differently).
		if ( method_exists( $im, 'autoOrient' ) ) {
			$im->autoOrient();
		}
		$im->setImageFormat( 'webp' );

		// Step 1: resize when wider than the allowed maximum.
		$w = $im->getImageWidth();
		if ( $max_width > 0 && $w > $max_width ) {
			$im->resizeImage( $max_width, 0, Imagick::FilterLanczos, 1, true );
		}

		// Step 2: watermark.
		if ( $do_wm ) {
			$im = $this->watermark->apply_imagick( $im, $settings );
		}

		// Step 3: WebP encode.
		if ( 100 === $quality ) {
			$im->setOption( 'webp:lossless', 'true' );
			$im->setImageCompressionQuality( 100 );
		} else {
			$im->setImageCompressionQuality( $quality );
		}

		$ok = $im->writeImage( $dest );
		$im->clear();
		$im->destroy();
		if ( ! $ok ) {
			throw new \RuntimeException( 'Imagick writeImage() failed.' );
		}
	}
}
