<?php

/**
 * Response-time sampling: request classification, aggregation by category
 * and concurrent analyses, percentile bounds and the bounded sample list.
 *
 * Run: php tests/Standalone/response-time-sampler.php
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

require_once dirname(__DIR__, 2) . '/includes/Support/ResponseTimeSampler.php';

use RRZE\MultisiteManager\Support\ResponseTimeSampler;

check(ResponseTimeSampler::classifyRestRequest('POST', '/wp/v2/posts/12') === 'save', 'REST update was not classified as save.');
check(ResponseTimeSampler::classifyRestRequest('PUT', '/wp/v2/pages/3') === 'save', 'REST page update was not classified as save.');
check(ResponseTimeSampler::classifyRestRequest('POST', '/wp/v2/posts') === 'save', 'REST create was not classified as save.');
check(ResponseTimeSampler::classifyRestRequest('POST', '/wp/v2/posts/12/autosaves') === 'autosave', 'Autosave was not separated.');
check(ResponseTimeSampler::classifyRestRequest('POST', '/wp/v2/media') === 'upload', 'Media upload was not classified.');
check(ResponseTimeSampler::classifyRestRequest('GET', '/wp/v2/posts/12') === '', 'A read request was sampled.');
check(ResponseTimeSampler::classifyRestRequest('POST', '/wp/v2/products/1') === '', 'A custom post type was sampled.');
check(ResponseTimeSampler::classifyAdminRequest('post.php', 'GET') === 'editor_load', 'Editor load was not classified.');
check(ResponseTimeSampler::classifyAdminRequest('post.php', 'POST') === 'save', 'Classic save was not classified.');
check(ResponseTimeSampler::classifyAdminRequest('async-upload.php', 'POST') === 'upload', 'Classic upload was not classified.');
check(ResponseTimeSampler::classifyAdminRequest('index.php', 'GET') === '', 'An unrelated admin page was sampled.');

$stats = [];
$stats = ResponseTimeSampler::addSample($stats, ['t' => 100, 'category' => 'save', 'ms' => 120.0, 'queries' => 40, 'workers' => 0]);
$stats = ResponseTimeSampler::addSample($stats, ['t' => 101, 'category' => 'save', 'ms' => 1800.0, 'queries' => 60, 'workers' => 2]);
$stats = ResponseTimeSampler::addSample($stats, ['t' => 102, 'category' => 'save', 'ms' => 7000.0, 'queries' => 80, 'workers' => 1]);
$stats = ResponseTimeSampler::addSample($stats, ['t' => 103, 'category' => 'unknown', 'ms' => 1.0, 'workers' => 0]);

check($stats['since'] === 100, 'Start of recording was not kept.');
check($stats['categories']['save']['idle']['count'] === 1, 'Idle sample was not counted separately.');
check($stats['categories']['save']['during_analysis']['count'] === 2, 'Samples during analyses were not counted together.');
check($stats['categories']['save']['during_analysis']['max_ms'] === 7000.0, 'Maximum was not kept.');
check($stats['categories']['save']['during_analysis']['total_queries'] === 140, 'Database queries were not summed.');
check($stats['categories']['save']['during_analysis']['buckets'] === [0, 0, 0, 1, 0, 1], 'Unexpected histogram.');
check(!isset($stats['categories']['unknown']) && count($stats['recent']) === 3, 'Unknown categories must be ignored.');
check($stats['recent'][0]['t'] === 102, 'Newest sample must come first.');

check(ResponseTimeSampler::getPercentileBound([10, 0, 0, 0, 0, 0], 0.95) === 250, 'Percentile bound in the first bucket is wrong.');
check(ResponseTimeSampler::getPercentileBound([90, 6, 4, 0, 0, 0], 0.95) === 500, 'Percentile bound is wrong.');
check(ResponseTimeSampler::getPercentileBound([1, 0, 0, 0, 0, 9], 0.95) === 0, 'Open last bucket must return 0.');

for ($i = 0; $i < ResponseTimeSampler::MAX_RECENT + 20; $i++) {
    $stats = ResponseTimeSampler::addSample($stats, ['t' => 200 + $i, 'category' => 'upload', 'ms' => 300.0, 'workers' => 0]);
}

check(count($stats['recent']) === ResponseTimeSampler::MAX_RECENT, 'Sample list is not bounded.');

echo "PASS: request classification, aggregation by concurrent analyses, percentile bounds and bounded sample list.\n";
