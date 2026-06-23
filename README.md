# HEIC Support

[![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-blue.svg)](https://wordpress.org/) [![PHP](https://img.shields.io/badge/PHP-7.4%2B-blue.svg)](https://www.php.net/) [![License](https://img.shields.io/badge/License-GPL--2.0--or--later-green.svg)](LICENSE)

Enable HEIC/HEIF uploads in WordPress and convert them to web-safe JPEG or WebP, with backups of every original and one-click restore.

This is a pre-1.0 release. The version number starts with `0.`, which means the format handling and admin surface are stable enough for real use but the API and defaults may still change between builds.

HEIC Support is part of the thisismyurl.com image-plugin family. It shares the same Optimize / Settings / Report admin shell as WebP, BMP, and SVG Support, so once you know one of them, you know all of them. The only difference is the format each plugin handles.

## What it does

- Enables `.heic` and `.heif` uploads, even where your host or another plugin has switched them off, with a real-MIME check so the file is what it claims to be
- Converts each HEIC/HEIF to JPEG (the default) or WebP through the WordPress image editor stack. This needs Imagick. Pure GD cannot decode HEIC on its own, so if you are on GD you also need the `php-imagick` extension installed for decoding to work.
- Keeps every original under `uploads/heic-backups/` with one-click restore, one file at a time or in bulk
- Optimizes on upload, with optional background auto-optimize driven by wp-admin traffic and/or WP-Cron
- Optionally strips EXIF/GPS/metadata and embeds a site-credit XMP block on the output (Imagick required)
- Reports the business ROI of the conversions across 30-day, 90-day, 12-month, and all-time windows
- Takes a safety snapshot before each destructive operation when the shared Vault or Shadow backup engine is active

## Requirements

- WordPress 6.0 or newer
- PHP 7.4 or newer
- Imagick, or GD with the `php-imagick` extension. HEIC decoding requires Imagick either way — GD on its own cannot decode HEIC.

## Installation

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate it through the Plugins screen.
3. Go to **Tools > HEIC Support**, choose your output format, and run optimization.

## Versioning

Versions follow `X.Yjjj.hhmm` — year, Julian day, 24-hour time of the build. The leading `0.` indicates this is a pre-1.0 release.

## About

HEIC Support is built and maintained by [Christopher Ross](https://thisismyurl.com/). I build focused WordPress tools for problems that keep showing up across real sites. No tracking, no ads, no upsells.

**WordPress.org:** [profiles.wordpress.org/thisismyurl](https://profiles.wordpress.org/thisismyurl/) · **GitHub:** [github.com/thisismyurl](https://github.com/thisismyurl) · **LinkedIn:** [linkedin.com/in/thisismyurl](https://linkedin.com/in/thisismyurl)

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
