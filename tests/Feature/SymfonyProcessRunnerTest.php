<?php

declare(strict_types=1);

use Mattmy\OfficeConverter\Enums\InputFormat;
use Mattmy\OfficeConverter\Exceptions\ConversionFailed;
use Mattmy\OfficeConverter\Exceptions\EnvironmentUnavailable;
use Mattmy\OfficeConverter\Internal\Configuration;
use Mattmy\OfficeConverter\Internal\SymfonyProcessRunner;
use Mattmy\OfficeConverter\Internal\Workspace;

/**
 * Create a package workspace for direct process boundary tests.
 */
function processTestWorkspace(): Workspace
{
    $configuration = Configuration::from([
        'binary' => PHP_BINARY,
        'timeout' => 1,
        'max_input_bytes' => 1024,
        'max_output_bytes' => 1024,
        'temporary_directory' => \sys_get_temp_dir(),
    ]);
    $workspace = Workspace::create($configuration);
    $workspace->write('valid text', InputFormat::TXT, 1024);

    return $workspace;
}

it('runs a process while bounding captured output', function (): void {
    $workspace = processTestWorkspace();

    try {
        (new SymfonyProcessRunner())->run(
            [PHP_BINARY, '-r', 'fwrite(STDOUT, str_repeat("x", 1000000)); fwrite(STDERR, str_repeat("y", 1000000));'],
            $workspace,
            5,
        );

        expect($workspace->directory())->toBeDirectory();
    } finally {
        $workspace->cleanup();
    }
});

it('maps a non-zero process and redacts its workspace path', function (): void {
    $workspace = processTestWorkspace();

    try {
        (new SymfonyProcessRunner())->run(
            [PHP_BINARY, '-r', 'fwrite(STDERR, $argv[1]); exit(7);', $workspace->directory()],
            $workspace,
            5,
        );
    } catch (ConversionFailed $exception) {
        expect($exception->getMessage())->not->toContain($workspace->directory())
            ->and($exception->getMessage())->toContain('[workspace]');
        $workspace->cleanup();

        return;
    }

    $workspace->cleanup();

    throw new RuntimeException('The process failure was not mapped.');
});

it('redacts a Windows workspace path written with forward slashes', function (): void {
    $workspace = processTestWorkspace();
    $forwardSlashPath = \str_replace('\\', '/', $workspace->directory());

    try {
        (new SymfonyProcessRunner())->run(
            [PHP_BINARY, '-r', 'fwrite(STDERR, $argv[1]); exit(7);', $forwardSlashPath],
            $workspace,
            5,
        );
    } catch (ConversionFailed $exception) {
        expect($exception->getMessage())->not->toContain($workspace->directory())
            ->and($exception->getMessage())->not->toContain($forwardSlashPath)
            ->and($exception->getMessage())->toContain('[workspace]');
        $workspace->cleanup();

        return;
    }

    $workspace->cleanup();

    throw new RuntimeException('The process failure was not mapped.');
});

it('maps a process timeout to a stable conversion failure', function (): void {
    $workspace = processTestWorkspace();

    try {
        expect(fn () => (new SymfonyProcessRunner())->run(
            [PHP_BINARY, '-r', 'usleep(500000);'],
            $workspace,
            0.05,
        ))->toThrow(ConversionFailed::class);
    } finally {
        $workspace->cleanup();
    }
});

it('maps an unavailable executable to an environment failure', function (): void {
    $workspace = processTestWorkspace();

    try {
        expect(fn () => (new SymfonyProcessRunner())->run(
            [$workspace->directory() . DIRECTORY_SEPARATOR . 'missing-executable'],
            $workspace,
            1,
        ))->toThrow(EnvironmentUnavailable::class);
    } finally {
        $workspace->cleanup();
    }
});
