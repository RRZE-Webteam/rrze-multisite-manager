<?php
// phpcs:ignoreFile WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer methods in this file escape dynamic data locally and intentionally return HTML fragments.

namespace RRZE\MultisiteManager\Widgets;

use RRZE\MultisiteManager\NetworkAuditPluginIntegration;

defined('ABSPATH') || exit;

class PluginUsageWidget extends Widgets {
    public function getId(): string {
        return 'plugin_usage';
    }

    public function getTitle(): string {
        return __('Plugin overview', 'rrze-multisite-manager');
    }

    public function getDescription(): string {
        return __('Initial evaluation of locally available plugins and plugins used in the network.', 'rrze-multisite-manager');
    }

    public function getWidth(): int {
        return 12;
    }

    public function getLayoutClass(): string {
        return 'rrze-msm-widget-size-fluid-wide';
    }

    /**
     * Adds current network state to cached website-usage data.
     *
     * The dashboard worker only determines which websites use a plugin. Plugin
     * headers, update availability and network state must reflect the current
     * request, not the last worker run.
     *
     * @param array<int, array<string, mixed>> $plugins
     * @return array<int, array<string, mixed>>
     */
    public function hydrateRuntimeState(array $plugins): array {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        $cachedPlugins = [];
        $availablePlugins = get_plugins();
        $networkActivePlugins = (array)get_site_option('active_sitewide_plugins', []);
        $autoUpdatePlugins = (array)get_site_option('auto_update_plugins', []);
        $pluginUpdates = get_site_transient('update_plugins');
        $plugin = [];
        $pluginFile = '';
        $pluginData = [];
        $updateItem = null;

        foreach ($plugins as $plugin) {
            if (!is_array($plugin)) {
                continue;
            }

            $pluginFile = plugin_basename((string)($plugin['file'] ?? ''));

            if ($pluginFile !== '') {
                $cachedPlugins[$pluginFile] = $plugin;
            }
        }

        $plugins = [];

        foreach ($availablePlugins as $pluginFile => $pluginData) {
            $pluginFile = plugin_basename((string)$pluginFile);
            $updateItem = is_object($pluginUpdates) && isset($pluginUpdates->response[$pluginFile]) && is_object($pluginUpdates->response[$pluginFile])
                ? $pluginUpdates->response[$pluginFile]
                : null;
            $plugin = $cachedPlugins[$pluginFile] ?? [
                'file' => $pluginFile,
                'site_count' => 0,
                'active_sites' => [],
                'active_sites_truncated' => false,
            ];
            $plugin['file'] = $pluginFile;
            $plugin['name'] = (string)($pluginData['Name'] ?? $pluginFile);
            $plugin['version'] = (string)($pluginData['Version'] ?? 'n/a');
            $plugin['description'] = wp_strip_all_tags((string)($pluginData['Description'] ?? ''));
            $plugin['author'] = wp_strip_all_tags((string)($pluginData['Author'] ?? ''));
            $plugin['author_url'] = esc_url_raw((string)($pluginData['AuthorURI'] ?? ''));
            $plugin['plugin_uri'] = esc_url_raw((string)($pluginData['PluginURI'] ?? ''));
            $plugin['text_domain'] = (string)($pluginData['TextDomain'] ?? '');
            $plugin['requires_php'] = (string)($pluginData['RequiresPHP'] ?? '');
            $plugin['requires_wp'] = (string)($pluginData['RequiresWP'] ?? '');
            $plugin['network_active'] = isset($networkActivePlugins[$pluginFile]);
            $plugin['auto_update_enabled'] = in_array($pluginFile, $autoUpdatePlugins, true);
            $plugin['update_available'] = $updateItem !== null;
            $plugin['update_version'] = $updateItem !== null ? (string)($updateItem->new_version ?? '') : '';
            $plugins[] = $plugin;
        }

        return $plugins;
    }

