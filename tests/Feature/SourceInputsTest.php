<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Mattmy\OfficeConverter\Enums\Format;
use Mattmy\OfficeConverter\Enums\InputFormat;
use Mattmy\OfficeConverter\Exceptions\EnvironmentUnavailable;
use Mattmy\OfficeConverter\Exceptions\InvalidOfficeInput;
use Mattmy\OfficeConverter\Facades\Office;
use Mattmy\OfficeConverter\Internal\ProcessRunner;
use Mattmy\OfficeConverter\Tests\Fakes\FakeProcessRunner;
use Mattmy\OfficeConverter\Tests\Fixtures\OfficeFixture;
use PHPUnit\Framework\Assert;
use Symfony\Component\Process\Process;

it('snapshots raw content immediately and converts the snapshot', function (): void {
    $path = OfficeFixture::create(InputFormat::DOCX);
    $content = \file_get_contents($path);
    OfficeFixture::remove($path);
    if (! \is_string($content)) {
        throw new RuntimeException('Unable to read the source fixture.');
    }

    $runner = FakeProcessRunner::writes(Format::PDF);
    app()->instance(ProcessRunner::class, $runner);

    Office::fromContent($content, InputFormat::DOCX)->convertTo(Format::PDF)->output();

    expect($runner->inputSnapshots)->toBe([$content]);
});

it('rejects invalid operation configuration before creating a workspace', function (int|float $timeout): void {
    $runner = FakeProcessRunner::writes(Format::PDF);
    app()->instance(ProcessRunner::class, $runner);
    config()->set('office-converter.timeout', $timeout);

    expect(fn () => Office::fromContent('valid text', InputFormat::TXT))->toThrow(EnvironmentUnavailable::class)
        ->and($runner->commands)->toBeEmpty();
})->with([
    'zero timeout' => [0],
    'infinite timeout' => [\INF],
    'not a number timeout' => [\NAN],
]);

it('snapshots an absolute path without taking ownership of the source', function (): void {
    $path = OfficeFixture::create(InputFormat::DOCX);
    $original = \file_get_contents($path);
    if (! \is_string($original)) {
        throw new RuntimeException('Unable to read the source fixture.');
    }

    try {
        $runner = FakeProcessRunner::writes(Format::PDF);
        app()->instance(ProcessRunner::class, $runner);
        $document = Office::fromPath($path);
        \file_put_contents($path, 'changed after snapshot');
        $document->convertTo(Format::PDF)->output();

        expect($runner->inputSnapshots)->toBe([$original])
            ->and(\file_get_contents($path))->toBe('changed after snapshot');
    } finally {
        OfficeFixture::remove($path);
    }
});

it('uses a valid upload original extension but validates its bytes', function (): void {
    $path = OfficeFixture::create(InputFormat::JPEG);

    try {
        $runner = FakeProcessRunner::writes(Format::PNG);
        app()->instance(ProcessRunner::class, $runner);
        $upload = new UploadedFile($path, 'PHOTO.JPEG', 'text/plain', null, true);

        Office::fromUploadedFile($upload)->convertTo(Format::PNG)->output();

        expect($runner->commands)->toHaveCount(1);
    } finally {
        OfficeFixture::remove($path);
    }
});

it('accepts a Windows absolute path with repeated forward slashes after the drive', function (): void {
    $path = OfficeFixture::create(InputFormat::DOCX);

    try {
        $absolutePath = \substr($path, 0, 2) . '//' . \str_replace('\\', '/', \substr($path, 3));
        app()->instance(ProcessRunner::class, FakeProcessRunner::writes(Format::PDF));

        expect(Office::fromPath($absolutePath)->convertTo(Format::PDF)->output())->toStartWith('%PDF-');
    } finally {
        OfficeFixture::remove($path);
    }
})->skip(\PHP_OS_FAMILY !== 'Windows', 'Drive-letter paths are Windows-specific.');

it('rejects invalid source boundaries before starting a process', function (): void {
    $runner = FakeProcessRunner::writes(Format::PDF);
    app()->instance(ProcessRunner::class, $runner);
    $upload = new UploadedFile('missing.docx', 'report.docx', null, \UPLOAD_ERR_PARTIAL, true);

    expect(fn () => Office::fromPath('relative.docx'))->toThrow(InvalidOfficeInput::class)
        ->and(fn () => Office::fromContent('', InputFormat::DOCX))->toThrow(InvalidOfficeInput::class)
        ->and(fn () => Office::fromUploadedFile($upload))->toThrow(InvalidOfficeInput::class)
        ->and($runner->commands)->toBeEmpty();
});

