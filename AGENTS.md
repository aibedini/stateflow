# AGENTS.md — StateFlow

WordPress/WooCommerce plugin. StateFlow adds an explainable sales-state layer to WooCommerce products and variations without mutating their canonical price or inventory data. Currently at SF-002.1 — persistence foundation complete and frozen after contract hardening.

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

## Persistence contract (binding for all future tickets — SF-002 §34)

- **Never** store virtual Normal/Inherited states as assignment rows.
  Absence of a row IS the normal/inherited semantics; `StateKey` rejects
  both as reserved keys.
- **Never** write StateFlow state into WooCommerce stock, price, post
  status or catalog visibility. Canonical WooCommerce data stays canonical.
- **Never** bypass `MigrationRunner` for schema changes. All DDL goes
  through `ensure_current()` (lock → dbDelta → verify → write version).
- **Every** schema change increments `Schema::VERSION` and ships a
  migration + integration tests on the full database matrix.
- **Never** edit an old migration's meaning after release. Schema
  versions migrate strictly forward.
- **No** destructive schema operation (DROP, data-erasing ALTER) without
  an explicit migration and a dedicated test.
- **No** table/index checks (`SHOW TABLES`, `DESCRIBE`, `SHOW INDEX`,
  dbDelta) on hot frontend paths — the schema-current fast path is a
  cached option read plus an integer comparison, nothing else.
