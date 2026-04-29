<?php

namespace RRZE\MultisiteManager;

defined('ABSPATH') || exit;

class MetricsService {
    protected const CACHE_KEY = 'rrze_multisite_manager_dashboard_metrics_v7_';
    protected const SITE_TABLE_MAX_ROWS = 100;
    protected ?Settings $settings;
    protected Config $config;

    public function __construct(?Settings $settings = null, ?Config $config = null) {
        $this->settings = $settings;
        $this->config = $config ?? new Config();
    }

    public function getDashboardData(): array {
        $cached = get_site_transient($this->getCacheKey());
        $siteOverview = [];

        if (is_array($cached) && $this->isCompleteDashboardData($cached)) {
            return $cached;
        }

        $siteOverview = $this->getSiteOverview();

        $data = [
            'summary' => $this->getSummary(),
            'site_table_default_limit' => $this->getActivitySiteLimit(),
            'status_distribution' => $this->getStatusDistribution(),
            'network_storage_usage' => $this->getNetworkStorageUsage(),
            'recent_sites' => $this->getRecentSites(),
            'site_overview' => $siteOverview,
            'archived_sites' => $this->filterFormattedSitesByFlag($siteOverview, 'is_archived'),
            'blocked_sites' => $this->filterFormattedSitesByFlag($siteOverview, 'is_spam'),
            'deleted_sites' => $this->filterFormattedSitesByFlag($siteOverview, 'is_deleted'),
            'themes' => $this->getThemes(),
            'theme_usage' => $this->getThemeUsage(),
            'editor_usage' => $this->getEditorUsage(),
            'plugin_usage' => $this->getPluginUsage(),
            'inactive_themes' => $this->getInactiveThemes(),
            'recently_updated_sites' => $this->getRecentlyUpdatedSites(),
            'inactive_sites' => $this->getInactiveSites(),
        ];

        set_site_transient($this->getCacheKey(), $data, $this->config->getMetricsCacheTtl());

        return $data;
    }

    public function clearCache(): void {
        delete_site_transient($this->getCacheKey());
        delete_site_transient('rrze_multisite_manager_dashboard_metrics_v1_' . (string)get_current_network_id());
        delete_site_transient('rrze_multisite_manager_dashboard_metrics_v2_' . (string)get_current_network_id());
        delete_site_transient('rrze_multisite_manager_dashboard_metrics_v3_' . (string)get_current_network_id());
        delete_site_transient('rrze_multisite_manager_dashboard_metrics_v4_' . (string)get_current_network_id());
        delete_site_transient('rrze_multisite_manager_dashboard_metrics_v5_' . (string)get_current_network_id());
        delete_site_transient('rrze_multisite_manager_dashboard_metrics_v6_' . (string)get_current_network_id());
    }

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

    public function searchPlugins(string $searchTerm, int $limit = 20): array {
        $availablePlugins = [];
        $results = [];
        $pluginFile = '';
        $pluginData = [];
        $searchNeedle = trim(mb_strtolower($searchTerm));
        $haystack = '';

        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        if ($searchNeedle === '' || mb_strlen($searchNeedle) < 2) {
            return [];
        }

        $availablePlugins = get_plugins();

        foreach ($availablePlugins as $pluginFile => $pluginData) {
            $haystack = mb_strtolower(
                (string)($pluginData['Name'] ?? '') . ' ' .
                (string)($pluginData['Description'] ?? '') . ' ' .
                $pluginFile
            );

            if (mb_strpos($haystack, $searchNeedle) === false) {
                continue;
            }

            $results[] = [
                'id' => $pluginFile,
                'name' => (string)($pluginData['Name'] ?? $pluginFile),
                'version' => (string)($pluginData['Version'] ?? ''),
                'file' => $pluginFile,
            ];

            if (count($results) >= $limit) {
                break;
            }
        }

        return $results;
    }

    public function searchThemes(string $searchTerm, int $limit = 20): array {
        $themes = wp_get_themes();
        $results = [];
        $stylesheet = '';
        $theme = null;
        $searchNeedle = trim(mb_strtolower($searchTerm));
        $haystack = '';

        if ($searchNeedle === '' || mb_strlen($searchNeedle) < 2) {
            return [];
        }

        foreach ($themes as $stylesheet => $theme) {
            if (!$theme instanceof \WP_Theme) {
                continue;
            }

            $haystack = mb_strtolower(
                (string)$theme->get('Name') . ' ' .
                (string)$theme->get('Description') . ' ' .
                $stylesheet
            );

            if (mb_strpos($haystack, $searchNeedle) === false) {
                continue;
            }

            $results[] = [
                'id' => $stylesheet,
                'name' => (string)$theme->get('Name'),
                'version' => (string)$theme->get('Version'),
                'stylesheet' => $stylesheet,
            ];

            if (count($results) >= $limit) {
                break;
            }
        }

        return $results;
    }

    public function getPluginDetails(string $pluginFile): array {
        $availablePlugins = [];
        $pluginData = [];
        $dashboardData = [];
        $pluginUsage = [];
        $pluginUsageItem = [];
        $updateItem = null;
        $analysis = [];
        $status = [];
        $supplementary = [];
        $installTimestamp = 0;
        $modifiedTimestamp = 0;

        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        $availablePlugins = get_plugins();

        if (!isset($availablePlugins[$pluginFile]) || !is_array($availablePlugins[$pluginFile])) {
            return [];
        }

        $pluginData = $availablePlugins[$pluginFile];
        $dashboardData = $this->getDashboardData();
        $pluginUsage = is_array($dashboardData['plugin_usage']['plugins'] ?? null) ? $dashboardData['plugin_usage']['plugins'] : [];
        $pluginUsageItem = $this->findPluginUsageItem($pluginUsage, $pluginFile);
        $updateItem = $this->getPluginUpdateItem($pluginFile);
        $analysis = $this->analyzePluginCode($pluginFile);
        $supplementary = $this->getPluginSupplementaryData($pluginFile);
        $installTimestamp = $this->getPluginInstallTimestamp($pluginFile);
        $modifiedTimestamp = $this->getPluginModifiedTimestamp($pluginFile);
        $status = $this->getPluginStatus($pluginUsageItem);

        if (empty($pluginUsageItem)) {
            $pluginUsageItem = [
                'file' => $pluginFile,
                'site_count' => 0,
                'active_sites' => [],
                'name' => (string)($pluginData['Name'] ?? $pluginFile),
                'version' => (string)($pluginData['Version'] ?? 'n/a'),
                'description' => wp_strip_all_tags((string)($pluginData['Description'] ?? '')),
                'author' => $this->getPluginAuthorLabel($pluginData),
                'author_url' => $this->getPluginAuthorUrl($pluginData),
                'network_active' => false,
                'settings_url' => $this->getPluginSettingsUrl($pluginFile, $pluginData),
                'details_url' => $this->getPluginDetailsUrl($pluginData),
                'deactivate_url' => '',
                'delete_url' => $this->getNetworkPluginDeleteUrl($pluginFile),
                'plugin_uri' => $this->getPluginDetailsUrl($pluginData),
                'text_domain' => !empty($pluginData['TextDomain']) && is_string($pluginData['TextDomain']) ? (string)$pluginData['TextDomain'] : '',
                'requires_php' => !empty($pluginData['RequiresPHP']) && is_string($pluginData['RequiresPHP']) ? (string)$pluginData['RequiresPHP'] : '',
                'requires_wp' => !empty($pluginData['RequiresWP']) && is_string($pluginData['RequiresWP']) ? (string)$pluginData['RequiresWP'] : '',
                'update_available' => $updateItem !== null,
                'update_version' => $updateItem !== null ? (string)($updateItem->new_version ?? '') : '',
                'update_details_url' => $this->getPluginUpdateDetailsUrl($pluginData, $updateItem),
                'update_url' => $updateItem !== null ? $this->getNetworkPluginUpdateUrl($pluginFile) : '',
            ];
            $status = $this->getPluginStatus($pluginUsageItem);
        }

        if ((string)($pluginUsageItem['author'] ?? '') === '' && !empty($supplementary['author']['name'])) {
            $pluginUsageItem['author'] = (string)$supplementary['author']['name'];
        }

        if ((string)($pluginUsageItem['author_url'] ?? '') === '' && !empty($supplementary['author']['url'])) {
            $pluginUsageItem['author_url'] = (string)$supplementary['author']['url'];
        }

        if ((string)($pluginUsageItem['description'] ?? '') === '' && !empty($supplementary['description'])) {
            $pluginUsageItem['description'] = (string)$supplementary['description'];
        }

        return array_merge(
            $pluginUsageItem,
            [
                'status' => $status,
                'author_email' => (string)($supplementary['author']['email'] ?? ''),
                'compatibility' => (array)($supplementary['compatibility'] ?? []),
                'supports' => (array)($supplementary['supports'] ?? []),
                'license' => (array)($supplementary['license'] ?? []),
                'tags' => (array)($supplementary['tags'] ?? []),
                'repository' => (array)($supplementary['repository'] ?? []),
                'extended_description' => (string)($supplementary['description'] ?? ''),
                'metadata_sources' => (array)($supplementary['sources'] ?? []),
                'readme_markdown' => (string)($supplementary['readme_markdown'] ?? ''),
                'shortcodes' => $analysis['shortcodes'],
                'blocks' => $analysis['blocks'],
                'block_patterns' => $analysis['block_patterns'],
                'provided_hooks' => $analysis['provided_hooks'],
                'installation_date_label' => $installTimestamp > 0 ? $this->formatTimestamp($installTimestamp) : __('Nicht verfügbar.', 'rrze-multisite-manager'),
                'last_release_date_label' => $modifiedTimestamp > 0 ? $this->formatTimestamp($modifiedTimestamp) : __('Nicht verfügbar.', 'rrze-multisite-manager'),
                'main_file_path' => $this->getPluginAbsolutePath($pluginFile),
            ]
        );
    }

