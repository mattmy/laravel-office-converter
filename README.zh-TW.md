# Laravel Office Converter

[English](README.md)

Laravel Office Converter 是一個基於 LibreOffice 的 Laravel 文件轉換套件。它可轉換支援的文件、
試算表、簡報、繪圖與圖片格式，並能直接取得結果或透過 Laravel Storage 儲存。

## 特色

- 支援 23 種文件、試算表、簡報、繪圖與圖片輸入格式。
- 可轉換成 PDF，或依輸入格式轉成其他常用格式。
- 可從本機檔案、原始內容或 Laravel 上傳檔案開始轉換。
- 可直接取得轉換後的檔案，或儲存至任一 Laravel Storage disk。

## 系統需求

| 需求 | 支援版本或設定 |
| --- | --- |
| PHP | PHP 8.x 系列的 8.3 以上版本，並啟用 DOM 與 ZIP |
| Laravel | 12 或 13 |
| 作業系統 | Windows、Linux 或 macOS |
| 外部指令 | LibreOffice 已加入 `PATH`，或透過 `LIBREOFFICE_BINARY` 指定位置 |

## 安裝 LibreOffice

請使用作業系統的套件管理工具，或從
[LibreOffice 官方下載頁](https://www.libreoffice.org/download/)取得預先建置的 installer。
[官方安裝說明](https://www.libreoffice.org/installation-instructions/)另有 macOS、Linux 與 Windows
的詳細步驟；不需要自行編譯 LibreOffice。

### macOS

使用 [Homebrew LibreOffice cask](https://formulae.brew.sh/cask/libreoffice) 安裝：

```bash
brew install --cask libreoffice
```

未使用 Homebrew 時，請從 LibreOffice 官網下載 Apple Silicon 或 Intel 對應的 `.dmg`，再依官方說明
將 LibreOffice 移至 Applications。

### Ubuntu 與 Debian

安裝發行版提供的套件：

```bash
sudo apt update
sudo apt install libreoffice
```

Ubuntu 可在[官方套件索引](https://packages.ubuntu.com/search?keywords=libreoffice)查詢 LibreOffice，
Debian 則可透過一般套件 repository 安裝。也可以從 LibreOffice 官方下載頁取得預先建置的 `.deb`
套件。

### RHEL 與 Fedora

已啟用的發行版 repository 有提供時，可直接安裝：

```bash
sudo dnf install libreoffice
```

Fedora 可在[官方套件索引](https://packages.fedoraproject.org/pkgs/libreoffice/libreoffice/)查詢
LibreOffice。RHEL 能否直接安裝會依版本與已啟用的訂閱 repository 而異；找不到套件時，請改用
LibreOffice 官方下載頁提供的預先建置 `.rpm`，並依官方安裝說明操作。

### Windows

請從 LibreOffice 官網下載 Windows installer，再依安裝精靈完成安裝。Console executable 通常位於：

```text
C:\Program Files\LibreOffice\program\soffice.com
```

若該 executable 不在 `PATH`，請在 Laravel 環境將 `LIBREOFFICE_BINARY` 設為上述路徑。

套件不要求或解析 LibreOffice 數字版本；實際 command 與 filters 能否完成指定轉換，才是相容性判準。

## 安裝

開始安裝：

```bash
composer require mattmy/laravel-office-converter
```

預設 command 在 Windows 是 `soffice.com`，其他平台是 `soffice`。LibreOffice 位於其他位置或需要
調整限制時，再發布設定：

```bash
php artisan vendor:publish --tag=office-converter-config
```

```dotenv
LIBREOFFICE_BINARY=/usr/bin/soffice
```

套件只執行設定的 command，並處理轉換輸出、驗證與儲存；不設定或要求 sandbox、LibreOffice
profile 政策、巨集政策或程序監管機制。執行環境由應用程式自行負責。

## 快速開始

將有效的 Laravel 上傳檔案轉成 PDF，再串流至預設 Storage disk：

```php
use Mattmy\OfficeConverter\Enums\Format;
use Mattmy\OfficeConverter\Facades\Office;

$path = Office::fromUploadedFile($request->file('document'))
    ->convertTo(Format::PDF)
    ->storeAs('converted-documents', 'report.pdf');
```

`$path` 是儲存路徑；所選 Storage driver 回報失敗時則為 `false`。

## 輸入與轉換

```php
use Mattmy\OfficeConverter\Enums\InputFormat;

$document = Office::fromPath('/absolute/path/report.docx');
$document = Office::fromContent($bytes, InputFormat::DOCX);
$document = Office::fromUploadedFile($uploadedFile);
```

| 輸入家族 | 接受的輸入 | 可用輸出 |
| --- | --- | --- |
| Writer | ODT、DOC、DOCX、DOCM、RTF、TXT、HTML | PDF、ODT、DOCX、RTF、TXT、HTML |
| Calc | ODS、XLS、XLSX、XLSM、CSV | PDF、ODS、XLSX、CSV |
| Impress | ODP、PPT、PPTX、PPTM | PDF、ODP、PPTX |
| Draw | ODG、PDF、DXF、SVG、PNG、JPEG、WebP | PDF、ODG、PNG、JPEG、SVG、WebP |

每個 `InputFormat`、canonical 副檔名與各格式限制請查看
[完整 Input 到 Output 列表](https://mattmy.github.io/laravel-office-converter-doc/zh-TW/guide/supported-conversions)。

## 一次性輸出

```php
$bytes = $document->convertTo(Format::PDF)->output();

$stored = $document
    ->convertTo(Format::DOCX)
    ->storeAs('exports', 'report.xlsx', 's3');
```

`output()` 會將完整產物載入 PHP 記憶體。`storeAs()` 會串流至 Laravel Storage，並沿用所選 driver
的覆寫、`string|false` 與例外行為。它會保留提供的檔名文字，再附加可信的目標副檔名，所以上方
第二個範例會儲存為 `exports/report.xlsx.docx`。

一個 `OfficeDocument` 只能嘗試轉換一次，一個 `ConvertedOffice` 只能呼叫一次 `output()` 或
`storeAs()`。無論成功或失敗，物件都會被消費，暫存的轉換檔案也會清除。

## 錯誤與操作限制

轉換錯誤都實作 `OfficeConverterException`：`EnvironmentUnavailable`、`InvalidOfficeInput`、
`UnsupportedConversion`、`ConversionFailed` 與 `AlreadyConsumed`。不安全 Storage 目的地使用
`InvalidArgumentException`；Storage 與 Flysystem 例外會原樣拋出。

預設限制為 100 MiB 輸入、200 MiB 完成輸出，以及套件直接管理之 process 的 60 秒 timeout。套件
不保證終止完整 process tree。成功轉換不代表無損、OCR 或視覺完全相同。

## 文件

- [完整文件](https://mattmy.github.io/laravel-office-converter-doc/zh-TW/)
- [Changelog](CHANGELOG.md)
- [安全政策](SECURITY.md)

## 授權

本套件使用 MIT License，詳見 [LICENSE](LICENSE)。LibreOffice 由使用者另外安裝，適用其自身授權條款。
