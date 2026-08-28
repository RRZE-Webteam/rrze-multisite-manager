<?php

namespace RRZE\MultisiteManager\Widgets;

defined('ABSPATH') || exit;

class EditorUsageWidget extends Widgets {
    public function getId(): string {
        return 'editor_usage';
    }

    public function getTitle(): string {
        return __('Editor usage', 'rrze-multisite-manager');
    }

    public function getDescription(): string {
        return __('Estimate based on whether the Classic Editor plugin is active per site or network-wide.', 'rrze-multisite-manager');
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
            'empty_message' => __('No editor data available.', 'rrze-multisite-manager'),
        ];
    }
}
