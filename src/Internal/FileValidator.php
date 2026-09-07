<?php

declare(strict_types=1);

namespace Mattmy\OfficeConverter\Internal;

use DOMDocument;
use DOMElement;
use Mattmy\OfficeConverter\Enums\Format;
use Mattmy\OfficeConverter\Enums\InputFormat;
use Mattmy\OfficeConverter\Exceptions\ConversionFailed;
use Mattmy\OfficeConverter\Exceptions\InvalidOfficeInput;
use ZipArchive;

/**
 * Performs bounded structural validation for accepted document formats.
 *
 * @internal
 */
final class FileValidator
{
    private const int MAX_ZIP_ENTRIES = 4096;

    private const int MAX_XML_BYTES = 4_194_304;

    private const int TEXT_CHUNK_BYTES = 8192;

    /** @var array<string, array{mainPart: string, contentType: string}> */
    private const array OOXML = [
        'docx' => ['mainPart' => 'word/document.xml', 'contentType' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml'],
        'docm' => ['mainPart' => 'word/document.xml', 'contentType' => 'application/vnd.ms-word.document.macroEnabled.main+xml'],
        'xlsx' => ['mainPart' => 'xl/workbook.xml', 'contentType' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml'],
        'xlsm' => ['mainPart' => 'xl/workbook.xml', 'contentType' => 'application/vnd.ms-excel.sheet.macroEnabled.main+xml'],
        'pptx' => ['mainPart' => 'ppt/presentation.xml', 'contentType' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml'],
        'pptm' => ['mainPart' => 'ppt/presentation.xml', 'contentType' => 'application/vnd.ms-powerpoint.presentation.macroEnabled.main+xml'],
    ];

    /** @var array<string, string> */
    private const array ODF = [
        'odt' => 'application/vnd.oasis.opendocument.text',
        'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
        'odp' => 'application/vnd.oasis.opendocument.presentation',
        'odg' => 'application/vnd.oasis.opendocument.graphics',
    ];

    /**
     * Validate one package-owned input snapshot.
     *
     * @throws InvalidOfficeInput
     */
    public function input(string $path, InputFormat $format, int $maxBytes): void
    {
        if (! $this->isBoundedFile($path, $maxBytes) || ! $this->matches($path, $format)) {
            throw new InvalidOfficeInput('The office input is empty, oversized, or does not match its declared format.');
        }
    }

    /**
     * Validate one generated output artifact.
     *
     * @throws ConversionFailed
     */
    public function output(string $path, Format $format, int $maxBytes): void
    {
        $inputFormat = match ($format) {
            Format::PDF => InputFormat::PDF,
            Format::ODT => InputFormat::ODT,
            Format::DOCX => InputFormat::DOCX,
            Format::RTF => InputFormat::RTF,
            Format::TXT => InputFormat::TXT,
            Format::HTML => InputFormat::HTML,
            Format::ODS => InputFormat::ODS,
            Format::XLSX => InputFormat::XLSX,
            Format::CSV => InputFormat::CSV,
            Format::ODP => InputFormat::ODP,
            Format::PPTX => InputFormat::PPTX,
            Format::ODG => InputFormat::ODG,
            Format::PNG => InputFormat::PNG,
            Format::JPEG => InputFormat::JPEG,
            Format::SVG => InputFormat::SVG,
            Format::WEBP => InputFormat::WEBP,
        };

        if (! $this->isBoundedFile($path, $maxBytes) || ! $this->matches($path, $inputFormat)) {
            throw new ConversionFailed('LibreOffice did not produce a valid bounded output artifact.');
        }
    }

    /**
     * Determine whether a path is a readable non-empty file within a byte limit.
     */
    private function isBoundedFile(string $path, int $maxBytes): bool
    {
        if (\is_link($path) || ! \is_file($path) || ! \is_readable($path)) {
            return false;
        }

        $size = \filesize($path);

        return \is_int($size) && $size > 0 && $size <= $maxBytes;
    }

    /**
     * Match file bytes against the declared input format.
     */
    private function matches(string $path, InputFormat $format): bool
    {
        if (isset(self::OOXML[$format->value])) {
            return $this->matchesOoxml($path, self::OOXML[$format->value]);
        }

        if (isset(self::ODF[$format->value])) {
            return $this->matchesOdf($path, self::ODF[$format->value]);
        }

        return match ($format) {
            InputFormat::DOC, InputFormat::XLS, InputFormat::PPT => $this->startsWith($path, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1"),
            InputFormat::PDF => $this->startsWith($path, '%PDF-') && $this->hasPdfTrailer($path),
            InputFormat::RTF => $this->startsWith($path, '{\\rtf'),
            InputFormat::PNG => $this->matchesPng($path),
            InputFormat::JPEG => $this->startsWith($path, "\xFF\xD8\xFF") && $this->endsWith($path, "\xFF\xD9"),
            InputFormat::WEBP => $this->matchesWebp($path),
            InputFormat::HTML => $this->matchesXmlLike($path, 'html', false),
            InputFormat::SVG => $this->matchesXmlLike($path, 'svg', true),
            InputFormat::DXF => $this->matchesDxf($path),
            InputFormat::TXT, InputFormat::CSV => $this->matchesUtf8($path),
            default => false,
        };
    }

    /**
     * Validate an OOXML container without extracting it.
     *
     * @param  array{mainPart: string, contentType: string}  $definition
     */
    private function matchesOoxml(string $path, array $definition): bool
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            return false;
        }

        try {
            $mainPart = $this->boundedEntry($zip, $definition['mainPart']);
            if (! $this->hasBoundedEntries($zip) || $mainPart === null || ! $this->validXml($mainPart)) {
                return false;
            }

            $xml = $this->boundedEntry($zip, '[Content_Types].xml');
            if ($xml === null || ! $this->validXml($xml)) {
                return false;
            }

            $document = $this->loadDom($xml, true);
            if ($document === null) {
                return false;
            }

            foreach ($document->getElementsByTagNameNS('*', 'Override') as $override) {
                if ($override->getAttribute('PartName') === '/' . $definition['mainPart']
                    && $override->getAttribute('ContentType') === $definition['contentType']) {
                    return true;
                }
            }

            return false;
        } finally {
            $zip->close();
        }
    }

    /**
     * Validate an ODF container without extracting it.
     */
    private function matchesOdf(string $path, string $mime): bool
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            return false;
        }

        try {
            $stat = $zip->statName('mimetype');

            return $this->hasBoundedEntries($zip)
                && \is_array($stat)
                && $stat['comp_method'] === ZipArchive::CM_STORE
                && $this->boundedEntry($zip, 'mimetype') === $mime;
        } finally {
            $zip->close();
        }
    }

