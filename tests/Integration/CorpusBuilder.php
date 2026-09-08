<?php

declare(strict_types=1);

namespace Mattmy\OfficeConverter\Tests\Integration;

use Mattmy\OfficeConverter\Enums\InputFormat;
use RuntimeException;
use Symfony\Component\Process\Process;
use ZipArchive;

/**
 * Builds an original MIT-licensed interoperability corpus for one test run.
 */
final class CorpusBuilder
{
    /**
     * Create every public input format from four minimal family documents.
     *
     * @return array<string, string>
     */
    public static function create(string $directory, string $binary): array
    {
        if (! \mkdir($directory, 0700, true) && ! \is_dir($directory)) {
            throw new RuntimeException('Unable to create the integration corpus directory.');
        }

        $writer = $directory . DIRECTORY_SEPARATOR . 'writer.odt';
        $calc = $directory . DIRECTORY_SEPARATOR . 'calc.ods';
        $presentation = $directory . DIRECTORY_SEPARATOR . 'presentation.odp';
        $drawing = $directory . DIRECTORY_SEPARATOR . 'drawing.odg';
        self::odf($writer, 'application/vnd.oasis.opendocument.text', '<office:text><text:h text:outline-level="1">轉換測試</text:h><text:p>繁體中文 Writer 文件。</text:p><table:table table:name="Table1"><table:table-row><table:table-cell office:value-type="string"><text:p>資料</text:p></table:table-cell></table:table-row></table:table></office:text>');
        self::odf($calc, 'application/vnd.oasis.opendocument.spreadsheet', '<office:spreadsheet><table:table table:name="工作表一"><table:table-row><table:table-cell office:value-type="string"><text:p>名稱</text:p></table:table-cell><table:table-cell office:value-type="float" office:value="42"><text:p>42</text:p></table:table-cell></table:table-row></table:table></office:spreadsheet>');
        self::odf($presentation, 'application/vnd.oasis.opendocument.presentation', '<office:presentation><draw:page draw:name="page1"><draw:frame svg:x="1cm" svg:y="1cm" svg:width="20cm" svg:height="3cm"><draw:text-box><text:p>第一張投影片－繁體中文</text:p></draw:text-box></draw:frame></draw:page><draw:page draw:name="page2"><draw:frame svg:x="1cm" svg:y="1cm" svg:width="20cm" svg:height="3cm"><draw:text-box><text:p>第二張投影片</text:p></draw:text-box></draw:frame></draw:page></office:presentation>');
        self::odf($drawing, 'application/vnd.oasis.opendocument.graphics', '<office:drawing><draw:page draw:name="page1"><draw:rect svg:x="1cm" svg:y="1cm" svg:width="8cm" svg:height="5cm"/><draw:frame svg:x="2cm" svg:y="2cm" svg:width="6cm" svg:height="2cm"><draw:text-box><text:p>第一頁－藍色圖形</text:p></draw:text-box></draw:frame></draw:page><draw:page draw:name="page2"><draw:ellipse svg:x="1cm" svg:y="1cm" svg:width="8cm" svg:height="5cm"/><draw:frame svg:x="2cm" svg:y="2cm" svg:width="6cm" svg:height="2cm"><draw:text-box><text:p>第二頁－圓形</text:p></draw:text-box></draw:frame></draw:page></office:drawing>');
        $writerWithImageHtml = $directory . DIRECTORY_SEPARATOR . 'writer-with-image.html';
        self::write($writerWithImageHtml, '<!doctype html><html lang="zh-Hant-TW"><body><p>含圖片的 HTML sidecar 測試。</p><img alt="pixel" src="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScLHeAAAAABJRU5ErkJggg=="></body></html>');

        self::write($directory . DIRECTORY_SEPARATOR . 'writer.txt', '繁體中文 Writer 純文字。');
        self::write($directory . DIRECTORY_SEPARATOR . 'writer.html', '<!doctype html><html lang="zh-Hant-TW"><body><h1>轉換測試</h1><table><tr><td>繁體中文</td></tr></table></body></html>');
        self::write($directory . DIRECTORY_SEPARATOR . 'calc.csv', "名稱,值\n繁體中文,42\n");
        self::write($directory . DIRECTORY_SEPARATOR . 'drawing.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="320" height="200"><rect width="160" height="200" fill="blue"/><circle cx="240" cy="100" r="70" fill="red"/><text x="20" y="100">繁體中文</text></svg>');
        self::write($directory . DIRECTORY_SEPARATOR . 'drawing.dxf', "0\nSECTION\n2\nHEADER\n9\n\$ACADVER\n1\nAC1009\n0\nENDSEC\n0\nSECTION\n2\nENTITIES\n0\nLINE\n8\n0\n10\n0.0\n20\n0.0\n30\n0.0\n11\n100.0\n21\n100.0\n31\n0.0\n0\nENDSEC\n0\nEOF\n");
        self::pdf($directory . DIRECTORY_SEPARATOR . 'multi-page.pdf');

        foreach ([
            [$writer, 'doc', 'MS Word 97'],
            [$writer, 'docx', 'Office Open XML Text'],
            [$writer, 'docm', 'MS Word 2007 XML VBA'],
            [$writer, 'rtf', 'Rich Text Format'],
            [$writerWithImageHtml, 'odt', 'writer8'],
            [$calc, 'xls', 'MS Excel 97'],
            [$calc, 'xlsx', 'Calc Office Open XML'],
            [$calc, 'xlsm', 'Calc MS Excel 2007 VBA XML'],
            [$presentation, 'ppt', 'MS PowerPoint 97'],
            [$presentation, 'pptx', 'Impress Office Open XML'],
            [$presentation, 'pptm', 'Impress MS PowerPoint 2007 XML VBA'],
            [$presentation, 'pdf', 'impress_pdf_Export'],
            [$drawing, 'pdf', 'draw_pdf_Export'],
            [$drawing, 'png', 'draw_png_Export'],
            [$drawing, 'jpg', 'draw_jpg_Export'],
            [$drawing, 'webp', 'draw_webp_Export'],
        ] as [$source, $extension, $filter]) {
            self::convert($binary, $source, $directory, $extension, $filter);
        }

        return [
            InputFormat::ODT->value => $writer,
            InputFormat::DOC->value => $directory . DIRECTORY_SEPARATOR . 'writer.doc',
            InputFormat::DOCX->value => $directory . DIRECTORY_SEPARATOR . 'writer.docx',
            InputFormat::DOCM->value => $directory . DIRECTORY_SEPARATOR . 'writer.docm',
            InputFormat::RTF->value => $directory . DIRECTORY_SEPARATOR . 'writer.rtf',
            InputFormat::TXT->value => $directory . DIRECTORY_SEPARATOR . 'writer.txt',
            InputFormat::HTML->value => $directory . DIRECTORY_SEPARATOR . 'writer.html',
            InputFormat::ODS->value => $calc,
            InputFormat::XLS->value => $directory . DIRECTORY_SEPARATOR . 'calc.xls',
            InputFormat::XLSX->value => $directory . DIRECTORY_SEPARATOR . 'calc.xlsx',
            InputFormat::XLSM->value => $directory . DIRECTORY_SEPARATOR . 'calc.xlsm',
            InputFormat::CSV->value => $directory . DIRECTORY_SEPARATOR . 'calc.csv',
            InputFormat::ODP->value => $presentation,
            InputFormat::PPT->value => $directory . DIRECTORY_SEPARATOR . 'presentation.ppt',
            InputFormat::PPTX->value => $directory . DIRECTORY_SEPARATOR . 'presentation.pptx',
            InputFormat::PPTM->value => $directory . DIRECTORY_SEPARATOR . 'presentation.pptm',
            InputFormat::ODG->value => $drawing,
            InputFormat::PDF->value => $directory . DIRECTORY_SEPARATOR . 'drawing.pdf',
            InputFormat::DXF->value => $directory . DIRECTORY_SEPARATOR . 'drawing.dxf',
            InputFormat::SVG->value => $directory . DIRECTORY_SEPARATOR . 'drawing.svg',
            InputFormat::PNG->value => $directory . DIRECTORY_SEPARATOR . 'drawing.png',
            InputFormat::JPEG->value => $directory . DIRECTORY_SEPARATOR . 'drawing.jpg',
            InputFormat::WEBP->value => $directory . DIRECTORY_SEPARATOR . 'drawing.webp',
            'multi_page_pdf' => $directory . DIRECTORY_SEPARATOR . 'multi-page.pdf',
            'writer_with_image' => $directory . DIRECTORY_SEPARATOR . 'writer-with-image.odt',
        ];
    }

