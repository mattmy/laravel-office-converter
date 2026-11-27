# Changelog

All notable changes to this project will be documented in this file.

## 0.2.5 - 2026-11-27

- Classify filesystem warnings while inspecting uploads as invalid office input.
- Report LibreOffice startup failures as environment errors across supported Symfony Process versions.
- Accept Windows absolute paths with repeated forward slashes after the drive letter.
- Reuse snapshot cleanup and bounded PDF and JPEG trailer reads, and simplify input format mapping.
- Clarify installation and conversion usage in the English and Traditional Chinese READMEs.

## 0.2.4 - 2026-11-24

- Discard LibreOffice stdout and stderr and return fixed diagnostics for process failures.
- Verify workspace ownership before reading artifacts or removing directories, including on Windows.
- Handle concurrent temporary-directory creation, filesystem warnings, and relative PATH entries consistently.
- Tighten ZIP, SVG, and WebP validation and reuse canonical output extensions from the format enum.
- Keep HTML visual samples in the source checkout and exclude them from Composer release archives and Git exports.
- Verify both archive layouts in CI and correct the one-time document examples in both READMEs.

## 0.2.3 - 2026-11-11

- Validate HTML and SVG incrementally using configured byte limits instead of a separate 4 MiB whole-file cap.
- Require the PHP XML extension for streaming SVG validation; retain bounded OOXML entry validation.

## 0.2.2 - 2026-11-11

- Close the Storage stream before workspace cleanup even when a disk throws and retains the stream.
- Qualify native PHP constants consistently with the package code-style authority.

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
