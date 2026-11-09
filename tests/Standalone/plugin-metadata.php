<?php

/**
 * Plugin detail metadata is read only from readme.txt, package.json and the
 * main plugin file header; translations only from the Domain Path and
 * WP_LANG_DIR/plugins without recursion.
 *
 * Run: php tests/Standalone/plugin-metadata.php
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
define('WP_PLUGIN_DIR', sys_get_temp_dir() . '/rrze-msm-plugin-metadata-' . bin2hex(random_bytes(6)));
define('WP_LANG_DIR', WP_PLUGIN_DIR . '-languages');

function __($text, $domain = '') {
    return $text;
}

function trailingslashit(string $path): string {
    return rtrim($path, '/\\') . '/';
}

function wp_date(string $format, int $timestamp): string {
    return date($format, $timestamp);
}

function wp_strip_all_tags(string $value): string {
    return strip_tags($value);
}

function get_file_data(string $file, array $headers): array {
    $data = (string)file_get_contents($file, false, null, 0, 8192);
    $result = [];
    $matches = [];

    foreach ($headers as $key => $header) {
        $result[$key] = preg_match('/^[ \t\/*#@]*' . preg_quote($header, '/') . ':(.*)$/mi', $data, $matches) ? trim($matches[1]) : '';
    }

    return $result;
}

function check(bool $condition, string $message): void {
    if (!$condition) {
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped, WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI test output, never rendered as HTML.
        throw new RuntimeException($message);
    }
}

function loadPluginClass(string $class): void {
    $prefix = 'RRZE\\MultisiteManager\\';

    if (str_starts_with($class, $prefix)) {
        $file = dirname(__DIR__, 2) . '/includes/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
}

function removeTree(string $directory): void {
    $entry = '';

    if (!is_dir($directory)) {
        return;
    }

    foreach (scandir($directory) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        if (is_dir($directory . '/' . $entry)) {
            removeTree($directory . '/' . $entry);
        } else {
            unlink($directory . '/' . $entry);
        }
    }

    rmdir($directory);
}

spl_autoload_register('loadPluginClass');

try {
    mkdir(WP_PLUGIN_DIR . '/demo/languages', 0777, true);
    mkdir(WP_PLUGIN_DIR . '/demo/includes/deep', 0777, true);
    mkdir(WP_LANG_DIR . '/plugins', 0777, true);
    file_put_contents(WP_PLUGIN_DIR . '/demo/demo.php', "<?php\n/**\n * Plugin Name: Demo\n * License: GPL-2.0\n * License URI: https://www.gnu.org/licenses/gpl-2.0.html\n */\n");
    file_put_contents(WP_PLUGIN_DIR . '/demo/readme.txt', "=== Demo ===\nTags: alpha, beta\nTested up to: 6.9\n\n== Description ==\nHello.\n\n= Question =\nAnswer\n");
    file_put_contents(WP_PLUGIN_DIR . '/demo/package.json', json_encode(['author' => ['name' => 'RRZE', 'email' => 'author@example.org'], 'repository' => ['type' => 'git', 'url' => 'https://example.org/repo']]));
    touch(WP_PLUGIN_DIR . '/demo/languages/demo-de_DE.mo');
    touch(WP_PLUGIN_DIR . '/demo/includes/deep/demo-fr_FR.mo');
    touch(WP_LANG_DIR . '/plugins/demo-de_DE_formal.l10n.php');
    touch(WP_LANG_DIR . '/plugins/other-it_IT.mo');
    file_put_contents(WP_PLUGIN_DIR . '/single.php', "<?php\n/* Plugin Name: Single */\n");
    file_put_contents(WP_PLUGIN_DIR . '/readme.txt', "=== Foreign ===\nTags: foreign\n");

    $metrics = (new ReflectionClass(RRZE\MultisiteManager\Metrics\MetricsImplementationService::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty($metrics, 'fileLocator'))->setValue($metrics, new RRZE\MultisiteManager\Filesystem\FileLocator());
    $method = new ReflectionMethod($metrics, 'getPluginSupplementaryData');

    $demo = $method->invoke($metrics, 'demo/demo.php', ['TextDomain' => 'demo', 'DomainPath' => '/languages', 'RequiresPHP' => '8.3']);
    check($demo['license']['name'] === 'GPL-2.0', 'License was not read from the plugin header.');
    check($demo['tags'] === ['alpha', 'beta'], 'Tags were not read from readme.txt.');
    check($demo['author_email'] === 'author@example.org', 'Author e-mail was not read from package.json.');
    check($demo['repository']['url'] === 'https://example.org/repo', 'Repository was not read from package.json.');
    check($demo['translation_languages'] === ['de-DE', 'de-DE-formal'], 'Unexpected translation languages: ' . implode(', ', $demo['translation_languages']));
    check($demo['compatibility']['php_requires'] === '8.3' && $demo['compatibility']['wp_tested_up_to'] === '6.9', 'Compatibility was not merged.');
    check(str_contains($demo['readme_markdown'], '### Description') && str_contains($demo['readme_markdown'], '#### Question'), 'readme.txt headings were not converted.');
    check($demo['installation_date_label'] !== 'Not available.', 'Installation date is missing.');

    $single = $method->invoke($metrics, 'single.php', []);
    check($single['tags'] === [] && $single['readme_markdown'] === '', 'A single-file plugin used a readme.txt of the plugin directory root.');
} finally {
    removeTree(WP_PLUGIN_DIR);
    removeTree(WP_LANG_DIR);
}

echo "PASS: plugin metadata from readme.txt, package.json and the plugin header; non-recursive translation lookup; single-file plugins isolated.\n";