it('classifies upload path warnings as invalid office input', function (): void {
    $path = OfficeFixture::create(InputFormat::DOCX);

    try {
        $upload = new class($path, 'report.docx', null, null, true) extends UploadedFile
        {
            /** Simulate a filesystem warning converted to an exception by Laravel. */
            public function getRealPath(): string|false
            {
                throw new ErrorException('Unable to resolve the upload path.');
            }
        };

        expect(fn () => Office::fromUploadedFile($upload))
            ->toThrow(InvalidOfficeInput::class, 'The uploaded office document could not be inspected.');
    } finally {
        OfficeFixture::remove($path);
    }
});

it('rejects an unknown path extension before starting a process', function (): void {
    $path = \tempnam(\sys_get_temp_dir(), 'office-unknown-');
    if (! \is_string($path) || ! \file_put_contents($path, 'valid text')) {
        throw new RuntimeException('Unable to create an unknown-extension fixture.');
    }

    try {
        $runner = FakeProcessRunner::writes(Format::PDF);
        app()->instance(ProcessRunner::class, $runner);

        expect(fn () => Office::fromPath($path))->toThrow(InvalidOfficeInput::class)
            ->and($runner->commands)->toBeEmpty();
    } finally {
        OfficeFixture::remove($path);
    }
});

it('rejects a symlinked path before starting a process', function (): void {
    $source = OfficeFixture::create(InputFormat::DOCX);
    $link = $source . '.link.docx';

    try {
        if (\PHP_OS_FAMILY === 'Windows') {
            $process = new Process(['cmd', '/d', '/c', 'mklink', $link, $source]);
            $process->run();
            if (! $process->isSuccessful()) {
                Assert::markTestSkipped('Creating Windows file symbolic links requires Developer Mode or an elevated account.');
            }
        } elseif (! \symlink($source, $link)) {
            throw new RuntimeException('Unable to create a path symlink fixture.');
        }

        $runner = FakeProcessRunner::writes(Format::PDF);
        app()->instance(ProcessRunner::class, $runner);

        expect(fn () => Office::fromPath($link))->toThrow(InvalidOfficeInput::class)
            ->and($runner->commands)->toBeEmpty();
    } finally {
        OfficeFixture::remove($link);
        OfficeFixture::remove($source);
    }
});

it('cleans a snapshot abandoned before conversion', function (): void {
    $path = OfficeFixture::create(InputFormat::TXT);
    $content = \file_get_contents($path);
    OfficeFixture::remove($path);
    if (! \is_string($content)) {
        throw new RuntimeException('Unable to read the source fixture.');
    }

    $document = Office::fromContent($content, InputFormat::TXT);
    unset($document);
    \gc_collect_cycles();

    expect(\scandir(config()->string('office-converter.temporary_directory')))->toBe(['.', '..']);
});

it('does not allow cloning a document that owns an input snapshot', function (): void {
    $runner = FakeProcessRunner::writes(Format::PDF);
    app()->instance(ProcessRunner::class, $runner);
    $document = Office::fromContent('valid text', InputFormat::TXT);

    expect((new ReflectionObject($document))->isCloneable())->toBeFalse()
        ->and($document->convertTo(Format::PDF)->output())->toStartWith('%PDF-');
});

it('rejects a temporary directory that is an existing file using an environment exception', function (): void {
    $path = OfficeFixture::create(InputFormat::TXT);

    try {
        config()->set('office-converter.temporary_directory', $path);

        expect(fn () => Office::fromContent('valid text', InputFormat::TXT))->toThrow(EnvironmentUnavailable::class);
    } finally {
        OfficeFixture::remove($path);
    }
});

it('uses a temporary parent created concurrently during mkdir', function (): void {
    $parent = config()->string('office-converter.temporary_directory');
    \file_put_contents($parent, '');

    \set_error_handler(static function (int $severity, string $message) use ($parent): never {
        if (\str_starts_with($message, 'mkdir():') && \is_file($parent)) {
            \unlink($parent);
            \mkdir($parent, 0700);
        }

        throw new ErrorException($message, 0, $severity);
    });

    try {
        $document = Office::fromContent('valid text', InputFormat::TXT);
        unset($document);

        expect(\scandir($parent))->toBe(['.', '..']);
    } finally {
        \restore_error_handler();
    }
});

it('rejects NUL in executable and temporary directory settings', function (string $key): void {
    config()->set('office-converter.' . $key, \sys_get_temp_dir() . "\0invalid");

    expect(fn () => Office::fromContent('valid text', InputFormat::TXT))->toThrow(EnvironmentUnavailable::class);
})->with(['binary', 'temporary_directory']);
