<?php

declare(strict_types=1);

namespace Mattmy\OfficeConverter\Internal;

use ErrorException;
use Mattmy\OfficeConverter\Exceptions\EnvironmentUnavailable;

/**
 * Holds validated immutable configuration for one operation.
 *
 * @internal
 */
final readonly class Configuration
{
    /**
     * Create a validated operation configuration.
     */
    private function __construct(
        public string $binary,
        public float $timeout,
        public int $maxInputBytes,
        public int $maxOutputBytes,
        public string $temporaryDirectory,
    ) {}

    /**
     * Validate configuration read at an operation boundary.
     *
     * @throws EnvironmentUnavailable
     */
    public static function from(mixed $configured): self
    {
        if (! \is_array($configured)) {
            throw new EnvironmentUnavailable('The office converter configuration is invalid.');
        }

        $binary = $configured['binary'] ?? null;
        $timeout = $configured['timeout'] ?? null;
        $maxInputBytes = $configured['max_input_bytes'] ?? null;
        $maxOutputBytes = $configured['max_output_bytes'] ?? null;
        $temporaryDirectory = $configured['temporary_directory'] ?? null;

        if (! \is_string($binary) || \trim($binary) === '' || \str_contains($binary, "\0")
            || (! \is_int($timeout) && ! \is_float($timeout)) || ! \is_finite((float) $timeout) || $timeout <= 0
            || ! \is_int($maxInputBytes) || $maxInputBytes < 1
            || ! \is_int($maxOutputBytes) || $maxOutputBytes < 1
            || ! \is_string($temporaryDirectory) || \str_contains($temporaryDirectory, "\0") || ! Workspace::isAbsoluteLocalPath($temporaryDirectory)) {
            throw new EnvironmentUnavailable('The office converter configuration is invalid.');
        }

        try {
            if (! \is_dir($temporaryDirectory)) {
                try {
                    if (! \mkdir($temporaryDirectory, 0700, true) && ! \is_dir($temporaryDirectory)) {
                        throw new EnvironmentUnavailable('The configured temporary directory cannot be created.');
                    }
                } catch (ErrorException $exception) {
                    if (! \is_dir($temporaryDirectory)) {
                        throw $exception;
                    }
                }
            }

            $realTemporaryDirectory = \realpath($temporaryDirectory);
            if (! \is_string($realTemporaryDirectory) || ! \is_writable($realTemporaryDirectory)) {
                throw new EnvironmentUnavailable('The configured temporary directory is not writable.');
            }
        } catch (ErrorException $exception) {
            throw new EnvironmentUnavailable('The configured temporary directory cannot be created.', previous: $exception);
        }

        return new self(
            \trim($binary),
            (float) $timeout,
            $maxInputBytes,
            $maxOutputBytes,
            $realTemporaryDirectory,
        );
    }
}
