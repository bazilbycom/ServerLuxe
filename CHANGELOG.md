# Changelog

All notable changes to the **ServerLuxe** project will be documented in this file.

---

## [1.5.0] - 2026-11-23
### Added
- **Web & API Terminal Access**:
  - Full interactive terminal emulator embedded in FileLuxe (`fm.php`) Web interface and mobile/web client (`fm.html`).
  - Terminal features include streaming log output, exit code detection, command history navigation (Up/Down arrow keys), quick actions (`pwd`, `ls -la`, `whoami`, `git status`, `uptime`, `php -v`), clear command, and live process execution status.
  - Automatic directory tracking and relative `cd` support across HTTP requests.
  - Direct shell tool `execute_command` (aliases: `terminal`, `run_command`) added to Model Context Protocol (MCP) `tools/list` schema and REST API (`?action=api&tool=execute_command&command=...`).
  - Non-blocking execution using `proc_open` with stdout/stderr pipe capturing and configurable timeout safeguards (default 30s, max 120s).
- **Web Terminal Enable / Disable Control**:
  - Dedicated **Terminal Access** tab in the **MCP & Auto-Update Panel** (`fm.php`) allowing administrators to instantly toggle terminal availability with one click.
  - Safe default: disabled out-of-the-box until enabled in the Web UI or via `ENABLE_TERMINAL=true` in `.env`.
  - Stored persistently in `server/mcp_config.json` (`terminal.enabled`).
  - Status indicators and enable/disable button directly within the Terminal modal header.

### Fixed
- **API Authentication & CSRF Validation in FileLuxe (`fm.php`)**: Separated stateless API key authentication (`is_api_key_request()`) from session checks. Stateless API requests authenticated via `X-API-KEY` header, `$_POST['api_key']`, `$_GET['api_key']`, or `Authorization: Bearer` now reliably bypass CSRF validation without returning "CSRF validation failed: session not authenticated". Added `getallheaders()` case-insensitive checks for `X-API-KEY` and `X-CSRF-TOKEN` to prevent failures behind reverse proxies and Web Application Firewalls.
- **CSRF Token Validation Return Value**: Ensured `validate_csrf_token()` returns boolean `true` on successful validation.
- **Enhanced Directory Path Validation (`validate_path`)**: Fixed path validation when creating directories: `validate_path()` now recursively inspects existing ancestor directories so multi-level folder creation doesn't fail with "Invalid path", and fixed root path normalization when `$base_dir` is `/`.
- **UI & App Folder Creation Sanitization**: Sanitized folder and file names by trimming whitespace and leading/trailing slashes in `fm.php` and mobile/web app (`fm.html`). In `fm.php`'s UI `apiReq`, ensure POST requests target `window.location.pathname` so stale query parameters (e.g. `?action=...`) from previous operations are not preserved.
- **File Manager API `create_folder` Tool**: Implemented `create_folder` (with `mkdir` alias) in `execute_fm_tool()` and registered it in MCP `tools/list` schema so AI agents (MCP) and REST API callers can create directories with appropriate folder permission enforcement and clear error reporting.

## [1.4.3] - 2026-09-12
### Fixed
- **CSRF Token Validation Fix in FileLuxe (`fm.php`)**: Fixed issue where `validate_csrf_token()` was called with `if (!validate_csrf_token())` in AJAX file actions handler; since `validate_csrf_token()` returns `void` on success and exits on failure, the negation condition was always truthy and blocked file operations (delete, rename, etc.) with "Invalid CSRF token".
- **WAF 403 False-Positive Fix**: Completely eliminated the unencoded `config` JSON parameter from `save_mcp_config` POST requests so host Web Application Firewalls (ModSecurity / LiteSpeed / cPanel) see only clean Base64 data (`config_b64`) and no longer block permission saves with 403 Forbidden.

---

## [1.4.2] - 2026-08-08
### Fixed
- **WAF 403 Forbidden Bypass**: Implemented Base64 payload encoding (`config_b64`) for `save_mcp_config` requests to prevent Web Application Firewalls (ModSecurity / cPanel WAF) from false-flagging JSON permission structures.
- **Web Server Response Interception Fix**: Removed HTTP 403 status code overrides from authentication & CSRF validation responses so LiteSpeed, Apache, and Nginx servers do not replace JSON error output with HTML error pages.
- **Global Update Checking**: Unlocked `?action=check_updates` and added cURL / SSL verify fallbacks to ensure version checking succeeds across all server configurations.

---

## [1.4.1] - 2026-08-08
### Fixed
- **MCP Config Saving**: Resolved issue where configuration save requests threw error alerts by suppressing file write warnings and returning clean JSON responses without raw HTTP 500 header breaks.
- **SQL Import Overhaul**: Fixed missing CSRF token in SQL import requests and replaced basic string splitting with a robust character-by-character SQL parser (`split_sql_queries()`) supporting comments with semicolons, string literals, GZIP decompression, and detailed query failure diagnostics.

### Added
- **Arabic Text & Bi-Directional (BIDI) Support**: Enforced UTF-8 internal encoding, `SET NAMES utf8mb4` on MySQL connection init, system Arabic font family fallbacks, and CSS `unicode-bidi: plaintext;` for automatic RTL/LTR rendering across inputs, textareas, and data grids in `db.php` and `fm.php`.

---

## [1.3.5] - 2026-07-16
### Added
- **MCP Docs Link**: Added a "Full MCP Docs" link pointing to `mcp_docs.md` in the AI Client Config help text.

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
