<?php

declare(strict_types=1);

namespace Mattmy\OfficeConverter\Enums;

/**
 * Lists the single-file formats available as conversion output.
 */
enum Format: string
{
    case PDF = 'pdf';
    case ODT = 'odt';
    case DOCX = 'docx';
    case RTF = 'rtf';
    case TXT = 'txt';
    case HTML = 'html';
    case ODS = 'ods';
    case XLSX = 'xlsx';
    case CSV = 'csv';
    case ODP = 'odp';
    case PPTX = 'pptx';
    case ODG = 'odg';
    case PNG = 'png';
    case JPEG = 'jpg';
    case SVG = 'svg';
    case WEBP = 'webp';
}
