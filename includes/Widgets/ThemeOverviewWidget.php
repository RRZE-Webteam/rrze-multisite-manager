<?php

namespace RRZE\MultisiteManager\Widgets;

defined('ABSPATH') || exit;

class ThemeOverviewWidget extends Widgets {
    public function getId(): string {
        return 'theme_overview';
    }

    public function getTitle(): string {
        return __('Theme-Überblick', 'rrze-multisite-manager');
    }

    public function getDescription(): string {
        return __('Installierte Themes mit Version, Anzahl verwendender Sites und Netzwerkfreigabe.', 'rrze-multisite-manager');
    }

    public function getWidth(): int {
        return 8;
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
