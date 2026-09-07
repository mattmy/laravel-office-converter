<?php

declare(strict_types=1);

namespace Mattmy\OfficeConverter;

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
        if (! $this->isAbsolutePath($path) || \is_link($path) || ! \is_file($path) || ! \is_readable($path)) {
            throw new InvalidOfficeInput('The office input path must be an absolute readable non-symlink regular file.');
        }

        $realPath = \realpath($path);
        if (! \is_string($realPath)) {
            throw new InvalidOfficeInput('The office input path cannot be resolved.');
        }

        $format = $this->formatFromFilename(\basename($path));

        return $this->fromFile($realPath, $format, \pathinfo(\basename($path), PATHINFO_FILENAME));
    }

    /**
     * Snapshot raw bytes with an explicit unambiguous format.
     *
     * @throws EnvironmentUnavailable
     * @throws InvalidOfficeInput
     */
    public function fromContent(string $content, InputFormat $format): OfficeDocument
    {
        $configuration = $this->configuration();
        $workspace = $this->workspace($configuration);

        try {
            $workspace->write($content, $format, $configuration->maxInputBytes);
            $this->validator->input($workspace->inputPath(), $format, $configuration->maxInputBytes);

            return $this->document($workspace, $configuration, $format, null);
        } catch (Throwable $exception) {
            $workspace->cleanup();

            throw $exception;
        }
    }

    /**
     * Snapshot a valid Laravel uploaded file using its original extension as a candidate.
     *
     * @throws EnvironmentUnavailable
     * @throws InvalidOfficeInput
     */
    public function fromUploadedFile(UploadedFile $file): OfficeDocument
    {
        if (! $file->isValid()) {
            throw new InvalidOfficeInput('The uploaded office document is invalid.');
        }

        $path = $file->getRealPath();
        if (! \is_string($path) || ! \is_file($path) || ! \is_readable($path)) {
            throw new InvalidOfficeInput('The uploaded office document is not readable.');
        }

        $name = $file->getClientOriginalName();
        $format = $this->formatFromFilename($name);

        return $this->fromFile($path, $format, \pathinfo($name, PATHINFO_FILENAME));
    }

    /**
     * Snapshot a validated caller-owned file.
     */
    private function fromFile(string $path, InputFormat $format, string $stem): OfficeDocument
    {
        $configuration = $this->configuration();
        $workspace = $this->workspace($configuration);

        try {
            $workspace->copy($path, $format, $configuration->maxInputBytes);
            $this->validator->input($workspace->inputPath(), $format, $configuration->maxInputBytes);

            return $this->document($workspace, $configuration, $format, $stem);
        } catch (Throwable $exception) {
            $workspace->cleanup();

            throw $exception;
        }
    }

    /**
     * Create the one-time domain object that assumes workspace ownership.
     */
    private function document(
        Workspace $workspace,
        Configuration $configuration,
        InputFormat $format,
        ?string $stem,
    ): OfficeDocument {
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
        $extension = \pathinfo($filename, PATHINFO_EXTENSION);
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
        } catch (RuntimeException $exception) {
            throw new EnvironmentUnavailable('A private conversion workspace could not be created.', previous: $exception);
        }
    }

    /**
     * Determine whether a path is an absolute local path on this platform.
     */
    private function isAbsolutePath(string $path): bool
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
