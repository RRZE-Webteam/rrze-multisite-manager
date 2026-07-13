<?php

namespace RRZE\MultisiteManager;

defined('ABSPATH') || exit;

class MetricsService {
    use MetricsServiceSiteTrait;
    use MetricsServicePluginTrait;
    use MetricsServiceThemeTrait;
    use MetricsServiceEnvironmentTrait;

    protected const CACHE_KEY = 'rrze_multisite_manager_dashboard_metrics_v7_';
    protected const SITE_TABLE_MAX_ROWS = 100;
    protected const DASHBOARD_REFRESH_HOOK = 'rrze_msm_refresh_dashboard_metrics';
    protected const DASHBOARD_LOCK_KEY = 'rrze_msm_dashboard_metrics_refresh_lock';
    protected const DASHBOARD_BATCH_OFFSET_OPTION = 'rrze_msm_dashboard_metrics_batch_offset';
    protected const DASHBOARD_BATCH_TOTAL_OPTION = 'rrze_msm_dashboard_metrics_batch_total';
    protected const DASHBOARD_BATCH_STATE_OPTION = 'rrze_msm_dashboard_metrics_batch_state';
    protected const DASHBOARD_BATCH_SIZE = 25;
    protected const DETAIL_CACHE_VERSION_OPTION = 'rrze_msm_detail_cache_version';
    protected const SITE_DETAIL_CACHE_VERSION_META = 'rrze_msm_site_detail_cache_version';
    protected const DETAIL_CACHE_TTL = 900;
    protected const DETAIL_SECTION_MAX_ROWS = 250;
    protected const STORAGE_LARGEST_FILES_LIMIT = 200;
    protected const STORAGE_ORPHAN_FILES_LIMIT = 500;
    protected const DASHBOARD_LOCK_TTL = 900;
    protected const DASHBOARD_ACTIVE_SITE_PREVIEW_LIMIT = 100;
    protected ?Settings $settings;
    protected Config $config;
    protected array $siteNameCache = [];
    protected array $siteAdminEmailCache = [];
    protected array $currentSiteAssetUsageIndexCache = [];
    protected ?array $themeSiteAggregate = null;
    protected ?int $dashboardSiteCount = null;

    public function __construct(?Settings $settings = null, ?Config $config = null) {
        $this->settings = $settings;
        $this->config = $config ?? new Config();
    }

    public function onLoaded(): void {
        add_action(self::DASHBOARD_REFRESH_HOOK, [$this, 'handleScheduledDashboardRefresh']);
        $this->registerInvalidationHooks();
    }

    public function getDashboardData(): array {
        $cached = $this->getStoredDashboardCache();

        if ($this->isUsableDashboardCache($cached)) {
            if ($this->shouldRefreshDashboardCache($cached)) {
                $this->scheduleDashboardRefresh();
            }

            return (array)($cached['data'] ?? []);
        }

        $this->scheduleDashboardRefresh(5);

        return $this->getEmptyDashboardDataPayload();
    }

    public function rebuildDashboardData(bool $force = false): array {
        $cached = $this->getStoredDashboardCache();
        $siteOverview = [];
        $networkStorageUsage = [];
        $data = [];

        if (!$force && $this->isDashboardRefreshLocked()) {
            if ($this->isUsableDashboardCache($cached)) {
                return (array)($cached['data'] ?? []);
            }
        }

        if (!$this->acquireDashboardRefreshLock()) {
            if ($this->isUsableDashboardCache($cached)) {
                return (array)($cached['data'] ?? []);
            }
        }

        $siteOverview = $this->getSiteOverview();
        $networkStorageUsage = $this->getNetworkStorageUsage();

        $data = $this->buildDashboardDataPayload($siteOverview, $networkStorageUsage);

        update_site_option(
            $this->getCacheKey(),
            [
                'data' => $data,
                'generated_at' => time(),
                'started_at' => time(),
                'duration_seconds' => 0,
                'dirty' => false,
            ]
        );
        $this->releaseDashboardRefreshLock();

        return $data;
    }

    public function clearCache(): void {
        $this->markAllCachesDirty();
        delete_site_transient('rrze_multisite_manager_dashboard_metrics_v1_' . (string)get_current_network_id());
        delete_site_transient('rrze_multisite_manager_dashboard_metrics_v2_' . (string)get_current_network_id());
        delete_site_transient('rrze_multisite_manager_dashboard_metrics_v3_' . (string)get_current_network_id());
        delete_site_transient('rrze_multisite_manager_dashboard_metrics_v4_' . (string)get_current_network_id());
        delete_site_transient('rrze_multisite_manager_dashboard_metrics_v5_' . (string)get_current_network_id());
        delete_site_transient('rrze_multisite_manager_dashboard_metrics_v6_' . (string)get_current_network_id());
    }

    public function handleScheduledDashboardRefresh(): void {
        $this->runDashboardRefreshBatch();
    }

    public function invalidateCaches(...$args): void {
        $this->markAllCachesDirty();
    }

    public function invalidateCurrentSiteCaches(...$args): void {
        $siteId = get_current_blog_id();

        if ($siteId > 0) {
            $this->invalidateSiteDetailCaches($siteId);
        }

        $this->markDashboardCacheDirty();
    }

    public function invalidateCurrentSiteAndGlobalCaches(...$args): void {
        $siteId = get_current_blog_id();

        if ($siteId > 0) {
            $this->invalidateSiteDetailCaches($siteId);
        }

        $this->markDashboardCacheDirty();
        $this->bumpDetailCacheVersion();
    }

    public function invalidateSiteCachesFromHook(int $siteId, ...$args): void {
        if ($siteId > 0) {
            $this->invalidateSiteDetailCaches($siteId);
        }

        $this->markDashboardCacheDirty();
    }

    public function invalidateSiteAndGlobalCachesFromHook(int $siteId, ...$args): void {
        if ($siteId > 0) {
            $this->invalidateSiteDetailCaches($siteId);
        }

        $this->markDashboardCacheDirty();
        $this->bumpDetailCacheVersion();
    }

    public function invalidateUserSiteCachesFromHook(int $userId, string $role, int $siteId): void {
        if ($siteId > 0) {
            $this->invalidateSiteDetailCaches($siteId);
        }

        $this->markDashboardCacheDirty();
    }

    public function invalidateUserRemovalSiteCachesFromHook(int $userId, int $siteId): void {
        if ($siteId > 0) {
            $this->invalidateSiteDetailCaches($siteId);
        }

        $this->markDashboardCacheDirty();
    }

    public function invalidateDashboardCacheOnly(...$args): void {
        $this->markDashboardCacheDirty();
    }

    public function startDashboardRefreshRun(bool $runImmediately = true): void {
        if ($this->isDashboardRefreshLocked()) {
            $this->scheduleDashboardRefresh(5);
            return;
        }

        $this->markDashboardCacheDirty(false);
        $this->resetDashboardRefreshBatchState();

        if ($runImmediately) {
            $this->runDashboardRefreshBatch(true);
            return;
        }

        $this->scheduleDashboardRefresh(5);
    }

    public function resetDashboardRefreshState(): void {
        $this->resetDashboardRefreshBatchState();
        $this->releaseDashboardRefreshLock();
    }

    protected function buildDashboardDataPayload(array $siteOverview, array $networkStorageUsage): array {
        return [
            'summary' => $this->getSummary($networkStorageUsage),
            'site_table_default_limit' => $this->getActivitySiteLimit(),
            'status_distribution' => $this->getStatusDistribution(),
            'operational_status_distribution' => $this->getOperationalStatusDistribution(),
            'network_storage_usage' => $networkStorageUsage,
            'recent_sites' => $this->getRecentSites(),
            'site_overview' => $siteOverview,
            'archived_sites' => $this->filterFormattedSitesByFlag($siteOverview, 'is_archived'),
            'blocked_sites' => $this->filterFormattedSitesByFlag($siteOverview, 'is_spam'),
            'deleted_sites' => $this->filterFormattedSitesByFlag($siteOverview, 'is_deleted'),
            'problem_sites' => $this->getProblemSites($siteOverview),
            'new_monitoring_alerts' => $this->getNewMonitoringAlerts($siteOverview),
            'provisioning_sites' => $this->filterFormattedSitesByOperationalStatus($siteOverview, 'provisioning'),
            'dns_missing_sites' => $this->filterFormattedSitesByOperationalStatus($siteOverview, 'dns_missing'),
            'unreachable_sites' => $this->filterFormattedSitesByOperationalStatus($siteOverview, 'unreachable'),
            'themes' => $this->getThemes(),
            'theme_usage' => $this->getThemeUsage(),
            'editor_usage' => $this->getEditorUsage(),
            'plugin_usage' => $this->getPluginUsage(),
            'inactive_themes' => $this->getInactiveThemes(),
            'recently_updated_sites' => $this->getRecentlyUpdatedSites(),
            'inactive_sites' => $this->getInactiveSites(),
        ];
    }

    protected function buildDashboardDataPayloadFromBatchState(array $state): array {
        $siteOverview = is_array($state['site_overview'] ?? null) ? (array)$state['site_overview'] : [];
        $networkStorageUsage = $this->finalizeDashboardBatchStorageUsage((array)($state['network_storage_usage'] ?? []));
        $pluginUsage = $this->finalizePluginUsageStats(
            (array)($state['plugin_usage']['stats'] ?? []),
            (array)($state['plugin_usage']['missing_plugins'] ?? []),
            (int)($state['plugin_usage']['total_sites'] ?? 0)
        );
        $editorUsage = $this->finalizeDashboardBatchEditorUsage((array)($state['editor_usage'] ?? []));
        $recentSites = array_slice($siteOverview, 0, $this->getSiteTableMaxRows());
        $recentlyUpdatedSites = $this->sortFormattedSitesByActivity($siteOverview, 'DESC');
        $inactiveSites = $this->sortFormattedSitesByActivity($siteOverview, 'ASC');

        $this->themeSiteAggregate = is_array($state['theme_aggregate'] ?? null)
            ? (array)$state['theme_aggregate']
            : [
                'counts' => [],
                'usage_map' => [],
                'truncated' => [],
            ];

        return [
            'summary' => $this->getSummary($networkStorageUsage),
            'site_table_default_limit' => $this->getActivitySiteLimit(),
            'status_distribution' => $this->getStatusDistribution(),
            'operational_status_distribution' => $this->getOperationalStatusDistribution(),
            'network_storage_usage' => $networkStorageUsage,
            'recent_sites' => $recentSites,
            'site_overview' => $siteOverview,
            'archived_sites' => $this->filterFormattedSitesByFlag($siteOverview, 'is_archived'),
            'blocked_sites' => $this->filterFormattedSitesByFlag($siteOverview, 'is_spam'),
            'deleted_sites' => $this->filterFormattedSitesByFlag($siteOverview, 'is_deleted'),
            'problem_sites' => $this->getProblemSites($siteOverview),
            'new_monitoring_alerts' => $this->getNewMonitoringAlerts($siteOverview),
            'provisioning_sites' => $this->filterFormattedSitesByOperationalStatus($siteOverview, 'provisioning'),
            'dns_missing_sites' => $this->filterFormattedSitesByOperationalStatus($siteOverview, 'dns_missing'),
            'unreachable_sites' => $this->filterFormattedSitesByOperationalStatus($siteOverview, 'unreachable'),
            'themes' => $this->getThemes(),
            'theme_usage' => $this->getThemeUsage(),
            'editor_usage' => $editorUsage,
            'plugin_usage' => $pluginUsage,
            'inactive_themes' => $this->getInactiveThemes(),
            'recently_updated_sites' => array_slice($recentlyUpdatedSites, 0, $this->getSiteTableMaxRows()),
            'inactive_sites' => array_slice($inactiveSites, 0, $this->getSiteTableMaxRows()),
        ];
    }

    public function getDashboardDataStatus(): array {
        $cached = $this->getStoredDashboardCache();
        $hasData = $this->isUsableDashboardCache($cached);
        $needsRefresh = $this->shouldRefreshDashboardCache($cached);
        $nextRunTimestamp = wp_next_scheduled(self::DASHBOARD_REFRESH_HOOK);
        $siteCount = 0;
        $batchOffset = (int)get_site_option(self::DASHBOARD_BATCH_OFFSET_OPTION, 0);
        $batchTotal = (int)get_site_option(self::DASHBOARD_BATCH_TOTAL_OPTION, 0);
        $batchState = $this->getDashboardRefreshBatchState();
        $startedAtTimestamp = (int)($batchState['started_at'] ?? 0);
        $isRunning = $this->isDashboardRefreshLocked();
        $currentDurationSeconds = ($isRunning && $startedAtTimestamp > 0)
            ? max(0, time() - $startedAtTimestamp)
            : 0;
        $checkedSites = $batchTotal > 0 ? min($batchTotal, $batchOffset) : 0;
        $remainingSites = $batchTotal > 0 ? max(0, $batchTotal - $checkedSites) : 0;
        $progressPercent = ($batchTotal > 0 && $checkedSites > 0)
            ? (int)round(($checkedSites / $batchTotal) * 100)
            : 0;
        $isStale = $this->isDashboardRefreshStale($isRunning, $batchTotal, $checkedSites, $nextRunTimestamp, $currentDurationSeconds);

        if ($hasData) {
            $siteCount = count((array)($cached['data']['site_overview'] ?? []));
        }

        return [
            'has_data' => $hasData,
            'is_dirty' => !empty($cached['dirty']),
            'needs_refresh' => $needsRefresh,
            'is_running' => $isRunning,
            'last_run_timestamp' => (int)($cached['generated_at'] ?? 0),
            'next_run_timestamp' => $nextRunTimestamp ? (int)$nextRunTimestamp : 0,
            'last_site_count' => $siteCount,
            'batch_offset' => $batchOffset,
            'batch_total' => $batchTotal,
            'checked_sites' => $checkedSites,
            'remaining_sites' => $remainingSites,
            'progress_percent' => $progressPercent,
            'started_at_timestamp' => $startedAtTimestamp,
            'current_duration_seconds' => $currentDurationSeconds,
            'last_duration_seconds' => (int)($cached['duration_seconds'] ?? 0),
            'is_stale' => $isStale,
        ];
    }

    public function getProcessesOverview(): array {
        $status = $this->getDashboardDataStatus();

        return [
            [
                'id' => 'dashboard-metrics',
                'title' => __('Dashboard-Metriken', 'rrze-multisite-manager'),
                'description' => __('Berechnet die aggregierten Netzwerkkennzahlen für Dashboard sowie Website-, Plugin- und Theme-Übersichten.', 'rrze-multisite-manager'),
                'interval_hours' => round($this->getMetricsRefreshIntervalMinutes() / 60, 2),
                'interval_label' => sprintf(
                    /* translators: %d: metrics refresh interval in minutes. */
                    __('Alle %d Minuten', 'rrze-multisite-manager'),
                    $this->getMetricsRefreshIntervalMinutes()
                ),
                'last_run' => $status['last_run_timestamp'] > 0 ? gmdate('Y-m-d H:i:s', (int)$status['last_run_timestamp']) : '',
                'last_site_count' => (int)$status['last_site_count'],
                'next_run_timestamp' => (int)($status['next_run_timestamp'] ?? 0),
                'is_running' => !empty($status['is_running']),
                'batch_offset' => (int)($status['batch_offset'] ?? 0),
                'batch_total' => (int)($status['batch_total'] ?? 0),
                'checked_sites' => (int)($status['checked_sites'] ?? 0),
                'remaining_sites' => (int)($status['remaining_sites'] ?? 0),
                'progress_percent' => (int)($status['progress_percent'] ?? 0),
                'batch_size' => self::DASHBOARD_BATCH_SIZE,
                'current_duration_seconds' => (int)($status['current_duration_seconds'] ?? 0),
                'last_duration_seconds' => (int)($status['last_duration_seconds'] ?? 0),
                'run_state' => [
                    'has_data' => !empty($status['has_data']),
                    'needs_refresh' => !empty($status['needs_refresh']),
                    'is_dirty' => !empty($status['is_dirty']),
                ],
            ],
        ];
    }

