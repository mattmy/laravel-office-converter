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
    /** @var array<string, InputFormat> */
    private const array INPUT_EXTENSIONS = [
        'odt' => InputFormat::ODT,
        'doc' => InputFormat::DOC,
        'docx' => InputFormat::DOCX,
        'docm' => InputFormat::DOCM,
        'rtf' => InputFormat::RTF,
        'txt' => InputFormat::TXT,
        'html' => InputFormat::HTML,
        'htm' => InputFormat::HTML,
        'ods' => InputFormat::ODS,
        'xls' => InputFormat::XLS,
        'xlsx' => InputFormat::XLSX,
        'xlsm' => InputFormat::XLSM,
        'csv' => InputFormat::CSV,
        'odp' => InputFormat::ODP,
        'ppt' => InputFormat::PPT,
        'pptx' => InputFormat::PPTX,
        'pptm' => InputFormat::PPTM,
        'odg' => InputFormat::ODG,
        'pdf' => InputFormat::PDF,
        'dxf' => InputFormat::DXF,
        'svg' => InputFormat::SVG,
        'png' => InputFormat::PNG,
        'jpg' => InputFormat::JPEG,
        'jpeg' => InputFormat::JPEG,
        'webp' => InputFormat::WEBP,
    ];

    /** @var array<string, DocumentFamily> */
    private const array INPUT_FAMILIES = [
        'odt' => DocumentFamily::WRITER,
        'doc' => DocumentFamily::WRITER,
        'docx' => DocumentFamily::WRITER,
        'docm' => DocumentFamily::WRITER,
        'rtf' => DocumentFamily::WRITER,
        'txt' => DocumentFamily::WRITER,
        'html' => DocumentFamily::WRITER,
        'ods' => DocumentFamily::CALC,
        'xls' => DocumentFamily::CALC,
        'xlsx' => DocumentFamily::CALC,
        'xlsm' => DocumentFamily::CALC,
        'csv' => DocumentFamily::CALC,
        'odp' => DocumentFamily::IMPRESS,
        'ppt' => DocumentFamily::IMPRESS,
        'pptx' => DocumentFamily::IMPRESS,
        'pptm' => DocumentFamily::IMPRESS,
        'odg' => DocumentFamily::DRAW,
        'pdf' => DocumentFamily::DRAW,
        'dxf' => DocumentFamily::DRAW,
        'svg' => DocumentFamily::DRAW,
        'png' => DocumentFamily::DRAW,
        'jpg' => DocumentFamily::DRAW,
        'webp' => DocumentFamily::DRAW,
    ];

    /** @var array<string, string> */
    private const array INPUT_FILTERS = [
        'csv' => 'Text - txt - csv (StarCalc):44,34,UTF8,1,,0,false,true,true',
        'txt' => 'Text (encoded):UTF8',
        'html' => 'HTML (StarWriter)',
        'pdf' => 'draw_pdf_import',
    ];

    /** @var array<string, array<string, array{extension: string, filter: string, options?: string}>> */
    private const array OUTPUTS = [
        'writer' => [
            'pdf' => ['extension' => 'pdf', 'filter' => 'writer_pdf_Export'],
            'odt' => ['extension' => 'odt', 'filter' => 'writer8'],
            'docx' => ['extension' => 'docx', 'filter' => 'Office Open XML Text'],
            'rtf' => ['extension' => 'rtf', 'filter' => 'Rich Text Format'],
            'txt' => ['extension' => 'txt', 'filter' => 'Text (encoded)', 'options' => 'UTF8'],
            'html' => ['extension' => 'html', 'filter' => 'HTML (StarWriter)'],
        ],
        'calc' => [
            'pdf' => ['extension' => 'pdf', 'filter' => 'calc_pdf_Export'],
            'ods' => ['extension' => 'ods', 'filter' => 'calc8'],
            'xlsx' => ['extension' => 'xlsx', 'filter' => 'Calc Office Open XML'],
            'csv' => ['extension' => 'csv', 'filter' => 'Text - txt - csv (StarCalc)', 'options' => '44,34,UTF8,1,,0,false,true,true'],
        ],
        'impress' => [
            'pdf' => ['extension' => 'pdf', 'filter' => 'impress_pdf_Export'],
            'odp' => ['extension' => 'odp', 'filter' => 'impress8'],
            'pptx' => ['extension' => 'pptx', 'filter' => 'Impress Office Open XML'],
        ],
        'draw' => [
            'pdf' => ['extension' => 'pdf', 'filter' => 'draw_pdf_Export'],
            'odg' => ['extension' => 'odg', 'filter' => 'draw8'],
            'png' => ['extension' => 'png', 'filter' => 'draw_png_Export'],
            'jpg' => ['extension' => 'jpg', 'filter' => 'draw_jpg_Export'],
            'svg' => ['extension' => 'svg', 'filter' => 'draw_svg_Export'],
            'webp' => ['extension' => 'webp', 'filter' => 'draw_webp_Export'],
        ],
    ];

    /**
     * Resolve a case-insensitive filename extension to an accepted input format.
     */
    public static function inputFromExtension(string $extension): ?InputFormat
    {
        return self::INPUT_EXTENSIONS[\strtolower($extension)] ?? null;
    }

    /**
     * Return the canonical extension used for an input snapshot.
     */
    public static function inputExtension(InputFormat $format): string
    {
        return $format->value;
    }

    /**
     * Return the document family selected by an input format.
     */
    public static function family(InputFormat $format): DocumentFamily
    {
        return self::INPUT_FAMILIES[$format->value];
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
     * @return array{extension: string, filter: string, options?: string}|null
     */
    public static function output(InputFormat $input, Format $format): ?array
    {
        return self::OUTPUTS[self::family($input)->value][$format->value] ?? null;
    }
}