    /**
     * Reject archives whose directory or required entries exceed fixed bounds.
     */
    private function hasBoundedEntries(ZipArchive $zip): bool
    {
        return $zip->numFiles > 0 && $zip->numFiles <= self::MAX_ZIP_ENTRIES;
    }

    /**
     * Read one declared-small ZIP entry with an actual byte bound.
     */
    private function boundedEntry(ZipArchive $zip, string $name): ?string
    {
        $stat = $zip->statName($name);
        if (! \is_array($stat) || $stat['size'] > self::MAX_XML_BYTES) {
            return null;
        }

        $contents = $zip->getFromName($name, self::MAX_XML_BYTES + 1);

        return \is_string($contents) && \strlen($contents) <= self::MAX_XML_BYTES ? $contents : null;
    }

    /**
     * Validate UTF-8 XML without external network access.
     */
    private function validXml(string $xml): bool
    {
        if (! \mb_check_encoding($xml, 'UTF-8')) {
            return false;
        }

        return $this->loadDom($xml, true) !== null;
    }

    /**
     * Validate an HTML or SVG root using a non-networked DOM parser.
     */
    private function matchesXmlLike(string $path, string $root, bool $xml): bool
    {
        $contents = \file_get_contents($path, false, null, 0, self::MAX_XML_BYTES + 1);
        if (! \is_string($contents) || \strlen($contents) > self::MAX_XML_BYTES || ! \mb_check_encoding($contents, 'UTF-8')) {
            return false;
        }

        if (! $xml && \preg_match('/<!doctype\s+html\b|<html(?:\s|>)/i', $contents) !== 1) {
            return false;
        }

        $document = $this->loadDom($contents, $xml);
        $documentElement = $document?->documentElement;
        if (! $documentElement instanceof DOMElement || ! \is_string($documentElement->localName)) {
            return false;
        }

        return \strtolower($documentElement->localName) === $root;
    }

