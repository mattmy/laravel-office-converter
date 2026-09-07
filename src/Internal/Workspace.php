<?php

declare(strict_types=1);

namespace Mattmy\OfficeConverter\Internal;

use Closure;
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
    private const int COPY_CHUNK_BYTES = 1_048_576;

    private bool $cleaned = false;

    private ?string $inputPath = null;

    private ?string $outputDirectory = null;

    /**
     * Create an empty package-owned workspace.
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
            $directory = $configuration->temporaryDirectory . DIRECTORY_SEPARATOR . \bin2hex(\random_bytes(16));
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

        $this->inputPath = $this->directory . DIRECTORY_SEPARATOR . 'input.' . FormatDefinition::inputExtension($format);
        if (\file_put_contents($this->inputPath, $contents, LOCK_EX) !== \strlen($contents)) {
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

        $this->inputPath = $this->directory . DIRECTORY_SEPARATOR . 'input.' . FormatDefinition::inputExtension($format);
        $output = \fopen($this->inputPath, 'xb');
        if (! \is_resource($output)) {
            \fclose($input);

            throw new InvalidOfficeInput('The office input snapshot could not be created.');
        }

        $written = 0;

        try {
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
            \fclose($output);
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
        $this->outputDirectory = $this->directory . DIRECTORY_SEPARATOR . 'output';
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
        if (\is_link($outputDirectory) || ! \is_dir($outputDirectory) || ! $this->owns($outputDirectory)) {
            throw new RuntimeException('The conversion output directory escaped its workspace.');
        }

        $items = \scandir($outputDirectory);
        if (! \is_array($items)) {
            throw new RuntimeException('The conversion output directory cannot be read.');
        }

        $paths = [];
        foreach ($items as $item) {
            if (\in_array($item, ['.', '..'], true)) {
                continue;
            }

            $paths[] = $outputDirectory . DIRECTORY_SEPARATOR . $item;
        }

        if (\count($paths) !== 1
            || \is_link($paths[0])
            || ! \is_file($paths[0])
            || ! $this->owns($paths[0])
            || \strtolower(\pathinfo($paths[0], PATHINFO_EXTENSION)) !== $extension) {
            throw new RuntimeException('LibreOffice did not produce exactly one expected output artifact.');
        }

        return $paths[0];
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
            && \str_starts_with($realPath, $directory . DIRECTORY_SEPARATOR);
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
        if (\is_link($this->directory)) {
            if (! ($this->deletePath)($this->directory, false)) {
                throw new RuntimeException('Unable to remove a symlinked conversion workspace.');
            }

            return;
        }

        $directory = $this->resolvedDirectory();
        if ($directory === null) {
            throw new RuntimeException('The conversion workspace escaped its configured parent.');
        }

        $this->removeDirectory($directory);
        if (\file_exists($directory) || \is_link($directory)) {
            throw new RuntimeException('Unable to remove the conversion workspace.');
        }
    }

    /**
     * Recursively remove known package-owned entries without following symlinks.
     */
    private function removeDirectory(string $directory): void
    {
        $items = \scandir($directory);
        if (\is_array($items)) {
            foreach ($items as $item) {
                if (\in_array($item, ['.', '..'], true)) {
                    continue;
                }

                $path = $directory . DIRECTORY_SEPARATOR . $item;
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

        return \is_string($parent)
            && \is_string($directory)
            && \str_starts_with($directory, $parent . DIRECTORY_SEPARATOR)
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
            ? \rmdir($path)
            : \unlink($path);
    }
}
