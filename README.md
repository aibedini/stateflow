# StateFlow

Order state automation for WooCommerce. **SF-001 (engineering foundation) only** —
no product features yet.

## Requirements

- PHP 8.0+, WordPress 6.4+, WooCommerce 8.0+ (declared in `stateflow.php`,
  enforced at runtime by `src/Infrastructure/Environment.php`).
- HPOS compatible (declared via `FeaturesUtil`).

## Development

```bash
bin/setup-toolchain.sh                      # one-time: tools/php + composer
tools/php/php.exe tools/composer.phar install

tools/php/php.exe tools/composer.phar qa    # lint + cs + stan + unit tests
```

| Script                | What it runs                                  |
|-----------------------|-----------------------------------------------|
| `composer lint`       | `php -l` over the tree (excl. vendor/tools)   |
| `composer cs`         | WordPress Coding Standards (WPCS 3)           |
| `composer stan`       | PHPStan level max (+ WP/WC stubs)             |
| `composer test:unit`  | Unit suite (WooCommerce-absent process)       |
| `composer test:present` | Unit suite (WooCommerce-present process)    |
| `composer test:integration` | WP-core suite (needs `WP_TESTS_DIR`)    |

See `docs/ARCHITECTURE.md` for module boundaries and design decisions.
