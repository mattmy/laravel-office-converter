<?php

declare(strict_types=1);

use Mattmy\OfficeConverter\Enums\Format;
use Mattmy\OfficeConverter\Enums\InputFormat;
use Mattmy\OfficeConverter\Facades\Office;
use Mattmy\OfficeConverter\Tests\Integration\CorpusBuilder;
use Symfony\Component\Process\Process;

it('reads every public input and exercises every output filter with LibreOffice 26.2', function (): void {
    $binary = config()->string('office-converter.binary');
    $version = new Process([$binary, '--version']);
    $version->mustRun();
    expect($version->getOutput())->toContain('LibreOffice 26.2');

    $corpusDirectory = \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'office-corpus-' . \bin2hex(\random_bytes(8));

    try {
        $corpus = CorpusBuilder::create($corpusDirectory, $binary);
        foreach (InputFormat::cases() as $inputFormat) {
            $bytes = Office::fromPath($corpus[$inputFormat->value])->convertTo(Format::PDF)->output();
            expect($bytes)->toStartWith('%PDF-');
        }

        $outputs = [
            InputFormat::ODT->value => [Format::PDF, Format::ODT, Format::DOCX, Format::RTF, Format::TXT, Format::HTML],
            InputFormat::ODS->value => [Format::PDF, Format::ODS, Format::XLSX, Format::CSV],
            InputFormat::ODP->value => [Format::PDF, Format::ODP, Format::PPTX],
            InputFormat::ODG->value => [Format::PDF, Format::ODG, Format::PNG, Format::JPEG, Format::SVG, Format::WEBP],
        ];
        foreach ($outputs as $inputValue => $formats) {
            foreach ($formats as $format) {
                expect(Office::fromPath($corpus[$inputValue])->convertTo($format)->output())->not->toBeEmpty();
            }
        }

        expect(Office::fromPath($corpus['multi_page_pdf'])->convertTo(Format::PNG)->output())->not->toBeEmpty();
    } finally {
        CorpusBuilder::cleanup($corpusDirectory);
    }
})->skip(
    fn (): bool => \getenv('LIBREOFFICE_BINARY') === false,
    'Set LIBREOFFICE_BINARY to run real interoperability coverage.',
);
