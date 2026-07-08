<?php

namespace RRZE\MultisiteManager\Widgets;

use RRZE\MultisiteManager\Config;
use RRZE\MultisiteManager\Plugin;
use RRZE\MultisiteManager\Template;

defined('ABSPATH') || exit;

abstract class Widgets {
    protected Plugin $plugin;
    protected Config $config;

    public function __construct(Plugin $plugin, Config $config) {
        $this->plugin = $plugin;
        $this->config = $config;
    }

    public function render(Template $template, array $dashboardData): string {
        return $template->render(
            'widgets/' . $this->getTemplateName(),
            array_merge(
                [
                    'widget_id' => $this->getId(),
                    'widget_title' => $this->getTitle(),
                    'widget_description' => $this->getDescription(),
                    'widget_classes' => $this->getLayoutClass(),
                ],
                $this->getTemplateData($dashboardData)
            ),
            $this
        );
    }

    public function getWidth(): int {
        return 4;
    }

    public function getLayoutClass(): string {
        return 'rrze-msm-widget-span-' . $this->getWidth();
    }

    public function getStatusBadgesHtml(array $statusItems): string {
        $html = '';
        $statusItem = [];

        foreach ($statusItems as $statusItem) {
            $html .= '<span class="rrze-msm-badge rrze-msm-badge-' . esc_attr((string)$statusItem['accent']) . '">' . esc_html((string)$statusItem['label']) . '</span> ';
        }

        return trim($html);
    }

    public function renderActionsForSite(array $site, string $displayMode = 'icon'): string {
        return $this->renderSiteActions($site, $displayMode);
    }

    public function renderThemeCard(array $theme, array $args = []): string {
        $linkTitle = !isset($args['link_title']) || !empty($args['link_title']);
        $showSites = !isset($args['show_sites']) || !empty($args['show_sites']);
        $title = (string)($theme['name'] ?? '');
        $detailUrl = $linkTitle ? $this->getThemeDetailsPageUrl((string)($theme['stylesheet'] ?? '')) : '';
        $themeUrl = (string)($theme['theme_uri'] ?? '');
        $author = (string)($theme['author'] ?? '');
        $authorUrl = (string)($theme['author_url'] ?? '');
        $siteCount = (int)($theme['site_count'] ?? 0);
        $html = '';

        $html .= '<div class="rrze-msm-site-theme-card">';
        $html .= '<div class="rrze-msm-site-theme-screenshot">';

        if (!empty($theme['screenshot'])) {
            $html .= '<img src="' . esc_url((string)$theme['screenshot']) . '" alt="' . esc_attr($title) . '">';
        } else {
            $html .= '<span class="rrze-msm-site-branding-empty">' . esc_html__('Kein Screenshot verfügbar', 'rrze-multisite-manager') . '</span>';
        }

        $html .= '</div>';
        $html .= '<div class="rrze-msm-site-theme-details">';

        if ($detailUrl !== '') {
            $html .= '<h3><a href="' . esc_url($detailUrl) . '">' . esc_html($title) . '</a></h3>';
        } else {
            $html .= '<h3>' . esc_html($title) . '</h3>';
        }

        if (!empty($theme['version'])) {
            $html .= '<p><strong>' . esc_html__('Version:', 'rrze-multisite-manager') . '</strong> ' . esc_html((string)$theme['version']) . '</p>';
        }

        if (!empty($theme['description'])) {
            $html .= '<p>' . esc_html((string)$theme['description']) . '</p>';
        }

        if (!empty($theme['status']) && is_array($theme['status'])) {
            $html .= '<div class="rrze-msm-theme-badges">' . $this->getStatusBadgesHtml((array)$theme['status']) . '</div>';
        }

        if ($author !== '') {
            $html .= '<p><strong>' . esc_html__('Autor:', 'rrze-multisite-manager') . '</strong> ';

            if ($authorUrl !== '') {
                $html .= '<a href="' . esc_url($authorUrl) . '" target="_blank" rel="noopener noreferrer">' . esc_html($author) . '</a>';
            } else {
                $html .= esc_html($author);
            }

            $html .= '</p>';
        }

        if ($themeUrl !== '') {
            $html .= '<p><strong>' . esc_html__('Theme-URL:', 'rrze-multisite-manager') . '</strong> <a href="' . esc_url($themeUrl) . '" target="_blank" rel="noopener noreferrer">' . esc_html($themeUrl) . '</a></p>';
        }

        if ($showSites && $siteCount > 0) {
            $html .= '<p><strong>' . esc_html(sprintf(_n('%d Website nutzt dieses Theme.', '%d Websites nutzen dieses Theme.', $siteCount, 'rrze-multisite-manager'), $siteCount)) . '</strong></p>';
            $html .= $this->renderThemeSitesHtml($theme);
        }

        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }

    public function renderSiteTable(array $sites, array $args = []): string {
        $site = [];
        $rowClass = '';
        $tableId = sanitize_key((string)($args['table_id'] ?? 'sites'));
        $defaultPerPage = max(1, (int)($args['default_per_page'] ?? 10));
        $sortKey = $this->normalizeSiteTableSortKey((string)($args['sort_key'] ?? 'name'));
        $sortDirection = strtolower((string)($args['sort_direction'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
        $actionMode = (string)($args['action_mode'] ?? 'icon');
        $perPageOptions = $this->getSiteTablePerPageOptions($defaultPerPage);
        $option = 0;

        if (empty($sites)) {
            return '<p>' . esc_html__('Keine Einträge vorhanden.', 'rrze-multisite-manager') . '</p>';
        }

        ob_start();
        echo '<div class="rrze-msm-site-table-wrap" data-table-id="' . esc_attr($tableId) . '" data-default-per-page="' . esc_attr((string)$defaultPerPage) . '" data-current-page="1" data-sort-key="' . esc_attr($sortKey) . '" data-sort-direction="' . esc_attr($sortDirection) . '">';
        echo '<div class="tablenav top">';
        echo '<div class="alignleft actions">';
        echo '<label for="rrze-msm-per-page-' . esc_attr($tableId) . '">' . esc_html__('Anzeigen:', 'rrze-multisite-manager') . '</label> ';
        echo '<select class="rrze-msm-site-table-per-page" id="rrze-msm-per-page-' . esc_attr($tableId) . '">';

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
        echo '<table class="widefat striped rrze-msm-table">';
        echo '<thead><tr>';
        echo '<th>' . $this->renderSiteTableSortButton('name', __('Site', 'rrze-multisite-manager')) . '</th>';
        echo '<th>' . $this->renderSiteTableSortButton('registered', __('Registriert', 'rrze-multisite-manager')) . '</th>';
        echo '<th>' . $this->renderSiteTableSortButton('last-updated', __('Zuletzt aktualisiert', 'rrze-multisite-manager')) . '</th>';
        echo '<th>' . $this->renderSiteTableSortButton('admin-email', __('Admin E-Mail', 'rrze-multisite-manager')) . '</th>';
        echo '<th>' . esc_html__('Aktionen', 'rrze-multisite-manager') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($sites as $site) {
            $rowClass = !empty($site['highlight_inactive']) ? ' class="rrze-msm-site-row-inactive"' : '';
            echo '<tr' . $rowClass;
            echo ' data-sort-name="' . esc_attr((string)($site['name_sort'] ?? strtolower((string)$site['name']))) . '"';
            echo ' data-sort-registered="' . esc_attr((string)($site['registered_timestamp'] ?? 0)) . '"';
            echo ' data-sort-last-updated="' . esc_attr((string)($site['last_updated_timestamp'] ?? 0)) . '"';
            echo ' data-sort-admin-email="' . esc_attr((string)($site['admin_email_sort'] ?? strtolower((string)($site['admin_email'] ?? '')))) . '"';
            echo '>';
            echo '<td>' . $this->renderSiteTitleAndUrl($site) . '</td>';
            echo '<td>' . esc_html((string)$site['registered_label']) . '</td>';
            echo '<td>' . esc_html((string)($site['last_updated_label'] ?? __('Unbekannt', 'rrze-multisite-manager'))) . '</td>';
            echo '<td>' . $this->renderSiteAdminEmail((string)($site['admin_email'] ?? '')) . '</td>';
            echo '<td>' . $this->renderSiteActions($site, $actionMode) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '<div class="tablenav bottom">';
        echo '<div class="tablenav-pages rrze-msm-site-table-pagination" aria-label="' . esc_attr__('Seitennavigation', 'rrze-multisite-manager') . '"></div>';
        echo '</div>';
        echo '</div>';

        return (string)ob_get_clean();
    }

    public function renderSiteOverviewTable(array $sites, array $args = []): string {
        $site = [];
        $tableId = sanitize_key((string)($args['table_id'] ?? 'site-overview'));
        $defaultPerPage = max(1, (int)($args['default_per_page'] ?? 10));
        $sortKey = $this->normalizeSiteTableSortKey((string)($args['sort_key'] ?? 'name'));
        $sortDirection = strtolower((string)($args['sort_direction'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
        $actionMode = (string)($args['action_mode'] ?? 'icon');
        $perPageOptions = $this->getSiteTablePerPageOptions($defaultPerPage);
        $option = 0;

        if (empty($sites)) {
            return '<p>' . esc_html__('Keine Einträge vorhanden.', 'rrze-multisite-manager') . '</p>';
        }

        ob_start();
        echo '<div class="rrze-msm-site-table-wrap rrze-msm-site-overview-wrap" data-table-id="' . esc_attr($tableId) . '" data-default-per-page="' . esc_attr((string)$defaultPerPage) . '" data-current-page="1" data-sort-key="' . esc_attr($sortKey) . '" data-sort-direction="' . esc_attr($sortDirection) . '">';
        echo '<div class="tablenav top">';
        echo '<div class="alignleft actions">';
        echo '<label for="rrze-msm-overview-per-page-' . esc_attr($tableId) . '">' . esc_html__('Anzeigen:', 'rrze-multisite-manager') . '</label> ';
        echo '<select class="rrze-msm-site-table-per-page" id="rrze-msm-overview-per-page-' . esc_attr($tableId) . '">';

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
        echo '<table class="widefat striped rrze-msm-table rrze-msm-site-overview-table">';
        echo '<thead><tr>';
        echo '<th class="rrze-msm-site-branding-column">' . esc_html__('Logo', 'rrze-multisite-manager') . '</th>';
        echo '<th>' . $this->renderSiteTableSortButton('name', __('Site', 'rrze-multisite-manager')) . '</th>';
        echo '<th>' . $this->renderSiteTableSortButton('registered', __('Registriert', 'rrze-multisite-manager')) . '</th>';
        echo '<th>' . $this->renderSiteTableSortButton('last-updated', __('Zuletzt aktualisiert', 'rrze-multisite-manager')) . '</th>';
        echo '<th>' . $this->renderSiteTableSortButton('admin-email', __('Admin E-Mail', 'rrze-multisite-manager')) . '</th>';
        echo '<th>' . esc_html__('Benutzer', 'rrze-multisite-manager') . '</th>';
        echo '<th>' . esc_html__('Inhalte', 'rrze-multisite-manager') . '</th>';
        echo '<th class="rrze-msm-col-numeric">' . $this->renderSiteTableSortButton('storage', __('Speicher', 'rrze-multisite-manager')) . '</th>';
        echo '<th>' . esc_html__('Aktionen', 'rrze-multisite-manager') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($sites as $site) {
            echo '<tr';
            echo !empty($site['storage']['warn_level']) && (string)$site['storage']['warn_level'] === 'critical' ? ' class="rrze-msm-site-row-capacity-critical"' : '';
            echo ' data-sort-name="' . esc_attr((string)($site['name_sort'] ?? strtolower((string)$site['name']))) . '"';
            echo ' data-sort-registered="' . esc_attr((string)($site['registered_timestamp'] ?? 0)) . '"';
            echo ' data-sort-last-updated="' . esc_attr((string)($site['last_updated_timestamp'] ?? 0)) . '"';
            echo ' data-sort-admin-email="' . esc_attr((string)($site['admin_email_sort'] ?? strtolower((string)($site['admin_email'] ?? '')))) . '"';
            echo ' data-sort-storage="' . esc_attr((string)($site['storage']['used_bytes'] ?? 0)) . '"';
            echo '>';
            echo '<td class="rrze-msm-site-branding-cell">' . $this->renderSiteBranding((array)($site['branding'] ?? []), (string)$site['name']) . '</td>';
            echo '<td>' . $this->renderSiteTitleAndUrl($site) . '</td>';
            echo '<td>' . esc_html((string)$site['registered_label']) . '</td>';
            echo '<td>' . esc_html((string)($site['last_updated_label'] ?? __('Unbekannt', 'rrze-multisite-manager'))) . '</td>';
            echo '<td>' . $this->renderSiteAdminEmail((string)($site['admin_email'] ?? '')) . '</td>';
            echo '<td>' . $this->renderRoleCounts((int)($site['id'] ?? 0), (array)($site['role_counts'] ?? [])) . '</td>';
            echo '<td>' . $this->renderContentCounts((array)($site['content_counts'] ?? [])) . '</td>';
            echo '<td class="' . esc_attr(trim('rrze-msm-col-numeric ' . $this->getStorageCellClass((array)($site['storage'] ?? [])))) . '">' . $this->renderStorageUsage((array)($site['storage'] ?? [])) . '</td>';
            echo '<td>' . $this->renderSiteActions($site, $actionMode) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '<div class="tablenav bottom">';
        echo '<div class="tablenav-pages rrze-msm-site-table-pagination" aria-label="' . esc_attr__('Seitennavigation', 'rrze-multisite-manager') . '"></div>';
        echo '</div>';
        echo '</div>';

        return (string)ob_get_clean();
    }

    public function renderStatusSiteTable(array $sites, array $args = []): string {
        $site = [];
        $tableId = sanitize_key((string)($args['table_id'] ?? 'status-sites'));
        $defaultPerPage = max(1, (int)($args['default_per_page'] ?? 10));
        $statusType = (string)($args['status_type'] ?? 'archive');
        $sortKey = $this->normalizeSiteTableSortKey((string)($args['sort_key'] ?? 'last-updated'));
        $sortDirection = strtolower((string)($args['sort_direction'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
        $actionMode = (string)($args['action_mode'] ?? 'icon');
        $statusLabel = $statusType === 'spam'
            ? __('Gesperrt seit', 'rrze-multisite-manager')
            : __('Archiviert seit', 'rrze-multisite-manager');
        $statusMetaKey = $statusType === 'spam' ? 'spam_at' : 'archived_at';
        $perPageOptions = $this->getSiteTablePerPageOptions($defaultPerPage);
        $option = 0;

        if (empty($sites)) {
            return '<p>' . esc_html__('Keine Einträge vorhanden.', 'rrze-multisite-manager') . '</p>';
        }

        ob_start();
        echo '<div class="rrze-msm-site-table-wrap rrze-msm-status-site-table-wrap" data-table-id="' . esc_attr($tableId) . '" data-default-per-page="' . esc_attr((string)$defaultPerPage) . '" data-current-page="1" data-sort-key="' . esc_attr($sortKey) . '" data-sort-direction="' . esc_attr($sortDirection) . '">';
        echo '<div class="tablenav top">';
        echo '<div class="alignleft actions">';
        echo '<label for="rrze-msm-status-per-page-' . esc_attr($tableId) . '">' . esc_html__('Anzeigen:', 'rrze-multisite-manager') . '</label> ';
        echo '<select class="rrze-msm-site-table-per-page" id="rrze-msm-status-per-page-' . esc_attr($tableId) . '">';

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
        echo '<table class="widefat striped rrze-msm-table rrze-msm-status-site-table">';
        echo '<thead><tr>';
        echo '<th>' . $this->renderSiteTableSortButton('name', __('Site', 'rrze-multisite-manager')) . '</th>';
        echo '<th>' . esc_html($statusLabel) . '</th>';
        echo '<th>' . esc_html__('Von', 'rrze-multisite-manager') . '</th>';
        echo '<th>' . esc_html__('Notiz', 'rrze-multisite-manager') . '</th>';
        echo '<th>' . esc_html__('Aktionen', 'rrze-multisite-manager') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($sites as $site) {
            echo '<tr';
            echo ' data-sort-name="' . esc_attr((string)($site['name_sort'] ?? strtolower((string)$site['name']))) . '"';
            echo ' data-sort-registered="0"';
            echo ' data-sort-last-updated="' . esc_attr((string)$this->getStatusMetaTimestamp((string)($site[$statusMetaKey] ?? ''))) . '"';
            echo ' data-sort-admin-email=""';
            echo '>';
            echo '<td>' . $this->renderSiteTitleAndUrl($site) . '</td>';
            echo '<td>' . esc_html($this->formatStatusMetaDate((string)($site[$statusMetaKey] ?? ''))) . '</td>';
            echo '<td>' . $this->renderStatusUser((int)($site['status_user_id'] ?? 0)) . '</td>';
            echo '<td>' . $this->renderStatusNote((string)($site['status_note'] ?? '')) . '</td>';
            echo '<td>' . $this->renderSiteActions($site, $actionMode) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '<div class="tablenav bottom">';
        echo '<div class="tablenav-pages rrze-msm-site-table-pagination" aria-label="' . esc_attr__('Seitennavigation', 'rrze-multisite-manager') . '"></div>';
        echo '</div>';
        echo '</div>';

        return (string)ob_get_clean();
    }

    public function renderOperationalStatusSiteTable(array $sites, array $args = []): string {
        $site = [];
        $tableId = sanitize_key((string)($args['table_id'] ?? 'operational-status-sites'));
        $defaultPerPage = max(1, (int)($args['default_per_page'] ?? 10));
        $sortKey = $this->normalizeSiteTableSortKey((string)($args['sort_key'] ?? 'name'));
        $sortDirection = strtolower((string)($args['sort_direction'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
        $actionMode = (string)($args['action_mode'] ?? 'icon');
        $perPageOptions = $this->getSiteTablePerPageOptions($defaultPerPage);
        $option = 0;

        if (empty($sites)) {
            return '<p>' . esc_html__('Keine problematischen Websites vorhanden.', 'rrze-multisite-manager') . '</p>';
        }

        ob_start();
        echo '<div class="rrze-msm-site-table-wrap rrze-msm-status-site-table-wrap" data-table-id="' . esc_attr($tableId) . '" data-default-per-page="' . esc_attr((string)$defaultPerPage) . '" data-current-page="1" data-sort-key="' . esc_attr($sortKey) . '" data-sort-direction="' . esc_attr($sortDirection) . '">';
        echo '<div class="tablenav top">';
        echo '<div class="alignleft actions">';
        echo '<label for="rrze-msm-operational-per-page-' . esc_attr($tableId) . '">' . esc_html__('Anzeigen:', 'rrze-multisite-manager') . '</label> ';
        echo '<select class="rrze-msm-site-table-per-page" id="rrze-msm-operational-per-page-' . esc_attr($tableId) . '">';

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
        echo '<table class="widefat striped rrze-msm-table rrze-msm-status-site-table">';
        echo '<thead><tr>';
        echo '<th>' . $this->renderSiteTableSortButton('name', __('Site', 'rrze-multisite-manager')) . '</th>';
        echo '<th>' . esc_html__('Betriebsstatus', 'rrze-multisite-manager') . '</th>';
        echo '<th>' . esc_html__('DNS', 'rrze-multisite-manager') . '</th>';
        echo '<th>' . esc_html__('HTTP', 'rrze-multisite-manager') . '</th>';
        echo '<th>' . esc_html__('Letzte Prüfung', 'rrze-multisite-manager') . '</th>';
        echo '<th>' . esc_html__('Notiz', 'rrze-multisite-manager') . '</th>';
        echo '<th>' . esc_html__('Aktionen', 'rrze-multisite-manager') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($sites as $site) {
            echo '<tr';
            echo ' data-sort-name="' . esc_attr((string)($site['name_sort'] ?? strtolower((string)$site['name']))) . '"';
            echo ' data-sort-registered="0"';
            echo ' data-sort-last-updated="' . esc_attr((string)$this->getStatusMetaTimestamp((string)($site['last_availability_check'] ?? ''))) . '"';
            echo ' data-sort-admin-email=""';
            echo '>';
            echo '<td>' . $this->renderSiteTitleAndUrl($site) . '</td>';
            echo '<td>' . $this->renderOperationalStatusBadge((string)($site['operational_status_label'] ?? ''), (string)($site['operational_status'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string)($site['dns_status_label'] ?? __('Nicht gesetzt', 'rrze-multisite-manager'))) . '</td>';
            echo '<td>' . esc_html((string)($site['http_status_label'] ?? __('Nicht gesetzt', 'rrze-multisite-manager'))) . '</td>';
            echo '<td>' . esc_html($this->formatStatusMetaDate((string)($site['last_availability_check'] ?? ''))) . '</td>';
            echo '<td>' . $this->renderStatusNote((string)($site['monitoring_note'] ?? '')) . '</td>';
            echo '<td>' . $this->renderSiteActions($site, $actionMode) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '<div class="tablenav bottom">';
        echo '<div class="tablenav-pages rrze-msm-site-table-pagination" aria-label="' . esc_attr__('Seitennavigation', 'rrze-multisite-manager') . '"></div>';
        echo '</div>';
        echo '</div>';

        return (string)ob_get_clean();
    }

    public function renderMonitoringAlertSiteTable(array $sites, array $args = []): string {
        $site = [];
        $tableId = sanitize_key((string)($args['table_id'] ?? 'monitoring-alert-sites'));
        $defaultPerPage = max(1, (int)($args['default_per_page'] ?? 10));
        $sortKey = $this->normalizeSiteTableSortKey((string)($args['sort_key'] ?? 'last-updated'));
        $sortDirection = strtolower((string)($args['sort_direction'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
        $actionMode = (string)($args['action_mode'] ?? 'icon');
        $perPageOptions = $this->getSiteTablePerPageOptions($defaultPerPage);
        $option = 0;

        if (empty($sites)) {
            return '<p>' . esc_html__('Seit dem letzten Monitoring-Lauf gibt es keine neuen technischen Warnungen.', 'rrze-multisite-manager') . '</p>';
        }

        ob_start();
        echo '<div class="rrze-msm-site-table-wrap rrze-msm-status-site-table-wrap" data-table-id="' . esc_attr($tableId) . '" data-default-per-page="' . esc_attr((string)$defaultPerPage) . '" data-current-page="1" data-sort-key="' . esc_attr($sortKey) . '" data-sort-direction="' . esc_attr($sortDirection) . '">';
        echo '<div class="tablenav top">';
        echo '<div class="alignleft actions">';
        echo '<label for="rrze-msm-monitoring-alerts-per-page-' . esc_attr($tableId) . '">' . esc_html__('Anzeigen:', 'rrze-multisite-manager') . '</label> ';
        echo '<select class="rrze-msm-site-table-per-page" id="rrze-msm-monitoring-alerts-per-page-' . esc_attr($tableId) . '">';

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
        echo '<table class="widefat striped rrze-msm-table rrze-msm-status-site-table">';
        echo '<thead><tr>';
        echo '<th>' . $this->renderSiteTableSortButton('name', __('Site', 'rrze-multisite-manager')) . '</th>';
        echo '<th>' . esc_html__('Neuer Betriebsstatus', 'rrze-multisite-manager') . '</th>';
        echo '<th>' . esc_html__('Vorheriger Status', 'rrze-multisite-manager') . '</th>';
        echo '<th>' . esc_html__('Geändert am', 'rrze-multisite-manager') . '</th>';
        echo '<th>' . esc_html__('DNS', 'rrze-multisite-manager') . '</th>';
        echo '<th>' . esc_html__('HTTP', 'rrze-multisite-manager') . '</th>';
        echo '<th>' . esc_html__('Aktionen', 'rrze-multisite-manager') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($sites as $site) {
            echo '<tr';
            echo ' data-sort-name="' . esc_attr((string)($site['name_sort'] ?? strtolower((string)$site['name']))) . '"';
            echo ' data-sort-registered="0"';
            echo ' data-sort-last-updated="' . esc_attr((string)$this->getStatusMetaTimestamp((string)($site['operational_status_changed_at'] ?? ''))) . '"';
            echo ' data-sort-admin-email=""';
            echo '>';
            echo '<td>' . $this->renderSiteTitleAndUrl($site) . '</td>';
            echo '<td>' . $this->renderOperationalStatusBadge((string)($site['operational_status_label'] ?? ''), (string)($site['operational_status'] ?? '')) . '</td>';
            echo '<td>' . esc_html(trim((string)($site['previous_operational_status_label'] ?? '')) !== '' ? (string)$site['previous_operational_status_label'] : __('Nicht gesetzt', 'rrze-multisite-manager')) . '</td>';
            echo '<td>' . esc_html($this->formatStatusMetaDate((string)($site['operational_status_changed_at'] ?? ''))) . '</td>';
            echo '<td>' . esc_html((string)($site['dns_status_label'] ?? __('Nicht gesetzt', 'rrze-multisite-manager'))) . '</td>';
            echo '<td>' . esc_html((string)($site['http_status_label'] ?? __('Nicht gesetzt', 'rrze-multisite-manager'))) . '</td>';
            echo '<td>' . $this->renderSiteActions($site, $actionMode) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '<div class="tablenav bottom">';
        echo '<div class="tablenav-pages rrze-msm-site-table-pagination" aria-label="' . esc_attr__('Seitennavigation', 'rrze-multisite-manager') . '"></div>';
        echo '</div>';
        echo '</div>';

        return (string)ob_get_clean();
    }

    public function renderPieChart(array $items, string $emptyMessage, array $args = []): string {
        $gradient = $this->getPieGradient($items);
        $centerTitle = trim((string)($args['center_title'] ?? ''));
        $centerValue = trim((string)($args['center_value'] ?? ''));
        $item = [];
        $label = [];
        $sliceLabels = [];

        if (empty($items)) {
            return '<p>' . esc_html($emptyMessage) . '</p>';
        }

        $sliceLabels = $this->getPieSliceLabels($items);

        ob_start();
        echo '<div class="rrze-msm-pie-layout">';
        echo '<div class="rrze-msm-pie-chart" style="background: ' . esc_attr($gradient) . ';">';

        foreach ($sliceLabels as $label) {
            echo '<span class="rrze-msm-pie-slice-label" style="left:' . esc_attr((string)$label['x']) . '%; top:' . esc_attr((string)$label['y']) . '%;" title="' . esc_attr((string)$label['title']) . '">' . esc_html((string)$label['text']) . '</span>';
        }

        if ($centerTitle !== '' || $centerValue !== '') {
            echo '<span class="rrze-msm-pie-center">';

            if ($centerTitle !== '') {
                echo '<span class="rrze-msm-pie-center-title">' . esc_html($centerTitle) . '</span>';
            }

            if ($centerValue !== '') {
                echo '<strong class="rrze-msm-pie-center-value">' . esc_html($centerValue) . '</strong>';
            }

            echo '</span>';
        }

        echo '</div>';
        echo '<div class="rrze-msm-pie-legend">';

        foreach ($items as $item) {
            echo '<div class="rrze-msm-pie-legend-item">';
            echo '<span class="rrze-msm-pie-swatch rrze-msm-swatch-' . esc_attr((string)$item['accent']) . '"></span>';
            echo '<div>';
            echo '<strong>' . esc_html((string)$item['label']) . '</strong><br>';
            echo '<span>' . esc_html((string)($item['value_label'] ?? number_format_i18n((int)$item['value']))) . ' (' . esc_html((string)$item['percent']) . '%)</span>';
            echo '</div>';
            echo '</div>';
        }

        echo '</div>';
        echo '</div>';

        return (string)ob_get_clean();
    }

    abstract public function getId(): string;
    abstract public function getTitle(): string;
    abstract public function getDescription(): string;
    abstract protected function getTemplateName(): string;
    abstract protected function getTemplateData(array $dashboardData): array;

    protected function getPieGradient(array $items): string {
        $segments = [];
        $position = 0;
        $item = [];
        $nextPosition = 0;
        $color = '';

        foreach ($items as $item) {
            if ((int)($item['value'] ?? 0) <= 0) {
                continue;
            }

            $color = $this->getAccentColor((string)$item['accent']);
            $nextPosition = min(100, $position + (int)$item['percent']);
            $segments[] = $color . ' ' . $position . '% ' . $nextPosition . '%';
            $position = $nextPosition;
        }

        if (empty($segments)) {
            return 'conic-gradient(#d0d5dd 0% 100%)';
        }

        if ($position < 100) {
            $segments[] = '#d0d5dd ' . $position . '% 100%';
        }

        return 'conic-gradient(' . implode(', ', $segments) . ')';
    }

    protected function getAccentColor(string $accent): string {
        $colors = [
            'positive' => 'var(--rrze-msm-positive)',
            'warning' => 'var(--rrze-msm-warning)',
            'danger' => 'var(--rrze-msm-danger)',
            'info' => 'var(--rrze-msm-info)',
            'blocked' => 'var(--rrze-msm-status-blocked-visual)',
            'neutral' => 'var(--rrze-msm-neutral)',
            'theme-1' => '#175cd3',
            'theme-2' => '#137333',
            'theme-3' => '#b26a00',
            'theme-4' => '#b42318',
            'theme-5' => '#6941c6',
            'theme-6' => '#087443',
        ];

        return $colors[$accent] ?? 'var(--rrze-msm-neutral)';
    }

    protected function getPieSliceLabels(array $items): array {
        $labels = [];
        $position = 0.0;
        $item = [];
        $percent = 0.0;
        $midpoint = 0.0;
        $angle = 0.0;
        $radius = 34.0;
        $x = 0.0;
        $y = 0.0;

        foreach ($items as $item) {
            if ((int)($item['value'] ?? 0) <= 0) {
                continue;
            }

            $percent = (float)($item['percent'] ?? 0);

            if ($percent < 8) {
                $position += $percent;
                continue;
            }

            $midpoint = $position + ($percent / 2);
            $angle = deg2rad(($midpoint * 3.6) - 90);
            $x = 50 + ($radius * cos($angle));
            $y = 50 + ($radius * sin($angle));

            $labels[] = [
                'text' => (string)((int)round($percent)) . '%',
                'title' => (string)($item['label'] ?? ''),
                'x' => round($x, 2),
                'y' => round($y, 2),
            ];

            $position += $percent;
        }

        return $labels;
    }

    protected function renderSiteAdminEmail(string $email): string {
        if ($email === '') {
            return esc_html__('Unbekannt', 'rrze-multisite-manager');
        }

        return '<a href="mailto:' . esc_attr($email) . '">' . esc_html($email) . '</a>';
    }

    protected function currentUserCanUseNetworkAdminFeatures(): bool {
        return is_super_admin();
    }

    protected function isNetworkAdminUrl(string $url): bool {
        return str_contains($url, '/wp-admin/network/');
    }

    protected function renderSiteActions(array $site, string $displayMode = 'icon'): string {
        $siteId = (int)($site['id'] ?? 0);
        $isMainSite = !empty($site['is_main_site']);
        $isArchived = !empty($site['is_archived']);
        $isSpam = !empty($site['is_spam']);
        $isDeleted = !empty($site['is_deleted']);
        $isNormal = !$isArchived && !$isSpam && !$isDeleted;
        $isRestricted = $isArchived || $isSpam;
        $isTechnicallyUnavailable = $this->isTechnicallyUnavailableSite($site);
        $actions = [];

        if ($siteId <= 0) {
            return '';
        }

        if ($this->currentUserCanUseNetworkAdminFeatures()) {
            $actions[] = $this->renderSiteActionLink(
                network_admin_url('site-info.php?id=' . $siteId),
                __('Bearbeiten', 'rrze-multisite-manager'),
                'edit',
                false,
                $displayMode
            );
        }

        if (!$isTechnicallyUnavailable) {
            $actions[] = $this->renderSiteActionLink(
                get_admin_url($siteId),
                __('Dashboard', 'rrze-multisite-manager'),
                'dashboard',
                false,
                $displayMode
            );
            $actions[] = $this->renderSiteActionLink(
                (string)($site['url'] ?? ''),
                __('Aufrufen', 'rrze-multisite-manager'),
                'visibility',
                true,
                $displayMode
            );
        }

        if ($isMainSite) {
            return '<div class="rrze-msm-site-actions">' . implode('', $actions) . '</div>';
        }

        if ($this->currentUserCanUseNetworkAdminFeatures() && $isNormal) {
            $actions[] = $this->renderSiteActionLink(
                $this->getSiteStatusPageUrl($siteId, 'archive'),
                __('Archivieren', 'rrze-multisite-manager'),
                'archive',
                false,
                $displayMode
            );
            $actions[] = $this->renderSiteActionLink(
                $this->getSiteStatusPageUrl($siteId, 'spam'),
                __('Sperren', 'rrze-multisite-manager'),
                'lock',
                false,
                $displayMode
            );
        }

        if ($this->currentUserCanUseNetworkAdminFeatures() && $isRestricted) {
            $actions[] = $this->renderSiteActionLink(
                $this->getSiteStatusSubmitUrl($siteId, 'restore'),
                __('Wiederherstellen', 'rrze-multisite-manager'),
                'backup',
                false,
                $displayMode
            );
            $actions[] = $this->renderSiteActionLink(
                $this->getSiteStatusSubmitUrl($siteId, 'delete'),
                __('Zum Löschen markieren', 'rrze-multisite-manager'),
                'trash',
                false,
                $displayMode
            );
        }

        if ($this->currentUserCanUseNetworkAdminFeatures() && $isDeleted) {
            $actions[] = $this->renderSiteActionLink(
                $this->getSiteStatusSubmitUrl($siteId, 'restore'),
                __('Wiederherstellen', 'rrze-multisite-manager'),
                'backup',
                false,
                $displayMode
            );
            $actions[] = $this->renderPermanentDeleteSiteAction($site, $displayMode);
        }

        return '<div class="rrze-msm-site-actions">' . implode('', $actions) . '</div>';
    }

    protected function renderSiteActionLink(string $url, string $label, string $icon, bool $newTab = false, string $displayMode = 'icon'): string {
        $attributes = $newTab ? ' target="_blank" rel="noopener noreferrer"' : '';
        $accentClass = $this->getSiteActionAccentClass($icon);
        $modeClass = $displayMode === 'text' ? 'rrze-msm-site-action-text' : 'rrze-msm-site-action-icon';

        if ($displayMode === 'text') {
            return '<a class="button button-small rrze-msm-site-action ' . esc_attr($accentClass . ' ' . $modeClass) . '" href="' . esc_url($url) . '" title="' . esc_attr($label) . '" aria-label="' . esc_attr($label) . '"' . $attributes . '><span class="rrze-msm-site-action-label">' . esc_html($label) . '</span></a>';
        }

        return '<a class="button button-small rrze-msm-site-action ' . esc_attr($accentClass . ' ' . $modeClass) . '" href="' . esc_url($url) . '" title="' . esc_attr($label) . '" aria-label="' . esc_attr($label) . '"' . $attributes . '><span class="dashicons dashicons-' . esc_attr($icon) . '" aria-hidden="true"></span><span class="screen-reader-text">' . esc_html($label) . '</span></a>';
    }

    protected function renderSiteActionState(string $label, string $icon): string {
        return '<span class="rrze-msm-site-action-state" title="' . esc_attr($label) . '"><span class="dashicons dashicons-' . esc_attr($icon) . '" aria-hidden="true"></span><span class="screen-reader-text">' . esc_html($label) . '</span></span>';
    }

    protected function renderPermanentDeleteSiteAction(array $site, string $displayMode = 'icon'): string {
        $siteId = (int)($site['id'] ?? 0);
        $siteName = (string)($site['name'] ?? '');
        $url = $this->getSitePermanentDeleteUrl($siteId);
        $label = __('Site endgültig löschen', 'rrze-multisite-manager');
        $modeClass = $displayMode === 'text' ? 'rrze-msm-site-action-text' : 'rrze-msm-site-action-icon';

        if ($siteId <= 0 || $url === '') {
            return '';
        }

        if ($displayMode === 'text') {
            return '<button type="button" class="button button-small rrze-msm-site-action rrze-msm-site-action-danger rrze-msm-site-action-text rrze-msm-open-site-delete-modal" data-site-name="' . esc_attr($siteName) . '" data-delete-url="' . esc_url($url) . '" title="' . esc_attr($label) . '" aria-label="' . esc_attr($label) . '"><span class="rrze-msm-site-action-label">' . esc_html($label) . '</span></button>';
        }

        return '<button type="button" class="button button-small rrze-msm-site-action rrze-msm-site-action-danger rrze-msm-open-site-delete-modal ' . esc_attr($modeClass) . '" data-site-name="' . esc_attr($siteName) . '" data-delete-url="' . esc_url($url) . '" title="' . esc_attr($label) . '" aria-label="' . esc_attr($label) . '"><span class="dashicons dashicons-warning" aria-hidden="true"></span><span class="screen-reader-text">' . esc_html($label) . '</span></button>';
    }

    protected function renderRoleCounts(int $siteId, array $roleCounts): string {
        $admins = (int)($roleCounts['admins'] ?? 0);
        $editors = (int)($roleCounts['editors'] ?? 0);
        $others = (int)($roleCounts['others'] ?? 0);

        return '<div class="rrze-msm-site-meta rrze-msm-role-counts">'
            . $this->renderMetaCount('admin-users', $admins, __('Admins', 'rrze-multisite-manager'), $this->getSiteUsersUrl($siteId, 'administrator'))
            . $this->renderMetaCount('welcome-write-blog', $editors, __('Redakteure', 'rrze-multisite-manager'), $this->getSiteUsersUrl($siteId, 'editor'))
            . $this->renderMetaCount('groups', $others, __('Weitere Rollen', 'rrze-multisite-manager'), $this->getSiteUsersUrl($siteId))
            . '</div>';
    }

    protected function renderContentCounts(array $contentCounts): string {
        $pages = (int)($contentCounts['pages'] ?? 0);
        $posts = (int)($contentCounts['posts'] ?? 0);
        $media = (int)($contentCounts['media'] ?? 0);

        return '<div class="rrze-msm-site-meta rrze-msm-content-counts">'
            . $this->renderMetaCount('media-document', $pages, __('Seiten', 'rrze-multisite-manager'))
            . $this->renderMetaCount('admin-post', $posts, __('Beiträge', 'rrze-multisite-manager'))
            . $this->renderMetaCount('format-image', $media, __('Medien', 'rrze-multisite-manager'))
            . '</div>';
    }

    protected function renderMetaCount(string $icon, int $count, string $label, string $url = ''): string {
        $content = '<span class="dashicons dashicons-' . esc_attr($icon) . '" aria-hidden="true"></span><span class="rrze-msm-meta-count-value">' . esc_html(number_format_i18n($count)) . '</span><span class="screen-reader-text">' . esc_html($label . ': ' . number_format_i18n($count)) . '</span>';

        if ($url !== '') {
            return '<a class="rrze-msm-meta-count" href="' . esc_url($url) . '" title="' . esc_attr($label . ': ' . number_format_i18n($count)) . '">' . $content . '</a>';
        }

        return '<span class="rrze-msm-meta-count" title="' . esc_attr($label . ': ' . number_format_i18n($count)) . '">' . $content . '</span>';
    }

    protected function renderStorageUsage(array $storage): string {
        $usedLabel = (string)($storage['used_label'] ?? __('Unbekannt', 'rrze-multisite-manager'));
        $maxLabel = (string)($storage['max_label'] ?? '');
        $percent = isset($storage['percent']) && is_int($storage['percent']) ? $storage['percent'] : null;
        $html = '<div class="rrze-msm-storage-usage"><strong>' . esc_html($usedLabel) . '</strong>';

        if ($maxLabel !== '') {
            $html .= '<br><span>' . esc_html(sprintf(__('von %s', 'rrze-multisite-manager'), $maxLabel)) . '</span>';
        }

        if ($percent !== null) {
            $html .= '<br><span>' . esc_html(sprintf(__('%d%% belegt', 'rrze-multisite-manager'), $percent)) . '</span>';
        }

        $html .= '</div>';

        return $html;
    }

    protected function renderStatusUser(int $userId): string {
        $user = null;

        if ($userId <= 0) {
            return '<span class="rrze-msm-site-note-empty">' . esc_html__('Unbekannt', 'rrze-multisite-manager') . '</span>';
        }

        $user = get_userdata($userId);

        if (!$user instanceof \WP_User) {
            return '<span class="rrze-msm-site-note-empty">' . esc_html(sprintf(__('User-ID %d', 'rrze-multisite-manager'), $userId)) . '</span>';
        }

        return '<div class="rrze-msm-status-user"><strong>' . esc_html($user->display_name) . '</strong><br><span>' . esc_html($user->user_email) . '</span></div>';
    }

    protected function renderStatusNote(string $note): string {
        if (trim($note) === '') {
            return '<span class="rrze-msm-site-note-empty">' . esc_html__('Keine Notiz', 'rrze-multisite-manager') . '</span>';
        }

        return '<div class="rrze-msm-status-note">' . nl2br(esc_html($note)) . '</div>';
    }

    protected function renderOperationalStatusBadge(string $label, string $status): string {
        $accent = 'neutral';

        if ($status === 'healthy') {
            $accent = 'positive';
        } elseif ($status === 'provisioning') {
            $accent = 'info';
        } elseif ($status === 'dns_missing') {
            $accent = 'danger';
        } elseif ($status === 'unreachable') {
            $accent = 'warning';
        }

        if (trim($label) === '') {
            $label = __('Nicht gesetzt', 'rrze-multisite-manager');
        }

        return '<span class="rrze-msm-badge rrze-msm-badge-' . esc_attr($accent) . '">' . esc_html($label) . '</span>';
    }

    protected function renderSiteTitleAndUrl(array $site): string {
        $siteId = (int)($site['id'] ?? 0);
        $siteName = (string)($site['name'] ?? '');
        $siteUrl = (string)($site['url'] ?? '');
        $detailsUrl = $siteId > 0 ? $this->getSiteDetailsPageUrl($siteId) : '';
        $html = '<strong>';

        if ($detailsUrl !== '') {
            $html .= '<a href="' . esc_url($detailsUrl) . '">' . esc_html($siteName) . '</a>';
        } else {
            $html .= esc_html($siteName);
        }

        $html .= '</strong>';

        if ($siteUrl !== '') {
            $html .= '<br>';

            if ($this->isTechnicallyUnavailableSite($site)) {
                $html .= '<span>' . esc_html($siteUrl) . '</span>';
            } else {
                $html .= '<a href="' . esc_url($siteUrl) . '" target="_blank" rel="noopener noreferrer">' . esc_html($siteUrl) . '</a>';
            }
        }

        return $html;
    }

    protected function isTechnicallyUnavailableSite(array $site): bool {
        $operationalStatus = (string)($site['operational_status'] ?? '');

        return in_array($operationalStatus, ['dns_missing', 'unreachable'], true);
    }

    protected function renderSiteBranding(array $branding, string $siteName): string {
        $url = (string)($branding['url'] ?? '');
        $type = (string)($branding['type'] ?? '');

        if ($url === '') {
            return '<span class="rrze-msm-site-branding-empty">' . esc_html__('Kein Logo', 'rrze-multisite-manager') . '</span>';
        }

        return '<img class="rrze-msm-site-branding-image rrze-msm-site-branding-image-' . esc_attr($type) . '" src="' . esc_url($url) . '" alt="' . esc_attr($siteName) . '">';
    }

    protected function getStorageCellClass(array $storage): string {
        $warnLevel = (string)($storage['warn_level'] ?? '');

        if ($warnLevel === 'critical') {
            return 'rrze-msm-storage-cell rrze-msm-storage-cell-critical';
        }

        if ($warnLevel === 'warning') {
            return 'rrze-msm-storage-cell rrze-msm-storage-cell-warning';
        }

        return 'rrze-msm-storage-cell';
    }

    protected function formatStatusMetaDate(string $dateValue): string {
        if ($dateValue === '' || $dateValue === '0000-00-00 00:00:00') {
            return __('Unbekannt', 'rrze-multisite-manager');
        }

        return get_date_from_gmt($dateValue, get_option('date_format') . ' ' . get_option('time_format'));
    }

    protected function getStatusMetaTimestamp(string $dateValue): int {
        if ($dateValue === '' || $dateValue === '0000-00-00 00:00:00') {
            return 0;
        }

        return (int)strtotime($dateValue . ' GMT');
    }

    protected function getSiteUsersUrl(int $siteId, string $role = ''): string {
        $args = [
            'role' => $role,
        ];

        if ($role === '') {
            unset($args['role']);
        }

        return add_query_arg($args, get_admin_url($siteId, 'users.php'));
    }

    protected function getSiteActionAccentClass(string $icon): string {
        if ($icon === 'archive') {
            return 'rrze-msm-site-action-warning';
        }

        if ($icon === 'lock') {
            return 'rrze-msm-site-action-blocked';
        }

        if ($icon === 'trash') {
            return 'rrze-msm-site-action-danger';
        }

        if ($icon === 'backup') {
            return 'rrze-msm-site-action-positive';
        }

        return '';
    }

    protected function getSiteStatusPageUrl(int $siteId, string $statusAction): string {
        return add_query_arg(
            [
                'page' => (string)($this->config->getMenuSettings()['site_status_slug'] ?? 'rrze-multisite-manager-site-status'),
                'site_id' => $siteId,
                'status_action' => $statusAction,
            ],
            admin_url('admin.php')
        );
    }

    protected function getSiteStatusSubmitUrl(int $siteId, string $statusAction): string {
        return wp_nonce_url(
            add_query_arg(
                [
                    'site_id' => $siteId,
                    'status_action' => $statusAction,
                ],
                add_query_arg(
                    [
                        'action' => 'rrze_multisite_manager_site_status',
                    ],
                    admin_url('admin-post.php')
                )
            ),
            'rrze_multisite_manager_site_status_' . $statusAction . '_' . $siteId
        );
    }

    protected function getSiteDetailsPageUrl(int $siteId): string {
        return add_query_arg(
            [
                'page' => (string)($this->config->getMenuSettings()['site_details_slug'] ?? 'rrze-multisite-manager-site-details'),
                'site_id' => $siteId,
            ],
            admin_url('admin.php')
        );
    }

    protected function getPluginDetailsPageUrl(string $pluginFile): string {
        $args = [
            'page' => (string)($this->config->getMenuSettings()['plugin_details_slug'] ?? 'rrze-multisite-manager-plugin-details'),
        ];

        if (trim($pluginFile) !== '') {
            $args['plugin'] = $pluginFile;
        }

        return add_query_arg($args, admin_url('admin.php'));
    }

    protected function getThemeDetailsPageUrl(string $stylesheet): string {
        $args = [
            'page' => (string)($this->config->getMenuSettings()['theme_details_slug'] ?? 'rrze-multisite-manager-theme-details'),
        ];

        if (trim($stylesheet) !== '') {
            $args['theme'] = $stylesheet;
        }

        return add_query_arg($args, admin_url('admin.php'));
    }

    protected function renderThemeSitesHtml(array $theme): string {
        $activeSites = is_array($theme['active_sites'] ?? null) ? $theme['active_sites'] : [];
        $isTruncated = !empty($theme['active_sites_truncated']);
        $siteCount = (int)($theme['site_count'] ?? count($activeSites));
        $site = [];
        $perPage = 20;
        $totalPages = (int)ceil(count($activeSites) / $perPage);
        $index = 0;
        $page = 1;
        $siteId = 0;
        $siteDetailsUrl = '';
        $toggleId = 'rrze-msm-theme-sites-' . sanitize_html_class(md5((string)($theme['stylesheet'] ?? (string)($theme['name'] ?? 'theme'))));

        if (empty($activeSites)) {
            return '';
        }

        ob_start();
        echo '<div class="rrze-msm-plugin-sites-inline" data-plugin-sites-id="' . esc_attr($toggleId) . '">';
        echo '<p class="rrze-msm-plugin-sites-collapsed"><button type="button" class="button-link rrze-msm-plugin-sites-toggle-text" data-plugin-sites-id="' . esc_attr($toggleId) . '" aria-expanded="false">▼ ' . esc_html__('Websites anzeigen', 'rrze-multisite-manager') . '</button></p>';
        echo '<div class="rrze-msm-plugin-sites-details" hidden>';
        echo '<p class="rrze-msm-plugin-sites-toggle-row"><button type="button" class="button-link rrze-msm-plugin-sites-toggle-text" data-plugin-sites-id="' . esc_attr($toggleId) . '" aria-expanded="true">▲ ' . esc_html__('Websites verbergen', 'rrze-multisite-manager') . '</button></p>';

        if ($isTruncated) {
            echo '<p class="description">';
            echo esc_html(
                sprintf(
                    __('Es wird eine Vorschau der ersten %1$s von %2$s Websites angezeigt.', 'rrze-multisite-manager'),
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

            echo '</strong> <span class="rrze-msm-plugin-site-sep">|</span> ';
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

    protected function getSitePermanentDeleteUrl(int $siteId): string {
        if ($siteId <= 0) {
            return '';
        }

        return wp_nonce_url(
            network_admin_url('sites.php?action=confirm&action2=deleteblog&id=' . $siteId),
            'deleteblog_' . $siteId
        );
    }

    protected function renderSiteTableSortButton(string $key, string $label): string {
        return '<button type="button" class="rrze-msm-site-table-sort" data-sort-key="' . esc_attr($key) . '" data-sort-direction="asc"><span>' . esc_html($label) . '</span><span class="rrze-msm-site-table-sort-indicator" aria-hidden="true"></span></button>';
    }

    protected function getSiteTablePerPageOptions(int $defaultPerPage): array {
        $sourceOptions = [$defaultPerPage, 10, 30, 50, 100];
        $options = [];
        $value = 0;

        foreach ($sourceOptions as $value) {
            $value = (int)$value;

            if (!$this->isValidPerPageOption($value) || in_array($value, $options, true)) {
                continue;
            }

            $options[] = $value;
        }

        return $options;
    }

    protected function isValidPerPageOption(int $value): bool {
        return $value > 0;
    }

    protected function normalizeSiteTableSortKey(string $sortKey): string {
        $sortKey = str_replace('_', '-', sanitize_key($sortKey));

        if (!in_array($sortKey, ['name', 'registered', 'last-updated', 'admin-email', 'storage'], true)) {
            return 'name';
        }

        return $sortKey;
    }
}
