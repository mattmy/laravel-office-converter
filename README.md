# Laravel Office Converter

[繁體中文](README.zh-TW.md)

Convert documents, spreadsheets, presentations, drawings, and images in Laravel with LibreOffice.

## Features

- Convert any of the 23 supported input formats to PDF, or choose another output supported by its document family.
- Start from a local file, raw bytes, or a Laravel upload.
- Read the result as bytes or stream it to a Laravel Storage disk.

## Requirements

| Requirement | Supported versions or setup |
| --- | --- |
| PHP | 8.2 or later in the PHP 8.x series, with DOM, XML, and ZIP |
| Laravel | 12 (PHP 8.2+) or 13 (PHP 8.3+) |
| Operating system | Windows, Linux, or macOS |
| LibreOffice | Install separately and make the executable available on `PATH` or set `LIBREOFFICE_BINARY` |

Compatibility depends on whether your LibreOffice installation can complete the requested conversion.
There is no minimum version check.

## Install LibreOffice

Use your operating system's package manager or the prebuilt installer from the
[LibreOffice download page](https://www.libreoffice.org/download/). The
[official installation instructions](https://www.libreoffice.org/installation-instructions/) cover macOS,
Linux, and Windows.

### macOS

Install the [Homebrew LibreOffice cask](https://formulae.brew.sh/cask/libreoffice):

```bash
brew install --cask libreoffice
```

Without Homebrew, download the Apple Silicon or Intel `.dmg` from the LibreOffice website and move
LibreOffice to Applications. If `soffice` is outside your application's `PATH`, set `LIBREOFFICE_BINARY` to
`/Applications/LibreOffice.app/Contents/MacOS/soffice` for an installation in Applications.

### Ubuntu and Debian

Install the distribution package:

```bash
sudo apt update
sudo apt install libreoffice
```

### RHEL and Fedora

Install from an enabled distribution repository when available:

```bash
sudo dnf install libreoffice
```

If your RHEL release and enabled repositories do not provide the package, use the `.rpm` packages from LibreOffice.

### Windows

Download the Windows installer from the LibreOffice website and complete its installation wizard. The usual
console executable location is:

```text
C:\Program Files\LibreOffice\program\soffice.com
```

If it is outside `PATH`, set `LIBREOFFICE_BINARY` in `.env` to that path in single quotes.

## Installation

Install the package with Composer:

```bash
composer require mattmy/laravel-office-converter
```

The package looks for `soffice.com` on Windows and `soffice` elsewhere. If the executable is outside `PATH`,
set its path in `.env`.

```dotenv
LIBREOFFICE_BINARY=/usr/bin/soffice
```

Publish the config to change the timeout, size limits, or temporary directory.

```bash
php artisan vendor:publish --tag=office-converter-config
```

If you use Laravel `config:cache`, rebuild the cache after changing the config or `LIBREOFFICE_BINARY`.

## Quick start

Convert a valid Laravel upload to PDF and stream it to the default Storage disk:

```php
use Mattmy\OfficeConverter\Enums\Format;
use Mattmy\OfficeConverter\Facades\Office;

$path = Office::fromUploadedFile($request->file('document'))
    ->convertTo(Format::PDF)
    ->storeAs('converted-documents', 'report.pdf');
```

On success, `$path` is `converted-documents/report.pdf`. If the selected disk returns `false`, `storeAs()`
returns `false` too.

## Inputs and conversions

```php
use Mattmy\OfficeConverter\Enums\InputFormat;

$document = Office::fromPath('/absolute/path/report.docx');
$document = Office::fromContent($bytes, InputFormat::DOCX);
$document = Office::fromUploadedFile($uploadedFile);
```

| Input family | Accepted inputs | Available outputs |
| --- | --- | --- |
| Writer | ODT, DOC, DOCX, DOCM, RTF, TXT, HTML | PDF, ODT, DOCX, RTF, TXT, HTML |
| Calc | ODS, XLS, XLSX, XLSM, CSV | PDF, ODS, XLSX, CSV |
| Impress | ODP, PPT, PPTX, PPTM | PDF, ODP, PPTX |
| Draw | ODG, PDF, DXF, SVG, PNG, JPEG, WebP | PDF, ODG, PNG, JPEG, SVG, WebP |

Choose an output from the same row as the input. Other combinations throw `UnsupportedConversion` before
LibreOffice starts. [Supported conversions](https://mattmy.github.io/laravel-office-converter-doc/guide/supported-conversions)

## One-time output

```php
$bytes = $document->convertTo(Format::PDF)->output();

$stored = Office::fromPath('/absolute/path/report.docx')
    ->convertTo(Format::DOCX)
    ->storeAs('exports', 'report.xlsx', 's3');
```

`output()` loads the entire file into PHP memory. `storeAs()` streams to Laravel Storage and uses the
selected disk's overwrite behavior. The target format sets the final extension, so `report.xlsx` is saved
as `exports/report.xlsx.docx` in the second example.

Each `OfficeDocument` allows one conversion attempt, even if it fails. Call either `output()` or `storeAs()`
once per `ConvertedOffice`. Both clean up temporary files even if the call fails.

## Errors and operational limits

Catch `OfficeConverterException` for package errors, including `EnvironmentUnavailable`, `InvalidOfficeInput`,
`UnsupportedConversion`, `ConversionFailed`, and `AlreadyConsumed`. An invalid Storage path or filename
throws `InvalidArgumentException`. Storage and Flysystem exceptions pass through unchanged.

By default, the input limit is 100 MiB, the completed output limit is 200 MiB, and the timeout is 60 seconds.
The timeout covers the process the package directly manages. Your application manages the execution environment,
including sandboxing, LibreOffice profiles, macro policies, and child processes.

Layout and formatting can vary after conversion. PDF conversion does not perform OCR.

## Documentation

- [Complete documentation](https://mattmy.github.io/laravel-office-converter-doc/)
- Get the samples from the source checkout. Composer packages and Git archives exclude them. [HTML conversion samples](https://github.com/mattmy/laravel-office-converter/tree/main/docx_tests)
- [Changelog](CHANGELOG.md)
- [Security policy](SECURITY.md)

## License

Laravel Office Converter is released under the MIT License. See [LICENSE](LICENSE). LibreOffice is installed
separately under its own license.
