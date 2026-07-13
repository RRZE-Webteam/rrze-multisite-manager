<?php

namespace RRZE\MultisiteManager\Widgets;

defined('ABSPATH') || exit;

class EditorUsageWidget extends Widgets {
    public function getId(): string {
        return 'editor_usage';
    }

    public function getTitle(): string {
        return __('Editor-Nutzung', 'rrze-multisite-manager');
    }

    public function getDescription(): string {
        return __('Schaetzung auf Basis der Aktivierung des Plugins Classic Editor pro Site oder netzwerkweit.', 'rrze-multisite-manager');
    }

    public function getLayoutClass(): string {
        return 'rrze-msm-widget-size-fluid-chart';
    }

    protected function getTemplateName(): string {
        return 'editor-usage-widget';
    }

    protected function getTemplateData(array $dashboardData): array {
        return [
            'items' => $dashboardData['editor_usage'] ?? [],
            'empty_message' => __('Keine Editor-Daten vorhanden.', 'rrze-multisite-manager'),
        ];
    }
}
