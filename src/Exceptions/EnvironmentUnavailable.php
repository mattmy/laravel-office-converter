<?php

declare(strict_types=1);

namespace Mattmy\OfficeConverter\Exceptions;

use Mattmy\OfficeConverter\Contracts\OfficeConverterException;
use RuntimeException;

/**
 * Reports invalid configuration or an unavailable LibreOffice environment.
 */
final class EnvironmentUnavailable extends RuntimeException implements OfficeConverterException {}
