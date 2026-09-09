<?php

declare(strict_types=1);

use Mattmy\OfficeConverter\Enums\Format;
use Mattmy\OfficeConverter\Enums\InputFormat;
use Mattmy\OfficeConverter\Exceptions\AlreadyConsumed;
use Mattmy\OfficeConverter\Exceptions\ConversionFailed;
use Mattmy\OfficeConverter\Exceptions\UnsupportedConversion;
use Mattmy\OfficeConverter\Facades\Office;
use Mattmy\OfficeConverter\Internal\FormatDefinition;
use Mattmy\OfficeConverter\Internal\ProcessRunner;
use Mattmy\OfficeConverter\Internal\Workspace;
use Mattmy\OfficeConverter\OfficeManager;
use Mattmy\OfficeConverter\Tests\Fakes\FakeProcessRunner;
use Mattmy\OfficeConverter\Tests\Fixtures\OfficeFixture;

it('builds the expected argv for every accepted family output', function (): void {
    $families = [
        [InputFormat::TXT, [Format::PDF, Format::ODT, Format::DOCX, Format::RTF, Format::TXT, Format::HTML]],
        [InputFormat::CSV, [Format::PDF, Format::ODS, Format::XLSX, Format::CSV]],
        [InputFormat::PPT, [Format::PDF, Format::ODP, Format::PPTX]],
        [InputFormat::SVG, [Format::PDF, Format::ODG, Format::PNG, Format::JPEG, Format::SVG, Format::WEBP]],
    ];

    foreach ($families as [$input, $formats]) {
        $source = OfficeFixture::create($input);
        $content = \file_get_contents($source);
        OfficeFixture::remove($source);
        if (! \is_string($content)) {
            throw new RuntimeException('Unable to read the source fixture.');
        }

        foreach ($formats as $format) {
            $runner = FakeProcessRunner::writes($format);
            app()->instance(ProcessRunner::class, $runner);
            app()->forgetInstance(OfficeManager::class);
            Office::clearResolvedInstance(OfficeManager::class);
            Office::fromContent($content, $input)->convertTo($format)->output();
            $definition = FormatDefinition::output($input, $format);
            if ($definition === null) {
                throw new RuntimeException('The accepted matrix is missing an output definition.');
            }
            $convertToIndex = \array_search('--convert-to', $runner->commands[0], true);

            expect($convertToIndex)->not->toBeFalse()
                ->and($runner->commands[0][$convertToIndex + 1] ?? null)->toStartWith(
                    $definition['extension'] . ':' . $definition['filter'],
                );
        }
    }
});

it('consumes an unsupported family conversion failure', function (): void {
    $document = Office::fromContent('valid text', InputFormat::TXT);

    expect(fn () => $document->convertTo(Format::PNG))->toThrow(UnsupportedConversion::class)
        ->and(fn () => $document->convertTo(Format::PDF))->toThrow(AlreadyConsumed::class);
});

it('does not add a page-selection option to Draw image conversion argv', function (): void {
    $source = OfficeFixture::create(InputFormat::SVG);
    $content = \file_get_contents($source);
    OfficeFixture::remove($source);
    if (! \is_string($content)) {
        throw new RuntimeException('Unable to read the Draw fixture.');
    }

    $runner = FakeProcessRunner::writes(Format::PNG);
    app()->instance(ProcessRunner::class, $runner);

    Office::fromContent($content, InputFormat::SVG)->convertTo(Format::PNG)->output();

    expect(\implode(' ', $runner->commands[0]))->not->toContain('PageNumber');
});

it('cleans the workspace after a process failure owned by a document', function (): void {
    $runner = new FakeProcessRunner(static function (): void {
        throw new ConversionFailed('The process failed.');
    });
    app()->instance(ProcessRunner::class, $runner);

    expect(fn () => Office::fromContent('valid text', InputFormat::TXT)->convertTo(Format::PDF))
        ->toThrow(ConversionFailed::class)
        ->and(\scandir(config()->string('office-converter.temporary_directory')))->toBe(['.', '..']);
});

it('rejects invalid initial process artifacts and cleans their workspaces', function (string $artifact): void {
    config()->set('office-converter.max_output_bytes', 1024);
    $runner = new FakeProcessRunner(static function (array $_command, Workspace $workspace) use ($artifact): void {
        $path = $workspace->outputDirectory() . DIRECTORY_SEPARATOR . 'input.pdf';

        match ($artifact) {
            'empty' => \file_put_contents($path, ''),
            'oversized' => \file_put_contents($path, \str_repeat('x', 2048)),
            'invalid' => \file_put_contents($path, 'not a pdf'),
            default => throw new RuntimeException('Unknown initial artifact mode.'),
        };
    });
    app()->instance(ProcessRunner::class, $runner);

    expect(fn () => Office::fromContent('valid text', InputFormat::TXT)->convertTo(Format::PDF))
        ->toThrow(ConversionFailed::class)
        ->and(\scandir(config()->string('office-converter.temporary_directory')))->toBe(['.', '..']);
})->with(['empty', 'oversized', 'invalid']);

it('rejects missing and extra process artifacts and cleans their workspaces', function (Closure $behavior): void {
    $runner = new FakeProcessRunner($behavior);
    app()->instance(ProcessRunner::class, $runner);
    app()->forgetInstance(OfficeManager::class);
    Office::clearResolvedInstance(OfficeManager::class);

    expect(fn () => Office::fromContent('valid text', InputFormat::TXT)->convertTo(Format::PDF))
        ->toThrow(ConversionFailed::class)
        ->and(\scandir(config()->string('office-converter.temporary_directory')))->toBe(['.', '..']);
})->with([
    'missing' => fn (): Closure => static function (): void {},
    'extra' => fn (): Closure => static function (array $_command, Workspace $workspace): void {
        \file_put_contents($workspace->outputDirectory() . DIRECTORY_SEPARATOR . 'input.pdf', "%PDF-1.7\n%%EOF");
        \file_put_contents($workspace->outputDirectory() . DIRECTORY_SEPARATOR . 'sidecar.png', 'extra');
    },
]);
