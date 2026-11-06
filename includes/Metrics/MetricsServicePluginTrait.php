<?php

namespace RRZE\MultisiteManager\Metrics;

defined('ABSPATH') || exit;

trait MetricsServicePluginTrait {

    public function getPluginDetails(string $pluginFile, bool $includeSourceAnalysis = false): array {
        $availablePlugins = [];
        $cacheKey = '';
        $cached = null;
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
        $translationLanguages = [];

        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        $availablePlugins = get_plugins();

        if (!isset($availablePlugins[$pluginFile]) || !is_array($availablePlugins[$pluginFile])) {
            return [];
        }

        $cacheKey = $this->getPluginDetailsCacheKey($pluginFile, $includeSourceAnalysis);
        $cached = get_site_transient($cacheKey);

        if (is_array($cached) && !empty($cached)) {
            return $cached;
        }

        $pluginData = $availablePlugins[$pluginFile];
        $dashboardData = $this->getDashboardData();
        $pluginUsage = is_array($dashboardData['plugin_usage']['plugins'] ?? null) ? $dashboardData['plugin_usage']['plugins'] : [];
        $pluginUsageItem = $this->findPluginUsageItem($pluginUsage, $pluginFile);
        $updateItem = $this->getPluginUpdateItem($pluginFile);
        $analysis = $this->pluginThemeUsageMetrics->getPluginSourceAnalysis($pluginFile);
        $sourceAnalysisCompleted = $this->pluginThemeUsageMetrics->hasPluginSourceAnalysis($pluginFile);
        $supplementary = $this->getPluginSupplementaryData($pluginFile);
        $installTimestamp = $this->getPluginInstallTimestamp($pluginFile);
        $modifiedTimestamp = $this->getPluginModifiedTimestamp($pluginFile);
        $translationLanguages = $this->getTranslationLanguages(
            $pluginFile,
            !empty($pluginData['TextDomain']) && is_string($pluginData['TextDomain']) ? (string)$pluginData['TextDomain'] : '',
            'plugin'
        );
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

        // Usage-index rows intentionally contain only relation data. Complete
        // the detail header from WordPress' plugin header even when a row was
        // already found, otherwise the visible plugin name can be empty.
        if (trim((string)($pluginUsageItem['name'] ?? '')) === '') {
            $pluginUsageItem['name'] = (string)($pluginData['Name'] ?? $pluginFile);
        }

        // Detail pages read the central relation index. They never iterate
        // over all websites or read every site's active_plugins option.
        $pluginUsageItem['active_sites'] = $this->getActiveSitesForPlugin($pluginFile);
        $pluginUsageItem['site_count'] = count($pluginUsageItem['active_sites']);
        $pluginUsageItem['active_sites_truncated'] = false;
        $pluginUsageItem['usage_data_available'] = $this->pluginThemeUsageMetrics->hasCompletedSnapshot();
        $pluginUsageItem['usage_monitoring_url'] = admin_url('admin.php?page=rrze-multisite-manager-monitoring&monitoring_tab=network');
        $status = $this->getPluginStatus($pluginUsageItem);

        if ((string)($pluginUsageItem['author'] ?? '') === '' && !empty($supplementary['author']['name'])) {
            $pluginUsageItem['author'] = (string)$supplementary['author']['name'];
        }

        if ((string)($pluginUsageItem['author_url'] ?? '') === '' && !empty($supplementary['author']['url'])) {
            $pluginUsageItem['author_url'] = (string)$supplementary['author']['url'];
        }

        if ((string)($pluginUsageItem['description'] ?? '') === '' && !empty($supplementary['description'])) {
            $pluginUsageItem['description'] = (string)$supplementary['description'];
        }

        $pluginData = array_merge(
            $pluginUsageItem,
            [
                'status' => $status,
                'author_email' => (string)($supplementary['author']['email'] ?? ''),
                'compatibility' => (array)($supplementary['compatibility'] ?? []),
                'supports' => (array)($supplementary['supports'] ?? []),
                'translation_languages' => $translationLanguages,
                'license' => (array)($supplementary['license'] ?? []),
                'tags' => (array)($supplementary['tags'] ?? []),
                'repository' => (array)($supplementary['repository'] ?? []),
                'extended_description' => (string)($supplementary['description'] ?? ''),
                'metadata_sources' => (array)($supplementary['sources'] ?? []),
                'readme_markdown' => (string)($supplementary['readme_markdown'] ?? ''),
                'source_analysis_completed' => $sourceAnalysisCompleted,
                'shortcodes' => (array)($analysis['shortcodes'] ?? []),
                'blocks' => (array)($analysis['blocks'] ?? []),
                'block_patterns' => (array)($analysis['block_patterns'] ?? []),
                'custom_post_types' => (array)($analysis['custom_post_types'] ?? []),
                'taxonomies' => (array)($analysis['taxonomies'] ?? []),
                'image_sizes' => (array)($analysis['image_sizes'] ?? []),
                'provided_hooks' => (array)($analysis['provided_hooks'] ?? []),
                'installation_date_label' => $installTimestamp > 0 ? $this->formatTimestamp($installTimestamp) : __('Not available.', 'rrze-multisite-manager'),
                'last_release_date_label' => $modifiedTimestamp > 0 ? $this->formatTimestamp($modifiedTimestamp) : __('Not available.', 'rrze-multisite-manager'),
                'main_file_path' => $this->getPluginAbsolutePath($pluginFile),
            ]
        );

        set_site_transient($cacheKey, $pluginData, $this->getDetailCacheTtl());

        return $pluginData;
    }

    protected function getPluginUsage(): array {
        $pluginStats = $this->createBasePluginUsageStats();
        $missingPlugins = [];
        $counts = $this->pluginThemeUsageMetrics->getPluginCounts();

        foreach ($counts as $pluginFile => $count) {
            if (isset($pluginStats[$pluginFile])) {
                $pluginStats[$pluginFile]['site_count'] = $count;
                continue;
            }
            $missingPlugins[$pluginFile] = [
                'file' => $pluginFile,
                'site_count' => $count,
                'active_sites' => [],
                'active_sites_truncated' => $count > 0,
            ];
        }

        return $this->finalizePluginUsageStats($pluginStats, $missingPlugins, $this->getDashboardSiteCount());
    }

    /**
     * Returns the complete current list of websites using a plugin.
     *
     * This is intentionally used only for a detail view. Embedding a complete
     * site list in every row of the dashboard metrics would grow that cached
     * network-wide payload disproportionately on large multisites.
     */
    protected function getActiveSitesForPlugin(string $pluginFile): array {
        return $this->getSiteOverviewRowsForIds($this->pluginThemeUsageMetrics->getPluginSiteIds($pluginFile));
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
                'label' => __('Network-active', 'rrze-multisite-manager'),
                'accent' => 'info',
            ];
        } elseif ((int)($plugin['site_count'] ?? 0) > 0) {
            $items[] = [
                'label' => __('Active on websites', 'rrze-multisite-manager'),
                'accent' => 'active',
            ];
        } else {
            $items[] = [
                'label' => __('Not activated', 'rrze-multisite-manager'),
                'accent' => 'archive',
            ];
        }

        if (!empty($plugin['update_available']) && !empty($plugin['update_version'])) {
            $items[] = [
                'label' => sprintf(
                    /* translators: %s: available plugin update version. */
                    __('Update %s available', 'rrze-multisite-manager'),
                    (string)$plugin['update_version']
                ),
                'accent' => 'info',
            ];
        }

        return $items;
    }
}
