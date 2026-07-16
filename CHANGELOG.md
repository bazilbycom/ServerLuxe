# Changelog

All notable changes to the **ServerLuxe** project will be documented in this file.

---

## [1.3.4] - 2026-07-16
### Fixed
- **Database dropdown layout**: The sidebar database dropdown was displaying options horizontally due to inheriting the `.modal-card` grid layout on desktop.

## [1.3.3] - 2026-07-16
### Fixed
- **Broken MCP modal tag**: Restored the closing `>` on the modal card `<div>` so Alpine directives (`.outside`, `x-data`) are properly parsed instead of rendering as visible text.

## [1.3.2] - 2026-07-16
### Changed
- **MCP & Auto-Update Modal UI**: The MCP panel now renders single-column on desktop (no more empty action-footer column); removed the fixed 80vh height so the panel scrolls naturally.
- **One-Click Copy MCP JSON**: Added a "Copy JSON" button to the AI Client Config block in the MCP panel so the `mcp_config` snippet can be copied in one click (with clipboard fallback and haptic feedback).

## [1.3.1] - 2026-07-16
### Added
- **Daily Master-Password Gate**: The `fm.php` and `db.php` web UIs now require the master password at least once per calendar day. Web browsers are no longer auto-authenticated; API-key / MCP access is unaffected.
- **Self-Documenting MCP Bridge**: `mcp-bridge.js` now ships with a full protocol/usage doc header and a `--help` flag so AI agents and operators can understand the stdio<->HTTPS contract.
- **New-File MCP Write Support**: `fm.php` folder ACLs now permit creating new files inside allowed folders (previously only existing files could be written).

### fixed
- Resolved MCP write failures for not-yet-existing files under permitted directories.

---

## [1.3.0] - 2026-06-19
### Changed
- **Horizontal Modals on Desktop**: On viewports ≥700px, all modals in `db.php` and `fm.php` now display in a two-column layout — the body content fills the left side and the action buttons appear in a right-side panel separated by a border, giving a modern side-by-side UX instead of a stacked footer. Mobile layouts (≤600px) revert cleanly to the original vertical stack.
- Small modals (QR code connector) are explicitly kept in the vertical layout on all screen sizes for better visual balance.

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
