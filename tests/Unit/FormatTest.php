<?php

declare(strict_types=1);

use Mattmy\OfficeConverter\Enums\Format;
use Mattmy\OfficeConverter\Enums\InputFormat;
use Mattmy\OfficeConverter\Internal\DocumentFamily;
use Mattmy\OfficeConverter\Internal\FormatDefinition;

it('maps every public input to one family and accepted aliases', function (): void {
    foreach (InputFormat::cases() as $input) {
        expect(FormatDefinition::family($input))->toBeInstanceOf(DocumentFamily::class);
    }

    expect(InputFormat::cases())->toHaveCount(23)
        ->and(FormatDefinition::inputFromExtension('DOCX'))->toBe(InputFormat::DOCX)
        ->and(FormatDefinition::inputFromExtension('htm'))->toBe(InputFormat::HTML)
        ->and(FormatDefinition::inputFromExtension('jpeg'))->toBe(InputFormat::JPEG)
        ->and(FormatDefinition::inputFromExtension('unknown'))->toBeNull();
});

it('exposes only the accepted family output matrix', function (): void {
    $expected = [
        InputFormat::DOCX->value => [Format::PDF, Format::ODT, Format::DOCX, Format::RTF, Format::TXT, Format::HTML],
        InputFormat::XLSX->value => [Format::PDF, Format::ODS, Format::XLSX, Format::CSV],
        InputFormat::PPTX->value => [Format::PDF, Format::ODP, Format::PPTX],
        InputFormat::ODG->value => [Format::PDF, Format::ODG, Format::PNG, Format::JPEG, Format::SVG, Format::WEBP],
    ];

    foreach ($expected as $inputValue => $formats) {
        $input = InputFormat::from($inputValue);
        foreach (Format::cases() as $format) {
            expect(FormatDefinition::output($input, $format) !== null)->toBe(\in_array($format, $formats, true));
        }
    }
});

it('uses family-specific pdf and fixed text input filters', function (): void {
    expect(FormatDefinition::output(InputFormat::DOCX, Format::PDF)['filter'] ?? null)->toBe('writer_pdf_Export')
        ->and(FormatDefinition::output(InputFormat::XLSX, Format::PDF)['filter'] ?? null)->toBe('calc_pdf_Export')
        ->and(FormatDefinition::output(InputFormat::PPTX, Format::PDF)['filter'] ?? null)->toBe('impress_pdf_Export')
        ->and(FormatDefinition::output(InputFormat::PDF, Format::PDF)['filter'] ?? null)->toBe('draw_pdf_Export')
        ->and(FormatDefinition::inputFilter(InputFormat::CSV))->toContain('44,34,UTF8,1')
        ->and(FormatDefinition::inputFilter(InputFormat::TXT))->toBe('Text (encoded):UTF8')
        ->and(FormatDefinition::inputFilter(InputFormat::HTML))->toBe('HTML (StarWriter)')
        ->and(FormatDefinition::inputFilter(InputFormat::PDF))->toBe('draw_pdf_import');
});
