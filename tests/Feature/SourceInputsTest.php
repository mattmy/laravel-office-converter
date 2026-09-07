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

it('rejects invalid operation configuration before creating a workspace', function (): void {
    $runner = FakeProcessRunner::writes(Format::PDF);
    app()->instance(ProcessRunner::class, $runner);
    config()->set('office-converter.timeout', 0);

    expect(fn () => Office::fromContent('valid text', InputFormat::TXT))->toThrow(EnvironmentUnavailable::class)
        ->and($runner->commands)->toBeEmpty();
});

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

it('rejects invalid source boundaries before starting a process', function (): void {
    $runner = FakeProcessRunner::writes(Format::PDF);
    app()->instance(ProcessRunner::class, $runner);
    $upload = new UploadedFile('missing.docx', 'report.docx', null, UPLOAD_ERR_PARTIAL, true);

    expect(fn () => Office::fromPath('relative.docx'))->toThrow(InvalidOfficeInput::class)
        ->and(fn () => Office::fromContent('', InputFormat::DOCX))->toThrow(InvalidOfficeInput::class)
        ->and(fn () => Office::fromUploadedFile($upload))->toThrow(InvalidOfficeInput::class)
        ->and($runner->commands)->toBeEmpty();
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
