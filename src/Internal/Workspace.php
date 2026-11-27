<?php

declare(strict_types=1);

namespace Mattmy\OfficeConverter\Internal;

use Closure;
use FilesystemIterator;
use Mattmy\OfficeConverter\Enums\InputFormat;
use Mattmy\OfficeConverter\Exceptions\InvalidOfficeInput;
use RuntimeException;
use Throwable;

/**
 * Owns one isolated input snapshot and all generated artifacts.
 *
 * @internal
 */
final class Workspace
{
    private const COPY_CHUNK_BYTES = 1_048_576;

    private bool $cleaned = false;

    private ?string $inputPath = null;

    private ?string $outputDirectory = null;

    /**
     * Create an empty package-owned workspace.
     *
     * @param  Closure(string, bool): bool  $deletePath
     */
    private function __construct(
        private readonly string $parentDirectory,
        private readonly string $directory,
        private readonly Closure $deletePath,
    ) {}

    /**
     * Remove an abandoned workspace as a final safety net.
     */
    public function __destruct()
    {
        $this->cleanup();
    }

    /**
     * Create a unique private workspace below validated configuration.
     *
     * @param  null|Closure(string, bool): bool  $deletePath
     */
    public static function create(Configuration $configuration, ?Closure $deletePath = null): self
    {
        try {
            $directory = \rtrim($configuration->temporaryDirectory, \DIRECTORY_SEPARATOR) . \DIRECTORY_SEPARATOR . \bin2hex(\random_bytes(16));
        } catch (Throwable $exception) {
            throw new RuntimeException('Unable to generate a private workspace name.', previous: $exception);
        }

        if (! \mkdir($directory, 0700)) {
            throw new RuntimeException('Unable to create a private conversion workspace.');
        }

        return new self(
            $configuration->temporaryDirectory,
            $directory,
            $deletePath ?? self::nativeDeletePath(),
        );
    }

