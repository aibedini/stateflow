# StateFlow Architecture (SF-002)

StateFlow adds an explainable sales-state layer to WooCommerce products and variations without mutating their canonical price or inventory data.
SF-001 established the boundaries, guard rails and quality gates. SF-002 adds
the **persistence foundation**: State domain primitives, the two StateFlow-owned
tables, safe schema migration, and real MySQL/MariaDB coverage. Still no product
behavior: no resolver, no UI, no automation, no assignment APIs.

## Module boundaries

```
src/
├── Plugin.php                  Orchestrator only: boot(), initialize(),
│                               activate(), deactivate(). No business logic.
├── Domain/State/               Pure domain (no WordPress/WooCommerce imports).
│   ├── StateKey.php            Immutable, locale-independent key (validated,
│   │                           reserved "normal"/"inherit" rejected).
│   ├── StateDefinition.php     Definition value object (key, name, description,
│   │                           enabled, builtin, sort order, nullable ID).
│   └── DomainException.php     Domain invariant violation.
├── Infrastructure/
│   ├── Environment.php         SSOT of minimum versions (PHP 8.0,
│   │                           WP 6.4, WC 8.0) + fail-closed guard.
│   └── Database/               Persistence boundary (SF-002).
│       ├── TableNames.php      Prefix-aware table names (trusted source).
│       ├── Schema.php          Target schema (CREATE SQL, columns, indexes,
│       │                       VERSION + VERSION_OPTION constants).
│       ├── SchemaVerifier.php  Structural verification (tables/columns/
│       │                       indexes) — never on the hot path.
│       ├── MigrationLock.php   Atomic token lock (add_option semantics,
│       │                       not autoloaded, stale-recoverable).
│       ├── MigrationResult.php Explicit outcome (success/already-current/
│       │                       locked/db-unavailable/failed+errors).
│       └── MigrationRunner.php ensure_current(): fast path = cached option
│                               read + int compare; migration path = lock →
│                               dbDelta → verify → write version (last).
├── WooCommerce/                All WooCommerce-specific integration.
│   └── Compatibility.php       HPOS declaration (FeaturesUtil).
└── Admin/                      wp-admin-only surface.
    ├── RequirementsNotice.php  Degraded-mode notice (admin + cap gated).
    └── SchemaErrorNotice.php   Migration-failure notice (admin + cap gated).
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
       ├─ Environment::is_supported() true  → ensure schema current (fast path:
       │                                       cached option read + int compare);
       │                                       then service registration point
       │                                       (SF-003+). Migration failure →
       │                                       SchemaErrorNotice (admin-only).
       └─ false                             → RequirementsNotice::register()
                                               (admin_notices only; zero
                                               frontend impact)
```

`before_woocommerce_init` fires the HPOS declaration
(`FeaturesUtil::declare_compatibility('custom_order_tables', …, true)`) —
the official WooCommerce mechanism. StateFlow never queries order
storage directly in any ticket.

## Persistence semantics (SF-002)

**Absence is meaningful.** An assignment row exists only for an EXPLICIT
StateFlow state:

- Simple product: no assignment row → WooCommerce normal behavior.
- Variation: explicit assignment → use it; no assignment → inherit the
  parent product assignment; parent also has none → WooCommerce normal
  behavior.

Therefore `Normal` and `Inherit` are **virtual concepts**: they are
resolver semantics, never State definitions, never assignment rows.
`StateKey` rejects them as reserved.

## Tables

`{$wpdb->prefix}stateflow_states` — State definitions (what states exist):
key (unique), name, description, enabled, built-in flag, sort order,
UTC timestamps.

`{$wpdb->prefix}stateflow_assignments` — Explicit assignments (which
object carries which state): `object_id` (products AND variations share
the WordPress object-ID space, so no object-type column), `state_id`,
`version` (optimistic-concurrency primitive for the later
TransitionService; populated now so no disruptive retrofit is needed),
UTC timestamps.

Deactivation drops nothing: tables, rows and the schema version survive
plugin deactivation. Destructive uninstall belongs to a later dedicated
ticket and requires explicit merchant choice.

## Schema version

`Schema::VERSION` (currently 1) is the **StateFlow database schema
version** — an independent concept from the plugin release version
(`STATEFLOW_VERSION`). The installed value lives in the autoloaded
`stateflow_schema_version` option and is never inferred from the plugin
version. Every schema change increments `Schema::VERSION`.

## Foreign-key decision

No SQL FOREIGN KEY between `assignments.state_id` and `states.id`.
WordPress portability (dbDelta, arbitrary table prefixes, MariaDB/MySQL
variance, plugin lifecycle, multisite quirks) outweighs DB-level
enforcement; referential integrity is enforced by repositories/domain
services in later tickets.

## Upgrade path

Activation alone is insufficient: WordPress does not run activation hooks
when updating an already-active plugin. Therefore `initialize()` also
ensures the schema is current on every supported request (cheap version
check first), so a plugin update that bumps the schema migrates on the
next request. The migration is locked (token lock, `add_option()`
acquire, not autoloaded, finite stale timeout, released in `finally`,
token-owned release) and idempotent regardless.

## Performance

The normal initialized request performs **zero** StateFlow database
operations: the fast path is `get_option()` (cached/autoloaded) plus an
integer comparison. No `upgrade.php` load, no dbDelta, no
`SHOW TABLES`/`DESCRIBE`/`SHOW INDEX`, no lock writes, no custom-table
SELECTs on the current-schema path — proven by a query-count assertion
in the integration suite.

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
