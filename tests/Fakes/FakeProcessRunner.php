<?php

declare(strict_types=1);

namespace Mattmy\OfficeConverter\Tests\Fakes;

use Closure;
use Mattmy\OfficeConverter\Enums\Format;
use Mattmy\OfficeConverter\Enums\InputFormat;
use Mattmy\OfficeConverter\Internal\ProcessRunner;
use Mattmy\OfficeConverter\Internal\Workspace;
use Mattmy\OfficeConverter\Tests\Fixtures\OfficeFixture;
use Override;
use RuntimeException;

/**
 * Records commands and produces controlled artifacts without LibreOffice.
 */
final class FakeProcessRunner implements ProcessRunner
{
    /** @var list<list<string>> */
    public array $commands = [];

    /** @var list<string> */
    public array $inputSnapshots = [];

    public ?string $artifact = null;

    /**
     * Create a fake around one test-controlled process behavior.
     *
     * @param  Closure(list<string>, Workspace): void  $run
     */
    public function __construct(private readonly Closure $run) {}

    /**
     * Create a fake that writes one structurally valid output artifact.
     */
    public static function writes(Format $format): self
    {
        return new self(static function (array $_command, Workspace $workspace) use ($format): void {
            $inputFormat = match ($format) {
                Format::PDF => InputFormat::PDF,
                Format::ODT => InputFormat::ODT,
                Format::DOCX => InputFormat::DOCX,
                Format::RTF => InputFormat::RTF,
                Format::TXT => InputFormat::TXT,
                Format::HTML => InputFormat::HTML,
                Format::ODS => InputFormat::ODS,
                Format::XLSX => InputFormat::XLSX,
                Format::CSV => InputFormat::CSV,
                Format::ODP => InputFormat::ODP,
                Format::PPTX => InputFormat::PPTX,
                Format::ODG => InputFormat::ODG,
                Format::PNG => InputFormat::PNG,
                Format::JPEG => InputFormat::JPEG,
                Format::SVG => InputFormat::SVG,
                Format::WEBP => InputFormat::WEBP,
            };
            $fixture = OfficeFixture::create($inputFormat);
            $artifact = $workspace->outputDirectory() . DIRECTORY_SEPARATOR . 'input.' . $format->value;

            try {
                if (! \copy($fixture, $artifact)) {
                    throw new RuntimeException('Unable to write the fake process artifact.');
                }
            } finally {
                OfficeFixture::remove($fixture);
            }
        });
    }

    /**
     * Record argv and invoke the test behavior.
     *
     * @param  list<string>  $command
     */
    #[Override]
    public function run(array $command, Workspace $workspace, float $timeout): void
    {
        $this->commands[] = $command;
        $snapshot = \file_get_contents($workspace->inputPath());
        if (! \is_string($snapshot)) {
            throw new RuntimeException('Unable to inspect the package input snapshot.');
        }

        $this->inputSnapshots[] = $snapshot;
        ($this->run)($command, $workspace);

        $items = \scandir($workspace->outputDirectory());
        if (\is_array($items)) {
            foreach ($items as $item) {
                $path = $workspace->outputDirectory() . DIRECTORY_SEPARATOR . $item;
                if ($item !== '.' && $item !== '..' && \is_file($path)) {
                    $this->artifact = $path;

                    break;
                }
            }
        }
    }
}
