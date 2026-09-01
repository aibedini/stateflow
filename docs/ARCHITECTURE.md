# StateFlow Architecture (SF-001)

Engineering foundation for StateFlow — StateFlow adds an explainable sales-state layer to WooCommerce products and variations without mutating their canonical price or inventory data.
This ticket deliberately implements **no product behavior** — no states,
no UI, no automation, no custom tables. It establishes the boundaries,
guard rails and quality gates every later ticket builds on.

## Module boundaries

```
src/
├── Plugin.php                  Orchestrator only: boot(), initialize(),
│                               activate(), deactivate(). No business logic.
├── Infrastructure/             Cross-cutting technical concerns.
│   └── Environment.php         SSOT of minimum versions (PHP 8.0,
│                               WP 6.4, WC 8.0) + fail-closed guard.
├── WooCommerce/                All WooCommerce-specific integration.
│   └── Compatibility.php       HPOS declaration (FeaturesUtil).
└── Admin/                      wp-admin-only surface.
    └── RequirementsNotice.php  Degraded-mode notice (admin + cap gated).
```

Reserved for later tickets (currently empty — no placeholder classes):
`src/Domain/` (state model, transitions), `src/Frontend/` (only if a
frontend surface is ever justified), `src/Automation/` (rules engine),
`src/CLI/` (WP-CLI commands), `tests/Integration/` (WP-core suite).

## Runtime flow

```
stateflow.php (bootstrap only)
  └─ StateFlow\Plugin::instance()->boot()        idempotent
       ├─ add_action('plugins_loaded', …, 20)
       ├─ Compatibility::register()              HPOS hook
       └─ register_activation/deactivation_hook

plugins_loaded
  └─ Plugin::initialize()
       ├─ Environment::is_supported() true  → service registration point (SF-002+)
       └─ false                             → RequirementsNotice::register()
                                               (admin_notices only; zero
                                               frontend impact)
```

`before_woocommerce_init` fires the HPOS declaration
(`FeaturesUtil::declare_compatibility('custom_order_tables', …, true)`) —
the official WooCommerce mechanism. StateFlow never queries order
storage directly in any ticket.

## Decisions

- **Namespace**: `StateFlow\` (PSR-4 via Composer, `src/` mapping). The
  only global functions are the plugin bootstrap's; all logic lives in
  classes.
- **Fail-closed version policy**: an *unknown* version fails
  `is_supported()` — the plugin degrades to the notice instead of
  guessing. Policy matrix is pure (`is_supported(?array $env)`) so the
  whole matrix is unit-testable without WooCommerce installed.
- **Degraded mode** registers exactly one listener (`admin_notices`),
  admin-only, capability-gated. The frontend gets no hooks, no assets,
  no queries.
- **Version SSOT**: `Environment::*` constants are the runtime source
  of truth; the `stateflow.php` header mirrors them for wp.org metadata.
  Keep the two in sync when bumping.
- **Test architecture**: unit tests run against a minimal WP-function
  stub harness (no WP install). WooCommerce presence is simulated with
  the real surface the plugin detects (the `WooCommerce` class +
  `WC_VERSION`) in a **separate PHPUnit process** (`test:present`),
  because `class_exists()` is cached per process. Integration tests
  (`test:integration`) use the real WP test suite when `WP_TESTS_DIR`
  is provided and report zero tests otherwise (CI-friendly skip).

## Performance & security stance (this ticket)

- No frontend-specific query, template, rendering, or asset hooks are
  registered by SF-001. (Lifecycle hooks — `plugins_loaded`,
  `before_woocommerce_init`, activation/deactivation — are global by
  nature; the footprint is verified by the integration suite.)
- Zero DB queries; zero enqueued assets; no React/admin app.
- No external HTTP, no telemetry, no remote code, no write endpoints.
- `composer qa` = lint + phpcs + phpstan + full unit matrix.

## Local toolchain (Windows, no global PHP required)

`bin/setup-toolchain.sh` recreates `tools/php/` (PHP 8.3 NTS) and
`tools/composer.phar`, then `composer install`. The toolchain is
git-ignored; every command runs through
`tools/php/php.exe tools/composer.phar <script>`.
