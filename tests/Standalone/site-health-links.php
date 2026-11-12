<?php

/**
 * Direct links into the Site Health Info tab: URL building, permission
 * check, accepted section keys and the script that opens the section.
 *
 * Run: php tests/Standalone/site-health-links.php
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

$GLOBALS['test_can_view'] = true;
$GLOBALS['test_enqueued'] = [];
$GLOBALS['test_inline'] = '';

function check(bool $condition, string $message): void {
    if (!$condition) {
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped, WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI test output, never rendered as HTML.
        throw new RuntimeException($message);
    }
}

function current_user_can(string $capability): bool {
    return $capability === 'view_site_health_checks' && $GLOBALS['test_can_view'];
}

function get_main_site_id(): int {
    return 1;
}

function get_admin_url(int $siteId, string $path): string {
    return 'https://example.test/wp-admin/' . $path;
}

function add_query_arg(array $args, string $url): string {
    return $url . '?' . http_build_query($args);
}

function sanitize_key(string $key): string {
    return preg_replace('/[^a-z0-9_\-]/', '', strtolower($key));
}

function wp_unslash(string $value): string {
    return $value;
}

function wp_json_encode($value): string {
    return (string)json_encode($value);
}

function wp_register_script(string $handle): void {
    $GLOBALS['test_enqueued'][] = 'register:' . $handle;
}

function wp_enqueue_script(string $handle): void {
    $GLOBALS['test_enqueued'][] = 'enqueue:' . $handle;
}

function wp_add_inline_script(string $handle, string $script): void {
    $GLOBALS['test_inline'] = $script;
}

require_once dirname(__DIR__, 2) . '/includes/Support/SiteHealthLinks.php';

use RRZE\MultisiteManager\Support\SiteHealthLinks;

check(SiteHealthLinks::getUrl('wp-database') === 'https://example.test/wp-admin/site-health.php?tab=debug&rrze-msm-section=wp-database', 'Unexpected section URL.');
check(SiteHealthLinks::getUrl() === 'https://example.test/wp-admin/site-health.php?tab=debug', 'Unexpected Info tab URL.');
check(SiteHealthLinks::getUrl('wp-paths-sizes') === 'https://example.test/wp-admin/site-health.php?tab=debug', 'A section that core omits in multisite was accepted.');
check(count(SiteHealthLinks::getLinks(['wp-server' => 'Server', 'wp-constants' => 'Constants'])) === 2, 'Links were not built.');

$GLOBALS['test_can_view'] = false;
check(SiteHealthLinks::getUrl('wp-server') === '', 'A link was built without permission.');
check(SiteHealthLinks::getLinks(['wp-server' => 'Server']) === [], 'Links were listed without permission.');
$GLOBALS['test_can_view'] = true;

$_GET[SiteHealthLinks::QUERY_ARG] = 'wp-server';
SiteHealthLinks::enqueueSectionOpener('index.php');
check($GLOBALS['test_enqueued'] === [], 'The script was added outside Site Health.');

SiteHealthLinks::enqueueSectionOpener('site-health.php');
check(in_array('enqueue:' . SiteHealthLinks::SCRIPT_HANDLE, $GLOBALS['test_enqueued'], true), 'The script was not enqueued on Site Health.');
check(str_contains($GLOBALS['test_inline'], 'var section = "wp-server";'), 'The requested section is missing in the script.');
check(str_contains($GLOBALS['test_inline'], 'health-check-accordion-block-'), 'The script does not address the core accordion.');
check(!str_contains($GLOBALS['test_inline'], '=>') && !preg_match('/function\s*\(/', $GLOBALS['test_inline']), 'The script contains an anonymous function.');

$GLOBALS['test_enqueued'] = [];
$_GET[SiteHealthLinks::QUERY_ARG] = 'x"><script>';
SiteHealthLinks::enqueueSectionOpener('site-health.php');
check($GLOBALS['test_enqueued'] === [], 'An unknown section was accepted.');

echo "site-health-links: OK\n";
