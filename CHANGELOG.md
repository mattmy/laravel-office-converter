# Changelog

All notable changes to this project will be documented in this file.

## 0.2.1 - 2026-10-21

- Prevent cloning documents and conversion results to preserve exclusive ownership of temporary files.
- Retry failed conversion cleanup when the document is destroyed and close source streams when snapshot creation fails.
- Fix workspace containment checks when the temporary directory is a filesystem root.
- Map filesystem warnings to the appropriate package exceptions and reject NUL bytes in executable and temporary directory settings.
- Reject empty or invalid UTF-8 OOXML entries with consistent exceptions, reuse XML validation and format mappings, and remove redundant helpers.

## 0.2.0 - 2026-09-30

- Added PHP 8.2 support and the matching Laravel 12/13 CI matrix.
- Removed PHP 8.3-only typed constants and the `#[Override]` attribute from the supported code paths.
- Tightened HTML, JPEG, PNG, WebP, streamed UTF-8, and timeout configuration validation.

## 0.1.0 - 2026-09-10

- Initial package implementation.
- `storeAs()` now preserves a user-provided filename's existing suffix before appending the output format extension.
- Updated GitHub Actions checkout to the Node.js 24 runtime.
