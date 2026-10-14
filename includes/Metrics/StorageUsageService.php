<?php

namespace RRZE\MultisiteManager\Metrics;

defined('ABSPATH') || exit;

/**
 * Reads storage snapshots without starting a recursive filesystem scan.
 */
class StorageUsageService {
    public function getCurrentSiteUsage(): array {
        $usedBytes = null;
        $generatedAt = '';
        $source = '';
        // Prefer this plugin's last completed measurement to an undated Core cache.
        $snapshot = get_option('rrze_msm_site_storage_analysis_result_meta', []);
        if (is_array($snapshot) && !empty($snapshot['generated_at']) && isset($snapshot['actual_bytes']) && is_numeric($snapshot['actual_bytes'])) {
            $usedBytes = max(0, (int)$snapshot['actual_bytes']);
            $generatedAt = (string)$snapshot['generated_at'];
            $source = 'analysis';
        } else {
            $uploads = wp_get_upload_dir();
            $baseDir = untrailingslashit((string)($uploads['basedir'] ?? ''));
            $directoryCache = get_transient('dirsize_cache');
            if ($baseDir !== '' && is_array($directoryCache) && isset($directoryCache[$baseDir]) && is_int($directoryCache[$baseDir])) {
                $usedBytes = max(0, $directoryCache[$baseDir]);
                $source = 'core_cache';
            }
        }
        $siteLimit = (int)get_option('blog_upload_space');
        $networkLimit = (int)get_site_option('blog_upload_space');
        $maxMegabytes = $siteLimit > 0 ? $siteLimit : $networkLimit;
        $percent = null;
        $warnLevel = '';

        if ($maxMegabytes > 0 && $usedBytes !== null) {
            $percent = (int)round(($usedBytes / ($maxMegabytes * MB_IN_BYTES)) * 100);
            $warnLevel = $percent > 95 ? 'critical' : ($percent > 90 ? 'warning' : '');
        }

        return [
            'used_bytes' => $usedBytes,
            /* translators: %s: most recently measured storage size. */
            'used_label' => $usedBytes === null ? __('Unknown', 'rrze-multisite-manager') : sprintf(__('%s (last measured)', 'rrze-multisite-manager'), size_format($usedBytes)),
            'is_known' => $usedBytes !== null,
            'source' => $source,
            'generated_at' => $generatedAt,
            'max_bytes' => $maxMegabytes > 0 ? $maxMegabytes * MB_IN_BYTES : 0,
            'max_label' => $maxMegabytes > 0 ? size_format($maxMegabytes * MB_IN_BYTES) : '',
            'percent' => $percent,
            'warn_level' => $warnLevel,
            'is_unlimited' => $maxMegabytes <= 0,
        ];
    }
}
