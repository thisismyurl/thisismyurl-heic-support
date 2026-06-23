# Changelog

## 0.6174.1641 — 2026-06-23

### Added
- Environment preflight panel on the HEIC Support admin page (Tools > HEIC Support). Displays a status table with three checks before the main settings area: Imagick extension loaded, HEIC decoding available via `Imagick::queryFormats()`, and backup directory writable. Each row shows a colored dot indicator (green/red) and a plain-language status string. CSS is enqueued via `admin_enqueue_scripts` scoped to the plugin page only — no inline styles.

## 1.251229 — initial

Initial release of HEIC Support based on the WebP Support architecture. Fixed GitHub updater directory renaming logic. Implemented non-destructive backup logic for HEIC files.
