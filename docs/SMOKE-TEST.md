# Real WordPress/WooCommerce smoke test (validated on Windows, PHP 8.3)

SF-001 acceptance was verified against a real WordPress 7.1 + WooCommerce 11.0.1
with the SQLite drop-in (no MySQL needed). The environment lives in `.smoke/`
(git-ignored). Recreate with:

```bash
mkdir -p .smoke && cd .smoke
curl -sL https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar -o wp-cli.phar
curl -sL https://wordpress.org/latest.zip -o wp.zip
curl -sL https://downloads.wordpress.org/plugin/woocommerce.latest-stable.zip -o wc.zip
curl -sL https://downloads.wordpress.org/plugin/sqlite-database-integration.latest-stable.zip -o sqlite.zip
unzip -qo wp.zip && mkdir -p wp-content/plugins && \
  unzip -qo wc.zip -d wp-content/plugins && \
  unzip -qo sqlite.zip -d wp-content/plugins
mv wordpress/* . && rm -rf wordpress
cp wp-content/plugins/sqlite-database-integration/db.copy wp-content/db.php
mkdir -p wp-content/database wp-content/uploads wp-content/upgrade
# wp-config.php: DB_ENGINE=sqlite, keys, WP_DEBUG log to file (see project history)
# copy the plugin in:  wp-content/plugins/stateflow/{stateflow.php,src,vendor,composer.json}

../tools/php/php.exe wp-cli.phar core install --url=stateflow.local --title="StateFlow Smoke" \
  --admin_user=admin --admin_password=admin123 --admin_email=admin@stateflow.local --skip-email --allow-root
../tools/php/php.exe wp-cli.phar plugin activate woocommerce stateflow --allow-root
```

Probe scripts (keep): `probe.sh` (frontend requests + debug log),
`probe2.sh` (HPOS FeaturesController, absent path), `probe3.sh`
(StateFlow callback scan over the real `$wp_filter` registry).

## SF-001 results on the real stack (2026-09-01)

- `wp plugin activate/deactivate/activate stateflow` → all Success; repeatable.
- `wp-content/debug.log`: **zero** StateFlow warnings/notices/deprecations.
  (The only entry ever seen was WooCommerce's own `_load_textdomain_just_in_time`
  notice, present before StateFlow activation.)
- HPOS: `FeaturesController::get_compatible_plugins_for_feature('custom_order_tables')`
  → `compatible: ["stateflow/stateflow.php"]`.
- Hook footprint in real WP: exactly `plugins_loaded`,
  `before_woocommerce_init`, `activate_stateflow/stateflow.php`,
  `deactivate_stateflow/stateflow.php`. **No** frontend hooks.
- Home/post requests render 200 with 0 StateFlow assets/references
  (the 5 page mentions of "stateflow" were the site title only).
- Frontend still returns 200 with WooCommerce deactivated (absent path).
