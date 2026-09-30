# Changelog

All notable changes to this project will be documented in this file.

## 0.2.0 - 2026-09-30

- Added PHP 8.2 support and the matching Laravel 12/13 CI matrix.
- Removed PHP 8.3-only typed constants and the `#[Override]` attribute from the supported code paths.
- Tightened HTML, JPEG, PNG, WebP, streamed UTF-8, and timeout configuration validation.

## 0.1.0 - 2026-09-10

- Initial package implementation.
- `storeAs()` now preserves a user-provided filename's existing suffix before appending the output format extension.
- Updated GitHub Actions checkout to the Node.js 24 runtime.
