<?php

declare(strict_types=1);

namespace Mattmy\OfficeConverter\Internal;

use DOMDocument;
use ErrorException;
use Mattmy\OfficeConverter\Enums\Format;
use Mattmy\OfficeConverter\Enums\InputFormat;
use Mattmy\OfficeConverter\Exceptions\ConversionFailed;
use Mattmy\OfficeConverter\Exceptions\InvalidOfficeInput;
use XMLParser;
use ZipArchive;

/**
 * Performs bounded structural validation for accepted document formats.
 *
 * @internal
 */
final class FileValidator
{
    private const MAX_ZIP_ENTRIES = 4096;

    private const MAX_XML_BYTES = 4_194_304;

    private const TEXT_CHUNK_BYTES = 8192;

    /** @var array<string, array{mainPart: string, contentType: string}> */
    private const OOXML = [
        'docx' => ['mainPart' => 'word/document.xml', 'contentType' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml'],
        'docm' => ['mainPart' => 'word/document.xml', 'contentType' => 'application/vnd.ms-word.document.macroEnabled.main+xml'],
        'xlsx' => ['mainPart' => 'xl/workbook.xml', 'contentType' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml'],
        'xlsm' => ['mainPart' => 'xl/workbook.xml', 'contentType' => 'application/vnd.ms-excel.sheet.macroEnabled.main+xml'],
        'pptx' => ['mainPart' => 'ppt/presentation.xml', 'contentType' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml'],
        'pptm' => ['mainPart' => 'ppt/presentation.xml', 'contentType' => 'application/vnd.ms-powerpoint.presentation.macroEnabled.main+xml'],
    ];

    /** @var array<string, string> */
    private const ODF = [
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
        try {
            if (! $this->isBoundedFile($path, $maxBytes) || ! $this->matches($path, $format)) {
                throw new InvalidOfficeInput('The office input is empty, oversized, or does not match its declared format.');
            }
        } catch (ErrorException $exception) {
            throw new InvalidOfficeInput('The office input could not be validated.', previous: $exception);
        }
    }

    /**
     * Validate one generated output artifact.
     *
     * @throws ConversionFailed
     */
    public function output(string $path, Format $format, int $maxBytes): void
    {
        $inputFormat = InputFormat::from($format->value);

        try {
            if (! $this->isBoundedFile($path, $maxBytes) || ! $this->matches($path, $inputFormat)) {
                throw new ConversionFailed('LibreOffice did not produce a valid bounded output artifact.');
            }
        } catch (ErrorException $exception) {
            throw new ConversionFailed('The converted office artifact could not be validated.', previous: $exception);
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
            InputFormat::HTML => $this->matchesUtf8($path) && $this->hasHtmlRoot($path),
            InputFormat::SVG => $this->matchesUtf8($path) && $this->matchesSvg($path),
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
        $zip = $this->openBoundedZip($path);
        if ($zip === null) {
            return false;
        }

        try {
            $mainPart = $this->boundedEntry($zip, $definition['mainPart']);
            if ($mainPart === null || $this->loadDom($mainPart) === null) {
                return false;
            }

            $xml = $this->boundedEntry($zip, '[Content_Types].xml');
            if ($xml === null) {
                return false;
            }

            $document = $this->loadDom($xml);
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
        $zip = $this->openBoundedZip($path);
        if ($zip === null) {
            return false;
        }

        try {
            $stat = $zip->statName('mimetype');
            $length = \strlen($mime);

            return \is_array($stat)
                && $stat['comp_method'] === ZipArchive::CM_STORE
                && $stat['size'] === $length
                && $zip->getFromName('mimetype', $length + 1) === $mime;
        } finally {
            $zip->close();
        }
    }

    /**
     * Open a non-empty ZIP with a bounded central directory for the caller to close.
     */
    private function openBoundedZip(string $path): ?ZipArchive
    {
        if (! $this->startsWith($path, "PK\x03\x04")) {
            return null;
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            return null;
        }

        if ($zip->numFiles <= 0 || $zip->numFiles > self::MAX_ZIP_ENTRIES) {
            $zip->close();

            return null;
        }

        return $zip;
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
     * Check original HTML root evidence without retaining the document or leading comments.
     */
    private function hasHtmlRoot(string $path): bool
    {
        $handle = \fopen($path, 'rb');
        if (! \is_resource($handle)) {
            return false;
        }

        $buffer = '';
        $comment = false;
        $doctype = false;
        $first = true;

        try {
            while (! \feof($handle)) {
                $chunk = \fread($handle, self::TEXT_CHUNK_BYTES);
                if (! \is_string($chunk)) {
                    return false;
                }

                if ($first) {
                    $chunk = \str_starts_with($chunk, "\xEF\xBB\xBF") ? \substr($chunk, 3) : $chunk;
                    $first = false;
                }

                $buffer .= $chunk;
                while ($buffer !== '') {
                    if ($comment) {
                        $end = \strpos($buffer, '-->');
                        if ($end === false) {
                            $buffer = \substr($buffer, -2);

                            break;
                        }

                        $buffer = \substr($buffer, $end + 3);
                        $comment = false;

                        continue;
                    }

                    $buffer = \ltrim($buffer, " \t\r\n\v\f");
                    if (! $doctype && \str_starts_with($buffer, '<!--')) {
                        $buffer = \substr($buffer, 4);
                        $comment = true;

                        continue;
                    }

                    if (! $doctype && \preg_match('/\A<!doctype\s/i', $buffer) === 1) {
                        $buffer = \substr($buffer, 9);
                        $doctype = true;

                        continue;
                    }

                    if (\preg_match($doctype ? '/\Ahtml(?:\s|>)/i' : '/\A<html(?:\s|>)/i', $buffer) === 1) {
                        return true;
                    }

                    if (\strlen($buffer) >= 10) {
                        return false;
                    }

                    break;
                }
            }

            return false;
        } finally {
            \fclose($handle);
        }
    }

    /**
     * Parse the complete SVG as XML chunks without building a DOM or resolving external entities.
     */
    private function matchesSvg(string $path): bool
    {
        $handle = \fopen($path, 'rb');
        if (! \is_resource($handle)) {
            return false;
        }

        $parser = \xml_parser_create_ns('UTF-8', '|');
        \xml_parser_set_option($parser, \XML_OPTION_CASE_FOLDING, 0);
        $root = null;
        \xml_set_element_handler($parser, static function (XMLParser $_parser, string $name) use (&$root): void {
            if ($root === null) {
                $separator = \strrpos($name, '|');
                $root = $separator === false ? $name : \substr($name, $separator + 1);
            }
        }, static function (): void {});
        \xml_set_external_entity_ref_handler($parser, static fn (): bool => false);

        try {
            while (! \feof($handle)) {
                $chunk = \fread($handle, self::TEXT_CHUNK_BYTES);
                if (! \is_string($chunk) || \str_contains($chunk, "\0")
                    || \xml_parse($parser, $chunk, \feof($handle)) !== 1) {
                    return false;
                }
            }

            return $root === 'svg';
        } finally {
            \fclose($handle);
        }
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
        $size = \filesize($path);
        if (! \is_int($size)) {
            return false;
        }

        $handle = \fopen($path, 'rb');
        if (! \is_resource($handle)) {
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

                if (\fseek($handle, $length + 4, \SEEK_CUR) !== 0) {
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
        $size = \filesize($path);
        if (! \is_int($size)) {
            return false;
        }

        $handle = \fopen($path, 'rb');
        if (! \is_resource($handle)) {
            return false;
        }

        try {
            $header = \fread($handle, 12);
            if (! \is_string($header) || \strlen($header) !== 12 || \substr($header, 0, 4) !== 'RIFF' || \substr($header, 8, 4) !== 'WEBP'
                || ($riffLength = $this->unsignedLength(\substr($header, 4, 4), 'V')) === null
                || $riffLength + 8 !== $size) {
                return false;
            }

            $hasExtendedHeader = false;
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

                $type = \substr($chunk, 0, 4);
                $hasImageChunk = $hasImageChunk || match ($type) {
                    'VP8 ', 'VP8L' => $length > 0,
                    // The frame needs a 16-byte header, an 8-byte subchunk header and payload.
                    'ANMF' => $hasExtendedHeader && $length > 24,
                    default => false,
                };
                $hasExtendedHeader = $hasExtendedHeader || ($type === 'VP8X' && $length === 10);
                if (\fseek($handle, $paddedLength, \SEEK_CUR) !== 0) {
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
     * Check the exact trailer of a file.
     */
    private function endsWith(string $path, string $signature): bool
    {
        $tail = $this->readTail($path);

        return \is_string($tail) && \str_ends_with($tail, $signature);
    }

    /**
     * Accept a PDF trailer followed only by ASCII whitespace.
     */
    private function hasPdfTrailer(string $path): bool
    {
        $tail = $this->readTail($path);

        return \is_string($tail) && \str_ends_with(\rtrim($tail, " \t\r\n\f\v"), '%%EOF');
    }

    /**
     * Read at most the final 1 KiB for trailer validation.
     */
    private function readTail(string $path): string|false
    {
        $size = \filesize($path);
        if (! \is_int($size)) {
            return false;
        }

        $length = \min(1024, $size);

        return \file_get_contents($path, false, null, $size - $length, $length);
    }

    /**
     * Parse a bounded container XML entry while containing libxml diagnostics.
     */
    private function loadDom(string $contents): ?DOMDocument
    {
        if ($contents === '' || \preg_match('//u', $contents) !== 1) {
            return null;
        }

        $previous = \libxml_use_internal_errors(true);

        try {
            $document = new DOMDocument();
            $loaded = $document->loadXML($contents, \LIBXML_NONET | \LIBXML_NOBLANKS);

            return $loaded ? $document : null;
        } finally {
            \libxml_clear_errors();
            \libxml_use_internal_errors($previous);
        }
    }
}
