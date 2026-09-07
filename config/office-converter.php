<?php

declare(strict_types=1);

return [
    'binary' => env('LIBREOFFICE_BINARY', PHP_OS_FAMILY === 'Windows' ? 'soffice.com' : 'soffice'),
    'timeout' => 60,
    'max_input_bytes' => 100 * 1024 * 1024,
    'max_output_bytes' => 200 * 1024 * 1024,
    'temporary_directory' => storage_path('framework/laravel-office-converter'),
];
