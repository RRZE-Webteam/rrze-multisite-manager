<?php

namespace RRZE\MultisiteManager\Widgets;

defined('ABSPATH') || exit;

class NewMonitoringAlertsWidget extends Widgets {
    public function getId(): string {
        return 'new_monitoring_alerts';
    }

    public function getTitle(): string {
        return __('Neue Monitoring-Warnungen', 'rrze-multisite-manager');
    }

    public function getDescription(): string {
        return __('Websites, die seit dem letzten Monitoring-Lauf neu in einen technischen Problemstatus gewechselt sind.', 'rrze-multisite-manager');
    }

    public function getWidth(): int {
        return 8;
    }

    public function getLayoutClass(): string {
        return 'rrze-msm-widget-size-fluid-wide';
    }

    protected function getTemplateName(): string {
        return 'new-monitoring-alerts-widget';
    }

    protected function getTemplateData(array $dashboardData): array {
        return [
            'sites' => $dashboardData['new_monitoring_alerts'] ?? [],
            'default_per_page' => (int)($dashboardData['site_table_default_limit'] ?? 10),
        ];
    }
}
