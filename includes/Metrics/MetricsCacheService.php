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
        return 'rrze_msm_site_details_' . $this->getDetailVersion() . '_' . $this->getSiteVersion($siteId) . '_' . md5((string)$siteId . '|' . wp_json_encode($load));
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

    protected function getStoredSiteVersion(int $siteId): int {
        return $siteId > 0 ? (int)get_site_meta($siteId, $this->siteVersionMeta, true) : 0;
    }

    protected function getNextVersion(int $currentVersion): int {
        return max(time(), $currentVersion + 1);
    }
}
