# AGENTS.md — StateFlow

WordPress/WooCommerce plugin (order state automation). Currently at SF-001
(engineering foundation only — no product features).

## Environment

- Windows host; **no system PHP**. Use the repo-local toolchain:
  - PHP: `tools/php/php.exe` (8.3)
  - Composer: `tools/php/php.exe tools/composer.phar <cmd>`
- Fresh clone: `bin/setup-toolchain.sh` (bash) then
  `tools/php/php.exe tools/composer.phar install`
- `.smoke/` contains a disposable WordPress+WooCommerce install (SQLite) for
  manual verification — never scan, lint, or commit it. It is git-ignored.

## Quality gates (all must pass before any commit)

```bash
tools/php/php.exe tools/composer.phar qa     # lint + phpcs + phpstan + unit tests
```

Individual gates:

| Command | Tool |
|---|---|
| `…composer.phar lint` | `php -l` over src/tests/bin + stateflow.php |
| `…composer.phar cs` | WordPress Coding Standards (WPCS 3) |
| `…composer.phar stan` | PHPStan level max (+ WP/WC stubs) |
| `…composer.phar test:unit` | PHPUnit, WooCommerce-absent process |
| `…composer.phar test:present` | PHPUnit, WooCommerce-present process |

## Conventions

- All PHP in the `StateFlow\` namespace (PSR-4 → `src/`); no global functions
  outside `stateflow.php` (bootstrap only).
- Minimum versions (SSOT): `src/Infrastructure/Environment.php` — the plugin
  header in `stateflow.php` mirrors them; keep both in sync when bumping.
- TDD: tests live in `tests/Unit` (two processes: WooCommerce present vs
  absent — do not merge them, `class_exists()` is cached per process).
- HPOS: declare via `src/WooCommerce/Compatibility.php` only; never query
  WooCommerce order storage directly.
- No frontend hooks/assets/DB queries; no external HTTP; fail-closed on
  unknown versions.
- Full architecture: `docs/ARCHITECTURE.md`; real-stack verification:
  `docs/SMOKE-TEST.md`.
