<?php

declare(strict_types=1);

namespace Mattmy\OfficeConverter\Exceptions;

use InvalidArgumentException;
use Mattmy\OfficeConverter\Contracts\OfficeConverterException;

/**
 * Reports an unreadable, unsupported, oversized, or malformed input.
 */
final class InvalidOfficeInput extends InvalidArgumentException implements OfficeConverterException {}
