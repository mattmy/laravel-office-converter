# Laravel Office Converter

[English](README.md)

在 Laravel 中使用 LibreOffice 轉換文件、試算表、簡報、繪圖與圖片。

## 特色

- 23 種支援的輸入格式都可轉成 PDF，也可依輸入格式選擇其他輸出。
- 使用本機檔案、原始位元組或 Laravel 上傳檔案。
- 讀取轉換後的位元組，或串流寫入 Laravel Storage 磁碟。

## 系統需求

| 需求 | 支援版本或設定 |
| --- | --- |
| PHP | PHP 8.x 系列的 8.2 以上版本，並啟用 DOM、XML 與 ZIP |
| Laravel | 12（PHP 8.2 以上）或 13（PHP 8.3 以上） |
| 作業系統 | Windows、Linux 或 macOS |
| LibreOffice | 另外安裝，並將執行檔加入 `PATH`，或透過 `LIBREOFFICE_BINARY` 指定位置 |

相容性取決於安裝的 LibreOffice 能否完成指定轉換。套件不檢查最低版本。

## 安裝 LibreOffice

請使用作業系統的套件管理工具，或從
[LibreOffice 官方下載頁](https://www.libreoffice.org/download/)取得安裝程式。
[官方安裝說明](https://www.libreoffice.org/installation-instructions/)涵蓋 macOS、Linux 與 Windows。

### macOS

使用 [Homebrew LibreOffice cask](https://formulae.brew.sh/cask/libreoffice) 安裝：

```bash
brew install --cask libreoffice
```

未使用 Homebrew 時，請從 LibreOffice 官網下載 Apple Silicon 或 Intel 對應的 `.dmg`，並將
LibreOffice 移至「應用程式」資料夾。若應用程式的 `PATH` 找不到 `soffice`，請將
`LIBREOFFICE_BINARY` 設為 `/Applications/LibreOffice.app/Contents/MacOS/soffice`。

### Ubuntu 與 Debian

安裝發行版提供的套件：

```bash
sudo apt update
sudo apt install libreoffice
```

### RHEL 與 Fedora

已啟用的軟體來源有提供時，可直接安裝：

```bash
sudo dnf install libreoffice
```

若使用的 RHEL 版本與已啟用的軟體來源未提供套件，請使用 LibreOffice 官網提供的 `.rpm` 套件。

### Windows

請從 LibreOffice 官網下載 Windows 安裝程式。一般安裝位置的執行檔路徑如下。

```text
C:\Program Files\LibreOffice\program\soffice.com
```

若執行檔不在 `PATH`，請在 `.env` 將 `LIBREOFFICE_BINARY` 設為上述路徑，並用單引號括住路徑。

## 安裝

```bash
composer require mattmy/laravel-office-converter
```

套件預設在 Windows 尋找 `soffice.com`，其他平台尋找 `soffice`。若執行檔不在 `PATH`，
在 `.env` 指定路徑即可。

```dotenv
LIBREOFFICE_BINARY=/usr/bin/soffice
```

要調整逾時時間、大小上限或暫存資料夾，請發布設定檔。

```bash
php artisan vendor:publish --tag=office-converter-config
```

如果使用 Laravel `config:cache`，請在修改設定檔或 `LIBREOFFICE_BINARY` 後重建設定快取。

## 快速開始

將有效的 Laravel 上傳檔案轉成 PDF，再串流寫入預設的 Storage 磁碟。

```php
use Mattmy\OfficeConverter\Enums\Format;
use Mattmy\OfficeConverter\Facades\Office;

$path = Office::fromUploadedFile($request->file('document'))
    ->convertTo(Format::PDF)
    ->storeAs('converted-documents', 'report.pdf');
```

成功時 `$path` 為 `converted-documents/report.pdf`。若磁碟回傳 `false`，`storeAs()` 也會回傳 `false`。

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

請從輸入格式所在的同一列選擇輸出。其他組合會在 LibreOffice 啟動前拋出 `UnsupportedConversion`。
[支援的轉換](https://mattmy.github.io/laravel-office-converter-doc/zh-TW/guide/supported-conversions)

## 一次性輸出

```php
$bytes = $document->convertTo(Format::PDF)->output();

$stored = Office::fromPath('/absolute/path/report.docx')
    ->convertTo(Format::DOCX)
    ->storeAs('exports', 'report.xlsx', 's3');
```

`output()` 會將整個檔案載入 PHP 記憶體。`storeAs()` 會串流寫入 Laravel Storage，並沿用所選磁碟的
覆寫規則。最後的副檔名由目標格式決定，第二個範例會將 `report.xlsx` 儲存為 `exports/report.xlsx.docx`。

每個 `OfficeDocument` 只能嘗試轉換一次，即使失敗也須重新建立來源。每個 `ConvertedOffice` 只能呼叫一次
`output()` 或 `storeAs()`。即使讀取或儲存失敗，套件也會清理暫存檔案。

## 錯誤與操作限制

用 `OfficeConverterException` 捕捉套件例外，包含 `EnvironmentUnavailable`、`InvalidOfficeInput`、
`UnsupportedConversion`、`ConversionFailed` 與 `AlreadyConsumed`。儲存路徑或檔名無效時會拋出
`InvalidArgumentException`。Storage 與 Flysystem 例外會原樣拋出。

預設輸入大小上限為 100 MiB，轉換完成後的輸出上限為 200 MiB，逾時時間為 60 秒。逾時只涵蓋套件
直接管理的程序。沙箱、LibreOffice 使用者設定檔、巨集政策與子程序由應用程式自行管理。

轉換後的版面與格式可能不同。PDF 轉換不會執行 OCR。

## 文件

- [完整文件](https://mattmy.github.io/laravel-office-converter-doc/zh-TW/)
- 請從套件原始碼儲存庫取得範例。Composer 發布封裝與 Git 匯出都排除這些範例。[HTML 轉換範例](https://github.com/mattmy/laravel-office-converter/tree/main/docx_tests)
- [版本變更紀錄](CHANGELOG.md)
- [安全政策](SECURITY.md)

## 授權

本套件使用 MIT License，詳見 [LICENSE](LICENSE)。LibreOffice 由使用者另外安裝，適用其自身授權條款。
