<?php

namespace RRZE\MultisiteManager;

defined('ABSPATH') || exit;

trait MetricsServiceSiteTrait {
    public function getSiteDetails(int $siteId): array {
        $site = $siteId > 0 ? get_site($siteId) : null;
        $formattedSites = [];
        $details = [];

        if (!$site instanceof \WP_Site) {
            return [];
        }

        $formattedSites = $this->formatSites([$site], true);

        if (empty($formattedSites[0]) || !is_array($formattedSites[0])) {
            return [];
        }

        $details = $formattedSites[0];
        $details['status_user'] = $this->getStatusUserData((int)($details['status_user_id'] ?? 0));
        $details = array_merge($details, $this->getSiteDetailMetrics($siteId));

        return $details;
    }

    public function searchSites(string $searchTerm, int $limit = 20): array {
        $sites = [];
        $results = [];
        $site = null;
        $siteId = 0;
        $siteName = '';
        $siteUrl = '';
        $haystack = '';
        $searchNeedle = trim(mb_strtolower($searchTerm));

        if ($searchNeedle === '' || mb_strlen($searchNeedle) < 2) {
            return [];
        }

        $sites = get_sites([
            'number' => 0,
            'orderby' => 'domain',
            'order' => 'ASC',
        ]);

        foreach ($sites as $site) {
            if (!$site instanceof \WP_Site) {
                continue;
            }

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

    protected function getSummary(): array {
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
        ];
    }

    protected function getStatusDistribution(): array {
        $siteBuckets = $this->getSiteBuckets();
        $totalSites = max(1, array_sum($siteBuckets));
        $rows = [
            [
                'label' => __('Aktiv', 'rrze-multisite-manager'),
                'value' => (int)$siteBuckets['active'],
                'accent' => 'positive',
            ],
            [
                'label' => __('Archiviert', 'rrze-multisite-manager'),
                'value' => (int)$siteBuckets['archived'],
                'accent' => 'warning',
            ],
            [
                'label' => __('Gesperrt', 'rrze-multisite-manager'),
                'value' => (int)$siteBuckets['spam'],
                'accent' => 'neutral',
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
