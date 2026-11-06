<?php

namespace RRZE\MultisiteManager\Metrics;

defined('ABSPATH') || exit;

trait MetricsServiceSiteTrait {
    public function getSiteDetails(int $siteId, array $load = []): array {
        $site = $siteId > 0 ? get_site($siteId) : null;
        $cacheKey = '';
        $cached = null;
        $formattedSites = [];
        $details = [];

        if (!$site instanceof \WP_Site) {
            return [];
        }

        $cacheKey = $this->getSiteDetailsCacheKey($siteId, $load);
        $cached = get_site_transient($cacheKey);

        if (is_array($cached) && !empty($cached)) {
            return $cached;
        }

        $formattedSites = $this->formatSites([$site], true);

        if (empty($formattedSites[0]) || !is_array($formattedSites[0])) {
            return [];
        }

        $details = $formattedSites[0];
        $details['status_user'] = $this->getStatusUserData((int)($details['status_user_id'] ?? 0));
        $details = array_merge($details, $this->getSiteDetailMetrics($siteId, $load));
        set_site_transient($cacheKey, $details, $this->getDetailCacheTtl());

        return $details;
    }

    public function getSiteStorageAnalysisSite(int $siteId): array {
        $site = $siteId > 0 ? get_site($siteId) : null;
        $formattedSites = [];

        if (!$site instanceof \WP_Site) {
            return [];
        }

        $formattedSites = $this->formatSites([$site], true);

        if (empty($formattedSites[0]) || !is_array($formattedSites[0])) {
            return [];
        }

        return $formattedSites[0];
    }

    /**
     * Finds websites by domain, path, name or URL for the admin search field.
     *
     * Domain and path are searched in wp_blogs by SQL. Names and home URLs
     * come from the stored dashboard cache, so a search request never reads
     * options of hundreds of websites. Websites created after the last
     * dashboard metrics run are found by domain or path until the next run.
     */
    public function searchSites(string $searchTerm, int $limit = 20): array {
        $results = [];
        $foundSiteIds = [];
        $site = null;
        $siteId = 0;
        $row = [];
        $siteName = '';
        $siteUrl = '';
        $searchNeedle = trim(mb_strtolower($searchTerm));
        $cachedRows = [];

        if ($searchNeedle === '' || mb_strlen($searchNeedle) < 3) {
            return [];
        }

        $cache = $this->getStoredDashboardCache();
        foreach ((array)($cache['data']['site_overview'] ?? []) as $row) {
            if (is_array($row) && (int)($row['id'] ?? 0) > 0) {
                $cachedRows[(int)$row['id']] = $row;
            }
        }

        $sites = get_sites([
            'number' => $limit,
            'orderby' => 'domain',
            'order' => 'ASC',
            'search' => $searchNeedle,
            'search_columns' => ['domain', 'path'],
        ]);

        foreach ($sites as $site) {
            if (!$site instanceof \WP_Site) {
                continue;
            }

            $siteId = (int)$site->blog_id;
            $siteName = (string)($cachedRows[$siteId]['name'] ?? '');
            $siteUrl = (string)($cachedRows[$siteId]['url'] ?? '');
            $foundSiteIds[$siteId] = true;
            $results[] = [
                'id' => $siteId,
                'name' => $siteName !== '' ? $siteName : $this->getSiteName($site),
                'url' => $siteUrl !== '' ? $siteUrl : get_home_url($siteId, '/'),
            ];
        }

        foreach ($cachedRows as $siteId => $row) {
            if (count($results) >= $limit) {
                break;
            }

            if (isset($foundSiteIds[$siteId])) {
                continue;
            }

            $siteName = (string)($row['name'] ?? '');
            $siteUrl = (string)($row['url'] ?? '');

            if (mb_strpos(mb_strtolower($siteName . ' ' . $siteUrl), $searchNeedle) === false) {
                continue;
            }

            $results[] = [
                'id' => (int)$siteId,
                'name' => $siteName,
                'url' => $siteUrl,
            ];
        }

        return array_slice($results, 0, $limit);
    }

    protected function getSummary(array $networkStorageUsage = []): array {
        $totalUsers = function_exists('get_user_count') ? (int)get_user_count() : 0;
        $superAdmins = function_exists('get_super_admins') ? count((array)get_super_admins()) : 0;
        $networkActivePlugins = (array)get_site_option('active_sitewide_plugins', []);
        $allowedThemes = (array)get_site_option('allowedthemes', []);
        $totalThemes = count(wp_get_themes());
        $totalPlugins = 0;

        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        $totalPlugins = count(get_plugins());

        return [
            'total_sites' => $this->countSites(),
            'active_sites' => $this->countSites([
                'archived' => 0,
                'deleted' => 0,
                'spam' => 0,
            ]),
            'public_sites' => $this->countSites([
                'public' => 1,
            ]),
            'archived_sites' => $this->countSites([
                'archived' => 1,
            ]),
            'deleted_sites' => $this->countSites([
                'deleted' => 1,
            ]),
            'spam_sites' => $this->countSites([
                'spam' => 1,
            ]),
            'recent_sites_30' => $this->countRecentSites(30),
            'total_users' => $totalUsers,
            'super_admins' => $superAdmins,
            'total_plugins' => $totalPlugins,
            'network_active_plugins' => count($networkActivePlugins),
            'total_themes' => $totalThemes,
            'network_enabled_themes' => count($allowedThemes),
            'total_storage_used_bytes' => (int)($networkStorageUsage['total_used_bytes'] ?? 0),
            'total_storage_used_label' => (string)($networkStorageUsage['total_used_label'] ?? ''),
            'total_storage_max_bytes' => (int)($networkStorageUsage['total_max_bytes'] ?? 0),
            'total_storage_max_label' => (string)($networkStorageUsage['total_max_label'] ?? ''),
            'has_unlimited_storage_site' => !empty($networkStorageUsage['has_unlimited_site']),
        ];
    }

