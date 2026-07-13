<?php

namespace RRZE\MultisiteManager\Widgets;

defined('ABSPATH') || exit;

class OperationalStatusWidget extends Widgets {
    public function getId(): string {
        return 'operational_status';
    }

    public function getTitle(): string {
        return __('Betriebsstatus von Websites', 'rrze-multisite-manager');
    }

    public function getDescription(): string {
        return __('Verteilung der eigenen MSM-Betriebszustände aller Websites im Netzwerk.', 'rrze-multisite-manager');
    }

    public function getLayoutClass(): string {
        return 'rrze-msm-widget-size-fluid-chart';
    }

    protected function getTemplateName(): string {
        return 'operational-status-widget';
    }

    protected function getTemplateData(array $dashboardData): array {
        return [
            'items' => $dashboardData['operational_status_distribution'] ?? [],
            'empty_message' => __('Keine Betriebsstatusdaten vorhanden.', 'rrze-multisite-manager'),
        ];
    }
}
