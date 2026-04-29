<?php

namespace RRZE\MultisiteManager\Widgets;

defined('ABSPATH') || exit;

class PluginUsageWidget extends Widgets {
    public function getId(): string {
        return 'plugin_usage';
    }

    public function getTitle(): string {
        return __('Plugin-Überblick', 'rrze-multisite-manager');
    }

    public function getDescription(): string {
        return __('Erste Auswertung der lokal verfügbaren und im Netzwerk verwendeten Plugins.', 'rrze-multisite-manager');
    }

    public function getWidth(): int {
        return 8;
    }

    public function getLayoutClass(): string {
        return 'rrze-msm-widget-size-fluid-wide';
    }

    public function renderTable(array $plugins, array $args = []): string {
        $tableId = sanitize_key((string)($args['table_id'] ?? 'plugin-usage'));
        $defaultPerPage = max(1, (int)($args['default_per_page'] ?? 10));
        $sortKey = $this->normalizePluginTableSortKey((string)($args['sort_key'] ?? 'active-sites'));
        $sortDirection = strtolower((string)($args['sort_direction'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
        $showActiveSites = !isset($args['show_active_sites']) || !empty($args['show_active_sites']);
        $showActiveSiteList = !empty($args['show_active_site_list']);
        $showNetworkButton = !empty($args['show_network_button']);
        $highlightNetworkPlugins = !empty($args['highlight_network_plugins']);
        $networkPluginsUrl = (string)($args['network_plugins_url'] ?? network_admin_url('plugins.php'));
        $perPageOptions = $this->getSiteTablePerPageOptions($defaultPerPage);
        $option = 0;
        $plugin = [];
        $mainRowClasses = [];

        if (empty($plugins)) {
            return '<p>' . esc_html__('Keine Einträge vorhanden.', 'rrze-multisite-manager') . '</p>';
        }

        ob_start();
        echo '<div class="rrze-msm-site-table-wrap rrze-msm-plugin-table-wrap" data-table-id="' . esc_attr($tableId) . '" data-default-per-page="' . esc_attr((string)$defaultPerPage) . '" data-current-page="1" data-sort-key="' . esc_attr($sortKey) . '" data-sort-direction="' . esc_attr($sortDirection) . '">';
        echo '<div class="tablenav top">';
        echo '<div class="alignleft actions">';

        if ($showNetworkButton) {
            echo '<a class="button" href="' . esc_url($networkPluginsUrl) . '">' . esc_html__('Plugin-Verwaltung im Netzwerk öffnen', 'rrze-multisite-manager') . '</a>';
        }

        echo '<label for="rrze-msm-plugin-search-' . esc_attr($tableId) . '">' . esc_html__('Plugin filtern:', 'rrze-multisite-manager') . '</label>';
        echo '<input type="search" class="rrze-msm-site-table-search" id="rrze-msm-plugin-search-' . esc_attr($tableId) . '" placeholder="' . esc_attr__('Nach Pluginname suchen', 'rrze-multisite-manager') . '" aria-label="' . esc_attr__('Plugins nach Namen filtern', 'rrze-multisite-manager') . '">';
        echo '<label for="rrze-msm-plugin-per-page-' . esc_attr($tableId) . '">' . esc_html__('Anzeigen:', 'rrze-multisite-manager') . '</label>';
        echo '<select class="rrze-msm-site-table-per-page" id="rrze-msm-plugin-per-page-' . esc_attr($tableId) . '">';

        foreach ($perPageOptions as $option) {
            echo '<option value="' . esc_attr((string)$option) . '"' . selected($option, $defaultPerPage, false) . '>';

            if ($option === $defaultPerPage) {
                echo esc_html(sprintf(__('Standard (%d)', 'rrze-multisite-manager'), $option));
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
        echo '<th>' . $this->renderSiteTableSortButton('name', __('Plugin', 'rrze-multisite-manager')) . '</th>';
        echo '<th class="rrze-msm-plugin-col-version">' . esc_html__('Version', 'rrze-multisite-manager') . '</th>';
        echo '<th>' . $this->renderSiteTableSortButton('author', __('Info', 'rrze-multisite-manager')) . '</th>';

        if ($showActiveSites) {
            echo '<th class="rrze-msm-plugin-col-active-sites">' . $this->renderSiteTableSortButton('active-sites', __('Aktive Sites', 'rrze-multisite-manager')) . '</th>';
        }

        echo '<th>' . esc_html__('Aktionen', 'rrze-multisite-manager') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($plugins as $plugin) {
            $mainRowClasses = [];

            if ($highlightNetworkPlugins && !empty($plugin['network_active'])) {
                $mainRowClasses[] = 'rrze-msm-detail-row-network-plugin';
            }

            echo '<tr class="' . esc_attr(implode(' ', $mainRowClasses)) . '"';
            echo ' data-sort-name="' . esc_attr(strtolower((string)($plugin['name'] ?? ''))) . '"';
            echo ' data-sort-author="' . esc_attr(strtolower((string)($plugin['author'] ?? ''))) . '"';
            echo ' data-sort-active-sites="' . esc_attr((string)((int)($plugin['site_count'] ?? 0))) . '"';
            echo '><td><strong><a href="' . esc_url($this->getPluginDetailsPageUrl((string)($plugin['file'] ?? ''))) . '">' . esc_html((string)($plugin['name'] ?? '')) . '</a></strong>';

            if (!empty($plugin['description'])) {
                echo '<br><span class="description">' . esc_html((string)$plugin['description']) . '</span>';
            }

            if (!empty($plugin['update_available']) && !empty($plugin['update_version'])) {
                echo '<div class="rrze-msm-plugin-update-note">';
                echo '<strong>' . esc_html(sprintf(__('Neue Version %s verfügbar.', 'rrze-multisite-manager'), (string)$plugin['update_version'])) . '</strong>';
                echo '<div class="rrze-msm-plugin-update-links">';

                if (!empty($plugin['update_details_url'])) {
                    echo '<a href="' . esc_url((string)$plugin['update_details_url']) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('Details', 'rrze-multisite-manager') . '</a>';
                }

                if (!empty($plugin['update_url'])) {
                    echo '<a href="' . esc_url((string)$plugin['update_url']) . '">' . esc_html__('Aktualisieren', 'rrze-multisite-manager') . '</a>';
                }

                echo '</div>';
                echo '</div>';
            }

            echo '</td>';
            echo '<td class="rrze-msm-plugin-col-version">' . esc_html((string)($plugin['version'] ?? '')) . '</td>';
            echo '<td>' . $this->renderPluginInfoHtml($plugin, $showActiveSiteList) . '</td>';

            if ($showActiveSites) {
                echo '<td class="rrze-msm-plugin-col-active-sites">';
                echo '<strong>' . esc_html(number_format_i18n((int)($plugin['site_count'] ?? 0))) . '</strong>';
                echo '</td>';
            }

            echo '<td><div class="rrze-msm-site-actions">';

            if (!empty($plugin['deactivate_url'])) {
                if (!empty($plugin['network_active'])) {
                    echo '<button type="button" class="button button-small rrze-msm-site-action rrze-msm-site-action-warning rrze-msm-open-plugin-deactivate-modal" data-plugin-name="' . esc_attr((string)($plugin['name'] ?? '')) . '" data-deactivate-url="' . esc_url((string)$plugin['deactivate_url']) . '" title="' . esc_attr__('Netzwerkweit für alle Sites deaktivieren', 'rrze-multisite-manager') . '" aria-label="' . esc_attr__('Netzwerkweit für alle Sites deaktivieren', 'rrze-multisite-manager') . '"><span class="dashicons dashicons-minus" aria-hidden="true"></span><span class="screen-reader-text">' . esc_html__('Netzwerkweit für alle Sites deaktivieren', 'rrze-multisite-manager') . '</span></button>';
                } else {
                    echo '<button type="button" class="button button-small rrze-msm-site-action rrze-msm-site-action-danger rrze-msm-open-plugin-deactivate-modal" data-plugin-name="' . esc_attr((string)($plugin['name'] ?? '')) . '" data-deactivate-url="' . esc_url((string)$plugin['deactivate_url']) . '" title="' . esc_attr__('Netzwerkweit für alle Sites deaktivieren', 'rrze-multisite-manager') . '" aria-label="' . esc_attr__('Netzwerkweit für alle Sites deaktivieren', 'rrze-multisite-manager') . '"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span><span class="screen-reader-text">' . esc_html__('Netzwerkweit für alle Sites deaktivieren', 'rrze-multisite-manager') . '</span></button>';
                }
            }

            if (!empty($plugin['settings_url'])) {
                echo '<a class="button button-small rrze-msm-site-action" href="' . esc_url((string)$plugin['settings_url']) . '" title="' . esc_attr__('Einstellungen', 'rrze-multisite-manager') . '" aria-label="' . esc_attr__('Einstellungen', 'rrze-multisite-manager') . '"><span class="dashicons dashicons-admin-tools" aria-hidden="true"></span><span class="screen-reader-text">' . esc_html__('Einstellungen', 'rrze-multisite-manager') . '</span></a>';
            }

            if (!empty($plugin['delete_url'])) {
                echo '<a class="button button-small rrze-msm-site-action rrze-msm-site-action-danger" href="' . esc_url((string)$plugin['delete_url']) . '" title="' . esc_attr__('Löschen', 'rrze-multisite-manager') . '" aria-label="' . esc_attr__('Löschen', 'rrze-multisite-manager') . '"><span class="dashicons dashicons-trash" aria-hidden="true"></span><span class="screen-reader-text">' . esc_html__('Löschen', 'rrze-multisite-manager') . '</span></a>';
            }

            echo '</div></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '<div class="tablenav bottom">';
        echo '<div class="tablenav-pages rrze-msm-site-table-pagination" aria-label="' . esc_attr__('Seitennavigation', 'rrze-multisite-manager') . '"></div>';
        echo '</div>';
        echo '</div>';

        return (string)ob_get_clean();
    }

    protected function getTemplateName(): string {
        return 'plugin-usage-widget';
    }

    protected function getTemplateData(array $dashboardData): array {
        $pluginUsage = $dashboardData['plugin_usage'] ?? [];
        $plugins = array_values(
            array_filter(
                $pluginUsage['plugins'] ?? [],
                [$this, 'isActivePlugin']
            )
        );

        return [
            'summary' => $pluginUsage['summary'] ?? [],
            'plugins' => $plugins,
            'network_plugins_url' => network_admin_url('plugins.php'),
            'default_per_page' => 10,
        ];
    }

    protected function isActivePlugin(array $plugin): bool {
        return (int)($plugin['site_count'] ?? 0) > 0;
    }

    protected function normalizePluginTableSortKey(string $sortKey): string {
        $sortKey = str_replace('_', '-', sanitize_key($sortKey));

        if (!in_array($sortKey, ['name', 'author', 'active-sites'], true)) {
            return 'active-sites';
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
            $items[] = '<a href="' . esc_url($pluginUri) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('Plugin-Seite', 'rrze-multisite-manager') . '</a>';
        }

        if ($textDomain !== '') {
            $items[] = esc_html(sprintf(__('Textdomain: %s', 'rrze-multisite-manager'), $textDomain));
        }

        if ($requiresWp !== '') {
            $items[] = esc_html(sprintf(__('WP ab: %s', 'rrze-multisite-manager'), $requiresWp));
        }

        if ($requiresPhp !== '') {
            $items[] = esc_html(sprintf(__('PHP ab: %s', 'rrze-multisite-manager'), $requiresPhp));
        }

        if (empty($items)) {
            return '';
        }

        return '<div class="rrze-msm-plugin-meta">' . implode(' <span class="rrze-msm-plugin-meta-sep">|</span> ', $items) . '</div>';
    }

    protected function renderPluginActiveSitesHtml(array $plugin): string {
        $activeSites = is_array($plugin['active_sites'] ?? null) ? $plugin['active_sites'] : [];
        $site = [];
        $perPage = 20;
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
        echo '<p class="rrze-msm-plugin-sites-collapsed"><button type="button" class="button-link rrze-msm-plugin-sites-toggle-text" data-plugin-sites-id="' . esc_attr($toggleId) . '" aria-expanded="false">▼ ' . esc_html__('Websites anzeigen', 'rrze-multisite-manager') . '</button></p>';
        echo '<div class="rrze-msm-plugin-sites-details" hidden>';
        echo '<p class="rrze-msm-plugin-sites-toggle-row"><button type="button" class="button-link rrze-msm-plugin-sites-toggle-text" data-plugin-sites-id="' . esc_attr($toggleId) . '" aria-expanded="true">▲ ' . esc_html__('Websites verbergen', 'rrze-multisite-manager') . '</button></p>';
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
            echo '<button type="button" class="button button-small rrze-msm-plugin-sites-page" data-direction="prev" disabled aria-disabled="true"><span aria-hidden="true">‹</span><span class="screen-reader-text">' . esc_html__('Vorherige Seite', 'rrze-multisite-manager') . '</span></button>';
            echo '<span class="rrze-msm-plugin-sites-page-label">' . esc_html(sprintf(__('Seite %1$d von %2$d', 'rrze-multisite-manager'), 1, $totalPages)) . '</span>';
            echo '<button type="button" class="button button-small rrze-msm-plugin-sites-page" data-direction="next"><span aria-hidden="true">›</span><span class="screen-reader-text">' . esc_html__('Nächste Seite', 'rrze-multisite-manager') . '</span></button>';
            echo '</div>';
        }

        echo '</div>';
        echo '</div>';

        return (string)ob_get_clean();
    }
}