    protected function getStatusDistribution(): array {
        $siteBuckets = $this->getSiteBuckets();
        $totalSites = max(1, array_sum($siteBuckets));
        $rows = [
            [
                'label' => __('Active and public', 'rrze-multisite-manager'),
                'status_key' => 'active_public',
                'value' => (int)$siteBuckets['active_public'],
                'accent' => 'positive',
                'color' => $this->config->getSiteStatusColor('active_public'),
            ],
            [
                'label' => __('Active, excluded from search engines', 'rrze-multisite-manager'),
                'status_key' => 'active_private',
                'value' => (int)$siteBuckets['active_private'],
                'accent' => 'info',
                'color' => $this->config->getSiteStatusColor('active_private'),
            ],
            [
                'label' => __('Archived', 'rrze-multisite-manager'),
                'status_key' => 'archived',
                'value' => (int)$siteBuckets['archived'],
                'accent' => 'warning',
                'color' => $this->config->getSiteStatusColor('archived'),
            ],
            [
                'label' => __('Blocked', 'rrze-multisite-manager'),
                'status_key' => 'blocked',
                'value' => (int)$siteBuckets['spam'],
                'accent' => 'blocked',
                'color' => $this->config->getSiteStatusColor('blocked'),
            ],
            [
                'label' => __('Marked for deletion', 'rrze-multisite-manager'),
                'status_key' => 'deleted',
                'value' => (int)$siteBuckets['deleted'],
                'accent' => 'danger',
                'color' => $this->config->getSiteStatusColor('deleted'),
            ],
        ];
        $index = 0;

        foreach ($rows as $index => $row) {
            $rows[$index]['percent'] = (int)round((((int)$row['value']) / $totalSites) * 100);
        }

        return $rows;
    }

    protected function getOperationalStatusDistribution(): array {
        $statusBuckets = $this->getOperationalStatusBuckets();
        $totalSites = max(1, array_sum($statusBuckets));
        $rows = [
            [
                'label' => __('Not set / automatic', 'rrze-multisite-manager'),
                'status_key' => 'automatic',
                'value' => (int)$statusBuckets['automatic'],
                'accent' => 'theme-1',
                'color' => $this->config->getSiteStatusColor('automatic'),
            ],
            [
                'label' => __('Technically reachable', 'rrze-multisite-manager'),
                'status_key' => 'technically_reachable',
                'value' => (int)$statusBuckets['healthy'],
                'accent' => 'positive',
                'color' => $this->config->getSiteStatusColor('technically_reachable'),
            ],
            [
                'label' => __('Provisioning in progress', 'rrze-multisite-manager'),
                'status_key' => 'provisioning',
                'value' => (int)$statusBuckets['provisioning'],
                'accent' => 'info',
                'color' => $this->config->getSiteStatusColor('provisioning'),
            ],
            [
                'label' => __('DNS missing', 'rrze-multisite-manager'),
                'status_key' => 'dns_missing',
                'value' => (int)$statusBuckets['dns_missing'],
                'accent' => 'danger',
                'color' => $this->config->getSiteStatusColor('dns_missing'),
            ],
            [
                'label' => __('Technically unreachable', 'rrze-multisite-manager'),
                'status_key' => 'technically_unreachable',
                'value' => (int)$statusBuckets['unreachable'],
                'accent' => 'warning',
                'color' => $this->config->getSiteStatusColor('technically_unreachable'),
            ],
            [
                'label' => __('Out of service', 'rrze-multisite-manager'),
                'status_key' => 'out_of_service',
                'value' => (int)$statusBuckets['retired'],
                'accent' => 'neutral',
                'color' => $this->config->getSiteStatusColor('out_of_service'),
            ],
        ];
        $index = 0;

        foreach ($rows as $index => $row) {
            $rows[$index]['percent'] = (int)round((((int)$row['value']) / $totalSites) * 100);
        }

        return $rows;
    }

    protected function getRecentSites(): array {
        $sites = get_sites([
            'number' => $this->getSiteTableMaxRows(),
            'orderby' => 'registered',
            'order' => 'DESC',
        ]);

        return $this->formatSites($sites);
    }

    protected function getSitesByFlag(string $flag, int $value): array {
        $sites = get_sites([
            'number' => $this->getSiteTableMaxRows(),
            'orderby' => 'registered',
            'order' => 'DESC',
            $flag => $value,
        ]);

        return $this->formatSites($sites);
    }

    protected function filterFormattedSitesByFlag(array $sites, string $flagKey): array {
        $results = [];
        $site = [];

        foreach ($sites as $site) {
            if (!is_array($site)) {
                continue;
            }

            if (empty($site[$flagKey])) {
                continue;
            }

            $results[] = $site;
        }

        return array_slice($results, 0, $this->getSiteTableMaxRows());
    }

    protected function getRecentlyUpdatedSites(): array {
        $sites = $this->getSitesSortedByActivity('DESC');
        return array_slice($sites, 0, $this->getSiteTableMaxRows());
    }

    protected function getInactiveSites(): array {
        $sites = $this->getSitesSortedByActivity('ASC');
        return array_slice($sites, 0, $this->getSiteTableMaxRows());
    }
}