    /**
     * Validate the minimal binary or text DXF framing.
     */
    private function matchesDxf(string $path): bool
    {
        $prefix = \file_get_contents($path, false, null, 0, 4096);

        return \is_string($prefix)
            && (\str_starts_with($prefix, "AutoCAD Binary DXF\r\n\x1A\x00")
                || \preg_match('/(?:^|\R)\s*0\s*\R\s*SECTION\s*(?:\R|$)/i', $prefix) === 1);
    }

    /**
     * Validate a non-empty UTF-8 text file.
     */
    private function matchesUtf8(string $path): bool
    {
        $handle = \fopen($path, 'rb');
        if (! \is_resource($handle)) {
            return false;
        }

        $pending = '';
        $read = false;

        try {
            while (! \feof($handle)) {
                $chunk = \fread($handle, self::TEXT_CHUNK_BYTES);
                if (! \is_string($chunk)) {
                    return false;
                }

                if ($chunk === '') {
                    continue;
                }

                $read = true;
                $buffer = $pending . $chunk;
                $pending = $this->utf8Tail($buffer);
                if ($pending === null) {
                    return false;
                }
            }
        } finally {
            \fclose($handle);
        }

        return $read && $pending === '';
    }

    /**
     * Return an incomplete UTF-8 suffix, or null when the bytes are invalid.
     */
    private function utf8Tail(string $buffer): ?string
    {
        for ($length = 0; $length <= 3 && $length <= \strlen($buffer); $length++) {
            $prefix = $length === 0 ? $buffer : \substr($buffer, 0, -$length);
            $tail = $length === 0 ? '' : \substr($buffer, -$length);
            if (\preg_match('//u', $prefix) === 1 && ($tail === '' || $this->isIncompleteUtf8Prefix($tail))) {
                return $tail;
            }
        }

        return null;
    }

    /**
     * Determine whether bytes can be the unfinished beginning of one UTF-8 character.
     */
    private function isIncompleteUtf8Prefix(string $bytes): bool
    {
        return \preg_match('/^(?:[\xC2-\xDF](?:[\x80-\xBF])?|(?:\xE0[\xA0-\xBF]?|[\xE1-\xEC\xEE-\xEF][\x80-\xBF]?|\xED[\x80-\x9F]?)(?:[\x80-\xBF])?|(?:\xF0[\x90-\xBF]?|[\xF1-\xF3][\x80-\xBF]?|\xF4[\x80-\x8F]?)(?:[\x80-\xBF])?(?:[\x80-\xBF])?)$/D', $bytes) === 1;
    }

    /**
     * Validate PNG chunk framing with required IHDR and terminal IEND chunks.
     */
    private function matchesPng(string $path): bool
    {
        $handle = \fopen($path, 'rb');
        $size = \filesize($path);
        if (! \is_resource($handle) || ! \is_int($size)) {
            return false;
        }

        try {
            if (\fread($handle, 8) !== "\x89PNG\r\n\x1A\n") {
                return false;
            }

            $first = true;
            while (\ftell($handle) < $size) {
                $header = \fread($handle, 8);
                if (! \is_string($header) || \strlen($header) !== 8) {
                    return false;
                }

                $length = $this->unsignedLength(\substr($header, 0, 4), 'N');
                $type = \substr($header, 4, 4);
                $position = \ftell($handle);
                if ($length === null || ! \is_int($position) || $length > $size - $position - 4) {
                    return false;
                }

                if ($first && ($type !== 'IHDR' || $length !== 13)) {
                    return false;
                }

                if ($type === 'IEND') {
                    return ! $first && $length === 0 && $position + 4 === $size;
                }

                if (\fseek($handle, $length + 4, SEEK_CUR) !== 0) {
                    return false;
                }

                $first = false;
            }
        } finally {
            \fclose($handle);
        }

        return false;
    }

