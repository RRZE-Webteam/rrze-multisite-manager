<?php

namespace RRZE\MultisiteManager\Widgets;

defined('ABSPATH') || exit;

class InactiveThemesWidget extends Widgets {
    public function getId(): string {
        return 'inactive_themes';
    }

    public function getTitle(): string {
        return __('Nicht aktivierte Themes', 'rrze-multisite-manager');
    }

    public function getDescription(): string {
        return __('Installierte Themes, die auf keiner Website des Netzwerks verwendet werden.', 'rrze-multisite-manager');
    }

    protected function getTemplateName(): string {
        return 'inactive-themes-widget';
    }

    protected function getTemplateData(array $dashboardData): array {
        return [
            'items' => $dashboardData['inactive_themes'] ?? [],
        ];
    }
}
