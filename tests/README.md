# Automated tests

The tests use the official WordPress PHPUnit test framework and require a disposable WordPress test database.

Install the framework with the WordPress test-library tooling, set `WP_TESTS_DIR` to its directory, install PHPUnit in the project, then run:

```sh
WP_TESTS_DIR=/path/to/wordpress-tests-lib vendor/bin/phpunit
```

The test suite loads the plugin as a must-use test plugin. Tests must never run against a productive WordPress installation or database.

## Isolated storage resource regressions

Run without WordPress or a database:

```sh
php -d memory_limit=64M -d error_reporting=-1 tests/Standalone/storage-resources.php
```

The harness executes the real scanner, paginated index/statistics, payload finalization, snapshot reader and scheduler methods. Central persistence uses an isolated in-memory SQLite adapter; legacy persistence, scheduling and WordPress display helpers are test doubles. It covers scan boundaries, nested/shortened directories, exact sizes above 6 GB, unknown values, deduplication across batches, cache bounds, cache invalidation during runs, phase continuation, stale events and handled-error cleanup. It also covers exact serialized byte budgets, extreme result lists/strings, safe identifiers, bounded legacy-result replacement with preserved summaries, failed writes, missing index segments, index/state admission, default/hard concurrency limits, live and unverifiable expired worker reservations, stale cleanup without payload reads, and a resumed 25-site maintenance traversal. It deliberately refuses to run inside WordPress and is not a replacement for integration or production load tests.

## Central analysis persistence regressions

```sh
php -d memory_limit=64M -d error_reporting=-1 tests/Standalone/analysis-storage.php
```

Both standalone harnesses require PDO SQLite. `tests/Support/AnalysisDatabase.php` translates the small MySQL query subset into an isolated in-memory SQLite database; WordPress boundaries remain test doubles. Coverage includes separated phase checkpoints/findings, idempotent replay, transactional rollback, failed publication retaining the old generation, paging/filtering, network/site isolation, expiry, bounded cleanup, legacy removal without payload reads, reanalysis flags and collection byte limits. The storage harness also exercises central media-index persistence and used-attachment segments.

These tests do **not** validate `dbDelta()`, MySQL/MariaDB locking or engine behaviour, real object-cache backends, WordPress rendering, or production load. Before deployment, run the WordPress integration suite against a disposable MySQL/MariaDB multisite and verify upgrade, resumption, cleanup and the paged admin interface.
