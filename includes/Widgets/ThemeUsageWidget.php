<?php

namespace RRZE\MultisiteManager\Widgets;

defined('ABSPATH') || exit;

class ThemeUsageWidget extends Widgets {
    public function getId(): string {
        return 'theme_usage';
    }

    public function getTitle(): string {
        return __('Theme-Nutzung', 'rrze-multisite-manager');
    }

    public function getDescription(): string {
        return __('Verteilung der aktuell auf Sites genutzten Themes.', 'rrze-multisite-manager');
    }

    public function getLayoutClass(): string {
        return 'rrze-msm-widget-size-fluid-chart';
    }

    protected function getTemplateName(): string {
        return 'theme-usage-widget';
    }

    protected function getTemplateData(array $dashboardData): array {
        return [
            'items' => $dashboardData['theme_usage'] ?? [],
            'empty_message' => __('Keine Theme-Nutzungsdaten vorhanden.', 'rrze-multisite-manager'),
        ];
    }
}
