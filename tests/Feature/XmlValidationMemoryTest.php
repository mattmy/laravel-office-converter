<?php

declare(strict_types=1);

use Mattmy\OfficeConverter\Enums\InputFormat;
use Symfony\Component\Process\Process;

it('streams large HTML prefixes and SVG contents within a small memory budget', function (InputFormat $format): void {
    $path = \tempnam(\sys_get_temp_dir(), 'office-xml-memory-');
    if (! \is_string($path)) {
        throw new RuntimeException('Unable to create a validation fixture.');
    }

    $handle = \fopen($path, 'wb');
    if (! \is_resource($handle)) {
        throw new RuntimeException('Unable to open a validation fixture.');
    }

    try {
        \fwrite($handle, $format === InputFormat::HTML ? "\xEF\xBB\xBF<!--" : '<svg><text>');
        for ($index = 0; $index < 32 * 1024; $index++) {
            \fwrite($handle, \str_repeat('x', 1024));
        }
        \fwrite($handle, $format === InputFormat::HTML ? '-->' . \str_repeat(' ', 8190) . '<!doctype' . \str_repeat(' ', 8192) . 'html><html></html>' : '</text></svg>');
        \fclose($handle);
        $process = new Process([
            \PHP_BINARY, '-d', 'memory_limit=24M', '-r',
            'require $argv[1]; (new Mattmy\OfficeConverter\Internal\FileValidator())->input($argv[2], Mattmy\OfficeConverter\Enums\InputFormat::from($argv[3]), 40 * 1024 * 1024);',
            __DIR__ . '/../../vendor/autoload.php', $path, $format->value,
        ]);
        $process->mustRun();
        expect($process->isSuccessful())->toBeTrue();
    } finally {
        if (\is_resource($handle)) {
            \fclose($handle);
        }
        \unlink($path);
    }
})->with([[InputFormat::HTML], [InputFormat::SVG]]);
