<?php
/**
 * Worx watermark engine.
 *
 * Pure coordinate math for the 9-anchor position matrix plus lossless-aware
 * alpha compositing on both raster engines:
 *
 *  - GD: per-pixel alpha rebuild (imagecopymerge() ignores source alpha, so
 *    the layer is rebuilt with effective alpha = source_alpha x opacity, then
 *    merged at 100% which honours per-pixel transparency exactly).
 *  - Imagick: channel multiply on the watermark (alpha included), then
 *    Composite_Over at the computed anchor.
 *
 * @package Worx
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Worx_Watermark {

	/**
	 * The 9 anchor codes of the position matrix.
	 *
	 * @var array
	 */
	const POSITIONS = array(
		'pos-tl', 'pos-tc', 'pos-tr',
		'pos-cl', 'pos-cc', 'pos-cr',
		'pos-bl', 'pos-bc', 'pos-br',
	);

	/**
	 * Maximum watermark width relative to the canvas width.
	 *
	 * @var float
	 */
	const MAX_RATIO = 0.35;

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
	 * Resolve the watermark image path.
	 *
	 * Custom attachment when set (validated as a readable raster image),
	 * otherwise the built-in neon WX logo.
	 *
	 * @return string Absolute file path (may not exist; callers must check).
	 */
	public function get_source_path() {
		$custom_id = isset( $this->settings['watermark_custom_id'] ) ? (int) $this->settings['watermark_custom_id'] : 0;
		if ( $custom_id > 0 && function_exists( 'get_attached_file' ) ) {
			$path = get_attached_file( $custom_id );
			if ( $path && file_exists( $path ) ) {
				$info = @getimagesize( $path );
				if ( $info && in_array( $info['mime'], array( 'image/png', 'image/webp', 'image/jpeg' ), true ) ) {
					return $path;
				}
			}
		}
		return WORX_PLUGIN_DIR . 'assets/img/wx-watermark.png';
	}

	/**
	 * Adaptive corner padding: 4% of the smallest canvas side, min 12px.
	 *
	 * @param int $canvas_w Canvas width in px.
	 * @param int $canvas_h Canvas height in px.
	 * @return int
	 */
	public function padding_for( $canvas_w, $canvas_h ) {
		return max( 12, (int) round( min( $canvas_w, $canvas_h ) * 0.04 ) );
	}

	/**
	 * Clamp the watermark to a sane size relative to the canvas.
	 *
	 * @param int $canvas_w Canvas width in px.
	 * @param int $wm_w     Watermark width in px.
	 * @param int $wm_h     Watermark height in px.
	 * @return array {0: width, 1: height}
	 */
	public function target_size( $canvas_w, $wm_w, $wm_h ) {
		$max_w = max( 1, (int) floor( $canvas_w * self::MAX_RATIO ) );
		if ( $wm_w <= $max_w ) {
			return array( $wm_w, $wm_h );
		}
		$ratio = $max_w / $wm_w;
		return array( $max_w, max( 1, (int) round( $wm_h * $ratio ) ) );
	}

	/**
	 * Compute anchor coordinates on the canvas.
	 *
	 * Horizontal: left  = padding | center = (W - w) / 2 | right = W - w - padding
	 * Vertical:   top   = padding | center = (H - h) / 2 | bottom = H - h - padding
	 *
	 * @param int    $canvas_w Canvas width in px.
	 * @param int    $canvas_h Canvas height in px.
	 * @param int    $wm_w     Watermark width in px (already clamped).
	 * @param int    $wm_h     Watermark height in px (already clamped).
	 * @param string $position One of self::POSITIONS.
	 * @param int    $padding  Corner padding in px.
	 * @return array {0: x, 1: y} clamped to >= 0.
	 */
	public function calculate_position( $canvas_w, $canvas_h, $wm_w, $wm_h, $position, $padding ) {
		$position = in_array( $position, self::POSITIONS, true ) ? $position : 'pos-br';

		// "pos-XY": X = row (t/c/b), Y = column (l/c/r).
		$row = $position[4];
		$col = $position[5];

		if ( 'c' === $col ) {
			$x = ( $canvas_w - $wm_w ) / 2;
		} elseif ( 'r' === $col ) {
			$x = $canvas_w - $wm_w - $padding;
		} else {
			$x = $padding;
		}

		if ( 'c' === $row ) {
			$y = ( $canvas_h - $wm_h ) / 2;
		} elseif ( 'b' === $row ) {
			$y = $canvas_h - $wm_h - $padding;
		} else {
			$y = $padding;
		}

		return array(
			(int) max( 0, $x ),
			(int) max( 0, $y ),
		);
	}

	/**
	 * Composite the watermark onto a GD truecolor canvas (in place).
	 *
	 * @param resource $canvas   GD truecolor image.
	 * @param array    $settings Worx settings array.
	 * @return resource The same canvas.
	 */
	public function apply_gd( $canvas, $settings ) {
		$src = $this->get_source_path();
		if ( ! $src || ! file_exists( $src ) ) {
			return $canvas;
		}

		$info = @getimagesize( $src );
		if ( ! $info ) {
			return $canvas;
		}

		$wm = null;
		switch ( $info['mime'] ) {
			case 'image/png':
				$wm = @imagecreatefrompng( $src );
				break;
			case 'image/webp':
				$wm = @imagecreatefromwebp( $src );
				break;
			default:
				$wm = @imagecreatefromjpeg( $src );
				break;
		}
		if ( ! $wm ) {
			return $canvas;
		}

		$canvas_w = imagesx( $canvas );
		$canvas_h = imagesy( $canvas );

		list( $tw, $th ) = $this->target_size( $canvas_w, imagesx( $wm ), imagesy( $wm ) );
		if ( (int) $tw !== imagesx( $wm ) || (int) $th !== imagesy( $wm ) ) {
			$scaled = imagecreatetruecolor( $tw, $th );
			imagealphablending( $scaled, false );
			imagesavealpha( $scaled, true );
			$transparent = imagecolorallocatealpha( $scaled, 0, 0, 0, 127 );
			imagefill( $scaled, 0, 0, $transparent );
			imagecopyresampled( $scaled, $wm, 0, 0, 0, 0, $tw, $th, imagesx( $wm ), imagesy( $wm ) );
			imagedestroy( $wm );
			$wm = $scaled;
		}

		$opacity = max( 10, min( 100, (int) $settings['watermark_opacity'] ) );
		$padding = $this->padding_for( $canvas_w, $canvas_h );
		list( $x, $y ) = $this->calculate_position( $canvas_w, $canvas_h, $tw, $th, $settings['watermark_position'], $padding );

		// Rebuild the layer with effective per-pixel alpha.
		// (127 - a) is the source opacity; scale it by the requested opacity.
		$layer = imagecreatetruecolor( $tw, $th );
		imagealphablending( $layer, false );
		imagesavealpha( $layer, true );
		for ( $px = 0; $px < $tw; $px++ ) {
			for ( $py = 0; $py < $th; $py++ ) {
				$rgba = imagecolorat( $wm, $px, $py );
				$src_alpha = 127 - ( $rgba & 0x7f ); // 0 transparent -> 127 opaque.
				if ( $src_alpha <= 0 ) {
					continue;
				}
				$effective = (int) floor( $src_alpha * $opacity / 100 );
				if ( $effective <= 0 ) {
					continue;
				}
				$r = ( $rgba >> 16 ) & 0xff;
				$g = ( $rgba >> 8 ) & 0xff;
				$b = $rgba & 0xff;
				imagesetpixel( $layer, $px, $py, imagecolorallocatealpha( $layer, $r, $g, $b, 127 - $effective ) );
			}
		}
		imagedestroy( $wm );

		// Merge at 100%: source per-pixel alpha is honoured exactly.
		imagecopymerge( $canvas, $layer, $x, $y, 0, 0, $tw, $th, 100 );
		imagedestroy( $layer );

		return $canvas;
	}

	/**
	 * Composite the watermark onto an Imagick canvas (in place).
	 *
	 * @param Imagick $canvas   Imagick image.
	 * @param array   $settings Worx settings array.
	 * @return Imagick The same canvas.
	 */
	public function apply_imagick( Imagick $canvas, array $settings ) {
		$src = $this->get_source_path();
		if ( ! $src || ! file_exists( $src ) ) {
			return $canvas;
		}

		try {
			$wm = new Imagick( $src );
		} catch ( \Exception $e ) {
			return $canvas;
		}

		$geom     = $canvas->getImageGeometry();
		$canvas_w = $geom['width'];
		$canvas_h = $geom['height'];

		list( $tw, $th ) = $this->target_size( $canvas_w, $wm->getImageWidth(), $wm->getImageHeight() );
		if ( (int) $tw !== $wm->getImageWidth() || (int) $th !== $wm->getImageHeight() ) {
			$wm->scaleImage( $tw, $th );
		}

		$opacity = max( 10, min( 100, (int) $settings['watermark_opacity'] ) );
		// Multiplying every channel (RGB + alpha) by the factor fades the
		// watermark without touching colour fidelity.
		$wm->evaluateImage( Imagick::Evaluate_Multiply, $opacity / 100, Imagick::PixelInterpolateUndefined );

		$padding = $this->padding_for( $canvas_w, $canvas_h );
		list( $x, $y ) = $this->calculate_position( $canvas_w, $canvas_h, $tw, $th, $settings['watermark_position'], $padding );

		$canvas->compositeImage( $wm, Imagick::Composite_Over, $x, $y );
		$wm->clear();

		return $canvas;
	}
}
