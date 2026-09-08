# Laravel Office Converter

以固定 allowlist 封裝 LibreOffice CLI 的 Laravel 單檔轉換套件。套件會先驗證輸入、建立私有快照，再以 shell-free argv 執行 configured executable；成功結果只能讀取或存入 Laravel Storage 一次。

## 需求

- PHP 8.3–8.5，並啟用 `ext-dom`、`ext-zip`
- Laravel 12 或 13
- LibreOffice；套件不設定數字版號下限，也不解析版本字串。實際命令與 filter 能否完成轉換才是相容性判準。production 必須使用符合下方部署契約的 wrapper／supervisor executable

## 安裝與設定

```bash
composer require mattmy/laravel-office-converter
php artisan vendor:publish --tag=office-converter-config
```

```env
LIBREOFFICE_BINARY=/usr/local/bin/office-converter-wrapper
```

`config/office-converter.php` 另可設定 timeout、輸入／輸出 byte 上限與 package workspace 根目錄。設定變更後可正常使用 Laravel `config:cache`。

## 使用方式

```php
use Mattmy\OfficeConverter\Enums\Format;
use Mattmy\OfficeConverter\Enums\InputFormat;
use Mattmy\OfficeConverter\Facades\Office;

$pdf = Office::fromPath('/absolute/path/report.docx')
    ->convertTo(Format::PDF)
    ->output();

$stored = Office::fromContent($bytes, InputFormat::DOCX)
    ->convertTo(Format::PDF)
    ->storeAs('exports', 'report', 's3');

$uploaded = Office::fromUploadedFile($request->file('document'))
    ->convertTo(Format::PDF)
    ->storeAs('', 'report', 'local');
```

`fromPath()` 只接受 absolute、非 symlink 的 regular file；`fromContent()` 必須明確提供 `InputFormat`。輸入與輸出格式只能使用兩個 enum，不能傳入任意 filter、extension、option 或 CLI flag。

`OfficeDocument` 與 `ConvertedOffice` 都是一次性物件：第一次轉換或 terminal 操作無論成功失敗都會消費物件並清理 package-owned workspace。`storeAs()` 保留 Laravel Storage 的覆寫、`string|false` 與底層例外語意。它保留使用者傳入的完整檔名，並由目標 `Format` 附加最後副檔名：`png-38.jpg` 轉 DOCX 會儲存為 `png-38.jpg.docx`；已帶 `.docx` 的名稱不重複附加。

## 格式邊界

Writer、Calc、Impress 與 Draw 各自只接受 enum 定義的目標矩陣。HTML 若產生 sidecar 會失敗；圖片輸出只接受單一 artifact；多頁 Draw／PDF 若要保留全部頁面，請輸出 PDF。轉換成功表示 LibreOffice 產生一個通過格式與大小驗證的 artifact，不代表內容無損或 pixel-perfect。

圖片輸出沿用 LibreOffice 一般 command，且只接受單一 artifact。套件不提供頁數探索或指定頁面；LibreOffice 選擇哪個預設頁面不屬於套件契約。

## Production 部署契約

`binary` 必須指向 wrapper 或受 supervisor 管理的 executable。每次 invocation 必須建立獨立 hardened LibreOffice `UserInstallation`，設定 Macro Security=Very High、工作目錄不在 trusted locations、external links 更新為 Never，並在 timeout 後清除完整 LibreOffice process tree。wrapper 只能加入 profile 隔離，不得改寫套件傳入的格式、輸入或輸出 arguments。

套件本身不提供 sandbox。請另外限制執行帳號權限、網路、CPU、記憶體、程序數與暫存磁碟空間；不要以 `--headless` 取代隔離。

## 品質檢查

```bash
composer validate --strict
vendor/bin/pint --test
vendor/bin/phpstan analyse
vendor/bin/pest
composer audit
```

真實 interoperability suite 需設定 `LIBREOFFICE_BINARY`，並以 `vendor/bin/pest --testsuite=Integration --fail-on-skipped` 執行。

## 授權

[MIT](LICENSE)
