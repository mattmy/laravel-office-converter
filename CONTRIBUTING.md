# Contributing

Use PHP 8.2-compatible syntax and add a focused Pest check for behavioral changes. Do not use typed class
constants or `#[Override]`; the package verifies constants and overrides with static analysis and tests.

```bash
composer install
vendor/bin/pint --test
vendor/bin/phpstan analyse
vendor/bin/pest
composer validate --strict
composer audit
```

Integration changes must also run with a real LibreOffice executable:

```bash
LIBREOFFICE_BINARY=soffice vendor/bin/pest --testsuite=Integration --fail-on-skipped
```
