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
                    'widget_classes' => 'rrze-msm-widget-span-' . $this->getWidth(),
                ],
                $this->getTemplateData($dashboardData)
            ),
            $this
        );
    }

    public function getWidth(): int {
        return 4;
    }

    public function getStatusBadgesHtml(array $statusItems): string {
        $html = '';
        $statusItem = [];

        foreach ($statusItems as $statusItem) {
            $html .= '<span class="rrze-msm-badge rrze-msm-badge-' . esc_attr((string)$statusItem['accent']) . '">' . esc_html((string)$statusItem['label']) . '</span> ';
        }

        return trim($html);
    }

    public function renderActionsForSite(array $site): string {
        return $this->renderSiteActions($site);
    }

    public function renderSiteTable(array $sites, array $args = []): string {
        $site = [];
        $rowClass = '';
        $tableId = sanitize_key((string)($args['table_id'] ?? 'sites'));
        $defaultPerPage = max(1, (int)($args['default_per_page'] ?? 10));
        $sortKey = $this->normalizeSiteTableSortKey((string)($args['sort_key'] ?? 'name'));
        $sortDirection = strtolower((string)($args['sort_direction'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
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
            echo '<td><strong>' . esc_html((string)$site['name']) . '</strong><br><a href="' . esc_url((string)$site['url']) . '" target="_blank" rel="noopener noreferrer">' . esc_html((string)$site['url']) . '</a></td>';
            echo '<td>' . esc_html((string)$site['registered_label']) . '</td>';
            echo '<td>' . esc_html((string)($site['last_updated_label'] ?? __('Unbekannt', 'rrze-multisite-manager'))) . '</td>';
            echo '<td>' . $this->renderSiteAdminEmail((string)($site['admin_email'] ?? '')) . '</td>';
            echo '<td>' . $this->renderSiteActions($site) . '</td>';
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
        echo '<th>' . $this->renderSiteTableSortButton('storage', __('Speicher', 'rrze-multisite-manager')) . '</th>';
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
            echo '<td><strong>' . esc_html((string)$site['name']) . '</strong><br><a href="' . esc_url((string)$site['url']) . '" target="_blank" rel="noopener noreferrer">' . esc_html((string)$site['url']) . '</a></td>';
            echo '<td>' . esc_html((string)$site['registered_label']) . '</td>';
            echo '<td>' . esc_html((string)($site['last_updated_label'] ?? __('Unbekannt', 'rrze-multisite-manager'))) . '</td>';
            echo '<td>' . $this->renderSiteAdminEmail((string)($site['admin_email'] ?? '')) . '</td>';
            echo '<td>' . $this->renderRoleCounts((int)($site['id'] ?? 0), (array)($site['role_counts'] ?? [])) . '</td>';
            echo '<td>' . $this->renderContentCounts((array)($site['content_counts'] ?? [])) . '</td>';
            echo '<td class="' . esc_attr($this->getStorageCellClass((array)($site['storage'] ?? []))) . '">' . $this->renderStorageUsage((array)($site['storage'] ?? [])) . '</td>';
            echo '<td>' . $this->renderSiteActions($site) . '</td>';
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
            echo '<td><strong>' . esc_html((string)$site['name']) . '</strong><br><a href="' . esc_url((string)$site['url']) . '" target="_blank" rel="noopener noreferrer">' . esc_html((string)$site['url']) . '</a></td>';
            echo '<td>' . esc_html($this->formatStatusMetaDate((string)($site[$statusMetaKey] ?? ''))) . '</td>';
            echo '<td>' . $this->renderStatusUser((int)($site['status_user_id'] ?? 0)) . '</td>';
            echo '<td>' . $this->renderStatusNote((string)($site['status_note'] ?? '')) . '</td>';
            echo '<td>' . $this->renderSiteActions($site) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '<div class="tablenav bottom">';
        echo '<div class="tablenav-pages rrze-msm-site-table-pagination" aria-label="' . esc_attr__('Seitennavigation', 'rrze-multisite-manager') . '"></div>';
        echo '</div>';
        echo '</div>';

        return (string)ob_get_clean();
    }

    public function renderPieChart(array $items, string $emptyMessage): string {
        $gradient = $this->getPieGradient($items);
        $item = [];

        if (empty($items)) {
            return '<p>' . esc_html($emptyMessage) . '</p>';
        }

        ob_start();
        echo '<div class="rrze-msm-pie-layout">';
        echo '<div class="rrze-msm-pie-chart" style="background: ' . esc_attr($gradient) . ';"></div>';
        echo '<div class="rrze-msm-pie-legend">';

        foreach ($items as $item) {
            echo '<div class="rrze-msm-pie-legend-item">';
            echo '<span class="rrze-msm-pie-swatch rrze-msm-swatch-' . esc_attr((string)$item['accent']) . '"></span>';
            echo '<div>';
            echo '<strong>' . esc_html((string)$item['label']) . '</strong><br>';
            echo '<span>' . esc_html(number_format_i18n((int)$item['value'])) . ' (' . esc_html((string)$item['percent']) . '%)</span>';
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

    protected function renderSiteAdminEmail(string $email): string {
        if ($email === '') {
            return esc_html__('Unbekannt', 'rrze-multisite-manager');
        }

        return '<a href="mailto:' . esc_attr($email) . '">' . esc_html($email) . '</a>';
    }

    protected function renderSiteActions(array $site): string {
        $siteId = (int)($site['id'] ?? 0);
        $isMainSite = !empty($site['is_main_site']);
        $isArchived = !empty($site['is_archived']);
        $isSpam = !empty($site['is_spam']);
        $isDeleted = !empty($site['is_deleted']);
        $isNormal = !$isArchived && !$isSpam && !$isDeleted;
        $isRestricted = $isArchived || $isSpam;
        $actions = [];

        if ($siteId <= 0) {
            return '';
        }

        $actions[] = $this->renderSiteActionLink(
            network_admin_url('site-info.php?id=' . $siteId),
            __('Bearbeiten', 'rrze-multisite-manager'),
            'edit'
        );
        $actions[] = $this->renderSiteActionLink(
            get_admin_url($siteId),
            __('Dashboard', 'rrze-multisite-manager'),
            'dashboard'
        );
        $actions[] = $this->renderSiteActionLink(
            (string)($site['url'] ?? ''),
            __('Aufrufen', 'rrze-multisite-manager'),
            'visibility',
            true
        );
        $actions[] = $this->renderSiteActionLink(
            $this->getSiteDetailsPageUrl($siteId),
            __('Details', 'rrze-multisite-manager'),
            'search'
        );

        if ($isMainSite) {
            return '<div class="rrze-msm-site-actions">' . implode('', $actions) . '</div>';
        }

        if ($isNormal) {
            $actions[] = $this->renderSiteActionLink(
                $this->getSiteStatusPageUrl($siteId, 'archive'),
                __('Archivieren', 'rrze-multisite-manager'),
                'archive'
            );
            $actions[] = $this->renderSiteActionLink(
                $this->getSiteStatusPageUrl($siteId, 'spam'),
                __('Sperren', 'rrze-multisite-manager'),
                'lock'
            );
        }

        if ($isRestricted) {
            $actions[] = $this->renderSiteActionLink(
                $this->getSiteStatusSubmitUrl($siteId, 'restore'),
                __('Wiederherstellen', 'rrze-multisite-manager'),
                'backup'
            );
            $actions[] = $this->renderSiteActionLink(
                $this->getSiteStatusSubmitUrl($siteId, 'delete'),
                __('Zum Löschen markieren', 'rrze-multisite-manager'),
                'trash'
            );
        }

        if ($isDeleted) {
            $actions[] = $this->renderSiteActionLink(
                $this->getSiteStatusSubmitUrl($siteId, 'restore'),
                __('Wiederherstellen', 'rrze-multisite-manager'),
                'backup'
            );
        }

        return '<div class="rrze-msm-site-actions">' . implode('', $actions) . '</div>';
    }

    protected function renderSiteActionLink(string $url, string $label, string $icon, bool $newTab = false): string {
        $attributes = $newTab ? ' target="_blank" rel="noopener noreferrer"' : '';
        $accentClass = $this->getSiteActionAccentClass($icon);

        return '<a class="button button-small rrze-msm-site-action ' . esc_attr($accentClass) . '" href="' . esc_url($url) . '" title="' . esc_attr($label) . '" aria-label="' . esc_attr($label) . '"' . $attributes . '><span class="dashicons dashicons-' . esc_attr($icon) . '" aria-hidden="true"></span><span class="screen-reader-text">' . esc_html($label) . '</span></a>';
    }

    protected function renderSiteActionState(string $label, string $icon): string {
        return '<span class="rrze-msm-site-action-state" title="' . esc_attr($label) . '"><span class="dashicons dashicons-' . esc_attr($icon) . '" aria-hidden="true"></span><span class="screen-reader-text">' . esc_html($label) . '</span></span>';
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
            'id' => $siteId,
        ];

        if ($role !== '') {
            $args['role'] = $role;
        }

        return add_query_arg($args, network_admin_url('site-users.php'));
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
            network_admin_url('admin.php')
        );
    }

    protected function getSiteStatusSubmitUrl(int $siteId, string $statusAction): string {
        return wp_nonce_url(
            add_query_arg(
                [
                    'action' => 'rrze_multisite_manager_site_status',
                    'site_id' => $siteId,
                    'status_action' => $statusAction,
                ],
                network_admin_url('edit.php')
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
            network_admin_url('admin.php')
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