    public function getThemeDetails(string $stylesheet): array {
        $themes = $this->getThemes();
        $themeItem = [];
        $supplementary = [];
        $analysis = [];
        $installTimestamp = 0;
        $modifiedTimestamp = 0;

        foreach ($themes as $themeItem) {
            if ((string)($themeItem['stylesheet'] ?? '') === $stylesheet) {
                break;
            }
        }

        if (empty($themeItem) || (string)($themeItem['stylesheet'] ?? '') !== $stylesheet) {
            return [];
        }

        $supplementary = $this->getThemeSupplementaryData($stylesheet);
        $analysis = $this->analyzeThemeCode($stylesheet);
        $installTimestamp = $this->getThemeInstallTimestamp($stylesheet);
        $modifiedTimestamp = $this->getThemeModifiedTimestamp($stylesheet);

        if ((string)($themeItem['author'] ?? '') === '' && !empty($supplementary['author']['name'])) {
            $themeItem['author'] = (string)$supplementary['author']['name'];
        }

        if ((string)($themeItem['author_url'] ?? '') === '' && !empty($supplementary['author']['url'])) {
            $themeItem['author_url'] = (string)$supplementary['author']['url'];
        }

        if ((string)($themeItem['description'] ?? '') === '' && !empty($supplementary['description'])) {
            $themeItem['description'] = (string)$supplementary['description'];
        }

        return array_merge(
            $themeItem,
            [
                'author_email' => (string)($supplementary['author']['email'] ?? ''),
                'compatibility' => (array)($supplementary['compatibility'] ?? []),
                'supports' => (array)($supplementary['supports'] ?? []),
                'license' => (array)($supplementary['license'] ?? []),
                'repository' => (array)($supplementary['repository'] ?? []),
                'metadata_sources' => (array)($supplementary['sources'] ?? []),
                'readme_markdown' => (string)($supplementary['readme_markdown'] ?? ''),
                'shortcodes' => (array)($analysis['shortcodes'] ?? []),
                'blocks' => (array)($analysis['blocks'] ?? []),
                'block_patterns' => (array)($analysis['block_patterns'] ?? []),
                'provided_hooks' => (array)($analysis['provided_hooks'] ?? []),
                'installation_date_label' => $installTimestamp > 0 ? $this->formatTimestamp($installTimestamp) : __('Nicht verfügbar.', 'rrze-multisite-manager'),
                'last_release_date_label' => $modifiedTimestamp > 0 ? $this->formatTimestamp($modifiedTimestamp) : __('Nicht verfügbar.', 'rrze-multisite-manager'),
                'main_file_path' => $this->getThemeMainFilePath($stylesheet),
            ]
        );
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

    protected function getMonthlyGrowth(): array {
        global $wpdb;

        $months = [];
        $results = [];
        $i = 0;
        $monthKey = '';
        $queryDate = null;
        $rows = [];
        $row = null;

        for ($i = 5; $i >= 0; $i--) {
            $queryDate = new \DateTimeImmutable('first day of this month');
            $queryDate = $queryDate->modify('-' . $i . ' months');
            $monthKey = $queryDate->format('Y-m');
            $months[$monthKey] = [
                'label' => $queryDate->format('M Y'),
                'value' => 0,
            ];
        }

        $sql = $wpdb->prepare(
            "SELECT DATE_FORMAT(registered, '%%Y-%%m') AS month_key, COUNT(blog_id) AS total
            FROM {$wpdb->blogs}
            WHERE site_id = %d
            AND registered >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
            GROUP BY month_key
            ORDER BY month_key ASC",
            get_current_network_id()
        );

        $rows = $wpdb->get_results($sql);

        foreach ($rows as $row) {
            if (!isset($months[$row->month_key])) {
                continue;
            }

            $months[$row->month_key]['value'] = (int)$row->total;
        }

        foreach ($months as $monthKey => $monthData) {
            $results[] = [
                'label' => $monthData['label'],
                'value' => (int)$monthData['value'],
            ];
        }

        return $results;
    }

    protected function getPluginUsage(): array {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        $availablePlugins = get_plugins();
        $networkActivePlugins = get_site_option('active_sitewide_plugins', []);
        $siteIds = get_sites([
            'fields' => 'ids',
            'number' => 0,
        ]);
        $pluginStats = [];
        $siteId = 0;
        $activePlugins = [];
        $pluginFile = '';
        $totalSites = count($siteIds);
        $sitePluginFiles = [];
        $pluginData = [];
        $pluginUpdates = get_site_transient('update_plugins');
        $updateItem = null;

        foreach ($availablePlugins as $pluginFile => $pluginData) {
            $updateItem = is_object($pluginUpdates) && !empty($pluginUpdates->response[$pluginFile]) && is_object($pluginUpdates->response[$pluginFile])
                ? $pluginUpdates->response[$pluginFile]
                : null;
            $pluginStats[$pluginFile] = [
                'file' => $pluginFile,
                'site_count' => 0,
                'active_sites' => [],
                'name' => (string)($pluginData['Name'] ?? $pluginFile),
                'version' => (string)($pluginData['Version'] ?? 'n/a'),
                'description' => wp_strip_all_tags((string)($pluginData['Description'] ?? '')),
                'author' => $this->getPluginAuthorLabel($pluginData),
                'author_url' => $this->getPluginAuthorUrl($pluginData),
                'network_active' => isset($networkActivePlugins[$pluginFile]),
                'settings_url' => $this->getPluginSettingsUrl($pluginFile, $pluginData),
                'details_url' => $this->getPluginDetailsUrl($pluginData),
                'deactivate_url' => isset($networkActivePlugins[$pluginFile]) ? $this->getNetworkPluginDeactivateUrl($pluginFile) : '',
                'delete_url' => $this->getNetworkPluginDeleteUrl($pluginFile),
                'plugin_uri' => $this->getPluginDetailsUrl($pluginData),
                'text_domain' => !empty($pluginData['TextDomain']) && is_string($pluginData['TextDomain']) ? (string)$pluginData['TextDomain'] : '',
                'requires_php' => !empty($pluginData['RequiresPHP']) && is_string($pluginData['RequiresPHP']) ? (string)$pluginData['RequiresPHP'] : '',
                'requires_wp' => !empty($pluginData['RequiresWP']) && is_string($pluginData['RequiresWP']) ? (string)$pluginData['RequiresWP'] : '',
                'update_available' => $updateItem !== null,
                'update_version' => $updateItem !== null ? (string)($updateItem->new_version ?? '') : '',
                'update_details_url' => $this->getPluginUpdateDetailsUrl($pluginData, $updateItem),
                'update_url' => $updateItem !== null ? $this->getNetworkPluginUpdateUrl($pluginFile) : '',
            ];
        }

        foreach ($siteIds as $siteId) {
            $activePlugins = get_blog_option((int)$siteId, 'active_plugins', []);

            if (!is_array($activePlugins)) {
                $activePlugins = [];
            }

            $sitePluginFiles = array_unique(
                array_merge(
                    array_keys($networkActivePlugins),
                    array_values(array_filter($activePlugins, 'is_string'))
                )
            );

            foreach ($sitePluginFiles as $pluginFile) {
                if (!isset($pluginStats[$pluginFile])) {
                    $pluginStats[$pluginFile] = [
                        'file' => $pluginFile,
                        'site_count' => 0,
                        'active_sites' => [],
                        'name' => $pluginFile,
                        'version' => 'n/a',
                        'description' => '',
                        'author' => '',
                        'author_url' => '',
                        'network_active' => isset($networkActivePlugins[$pluginFile]),
                        'settings_url' => '',
                        'details_url' => '',
                        'deactivate_url' => isset($networkActivePlugins[$pluginFile]) ? $this->getNetworkPluginDeactivateUrl($pluginFile) : '',
                        'delete_url' => $this->getNetworkPluginDeleteUrl($pluginFile),
                        'plugin_uri' => '',
                        'text_domain' => '',
                        'requires_php' => '',
                        'requires_wp' => '',
                        'update_available' => false,
                        'update_version' => '',
                        'update_details_url' => '',
                        'update_url' => '',
                    ];
                }

                $pluginStats[$pluginFile]['site_count']++;
                $pluginStats[$pluginFile]['active_sites'][] = [
                    'id' => (int)$siteId,
                    'name' => $this->getSiteNameById((int)$siteId),
                    'url' => get_home_url((int)$siteId, '/'),
                ];
            }
        }

        foreach ($pluginStats as $pluginFile => $pluginData) {
            $pluginStats[$pluginFile]['active_sites'] = $this->sortPluginActiveSites((array)($pluginData['active_sites'] ?? []));
        }

        uasort($pluginStats, [self::class, 'comparePluginUsage']);

        return [
            'summary' => [
                'available_plugins' => count($availablePlugins),
                'network_active_plugins' => count($networkActivePlugins),
                'locally_used_plugins' => $this->countLocallyUsedPlugins($pluginStats),
                'total_sites' => $totalSites,
            ],
            'plugins' => array_values($pluginStats),
            'distribution' => $this->buildPluginUsageDistribution($pluginStats),
            'inactive_plugins' => array_values(
                array_filter(
                    $pluginStats,
                    [self::class, 'isUnusedPlugin']
                )
            ),
        ];
    }

    protected function findPluginUsageItem(array $plugins, string $pluginFile): array {
        $plugin = [];

        foreach ($plugins as $plugin) {
            if ((string)($plugin['file'] ?? '') === $pluginFile) {
                return $plugin;
            }
        }

        return [];
    }

    protected function getPluginUpdateItem(string $pluginFile): ?object {
        $pluginUpdates = get_site_transient('update_plugins');

        if (!is_object($pluginUpdates) || empty($pluginUpdates->response[$pluginFile]) || !is_object($pluginUpdates->response[$pluginFile])) {
            return null;
        }

        return $pluginUpdates->response[$pluginFile];
    }

    protected function getPluginStatus(array $plugin): array {
        $items = [];

        if (!empty($plugin['network_active'])) {
            $items[] = [
                'label' => __('Netzwerkweit aktiv', 'rrze-multisite-manager'),
                'accent' => 'info',
            ];
        } elseif ((int)($plugin['site_count'] ?? 0) > 0) {
            $items[] = [
                'label' => __('Auf Websites aktiv', 'rrze-multisite-manager'),
                'accent' => 'active',
            ];
        } else {
            $items[] = [
                'label' => __('Nicht aktiviert', 'rrze-multisite-manager'),
                'accent' => 'archive',
            ];
        }

        if (!empty($plugin['update_available']) && !empty($plugin['update_version'])) {
            $items[] = [
                'label' => sprintf(__('Update %s verfügbar', 'rrze-multisite-manager'), (string)$plugin['update_version']),
                'accent' => 'info',
            ];
        }

        return $items;
    }

    protected function analyzePluginCode(string $pluginFile): array {
        $files = $this->getPluginAnalysisFiles($pluginFile);
        $shortcodes = [];
        $blocks = [];
        $patterns = [];
        $hooks = [];
        $filePath = '';
        $source = '';
        $extension = '';

        foreach ($files as $filePath) {
            if (!is_readable($filePath)) {
                continue;
            }

            $source = (string)file_get_contents($filePath);

            if ($source === '') {
                continue;
            }

            $extension = strtolower((string)pathinfo($filePath, PATHINFO_EXTENSION));

            if (in_array($extension, ['php', 'js'], true)) {
                $shortcodes = $this->mergeStringList($shortcodes, $this->extractShortcodesFromSource($source));
                $blocks = $this->mergeKeyedRows($blocks, $this->extractBlocksFromSource($source));
                $patterns = $this->mergeStringList($patterns, $this->extractBlockPatternsFromSource($source));
                $hooks = $this->mergeKeyedRows($hooks, $this->extractProvidedHooksFromSource($source));
            }

            if (strtolower((string)basename($filePath)) === 'block.json') {
                $blocks = $this->mergeKeyedRows($blocks, $this->extractBlocksFromMetadataFile($filePath));
            }
        }

        sort($shortcodes, SORT_NATURAL | SORT_FLAG_CASE);
        usort($blocks, [self::class, 'comparePluginNamedRows']);
        sort($patterns, SORT_NATURAL | SORT_FLAG_CASE);
        usort($hooks, [self::class, 'comparePluginNamedRows']);

        return [
            'shortcodes' => $shortcodes,
            'blocks' => $blocks,
            'block_patterns' => $patterns,
            'provided_hooks' => $hooks,
        ];
    }

    protected function analyzeThemeCode(string $stylesheet): array {
        $files = $this->getThemeAnalysisFiles($stylesheet);
        $shortcodes = [];
        $blocks = [];
        $patterns = [];
        $hooks = [];
        $filePath = '';
        $source = '';
        $extension = '';

        foreach ($files as $filePath) {
            if (!is_readable($filePath)) {
                continue;
            }

            $source = (string)file_get_contents($filePath);

            if ($source === '') {
                continue;
            }

            $extension = strtolower((string)pathinfo($filePath, PATHINFO_EXTENSION));

            if (in_array($extension, ['php', 'js'], true)) {
                $shortcodes = $this->mergeStringList($shortcodes, $this->extractShortcodesFromSource($source));
                $blocks = $this->mergeKeyedRows($blocks, $this->extractBlocksFromSource($source));
                $patterns = $this->mergeStringList($patterns, $this->extractBlockPatternsFromSource($source));
                $hooks = $this->mergeKeyedRows($hooks, $this->extractProvidedHooksFromSource($source));
            }

            if (strtolower((string)basename($filePath)) === 'block.json') {
                $blocks = $this->mergeKeyedRows($blocks, $this->extractBlocksFromMetadataFile($filePath));
            }
        }

        sort($shortcodes, SORT_NATURAL | SORT_FLAG_CASE);
        usort($blocks, [self::class, 'comparePluginNamedRows']);
        sort($patterns, SORT_NATURAL | SORT_FLAG_CASE);
        usort($hooks, [self::class, 'comparePluginNamedRows']);

        return [
            'shortcodes' => $shortcodes,
            'blocks' => $blocks,
            'block_patterns' => $patterns,
            'provided_hooks' => $hooks,
        ];
    }

    protected function getPluginAnalysisFiles(string $pluginFile): array {
        $mainFilePath = $this->getPluginAbsolutePath($pluginFile);
        $baseDir = is_file($mainFilePath) ? dirname($mainFilePath) : '';
        $results = [];
        $iterator = null;
        $current = null;
        $pathname = '';

        if ($mainFilePath === '' || !file_exists($mainFilePath)) {
            return [];
        }

        $results[] = $mainFilePath;

        if ($baseDir === '' || !is_dir($baseDir)) {
            return $results;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $baseDir,
                \FilesystemIterator::SKIP_DOTS
            )
        );

        foreach ($iterator as $current) {
            if (!$current instanceof \SplFileInfo || !$current->isFile()) {
                continue;
            }

            $pathname = (string)$current->getPathname();

            if ($pathname === $mainFilePath || !$this->isPluginAnalysisFile($pathname)) {
                continue;
            }

            $results[] = $pathname;
        }

        sort($results, SORT_NATURAL | SORT_FLAG_CASE);

        return array_values(array_unique($results));
    }

    protected function isPluginAnalysisFile(string $filePath): bool {
        $extension = strtolower((string)pathinfo($filePath, PATHINFO_EXTENSION));

        return in_array($extension, ['php', 'js', 'json'], true);
    }

    protected function getPluginAbsolutePath(string $pluginFile): string {
        $pluginFile = ltrim($pluginFile, '/');

        if ($pluginFile === '') {
            return '';
        }

        return trailingslashit(WP_PLUGIN_DIR) . $pluginFile;
    }

    protected function extractOptionsFromSource(string $source): array {
        $results = [];
        $patterns = [
            'site' => '/\b(update_option|add_option|delete_option)\s*\(\s*[\'"]([^\'"]+)[\'"]/m',
            'network' => '/\b(update_site_option|add_site_option|delete_site_option)\s*\(\s*[\'"]([^\'"]+)[\'"]/m',
        ];
        $scope = '';
        $matches = [];
        $index = 0;
        $name = '';
        $functionName = '';
        $key = '';

        foreach ($patterns as $scope => $pattern) {
            $matches = [];

            if (!preg_match_all($pattern, $source, $matches, PREG_SET_ORDER)) {
                continue;
            }

            for ($index = 0; $index < count($matches); $index++) {
                $functionName = (string)($matches[$index][1] ?? '');
                $name = (string)($matches[$index][2] ?? '');

                if ($name === '') {
                    continue;
                }

                $key = $scope . ':' . $name;

                if (!isset($results[$key])) {
                    $results[$key] = [
                        'name' => $name,
                        'scope' => $scope,
                        'functions' => [],
                    ];
                }

                if (!in_array($functionName, $results[$key]['functions'], true)) {
                    $results[$key]['functions'][] = $functionName;
                }
            }
        }

        return array_values($results);
    }

    protected function extractShortcodesFromSource(string $source): array {
        $matches = [];
        $results = [];
        $index = 0;
        $name = '';

        if (!preg_match_all('/\badd_shortcode\s*\(\s*[\'"]([^\'"]+)[\'"]/m', $source, $matches, PREG_SET_ORDER)) {
            return [];
        }

        for ($index = 0; $index < count($matches); $index++) {
            $name = (string)($matches[$index][1] ?? '');

            if ($name !== '') {
                $results[] = $name;
            }
        }

        return $results;
    }

    protected function getThemeAnalysisFiles(string $stylesheet): array {
        $baseDir = $this->getThemeAbsolutePath($stylesheet);
        $results = [];
        $iterator = null;
        $current = null;
        $pathname = '';

        if ($baseDir === '' || !is_dir($baseDir)) {
            return [];
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $baseDir,
                \FilesystemIterator::SKIP_DOTS
            )
        );

        foreach ($iterator as $current) {
            if (!$current instanceof \SplFileInfo || !$current->isFile()) {
                continue;
            }

            $pathname = (string)$current->getPathname();

            if (!$this->isPluginAnalysisFile($pathname)) {
                continue;
            }

            $results[] = $pathname;
        }

