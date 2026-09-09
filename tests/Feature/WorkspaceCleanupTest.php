<?php

declare(strict_types=1);

use Mattmy\OfficeConverter\Enums\InputFormat;
use Mattmy\OfficeConverter\Internal\Configuration;
use Mattmy\OfficeConverter\Internal\Workspace;
use Symfony\Component\Process\Process;

it('keeps an input snapshot available for cleanup after a failed deletion', function (): void {
    $configuration = Configuration::from(config('office-converter'));
    $failed = false;
    $workspace = Workspace::create($configuration, static function (string $path, bool $directory) use (&$failed): bool {
        if (! $directory && ! $failed) {
            $failed = true;

            return false;
        }

        return $directory ? \rmdir($path) : \unlink($path);
    });

    try {
        $workspace->write('valid text', InputFormat::TXT, $configuration->maxInputBytes);
        $inputPath = $workspace->inputPath();

        expect(fn () => $workspace->removeInput())->toThrow(RuntimeException::class)
            ->and($inputPath)->toBeFile();

        $workspace->removeInput();

        expect($inputPath)->not->toBeFile();
    } finally {
        $workspace->cleanup();
    }
});

it('retries workspace cleanup after a temporary directory deletion failure', function (): void {
    $configuration = Configuration::from(config('office-converter'));
    $failed = false;
    $workspace = Workspace::create($configuration, static function (string $path, bool $directory) use (&$failed): bool {
        if ($directory && ! $failed) {
            $failed = true;

            return false;
        }

        return $directory ? \rmdir($path) : \unlink($path);
    });

    $workspace->write('valid text', InputFormat::TXT, $configuration->maxInputBytes);
    $directory = $workspace->directory();

    $workspace->cleanup();

    expect($directory)->toBeDirectory();

    $workspace->cleanup();

    expect($directory)->not->toBeDirectory();
});

it('rejects a Windows output directory junction that escapes its workspace', function (): void {
    $configuration = Configuration::from(config('office-converter'));
    $workspace = Workspace::create($configuration);
    $outside = \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'office-converter-junction-' . \bin2hex(\random_bytes(8));
    if (! \mkdir($outside, 0700)) {
        throw new RuntimeException('Unable to create the junction test directory.');
    }

    try {
        $workspace->createOutputDirectory();
        $outputDirectory = $workspace->outputDirectory();
        if (! \rmdir($outputDirectory)) {
            throw new RuntimeException('Unable to prepare the output directory junction test.');
        }

        (new Process(['cmd', '/d', '/c', 'mklink', '/J', $outputDirectory, $outside]))->mustRun();
        \file_put_contents($outside . DIRECTORY_SEPARATOR . 'input.pdf', "%PDF-1.7\n%%EOF");

        expect(fn () => $workspace->singleArtifact('pdf'))->toThrow(RuntimeException::class);
    } finally {
        $outputDirectory = $workspace->outputDirectory();
        if (\is_dir($outputDirectory)) {
            (new Process(['cmd', '/d', '/c', 'rmdir', $outputDirectory]))->run();
        }

        $workspace->cleanup();
        $artifact = $outside . DIRECTORY_SEPARATOR . 'input.pdf';
        if (\is_file($artifact) && ! \unlink($artifact)) {
            throw new RuntimeException('Unable to remove the junction test artifact.');
        }

        \rmdir($outside);
    }
})->skip(
    fn (): bool => PHP_OS_FAMILY !== 'Windows',
    'Windows junction validation requires a Windows filesystem.',
);

it('rejects a Unix output directory symlink that escapes its workspace', function (): void {
    $configuration = Configuration::from(config('office-converter'));
    $workspace = Workspace::create($configuration);
    $outside = \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'office-converter-output-' . \bin2hex(\random_bytes(8));
    if (! \mkdir($outside, 0700)) {
        throw new RuntimeException('Unable to create the output symlink test directory.');
    }

    try {
        $workspace->createOutputDirectory();
        $outputDirectory = $workspace->outputDirectory();
        if (! \rmdir($outputDirectory) || ! \symlink($outside, $outputDirectory)) {
            throw new RuntimeException('Unable to create the output directory symlink.');
        }

        \file_put_contents($outside . DIRECTORY_SEPARATOR . 'input.pdf', "%PDF-1.7\n%%EOF");

        expect(fn () => $workspace->singleArtifact('pdf'))->toThrow(RuntimeException::class);
    } finally {
        $outputDirectory = $workspace->outputDirectory();
        if (\is_link($outputDirectory) && ! \unlink($outputDirectory)) {
            throw new RuntimeException('Unable to remove the output directory symlink.');
        }

        $workspace->cleanup();
        $artifact = $outside . DIRECTORY_SEPARATOR . 'input.pdf';
        if (\is_file($artifact) && ! \unlink($artifact)) {
            throw new RuntimeException('Unable to remove the output symlink test artifact.');
        }

        \rmdir($outside);
    }
})->skip(
    fn (): bool => PHP_OS_FAMILY === 'Windows',
    'Unix output-directory symlink validation requires a Unix filesystem.',
);