    protected function runDashboardRefreshBatch(bool $manual = false): void {
        $offset = (int)get_site_option(self::DASHBOARD_BATCH_OFFSET_OPTION, 0);
        $totalSites = (int)get_site_option(self::DASHBOARD_BATCH_TOTAL_OPTION, 0);
        $state = $this->getDashboardRefreshBatchState();
        $siteIds = [];
        $siteId = 0;
        $nextOffset = 0;
        $site = null;
        $formattedSites = [];
        $formattedSite = [];
        $siteName = '';
        $siteUrl = '';
        $stylesheet = '';
        $storage = [];
        $activePlugins = [];
        $sitePluginFiles = [];
        $networkActivePlugins = (array)get_site_option('active_sitewide_plugins', []);

        if (!$this->acquireDashboardRefreshLock()) {
            return;
        }

        if ($offset <= 0 || $totalSites <= 0 || empty($state)) {
            $totalSites = (int)get_sites([
                'count' => true,
                'number' => 1,
            ]);
            update_site_option(self::DASHBOARD_BATCH_TOTAL_OPTION, $totalSites);
            $state = $this->getInitialDashboardRefreshBatchState();
        }

        $siteIds = get_sites([
            'fields' => 'ids',
            'number' => self::DASHBOARD_BATCH_SIZE,
            'offset' => max(0, $offset),
            'orderby' => 'registered',
            'order' => 'DESC',
        ]);

        foreach ($siteIds as $siteId) {
            $siteId = (int)$siteId;

            if ($siteId <= 0) {
                continue;
            }

            $site = get_site($siteId);

            if (!$site instanceof \WP_Site) {
                continue;
            }

            $formattedSites = $this->formatSites([$site], true);

            if (empty($formattedSites[0]) || !is_array($formattedSites[0])) {
                continue;
            }

            $formattedSite = $formattedSites[0];
            $state['site_overview'][] = $formattedSite;

            $siteName = (string)($formattedSite['name'] ?? $this->getSiteName($site));
            $siteUrl = (string)($formattedSite['url'] ?? get_home_url($siteId, '/'));
            $storage = is_array($formattedSite['storage'] ?? null) ? $formattedSite['storage'] : [];
            $stylesheet = (string)get_blog_option($siteId, 'stylesheet', '');

            if ($stylesheet === '') {
                $stylesheet = (string)get_blog_option($siteId, 'template', '');
            }

            $activePlugins = get_blog_option($siteId, 'active_plugins', []);

            if (!is_array($activePlugins)) {
                $activePlugins = [];
            }

            $sitePluginFiles = array_unique(
                array_merge(
                    array_keys($networkActivePlugins),
                    array_values(array_filter($activePlugins, 'is_string'))
                )
            );

            $this->accumulateDashboardBatchStorageUsage($state['network_storage_usage'], $siteId, $siteName, $storage);
            $this->accumulateDashboardBatchThemeUsage($state['theme_aggregate'], $siteId, $siteName, $siteUrl, $stylesheet);
            $this->accumulateDashboardBatchPluginUsage($state['plugin_usage'], $siteId, $siteName, $siteUrl, $sitePluginFiles, $networkActivePlugins);
            $this->accumulateDashboardBatchEditorUsage($state['editor_usage'], $sitePluginFiles, $networkActivePlugins);
        }

        $this->saveDashboardRefreshBatchState($state);
        $nextOffset = $offset + count($siteIds);

        if (empty($siteIds) || $nextOffset >= $totalSites) {
            $this->finalizeDashboardRefreshBatchState($state);
            $this->resetDashboardRefreshBatchState();
            $this->releaseDashboardRefreshLock();
            return;
        }

        update_site_option(self::DASHBOARD_BATCH_OFFSET_OPTION, $nextOffset);
        update_site_option(self::DASHBOARD_BATCH_TOTAL_OPTION, $totalSites);
        $this->releaseDashboardRefreshLock();
        $this->scheduleDashboardRefresh($manual ? 5 : 20);
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

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Aggregated dashboard metric query; result is persisted in the dashboard cache.
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


    protected function analyzePluginCode(string $pluginFile): array {
        $files = $this->getPluginAnalysisFiles($pluginFile);
        $globalSymbols = $this->getSourceStringSymbolsFromFiles($files);
        $shortcodes = [];
        $blocks = [];
        $patterns = [];
        $customPostTypes = [];
        $taxonomies = [];
        $imageSizes = [];
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
                $customPostTypes = $this->mergePostTypeRows($customPostTypes, $this->extractCustomPostTypesFromSource($source, $globalSymbols));
                $taxonomies = $this->mergeTaxonomyRows($taxonomies, $this->extractTaxonomiesFromSource($source, $globalSymbols));
                $imageSizes = $this->mergeImageSizeRows($imageSizes, $this->extractImageSizesFromSource($source, $globalSymbols));
                $hooks = $this->mergeKeyedRows($hooks, $this->extractProvidedHooksFromSource($source));
            }

            if (strtolower((string)basename($filePath)) === 'block.json') {
                $blocks = $this->mergeKeyedRows($blocks, $this->extractBlocksFromMetadataFile($filePath));
            }
        }

        sort($shortcodes, SORT_NATURAL | SORT_FLAG_CASE);
        usort($blocks, [self::class, 'comparePluginNamedRows']);
        sort($patterns, SORT_NATURAL | SORT_FLAG_CASE);
        usort($customPostTypes, [self::class, 'comparePluginPostTypeRows']);
        usort($taxonomies, [self::class, 'comparePluginTaxonomyRows']);
        usort($imageSizes, [self::class, 'compareImageSizeRows']);
        usort($hooks, [self::class, 'comparePluginNamedRows']);