        return $results;
    }

    protected function getThemeAbsolutePath(string $stylesheet): string {
        $theme = wp_get_theme($stylesheet);

        if (!$theme instanceof \WP_Theme || !$theme->exists()) {
            return '';
        }

        return (string)$theme->get_stylesheet_directory();
    }

    protected function getThemeMainFilePath(string $stylesheet): string {
        $themePath = $this->getThemeAbsolutePath($stylesheet);
        $stylePath = $themePath !== '' ? trailingslashit($themePath) . 'style.css' : '';

        if ($stylePath !== '' && file_exists($stylePath)) {
            return $stylePath;
        }

        return $themePath;
    }

    protected function getThemeInstallTimestamp(string $stylesheet): int {
        $mainPath = $this->getThemeMainFilePath($stylesheet);
        $themePath = $this->getThemeAbsolutePath($stylesheet);
        $targetPath = $themePath !== '' && file_exists($themePath) ? $themePath : $mainPath;

        if ($targetPath === '' || !file_exists($targetPath)) {
            return 0;
        }

        return (int)filectime($targetPath);
    }

    protected function getThemeModifiedTimestamp(string $stylesheet): int {
        $mainPath = $this->getThemeMainFilePath($stylesheet);

        if ($mainPath === '' || !file_exists($mainPath)) {
            return 0;
        }

        return (int)filemtime($mainPath);
    }

    protected function getThemeSupplementaryData(string $stylesheet): array {
        $themePath = $this->getThemeAbsolutePath($stylesheet);
        $packagePath = $themePath !== '' ? $this->getFirstExistingPath([trailingslashit($themePath) . 'package.json']) : '';
        $readmeTxtPath = $themePath !== '' ? $this->getFirstExistingPath([trailingslashit($themePath) . 'readme.txt']) : '';
        $readmeMarkdownPath = $themePath !== '' ? $this->getFirstExistingPath([
            trailingslashit($themePath) . 'README.md',
            trailingslashit($themePath) . 'readme.md',
            trailingslashit($themePath) . 'README.pm',
            trailingslashit($themePath) . 'readme.pm',
        ]) : '';
        $packageData = $packagePath !== '' ? $this->parsePluginPackageJson($packagePath) : [];
        $readmeData = $readmeTxtPath !== '' ? $this->parsePluginReadmeTxt($readmeTxtPath) : [];
        $readmeMarkdown = '';
        $sources = [];

        if ($packagePath !== '') {
            $sources[] = 'package.json';
        }

        if ($readmeTxtPath !== '') {
            $sources[] = 'readme.txt';
        }

        if ($readmeMarkdownPath !== '' && is_readable($readmeMarkdownPath)) {
            $readmeMarkdown = (string)file_get_contents($readmeMarkdownPath);
            $sources[] = basename($readmeMarkdownPath);
        }

        return [
            'author' => $this->mergePluginAuthorData(
                (array)($packageData['author'] ?? []),
                (array)($readmeData['author'] ?? [])
            ),
            'compatibility' => $this->mergePluginCompatibility(
                (array)($packageData['compatibility'] ?? []),
                (array)($readmeData['compatibility'] ?? [])
            ),
            'supports' => $this->mergeStringList(
                (array)($packageData['supports'] ?? []),
                (array)($readmeData['supports'] ?? [])
            ),
            'license' => $this->mergePluginLicenseData(
                (array)($packageData['license'] ?? []),
                (array)($readmeData['license'] ?? [])
            ),
            'repository' => $this->mergePluginRepositoryData(
                (array)($packageData['repository'] ?? []),
                (array)($readmeData['repository'] ?? [])
            ),
            'description' => $this->pickFirstNonEmptyString([
                (string)($packageData['description'] ?? ''),
                (string)($readmeData['description'] ?? ''),
            ]),
            'sources' => $sources,
            'readme_markdown' => $readmeMarkdown,
        ];
    }

    protected function extractBlocksFromSource(string $source): array {
        $matches = [];
        $results = [];
        $index = 0;
        $name = '';

        if (preg_match_all('/\bregister_block_type(?:_from_metadata)?\s*\(\s*[\'"]([^\'"]+)[\'"]/m', $source, $matches, PREG_SET_ORDER)) {
            for ($index = 0; $index < count($matches); $index++) {
                $name = (string)($matches[$index][1] ?? '');

                if ($name !== '' && str_contains($name, '/')) {
                    $results[$name] = [
                        'name' => $name,
                        'source' => 'php',
                    ];
                }
            }
        }

        if (preg_match_all('/\bregisterBlockType\s*\(\s*[\'"]([^\'"]+)[\'"]/mi', $source, $matches, PREG_SET_ORDER)) {
            for ($index = 0; $index < count($matches); $index++) {
                $name = (string)($matches[$index][1] ?? '');

                if ($name !== '') {
                    $results[$name] = [
                        'name' => $name,
                        'source' => 'js',
                    ];
                }
            }
        }

        return array_values($results);
    }

    protected function extractBlocksFromMetadataFile(string $filePath): array {
        $json = (string)file_get_contents($filePath);
        $data = [];
        $name = '';
        $title = '';
        $description = '';
        $category = '';
        $icon = '';
        $keywords = [];

        if ($json === '') {
            return [];
        }

        $data = json_decode($json, true);

        if (!is_array($data) || empty($data['name']) || !is_string($data['name'])) {
            return [];
        }

        $name = (string)$data['name'];
        $title = !empty($data['title']) && is_string($data['title']) ? (string)$data['title'] : '';
        $description = !empty($data['description']) && is_string($data['description']) ? (string)$data['description'] : '';
        $category = !empty($data['category']) && is_string($data['category']) ? (string)$data['category'] : '';
        $icon = is_string($data['icon'] ?? null) ? (string)$data['icon'] : '';
        $keywords = is_array($data['keywords'] ?? null) ? $this->normalizeStringList((array)$data['keywords']) : [];

        return [
            [
                'name' => $name,
                'source' => 'block.json',
                'title' => $title,
                'description' => $description,
                'category' => $category,
                'icon' => $icon,
                'keywords' => $keywords,
            ],
        ];
    }

    protected function extractBlockPatternsFromSource(string $source): array {
        $matches = [];
        $results = [];
        $index = 0;
        $name = '';

        if (!preg_match_all('/\bregister_block_pattern\s*\(\s*[\'"]([^\'"]+)[\'"]/m', $source, $matches, PREG_SET_ORDER)) {
            return [];
        }

        for ($index = 0; $index < count($matches); $index++) {
            $name = (string)($matches[$index][1] ?? '');

            if ($name !== '') {
                $results[] = $name;
            }
        }

        return $results;
    }

    protected function extractProvidedHooksFromSource(string $source): array {
        $matches = [];
        $results = [];
        $index = 0;
        $type = '';
        $name = '';

        if (!preg_match_all('/\b(do_action|do_action_ref_array|apply_filters|apply_filters_ref_array)\s*\(\s*[\'"]([^\'"]+)[\'"]/m', $source, $matches, PREG_SET_ORDER)) {
            return [];
        }

        for ($index = 0; $index < count($matches); $index++) {
            $type = str_starts_with((string)($matches[$index][1] ?? ''), 'apply_filters') ? 'filter' : 'action';
            $name = (string)($matches[$index][2] ?? '');

            if ($name === '') {
                continue;
            }

            $results[$type . ':' . $name] = [
                'name' => $name,
                'type' => $type,
            ];
        }

        return array_values($results);
    }

    protected function extractCustomPostTypesFromSource(string $source): array {
        $matches = [];
        $results = [];
        $index = 0;
        $slug = '';
        $context = '';
        $label = '';
        $type = '';

        if (!preg_match_all('/register_post_type\s*\(\s*[\'"]([^\'"]+)[\'"]/m', $source, $matches, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        for ($index = 0; $index < count($matches[1]); $index++) {
            $slug = (string)($matches[1][$index][0] ?? '');

            if ($slug === '') {
                continue;
            }

            $context = substr($source, (int)($matches[1][$index][1] ?? 0), 1200);
            $label = $this->extractCustomPostTypeLabel($context, $slug);
            $type = $this->extractCustomPostTypeDisplayType($context);
            $results[$slug] = [
                'slug' => $slug,
                'label' => $label,
                'type' => $type,
            ];
        }

        return array_values($results);
    }

    protected function extractCustomPostTypeLabel(string $context, string $fallback): string {
        $matches = [];

        if (preg_match('/[\'"]name[\'"]\s*=>\s*[\'"]([^\'"]+)[\'"]/i', $context, $matches)) {
            return (string)($matches[1] ?? $fallback);
        }

        if (preg_match('/[\'"]label[\'"]\s*=>\s*[\'"]([^\'"]+)[\'"]/i', $context, $matches)) {
            return (string)($matches[1] ?? $fallback);
        }

        return $fallback;
    }

    protected function extractCustomPostTypeDisplayType(string $context): string {
        if (preg_match('/[\'"]hierarchical[\'"]\s*=>\s*true/i', $context)) {
            return 'page';
        }

        return 'post';
    }

    protected function mergeDiscoveredOptions(array $current, array $additional): array {
        $result = [];
        $item = [];
        $key = '';

        foreach ($current as $item) {
            $key = (string)($item['scope'] ?? '') . ':' . (string)($item['name'] ?? '');
            $result[$key] = $item;
        }

        foreach ($additional as $item) {
            $key = (string)($item['scope'] ?? '') . ':' . (string)($item['name'] ?? '');

            if (!isset($result[$key])) {
                $result[$key] = $item;
                continue;
            }

            $result[$key]['functions'] = $this->mergeStringList(
                (array)($result[$key]['functions'] ?? []),
                (array)($item['functions'] ?? [])
            );
        }

        return array_values($result);
    }

    protected function mergeStringList(array $current, array $additional): array {
        $value = '';

        foreach ($additional as $value) {
            if (!is_string($value) || $value === '' || in_array($value, $current, true)) {
                continue;
            }

            $current[] = $value;
        }

        return $current;
    }

    protected function mergeKeyedRows(array $current, array $additional): array {
        $result = [];
        $item = [];
        $key = '';

        foreach ($current as $item) {
            $key = (string)($item['name'] ?? '');

            if ($key !== '') {
                $result[$key] = $item;
            }
        }

        foreach ($additional as $item) {
            $key = (string)($item['name'] ?? '');

            if ($key !== '') {
                if (isset($result[$key]) && is_array($result[$key])) {
                    $result[$key] = array_merge($result[$key], $item);
                } else {
                    $result[$key] = $item;
                }
            }
        }

        return array_values($result);
    }

    protected function mergePostTypeRows(array $current, array $additional): array {
        $result = [];
        $item = [];
        $key = '';

        foreach ($current as $item) {
            $key = (string)($item['slug'] ?? '');

            if ($key !== '') {
                $result[$key] = $item;
            }
        }

        foreach ($additional as $item) {
            $key = (string)($item['slug'] ?? '');

            if ($key !== '') {
                $result[$key] = $item;
            }
        }

        return array_values($result);
    }

    protected function getPluginReleaseDateLabel(?object $updateItem): string {
        if ($updateItem !== null && !empty($updateItem->last_updated) && is_string($updateItem->last_updated)) {
            return $this->formatDate((string)$updateItem->last_updated);
        }

        return __('Nicht verfügbar.', 'rrze-multisite-manager');
    }

    protected function getPluginSupplementaryData(string $pluginFile): array {
        $pluginDir = $this->getPluginDirectoryPath($pluginFile);
        $packageJsonPath = $pluginDir !== '' ? $pluginDir . '/package.json' : '';
        $readmeTxtPath = $pluginDir !== '' ? $pluginDir . '/readme.txt' : '';
        $readmeMarkdownPath = $this->getFirstExistingPath([
            $pluginDir !== '' ? $pluginDir . '/README.md' : '',
            $pluginDir !== '' ? $pluginDir . '/readme.md' : '',
        ]);
        $packageData = is_readable($packageJsonPath) ? $this->parsePluginPackageJson($packageJsonPath) : [];
        $readmeData = is_readable($readmeTxtPath) ? $this->parsePluginReadmeTxt($readmeTxtPath) : [];
        $readmeMarkdown = is_readable($readmeMarkdownPath) ? (string)file_get_contents($readmeMarkdownPath) : '';
        $sources = [];

        if ($packageData !== []) {
            $sources[] = 'package.json';
        }

        if ($readmeData !== []) {
            $sources[] = 'readme.txt';
        }

        if (trim($readmeMarkdown) !== '') {
            $sources[] = basename($readmeMarkdownPath);
        }

        return [
            'compatibility' => $this->mergePluginCompatibility(
                (array)($packageData['compatibility'] ?? []),
                (array)($readmeData['compatibility'] ?? [])
            ),
            'supports' => $this->mergeStringList(
                (array)($packageData['supports'] ?? []),
                (array)($readmeData['supports'] ?? [])
            ),
            'author' => $this->mergePluginAuthorData(
                (array)($packageData['author'] ?? []),
                (array)($readmeData['author'] ?? [])
            ),
            'license' => $this->mergePluginLicenseData(
                (array)($packageData['license'] ?? []),
                (array)($readmeData['license'] ?? [])
            ),
            'tags' => $this->mergeStringList(
                (array)($packageData['tags'] ?? []),
                (array)($readmeData['tags'] ?? [])
            ),
            'description' => $this->pickFirstNonEmptyString([
                (string)($packageData['description'] ?? ''),
                (string)($readmeData['description'] ?? ''),
            ]),
            'repository' => $this->mergePluginRepositoryData(
                (array)($packageData['repository'] ?? []),
                (array)($readmeData['repository'] ?? [])
            ),
            'readme_markdown' => $readmeMarkdown,
            'sources' => $sources,
        ];
    }

    protected function getPluginDirectoryPath(string $pluginFile): string {
        $mainFilePath = $this->getPluginAbsolutePath($pluginFile);

        if ($mainFilePath === '' || !file_exists($mainFilePath)) {
            return '';
        }

        return dirname($mainFilePath);
    }

    protected function getPluginInstallTimestamp(string $pluginFile): int {
        $pluginDir = $this->getPluginDirectoryPath($pluginFile);
        $target = $pluginDir !== '' && is_dir($pluginDir) ? $pluginDir : $this->getPluginAbsolutePath($pluginFile);

        if ($target === '' || !file_exists($target)) {
            return 0;
        }

        return max(0, (int)@filectime($target));
    }

    protected function getPluginModifiedTimestamp(string $pluginFile): int {
        $mainFilePath = $this->getPluginAbsolutePath($pluginFile);

        if ($mainFilePath === '' || !file_exists($mainFilePath)) {
            return 0;
        }

        return max(0, (int)@filemtime($mainFilePath));
    }

    protected function parsePluginPackageJson(string $path): array {
        $content = (string)file_get_contents($path);
        $data = [];
        $repository = [];
        $compatibility = [];
        $author = [];
        $license = [];

        if ($content === '') {
            return [];
        }

        $data = json_decode($content, true);

        if (!is_array($data)) {
            return [];
        }

        $compatibility = is_array($data['compatibility'] ?? null) ? (array)$data['compatibility'] : [];
        $repository = $this->normalizePluginRepository($data['repository'] ?? []);
        $author = $this->normalizePluginAuthor($data['author'] ?? []);
        $license = $this->normalizePluginLicense($data['license'] ?? ($data['licence'] ?? []), $data['license_url'] ?? ($data['licence_url'] ?? ''));

        return [
            'compatibility' => [
                'wp_requires' => $this->pickFirstNonEmptyString([
                    (string)($compatibility['wprequires'] ?? ''),
                    (string)($compatibility['wprequires'] ?? ''),
                    (string)($compatibility['requires_wp'] ?? ''),
                ]),
                'wp_tested_up_to' => $this->pickFirstNonEmptyString([
                    (string)($compatibility['wptestedup'] ?? ''),
                    (string)($compatibility['wptestetup'] ?? ''),
                    (string)($compatibility['tested_up_to'] ?? ''),
                ]),
                'php_requires' => $this->pickFirstNonEmptyString([
                    (string)($compatibility['phprequires'] ?? ''),
                    (string)($compatibility['requires_php'] ?? ''),
                ]),
            ],
            'supports' => $this->normalizeStringList($data['supports'] ?? []),
            'author' => $author,
            'license' => $license,
            'tags' => $this->normalizeStringList($data['tags'] ?? []),
            'description' => is_string($data['description'] ?? null) ? trim((string)$data['description']) : '',
            'repository' => $repository,
        ];
    }

    protected function parsePluginReadmeTxt(string $path): array {
        $content = (string)file_get_contents($path);
        $compatibility = [
            'wp_requires' => '',
            'wp_tested_up_to' => '',
            'php_requires' => '',
        ];
        $tags = [];
        $license = [
            'name' => '',
            'url' => '',
        ];
        $description = '';
        $lines = [];
        $line = '';

        if ($content === '') {
            return [];
        }

        $compatibility['wp_requires'] = $this->extractReadmeHeaderValue($content, 'Requires at least');
        $compatibility['wp_tested_up_to'] = $this->extractReadmeHeaderValue($content, 'Tested up to');
        $compatibility['php_requires'] = $this->extractReadmeHeaderValue($content, 'Requires PHP');
        $license['name'] = $this->extractReadmeHeaderValue($content, 'License');
        $license['url'] = $this->extractReadmeHeaderValue($content, 'License URI');
        $description = $this->extractReadmeDescription($content);
        $lines = explode("\n", str_replace("\r", '', $content));

        foreach ($lines as $line) {
            if (!str_starts_with(strtolower($line), 'tags:')) {
                continue;
            }

            $tags = $this->normalizeStringList(explode(',', trim(substr($line, 5))));
            break;
        }

        return [
            'compatibility' => $compatibility,
            'supports' => [],
            'author' => [],
            'license' => $license,
            'tags' => $tags,
            'description' => $description,
            'repository' => [],
        ];
    }

    protected function extractReadmeHeaderValue(string $content, string $label): string {
        $matches = [];

        if (!preg_match('/^' . preg_quote($label, '/') . ':\s*(.+)$/mi', $content, $matches)) {
            return '';
        }

        return trim((string)($matches[1] ?? ''));
    }

    protected function extractReadmeDescription(string $content): string {
        $parts = preg_split('/==\s*Description\s*==/i', $content);
        $section = '';
        $sectionParts = [];

        if (!is_array($parts) || empty($parts[1])) {
            return '';
        }

        $section = (string)$parts[1];
        $sectionParts = preg_split('/\n==[^=]+==\n/', $section, 2);

        if (!is_array($sectionParts) || empty($sectionParts[0])) {
            return '';
        }

        return trim(wp_strip_all_tags((string)$sectionParts[0]));
    }

    protected function normalizePluginRepository(mixed $repositoryValue): array {
        $repository = [
            'type' => '',
            'url' => '',
            'issues' => '',
            'clone' => '',
        ];

        if (is_string($repositoryValue) && trim($repositoryValue) !== '') {
            $repository['url'] = trim($repositoryValue);
            return $repository;
        }

        if (!is_array($repositoryValue)) {
            return $repository;
        }

        $repository['type'] = is_string($repositoryValue['type'] ?? null) ? trim((string)$repositoryValue['type']) : '';
        $repository['url'] = is_string($repositoryValue['url'] ?? null) ? trim((string)$repositoryValue['url']) : '';
        $repository['clone'] = is_string($repositoryValue['clone'] ?? null) ? trim((string)$repositoryValue['clone']) : '';

        if (is_string($repositoryValue['issues'] ?? null)) {
            $repository['issues'] = trim((string)$repositoryValue['issues']);
        } elseif (is_array($repositoryValue['issues'] ?? null) && is_string($repositoryValue['issues']['url'] ?? null)) {
            $repository['issues'] = trim((string)$repositoryValue['issues']['url']);
        }

        return $repository;
    }

    protected function normalizePluginAuthor(mixed $authorValue): array {
        if (is_string($authorValue) && trim($authorValue) !== '') {
            return [
                'name' => trim($authorValue),
                'email' => '',
                'url' => '',
            ];
        }

        if (!is_array($authorValue)) {
            return [
                'name' => '',
                'email' => '',
                'url' => '',
            ];
        }

        return [
            'name' => is_string($authorValue['name'] ?? null) ? trim((string)$authorValue['name']) : '',
            'email' => is_string($authorValue['email'] ?? null) ? trim((string)$authorValue['email']) : '',
            'url' => is_string($authorValue['url'] ?? null) ? trim((string)$authorValue['url']) : '',
        ];
    }

    protected function normalizePluginLicense(mixed $licenseValue, mixed $licenseUrl = ''): array {
        return [
            'name' => is_string($licenseValue) ? trim($licenseValue) : '',
            'url' => is_string($licenseUrl) ? trim($licenseUrl) : '',
        ];
    }

    protected function normalizeStringList(mixed $values): array {
        $result = [];
        $value = '';

        if (is_string($values)) {
            $values = preg_split('/[,\\n]+/', $values);
        }

        if (!is_array($values)) {
            return [];
        }

        foreach ($values as $value) {
            if (!is_string($value)) {
                continue;
            }

            $value = trim($value);

            if ($value === '' || in_array($value, $result, true)) {
                continue;
            }

            $result[] = $value;
        }

        return $result;
    }

    protected function mergePluginCompatibility(array $packageCompatibility, array $readmeCompatibility): array {
        return [
            'wp_requires' => $this->pickFirstNonEmptyString([
                (string)($packageCompatibility['wp_requires'] ?? ''),
                (string)($readmeCompatibility['wp_requires'] ?? ''),
            ]),
            'wp_tested_up_to' => $this->pickFirstNonEmptyString([
                (string)($packageCompatibility['wp_tested_up_to'] ?? ''),
                (string)($readmeCompatibility['wp_tested_up_to'] ?? ''),
            ]),
            'php_requires' => $this->pickFirstNonEmptyString([
                (string)($packageCompatibility['php_requires'] ?? ''),
                (string)($readmeCompatibility['php_requires'] ?? ''),
            ]),
        ];
    }

    protected function mergePluginAuthorData(array $packageAuthor, array $readmeAuthor): array {
        return [
            'name' => $this->pickFirstNonEmptyString([
                (string)($packageAuthor['name'] ?? ''),
                (string)($readmeAuthor['name'] ?? ''),
            ]),
            'email' => $this->pickFirstNonEmptyString([
                (string)($packageAuthor['email'] ?? ''),
                (string)($readmeAuthor['email'] ?? ''),
            ]),
            'url' => $this->pickFirstNonEmptyString([
                (string)($packageAuthor['url'] ?? ''),
                (string)($readmeAuthor['url'] ?? ''),
            ]),
        ];
    }

    protected function mergePluginLicenseData(array $packageLicense, array $readmeLicense): array {
        return [
            'name' => $this->pickFirstNonEmptyString([
                (string)($packageLicense['name'] ?? ''),
                (string)($readmeLicense['name'] ?? ''),
            ]),
            'url' => $this->pickFirstNonEmptyString([
                (string)($packageLicense['url'] ?? ''),
                (string)($readmeLicense['url'] ?? ''),
            ]),
        ];
    }

    protected function mergePluginRepositoryData(array $packageRepository, array $readmeRepository): array {
        return [
            'type' => $this->pickFirstNonEmptyString([
                (string)($packageRepository['type'] ?? ''),
                (string)($readmeRepository['type'] ?? ''),
            ]),
            'url' => $this->pickFirstNonEmptyString([
                (string)($packageRepository['url'] ?? ''),
                (string)($readmeRepository['url'] ?? ''),
            ]),
            'issues' => $this->pickFirstNonEmptyString([
                (string)($packageRepository['issues'] ?? ''),
                (string)($readmeRepository['issues'] ?? ''),
            ]),
            'clone' => $this->pickFirstNonEmptyString([
                (string)($packageRepository['clone'] ?? ''),
                (string)($readmeRepository['clone'] ?? ''),
            ]),
        ];
    }

    protected function pickFirstNonEmptyString(array $values): string {
        $value = '';

        foreach ($values as $value) {
            if (trim((string)$value) !== '') {
                return trim((string)$value);
            }
        }

        return '';
    }

    protected function getFirstExistingPath(array $paths): string {
        $path = '';

        foreach ($paths as $path) {
            if ($path !== '' && file_exists($path)) {
                return $path;
            }
        }

        return '';
    }

    protected function getThemes(): array {
        $themes = wp_get_themes();
        $allowedThemes = $this->getAllowedThemes();
        $siteCounts = $this->getThemeSiteCounts();
        $siteUsageMap = $this->getThemeSiteUsageMap();
        $results = [];
        $stylesheet = '';
        $theme = null;
        $themeData = [];
        $description = '';
        $screenshot = '';
        $tags = [];

        foreach ($themes as $stylesheet => $theme) {
            if (!$theme instanceof \WP_Theme) {
                continue;
            }

            $description = (string)$theme->get('Description');
            $screenshot = $theme->get_screenshot();
            $tags = $theme->get('Tags');
            $themeData = [
                'stylesheet' => $stylesheet,
                'name' => $theme->get('Name') ?: $stylesheet,
                'version' => $theme->get('Version') ?: 'n/a',
                'description' => wp_strip_all_tags($description),
                'site_count' => (int)($siteCounts[$stylesheet] ?? 0),
                'network_enabled' => isset($allowedThemes[$stylesheet]),
                'active_sites' => (array)($siteUsageMap[$stylesheet] ?? []),
                'author' => (string)$theme->get('Author'),
                'author_url' => (string)$theme->get('AuthorURI'),
                'theme_uri' => (string)$theme->get('ThemeURI'),
                'text_domain' => (string)$theme->get('TextDomain'),
                'template' => (string)$theme->get_template(),
                'screenshot' => is_string($screenshot) ? $screenshot : '',
                'is_block_theme' => method_exists($theme, 'is_block_theme') ? (bool)$theme->is_block_theme() : false,
                'tags' => is_array($tags) ? $this->normalizeStringList($tags) : [],
            ];
            $themeData['status'] = $this->getThemeStatus($themeData);
            $results[] = $themeData;
        }

        usort($results, [self::class, 'compareThemeUsage']);

        return $results;
    }

    protected function getThemeUsage(): array {
        $themes = $this->getThemes();
        return $this->buildThemeUsageDistribution($themes);
    }

    protected function getInactiveThemes(): array {
        $themes = $this->getThemes();

        return array_values(
            array_filter(
                $themes,
                [self::class, 'isUnusedTheme']
            )
        );
    }

    protected function buildThemeUsageDistribution(array $themes): array {
        $totalSites = count(get_sites([
            'fields' => 'ids',
            'number' => 0,
        ]));
        $items = [];
        $theme = [];

        foreach ($themes as $theme) {
            if ((int)($theme['site_count'] ?? 0) <= 0) {
                continue;
            }

            $items[] = [
                'label' => (string)($theme['name'] ?? ''),
                'value' => (int)($theme['site_count'] ?? 0),
            ];
        }

        usort($items, [self::class, 'compareUsageDistributionRows']);

        return $this->finalizeUsageDistribution($items, $totalSites);
    }

    protected function buildPluginUsageDistribution(array $pluginStats): array {
        $items = [];
        $plugin = [];
        $totalUsage = 0;

        foreach ($pluginStats as $plugin) {
            if ((int)($plugin['site_count'] ?? 0) <= 0) {
                continue;
            }

            $items[] = [
                'label' => (string)($plugin['name'] ?? $plugin['file'] ?? ''),
                'value' => (int)($plugin['site_count'] ?? 0),
            ];
            $totalUsage += (int)($plugin['site_count'] ?? 0);
        }

        usort($items, [self::class, 'compareUsageDistributionRows']);

        return $this->finalizeUsageDistribution($items, $totalUsage);
    }

    protected function finalizeUsageDistribution(array $items, int $totalSites): array {
        $results = [];
        $index = 0;
        $item = [];
        $value = 0;

        if ($totalSites <= 0) {
            return [];
        }

        foreach ($items as $item) {
            $value = (int)($item['value'] ?? 0);
            $results[] = [
                'label' => (string)($item['label'] ?? ''),
                'value' => $value,
                'percent' => (int)round(($value / $totalSites) * 100),
                'accent' => 'theme-' . (($index % 6) + 1),
            ];
            $index++;
        }

        return $results;
    }

    protected function getEditorUsage(): array {
        $siteIds = get_sites([
            'fields' => 'ids',
            'number' => 0,
        ]);
        $networkActivePlugins = get_site_option('active_sitewide_plugins', []);
        $classicEverywhere = isset($networkActivePlugins['classic-editor/classic-editor.php']);
        $classicSites = 0;
        $blockSites = 0;
        $siteId = 0;
        $activePlugins = [];
        $totalSites = count($siteIds);

        foreach ($siteIds as $siteId) {
            if ($classicEverywhere) {
                $classicSites++;
                continue;
            }

            $activePlugins = get_blog_option((int)$siteId, 'active_plugins', []);

            if (is_array($activePlugins) && in_array('classic-editor/classic-editor.php', $activePlugins, true)) {
                $classicSites++;
            } else {
                $blockSites++;
            }
        }

        if ($totalSites === 0) {
            return [];
        }

        return [
            [
                'label' => __('Classic Editor', 'rrze-multisite-manager'),
                'value' => $classicSites,
                'percent' => (int)round(($classicSites / $totalSites) * 100),
                'accent' => 'warning',
            ],
            [
                'label' => __('Block Editor', 'rrze-multisite-manager'),
                'value' => $blockSites,
                'percent' => (int)round(($blockSites / $totalSites) * 100),
                'accent' => 'info',
            ],
        ];
    }

    protected function countSites(array $args = []): int {
        $queryArgs = array_merge(
            [
                'count' => true,
                'number' => 1,
            ],
            $args
        );

        return (int)get_sites($queryArgs);
    }

    protected function countRecentSites(int $days): int {
        global $wpdb;

        $sql = $wpdb->prepare(
            "SELECT COUNT(blog_id) FROM {$wpdb->blogs}
            WHERE site_id = %d
            AND registered >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)",
            get_current_network_id(),
            $days
        );

        return (int)$wpdb->get_var($sql);
    }

    protected function formatSites(array $sites, bool $includeOverviewMetrics = false): array {
        $results = [];
        $site = null;
        $siteId = 0;
        $registered = '';
        $lastUpdatedTimestamp = 0;
        $lastUpdated = '';
        $formattedSite = [];
        $statusMeta = [];

        foreach ($sites as $site) {
            if (!$site instanceof \WP_Site) {
                continue;
            }

            $siteId = (int)$site->blog_id;
            $registered = (string)$site->registered;
            $lastUpdated = isset($site->last_updated) ? (string)$site->last_updated : '';
            $lastUpdatedTimestamp = $this->getSiteLastUpdatedTimestamp($lastUpdated, $registered);

            $formattedSite = [
                'id' => $siteId,
                'name' => $this->getSiteName($site),
                'url' => get_home_url($siteId, '/'),
                'name_sort' => strtolower($this->getSiteName($site)),
                'registered_label' => $this->formatDate($registered),
                'registered_timestamp' => $this->parseDateToTimestamp($registered),
                'last_updated_label' => $this->formatTimestamp($lastUpdatedTimestamp),
                'last_updated_timestamp' => $lastUpdatedTimestamp,
                'admin_email' => $this->getSiteAdminEmail($siteId),
                'admin_email_sort' => strtolower($this->getSiteAdminEmail($siteId)),
                'status' => $this->getSiteStatus($siteId, $site),
                'is_main_site' => is_main_site($siteId),
                'is_archived' => ((int)$site->archived === 1),
                'is_spam' => ((int)$site->spam === 1),
                'is_deleted' => ((int)$site->deleted === 1),
            ];

            if ($includeOverviewMetrics) {
                $formattedSite = array_merge($formattedSite, $this->getSiteOverviewMetrics($siteId));
            }

            $statusMeta = $this->getSiteStatusMeta($siteId);
            $formattedSite = array_merge($formattedSite, $statusMeta);

            $results[] = $formattedSite;
        }

        return $results;
    }

    protected function getSitesSortedByActivity(string $direction): array {
        $sites = get_sites([
            'number' => 0,
        ]);
        $results = $this->formatSites($sites);
        $thresholdTimestamp = $this->getInactiveThresholdTimestamp();

        usort($results, [$this, 'compareSitesByActivity']);

        if ($direction === 'ASC') {
            $results = array_reverse($results);
        }

        foreach ($results as $index => $site) {
            $results[$index]['highlight_inactive'] = ((int)$site['last_updated_timestamp'] > 0 && (int)$site['last_updated_timestamp'] <= $thresholdTimestamp);
        }

        return $results;
    }

    protected function getSiteName(\WP_Site $site): string {
        $blogName = get_blog_option((int)$site->blog_id, 'blogname', '');

        if (is_string($blogName) && trim($blogName) !== '') {
            return $blogName;
        }

        return untrailingslashit($site->domain . $site->path);
    }

    protected function formatDate(string $dateValue): string {
        $timestamp = $this->parseDateToTimestamp($dateValue);

        return $this->formatTimestamp($timestamp);
    }

    protected function formatTimestamp(int $timestamp): string {

        if ($timestamp <= 0) {
            return __('Unbekannt', 'rrze-multisite-manager');
        }

        return wp_date(get_option('date_format') . ' ' . get_option('time_format'), $timestamp);
    }

    protected function getSiteLastUpdatedTimestamp(string $lastUpdatedDate, string $fallbackDate): int {
        $fallbackTimestamp = $this->parseDateToTimestamp($fallbackDate);
        $timestamp = $this->parseDateToTimestamp($lastUpdatedDate, true);

        if ($timestamp <= 0) {
            return $fallbackTimestamp;
        }

        return $timestamp;
    }

    protected function getSiteAdminEmail(int $siteId): string {
        $email = get_blog_option($siteId, 'admin_email', '');

        if (!is_string($email) || trim($email) === '') {
            return '';
        }

        return $email;
    }

    protected function getSiteStatusMeta(int $siteId): array {
        return [
            'status_note' => (string)get_site_meta($siteId, 'rrze_msm_status_note', true),
            'status_user_id' => (int)get_site_meta($siteId, 'rrze_msm_status_user_id', true),
            'archived_at' => (string)get_site_meta($siteId, 'rrze_msm_archived_at', true),
            'spam_at' => (string)get_site_meta($siteId, 'rrze_msm_spam_at', true),
        ];
    }

    protected function getStatusUserData(int $userId): array {
        $user = null;

        if ($userId <= 0) {
            return [
                'id' => 0,
                'display_name' => '',
                'email' => '',
            ];
        }

        $user = get_userdata($userId);

        if (!$user instanceof \WP_User) {
            return [
                'id' => $userId,
                'display_name' => '',
                'email' => '',
            ];
        }

        return [
            'id' => $userId,
            'display_name' => (string)$user->display_name,
            'email' => (string)$user->user_email,
        ];
    }

    protected function getSiteOverviewMetrics(int $siteId): array {
        $roleCounts = [
            'admins' => 0,
            'editors' => 0,
            'others' => 0,
        ];
        $contentCounts = [
            'pages' => 0,
            'posts' => 0,
            'media' => 0,
        ];
        $storage = [
            'used_label' => __('Unbekannt', 'rrze-multisite-manager'),
            'max_label' => '',
            'percent' => null,
            'warn_level' => '',
        ];
        $branding = [
            'url' => '',
            'type' => '',
        ];
        $userData = [];

        switch_to_blog($siteId);

        $userData = count_users();
        $branding = $this->getSiteBranding();
        $roleCounts = $this->normalizeRoleCounts(is_array($userData['avail_roles'] ?? null) ? $userData['avail_roles'] : []);
        $contentCounts['pages'] = $this->countContentItems('page');
        $contentCounts['posts'] = $this->countContentItems('post');
        $contentCounts['media'] = $this->countContentItems('attachment');
        $storage = $this->getSiteStorageUsage();

        restore_current_blog();

        return [
            'branding' => $branding,
            'role_counts' => $roleCounts,
            'content_counts' => $contentCounts,
            'storage' => $storage,
        ];
    }

    protected function getSiteDetailMetrics(int $siteId): array {
        $theme = [
            'name' => '',
            'version' => '',
            'description' => '',
            'screenshot' => '',
        ];
        $plugins = [];
        $users = [];
        $contentTypes = [];
        $optionsOverview = [];
        $customPostTypes = [];
        $blockTemplateTypes = [];
        $transients = [];
        $cronEvents = [];

        switch_to_blog($siteId);
        $theme = $this->getCurrentThemeDetails();
        $plugins = $this->getCurrentSiteActivePlugins();
        $users = $this->getCurrentSiteUsers();
        $contentTypes = $this->getCurrentSiteContentTypeCounts();
        $customPostTypes = $this->getCurrentSiteCustomPostTypes();
        $blockTemplateTypes = $this->getCurrentSiteBlockTemplateTypes();
        $optionsOverview = $this->getCurrentSiteOptionsOverview();
        $transients = $this->getCurrentSiteTransients();
        $cronEvents = $this->getCurrentSiteCronEvents();
        restore_current_blog();

        return [
            'theme' => $theme,
            'plugins' => $plugins,
            'users' => $users,
            'content_types' => $contentTypes,
            'custom_post_types' => $customPostTypes,
            'block_template_types' => $blockTemplateTypes,
            'options_overview' => $optionsOverview,
            'transients' => $transients,
            'cron_events' => $cronEvents,
        ];
    }

    protected function getCurrentThemeDetails(): array {
        $theme = wp_get_theme();
        $screenshot = $theme instanceof \WP_Theme ? $theme->get_screenshot() : '';
        $description = '';
        $tags = [];

        if ($theme instanceof \WP_Theme) {
            $description = (string)$theme->get('Description');
            $tags = $theme->get('Tags');
        }

        return [
            'name' => $theme instanceof \WP_Theme ? ((string)$theme->get('Name') ?: (string)$theme->get_stylesheet()) : '',
            'stylesheet' => $theme instanceof \WP_Theme ? (string)$theme->get_stylesheet() : '',
            'version' => $theme instanceof \WP_Theme ? ((string)$theme->get('Version') ?: '') : '',
            'description' => wp_strip_all_tags($description),
            'screenshot' => is_string($screenshot) ? $screenshot : '',
            'is_block_theme' => $theme instanceof \WP_Theme && method_exists($theme, 'is_block_theme') ? (bool)$theme->is_block_theme() : false,
            'theme_uri' => $theme instanceof \WP_Theme ? (string)$theme->get('ThemeURI') : '',
            'author' => $theme instanceof \WP_Theme ? (string)$theme->get('Author') : '',
            'author_url' => $theme instanceof \WP_Theme ? (string)$theme->get('AuthorURI') : '',
            'tags' => is_array($tags) ? $this->normalizeStringList($tags) : [],
        ];
    }

    protected function getCurrentSiteActivePlugins(): array {
        $networkActivePlugins = get_site_option('active_sitewide_plugins', []);
        $localActivePlugins = get_option('active_plugins', []);
        $pluginFiles = [];
        $results = [];
        $pluginFile = '';
        $pluginHeaders = [];
        $siteId = get_current_blog_id();

        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        if (!is_array($networkActivePlugins)) {
            $networkActivePlugins = [];
        }

        if (!is_array($localActivePlugins)) {
            $localActivePlugins = [];
        }

        $pluginFiles = array_keys($networkActivePlugins);

        foreach ($localActivePlugins as $pluginFile) {
            if (!is_string($pluginFile) || $pluginFile === '' || in_array($pluginFile, $pluginFiles, true)) {
                continue;
            }

            $pluginFiles[] = $pluginFile;
        }

        foreach ($pluginFiles as $pluginFile) {
            $pluginHeaders = get_plugin_data(WP_PLUGIN_DIR . '/' . $pluginFile, false, false);
            $results[] = [
                'file' => $pluginFile,
                'name' => (string)($pluginHeaders['Name'] ?? $pluginFile),
                'version' => (string)($pluginHeaders['Version'] ?? ''),
                'description' => wp_strip_all_tags((string)($pluginHeaders['Description'] ?? '')),
                'author' => $this->getPluginAuthorLabel($pluginHeaders),
                'network_active' => isset($networkActivePlugins[$pluginFile]),
                'settings_url' => $this->getPluginSettingsUrl($pluginFile, $pluginHeaders),
                'details_url' => $this->getPluginDetailsUrl($pluginHeaders),
                'deactivate_url' => isset($networkActivePlugins[$pluginFile]) ? '' : $this->getSitePluginDeactivateUrl($siteId, $pluginFile),
            ];
        }

        usort($results, [self::class, 'compareDetailedPlugins']);

        return $results;
    }

    protected function getPluginAuthorLabel(array $pluginData): string {
        if (!empty($pluginData['AuthorName']) && is_string($pluginData['AuthorName'])) {
            return trim((string)$pluginData['AuthorName']);
        }

        if (!empty($pluginData['Author']) && is_string($pluginData['Author'])) {
            return trim(wp_strip_all_tags((string)$pluginData['Author']));
        }

        return '';
    }

    protected function getPluginAuthorUrl(array $pluginData): string {
        if (!empty($pluginData['AuthorURI']) && is_string($pluginData['AuthorURI'])) {
            return (string)$pluginData['AuthorURI'];
        }

        return '';
    }

    protected function getPluginSettingsUrl(string $pluginFile, array $pluginData): string {
        $actions = apply_filters('network_admin_plugin_action_links_' . $pluginFile, [], $pluginData, 'all');
        $url = $this->extractFirstActionUrl($actions, ['settings']);

        if ($url !== '') {
            return $url;
        }

        $actions = apply_filters('plugin_action_links_' . $pluginFile, [], $pluginData, 'all');
        return $this->extractFirstActionUrl($actions, ['settings']);
    }

    protected function getPluginDetailsUrl(array $pluginData): string {
        if (!empty($pluginData['PluginURI']) && is_string($pluginData['PluginURI'])) {
            return (string)$pluginData['PluginURI'];
        }

        return '';
    }

    protected function getPluginUpdateDetailsUrl(array $pluginData, ?object $updateItem): string {
        if ($updateItem !== null && !empty($updateItem->url) && is_string($updateItem->url)) {
            return (string)$updateItem->url;
        }

        return $this->getPluginDetailsUrl($pluginData);
    }

    protected function getNetworkPluginDeactivateUrl(string $pluginFile): string {
        return wp_nonce_url(
            add_query_arg(
                [
                    'action' => 'deactivate',
                    'plugin' => $pluginFile,
                ],
                network_admin_url('plugins.php')
            ),
            'deactivate-plugin_' . $pluginFile
        );
    }

    protected function getNetworkPluginUpdateUrl(string $pluginFile): string {
        return wp_nonce_url(
            add_query_arg(
                [
                    'action' => 'upgrade-plugin',
                    'plugin' => $pluginFile,
                ],
                network_admin_url('update.php')
            ),
            'upgrade-plugin_' . $pluginFile
        );
    }

    protected function getNetworkPluginDeleteUrl(string $pluginFile): string {
        return wp_nonce_url(
            'plugins.php?action=delete-selected&verify-delete=1&checked[]=' . rawurlencode($pluginFile),
            'bulk-plugins'
        );
    }

    protected function getSiteNameById(int $siteId): string {
        $site = get_site($siteId);

        if (!$site instanceof \WP_Site) {
            return (string)$siteId;
        }

        return $this->getSiteName($site);
    }

    protected function sortPluginActiveSites(array $sites): array {
        usort($sites, [self::class, 'comparePluginActiveSites']);

        return $sites;
    }

    protected function getSitePluginDeactivateUrl(int $siteId, string $pluginFile): string {
        return wp_nonce_url(
            add_query_arg(
                [
                    'action' => 'deactivate',
                    'plugin' => $pluginFile,
                ],
                get_admin_url($siteId, 'plugins.php')
            ),
            'deactivate-plugin_' . $pluginFile
        );
    }

    protected function extractFirstActionUrl(array $actions, array $preferredKeys): string {
        $preferredKey = '';
        $action = '';
        $url = '';

        foreach ($preferredKeys as $preferredKey) {
            if (!empty($actions[$preferredKey]) && is_string($actions[$preferredKey])) {
                $url = $this->extractHrefFromHtml($actions[$preferredKey]);

                if ($url !== '') {
                    return $url;
                }
            }
        }

        foreach ($actions as $action) {
            if (!is_string($action)) {
                continue;
            }

            $url = $this->extractHrefFromHtml($action);

            if ($url !== '') {
                return $url;
            }
        }

        return '';
    }

    protected function extractHrefFromHtml(string $html): string {
        $matches = [];

        if (preg_match('/href=[\'"]([^\'"]+)[\'"]/i', $html, $matches) === 1 && !empty($matches[1])) {
            return html_entity_decode((string)$matches[1], ENT_QUOTES, 'UTF-8');
        }

        return '';
    }

    protected function getCurrentSiteUsers(): array {
        $users = get_users([
            'blog_id' => get_current_blog_id(),
            'orderby' => 'display_name',
            'order' => 'ASC',
        ]);
        $results = [];
        $user = null;
        $roles = [];

        foreach ($users as $user) {
            if (!$user instanceof \WP_User) {
                continue;
            }

            $roles = is_array($user->roles) ? $user->roles : [];
            $results[] = [
                'id' => (int)$user->ID,
                'username' => (string)$user->user_login,
                'name' => trim((string)$user->display_name),
                'email' => (string)$user->user_email,
                'role_key' => $this->getPrimaryUserRole($roles),
                'role_label' => $this->getPrimaryUserRoleLabel($roles),
            ];
        }

        usort($results, [self::class, 'compareDetailedUsers']);

        return $results;
    }

    protected function getCurrentSiteContentTypeCounts(): array {
        $postTypes = get_post_types([], 'objects');
        $postTypeCounts = $this->getDistinctPostTypeCounts();
        $results = [];
        $grouped = [
            'post' => [],
            'page' => [],
            'attachment' => [],
            'other' => [],
        ];
        $postType = null;
        $total = 0;
        $slug = '';
        $label = '';
        $attachmentCounts = [];
        $group = '';
        $excludedTypes = [
            'revision',
            'nav_menu_item',
            'custom_css',
            'customize_changeset',
            'oembed_cache',
            'user_request',
            'wp_block',
            'wp_template',
            'wp_template_part',
            'wp_global_styles',
            'wp_navigation',
            'wp_font_family',
            'wp_font_face',
            'wp_pattern_category',
        ];

        if (isset($postTypes['post'])) {
            $grouped['post'][] = [
                'slug' => 'post',
                'label' => $postTypes['post']->labels->name ?: 'post',
                'count' => (int)($postTypeCounts['post'] ?? 0),
                'level' => 0,
            ];
        }

        if (isset($postTypes['page'])) {
            $grouped['page'][] = [
                'slug' => 'page',
                'label' => $postTypes['page']->labels->name ?: 'page',
                'count' => (int)($postTypeCounts['page'] ?? 0),
                'level' => 0,
            ];
        }

        if (isset($postTypes['attachment'])) {
            $grouped['attachment'][] = [
                'slug' => 'attachment',
                'label' => $postTypes['attachment']->labels->name ?: 'attachment',
                'count' => (int)($postTypeCounts['attachment'] ?? 0),
                'level' => 0,
            ];
        }

        foreach ($postTypeCounts as $slug => $total) {
            if (in_array($slug, $excludedTypes, true) || in_array($slug, ['post', 'page', 'attachment'], true)) {
                continue;
            }

            if ($total <= 0) {
                continue;
            }

            $postType = isset($postTypes[$slug]) && $postTypes[$slug] instanceof \WP_Post_Type ? $postTypes[$slug] : null;
            $label = $postType instanceof \WP_Post_Type ? ($postType->labels->name ?: $slug) : $slug;
            $group = $postType instanceof \WP_Post_Type ? $this->getPostTypeCapabilityGroup($postType) : 'post';

            if ($group === 'post') {
                $grouped['post'][] = [
                    'slug' => $slug,
                    'label' => $label,
                    'count' => $total,
                    'level' => 1,
                    'registered' => $postType instanceof \WP_Post_Type,
                ];
                continue;
            }

            if ($group === 'page') {
                $grouped['page'][] = [
                    'slug' => $slug,
                    'label' => $label,
                    'count' => $total,
                    'level' => 1,
                    'registered' => $postType instanceof \WP_Post_Type,
                ];
                continue;
            }

            $grouped[$group][] = [
                'slug' => $slug,
                'label' => $label,
                'count' => $total,
                'level' => 0,
                'registered' => $postType instanceof \WP_Post_Type,
            ];
        }

        $attachmentCounts = $this->getAttachmentTypeCounts();

        foreach ($attachmentCounts as $slug => $count) {
            if ($count <= 0) {
                continue;
            }

            $grouped['attachment'][] = [
                'slug' => $slug,
                'label' => $this->getAttachmentTypeLabel($slug),
                'count' => $count,
                'level' => 1,
            ];
        }

        usort($grouped['post'], [self::class, 'compareDetailedContentTypes']);
        usort($grouped['page'], [self::class, 'compareDetailedContentTypes']);
        usort($grouped['other'], [self::class, 'compareDetailedContentTypes']);

        if (!empty($grouped['post'])) {
            $results = array_merge($results, $grouped['post']);
        }

        if (!empty($grouped['page'])) {
            $results = array_merge($results, $grouped['page']);
        }

        if (!empty($grouped['attachment'])) {
            $results = array_merge($results, $grouped['attachment']);
        }

        if (!empty($grouped['other'])) {
            $results = array_merge($results, $grouped['other']);
        }

        return $results;
    }

    protected function getCurrentSiteCustomPostTypes(): array {
        $postTypes = get_post_types([], 'objects');
        $postTypeCounts = $this->getDistinctPostTypeCounts();
        $results = [];
        $slug = '';
        $count = 0;
        $postType = null;
        $excludedTypes = [
            'post',
            'page',
            'attachment',
            'revision',
            'nav_menu_item',
            'custom_css',
            'customize_changeset',
            'oembed_cache',
            'user_request',
            'wp_block',
            'wp_template',
            'wp_template_part',
            'wp_global_styles',
            'wp_navigation',
            'wp_font_family',
            'wp_font_face',
            'wp_pattern_category',
        ];

        foreach ($postTypeCounts as $slug => $count) {
            if (in_array($slug, $excludedTypes, true) || $count <= 0) {
                continue;
            }

            $postType = isset($postTypes[$slug]) && $postTypes[$slug] instanceof \WP_Post_Type ? $postTypes[$slug] : null;
            $results[] = [
                'slug' => $slug,
                'label' => $postType instanceof \WP_Post_Type ? ($postType->labels->name ?: $slug) : $slug,
                'count' => $count,
                'registered' => $postType instanceof \WP_Post_Type,
                'group' => $postType instanceof \WP_Post_Type ? $this->getPostTypeCapabilityGroup($postType) : 'post',
            ];
        }

        usort($results, [self::class, 'compareDetailedContentTypes']);

        return $results;
    }

    protected function getCurrentSiteBlockTemplateTypes(): array {
        $postTypeCounts = $this->getDistinctPostTypeCounts();
        $results = [];
        $map = [
            'wp_template' => __('Block Templates', 'rrze-multisite-manager'),
            'wp_template_part' => __('Template Parts', 'rrze-multisite-manager'),
        ];
        $slug = '';

        foreach ($map as $slug => $label) {
            if (empty($postTypeCounts[$slug])) {
                continue;
            }

            $results[] = [
                'slug' => $slug,
                'label' => $label,
                'count' => (int)$postTypeCounts[$slug],
            ];
        }

        return $results;
    }

    protected function getPostTypeCapabilityGroup(\WP_Post_Type $postType): string {
        if ($postType->name === 'attachment') {
            return 'attachment';
        }

        if (!empty($postType->hierarchical)) {
            return 'page';
        }

        return 'post';
    }

    protected function getAttachmentTypeCounts(): array {
        global $wpdb;

        $rows = [];
        $counts = [
            'attachment-image' => 0,
            'attachment-audio' => 0,
            'attachment-video' => 0,
            'attachment-document' => 0,
        ];
        $row = null;
        $mime = '';
        $count = 0;

        $rows = $wpdb->get_results(
            "SELECT post_mime_type, COUNT(ID) AS total
            FROM {$wpdb->posts}
            WHERE post_type = 'attachment'
            AND post_status <> 'trash'
            GROUP BY post_mime_type"
        );

        foreach ($rows as $row) {
            $mime = (string)($row->post_mime_type ?? '');
            $count = (int)($row->total ?? 0);

            if (str_starts_with($mime, 'image/')) {
                $counts['attachment-image'] += $count;
                continue;
            }

            if (str_starts_with($mime, 'audio/')) {
                $counts['attachment-audio'] += $count;
                continue;
            }

            if (str_starts_with($mime, 'video/')) {
                $counts['attachment-video'] += $count;
                continue;
            }

            $counts['attachment-document'] += $count;
        }

        return $counts;
    }

    protected function getAttachmentTypeLabel(string $slug): string {
        if ($slug === 'attachment-image') {
            return __('Bilder', 'rrze-multisite-manager');
        }

        if ($slug === 'attachment-audio') {
            return __('Audio', 'rrze-multisite-manager');
        }

        if ($slug === 'attachment-video') {
            return __('Video', 'rrze-multisite-manager');
        }

        return __('Dokumente', 'rrze-multisite-manager');
    }

    protected function getPrimaryUserRole(array $roles): string {
        if (in_array('administrator', $roles, true)) {
            return 'administrator';
        }

        if (in_array('editor', $roles, true)) {
            return 'editor';
        }

        if (!empty($roles[0]) && is_string($roles[0])) {
            return $roles[0];
        }

        return '';
    }

    protected function getPrimaryUserRoleLabel(array $roles): string {
        $roleKey = $this->getPrimaryUserRole($roles);
        $wpRoles = wp_roles();

        if ($roleKey === '') {
            return __('Unbekannt', 'rrze-multisite-manager');
        }

        if ($wpRoles instanceof \WP_Roles && isset($wpRoles->role_names[$roleKey])) {
            return translate_user_role((string)$wpRoles->role_names[$roleKey]);
        }

        return $roleKey;
    }

    protected function sumPostCounts(\stdClass $counts): int {
        $total = 0;
        $status = '';
        $count = 0;

        foreach ((array)$counts as $status => $count) {
            if (in_array($status, ['trash', 'auto-draft', 'inherit'], true)) {
                continue;
            }

            $total += (int)$count;
        }

        if ($total === 0 && isset($counts->inherit)) {
            $total += (int)$counts->inherit;
        }

        return $total;
    }

    protected function getSiteBranding(): array {
        $customLogoId = (int)get_theme_mod('custom_logo');
        $customLogoUrl = $customLogoId > 0 ? wp_get_attachment_image_url($customLogoId, 'medium') : '';
        $siteIconUrl = function_exists('get_site_icon_url') ? get_site_icon_url(120) : '';

        if (is_string($customLogoUrl) && $customLogoUrl !== '') {
            return [
                'url' => $customLogoUrl,
                'type' => 'logo',
            ];
        }

        if (is_string($siteIconUrl) && $siteIconUrl !== '') {
            return [
                'url' => $siteIconUrl,
                'type' => 'icon',
            ];
        }

        return [
            'url' => '',
            'type' => '',
        ];
    }

    protected function normalizeRoleCounts(array $roleCounts): array {
        $admins = (int)($roleCounts['administrator'] ?? 0);
        $editors = (int)($roleCounts['editor'] ?? 0);
        $others = 0;
        $role = '';
        $count = 0;

        foreach ($roleCounts as $role => $count) {
            if (in_array($role, ['administrator', 'editor'], true)) {
                continue;
            }

            $others += (int)$count;
        }

        return [
            'admins' => $admins,
            'editors' => $editors,
            'others' => $others,
        ];
    }

    protected function countContentItems(string $postType): int {
        $counts = wp_count_posts($postType);
        $total = 0;
        $status = '';
        $count = 0;

        if (!$counts instanceof \stdClass) {
            return 0;
        }

        foreach ((array)$counts as $status => $count) {
            if (in_array($status, ['trash', 'auto-draft'], true)) {
                continue;
            }

            $total += (int)$count;
        }

        return $total;
    }

    protected function countPostsForType(string $postType): int {
        global $wpdb;

        $count = 0;

        if ($postType === '') {
            return 0;
        }

        $count = (int)$wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(ID)
                FROM {$wpdb->posts}
                WHERE post_type = %s
                AND post_status NOT IN ('trash', 'auto-draft')",
                $postType
            )
        );

        return max(0, $count);
    }

    protected function getDistinctPostTypeCounts(): array {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT post_type, COUNT(ID) AS total
            FROM {$wpdb->posts}
            WHERE post_status NOT IN ('trash', 'auto-draft')
            GROUP BY post_type"
        );
        $results = [];
        $row = null;
        $postType = '';

        foreach ($rows as $row) {
            $postType = (string)($row->post_type ?? '');

            if ($postType === '') {
                continue;
            }

            $results[$postType] = (int)($row->total ?? 0);
        }

        return $results;
    }

    protected function getSiteStorageUsage(): array {
        $megabytes = function_exists('get_space_used') ? (int)get_space_used() : 0;
        $siteLimit = (int)get_option('blog_upload_space');
        $networkLimit = (int)get_site_option('blog_upload_space');
        $maxMegabytes = 0;
        $percent = null;
        $warnLevel = '';

        if ($siteLimit > 0) {
            $maxMegabytes = $siteLimit;
        } elseif ($networkLimit > 0) {
            $maxMegabytes = $networkLimit;
        }

        if ($maxMegabytes > 0) {
            $percent = (int)round(($megabytes / $maxMegabytes) * 100);

            if ($percent > 95) {
                $warnLevel = 'critical';
            } elseif ($percent > 90) {
                $warnLevel = 'warning';
            }
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

    protected function getNetworkStorageUsage(): array {
        $siteIds = get_sites([
            'fields' => 'ids',
            'number' => 0,
        ]);
        $items = [];
        $siteId = 0;
        $storage = [];
        $usedBytes = 0;
        $maxBytes = 0;
        $hasUnlimitedSite = false;
        $totalUsedBytes = 0;
        $totalMaxBytes = 0;
        $freeBytes = 0;
        $item = [];
        $index = 0;
        $percentBase = 0;
        $mode = 'capacity';

        foreach ($siteIds as $siteId) {
            switch_to_blog((int)$siteId);
            $storage = $this->getSiteStorageUsage();
            restore_current_blog();

            $usedBytes = (int)($storage['used_bytes'] ?? 0);
            $maxBytes = (int)($storage['max_bytes'] ?? 0);

            if (!empty($storage['is_unlimited'])) {
                $hasUnlimitedSite = true;
            }

            $totalUsedBytes += max(0, $usedBytes);
            $totalMaxBytes += max(0, $maxBytes);

            if ($usedBytes <= 0) {
                continue;
            }

            $items[] = [
                'label' => $this->getSiteNameById((int)$siteId),
                'value' => $usedBytes,
                'value_label' => size_format($usedBytes),
                'site_id' => (int)$siteId,
            ];
        }

        usort($items, [self::class, 'compareUsageDistributionRows']);

        if ($hasUnlimitedSite) {
            $mode = 'usage';
            $percentBase = $totalUsedBytes;
        } else {
            $freeBytes = max(0, $totalMaxBytes - $totalUsedBytes);
            $percentBase = $totalMaxBytes;

            if ($freeBytes > 0) {
                $items[] = [
                    'label' => __('Freier Speicher', 'rrze-multisite-manager'),
                    'value' => $freeBytes,
                    'value_label' => size_format($freeBytes),
                    'accent' => 'neutral',
                ];
            }
        }

        foreach ($items as $index => $item) {
            $items[$index]['percent'] = $percentBase > 0
                ? (int)round((((int)$item['value']) / $percentBase) * 100)
                : 0;

            if (!isset($items[$index]['accent'])) {
                $items[$index]['accent'] = 'theme-' . (($index % 6) + 1);
            }
        }

        return [
            'mode' => $mode,
            'items' => $items,
            'total_used_bytes' => $totalUsedBytes,
            'total_used_label' => size_format($totalUsedBytes),
            'total_max_bytes' => $totalMaxBytes,
            'total_max_label' => $totalMaxBytes > 0 ? size_format($totalMaxBytes) : '',
            'percent' => (!$hasUnlimitedSite && $totalMaxBytes > 0)
                ? (int)round(($totalUsedBytes / $totalMaxBytes) * 100)
                : null,
            'has_unlimited_site' => $hasUnlimitedSite,
        ];
    }

    protected function getCurrentSiteOptionsOverview(): array {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT option_name, option_value, autoload
            FROM {$wpdb->options}
            ORDER BY option_name ASC"
        );
        $groups = [
            'all' => [
                'slug' => 'all',
                'label' => __('Alle Optionen', 'rrze-multisite-manager'),
                'count' => 0,
                'options' => [],
            ],
        ];
        $row = null;
        $optionName = '';
        $groupKey = '';
        $optionEntry = [];

        foreach ($rows as $row) {
            $optionName = (string)($row->option_name ?? '');

            if ($optionName === '') {
                continue;
            }

            $groupKey = $this->getOptionGroupKey($optionName);
            $optionEntry = [
                'name' => $optionName,
                'value' => $this->formatOptionValue((string)($row->option_value ?? '')),
                'autoload' => (string)($row->autoload ?? ''),
                'is_core' => $this->isWordPressCoreOption($optionName),
            ];

            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'slug' => $groupKey,
                    'label' => $this->getOptionGroupLabel($groupKey, $optionName),
                    'count' => 0,
                    'options' => [],
                ];
            }

            $groups['all']['options'][] = $optionEntry;
            $groups['all']['count']++;
            $groups[$groupKey]['options'][] = $optionEntry;
            $groups[$groupKey]['count']++;
        }

        uasort($groups, [self::class, 'compareOptionGroups']);

        return [
            'groups' => array_values($groups),
        ];
    }

    protected function getCurrentSiteTransients(): array {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT option_name, option_value
            FROM {$wpdb->options}
            WHERE option_name LIKE '\\_transient\\_%'
            OR option_name LIKE '\\_transient\\_timeout\\_%'
            ORDER BY option_name ASC"
        );
        $timeouts = [];
        $transients = [];
        $row = null;
        $optionName = '';
        $transientName = '';
        $timestamp = 0;

        foreach ($rows as $row) {
            $optionName = (string)($row->option_name ?? '');

            if (str_starts_with($optionName, '_transient_timeout_')) {
                $transientName = substr($optionName, strlen('_transient_timeout_'));
                $timeouts[$transientName] = (int)($row->option_value ?? 0);
            }
        }

        foreach ($rows as $row) {
            $optionName = (string)($row->option_name ?? '');

            if (!str_starts_with($optionName, '_transient_') || str_starts_with($optionName, '_transient_timeout_')) {
                continue;
            }

            $transientName = substr($optionName, strlen('_transient_'));
            $timestamp = (int)($timeouts[$transientName] ?? 0);

            $transients[] = [
                'name' => $transientName,
                'expires_at' => $timestamp > 0 ? $this->formatTimestamp($timestamp) : __('Kein Ablauf gesetzt', 'rrze-multisite-manager'),
            ];
        }

        return $transients;
    }

    protected function getCurrentSiteCronEvents(): array {
        $cronArray = _get_cron_array();
        $results = [];
        $timestamp = 0;
        $hooks = [];
        $hook = '';
        $events = [];
        $event = [];

        if (!is_array($cronArray)) {
            return [];
        }

        foreach ($cronArray as $timestamp => $hooks) {
            if (!is_array($hooks)) {
                continue;
            }

            foreach ($hooks as $hook => $events) {
                if (!is_array($events)) {
                    continue;
                }

                foreach ($events as $event) {
                    if (!is_array($event)) {
                        continue;
                    }

                    $results[] = [
                        'hook' => (string)$hook,
                        'next_run' => $this->formatTimestamp((int)$timestamp),
                        'next_run_timestamp' => (int)$timestamp,
                        'schedule' => !empty($event['schedule']) ? (string)$event['schedule'] : __('einmalig', 'rrze-multisite-manager'),
                    ];
                }
            }
        }

        usort($results, [self::class, 'compareCronEvents']);

        return $results;
    }

    public function deleteSiteOption(int $siteId, string $optionName): bool {
        $deleted = false;

        if ($siteId <= 0 || trim($optionName) === '') {
            return false;
        }

        switch_to_blog($siteId);
        $deleted = delete_option($optionName);
        restore_current_blog();

        return (bool)$deleted;
    }

    public function deletePostTypeEntries(int $siteId, string $postType): int {
        global $wpdb;

        $deleted = 0;
        $rows = [];
        $row = null;
        $postId = 0;
        $protectedTypes = [
            'post',
            'page',
            'attachment',
            'revision',
            'nav_menu_item',
            'custom_css',
            'customize_changeset',
            'oembed_cache',
            'user_request',
            'wp_block',
            'wp_template',
            'wp_template_part',
            'wp_global_styles',
            'wp_navigation',
            'wp_font_family',
            'wp_font_face',
            'wp_pattern_category',
        ];

        if ($siteId <= 0 || $postType === '' || in_array($postType, $protectedTypes, true)) {
            return 0;
        }

        switch_to_blog($siteId);
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ID
                FROM {$wpdb->posts}
                WHERE post_type = %s",
                $postType
            )
        );

        foreach ($rows as $row) {
            $postId = (int)($row->ID ?? 0);

            if ($postId <= 0) {
                continue;
            }

            if (wp_delete_post($postId, true)) {
                $deleted++;
            }
        }

        restore_current_blog();

        return $deleted;
    }

    public function deleteSiteOptionGroup(int $siteId, string $groupKey): int {
        global $wpdb;

        $deleted = 0;
        $rows = [];
        $row = null;
        $optionName = '';

        if ($siteId <= 0 || trim($groupKey) === '') {
            return 0;
        }

        switch_to_blog($siteId);
        $rows = $wpdb->get_results(
            "SELECT option_name
            FROM {$wpdb->options}
            ORDER BY option_name ASC"
        );

        foreach ($rows as $row) {
            $optionName = (string)($row->option_name ?? '');

            if ($optionName === '' || $this->getOptionGroupKey($optionName) !== $groupKey) {
                continue;
            }

            if (delete_option($optionName)) {
                $deleted++;
            }
        }

        restore_current_blog();

        return $deleted;
    }

    public function isWordPressCoreOptionName(string $optionName): bool {
        return $this->isWordPressCoreOption($optionName);
    }

    public function isWordPressCoreOptionGroup(string $groupKey): bool {
        return $groupKey === 'wordpress-core';
    }

    protected function getOptionGroupKey(string $optionName): string {
        $normalized = ltrim($optionName, '_');
        $segments = [];
        $firstSegment = '';

        if ($this->isWordPressCoreOption($optionName)) {
            return 'wordpress-core';
        }

        if (str_starts_with($optionName, 'theme_mods_')) {
            return 'theme_mods';
        }

        if (str_starts_with($optionName, 'widget_') || str_starts_with($optionName, 'sidebars_')) {
            return 'widgets';
        }

        $segments = preg_split('/[_-]+/', $normalized);
        $firstSegment = is_array($segments) && !empty($segments[0]) ? (string)$segments[0] : '';

        if ($firstSegment === '') {
            return 'misc';
        }

        return sanitize_key($firstSegment);
    }

    protected function getOptionGroupLabel(string $groupKey, string $optionName = ''): string {
        if ($groupKey === 'wordpress-core') {
            return __('WordPress Core', 'rrze-multisite-manager');
        }

        if ($groupKey === 'fau') {
            return 'FAU';
        }

        if ($groupKey === 'rrze') {
            return 'RRZE';
        }

        if ($groupKey === 'theme_mods') {
            return __('Theme Mods', 'rrze-multisite-manager');
        }

        if ($groupKey === 'widgets') {
            return __('Widgets', 'rrze-multisite-manager');
        }

        if ($groupKey === 'misc') {
            return __('Sonstiges', 'rrze-multisite-manager');
        }

        if ($optionName !== '') {
            return $this->getOriginalOptionPrefix($optionName);
        }

        return $groupKey;
    }

    protected function getOriginalOptionPrefix(string $optionName): string {
        $normalized = ltrim($optionName, '_');
        $segments = [];
        $firstSegment = '';

        $segments = preg_split('/[_-]+/', $normalized);
        $firstSegment = is_array($segments) && !empty($segments[0]) ? (string)$segments[0] : '';

        if ($firstSegment === '') {
            return $optionName;
        }

        return $firstSegment;
    }

    protected function isWordPressCoreOption(string $optionName): bool {
        $coreOptions = $this->getWordPressCoreOptionNames();
        $corePrefixes = [
            'dashboard_widget_options',
        ];
        $prefix = '';

        if (isset($coreOptions[$optionName])) {
            return true;
        }

        foreach ($corePrefixes as $prefix) {
            if (str_starts_with($optionName, $prefix)) {
                return true;
            }
        }

        return false;
    }

    protected function getWordPressCoreOptionNames(): array {
        $options = [
            'siteurl',
            'home',
            'blogname',
            'blogdescription',
            'users_can_register',
            'admin_email',
            'start_of_week',
            'use_balanceTags',
            'use_smilies',
            'require_name_email',
            'comments_notify',
            'posts_per_rss',
            'rss_use_excerpt',
            'mailserver_url',
            'mailserver_login',
            'mailserver_pass',
            'mailserver_port',
            'default_category',
            'default_comment_status',
            'default_ping_status',
            'default_pingback_flag',
            'posts_per_page',
            'date_format',
            'time_format',
            'links_updated_date_format',
            'comment_moderation',
            'moderation_notify',
            'permalink_structure',
            'rewrite_rules',
            'hack_file',
            'blog_charset',
            'moderation_keys',
            'active_plugins',
            'category_base',
            'ping_sites',
            'comment_max_links',
            'gmt_offset',
            'default_email_category',
            'recently_edited',
            'template',
            'stylesheet',
            'comment_registration',
            'html_type',
            'use_trackback',
            'default_role',
            'db_version',
            'uploads_use_yearmonth_folders',
            'upload_path',
            'blog_public',
            'default_link_category',
            'show_on_front',
            'tag_base',
            'show_avatars',
            'avatar_rating',
            'upload_url_path',
            'thumbnail_size_w',
            'thumbnail_size_h',
            'thumbnail_crop',
            'medium_size_w',
            'medium_size_h',
            'avatar_default',
            'large_size_w',
            'large_size_h',
            'image_default_link_type',
            'image_default_size',
            'image_default_align',
            'close_comments_for_old_posts',
            'close_comments_days_old',
            'thread_comments',
            'thread_comments_depth',
            'page_comments',
            'comments_per_page',
            'default_comments_page',
            'comment_order',
            'sticky_posts',
            'widget_categories',
            'widget_text',
            'widget_rss',
            'uninstall_plugins',
            'timezone_string',
            'page_for_posts',
            'page_on_front',
            'default_post_format',
            'link_manager_enabled',
            'finished_splitting_shared_terms',
            'site_icon',
            'medium_large_size_w',
            'medium_large_size_h',
            'wp_page_for_privacy_policy',
            'show_comments_cookies_opt_in',
            'admin_email_lifespan',
            'disallowed_keys',
            'comment_previously_approved',
            'auto_plugin_theme_update_emails',
            'auto_update_core_dev',
            'auto_update_core_minor',
            'auto_update_core_major',
            'wp_force_deactivated_plugins',
            'wp_attachment_pages_enabled',
            'wp_notes_notify',
            'initial_db_version',
            'sidebars_widgets',
            'widget_archives',
            'widget_block',
            'widget_calendar',
            'widget_categories',
            'widget_custom_html',
            'widget_media_audio',
            'widget_media_gallery',
            'widget_media_image',
            'widget_media_video',
            'widget_meta',
            'widget_nav_menu',
            'widget_pages',
            'widget_recent-comments',
            'widget_recent-posts',
            'widget_search',
            'widget_tag_cloud',
            'widget_text',
            'cron',
            'can_compress_scripts',
            'page_uris',
            'update_core',
            'update_plugins',
            'update_themes',
            'doing_cron',
            'random_seed',
            'wp_user_roles',
            'alloptions',
            'notoptions',
        ];

        return array_fill_keys($options, true);
    }

    protected function formatOptionValue(string $rawValue): string {
        $value = maybe_unserialize($rawValue);
        $formatted = '';

        if (is_array($value) || is_object($value)) {
            $formatted = wp_json_encode(
                $value,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        } elseif (is_bool($value)) {
            $formatted = $value ? 'true' : 'false';
        } elseif ($value === null) {
            $formatted = 'null';
        } else {
            $formatted = (string)$value;
        }

        if (!is_string($formatted) || $formatted === '') {
            return __('(leer)', 'rrze-multisite-manager');
        }

        if (mb_strlen($formatted) > 4000) {
            return mb_substr($formatted, 0, 4000) . "\n…";
        }

        return $formatted;
    }

    protected function getSiteStatus(int $siteId, \WP_Site $site): array {
        $status = [];

        if ((int)$site->archived === 1) {
            $status[] = [
                'label' => __('Archiviert', 'rrze-multisite-manager'),
                'accent' => 'warning',
            ];
        }

        if ((int)$site->deleted === 1) {
            $status[] = [
                'label' => __('Gelöscht', 'rrze-multisite-manager'),
                'accent' => 'danger',
            ];
        }

        if ((int)$site->spam === 1) {
            $status[] = [
                'label' => __('Gesperrt', 'rrze-multisite-manager'),
                'accent' => 'neutral',
            ];
        }

        if ((int)$site->public === 1) {
            $status[] = [
                'label' => __('Öffentlich', 'rrze-multisite-manager'),
                'accent' => 'info',
            ];
        }

        if (empty($status)) {
            $status[] = [
                'label' => __('Aktiv', 'rrze-multisite-manager'),
                'accent' => 'positive',
            ];
        }

        if ((int)$site->public === 0) {
            $status[] = [
                'label' => __('Nicht öffentlich', 'rrze-multisite-manager'),
                'accent' => 'neutral',
            ];
        }

        return $status;
    }

    protected function getSiteBuckets(): array {
        $sites = get_sites([
            'number' => 0,
        ]);
        $buckets = [
            'active' => 0,
            'archived' => 0,
            'deleted' => 0,
            'spam' => 0,
        ];
        $site = null;

        foreach ($sites as $site) {
            if (!$site instanceof \WP_Site) {
                continue;
            }

            if ((int)$site->deleted === 1) {
                $buckets['deleted']++;
                continue;
            }

            if ((int)$site->spam === 1) {
                $buckets['spam']++;
                continue;
            }

            if ((int)$site->archived === 1) {
                $buckets['archived']++;
                continue;
            }

            $buckets['active']++;
        }

        return $buckets;
    }

    protected function countLocallyUsedPlugins(array $pluginStats): int {
        $count = 0;
        $plugin = [];

        foreach ($pluginStats as $plugin) {
            if (!empty($plugin['site_count'])) {
                $count++;
            }
        }

        return $count;
    }

    protected function getThemeSiteCounts(): array {
        $siteIds = get_sites([
            'fields' => 'ids',
            'number' => 0,
        ]);
        $counts = [];
        $siteId = 0;
        $stylesheet = '';

        foreach ($siteIds as $siteId) {
            switch_to_blog((int)$siteId);
            $stylesheet = (string)get_option('stylesheet', '');

            if ($stylesheet === '') {
                $stylesheet = (string)get_option('template', '');
            }

            restore_current_blog();

            if ($stylesheet === '') {
                continue;
            }

            if (!isset($counts[$stylesheet])) {
                $counts[$stylesheet] = 0;
            }

            $counts[$stylesheet]++;
        }

        return $counts;
    }

    protected function getThemeSiteUsageMap(): array {
        $sites = get_sites([
            'number' => 0,
            'orderby' => 'domain',
            'order' => 'ASC',
        ]);
        $results = [];
        $site = null;
        $siteId = 0;
        $stylesheet = '';
        $siteName = '';
        $siteUrl = '';

        foreach ($sites as $site) {
            if (!$site instanceof \WP_Site) {
                continue;
            }

            $siteId = (int)$site->blog_id;
            $siteName = $this->getSiteName($site);
            $siteUrl = get_home_url($siteId, '/');

            switch_to_blog($siteId);
            $stylesheet = (string)get_option('stylesheet', '');

            if ($stylesheet === '') {
                $stylesheet = (string)get_option('template', '');
            }

            restore_current_blog();

            if ($stylesheet === '') {
                continue;
            }

            if (!isset($results[$stylesheet])) {
                $results[$stylesheet] = [];
            }

            $results[$stylesheet][] = [
                'id' => $siteId,
                'name' => $siteName,
                'url' => $siteUrl,
            ];
        }

        return $results;
    }

    protected function getAllowedThemes(): array {
        $allowedThemes = get_site_option('allowedthemes', []);

        if (!is_array($allowedThemes)) {
            return [];
        }

        return $allowedThemes;
    }

    protected function getThemeStatus(array $theme): array {
        $status = [];
        $tag = '';

        if (!empty($theme['network_enabled'])) {
            $status[] = [
                'label' => __('Netzwerkweit verfügbar', 'rrze-multisite-manager'),
                'accent' => 'info',
            ];
        }

        if ((int)($theme['site_count'] ?? 0) > 0) {
            $status[] = [
                'label' => __('Auf Websites aktiv', 'rrze-multisite-manager'),
                'accent' => 'active',
            ];
        } else {
            $status[] = [
                'label' => __('Nicht genutzt', 'rrze-multisite-manager'),
                'accent' => 'archive',
            ];
        }

        if (!empty($theme['is_block_theme'])) {
            $status[] = [
                'label' => __('Block Theme', 'rrze-multisite-manager'),
                'accent' => 'positive',
            ];
        }

        foreach ((array)($theme['tags'] ?? []) as $tag) {
            if (!is_string($tag) || $tag === '') {
                continue;
            }

            $status[] = [
                'label' => $tag,
                'accent' => 'neutral',
            ];
        }

        return $status;
    }

    protected static function comparePluginUsage(array $left, array $right): int {
        if ((int)$left['site_count'] === (int)$right['site_count']) {
            return strcmp((string)$left['name'], (string)$right['name']);
        }

        return (int)$right['site_count'] <=> (int)$left['site_count'];
    }

    protected static function compareThemeUsage(array $left, array $right): int {
        if ((int)$left['site_count'] === (int)$right['site_count']) {
            return strcmp((string)$left['name'], (string)$right['name']);
        }

        return (int)$right['site_count'] <=> (int)$left['site_count'];
    }

    protected function compareSitesByActivity(array $left, array $right): int {
        if ((int)$left['last_updated_timestamp'] === (int)$right['last_updated_timestamp']) {
            return strcmp((string)$left['name'], (string)$right['name']);
        }

        return (int)$right['last_updated_timestamp'] <=> (int)$left['last_updated_timestamp'];
    }

    protected static function compareDetailedPlugins(array $left, array $right): int {
        if (!empty($left['network_active']) && empty($right['network_active'])) {
            return -1;
        }

        if (empty($left['network_active']) && !empty($right['network_active'])) {
            return 1;
        }

        return strcmp((string)($left['name'] ?? ''), (string)($right['name'] ?? ''));
    }

    protected static function compareDetailedUsers(array $left, array $right): int {
        $priority = [
            'administrator' => 1,
            'editor' => 2,
        ];
        $leftRole = (string)($left['role_key'] ?? '');
        $rightRole = (string)($right['role_key'] ?? '');
        $leftPriority = $priority[$leftRole] ?? 3;
        $rightPriority = $priority[$rightRole] ?? 3;

        if ($leftPriority !== $rightPriority) {
            return $leftPriority <=> $rightPriority;
        }

        return strcmp((string)($left['name'] ?? $left['username'] ?? ''), (string)($right['name'] ?? $right['username'] ?? ''));
    }

    protected static function comparePluginActiveSites(array $left, array $right): int {
        return strcmp((string)($left['name'] ?? ''), (string)($right['name'] ?? ''));
    }

    protected static function compareDetailedContentTypes(array $left, array $right): int {
        $priority = [
            'post' => 1,
            'page' => 2,
            'attachment' => 3,
        ];
        $leftSlug = (string)($left['slug'] ?? '');
        $rightSlug = (string)($right['slug'] ?? '');
        $leftPriority = $priority[$leftSlug] ?? 10;
        $rightPriority = $priority[$rightSlug] ?? 10;

        if ($leftPriority !== $rightPriority) {
            return $leftPriority <=> $rightPriority;
        }

        return strcmp((string)($left['label'] ?? ''), (string)($right['label'] ?? ''));
    }

    protected static function compareOptionGroups(array $left, array $right): int {
        $priority = [
            'wordpress-core' => 1,
            'fau' => 2,
            'rrze' => 3,
            'all' => 4,
        ];
        $leftSlug = (string)($left['slug'] ?? '');
        $rightSlug = (string)($right['slug'] ?? '');
        $leftPriority = $priority[$leftSlug] ?? 100;
        $rightPriority = $priority[$rightSlug] ?? 100;

        if ($leftPriority !== $rightPriority) {
            return $leftPriority <=> $rightPriority;
        }

        return strcmp((string)($left['label'] ?? ''), (string)($right['label'] ?? ''));
    }

    protected static function comparePluginOptions(array $left, array $right): int {
        if ((string)($left['scope'] ?? '') === (string)($right['scope'] ?? '')) {
            return strcmp((string)($left['name'] ?? ''), (string)($right['name'] ?? ''));
        }

        return strcmp((string)($left['scope'] ?? ''), (string)($right['scope'] ?? ''));
    }

    protected static function comparePluginNamedRows(array $left, array $right): int {
        return strcmp((string)($left['name'] ?? ''), (string)($right['name'] ?? ''));
    }

    protected static function comparePluginPostTypeRows(array $left, array $right): int {
        if ((string)($left['type'] ?? '') === (string)($right['type'] ?? '')) {
            return strcmp((string)($left['label'] ?? $left['slug'] ?? ''), (string)($right['label'] ?? $right['slug'] ?? ''));
        }

        return strcmp((string)($left['type'] ?? ''), (string)($right['type'] ?? ''));
    }

    protected static function compareCronEvents(array $left, array $right): int {
        if ((int)($left['next_run_timestamp'] ?? 0) === (int)($right['next_run_timestamp'] ?? 0)) {
            return strcmp((string)($left['hook'] ?? ''), (string)($right['hook'] ?? ''));
        }

        return (int)($left['next_run_timestamp'] ?? 0) <=> (int)($right['next_run_timestamp'] ?? 0);
    }

    protected static function compareUsageDistributionRows(array $left, array $right): int {
        $leftValue = (int)($left['value'] ?? 0);
        $rightValue = (int)($right['value'] ?? 0);

        if ($leftValue === $rightValue) {
            return strcmp((string)($left['label'] ?? ''), (string)($right['label'] ?? ''));
        }

        return $rightValue <=> $leftValue;
    }

    protected static function isUnusedPlugin(array $plugin): bool {
        return (int)($plugin['site_count'] ?? 0) === 0;
    }

    protected static function isUnusedTheme(array $theme): bool {
        return (int)($theme['site_count'] ?? 0) === 0;
    }

    protected function isCompleteDashboardData(array $data): bool {
        $requiredKeys = [
            'summary',
            'site_table_default_limit',
            'status_distribution',
            'network_storage_usage',
            'recent_sites',
            'site_overview',
            'archived_sites',
            'blocked_sites',
            'deleted_sites',
            'themes',
            'theme_usage',
            'editor_usage',
            'plugin_usage',
            'recently_updated_sites',
            'inactive_sites',
        ];
        $key = '';

        foreach ($requiredKeys as $key) {
            if (!array_key_exists($key, $data)) {
                return false;
            }
        }

        return true;
    }

    protected function getCacheKey(): string {
        return self::CACHE_KEY . (string)get_current_network_id();
    }

    protected function getActivitySiteLimit(): int {
        if ($this->settings instanceof Settings) {
            return max(1, (int)$this->settings->getOption('dashboard', 'activity_site_limit', 10));
        }

        return 10;
    }

    protected function getInactiveHighlightMonths(): int {
        if ($this->settings instanceof Settings) {
            return max(1, (int)$this->settings->getOption('dashboard', 'inactive_highlight_months', 6));
        }

        return 6;
    }

    protected function getInactiveThresholdTimestamp(): int {
        return strtotime('-' . $this->getInactiveHighlightMonths() . ' months');
    }

    protected function getSiteTableMaxRows(): int {
        return self::SITE_TABLE_MAX_ROWS;
    }

    protected function parseDateToTimestamp(string $dateValue, bool $isGmt = false): int {
        if ($dateValue === '' || $dateValue === '0000-00-00 00:00:00') {
            return 0;
        }

        if ($isGmt) {
            return (int)strtotime($dateValue . ' GMT');
        }

        return (int)strtotime($dateValue);
    }
}
