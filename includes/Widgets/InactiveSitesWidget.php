<?php

namespace RRZE\MultisiteManager\Widgets;

defined('ABSPATH') || exit;

class InactiveSitesWidget extends Widgets {
    public function getId(): string {
        return 'inactive_sites';
    }

    public function getTitle(): string {
        return __('Sites mit langer Nichtnutzung', 'rrze-multisite-manager');
    }

    public function getDescription(): string {
        return __('Sites mit der längsten Inaktivität bei Posts, Pages oder Medien.', 'rrze-multisite-manager');
    }

    public function getWidth(): int {
        return 8;
    }

    protected function getTemplateName(): string {
        return 'inactive-sites-widget';
    }

    protected function getTemplateData(array $dashboardData): array {
        return [
            'sites' => $dashboardData['inactive_sites'] ?? [],
            'default_per_page' => (int)($dashboardData['site_table_default_limit'] ?? 10),
        ];
    }
}
