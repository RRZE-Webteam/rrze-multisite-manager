<?php

namespace RRZE\MultisiteManager\Widgets;

defined('ABSPATH') || exit;

class PluginUsageDistributionWidget extends Widgets {
    public function getId(): string {
        return 'plugin_usage_distribution';
    }

    public function getTitle(): string {
        return __('Plugin-Nutzung', 'rrze-multisite-manager');
    }

    public function getDescription(): string {
        return __('Prozentuale Verteilung, wie häufig Plugins auf allen Websites des Netzwerks genutzt werden.', 'rrze-multisite-manager');
    }

    protected function getTemplateName(): string {
        return 'plugin-usage-distribution-widget';
    }

    protected function getTemplateData(array $dashboardData): array {
        $pluginUsage = is_array($dashboardData['plugin_usage'] ?? null) ? $dashboardData['plugin_usage'] : [];

        return [
            'items' => $pluginUsage['distribution'] ?? [],
            'empty_message' => __('Keine Plugin-Nutzungsdaten vorhanden.', 'rrze-multisite-manager'),
        ];
    }
}
