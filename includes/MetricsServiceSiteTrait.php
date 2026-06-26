<?php

namespace RRZE\MultisiteManager;

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

    public function searchSites(string $searchTerm, int $limit = 20): array {
        $sites = [];
        $results = [];
        $siteMap = [];
        $site = null;
        $siteId = 0;
        $siteName = '';
        $siteUrl = '';
        $haystack = '';
        $searchNeedle = trim(mb_strtolower($searchTerm));
        $candidateSiteIds = [];
        $fallbackScanLimit = 500;

        if ($searchNeedle === '' || mb_strlen($searchNeedle) < 3) {
            return [];
        }

        $sites = get_sites([
            'number' => max($limit * 3, 20),
            'orderby' => 'domain',
            'order' => 'ASC',
            'search' => $searchNeedle,
            'search_columns' => ['domain', 'path'],
        ]);

        foreach ($sites as $site) {
            if (!$site instanceof \WP_Site) {
                continue;
            }

            $siteMap[(int)$site->blog_id] = $site;
        }

        if (count($siteMap) < $limit) {
            $candidateSiteIds = get_sites([
                'fields' => 'ids',
                'number' => $fallbackScanLimit,
                'orderby' => 'registered',
                'order' => 'DESC',
            ]);

            foreach ($candidateSiteIds as $siteId) {
                $siteId = (int)$siteId;

                if ($siteId <= 0 || isset($siteMap[$siteId])) {
                    continue;
                }

                $site = get_site($siteId);

                if ($site instanceof \WP_Site) {
                    $siteMap[$siteId] = $site;
                }
            }
        }

        foreach ($siteMap as $site) {
            $siteId = (int)$site->blog_id;
            $siteName = $this->getSiteName($site);
            $siteUrl = get_home_url($siteId, '/');
            $haystack = mb_strtolower($siteName . ' ' . $siteUrl . ' ' . $site->domain . $site->path);

            if (mb_strpos($haystack, $searchNeedle) === false) {
                continue;
            }

            $results[] = [
                'id' => $siteId,
                'name' => $siteName,
                'url' => $siteUrl,
            ];

            if (count($results) >= $limit) {
                break;
            }
        }

        return $results;
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
                'label' => __('Aktiv und öffentlich', 'rrze-multisite-manager'),
                'value' => (int)$siteBuckets['active_public'],
                'accent' => 'positive',
            ],
            [
                'label' => __('Aktiv, Suchmaschinen ausgeschlossen', 'rrze-multisite-manager'),
                'value' => (int)$siteBuckets['active_private'],
                'accent' => 'info',
            ],
            [
                'label' => __('Archiviert', 'rrze-multisite-manager'),
                'value' => (int)$siteBuckets['archived'],
                'accent' => 'warning',
            ],
            [
                'label' => __('Gesperrt', 'rrze-multisite-manager'),
                'value' => (int)$siteBuckets['spam'],
                'accent' => 'blocked',
            ],
            [
                'label' => __('Zum Löschen markiert', 'rrze-multisite-manager'),
                'value' => (int)$siteBuckets['deleted'],
                'accent' => 'danger',
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
                'label' => __('Nicht gesetzt / automatisch', 'rrze-multisite-manager'),
                'value' => (int)$statusBuckets['automatic'],
                'accent' => 'theme-1',
            ],
            [
                'label' => __('Technisch erreichbar', 'rrze-multisite-manager'),
                'value' => (int)$statusBuckets['healthy'],
                'accent' => 'positive',
            ],
            [
                'label' => __('Einrichtung läuft', 'rrze-multisite-manager'),
                'value' => (int)$statusBuckets['provisioning'],
                'accent' => 'info',
            ],
            [
                'label' => __('DNS fehlt', 'rrze-multisite-manager'),
                'value' => (int)$statusBuckets['dns_missing'],
                'accent' => 'danger',
            ],
            [
                'label' => __('Technisch nicht erreichbar', 'rrze-multisite-manager'),
                'value' => (int)$statusBuckets['unreachable'],
                'accent' => 'warning',
            ],
            [
                'label' => __('Außer Betrieb', 'rrze-multisite-manager'),
                'value' => (int)$statusBuckets['retired'],
                'accent' => 'neutral',
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

    protected function getSiteOverview(): array {
        $sites = get_sites([
            'number' => 0,
            'orderby' => 'registered',
            'order' => 'DESC',
        ]);

        return $this->formatSites($sites, true);
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
