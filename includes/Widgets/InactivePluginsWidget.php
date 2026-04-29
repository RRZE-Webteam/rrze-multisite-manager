<?php

namespace RRZE\MultisiteManager\Widgets;

defined('ABSPATH') || exit;

class InactivePluginsWidget extends Widgets {
    public function getId(): string {
        return 'inactive_plugins';
    }

    public function getTitle(): string {
        return __('Nicht aktivierte Plugins', 'rrze-multisite-manager');
    }

    public function getDescription(): string {
        return __('Installierte Plugins, die auf keiner Website des Netzwerks aktiv genutzt werden.', 'rrze-multisite-manager');
    }

    public function getLayoutClass(): string {
        return 'rrze-msm-widget-size-fluid-medium';
    }

    protected function getTemplateName(): string {
        return 'inactive-plugins-widget';
    }

    protected function getTemplateData(array $dashboardData): array {
        $pluginUsage = is_array($dashboardData['plugin_usage'] ?? null) ? $dashboardData['plugin_usage'] : [];

        return [
            'items' => $pluginUsage['inactive_plugins'] ?? [],
        ];
    }
}
