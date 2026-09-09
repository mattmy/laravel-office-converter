<?php

declare(strict_types=1);

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Facades\Storage;
use Mattmy\OfficeConverter\Enums\Format;
use Mattmy\OfficeConverter\Enums\InputFormat;
use Mattmy\OfficeConverter\Exceptions\AlreadyConsumed;
use Mattmy\OfficeConverter\Exceptions\ConversionFailed;
use Mattmy\OfficeConverter\Facades\Office;
use Mattmy\OfficeConverter\Internal\ProcessRunner;
use Mattmy\OfficeConverter\Tests\Fakes\FakeProcessRunner;
use PHPUnit\Framework\Assert;
use Symfony\Component\Process\Process;

use function Pest\Laravel\mock;

it('allows exactly one output read and cleans the workspace', function (): void {
    $runner = FakeProcessRunner::writes(Format::PDF);
    app()->instance(ProcessRunner::class, $runner);
    $output = Office::fromContent('valid text', InputFormat::TXT)->convertTo(Format::PDF);
    $bytes = $output->output();

    expect($bytes)->toStartWith('%PDF-')
        ->and(fn () => $output->output())->toThrow(AlreadyConsumed::class)
        ->and(\scandir(config()->string('office-converter.temporary_directory')))->toBe(['.', '..']);
});

it('revalidates an artifact immediately before terminal io', function (): void {
    $runner = FakeProcessRunner::writes(Format::PDF);
    app()->instance(ProcessRunner::class, $runner);
    $output = Office::fromContent('valid text', InputFormat::TXT)->convertTo(Format::PDF);
    if ($runner->artifact === null) {
        throw new RuntimeException('The fake did not expose its generated artifact.');
    }

    \file_put_contents($runner->artifact, 'not a pdf');

    expect(fn () => $output->output())->toThrow(ConversionFailed::class)
        ->and(fn () => $output->output())->toThrow(AlreadyConsumed::class);
});

it('rejects a symlinked artifact immediately before terminal io', function (): void {
    $runner = FakeProcessRunner::writes(Format::PDF);
    app()->instance(ProcessRunner::class, $runner);
    $output = Office::fromContent('valid text', InputFormat::TXT)->convertTo(Format::PDF);
    if ($runner->artifact === null) {
        throw new RuntimeException('The fake did not expose its generated artifact.');
    }

    $outside = \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'office-converter-artifact-' . \bin2hex(\random_bytes(8));
    if (\file_put_contents($outside, "%PDF-1.7\n%%EOF") === false) {
        throw new RuntimeException('Unable to create the external artifact fixture.');
    }

    try {
        if (! \unlink($runner->artifact)) {
            throw new RuntimeException('Unable to replace the generated artifact.');
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $link = new Process(['cmd', '/d', '/c', 'mklink', $runner->artifact, $outside]);
            $link->run();
            if (! $link->isSuccessful()) {
                Assert::markTestSkipped('Creating Windows file symbolic links requires Developer Mode or an elevated account.');
            }
        } elseif (! \symlink($outside, $runner->artifact)) {
            throw new RuntimeException('Unable to create the artifact symlink.');
        }

        expect(fn () => $output->output())->toThrow(ConversionFailed::class);
    } finally {
        if (\is_file($outside) && ! \unlink($outside)) {
            throw new RuntimeException('Unable to remove the external artifact fixture.');
        }
    }
});

it('rejects a tampered artifact before Storage io', function (string $tamper): void {
    Storage::fake('exports');
    config()->set('office-converter.max_output_bytes', 1024);
    $runner = FakeProcessRunner::writes(Format::PDF);
    app()->instance(ProcessRunner::class, $runner);
    $output = Office::fromContent('valid text', InputFormat::TXT)->convertTo(Format::PDF);
    if ($runner->artifact === null) {
        throw new RuntimeException('The fake did not expose its generated artifact.');
    }

    match ($tamper) {
        'missing' => \unlink($runner->artifact),
        'empty' => \file_put_contents($runner->artifact, ''),
        'oversized' => \file_put_contents($runner->artifact, \str_repeat('x', 2048), FILE_APPEND),
        'invalid' => \file_put_contents($runner->artifact, 'not a pdf'),
        default => throw new RuntimeException('Unknown artifact tamper mode.'),
    };

    expect(fn () => $output->storeAs('', 'report', 'exports'))->toThrow(ConversionFailed::class)
        ->and(Storage::disk('exports')->allFiles())->toBeEmpty();
})->with(['missing', 'empty', 'oversized', 'invalid']);

it('rejects an unreadable artifact before Storage io', function (): void {
    Storage::fake('exports');
    $runner = FakeProcessRunner::writes(Format::PDF);
    app()->instance(ProcessRunner::class, $runner);
    $output = Office::fromContent('valid text', InputFormat::TXT)->convertTo(Format::PDF);
    if ($runner->artifact === null || ! \chmod($runner->artifact, 0)) {
        throw new RuntimeException('Unable to make the generated artifact unreadable.');
    }

    expect(fn () => $output->storeAs('', 'report', 'exports'))->toThrow(ConversionFailed::class)
        ->and(Storage::disk('exports')->allFiles())->toBeEmpty();
})->skip(
    fn (): bool => PHP_OS_FAMILY === 'Windows',
    'Windows read permissions do not provide portable unreadable-file semantics.',
);

