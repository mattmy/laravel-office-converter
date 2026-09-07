<?php

declare(strict_types=1);

namespace Mattmy\OfficeConverter\Exceptions;

use LogicException;
use Mattmy\OfficeConverter\Contracts\OfficeConverterException;

/**
 * Reports a second operation on a one-time document or result.
 */
final class AlreadyConsumed extends LogicException implements OfficeConverterException {}
