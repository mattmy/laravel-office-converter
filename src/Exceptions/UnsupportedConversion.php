<?php

declare(strict_types=1);

namespace Mattmy\OfficeConverter\Exceptions;

use InvalidArgumentException;
use Mattmy\OfficeConverter\Contracts\OfficeConverterException;

/**
 * Reports an output format unavailable to the input document family.
 */
final class UnsupportedConversion extends InvalidArgumentException implements OfficeConverterException {}
