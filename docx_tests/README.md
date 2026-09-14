# HTML conversion samples

This directory contains standalone HTML documents for manually testing and comparing HTML-to-PDF and
HTML-to-DOCX conversion. The numbered files cover document structure, tables, pagination, lists, links,
fonts, special characters, RTL text, images, alignment, long content, semantic elements, borders, and
background colors.

From a Laravel application, convert any sample with the package's regular API:

```php
use Mattmy\OfficeConverter\Enums\Format;
use Mattmy\OfficeConverter\Facades\Office;

$source = base_path('vendor/mattmy/laravel-office-converter/docx_tests/01_document_basic.html');

Office::fromPath($source)
    ->convertTo(Format::PDF)
    ->storeAs('converter-tests', '01_document_basic.pdf');
```

Change `Format::PDF` to `Format::DOCX` to inspect the Word output. When working from a source checkout,
replace `$source` with the absolute path to the selected file.

These are visual reference samples, not golden-output assertions. Results may differ across LibreOffice
versions, operating systems, and installed fonts. Unless noted otherwise, the samples are covered by the
repository's MIT License.
