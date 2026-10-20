<?php

namespace RRZE\MultisiteManager\Widgets;

defined('ABSPATH') || exit;

class InactivePluginsWidget extends Widgets {
    public function getId(): string {
        return 'inactive_plugins';
    }

    public function getTitle(): string {
        return __('Inactive plugins', 'rrze-multisite-manager');
    }

    public function getDescription(): string {
        return __('Installed plugins that are not actively used on any website in the network.', 'rrze-multisite-manager');
    }

    public function getLayoutClass(): string {
        return 'rrze-msm-widget-size-fluid-medium';
    }

    protected function getTemplateName(): string {
        return 'inactive-plugins-widget';
    }

    protected function getTemplateData(array $dashboardData): array {
        $pluginUsage = is_array($dashboardData['plugin_usage'] ?? null) ? $dashboardData['plugin_usage'] : [];
        $items = is_array($pluginUsage['inactive_plugins'] ?? null) ? $pluginUsage['inactive_plugins'] : [];

        return [
            'items' => empty($items)
                ? []
                : (new PluginUsageWidget($this->plugin, $this->config))->hydrateRuntimeState($items),
        ];
    }
}
