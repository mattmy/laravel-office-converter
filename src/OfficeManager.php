<?php

declare(strict_types=1);

namespace Mattmy\OfficeConverter;

use Closure;
use ErrorException;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Http\UploadedFile;
use Mattmy\OfficeConverter\Enums\InputFormat;
use Mattmy\OfficeConverter\Exceptions\EnvironmentUnavailable;
use Mattmy\OfficeConverter\Exceptions\InvalidOfficeInput;
use Mattmy\OfficeConverter\Internal\Configuration;
use Mattmy\OfficeConverter\Internal\FileValidator;
use Mattmy\OfficeConverter\Internal\FormatDefinition;
use Mattmy\OfficeConverter\Internal\ProcessRunner;
use Mattmy\OfficeConverter\Internal\Workspace;
use RuntimeException;
use Throwable;

/**
 * Creates validated one-time office document snapshots.
 *
 * @internal
 */
final class OfficeManager
{
    /**
     * Create the stateless facade manager.
     */
    public function __construct(
        private readonly ProcessRunner $processRunner,
        private readonly FilesystemManager $filesystems,
        private readonly FileValidator $validator,
    ) {}

    /**
     * Snapshot an absolute non-symlink local document path.
     *
     * @throws EnvironmentUnavailable
     * @throws InvalidOfficeInput
     */
    public function fromPath(string $path): OfficeDocument
    {
        try {
            if (! Workspace::isAbsoluteLocalPath($path) || \is_link($path) || ! \is_file($path) || ! \is_readable($path)) {
                throw new InvalidOfficeInput('The office input path must be an absolute readable non-symlink regular file.');
            }

            $realPath = \realpath($path);
        } catch (ErrorException $exception) {
            throw new InvalidOfficeInput('The office input path could not be inspected.', previous: $exception);
        }

        if (! \is_string($realPath)) {
            throw new InvalidOfficeInput('The office input path cannot be resolved.');
        }

        $filename = \basename($path);
        $format = $this->formatFromFilename($filename);

        return $this->fromFile($realPath, $format, \pathinfo($filename, \PATHINFO_FILENAME));
    }

    /**
     * Snapshot raw bytes with an explicit unambiguous format.
     *
     * @throws EnvironmentUnavailable
     * @throws InvalidOfficeInput
     */
    public function fromContent(string $content, InputFormat $format): OfficeDocument
    {
        return $this->snapshot($format, null, static function (Workspace $workspace, int $maxBytes) use ($content, $format): void {
            $workspace->write($content, $format, $maxBytes);
        });
    }

    /**
     * Snapshot a valid Laravel uploaded file using its original extension as a candidate.
     *
     * @throws EnvironmentUnavailable
     * @throws InvalidOfficeInput
     */
    public function fromUploadedFile(UploadedFile $file): OfficeDocument
    {
        try {
            if (! $file->isValid()) {
                throw new InvalidOfficeInput('The uploaded office document is invalid.');
            }

            $path = $file->getRealPath();
            if (! \is_string($path) || ! \is_file($path) || ! \is_readable($path)) {
                throw new InvalidOfficeInput('The uploaded office document is not readable.');
            }
        } catch (ErrorException $exception) {
            throw new InvalidOfficeInput('The uploaded office document could not be inspected.', previous: $exception);
        }

        $name = $file->getClientOriginalName();
        $format = $this->formatFromFilename($name);

        return $this->fromFile($path, $format, \pathinfo($name, \PATHINFO_FILENAME));
    }

    /**
     * Snapshot a validated caller-owned file.
     */
    private function fromFile(string $path, InputFormat $format, string $stem): OfficeDocument
    {
        return $this->snapshot($format, $stem, static function (Workspace $workspace, int $maxBytes) use ($path, $format): void {
            $workspace->copy($path, $format, $maxBytes);
        });
    }

    /**
     * Create a workspace and dispose of it if writing or validating the snapshot fails.
     *
     * @param  Closure(Workspace, int): void  $write
     */
    private function snapshot(InputFormat $format, ?string $stem, Closure $write): OfficeDocument
    {
        $configuration = $this->configuration();
        $workspace = $this->workspace($configuration);

        try {
            $write($workspace, $configuration->maxInputBytes);

            return $this->document($workspace, $configuration, $format, $stem);
        } catch (Throwable $exception) {
            $workspace->cleanup();

            if ($exception instanceof ErrorException) {
                throw new InvalidOfficeInput('The office input could not be snapshotted or validated.', previous: $exception);
            }

            throw $exception;
        }
    }

    /**
     * Validate the owned snapshot before handing it to the one-time document.
     */
    private function document(
        Workspace $workspace,
        Configuration $configuration,
        InputFormat $format,
        ?string $stem,
    ): OfficeDocument {
        $input = $workspace->inputPath();
        if (! $workspace->owns($input)) {
            throw new InvalidOfficeInput('The office input snapshot escaped its workspace.');
        }

        $this->validator->input($input, $format, $configuration->maxInputBytes);

        return new OfficeDocument(
            $workspace,
            $configuration,
            $format,
            $stem,
            $this->processRunner,
            $this->filesystems,
            $this->validator,
        );
    }

    /**
     * Resolve an accepted extension from the final filename segment.
     *
     * @throws InvalidOfficeInput
     */
    private function formatFromFilename(string $filename): InputFormat
    {
        $extension = \pathinfo($filename, \PATHINFO_EXTENSION);
        $format = FormatDefinition::inputFromExtension($extension);
        if ($format === null) {
            throw new InvalidOfficeInput('The office input filename has an unsupported extension.');
        }

        return $format;
    }

    /**
     * Read and validate immutable configuration for one factory operation.
     */
    private function configuration(): Configuration
    {
        return Configuration::from(config('office-converter'));
    }

    /**
     * Create a workspace while mapping environmental filesystem failures.
     */
    private function workspace(Configuration $configuration): Workspace
    {
        try {
            return Workspace::create($configuration);
        } catch (ErrorException|RuntimeException $exception) {
            throw new EnvironmentUnavailable('A private conversion workspace could not be created.', previous: $exception);
        }
    }
}
