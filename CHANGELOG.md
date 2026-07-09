# Changelog

## 1.6190.1640 — 2026-07-09

### Changed
- **Suite core refactor** — Vortops client, settings UI, and event recording moved to shared `class-timu-suite-core.php`. A single canonical file is synced across all thisismyurl plugins; a change to the core propagates to all of them at once. Same as the Colophon core architecture.
- Vortops Settings postbox now rendered by `TIMU_Suite_Settings::render_vortops_postbox()` — identical UI across all plugins, including inline test-connection script, driven from one place.
- `TIMU_Suite_Event::record()` now called after each conversion to record whether the work was done locally or via Vortops cloud.
- `handle_vortops_save()` now an explicit `admin_post` handler (consistent with the other TIMU plugins) rather than inline POST handling in `render_admin_page()`.

## 1.6190.1600 — 2026-07-09

### Added
- **Vortops cloud conversion** — when local Imagick + libheif is unavailable, HEIC conversions are routed through the Vortops cloud API. Local conversion is always preferred; Vortops is the fallback. API key is entered in Settings and shared across all thisismyurl plugins. Includes a "Test connection" button that tests without saving.
- **Honest capability messaging** — when the server lacks HEIC support, the plugin explains the server-level reason (not a plugin restriction) and offers two paths forward: contact the host or connect Vortops. No pressure, no upsell.
- `TIMU_Vortops_Client` shared client class (`includes/class-timu-vortops-client.php`) — `class_exists` guard so only the first TIMU plugin to load wins; `ping_with_key()`, `convert()`, `sanitize_svg()`, and `get_usage()` all use `wp_remote_*` (never direct curl).
- `local_conversion_available()` static method — single truth for whether Imagick + libheif is available.

### Fixed
- `$env_ok` now reflects whether any conversion path works (local OR cloud), not whether every environment check passes. The Convert button is no longer disabled simply because Vortops isn't connected.
- Environment preflight panel now marks the Vortops row as optional, with an "(optional)" label and a neutral indicator rather than a red fail dot.

## 0.6174.1641 — 2026-06-23

### Added
- Environment preflight panel on the HEIC Support admin page (Tools > HEIC Support). Displays a status table with three checks before the main settings area: Imagick extension loaded, HEIC decoding available via `Imagick::queryFormats()`, and backup directory writable. Each row shows a colored dot indicator (green/red) and a plain-language status string. CSS is enqueued via `admin_enqueue_scripts` scoped to the plugin page only — no inline styles.

## 1.251229 — initial

Initial release of HEIC Support based on the WebP Support architecture. Fixed GitHub updater directory renaming logic. Implemented non-destructive backup logic for HEIC files.
