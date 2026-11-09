<?php

/**
 * Isolated regression test for multiselect settings (rendered as checkbox
 * groups): stored selections are shown, deselecting every entry is saved,
 * and saving another tab keeps the stored selection.
 *
 * Run: php tests/Standalone/settings-multiselect.php
 */

declare(strict_types=1);

namespace {
    // Standalone CLI test: no direct web access, never inside a WordPress request.
    if (!defined('ABSPATH')) {
        if (PHP_SAPI !== 'cli') {
            exit;
        }
        define('ABSPATH', __DIR__ . '/');
    } else {
        exit(1);
    }

    function sanitize_key($key) {
        return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string)$key));
    }

    function esc_attr($value) {
        return htmlspecialchars((string)$value, ENT_QUOTES);
    }

    function esc_html($value) {
        return htmlspecialchars((string)$value, ENT_QUOTES);
    }

    function checked($checked, $current = true, $display = true) {
        return (string)$checked === (string)$current ? "checked='checked'" : '';
    }

    function check(bool $condition, string $message): void {
        if (!$condition) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped, WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI test output, never rendered as HTML.
            throw new RuntimeException($message);
        }
    }

    require_once dirname(__DIR__, 2) . '/includes/Settings.php';

    $settings = (new ReflectionClass(\RRZE\MultisiteManager\Settings::class))->newInstanceWithoutConstructor();
    $fields = [
        'general' => [
            ['name' => 'logging', 'type' => 'checkbox', 'default' => false],
        ],
        'monitoring' => [
            [
                'name' => 'availability_ignored_statuses',
                'type' => 'multiselect',
                'default' => [],
                'choices' => ['archived' => 'Archived', 'blocked' => 'Blocked', 'deleted' => 'Marked for deletion'],
            ],
        ],
    ];

    $normalize = new ReflectionMethod($settings, 'normalizeOptions');
    $render = new ReflectionMethod($settings, 'renderFieldInput');
    $key = 'monitoring_availability_ignored_statuses';
    $legacy = [
        'monitoring_source_analysis_enabled' => true,
        'monitoring_source_analysis_max_depth' => 4,
        'monitoring_source_analysis_max_php_files' => 100,
    ];
    foreach (['small' => 1, 'medium' => 2, 'large' => 3] as $profile => $threshold) {
        foreach (["storage_analysis_{$profile}_batch_media_threshold", "shortcode_block_analysis_{$profile}_batch_post_threshold",
            "storage_analysis_{$profile}_batch_runtime_threshold_seconds", "shortcode_block_analysis_{$profile}_batch_runtime_threshold_seconds"] as $fieldName) {
            $fields['monitoring'][] = ['name' => $fieldName, 'type' => 'number', 'default' => $threshold];
        }
    }
    foreach (['settingsFields' => $fields, 'optionName' => 'rrze-multisite-manager', 'settingsPrefix' => 'rrze-msm-'] as $property => $value) {
        (new ReflectionProperty($settings, $property))->setValue($settings, $value);
    }

    $settings->options = $normalize->invoke($settings, [$key => ['deleted']] + $legacy);
    check(!array_intersect_key($legacy, $settings->options), 'Retired source scanner options survived normalization.');

    // The stored selection is rendered as a checked checkbox.
    ob_start();
    $render->invoke($settings, 'rrze-msm-' . $key, $key, $fields['monitoring'][0], $settings->options[$key]);
    $html = (string)ob_get_clean();
    check(preg_match('/value="deleted" checked/', $html) === 1, 'Stored selection is not shown as checked.');
    check(preg_match('/value="archived" checked/', $html) === 0, 'Unselected entry is shown as checked.');
    check(str_contains($html, 'name="rrze-multisite-manager[' . $key . '][]" value=""'), 'Empty marker for a fully deselected group is missing.');

    // Saving another tab keeps the stored selection.
    $saved = $settings->sanitizeOptions(['general_logging' => '1']);
    check($saved[$key] === ['deleted'], 'Saving another tab reset the selection.');

    // Selecting entries saves them; the empty marker is ignored.
    $saved = $settings->sanitizeOptions([$key => ['', 'deleted', 'archived', 'deleted', 'unknown']]);
    check($saved[$key] === ['deleted', 'archived'], 'Submitted selection was not stored exactly.');

    // Deselecting every entry submits only the marker and is saved as empty.
    $saved = $settings->sanitizeOptions([$key => ['']]);
    check($saved[$key] === [], 'Deselecting every entry was not saved.');

    echo "PASS: multiselect settings show the stored selection, keep it when another tab is saved, and save a full deselection.\n";
}
