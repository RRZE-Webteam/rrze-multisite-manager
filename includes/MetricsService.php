<?php

namespace RRZE\MultisiteManager;

defined('ABSPATH') || exit;

class MetricsService {
    protected const CACHE_KEY = 'rrze_multisite_manager_dashboard_metrics_v5_';
    protected const SITE_TABLE_MAX_ROWS = 100;
    protected ?Settings $settings;
    protected Config $config;

    public function __construct(?Settings $settings = null, ?Config $config = null) {
        $this->settings = $settings;
        $this->config = $config ?? new Config();
    }

    public function getDashboardData(): array {
        $cached = get_site_transient($this->getCacheKey());

        if (is_array($cached) && $this->isCompleteDashboardData($cached)) {
            return $cached;
        }

        $data = [
            'summary' => $this->getSummary(),
            'site_table_default_limit' => $this->getActivitySiteLimit(),
            'status_distribution' => $this->getStatusDistribution(),
            'recent_sites' => $this->getRecentSites(),
            'site_overview' => $this->getSiteOverview(),
            'archived_sites' => $this->getSitesByFlag('archived', 1),
            'blocked_sites' => $this->getSitesByFlag('spam', 1),
            'deleted_sites' => $this->getSitesByFlag('deleted', 1),
            'monthly_growth' => $this->getMonthlyGrowth(),
            'themes' => $this->getThemes(),
            'theme_usage' => $this->getThemeUsage(),
            'editor_usage' => $this->getEditorUsage(),
            'plugin_usage' => $this->getPluginUsage(),
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
                'label' => __('Nicht öffentlich', 'rrze-multisite-manager'),
                'value' => (int)$siteBuckets['private'],
                'accent' => 'neutral',
            ],
            [
                'label' => __('Archiviert', 'rrze-multisite-manager'),
                'value' => (int)$siteBuckets['archived'],
                'accent' => 'warning',
            ],
            [
                'label' => __('Zum Löschen markiert', 'rrze-multisite-manager'),
                'value' => (int)$siteBuckets['deleted'],
                'accent' => 'danger',
            ],
            [
                'label' => __('Gesperrt', 'rrze-multisite-manager'),
                'value' => (int)$siteBuckets['spam'],
                'accent' => 'info',
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

        foreach ($siteIds as $siteId) {
            $activePlugins = get_blog_option((int)$siteId, 'active_plugins', []);

            if (!is_array($activePlugins)) {
                continue;
            }

            foreach ($activePlugins as $pluginFile) {
                if (!isset($pluginStats[$pluginFile])) {
                    $pluginStats[$pluginFile] = [
                        'file' => $pluginFile,
                        'site_count' => 0,
                    ];
                }

                $pluginStats[$pluginFile]['site_count']++;
            }
        }

        foreach ($networkActivePlugins as $pluginFile => $timestamp) {
            if (!isset($pluginStats[$pluginFile])) {
                $pluginStats[$pluginFile] = [
                    'file' => $pluginFile,
                    'site_count' => 0,
                ];
            }
        }

        foreach ($pluginStats as $pluginFile => $pluginData) {
            $pluginStats[$pluginFile]['name'] = $availablePlugins[$pluginFile]['Name'] ?? $pluginFile;
            $pluginStats[$pluginFile]['version'] = $availablePlugins[$pluginFile]['Version'] ?? 'n/a';
            $pluginStats[$pluginFile]['network_active'] = isset($networkActivePlugins[$pluginFile]);
        }

        uasort($pluginStats, [self::class, 'comparePluginUsage']);

        return [
            'summary' => [
                'available_plugins' => count($availablePlugins),
                'network_active_plugins' => count($networkActivePlugins),
                'locally_used_plugins' => $this->countLocallyUsedPlugins($pluginStats),
            ],
            'top_plugins' => array_slice(array_values($pluginStats), 0, 8),
        ];
    }

    protected function getThemes(): array {
        $themes = wp_get_themes();
        $allowedThemes = $this->getAllowedThemes();
        $siteCounts = $this->getThemeSiteCounts();
        $results = [];
        $stylesheet = '';
        $theme = null;

        foreach ($themes as $stylesheet => $theme) {
            $results[] = [
                'stylesheet' => $stylesheet,
                'name' => $theme->get('Name') ?: $stylesheet,
                'version' => $theme->get('Version') ?: 'n/a',
                'site_count' => (int)($siteCounts[$stylesheet] ?? 0),
                'network_enabled' => isset($allowedThemes[$stylesheet]),
            ];
        }

        usort($results, [self::class, 'compareThemeUsage']);

        return $results;
    }

    protected function getThemeUsage(): array {
        $siteCounts = $this->getThemeSiteCounts();
        $themes = wp_get_themes();
        $results = [];
        $index = 0;
        $total = array_sum($siteCounts);
        $stylesheet = '';
        $value = 0;

        if ($total === 0) {
            return [];
        }

        arsort($siteCounts);

        foreach ($siteCounts as $stylesheet => $value) {
            if ($value <= 0) {
                continue;
            }

            $index++;
            $results[] = [
                'label' => isset($themes[$stylesheet]) ? ($themes[$stylesheet]->get('Name') ?: $stylesheet) : $stylesheet,
                'value' => (int)$value,
                'percent' => (int)round((((int)$value) / $total) * 100),
                'accent' => 'theme-' . (($index - 1) % 6 + 1),
            ];
        }

        return array_slice($results, 0, 6);
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
            'max_label' => $maxMegabytes > 0 ? size_format($maxMegabytes * MB_IN_BYTES) : '',
            'percent' => $percent,
            'warn_level' => $warnLevel,
        ];
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
            'private' => 0,
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

            if ((int)$site->public === 0) {
                $buckets['private']++;
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
            $stylesheet = (string)get_blog_option((int)$siteId, 'stylesheet', '');

            if ($stylesheet === '') {
                $stylesheet = (string)get_blog_option((int)$siteId, 'template', '');
            }

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

    protected function getAllowedThemes(): array {
        $allowedThemes = get_site_option('allowedthemes', []);

        if (!is_array($allowedThemes)) {
            return [];
        }

        return $allowedThemes;
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

    protected function isCompleteDashboardData(array $data): bool {
        $requiredKeys = [
            'summary',
            'site_table_default_limit',
            'status_distribution',
            'recent_sites',
            'site_overview',
            'archived_sites',
            'blocked_sites',
            'deleted_sites',
            'monthly_growth',
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
