<?php

declare(strict_types=1);

namespace Mattmy\OfficeConverter\Exceptions;

use Mattmy\OfficeConverter\Contracts\OfficeConverterException;
use RuntimeException;

/**
 * Reports a failed LibreOffice conversion or invalid generated artifact.
 */
final class ConversionFailed extends RuntimeException implements OfficeConverterException {}
