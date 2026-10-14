<?php

namespace RRZE\MultisiteManager\Support;

defined('ABSPATH') || exit;

/** Bounds serialized payloads without first serializing an unbounded input. */
class BoundedArray {
    public static function fits(mixed $value, int $limit): bool {
        return self::measure($value, $limit, 0);
    }

    private static function measure(mixed $value, int &$remaining, int $depth): bool {
        if ($depth > 16) {
            return false;
        }
        if (is_array($value)) {
            $remaining -= 5 + strlen((string)count($value));
            foreach ($value as $key => $child) {
                if ($remaining < 0 || !self::measure($key, $remaining, $depth + 1) || !self::measure($child, $remaining, $depth + 1)) {
                    return false;
                }
            }
        } elseif (is_string($value)) {
            $length = strlen($value);
            $remaining -= $length + strlen((string)$length) + 6;
        } elseif (is_scalar($value) || $value === null) {
            $remaining -= strlen(serialize($value));
        } else {
            return false;
        }
        return $remaining >= 0;
    }

    public static function compact(array $value, int $limit): array {
        $remaining = max(128, $limit - 64);
        $truncated = false;
        // Keep counters and timestamps before spending space on verbose lists.
        $ordered = [];
        foreach ($value as $key => $child) {
            if (is_scalar($child) || $child === null) {
                $ordered[$key] = $child;
            }
        }
        foreach (['wordpress_storage', 'attachment_stats', 'counts', 'summary_rows'] as $key) {
            if (isset($value[$key])) {
                $ordered[$key] = $value[$key];
            }
        }
        $result = self::copy($ordered + $value, $remaining, $truncated, 0);
        if ($truncated) {
            $result['result_truncated'] = true;
        }
        if (!self::fits($result, $limit)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Escaped by callers when displayed.
            throw new \RuntimeException(__('The analysis result could not be reduced to its storage limit.', 'rrze-multisite-manager'));
        }
        return $result;
    }

    private static function copy(mixed $value, int &$remaining, bool &$truncated, int $depth, string $field = ''): mixed {
        if ($depth >= 8 || $remaining < 32) {
            $truncated = true;
            $remaining -= 2;
            return null;
        }
        if (is_array($value)) {
            $remaining -= 16;
            $result = [];
            foreach ($value as $key => $child) {
                if (count($result) >= 500 || (is_string($key) && strlen($key) > 128) || $remaining < 160) {
                    $truncated = true;
                    break;
                }
                $remaining -= is_string($key) ? strlen($key) + 16 : 32;
                $result[$key] = self::copy($child, $remaining, $truncated, $depth + 1, (string)$key);
            }
            return $result;
        }
        if (is_string($value)) {
            $maximum = min(2048, max(0, $remaining - 32));
            if (strlen($value) > $maximum && in_array($field, ['path', 'file_url', 'edit_url', 'view_url', 'media_edit_url', 'upload_basedir', 'upload_baseurl'], true)) {
                // Never turn a cut-off path or action URL into a different deletion target.
                $truncated = true;
                $remaining -= 16;
                return '';
            }
            if (strlen($value) > $maximum) {
                $value = function_exists('mb_strcut') ? mb_strcut($value, 0, $maximum, 'UTF-8') : wp_check_invalid_utf8(substr($value, 0, $maximum), true);
                $truncated = true;
            }
            $remaining -= strlen($value) + 16;
            return $value;
        }
        if (is_scalar($value) || $value === null) {
            $remaining -= 32;
            return $value;
        }
        $truncated = true;
        $remaining -= 2;
        return null;
    }
}
