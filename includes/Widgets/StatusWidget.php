<?php

namespace RRZE\MultisiteManager\Widgets;

defined('ABSPATH') || exit;

class StatusWidget extends Widgets {
    public function getId(): string {
        return 'status';
    }

    public function getTitle(): string {
        return __('Statusverteilung', 'rrze-multisite-manager');
    }

    public function getDescription(): string {
        return __('Verteilung der Site-Zustände innerhalb dieses Netzwerks.', 'rrze-multisite-manager');
    }

    public function getLayoutClass(): string {
        return 'rrze-msm-widget-size-fluid-chart';
    }

    protected function getTemplateName(): string {
        return 'status-widget';
    }

    protected function getTemplateData(array $dashboardData): array {
        return [
            'items' => $dashboardData['status_distribution'] ?? [],
            'empty_message' => __('Keine Statusdaten vorhanden.', 'rrze-multisite-manager'),
        ];
    }
}
