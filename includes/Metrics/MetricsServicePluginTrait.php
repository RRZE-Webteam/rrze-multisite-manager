<?php

namespace RRZE\MultisiteManager\Metrics;

defined('ABSPATH') || exit;

trait MetricsServicePluginTrait {

    public function getPluginDetails(string $pluginFile): array {
        $availablePlugins = [];
        $cacheKey = '';
        $cached = null;
        $pluginData = [];
        $dashboardData = [];
        $pluginUsage = [];
        $pluginUsageItem = [];
        $updateItem = null;
        $status = [];

        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        $availablePlugins = get_plugins();

        if (!isset($availablePlugins[$pluginFile]) || !is_array($availablePlugins[$pluginFile])) {
            return [];
        }

        $cacheKey = $this->getPluginDetailsCacheKey($pluginFile);
        $cached = get_site_transient($cacheKey);

        if (is_array($cached) && !empty($cached)) {
            return array_merge($cached, $this->getPluginUsageSiteRows($pluginFile), $this->getLivePluginRegistrations($pluginFile));
        }

        $pluginData = $availablePlugins[$pluginFile];
        $dashboardData = $this->getDashboardData();
        $pluginUsage = is_array($dashboardData['plugin_usage']['plugins'] ?? null) ? $dashboardData['plugin_usage']['plugins'] : [];
        $pluginUsageItem = $this->findPluginUsageItem($pluginUsage, $pluginFile);
        $updateItem = $this->getPluginUpdateItem($pluginFile);
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
        $pluginUsageItem = array_merge($pluginUsageItem, $this->getPluginUsageSiteRows($pluginFile));
        $pluginUsageItem['usage_data_available'] = $this->pluginThemeUsageMetrics->hasCompletedSnapshot();
        $pluginUsageItem['usage_monitoring_url'] = admin_url('admin.php?page=rrze-multisite-manager-monitoring&monitoring_tab=network');
        $status = $this->getPluginStatus($pluginUsageItem);

        // Cached with the detail page; the files are read again only after
        // the detail cache expired or was invalidated.
        $pluginData = array_merge(
            $pluginUsageItem,
            $this->getPluginSupplementaryData($pluginFile, $availablePlugins[$pluginFile])
        );
        $pluginData['status'] = $status;

        $this->storeDetailCacheWithoutSiteRows($cacheKey, $pluginData);

        return array_merge($pluginData, $this->getLivePluginRegistrations($pluginFile));
    }

    /**
     * Website rows of the detail page. They are built on every request from
     * the indexed site IDs and the stored dashboard rows (no per-site
     * metrics) and are not part of the detail cache, so a network-active
     * plugin does not put hundreds of rows into one site transient.
     */
    protected function getPluginUsageSiteRows(string $pluginFile): array {
        $rows = $this->getActiveSitesForPlugin($pluginFile);

        return [
            'active_sites' => $rows,
            'site_count' => count($rows),
            'active_sites_truncated' => false,
        ];
    }

    /** Stores a detail payload without its website rows. */
    protected function storeDetailCacheWithoutSiteRows(string $cacheKey, array $details): void {
        unset($details['active_sites']);
        set_site_transient($cacheKey, $details, $this->getDetailCacheTtl());
    }

    /** Only attribute registrations whose loaded callback belongs to this plugin. */
    protected function getLivePluginRegistrations(string $pluginFile): array {
        global $shortcode_tags, $wp_filter;
        $directory = dirname($pluginFile);
        $belongsToPlugin = static function ($callback) use ($pluginFile, $directory): bool {
            $class = is_array($callback) ? ($callback[0] ?? null) : null;
            if (is_string($callback) && str_contains($callback, '::')) {
                $class = strstr($callback, '::', true);
            }
            if ((is_string($class) && !class_exists($class, false)) || !is_callable($callback)) {
                return false;
            }
            try {
                $reflection = new \ReflectionFunction(\Closure::fromCallable($callback));
            } catch (\TypeError $error) {
                return false;
            }
            $filename = $reflection->getFileName();
            if (!$filename) {
                return false;
            }
            $file = plugin_basename($filename);
            return $file === $pluginFile || ($directory !== '.' && str_starts_with($file, $directory . '/'));
        };

        $result = ['shortcodes' => [], 'blocks' => [], 'hooks' => []];
        foreach ((array)$shortcode_tags as $tag => $callback) {
            if ($belongsToPlugin($callback)) {
                $result['shortcodes'][] = (string)$tag;
            }
        }
        foreach (\WP_Block_Type_Registry::get_instance()->get_all_registered() as $name => $block) {
            if ($belongsToPlugin($block->render_callback)) {
                $result['blocks'][] = $name;
            }
        }
        foreach ((array)$wp_filter as $name => $hook) {
            foreach ($hook->callbacks as $callbacks) {
                foreach ($callbacks as $registration) {
                    if ($belongsToPlugin($registration['function'])) {
                        $result['hooks'][] = $name;
                        continue 3;
                    }
                }
            }
        }
        foreach ($result as &$names) {
            sort($names, SORT_NATURAL | SORT_FLAG_CASE);
        }
        return $result;
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
