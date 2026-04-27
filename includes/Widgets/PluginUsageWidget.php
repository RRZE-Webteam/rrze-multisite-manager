<?php

namespace RRZE\MultisiteManager\Widgets;

defined('ABSPATH') || exit;

class PluginUsageWidget extends Widgets {
    public function getId(): string {
        return 'plugin_usage';
    }

    public function getTitle(): string {
        return __('Plugin-Überblick', 'rrze-multisite-manager');
    }

    public function getDescription(): string {
        return __('Erste Auswertung der lokal verfügbaren und im Netzwerk verwendeten Plugins.', 'rrze-multisite-manager');
    }

    public function getWidth(): int {
        return 8;
    }

    protected function getTemplateName(): string {
        return 'plugin-usage-widget';
    }

    protected function getTemplateData(array $dashboardData): array {
        $pluginUsage = $dashboardData['plugin_usage'] ?? [];
        $plugins = array_values(
            array_filter(
                $pluginUsage['plugins'] ?? [],
                [$this, 'isActivePlugin']
            )
        );

        return [
            'summary' => $pluginUsage['summary'] ?? [],
            'plugins' => $plugins,
            'network_plugins_url' => network_admin_url('plugins.php'),
            'default_per_page' => 10,
        ];
    }

    protected function isActivePlugin(array $plugin): bool {
        return (int)($plugin['site_count'] ?? 0) > 0;
    }
}