        return [
            'shortcodes' => $shortcodes,
            'blocks' => $blocks,
            'block_patterns' => $patterns,
            'custom_post_types' => $customPostTypes,
            'taxonomies' => $taxonomies,
            'image_sizes' => $imageSizes,
            'provided_hooks' => $hooks,
        ];
    }

    protected function analyzeThemeCode(string $stylesheet): array {
        $files = $this->getThemeAnalysisFiles($stylesheet);
        $shortcodes = [];
        $blocks = [];
        $patterns = [];
        $imageSizes = [];
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
                $imageSizes = $this->mergeImageSizeRows($imageSizes, $this->extractImageSizesFromSource($source));
                $hooks = $this->mergeKeyedRows($hooks, $this->extractProvidedHooksFromSource($source));
            }

            if (strtolower((string)basename($filePath)) === 'block.json') {
                $blocks = $this->mergeKeyedRows($blocks, $this->extractBlocksFromMetadataFile($filePath));
            }
        }

        sort($shortcodes, SORT_NATURAL | SORT_FLAG_CASE);
        usort($blocks, [self::class, 'comparePluginNamedRows']);
        sort($patterns, SORT_NATURAL | SORT_FLAG_CASE);
        usort($imageSizes, [self::class, 'compareImageSizeRows']);
        usort($hooks, [self::class, 'comparePluginNamedRows']);

        return [
            'shortcodes' => $shortcodes,
            'blocks' => $blocks,
            'block_patterns' => $patterns,
            'image_sizes' => $imageSizes,
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

    protected function extractCustomPostTypesFromSource(string $source, array $sharedSymbols = []): array {
        $matches = [];
        $results = [];
        $index = 0;
        $token = '';
        $slug = '';
        $context = '';
        $label = '';
        $type = '';
        $symbols = array_merge($sharedSymbols, $this->getSourceStringSymbols($source));
        $symbols = $this->resolveGetterBackedSourceSymbols($source, $symbols);

        if (!preg_match_all('/(?<!function )\bregister_post_type\s*\(\s*([^,\)]+)\s*,/m', $source, $matches, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        for ($index = 0; $index < count($matches[1]); $index++) {
            $token = trim((string)($matches[1][$index][0] ?? ''));
            $context = substr($source, (int)($matches[0][$index][1] ?? 0), 1600);
            $slug = $this->resolveSourceStringToken($token, $symbols);
            $label = $this->extractCustomPostTypeLabel($context, __('Dynamisch registrierter Post Type', 'rrze-multisite-manager'));
            $type = $this->extractCustomPostTypeDisplayType($context);

            if ($slug === '') {
                $results['__dynamic_cpt_' . $index] = [
                    'slug' => __('Nicht statisch auflösbar', 'rrze-multisite-manager'),
                    'label' => $label,
                    'type' => $type,
                    'resolved' => false,
                ];
                continue;
            }

            $results[$slug] = [
                'slug' => $slug,
                'label' => $this->extractCustomPostTypeLabel($context, $slug),
                'type' => $type,
                'resolved' => true,
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

    protected function extractTaxonomiesFromSource(string $source, array $sharedSymbols = []): array {
        $matches = [];
        $results = [];
        $index = 0;
        $taxonomyToken = '';
        $objectTypeToken = '';
        $slug = '';
        $context = '';
        $label = '';
        $objectTypes = [];
        $objectType = '';
        $symbols = array_merge($sharedSymbols, $this->getSourceStringSymbols($source));
        $symbols = $this->resolveGetterBackedSourceSymbols($source, $symbols);

        if (!preg_match_all('/(?<!function )\bregister_taxonomy\s*\(\s*(.+?)\s*,\s*(\[.*?\]|array\s*\(.*?\)|[^,\)]+)/ms', $source, $matches, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        for ($index = 0; $index < count($matches[1]); $index++) {
            $taxonomyToken = trim((string)($matches[1][$index][0] ?? ''));
            $objectTypeToken = trim((string)($matches[2][$index][0] ?? ''));
            $context = substr($source, (int)($matches[0][$index][1] ?? 0), 1800);
            $slug = $this->resolveSourceStringToken($taxonomyToken, $symbols);
            $label = $this->extractTaxonomyLabel($context, __('Dynamisch registrierte Taxonomie', 'rrze-multisite-manager'));
            $objectTypes = $this->extractTaxonomyObjectTypes($objectTypeToken, $symbols);

            if ($slug === '') {
                if (empty($objectTypes)) {
                    $objectTypes[] = __('Nicht statisch auflösbar', 'rrze-multisite-manager');
                }

                foreach ($objectTypes as $objectType) {
                    $results['__dynamic_tax_' . $index . ':' . $objectType] = [
                        'slug' => __('Nicht statisch auflösbar', 'rrze-multisite-manager'),
                        'label' => $label,
                        'object_type' => $objectType,
                        'resolved' => false,
                    ];
                }

                continue;
            }

            if (empty($objectTypes)) {
                $objectTypes[] = '';
            }

            foreach ($objectTypes as $objectType) {
                $results[$slug . ':' . $objectType] = [
                    'slug' => $slug,
                    'label' => $this->extractTaxonomyLabel($context, $slug),
                    'object_type' => $objectType,
                    'resolved' => true,
                ];
            }
        }

        return array_values($results);
    }

    protected function extractImageSizesFromSource(string $source, array $sharedSymbols = []): array {
        $results = [];
        $matches = [];
        $index = 0;
        $nameToken = '';
        $widthToken = '';
        $heightToken = '';
        $cropToken = '';
        $name = '';
        $width = 0;
        $height = 0;
        $crop = '';
        $symbols = array_merge($sharedSymbols, $this->getSourceStringSymbols($source));
        $symbols = $this->resolveGetterBackedSourceSymbols($source, $symbols);

        if (preg_match_all('/\badd_image_size\s*\(\s*([^,\)]+)\s*,\s*([^,\)]+)\s*,\s*([^,\)]+)(?:\s*,\s*([^\)]+))?\)/m', $source, $matches, PREG_SET_ORDER)) {
            for ($index = 0; $index < count($matches); $index++) {
                $nameToken = trim((string)($matches[$index][1] ?? ''));
                $widthToken = trim((string)($matches[$index][2] ?? ''));
                $heightToken = trim((string)($matches[$index][3] ?? ''));
                $cropToken = trim((string)($matches[$index][4] ?? ''));
                $name = $this->resolveSourceStringToken($nameToken, $symbols);

                if ($name === '') {
                    continue;
                }

                $width = $this->resolveSourceIntegerToken($widthToken, $symbols);
                $height = $this->resolveSourceIntegerToken($heightToken, $symbols);
                $crop = $this->normalizeImageSizeCropToken($cropToken, $symbols);

                $results[$name] = [
                    'slug' => $name,
                    'label' => $this->formatImageSizeLabel($name),
                    'width' => $width,
                    'height' => $height,
                    'crop' => $crop,
                ];
            }
        }

        if (preg_match_all('/\bset_post_thumbnail_size\s*\(\s*([^,\)]+)\s*,\s*([^,\)]+)(?:\s*,\s*([^\)]+))?\)/m', $source, $matches, PREG_SET_ORDER)) {
            for ($index = 0; $index < count($matches); $index++) {
                $widthToken = trim((string)($matches[$index][1] ?? ''));
                $heightToken = trim((string)($matches[$index][2] ?? ''));
                $cropToken = trim((string)($matches[$index][3] ?? ''));

                $results['post-thumbnail'] = [
                    'slug' => 'post-thumbnail',
                    'label' => $this->formatImageSizeLabel('post-thumbnail'),
                    'width' => $this->resolveSourceIntegerToken($widthToken, $symbols),
                    'height' => $this->resolveSourceIntegerToken($heightToken, $symbols),
                    'crop' => $this->normalizeImageSizeCropToken($cropToken, $symbols),
                ];
            }
        }

        return array_values($results);
    }

    protected function extractTaxonomyLabel(string $context, string $fallback): string {
        $matches = [];

        if (preg_match('/[\'"]name[\'"]\s*=>\s*[\'"]([^\'"]+)[\'"]/i', $context, $matches)) {
            return (string)($matches[1] ?? $fallback);
        }

        if (preg_match('/[\'"]label[\'"]\s*=>\s*[\'"]([^\'"]+)[\'"]/i', $context, $matches)) {
            return (string)($matches[1] ?? $fallback);
        }

        return $fallback;
    }

    protected function extractTaxonomyObjectTypes(string $token, array $symbols): array {
        $objectTypes = [];
        $objectType = '';
        $matches = [];
        $parts = [];
        $part = '';

        $token = trim($token);

        if ($token === '') {
            return [];
        }

        if (str_starts_with($token, '[') && str_ends_with($token, ']')) {
            $token = trim(substr($token, 1, -1));
            $parts = preg_split('/\s*,\s*/', $token);

            if (!is_array($parts)) {
                return [];
            }

            foreach ($parts as $part) {
                $objectType = $this->resolveSourceStringToken((string)$part, $symbols);

                if ($objectType !== '' && !in_array($objectType, $objectTypes, true)) {
                    $objectTypes[] = $objectType;
                }
            }

            return $objectTypes;
        }

        if (preg_match('/^array\s*\((.*)\)$/is', $token, $matches)) {
            $parts = preg_split('/\s*,\s*/', (string)($matches[1] ?? ''));

            if (!is_array($parts)) {
                return [];
            }

            foreach ($parts as $part) {
                $objectType = $this->resolveSourceStringToken((string)$part, $symbols);

                if ($objectType !== '' && !in_array($objectType, $objectTypes, true)) {
                    $objectTypes[] = $objectType;
                }
            }

            return $objectTypes;
        }

        $objectType = $this->resolveSourceStringToken($token, $symbols);

        if ($objectType !== '') {
            return [$objectType];
        }

        return [];
    }

    protected function getSourceStringSymbols(string $source): array {
        $symbols = [];
        $matches = [];
        $index = 0;
        $name = '';
        $value = '';

        if (preg_match_all('/define\s*\(\s*[\'"]([A-Z0-9_]+)[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]\s*\)/', $source, $matches, PREG_SET_ORDER)) {
            for ($index = 0; $index < count($matches); $index++) {
                $name = (string)($matches[$index][1] ?? '');
                $value = (string)($matches[$index][2] ?? '');

                if ($name !== '' && $value !== '') {
                    $symbols[$name] = $value;
                }
            }
        }

        if (preg_match_all('/(?:public|protected|private)?\s*const\s+([A-Z0-9_]+)\s*=\s*[\'"]([^\'"]+)[\'"]\s*;/i', $source, $matches, PREG_SET_ORDER)) {
            for ($index = 0; $index < count($matches); $index++) {
                $name = (string)($matches[$index][1] ?? '');
                $value = (string)($matches[$index][2] ?? '');

                if ($name !== '' && $value !== '') {
                    $symbols[$name] = $value;
                }
            }
        }

        if (preg_match_all('/\$([A-Za-z_][A-Za-z0-9_]*)\s*=\s*[\'"]([^\'"]+)[\'"]\s*;/', $source, $matches, PREG_SET_ORDER)) {
            for ($index = 0; $index < count($matches); $index++) {
                $name = '$' . (string)($matches[$index][1] ?? '');
                $value = (string)($matches[$index][2] ?? '');

                if ($name !== '$' && $value !== '') {
                    $symbols[$name] = $value;
                }
            }
        }

        if (preg_match_all('/\$this->([A-Za-z_][A-Za-z0-9_]*)\s*=\s*[\'"]([^\'"]+)[\'"]\s*;/', $source, $matches, PREG_SET_ORDER)) {
            for ($index = 0; $index < count($matches); $index++) {
                $name = '$this->' . (string)($matches[$index][1] ?? '');
                $value = (string)($matches[$index][2] ?? '');

                if ($name !== '$this->' && $value !== '') {
                    $symbols[$name] = $value;
                }
            }
        }

        if (preg_match_all('/[\'"]([A-Za-z0-9_\-]+)[\'"]\s*=>\s*[\'"]([^\'"]+)[\'"]/', $source, $matches, PREG_SET_ORDER)) {
            for ($index = 0; $index < count($matches); $index++) {
                $name = (string)($matches[$index][1] ?? '');
                $value = (string)($matches[$index][2] ?? '');

                if ($name !== '' && $value !== '' && !isset($symbols[$name])) {
                    $symbols[$name] = $value;
                }
            }
        }

        return $symbols;
    }

    protected function getSourceStringSymbolsFromFiles(array $files): array {
        $symbols = [];
        $filePath = '';
        $source = '';

        foreach ($files as $filePath) {
            if (!is_string($filePath) || $filePath === '' || !is_readable($filePath)) {
                continue;
            }

            $source = (string)file_get_contents($filePath);

            if ($source === '') {
                continue;
            }

            $symbols = array_merge($symbols, $this->getSourceStringSymbols($source));
            $symbols = $this->resolveGetterBackedSourceSymbols($source, $symbols);
        }

        return $symbols;
    }

    protected function resolveGetterBackedSourceSymbols(string $source, array $symbols): array {
        $matches = [];
        $index = 0;
        $name = '';
        $key = '';

        if (!preg_match_all('/(\$this->\w+|\$\w+)\s*=\s*(?:\(string\)\s*)?(?:\$this->config|\$config|\$this->settings|\$settings)->get\(\s*[\'"]([^\'"]+)[\'"]\s*\)\s*;/i', $source, $matches, PREG_SET_ORDER)) {
            return $symbols;
        }

        for ($index = 0; $index < count($matches); $index++) {
            $name = trim((string)($matches[$index][1] ?? ''));
            $key = trim((string)($matches[$index][2] ?? ''));

            if ($name === '' || $key === '' || !isset($symbols[$key]) || !is_string($symbols[$key])) {
                continue;
            }

            $symbols[$name] = (string)$symbols[$key];
        }

        return $symbols;
    }

    protected function resolveSourceStringToken(string $token, array $symbols): string {
        $matches = [];
        $key = '';

        $token = trim($token);

        if ($token === '') {
            return '';
        }

        if (preg_match('/^[\'"]([^\'"]+)[\'"]$/', $token, $matches)) {
            return (string)($matches[1] ?? '');
        }

        if (isset($symbols[$token]) && is_string($symbols[$token])) {
            return (string)$symbols[$token];
        }

        if (preg_match('/^(?:\(string\)\s*)?(?:\$this->config|\$config|\$this->settings|\$settings)->get\(\s*[\'"]([^\'"]+)[\'"]\s*\)$/i', $token, $matches)) {
            $key = (string)($matches[1] ?? '');

            if ($key !== '' && isset($symbols[$key]) && is_string($symbols[$key])) {
                return (string)$symbols[$key];
            }
        }

        if (str_starts_with($token, 'self::')) {
            $token = substr($token, 6);
        } elseif (str_starts_with($token, 'static::')) {
            $token = substr($token, 8);
        }

        if (isset($symbols[$token]) && is_string($symbols[$token])) {
            return (string)$symbols[$token];
        }

        return '';
    }

    protected function resolveSourceIntegerToken(string $token, array $symbols): int {
        $value = $this->resolveSourceStringToken($token, $symbols);

        if ($value !== '' && is_numeric($value)) {
            return max(0, (int)$value);
        }

        $token = trim($token);

        if ($token === '') {
            return 0;
        }

        if (preg_match('/^\(?\s*(\d+)\s*\)?$/', $token, $matches)) {
            return (int)($matches[1] ?? 0);
        }

        if (isset($symbols[$token]) && is_numeric($symbols[$token])) {
            return max(0, (int)$symbols[$token]);
        }

        return 0;
    }

    protected function normalizeImageSizeCropToken(string $token, array $symbols): string {
        $token = trim($token);

        if ($token === '') {
            return __('Nein', 'rrze-multisite-manager');
        }

        if (preg_match('/^\[\s*[\'"]([^\'"]+)[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]\s*\]$/', $token, $matches)) {
            return (string)($matches[1] ?? '') . ' / ' . (string)($matches[2] ?? '');
        }

        if (preg_match('/^array\s*\(\s*[\'"]([^\'"]+)[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]\s*\)$/i', $token, $matches)) {
            return (string)($matches[1] ?? '') . ' / ' . (string)($matches[2] ?? '');
        }

        $resolved = strtolower($this->resolveSourceStringToken($token, $symbols));

        if ($resolved === 'true' || strtolower($token) === 'true') {
            return __('Ja', 'rrze-multisite-manager');
        }

        if ($resolved === 'false' || strtolower($token) === 'false') {
            return __('Nein', 'rrze-multisite-manager');
        }

        return $token;
    }

    protected function formatImageSizeCropValue(mixed $crop): string {
        if (is_array($crop) && count($crop) === 2) {
            return (string)($crop[0] ?? '') . ' / ' . (string)($crop[1] ?? '');
        }

        if ($crop) {
            return __('Ja', 'rrze-multisite-manager');
        }

        return __('Nein', 'rrze-multisite-manager');
    }

    protected function formatImageSizeLabel(string $slug): string {
        $labels = [
            'thumbnail' => __('Vorschaubild', 'rrze-multisite-manager'),
            'medium' => __('Mittel', 'rrze-multisite-manager'),
            'medium_large' => __('Mittel groß', 'rrze-multisite-manager'),
            'large' => __('Groß', 'rrze-multisite-manager'),
            'post-thumbnail' => __('Beitragsbild', 'rrze-multisite-manager'),
            '1536x1536' => '1536x1536',
            '2048x2048' => '2048x2048',
        ];

        if (isset($labels[$slug])) {
            return $labels[$slug];
        }

        return ucwords(str_replace(['-', '_'], ' ', $slug));
    }

    protected function indexImageSizesBySlug(array $imageSizes, string $providerName): array {
        $result = [];
        $item = [];
        $slug = '';

        foreach ($imageSizes as $item) {
            $slug = (string)($item['slug'] ?? '');

            if ($slug === '') {
                continue;
            }

            if (!isset($result[$slug])) {
                $result[$slug] = [
                    'providers' => [],
                ];
            }

            if ($providerName !== '' && !in_array($providerName, $result[$slug]['providers'], true)) {
                $result[$slug]['providers'][] = $providerName;
            }
        }

        return $result;
    }

    protected function mergeImageSizeProviderMap(array $current, array $additional): array {
        $slug = '';
        $provider = '';

        foreach ($additional as $slug => $data) {
            if (!isset($current[$slug])) {
                $current[$slug] = [
                    'providers' => [],
                ];
            }

            foreach ((array)($data['providers'] ?? []) as $provider) {
                if (!is_string($provider) || $provider === '' || in_array($provider, $current[$slug]['providers'], true)) {
                    continue;
                }

                $current[$slug]['providers'][] = $provider;
            }
        }

        return $current;
    }

    protected function determineImageSizeProviderType(string $slug, array $providerNames): string {
        $coreSlugs = [
            'thumbnail',
            'medium',
            'medium_large',
            'large',
            'post-thumbnail',
            '1536x1536',
            '2048x2048',
        ];

        if (!empty($providerNames)) {
            return __('Theme/Plugin', 'rrze-multisite-manager');
        }

        if (in_array($slug, $coreSlugs, true)) {
            return __('WordPress Core', 'rrze-multisite-manager');
        }

        return __('Nicht direkt zugeordnet', 'rrze-multisite-manager');
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

    protected function mergeTaxonomyRows(array $current, array $additional): array {
        $result = [];
        $item = [];
        $key = '';

        foreach ($current as $item) {
            $key = (string)($item['slug'] ?? '') . ':' . (string)($item['object_type'] ?? '');

            if ($key !== ':') {
                $result[$key] = $item;
            }
        }

        foreach ($additional as $item) {
            $key = (string)($item['slug'] ?? '') . ':' . (string)($item['object_type'] ?? '');

            if ($key !== ':') {
                $result[$key] = $item;
            }
        }

        return array_values($result);
    }

    protected function mergeImageSizeRows(array $current, array $additional): array {
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

    protected function getTranslationLanguages(string $target, string $textDomain = '', string $type = 'plugin'): array {
        $baseDir = $type === 'theme'
            ? $this->getThemeAbsolutePath($target)
            : $this->getPluginDirectoryPath($target);
        $directories = [];
        $directory = '';
        $files = [];
        $iterator = null;
        $current = null;
        $path = '';
        $basename = '';
        $language = '';
        $extension = '';
        $languages = [];

        if ($baseDir === '' || !is_dir($baseDir)) {
            return [];
        }

        $directories[] = $baseDir;

        if (is_dir($baseDir . '/languages')) {
            $directories[] = $baseDir . '/languages';
        }

        if (is_dir($baseDir . '/lang')) {
            $directories[] = $baseDir . '/lang';
        }

        foreach (array_unique($directories) as $directory) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(
                    $directory,
                    \FilesystemIterator::SKIP_DOTS
                )
            );

            foreach ($iterator as $current) {
                if (!$current instanceof \SplFileInfo || !$current->isFile()) {
                    continue;
                }

                $path = (string)$current->getPathname();
                $extension = strtolower((string)pathinfo($path, PATHINFO_EXTENSION));

                if (!in_array($extension, ['po', 'mo'], true)) {
                    continue;
                }

                $files[] = $path;
            }
        }

        sort($files, SORT_NATURAL | SORT_FLAG_CASE);

        foreach ($files as $path) {
            $basename = basename($path);

            if ($textDomain !== '' && !str_starts_with($basename, $textDomain . '-')) {
                continue;
            }

            $language = $this->extractLanguageFromTranslationFilename($basename, $textDomain);

            if ($language === '') {
                continue;
            }

            if (isset($languages[$language])) {
                continue;
            }

            $languages[$language] = $language;
        }

        return array_values($languages);
    }

    protected function extractLanguageFromTranslationFilename(string $basename, string $textDomain = ''): string {
        $matches = [];
        $language = '';

        if ($textDomain !== '' && preg_match('/^' . preg_quote($textDomain, '/') . '-([A-Za-z_@-]+)\.(?:po|mo|json)$/', $basename, $matches)) {
            $language = (string)($matches[1] ?? '');
        } elseif (preg_match('/-([a-z]{2,3}(?:_[A-Z]{2})?(?:_[a-z0-9]+)?)(?:\.l10n)?\.(?:po|mo|json)$/', $basename, $matches)) {
            $language = (string)($matches[1] ?? '');
        }

        return str_replace('_', '-', $language);
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

    protected function buildThemeUsageDistribution(array $themes): array {
        $totalSites = $this->countSites();
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

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Lightweight aggregate used for dashboard summary generation and covered by dashboard caching.
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

        return $this->sortFormattedSitesByActivity($results, $direction);
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
        if (isset($this->siteAdminEmailCache[$siteId])) {
            return $this->siteAdminEmailCache[$siteId];
        }

        $email = get_blog_option($siteId, 'admin_email', '');

        if (!is_string($email) || trim($email) === '') {
            $this->siteAdminEmailCache[$siteId] = '';
            return '';
        }

        $this->siteAdminEmailCache[$siteId] = $email;

        return $email;
    }

    protected function getSiteStatusMeta(int $siteId): array {
        return [
            'status_note' => (string)get_site_meta($siteId, 'rrze_msm_status_note', true),
            'status_user_id' => (int)get_site_meta($siteId, 'rrze_msm_status_user_id', true),
            'archived_at' => (string)get_site_meta($siteId, 'rrze_msm_archived_at', true),
            'spam_at' => (string)get_site_meta($siteId, 'rrze_msm_spam_at', true),
            'operational_status' => (string)get_site_meta($siteId, 'rrze_msm_operational_status', true),
            'operational_status_label' => $this->getOperationalStatusLabel((string)get_site_meta($siteId, 'rrze_msm_operational_status', true)),
            'operational_status_source' => (string)get_site_meta($siteId, 'rrze_msm_operational_status_source', true),
            'previous_operational_status' => (string)get_site_meta($siteId, 'rrze_msm_previous_operational_status', true),
            'previous_operational_status_label' => $this->getOperationalStatusLabel((string)get_site_meta($siteId, 'rrze_msm_previous_operational_status', true)),
            'operational_status_changed_at' => (string)get_site_meta($siteId, 'rrze_msm_operational_status_changed_at', true),
            'dns_status' => (string)get_site_meta($siteId, 'rrze_msm_dns_status', true),
            'dns_status_label' => $this->getMonitoringStatusLabel((string)get_site_meta($siteId, 'rrze_msm_dns_status', true)),
            'http_status' => (string)get_site_meta($siteId, 'rrze_msm_http_status', true),
            'http_status_label' => $this->getMonitoringStatusLabel((string)get_site_meta($siteId, 'rrze_msm_http_status', true)),
            'last_availability_check' => (string)get_site_meta($siteId, 'rrze_msm_last_availability_check', true),
            'last_dns_ok_at' => (string)get_site_meta($siteId, 'rrze_msm_last_dns_ok_at', true),
            'last_http_ok_at' => (string)get_site_meta($siteId, 'rrze_msm_last_http_ok_at', true),
            'last_dns_error_at' => (string)get_site_meta($siteId, 'rrze_msm_last_dns_error_at', true),
            'last_http_error_at' => (string)get_site_meta($siteId, 'rrze_msm_last_http_error_at', true),
            'dns_failure_count' => (int)get_site_meta($siteId, 'rrze_msm_dns_failure_count', true),
            'http_failure_count' => (int)get_site_meta($siteId, 'rrze_msm_http_failure_count', true),
            'monitoring_note' => (string)get_site_meta($siteId, 'rrze_msm_monitoring_note', true),
            'monitoring_history' => $this->normalizeMonitoringHistory((array)get_site_meta($siteId, 'rrze_msm_monitoring_history', true)),
        ];
    }

    protected function normalizeMonitoringHistory(array $history): array {
        $results = [];
        $entry = [];

        foreach ($history as $entry) {
            if (!is_array($entry) || empty($entry['checked_at'])) {
                continue;
            }

            $results[] = [
                'checked_at' => (string)($entry['checked_at'] ?? ''),
                'dns_status' => (string)($entry['dns_status'] ?? ''),
                'dns_status_label' => $this->getMonitoringStatusLabel((string)($entry['dns_status'] ?? '')),
                'http_status' => (string)($entry['http_status'] ?? ''),
                'http_status_label' => $this->getMonitoringStatusLabel((string)($entry['http_status'] ?? '')),
                'previous_status' => (string)($entry['previous_status'] ?? ''),
                'previous_status_label' => $this->getOperationalStatusLabel((string)($entry['previous_status'] ?? '')),
                'status' => (string)($entry['status'] ?? ''),
                'status_label' => $this->getOperationalStatusLabel((string)($entry['status'] ?? '')),
                'status_changed' => !empty($entry['status_changed']),
            ];
        }

        return $results;
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
        $cacheKey = 'rrze_msm_site_overview_metrics_' . $this->getDetailCacheVersion() . '_' . $siteId;
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
        $cached = get_site_transient($cacheKey);
        $userData = [];

        if (is_array($cached) && !empty($cached)) {
            return $cached;
        }

        switch_to_blog($siteId);

        $userData = count_users();
        $branding = $this->getSiteBranding();
        $roleCounts = $this->normalizeRoleCounts(is_array($userData['avail_roles'] ?? null) ? $userData['avail_roles'] : []);
        $contentCounts['pages'] = $this->countContentItems('page');
        $contentCounts['posts'] = $this->countContentItems('post');
        $contentCounts['media'] = $this->countContentItems('attachment');
        $storage = $this->getSiteStorageUsage();

        restore_current_blog();

        $cached = [
            'branding' => $branding,
            'role_counts' => $roleCounts,
            'content_counts' => $contentCounts,
            'storage' => $storage,
        ];

        set_site_transient($cacheKey, $cached, $this->getDetailCacheTtl());

        return $cached;
    }

    protected function getSiteDetailMetrics(int $siteId, array $load = []): array {
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
        $imageSizes = [];
        $optionsGroups = [];
        $optionsGroupDetail = [];
        $processStats = [
            'transients' => 0,
            'cron_events' => 0,
        ];
        $transients = [];
        $cronEvents = [];
        $loadTheme = !empty($load['theme']);
        $loadPlugins = !empty($load['plugins']);
        $loadUsers = !empty($load['users']);
        $loadImageSizes = !empty($load['image_sizes']);
        $loadContent = !empty($load['content']);
        $loadOptionsSummary = !empty($load['options_summary']);
        $loadOptionValuesGroup = !empty($load['options_values_group']) ? (string)$load['options_values_group'] : '';
        $loadProcessStats = !empty($load['process_stats']);
        $loadTransients = !empty($load['transients']);
        $loadCronEvents = !empty($load['cron_events']);

        switch_to_blog($siteId);

        if ($loadTheme || $loadImageSizes) {
            $theme = $this->getCurrentThemeDetails();
        }

        if ($loadPlugins || $loadImageSizes) {
            $plugins = $this->getCurrentSiteActivePlugins();
        }

        if ($loadUsers) {
            $users = $this->getCurrentSiteUsers();
        }

        if ($loadImageSizes) {
            $imageSizes = $this->getCurrentSiteImageSizes($theme, $plugins);
        }

        if ($loadContent) {
            $contentTypes = $this->getCurrentSiteContentTypeCounts();
            $customPostTypes = $this->getCurrentSiteCustomPostTypes();
            $blockTemplateTypes = $this->getCurrentSiteBlockTemplateTypes();
        }

        if ($loadOptionsSummary) {
            $optionsGroups = $this->getCurrentSiteOptionsGroupSummary();
        }

        if ($loadOptionValuesGroup !== '') {
            $optionsGroupDetail = $this->getCurrentSiteOptionsByGroup($loadOptionValuesGroup);
        }

        if ($loadProcessStats) {
            $processStats = $this->getCurrentSiteProcessStats();
        }

        if ($loadTransients) {
            $transients = $this->getCurrentSiteTransients();
        }

        if ($loadCronEvents) {
            $cronEvents = $this->getCurrentSiteCronEvents();
        }

        restore_current_blog();

        return [
            'theme' => $theme,
            'plugins' => $plugins,
            'users' => $users,
            'content_types' => $contentTypes,
            'custom_post_types' => $customPostTypes,
            'block_template_types' => $blockTemplateTypes,
            'image_sizes' => $imageSizes,
            'options_overview' => [
                'groups' => $optionsGroups,
                'selected_group' => $optionsGroupDetail,
            ],
            'process_stats' => $processStats,
            'transients' => $transients,
            'cron_events' => $cronEvents,
            'transients_truncated' => count($transients) >= $this->getDetailSectionMaxRows(),
            'cron_events_truncated' => count($cronEvents) >= $this->getDetailSectionMaxRows(),
        ];
    }

    protected function getCurrentThemeDetails(): array {
        $cached = $this->getCachedCurrentSiteDetailSection('theme');

        if (is_array($cached)) {
            return $cached;
        }

        $theme = wp_get_theme();
        $screenshot = $theme instanceof \WP_Theme ? $theme->get_screenshot() : '';
        $description = '';
        $tags = [];
        $result = [];

        if ($theme instanceof \WP_Theme) {
            $description = (string)$theme->get('Description');
            $tags = $theme->get('Tags');
        }

        $result = [
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

        $this->setCachedCurrentSiteDetailSection('theme', $result);

        return $result;
    }

    protected function getCurrentSiteActivePlugins(): array {
        $cached = $this->getCachedCurrentSiteDetailSection('plugins');

        if (is_array($cached)) {
            return $cached;
        }

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
                'site_count' => 1,
                'network_active' => isset($networkActivePlugins[$pluginFile]),
                'settings_url' => $this->getPluginSettingsUrl($pluginFile, $pluginHeaders),
                'details_url' => $this->getPluginDetailsUrl($pluginHeaders),
                'deactivate_url' => isset($networkActivePlugins[$pluginFile]) ? '' : $this->getSitePluginDeactivateUrl($siteId, $pluginFile),
            ];
        }

        usort($results, [self::class, 'compareDetailedPlugins']);

        $this->setCachedCurrentSiteDetailSection('plugins', $results);

        return $results;
    }

    protected function getCurrentSiteImageSizes(array $theme, array $plugins): array {
        $cached = $this->getCachedCurrentSiteDetailSection('image_sizes');

        if (is_array($cached)) {
            return $cached;
        }

        global $_wp_additional_image_sizes;

        $registeredSizes = function_exists('wp_get_registered_image_subsizes')
            ? wp_get_registered_image_subsizes()
            : [];
        $themeDetails = [];
        $pluginDetails = [];
        $themeSizeMap = [];
        $pluginSizeMap = [];
        $rows = [];
        $slug = '';
        $sizeData = [];
        $providerNames = [];
        $crop = '';

        if (!is_array($registeredSizes)) {
            $registeredSizes = [];
        }

        if (!empty($theme['stylesheet']) && is_string($theme['stylesheet'])) {
            $themeDetails = $this->getThemeDetails((string)$theme['stylesheet']);
            $themeSizeMap = $this->indexImageSizesBySlug((array)($themeDetails['image_sizes'] ?? []), (string)($theme['name'] ?? ''));
        }

        foreach ($plugins as $plugin) {
            if (empty($plugin['file']) || !is_string($plugin['file'])) {
                continue;
            }

            $pluginDetails = $this->getPluginDetails((string)$plugin['file']);
            $pluginSizeMap = $this->mergeImageSizeProviderMap(
                $pluginSizeMap,
                $this->indexImageSizesBySlug(
                    (array)($pluginDetails['image_sizes'] ?? []),
                    (string)($plugin['name'] ?? $plugin['file'])
                )
            );
        }

        foreach ($registeredSizes as $slug => $sizeData) {
            if (!is_string($slug) || !is_array($sizeData)) {
                continue;
            }

            $providerNames = [];

            if (isset($themeSizeMap[$slug]) && is_array($themeSizeMap[$slug])) {
                $providerNames = array_merge($providerNames, (array)($themeSizeMap[$slug]['providers'] ?? []));
            }

            if (isset($pluginSizeMap[$slug]) && is_array($pluginSizeMap[$slug])) {
                $providerNames = array_merge($providerNames, (array)($pluginSizeMap[$slug]['providers'] ?? []));
            }

            $crop = $this->formatImageSizeCropValue($sizeData['crop'] ?? false);

            $rows[] = [
                'slug' => $slug,
                'label' => $this->formatImageSizeLabel($slug),
                'width' => (int)($sizeData['width'] ?? 0),
                'height' => (int)($sizeData['height'] ?? 0),
                'crop' => $crop,
                'provider_type' => $this->determineImageSizeProviderType($slug, $providerNames),
                'providers' => $this->normalizeStringList($providerNames),
                'is_reserved' => !empty($sizeData['crop']) || isset($_wp_additional_image_sizes[$slug]) || in_array($slug, get_intermediate_image_sizes(), true),
            ];
        }

        usort($rows, [self::class, 'compareImageSizeRows']);

        $this->setCachedCurrentSiteDetailSection('image_sizes', $rows);

        return $rows;
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
            add_query_arg(
                [
                    'action' => 'delete-selected',
                    'verify-delete' => 1,
                    'checked[]' => $pluginFile,
                ],
                network_admin_url('plugins.php')
            ),
            'bulk-plugins'
        );
    }

    protected function getSiteNameById(int $siteId): string {
        if (isset($this->siteNameCache[$siteId])) {
            return $this->siteNameCache[$siteId];
        }

        $site = get_site($siteId);

        if (!$site instanceof \WP_Site) {
            $this->siteNameCache[$siteId] = (string)$siteId;
            return $this->siteNameCache[$siteId];
        }

        $this->siteNameCache[$siteId] = $this->getSiteName($site);

        return $this->siteNameCache[$siteId];
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
        $cached = $this->getCachedCurrentSiteDetailSection('users');

        if (is_array($cached)) {
            return $cached;
        }

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

        $this->setCachedCurrentSiteDetailSection('users', $results);

        return $results;
    }

    protected function getCurrentSiteContentTypeCounts(): array {
        $cached = $this->getCachedCurrentSiteDetailSection('content_types');

        if (is_array($cached)) {
            return $cached;
        }

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

        $this->setCachedCurrentSiteDetailSection('content_types', $results);

        return $results;
    }

    protected function getCurrentSiteCustomPostTypes(): array {
        $cached = $this->getCachedCurrentSiteDetailSection('custom_post_types');

        if (is_array($cached)) {
            return $cached;
        }

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

        $this->setCachedCurrentSiteDetailSection('custom_post_types', $results);

        return $results;
    }

    protected function getCurrentSiteBlockTemplateTypes(): array {
        $cached = $this->getCachedCurrentSiteDetailSection('block_template_types');

        if (is_array($cached)) {
            return $cached;
        }

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

        $this->setCachedCurrentSiteDetailSection('block_template_types', $results);

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

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Attachment MIME aggregation is not available through a core helper and is cached by the surrounding detail cache.
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

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Purpose-built post type count query used in a cached detail context.
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

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Distinct post type overview requires direct grouping and is computed inside cached detail generation.
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

    public function getSiteStorageAnalysis(int $siteId): array {
        $cacheKey = 'rrze_msm_site_storage_analysis_v2_' . $this->getDetailCacheVersion() . '_' . $siteId;
        $cached = get_site_transient($cacheKey);
        $analysis = [];

        if ($siteId <= 0) {
            return [];
        }

        if (
            is_array($cached)
            && !empty($cached)
            && array_key_exists('orphan_files_found_in_content', $cached)
            && array_key_exists('orphan_files_without_content_matches', $cached)
        ) {
            return $cached;
        }

        switch_to_blog($siteId);
        $analysis = $this->buildCurrentSiteStorageAnalysis();
        restore_current_blog();

        set_site_transient($cacheKey, $analysis, $this->getDetailCacheTtl());

        return $analysis;
    }

    protected function buildCurrentSiteStorageAnalysis(): array {
        $uploadDir = wp_get_upload_dir();
        $baseDir = is_array($uploadDir) && !empty($uploadDir['basedir']) ? (string)$uploadDir['basedir'] : '';
        $baseUrl = is_array($uploadDir) && !empty($uploadDir['baseurl']) ? (string)$uploadDir['baseurl'] : '';
        $wordpressStorage = $this->getSiteStorageUsage();
        $scan = [];
        $attachmentIndex = [];
        $referencedFiles = [];
        $excludedTopLevelDirectories = [];
        $actualBytes = 0;
        $differenceBytes = 0;
        $summary = [];
        $warnings = [];

        if ($baseDir === '' || !is_dir($baseDir)) {
            return [
                'upload_basedir' => $baseDir,
                'upload_baseurl' => $baseUrl,
                'wordpress_storage' => $wordpressStorage,
                'error' => __('Das Upload-Verzeichnis dieser Website konnte nicht gefunden werden.', 'rrze-multisite-manager'),
                'generated_at' => current_time('mysql', true),
            ];
        }

        if (!is_readable($baseDir)) {
            return [
                'upload_basedir' => $baseDir,
                'upload_baseurl' => $baseUrl,
                'wordpress_storage' => $wordpressStorage,
                'error' => __('Das Upload-Verzeichnis dieser Website ist nicht lesbar.', 'rrze-multisite-manager'),
                'generated_at' => current_time('mysql', true),
            ];
        }

        $attachmentIndex = $this->getCurrentSiteUploadAttachmentIndex();
        $referencedFiles = array_fill_keys(array_keys($attachmentIndex), true);
        $excludedTopLevelDirectories = $this->getExcludedUploadTopLevelDirectoriesForCurrentSite();
        $scan = $this->scanUploadDirectory($baseDir, $baseUrl, $attachmentIndex, $excludedTopLevelDirectories);
        $actualBytes = (int)($scan['total_bytes'] ?? 0);
        $differenceBytes = $actualBytes - (int)($wordpressStorage['used_bytes'] ?? 0);
        $warnings = $this->buildStorageAnalysisWarnings($differenceBytes, $actualBytes, $wordpressStorage, $scan);
        $summary = [
            [
                'label' => __('WordPress gemeldet', 'rrze-multisite-manager'),
                'value' => (string)($wordpressStorage['used_label'] ?? ''),
            ],
            [
                'label' => __('Im Upload-Verzeichnis gefunden', 'rrze-multisite-manager'),
                'value' => size_format($actualBytes),
            ],
            [
                'label' => __('Differenz', 'rrze-multisite-manager'),
                'value' => ($differenceBytes >= 0 ? '+' : '-') . size_format(abs($differenceBytes)),
            ],
            [
                'label' => __('Dateien', 'rrze-multisite-manager'),
                'value' => number_format_i18n((int)($scan['total_files'] ?? 0)),
            ],
            [
                'label' => __('Dateien laut Datenbank referenziert', 'rrze-multisite-manager'),
                'value' => number_format_i18n(count($referencedFiles)),
            ],
            [
                'label' => __('Ordner', 'rrze-multisite-manager'),
                'value' => number_format_i18n((int)($scan['total_directories'] ?? 0)),
            ],
            [
                'label' => __('Potenziell verwaiste Dateien', 'rrze-multisite-manager'),
                'value' => number_format_i18n((int)($scan['orphan_file_count'] ?? 0)),
            ],
            [
                'label' => __('Analysezeitpunkt', 'rrze-multisite-manager'),
                'value' => $this->formatDate((string)current_time('mysql', true)),
            ],
        ];

        return [
            'upload_basedir' => $baseDir,
            'upload_baseurl' => $baseUrl,
            'wordpress_storage' => $wordpressStorage,
            'actual_bytes' => $actualBytes,
            'actual_label' => size_format($actualBytes),
            'difference_bytes' => $differenceBytes,
            'difference_label' => ($differenceBytes >= 0 ? '+' : '-') . size_format(abs($differenceBytes)),
            'total_files' => (int)($scan['total_files'] ?? 0),
            'total_directories' => (int)($scan['total_directories'] ?? 0),
            'orphan_file_count' => (int)($scan['orphan_file_count'] ?? 0),
            'orphan_total_bytes' => (int)($scan['orphan_total_bytes'] ?? 0),
            'orphan_total_label' => size_format((int)($scan['orphan_total_bytes'] ?? 0)),
            'largest_orphan_files' => (array)($scan['largest_orphan_files'] ?? []),
            'orphan_files_found_in_content' => (array)($scan['orphan_files_found_in_content'] ?? []),
            'orphan_files_without_content_matches' => (array)($scan['orphan_files_without_content_matches'] ?? []),
            'top_level_directories' => (array)($scan['top_level_directories'] ?? []),
            'top_consumers' => (array)($scan['top_consumers'] ?? []),
            'largest_files' => (array)($scan['largest_files'] ?? []),
            'summary_rows' => $summary,
            'warnings' => $warnings,
            'generated_at' => current_time('mysql', true),
        ];
    }

    protected function scanUploadDirectory(string $baseDir, string $baseUrl, array $attachmentIndex, array $excludedTopLevelDirectories = []): array {
        $normalizedBaseDir = trailingslashit(wp_normalize_path($baseDir));
        $directoryStats = [];
        $topLevelDirectoryStats = [];
        $largestFiles = [];
        $totalBytes = 0;
        $totalFiles = 0;
        $totalDirectories = 0;
        $iterator = null;
        $fileInfo = null;
        $normalizedPath = '';
        $relativePath = '';
        $sizeBytes = 0;
        $modifiedTimestamp = 0;
        $ancestorPath = '';
        $topLevelKey = '';
        $topConsumers = [];
        $referencedFiles = array_fill_keys(array_keys($attachmentIndex), true);
        $orphanFileCount = 0;
        $orphanTotalBytes = 0;
        $largestOrphanFiles = [];
        $classifiedLargestOrphanFiles = [];
        $directoryIterator = null;
        $callbackIterator = null;

        $directoryStats[$normalizedBaseDir] = [
            'relative_path' => '.',
            'size_bytes' => 0,
            'file_count' => 0,
        ];

        try {
            $directoryIterator = new \RecursiveDirectoryIterator($baseDir, \FilesystemIterator::SKIP_DOTS);
            $callbackIterator = new \RecursiveCallbackFilterIterator(
                $directoryIterator,
                function ($current, $key, $iterator) use ($normalizedBaseDir, $excludedTopLevelDirectories) {
                    $pathname = '';
                    $relativePath = '';

                    if (!$current instanceof \SplFileInfo) {
                        return false;
                    }

                    $pathname = wp_normalize_path((string)$current->getPathname());
                    $relativePath = ltrim(substr($pathname, strlen($normalizedBaseDir)), '/');

                    if ($this->shouldExcludeUploadRelativePath($relativePath, $excludedTopLevelDirectories)) {
                        return false;
                    }

                    return true;
                }
            );
            $iterator = new \RecursiveIteratorIterator(
                $callbackIterator,
                \RecursiveIteratorIterator::SELF_FIRST
            );
        } catch (\Throwable $exception) {
            return [
                'total_bytes' => 0,
                'total_files' => 0,
                'total_directories' => 0,
                'top_level_directories' => [],
                'top_consumers' => [],
                'largest_files' => [],
            ];
        }

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo instanceof \SplFileInfo) {
                continue;
            }

            if ($fileInfo->isLink()) {
                continue;
            }

            $normalizedPath = wp_normalize_path((string)$fileInfo->getPathname());
            $relativePath = ltrim(substr($normalizedPath, strlen($normalizedBaseDir)), '/');

            if ($fileInfo->isDir()) {
                $totalDirectories++;
                $this->ensureDirectoryStat($directoryStats, trailingslashit($normalizedPath), $normalizedBaseDir);
                continue;
            }

            if (!$fileInfo->isFile()) {
                continue;
            }

            try {
                $sizeBytes = (int)$fileInfo->getSize();
            } catch (\Throwable $exception) {
                $sizeBytes = 0;
            }

            try {
                $modifiedTimestamp = (int)$fileInfo->getMTime();
            } catch (\Throwable $exception) {
                $modifiedTimestamp = 0;
            }

            $totalBytes += max(0, $sizeBytes);
            $totalFiles++;
            $topLevelKey = $this->getTopLevelDirectoryKey($relativePath);
            $this->addToTopLevelDirectoryStats($topLevelDirectoryStats, $topLevelKey, $sizeBytes);
            $this->pushLargestFileEntry(
                $largestFiles,
                $this->buildStorageFileEntry(
                    $relativePath === '' ? basename($normalizedPath) : $relativePath,
                    max(0, $sizeBytes),
                    $modifiedTimestamp,
                    $baseUrl,
                    $attachmentIndex
                )
            );

            if ($this->isPotentiallyOrphanUploadFile($relativePath, $referencedFiles)) {
                $orphanFileCount++;
                $orphanTotalBytes += max(0, $sizeBytes);
                $this->pushLargestFileEntry(
                    $largestOrphanFiles,
                    $this->buildStorageFileEntry(
                        $relativePath === '' ? basename($normalizedPath) : $relativePath,
                        max(0, $sizeBytes),
                        $modifiedTimestamp,
                        $baseUrl,
                        $attachmentIndex
                    ),
                    self::STORAGE_ORPHAN_FILES_LIMIT
                );
            }

            $ancestorPath = trailingslashit(wp_normalize_path(dirname($normalizedPath)));

            while (str_starts_with($ancestorPath, $normalizedBaseDir)) {
                $this->ensureDirectoryStat($directoryStats, $ancestorPath, $normalizedBaseDir);
                $directoryStats[$ancestorPath]['size_bytes'] += max(0, $sizeBytes);
                $directoryStats[$ancestorPath]['file_count']++;

                if ($ancestorPath === $normalizedBaseDir) {
                    break;
                }

                $ancestorPath = trailingslashit(wp_normalize_path(dirname(rtrim($ancestorPath, '/'))));
            }
        }

        $topConsumers = $this->buildTopStorageConsumers($directoryStats, $largestFiles, $normalizedBaseDir);
        $classifiedLargestOrphanFiles = $this->classifyCurrentSitePotentialOrphanFiles($largestOrphanFiles);

        return [
            'total_bytes' => $totalBytes,
            'total_files' => $totalFiles,
            'total_directories' => $totalDirectories,
            'orphan_file_count' => $orphanFileCount,
            'orphan_total_bytes' => $orphanTotalBytes,
            'largest_orphan_files' => $largestOrphanFiles,
            'orphan_files_truncated' => $orphanFileCount > self::STORAGE_ORPHAN_FILES_LIMIT,
            'orphan_files_found_in_content' => (array)($classifiedLargestOrphanFiles['found_in_content'] ?? []),
            'orphan_files_without_content_matches' => (array)($classifiedLargestOrphanFiles['without_matches'] ?? []),
            'top_level_directories' => $this->finalizeTopLevelDirectoryStats($topLevelDirectoryStats, $totalBytes),
            'top_consumers' => $topConsumers,
            'largest_files' => array_slice($largestFiles, 0, self::STORAGE_LARGEST_FILES_LIMIT),
        ];
    }

    protected function getExcludedUploadTopLevelDirectoriesForCurrentSite(): array {
        if (!is_multisite() || !is_main_site()) {
            return [];
        }

        return ['sites'];
    }

    protected function shouldExcludeUploadRelativePath(string $relativePath, array $excludedTopLevelDirectories): bool {
        $normalizedRelativePath = trim($this->normalizeRelativeUploadPath($relativePath), '/');
        $firstSegment = '';

        if ($normalizedRelativePath === '' || empty($excludedTopLevelDirectories)) {
            return false;
        }

        $firstSegment = strtok($normalizedRelativePath, '/');

        if (!is_string($firstSegment) || $firstSegment === '') {
            return false;
        }

        return in_array($firstSegment, $excludedTopLevelDirectories, true);
    }

    protected function classifyCurrentSitePotentialOrphanFiles(array $files): array {
        $foundInContent = [];
        $withoutMatches = [];
        $fileRow = [];
        $matches = [];
        $matchCount = 0;

        foreach ($files as $fileRow) {
            if (!is_array($fileRow)) {
                continue;
            }

            $matches = $this->searchCurrentSiteFileUsageMatches(
                is_string($fileRow['file_url'] ?? null) ? (string)$fileRow['file_url'] : '',
                is_string($fileRow['path'] ?? null) ? (string)$fileRow['path'] : ''
            );
            $matchCount = count($matches);
            $fileRow['content_usage_count'] = $matchCount;
            $fileRow['content_usage_label'] = sprintf(
                /* translators: %d: number of content usage matches. */
                _n('%d Treffer', '%d Treffer', $matchCount, 'rrze-multisite-manager'),
                $matchCount
            );

            if ($matchCount > 0) {
                $fileRow['content_usage_results'] = $matches;
                $foundInContent[] = $fileRow;
                continue;
            }

            $withoutMatches[] = $fileRow;
        }

        return [
            'found_in_content' => $foundInContent,
            'without_matches' => $withoutMatches,
        ];
    }

    protected function getCurrentSiteUploadAttachmentIndex(): array {
        global $wpdb;

        $index = [];
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Attachment index needs a joined metadata query and is reused within cached storage analysis.
        $rows = $wpdb->get_results(
            "SELECT p.ID, p.post_mime_type, pm_file.meta_value AS attached_file, pm_meta.meta_value AS attachment_metadata
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm_file
                ON pm_file.post_id = p.ID AND pm_file.meta_key = '_wp_attached_file'
            LEFT JOIN {$wpdb->postmeta} pm_meta
                ON pm_meta.post_id = p.ID AND pm_meta.meta_key = '_wp_attachment_metadata'
            WHERE p.post_type = 'attachment'"
        );
        $row = null;
        $attachedPath = '';
        $metadata = [];
        $attachmentId = 0;
        $baseEntry = [];

        foreach ($rows as $row) {
            $attachmentId = (int)($row->ID ?? 0);
            $attachedPath = is_string($row->attached_file ?? null) ? (string)$row->attached_file : '';

            if ($attachmentId <= 0 || trim($attachedPath) === '') {
                continue;
            }

            $baseEntry = [
                'attachment_id' => $attachmentId,
                'media_edit_url' => get_edit_post_link($attachmentId, ''),
                'mime_type' => is_string($row->post_mime_type ?? null) ? (string)$row->post_mime_type : '',
                'type_label' => $this->getStorageFileTypeLabel($attachedPath, is_string($row->post_mime_type ?? null) ? (string)$row->post_mime_type : ''),
            ];

            $index[$this->normalizeRelativeUploadPath($attachedPath)] = $baseEntry;

            $metadata = maybe_unserialize($row->attachment_metadata ?? '');

            if (!is_array($metadata)) {
                continue;
            }

            $this->collectAttachmentIndexPathsFromMetadata($index, $baseEntry, $metadata);
        }

        return $index;
    }

    protected function collectAttachmentIndexPathsFromMetadata(array &$index, array $baseEntry, array $metadata): void {
        $baseFile = '';
        $baseDir = '';
        $sizes = [];
        $sizeRow = [];
        $originalImage = '';

        if (!empty($metadata['file']) && is_string($metadata['file'])) {
            $baseFile = $this->normalizeRelativeUploadPath((string)$metadata['file']);
            $index[$baseFile] = $baseEntry;
            $baseDir = dirname($baseFile);

            if ($baseDir === '.' || $baseDir === DIRECTORY_SEPARATOR) {
                $baseDir = '';
            }
        }

        $sizes = is_array($metadata['sizes'] ?? null) ? $metadata['sizes'] : [];

        foreach ($sizes as $sizeRow) {
            if (!is_array($sizeRow) || empty($sizeRow['file']) || !is_string($sizeRow['file'])) {
                continue;
            }

            if ($baseDir !== '') {
                $index[$this->normalizeRelativeUploadPath($baseDir . '/' . (string)$sizeRow['file'])] = $baseEntry;
                continue;
            }

            $index[$this->normalizeRelativeUploadPath((string)$sizeRow['file'])] = $baseEntry;
        }

        $originalImage = !empty($metadata['original_image']) && is_string($metadata['original_image'])
            ? (string)$metadata['original_image']
            : '';

        if ($originalImage !== '') {
            if ($baseDir !== '') {
                $index[$this->normalizeRelativeUploadPath($baseDir . '/' . $originalImage)] = $baseEntry;
            } else {
                $index[$this->normalizeRelativeUploadPath($originalImage)] = $baseEntry;
            }
        }
    }

    protected function getCurrentSiteReferencedUploadFiles(): array {
        $index = $this->getCurrentSiteUploadAttachmentIndex();
        $paths = [];

        foreach (array_keys($index) as $attachedPath) {
            if (!is_string($attachedPath) || trim($attachedPath) === '') {
                continue;
            }

            $paths[$this->normalizeRelativeUploadPath($attachedPath)] = true;
        }

        return $paths;
    }

    protected function buildStorageFileEntry(string $relativePath, int $sizeBytes, int $modifiedTimestamp, string $baseUrl, array $attachmentIndex): array {
        $normalizedRelativePath = $this->normalizeRelativeUploadPath($relativePath);
        $attachmentEntry = is_array($attachmentIndex[$normalizedRelativePath] ?? null) ? $attachmentIndex[$normalizedRelativePath] : [];
        $mimeType = is_string($attachmentEntry['mime_type'] ?? null) ? (string)$attachmentEntry['mime_type'] : '';

        return [
            'type' => 'file',
            'path' => $relativePath,
            'size_bytes' => $sizeBytes,
            'size_label' => size_format($sizeBytes),
            'modified_label' => $this->formatTimestamp($modifiedTimestamp),
            'file_url' => trailingslashit($baseUrl) . ltrim($normalizedRelativePath, '/'),
            'media_edit_url' => is_string($attachmentEntry['media_edit_url'] ?? null) ? (string)$attachmentEntry['media_edit_url'] : '',
            'attachment_id' => (int)($attachmentEntry['attachment_id'] ?? 0),
            'mime_type' => $mimeType,
            'type_label' => !empty($attachmentEntry['type_label'])
                ? (string)$attachmentEntry['type_label']
                : $this->getStorageFileTypeLabel($normalizedRelativePath, $mimeType),
        ];
    }

    protected function collectReferencedPathsFromAttachmentMetadata(array &$paths, array $metadata): void {
        $baseFile = '';
        $baseDir = '';
        $sizes = [];
        $sizeRow = [];
        $originalImage = '';

        if (!empty($metadata['file']) && is_string($metadata['file'])) {
            $baseFile = $this->normalizeRelativeUploadPath((string)$metadata['file']);
            $paths[$baseFile] = true;
            $baseDir = dirname($baseFile);

            if ($baseDir === '.' || $baseDir === DIRECTORY_SEPARATOR) {
                $baseDir = '';
            }
        }

        $sizes = is_array($metadata['sizes'] ?? null) ? $metadata['sizes'] : [];

        foreach ($sizes as $sizeRow) {
            if (!is_array($sizeRow) || empty($sizeRow['file']) || !is_string($sizeRow['file'])) {
                continue;
            }

            if ($baseDir !== '') {
                $paths[$this->normalizeRelativeUploadPath($baseDir . '/' . (string)$sizeRow['file'])] = true;
                continue;
            }

            $paths[$this->normalizeRelativeUploadPath((string)$sizeRow['file'])] = true;
        }

        $originalImage = !empty($metadata['original_image']) && is_string($metadata['original_image'])
            ? (string)$metadata['original_image']
            : '';

        if ($originalImage !== '') {
            if ($baseDir !== '') {
                $paths[$this->normalizeRelativeUploadPath($baseDir . '/' . $originalImage)] = true;
            } else {
                $paths[$this->normalizeRelativeUploadPath($originalImage)] = true;
            }
        }
    }

    protected function normalizeRelativeUploadPath(string $path): string {
        $normalized = ltrim(wp_normalize_path($path), '/');

        return $normalized;
    }

    protected function isPotentiallyOrphanUploadFile(string $relativePath, array $referencedFiles): bool {
        $normalizedPath = $this->normalizeRelativeUploadPath($relativePath);
        $basename = basename($normalizedPath);

        if ($normalizedPath === '' || isset($referencedFiles[$normalizedPath])) {
            return false;
        }

        if (in_array($basename, ['index.php', '.htaccess', 'web.config'], true)) {
            return false;
        }

        return true;
    }

    protected function getStorageFileTypeLabel(string $relativePath, string $mimeType = ''): string {
        $extension = strtolower((string)pathinfo($relativePath, PATHINFO_EXTENSION));

        if ($extension !== '') {
            return strtoupper($extension);
        }

        if ($mimeType !== '') {
            return strtoupper((string)preg_replace('/[^a-z0-9]+/i', '-', $mimeType));
        }

        return __('Datei', 'rrze-multisite-manager');
    }

    protected function buildStorageAnalysisWarnings(int $differenceBytes, int $actualBytes, array $wordpressStorage, array $scan): array {
        $warnings = [];
        $differencePercent = $actualBytes > 0 ? abs($differenceBytes) / $actualBytes : 0.0;
        $orphanFileCount = (int)($scan['orphan_file_count'] ?? 0);
        $orphanTotalBytes = (int)($scan['orphan_total_bytes'] ?? 0);

        if (abs($differenceBytes) >= (50 * MB_IN_BYTES) && $differencePercent >= 0.2) {
            $warnings[] = [
                'type' => $differenceBytes > 0 ? 'warning' : 'info',
                'message' => $differenceBytes > 0
                    ? sprintf(
                        /* translators: %s: storage size difference. */
                        __('Das Upload-Verzeichnis ist um %s größer als der von WordPress gemeldete Speicherwert. Das deutet oft auf veraltete Core-Caches oder zusätzliche Dateien im Uploads-Ordner hin.', 'rrze-multisite-manager'),
                        size_format(abs($differenceBytes))
                    )
                    : sprintf(
                        /* translators: %s: storage size difference. */
                        __('WordPress meldet %s mehr Speicher als im aktuell gescannten Upload-Verzeichnis gefunden wurde. Das deutet oft auf einen veralteten WordPress-Speicherwert hin.', 'rrze-multisite-manager'),
                        size_format(abs($differenceBytes))
                    ),
            ];
        }

        if ($orphanFileCount > 0 && $orphanTotalBytes >= (5 * MB_IN_BYTES)) {
            $warnings[] = [
                'type' => 'warning',
                'message' => sprintf(
                    /* translators: 1: orphan file count, 2: total orphan storage size. */
                    __('Es wurden %1$s potenziell verwaiste Dateien mit zusammen %2$s gefunden. Das sind Dateien im Uploads-Ordner, die aktuell nicht über Attachment-Metadaten referenziert werden.', 'rrze-multisite-manager'),
                    number_format_i18n($orphanFileCount),
                    size_format($orphanTotalBytes)
                ),
            ];
        }

        if (!empty($wordpressStorage['is_unlimited'])) {
            $warnings[] = [
                'type' => 'info',
                'message' => __('Diese Website hat kein festes Upload-Limit. Die Speicheranalyse zeigt deshalb nur tatsächlichen Verbrauch und keine sinnvolle Auslastung in Prozent.', 'rrze-multisite-manager'),
            ];
        }

        return $warnings;
    }

    public function searchSiteFileUsage(int $siteId, string $fileUrl, string $relativePath): array {
        if ($siteId <= 0 || trim($fileUrl) === '') {
            return [];
        }

        switch_to_blog($siteId);
        $results = $this->searchCurrentSiteFileUsageMatches($fileUrl, $relativePath);
        restore_current_blog();

        return $results;
    }

    public function deleteSiteOrphanFile(int $siteId, string $relativePath): array {
        $result = [];

        if ($siteId <= 0 || trim($relativePath) === '') {
            return [
                'deleted' => false,
                'message' => __('Ungültige Datei.', 'rrze-multisite-manager'),
            ];
        }

        switch_to_blog($siteId);
        $result = $this->deleteCurrentSiteOrphanFile($relativePath);
        restore_current_blog();

        return $result;
    }

    protected function searchCurrentSiteFileUsageMatches(string $fileUrl, string $relativePath): array {
        global $wpdb;

        $results = [];
        $needles = [];
        $seen = [];
        $posts = [];
        $metaRows = [];
        $post = null;
        $metaRow = null;
        $postId = 0;
        $codeMatches = [];
        $codeMatch = [];
        $codeKey = '';
        $contentConditions = [];
        $contentParams = [];
        $metaConditions = [];
        $metaParams = [];
        $needle = '';

        $needles = $this->buildFileUsageSearchNeedles($fileUrl, $relativePath);

        if (empty($needles)) {
            return [];
        }

        foreach ($needles as $needle) {
            $contentConditions[] = 'post_content LIKE %s';
            $contentParams[] = '%' . $wpdb->esc_like($needle) . '%';
            $metaConditions[] = 'pm.meta_value LIKE %s';
            $metaParams[] = '%' . $wpdb->esc_like($needle) . '%';
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Placeholder conditions are assembled internally and bound safely via $wpdb->prepare().
        $posts = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ID, post_type, post_title
                FROM {$wpdb->posts}
                WHERE post_type IN ('post', 'page')
                AND post_status NOT IN ('auto-draft', 'trash')
                AND (" . implode(' OR ', $contentConditions) . ')',
                ...$contentParams
            )
        );

        foreach ($posts as $post) {
            $postId = (int)($post->ID ?? 0);

            if ($postId <= 0) {
                continue;
            }

            $results[$postId] = [
                'post_id' => $postId,
                'post_type' => (string)($post->post_type ?? ''),
                'title' => trim((string)($post->post_title ?? '')) !== '' ? (string)$post->post_title : __('(ohne Titel)', 'rrze-multisite-manager'),
                'edit_url' => get_edit_post_link($postId, ''),
                'view_url' => get_permalink($postId),
                'matches' => [__('Inhalt', 'rrze-multisite-manager')],
            ];
            $seen[$postId . ':content'] = true;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Placeholder conditions are assembled internally and bound safely via $wpdb->prepare().
        $metaRows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DISTINCT p.ID, p.post_type, p.post_title, pm.meta_key
                FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
                WHERE p.post_type IN ('post', 'page')
                AND p.post_status NOT IN ('auto-draft', 'trash')
                AND (" . implode(' OR ', $metaConditions) . ')',
                ...$metaParams
            )
        );

        foreach ($metaRows as $metaRow) {
            $postId = (int)($metaRow->ID ?? 0);

            if ($postId <= 0) {
                continue;
            }

            if (!isset($results[$postId])) {
                $results[$postId] = [
                    'post_id' => $postId,
                    'post_type' => (string)($metaRow->post_type ?? ''),
                    'title' => trim((string)($metaRow->post_title ?? '')) !== '' ? (string)$metaRow->post_title : __('(ohne Titel)', 'rrze-multisite-manager'),
                    'edit_url' => get_edit_post_link($postId, ''),
                    'view_url' => get_permalink($postId),
                    'matches' => [],
                ];
            }

            if (!isset($seen[$postId . ':meta'])) {
                $results[$postId]['matches'][] = sprintf(
                    /* translators: %s: post meta key name. */
                    __('Metafeld: %s', 'rrze-multisite-manager'),
                    (string)($metaRow->meta_key ?? '')
                );
                $seen[$postId . ':meta'] = true;
            }
        }

        foreach ($results as $index => $resultRow) {
            $results[$index]['matches_label'] = implode(', ', (array)($resultRow['matches'] ?? []));
        }

        $codeMatches = $this->searchCurrentSiteCodeFileUsageMatches($fileUrl, $relativePath);

        foreach ($codeMatches as $codeMatch) {
            if (!is_array($codeMatch)) {
                continue;
            }

            $codeKey = 'code:' . (string)($codeMatch['key'] ?? md5(wp_json_encode($codeMatch)));
            $results[$codeKey] = $codeMatch;
            $results[$codeKey]['matches_label'] = implode(', ', (array)($results[$codeKey]['matches'] ?? []));
        }

        return array_values($results);
    }

    protected function searchCurrentSiteCodeFileUsageMatches(string $fileUrl, string $relativePath): array {
        $index = $this->getCurrentSiteAssetUsageIndex();
        $needles = $this->buildFileUsageCodeSearchNeedles($fileUrl, $relativePath);
        $results = [];
        $entry = [];
        $haystack = '';
        $needle = '';
        $matchLabel = '';

        if (empty($index) || empty($needles)) {
            return [];
        }

        foreach ($index as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $haystack = mb_strtolower((string)($entry['haystack'] ?? ''));

            if ($haystack === '') {
                continue;
            }

            foreach ($needles as $needle) {
                if ($needle === '' || mb_stripos($haystack, mb_strtolower($needle)) === false) {
                    continue;
                }

                $matchLabel = !empty($entry['match_label'])
                    ? (string)$entry['match_label']
                    : __('Code-Referenz', 'rrze-multisite-manager');

                $results[] = [
                    'key' => (string)($entry['key'] ?? md5($haystack)),
                    'post_id' => 0,
                    'post_type' => 'code',
                    'title' => (string)($entry['title'] ?? __('Code-Referenz', 'rrze-multisite-manager')),
                    'edit_url' => '',
                    'view_url' => '',
                    'matches' => [$matchLabel],
                ];
                break;
            }
        }

        return $results;
    }

    protected function getCurrentSiteAssetUsageIndex(): array {
        $siteId = get_current_blog_id();

        if ($siteId <= 0) {
            return [];
        }

        if (isset($this->currentSiteAssetUsageIndexCache[$siteId]) && is_array($this->currentSiteAssetUsageIndexCache[$siteId])) {
            return $this->currentSiteAssetUsageIndexCache[$siteId];
        }

        $this->currentSiteAssetUsageIndexCache[$siteId] = $this->buildCurrentSiteAssetUsageIndex();

        return $this->currentSiteAssetUsageIndexCache[$siteId];
    }

    protected function buildCurrentSiteAssetUsageIndex(): array {
        $results = [];
        $pluginFiles = $this->getCurrentSiteActivePluginFiles();
        $pluginCatalog = [];
        $pluginFile = '';
        $pluginName = '';
        $themeStylesheet = (string)get_option('stylesheet', '');
        $theme = null;
        $muPlugins = [];
        $muPath = '';
        $muName = '';

        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $pluginCatalog = get_plugins();

        foreach ($pluginFiles as $pluginFile) {
            $pluginName = !empty($pluginCatalog[$pluginFile]['Name']) && is_string($pluginCatalog[$pluginFile]['Name'])
                ? (string)$pluginCatalog[$pluginFile]['Name']
                : $pluginFile;
                $results = array_merge(
                    $results,
                    $this->buildAssetUsageIndexEntriesForFiles(
                        $this->getPluginAnalysisFiles($pluginFile),
                        /* translators: %s: plugin name. */
                        sprintf(__('Plugin: %s', 'rrze-multisite-manager'), $pluginName)
                    )
                );
        }

        if ($themeStylesheet !== '') {
            $theme = wp_get_theme($themeStylesheet);

            if ($theme instanceof \WP_Theme && $theme->exists()) {
                $results = array_merge(
                    $results,
                    $this->buildAssetUsageIndexEntriesForFiles(
                        $this->getThemeAnalysisFiles($themeStylesheet),
                        /* translators: %s: theme name. */
                        sprintf(__('Theme: %s', 'rrze-multisite-manager'), (string)$theme->get('Name'))
                    )
                );
            }
        }

        if (function_exists('get_mu_plugins')) {
            $muPlugins = get_mu_plugins();

            foreach ($muPlugins as $muPath => $muData) {
                $muName = !empty($muData['Name']) && is_string($muData['Name']) ? (string)$muData['Name'] : basename((string)$muPath);
                $results = array_merge(
                    $results,
                    $this->buildAssetUsageIndexEntriesForFiles(
                        $this->getStandaloneAnalysisFiles((string)$muPath),
                        /* translators: %s: MU plugin name. */
                        sprintf(__('MU-Plugin: %s', 'rrze-multisite-manager'), $muName)
                    )
                );
            }
        }

        return $results;
    }

    protected function getCurrentSiteActivePluginFiles(): array {
        $networkActivePlugins = (array)get_site_option('active_sitewide_plugins', []);
        $activePlugins = get_option('active_plugins', []);
        $pluginFiles = array_unique(
            array_merge(
                array_keys($networkActivePlugins),
                is_array($activePlugins) ? array_values(array_filter($activePlugins, 'is_string')) : []
            )
        );

        sort($pluginFiles, SORT_NATURAL | SORT_FLAG_CASE);

        return array_values($pluginFiles);
    }

    protected function getStandaloneAnalysisFiles(string $mainFilePath): array {
        $results = [];
        $baseDir = '';
        $iterator = null;
        $current = null;
        $pathname = '';

        if ($mainFilePath === '' || !is_file($mainFilePath)) {
            return [];
        }

        $results[] = $mainFilePath;
        $baseDir = dirname($mainFilePath);

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

    protected function buildAssetUsageIndexEntriesForFiles(array $files, string $providerLabel): array {
        $results = [];
        $filePath = '';
        $source = '';
        $relevantChunks = [];
        $chunk = '';
        $relativeFile = '';
        $providerPrefix = sanitize_title($providerLabel);

        foreach ($files as $filePath) {
            if (!is_string($filePath) || $filePath === '' || !is_readable($filePath)) {
                continue;
            }

            $source = (string)file_get_contents($filePath);

            if ($source === '') {
                continue;
            }

            $relevantChunks = $this->extractAssetUsageRelevantSourceChunks($source);

            if (empty($relevantChunks)) {
                continue;
            }

            $relativeFile = basename($filePath);

            foreach ($relevantChunks as $chunk) {
                $results[] = [
                    'key' => $providerPrefix . ':' . md5($filePath . '|' . $chunk),
                    'title' => $providerLabel,
                    'match_label' => sprintf(
                        /* translators: %s: file name where the asset registration or enqueue was found. */
                        __('Code-Registrierung/Enqueue in %s', 'rrze-multisite-manager'),
                        $relativeFile
                    ),
                    'haystack' => $chunk,
                ];
            }
        }

        return $results;
    }

    protected function extractAssetUsageRelevantSourceChunks(string $source): array {
        $chunks = [];
        $needles = [
            'wp_register_script',
            'wp_register_style',
            'wp_enqueue_script',
            'wp_enqueue_style',
            'wp_add_inline_script',
            'wp_add_inline_style',
            'register_block_type',
        ];
        $lowerSource = strtolower($source);
        $needle = '';
        $offset = 0;
        $position = false;
        $chunkEnd = 0;
        $chunk = '';
        $sourceLength = strlen($source);
        $maxChunkLength = 4000;

        foreach ($needles as $needle) {
            $offset = 0;

            while (($position = strpos($lowerSource, $needle, $offset)) !== false) {
                $chunkEnd = strpos($source, ';', $position);

                if ($chunkEnd === false || ($chunkEnd - $position) > $maxChunkLength) {
                    $chunkEnd = min($position + $maxChunkLength, $sourceLength);
                } else {
                    $chunkEnd++;
                }

                $chunk = trim(substr($source, $position, $chunkEnd - $position));

                if ($chunk !== '') {
                    $chunks[] = $chunk;
                }

                $offset = $position + strlen($needle);
            }
        }

        return array_values(array_unique($chunks));
    }

    protected function buildFileUsageCodeSearchNeedles(string $fileUrl, string $relativePath): array {
        $needles = $this->buildFileUsageSearchNeedles($fileUrl, $relativePath);
        $normalizedRelativePath = $this->normalizeRelativeUploadPath($relativePath);
        $segments = $normalizedRelativePath !== '' ? explode('/', $normalizedRelativePath) : [];
        $basename = $normalizedRelativePath !== '' ? basename($normalizedRelativePath) : '';
        $lastTwoSegments = '';

        if (count($segments) >= 2) {
            $lastTwoSegments = implode('/', array_slice($segments, -2));
        }

        foreach ([$basename, $lastTwoSegments] as $extraNeedle) {
            if (!is_string($extraNeedle) || trim($extraNeedle) === '' || mb_strlen($extraNeedle) < 6) {
                continue;
            }

            if (!in_array($extraNeedle, $needles, true)) {
                $needles[] = $extraNeedle;
            }
        }

        return $needles;
    }

    protected function deleteCurrentSiteOrphanFile(string $relativePath): array {
        $uploadDir = wp_get_upload_dir();
        $baseDir = is_array($uploadDir) && !empty($uploadDir['basedir']) ? (string)$uploadDir['basedir'] : '';
        $baseUrl = is_array($uploadDir) && !empty($uploadDir['baseurl']) ? (string)$uploadDir['baseurl'] : '';
        $normalizedBaseDir = trailingslashit(wp_normalize_path($baseDir));
        $normalizedRelativePath = $this->normalizeRelativeUploadPath($relativePath);
        $targetPath = $normalizedBaseDir . ltrim($normalizedRelativePath, '/');
        $attachmentIndex = [];
        $fileUrl = '';

        if ($baseDir === '' || !is_dir($baseDir)) {
            return [
                'deleted' => false,
                'message' => __('Das Upload-Verzeichnis wurde nicht gefunden.', 'rrze-multisite-manager'),
            ];
        }

        if ($normalizedRelativePath === '') {
            return [
                'deleted' => false,
                'message' => __('Ungültige Datei.', 'rrze-multisite-manager'),
            ];
        }

        if (!str_starts_with(wp_normalize_path($targetPath), $normalizedBaseDir)) {
            return [
                'deleted' => false,
                'message' => __('Der Dateipfad ist ungültig.', 'rrze-multisite-manager'),
            ];
        }

        if (!is_file($targetPath)) {
            return [
                'deleted' => false,
                'message' => __('Die Datei existiert nicht mehr.', 'rrze-multisite-manager'),
            ];
        }

        $attachmentIndex = $this->getCurrentSiteUploadAttachmentIndex();

        if (isset($attachmentIndex[$normalizedRelativePath])) {
            return [
                'deleted' => false,
                'message' => __('Diese Datei ist noch als Attachment registriert und darf hier nicht gelöscht werden.', 'rrze-multisite-manager'),
            ];
        }

        $fileUrl = trailingslashit($baseUrl) . ltrim($normalizedRelativePath, '/');

        if (!empty($this->searchCurrentSiteFileUsageMatches($fileUrl, $normalizedRelativePath))) {
            return [
                'deleted' => false,
                'message' => __('Diese Datei wird noch in Inhalten, Metafeldern oder in einer Code-Registrierung/Enqueue referenziert und wird deshalb nicht gelöscht.', 'rrze-multisite-manager'),
            ];
        }

        if (!is_writable($targetPath)) {
            return [
                'deleted' => false,
                'message' => __('Die Datei ist nicht beschreibbar.', 'rrze-multisite-manager'),
            ];
        }

        if (!@unlink($targetPath)) {
            return [
                'deleted' => false,
                'message' => __('Die Datei konnte nicht gelöscht werden.', 'rrze-multisite-manager'),
            ];
        }

        return [
            'deleted' => true,
            'message' => __('Die Datei wurde gelöscht.', 'rrze-multisite-manager'),
        ];
    }

    protected function buildFileUsageSearchNeedles(string $fileUrl, string $relativePath): array {
        $needles = [];
        $parsedPath = (string)wp_parse_url($fileUrl, PHP_URL_PATH);
        $normalizedRelativePath = $this->normalizeRelativeUploadPath($relativePath);
        $needle = '';

        foreach ([$fileUrl, rawurldecode($fileUrl), $parsedPath, rawurldecode($parsedPath), $normalizedRelativePath] as $needle) {
            if (!is_string($needle) || trim($needle) === '' || mb_strlen($needle) < 6) {
                continue;
            }

            if (!in_array($needle, $needles, true)) {
                $needles[] = $needle;
            }
        }

        return $needles;
    }

    protected function ensureDirectoryStat(array &$directoryStats, string $directoryPath, string $normalizedBaseDir): void {
        $relativePath = '.';

        if (isset($directoryStats[$directoryPath])) {
            return;
        }

        if ($directoryPath !== $normalizedBaseDir) {
            $relativePath = rtrim(ltrim(substr($directoryPath, strlen($normalizedBaseDir)), '/'), '/');
        }

        $directoryStats[$directoryPath] = [
            'relative_path' => $relativePath === '' ? '.' : $relativePath,
            'size_bytes' => 0,
            'file_count' => 0,
        ];
    }

    protected function getTopLevelDirectoryKey(string $relativePath): string {
        $segments = [];

        if ($relativePath === '') {
            return '.';
        }

        $segments = explode('/', $relativePath);

        return !empty($segments[0]) ? (string)$segments[0] : '.';
    }

    protected function addToTopLevelDirectoryStats(array &$topLevelDirectoryStats, string $topLevelKey, int $sizeBytes): void {
        if (!isset($topLevelDirectoryStats[$topLevelKey])) {
            $topLevelDirectoryStats[$topLevelKey] = [
                'path' => $topLevelKey === '.' ? __('Dateien im Wurzelverzeichnis', 'rrze-multisite-manager') : $topLevelKey,
                'size_bytes' => 0,
                'file_count' => 0,
            ];
        }

        $topLevelDirectoryStats[$topLevelKey]['size_bytes'] += max(0, $sizeBytes);
        $topLevelDirectoryStats[$topLevelKey]['file_count']++;
    }

    protected function pushLargestFileEntry(array &$largestFiles, array $entry, int $limit = self::STORAGE_LARGEST_FILES_LIMIT): void {
        $largestFiles[] = $entry;
        usort($largestFiles, [self::class, 'compareStorageEntries']);
        $largestFiles = array_slice($largestFiles, 0, max(1, $limit));
    }

    protected function buildTopStorageConsumers(array $directoryStats, array $largestFiles, string $normalizedBaseDir): array {
        $directoryEntries = [];
        $stats = [];
        $entries = [];

        foreach ($directoryStats as $path => $stats) {
            if ($path === $normalizedBaseDir || empty($stats['relative_path']) || (string)$stats['relative_path'] === '.') {
                continue;
            }

            $directoryEntries[] = [
                'type' => 'directory',
                'path' => (string)$stats['relative_path'],
                'size_bytes' => (int)($stats['size_bytes'] ?? 0),
                'size_label' => size_format((int)($stats['size_bytes'] ?? 0)),
                'file_count' => (int)($stats['file_count'] ?? 0),
            ];
        }

        usort($directoryEntries, [self::class, 'compareStorageEntries']);
        $directoryEntries = array_slice($directoryEntries, 0, 10);
        $entries = array_merge($directoryEntries, $largestFiles);
        usort($entries, [self::class, 'compareStorageEntries']);

        return array_slice($entries, 0, 10);
    }

    protected function finalizeTopLevelDirectoryStats(array $topLevelDirectoryStats, int $totalBytes): array {
        $results = array_values($topLevelDirectoryStats);
        $entry = [];

        usort($results, [self::class, 'compareStorageEntries']);

        foreach ($results as $index => $entry) {
            $results[$index]['size_label'] = size_format((int)($entry['size_bytes'] ?? 0));
            $results[$index]['percent'] = $totalBytes > 0
                ? (int)round((((int)($entry['size_bytes'] ?? 0)) / $totalBytes) * 100)
                : 0;
        }

        return $results;
    }

    protected function getNetworkStorageUsage(): array {
        $siteIds = get_sites([
            'fields' => 'ids',
            'number' => 0,
        ]);
        $siteId = 0;
        $storage = [];
        $state = [
            'items' => [],
            'total_used_bytes' => 0,
            'total_max_bytes' => 0,
            'has_unlimited_site' => false,
        ];

        foreach ($siteIds as $siteId) {
            switch_to_blog((int)$siteId);
            $storage = $this->getSiteStorageUsage();
            restore_current_blog();

            $this->accumulateDashboardBatchStorageUsage(
                $state,
                (int)$siteId,
                $this->getSiteNameById((int)$siteId),
                $storage
            );
        }

        return $this->finalizeDashboardBatchStorageUsage($state);
    }

    protected function getCurrentSiteOptionsGroupSummary(): array {
        $cached = $this->getCachedCurrentSiteDetailSection('options_summary');

        if (is_array($cached)) {
            return $cached;
        }

        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Options summary needs a full option-name scan and is stored in the section cache.
        $rows = $wpdb->get_results(
            "SELECT option_name
            FROM {$wpdb->options}
            ORDER BY option_name ASC"
        );
        $groups = [
            'all' => [
                'slug' => 'all',
                'label' => __('Alle Optionen', 'rrze-multisite-manager'),
                'count' => 0,
            ],
        ];
        $row = null;
        $optionName = '';
        $groupKey = '';

        foreach ($rows as $row) {
            $optionName = (string)($row->option_name ?? '');

            if ($optionName === '') {
                continue;
            }

            $groupKey = $this->getOptionGroupKey($optionName);

            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'slug' => $groupKey,
                    'label' => $this->getOptionGroupLabel($groupKey, $optionName),
                    'count' => 0,
                ];
            }

            $groups['all']['count']++;
            $groups[$groupKey]['count']++;
        }

        uasort($groups, [self::class, 'compareOptionGroups']);

        $groups = array_values($groups);

        $this->setCachedCurrentSiteDetailSection('options_summary', $groups);

        return $groups;
    }

    protected function getCurrentSiteOptionsByGroup(string $groupKey): array {
        $cached = $this->getCachedCurrentSiteDetailSection('options_group', $groupKey);

        if (is_array($cached)) {
            return $cached;
        }

        global $wpdb;

        $whereData = $this->getOptionGroupWhereData($groupKey);
        $limit = $this->getDetailSectionMaxRows() + 1;
        $rows = [];
        $options = [];
        $row = null;
        $optionName = '';
        $isTruncated = false;

        if (!empty($whereData['where'])) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Group-filtered option inspection is cached per site and group.
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT option_name, option_value, autoload
                    FROM {$wpdb->options}
                    WHERE " . (string)$whereData['where'] . '
                    ORDER BY option_name ASC
                    LIMIT %d',
                    ...array_merge((array)($whereData['params'] ?? []), [$limit])
                )
            );
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Full option inspection fallback is cached per site and group.
            $rows = $wpdb->get_results(
                "SELECT option_name, option_value, autoload
                FROM {$wpdb->options}
                ORDER BY option_name ASC"
            );
        }

        foreach ($rows as $row) {
            $optionName = (string)($row->option_name ?? '');

            if ($optionName === '') {
                continue;
            }

            if ($groupKey !== 'all' && $this->getOptionGroupKey($optionName) !== $groupKey) {
                continue;
            }

            $options[] = [
                'name' => $optionName,
                'value' => $this->formatOptionValue((string)($row->option_value ?? '')),
                'autoload' => (string)($row->autoload ?? ''),
                'is_core' => $this->isWordPressCoreOption($optionName),
            ];

            if (count($options) >= $this->getDetailSectionMaxRows()) {
                $isTruncated = true;
                break;
            }
        }

        $result = [
            'slug' => $groupKey,
            'options' => $options,
            'is_truncated' => $isTruncated,
            'limit' => $this->getDetailSectionMaxRows(),
        ];

        $this->setCachedCurrentSiteDetailSection('options_group', $result, $groupKey);

        return $result;
    }

    protected function getCurrentSiteProcessStats(): array {
        $cached = $this->getCachedCurrentSiteDetailSection('process_stats');

        if (is_array($cached)) {
            return $cached;
        }

        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Process stats are cached per detail section and require a direct transient count query.
        $transientCount = (int)$wpdb->get_var(
            "SELECT COUNT(option_name)
            FROM {$wpdb->options}
            WHERE option_name LIKE '\\_transient\\_%'
            AND option_name NOT LIKE '\\_transient\\_timeout\\_%'"
        );
        $cronArray = _get_cron_array();
        $cronEventCount = 0;
        $hooks = [];
        $events = [];

        if (is_array($cronArray)) {
            foreach ($cronArray as $hooks) {
                if (!is_array($hooks)) {
                    continue;
                }

                foreach ($hooks as $events) {
                    if (!is_array($events)) {
                        continue;
                    }

                    $cronEventCount += count($events);
                }
            }
        }

        $result = [
            'transients' => max(0, $transientCount),
            'cron_events' => max(0, $cronEventCount),
        ];

        $this->setCachedCurrentSiteDetailSection('process_stats', $result);

        return $result;
    }

    protected function getCurrentSiteTransients(): array {
        $cached = $this->getCachedCurrentSiteDetailSection('transients');

        if (is_array($cached)) {
            return $cached;
        }

        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transient listing is cached per site detail section.
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

            if (count($transients) >= $this->getDetailSectionMaxRows()) {
                break;
            }
        }

        $this->setCachedCurrentSiteDetailSection('transients', $transients);

        return $transients;
    }

    protected function getCurrentSiteCronEvents(): array {
        $cached = $this->getCachedCurrentSiteDetailSection('cron_events');

        if (is_array($cached)) {
            return $cached;
        }

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
        $results = array_slice($results, 0, $this->getDetailSectionMaxRows());

        $this->setCachedCurrentSiteDetailSection('cron_events', $results);

        return $results;
    }

    protected function getOptionGroupWhereData(string $groupKey): array {
        if ($groupKey === 'all' || $groupKey === 'wordpress-core' || $groupKey === 'misc') {
            return [
                'where' => '',
                'params' => [],
            ];
        }

        if ($groupKey === 'theme_mods') {
            return [
                'where' => 'option_name LIKE %s',
                'params' => ['theme_mods_%'],
            ];
        }

        if ($groupKey === 'widgets') {
            return [
                'where' => '(option_name LIKE %s OR option_name LIKE %s)',
                'params' => ['widget_%', 'sidebars_%'],
            ];
        }

        return [
            'where' => '(option_name LIKE %s OR option_name LIKE %s)',
            'params' => [$groupKey . '_%', $groupKey . '-%'],
        ];
    }

    protected function getDetailSectionMaxRows(): int {
        return self::DETAIL_SECTION_MAX_ROWS;
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
        do {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch selection for CPT deletion is necessary and bounded to 100 rows per loop.
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT ID
                    FROM {$wpdb->posts}
                    WHERE post_type = %s
                    LIMIT %d",
                    $postType,
                    100
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
        } while (!empty($rows));

        restore_current_blog();

        return $deleted;
    }

    public function deleteSiteOptionGroup(int $siteId, string $groupKey): int {
        global $wpdb;

        $deleted = 0;
        $rows = [];
        $row = null;
        $optionName = '';
        $whereData = [];

        if ($siteId <= 0 || trim($groupKey) === '' || in_array($groupKey, ['all', 'wordpress-core'], true)) {
            return 0;
        }

        switch_to_blog($siteId);
        $whereData = $this->getOptionGroupWhereData($groupKey);

        if (!empty($whereData['where'])) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Option group selection is a targeted admin delete action.
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT option_name
                    FROM {$wpdb->options}
                    WHERE " . (string)$whereData['where'] . '
                    ORDER BY option_name ASC',
                    ...((array)($whereData['params'] ?? []))
                )
            );
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Option group fallback selection is a targeted admin delete action.
            $rows = $wpdb->get_results(
                "SELECT option_name
                FROM {$wpdb->options}
                ORDER BY option_name ASC"
            );
        }

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
        $operationalStatus = (string)get_site_meta($siteId, 'rrze_msm_operational_status', true);

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

        if ($operationalStatus !== '') {
            $status[] = [
                'label' => $this->getOperationalStatusLabel($operationalStatus),
                'accent' => $this->getOperationalStatusAccent($operationalStatus),
            ];
        }

        return $status;
    }

    protected function getOperationalStatusLabel(string $status): string {
        $labels = [
            'provisioning' => __('Einrichtung läuft', 'rrze-multisite-manager'),
            'healthy' => __('Technisch erreichbar', 'rrze-multisite-manager'),
            'dns_missing' => __('DNS fehlt', 'rrze-multisite-manager'),
            'unreachable' => __('Technisch nicht erreichbar', 'rrze-multisite-manager'),
            'retired' => __('Außer Betrieb', 'rrze-multisite-manager'),
        ];

        return $labels[$status] ?? $status;
    }

    protected function getOperationalStatusAccent(string $status): string {
        $accents = [
            'provisioning' => 'info',
            'healthy' => 'positive',
            'dns_missing' => 'danger',
            'unreachable' => 'warning',
            'retired' => 'neutral',
        ];

        return $accents[$status] ?? 'neutral';
    }

    protected function getMonitoringStatusLabel(string $status): string {
        $labels = [
            'ok' => __('OK', 'rrze-multisite-manager'),
            'missing' => __('Fehlt', 'rrze-multisite-manager'),
            'error' => __('Fehler', 'rrze-multisite-manager'),
            'timeout' => __('Timeout', 'rrze-multisite-manager'),
            'unknown' => __('Unbekannt', 'rrze-multisite-manager'),
            'pending' => __('Ausstehend', 'rrze-multisite-manager'),
        ];

        return $labels[$status] ?? ($status !== '' ? $status : __('Nicht gesetzt', 'rrze-multisite-manager'));
    }

    protected function getSiteBuckets(): array {
        $sites = get_sites([
            'number' => 0,
        ]);
        $buckets = [
            'active_public' => 0,
            'active_private' => 0,
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

            if ((int)$site->public === 1) {
                $buckets['active_public']++;
                continue;
            }

            $buckets['active_private']++;
        }

        return $buckets;
    }

    protected function getOperationalStatusBuckets(): array {
        $siteIds = get_sites([
            'fields' => 'ids',
            'number' => 0,
        ]);
        $buckets = [
            'automatic' => 0,
            'healthy' => 0,
            'provisioning' => 0,
            'dns_missing' => 0,
            'unreachable' => 0,
            'retired' => 0,
        ];
        $siteId = 0;
        $status = '';

        foreach ($siteIds as $siteId) {
            $status = (string)get_site_meta((int)$siteId, 'rrze_msm_operational_status', true);

            if ($status === '' || !isset($buckets[$status])) {
                $buckets['automatic']++;
                continue;
            }

            $buckets[$status]++;
        }

        return $buckets;
    }

    protected function filterFormattedSitesByOperationalStatus(array $sites, string $status): array {
        $results = [];
        $site = [];

        foreach ($sites as $site) {
            if ((string)($site['operational_status'] ?? '') === $status) {
                $results[] = $site;
            }
        }

        return $results;
    }

    protected function getProblemSites(array $sites): array {
        $results = [];
        $site = [];
        $status = '';

        foreach ($sites as $site) {
            $status = (string)($site['operational_status'] ?? '');

            if (in_array($status, ['provisioning', 'dns_missing', 'unreachable'], true)) {
                $results[] = $site;
            }
        }

        return $results;
    }

    protected function getNewMonitoringAlerts(array $sites): array {
        $results = [];
        $site = [];
        $previousRun = (string)get_site_option('rrze_msm_monitoring_previous_run', '');
        $previousRunTimestamp = 0;
        $changedTimestamp = 0;
        $status = '';

        if ($previousRun === '' || $previousRun === '0000-00-00 00:00:00') {
            return [];
        }

        $previousRunTimestamp = (int)strtotime($previousRun . ' GMT');

        if ($previousRunTimestamp <= 0) {
            return [];
        }

        foreach ($sites as $site) {
            $status = (string)($site['operational_status'] ?? '');

            if (!in_array($status, ['dns_missing', 'unreachable'], true)) {
                continue;
            }

            if ((string)($site['operational_status_source'] ?? '') !== 'auto') {
                continue;
            }

            $changedTimestamp = (int)strtotime((string)($site['operational_status_changed_at'] ?? '') . ' GMT');

            if ($changedTimestamp <= $previousRunTimestamp) {
                continue;
            }

            $results[] = $site;
        }

        return $results;
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

    protected function getThemeSiteAggregate(): array {
        if (is_array($this->themeSiteAggregate)) {
            return $this->themeSiteAggregate;
        }

        $siteIds = get_sites([
            'fields' => 'ids',
            'number' => 0,
            'orderby' => 'id',
            'order' => 'ASC',
        ]);
        $aggregate = [
            'counts' => [],
            'usage_map' => [],
            'truncated' => [],
        ];
        $siteId = 0;
        $site = null;
        $stylesheet = '';
        $siteName = '';
        $siteUrl = '';

        foreach ($siteIds as $siteId) {
            $siteId = (int)$siteId;

            if ($siteId <= 0) {
                continue;
            }

            $site = get_site($siteId);

            if (!$site instanceof \WP_Site) {
                continue;
            }

            $siteName = $this->getSiteName($site);
            $siteUrl = get_home_url($siteId, '/');

            switch_to_blog($siteId);
            $stylesheet = (string)get_option('stylesheet', '');

            if ($stylesheet === '') {
                $stylesheet = (string)get_option('template', '');
            }

            restore_current_blog();

            $this->accumulateDashboardBatchThemeUsage($aggregate, $siteId, $siteName, $siteUrl, $stylesheet);
        }

        $this->themeSiteAggregate = $aggregate;

        return $this->themeSiteAggregate;
    }

    protected function getThemeSiteCounts(): array {
        $aggregate = $this->getThemeSiteAggregate();

        return (array)($aggregate['counts'] ?? []);
    }

    protected function getThemeSiteUsageMap(): array {
        $aggregate = $this->getThemeSiteAggregate();

        return (array)($aggregate['usage_map'] ?? []);
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

    protected function sortFormattedSitesByActivity(array $sites, string $direction): array {
        $results = array_values($sites);
        $thresholdTimestamp = $this->getInactiveThresholdTimestamp();
        $site = [];

        usort($results, [$this, 'compareSitesByActivity']);

        if ($direction === 'ASC') {
            $results = array_reverse($results);
        }

        foreach ($results as $index => $site) {
            $results[$index]['highlight_inactive'] = ((int)($site['last_updated_timestamp'] ?? 0) > 0 && (int)($site['last_updated_timestamp'] ?? 0) <= $thresholdTimestamp);
        }

        return $results;
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

    protected static function comparePluginTaxonomyRows(array $left, array $right): int {
        if ((string)($left['object_type'] ?? '') === (string)($right['object_type'] ?? '')) {
            return strcmp((string)($left['label'] ?? $left['slug'] ?? ''), (string)($right['label'] ?? $right['slug'] ?? ''));
        }

        return strcmp((string)($left['object_type'] ?? ''), (string)($right['object_type'] ?? ''));
    }

    protected static function compareImageSizeRows(array $left, array $right): int {
        return strcmp((string)($left['label'] ?? $left['slug'] ?? ''), (string)($right['label'] ?? $right['slug'] ?? ''));
    }

    protected static function compareStorageEntries(array $left, array $right): int {
        $leftSize = (int)($left['size_bytes'] ?? 0);
        $rightSize = (int)($right['size_bytes'] ?? 0);

        if ($leftSize === $rightSize) {
            return strcmp((string)($left['path'] ?? ''), (string)($right['path'] ?? ''));
        }

        return $rightSize <=> $leftSize;
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
            'operational_status_distribution',
            'network_storage_usage',
            'recent_sites',
            'site_overview',
            'archived_sites',
            'blocked_sites',
            'deleted_sites',
            'problem_sites',
            'new_monitoring_alerts',
            'provisioning_sites',
            'dns_missing_sites',
            'unreachable_sites',
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

    protected function isUsableDashboardCache(array $cached): bool {
        return !empty($cached['data']) && is_array($cached['data']) && $this->isCompleteDashboardData((array)$cached['data']);
    }

    protected function shouldRefreshDashboardCache(array $cached): bool {
        $generatedAt = (int)($cached['generated_at'] ?? 0);
        $dirty = !empty($cached['dirty']);
        $ttl = max(300, $this->config->getMetricsCacheTtl());
        $interval = $this->getMetricsRefreshIntervalSeconds();

        if ($dirty) {
            if ($generatedAt <= 0) {
                return true;
            }

            return ($generatedAt + $interval) <= time();
        }

        if ($generatedAt <= 0) {
            return true;
        }

        return ($generatedAt + $ttl) <= time();
    }

    protected function getStoredDashboardCache(): array {
        $cached = get_site_option($this->getCacheKey(), []);

        return is_array($cached) ? $cached : [];
    }

    protected function getEmptyDashboardDataPayload(): array {
        return [
            'summary' => [],
            'site_table_default_limit' => $this->getActivitySiteLimit(),
            'status_distribution' => [],
            'operational_status_distribution' => [],
            'network_storage_usage' => [],
            'recent_sites' => [],
            'site_overview' => [],
            'archived_sites' => [],
            'blocked_sites' => [],
            'deleted_sites' => [],
            'problem_sites' => [],
            'new_monitoring_alerts' => [],
            'provisioning_sites' => [],
            'dns_missing_sites' => [],
            'unreachable_sites' => [],
            'themes' => [],
            'theme_usage' => [],
            'editor_usage' => [],
            'plugin_usage' => [],
            'inactive_themes' => [],
            'recently_updated_sites' => [],
            'inactive_sites' => [],
        ];
    }

    protected function markAllCachesDirty(bool $scheduleRefresh = true): void {
        $this->markDashboardCacheDirty($scheduleRefresh);
        $this->bumpDetailCacheVersion();
    }

    protected function markDashboardCacheDirty(bool $scheduleRefresh = true): void {
        $cached = $this->getStoredDashboardCache();

        if (!is_array($cached)) {
            $cached = [];
        }

        $cached['dirty'] = true;
        update_site_option($this->getCacheKey(), $cached);

        if ($scheduleRefresh) {
            $this->scheduleDashboardRefresh();
        }
    }

    protected function registerInvalidationHooks(): void {
        add_action('wpmu_new_blog', [$this, 'invalidateSiteAndGlobalCachesFromHook'], 20, 6);
        add_action('archive_blog', [$this, 'invalidateSiteCachesFromHook'], 20, 1);
        add_action('unarchive_blog', [$this, 'invalidateSiteCachesFromHook'], 20, 1);
        add_action('make_spam_blog', [$this, 'invalidateSiteCachesFromHook'], 20, 1);
        add_action('make_ham_blog', [$this, 'invalidateSiteCachesFromHook'], 20, 1);
        add_action('delete_blog', [$this, 'invalidateSiteAndGlobalCachesFromHook'], 20, 2);
        add_action('undelete_blog', [$this, 'invalidateSiteAndGlobalCachesFromHook'], 20, 1);
        add_action('mature_blog', [$this, 'invalidateSiteCachesFromHook'], 20, 1);
        add_action('unmature_blog', [$this, 'invalidateSiteCachesFromHook'], 20, 1);
        add_action('activated_plugin', [$this, 'invalidateCurrentSiteAndGlobalCaches'], 20, 2);
        add_action('deactivated_plugin', [$this, 'invalidateCurrentSiteAndGlobalCaches'], 20, 2);
        add_action('switch_theme', [$this, 'invalidateCurrentSiteAndGlobalCaches'], 20, 3);
        add_action('upgrader_process_complete', [$this, 'invalidateCaches'], 20, 2);
        add_action('save_post', [$this, 'invalidateCurrentSiteCaches'], 20, 3);
        add_action('deleted_post', [$this, 'invalidateCurrentSiteCaches'], 20, 2);
        add_action('add_attachment', [$this, 'invalidateCurrentSiteCaches'], 20, 1);
        add_action('delete_attachment', [$this, 'invalidateCurrentSiteCaches'], 20, 1);
        add_action('user_register', [$this, 'invalidateDashboardCacheOnly'], 20, 1);
        add_action('deleted_user', [$this, 'invalidateCaches'], 20, 1);
        add_action('add_user_to_blog', [$this, 'invalidateUserSiteCachesFromHook'], 20, 3);
        add_action('remove_user_from_blog', [$this, 'invalidateUserRemovalSiteCachesFromHook'], 20, 2);
        add_action('set_user_role', [$this, 'invalidateCurrentSiteCaches'], 20, 3);
        add_action('update_option_blogname', [$this, 'invalidateCurrentSiteCaches'], 20, 3);
        add_action('update_option_admin_email', [$this, 'invalidateCurrentSiteCaches'], 20, 3);
        add_action('update_option_stylesheet', [$this, 'invalidateCurrentSiteAndGlobalCaches'], 20, 3);
        add_action('update_option_template', [$this, 'invalidateCurrentSiteAndGlobalCaches'], 20, 3);
        add_action('update_option_active_plugins', [$this, 'invalidateCurrentSiteAndGlobalCaches'], 20, 3);
        add_action('add_option_active_plugins', [$this, 'invalidateCurrentSiteAndGlobalCaches'], 20, 2);
        add_action('delete_option_active_plugins', [$this, 'invalidateCurrentSiteAndGlobalCaches'], 20, 1);
        add_action('update_site_option_active_sitewide_plugins', [$this, 'invalidateCaches'], 20, 4);
        add_action('update_site_option_allowedthemes', [$this, 'invalidateCaches'], 20, 4);
        add_action('update_site_option_blog_upload_space', [$this, 'invalidateCaches'], 20, 4);
    }

    protected function scheduleDashboardRefresh(int $delay = 60): void {
        $scheduledAt = wp_next_scheduled(self::DASHBOARD_REFRESH_HOOK);
        $cached = $this->getStoredDashboardCache();
        $generatedAt = (int)($cached['generated_at'] ?? 0);
        $baseTimestamp = $generatedAt > 0 ? $generatedAt : time();
        $minimumRunTimestamp = $baseTimestamp + $this->getMetricsRefreshIntervalSeconds();
        $requestedTimestamp = time() + max(5, $delay);
        $scheduleTimestamp = max($requestedTimestamp, $minimumRunTimestamp);

        if ($scheduledAt && (int)$scheduledAt > 0) {
            return;
        }

        wp_schedule_single_event($scheduleTimestamp, self::DASHBOARD_REFRESH_HOOK);
    }

    protected function getMetricsRefreshIntervalMinutes(): int {
        $options = get_site_option($this->config->getOptionName(), []);
        $interval = 60;

        if (is_array($options) && isset($options['monitoring_metrics_interval_minutes'])) {
            $interval = (int)$options['monitoring_metrics_interval_minutes'];
        }

        return max(60, min(10080, $interval));
    }

    protected function getMetricsRefreshIntervalSeconds(): int {
        return $this->getMetricsRefreshIntervalMinutes() * MINUTE_IN_SECONDS;
    }

    protected function acquireDashboardRefreshLock(): bool {
        if ($this->isDashboardRefreshLocked()) {
            return false;
        }

        return (bool)set_site_transient(self::DASHBOARD_LOCK_KEY, time(), self::DASHBOARD_LOCK_TTL);
    }

    protected function releaseDashboardRefreshLock(): void {
        delete_site_transient(self::DASHBOARD_LOCK_KEY);
    }

    protected function isDashboardRefreshLocked(): bool {
        return (int)get_site_transient(self::DASHBOARD_LOCK_KEY) > 0;
    }

    protected function isDashboardRefreshStale(bool $isRunning, int $batchTotal, int $checkedSites, int $nextRunTimestamp, int $currentDurationSeconds): bool {
        if ($isRunning && $currentDurationSeconds > (self::DASHBOARD_LOCK_TTL + 120)) {
            return true;
        }

        if (!$isRunning && $batchTotal > 0 && $checkedSites < $batchTotal && $nextRunTimestamp > 0 && $nextRunTimestamp < (time() - 300)) {
            return true;
        }

        return false;
    }

    protected function bumpDetailCacheVersion(): int {
        $version = $this->getNextCacheVersion((int)get_site_option(self::DETAIL_CACHE_VERSION_OPTION, 0));
        update_site_option(self::DETAIL_CACHE_VERSION_OPTION, $version);
        $this->themeSiteAggregate = null;
        return $version;
    }

    protected function getDetailCacheVersion(): int {
        $version = (int)get_site_option(self::DETAIL_CACHE_VERSION_OPTION, 0);

        if ($version <= 0) {
            $version = $this->bumpDetailCacheVersion();
        }

        return $version;
    }

    protected function getDetailCacheTtl(): int {
        return self::DETAIL_CACHE_TTL;
    }

    protected function getSiteDetailsCacheKey(int $siteId, array $load = []): string {
        return 'rrze_msm_site_details_' . $this->getDetailCacheVersion() . '_' . $this->getSiteDetailCacheVersion($siteId) . '_' . md5((string)$siteId . '|' . wp_json_encode($load));
    }

    protected function getSiteDetailSectionCacheKey(int $siteId, string $section, string $suffix = ''): string {
        return 'rrze_msm_site_detail_section_' . $this->getDetailCacheVersion() . '_' . $this->getSiteDetailCacheVersion($siteId) . '_' . md5($siteId . '|' . $section . '|' . $suffix);
    }

    protected function getCachedCurrentSiteDetailSection(string $section, string $suffix = ''): mixed {
        $siteId = get_current_blog_id();

        if ($siteId <= 0) {
            return null;
        }

        return get_site_transient($this->getSiteDetailSectionCacheKey($siteId, $section, $suffix));
    }

    protected function setCachedCurrentSiteDetailSection(string $section, mixed $value, string $suffix = ''): void {
        $siteId = get_current_blog_id();

        if ($siteId <= 0) {
            return;
        }

        set_site_transient(
            $this->getSiteDetailSectionCacheKey($siteId, $section, $suffix),
            $value,
            self::DETAIL_CACHE_TTL
        );
    }

    protected function getPluginDetailsCacheKey(string $pluginFile): string {
        return 'rrze_msm_plugin_details_' . $this->getDetailCacheVersion() . '_' . md5($pluginFile . '|' . $this->getPluginCacheFingerprint($pluginFile));
    }

    protected function getThemeDetailsCacheKey(string $stylesheet): string {
        return 'rrze_msm_theme_details_' . $this->getDetailCacheVersion() . '_' . md5($stylesheet . '|' . $this->getThemeCacheFingerprint($stylesheet));
    }

    protected function getPluginCacheFingerprint(string $pluginFile): string {
        $mainFilePath = $this->getPluginAbsolutePath($pluginFile);
        $baseDir = $mainFilePath !== '' ? dirname($mainFilePath) : '';
        $fingerprint = [
            $pluginFile,
            $mainFilePath !== '' && file_exists($mainFilePath) ? (string)filemtime($mainFilePath) : '0',
            $baseDir !== '' && file_exists($baseDir) ? (string)filemtime($baseDir) : '0',
        ];

        return implode('|', $fingerprint);
    }

    protected function getThemeCacheFingerprint(string $stylesheet): string {
        $themePath = $this->getThemeAbsolutePath($stylesheet);
        $mainPath = $this->getThemeMainFilePath($stylesheet);
        $fingerprint = [
            $stylesheet,
            $themePath !== '' && file_exists($themePath) ? (string)filemtime($themePath) : '0',
            $mainPath !== '' && file_exists($mainPath) ? (string)filemtime($mainPath) : '0',
        ];

        return implode('|', $fingerprint);
    }

    protected function getCacheKey(): string {
        return self::CACHE_KEY . (string)get_current_network_id();
    }

    protected function getDashboardRefreshBatchState(): array {
        $state = get_site_option(self::DASHBOARD_BATCH_STATE_OPTION, []);

        return is_array($state) ? $state : [];
    }

    protected function saveDashboardRefreshBatchState(array $state): void {
        update_site_option(self::DASHBOARD_BATCH_STATE_OPTION, $state);
    }

    protected function resetDashboardRefreshBatchState(): void {
        update_site_option(self::DASHBOARD_BATCH_OFFSET_OPTION, 0);
        update_site_option(self::DASHBOARD_BATCH_TOTAL_OPTION, 0);
        delete_site_option(self::DASHBOARD_BATCH_STATE_OPTION);
    }

    protected function getInitialDashboardRefreshBatchState(): array {
        $siteCount = $this->getDashboardSiteCount();

        return [
            'started_at' => time(),
            'site_overview' => [],
            'network_storage_usage' => [
                'items' => [],
                'total_used_bytes' => 0,
                'total_max_bytes' => 0,
                'has_unlimited_site' => false,
            ],
            'theme_aggregate' => [
                'counts' => [],
                'usage_map' => [],
                'truncated' => [],
            ],
            'plugin_usage' => [
                'total_sites' => $siteCount,
                'stats' => $this->createBasePluginUsageStats(),
                'missing_plugins' => [],
            ],
            'editor_usage' => [
                'total_sites' => $siteCount,
                'classic_sites' => 0,
                'block_sites' => 0,
                'classic_everywhere' => isset(((array)get_site_option('active_sitewide_plugins', []))['classic-editor/classic-editor.php']),
            ],
        ];
    }

    protected function finalizeDashboardRefreshBatchState(array $state): void {
        $data = $this->buildDashboardDataPayloadFromBatchState($state);
        $generatedAt = time();
        $startedAt = (int)($state['started_at'] ?? $generatedAt);

        update_site_option(
            $this->getCacheKey(),
            [
                'data' => $data,
                'generated_at' => $generatedAt,
                'started_at' => $startedAt,
                'duration_seconds' => max(0, $generatedAt - $startedAt),
                'dirty' => false,
            ]
        );
    }

    protected function createBasePluginUsageStats(): array {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        $availablePlugins = get_plugins();
        $networkActivePlugins = (array)get_site_option('active_sitewide_plugins', []);
        $pluginUpdates = get_site_transient('update_plugins');
        $pluginStats = [];
        $pluginFile = '';
        $pluginData = [];
        $updateItem = null;

        foreach ($availablePlugins as $pluginFile => $pluginData) {
            $updateItem = is_object($pluginUpdates) && !empty($pluginUpdates->response[$pluginFile]) && is_object($pluginUpdates->response[$pluginFile])
                ? $pluginUpdates->response[$pluginFile]
                : null;
            $pluginStats[$pluginFile] = [
                'file' => $pluginFile,
                'site_count' => 0,
                'active_sites' => [],
                'active_sites_truncated' => false,
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

        return $pluginStats;
    }

    protected function accumulatePluginUsageStats(array &$pluginStats, array &$missingPlugins, int $siteId, string $siteName, string $siteUrl, array $sitePluginFiles, array $networkActivePlugins = []): void {
        $pluginFile = '';

        foreach ($sitePluginFiles as $pluginFile) {
            if (!isset($pluginStats[$pluginFile])) {
                $missingPlugins[$pluginFile] = $this->accumulateMissingPluginUsage(
                    (array)($missingPlugins[$pluginFile] ?? []),
                    $pluginFile,
                    $siteId,
                    $siteName,
                    $siteUrl
                );
                continue;
            }

            $pluginStats[$pluginFile]['site_count']++;

            if (count((array)$pluginStats[$pluginFile]['active_sites']) < $this->getDashboardActiveSitePreviewLimit()) {
                $pluginStats[$pluginFile]['active_sites'][] = [
                    'id' => $siteId,
                    'name' => $siteName,
                    'url' => $siteUrl,
                ];
            } else {
                $pluginStats[$pluginFile]['active_sites_truncated'] = true;
            }
        }
    }

    protected function accumulateMissingPluginUsage(array $missingPlugin, string $pluginFile, int $siteId, string $siteName, string $siteUrl): array {
        if (empty($missingPlugin)) {
            $missingPlugin = [
                'file' => $pluginFile,
                'site_count' => 0,
                'active_sites' => [],
                'active_sites_truncated' => false,
            ];
        }

        $missingPlugin['site_count'] = (int)($missingPlugin['site_count'] ?? 0) + 1;

        if (count((array)$missingPlugin['active_sites']) < $this->getDashboardActiveSitePreviewLimit()) {
            $missingPlugin['active_sites'][] = [
                'id' => $siteId,
                'name' => $siteName,
                'url' => $siteUrl,
            ];
        } else {
            $missingPlugin['active_sites_truncated'] = true;
        }

        $missingPlugin['active_sites'] = $this->sortPluginActiveSites((array)$missingPlugin['active_sites']);

        return $missingPlugin;
    }

    protected function finalizePluginUsageStats(array $pluginStats, array $missingPlugins, int $totalSites): array {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        $pluginFile = '';
        $pluginData = [];
        $availablePluginCount = count(get_plugins());

        foreach ($pluginStats as $pluginFile => $pluginData) {
            $pluginStats[$pluginFile]['active_sites'] = $this->sortPluginActiveSites((array)($pluginData['active_sites'] ?? []));
            $pluginStats[$pluginFile]['active_sites_truncated'] = !empty($pluginData['active_sites_truncated']);
        }

        uasort($pluginStats, [self::class, 'comparePluginUsage']);

        return [
            'summary' => [
                'available_plugins' => $availablePluginCount,
                'network_active_plugins' => count((array)get_site_option('active_sitewide_plugins', [])),
                'locally_used_plugins' => $this->countLocallyUsedPlugins($pluginStats),
                'missing_plugin_entries' => count($missingPlugins),
                'total_sites' => $totalSites,
            ],
            'plugins' => array_values($pluginStats),
            'missing_plugins' => array_values($missingPlugins),
            'distribution' => $this->buildPluginUsageDistribution($pluginStats),
            'inactive_plugins' => array_values(
                array_filter(
                    $pluginStats,
                    [self::class, 'isUnusedPlugin']
                )
            ),
        ];
    }

    protected function accumulateDashboardBatchPluginUsage(array &$pluginUsageState, int $siteId, string $siteName, string $siteUrl, array $sitePluginFiles, array $networkActivePlugins = []): void {
        if (!isset($pluginUsageState['stats']) || !is_array($pluginUsageState['stats'])) {
            $pluginUsageState['stats'] = $this->createBasePluginUsageStats();
        }

        if (!isset($pluginUsageState['total_sites'])) {
            $pluginUsageState['total_sites'] = 0;
        }

        if (!isset($pluginUsageState['missing_plugins']) || !is_array($pluginUsageState['missing_plugins'])) {
            $pluginUsageState['missing_plugins'] = [];
        }

        $this->accumulatePluginUsageStats($pluginUsageState['stats'], $pluginUsageState['missing_plugins'], $siteId, $siteName, $siteUrl, $sitePluginFiles, $networkActivePlugins);
    }

    protected function accumulateDashboardBatchThemeUsage(array &$themeAggregate, int $siteId, string $siteName, string $siteUrl, string $stylesheet): void {
        if ($stylesheet === '') {
            return;
        }

        if (!isset($themeAggregate['counts'][$stylesheet])) {
            $themeAggregate['counts'][$stylesheet] = 0;
        }

        if (!isset($themeAggregate['usage_map'][$stylesheet]) || !is_array($themeAggregate['usage_map'][$stylesheet])) {
            $themeAggregate['usage_map'][$stylesheet] = [];
        }

        if (!isset($themeAggregate['truncated'][$stylesheet])) {
            $themeAggregate['truncated'][$stylesheet] = false;
        }

        $themeAggregate['counts'][$stylesheet]++;

        if (count($themeAggregate['usage_map'][$stylesheet]) < $this->getDashboardActiveSitePreviewLimit()) {
            $themeAggregate['usage_map'][$stylesheet][] = [
                'id' => $siteId,
                'name' => $siteName,
                'url' => $siteUrl,
            ];
        } else {
            $themeAggregate['truncated'][$stylesheet] = true;
        }
    }

    protected function getDashboardActiveSitePreviewLimit(): int {
        return self::DASHBOARD_ACTIVE_SITE_PREVIEW_LIMIT;
    }

    protected function getDashboardSiteCount(): int {
        if ($this->dashboardSiteCount === null) {
            $this->dashboardSiteCount = $this->countSites();
        }

        return $this->dashboardSiteCount;
    }

    protected function accumulateDashboardBatchStorageUsage(array &$storageState, int $siteId, string $siteName, array $storage): void {
        $usedBytes = (int)($storage['used_bytes'] ?? 0);
        $maxBytes = (int)($storage['max_bytes'] ?? 0);

        if (!isset($storageState['items']) || !is_array($storageState['items'])) {
            $storageState['items'] = [];
        }

        $storageState['total_used_bytes'] = (int)($storageState['total_used_bytes'] ?? 0) + max(0, $usedBytes);
        $storageState['total_max_bytes'] = (int)($storageState['total_max_bytes'] ?? 0) + max(0, $maxBytes);

        if (!empty($storage['is_unlimited'])) {
            $storageState['has_unlimited_site'] = true;
        }

        if ($usedBytes <= 0) {
            return;
        }

        $storageState['items'][] = [
            'label' => $siteName,
            'value' => $usedBytes,
            'value_label' => size_format($usedBytes),
            'site_id' => $siteId,
        ];
    }

    protected function finalizeDashboardBatchStorageUsage(array $storageState): array {
        $items = is_array($storageState['items'] ?? null) ? $storageState['items'] : [];
        $totalUsedBytes = (int)($storageState['total_used_bytes'] ?? 0);
        $totalMaxBytes = (int)($storageState['total_max_bytes'] ?? 0);
        $hasUnlimitedSite = !empty($storageState['has_unlimited_site']);
        $freeBytes = 0;
        $item = [];
        $index = 0;
        $percentBase = 0;
        $mode = 'capacity';

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
                    'accent' => 'free-storage',
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

    protected function accumulateDashboardBatchEditorUsage(array &$editorState, array $sitePluginFiles, array $networkActivePlugins = []): void {
        $classicEverywhere = !empty($editorState['classic_everywhere']) || isset($networkActivePlugins['classic-editor/classic-editor.php']);

        if ($classicEverywhere || in_array('classic-editor/classic-editor.php', $sitePluginFiles, true)) {
            $editorState['classic_sites'] = (int)($editorState['classic_sites'] ?? 0) + 1;
            return;
        }

        $editorState['block_sites'] = (int)($editorState['block_sites'] ?? 0) + 1;
    }

    protected function finalizeDashboardBatchEditorUsage(array $editorState): array {
        $totalSites = (int)($editorState['total_sites'] ?? 0);
        $classicSites = (int)($editorState['classic_sites'] ?? 0);
        $blockSites = (int)($editorState['block_sites'] ?? 0);

        if ($totalSites <= 0) {
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

    protected function invalidateSiteDetailCaches(int $siteId): void {
        if ($siteId <= 0) {
            return;
        }

        update_site_meta($siteId, self::SITE_DETAIL_CACHE_VERSION_META, $this->getNextCacheVersion($this->getStoredSiteDetailCacheVersion($siteId)));
    }

    protected function getSiteDetailCacheVersion(int $siteId): int {
        $version = $this->getStoredSiteDetailCacheVersion($siteId);

        if ($version <= 0) {
            $version = $this->getNextCacheVersion(0);
            update_site_meta($siteId, self::SITE_DETAIL_CACHE_VERSION_META, $version);
        }

        return $version;
    }

    protected function getStoredSiteDetailCacheVersion(int $siteId): int {
        if ($siteId <= 0) {
            return 0;
        }

        return (int)get_site_meta($siteId, self::SITE_DETAIL_CACHE_VERSION_META, true);
    }

    protected function getNextCacheVersion(int $currentVersion): int {
        return max(time(), $currentVersion + 1);
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
