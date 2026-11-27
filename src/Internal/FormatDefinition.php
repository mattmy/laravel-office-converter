<?php

declare(strict_types=1);

namespace Mattmy\OfficeConverter\Internal;

use Mattmy\OfficeConverter\Enums\Format;
use Mattmy\OfficeConverter\Enums\InputFormat;

/**
 * Owns the closed input and output format mappings.
 *
 * @internal
 */
final class FormatDefinition
{
    /** @var array<string, string> */
    private const INPUT_FILTERS = [
        'csv' => 'Text - txt - csv (StarCalc):44,34,UTF8,1,,0,false,true,true',
        'txt' => 'Text (encoded):UTF8',
        'html' => 'HTML (StarWriter)',
        'pdf' => 'draw_pdf_import',
    ];

    /** @var array<string, array<string, array{filter: string, options?: string}>> */
    private const OUTPUTS = [
        'writer' => [
            'pdf' => ['filter' => 'writer_pdf_Export'],
            'odt' => ['filter' => 'writer8'],
            'docx' => ['filter' => 'Office Open XML Text'],
            'rtf' => ['filter' => 'Rich Text Format'],
            'txt' => ['filter' => 'Text (encoded)', 'options' => 'UTF8'],
            'html' => ['filter' => 'HTML (StarWriter)'],
        ],
        'calc' => [
            'pdf' => ['filter' => 'calc_pdf_Export'],
            'ods' => ['filter' => 'calc8'],
            'xlsx' => ['filter' => 'Calc Office Open XML'],
            'csv' => ['filter' => 'Text - txt - csv (StarCalc)', 'options' => '44,34,UTF8,1,,0,false,true,true'],
        ],
        'impress' => [
            'pdf' => ['filter' => 'impress_pdf_Export'],
            'odp' => ['filter' => 'impress8'],
            'pptx' => ['filter' => 'Impress Office Open XML'],
        ],
        'draw' => [
            'pdf' => ['filter' => 'draw_pdf_Export'],
            'odg' => ['filter' => 'draw8'],
            'png' => ['filter' => 'draw_png_Export'],
            'jpg' => ['filter' => 'draw_jpg_Export'],
            'svg' => ['filter' => 'draw_svg_Export'],
            'webp' => ['filter' => 'draw_webp_Export'],
        ],
    ];

    /**
     * Resolve a case-insensitive filename extension to an accepted input format.
     */
    public static function inputFromExtension(string $extension): ?InputFormat
    {
        $extension = \strtolower($extension);

        return match ($extension) {
            'htm' => InputFormat::HTML,
            'jpeg' => InputFormat::JPEG,
            default => InputFormat::tryFrom($extension),
        };
    }

    /**
     * Return the document family selected by an input format.
     */
    public static function family(InputFormat $format): DocumentFamily
    {
        return match ($format) {
            InputFormat::ODT, InputFormat::DOC, InputFormat::DOCX, InputFormat::DOCM,
            InputFormat::RTF, InputFormat::TXT, InputFormat::HTML => DocumentFamily::WRITER,
            InputFormat::ODS, InputFormat::XLS, InputFormat::XLSX, InputFormat::XLSM,
            InputFormat::CSV => DocumentFamily::CALC,
            InputFormat::ODP, InputFormat::PPT, InputFormat::PPTX, InputFormat::PPTM => DocumentFamily::IMPRESS,
            InputFormat::ODG, InputFormat::PDF, InputFormat::DXF, InputFormat::SVG,
            InputFormat::PNG, InputFormat::JPEG, InputFormat::WEBP => DocumentFamily::DRAW,
        };
    }

    /**
     * Return the package-controlled input filter when the format needs one.
     */
    public static function inputFilter(InputFormat $format): ?string
    {
        return self::INPUT_FILTERS[$format->value] ?? null;
    }

    /**
     * Resolve an allowed output definition for an input family.
     *
     * @return array{filter: string, options?: string}|null
     */
    public static function output(InputFormat $input, Format $format): ?array
    {
        return self::OUTPUTS[self::family($input)->value][$format->value] ?? null;
    }
}
