<?php

declare(strict_types=1);

namespace Mattmy\OfficeConverter;

use Illuminate\Filesystem\FilesystemManager;
use Mattmy\OfficeConverter\Enums\Format;
use Mattmy\OfficeConverter\Enums\InputFormat;
use Mattmy\OfficeConverter\Exceptions\AlreadyConsumed;
use Mattmy\OfficeConverter\Exceptions\ConversionFailed;
use Mattmy\OfficeConverter\Exceptions\EnvironmentUnavailable;
use Mattmy\OfficeConverter\Exceptions\UnsupportedConversion;
use Mattmy\OfficeConverter\Internal\Configuration;
use Mattmy\OfficeConverter\Internal\FileValidator;
use Mattmy\OfficeConverter\Internal\FormatDefinition;
use Mattmy\OfficeConverter\Internal\ProcessRunner;
use Mattmy\OfficeConverter\Internal\Workspace;
use RuntimeException;

/**
 * Owns one immutable input snapshot until its first conversion attempt.
 */
final class OfficeDocument
{
    private bool $consumed = false;

    /**
     * Create a package-owned one-time document.
     *
     * @internal
     */
    public function __construct(
        private ?Workspace $workspace,
        private readonly Configuration $configuration,
        private readonly InputFormat $inputFormat,
        private readonly ?string $sourceStem,
        private readonly ProcessRunner $processRunner,
        private readonly FilesystemManager $filesystems,
        private readonly FileValidator $validator,
    ) {}

    /**
     * Remove an input snapshot abandoned before conversion.
     */
    public function __destruct()
    {
        $this->workspace?->cleanup();
    }

    /**
     * Convert the snapshot once to an allowed single-file target.
     *
     * @throws AlreadyConsumed
     * @throws ConversionFailed
     * @throws EnvironmentUnavailable
     * @throws UnsupportedConversion
     */
    public function convertTo(Format $format): ConvertedOffice
    {
        if ($this->consumed || $this->workspace === null) {
            throw new AlreadyConsumed('This office document has already been consumed.');
        }

        $this->consumed = true;
        $workspace = $this->workspace;

        try {
            $definition = FormatDefinition::output($this->inputFormat, $format);
            if ($definition === null) {
                throw new UnsupportedConversion('The requested output format is not supported for this input family.');
            }

            $workspace->createOutputDirectory();
            $this->processRunner->run(
                $this->command($workspace, $definition),
                $workspace,
                $this->configuration->timeout,
            );

            $artifact = $workspace->singleArtifact($definition['extension']);
            $this->validator->output($artifact, $format, $this->configuration->maxOutputBytes);
            $workspace->removeInput();

            $result = new ConvertedOffice(
                $workspace,
                $artifact,
                $format,
                $definition['extension'],
                $this->sourceStem,
                $this->configuration->maxOutputBytes,
                $this->filesystems,
                $this->validator,
            );
            $this->workspace = null;

            return $result;
        } catch (EnvironmentUnavailable|ConversionFailed $exception) {
            throw $exception;
        } catch (RuntimeException $exception) {
            throw new ConversionFailed(
                'LibreOffice did not produce exactly one valid output artifact.',
                previous: $exception,
            );
        } finally {
            if ($this->workspace !== null) {
                $this->workspace->cleanup();
                $this->workspace = null;
            }
        }
    }

    /**
     * Build the fixed shell-free LibreOffice argument vector.
     *
     * @param  array{extension: string, filter: string, options?: string}  $definition
     * @return list<string>
     */
    private function command(
        Workspace $workspace,
        array $definition,
    ): array {
        $command = [
            $this->configuration->binary,
            '--headless',
            '--nologo',
            '--nodefault',
            '--norestore',
        ];
        $inputFilter = FormatDefinition::inputFilter($this->inputFormat);
        if ($inputFilter !== null) {
            $command[] = '--infilter=' . $inputFilter;
        }

        $convertTo = $definition['extension'] . ':' . $definition['filter'];
        if (isset($definition['options'])) {
            $convertTo .= ':' . $definition['options'];
        }

        $command[] = '--convert-to';
        $command[] = $convertTo;
        $command[] = '--outdir';
        $command[] = $workspace->outputDirectory();
        $command[] = $workspace->inputPath();

        return $command;
    }
}
