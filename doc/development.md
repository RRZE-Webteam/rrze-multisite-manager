# Development

## Binding basis

The binding basis for developing and reviewing RRZE WordPress plugins is the [RRZE specification](https://github.com/RRZE-Webteam/SPEC). Repository-specific instructions in `AGENTS.md` supplement these requirements but must not conflict with them.

Key rules for this plugin:

* Use WordPress APIs and multisite context; validate input, check permissions and nonces, and escape output only at the output point.
* Do not introduce complete network scans in regular admin or frontend requests. Model network-wide work as a bounded, resumable batch.
* Do not edit generated files directly. Change JavaScript and Sass in `src/`; build the results into `build/`.
* New visible strings must use the `rrze-multisite-manager` text domain and update language files.
* New background processes require a clearly named hook, protected state, lock-expiry handling, and documentation in [Runtime state and scheduler](runtime-state.md).

## Repository structure

| Path | Contents |
| --- | --- |
| `includes/` | PHP classes, services, schedulers, and widgets |
| `includes/Metrics/` | Metrics facade, implementation, caches, detail, and storage-analysis services |
| `templates/` | PHP templates for pages and widgets |
| `src/js/` | JavaScript source files |
| `src/sass/` | Sass source files |
| `build/` | Generated JavaScript and CSS assets |
| `languages/` | POT, PO, and MO language files |
| `tests/` | WordPress PHPUnit tests and test bootstrap |
| `doc/` | Developer documentation |

## Build

`npm run dev` creates development assets, updates language files, synchronises the development version number, and generates `readme.txt`. Run this command after every completed code or documentation change.

Additional scripts:

| Command | Purpose |
| --- | --- |
| `npm run build` | Generate production assets and language files |
| `npm run prod` | Production build with version and `readme.txt` update |
| `npm run release` | Release build using the release version |
| `npm run watch` | Watch and regenerate development assets |
| `npm test` | Run PHPUnit when `vendor/bin/phpunit` is installed |

`readme.txt` is generated from `package.json`. `README.md` and files in `doc/` are manually maintained documentation.

## Tests

Tests use the official WordPress PHPUnit test framework and require a disposable test database. `WP_TESTS_DIR` must point to the WordPress test library. Details are in [tests/README.md](../tests/README.md).

Never run tests against a production WordPress installation or database. New calculation, cache, scheduler, and cleanup logic should receive at least one regression test. If the local PHPUnit dependency is missing, document that the test was not run instead of presenting it as a successful test run.

## Translations

Translations are in `languages/`. Source strings in code are English and use the `rrze-multisite-manager` text domain.

The build invokes `scripts/build-languages.js`. For new or changed strings, the script updates the POT file and existing German PO and MO files. WP-CLI can be used for a manual rebuild:

```sh
wp i18n make-pot . languages/rrze-multisite-manager.pot --domain=rrze-multisite-manager --exclude=node_modules,build,.git
```

PO files can then be maintained, for example, with Loco Translate and compiled to MO files. Generated language files belong in the same change as the PHP, JavaScript, or template string that changed.

## Documentation maintenance

For new or renamed menu pages, create or update a file in `doc/` and link it from [doc/README.md](README.md). Document new persistent options, meta keys, transients, Cron hooks, locks, and AJAX endpoints additionally in [Runtime state and scheduler](runtime-state.md). When a background workflow or its state transitions change, update the matching Mermaid flow diagram there or in [Storage analysis](storage-analysis.md) as well.
