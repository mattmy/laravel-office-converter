<?php

declare(strict_types=1);

namespace Mattmy\OfficeConverter;

use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Http\File;
use InvalidArgumentException;
use Mattmy\OfficeConverter\Enums\Format;
use Mattmy\OfficeConverter\Exceptions\AlreadyConsumed;
use Mattmy\OfficeConverter\Exceptions\ConversionFailed;
use Mattmy\OfficeConverter\Internal\FileValidator;
use Mattmy\OfficeConverter\Internal\Workspace;

/**
 * Owns one generated artifact until its first terminal operation.
 */
final class ConvertedOffice
{
    private const string READY = 'ready';

    private const string CONSUMED = 'consumed';

    private string $state = self::READY;

    /**
     * Create a one-time result that assumes workspace ownership.
     *
     * @internal
     */
    public function __construct(
        private readonly Workspace $workspace,
        private readonly string $artifact,
        private readonly Format $format,
        private readonly string $extension,
        private readonly ?string $sourceStem,
        private readonly int $maxOutputBytes,
        private readonly FilesystemManager $filesystems,
        private readonly FileValidator $validator,
    ) {}

    /**
     * Remove an artifact abandoned before terminal consumption.
     */
    public function __destruct()
    {
        $this->workspace->cleanup();
    }

    /**
     * Read all output bytes and then remove package-owned resources.
     *
     * @throws AlreadyConsumed
     * @throws ConversionFailed
     */
    public function output(): string
    {
        $this->beginConsumption();

        try {
            $this->assertOutputFile();
            $contents = \file_get_contents($this->artifact);
            if (! \is_string($contents)) {
                throw new ConversionFailed('The converted office artifact could not be read.');
            }

            return $contents;
        } finally {
            $this->finishConsumption();
        }
    }

    /**
     * Stream the artifact to Laravel Storage and preserve the disk result semantics.
     *
     * @throws AlreadyConsumed
     * @throws ConversionFailed
     * @throws InvalidArgumentException
     */
    public function storeAs(string $path, ?string $filename = null, ?string $disk = null): string|false
    {
        $this->beginConsumption();

        try {
            [$directory, $name] = $this->validateStorageDestination($path, $filename);
            $this->assertOutputFile();

            return $this->filesystems->disk($disk)->putFileAs($directory, new File($this->artifact), $name);
        } finally {
            $this->finishConsumption();
        }
    }

    /**
     * Mark the terminal operation before it performs any validation or I/O.
     *
     * @throws AlreadyConsumed
     */
    private function beginConsumption(): void
    {
        if ($this->state !== self::READY) {
            throw new AlreadyConsumed('This converted office artifact has already been consumed.');
        }

        $this->state = self::CONSUMED;
    }

    /**
     * Validate and normalize a relative Storage directory and package-controlled filename.
     *
     * @return array{string, string}
     *
     * @throws InvalidArgumentException
     */
    private function validateStorageDestination(string $path, ?string $filename): array
    {
        if (\str_contains($path, "\0")
            || \str_contains($path, '\\')
            || \preg_match('/[\x00-\x1F\x7F]/', $path) === 1
            || \str_starts_with($path, '/')
            || \preg_match('/^[A-Za-z]:/', $path) === 1) {
            throw new InvalidArgumentException('The Storage directory must be a safe relative path.');
        }

        $directory = \rtrim($path, '/');
        if ($directory !== '') {
            foreach (\explode('/', $directory) as $segment) {
                if (\in_array($segment, ['', '.', '..'], true)) {
                    throw new InvalidArgumentException('The Storage directory must not contain traversal or empty segments.');
                }
            }
        }

        if ($filename !== null) {
            $name = $this->validatedFilename($filename);
        } else {
            try {
                $name = $this->sourceStem === null ? null : $this->validatedFilename($this->sourceStem);
            } catch (InvalidArgumentException) {
                $name = null;
            }

            $name ??= 'converted-' . \bin2hex(\random_bytes(8));
        }

        return [$directory, $this->filenameWithExtension($name)];
    }

    /**
     * Return a safe non-empty user-provided filename.
     *
     * @throws InvalidArgumentException
     */
    private function validatedFilename(string $filename): string
    {
        if (\in_array($filename, ['', '.', '..'], true)
            || \str_contains($filename, '/')
            || \str_contains($filename, '\\')
            || \preg_match('/[\x00-\x1F\x7F]/', $filename) === 1) {
            throw new InvalidArgumentException('The Storage filename is invalid.');
        }

        return $filename;
    }

    /**
     * Append the trusted output extension without discarding user filename text.
     */
    private function filenameWithExtension(string $filename): string
    {
        $suffix = '.' . $this->extension;
        if (\str_ends_with(\strtolower($filename), $suffix)) {
            return \substr($filename, 0, -\strlen($suffix)) . $suffix;
        }

        return $filename . $suffix;
    }

    /**
     * Revalidate containment, file type, byte limit, and target structure before I/O.
     *
     * @throws ConversionFailed
     */
    private function assertOutputFile(): void
    {
        if (! $this->workspace->owns($this->artifact) || \is_link($this->artifact)) {
            throw new ConversionFailed('The converted office artifact is no longer a safe workspace file.');
        }

        $this->validator->output($this->artifact, $this->format, $this->maxOutputBytes);
    }

    /**
     * Clean the workspace after every terminal success or failure.
     */
    private function finishConsumption(): void
    {
        $this->workspace->cleanup();
        $this->state = self::CONSUMED;
    }
}
