<?php

namespace RRZE\MultisiteManager;

defined('ABSPATH') || exit;

trait MetricsServicePluginTrait {
    public function searchPlugins(string $searchTerm, int $limit = 20): array {
        $availablePlugins = [];
        $results = [];
        $pluginFile = '';
        $pluginData = [];
        $searchNeedle = trim(mb_strtolower($searchTerm));
        $haystack = '';

        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        if ($searchNeedle === '' || mb_strlen($searchNeedle) < 3) {
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

    public function getPluginDetails(string $pluginFile): array {
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

        $cacheKey = $this->getPluginDetailsCacheKey($pluginFile);
        $cached = get_site_transient($cacheKey);

        if (is_array($cached) && !empty($cached)) {
            return $cached;
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
                'shortcodes' => $analysis['shortcodes'],
                'blocks' => $analysis['blocks'],
                'block_patterns' => $analysis['block_patterns'],
                'custom_post_types' => $analysis['custom_post_types'],
                'taxonomies' => $analysis['taxonomies'],
                'image_sizes' => $analysis['image_sizes'],
                'provided_hooks' => $analysis['provided_hooks'],
                'installation_date_label' => $installTimestamp > 0 ? $this->formatTimestamp($installTimestamp) : __('Nicht verfügbar.', 'rrze-multisite-manager'),
                'last_release_date_label' => $modifiedTimestamp > 0 ? $this->formatTimestamp($modifiedTimestamp) : __('Nicht verfügbar.', 'rrze-multisite-manager'),
                'main_file_path' => $this->getPluginAbsolutePath($pluginFile),
            ]
        );

        set_site_transient($cacheKey, $pluginData, $this->getDetailCacheTtl());

        return $pluginData;
    }

    protected function getPluginUsage(): array {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        $networkActivePlugins = get_site_option('active_sitewide_plugins', []);
        $siteIds = get_sites([
            'fields' => 'ids',
            'number' => 0,
        ]);
        $pluginStats = $this->createBasePluginUsageStats();
        $siteId = 0;
        $activePlugins = [];
        $totalSites = count($siteIds);
        $sitePluginFiles = [];

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

            $this->accumulatePluginUsageStats(
                $pluginStats,
                (int)$siteId,
                $this->getSiteNameById((int)$siteId),
                get_home_url((int)$siteId, '/'),
                $sitePluginFiles,
                is_array($networkActivePlugins) ? $networkActivePlugins : []
            );
        }

        return $this->finalizePluginUsageStats($pluginStats, $totalSites);
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
}
