<?php

namespace RRZE\MultisiteManager\Widgets;

defined('ABSPATH') || exit;

class PluginUsageDistributionWidget extends Widgets {
    public function getId(): string {
        return 'plugin_usage_distribution';
    }

    public function getTitle(): string {
        return __('Plugin usage', 'rrze-multisite-manager');
    }

    public function getDescription(): string {
        return __('Percentage distribution of how often plugins are used across all websites in the network.', 'rrze-multisite-manager');
    }

    protected function getTemplateName(): string {
        return 'plugin-usage-distribution-widget';
    }

    protected function getTemplateData(array $dashboardData): array {
        $pluginUsage = is_array($dashboardData['plugin_usage'] ?? null) ? $dashboardData['plugin_usage'] : [];

        return [
            'items' => $pluginUsage['distribution'] ?? [],
            'empty_message' => __('No plugin usage data available.', 'rrze-multisite-manager'),
        ];
    }
}
