=== Worx Image Optimizer & Smart Watermark ===
Contributors: obsifox
Tags: webp, image optimization, watermark, media library, performance
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.2
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Ultra-fast WebP conversion, smart resizing, real-time 9-position watermark and a modern media hub. Zero frontend bloat, zero external dependencies.

== Description ==

Worx modernizes the WordPress media lifecycle with a zero-lag image pipeline:

* **Automatic WebP conversion** at quality 100 (true lossless on Imagick servers, maximum quality on GD).
* **Smart resizing** to your maximum allowed width before encoding.
* **Real-time watermark** with a 9-anchor position matrix, adjustable opacity and a swappable logo (built-in WX logo or any image from your library).
* **Setup wizard** with a live watermark preview driven by pure Vanilla JS - zero server round-trips while you adjust settings.
* **Modern media hub** - savings percentage, WebP and watermark badges in the Media Library, one-click Reprocess per image, and a library-wide stats strip.
* **Size analytics** stored per attachment (original size, optimized size, savings %).
* **i18n ready** - 100% English code base (text domain: worx) with a bundled, fully translated Persian (fa-IR) version and RTL layouts.

Performance guarantees:

* Nothing is enqueued on the frontend - an absolute zero footprint for visitors.
* All icons are inline SVG (no icon fonts, no CDN requests, works offline).
* One settings row in wp_options and one postmeta record per image.

Safety:

* Real-byte MIME validation (finfo) before any processing.
* Nonce + capability checks on every write and AJAX action.
* Atomic file swap: if encoding fails, the original file stays intact (Fallback Engine).
* Complete data cleanup on uninstall.

== Installation ==

1. Upload worx-image-optimizer.zip via Plugins > Add New > Upload Plugin, or clone the repository into wp-content/plugins/.
2. Activate the plugin. You are redirected to the setup wizard (Media > Worx).
3. Choose your watermark anchor, opacity and engine options, then save.
4. New JPG/PNG uploads are converted, watermarked and optimized automatically.
5. Use the Reprocess action in the Media Library to convert existing images.

== Frequently Asked Questions ==

= Does it add anything to my frontend? =

No. Zero assets, zero queries, zero bytes on the public side of the site.

= What if my server has neither GD nor Imagick? =

The plugin detects the missing engine and leaves every upload untouched (Fallback Engine). The wizard shows which engine is active.

= Is the original file deleted? =

Only if you enable "Delete the original file after WebP is generated" in the wizard. Otherwise a copy is kept next to the WebP file with the "-original" suffix, and Reprocess always rebuilds from that clean copy.

= Which languages are supported? =

English (code base) and Persian (fa-IR, bundled). Any other language can be added by translating languages/worx.pot.

== Changelog ==

= 1.0.0 =
* Initial release.
