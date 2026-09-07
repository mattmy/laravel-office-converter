# Contributing

Use PHP 8.3-compatible syntax and add a focused Pest check for behavioral changes.

```bash
composer install
vendor/bin/pint --test
vendor/bin/phpstan analyse
vendor/bin/pest
composer validate --strict
composer audit
```

Integration changes must also run with LibreOffice 26.2:

```bash
LIBREOFFICE_BINARY=soffice vendor/bin/pest --testsuite=Integration --fail-on-skipped
```
