<?php

declare(strict_types=1);

use Mattmy\OfficeConverter\Enums\InputFormat;
use Mattmy\OfficeConverter\Exceptions\InvalidOfficeInput;
use Mattmy\OfficeConverter\Internal\FileValidator;
use Mattmy\OfficeConverter\Tests\Fixtures\OfficeFixture;

it('accepts a bounded structural fixture for every public input format', function (): void {
    $validator = new FileValidator();

    foreach (InputFormat::cases() as $format) {
        $path = OfficeFixture::create($format);

        try {
            $validator->input($path, $format, 1024 * 1024);
            expect($path)->toBeFile();
        } finally {
            OfficeFixture::remove($path);
        }
    }
});

it('rejects wrong-family and bounded-archive violations', function (): void {
    $validator = new FileValidator();
    $wrongFamily = OfficeFixture::create(InputFormat::XLSX);
    $tooManyEntries = \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'office-many-' . \bin2hex(\random_bytes(8)) . '.docx';
    $oversizedXml = \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'office-xml-' . \bin2hex(\random_bytes(8)) . '.docx';

    foreach ([$tooManyEntries, $oversizedXml] as $path) {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Unable to create an invalid archive fixture.');
        }

        if ($path === $tooManyEntries) {
            for ($index = 0; $index < 4097; $index++) {
                $zip->addFromString('word/entry-' . $index, 'x');
            }

            $zip->addFromString('[Content_Types].xml', '<Types/>');
        } else {
            $zip->addFromString('word/document.xml', '<document/>');
            $zip->addFromString('[Content_Types].xml', \str_repeat('x', 4_194_305));
        }

        $zip->close();
    }

    try {
        expect(fn () => $validator->input($wrongFamily, InputFormat::DOCX, 10 * 1024 * 1024))->toThrow(InvalidOfficeInput::class)
            ->and(fn () => $validator->input($tooManyEntries, InputFormat::DOCX, 10 * 1024 * 1024))->toThrow(InvalidOfficeInput::class)
            ->and(fn () => $validator->input($oversizedXml, InputFormat::DOCX, 10 * 1024 * 1024))->toThrow(InvalidOfficeInput::class);
    } finally {
        OfficeFixture::remove($wrongFamily);
        OfficeFixture::remove($tooManyEntries);
        OfficeFixture::remove($oversizedXml);
    }
});

it('rejects empty oversized and disguised input before conversion', function (): void {
    $validator = new FileValidator();
    $empty = \tempnam(\sys_get_temp_dir(), 'office-empty-');
    $text = \tempnam(\sys_get_temp_dir(), 'office-fake-docx-');
    if (! \is_string($empty) || ! \is_string($text) || \file_put_contents($text, 'plain text') === false) {
        throw new RuntimeException('Unable to create invalid input fixtures.');
    }

    try {
        expect(fn () => $validator->input($empty, InputFormat::TXT, 1024))->toThrow(InvalidOfficeInput::class)
            ->and(fn () => $validator->input($text, InputFormat::DOCX, 1024))->toThrow(InvalidOfficeInput::class)
            ->and(fn () => $validator->input($text, InputFormat::TXT, 2))->toThrow(InvalidOfficeInput::class);
    } finally {
        \unlink($empty);
        \unlink($text);
    }
});

it('rejects OOXML main-part and content-type spoofing', function (): void {
    $validator = new FileValidator();
    $paths = [];

    foreach ([
        'missing main part' => ['word/other.xml', '<Types><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>'],
        'wrong override part' => ['word/document.xml', '<Types><Override PartName="/word/other.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>'],
        'comment spoof' => ['word/other.xml', '<Types><!-- application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml --></Types>'],
    ] as [$part, $types]) {
        $path = \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'office-spoof-' . \bin2hex(\random_bytes(8)) . '.docx';
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Unable to create an OOXML spoof fixture.');
        }

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?>' . $types);
        $zip->addFromString($part, '<document/>');
        $zip->close();
        $paths[] = $path;
    }

    try {
        foreach ($paths as $path) {
            expect(fn () => $validator->input($path, InputFormat::DOCX, 1024 * 1024))->toThrow(InvalidOfficeInput::class);
        }
    } finally {
        foreach ($paths as $path) {
            OfficeFixture::remove($path);
        }
    }
});

it('rejects plain text HTML, corrupted raster framing, and invalid streamed UTF-8', function (): void {
    $validator = new FileValidator();
    $html = \tempnam(\sys_get_temp_dir(), 'office-html-');
    $png = OfficeFixture::create(InputFormat::PNG);
    $webp = OfficeFixture::create(InputFormat::WEBP);
    $text = \tempnam(\sys_get_temp_dir(), 'office-text-');
    if (! \is_string($html) || ! \is_string($text)
        || \file_put_contents($html, 'plain text') === false
        || \file_put_contents($text, \str_repeat('a', 8191) . "繁\xFF") === false) {
        throw new RuntimeException('Unable to create malformed validation fixtures.');
    }

    \file_put_contents($png, 'trailing', FILE_APPEND);
    \file_put_contents($webp, 'trailing', FILE_APPEND);

    try {
        expect(fn () => $validator->input($html, InputFormat::HTML, 1024))->toThrow(InvalidOfficeInput::class)
            ->and(fn () => $validator->input($png, InputFormat::PNG, 1024))->toThrow(InvalidOfficeInput::class)
            ->and(fn () => $validator->input($webp, InputFormat::WEBP, 1024))->toThrow(InvalidOfficeInput::class)
            ->and(fn () => $validator->input($text, InputFormat::TXT, 16 * 1024))->toThrow(InvalidOfficeInput::class);
    } finally {
        foreach ([$html, $png, $webp, $text] as $path) {
            OfficeFixture::remove($path);
        }
    }
});

it('accepts valid UTF-8 split across validator chunks', function (): void {
    $validator = new FileValidator();
    $path = \tempnam(\sys_get_temp_dir(), 'office-utf8-');
    if (! \is_string($path) || \file_put_contents($path, \str_repeat('a', 8191) . '繁體中文') === false) {
        throw new RuntimeException('Unable to create a streamed UTF-8 fixture.');
    }

    try {
        $validator->input($path, InputFormat::TXT, 16 * 1024);
        expect($path)->toBeFile();
    } finally {
        OfficeFixture::remove($path);
    }
});
