<?php

declare(strict_types=1);

namespace Mattmy\OfficeConverter;

use Illuminate\Support\ServiceProvider;
use Mattmy\OfficeConverter\Internal\ProcessRunner;
use Mattmy\OfficeConverter\Internal\SymfonyProcessRunner;
use Override;

/**
 * Registers the office converter configuration and services.
 */
final class OfficeConverterServiceProvider extends ServiceProvider
{
    /**
     * Register package configuration and stateless services.
     */
    #[Override]
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/office-converter.php', 'office-converter');
        $this->app->singleton(ProcessRunner::class, SymfonyProcessRunner::class);
        $this->app->singleton(OfficeManager::class);
    }

    /**
     * Register the publishable package configuration.
     */
    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/office-converter.php' => config_path('office-converter.php'),
        ], 'office-converter-config');
    }
}
