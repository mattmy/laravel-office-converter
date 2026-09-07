<?php

declare(strict_types=1);

namespace Mattmy\OfficeConverter\Enums;

/**
 * Lists the document formats accepted as conversion input.
 */
enum InputFormat: string
{
    case ODT = 'odt';
    case DOC = 'doc';
    case DOCX = 'docx';
    case DOCM = 'docm';
    case RTF = 'rtf';
    case TXT = 'txt';
    case HTML = 'html';
    case ODS = 'ods';
    case XLS = 'xls';
    case XLSX = 'xlsx';
    case XLSM = 'xlsm';
    case CSV = 'csv';
    case ODP = 'odp';
    case PPT = 'ppt';
    case PPTX = 'pptx';
    case PPTM = 'pptm';
    case ODG = 'odg';
    case PDF = 'pdf';
    case DXF = 'dxf';
    case SVG = 'svg';
    case PNG = 'png';
    case JPEG = 'jpg';
    case WEBP = 'webp';
}