    public function renderTable(array $plugins, array $args = []): string {
        $plugins = $this->hydrateRuntimeState($plugins);
        $tableId = sanitize_key((string)($args['table_id'] ?? 'plugin-usage'));
        $defaultPerPage = max(1, (int)($args['default_per_page'] ?? 10));
        $sortKey = $this->normalizePluginTableSortKey((string)($args['sort_key'] ?? 'name'));
        $sortDirection = strtolower((string)($args['sort_direction'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
        $showActiveSites = !isset($args['show_active_sites']) || !empty($args['show_active_sites']);
        $showActiveSiteList = !empty($args['show_active_site_list']);
        $showNetworkButton = !empty($args['show_network_button']);
        $actionMode = (string)($args['action_mode'] ?? 'text');
        $actionModeClass = $actionMode === 'text' ? 'rrze-msm-plugin-table-text-actions' : 'rrze-msm-plugin-table-icon-actions';
        $actionCellClass = $actionMode === 'text' ? 'rrze-msm-col-actions-text' : 'rrze-msm-col-actions-icon';
        $networkPluginsUrl = (string)($args['network_plugins_url'] ?? network_admin_url('plugins.php'));
        $networkAuditRedirectUrl = (string)($args['network_audit_redirect_url'] ?? '');
        $canUseNetworkAdminFeatures = $this->currentUserCanUseNetworkAdminFeatures();
        $perPageOptions = $this->getSiteTablePerPageOptions($defaultPerPage);
        $option = 0;
        $plugin = [];
        $mainRowClasses = [];
        $networkAudit = new NetworkAuditPluginIntegration();

        if (empty($plugins)) {
            return '<p>' . esc_html__('No entries available.', 'rrze-multisite-manager') . '</p>';
        }

        ob_start();
        echo '<div class="rrze-msm-site-table-wrap rrze-msm-plugin-table-wrap ' . esc_attr($actionModeClass) . '" data-table-id="' . esc_attr($tableId) . '" data-default-per-page="' . esc_attr((string)$defaultPerPage) . '" data-current-page="1" data-sort-key="' . esc_attr($sortKey) . '" data-sort-direction="' . esc_attr($sortDirection) . '">';
        echo '<form method="post" action="' . esc_url($networkPluginsUrl) . '">';
        echo '<div class="tablenav top">';
        echo '<div class="alignleft actions">';

        if ($canUseNetworkAdminFeatures) {
            echo '<label class="screen-reader-text" for="rrze-msm-plugin-bulk-' . esc_attr($tableId) . '">' . esc_html__('Select bulk action', 'rrze-multisite-manager') . '</label>';
            echo '<select id="rrze-msm-plugin-bulk-' . esc_attr($tableId) . '" name="action">';
            echo '<option value="-1">' . esc_html__('Bulk actions', 'rrze-multisite-manager') . '</option>';
            echo '<option value="activate-selected">' . esc_html__('Activate network-wide', 'rrze-multisite-manager') . '</option>';
            echo '<option value="deactivate-selected">' . esc_html__('Deactivate network-wide', 'rrze-multisite-manager') . '</option>';
            echo '<option value="update-selected">' . esc_html__('Update', 'rrze-multisite-manager') . '</option>';
            echo '<option value="delete-selected">' . esc_html__('Delete', 'rrze-multisite-manager') . '</option>';
            echo '<option value="enable-auto-update-selected">' . esc_html__('Enable auto-updates', 'rrze-multisite-manager') . '</option>';
            echo '<option value="disable-auto-update-selected">' . esc_html__('Disable auto-updates', 'rrze-multisite-manager') . '</option>';

            if ($networkAudit->isAvailable()) {
                echo '<option value="rrze_multisite_manager_enable_selected_network_audit_plugins">' . esc_html__('Enable selected plugins', 'rrze-multisite-manager') . '</option>';
                echo '<option value="rrze_multisite_manager_disable_selected_network_audit_plugins">' . esc_html__('Disable selected plugins', 'rrze-multisite-manager') . '</option>';
            }

            echo '</select>';

            if ($networkAudit->isAvailable()) {
                echo '<input type="hidden" name="rrze_msm_redirect_to" value="' . esc_attr($networkAuditRedirectUrl) . '">';
            }

            echo '<input type="submit" class="button action" value="' . esc_attr__('Apply', 'rrze-multisite-manager') . '">';
            wp_nonce_field('bulk-plugins');
        }

        if ($showNetworkButton && $canUseNetworkAdminFeatures) {
            echo '<a class="button" href="' . esc_url($networkPluginsUrl) . '">' . esc_html__('Open network plugin management', 'rrze-multisite-manager') . '</a>';
        }

        echo '<label for="rrze-msm-plugin-search-' . esc_attr($tableId) . '">' . esc_html__('Filter plugins:', 'rrze-multisite-manager') . '</label>';
        echo '<input type="search" class="rrze-msm-site-table-search" id="rrze-msm-plugin-search-' . esc_attr($tableId) . '" placeholder="' . esc_attr__('Search by plugin name', 'rrze-multisite-manager') . '" aria-label="' . esc_attr__('Filter plugins by name', 'rrze-multisite-manager') . '">';
        echo '<label for="rrze-msm-plugin-per-page-' . esc_attr($tableId) . '">' . esc_html__('Show:', 'rrze-multisite-manager') . '</label>';
        echo '<select class="rrze-msm-site-table-per-page" id="rrze-msm-plugin-per-page-' . esc_attr($tableId) . '">';

        foreach ($perPageOptions as $option) {
            echo '<option value="' . esc_attr((string)$option) . '"' . selected($option, $defaultPerPage, false) . '>';

            if ($option === $defaultPerPage) {
                echo esc_html(
                    sprintf(
                        /* translators: %d: number of rows shown by default. */
                        __('Default (%d)', 'rrze-multisite-manager'),
                        $option
                    )
                );
            } else {
                echo esc_html((string)$option);
            }

            echo '</option>';
        }

        echo '</select>';
        echo '</div>';
        echo '</div>';
        echo '<table class="widefat striped rrze-msm-table rrze-msm-plugin-table rrze-msm-plugin-overview-table">';
        echo '<colgroup>';
        echo '<col class="rrze-msm-plugin-col-select">';
        echo '<col class="rrze-msm-plugin-col-name">';
        echo '<col class="rrze-msm-plugin-col-version">';
        echo '<col class="rrze-msm-plugin-col-info">';
        echo '<col class="rrze-msm-plugin-col-active-sites">';
        echo '<col class="rrze-msm-plugin-col-network-active">';
        echo '<col class="rrze-msm-plugin-col-auto-updates">';
        echo '<col class="rrze-msm-plugin-col-enabled">';
        echo '</colgroup>';
        echo '<thead><tr>';
        echo '<td rowspan="2" class="check-column"><input type="checkbox" class="rrze-msm-plugin-select-all" aria-label="' . esc_attr__('Select all plugins', 'rrze-multisite-manager') . '"></td>';
        echo '<th rowspan="2" class="rrze-msm-plugin-col-name">' . $this->renderSiteTableSortButton('name', __('Plugin', 'rrze-multisite-manager')) . '</th>';
        echo '<th rowspan="2" class="rrze-msm-plugin-col-version">' . esc_html__('Version', 'rrze-multisite-manager') . '</th>';
        echo '<th rowspan="2" class="rrze-msm-plugin-col-info">' . $this->renderSiteTableSortButton('author', __('Info', 'rrze-multisite-manager')) . '</th>';
        echo '<th colspan="4" class="rrze-msm-plugin-status-group">' . esc_html__('Status', 'rrze-multisite-manager') . '</th>';
        echo '</tr><tr><th class="rrze-msm-col-numeric">' . $this->renderSiteTableSortButton('active-sites', __('Sites', 'rrze-multisite-manager')) . '</th><th class="rrze-msm-col-numeric">' . $this->renderSiteTableSortButton('network-wide', __('Network', 'rrze-multisite-manager')) . '</th><th class="rrze-msm-col-numeric">' . $this->renderSiteTableSortButton('auto-updates', __('Updates', 'rrze-multisite-manager')) . '</th><th class="rrze-msm-plugin-col-enabled">' . $this->renderSiteTableSortButton('available', _x('Available', 'Plugin availability status column', 'rrze-multisite-manager')) . '</th></tr></thead><tbody>';

        foreach ($plugins as $plugin) {
            $pluginFile = (string)($plugin['file'] ?? '');
            $networkActive = !empty($plugin['network_active']);
            $autoUpdateEnabled = !empty($plugin['auto_update_enabled']);
            $mainRowClasses = [];
            $networkAuditState = $networkAudit->getPluginState($pluginFile, $networkAuditRedirectUrl);

            if ($networkActive) {
                $mainRowClasses[] = 'rrze-msm-detail-row-network-plugin';
            }

            if (!empty($plugin['update_available'])) {
                $mainRowClasses[] = 'rrze-msm-detail-row-hasupdate';
            }

            echo '<tr class="' . esc_attr(implode(' ', $mainRowClasses)) . '"';
            echo ' data-sort-name="' . esc_attr(strtolower((string)($plugin['name'] ?? ''))) . '"';
            echo ' data-sort-author="' . esc_attr(strtolower((string)($plugin['author'] ?? ''))) . '"';
            echo ' data-sort-active-sites="' . esc_attr((string)((int)($plugin['site_count'] ?? 0))) . '"';
            echo ' data-sort-network-wide="' . esc_attr($networkActive ? '1' : '0') . '"';
            echo ' data-sort-auto-updates="' . esc_attr($autoUpdateEnabled ? '1' : '0') . '"';
            echo ' data-sort-available="' . esc_attr($networkActive || !empty($networkAuditState['enabled']) ? '1' : '0') . '"';
            echo '><th scope="row" class="check-column"><input type="checkbox" name="checked[]" value="' . esc_attr((string)($plugin['file'] ?? '')) . '"></th><td><strong><a href="' . esc_url($this->getPluginDetailsPageUrl((string)($plugin['file'] ?? ''))) . '">' . esc_html((string)($plugin['name'] ?? '')) . '</a></strong>';

            if (!empty($plugin['description'])) {
                echo '<br><span class="description">' . esc_html((string)$plugin['description']) . '</span>';
            }

            if (!empty($plugin['update_available']) && !empty($plugin['update_version'])) {
                echo '<div class="rrze-msm-plugin-update-note">';
                echo '<strong>' . esc_html(
                    sprintf(
                        /* translators: %s: available plugin version number. */
                        __('New version %s available.', 'rrze-multisite-manager'),
                        (string)$plugin['update_version']
                    )
                ) . '</strong>';
                echo '<div class="rrze-msm-plugin-update-links">';

                if (!empty($plugin['update_details_url'])) {
                    echo '<a href="' . esc_url((string)$plugin['update_details_url']) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('Details', 'rrze-multisite-manager') . '</a>';
                }

                if ($canUseNetworkAdminFeatures && $pluginFile !== '') {
                    echo '<a href="' . esc_url($this->getNetworkPluginUpdateUrl($pluginFile)) . '">' . esc_html__('Update', 'rrze-multisite-manager') . '</a>';
                }

                echo '</div>';
                echo '</div>';
            }

            $pluginCheckUrl = $this->getPluginCheckTestUrl($pluginFile);

            echo '<div class="row-actions"><span class="details"><a href="' . esc_url($this->getPluginDetailsPageUrl($pluginFile)) . '">' . esc_html__('Details', 'rrze-multisite-manager') . '</a></span>';

            if (!empty($plugin['settings_url'])) {
                echo ' | <span class="settings"><a href="' . esc_url((string)$plugin['settings_url']) . '">' . esc_html__('Settings', 'rrze-multisite-manager') . '</a></span>';
            }

            if ($networkAuditState['action_url'] !== '') {
                echo ' | <span class="rrze-network-audit-toggle"><a href="' . esc_url($networkAuditState['action_url']) . '">' . esc_html($networkAuditState['action_label']) . '</a></span>';
            }

            if ($canUseNetworkAdminFeatures && $networkActive) {
                echo ' | <span class="deactivate"><a href="' . esc_url($this->getNetworkPluginDeactivateUrl($pluginFile)) . '">' . esc_html__('Deactivate network-wide', 'rrze-multisite-manager') . '</a></span>';
            } elseif ($canUseNetworkAdminFeatures && $pluginFile !== '') {
                echo ' | <span class="activate"><a href="' . esc_url($this->getNetworkPluginActivateUrl($pluginFile)) . '">' . esc_html__('Activate network-wide', 'rrze-multisite-manager') . '</a></span>';
            }

            if ($canUseNetworkAdminFeatures && $pluginFile !== '') {
                $autoUpdateAction = $autoUpdateEnabled ? 'disable-auto-update' : 'enable-auto-update';
                $autoUpdateLabel = $autoUpdateEnabled ? __('Disable auto-updates', 'rrze-multisite-manager') : __('Enable auto-updates', 'rrze-multisite-manager');
                echo ' | <span class="auto-update"><a href="' . esc_url($this->getPluginAutoUpdateUrl($pluginFile, $autoUpdateAction)) . '">' . esc_html($autoUpdateLabel) . '</a></span>';
            }

            if ($pluginCheckUrl !== '') {
                echo ' | <span class="plugin-check"><a href="' . esc_url($pluginCheckUrl) . '">' . esc_html__('Start PCP test', 'rrze-multisite-manager') . '</a></span>';
            }

            echo '</div></td>';
            echo '<td class="rrze-msm-plugin-col-version">' . esc_html((string)($plugin['version'] ?? '')) . '</td>';
            echo '<td>' . $this->renderPluginInfoHtml($plugin, $showActiveSiteList) . '</td>';

            echo '<td class="rrze-msm-plugin-col-active-sites rrze-msm-col-numeric">' . esc_html(number_format_i18n((int)($plugin['site_count'] ?? 0))) . '</td>';
            echo '<td class="rrze-msm-plugin-col-network-active">' . ($networkActive ? '<span class="dashicons dashicons-yes-alt rrze-msm-media-metadata-present"><span class="screen-reader-text">' . esc_html__('Yes', 'rrze-multisite-manager') . '</span></span>' : '') . '</td>';
            echo '<td class="rrze-msm-plugin-col-auto-updates">' . ($autoUpdateEnabled ? '<span class="dashicons dashicons-yes-alt rrze-msm-media-metadata-present"><span class="screen-reader-text">' . esc_html__('Enabled', 'rrze-multisite-manager') . '</span></span>' : '') . '</td>';
            echo '<td class="rrze-msm-plugin-col-enabled">' . $this->renderNetworkAuditEnabledStatus($networkAuditState, $networkActive) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '<div class="tablenav bottom">';
        echo '<div class="tablenav-pages rrze-msm-site-table-pagination" aria-label="' . esc_attr__('Pagination', 'rrze-multisite-manager') . '"></div>';
        echo '</div></form>';
        echo '</div>';

        return (string)ob_get_clean();
    }

    private function getNetworkPluginActivateUrl(string $pluginFile): string {
        return wp_nonce_url(
            add_query_arg(
                [
                    'action' => 'activate',
                    'plugin' => $pluginFile,
                ],
                network_admin_url('plugins.php')
            ),
            'activate-plugin_' . $pluginFile
        );
    }

    private function getNetworkPluginDeactivateUrl(string $pluginFile): string {
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

    private function getPluginAutoUpdateUrl(string $pluginFile, string $action): string {
        return wp_nonce_url(
            add_query_arg(
                [
                    'action' => $action,
                    'plugin' => $pluginFile,
                ],
                network_admin_url('plugins.php')
            ),
            'updates'
        );
    }

    private function getNetworkPluginUpdateUrl(string $pluginFile): string {
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

    private function getPluginCheckTestUrl(string $pluginFile): string {
        if ($pluginFile === '' || !$this->isPluginCheckActive()) {
            return '';
        }

        return (string)add_query_arg(
            [
                'page' => 'plugin-check',
                'plugin' => $pluginFile,
            ],
            get_admin_url(get_main_site_id(), 'tools.php')
        );
    }

    private function isPluginCheckActive(): bool {
        $pluginCheckFile = 'plugin-check/plugin.php';

        if (!defined('WP_PLUGIN_DIR') || !is_file(WP_PLUGIN_DIR . '/' . $pluginCheckFile)) {
            return false;
        }

        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        return is_plugin_active_for_network($pluginCheckFile) || is_plugin_active($pluginCheckFile);
    }

    public function renderMissingPluginTable(array $plugins, array $args = []): string {
        $tableId = sanitize_key((string)($args['table_id'] ?? 'missing-plugin-usage'));
        $defaultPerPage = max(1, (int)($args['default_per_page'] ?? 20));
        $perPageOptions = $this->getSiteTablePerPageOptions($defaultPerPage);
        $plugin = [];
        $option = 0;

        if (empty($plugins)) {
            return '<p>' . esc_html__('No orphaned plugin entries available.', 'rrze-multisite-manager') . '</p>';
        }

        usort($plugins, [self::class, 'compareMissingPluginRows']);

        ob_start();
        echo '<div class="rrze-msm-site-table-wrap rrze-msm-plugin-table-wrap" data-table-id="' . esc_attr($tableId) . '" data-default-per-page="' . esc_attr((string)$defaultPerPage) . '" data-current-page="1" data-sort-key="name" data-sort-direction="asc">';
        echo '<div class="tablenav top">';
        echo '<div class="alignleft actions">';
        echo '<label for="rrze-msm-missing-plugin-search-' . esc_attr($tableId) . '">' . esc_html__('Filter plugins:', 'rrze-multisite-manager') . '</label>';
        echo '<input type="search" class="rrze-msm-site-table-search" id="rrze-msm-missing-plugin-search-' . esc_attr($tableId) . '" placeholder="' . esc_attr__('Search by plugin path', 'rrze-multisite-manager') . '" aria-label="' . esc_attr__('Filter orphaned plugins by path', 'rrze-multisite-manager') . '">';
        echo '<label for="rrze-msm-missing-plugin-per-page-' . esc_attr($tableId) . '">' . esc_html__('Show:', 'rrze-multisite-manager') . '</label>';
        echo '<select class="rrze-msm-site-table-per-page" id="rrze-msm-missing-plugin-per-page-' . esc_attr($tableId) . '">';

        foreach ($perPageOptions as $option) {
            echo '<option value="' . esc_attr((string)$option) . '"' . selected($option, $defaultPerPage, false) . '>';

            if ($option === $defaultPerPage) {
                echo esc_html(
                    sprintf(
                        /* translators: %d: number of rows shown by default. */
                        __('Default (%d)', 'rrze-multisite-manager'),
                        $option
                    )
                );
            } else {
                echo esc_html((string)$option);
            }

            echo '</option>';
        }

        echo '</select>';
        echo '</div>';
        echo '</div>';
        echo '<table class="widefat striped rrze-msm-table rrze-msm-plugin-table">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Plugin file', 'rrze-multisite-manager') . '</th>';
        echo '<th class="rrze-msm-plugin-col-active-sites">' . esc_html__('Active sites', 'rrze-multisite-manager') . '</th>';
        echo '<th>' . esc_html__('Websites', 'rrze-multisite-manager') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($plugins as $plugin) {
            echo '<tr';
            echo ' data-sort-name="' . esc_attr(strtolower((string)($plugin['file'] ?? ''))) . '"';
            echo ' data-sort-active-sites="' . esc_attr((string)((int)($plugin['site_count'] ?? 0))) . '"';
            echo '><td><strong><code>' . esc_html((string)($plugin['file'] ?? '')) . '</code></strong></td>';
            echo '<td class="rrze-msm-plugin-col-active-sites"><strong>' . esc_html(number_format_i18n((int)($plugin['site_count'] ?? 0))) . '</strong></td>';
            echo '<td>' . $this->renderPluginActiveSitesHtml($plugin) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '<div class="tablenav bottom">';
        echo '<div class="tablenav-pages rrze-msm-site-table-pagination" aria-label="' . esc_attr__('Pagination', 'rrze-multisite-manager') . '"></div>';
        echo '</div>';
        echo '</div>';

        return (string)ob_get_clean();
    }

    protected function getTemplateName(): string {
        return 'plugin-usage-widget';
    }

    protected function getTemplateData(array $dashboardData): array {
        $pluginUsage = $dashboardData['plugin_usage'] ?? [];
        $allPlugins = $this->hydrateRuntimeState((array)($pluginUsage['plugins'] ?? []));
        $summary = is_array($pluginUsage['summary'] ?? null) ? $pluginUsage['summary'] : [];
        $summary['available_plugins'] = count($allPlugins);
        $summary['network_active_plugins'] = count(
            array_filter($allPlugins, static fn(array $plugin): bool => !empty($plugin['network_active']))
        );
        $plugins = array_values(
            array_filter(
                $allPlugins,
                [$this, 'isActivePlugin']
            )
        );

        return [
            'summary' => $summary,
            'plugins' => $plugins,
            'network_plugins_url' => network_admin_url('plugins.php'),
            'network_audit_redirect_url' => add_query_arg(
                [
                    'page' => (string)($this->config->getMenuSettings()['dashboard_slug'] ?? 'rrze-multisite-manager-dashboard'),
                ],
                admin_url('admin.php')
            ),
            'default_per_page' => 10,
        ];
    }

    protected function isActivePlugin(array $plugin): bool {
        return (int)($plugin['site_count'] ?? 0) > 0;
    }

    protected static function compareMissingPluginRows(array $left, array $right): int {
        $siteCountComparison = ((int)($right['site_count'] ?? 0)) <=> ((int)($left['site_count'] ?? 0));

        if ($siteCountComparison !== 0) {
            return $siteCountComparison;
        }

        return strnatcasecmp((string)($left['file'] ?? ''), (string)($right['file'] ?? ''));
    }

    protected function normalizePluginTableSortKey(string $sortKey): string {
        $sortKey = str_replace('_', '-', sanitize_key($sortKey));

        if (!in_array($sortKey, ['name', 'author', 'active-sites', 'network-wide', 'auto-updates', 'available'], true)) {
            return 'name';
        }

        return $sortKey;
    }

    protected function renderPluginAuthorHtml(array $plugin): string {
        $author = (string)($plugin['author'] ?? '');
        $authorUrl = (string)($plugin['author_url'] ?? '');

        if ($author === '') {
            return '';
        }

        if ($authorUrl !== '') {
            return '<a href="' . esc_url($authorUrl) . '" target="_blank" rel="noopener noreferrer">' . esc_html($author) . '</a>';
        }

        return esc_html($author);
    }

    protected function renderPluginInfoHtml(array $plugin, bool $showActiveSiteList = false): string {
        $authorHtml = $this->renderPluginAuthorHtml($plugin);
        $metaHtml = $this->renderPluginMetaHtml($plugin);
        $sitesHtml = $showActiveSiteList && empty($plugin['network_active']) ? $this->renderPluginActiveSitesHtml($plugin) : '';

        if ($authorHtml === '' && $metaHtml === '' && $sitesHtml === '') {
            return '';
        }

        $html = '';

        if ($authorHtml !== '') {
            $html .= '<strong>' . $authorHtml . '</strong>';
        }

        if ($metaHtml !== '') {
            $html .= $metaHtml;
        }

        if ($sitesHtml !== '') {
            $html .= $sitesHtml;
        }

        return $html;
    }

    protected function renderPluginMetaHtml(array $plugin): string {
        $items = [];
        $pluginUri = (string)($plugin['plugin_uri'] ?? '');
        $detailsUrl = (string)($plugin['details_url'] ?? '');
        $textDomain = (string)($plugin['text_domain'] ?? '');
        $requiresWp = (string)($plugin['requires_wp'] ?? '');
        $requiresPhp = (string)($plugin['requires_php'] ?? '');

        if ($detailsUrl !== '') {
            $items[] = '<a href="' . esc_url($detailsUrl) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('Details', 'rrze-multisite-manager') . '</a>';
        } elseif ($pluginUri !== '') {
            $items[] = '<a href="' . esc_url($pluginUri) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('Plugin page', 'rrze-multisite-manager') . '</a>';
        }

        if ($textDomain !== '') {
            $items[] = esc_html(
                sprintf(
                    /* translators: %s: plugin text domain. */
                    __('Text domain: %s', 'rrze-multisite-manager'),
                    $textDomain
                )
            );
        }

        if ($requiresWp !== '') {
            $items[] = esc_html(
                sprintf(
                    /* translators: %s: minimum supported WordPress version. */
                    __('WP from: %s', 'rrze-multisite-manager'),
                    $requiresWp
                )
            );
        }

        if ($requiresPhp !== '') {
            $items[] = esc_html(
                sprintf(
                    /* translators: %s: minimum supported PHP version. */
                    __('PHP from: %s', 'rrze-multisite-manager'),
                    $requiresPhp
                )
            );
        }

        if (empty($items)) {
            return '';
        }

        return '<div class="rrze-msm-plugin-meta">' . implode(' <span class="rrze-msm-plugin-meta-sep">|</span> ', $items) . '</div>';
    }

    protected function renderPluginActiveSitesHtml(array $plugin): string {
        $activeSites = is_array($plugin['active_sites'] ?? null) ? $plugin['active_sites'] : [];
        $siteCount = (int)($plugin['site_count'] ?? count($activeSites));
        $site = [];
        $perPage = 20;
        $isTruncated = !empty($plugin['active_sites_truncated']) || count($activeSites) > $perPage;
        $activeSites = array_slice($activeSites, 0, $perPage);
        $totalPages = (int)ceil(count($activeSites) / $perPage);
        $index = 0;
        $page = 1;
        $siteId = 0;
        $siteDetailsUrl = '';
        $toggleId = 'rrze-msm-plugin-sites-' . sanitize_html_class(md5((string)($plugin['file'] ?? (string)($plugin['name'] ?? 'plugin'))));

        if (empty($activeSites)) {
            return '';
        }

        ob_start();
        echo '<div class="rrze-msm-plugin-sites-inline" data-plugin-sites-id="' . esc_attr($toggleId) . '">';
        echo '<p class="rrze-msm-plugin-sites-collapsed"><button type="button" class="button-link rrze-msm-plugin-sites-toggle-text" data-plugin-sites-id="' . esc_attr($toggleId) . '" aria-expanded="false">▼ ' . esc_html__('Show websites', 'rrze-multisite-manager') . '</button></p>';
        echo '<div class="rrze-msm-plugin-sites-details" hidden>';
        echo '<p class="rrze-msm-plugin-sites-toggle-row"><button type="button" class="button-link rrze-msm-plugin-sites-toggle-text" data-plugin-sites-id="' . esc_attr($toggleId) . '" aria-expanded="true">▲ ' . esc_html__('Hide websites', 'rrze-multisite-manager') . '</button></p>';

        if ($isTruncated) {
            echo '<p class="description">';
            echo esc_html(
                sprintf(
                    /* translators: 1: number of previewed websites, 2: total number of websites. */
                    __('A preview of the first %1$s of %2$s websites is shown.', 'rrze-multisite-manager'),
                    number_format_i18n(count($activeSites)),
                    number_format_i18n($siteCount)
                )
            );
            echo '</p>';
        }

        echo '<ul class="rrze-msm-plugin-sites-list">';

        foreach ($activeSites as $site) {
            $page = (int)floor($index / $perPage) + 1;
            $siteId = (int)($site['id'] ?? 0);
            $siteDetailsUrl = $siteId > 0 ? $this->getSiteDetailsPageUrl($siteId) : '';
            echo '<li data-page="' . esc_attr((string)$page) . '"' . ($page > 1 ? ' hidden' : '') . '>';
            echo '<strong>';

            if ($siteDetailsUrl !== '') {
                echo '<a href="' . esc_url($siteDetailsUrl) . '">' . esc_html((string)($site['name'] ?? '')) . '</a>';
            } else {
                echo esc_html((string)($site['name'] ?? ''));
            }

            echo '</strong>';
            echo ' <span class="rrze-msm-plugin-site-sep">|</span> ';
            echo '<a href="' . esc_url((string)($site['url'] ?? '')) . '" target="_blank" rel="noopener noreferrer">' . esc_html((string)($site['url'] ?? '')) . '</a>';

            echo '</li>';
            $index++;
        }

        echo '</ul>';

        if ($totalPages > 1) {
            echo '<div class="rrze-msm-plugin-sites-pagination" data-current-page="1" data-total-pages="' . esc_attr((string)$totalPages) . '">';
            echo '<button type="button" class="button button-small rrze-msm-plugin-sites-page" data-direction="prev" disabled aria-disabled="true"><span aria-hidden="true">‹</span><span class="screen-reader-text">' . esc_html__('Previous page', 'rrze-multisite-manager') . '</span></button>';
            echo '<span class="rrze-msm-plugin-sites-page-label">' . esc_html(
                sprintf(
                    /* translators: 1: current page number, 2: total number of pages. */
                    __('Page %1$d of %2$d', 'rrze-multisite-manager'),
                    1,
                    $totalPages
                )
            ) . '</span>';
            echo '<button type="button" class="button button-small rrze-msm-plugin-sites-page" data-direction="next"><span aria-hidden="true">›</span><span class="screen-reader-text">' . esc_html__('Next page', 'rrze-multisite-manager') . '</span></button>';
            echo '</div>';
        }

        echo '</div>';
        echo '</div>';

        return (string)ob_get_clean();
    }

    /**
     * @param array{available: bool, enabled: bool, manageable: bool, action_url: string, action_label: string} $networkAuditState
     */
    protected function renderNetworkAuditEnabledStatus(array $networkAuditState, bool $networkActive): string {
        if ($networkActive) {
            return '<span class="dashicons dashicons-marker rrze-msm-network-audit-network-active"><span class="screen-reader-text">' . esc_html__('Network-active', 'rrze-multisite-manager') . '</span></span>';
        }

        if (!$networkAuditState['available']) {
            return '<span class="screen-reader-text">' . esc_html__('RRZE Netzwerk-Audit is not active.', 'rrze-multisite-manager') . '</span>&mdash;';
        }

        if ($networkAuditState['enabled']) {
            return '<span class="dashicons dashicons-yes-alt rrze-msm-network-audit-enabled"><span class="screen-reader-text">' . esc_html__('Enabled', 'rrze-multisite-manager') . '</span></span>';
        }

        return '<span class="dashicons dashicons-no rrze-msm-network-audit-disabled"><span class="screen-reader-text">' . esc_html__('Disabled', 'rrze-multisite-manager') . '</span></span>';
    }

    protected function renderPluginActionLink(string $url, string $label, string $icon, string $accent = '', string $displayMode = 'text'): string {
        $classes = trim('button button-small rrze-msm-site-action ' . ($accent !== '' ? 'rrze-msm-site-action-' . $accent . ' ' : '') . ($displayMode === 'text' ? 'rrze-msm-site-action-text' : 'rrze-msm-site-action-icon'));

        if ($displayMode === 'text') {
            return '<a class="' . esc_attr($classes) . '" href="' . esc_url($url) . '" title="' . esc_attr($label) . '" aria-label="' . esc_attr($label) . '"><span class="rrze-msm-site-action-label">' . esc_html($label) . '</span></a>';
        }

        return '<a class="' . esc_attr($classes) . '" href="' . esc_url($url) . '" title="' . esc_attr($label) . '" aria-label="' . esc_attr($label) . '"><span class="dashicons dashicons-' . esc_attr($icon) . '" aria-hidden="true"></span><span class="screen-reader-text">' . esc_html($label) . '</span></a>';
    }

    protected function renderPluginActionButton(string $label, string $icon, string $accent = '', array $dataAttributes = [], string $displayMode = 'text'): string {
        $classes = trim('button button-small rrze-msm-site-action rrze-msm-open-plugin-deactivate-modal ' . ($accent !== '' ? 'rrze-msm-site-action-' . $accent . ' ' : '') . ($displayMode === 'text' ? 'rrze-msm-site-action-text' : 'rrze-msm-site-action-icon'));
        $attributes = '';
        $attributeName = '';
        $attributeValue = '';

        foreach ($dataAttributes as $attributeName => $attributeValue) {
            $attributes .= ' ' . esc_attr($attributeName) . '="' . esc_attr((string)$attributeValue) . '"';
        }

        if ($displayMode === 'text') {
            return '<button type="button" class="' . esc_attr($classes) . '" title="' . esc_attr($label) . '" aria-label="' . esc_attr($label) . '"' . $attributes . '><span class="rrze-msm-site-action-label">' . esc_html($label) . '</span></button>';
        }

        return '<button type="button" class="' . esc_attr($classes) . '" title="' . esc_attr($label) . '" aria-label="' . esc_attr($label) . '"' . $attributes . '><span class="dashicons dashicons-' . esc_attr($icon) . '" aria-hidden="true"></span><span class="screen-reader-text">' . esc_html($label) . '</span></button>';
    }
}
