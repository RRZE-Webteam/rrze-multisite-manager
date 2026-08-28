<?php

namespace RRZE\MultisiteManager\Widgets;

defined('ABSPATH') || exit;

class RecentSitesWidget extends Widgets {
    public function getId(): string {
        return 'recent_sites';
    }

    public function getTitle(): string {
        return __('Most recently created sites', 'rrze-multisite-manager');
    }

    public function getDescription(): string {
        return __('The newest instances in the network, including status and creation date.', 'rrze-multisite-manager');
    }

    public function getWidth(): int {
        return 12;
    }

    public function getLayoutClass(): string {
        return 'rrze-msm-widget-size-fluid-wide';
    }

    protected function getTemplateName(): string {
        return 'recent-sites-widget';
    }

    protected function getTemplateData(array $dashboardData): array {
        return [
            'sites' => $dashboardData['recent_sites'] ?? [],
            'default_per_page' => (int)($dashboardData['site_table_default_limit'] ?? 10),
        ];
    }
}