it('streams to a named disk with an enum-controlled extension', function (): void {
    Storage::fake('exports');
    $runner = FakeProcessRunner::writes(Format::DOCX);
    app()->instance(ProcessRunner::class, $runner);

    $stored = Office::fromContent('valid text', InputFormat::TXT)
        ->convertTo(Format::DOCX)
        ->storeAs('converted', 'png-38.jpg', 'exports');

    expect($stored)->toBe('converted/png-38.jpg.docx')
        ->and(Storage::disk('exports')->exists('converted/png-38.jpg.docx'))->toBeTrue();
});

it('accepts an empty directory as the selected disk root', function (): void {
    Storage::fake('exports');
    $runner = FakeProcessRunner::writes(Format::PDF);
    app()->instance(ProcessRunner::class, $runner);

    $stored = Office::fromContent('valid text', InputFormat::TXT)
        ->convertTo(Format::PDF)
        ->storeAs('', 'report.docx', 'exports');

    expect($stored)->toBe('report.docx.pdf')
        ->and(Storage::disk('exports')->exists('report.docx.pdf'))->toBeTrue();
});

it('does not duplicate a matching filename extension', function (): void {
    Storage::fake('exports');
    $runner = FakeProcessRunner::writes(Format::PDF);
    app()->instance(ProcessRunner::class, $runner);

    $stored = Office::fromContent('valid text', InputFormat::TXT)
        ->convertTo(Format::PDF)
        ->storeAs('', 'report.PDF', 'exports');

    expect($stored)->toBe('report.pdf')
        ->and(Storage::disk('exports')->exists('report.pdf'))->toBeTrue();
});

it('generates a safe name for raw content without a source stem', function (): void {
    Storage::fake('exports');
    $runner = FakeProcessRunner::writes(Format::PDF);
    app()->instance(ProcessRunner::class, $runner);

    $stored = Office::fromContent('valid text', InputFormat::TXT)
        ->convertTo(Format::PDF)
        ->storeAs('', disk: 'exports');
    if ($stored === false) {
        throw new RuntimeException('The fake Storage disk rejected the generated filename.');
    }

    expect($stored)->toMatch('/^converted-[a-f0-9]{16}\.pdf$/')
        ->and(Storage::disk('exports')->exists($stored))->toBeTrue();
});

it('rejects unsafe destinations before Storage and consumes the result', function (string $path, ?string $filename): void {
    Storage::fake('exports');
    $runner = FakeProcessRunner::writes(Format::PDF);
    app()->instance(ProcessRunner::class, $runner);
    $output = Office::fromContent('valid text', InputFormat::TXT)->convertTo(Format::PDF);

    expect(fn () => $output->storeAs($path, $filename, 'exports'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $output->output())->toThrow(AlreadyConsumed::class)
        ->and(Storage::disk('exports')->allFiles())->toBeEmpty();
})->with([
    'absolute' => ['/outside', 'report.pdf'],
    'parent traversal' => ['../outside', 'report.pdf'],
    'empty segment' => ['safe//outside', 'report.pdf'],
    'current segment' => ['safe/./outside', 'report.pdf'],
    'backslash' => ['safe\\outside', 'report.pdf'],
    'control character directory' => ["safe\u{0085}outside", 'report.pdf'],
    'nested filename' => ['safe', 'nested/report.pdf'],
    'empty filename' => ['safe', ''],
    'C1 control character filename' => ['safe', "report\u{0085}.pdf"],
]);

it('passes through a false Storage result and underlying exception', function (): void {
    $falseDisk = mock(FilesystemAdapter::class);
    $falseDisk->shouldReceive('putFileAs')->once()->andReturn(false);
    $falseManager = mock(FilesystemManager::class);
    $falseManager->shouldReceive('disk')->with('broken')->once()->andReturn($falseDisk);
    app()->instance(FilesystemManager::class, $falseManager);
    $runner = FakeProcessRunner::writes(Format::PDF);
    app()->instance(ProcessRunner::class, $runner);

    $stored = Office::fromContent('valid text', InputFormat::TXT)
        ->convertTo(Format::PDF)
        ->storeAs('', 'report', 'broken');

    expect($stored)->toBeFalse();
    expect(\scandir(config()->string('office-converter.temporary_directory')))->toBe(['.', '..']);
});

it('does not wrap a Storage exception', function (): void {
    $failure = new RuntimeException('disk unavailable');
    $disk = mock(FilesystemAdapter::class);
    $disk->shouldReceive('putFileAs')->once()->andThrow($failure);
    $manager = mock(FilesystemManager::class);
    $manager->shouldReceive('disk')->with('broken')->once()->andReturn($disk);
    app()->instance(FilesystemManager::class, $manager);
    $runner = FakeProcessRunner::writes(Format::PDF);
    app()->instance(ProcessRunner::class, $runner);
    $output = Office::fromContent('valid text', InputFormat::TXT)->convertTo(Format::PDF);

    try {
        $output->storeAs('', 'report', 'broken');
    } catch (RuntimeException $exception) {
        expect($exception)->toBe($failure)
            ->and(fn () => $output->output())->toThrow(AlreadyConsumed::class)
            ->and(\scandir(config()->string('office-converter.temporary_directory')))->toBe(['.', '..']);

        return;
    }

    throw new RuntimeException('The Storage exception was not propagated.');
});
