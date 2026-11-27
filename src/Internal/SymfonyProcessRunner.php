<?php

declare(strict_types=1);

namespace Mattmy\OfficeConverter\Internal;

use ErrorException;
use Mattmy\OfficeConverter\Exceptions\ConversionFailed;
use Mattmy\OfficeConverter\Exceptions\EnvironmentUnavailable;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Exception\RuntimeException as ProcessRuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Runs LibreOffice with Symfony Process and fixed failure diagnostics.
 *
 * @internal
 */
final class SymfonyProcessRunner implements ProcessRunner
{
    /**
     * Run a shell-free command inside its package-owned workspace.
     *
     * @param  list<string>  $command
     *
     * @throws ConversionFailed
     * @throws EnvironmentUnavailable
     */
    public function run(array $command, Workspace $workspace, float $timeout): void
    {
        if (! \function_exists('proc_open')) {
            throw new EnvironmentUnavailable('LibreOffice could not be started; enable proc_open in PHP.');
        }

        try {
            $executable = \realpath($command[0]);
            if ($executable === false || ! \is_file($executable)
                || (\PHP_OS_FAMILY !== 'Windows' && ! \is_executable($executable))) {
                $located = (new ExecutableFinder())->find($command[0]);
                $executable = $located === null ? false : \realpath($located);
            }

            if ($executable === false || ! \is_file($executable)) {
                throw new EnvironmentUnavailable('LibreOffice could not be found; check the configured binary.');
            }
        } catch (ErrorException $exception) {
            throw new EnvironmentUnavailable('LibreOffice could not be located; check the configured binary.', previous: $exception);
        }

        $command[0] = $executable;
        $process = new Process($command, $workspace->directory());
        $process->setTimeout($timeout);
        $process->disableOutput();

        try {
            $exitCode = $process->run();
        } catch (ProcessTimedOutException $exception) {
            $process->stop(0);

            throw new ConversionFailed(
                'LibreOffice conversion timed out; check LibreOffice and the configured timeout.',
                previous: $exception,
            );
        } catch (ProcessRuntimeException $exception) {
            if ($process->isStarted()) {
                throw $exception;
            }

            throw new EnvironmentUnavailable(
                'LibreOffice could not be started; check the configured binary.',
                previous: $exception,
            );
        }

        if ($exitCode !== 0) {
            throw new ConversionFailed('LibreOffice conversion failed. Check LibreOffice and filter compatibility.');
        }
    }
}
