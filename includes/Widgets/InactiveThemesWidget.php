<?php

namespace RRZE\MultisiteManager\Widgets;

defined('ABSPATH') || exit;

class InactiveThemesWidget extends Widgets {
    public function getId(): string {
        return 'inactive_themes';
    }

    public function getTitle(): string {
        return __('Inactive themes', 'rrze-multisite-manager');
    }

    public function getDescription(): string {
        return __('Installed themes that are not used on any website in the network.', 'rrze-multisite-manager');
    }

    public function getLayoutClass(): string {
        return 'rrze-msm-widget-size-fluid-medium';
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
