<?php

namespace RRZE\MultisiteManager\Metrics;

defined('ABSPATH') || exit;

/**
 * Calculates the current website's storage quota and usage.
 */
class StorageUsageService {
    public function getCurrentSiteUsage(): array {
        $megabytes = function_exists('get_space_used') ? (int)get_space_used() : 0;
        $siteLimit = (int)get_option('blog_upload_space');
        $networkLimit = (int)get_site_option('blog_upload_space');
        $maxMegabytes = $siteLimit > 0 ? $siteLimit : $networkLimit;
        $percent = null;
        $warnLevel = '';

        if ($maxMegabytes > 0) {
            $percent = (int)round(($megabytes / $maxMegabytes) * 100);
            $warnLevel = $percent > 95 ? 'critical' : ($percent > 90 ? 'warning' : '');
        }

        return [
            'used_bytes' => max(0, $megabytes) * MB_IN_BYTES,
            'used_label' => size_format(max(0, $megabytes) * MB_IN_BYTES),
            'max_bytes' => $maxMegabytes > 0 ? $maxMegabytes * MB_IN_BYTES : 0,
            'max_label' => $maxMegabytes > 0 ? size_format($maxMegabytes * MB_IN_BYTES) : '',
            'percent' => $percent,
            'warn_level' => $warnLevel,
            'is_unlimited' => $maxMegabytes <= 0,
        ];
    }
}
