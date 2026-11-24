<?php

declare(strict_types=1);

use Mattmy\OfficeConverter\Enums\InputFormat;
use Mattmy\OfficeConverter\Exceptions\ConversionFailed;
use Mattmy\OfficeConverter\Exceptions\EnvironmentUnavailable;
use Mattmy\OfficeConverter\Internal\Configuration;
use Mattmy\OfficeConverter\Internal\SymfonyProcessRunner;
use Mattmy\OfficeConverter\Internal\Workspace;
use Symfony\Component\Process\Process;

/**
 * Create a package workspace for direct process boundary tests.
 */
function processTestWorkspace(): Workspace
{
    $configuration = Configuration::from([
        'binary' => \PHP_BINARY,
        'timeout' => 1,
        'max_input_bytes' => 1024,
        'max_output_bytes' => 1024,
        'temporary_directory' => \sys_get_temp_dir(),
    ]);
    $workspace = Workspace::create($configuration);
    $workspace->write('valid text', InputFormat::TXT, 1024);

    return $workspace;
}

it('runs a process that writes large amounts of stdout and stderr', function (): void {
    $workspace = processTestWorkspace();

    try {
        (new SymfonyProcessRunner())->run(
            [\PHP_BINARY, '-r', 'fwrite(STDOUT, str_repeat("x", 1000000)); fwrite(STDERR, str_repeat("y", 1000000));'],
            $workspace,
            5,
        );

        expect($workspace->directory())->toBeDirectory();
    } finally {
        $workspace->cleanup();
    }
});

it('reports a non-zero exit with a fixed diagnostic', function (): void {
    $workspace = processTestWorkspace();

    try {
        (new SymfonyProcessRunner())->run(
            [
                \PHP_BINARY,
                '-r',
                'fwrite(STDOUT, "stdout marker"); fwrite(STDERR, "stderr marker " . $argv[1] . " " . $argv[2] . " " . PHP_BINARY); exit(7);',
                $workspace->directory(),
                \str_replace('\\', '/', $workspace->directory()),
            ],
            $workspace,
            5,
        );
    } catch (ConversionFailed $exception) {
        expect($exception->getMessage())->toBe('LibreOffice conversion failed. Check LibreOffice and filter compatibility.');

        return;
    } finally {
        $workspace->cleanup();
    }

    throw new RuntimeException('The process failure was not mapped.');
});

it('maps a process timeout to a stable conversion failure', function (): void {
    $workspace = processTestWorkspace();

    try {
        expect(fn () => (new SymfonyProcessRunner())->run(
            [\PHP_BINARY, '-r', 'usleep(500000);'],
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
            [$workspace->directory() . \DIRECTORY_SEPARATOR . 'missing-executable'],
            $workspace,
            1,
        ))->toThrow(EnvironmentUnavailable::class);
    } finally {
        $workspace->cleanup();
    }
});

it('resolves an executable from a relative PATH before changing the process directory', function (): void {
    $workspace = processTestWorkspace();
    $originalDirectory = \getcwd();
    $originalPath = \getenv('PATH');
    $binaryDirectory = \dirname(\PHP_BINARY);

    try {
        if ($originalDirectory === false || ! \chdir(\dirname($binaryDirectory))
            || ! \putenv('PATH=' . \basename($binaryDirectory) . \PATH_SEPARATOR . ($originalPath === false ? '' : $originalPath))) {
            throw new RuntimeException('Unable to configure a relative PATH.');
        }

        (new SymfonyProcessRunner())->run([\basename(\PHP_BINARY), '-r', 'exit(0);'], $workspace, 5);

        expect($workspace->directory())->toBeDirectory();
    } finally {
        \putenv($originalPath === false ? 'PATH' : 'PATH=' . $originalPath);
        if ($originalDirectory !== false) {
            \chdir($originalDirectory);
        }

        $workspace->cleanup();
    }
});

it('reports disabled process execution as an unavailable environment', function (): void {
    $script = <<<'PHP'
        require $argv[1];

        $configuration = Mattmy\OfficeConverter\Internal\Configuration::from([
            'binary' => PHP_BINARY,
            'timeout' => 1,
            'max_input_bytes' => 1024,
            'max_output_bytes' => 1024,
            'temporary_directory' => sys_get_temp_dir(),
        ]);

        $workspace = Mattmy\OfficeConverter\Internal\Workspace::create($configuration);

        try {
            (new Mattmy\OfficeConverter\Internal\SymfonyProcessRunner())->run([PHP_BINARY], $workspace, 1);
        } catch (Mattmy\OfficeConverter\Exceptions\EnvironmentUnavailable) {
            echo 'EnvironmentUnavailable';
        } finally {
            $workspace->cleanup();
        }
        PHP;

    $process = new Process([
        \PHP_BINARY, '-d', 'disable_functions=proc_open', '-r', $script, __DIR__ . '/../../vendor/autoload.php',
    ]);
    $process->mustRun();

    expect($process->getOutput())->toBe('EnvironmentUnavailable');
});

it('rejects a configured file without execute permission on Unix', function (): void {
    $workspace = processTestWorkspace();

    try {
        expect(fn () => (new SymfonyProcessRunner())->run([$workspace->inputPath()], $workspace, 1))
            ->toThrow(EnvironmentUnavailable::class);
    } finally {
        $workspace->cleanup();
    }
})->skip(\PHP_OS_FAMILY === 'Windows', 'Execute permission is a Unix file mode.');
