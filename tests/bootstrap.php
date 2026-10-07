<?php

declare(strict_types=1);

$wpTestsDir = getenv('WP_TESTS_DIR');

if (!is_string($wpTestsDir) || $wpTestsDir === '') {
    throw new RuntimeException('WP_TESTS_DIR must point to the WordPress test library.');
}

require_once rtrim($wpTestsDir, '/') . '/includes/functions.php';

tests_add_filter('muplugins_loaded', static function (): void {
    require dirname(__DIR__) . '/rrze-multisite-manager.php';
});

require rtrim($wpTestsDir, '/') . '/includes/bootstrap.php';
