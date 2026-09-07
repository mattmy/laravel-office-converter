<?php

declare(strict_types=1);

namespace Mattmy\OfficeConverter\Internal;

/**
 * Identifies the LibreOffice document service used by an input.
 *
 * @internal
 */
enum DocumentFamily: string
{
    case WRITER = 'writer';
    case CALC = 'calc';
    case IMPRESS = 'impress';
    case DRAW = 'draw';
}
