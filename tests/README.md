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

The harness executes the real scanner, paginated index/statistics, payload finalization, snapshot reader and scheduler methods. Persistence, scheduling and WordPress display helpers are in-memory test doubles. It covers scan boundaries, nested/shortened directories, exact sizes above 6 GB, unknown values, deduplication across batches, cache bounds, cache invalidation during runs, phase continuation, stale events and handled-error cleanup. It also covers exact serialized byte budgets, extreme result lists/strings, safe identifiers, bounded legacy-result replacement with preserved summaries, failed writes, missing index segments, index/state admission, default/hard concurrency limits, live and unverifiable expired worker reservations, stale cleanup without payload reads, and a resumed 25-site maintenance traversal. It deliberately refuses to run inside WordPress and is not a replacement for integration or production load tests.
