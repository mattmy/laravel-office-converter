<?php

declare(strict_types=1);

namespace Mattmy\OfficeConverter\Internal;

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

        if (! \is_string($binary) || \trim($binary) === ''
            || (! \is_int($timeout) && ! \is_float($timeout)) || $timeout <= 0
            || ! \is_int($maxInputBytes) || $maxInputBytes < 1
            || ! \is_int($maxOutputBytes) || $maxOutputBytes < 1
            || ! \is_string($temporaryDirectory) || ! self::isAbsolutePath($temporaryDirectory)) {
            throw new EnvironmentUnavailable('The office converter configuration is invalid.');
        }

        if (! \is_dir($temporaryDirectory)
            && ! \mkdir($temporaryDirectory, 0700, true)
            && ! \is_dir($temporaryDirectory)) {
            throw new EnvironmentUnavailable('The configured temporary directory cannot be created.');
        }

        $realTemporaryDirectory = \realpath($temporaryDirectory);
        if (! \is_string($realTemporaryDirectory) || ! \is_writable($realTemporaryDirectory)) {
            throw new EnvironmentUnavailable('The configured temporary directory is not writable.');
        }

        return new self(
            \trim($binary),
            (float) $timeout,
            $maxInputBytes,
            $maxOutputBytes,
            $realTemporaryDirectory,
        );
    }

    /**
     * Determine whether a path is absolute on the current platform.
     */
    private static function isAbsolutePath(string $path): bool
    {
        if ($path === '' || \str_contains($path, '://')) {
            return false;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            return \preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
        }

        return \str_starts_with($path, '/');
    }
}
