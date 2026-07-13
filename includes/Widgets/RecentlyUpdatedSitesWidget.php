<?php

namespace RRZE\MultisiteManager\Widgets;

defined('ABSPATH') || exit;

class RecentlyUpdatedSitesWidget extends Widgets {
    public function getId(): string {
        return 'recently_updated_sites';
    }

    public function getTitle(): string {
        return __('Zuletzt aktualisierte Sites', 'rrze-multisite-manager');
    }

    public function getDescription(): string {
        return __('Sites mit den zuletzt geänderten Posts, Pages oder Medien.', 'rrze-multisite-manager');
    }

    public function getWidth(): int {
        return 8;
    }

    public function getLayoutClass(): string {
        return 'rrze-msm-widget-size-fluid-wide';
    }

    protected function getTemplateName(): string {
        return 'recently-updated-sites-widget';
    }

    protected function getTemplateData(array $dashboardData): array {
        return [
            'sites' => $dashboardData['recently_updated_sites'] ?? [],
            'default_per_page' => (int)($dashboardData['site_table_default_limit'] ?? 10),
        ];
    }
}
