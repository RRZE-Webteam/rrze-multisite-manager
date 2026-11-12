<?php

/**
 * Manual database round-trip measurement: summary values and the bounded,
 * newest-first list of runs.
 *
 * Run: php tests/Standalone/database-latency-probe.php
 */

declare(strict_types=1);

// Standalone CLI test: no direct web access, never inside a WordPress request.
if (!defined('ABSPATH')) {
    if (PHP_SAPI !== 'cli') {
        exit;
    }
    define('ABSPATH', __DIR__ . '/');
} else {
    exit(1);
}

function check(bool $condition, string $message): void {
    if (!$condition) {
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped, WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI test output, never rendered as HTML.
        throw new RuntimeException($message);
    }
}

require_once dirname(__DIR__, 2) . '/includes/Support/DatabaseLatencyProbe.php';

use RRZE\MultisiteManager\Support\DatabaseLatencyProbe;

$summary = DatabaseLatencyProbe::summarize([4.0, 1.0, 3.0, 2.0]);
check($summary['count'] === 4, 'Query count is wrong.');
check($summary['min_ms'] === 1.0 && $summary['max_ms'] === 4.0, 'Minimum or maximum is wrong.');
check($summary['median_ms'] === 2.5, 'Median of an even count is wrong.');
check($summary['avg_ms'] === 2.5, 'Mean is wrong.');

$summary = DatabaseLatencyProbe::summarize([0.3, 9.0, 0.4]);
check($summary['median_ms'] === 0.4, 'Median of an odd count is wrong; an outlier must not shift it.');

$summary = DatabaseLatencyProbe::summarize([]);
check($summary['count'] === 0 && $summary['median_ms'] === 0.0, 'An empty measurement must yield zero values.');

$runs = [];

for ($index = 1; $index <= DatabaseLatencyProbe::MAX_RUNS + 5; $index++) {
    $runs = DatabaseLatencyProbe::addRun($runs, ['t' => $index]);
}

check(count($runs) === DatabaseLatencyProbe::MAX_RUNS, 'The list of runs is not bounded.');
check($runs[0]['t'] === DatabaseLatencyProbe::MAX_RUNS + 5, 'The newest run must come first.');

echo "database-latency-probe: OK\n";
