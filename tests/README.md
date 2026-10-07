# Automated tests

The tests use the official WordPress PHPUnit test framework and require a disposable WordPress test database.

Install the framework with the WordPress test-library tooling, set `WP_TESTS_DIR` to its directory, install PHPUnit in the project, then run:

```sh
WP_TESTS_DIR=/path/to/wordpress-tests-lib vendor/bin/phpunit
```

The test suite loads the plugin as a must-use test plugin. Tests must never run against a productive WordPress installation or database.
