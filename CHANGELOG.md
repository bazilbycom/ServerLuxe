# Changelog

All notable changes to the **ServerLuxe** project will be documented in this file.

---

## [1.2.9] - 2026-06-18
### Added
- Richer MCP documentation explaining tool capabilities and argument details for both `db.php` and `fm.php`.

### Fixed
- **JSON Serialization Bug**: Resolved a critical bug where empty databases and folders lists were treated as JavaScript arrays (`[]`) instead of plain objects (`{}`), causing the browser's `JSON.stringify` to strip all string-keyed permission configurations on save.

---

## [1.2.8] - 2026-06-18
### Fixed
- **Permission Self-Healing**: Implemented automatic creation and `chmod 0666` for `mcp_config.json` in both `db.php` and `fm.php` to handle write permission constraints gracefully on remote environments.
- **UI Error Feedback**: Modified the `save_mcp_config` route to return detailed PHP file-write error messages rather than a silent success response.

---

## [1.2.7] - 2026-06-18
### Added
- GitHub repository navigation link to the header of all admin panels.

---

## [1.2.6] - 2026-06-18
### Fixed
- Folder MCP configuration reactivity state updates in `fm.php`.
- Checkbox model binding synchronization issues for table rows in `db.php`.

---

## [1.2.5] - 2026-06-18
### Changed
- Separated MCP database configurations and file manager configurations into distinct scopes.
- Cleaned up local testing scratchpads.

---

## [1.2.4] - 2026-06-18
### Changed
- Retrieved the application version string directly from the PHP constants instead of parsing `.env` files for improved build consistency.

---

## [1.2.3] - 2026-06-18
### Fixed
- Bypassed GitHub Raw CDN caching during automatic update checks by appending dynamic timestamps to HTTP requests.

---

## [1.2.2] - 2026-06-18
### Changed
- Replaced the default WebP logo assets with high-definition transparent `logo-dark.png` graphics.
