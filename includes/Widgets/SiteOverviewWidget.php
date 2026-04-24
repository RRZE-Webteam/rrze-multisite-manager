<?php

namespace RRZE\MultisiteManager\Widgets;

defined('ABSPATH') || exit;

class SiteOverviewWidget extends Widgets {
    public function getId(): string {
        return 'site_overview';
    }

    public function getTitle(): string {
        return __('Site-Übersicht', 'rrze-multisite-manager');
    }

    public function getDescription(): string {
        return __('Erweiterte Übersicht über Sites, Benutzer, Inhalte und Speicherverbrauch.', 'rrze-multisite-manager');
    }

    public function getWidth(): int {
        return 12;
    }

    protected function getTemplateName(): string {
        return 'site-overview-widget';
    }

    protected function getTemplateData(array $dashboardData): array {
        return [
            'sites' => $dashboardData['site_overview'] ?? [],
            'default_per_page' => (int)($dashboardData['site_table_default_limit'] ?? 10),
        ];
    }

    public function renderTable(array $dashboardData): string {
        return $this->renderSiteOverviewTable(
            $dashboardData['site_overview'] ?? [],
            [
                'table_id' => 'site-overview-page',
                'default_per_page' => (int)($dashboardData['site_table_default_limit'] ?? 10),
                'sort_key' => 'registered',
                'sort_direction' => 'desc',
            ]
        );
    }
}
