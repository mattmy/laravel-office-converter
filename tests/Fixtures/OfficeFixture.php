<?php

declare(strict_types=1);

namespace Mattmy\OfficeConverter\Tests\Fixtures;

use Mattmy\OfficeConverter\Enums\InputFormat;
use RuntimeException;
use ZipArchive;

/**
 * Builds minimal structurally valid office fixtures for unit tests.
 */
final class OfficeFixture
{
    /**
     * Create a fixture for an accepted input format.
     */
    public static function create(InputFormat $format): string
    {
        return match ($format) {
            InputFormat::DOCX, InputFormat::DOCM, InputFormat::XLSX, InputFormat::XLSM,
            InputFormat::PPTX, InputFormat::PPTM => self::ooxml($format),
            InputFormat::ODT, InputFormat::ODS, InputFormat::ODP, InputFormat::ODG => self::odf($format),
            InputFormat::DOC, InputFormat::XLS, InputFormat::PPT => self::bytes($format, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1fixture"),
            InputFormat::PDF => self::bytes($format, "%PDF-1.7\nfixture\n%%EOF\n"),
            InputFormat::RTF => self::bytes($format, '{\\rtf1 fixture}'),
            InputFormat::TXT => self::bytes($format, '繁體中文 text'),
            InputFormat::HTML => self::bytes($format, '<!doctype html><html><body>測試</body></html>'),
            InputFormat::CSV => self::bytes($format, "名稱,值\n測試,1\n"),
            InputFormat::DXF => self::bytes($format, "0\nSECTION\n2\nENTITIES\n0\nENDSEC\n0\nEOF\n"),
            InputFormat::SVG => self::bytes($format, '<svg xmlns="http://www.w3.org/2000/svg"><rect width="1" height="1"/></svg>'),
            InputFormat::PNG => self::bytes($format, self::png()),
            InputFormat::JPEG => self::bytes($format, "\xFF\xD8\xFFfixture\xFF\xD9"),
            InputFormat::WEBP => self::bytes($format, 'RIFF' . \pack('V', 12) . 'WEBPVP8 ' . \pack('V', 0)),
        };
    }

    /**
     * Remove a fixture created by this helper.
     */
    public static function remove(string $path): void
    {
        if (\is_file($path)) {
            \unlink($path);
        }
    }

    /**
     * Create a byte fixture with a canonical extension.
     */
    private static function bytes(InputFormat $format, string $contents): string
    {
        $path = self::path($format);
        if (\file_put_contents($path, $contents) === false) {
            throw new RuntimeException('Unable to create an office fixture.');
        }

        return $path;
    }

    /**
     * Create a minimal OOXML ZIP fixture.
     */
    private static function ooxml(InputFormat $format): string
    {
        $definition = match ($format) {
            InputFormat::DOCX => ['mainPart' => 'word/document.xml', 'contentType' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml'],
            InputFormat::DOCM => ['mainPart' => 'word/document.xml', 'contentType' => 'application/vnd.ms-word.document.macroEnabled.main+xml'],
            InputFormat::XLSX => ['mainPart' => 'xl/workbook.xml', 'contentType' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml'],
            InputFormat::XLSM => ['mainPart' => 'xl/workbook.xml', 'contentType' => 'application/vnd.ms-excel.sheet.macroEnabled.main+xml'],
            InputFormat::PPTX => ['mainPart' => 'ppt/presentation.xml', 'contentType' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml'],
            InputFormat::PPTM => ['mainPart' => 'ppt/presentation.xml', 'contentType' => 'application/vnd.ms-powerpoint.presentation.macroEnabled.main+xml'],
            default => throw new RuntimeException('Unsupported OOXML fixture format.'),
        };
        $path = self::path($format);
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Unable to create an OOXML fixture.');
        }

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types><Override PartName="/' . $definition['mainPart'] . '" ContentType="' . $definition['contentType'] . '"/></Types>');
        $zip->addFromString($definition['mainPart'], '<?xml version="1.0"?><document/>');
        $zip->close();

        return $path;
    }

    /**
     * Create a minimal ODF ZIP fixture.
     */
    private static function odf(InputFormat $format): string
    {
        $mime = match ($format) {
            InputFormat::ODT => 'application/vnd.oasis.opendocument.text',
            InputFormat::ODS => 'application/vnd.oasis.opendocument.spreadsheet',
            InputFormat::ODP => 'application/vnd.oasis.opendocument.presentation',
            InputFormat::ODG => 'application/vnd.oasis.opendocument.graphics',
            default => throw new RuntimeException('Unsupported ODF fixture format.'),
        };
        $path = self::path($format);
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Unable to create an ODF fixture.');
        }

        $zip->addFromString('mimetype', $mime);
        $zip->setCompressionName('mimetype', ZipArchive::CM_STORE);
        $zip->addFromString('content.xml', '<?xml version="1.0"?><document/>');
        $zip->close();

        return $path;
    }

    /**
     * Return a unique path with the requested extension.
     */
    private static function path(InputFormat $format): string
    {
        return \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'office-fixture-' . \bin2hex(\random_bytes(8)) . '.' . $format->value;
    }

    /**
     * Create a minimally framed PNG with IHDR and IEND chunks.
     */
    private static function png(): string
    {
        $ihdr = \pack('N', 1) . \pack('N', 1) . "\x08\x02\x00\x00\x00";

        return "\x89PNG\r\n\x1A\n"
            . \pack('N', 13) . 'IHDR' . $ihdr . "\x00\x00\x00\x00"
            . \pack('N', 0) . 'IEND' . "\x00\x00\x00\x00";
    }
}