    /**
     * Validate basic RIFF/WebP framing.
     */
    private function matchesWebp(string $path): bool
    {
        $handle = \fopen($path, 'rb');
        $size = \filesize($path);
        if (! \is_resource($handle) || ! \is_int($size)) {
            return false;
        }

        try {
            $header = \fread($handle, 12);
            if (! \is_string($header) || \strlen($header) !== 12 || \substr($header, 0, 4) !== 'RIFF' || \substr($header, 8, 4) !== 'WEBP'
                || ($riffLength = $this->unsignedLength(\substr($header, 4, 4), 'V')) === null
                || $riffLength + 8 !== $size) {
                return false;
            }

            $hasImageChunk = false;
            while (\ftell($handle) < $size) {
                $chunk = \fread($handle, 8);
                if (! \is_string($chunk) || \strlen($chunk) !== 8) {
                    return false;
                }

                $length = $this->unsignedLength(\substr($chunk, 4, 4), 'V');
                $position = \ftell($handle);
                if ($length === null || ! \is_int($position)) {
                    return false;
                }

                $paddedLength = $length + ($length % 2);
                if ($paddedLength > $size - $position) {
                    return false;
                }

                $hasImageChunk = $hasImageChunk || \in_array(\substr($chunk, 0, 4), ['VP8 ', 'VP8L', 'VP8X'], true);
                if (\fseek($handle, $paddedLength, SEEK_CUR) !== 0) {
                    return false;
                }
            }

            return $hasImageChunk;
        } finally {
            \fclose($handle);
        }
    }

    /**
     * Decode an unsigned four-byte integer from a binary container header.
     */
    private function unsignedLength(string $bytes, string $format): ?int
    {
        $unpacked = \unpack($format . 'length', $bytes);
        $length = \is_array($unpacked) ? $unpacked['length'] ?? null : null;

        return \is_int($length) ? $length : null;
    }

    /**
     * Compare the first bytes of a file.
     */
    private function startsWith(string $path, string $signature): bool
    {
        return \file_get_contents($path, false, null, 0, \strlen($signature)) === $signature;
    }

    /**
     * Find a trailer in the final bounded portion of a file.
     */
    private function endsWith(string $path, string $signature): bool
    {
        $size = \filesize($path);
        if (! \is_int($size)) {
            return false;
        }

        $length = \min(1024, $size);
        $tail = \file_get_contents($path, false, null, $size - $length, $length);

        return \is_string($tail) && \str_contains($tail, $signature);
    }

    /**
     * Accept a PDF trailer followed only by ASCII whitespace.
     */
    private function hasPdfTrailer(string $path): bool
    {
        $size = \filesize($path);
        if (! \is_int($size)) {
            return false;
        }

        $length = \min(1024, $size);
        $tail = \file_get_contents($path, false, null, $size - $length, $length);

        return \is_string($tail) && \str_ends_with(\rtrim($tail, " \t\r\n\f\v"), '%%EOF');
    }

    /**
     * Parse XML or HTML while containing libxml diagnostics to this boundary.
     */
    private function loadDom(string $contents, bool $xml): ?DOMDocument
    {
        $previous = \libxml_use_internal_errors(true);

        try {
            $document = new DOMDocument();
            $loaded = $xml
                ? $document->loadXML($contents, LIBXML_NONET | LIBXML_NOBLANKS)
                : $document->loadHTML($contents, LIBXML_NONET | LIBXML_NOBLANKS);

            return $loaded ? $document : null;
        } finally {
            \libxml_clear_errors();
            \libxml_use_internal_errors($previous);
        }
    }
}
