<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Mattmy\OfficeConverter\Facades\Office;
use Mattmy\OfficeConverter\OfficeConverterServiceProvider;
use Mattmy\OfficeConverter\OfficeManager;

use function Orchestra\Testbench\artisan;

it('boots through package discovery with publishable configuration', function (): void {
    $manager = app(OfficeManager::class);
    assert($manager instanceof OfficeManager);
    $facadeRoot = Office::getFacadeRoot();
    assert($facadeRoot instanceof OfficeManager);
    $published = ServiceProvider::pathsToPublish(
        OfficeConverterServiceProvider::class,
        'office-converter-config',
    );

    expect($manager)->toBeInstanceOf(OfficeManager::class)
        ->and($facadeRoot)->toBeInstanceOf(OfficeManager::class)
        ->and(config('office-converter.timeout'))->toBe(60)
        ->and($published)->toHaveCount(1);
});

it('publishes configuration and supports Laravel config caching', function (): void {
    $application = app(Application::class);
    $published = artisan($application, 'vendor:publish', [
        '--provider' => OfficeConverterServiceProvider::class,
        '--tag' => 'office-converter-config',
    ]);
    $cached = artisan($application, 'config:cache');

    expect($published)->toBe(0)
        ->and($cached)->toBe(0)
        ->and(config()->string('office-converter.binary'))->not->toBeEmpty();
});
