# Laravel Office Converter

[繁體中文](README.zh-TW.md)

Laravel Office Converter is a Laravel document conversion package powered by LibreOffice. Convert supported
documents, spreadsheets, presentations, drawings, and images, then retrieve the result or save it with
Laravel Storage.

## Features

- Convert 23 supported document, spreadsheet, presentation, drawing, and image formats.
- Create PDF files or convert to other commonly used formats supported by each input.
- Start from a local file, raw content, or a Laravel upload.
- Retrieve the converted file or save it directly to any Laravel Storage disk.

## Requirements

| Requirement | Supported versions or setup |
| --- | --- |
| PHP | 8.3 or later in the PHP 8.x series, with DOM and ZIP |
| Laravel | 12 or 13 |
| Operating system | Windows, Linux, or macOS |
| External command | LibreOffice installed on `PATH` or configured with `LIBREOFFICE_BINARY` |

## Install LibreOffice

Use your operating system's package manager or the prebuilt installer from the
[LibreOffice download page](https://www.libreoffice.org/download/). The
[official installation instructions](https://www.libreoffice.org/installation-instructions/) cover macOS,
Linux, and Windows in more detail. You do not need to compile LibreOffice from source.

### macOS

Install the [Homebrew LibreOffice cask](https://formulae.brew.sh/cask/libreoffice):

```bash
brew install --cask libreoffice
```

Without Homebrew, download the Apple Silicon or Intel `.dmg` from the LibreOffice website and move
LibreOffice to Applications as described in the official instructions.

### Ubuntu and Debian

Install the distribution package:

```bash
sudo apt update
sudo apt install libreoffice
```

Ubuntu lists LibreOffice in its [official package index](https://packages.ubuntu.com/search?keywords=libreoffice),
and Debian provides it through its normal package repositories. You can also choose the prebuilt `.deb`
packages from the LibreOffice download page.

### RHEL and Fedora

Install from an enabled distribution repository when available:

```bash
sudo dnf install libreoffice
```

Fedora publishes LibreOffice in its [official package index](https://packages.fedoraproject.org/pkgs/libreoffice/libreoffice/).
RHEL repository availability depends on the release and enabled subscriptions; use LibreOffice's prebuilt
`.rpm` download and official installation instructions when the package is unavailable.

### Windows

Download the Windows installer from the LibreOffice website and complete its installation wizard. The usual
console executable location is:

```text
C:\Program Files\LibreOffice\program\soffice.com
```

If it is not on `PATH`, set `LIBREOFFICE_BINARY` to that path in your Laravel environment.

The package does not require or inspect a numeric LibreOffice version. Compatibility is determined by whether
the installed command and filters complete the requested conversion.

## Installation

Install the package with Composer:

```bash
composer require mattmy/laravel-office-converter
```

The default command is `soffice.com` on Windows and `soffice` elsewhere. Publish the config when LibreOffice
is elsewhere or you need different limits:

```bash
php artisan vendor:publish --tag=office-converter-config
```

```dotenv
LIBREOFFICE_BINARY=/usr/bin/soffice
```

The package executes the configured command and handles conversion output, validation, and storage. It does
not configure or require a sandbox, LibreOffice profile policy, macro policy, or process supervisor; the
execution environment is the application's responsibility.

## Quick start

Convert a valid Laravel upload to PDF and stream it to the default Storage disk:

```php
use Mattmy\OfficeConverter\Enums\Format;
use Mattmy\OfficeConverter\Facades\Office;

$path = Office::fromUploadedFile($request->file('document'))
    ->convertTo(Format::PDF)
    ->storeAs('converted-documents', 'report.pdf');
```

`$path` is the stored path or `false` when the selected Storage driver reports failure.

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

See the [complete input-to-output list](https://mattmy.github.io/laravel-office-converter-doc/guide/supported-conversions)
for every `InputFormat`, canonical extension, and format-specific limit.

## One-time output

```php
$bytes = $document->convertTo(Format::PDF)->output();

$stored = $document
    ->convertTo(Format::DOCX)
    ->storeAs('exports', 'report.xlsx', 's3');
```

`output()` loads the complete artifact into PHP memory. `storeAs()` streams to Laravel Storage and preserves
the selected driver's overwrite, `string|false`, and exception behavior. It keeps the supplied filename text
and appends the trusted target extension, so the second example stores `exports/report.xlsx.docx`.

An `OfficeDocument` allows one conversion attempt, and a `ConvertedOffice` allows one call to `output()` or
`storeAs()`. Success and failure both consume the object and clean its temporary conversion files.

## Errors and operational limits

Converter failures implement `OfficeConverterException`: `EnvironmentUnavailable`, `InvalidOfficeInput`,
`UnsupportedConversion`, `ConversionFailed`, and `AlreadyConsumed`. Invalid Storage destinations use
`InvalidArgumentException`; Storage and Flysystem exceptions pass through unchanged.

Defaults are 100 MiB input, 200 MiB completed output, and a 60-second timeout for the directly managed process.
The package does not guarantee complete process-tree termination. Successful conversion is not a losslessness,
OCR, or visual-fidelity guarantee.

## Documentation

- [Complete documentation](https://mattmy.github.io/laravel-office-converter-doc/)
- [Changelog](CHANGELOG.md)
- [Security policy](SECURITY.md)

## License

The MIT License. See [LICENSE](LICENSE). LibreOffice is installed separately under its own license.