    /**
     * Remove the generated corpus directory.
     */
    public static function cleanup(string $directory): void
    {
        if (! \is_dir($directory)) {
            return;
        }

        $items = \scandir($directory);
        if (\is_array($items)) {
            foreach ($items as $item) {
                if ($item !== '.' && $item !== '..') {
                    \unlink($directory . DIRECTORY_SEPARATOR . $item);
                }
            }
        }

        \rmdir($directory);
    }

    /**
     * Create a minimal ODF package with original test content.
     */
    private static function odf(string $path, string $mime, string $body): void
    {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Unable to create an ODF integration fixture.');
        }

        $zip->addFromString('mimetype', $mime);
        $zip->setCompressionName('mimetype', ZipArchive::CM_STORE);
        $zip->addFromString('META-INF/manifest.xml', '<?xml version="1.0" encoding="UTF-8"?><manifest:manifest xmlns:manifest="urn:oasis:names:tc:opendocument:xmlns:manifest:1.0" manifest:version="1.3"><manifest:file-entry manifest:full-path="/" manifest:media-type="' . $mime . '"/><manifest:file-entry manifest:full-path="content.xml" manifest:media-type="text/xml"/></manifest:manifest>');
        $zip->addFromString('content.xml', '<?xml version="1.0" encoding="UTF-8"?><office:document-content xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0" xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0" xmlns:draw="urn:oasis:names:tc:opendocument:xmlns:drawing:1.0" xmlns:svg="urn:oasis:names:tc:opendocument:xmlns:svg-compatible:1.0" office:version="1.3"><office:body>' . $body . '</office:body></office:document-content>');
        $zip->close();
    }

    /**
     * Write an original text fixture.
     */
    private static function write(string $path, string $contents): void
    {
        if (\file_put_contents($path, $contents) === false) {
            throw new RuntimeException('Unable to write an integration fixture.');
        }
    }

    /**
     * Write a deterministic two-page PDF without relying on LibreOffice generation.
     */
    private static function pdf(string $path): void
    {
        $streams = [
            'BT /F1 28 Tf 72 700 Td (Page One) Tj ET',
            'BT /F1 28 Tf 72 700 Td (Page Two) Tj ET',
        ];
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Count 2 /Kids [3 0 R 4 0 R] >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 5 0 R >> >> /Contents 6 0 R >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 5 0 R >> >> /Contents 7 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            '<< /Length ' . \strlen($streams[0]) . " >>\nstream\n" . $streams[0] . "\nendstream",
            '<< /Length ' . \strlen($streams[1]) . " >>\nstream\n" . $streams[1] . "\nendstream",
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $index => $object) {
            $offsets[] = \strlen($pdf);
            $pdf .= ($index + 1) . " 0 obj\n{$object}\nendobj\n";
        }

        $xref = \strlen($pdf);
        $pdf .= "xref\n0 8\n0000000000 65535 f \n";
        foreach (\array_slice($offsets, 1) as $offset) {
            $pdf .= \str_pad((string) $offset, 10, '0', STR_PAD_LEFT) . " 00000 n \n";
        }

        $pdf .= "trailer\n<< /Size 8 /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
        self::write($path, $pdf);
    }

    /**
     * Use the real LibreOffice binary to create one derived input fixture.
     */
    private static function convert(
        string $binary,
        string $source,
        string $directory,
        string $extension,
        string $filter,
    ): void {
        $process = new Process([
            $binary,
            '--headless',
            '--nologo',
            '--nodefault',
            '--norestore',
            '--convert-to',
            $extension . ':' . $filter,
            '--outdir',
            $directory,
            $source,
        ]);
        $process->setTimeout(30);
        $process->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException('LibreOffice could not build the ' . $extension . ' integration fixture: ' . $process->getErrorOutput());
        }
    }
}
