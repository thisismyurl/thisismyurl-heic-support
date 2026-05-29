=== HEIC Support by thisismyurl.com ===
Contributors: thisismyurl
Author: thisismyurl
Author URI: https://thisismyurl.com/
Donate link: https://thisismyurl.com/donate/
Tags: heic, heif, webp, ios images, optimization
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.6149.0734
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
GitHub Plugin URI: https://github.com/thisismyurl/thisismyurl-heic-support/
Primary Branch: main

A free, non-destructive HEIC/HEIF to WebP converter for WordPress: auto-convert new Apple-device uploads, bulk-convert your existing library, and restore originals at any time.

== Description ==

HEIC Support by thisismyurl.com converts HEIC/HEIF images — the format modern iPhones and iPads capture — into WebP, which every browser can display. Conversion uses Imagick with libheif on the server; no external services and no phone-home. Each original is moved to a backup directory under `uploads/heic-backups/` and can be restored at any time, individually or in bulk.

**On activation, automatic conversion of new HEIC/HEIF uploads to WebP is turned on by default** (on a host with Imagick + libheif). This is reversible: switch it off under Tools > HEIC Support > Settings, and existing files are untouched until you choose to convert them.

What this plugin actually ships:

* Tools > HEIC Support page with a conversion dashboard, settings, a Pending table, and a Managed Media table.
* Auto-convert on upload: new HEIC/HEIF uploads are converted to WebP the moment they land in the Media Library (toggle in settings).
* Non-destructive bulk conversion with progress bar, savings display, and cancel.
* Quality Preset setting: Web (82), Print (95), Lossless (100), or Custom.
* Savings display: a dashboard line showing total bytes saved, a Size column in the Pending table, and a Saved column in the Managed Media table.
* Single Restore button per managed image and a Restore All Originals bulk action.
* Status flag for managed files that have gone missing on disk.
* Per-attachment lock so two operators or two browser tabs cannot race the same file.
* Attachment metadata regenerated after each conversion or restoration.
* Optional backup-folder cleanup on uninstall.
* WP-CLI commands: `wp heic convert`, `wp heic restore`, `wp heic status`.
* WordPress 7.0 Abilities API: `thisismyurl-heic-support/convert` and `thisismyurl-heic-support/restore`.
* Localization support: French (Canada) translation included.

What this plugin does NOT do:

* No background WP-Cron scheduler (conversion happens on upload or when you run the batch/CLI/ability).
* No EXIF / GPS / metadata stripping.
* No outbound tracking, ads, or UTM tagging.
* No GD fallback: HEIC decoding requires Imagick built with libheif. On a host without it, uploads are left as HEIC and conversion is disabled gracefully — uploads never fail.

How it works:

1. Go to Tools > HEIC Support.
2. Choose a quality preset and batch size, and decide whether new uploads convert automatically.
3. New HEIC/HEIF uploads convert to WebP on the way in (if auto-convert is on).
4. Click "Convert All" to process HEIC/HEIF images already in your library, in AJAX batches.
5. Use Restore on individual rows, or "Restore All Originals" for a bulk rollback.

Notes:

* Requires Imagick with HEIC/HEIF support (libheif). The dashboard shows a warning when it is unavailable.
* Backup paths are stored relative to the uploads directory so dev↔prod database copies survive migration.
* WebP attachments the plugin did not create are left untouched.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins screen. Note: activation turns on automatic HEIC/HEIF-to-WebP conversion of new uploads by default — switch it off under Tools > HEIC Support > Settings if you prefer to convert manually.
3. Go to Tools > HEIC Support.
4. Configure and run conversion.

== Frequently Asked Questions ==

= Does this delete my original images? =
No. Originals are moved to `uploads/heic-backups/` and can be restored individually or with Restore All Originals.

= Will my images break if I delete the plugin? =
Use Restore All Originals in the dashboard before uninstalling to revert WebP files back to your original HEIC/HEIF images. By default, uninstall also removes the backup folder — turn that off in settings if you want the backups kept.

= Does this support HEIF as well as HEIC? =
Yes. Both `.heic` and `.heif` files are handled.

= Does this require Imagick? =
Yes. HEIC/HEIF decoding requires Imagick built with libheif. Without it, uploads are left as HEIC and the dashboard shows a warning; nothing fails.

= Is there a WP-CLI interface? =
Yes. `wp heic convert <id|--all>`, `wp heic restore <id|--all>`, `wp heic status`.

== Languages ==

* French (Canada) — Christopher Ross

== Changelog ==

= 0.6150 =
* Disclosure: the readme Description and Installation now state plainly that activation turns on automatic HEIC/HEIF-to-WebP conversion of new uploads by default (on a libheif-capable host), and that it is reversible from Tools > HEIC Support > Settings. No behaviour change.

= 0.6149 =
* Security: replaced the bundled GitHub updater with the hardened release updater shared with WebP Support — the `after_install` relocation now only fires for this plugin's own update, and the GitHub API request carries a timeout, a User-Agent header, a 200-response check, and a 6-hour cache.
* Accessibility: the bulk-convert progress bar now exposes `role="progressbar"` with live `aria-valuenow`, paired with a polite `role="status"` region announcing conversion progress.
* Accessibility: the "File Missing" status carries a non-colour cue, and the Quality Preset radios are grouped in a `fieldset`/`legend`.

= 0.6148 =
* Rebuilt the conversion engine on the WebP Support architecture: a single Imagick decode/encode routine now serves the upload prefilter, the batch library walk, the WP-CLI commands, and the abilities.
* Added auto-convert on upload: new HEIC/HEIF uploads are converted to WebP, with the original archived and restore parity preserved (toggle in settings).
* Added the Tools > HEIC Support dashboard: Pending and Managed Media tables, total-savings display, Convert All with progress and cancel, single Restore, and Restore All Originals.
* Added per-attachment locking, relative backup paths for migration safety, and a direct-PHP filesystem fallback on hosts that prompt for FTP credentials.
* Added WP-CLI commands `wp heic convert`, `wp heic restore`, `wp heic status`.
* Added the `thisismyurl-heic-support/restore` ability and aligned `thisismyurl-heic-support/convert` to report bytes saved and per-failure messages.
* Tracked bytes saved per attachment in `_heic_savings`; uninstall now honours the keep/delete-backups setting and cleans plugin options.

= 0.6147 =
* Unified plugin versioning to the x.Yddd calendar-version scheme.
* Confirmed compatibility with WordPress 7.0.

= 1.251229 =
* Initial release of HEIC Support based on the WebP Support architecture.
* Implemented non-destructive backup logic for HEIC files.

== Upgrade Notice ==

= 0.6148 =
Engine rebuild on the WebP Support architecture. Adds auto-convert on upload, a full conversion dashboard with savings and Restore All, WP-CLI commands, and a restore ability. Requires Imagick with libheif; degrades gracefully without it.
