---
name: graphy-checks
description: Run Graphy's quality gates (Pint, PHPStan level 7, PHPUnit, vp check, tsc) — targeted first, full suite before finishing. Use after any code change or when asked to "run checks/tests/lint".
---

# Graphy quality gates

Windows dev box. **Run PHP commands with the PowerShell tool**, where `php` resolves to Herd's PHP 8.5.
In Git Bash `php` resolves to an old 8.3 install and every artisan/vendor command dies on Composer's
platform check (deps need >= 8.4.1). From Bash, use `php.bat` (Herd shim) or `"/d/PHP 8.4/php.exe"`.
Tests use sqlite `:memory:` (phpunit.xml), so no MySQL server is needed. Output from `php artisan test` is a
compact JSON summary line (laravel/pao).

## Fast loop (while iterating)
```bash
php artisan test --filter=ClientControllerTest      # or a path: php artisan test tests/Feature/Foo.php
vendor/bin/pint --dirty                              # format only changed PHP files
vendor/bin/phpstan analyse <changed paths> --memory-limit=1G
```

## Before declaring done
```bash
vendor/bin/pint --parallel --test
vendor/bin/phpstan analyse --memory-limit=1G
php artisan test --parallel || php artisan test
npm run check          # vp check (lint/format for TS/React)
npm run types:check    # tsc --noEmit
```
`composer ci:check` runs all of this (what CI runs) but is slow — prefer the individual commands.

## Rules
- If Pint reports issues, run `vendor/bin/pint --dirty` to fix; don't hand-format.
- Don't add PHPStan baseline entries or `@phpstan-ignore` to get green — fix the type.
- If routes/controllers changed and TS fails on `@/routes/*` or `@/actions/*` imports, run
  `php artisan wayfinder:generate` first.
- Report failures verbatim (test name + assertion). Never claim green without having run it.
