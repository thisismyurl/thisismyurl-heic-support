=== HEIC Support by Christopher Ross ===
Contributors: thisismyurl
Plugin URI: https://thisismyurl.com/thisismyurl-heic-support/
Author: Christopher Ross
Author URI: https://thisismyurl.com/
Donate link: https://github.com/sponsors/thisismyurl
Tags: heic, heif, webp, media library, apple images
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.6190.1640
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Convert HEIC/HEIF images from iPhone and iPad uploads to WebP automatically, with non-destructive backups and one-click restore.

== Description ==

Modern iOS and iPadOS devices shoot in HEIC/HEIF by default. That format is compact on device, but most WordPress hosts and browsers don't handle it natively — images don't display, uploads fail, and pages stall while browsers try to decode something they can't read.

HEIC Support by Christopher Ross converts every HEIC or HEIF file the moment it lands in your Media Library. The original file is never deleted — it moves to a backup folder and can be restored any time with one click, individually or in bulk.

= What it does =

* Converts HEIC and HEIF uploads to WebP automatically on upload.
* Batch-converts existing HEIC/HEIF images already in your Media Library via an AJAX-powered tool under Media > HEIC Support.
* Preserves originals in `uploads/heic-backups/` — the plugin never deletes a source file.
* One-click restore per image, and a "Restore All" bulk action for a full rollback.
* Environment preflight panel: confirms Imagick is installed, HEIC decoding is available, and the backup directory is writable before you run anything.
* Live savings report showing how much storage the conversion has recovered.

= No external services =

All conversion runs on your server using PHP's Imagick extension and libheif. Nothing is sent off-site.

= Requirements =

* Imagick PHP extension with libheif support — most modern shared hosts (WP Engine, SiteGround, Kinsta) include this. The plugin's Environment Check panel tells you immediately if anything is missing.
* WordPress 6.0+ and PHP 7.4+.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` or install directly from the WordPress plugin directory.
2. Activate through the **Plugins** screen.
3. Go to **Media > HEIC Support**.
4. Run the Environment Check to confirm your server supports conversion.
5. Click **Optimize All** to convert existing HEIC/HEIF files, or just let new uploads convert automatically.

== Frequently Asked Questions ==

= Does this delete my original HEIC images? =
No. Each original is moved to `uploads/heic-backups/` before conversion. You can restore any image — or all of them — with one click from the Media > HEIC Support screen.

= What happens if I deactivate the plugin? =
Your converted WebP files stay in place. If you want to revert to the original HEIC files before deactivating, use "Restore All Originals" first. There is no forced revert on deactivation.

= My server doesn't have Imagick or libheif. Can I still use this? =
Not for HEIC conversion — Imagick with libheif is required. The Environment Check panel on the admin screen tells you exactly what's missing. Most managed WordPress hosts (WP Engine, Kinsta, SiteGround, Cloudways) include the necessary libraries; contact your host if it's missing.

= Does it handle HEIF as well as HEIC? =
Yes. The plugin processes both `.heic` and `.heif` extensions using the same conversion pipeline.

= What format does it convert to? =
WebP. It is the best balance of compression, quality, and browser support. If you need AVIF output from JPEG/PNG files, see the companion plugin WEBP Support.

= Will this slow down media uploads? =
The conversion adds a brief processing step (typically under a second for phone photos). The batch tool uses AJAX so it never times out the browser, regardless of library size.

= Is there a way to undo everything? =
Yes. "Restore All Originals" on the admin screen restores every previously converted image from backup and returns your library to its pre-plugin state.

== Privacy Policy ==

HEIC Support does not collect, transmit, or store any personal data. All image processing runs locally on your server. No data is sent to external servers.

== Changelog ==

= 1.6190.1030 =
* Changed plugin name from "HEIC Support by thisismyurl.com" to "HEIC Support by Christopher Ross" for consistency across the plugin line.
* Removed GitHub Updater headers (`GitHub Plugin URI`, `Primary Branch`) from the plugin file header in preparation for WordPress.org directory submission.
* Updated Donate link to GitHub Sponsors.
* Bumped version from pre-release (0.x) to full release (1.x).
* Updated Tested up to: 7.0.
* Rewrote readme.txt to WordPress.org submission quality with structured FAQ and Privacy Policy sections.

= 1.6190.1600 =
* **New:** Vortops cloud conversion — when local Imagick + libheif is not available on the server, the plugin can now route HEIC conversions through the Vortops cloud API instead. Local conversion is always preferred; Vortops is a fallback, not a default. Connect a free API key in Settings > HEIC Settings.
* **New:** Honest capability messaging — when the server cannot convert HEIC files locally, the plugin now clearly explains the server-level reason (not a plugin restriction) and offers a path forward (contact host or connect Vortops). Zero pressure framing.
* **New:** Vortops test-connection button — enter an API key and test the connection before saving. The same key works across all thisismyurl plugins.
* **Fix:** Environment panel now correctly flags Vortops as optional so a missing Vortops key never disables the Convert button when local Imagick is working.
* **Fix:** `$env_ok` (which gates the Convert All button) now reflects whether any conversion path is available, not whether every environment check passes.

= 0.6174.1641 =
* Added environment preflight check panel (Imagick, HEIC decoding, backup directory writability).
* Fixed admin settings link URL (tools.php scope).

= 0.6112 =
* Initial release based on the WEBP Support plugin architecture.
* Non-destructive backup logic for HEIC/HEIF files.
* Batch AJAX converter.

== Upgrade Notice ==

= 1.6190.1030 =
First full-release (1.x) version. Plugin name updated to "HEIC Support by Christopher Ross" for consistency with the full plugin line. Removing GitHub Updater headers in preparation for WP.org submission.
