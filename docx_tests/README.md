# HTML conversion samples

This directory contains standalone HTML documents for manually testing and comparing HTML-to-PDF and
HTML-to-DOCX conversion. The numbered files cover document structure, tables, pagination, lists, links,
fonts, special characters, RTL text, images, alignment, long content, semantic elements, borders, and
background colors.

Clone the source repository to get these samples. Composer release archives and Git exports omit this directory.
From a Laravel application, pass the absolute path to a sample in the checkout:

```php
use Mattmy\OfficeConverter\Enums\Format;
use Mattmy\OfficeConverter\Facades\Office;

$source = '/absolute/path/to/laravel-office-converter/docx_tests/01_document_basic.html';

Office::fromPath($source)
    ->convertTo(Format::PDF)
    ->storeAs('converter-tests', '01_document_basic.pdf');
```

To inspect Word output, use `Format::DOCX` and save the result as `01_document_basic.docx`.

These are visual reference samples, not golden-output assertions. Results may differ across LibreOffice
versions, operating systems, and installed fonts. Unless noted otherwise, the samples are covered by the
repository's MIT License.
