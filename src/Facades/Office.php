<?php

declare(strict_types=1);

namespace Mattmy\OfficeConverter\Facades;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Facade;
use Mattmy\OfficeConverter\Enums\InputFormat;
use Mattmy\OfficeConverter\OfficeDocument;
use Mattmy\OfficeConverter\OfficeManager;
use Override;

/**
 * Provides the Laravel facade for office document conversion.
 *
 * @method static OfficeDocument fromPath(string $path)
 * @method static OfficeDocument fromContent(string $content, InputFormat $format)
 * @method static OfficeDocument fromUploadedFile(UploadedFile $file)
 *
 * @see OfficeManager
 */
final class Office extends Facade
{
    /**
     * Return the container binding resolved by the facade.
     */
    #[Override]
    protected static function getFacadeAccessor(): string
    {
        return OfficeManager::class;
    }
}