    /**
     * Determine whether a path is an absolute local path on this platform.
     */
    public static function isAbsoluteLocalPath(string $path): bool
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            return \preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
        }

        return \str_starts_with($path, '/');
    }

    /**
     * Return the package-owned workspace directory.
     */
    public function directory(): string
    {
        return $this->directory;
    }

    /**
     * Return the input snapshot path after it has been written.
     */
    public function inputPath(): string
    {
        if ($this->inputPath === null) {
            throw new RuntimeException('The workspace has no input snapshot.');
        }

        return $this->inputPath;
    }

    /**
     * Return the private output directory after it has been created.
     */
    public function outputDirectory(): string
    {
        if ($this->outputDirectory === null) {
            throw new RuntimeException('The workspace has no output directory.');
        }

        return $this->outputDirectory;
    }

    /**
     * Snapshot in-memory bytes without another full string copy.
     *
     * @throws InvalidOfficeInput
     */
    public function write(string $contents, InputFormat $format, int $maxBytes): void
    {
        if ($contents === '' || \strlen($contents) > $maxBytes) {
            throw new InvalidOfficeInput('The office input is empty or exceeds the configured byte limit.');
        }

        $this->inputPath = $this->directory . \DIRECTORY_SEPARATOR . 'input.' . $format->value;
        if (\file_put_contents($this->inputPath, $contents) !== \strlen($contents)) {
            throw new InvalidOfficeInput('The office input could not be snapshotted.');
        }
    }

    /**
     * Stream a caller-owned local file into this workspace.
     *
     * @throws InvalidOfficeInput
     */
    public function copy(string $source, InputFormat $format, int $maxBytes): void
    {
        $input = \fopen($source, 'rb');
        if (! \is_resource($input)) {
            throw new InvalidOfficeInput('The office input is not readable.');
        }

        $written = 0;

        try {
            $this->inputPath = $this->directory . \DIRECTORY_SEPARATOR . 'input.' . $format->value;
            $output = \fopen($this->inputPath, 'xb');
            if (! \is_resource($output)) {
                throw new InvalidOfficeInput('The office input snapshot could not be created.');
            }

            while (! \feof($input)) {
                $chunk = \fread($input, self::COPY_CHUNK_BYTES);
                if (! \is_string($chunk)) {
                    throw new InvalidOfficeInput('The office input could not be read.');
                }

                $written += \strlen($chunk);
                if ($written > $maxBytes) {
                    throw new InvalidOfficeInput('The office input exceeds the configured byte limit.');
                }

                if ($chunk !== '' && \fwrite($output, $chunk) !== \strlen($chunk)) {
                    throw new InvalidOfficeInput('The office input snapshot could not be written.');
                }
            }
        } finally {
            \fclose($input);
            if (isset($output) && \is_resource($output)) {
                \fclose($output);
            }
        }

        if ($written === 0) {
            throw new InvalidOfficeInput('The office input is empty.');
        }
    }

    /**
     * Create the unique directory passed to LibreOffice as its output target.
     */
    public function createOutputDirectory(): void
    {
        $this->outputDirectory = $this->directory . \DIRECTORY_SEPARATOR . 'output';
        if (! \mkdir($this->outputDirectory, 0700)) {
            throw new RuntimeException('Unable to create the conversion output directory.');
        }
    }

    /**
     * Find exactly one canonical artifact and reject every sidecar or subdirectory.
     */
    public function singleArtifact(string $extension): string
    {
        $outputDirectory = $this->outputDirectory();
        \clearstatcache(true, $outputDirectory);
        if (\is_link($outputDirectory) || ! \is_dir($outputDirectory)
            || \realpath($outputDirectory) !== $outputDirectory || ! $this->owns($outputDirectory)) {
            throw new RuntimeException('The conversion output directory escaped its workspace.');
        }

        $artifact = null;
        foreach (new FilesystemIterator($outputDirectory, FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_PATHNAME) as $path) {
            if ($artifact !== null) {
                throw new RuntimeException('LibreOffice did not produce exactly one expected output artifact.');
            }

            $artifact = $path;
        }

        if (! \is_string($artifact)
            || \is_link($artifact)
            || ! \is_file($artifact)
            || ! $this->owns($artifact)
            || \strtolower(\pathinfo($artifact, \PATHINFO_EXTENSION)) !== $extension) {
            throw new RuntimeException('LibreOffice did not produce exactly one expected output artifact.');
        }

        return $artifact;
    }

    /**
     * Remove the input snapshot after successful conversion.
     */
    public function removeInput(): void
    {
        if ($this->inputPath === null || \is_link($this->inputPath) || ! \is_file($this->inputPath) || ! $this->owns($this->inputPath)
            || ! ($this->deletePath)($this->inputPath, false)) {
            throw new RuntimeException('The conversion input snapshot could not be removed.');
        }

        $this->inputPath = null;
    }

    /**
     * Determine whether an existing path remains inside this workspace.
     */
    public function owns(string $path): bool
    {
        $directory = $this->resolvedDirectory();
        $realPath = \realpath($path);

        return \is_string($directory)
            && \is_string($realPath)
            && \str_starts_with($realPath, $directory . \DIRECTORY_SEPARATOR);
    }

    /**
     * Delete only this package-owned workspace without following symlinks.
     */
    public function cleanup(): void
    {
        if ($this->cleaned) {
            return;
        }

        try {
            $this->cleanupOwnedDirectory();
            $this->cleaned = true;
        } catch (Throwable) {
            // Cleanup must never replace the operation's primary exception.
        }
    }

    /**
     * Delete this workspace after containment has been verified.
     */
    private function cleanupOwnedDirectory(): void
    {
        \clearstatcache(true, $this->parentDirectory);
        if (\realpath($this->parentDirectory) !== $this->parentDirectory) {
            throw new RuntimeException('The conversion workspace escaped its configured parent.');
        }

        \clearstatcache(true, $this->directory);
        if (\is_link($this->directory)) {
            if (! ($this->deletePath)($this->directory, false)) {
                throw new RuntimeException('Unable to remove a symlinked conversion workspace.');
            }
        } else {
            $this->removeDirectory($this->directory);
        }

        \clearstatcache(true, $this->directory);
        if (\file_exists($this->directory) || \is_link($this->directory)) {
            throw new RuntimeException('Unable to remove the conversion workspace.');
        }
    }

    /**
     * Recursively remove known package-owned entries without following symlinks.
     */
    private function removeDirectory(string $directory): void
    {
        \clearstatcache(true, $directory);
        // Windows junctions can appear as directories to is_link().
        if (\realpath($directory) === $directory) {
            $items = \scandir($directory, \SCANDIR_SORT_NONE);
            foreach ($items === false ? [] : $items as $item) {
                if (\in_array($item, ['.', '..'], true)) {
                    continue;
                }

                $path = $directory . \DIRECTORY_SEPARATOR . $item;
                if (\is_dir($path) && ! \is_link($path)) {
                    $this->removeDirectory($path);
                } elseif (! ($this->deletePath)($path, false)) {
                    throw new RuntimeException('Unable to remove a conversion workspace entry.');
                }
            }
        }

        if (! ($this->deletePath)($directory, true)) {
            throw new RuntimeException('Unable to remove a conversion workspace directory.');
        }
    }

    /**
     * Resolve the workspace only when it remains below its configured parent.
     */
    private function resolvedDirectory(): ?string
    {
        $parent = \realpath($this->parentDirectory);
        $directory = \realpath($this->directory);

        return $parent === $this->parentDirectory
            && $directory === $this->directory
            && \str_starts_with($directory, \rtrim($parent, \DIRECTORY_SEPARATOR) . \DIRECTORY_SEPARATOR)
            ? $directory
            : null;
    }

    /**
     * Delete one owned filesystem entry with the native PHP operation.
     *
     * @return Closure(string, bool): bool
     */
    private static function nativeDeletePath(): Closure
    {
        return static fn (string $path, bool $directory): bool => $directory
            || (\PHP_OS_FAMILY === 'Windows' && \is_link($path) && \is_dir($path))
            ? \rmdir($path)
            : \unlink($path);
    }
}
