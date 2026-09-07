<?php

declare(strict_types=1);

namespace Mattmy\OfficeConverter\Tests;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Mattmy\OfficeConverter\OfficeConverterServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Override;
use RuntimeException;

/**
 * Boots a minimal Laravel application with the package loaded.
 */
abstract class TestCase extends Orchestra
{
    private ?string $temporaryDirectory = null;

    /** {@inheritDoc} */
    #[Override]
    protected function tearDown(): void
    {
        try {
            if ($this->temporaryDirectory !== null) {
                (new Filesystem())->deleteDirectory($this->temporaryDirectory);
            }
        } finally {
            parent::tearDown();
        }
    }

    /**
     * Register the package provider for Testbench.
     *
     * @param  mixed  $app
     * @return list<class-string>
     */
    #[Override]
    protected function getPackageProviders($app): array
    {
        return [OfficeConverterServiceProvider::class];
    }

    /**
     * Use an isolated temporary root for every test.
     *
     * @param  mixed  $app
     */
    #[Override]
    protected function defineEnvironment($app): void
    {
        if (! $app instanceof Application) {
            throw new RuntimeException('Testbench did not provide a Laravel application.');
        }

        $this->temporaryDirectory = \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'laravel-office-converter-tests-' . \bin2hex(\random_bytes(8));
        $app->make(Repository::class)->set('office-converter.temporary_directory', $this->temporaryDirectory);
    }
}
