<?php

declare(strict_types=1);

namespace Mattmy\OfficeConverter\Internal;

/**
 * Runs one deterministic external process for the converter.
 *
 * @internal
 */
interface ProcessRunner
{
    /**
     * Run a shell-free command inside its package-owned workspace.
     *
     * @param  list<string>  $command
     */
    public function run(array $command, Workspace $workspace, float $timeout): void;
}
