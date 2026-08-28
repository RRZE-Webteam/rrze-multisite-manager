<?php

namespace RRZE\MultisiteManager\Widgets;

defined('ABSPATH') || exit;

class ThemeOverviewWidget extends Widgets {
    public function getId(): string {
        return 'theme_overview';
    }

    public function getTitle(): string {
        return __('Theme overview', 'rrze-multisite-manager');
    }

    public function getDescription(): string {
        return __('Installed themes with version, number of using sites, and network availability.', 'rrze-multisite-manager');
    }

    public function getWidth(): int {
        return 12;
    }

    protected function getTemplateName(): string {
        return 'theme-overview-widget';
    }

    protected function getTemplateData(array $dashboardData): array {
        return [
            'themes' => $dashboardData['themes'] ?? [],
        ];
    }
}
