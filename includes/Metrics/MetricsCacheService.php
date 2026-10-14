<?php

namespace RRZE\MultisiteManager\Metrics;

defined('ABSPATH') || exit;

/**
 * Owns versioned cache keys and invalidation state for site detail metrics.
 */
class MetricsCacheService {
    protected string $networkVersionOption;
    protected string $siteVersionMeta;
    protected int $sectionFormatVersion;
    protected int $ttl;

    public function __construct(string $networkVersionOption, string $siteVersionMeta, int $sectionFormatVersion, int $ttl) {
        $this->networkVersionOption = $networkVersionOption;
        $this->siteVersionMeta = $siteVersionMeta;
        $this->sectionFormatVersion = $sectionFormatVersion;
        $this->ttl = $ttl;
    }

    public function bumpDetailVersion(): int {
        $version = $this->getNextVersion((int)get_site_option($this->networkVersionOption, 0));
        update_site_option($this->networkVersionOption, $version);

        return $version;
    }

    public function getDetailVersion(): int {
        $version = (int)get_site_option($this->networkVersionOption, 0);

        return $version > 0 ? $version : $this->bumpDetailVersion();
    }

    public function invalidateSite(int $siteId): void {
        if ($siteId <= 0) {
            return;
        }

        update_site_meta($siteId, $this->siteVersionMeta, $this->getNextVersion($this->getStoredSiteVersion($siteId)));
    }

    public function getSiteVersion(int $siteId): int {
        $version = $this->getStoredSiteVersion($siteId);

        if ($version > 0) {
            return $version;
        }

        $version = $this->getNextVersion(0);
        update_site_meta($siteId, $this->siteVersionMeta, $version);

        return $version;
    }

    public function getSiteDetailsKey(int $siteId, array $load = []): string {
        return 'rrze_msm_site_details_' . $this->sectionFormatVersion . '_' . $this->getDetailVersion() . '_' . $this->getSiteVersion($siteId) . '_' . md5((string)$siteId . '|' . wp_json_encode($load));
    }

    public function getSectionKey(int $siteId, string $section, string $suffix = ''): string {
        return 'rrze_msm_site_detail_section_' . $this->sectionFormatVersion . '_' . $this->getDetailVersion() . '_' . $this->getSiteVersion($siteId) . '_' . md5($siteId . '|' . $section . '|' . $suffix);
    }

    public function getCurrentSection(string $section, string $suffix = ''): mixed {
        $siteId = get_current_blog_id();

        return $siteId > 0 ? get_site_transient($this->getSectionKey($siteId, $section, $suffix)) : null;
    }

    public function setCurrentSection(string $section, mixed $value, string $suffix = ''): void {
        $siteId = get_current_blog_id();

        if ($siteId > 0) {
            set_site_transient($this->getSectionKey($siteId, $section, $suffix), $value, $this->ttl);
        }
    }

    /**
     * Removes a bounded number of version-invalidated detail cache transients.
     *
     * Version changes make prior keys unreachable immediately. Removing them here
     * prevents their value and timeout rows from accumulating until Core cleanup
     * happens to run (which is commonly skipped with a persistent object cache).
     */
    public function deleteInvalidatedTransients(int $limit = 100): bool {
        global $wpdb;

        $networkId = get_current_network_id();
        $detailVersion = $this->getDetailVersion();
        $currentPrefixes = [
            'rrze_msm_site_details_' . $this->sectionFormatVersion . '_' . $detailVersion . '_',
            'rrze_msm_site_detail_section_' . $this->sectionFormatVersion . '_' . $detailVersion . '_',
            'rrze_msm_plugin_details_' . $detailVersion . '_',
            'rrze_msm_theme_details_' . $detailVersion . '_',
            'rrze_msm_site_overview_metrics_v2_' . $detailVersion . '_',
        ];
        $prefixes = [
            'rrze_msm_site_details_',
            'rrze_msm_site_detail_section_',
            'rrze_msm_plugin_details_',
            'rrze_msm_theme_details_',
            'rrze_msm_site_overview_metrics_v2_',
        ];
        $conditions = [];
        $parameters = [$networkId];

        foreach ($prefixes as $index => $prefix) {
            $conditions[] = '(meta_key LIKE %s AND meta_key NOT LIKE %s)';
            $parameters[] = '_site_transient_' . $wpdb->esc_like($prefix) . '%';
            $parameters[] = '_site_transient_' . $wpdb->esc_like($currentPrefixes[$index]) . '%';
        }

        $parameters[] = max(1, min(250, $limit));
        $sql = "SELECT meta_id, meta_key FROM {$wpdb->sitemeta} WHERE site_id = %d AND (" . implode(' OR ', $conditions) . ') ORDER BY meta_id ASC LIMIT %d';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded maintenance restricted to obsolete keys from this service.
        $rows = (array)$wpdb->get_results($wpdb->prepare($sql, ...$parameters));
        $ids = [];
        $timeoutKeys = [];

        foreach ($rows as $row) {
            $key = is_object($row) ? (string)($row->meta_key ?? '') : '';
            $id = is_object($row) ? (int)($row->meta_id ?? 0) : 0;

            if ($id <= 0 || !str_starts_with($key, '_site_transient_')) {
                continue;
            }

            $ids[] = $id;
            $timeoutKeys[] = '_site_transient_timeout_' . substr($key, strlen('_site_transient_'));
        }

        if (!empty($ids)) {
            $placeholders = implode(', ', array_fill(0, count($ids), '%d'));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- IDs were selected above from the bounded, service-owned key set.
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->sitemeta} WHERE meta_id IN ({$placeholders})", ...$ids));
        }

        if (!empty($timeoutKeys)) {
            $timeoutKeys = array_values(array_unique($timeoutKeys));
            $placeholders = implode(', ', array_fill(0, count($timeoutKeys), '%s'));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Timeout rows correspond directly to the deleted service-owned transient values.
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->sitemeta} WHERE site_id = %d AND meta_key IN ({$placeholders})", $networkId, ...$timeoutKeys));
        }

        return count($rows) >= max(1, min(250, $limit));
    }

    protected function getStoredSiteVersion(int $siteId): int {
        return $siteId > 0 ? (int)get_site_meta($siteId, $this->siteVersionMeta, true) : 0;
    }

    protected function getNextVersion(int $currentVersion): int {
        return max(time(), $currentVersion + 1);
    }
}
