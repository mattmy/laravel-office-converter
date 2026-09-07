<?php

declare(strict_types=1);

namespace Mattmy\OfficeConverter\Internal;

use Mattmy\OfficeConverter\Exceptions\ConversionFailed;
use Mattmy\OfficeConverter\Exceptions\EnvironmentUnavailable;
use Override;
use Symfony\Component\Process\Exception\ProcessStartFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Runs LibreOffice with Symfony Process and bounded diagnostic capture.
 *
 * @internal
 */
final class SymfonyProcessRunner implements ProcessRunner
{
    private const int MAX_CAPTURE_BYTES = 65_536;

    /**
     * Run a shell-free command inside its package-owned workspace.
     *
     * @param  list<string>  $command
     *
     * @throws ConversionFailed
     * @throws EnvironmentUnavailable
     */
    #[Override]
    public function run(array $command, Workspace $workspace, float $timeout): void
    {
        $realExecutable = \realpath($command[0]);
        $executable = \is_string($realExecutable) && \is_file($realExecutable)
            ? $realExecutable
            : (new ExecutableFinder())->find($command[0]);
        if ($executable === null) {
            throw new EnvironmentUnavailable('LibreOffice could not be found; check the configured binary.');
        }

        $command[0] = $executable;
        $process = new Process($command, $workspace->directory());
        $process->setTimeout($timeout);
        $stderr = '';

        try {
            $exitCode = $process->run(function (string $type, string $buffer) use ($process, &$stderr): void {
                if ($type === Process::ERR && \strlen($stderr) < self::MAX_CAPTURE_BYTES) {
                    $remaining = self::MAX_CAPTURE_BYTES - \strlen($stderr);
                    $stderr .= \substr($buffer, 0, $remaining);
                }

                $process->clearOutput();
                $process->clearErrorOutput();
            });
        } catch (ProcessStartFailedException $exception) {
            throw new EnvironmentUnavailable(
                'LibreOffice could not be started; check the configured binary.',
                previous: $exception,
            );
        } catch (ProcessTimedOutException $exception) {
            $process->stop(0);

            throw new ConversionFailed(
                'LibreOffice conversion timed out; check the deployment process supervisor.',
                previous: $exception,
            );
        }

        if ($exitCode !== 0) {
            throw new ConversionFailed(
                'LibreOffice conversion failed. ' . $this->safeDiagnostic($stderr, $workspace),
            );
        }
    }

    /**
     * Return a short control-free diagnostic without private workspace paths.
     */
    private function safeDiagnostic(string $stderr, Workspace $workspace): string
    {
        $directory = $workspace->directory();
        $safe = \str_ireplace([
            $directory,
            \str_replace('\\', '/', $directory),
            \str_replace('/', '\\', $directory),
        ], '[workspace]', $stderr);
        $safe = \preg_replace('/[\x00-\x1F\x7F]+/', ' ', $safe) ?? '';
        $safe = \trim($safe);

        return $safe === '' ? 'Check LibreOffice and filter compatibility.' : \substr($safe, 0, 512);
    }
}
