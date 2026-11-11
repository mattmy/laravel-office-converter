<?php

declare(strict_types=1);

use Mattmy\OfficeConverter\Enums\Format;
use Mattmy\OfficeConverter\Enums\InputFormat;
use Mattmy\OfficeConverter\Exceptions\ConversionFailed;
use Mattmy\OfficeConverter\Exceptions\InvalidOfficeInput;
use Mattmy\OfficeConverter\Internal\FileValidator;

it('validates HTML and SVG around the former limit using configured byte limits', function (InputFormat $format, int $size): void {
    $path = \tempnam(\sys_get_temp_dir(), 'office-xml-');
    if (! \is_string($path)) {
        throw new RuntimeException('Unable to create a validation fixture.');
    }

    $opening = $format === InputFormat::HTML ? '<!doctype html><html><body>' : '<svg xmlns="http://www.w3.org/2000/svg"><text>';
    $closing = $format === InputFormat::HTML ? '</body></html>' : '</text></svg>';
    $validator = new FileValidator();
    $output = Format::from($format->value);

    try {
        \file_put_contents($path, $opening . \str_repeat('x', $size - \strlen($opening . $closing)) . $closing);
        $validator->input($path, $format, $size);
        $validator->output($path, $output, $size);
        expect(fn () => $validator->input($path, $format, $size - 1))->toThrow(InvalidOfficeInput::class)
            ->and(fn () => $validator->output($path, $output, $size - 1))->toThrow(ConversionFailed::class);
    } finally {
        \unlink($path);
    }
})->with([[InputFormat::HTML], [InputFormat::SVG]])->with([[4_194_303], [4_194_304], [4_194_305], [12 * 1024 * 1024]]);

it('rejects invalid XML-like contents with stable input and output exceptions', function (InputFormat $format, string $contents): void {
    $path = \tempnam(\sys_get_temp_dir(), 'office-xml-invalid-');
    if (! \is_string($path)) {
        throw new RuntimeException('Unable to create a validation fixture.');
    }

    try {
        \file_put_contents($path, $contents);
        $validator = new FileValidator();
        expect(fn () => $validator->input($path, $format, 20 * 1024 * 1024))->toThrow(InvalidOfficeInput::class)
            ->and(fn () => $validator->output($path, Format::from($format->value), 20 * 1024 * 1024))->toThrow(ConversionFailed::class);
    } finally {
        \unlink($path);
    }
})->with([
    'late SVG mismatch' => [InputFormat::SVG, '<svg><text>' . \str_repeat('x', 4_194_304) . '</svg>'],
    'late SVG invalid UTF-8' => [InputFormat::SVG, '<svg>' . \str_repeat('x', 4_194_304) . "\xFF</svg>"],
    'late HTML invalid UTF-8' => [InputFormat::HTML, '<html>' . \str_repeat('x', 4_194_304) . "\xFF</html>"],
    'wrong SVG root' => [InputFormat::SVG, '<root><svg/></root>'],
    'second SVG root' => [InputFormat::SVG, '<svg/><svg/>'],
    'commented HTML root' => [InputFormat::HTML, '<!-- <html> --><body>text</body>'],
    'unclosed HTML comment' => [InputFormat::HTML, '<!-- <html>'],
    'false HTML doctype' => [InputFormat::HTML, '<!doctype html-not><body>text</body>'],
    'external SVG entity' => [InputFormat::SVG, '<!DOCTYPE svg [<!ENTITY ext SYSTEM "file:///missing-office-validation">]><svg>&ext;</svg>'],
]);
